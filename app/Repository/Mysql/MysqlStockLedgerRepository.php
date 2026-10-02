<?php

declare(strict_types=1);

namespace App\Repository\Mysql;

use App\Entity\ReferenceType;
use App\Entity\StockLedgerEntry;
use App\Entity\StockMovementType;
use App\Repository\Contracts\StockLedgerRepositoryInterface;
use PDO;

final class MysqlStockLedgerRepository implements StockLedgerRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(StockLedgerEntry $entry): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO stock_ledger (product_id, warehouse_id, movement_type, quantity, reference_type, reference_id, performed_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $entry->productId,
            $entry->warehouseId,
            $entry->movementType->value,
            $entry->quantity,
            $entry->referenceType->value,
            $entry->referenceId,
            $entry->performedBy,
        ]);

        $entry->id = (int) $this->pdo->lastInsertId();

        return $entry->id;
    }

    public function findByProduct(int $productId, int $limit = 20): array
    {
        $stmt = $this->pdo->prepare($this->baseQuery() . ' WHERE sl.product_id = ? ORDER BY sl.created_at DESC, sl.id DESC LIMIT ?');
        $stmt->bindValue(1, $productId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map($this->hydrate(...), $stmt->fetchAll());
    }

    public function findByReference(ReferenceType $referenceType, int $referenceId): array
    {
        $stmt = $this->pdo->prepare(
            $this->baseQuery() . ' WHERE sl.reference_type = ? AND sl.reference_id = ? ORDER BY sl.created_at DESC, sl.id DESC'
        );
        $stmt->execute([$referenceType->value, $referenceId]);

        return array_map($this->hydrate(...), $stmt->fetchAll());
    }

    public function findInRange(string $from, string $to): array
    {
        $stmt = $this->pdo->prepare(
            $this->baseQuery() . ' WHERE DATE(sl.created_at) BETWEEN ? AND ? ORDER BY sl.created_at ASC, sl.id ASC'
        );
        $stmt->execute([$from, $to]);

        return array_map($this->hydrate(...), $stmt->fetchAll());
    }

    private function baseQuery(): string
    {
        return 'SELECT sl.id, sl.product_id, sl.warehouse_id, sl.movement_type, sl.quantity, sl.reference_type, sl.reference_id, sl.performed_by, sl.created_at, w.name AS warehouse_name, u.name AS performed_by_name,
                       p.name AS product_name, p.sku AS product_sku
                FROM stock_ledger sl
                JOIN warehouses w ON w.id = sl.warehouse_id
                JOIN users u ON u.id = sl.performed_by
                JOIN products p ON p.id = sl.product_id';
    }

    private function hydrate(array $row): StockLedgerEntry
    {
        return new StockLedgerEntry(
            id: (int) $row['id'],
            productId: (int) $row['product_id'],
            warehouseId: (int) $row['warehouse_id'],
            movementType: StockMovementType::from((string) $row['movement_type']),
            quantity: (int) $row['quantity'],
            referenceType: ReferenceType::from((string) $row['reference_type']),
            referenceId: $row['reference_id'] !== null ? (int) $row['reference_id'] : null,
            performedBy: (int) $row['performed_by'],
            warehouseName: $row['warehouse_name'] ?? null,
            performedByName: $row['performed_by_name'] ?? null,
            createdAt: $row['created_at'] ?? null,
            productName: $row['product_name'] ?? null,
            productSku: $row['product_sku'] ?? null,
        );
    }
}
