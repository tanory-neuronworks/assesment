<?php

declare(strict_types=1);

namespace App\Entity;

enum Role: string
{
    case Admin = 'Admin';
    case Sales = 'Sales';
    case WarehouseStaff = 'WarehouseStaff';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Sales => 'Sales',
            self::WarehouseStaff => 'Warehouse Staff',
        };
    }
}
