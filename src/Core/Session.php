<?php
declare(strict_types=1);

namespace StockFlow\Core;

/** Phiên làm việc: người dùng đăng nhập, CSRF token, thông báo flash. */
final class Session
{
    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        session_set_cookie_params([
            'httponly' => true, 'samesite' => 'Lax',
            'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        ]);
        session_name('STOCKFLOW');
        session_start();
        $_SESSION['csrf'] ??= bin2hex(random_bytes(24));
    }

    /** @var array<string,mixed> tuỳ chọn hiển thị của tài khoản đang đăng nhập (do App nạp mỗi yêu cầu) */
    private array $prefs = [];

    public function setPrefs(array $prefs): void
    {
        $this->prefs = $prefs;
    }

    public function pref(string $key, mixed $default = null): mixed
    {
        return $this->prefs[$key] ?? $default;
    }

    /** Số giây kể từ thao tác trước (0 nếu chưa có mốc). */
    public function idleSeconds(): int
    {
        return isset($_SESSION['seen']) ? max(0, time() - (int)$_SESSION['seen']) : 0;
    }

    public function touch(): void
    {
        $_SESSION['seen'] = time();
    }

    public function userId(): ?int
    {
        return isset($_SESSION['uid']) ? (int)$_SESSION['uid'] : null;
    }

    public function login(int $userId): void
    {
        session_regenerate_id(true);
        $_SESSION['uid'] = $userId;
    }

    public function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }

    public function csrf(): string
    {
        return (string)($_SESSION['csrf'] ?? '');
    }

    public function checkCsrf(?string $token): bool
    {
        return is_string($token) && $token !== '' && hash_equals($this->csrf(), $token);
    }

    public function flash(string $kind, string $message): void
    {
        $_SESSION['flash'][] = [$kind, $message];
    }

    /** @return list<array{0:string,1:string}> */
    public function takeFlash(): array
    {
        $f = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $f;
    }

    /** Throttle đăng nhập sai theo phiên + IP (đơn giản, chống dò mật khẩu hàng loạt). */
    public function loginBlocked(): bool
    {
        return ($_SESSION['login_fail'] ?? 0) >= 8 && time() - ($_SESSION['login_fail_at'] ?? 0) < 300;
    }

    public function loginFailed(): void
    {
        $_SESSION['login_fail'] = ($_SESSION['login_fail'] ?? 0) + 1;
        $_SESSION['login_fail_at'] = time();
    }

    public function loginOk(): void
    {
        unset($_SESSION['login_fail'], $_SESSION['login_fail_at']);
    }
}
