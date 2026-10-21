<?php
declare(strict_types=1);

namespace StockFlow\Service;

use StockFlow\Core\Db;
use StockFlow\Core\Hooks;
use StockFlow\Domain\InsufficientStock;

/**
 * Lõi ghi sổ. Quy trình cho mọi chứng từ:
 *   1) lock()  — khoá các dòng tồn (kho, hàng, lô) theo thứ tự cố định → không deadlock giữa các giao dịch;
 *   2) post()  — kiểm tra đủ hàng, cập nhật số dư, ghi một dòng stock_movements cho mỗi dòng chứng từ.
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
            $this->db->exec('INSERT IGNORE INTO stock_balances (warehouse_id, item_id, batch_id, quantity) VALUES (?,?,?,0)', [$w, $i, $b]);
        }
        foreach ($keys as [$w, $i, $b]) {
            $this->db->one('SELECT quantity FROM stock_balances WHERE warehouse_id = ? AND item_id = ? AND batch_id = ? FOR UPDATE', [$w, $i, $b]);
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
                'SELECT quantity FROM stock_balances WHERE warehouse_id = ? AND item_id = ? AND batch_id = ? FOR UPDATE',
                [$op['warehouse_id'], $op['item_id'], $op['batch_id']]
            );
            $next = $cur + $op['delta'];
            if ($next < 0) {
                throw $this->insufficient($op, $cur);
            }
            Hooks::fire('ledger.before_write', $op);
            $this->db->exec('UPDATE stock_balances SET quantity = ? WHERE warehouse_id = ? AND item_id = ? AND batch_id = ?',
                [$next, $op['warehouse_id'], $op['item_id'], $op['batch_id']]);
            $this->db->exec(
                'INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, qty_change, balance_after)
                 VALUES (?,?,?,?,?,?,?)',
                [$op['voucher_id'], $op['line_id'], $op['warehouse_id'], $op['item_id'], $op['batch_id'], $op['delta'], $next]
            );
        }
    }

    private function insufficient(array $op, int $available): InsufficientStock
    {
        $info = $this->db->one('SELECT i.sku, b.batch_no, w.code FROM items i JOIN batches b ON b.id = ? JOIN warehouses w ON w.id = ? WHERE i.id = ?',
            [$op['batch_id'], $op['warehouse_id'], $op['item_id']]) ?? ['sku' => '#' . $op['item_id'], 'batch_no' => '?', 'code' => '#' . $op['warehouse_id']];
        return new InsufficientStock($info['sku'], $info['batch_no'], $info['code'], $available, abs($op['delta']));
    }
}
