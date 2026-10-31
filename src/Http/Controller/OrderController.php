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
use StockFlow\Domain\Forbidden;
use StockFlow\Domain\Permission;
use StockFlow\Domain\StockRules;
use StockFlow\Domain\User;
use StockFlow\Service\Authorizer;
use StockFlow\Service\OrderService;
use StockFlow\Service\PartnerService;
use StockFlow\Service\StockQuery;

/** Đơn đặt hàng nhà cung cấp: quản lý kho lập và theo dõi, quản trị chỉ xem. */
final class OrderController extends Controller
{
    public function __construct(View $view, Session $session, Authorizer $auth, private OrderService $orders, private StockQuery $query, private PartnerService $partners, private Db $db)
    {
        parent::__construct($view, $session, $auth);
    }

    public function index(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::XemDonDatHang);
        $ids = $this->auth->visibleWarehouseIds($user);
        $status = isset(OrderService::STATUS[$r->str('status')]) ? $r->str('status') : '';
        $q = trim($r->str('q'));
        $suppliers = $this->orders->suppliers($ids);
        $supplier = in_array($r->str('supplier'), $suppliers, true) ? $r->str('supplier') : '';
        [$from, $to, $rangeSel] = range_params($r);
        $found = $this->orders->search($ids, $q, $supplier, $from, $to);
        $counts = array_count_values(array_column($found, 'trang_thai'));   // số trên từng tab theo đúng bộ lọc đang chọn
        [$rows, $pg] = Paginator::of($status === '' ? $found : array_values(array_filter($found, fn($o) => $o['trang_thai'] === $status)), $r);
        return $this->render('orders/index', $user, 'orders', ['rows' => $rows, 'pg' => $pg, 'status' => $status, 'q' => $q, 'counts' => $counts,
            'supplier' => $supplier, 'suppliers' => $suppliers, 'rangeSel' => $rangeSel, 'from' => $from, 'to' => $to,
            'canMake' => $this->auth->allows($user, Permission::LapDonDatHang) && $user->warehouseId !== null], 'Đặt hàng nhà cung cấp');
    }

    public function create(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::LapDonDatHang);
        if ($user->warehouseId === null) {
            throw new Forbidden('Chỉ tài khoản gắn với một kho mới lập đơn đặt hàng');
        }
        $pick = [];   // mỗi mặt hàng một dòng (đơn đặt theo mặt hàng, không theo lô)
        foreach ($this->query->pickList() as $pi) {
            if (!isset($pick[$pi['mat_hang_id']])) {
                $pick[$pi['mat_hang_id']] = ['quan_ly_lo' => 0, 'so_lo' => '', 'han_dung' => null] + $pi;
            }
        }
        $pick = array_values($pick);
        $prefill = [];
        $presetPartner = null;
        if (preg_match('/^(\d+):(\d{1,7})$/', $r->str('restock'), $m)) {   // từ cảnh báo «sắp hết hàng»
            foreach ($pick as $pi) {
                if ((int)$pi['mat_hang_id'] === (int)$m[1]) {
                    $prefill[] = [$pi['mat_hang_id'] . ':' . $pi['lo_hang_id'], (int)$m[2]];
                    $presetPartner = $this->db->one('SELECT d.ten FROM mat_hang_nha_cung_cap x JOIN doi_tac d ON d.id = x.doi_tac_id WHERE x.mat_hang_id = ? AND d.hoat_dong ORDER BY d.ten LIMIT 1', [(int)$m[1]])['ten'] ?? null;
                    break;
                }
            }
        }
        $by = $this->partners->suppliersByItem();
        return $this->render('orders/form', $user, 'orders', [
            'pick' => $pick, 'stock' => [], 'prefill' => $prefill, 'presetPartner' => $presetPartner, 'idem' => bin2hex(random_bytes(16)),
            'suppliers' => $this->db->all("SELECT id, ma, ten, nguoi_lien_he, so_dien_thoai, email, dia_chi FROM doi_tac WHERE loai = 'SUPPLIER' AND hoat_dong ORDER BY ten"),
            'orderInfo' => $this->orders->itemInfo((int)$user->warehouseId),
            'itemSuppliers' => array_map(fn($l) => array_column($l, 'id'), $by),
            'pickCats' => $this->db->all('SELECT id, ten FROM danh_muc WHERE hoat_dong ORDER BY ten'),
            'pickSups' => $this->db->all("SELECT id, ten FROM doi_tac WHERE loai = 'SUPPLIER' AND hoat_dong ORDER BY ten"),
        ], 'Lập đơn đặt hàng');
    }

    public function store(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::LapDonDatHang);
        return $this->act(function () use ($r, $user) {
            $lines = StockRules::normalizeLines($r->arr('line'), $r->arr('qty'));
            $merged = [];   // đơn theo mặt hàng: gộp các dòng cùng mặt hàng
            foreach ($lines as $l) {
                $merged[$l['mat_hang_id']] = ['mat_hang_id' => $l['mat_hang_id'], 'so_luong' => ($merged[$l['mat_hang_id']]['so_luong'] ?? 0) + $l['so_luong']];
            }
            $id = $this->orders->create($user, (int)$user->warehouseId, $r->str('partner'), array_values($merged), $r->str('expected') ?: null, $r->str('note'));
            return '/orders/' . $id;
        }, 'Đã lập đơn đặt hàng (nháp). Đánh dấu «Đã gửi» khi đã gửi cho nhà cung cấp.', '/orders/new');
    }

    public function show(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::XemDonDatHang);
        $o = $this->orders->find((int)$p['id']);
        $this->auth->requireVisible($user, (int)$o['kho_id']);
        return $this->render('orders/show', $user, 'orders', ['o' => $o,
            'canMake' => $this->auth->can($user, (int)$o['kho_id'], Permission::LapDonDatHang),
            'canReceive' => $this->auth->can($user, (int)$o['kho_id'], Permission::LapPhieuNhap)], 'Đơn ' . $o['ma']);
    }

    public function send(Request $r, array $p, ?User $user): Response
    {
        $id = (int)$p['id'];
        return $this->act(fn() => $this->orders->markSent($user, $id), 'Đã đánh dấu gửi nhà cung cấp. Khi hàng về, lập phiếu nhập từ đơn.', "/orders/$id");
    }

    public function close(Request $r, array $p, ?User $user): Response
    {
        $id = (int)$p['id'];
        return $this->act(fn() => $this->orders->close($user, $id), 'Đã đóng đơn.', "/orders/$id");
    }
}
