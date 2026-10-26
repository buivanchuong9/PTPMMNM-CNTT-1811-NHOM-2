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
                                private VoucherRepository $repo, private StockQuery $query, private AuditLog $audit, private Db $db, private FleetService $fleet, private \StockFlow\Service\CatalogService $catalog)
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
        ], 'Phiếu nhập xuất');
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
        return $this->render('vouchers/form', $user, 'new-' . strtolower($type->value), [
            'type' => $type, 'warehouses' => $this->warehouseRows($ids), 'pick' => $this->query->pickList(), 'stock' => $this->query->stockMap($ids),
            'idem' => bin2hex(random_bytes(16)), 'selected' => $r->int('warehouse'), 'prefillItem' => $r->int('item'),
            'partnerNames' => array_column($this->db->all('SELECT ten FROM doi_tac WHERE loai = ? AND hoat_dong ORDER BY ten', [$type === VoucherType::Receipt ? 'SUPPLIER' : 'CUSTOMER']), 'ten'),
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
            if ($type === VoucherType::Issue) {
                $this->assertInStock((int)$r->int('warehouse_id'), $lines);
            }
            if ($type === VoucherType::Receipt) {
                $this->assertSupplierItems($r->str('partner'), $lines);
            }
            $partnerSvc = new \StockFlow\Service\PartnerService($this->db, $this->auth, $this->audit);
            if ($type === VoucherType::Issue) {
                $partnerSvc->requireNewCustomerInfo($r->str('partner'), $r->str('cust_contact'), $r->str('cust_phone'), $r->str('cust_address'));
            }
            $res = $this->vouchers->createDraft($user, $type, (int)$r->int('warehouse_id'), $lines, $r->str('partner'), $r->str('note'), $this->idemKey($r));
            if ($type === VoucherType::Issue && $res['created'] && $partnerSvc->ensureCustomer($user, $r->str('partner'), $r->str('cust_contact'), $r->str('cust_phone'), $r->str('cust_address'))) {
                $this->session->flash('ok', 'Đã lưu «' . $r->str('partner') . '» vào danh sách khách hàng.');
            }
            if (!$res['created']) {
                $this->session->flash('ok', 'Yêu cầu này đã được ghi nhận trước đó — không tạo thêm phiếu nhập xuất.');
            }
            return '/vouchers/' . $res['voucher']['id'];
        }, 'Đã lập phiếu nháp. Kiểm tra rồi bấm Xác nhận để ghi sổ.', '/vouchers/new?type=' . $type->value);
    }

    /**
     * Phiếu xuất cho khách chưa có trong danh sách ⇒ chuyển sang «bước 2» nhập thông tin khách (giữ nguyên các dòng hàng đã chọn).
     * @return ?Response null nếu không cần bước 2
     */
    private function customerStep(Request $r, User $user): ?Response
    {
        $svc = new \StockFlow\Service\PartnerService($this->db, $this->auth, $this->audit);
        $name = $r->str('partner');
        if ($name === '' || $svc->known($name)) {
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
            $svc->requireNewCustomerInfo($name, $r->str('cust_contact'), $r->str('cust_phone'), $r->str('cust_address'));
            return null;   // đã đủ thông tin khách mới ⇒ cho lập phiếu
        } catch (DomainError $e) {
            if ($r->str('cust_contact') . $r->str('cust_phone') . $r->str('cust_address') !== '') {
                $this->session->flash('err', $e->getMessage());
            }
            return $this->render('vouchers/customer_step', $user, 'new-issue', [
                'post' => $r->post, 'partner' => (string)preg_replace('/\s+/u', ' ', $name), 'lineCount' => count($lines), 'totalQty' => array_sum(array_column($lines, 'so_luong')),
            ], 'Phiếu xuất mới — bước 2');
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
            'vehicles' => $isTransfer && $v['loai'] === 'TRANSFER_OUT' && $v['trang_thai'] === 'DRAFT' ? $this->fleet->availableVehicles((int)$v['kho_id']) : [],
        ], $v['ma']);
    }

    /**
     * Thực nhận tại kho đến của một chuyến đã giao (hiện ở cả phiếu đi lẫn phiếu đến, để kho gửi biết kho nhận nhận được bao nhiêu):
     * từng dòng = số trên phiếu − phần thiếu ghi ở phiếu điều chỉnh của phiếu đến.
     * @return ?array{rows:list<array<string,mixed>>,in_id:int,when:?string,by:?string,note:string}
     */
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
                $res = $this->transfers->dispatch($user, $id, (int)$r->int('vehicle_id'), $r->str('driver_ok') === '1');
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
                    return null;
                }
            } else {
                $res = $this->vouchers->confirm($user, $id);
            }
            if ($res['already']) {
                $this->session->flash('ok', 'Phiếu nhập xuất đã được xác nhận trước đó — sổ phát sinh không ghi thêm.');
            }
            return null;
        }, 'Đã xác nhận và ghi sổ phát sinh.', "/vouchers/$id");
    }

    /** Kho đến báo CHƯA NHẬN ĐƯỢC HÀNG: chọn lý do, không cộng tồn, báo cho tài khoản cấp cao ở trang Đội xe. */
    public function notReceived(Request $r, array $p, ?User $user): Response
    {
        $id = (int)$p['id'];
        return $this->act(function () use ($r, $user, $id) {
            $this->transfers->reportNotReceived($user, $id, $r->str('missing_reason'), $r->str('missing_note'));
        }, 'Đã báo chưa nhận được hàng. Tồn kho chưa thay đổi — quản trị hệ thống sẽ thấy chuyến này ở trang Đội xe.', "/vouchers/$id");
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
        return $this->render('transfers/form', $user, 'new-transfer', [
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
