<?php
declare(strict_types=1);

define('APP_VERSION', '2.5.4');

// Extend limits — reset can delete thousands of products
@set_time_limit(600);
@ini_set('max_execution_time', '600');
@ini_set('memory_limit', '256M');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store, no-cache, must-revalidate');

ob_start();

register_shutdown_function(static function (): void {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR], true)) {
        while (ob_get_level()) ob_end_clean();
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        $msg = preg_replace('/in\s+\/\S+/', 'in [file]', $e['message'] ?? 'Unknown fatal');
        echo json_encode(['ok' => false, 'error' => mb_substr($msg, 0, 400)]);
    }
});

try {
    require_once __DIR__ . '/src/Database.php';
    require_once __DIR__ . '/src/DebugLogger.php';
    require_once __DIR__ . '/src/FieldMapper.php';
    require_once __DIR__ . '/src/WooCommerce/WCAnalyser.php';
    require_once __DIR__ . '/src/PrestaShop/PSAnalyser.php';
    require_once __DIR__ . '/src/PrestaShop/PSWriter.php';
    require_once __DIR__ . '/src/Migrator.php';
} catch (\Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Load error: ' . mb_substr($e->getMessage(), 0, 300)]);
    exit;
}

set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
    if (!(error_reporting() & $no)) return false;
    throw new \ErrorException($str, 0, $no, $file, $line);
});

// ── Helpers ──────────────────────────────────────────────────────────────────

function respond(array $data, int $status = 200): never
{
    while (ob_get_level()) ob_end_clean();
    http_response_code($status);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    echo $json ?: '{"ok":false,"error":"Encoding error"}';
    exit;
}

function fail(string $msg, int $status = 400): never
{
    error_log("api.php [{$status}]: {$msg}");
    respond(['ok' => false, 'error' => mb_substr($msg, 0, 500)], $status);
}

function body(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function sanitizeSession(string $s): string
{
    $clean = preg_replace('/[^a-zA-Z0-9_-]/', '', $s) ?: 'default';
    return substr($clean, 0, 64);
}

function buildDatabases(array $data): array
{
    $wc = new Database(
        $data['wc_host']   ?? '127.0.0.1',
        $data['wc_port']   ?? '3306',
        $data['wc_db']     ?? '',
        $data['wc_user']   ?? '',
        $data['wc_pass']   ?? '',
        $data['wc_prefix'] ?? 'wp_'
    );
    $ps = new Database(
        $data['ps_host']   ?? '127.0.0.1',
        $data['ps_port']   ?? '3306',
        $data['ps_db']     ?? '',
        $data['ps_user']   ?? '',
        $data['ps_pass']   ?? '',
        $data['ps_prefix'] ?? 'ps_'
    );
    return [$wc, $ps];
}

function buildMigrator(array $data, string $session): array
{
    [$wcDb, $psDb] = buildDatabases($data);
    $log        = new DebugLogger($session);
    $wcAnal     = new WCAnalyser($wcDb, $log);
    $psAnal     = new PSAnalyser($psDb, $log);
    $idLang     = max(1, (int)($data['ps_id_lang'] ?? 1));
    $idShop     = max(1, (int)($data['ps_id_shop'] ?? 1));
    $psWriter   = new PSWriter($psDb, $psAnal, $log, $idLang, $idShop);
    $batchSize  = max(1, min((int)($data['batch_size'] ?? 20), 100));
    $migrator   = new Migrator($wcAnal, $psAnal, $psWriter, $log, $session, $batchSize);
    return [$migrator, $log, $wcAnal, $psAnal];
}

// ── Router ────────────────────────────────────────────────────────────────────

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$data   = body();
if (!$action && isset($data['action'])) $action = $data['action'];

try {
    switch ($action) {

        // ── Test DB connection ────────────────────────────────────────────────
        case 'test_connection': {
            $side = $data['side'] ?? 'wc'; // 'wc' or 'ps'
            $prefix = $side === 'ps' ? 'ps_' : 'wp_';
            $db = new Database(
                $data['host']   ?? '127.0.0.1',
                $data['port']   ?? '3306',
                $data['db']     ?? '',
                $data['user']   ?? '',
                $data['pass']   ?? '',
                $data['prefix'] ?? $prefix
            );
            // Quick smoke test
            $db->query('SELECT 1');
            $tables = count($db->getTables($data['prefix'] ?? $prefix));
            $extra  = [];

            if ($side === 'wc') {
                $row = $db->queryOne(
                    "SELECT option_value FROM `{$db->getPrefix()}options`
                     WHERE option_name='siteurl' LIMIT 1"
                );
                $extra['site_url'] = $row['option_value'] ?? '';
                $wc = $db->queryOne(
                    "SELECT option_value FROM `{$db->getPrefix()}options`
                     WHERE option_name='woocommerce_version' LIMIT 1"
                );
                $extra['wc_version'] = $wc['option_value'] ?? 'unknown';
            } else {
                $row = $db->queryOne(
                    "SELECT value FROM `{$db->getPrefix()}configuration`
                     WHERE name='PS_VERSION_DB' LIMIT 1"
                );
                $extra['ps_version'] = $row['value'] ?? 'unknown';
                $langRow = $db->queryOne(
                    "SELECT value FROM `{$db->getPrefix()}configuration`
                     WHERE name='PS_LANG_DEFAULT' LIMIT 1"
                );
                $extra['default_lang'] = $langRow ? (int)$langRow['value'] : 1;
                $shopRow = $db->queryOne(
                    "SELECT id_shop FROM `{$db->getPrefix()}shop` ORDER BY id_shop ASC LIMIT 1"
                );
                $extra['default_shop'] = $shopRow ? (int)$shopRow['id_shop'] : 1;
            }

            respond(['ok' => true, 'tables' => $tables] + $extra);
        }

        // ── Analyse both databases ────────────────────────────────────────────
        case 'analyse': {
            $session = sanitizeSession($data['session'] ?? 'default');
            [, $log, $wcAnal, $psAnal] = buildMigrator($data, $session);
            $analysis = [
                'wc' => $wcAnal->analyse(),
                'ps' => $psAnal->analyse(),
            ];
            $log->info('Analysis complete');
            respond(['ok' => true, 'analysis' => $analysis, 'log' => $log->getEntries()]);
        }

        // ── Start migration ───────────────────────────────────────────────────
        case 'start': {
            $session = sanitizeSession($data['session'] ?? 'default');
            [$migrator] = buildMigrator($data, $session);
            $opts = [
                'migrate_categories' => (bool)($data['migrate_categories'] ?? true),
                'migrate_attributes' => (bool)($data['migrate_attributes'] ?? true),
                'migrate_products'   => (bool)($data['migrate_products']   ?? true),
                'migrate_images'     => (bool)($data['migrate_images']     ?? false),
                'ps_root_path'       => (string)($data['ps_root_path']     ?? ''),
                'batch_size'         => max(1, min((int)($data['batch_size'] ?? 20), 100)),
            ];
            respond(['ok' => true, 'progress' => $migrator->runStep($opts)]);
        }

        // ── Poll / continue migration ─────────────────────────────────────────
        case 'step': {
            $session = sanitizeSession($data['session'] ?? 'default');
            [$migrator] = buildMigrator($data, $session);
            // opts are stored in state from the initial 'start' call
            respond(['ok' => true, 'progress' => $migrator->runStep()]);
        }

        // ── Get current progress ──────────────────────────────────────────────
        case 'progress': {
            $session = sanitizeSession($data['session'] ?? 'default');
            [$migrator] = buildMigrator($data, $session);
            respond(['ok' => true, 'progress' => $migrator->getProgress()]);
        }

        // ── Poll log entries ──────────────────────────────────────────────────
        case 'log': {
            $session = sanitizeSession($data['session'] ?? $_GET['session'] ?? 'default');
            $offset  = max(0, (int)($data['offset'] ?? $_GET['offset'] ?? 0));
            $log     = new DebugLogger($session);
            $result  = $log->getEntries($offset);

            // Include diagnostic info on first poll (offset=0) to help debug blank terminal
            $extra = [];
            if ($offset === 0) {
                $dir      = __DIR__ . '/migration_progress';
                $logFile  = $dir . '/log_' . $session . '.json';
                $extra = [
                    'debug_session'    => $session,
                    'debug_dir_exists' => is_dir($dir),
                    'debug_dir_write'  => is_writable($dir),
                    'debug_file_exists'=> file_exists($logFile),
                    'debug_file_size'  => file_exists($logFile) ? filesize($logFile) : 0,
                ];
            }

            respond(['ok' => true, 'version' => APP_VERSION] + $result + $extra);
        }

        // ── Image batch migration ─────────────────────────────────────────────
        case 'images': {
            $session = sanitizeSession($data['session'] ?? 'default');
            [$migrator] = buildMigrator($data, $session);
            $progress = $migrator->migrateImagesBatch($data);
            respond(['ok' => true, 'progress' => $progress]);
        }

        // ── Auto-detect WC/PS filesystem paths ───────────────────────────────
        case 'detect_paths': {
            [, , $wcAnal, $psAnal] = buildMigrator($data, 'detect');
            $wcUploads = $wcAnal->detectUploadsPath();
            $psRoot    = $psAnal->detectRootPath();
            respond([
                'ok'            => true,
                'wc_uploads'    => $wcUploads,
                'ps_root'       => $psRoot,
                'wc_found'      => $wcUploads !== '',
                'ps_found'      => $psRoot    !== '',
            ]);
        }

        // ── Import images (separate phase, batched) ──────────────────────────
        case 'import_images': {
            $session = sanitizeSession($data['session'] ?? 'default');
            [$migrator] = buildMigrator($data, $session);
            $progress = $migrator->importImages($data);
            respond(['ok' => true, 'progress' => $progress]);
        }

        // ── Pause ─────────────────────────────────────────────────────────────
        case 'pause': {
            $session = sanitizeSession($data['session'] ?? 'default');
            [$migrator] = buildMigrator($data, $session);
            respond(['ok' => true, 'progress' => $migrator->pause()]);
        }

        // ── Resume ────────────────────────────────────────────────────────────
        case 'resume': {
            $session = sanitizeSession($data['session'] ?? 'default');
            [$migrator] = buildMigrator($data, $session);
            respond(['ok' => true, 'progress' => $migrator->resume()]);
        }

        // ── Stop (keep cursor, don't delete PS data) ──────────────────────────
        case 'stop': {
            $session = sanitizeSession($data['session'] ?? 'default');
            [$migrator] = buildMigrator($data, $session);
            respond(['ok' => true, 'progress' => $migrator->stop()]);
        }

        // ── Reset ─────────────────────────────────────────────────────────────
        case 'reset': {
            $session = sanitizeSession($data['session'] ?? 'default');
            [$migrator] = buildMigrator($data, $session);
            $result = $migrator->reset();
            respond(['ok' => true] + $result);
        }

        // ── List active sessions (diagnostic) ──────────────────────────────
        case 'list_sessions': {
            $dir   = __DIR__ . '/migration_progress';
            $files = glob($dir . '/progress_*.json') ?: [];
            $sessions = [];
            foreach ($files as $f) {
                $sess = preg_replace('/.*progress_(.+)\.json$/', '$1', $f);
                $size = filesize($f);
                $mtime= filemtime($f);
                $logF = $dir . '/log_' . $sess . '.json';
                $data = @json_decode(@file_get_contents($f), true);
                $sessions[] = [
                    'session'      => $sess,
                    'modified'     => date('H:i:s', $mtime),
                    'progress_size'=> $size,
                    'log_exists'   => file_exists($logF),
                    'log_size'     => file_exists($logF) ? filesize($logF) : 0,
                    'status'       => $data['status'] ?? '?',
                    'step'         => $data['step']   ?? '?',
                    'done_products'=> $data['done_products'] ?? 0,
                    'total_products'=> $data['total_products'] ?? 0,
                ];
            }
            respond(['ok' => true, 'sessions' => $sessions, 'count' => count($sessions)]);
        }

        default:
            fail("Unknown action: {$action}");
    }
} catch (\Throwable $e) {
    fail($e->getMessage());
}
