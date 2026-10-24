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
 * Kiểm kê: OPEN (đếm) → REVIEW (đã nộp, mọi dòng lệch đều có lý do, chờ duyệt) → CLOSED (đã lập phiếu điều chỉnh).
 * Dòng để trống = không phát hiện chênh lệch (coi như khớp sổ sách): chỉ cần nhập và giải trình những dòng có thay đổi.
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

    /** Quá ngần này ngày chưa chốt đợt kiểm kê nào thì kho bị cảnh báo «quá hạn kiểm kê». */
    public const DUE_DAYS = 30;

    /**
     * @param ?string $from / $to ngày YYYY-MM-DD theo giờ Việt Nam (UTC+7), lọc theo ngày mở đợt
     * @param bool $withCancelled false ⇒ bỏ các đợt đã huỷ (trừ khi đang lọc đúng trạng thái CANCELLED)
     * @return array<int,array> các đợt kiểm kê (mới nhất trước), tuỳ chọn lọc theo trạng thái.
     */
    public function list(array $warehouseIds, ?string $status = null, ?string $from = null, ?string $to = null, bool $withCancelled = true): array
    {
        if ($warehouseIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($warehouseIds), '?'));
        $args = $warehouseIds;
        $where = "c.kho_id IN ($in)";
        if ($status !== null && $status !== '') {
            $where .= ' AND c.trang_thai = ?';
            $args[] = $status;
        } elseif (!$withCancelled) {
            $where .= " AND c.trang_thai <> 'CANCELLED'";
        }
        if ($from) {
            $where .= ' AND c.ngay_tao >= ?';
            $args[] = gmdate('Y-m-d H:i:s', strtotime("$from 00:00:00 UTC") - 7 * 3600);
        }
        if ($to) {
            $where .= ' AND c.ngay_tao < ?';
            $args[] = gmdate('Y-m-d H:i:s', strtotime("$to 00:00:00 UTC") + 17 * 3600);
        }
        return $this->db->all(
            "SELECT c.*, w.ma AS wh_code, w.ten AS wh_name, cu.ho_ten AS creator_name, au.ho_ten AS approver_name,
                    (SELECT m.ho_ten FROM nguoi_dung m WHERE m.quan_ly_kho_id = c.kho_id AND m.hoat_dong LIMIT 1) AS manager_name,
                    (SELECT u.ho_ten FROM nhat_ky a JOIN nguoi_dung u ON u.id = a.nguoi_dung_id WHERE a.doi_tuong = 'count' AND a.doi_tuong_id = c.id AND a.hanh_dong = 'count.cancel' ORDER BY a.id DESC LIMIT 1) AS cancel_by,
                    (SELECT a.ngay_tao FROM nhat_ky a WHERE a.doi_tuong = 'count' AND a.doi_tuong_id = c.id AND a.hanh_dong = 'count.cancel' ORDER BY a.id DESC LIMIT 1) AS cancel_at,
                    (SELECT a.chi_tiet FROM nhat_ky a WHERE a.doi_tuong = 'count' AND a.doi_tuong_id = c.id AND a.hanh_dong = 'count.cancel' ORDER BY a.id DESC LIMIT 1) AS cancel_reason,
                    (SELECT COUNT(*) FROM chi_tiet_kiem_ke l WHERE l.dot_kiem_ke_id = c.id) AS line_total,
                    (SELECT COUNT(*) FROM chi_tiet_kiem_ke l WHERE l.dot_kiem_ke_id = c.id AND l.so_luong_thuc_dem IS NOT NULL) AS counted,
                    (SELECT COUNT(*) FROM chi_tiet_kiem_ke l WHERE l.dot_kiem_ke_id = c.id AND l.so_luong_thuc_dem IS NOT NULL AND l.so_luong_thuc_dem <> l.so_luong_so_sach) AS variances,
                    (SELECT COALESCE(SUM(l.so_luong_thuc_dem - l.so_luong_so_sach), 0) FROM chi_tiet_kiem_ke l WHERE l.dot_kiem_ke_id = c.id AND l.so_luong_thuc_dem IS NOT NULL) AS net_variance
             FROM dot_kiem_ke c JOIN kho w ON w.id = c.kho_id JOIN nguoi_dung cu ON cu.id = c.nguoi_lap_id LEFT JOIN nguoi_dung au ON au.id = c.nguoi_duyet_id
             WHERE $where ORDER BY c.id DESC LIMIT 500",
            $args
        );
    }

    /** Số đợt theo trạng thái trong các kho cho phép (làm số đếm trên tab). @return array<string,int> */
    public function statusCounts(array $warehouseIds): array
    {
        if ($warehouseIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($warehouseIds), '?'));
        $out = [];
        foreach ($this->db->all("SELECT trang_thai, COUNT(*) AS n FROM dot_kiem_ke WHERE kho_id IN ($in) GROUP BY trang_thai", $warehouseIds) as $r) {
            $out[$r['trang_thai']] = (int)$r['n'];
        }
        return $out;
    }

    /** Tổng hợp lịch sử kiểm kê theo kho (cho quản trị cấp cao). @return array<int,array> */
    public function summaryByWarehouse(array $warehouseIds): array
    {
        if ($warehouseIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($warehouseIds), '?'));
        $rows = $this->db->all(
            "SELECT w.id, w.ma, w.ten, COUNT(CASE WHEN c.trang_thai <> 'CANCELLED' THEN c.id END) AS total,
                    COALESCE(SUM(c.trang_thai = 'OPEN'), 0) AS open_n, COALESCE(SUM(c.trang_thai = 'REVIEW'), 0) AS review_n,
                    COALESCE(SUM(c.trang_thai = 'CLOSED'), 0) AS closed_n, MAX(c.ngay_dong) AS last_closed
             FROM kho w LEFT JOIN dot_kiem_ke c ON c.kho_id = w.id WHERE w.id IN ($in) GROUP BY w.id, w.ma, w.ten ORDER BY w.ma",
            $warehouseIds
        );
        foreach ($rows as &$r) {
            $r['days_since'] = $r['last_closed'] ? (int)floor((time() - strtotime($r['last_closed'] . ' UTC')) / 86400) : null;
            // quá hạn = chưa chốt lần nào hoặc đã lâu, và hiện không có đợt nào đang đếm / chờ duyệt
            $r['due'] = !(int)$r['open_n'] && !(int)$r['review_n'] && ($r['days_since'] === null || $r['days_since'] > self::DUE_DAYS);
        }
        return $rows;
    }

    /**
     * Lý do chênh lệch theo kho (các đợt chờ duyệt và đã đóng, không tính đợt huỷ): nhóm theo loại lý do (phần trước «: »).
     * @return array<string,array<int,array{n:int,net:int}>> lý do ⇒ [kho_id ⇒ số dòng lệch + lệch ròng]
     */
    public function varianceReasons(array $warehouseIds, ?string $from = null, ?string $to = null): array
    {
        if ($warehouseIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($warehouseIds), '?'));
        $args = $warehouseIds;
        $when = '';
        if ($from) {
            $when .= ' AND c.ngay_tao >= ?';
            $args[] = gmdate('Y-m-d H:i:s', strtotime("$from 00:00:00 UTC") - 7 * 3600);
        }
        if ($to) {
            $when .= ' AND c.ngay_tao < ?';
            $args[] = gmdate('Y-m-d H:i:s', strtotime("$to 00:00:00 UTC") + 17 * 3600);
        }
        $out = [];
        foreach ($this->db->all(
            "SELECT c.kho_id, COALESCE(NULLIF(SUBSTRING_INDEX(l.ly_do, ': ', 1), ''), 'Chưa ghi lý do') AS reason, COUNT(*) AS n,
                    SUM(l.so_luong_thuc_dem - l.so_luong_so_sach) AS net
             FROM chi_tiet_kiem_ke l JOIN dot_kiem_ke c ON c.id = l.dot_kiem_ke_id
             WHERE c.kho_id IN ($in) AND c.trang_thai IN ('REVIEW','CLOSED') AND l.so_luong_thuc_dem IS NOT NULL AND l.so_luong_thuc_dem <> l.so_luong_so_sach $when
             GROUP BY c.kho_id, reason", $args) as $r) {
            $out[$r['reason']][(int)$r['kho_id']] = ['n' => (int)$r['n'], 'net' => (int)$r['net']];
        }
        uasort($out, fn($a, $b) => array_sum(array_column($b, 'n')) <=> array_sum(array_column($a, 'n')));
        return $out;
    }

    /** Ai huỷ đợt, lúc nào, vì sao (từ nhật ký thao tác); null nếu đợt chưa bị huỷ. @return ?array{by:?string,at:string,reason:string} */
    public function cancelInfo(int $id): ?array
    {
        $r = $this->db->one("SELECT u.ho_ten AS by_name, a.ngay_tao AS at, a.chi_tiet AS reason FROM nhat_ky a LEFT JOIN nguoi_dung u ON u.id = a.nguoi_dung_id
                             WHERE a.doi_tuong = 'count' AND a.doi_tuong_id = ? AND a.hanh_dong = 'count.cancel' ORDER BY a.id DESC LIMIT 1", [$id]);
        return $r ? ['by' => $r['by_name'], 'at' => $r['at'], 'reason' => (string)$r['reason']] : null;
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

    /** Nộp đợt kiểm: dòng để trống coi như khớp; mọi dòng đã nhập mà chênh lệch phải có lý do. */
    public function submit(User $user, int $id, array $counted, array $reasons): void
    {
        $this->save($user, $id, $counted, $reasons);
        $this->db->transaction(function () use ($user, $id) {
            $c = $this->db->one('SELECT trang_thai FROM dot_kiem_ke WHERE id = ? FOR UPDATE', [$id]);
            if (!$c || $c['trang_thai'] !== 'OPEN') {
                throw new DomainError('Đợt kiểm kê không còn ở trạng thái đang đếm');
            }
            foreach ($this->db->all('SELECT l.*, i.ma_hang FROM chi_tiet_kiem_ke l JOIN mat_hang i ON i.id = l.mat_hang_id WHERE l.dot_kiem_ke_id = ?', [$id]) as $l) {
                if ($l['so_luong_thuc_dem'] !== null && (int)$l['so_luong_thuc_dem'] !== (int)$l['so_luong_so_sach'] && trim($l['ly_do']) === '') {
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
                $delta = $l['so_luong_thuc_dem'] === null ? 0 : StockRules::variance((int)$l['so_luong_so_sach'], (int)$l['so_luong_thuc_dem']);   // chưa nhập = khớp sổ sách
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

    public function cancel(User $user, int $id, string $reason = ''): void
    {
        $peek = $this->find($id);
        if (!$this->auth->can($user, (int)$peek['kho_id'], Permission::KiemKeDuyet)) {
            $this->auth->require($user, (int)$peek['kho_id'], Permission::KiemKeThucHien);
        }
        $n = $this->db->exec("UPDATE dot_kiem_ke SET trang_thai = 'CANCELLED' WHERE id = ? AND trang_thai IN ('OPEN','REVIEW')", [$id]);
        if ($n !== 1) {
            throw new DomainError('Không thể huỷ đợt kiểm kê này');
        }
        $this->audit->record($user->id, 'count.cancel', 'count', $id, mb_substr(trim($reason), 0, 200));
    }
}
