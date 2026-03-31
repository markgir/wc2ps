<?php
declare(strict_types=1);

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../DebugLogger.php';

/**
 * PSAnalyser — introspects the PrestaShop target database.
 *
 * Detects PS version, default lang/shop, existing categories,
 * available columns per table (for safe dynamic inserts), and issues.
 */
class PSAnalyser
{
    private Database    $db;
    private string      $p;
    private DebugLogger $log;

    /** Column name → type cache */
    private array $schemaCache = [];

    public function __construct(Database $db, DebugLogger $log)
    {
        $this->db  = $db;
        $this->p   = $db->getPrefix();
        $this->log = $log;
    }

    // ── Full analysis ─────────────────────────────────────────────────────────

    public function analyse(): array
    {
        $this->log->setStep('analyse-ps');
        $this->log->info('Starting PrestaShop database analysis…');

        $result = [
            'version'        => $this->detectVersion(),
            'default_lang'   => $this->getDefaultLangId(),
            'default_shop'   => $this->getDefaultShopId(),
            'root_category'  => $this->getRootCategoryId(),
            'home_category'  => $this->getHomeCategoryId(),
            'existing_counts'=> $this->getExistingCounts(),
            'tables'         => $this->getRelevantTables(),
            'schemas'        => $this->introspectSchemas(),
            'issues'         => $this->detectIssues(),
        ];

        $this->log->info('PrestaShop analysis complete', [
            'version'      => $result['version'],
            'default_lang' => $result['default_lang'],
            'default_shop' => $result['default_shop'],
            'existing_products' => $result['existing_counts']['products'],
        ]);

        return $result;
    }

    // ── Version ──────────────────────────────────────────────────────────────

    public function detectVersion(): string
    {
        $row = $this->db->queryOne(
            "SELECT value FROM `{$this->p}configuration` WHERE name='PS_VERSION_DB' LIMIT 1"
        );
        $ver = $row ? (string)$row['value'] : 'unknown';
        $this->log->debug("PS version: {$ver}");
        return $ver;
    }

    // ── Default IDs ───────────────────────────────────────────────────────────

    public function getDefaultLangId(): int
    {
        $row = $this->db->queryOne(
            "SELECT value FROM `{$this->p}configuration` WHERE name='PS_LANG_DEFAULT' LIMIT 1"
        );
        return $row ? (int)$row['value'] : 1;
    }

    public function getDefaultShopId(): int
    {
        $row = $this->db->queryOne(
            "SELECT id_shop FROM `{$this->p}shop` ORDER BY id_shop ASC LIMIT 1"
        );
        return $row ? (int)$row['id_shop'] : 1;
    }

    public function getRootCategoryId(): int
    {
        $row = $this->db->queryOne(
            "SELECT value FROM `{$this->p}configuration` WHERE name='PS_ROOT_CATEGORY' LIMIT 1"
        );
        return $row ? (int)$row['value'] : 1;
    }

    public function getHomeCategoryId(): int
    {
        $row = $this->db->queryOne(
            "SELECT value FROM `{$this->p}configuration` WHERE name='PS_HOME_CATEGORY' LIMIT 1"
        );
        return $row ? (int)$row['value'] : 2;
    }

    // ── Existing data counts ──────────────────────────────────────────────────

    public function getExistingCounts(): array
    {
        $counts = [];
        $tables = ['product','category','attribute_group','specific_price','image'];
        foreach ($tables as $t) {
            $full = $this->p . $t;
            if ($this->db->tableExists($full)) {
                $idCol = 'id_' . $t;
                $row   = $this->db->queryOne("SELECT COUNT(*) AS n FROM `{$full}`");
                $counts[$t . 's'] = (int)($row['n'] ?? 0);
            } else {
                $counts[$t . 's'] = null; // table absent
            }
        }
        return $counts;
    }

    // ── Table presence ────────────────────────────────────────────────────────

    public function getRelevantTables(): array
    {
        $want = [
            'product','product_lang','product_shop',
            'category','category_lang','category_shop',
            'attribute','attribute_lang','attribute_group','attribute_group_lang',
            'attribute_group_shop','attribute_shop',
            'product_attribute','product_attribute_combination','product_attribute_shop',
            'stock_available',
            'image','image_lang','image_shop',
            'specific_price',
            'category_product',
            'search_index',
            'configuration','shop','lang',
        ];
        $found = [];
        foreach ($want as $t) {
            $found[$t] = $this->db->tableExists($this->p . $t);
            if (!$found[$t]) {
                $this->log->debug("PS table absent: {$this->p}{$t}");
            }
        }
        return $found;
    }

    // ── Column introspection ──────────────────────────────────────────────────

    public function introspectSchemas(): array
    {
        $tables = [
            'product','product_lang','product_shop',
            'category',
            'product_attribute','product_attribute_shop',
            'stock_available',
            'specific_price',
            'image',
        ];
        $schemas = [];
        foreach ($tables as $t) {
            $full = $this->p . $t;
            if ($this->db->tableExists($full)) {
                $cols = $this->db->getColumnTypes($full);
                $schemas[$t] = $cols;
                // Warm the cache using the FULL name so getColumnTypes hits cache
                $this->schemaCache[$full] = $cols;
                $this->log->schema($full, array_keys($cols));
            }
        }
        return $schemas;
    }

    /**
     * Get cached column types for a table.
     *
     * @param string $table  FULL table name, already including prefix (e.g. "ps_product").
     *                       PSWriter always calls this with $this->p . 'table_name'.
     */
    public function getColumnTypes(string $table): array
    {
        // $table is already the full name — do NOT add prefix again.
        if (!isset($this->schemaCache[$table])) {
            $this->schemaCache[$table] = $this->db->tableExists($table)
                ? $this->db->getColumnTypes($table)
                : [];
            if (empty($this->schemaCache[$table])) {
                // Table absent or empty — log once so it's visible in debug
                // (avoid spamming for optional tables like image_lang)
            }
        }
        return $this->schemaCache[$table];
    }

    public function getColumns(string $table): array
    {
        return array_keys($this->getColumnTypes($table));
    }

    // ── Issue detection ───────────────────────────────────────────────────────

    public function detectIssues(): array
    {
        $issues = [];

        // Required core tables
        $required = ['product','category','configuration'];
        foreach ($required as $t) {
            if (!$this->db->tableExists($this->p . $t)) {
                $issues[] = ['level' => 'error', 'msg' => "Required PS table `{$this->p}{$t}` not found. Is this a PrestaShop database?"];
            }
        }

        // Warn if there are already products (potential duplicate risk)
        $existing = (int)($this->db->queryOne(
            "SELECT COUNT(*) AS n FROM `{$this->p}product`"
        )['n'] ?? 0);
        if ($existing > 0) {
            $issues[] = ['level' => 'warning', 'msg' => "{$existing} products already exist in PrestaShop. Migration will ADD to these."];
        }

        // Check default lang exists
        $langId = $this->getDefaultLangId();
        $lang   = $this->db->queryOne(
            "SELECT id_lang FROM `{$this->p}lang` WHERE id_lang = ? LIMIT 1",
            [$langId]
        );
        if (!$lang) {
            $issues[] = ['level' => 'error', 'msg' => "Default language id={$langId} not found in ps_lang."];
        }

        foreach ($issues as $i) $this->log->warning('[PS issue] ' . $i['msg']);
        return $issues;
    }
    /**
     * Try to auto-detect the PS root path on the filesystem.
     * Reads PS_SHOP_DOMAIN from ps_configuration, then builds the path
     * using the same www base logic as WCAnalyser::detectUploadsPath().
     */
    public function detectRootPath(): string
    {
        // Get shop domain from PS configuration table
        $rows = $this->db->query(
            "SELECT value FROM `{$this->p}configuration` WHERE name = 'PS_SHOP_DOMAIN' LIMIT 1"
        );
        $domain = $rows[0]['value'] ?? '';
        if (!$domain) return '';

        // Strip port if present
        $host = explode(':', $domain)[0];
        if (!$host) return '';

        $selfDir = dirname(dirname(__DIR__));
        $wwwBase = dirname($selfDir);

        $candidate = $wwwBase . '/' . $host;
        if (is_dir($candidate . '/img/p')) return $candidate;

        $hostNoWww = preg_replace('/^www\./', '', $host);
        $candidate2 = $wwwBase . '/' . $hostNoWww;
        if (is_dir($candidate2 . '/img/p')) return $candidate2;

        $candidate3 = $wwwBase . '/www.' . $hostNoWww;
        if (is_dir($candidate3 . '/img/p')) return $candidate3;

        return '';
    }

}