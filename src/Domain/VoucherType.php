<?php
declare(strict_types=1);

namespace StockFlow\Domain;

enum VoucherType: string
{
    case Receipt = 'RECEIPT';
    case Issue = 'ISSUE';
    case TransferOut = 'TRANSFER_OUT';
    case TransferIn = 'TRANSFER_IN';
    case Adjustment = 'ADJUSTMENT';

    public function label(): string
    {
        return match ($this) {
            self::Receipt => 'Phiếu nhập',
            self::Issue => 'Phiếu xuất',
            self::TransferOut => 'Điều chuyển đi',
            self::TransferIn => 'Điều chuyển đến',
            self::Adjustment => 'Điều chỉnh',
        };
    }

    public function prefix(): string
    {
        return match ($this) {
            self::Receipt => 'PN', self::Issue => 'PX', self::TransferOut => 'DCX',
            self::TransferIn => 'DCN', self::Adjustment => 'DCH',
        };
    }

    /** Dấu của tác động lên tồn đối với số lượng dương của dòng phiếu (điều chỉnh: qty đã mang dấu). */
    public function sign(): int
    {
        return match ($this) {
            self::Receipt, self::TransferIn, self::Adjustment => 1,
            self::Issue, self::TransferOut => -1,
        };
    }

    public function isSigned(): bool
    {
        return $this === self::Adjustment;
    }
}
