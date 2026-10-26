<?php
declare(strict_types=1);

namespace StockFlow\Http\Controller;

use StockFlow\Core\Db;
use StockFlow\Core\Request;
use StockFlow\Core\Response;
use StockFlow\Core\Session;
use StockFlow\Core\View;
use StockFlow\Domain\DomainError;
use StockFlow\Domain\Permission;
use StockFlow\Domain\User;
use StockFlow\Service\Authorizer;
use StockFlow\Service\CatalogService;
use StockFlow\Service\ItemProposalService;

/**
 * Quét mã vạch. Ảnh/camera được đọc ngay trên trình duyệt — máy chủ chỉ nhận chuỗi mã, không bao giờ nhận hay lưu ảnh.
 * Mã đã có ⇒ trả thông tin mặt hàng để chọn nhập / xuất / điều chuyển; mã chưa có ⇒ quản lý kho gửi đề xuất mặt hàng mới cho quản trị duyệt.
 */
final class ScanController extends Controller
{
    public function __construct(View $view, Session $session, Authorizer $auth, private CatalogService $catalog, private Db $db, private ItemProposalService $proposals)
    {
        parent::__construct($view, $session, $auth);
    }

    private function json(array $data, int $status = 200): Response
    {
        return new Response(json_encode($data, JSON_UNESCAPED_UNICODE), $status, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public function lookup(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::XemTonKho, Permission::XemDanhMuc);
        try {
            $code = CatalogService::normalizeBarcode($r->str('code'));
        } catch (DomainError $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }
        if ($code === null) {
            return $this->json(['error' => 'Chưa có mã vạch'], 422);
        }
        $canAssign = $this->auth->allows($user, Permission::GanMaVach);
        $item = $this->catalog->itemByBarcode($code);
        if ($item === null) {
            $out = ['found' => false, 'code' => $code, 'canCreate' => $canAssign, 'pending' => null];
            if ($pend = $this->proposals->pendingByBarcode($code)) {   // đã có người đề xuất mã này, đang chờ quản trị
                $out['pending'] = ['sku' => $pend['ma_hang'], 'name' => $pend['ten'], 'kho' => $pend['kho_ma']];
            } elseif ($canAssign) {
                $out += [
                    'units' => $this->db->all('SELECT id, ma, ten FROM don_vi_tinh ORDER BY ma'),
                    'categories' => $this->db->all('SELECT id, ten FROM danh_muc WHERE hoat_dong ORDER BY ten'),
                    'suppliers' => $this->db->all("SELECT id, ma, ten FROM doi_tac WHERE loai = 'SUPPLIER' AND hoat_dong ORDER BY ten"),
                ];
            }
            return $this->json($out);
        }
        $id = (int)$item['id'];
        $mine = $user->warehouseId !== null ? (int)$this->db->value('SELECT COALESCE(SUM(bien_dong), 0) FROM so_phat_sinh WHERE mat_hang_id = ? AND kho_id = ?', [$id, $user->warehouseId]) : null;
        $total = (int)$this->db->value('SELECT COALESCE(SUM(bien_dong), 0) FROM so_phat_sinh WHERE mat_hang_id = ?', [$id]);
        $actions = [];
        if ($user->warehouseId !== null) {
            foreach ([
                ['Nhập hàng', "/vouchers/new?type=RECEIPT&item=$id", Permission::LapPhieuNhap],
                ['Xuất hàng', "/vouchers/new?type=ISSUE&item=$id", Permission::LapPhieuXuat],
                ['Điều chuyển', "/transfers/new?item=$id", Permission::LapPhieuDieuChuyen],
            ] as [$label, $href, $perm]) {
                if ($this->auth->allows($user, $perm)) {
                    $actions[] = ['label' => $label, 'href' => $href];
                }
            }
        }
        return $this->json([
            'found' => true, 'code' => $code, 'canAssign' => $canAssign, 'actions' => $actions,
            'item' => ['id' => $id, 'sku' => $item['ma_hang'], 'name' => $item['ten'], 'unit' => $item['unit'], 'category' => $item['category']],
            'stockMine' => $mine, 'stockTotal' => $total, 'viewHref' => '/inventory?q=' . rawurlencode($item['ma_hang']),
        ]);
    }

    /** Gán / đổi / gỡ mã vạch của một mặt hàng có sẵn (ô «mã vạch» để trống = gỡ mã). */
    public function setBarcode(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::GanMaVach);
        return $this->act(function () use ($r, $user) {
            $this->catalog->setBarcode($user, (int)$r->int('item_id'), $r->str('code'));
        }, $r->str('code') === '' ? 'Đã gỡ mã vạch của mặt hàng.' : 'Đã lưu mã vạch của mặt hàng.', '/inventory');
    }
}
