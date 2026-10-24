<?php
declare(strict_types=1);

namespace StockFlow\Service;

use StockFlow\Core\Db;
use StockFlow\Domain\User;

/** Truy vấn tồn / lịch sử / đối chiếu — số tồn luôn được TÍNH TỪ sổ phát sinh (so_phat_sinh). */
final class StockQuery
{
    public function __construct(private Db $db, private Authorizer $auth) {}

    private function in(array $ids): string
    {
        return implode(',', array_fill(0, count($ids), '?'));
    }

    /** Danh sách (hàng, lô) có thể chọn trong form. */
    public function pickList(): array
    {
        return $this->db->all(
            'SELECT i.id AS mat_hang_id, b.id AS lo_hang_id, i.ma_hang, i.ten, u.ma AS unit, i.quan_ly_lo, b.so_lo, b.han_dung
             FROM mat_hang i JOIN don_vi_tinh u ON u.id = i.don_vi_tinh_id JOIN lo_hang b ON b.mat_hang_id = i.id WHERE i.hoat_dong ORDER BY i.ma_hang, b.so_lo'
        );
    }

    /**
     * Tồn hiện có theo kho, đọc từ bảng ton_kho: [kho => ["hàng:lô" => tồn]]. Dùng để hiển thị và chặn nhập quá tồn ngay trong form.
     * @return array<int,array<string,int>>
     */
    public function stockMap(array $warehouseIds): array
    {
        if ($warehouseIds === []) {
            return [];
        }
        $out = [];
        foreach ($this->db->all(
            "SELECT kho_id, mat_hang_id, lo_hang_id, so_luong AS q FROM ton_kho WHERE kho_id IN ({$this->in($warehouseIds)}) AND so_luong > 0",
            $warehouseIds
        ) as $r) {
            $out[(int)$r['kho_id']][$r['mat_hang_id'] . ':' . $r['lo_hang_id']] = (int)$r['q'];
        }
        return $out;
    }

    /**
     * Các dòng đòi xuất nhiều hơn tồn hiện có trong ton_kho tại kho đó.
     * @param list<array{item_id:int,batch_id:int,qty:int}> $lines
     * @return list<string> thông báo lỗi, rỗng nếu mọi dòng đều trong mức tồn
     */
    public function shortages(int $warehouseId, array $lines): array
    {
        $errors = [];
        foreach ($lines as $l) {
            $row = $this->db->one(
                'SELECT i.ma_hang, b.so_lo, i.quan_ly_lo, COALESCE(t.so_luong, 0) AS q FROM lo_hang b JOIN mat_hang i ON i.id = b.mat_hang_id
                 LEFT JOIN ton_kho t ON t.kho_id = ? AND t.mat_hang_id = i.id AND t.lo_hang_id = b.id WHERE i.id = ? AND b.id = ?',
                [$warehouseId, $l['mat_hang_id'], $l['lo_hang_id']]
            );
            if ($row && (int)$row['q'] < (int)$l['so_luong']) {
                $errors[] = $row['ma_hang'] . ($row['quan_ly_lo'] ? ' (lô ' . $row['so_lo'] . ')' : '')
                    . ': kho chỉ còn ' . n((int)$row['q']) . ', bạn nhập ' . n((int)$l['so_luong']);
            }
        }
        return $errors;
    }

    /** Tồn theo (kho, hàng[, lô]) tính từ sổ phát sinh. */
    public function onHand(array $warehouseIds, string $search = '', bool $byBatch = false, bool $includeZero = false): array
    {
        if ($warehouseIds === []) {
            return [];
        }
        $batchCols = $byBatch ? ', b.id AS lo_hang_id, b.so_lo, b.han_dung' : '';
        $group = $byBatch ? ', b.id, b.so_lo, b.han_dung' : '';
        $rows = $this->db->all(
            "SELECT w.id AS kho_id, w.ma AS wh_code, i.id AS mat_hang_id, i.ma_hang, i.ten, i.dinh_muc_toi_thieu, i.quy_cach_lo, i.danh_muc_id, c.ten AS category, u.ma AS unit $batchCols,
                    SUM(m.bien_dong) AS so_luong, MAX(m.ngay_ghi_so) AS last_move
             FROM so_phat_sinh m JOIN kho w ON w.id = m.kho_id JOIN mat_hang i ON i.id = m.mat_hang_id
             JOIN don_vi_tinh u ON u.id = i.don_vi_tinh_id JOIN lo_hang b ON b.id = m.lo_hang_id LEFT JOIN danh_muc c ON c.id = i.danh_muc_id
             WHERE m.kho_id IN ({$this->in($warehouseIds)}) AND (LOWER(i.ma_hang) LIKE ? OR LOWER(i.ten) LIKE ?)
             GROUP BY w.id, w.ma, i.id, i.ma_hang, i.ten, i.dinh_muc_toi_thieu, i.quy_cach_lo, i.danh_muc_id, c.ten, u.ma $group
             " . ($includeZero ? '' : 'HAVING SUM(m.bien_dong) <> 0 ') . 'ORDER BY i.ma_hang, w.ma' . ($byBatch ? ', b.so_lo' : ''),
            [...$warehouseIds, "%$search%", "%$search%"]
        );
        foreach ($rows as &$r) {
            $r['so_luong'] = (int)$r['so_luong'];
        }
        return $rows;
    }

    /** Ma trận: mỗi mặt hàng một dòng, cột theo kho. @return list<array<string,mixed>> */
    public function matrix(array $warehouseIds, string $search): array
    {
        $by = [];
        foreach ($this->onHand($warehouseIds, $search) as $r) {
            $by[$r['mat_hang_id']] ??= ['mat_hang_id' => $r['mat_hang_id'], 'ma_hang' => $r['ma_hang'], 'ten' => $r['ten'], 'unit' => $r['unit'],
                                    'dinh_muc_toi_thieu' => (int)$r['dinh_muc_toi_thieu'], 'by_wh' => [], 'total' => 0];
            $by[$r['mat_hang_id']]['by_wh'][(int)$r['kho_id']] = $r['so_luong'];
            $by[$r['mat_hang_id']]['total'] += $r['so_luong'];
        }
        return array_values($by);
    }

    /** @return array{0:string,1:list<mixed>} mệnh đề WHERE + tham số của bộ lọc lịch sử */
    private function historyFilter(array $warehouseIds, array $f): array
    {
        $where = ['m.kho_id IN (' . $this->in($warehouseIds) . ')'];
        $params = $warehouseIds;
        if (!empty($f['warehouse'])) { $where[] = 'm.kho_id = ?'; $params[] = (int)$f['warehouse']; }
        if (!empty($f['item'])) { $where[] = 'm.mat_hang_id = ?'; $params[] = (int)$f['item']; }
        if (!empty($f['type'])) { $where[] = 'v.loai = ?'; $params[] = $f['type']; }
        if (!empty($f['q'])) { $where[] = '(LOWER(v.ma) LIKE ? OR LOWER(i.ma_hang) LIKE ? OR LOWER(i.ten) LIKE ? OR LOWER(b.so_lo) LIKE ?)'; array_push($params, ...array_fill(0, 4, '%' . $f['q'] . '%')); }
        if (!empty($f['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['from'])) { $where[] = 'm.ngay_ghi_so >= ?'; $params[] = gmdate('Y-m-d H:i:s', strtotime($f['from'] . ' 00:00:00 UTC') - 7 * 3600); }   // ngày theo giờ VN (UTC+7), sổ lưu UTC
        if (!empty($f['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['to'])) { $where[] = 'm.ngay_ghi_so < ?'; $params[] = gmdate('Y-m-d H:i:s', strtotime($f['to'] . ' 00:00:00 UTC') + 17 * 3600); }
        return [implode(' AND ', $where), $params];
    }

    private const HISTORY_FROM = 'FROM so_phat_sinh m JOIN phieu_nhap_xuat v ON v.id = m.phieu_nhap_xuat_id JOIN kho w ON w.id = m.kho_id
             JOIN mat_hang i ON i.id = m.mat_hang_id JOIN don_vi_tinh u ON u.id = i.don_vi_tinh_id JOIN lo_hang b ON b.id = m.lo_hang_id';

    /** Tổng số dòng phát sinh khớp bộ lọc (để phân trang). */
    public function historyCount(array $warehouseIds, array $f = []): int
    {
        if ($warehouseIds === []) {
            return 0;
        }
        [$where, $params] = $this->historyFilter($warehouseIds, $f);
        return (int)$this->db->value('SELECT COUNT(*) ' . self::HISTORY_FROM . ' WHERE ' . $where, $params);
    }

    /** Lịch sử phát sinh có lọc; chỉ trong phạm vi kho cho phép. */
    public function history(array $warehouseIds, array $f = [], int $limit = 200, int $offset = 0): array
    {
        if ($warehouseIds === []) {
            return [];
        }
        [$where, $params] = $this->historyFilter($warehouseIds, $f);
        return $this->db->all(
            'SELECT m.*, w.ma AS wh_code, i.ma_hang, i.ten, u.ma AS unit, b.so_lo, v.ma AS voucher_code, v.loai, v.ly_do, v.ghi_chu '
            . self::HISTORY_FROM . ' WHERE ' . $where . ' ORDER BY m.id ' . (($f['sort'] ?? '') === 'old' ? 'ASC' : 'DESC') . ' LIMIT ' . max(1, min(5000, $limit)) . ' OFFSET ' . max(0, $offset),
            $params
        );
    }

    /**
     * Đối chiếu: so sánh 3 nguồn — số dư (ton_kho), tổng sổ phát sinh, và tổng dòng phiếu nhập xuất đã xác nhận.
     * @return array{rows:list<array>,mismatches:int,checked:int,voucher_mismatches:list<array>}
     */
    public function reconcile(array $warehouseIds): array
    {
        if ($warehouseIds === []) {
            return ['rows' => [], 'mismatches' => 0, 'checked' => 0, 'voucher_mismatches' => []];
        }
        $in = $this->in($warehouseIds);
        $rows = $this->db->all(
            "SELECT k.kho_id, w.ma AS wh_code, k.mat_hang_id, i.ma_hang, k.lo_hang_id, b.so_lo,
                    COALESCE(sb.so_luong, 0) AS balance, COALESCE(sm.q, 0) AS ledger
             FROM (SELECT kho_id, mat_hang_id, lo_hang_id FROM ton_kho WHERE kho_id IN ($in)
                   UNION SELECT kho_id, mat_hang_id, lo_hang_id FROM so_phat_sinh WHERE kho_id IN ($in)) k
             LEFT JOIN ton_kho sb ON sb.kho_id = k.kho_id AND sb.mat_hang_id = k.mat_hang_id AND sb.lo_hang_id = k.lo_hang_id
             LEFT JOIN (SELECT kho_id, mat_hang_id, lo_hang_id, SUM(bien_dong) AS q FROM so_phat_sinh GROUP BY kho_id, mat_hang_id, lo_hang_id) sm
                    ON sm.kho_id = k.kho_id AND sm.mat_hang_id = k.mat_hang_id AND sm.lo_hang_id = k.lo_hang_id
             JOIN kho w ON w.id = k.kho_id JOIN mat_hang i ON i.id = k.mat_hang_id JOIN lo_hang b ON b.id = k.lo_hang_id
             ORDER BY w.ma, i.ma_hang, b.so_lo",
            [...$warehouseIds, ...$warehouseIds]
        );
        $mismatch = array_values(array_filter($rows, fn($r) => (int)$r['balance'] !== (int)$r['ledger']));
        // Mỗi dòng phiếu nhập xuất đã xác nhận phải có đúng một dòng sổ với biến động tương ứng.
        $voucherMismatch = $this->db->all(
            "SELECT v.ma, l.id AS line_id, l.so_luong, m.bien_dong FROM phieu_nhap_xuat v JOIN chi_tiet_phieu_nhap_xuat l ON l.phieu_nhap_xuat_id = v.id
             LEFT JOIN so_phat_sinh m ON m.chi_tiet_phieu_nhap_xuat_id = l.id
             WHERE v.trang_thai = 'CONFIRMED' AND v.kho_id IN ($in)
               AND (m.id IS NULL OR m.bien_dong <> CASE v.loai WHEN 'RECEIPT' THEN l.so_luong WHEN 'TRANSFER_IN' THEN l.so_luong WHEN 'ADJUSTMENT' THEN l.so_luong ELSE -l.so_luong END)
             LIMIT 100",
            $warehouseIds
        );
        return ['rows' => $rows, 'mismatches' => count($mismatch) + count($voucherMismatch), 'checked' => count($rows), 'voucher_mismatches' => $voucherMismatch];
    }

    /**
     * Xuất nhập từng kho trong N ngày gần nhất (từ sổ phát sinh): [kho_id => [nhap, xuat, chuyen_di, chuyen_den, dieu_chinh, so_phieu, vao, ra]].
     * nhap = phiếu nhập · xuat = phiếu xuất · chuyen_di/chuyen_den = điều chuyển · dieu_chinh = chênh lệch ròng; vao/ra = tổng vào / tổng ra của kho.
     * @return array<int,array<string,int>>
     */
    public function flowByWarehouse(array $warehouseIds, int $days = 30): array
    {
        if ($warehouseIds === []) {
            return [];
        }
        $since = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $out = [];
        foreach ($this->db->all(
            "SELECT m.kho_id, v.loai, SUM(ABS(m.bien_dong)) AS abs_qty, SUM(m.bien_dong) AS net_qty, COUNT(DISTINCT m.phieu_nhap_xuat_id) AS so_phieu
             FROM so_phat_sinh m JOIN phieu_nhap_xuat v ON v.id = m.phieu_nhap_xuat_id
             WHERE m.kho_id IN ({$this->in($warehouseIds)}) AND m.ngay_ghi_so >= ? GROUP BY m.kho_id, v.loai",
            [...$warehouseIds, $since]
        ) as $r) {
            $k = (int)$r['kho_id'];
            $out[$k] ??= ['nhap' => 0, 'xuat' => 0, 'chuyen_di' => 0, 'chuyen_den' => 0, 'dieu_chinh' => 0, 'so_phieu' => 0, 'vao' => 0, 'ra' => 0];
            $abs = (int)$r['abs_qty'];
            match ($r['loai']) {
                'RECEIPT' => $out[$k]['nhap'] += $abs,
                'ISSUE' => $out[$k]['xuat'] += $abs,
                'TRANSFER_OUT' => $out[$k]['chuyen_di'] += $abs,
                'TRANSFER_IN' => $out[$k]['chuyen_den'] += $abs,
                default => $out[$k]['dieu_chinh'] += (int)$r['net_qty'],
            };
            $out[$k]['so_phieu'] += (int)$r['so_phieu'];
        }
        foreach ($out as &$o) {
            $o['vao'] = $o['nhap'] + $o['chuyen_den'];
            $o['ra'] = $o['xuat'] + $o['chuyen_di'];
        }
        return $out;
    }

    /**
     * Tổng vào / ra mỗi ngày trong N ngày gần nhất (đủ cả ngày không phát sinh) cho biểu đồ đường.
     * @return list<array{ngay:string,vao:int,ra:int}>
     */
    public function dailyFlow(array $warehouseIds, int $days = 14): array
    {
        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $series[gmdate('Y-m-d', time() - $i * 86400)] = ['ngay' => gmdate('Y-m-d', time() - $i * 86400), 'vao' => 0, 'ra' => 0];
        }
        if ($warehouseIds === []) {
            return array_values($series);
        }
        foreach ($this->db->all(
            "SELECT DATE(m.ngay_ghi_so) AS ngay,
                    SUM(CASE WHEN v.loai IN ('RECEIPT','TRANSFER_IN') THEN ABS(m.bien_dong) ELSE 0 END) AS vao,
                    SUM(CASE WHEN v.loai IN ('ISSUE','TRANSFER_OUT') THEN ABS(m.bien_dong) ELSE 0 END) AS ra
             FROM so_phat_sinh m JOIN phieu_nhap_xuat v ON v.id = m.phieu_nhap_xuat_id
             WHERE m.kho_id IN ({$this->in($warehouseIds)}) AND m.ngay_ghi_so >= ? GROUP BY DATE(m.ngay_ghi_so)",
            [...$warehouseIds, array_key_first($series) . ' 00:00:00']
        ) as $r) {
            if (isset($series[$r['ngay']])) {
                $series[$r['ngay']]['vao'] = (int)$r['vao'];
                $series[$r['ngay']]['ra'] = (int)$r['ra'];
            }
        }
        return array_values($series);
    }

    public function dashboard(User $user): array
    {
        $ids = $this->auth->visibleWarehouseIds($user);
        if ($ids === []) {
            return ['warehouses' => [], 'units' => 0, 'drafts' => 0, 'confirmed_today' => 0, 'low' => [], 'recent' => [], 'pending' => [], 'flow' => [], 'daily' => [], 'voucher_status' => [], 'stock_status' => [], 'top' => []];
        }
        $in = $this->in($ids);
        $perWh = $this->db->all(
            "SELECT w.id, w.ma, w.ten, w.dia_chi, COALESCE(s.units,0) AS units, COALESCE(s.skus,0) AS skus,
                    (SELECT COUNT(*) FROM phieu_nhap_xuat v WHERE v.kho_id = w.id AND v.trang_thai = 'DRAFT') AS drafts
             FROM kho w LEFT JOIN (SELECT kho_id, SUM(bien_dong) AS units, COUNT(DISTINCT mat_hang_id) AS skus FROM so_phat_sinh GROUP BY kho_id) s ON s.kho_id = w.id
             WHERE w.id IN ($in) ORDER BY w.ma", $ids);
        $low = $this->db->all(
            "SELECT i.ma_hang, i.ten, i.dinh_muc_toi_thieu, u.ma AS unit, SUM(m.bien_dong) AS so_luong FROM mat_hang i JOIN don_vi_tinh u ON u.id = i.don_vi_tinh_id
             LEFT JOIN so_phat_sinh m ON m.mat_hang_id = i.id AND m.kho_id IN ($in)
             WHERE i.hoat_dong AND i.dinh_muc_toi_thieu > 0 GROUP BY i.id, i.ma_hang, i.ten, i.dinh_muc_toi_thieu, u.ma
             HAVING COALESCE(SUM(m.bien_dong),0) <= i.dinh_muc_toi_thieu ORDER BY COALESCE(SUM(m.bien_dong),0) - i.dinh_muc_toi_thieu LIMIT 6", $ids);
        $pending = $this->db->all(
            "SELECT v.id, v.ma, v.loai, v.ngay_tao, w.ma AS wh_code, cu.ho_ten AS creator_name,
                    (SELECT COUNT(*) FROM chi_tiet_phieu_nhap_xuat l WHERE l.phieu_nhap_xuat_id = v.id) AS line_count
             FROM phieu_nhap_xuat v JOIN kho w ON w.id = v.kho_id JOIN nguoi_dung cu ON cu.id = v.nguoi_lap_id
             WHERE v.trang_thai = 'DRAFT' AND v.kho_id IN ($in) ORDER BY v.id DESC LIMIT 6", $ids);
        $voucherStatus = ['DRAFT' => 0, 'CONFIRMED' => 0, 'CANCELLED' => 0];
        foreach ($this->db->all("SELECT trang_thai, COUNT(*) AS n FROM phieu_nhap_xuat WHERE kho_id IN ($in) GROUP BY trang_thai", $ids) as $r) {
            $voucherStatus[$r['trang_thai']] = (int)$r['n'];
        }
        $stockStatus = ['ok' => 0, 'low' => 0, 'out' => 0];   // còn hàng / sắp hết (≤ định mức) / hết hàng, tính theo mặt hàng
        foreach ($this->db->all(
            "SELECT i.dinh_muc_toi_thieu AS dm, SUM(m.bien_dong) AS q FROM mat_hang i JOIN so_phat_sinh m ON m.mat_hang_id = i.id AND m.kho_id IN ($in)
             WHERE i.hoat_dong GROUP BY i.id, i.dinh_muc_toi_thieu", $ids) as $r) {
            $q = (int)$r['q'];
            $stockStatus[$q <= 0 ? 'out' : ($q <= (int)$r['dm'] ? 'low' : 'ok')]++;
        }
        $top = $this->db->all(
            "SELECT i.ma_hang, i.ten, u.ma AS unit, SUM(m.bien_dong) AS so_luong FROM so_phat_sinh m JOIN mat_hang i ON i.id = m.mat_hang_id
             JOIN don_vi_tinh u ON u.id = i.don_vi_tinh_id WHERE m.kho_id IN ($in) AND i.hoat_dong
             GROUP BY i.id, i.ma_hang, i.ten, u.ma HAVING SUM(m.bien_dong) > 0 ORDER BY SUM(m.bien_dong) DESC LIMIT 6", $ids);
        return [
            'top' => $top,
            'voucher_status' => $voucherStatus,
            'stock_status' => $stockStatus,
            'warehouses' => $perWh,
            'units' => array_sum(array_column($perWh, 'units')),
            'drafts' => array_sum(array_column($perWh, 'drafts')),
            'confirmed_today' => (int)$this->db->value("SELECT COUNT(*) FROM phieu_nhap_xuat WHERE trang_thai = 'CONFIRMED' AND kho_id IN ($in) AND ngay_xac_nhan >= CURRENT_DATE", $ids),
            'low' => $low,
            'pending' => $pending,
            'recent' => $this->history($ids, [], 8),
            'flow' => $this->flowByWarehouse($ids, 30),
            'daily' => $this->dailyFlow($ids, 14),
        ];
    }
}
