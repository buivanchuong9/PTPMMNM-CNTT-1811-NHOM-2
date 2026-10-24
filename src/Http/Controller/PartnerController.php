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
use StockFlow\Service\PartnerService;

/** Quản lý đối tác: nhà cung cấp & khách hàng (xem: ai có quyền xem danh mục; sửa: tài khoản cấp cao). */
final class PartnerController extends Controller
{
    public function __construct(View $view, Session $session, Authorizer $auth, private PartnerService $partners)
    {
        parent::__construct($view, $session, $auth);
    }

    public function index(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::XemDanhMuc);
        $all = $this->partners->all();
        $type = isset(PartnerService::TYPES[$r->str('type')]) ? $r->str('type') : '';
        $q = mb_strtolower($r->search('q'));
        $counts = ['' => count($all), 'SUPPLIER' => 0, 'CUSTOMER' => 0];
        foreach ($all as $x) {
            $counts[$x['loai']]++;
        }
        $rows = array_values(array_filter($all, fn($x) => ($type === '' || $x['loai'] === $type)
            && ($q === '' || str_contains(mb_strtolower($x['ma'] . ' ' . $x['ten'] . ' ' . $x['nguoi_lien_he'] . ' ' . $x['so_dien_thoai'] . ' ' . $x['email'] . ' ' . implode(' ', array_column($x['items'], 'sku'))), $q))));
        [$rows, $pg] = Paginator::of($rows, $r);
        return $this->render('partners/index', $user, 'partners', ['rows' => $rows, 'pg' => $pg, 'type' => $type, 'q' => $r->search('q'), 'counts' => $counts, 'types' => PartnerService::TYPES], 'Đối tác');
    }

    private function fields(Request $r): array
    {
        return [$r->str('code'), $r->str('name'), $r->str('type'), $r->str('contact'), $r->str('phone'), $r->str('email'), $r->str('address'), $r->str('note')];
    }

    public function add(Request $r, array $p, ?User $user): Response
    {
        return $this->act(fn() => $this->partners->add($user, ...$this->fields($r)), 'Đã thêm đối tác.', '/partners');
    }

    public function update(Request $r, array $p, ?User $user): Response
    {
        return $this->act(fn() => $this->partners->update($user, (int)$r->int('partner_id'), ...$this->fields($r)), 'Đã lưu thông tin đối tác.', '/partners');
    }

    public function active(Request $r, array $p, ?User $user): Response
    {
        $on = $r->str('active') === '1';
        return $this->act(fn() => $this->partners->setActive($user, (int)$r->int('partner_id'), $on), $on ? 'Đã dùng lại đối tác.' : 'Đã ngừng hợp tác — không còn gợi ý trong phiếu.', '/partners');
    }
}
