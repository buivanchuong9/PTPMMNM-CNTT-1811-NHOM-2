<?php
declare(strict_types=1);

namespace StockFlow\Service;

use DateTimeImmutable;
use StockFlow\Core\Db;
use StockFlow\Domain\DomainError;
use StockFlow\Domain\NotFound;
use StockFlow\Domain\Permission;
use StockFlow\Domain\User;
use StockFlow\Domain\VoucherType;

/**
 * Kho bảo hành. Chỉ mặt hàng có «thời hạn bảo hành» (mat_hang.bao_hanh_thang > 0) mới nhận bảo hành — linh kiện, điện tử có,
 * hàng tiêu dùng thì không. Hàng của khách mang đến không phải tồn của kho nên tiếp nhận không đụng sổ cái;
 * chỉ khi ĐỔI MỚI thì một đơn vị lấy từ tồn kho, được trừ bằng phiếu điều chỉnh (có mã, có lý do) như kiểm kê.
 *   TIEP_NHAN → DA_SUA | DOI_MOI | TU_CHOI → DA_TRA
 */
final class WarrantyService
{
    public const STATUS = [
        'TIEP_NHAN' => ['Đang giữ', 'warn'], 'DA_SUA' => ['Đã sửa xong', 'ok'], 'DOI_MOI' => ['Đã đổi mới', 'ok'],
        'TU_CHOI' => ['Từ chối bảo hành', 'danger'], 'DA_TRA' => ['Đã trả khách', 'muted'],
    ];
    /** Kết quả đã có, đang chờ trả khách. */
    public const WAITING = ['DA_SUA', 'DOI_MOI', 'TU_CHOI'];

    public function __construct(private Db $db, private Authorizer $auth, private AuditLog $audit, private VoucherRepository $vouchers, private Ledger $ledger) {}

    /** Mặt hàng nhận bảo hành. */
    public function warrantyItems(): array
    {
        return $this->db->all('SELECT i.id, i.ma_hang, i.ten, i.bao_hanh_thang, u.ma AS unit FROM mat_hang i JOIN don_vi_tinh u ON u.id = i.don_vi_tinh_id
                               WHERE i.hoat_dong AND i.bao_hanh_thang > 0 ORDER BY i.ma_hang');
    }

    /** Ngày hết bảo hành = ngày mua + số tháng; ngày không có trong tháng đích (31/01 + 1 tháng) lùi về cuối tháng. */
    public static function expiryOf(string $purchaseDate, int $months): string
    {
        $d = new DateTimeImmutable($purchaseDate);
        $first = $d->modify('first day of this month')->modify("+$months months");
        return $first->setDate((int)$first->format('Y'), (int)$first->format('n'), min((int)$d->format('j'), (int)$first->format('t')))->format('Y-m-d');
    }

    public function receive(User $user, int $warehouseId, int $itemId, int $qty, string $customer, string $phone, string $purchaseCode, string $defect): int
    {
        $this->auth->require($user, $warehouseId, Permission::XuLyBaoHanh);
        $customer = trim($customer);
        $phone = trim($phone);
        $defect = trim($defect);
        if ($customer === '' || mb_strlen($customer) > 120) {
            throw new DomainError('Nhập tên khách hàng (tối đa 120 ký tự)');
        }
        if ($phone !== '' && !preg_match('/^[0-9 .+-]{8,20}$/', $phone)) {
            throw new DomainError('Số điện thoại không hợp lệ');
        }
        if ($defect === '' || mb_strlen($defect) > 255) {
            throw new DomainError('Ghi tình trạng lỗi của hàng (tối đa 255 ký tự)');
        }
        if ($qty < 1 || $qty > 1000) {
            throw new DomainError('Số lượng nhận bảo hành từ 1 đến 1.000');
        }
        $today = StockQuery::today();
        $item = $this->db->one('SELECT ma_hang, ten, bao_hanh_thang, hoat_dong FROM mat_hang WHERE id = ?', [$itemId]) ?? throw new DomainError('Mặt hàng không tồn tại');
        if (!(int)$item['bao_hanh_thang']) {
            throw new DomainError("{$item['ten']} không có chính sách bảo hành");
        }
        $makers = array_column($this->db->all('SELECT p.ten FROM mat_hang_nha_cung_cap m JOIN doi_tac p ON p.id = m.doi_tac_id WHERE m.mat_hang_id = ? ORDER BY p.ten', [$itemId]), 'ten');
        if (!$makers) {
            throw new DomainError("{$item['ten']} chưa có thông tin nhà sản xuất / nhà cung cấp — bổ sung ở Hàng hoá trước khi tạo phiếu bảo hành");
        }
        $purchaseCode = trim($purchaseCode);
        $sale = $purchaseCode === '' ? null : $this->db->one("SELECT id, kho_id, ngay_xac_nhan FROM phieu_nhap_xuat WHERE ma = ? AND loai = 'ISSUE' AND trang_thai = 'CONFIRMED'", [$purchaseCode]);
        if (!$sale) {
            throw new DomainError('Nhập mã phiếu mua (phiếu xuất bán đã xác nhận) của khách — không có phiếu mua thì không nhận bảo hành');
        }
        if ((int)$sale['kho_id'] !== $warehouseId) {
            throw new DomainError('Phiếu mua này được bán từ kho khác — nhận bảo hành ở kho đã bán hàng');
        }
        $bought = (int)$this->db->value('SELECT COALESCE(SUM(so_luong),0) FROM chi_tiet_phieu_nhap_xuat WHERE phieu_nhap_xuat_id = ? AND mat_hang_id = ?', [(int)$sale['id'], $itemId]);
        if ($bought < 1) {
            throw new DomainError("Phiếu mua $purchaseCode không có mặt hàng {$item['ma_hang']}");
        }
        if ($qty > $bought) {
            throw new DomainError("Phiếu mua $purchaseCode chỉ bán $bought — không nhận bảo hành $qty");
        }
        $purchaseDate = substr((string)$sale['ngay_xac_nhan'], 0, 10);   // ngày mua = ngày xuất bán trên phiếu
        $expiry = self::expiryOf($purchaseDate, (int)$item['bao_hanh_thang']);
        $maker = mb_substr(implode(', ', $makers), 0, 255);
        $saleId = (int)$sale['id'];
        return $this->db->transaction(function () use ($user, $warehouseId, $itemId, $qty, $customer, $phone, $purchaseDate, $defect, $expiry, $today, $item, $maker, $saleId) {
            if (!$this->db->value('SELECT 1 FROM kho WHERE id = ? AND hoat_dong', [$warehouseId])) {
                throw new DomainError('Kho không tồn tại hoặc đã ngừng hoạt động');
            }
            $seq = $this->db->nextSequence('warranty');
            $id = $this->db->insert(
                'INSERT INTO bao_hanh (ma, kho_id, mat_hang_id, so_luong, khach_ten, khach_sdt, ngay_mua, het_han, con_han, loi, nguoi_lap_id, phieu_mua_id, nha_san_xuat) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [sprintf('BH-%s-%05d', gmdate('ymd'), $seq), $warehouseId, $itemId, $qty, $customer, $phone, $purchaseDate, $expiry, (int)($expiry >= $today), $defect, $user->id, $saleId, $maker]
            );
            $this->audit->record($user->id, 'warranty.receive', 'warranty', $id, $item['ma_hang'] . ' × ' . $qty . ' · ' . $customer);
            return $id;
        });
    }

    public function find(int $id): array
    {
        return $this->db->one(self::SELECT . ' WHERE b.id = ?', [$id]) ?? throw new NotFound('Không tìm thấy phiếu bảo hành');
    }

    private const SELECT = 'SELECT b.*, w.ma AS wh_code, w.ten AS wh_name, i.ma_hang, i.ten AS item_name, i.bao_hanh_thang, u.ma AS unit,
                                   lu.ho_ten AS creator_name, xu.ho_ten AS handler_name, v.ma AS voucher_code
                            FROM bao_hanh b JOIN kho w ON w.id = b.kho_id JOIN mat_hang i ON i.id = b.mat_hang_id JOIN don_vi_tinh u ON u.id = i.don_vi_tinh_id
                            JOIN nguoi_dung lu ON lu.id = b.nguoi_lap_id LEFT JOIN nguoi_dung xu ON xu.id = b.nguoi_xu_ly_id LEFT JOIN phieu_nhap_xuat v ON v.id = b.phieu_nhap_xuat_id';

    /** @param string $tab TIEP_NHAN (đang giữ) · WAITING (chờ trả khách) · DA_TRA · '' (tất cả) */
    public function list(array $warehouseIds, string $tab = '', string $q = ''): array
    {
        if ($warehouseIds === []) {
            return [];
        }
        $where = 'b.kho_id IN (' . implode(',', array_fill(0, count($warehouseIds), '?')) . ')';
        $args = $warehouseIds;
        if ($tab === 'WAITING') {
            $where .= " AND b.trang_thai IN ('DA_SUA','DOI_MOI','TU_CHOI')";
        } elseif ($tab !== '') {
            $where .= ' AND b.trang_thai = ?';
            $args[] = $tab;
        }
        if (($q = mb_strtolower(trim($q))) !== '') {
            $where .= ' AND (LOWER(b.ma) LIKE ? OR LOWER(b.khach_ten) LIKE ? OR b.khach_sdt LIKE ? OR LOWER(i.ten) LIKE ? OR LOWER(i.ma_hang) LIKE ?)';
            array_push($args, "%$q%", "%$q%", "%$q%", "%$q%", "%$q%");
        }
        return $this->db->all(self::SELECT . " WHERE $where ORDER BY b.id DESC LIMIT 500", $args);
    }

    /** @return array{TIEP_NHAN:int,WAITING:int,DA_TRA:int} */
    public function counts(array $warehouseIds): array
    {
        $out = ['TIEP_NHAN' => 0, 'WAITING' => 0, 'DA_TRA' => 0];
        if ($warehouseIds === []) {
            return $out;
        }
        $in = implode(',', array_fill(0, count($warehouseIds), '?'));
        foreach ($this->db->all("SELECT trang_thai, COUNT(*) AS n FROM bao_hanh WHERE kho_id IN ($in) GROUP BY trang_thai", $warehouseIds) as $r) {
            $out[in_array($r['trang_thai'], self::WAITING, true) ? 'WAITING' : $r['trang_thai']] += (int)$r['n'];
        }
        return $out;
    }

    /** Số phiếu bảo hành đang chờ nhân viên xử lý (đang giữ + chờ trả khách) — dùng cho số nhỏ trên menu. */
    public function open(array $warehouseIds): int
    {
        $c = $this->counts($warehouseIds);
        return $c['TIEP_NHAN'] + $c['WAITING'];
    }

    /** Ghi kết quả: sửa xong · đổi mới (trừ tồn kho) · từ chối. */
    public function resolve(User $user, int $id, string $result, string $note): void
    {
        if (!in_array($result, ['DA_SUA', 'DOI_MOI', 'TU_CHOI'], true)) {
            throw new DomainError('Kết quả bảo hành không hợp lệ');
        }
        $note = trim($note);
        if (mb_strlen($note) > 255) {
            throw new DomainError('Ghi chú tối đa 255 ký tự');
        }
        if ($result === 'TU_CHOI' && $note === '') {
            throw new DomainError('Từ chối bảo hành cần ghi lý do');
        }
        $peek = $this->find($id);
        $this->auth->require($user, (int)$peek['kho_id'], Permission::XuLyBaoHanh);
        $this->db->transaction(function () use ($user, $id, $result, $note) {
            $b = $this->db->one('SELECT * FROM bao_hanh WHERE id = ? FOR UPDATE', [$id]);
            if (!$b || $b['trang_thai'] !== 'TIEP_NHAN') {
                throw new DomainError('Phiếu bảo hành này đã có kết quả');
            }
            $voucherId = null;
            if ($result === 'DOI_MOI') {
                if (!(int)$b['con_han']) {
                    throw new DomainError('Hàng đã hết thời hạn bảo hành (đến ' . date('d/m/Y', strtotime($b['het_han'])) . ') — không được đổi mới miễn phí');
                }
                $voucherId = $this->issueReplacement($user, $b);
            }
            $this->db->exec('UPDATE bao_hanh SET trang_thai = ?, ket_qua = ?, phieu_nhap_xuat_id = ?, nguoi_xu_ly_id = ?, ngay_xu_ly = CURRENT_TIMESTAMP WHERE id = ?',
                [$result, $note, $voucherId, $user->id, $id]);
            $this->audit->record($user->id, 'warranty.' . strtolower($result), 'warranty', $id, $b['ma'] . ($note !== '' ? ': ' . $note : ''));
        });
    }

    /** Lấy một đơn vị mới đổi cho khách từ tồn kho (lô hết hạn sớm nhất trước) bằng phiếu điều chỉnh âm. @return int id phiếu */
    private function issueReplacement(User $user, array $b): int
    {
        $qty = (int)$b['so_luong'];
        $lot = $this->db->one(
            'SELECT t.lo_hang_id FROM ton_kho t JOIN lo_hang l ON l.id = t.lo_hang_id
             WHERE t.kho_id = ? AND t.mat_hang_id = ? AND t.so_luong >= ? AND (l.han_dung IS NULL OR l.han_dung >= ?)
             ORDER BY (l.han_dung IS NULL), l.han_dung, l.id LIMIT 1',
            [$b['kho_id'], $b['mat_hang_id'], $qty, StockQuery::today()]
        ) ?? throw new DomainError('Kho không còn đủ hàng để đổi mới — nhập thêm hàng hoặc chọn «Đã sửa xong»');
        $cost = (int)$this->db->value('SELECT gia_von FROM mat_hang WHERE id = ?', [$b['mat_hang_id']]);
        $voucherId = $this->vouchers->insert([
            'loai' => VoucherType::Adjustment->value, 'trang_thai' => 'CONFIRMED', 'kho_id' => (int)$b['kho_id'],
            'ly_do' => 'Đổi bảo hành ' . $b['ma'], 'nguoi_lap_id' => $user->id,
        ], [['mat_hang_id' => (int)$b['mat_hang_id'], 'lo_hang_id' => (int)$lot['lo_hang_id'], 'so_luong' => -$qty, 'ghi_chu' => 'Đổi bảo hành ' . $b['ma'], 'gia_von' => $cost]]);
        $adj = $this->vouchers->lock($voucherId);
        $ops = $this->vouchers->ops($adj, $this->vouchers->lines($voucherId));
        $this->ledger->lock($this->vouchers->lockKeys($ops));
        $this->ledger->post($ops);
        return $voucherId;
    }

    /** Trả hàng (đã sửa / đã đổi / bị từ chối) cho khách. */
    public function handBack(User $user, int $id): void
    {
        $peek = $this->find($id);
        $this->auth->require($user, (int)$peek['kho_id'], Permission::XuLyBaoHanh);
        $n = $this->db->exec("UPDATE bao_hanh SET trang_thai = 'DA_TRA', ngay_tra = CURRENT_TIMESTAMP WHERE id = ? AND trang_thai IN ('DA_SUA','DOI_MOI','TU_CHOI')", [$id]);
        if ($n !== 1) {
            throw new DomainError('Phiếu chưa có kết quả hoặc đã trả khách');
        }
        $this->audit->record($user->id, 'warranty.return', 'warranty', $id, $peek['ma']);
    }
}
