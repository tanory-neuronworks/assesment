<?php

declare(strict_types=1);

namespace App\Repository\Mysql;

use App\Core\Pagination;
use App\Entity\Role;
use App\Entity\User;
use App\Repository\Contracts\UserRepositoryInterface;
use PDO;

final class MysqlUserRepository implements UserRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findById(int $id): ?User
    {
        $stmt = $this->pdo->prepare('SELECT id, name, username, email, password_hash, role, is_active, created_at, updated_at FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    public function findByEmail(string $email): ?User
    {
        $stmt = $this->pdo->prepare('SELECT id, name, username, email, password_hash, role, is_active, created_at, updated_at FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    public function findByUsername(string $username): ?User
    {
        $stmt = $this->pdo->prepare('SELECT id, name, username, email, password_hash, role, is_active, created_at, updated_at FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    public function all(): array
    {
        $stmt = $this->pdo->query('SELECT id, name, username, email, password_hash, role, is_active, created_at, updated_at FROM users ORDER BY name ASC');

        return array_map($this->hydrate(...), $stmt->fetchAll());
    }

    public function paginate(?string $search, int $page, int $perPage): Pagination
    {
        $where = [];
        $params = [];

        if ($search !== null && $search !== '') {
            // Matches name/email text, plus a reverse lookup from the
            // human-readable role/status label back to its raw column value
            // (role enum, is_active) - same convention used for the other
            // master-data list pages (Aktif/Nonaktif, PO/SO status search).
            $like = '%' . $search . '%';
            $clauses = ['name LIKE ?', 'username LIKE ?', 'email LIKE ?'];
            $clauseParams = [$like, $like, $like];

            foreach (Role::cases() as $role) {
                if (stripos($role->label(), $search) !== false) {
                    $clauses[] = 'role = ?';
                    $clauseParams[] = $role->value;
                }
            }

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

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM users{$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $listStmt = $this->pdo->prepare("SELECT id, name, username, email, password_hash, role, is_active, created_at, updated_at FROM users{$whereSql} ORDER BY name ASC LIMIT ? OFFSET ?");
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

    public function create(User $user): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (name, username, email, password_hash, role, is_active) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $user->name,
            $user->username,
            $user->email,
            $user->passwordHash,
            $user->role->value,
            (int) $user->isActive,
        ]);

        $user->id = (int) $this->pdo->lastInsertId();

        return $user->id;
    }

    public function update(User $user): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET name = ?, username = ?, email = ?, password_hash = ?, role = ?, is_active = ? WHERE id = ?'
        );
        $stmt->execute([
            $user->name,
            $user->username,
            $user->email,
            $user->passwordHash,
            $user->role->value,
            (int) $user->isActive,
            $user->id,
        ]);
    }

    public function setActive(int $id, bool $active): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET is_active = ? WHERE id = ?');
        $stmt->execute([(int) $active, $id]);
    }

    private function hydrate(array $row): User
    {
        return new User(
            id: (int) $row['id'],
            name: (string) $row['name'],
            username: (string) $row['username'],
            email: (string) $row['email'],
            passwordHash: (string) $row['password_hash'],
            role: Role::from((string) $row['role']),
            isActive: (bool) $row['is_active'],
            createdAt: $row['created_at'] ?? null,
            updatedAt: $row['updated_at'] ?? null,
        );
    }
}
