<?php
// ============================================================
// UIS Driver Scheduling and Management System
// profile.php  –  View / Update Profile (any signed-in user)
// Name, staff ID, e-mail, department and role come from the UIS staff system, so they are read-only here.
// Everyone can update their phone number; drivers can also update their address.
// Licence details are kept by the Administrative Assistant (admin/edit_driver.php).
// Layout: a settings-style page. A small menu on the left (Profile, Contact, Driving licence for drivers, Security,
// Display & language) and one section on the right at a time (?s=...). Security changes the password here.
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
$pwError = '';

$sections = ['profile' => ['user', 'Profile'], 'contact' => ['address-book', 'Contact']]
          + ($driver ? ['licence' => ['id-card', 'Driving licence']] : [])
          + ['security' => ['shield-halved', 'Security'], 'display' => ['palette', 'Display & language']];
$s = (string)($_GET['s'] ?? 'profile');
if (!isset($sections[$s])) { $s = 'profile'; }

// Security: change the password (same rules as change_password.php)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'password') {
    $s = 'security';
    $current = (string)($_POST['current_password'] ?? '');
    $new     = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');
    $q = $conn->prepare('SELECT password FROM users WHERE user_id = ?');
    $q->bind_param('i', $uid);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    $q->close();
    if (!$row || !verifyPassword($current, $row['password'])) { $pwError = 'Your current password is not correct.'; }
    elseif ($new !== $confirm)                            { $pwError = 'The new password and its confirmation do not match.'; }
    elseif ($new === $current)                            { $pwError = 'The new password must be different from the current one.'; }
    elseif (($problem = passwordProblem($new)) !== null)  { $pwError = $problem; }
    else {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $stmt = $conn->prepare('UPDATE users SET password = ? WHERE user_id = ?');
        $stmt->bind_param('si', $hash, $uid);
        $stmt->execute();
        $stmt->close();
        auditLog($conn, 'password_changed', null, 'Own password changed');
        setFlash('success', 'Your password has been changed.');
        header('Location: profile.php?s=security');
        exit();
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $s = 'contact';
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
        header('Location: profile.php?s=contact');
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
$sec = fn(string $k) => 'profile.php?s=' . $k;
$lockTag = '<span class="pf-lock"><i class="fas fa-lock" aria-hidden="true"></i>UIS staff system</span>';
?>
    <h1 class="mb-5">My Profile</h1>

    <div class="grid items-start gap-6 lg:grid-cols-[16rem_minmax(0,1fr)]">
        <!-- The menu -->
        <nav class="card min-w-0 bg-base-100 p-3" aria-label="Profile sections">
            <div class="mb-2 flex items-center gap-3 border-b border-base-300 px-2 pt-1 pb-3">
                <?php if ($driver && $driver['photo']): ?>
                    <img src="<?= htmlspecialchars(driverPhotoUrl($driver['photo'])) ?>" alt="" class="size-11 rounded-full object-cover ring-1 ring-base-300">
                <?php else: ?>
                    <span class="grid size-11 shrink-0 place-items-center rounded-full bg-[#fbf1d6] font-bold text-[#7a5800]" aria-hidden="true"><?= htmlspecialchars($initials) ?></span>
                <?php endif; ?>
                <div class="min-w-0"><div class="truncate text-sm font-semibold"><?= htmlspecialchars($me['full_name']) ?></div><div class="text-xs text-base-content/70"><?= htmlspecialchars(roleLabel($me['role'])) ?></div></div>
            </div>
            <ul class="pf-nav">
                <?php foreach ($sections as $k => [$icon, $label]): ?>
                    <li><a href="<?= $sec($k) ?>" class="<?= $s === $k ? 'is-active' : '' ?>" <?= $s === $k ? 'aria-current="page"' : '' ?>><i class="fas fa-<?= $icon ?>" aria-hidden="true"></i><?= htmlspecialchars($label) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </nav>

        <section class="card min-w-0 bg-base-100" aria-labelledby="pfTitle">
            <div class="card-body gap-0 p-6 sm:p-8">
            <?php if ($s === 'profile'): ?>
                <h2 id="pfTitle" class="text-xl font-bold">Profile</h2>
                <p class="mt-1 text-sm text-base-content/70">These come from the UIS staff system and cannot be changed here.</p>
                <dl class="pf-rows">
                    <dt>Full name</dt><dd><?= htmlspecialchars($me['full_name']) ?> <?= $lockTag ?></dd>
                    <dt>Staff ID</dt><dd class="tabular-nums"><?= htmlspecialchars($driver['employee_id'] ?? $me['username']) ?></dd>
                    <dt>E-mail</dt><dd class="break-all"><?= htmlspecialchars($me['email']) ?></dd>
                    <dt>Department</dt><dd><?= htmlspecialchars($me['department'] ?: '-') ?></dd>
                    <dt>Role</dt><dd><?= htmlspecialchars(roleLabel($me['role'])) ?></dd>
                    <dt>Last sign-in</dt><dd class="tabular-nums"><?= $me['last_login_at'] ? date('d M Y, H:i', strtotime($me['last_login_at'])) : '-' ?></dd>
                </dl>

            <?php elseif ($s === 'contact'): ?>
                <h2 id="pfTitle" class="text-xl font-bold">Contact</h2>
                <p class="mt-1 text-sm text-base-content/70">Used by the Vehicle Section to reach you about your trips.</p>
                <form method="post" action="profile.php?s=contact" novalidate class="mt-6">
                    <?php if ($errors): ?><div class="alert alert-soft alert-error mb-4 text-sm" role="alert">Please correct the highlighted field.</div><?php endif; ?>
                    <div class="pf-rows pf-form">
                        <label for="phone">Phone</label>
                        <div><input class="input w-full<?= isset($errors['phone']) ? ' input-error' : '' ?>" id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel"
                               value="<?= htmlspecialchars($v['phone']) ?>" placeholder="e.g. 012-345 6789" <?= isset($errors['phone']) ? 'aria-invalid="true" aria-describedby="phone_err"' : '' ?>>
                            <?php if (isset($errors['phone'])): ?><p class="mt-1 text-sm text-error" id="phone_err"><?= htmlspecialchars($errors['phone']) ?></p><?php endif; ?></div>
                        <?php if ($driver): ?>
                        <label for="address">Address</label>
                        <textarea class="textarea w-full" id="address" name="address" rows="3" autocomplete="street-address"><?= htmlspecialchars($v['address']) ?></textarea>
                        <?php endif; ?>
                        <span class="pf-label">E-mail</span>
                        <div class="pf-value break-all"><?= htmlspecialchars($me['email']) ?> <?= $lockTag ?></div>
                    </div>
                    <div class="mt-6 flex flex-wrap justify-end gap-2 border-t border-base-300 pt-5">
                        <a class="btn" href="<?= $sec('contact') ?>">Discard</a>
                        <button class="btn btn-primary" type="submit"><i class="fas fa-floppy-disk" aria-hidden="true"></i>Save changes</button>
                    </div>
                </form>

            <?php elseif ($s === 'licence' && $driver): ?>
                <h2 id="pfTitle" class="text-xl font-bold">Driving licence</h2>
                <p class="mt-1 text-sm text-base-content/70">Kept by the Administrative Assistant. Tell the Vehicle Section if anything here is wrong.</p>
                <div class="pf-licence mt-6">
                    <div class="text-xs font-semibold tracking-[0.14em] opacity-80">DRIVING LICENCE</div>
                    <div class="mt-1 text-2xl font-bold tracking-wide tabular-nums"><?= htmlspecialchars($driver['license_number'] ?: '-') ?></div>
                    <div class="mt-4 flex flex-wrap gap-x-10 gap-y-3 text-sm">
                        <div><div class="opacity-80">Classes</div><div class="mt-1 flex flex-wrap gap-1.5"><?php foreach ($classes as $c): ?><span class="pf-class"><?= htmlspecialchars($c) ?></span><?php endforeach; ?><?= $classes ? '' : '-' ?></div></div>
                        <div><div class="opacity-80">Expires</div><div class="font-semibold"><?= $driver['license_expiry'] ? date('d M Y', strtotime($driver['license_expiry'])) : '-' ?></div></div>
                        <div><div class="opacity-80">Experience</div><div class="font-semibold"><?= rtrim(rtrim(number_format((float)$driver['experience_years'], 1), '0'), '.') ?> years</div></div>
                    </div>
                </div>
                <?php if ($expiryNote): ?><p class="mt-4 text-sm"><span class="e-status text-<?= $expiryNote[0] ?>"><?= htmlspecialchars($expiryNote[1]) ?></span></p><?php endif; ?>

            <?php elseif ($s === 'security'): ?>
                <h2 id="pfTitle" class="text-xl font-bold">Security</h2>
                <p class="mt-1 text-sm text-base-content/70">Change your password. At least 8 characters, with a letter and a number.</p>
                <form method="post" action="profile.php?s=security" autocomplete="off" class="mt-6">
                    <input type="hidden" name="action" value="password">
                    <?php if ($pwError): ?><div class="alert alert-soft alert-error mb-4 text-sm" role="alert"><?= htmlspecialchars($pwError) ?></div><?php endif; ?>
                    <div class="pf-rows pf-form">
                        <label for="current_password">Current password</label><input type="password" class="input w-full" id="current_password" name="current_password" required autocomplete="current-password">
                        <label for="new_password">New password</label><input type="password" class="input w-full" id="new_password" name="new_password" required minlength="8" autocomplete="new-password">
                        <label for="confirm_password">Confirm new password</label><input type="password" class="input w-full" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password">
                    </div>
                    <div class="mt-6 flex justify-end border-t border-base-300 pt-5">
                        <button class="btn btn-primary" type="submit"><i class="fas fa-key" aria-hidden="true"></i>Change password</button>
                    </div>
                </form>
                <dl class="pf-rows mt-4 text-sm">
                    <dt>Last sign-in</dt><dd class="tabular-nums"><?= $me['last_login_at'] ? date('d M Y, H:i', strtotime($me['last_login_at'])) : '-' ?></dd>
                    <dt>Idle sign-out</dt><dd>After <?= max(1, getSettingInt($conn, 'SESSION_TIMEOUT_MIN')) ?> minutes without activity</dd>
                </dl>

            <?php else: ?>
                <h2 id="pfTitle" class="text-xl font-bold">Display &amp; language</h2>
                <p class="mt-1 text-sm text-base-content/70">Saved on this device.</p>
                <fieldset class="mt-6">
                    <legend class="mb-2 text-sm font-semibold">Theme</legend>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <button type="button" class="pf-choice" data-set-theme="light"><span class="pf-swatch bg-[#f4f6f5]"><span class="bg-white"></span></span><span><b>Light</b><small>White cards on a pale grey page</small></span></button>
                        <button type="button" class="pf-choice" data-set-theme="dark"><span class="pf-swatch bg-[#0b120f]"><span class="bg-[#121b17]"></span></span><span><b>Dark</b><small>Green-black, easier at night</small></span></button>
                    </div>
                </fieldset>
                <fieldset class="mt-6">
                    <legend class="mb-2 text-sm font-semibold">Language</legend>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <a class="pf-choice" href="<?= htmlspecialchars(uisLangUrl('ms')) ?>" lang="ms" <?= uisLang() === 'ms' ? 'aria-current="true"' : '' ?>><span class="pf-flag">BM</span><span><b>Bahasa Melayu</b><small>Semua halaman dalam BM</small></span></a>
                        <a class="pf-choice" href="<?= htmlspecialchars(uisLangUrl('en')) ?>" lang="en" <?= uisLang() === 'en' ? 'aria-current="true"' : '' ?>><span class="pf-flag">EN</span><span><b>English</b><small>Every page in English</small></span></a>
                    </div>
                </fieldset>
                <script>
                (function () {
                    var root = document.documentElement;
                    function mark() {
                        var dark = root.getAttribute('data-theme') === 'uis';
                        document.querySelectorAll('[data-set-theme]').forEach(function (b) { b.setAttribute('aria-pressed', String((b.getAttribute('data-set-theme') === 'dark') === dark)); });
                    }
                    document.addEventListener('click', function (e) {
                        var b = e.target.closest('[data-set-theme]'); if (!b) { return; }
                        var dark = b.getAttribute('data-set-theme') === 'dark';
                        root.setAttribute('data-theme', dark ? 'uis' : 'uis-light');
                        try { localStorage.setItem('uis-theme', dark ? 'dark' : 'light'); } catch (e2) {}
                        document.dispatchEvent(new Event('uis-theme')); mark();
                    });
                    document.addEventListener('uis-theme', mark); mark();
                })();
                </script>
            <?php endif; ?>
            </div>
        </section>
    </div>
<?php
adminPageEnd();
