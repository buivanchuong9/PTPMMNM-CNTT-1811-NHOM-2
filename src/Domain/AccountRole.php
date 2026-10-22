<?php
declare(strict_types=1);

namespace StockFlow\Domain;

/**
 * Vai trò của tài khoản.
 *  - ADMIN: tài khoản cấp cao (chủ hệ thống) — xem thống kê mọi kho và quản lý kho, mặt hàng, tài khoản, phân quyền;
 *    KHÔNG thao tác nhập/xuất/điều chuyển (việc của quản lý & nhân viên). Không gắn với kho nào.
 *  - MANAGER: quản lý kho — mỗi kho có đúng một.
 *  - STAFF: nhân viên kho — mỗi kho có nhiều.
 * Tài khoản MANAGER / STAFF luôn gắn với đúng một kho.
 */
enum AccountRole: string
{
    case Admin = 'ADMIN';
    case Manager = 'MANAGER';
    case Staff = 'STAFF';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Quản trị hệ thống',
            self::Manager => 'Quản lý kho',
            self::Staff => 'Nhân viên kho',
        };
    }

    /** Màu nhãn trên giao diện (dùng class tag-*). */
    public function tone(): string
    {
        return match ($this) {
            self::Admin => 'danger',
            self::Manager => 'info',
            self::Staff => 'muted',
        };
    }

    /** Vai trò gắn với một kho (cần chọn kho khi tạo tài khoản). */
    public function needsWarehouse(): bool
    {
        return $this !== self::Admin;
    }

    /** Các vai trò có thể chỉnh quyền chức năng (ADMIN luôn toàn quyền). */
    public static function configurable(): array
    {
        return [self::Manager, self::Staff];
    }
}
