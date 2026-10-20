<?php
// ============================================================
// admin/reports.php  -  Reports (SRS UC031 Generate, UC032 Export)
// Monthly report by type and month; on screen, print, PDF (?export=pdf) and Excel (?export=xlsx).
// ============================================================
$current_page = 'reports.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
require_once '../includes/report_export.php';
requireAdmin();

$types = reportTypes();
$type  = array_key_exists($_GET['type'] ?? '', $types) ? $_GET['type'] : 'tasks';
$month = (string)($_GET['month'] ?? '');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) { $month = date('Y-m'); }
$rep = buildReport($conn, $type, $month);

$export = $_GET['export'] ?? '';
if ($export === 'pdf')  { exportReportPdf($rep, $type); }
if ($export === 'xlsx') { exportReportXlsx($rep, $type); }

$h = fn($s) => htmlspecialchars(reportCellText($s), ENT_QUOTES, 'UTF-8');
$q = http_build_query(['type' => $type, 'month' => $month]);
adminPageStart('Reports');
?>
    <h4 class="fw-bold mb-1 no-print"><i class="fas fa-chart-column text-success me-2"></i>Reports</h4>
    <p class="text-muted small mb-3 no-print">Choose a report and a month. You can print it or export it as PDF or Excel.</p>
    <form method="get" class="row g-2 align-items-end mb-3 no-print">
        <div class="col-12 col-md-5"><label class="form-label fw-semibold" for="type">Report</label>
            <select class="form-select" id="type" name="type"><?php foreach ($types as $k => $lbl): ?><option value="<?= $k ?>" <?= $k === $type ? 'selected' : '' ?>><?= $h($lbl) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><label class="form-label fw-semibold" for="month">Month</label>
            <input type="month" class="form-control" id="month" name="month" value="<?= $h($month) ?>" required></div>
        <div class="col-6 col-md-2"><button class="btn btn-uis-primary w-100" type="submit"><i class="fas fa-table me-1"></i>Show</button></div>
    </form>

    <div class="card border-0 shadow-sm"><div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
            <div><h5 class="fw-bold mb-0"><?= $h($rep['title']) ?></h5><div class="text-muted"><?= $h(date('F Y', strtotime($month . '-01'))) ?></div></div>
            <div class="d-flex flex-wrap gap-2 no-print">
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="fas fa-print me-1"></i>Print</button>
                <a class="btn btn-sm btn-outline-danger" href="?<?= $q ?>&amp;export=pdf"><i class="fas fa-file-pdf me-1"></i>PDF</a>
                <a class="btn btn-sm btn-outline-success" href="?<?= $q ?>&amp;export=xlsx"><i class="fas fa-file-excel me-1"></i>Excel</a>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle mb-0">
                <thead class="table-light"><tr>
                    <?php foreach ($rep['headers'] as $c => $hd): ?><th class="<?= $c > 0 && is_numeric($rep['totals'][$c] ?? null) ? 'text-end' : '' ?>"><?= $h($hd) ?></th><?php endforeach; ?>
                </tr></thead>
                <tbody>
                <?php foreach ($rep['rows'] as $row): ?>
                    <tr><?php foreach ($row as $c => $v): ?><td class="<?= is_int($v) || is_float($v) ? 'text-end' : '' ?>"><?= $h($v) ?></td><?php endforeach; ?></tr>
                <?php endforeach; ?>
                <?php if (!$rep['rows']): ?><tr><td colspan="<?= count($rep['headers']) ?>" class="text-center text-muted py-4">No data for this month.</td></tr><?php endif; ?>
                </tbody>
                <tfoot><tr class="table-light fw-bold"><?php foreach ($rep['totals'] as $c => $v): ?><td class="<?= is_int($v) || is_float($v) ? 'text-end' : '' ?>"><?= $h($v) ?></td><?php endforeach; ?></tr></tfoot>
            </table>
        </div>
        <?php if ($rep['note']): ?><p class="small text-muted mt-3 mb-0"><?= $h($rep['note']) ?></p><?php endif; ?>
    </div></div>
<?php adminPageEnd(); ?>
