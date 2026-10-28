<?php
declare(strict_types=1);

namespace StockFlow\Service;

use StockFlow\Core\Db;
use StockFlow\Domain\DomainError;
use StockFlow\Domain\NotFound;
use StockFlow\Domain\Permission;
use StockFlow\Domain\User;

/**
 * Trả hàng không đạt chất lượng cho nhà cung cấp và nhận hàng cấp lại.
 *  - Phiếu nhập ghi số NHẬN và số KHÔNG ĐẠT từng dòng (nhap_loi); tồn chỉ cộng phần đạt (nhận − không đạt).
 *  - Khi phiếu nhập được ghi sổ mà có hàng không đạt ⇒ tự lập phiếu trả hàng (CHO_CAP_LAI).
 *  - Nhà cung cấp cấp lại ⇒ lập phiếu nhập mới «cấp lại cho phiếu trả X»; ghi sổ xong thì trừ vào số còn thiếu, đủ thì DA_CAP_LAI.
 */
final class SupplierReturnService
{
    public const REASONS = ['VO_HONG' => 'Vỡ / hỏng', 'SAI_QUY_CACH' => 'Sai quy cách / sai hàng', 'HET_HAN' => 'Hết hạn / cận hạn', 'LOI' => 'Lỗi nhà sản xuất', 'KHAC' => 'Khác'];
    public const STATUS = ['CHO_CAP_LAI' => ['Chờ cấp lại', 'warn'], 'DA_CAP_LAI' => ['Đã cấp lại đủ', 'ok'], 'HUY' => ['Không cấp lại', 'muted']];

    public function __construct(private Db $db, private Authorizer $auth, private AuditLog $audit) {}

    /** Gọi trong giao dịch ghi sổ phiếu nhập: lập phiếu trả hàng nếu có hàng không đạt. @return ?int id phiếu trả */
    public function createFromReceipt(User $user, array $voucher): ?int
    {
        $bad = $this->db->all('SELECT * FROM nhap_loi WHERE phieu_nhap_xuat_id = ? AND so_luong_loi > 0 ORDER BY id', [$voucher['id']]);
        if ($bad === []) {
            return null;
        }
        $seq = $this->db->nextSequence('supplier_return');
        $id = $this->db->insert('INSERT INTO tra_hang_ncc (ma, phieu_nhap_xuat_id, kho_id, doi_tac, nguoi_lap_id) VALUES (?,?,?,?,?)',
            [sprintf('TH-%s-%05d', gmdate('ymd'), $seq), $voucher['id'], $voucher['kho_id'], mb_substr((string)$voucher['doi_tac'], 0, 120), $user->id]);
        foreach ($bad as $b) {
            $this->db->exec('INSERT INTO chi_tiet_tra_hang (tra_hang_id, mat_hang_id, lo_hang_id, so_luong, ly_do, ghi_chu) VALUES (?,?,?,?,?,?)',
                [$id, $b['mat_hang_id'], $b['lo_hang_id'], $b['so_luong_loi'], $b['ly_do'], $b['ghi_chu']]);
        }
        $this->audit->record($user->id, 'return.create', 'return', $id, $voucher['ma'] . ' · ' . count($bad) . ' dòng không đạt');
        return $id;
    }

    /** Phiếu nhập «cấp lại» được ghi sổ: trừ phần đã nhận vào số còn thiếu của phiếu trả. Gọi trong giao dịch ghi sổ. */
    public function fulfil(array $voucher, array $lines): void
    {
        if (empty($voucher['cap_lai_cho_id'])) {
            return;
        }
        $rid = (int)$voucher['cap_lai_cho_id'];
        foreach ($lines as $l) {
            $left = (int)$l['so_luong'];
            foreach ($this->db->all('SELECT id, so_luong, so_luong_da_cap FROM chi_tiet_tra_hang WHERE tra_hang_id = ? AND mat_hang_id = ? AND so_luong_da_cap < so_luong ORDER BY id', [$rid, $l['mat_hang_id']]) as $t) {
                $take = min($left, (int)$t['so_luong'] - (int)$t['so_luong_da_cap']);
                if ($take > 0) {
                    $this->db->exec('UPDATE chi_tiet_tra_hang SET so_luong_da_cap = so_luong_da_cap + ? WHERE id = ?', [$take, $t['id']]);
                    $left -= $take;
                }
            }
        }
        if (!$this->db->value('SELECT 1 FROM chi_tiet_tra_hang WHERE tra_hang_id = ? AND so_luong_da_cap < so_luong', [$rid])) {
            $this->db->exec("UPDATE tra_hang_ncc SET trang_thai = 'DA_CAP_LAI', ngay_dong = CURRENT_TIMESTAMP WHERE id = ? AND trang_thai = 'CHO_CAP_LAI'", [$rid]);
        }
    }

    /** Kiểm tra phiếu nhập «cấp lại»: đúng kho, đúng nhà cung cấp, chỉ gồm hàng có trong phiếu trả, phiếu trả còn chờ. */
    public function assertReplacement(int $returnId, int $warehouseId, string $partner, array $lines): void
    {
        $t = $this->db->one('SELECT * FROM tra_hang_ncc WHERE id = ?', [$returnId]) ?? throw new DomainError('Phiếu trả hàng không tồn tại');
        if ($t['trang_thai'] !== 'CHO_CAP_LAI') {
            throw new DomainError("Phiếu trả hàng {$t['ma']} không còn chờ cấp lại");
        }
        if ((int)$t['kho_id'] !== $warehouseId) {
            throw new DomainError("Phiếu trả hàng {$t['ma']} thuộc kho khác");
        }
        if (trim($partner) !== $t['doi_tac']) {
            throw new DomainError("Hàng cấp lại phải đến từ nhà cung cấp «{$t['doi_tac']}»");
        }
        $items = array_map('intval', array_column($this->db->all('SELECT DISTINCT mat_hang_id FROM chi_tiet_tra_hang WHERE tra_hang_id = ?', [$returnId]), 'mat_hang_id'));
        foreach ($lines as $l) {
            if (!in_array((int)$l['mat_hang_id'], $items, true)) {
                throw new DomainError('Phiếu cấp lại chỉ được chứa mặt hàng có trong phiếu trả ' . $t['ma']);
            }
        }
    }

    public function find(int $id): array
    {
        $t = $this->db->one('SELECT t.*, w.ma AS wh_code, w.ten AS wh_name, w.dia_chi AS wh_addr, v.ma AS receipt_code, u.ho_ten AS creator_name
                             FROM tra_hang_ncc t JOIN kho w ON w.id = t.kho_id JOIN phieu_nhap_xuat v ON v.id = t.phieu_nhap_xuat_id JOIN nguoi_dung u ON u.id = t.nguoi_lap_id WHERE t.id = ?', [$id])
            ?? throw new NotFound('Không tìm thấy phiếu trả hàng');
        $t['lines'] = $this->db->all('SELECT c.*, i.ma_hang, i.ten, u.ma AS unit, b.so_lo, b.han_dung FROM chi_tiet_tra_hang c JOIN mat_hang i ON i.id = c.mat_hang_id
                                      JOIN don_vi_tinh u ON u.id = i.don_vi_tinh_id JOIN lo_hang b ON b.id = c.lo_hang_id WHERE c.tra_hang_id = ? ORDER BY c.id', [$id]);
        $t['replacements'] = $this->db->all("SELECT id, ma, trang_thai FROM phieu_nhap_xuat WHERE cap_lai_cho_id = ? AND trang_thai <> 'CANCELLED' ORDER BY id", [$id]);
        return $t;
    }

    public function list(array $warehouseIds, string $status = ''): array
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
        return $this->db->all("SELECT t.*, w.ma AS wh_code, v.ma AS receipt_code,
                                      (SELECT SUM(c.so_luong) FROM chi_tiet_tra_hang c WHERE c.tra_hang_id = t.id) AS qty,
                                      (SELECT SUM(c.so_luong_da_cap) FROM chi_tiet_tra_hang c WHERE c.tra_hang_id = t.id) AS got
                               FROM tra_hang_ncc t JOIN kho w ON w.id = t.kho_id JOIN phieu_nhap_xuat v ON v.id = t.phieu_nhap_xuat_id
                               WHERE $where ORDER BY t.id DESC LIMIT 500", $args);
    }

    /** @return array<string,int> */
    public function counts(array $warehouseIds): array
    {
        if ($warehouseIds === []) {
            return [];
        }
        $out = [];
        foreach ($this->db->all('SELECT trang_thai, COUNT(*) AS n FROM tra_hang_ncc WHERE kho_id IN (' . implode(',', array_fill(0, count($warehouseIds), '?')) . ') GROUP BY trang_thai', $warehouseIds) as $r) {
            $out[$r['trang_thai']] = (int)$r['n'];
        }
        return $out;
    }

    /** Số còn thiếu từng mặt hàng của phiếu trả (để điền sẵn phiếu nhập cấp lại). @return array<int,int> mặt hàng ⇒ số còn thiếu */
    public function outstanding(int $id): array
    {
        $out = [];
        foreach ($this->db->all('SELECT mat_hang_id, SUM(so_luong - so_luong_da_cap) AS n FROM chi_tiet_tra_hang WHERE tra_hang_id = ? GROUP BY mat_hang_id', [$id]) as $r) {
            if ((int)$r['n'] > 0) {
                $out[(int)$r['mat_hang_id']] = (int)$r['n'];
            }
        }
        return $out;
    }

    /** Nhà cung cấp không cấp lại nữa: đóng phiếu trả (còn phiếu cấp lại nháp thì phải huỷ trước). */
    public function close(User $user, int $id): void
    {
        $t = $this->find($id);
        $this->auth->require($user, (int)$t['kho_id'], Permission::XacNhanPhieu);
        if ($this->db->value("SELECT 1 FROM phieu_nhap_xuat WHERE cap_lai_cho_id = ? AND trang_thai = 'DRAFT'", [$id])) {
            throw new DomainError('Còn phiếu nhập cấp lại đang nháp — hãy xác nhận hoặc huỷ phiếu đó trước');
        }
        $n = $this->db->exec("UPDATE tra_hang_ncc SET trang_thai = 'HUY', ngay_dong = CURRENT_TIMESTAMP WHERE id = ? AND trang_thai = 'CHO_CAP_LAI'", [$id]);
        if ($n !== 1) {
            throw new DomainError('Phiếu trả hàng này không còn chờ cấp lại');
        }
        $this->audit->record($user->id, 'return.close', 'return', $id, $t['ma']);
    }
}
