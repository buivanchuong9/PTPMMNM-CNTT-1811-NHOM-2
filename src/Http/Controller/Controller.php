<?php
declare(strict_types=1);

namespace StockFlow\Http\Controller;

use StockFlow\Core\Request;
use StockFlow\Core\Response;
use StockFlow\Core\Session;
use StockFlow\Core\View;
use StockFlow\Domain\DomainError;
use StockFlow\Domain\User;
use StockFlow\Service\Authorizer;

abstract class Controller
{
    public function __construct(protected View $view, protected Session $session, protected Authorizer $auth) {}

    /** @param array<string,mixed> $data */
    protected function render(string $template, User $user, string $active, array $data = [], string $title = ''): Response
    {
        return $this->view->render($template, $data + ['user' => $user, 'active' => $active, 'title' => $title, 'auth' => $this->auth]);
    }

    /**
     * Thực hiện một hành động ghi: lỗi nghiệp vụ ⇒ flash lỗi + quay lại; thành công ⇒ flash + chuyển trang.
     * Forbidden/NotFound không bị nuốt: App chuyển thành 403/404.
     * @param callable():(string|null) $fn trả về URL đích (hoặc null để dùng $back)
     */
    protected function act(callable $fn, string $ok, string $back): Response
    {
        try {
            $to = $fn();
        } catch (DomainError $e) {
            $this->session->flash('err', $e->getMessage());
            return Response::redirect($back);
        }
        if ($ok !== '') {
            $this->session->flash('ok', $ok);
        }
        return Response::redirect(is_string($to) ? $to : $back);
    }

    /** Khoá chống gửi lặp: token ngẫu nhiên đặt trong form; người dùng bấm 2 lần/refresh POST vẫn chỉ tạo một chứng từ. */
    protected function idemKey(Request $r): ?string
    {
        $k = $r->str('idem');
        return preg_match('/^[a-f0-9]{32}$/', $k) ? $k : null;
    }

    /** @return list<int> các kho người dùng được thao tác theo vai trò */
    protected function warehouseIdsFor(User $user, \StockFlow\Domain\Role $role): array
    {
        return $this->auth->warehousesWith($user, $role);
    }
}
