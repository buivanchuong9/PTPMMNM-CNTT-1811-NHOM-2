<?php
declare(strict_types=1);

namespace StockFlow\Domain;

enum Role: string
{
    case Keeper = 'KEEPER';
    case Approver = 'APPROVER';
    case Counter = 'COUNTER';

    public function label(): string
    {
        return match ($this) {
            self::Keeper => 'Nhân viên kho',
            self::Approver => 'Người duyệt',
            self::Counter => 'Phụ trách kiểm kê',
        };
    }
}
