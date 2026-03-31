<?php
/**
 * debug.php — Diagnóstico de servidor para wc2ps
 * APAGAR após resolver o problema.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

header('Content-Type: text/plain; charset=utf-8');

echo "=== SERVER DIAGNOSTIC ===\n";
echo "PHP version:    " . PHP_VERSION . "\n";
echo "PHP SAPI:       " . PHP_SAPI . "\n";
echo "Server:         " . ($_SERVER['SERVER_SOFTWARE'] ?? '?') . "\n";
echo "Script:         " . __FILE__ . "\n";
echo "Date:           " . date('Y-m-d H:i:s') . "\n\n";

echo "=== PHP EXTENSIONS ===\n";
$needed = ['pdo', 'pdo_mysql', 'mbstring', 'json', 'filter', 'fileinfo', 'iconv', 'openssl'];
foreach ($needed as $ext) {
    echo ($ext) . ": " . (extension_loaded($ext) ? "OK" : "MISSING") . "\n";
}

echo "\n=== PHP INI ===\n";
$keys = ['display_errors','log_errors','error_log','max_execution_time',
         'memory_limit','upload_max_filesize','post_max_size','short_open_tag'];
foreach ($keys as $k) {
    echo "$k = " . ini_get($k) . "\n";
}

echo "\n=== FILE PERMISSIONS ===\n";
$check = [
    __DIR__,
    __DIR__ . '/api.php',
    __DIR__ . '/src',
    __DIR__ . '/src/Database.php',
    __DIR__ . '/src/DebugLogger.php',
    __DIR__ . '/src/FieldMapper.php',
    __DIR__ . '/src/Migrator.php',
    __DIR__ . '/src/WooCommerce/WCAnalyser.php',
    __DIR__ . '/src/PrestaShop/PSAnalyser.php',
    __DIR__ . '/src/PrestaShop/PSWriter.php',
    __DIR__ . '/migration_progress',
];
foreach ($check as $path) {
    $exists = file_exists($path);
    $readable = $exists && is_readable($path);
    $writable = $exists && is_writable($path);
    echo "$path\n";
    echo "  exists=" . ($exists?'yes':'NO') .
         " readable=" . ($readable?'yes':'NO') .
         " writable=" . ($writable?'yes':'NO') . "\n";
}

echo "\n=== REQUIRE TEST ===\n";
$files = [
    'src/Database.php',
    'src/DebugLogger.php',
    'src/FieldMapper.php',
    'src/WooCommerce/WCAnalyser.php',
    'src/PrestaShop/PSAnalyser.php',
    'src/PrestaShop/PSWriter.php',
    'src/Migrator.php',
];
foreach ($files as $f) {
    $path = __DIR__ . '/' . $f;
    if (!file_exists($path)) {
        echo "MISSING: $f\n";
        continue;
    }
    try {
        // Check PHP syntax by reading and tokenizing
        $tokens = @token_get_all(file_get_contents($path));
        echo "OK:      $f\n";
    } catch (\Throwable $e) {
        echo "ERROR:   $f — " . $e->getMessage() . "\n";
    }
}

echo "\n=== LOAD TEST ===\n";
try {
    require_once __DIR__ . '/src/Database.php';
    echo "Database.php loaded OK\n";
} catch (\Throwable $e) {
    echo "Database.php FAILED: " . $e->getMessage() . "\n";
}

try {
    require_once __DIR__ . '/src/DebugLogger.php';
    echo "DebugLogger.php loaded OK\n";
} catch (\Throwable $e) {
    echo "DebugLogger.php FAILED: " . $e->getMessage() . "\n";
}

try {
    require_once __DIR__ . '/src/FieldMapper.php';
    echo "FieldMapper.php loaded OK\n";
} catch (\Throwable $e) {
    echo "FieldMapper.php FAILED: " . $e->getMessage() . "\n";
}

try {
    require_once __DIR__ . '/src/WooCommerce/WCAnalyser.php';
    echo "WCAnalyser.php loaded OK\n";
} catch (\Throwable $e) {
    echo "WCAnalyser.php FAILED: " . $e->getMessage() . "\n";
}

try {
    require_once __DIR__ . '/src/PrestaShop/PSAnalyser.php';
    echo "PSAnalyser.php loaded OK\n";
} catch (\Throwable $e) {
    echo "PSAnalyser.php FAILED: " . $e->getMessage() . "\n";
}

try {
    require_once __DIR__ . '/src/PrestaShop/PSWriter.php';
    echo "PSWriter.php loaded OK\n";
} catch (\Throwable $e) {
    echo "PSWriter.php FAILED: " . $e->getMessage() . "\n";
}

try {
    require_once __DIR__ . '/src/Migrator.php';
    echo "Migrator.php loaded OK\n";
} catch (\Throwable $e) {
    echo "Migrator.php FAILED: " . $e->getMessage() . "\n";
}

echo "\n=== .HTACCESS CHECK ===\n";
$htaccess = __DIR__ . '/.htaccess';
if (file_exists($htaccess)) {
    echo file_get_contents($htaccess);
} else {
    echo ".htaccess not found\n";
}

echo "\n=== ERROR LOG (last 20 lines) ===\n";
$logFile = ini_get('error_log');
if ($logFile && file_exists($logFile) && is_readable($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $recent = array_slice($lines, -20);
    echo implode("\n", $recent) . "\n";
} else {
    echo "Error log not readable: $logFile\n";
    // Try common locations
    $candidates = [
        '/var/log/apache2/error.log',
        '/var/log/httpd/error_log',
        '/tmp/php_errors.log',
        sys_get_temp_dir() . '/php_errors.log',
    ];
    foreach ($candidates as $c) {
        if (file_exists($c) && is_readable($c)) {
            echo "Found at $c:\n";
            $lines = file($c, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            echo implode("\n", array_slice($lines, -10)) . "\n";
            break;
        }
    }
}

echo "\n=== DONE ===\n";
