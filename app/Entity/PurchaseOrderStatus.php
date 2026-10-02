<?php

declare(strict_types=1);

namespace App\Entity;

enum PurchaseOrderStatus: string
{
    case Draft = 'Draft';
    case Ordered = 'Ordered';
    case PartiallyReceived = 'PartiallyReceived';
    case Received = 'Received';
    case Cancelled = 'Cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Ordered => 'Dipesan',
            self::PartiallyReceived => 'Diterima Sebagian',
            self::Received => 'Diterima Penuh',
            self::Cancelled => 'Dibatalkan',
        };
    }
}
