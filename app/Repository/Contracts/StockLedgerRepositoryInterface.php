<?php

declare(strict_types=1);

namespace App\Repository\Contracts;

use App\Entity\ReferenceType;
use App\Entity\StockLedgerEntry;

interface StockLedgerRepositoryInterface
{
    public function create(StockLedgerEntry $entry): int;

    /**
     * @return StockLedgerEntry[] most recent first
     */
    public function findByProduct(int $productId, int $limit = 20): array;

    /**
     * @return StockLedgerEntry[] most recent first
     */
    public function findByReference(ReferenceType $referenceType, int $referenceId): array;

    /**
     * @return StockLedgerEntry[] oldest first, created_at date between $from and $to inclusive
     */
    public function findInRange(string $from, string $to): array;
}
