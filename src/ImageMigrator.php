<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/DebugLogger.php';
require_once __DIR__ . '/FieldMapper.php';

/**
 * ImageMigrator — passo separado de migração de imagens.
 *
 * Suporta dois modos, detectados automaticamente:
 *   LOCAL  — WC e PS no mesmo servidor: copy() directo do filesystem
 *   REMOTE — Servidores separados: HTTP download em batches resumíveis
 *
 * Estado guardado em migration_progress/images_{session}.json
 * para permitir pausa e retoma.
 *
 * Versão: 2.5.0
 */
class ImageMigrator
{
    private Database    $wcDb;
    private Database    $psDb;
    private DebugLogger $log;
    private string      $p;          // PS prefix
    private string      $wcP;        // WC prefix
    private int         $idLang;
    private int         $idShop;
    private string      $stateFile;
    private array       $state;

    private const MAX_IMG_BYTES   = 8 * 1024 * 1024; // 8MB
    private const IMG_TIMEOUT_SEC = 20;
    private const ALLOWED_MIME    = ['image/jpeg','image/png','image/gif','image/webp'];
    private const ALLOWED_EXT     = ['jpg','jpeg','png','gif','webp'];

    public function __construct(
        Database    $wcDb,
        Database    $psDb,
        DebugLogger $log,
        string      $sessionId,
        int         $idLang = 1,
        int         $idShop = 1
    ) {
        $this->wcDb   = $wcDb;
        $this->psDb   = $psDb;
        $this->log    = $log;
        $this->p      = $psDb->getPrefix();
        $this->wcP    = $wcDb->getPrefix();
        $this->idLang = $idLang;
        $this->idShop = $idShop;

        $dir  = __DIR__ . '/../migration_progress';
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId) ?: 'default';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $this->stateFile = $dir . '/images_' . $safe . '.json';
        $this->loadState();
    }

    // ── State ─────────────────────────────────────────────────────────────────

    private function loadState(): void
    {
        if (file_exists($this->stateFile)) {
            $data = json_decode(file_get_contents($this->stateFile), true);
            if (is_array($data)) { $this->state = $data; return; }
        }
        $this->state = $this->defaultState();
    }

    private function saveState(): void
    {
        file_put_contents(
            $this->stateFile,
            json_encode($this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }

    private function defaultState(): array
    {
        return [
            'status'        => 'idle',   // idle|running|paused|completed|error
            'mode'          => null,     // local|remote
            'wc_root'       => '',       // WC filesystem root (local mode)
            'ps_root'       => '',       // PS filesystem root
            'last_ps_product_id' => 0,   // cursor
            'total'         => 0,
            'done'          => 0,
            'skipped'       => 0,
            'errors'        => [],
            'started_at'    => null,
            'completed_at'  => null,
        ];
    }

    public function getProgress(): array { return $this->state; }

    // ── Detect mode ───────────────────────────────────────────────────────────

    /**
     * Detect whether WC uploads are accessible from the local filesystem.
     * Returns ['mode'=>'local'|'remote', 'wc_uploads'=>path|null]
     */
    public function detectMode(string $wcRootPath = '', string $psRootPath = ''): array
    {
        $info = ['mode' => 'remote', 'wc_uploads' => null, 'ps_root' => null];

        // Try to find WC uploads path
        if ($wcRootPath !== '') {
            $candidates = [
                rtrim($wcRootPath, '/') . '/wp-content/uploads',
                rtrim($wcRootPath, '/') . '/wp-content/uploads/woocommerce_uploads',
            ];
            foreach ($candidates as $c) {
                if (is_dir($c) && is_readable($c)) {
                    $info['wc_uploads'] = $c;
                    break;
                }
            }
        }

        // Validate PS root
        if ($psRootPath !== '' && is_dir($psRootPath . '/img/p')) {
            $info['ps_root'] = $psRootPath;
        }

        // Local mode: both paths accessible AND writable PS img dir
        if ($info['wc_uploads'] && $info['ps_root'] && is_writable($info['ps_root'] . '/img')) {
            $info['mode'] = 'local';
        }

        $this->log->info("Image mode detected: {$info['mode']}", [
            'wc_uploads' => $info['wc_uploads'] ?? 'n/a',
            'ps_root'    => $info['ps_root']    ?? 'n/a',
        ]);

        return $info;
    }

    // ── Main entry ────────────────────────────────────────────────────────────

    /**
     * Run one batch of image migrations.
     * Returns current state. Caller polls until status=completed.
     */
    public function runBatch(array $options = []): array
    {
        if ($this->state['status'] === 'idle') {
            $mode = $this->detectMode(
                $options['wc_root'] ?? '',
                $options['ps_root'] ?? ''
            );
            $this->state['mode']     = $mode['mode'];
            $this->state['wc_root']  = $mode['wc_uploads'] ?? '';
            $this->state['ps_root']  = $mode['ps_root'] ?? ($options['ps_root'] ?? '');
            $this->state['status']   = 'running';
            $this->state['started_at'] = date('Y-m-d H:i:s');
            $this->state['total']    = $this->countProductsWithImages();
            $this->log->info("Image migration started", [
                'mode'  => $this->state['mode'],
                'total' => $this->state['total'],
            ]);
            $this->saveState();
        }

        if ($this->state['status'] === 'paused') {
            return $this->state;
        }

        if (in_array($this->state['status'], ['error','stopped'], true)) {
            $this->state['status'] = 'running';
        }

        $batchSize = max(1, min((int)($options['batch_size'] ?? 10), 50));

        try {
            $done = $this->processBatch($batchSize);
            if ($done) {
                $this->state['status']       = 'completed';
                $this->state['completed_at'] = date('Y-m-d H:i:s');
                $this->log->success("Image migration complete", [
                    'done'    => $this->state['done'],
                    'skipped' => $this->state['skipped'],
                    'errors'  => count($this->state['errors']),
                ]);
            }
            $this->saveState();
        } catch (\Throwable $e) {
            $this->state['status'] = 'error';
            $this->state['errors'][] = $e->getMessage();
            $this->saveState();
            $this->log->error("Image batch error: " . $e->getMessage());
        }

        return $this->state;
    }

    public function pause(): array
    {
        if ($this->state['status'] === 'running') {
            $this->state['status'] = 'paused';
            $this->saveState();
            $this->log->info("Image migration paused at cursor #{$this->state['last_ps_product_id']}");
        }
        return $this->state;
    }

    public function stop(): array
    {
        $this->state['status'] = 'stopped';
        $this->saveState();
        $this->log->info("Image migration stopped. Cursor: #{$this->state['last_ps_product_id']}");
        return $this->state;
    }

    public function reset(): void
    {
        $this->state = $this->defaultState();
        $this->saveState();
        $this->log->info("Image migration reset.");
    }

    // ── Batch processing ──────────────────────────────────────────────────────

    private function countProductsWithImages(): int
    {
        $row = $this->psDb->queryOne(
            "SELECT COUNT(DISTINCT id_product) AS n FROM `{$this->p}image`"
        );
        return (int)($row['n'] ?? 0);
    }

    private function processBatch(int $batchSize): bool
    {
        // Load PS products that have image records but no physical file yet
        $cursor = $this->state['last_ps_product_id'];

        $products = $this->psDb->query(
            "SELECT DISTINCT id_product FROM `{$this->p}image`
             WHERE id_product > ?
             ORDER BY id_product ASC
             LIMIT ?",
            [$cursor, $batchSize]
        );

        if (empty($products)) return true;

        foreach ($products as $row) {
            $psId = (int)$row['id_product'];
            $this->state['last_ps_product_id'] = $psId;
            $this->processProductImages($psId);
        }

        return false; // more batches may exist
    }

    private function processProductImages(int $psProductId): void
    {
        // Get images registered for this PS product
        $images = $this->psDb->query(
            "SELECT id_image, cover FROM `{$this->p}image` WHERE id_product = ? ORDER BY position ASC",
            [$psProductId]
        );

        // Get the WC product ID from the PS product reference or via product_lang
        $wcProductId = $this->findWcProductId($psProductId);
        if (!$wcProductId) {
            $this->log->debug("No WC mapping for PS#{$psProductId} — skipping images");
            $this->state['skipped']++;
            return;
        }

        // Get WC image URLs for this product
        $wcImages = $this->getWcProductImages($wcProductId);
        if (empty($wcImages)) {
            $this->log->debug("No WC images for WC#{$wcProductId}");
            $this->state['skipped']++;
            return;
        }

        $imageCount = count($images);
        $wcCount    = count($wcImages);

        for ($i = 0; $i < $imageCount; $i++) {
            $psImage = $images[$i];
            $imgId   = (int)$psImage['id_image'];
            $wcUrl   = $wcImages[$i % $wcCount]; // cycle if more PS slots than WC images

            $success = $this->state['mode'] === 'local'
                ? $this->copyLocal($imgId, $wcUrl)
                : $this->downloadRemote($imgId, $wcUrl);

            if ($success) {
                $this->state['done']++;
                $this->log->success("Image PS#{$imgId} ← " . basename($wcUrl));
            } else {
                $this->state['errors'][] = "PS#{$imgId}: failed for $wcUrl";
                if (count($this->state['errors']) > 500) {
                    array_shift($this->state['errors']); // cap error list
                }
            }
        }
    }

    // ── WC data lookup ────────────────────────────────────────────────────────

    private function findWcProductId(int $psProductId): ?int
    {
        // Look for a matching WC post by title similarity via product_lang
        $psName = $this->psDb->queryOne(
            "SELECT name FROM `{$this->p}product_lang`
             WHERE id_product = ? AND id_lang = ? LIMIT 1",
            [$psProductId, $this->idLang]
        );
        if (!$psName) return null;

        $row = $this->wcDb->queryOne(
            "SELECT ID FROM `{$this->wcP}posts`
             WHERE post_title = ? AND post_type = 'product' LIMIT 1",
            [$psName['name']]
        );
        return $row ? (int)$row['ID'] : null;
    }

    private function getWcProductImages(int $wcProductId): array
    {
        $meta = $this->wcDb->query(
            "SELECT meta_key, meta_value FROM `{$this->wcP}postmeta`
             WHERE post_id = ? AND meta_key IN ('_thumbnail_id', '_product_image_gallery')",
            [$wcProductId]
        );

        $metaMap = [];
        foreach ($meta as $m) $metaMap[$m['meta_key']] = $m['meta_value'];

        $images    = [];
        $thumbId   = (int)($metaMap['_thumbnail_id'] ?? 0);

        if ($thumbId > 0) {
            $url = $this->getAttachmentUrl($thumbId);
            if ($url) $images[] = $url;
        }

        $gallery = $metaMap['_product_image_gallery'] ?? '';
        if ($gallery) {
            foreach (explode(',', $gallery) as $gid) {
                $gid = (int)trim($gid);
                if ($gid > 0 && $gid !== $thumbId) {
                    $url = $this->getAttachmentUrl($gid);
                    if ($url) $images[] = $url;
                }
            }
        }

        return $images;
    }

    private function getAttachmentUrl(int $id): ?string
    {
        $row = $this->wcDb->queryOne(
            "SELECT guid FROM `{$this->wcP}posts`
             WHERE ID = ? AND post_type = 'attachment' LIMIT 1",
            [$id]
        );
        return ($row && !empty($row['guid'])) ? $row['guid'] : null;
    }

    // ── Local copy ────────────────────────────────────────────────────────────

    private function copyLocal(int $imgId, string $wcUrl): bool
    {
        // Convert public URL to local filesystem path
        $wcUploadsRoot = $this->state['wc_root'];
        $psRoot        = $this->state['ps_root'];

        if (!$wcUploadsRoot || !$psRoot) {
            return $this->downloadRemote($imgId, $wcUrl); // fallback
        }

        // Extract path after /wp-content/uploads/
        if (!preg_match('#/wp-content/uploads/(.+)$#', $wcUrl, $m)) {
            return $this->downloadRemote($imgId, $wcUrl); // fallback
        }

        $localSrc = rtrim($wcUploadsRoot, '/') . '/' . $m[1];
        if (!file_exists($localSrc) || !is_readable($localSrc)) {
            $this->log->debug("Local file not found: {$localSrc}, falling back to HTTP");
            return $this->downloadRemote($imgId, $wcUrl);
        }

        // Validate MIME
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($localSrc);
        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            $this->log->warning("Not an image ({$mime}): {$localSrc}");
            return false;
        }

        // Determine extension
        $ext = strtolower(pathinfo($localSrc, PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXT, true)) $ext = 'jpg';

        $destDir  = $this->buildPsImageDir($imgId, $psRoot);
        $destFile = $destDir . '/' . $imgId . '.' . $ext;

        if (!is_dir($destDir)) mkdir($destDir, 0755, true);

        $result = copy($localSrc, $destFile);
        if ($result) {
            $this->log->debug("LOCAL copy: {$localSrc} → {$destFile}");
        }
        return $result;
    }

    // ── Remote download ───────────────────────────────────────────────────────

    private function downloadRemote(int $imgId, string $url): bool
    {
        $psRoot = $this->state['ps_root'];
        if (!$psRoot) {
            $this->log->warning("No PS root path — cannot save image #{$imgId}");
            return false;
        }

        if (!$this->isValidUrl($url)) {
            $this->log->warning("Invalid URL: {$url}");
            return false;
        }

        $ctx = stream_context_create([
            'http' => [
                'timeout'        => self::IMG_TIMEOUT_SEC,
                'max_redirects'  => 3,
                'follow_location'=> 1,
                'ignore_errors'  => true,
                'user_agent'     => 'WC2PS-ImageMigrator/2.5',
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $data = @file_get_contents($url, false, $ctx);
        if ($data === false) {
            $this->log->warning("Download failed: {$url}");
            return false;
        }

        if (strlen($data) > self::MAX_IMG_BYTES) {
            $this->log->warning("Image too large (>" . (self::MAX_IMG_BYTES/1024/1024) . "MB): {$url}");
            return false;
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($data);
        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            $this->log->warning("Not an image (MIME {$mime}): {$url}");
            return false;
        }

        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXT, true)) $ext = 'jpg';

        $destDir  = $this->buildPsImageDir($imgId, $psRoot);
        $destFile = $destDir . '/' . $imgId . '.' . $ext;

        if (!is_dir($destDir)) mkdir($destDir, 0755, true);

        return file_put_contents($destFile, $data) !== false;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function buildPsImageDir(int $imgId, string $psRoot): string
    {
        $digits = str_split((string)abs($imgId));
        return rtrim($psRoot, '/') . '/img/p/' . implode('/', $digits);
    }

    private function isValidUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http','https'], true)) return false;
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) return false;
        // SSRF protection
        $ip = gethostbyname($host);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            if (!filter_var($ip, FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $this->log->warning("Blocked private IP: {$host} ({$ip})");
                return false;
            }
        }
        return true;
    }
}
