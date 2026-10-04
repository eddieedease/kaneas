<?php

declare(strict_types=1);

namespace Kaneas\Core;

use PDO;
use PDOStatement;

/**
 * Thin PDO wrapper. Table names are written as `{table}` in SQL and are
 * expanded with the configured table prefix (shared hosting often shares one database).
 */
final class Database
{
    private static ?self $instance = null;

    private int $transactionDepth = 0;

    public function __construct(private readonly PDO $pdo, private readonly string $prefix)
    {
    }

    public static function get(): self
    {
        return self::$instance ??= new self(
            self::connect(Config::get('db')),
            (string) Config::get('db.prefix', ''),
        );
    }

    /** @param array{host:string,port?:int|string,name:string,user:string,pass:string} $cfg */
    public static function connect(array $cfg): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $cfg['host'],
            (int) ($cfg['port'] ?? 3306),
            $cfg['name'],
        );
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
        return $pdo;
    }

    public static function isValidPrefix(string $prefix): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_]{0,20}$/', $prefix);
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function sql(string $sql): string
    {
        return (string) preg_replace('/\{(\w+)\}/', '`' . $this->prefix . '$1`', $sql);
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($this->sql($sql));
        $stmt->execute($params);
        return $stmt;
    }

    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function all(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function value(string $sql, array $params = []): mixed
    {
        $value = $this->query($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    public function insert(string $sql, array $params = []): int
    {
        $this->query($sql, $params);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @template T
     * @param callable(self): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        if ($this->transactionDepth > 0) {
            return $fn($this);
        }
        $this->pdo->beginTransaction();
        $this->transactionDepth++;
        try {
            $result = $fn($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        } finally {
            $this->transactionDepth--;
        }
    }
}
