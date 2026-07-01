<?php
// ============================================================
// UIS Driver Scheduling and Management System
// driver/messages.php  –  Communication Hub (Driver)
// ============================================================

$page_title   = 'Messages';
$current_page = 'messages.php';

require_once '../config/database.php';
requireDriver();

$me = (int)$_SESSION['user_id'];

// ── Handle POST: send reply ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $to   = (int)($_POST['receiver_id'] ?? 0);
    $body = trim($_POST['body'] ?? '');
    if ($to > 0 && $body !== '') {
        $s = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, body, created_at) VALUES (?,?,?,NOW())");
        $s->bind_param('iis', $me, $to, $body);
        $s->execute();
    }
    header("Location: " . SITE_URL . "/driver/messages.php?to=$to");
    exit();
}

// ── Selected admin to chat with ──────────────────────────────
$selected = (int)($_GET['to'] ?? 0);

// ── Get all admin users ───────────────────────────────────────
$admins_result = $conn->query("SELECT user_id, full_name FROM users WHERE role='admin' ORDER BY full_name ASC");
$admin_list    = $admins_result ? $admins_result->fetch_all(MYSQLI_ASSOC) : [];

// Default to first admin if none selected
if ($selected <= 0 && !empty($admin_list)) {
    $selected = (int)$admin_list[0]['user_id'];
}

// ── Mark messages from selected admin as read ─────────────────
if ($selected > 0) {
    $conn->query("UPDATE messages SET is_read=1 WHERE sender_id=$selected AND receiver_id=$me AND is_read=0");
}

// ── Load selected admin info ──────────────────────────────────
$selected_admin = null;
if ($selected > 0) {
    $s = $conn->prepare("SELECT user_id, full_name FROM users WHERE user_id=? AND role='admin'");
    $s->bind_param('i', $selected);
    $s->execute();
    $selected_admin = $s->get_result()->fetch_assoc();
}

// ── Load conversation ─────────────────────────────────────────
$conversation = [];
if ($selected > 0) {
    $s = $conn->prepare(
        "SELECT m.*, u.full_name AS sender_name, u.role AS sender_role
         FROM messages m
         JOIN users u ON m.sender_id = u.user_id
         WHERE (m.sender_id=? AND m.receiver_id=?) OR (m.sender_id=? AND m.receiver_id=?)
         ORDER BY m.created_at ASC"
    );
    $s->bind_param('iiii', $me, $selected, $selected, $me);
    $s->execute();
    $conversation = $s->get_result()->fetch_all(MYSQLI_ASSOC);
}

// ── Unread count (for sidebar badge) ─────────────────────────
$unread_total = (int)$conn->query(
    "SELECT COUNT(*) AS cnt FROM messages WHERE receiver_id=$me AND is_read=0"
)->fetch_assoc()['cnt'];

// ── Unread per admin ──────────────────────────────────────────
$unread_per = [];
foreach ($admin_list as $adm) {
    $aid = (int)$adm['user_id'];
    $unread_per[$aid] = (int)$conn->query(
        "SELECT COUNT(*) AS cnt FROM messages WHERE sender_id=$aid AND receiver_id=$me AND is_read=0"
    )->fetch_assoc()['cnt'];
}

require_once '../includes/header.php';
require_once '../includes/sidebar.php';
?>

<main class="main-content">

    <!-- ── Desktop Top Navbar ─────────────────────────────────── -->
    <div class="d-none d-lg-flex align-items-center justify-content-between mb-4 pb-3"
         style="border-bottom:2px solid #e5e9f0;">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1" style="font-size:0.78rem;">
                    <li class="breadcrumb-item">
                        <a href="<?php echo SITE_URL; ?>/driver/dashboard.php"
                           class="text-decoration-none" style="color:var(--uis-primary);">
                            <i class="fas fa-home me-1"></i>Home
                        </a>
                    </li>
                    <li class="breadcrumb-item active">Messages</li>
                </ol>
            </nav>
            <h1 class="page-title mb-0" style="font-size:1.6rem;">
                <i class="fas fa-comments me-2" style="color:var(--uis-primary);"></i>Messages
                <?php if ($unread_total > 0): ?>
                <span class="badge ms-2" style="background:#dc2626;font-size:0.7rem;vertical-align:middle;">
                    <?php echo $unread_total; ?> unread
                </span>
                <?php endif; ?>
            </h1>
            <p class="page-subtitle mb-0">Communicate with the administration team.</p>
        </div>
    </div>

    <?php showFlash(); ?>

    <div class="content-card" style="overflow:hidden;">
        <div class="row g-0" style="min-height:580px;">

            <!-- ── Admin List (Left Panel) ───────────────────── -->
            <div class="col-12 col-lg-4" style="border-right:1px solid #e8edf5;">

                <div class="px-3 py-3" style="border-bottom:1px solid #e8edf5;background:#f8fafc;">
                    <div style="font-size:0.78rem;font-weight:700;color:var(--uis-primary);text-transform:uppercase;letter-spacing:0.06em;">
                        <i class="fas fa-headset me-1"></i> Administration
                    </div>
                </div>

                <?php foreach ($admin_list as $adm): ?>
                <?php
                    $aid = (int)$adm['user_id'];
                    $is_active = ($aid === $selected);
                    $ucount = $unread_per[$aid] ?? 0;
                    $parts = array_filter(explode(' ', trim($adm['full_name'])));
                    $ini = '';
                    foreach (array_slice($parts, 0, 2) as $p) { $ini .= strtoupper($p[0]); }
                ?>
                <a href="<?php echo SITE_URL; ?>/driver/messages.php?to=<?php echo $aid; ?>"
                   class="d-flex align-items-center gap-3 px-3 py-3 text-decoration-none"
                   style="border-bottom:1px solid #f0f4f8;background:<?php echo $is_active ? 'linear-gradient(135deg,#eff6ff,#e0f2fe)' : '#fff'; ?>;">
                    <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 position-relative"
                         style="width:42px;height:42px;background:linear-gradient(135deg,#1e3a8a,#1d4ed8);color:#fff;font-size:0.8rem;font-weight:700;">
                        <?php echo htmlspecialchars($ini ?: 'A'); ?>
                        <?php if ($ucount > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill"
                              style="background:#dc2626;font-size:0.6rem;min-width:16px;height:16px;display:flex;align-items:center;justify-content:center;">
                            <?php echo $ucount; ?>
                        </span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div style="font-size:0.86rem;font-weight:<?php echo $ucount > 0 ? '700' : '600'; ?>;color:#1a2035;">
                            <?php echo htmlspecialchars($adm['full_name']); ?>
                        </div>
                        <div style="font-size:0.73rem;color:#6b7280;">
                            <i class="fas fa-shield-halved fa-xs me-1"></i>Administrator
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>

                <?php if (empty($admin_list)): ?>
                <div class="text-center py-5" style="color:#9ca3af;">
                    <i class="fas fa-user-slash fa-lg mb-2 d-block"></i>
                    <div style="font-size:0.84rem;">No admin accounts found</div>
                </div>
                <?php endif; ?>
            </div>

            <!-- ── Conversation (Right Panel) ────────────────── -->
            <div class="col-12 col-lg-8 d-flex flex-column">

                <?php if ($selected_admin): ?>
                <!-- Header -->
                <div class="px-4 py-3 d-flex align-items-center gap-3"
                     style="border-bottom:1px solid #e8edf5;background:#f8fafc;">
                    <?php
                        $parts = array_filter(explode(' ', trim($selected_admin['full_name'])));
                        $ini = '';
                        foreach (array_slice($parts, 0, 2) as $p) { $ini .= strtoupper($p[0]); }
                    ?>
                    <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                         style="width:40px;height:40px;background:linear-gradient(135deg,#1e3a8a,#1d4ed8);color:#fff;font-size:0.78rem;font-weight:700;">
                        <?php echo htmlspecialchars($ini ?: 'A'); ?>
                    </div>
                    <div>
                        <div style="font-size:0.9rem;font-weight:700;color:#1a2035;">
                            <?php echo htmlspecialchars($selected_admin['full_name']); ?>
                        </div>
                        <div style="font-size:0.75rem;color:#6b7280;">
                            <i class="fas fa-shield-halved fa-xs me-1"></i>Administrator · UIS Driver Management
                        </div>
                    </div>
                </div>

                <!-- Messages Thread -->
                <div id="chatBox" class="flex-grow-1 px-4 py-3"
                     style="overflow-y:auto;max-height:400px;background:#f8fafc;display:flex;flex-direction:column;gap:12px;">
                    <?php if (empty($conversation)): ?>
                    <div class="text-center my-auto py-4" style="color:#9ca3af;">
                        <i class="fas fa-comment-dots fa-2x mb-2 d-block"></i>
                        <div style="font-size:0.84rem;">No messages yet. Say hello!</div>
                    </div>
                    <?php else: ?>
                    <?php foreach ($conversation as $msg): ?>
                    <?php $is_mine = ((int)$msg['sender_id'] === $me); ?>
                    <div class="d-flex <?php echo $is_mine ? 'justify-content-end' : 'justify-content-start'; ?>">
                        <?php if (!$is_mine): ?>
                        <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 me-2"
                             style="width:30px;height:30px;background:linear-gradient(135deg,#1e3a8a,#1d4ed8);color:#fff;font-size:0.65rem;font-weight:700;align-self:flex-end;">
                            <?php echo htmlspecialchars($ini ?: 'A'); ?>
                        </div>
                        <?php endif; ?>
                        <div style="max-width:70%;">
                            <div style="background:<?php echo $is_mine ? 'linear-gradient(135deg,#059669,#047857)' : '#fff'; ?>;
                                        color:<?php echo $is_mine ? '#fff' : '#1a2035'; ?>;
                                        padding:10px 14px;
                                        border-radius:<?php echo $is_mine ? '18px 18px 4px 18px' : '18px 18px 18px 4px'; ?>;
                                        font-size:0.84rem;line-height:1.5;
                                        box-shadow:0 1px 3px rgba(0,0,0,0.08);
                                        border:<?php echo $is_mine ? 'none' : '1px solid #e8edf5'; ?>;">
                                <?php echo nl2br(htmlspecialchars($msg['body'])); ?>
                            </div>
                            <div style="font-size:0.7rem;color:#9ca3af;margin-top:3px;text-align:<?php echo $is_mine ? 'right' : 'left'; ?>;">
                                <?php echo date('d M Y, h:i A', strtotime($msg['created_at'])); ?>
                                <?php if ($is_mine): ?>
                                <i class="fas fa-check<?php echo $msg['is_read'] ? '-double' : ''; ?> ms-1"
                                   style="color:<?php echo $msg['is_read'] ? '#059669' : '#9ca3af'; ?>;"></i>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Reply Form -->
                <div class="px-4 py-3" style="border-top:1px solid #e8edf5;background:#fff;">
                    <form method="POST">
                        <input type="hidden" name="receiver_id" value="<?php echo $selected; ?>">
                        <div class="d-flex gap-2 align-items-end">
                            <textarea name="body" rows="2" required
                                      placeholder="Type a message…"
                                      class="form-control"
                                      style="border-radius:var(--radius-md);border:1.5px solid #e5e9f0;font-size:0.84rem;resize:none;"
                                      onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();this.form.submit();}"></textarea>
                            <button type="submit" class="btn flex-shrink-0"
                                    style="background:linear-gradient(135deg,#059669,#047857);color:#fff;border:none;padding:10px 18px;border-radius:var(--radius-md);">
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </div>
                        <div style="font-size:0.72rem;color:#9ca3af;margin-top:4px;">
                            Press <kbd style="font-size:0.68rem;">Enter</kbd> to send · <kbd style="font-size:0.68rem;">Shift+Enter</kbd> for new line
                        </div>
                    </form>
                </div>

                <?php else: ?>
                <div class="flex-grow-1 d-flex align-items-center justify-content-center" style="background:#f8fafc;">
                    <div class="text-center py-5" style="color:#9ca3af;">
                        <i class="fas fa-comments fa-2x mb-2 d-block"></i>
                        <div style="font-size:0.84rem;">No administrators found</div>
                    </div>
                </div>
                <?php endif; ?>

            </div><!-- /.col-lg-8 -->
        </div><!-- /.row -->
    </div><!-- /.content-card -->

</main>

<?php
$extra_js = '<script>
(function(){
    var box = document.getElementById("chatBox");
    if (box) box.scrollTop = box.scrollHeight;
})();
</script>';
require_once '../includes/footer.php';
?>
