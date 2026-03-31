#!/usr/bin/env php
<?php
/**
 * fix_numeric_fields.php — Post-migration repair for PrestaShop.
 *
 * Fixes empty string ('') values in numeric MySQL columns that cause the
 * "cannot be interpreted as a number" error in PrestaShop's backoffice.
 *
 * Can be run via:
 *   php tools/fix_numeric_fields.php
 *   php tools/fix_numeric_fields.php --dry-run   (show what would change)
 *
 * Or configure via environment variables:
 *   PS_DB_HOST, PS_DB_PORT, PS_DB_NAME, PS_DB_USER, PS_DB_PASS, PS_DB_PREFIX
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

$dryRun = in_array('--dry-run', $argv ?? [], true);

// ── Config ────────────────────────────────────────────────────────────────────

$cfg = [
    'host'   => getenv('PS_DB_HOST')   ?: '127.0.0.1',
    'port'   => getenv('PS_DB_PORT')   ?: '3306',
    'db'     => getenv('PS_DB_NAME')   ?: '',
    'user'   => getenv('PS_DB_USER')   ?: '',
    'pass'   => getenv('PS_DB_PASS')   ?: '',
    'prefix' => getenv('PS_DB_PREFIX') ?: 'ps_',
];

// If running interactively and no env vars set, ask
if (PHP_SAPI === 'cli' && $cfg['db'] === '') {
    fwrite(STDOUT, "PrestaShop DB host   [{$cfg['host']}]: ");
    $in = trim(fgets(STDIN));
    if ($in) $cfg['host'] = $in;

    fwrite(STDOUT, "PrestaShop DB name: ");
    $cfg['db'] = trim(fgets(STDIN));

    fwrite(STDOUT, "DB user: ");
    $cfg['user'] = trim(fgets(STDIN));

    fwrite(STDOUT, "DB password: ");
    $cfg['pass'] = trim(fgets(STDIN));

    fwrite(STDOUT, "Table prefix [{$cfg['prefix']}]: ");
    $in = trim(fgets(STDIN));
    if ($in) $cfg['prefix'] = $in;
}

// ── Connect ───────────────────────────────────────────────────────────────────

echo "\n=====================================================\n";
echo "  PrestaShop — Fix Empty Numeric Fields\n";
echo "  " . ($dryRun ? "[DRY RUN — no changes will be made]" : "[LIVE — will UPDATE rows]") . "\n";
echo "=====================================================\n\n";

try {
    $pdo = new PDO(
        "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['db']};charset=utf8mb4",
        $cfg['user'], $cfg['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    echo "[OK] Connected to {$cfg['db']}@{$cfg['host']}\n\n";
} catch (\PDOException $e) {
    echo "[FATAL] Cannot connect: " . $e->getMessage() . "\n";
    exit(1);
}

$p = $cfg['prefix'];

// ── Tables + columns to check ─────────────────────────────────────────────────
// Maps table → columns that must NOT be empty string (use 0 instead)
// NULL-semantic columns (default_on, cover) are intentionally excluded.
$targets = [
    'product' => [
        'id_supplier','id_manufacturer','id_category_default','id_shop_default',
        'id_tax_rules_group','on_sale','online_only','ecotax','quantity',
        'minimal_quantity','price','wholesale_price','unit_price_ratio',
        'additional_shipping_cost','width','height','depth','weight',
        'out_of_stock','quantity_discount','customizable','uploadable_files',
        'text_fields','active','available_for_order','show_condition',
        'show_price','indexed','cache_is_pack','cache_has_attachments',
        'is_virtual','cache_default_attribute','pack_stock_type','state',
        'additional_delivery_times','low_stock_threshold','low_stock_alert',
    ],
    'product_attribute' => [
        'id_product','wholesale_price','price','ecotax','quantity','weight',
        'unit_price_impact','minimal_quantity','low_stock_threshold','low_stock_alert',
    ],
    'product_attribute_shop' => [
        'id_product','id_shop','wholesale_price','price','ecotax','weight',
        'unit_price_impact','minimal_quantity','low_stock_threshold','low_stock_alert',
    ],
    'product_shop' => [
        'id_shop','id_category_default','id_tax_rules_group','on_sale','online_only',
        'ecotax','minimal_quantity','price','wholesale_price','unit_price_ratio',
        'additional_shipping_cost','customizable','uploadable_files','text_fields',
        'active','available_for_order','show_condition','show_price','indexed',
        'cache_default_attribute','advanced_stock_management','pack_stock_type',
        'additional_delivery_times','low_stock_threshold','low_stock_alert',
    ],
    'stock_available' => [
        'id_product','id_product_attribute','id_shop','id_shop_group',
        'quantity','depends_on_stock','out_of_stock',
        'physical_quantity','reserved_quantity',
    ],
    'specific_price' => [
        'id_specific_price_rule','id_cart','id_product','id_shop','id_shop_group',
        'id_currency','id_country','id_group','id_customer','id_product_attribute',
        'price','from_quantity','reduction','reduction_tax',
    ],
];

$totalFixed = 0;

foreach ($targets as $table => $cols) {
    $full = $p . $table;

    // Check table exists
    $check = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($full));
    if (!$check->fetch()) {
        echo "  [SKIP] {$full} — table not found\n";
        continue;
    }

    // Get actual column names to avoid referencing absent columns
    $existing = array_column($pdo->query("SHOW COLUMNS FROM `{$full}`")->fetchAll(), 'Field');
    $existSet = array_flip($existing);

    $tableFixed = 0;
    foreach ($cols as $col) {
        if (!isset($existSet[$col])) continue;

        // Count affected rows
        $stmt = $pdo->prepare("SELECT COUNT(*) AS n FROM `{$full}` WHERE `{$col}` = ''");
        $stmt->execute();
        $n = (int)($stmt->fetch()['n'] ?? 0);

        if ($n === 0) continue;

        echo "  {$full}.{$col}: {$n} empty → 0";
        if (!$dryRun) {
            $pdo->exec("UPDATE `{$full}` SET `{$col}` = 0 WHERE `{$col}` = ''");
            echo " [FIXED]";
        } else {
            echo " [would fix]";
        }
        echo "\n";
        $tableFixed += $n;
    }

    if ($tableFixed > 0) {
        echo "  → {$full}: {$tableFixed} cells total\n";
        $totalFixed += $tableFixed;
    }
}

echo "\n=====================================================\n";
if ($dryRun) {
    echo "  DRY RUN complete. {$totalFixed} cells would be fixed.\n";
    echo "  Run without --dry-run to apply changes.\n";
} else {
    echo "  Done. {$totalFixed} cells fixed.\n";
}
echo "=====================================================\n\n";
