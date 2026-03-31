<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/DebugLogger.php';
require_once __DIR__ . '/FieldMapper.php';
require_once __DIR__ . '/WooCommerce/WCAnalyser.php';
require_once __DIR__ . '/PrestaShop/PSAnalyser.php';
require_once __DIR__ . '/PrestaShop/PSWriter.php';

/**
 * Migrator v2 — wizard-aware, fully resumable WC → PS migration.
 *
 * Flow:
 *   step=analyse    → run WCAnalyser + PSAnalyser, build mapping, save to state
 *   step=categories → migrate all categories at once
 *   step=attributes → migrate all attribute groups + term values
 *   step=products   → migrate products in batches (cursor-based, resumable)
 *   step=done       → completed
 *
 * Transaction strategy:
 *   - Categories/attributes: each object in its own transaction (in PSWriter)
 *   - Products: ONE transaction per product covers the core rows + all
 *     combinations + stock + specific_price. Images are outside the
 *     transaction (HTTP downloads, non-rollbackable).
 */
class Migrator
{
    private WCAnalyser  $wc;
    private PSAnalyser  $psAnalyser;
    private PSWriter    $ps;
    private DebugLogger $log;
    private string      $progressFile;
    private array       $state;
    private int         $batchSize;

    private const MAX_ERRORS = 300;

    public function __construct(
        WCAnalyser  $wc,
        PSAnalyser  $psAnalyser,
        PSWriter    $ps,
        DebugLogger $log,
        string      $sessionId,
        int         $batchSize = 20
    ) {
        $this->wc         = $wc;
        $this->psAnalyser = $psAnalyser;
        $this->ps         = $ps;
        $this->log        = $log;
        $this->batchSize  = max(1, min($batchSize, 100));

        $dir  = __DIR__ . '/../migration_progress';
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId) ?: 'default';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $this->progressFile = $dir . '/progress_' . $safe . '.json';
        $this->loadState();
    }

    // ── State persistence ─────────────────────────────────────────────────────

    private function loadState(): void
    {
        if (file_exists($this->progressFile)) {
            $content = file_get_contents($this->progressFile);
            if ($content !== false) {
                $data = json_decode($content, true);
                if (is_array($data)) { $this->state = $data; return; }
            }
        }
        $this->state = $this->defaultState();
    }

    private function saveState(): void
    {
        $json = json_encode($this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json !== false) file_put_contents($this->progressFile, $json, LOCK_EX);
    }

    private function defaultState(): array
    {
        return [
            'status'             => 'idle',  // idle|running|paused|completed|error
            'step'               => 'categories',
            'options'            => [],
            'analysis'           => [],        // result of analyse step
            'mapping'            => [],        // field mapping overrides
            'total_categories'   => 0,
            'done_categories'    => 0,
            'total_attrs'        => 0,
            'done_attrs'         => 0,
            'total_products'     => 0,
            'done_products'      => 0,
            'skipped_products'   => 0,
            'last_wc_product_id' => 0,
            'categories_cursor'  => 0,  // index into category tree array
            'attributes_cursor'  => 0,  // index into taxonomy array
            'category_id_map'    => [],
            'attr_group_map'     => [],
            'attr_value_map'     => [],
            'product_id_map'     => [],
            'errors'             => [],
            // Image import phase (separate step after products)
            'total_images'           => 0,
            'done_images'            => 0,
            'skipped_images'         => 0,
            'last_image_product_id'  => 0,   // cursor: last ps_product id processed
            'image_mode'             => '',   // 'copy' | 'http'
            'wc_uploads_path'        => '',   // for copy mode
            'started_at'         => null,
            'completed_at'       => null,
        ];
    }

    public function getProgress(): array
    {
        // Include sessionId so the browser can confirm it's polling the right log file
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', basename($this->progressFile, '.json'));
        $safe = str_replace('progress_', '', $safe);
        return array_merge($this->state, [
            'log_stats' => $this->log->getSummary(),
            'session'   => $safe,
        ]);
    }

    private function addError(array $entry): void
    {
        if (count($this->state['errors']) < self::MAX_ERRORS) {
            $this->state['errors'][] = $entry;
        }
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Step 1: Analyse both databases.
     * Returns full analysis for the wizard mapping screen.
     */
    public function runAnalysis(): array
    {
        $this->log->setStep('analyse');
        $wcAnalysis = $this->wc->analyse();
        $psAnalysis = $this->psAnalyser->analyse();

        $this->state['analysis'] = [
            'wc' => $wcAnalysis,
            'ps' => $psAnalysis,
        ];
        $this->state['total_categories'] = $wcAnalysis['counts']['categories'];
        $this->state['total_attrs']      = $wcAnalysis['counts']['attributes'];
        $this->state['total_products']   = $wcAnalysis['counts']['products'];
        $this->saveState();

        return $this->state['analysis'];
    }

    /**
     * Migrate images in batches after product migration is complete.
     *
     * Strategy 'local': copy files from WC uploads dir to PS (same server).
     * Strategy 'http':  download via HTTP (works across servers).
     *
     * @param array $opts {strategy, wc_uploads_path, ps_root_path, batch_size}
     * @return array  progress state
     */
    public function migrateImagesBatch(array $opts): array
    {
        $strategy  = $opts['image_strategy']  ?? 'http';  // 'local' or 'http'
        $wcUploads = rtrim($opts['wc_uploads_path'] ?? '', '/');
        $psRoot    = rtrim($opts['ps_root_path']    ?? '', '/');
        $batchSize = max(1, min((int)($opts['batch_size'] ?? 20), 100));

        if ($psRoot === '') {
            $this->log->error('migrateImagesBatch: ps_root_path is required');
            $this->state['images_status'] = 'completed';
            $this->saveState();
            return $this->getProgress();
        }

        if ($strategy === 'local' && $wcUploads === '') {
            $this->log->error('migrateImagesBatch: wc_uploads_path required for local strategy');
            $this->state['images_status'] = 'completed';
            $this->saveState();
            return $this->getProgress();
        }

        // Count total images on first run
        if ($this->state['total_images'] === 0) {
            $total = $this->ps->countPendingImages();
            $this->state['total_images'] = $total;
            $this->log->info("Image batch: {$total} images to process (strategy={$strategy})");
            if ($total === 0) {
                $this->state['images_status'] = 'completed';
                $this->saveState();
                return $this->getProgress();
            }
        }

        $this->state['images_status'] = 'running';
        $batch = $this->ps->getImagesBatch($this->state['last_image_id'], $batchSize);

        if (empty($batch)) {
            $this->state['images_status'] = 'completed';
            $this->log->success('Image migration complete. Done=' . $this->state['done_images'] .
                                ' Skipped=' . $this->state['skipped_images']);
            $this->saveState();
            return $this->getProgress();
        }

        foreach ($batch as $row) {
            $imageId   = (int)$row['id_image'];
            $sourceUrl = (string)($row['source_url'] ?? '');

            if ($sourceUrl === '') {
                $this->state['skipped_images']++;
                $this->state['last_image_id'] = $imageId;
                continue;
            }

            $ok = false;
            if ($strategy === 'local') {
                $ok = $this->ps->copyImageLocal($imageId, $sourceUrl, $wcUploads, $psRoot);
            } else {
                $ok = $this->ps->downloadImageBatch($imageId, $sourceUrl, $psRoot);
            }

            if ($ok) {
                $this->state['done_images']++;
            } else {
                $this->state['skipped_images']++;
            }
            $this->state['last_image_id'] = $imageId;
        }

        $this->log->info('Image batch: done=' . $this->state['done_images'] .
                         ' skipped=' . $this->state['skipped_images'] .
                         ' remaining≈' . ($this->state['total_images'] - $this->state['done_images'] - $this->state['skipped_images']));
        $this->saveState();
        return $this->getProgress();
    }

    /**
     * Pause: set status to 'paused' so the next runStep() is a no-op.
     * The JS stops polling after getting status=paused.
     */
    public function pause(): array
    {
        if ($this->state['status'] === 'running') {
            $this->state['status'] = 'paused';
            $this->saveState();
            $this->log->info('Migration paused at step: ' . $this->state['step'] .
                             ' (products done: ' . ($this->state['done_products'] ?? 0) . ')');
        }
        return $this->getProgress();
    }

    /**
     * Resume from paused or stopped state.
     */
    public function resume(): array
    {
        if (in_array($this->state['status'], ['paused', 'stopped'], true)) {
            $this->state['status'] = 'running';
            $this->saveState();
            $this->log->info('Migration resumed from paused state.');
        }
        return $this->runStep();
    }

    /**
     * Stop (abort): persists cursor so migration can be resumed later.
     * Does NOT delete anything from PrestaShop.
     */
    public function stop(): array
    {
        $done = $this->state['done_products'] ?? 0;
        $cursor = $this->state['last_wc_product_id'] ?? 0;
        $this->log->info("Migration stopped. Cursor: WC#{$cursor}, done: {$done}");
        $this->state['status'] = 'stopped';
        $this->saveState();
        return $this->getProgress();
    }

    /**
     * Step 2+: Run migration (categories → attributes → products).
     * Returns current state after the step. Caller polls until status=completed.
     */
    public function runStep(array $options = []): array
    {
        if ($this->state['status'] === 'idle') {
            $this->state['options']    = $options;
            $this->state['status']     = 'running';
            $this->state['started_at'] = date('Y-m-d H:i:s');

            // If analysis wasn't run separately, count products now
            if ($this->state['total_products'] === 0) {
                $this->state['total_products'] = $this->wc->countProducts();
            }
            if ($this->state['total_categories'] === 0) {
                $this->state['total_categories'] = $this->wc->countCategories();
            }

            $this->log->info('Migration started', [
                'categories' => $this->state['total_categories'],
                'attributes' => $this->state['total_attrs'],
                'products'   => $this->state['total_products'],
            ]);
            $this->saveState();
        }

        // Allow resuming from error or stopped states
        if (in_array($this->state['status'], ['error', 'stopped'], true)) {
            $this->state['status'] = 'running';
            $this->log->info('Resumed from ' . $this->state['status'] . ' state.');
        }

        // Paused: return current state without doing any work
        if ($this->state['status'] === 'paused') {
            return $this->getProgress();
        }

        $opts = $this->state['options'];

        try {
            if ($this->state['step'] === 'categories') {
                if ($opts['migrate_categories'] ?? true) {
                    $catDone = $this->migrateCategoriesBatch();
                    if (!$catDone) {
                        $this->saveState();
                        return $this->getProgress(); // return per-batch for progress bar
                    }
                } else {
                    $this->log->info('Category migration skipped.');
                }
                $this->state['step'] = 'attributes';
                $this->saveState();
            }

            if ($this->state['step'] === 'attributes') {
                if ($opts['migrate_attributes'] ?? true) {
                    $attrDone = $this->migrateAttributesBatch();
                    if (!$attrDone) {
                        $this->saveState();
                        return $this->getProgress(); // return per-batch for progress bar
                    }
                } else {
                    $this->log->info('Attribute migration skipped.');
                }
                $this->state['step'] = 'products';
                $this->saveState();
            }

            if ($this->state['step'] === 'products') {
                if ($opts['migrate_products'] ?? true) {
                    $done = $this->migrateProductsBatch($opts);
                    if (!$done) {
                        $this->saveState();
                        return $this->getProgress();
                    }
                } else {
                    $this->log->info('Product migration skipped.');
                }
                $this->state['step']         = 'done';
                $this->state['status']       = 'completed';
                $this->state['completed_at'] = date('Y-m-d H:i:s');
                $this->saveState();
                $this->log->success('Migration completed!', $this->log->getSummary());
            }
        } catch (\Throwable $e) {
            $this->state['status'] = 'error';
            $this->addError([
                'step'    => $this->state['step'],
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            $this->saveState();
            $this->log->error('Migration error: ' . $e->getMessage());
        }

        return $this->getProgress();
    }

    /**
     * Reset: delete all previously migrated data from PS and clear state.
     */
    /**
     * Import images as a separate phase after products.
     *
     * Modes:
     *   'copy' — copy files directly from WC uploads dir to PS img/p/ (same server)
     *   'http' — download via HTTP (different servers or copy not available)
     *
     * Call repeatedly until status='images_done'. Each call processes one batch.
     */
    public function importImages(array $options = []): array
    {
        $mode          = $options['image_mode']      ?? $this->state['image_mode'] ?? 'http';
        $psRootPath    = $options['ps_root_path']    ?? '';
        $wcUploadsPath = $options['wc_uploads_path'] ?? $this->state['wc_uploads_path'] ?? '';
        $batchSize     = $this->batchSize;

        // Persist mode and paths in state
        if ($mode)          $this->state['image_mode']        = $mode;
        if ($wcUploadsPath) $this->state['wc_uploads_path']   = $wcUploadsPath;
        if ($psRootPath)    $this->state['options']['ps_root_path'] = $psRootPath;
        else                $psRootPath = $this->state['options']['ps_root_path'] ?? '';

        // Build list of products that have images registered in ps_image
        // but whose files haven't been downloaded yet (no physical file exists).
        // Cursor: last ps_product id we processed.
        $cursor = (int)($this->state['last_image_product_id'] ?? 0);

        // Count total on first call
        if ($this->state['total_images'] === 0) {
            $total = (int)($this->ps->countProductsWithImages() ?? 0);
            $this->state['total_images'] = $total;
            $this->log->info("Image import started. Mode={$mode}. Total products with images: {$total}");
        }

        // Fetch next batch of products (by ps_product id)
        $batch = $this->ps->getProductsWithImages($cursor, $batchSize);

        if (empty($batch)) {
            $this->state['status'] = 'images_done';
            $this->saveState();
            $this->log->success("Image import complete. Done={$this->state['done_images']} Skipped={$this->state['skipped_images']}");
            return $this->getProgress();
        }

        foreach ($batch as $row) {
            $psProductId = (int)$row['id_product'];
            $imageId     = (int)$row['id_image'];
            $wcProductId = (int)($row['wc_id'] ?? 0);
            $imageUrl    = $row['image_url'] ?? '';

            $cursor = $psProductId;

            if ($imageUrl === '') {
                $this->state['skipped_images']++;
                continue;
            }

            // Determine target path
            $imgPath = $this->ps->getImageFilePath($psRootPath, $imageId);
            if ($imgPath === null) {
                $this->log->warning("Cannot resolve image path for id={$imageId}");
                $this->state['skipped_images']++;
                continue;
            }

            // Skip if file already exists
            if (file_exists($imgPath)) {
                $this->state['done_images']++;
                continue;
            }

            if ($mode === 'copy') {
                $this->copyImageFromWC($imageId, $imageUrl, $wcUploadsPath, $imgPath);
            } else {
                $this->downloadImageHTTP($imageId, $imageUrl, $imgPath);
            }

            $this->state['done_images']++;
            $this->log->debug("Image {$imageId} done [WC#{$wcProductId}]");
        }

        $this->state['last_image_product_id'] = $cursor;
        $this->state['status'] = 'images_running';
        $this->saveState();

        return $this->getProgress();
    }

    private function copyImageFromWC(int $imageId, string $imageUrl, string $wcUploadsPath, string $destPath): void
    {
        try {
            // Extract relative path from URL (after /wp-content/uploads/)
            if (preg_match('#/wp-content/uploads/(.+)$#', $imageUrl, $m)) {
                $relPath = $m[1];
                $srcPath = rtrim($wcUploadsPath, '/') . '/' . ltrim($relPath, '/');
                if (!file_exists($srcPath)) {
                    $this->log->warning("Source file not found: {$srcPath}");
                    $this->state['skipped_images']++;
                    return;
                }
                $dir = dirname($destPath);
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                if (!copy($srcPath, $destPath)) {
                    $this->log->warning("copy() failed: {$srcPath} → {$destPath}");
                    $this->state['skipped_images']++;
                }
            } else {
                $this->log->warning("Cannot extract WC path from URL: {$imageUrl}");
                $this->state['skipped_images']++;
            }
        } catch (\Throwable $e) {
            $this->log->warning("Copy failed id={$imageId}: " . $e->getMessage());
            $this->state['skipped_images']++;
        }
    }

    private function downloadImageHTTP(int $imageId, string $imageUrl, string $destPath): void
    {
        try {
            $ctx  = stream_context_create(['http' => [
                'timeout' => 20, 'max_redirects' => 3,
                'follow_location' => 1, 'ignore_errors' => true,
            ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);

            $data = @file_get_contents($imageUrl, false, $ctx);
            if ($data === false || strlen($data) < 100) {
                $this->log->warning("Download failed: {$imageUrl}");
                $this->state['skipped_images']++;
                return;
            }
            if (strlen($data) > 10 * 1024 * 1024) {
                $this->log->warning("Image too large (>10MB): {$imageUrl}");
                $this->state['skipped_images']++;
                return;
            }
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($data);
            if (!in_array($mime, ['image/jpeg','image/png','image/gif','image/webp'], true)) {
                $this->log->warning("Not an image (MIME {$mime}): {$imageUrl}");
                $this->state['skipped_images']++;
                return;
            }
            $dir = dirname($destPath);
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            file_put_contents($destPath, $data);
        } catch (\Throwable $e) {
            $this->log->warning("HTTP download failed id={$imageId}: " . $e->getMessage());
            $this->state['skipped_images']++;
        }
    }

    /**
     * Reset: delete migrated data in batches to avoid timeout on large catalogues.
     *
     * Each call processes up to $batchSize products. Returns true when done.
     * The JS calls this repeatedly until done=true.
     */
    public function reset(): array
    {
        $prodMap  = &$this->state['product_id_map'];
        $catMap   = &$this->state['category_id_map'];
        $groupMap = &$this->state['attr_group_map'];

        $done = false;

        if (!empty($prodMap)) {
            // Process one batch of products
            $batch = array_splice($prodMap, 0, $this->batchSize);
            foreach (array_reverse($batch, true) as $psId) {
                try { $this->ps->deleteProduct((int)$psId); }
                catch (\Throwable $e) { $this->log->warning("Delete product #{$psId}: " . $e->getMessage()); }
            }
            $remaining = count($prodMap);
            $this->log->info("Reset: products remaining = {$remaining}");
            $this->saveState();
            return ['done' => false, 'remaining' => $remaining, 'phase' => 'products'];
        }

        if (!empty($catMap)) {
            foreach (array_reverse($catMap, true) as $psId) {
                try { $this->ps->deleteCategory((int)$psId); }
                catch (\Throwable $e) { $this->log->warning("Delete category #{$psId}: " . $e->getMessage()); }
            }
            $this->state['category_id_map'] = [];
            $this->saveState();
            return ['done' => false, 'remaining' => 0, 'phase' => 'categories'];
        }

        if (!empty($groupMap)) {
            foreach ($groupMap as $psGroupId) {
                try { $this->ps->deleteAttributeGroup((int)$psGroupId); }
                catch (\Throwable $e) { $this->log->warning("Delete attr group #{$psGroupId}: " . $e->getMessage()); }
            }
            $this->state['attr_group_map'] = [];
            $this->saveState();
            return ['done' => false, 'remaining' => 0, 'phase' => 'attributes'];
        }

        // All done
        $this->state = $this->defaultState();
        $this->saveState();
        $this->log->clearLog();
        $this->log->success('Reset complete.');
        return ['done' => true, 'remaining' => 0, 'phase' => 'done'];
    }

    // ── Categories ────────────────────────────────────────────────────────────

    /**
     * Process categories in micro-batches so progress bar updates smoothly.
     * Returns true when all done, false if more remain.
     */
    private function migrateCategoriesBatch(): bool
    {
        $this->log->setStep('categories');
        $homeCatId  = $this->ps->getHomeCategoryId();
        $categories = $this->wc->getCategoryTree();
        $total      = count($categories);
        $this->state['total_categories'] = $total;
        $map        = &$this->state['category_id_map'];
        $batchSize  = max(10, (int)($this->batchSize * 2));
        $cursor     = (int)($this->state['categories_cursor'] ?? 0);
        $processed  = 0;
        $catList    = array_values($categories);
        $catKeys    = array_keys($categories);

        for ($i = $cursor; $i < $total; $i++) {
            $wcId = $catKeys[$i];
            $cat  = $catList[$i];

            if (!isset($map[(string)$wcId])) {
                $psParentId = $cat['parent'] === 0
                    ? $homeCatId
                    : ($map[(string)$cat['parent']] ?? $homeCatId);
                try {
                    $psId = $this->ps->insertCategory($cat, $psParentId);
                    $map[(string)$wcId] = $psId;
                    $this->state['done_categories']++;
                    $this->log->success("Cat: '{$cat['name']}' → PS #{$psId}");
                } catch (\Throwable $e) {
                    $this->addError(['step'=>'categories','wc_id'=>$wcId,'name'=>$cat['name'],'message'=>$e->getMessage()]);
                    $this->log->error("Cat '{$cat['name']}' failed: " . $e->getMessage());
                }
            }

            $processed++;
            if ($processed >= $batchSize) {
                $this->state['categories_cursor'] = $i + 1;
                return false; // more remain
            }
        }

        $this->state['categories_cursor'] = $total;
        $this->log->info("Categories done: {$this->state['done_categories']}/{$this->state['total_categories']}");
        return true;
    }

    // ── Attributes ────────────────────────────────────────────────────────────

    /**
     * Process one taxonomy per call for smooth progress bar.
     * Returns true when all done, false if more remain.
     */
    private function migrateAttributesBatch(): bool
    {
        $this->log->setStep('attributes');
        $taxonomies = $this->wc->getAttributeTaxonomies();
        $total      = count($taxonomies);
        $this->state['total_attrs'] = $total;
        $cursor     = (int)($this->state['attributes_cursor'] ?? 0);
        $taxList    = array_values($taxonomies);

        if ($cursor >= $total) {
            $this->log->info("Attributes done: {$this->state['done_attrs']}/{$total}");
            return true;
        }

        // Process ONE taxonomy group per call
        $attrType = $taxList[$cursor];
        $attrName = $attrType['label'];
        $taxonomy = $attrType['taxonomy'];

        // Create group if not yet done
        if (!isset($this->state['attr_group_map'][$attrType['name']])) {
            try {
                $psGroupId = $this->ps->insertAttributeGroup($attrName);
                $this->state['attr_group_map'][$attrType['name']] = $psGroupId;
                $this->log->success("AttrGroup: '{$attrName}' → PS #{$psGroupId}");
            } catch (\Throwable $e) {
                $this->log->error("AttrGroup '{$attrName}' failed: " . $e->getMessage());
                $this->state['attributes_cursor'] = $cursor + 1;
                $this->state['done_attrs']++;
                return false;
            }
        }

        $psGroupId = $this->state['attr_group_map'][$attrType['name']];

        foreach ($this->wc->getAttributeTerms($taxonomy) as $term) {
            try {
                $psAttrId  = $this->ps->insertAttribute($term['name'], $psGroupId);
                $slug      = strtolower(trim($term['slug']));
                $attrSlug  = strtolower(trim($attrType['name']));
                $lookupKey = $attrSlug . ':' . $slug;
                $this->state['attr_value_map'][$lookupKey] = $psAttrId;

                // Also register slug without trailing -N suffix (e.g. 'xs-2' → 'xs')
                $slugBase = preg_replace('/-\d+$/', '', $slug);
                if ($slugBase !== $slug) {
                    $baseKey = $attrSlug . ':' . $slugBase;
                    if (!isset($this->state['attr_value_map'][$baseKey])) {
                        $this->state['attr_value_map'][$baseKey] = $psAttrId;
                    }
                }
                $this->log->debug("AttrVal: '{$term['name']}' [{$lookupKey}] → PS #{$psAttrId}");
            } catch (\Throwable $e) {
                $this->log->error("AttrVal '{$term['name']}' failed: " . $e->getMessage());
            }
        }

        $this->state['done_attrs']++;
        $this->state['attributes_cursor'] = $cursor + 1;

        if ($cursor + 1 >= $total) {
            $this->log->info("Attributes done: {$this->state['done_attrs']}/{$total}");
            return true;
        }

        return false; // more taxonomies remain
    }

    // ── Products (batched) ────────────────────────────────────────────────────

    /**
     * Migrate one batch. Returns true when all products processed.
     *
     * Each product = ONE transaction: core + combinations + stock.
     * Images are outside the transaction (HTTP, non-rollbackable).
     */
    private function migrateProductsBatch(array $opts): bool
    {
        $this->log->setStep('products');
        $psRootPath = $opts['ps_root_path'] ?? '';
        $doImages   = !empty($opts['migrate_images']);
        $homeCatId  = $this->ps->getHomeCategoryId();
        $catMap     = &$this->state['category_id_map'];
        $prodMap    = &$this->state['product_id_map'];
        $attrMap    = $this->state['attr_value_map'];

        $products = $this->wc->getProductsBatch($this->state['last_wc_product_id'], $this->batchSize);
        if (empty($products)) return true;

        foreach ($products as $wcId => $product) {
            $this->state['last_wc_product_id'] = $wcId;

            $defaultCatId = $homeCatId;
            if (!empty($product['category_ids'])) {
                $first = (string)$product['category_ids'][0];
                if (isset($catMap[$first])) $defaultCatId = $catMap[$first];
            }

            try {
                // ── Open transaction for this product ──────────────────────
                $this->ps->beginTransaction();

                $psId = $this->ps->insertProduct($product, $defaultCatId);
                $prodMap[(string)$wcId] = $psId;

                // Extra category assignments
                $psCatIds = [];
                foreach ($product['category_ids'] as $wcCatId) {
                    if (isset($catMap[(string)$wcCatId])) $psCatIds[] = $catMap[(string)$wcCatId];
                }
                if (!empty($psCatIds)) $this->ps->assignProductCategories($psId, $psCatIds);

                // Grouped product — no PS equivalent, log notice
                if ($product['type'] === 'grouped') {
                    $this->log->warning(
                        "'{$product['title']}' is a grouped product — imported as simple. " .
                        "Grouped relationships must be set manually in PS."
                    );
                }

                // Combinations (variable)
                $defaultAttrId = 0;
                if ($product['type'] === 'variable' && !empty($product['variations'])) {
                    $parentPrice = max(0.0, FieldMapper::toFloat($product['meta']['_regular_price'] ?? 0));
                    $isFirst     = true;
                    foreach ($product['variations'] as $variation) {
                        try {
                            $paId = $this->ps->insertCombination($psId, $variation, $attrMap, $parentPrice, $isFirst);
                            if ($isFirst && $paId > 0) $defaultAttrId = $paId;
                            $isFirst = false;
                        } catch (\Throwable $e) {
                            $this->log->warning("Variation {$variation['id']} of WC#{$wcId}: " . $e->getMessage());
                        }
                    }
                    if ($defaultAttrId > 0) {
                        try { $this->ps->updateDefaultAttribute($psId, $defaultAttrId); }
                        catch (\Throwable $e) { $this->log->warning("Default attr WC#{$wcId}: " . $e->getMessage()); }
                    }
                }

                // ── Commit ─────────────────────────────────────────────────
                $this->ps->commit();

                // Images — outside TX
                if ($doImages && !empty($product['images'])) {
                    $isCover = true;
                    foreach ($product['images'] as $imgUrl) {
                        try { $this->ps->insertImage($psId, $imgUrl, $isCover, $psRootPath); $isCover = false; }
                        catch (\Throwable $e) { $this->log->warning("Image WC#{$wcId}: " . $e->getMessage()); }
                    }
                }

                $this->state['done_products']++;
                $this->log->success("Product '{$product['title']}' WC#{$wcId} → PS#{$psId}");

            } catch (\Throwable $e) {
                try { $this->ps->rollback(); } catch (\Throwable $rb) {
                    $this->log->warning("Rollback failed for WC#{$wcId}: " . $rb->getMessage());
                }
                $this->state['skipped_products']++;
                $this->addError(['step'=>'products','wc_id'=>$wcId,'title'=>$product['title']??'','message'=>$e->getMessage()]);
                $this->log->error("Product WC#{$wcId} '{$product['title']}' failed: " . $e->getMessage());
            }
        }

        return false; // More batches may exist — rely on empty batch to terminate
    }
}
