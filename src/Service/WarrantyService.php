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

    public function __construct(private Db $db, private Authorizer $auth, private AuditLog $audit, private VoucherRepository $vouchers, private Ledger $ledger, private SaleCodeService $saleCodes) {}

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

    /**
     * Nhận hàng bảo hành. Có đủ «mã lô hàng» + «mã món hàng» (in trên phiếu bán) thì mặt hàng, ngày mua, hạn bảo hành lấy theo mã (mỗi mã một chiếc);
     * không có thì nhận theo mặt hàng + mã phiếu mua như trước (hàng bán từ trước khi có mã).
     */
    public function receive(User $user, int $warehouseId, int $itemId, int $qty, string $customer, string $phone, string $purchaseCode, string $defect, string $lotCode = '', string $itemCode = ''): int
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
        $unit = null;
        if (trim($lotCode) !== '' || trim($itemCode) !== '') {   // nhận theo mã bán hàng: mỗi mã là một chiếc đã bán
            $unit = $this->saleCodes->find($lotCode, $itemCode) ?? throw new DomainError('Mã lô hàng và mã món hàng không khớp với chiếc nào đã bán — kiểm tra lại hai mã trên phiếu bán');
            if ((int)$unit['da_huy']) {
                throw new DomainError('Mã này thuộc phiếu bán đã bị điều chỉnh đảo — không còn hiệu lực');
            }
            if ((int)$unit['kho_id'] !== $warehouseId) {
                throw new DomainError('Chiếc này được bán từ kho ' . $unit['wh_code'] . ' — nhận bảo hành ở kho đã bán hàng');
            }
            if ($unit['dang_bao_hanh']) {
                throw new DomainError('Chiếc này đang có phiếu bảo hành ' . $unit['dang_bao_hanh'] . ' chưa trả khách');
            }
            $itemId = (int)$unit['mat_hang_id'];
            $qty = 1;
        }
        if ($qty < 1 || $qty > 1000) {
            throw new DomainError('Số lượng nhận bảo hành từ 1 đến 1.000');
        }
        $today = StockQuery::today();
        $item = $this->db->one('SELECT ma_hang, ten, bao_hanh_thang, hoat_dong FROM mat_hang WHERE id = ?', [$itemId]) ?? throw new DomainError('Mặt hàng không tồn tại');
        if (!$unit && !(int)$item['bao_hanh_thang']) {
            throw new DomainError("{$item['ten']} không có chính sách bảo hành");
        }
        $makers = array_column($this->db->all('SELECT p.ten FROM mat_hang_nha_cung_cap m JOIN doi_tac p ON p.id = m.doi_tac_id WHERE m.mat_hang_id = ? ORDER BY p.ten', [$itemId]), 'ten');
        if (!$makers) {
            throw new DomainError("{$item['ten']} chưa có thông tin nhà sản xuất / nhà cung cấp — bổ sung ở Hàng hoá trước khi tạo phiếu bảo hành");
        }
        if ($unit) {
            $purchaseDate = (string)$unit['ngay_mua'];   // ngày mua và hạn bảo hành đã chốt lúc bán
            $expiry = (string)$unit['het_han'];
            $saleId = (int)$unit['phieu_nhap_xuat_id'];
            $unitId = (int)$unit['id'];
        } else {
            $purchaseCode = trim($purchaseCode);
            $sale = $purchaseCode === '' ? null : $this->db->one("SELECT id, kho_id, ngay_xac_nhan FROM phieu_nhap_xuat WHERE ma = ? AND loai = 'ISSUE' AND trang_thai = 'CONFIRMED'", [$purchaseCode]);
            if (!$sale) {
                throw new DomainError('Nhập mã lô hàng + mã món hàng (hoặc mã phiếu mua) của khách — không có thì không nhận bảo hành');
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
            $saleId = (int)$sale['id'];
            $unitId = null;
        }
        $maker = mb_substr(implode(', ', $makers), 0, 255);
        return $this->db->transaction(function () use ($user, $warehouseId, $itemId, $qty, $customer, $phone, $purchaseDate, $defect, $expiry, $today, $item, $maker, $saleId, $unitId) {
            if (!$this->db->value('SELECT 1 FROM kho WHERE id = ? AND hoat_dong', [$warehouseId])) {
                throw new DomainError('Kho không tồn tại hoặc đã ngừng hoạt động');
            }
            $seq = $this->db->nextSequence('warranty');
            $id = $this->db->insert(
                'INSERT INTO bao_hanh (ma, kho_id, mat_hang_id, so_luong, khach_ten, khach_sdt, ngay_mua, het_han, con_han, loi, nguoi_lap_id, phieu_mua_id, nha_san_xuat, san_pham_ban_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [sprintf('BH-%s-%05d', gmdate('ymd'), $seq), $warehouseId, $itemId, $qty, $customer, $phone, $purchaseDate, $expiry, (int)($expiry >= $today), $defect, $user->id, $saleId, $maker, $unitId]
            );
            $this->audit->record($user->id, 'warranty.receive', 'warranty', $id, $item['ma_hang'] . ' × ' . $qty . ' · ' . $customer);
            return $id;
        });
    }

    /**
     * Nhận bảo hành THEO MÃ (form nhận hàng): chỉ nhập mã lô hàng ⇒ bảo hành CẢ LÔ (mọi chiếc của dòng phiếu bán đó chưa có phiếu bảo hành đang mở);
     * nhập mã món hàng ⇒ bảo hành đúng chiếc đó. Mặt hàng, số lượng, ngày mua, hạn bảo hành đều lấy theo mã. Ngày nhận mặc định hôm nay, ngày hẹn trả là ước lượng.
     * @return int id phiếu bảo hành
     */
    public function receiveByCode(User $user, int $warehouseId, string $lotCode, string $itemCode, string $customer, string $phone, string $address, string $defect, ?string $receivedOn = null, ?string $dueOn = null): int
    {
        $this->auth->require($user, $warehouseId, Permission::XuLyBaoHanh);
        $customer = trim($customer);
        $phone = trim($phone);
        $address = trim($address);
        $defect = trim($defect);
        if ($customer === '' || mb_strlen($customer) > 120) {
            throw new DomainError('Nhập tên khách hàng (tối đa 120 ký tự)');
        }
        if (!preg_match('/^[0-9 .+-]{8,20}$/', $phone)) {
            throw new DomainError('Nhập số điện thoại khách để liên hệ khi trả hàng (8–20 chữ số)');
        }
        if (mb_strlen($address) > 255) {
            throw new DomainError('Địa chỉ khách tối đa 255 ký tự');
        }
        if ($defect === '' || mb_strlen($defect) > 255) {
            throw new DomainError('Ghi tình trạng lỗi của hàng (tối đa 255 ký tự)');
        }
        $today = StockQuery::today();
        $receivedOn = $receivedOn === null || $receivedOn === '' ? $today : $receivedOn;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $receivedOn) || $receivedOn > $today) {
            throw new DomainError('Ngày nhận bảo hành không hợp lệ (không được là ngày trong tương lai)');
        }
        if ($dueOn !== null && $dueOn !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueOn) || $dueOn < $receivedOn || $dueOn > date('Y-m-d', strtotime($receivedOn . ' +365 days')))) {
            throw new DomainError('Ngày hẹn trả phải từ ngày nhận trở đi (tối đa 1 năm)');
        }
        $dueOn = $dueOn === '' ? null : $dueOn;
        $lotCode = SaleCodeService::clean($lotCode);
        $itemCode = SaleCodeService::clean($itemCode);
        if ($lotCode === '' && $itemCode === '') {
            throw new DomainError('Nhập mã lô hàng (bảo hành cả lô) hoặc mã món hàng (bảo hành một chiếc)');
        }
        if ($itemCode !== '') {   // một chiếc
            $unit = $this->saleCodes->find($lotCode, $itemCode) ?? throw new DomainError('Mã món hàng không khớp với chiếc nào đã bán — kiểm tra lại mã trên phiếu bán');
            $lotCode = (string)$unit['ma_lo'];
            $units = [$unit];
        } else {   // cả lô
            $units = $this->saleCodes->unitsOfLot($lotCode, $warehouseId);
            if (!$units) {
                throw new DomainError('Mã lô hàng không khớp với lô nào đã bán ở kho này, hoặc mọi chiếc trong lô đang có phiếu bảo hành');
            }
        }
        $first = $units[0];
        if ((int)$first['da_huy']) {
            throw new DomainError('Mã này thuộc phiếu bán đã bị điều chỉnh đảo — không còn hiệu lực');
        }
        if ((int)$first['kho_id'] !== $warehouseId) {
            throw new DomainError('Hàng này được bán từ kho ' . ($first['wh_code'] ?? '') . ' — nhận bảo hành ở kho đã bán hàng');
        }
        if ($this->db->value("SELECT 1 FROM bao_hanh WHERE ma_lo = ? AND san_pham_ban_id IS NULL AND trang_thai <> 'DA_TRA' LIMIT 1", [$lotCode])) {
            throw new DomainError('Lô ' . $lotCode . ' đang có phiếu bảo hành cả lô chưa trả khách');
        }
        if ($itemCode !== '' && $first['dang_bao_hanh']) {
            throw new DomainError('Chiếc này đang có phiếu bảo hành ' . $first['dang_bao_hanh'] . ' chưa trả khách');
        }
        $itemId = (int)$first['mat_hang_id'];
        $item = $this->db->one('SELECT ma_hang, ten FROM mat_hang WHERE id = ?', [$itemId]) ?? throw new DomainError('Mặt hàng không tồn tại');
        $makers = array_column($this->db->all('SELECT p.ten FROM mat_hang_nha_cung_cap m JOIN doi_tac p ON p.id = m.doi_tac_id WHERE m.mat_hang_id = ? ORDER BY p.ten', [$itemId]), 'ten');
        if (!$makers) {
            throw new DomainError("{$item['ten']} chưa có thông tin nhà sản xuất / nhà cung cấp — bổ sung ở Hàng hoá trước khi tạo phiếu bảo hành");
        }
        $maker = mb_substr(implode(', ', $makers), 0, 255);
        $whole = $itemCode === '';
        $qty = count($units);
        return $this->db->transaction(function () use ($user, $warehouseId, $itemId, $qty, $customer, $phone, $address, $defect, $receivedOn, $dueOn, $first, $item, $maker, $whole, $lotCode) {
            if (!$this->db->value('SELECT 1 FROM kho WHERE id = ? AND hoat_dong', [$warehouseId])) {
                throw new DomainError('Kho không tồn tại hoặc đã ngừng hoạt động');
            }
            $seq = $this->db->nextSequence('warranty');
            $id = $this->db->insert(
                'INSERT INTO bao_hanh (ma, kho_id, mat_hang_id, so_luong, khach_ten, khach_sdt, khach_dia_chi, ngay_mua, het_han, con_han, loi, nguoi_lap_id, phieu_mua_id, nha_san_xuat, san_pham_ban_id, ma_lo, ngay_nhan, ngay_hen_tra) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [sprintf('BH-%s-%05d', gmdate('ymd'), $seq), $warehouseId, $itemId, $qty, $customer, $phone, $address, $first['ngay_mua'], $first['het_han'], (int)($first['het_han'] >= $receivedOn),
                 $defect, $user->id, (int)$first['phieu_nhap_xuat_id'], $maker, $whole ? null : (int)$first['id'], $lotCode, $receivedOn, $dueOn]
            );
            $this->audit->record($user->id, 'warranty.receive', 'warranty', $id, $item['ma_hang'] . ' × ' . $qty . ($whole ? ' (cả lô ' . $lotCode . ')' : ' (' . $first['ma_mon'] . ')') . ' · ' . $customer);
            return $id;
        });
    }

    public function find(int $id): array
    {
        return $this->db->one(self::SELECT . ' WHERE b.id = ?', [$id]) ?? throw new NotFound('Không tìm thấy phiếu bảo hành');
    }

    private const SELECT = 'SELECT b.*, w.ma AS wh_code, w.ten AS wh_name, w.dia_chi AS wh_addr, i.ma_hang, i.ten AS item_name, i.bao_hanh_thang, u.ma AS unit,
                                   lu.ho_ten AS creator_name, xu.ho_ten AS handler_name, v.ma AS voucher_code, sp.ma_lo AS sale_lot, sp.ma_mon AS sale_item
                            FROM bao_hanh b JOIN kho w ON w.id = b.kho_id JOIN mat_hang i ON i.id = b.mat_hang_id JOIN don_vi_tinh u ON u.id = i.don_vi_tinh_id
                            JOIN nguoi_dung lu ON lu.id = b.nguoi_lap_id LEFT JOIN nguoi_dung xu ON xu.id = b.nguoi_xu_ly_id LEFT JOIN phieu_nhap_xuat v ON v.id = b.phieu_nhap_xuat_id
                            LEFT JOIN san_pham_ban sp ON sp.id = b.san_pham_ban_id';

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
            $where .= ' AND (LOWER(b.ma) LIKE ? OR LOWER(b.khach_ten) LIKE ? OR b.khach_sdt LIKE ? OR LOWER(i.ten) LIKE ? OR LOWER(i.ma_hang) LIKE ? OR LOWER(b.ma_lo) LIKE ? OR LOWER(sp.ma_mon) LIKE ?)';
            array_push($args, "%$q%", "%$q%", "%$q%", "%$q%", "%$q%", "%$q%", "%$q%");
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
    /**
     * Thu lại kết quả (bấm nhầm «Từ chối» / «Đã sửa xong»): phiếu quay về «Đang giữ» để ghi kết quả khác. Chỉ khi chưa trả khách.
     * «Đã đổi mới» không thu lại được vì đã trừ một đơn vị khỏi tồn bằng phiếu điều chỉnh (đảo phiếu đó là việc khác).
     */
    public function reopen(User $user, int $id): void
    {
        $peek = $this->find($id);
        $this->auth->require($user, (int)$peek['kho_id'], Permission::XuLyBaoHanh);
        if ($peek['trang_thai'] === 'DOI_MOI') {
            throw new DomainError('Hàng đã đổi mới nên đã trừ tồn kho (phiếu ' . ($peek['voucher_code'] ?? '') . ') — không thu lại kết quả được');
        }
        $n = $this->db->exec("UPDATE bao_hanh SET trang_thai = 'TIEP_NHAN', ket_qua = '', phieu_nhap_xuat_id = NULL, nguoi_xu_ly_id = NULL, ngay_xu_ly = NULL
                              WHERE id = ? AND trang_thai IN ('DA_SUA','TU_CHOI')", [$id]);
        if ($n !== 1) {
            throw new DomainError('Chỉ phiếu đã sửa xong hoặc bị từ chối, chưa trả khách, mới thu lại kết quả được');
        }
        $this->audit->record($user->id, 'warranty.reopen', 'warranty', $id, $peek['ma'] . ' · thu lại kết quả «' . (self::STATUS[$peek['trang_thai']][0] ?? $peek['trang_thai']) . '», quay về đang giữ');
    }

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
