<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/messages.php  –  Communication Hub (Admin)
// ============================================================

$page_title   = 'Messages';
$current_page = 'messages.php';

require_once '../config/database.php';
requireAdmin();

$me = (int)$_SESSION['user_id'];

// ── Handle POST: send message ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $to   = (int)($_POST['receiver_id'] ?? 0);
    $body = trim($_POST['body'] ?? '');
    if ($to > 0 && $body !== '') {
        $s = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, body, created_at) VALUES (?,?,?,NOW())");
        $s->bind_param('iis', $me, $to, $body);
        $s->execute();
    }
    header("Location: " . SITE_URL . "/admin/messages.php?to=$to");
    exit();
}

// ── Selected conversation ────────────────────────────────────
$selected = (int)($_GET['to'] ?? 0);

// ── Mark messages from selected driver as read ───────────────
if ($selected > 0) {
    $conn->query("UPDATE messages SET is_read=1 WHERE sender_id=$selected AND receiver_id=$me AND is_read=0");
}

// ── Load conversation ────────────────────────────────────────
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

// ── Load selected driver user info ───────────────────────────
$selected_user = null;
if ($selected > 0) {
    $s = $conn->prepare("SELECT u.*, d.employee_id, d.phone FROM users u LEFT JOIN drivers d ON u.driver_id=d.driver_id WHERE u.user_id=?");
    $s->bind_param('i', $selected);
    $s->execute();
    $selected_user = $s->get_result()->fetch_assoc();
}

// ── Load driver list with unread counts ─────────────────────
$drivers_sql = "
    SELECT u.user_id, u.full_name,
           COALESCE(d.employee_id,'') AS employee_id,
           (SELECT COUNT(*) FROM messages m
            WHERE m.sender_id=u.user_id AND m.receiver_id=? AND m.is_read=0) AS unread_count,
           (SELECT m.body FROM messages m
            WHERE (m.sender_id=u.user_id AND m.receiver_id=?)
               OR (m.sender_id=? AND m.receiver_id=u.user_id)
            ORDER BY m.created_at DESC LIMIT 1) AS last_msg,
           (SELECT m.created_at FROM messages m
            WHERE (m.sender_id=u.user_id AND m.receiver_id=?)
               OR (m.sender_id=? AND m.receiver_id=u.user_id)
            ORDER BY m.created_at DESC LIMIT 1) AS last_time
    FROM users u
    LEFT JOIN drivers d ON u.driver_id=d.driver_id
    WHERE u.role='driver'
    ORDER BY unread_count DESC, last_time DESC, u.full_name ASC
";
$s = $conn->prepare($drivers_sql);
$s->bind_param('iiiii', $me, $me, $me, $me, $me);
$s->execute();
$driver_list = $s->get_result()->fetch_all(MYSQLI_ASSOC);

$total_unread = array_sum(array_column($driver_list, 'unread_count'));

require_once '../includes/header.php';
require_once '../includes/sidebar.php';
?>

<main class="main-content">

    <!-- Desktop Top Navbar moved to sidebar.php -->

    <?php showFlash(); ?>

    <!-- ================================================================
         MESSAGING LAYOUT
         ================================================================ -->
    <div class="content-card" style="overflow:hidden;">
        <div class="row g-0" style="min-height:600px;">

            <!-- ── Driver List (Left Panel) ──────────────────── -->
            <div class="col-12 col-lg-4"
                 style="border-right:1px solid #e8edf5;">

                <!-- Search header -->
                <div class="px-3 py-3" style="border-bottom:1px solid #e8edf5;background:#f8fafc;">
                    <div style="font-size:0.78rem;font-weight:700;color:var(--uis-primary);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:8px;">
                        <i class="fas fa-users me-1"></i> Drivers
                        <span class="float-end" style="font-weight:400;color:#9ca3af;"><?php echo count($driver_list); ?> total</span>
                    </div>
                    <input type="text" id="driverSearch" placeholder="Search driver..."
                           class="form-control form-control-sm"
                           style="border-radius:var(--radius-md);border:1.5px solid #e5e9f0;font-size:0.82rem;">
                </div>

                <!-- Driver list -->
                <div id="driverListWrap" style="overflow-y:auto;max-height:540px;">
                    <?php if (empty($driver_list)): ?>
                    <div class="text-center py-5" style="color:#9ca3af;">
                        <i class="fas fa-user-slash fa-lg mb-2 d-block"></i>
                        <div style="font-size:0.84rem;">No driver accounts found</div>
                    </div>
                    <?php endif; ?>
                    <?php foreach ($driver_list as $drv): ?>
                    <?php
                        $parts = array_filter(explode(' ', trim($drv['full_name'])));
                        $ini = '';
                        foreach (array_slice($parts, 0, 2) as $p) { $ini .= strtoupper($p[0]); }
                        $is_active = ($drv['user_id'] == $selected);
                        $preview = $drv['last_msg'] ? (mb_strlen($drv['last_msg']) > 42 ? mb_substr($drv['last_msg'], 0, 42) . '…' : $drv['last_msg']) : 'No messages yet';
                        $time_str = $drv['last_time'] ? date('d M', strtotime($drv['last_time'])) : '';
                    ?>
                    <a href="<?php echo SITE_URL; ?>/admin/messages.php?to=<?php echo $drv['user_id']; ?>"
                       class="driver-contact-item d-flex align-items-center gap-3 px-3 py-3 text-decoration-none"
                       data-name="<?php echo htmlspecialchars(strtolower($drv['full_name'])); ?>"
                       style="border-bottom:1px solid #f0f4f8;background:<?php echo $is_active ? 'linear-gradient(135deg,#eff6ff,#e0f2fe)' : '#fff'; ?>;transition:background 0.15s;">
                        <!-- Avatar -->
                        <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 position-relative"
                             style="width:42px;height:42px;background:linear-gradient(135deg,var(--uis-primary),var(--uis-secondary));color:#fff;font-size:0.8rem;font-weight:700;">
                            <?php echo htmlspecialchars($ini ?: 'D'); ?>
                            <?php if ($drv['unread_count'] > 0): ?>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill"
                                  style="background:#dc2626;font-size:0.6rem;min-width:16px;height:16px;display:flex;align-items:center;justify-content:center;">
                                <?php echo $drv['unread_count']; ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <!-- Info -->
                        <div class="flex-grow-1" style="min-width:0;">
                            <div class="d-flex justify-content-between align-items-center">
                                <span style="font-size:0.84rem;font-weight:<?php echo $drv['unread_count'] > 0 ? '700' : '600'; ?>;color:#1a2035;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:130px;">
                                    <?php echo htmlspecialchars($drv['full_name']); ?>
                                </span>
                                <?php if ($time_str): ?>
                                <span style="font-size:0.7rem;color:#9ca3af;flex-shrink:0;margin-left:4px;"><?php echo $time_str; ?></span>
                                <?php endif; ?>
                            </div>
                            <div style="font-size:0.75rem;color:<?php echo $drv['unread_count'] > 0 ? '#374151' : '#9ca3af'; ?>;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                <?php echo htmlspecialchars($preview); ?>
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- ── Conversation / Compose (Right Panel) ──────── -->
            <div class="col-12 col-lg-8 d-flex flex-column">

                <?php if ($selected_user): ?>
                <!-- Conversation Header -->
                <div class="px-4 py-3 d-flex align-items-center gap-3"
                     style="border-bottom:1px solid #e8edf5;background:#f8fafc;">
                    <?php
                        $parts = array_filter(explode(' ', trim($selected_user['full_name'])));
                        $ini = '';
                        foreach (array_slice($parts, 0, 2) as $p) { $ini .= strtoupper($p[0]); }
                    ?>
                    <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                         style="width:40px;height:40px;background:linear-gradient(135deg,var(--uis-primary),var(--uis-secondary));color:#fff;font-size:0.78rem;font-weight:700;">
                        <?php echo htmlspecialchars($ini ?: 'D'); ?>
                    </div>
                    <div>
                        <div style="font-size:0.9rem;font-weight:700;color:#1a2035;">
                            <?php echo htmlspecialchars($selected_user['full_name']); ?>
                        </div>
                        <div style="font-size:0.75rem;color:#6b7280;">
                            <i class="fas fa-id-card fa-xs me-1"></i>Driver
                            <?php if (!empty($selected_user['employee_id'])): ?>
                            · <?php echo htmlspecialchars($selected_user['employee_id']); ?>
                            <?php endif; ?>
                            <?php if (!empty($selected_user['phone'])): ?>
                            · <i class="fas fa-phone fa-xs me-1"></i><?php echo htmlspecialchars($selected_user['phone']); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Messages Thread -->
                <div id="chatBox" class="flex-grow-1 px-4 py-3"
                     style="overflow-y:auto;max-height:400px;background:#f8fafc;display:flex;flex-direction:column;gap:12px;">
                    <?php if (empty($conversation)): ?>
                    <div class="text-center my-auto py-4" style="color:#9ca3af;">
                        <i class="fas fa-comment-slash fa-2x mb-2 d-block"></i>
                        <div style="font-size:0.84rem;">No messages yet. Start the conversation!</div>
                    </div>
                    <?php else: ?>
                    <?php foreach ($conversation as $msg): ?>
                    <?php $is_mine = ((int)$msg['sender_id'] === $me); ?>
                    <div class="d-flex <?php echo $is_mine ? 'justify-content-end' : 'justify-content-start'; ?>">
                        <?php if (!$is_mine): ?>
                        <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 me-2"
                             style="width:30px;height:30px;background:linear-gradient(135deg,var(--uis-primary),var(--uis-secondary));color:#fff;font-size:0.65rem;font-weight:700;align-self:flex-end;">
                            <?php echo htmlspecialchars($ini ?: 'D'); ?>
                        </div>
                        <?php endif; ?>
                        <div style="max-width:70%;">
                            <div style="background:<?php echo $is_mine ? 'linear-gradient(135deg,var(--uis-primary),#1d4ed8)' : '#fff' ?>;
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
                                <i class="fas fa-check<?php echo $msg['is_read'] ? '-double' : ''; ?> ms-1" style="color:<?php echo $msg['is_read'] ? '#059669' : '#9ca3af'; ?>;"></i>
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
                                    style="background:linear-gradient(135deg,var(--uis-primary),#1d4ed8);color:#fff;border:none;padding:10px 18px;border-radius:var(--radius-md);">
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </div>
                        <div style="font-size:0.72rem;color:#9ca3af;margin-top:4px;">
                            Press <kbd style="font-size:0.68rem;">Enter</kbd> to send · <kbd style="font-size:0.68rem;">Shift+Enter</kbd> for new line
                        </div>
                    </form>
                </div>

                <?php else: ?>
                <!-- Empty State -->
                <div class="flex-grow-1 d-flex align-items-center justify-content-center"
                     style="background:#f8fafc;">
                    <div class="text-center py-5" style="color:#9ca3af;">
                        <div class="rounded-circle d-inline-flex align-items-center justify-content-center mb-3"
                             style="width:72px;height:72px;background:linear-gradient(135deg,#e0f2fe,#bfdbfe);">
                            <i class="fas fa-comments fa-2x" style="color:var(--uis-primary);"></i>
                        </div>
                        <div style="font-size:0.9rem;font-weight:600;color:#374151;margin-bottom:4px;">
                            Select a driver to start messaging
                        </div>
                        <div style="font-size:0.8rem;">
                            Choose a driver from the list on the left
                        </div>
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
    // Auto-scroll chat to bottom
    var box = document.getElementById("chatBox");
    if (box) box.scrollTop = box.scrollHeight;

    // Driver search filter
    document.getElementById("driverSearch").addEventListener("input", function(){
        var q = this.value.toLowerCase();
        document.querySelectorAll(".driver-contact-item").forEach(function(el){
            el.style.display = el.dataset.name.includes(q) ? "" : "none";
        });
    });
})();
</script>';
require_once '../includes/footer.php';
?>
