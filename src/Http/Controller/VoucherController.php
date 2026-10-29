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
use StockFlow\Domain\NotFound;
use StockFlow\Domain\StockRules;
use StockFlow\Domain\Permission;
use StockFlow\Domain\User;
use StockFlow\Domain\VoucherType;
use StockFlow\Service\AuditLog;
use StockFlow\Service\Authorizer;
use StockFlow\Service\FleetService;
use StockFlow\Service\StockQuery;
use StockFlow\Service\TransferService;
use StockFlow\Service\VoucherRepository;
use StockFlow\Service\VoucherService;

final class VoucherController extends Controller
{
    public function __construct(View $view, Session $session, Authorizer $auth, private VoucherService $vouchers, private TransferService $transfers,
                                private VoucherRepository $repo, private StockQuery $query, private AuditLog $audit, private Db $db, private FleetService $fleet, private \StockFlow\Service\CatalogService $catalog, private \StockFlow\Service\SupplierReturnService $returns, private \StockFlow\Service\DeliveryService $deliveries, private \StockFlow\Service\TransportService $transport)
    {
        parent::__construct($view, $session, $auth);
    }

    public function index(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::XemPhieu);
        $ids = $this->auth->visibleWarehouseIds($user);
        $type = VoucherType::tryFrom($r->str('type'))?->value;
        $status = in_array($r->str('status'), ['DRAFT', 'CONFIRMED', 'CANCELLED'], true) ? $r->str('status') : null;
        $q = $r->search('q');
        $wh = $r->int('warehouse');
        $scope = $wh && in_array($wh, $ids, true) ? [$wh] : $ids;
        $date = fn(string $k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $r->str($k)) && checkdate((int)substr($r->str($k), 5, 2), (int)substr($r->str($k), 8, 2), (int)substr($r->str($k), 0, 4)) ? $r->str($k) : null;
        // mốc nhanh (range=today|yesterday|7d|1m|3m) thắng; 'custom' hoặc có from/to thì dùng ngày tự chọn
        $rangeSel = '';
        if ($preset = date_range($r->str('range'))) {
            [$from, $to] = $preset;
            $rangeSel = $r->str('range');
        } else {
            [$from, $to] = [$date('from'), $date('to')];
            if ($from && $to && $from > $to) {
                [$from, $to] = [$to, $from];
            }
            $rangeSel = ($r->str('range') === 'custom' || $from || $to) ? 'custom' : '';
        }
        $sort = $r->str('sort') === 'old' ? 'old' : 'new';
        // lọc theo hàng hoá: danh mục (id · 'none' = chưa phân loại) rồi mặt hàng; phiếu nào CÓ hàng đó là hiện, kể cả khi còn hàng khác
        $catRaw = $r->str('category');
        $category = $catRaw === 'none' ? -1 : ((int)$catRaw ?: null);
        $itemOptions = array_values(array_filter(
            $this->db->all('SELECT id, ma_hang, ten, danh_muc_id FROM mat_hang WHERE hoat_dong ORDER BY ma_hang'),
            fn($i) => $category === null || ($category === -1 ? !(int)$i['danh_muc_id'] : (int)$i['danh_muc_id'] === $category)
        ));
        $item = $r->int('item');
        $item = $item && in_array($item, array_map(fn($i) => (int)$i['id'], $itemOptions), true) ? $item : null;   // đổi danh mục thì bỏ mặt hàng không còn thuộc danh mục
        $pg = new Paginator($this->repo->searchCount($scope, $type, $status, $q, $from, $to, $category, $item), $r->int('page'));
        return $this->render('vouchers/index', $user, 'vouchers', [
            'rows' => $this->repo->search($scope, $type, $status, $q, $pg->per, $pg->offset, $from, $to, $sort === 'old', $category, $item), 'pg' => $pg,
            'catSel' => $category === -1 ? 'none' : (string)($category ?? ''), 'item' => $item, 'itemOptions' => $itemOptions,
            'categories' => $this->db->all('SELECT id, ten FROM danh_muc WHERE hoat_dong ORDER BY ten'),
            'from' => $from, 'to' => $to, 'sort' => $sort, 'rangeSel' => $rangeSel,
            'counts' => $this->repo->statusCounts($scope),
            'type' => $type, 'status' => $status, 'q' => $q, 'wh' => $wh,
            'warehouses' => $this->warehouseRows($ids),
        ], 'Phiếu');
    }

    public function create(Request $r, array $p, ?User $user): Response
    {
        $type = VoucherType::tryFrom($r->str('type')) ?? VoucherType::Receipt;
        if (!in_array($type, [VoucherType::Receipt, VoucherType::Issue], true)) {
            throw new NotFound('Loại phiếu không hợp lệ');
        }
        $perm = $type === VoucherType::Receipt ? Permission::LapPhieuNhap : Permission::LapPhieuXuat;
        $this->need($user, $perm);
        $ids = $this->warehouseIdsFor($user, $perm);
        $pick = $this->query->pickList();
        $replace = null;   // lập phiếu nhập hàng nhà cung cấp cấp lại cho một phiếu trả: điền sẵn nhà cung cấp và số còn thiếu
        $prefill = [];
        if ($type === VoucherType::Receipt && ($rid = $r->int('replace'))) {
            $replace = $this->returns->find($rid);
            if ($replace['trang_thai'] !== 'CHO_CAP_LAI' || !in_array((int)$replace['kho_id'], $ids, true)) {
                throw new DomainError('Phiếu trả hàng ' . $replace['ma'] . ' không còn chờ cấp lại hoặc thuộc kho khác');
            }
            foreach ($this->returns->outstanding($rid) as $itemId => $qty) {
                foreach ($pick as $pi) {
                    if ((int)$pi['mat_hang_id'] === $itemId) {
                        $prefill[] = [$itemId . ':' . ($pi['quan_ly_lo'] ? 'new' : $pi['lo_hang_id']), $qty];
                        break;
                    }
                }
            }
        }
        return $this->render('vouchers/form', $user, 'vouchers', [
            'type' => $type, 'warehouses' => $this->warehouseRows($ids), 'pick' => $pick, 'stock' => $this->query->stockMap($ids),
            'replace' => $replace, 'prefill' => $prefill,
            'idem' => bin2hex(random_bytes(16)), 'selected' => $replace ? (int)$replace['kho_id'] : $r->int('warehouse'), 'prefillItem' => $r->int('item'),
            'partnerNames' => array_column($partnerRows = $type === VoucherType::Receipt
                ? $this->db->all("SELECT ten, '' AS kho_ma, 0 AS mine FROM doi_tac WHERE loai = 'SUPPLIER' AND hoat_dong ORDER BY ten")
                : $this->db->all("SELECT d.ten, COALESCE(k.ma, '') AS kho_ma, (d.kho_id = ?) AS mine FROM doi_tac d LEFT JOIN kho k ON k.id = d.kho_id WHERE d.loai = 'CUSTOMER' AND d.hoat_dong" . ($user->isAdmin ? '' : ' AND (d.kho_id IS NULL OR d.kho_id = ' . (int)$user->warehouseId . ')') . ' ORDER BY mine DESC, d.ten', [(int)($user->warehouseId ?? 0)]), 'ten'),   // mỗi kho một danh sách khách riêng; quản trị thấy tất cả
            'partnerKho' => array_column($partnerRows, 'kho_ma', 'ten'),
            'suppliers' => $type === VoucherType::Receipt ? $this->db->all("SELECT id, ma, ten FROM doi_tac WHERE loai = 'SUPPLIER' AND hoat_dong ORDER BY ten") : [],
        ] + $this->pickFilterData(), $type->label() . ' mới');
    }

    /** Dữ liệu cho các ô lọc «Danh mục» / «Nhà cung cấp» phía trên ô tìm hàng của form phiếu. */
    private function pickFilterData(): array
    {
        $by = (new \StockFlow\Service\PartnerService($this->db, $this->auth, $this->audit))->suppliersByItem();
        return [
            'itemSuppliers' => array_map(fn($l) => array_column($l, 'id'), $by),
            'pickCats' => $this->db->all('SELECT id, ten FROM danh_muc WHERE hoat_dong ORDER BY ten'),
            'pickSups' => $this->db->all("SELECT id, ten FROM doi_tac WHERE loai = 'SUPPLIER' AND hoat_dong ORDER BY ten"),
        ];
    }

    public function store(Request $r, array $p, ?User $user): Response
    {
        $type = VoucherType::tryFrom($r->str('type')) ?? VoucherType::Receipt;
        $perm = $type === VoucherType::Receipt ? Permission::LapPhieuNhap : Permission::LapPhieuXuat;
        $this->need($user, $perm);
        $this->auth->require($user, (int)$r->int('warehouse_id'), $perm);   // kho ngoài phạm vi ⇒ 403 ngay, trước cả bước nhập khách hàng / kiểm tra tồn
        if ($type === VoucherType::Issue && ($step = $this->customerStep($r, $user)) !== null) {
            return $step;
        }
        return $this->act(function () use ($r, $user, $type) {
            $keys = $r->arr('line');
            $qtys = $r->arr('qty');
            if ($type === VoucherType::Issue) {   // «mặt hàng:auto» ⇒ tự chia lô theo hạn dùng
                [$keys, $qtys] = $this->query->expandAutoLots((int)$r->int('warehouse_id'), $keys, $qtys);
            }
            if ($type === VoucherType::Receipt) {   // hàng theo lô: «mặt hàng:new» + số lô + hạn dùng gõ trên dòng ⇒ tìm / tạo lô rồi đổi thành «mặt hàng:lô»
                $lotNos = $r->arr('lot_no');
                $exps = $r->arr('exp');
                foreach ($keys as $i => $key) {
                    if (is_scalar($key) && preg_match('/^(\d+):new$/', trim((string)$key), $m)) {
                        $keys[$i] = $m[1] . ':' . $this->catalog->ensureReceiptLot($user, (int)$m[1], is_scalar($lotNos[$i] ?? null) ? (string)$lotNos[$i] : '', is_scalar($exps[$i] ?? null) ? (string)$exps[$i] : '');
                    }
                }
            }
            $lines = StockRules::normalizeLines($keys, $qtys);
            $quality = $type === VoucherType::Receipt ? $this->readQuality($r, $keys) : [];
            if ($type === VoucherType::Issue) {
                $this->assertInStock((int)$r->int('warehouse_id'), $lines);
            }
            if ($type === VoucherType::Receipt) {
                $this->assertSupplierItems($r->str('partner'), $lines);
            }
            $partnerSvc = new \StockFlow\Service\PartnerService($this->db, $this->auth, $this->audit);
            if ($type === VoucherType::Issue) {
                $partnerSvc->requireNewCustomerInfo($r->str('partner'), $r->str('cust_contact'), $r->str('cust_phone'), $this->custAddress($r), $user->warehouseId);
            }
            $res = $this->vouchers->createDraft($user, $type, (int)$r->int('warehouse_id'), $lines, $r->str('partner'), $r->str('note'), $this->idemKey($r),
                $quality, $type === VoucherType::Receipt ? ($r->int('replace') ?: null) : null);
            if ($type === VoucherType::Issue && $res['created'] && $partnerSvc->ensureCustomer($user, $r->str('partner'), $r->str('cust_contact'), $r->str('cust_phone'), $this->custAddress($r))) {
                $this->session->flash('ok', 'Đã lưu «' . $r->str('partner') . '» vào danh sách khách hàng.');
            }
            if (!$res['created']) {
                $this->session->flash('ok', 'Yêu cầu này đã được ghi nhận trước đó — không tạo thêm phiếu.');
            }
            return '/vouchers/' . $res['voucher']['id'];
        }, 'Đã lập phiếu nháp. Kiểm tra rồi bấm Xác nhận để ghi sổ.', '/vouchers/new?type=' . $type->value);
    }

    /**
     * Kiểm tra chất lượng từng dòng phiếu nhập: bad[i] / bad_reason[i] / bad_note[i] đi song song với line[i]; dòng trùng hàng/lô được cộng dồn như normalizeLines.
     * @param array<int,mixed> $keys khoá «hàng:lô» đã đổi lô mới
     * @return array<string,array{loi:int,ly_do:string,ghi_chu:string}>
     */
    private function readQuality(Request $r, array $keys): array
    {
        $bad = $r->arr('bad');
        $reason = $r->arr('bad_reason');
        $note = $r->arr('bad_note');
        $out = [];
        foreach ($keys as $i => $key) {
            if (!is_scalar($key) || !preg_match('/^(\d+):(\d+)$/', trim((string)$key), $m)) {
                continue;
            }
            $raw = $bad[$i] ?? '';
            $n = is_scalar($raw) && trim((string)$raw) !== '' ? StockRules::parseQty($raw) : 0;
            if ($n === null) {
                throw new DomainError('Số lượng không đạt chất lượng phải là số nguyên không âm');
            }
            $k = $m[1] . ':' . $m[2];
            $out[$k] ??= ['loi' => 0, 'ly_do' => '', 'ghi_chu' => ''];
            $out[$k]['loi'] += $n;
            if ($n > 0) {
                $out[$k]['ly_do'] = is_scalar($reason[$i] ?? null) ? (string)$reason[$i] : $out[$k]['ly_do'];
                $out[$k]['ghi_chu'] = is_scalar($note[$i] ?? null) ? trim((string)$note[$i]) : $out[$k]['ghi_chu'];
            }
        }
        return $out;
    }

    /**
     * Phiếu xuất cho khách chưa có trong danh sách ⇒ chuyển sang «bước 2» nhập thông tin khách (giữ nguyên các dòng hàng đã chọn).
     * @return ?Response null nếu không cần bước 2
     */
    private function customerStep(Request $r, User $user): ?Response
    {
        $svc = new \StockFlow\Service\PartnerService($this->db, $this->auth, $this->audit);
        $name = $r->str('partner');
        if ($name === '' || $svc->known($name, $user->warehouseId)) {
            return null;
        }
        try {   // kiểm tra dòng hàng / tồn trước: phiếu sai thì báo ngay ở bước 1, không bắt nhập khách
            [$keys, $qtys] = $this->query->expandAutoLots((int)$r->int('warehouse_id'), $r->arr('line'), $r->arr('qty'));
            $lines = StockRules::normalizeLines($keys, $qtys);
            $this->assertInStock((int)$r->int('warehouse_id'), $lines);
        } catch (DomainError $e) {
            $this->session->flash('err', $e->getMessage());
            return Response::redirect('/vouchers/new?type=ISSUE');
        }
        try {
            $svc->requireNewCustomerInfo($name, $r->str('cust_contact'), $r->str('cust_phone'), $this->custAddress($r), $user->warehouseId);
            return null;   // đã đủ thông tin khách mới ⇒ cho lập phiếu
        } catch (DomainError $e) {
            if ($r->str('cust_contact') . $r->str('cust_phone') . $r->str('cust_address') . $r->str('street') . $r->str('ward') . $r->str('province') !== '') {
                $this->session->flash('err', $e->getMessage());
            }
            $page = $this->render('vouchers/customer_step', $user, 'vouchers', [
                'post' => $r->post, 'partner' => (string)preg_replace('/\s+/u', ' ', $name), 'lineCount' => count($lines), 'totalQty' => array_sum(array_column($lines, 'so_luong')),
            ], 'Phiếu xuất mới — bước 2');
            $this->session->rememberInput('/vouchers', $r->post);   // «Quay lại» bước 1 thì các dòng hàng đã chọn được điền lại
            return $page;
        }
    }

    /** Phiếu nhập từ một nhà cung cấp đã khai báo thì chỉ được chứa mặt hàng của nhà cung cấp đó. */
    private function assertSupplierItems(string $partner, array $lines): void
    {
        $sup = $this->db->one("SELECT id, ten FROM doi_tac WHERE loai = 'SUPPLIER' AND ten = ?", [trim($partner)]);
        if (!$sup) {
            return;
        }
        $ok = array_map('intval', array_column($this->db->all('SELECT mat_hang_id FROM mat_hang_nha_cung_cap WHERE doi_tac_id = ?', [$sup['id']]), 'mat_hang_id'));
        foreach ($lines as $l) {
            if (!in_array((int)$l['mat_hang_id'], $ok, true)) {
                $name = $this->db->value('SELECT ma_hang FROM mat_hang WHERE id = ?', [(int)$l['mat_hang_id']]);
                throw new DomainError("Mặt hàng $name không thuộc nhà cung cấp «{$sup['ten']}»");
            }
        }
    }

    public function show(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::XemPhieu);
        $v = $this->repo->find((int)$p['id']);
        $this->auth->requireVisible($user, (int)$v['kho_id']);
        $lines = $this->repo->lines((int)$v['id']);
        $check = $v['trang_thai'] === 'DRAFT' ? $this->vouchers->check($user, (int)$v['id']) : [];
        $movements = $v['trang_thai'] === 'CONFIRMED' ? $this->db->all('SELECT m.*, w.ma AS wh_code FROM so_phat_sinh m JOIN kho w ON w.id = m.kho_id WHERE m.phieu_nhap_xuat_id = ? ORDER BY m.id', [$v['id']]) : [];
        $isTransfer = in_array($v['loai'], ['TRANSFER_OUT', 'TRANSFER_IN'], true);
        $canApprove = $this->auth->can($user, (int)$v['kho_id'], $v['loai'] === 'TRANSFER_IN' ? Permission::NhanHangDieuChuyen : Permission::XacNhanPhieu);
        $createPerm = match ($v['loai']) {
            'RECEIPT' => Permission::LapPhieuNhap, 'ISSUE' => Permission::LapPhieuXuat, 'TRANSFER_OUT' => Permission::LapPhieuDieuChuyen, default => null,
        };
        return $this->render('vouchers/show', $user, 'vouchers', [
            'v' => $v, 'lines' => $lines, 'check' => $check, 'movements' => $movements,
            'quality' => $v['loai'] === 'RECEIPT' ? $this->repo->quality((int)$v['id']) : [],
            'returnNote' => $v['loai'] === 'RECEIPT' ? $this->db->one('SELECT id, ma, trang_thai FROM tra_hang_ncc WHERE phieu_nhap_xuat_id = ?', [(int)$v['id']]) : null,
            'replaces' => !empty($v['cap_lai_cho_id']) ? $this->db->one('SELECT id, ma FROM tra_hang_ncc WHERE id = ?', [(int)$v['cap_lai_cho_id']]) : null,
            'pair' => $this->repo->pairOf($v), 'correction' => $this->repo->correctionOf((int)$v['id']),
            'original' => $v['dieu_chinh_cho_id'] ? $this->repo->find((int)$v['dieu_chinh_cho_id']) : null,
            'trail' => $this->audit->forEntity('voucher', (int)$v['id']),
            'manager' => $this->db->one('SELECT ho_ten FROM nguoi_dung WHERE quan_ly_kho_id = ? AND hoat_dong', [(int)$v['kho_id']]),
            'canApprove' => $canApprove,
            'canDraft' => ($createPerm && $this->auth->can($user, (int)$v['kho_id'], $createPerm)) || $canApprove,
            // đảo điều chuyển cần quyền ở cả hai kho ⇒ chỉ tài khoản quản trị hệ thống
            'canAdjust' => $isTransfer ? $user->isAdmin : $this->auth->can($user, (int)$v['kho_id'], Permission::DieuChinhPhieu),
            'canCorrect' => $v['trang_thai'] === 'CONFIRMED' && $v['loai'] !== 'ADJUSTMENT' || ($v['trang_thai'] === 'CONFIRMED' && $v['loai'] === 'ADJUSTMENT' && !$v['dieu_chinh_cho_id']),
            'isTransfer' => $isTransfer,
            'trip' => $trip = $this->fleet->tripOf($v),
            'replies' => $trip ? $this->fleet->replies((int)$trip['phieu_den_id']) : [],
            // phiếu đến đã nhận thiếu: phiếu điều chỉnh ghi phần thiếu ở kho đến ⇒ số thực nhận từng dòng = số trên phiếu − phần thiếu
            'shortage' => $v['loai'] === 'TRANSFER_IN' && $v['trang_thai'] === 'CONFIRMED' ? $this->db->all(
                "SELECT l.mat_hang_id, l.lo_hang_id, -l.so_luong AS thieu, p.id AS adj_id, p.ma AS adj_ma FROM phieu_nhap_xuat p JOIN chi_tiet_phieu_nhap_xuat l ON l.phieu_nhap_xuat_id = p.id
                 WHERE p.phieu_nhap_xuat_lien_quan_id = ? AND p.loai = 'ADJUSTMENT'", [(int)$v['id']]) : [],
            'receipt' => $this->receiptOf($trip),
            'convoy' => $isTransfer ? $this->fleet->convoyOf((int)($v['loai'] === 'TRANSFER_OUT' ? $v['id'] : $v['phieu_nhap_xuat_lien_quan_id'])) : [],
            'breakdown' => $trip && $trip['trang_thai'] === 'IN_TRANSIT' ? $this->fleet->breakdownOf((int)$trip['id']) : null,
            'route' => $this->routeOf($v, $trip),
            'deliveryRoute' => $this->deliveryRouteOf($v),
            'load' => in_array($v['loai'], ['TRANSFER_OUT', 'TRANSFER_IN', 'ISSUE'], true) ? $load = $this->transport->loadOf($lines) : null,
            'vehicles' => $vehicles = in_array($v['loai'], ['TRANSFER_OUT', 'ISSUE'], true) && $v['trang_thai'] === 'DRAFT' ? $this->fleet->availableVehicles((int)$v['kho_id']) : [],
            'fit' => $fit = $vehicles && isset($load) ? $this->transport->fitMap($vehicles, $load, $v['loai'] === 'TRANSFER_OUT') : [],
            'recommended' => $fit ? $this->transport->recommend($vehicles, $fit, $v['loai'] === 'TRANSFER_OUT') : null,   // xe nhỏ nhất vẫn đủ chở: khuyến khích chọn
            'drivers' => $vehicles ? $drv = $this->fleet->availableDrivers((int)$v['kho_id']) : [],   // tài xế rảnh của kho: chọn riêng với xe
            'driverFit' => !empty($drv) ? $this->transport->driverFit($drv) : [],
            'payInfo' => $v['loai'] === 'TRANSFER_OUT' && $v['trang_thai'] === 'DRAFT'
                ? $this->fleet->distanceHint((int)$v['kho_id'], (int)$v['kho_doi_ung_id']) + ['rates' => $this->fleet->rates((int)$v['kho_id'])]
                : ($v['loai'] === 'ISSUE' && $v['trang_thai'] === 'DRAFT' ? ['km' => null, 'rates' => $this->fleet->rates((int)$v['kho_id'])] : null),
            'delivery' => $v['loai'] === 'ISSUE' && $v['trang_thai'] === 'CONFIRMED' ? $this->deliveries->of((int)$v['id']) : null,
            'custInfo' => $v['loai'] === 'ISSUE' && $v['trang_thai'] === 'DRAFT' && $v['doi_tac'] !== ''
                ? ($this->db->one("SELECT dia_chi, nguoi_lien_he, so_dien_thoai FROM doi_tac WHERE loai = 'CUSTOMER' AND ten = ?", [$v['doi_tac']]) ?? []) + ['ten' => $v['doi_tac']] : [],
        ], $v['ma']);
    }

    /**
     * Thực nhận tại kho đến của một chuyến đã giao (hiện ở cả phiếu đi lẫn phiếu đến, để kho gửi biết kho nhận nhận được bao nhiêu):
     * từng dòng = số trên phiếu − phần thiếu ghi ở phiếu điều chỉnh của phiếu đến.
     * @return ?array{rows:list<array<string,mixed>>,in_id:int,when:?string,by:?string,note:string}
     */
    /**
     * Lộ trình giao hàng (kho → địa chỉ khách) của phiếu xuất giao tận nơi bằng xe của kho hoặc xe thuê ngoài; null nếu khách tự lấy hoặc chưa xác định được vị trí.
     * Vị trí khách ở đây chỉ gần đúng theo tỉnh / thành (nếu kho và khách cùng tỉnh thì lệch nhẹ theo địa chỉ để thấy được đường đi); trình duyệt sẽ tìm lại chính xác theo địa chỉ.
     * @param array<string,mixed> $v
     */
    private function deliveryRouteOf(array $v): ?array
    {
        if ($v['loai'] !== 'ISSUE' || $v['trang_thai'] !== 'CONFIRMED') {
            return null;
        }
        $g = $this->deliveries->of((int)$v['id']);
        if (!$g || $g['hinh_thuc'] === 'TU_LAY' || trim((string)$g['dia_chi']) === '') {
            return null;
        }
        $wh = $this->db->one('SELECT ma, ten, dia_chi FROM kho WHERE id = ?', [(int)$v['kho_id']]);
        $from = $wh ? \StockFlow\Domain\VnAddress::locate($wh['dia_chi']) : null;
        $to = \StockFlow\Domain\VnAddress::locate((string)$g['dia_chi']);
        $known = $to !== null;   // địa chỉ cũ gõ tay, không nhận ra tỉnh: giao quanh khu vực kho (demo), không tìm trên bản đồ để khỏi ra nhầm chỗ xa
        $to ??= $from;
        if (!$from || !$to) {
            return null;
        }
        if (abs($from['lat'] - $to['lat']) < 0.02 && abs($from['lng'] - $to['lng']) < 0.02) {
            $h = crc32((string)$g['dia_chi']);
            $shift = fn(int $n) => (($n % 1000) / 1000 - 0.5) * 0.16;   // ±0,08° ≈ ±9 km quanh trung tâm tỉnh
            [$dy, $dx] = [$shift($h), $shift(intdiv($h, 1000))];
            $to['lat'] += abs($dy) < 0.03 ? ($dy < 0 ? -0.03 : 0.03) : $dy;
            $to['lng'] += abs($dx) < 0.03 ? ($dx < 0 ? -0.03 : 0.03) : $dx;
        }
        $me = ['code' => $wh['ma'], 'name' => $wh['ten'], 'lat' => $from['lat'], 'lng' => $from['lng']];
        ['ward' => $ward, 'province' => $province] = \StockFlow\Domain\VnAddress::parse((string)$g['dia_chi']);
        return ['mode' => 'delivery', 'from' => $me, 'all' => [$me],
                'to' => ['code' => 'KH', 'name' => (string)$g['nguoi_nhan'], 'addr' => (string)$g['dia_chi'], 'lat' => $to['lat'], 'lng' => $to['lng'], 'query' => $known ? $g['dia_chi'] . ', Việt Nam' : '',
                         'ward' => $ward, 'wardQuery' => $known && $ward !== '' && $province !== '' ? "$ward, $province, Việt Nam" : '', 'seed' => crc32((string)$g['dia_chi'])],   // điểm giao demo: chọn ngẫu nhiên (cố định theo địa chỉ) trong phường
                'arrived' => !empty($g['ngay_giao_den']),
                'km' => (int)$g['km'], 'plate' => (string)($g['bien_so'] ?? ''), 'kind' => $g['kieu_xe'] ?? 'TAI',
                'trucks' => [['plate' => (string)($g['bien_so'] ?? ''), 'kind' => $g['kieu_xe'] ?? 'TAI', 'me' => true, 'state' => 'moving', 'at' => 1, 'why' => '']]];
    }

    /** Lộ trình minh hoạ kho đi → kho đến của chuyến đang chạy; null nếu không phải đang vận chuyển hoặc không xác định được vị trí kho. */
    private function routeOf(array $v, ?array $trip): ?array
    {
        if (!$trip || !in_array($trip['trang_thai'], ['IN_TRANSIT', 'RECALLED'], true) || empty($v['kho_doi_ung_id'])) {
            return null;
        }
        [$fromId, $toId] = $v['loai'] === 'TRANSFER_OUT' ? [(int)$v['kho_id'], (int)$v['kho_doi_ung_id']] : [(int)$v['kho_doi_ung_id'], (int)$v['kho_id']];
        $ends = [];
        foreach ([$fromId, $toId] as $id) {
            $w = $this->db->one('SELECT ma, ten, dia_chi FROM kho WHERE id = ?', [$id]);
            $at = $w ? \StockFlow\Domain\VnAddress::locate($w['dia_chi']) : null;
            if (!$at) {
                return null;
            }
            $ends[] = ['code' => $w['ma'], 'name' => $w['ten'], 'lat' => $at['lat'], 'lng' => $at['lng']];
        }
        $all = [];   // mọi kho đang hoạt động có vị trí nhận ra được — kho mới thêm tự hiện trên bản đồ
        foreach ($this->db->all('SELECT ma, ten, dia_chi FROM kho WHERE hoat_dong ORDER BY ma') as $w) {
            if ($at = \StockFlow\Domain\VnAddress::locate($w['dia_chi'])) {
                $all[] = ['code' => $w['ma'], 'name' => $w['ten'], 'lat' => $at['lat'], 'lng' => $at['lng']];
            }
        }
        $outId = (int)($v['loai'] === 'TRANSFER_OUT' ? $v['id'] : $v['phieu_nhap_xuat_lien_quan_id']);
        $trucks = [];   // xe nối đuôi nhau theo thứ tự trong đợt; xe bị thu hồi chạy ngược về kho đi; xe mất hàng không còn trên bản đồ
        foreach ($this->fleet->convoyOf($outId) as $row) {
            if (in_array($row['trang_thai'], ['IN_TRANSIT', 'DELIVERED', 'RECALLED'], true)) {
                $trucks[] = ['plate' => $row['bien_so'], 'me' => (int)$row['chuyen_id'] === (int)$trip['id'],
                             'state' => match (true) { $row['trang_thai'] === 'DELIVERED' => 'arrived', $row['trang_thai'] === 'RECALLED' => 'returning', $row['hong_ly_do'] !== null => 'broken', default => 'moving' },
                             'at' => $row['hong_ly_do'] !== null ? (int)$row['hong_tien_do'] / 100 : 1, 'why' => (string)$row['hong_ly_do']];
            }
        }
        if ($trucks === []) {
            $b = $this->fleet->breakdownOf((int)$trip['id']);
            $trucks[] = ['plate' => $trip['bien_so'], 'me' => true, 'state' => $trip['trang_thai'] === 'RECALLED' ? 'returning' : ($b ? 'broken' : 'moving'), 'at' => $b ? (int)$b['tien_do'] / 100 : 1, 'why' => (string)($b['ly_do'] ?? '')];
        }
        return ['from' => $ends[0], 'to' => $ends[1], 'all' => $all, 'km' => (int)$trip['km'], 'plate' => $trip['bien_so'], 'trucks' => $trucks];
    }

    private function receiptOf(?array $trip): ?array
    {
        if (!$trip || $trip['trang_thai'] !== 'DELIVERED') {
            return null;
        }
        $inId = (int)$trip['phieu_den_id'];
        $lack = [];
        foreach ($this->db->all("SELECT l.mat_hang_id, l.lo_hang_id, -l.so_luong AS thieu FROM phieu_nhap_xuat p JOIN chi_tiet_phieu_nhap_xuat l ON l.phieu_nhap_xuat_id = p.id
                                 WHERE p.phieu_nhap_xuat_lien_quan_id = ? AND p.loai = 'ADJUSTMENT'", [$inId]) as $s) {
            $k = $s['mat_hang_id'] . ':' . $s['lo_hang_id'];
            $lack[$k] = ($lack[$k] ?? 0) + (int)$s['thieu'];
        }
        $rows = [];
        foreach ($this->repo->lines($inId) as $l) {
            $expected = (int)$l['so_luong'];
            $short = $lack[$l['mat_hang_id'] . ':' . $l['lo_hang_id']] ?? 0;
            $rows[] = ['ma_hang' => $l['ma_hang'], 'ten' => $l['ten'], 'unit' => $l['unit'], 'so_lo' => $l['so_lo'], 'expected' => $expected, 'got' => $expected - $short, 'short' => $short];
        }
        return ['rows' => $rows, 'in_id' => $inId, 'when' => $trip['ngay_nhan'], 'by' => $trip['nhan_ten'], 'note' => (string)$trip['sai_lech']];
    }

    public function confirm(Request $r, array $p, ?User $user): Response
    {
        $id = (int)$p['id'];
        return $this->act(function () use ($r, $user, $id) {
            $v = $this->repo->find($id);
            if ($v['loai'] === 'TRANSFER_OUT') {   // xuất kho đi: bắt buộc xe + tài xế + tài xế xác nhận đủ hàng
                $extra = [];   // xe phụ cùng chở: extra[id xe][id dòng] = số lượng
                foreach ($r->arr('extra') as $vid => $byLine) {
                    foreach (is_array($byLine) ? $byLine : [] as $lineId => $val) {
                        $q = trim((string)$val) === '' ? 0 : StockRules::parseQty((string)$val);
                        if ($q === null) {
                            throw new DomainError('Số lượng chia cho xe phải là số nguyên không âm');
                        }
                        $extra[(int)$vid][(int)$lineId] = $q;
                    }
                }
                $extraDrivers = [];   // tài xế của từng xe phụ: extra_driver[id xe] = id tài xế
                foreach ($r->arr('extra_driver') as $vid => $did) {
                    $extraDrivers[(int)$vid] = (int)$did;
                }
                $res = $this->transfers->dispatchConvoy($user, $id, (int)$r->int('vehicle_id'), $extra, $r->str('driver_ok') === '1', [
                    'hinh_thuc' => $r->str('hinh_thuc') === 'THUE_NGOAI' ? 'THUE_NGOAI' : 'NOI_BO',
                    'km' => StockRules::parseQty($r->str('km')) ?? 0, 'tien' => \StockFlow\Service\PricingService::parseMoney($r->str('tien')) ?? -1, 'ghi_chu' => $r->str('gia_note'),
                    'ngoai' => $this->external($r),
                ], (int)$r->int('driver_id') ?: null, $extraDrivers);
            } elseif ($v['loai'] === 'TRANSFER_IN') {   // nhận hàng: kho đến kiểm tra hàng đủ + đúng tài xế
                $received = null;   // nhập số thực nhận từng dòng: received[id dòng] = số lượng
                if ($raw = $r->arr('received')) {
                    $received = [];
                    foreach ($raw as $lineId => $val) {
                        $q = StockRules::parseQty($val);
                        if ($q === null) {
                            throw new DomainError('Số lượng thực nhận phải là số nguyên không âm');
                        }
                        $received[(int)$lineId] = $q;
                    }
                }
                $res = $this->transfers->receiveChecked($user, $id, $received, $r->str('driver_match') === '1', $r->str('short_reason'), $r->str('short_note'));
                if (!$res['already'] && $res['short']) {
                    $this->session->flash('ok', 'Đã nhận hàng — nhận thiếu ở ' . count($res['short']) . ' dòng, phần thiếu đã được ghi bằng phiếu điều chỉnh ' . ($res['adjustment']['ma'] ?? '') . ' và báo cho quản trị.');
                    return "/vouchers/$id/print?fresh=1";
                }
            } else {
                $delivery = $v['loai'] === 'ISSUE' && $r->str('hinh_thuc') !== '' ? [   // xuất bán: khách tự lấy hoặc giao hàng (xe kho / thuê ngoài)
                    'hinh_thuc' => $r->str('hinh_thuc'), 'thanh_toan' => $r->str('thanh_toan'), 'phuong_thuc_tt' => $r->str('pt_tt'), 'dia_chi' => $this->deliveryAddress($r), 'nguoi_nhan' => $r->str('giao_nguoi_nhan'), 'sdt_nhan' => $r->str('giao_sdt'), 'vehicle_id' => (int)$r->int('vehicle_id'), 'driver_id' => (int)$r->int('driver_id'), 'driver_ok' => $r->str('driver_ok') === '1',
                    'km' => StockRules::parseQty($r->str('km')) ?? 0, 'tien' => \StockFlow\Service\PricingService::parseMoney($r->str('tien')) ?? -1, 'ghi_chu' => $r->str('gia_note'),
                    'ngoai' => $this->external($r),
                ] : null;
                $res = $this->vouchers->confirm($user, $id, $delivery);
                if ($delivery !== null && $delivery['hinh_thuc'] !== 'TU_LAY' && $r->str('save_cust') === '1' && (string)$v['doi_tac'] !== '') {   // «Lưu địa chỉ cho khách»: lần sau chọn khách là tự điền
                    (new \StockFlow\Service\PartnerService($this->db, $this->auth, $this->audit))->rememberDelivery($user, (string)$v['doi_tac'], (string)$delivery['dia_chi'], (string)$delivery['nguoi_nhan'], (string)$delivery['sdt_nhan']);
                }
            }
            if ($res['already']) {
                $this->session->flash('ok', 'Phiếu đã được xác nhận trước đó — sổ phát sinh không ghi thêm.');
                return null;
            }
            if (!empty($res['split'])) {   // chia nhiều xe: phiếu nháp gốc đã tách thành mỗi xe một phiếu
                $this->session->flash('ok', 'Đã xuất kho bằng ' . count($res['split']) . ' xe — mỗi xe một phiếu, xem các xe cùng đợt ở cột bên phải.');
                return '/vouchers/' . (int)$res['voucher']['id'];
            }
            return "/vouchers/$id/print?fresh=1";   // vừa ghi sổ ⇒ mở luôn phiếu để in và ký
        }, 'Đã xác nhận và ghi sổ phát sinh.', "/vouchers/$id");
    }

    /** Phiếu in (A4) để ký sau khi ghi sổ: nhập / xuất / điều chuyển. Phiếu nháp hoặc đã huỷ chưa có giá trị chứng từ nên không in. */
    public function print(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::XemPhieu);
        $v = $this->repo->find((int)$p['id']);
        $this->auth->requireVisible($user, (int)$v['kho_id']);
        if ($v['trang_thai'] !== 'CONFIRMED' || $v['loai'] === 'ADJUSTMENT') {
            $this->session->flash('err', 'Chỉ in được phiếu nhập, xuất, điều chuyển đã xác nhận và ghi sổ.');
            return Response::redirect('/vouchers/' . (int)$v['id']);
        }
        $wh = $this->db->one('SELECT ma, ten, dia_chi FROM kho WHERE id = ?', [(int)$v['kho_id']]);
        $partner = in_array($v['loai'], ['RECEIPT', 'ISSUE'], true) && $v['doi_tac'] !== ''
            ? $this->db->one('SELECT ten, nguoi_lien_he, so_dien_thoai, dia_chi FROM doi_tac WHERE ten = ?', [$v['doi_tac']]) : null;
        return $this->view->render('vouchers/print', [
            'v' => $v, 'lines' => $this->withCost($this->repo->lines((int)$v['id'])), 'wh' => $wh, 'partner' => $partner, 'fresh' => $r->str('fresh') === '1',
            'trip' => $trip = $this->fleet->tripOf($v), 'receipt' => $this->receiptOf($trip), 'user' => $user,
            'delivery' => $v['loai'] === 'ISSUE' ? $this->deliveries->of((int)$v['id']) : null,
            'quality' => $v['loai'] === 'RECEIPT' ? $this->repo->quality((int)$v['id']) : [],
            'returnNote' => $v['loai'] === 'RECEIPT' ? $this->db->one('SELECT id, ma FROM tra_hang_ncc WHERE phieu_nhap_xuat_id = ?', [(int)$v['id']]) : null,
            'replaces' => !empty($v['cap_lai_cho_id']) ? $this->db->one('SELECT id, ma FROM tra_hang_ncc WHERE id = ?', [(int)$v['cap_lai_cho_id']]) : null,
        ], '');
    }

    /** Dòng điều chuyển cũ (lập trước khi lưu giá) chưa có đơn giá ⇒ dùng giá gốc hiện hành của mặt hàng. */
    private function withCost(array $lines): array
    {
        foreach ($lines as &$l) {
            if ($l['don_gia'] === null) {
                $item = $this->db->one('SELECT thue_suat, gia_von FROM mat_hang WHERE id = ?', [(int)$l['mat_hang_id']]);
                $l['don_gia'] = (int)($item['gia_von'] ?? 0);
                $l['thue_suat'] = (int)($item['thue_suat'] ?? 0);
            }
        }
        return $lines;
    }

    /** Kho đến báo CHƯA NHẬN ĐƯỢC HÀNG: chọn lý do, không cộng tồn, báo cho tài khoản cấp cao ở trang Đội xe. */
    public function notReceived(Request $r, array $p, ?User $user): Response
    {
        $id = (int)$p['id'];
        return $this->act(function () use ($r, $user, $id) {
            $this->transfers->reportNotReceived($user, $id, $r->str('missing_reason'), $r->str('missing_note'));
        }, 'Đã báo chưa nhận được hàng. Tồn kho chưa thay đổi — quản trị hệ thống sẽ thấy chuyến này ở trang Đội xe.', "/vouchers/$id");
    }

    /** Kho đi thu hồi chuyến khi xe còn trên đường (kho đến chưa nhận): cần lý do; hàng cộng lại kho đi, xe quay về. */
    public function recall(Request $r, array $p, ?User $user): Response
    {
        $id = (int)$p['id'];
        return $this->act(function () use ($r, $user, $id) {
            $why = $r->str('reason');
            if (!in_array($why, \StockFlow\Service\TransferService::RECALL_REASONS, true)) {
                throw new DomainError('Chọn lý do thu hồi chuyến');
            }
            $note = trim($r->str('note'));
            $this->transfers->recall($user, $this->convoyTarget($r, $id), $why . ($note !== '' ? ' — ' . $note : ''));
        }, 'Đã thu hồi chuyến: hàng được cộng lại vào kho đi, xe quay về kho đi, phiếu nhận ở kho đến đã bị huỷ.', "/vouchers/$id");
    }

    /** Phiếu đi của xe được chọn trong đợt (ô «xe nào» ở các khối báo hỏng / chạy tiếp / thu hồi): mặc định chính phiếu đang xem; phải cùng đợt. */
    private function convoyTarget(Request $r, int $id): int
    {
        $target = (int)($r->int('target') ?? 0);
        if ($target === 0 || $target === $id) {
            return $id;
        }
        foreach ($this->fleet->convoyOf($id) as $c) {
            if ((int)$c['phieu_di_id'] === $target) {
                return $target;
            }
        }
        throw new DomainError('Xe được chọn không thuộc đợt điều chuyển này');
    }

    /** Địa chỉ khách hàng mới: ghép từ ba ô có gợi ý (số nhà · phường / xã · tỉnh / thành); form cũ chỉ có một ô thì dùng ô đó. */
    private function custAddress(Request $r): string
    {
        if ($r->str('street') . $r->str('ward') . $r->str('province') !== '') {
            return \StockFlow\Domain\VnAddress::composeLoose($r->str('street'), $r->str('ward'), $r->str('province'));
        }
        return $r->str('cust_address');
    }

    /** Km tự tính từ kho tới địa chỉ giao đang chọn trên form (JSON), cố định theo địa chỉ; km = null nếu chưa chọn đủ tỉnh / phường. */
    public function deliveryKm(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::XemPhieu);
        $v = $this->repo->find((int)$p['id']);
        $this->auth->requireVisible($user, (int)$v['kho_id']);
        $km = null;
        try {
            $addr = \StockFlow\Domain\VnAddress::compose($r->str('street'), $r->str('ward'), $r->str('province'));
            $km = \StockFlow\Domain\VnAddress::deliveryKm((string)$this->db->value('SELECT dia_chi FROM kho WHERE id = ?', [(int)$v['kho_id']]), $addr);
        } catch (DomainError) {
            // chưa chọn đủ địa chỉ: chưa tính
        }
        $nearer = null;   // khách ở xa kho này mà có kho khác gần hơn nhiều ⇒ nên xuất từ kho đó (đỡ tiền công, đỡ thời gian)
        if ($km !== null && isset($addr)) {
            foreach ($this->db->all('SELECT ma, ten, dia_chi FROM kho WHERE hoat_dong AND id <> ?', [(int)$v['kho_id']]) as $w) {
                $k2 = \StockFlow\Domain\VnAddress::deliveryKm($w['dia_chi'], $addr);
                if ($k2 !== null && $k2 + 100 < $km && ($nearer === null || $k2 < $nearer['km'])) {
                    $nearer = ['ma' => $w['ma'], 'ten' => $w['ten'], 'km' => $k2];
                }
            }
        }
        return new Response(json_encode(['km' => $km, 'nearer' => $nearer], JSON_UNESCAPED_UNICODE), 200, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    /** Địa chỉ giao hàng ghép từ ba ô có gợi ý (số nhà · phường / xã · tỉnh / thành), kiểm tra lại với danh mục hành chính; khách tự lấy thì không cần. */
    private function deliveryAddress(Request $r): string
    {
        if (!in_array($r->str('hinh_thuc'), ['NOI_BO', 'THUE_NGOAI'], true)) {
            return '';
        }
        return \StockFlow\Domain\VnAddress::compose($r->str('street'), $r->str('ward'), $r->str('province'));
    }

    /** Tài xế đã giao hàng tới nơi (nút demo): ghi nhận thời điểm, bản đồ giao hàng hiện xe đã tới đích. */
    public function delivered(Request $r, array $p, ?User $user): Response
    {
        $id = (int)$p['id'];
        return $this->act(fn() => $this->deliveries->markArrived($user, $id), 'Đã ghi nhận: tài xế đã giao hàng đến nơi.', "/vouchers/$id");
    }

    /** Thông tin đơn vị vận tải thuê ngoài trên form (tải trọng, GVW nhập theo tấn ⇒ kg). */
    private function external(Request $r): array
    {
        $kg = function (string $key, string $label) use ($r): string {
            $t = \StockFlow\Domain\TransportRules::parseDecimal($r->str($key)) ?? throw new DomainError("$label phải là số không âm (ví dụ 2,5)");
            return $t > 0 ? (string)(int)round($t * 1000) : '';
        };
        return ['company' => $r->str('ext_company'), 'driver' => $r->str('ext_driver'), 'cccd' => $r->str('ext_cccd'), 'phone' => $r->str('ext_phone'), 'plate' => $r->str('ext_plate'), 'type' => $r->str('ext_type'),
                'tai_trong_kg' => $kg('ext_cap', 'Tải trọng xe'), 'gvw_kg' => $kg('ext_gvw', 'Khối lượng toàn bộ'),
                'license_no' => $r->str('ext_lic_no'), 'license_class' => $r->str('ext_lic_class'), 'license_expiry' => $r->str('ext_lic_exp')];
    }

    /** Kho đi báo xe hỏng giữa đường: hàng tới muộn, kho đến chưa nhận được cho tới khi xe chạy tiếp. */
    public function breakdown(Request $r, array $p, ?User $user): Response
    {
        $id = (int)$p['id'];
        return $this->act(fn() => $this->transfers->reportBreakdown($user, $this->convoyTarget($r, $id), $r->str('reason'), $r->str('note')),
            'Đã báo xe hỏng giữa đường. Hàng sẽ tới muộn — kho đến chưa nhận được cho tới khi xe chạy tiếp.', "/vouchers/$id");
    }

    /** Xe đã sửa xong, chạy tiếp. */
    public function resume(Request $r, array $p, ?User $user): Response
    {
        $id = (int)$p['id'];
        return $this->act(fn() => $this->transfers->resumeTrip($user, $this->convoyTarget($r, $id)), 'Xe đã chạy tiếp — kho đến nhận được hàng khi xe tới.', "/vouchers/$id");
    }

    public function cancel(Request $r, array $p, ?User $user): Response
    {
        $id = (int)$p['id'];
        return $this->act(fn() => $this->vouchers->cancel($user, $id), 'Đã huỷ phiếu nháp.', "/vouchers/$id");
    }

    public function correct(Request $r, array $p, ?User $user): Response
    {
        $id = (int)$p['id'];
        return $this->act(function () use ($r, $user, $id) {
            $res = $this->vouchers->correct($user, $id, $r->str('reason'));
            return '/vouchers/' . $res['voucher']['id'];
        }, 'Đã lập phiếu điều chỉnh để sửa sai; lịch sử cũ được giữ nguyên.', "/vouchers/$id");
    }

    public function createTransfer(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::LapPhieuDieuChuyen);
        $from = $this->warehouseIdsFor($user, Permission::LapPhieuDieuChuyen);
        return $this->render('transfers/form', $user, 'vouchers', [
            'from' => $this->warehouseRows($from), 'to' => $this->warehouseRows($this->allActiveIds()),
            'pick' => $this->query->pickList(), 'stock' => $this->query->stockMap($from), 'idem' => bin2hex(random_bytes(16)), 'prefillItem' => $r->int('item'),
        ] + $this->pickFilterData(), 'Điều chuyển mới');
    }

    public function storeTransfer(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::LapPhieuDieuChuyen);
        $this->auth->require($user, (int)$r->int('from_wh'), Permission::LapPhieuDieuChuyen);   // kho đi ngoài phạm vi ⇒ 403 trước khi đọc tồn
        return $this->act(function () use ($r, $user) {
            [$keys, $qtys] = $this->query->expandAutoLots((int)$r->int('from_wh'), $r->arr('line'), $r->arr('qty'));
            $lines = StockRules::normalizeLines($keys, $qtys);
            $this->assertInStock((int)$r->int('from_wh'), $lines);
            $res = $this->transfers->createDraft($user, (int)$r->int('from_wh'), (int)$r->int('to_wh'), $lines, $r->str('note'), $this->idemKey($r));
            if (!$res['created']) {
                $this->session->flash('ok', 'Yêu cầu này đã được ghi nhận trước đó — không tạo thêm phiếu.');
            }
            return '/vouchers/' . $res['voucher']['id'];
        }, 'Đã lập phiếu điều chuyển (nháp).', '/transfers/new');
    }

    /** Không cho lập phiếu xuất/điều chuyển vượt tồn hiện có (đọc từ ton_kho). Xác nhận phiếu vẫn kiểm tra lại có khoá dòng. */
    private function assertInStock(int $warehouseId, array $lines): void
    {
        if ($errors = $this->query->shortages($warehouseId, $lines)) {
            throw new \StockFlow\Domain\DomainError('Số lượng vượt tồn kho — ' . implode('; ', $errors));
        }
        if ($errors = $this->query->fefoErrors($warehouseId, $lines)) {
            throw new \StockFlow\Domain\DomainError('Xuất theo hạn dùng (hết hạn trước, xuất trước) — ' . implode('; ', $errors));
        }
    }

    private function allActiveIds(): array
    {
        return array_map('intval', array_column($this->db->all('SELECT id FROM kho WHERE hoat_dong'), 'id'));
    }

    private function warehouseRows(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        return $this->db->all('SELECT id, ma, ten, dia_chi FROM kho WHERE id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY ma');
    }
}
