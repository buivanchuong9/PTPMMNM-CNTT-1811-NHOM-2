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
use StockFlow\Domain\StockRules;
use StockFlow\Domain\Permission;
use StockFlow\Domain\User;
use StockFlow\Service\Authorizer;
use StockFlow\Service\DisposalService;
use StockFlow\Service\StockCountService;

final class CountController extends Controller
{
    public function __construct(View $view, Session $session, Authorizer $auth, private StockCountService $counts, private Db $db)
    {
        parent::__construct($view, $session, $auth);
    }

    public function index(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::XemKiemKe, Permission::KiemKeThucHien, Permission::KiemKeDuyet);
        $visible = $this->auth->visibleWarehouseIds($user);
        $wh = $r->int('warehouse');
        $scope = $user->isAdmin && $wh !== null && in_array($wh, $visible, true) ? [$wh] : $visible;   // chỉ quản trị cấp cao mới chọn được kho
        $status = in_array($r->str('status'), ['OPEN', 'REVIEW', 'CLOSED', 'CANCELLED'], true) ? $r->str('status') : '';
        $countable = $this->auth->warehousesWith($user, Permission::KiemKeThucHien);
        // lọc thời gian: mốc nhanh (range=today|yesterday|7d|1m|3m) thắng; 'custom' hoặc có from/to thì dùng ngày tự chọn
        $validDate = fn(string $k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $r->str($k)) && checkdate((int)substr($r->str($k), 5, 2), (int)substr($r->str($k), 8, 2), (int)substr($r->str($k), 0, 4)) ? $r->str($k) : null;
        if ($preset = date_range($r->str('range'))) {
            [$from, $to] = $preset;
            $rangeSel = $r->str('range');
        } else {
            [$from, $to] = [$validDate('from'), $validDate('to')];
            if ($from && $to && $from > $to) {
                [$from, $to] = [$to, $from];
            }
            $rangeSel = ($r->str('range') === 'custom' || $from || $to) ? 'custom' : '';
        }
        // «Tất cả» không gồm các đợt đã huỷ (chỉ xem ở tab «Đã huỷ»)
        [$rows, $pg] = Paginator::of($this->counts->list($scope, $status, $from, $to, false), $r);
        $statusCounts = $this->counts->statusCounts($scope);
        $all = $visible ? $this->db->all('SELECT id, ma, ten FROM kho WHERE id IN (' . implode(',', array_map('intval', $visible)) . ') ORDER BY ma') : [];
        return $this->render('counts/index', $user, 'counts', [
            'rows' => $rows, 'pg' => $pg, 'status' => $status, 'wh' => $scope === [$wh] ? $wh : null,
            'statusCounts' => $statusCounts, 'totalActive' => array_sum($statusCounts) - ($statusCounts['CANCELLED'] ?? 0),
            'from' => $from, 'to' => $to, 'rangeSel' => $rangeSel,
            'reasons' => $user->isAdmin ? $this->counts->varianceReasons($scope, $from, $to) : [],
            'dueDays' => StockCountService::DUE_DAYS,
            'allWarehouses' => $all,
            'summary' => $user->isAdmin ? $this->counts->summaryByWarehouse($visible) : [],
            'warehouses' => $countable ? $this->db->all('SELECT id, ma, ten FROM kho WHERE hoat_dong AND id IN (' . implode(',', array_map('intval', $countable)) . ') ORDER BY ma') : [],
        ], 'Kiểm kê');
    }

    public function store(Request $r, array $p, ?User $user): Response
    {
        return $this->act(fn() => '/counts/' . $this->counts->open($user, (int)$r->int('warehouse_id'), $r->str('note')),
            'Đã mở đợt kiểm kê — hệ thống đã chụp tồn sổ sách.', '/counts');
    }

    public function show(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::XemKiemKe, Permission::KiemKeThucHien, Permission::KiemKeDuyet);
        $c = $this->counts->find((int)$p['id']);
        $this->auth->requireVisible($user, (int)$c['kho_id']);
        // tìm theo SKU, tên, số lô hoặc mã vạch; mỗi trang một phần dòng (số nhập ở trang này được lưu khi chuyển trang)
        $q = trim($r->str('q'));
        $all = $c['lines'];
        $lines = $all;
        if ($q !== '') {
            $needle = fold_vn($q);
            $lines = array_values(array_filter($all, fn($l) => str_contains(fold_vn($l['ma_hang'] . ' ' . $l['ten'] . ' ' . $l['so_lo']), $needle) || ($l['ma_vach'] !== null && str_contains((string)$l['ma_vach'], $q))));
        }
        [$page, $pg] = Paginator::of($lines, $r);
        $stat = function (array $rows): array {   // dòng lệch · lệch ròng · số hàng không đạt chất lượng
            $s = ['var' => 0, 'net' => 0, 'bad' => 0];
            foreach ($rows as $l) {
                if ($l['so_luong_thuc_dem'] !== null) {
                    $d = (int)$l['so_luong_thuc_dem'] - (int)$l['so_luong_so_sach'];
                    $s['net'] += $d;
                    $s['var'] += $d !== 0 ? 1 : 0;
                }
                $s['bad'] += (int)$l['so_luong_hong'];
            }
            return $s;
        };
        $onPage = array_map(fn($l) => $l['mat_hang_id'] . ':' . $l['lo_hang_id'], $page);
        $c['lines'] = $page;
        return $this->render('counts/show', $user, 'counts', [
            'pg' => $pg, 'q' => $q, 'lineTotal' => count($all), 'tot' => $stat($all),
            'base' => $stat(array_filter($all, fn($l) => !in_array($l['mat_hang_id'] . ':' . $l['lo_hang_id'], $onPage, true))),   // phần ngoài trang đang xem (JS cộng thêm phần trang này)
            'badReasons' => DisposalService::REASONS,
            'c' => $c, 'cancel' => $c['trang_thai'] === 'CANCELLED' ? $this->counts->cancelInfo((int)$c['id']) : null,
            'canCount' => $this->auth->can($user, (int)$c['kho_id'], Permission::KiemKeThucHien),
            'canApprove' => $this->auth->can($user, (int)$c['kho_id'], Permission::KiemKeDuyet),
            'reasons' => ['Hư hỏng / vỡ', 'Thất thoát / mất hàng', 'Nhập sai số lượng', 'Xuất sai số lượng', 'Chưa ghi phiếu nhập xuất', 'Nhầm lô / nhầm hàng', 'Khác'],
        ], $c['ma']);
    }

    /** Số hàng không đạt chất lượng từng dòng (nằm trong số thực đếm). @return array<string,array{qty:int,reason:string,note:string}> */
    private function readQuality(Request $r): array
    {
        $out = [];
        foreach ($r->arr('bad_qty') as $key => $v) {
            $v = is_string($v) ? trim($v) : '';
            $qty = $v === '' ? 0 : StockRules::parseQty($v);
            if ($qty === null) {
                throw new DomainError('Số lượng không đạt chất lượng phải là số nguyên không âm');
            }
            $reason = $r->arr('bad_reason')[$key] ?? '';
            $note = $r->arr('bad_note')[$key] ?? '';
            $out[(string)$key] = ['qty' => $qty, 'reason' => is_string($reason) ? $reason : '', 'note' => is_string($note) ? $note : ''];
        }
        return $out;
    }

    /** Địa chỉ quay lại sau khi lưu (chuyển trang / tìm kiếm khi đang nhập): chỉ nhận đường dẫn của chính đợt này. */
    private function goto(Request $r, int $id): ?string
    {
        $to = $r->str('goto');
        return preg_match('#^/counts/' . $id . '(\?[^\s]*)?$#', $to) ? $to : null;
    }

    /** @return array{0:array<string,?int>,1:array<string,string>} */
    private function readForm(Request $r): array
    {
        $counted = [];
        foreach ($r->arr('counted') as $key => $v) {
            $v = is_string($v) ? trim($v) : '';
            if ($v === '') {
                $counted[(string)$key] = null;
                continue;
            }
            $q = StockRules::parseQty($v);
            if ($q === null) {
                throw new DomainError('Số đếm phải là số nguyên không âm');
            }
            $counted[(string)$key] = $q;
        }
        $reasons = [];
        foreach ($r->arr('reason') as $key => $v) {
            $reasons[(string)$key] = is_string($v) ? trim($v) : '';
        }
        foreach ($r->arr('reason_note') as $key => $v) {
            if (is_string($v) && trim($v) !== '' && ($reasons[(string)$key] ?? '') !== '') {
                $reasons[(string)$key] .= ': ' . trim($v);
            }
        }
        return [$counted, $reasons];
    }

    public function save(Request $r, array $p, ?User $user): Response
    {
        $id = (int)$p['id'];
        return $this->act(function () use ($r, $user, $id) {
            [$counted, $reasons] = $this->readForm($r);
            $this->counts->save($user, $id, $counted, $reasons, $this->readQuality($r));
            return $this->goto($r, $id);
        }, $this->goto($r, $id) ? '' : 'Đã lưu số đếm.', "/counts/$id");
    }

    public function submit(Request $r, array $p, ?User $user): Response
    {
        $id = (int)$p['id'];
        return $this->act(function () use ($r, $user, $id) {
            [$counted, $reasons] = $this->readForm($r);
            $this->counts->submit($user, $id, $counted, $reasons, $this->readQuality($r));
        }, 'Đã nộp kết quả kiểm kê — chờ người duyệt xác nhận chênh lệch.', "/counts/$id");
    }

    public function approve(Request $r, array $p, ?User $user): Response
    {
        $id = (int)$p['id'];
        return $this->act(function () use ($user, $id) {
            $res = $this->counts->approve($user, $id);
            $this->session->flash('ok', $res['already'] ? 'Đợt kiểm kê đã được xác nhận trước đó — không lập thêm phiếu.'
                : ($res['adjusted'] === 0 ? 'Sổ sách khớp thực tế — không cần phiếu điều chỉnh.'
                    : 'Đã lập phiếu điều chỉnh ' . $res['voucher']['ma'] . ' (' . $res['adjusted'] . ' dòng, chênh lệch ròng ' . signed($res['net']) . ').'));
            if ($res['disposed']) {
                $this->session->flash('ok', $res['disposed'] . ' dòng hàng không đạt chất lượng đã được trừ khỏi tồn và chuyển vào kho huỷ.');
            }
        }, '', "/counts/$id");
    }

    public function reject(Request $r, array $p, ?User $user): Response
    {
        $id = (int)$p['id'];
        return $this->act(fn() => $this->counts->reject($user, $id), 'Đã trả lại để đếm lại.', "/counts/$id");
    }

    public function cancel(Request $r, array $p, ?User $user): Response
    {
        $id = (int)$p['id'];
        $reason = trim($r->str('cancel_reason'));
        return $this->act(function () use ($user, $id, $reason) {
            if ($reason === '') {
                throw new DomainError('Ghi lý do huỷ đợt kiểm kê (ô «Lý do huỷ» cạnh nút Huỷ đợt).');
            }
            $this->counts->cancel($user, $id, $reason);
        }, 'Đã huỷ đợt kiểm kê.', "/counts/$id");
    }
}
