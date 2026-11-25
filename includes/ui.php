<?php
// ============================================================
// includes/ui.php  -  View Layer (SDD_PKG_100): the HTML pieces shared by the pages
//   labels and badges, page header, form fields, pager, tabs, request details (UC017), status history, driver pickers (UC009)
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

// ---------------------------------------------------------------- labels and badges

function badge(string $text, string $cls): string
{
    return '<span class="badge ' . $cls . ' whitespace-nowrap">' . h($text) . '</span>';
}

function requestStatusBadge(string $s): string
{
    return badge($s, ['Pending' => 'badge-warning', 'Approved by HoD' => 'badge-info', 'Driver Assigned' => 'badge-primary badge-outline', 'Approved' => 'badge-success',
                      'Rejected' => 'badge-error', 'Completed' => 'badge-neutral'][$s] ?? 'border-0 bg-slate-500 text-white');
}

function taskStatusBadge(string $s): string
{
    return badge($s, ['Waiting for Driver' => 'badge-warning', 'Driver Assigned' => 'badge-primary badge-outline', 'Approved' => 'badge-success', 'Rejected' => 'badge-error',
                      'Completed' => 'badge-neutral'][$s] ?? 'border-0 bg-slate-500 text-white');
}

function vehicleStatusBadge(string $s): string
{
    return badge($s, ['Active' => 'badge-success', 'Under Maintenance' => 'badge-warning'][$s] ?? 'border-0 bg-slate-500 text-white');
}

function userStatusBadge(string $s): string
{
    return badge($s, $s === 'Active' ? 'badge-success' : 'border-0 bg-slate-500 text-white');
}

function decisionBadge(string $s): string
{
    return badge($s, ['Pending' => 'badge-warning', 'Approved' => 'badge-success', 'Rejected' => 'badge-error'][$s] ?? 'badge-ghost');
}

/** "Request task (Outbound)", "Workshop", "Welfare duty" */
function taskTypeLabel(array $t): string
{
    if ($t['task_source'] === 'Request') { return !empty($t['vendor_name']) ? 'Vendor bus (' . strtolower((string)$t['trip_type']) . ')' : 'Request task (' . strtolower((string)$t['trip_type']) . ')'; }
    if ($t['task_source'] === 'Welfare Duty') { return 'Welfare duty'; }
    return (string)$t['task_type'];
}

/** A registration number set like a number plate. */
function plate(?string $plateNo): string
{
    $p = trim((string)$plateNo);
    return $p === '' ? '' : '<span class="plate">' . h($p) . '</span>';
}

/** The status history (UC017 3.1): status, reason, who, date and time. */
function historyHtml(array $rows): string
{
    if (!$rows) { return '<p class="text-sm text-base-content/60">No history yet.</p>'; }
    $h = '<ol class="relative ms-2 border-s border-base-300">';
    foreach ($rows as $r) {
        $who = $r['changed_by'] ? h($r['changed_by_name'] ?? 'User') . ' <span class="text-base-content/60">(' . h($r['changed_by_role']) . ')</span>' : '<span class="text-base-content/60">System</span>';
        $title = $r['old_status'] === $r['new_status'] ? 'Note' : ($r['old_status'] ? 'Status changed' : 'Submitted');
        $h .= '<li class="mb-4 ms-4"><span class="absolute -start-1.5 mt-1.5 size-3 rounded-full border-2 border-base-100 bg-primary"></span>'
            . '<div class="text-sm font-semibold">' . h($title) . ' ' . ($r['old_status'] !== $r['new_status'] ? requestStatusBadge($r['new_status']) : '') . '</div>'
            . '<div class="text-xs text-base-content/65">' . h(date('d M Y, g:i a', strtotime($r['changed_at']))) . ' &middot; ' . $who . '</div>'
            . ($r['remark'] ? '<div class="mt-1 rounded-md bg-base-200 px-2.5 py-1.5 text-sm">' . h($r['remark']) . '</div>' : '') . '</li>';
    }
    return $h . '</ol>';
}

function urgentBadge(array $r): string
{
    return !empty($r['is_urgent']) ? '<span class="badge badge-error badge-sm ms-1"><i class="fas fa-bolt me-1" aria-hidden="true"></i>Urgent</span>' : '';
}

/**
 * The details of a request (UC017 step 3): details, supporting letter, vehicles and drivers, reasons and status history.
 * $r comes from RequestController::getRequestDetails(). The requester sees the drivers once the request is Approved.
 */
function requestDetailsHtml(array $r, array $me, RequestController $rc): string
{
    $id = (int)$r['request_id'];
    $staffView = !in_array($me['role'], ['Administrative Assistant', 'Head of Admin'], true);
    $h = '<div class="grid grid-cols-12 gap-5"><div class="col-span-12 lg:col-span-7">';
    $h .= '<section class="card mb-5 border border-base-300 bg-base-100"><div class="card-body"><h2 class="mb-1 text-base font-bold">Request details</h2><dl>';
    $h .= dlRow('Status', requestStatusBadge($r['status']) . urgentBadge($r) . (($w = $rc->waitingText($r)) ? '<div class="mt-1 text-xs text-base-content/65">' . h($w) . '</div>' : ''));
    $h .= dlRow('Requester', h($r['requester_name']) . ' <span class="text-base-content/60">(' . h($r['requester_staff_id']) . ')</span><div class="text-xs text-base-content/65">' . h($r['department_name']) . ' &middot; ' . h($r['requester_phone']) . '</div>');
    $h .= dlRow('Departure', h(fmtDT($r['depart_datetime'])));
    $h .= dlRow('Return', h(fmtDT($r['return_datetime'])));
    $h .= dlRow('Vehicle', h($r['vehicle_type']) . ' &times; ' . (int)$r['quantity'] . ' &middot; ' . h($r['driver_option']));
    $h .= dlRow('Purpose type', h($r['purpose_type']));
    $h .= dlRow('Purpose', nl2br(h($r['purpose'])));
    $h .= dlRow('Destination', '<strong>' . h($r['destination']) . '</strong><div class="text-sm">' . nl2br(h($r['address'])) . '</div>');
    $h .= dlRow('Waiting location', h($r['waiting_location']));
    $h .= dlRow('Passengers', (string)(int)$r['no_of_passengers']);
    if ($r['purpose_type'] === 'Personal') {
        $h .= dlRow('Round-trip distance', h(number_format((float)$r['distance_km'], 1)) . ' km');
        $h .= dlRow('Charge', '<strong>' . h(money((float)$r['charge'])) . '</strong> <span class="text-xs text-base-content/60">(RM ' . number_format($rc->getChargePerKm(), 2) . ' per km)</span>');
    } else {
        $ap = $rc->approverOf($r);
        $h .= dlRow('Approver', h($r['hod_name'] ?? ($ap['name'] ?? '-')) . ($r['hod_approved_date'] ? ' <span class="text-xs text-base-content/60">approved ' . h(fmtDT($r['hod_approved_date'])) . '</span>' : ''));
    }
    if ($r['hoa_name'] && $r['hoa_approved_date']) { $h .= dlRow('Head of Admin', h($r['hoa_name']) . ' <span class="text-xs text-base-content/60">approved ' . h(fmtDT($r['hoa_approved_date'])) . '</span>'); }
    $h .= dlRow('Submitted', h(fmtDT($r['date_submitted'])));
    $h .= dlRow('Supporting letter', $r['supporting_doc'] ? '<a class="link link-primary" target="_blank" rel="noopener" href="' . h(SITE_URL . '/ajax/get_document.php?id=' . $id) . '"><i class="fas fa-paperclip me-1"></i>Open the letter</a>' : '<span class="text-base-content/60">None</span>');
    if ($r['reject_reason']) { $h .= dlRow('Reason for rejection', '<span class="text-error">' . h($r['reject_reason']) . '</span> <span class="text-xs text-base-content/60">' . h(fmtDT($r['rejected_date'])) . '</span>'); }
    if ($r['cancel_reason']) { $h .= dlRow('Reason for cancellation', h($r['cancel_reason']) . ' <span class="text-xs text-base-content/60">' . h(($r['cancelled_by_name'] ?? '') . ', ' . fmtDT($r['cancelled_date'])) . '</span>'); }
    $h .= '</dl></div></section>';

    if ($r['driver_option'] !== 'Pay and Claim') {
        $show = !$staffView || in_array($r['status'], ['Approved', 'Completed'], true);
        $byVehicle = [];
        foreach ($r['tasks'] as $t) { $byVehicle[$t['vehicle_id'] ? 'v' . $t['vehicle_id'] : 'x' . $t['vendor_plate_no']][$t['trip_type']] = $t; }
        $h .= '<section class="card mb-5 border border-base-300 bg-base-100"><div class="card-body"><h2 class="mb-1 text-base font-bold">Vehicles and drivers</h2>';
        if (!$r['reservations']) { $h .= '<p class="text-sm text-base-content/60">No vehicle reserved.</p>'; }
        else {
            $h .= '<div class="overflow-x-auto"><table class="table table-sm"><thead><tr><th>Reserved unit</th><th>Outbound driver</th><th>Return driver</th></tr></thead><tbody>';
            $cell = function (?array $t) use ($show) {
                if (!$t) { return '<span class="text-base-content/50">-</span>'; }
                if (!$show) { return '<span class="text-base-content/60">Shown after approval</span>'; }
                if ($t['vendor_name']) { return '<span class="text-xs">' . h($t['vendor_name']) . ' (vendor driver)</span>'; }
                return h($t['driver_name']) . '<div class="text-xs text-base-content/65">' . h($t['driver_phone']) . ' &middot; ' . h(date('d M, g:i a', strtotime($t['start_datetime']))) . '</div>';
            };
            foreach ($r['reservations'] as $res) {
                $released = $res['status'] === 'Released' ? ' <span class="badge badge-ghost badge-xs">released</span>' : '';
                if ($res['is_vendor']) {
                    $vt = null;
                    foreach ($r['tasks'] as $t) { if ($t['vendor_name'] && str_ends_with((string)$t['description'], '#' . $res['reservation_id'] . ')')) { $vt = $t; } }
                    $veh = '<span class="badge badge-warning badge-sm">Vendor bus</span> ' . ($vt ? plate($vt['vendor_plate_no']) . ' <span class="text-xs">' . h($vt['vendor_name']) . '</span>' : '<span class="text-xs text-base-content/60">Vendor Bus Needed</span>') . $released;
                    $h .= '<tr><td>' . $veh . '</td><td colspan="2" class="text-xs text-base-content/65">The vendor provides the driver.</td></tr>';
                    continue;
                }
                $key = 'v' . $res['vehicle_id'];
                $veh = h($res['vehicle_type']) . ' ' . ($res['vehicle_id'] ? plate($res['plate_no']) . ' <span class="text-xs text-base-content/65">' . h($res['brand'] . ' ' . $res['model']) . '</span>' : '<span class="text-xs text-base-content/60">(vehicle not chosen yet)</span>') . $released;
                $h .= '<tr><td>' . $veh . '</td><td>' . ($res['vehicle_id'] ? $cell($byVehicle[$key]['Outbound'] ?? null) : '-') . '</td><td>' . ($res['vehicle_id'] ? $cell($byVehicle[$key]['Return'] ?? null) : '-') . '</td></tr>';
            }
            $h .= '</tbody></table></div>';
            if (!$staffView) { $h .= '<p class="mt-1 text-xs text-base-content/60">Reserved ' . h(fmtPeriod($r['depart_datetime'], $rc->reservationEnd($r['return_datetime']))) . '.</p>'; }
        }
        $h .= '</div></section>';
    }
    $h .= '</div><div class="col-span-12 lg:col-span-5"><section class="card mb-5 border border-base-300 bg-base-100"><div class="card-body"><h2 class="mb-3 text-base font-bold">Status history</h2>' . historyHtml($r['history']) . '</div></section></div></div>';
    return $h;
}

/** Request rows for the lists (UC017): ID, vehicle type, departure, destination, status. */
function requestListTable(array $rows, bool $showRequester, RequestController $rc, string $empty = 'No record is found.'): string
{
    $h = '<div class="card border border-base-300 bg-base-100"><div class="overflow-x-auto"><table class="table [&_tbody_tr:hover]:bg-base-200/60"><thead><tr><th>Request</th>'
       . ($showRequester ? '<th>Requester</th>' : '') . '<th>Vehicle</th><th>Departure</th><th>Destination</th><th>Status</th><th></th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $h .= '<tr><td class="whitespace-nowrap font-semibold">#' . (int)$r['request_id'] . urgentBadge($r) . '</td>'
            . ($showRequester ? '<td>' . h($r['requester_name']) . '<div class="text-xs text-base-content/60">' . h($r['department_name']) . '</div></td>' : '')
            . '<td class="whitespace-nowrap">' . h($r['vehicle_type']) . ((int)$r['quantity'] > 1 ? ' &times; ' . (int)$r['quantity'] : '') . '<div class="text-xs text-base-content/60">' . h($r['driver_option']) . ($r['purpose_type'] === 'Personal' ? ', Personal' : '') . '</div></td>'
            . '<td class="whitespace-nowrap">' . h(date('D, d M Y', strtotime($r['depart_datetime']))) . '<div class="text-xs text-base-content/60">' . h(date('g:i a', strtotime($r['depart_datetime']))) . '</div></td>'
            . '<td>' . h($r['destination']) . '</td><td>' . requestStatusBadge($r['status']) . (($w = $rc->waitingText($r)) ? '<div class="mt-0.5 text-xs text-base-content/60">' . h($w) . '</div>' : '') . '</td>'
            . '<td class="text-end"><a class="btn btn-sm btn-outline" href="' . SITE_URL . '/staff/request.php?id=' . (int)$r['request_id'] . '">View</a></td></tr>';
    }
    if (!$rows) { $h .= emptyRow($showRequester ? 7 : 6, $empty); }
    return $h . '</tbody></table></div></div>';
}

/** The filter of request lists from the address: status, from, to, q. */
function requestListFilter(): array
{
    return ['status' => in_array($_GET['status'] ?? '', Request::STATUSES, true) ? $_GET['status'] : '',
            'from' => isValidDate((string)($_GET['from'] ?? '')) ? $_GET['from'] : '', 'to' => isValidDate((string)($_GET['to'] ?? '')) ? $_GET['to'] : '',
            'q' => trim((string)($_GET['q'] ?? ''))];
}

/** The filter form of request lists. $keep = extra GET values to keep (e.g. tab). */
function requestFilterForm(array $vals, array $keep = [], bool $search = false): string
{
    $h = '<form method="get" class="mb-4 flex flex-wrap items-end gap-2">';
    foreach ($keep as $k => $v) { $h .= '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">'; }
    if ($search) { $h .= '<div><label class="mb-1 block text-xs font-medium" for="f_q">Search</label><input type="search" class="input input-sm w-48" id="f_q" name="q" value="' . h($vals['q']) . '" placeholder="ID, destination, name"></div>'; }
    $h .= '<div><label class="mb-1 block text-xs font-medium" for="f_status">Status</label><select class="select select-sm w-52" id="f_status" name="status"><option value="">All statuses</option>';
    foreach (Request::STATUSES as $s) { $h .= '<option' . ($vals['status'] === $s ? ' selected' : '') . '>' . h($s) . '</option>'; }
    $h .= '</select></div><div><label class="mb-1 block text-xs font-medium" for="f_from">Departure from</label><input type="date" class="input input-sm" id="f_from" name="from" value="' . h($vals['from']) . '"></div>'
        . '<div><label class="mb-1 block text-xs font-medium" for="f_to">to</label><input type="date" class="input input-sm" id="f_to" name="to" value="' . h($vals['to']) . '"></div>'
        . '<button class="btn btn-sm btn-primary" type="submit"><i class="fas fa-filter"></i>Filter</button><a class="btn btn-sm btn-ghost" href="?' . h(http_build_query($keep)) . '">Reset</a></form>';
    return $h;
}

/** Date, task and vehicle cells of a task row. */
function taskRowCells(array $t): string
{
    $sameDay = substr($t['start_datetime'], 0, 10) === substr($t['end_datetime'], 0, 10);
    return '<td class="whitespace-nowrap">' . h(date('D, d M Y', strtotime($t['start_datetime']))) . '<div class="text-xs text-base-content/60">' . h(date('g:i a', strtotime($t['start_datetime'])) . ' - ' . date(($sameDay ? '' : 'd M ') . 'g:i a', strtotime($t['end_datetime']))) . '</div></td>'
         . '<td>' . h(taskTypeLabel($t)) . (!empty($t['request_id']) ? ' <a class="link text-xs" href="' . SITE_URL . '/staff/request.php?id=' . (int)$t['request_id'] . '">#' . (int)$t['request_id'] . '</a>' : '') . '</td>'
         . '<td class="whitespace-nowrap">' . (!empty($t['plate_no']) ? plate($t['plate_no']) : (!empty($t['vendor_plate_no']) ? plate($t['vendor_plate_no']) : '-')) . '</td>';
}

// ---------------------------------------------------------------- UC009 pickers

/**
 * <option>s for a driver picker: recommended drivers first, then the others ("outside the recommendation": the page asks
 * for a confirmation with the task count, UC003 A2). Drivers who fail the filters are not offered.
 */
function driverOptionsHtml(array $rec, int $selected = 0): string
{
    $h = '<option value="">Select a driver</option>';
    foreach (['Recommended' => array_filter($rec['ranked'], fn($c) => $c['recommended']), 'Outside the recommendation' => array_filter($rec['ranked'], fn($c) => !$c['recommended'])] as $label => $list) {
        if (!$list) { continue; }
        $h .= '<optgroup label="' . h($label) . '">';
        foreach ($list as $c) {
            $txt = '#' . $c['rank'] . ' ' . $c['name'] . ' - ' . $c['tasks'] . ' tasks, ' . $c['weekend'] . ' weekend, ' . $c['years'] . ' yrs' . ($c['on_welfare'] ? ' (on welfare duty)' : '');
            $h .= '<option value="' . (int)$c['driver_id'] . '" data-tasks="' . (int)$c['tasks'] . '" data-name="' . h($c['name']) . '" data-outside="' . ($c['recommended'] ? '0' : '1') . '"'
                . ((int)$c['driver_id'] === $selected ? ' selected' : '') . '>' . h($txt) . '</option>';
        }
        $h .= '</optgroup>';
    }
    return $h;
}

/** The ranked table shown next to a picker (UC009 1.5). */
function recommendationTableHtml(array $rec): string
{
    if (!$rec['ranked']) { return '<div class="alert alert-soft alert-warning text-sm"><i class="fas fa-triangle-exclamation"></i><span>No suitable driver is available for this period.</span></div>'; }
    $h = '<div class="overflow-x-auto"><table class="table table-sm"><thead><tr><th>#</th><th>Driver</th><th class="text-end">Tasks this month</th><th class="text-end">Weekend</th><th class="text-end">Experience</th><th class="text-end">Score</th></tr></thead><tbody>';
    foreach ($rec['ranked'] as $c) {
        $h .= '<tr class="' . ($c['recommended'] ? 'bg-success/5' : '') . '"><td>' . (int)$c['rank'] . '</td><td>' . h($c['name'])
            . ($c['recommended'] ? ' <span class="badge badge-success badge-xs">Recommended</span>' : '') . ($c['on_welfare'] ? ' <span class="badge badge-warning badge-xs" title="On welfare duty on that date">Welfare duty</span>' : '')
            . '<div class="text-xs text-base-content/60">' . (int)$c['request'] . ' request, ' . (int)$c['internal'] . ' internal, ' . (int)$c['welfare'] . ' welfare</div></td>'
            . '<td class="text-end">' . (int)$c['tasks'] . '</td><td class="text-end">' . (int)$c['weekend'] . '</td><td class="text-end">' . h($c['years']) . ' yrs</td>'
            . '<td class="text-end font-medium">' . number_format($c['score'] * 100, 0) . '%</td></tr>';
    }
    $h .= '</tbody></table></div>';
    if ($rec['excluded']) {
        $h .= '<details class="mt-2 text-xs text-base-content/70"><summary class="cursor-pointer">' . count($rec['excluded']) . ' driver(s) not available</summary><ul class="ms-4 mt-1 list-disc">';
        foreach ($rec['excluded'] as $x) { $h .= '<li>' . h($x['name']) . ': ' . h($x['reason']) . '</li>'; }
        $h .= '</ul></details>';
    }
    return $h;
}

/** The licence class, licence expiry date and start date of service fields of a driver (UC001A, UC001C). */
function driverFieldsHtml(array $v, array $errors): string
{
    $sel = array_map('trim', is_array($v['licence_classes'] ?? null) ? $v['licence_classes'] : explode(',', (string)($v['licence_classes'] ?? '')));
    $h = '<fieldset class="col-span-12 md:col-span-6"><legend class="mb-1 text-sm font-medium">Licence classes</legend><div class="flex flex-wrap gap-4">';
    foreach (Driver::LICENCE_CLASSES as $k => $label) {
        $h .= '<label class="flex items-center gap-2 text-sm"><input type="checkbox" class="checkbox checkbox-sm" name="licence_classes[]" value="' . $k . '"' . (in_array($k, $sel, true) ? ' checked' : '') . '>' . h($label) . '</label>';
    }
    $h .= '</div>' . fieldError($errors, 'licence_classes') . '</fieldset>'
        . '<div class="col-span-6 md:col-span-3"><label class="mb-1 block text-sm font-medium" for="licence_expiry">Licence expiry date</label><input type="date" class="input w-full' . errClass($errors, 'licence_expiry') . '" id="licence_expiry" name="licence_expiry" value="' . h($v['licence_expiry'] ?? '') . '" required>' . fieldError($errors, 'licence_expiry') . '</div>'
        . '<div class="col-span-6 md:col-span-3"><label class="mb-1 block text-sm font-medium" for="start_date">Start date of service</label><input type="date" class="input w-full' . errClass($errors, 'start_date') . '" id="start_date" name="start_date" max="' . date('Y-m-d') . '" value="' . h($v['start_date'] ?? '') . '" required>' . fieldError($errors, 'start_date') . '<div class="mt-1 text-xs text-base-content/60">Used for the experience in Recommend Driver.</div></div>';
    return $h;
}
