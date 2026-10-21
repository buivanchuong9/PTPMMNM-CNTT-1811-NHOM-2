<?php
declare(strict_types=1);

namespace StockFlow\Core;

/**
 * Điểm gắn "chèn lỗi" cho kiểm thử hỗn loạn (chaos): production không đăng ký gì nên fire() là no-op.
 * Ví dụ test: Hooks::on('transfer.between', fn() => throw new \RuntimeException('boom'));
 */
final class Hooks
{
    /** @var array<string,list<callable>> */
    private static array $handlers = [];

    public static function on(string $point, callable $handler): void
    {
        self::$handlers[$point][] = $handler;
    }

    public static function clear(): void
    {
        self::$handlers = [];
    }

    public static function fire(string $point, array $context = []): void
    {
        foreach (self::$handlers[$point] ?? [] as $handler) {
            $handler($context);
        }
    }
}
