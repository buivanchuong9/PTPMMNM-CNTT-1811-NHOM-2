<?php
declare(strict_types=1);

namespace StockFlow\Http\Controller;

use StockFlow\Core\Db;
use StockFlow\Core\Paginator;
use StockFlow\Core\Request;
use StockFlow\Core\Response;
use StockFlow\Core\Session;
use StockFlow\Core\View;
use StockFlow\Domain\Forbidden;
use StockFlow\Domain\Permission;
use StockFlow\Domain\StockRules;
use StockFlow\Domain\User;
use StockFlow\Service\Authorizer;
use StockFlow\Service\PartnerService;
use StockFlow\Service\StockQuery;
use StockFlow\Service\TransferRequestService;

/** «Yêu cầu chuyển hàng»: kho thiếu hàng xin kho khác chuyển sang; kho cấp chấp nhận (mở phiếu điều chuyển) hoặc từ chối kèm lý do. */
final class TransferRequestController extends Controller
{
    public function __construct(View $view, Session $session, Authorizer $auth, private TransferRequestService $requests, private StockQuery $query,
                                private Db $db, private PartnerService $partners)
    {
        parent::__construct($view, $session, $auth);
    }

    public function index(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::XemPhieu);
        $scope = $this->auth->visibleWarehouseIds($user);
        $status = array_key_exists($r->str('status'), TransferRequestService::LABELS) ? $r->str('status') : '';
        $counts = $this->requests->counts($scope);
        // bộ lọc: tìm · kho cấp · kho nhận · mặt hàng · thời gian · sắp xếp (lựa chọn lấy từ chính các yêu cầu đang có)
        $q = trim($r->str('q'));
        [$from, $to, $rangeSel] = range_params($r);
        $fromWh = (int)$r->int('from_wh');
        $toWh = (int)$r->int('to_wh');
        $item = (int)$r->int('item');
        $sort = $r->str('sort') === 'old' ? 'old' : 'new';
        $all = $this->requests->list($scope, $status ?: null);
        $lines = array_merge([], ...array_column($all, 'lines'));
        $list = array_filter($all, fn($x) => (!$fromWh || (int)$x['kho_cap_id'] === $fromWh) && (!$toWh || (int)$x['kho_nhan_id'] === $toWh)
            && (!$item || in_array($item, array_map('intval', array_column($x['lines'], 'mat_hang_id')), true)) && in_vn_range($x['ngay_tao'], $from, $to));
        $list = filter_rows(array_values($list), $q, fn($x) => implode(' ', [$x['ma'], $x['cap_ma'], $x['cap_ten'], $x['nhan_ma'], $x['nhan_ten'], $x['ghi_chu'], $x['lap_ten'] ?? '',
            ...array_map(fn($l) => $l['ma_hang'] . ' ' . $l['ten'] . ' ' . $l['so_lo'], $x['lines'])]));
        [$rows, $pg] = Paginator::of($sort === 'old' ? array_reverse($list) : $list, $r);
        $f = ['from_wh' => $fromWh, 'to_wh' => $toWh, 'item' => $item, 'sort' => $sort,
            'fromOpts' => options_of($all, 'kho_cap_id', fn($x) => $x['cap_ma'] . ' — ' . $x['cap_ten']),
            'toOpts' => options_of($all, 'kho_nhan_id', fn($x) => $x['nhan_ma'] . ' — ' . $x['nhan_ten']),
            'itemOpts' => options_of($lines, 'mat_hang_id', fn($l) => $l['ma_hang'] . ' — ' . $l['ten'])];
        $keep = keep_params(['q' => $q, 'from_wh' => $fromWh, 'to_wh' => $toWh, 'item' => $item, 'range' => $rangeSel, 'from' => $rangeSel === 'custom' ? $from : null, 'to' => $rangeSel === 'custom' ? $to : null, 'sort' => $sort === 'old' ? 'old' : null]);
        $this->requests->markSeen($user);   // lý do bị từ chối đã hiện trên trang này ⇒ bỏ số nhỏ trên menu
        return $this->render('requests/index', $user, 'requests', [
            'rows' => $rows, 'pg' => $pg, 'status' => $status, 'q' => $q, 'f' => $f, 'keep' => $keep, 'from' => $from, 'to' => $to, 'rangeSel' => $rangeSel,
            'counts' => $counts, 'total' => array_sum($counts),
            'canCreate' => $user->warehouseId !== null && $this->auth->allows($user, Permission::LapPhieuDieuChuyen),
            'isManager' => $this->auth->allows($user, Permission::XacNhanPhieu),
        ], 'Yêu cầu chuyển hàng');
    }

    public function create(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::LapPhieuDieuChuyen);
        $mine = $this->auth->warehouseOf($user) ?? throw new Forbidden('Chỉ tài khoản thuộc một kho mới lập được yêu cầu chuyển hàng');
        $others = $this->db->all('SELECT id, ma, ten, dia_chi FROM kho WHERE hoat_dong AND id <> ? ORDER BY ma', [$mine['id']]);
        $otherIds = array_map(fn($w) => (int)$w['id'], $others);
        $stock = $this->query->stockMap($otherIds);
        // Kho cấp hàng do nút «Yêu cầu chuyển hàng» ở trang Hàng hoá quyết định, không cho đổi để khỏi nhầm kho.
        $from = (int)$r->int('from');
        $source = null;
        foreach ($others as $w) {
            if ((int)$w['id'] === $from) {
                $source = $w;
            }
        }
        if ($source === null) {
            $this->session->flash('err', 'Hãy bấm «Yêu cầu chuyển hàng» ở kho cần lấy hàng trong trang Hàng hoá.');
            return Response::redirect('/inventory');
        }
        $by = $this->partners->suppliersByItem();
        $data = [
            'mine' => $mine, 'source' => $source, 'pick' => $this->query->pickList(), 'stock' => $stock, 'idem' => bin2hex(random_bytes(16)),
            'itemSuppliers' => array_map(fn($l) => array_column($l, 'id'), $by),
            'pickCats' => $this->db->all('SELECT id, ten FROM danh_muc WHERE hoat_dong ORDER BY ten'),
            'pickSups' => $this->db->all("SELECT id, ten FROM doi_tac WHERE loai = 'SUPPLIER' AND hoat_dong ORDER BY ten"),
        ];
        // Nút «Yêu cầu chuyển hàng» ở trang Hàng hoá: ?from=kho&item=mặt hàng&qty=số lượng ⇒ điền sẵn (điền sẵn dòng hàng, ẩn ô chọn hàng).
        $item = (int)$r->int('item');
        if ($item) {
            $left = max(1, (int)$r->int('qty'));
            $keys = $qtys = [];
            $han = [];   // lô hết hạn trước lấy trước
            foreach ($this->query->pickList() as $pl) {
                $han[$pl['mat_hang_id'] . ':' . $pl['lo_hang_id']] = $pl['han_dung'] ?? '9999-12-31';
            }
            $lots = $stock[$from] ?? [];
            uksort($lots, fn($a, $b) => strcmp((string)($han[$a] ?? '9999'), (string)($han[$b] ?? '9999')));
            foreach ($lots as $key => $have) {   // mặt hàng theo lô: lấy lần lượt các lô còn hàng cho đến khi đủ
                if ($left > 0 && str_starts_with((string)$key, $item . ':')) {
                    $keys[] = $key;
                    $qtys[] = $take = min($left, $have);
                    $left -= $take;
                }
            }
            if ($keys) {
                $data['prefill'] = array_map(null, $keys, $qtys);
                $data['lockPick'] = true;   // đã có hàng từ dòng được bấm — không cần chọn lại
            }
        }
        return $this->render('requests/form', $user, 'requests', $data, 'Yêu cầu chuyển hàng mới');
    }

    public function store(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::LapPhieuDieuChuyen);
        return $this->act(function () use ($r, $user) {
            $lines = StockRules::normalizeLines($r->arr('line'), $r->arr('qty'));
            $res = $this->requests->create($user, (int)$r->int('from_wh'), $lines, $r->str('note'), $this->idemKey($r));
            return '/requests';
        }, $this->auth->allows($user, Permission::XacNhanPhieu) ? 'Đã gửi yêu cầu đến kho cấp hàng.' : 'Đã lập yêu cầu — chờ quản lý kho của bạn xác nhận.', '/requests/new?from=' . (int)$r->int('from_wh'));
    }

    public function approve(Request $r, array $p, ?User $user): Response
    {
        $id = (int)$p['id'];
        return $this->act(fn() => $this->requests->approveOwn($user, $id), 'Đã xác nhận và gửi yêu cầu đến kho cấp hàng.', '/requests');
    }

    public function accept(Request $r, array $p, ?User $user): Response
    {
        $id = (int)$p['id'];
        return $this->act(fn() => '/vouchers/' . $this->requests->accept($user, $id),
            'Đã chấp nhận — phiếu điều chuyển đi (nháp) đã được tạo. Chọn xe, kiểm đếm lại số lượng rồi xuất kho.', '/requests');
    }

    public function reject(Request $r, array $p, ?User $user): Response
    {
        $id = (int)$p['id'];
        return $this->act(fn() => $this->requests->reject($user, $id, $r->str('reason')), 'Đã từ chối yêu cầu. Quản lý kho nhận sẽ thấy lý do.', '/requests');
    }

    public function cancel(Request $r, array $p, ?User $user): Response
    {
        $id = (int)$p['id'];
        return $this->act(fn() => $this->requests->cancel($user, $id, $r->str('reason')), 'Đã huỷ yêu cầu.', '/requests');
    }
}
