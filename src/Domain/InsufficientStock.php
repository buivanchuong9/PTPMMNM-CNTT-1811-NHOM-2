<?php
declare(strict_types=1);

namespace StockFlow\Domain;

/** Tồn không đủ cho một dòng (kho, hàng, lô). */
final class InsufficientStock extends DomainError
{
    public function __construct(public readonly string $sku, public readonly string $batchNo, public readonly string $warehouse,
                                public readonly int $available, public readonly int $required)
    {
        parent::__construct("Không đủ tồn: $sku (lô $batchNo) tại kho $warehouse — hiện có $available, cần $required");
    }
}
