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
    'vehicle_id'      => '',
    'officer_name'    => $full_name,
    'officer_phone'   => '',
    'waiting_place'   => '',
];
$had_files = false; // true when the failed POST contained chosen files

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $trip_date       = trim($_POST['trip_date']       ?? '');
    $start_time      = trim($_POST['start_time']      ?? '');
    $end_time        = trim($_POST['end_time']        ?? '');
    $destination     = trim($_POST['destination']     ?? '');
    $purpose         = trim($_POST['purpose']         ?? '');
    $passenger_count = (int)($_POST['passenger_count'] ?? 0);
    $vehicle_id_raw  = trim($_POST['vehicle_id']      ?? '');
    $vehicle_id      = $vehicle_id_raw !== '' ? (int)$vehicle_id_raw : null;
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
        'vehicle_id'      => $vehicle_id_raw,
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

    // If a specific vehicle was chosen, re-verify it is actually free for
    // that slot AND has enough seats for the passenger count
    if (empty($errors) && $vehicle_id !== null) {
        $stmt = $conn->prepare(
            "SELECT vehicle_id, capacity FROM vehicles
             WHERE vehicle_id = ?
               AND status = 'available'
               AND capacity >= ?
               AND vehicle_id NOT IN (
                     SELECT vehicle_id FROM schedules
                     WHERE trip_date = ?
                       AND status NOT IN ('cancelled','completed')
                       AND vehicle_id IS NOT NULL
                       AND (start_time < ? AND end_time > ?)
               )"
        );
        $stmt->bind_param('iisss', $vehicle_id, $passenger_count, $trip_date, $end_time, $start_time);
        $stmt->execute();
        $free = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($free === null) {
            $errors[] = 'The selected vehicle is no longer available for that date/time or cannot fit your passenger count. Please choose another vehicle.';
            $form['vehicle_id'] = '';
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
                      officer_name, officer_phone, waiting_place)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            if (!$stmt) {
                throw new RuntimeException('Could not prepare the request insert.');
            }
            $stmt->bind_param(
                'iisssssiisss',
                $staff_id, $vehicle_id, $trip_date, $start_time, $end_time,
                $destination, $purpose, $passenger_count, $supervisor_id,
                $officer_name, $officer_phone, $waiting_place_db
            );
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('Could not save the request.');
            }
            $new_request_id = (int)$stmt->insert_id;
            $stmt->close();

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
                                <label class="form-label fw-semibold" for="vehicle_id">
                                    Vehicle <span class="text-muted fw-normal">(optional)</span>
                                </label>
                                <select class="form-select" id="vehicle_id" name="vehicle_id" disabled
                                        data-selected="<?= htmlspecialchars($form['vehicle_id']) ?>">
                                    <option value="">Select date &amp; time first</option>
                                </select>
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
            <div class="d-flex gap-2 flex-wrap">
                <button type="submit" class="btn btn-primary px-4 fw-semibold">
                    <i class="fas fa-paper-plane me-2"></i>Submit Request
                </button>
                <a href="my_requests.php" class="btn btn-outline-secondary px-4">
                    <i class="fas fa-xmark me-1"></i>Cancel
                </a>
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

    var dateInput   = document.getElementById('trip_date');
    var startInput  = document.getElementById('start_time');
    var endInput    = document.getElementById('end_time');
    var timeHint    = document.getElementById('timeHint');
    var purposeEl   = document.getElementById('purpose');
    var charCount   = document.getElementById('charCount');
    var vehicleSel  = document.getElementById('vehicle_id');
    var vehicleHint = document.getElementById('vehicleHint');

    var AJAX_URL = '<?= SITE_URL ?>/ajax/get_free_vehicles.php';

    function updateCharCount() {
        var len = purposeEl.value.length;
        charCount.textContent = len;
        charCount.className   = len >= 20 ? 'fw-semibold text-success' : 'fw-semibold text-danger';
    }

    function resetVehicleSelect(placeholder) {
        vehicleSel.innerHTML = '';
        var opt = document.createElement('option');
        opt.value = '';
        opt.textContent = placeholder;
        vehicleSel.appendChild(opt);
        vehicleSel.disabled = true;
    }

    function loadVehicles() {
        var d = dateInput.value;
        var s = startInput.value;
        var e = endInput.value;

        timeHint.textContent = '';
        timeHint.className   = 'form-text mt-1';

        if (!d || !s || !e) {
            resetVehicleSelect('Select date & time first');
            vehicleHint.textContent = '';
            return;
        }

        if (e <= s) {
            timeHint.textContent = 'End time must be after the start time.';
            timeHint.className   = 'form-text mt-1 text-danger fw-semibold';
            resetVehicleSelect('Select date & time first');
            vehicleHint.textContent = '';
            return;
        }

        resetVehicleSelect('Loading available vehicles...');
        vehicleHint.textContent = '';

        var pax = parseInt(document.getElementById('passenger_count').value, 10) || 1;
        var params = new URLSearchParams({ trip_date: d, start_time: s, end_time: e, passengers: pax });

        fetch(AJAX_URL + '?' + params.toString())
            .then(function (res) { return res.json(); })
            .then(function (data) {
                vehicleSel.innerHTML = '';

                var anyOpt = document.createElement('option');
                anyOpt.value = '';
                anyOpt.textContent = 'Any available vehicle (let admin decide)';
                vehicleSel.appendChild(anyOpt);

                if (data.success && data.vehicles.length > 0) {
                    data.vehicles.forEach(function (v) {
                        var opt = document.createElement('option');
                        opt.value = v.vehicle_id;
                        var name = [v.brand, v.model].filter(Boolean).join(' ') || v.vehicle_type;
                        opt.textContent = v.plate_number + ' — ' + name +
                            ' (' + v.vehicle_type + ', ' + v.capacity + ' seats)';
                        vehicleSel.appendChild(opt);
                    });
                    vehicleHint.textContent = data.vehicles.length + ' vehicle' +
                        (data.vehicles.length !== 1 ? 's' : '') + ' free with enough seats for this slot.';
                    vehicleHint.className = 'form-text mt-1 text-success fw-semibold';
                } else if (data.success) {
                    vehicleHint.textContent = 'No vehicle with enough seats is free for this slot. Reduce passengers, change the time, or submit with "Any available vehicle".';
                    vehicleHint.className = 'form-text mt-1 text-warning fw-semibold';
                } else {
                    vehicleHint.textContent = data.message || 'Could not load vehicles.';
                    vehicleHint.className = 'form-text mt-1 text-danger fw-semibold';
                }

                vehicleSel.disabled = false;

                // Restore previous selection after a failed POST, if still free
                var prev = vehicleSel.getAttribute('data-selected');
                if (prev) {
                    vehicleSel.value = prev;
                    if (vehicleSel.value !== prev) {
                        vehicleSel.value = '';
                    }
                }
            })
            .catch(function () {
                resetVehicleSelect('Select date & time first');
                vehicleHint.textContent = 'Could not load vehicles. Please try again.';
                vehicleHint.className   = 'form-text mt-1 text-danger fw-semibold';
            });
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
</body>
</html>
