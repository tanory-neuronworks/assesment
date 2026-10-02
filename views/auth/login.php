<?php
/** @var array<string,mixed> $old */

use App\Core\Csrf;
use App\Core\Icon;
use App\Core\View;

$old = $old ?? [];
?>
<div class="login-shell">
    <div class="login-card">
        <div class="login-card__brand">
            <span class="login-card__brand-mark"><?= Icon::svg('package', 19) ?></span>
            <strong>Inventory &amp; Order</strong>
        </div>
        <h1>Masuk ke akun Anda</h1>
        <p class="login-card__subtitle">Silakan login untuk melanjutkan.</p>
        <form method="post" action="/login" data-validate novalidate>
            <?= Csrf::field() ?>
            <div class="form-field">
                <label for="login">Email/Username</label>
                <input type="text" id="login" name="login" required autofocus autocapitalize="off" autocomplete="username" value="<?= View::e($old['login'] ?? '') ?>">
            </div>
            <div class="form-field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn--primary" style="width:100%; justify-content:center;">Login</button>
            </div>
        </form>
    </div>
</div>
