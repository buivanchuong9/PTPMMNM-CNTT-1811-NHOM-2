<?php
declare(strict_types=1);

namespace StockFlow\Domain;

final class User
{
    /** Tài khoản cấp cao của hệ thống (toàn quyền, thấy mọi kho). */
    public readonly bool $isAdmin;

    /** @param ?int $warehouseId kho mà tài khoản được gắn; null với ADMIN */
    public function __construct(public readonly int $id, public readonly string $username, public readonly string $fullName,
                                public readonly AccountRole $role, public readonly ?int $warehouseId = null)
    {
        $this->isAdmin = $role === AccountRole::Admin;
    }

    /** Chuẩn hoá + kiểm tra tên đăng nhập: chữ thường, 3–40 ký tự a-z 0-9 . _ - */
    public static function normalizeUsername(string $username): string
    {
        $username = strtolower(trim($username));
        if (!preg_match('/^[a-z0-9._-]{3,40}$/', $username)) {
            throw new DomainError('Tên đăng nhập gồm 3–40 ký tự a-z, 0-9, ., _ hoặc -');
        }
        return $username;
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/u', trim(preg_replace('/\(.*?\)/u', '', $this->fullName))) ?: [];
        $last = end($parts) ?: $this->username;
        return mb_strtoupper(mb_substr($last, 0, 1));
    }
}
