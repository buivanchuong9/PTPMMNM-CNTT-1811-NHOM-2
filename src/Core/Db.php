<?php
declare(strict_types=1);

namespace StockFlow\Core;

use mysqli;
use mysqli_sql_exception;
use RuntimeException;
use Throwable;

/** Lớp mỏng quanh mysqli: prepared statement, giao dịch lồng nhau, tự thử lại khi deadlock. */
final class Db
{
    private const DEADLOCK = 1213;
    private const LOCK_WAIT_TIMEOUT = 1205;
    public const DUPLICATE_KEY = 1062;

    private int $depth = 0;

    public function __construct(private mysqli $conn) {}

    public static function connect(array $config): self
    {
        if (!extension_loaded('mysqli')) {
            throw new RuntimeException('MYSQLI_EXTENSION_MISSING');
        }
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        try {
            $c = mysqli_init();
            $c->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3);
            $c->real_connect($config['host'], $config['user'], $config['password'], $config['database'], (int)$config['port']);
            $c->set_charset('utf8mb4');
            $c->query("SET time_zone = '+00:00'");
            $c->query("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION,ONLY_FULL_GROUP_BY'");
            $c->query('SET SESSION transaction_isolation = "READ-COMMITTED"');
            return new self($c);
        } catch (mysqli_sql_exception) {
            throw new RuntimeException('DATABASE_UNAVAILABLE');
        }
    }

    private function run(string $sql, array $params): \mysqli_stmt
    {
        $st = $this->conn->prepare($sql);
        $st->execute($params);
        return $st;
    }

    /** Chạy câu lệnh thô (DDL/trigger không hỗ trợ prepared statement) — chỉ dùng cho migration. */
    public function raw(string $sql): void
    {
        $this->conn->query($sql);
    }

    /** @return list<array<string,mixed>> */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function one(string $sql, array $params = []): ?array
    {
        return $this->all($sql, $params)[0] ?? null;
    }

    public function value(string $sql, array $params = []): mixed
    {
        $row = $this->one($sql, $params);
        return $row === null ? null : reset($row);
    }

    /** @return int số dòng bị ảnh hưởng */
    public function exec(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->affected_rows;
    }

    public function insert(string $sql, array $params = []): int
    {
        $this->run($sql, $params);
        return (int)$this->conn->insert_id;
    }

    public function inTransaction(): bool
    {
        return $this->depth > 0;
    }

    /**
     * Chạy $fn trong một giao dịch. Gọi lồng nhau thì nhập vào giao dịch ngoài cùng.
     * Giao dịch ngoài cùng được thử lại tối đa $retries lần nếu MySQL báo deadlock/lock-wait-timeout.
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public function transaction(callable $fn, int $retries = 3): mixed
    {
        if ($this->depth > 0) {
            return $fn();
        }
        for ($attempt = 1;; $attempt++) {
            $this->conn->begin_transaction();
            $this->depth = 1;
            try {
                $result = $fn();
                $this->conn->commit();
                return $result;
            } catch (Throwable $e) {
                try {
                    $this->conn->rollback();
                } catch (Throwable) {
                }
                $retryable = $e instanceof mysqli_sql_exception
                    && in_array($e->getCode(), [self::DEADLOCK, self::LOCK_WAIT_TIMEOUT], true);
                if (!$retryable || $attempt >= $retries) {
                    throw $e;
                }
                usleep(random_int(20_000, 80_000) * $attempt);
            } finally {
                $this->depth = 0;
            }
        }
    }

    /** Cấp số thứ tự nguyên tử cho mã chứng từ (khoá dòng đến hết giao dịch). */
    public function nextSequence(string $name): int
    {
        $this->exec('INSERT INTO sequences (name, value) VALUES (?, 0) ON DUPLICATE KEY UPDATE value = value', [$name]);
        $this->exec('UPDATE sequences SET value = LAST_INSERT_ID(value + 1) WHERE name = ?', [$name]);
        return (int)$this->value('SELECT LAST_INSERT_ID()');
    }
}
