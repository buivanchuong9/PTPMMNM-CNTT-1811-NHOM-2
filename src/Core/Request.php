<?php
declare(strict_types=1);

namespace StockFlow\Core;

final class Request
{
    public function __construct(public readonly string $method, public readonly string $path,
                                public readonly array $query, public readonly array $post, public readonly array $files = []) {}

    public static function fromGlobals(): self
    {
        $path = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
        $path = '/' . trim($path, '/');
        return new self(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), $path, $_GET, $_POST, $_FILES);
    }

    public function str(string $key, string $default = ''): string
    {
        $v = $this->post[$key] ?? $this->query[$key] ?? $default;
        return is_string($v) ? trim($v) : $default;
    }

    public function int(string $key): ?int
    {
        $v = filter_var($this->str($key), FILTER_VALIDATE_INT);
        return $v === false ? null : $v;
    }

    /** @return array<mixed> */
    public function arr(string $key): array
    {
        $v = $this->post[$key] ?? $this->query[$key] ?? [];
        return is_array($v) ? $v : [];
    }
}
