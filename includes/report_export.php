<?php
// ============================================================
// includes/report_export.php  -  Reports (SRS UC031 Generate, UC032 Export)
//   reportTypes()            the monthly report types (UC031)
//   buildReport()            numbers for one type and month (prepared statements)
//   exportReportPdf()        small PDF 1.4 writer (A4, Helvetica, wrapped cells, page breaks, xref)
//   exportReportXlsx()       .xlsx writer on ZipArchive (HTML-table .xls when ZipArchive is missing)
// No external libraries.
// ============================================================

function reportTypes(): array
{
    return [
        'tasks'   => 'Tasks per driver',
        'weekend' => 'Weekend tasks per driver',
        'outside' => 'Outside working hours per driver',
        'welfare' => 'Welfare duty days per driver',
        'replacements' => 'Driver replacements',
        'vehicle' => 'Vehicle usage',
    ];
}

/**
 * The table of one report.
 * @return array{title:string, month:string, headers:string[], rows:array<int,array>, totals:array, widths:int[], note:string}
 *   rows hold ints / floats (numbers) and strings (text). `totals` has the same length as `headers`.
 */
function buildReport(mysqli $conn, string $type, string $month): array
{
    $types = reportTypes();
    $from = $month . '-01';
    $to   = date('Y-m-t', strtotime($from));
    $rep  = ['title' => $types[$type] ?? $type, 'month' => $month, 'headers' => [], 'rows' => [], 'totals' => [], 'widths' => [], 'note' => ''];

    if (in_array($type, ['tasks', 'weekend', 'outside', 'welfare'], true)) {
        // drivers that are active, or that have something in the month
        $counts = driverMonthCounts($conn, $month);
        $drivers = $conn->query('SELECT driver_id, name, employee_id, status FROM drivers ORDER BY name')->fetch_all(MYSQLI_ASSOC);
    }

    if ($type === 'tasks') {
        $split = [];
        $stmt = $conn->prepare("SELECT driver_id, SUM(k = 'r') AS req, SUM(k = 'i') AS internal FROM (
                                   SELECT driver_id, 'r' AS k FROM schedules WHERE driver_id IS NOT NULL AND status <> 'cancelled' AND trip_date BETWEEN ? AND ?
                                   UNION ALL
                                   SELECT driver_id, 'i' FROM internal_tasks WHERE status = 'active' AND task_date BETWEEN ? AND ?
                                ) x GROUP BY driver_id");
        $stmt->bind_param('ssss', $from, $to, $from, $to);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) { $split[(int)$r['driver_id']] = [(int)$r['req'], (int)$r['internal']]; }
        $stmt->close();
        $rep['headers'] = ['Driver', 'Employee ID', 'Request tasks', 'Internal tasks', 'Total tasks'];
        $rep['widths']  = [40, 16, 15, 15, 14];
        $rep['note']    = 'Request tasks and internal tasks that are not cancelled. Welfare duty days are not counted here.';
        foreach ($drivers as $d) {
            [$r, $i] = $split[(int)$d['driver_id']] ?? [0, 0];
            if ($d['status'] !== 'active' && $r + $i === 0) { continue; }
            $rep['rows'][] = [$d['name'], $d['employee_id'], $r, $i, $r + $i];
        }
    } elseif ($type === 'weekend') {
        $rep['headers'] = ['Driver', 'Employee ID', 'Total tasks', 'Weekend tasks', 'Share'];
        $rep['widths']  = [36, 14, 14, 22, 14];
        $rep['note']    = 'Tasks that fall on a Saturday or Sunday (request tasks and internal tasks that are not cancelled). Welfare duty days are not counted.';
        foreach ($drivers as $d) {
            $c = $counts[(int)$d['driver_id']] ?? ['tasks' => 0, 'outside' => 0, 'weekend' => 0, 'welfare' => 0];
            $t = $c['tasks'] - $c['welfare'];
            if ($d['status'] !== 'active' && $t === 0) { continue; }
            $rep['rows'][] = [$d['name'], $d['employee_id'], $t, $c['weekend'], $t ? round($c['weekend'] * 100 / $t) . '%' : '-'];
        }
    } elseif ($type === 'outside') {
        $ws = substr(getSetting($conn, 'WORK_START'), 0, 5); $we = substr(getSetting($conn, 'WORK_END'), 0, 5);
        $rep['headers'] = ['Driver', 'Employee ID', 'Total tasks', 'Outside working hours', 'Share'];
        $rep['widths']  = [36, 14, 14, 22, 14];
        $rep['note']    = "Tasks that start before $ws, end after $we or fall on a Saturday or Sunday. Welfare duty days are not counted.";
        foreach ($drivers as $d) {
            $c = $counts[(int)$d['driver_id']] ?? ['tasks' => 0, 'outside' => 0, 'welfare' => 0];
            $t = $c['tasks'] - $c['welfare'];
            if ($d['status'] !== 'active' && $t === 0) { continue; }
            $rep['rows'][] = [$d['name'], $d['employee_id'], $t, $c['outside'], $t ? round($c['outside'] * 100 / $t) . '%' : '-'];
        }
    } elseif ($type === 'welfare') {
        $days = [];
        $stmt = $conn->prepare('SELECT driver_id, DAY(duty_date) AS d FROM welfare_roster WHERE duty_date BETWEEN ? AND ? ORDER BY duty_date');
        $stmt->bind_param('ss', $from, $to);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) { $days[(int)$r['driver_id']][] = (int)$r['d']; }
        $stmt->close();
        $rep['headers'] = ['Driver', 'Employee ID', 'Welfare duty days', 'Dates'];
        $rep['widths']  = [34, 14, 16, 36];
        $rep['note']    = 'Days on welfare duty (24 hours each).';
        foreach ($drivers as $d) {
            $list = $days[(int)$d['driver_id']] ?? [];
            if ($d['status'] !== 'active' && !$list) { continue; }
            $rep['rows'][] = [$d['name'], $d['employee_id'], count($list), implode(', ', $list)];
        }
    } elseif ($type === 'replacements') {
        $stmt = $conn->prepare(
            "SELECT r.changed_at, s.request_id, s.leg_no, s.trip_date, s.destination, o.name AS old_name, n.name AS new_name, r.reason, u.full_name AS by_name
               FROM driver_replacements r
               JOIN schedules s ON s.schedule_id = r.schedule_id
               LEFT JOIN drivers o ON o.driver_id = r.previous_driver_id
               LEFT JOIN drivers n ON n.driver_id = r.new_driver_id
               LEFT JOIN users u ON u.user_id = r.changed_by
              WHERE DATE(r.changed_at) BETWEEN ? AND ?
              ORDER BY r.changed_at, r.replacement_id");
        $stmt->bind_param('ss', $from, $to);
        $stmt->execute();
        $rep['headers'] = ['Date changed', 'Request', 'Trip date', 'Previous driver', 'New driver', 'Reason', 'Changed by'];
        $rep['widths']  = [18, 12, 14, 24, 24, 38, 20];
        $rep['note']    = 'Every driver replacement made in the month (UC020), with the reason and who made it. Records are never edited.';
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $rep['rows'][] = [date('d M Y H:i', strtotime($r['changed_at'])), '#' . $r['request_id'] . ($r['leg_no'] ? ' (task ' . $r['leg_no'] . ')' : ''),
                              date('d M Y', strtotime($r['trip_date'])), $r['old_name'] ?? '-', $r['new_name'] ?? '-', $r['reason'], $r['by_name'] ?? '-'];
        }
        $stmt->close();
    } else {
        $stmt = $conn->prepare(
            "SELECT v.vehicle_id, v.plate_number, v.vehicle_type, v.brand, v.model, v.status,
                    COALESCE((SELECT COUNT(*) FROM schedules s WHERE s.vehicle_id = v.vehicle_id AND s.status <> 'cancelled' AND s.trip_date BETWEEN ? AND ?), 0) AS trips,
                    COALESCE((SELECT SUM(TIMESTAMPDIFF(MINUTE, s.start_dt, s.end_dt)) FROM schedules s WHERE s.vehicle_id = v.vehicle_id AND s.status <> 'cancelled' AND s.trip_date BETWEEN ? AND ?), 0) AS trip_min,
                    COALESCE((SELECT SUM(TIMESTAMPDIFF(MINUTE, t.start_dt, t.end_dt)) FROM internal_tasks t WHERE t.vehicle_id = v.vehicle_id AND t.status = 'active' AND t.task_date BETWEEN ? AND ?), 0) AS int_min,
                    COALESCE((SELECT SUM(GREATEST(0, DATEDIFF(LEAST(m.end_date, COALESCE(DATE(m.closed_at), m.end_date), ?), GREATEST(m.start_date, ?)) + 1))
                                FROM vehicle_maintenance m WHERE m.vehicle_id = v.vehicle_id AND m.start_date <= ? AND m.end_date >= ?), 0) AS maint_days
               FROM vehicles v ORDER BY v.plate_number");
        $stmt->bind_param('ssssssssss', $from, $to, $from, $to, $from, $to, $to, $from, $to, $from);
        $stmt->execute();
        $rep['headers'] = ['Vehicle', 'Type', 'Trips', 'Hours used', 'Internal task hours', 'Maintenance days'];
        $rep['widths']  = [26, 10, 10, 16, 18, 20];
        $rep['note']    = 'Trips and hours come from request tasks that are not cancelled. Internal task hours are active internal tasks. Maintenance days are the days of the month the vehicle was in the workshop.';
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            if ($r['status'] === 'inactive' && !$r['trips'] && !$r['int_min'] && !$r['maint_days']) { continue; }
            $rep['rows'][] = [trim($r['plate_number'] . ' ' . $r['brand'] . ' ' . $r['model']), $r['vehicle_type'], (int)$r['trips'],
                              round($r['trip_min'] / 60, 2), round($r['int_min'] / 60, 2), (int)$r['maint_days']];
        }
        $stmt->close();
    }

    // totals: numbers are summed; the first column says "Total"
    $n = count($rep['headers']);
    $tot = array_fill(0, $n, '');
    $tot[0] = 'Total';
    for ($c = 1; $c < $n; $c++) {
        $isNum = $rep['rows'] ? true : false;
        $sum = 0;
        foreach ($rep['rows'] as $row) { if (!is_int($row[$c]) && !is_float($row[$c])) { $isNum = false; break; } $sum += $row[$c]; }
        if ($isNum) { $tot[$c] = is_float($sum) ? round($sum, 2) : $sum; }
    }
    if ($type === 'outside' && $tot[2] !== '') { $tot[4] = $tot[2] ? round($tot[3] * 100 / $tot[2]) . '%' : '-'; }
    if ($type === 'replacements') { $tot[0] = 'Total: ' . count($rep['rows']); }
    $rep['totals'] = $tot;
    return $rep;
}

function reportFileName(string $type, string $month, string $ext): string
{
    return 'dsms_report_' . $type . '_' . $month . '.' . $ext;
}

/** Discards everything buffered so far and sends the download headers. */
function startDownload(string $mime, string $filename, int $length): void
{
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . $length);
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
}

function reportCellText($v): string
{
    return is_float($v) ? rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') : (string)$v;
}

// ------------------------------------------------------------
// PDF
// ------------------------------------------------------------

const PDF_HELV_WIDTHS = [
    32 => 278, 33 => 278, 34 => 355, 35 => 556, 36 => 556, 37 => 889, 38 => 667, 39 => 191, 40 => 333, 41 => 333, 42 => 389, 43 => 584,
    44 => 278, 45 => 333, 46 => 278, 47 => 278, 48 => 556, 49 => 556, 50 => 556, 51 => 556, 52 => 556, 53 => 556, 54 => 556, 55 => 556,
    56 => 556, 57 => 556, 58 => 278, 59 => 278, 60 => 584, 61 => 584, 62 => 584, 63 => 556, 64 => 1015, 65 => 667, 66 => 667, 67 => 722,
    68 => 722, 69 => 667, 70 => 611, 71 => 778, 72 => 722, 73 => 278, 74 => 500, 75 => 667, 76 => 556, 77 => 833, 78 => 722, 79 => 778,
    80 => 667, 81 => 778, 82 => 722, 83 => 667, 84 => 611, 85 => 722, 86 => 667, 87 => 944, 88 => 667, 89 => 667, 90 => 611, 91 => 278,
    92 => 278, 93 => 278, 94 => 469, 95 => 556, 96 => 333, 97 => 556, 98 => 556, 99 => 500, 100 => 556, 101 => 556, 102 => 278, 103 => 556,
    104 => 556, 105 => 222, 106 => 222, 107 => 500, 108 => 222, 109 => 833, 110 => 556, 111 => 556, 112 => 556, 113 => 556, 114 => 333,
    115 => 500, 116 => 278, 117 => 556, 118 => 500, 119 => 722, 120 => 500, 121 => 500, 122 => 500, 123 => 334, 124 => 260, 125 => 334, 126 => 584,
];

/** UTF-8 -> Windows-1252 bytes (what the PDF's WinAnsiEncoding expects); unknown characters become '?'. */
function pdfEncode(string $s): string
{
    $s = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', ' ', $s);
    $out = @mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
    return $out === false ? preg_replace('/[^\x20-\x7E]/', '?', $s) : $out;
}

/** Width of a Windows-1252 string in points at the given size (bold is about 6% wider). */
function pdfTextWidth(string $s, float $size, bool $bold = false): float
{
    $w = 0;
    for ($i = 0, $n = strlen($s); $i < $n; $i++) { $w += PDF_HELV_WIDTHS[ord($s[$i])] ?? 556; }
    return $w * $size / 1000 * ($bold ? 1.06 : 1);
}

/** Breaks text into lines that fit $width; at most $maxLines (the last one ends with "..." when cut). */
function pdfWrap(string $text, float $width, float $size, int $maxLines, bool $bold = false): array
{
    $lines = []; $cur = '';
    foreach (preg_split('/\s+/', trim($text)) as $word) {
        if ($word === '') { continue; }
        while (pdfTextWidth($word, $size, $bold) > $width) {          // a word longer than the cell is split
            $k = strlen($word);
            while ($k > 1 && pdfTextWidth(substr($word, 0, $k), $size, $bold) > $width) { $k--; }
            if ($cur !== '') { $lines[] = $cur; $cur = ''; }
            $lines[] = substr($word, 0, $k);
            $word = substr($word, $k);
        }
        $try = $cur === '' ? $word : $cur . ' ' . $word;
        if (pdfTextWidth($try, $size, $bold) <= $width) { $cur = $try; } else { $lines[] = $cur; $cur = $word; }
    }
    if ($cur !== '' || !$lines) { $lines[] = $cur; }
    if (count($lines) > $maxLines) {
        $lines = array_slice($lines, 0, $maxLines);
        $last = rtrim($lines[$maxLines - 1]);
        while ($last !== '' && pdfTextWidth($last . '...', $size, $bold) > $width) { $last = substr($last, 0, -1); }
        $lines[$maxLines - 1] = $last . '...';
    }
    return $lines;
}

function pdfEsc(string $s): string
{
    return str_replace(['\\', '(', ')', "\r"], ['\\\\', '\\(', '\\)', ''], $s);
}

/** Builds the PDF file (string) for a report from buildReport(). */
function reportPdfBytes(array $rep): string
{
    $pageW = 595.28; $pageH = 841.89; $margin = 40.0; $size = 9.0; $lead = 12.0; $pad = 4.0;
    $usable = $pageW - 2 * $margin;
    $sumW = array_sum($rep['widths']) ?: 1;
    $colW = array_map(fn($w) => $usable * $w / $sumW, $rep['widths']);
    $ncol = count($rep['headers']);
    $numeric = [];
    foreach ($rep['headers'] as $c => $_) {
        $numeric[$c] = $rep['rows'] ? !array_filter($rep['rows'], fn($r) => !is_int($r[$c]) && !is_float($r[$c])) : false;
    }

    $pages = []; $cur = ''; $y = 0.0;
    $text = function (string $s, float $x, float $yy, float $sz, bool $bold = false) {
        return sprintf("BT /%s %.2f Tf %.2f %.2f Td (%s) Tj ET\n", $bold ? 'F2' : 'F1', $sz, $x, $yy, pdfEsc($s));
    };
    $startPage = function () use (&$cur, &$y, &$pages, $rep, $margin, $pageW, $pageH, $text, $colW, $ncol, $lead, $pad, $size) {
        if ($cur !== '') { $pages[] = $cur; }
        $cur = '';
        $y = $pageH - $margin;
        $cur .= "0.04 0.36 0.23 rg\n" . sprintf("%.2f %.2f %.2f %.2f re f\n", $margin, $y - 6, $pageW - 2 * $margin, 3) . "0 g\n";
        $y -= 26;
        $cur .= $text('Universiti Islam Selangor - Driver Scheduling and Management System', $margin, $y + 10, 8, false);
        $y -= 16;
        $cur .= $text(pdfEncode($rep['title']), $margin, $y, 15, true);
        $y -= 15;
        $cur .= $text(pdfEncode('Month: ' . date('F Y', strtotime($rep['month'] . '-01')) . '    Generated: ' . date('d M Y H:i')), $margin, $y, 9, false);
        $y -= 18;
        // table header
        $hl = array_map(fn($hd, $c) => pdfWrap(pdfEncode($hd), $colW[$c] - 2 * $pad, $size, 2, true), $rep['headers'], array_keys($rep['headers']));
        $h = max(array_map('count', $hl)) * $lead + 2 * $pad - 2;
        $cur .= "0.90 0.94 0.92 rg\n" . sprintf("%.2f %.2f %.2f %.2f re f\n", $margin, $y - $h, array_sum($colW), $h) . "0 g\n";
        $x = $margin;
        foreach ($hl as $c => $lines) {
            foreach ($lines as $k => $ln) { $cur .= $text($ln, $x + $pad, $y - $pad - $size - $k * $lead, $size, true); }
            $x += $colW[$c];
        }
        $y -= $h;
    };
    $drawRow = function (array $cells, bool $bold, bool $fill) use (&$cur, &$y, &$startPage, $colW, $ncol, $numeric, $lead, $pad, $size, $margin, $pageH, $text) {
        $cellLines = []; $maxL = 1;
        foreach ($cells as $c => $v) {
            $cellLines[$c] = pdfWrap(pdfEncode(reportCellText($v)), $colW[$c] - 2 * $pad, $size, 3, $bold);
            $maxL = max($maxL, count($cellLines[$c]));
        }
        $h = $maxL * $lead + 2 * $pad - 2;
        if ($y - $h < $margin + 24) { $startPage(); }
        if ($fill) { $cur .= "0.96 0.97 0.96 rg\n" . sprintf("%.2f %.2f %.2f %.2f re f\n", $margin, $y - $h, array_sum($colW), $h) . "0 g\n"; }
        $x = $margin;
        foreach ($cellLines as $c => $lines) {
            foreach ($lines as $k => $ln) {
                $tx = $numeric[$c] ? $x + $colW[$c] - $pad - pdfTextWidth($ln, $size, $bold) : $x + $pad;
                $cur .= $text($ln, $tx, $y - $pad - $size - $k * $lead, $size, $bold);
            }
            $x += $colW[$c];
        }
        $cur .= "0.8 G 0.5 w " . sprintf("%.2f %.2f m %.2f %.2f l S\n", $margin, $y - $h, $margin + array_sum($colW), $y - $h) . "0 G\n";
        $y -= $h;
    };

    $startPage();
    foreach ($rep['rows'] as $i => $row) { $drawRow($row, false, $i % 2 === 1); }
    if (!$rep['rows']) { $drawRow(array_merge(['No data for the selected month'], array_fill(0, $ncol - 1, '')), false, false); }
    $drawRow($rep['totals'], true, true);
    if ($rep['note'] !== '') {
        foreach (pdfWrap(pdfEncode($rep['note']), $pageW - 2 * $margin, 8, 4) as $ln) {
            if ($y < $margin + 36) { $startPage(); }
            $y -= 11;
            $cur .= $text($ln, $margin, $y, 8, false);
        }
    }
    $pages[] = $cur;

    // footers with the page count, then the objects
    $total = count($pages);
    $objs = [];                                                       // [id => body]
    $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $kids = [];
    $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
    $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
    $id = 5;
    foreach ($pages as $i => $stream) {
        $stream .= $text('Page ' . ($i + 1) . ' of ' . $total, $pageW - $margin - 60, 24, 8, false);
        $pid = $id++; $cid = $id++;
        $kids[] = "$pid 0 R";
        $objs[$pid] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents $cid 0 R >>";
        $objs[$cid] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
    }
    $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $total . ' >>';
    ksort($objs);

    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [];
    foreach ($objs as $n => $body) {
        $offsets[$n] = strlen($pdf);
        $pdf .= "$n 0 obj\n$body\nendobj\n";
    }
    $xref = strlen($pdf);
    $count = count($objs) + 1;
    $pdf .= "xref\n0 $count\n0000000000 65535 f \n";
    foreach ($offsets as $off) { $pdf .= sprintf("%010d 00000 n \n", $off); }
    $pdf .= "trailer\n<< /Size $count /Root 1 0 R /Info << /Title (" . pdfEsc(pdfEncode($rep['title'])) . ") /Producer (DSMS) >> >>\nstartxref\n$xref\n%%EOF\n";
    return $pdf;
}

function exportReportPdf(array $rep, string $type): never
{
    $pdf = reportPdfBytes($rep);
    startDownload('application/pdf', reportFileName($type, $rep['month'], 'pdf'), strlen($pdf));
    echo $pdf;
    exit();
}

// ------------------------------------------------------------
// Excel
// ------------------------------------------------------------

function xlsxText(string $s): string
{
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function xlsxCol(int $i): string          // 0 -> A
{
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) { $s = chr(65 + ($i - 1) % 26) . $s; }
    return $s;
}

/** Builds the .xlsx file (string), or null when ZipArchive is not available. */
function reportXlsxBytes(array $rep): ?string
{
    if (!class_exists('ZipArchive')) { return null; }
    $rows = [];
    $rows[] = [[$rep['title'], 1]];
    $rows[] = [['Month: ' . date('F Y', strtotime($rep['month'] . '-01')) . '    Generated: ' . date('d M Y H:i'), 0]];
    $rows[] = [];
    $rows[] = array_map(fn($h) => [$h, 1], $rep['headers']);
    foreach ($rep['rows'] as $r) { $rows[] = array_map(fn($v) => [$v, 0], $r); }
    $rows[] = array_map(fn($v) => [$v, 1], $rep['totals']);
    if ($rep['note'] !== '') { $rows[] = []; $rows[] = [[$rep['note'], 0]]; }

    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
           . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cols>';
    $sumW = array_sum($rep['widths']) ?: 1;
    foreach ($rep['widths'] as $c => $w) { $sheet .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . round(12 + $w / $sumW * 60, 1) . '" customWidth="1"/>'; }
    $sheet .= '</cols><sheetData>';
    foreach ($rows as $ri => $cells) {
        $sheet .= '<row r="' . ($ri + 1) . '">';
        foreach ($cells as $ci => [$v, $bold]) {
            $ref = xlsxCol($ci) . ($ri + 1);
            $st = $bold ? ' s="1"' : '';
            if (is_int($v) || is_float($v)) { $sheet .= "<c r=\"$ref\"$st><v>" . (is_float($v) ? rtrim(rtrim(sprintf('%.4F', $v), '0'), '.') : $v) . '</v></c>'; }
            elseif ($v === '' || $v === null) { $sheet .= "<c r=\"$ref\"$st/>"; }
            else { $sheet .= "<c r=\"$ref\"$st t=\"inlineStr\"><is><t xml:space=\"preserve\">" . xlsxText((string)$v) . '</t></is></c>'; }
        }
        $sheet .= '</row>';
    }
    $sheet .= '</sheetData></worksheet>';

    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
        'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
        'xl/worksheets/sheet1.xml' => $sheet,
    ];
    $tmp = tempnam(sys_get_temp_dir(), 'dsmsx');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) { @unlink($tmp); return null; }
    foreach ($files as $name => $xml) { $zip->addFromString($name, $xml); }
    $zip->close();
    $bytes = file_get_contents($tmp);
    @unlink($tmp);
    return $bytes === false ? null : $bytes;
}

function exportReportXlsx(array $rep, string $type): never
{
    $bytes = reportXlsxBytes($rep);
    if ($bytes !== null) {
        startDownload('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', reportFileName($type, $rep['month'], 'xlsx'), strlen($bytes));
        echo $bytes;
        exit();
    }
    // fallback: an HTML table that Excel opens
    $h = fn($s) => htmlspecialchars(reportCellText($s), ENT_QUOTES, 'UTF-8');
    $html = '<html><head><meta charset="UTF-8"></head><body><h3>' . $h($rep['title']) . ' - ' . $h($rep['month']) . '</h3><table border="1"><tr>';
    foreach ($rep['headers'] as $c) { $html .= '<th>' . $h($c) . '</th>'; }
    $html .= '</tr>';
    foreach (array_merge($rep['rows'], [$rep['totals']]) as $r) {
        $html .= '<tr>';
        foreach ($r as $v) { $html .= '<td>' . $h($v) . '</td>'; }
        $html .= '</tr>';
    }
    $html .= '</table></body></html>';
    startDownload('application/vnd.ms-excel', reportFileName($type, $rep['month'], 'xls'), strlen($html));
    echo $html;
    exit();
}
