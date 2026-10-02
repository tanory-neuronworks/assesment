<?php

declare(strict_types=1);

namespace App\Entity;

enum StockMovementType: string
{
    case Receipt = 'Receipt';
    case Issue = 'Issue';
    case Adjustment = 'Adjustment';
}
