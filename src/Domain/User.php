<?php
declare(strict_types=1);

namespace StockFlow\Domain;

final class User
{
    public function __construct(public readonly int $id, public readonly string $username,
                                public readonly string $fullName, public readonly bool $isAdmin) {}

    public function initials(): string
    {
        $parts = preg_split('/\s+/u', trim(preg_replace('/\(.*?\)/u', '', $this->fullName))) ?: [];
        $last = end($parts) ?: $this->username;
        return mb_strtoupper(mb_substr($last, 0, 1));
    }
}
