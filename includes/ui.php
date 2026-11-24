<?php
// ============================================================
// includes/ui.php  -  small pieces of HTML shared by the pages
//   page header, form fields, pager, tabs, request details (UC017), status history
// ============================================================
require_once __DIR__ . '/admin_page.php';

/** The page title row: icon, title, a short line, and buttons on the right. */
function pageHeader(string $title, string $icon, string $sub = '', string $actions = ''): void
{
    echo '<div class="mb-5 flex flex-wrap items-end justify-between gap-3"><div><h1 class="text-2xl font-extrabold tracking-tight"><i class="fas fa-' . h($icon) . ' me-2 text-[0.8em] text-primary" aria-hidden="true"></i>'
       . h($title) . '</h1>' . ($sub !== '' ? '<p class="mt-1 text-sm text-base-content/65">' . h($sub) . '</p>' : '') . '</div>'
       . ($actions !== '' ? '<div class="flex flex-wrap gap-2">' . $actions . '</div>' : '') . '</div>';
}

/** The error text under a field. */
function fieldError(array $errors, string $key): string
{
    return isset($errors[$key]) ? '<div class="mt-1 text-xs text-error">' . h($errors[$key]) . '</div>' : '';
}

/** class 'input-error' etc. when the field has an error. */
function errClass(array $errors, string $key, string $kind = 'input'): string
{
    return isset($errors[$key]) ? " $kind-error" : '';
}

/** Tabs as links: [key => label]; $counts optional [key => n]. */
function tabsNav(array $tabs, string $current, callable $url, array $counts = []): string
{
    $h = '<nav class="tabs tabs-border mb-5 flex-nowrap overflow-x-auto" aria-label="Views">';
    foreach ($tabs as $k => $label) {
        $on = $k === $current;
        $h .= '<a class="tab gap-2 whitespace-nowrap' . ($on ? ' tab-active' : '') . '"' . ($on ? ' aria-current="page"' : '') . ' href="' . h($url($k)) . '">' . h($label)
            . (isset($counts[$k]) && $counts[$k] > 0 ? ' <span class="badge badge-sm badge-ghost">' . (int)$counts[$k] . '</span>' : '') . '</a>';
    }
    return $h . '</nav>';
}

/** Page links for paginate(). $url(page) builds the address. */
function pagerHtml(array $pg, callable $url): string
{
    if ($pg['total_pages'] <= 1) { return ''; }
    $h = '<nav class="join mt-4" aria-label="Pages">';
    for ($i = 1; $i <= $pg['total_pages']; $i++) {
        $on = $i === $pg['current_page'];
        $h .= '<a class="join-item btn btn-sm' . ($on ? ' btn-active' : '') . '" href="' . h($url($i)) . '"' . ($on ? ' aria-current="page"' : '') . '>' . $i . '</a>';
    }
    return $h . '</nav>';
}

/** "No record is found." row / box. */
function emptyRow(int $cols, string $text = 'No record is found.'): string
{
    return '<tr><td colspan="' . $cols . '" class="py-8 text-center text-base-content/60"><i class="fas fa-inbox me-1.5" aria-hidden="true"></i>' . h($text) . '</td></tr>';
}

/** A label/value row in a details list. */
function dlRow(string $label, string $valueHtml): string
{
    return '<div class="grid grid-cols-3 gap-3 border-b border-base-200 py-2 text-sm last:border-0"><dt class="text-base-content/65">' . h($label) . '</dt><dd class="col-span-2 break-words">' . $valueHtml . '</dd></div>';
}

/** A card with a title. */
function cardStart(string $title = '', string $icon = '', string $extra = ''): void
{
    echo '<section class="card mb-5 border border-base-300 bg-base-100 ' . h($extra) . '"><div class="card-body">';
    if ($title !== '') { echo '<h2 class="mb-2 flex items-center gap-2 text-base font-bold">' . ($icon ? '<i class="fas fa-' . h($icon) . ' text-primary" aria-hidden="true"></i>' : '') . h($title) . '</h2>'; }
}

function cardEnd(): void
{
    echo '</div></section>';
}

/** The status history (UC017 3.1): status, reason, who, date and time. */
function historyHtml(array $rows): string
{
    if (!$rows) { return '<p class="text-sm text-base-content/60">No history yet.</p>'; }
    $h = '<ol class="relative ms-2 border-s border-base-300">';
    foreach ($rows as $r) {
        $who = $r['user_id'] ? h($r['full_name'] ?? 'User') . ' <span class="text-base-content/60">(' . h(roleLabel((string)$r['user_role'])) . ')</span>' : '<span class="text-base-content/60">System</span>';
        $h .= '<li class="mb-4 ms-4"><span class="absolute -start-1.5 mt-1.5 size-3 rounded-full border-2 border-base-100 bg-primary"></span>'
            . '<div class="text-sm font-semibold">' . h(historyActionLabel($r['action'])) . ($r['status_to'] ? ' ' . requestStatusBadge($r['status_to']) : '') . '</div>'
            . '<div class="text-xs text-base-content/65">' . h(date('d M Y, g:i a', strtotime($r['created_at']))) . ' &middot; ' . $who . '</div>'
            . ($r['reason'] ? '<div class="mt-1 rounded-md bg-base-200 px-2.5 py-1.5 text-sm">' . h($r['reason']) . '</div>' : '') . '</li>';
    }
    return $h . '</ol>';
}

/** The Urgent badge. */
function urgentBadge(array $r): string
{
    return !empty($r['is_urgent']) ? '<span class="badge badge-error badge-sm ms-1"><i class="fas fa-bolt me-1" aria-hidden="true"></i>Urgent</span>' : '';
}

/** What the request is waiting for, in words. */
function requestWaitingText(array $r): string
{
    if ($r['status'] === 'pending') {
        if ($r['purpose_type'] === 'personal') { return empty($r['charge_confirmed_at']) ? 'Waiting for the Administrative Assistant' : 'Waiting for the Head of Admin'; }
        return 'Waiting for ' . ($r['approver_name'] ?? 'the Head of Department');
    }
    if ($r['status'] === 'hod_approved') { return $r['driver_option'] === 'with_driver' ? 'Waiting for driver assignment' : 'Waiting for the Head of Admin'; }
    if ($r['status'] === 'driver_assigned') { return 'Waiting for the Head of Admin'; }
    return '';
}

/**
 * The details of a request (UC017 step 3): request details, supporting letter, assigned drivers and vehicles,
 * reasons and status history. The requester sees the drivers once the request is Approved.
 */
function requestDetailsHtml(mysqli $conn, array $r, array $me): string
{
    $id = (int)$r['request_id'];
    $isStaffView = !in_array($me['role'], ['admin', 'head_admin'], true);
    $h = '<div class="grid grid-cols-12 gap-5"><div class="col-span-12 lg:col-span-7">';
    $h .= '<section class="card mb-5 border border-base-300 bg-base-100"><div class="card-body"><h2 class="mb-1 text-base font-bold">Request details</h2><dl>';
    $h .= dlRow('Status', requestStatusBadge($r['status']) . urgentBadge($r) . (($w = requestWaitingText($r)) ? '<div class="mt-1 text-xs text-base-content/65">' . h($w) . '</div>' : ''));
    $h .= dlRow('Requester', h($r['requester_name']) . ' <span class="text-base-content/60">(' . h($r['requester_staff_no']) . ')</span><div class="text-xs text-base-content/65">' . h($r['department_name'] ?? '-') . ($r['requester_phone'] ? ' &middot; ' . h($r['requester_phone']) : '') . '</div>');
    $h .= dlRow('Departure', h(fmtDT($r['depart_at'])));
    $h .= dlRow('Return', h(fmtDT($r['return_at'])));
    $h .= dlRow('Vehicle', h($r['vehicle_type']) . ' &times; ' . (int)$r['quantity'] . ' &middot; ' . h(driverOptionLabel($r['driver_option'])));
    $h .= dlRow('Purpose type', h(purposeTypeLabel($r['purpose_type'])));
    $h .= dlRow('Purpose', nl2br(h($r['purpose'])));
    $h .= dlRow('Destination', '<strong>' . h($r['destination']) . '</strong><div class="text-sm">' . nl2br(h($r['address'])) . '</div>');
    $h .= dlRow('Waiting location', h($r['waiting_location']));
    $h .= dlRow('Passengers', (string)(int)$r['passengers']);
    if ($r['purpose_type'] === 'personal') {
        $h .= dlRow('Round-trip distance', h(number_format((float)$r['distance_km'], 1)) . ' km');
        $h .= dlRow('Charge', '<strong>' . h(money((float)$r['charge'])) . '</strong> <span class="text-xs text-base-content/60">(RM ' . h(number_format(getSettingFloat($conn, 'CHARGE_PER_KM'), 2)) . ' per km)</span>');
    }
    if ($r['purpose_type'] !== 'personal') { $h .= dlRow('Approver (Head of Department)', h($r['approver_name'] ?? '-')); }
    $h .= dlRow('Submitted', h(fmtDT($r['created_at'])));
    $docs = getRequestDocuments($conn, [$id])[$id] ?? [];
    $dh = '';
    foreach ($docs as $d) { $dh .= '<a class="link link-primary" target="_blank" rel="noopener" href="' . h($d['url']) . '"><i class="fas fa-paperclip me-1"></i>' . h($d['original_name']) . '</a> <span class="text-xs text-base-content/60">(' . h(formatFileSize($d['file_size'])) . ')</span><br>'; }
    $h .= dlRow('Supporting letter', $dh !== '' ? $dh : '<span class="text-base-content/60">None</span>');
    if ($r['reject_reason']) { $h .= dlRow('Reason for rejection', '<span class="text-error">' . h($r['reject_reason']) . '</span> <span class="text-xs text-base-content/60">' . h(($r['rejected_by_name'] ?? '') . ', ' . fmtDT($r['rejected_at'])) . '</span>'); }
    if ($r['cancel_reason']) { $h .= dlRow('Reason for cancellation', h($r['cancel_reason']) . ' <span class="text-xs text-base-content/60">' . h(($r['cancelled_by_name'] ?? '') . ', ' . fmtDT($r['cancelled_at'])) . '</span>'); }
    if ($r['assign_reject_reason'] && !$isStaffView) { $h .= dlRow('Assignment returned', '<span class="text-warning">' . h($r['assign_reject_reason']) . '</span>'); }
    $h .= '</dl></div></section>';

    // Vehicles and drivers
    $showDrivers = !$isStaffView || in_array($r['status'], ['approved', 'completed'], true);
    if ($r['driver_option'] !== 'pay_claim') {
        $rvs = requestVehicles($conn, $id);
        $tasks = requestTasks($conn, $id);
        $byRv = [];
        foreach ($tasks as $t) { $byRv[(int)$t['rv_id']][$t['trip_type']] = $t; }
        $h .= '<section class="card mb-5 border border-base-300 bg-base-100"><div class="card-body"><h2 class="mb-1 text-base font-bold">Vehicles and drivers</h2>';
        if (!$rvs) { $h .= '<p class="text-sm text-base-content/60">No vehicle reserved.</p>'; }
        else {
            $h .= '<div class="overflow-x-auto"><table class="table table-sm"><thead><tr><th>Vehicle</th><th>Outbound driver</th><th>Return driver</th></tr></thead><tbody>';
            foreach ($rvs as $rv) {
                if ($rv['is_vendor']) {
                    $veh = '<span class="badge badge-warning badge-sm">Vendor bus</span> ' . ($rv['vendor_plate'] ? plate($rv['vendor_plate']) . ' <span class="text-xs">' . h($rv['vendor_name']) . '</span>' : '<span class="text-xs text-base-content/60">Vendor Bus Needed</span>');
                    $h .= '<tr><td>' . $veh . '</td><td colspan="2" class="text-xs text-base-content/65">The vendor provides the driver.</td></tr>';
                    continue;
                }
                $veh = plate($rv['plate_number']) . ' <span class="text-xs text-base-content/65">' . h($rv['brand'] . ' ' . $rv['model']) . '</span>' . (!$rv['active'] ? ' <span class="badge badge-ghost badge-xs">released</span>' : '');
                $cell = function (?array $t) use ($showDrivers) {
                    if (!$t) { return '<span class="text-base-content/50">-</span>'; }
                    if (!$showDrivers) { return '<span class="text-base-content/60">Shown after approval</span>'; }
                    return h($t['driver_name']) . ($t['driver_phone'] ? '<div class="text-xs text-base-content/65">' . h($t['driver_phone']) . '</div>' : '') . '<div class="text-xs text-base-content/65">' . h(date('d M, g:i a', strtotime($t['start_dt']))) . '</div>';
                };
                $h .= '<tr><td>' . $veh . '</td><td>' . $cell($byRv[(int)$rv['rv_id']]['outbound'] ?? null) . '</td><td>' . $cell($byRv[(int)$rv['rv_id']]['return'] ?? null) . '</td></tr>';
            }
            $h .= '</tbody></table></div>';
            if (!$isStaffView) { $h .= '<p class="mt-1 text-xs text-base-content/60">Reserved ' . h(fmtPeriod($r['depart_at'], reservationEnd($conn, $r['return_at']))) . '. Task length ' . h($r['task_hours']) . ' h.</p>'; }
        }
        $h .= '</div></section>';
    }
    $h .= '</div><div class="col-span-12 lg:col-span-5">';
    $h .= '<section class="card mb-5 border border-base-300 bg-base-100"><div class="card-body"><h2 class="mb-3 text-base font-bold">Status history</h2>' . historyHtml(requestHistory($conn, $id)) . '</div></section>';
    $h .= '</div></div>';
    return $h;
}

/** Request rows for the lists (UC017): ID, vehicle type, departure, destination, status. */
function requestListTable(array $rows, bool $showRequester, string $empty = 'No record is found.'): string
{
    $h = '<div class="card border border-base-300 bg-base-100"><div class="overflow-x-auto"><table class="table [&_tbody_tr:hover]:bg-base-200/60"><thead><tr><th>Request</th>'
       . ($showRequester ? '<th>Requester</th>' : '') . '<th>Vehicle</th><th>Departure</th><th>Destination</th><th>Status</th><th></th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $h .= '<tr><td class="whitespace-nowrap font-semibold">#' . (int)$r['request_id'] . urgentBadge($r) . '</td>'
            . ($showRequester ? '<td>' . h($r['requester_name']) . '<div class="text-xs text-base-content/60">' . h($r['department_name'] ?? '') . '</div></td>' : '')
            . '<td class="whitespace-nowrap">' . h($r['vehicle_type']) . ((int)$r['quantity'] > 1 ? ' &times; ' . (int)$r['quantity'] : '') . '<div class="text-xs text-base-content/60">' . h(driverOptionLabel($r['driver_option'])) . ($r['purpose_type'] === 'personal' ? ', Personal' : '') . '</div></td>'
            . '<td class="whitespace-nowrap">' . h(date('D, d M Y', strtotime($r['depart_at']))) . '<div class="text-xs text-base-content/60">' . h(date('g:i a', strtotime($r['depart_at']))) . '</div></td>'
            . '<td>' . h($r['destination']) . '</td><td>' . requestStatusBadge($r['status']) . (($w = requestWaitingText($r)) ? '<div class="mt-0.5 text-xs text-base-content/60">' . h($w) . '</div>' : '') . '</td>'
            . '<td class="text-end"><a class="btn btn-sm btn-outline" href="' . SITE_URL . '/staff/request.php?id=' . (int)$r['request_id'] . '">View</a></td></tr>';
    }
    if (!$rows) { $h .= emptyRow($showRequester ? 7 : 6, $empty); }
    return $h . '</tbody></table></div></div>';
}

/** The common filter of request lists: status and departure date range. Returns [where sql, params, values]. */
function requestListFilter(): array
{
    $status = in_array($_GET['status'] ?? '', REQUEST_STATUSES, true) ? $_GET['status'] : '';
    $from = isValidDate((string)($_GET['from'] ?? '')) ? $_GET['from'] : '';
    $to   = isValidDate((string)($_GET['to'] ?? '')) ? $_GET['to'] : '';
    $q    = trim((string)($_GET['q'] ?? ''));
    $w = []; $p = [];
    if ($status) { $w[] = 'r.status = ?'; $p[] = $status; }
    if ($from) { $w[] = 'r.depart_at >= ?'; $p[] = $from . ' 00:00:00'; }
    if ($to) { $w[] = 'r.depart_at <= ?'; $p[] = $to . ' 23:59:59'; }
    if ($q !== '') { $w[] = '(r.request_id = ? OR r.destination LIKE ? OR u.full_name LIKE ?)'; $p[] = (int)ltrim($q, '#'); $p[] = "%$q%"; $p[] = "%$q%"; }
    return [$w ? ' AND ' . implode(' AND ', $w) : '', $p, ['status' => $status, 'from' => $from, 'to' => $to, 'q' => $q]];
}

/** The filter form of request lists. $keep = extra GET values to keep (e.g. tab). */
function requestFilterForm(array $vals, array $keep = [], bool $search = false): string
{
    $h = '<form method="get" class="mb-4 flex flex-wrap items-end gap-2">';
    foreach ($keep as $k => $v) { $h .= '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">'; }
    if ($search) { $h .= '<div><label class="mb-1 block text-xs font-medium" for="f_q">Search</label><input type="search" class="input input-sm w-48" id="f_q" name="q" value="' . h($vals['q']) . '" placeholder="ID, destination, name"></div>'; }
    $h .= '<div><label class="mb-1 block text-xs font-medium" for="f_status">Status</label><select class="select select-sm w-52" id="f_status" name="status"><option value="">All statuses</option>';
    foreach (REQUEST_STATUSES as $s) { $h .= '<option value="' . $s . '"' . ($vals['status'] === $s ? ' selected' : '') . '>' . h(requestStatusLabel($s)) . '</option>'; }
    $h .= '</select></div><div><label class="mb-1 block text-xs font-medium" for="f_from">Departure from</label><input type="date" class="input input-sm" id="f_from" name="from" value="' . h($vals['from']) . '"></div>'
        . '<div><label class="mb-1 block text-xs font-medium" for="f_to">to</label><input type="date" class="input input-sm" id="f_to" name="to" value="' . h($vals['to']) . '"></div>'
        . '<button class="btn btn-sm btn-primary" type="submit"><i class="fas fa-filter"></i>Filter</button><a class="btn btn-sm btn-ghost" href="?' . h(http_build_query($keep)) . '">Reset</a></form>';
    return $h;
}

/** Task rows for schedule-type lists. */
function taskRowCells(array $t): string
{
    return '<td class="whitespace-nowrap">' . h(date('D, d M Y', strtotime($t['start_dt']))) . '<div class="text-xs text-base-content/60">' . h(date('g:i a', strtotime($t['start_dt'])) . ' - ' . date((substr($t['start_dt'], 0, 10) === substr($t['end_dt'], 0, 10) ? '' : 'd M ') . 'g:i a', strtotime($t['end_dt']))) . '</div></td>'
         . '<td>' . h(taskTypeLabel($t)) . (!empty($t['request_id']) ? ' <a class="link text-xs" href="' . SITE_URL . '/staff/request.php?id=' . (int)$t['request_id'] . '">#' . (int)$t['request_id'] . '</a>' : '') . '</td>'
         . '<td class="whitespace-nowrap">' . (!empty($t['plate_number']) ? plate($t['plate_number']) : '-') . '</td>';
}

/** The licence class, licence expiry date and start date of service fields of a driver (UC001A, UC001C). */
function driverFieldsHtml(array $v, array $errors): string
{
    $sel = array_map('trim', is_array($v['licence_classes'] ?? null) ? $v['licence_classes'] : explode(',', (string)($v['licence_classes'] ?? '')));
    $h = '<fieldset class="col-span-12 md:col-span-6"><legend class="mb-1 text-sm font-medium">Licence classes</legend><div class="flex flex-wrap gap-4">';
    foreach (LICENCE_CLASSES as $k => $label) {
        $h .= '<label class="flex items-center gap-2 text-sm"><input type="checkbox" class="checkbox checkbox-sm" name="licence_classes[]" value="' . $k . '"' . (in_array($k, $sel, true) ? ' checked' : '') . '>' . h($label) . '</label>';
    }
    $h .= '</div>' . fieldError($errors, 'licence_classes') . '</fieldset>'
        . '<div class="col-span-6 md:col-span-3"><label class="mb-1 block text-sm font-medium" for="licence_expiry">Licence expiry date</label><input type="date" class="input w-full' . errClass($errors, 'licence_expiry') . '" id="licence_expiry" name="licence_expiry" value="' . h($v['licence_expiry'] ?? '') . '" required>' . fieldError($errors, 'licence_expiry') . '</div>'
        . '<div class="col-span-6 md:col-span-3"><label class="mb-1 block text-sm font-medium" for="start_date">Start date of service</label><input type="date" class="input w-full' . errClass($errors, 'start_date') . '" id="start_date" name="start_date" max="' . date('Y-m-d') . '" value="' . h($v['start_date'] ?? '') . '" required>' . fieldError($errors, 'start_date') . '<div class="mt-1 text-xs text-base-content/60">Used for the experience in Recommend Driver.</div></div>';
    return $h;
}
