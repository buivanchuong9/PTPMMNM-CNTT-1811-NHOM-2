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
        $_SESSION['welcome'] = true;   // trang đầu tiên sau đăng nhập có màn chào «xe tải chạy ngang màn hình»
    }

    /** Có màn chào sau đăng nhập không (chỉ hiện đúng một lần cho mỗi lần đăng nhập). */
    public function takeWelcome(): bool
    {
        $on = !empty($_SESSION['welcome']);
        unset($_SESSION['welcome']);
        return $on;
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

    /** Số lần nhập sai mật khẩu (đúng tên đăng nhập) trước khi tạm khoá. */
    public const LOGIN_MAX_FAILS = 5;
    /** Thời gian tạm khoá (giây) theo từng đợt khoá liên tiếp; đợt cuối lặp lại cho các lần sau. */
    private const LOGIN_LOCK_STEPS = [60, 300, 900, 1800, 3600];

    /** Số giây còn lại của đợt tạm khoá trên máy (phiên) này; 0 nếu không bị khoá. */
    public function loginLockedFor(): int
    {
        return max(0, (int)($_SESSION['login_lock_until'] ?? 0) - time());
    }

    /** Ghi nhận một lần sai mật khẩu của tài khoản có thật; đủ số lần thì khoá, mỗi đợt lâu hơn đợt trước. */
    public function loginFailed(): void
    {
        $fails = ($_SESSION['login_fail'] ?? 0) + 1;
        if ($fails < self::LOGIN_MAX_FAILS) {
            $_SESSION['login_fail'] = $fails;
            return;
        }
        $level = (int)($_SESSION['login_lock_level'] ?? 0);
        $steps = self::LOGIN_LOCK_STEPS;
        $_SESSION['login_lock_until'] = time() + $steps[min($level, count($steps) - 1)];
        $_SESSION['login_lock_level'] = $level + 1;
        $_SESSION['login_fail'] = 0;
    }

    public function loginOk(): void
    {
        unset($_SESSION['login_fail'], $_SESSION['login_lock_until'], $_SESSION['login_lock_level']);
    }
}
