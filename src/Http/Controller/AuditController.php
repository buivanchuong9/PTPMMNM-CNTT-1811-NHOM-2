<?php
declare(strict_types=1);

namespace StockFlow\Http\Controller;

use StockFlow\Core\Paginator;
use StockFlow\Core\Request;
use StockFlow\Core\Response;
use StockFlow\Core\Session;
use StockFlow\Core\View;
use StockFlow\Domain\Permission;
use StockFlow\Domain\User;
use StockFlow\Service\Authorizer;
use StockFlow\Service\AuditLog;

/** Nhật ký thao tác: ai làm gì, trên phiếu nào, lúc nào. Quản trị xem tất cả; quản lý / nhân viên chỉ thấy thao tác của tài khoản cùng kho. */
final class AuditController extends Controller
{
    public function __construct(View $view, Session $session, Authorizer $auth, private AuditLog $audit)
    {
        parent::__construct($view, $session, $auth);
    }

    public function index(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::XemLichSu);
        $all = $this->audit->recent($user->isAdmin ? null : $this->auth->visibleWarehouseIds($user));
        $people = [];
        foreach ($all as $a) {
            if ($a['nguoi_dung_id'] !== null) {
                $people[(int)$a['nguoi_dung_id']] = $a['ho_ten'] ?? '—';
            }
        }
        asort($people);
        $group = array_key_exists($r->str('group'), AUDIT_GROUPS) ? $r->str('group') : '';
        $who = (int)$r->int('who');
        $q = trim($r->str('q'));
        [$from, $to, $rangeSel] = range_params($r);
        $list = array_values(array_filter($all, fn($a) => ($group === '' || audit_group($a['hanh_dong']) === $group)
            && (!$who || (int)$a['nguoi_dung_id'] === $who) && in_vn_range($a['ngay_tao'], $from, $to)));
        $list = filter_rows($list, $q, fn($a) => implode(' ', [audit_label($a['hanh_dong']), $a['chi_tiet'], $a['phieu_ma'] ?? '', $a['ho_ten'] ?? '', $a['ten_dang_nhap'] ?? '', $a['kho_ma'] ?? '']));
        if ($r->str('format') === 'csv') {
            return Response::csv('nhat-ky-thao-tac-' . gmdate('Ymd') . '.csv', ['Thời gian (UTC)', 'Người thao tác', 'Kho', 'Việc đã làm', 'Chi tiết'],
                array_map(fn($a) => [$a['ngay_tao'], $a['ho_ten'] ?? 'Hệ thống', $a['kho_ma'] ?? '', audit_label($a['hanh_dong']), audit_detail($a)], $list));
        }
        [$rows, $pg] = Paginator::of($list, $r);
        $keep = keep_params(['q' => $q, 'group' => $group, 'who' => $who ?: null, 'range' => $rangeSel === 'custom' ? null : $rangeSel,
            'from' => $rangeSel === 'custom' ? $from : null, 'to' => $rangeSel === 'custom' ? $to : null]);
        return $this->render('audit/index', $user, 'audit', ['rows' => $rows, 'pg' => $pg, 'q' => $q, 'group' => $group, 'who' => $who, 'people' => $people,
            'from' => $from, 'to' => $to, 'rangeSel' => $rangeSel, 'keep' => $keep], 'Nhật ký thao tác');
    }
}
