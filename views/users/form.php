<?php
/** @var string $mode */
/** @var \App\Entity\User|null $target */
/** @var \App\Entity\Role[] $roles */
/** @var array<string,string> $errors */
/** @var array<string,mixed> $old */

use App\Core\Csrf;
use App\Core\View;

$errors = $errors ?? [];
$old = $old ?? [];
$isEdit = $mode === 'edit';
$action = $isEdit ? "/users/{$target->id}" : '/users';
$name = $old['name'] ?? ($target->name ?? '');
$username = $old['username'] ?? ($target->username ?? '');
$email = $old['email'] ?? ($target->email ?? '');
$role = $old['role'] ?? ($target->role->value ?? '');

echo View::renderFile('components.breadcrumb', [
    'items' => [
        ['label' => 'User', 'url' => '/users'],
        ['label' => $isEdit ? 'Edit User' : 'Tambah User'],
    ],
]);
?>
<div class="topbar">
    <h1><?= $isEdit ? 'Edit User' : 'Tambah User' ?></h1>
</div>

<div class="card">
    <form method="post" action="<?= View::e($action) ?>" data-validate novalidate>
        <?= Csrf::field() ?>
        <div class="form-grid">
            <div class="form-field">
                <label for="name">Nama</label>
                <input type="text" id="name" name="name" required value="<?= View::e($name) ?>">
                <?php if (isset($errors['name'])): ?><span class="field-error"><?= View::e($errors['name']) ?></span><?php endif; ?>
            </div>
            <div class="form-field">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required value="<?= View::e($username) ?>" pattern="[a-zA-Z0-9._-]{3,60}" autocapitalize="off">
                <?php if (isset($errors['username'])): ?><span class="field-error"><?= View::e($errors['username']) ?></span><?php endif; ?>
            </div>
            <div class="form-field">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" required value="<?= View::e($email) ?>">
                <?php if (isset($errors['email'])): ?><span class="field-error"><?= View::e($errors['email']) ?></span><?php endif; ?>
            </div>
            <div class="form-field">
                <label for="role">Role</label>
                <?= View::renderFile('components.enum-dropdown', [
                    'name' => 'role',
                    'placeholder' => 'Pilih role',
                    'selected' => $role,
                    'required' => true,
                    'options' => array_map(
                        static fn (\App\Entity\Role $r) => ['value' => $r->value, 'label' => $r->label()],
                        $roles
                    ),
                ]) ?>
                <?php if (isset($errors['role'])): ?><span class="field-error"><?= View::e($errors['role']) ?></span><?php endif; ?>
            </div>
            <div class="form-field">
                <label for="password">Password<?= $isEdit ? ' (kosongkan jika tidak diubah)' : '' ?></label>
                <input type="password" id="password" name="password" <?= $isEdit ? '' : 'required' ?>>
                <?php if (isset($errors['password'])): ?><span class="field-error"><?= View::e($errors['password']) ?></span><?php endif; ?>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn--primary">Simpan</button>
            <a href="/users" class="btn">Batal</a>
        </div>
    </form>
</div>
