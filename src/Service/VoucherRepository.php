<?php
declare(strict_types=1);

namespace StockFlow\Service;

use StockFlow\Core\Db;
use StockFlow\Domain\NotFound;
use StockFlow\Domain\VoucherType;

/** Truy cập dữ liệu phiếu nhập xuất dùng chung cho các service. */
final class VoucherRepository
{
    private const FIELDS = 'v.*, w.ma AS wh_code, w.ten AS wh_name, cw.ma AS cp_code, cw.ten AS cp_name,
                            cu.ho_ten AS creator_name, au.ho_ten AS approver_name,
                            (SELECT p.trang_thai FROM phieu_nhap_xuat p WHERE p.phieu_nhap_xuat_lien_quan_id = v.id AND p.loai = \'TRANSFER_IN\') AS pair_status';
    private const FROM = 'FROM phieu_nhap_xuat v JOIN kho w ON w.id = v.kho_id
                            LEFT JOIN kho cw ON cw.id = v.kho_doi_ung_id
                            JOIN nguoi_dung cu ON cu.id = v.nguoi_lap_id LEFT JOIN nguoi_dung au ON au.id = v.nguoi_xac_nhan_id';
    private const SELECT = 'SELECT ' . self::FIELDS . ' ' . self::FROM;

    public function __construct(private Db $db) {}

    public function find(int $id): array
    {
        return $this->db->one(self::SELECT . ' WHERE v.id = ?', [$id]) ?? throw new NotFound('Không tìm thấy phiếu nhập xuất');
    }

    public function findByKey(string $key): ?array
    {
        return $this->db->one(self::SELECT . ' WHERE v.khoa_chong_trung = ?', [$key]);
    }

    /** Khoá dòng phiếu nhập xuất (FOR UPDATE) để chuỗi hoá các thao tác xác nhận/huỷ/điều chỉnh. */
    public function lock(int $id): array
    {
        return $this->db->one('SELECT * FROM phieu_nhap_xuat WHERE id = ? FOR UPDATE', [$id]) ?? throw new NotFound('Không tìm thấy phiếu nhập xuất');
    }

    public function lines(int $voucherId): array
    {
        return $this->db->all(
            'SELECT l.*, i.ma_hang, i.ten, i.quan_ly_lo, u.ma AS unit, b.so_lo, b.han_dung
             FROM chi_tiet_phieu_nhap_xuat l JOIN mat_hang i ON i.id = l.mat_hang_id JOIN don_vi_tinh u ON u.id = i.don_vi_tinh_id JOIN lo_hang b ON b.id = l.lo_hang_id
             WHERE l.phieu_nhap_xuat_id = ? ORDER BY l.id',
            [$voucherId]
        );
    }

    public function correctionOf(int $id): ?array
    {
        return $this->db->one('SELECT id, ma FROM phieu_nhap_xuat WHERE dieu_chinh_cho_id = ?', [$id]);
    }

    public function pairOf(array $voucher): ?array
    {
        if ($voucher['loai'] === VoucherType::TransferIn->value && $voucher['phieu_nhap_xuat_lien_quan_id']) {
            return $this->db->one('SELECT id, ma FROM phieu_nhap_xuat WHERE id = ?', [$voucher['phieu_nhap_xuat_lien_quan_id']]);
        }
        if ($voucher['loai'] === VoucherType::TransferOut->value) {
            return $this->db->one('SELECT id, ma FROM phieu_nhap_xuat WHERE phieu_nhap_xuat_lien_quan_id = ? AND loai = ?', [$voucher['id'], VoucherType::TransferIn->value]);
        }
        return null;
    }

    /** Tạo dòng phiếu nhập xuất + trả về danh sách dòng kèm id (để ghi sổ). */
    public function insert(array $v, array $lines): int
    {
        $seq = $this->db->nextSequence('voucher');
        $type = VoucherType::from($v['loai']);
        $code = sprintf('%s-%s-%06d', $type->prefix(), gmdate('ymd'), $seq);
        $confirmed = ($v['trang_thai'] ?? 'DRAFT') === 'CONFIRMED';
        $id = $this->db->insert(
            'INSERT INTO phieu_nhap_xuat (ma, loai, trang_thai, kho_id, kho_doi_ung_id, phieu_nhap_xuat_lien_quan_id, dieu_chinh_cho_id, khoa_chong_trung,
                                   doi_tac, ly_do, ghi_chu, nguoi_lap_id, nguoi_xac_nhan_id, ngay_xac_nhan)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,' . ($confirmed ? 'CURRENT_TIMESTAMP' : 'NULL') . ')',
            [$code, $v['loai'], $v['trang_thai'] ?? 'DRAFT', $v['kho_id'], $v['kho_doi_ung_id'] ?? null, $v['phieu_nhap_xuat_lien_quan_id'] ?? null,
             $v['dieu_chinh_cho_id'] ?? null, $v['khoa_chong_trung'] ?? null, mb_substr($v['doi_tac'] ?? '', 0, 120),
             mb_substr($v['ly_do'] ?? '', 0, 255), mb_substr($v['ghi_chu'] ?? '', 0, 255), $v['nguoi_lap_id'], $confirmed ? $v['nguoi_lap_id'] : null]
        );
        foreach ($lines as $l) {
            $this->db->exec('INSERT INTO chi_tiet_phieu_nhap_xuat (phieu_nhap_xuat_id, mat_hang_id, lo_hang_id, so_luong, ghi_chu, don_gia, thue_suat, gia_von) VALUES (?,?,?,?,?,?,?,?)',
                [$id, $l['mat_hang_id'], $l['lo_hang_id'], $l['so_luong'], mb_substr($l['ghi_chu'] ?? '', 0, 255), $l['don_gia'] ?? null, (int)($l['thue_suat'] ?? 0), (int)($l['gia_von'] ?? 0)]);
        }
        return $id;
    }

    /** @return list<array{voucher_id:int,line_id:int,warehouse_id:int,item_id:int,batch_id:int,delta:int}> */
    public function ops(array $voucher, array $lines): array
    {
        $type = VoucherType::from($voucher['loai']);
        return array_map(fn(array $l) => [
            'phieu_nhap_xuat_id' => (int)$voucher['id'], 'line_id' => (int)$l['id'], 'kho_id' => (int)$voucher['kho_id'],
            'mat_hang_id' => (int)$l['mat_hang_id'], 'lo_hang_id' => (int)$l['lo_hang_id'],
            'delta' => \StockFlow\Domain\StockRules::delta($type, (int)$l['so_luong']),
        ], $lines);
    }

    public function lockKeys(array $ops): array
    {
        return array_map(fn($o) => [$o['kho_id'], $o['mat_hang_id'], $o['lo_hang_id']], $ops);
    }

    /** @return array{0:string,1:list<mixed>} */
    /**
     * $from / $to: ngày YYYY-MM-DD theo giờ Việt Nam (UTC+7), ngày_tạo lưu theo UTC.
     * $category: id danh mục (−1 = chưa phân loại) · $item: id mặt hàng — phiếu khớp nếu CÓ ÍT NHẤT MỘT dòng thoả, dù phiếu còn hàng khác.
     */
    private function searchFilter(array $warehouseIds, ?string $type, ?string $status, string $q, ?string $from = null, ?string $to = null, ?int $category = null, ?int $item = null): array
    {
        $in = implode(',', array_fill(0, count($warehouseIds), '?'));
        $where = ["v.kho_id IN ($in)"];
        $params = $warehouseIds;
        if ($type) { $where[] = 'v.loai = ?'; $params[] = $type; }
        if ($status) { $where[] = 'v.trang_thai = ?'; $params[] = $status; }
        if ($q !== '') { $where[] = '(LOWER(v.ma) LIKE ? OR LOWER(v.doi_tac) LIKE ?)'; $params[] = "%$q%"; $params[] = "%$q%"; }
        if ($from) { $where[] = 'v.ngay_tao >= ?'; $params[] = gmdate('Y-m-d H:i:s', strtotime("$from 00:00:00 UTC") - 7 * 3600); }
        if ($to) { $where[] = 'v.ngay_tao < ?'; $params[] = gmdate('Y-m-d H:i:s', strtotime("$to 00:00:00 UTC") + 17 * 3600); }
        if ($item) { $where[] = 'EXISTS (SELECT 1 FROM chi_tiet_phieu_nhap_xuat l WHERE l.phieu_nhap_xuat_id = v.id AND l.mat_hang_id = ?)'; $params[] = $item; }
        if ($category) {
            $where[] = 'EXISTS (SELECT 1 FROM chi_tiet_phieu_nhap_xuat l JOIN mat_hang i ON i.id = l.mat_hang_id WHERE l.phieu_nhap_xuat_id = v.id AND ' . ($category === -1 ? 'i.danh_muc_id IS NULL' : 'i.danh_muc_id = ?') . ')';
            if ($category !== -1) { $params[] = $category; }
        }
        return [implode(' AND ', $where), $params];
    }

    /** Tổng số phiếu nhập xuất khớp bộ lọc (để phân trang). */
    public function searchCount(array $warehouseIds, ?string $type, ?string $status, string $q, ?string $from = null, ?string $to = null, ?int $category = null, ?int $item = null): int
    {
        if ($warehouseIds === []) {
            return 0;
        }
        [$where, $params] = $this->searchFilter($warehouseIds, $type, $status, $q, $from, $to, $category, $item);
        return (int)$this->db->value('SELECT COUNT(*) ' . self::FROM . ' WHERE ' . $where, $params);
    }

    /** Tìm danh sách phiếu nhập xuất theo bộ lọc, chỉ trong phạm vi kho cho phép. */
    public function search(array $warehouseIds, ?string $type, ?string $status, string $q, int $limit = 200, int $offset = 0, ?string $from = null, ?string $to = null, bool $oldestFirst = false, ?int $category = null, ?int $item = null): array
    {
        if ($warehouseIds === []) {
            return [];
        }
        [$where, $params] = $this->searchFilter($warehouseIds, $type, $status, $q, $from, $to, $category, $item);
        // cột «Hàng hoá» ưu tiên dòng khớp bộ lọc hàng hoá (id đã ép int nên nhúng thẳng an toàn)
        $pref = $item ? '(l.mat_hang_id = ' . (int)$item . ') DESC, ' : ($category > 0 ? '(i.danh_muc_id = ' . (int)$category . ') DESC, ' : ($category === -1 ? '(i.danh_muc_id IS NULL) DESC, ' : ''));
        return $this->db->all(
            'SELECT ' . self::FIELDS . ', (SELECT COUNT(*) FROM chi_tiet_phieu_nhap_xuat l WHERE l.phieu_nhap_xuat_id = v.id) AS line_count,
                (SELECT COALESCE(SUM(ABS(l.so_luong)),0) FROM chi_tiet_phieu_nhap_xuat l WHERE l.phieu_nhap_xuat_id = v.id) AS total_qty,
                (SELECT i.ten FROM chi_tiet_phieu_nhap_xuat l JOIN mat_hang i ON i.id = l.mat_hang_id WHERE l.phieu_nhap_xuat_id = v.id ORDER BY ' . $pref . 'l.id LIMIT 1) AS first_item,
                (SELECT u.ma FROM chi_tiet_phieu_nhap_xuat l JOIN mat_hang i ON i.id = l.mat_hang_id JOIN don_vi_tinh u ON u.id = i.don_vi_tinh_id WHERE l.phieu_nhap_xuat_id = v.id ORDER BY ' . $pref . 'l.id LIMIT 1) AS first_unit,
                (SELECT l.so_luong FROM chi_tiet_phieu_nhap_xuat l JOIN mat_hang i ON i.id = l.mat_hang_id WHERE l.phieu_nhap_xuat_id = v.id ORDER BY ' . $pref . 'l.id LIMIT 1) AS first_qty ' . self::FROM . '
             WHERE ' . $where . ' ORDER BY v.ngay_tao ' . ($oldestFirst ? 'ASC, v.id ASC' : 'DESC, v.id DESC') . ' LIMIT ' . max(1, min(500, $limit)) . ' OFFSET ' . max(0, $offset),
            $params
        );
    }

    /** @return array<string,int> số phiếu theo trạng thái trong phạm vi kho */
    public function statusCounts(array $warehouseIds): array
    {
        if ($warehouseIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($warehouseIds), '?'));
        $out = [];
        foreach ($this->db->all("SELECT trang_thai, COUNT(*) AS n FROM phieu_nhap_xuat WHERE kho_id IN ($in) GROUP BY trang_thai", $warehouseIds) as $r) {
            $out[$r['trang_thai']] = (int)$r['n'];
        }
        return $out;
    }
}
