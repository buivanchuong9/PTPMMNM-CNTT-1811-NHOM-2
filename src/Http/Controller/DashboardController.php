<?php
declare(strict_types=1);

namespace StockFlow\Http\Controller;

use StockFlow\Core\Request;
use StockFlow\Core\Response;
use StockFlow\Core\Session;
use StockFlow\Core\View;
use StockFlow\Domain\Permission;
use StockFlow\Domain\User;
use StockFlow\Service\Authorizer;
use StockFlow\Service\StockQuery;

final class DashboardController extends Controller
{
    public function __construct(View $view, Session $session, Authorizer $auth, private StockQuery $query)
    {
        parent::__construct($view, $session, $auth);
    }

    public function index(Request $r, array $p, ?User $user): Response
    {
        if (!$this->auth->allows($user, Permission::XemTongQuan)) {
            foreach (['/inventory' => Permission::XemTonKho, '/vouchers' => Permission::XemPhieu, '/vouchers/new?type=RECEIPT' => Permission::LapPhieuNhap,
                      '/vouchers/new?type=ISSUE' => Permission::LapPhieuXuat, '/transfers/new' => Permission::LapPhieuDieuChuyen,
                      '/warehouses' => Permission::XemDanhMuc] as $to => $perm) {
                if ($this->auth->allows($user, $perm)) {
                    return Response::redirect($to);
                }
            }
            $this->need($user, Permission::XemTongQuan);
        }
        return $this->render('dashboard/index', $user, 'dashboard', ['d' => $this->query->dashboard($user)], 'Tổng quan');
    }
}
