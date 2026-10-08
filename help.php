<?php
// ============================================================
// UIS Driver Scheduling and Management System
// help.php  –  Help & User Guide (role-aware)
// Universiti Islam Selangor (UIS)
// ============================================================
$page_title   = 'Help & User Guide';
$current_page = 'help.php';
require_once 'config/database.php';
requireLogin();

$role = $_SESSION['role'] ?? 'driver';
if ($role === 'superadmin') {
    $role = 'admin';
}
if (!in_array($role, ['admin', 'driver', 'staff', 'supervisor'], true)) {
    $role = 'driver';
}

$role_label = [
    'admin'      => 'Administrator',
    'driver'     => 'Driver',
    'staff'      => 'Staff',
    'supervisor' => 'Head of Section',
][$role];

// ── Quick start steps (menu names match the sidebar) ────────────
$quick_start = [
    'staff' => [
        'Open <strong>Request Vehicle</strong> in the menu.',
        'Pick your trip date and time first. The page then shows only the vehicles that are free.',
        'Fill in the trip details, the officer name, officer phone and waiting place. Attach your letter if you have one.',
        'Press submit. Your Head of Section will review it.',
        'Open <strong>My Requests</strong> any time to see the status.',
    ],
    'supervisor' => [
        'Open <strong>Vehicle Approvals</strong> in the menu. The number badge shows how many requests wait for you.',
        'Open a request and read the purpose, passengers and dates.',
        'Press <strong>View</strong> on each document and read it.',
        'Choose <strong>Approve</strong>, or <strong>Reject</strong> and write a reason.',
    ],
    'admin' => [
        'Open <strong>Vehicle Requests</strong>. The badge shows approved requests that wait for you.',
        'Process an approved request. This creates a schedule.',
        'Open <strong>Schedules</strong> and use <strong>Auto Assign</strong> to give the trip a driver.',
        'Check the plan in <strong>Schedules &rarr; Calendar</strong>.',
        'Open <strong>Reports</strong> when you need a summary.',
    ],
    'driver' => [
        'Open <strong>My Schedules</strong> to see your trips.',
        'Check the date, time, place and the officer\'s name and phone.',
        'Open <strong>Calendar</strong> to see the whole month at a glance.',
        'Start the trip when you leave. Complete it when you finish.',
    ],
];

// ── Accordion items ─────────────────────────────────────────────
// Each item: [id, icon, title, html, group]
$items = [];

// ---------- STAFF ----------
if ($role === 'staff') {
    $items[] = ['staff-request', 'file-circle-plus', 'How to request a vehicle', '
        <p>Use <strong>Request Vehicle</strong> in the menu.</p>
        <ol>
            <li><strong>Plan ahead.</strong> You must send your request at least <strong>3 days before</strong> the trip. Earlier dates cannot be picked.</li>
            <li><strong>Choose the date and time first.</strong> The page then shows only vehicles that are free and have enough seats for your passengers.</li>
            <li><strong>Write the trip details</strong>: where you are going, why, and how many passengers.</li>
            <li><strong>Give the officer name, officer phone and waiting place.</strong> The driver will call the officer when the vehicle arrives or if the driver cannot find the group. The waiting place tells the driver where to pick you up.</li>
            <li><strong>Attach supporting documents</strong> if you have them, for example a Release Letter (<em>Surat Pelepasan</em>) or a Seminar Letter.</li>
            <li>Press the submit button.</li>
        </ol>
        <h3 class="h6 fw-bold mt-3">About documents</h3>
        <ul>
            <li>Allowed types: PDF, JPG, PNG and WebP.</li>
            <li>Each file can be up to <strong>5 MB</strong>.</li>
            <li>You can add up to <strong>5 files</strong>.</li>
            <li>One PDF can have many pages, so you can put a whole letter in one file.</li>
        </ul>
    ', 'role'];

    $items[] = ['staff-track', 'clock-rotate-left', 'How to track your request', '
        <p>Open <strong>My Requests</strong>. Each request shows one of these statuses.</p>
        <div class="table-responsive">
        <table class="table table-sm align-middle help-table">
            <caption class="visually-hidden">Request statuses and their meaning</caption>
            <thead><tr><th scope="col">Status</th><th scope="col">What it means</th></tr></thead>
            <tbody>
                <tr><th scope="row"><span class="badge bg-warning text-dark">Pending</span></th><td>Your Head of Section has not decided yet. Please wait.</td></tr>
                <tr><th scope="row"><span class="badge bg-success">Approved</span></th><td>Your Head of Section said yes. The Transport Unit will now arrange a driver.</td></tr>
                <tr><th scope="row"><span class="badge bg-primary">Processed</span></th><td>A driver and vehicle are assigned to your trip. You are all set.</td></tr>
                <tr><th scope="row"><span class="badge bg-danger">Rejected</span></th><td>Your request was not accepted. The reason is shown on the request.</td></tr>
                <tr><th scope="row"><span class="badge bg-secondary">Cancelled</span></th><td>The request was cancelled and will not go ahead.</td></tr>
            </tbody>
        </table>
        </div>
    ', 'role'];

    $items[] = ['staff-cancel', 'ban', 'How to cancel a request', '
        <p>You can cancel a request <strong>only while it is Pending</strong>.</p>
        <ol>
            <li>Open <strong>My Requests</strong>.</li>
            <li>Find the request and press <strong>Cancel</strong>.</li>
            <li>Confirm in the pop-up.</li>
        </ol>
        <p>If the request is already Approved or Processed, please contact the Transport Unit.</p>
    ', 'role'];

    $items[] = ['staff-rejected', 'circle-xmark', 'What to do if your request is rejected', '
        <ol>
            <li>Open <strong>My Requests</strong> and read the reason.</li>
            <li>Fix the problem. For example, change the date, add a missing document, or reduce the number of passengers.</li>
            <li>Send a <strong>new request</strong> with <strong>Request Vehicle</strong>.</li>
        </ol>
        <p>If you do not understand the reason, speak to your Head of Section.</p>
    ', 'role'];
}

// ---------- SUPERVISOR ----------
if ($role === 'supervisor') {
    $items[] = ['sup-review', 'clipboard-check', 'Reviewing requests', '
        <p>Open <strong>Vehicle Approvals</strong>. A number badge on the menu shows how many requests are waiting for you.</p>
        <h3 class="h6 fw-bold mt-3">What to check</h3>
        <ul>
            <li><strong>Purpose:</strong> is the trip for official UIS work?</li>
            <li><strong>Passengers:</strong> is the number sensible for the trip?</li>
            <li><strong>Documents:</strong> press <strong>View</strong> and read each letter <strong>before</strong> you approve.</li>
        </ul>
        <h3 class="h6 fw-bold mt-3">Making your decision</h3>
        <ul>
            <li><strong>Approve:</strong> the request goes to the Transport Unit.</li>
            <li><strong>Reject:</strong> you <strong>must write a reason</strong>. The staff member will read it and can send a new request.</li>
        </ul>
    ', 'role'];

    $items[] = ['sup-status', 'tags', 'Request statuses', '
        <div class="table-responsive">
        <table class="table table-sm align-middle help-table">
            <caption class="visually-hidden">Request statuses and their meaning</caption>
            <thead><tr><th scope="col">Status</th><th scope="col">What it means for you</th></tr></thead>
            <tbody>
                <tr><th scope="row"><span class="badge bg-warning text-dark">Pending</span></th><td>Waiting for your decision.</td></tr>
                <tr><th scope="row"><span class="badge bg-success">Approved</span></th><td>You approved it. The Transport Unit will arrange a driver.</td></tr>
                <tr><th scope="row"><span class="badge bg-primary">Processed</span></th><td>A driver and vehicle are now assigned. Nothing more for you to do.</td></tr>
                <tr><th scope="row"><span class="badge bg-danger">Rejected</span></th><td>You rejected it and gave a reason.</td></tr>
                <tr><th scope="row"><span class="badge bg-secondary">Cancelled</span></th><td>The staff member cancelled it before a decision.</td></tr>
            </tbody>
        </table>
        </div>
    ', 'role'];

    $items[] = ['sup-badge', 'bell', 'The badge on the menu', '
        <p>The number next to <strong>Vehicle Approvals</strong> is the count of requests that still need your decision. It goes down as you approve or reject. When it disappears, you have no waiting requests.</p>
    ', 'role'];
}

// ---------- ADMIN ----------
if ($role === 'admin') {
    $items[] = ['admin-process', 'gears', 'Processing approved requests', '
        <p>Open <strong>Vehicle Requests</strong>. A badge shows how many approved requests are waiting.</p>
        <ol>
            <li>Open an approved request and check the details and documents.</li>
            <li>Process it. This creates a schedule for the trip.</li>
            <li>Give the trip a driver (see the next topic).</li>
        </ol>
        <p>After you process it, the status becomes <strong>Processed</strong> and the staff member can see it.</p>
    ', 'role'];

    $items[] = ['admin-auto', 'wand-magic-sparkles', 'Auto-assign and driver recommendations', '
        <p>Open <strong>Schedules &rarr; Auto Assign</strong>. The system suggests the best driver for each trip.</p>
        <p>Each driver gets an <strong>allocation score</strong>. A higher score means a better match. The score is made of three parts.</p>
        <ul>
            <li><strong>50%</strong> &ndash; fewer tasks this month.</li>
            <li><strong>30%</strong> &ndash; fewer weekend tasks.</li>
            <li><strong>20%</strong> &ndash; more experience.</li>
        </ul>
        <p>This keeps the work fair between drivers.</p>
        <p>The driver&rsquo;s <strong>licence class</strong> (B2, D or E) must match the vehicle. Drivers with the wrong class are not suggested.</p>
        <p><strong>You can always change it.</strong> If you know a better choice, pick another driver yourself.</p>
    ', 'role'];

    $items[] = ['admin-team', 'users', 'Jobs that need more than one driver', '
        <p>Most jobs need one driver. Some jobs, such as a seminar or an event with several buses, need two or more.</p>
        <ol>
            <li>Open <strong>Create Schedule</strong> and choose <strong>Drivers needed</strong> (2 to 5).</li>
            <li>Pick a driver and a vehicle for each row. Drivers can use different vehicles, or share one (for example a relief driver on a long trip).</li>
            <li>Save. Every driver gets the same job on their own schedule and sees who else is on it.</li>
        </ol>
        <p>To add another driver to a job that already exists, press the <strong>group icon</strong> on that row in <strong>All Schedules</strong>.</p>
        <p><strong>Duplicates are blocked.</strong> The same driver cannot be on the same job twice, and the same job (same date, start time and destination) cannot be entered twice.</p>
    ', 'role'];

    $items[] = ['admin-manage', 'car-side', 'Managing vehicles and drivers', '
        <ul>
            <li><strong>Vehicles &rarr; All Vehicles</strong> and <strong>Drivers &rarr; All Drivers</strong> show everything in one place.</li>
            <li>Use <strong>Add Vehicle</strong> or <strong>Add Driver</strong> to create new records. You can upload a photo.</li>
            <li>Switch between <strong>Cards</strong> and <strong>Table</strong> view with the buttons on the page. The page remembers your choice.</li>
            <li>Mark a driver as <strong>Top Management</strong> if they only drive VIP or executive trips.</li>
        </ul>
    ', 'role'];

    $items[] = ['admin-reports', 'chart-column', 'Reports', '
        <p>Open <strong>Reports</strong> in the menu. You will find reports for drivers, workload, vehicles and monthly summaries.</p>
        <p>The <strong>Workload report</strong> has a monthly view. It shows how many tasks each driver had, so you can see if the work is fair.</p>
    ', 'role'];

    $items[] = ['admin-sheet', 'print', 'Printing the Tugasan Pemandu task sheet', '
        <ol>
            <li>Open <strong>Schedules</strong> and open the trip you want.</li>
            <li>Press the <strong>print</strong> button for the task sheet.</li>
            <li>The <em>Tugasan Pemandu</em> sheet opens in a new tab. Use your browser&rsquo;s print option.</li>
        </ol>
        <p>The driver can also print the same sheet from the driver account.</p>
    ', 'role'];
}

// ---------- DRIVER ----------
if ($role === 'driver') {
    $items[] = ['drv-view', 'calendar-days', 'Viewing your schedules and calendar', '
        <ul>
            <li><strong>My Schedules</strong> lists your trips. Press a trip to see all its details.</li>
            <li><strong>Calendar</strong> shows your trips by day, so you can plan the month.</li>
            <li>Check the <strong>Messages</strong> menu for notes from the Transport Unit.</li>
        </ul>
    ', 'role'];

    $items[] = ['drv-officer', 'phone', 'The officer and the waiting place', '
        <p>Each trip shows three useful details.</p>
        <ul>
            <li><strong>Officer name:</strong> the person in charge of the group.</li>
            <li><strong>Officer phone:</strong> tap the number to call from your phone.</li>
            <li><strong>Waiting place:</strong> where the group will wait for you.</li>
        </ul>
        <h3 class="h6 fw-bold mt-3">If the officer is late</h3>
        <p>Do not leave. Call the officer using the phone number on the trip. Ask where they are and when they will be ready.</p>
    ', 'role'];

    $items[] = ['drv-trip', 'route', 'Starting and completing a trip', '
        <ol>
            <li>Open <strong>My Schedules</strong>.</li>
            <li>When you leave, press <strong>Start Trip</strong>.</li>
            <li>When you finish, press <strong>Complete Trip</strong> and confirm.</li>
        </ol>
        <p>A completed trip cannot be changed back, so please press it only when the trip is really finished.</p>
    ', 'role'];

    $items[] = ['drv-sheet', 'print', 'Printing the task sheet', '
        <p>In <strong>My Schedules</strong>, press the print button on a trip. The <em>Tugasan Pemandu</em> task sheet opens in a new tab. Use your browser&rsquo;s print option.</p>
    ', 'role'];
}

// ---------- FOR EVERYONE ----------
$items[] = ['all-tips', 'lightbulb', 'Tips and shortcuts', '
    <ul>
        <li>Press <kbd>/</kbd> on your keyboard to jump to the search box on a page.</li>
        <li>The <strong>Cards / Table</strong> buttons switch the view. The page remembers your choice next time.</li>
        <li>Press <kbd>Esc</kbd> to close a pop-up.</li>
        <li>The <i class="fas fa-bell" aria-hidden="true"></i> <strong>bell icon</strong>, when you see it, shows items that need your attention.</li>
        <li>On a phone, use the menu button at the top to open the main menu.</li>
    </ul>
', 'all'];

$items[] = ['all-signout', 'right-from-bracket', 'Signing out', '
    <p>Press <strong>Log Out</strong> at the bottom of the menu. Always sign out when you use a shared computer.</p>
', 'all'];

$items[] = ['all-contact', 'headset', 'Who do I contact?', '
    <p>For help that is not on this page, please contact the <strong>Transport Unit, Universiti Islam Selangor</strong>.</p>
    <p>When you ask for help, please tell them your name, your role and what you were doing. This helps them answer faster.</p>
', 'all'];

$items[] = ['glossary', 'book', 'Glossary', '
    <dl class="mb-0 help-glossary">
        <dt>Pending</dt>
        <dd>A request that is waiting for the Head of Section to decide.</dd>
        <dt>Approved</dt>
        <dd>A request that the Head of Section accepted. It still needs a driver.</dd>
        <dt>Processed</dt>
        <dd>An approved request that now has a schedule, a driver and a vehicle.</dd>
        <dt>Allocation score</dt>
        <dd>A number that shows how well a driver suits a trip. It looks at workload, weekend work and experience. A higher number is a better match.</dd>
        <dt>Licence class</dt>
        <dd>The type of driving licence (B2, D or E). It must match the vehicle.</dd>
        <dt>Top Management trip (VIP)</dt>
        <dd>A trip for the university&rsquo;s leaders. It needs priority handling and a Top Management driver.</dd>
        <dt>Waiting place</dt>
        <dd>The place where the group waits for the driver to pick them up.</dd>
    </dl>
', 'glossary'];

$role_heading = [
    'staff'      => 'Requesting a vehicle',
    'supervisor' => 'Approving requests',
    'admin'      => 'Managing the system',
    'driver'     => 'Doing your trips',
][$role];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Help &amp; User Guide | UIS Driver Management</title>
    <link rel="icon" type="image/png" href="<?php echo SITE_URL; ?>/assets/images/uis-favicon.png">
    <!-- Bootstrap 5.3 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome 6.4 -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Google Fonts – Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="<?= SITE_URL ?>/assets/css/style.css" rel="stylesheet">
    <style>
        .help-card { border: 1px solid #e5e7eb; border-radius: 14px; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.05); }
        .help-quick ol { padding-left: 0; margin: 0; list-style: none; counter-reset: step; }
        .help-quick li { counter-increment: step; display: flex; gap: .75rem; align-items: flex-start; padding: .45rem 0; }
        .help-quick li::before {
            content: counter(step); flex: 0 0 1.75rem; height: 1.75rem; border-radius: 50%;
            background: #0b5d3b; color: #fff; font-weight: 700; font-size: .85rem;
            display: inline-flex; align-items: center; justify-content: center;
        }
        .help-group-label { font-size: .8rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: #4b5563; margin: 1.5rem 0 .6rem; }
        .help-accordion .accordion-item { border: 1px solid #e5e7eb; border-radius: 14px !important; overflow: hidden; margin-bottom: .6rem; background: #fff; }
        .help-accordion .accordion-button { font-weight: 600; color: #111827; padding: 1rem 1.25rem; background: #fff; }
        .help-accordion .accordion-button:not(.collapsed) { color: #0b5d3b; background: #f0f9f4; box-shadow: none; }
        .help-accordion .accordion-button:focus-visible { outline: 3px solid #15804f; outline-offset: -3px; box-shadow: none; }
        .help-accordion .accordion-button .help-icon { width: 1.75rem; color: #0b5d3b; }
        .help-accordion .accordion-body { color: #1f2937; line-height: 1.65; }
        .help-accordion .accordion-body li { margin-bottom: .35rem; }
        .help-table th, .help-table td { vertical-align: middle; }
        .help-glossary dt { font-weight: 700; color: #0b5d3b; margin-top: .75rem; }
        .help-glossary dt:first-child { margin-top: 0; }
        .help-glossary dd { margin-left: 0; }
        kbd { background: #1f2937; color: #fff; }
        #helpNoResults { display: none; }
        mark.help-hit { background: #fff3b0; padding: 0 .1em; }
    </style>
</head>
<body>
<?php require_once 'includes/sidebar.php'; ?>

<main class="main-content p-4" id="mainContent">
    <?php if (function_exists('showFlash')) { showFlash(); } ?>

    <!-- Header banner -->
    <div style="background: linear-gradient(135deg, #0b5d3b 0%, #15804f 100%); border-radius: 14px; color: #fff; padding: 1.4rem 2rem; margin-bottom: 1.5rem;">
        <h1 class="h4 fw-bold mb-1"><i class="fas fa-circle-question me-2" aria-hidden="true"></i>Help &amp; User Guide</h1>
        <p class="mb-0">Simple steps for your work as <strong><?= htmlspecialchars($role_label) ?></strong>. <?= htmlspecialchars($role_heading) ?> made easy.</p>
    </div>

    <!-- Quick start -->
    <section class="help-card help-quick p-4 mb-4" aria-labelledby="quickStartTitle">
        <h2 class="h5 fw-bold mb-3" id="quickStartTitle"><i class="fas fa-rocket me-2 text-success" aria-hidden="true"></i>Quick start</h2>
        <ol>
            <?php foreach ($quick_start[$role] as $step): ?>
                <li><span><?= $step ?></span></li>
            <?php endforeach; ?>
        </ol>
    </section>

    <!-- Search -->
    <div class="help-card p-3 mb-3">
        <label for="helpSearch" class="form-label fw-semibold mb-1">Search help topics</label>
        <div class="input-group">
            <span class="input-group-text bg-white" aria-hidden="true"><i class="fas fa-magnifying-glass"></i></span>
            <input type="search" id="helpSearch" class="form-control" placeholder="Type a word, for example: cancel, documents, phone"
                   autocomplete="off" aria-controls="helpAccordion">
        </div>
        <div id="helpStatus" class="visually-hidden" role="status" aria-live="polite"></div>
    </div>

    <div id="helpNoResults" class="alert alert-info" role="alert">
        <i class="fas fa-circle-info me-1" aria-hidden="true"></i>No topics match your search. Try a shorter word.
    </div>

    <!-- Accordion -->
    <div class="accordion help-accordion" id="helpAccordion">
        <?php
        $first = true;
        $last_group = null;
        foreach ($items as [$id, $icon, $title, $html, $group]):
            if ($group !== $last_group):
                $group_title = [
                    'role'     => 'For you as ' . ($role === 'supervisor' ? 'a Head of Section' : ($role === 'admin' ? 'an Administrator' : ($role === 'staff' ? 'a staff member' : 'a driver'))),
                    'all'      => 'For everyone',
                    'glossary' => 'Words used in this system',
                ][$group];
                $last_group = $group;
        ?>
        <div class="help-group-label" data-group="<?= htmlspecialchars($group) ?>"><?= htmlspecialchars($group_title) ?></div>
        <?php endif; ?>
        <div class="accordion-item help-item">
            <h2 class="accordion-header" id="h-<?= $id ?>">
                <button class="accordion-button <?= $first ? '' : 'collapsed' ?>" type="button"
                        data-bs-toggle="collapse" data-bs-target="#c-<?= $id ?>"
                        aria-expanded="<?= $first ? 'true' : 'false' ?>" aria-controls="c-<?= $id ?>">
                    <i class="fas fa-<?= $icon ?> help-icon me-2" aria-hidden="true"></i><?= htmlspecialchars($title) ?>
                </button>
            </h2>
            <div id="c-<?= $id ?>" class="accordion-collapse collapse <?= $first ? 'show' : '' ?>"
                 role="region" aria-labelledby="h-<?= $id ?>">
                <div class="accordion-body"><?= $html ?></div>
            </div>
        </div>
        <?php $first = false; endforeach; ?>
    </div>
</main>

<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<!-- Bootstrap 5.3 JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- Custom JS -->
<script src="<?= SITE_URL ?>/assets/js/main.js"></script>
<script>
(function () {
    'use strict';
    var input   = document.getElementById('helpSearch');
    var none    = document.getElementById('helpNoResults');
    var status  = document.getElementById('helpStatus');
    var items   = Array.prototype.slice.call(document.querySelectorAll('#helpAccordion .help-item'));
    var labels  = Array.prototype.slice.call(document.querySelectorAll('#helpAccordion .help-group-label'));
    if (!input) { return; }

    // The accordion item that was open when the page loaded
    var defaultOpen = items.length ? items[0] : null;

    function setOpen(item, open) {
        var panel = item.querySelector('.accordion-collapse');
        var btn   = item.querySelector('.accordion-button');
        panel.classList.add('show');          // avoid animation flicker while filtering
        if (!open) { panel.classList.remove('show'); }
        btn.classList.toggle('collapsed', !open);
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    function filter() {
        var q = input.value.trim().toLowerCase();
        var words = q.split(/\s+/).filter(Boolean);
        var shown = 0;

        items.forEach(function (item) {
            var text = item.textContent.toLowerCase();
            var match = words.every(function (w) { return text.indexOf(w) !== -1; });
            item.hidden = !match;
            if (match) { shown++; }
            if (words.length) { setOpen(item, match); }
            else { setOpen(item, item === defaultOpen); }
        });

        // Hide a group label when all of its items are hidden
        labels.forEach(function (label) {
            var el = label.nextElementSibling, any = false;
            while (el && !el.classList.contains('help-group-label')) {
                if (el.classList.contains('help-item') && !el.hidden) { any = true; }
                el = el.nextElementSibling;
            }
            label.hidden = !any;
        });

        none.style.display = (words.length && shown === 0) ? 'block' : 'none';
        status.textContent = words.length
            ? (shown === 0 ? 'No topics match.' : shown + (shown === 1 ? ' topic matches.' : ' topics match.'))
            : '';
    }

    input.addEventListener('input', filter);
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && input.value) { input.value = ''; filter(); }
    });
})();
</script>
</body>
</html>
