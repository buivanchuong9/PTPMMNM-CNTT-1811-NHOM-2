<?php
declare(strict_types=1);

namespace StockFlow\Service;

use StockFlow\Core\Db;
use StockFlow\Domain\DomainError;
use StockFlow\Domain\NotFound;
use StockFlow\Domain\Permission;
use StockFlow\Domain\User;

/**
 * Đơn đặt hàng nhà cung cấp. Chỉ quản lý kho lập và theo dõi (chức năng «Lập đơn đặt hàng»); quản trị chỉ xem.
 *   NHAP → DA_GUI (đã gửi nhà cung cấp) → NHAN_MOT_PHAN → DA_NHAN (tự cập nhật khi phiếu nhập gắn với đơn được ghi sổ)
 *   DA_DONG = không nhận thêm (đóng khi còn thiếu) · HUY = huỷ trước khi nhận hàng.
 */
final class OrderService
{
    public const STATUS = [
        'NHAP' => ['Nháp', 'muted'], 'DA_GUI' => ['Đã gửi nhà cung cấp', 'info'], 'NHAN_MOT_PHAN' => ['Nhận một phần', 'warn'],
        'DA_NHAN' => ['Đã nhận đủ', 'ok'], 'DA_DONG' => ['Đã đóng (thiếu hàng)', 'muted'], 'HUY' => ['Đã huỷ', 'danger'],
    ];
    /** Đơn còn có thể nhận hàng. */
    public const RECEIVABLE = ['DA_GUI', 'NHAN_MOT_PHAN'];

    public function __construct(private Db $db, private Authorizer $auth, private AuditLog $audit) {}

    /** @param list<array{mat_hang_id:int,so_luong:int}> $lines */
    public function create(User $user, int $warehouseId, string $supplier, array $lines, ?string $expected, string $note): int
    {
        $this->auth->require($user, $warehouseId, Permission::LapDonDatHang);
        $supplier = trim($supplier);
        $note = trim($note);
        if (mb_strlen($note) > 255) {
            throw new DomainError('Ghi chú tối đa 255 ký tự');
        }
        if ($expected !== null && $expected !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expected) || $expected < gmdate('Y-m-d', time() + 7 * 3600))) {
            throw new DomainError('Ngày dự kiến nhận không được trước hôm nay');
        }
        $sup = $this->db->one("SELECT id, ten FROM doi_tac WHERE loai = 'SUPPLIER' AND hoat_dong AND ten = ?", [$supplier]) ?? throw new DomainError('Chọn nhà cung cấp');
        if ($lines === []) {
            throw new DomainError('Đơn chưa có mặt hàng nào');
        }
        $ok = array_map('intval', array_column($this->db->all('SELECT mat_hang_id FROM mat_hang_nha_cung_cap WHERE doi_tac_id = ?', [$sup['id']]), 'mat_hang_id'));
        foreach ($lines as $l) {
            if (!in_array((int)$l['mat_hang_id'], $ok, true)) {
                throw new DomainError('Mặt hàng ' . $this->db->value('SELECT ma_hang FROM mat_hang WHERE id = ?', [(int)$l['mat_hang_id']]) . ' không thuộc nhà cung cấp ' . $sup['ten']);
            }
        }
        return $this->db->transaction(function () use ($user, $warehouseId, $sup, $lines, $expected, $note) {
            if (!$this->db->value('SELECT 1 FROM kho WHERE id = ? AND hoat_dong', [$warehouseId])) {
                throw new DomainError('Kho không tồn tại hoặc đã ngừng hoạt động');
            }
            $seq = $this->db->nextSequence('purchase_order');
            $id = $this->db->insert('INSERT INTO don_dat_hang (ma, kho_id, doi_tac, ngay_du_kien, ghi_chu, nguoi_lap_id) VALUES (?,?,?,?,?,?)',
                [sprintf('DH-%s-%05d', gmdate('ymd'), $seq), $warehouseId, $sup['ten'], $expected ?: null, $note, $user->id]);
            foreach ($lines as $l) {
                $this->db->exec('INSERT INTO chi_tiet_don_dat_hang (don_id, mat_hang_id, so_luong) VALUES (?,?,?)', [$id, (int)$l['mat_hang_id'], (int)$l['so_luong']]);
            }
            $this->audit->record($user->id, 'order.create', 'order', $id, $sup['ten'] . ' · ' . count($lines) . ' mặt hàng');
            return $id;
        });
    }

    public function find(int $id): array
    {
        $o = $this->db->one('SELECT d.*, w.ma AS wh_code, w.ten AS wh_name, u.ho_ten AS creator_name FROM don_dat_hang d JOIN kho w ON w.id = d.kho_id JOIN nguoi_dung u ON u.id = d.nguoi_lap_id WHERE d.id = ?', [$id])
            ?? throw new NotFound('Không tìm thấy đơn đặt hàng');
        $o['lines'] = $this->db->all('SELECT c.*, i.ma_hang, i.ten, u.ma AS unit FROM chi_tiet_don_dat_hang c JOIN mat_hang i ON i.id = c.mat_hang_id JOIN don_vi_tinh u ON u.id = i.don_vi_tinh_id WHERE c.don_id = ? ORDER BY c.id', [$id]);
        $o['receipts'] = $this->db->all("SELECT id, ma, trang_thai FROM phieu_nhap_xuat WHERE don_dat_hang_id = ? AND trang_thai <> 'CANCELLED' ORDER BY id", [$id]);
        return $o;
    }

    public function list(array $warehouseIds, string $status = '', string $q = ''): array
    {
        if ($warehouseIds === []) {
            return [];
        }
        $where = 'd.kho_id IN (' . implode(',', array_fill(0, count($warehouseIds), '?')) . ')';
        $args = $warehouseIds;
        if (isset(self::STATUS[$status])) {
            $where .= ' AND d.trang_thai = ?';
            $args[] = $status;
        }
        $rows = $this->db->all("SELECT d.*, w.ma AS wh_code,
                                       (SELECT SUM(c.so_luong) FROM chi_tiet_don_dat_hang c WHERE c.don_id = d.id) AS qty,
                                       (SELECT SUM(LEAST(c.so_luong_da_nhan, c.so_luong)) FROM chi_tiet_don_dat_hang c WHERE c.don_id = d.id) AS got,
                                       (SELECT COUNT(*) FROM chi_tiet_don_dat_hang c WHERE c.don_id = d.id) AS line_count
                                FROM don_dat_hang d JOIN kho w ON w.id = d.kho_id WHERE $where ORDER BY d.id DESC LIMIT 500", $args);
        return filter_rows($rows, $q, fn($r) => $r['ma'] . ' ' . $r['doi_tac'] . ' ' . $r['ghi_chu']);
    }

    /** @return array<string,int> */
    public function counts(array $warehouseIds): array
    {
        if ($warehouseIds === []) {
            return [];
        }
        $out = [];
        foreach ($this->db->all('SELECT trang_thai, COUNT(*) AS n FROM don_dat_hang WHERE kho_id IN (' . implode(',', array_fill(0, count($warehouseIds), '?')) . ') GROUP BY trang_thai', $warehouseIds) as $r) {
            $out[$r['trang_thai']] = (int)$r['n'];
        }
        return $out;
    }

    /** Số còn thiếu từng mặt hàng của đơn (để điền sẵn phiếu nhập). @return array<int,int> mặt hàng ⇒ số còn thiếu */
    public function outstanding(int $id): array
    {
        $out = [];
        foreach ($this->db->all('SELECT mat_hang_id, so_luong - so_luong_da_nhan AS n FROM chi_tiet_don_dat_hang WHERE don_id = ?', [$id]) as $r) {
            if ((int)$r['n'] > 0) {
                $out[(int)$r['mat_hang_id']] = (int)$r['n'];
            }
        }
        return $out;
    }

    private function transition(User $user, int $id, array $from, string $to, string $audit, string $errorWhen): void
    {
        $o = $this->find($id);
        $this->auth->require($user, (int)$o['kho_id'], Permission::LapDonDatHang);
        $closing = in_array($to, ['DA_DONG', 'HUY'], true);
        $n = $this->db->exec('UPDATE don_dat_hang SET trang_thai = ?' . ($to === 'DA_GUI' ? ', ngay_gui = CURRENT_TIMESTAMP' : '') . ($closing ? ', ngay_dong = CURRENT_TIMESTAMP' : '')
            . ' WHERE id = ? AND trang_thai IN (' . implode(',', array_fill(0, count($from), '?')) . ')', [$to, $id, ...$from]);
        if ($n !== 1) {
            throw new DomainError($errorWhen);
        }
        $this->audit->record($user->id, $audit, 'order', $id, $o['ma']);
    }

    public function markSent(User $user, int $id): void
    {
        $this->transition($user, $id, ['NHAP'], 'DA_GUI', 'order.send', 'Đơn này đã gửi hoặc đã đóng');
    }

    /** Huỷ đơn chưa nhận hàng; đơn đã nhận một phần thì đóng (không nhận thêm). */
    public function close(User $user, int $id): void
    {
        $o = $this->find($id);
        if ($o['trang_thai'] === 'NHAN_MOT_PHAN') {
            $this->transition($user, $id, ['NHAN_MOT_PHAN'], 'DA_DONG', 'order.close', 'Đơn này đã đóng');
            return;
        }
        if ($this->db->value("SELECT 1 FROM phieu_nhap_xuat WHERE don_dat_hang_id = ? AND trang_thai = 'DRAFT'", [$id])) {
            throw new DomainError('Còn phiếu nhập nháp theo đơn này — hãy xác nhận hoặc huỷ phiếu đó trước');
        }
        $this->transition($user, $id, ['NHAP', 'DA_GUI'], 'HUY', 'order.cancel', 'Đơn này không huỷ được nữa');
    }

    /** Kiểm tra phiếu nhập gắn với đơn: đúng kho, đúng nhà cung cấp, đơn còn nhận được, chỉ gồm hàng có trong đơn. */
    public function assertReceipt(int $orderId, int $warehouseId, string $partner, array $lines): void
    {
        $o = $this->db->one('SELECT * FROM don_dat_hang WHERE id = ?', [$orderId]) ?? throw new DomainError('Đơn đặt hàng không tồn tại');
        if (!in_array($o['trang_thai'], self::RECEIVABLE, true)) {
            throw new DomainError("Đơn {$o['ma']} không còn nhận hàng (trạng thái: " . (self::STATUS[$o['trang_thai']][0] ?? $o['trang_thai']) . ')');
        }
        if ((int)$o['kho_id'] !== $warehouseId) {
            throw new DomainError("Đơn {$o['ma']} thuộc kho khác");
        }
        if (trim($partner) !== $o['doi_tac']) {
            throw new DomainError("Hàng theo đơn {$o['ma']} phải đến từ nhà cung cấp «{$o['doi_tac']}»");
        }
        $items = array_map('intval', array_column($this->db->all('SELECT mat_hang_id FROM chi_tiet_don_dat_hang WHERE don_id = ?', [$orderId]), 'mat_hang_id'));
        foreach ($lines as $l) {
            if (!in_array((int)$l['mat_hang_id'], $items, true)) {
                throw new DomainError('Phiếu nhập theo đơn chỉ được chứa mặt hàng có trong đơn ' . $o['ma']);
            }
        }
    }

    /** Phiếu nhập gắn với đơn được ghi sổ: cộng số đã nhận (phần dư so với đơn vẫn được ghi); đủ thì DA_NHAN, chưa đủ thì NHAN_MOT_PHAN. Gọi trong giao dịch ghi sổ. */
    public function fulfil(array $voucher, array $lines): void
    {
        if (empty($voucher['don_dat_hang_id'])) {
            return;
        }
        $oid = (int)$voucher['don_dat_hang_id'];
        foreach ($lines as $l) {
            $this->db->exec('UPDATE chi_tiet_don_dat_hang SET so_luong_da_nhan = so_luong_da_nhan + ? WHERE don_id = ? AND mat_hang_id = ?', [(int)$l['so_luong'], $oid, (int)$l['mat_hang_id']]);
        }
        $short = (bool)$this->db->value('SELECT 1 FROM chi_tiet_don_dat_hang WHERE don_id = ? AND so_luong_da_nhan < so_luong', [$oid]);
        $this->db->exec("UPDATE don_dat_hang SET trang_thai = ?, ngay_dong = " . ($short ? 'NULL' : 'CURRENT_TIMESTAMP') . " WHERE id = ? AND trang_thai IN ('DA_GUI','NHAN_MOT_PHAN')", [$short ? 'NHAN_MOT_PHAN' : 'DA_NHAN', $oid]);
    }
}
