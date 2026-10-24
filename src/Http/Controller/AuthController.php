<?php
declare(strict_types=1);

namespace StockFlow\Http\Controller;

use StockFlow\Core\Request;
use StockFlow\Core\Response;
use StockFlow\Core\Session;
use StockFlow\Core\View;
use StockFlow\Service\AuthService;
use StockFlow\Service\Authorizer;

final class AuthController extends Controller
{
    public function __construct(View $view, Session $session, Authorizer $auth, private AuthService $users)
    {
        parent::__construct($view, $session, $auth);
    }

    public function form(Request $r, array $p, ?\StockFlow\Domain\User $user): Response
    {
        if ($user) {
            return Response::redirect('/');
        }
        if ($wait = $this->session->loginLockedFor()) {
            return $this->locked($wait, '');
        }
        return $this->view->render('auth/login', ['error' => null, 'username' => ''], 'layout/bare');
    }

    public function login(Request $r, array $p, ?\StockFlow\Domain\User $user): Response
    {
        $username = $r->str('username');
        if ($wait = $this->session->loginLockedFor()) {
            return $this->locked($wait, $username);
        }
        $found = $this->users->attempt($username, (string)($r->post['password'] ?? ''));
        if (!$found) {
            // Chỉ tính khi nhập đúng tên đăng nhập mà sai mật khẩu; tên không tồn tại thì không tính.
            if ($this->users->exists($username)) {
                $this->session->loginFailed();
                if ($wait = $this->session->loginLockedFor()) {
                    return $this->locked($wait, $username);
                }
            }
            return $this->view->render('auth/login', ['error' => 'Sai tên đăng nhập hoặc mật khẩu (tối đa ' . Session::LOGIN_MAX_FAILS . ' lần).', 'username' => $username], 'layout/bare', 401);
        }
        $this->session->loginOk();
        $this->session->login($found->id);
        return Response::redirect('/');
    }

    private function locked(int $seconds, string $username): Response
    {
        return $this->view->render('auth/login', ['error' => 'Tài khoản tạm khoá do nhập sai mật khẩu quá nhiều lần.', 'username' => $username, 'lock' => $seconds], 'layout/bare', 429);
    }

    public function logout(Request $r, array $p, ?\StockFlow\Domain\User $user): Response
    {
        $this->session->logout();
        return Response::redirect('/login');
    }
}
