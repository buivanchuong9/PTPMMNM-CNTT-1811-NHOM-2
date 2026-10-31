<?php
declare(strict_types=1);

namespace StockFlow\Service;

use StockFlow\Core\Db;
use StockFlow\Domain\DomainError;
use StockFlow\Domain\NotFound;
use StockFlow\Domain\Permission;
use StockFlow\Domain\User;
use StockFlow\Domain\VoucherType;

/**
 * Khách trả hàng. Chọn phiếu xuất bán gốc, ghi số trả và số LỖI từng dòng (không vượt số đã bán trừ số đã trả trước đó).
 *   DRAFT → CONFIRMED: quản lý kho xác nhận ⇒ hàng trả nhập lại kho bằng một phiếu điều chỉnh «Khách trả hàng …» (sổ phát sinh bất biến, không sửa phiếu bán);
 *                      phần LỖI đi theo nguyên nhân (xem CAUSES): lỗi nhà sản xuất → phiếu kho bảo hành; còn lại → đề xuất huỷ (DisposalService), người duyệt có quyền thì vào kho huỷ ngay.
 *                      Mỗi phiếu có tiền hoàn cho khách (giá đã trả − tiền trừ khi khách làm hỏng) và tiền bên chịu trách nhiệm (tài xế / nhân viên kho) phải đền.
 *   DRAFT → CANCELLED: không ảnh hưởng tồn.
 */
final class CustomerReturnService
{
    public const STATUS = ['DRAFT' => ['Chờ xác nhận', 'warn'], 'CONFIRMED' => ['Đã nhập lại kho', 'ok'], 'CANCELLED' => ['Đã huỷ', 'danger']];
    /**
     * Hàng lỗi do ai — quyết định hàng đi đâu và ai chịu tiền:
     *   KHACH      khách làm hỏng      → hàng sang kho huỷ, trừ tiền hàng hỏng vào tiền hoàn của khách
     *   VAN_CHUYEN do vận chuyển       → hoàn đủ tiền khách, hàng sang kho huỷ, tài xế đền theo giá vốn
     *   KHO        do kho (đóng gói/bảo quản) → hoàn đủ tiền khách, hàng sang kho huỷ, nhân viên kho đền theo giá vốn
     *   NHA_SX     lỗi nhà sản xuất    → hoàn đủ tiền khách, hàng KHÔNG vào tồn mà mở phiếu kho bảo hành để đòi nhà sản xuất
     *   KHAC       khác                → hoàn đủ tiền khách, hàng sang kho huỷ, công ty chịu
     */
    public const CAUSES = [
        'KHACH' => 'Khách làm hỏng', 'VAN_CHUYEN' => 'Hỏng khi vận chuyển', 'KHO' => 'Do kho (đóng gói / bảo quản)',
        'NHA_SX' => 'Lỗi nhà sản xuất', 'KHAC' => 'Khác (công ty chịu)',
    ];
    public const REFUND_METHODS = ['TIEN_MAT' => 'Tiền mặt', 'CHUYEN_KHOAN' => 'Chuyển khoản', 'TRU_CONG_NO' => 'Trừ vào công nợ'];

    public function __construct(private Db $db, private Authorizer $auth, private AuditLog $audit, private VoucherRepository $vouchers, private Ledger $ledger, private DisposalService $disposals) {}

    /** Giá khách đã trả cho 1 đơn vị, gồm thuế. */
    public static function unitPrice(?int $price, int $tax): int
    {
        return $price === null ? 0 : (int)round($price * (100 + $tax) / 100);
    }

    /** Tài xế đã giao phiếu xuất này (nếu giao bằng xe), để gợi ý người chịu khi hỏng lúc vận chuyển. */
    public function deliveryDriver(int $issueId): ?array
    {
        return $this->db->one('SELECT t.id, t.ho_ten FROM giao_hang g JOIN tai_xe t ON t.id = g.tai_xe_id WHERE g.phieu_nhap_xuat_id = ?', [$issueId]);
    }

    /** Nhân viên của kho (người có thể bị quy trách nhiệm khi lỗi do kho). */
    public function warehouseStaff(int $warehouseId): array
    {
        return $this->db->all('SELECT id, ho_ten FROM nguoi_dung WHERE kho_id = ? AND hoat_dong ORDER BY ho_ten', [$warehouseId]);
    }

    public function drivers(int $warehouseId): array
    {
        return $this->db->all('SELECT id, ho_ten FROM tai_xe WHERE hoat_dong AND (kho_id = ? OR kho_id IS NULL) ORDER BY ho_ten', [$warehouseId]);
    }

    /**
     * Các dòng của phiếu xuất kèm số đã trả (phiếu trả chưa huỷ) và số còn trả được.
     * @return list<array<string,mixed>>
     */
    public function soldLines(int $issueId): array
    {
        return $this->db->all(
            "SELECT l.mat_hang_id, l.lo_hang_id, SUM(l.so_luong) AS sold, i.ma_hang, i.ten, u.ma AS unit, b.so_lo, b.han_dung, MAX(l.don_gia) AS don_gia, MAX(l.thue_suat) AS thue_suat, MAX(i.gia_von) AS gia_von,
                    COALESCE((SELECT SUM(c.so_luong) FROM chi_tiet_tra_hang_khach c JOIN tra_hang_khach t ON t.id = c.tra_hang_id
                              WHERE t.phieu_xuat_id = l.phieu_nhap_xuat_id AND t.trang_thai <> 'CANCELLED' AND c.mat_hang_id = l.mat_hang_id AND c.lo_hang_id = l.lo_hang_id), 0) AS returned
             FROM chi_tiet_phieu_nhap_xuat l JOIN mat_hang i ON i.id = l.mat_hang_id JOIN don_vi_tinh u ON u.id = i.don_vi_tinh_id JOIN lo_hang b ON b.id = l.lo_hang_id
             WHERE l.phieu_nhap_xuat_id = ? GROUP BY l.mat_hang_id, l.lo_hang_id, l.phieu_nhap_xuat_id, i.ma_hang, i.ten, u.ma, b.so_lo, b.han_dung ORDER BY MIN(l.id)", [$issueId]);
    }

    /** Phiếu xuất bán đã ghi sổ, chưa bị đảo, để lập phiếu trả. */
    public function returnableIssue(int $issueId): array
    {
        $v = $this->db->one('SELECT v.*, w.ma AS wh_code, w.ten AS wh_name FROM phieu_nhap_xuat v JOIN kho w ON w.id = v.kho_id WHERE v.id = ?', [$issueId]) ?? throw new NotFound('Không tìm thấy phiếu xuất');
        if ($v['loai'] !== VoucherType::Issue->value || $v['trang_thai'] !== 'CONFIRMED') {
            throw new DomainError('Chỉ phiếu xuất bán đã xác nhận mới có hàng khách trả');
        }
        if ($this->db->value('SELECT 1 FROM phieu_nhap_xuat WHERE dieu_chinh_cho_id = ? LIMIT 1', [$issueId])) {
            throw new DomainError('Phiếu xuất này đã bị điều chỉnh đảo — không lập phiếu trả');
        }
        return $v;
    }

    /**
     * Lập phiếu trả (nháp).
     * ly_do_loi = nguyên nhân (khoá của CAUSES). Tuỳ chọn: tai_xe_id (lỗi vận chuyển), nguoi_chiu_id (lỗi do kho), tien_tru_khach (khách làm hỏng; bỏ trống = trừ đủ giá hàng hỏng).
     * Tiền hoàn = tiền khách đã trả cho hàng trả − tiền trừ khách.
     * @param list<array{mat_hang_id:int,lo_hang_id:int,so_luong:int,so_luong_loi:int,ly_do_loi:string,tai_xe_id?:?int,nguoi_chiu_id?:?int,tien_tru_khach?:?int}> $lines
     */
    public function create(User $user, int $issueId, array $lines, string $reason = "", string $refundMethod = 'TIEN_MAT'): int
    {
        $v = $this->returnableIssue($issueId);
        $this->auth->require($user, (int)$v['kho_id'], Permission::LapPhieuNhap);
        $reason = trim($reason);
        if (mb_strlen($reason) > 255) {
            throw new DomainError('Lý do trả tối đa 255 ký tự');
        }
        if (!isset(self::REFUND_METHODS[$refundMethod])) {
            throw new DomainError('Chọn hình thức hoàn tiền');
        }
        $lines = array_values(array_filter($lines, fn($l) => (int)$l['so_luong'] > 0));
        if ($lines === []) {
            throw new DomainError('Chưa nhập số lượng khách trả ở dòng nào');
        }
        $room = [];
        foreach ($this->soldLines($issueId) as $s) {
            $room[$s['mat_hang_id'] . ':' . $s['lo_hang_id']] = ['left' => (int)$s['sold'] - (int)$s['returned'], 'name' => $s['ma_hang'],
                'unit' => self::unitPrice($s['don_gia'] !== null ? (int)$s['don_gia'] : null, (int)($s['thue_suat'] ?? 0)), 'cost' => (int)$s['gia_von']];
        }
        $refund = 0;
        $used = [];
        foreach ($lines as $i => $l) {
            $k = (int)$l['mat_hang_id'] . ':' . (int)$l['lo_hang_id'];
            if (!isset($room[$k])) {
                throw new DomainError('Có dòng không thuộc phiếu xuất ' . $v['ma']);
            }
            $used[$k] = ($used[$k] ?? 0) + (int)$l['so_luong'];
            if ($used[$k] > $room[$k]['left']) {
                throw new DomainError($room[$k]['name'] . ': chỉ còn trả được ' . $room[$k]['left'] . ' (đã bán trừ số đã trả trước)');
            }
            if ((int)$l['so_luong_loi'] < 0 || (int)$l['so_luong_loi'] > (int)$l['so_luong']) {
                throw new DomainError($room[$k]['name'] . ': số lỗi không được lớn hơn số khách trả');
            }
            $bad = (int)$l['so_luong_loi'];
            $cause = $bad > 0 ? (string)$l['ly_do_loi'] : '';
            if ($bad > 0 && !isset(self::CAUSES[$cause])) {
                throw new DomainError($room[$k]['name'] . ': chọn nguyên nhân hàng lỗi (khách, vận chuyển, kho, nhà sản xuất…)');
            }
            $unit = $room[$k]['unit'];
            $deduct = 0;
            $compensate = 0;
            $driver = null;
            $staff = null;
            if ($cause === 'KHACH') {
                $given = $l['tien_tru_khach'] ?? null;
                $deduct = $given === null || $given === '' ? $bad * $unit : (int)$given;
                if ($deduct < 0 || $deduct > $bad * $unit) {
                    throw new DomainError($room[$k]['name'] . ': tiền trừ khách từ 0 đến ' . n($bad * $unit) . ' (giá hàng hỏng)');
                }
            } elseif ($cause === 'VAN_CHUYEN' || $cause === 'KHO') {
                $compensate = $bad * $room[$k]['cost'];
                if ($cause === 'VAN_CHUYEN') {
                    $driver = (int)($l['tai_xe_id'] ?? 0) ?: (int)($this->deliveryDriver($issueId)['id'] ?? 0) ?: null;
                    if ($driver !== null && !$this->db->value('SELECT 1 FROM tai_xe WHERE id = ?', [$driver])) {
                        throw new DomainError($room[$k]['name'] . ': tài xế không tồn tại');
                    }
                } else {
                    $staff = (int)($l['nguoi_chiu_id'] ?? 0) ?: null;
                    if ($staff !== null && !$this->db->value('SELECT 1 FROM nguoi_dung WHERE id = ? AND kho_id = ?', [$staff, (int)$v['kho_id']])) {
                        throw new DomainError($room[$k]['name'] . ': người chịu trách nhiệm phải là nhân viên của kho ' . $v['kho_id']);
                    }
                }
            }
            $lines[$i] = $l + ['_unit' => $unit, '_deduct' => $deduct, '_comp' => $compensate, '_driver' => $driver, '_staff' => $staff, '_cause' => $cause];
            $refund += (int)$l['so_luong'] * $unit - $deduct;
        }
        return $this->db->transaction(function () use ($user, $v, $lines, $reason, $refund, $refundMethod) {
            $seq = $this->db->nextSequence('customer_return');
            $id = $this->db->insert('INSERT INTO tra_hang_khach (ma, phieu_xuat_id, kho_id, khach, ly_do, nguoi_lap_id, tien_hoan, hinh_thuc_hoan) VALUES (?,?,?,?,?,?,?,?)',
                [sprintf('KT-%s-%05d', gmdate('ymd'), $seq), $v['id'], $v['kho_id'], mb_substr((string)$v['doi_tac'], 0, 120), $reason, $user->id, $refund, $refundMethod]);
            foreach ($lines as $l) {
                $this->db->exec('INSERT INTO chi_tiet_tra_hang_khach (tra_hang_id, mat_hang_id, lo_hang_id, so_luong, so_luong_loi, ly_do_loi, don_gia, tien_tru_khach, tien_den_bu, tai_xe_id, nguoi_chiu_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                    [$id, (int)$l['mat_hang_id'], (int)$l['lo_hang_id'], (int)$l['so_luong'], (int)$l['so_luong_loi'], $l['_cause'], $l['_unit'], $l['_deduct'], $l['_comp'], $l['_driver'], $l['_staff']]);
            }
            $this->audit->record($user->id, 'creturn.create', 'creturn', $id, $v['ma'] . ' · ' . count($lines) . ' dòng');
            return $id;
        });
    }

    public function find(int $id): array
    {
        $t = $this->db->one('SELECT t.*, w.ma AS wh_code, w.ten AS wh_name, w.dia_chi AS wh_addr, x.ma AS issue_code, n.ma AS receipt_code, lu.ho_ten AS creator_name, au.ho_ten AS approver_name
                             FROM tra_hang_khach t JOIN kho w ON w.id = t.kho_id JOIN phieu_nhap_xuat x ON x.id = t.phieu_xuat_id LEFT JOIN phieu_nhap_xuat n ON n.id = t.phieu_nhap_id
                             JOIN nguoi_dung lu ON lu.id = t.nguoi_lap_id LEFT JOIN nguoi_dung au ON au.id = t.nguoi_xac_nhan_id WHERE t.id = ?', [$id]) ?? throw new NotFound('Không tìm thấy phiếu khách trả hàng');
        $t['lines'] = $this->db->all('SELECT c.*, i.ma_hang, i.ten, u.ma AS unit, b.so_lo, b.han_dung, tx.ho_ten AS driver_name, nd.ho_ten AS staff_name, bh.ma AS warranty_code
                                      FROM chi_tiet_tra_hang_khach c JOIN mat_hang i ON i.id = c.mat_hang_id
                                      JOIN don_vi_tinh u ON u.id = i.don_vi_tinh_id JOIN lo_hang b ON b.id = c.lo_hang_id
                                      LEFT JOIN tai_xe tx ON tx.id = c.tai_xe_id LEFT JOIN nguoi_dung nd ON nd.id = c.nguoi_chiu_id LEFT JOIN bao_hanh bh ON bh.id = c.bao_hanh_id
                                      WHERE c.tra_hang_id = ? ORDER BY c.id', [$id]);
        $t['goods_value'] = array_sum(array_map(fn($l) => (int)$l['so_luong'] * (int)$l['don_gia'], $t['lines']));
        $t['deducted'] = array_sum(array_column($t['lines'], 'tien_tru_khach'));
        $t['compensation'] = array_sum(array_column($t['lines'], 'tien_den_bu'));
        $t['disposals'] = $this->db->all("SELECT id, ma, trang_thai FROM huy_hang WHERE ghi_chu LIKE ? ORDER BY id", ['Khách trả hàng ' . $t['ma'] . '%']);
        return $t;
    }

    public function list(array $warehouseIds, string $status = '', string $q = ''): array
    {
        if ($warehouseIds === []) {
            return [];
        }
        $where = 't.kho_id IN (' . implode(',', array_fill(0, count($warehouseIds), '?')) . ')';
        $args = $warehouseIds;
        if (isset(self::STATUS[$status])) {
            $where .= ' AND t.trang_thai = ?';
            $args[] = $status;
        }
        $rows = $this->db->all("SELECT t.*, w.ma AS wh_code, x.ma AS issue_code,
                                       (SELECT SUM(c.so_luong) FROM chi_tiet_tra_hang_khach c WHERE c.tra_hang_id = t.id) AS qty,
                                       (SELECT SUM(c.so_luong_loi) FROM chi_tiet_tra_hang_khach c WHERE c.tra_hang_id = t.id) AS bad
                                FROM tra_hang_khach t JOIN kho w ON w.id = t.kho_id JOIN phieu_nhap_xuat x ON x.id = t.phieu_xuat_id WHERE $where ORDER BY t.id DESC LIMIT 500", $args);
        return filter_rows($rows, $q, fn($r) => $r['ma'] . ' ' . $r['issue_code'] . ' ' . $r['khach'] . ' ' . $r['ly_do']);
    }

    /** @return array<string,int> */
    public function counts(array $warehouseIds): array
    {
        if ($warehouseIds === []) {
            return [];
        }
        $out = [];
        foreach ($this->db->all('SELECT trang_thai, COUNT(*) AS n FROM tra_hang_khach WHERE kho_id IN (' . implode(',', array_fill(0, count($warehouseIds), '?')) . ') GROUP BY trang_thai', $warehouseIds) as $r) {
            $out[$r['trang_thai']] = (int)$r['n'];
        }
        return $out;
    }

    /** Phiếu trả của một phiếu xuất (chưa huỷ) — hiện ở trang phiếu xuất. */
    public function forIssue(int $issueId): array
    {
        return $this->db->all("SELECT id, ma, trang_thai FROM tra_hang_khach WHERE phieu_xuat_id = ? AND trang_thai <> 'CANCELLED' ORDER BY id", [$issueId]);
    }

    /**
     * Các phiếu khách trả (chưa huỷ) của một phiếu xuất, kèm từng dòng chia ra: nhập lại kho (dùng được) / huỷ / bảo hành — hiện ở trang phiếu xuất.
     * @return list<array<string,mixed>>
     */
    public function forIssueDetail(int $issueId): array
    {
        $out = [];
        foreach ($this->db->all("SELECT t.id, t.ma, t.trang_thai, t.tien_hoan, t.hinh_thuc_hoan, t.ly_do, t.ngay_tao, t.ngay_xac_nhan, n.ma AS receipt_code, t.phieu_nhap_id
                                 FROM tra_hang_khach t LEFT JOIN phieu_nhap_xuat n ON n.id = t.phieu_nhap_id WHERE t.phieu_xuat_id = ? AND t.trang_thai <> 'CANCELLED' ORDER BY t.id", [$issueId]) as $t) {
            $t['lines'] = [];
            foreach ($this->db->all('SELECT c.so_luong, c.so_luong_loi, c.ly_do_loi, i.ma_hang, i.ten, u.ma AS unit FROM chi_tiet_tra_hang_khach c JOIN mat_hang i ON i.id = c.mat_hang_id
                                     JOIN don_vi_tinh u ON u.id = i.don_vi_tinh_id WHERE c.tra_hang_id = ? ORDER BY c.id', [(int)$t['id']]) as $c) {
                $bad = (int)$c['so_luong_loi'];
                $c['good'] = (int)$c['so_luong'] - $bad;
                $c['warranty'] = $c['ly_do_loi'] === 'NHA_SX' ? $bad : 0;
                $c['scrap'] = $bad - $c['warranty'];
                $t['lines'][] = $c;
            }
            $out[] = $t;
        }
        return $out;
    }

    /** Quản lý kho xác nhận: nhập lại kho toàn bộ hàng trả, phần lỗi đề xuất huỷ. @return list<string> cảnh báo (vd. chưa đề xuất huỷ được) */
    public function confirm(User $user, int $id): array
    {
        $peek = $this->find($id);
        $this->auth->require($user, (int)$peek['kho_id'], Permission::XacNhanPhieu);
        $this->db->transaction(function () use ($user, $id) {
            $t = $this->db->one('SELECT * FROM tra_hang_khach WHERE id = ? FOR UPDATE', [$id]);
            if (!$t || $t['trang_thai'] !== 'DRAFT') {
                throw new DomainError('Phiếu này không còn chờ xác nhận');
            }
            $this->returnableIssue((int)$t['phieu_xuat_id']);   // phiếu bán gốc có bị đảo trong lúc chờ không
            $left = [];
            foreach ($this->soldLines((int)$t['phieu_xuat_id']) as $s) {
                $left[$s['mat_hang_id'] . ':' . $s['lo_hang_id']] = $s;
            }
            $lines = [];
            foreach ($this->db->all('SELECT * FROM chi_tiet_tra_hang_khach WHERE tra_hang_id = ? ORDER BY id', [$id]) as $c) {
                $s = $left[$c['mat_hang_id'] . ':' . $c['lo_hang_id']] ?? null;
                // số đã trả đã gồm chính phiếu này (đang DRAFT, chưa huỷ) nên kiểm lại không cộng thêm nó lần nữa
                if (!$s || (int)$s['returned'] > (int)$s['sold']) {
                    throw new DomainError('Tổng số trả vượt số đã bán — kiểm tra lại các phiếu khách trả của phiếu xuất này');
                }
                $item = $this->db->one('SELECT gia_von FROM mat_hang WHERE id = ?', [(int)$c['mat_hang_id']]);
                $restock = (int)$c['so_luong'] - ($c['ly_do_loi'] === 'NHA_SX' ? (int)$c['so_luong_loi'] : 0);   // hàng lỗi nhà sản xuất không vào tồn mà sang kho bảo hành
                $key = $c['mat_hang_id'] . ':' . $c['lo_hang_id'];
                if ($restock > 0 && isset($lines[$key])) {   // phiếu điều chỉnh chỉ có một dòng cho mỗi mặt hàng + lô
                    $lines[$key]['so_luong'] += $restock;
                } elseif ($restock > 0) {
                    $lines[$key] = ['mat_hang_id' => (int)$c['mat_hang_id'], 'lo_hang_id' => (int)$c['lo_hang_id'], 'so_luong' => $restock, 'ghi_chu' => 'Khách trả hàng ' . $t['ma'],
                        'don_gia' => $s['don_gia'] !== null ? (int)$s['don_gia'] : null, 'thue_suat' => (int)($s['thue_suat'] ?? 0), 'gia_von' => (int)($item['gia_von'] ?? 0)];
                }
            }
            $vid = null;
            if ($lines !== []) {
                $vid = $this->vouchers->insert([
                    'loai' => VoucherType::Adjustment->value, 'trang_thai' => 'CONFIRMED', 'kho_id' => (int)$t['kho_id'], 'doi_tac' => $t['khach'],
                    'ly_do' => 'Khách trả hàng ' . $t['ma'], 'nguoi_lap_id' => $user->id,
                ], array_values($lines));
                $adj = $this->vouchers->lock($vid);
                $ops = $this->vouchers->ops($adj, $this->vouchers->lines($vid));
                $this->ledger->lock($this->vouchers->lockKeys($ops));
                $this->ledger->post($ops);
            }
            $this->openWarranty($user, $t);
            $this->db->exec("UPDATE tra_hang_khach SET trang_thai = 'CONFIRMED', phieu_nhap_id = ?, nguoi_xac_nhan_id = ?, ngay_xac_nhan = CURRENT_TIMESTAMP WHERE id = ?", [$vid, $user->id, $id]);
            $this->audit->record($user->id, 'creturn.confirm', 'creturn', $id, $t['ma']);
        });
        $warnings = [];
        $t = $this->find($id);
        foreach ($t['lines'] as $l) {   // phần lỗi (trừ lỗi nhà sản xuất đã sang kho bảo hành): đề xuất huỷ, người duyệt có quyền thì vào kho huỷ ngay
            if ((int)$l['so_luong_loi'] > 0 && $l['ly_do_loi'] !== 'NHA_SX') {
                try {
                    $this->disposals->propose($user, (int)$t['kho_id'], (int)$l['mat_hang_id'], (int)$l['lo_hang_id'], (int)$l['so_luong_loi'], $l['ly_do_loi'] === 'KHAC' ? 'KHAC' : 'VO_HONG',
                        'Khách trả hàng ' . $t['ma'] . ' (hàng lỗi — ' . mb_strtolower(self::CAUSES[$l['ly_do_loi']]) . ')');
                } catch (DomainError $e) {
                    $warnings[] = $l['ma_hang'] . ': chưa đề xuất huỷ được phần lỗi — ' . $e->getMessage();
                }
            }
        }
        return $warnings;
    }

    /** Hàng lỗi nhà sản xuất: mở phiếu kho bảo hành (không động tới tồn) để gửi trả / đòi nhà sản xuất. */
    private function openWarranty(User $user, array $t): void
    {
        $issue = $this->db->one('SELECT ngay_xac_nhan FROM phieu_nhap_xuat WHERE id = ?', [(int)$t['phieu_xuat_id']]);
        $bought = substr((string)($issue['ngay_xac_nhan'] ?? ''), 0, 10) ?: StockQuery::today();
        foreach ($this->db->all("SELECT * FROM chi_tiet_tra_hang_khach WHERE tra_hang_id = ? AND ly_do_loi = 'NHA_SX' AND so_luong_loi > 0 ORDER BY id", [(int)$t['id']]) as $c) {
            $item = $this->db->one('SELECT ma_hang, bao_hanh_thang FROM mat_hang WHERE id = ?', [(int)$c['mat_hang_id']]);
            $makers = array_column($this->db->all('SELECT p.ten FROM mat_hang_nha_cung_cap m JOIN doi_tac p ON p.id = m.doi_tac_id WHERE m.mat_hang_id = ? ORDER BY p.ten', [(int)$c['mat_hang_id']]), 'ten');
            $expiry = (int)$item['bao_hanh_thang'] > 0 ? WarrantyService::expiryOf($bought, (int)$item['bao_hanh_thang']) : $bought;
            $seq = $this->db->nextSequence('warranty');
            $bid = $this->db->insert(
                'INSERT INTO bao_hanh (ma, kho_id, mat_hang_id, so_luong, khach_ten, khach_sdt, ngay_mua, het_han, con_han, loi, nguoi_lap_id, phieu_mua_id, nha_san_xuat, ngay_nhan) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [sprintf('BH-%s-%05d', gmdate('ymd'), $seq), (int)$t['kho_id'], (int)$c['mat_hang_id'], (int)$c['so_luong_loi'], mb_substr((string)($t['khach'] ?: 'Khách lẻ'), 0, 120), '', $bought, $expiry, 1,
                 mb_substr('Khách trả hàng ' . $t['ma'] . ' — lỗi nhà sản xuất', 0, 255), $user->id, (int)$t['phieu_xuat_id'], mb_substr(implode(', ', $makers), 0, 255), StockQuery::today()]
            );
            $this->db->exec('UPDATE chi_tiet_tra_hang_khach SET bao_hanh_id = ? WHERE id = ?', [$bid, (int)$c['id']]);
            $this->audit->record($user->id, 'warranty.receive', 'warranty', $bid, $item['ma_hang'] . ' × ' . $c['so_luong_loi'] . ' · từ ' . $t['ma']);
        }
    }

    public function cancel(User $user, int $id): void
    {
        $t = $this->find($id);
        if (!$this->auth->can($user, (int)$t['kho_id'], Permission::XacNhanPhieu)) {
            $this->auth->require($user, (int)$t['kho_id'], Permission::LapPhieuNhap);
        }
        $n = $this->db->exec("UPDATE tra_hang_khach SET trang_thai = 'CANCELLED' WHERE id = ? AND trang_thai = 'DRAFT'", [$id]);
        if ($n !== 1) {
            throw new DomainError('Chỉ phiếu đang chờ xác nhận mới huỷ được');
        }
        $this->audit->record($user->id, 'creturn.cancel', 'creturn', $id, $t['ma']);
    }
}
