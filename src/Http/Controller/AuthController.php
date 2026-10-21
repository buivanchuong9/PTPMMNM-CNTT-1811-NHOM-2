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
        return $this->view->render('auth/login', ['error' => null, 'username' => ''], 'layout/bare');
    }

    public function login(Request $r, array $p, ?\StockFlow\Domain\User $user): Response
    {
        $username = $r->str('username');
        if ($this->session->loginBlocked()) {
            return $this->view->render('auth/login', ['error' => 'Đăng nhập sai quá nhiều lần. Vui lòng thử lại sau vài phút.', 'username' => $username], 'layout/bare', 429);
        }
        $found = $this->users->attempt($username, (string)($r->post['password'] ?? ''));
        if (!$found) {
            $this->session->loginFailed();
            return $this->view->render('auth/login', ['error' => 'Sai tên đăng nhập hoặc mật khẩu.', 'username' => $username], 'layout/bare', 401);
        }
        $this->session->loginOk();
        $this->session->login($found->id);
        return Response::redirect('/');
    }

    public function logout(Request $r, array $p, ?\StockFlow\Domain\User $user): Response
    {
        $this->session->logout();
        return Response::redirect('/login');
    }
}
