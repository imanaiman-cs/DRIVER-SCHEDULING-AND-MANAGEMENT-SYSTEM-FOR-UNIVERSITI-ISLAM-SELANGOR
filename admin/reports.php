<?php
// ============================================================
// admin/reports.php  -  View Layer: ReportUI (SDD_CLASS_110) displayReportTypes(), displayReport(), downloadFile()
//   UC011 View Report + UC012 Export Report through ReportController
// Driver Workload, Driver Replacement and Vehicle Usage for a month; print, PDF (?export=pdf) and Excel (?export=xlsx).
// ============================================================
$current_page = 'reports.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
requireAdmin();

$report = new ReportController();
$types = ReportController::TYPES;
$type  = array_key_exists($_GET['type'] ?? '', $types) ? $_GET['type'] : 'workload';
$month = (string)($_GET['month'] ?? '');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) { $month = date('Y-m'); }
$rep = $report->generateReport($type, $month);

$export = $_GET['export'] ?? '';
try {
    if ($export === 'pdf' || $export === 'xlsx') { $report->exportReport($type, $month, $export); }
} catch (Throwable $e) {
    error_log('report export: ' . $e->getMessage());
    setFlash('danger', 'The report file cannot be generated. Please try again later.');
}

$h = fn($s) => htmlspecialchars(reportCellText($s), ENT_QUOTES, 'UTF-8');
$q = http_build_query(['type' => $type, 'month' => $month]);
adminPageStart('Report');
?>
    <h1 class="text-2xl font-extrabold tracking-tight mb-1 no-print"><i class="fas fa-chart-column me-2 text-[0.8em] text-primary"></i>Report</h1>
    <p class="text-base-content/60 text-sm mb-4 no-print">Choose a report and a month. You can print it or export it as PDF or Excel.</p>
    <form method="get" class="grid-12 grid grid-cols-12 gap-2 items-end mb-4 no-print">
        <div class="col-span-12 md:col-span-5"><label class="mb-1 block text-sm font-medium" for="type">Report</label>
            <select class="select w-full" id="type" name="type"><?php foreach ($types as $k => $lbl): ?><option value="<?= $k ?>" <?= $k === $type ? 'selected' : '' ?>><?= $h($lbl) ?></option><?php endforeach; ?></select></div>
        <div class="col-span-6 md:col-span-3"><label class="mb-1 block text-sm font-medium" for="month">Month</label>
            <input type="month" class="input w-full" id="month" name="month" value="<?= $h($month) ?>" required></div>
        <div class="col-span-6 md:col-span-2"><button class="btn btn-primary w-full" type="submit"><i class="fas fa-table me-1"></i>Show</button></div>
    </form>

    <div class="card border border-base-300 bg-base-100"><div class="card-body">
        <div class="flex flex-wrap justify-between items-start gap-2 mb-4">
            <div><h2 class="text-lg font-bold mb-0"><?= $h($rep['title']) ?></h2><div class="text-base-content/60"><?= $h(date('F Y', strtotime($month . '-01'))) ?></div></div>
            <div class="flex flex-wrap gap-2 no-print">
                <button type="button" class="btn btn-sm btn-outline" onclick="window.print()"><i class="fas fa-print me-1"></i>Print</button>
                <a class="btn btn-sm btn-outline btn-error" href="?<?= $q ?>&amp;export=pdf"><i class="fas fa-file-pdf me-1"></i>PDF</a>
                <a class="btn btn-sm btn-outline btn-success" href="?<?= $q ?>&amp;export=xlsx"><i class="fas fa-file-excel me-1"></i>Excel</a>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="table table-zebra align-middle mb-0">
                <thead class=""><tr>
                    <?php foreach ($rep['headers'] as $c => $hd): ?><th class="<?= $c > 0 && is_numeric($rep['totals'][$c] ?? null) ? 'text-end' : '' ?>"><?= $h($hd) ?></th><?php endforeach; ?>
                </tr></thead>
                <tbody>
                <?php foreach ($rep['rows'] as $row): ?>
                    <tr><?php foreach ($row as $c => $v): ?><td class="<?= is_int($v) || is_float($v) ? 'text-end' : '' ?>"><?= $h($v) ?></td><?php endforeach; ?></tr>
                <?php endforeach; ?>
                <?php if (!$rep['rows']): ?><tr><td colspan="<?= count($rep['headers']) ?>" class="text-center text-base-content/60 py-6">No record is found for the selected month.</td></tr><?php endif; ?>
                </tbody>
                <tfoot><tr class="font-bold"><?php foreach ($rep['totals'] as $c => $v): ?><td class="<?= is_int($v) || is_float($v) ? 'text-end' : '' ?>"><?= $h($v) ?></td><?php endforeach; ?></tr></tfoot>
            </table>
        </div>
        <?php if ($rep['note']): ?><p class="text-sm text-base-content/60 mt-4 mb-0"><?= $h($rep['note']) ?></p><?php endif; ?>
    </div></div>
<?php adminPageEnd(); ?>
