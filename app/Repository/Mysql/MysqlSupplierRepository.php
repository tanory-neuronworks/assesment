<?php

declare(strict_types=1);

namespace App\Repository\Mysql;

use App\Core\Pagination;
use App\Entity\Supplier;
use App\Repository\Contracts\SupplierRepositoryInterface;
use PDO;

final class MysqlSupplierRepository implements SupplierRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findById(int $id): ?Supplier
    {
        $stmt = $this->pdo->prepare('SELECT id, name, contact, address, is_active FROM suppliers WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    public function all(bool $onlyActive = false): array
    {
        $sql = 'SELECT id, name, contact, address, is_active FROM suppliers';
        if ($onlyActive) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY name ASC';

        $stmt = $this->pdo->query($sql);

        return array_map($this->hydrate(...), $stmt->fetchAll());
    }

    public function create(Supplier $supplier): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO suppliers (name, contact, address, is_active) VALUES (?, ?, ?, ?)');
        $stmt->execute([$supplier->name, $supplier->contact, $supplier->address, (int) $supplier->isActive]);

        $supplier->id = (int) $this->pdo->lastInsertId();

        return $supplier->id;
    }

    public function update(Supplier $supplier): void
    {
        $stmt = $this->pdo->prepare('UPDATE suppliers SET name = ?, contact = ?, address = ?, is_active = ? WHERE id = ?');
        $stmt->execute([$supplier->name, $supplier->contact, $supplier->address, (int) $supplier->isActive, $supplier->id]);
    }

    public function setActive(int $id, bool $active): void
    {
        $stmt = $this->pdo->prepare('UPDATE suppliers SET is_active = ? WHERE id = ?');
        $stmt->execute([(int) $active, $id]);
    }

    public function paginate(?string $search, int $page, int $perPage): Pagination
    {
        $where = [];
        $params = [];

        if ($search !== null && $search !== '') {
            $like = '%' . $search . '%';
            $clauses = ['name LIKE ?', 'contact LIKE ?', 'address LIKE ?'];
            $clauseParams = [$like, $like, $like];

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

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM suppliers{$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $listStmt = $this->pdo->prepare("SELECT id, name, contact, address, is_active FROM suppliers{$whereSql} ORDER BY name ASC LIMIT ? OFFSET ?");
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

    private function hydrate(array $row): Supplier
    {
        return new Supplier(
            id: (int) $row['id'],
            name: (string) $row['name'],
            contact: (string) $row['contact'],
            address: (string) $row['address'],
            isActive: (bool) $row['is_active'],
        );
    }
}
