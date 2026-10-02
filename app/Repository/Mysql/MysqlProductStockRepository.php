<?php

declare(strict_types=1);

namespace App\Repository\Mysql;

use App\Entity\ProductStock;
use App\Repository\Contracts\ProductStockRepositoryInterface;
use PDO;

final class MysqlProductStockRepository implements ProductStockRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findByProduct(int $productId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ps.product_id, ps.warehouse_id, ps.quantity, w.name AS warehouse_name FROM product_stocks ps
             JOIN warehouses w ON w.id = ps.warehouse_id
             WHERE ps.product_id = ?
             ORDER BY w.name ASC'
        );
        $stmt->execute([$productId]);

        return array_map($this->hydrate(...), $stmt->fetchAll());
    }

    public function totalForProduct(int $productId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(quantity), 0) FROM product_stocks WHERE product_id = ?');
        $stmt->execute([$productId]);

        return (int) $stmt->fetchColumn();
    }

    public function totalsForProducts(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT product_id, COALESCE(SUM(quantity), 0) AS total FROM product_stocks
             WHERE product_id IN ({$placeholders}) GROUP BY product_id"
        );
        $stmt->execute($productIds);

        $totals = [];
        foreach ($stmt->fetchAll() as $row) {
            $totals[(int) $row['product_id']] = (int) $row['total'];
        }

        return $totals;
    }

    public function incrementStock(int $productId, int $warehouseId, int $quantity): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO product_stocks (product_id, warehouse_id, quantity) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)'
        );
        $stmt->execute([$productId, $warehouseId, $quantity]);
    }

    public function decrementStockIfSufficient(int $productId, int $warehouseId, int $quantity): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE product_stocks SET quantity = quantity - ?
             WHERE product_id = ? AND warehouse_id = ? AND quantity >= ?'
        );
        $stmt->execute([$quantity, $productId, $warehouseId, $quantity]);

        return $stmt->rowCount() > 0;
    }

    private function hydrate(array $row): ProductStock
    {
        return new ProductStock(
            productId: (int) $row['product_id'],
            warehouseId: (int) $row['warehouse_id'],
            quantity: (int) $row['quantity'],
            warehouseName: $row['warehouse_name'] ?? null,
        );
    }
}
