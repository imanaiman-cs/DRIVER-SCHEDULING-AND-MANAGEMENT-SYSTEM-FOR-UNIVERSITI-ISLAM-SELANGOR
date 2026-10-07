<?php
// ============================================================
// UIS Driver Scheduling and Management System
// includes/task_sheet.php  –  "Tugasan Pemandu" printable sheet
// Universiti Islam Selangor (UIS)
//
// Shared by admin/task_sheet.php and driver/task_sheet.php.
// renderTaskSheet() echoes the sheet body only (no <html>/<head>).
// ============================================================

/**
 * Formats a MySQL TIME string as Malay 12-hour time.
 * Example: '14:00:00' → '2.00 PETANG', '10:00:00' → '10.00 PAGI'
 *
 * PAGI before 12:00, TENGAHARI 12:00–13:59, PETANG 14:00–18:59,
 * MALAM from 19:00 onward.
 *
 * @param string|null $time Time string (H:i or H:i:s)
 *
 * @return string Formatted time, or '' when the input is empty/invalid
 */
function formatMalayTime(?string $time): string
{
    if ($time === null || trim($time) === '') {
        return '';
    }
    if (!preg_match('/^(\d{1,2}):(\d{2})/', trim($time), $m)) {
        return '';
    }

    $hour24 = (int)$m[1];
    $minute = $m[2];
    if ($hour24 > 23 || (int)$minute > 59) {
        return '';
    }

    $hour12 = $hour24 % 12;
    if ($hour12 === 0) {
        $hour12 = 12;
    }

    if ($hour24 < 12) {
        $period = 'PAGI';
    } elseif ($hour24 < 14) {
        $period = 'TENGAHARI';
    } elseif ($hour24 < 19) {
        $period = 'PETANG';
    } else {
        $period = 'MALAM';
    }

    return $hour12 . '.' . $minute . ' ' . $period;
}

/**
 * Translates a vehicle_type enum value to its Malay equivalent.
 *
 * @param string|null $type Lorry, Bus, Van, Car, Minibus or Motorcycle
 *
 * @return string Malay name (unknown types are returned unchanged)
 */
function vehicleTypeMalay(?string $type): string
{
    $type = trim((string)$type);
    $map  = [
        'lorry'      => 'LORI',
        'bus'        => 'BAS',
        'van'        => 'VAN',
        'car'        => 'KERETA',
        'minibus'    => 'MINIBAS',
        'motorcycle' => 'MOTOSIKAL',
    ];
    return $map[strtolower($type)] ?? $type;
}

/**
 * Uppercases and HTML-escapes a value for the task sheet,
 * falling back to an em dash when empty.
 *
 * @param string|null $value Raw value
 *
 * @return string Escaped HTML string
 */
function taskSheetValue(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '—';
    }
    return htmlspecialchars(mb_strtoupper($value, 'UTF-8'), ENT_QUOTES, 'UTF-8');
}

/**
 * Echoes the "Tugasan Pemandu" sheet body for one schedule.
 *
 * @param array $s Keys: driver_name, trip_date, start_time, end_time,
 *                 vehicle_plate, vehicle_brand, vehicle_model, vehicle_type,
 *                 destination, waiting_place, purpose, officer_name,
 *                 officer_phone, schedule_id
 *
 * @return void
 */
function renderTaskSheet(array $s): void
{
    // Date: j.n.Y (no leading zeros), e.g. 6.10.2026
    $date = '';
    $tripDate = (string)($s['trip_date'] ?? '');
    if ($tripDate !== '' && $tripDate !== '0000-00-00' && ($ts = strtotime($tripDate)) !== false) {
        $date = date('j.n.Y', $ts);
    }

    // Time range: "10.00 PAGI - 2.00 PETANG"
    $start = formatMalayTime($s['start_time'] ?? null);
    $end   = formatMalayTime($s['end_time'] ?? null);
    if ($start !== '' && $end !== '') {
        $time = $start . ' - ' . $end;
    } else {
        $time = $start !== '' ? $start : $end;
    }

    // Vehicle: "LORI DAIHATSU - BHW 8595"
    $plate   = trim((string)($s['vehicle_plate'] ?? ''));
    $vehicle = trim(vehicleTypeMalay($s['vehicle_type'] ?? '') . ' ' . trim((string)($s['vehicle_brand'] ?? '')));
    if ($vehicle !== '' && $plate !== '') {
        $vehicle .= ' - ' . $plate;
    } elseif ($plate !== '') {
        $vehicle = $plate;
    }

    $rows = [
        'Nama Pemandu'    => taskSheetValue($s['driver_name'] ?? ''),
        'Tarikh Tugasan'  => taskSheetValue($date),
        'Masa'            => taskSheetValue($time),
        'Kenderaan'       => taskSheetValue($vehicle),
        'Lokasi'          => taskSheetValue($s['destination'] ?? ''),
        'Tempat Menunggu' => taskSheetValue($s['waiting_place'] ?? ''),
        'Tujuan'          => taskSheetValue($s['purpose'] ?? ''),
        'Pegawai'         => taskSheetValue($s['officer_name'] ?? ''),
        'No. Tel'         => taskSheetValue($s['officer_phone'] ?? ''),
    ];

    $logo       = htmlspecialchars(SITE_URL . '/assets/images/uis-logo.png', ENT_QUOTES, 'UTF-8');
    $scheduleId = (int)($s['schedule_id'] ?? 0);
    $printed    = date('j.n.Y');
    ?>
    <style>
        .task-sheet { color: #111; font-family: "Segoe UI", Arial, Helvetica, sans-serif; }
        .task-sheet .ts-header { text-align: center; margin-bottom: 1.5rem; }
        .task-sheet .ts-header img { height: 80px; width: auto; margin-bottom: .5rem; }
        .task-sheet .ts-uni { font-size: 1.05rem; font-weight: 700; letter-spacing: .08em; color: #0b5d3b; margin: 0; }
        .task-sheet .ts-title {
            display: inline-block; font-size: 1.6rem; font-weight: 800; letter-spacing: .06em;
            margin: .9rem 0 0; padding-bottom: .3rem; border-bottom: 3px solid #0b5d3b;
        }
        .task-sheet table.ts-table { width: 100%; border-collapse: collapse; margin: 1.5rem 0; }
        .task-sheet .ts-table th, .task-sheet .ts-table td {
            padding: .45rem .4rem; vertical-align: top; font-size: 1.02rem; text-align: left;
        }
        .task-sheet .ts-table th { width: 30%; font-weight: 600; white-space: nowrap; }
        .task-sheet .ts-table td.ts-colon { width: 1.5rem; text-align: center; font-weight: 600; }
        .task-sheet .ts-table td.ts-value { font-weight: 700; color: #0b5d3b; }
        .task-sheet .ts-notice { font-style: italic; margin: 2rem 0 1.5rem; line-height: 1.6; font-size: .98rem; }
        .task-sheet .ts-footer {
            border-top: 1px solid #ccc; padding-top: .5rem; font-size: .75rem; color: #666; text-align: right;
        }
        @media print {
            .task-sheet .ts-table td.ts-value { color: #000; }
        }
    </style>
    <div class="task-sheet">
        <div class="ts-header">
            <img alt="" src="<?php echo $logo; ?>" alt="Logo UIS">
            <p class="ts-uni">UNIVERSITI ISLAM SELANGOR</p>
            <h1 class="ts-title">TUGASAN PEMANDU</h1>
        </div>

        <table class="ts-table">
            <tbody>
            <?php foreach ($rows as $label => $value): ?>
                <tr>
                    <th scope="row"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></th>
                    <td class="ts-colon">:</td>
                    <td class="ts-value"><?php echo $value; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <p class="ts-notice">
            Sekiranya Pegawai lewat daripada masa ditetapkan, Pemandu adalah dipohon untuk menghubungi Pegawai berkaitan untuk tindakan selanjutnya.
        </p>

        <div class="ts-footer">
            Rujukan: Jadual #<?php echo $scheduleId; ?> &middot; Dicetak pada <?php echo htmlspecialchars($printed, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    </div>
    <?php
}
