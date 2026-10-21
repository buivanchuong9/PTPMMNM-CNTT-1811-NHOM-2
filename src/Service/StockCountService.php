<?php
declare(strict_types=1);

namespace StockFlow\Service;

use StockFlow\Core\Db;
use StockFlow\Domain\DomainError;
use StockFlow\Domain\NotFound;
use StockFlow\Domain\Permission;
use StockFlow\Domain\StockRules;
use StockFlow\Domain\User;
use StockFlow\Domain\VoucherType;

/**
 * Kiểm kê: OPEN (đếm) → REVIEW (đã nhập đủ + lý do chênh lệch, chờ duyệt) → CLOSED (đã lập phiếu nhập xuất điều chỉnh).
 * Điều chỉnh = thực đếm − sổ sách lúc mở đợt, CỘNG vào tồn hiện tại ⇒ giữ nguyên các giao dịch phát sinh trong lúc đếm.
 */
final class StockCountService
{
    public function __construct(private Db $db, private VoucherRepository $vouchers, private Ledger $ledger,
                                private Authorizer $auth, private AuditLog $audit) {}

    public function open(User $user, int $warehouseId, string $note): int
    {
        $this->auth->require($user, $warehouseId, Permission::KiemKeThucHien);
        return $this->db->transaction(function () use ($user, $warehouseId, $note) {
            if (!$this->db->value('SELECT 1 FROM kho WHERE id = ? AND hoat_dong FOR UPDATE', [$warehouseId])) {
                throw new DomainError('Kho không tồn tại hoặc đã ngừng hoạt động');
            }
            if ($this->db->value("SELECT 1 FROM dot_kiem_ke WHERE kho_id = ? AND trang_thai IN ('OPEN','REVIEW')", [$warehouseId])) {
                throw new DomainError('Kho này đang có một đợt kiểm kê chưa hoàn tất');
            }
            $seq = $this->db->nextSequence('count');
            $id = $this->db->insert('INSERT INTO dot_kiem_ke (ma, kho_id, ghi_chu, nguoi_lap_id) VALUES (?,?,?,?)',
                [sprintf('KK-%s-%05d', gmdate('ymd'), $seq), $warehouseId, mb_substr(trim($note), 0, 255), $user->id]);
            $this->db->exec(
                'INSERT INTO chi_tiet_kiem_ke (dot_kiem_ke_id, mat_hang_id, lo_hang_id, so_luong_so_sach)
                 SELECT ?, b.mat_hang_id, b.id, COALESCE(m.q, 0) FROM lo_hang b JOIN mat_hang i ON i.id = b.mat_hang_id AND i.hoat_dong
                 LEFT JOIN (SELECT mat_hang_id, lo_hang_id, SUM(bien_dong) AS q FROM so_phat_sinh WHERE kho_id = ? GROUP BY mat_hang_id, lo_hang_id) m
                        ON m.mat_hang_id = b.mat_hang_id AND m.lo_hang_id = b.id',
                [$id, $warehouseId]
            );
            $this->audit->record($user->id, 'count.open', 'count', $id, '');
            return $id;
        });
    }

    public function find(int $id): array
    {
        $c = $this->db->one('SELECT c.*, w.ma AS wh_code, w.ten AS wh_name, cu.ho_ten AS creator_name, au.ho_ten AS approver_name,
                                    v.ma AS adj_code
                             FROM dot_kiem_ke c JOIN kho w ON w.id = c.kho_id JOIN nguoi_dung cu ON cu.id = c.nguoi_lap_id
                             LEFT JOIN nguoi_dung au ON au.id = c.nguoi_duyet_id LEFT JOIN phieu_nhap_xuat v ON v.id = c.phieu_nhap_xuat_dieu_chinh_id
                             WHERE c.id = ?', [$id]) ?? throw new NotFound('Không tìm thấy đợt kiểm kê');
        $c['lines'] = $this->db->all(
            'SELECT l.*, i.ma_hang, i.ten, u.ma AS unit, b.so_lo, b.han_dung FROM chi_tiet_kiem_ke l
             JOIN mat_hang i ON i.id = l.mat_hang_id JOIN don_vi_tinh u ON u.id = i.don_vi_tinh_id JOIN lo_hang b ON b.id = l.lo_hang_id
             WHERE l.dot_kiem_ke_id = ? ORDER BY i.ma_hang, b.so_lo', [$id]);
        return $c;
    }

    public function list(array $warehouseIds): array
    {
        if ($warehouseIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($warehouseIds), '?'));
        return $this->db->all(
            "SELECT c.*, w.ma AS wh_code, w.ten AS wh_name,
                    (SELECT COUNT(*) FROM chi_tiet_kiem_ke l WHERE l.dot_kiem_ke_id = c.id) AS line_total,
                    (SELECT COUNT(*) FROM chi_tiet_kiem_ke l WHERE l.dot_kiem_ke_id = c.id AND l.so_luong_thuc_dem IS NOT NULL) AS counted,
                    (SELECT COUNT(*) FROM chi_tiet_kiem_ke l WHERE l.dot_kiem_ke_id = c.id AND l.so_luong_thuc_dem IS NOT NULL AND l.so_luong_thuc_dem <> l.so_luong_so_sach) AS variances
             FROM dot_kiem_ke c JOIN kho w ON w.id = c.kho_id WHERE c.kho_id IN ($in) ORDER BY c.id DESC LIMIT 200",
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
        $this->auth->require($user, (int)$peek['kho_id'], Permission::KiemKeThucHien);
        $this->db->transaction(function () use ($id, $counted, $reasons) {
            $c = $this->db->one('SELECT trang_thai FROM dot_kiem_ke WHERE id = ? FOR UPDATE', [$id]);
            if (!$c || $c['trang_thai'] !== 'OPEN') {
                throw new DomainError('Đợt kiểm kê không còn ở trạng thái đang đếm');
            }
            foreach ($counted as $key => $qty) {
                if (!preg_match('/^(\d+):(\d+)$/', (string)$key, $m)) {
                    throw new DomainError('Dòng kiểm kê không hợp lệ');
                }
                if ($qty !== null && (!is_int($qty) || $qty < 0)) {
                    throw new DomainError('Số đếm phải là số nguyên không âm');
                }
                $this->db->exec('UPDATE chi_tiet_kiem_ke SET so_luong_thuc_dem = ?, ly_do = ? WHERE dot_kiem_ke_id = ? AND mat_hang_id = ? AND lo_hang_id = ?',
                    [$qty, mb_substr(trim($reasons[$key] ?? ''), 0, 255), $id, (int)$m[1], (int)$m[2]]);
            }
        });
    }

    /** Nộp đợt kiểm: phải đếm đủ mọi dòng, và mọi dòng chênh lệch phải có lý do. */
    public function submit(User $user, int $id, array $counted, array $reasons): void
    {
        $this->save($user, $id, $counted, $reasons);
        $this->db->transaction(function () use ($user, $id) {
            $c = $this->db->one('SELECT trang_thai FROM dot_kiem_ke WHERE id = ? FOR UPDATE', [$id]);
            if (!$c || $c['trang_thai'] !== 'OPEN') {
                throw new DomainError('Đợt kiểm kê không còn ở trạng thái đang đếm');
            }
            foreach ($this->db->all('SELECT l.*, i.ma_hang FROM chi_tiet_kiem_ke l JOIN mat_hang i ON i.id = l.mat_hang_id WHERE l.dot_kiem_ke_id = ?', [$id]) as $l) {
                if ($l['so_luong_thuc_dem'] === null) {
                    throw new DomainError("Còn dòng chưa đếm: {$l['ma_hang']} — nhập đủ số liệu trước khi nộp");
                }
                if ((int)$l['so_luong_thuc_dem'] !== (int)$l['so_luong_so_sach'] && trim($l['ly_do']) === '') {
                    throw new DomainError("Dòng {$l['ma_hang']} có chênh lệch: cần ghi lý do");
                }
            }
            $this->db->exec("UPDATE dot_kiem_ke SET trang_thai = 'REVIEW', ngay_nop = CURRENT_TIMESTAMP WHERE id = ?", [$id]);
            $this->audit->record($user->id, 'count.submit', 'count', $id, '');
        });
    }

    /** Người duyệt trả đợt kiểm về trạng thái đang đếm để đếm lại. */
    public function reject(User $user, int $id): void
    {
        $peek = $this->find($id);
        $this->auth->require($user, (int)$peek['kho_id'], Permission::KiemKeDuyet);
        $n = $this->db->exec("UPDATE dot_kiem_ke SET trang_thai = 'OPEN', ngay_nop = NULL WHERE id = ? AND trang_thai = 'REVIEW'", [$id]);
        if ($n !== 1) {
            throw new DomainError('Chỉ đợt kiểm đang chờ duyệt mới được trả lại');
        }
        $this->audit->record($user->id, 'count.reject', 'count', $id, '');
    }

    /**
     * Xác nhận chênh lệch ⇒ lập PHIẾU NHẬP XUẤT ĐIỀU CHỈNH (có lý do, đã ghi sổ) và đóng đợt kiểm. Idempotent.
     * @return array{already:bool,voucher:?array,adjusted:int,net:int}
     */
    public function approve(User $user, int $id): array
    {
        $peek = $this->find($id);
        $this->auth->require($user, (int)$peek['kho_id'], Permission::KiemKeDuyet);
        $out = $this->db->transaction(function () use ($user, $id) {
            $c = $this->db->one('SELECT * FROM dot_kiem_ke WHERE id = ? FOR UPDATE', [$id]);
            if ($c['trang_thai'] === 'CLOSED') {
                return ['already' => true, 'phieu_nhap_xuat_id' => $c['phieu_nhap_xuat_dieu_chinh_id'], 'adjusted' => 0, 'net' => 0];
            }
            if ($c['trang_thai'] !== 'REVIEW') {
                throw new DomainError('Chỉ đợt kiểm đã nộp (chờ duyệt) mới được xác nhận');
            }
            $lines = [];
            $net = 0;
            $reasons = [];
            foreach ($this->db->all('SELECT l.*, i.ma_hang FROM chi_tiet_kiem_ke l JOIN mat_hang i ON i.id = l.mat_hang_id WHERE l.dot_kiem_ke_id = ? ORDER BY l.mat_hang_id, l.lo_hang_id', [$id]) as $l) {
                $delta = StockRules::variance((int)$l['so_luong_so_sach'], $l['so_luong_thuc_dem'] === null ? null : (int)$l['so_luong_thuc_dem']);
                if ($delta === null) {
                    throw new DomainError('Còn dòng chưa đếm');
                }
                if ($delta !== 0) {
                    $lines[] = ['mat_hang_id' => (int)$l['mat_hang_id'], 'lo_hang_id' => (int)$l['lo_hang_id'], 'so_luong' => $delta, 'ghi_chu' => $l['ly_do']];
                    $net += $delta;
                    $reasons[$l['ly_do']] = true;
                }
            }
            $voucherId = null;
            if ($lines !== []) {
                $voucherId = $this->vouchers->insert([
                    'loai' => VoucherType::Adjustment->value, 'trang_thai' => 'CONFIRMED', 'kho_id' => (int)$c['kho_id'],
                    'ly_do' => 'Kiểm kê ' . $c['ma'] . ': ' . implode('; ', array_slice(array_keys($reasons), 0, 3)),
                    'nguoi_lap_id' => $user->id,
                ], $lines);
                $adj = $this->vouchers->lock($voucherId);
                $ops = $this->vouchers->ops($adj, $this->vouchers->lines($voucherId));
                $this->ledger->lock($this->vouchers->lockKeys($ops));
                $this->ledger->post($ops);
            }
            $this->db->exec("UPDATE dot_kiem_ke SET trang_thai = 'CLOSED', nguoi_duyet_id = ?, phieu_nhap_xuat_dieu_chinh_id = ?, ngay_dong = CURRENT_TIMESTAMP WHERE id = ?",
                [$user->id, $voucherId, $id]);
            $this->audit->record($user->id, 'count.approve', 'count', $id, count($lines) . ' dòng chênh lệch');
            return ['already' => false, 'phieu_nhap_xuat_id' => $voucherId, 'adjusted' => count($lines), 'net' => $net];
        });
        return ['already' => $out['already'], 'voucher' => $out['phieu_nhap_xuat_id'] ? $this->vouchers->find((int)$out['phieu_nhap_xuat_id']) : null,
                'adjusted' => $out['adjusted'], 'net' => $out['net']];
    }

    public function cancel(User $user, int $id): void
    {
        $peek = $this->find($id);
        if (!$this->auth->can($user, (int)$peek['kho_id'], Permission::KiemKeDuyet)) {
            $this->auth->require($user, (int)$peek['kho_id'], Permission::KiemKeThucHien);
        }
        $n = $this->db->exec("UPDATE dot_kiem_ke SET trang_thai = 'CANCELLED' WHERE id = ? AND trang_thai IN ('OPEN','REVIEW')", [$id]);
        if ($n !== 1) {
            throw new DomainError('Không thể huỷ đợt kiểm kê này');
        }
        $this->audit->record($user->id, 'count.cancel', 'count', $id, '');
    }
}
