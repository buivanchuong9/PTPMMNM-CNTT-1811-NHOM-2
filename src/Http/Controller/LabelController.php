<?php
declare(strict_types=1);

namespace StockFlow\Http\Controller;

use StockFlow\Core\Db;
use StockFlow\Core\Paginator;
use StockFlow\Core\Request;
use StockFlow\Core\Response;
use StockFlow\Core\Session;
use StockFlow\Core\View;
use StockFlow\Domain\DomainError;
use StockFlow\Domain\Permission;
use StockFlow\Domain\User;
use StockFlow\Service\Authorizer;

/** In tem mã vạch cho mặt hàng và cho lô (số lô + hạn dùng in kèm). Hai khổ: tờ A4 chia ô (3×8) và tem cuộn 50×30 mm. */
final class LabelController extends Controller
{
    public const FORMATS = ['a4' => 'Tờ A4 chia ô — 3 cột × 8 hàng (70 × 37 mm)', 'thermal' => 'Tem cuộn nhiệt — 50 × 30 mm, mỗi tem một trang'];
    private const MAX_LABELS = 500;

    public function __construct(View $view, Session $session, Authorizer $auth, private Db $db)
    {
        parent::__construct($view, $session, $auth);
    }

    public function index(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::XemTonKho);
        $q = trim($r->str('q'));
        $cat = (int)$r->int('category');
        $sup = (int)$r->int('supplier');
        $code = in_array($r->str('code'), ['has', 'none'], true) ? $r->str('code') : '';       // đã / chưa gắn mã vạch (chưa gắn thì tem in mã theo SKU)
        $lot = in_array($r->str('lot'), ['lot', 'nolot'], true) ? $r->str('lot') : '';         // hàng quản lý theo lô (tem in kèm số lô, hạn dùng) hay không
        $items = $this->db->all('SELECT i.id, i.ma_hang, i.ten, i.ma_vach, i.quan_ly_lo, c.ten AS category FROM mat_hang i LEFT JOIN danh_muc c ON c.id = i.danh_muc_id
                                 WHERE i.hoat_dong' . ($cat ? ' AND i.danh_muc_id = ?' : '') . ($sup ? ' AND EXISTS (SELECT 1 FROM mat_hang_nha_cung_cap x WHERE x.mat_hang_id = i.id AND x.doi_tac_id = ?)' : '')
                                 . ($code === 'has' ? " AND i.ma_vach IS NOT NULL AND i.ma_vach <> ''" : ($code === 'none' ? " AND (i.ma_vach IS NULL OR i.ma_vach = '')" : ''))
                                 . ($lot === 'lot' ? ' AND i.quan_ly_lo = 1' : ($lot === 'nolot' ? ' AND i.quan_ly_lo = 0' : '')) . ' ORDER BY i.ma_hang', array_values(array_filter([$cat, $sup])));
        $items = filter_rows($items, $q, fn($i) => $i['ma_hang'] . ' ' . $i['ten'] . ' ' . ($i['ma_vach'] ?? '') . ' ' . ($i['category'] ?? ''));
        [$rows, $pg] = Paginator::of($items, $r);
        $lots = [];
        if ($rows) {
            $in = implode(',', array_fill(0, count($rows), '?'));
            foreach ($this->db->all("SELECT id, mat_hang_id, so_lo, han_dung FROM lo_hang WHERE mat_hang_id IN ($in) AND so_lo <> 'NOLOT' ORDER BY (han_dung IS NULL), han_dung, so_lo", array_column($rows, 'id')) as $l) {
                $lots[(int)$l['mat_hang_id']][] = $l;
            }
        }
        return $this->render('labels/index', $user, 'labels', ['rows' => $rows, 'pg' => $pg, 'lots' => $lots, 'q' => $q, 'cat' => $cat, 'sup' => $sup, 'code' => $code, 'lot' => $lot, 'formats' => self::FORMATS,
            'suppliers' => $this->db->all("SELECT id, ten FROM doi_tac WHERE loai = 'SUPPLIER' AND hoat_dong ORDER BY ten"),
            'categories' => $this->db->all('SELECT id, ten FROM danh_muc WHERE hoat_dong ORDER BY ten')], 'In tem mã vạch');
    }

    public function print(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::XemTonKho);
        $format = isset(self::FORMATS[$r->str('format')]) ? $r->str('format') : 'a4';
        $ids = array_values(array_unique(array_filter(array_map('intval', $r->arr('sel')))));
        if (!$ids) {
            throw new DomainError('Chưa chọn mặt hàng nào để in tem');
        }
        $lotOf = $r->arr('lot');
        $copies = $r->arr('n');
        $labels = [];
        foreach ($ids as $id) {
            $i = $this->db->one('SELECT i.id, i.ma_hang, i.ten, i.ma_vach FROM mat_hang i WHERE i.id = ? AND i.hoat_dong', [$id]);
            if (!$i) {
                continue;
            }
            $lotId = (int)($lotOf[$id] ?? 0);
            $lot = $lotId ? $this->db->one('SELECT so_lo, han_dung FROM lo_hang WHERE id = ? AND mat_hang_id = ?', [$lotId, $id]) : null;
            $count = max(1, min(100, (int)($copies[$id] ?? 1)));
            $content = ($i['ma_vach'] ?? '') !== '' ? (string)$i['ma_vach'] : (string)$i['ma_hang'];
            for ($k = 0; $k < $count && count($labels) < self::MAX_LABELS; $k++) {
                $labels[] = ['name' => $i['ten'], 'sku' => $i['ma_hang'], 'code' => $content, 'lot' => $lot['so_lo'] ?? null, 'exp' => $lot['han_dung'] ?? null];
            }
        }
        if (!$labels) {
            throw new DomainError('Không có mặt hàng hợp lệ để in tem');
        }
        return $this->view->render('labels/print', ['labels' => $labels, 'format' => $format, 'user' => $user], '');
    }
}
