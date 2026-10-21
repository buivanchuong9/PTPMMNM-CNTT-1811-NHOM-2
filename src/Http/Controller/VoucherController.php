<?php
declare(strict_types=1);

namespace StockFlow\Http\Controller;

use StockFlow\Core\Db;
use StockFlow\Core\Request;
use StockFlow\Core\Response;
use StockFlow\Core\Session;
use StockFlow\Core\View;
use StockFlow\Domain\DomainError;
use StockFlow\Domain\NotFound;
use StockFlow\Domain\Role;
use StockFlow\Domain\StockRules;
use StockFlow\Domain\User;
use StockFlow\Domain\VoucherType;
use StockFlow\Service\AuditLog;
use StockFlow\Service\Authorizer;
use StockFlow\Service\StockQuery;
use StockFlow\Service\TransferService;
use StockFlow\Service\VoucherRepository;
use StockFlow\Service\VoucherService;

final class VoucherController extends Controller
{
    public function __construct(View $view, Session $session, Authorizer $auth, private VoucherService $vouchers, private TransferService $transfers,
                                private VoucherRepository $repo, private StockQuery $query, private AuditLog $audit, private Db $db)
    {
        parent::__construct($view, $session, $auth);
    }

    public function index(Request $r, array $p, ?User $user): Response
    {
        $ids = $this->auth->visibleWarehouseIds($user);
        $type = VoucherType::tryFrom($r->str('type'))?->value;
        $status = in_array($r->str('status'), ['DRAFT', 'CONFIRMED', 'CANCELLED'], true) ? $r->str('status') : null;
        $q = $r->str('q');
        $wh = $r->int('warehouse');
        $scope = $wh && in_array($wh, $ids, true) ? [$wh] : $ids;
        return $this->render('vouchers/index', $user, 'vouchers', [
            'rows' => $this->repo->search($scope, $type, $status, $q),
            'counts' => $this->repo->statusCounts($scope),
            'type' => $type, 'status' => $status, 'q' => $q, 'wh' => $wh,
            'warehouses' => $this->warehouseRows($ids),
        ], 'Chứng từ');
    }

    public function create(Request $r, array $p, ?User $user): Response
    {
        $type = VoucherType::tryFrom($r->str('type')) ?? VoucherType::Receipt;
        if (!in_array($type, [VoucherType::Receipt, VoucherType::Issue], true)) {
            throw new NotFound('Loại phiếu không hợp lệ');
        }
        $ids = $this->warehouseIdsFor($user, Role::Keeper);
        return $this->render('vouchers/form', $user, 'new-' . strtolower($type->value), [
            'type' => $type, 'warehouses' => $this->warehouseRows($ids), 'pick' => $this->query->pickList(),
            'idem' => bin2hex(random_bytes(16)), 'selected' => $r->int('warehouse'),
        ], $type->label() . ' mới');
    }

    public function store(Request $r, array $p, ?User $user): Response
    {
        $type = VoucherType::tryFrom($r->str('type')) ?? VoucherType::Receipt;
        return $this->act(function () use ($r, $user, $type) {
            $lines = StockRules::normalizeLines($r->arr('line'), $r->arr('qty'));
            $res = $this->vouchers->createDraft($user, $type, (int)$r->int('warehouse_id'), $lines, $r->str('partner'), $r->str('note'), $this->idemKey($r));
            if (!$res['created']) {
                $this->session->flash('ok', 'Yêu cầu này đã được ghi nhận trước đó — không tạo thêm chứng từ.');
            }
            return '/vouchers/' . $res['voucher']['id'];
        }, 'Đã lập phiếu nháp. Kiểm tra rồi bấm Xác nhận để ghi sổ.', '/vouchers/new?type=' . $type->value);
    }

    public function show(Request $r, array $p, ?User $user): Response
    {
        $v = $this->repo->find((int)$p['id']);
        $this->auth->requireVisible($user, (int)$v['warehouse_id']);
        $lines = $this->repo->lines((int)$v['id']);
        $check = $v['status'] === 'DRAFT' ? $this->vouchers->check($user, (int)$v['id']) : [];
        $movements = $v['status'] === 'CONFIRMED' ? $this->db->all('SELECT m.*, w.code AS wh_code FROM so_phat_sinh m JOIN kho w ON w.id = m.warehouse_id WHERE m.voucher_id = ? ORDER BY m.id', [$v['id']]) : [];
        $isTransfer = in_array($v['type'], ['TRANSFER_OUT', 'TRANSFER_IN'], true);
        $canApprove = $this->auth->can($user, (int)$v['warehouse_id'], Role::Approver);
        return $this->render('vouchers/show', $user, 'vouchers', [
            'v' => $v, 'lines' => $lines, 'check' => $check, 'movements' => $movements,
            'pair' => $this->repo->pairOf($v), 'correction' => $this->repo->correctionOf((int)$v['id']),
            'original' => $v['correction_of'] ? $this->repo->find((int)$v['correction_of']) : null,
            'trail' => $this->audit->forEntity('voucher', (int)$v['id']),
            'canApprove' => $canApprove,
            'canDraft' => $this->auth->can($user, (int)$v['warehouse_id'], Role::Keeper) || $canApprove,
            'canCorrect' => $v['status'] === 'CONFIRMED' && $v['type'] !== 'ADJUSTMENT' || ($v['status'] === 'CONFIRMED' && $v['type'] === 'ADJUSTMENT' && !$v['correction_of']),
            'isTransfer' => $isTransfer,
        ], $v['code']);
    }

    public function confirm(Request $r, array $p, ?User $user): Response
    {
        $id = (int)$p['id'];
        return $this->act(function () use ($user, $id) {
            $res = $this->vouchers->confirm($user, $id);
            if ($res['already']) {
                $this->session->flash('ok', 'Chứng từ đã được xác nhận trước đó — sổ phát sinh không ghi thêm.');
            }
            return null;
        }, 'Đã xác nhận và ghi sổ phát sinh.', "/vouchers/$id");
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
        }, 'Đã lập chứng từ điều chỉnh đảo; lịch sử cũ được giữ nguyên.', "/vouchers/$id");
    }

    public function createTransfer(Request $r, array $p, ?User $user): Response
    {
        $from = $this->warehouseIdsFor($user, Role::Keeper);
        return $this->render('transfers/form', $user, 'new-transfer', [
            'from' => $this->warehouseRows($from), 'to' => $this->warehouseRows($this->allActiveIds()),
            'pick' => $this->query->pickList(), 'idem' => bin2hex(random_bytes(16)),
        ], 'Điều chuyển mới');
    }

    public function storeTransfer(Request $r, array $p, ?User $user): Response
    {
        return $this->act(function () use ($r, $user) {
            $lines = StockRules::normalizeLines($r->arr('line'), $r->arr('qty'));
            $res = $this->transfers->createDraft($user, (int)$r->int('from_wh'), (int)$r->int('to_wh'), $lines, $r->str('note'), $this->idemKey($r));
            if (!$res['created']) {
                $this->session->flash('ok', 'Yêu cầu này đã được ghi nhận trước đó — không tạo thêm phiếu.');
            }
            return '/vouchers/' . $res['voucher']['id'];
        }, 'Đã lập phiếu điều chuyển (nháp).', '/transfers/new');
    }

    private function allActiveIds(): array
    {
        return array_map('intval', array_column($this->db->all('SELECT id FROM kho WHERE active'), 'id'));
    }

    private function warehouseRows(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        return $this->db->all('SELECT id, code, name FROM kho WHERE id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY code');
    }
}
