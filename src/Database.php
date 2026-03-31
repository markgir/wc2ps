<?php
declare(strict_types=1);

/**
 * Database — thin PDO wrapper with transaction guards and schema introspection.
 */
class Database
{
    private PDO    $pdo;
    private string $prefix;
    private bool   $inTransaction = false;

    public function __construct(
        string $host,
        string $port,
        string $dbname,
        string $user,
        string $password,
        string $prefix = ''
    ) {
        if ($dbname === '') throw new \InvalidArgumentException('Database name must not be empty.');
        if ($user   === '') throw new \InvalidArgumentException('Database user must not be empty.');

        $portInt = (int) $port;
        if ($portInt < 1 || $portInt > 65535)
            throw new \InvalidArgumentException("Invalid port: {$port}");

        if ($prefix !== '' && !preg_match('/^[a-zA-Z0-9_]+$/', $prefix))
            throw new \InvalidArgumentException('Prefix contains invalid characters.');

        // Fast TCP pre-check (skip for localhost/socket)
        $isLocal = ($host === '' || $host === 'localhost' || ($host[0] ?? '') === '/');
        if (!$isLocal) {
            $fp = @fsockopen($host, $portInt, $errno, $errstr, 5);
            if ($fp === false)
                throw new \RuntimeException("Cannot reach {$host}:{$port} — " . ($errstr ?: 'timeout'));
            fclose($fp);
        }

        $dsn = "mysql:host={$host};port={$portInt};dbname={$dbname};charset=utf8mb4";
        $this->pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ]);
        $this->prefix = $prefix;
    }

    public function getPrefix(): string { return $this->prefix; }
    public function getPdo(): PDO       { return $this->pdo; }

    public function query(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function queryOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return ($row !== false) ? $row : null;
    }

    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $this->pdo->lastInsertId();
    }

    public function executeAndCount(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function beginTransaction(): void
    {
        if (!$this->inTransaction) {
            $this->pdo->beginTransaction();
            $this->inTransaction = true;
        }
    }

    public function commit(): void
    {
        if ($this->inTransaction) {
            $this->pdo->commit();
            $this->inTransaction = false;
        }
    }

    public function rollback(): void
    {
        if ($this->inTransaction) {
            $this->pdo->rollBack();
            $this->inTransaction = false;
        }
    }

    public function isInTransaction(): bool { return $this->inTransaction; }

    public function tableExists(string $table): bool
    {
        $quoted = $this->pdo->quote($table);
        return $this->queryOne("SHOW TABLES LIKE {$quoted}") !== null;
    }

    public function getTableColumns(string $table): array
    {
        $rows = $this->query("SHOW COLUMNS FROM `{$table}`");
        return array_column($rows, 'Field');
    }

    public function getColumnTypes(string $table): array
    {
        $rows = $this->query("SHOW COLUMNS FROM `{$table}`");
        $types = [];
        foreach ($rows as $row) {
            $types[$row['Field']] = strtolower($row['Type']);
        }
        return $types;
    }

    public function getTables(string $likePrefix = ''): array
    {
        if ($likePrefix !== '') {
            $escaped = str_replace(['%','_'], ['\\%','\\_'], $likePrefix);
            $quoted  = $this->pdo->quote($escaped . '%');
            $rows    = $this->query("SHOW TABLES LIKE {$quoted}");
        } else {
            $rows = $this->query('SHOW TABLES');
        }
        return array_map('current', $rows);
    }
}
