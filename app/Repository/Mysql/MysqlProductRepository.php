<?php

declare(strict_types=1);

namespace App\Repository\Mysql;

use App\Core\Pagination;
use App\Entity\Product;
use App\Repository\Contracts\ProductRepositoryInterface;
use PDO;

final class MysqlProductRepository implements ProductRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findById(int $id): ?Product
    {
        $stmt = $this->pdo->prepare($this->baseQuery() . ' WHERE p.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    public function findBySku(string $sku): ?Product
    {
        $stmt = $this->pdo->prepare($this->baseQuery() . ' WHERE p.sku = ?');
        $stmt->execute([$sku]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    public function all(bool $onlyActive = false): array
    {
        $sql = $this->baseQuery();
        if ($onlyActive) {
            $sql .= ' WHERE p.is_active = 1';
        }
        $sql .= ' ORDER BY p.name ASC';

        $stmt = $this->pdo->query($sql);

        return array_map($this->hydrate(...), $stmt->fetchAll());
    }

    public function create(Product $product): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO products (sku, name, category_id, unit, cost_price, sell_price, reorder_point, image, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $product->sku,
            $product->name,
            $product->categoryId,
            $product->unit,
            $product->costPrice,
            $product->sellPrice,
            $product->reorderPoint,
            $product->image,
            (int) $product->isActive,
        ]);

        $product->id = (int) $this->pdo->lastInsertId();

        return $product->id;
    }

    public function update(Product $product): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE products SET sku = ?, name = ?, category_id = ?, unit = ?, cost_price = ?, sell_price = ?,
             reorder_point = ?, image = ?, is_active = ? WHERE id = ?'
        );
        $stmt->execute([
            $product->sku,
            $product->name,
            $product->categoryId,
            $product->unit,
            $product->costPrice,
            $product->sellPrice,
            $product->reorderPoint,
            $product->image,
            (int) $product->isActive,
            $product->id,
        ]);
    }

    public function setActive(int $id, bool $active): void
    {
        $stmt = $this->pdo->prepare('UPDATE products SET is_active = ? WHERE id = ?');
        $stmt->execute([(int) $active, $id]);
    }

    public function skuExists(string $sku, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM products WHERE sku = ?';
        $params = [$sku];
        if ($excludeId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $excludeId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function lowStockCount(): int
    {
        $sql = 'SELECT COUNT(*) FROM (
                    SELECT p.id, p.reorder_point, COALESCE(SUM(ps.quantity), 0) AS total_stock
                    FROM products p
                    LEFT JOIN product_stocks ps ON ps.product_id = p.id
                    WHERE p.is_active = 1
                    GROUP BY p.id, p.reorder_point
                    HAVING total_stock < p.reorder_point
                ) low_stock';

        return (int) $this->pdo->query($sql)->fetchColumn();
    }

    public function inventoryValue(): float
    {
        $sql = 'SELECT COALESCE(SUM(ps.quantity * p.cost_price), 0)
                FROM product_stocks ps
                JOIN products p ON p.id = ps.product_id';

        return (float) $this->pdo->query($sql)->fetchColumn();
    }

    public function paginate(?string $search, ?int $categoryId, ?string $stockStatus, int $page, int $perPage): Pagination
    {
        $where = [];
        $params = [];

        if ($categoryId !== null) {
            $where[] = 'p.category_id = ?';
            $params[] = $categoryId;
        }

        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        // Free-text search must span every column the Products table
        // actually displays (SKU, Nama, Kategori, Harga Jual, Stok, Status),
        // several of which only exist after aggregation/derivation - so it
        // lives in HAVING, not WHERE. MySQL only resolves HAVING against
        // aggregates or the SELECT list, so every referenced column below
        // (sku, name, category_name, sell_price, total_stock, status_label,
        // and p.reorder_point via MAX()) must appear in both this query's
        // and the count subquery's SELECT list.
        $havingClauses = [];
        $havingParams = [];

        if ($stockStatus === 'low') {
            $havingClauses[] = 'total_stock < MAX(p.reorder_point)';
        } elseif ($stockStatus === 'normal') {
            $havingClauses[] = 'total_stock >= MAX(p.reorder_point)';
        }

        if ($search !== null && $search !== '') {
            $like = '%' . $search . '%';
            $havingClauses[] = '(sku LIKE ? OR name LIKE ? OR category_name LIKE ?
                                  OR sell_price LIKE ? OR total_stock LIKE ? OR status_label LIKE ?)';
            $havingParams = [$like, $like, $like, $like, $like, $like];
        }

        $having = $havingClauses === [] ? '' : ' HAVING ' . implode(' AND ', $havingClauses);

        $fromAndGroup = "FROM products p
                          JOIN categories c ON c.id = p.category_id
                          LEFT JOIN product_stocks ps ON ps.product_id = p.id
                          {$whereSql}
                          GROUP BY p.id
                          {$having}";

        $selectExtra = "p.sku, p.name, c.name AS category_name, p.sell_price,
                         COALESCE(SUM(ps.quantity), 0) AS total_stock,
                         CASE WHEN p.is_active = 1 THEN 'Aktif' ELSE 'Nonaktif' END AS status_label";

        $countStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM (SELECT p.id, {$selectExtra} {$fromAndGroup}) counted"
        );
        $countStmt->execute([...$params, ...$havingParams]);
        $total = (int) $countStmt->fetchColumn();

        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $listStmt = $this->pdo->prepare(
            "SELECT p.id, p.sku, p.name, p.category_id, p.unit, p.cost_price, p.sell_price, p.reorder_point, p.image, p.is_active, c.name AS category_name,
                    COALESCE(SUM(ps.quantity), 0) AS total_stock,
                    CASE WHEN p.is_active = 1 THEN 'Aktif' ELSE 'Nonaktif' END AS status_label
             {$fromAndGroup}
             ORDER BY p.name ASC
             LIMIT ? OFFSET ?"
        );
        $position = 1;
        foreach ([...$params, ...$havingParams] as $value) {
            $listStmt->bindValue($position++, $value);
        }
        $listStmt->bindValue($position++, $perPage, PDO::PARAM_INT);
        $listStmt->bindValue($position++, $offset, PDO::PARAM_INT);
        $listStmt->execute();

        $items = array_map($this->hydrate(...), $listStmt->fetchAll());

        return new Pagination($items, $total, $page, $perPage);
    }

    private function baseQuery(): string
    {
        return 'SELECT p.id, p.sku, p.name, p.category_id, p.unit, p.cost_price, p.sell_price, p.reorder_point, p.image, p.is_active, c.name AS category_name FROM products p
                JOIN categories c ON c.id = p.category_id';
    }

    private function hydrate(array $row): Product
    {
        return new Product(
            id: (int) $row['id'],
            sku: (string) $row['sku'],
            name: (string) $row['name'],
            categoryId: (int) $row['category_id'],
            unit: (string) $row['unit'],
            costPrice: (float) $row['cost_price'],
            sellPrice: (float) $row['sell_price'],
            reorderPoint: (int) $row['reorder_point'],
            image: $row['image'] ?? null,
            isActive: (bool) $row['is_active'],
            categoryName: $row['category_name'] ?? null,
        );
    }
}
