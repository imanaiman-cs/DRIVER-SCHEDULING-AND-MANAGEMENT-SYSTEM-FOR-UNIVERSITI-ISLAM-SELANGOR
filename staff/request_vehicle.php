<?php
// Set to true to make at least one supporting document mandatory.
const REQUIRE_SUPPORTING_DOCUMENT = false;
const MAX_SUPPORTING_DOCUMENTS    = 5;
const MAX_DOCUMENT_BYTES          = 5242880; // 5 MB

$page_title   = 'Request Vehicle';
$current_page = 'request_vehicle.php';
require_once '../config/database.php';
require_once '../includes/upload.php';
requireStaff();

$doc_types = documentTypes();

$staff_id      = (int)$_SESSION['user_id'];
$full_name     = $_SESSION['full_name']  ?? '';
$department    = $_SESSION['department'] ?? '';
$supervisor_id = isset($_SESSION['supervisor_id']) && !empty($_SESSION['supervisor_id'])
    ? (int)$_SESSION['supervisor_id']
    : null;

$min_date = date('Y-m-d', strtotime('+3 days'));

$errors = [];
$form   = [
    'trip_date'       => '',
    'start_time'      => '',
    'end_time'        => '',
    'destination'     => '',
    'purpose'         => '',
    'passenger_count' => '',
    'vehicle_ids'     => [],
    'vehicles_needed' => '1',
    'officer_name'    => $full_name,
    'officer_phone'   => '',
    'waiting_place'   => '',
];
$had_files = false; // true when the failed POST contained chosen files

// ── Step 1 (choose vehicles) or step 2 (the form) ────────────
// GET with no choice          -> vehicle cards (pick one or more).
// ?vehicles=5,7               -> the form for those vehicles.
// ?any=2                      -> the form for "any available vehicle" x 2.
// (old links ?vehicle=5 and ?vehicle=any still work)
// A POST (including one that failed validation) always shows the form.
$get_ids = [];
$get_any = 0;
if (isset($_GET['vehicle']) && $_GET['vehicle'] !== '') {            // old single-vehicle link
    if ($_GET['vehicle'] === 'any') { $get_any = 1; } else { $get_ids = [(int)$_GET['vehicle']]; }
}
if (isset($_GET['vehicles']) && $_GET['vehicles'] !== '') {
    $get_ids = array_slice(array_values(array_unique(array_filter(array_map('intval', explode(',', (string)$_GET['vehicles']))))), 0, 5);
}
if (isset($_GET['any']) && $_GET['any'] !== '') {
    $get_any = max(1, min(5, (int)$_GET['any']));
}
$show_picker = ($_SERVER['REQUEST_METHOD'] !== 'POST') && !$get_ids && $get_any === 0;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $get_ids) {
    // Only vehicles that can be requested may be chosen (not retired / in maintenance)
    $in_ids = implode(',', array_map('intval', $get_ids));
    $okq = $conn->query("SELECT COUNT(*) AS c FROM vehicles WHERE vehicle_id IN ({$in_ids}) AND status IN ('available', 'in_use')");
    $ok_count = $okq ? (int)$okq->fetch_assoc()['c'] : 0;
    if ($ok_count !== count($get_ids)) {
        setFlash('warning', 'One of the vehicles cannot be requested right now. Please choose again.');
        header('Location: request_vehicle.php');
        exit();
    }
    $form['vehicle_ids']     = $get_ids;
    $form['vehicles_needed'] = (string)count($get_ids);
} elseif ($_SERVER['REQUEST_METHOD'] !== 'POST' && $get_any > 0) {
    $form['vehicles_needed'] = '1';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $trip_date       = trim($_POST['trip_date']       ?? '');
    $start_time      = trim($_POST['start_time']      ?? '');
    $end_time        = trim($_POST['end_time']        ?? '');
    $destination     = trim($_POST['destination']     ?? '');
    $purpose         = trim($_POST['purpose']         ?? '');
    $passenger_count = (int)($_POST['passenger_count'] ?? 0);
    $posted_ids      = (isset($_POST['vehicle_ids']) && is_array($_POST['vehicle_ids'])) ? $_POST['vehicle_ids'] : [];
    $vehicle_ids     = array_slice(array_values(array_unique(array_filter(array_map('intval', $posted_ids)))), 0, 5);
    $vehicles_needed = $vehicle_ids ? count($vehicle_ids) : 1;     // 'any': the transport unit decides how many
    $vehicle_id      = $vehicle_ids ? $vehicle_ids[0] : null;       // the first one is kept on the request itself
    $officer_name    = trim($_POST['officer_name']    ?? '');
    $officer_phone   = trim($_POST['officer_phone']   ?? '');
    $waiting_place   = trim($_POST['waiting_place']   ?? '');

    $form = [
        'trip_date'       => $trip_date,
        'start_time'      => $start_time,
        'end_time'        => $end_time,
        'destination'     => $destination,
        'purpose'         => $purpose,
        'passenger_count' => $passenger_count > 0 ? (string)$passenger_count : '',
        'vehicle_ids'     => $vehicle_ids,
        'vehicles_needed' => (string)$vehicles_needed,
        'officer_name'    => $officer_name,
        'officer_phone'   => $officer_phone,
        'waiting_place'   => $waiting_place,
    ];

    // Trip date: required, valid, at least 3 days ahead
    if ($trip_date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $trip_date) || !strtotime($trip_date)) {
        $errors[] = 'Trip date is required and must be a valid date.';
    } elseif ($trip_date < $min_date) {
        $errors[] = 'Requests must be submitted at least 3 days in advance (earliest allowed date: ' . date('d M Y', strtotime($min_date)) . ').';
    }

    // Times: required, valid, end after start
    if ($start_time === '' || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $start_time)) {
        $errors[] = 'Start time is required.';
    }
    if ($end_time === '' || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $end_time)) {
        $errors[] = 'End time is required.';
    }
    if ($start_time !== '' && $end_time !== '' && $end_time <= $start_time) {
        $errors[] = 'End time must be after the start time.';
    }

    if ($destination === '') {
        $errors[] = 'Destination is required.';
    }
    if (strlen($purpose) < 20) {
        $errors[] = 'Purpose must be at least 20 characters long.';
    }
    if ($passenger_count < 1) {
        $errors[] = 'Passenger count must be at least 1.';
    }
    if ($officer_name === '') {
        $errors[] = 'Officer name(s) is required.';
    } elseif (mb_strlen($officer_name) > 255) {
        $errors[] = 'Officer name(s) must not exceed 255 characters.';
    }
    if ($officer_phone === '') {
        $errors[] = 'Officer phone number is required.';
    } elseif (!preg_match('/^[0-9+\-\s()]{7,20}$/', $officer_phone)) {
        $errors[] = 'Officer phone number is invalid (use digits, spaces, +, - and brackets only, e.g. 011-2835 4792).';
    }
    if (mb_strlen($waiting_place) > 150) {
        $errors[] = 'Waiting place must not exceed 150 characters.';
    }
    $waiting_place_db = $waiting_place !== '' ? $waiting_place : null;

    // Supporting documents: gather chosen files (keyed by their form row index)
    $files     = collectUploadedFiles('docs');
    $had_files = !empty($files);

    // A POST larger than post_max_size arrives with $_POST and $_FILES both empty
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $errors[] = 'The submitted form was too large and was not received. Each document must be 5 MB or smaller.';
    }
    if (count($files) > MAX_SUPPORTING_DOCUMENTS) {
        $errors[] = 'You can attach at most ' . MAX_SUPPORTING_DOCUMENTS . ' supporting documents.';
    } elseif (REQUIRE_SUPPORTING_DOCUMENT && empty($files)) {
        $errors[] = 'Please attach at least one supporting document.';
    }

    // Re-verify every chosen vehicle: can be requested, free for that slot, and
    // the seats of all of them together cover the passengers
    if (empty($errors) && $vehicle_ids) {
        $in_ids = implode(',', array_map('intval', $vehicle_ids));
        $stmt = $conn->prepare(
            "SELECT v.vehicle_id, v.plate_number, v.capacity
             FROM vehicles v
             WHERE v.vehicle_id IN ({$in_ids}) AND v.status IN ('available', 'in_use')
               AND v.vehicle_id NOT IN (
                     SELECT vehicle_id FROM schedules
                     WHERE trip_date = ? AND status NOT IN ('cancelled','completed')
                       AND vehicle_id IS NOT NULL AND start_time < ? AND end_time > ?
               )"
        );
        $stmt->bind_param('sss', $trip_date, $end_time, $start_time);
        $stmt->execute();
        $free_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (count($free_rows) !== count($vehicle_ids)) {
            $free_ids = array_map('intval', array_column($free_rows, 'vehicle_id'));
            $bad = array_diff($vehicle_ids, $free_ids);
            $names = [];
            if ($bad) {
                $nq = $conn->query("SELECT plate_number FROM vehicles WHERE vehicle_id IN (" . implode(',', array_map('intval', $bad)) . ")");
                while ($nq && ($nr = $nq->fetch_assoc())) { $names[] = $nr['plate_number']; }
            }
            $errors[] = 'Not free for that date and time, or not available: ' . ($names ? implode(', ', $names) : 'a selected vehicle')
                      . '. Please choose other vehicles.';
            $form['vehicle_ids'] = array_values(array_intersect($vehicle_ids, $free_ids));
        } else {
            $seats = array_sum(array_map('intval', array_column($free_rows, 'capacity')));
            if ($seats < $passenger_count) {
                $errors[] = 'The vehicles you chose seat ' . $seats . ' in total, but you have ' . $passenger_count
                          . ' passengers. Add another vehicle or reduce the passengers.';
            }
        }
    }

    // Store the uploaded documents (only once everything else is valid)
    $saved_docs = [];
    if (empty($errors) && !empty($files)) {
        $valid_types = array_keys($doc_types);
        $posted_types = (isset($_POST['doc_type']) && is_array($_POST['doc_type'])) ? $_POST['doc_type'] : [];

        foreach ($files as $i => $file) {
            $type = $posted_types[$i] ?? 'other';
            if (!is_string($type) || !in_array($type, $valid_types, true)) {
                $type = 'other';
            }

            $res = saveUploadedDocument($file, MAX_DOCUMENT_BYTES);
            if (empty($res['ok'])) {
                // Roll back files already stored during this request
                foreach ($saved_docs as $d) {
                    deleteStoredDocument($d['path']);
                }
                $saved_docs = [];
                $label = mb_strimwidth((string)($file['name'] ?? ''), 0, 80, '...');
                $errors[] = 'Document "' . $label . '": ' . ($res['error'] ?? 'the file could not be uploaded.');
                break;
            }

            $saved_docs[] = [
                'type' => $type,
                'name' => (string)$res['original_name'],
                'path' => (string)$res['path'],
                'mime' => (string)$res['mime'],
                'size' => (int)$res['size'],
            ];
        }
    }

    if (empty($errors)) {
        $in_transaction = false;
        try {
            $conn->begin_transaction();
            $in_transaction = true;

            $stmt = $conn->prepare(
                "INSERT INTO vehicle_requests
                     (staff_id, vehicle_id, trip_date, start_time, end_time,
                      destination, purpose, passenger_count, supervisor_id,
                      officer_name, officer_phone, waiting_place, vehicles_needed)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            if (!$stmt) {
                throw new RuntimeException('Could not prepare the request insert.');
            }
            $stmt->bind_param(
                'iisssssiisssi',
                $staff_id, $vehicle_id, $trip_date, $start_time, $end_time,
                $destination, $purpose, $passenger_count, $supervisor_id,
                $officer_name, $officer_phone, $waiting_place_db, $vehicles_needed
            );
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('Could not save the request.');
            }
            $new_request_id = (int)$stmt->insert_id;
            $stmt->close();

            // Every vehicle the staff member asked for
            if ($vehicle_ids) {
                $rvs = $conn->prepare("INSERT INTO request_vehicles (request_id, vehicle_id) VALUES (?, ?)");
                if (!$rvs) {
                    throw new RuntimeException('Could not prepare the vehicle insert.');
                }
                foreach ($vehicle_ids as $vid) {
                    $rvs->bind_param('ii', $new_request_id, $vid);
                    if (!$rvs->execute()) {
                        $rvs->close();
                        throw new RuntimeException('Could not save the requested vehicles.');
                    }
                }
                $rvs->close();
            }

            if (!empty($saved_docs)) {
                $doc_stmt = $conn->prepare(
                    "INSERT INTO request_documents
                         (request_id, doc_type, original_name, stored_path, mime_type, file_size)
                     VALUES (?, ?, ?, ?, ?, ?)"
                );
                if (!$doc_stmt) {
                    throw new RuntimeException('Could not prepare the document insert.');
                }
                foreach ($saved_docs as $d) {
                    $doc_stmt->bind_param(
                        'issssi',
                        $new_request_id, $d['type'], $d['name'], $d['path'], $d['mime'], $d['size']
                    );
                    if (!$doc_stmt->execute()) {
                        $doc_stmt->close();
                        throw new RuntimeException('Could not save a supporting document.');
                    }
                }
                $doc_stmt->close();
            }

            $conn->commit();
            $in_transaction = false;

            $doc_count = count($saved_docs);
            setFlash(
                'success',
                $doc_count > 0
                    ? 'Vehicle request submitted with ' . $doc_count . ' supporting document' . ($doc_count !== 1 ? 's' : '') . '. Awaiting your Head of Section approval.'
                    : 'Vehicle request submitted. Awaiting your Head of Section approval.'
            );
            header('Location: my_requests.php');
            exit();
        } catch (Throwable $ex) {
            if ($in_transaction) {
                try {
                    $conn->rollback();
                } catch (Throwable $rollbackEx) {
                    // ignore: connection may already be gone
                }
            }
            foreach ($saved_docs as $d) {
                deleteStoredDocument($d['path']);
            }
            $errors[] = 'A database error occurred. Please try again.';
        }
    }
}

// ── Suggestions from this staff member's previous requests ───────
// (recognition rather than recall: most recent distinct values first)
$suggest = ['destination' => [], 'officer_name' => [], 'officer_phone' => [], 'waiting_place' => []];
foreach (array_keys($suggest) as $col) {   // $col comes from this fixed list only
    $stmt = $conn->prepare(
        "SELECT `$col` AS val
         FROM vehicle_requests
         WHERE staff_id = ? AND `$col` IS NOT NULL AND `$col` <> ''
         GROUP BY `$col`
         ORDER BY MAX(created_at) DESC, MAX(request_id) DESC
         LIMIT 8"
    );
    if ($stmt) {
        $stmt->bind_param('i', $staff_id);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $suggest[$col][] = $row['val'];
        }
        $stmt->close();
    }
}

// officer name -> phone / waiting place last used with it (newest wins)
$officer_map = [];
$stmt = $conn->prepare(
    "SELECT officer_name, officer_phone, waiting_place
     FROM vehicle_requests
     WHERE staff_id = ? AND officer_name IS NOT NULL AND officer_name <> ''
     ORDER BY created_at DESC, request_id DESC
     LIMIT 50"
);
if ($stmt) {
    $stmt->bind_param('i', $staff_id);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        if (!isset($officer_map[$row['officer_name']])) {
            $officer_map[$row['officer_name']] = [
                'phone' => (string)($row['officer_phone'] ?? ''),
                'place' => (string)($row['waiting_place'] ?? ''),
            ];
        }
    }
    $stmt->close();
}

// ── Vehicles for the picker (retired ones are never shown) ───
$picker_vehicles = [];
if ($show_picker) {
    $pv = $conn->query(
        "SELECT vehicle_id, plate_number, vehicle_type, brand, model, year, capacity, fuel_type, status, photo
         FROM vehicles WHERE status <> 'retired'
         ORDER BY FIELD(status, 'available', 'in_use', 'maintenance'), vehicle_type, plate_number"
    );
    $picker_vehicles = $pv ? $pv->fetch_all(MYSQLI_ASSOC) : [];
}

// ── The vehicles chosen in step 1 (shown above the form) ─────
$chosen_vehicles = [];
if (!$show_picker && !empty($form['vehicle_ids'])) {
    $in_ids = implode(',', array_map('intval', $form['vehicle_ids']));
    $cvq = $conn->query("SELECT vehicle_id, plate_number, vehicle_type, brand, model, capacity, fuel_type, status, photo
                         FROM vehicles WHERE vehicle_id IN ({$in_ids}) ORDER BY vehicle_type, plate_number");
    $chosen_vehicles = $cvq ? $cvq->fetch_all(MYSQLI_ASSOC) : [];
}
$vehicle_icon = static function (string $type): string {
    return match ($type) {
        'Bus' => 'fa-bus', 'Minibus' => 'fa-bus-simple', 'Van' => 'fa-van-shuttle',
        'Lorry' => 'fa-truck', 'Motorcycle' => 'fa-motorcycle', default => 'fa-car',
    };
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Vehicle | UIS Driver Management</title>
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
        .rv-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(250px,1fr)); gap:1rem; }
        .rv-card { background:#fff; border:1px solid #e3e9e5; border-radius:14px; overflow:hidden; display:flex; flex-direction:column;
                   box-shadow:0 2px 10px rgba(11,93,59,.06); position:relative; }
        .rv-card::before { content:''; position:absolute; inset:0 0 auto 0; height:4px; background:var(--rv,#15803d); z-index:1; }
        .rv-card.st-available { --rv:#15803d; } .rv-card.st-in_use { --rv:#0e7490; } .rv-card.st-maintenance { --rv:#b45309; }
        .rv-card.st-maintenance .rv-photo img, .rv-card.st-maintenance .rv-body { opacity:.7; filter:grayscale(.4); }
        .rv-photo { position:relative; background:#eef3f0; aspect-ratio:16/9; overflow:hidden; }
        .rv-photo img { width:100%; height:100%; object-fit:cover; display:block; }
        .rv-photo.rv-ph img { object-fit:contain; padding:1.2rem; }
        .rv-status { position:absolute; z-index:2; box-shadow:0 1px 4px rgba(0,0,0,.18); top:.7rem; right:.7rem; font-size:.7rem; font-weight:700; padding:.25rem .6rem; border-radius:999px; color:#fff; background:var(--rv); }
        .rv-body { padding:1rem 1.1rem .6rem; flex:1; }
        .rv-plate { font-weight:800; letter-spacing:.04em; font-size:1.05rem; color:#122018; }
        .rv-name { font-weight:600; color:#334155; }
        .rv-meta { font-size:.8rem; color:#64748b; }
        .rv-specs { display:flex; gap:1.2rem; margin-top:.75rem; font-size:.82rem; color:#334155; }
        .rv-specs i { color:#0b5d3b; margin-right:.35rem; }
        .rv-note { font-size:.78rem; margin-top:.7rem; padding:.45rem .6rem; border-radius:8px; background:#fdf3e4; color:#92400e; }
        .rv-note.info { background:#e6f4f7; color:#0e5e72; }
        .rv-foot { padding:.7rem 1.1rem 1.1rem; }
        .rv-chips { display:flex; flex-wrap:wrap; gap:.5rem; }
        .rv-chip { border:1px solid #d6e0da; background:#fff; border-radius:999px; padding:.35rem .9rem; font-size:.82rem; font-weight:600; color:#334155; cursor:pointer; }
        .rv-chip.active { background:#0b5d3b; color:#fff; border-color:#0b5d3b; }
        .rv-bar { position:sticky; bottom:12px; margin-top:1rem; display:flex; justify-content:space-between; align-items:center; gap:1rem; flex-wrap:wrap;
                  background:#fff; border:1px solid #cfe3d8; border-left:4px solid #0b5d3b; border-radius:12px; padding:.8rem 1rem; box-shadow:0 6px 24px rgba(11,93,59,.18); z-index:5; }
        .rv-card.picked { outline:3px solid #0b5d3b; outline-offset:-1px; }
        .rv-vlist { display:flex; flex-direction:column; gap:.5rem; flex:1; min-width:240px; }
        .rv-vrow { display:flex; align-items:center; gap:.75rem; }
        .rv-vrow img { width:64px; height:42px; object-fit:cover; border-radius:6px; background:#eef3f0; }
        .rv-banner { display:flex; align-items:center; gap:1rem; flex-wrap:wrap; background:#fff; border:1px solid #cfe3d8; border-left:4px solid #0b5d3b;
                     border-radius:12px; padding:.8rem 1rem; margin-bottom:1.25rem; }
        .rv-banner img { width:84px; height:56px; object-fit:cover; border-radius:8px; background:#eef3f0; }
    </style>
</head>
<body>
<?php require_once '../includes/sidebar.php'; ?>

<main class="main-content p-4">
    <?php showFlash(); ?>

    <!-- Page Header -->
    <div style="background: linear-gradient(135deg, #0b5d3b 0%, #15804f 100%); border-radius: 14px; color: #fff; padding: 1.4rem 2rem; margin-bottom: 1.5rem;">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h4 class="fw-bold mb-1">
                    <i class="fas fa-van-shuttle me-2"></i>Request Vehicle
                </h4>
                <p class="mb-0 opacity-75">Book an official UIS vehicle for your work trip</p>
            </div>
            <a href="my_requests.php" class="btn btn-light btn-sm fw-semibold">
                <i class="fas fa-clock-rotate-left me-1"></i>My Requests
            </a>
        </div>
    </div>

    <!-- Validation Errors -->
    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-triangle-exclamation me-2"></i>
        <strong>Please fix the following errors:</strong>
        <ul class="mb-0 mt-2 ps-3">
            <?php foreach ($errors as $e): ?>
            <li><?= htmlspecialchars($e) ?></li>
            <?php endforeach; ?>
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <?php if ($show_picker): ?>
    <!-- ── Step 1: choose a vehicle ─────────────────────────────── -->
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3">
        <div>
            <h5 class="fw-bold mb-1">Step 1 of 2 &middot; Choose vehicle(s)</h5>
            <p class="text-muted mb-0 small">Add the vehicle you would like. If your trip needs more than one, add each of them. Not sure (for example a seminar or event)? Let the transport unit decide the vehicles and how many are needed. Vehicles under maintenance are shown for your information only.</p>
        </div>
        <a href="request_vehicle.php?any=1" class="btn btn-outline-primary fw-semibold">
            <i class="fas fa-wand-magic-sparkles me-1" aria-hidden="true"></i>Let the transport unit decide
        </a>
    </div>

    <div class="rv-chips mb-3" role="group" aria-label="Filter by vehicle type">
        <button type="button" class="rv-chip active" data-type="">All</button>
        <?php foreach (array_values(array_unique(array_column($picker_vehicles, 'vehicle_type'))) as $t): ?>
        <button type="button" class="rv-chip" data-type="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></button>
        <?php endforeach; ?>
    </div>

    <?php if (empty($picker_vehicles)): ?>
    <div class="alert alert-info">There are no vehicles to show right now. Use &ldquo;Any available vehicle&rdquo; and the transport unit will decide.</div>
    <?php endif; ?>

    <div class="rv-grid" id="rvGrid">
        <?php foreach ($picker_vehicles as $v):
            $vStatusLabel = ['available' => 'Available', 'in_use' => 'In use', 'maintenance' => 'Under maintenance'][$v['status']] ?? ucfirst($v['status']);
            $canPick = in_array($v['status'], ['available', 'in_use'], true);
            $hasPhoto = !empty($v['photo']);
            $fuelIcon = match ($v['fuel_type']) { 'Electric' => 'fa-bolt', 'Hybrid' => 'fa-leaf', default => 'fa-gas-pump' };
        ?>
        <article class="rv-card st-<?= htmlspecialchars($v['status']) ?>" data-type="<?= htmlspecialchars($v['vehicle_type']) ?>">
            <div class="rv-photo<?= $hasPhoto ? '' : ' rv-ph' ?>">
                <img alt="<?= htmlspecialchars(trim($v['plate_number'] . ' ' . $v['brand'] . ' ' . $v['model'])) ?>"
                     src="<?= htmlspecialchars(vehiclePhotoUrl($v['photo'] ?? null, $v['vehicle_type'])) ?>"
                     data-fallback="<?= htmlspecialchars(vehiclePhotoUrl(null, $v['vehicle_type'])) ?>"
                     loading="lazy"
                     onerror="if(!this.dataset.failed){this.dataset.failed='1';this.src=this.dataset.fallback;this.parentNode.classList.add('rv-ph');}">
                <span class="rv-status"><?= $vStatusLabel ?></span>
            </div>
            <div class="rv-body">
                <div class="rv-plate"><?= htmlspecialchars($v['plate_number']) ?></div>
                <div class="rv-name"><?= htmlspecialchars(trim($v['brand'] . ' ' . $v['model'])) ?></div>
                <div class="rv-meta"><i class="fas <?= $vehicle_icon($v['vehicle_type']) ?> me-1" aria-hidden="true"></i><?= htmlspecialchars($v['vehicle_type']) ?><?= !empty($v['year']) ? ' &middot; ' . (int)$v['year'] : '' ?></div>
                <div class="rv-specs">
                    <span><i class="fas fa-user-group" aria-hidden="true"></i><?= (int)$v['capacity'] ?> seats</span>
                    <span><i class="fas <?= $fuelIcon ?>" aria-hidden="true"></i><?= htmlspecialchars($v['fuel_type']) ?></span>
                </div>
                <?php if ($v['status'] === 'maintenance'): ?>
                <div class="rv-note"><i class="fas fa-screwdriver-wrench me-1" aria-hidden="true"></i>Under maintenance. It cannot be requested at the moment.</div>
                <?php elseif ($v['status'] === 'in_use'): ?>
                <div class="rv-note info"><i class="fas fa-circle-info me-1" aria-hidden="true"></i>In use now. You can still request it; the next step checks your date and time.</div>
                <?php endif; ?>
            </div>
            <div class="rv-foot">
                <?php if ($canPick): ?>
                <button type="button" class="btn btn-primary w-100 fw-semibold rv-toggle"
                        data-id="<?= (int)$v['vehicle_id'] ?>" data-seats="<?= (int)$v['capacity'] ?>" aria-pressed="false">
                    <span class="rv-off"><i class="fas fa-plus me-1" aria-hidden="true"></i>Add to request</span>
                    <span class="rv-on d-none"><i class="fas fa-check me-1" aria-hidden="true"></i>Added &middot; click to remove</span>
                </button>
                <?php else: ?>
                <button type="button" class="btn btn-outline-secondary w-100" disabled>Not available</button>
                <?php endif; ?>
            </div>
        </article>
        <?php endforeach; ?>
    </div>

    <!-- Selection bar -->
    <div id="rvBar" class="rv-bar" style="display:none;" role="status" aria-live="polite">
        <div>
            <strong id="rvCount">0</strong> vehicle(s) selected
            <span class="text-muted">&middot; <span id="rvSeats">0</span> seats in total</span>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="rvClear">Clear</button>
            <a href="#" class="btn btn-primary btn-sm fw-semibold" id="rvContinue">Continue <i class="fas fa-arrow-right ms-1" aria-hidden="true"></i></a>
        </div>
    </div>
    <?php else: ?>

    <!-- ── Step 2: the form, with the chosen vehicle on top ─────── -->
    <div class="rv-banner">
        <div class="rv-vlist">
            <div class="small text-muted fw-semibold text-uppercase">Step 2 of 2 &middot; Selected vehicle<?= count($chosen_vehicles) > 1 ? 's' : '' ?></div>
            <?php if ($chosen_vehicles): foreach ($chosen_vehicles as $cv): ?>
            <div class="rv-vrow">
                <img alt="" src="<?= htmlspecialchars(vehiclePhotoUrl($cv['photo'] ?? null, $cv['vehicle_type'])) ?>"
                     data-fallback="<?= htmlspecialchars(vehiclePhotoUrl(null, $cv['vehicle_type'])) ?>"
                     onerror="if(!this.dataset.failed){this.dataset.failed='1';this.src=this.dataset.fallback;}">
                <div>
                    <div class="fw-bold"><?= htmlspecialchars($cv['plate_number']) ?>
                        <span class="fw-semibold text-muted">&middot; <?= htmlspecialchars(trim($cv['brand'] . ' ' . $cv['model'])) ?></span></div>
                    <div class="small text-muted"><?= htmlspecialchars($cv['vehicle_type']) ?> &middot; <?= (int)$cv['capacity'] ?> seats
                        <span class="rv-free ms-2" data-vid="<?= (int)$cv['vehicle_id'] ?>"></span></div>
                </div>
            </div>
            <?php endforeach; else: ?>
            <div class="fw-bold"><i class="fas fa-wand-magic-sparkles me-1 text-success" aria-hidden="true"></i>Any available vehicle</div>
            <div class="small text-muted">The transport unit will choose the vehicles, and how many are needed for your passengers.</div>
            <?php endif; ?>
        </div>
        <a href="request_vehicle.php" class="btn btn-outline-secondary btn-sm ms-auto align-self-start">
            <i class="fas fa-arrow-left me-1" aria-hidden="true"></i>Change vehicle(s)
        </a>
    </div>

    <div class="row g-4">

        <!-- ── Request Form ────────────────────────────────────────── -->
        <div class="col-lg-8">
            <form method="POST" action="" enctype="multipart/form-data" id="requestForm" novalidate>
            <div class="card mb-4" style="border: none; border-radius: 14px; box-shadow: 0 2px 12px rgba(11,93,59,.10);">
                <div class="card-header bg-white border-bottom px-4 py-3" style="border-radius: 14px 14px 0 0;">
                    <h6 class="mb-0 fw-semibold">
                        <i class="fas fa-file-pen text-primary me-2"></i>Trip Details
                    </h6>
                </div>
                <div class="card-body px-4 py-4">

                        <!-- Trip Date -->
                        <div class="mb-4">
                            <label class="form-label fw-semibold" for="trip_date">
                                Trip Date <span class="text-danger">*</span>
                            </label>
                            <input type="date" class="form-control" id="trip_date" name="trip_date"
                                   value="<?= htmlspecialchars($form['trip_date']) ?>"
                                   min="<?= htmlspecialchars($min_date) ?>" required>
                            <div class="form-text mt-1">
                                <i class="fas fa-circle-info me-1" style="color: var(--uis-primary);"></i>
                                Requests must be submitted at least 3 days in advance.
                            </div>
                        </div>

                        <!-- Time Range -->
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold" for="start_time">
                                    Start Time <span class="text-danger">*</span>
                                </label>
                                <input type="time" class="form-control" id="start_time" name="start_time"
                                       value="<?= htmlspecialchars($form['start_time']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold" for="end_time">
                                    End Time <span class="text-danger">*</span>
                                </label>
                                <input type="time" class="form-control" id="end_time" name="end_time"
                                       value="<?= htmlspecialchars($form['end_time']) ?>" required>
                                <div class="form-text mt-1" id="timeHint"></div>
                            </div>
                        </div>

                        <!-- Destination -->
                        <div class="mb-4">
                            <label class="form-label fw-semibold" for="destination">
                                Destination <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control" id="destination" name="destination"
                                   value="<?= htmlspecialchars($form['destination']) ?>"
                                   maxlength="255" placeholder="e.g. Putrajaya International Convention Centre" required
                                   list="dlDestinations" autocomplete="off">
                            <datalist id="dlDestinations">
                                <?php foreach ($suggest['destination'] as $opt): ?>
                                <option value="<?= htmlspecialchars($opt) ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>

                        <!-- Purpose -->
                        <div class="mb-4">
                            <label class="form-label fw-semibold" for="purpose">
                                Purpose <span class="text-danger">*</span>
                            </label>
                            <textarea class="form-control" id="purpose" name="purpose" rows="4"
                                      minlength="20" required
                                      placeholder="Describe the purpose of this trip (minimum 20 characters)..."><?= htmlspecialchars($form['purpose']) ?></textarea>
                            <div class="form-text mt-1">
                                <span id="charCount" class="fw-semibold">0</span>
                                <span class="text-muted"> characters (minimum 20 required)</span>
                            </div>
                        </div>

                        <!-- Passengers + Vehicle -->
                        <div class="row g-3 mb-4">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold" for="passenger_count">
                                    Passengers <span class="text-danger">*</span>
                                </label>
                                <input type="number" class="form-control" id="passenger_count" name="passenger_count"
                                       value="<?= htmlspecialchars($form['passenger_count']) ?>"
                                       min="1" required>
                            </div>
                            <div class="col-md-8">
                                <label class="form-label fw-semibold">Vehicle<?= count($chosen_vehicles) > 1 ? 's' : '' ?></label>
                                <?php foreach ($chosen_vehicles as $cv): ?>
                                <input type="hidden" name="vehicle_ids[]" value="<?= (int)$cv['vehicle_id'] ?>">
                                <?php endforeach; ?>
                                <?php if ($chosen_vehicles): ?>
                                <div class="form-control bg-light" style="height:auto;">
                                    <?= htmlspecialchars(implode(', ', array_column($chosen_vehicles, 'plate_number'))) ?>
                                    <span class="text-muted">&middot; <?= array_sum(array_map('intval', array_column($chosen_vehicles, 'capacity'))) ?> seats in total</span>
                                </div>
                                <?php else: ?>
                                <input type="hidden" name="vehicles_needed" value="1">
                                <div class="form-control bg-light" style="height:auto;">
                                    <i class="fas fa-wand-magic-sparkles text-success me-1" aria-hidden="true"></i>
                                    Any available vehicle <span class="text-muted">&middot; the transport unit decides which and how many</span>
                                </div>
                                <?php endif; ?>
                                <div class="form-text mt-1" id="vehicleHint"></div>
                            </div>
                        </div>

                        <!-- Officer Name(s) -->
                        <div class="mb-4">
                            <label class="form-label fw-semibold" for="officer_name">
                                Officer Name(s) <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control" id="officer_name" name="officer_name"
                                   value="<?= htmlspecialchars($form['officer_name']) ?>"
                                   maxlength="255" placeholder="e.g. En. Faruq, En. Amir" required
                                   list="dlOfficerNames" autocomplete="off">
                            <datalist id="dlOfficerNames">
                                <?php foreach ($suggest['officer_name'] as $opt): ?>
                                <option value="<?= htmlspecialchars($opt) ?>">
                                <?php endforeach; ?>
                            </datalist>
                            <div class="form-text mt-1">Person(s) the driver will serve; separate names with commas</div>
                        </div>

                        <!-- Officer Phone + Waiting Place -->
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold" for="officer_phone">
                                    Officer Phone No. <span class="text-danger">*</span>
                                </label>
                                <input type="tel" class="form-control" id="officer_phone" name="officer_phone"
                                       value="<?= htmlspecialchars($form['officer_phone']) ?>"
                                       maxlength="50" placeholder="e.g. 011-2835 4792" required
                                       list="dlOfficerPhones" autocomplete="off">
                                <datalist id="dlOfficerPhones">
                                    <?php foreach ($suggest['officer_phone'] as $opt): ?>
                                    <option value="<?= htmlspecialchars($opt) ?>">
                                    <?php endforeach; ?>
                                </datalist>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold" for="waiting_place">
                                    Waiting Place <span class="text-muted fw-normal">(optional)</span>
                                </label>
                                <input type="text" class="form-control" id="waiting_place" name="waiting_place"
                                       value="<?= htmlspecialchars($form['waiting_place']) ?>"
                                       maxlength="150" placeholder="e.g. Stor UIS"
                                       list="dlWaitingPlaces" autocomplete="off">
                                <datalist id="dlWaitingPlaces">
                                    <?php foreach ($suggest['waiting_place'] as $opt): ?>
                                    <option value="<?= htmlspecialchars($opt) ?>">
                                    <?php endforeach; ?>
                                </datalist>
                            </div>
                        </div>

                </div>
            </div>

            <!-- Supporting Documents -->
            <div class="card mb-4" style="border: none; border-radius: 14px; box-shadow: 0 2px 12px rgba(11,93,59,.10);">
                <div class="card-header bg-white border-bottom px-4 py-3" style="border-radius: 14px 14px 0 0;">
                    <h6 class="mb-0 fw-semibold">
                        <i class="fas fa-paperclip text-primary me-2"></i>Supporting Documents
                        <?php if (REQUIRE_SUPPORTING_DOCUMENT): ?>
                            <span class="text-danger">*</span>
                        <?php else: ?>
                            <span class="text-muted fw-normal small">(optional)</span>
                        <?php endif; ?>
                    </h6>
                </div>
                <div class="card-body px-4 py-4">

                    <?php if (!empty($errors) && $had_files): ?>
                    <div class="alert alert-warning py-2 px-3 mb-3" style="font-size:0.85rem;border-radius:8px;">
                        <i class="fas fa-paperclip me-1"></i>Please re-attach your documents.
                    </div>
                    <?php endif; ?>

                    <div class="form-text mb-3 mt-0">
                        <i class="fas fa-circle-info me-1" style="color: var(--uis-primary);"></i>
                        Attach any letters needed for approval &mdash; e.g. Release Letter (Surat Pelepasan) or Seminar Letter.
                        PDF, JPG, PNG or WebP &middot; max 5 MB per file &middot; up to 5 files per request. A PDF can have any number of pages.
                    </div>

                    <div id="docRows">
                        <div class="doc-row border rounded-3 p-3 mb-2">
                            <div class="row g-2 align-items-start">
                                <div class="col-md-5">
                                    <select class="form-select doc-type" name="doc_type[]" aria-label="Document type">
                                        <?php foreach ($doc_types as $key => $label): ?>
                                        <option value="<?= htmlspecialchars((string)$key) ?>"<?= $key === 'release_letter' ? ' selected' : '' ?>><?= htmlspecialchars((string)$label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col">
                                    <input type="file" class="form-control doc-file" name="docs[]"
                                           accept="application/pdf,image/jpeg,image/png,image/webp"
                                           aria-label="Document file">
                                </div>
                                <div class="col-auto">
                                    <button type="button" class="btn btn-outline-danger doc-remove" title="Remove document" aria-label="Remove document">
                                        <i class="fas fa-xmark"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="form-text mt-1 doc-info"></div>
                        </div>
                    </div>

                    <div id="docFormError" class="text-danger fw-semibold small mt-2" role="alert" style="display:none;"></div>

                    <button type="button" class="btn btn-outline-primary btn-sm mt-2" id="addDocBtn">
                        <i class="fas fa-plus me-1"></i>Add another document
                    </button>
                </div>
            </div>

            <!-- Actions -->
            <div class="d-flex gap-2 justify-content-end flex-wrap">
                <a href="my_requests.php" class="btn btn-outline-secondary px-4">
                    <i class="fas fa-xmark me-1"></i>Cancel
                </a>
                <button type="submit" class="btn btn-primary px-4 fw-semibold">
                    <i class="fas fa-paper-plane me-2"></i>Submit Request
                </button>
            </div>
            </form>
        </div>

        <!-- ── How It Works Info Box ───────────────────────────────── -->
        <div class="col-lg-4">
            <div class="card" style="border: none; border-radius: 14px; box-shadow: 0 2px 12px rgba(11,93,59,.10);">
                <div class="card-header bg-white border-bottom px-4 py-3" style="border-radius: 14px 14px 0 0;">
                    <h6 class="mb-0 fw-semibold">
                        <i class="fas fa-circle-info text-info me-2"></i>How It Works
                    </h6>
                </div>
                <div class="card-body px-4 py-3">

                    <!-- Step 1 -->
                    <div class="d-flex gap-3 mb-3 pb-3 border-bottom">
                        <div class="flex-shrink-0">
                            <span class="rounded-circle fw-bold text-white"
                                  style="width:36px;height:36px;display:flex;align-items:center;justify-content:center;background:var(--uis-primary);">
                                1
                            </span>
                        </div>
                        <div>
                            <div class="fw-semibold small mb-1">Submit Request</div>
                            <div class="text-muted" style="font-size:0.82rem;">
                                Fill in your trip details at least 3 days before the trip date,
                                as required by UIS transport policy, and attach any supporting
                                letters (e.g. Release Letter or Seminar Letter).
                            </div>
                        </div>
                    </div>

                    <!-- Step 2 -->
                    <div class="d-flex gap-3 mb-3 pb-3 border-bottom">
                        <div class="flex-shrink-0">
                            <span class="rounded-circle fw-bold text-white"
                                  style="width:36px;height:36px;display:flex;align-items:center;justify-content:center;background:var(--uis-primary);">
                                2
                            </span>
                        </div>
                        <div>
                            <div class="fw-semibold small mb-1">Head of Section Approval</div>
                            <div class="text-muted" style="font-size:0.82rem;">
                                Your Head of Section reviews and approves or rejects the request.
                                You will be notified of the outcome.
                            </div>
                        </div>
                    </div>

                    <!-- Step 3 -->
                    <div class="d-flex gap-3 mb-3">
                        <div class="flex-shrink-0">
                            <span class="rounded-circle fw-bold text-white"
                                  style="width:36px;height:36px;display:flex;align-items:center;justify-content:center;background:var(--uis-primary);">
                                3
                            </span>
                        </div>
                        <div>
                            <div class="fw-semibold small mb-1">Driver &amp; Vehicle Assigned</div>
                            <div class="text-muted" style="font-size:0.82rem;">
                                Once approved, the transport unit assigns a driver and vehicle,
                                and your trip is added to the official schedule.
                            </div>
                        </div>
                    </div>

                    <hr class="my-3">
                    <div class="alert alert-warning mb-0 py-2 px-3" style="font-size:0.82rem;border-radius:8px;">
                        <i class="fas fa-triangle-exclamation me-1"></i>
                        Choosing a specific vehicle is optional &mdash; select
                        "Any available vehicle" to let the transport unit decide.
                    </div>
                </div>
            </div>
        </div>

    </div>
    <?php endif; ?>
</main>

<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<!-- Bootstrap 5.3 JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- Custom JS -->
<script src="<?= SITE_URL ?>/assets/js/main.js"></script>
<?php if ($show_picker): ?>
<script>
(function () {
    var picked = {};                                   // vehicle id -> seats
    var bar = document.getElementById('rvBar');
    function refresh() {
        var ids = Object.keys(picked), seats = 0;
        ids.forEach(function (id) { seats += picked[id]; });
        document.getElementById('rvCount').textContent = ids.length;
        document.getElementById('rvSeats').textContent = seats;
        document.getElementById('rvContinue').href = 'request_vehicle.php?vehicles=' + ids.join(',');
        bar.style.display = ids.length ? '' : 'none';
    }
    document.querySelectorAll('.rv-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-id'), card = btn.closest('.rv-card');
            if (picked[id] !== undefined) {
                delete picked[id];
            } else {
                if (Object.keys(picked).length >= 5) { UIS.alert('You can request up to 5 vehicles at a time.', { title: 'Limit reached', tone: 'warning' }); return; }
                picked[id] = parseInt(btn.getAttribute('data-seats'), 10) || 0;
            }
            var on = picked[id] !== undefined;
            card.classList.toggle('picked', on);
            btn.classList.toggle('btn-primary', !on);
            btn.classList.toggle('btn-success', on);
            btn.setAttribute('aria-pressed', on ? 'true' : 'false');
            btn.querySelector('.rv-off').classList.toggle('d-none', on);
            btn.querySelector('.rv-on').classList.toggle('d-none', !on);
            refresh();
        });
    });
    document.getElementById('rvClear').addEventListener('click', function () {
        Object.keys(picked).forEach(function (id) {
            var btn = document.querySelector('.rv-toggle[data-id="' + id + '"]'); if (btn) { btn.click(); }
        });
    });
})();
document.querySelectorAll('.rv-chip').forEach(function (chip) {
    chip.addEventListener('click', function () {
        document.querySelectorAll('.rv-chip').forEach(function (c) { c.classList.remove('active'); });
        chip.classList.add('active');
        var t = chip.getAttribute('data-type');
        document.querySelectorAll('.rv-card').forEach(function (card) {
            card.style.display = (!t || card.getAttribute('data-type') === t) ? '' : 'none';
        });
    });
});
</script>
<?php else: ?>
<script>
(function () {
    'use strict';

    var dateInput   = document.getElementById('trip_date');
    var startInput  = document.getElementById('start_time');
    var endInput    = document.getElementById('end_time');
    var timeHint    = document.getElementById('timeHint');
    var purposeEl   = document.getElementById('purpose');
    var charCount   = document.getElementById('charCount');
    var vehicleHint = document.getElementById('vehicleHint');

    var AJAX_URL = '<?= SITE_URL ?>/ajax/get_free_vehicles.php';

    function updateCharCount() {
        var len = purposeEl.value.length;
        charCount.textContent = len;
        charCount.className   = len >= 20 ? 'fw-semibold text-success' : 'fw-semibold text-danger';
    }

    var CHOSEN_IDS = <?= json_encode(array_map('intval', array_column($chosen_vehicles, 'vehicle_id'))) ?>;
    var CHECK_URL  = '<?= SITE_URL ?>/ajax/check_vehicles_free.php';
    var FREE_URL   = '<?= SITE_URL ?>/ajax/get_free_vehicles.php';

    function setHint(text, tone) {
        vehicleHint.textContent = text || '';
        vehicleHint.className = 'form-text mt-1' + (tone ? ' text-' + tone + ' fw-semibold' : '');
    }
    function clearFreeMarks() {
        document.querySelectorAll('.rv-free').forEach(function (el) { el.textContent = ''; el.className = 'rv-free ms-2'; });
    }

    // Once date and time are known: tell the staff member whether the chosen
    // vehicles are free, and whether their seats cover the passengers.
    function loadVehicles() {
        var d = dateInput.value, s = startInput.value, e = endInput.value;
        timeHint.textContent = '';
        timeHint.className   = 'form-text mt-1';
        clearFreeMarks();
        setHint('');

        if (!d || !s || !e) { return; }
        if (e <= s) {
            timeHint.textContent = 'End time must be after the start time.';
            timeHint.className   = 'form-text mt-1 text-danger fw-semibold';
            return;
        }
        var pax = parseInt(document.getElementById('passenger_count').value, 10) || 1;

        if (CHOSEN_IDS.length) {
            var q = new URLSearchParams({ trip_date: d, start_time: s, end_time: e, ids: CHOSEN_IDS.join(',') });
            fetch(CHECK_URL + '?' + q.toString(), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.success) { return; }
                    var bad = [];
                    data.vehicles.forEach(function (v) {
                        var mark = document.querySelector('.rv-free[data-vid="' + v.vehicle_id + '"]');
                        if (mark) {
                            mark.textContent = v.free ? '✓ free at this time' : '✗ ' + v.reason;
                            mark.className = 'rv-free ms-2 fw-semibold text-' + (v.free ? 'success' : 'danger');
                        }
                        if (!v.free) { bad.push(v.plate_number); }
                    });
                    if (bad.length) {
                        setHint('Not free: ' + bad.join(', ') + '. Change the date or time, or choose other vehicles.', 'danger');
                    } else if (data.free_seats < pax) {
                        setHint('The vehicles seat ' + data.free_seats + ' in total but you have ' + pax + ' passengers. Add another vehicle or reduce the passengers.', 'warning');
                    } else {
                        setHint('All chosen vehicles are free at this time and seat ' + data.free_seats + ' in total.', 'success');
                    }
                })
                .catch(function () { /* the server checks again on submit */ });
        } else {
            setHint('The transport unit will choose suitable vehicles for ' + pax + ' passenger(s), and decide how many are needed.', 'success');
        }
    }

    dateInput.addEventListener('change',  loadVehicles);
    startInput.addEventListener('change', loadVehicles);
    endInput.addEventListener('change',   loadVehicles);
    document.getElementById('passenger_count').addEventListener('change', loadVehicles);
    purposeEl.addEventListener('input',   updateCharCount);

    // ── Supporting documents ─────────────────────────────────────
    var MAX_DOCS       = <?= (int)MAX_SUPPORTING_DOCUMENTS ?>;
    var MAX_BYTES      = <?= (int)MAX_DOCUMENT_BYTES ?>;
    var DOC_REQUIRED   = <?= REQUIRE_SUPPORTING_DOCUMENT ? 'true' : 'false' ?>;
    var ALLOWED_MIME   = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
    var ALLOWED_EXT    = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

    var form        = document.getElementById('requestForm');
    var docRows     = document.getElementById('docRows');
    var addDocBtn   = document.getElementById('addDocBtn');
    var docFormErr  = document.getElementById('docFormError');
    var rowTemplate = docRows.querySelector('.doc-row').cloneNode(true);

    function formatSize(bytes) {
        if (bytes < 1024) { return bytes + ' B'; }
        if (bytes < 1048576) { return (bytes / 1024).toFixed(1) + ' KB'; }
        return (bytes / 1048576).toFixed(2) + ' MB';
    }

    function isAllowedType(file) {
        if (file.type) { return ALLOWED_MIME.indexOf(file.type) !== -1; }
        var ext = (file.name.split('.').pop() || '').toLowerCase();
        return ALLOWED_EXT.indexOf(ext) !== -1;
    }

    function setInfo(row, text, cls) {
        var info = row.querySelector('.doc-info');
        info.textContent = text;
        info.className   = 'form-text mt-1 doc-info' + (cls ? ' ' + cls : '');
    }

    // Returns true when the row's chosen file (if any) is acceptable
    function checkRow(row) {
        var input = row.querySelector('.doc-file');
        if (!input.files || input.files.length === 0) {
            setInfo(row, '', '');
            return true;
        }
        var f = input.files[0];
        if (!isAllowedType(f)) {
            setInfo(row, f.name + ' — unsupported file type. Use PDF, JPG, PNG or WebP.', 'text-danger fw-semibold');
            return false;
        }
        if (f.size > MAX_BYTES) {
            setInfo(row, f.name + ' (' + formatSize(f.size) + ') — too large. Maximum size is 5 MB.', 'text-danger fw-semibold');
            return false;
        }
        setInfo(row, f.name + ' (' + formatSize(f.size) + ')', 'text-success');
        return true;
    }

    function rowCount() {
        return docRows.querySelectorAll('.doc-row').length;
    }

    function refreshAddButton() {
        addDocBtn.disabled = rowCount() >= MAX_DOCS;
    }

    function showDocError(msg) {
        docFormErr.textContent   = msg;
        docFormErr.style.display = msg ? '' : 'none';
    }

    // Pick the first document type not yet used by another row
    function nextFreeType(selectEl) {
        var used = {};
        Array.prototype.forEach.call(docRows.querySelectorAll('.doc-type'), function (s) {
            used[s.value] = true;
        });
        var chosen = 'other';
        Array.prototype.some.call(selectEl.options, function (o) {
            if (o.value !== 'other' && !used[o.value]) { chosen = o.value; return true; }
            return false;
        });
        return chosen;
    }

    function addRow() {
        if (rowCount() >= MAX_DOCS) { return; }
        var row = rowTemplate.cloneNode(true);
        row.querySelector('.doc-file').value = '';
        setInfo(row, '', '');
        docRows.appendChild(row);
        var sel = row.querySelector('.doc-type');
        sel.value = nextFreeType(sel);
        refreshAddButton();
        row.querySelector('.doc-file').focus();
    }

    addDocBtn.addEventListener('click', addRow);

    docRows.addEventListener('click', function (ev) {
        var btn = ev.target.closest('.doc-remove');
        if (!btn) { return; }
        var row = btn.closest('.doc-row');
        if (rowCount() > 1) {
            row.parentNode.removeChild(row);
        } else {
            // Last row: clear it instead of removing it
            row.querySelector('.doc-file').value = '';
            setInfo(row, '', '');
        }
        showDocError('');
        refreshAddButton();
    });

    docRows.addEventListener('change', function (ev) {
        if (ev.target.classList.contains('doc-file')) {
            checkRow(ev.target.closest('.doc-row'));
            showDocError('');
        }
    });

    form.addEventListener('submit', function (ev) {
        var rows = docRows.querySelectorAll('.doc-row');
        var allOk = true, anyFile = false;
        Array.prototype.forEach.call(rows, function (row) {
            if (row.querySelector('.doc-file').files.length > 0) { anyFile = true; }
            if (!checkRow(row)) { allOk = false; }
        });
        if (!allOk) {
            ev.preventDefault();
            showDocError('Please fix or remove the highlighted documents before submitting.');
            docFormErr.scrollIntoView({ behavior: 'smooth', block: 'center' });
        } else if (DOC_REQUIRED && !anyFile) {
            ev.preventDefault();
            showDocError('Please attach at least one supporting document.');
            docFormErr.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    });

    refreshAddButton();

    updateCharCount();
    loadVehicles();

    // ── Reuse previous answers: officer name -> phone / waiting place ──
    var OFFICER_MAP = <?= json_encode(
        $officer_map,
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
    ) ?>;
    var officerNameEl  = document.getElementById('officer_name');
    var officerPhoneEl = document.getElementById('officer_phone');
    var waitingEl      = document.getElementById('waiting_place');

    function fillFromOfficer() {
        var typed = officerNameEl.value.trim().toLowerCase();
        if (!typed) return;
        var match = null;
        Object.keys(OFFICER_MAP).forEach(function (name) {
            if (match === null && name.trim().toLowerCase() === typed) match = OFFICER_MAP[name];
        });
        if (!match) return;
        if (officerPhoneEl.value.trim() === '' && match.phone) officerPhoneEl.value = match.phone;
        if (waitingEl.value.trim() === ''      && match.place) waitingEl.value      = match.place;
    }
    officerNameEl.addEventListener('change', fillFromOfficer);
    officerNameEl.addEventListener('input',  fillFromOfficer);
})();
</script>
<?php endif; ?>
</body>
</html>
