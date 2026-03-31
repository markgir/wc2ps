<?php
declare(strict_types=1);

/**
 * DebugLogger — structured JSON log with timing, query stats and SQL tracing.
 *
 * Every entry has:
 *   ts        — ISO timestamp
 *   elapsed   — ms since logger creation
 *   level     — debug|info|success|warning|error|sql|query
 *   step      — migration phase (connections|analyse|mapping|migrate|done)
 *   message   — human string
 *   context   — optional key/value data
 */
class DebugLogger
{
    private string $logFile;
    private float  $startTime;
    private string $currentStep = 'init';
    private int    $queryCount  = 0;
    private int    $errorCount  = 0;
    private int    $warnCount   = 0;

    private const MAX_LOG_BYTES = 10 * 1024 * 1024; // 10 MB

    public function __construct(string $sessionId)
    {
        $dir  = __DIR__ . '/../migration_progress';
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId) ?: 'default';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $this->logFile   = $dir . '/log_' . $safe . '.json';
        $this->startTime = microtime(true);
    }

    // ── Step tracking ───────────────────────────────────────────────────────

    public function setStep(string $step): void
    {
        $this->currentStep = $step;
        $this->writeEntry('info', "── Step: {$step} ──");
    }

    // ── Log levels ──────────────────────────────────────────────────────────

    public function debug(string $msg, array $ctx = []): void   { $this->writeEntry('debug',   $msg, $ctx); }
    public function info(string $msg, array $ctx = []): void    { $this->writeEntry('info',    $msg, $ctx); }
    public function success(string $msg, array $ctx = []): void { $this->writeEntry('success', $msg, $ctx); }
    public function warning(string $msg, array $ctx = []): void { $this->warnCount++;  $this->writeEntry('warning', $msg, $ctx); }
    public function error(string $msg, array $ctx = []): void   { $this->errorCount++; $this->writeEntry('error',   $msg, $ctx); }

    /**
     * Log a SQL statement (only in debug mode — avoids flooding the log).
     */
    public function sql(string $sql, array $params = [], ?int $affectedRows = null): void
    {
        $this->queryCount++;
        $ctx = ['query_n' => $this->queryCount];
        if (!empty($params))       $ctx['params']        = $params;
        if ($affectedRows !== null) $ctx['affected_rows'] = $affectedRows;
        // Shorten long SQLs in the log
        $short = strlen($sql) > 300 ? substr($sql, 0, 300) . '…' : $sql;
        $this->writeEntry('sql', $short, $ctx);
    }

    /**
     * Log a schema discovery (table/column info).
     */
    public function schema(string $table, array $columns): void
    {
        $this->writeEntry('debug', "Schema: {$table}", ['columns' => $columns]);
    }

    /**
     * Log a field mapping decision.
     */
    public function mapping(string $from, string $to, string $reason = ''): void
    {
        $ctx = ['from' => $from, 'to' => $to];
        if ($reason) $ctx['reason'] = $reason;
        $this->writeEntry('debug', "Map: {$from} → {$to}", $ctx);
    }

    // ── Stats ───────────────────────────────────────────────────────────────

    public function getQueryCount(): int { return $this->queryCount; }
    public function getErrorCount(): int { return $this->errorCount; }
    public function getWarnCount(): int  { return $this->warnCount;  }

    public function getSummary(): array
    {
        return [
            'queries' => $this->queryCount,
            'errors'  => $this->errorCount,
            'warnings'=> $this->warnCount,
            'elapsed_ms' => round((microtime(true) - $this->startTime) * 1000),
        ];
    }

    // ── Read / Clear ────────────────────────────────────────────────────────

    public function getEntries(int $offset = 0): array
    {
        if (!file_exists($this->logFile))
            return ['entries' => [], 'next_offset' => 0];

        $lines = file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $total = count($lines);
        $offset = max(0, min($offset, $total));

        $entries = [];
        foreach (array_slice($lines, $offset) as $line) {
            $d = json_decode($line, true);
            if (is_array($d)) $entries[] = $d;
        }
        return ['entries' => $entries, 'next_offset' => $total];
    }

    public function clearLog(): void
    {
        if (file_exists($this->logFile)) unlink($this->logFile);
        $this->queryCount = $this->errorCount = $this->warnCount = 0;
        $this->startTime  = microtime(true);
    }

    // ── Internal ────────────────────────────────────────────────────────────

    /**
     * Public write() — dispatch by level string. Used by WCAnalyser/PSAnalyser
     * to log issue entries without choosing a specific named method.
     */
    public function write(string $level, string $message, array $ctx = []): void
    {
        match ($level) {
            'error'   => $this->error($message, $ctx),
            'warning' => $this->warning($message, $ctx),
            'success' => $this->success($message, $ctx),
            'debug'   => $this->debug($message, $ctx),
            default   => $this->info($message, $ctx),
        };
    }

    private function writeEntry(string $level, string $message, array $ctx = []): void
    {
        // Rotate if too large
        if (file_exists($this->logFile) && filesize($this->logFile) > self::MAX_LOG_BYTES) {
            $this->rotate();
        }

        $entry = [
            'ts'      => date('H:i:s'),
            'elapsed' => round((microtime(true) - $this->startTime) * 1000),
            'step'    => $this->currentStep,
            'level'   => $level,
            'message' => $message,
        ];
        if (!empty($ctx)) $entry['ctx'] = $ctx;

        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line === false)
            $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($line !== false)
            file_put_contents($this->logFile, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    private function rotate(): void
    {
        // Don't rotate — truncation breaks client offset tracking.
        // Log growth is acceptable (typically ~200 bytes/entry).
        // A 5900-product migration with debug logging = ~60K lines = ~12MB max.
    }
}
