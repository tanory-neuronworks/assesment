<?php

declare(strict_types=1);

namespace App\Repository\Mysql;

use App\Core\Pagination;
use App\Entity\Customer;
use App\Repository\Contracts\CustomerRepositoryInterface;
use PDO;

final class MysqlCustomerRepository implements CustomerRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findById(int $id): ?Customer
    {
        $stmt = $this->pdo->prepare('SELECT id, name, contact, address, is_active FROM customers WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    public function all(bool $onlyActive = false): array
    {
        $sql = 'SELECT id, name, contact, address, is_active FROM customers';
        if ($onlyActive) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY name ASC';

        $stmt = $this->pdo->query($sql);

        return array_map($this->hydrate(...), $stmt->fetchAll());
    }

    public function create(Customer $customer): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO customers (name, contact, address, is_active) VALUES (?, ?, ?, ?)');
        $stmt->execute([$customer->name, $customer->contact, $customer->address, (int) $customer->isActive]);

        $customer->id = (int) $this->pdo->lastInsertId();

        return $customer->id;
    }

    public function update(Customer $customer): void
    {
        $stmt = $this->pdo->prepare('UPDATE customers SET name = ?, contact = ?, address = ?, is_active = ? WHERE id = ?');
        $stmt->execute([$customer->name, $customer->contact, $customer->address, (int) $customer->isActive, $customer->id]);
    }

    public function setActive(int $id, bool $active): void
    {
        $stmt = $this->pdo->prepare('UPDATE customers SET is_active = ? WHERE id = ?');
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

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM customers{$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $listStmt = $this->pdo->prepare("SELECT id, name, contact, address, is_active FROM customers{$whereSql} ORDER BY name ASC LIMIT ? OFFSET ?");
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

    private function hydrate(array $row): Customer
    {
        return new Customer(
            id: (int) $row['id'],
            name: (string) $row['name'],
            contact: (string) $row['contact'],
            address: (string) $row['address'],
            isActive: (bool) $row['is_active'],
        );
    }
}
