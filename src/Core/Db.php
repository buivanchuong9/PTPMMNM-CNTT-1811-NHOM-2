<?php
declare(strict_types=1);

namespace StockFlow\Core;

use mysqli;
use mysqli_sql_exception;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Lớp mỏng quanh CSDL: prepared statement, giao dịch lồng nhau, tự thử lại khi deadlock.
 * Hai driver: MySQL (mysqli, dùng thật) và SQLite (PDO, chế độ demo không cần cài MySQL).
 */
final class Db
{
    private const DEADLOCK = 1213;
    private const LOCK_WAIT_TIMEOUT = 1205;
    public const DUPLICATE_KEY = 1062;

    private int $depth = 0;

    private function __construct(private ?mysqli $conn, private ?PDO $pdo) {}

    public static function connect(array $config): self
    {
        return ($config['driver'] ?? 'mysql') === 'sqlite' ? self::connectSqlite($config['path']) : self::connectMysql($config);
    }

    private static function connectMysql(array $config): self
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
            // MySQL 8 dùng transaction_isolation; MariaDB (XAMPP) < 11.1 chỉ có tx_isolation.
            $c->query('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
            return new self($c, null);
        } catch (mysqli_sql_exception $e) {
            throw new RuntimeException('DATABASE_UNAVAILABLE', (int)$e->getCode(), $e);
        }
    }

    private static function connectSqlite(string $path): self
    {
        if (!extension_loaded('pdo_sqlite')) {
            throw new RuntimeException('SQLITE_EXTENSION_MISSING');
        }
        if ($path !== ':memory:' && !is_dir(dirname($path))) {
            @mkdir(dirname($path), 0700, true);
        }
        $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $pdo->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000; PRAGMA journal_mode = WAL');
        return new self(null, $pdo);
    }

    public function isSqlite(): bool
    {
        return $this->pdo !== null;
    }

    /** Giải thích lỗi kết nối bằng tiếng Việt kèm cách xử lý (dùng cho CLI). */
    public static function explain(Throwable $e): string
    {
        $cause = $e->getPrevious() ?? $e;
        $hint = match ((int)$cause->getCode()) {
            2002, 2003 => 'MySQL chưa chạy hoặc sai máy chủ/cổng. macOS: `brew services start mysql` · Ubuntu: `sudo service mysql start`.',
            1045 => 'Sai tên tài khoản hoặc mật khẩu (hoặc tài khoản chưa được tạo cho host này).',
            1049 => 'CSDL chưa tồn tại. Tạo bằng: CREATE DATABASE <tên> CHARACTER SET utf8mb4;',
            1044 => 'Tài khoản chưa có quyền trên CSDL này. Cấp bằng: GRANT ALL ON <tên>.* TO \'<user>\'@\'localhost\';',
            default => '',
        };
        return $e->getMessage() . ($cause !== $e ? ' — MySQL: [' . $cause->getCode() . '] ' . $cause->getMessage() : '') . ($hint !== '' ? "\n  → $hint" : '');
    }

    /** Dịch phần cú pháp riêng của MySQL sang SQLite (chỉ vài điểm khác biệt). */
    private function sqlite(string $sql): string
    {
        $sql = preg_replace('/\s+FOR UPDATE\b/', '', $sql);
        $sql = str_replace('INSERT IGNORE INTO', 'INSERT OR IGNORE INTO', $sql);
        return (string)preg_replace('/DATE_ADD\(\?, INTERVAL 1 DAY\)/', "date(?, '+1 day')", $sql);
    }

    /** Chạy prepared statement; trả về [mysqli_stmt|PDOStatement]. */
    private function run(string $sql, array $params): mixed
    {
        if ($this->pdo) {
            try {
                $st = $this->pdo->prepare($this->sqlite($sql));
                $st->execute($params);
                return $st;
            } catch (PDOException $e) {
                throw self::asMysqlError($e);
            }
        }
        $st = $this->conn->prepare($sql);
        $st->execute($params);
        return $st;
    }

    /** Quy lỗi SQLite về mã lỗi MySQL tương ứng để phần còn lại của ứng dụng xử lý chung. */
    private static function asMysqlError(PDOException $e): mysqli_sql_exception
    {
        $m = $e->getMessage();
        $code = match (true) {
            str_contains($m, 'UNIQUE constraint') => self::DUPLICATE_KEY,
            str_contains($m, 'CHECK constraint') => 3819,
            str_contains($m, 'FOREIGN KEY') => 1452,
            str_contains($m, 'RAISE') || str_contains($m, 'append-only') || str_contains($m, 'immutable') || str_contains($m, 'cannot be deleted') => 1644,
            default => 0,
        };
        return new mysqli_sql_exception($m, $code);
    }

    /** Chạy câu lệnh thô (DDL/trigger) — chỉ dùng cho migration. */
    public function raw(string $sql): void
    {
        if ($this->pdo) {
            try {
                $this->pdo->exec($sql);
            } catch (PDOException $e) {
                throw self::asMysqlError($e);
            }
            return;
        }
        $this->conn->query($sql);
    }

    /** @return list<array<string,mixed>> */
    public function all(string $sql, array $params = []): array
    {
        $st = $this->run($sql, $params);
        return $this->pdo ? $st->fetchAll(PDO::FETCH_ASSOC) : $st->get_result()->fetch_all(MYSQLI_ASSOC);
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
        $st = $this->run($sql, $params);
        return $this->pdo ? $st->rowCount() : $st->affected_rows;
    }

    public function insert(string $sql, array $params = []): int
    {
        $this->run($sql, $params);
        return $this->pdo ? (int)$this->pdo->lastInsertId() : (int)$this->conn->insert_id;
    }

    public function inTransaction(): bool
    {
        return $this->depth > 0;
    }

    private function begin(): void
    {
        $this->pdo ? $this->pdo->exec('BEGIN IMMEDIATE') : $this->conn->begin_transaction();
    }

    private function commit(): void
    {
        $this->pdo ? $this->pdo->exec('COMMIT') : $this->conn->commit();
    }

    private function rollback(): void
    {
        $this->pdo ? ($this->pdo->inTransaction() ? $this->pdo->exec('ROLLBACK') : null) : $this->conn->rollback();
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
            $this->begin();
            $this->depth = 1;
            try {
                $result = $fn();
                $this->commit();
                return $result;
            } catch (Throwable $e) {
                try {
                    $this->rollback();
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
        if ($this->pdo) {
            $this->exec('INSERT OR IGNORE INTO sequences (name, value) VALUES (?, 0)', [$name]);
            $this->exec('UPDATE sequences SET value = value + 1 WHERE name = ?', [$name]);
            return (int)$this->value('SELECT value FROM sequences WHERE name = ?', [$name]);
        }
        $this->exec('INSERT INTO sequences (name, value) VALUES (?, 0) ON DUPLICATE KEY UPDATE value = value', [$name]);
        $this->exec('UPDATE sequences SET value = LAST_INSERT_ID(value + 1) WHERE name = ?', [$name]);
        return (int)$this->value('SELECT LAST_INSERT_ID()');
    }
}
