<?php
declare(strict_types=1);

namespace StockFlow\Service;

use StockFlow\Core\Db;
use StockFlow\Domain\DomainError;
use StockFlow\Domain\NotFound;
use StockFlow\Domain\Role;
use StockFlow\Domain\StockRules;
use StockFlow\Domain\User;
use StockFlow\Domain\VoucherType;

/**
 * Kiểm kê: OPEN (đếm) → REVIEW (đã nhập đủ + lý do chênh lệch, chờ duyệt) → CLOSED (đã lập chứng từ điều chỉnh).
 * Điều chỉnh = thực đếm − sổ sách lúc mở đợt, CỘNG vào tồn hiện tại ⇒ giữ nguyên các giao dịch phát sinh trong lúc đếm.
 */
final class StockCountService
{
    public function __construct(private Db $db, private VoucherRepository $vouchers, private Ledger $ledger,
                                private Authorizer $auth, private AuditLog $audit) {}

    public function open(User $user, int $warehouseId, string $note): int
    {
        $this->auth->require($user, $warehouseId, Role::Counter);
        return $this->db->transaction(function () use ($user, $warehouseId, $note) {
            if (!$this->db->value('SELECT 1 FROM kho WHERE id = ? AND active FOR UPDATE', [$warehouseId])) {
                throw new DomainError('Kho không tồn tại hoặc đã ngừng hoạt động');
            }
            if ($this->db->value("SELECT 1 FROM dot_kiem_ke WHERE warehouse_id = ? AND status IN ('OPEN','REVIEW')", [$warehouseId])) {
                throw new DomainError('Kho này đang có một đợt kiểm kê chưa hoàn tất');
            }
            $seq = $this->db->nextSequence('count');
            $id = $this->db->insert('INSERT INTO dot_kiem_ke (code, warehouse_id, note, created_by) VALUES (?,?,?,?)',
                [sprintf('KK-%s-%05d', gmdate('ymd'), $seq), $warehouseId, mb_substr(trim($note), 0, 255), $user->id]);
            $this->db->exec(
                'INSERT INTO chi_tiet_kiem_ke (count_id, item_id, batch_id, system_qty)
                 SELECT ?, b.item_id, b.id, COALESCE(m.q, 0) FROM lo_hang b JOIN mat_hang i ON i.id = b.item_id AND i.active
                 LEFT JOIN (SELECT item_id, batch_id, SUM(qty_change) AS q FROM so_phat_sinh WHERE warehouse_id = ? GROUP BY item_id, batch_id) m
                        ON m.item_id = b.item_id AND m.batch_id = b.id',
                [$id, $warehouseId]
            );
            $this->audit->record($user->id, 'count.open', 'count', $id, '');
            return $id;
        });
    }

    public function find(int $id): array
    {
        $c = $this->db->one('SELECT c.*, w.code AS wh_code, w.name AS wh_name, cu.full_name AS creator_name, au.full_name AS approver_name,
                                    v.code AS adj_code
                             FROM dot_kiem_ke c JOIN kho w ON w.id = c.warehouse_id JOIN nguoi_dung cu ON cu.id = c.created_by
                             LEFT JOIN nguoi_dung au ON au.id = c.approved_by LEFT JOIN chung_tu v ON v.id = c.adjustment_voucher_id
                             WHERE c.id = ?', [$id]) ?? throw new NotFound('Không tìm thấy đợt kiểm kê');
        $c['lines'] = $this->db->all(
            'SELECT l.*, i.sku, i.name, u.code AS unit, b.batch_no, b.expiry_date FROM chi_tiet_kiem_ke l
             JOIN mat_hang i ON i.id = l.item_id JOIN don_vi_tinh u ON u.id = i.unit_id JOIN lo_hang b ON b.id = l.batch_id
             WHERE l.count_id = ? ORDER BY i.sku, b.batch_no', [$id]);
        return $c;
    }

    public function list(array $warehouseIds): array
    {
        if ($warehouseIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($warehouseIds), '?'));
        return $this->db->all(
            "SELECT c.*, w.code AS wh_code, w.name AS wh_name,
                    (SELECT COUNT(*) FROM chi_tiet_kiem_ke l WHERE l.count_id = c.id) AS line_total,
                    (SELECT COUNT(*) FROM chi_tiet_kiem_ke l WHERE l.count_id = c.id AND l.counted_qty IS NOT NULL) AS counted,
                    (SELECT COUNT(*) FROM chi_tiet_kiem_ke l WHERE l.count_id = c.id AND l.counted_qty IS NOT NULL AND l.counted_qty <> l.system_qty) AS variances
             FROM dot_kiem_ke c JOIN kho w ON w.id = c.warehouse_id WHERE c.warehouse_id IN ($in) ORDER BY c.id DESC LIMIT 200",
            $warehouseIds
        );
    }

    /**
     * Lưu số đếm (và lý do chênh lệch). Khoá "item:batch" ⇒ số đếm ('' = chưa đếm).
     * @param array<string,?int> $counted @param array<string,string> $reasons
     */
    public function save(User $user, int $id, array $counted, array $reasons = []): void
    {
        $peek = $this->find($id);
        $this->auth->require($user, (int)$peek['warehouse_id'], Role::Counter);
        $this->db->transaction(function () use ($id, $counted, $reasons) {
            $c = $this->db->one('SELECT status FROM dot_kiem_ke WHERE id = ? FOR UPDATE', [$id]);
            if (!$c || $c['status'] !== 'OPEN') {
                throw new DomainError('Đợt kiểm kê không còn ở trạng thái đang đếm');
            }
            foreach ($counted as $key => $qty) {
                if (!preg_match('/^(\d+):(\d+)$/', (string)$key, $m)) {
                    throw new DomainError('Dòng kiểm kê không hợp lệ');
                }
                if ($qty !== null && (!is_int($qty) || $qty < 0)) {
                    throw new DomainError('Số đếm phải là số nguyên không âm');
                }
                $this->db->exec('UPDATE chi_tiet_kiem_ke SET counted_qty = ?, reason = ? WHERE count_id = ? AND item_id = ? AND batch_id = ?',
                    [$qty, mb_substr(trim($reasons[$key] ?? ''), 0, 255), $id, (int)$m[1], (int)$m[2]]);
            }
        });
    }

    /** Nộp đợt kiểm: phải đếm đủ mọi dòng, và mọi dòng chênh lệch phải có lý do. */
    public function submit(User $user, int $id, array $counted, array $reasons): void
    {
        $this->save($user, $id, $counted, $reasons);
        $this->db->transaction(function () use ($user, $id) {
            $c = $this->db->one('SELECT status FROM dot_kiem_ke WHERE id = ? FOR UPDATE', [$id]);
            if (!$c || $c['status'] !== 'OPEN') {
                throw new DomainError('Đợt kiểm kê không còn ở trạng thái đang đếm');
            }
            foreach ($this->db->all('SELECT l.*, i.sku FROM chi_tiet_kiem_ke l JOIN mat_hang i ON i.id = l.item_id WHERE l.count_id = ?', [$id]) as $l) {
                if ($l['counted_qty'] === null) {
                    throw new DomainError("Còn dòng chưa đếm: {$l['sku']} — nhập đủ số liệu trước khi nộp");
                }
                if ((int)$l['counted_qty'] !== (int)$l['system_qty'] && trim($l['reason']) === '') {
                    throw new DomainError("Dòng {$l['sku']} có chênh lệch: cần ghi lý do");
                }
            }
            $this->db->exec("UPDATE dot_kiem_ke SET status = 'REVIEW', submitted_at = CURRENT_TIMESTAMP WHERE id = ?", [$id]);
            $this->audit->record($user->id, 'count.submit', 'count', $id, '');
        });
    }

    /** Người duyệt trả đợt kiểm về trạng thái đang đếm để đếm lại. */
    public function reject(User $user, int $id): void
    {
        $peek = $this->find($id);
        $this->auth->require($user, (int)$peek['warehouse_id'], Role::Approver);
        $n = $this->db->exec("UPDATE dot_kiem_ke SET status = 'OPEN', submitted_at = NULL WHERE id = ? AND status = 'REVIEW'", [$id]);
        if ($n !== 1) {
            throw new DomainError('Chỉ đợt kiểm đang chờ duyệt mới được trả lại');
        }
        $this->audit->record($user->id, 'count.reject', 'count', $id, '');
    }

    /**
     * Xác nhận chênh lệch ⇒ lập CHỨNG TỪ ĐIỀU CHỈNH (có lý do, đã ghi sổ) và đóng đợt kiểm. Idempotent.
     * @return array{already:bool,voucher:?array,adjusted:int,net:int}
     */
    public function approve(User $user, int $id): array
    {
        $peek = $this->find($id);
        $this->auth->require($user, (int)$peek['warehouse_id'], Role::Approver);
        $out = $this->db->transaction(function () use ($user, $id) {
            $c = $this->db->one('SELECT * FROM dot_kiem_ke WHERE id = ? FOR UPDATE', [$id]);
            if ($c['status'] === 'CLOSED') {
                return ['already' => true, 'voucher_id' => $c['adjustment_voucher_id'], 'adjusted' => 0, 'net' => 0];
            }
            if ($c['status'] !== 'REVIEW') {
                throw new DomainError('Chỉ đợt kiểm đã nộp (chờ duyệt) mới được xác nhận');
            }
            $lines = [];
            $net = 0;
            $reasons = [];
            foreach ($this->db->all('SELECT l.*, i.sku FROM chi_tiet_kiem_ke l JOIN mat_hang i ON i.id = l.item_id WHERE l.count_id = ? ORDER BY l.item_id, l.batch_id', [$id]) as $l) {
                $delta = StockRules::variance((int)$l['system_qty'], $l['counted_qty'] === null ? null : (int)$l['counted_qty']);
                if ($delta === null) {
                    throw new DomainError('Còn dòng chưa đếm');
                }
                if ($delta !== 0) {
                    $lines[] = ['item_id' => (int)$l['item_id'], 'batch_id' => (int)$l['batch_id'], 'qty' => $delta, 'note' => $l['reason']];
                    $net += $delta;
                    $reasons[$l['reason']] = true;
                }
            }
            $voucherId = null;
            if ($lines !== []) {
                $voucherId = $this->vouchers->insert([
                    'type' => VoucherType::Adjustment->value, 'status' => 'CONFIRMED', 'warehouse_id' => (int)$c['warehouse_id'],
                    'reason' => 'Kiểm kê ' . $c['code'] . ': ' . implode('; ', array_slice(array_keys($reasons), 0, 3)),
                    'created_by' => $user->id,
                ], $lines);
                $adj = $this->vouchers->lock($voucherId);
                $ops = $this->vouchers->ops($adj, $this->vouchers->lines($voucherId));
                $this->ledger->lock($this->vouchers->lockKeys($ops));
                $this->ledger->post($ops);
            }
            $this->db->exec("UPDATE dot_kiem_ke SET status = 'CLOSED', approved_by = ?, adjustment_voucher_id = ?, closed_at = CURRENT_TIMESTAMP WHERE id = ?",
                [$user->id, $voucherId, $id]);
            $this->audit->record($user->id, 'count.approve', 'count', $id, count($lines) . ' dòng chênh lệch');
            return ['already' => false, 'voucher_id' => $voucherId, 'adjusted' => count($lines), 'net' => $net];
        });
        return ['already' => $out['already'], 'voucher' => $out['voucher_id'] ? $this->vouchers->find((int)$out['voucher_id']) : null,
                'adjusted' => $out['adjusted'], 'net' => $out['net']];
    }

    public function cancel(User $user, int $id): void
    {
        $peek = $this->find($id);
        if (!$this->auth->can($user, (int)$peek['warehouse_id'], Role::Approver)) {
            $this->auth->require($user, (int)$peek['warehouse_id'], Role::Counter);
        }
        $n = $this->db->exec("UPDATE dot_kiem_ke SET status = 'CANCELLED' WHERE id = ? AND status IN ('OPEN','REVIEW')", [$id]);
        if ($n !== 1) {
            throw new DomainError('Không thể huỷ đợt kiểm kê này');
        }
        $this->audit->record($user->id, 'count.cancel', 'count', $id, '');
    }
}
