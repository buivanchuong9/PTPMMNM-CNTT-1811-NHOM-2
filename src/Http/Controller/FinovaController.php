<?php
declare(strict_types=1);

namespace StockFlow\Http\Controller;

use StockFlow\Core\Request;
use StockFlow\Core\Response;
use StockFlow\Domain\User;

/** Trang «Orders» kiểu Finova (giao diện quản lý đơn hàng thương mại điện tử, dữ liệu mẫu ở /assets/js/finova/data.js). Chỉ cần đăng nhập. */
final class FinovaController extends Controller
{
    public function orders(Request $r, array $p, ?User $user): Response
    {
        return $this->view->render('finova/orders', ['title' => 'Orders', 'active' => 'orders'], 'layout/finova');
    }
}
