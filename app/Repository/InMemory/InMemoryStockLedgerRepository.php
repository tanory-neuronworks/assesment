<?php

declare(strict_types=1);

namespace App\Repository\InMemory;

use App\Entity\ReferenceType;
use App\Entity\StockLedgerEntry;
use App\Repository\Contracts\StockLedgerRepositoryInterface;

final class InMemoryStockLedgerRepository implements StockLedgerRepositoryInterface
{
    /** @var StockLedgerEntry[] */
    private array $entries = [];

    private int $nextId = 1;

    public function create(StockLedgerEntry $entry): int
    {
        $entry->id = $this->nextId++;
        $entry->createdAt = $entry->createdAt ?? date('Y-m-d H:i:s');
        $this->entries[] = $entry;

        return $entry->id;
    }

    public function findByProduct(int $productId, int $limit = 20): array
    {
        $matches = array_values(array_filter($this->entries, static fn (StockLedgerEntry $e) => $e->productId === $productId));
        $matches = array_reverse($matches);

        return array_slice($matches, 0, $limit);
    }

    public function findByReference(ReferenceType $referenceType, int $referenceId): array
    {
        $matches = array_filter(
            $this->entries,
            static fn (StockLedgerEntry $e) => $e->referenceType === $referenceType && $e->referenceId === $referenceId
        );

        return array_reverse(array_values($matches));
    }

    public function findInRange(string $from, string $to): array
    {
        return array_values(array_filter($this->entries, static function (StockLedgerEntry $e) use ($from, $to) {
            $date = substr((string) $e->createdAt, 0, 10);

            return $date >= $from && $date <= $to;
        }));
    }
}
