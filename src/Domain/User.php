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

    public function initials(): string
    {
        $parts = preg_split('/\s+/u', trim(preg_replace('/\(.*?\)/u', '', $this->fullName))) ?: [];
        $last = end($parts) ?: $this->username;
        return mb_strtoupper(mb_substr($last, 0, 1));
    }
}
