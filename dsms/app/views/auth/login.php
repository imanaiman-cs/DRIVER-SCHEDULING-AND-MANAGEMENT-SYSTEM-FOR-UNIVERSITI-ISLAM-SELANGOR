<?php /** @var ?string $error @var string $login */ ?>
<div class="card">
    <div class="card-body p-4">
        <h1 class="h5 mb-3">Login</h1>
        <?php if ($error): ?><div class="alert alert-dark py-2" role="alert"><?= e($error) ?></div><?php endif; ?>
        <form method="post" action="<?= e(url('auth/login')) ?>" autocomplete="off">
            <?= Csrf::field() ?>
            <div class="mb-3">
                <label class="form-label" for="login">Staff ID</label>
                <input class="form-control" id="login" name="login" value="<?= e($login) ?>" required autofocus>
            </div>
            <div class="mb-3">
                <label class="form-label" for="password">Password</label>
                <input class="form-control" type="password" id="password" name="password" required>
            </div>
            <button class="btn btn-dark w-100" type="submit">Login</button>
        </form>
        <p class="small text-muted mt-3 mb-0">Accounts are created by the Vehicle Section admin. If you cannot log in, please contact them.</p>
    </div>
</div>
