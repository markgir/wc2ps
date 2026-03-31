#!/usr/bin/env php
<?php
/**
 * inspect_products.php — Inspects WooCommerce product structure
 * to understand how "BLUSÃO KENNY ADVENTURE - BLACK" type products are stored.
 * 
 * Usage: php tools/inspect_products.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';

function ask(string $prompt, string $default = ''): string {
    fwrite(STDOUT, $prompt . ($default ? " [{$default}]" : '') . ': ');
    $v = trim(fgets(STDIN));
    return $v !== '' ? $v : $default;
}

// Config
$host   = getenv('WC_DB_HOST')   ?: ask('WC host',   '127.0.0.1');
$port   = getenv('WC_DB_PORT')   ?: ask('WC port',   '3306');
$db     = getenv('WC_DB_NAME')   ?: ask('WC db name');
$user   = getenv('WC_DB_USER')   ?: ask('WC user');
$pass   = getenv('WC_DB_PASS')   ?: ask('WC password');
$prefix = getenv('WC_DB_PREFIX') ?: ask('WC prefix',  'wp_');

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
        $user, $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (\PDOException $e) {
    die("Cannot connect: " . $e->getMessage() . "\n");
}

$p = $prefix;

echo "\n=== Inspecting WC IDs: 94681, 94689, 94697, 94705, 94721, 94729, 94737, 94745 ===\n\n";

$ids = [94681, 94689, 94697, 94705, 94721, 94729, 94737, 94745];
$ph  = implode(',', $ids);

// Basic post info
$rows = $pdo->query(
    "SELECT ID, post_title, post_type, post_status, post_parent
     FROM `{$p}posts`
     WHERE ID IN ($ph)
     ORDER BY ID"
)->fetchAll();

echo "--- Post info ---\n";
foreach ($rows as $r) {
    printf("  ID=%-8d type=%-20s status=%-10s parent=%-8d title=%s\n",
        $r['ID'], $r['post_type'], $r['post_status'], $r['post_parent'], $r['post_title']);
}

echo "\n--- Meta for each product ---\n";
$keys = ['_product_type','_sku','_regular_price','_stock','_stock_quantity',
         '_stock_status','_weight','_manage_stock'];
foreach ($ids as $id) {
    $meta = $pdo->prepare(
        "SELECT meta_key, meta_value FROM `{$p}postmeta`
         WHERE post_id = ? AND meta_key IN ('" . implode("','", $keys) . "')"
    );
    $meta->execute([$id]);
    $m = [];
    foreach ($meta->fetchAll() as $r) $m[$r['meta_key']] = $r['meta_value'];
    
    $title = $pdo->query("SELECT post_title FROM `{$p}posts` WHERE ID=$id")->fetchColumn();
    echo "\n  ID=$id  $title\n";
    foreach ($keys as $k) {
        if (isset($m[$k])) printf("    %-25s = %s\n", $k, $m[$k]);
    }
}

echo "\n--- Product type taxonomy ---\n";
$rows = $pdo->query(
    "SELECT tr.object_id, t.slug AS type
     FROM `{$p}term_relationships` tr
     JOIN `{$p}term_taxonomy` tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
     JOIN `{$p}terms` t ON tt.term_id = t.term_id
     WHERE tr.object_id IN ($ph) AND tt.taxonomy = 'product_type'"
)->fetchAll();
foreach ($rows as $r) {
    echo "  ID={$r['object_id']} type={$r['type']}\n";
}

echo "\n--- Categories ---\n";
$rows = $pdo->query(
    "SELECT tr.object_id, t.name, t.slug
     FROM `{$p}term_relationships` tr
     JOIN `{$p}term_taxonomy` tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
     JOIN `{$p}terms` t ON tt.term_id = t.term_id
     WHERE tr.object_id IN ($ph) AND tt.taxonomy = 'product_cat'"
)->fetchAll();
foreach ($rows as $r) {
    echo "  ID={$r['object_id']} cat='{$r['name']}'\n";
}

echo "\n--- Attributes on these products ---\n";
$rows = $pdo->query(
    "SELECT post_id, meta_key, meta_value
     FROM `{$p}postmeta`
     WHERE post_id IN ($ph) AND meta_key = '_product_attributes'"
)->fetchAll();
foreach ($rows as $r) {
    $attrs = @unserialize($r['meta_value'], ['allowed_classes' => false]);
    if (is_array($attrs)) {
        echo "  ID={$r['post_id']}:\n";
        foreach ($attrs as $slug => $attr) {
            echo "    slug=$slug name={$attr['name']} value={$attr['value']} variation={$attr['is_variation']}\n";
        }
    }
}

echo "\n--- Searching for parent 'BLUSÃO KENNY ADVENTURE' ---\n";
$rows = $pdo->query(
    "SELECT ID, post_title, post_type, post_status, post_parent
     FROM `{$p}posts`
     WHERE post_title LIKE '%BLUSÃO KENNY%'
       AND post_type IN ('product','product_variation')
     ORDER BY post_title, ID
     LIMIT 30"
)->fetchAll();
foreach ($rows as $r) {
    printf("  ID=%-8d type=%-20s status=%-10s parent=%-8d title=%s\n",
        $r['ID'], $r['post_type'], $r['post_status'], $r['post_parent'], $r['post_title']);
}

echo "\n=== DONE ===\n";
