<?php
declare(strict_types=1);

namespace StockFlow\Http\Controller;

use StockFlow\Core\Request;
use StockFlow\Core\Response;
use StockFlow\Core\Session;
use StockFlow\Core\View;
use StockFlow\Domain\AccountRole;
use StockFlow\Domain\DomainError;
use StockFlow\Domain\NotFound;
use StockFlow\Domain\Forbidden;
use StockFlow\Domain\User;
use StockFlow\Domain\VnAddress;
use StockFlow\Service\Authorizer;
use StockFlow\Service\SettingsService;

/** Trang «Cài đặt»: nội dung theo vai trò (nhân viên → cá nhân; quản lý → + kho của mình; quản trị → + hệ thống, nhật ký). */
final class SettingsController extends Controller
{
    public function __construct(View $view, Session $session, Authorizer $auth, private SettingsService $settings)
    {
        parent::__construct($view, $session, $auth);
    }

    /** @return list<string> các tab tài khoản này được xem */
    private function tabsFor(User $user): array
    {
        return match ($user->role) {
            AccountRole::Admin => ['ca-nhan', 'he-thong', 'nhat-ky'],
            AccountRole::Manager => ['ca-nhan'],
            AccountRole::Staff => ['ca-nhan'],
        };
    }

    public function index(Request $r, array $p, ?User $user): Response
    {
        $tabs = $this->tabsFor($user);
        $tab = in_array($r->str('tab'), $tabs, true) ? $r->str('tab') : 'ca-nhan';
        $data = ['tabs' => $tabs, 'tab' => $tab, 'prefs' => $this->settings->preferences($user), 'system' => $this->settings->system(),
                 'rowOptions' => SettingsService::ROWS, 'idleOptions' => SettingsService::IDLE];
        if ($tab === 'he-thong') {
            $data['overview'] = $this->settings->overview();
        } elseif ($tab === 'nhat-ky') {
            $data['log'] = $this->settings->recentAudit($user);
        }
        return $this->render('settings/index', $user, 'settings', $data, 'Cài đặt');
    }

    private function back(string $tab): string
    {
        return '/settings?tab=' . $tab;
    }

    public function profile(Request $r, array $p, ?User $user): Response
    {
        return $this->act(fn() => $this->settings->updateProfile($user, $r->str('full_name')), 'Đã cập nhật hồ sơ.', $this->back('ca-nhan'));
    }

    public function password(Request $r, array $p, ?User $user): Response
    {
        return $this->act(fn() => $this->settings->changePassword($user, (string)($r->post['current'] ?? ''), (string)($r->post['password'] ?? ''), (string)($r->post['confirm'] ?? '')),
            'Đã đổi mật khẩu.', $this->back('ca-nhan'));
    }

    public function preferences(Request $r, array $p, ?User $user): Response
    {
        return $this->act(fn() => $this->settings->savePreferences($user, $r->int('rows') ?? ($r->str('rows') === '' ? 0 : -1), $r->str('compact') === '1'), 'Đã lưu tuỳ chọn hiển thị.', $this->back('ca-nhan'));
    }

    public function avatarUpload(Request $r, array $p, ?User $user): Response
    {
        return $this->act(function () use ($r, $user) {
            $f = $r->files['avatar'] ?? null;
            $err = is_array($f) ? (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
            if ($err === UPLOAD_ERR_NO_FILE) {
                throw new DomainError('Hãy chọn một ảnh');
            }
            if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                throw new DomainError('Ảnh quá lớn — tối đa 2 MB');
            }
            if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) {
                throw new DomainError('Tải ảnh lên không thành công. Hãy thử lại.');
            }
            $this->settings->saveAvatar($user, (string)$f['tmp_name'], (int)$f['size']);
        }, 'Đã cập nhật ảnh đại diện.', $this->back('ca-nhan'));
    }

    public function avatarRemove(Request $r, array $p, ?User $user): Response
    {
        return $this->act(fn() => $this->settings->removeAvatar($user), 'Đã xoá ảnh đại diện.', $this->back('ca-nhan'));
    }

    /** Phục vụ file ảnh (chỉ tài khoản đã đăng nhập). Đường dẫn file không bao giờ lấy từ yêu cầu — chỉ từ tên do hệ thống sinh trong CSDL. */
    public function avatarFile(Request $r, array $p, ?User $user): Response
    {
        $f = $this->settings->avatarFile((int)$p['id']) ?? throw new NotFound('Không có ảnh đại diện');
        return new Response((string)file_get_contents($f['path']), 200, [
            'Content-Type' => $f['mime'], 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=604800', 'Content-Disposition' => 'inline',
        ]);
    }

    public function staffActive(Request $r, array $p, ?User $user): Response
    {
        $on = $r->str('active') === '1';
        return $this->act(fn() => $this->settings->setStaffActive($user, (int)$r->int('user_id'), $on),
            $on ? 'Đã mở khoá nhân viên.' : 'Đã khoá nhân viên — không đăng nhập được nữa, lịch sử thao tác vẫn được giữ.', '/warehouses');
    }

    public function staffUpdate(Request $r, array $p, ?User $user): Response
    {
        return $this->act(fn() => $this->settings->editStaff($user, (int)$r->int('user_id'), $r->str('username'), (string)($r->post['password'] ?? '')),
            'Đã lưu thay đổi nhân viên. Tên đăng nhập / mật khẩu mới dùng được ngay.', '/warehouses');
    }

    public function system(Request $r, array $p, ?User $user): Response
    {
        if (!$user->isAdmin) {
            throw new Forbidden('Chỉ quản trị hệ thống mới chỉnh được cài đặt hệ thống');
        }
        return $this->act(fn() => $this->settings->saveSystem($user, (int)$r->int('rows'), (int)$r->int('min_password'), (int)$r->int('idle')), 'Đã lưu cài đặt hệ thống.', $this->back('he-thong'));
    }
}
