<?php

declare(strict_types=1);

namespace App\Entity;

enum SalesOrderStatus: string
{
    case Draft = 'Draft';
    case PendingApproval = 'PendingApproval';
    case Approved = 'Approved';
    case Fulfilled = 'Fulfilled';
    case Cancelled = 'Cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingApproval => 'Menunggu Persetujuan',
            self::Approved => 'Disetujui',
            self::Fulfilled => 'Terpenuhi',
            self::Cancelled => 'Dibatalkan',
        };
    }
}
