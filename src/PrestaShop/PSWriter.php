<?php
declare(strict_types=1);

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../DebugLogger.php';
require_once __DIR__ . '/../FieldMapper.php';
require_once __DIR__ . '/PSAnalyser.php';

/**
 * PSWriter — writes products, categories, attributes, combinations
 * and images into a PrestaShop 1.7 / 8.x / 9.x database.
 *
 * Transaction ownership: PSWriter methods do NOT open/commit their own
 * transactions. The Migrator opens one per product and calls
 * beginTransaction() / commit() / rollback() on this class.
 *
 * Dynamic inserts: every INSERT filters columns through SHOW COLUMNS
 * (cached) so that schema differences across PS versions are handled
 * automatically — no hard-coded version branches.
 */
class PSWriter
{
    private Database    $db;
    private PSAnalyser  $analyser;
    private DebugLogger $log;
    private string      $p;
    private int         $idLang;
    private int         $idShop;

    /** Allowed image extensions for download. */
    private const ALLOWED_IMG_EXT  = ['jpg','jpeg','png','gif','webp'];
    private const MAX_IMG_BYTES    = 5 * 1024 * 1024;
    private const IMG_TIMEOUT_SEC  = 15;

    public function __construct(
        Database    $db,
        PSAnalyser  $analyser,
        DebugLogger $log,
        int         $idLang = 1,
        int         $idShop = 1
    ) {
        $this->db       = $db;
        $this->analyser = $analyser;
        $this->log      = $log;
        $this->p        = $db->getPrefix();
        $this->idLang   = max(1, $idLang);
        $this->idShop   = max(1, $idShop);
    }

    // ── Transaction delegates (Migrator calls these) ──────────────────────────

    public function beginTransaction(): void { $this->db->beginTransaction(); }
    public function commit(): void           { $this->db->commit(); }
    public function rollback(): void         { $this->db->rollback(); }

    // ── Dynamic INSERT helpers ────────────────────────────────────────────────

    /**
     * INSERT — filters data to only include columns that actually exist
     * in the target table, then sanitises numeric fields.
     */
    private function dynamicInsert(string $table, array $data, bool $ignore = false): int
    {
        $types    = $this->analyser->getColumnTypes($table);
        $existing = array_keys($types);
        $flip     = array_flip($existing);

        $filtered = [];
        foreach ($data as $col => $val) {
            if (!isset($flip[$col])) continue;
            $filtered[$col] = FieldMapper::coerceNumeric($val, $types[$col] ?? '');
        }

        if (empty($filtered)) {
            // If schema cache is empty the table is absent — warn and skip for optional tables,
            // throw for tables that are required for product integrity.
            $required = [$this->p.'product', $this->p.'product_lang', $this->p.'stock_available'];
            if (in_array($table, $required, true)) {
                throw new \RuntimeException("Required PS table `{$table}` has no matching columns — is this a PrestaShop database?");
            }
            $this->log->warning("dynamicInsert: skipping `{$table}` — no valid columns (table may be absent)");
            return 0;
        }

        $cols  = array_keys($filtered);
        $cSql  = implode(', ', array_map(fn($c) => "`{$c}`", $cols));
        $pSql  = implode(', ', array_fill(0, count($cols), '?'));
        $verb  = $ignore ? 'INSERT IGNORE' : 'INSERT';
        $sql   = "{$verb} INTO `{$table}` ({$cSql}) VALUES ({$pSql})";

        $id = $this->db->execute($sql, array_values($filtered));
        $this->log->debug("INSERT `{$table}` → id={$id}");
        return $id;
    }

    /**
     * INSERT … ON DUPLICATE KEY UPDATE — dynamic column filtering + upsert.
     */
    private function dynamicUpsert(string $table, array $data, array $updateCols): int
    {
        $types    = $this->analyser->getColumnTypes($table);
        $existing = array_keys($types);
        $flip     = array_flip($existing);

        $filtered = [];
        foreach ($data as $col => $val) {
            if (!isset($flip[$col])) continue;
            $filtered[$col] = FieldMapper::coerceNumeric($val, $types[$col] ?? '');
        }
        if (empty($filtered)) return 0;

        $cols    = array_keys($filtered);
        $cSql    = implode(', ', array_map(fn($c) => "`{$c}`", $cols));
        $pSql    = implode(', ', array_fill(0, count($cols), '?'));

        $updParts = [];
        foreach ($updateCols as $uc) {
            if (isset($flip[$uc])) $updParts[] = "`{$uc}` = VALUES(`{$uc}`)";
        }
        $updSql = $updParts ? ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updParts) : '';

        $sql = "INSERT INTO `{$table}` ({$cSql}) VALUES ({$pSql}){$updSql}";
        return $this->db->execute($sql, array_values($filtered));
    }

    // ── PrestaShop info (passthrough to analyser) ─────────────────────────────

    public function getHomeCategoryId(): int   { return $this->analyser->getHomeCategoryId(); }
    public function getRootCategoryId(): int   { return $this->analyser->getRootCategoryId(); }
    public function getDefaultLangId(): int    { return $this->idLang; }
    public function getDefaultShopId(): int    { return $this->idShop; }

    // ── Categories ────────────────────────────────────────────────────────────

    /**
     * Insert a category using nested-set tree arithmetic.
     * Returns new id_category.
     */
    public function insertCategory(array $wcCat, int $parentPsId): int
    {
        $slug  = FieldMapper::slugify($wcCat['name'] ?? '');
        $depth = $this->getCategoryDepth($parentPsId) + 1;

        $this->db->beginTransaction();
        try {
            $rightBound = $this->getCategoryRightBound($parentPsId);

            $this->db->execute(
                "UPDATE `{$this->p}category` SET nleft  = nleft  + 2 WHERE nleft  >= ?", [$rightBound]
            );
            $this->db->execute(
                "UPDATE `{$this->p}category` SET nright = nright + 2 WHERE nright >= ?", [$rightBound]
            );

            $id = $this->db->execute(
                "INSERT INTO `{$this->p}category`
                    (id_parent, level_depth, nleft, nright, active, date_add, date_upd, position, is_root_category)
                 VALUES (?, ?, ?, ?, 1, NOW(), NOW(), 0, 0)",
                [$parentPsId, $depth, $rightBound, $rightBound + 1]
            );

            $this->db->execute(
                "INSERT INTO `{$this->p}category_lang`
                    (id_category, id_shop, id_lang, name, description, link_rewrite,
                     meta_title, meta_keywords, meta_description)
                 VALUES (?, ?, ?, ?, ?, ?, '', '', '')",
                [$id, $this->idShop, $this->idLang,
                 FieldMapper::truncate($wcCat['name'] ?? '', 128),
                 $wcCat['description'] ?? '',
                 FieldMapper::truncate($slug, 128)]
            );

            if ($this->db->tableExists($this->p . 'category_shop')) {
                $this->db->execute(
                    "INSERT IGNORE INTO `{$this->p}category_shop` (id_category, id_shop) VALUES (?, ?)",
                    [$id, $this->idShop]
                );
            }

            $this->db->commit();
            $this->log->debug("Category inserted: '{$wcCat['name']}' → id={$id}");
            return $id;
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    private function getCategoryDepth(int $catId): int
    {
        $row = $this->db->queryOne(
            "SELECT level_depth FROM `{$this->p}category` WHERE id_category = ? LIMIT 1", [$catId]
        );
        return (int)($row['level_depth'] ?? 0);
    }

    private function getCategoryRightBound(int $catId): int
    {
        $row = $this->db->queryOne(
            "SELECT nright FROM `{$this->p}category` WHERE id_category = ? LIMIT 1", [$catId]
        );
        return (int)($row['nright'] ?? 2);
    }

    // ── Attribute groups + values ─────────────────────────────────────────────

    public function insertAttributeGroup(string $name): int
    {
        $id = $this->db->execute(
            "INSERT INTO `{$this->p}attribute_group` (is_color_group, group_type, position)
             VALUES (0, 'select', 0)"
        );
        $this->db->execute(
            "INSERT INTO `{$this->p}attribute_group_lang`
                (id_attribute_group, id_lang, name, public_name)
             VALUES (?, ?, ?, ?)",
            [$id, $this->idLang, FieldMapper::truncate($name, 128), FieldMapper::truncate($name, 128)]
        );
        if ($this->db->tableExists($this->p . 'attribute_group_shop')) {
            $this->db->execute(
                "INSERT IGNORE INTO `{$this->p}attribute_group_shop` (id_attribute_group, id_shop)
                 VALUES (?, ?)",
                [$id, $this->idShop]
            );
        }
        return $id;
    }

    public function insertAttribute(string $name, int $groupId): int
    {
        $id = $this->db->execute(
            "INSERT INTO `{$this->p}attribute` (id_attribute_group, color, position)
             VALUES (?, '', 0)",
            [$groupId]
        );
        $this->db->execute(
            "INSERT INTO `{$this->p}attribute_lang` (id_attribute, id_lang, name)
             VALUES (?, ?, ?)",
            [$id, $this->idLang, FieldMapper::truncate($name, 128)]
        );
        if ($this->db->tableExists($this->p . 'attribute_shop')) {
            $this->db->execute(
                "INSERT IGNORE INTO `{$this->p}attribute_shop` (id_attribute, id_shop) VALUES (?, ?)",
                [$id, $this->idShop]
            );
        }
        return $id;
    }

    // ── Products ──────────────────────────────────────────────────────────────

    /**
     * Insert a product core row + lang row + shop row + stock + specific_price.
     * Caller MUST open a transaction before calling and commit/rollback after.
     * Returns new id_product.
     */
    public function insertProduct(array $wc, int $defaultCatId): int
    {
        $meta      = $wc['meta'] ?? [];
        // Fallback chain: _regular_price → _sale_price → _price
        $price     = max(0.0, FieldMapper::toFloat($meta['_regular_price'] ?? 0));
        if ($price === 0.0) $price = max(0.0, FieldMapper::toFloat($meta['_sale_price'] ?? 0));
        if ($price === 0.0) $price = max(0.0, FieldMapper::toFloat($meta['_price']      ?? 0));
        $salePrice = max(0.0, FieldMapper::toFloat($meta['_sale_price']    ?? 0));
        $weight    = max(0.0, FieldMapper::toFloat($meta['_weight']        ?? 0));
        $width     = max(0.0, FieldMapper::toFloat($meta['_width']         ?? 0));
        $height    = max(0.0, FieldMapper::toFloat($meta['_height']        ?? 0));
        $depth     = max(0.0, FieldMapper::toFloat($meta['_length']        ?? 0));
        $ref       = FieldMapper::truncate((string)($meta['_sku'] ?? ''), 64);
        $active    = ($wc['status'] ?? '') === 'publish' ? 1 : 0;
        $dateAdd   = $wc['date_add'] ?? date('Y-m-d H:i:s');
        $dateUpd   = $wc['date_upd'] ?? date('Y-m-d H:i:s');

        $id = $this->dynamicInsert($this->p . 'product', [
            'id_supplier'              => 0,
            'id_manufacturer'          => 0,
            'id_category_default'      => $defaultCatId,
            'id_shop_default'          => $this->idShop,
            'id_tax_rules_group'       => 1,
            'on_sale'                  => 0,
            'online_only'              => 0,
            'ean13'                    => '',
            'isbn'                     => '',
            'upc'                      => '',
            'mpn'                      => '',
            'ecotax'                   => 0,
            'quantity'                 => 0,
            'minimal_quantity'         => 1,
            'low_stock_threshold'      => 0,
            'low_stock_alert'          => 0,
            'price'                    => $price,
            'wholesale_price'          => 0,
            'unity'                    => '',
            'unit_price_ratio'         => 0,
            'additional_shipping_cost' => 0,
            'reference'                => $ref,
            'supplier_reference'       => '',
            'location'                 => '',
            'width'                    => $width,
            'height'                   => $height,
            'depth'                    => $depth,
            'weight'                   => $weight,
            'out_of_stock'             => 2,
            'quantity_discount'        => 0,
            'customizable'             => 0,
            'uploadable_files'         => 0,
            'text_fields'              => 0,
            'active'                   => $active,
            'redirect_type'            => '404',
            'id_product_redirected'    => 0,
            'available_for_order'      => 1,
            'available_date'           => '0000-00-00',
            'show_condition'           => 0,
            'condition'                => 'new',
            'show_price'               => 1,
            'indexed'                  => 1,
            'visibility'               => 'both',
            'cache_is_pack'            => 0,
            'cache_has_attachments'    => 0,
            'is_virtual'               => 0,
            'cache_default_attribute'  => 0,
            'date_add'                 => $dateAdd,
            'date_upd'                 => $dateUpd,
            'pack_stock_type'          => 3,
            'state'                    => 1,
            'additional_delivery_times'=> 1,
        ]);

        if ($id === 0) throw new \RuntimeException("insertProduct: INSERT returned 0 for '{$wc['title']}'");

        // product_lang
        $title       = $wc['title'] ?? '';
        $linkRewrite = FieldMapper::slugify($title);
        $this->dynamicInsert($this->p . 'product_lang', [
            'id_product'        => $id,
            'id_shop'           => $this->idShop,
            'id_lang'           => $this->idLang,
            'description'       => $wc['description'] ?? '',
            'description_short' => FieldMapper::truncate($wc['short_desc'] ?? '', 800),
            'link_rewrite'      => FieldMapper::truncate($linkRewrite, 128),
            'meta_description'  => '',
            'meta_keywords'     => '',
            'meta_title'        => FieldMapper::truncate($title, 128),
            'name'              => FieldMapper::truncate($title, 128),
            'available_now'     => '',
            'available_later'   => '',
            'delivery_in_stock' => '',
            'delivery_out_stock'=> '',
        ]);

        // product_shop
        if ($this->db->tableExists($this->p . 'product_shop')) {
            $this->dynamicInsert($this->p . 'product_shop', [
                'id_product'               => $id,
                'id_shop'                  => $this->idShop,
                'id_category_default'      => $defaultCatId,
                'id_tax_rules_group'       => 1,
                'on_sale'                  => 0,
                'online_only'              => 0,
                'ecotax'                   => 0,
                'minimal_quantity'         => 1,
                'low_stock_threshold'      => 0,
                'low_stock_alert'          => 0,
                'price'                    => $price,
                'wholesale_price'          => 0,
                'unity'                    => '',
                'unit_price_ratio'         => 0,
                'additional_shipping_cost' => 0,
                'customizable'             => 0,
                'uploadable_files'         => 0,
                'text_fields'              => 0,
                'active'                   => $active,
                'redirect_type'            => '404',
                'id_product_redirected'    => 0,
                'available_for_order'      => 1,
                'available_date'           => '0000-00-00',
                'show_condition'           => 0,
                'condition'                => 'new',
                'show_price'               => 1,
                'indexed'                  => 1,
                'visibility'               => 'both',
                'cache_default_attribute'  => 0,
                'advanced_stock_management'=> 0,
                'date_add'                 => $dateAdd,
                'date_upd'                 => $dateUpd,
                'pack_stock_type'          => 3,
                'additional_delivery_times'=> 1,
            ], true);
        }

        // category_product
        $this->dynamicInsert($this->p . 'category_product', [
            'id_category' => $defaultCatId,
            'id_product'  => $id,
            'position'    => 0,
        ], true);

        // stock_available
        $qty = max(0, (int)($meta['_stock'] ?? $meta['_stock_quantity'] ?? 0));
        $this->insertStockAvailable($id, 0, $qty);

        // specific_price for sale
        if ($salePrice > 0 && $salePrice < $price) {
            $this->insertSpecificPrice($id, $salePrice);
        }

        return $id;
    }

    public function assignProductCategories(int $psId, array $psCatIds): void
    {
        foreach ($psCatIds as $catId) {
            $catId = (int)$catId;
            if ($catId < 1) continue;
            $this->db->execute(
                "INSERT IGNORE INTO `{$this->p}category_product` (id_category, id_product, position)
                 VALUES (?, ?, 0)",
                [$catId, $psId]
            );
        }
    }

    // ── Stock ────────────────────────────────────────────────────────────────

    private function insertStockAvailable(int $productId, int $attrId, int $qty): void
    {
        $this->dynamicUpsert($this->p . 'stock_available', [
            'id_product'           => $productId,
            'id_product_attribute' => $attrId,
            'id_shop'              => $this->idShop,
            'id_shop_group'        => 0,
            'quantity'             => $qty,
            'depends_on_stock'     => 0,
            'out_of_stock'         => 2,
            'location'             => '',
            'physical_quantity'    => $qty,
            'reserved_quantity'    => 0,
        ], ['quantity', 'physical_quantity']);
    }

    // ── Specific price ────────────────────────────────────────────────────────

    private function insertSpecificPrice(int $productId, float $salePrice): void
    {
        if (!$this->db->tableExists($this->p . 'specific_price')) return;
        $this->dynamicInsert($this->p . 'specific_price', [
            'id_specific_price_rule' => 0,
            'id_cart'                => 0,
            'id_product'             => $productId,
            'id_shop'                => $this->idShop,
            'id_shop_group'          => 0,
            'id_currency'            => 0,
            'id_country'             => 0,
            'id_group'               => 0,
            'id_customer'            => 0,
            'id_product_attribute'   => 0,
            'price'                  => $salePrice,
            'from_quantity'          => 1,
            'reduction'              => 0,
            'reduction_tax'          => 1,
            'reduction_type'         => 'amount',
            'from'                   => '0000-00-00 00:00:00',
            'to'                     => '0000-00-00 00:00:00',
        ]);
    }

    // ── Combinations (variations) ─────────────────────────────────────────────

    /**
     * Insert a product combination.
     * Caller owns the transaction.
     * Returns new id_product_attribute.
     */
    public function insertCombination(
        int   $psProductId,
        array $variation,
        array $attrMap,
        float $parentPrice = 0.0,
        bool  $isDefault   = false
    ): int {
        $meta         = $variation['meta'] ?? [];
        $absPrice     = max(0.0, FieldMapper::toFloat($meta['_regular_price'] ?? 0));
        $priceImpact  = round($absPrice - $parentPrice, 6);
        $ref          = FieldMapper::truncate((string)($meta['_sku'] ?? ''), 64);
        $weight       = max(0.0, FieldMapper::toFloat($meta['_weight'] ?? 0));
        $defaultOn    = $isDefault ? 1 : null;

        $id = $this->dynamicInsert($this->p . 'product_attribute', [
            'id_product'          => $psProductId,
            'reference'           => $ref,
            'supplier_reference'  => '',
            'location'            => '',
            'ean13'               => '',
            'isbn'                => '',
            'upc'                 => '',
            'mpn'                 => '',
            'wholesale_price'     => 0,
            'price'               => $priceImpact,
            'ecotax'              => 0,
            'quantity'            => 0,
            'weight'              => $weight,
            'unit_price_impact'   => 0,
            'minimal_quantity'    => 1,
            'low_stock_threshold' => 0,
            'low_stock_alert'     => 0,
            'default_on'          => $defaultOn,
            'available_date'      => '0000-00-00',
        ]);

        // Link attribute values to combination
        if (!empty($variation['attrs'])) {
            foreach ($variation['attrs'] as $attrSlug => $attrValue) {
                if ($attrValue === '') continue; // "Any" — skip

                $lookupKey = $attrSlug . ':' . $attrValue; // already normalised
                if (isset($attrMap[$lookupKey])) {
                    $this->dynamicInsert($this->p . 'product_attribute_combination', [
                        'id_product_attribute' => $id,
                        'id_attribute'         => (int)$attrMap[$lookupKey],
                    ], true);
                } else {
                    $this->log->warning(
                        "No PS attribute for key '{$lookupKey}' on variation {$variation['id']} — skipped."
                    );
                }
            }
        }

        // Stock for this combination
        $qty = max(0, (int)($meta['_stock'] ?? $meta['_stock_quantity'] ?? 0));
        $this->insertStockAvailable($psProductId, $id, $qty);

        // product_attribute_shop
        if ($this->db->tableExists($this->p . 'product_attribute_shop')) {
            $this->dynamicInsert($this->p . 'product_attribute_shop', [
                'id_product_attribute' => $id,
                'id_product'           => $psProductId,
                'id_shop'              => $this->idShop,
                'wholesale_price'      => 0,
                'price'                => $priceImpact,
                'ecotax'               => 0,
                'weight'               => $weight,
                'unit_price_impact'    => 0,
                'default_on'           => $defaultOn,
                'minimal_quantity'     => 1,
                'low_stock_threshold'  => 0,
                'low_stock_alert'      => 0,
                'available_date'       => '0000-00-00',
            ], true);
        }

        return $id;
    }

    public function updateDefaultAttribute(int $psProductId, int $defaultAttrId): void
    {
        $this->db->execute(
            "UPDATE `{$this->p}product` SET cache_default_attribute = ? WHERE id_product = ?",
            [$defaultAttrId, $psProductId]
        );
        if ($this->db->tableExists($this->p . 'product_shop')) {
            $this->db->execute(
                "UPDATE `{$this->p}product_shop` SET cache_default_attribute = ?
                 WHERE id_product = ? AND id_shop = ?",
                [$defaultAttrId, $psProductId, $this->idShop]
            );
        }
    }

    // ── Images ────────────────────────────────────────────────────────────────

    /**
     * Register an image in the PS database (no file copy/download — done in batch step).
     * Stores the original WC URL in a temporary column if the table supports it.
     */
    public function insertImage(
        int    $psProductId,
        string $imageUrl,
        bool   $isCover     = false,
        string $psRootPath  = ''  // kept for BC — ignored here, used in batch step
    ): int {
        $position = $this->getNextImagePosition($psProductId);
        $coverVal = $isCover ? 1 : null;

        if ($isCover) {
            $this->db->execute(
                "UPDATE `{$this->p}image` SET cover = NULL WHERE id_product = ? AND cover = 1",
                [$psProductId]
            );
            if ($this->db->tableExists($this->p . 'image_shop')) {
                $this->db->execute(
                    "UPDATE `{$this->p}image_shop` SET cover = NULL
                     WHERE id_image IN (SELECT id_image FROM `{$this->p}image` WHERE id_product = ?)
                       AND id_shop = ? AND cover = 1",
                    [$psProductId, $this->idShop]
                );
            }
        }

        // Store source URL in legend field temporarily (used by image batch step)
        $id = $this->dynamicInsert($this->p . 'image', [
            'id_product' => $psProductId,
            'position'   => $position,
            'cover'      => $coverVal,
        ]);

        if ($id === 0) return 0;

        if ($this->db->tableExists($this->p . 'image_lang')) {
            $this->dynamicInsert($this->p . 'image_lang', [
                'id_image' => $id,
                'id_lang'  => $this->idLang,
                'legend'   => $imageUrl,  // ← store URL here for batch step
            ], true);
        }
        if ($this->db->tableExists($this->p . 'image_shop')) {
            $this->dynamicInsert($this->p . 'image_shop', [
                'id_image' => $id, 'id_shop' => $this->idShop, 'cover' => $coverVal,
            ], true);
        }

        return $id;
    }

    /**
     * Count how many images still need processing (legend contains URL).
     */
    public function countPendingImages(): int
    {
        $row = $this->db->queryOne(
            "SELECT COUNT(*) AS n FROM `{$this->p}image_lang`
              WHERE id_lang = ? AND legend LIKE 'http%'",
            [$this->idLang]
        );
        return (int)($row['n'] ?? 0);
    }

    /**
     * Fetch a batch of PS images that still need their file copied/downloaded.
     * Returns rows: [id_image, source_url, id_product]
     */
    public function getImagesBatch(int $afterId, int $limit): array
    {
        return $this->db->query(
            "SELECT il.id_image, il.legend AS source_url, i.id_product
              FROM `{$this->p}image_lang` il
              JOIN `{$this->p}image` i ON i.id_image = il.id_image
             WHERE il.id_lang = ?
               AND il.legend LIKE 'http%'
               AND il.id_image > ?
             ORDER BY il.id_image ASC
             LIMIT ?",
            [$this->idLang, $afterId, $limit]
        );
    }

    /**
     * Strategy A: Copy image file directly from WC local path to PS.
     * Fast — no HTTP, no timeout. Requires both on the same server.
     *
     * @param int    $imageId    PS image id
     * @param string $sourceUrl  Original WC URL (to derive filename)
     * @param string $wcUploads  WC uploads dir: /var/www/wc/wp-content/uploads
     * @param string $psRoot     PS root: /var/www/prestashop
     */
    public function copyImageLocal(int $imageId, string $sourceUrl, string $wcUploads, string $psRoot): bool
    {
        try {
            $realWc = realpath($wcUploads);
            $realPs = realpath($psRoot);
            if (!$realWc || !$realPs) {
                $this->log->warning("copyImageLocal: invalid paths wc={$wcUploads} ps={$psRoot}");
                return false;
            }

            // Derive local path from URL: .../uploads/2024/03/img.jpg → uploads/2024/03/img.jpg
            $urlPath = parse_url($sourceUrl, PHP_URL_PATH) ?? '';
            // Find "uploads/" in path and take from there
            $uploadsPos = strpos($urlPath, '/uploads/');
            if ($uploadsPos === false) {
                $this->log->warning("copyImageLocal: cannot find /uploads/ in URL: {$sourceUrl}");
                return false;
            }
            $relPath   = substr($urlPath, $uploadsPos + 1); // uploads/2024/03/img.jpg
            $localFile = $realWc . '/' . ltrim($relPath, '/');

            // Security: local file must be inside wcUploads
            $realFile = realpath($localFile);
            if (!$realFile || strpos($realFile, $realWc) !== 0) {
                $this->log->warning("copyImageLocal: file not found or outside uploads: {$localFile}");
                return false;
            }

            return $this->writeImageFile($imageId, $realFile, $realPs, 'copy');
        } catch (\Throwable $e) {
            $this->log->warning("copyImageLocal #{$imageId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Strategy B: Download image via HTTP into PS.
     * Works across servers. Slower — one HTTP request per image.
     */
    public function downloadImageBatch(int $imageId, string $url, string $psRoot): bool
    {
        if (!$this->isValidImageUrl($url)) {
            $this->log->warning("downloadImageBatch: invalid URL {$url}");
            return false;
        }
        try {
            $realPs = realpath($psRoot);
            if (!$realPs) { $this->log->warning("PS root not found: {$psRoot}"); return false; }

            $ctx  = stream_context_create(['http' => [
                'timeout' => self::IMG_TIMEOUT_SEC,
                'max_redirects' => 3, 'follow_location' => 1, 'ignore_errors' => true,
            ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);

            $data = @file_get_contents($url, false, $ctx);
            if ($data === false || $data === '') {
                $this->log->warning("Failed download: {$url}"); return false;
            }
            if (strlen($data) > self::MAX_IMG_BYTES) {
                $this->log->warning("Image too large: {$url}"); return false;
            }
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($data);
            if (!in_array($mime, ['image/jpeg','image/png','image/gif','image/webp'], true)) {
                $this->log->warning("Not an image (MIME {$mime}): {$url}"); return false;
            }

            // Write to temp file then use writeImageFile
            $tmp = tempnam(sys_get_temp_dir(), 'wc2ps_img_');
            file_put_contents($tmp, $data);
            $result = $this->writeImageFile($imageId, $tmp, $realPs, 'download');
            @unlink($tmp);
            return $result;
        } catch (\Throwable $e) {
            $this->log->warning("downloadImageBatch #{$imageId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Shared: write image file to PS directory structure and clear URL from legend.
     */
    private function writeImageFile(int $imageId, string $sourcePath, string $psRoot, string $mode): bool
    {
        $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_IMG_EXT, true)) $ext = 'jpg';

        $imgDir = $psRoot . '/img/p/' . $this->buildImagePath($imageId);
        if (!is_dir($imgDir) && !mkdir($imgDir, 0755, true) && !is_dir($imgDir)) {
            $this->log->warning("Cannot create dir: {$imgDir}"); return false;
        }
        $dest = $imgDir . '/' . $imageId . '.' . $ext;

        $ok = ($mode === 'copy') ? copy($sourcePath, $dest) : rename($sourcePath, $dest);
        if (!$ok) { $this->log->warning("File write failed: {$dest}"); return false; }

        // Clear the legend (URL) now that file is on disk
        $this->db->execute(
            "UPDATE `{$this->p}image_lang` SET legend = '' WHERE id_image = ? AND id_lang = ?",
            [$imageId, $this->idLang]
        );

        $this->log->debug("Image [{$mode}] #{$imageId} → {$dest}");
        return true;
    }

    private function getNextImagePosition(int $productId): int
    {
        $row = $this->db->queryOne(
            "SELECT COALESCE(MAX(position),0)+1 AS pos FROM `{$this->p}image` WHERE id_product=?",
            [$productId]
        );
        return (int)($row['pos'] ?? 1);
    }

    private function isValidImageUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http','https'], true)) return false;
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) return false;
        $ip = gethostbyname($host);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $this->log->warning("Blocked image from private IP: {$host} ({$ip})");
                return false;
            }
        }
        return true;
    }

    private function downloadImage(int $imageId, string $url, string $psRoot): void
    {
        try {
            $realRoot = realpath($psRoot);
            if (!$realRoot) { $this->log->warning("PS root not found: {$psRoot}"); return; }

            $imgDir = $realRoot . '/img/p/' . $this->buildImagePath($imageId);
            if (!is_dir($imgDir)) mkdir($imgDir, 0755, true);

            $realImgDir = realpath($imgDir);
            if (!$realImgDir || strpos($realImgDir, $realRoot) !== 0) {
                $this->log->warning("Image dir escaped PS root: {$imgDir}"); return;
            }

            $urlPath = parse_url($url, PHP_URL_PATH);
            $ext     = strtolower(pathinfo($urlPath ?? '', PATHINFO_EXTENSION));
            if (!in_array($ext, self::ALLOWED_IMG_EXT, true)) $ext = 'jpg';

            $ctx  = stream_context_create(['http' => [
                'timeout' => self::IMG_TIMEOUT_SEC,
                'max_redirects' => 3, 'follow_location' => 1, 'ignore_errors' => true,
            ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);

            $data = @file_get_contents($url, false, $ctx);
            if ($data === false) { $this->log->warning("Failed download: {$url}"); return; }
            if (strlen($data) > self::MAX_IMG_BYTES) { $this->log->warning("Image too large: {$url}"); return; }

            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($data);
            if (!in_array($mime, ['image/jpeg','image/png','image/gif','image/webp'], true)) {
                $this->log->warning("Not an image (MIME {$mime}): {$url}"); return;
            }

            file_put_contents($imgDir . '/' . $imageId . '.' . $ext, $data);
            $this->log->debug("Image downloaded: {$url} → {$imgDir}/{$imageId}.{$ext}");
        } catch (\Throwable $e) {
            $this->log->warning("Image download failed: {$url} — " . $e->getMessage());
        }
    }

    private function buildImagePath(int $id): string
    {
        return implode('/', str_split((string)abs($id)));
    }

    // ── Image import helpers ─────────────────────────────────────────────────

    /** Count distinct products that have at least one image registered. */
    public function countProductsWithImages(): int
    {
        $row = $this->db->query(
            "SELECT COUNT(DISTINCT id_product) AS n FROM `{$this->p}image`"
        );
        return (int)($row[0]['n'] ?? 0);
    }

    /**
     * Get next batch of products+images after cursor (ps_product id).
     * Returns rows: id_product, id_image, image_url (from wc_id if stored), cover.
     * We store the WC image URL in image_lang.legend during migration.
     */
    public function getProductsWithImages(int $afterProductId, int $limit): array
    {
        // Fetch images with their source URL stored in image_lang.legend
        $hasLang = $this->db->tableExists($this->p . 'image_lang');
        if ($hasLang) {
            return $this->db->query(
                "SELECT i.id_product, i.id_image, i.cover,
                        COALESCE(il.legend, '') AS image_url
                 FROM `{$this->p}image` i
                 LEFT JOIN `{$this->p}image_lang` il
                   ON i.id_image = il.id_image AND il.id_lang = ?
                 WHERE i.id_product > ?
                 ORDER BY i.id_product ASC, i.position ASC
                 LIMIT ?",
                [$this->idLang, $afterProductId, $limit]
            );
        }
        return $this->db->query(
            "SELECT id_product, id_image, cover, '' AS image_url
             FROM `{$this->p}image`
             WHERE id_product > ?
             ORDER BY id_product ASC, position ASC
             LIMIT ?",
            [$afterProductId, $limit]
        );
    }

    /**
     * Resolve the physical file path for a PS image.
     * Returns null if psRootPath is invalid.
     */
    public function getImageFilePath(string $psRootPath, int $imageId): ?string
    {
        $realRoot = realpath($psRootPath);
        if (!$realRoot) return null;
        $sub = implode('/', str_split((string)abs($imageId)));
        return $realRoot . '/img/p/' . $sub . '/' . $imageId . '.jpg';
    }

    // ── Cleanup ───────────────────────────────────────────────────────────────

    public function deleteProduct(int $psId): void
    {
        // Combinations
        $combIds = array_column(
            $this->db->query("SELECT id_product_attribute FROM `{$this->p}product_attribute` WHERE id_product=?", [$psId]),
            'id_product_attribute'
        );
        foreach ($combIds as $paId) {
            $paId = (int)$paId;
            $this->db->execute("DELETE FROM `{$this->p}product_attribute_combination` WHERE id_product_attribute=?", [$paId]);
            if ($this->db->tableExists($this->p.'product_attribute_shop'))
                $this->db->execute("DELETE FROM `{$this->p}product_attribute_shop` WHERE id_product_attribute=?", [$paId]);
        }
        $this->db->execute("DELETE FROM `{$this->p}product_attribute` WHERE id_product=?", [$psId]);

        // Images
        $imgIds = array_column(
            $this->db->query("SELECT id_image FROM `{$this->p}image` WHERE id_product=?", [$psId]),
            'id_image'
        );
        foreach ($imgIds as $imgId) {
            $imgId = (int)$imgId;
            if ($this->db->tableExists($this->p.'image_lang'))
                $this->db->execute("DELETE FROM `{$this->p}image_lang` WHERE id_image=?", [$imgId]);
            if ($this->db->tableExists($this->p.'image_shop'))
                $this->db->execute("DELETE FROM `{$this->p}image_shop` WHERE id_image=?", [$imgId]);
        }
        $this->db->execute("DELETE FROM `{$this->p}image` WHERE id_product=?", [$psId]);

        // Stock, specific prices, category links
        $this->db->execute("DELETE FROM `{$this->p}stock_available` WHERE id_product=?", [$psId]);
        if ($this->db->tableExists($this->p.'specific_price'))
            $this->db->execute("DELETE FROM `{$this->p}specific_price` WHERE id_product=?", [$psId]);
        $this->db->execute("DELETE FROM `{$this->p}category_product` WHERE id_product=?", [$psId]);
        $this->db->execute("DELETE FROM `{$this->p}product_lang` WHERE id_product=?", [$psId]);
        if ($this->db->tableExists($this->p.'product_shop'))
            $this->db->execute("DELETE FROM `{$this->p}product_shop` WHERE id_product=?", [$psId]);

        // Search index
        if ($this->db->tableExists($this->p.'search_index'))
            $this->db->execute("DELETE FROM `{$this->p}search_index` WHERE id_product=?", [$psId]);

        $this->db->execute("DELETE FROM `{$this->p}product` WHERE id_product=?", [$psId]);
    }

    public function deleteCategory(int $psCatId): void
    {
        $root = $this->getRootCategoryId();
        $home = $this->getHomeCategoryId();
        if ($psCatId <= 0 || $psCatId === $root || $psCatId === $home) return;

        $this->db->execute("DELETE FROM `{$this->p}category_lang` WHERE id_category=?", [$psCatId]);
        if ($this->db->tableExists($this->p.'category_shop'))
            $this->db->execute("DELETE FROM `{$this->p}category_shop` WHERE id_category=?", [$psCatId]);
        $this->db->execute("DELETE FROM `{$this->p}category` WHERE id_category=?", [$psCatId]);
    }

    public function deleteAttributeGroup(int $psGroupId): void
    {
        $attrIds = array_column(
            $this->db->query("SELECT id_attribute FROM `{$this->p}attribute` WHERE id_attribute_group=?", [$psGroupId]),
            'id_attribute'
        );
        foreach ($attrIds as $attrId) {
            $attrId = (int)$attrId;
            $this->db->execute("DELETE FROM `{$this->p}attribute_lang` WHERE id_attribute=?", [$attrId]);
            if ($this->db->tableExists($this->p.'attribute_shop'))
                $this->db->execute("DELETE FROM `{$this->p}attribute_shop` WHERE id_attribute=?", [$attrId]);
        }
        $this->db->execute("DELETE FROM `{$this->p}attribute` WHERE id_attribute_group=?", [$psGroupId]);
        $this->db->execute("DELETE FROM `{$this->p}attribute_group_lang` WHERE id_attribute_group=?", [$psGroupId]);
        if ($this->db->tableExists($this->p.'attribute_group_shop'))
            $this->db->execute("DELETE FROM `{$this->p}attribute_group_shop` WHERE id_attribute_group=?", [$psGroupId]);
        $this->db->execute("DELETE FROM `{$this->p}attribute_group` WHERE id_attribute_group=?", [$psGroupId]);
    }
}
