<?php
/**
 * Step 1 home page = system check.
 * @var bool $phpOk @var bool $pdoOk @var bool $dbOk @var ?string $dbError
 * @var array $counts @var array $byStatus @var array $settings
 */
$badge = fn(bool $ok) => $ok ? '<span class="badge text-bg-dark">OK</span>' : '<span class="badge text-bg-secondary">FAILED</span>';
$missing = array_filter($counts, fn($c) => $c === -1);
?>
<div class="mb-4">
    <h1 class="h3 mb-1"><?= e($config['app_title']) ?></h1>
    <p class="text-muted mb-0"><?= e($config['org_name']) ?></p>
</div>

<div class="alert alert-secondary">
    <strong>Step 1 &ndash; project skeleton.</strong>
    This page checks that the router, layout, database and demo data are working.
    Login and the real dashboards come in Step 2.
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between"><span>PHP 8 or newer</span><?= $badge($phpOk) ?></div>
            <div class="small text-muted mt-1">Running PHP <?= e(PHP_VERSION) ?></div>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between"><span>PDO MySQL extension</span><?= $badge($pdoOk) ?></div>
            <div class="small text-muted mt-1">Needed for every database query</div>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between"><span>Database connection</span><?= $badge($dbOk) ?></div>
            <div class="small text-muted mt-1">
                <?php if ($dbOk): ?>Connected to <code>dsms</code><?php else: ?>
                    Import <code>database/dsms.sql</code> then <code>database/seed.sql</code>.
                    <?php if ($dbError): ?><br><code><?= e($dbError) ?></code><?php endif; ?>
                <?php endif; ?>
            </div>
        </div></div>
    </div>
</div>

<?php if ($dbOk): ?>
<div class="row g-3 mb-4">
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold">Database tables (<?= count($counts) ?>) and rows loaded</div>
            <div class="card-body p-0">
                <?php if ($missing): ?>
                    <div class="alert alert-warning m-3">Missing tables: <?= e(implode(', ', array_keys($missing))) ?>. Re-import <code>dsms.sql</code>.</div>
                <?php endif; ?>
                <div class="table-responsive">
                    <table class="table table-sm table-striped mb-0">
                        <thead><tr><th>Table</th><th class="text-end">Rows</th></tr></thead>
                        <tbody>
                        <?php foreach ($counts as $table => $n): ?>
                            <tr><td><code><?= e($table) ?></code></td><td class="text-end"><?= $n === -1 ? 'missing' : (int)$n ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header bg-white fw-semibold">Demo requests by status</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($byStatus as $row): ?>
                    <li class="list-group-item d-flex justify-content-between"><?= e($row['status']) ?><span class="badge text-bg-secondary"><?= (int)$row['total'] ?></span></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="card">
            <div class="card-header bg-white fw-semibold">Settings (editable later)</div>
            <ul class="list-group list-group-flush small">
                <?php foreach ($settings as $key => $value): ?>
                    <li class="list-group-item d-flex justify-content-between"><code><?= e($key) ?></code><span><?= e($value) ?></span></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header bg-white fw-semibold">Test accounts (login works from Step 2)</div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead><tr><th>Username</th><th>Role</th><th>Notes</th></tr></thead>
            <tbody>
                <tr><td><code>staff1</code>, <code>staff2</code></td><td>staff</td><td>Approver is <code>hod1</code></td></tr>
                <tr><td><code>hod1</code></td><td>hod</td><td>Approver is <code>hod2</code></td></tr>
                <tr><td><code>hod2</code></td><td>hod</td><td>Top of the approval chain</td></tr>
                <tr><td><code>admin1</code></td><td>admin_assistant</td><td>All admin functions</td></tr>
                <tr><td><code>headadmin1</code></td><td>head_admin</td><td>Admin functions + approves driver assignments</td></tr>
                <tr><td><code>driver1</code> ... <code>driver16</code></td><td>driver</td><td>1-12 carpool, 13-16 management</td></tr>
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white small text-muted">Password for every account: <code>Password123</code></div>
</div>
<?php endif; ?>
