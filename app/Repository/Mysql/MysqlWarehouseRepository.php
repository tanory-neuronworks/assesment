<?php

declare(strict_types=1);

namespace App\Repository\Mysql;

use App\Core\Pagination;
use App\Entity\Warehouse;
use App\Repository\Contracts\WarehouseRepositoryInterface;
use PDO;

final class MysqlWarehouseRepository implements WarehouseRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findById(int $id): ?Warehouse
    {
        $stmt = $this->pdo->prepare('SELECT id, name, location, is_active FROM warehouses WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    public function all(bool $onlyActive = false): array
    {
        $sql = 'SELECT id, name, location, is_active FROM warehouses';
        if ($onlyActive) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY name ASC';

        $stmt = $this->pdo->query($sql);

        return array_map($this->hydrate(...), $stmt->fetchAll());
    }

    public function create(Warehouse $warehouse): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO warehouses (name, location, is_active) VALUES (?, ?, ?)');
        $stmt->execute([$warehouse->name, $warehouse->location, (int) $warehouse->isActive]);

        $warehouse->id = (int) $this->pdo->lastInsertId();

        return $warehouse->id;
    }

    public function update(Warehouse $warehouse): void
    {
        $stmt = $this->pdo->prepare('UPDATE warehouses SET name = ?, location = ?, is_active = ? WHERE id = ?');
        $stmt->execute([$warehouse->name, $warehouse->location, (int) $warehouse->isActive, $warehouse->id]);
    }

    public function setActive(int $id, bool $active): void
    {
        $stmt = $this->pdo->prepare('UPDATE warehouses SET is_active = ? WHERE id = ?');
        $stmt->execute([(int) $active, $id]);
    }

    public function nameExists(string $name, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM warehouses WHERE name = ?';
        $params = [$name];
        if ($excludeId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $excludeId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function paginate(?string $search, int $page, int $perPage): Pagination
    {
        $where = [];
        $params = [];

        if ($search !== null && $search !== '') {
            // Matches name/location text, plus a reverse lookup from the
            // human-readable Aktif/Nonaktif label back to is_active - same
            // substring-match convention used for PO/SO status search.
            $like = '%' . $search . '%';
            $clauses = ['name LIKE ?', 'location LIKE ?'];
            $clauseParams = [$like, $like];

            foreach (['Aktif' => 1, 'Nonaktif' => 0] as $label => $value) {
                if (stripos($label, $search) !== false) {
                    $clauses[] = 'is_active = ?';
                    $clauseParams[] = $value;
                }
            }

            $where[] = '(' . implode(' OR ', $clauses) . ')';
            array_push($params, ...$clauseParams);
        }

        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM warehouses{$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $listStmt = $this->pdo->prepare("SELECT id, name, location, is_active FROM warehouses{$whereSql} ORDER BY name ASC LIMIT ? OFFSET ?");
        $position = 1;
        foreach ($params as $value) {
            $listStmt->bindValue($position++, $value);
        }
        $listStmt->bindValue($position++, $perPage, PDO::PARAM_INT);
        $listStmt->bindValue($position++, $offset, PDO::PARAM_INT);
        $listStmt->execute();

        $items = array_map($this->hydrate(...), $listStmt->fetchAll());

        return new Pagination($items, $total, $page, $perPage);
    }

    private function hydrate(array $row): Warehouse
    {
        return new Warehouse(
            id: (int) $row['id'],
            name: (string) $row['name'],
            location: (string) $row['location'],
            isActive: (bool) $row['is_active'],
        );
    }
}
