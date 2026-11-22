<?php
// ============================================================
// UIS Driver Scheduling and Management System
// profile.php  –  View / Update Profile (any signed-in user)
// Name, staff ID, e-mail, department and role come from the UIS staff system, so they are read-only here.
// Everyone can update their phone number; drivers can also update their address.
// Licence details are kept by the Administrative Assistant (admin/edit_driver.php).
// ============================================================
$current_page = 'profile.php';
require_once 'config/database.php';
require_once 'includes/admin_page.php';
requireLogin();

$uid = (int)$_SESSION['user_id'];

$stmt = $conn->prepare('SELECT user_id, username, email, full_name, role, driver_id, department, phone, last_login_at, created_at FROM users WHERE user_id = ?');
$stmt->bind_param('i', $uid);
$stmt->execute();
$me = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$me) { header('Location: logout.php'); exit(); }

$driver = null;
if ($me['role'] === 'driver' && $me['driver_id']) {
    $did = (int)$me['driver_id'];
    $stmt = $conn->prepare('SELECT driver_id, employee_id, phone, address, experience_years, license_number, license_class, license_expiry, photo FROM drivers WHERE driver_id = ?');
    $stmt->bind_param('i', $did);
    $stmt->execute();
    $driver = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}

$v = [
    'phone'   => (string)($driver['phone'] ?? $me['phone'] ?? ''),
    'address' => (string)($driver['address'] ?? ''),
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $v['phone']   = mb_substr(trim((string)($_POST['phone'] ?? '')), 0, 20);
    $v['address'] = mb_substr(trim((string)($_POST['address'] ?? '')), 0, 500);
    if ($v['phone'] !== '' && !preg_match('/^[0-9+\-\s()]{6,20}$/', $v['phone'])) {
        $errors['phone'] = 'Enter a valid phone number.';
    }
    if (!$errors) {
        $phone = $v['phone'] === '' ? null : $v['phone'];
        $stmt = $conn->prepare('UPDATE users SET phone = ? WHERE user_id = ?');
        $stmt->bind_param('si', $phone, $uid);
        $stmt->execute();
        $stmt->close();
        if ($driver) {
            $addr = $v['address'] === '' ? null : $v['address'];
            $did  = (int)$driver['driver_id'];
            $stmt = $conn->prepare('UPDATE drivers SET phone = ?, address = ? WHERE driver_id = ?');
            $stmt->bind_param('ssi', $phone, $addr, $did);
            $stmt->execute();
            $stmt->close();
        }
        auditLog($conn, 'profile_update', null, 'Own profile updated');
        setFlash('success', 'Your profile has been updated.');
        header('Location: profile.php');
        exit();
    }
}

// Initials for the avatar
$initials = '';
foreach (array_slice(array_filter(explode(' ', trim($me['full_name']))), 0, 2) as $part) { $initials .= strtoupper($part[0]); }
$initials = $initials ?: 'U';

// Licence expiry state (drivers)
$expiryNote = null;
if ($driver && $driver['license_expiry']) {
    $days = (int)floor((strtotime($driver['license_expiry']) - strtotime(date('Y-m-d'))) / 86400);
    if ($days < 0)        { $expiryNote = ['error', 'Expired']; }
    elseif ($days <= 60)  { $expiryNote = ['warning', 'Expires in ' . $days . ' days']; }
    else                  { $expiryNote = ['success', 'Valid']; }
}
$classes = $driver ? array_filter(array_map('trim', explode(',', (string)$driver['license_class']))) : [];

adminPageStart('My Profile');
?>
    <h1 class="mb-5">My Profile</h1>

    <!-- Identity -->
    <section class="card mb-6 bg-base-100" aria-label="Account summary">
        <div class="card-body flex-row flex-wrap items-center gap-5">
            <?php if ($driver && $driver['photo']): ?>
                <img src="<?= htmlspecialchars(driverPhotoUrl($driver['photo'])) ?>" alt="" class="size-20 rounded-full object-cover ring-1 ring-base-300">
            <?php else: ?>
                <span class="grid size-20 shrink-0 place-items-center rounded-full bg-[#fbf1d6] text-2xl font-bold text-[#7a5800]" aria-hidden="true"><?= htmlspecialchars($initials) ?></span>
            <?php endif; ?>
            <div class="min-w-0 flex-1">
                <div class="text-xl font-bold"><?= htmlspecialchars($me['full_name']) ?></div>
                <div class="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-base-content/70">
                    <span><i class="fas fa-id-badge me-1.5" aria-hidden="true"></i><?= htmlspecialchars(roleLabel($me['role'])) ?></span>
                    <?php if ($me['department']): ?><span><i class="fas fa-building me-1.5" aria-hidden="true"></i><?= htmlspecialchars($me['department']) ?></span><?php endif; ?>
                    <span><i class="fas fa-envelope me-1.5" aria-hidden="true"></i><?= htmlspecialchars($me['email']) ?></span>
                </div>
            </div>
            <a class="btn" href="<?= SITE_URL ?>/change_password.php"><i class="fas fa-key" aria-hidden="true"></i>Change password</a>
        </div>
    </section>

    <div class="grid gap-6 lg:grid-cols-2">
        <!-- Account details (read-only) -->
        <section class="card bg-base-100">
            <div class="card-body">
                <h2 class="card-title mb-1">Account details</h2>
                <p class="mb-3 grow-0 text-xs text-base-content/65">These come from the UIS staff system and cannot be changed here.</p>
                <dl class="grid grid-cols-12 content-start gap-y-3 text-sm">
                    <dt class="col-span-5 text-base-content/70">Full name</dt><dd class="col-span-7 font-medium"><?= htmlspecialchars($me['full_name']) ?></dd>
                    <dt class="col-span-5 text-base-content/70">Staff ID</dt><dd class="col-span-7 font-medium tabular-nums"><?= htmlspecialchars($driver['employee_id'] ?? $me['username']) ?></dd>
                    <dt class="col-span-5 text-base-content/70">E-mail</dt><dd class="col-span-7 font-medium break-all"><?= htmlspecialchars($me['email']) ?></dd>
                    <dt class="col-span-5 text-base-content/70">Department</dt><dd class="col-span-7 font-medium"><?= htmlspecialchars($me['department'] ?: '-') ?></dd>
                    <dt class="col-span-5 text-base-content/70">Role</dt><dd class="col-span-7 font-medium"><?= htmlspecialchars(roleLabel($me['role'])) ?></dd>
                    <dt class="col-span-5 text-base-content/70">Last sign-in</dt><dd class="col-span-7 font-medium tabular-nums"><?= $me['last_login_at'] ? date('d M Y, H:i', strtotime($me['last_login_at'])) : '-' ?></dd>
                </dl>
            </div>
        </section>

        <!-- Contact (editable) -->
        <form method="post" class="card bg-base-100" novalidate>
            <div class="card-body">
                <h2 class="card-title mb-1">Contact details</h2>
                <p class="mb-3 grow-0 text-xs text-base-content/65">Used by the Vehicle Section to reach you about your trips.</p>
                <?php if ($errors): ?><div class="alert alert-soft alert-error mb-3 text-sm" role="alert">Please correct the highlighted field.</div><?php endif; ?>
                <div class="mb-4">
                    <label class="mb-1 block text-sm font-medium" for="phone">Phone</label>
                    <input class="input w-full<?= isset($errors['phone']) ? ' input-error' : '' ?>" id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel"
                           value="<?= htmlspecialchars($v['phone']) ?>" placeholder="e.g. 012-345 6789"
                           <?= isset($errors['phone']) ? 'aria-invalid="true" aria-describedby="phone_err"' : '' ?>>
                    <?php if (isset($errors['phone'])): ?><p class="mt-1 text-sm text-error" id="phone_err"><?= htmlspecialchars($errors['phone']) ?></p><?php endif; ?>
                </div>
                <?php if ($driver): ?>
                <div class="mb-4">
                    <label class="mb-1 block text-sm font-medium" for="address">Address</label>
                    <textarea class="textarea w-full" id="address" name="address" rows="3" autocomplete="street-address"><?= htmlspecialchars($v['address']) ?></textarea>
                </div>
                <?php endif; ?>
                <div class="card-actions justify-end">
                    <button class="btn btn-primary" type="submit"><i class="fas fa-floppy-disk" aria-hidden="true"></i>Save changes</button>
                </div>
            </div>
        </form>

        <?php if ($driver): ?>
        <!-- Licence (drivers, read-only) -->
        <section class="card bg-base-100 lg:col-span-2">
            <div class="card-body">
                <h2 class="card-title mb-1">Driving licence</h2>
                <p class="mb-3 grow-0 text-xs text-base-content/65">Kept by the Administrative Assistant. Tell the Vehicle Section if anything here is wrong.</p>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="e-tile"><span class="e-tile-label">Licence classes</span>
                        <span class="flex flex-wrap gap-1.5 pt-1"><?php foreach ($classes as $c): ?><span class="badge badge-outline"><?= htmlspecialchars($c) ?></span><?php endforeach; ?><?= $classes ? '' : '-' ?></span></div>
                    <div class="e-tile"><span class="e-tile-label">Licence number</span><span class="e-tile-value text-base"><?= htmlspecialchars($driver['license_number'] ?: '-') ?></span></div>
                    <div class="e-tile"><span class="e-tile-label">Expiry date</span>
                        <span class="e-tile-value text-base"><?= $driver['license_expiry'] ? date('d M Y', strtotime($driver['license_expiry'])) : '-' ?></span>
                        <?php if ($expiryNote): ?><span class="e-status text-<?= $expiryNote[0] ?>"><?= htmlspecialchars($expiryNote[1]) ?></span><?php endif; ?></div>
                    <div class="e-tile"><span class="e-tile-label">Experience</span><span class="e-tile-value text-base"><?= rtrim(rtrim(number_format((float)$driver['experience_years'], 1), '0'), '.') ?> years</span></div>
                </div>
            </div>
        </section>
        <?php endif; ?>
    </div>
<?php
adminPageEnd();
