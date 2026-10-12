<?php /** @var ?string $error @var bool $forced */ ?>
<div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
        <div class="card">
            <div class="card-body p-4">
                <h1 class="h5 mb-3">Change password</h1>
                <?php if ($forced): ?>
                    <div class="alert alert-secondary py-2">You are using a temporary password. Please choose your own password to continue.</div>
                <?php endif; ?>
                <?php if ($error): ?><div class="alert alert-dark py-2"><?= e($error) ?></div><?php endif; ?>
                <form method="post" action="<?= e(url('auth/change_password')) ?>" autocomplete="off">
                    <?= Csrf::field() ?>
                    <div class="mb-3"><label class="form-label" for="current_password">Current password</label>
                        <input class="form-control" type="password" id="current_password" name="current_password" required></div>
                    <div class="mb-3"><label class="form-label" for="new_password">New password</label>
                        <input class="form-control" type="password" id="new_password" name="new_password" minlength="8" required>
                        <div class="form-text">At least 8 characters, with letters and numbers.</div></div>
                    <div class="mb-3"><label class="form-label" for="confirm_password">Confirm new password</label>
                        <input class="form-control" type="password" id="confirm_password" name="confirm_password" required></div>
                    <button class="btn btn-dark" type="submit">Save password</button>
                </form>
            </div>
        </div>
    </div>
</div>
