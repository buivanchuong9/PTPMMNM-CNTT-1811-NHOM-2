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
 * Kho huỷ: hàng hết hạn, vỡ, hỏng, không bán được.
 *   CHO_DUYET (đề xuất, chưa đụng tới tồn) → TRONG_KHO_HUY (quản lý duyệt: trừ tồn bằng phiếu điều chỉnh, hàng nằm chờ tiêu huỷ) → DA_TIEU_HUY.
 * Người có quyền duyệt tự lập thì duyệt luôn. TU_CHOI = không huỷ nữa (chỉ khi còn CHO_DUYET).
 */
final class DisposalService
{
    public const REASONS = ['HET_HAN' => 'Hết hạn', 'VO_HONG' => 'Vỡ / hỏng', 'LOI' => 'Lỗi nhà sản xuất', 'KHONG_BAN_DUOC' => 'Không bán được', 'KHAC' => 'Khác'];
    public const STATUS = [
        'CHO_DUYET' => ['Chờ duyệt', 'warn'], 'TRONG_KHO_HUY' => ['Trong kho huỷ', 'info'], 'DA_TIEU_HUY' => ['Đã tiêu huỷ', 'muted'], 'TU_CHOI' => ['Đã từ chối', 'danger'],
    ];

    /** Nơi tiêu huỷ mặc định khi kho chưa đặt riêng. */
    public const DEFAULT_SITE = ['name' => 'Khu xử lý chất thải Nam Sơn', 'address' => 'Xã Nam Sơn, Huyện Sóc Sơn, Hà Nội'];

    public function __construct(private Db $db, private Authorizer $auth, private AuditLog $audit, private VoucherRepository $vouchers, private Ledger $ledger, private FleetService $fleet, private TransportService $transport) {}

    /**
     * Đề xuất huỷ một dòng hàng. Người có quyền duyệt thì hàng vào kho huỷ ngay.
     * @return array{id:int,approved:bool}
     */
    public function propose(User $user, int $warehouseId, int $itemId, int $batchId, int $qty, string $reason, string $note): array
    {
        $this->auth->require($user, $warehouseId, Permission::LapPhieuHuy);
        if (!isset(self::REASONS[$reason])) {
            throw new DomainError('Chọn lý do huỷ hàng');
        }
        $note = trim($note);
        if (mb_strlen($note) > 255) {
            throw new DomainError('Ghi chú tối đa 255 ký tự');
        }
        if ($reason === 'KHAC' && $note === '') {
            throw new DomainError('Lý do «Khác» cần ghi chú rõ');
        }
        if ($qty < 1 || $qty > 1_000_000) {
            throw new DomainError('Số lượng phải là số nguyên dương');
        }
        $id = $this->db->transaction(function () use ($user, $warehouseId, $itemId, $batchId, $qty, $reason, $note) {
            if (!$this->db->value('SELECT 1 FROM kho WHERE id = ? AND hoat_dong', [$warehouseId])) {
                throw new DomainError('Kho không tồn tại hoặc đã ngừng hoạt động');
            }
            $item = $this->db->one('SELECT i.gia_von FROM mat_hang i JOIN lo_hang b ON b.mat_hang_id = i.id WHERE i.id = ? AND b.id = ?', [$itemId, $batchId])
                ?? throw new DomainError('Mặt hàng/lô không hợp lệ');
            $onHand = (int)$this->db->value('SELECT COALESCE(SUM(so_luong),0) FROM ton_kho WHERE kho_id = ? AND mat_hang_id = ? AND lo_hang_id = ?', [$warehouseId, $itemId, $batchId]);
            $pending = (int)$this->db->value("SELECT COALESCE(SUM(so_luong),0) FROM huy_hang WHERE kho_id = ? AND mat_hang_id = ? AND lo_hang_id = ? AND trang_thai = 'CHO_DUYET'", [$warehouseId, $itemId, $batchId]);
            if ($qty > $onHand - $pending) {
                throw new DomainError('Tồn kho chỉ còn ' . ($onHand - $pending) . ' (đã trừ ' . $pending . ' đang chờ duyệt huỷ) — không đủ để huỷ ' . $qty);
            }
            $seq = $this->db->nextSequence('disposal');
            $id = $this->db->insert(
                'INSERT INTO huy_hang (ma, kho_id, mat_hang_id, lo_hang_id, so_luong, ly_do, ghi_chu, gia_von, nguoi_lap_id) VALUES (?,?,?,?,?,?,?,?,?)',
                [sprintf('H-%s-%05d', gmdate('ymd'), $seq), $warehouseId, $itemId, $batchId, $qty, $reason, $note, (int)$item['gia_von'], $user->id]
            );
            $this->audit->record($user->id, 'disposal.propose', 'disposal', $id, self::REASONS[$reason] . ' × ' . $qty);
            return $id;
        });
        $approved = false;
        if ($this->auth->can($user, $warehouseId, Permission::XacNhanHuy)) {
            $this->approve($user, $id);
            $approved = true;
        }
        return ['id' => $id, 'approved' => $approved];
    }

    private const SELECT = 'SELECT h.*, w.ma AS wh_code, w.ten AS wh_name, i.ma_hang, i.ten AS item_name, u.ma AS unit, b.so_lo, b.han_dung, i.quan_ly_lo, i.danh_muc_id, dm.ten AS category_name,
                                   lu.ho_ten AS creator_name, du.ho_ten AS approver_name, v.ma AS voucher_code, xe.bien_so, tx.ho_ten AS tai_xe_ten, h.so_luong * h.gia_von AS value
                            FROM huy_hang h JOIN kho w ON w.id = h.kho_id JOIN mat_hang i ON i.id = h.mat_hang_id JOIN don_vi_tinh u ON u.id = i.don_vi_tinh_id LEFT JOIN danh_muc dm ON dm.id = i.danh_muc_id
                            JOIN lo_hang b ON b.id = h.lo_hang_id JOIN nguoi_dung lu ON lu.id = h.nguoi_lap_id LEFT JOIN nguoi_dung du ON du.id = h.nguoi_duyet_id
                            LEFT JOIN phieu_nhap_xuat v ON v.id = h.phieu_nhap_xuat_id LEFT JOIN xe ON xe.id = h.xe_id LEFT JOIN tai_xe tx ON tx.id = h.tai_xe_id';

    public function find(int $id): array
    {
        return $this->db->one(self::SELECT . ' WHERE h.id = ?', [$id]) ?? throw new NotFound('Không tìm thấy phiếu huỷ hàng');
    }

    public function list(array $warehouseIds, string $status = '', string $q = ''): array
    {
        if ($warehouseIds === []) {
            return [];
        }
        $where = 'h.kho_id IN (' . implode(',', array_fill(0, count($warehouseIds), '?')) . ')';
        $args = $warehouseIds;
        if (isset(self::STATUS[$status])) {
            $where .= ' AND h.trang_thai = ?';
            $args[] = $status;
        }
        if (($q = mb_strtolower(trim($q))) !== '') {
            $where .= ' AND (LOWER(h.ma) LIKE ? OR LOWER(i.ten) LIKE ? OR LOWER(i.ma_hang) LIKE ? OR LOWER(b.so_lo) LIKE ?)';
            array_push($args, "%$q%", "%$q%", "%$q%", "%$q%");
        }
        return $this->db->all(self::SELECT . " WHERE $where ORDER BY h.id DESC LIMIT 500", $args);
    }

    /**
     * Phiếu huỷ theo từ khoá, lý do, loại hàng, kho và khoảng ngày lập (giờ Việt Nam), KHÔNG lọc trạng thái — để tính số trên từng tab theo đúng bộ lọc đang chọn.
     * @return list<array<string,mixed>>
     */
    public function search(array $warehouseIds, string $q = '', string $reason = '', int $category = 0, int $warehouse = 0, ?string $from = null, ?string $to = null): array
    {
        return array_values(array_filter($this->list($warehouseIds, '', $q), fn($h) => ($reason === '' || $h['ly_do'] === $reason) && (!$category || (int)$h['danh_muc_id'] === $category)
            && (!$warehouse || (int)$h['kho_id'] === $warehouse) && in_vn_range($h['ngay_tao'], $from, $to)));
    }

    /** @return array<string,int> số phiếu theo trạng thái */
    public function counts(array $warehouseIds): array
    {
        if ($warehouseIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($warehouseIds), '?'));
        $out = [];
        foreach ($this->db->all("SELECT trang_thai, COUNT(*) AS n FROM huy_hang WHERE kho_id IN ($in) GROUP BY trang_thai", $warehouseIds) as $r) {
            $out[$r['trang_thai']] = (int)$r['n'];
        }
        return $out;
    }

    /** Giá trị vốn của hàng đã bị huỷ (trong kho huỷ + đã tiêu huỷ), theo lý do. @return array<string,array{n:int,qty:int,value:int}> */
    public function lossByReason(array $warehouseIds): array
    {
        if ($warehouseIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($warehouseIds), '?'));
        $out = [];
        foreach ($this->db->all("SELECT ly_do, COUNT(*) AS n, SUM(so_luong) AS qty, SUM(so_luong * gia_von) AS value FROM huy_hang
                                 WHERE kho_id IN ($in) AND trang_thai IN ('TRONG_KHO_HUY','DA_TIEU_HUY') GROUP BY ly_do", $warehouseIds) as $r) {
            $out[$r['ly_do']] = ['n' => (int)$r['n'], 'qty' => (int)$r['qty'], 'value' => (int)$r['value']];
        }
        return $out;
    }

    /**
     * Lô đã quá hạn dùng mà kho vẫn còn tồn (chưa nằm trong đề xuất huỷ nào) — gợi ý chuyển vào kho huỷ.
     * @return list<array{kho_id:int,wh_code:string,mat_hang_id:int,lo_hang_id:int,ma_hang:string,ten:string,so_lo:string,han_dung:string,unit:string,qty:int,gia_von:int}>
     */
    public function expiredStock(array $warehouseIds): array
    {
        if ($warehouseIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($warehouseIds), '?'));
        $rows = $this->db->all(
            "SELECT t.kho_id, w.ma AS wh_code, t.mat_hang_id, t.lo_hang_id, i.ma_hang, i.ten, b.so_lo, b.han_dung, u.ma AS unit, i.gia_von,
                    t.so_luong - COALESCE((SELECT SUM(h.so_luong) FROM huy_hang h WHERE h.kho_id = t.kho_id AND h.mat_hang_id = t.mat_hang_id AND h.lo_hang_id = t.lo_hang_id AND h.trang_thai = 'CHO_DUYET'), 0) AS qty
             FROM ton_kho t JOIN kho w ON w.id = t.kho_id JOIN mat_hang i ON i.id = t.mat_hang_id JOIN lo_hang b ON b.id = t.lo_hang_id JOIN don_vi_tinh u ON u.id = i.don_vi_tinh_id
             WHERE t.kho_id IN ($in) AND t.so_luong > 0 AND b.han_dung IS NOT NULL AND b.han_dung < ? ORDER BY b.han_dung, i.ma_hang",
            [...$warehouseIds, StockQuery::today()]
        );
        return array_values(array_filter($rows, fn($r) => (int)$r['qty'] > 0));
    }

    /** Hàng/lô đang còn tồn ở kho để chọn khi lập phiếu huỷ. */
    public function pickable(int $warehouseId): array
    {
        return $this->db->all(
            'SELECT t.mat_hang_id, t.lo_hang_id, t.so_luong AS qty, i.ma_hang, i.ten, i.quan_ly_lo, b.so_lo, b.han_dung, u.ma AS unit
             FROM ton_kho t JOIN mat_hang i ON i.id = t.mat_hang_id JOIN lo_hang b ON b.id = t.lo_hang_id JOIN don_vi_tinh u ON u.id = i.don_vi_tinh_id
             WHERE t.kho_id = ? AND t.so_luong > 0 ORDER BY i.ma_hang, (b.han_dung IS NULL), b.han_dung, b.so_lo',
            [$warehouseId]
        );
    }

    /** Quản lý duyệt: trừ tồn bằng phiếu điều chỉnh rồi chuyển hàng vào kho huỷ. Idempotent. */
    public function approve(User $user, int $id): void
    {
        $peek = $this->find($id);
        $this->auth->require($user, (int)$peek['kho_id'], Permission::XacNhanHuy);
        $this->db->transaction(function () use ($user, $id) {
            $h = $this->db->one('SELECT * FROM huy_hang WHERE id = ? FOR UPDATE', [$id]);
            if ($h['trang_thai'] === 'TRONG_KHO_HUY') {
                return;
            }
            if ($h['trang_thai'] !== 'CHO_DUYET') {
                throw new DomainError('Chỉ đề xuất đang chờ duyệt mới duyệt được');
            }
            $reason = self::REASONS[$h['ly_do']] ?? $h['ly_do'];
            $voucherId = $this->vouchers->insert([
                'loai' => VoucherType::Adjustment->value, 'trang_thai' => 'CONFIRMED', 'kho_id' => (int)$h['kho_id'],
                'ly_do' => 'Huỷ hàng ' . $h['ma'] . ': ' . $reason, 'nguoi_lap_id' => $user->id,
            ], [['mat_hang_id' => (int)$h['mat_hang_id'], 'lo_hang_id' => (int)$h['lo_hang_id'], 'so_luong' => -(int)$h['so_luong'], 'ghi_chu' => $reason, 'gia_von' => (int)$h['gia_von']]]);
            $adj = $this->vouchers->lock($voucherId);
            $ops = $this->vouchers->ops($adj, $this->vouchers->lines($voucherId));
            $this->ledger->lock($this->vouchers->lockKeys($ops));
            $this->ledger->post($ops);   // không đủ tồn ⇒ InsufficientStock, cả giao dịch quay lại
            $this->db->exec("UPDATE huy_hang SET trang_thai = 'TRONG_KHO_HUY', phieu_nhap_xuat_id = ?, nguoi_duyet_id = ?, ngay_duyet = CURRENT_TIMESTAMP WHERE id = ?", [$voucherId, $user->id, $id]);
            $this->audit->record($user->id, 'disposal.approve', 'disposal', $id, $h['ma']);
        });
    }

    /**
     * Kiểm kê phát hiện hàng không đạt chất lượng: trừ khỏi tồn và đưa thẳng vào kho huỷ (đã duyệt). Gọi trong giao dịch duyệt kiểm kê.
     * @param array $count đợt kiểm kê @param list<array> $bad dòng chi_tiet_kiem_ke có so_luong_hong > 0
     * @return int số dòng đã chuyển vào kho huỷ
     */
    public function fromCount(User $approver, array $count, array $bad): int
    {
        if ($bad === []) {
            return 0;
        }
        $lines = [];
        foreach ($bad as $b) {
            $cost = (int)$this->db->value('SELECT gia_von FROM mat_hang WHERE id = ?', [$b['mat_hang_id']]);
            $lines[] = ['mat_hang_id' => (int)$b['mat_hang_id'], 'lo_hang_id' => (int)$b['lo_hang_id'], 'so_luong' => -(int)$b['so_luong_hong'],
                        'ghi_chu' => self::REASONS[$b['ly_do_hong']] ?? $b['ly_do_hong'], 'gia_von' => $cost];
        }
        $voucherId = $this->vouchers->insert([
            'loai' => VoucherType::Adjustment->value, 'trang_thai' => 'CONFIRMED', 'kho_id' => (int)$count['kho_id'],
            'ly_do' => 'Hàng không đạt chất lượng, kiểm kê ' . $count['ma'], 'nguoi_lap_id' => $approver->id,
        ], $lines);
        $adj = $this->vouchers->lock($voucherId);
        $ops = $this->vouchers->ops($adj, $this->vouchers->lines($voucherId));
        $this->ledger->lock($this->vouchers->lockKeys($ops));
        $this->ledger->post($ops);
        foreach ($bad as $i => $b) {
            $seq = $this->db->nextSequence('disposal');
            $id = $this->db->insert(
                "INSERT INTO huy_hang (ma, kho_id, mat_hang_id, lo_hang_id, so_luong, ly_do, ghi_chu, gia_von, trang_thai, phieu_nhap_xuat_id, nguoi_lap_id, nguoi_duyet_id, ngay_duyet)
                 VALUES (?,?,?,?,?,?,?,?,'TRONG_KHO_HUY',?,?,?,CURRENT_TIMESTAMP)",
                [sprintf('H-%s-%05d', gmdate('ymd'), $seq), $count['kho_id'], $b['mat_hang_id'], $b['lo_hang_id'], $b['so_luong_hong'], $b['ly_do_hong'],
                 mb_substr(trim('Kiểm kê ' . $count['ma'] . ($b['ghi_chu_hong'] !== '' ? ': ' . $b['ghi_chu_hong'] : '')), 0, 255), $lines[$i]['gia_von'], $voucherId,
                 $count['nguoi_lap_id'], $approver->id]
            );
            $this->audit->record($approver->id, 'disposal.approve', 'disposal', $id, 'từ kiểm kê ' . $count['ma']);
        }
        return count($bad);
    }

    /** Từ chối / rút lại đề xuất (chưa trừ tồn). Người duyệt hoặc người lập đều làm được. */
    public function reject(User $user, int $id): void
    {
        $peek = $this->find($id);
        if (!$this->auth->can($user, (int)$peek['kho_id'], Permission::XacNhanHuy)) {
            $this->auth->require($user, (int)$peek['kho_id'], Permission::LapPhieuHuy);
        }
        $n = $this->db->exec("UPDATE huy_hang SET trang_thai = 'TU_CHOI', nguoi_duyet_id = ?, ngay_duyet = CURRENT_TIMESTAMP WHERE id = ? AND trang_thai = 'CHO_DUYET'", [$user->id, $id]);
        if ($n !== 1) {
            throw new DomainError('Chỉ đề xuất đang chờ duyệt mới từ chối được');
        }
        $this->audit->record($user->id, 'disposal.reject', 'disposal', $id, $peek['ma']);
    }

    /**
     * Hoàn tác khi bấm nhầm, lùi từng bước: đã tiêu huỷ ⇒ xe vẫn đang chở · đang trên xe ⇒ hàng xuống xe, nằm lại kho huỷ · nằm trong kho huỷ ⇒ trả hàng về tồn bằng phiếu điều chỉnh cộng lại và đóng phiếu huỷ (không huỷ nữa).
     * @return string trạng thái mới
     */
    public function undo(User $user, int $id): string
    {
        $peek = $this->find($id);
        $this->auth->require($user, (int)$peek['kho_id'], Permission::XacNhanHuy);
        return $this->db->transaction(function () use ($user, $id) {
            $h = $this->db->one('SELECT * FROM huy_hang WHERE id = ? FOR UPDATE', [$id]);
            if ($h['trang_thai'] === 'DA_TIEU_HUY') {
                $this->db->exec("UPDATE huy_hang SET trang_thai = 'TRONG_KHO_HUY', ngay_tieu_huy = NULL WHERE id = ?", [$id]);
                $this->audit->record($user->id, 'disposal.undo', 'disposal', $id, $h['ma'] . ' · bấm nhầm đã đến, xe vẫn đang chở hàng');
                return 'TRONG_KHO_HUY';
            }
            if ($h['trang_thai'] === 'TRONG_KHO_HUY' && $h['xe_id'] !== null) {
                $this->db->exec("UPDATE huy_hang SET xe_id = NULL, tai_xe_id = NULL, ngay_xuat = NULL, don_vi_tieu_huy = '', dia_chi_tieu_huy = '' WHERE id = ?", [$id]);
                $this->audit->record($user->id, 'disposal.undo', 'disposal', $id, $h['ma'] . ' · hàng xuống xe, quay lại kho huỷ');
                return 'KHO_HUY';
            }
            if ($h['trang_thai'] !== 'TRONG_KHO_HUY') {
                throw new DomainError('Chỉ hàng đang ở kho huỷ hoặc đã tiêu huỷ mới hoàn tác được');
            }
            $voucherId = $this->vouchers->insert([
                'loai' => VoucherType::Adjustment->value, 'trang_thai' => 'CONFIRMED', 'kho_id' => (int)$h['kho_id'],
                'ly_do' => 'Hoàn tác huỷ hàng ' . $h['ma'] . ': trả hàng về tồn', 'nguoi_lap_id' => $user->id,
            ], [['mat_hang_id' => (int)$h['mat_hang_id'], 'lo_hang_id' => (int)$h['lo_hang_id'], 'so_luong' => (int)$h['so_luong'], 'ghi_chu' => 'Hoàn tác huỷ', 'gia_von' => (int)$h['gia_von']]]);
            $adj = $this->vouchers->lock($voucherId);
            $ops = $this->vouchers->ops($adj, $this->vouchers->lines($voucherId));
            $this->ledger->lock($this->vouchers->lockKeys($ops));
            $this->ledger->post($ops);
            $this->db->exec("UPDATE huy_hang SET trang_thai = 'TU_CHOI', ghi_chu = ? WHERE id = ?", [mb_substr('Hoàn tác: trả hàng về tồn (phiếu điều chỉnh)', 0, 255), $id]);
            $this->audit->record($user->id, 'disposal.undo', 'disposal', $id, $h['ma'] . ' · trả hàng về tồn');
            return 'TU_CHOI';
        });
    }

    /** Nơi tiêu huỷ của kho: kho tự đặt, chưa đặt thì dùng nơi mặc định. @return array{name:string,address:string,custom:bool} */
    public function site(int $warehouseId): array
    {
        $k = $this->db->one('SELECT noi_tieu_huy, dia_chi_tieu_huy FROM kho WHERE id = ?', [$warehouseId]) ?? throw new DomainError('Kho không tồn tại');
        if (trim((string)$k['noi_tieu_huy']) !== '' && trim((string)$k['dia_chi_tieu_huy']) !== '') {
            return ['name' => (string)$k['noi_tieu_huy'], 'address' => (string)$k['dia_chi_tieu_huy'], 'custom' => true];
        }
        return self::DEFAULT_SITE + ['custom' => false];
    }

    /** Đặt nơi tiêu huỷ của kho (hàng sẽ được giao đến đây). Để trống cả hai ô = quay về nơi mặc định. */
    public function setSite(User $user, int $warehouseId, string $name, string $address): void
    {
        $this->auth->require($user, $warehouseId, Permission::XacNhanHuy);
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        $address = trim(preg_replace('/\s+/u', ' ', $address) ?? '');
        if (($name === '') !== ($address === '')) {
            throw new DomainError('Nhập đủ tên đơn vị và địa chỉ tiêu huỷ (hoặc để trống cả hai để dùng nơi mặc định)');
        }
        if (mb_strlen($name) > 120 || mb_strlen($address) > 255) {
            throw new DomainError('Tên đơn vị tối đa 120 ký tự, địa chỉ tối đa 255 ký tự');
        }
        $this->db->exec('UPDATE kho SET noi_tieu_huy = ?, dia_chi_tieu_huy = ? WHERE id = ?', [$name, $address, $warehouseId]);
        $this->audit->record($user->id, 'disposal.site', 'warehouse', $warehouseId, $name === '' ? 'dùng nơi tiêu huỷ mặc định' : "$name · $address");
    }

    /**
     * Nhận hàng từ kho huỷ lên xe và xuất đi tiêu huỷ: chọn xe của kho + tài xế, tài xế xác nhận đã nhận hàng; nơi đến là nơi tiêu huỷ của kho (chốt tại thời điểm đi).
     * Xe phải đủ tải / giấy tờ / bằng lái như mọi chuyến chở hàng.
     */
    public function load(User $user, int $id, int $vehicleId, int $driverId, bool $driverOk): void
    {
        $this->loadMany($user, [$id], $vehicleId, $driverId, $driverOk);
    }

    /**
     * Nhiều lô hàng trong kho huỷ lên CHUNG MỘT xe, một tài xế (một chuyến đi tiêu huỷ). Tổng tải của cả đợt phải vừa xe; các lô phải cùng kho.
     * Các lô của cùng chuyến có cùng xe và cùng giờ xuất (ngay_xuat) — dùng để gom nhóm khi hiển thị và khi xác nhận «đã đến nơi».
     * @param list<int> $ids @return int số lô đã lên xe
     */
    public function loadMany(User $user, array $ids, int $vehicleId, int $driverId, bool $driverOk): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            throw new DomainError('Chọn ít nhất một lô hàng cần xuất đi tiêu huỷ');
        }
        $peek = $this->find($ids[0]);
        $wh = (int)$peek['kho_id'];
        $this->auth->require($user, $wh, Permission::XacNhanHuy);
        if (!$vehicleId || !$driverId) {
            throw new DomainError('Chọn xe và tài xế chở hàng đi tiêu huỷ');
        }
        if (!$driverOk) {
            throw new DomainError('Tài xế phải xác nhận đã nhận đủ hàng lên xe');
        }
        return $this->db->transaction(function () use ($user, $ids, $vehicleId, $driverId, $wh) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $rows = $this->db->all("SELECT * FROM huy_hang WHERE id IN ($in) ORDER BY id FOR UPDATE", $ids);
            if (count($rows) !== count($ids)) {
                throw new DomainError('Có lô hàng không tồn tại');
            }
            $lines = [];
            foreach ($rows as $h) {
                if ((int)$h['kho_id'] !== $wh) {
                    throw new DomainError('Chỉ gom hàng của cùng một kho lên một xe');
                }
                if ($h['trang_thai'] !== 'TRONG_KHO_HUY' || $h['xe_id'] !== null) {
                    throw new DomainError('Chỉ hàng đang nằm trong kho huỷ mới xuất đi tiêu huỷ được (' . $h['ma'] . ' không đúng trạng thái)');
                }
                $lines[] = ['mat_hang_id' => (int)$h['mat_hang_id'], 'so_luong' => (int)$h['so_luong']];
            }
            $xe = $this->db->one('SELECT * FROM xe WHERE id = ? FOR UPDATE', [$vehicleId]);
            if (!$xe || !$xe['hoat_dong'] || (int)$xe['thue_ngoai'] || (int)($xe['kho_hien_tai_id'] ?? 0) !== $wh) {
                throw new DomainError('Chọn xe của kho đang đứng tại kho này');
            }
            $tx = $this->fleet->assignableDriver($driverId, $wh);
            $this->transport->assertCanCarry($vehicleId, $lines, (int)$tx['id']);   // tải của CẢ đợt phải vừa xe
            $site = $this->site($wh);
            $at = gmdate('Y-m-d H:i:s');   // cùng một mốc cho cả chuyến
            foreach ($rows as $h) {
                $this->db->exec('UPDATE huy_hang SET xe_id = ?, tai_xe_id = ?, ngay_xuat = ?, don_vi_tieu_huy = ?, dia_chi_tieu_huy = ? WHERE id = ?',
                    [$vehicleId, (int)$tx['id'], $at, $site['name'], $site['address'], (int)$h['id']]);
                $this->audit->record($user->id, 'disposal.load', 'disposal', (int)$h['id'], $h['ma'] . " · {$xe['bien_so']} · {$tx['ho_ten']} → {$site['name']}, {$site['address']}" . (count($rows) > 1 ? ' · chung chuyến ' . count($rows) . ' lô' : ''));
            }
            return count($rows);
        });
    }

    /**
     * Xác nhận cả chuyến đã đến nơi tiêu huỷ (nhiều lô cùng xe). Một lô sai trạng thái ⇒ cả đợt quay lại, không lô nào đổi.
     * @param list<int> $ids @return int số lô đã tiêu huỷ
     */
    public function destroyMany(User $user, array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            throw new DomainError('Chưa chọn lô hàng nào');
        }
        return $this->db->transaction(function () use ($user, $ids) {
            foreach ($ids as $id) {
                $this->destroy($user, $id);
            }
            return count($ids);
        });
    }

    /**
     * Cả chuyến xuống xe, quay lại kho huỷ (nhiều lô cùng xe). Chỉ áp dụng cho hàng đang trên xe.
     * @param list<int> $ids @return int số lô đã xuống xe
     */
    public function unloadMany(User $user, array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            throw new DomainError('Chưa chọn lô hàng nào');
        }
        return $this->db->transaction(function () use ($user, $ids) {
            foreach ($ids as $id) {
                $h = $this->find($id);
                if ($h['trang_thai'] !== 'TRONG_KHO_HUY' || $h['xe_id'] === null) {
                    throw new DomainError($h['ma'] . ' không đang trên xe nên không thể cho xuống xe');
                }
                $this->undo($user, $id);
            }
            return count($ids);
        });
    }

    /** Xe đã đến nơi tiêu huỷ: xác nhận ⇒ hàng được tiêu huỷ (rời kho huỷ). */
    public function destroy(User $user, int $id): void
    {
        $peek = $this->find($id);
        $this->auth->require($user, (int)$peek['kho_id'], Permission::XacNhanHuy);
        if ($peek['trang_thai'] === 'TRONG_KHO_HUY' && $peek['xe_id'] === null) {
            throw new DomainError('Hàng chưa lên xe đi tiêu huỷ — nhận hàng lên xe trước');
        }
        $n = $this->db->exec("UPDATE huy_hang SET trang_thai = 'DA_TIEU_HUY', ngay_tieu_huy = CURRENT_TIMESTAMP WHERE id = ? AND trang_thai = 'TRONG_KHO_HUY' AND xe_id IS NOT NULL", [$id]);
        if ($n !== 1) {
            throw new DomainError('Chỉ hàng đang trên xe đi tiêu huỷ mới xác nhận đến nơi được');
        }
        $this->audit->record($user->id, 'disposal.destroy', 'disposal', $id, $peek['ma'] . ' · xe đã đến ' . $peek['don_vi_tieu_huy'] . ', ' . $peek['dia_chi_tieu_huy'] . ' · đã tiêu huỷ');
    }
}
