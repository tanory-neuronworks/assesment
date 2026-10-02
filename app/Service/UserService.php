<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Pagination;
use App\Entity\Role;
use App\Entity\User;
use App\Repository\Contracts\UserRepositoryInterface;

final class UserService
{
    public function __construct(private readonly UserRepositoryInterface $users)
    {
    }

    /**
     * @return User[]
     */
    public function list(): array
    {
        return $this->users->all();
    }

    public function paginate(?string $search, int $page, int $perPage = 10): Pagination
    {
        return $this->users->paginate($search, $page, $perPage);
    }

    public function find(int $id): User
    {
        $user = $this->users->findById($id);
        if ($user === null) {
            throw new NotFoundException("User #{$id} not found");
        }

        return $user;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function create(array $data): User
    {
        $errors = $this->validate($data, null);
        $errors = array_merge($errors, $this->validatePassword($data['password'] ?? '', true));

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $user = new User(
            id: null,
            name: trim((string) $data['name']),
            username: trim((string) $data['username']),
            email: strtolower(trim((string) $data['email'])),
            passwordHash: password_hash((string) $data['password'], PASSWORD_DEFAULT),
            role: Role::from((string) $data['role']),
            isActive: true,
        );

        $this->users->create($user);

        return $user;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function update(int $id, array $data): User
    {
        $user = $this->find($id);

        $errors = $this->validate($data, $id);
        $password = trim((string) ($data['password'] ?? ''));
        if ($password !== '') {
            $errors = array_merge($errors, $this->validatePassword($password, false));
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $user->name = trim((string) $data['name']);
        $user->username = trim((string) $data['username']);
        $user->email = strtolower(trim((string) $data['email']));
        $user->role = Role::from((string) $data['role']);
        if ($password !== '') {
            $user->passwordHash = password_hash($password, PASSWORD_DEFAULT);
        }

        $this->users->update($user);

        return $user;
    }

    public function setActive(int $id, bool $active): void
    {
        $this->find($id);
        $this->users->setActive($id, $active);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,string>
     */
    private function validate(array $data, ?int $excludeId): array
    {
        $errors = [];

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            $errors['name'] = 'Nama wajib diisi.';
        }

        $username = trim((string) ($data['username'] ?? ''));
        if ($username === '') {
            $errors['username'] = 'Username wajib diisi.';
        } elseif (!preg_match('/^[a-zA-Z0-9._-]{3,60}$/', $username)) {
            $errors['username'] = 'Username 3-60 karakter, hanya huruf/angka/titik/garis (._-).';
        } else {
            $existing = $this->users->findByUsername($username);
            if ($existing !== null && $existing->id !== $excludeId) {
                $errors['username'] = 'Username sudah dipakai.';
            }
        }

        $email = trim((string) ($data['email'] ?? ''));
        if ($email === '') {
            $errors['email'] = 'Email wajib diisi.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Format email tidak valid.';
        } else {
            $existing = $this->users->findByEmail($email);
            if ($existing !== null && $existing->id !== $excludeId) {
                $errors['email'] = 'Email sudah terdaftar.';
            }
        }

        $role = (string) ($data['role'] ?? '');
        if (Role::tryFrom($role) === null) {
            $errors['role'] = 'Role tidak valid.';
        }

        return $errors;
    }

    /**
     * @return array<string,string>
     */
    private function validatePassword(string $password, bool $required): array
    {
        if ($password === '' && $required) {
            return ['password' => 'Password wajib diisi.'];
        }

        if ($password !== '' && strlen($password) < 8) {
            return ['password' => 'Password minimal 8 karakter.'];
        }

        return [];
    }
}
