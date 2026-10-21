<?php
declare(strict_types=1);

namespace StockFlow\Service;

use StockFlow\Core\Db;
use StockFlow\Domain\User;

/** Truy vấn tồn / lịch sử / đối chiếu — số tồn luôn được TÍNH TỪ sổ phát sinh (so_phat_sinh). */
final class StockQuery
{
    public function __construct(private Db $db, private Authorizer $auth) {}

    private function in(array $ids): string
    {
        return implode(',', array_fill(0, count($ids), '?'));
    }

    /** Danh sách (hàng, lô) có thể chọn trong form. */
    public function pickList(): array
    {
        return $this->db->all(
            'SELECT i.id AS item_id, b.id AS batch_id, i.sku, i.name, u.code AS unit, i.track_batch, b.batch_no, b.expiry_date
             FROM mat_hang i JOIN don_vi_tinh u ON u.id = i.unit_id JOIN lo_hang b ON b.item_id = i.id WHERE i.active ORDER BY i.sku, b.batch_no'
        );
    }

    /** Tồn theo (kho, hàng[, lô]) tính từ sổ phát sinh. */
    public function onHand(array $warehouseIds, string $search = '', bool $byBatch = false, bool $includeZero = false): array
    {
        if ($warehouseIds === []) {
            return [];
        }
        $batchCols = $byBatch ? ', b.id AS batch_id, b.batch_no, b.expiry_date' : '';
        $group = $byBatch ? ', b.id, b.batch_no, b.expiry_date' : '';
        $rows = $this->db->all(
            "SELECT w.id AS warehouse_id, w.code AS wh_code, i.id AS item_id, i.sku, i.name, i.reorder_level, u.code AS unit $batchCols,
                    SUM(m.qty_change) AS quantity, MAX(m.posted_at) AS last_move
             FROM so_phat_sinh m JOIN kho w ON w.id = m.warehouse_id JOIN mat_hang i ON i.id = m.item_id
             JOIN don_vi_tinh u ON u.id = i.unit_id JOIN lo_hang b ON b.id = m.batch_id
             WHERE m.warehouse_id IN ({$this->in($warehouseIds)}) AND (i.sku LIKE ? OR i.name LIKE ?)
             GROUP BY w.id, w.code, i.id, i.sku, i.name, i.reorder_level, u.code $group
             " . ($includeZero ? '' : 'HAVING SUM(m.qty_change) <> 0 ') . 'ORDER BY i.sku, w.code' . ($byBatch ? ', b.batch_no' : ''),
            [...$warehouseIds, "%$search%", "%$search%"]
        );
        foreach ($rows as &$r) {
            $r['quantity'] = (int)$r['quantity'];
        }
        return $rows;
    }

    /** Ma trận: mỗi mặt hàng một dòng, cột theo kho. @return list<array<string,mixed>> */
    public function matrix(array $warehouseIds, string $search): array
    {
        $by = [];
        foreach ($this->onHand($warehouseIds, $search) as $r) {
            $by[$r['item_id']] ??= ['item_id' => $r['item_id'], 'sku' => $r['sku'], 'name' => $r['name'], 'unit' => $r['unit'],
                                    'reorder_level' => (int)$r['reorder_level'], 'by_wh' => [], 'total' => 0];
            $by[$r['item_id']]['by_wh'][(int)$r['warehouse_id']] = $r['quantity'];
            $by[$r['item_id']]['total'] += $r['quantity'];
        }
        return array_values($by);
    }

    /** Lịch sử phát sinh có lọc; chỉ trong phạm vi kho cho phép. */
    public function history(array $warehouseIds, array $f = [], int $limit = 200): array
    {
        if ($warehouseIds === []) {
            return [];
        }
        $where = ['m.warehouse_id IN (' . $this->in($warehouseIds) . ')'];
        $params = $warehouseIds;
        if (!empty($f['warehouse'])) { $where[] = 'm.warehouse_id = ?'; $params[] = (int)$f['warehouse']; }
        if (!empty($f['item'])) { $where[] = 'm.item_id = ?'; $params[] = (int)$f['item']; }
        if (!empty($f['type'])) { $where[] = 'v.type = ?'; $params[] = $f['type']; }
        if (!empty($f['q'])) { $where[] = '(v.code LIKE ? OR i.sku LIKE ? OR i.name LIKE ? OR b.batch_no LIKE ?)'; array_push($params, ...array_fill(0, 4, '%' . $f['q'] . '%')); }
        if (!empty($f['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['from'])) { $where[] = 'm.posted_at >= ?'; $params[] = $f['from'] . ' 00:00:00'; }
        if (!empty($f['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['to'])) { $where[] = 'm.posted_at < DATE_ADD(?, INTERVAL 1 DAY)'; $params[] = $f['to'] . ' 00:00:00'; }
        return $this->db->all(
            'SELECT m.*, w.code AS wh_code, i.sku, i.name, u.code AS unit, b.batch_no, v.code AS voucher_code, v.type, v.reason, v.note
             FROM so_phat_sinh m JOIN chung_tu v ON v.id = m.voucher_id JOIN kho w ON w.id = m.warehouse_id
             JOIN mat_hang i ON i.id = m.item_id JOIN don_vi_tinh u ON u.id = i.unit_id JOIN lo_hang b ON b.id = m.batch_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY m.id DESC LIMIT ' . max(1, min(5000, $limit)),
            $params
        );
    }

    /**
     * Đối chiếu: so sánh 3 nguồn — số dư (ton_kho), tổng sổ phát sinh, và tổng dòng chứng từ đã xác nhận.
     * @return array{rows:list<array>,mismatches:int,checked:int,voucher_mismatches:list<array>}
     */
    public function reconcile(array $warehouseIds): array
    {
        if ($warehouseIds === []) {
            return ['rows' => [], 'mismatches' => 0, 'checked' => 0, 'voucher_mismatches' => []];
        }
        $in = $this->in($warehouseIds);
        $rows = $this->db->all(
            "SELECT k.warehouse_id, w.code AS wh_code, k.item_id, i.sku, k.batch_id, b.batch_no,
                    COALESCE(sb.quantity, 0) AS balance, COALESCE(sm.q, 0) AS ledger
             FROM (SELECT warehouse_id, item_id, batch_id FROM ton_kho WHERE warehouse_id IN ($in)
                   UNION SELECT warehouse_id, item_id, batch_id FROM so_phat_sinh WHERE warehouse_id IN ($in)) k
             LEFT JOIN ton_kho sb ON sb.warehouse_id = k.warehouse_id AND sb.item_id = k.item_id AND sb.batch_id = k.batch_id
             LEFT JOIN (SELECT warehouse_id, item_id, batch_id, SUM(qty_change) AS q FROM so_phat_sinh GROUP BY warehouse_id, item_id, batch_id) sm
                    ON sm.warehouse_id = k.warehouse_id AND sm.item_id = k.item_id AND sm.batch_id = k.batch_id
             JOIN kho w ON w.id = k.warehouse_id JOIN mat_hang i ON i.id = k.item_id JOIN lo_hang b ON b.id = k.batch_id
             ORDER BY w.code, i.sku, b.batch_no",
            [...$warehouseIds, ...$warehouseIds]
        );
        $mismatch = array_values(array_filter($rows, fn($r) => (int)$r['balance'] !== (int)$r['ledger']));
        // Mỗi dòng chứng từ đã xác nhận phải có đúng một dòng sổ với biến động tương ứng.
        $voucherMismatch = $this->db->all(
            "SELECT v.code, l.id AS line_id, l.qty, m.qty_change FROM chung_tu v JOIN chi_tiet_chung_tu l ON l.voucher_id = v.id
             LEFT JOIN so_phat_sinh m ON m.voucher_line_id = l.id
             WHERE v.status = 'CONFIRMED' AND v.warehouse_id IN ($in)
               AND (m.id IS NULL OR m.qty_change <> CASE v.type WHEN 'RECEIPT' THEN l.qty WHEN 'TRANSFER_IN' THEN l.qty WHEN 'ADJUSTMENT' THEN l.qty ELSE -l.qty END)
             LIMIT 100",
            $warehouseIds
        );
        return ['rows' => $rows, 'mismatches' => count($mismatch) + count($voucherMismatch), 'checked' => count($rows), 'voucher_mismatches' => $voucherMismatch];
    }

    public function dashboard(User $user): array
    {
        $ids = $this->auth->visibleWarehouseIds($user);
        if ($ids === []) {
            return ['warehouses' => [], 'units' => 0, 'drafts' => 0, 'confirmed_today' => 0, 'open_counts' => 0, 'low' => [], 'recent' => [], 'pending' => []];
        }
        $in = $this->in($ids);
        $perWh = $this->db->all(
            "SELECT w.id, w.code, w.name, w.address, COALESCE(s.units,0) AS units, COALESCE(s.skus,0) AS skus,
                    (SELECT COUNT(*) FROM chung_tu v WHERE v.warehouse_id = w.id AND v.status = 'DRAFT') AS drafts
             FROM kho w LEFT JOIN (SELECT warehouse_id, SUM(qty_change) AS units, COUNT(DISTINCT item_id) AS skus FROM so_phat_sinh GROUP BY warehouse_id) s ON s.warehouse_id = w.id
             WHERE w.id IN ($in) ORDER BY w.code", $ids);
        $low = $this->db->all(
            "SELECT i.sku, i.name, i.reorder_level, u.code AS unit, SUM(m.qty_change) AS qty FROM mat_hang i JOIN don_vi_tinh u ON u.id = i.unit_id
             LEFT JOIN so_phat_sinh m ON m.item_id = i.id AND m.warehouse_id IN ($in)
             WHERE i.active AND i.reorder_level > 0 GROUP BY i.id, i.sku, i.name, i.reorder_level, u.code
             HAVING COALESCE(SUM(m.qty_change),0) <= i.reorder_level ORDER BY COALESCE(SUM(m.qty_change),0) - i.reorder_level LIMIT 6", $ids);
        $pending = $this->db->all(
            "SELECT v.id, v.code, v.type, v.created_at, w.code AS wh_code, cu.full_name AS creator_name,
                    (SELECT COUNT(*) FROM chi_tiet_chung_tu l WHERE l.voucher_id = v.id) AS line_count
             FROM chung_tu v JOIN kho w ON w.id = v.warehouse_id JOIN nguoi_dung cu ON cu.id = v.created_by
             WHERE v.status = 'DRAFT' AND v.warehouse_id IN ($in) ORDER BY v.id DESC LIMIT 6", $ids);
        return [
            'warehouses' => $perWh,
            'units' => array_sum(array_column($perWh, 'units')),
            'drafts' => array_sum(array_column($perWh, 'drafts')),
            'confirmed_today' => (int)$this->db->value("SELECT COUNT(*) FROM chung_tu WHERE status = 'CONFIRMED' AND warehouse_id IN ($in) AND confirmed_at >= CURRENT_DATE", $ids),
            'open_counts' => (int)$this->db->value("SELECT COUNT(*) FROM dot_kiem_ke WHERE status IN ('OPEN','REVIEW') AND warehouse_id IN ($in)", $ids),
            'low' => $low,
            'pending' => $pending,
            'recent' => $this->history($ids, [], 8),
        ];
    }
}
