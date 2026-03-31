<?php
declare(strict_types=1);

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../DebugLogger.php';
require_once __DIR__ . '/../FieldMapper.php';

/**
 * WCAnalyser — inspects the WooCommerce database and produces:
 *   - Counts (products, variations, categories, attributes, images)
 *   - WC/WP version detection
 *   - Sample data for mapping preview
 *   - Detected custom meta keys
 *   - Issues / warnings (missing tables, low data quality)
 */
class WCAnalyser
{
    private Database    $db;
    private string      $p;
    private DebugLogger $log;

    public function __construct(Database $db, DebugLogger $log)
    {
        $this->db  = $db;
        $this->p   = $db->getPrefix();
        $this->log = $log;
    }

    // ── Full analysis ────────────────────────────────────────────────────────

    public function analyse(): array
    {
        $this->log->setStep('analyse-wc');
        $this->log->info('Starting WooCommerce database analysis…');

        $result = [
            'versions'      => $this->detectVersions(),
            'counts'        => $this->getCounts(),
            'meta_keys'     => $this->discoverMetaKeys(),
            'sample'        => $this->getSampleProducts(3),
            'categories'    => $this->getCategoryTree(),
            'attributes'    => $this->getAttributeTaxonomies(),
            'issues'        => $this->detectIssues(),
            'tables_found'  => $this->getRelevantTables(),
        ];

        $this->log->info('WooCommerce analysis complete', [
            'products'   => $result['counts']['products'],
            'variations' => $result['counts']['variations'],
            'categories' => $result['counts']['categories'],
            'attributes' => $result['counts']['attributes'],
            'issues'     => count($result['issues']),
        ]);

        return $result;
    }

    // ── Versions ─────────────────────────────────────────────────────────────

    public function detectVersions(): array
    {
        $wp  = $this->getOption('db_version')  ?? '?';
        $wc  = $this->getOption('woocommerce_version') ?? '?';
        $url = $this->getOption('siteurl') ?? '';
        $this->log->debug("WP db_version={$wp}, WC={$wc}, url={$url}");
        return ['wordpress' => $wp, 'woocommerce' => $wc, 'site_url' => $url];
    }

    private function getOption(string $name): ?string
    {
        $row = $this->db->queryOne(
            "SELECT option_value FROM `{$this->p}options` WHERE option_name = ? LIMIT 1",
            [$name]
        );
        return $row ? (string) $row['option_value'] : null;
    }

    // ── Counts ────────────────────────────────────────────────────────────────

    public function getCounts(): array
    {
        $counts = [];

        $counts['products'] = (int) ($this->db->queryOne(
            "SELECT COUNT(*) AS n FROM `{$this->p}posts`
             WHERE post_type='product' AND post_status IN ('publish','draft','pending')"
        )['n'] ?? 0);

        $counts['variations'] = (int) ($this->db->queryOne(
            "SELECT COUNT(*) AS n FROM `{$this->p}posts`
             WHERE post_type='product_variation' AND post_status IN ('publish','private','inherit')"
        )['n'] ?? 0);

        $counts['variable_products'] = (int) ($this->db->queryOne(
            "SELECT COUNT(DISTINCT tr.object_id) AS n
             FROM `{$this->p}term_relationships` tr
             JOIN `{$this->p}term_taxonomy` tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
             JOIN `{$this->p}terms` t ON tt.term_id = t.term_id
             WHERE tt.taxonomy = 'product_type' AND t.slug = 'variable'"
        )['n'] ?? 0);

        $counts['simple_products'] = (int) ($this->db->queryOne(
            "SELECT COUNT(DISTINCT tr.object_id) AS n
             FROM `{$this->p}term_relationships` tr
             JOIN `{$this->p}term_taxonomy` tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
             JOIN `{$this->p}terms` t ON tt.term_id = t.term_id
             WHERE tt.taxonomy = 'product_type' AND t.slug = 'simple'"
        )['n'] ?? 0);

        $counts['categories'] = (int) ($this->db->queryOne(
            "SELECT COUNT(*) AS n FROM `{$this->p}term_taxonomy` WHERE taxonomy='product_cat'"
        )['n'] ?? 0);

        $counts['attributes'] = $this->db->tableExists($this->p . 'woocommerce_attribute_taxonomies')
            ? (int) ($this->db->queryOne(
                "SELECT COUNT(*) AS n FROM `{$this->p}woocommerce_attribute_taxonomies`"
              )['n'] ?? 0)
            : 0;

        $counts['images'] = (int) ($this->db->queryOne(
            "SELECT COUNT(*) AS n FROM `{$this->p}posts`
             WHERE post_type='attachment' AND post_mime_type LIKE 'image/%'"
        )['n'] ?? 0);

        $counts['products_with_images'] = (int) ($this->db->queryOne(
            "SELECT COUNT(DISTINCT post_id) AS n FROM `{$this->p}postmeta`
             WHERE meta_key = '_thumbnail_id'"
        )['n'] ?? 0);

        $this->log->debug('Counts', $counts);
        return $counts;
    }

    // ── Meta key discovery ────────────────────────────────────────────────────

    public function discoverMetaKeys(): array
    {
        // Standard WC keys we care about
        $standard = [
            '_regular_price','_sale_price','_price','_sku','_stock','_stock_quantity',
            '_stock_status','_weight','_length','_width','_height','_manage_stock',
            '_backorders','_sold_individually','_virtual','_downloadable',
            '_product_type','_thumbnail_id','_product_image_gallery',
        ];

        // Find which ones actually exist + their coverage %
        $total = max(1, (int) ($this->db->queryOne(
            "SELECT COUNT(*) AS n FROM `{$this->p}posts` WHERE post_type='product'"
        )['n'] ?? 1));

        $coverage = [];
        foreach ($standard as $key) {
            $cnt = (int) ($this->db->queryOne(
                "SELECT COUNT(DISTINCT post_id) AS n FROM `{$this->p}postmeta`
                 WHERE meta_key = ?",
                [$key]
            )['n'] ?? 0);
            if ($cnt > 0) {
                $coverage[$key] = [
                    'count'    => $cnt,
                    'coverage' => round(($cnt / $total) * 100, 1),
                    'standard' => true,
                ];
            }
        }

        // Find non-standard custom keys (top 10 by frequency)
        $custom = $this->db->query(
            "SELECT meta_key, COUNT(DISTINCT post_id) AS cnt
             FROM `{$this->p}postmeta` pm
             JOIN `{$this->p}posts` p ON pm.post_id = p.ID
             WHERE p.post_type = 'product'
               AND meta_key NOT LIKE '\_%'
             GROUP BY meta_key
             ORDER BY cnt DESC
             LIMIT 10"
        );
        foreach ($custom as $row) {
            $coverage[$row['meta_key']] = [
                'count'    => (int) $row['cnt'],
                'coverage' => round(((int) $row['cnt'] / $total) * 100, 1),
                'standard' => false,
            ];
        }

        $this->log->debug('Meta keys discovered', ['count' => count($coverage)]);
        return $coverage;
    }

    // ── Sample products ───────────────────────────────────────────────────────

    public function getSampleProducts(int $limit = 3): array
    {
        $rows = $this->db->query(
            "SELECT p.ID, p.post_title, p.post_status,
                    t.slug AS product_type
             FROM `{$this->p}posts` p
             LEFT JOIN `{$this->p}term_relationships` tr ON tr.object_id = p.ID
             LEFT JOIN `{$this->p}term_taxonomy` tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                       AND tt.taxonomy = 'product_type'
             LEFT JOIN `{$this->p}terms` t ON tt.term_id = t.term_id
             WHERE p.post_type = 'product' AND p.post_status IN ('publish','draft')
             ORDER BY p.ID ASC
             LIMIT ?",
            [$limit]
        );

        $samples = [];
        foreach ($rows as $row) {
            $meta = $this->getProductMeta((int) $row['ID']);
            $samples[] = [
                'id'           => (int) $row['ID'],
                'title'        => $row['post_title'],
                'status'       => $row['post_status'],
                'type'         => $row['product_type'] ?? 'simple',
                'price'        => $meta['_regular_price'] ?? '',
                'sku'          => $meta['_sku'] ?? '',
                'stock'        => $meta['_stock'] ?? $meta['_stock_quantity'] ?? '',
                'weight'       => $meta['_weight'] ?? '',
                'has_image'    => isset($meta['_thumbnail_id']),
            ];
        }
        return $samples;
    }

    private function getProductMeta(int $id): array
    {
        $rows = $this->db->query(
            "SELECT meta_key, meta_value FROM `{$this->p}postmeta` WHERE post_id = ?",
            [$id]
        );
        $meta = [];
        foreach ($rows as $r) {
            $meta[$r['meta_key']] = FieldMapper::safeUnserialize($r['meta_value'] ?? '');
        }
        return $meta;
    }

    // ── Category tree ─────────────────────────────────────────────────────────

    public function getCategoryTree(): array
    {
        $rows = $this->db->query(
            "SELECT t.term_id, t.name, t.slug, tt.parent, tt.count
             FROM `{$this->p}terms` t
             JOIN `{$this->p}term_taxonomy` tt ON t.term_id = tt.term_id
             WHERE tt.taxonomy = 'product_cat'
             ORDER BY tt.parent ASC, t.term_id ASC"
        );
        $cats = [];
        foreach ($rows as $r) {
            $cats[(int)$r['term_id']] = [
                'id'     => (int) $r['term_id'],
                'name'   => $r['name'],
                'slug'   => $r['slug'],
                'parent' => (int) $r['parent'],
                'count'  => (int) $r['count'],
            ];
        }
        return $cats;
    }

    // ── Attributes ────────────────────────────────────────────────────────────

    public function getAttributeTaxonomies(): array
    {
        if (!$this->db->tableExists($this->p . 'woocommerce_attribute_taxonomies')) return [];

        $rows = $this->db->query(
            "SELECT attribute_id, attribute_name, attribute_label, attribute_type
             FROM `{$this->p}woocommerce_attribute_taxonomies`
             ORDER BY attribute_id ASC"
        );

        $attrs = [];
        foreach ($rows as $r) {
            $taxonomy = 'pa_' . $r['attribute_name'];
            $termCount = (int) ($this->db->queryOne(
                "SELECT COUNT(*) AS n FROM `{$this->p}term_taxonomy` WHERE taxonomy = ?",
                [$taxonomy]
            )['n'] ?? 0);

            $attrs[(int)$r['attribute_id']] = [
                'id'         => (int) $r['attribute_id'],
                'name'       => $r['attribute_name'],
                'label'      => $r['attribute_label'],
                'type'       => $r['attribute_type'],
                'taxonomy'   => $taxonomy,
                'term_count' => $termCount,
            ];
        }
        return $attrs;
    }

    // ── Issue detection ───────────────────────────────────────────────────────

    public function detectIssues(): array
    {
        $issues = [];

        // Check required tables
        $required = ['posts','postmeta','terms','term_taxonomy','term_relationships','options'];
        foreach ($required as $t) {
            if (!$this->db->tableExists($this->p . $t)) {
                $issues[] = ['level' => 'error', 'msg' => "Required table `{$this->p}{$t}` not found."];
            }
        }

        // Products without price
        $noprice = (int) ($this->db->queryOne(
            "SELECT COUNT(DISTINCT p.ID) AS n
             FROM `{$this->p}posts` p
             WHERE p.post_type='product' AND p.post_status='publish'
               AND NOT EXISTS (
                 SELECT 1 FROM `{$this->p}postmeta` pm
                 WHERE pm.post_id = p.ID AND pm.meta_key = '_regular_price' AND pm.meta_value != ''
               )"
        )['n'] ?? 0);
        if ($noprice > 0)
            $issues[] = ['level' => 'warning', 'msg' => "{$noprice} published products have no regular price."];

        // Products without SKU
        $nosku = (int) ($this->db->queryOne(
            "SELECT COUNT(DISTINCT p.ID) AS n
             FROM `{$this->p}posts` p
             WHERE p.post_type='product' AND p.post_status='publish'
               AND NOT EXISTS (
                 SELECT 1 FROM `{$this->p}postmeta` pm
                 WHERE pm.post_id = p.ID AND pm.meta_key = '_sku' AND pm.meta_value != ''
               )"
        )['n'] ?? 0);
        if ($nosku > 0)
            $issues[] = ['level' => 'info', 'msg' => "{$nosku} products have no SKU — reference will be empty."];

        // Duplicate SKUs
        $dupsku = (int) ($this->db->queryOne(
            "SELECT COUNT(*) AS n FROM (
               SELECT meta_value, COUNT(*) AS c
               FROM `{$this->p}postmeta`
               WHERE meta_key='_sku' AND meta_value != ''
               GROUP BY meta_value HAVING c > 1
             ) t"
        )['n'] ?? 0);
        if ($dupsku > 0)
            $issues[] = ['level' => 'warning', 'msg' => "{$dupsku} duplicate SKUs found — may cause reference conflicts in PS."];

        foreach ($issues as $i) $this->log->write($i['level'] ?? 'info', '[issue] ' . $i['msg']);
        return $issues;
    }

    // ── Table discovery ───────────────────────────────────────────────────────

    public function getRelevantTables(): array
    {
        $all  = $this->db->getTables($this->p);
        $want = ['posts','postmeta','terms','term_taxonomy','term_relationships',
                 'options','woocommerce_attribute_taxonomies','woocommerce_order_items'];
        $found = [];
        foreach ($want as $t) {
            $full = $this->p . $t;
            $found[$t] = in_array($full, $all, true);
        }
        return $found;
    }

    // ── Batch product reader (used by Migrator) ────────────────────────────────

    public function getProductsBatch(int $afterId = 0, int $limit = 20): array
    {
        $limit = max(1, min($limit, 200));

        $rows = $this->db->query(
            "SELECT ID, post_title, post_content, post_excerpt, post_status,
                    post_date, post_modified
             FROM `{$this->p}posts`
             WHERE post_type = 'product'
               AND post_status IN ('publish','draft','pending')
               AND ID > ?
             ORDER BY ID ASC LIMIT ?",
            [$afterId, $limit]
        );
        if (empty($rows)) return [];

        $productIds  = array_column($rows, 'ID');
        $allMeta     = $this->getBatchMeta($productIds);
        $allCatIds   = $this->getBatchCategoryIds($productIds);

        $products = [];
        foreach ($rows as $row) {
            $id   = (int) $row['ID'];
            $meta = $allMeta[$id] ?? [];

            // Resolve type from taxonomy (WC 5+ stores type in taxonomy, not meta)
            $type = null;
            if (isset($meta['_product_type'])) {
                $type = (string) $meta['_product_type'];
            } else {
                $tr = $this->db->queryOne(
                    "SELECT t.slug FROM `{$this->p}terms` t
                     JOIN `{$this->p}term_taxonomy` tt ON t.term_id = tt.term_id
                     JOIN `{$this->p}term_relationships` tr ON tt.term_taxonomy_id = tr.term_taxonomy_id
                     WHERE tr.object_id = ? AND tt.taxonomy = 'product_type' LIMIT 1",
                    [$id]
                );
                $type = $tr['slug'] ?? 'simple';
            }

            $products[$id] = [
                'id'          => $id,
                'title'       => $row['post_title'] ?? '',
                'description' => FieldMapper::stripHtml($row['post_content'] ?? ''),
                'short_desc'  => FieldMapper::stripHtml($row['post_excerpt'] ?? ''),
                'status'      => $row['post_status'],
                'date_add'    => $row['post_date'],
                'date_upd'    => $row['post_modified'],
                'type'        => $type,
                'meta'        => $meta,
                'category_ids'=> $allCatIds[$id] ?? [],
                'images'      => $this->getProductImages($id, $meta),
            ];

            if ($type === 'variable') {
                $products[$id]['variations'] = $this->getVariations($id);
            }
        }
        return $products;
    }

    private function getBatchMeta(array $ids): array
    {
        if (empty($ids)) return [];
        $ph   = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->db->query(
            "SELECT post_id, meta_key, meta_value FROM `{$this->p}postmeta` WHERE post_id IN ({$ph})",
            $ids
        );
        $all = [];
        foreach ($rows as $r) {
            $all[(int)$r['post_id']][$r['meta_key']] = FieldMapper::safeUnserialize($r['meta_value'] ?? '');
        }
        return $all;
    }

    private function getBatchCategoryIds(array $ids): array
    {
        if (empty($ids)) return [];
        $ph   = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->db->query(
            "SELECT tr.object_id, t.term_id
             FROM `{$this->p}terms` t
             JOIN `{$this->p}term_taxonomy` tt ON t.term_id = tt.term_id
             JOIN `{$this->p}term_relationships` tr ON tt.term_taxonomy_id = tr.term_taxonomy_id
             WHERE tr.object_id IN ({$ph}) AND tt.taxonomy = 'product_cat'",
            $ids
        );
        $result = [];
        foreach ($rows as $r) {
            $result[(int)$r['object_id']][] = (int)$r['term_id'];
        }
        return $result;
    }

    public function getProductImages(int $productId, array $meta = []): array
    {
        $images = [];
        $thumbId = (int) ($meta['_thumbnail_id'] ?? 0);
        if ($thumbId > 0) {
            $url = $this->getAttachmentUrl($thumbId);
            if ($url) $images[] = $url;
        }
        $gallery = $meta['_product_image_gallery'] ?? '';
        if (is_string($gallery) && $gallery !== '') {
            foreach (explode(',', $gallery) as $gid) {
                $gid = (int) trim($gid);
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
        $row = $this->db->queryOne(
            "SELECT guid FROM `{$this->p}posts` WHERE ID = ? AND post_type='attachment' LIMIT 1",
            [$id]
        );
        return ($row && !empty($row['guid'])) ? $row['guid'] : null;
    }

    public function getVariations(int $parentId): array
    {
        $rows = $this->db->query(
            "SELECT ID, post_status, menu_order FROM `{$this->p}posts`
             WHERE post_parent = ? AND post_type = 'product_variation'
             ORDER BY menu_order ASC, ID ASC",
            [$parentId]
        );
        if (empty($rows)) return [];

        $varIds  = array_column($rows, 'ID');
        $allMeta = $this->getBatchMeta($varIds);

        $variations = [];
        foreach ($rows as $r) {
            $vid  = (int) $r['ID'];
            $vmeta= $allMeta[$vid] ?? [];
            $variations[$vid] = [
                'id'     => $vid,
                'status' => $r['post_status'],
                'meta'   => $vmeta,
                'attrs'  => $this->extractVariationAttributes($vmeta),
            ];
        }
        return $variations;
    }

    /**
     * Extract attribute slug → normalised term slug pairs from variation meta.
     * Both key and value are lowercased/trimmed to match the attr_value_map key format.
     * Empty value = "Any" — preserved intentionally, skipped in PSExporter.
     */
    private function extractVariationAttributes(array $meta): array
    {
        $attrs = [];
        foreach ($meta as $key => $value) {
            if (!is_string($key) || strpos($key, 'attribute_') !== 0) continue;

            $attrSlug = strpos($key, 'attribute_pa_') === 0
                ? substr($key, strlen('attribute_pa_'))
                : substr($key, strlen('attribute_'));

            $attrSlug = strtolower(trim($attrSlug));
            $termSlug = strtolower(trim(is_string($value) ? $value : (string)$value));
            if ($attrSlug === '') continue;
            $attrs[$attrSlug] = $termSlug;
        }
        return $attrs;
    }

    public function countProducts(): int
    {
        return (int) ($this->db->queryOne(
            "SELECT COUNT(*) AS n FROM `{$this->p}posts`
             WHERE post_type='product' AND post_status IN ('publish','draft','pending')"
        )['n'] ?? 0);
    }

    public function countCategories(): int
    {
        return (int) ($this->db->queryOne(
            "SELECT COUNT(*) AS n FROM `{$this->p}term_taxonomy` WHERE taxonomy='product_cat'"
        )['n'] ?? 0);
    }

    public function countAttributes(): int
    {
        if (!$this->db->tableExists($this->p . 'woocommerce_attribute_taxonomies')) return 0;
        return (int) ($this->db->queryOne(
            "SELECT COUNT(*) AS n FROM `{$this->p}woocommerce_attribute_taxonomies`"
        )['n'] ?? 0);
    }

    public function getAttributeTerms(string $taxonomy): array
    {
        $rows = $this->db->query(
            "SELECT t.term_id, t.name, t.slug
             FROM `{$this->p}terms` t
             JOIN `{$this->p}term_taxonomy` tt ON t.term_id = tt.term_id
             WHERE tt.taxonomy = ? ORDER BY t.term_id ASC",
            [$taxonomy]
        );
        return $rows;
    }

    public function getSiteUrl(): string
    {
        return rtrim((string)($this->getOption('siteurl') ?? ''), '/');
    }

    /**
     * Try to auto-detect the WC uploads path on the filesystem.
     * Uses the migration tool's own location to find the www base dir,
     * then combines with the site domain extracted from siteurl.
     */
    public function detectUploadsPath(): string
    {
        $siteUrl = $this->getSiteUrl();
        if (!$siteUrl) return '';

        $host = parse_url($siteUrl, PHP_URL_HOST) ?: '';
        if (!$host) return '';

        // Base: go up from migration tool's own directory to the www root
        // e.g. /var/www/atena/data/www/migration.domain.pt/ → /var/www/atena/data/www/
        $selfDir = dirname(dirname(__DIR__)); // src/ → project root
        $wwwBase = dirname($selfDir);         // project root → www base

        // Try: wwwBase/host/wp-content/uploads
        $candidate = $wwwBase . '/' . $host . '/wp-content/uploads';
        if (is_dir($candidate)) return $candidate;

        // Try without www prefix
        $hostNoWww = preg_replace('/^www\./', '', $host);
        $candidate2 = $wwwBase . '/' . $hostNoWww . '/wp-content/uploads';
        if (is_dir($candidate2)) return $candidate2;

        // Try with www prefix
        $candidate3 = $wwwBase . '/www.' . $hostNoWww . '/wp-content/uploads';
        if (is_dir($candidate3)) return $candidate3;

        return '';
    }
}

// Allow DebugLogger::write() to be called with level string (it's private in original)
// Workaround: just add a public proxy in DebugLogger — done already above.
