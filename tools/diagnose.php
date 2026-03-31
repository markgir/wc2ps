#!/usr/bin/env php
<?php
/**
 * diagnose.php — Pre-migration diagnostic for WooCommerce + PrestaShop.
 *
 * Checks both databases and reports everything the migration needs.
 * Run this first if you're having trouble.
 *
 * Usage:
 *   php tools/diagnose.php
 *
 * Or set env vars and pipe to a file:
 *   WC_DB_NAME=myshop PS_DB_NAME=prestashop php tools/diagnose.php > diag.txt
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/DebugLogger.php';
require_once __DIR__ . '/../src/FieldMapper.php';
require_once __DIR__ . '/../src/WooCommerce/WCAnalyser.php';
require_once __DIR__ . '/../src/PrestaShop/PSAnalyser.php';

// ── Config ────────────────────────────────────────────────────────────────────

function envOr(string $key, string $default): string
{
    $v = getenv($key);
    return ($v !== false && $v !== '') ? $v : $default;
}

function ask(string $prompt, string $default = ''): string
{
    if (PHP_SAPI !== 'cli') return $default;
    $disp = $default ? " [{$default}]" : '';
    fwrite(STDOUT, $prompt . $disp . ': ');
    $v = trim(fgets(STDIN));
    return $v !== '' ? $v : $default;
}

$auto = (getenv('WC_DB_NAME') !== false);

$wcCfg = [
    'host'   => envOr('WC_DB_HOST',   $auto ? '127.0.0.1' : ask('WC host',   '127.0.0.1')),
    'port'   => envOr('WC_DB_PORT',   $auto ? '3306'       : ask('WC port',   '3306')),
    'db'     => envOr('WC_DB_NAME',   $auto ? ''           : ask('WC db name')),
    'user'   => envOr('WC_DB_USER',   $auto ? ''           : ask('WC user')),
    'pass'   => envOr('WC_DB_PASS',   $auto ? ''           : ask('WC password')),
    'prefix' => envOr('WC_DB_PREFIX', $auto ? 'wp_'        : ask('WC prefix', 'wp_')),
];

$psCfg = [
    'host'   => envOr('PS_DB_HOST',   $auto ? '127.0.0.1' : ask('PS host',   '127.0.0.1')),
    'port'   => envOr('PS_DB_PORT',   $auto ? '3306'       : ask('PS port',   '3306')),
    'db'     => envOr('PS_DB_NAME',   $auto ? ''           : ask('PS db name')),
    'user'   => envOr('PS_DB_USER',   $auto ? ''           : ask('PS user')),
    'pass'   => envOr('PS_DB_PASS',   $auto ? ''           : ask('PS password')),
    'prefix' => envOr('PS_DB_PREFIX', $auto ? 'ps_'        : ask('PS prefix', 'ps_')),
];

// ── Helpers ───────────────────────────────────────────────────────────────────

function sec(string $title): void
{
    echo "\n" . str_repeat('─', 52) . "\n";
    echo "  {$title}\n";
    echo str_repeat('─', 52) . "\n";
}

function ok(string $msg): void  { echo "  ✓  {$msg}\n"; }
function err(string $msg): void { echo "  ✗  {$msg}\n"; }
function warn(string $msg): void{ echo "  ⚠  {$msg}\n"; }
function inf(string $msg): void { echo "     {$msg}\n"; }

// ── Header ────────────────────────────────────────────────────────────────────

echo "\n";
echo "══════════════════════════════════════════════════════\n";
echo "  WC → PS Migration — Pre-flight Diagnostic\n";
echo "  " . date('Y-m-d H:i:s') . "\n";
echo "══════════════════════════════════════════════════════\n";

// ── PHP requirements ──────────────────────────────────────────────────────────

sec('PHP Environment');
inf('PHP version: ' . PHP_VERSION);

$required_ext = ['pdo', 'pdo_mysql', 'mbstring', 'json', 'filter'];
foreach ($required_ext as $ext) {
    extension_loaded($ext) ? ok("ext/{$ext}") : err("ext/{$ext} MISSING — required");
}

$optional_ext = ['fileinfo', 'iconv', 'openssl'];
foreach ($optional_ext as $ext) {
    extension_loaded($ext)
        ? ok("ext/{$ext} (optional)")
        : warn("ext/{$ext} not loaded (optional — needed for images/SSRF check)");
}

inf('max_execution_time: ' . ini_get('max_execution_time') . 's');
inf('memory_limit: ' . ini_get('memory_limit'));

// ── WooCommerce DB ────────────────────────────────────────────────────────────

sec('WooCommerce Database');

try {
    $wcDb = new Database(
        $wcCfg['host'], $wcCfg['port'], $wcCfg['db'],
        $wcCfg['user'], $wcCfg['pass'], $wcCfg['prefix']
    );
    ok("Connected to {$wcCfg['db']}@{$wcCfg['host']}:{$wcCfg['port']}");

    $fakeLog = new class {
        public function setStep(string $s): void {}
        public function debug(string $m, array $c = []): void {}
        public function info(string $m, array $c = []): void {}
        public function success(string $m, array $c = []): void {}
        public function warning(string $m, array $c = []): void {}
        public function error(string $m, array $c = []): void {}
        public function write(string $l, string $m, array $c = []): void {}
        public function schema(string $t, array $c): void {}
    };

    $anal  = new WCAnalyser($wcDb, $fakeLog);
    $vers  = $anal->detectVersions();
    ok("WordPress db_version: {$vers['wordpress']}");
    ok("WooCommerce version: {$vers['woocommerce']}");
    inf("Site URL: {$vers['site_url']}");

    $counts = $anal->getCounts();
    ok("Products: {$counts['products']} ({$counts['simple_products']} simple, {$counts['variable_products']} variable)");
    ok("Variations: {$counts['variations']}");
    ok("Categories: {$counts['categories']}");
    ok("Attributes: {$counts['attributes']}");
    ok("Images (attachments): {$counts['images']}");
    ok("Products with cover image: {$counts['products_with_images']}");

    $tables = $anal->getRelevantTables();
    foreach ($tables as $t => $found) {
        $found ? ok("Table: {$wcCfg['prefix']}{$t}") : err("Table MISSING: {$wcCfg['prefix']}{$t}");
    }

    $issues = $anal->detectIssues();
    foreach ($issues as $i) {
        match ($i['level']) {
            'error'   => err($i['msg']),
            'warning' => warn($i['msg']),
            default   => inf($i['msg']),
        };
    }

    // Attribute key format check
    $meta = $wcDb->queryOne(
        "SELECT meta_key FROM `{$wcCfg['prefix']}postmeta`
         WHERE meta_key LIKE 'attribute_pa_%' LIMIT 1"
    );
    if ($meta) {
        ok("Variation attribute meta found (e.g. {$meta['meta_key']})");
    } else {
        warn("No variation attribute meta found — variable products may have no combinations.");
    }

} catch (\Throwable $e) {
    err("Connection failed: " . $e->getMessage());
}

// ── PrestaShop DB ─────────────────────────────────────────────────────────────

sec('PrestaShop Database');

try {
    $psDb = new Database(
        $psCfg['host'], $psCfg['port'], $psCfg['db'],
        $psCfg['user'], $psCfg['pass'], $psCfg['prefix']
    );
    ok("Connected to {$psCfg['db']}@{$psCfg['host']}:{$psCfg['port']}");

    $fakeLog2 = new class {
        public function setStep(string $s): void {}
        public function debug(string $m, array $c = []): void {}
        public function info(string $m, array $c = []): void {}
        public function success(string $m, array $c = []): void {}
        public function warning(string $m, array $c = []): void {}
        public function error(string $m, array $c = []): void {}
        public function write(string $l, string $m, array $c = []): void {}
        public function schema(string $t, array $c): void {}
    };

    $psAnal = new PSAnalyser($psDb, $fakeLog2);
    $psVer  = $psAnal->detectVersion();
    ok("PrestaShop version: {$psVer}");

    $langId = $psAnal->getDefaultLangId();
    $shopId = $psAnal->getDefaultShopId();
    $rootId = $psAnal->getRootCategoryId();
    $homeId = $psAnal->getHomeCategoryId();
    ok("Default language id: {$langId}");
    ok("Default shop id: {$shopId}");
    ok("Root category id: {$rootId}");
    ok("Home category id: {$homeId}");

    // Verify lang exists
    $lang = $psDb->queryOne(
        "SELECT id_lang, name FROM `{$psCfg['prefix']}lang` WHERE id_lang = ? LIMIT 1",
        [$langId]
    );
    $lang
        ? ok("Language {$langId} = '{$lang['name']}'")
        : err("Language id={$langId} NOT FOUND in ps_lang — migration will fail");

    $counts = $psAnal->getExistingCounts();
    foreach ($counts as $k => $v) {
        if ($v === null) {
            warn("Table ps_{$k} absent");
        } elseif ($v > 0) {
            warn("{$v} existing {$k} in PS — migration will ADD to these");
        } else {
            ok("ps_{$k}: empty (clean target)");
        }
    }

    $tables = $psAnal->getRelevantTables();
    $missing = array_keys(array_filter($tables, fn($v) => !$v));
    if (empty($missing)) {
        ok("All expected PS tables present");
    } else {
        foreach ($missing as $t) warn("PS table absent: {$psCfg['prefix']}{$t}");
    }

    $psIssues = $psAnal->detectIssues();
    foreach ($psIssues as $i) {
        match ($i['level']) {
            'error'   => err($i['msg']),
            'warning' => warn($i['msg']),
            default   => inf($i['msg']),
        };
    }

} catch (\Throwable $e) {
    err("Connection failed: " . $e->getMessage());
}

// ── File system ───────────────────────────────────────────────────────────────

sec('File System');

$dir = __DIR__ . '/../migration_progress';
if (is_dir($dir)) {
    is_writable($dir) ? ok("migration_progress/ is writable") : err("migration_progress/ is NOT writable");
} else {
    err("migration_progress/ directory not found");
}

// ── Summary ───────────────────────────────────────────────────────────────────

echo "\n══════════════════════════════════════════════════════\n";
echo "  Diagnostic complete\n";
echo "══════════════════════════════════════════════════════\n\n";
