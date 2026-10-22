<?php
declare(strict_types=1);

namespace StockFlow\Domain;

/** Quy tắc nghiệp vụ thuần — không chạm CSDL, dễ kiểm thử. */
final class StockRules
{
    /** Áp biến động vào tồn hiện tại; tồn không bao giờ âm. */
    public static function apply(int $current, int $delta): int
    {
        if ($current < 0) {
            throw new \InvalidArgumentException('Tồn hiện tại không hợp lệ');
        }
        $next = $current + $delta;
        if ($next < 0) {
            throw new DomainError("Không đủ tồn: hiện có $current, cần " . abs($delta));
        }
        return $next;
    }

    /** Biến động có dấu của một dòng phiếu. */
    public static function delta(VoucherType $type, int $qty): int
    {
        if ($qty === 0) {
            throw new DomainError('Số lượng phải khác 0');
        }
        if (!$type->isSigned() && $qty < 0) {
            throw new DomainError('Số lượng phải là số nguyên dương');
        }
        return $type->isSigned() ? $qty : $type->sign() * $qty;
    }

    /** Đọc số nguyên không âm từ chuỗi nhập; null nếu sai định dạng. */
    public static function parseQty(mixed $value, int $max = 1_000_000): ?int
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $text = trim((string)$value);
        if ($text === '' || !ctype_digit($text) || strlen($text) > 9) {
            return null;
        }
        $n = (int)$text;
        return $n <= $max ? $n : null;
    }

    /**
     * Gộp các dòng nhập tay ("item:batch" + số lượng) thành danh sách dòng chuẩn; bỏ dòng trống, cộng dồn dòng trùng.
     * @param list<mixed> $keys @param list<mixed> $qtys
     * @return list<array{item_id:int,batch_id:int,qty:int}>
     */
    public static function normalizeLines(array $keys, array $qtys): array
    {
        $merged = [];
        $blank = static fn($v) => !is_scalar($v) || trim((string)$v) === '';
        foreach ($keys as $i => $rawKey) {
            $rawQty = $qtys[$i] ?? '';
            if ($blank($rawKey) && $blank($rawQty)) {
                continue;
            }
            if ($blank($rawKey) || !preg_match('/^(\d+):(\d+)$/', trim((string)$rawKey), $m)) {
                throw new DomainError('Chưa chọn mặt hàng/lô ở một dòng phiếu');
            }
            $qty = self::parseQty($rawQty);
            if ($qty === null || $qty < 1) {
                throw new DomainError('Số lượng phải là số nguyên dương');
            }
            $key = $m[1] . ':' . $m[2];
            $merged[$key] = ['mat_hang_id' => (int)$m[1], 'lo_hang_id' => (int)$m[2], 'so_luong' => ($merged[$key]['so_luong'] ?? 0) + $qty];
        }
        return array_values($merged);
    }

    /** Dòng đảo của phiếu nhập xuất gốc (điều chỉnh sửa phiếu nhập xuất, không xoá lịch sử). */
    public static function reversalQty(VoucherType $type, int $qty): int
    {
        return -self::delta($type, $qty);
    }
}
