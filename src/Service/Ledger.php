<?php
declare(strict_types=1);

namespace StockFlow\Service;

use StockFlow\Core\Db;
use StockFlow\Core\Hooks;
use StockFlow\Domain\InsufficientStock;

/**
 * Lõi ghi sổ. Quy trình cho mọi phiếu nhập xuất:
 *   1) lock()  — khoá các dòng tồn (kho, hàng, lô) theo thứ tự cố định → không deadlock giữa các giao dịch;
 *   2) post()  — kiểm tra đủ hàng, cập nhật số dư, ghi một dòng so_phat_sinh cho mỗi dòng phiếu nhập xuất.
 * Phải gọi trong giao dịch của người gọi: lỗi ở bất kỳ bước nào ⇒ rollback toàn bộ, không có thay đổi dở dang.
 */
final class Ledger
{
    public function __construct(private Db $db) {}

    /** @param list<array{0:int,1:int,2:int}> $keys danh sách [warehouse, item, batch] */
    public function lock(array $keys): void
    {
        $unique = [];
        foreach ($keys as [$w, $i, $b]) {
            $unique["$w:$i:$b"] = [$w, $i, $b];
        }
        $keys = array_values($unique);
        usort($keys, fn($x, $y) => $x <=> $y);
        foreach ($keys as [$w, $i, $b]) {
            $this->db->exec('INSERT IGNORE INTO ton_kho (kho_id, mat_hang_id, lo_hang_id, so_luong) VALUES (?,?,?,0)', [$w, $i, $b]);
        }
        foreach ($keys as [$w, $i, $b]) {
            $this->db->one('SELECT so_luong FROM ton_kho WHERE kho_id = ? AND mat_hang_id = ? AND lo_hang_id = ? FOR UPDATE', [$w, $i, $b]);
        }
        Hooks::fire('ledger.after_lock', ['keys' => $keys]);
    }

    /**
     * @param list<array{voucher_id:int,line_id:int,warehouse_id:int,item_id:int,batch_id:int,delta:int}> $ops
     */
    public function post(array $ops): void
    {
        foreach ($ops as $op) {
            $cur = (int)$this->db->value(
                'SELECT so_luong FROM ton_kho WHERE kho_id = ? AND mat_hang_id = ? AND lo_hang_id = ? FOR UPDATE',
                [$op['kho_id'], $op['mat_hang_id'], $op['lo_hang_id']]
            );
            $next = $cur + $op['delta'];
            if ($next < 0) {
                throw $this->insufficient($op, $cur);
            }
            Hooks::fire('ledger.before_write', $op);
            $this->db->exec('UPDATE ton_kho SET so_luong = ? WHERE kho_id = ? AND mat_hang_id = ? AND lo_hang_id = ?',
                [$next, $op['kho_id'], $op['mat_hang_id'], $op['lo_hang_id']]);
            $this->db->exec(
                'INSERT INTO so_phat_sinh (phieu_nhap_xuat_id, chi_tiet_phieu_nhap_xuat_id, kho_id, mat_hang_id, lo_hang_id, bien_dong, ton_sau)
                 VALUES (?,?,?,?,?,?,?)',
                [$op['phieu_nhap_xuat_id'], $op['line_id'], $op['kho_id'], $op['mat_hang_id'], $op['lo_hang_id'], $op['delta'], $next]
            );
        }
    }

    private function insufficient(array $op, int $available): InsufficientStock
    {
        $info = $this->db->one('SELECT i.ma_hang, b.so_lo, w.ma FROM mat_hang i JOIN lo_hang b ON b.id = ? JOIN kho w ON w.id = ? WHERE i.id = ?',
            [$op['lo_hang_id'], $op['kho_id'], $op['mat_hang_id']]) ?? ['ma_hang' => '#' . $op['mat_hang_id'], 'so_lo' => '?', 'ma' => '#' . $op['kho_id']];
        return new InsufficientStock($info['ma_hang'], $info['so_lo'], $info['ma'], $available, abs($op['delta']));
    }
}
