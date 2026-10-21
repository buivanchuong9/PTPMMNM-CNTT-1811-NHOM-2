<?php
declare(strict_types=1);

namespace StockFlow\Core;

final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(public readonly string $body = '', public readonly int $status = 200, public readonly array $headers = []) {}

    public static function redirect(string $to, int $status = 303): self
    {
        return new self('', $status, ['Location' => $to]);
    }

    /** @param list<list<scalar|null>> $rows */
    public static function csv(string $filename, array $header, array $rows): self
    {
        $h = fopen('php://temp', 'w+');
        fwrite($h, "\xEF\xBB\xBF"); // BOM để Excel đọc đúng UTF-8
        foreach ([$header, ...$rows] as $row) {
            // Chống CSV injection: ô bắt đầu bằng = + - @ bị Excel hiểu là công thức.
            fputcsv($h, array_map(fn($c) => is_string($c) && $c !== '' && strpbrk($c[0], "=+-@\t\r") !== false ? "'" . $c : $c, $row), ',', '"', '');
        }
        rewind($h);
        $body = (string)stream_get_contents($h);
        return new self($body, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"',
        ]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) {
            header("$k: $v");
        }
        echo $this->body;
    }
}
