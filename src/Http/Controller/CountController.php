<?php
declare(strict_types=1);

namespace StockFlow\Http\Controller;

use StockFlow\Core\Db;
use StockFlow\Core\Request;
use StockFlow\Core\Response;
use StockFlow\Core\Session;
use StockFlow\Core\View;
use StockFlow\Domain\DomainError;
use StockFlow\Domain\Role;
use StockFlow\Domain\StockRules;
use StockFlow\Domain\User;
use StockFlow\Service\Authorizer;
use StockFlow\Service\StockCountService;

final class CountController extends Controller
{
    public function __construct(View $view, Session $session, Authorizer $auth, private StockCountService $counts, private Db $db)
    {
        parent::__construct($view, $session, $auth);
    }

    public function index(Request $r, array $p, ?User $user): Response
    {
        $ids = $this->auth->visibleWarehouseIds($user);
        $countable = $this->auth->warehousesWith($user, Role::Counter);
        return $this->render('counts/index', $user, 'counts', [
            'rows' => $this->counts->list($ids),
            'warehouses' => $countable ? $this->db->all('SELECT id, code, name FROM kho WHERE active AND id IN (' . implode(',', array_map('intval', $countable)) . ') ORDER BY code') : [],
        ], 'Kiểm kê');
    }

    public function store(Request $r, array $p, ?User $user): Response
    {
        return $this->act(fn() => '/counts/' . $this->counts->open($user, (int)$r->int('warehouse_id'), $r->str('note')),
            'Đã mở đợt kiểm kê — hệ thống đã chụp tồn sổ sách.', '/counts');
    }

    public function show(Request $r, array $p, ?User $user): Response
    {
        $c = $this->counts->find((int)$p['id']);
        $this->auth->requireVisible($user, (int)$c['warehouse_id']);
        return $this->render('counts/show', $user, 'counts', [
            'c' => $c,
            'canCount' => $this->auth->can($user, (int)$c['warehouse_id'], Role::Counter),
            'canApprove' => $this->auth->can($user, (int)$c['warehouse_id'], Role::Approver),
            'reasons' => ['Hư hỏng / vỡ', 'Thất thoát / mất hàng', 'Nhập sai số lượng', 'Xuất sai số lượng', 'Chưa ghi chứng từ', 'Nhầm lô / nhầm hàng', 'Khác'],
        ], $c['code']);
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
            $this->counts->save($user, $id, $counted, $reasons);
        }, 'Đã lưu số đếm.', "/counts/$id");
    }

    public function submit(Request $r, array $p, ?User $user): Response
    {
        $id = (int)$p['id'];
        return $this->act(function () use ($r, $user, $id) {
            [$counted, $reasons] = $this->readForm($r);
            $this->counts->submit($user, $id, $counted, $reasons);
        }, 'Đã nộp kết quả kiểm kê — chờ người duyệt xác nhận chênh lệch.', "/counts/$id");
    }

    public function approve(Request $r, array $p, ?User $user): Response
    {
        $id = (int)$p['id'];
        return $this->act(function () use ($user, $id) {
            $res = $this->counts->approve($user, $id);
            $this->session->flash('ok', $res['already'] ? 'Đợt kiểm kê đã được xác nhận trước đó — không lập thêm chứng từ.'
                : ($res['adjusted'] === 0 ? 'Sổ sách khớp thực tế — không cần chứng từ điều chỉnh.'
                    : 'Đã lập chứng từ điều chỉnh ' . $res['voucher']['code'] . ' (' . $res['adjusted'] . ' dòng, chênh lệch ròng ' . signed($res['net']) . ').'));
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
        return $this->act(fn() => $this->counts->cancel($user, $id), 'Đã huỷ đợt kiểm kê.', "/counts/$id");
    }
}
