<?php
declare(strict_types=1);

namespace StockFlow\Core;

/** Phân trang: 20 dòng / trang. Trang ngoài phạm vi được kẹp về trang hợp lệ gần nhất. */
final class Paginator
{
    public const PER_PAGE = 20;

    /** Số dòng mỗi trang áp dụng cho yêu cầu hiện tại (App nạp theo cài đặt của tài khoản). */
    public static int $default = self::PER_PAGE;

    public readonly int $per;

    public readonly int $pages;
    public readonly int $page;
    public readonly int $offset;

    public function __construct(public readonly int $total, ?int $page, ?int $per = null, public readonly string $param = 'page')
    {
        $this->per = $per ?? self::$default;
        $this->pages = max(1, (int)ceil($total / $this->per));
        $this->page = min(max(1, $page ?? 1), $this->pages);
        $this->offset = ($this->page - 1) * $this->per;
    }

    /** Phân trang trên mảng đã có sẵn trong bộ nhớ (danh mục nhỏ). */
    public static function of(array $rows, Request $r, string $param = 'page'): array
    {
        $pg = new self(count($rows), $r->int($param), null, $param);
        return [$pg->slice($rows), $pg];
    }

    public function slice(array $rows): array
    {
        return array_slice($rows, $this->offset, $this->per);
    }

    public function from(): int
    {
        return $this->total === 0 ? 0 : $this->offset + 1;
    }

    public function to(): int
    {
        return min($this->total, $this->offset + $this->per);
    }
}
