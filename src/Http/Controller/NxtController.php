<?php
declare(strict_types=1);

namespace StockFlow\Http\Controller;

use StockFlow\Core\Db;
use StockFlow\Core\Paginator;
use StockFlow\Core\Request;
use StockFlow\Core\Response;
use StockFlow\Core\Session;
use StockFlow\Core\View;
use StockFlow\Domain\NotFound;
use StockFlow\Domain\Permission;
use StockFlow\Domain\User;
use StockFlow\Service\Authorizer;

/**
 * Báo cáo Nhập – Xuất – Tồn theo kỳ và thẻ kho từng mặt hàng, tính từ sổ phát sinh (bất biến).
 * Tồn đầu = tổng biến động trước kỳ; Nhập / Chuyển đến / Xuất / Chuyển đi / Điều chỉnh = biến động trong kỳ theo loại phiếu; Tồn cuối = tồn đầu + biến động trong kỳ.
 */
final class NxtController extends Controller
{
    public function __construct(View $view, Session $session, Authorizer $auth, private Db $db)
    {
        parent::__construct($view, $session, $auth);
    }

    /** Kỳ báo cáo: mặc định từ đầu tháng tới hôm nay (giờ Việt Nam). @return array{0:string,1:string,2:string} [từ ngày, đến ngày, mốc chọn] */
    private function period(Request $r): array
    {
        [$from, $to, $sel] = range_params($r);
        $sel = $sel === '' ? 'custom' : $sel;   // kỳ mặc định (tháng này) hiện luôn ô từ ngày / đến ngày
        $today = gmdate('Y-m-d', time() + 7 * 3600);
        $from ??= gmdate('Y-m-01', strtotime(($to ?? $today) . ' UTC'));
        $to ??= $today;
        return [$from, $to, $sel];
    }

    /** Mốc UTC của đầu ngày giờ Việt Nam (sổ phát sinh lưu UTC). */
    private static function utc(string $vnDate): string
    {
        return gmdate('Y-m-d H:i:s', strtotime($vnDate . ' 00:00:00 UTC') - 7 * 3600);
    }

    /** @return list<int> các kho được xem theo bộ lọc */
    private function scope(User $user, int $wh): array
    {
        $ids = $this->auth->visibleWarehouseIds($user);
        return $wh && in_array($wh, $ids, true) ? [$wh] : $ids;
    }

    /** @return list<array<string,mixed>> */
    private function rows(array $scope, string $from, string $to, int $cat, string $q): array
    {
        if ($scope === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($scope), '?'));
        $a = self::utc($from);
        $b = self::utc(gmdate('Y-m-d', strtotime($to . ' UTC') + 86400));   // hết ngày «đến»
        $sql = "SELECT i.id, i.ma_hang, i.ten, u.ma AS unit, c.ten AS category,
                    COALESCE(SUM(CASE WHEN m.ngay_ghi_so < ? THEN m.bien_dong END), 0) AS opening,
                    COALESCE(SUM(CASE WHEN m.ngay_ghi_so >= ? AND v.loai = 'RECEIPT' THEN m.bien_dong END), 0) AS q_in,
                    COALESCE(SUM(CASE WHEN m.ngay_ghi_so >= ? AND v.loai = 'TRANSFER_IN' THEN m.bien_dong END), 0) AS q_xin,
                    COALESCE(SUM(CASE WHEN m.ngay_ghi_so >= ? AND v.loai = 'ISSUE' THEN -m.bien_dong END), 0) AS q_out,
                    COALESCE(SUM(CASE WHEN m.ngay_ghi_so >= ? AND v.loai = 'TRANSFER_OUT' THEN -m.bien_dong END), 0) AS q_xout,
                    COALESCE(SUM(CASE WHEN m.ngay_ghi_so >= ? AND v.loai = 'ADJUSTMENT' AND v.ly_do LIKE 'Khách trả hàng%' THEN m.bien_dong END), 0) AS q_ret,
                    COALESCE(SUM(CASE WHEN m.ngay_ghi_so >= ? AND v.loai = 'ADJUSTMENT' AND v.ly_do NOT LIKE 'Khách trả hàng%' THEN m.bien_dong END), 0) AS q_adj
                FROM so_phat_sinh m JOIN phieu_nhap_xuat v ON v.id = m.phieu_nhap_xuat_id
                JOIN mat_hang i ON i.id = m.mat_hang_id JOIN don_vi_tinh u ON u.id = i.don_vi_tinh_id LEFT JOIN danh_muc c ON c.id = i.danh_muc_id
                WHERE m.kho_id IN ($in) AND m.ngay_ghi_so < ?" . ($cat ? ' AND i.danh_muc_id = ?' : '') . '
                GROUP BY i.id, i.ma_hang, i.ten, u.ma, c.ten ORDER BY i.ma_hang';
        $args = [$a, $a, $a, $a, $a, $a, $a, ...$scope, $b];
        if ($cat) {
            $args[] = $cat;
        }
        $rows = [];
        foreach ($this->db->all($sql, $args) as $r) {
            foreach (['opening', 'q_in', 'q_xin', 'q_out', 'q_xout', 'q_ret', 'q_adj'] as $k) {
                $r[$k] = (int)$r[$k];
            }
            $r['closing'] = $r['opening'] + $r['q_in'] + $r['q_xin'] + $r['q_ret'] - $r['q_out'] - $r['q_xout'] + $r['q_adj'];
            if ($r['opening'] || $r['q_in'] || $r['q_xin'] || $r['q_out'] || $r['q_xout'] || $r['q_ret'] || $r['q_adj']) {
                $rows[] = $r;
            }
        }
        return filter_rows($rows, $q, fn($r) => $r['ma_hang'] . ' ' . $r['ten'] . ' ' . ($r['category'] ?? ''));
    }

    private function warehouses(array $ids): array
    {
        return $ids === [] ? [] : $this->db->all('SELECT id, ma, ten FROM kho WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY ma', $ids);
    }

    public function index(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::XemBaoCao);
        [$from, $to, $rangeSel] = $this->period($r);
        $wh = (int)$r->int('warehouse');
        $cat = (int)$r->int('category');
        $q = trim($r->str('q'));
        $all = $this->rows($this->scope($user, $wh), $from, $to, $cat, $q);
        if ($r->str('format') === 'csv') {
            return Response::csv('nhap-xuat-ton-' . $from . '-' . $to . '.csv', ['SKU', 'Tên hàng', 'Loại hàng', 'ĐVT', 'Tồn đầu', 'Nhập', 'Chuyển đến', 'Khách trả', 'Xuất', 'Chuyển đi', 'Điều chỉnh', 'Tồn cuối'],
                array_map(fn($x) => [$x['ma_hang'], $x['ten'], $x['category'] ?? '', $x['unit'], $x['opening'], $x['q_in'], $x['q_xin'], $x['q_ret'], $x['q_out'], $x['q_xout'], $x['q_adj'], $x['closing']], $all));
        }
        $tot = array_fill_keys(['opening', 'q_in', 'q_xin', 'q_ret', 'q_out', 'q_xout', 'q_adj', 'closing'], 0);
        foreach ($all as $x) {
            foreach ($tot as $k => $_) {
                $tot[$k] += $x[$k];
            }
        }
        [$rows, $pg] = Paginator::of($all, $r);
        $keep = keep_params(['q' => $q, 'warehouse' => $wh ?: null, 'category' => $cat ?: null, 'range' => $rangeSel === 'custom' ? null : $rangeSel,
            'from' => $from, 'to' => $to]);
        return $this->render('reports/nxt', $user, 'nxt', ['rows' => $rows, 'pg' => $pg, 'tot' => $tot, 'from' => $from, 'to' => $to, 'rangeSel' => $rangeSel, 'q' => $q,
            'wh' => $wh, 'cat' => $cat, 'warehouses' => $this->warehouses($this->auth->visibleWarehouseIds($user)),
            'categories' => $this->db->all('SELECT id, ten FROM danh_muc WHERE hoat_dong ORDER BY ten'), 'keep' => $keep], 'Nhập – Xuất – Tồn');
    }

    /** Thẻ kho: mọi biến động của một mặt hàng trong kỳ, kèm tồn đầu và tồn sau từng dòng. */
    public function card(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::XemBaoCao);
        $item = $this->db->one('SELECT i.id, i.ma_hang, i.ten, u.ma AS unit FROM mat_hang i JOIN don_vi_tinh u ON u.id = i.don_vi_tinh_id WHERE i.id = ?', [(int)$r->int('item')])
            ?? throw new NotFound('Không tìm thấy mặt hàng');
        [$from, $to, $rangeSel] = $this->period($r);
        $wh = (int)$r->int('warehouse');
        $scope = $this->scope($user, $wh);
        $lines = [];
        $opening = 0;
        if ($scope !== []) {
            $in = implode(',', array_fill(0, count($scope), '?'));
            $a = self::utc($from);
            $b = self::utc(gmdate('Y-m-d', strtotime($to . ' UTC') + 86400));
            $opening = (int)$this->db->value("SELECT COALESCE(SUM(bien_dong), 0) FROM so_phat_sinh WHERE mat_hang_id = ? AND kho_id IN ($in) AND ngay_ghi_so < ?", [$item['id'], ...$scope, $a]);
            $lines = $this->db->all("SELECT m.id, m.ngay_ghi_so, m.bien_dong, m.phieu_nhap_xuat_id, v.ma AS voucher_code, v.loai, v.doi_tac, v.ly_do, w.ma AS wh_code, b.so_lo
                                     FROM so_phat_sinh m JOIN phieu_nhap_xuat v ON v.id = m.phieu_nhap_xuat_id JOIN kho w ON w.id = m.kho_id JOIN lo_hang b ON b.id = m.lo_hang_id
                                     WHERE m.mat_hang_id = ? AND m.kho_id IN ($in) AND m.ngay_ghi_so >= ? AND m.ngay_ghi_so < ? ORDER BY m.ngay_ghi_so, m.id", [$item['id'], ...$scope, $a, $b]);
        }
        $run = $opening;
        foreach ($lines as &$l) {
            $l['bien_dong'] = (int)$l['bien_dong'];
            $run += $l['bien_dong'];
            $l['balance'] = $run;
        }
        unset($l);
        if ($r->str('format') === 'csv') {
            return Response::csv('the-kho-' . $item['ma_hang'] . '-' . $from . '-' . $to . '.csv', ['Thời gian', 'Phiếu', 'Loại', 'Kho', 'Lô', 'Đối tác / lý do', 'Nhập', 'Xuất', 'Tồn'],
                array_merge([['', 'Tồn đầu kỳ', '', '', '', '', '', '', $opening]], array_map(fn($l) => [$l['ngay_ghi_so'], $l['voucher_code'], TYPE_LABELS[$l['loai']][0] ?? $l['loai'], $l['wh_code'],
                    $l['so_lo'] === 'NOLOT' ? '' : $l['so_lo'], $l['doi_tac'] ?: $l['ly_do'], $l['bien_dong'] > 0 ? $l['bien_dong'] : '', $l['bien_dong'] < 0 ? -$l['bien_dong'] : '', $l['balance']], $lines)));
        }
        $keep = keep_params(['warehouse' => $wh ?: null, 'range' => $rangeSel === 'custom' ? null : $rangeSel, 'from' => $from, 'to' => $to]);
        return $this->render('reports/stock_card', $user, 'nxt', ['item' => $item, 'lines' => $lines, 'opening' => $opening, 'closing' => $run, 'from' => $from, 'to' => $to,
            'rangeSel' => $rangeSel, 'wh' => $wh, 'warehouses' => $this->warehouses($this->auth->visibleWarehouseIds($user)), 'keep' => $keep], 'Thẻ kho ' . $item['ma_hang']);
    }
}
