<?php /** @var array $report @var string $generatedBy */ ?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title>DSMS monthly report</title>
<style>
    @page { margin: 18mm 14mm; }
    body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 9pt; color: #111; }
    .rep-title { border-bottom: 2px solid #111; padding-bottom: 6px; margin-bottom: 10px; }
    .rep-title .org { font-size: 12pt; font-weight: bold; }
    .rep-h { font-size: 10.5pt; margin: 14px 0 4px; border-bottom: 1px solid #999; }
    .rep-note { font-size: 8pt; color: #555; margin: 2px 0 4px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
    th, td { border: 1px solid #999; padding: 3px 5px; text-align: left; vertical-align: top; }
    th { background: #e8e8e8; }
    .foot { margin-top: 14px; font-size: 8pt; color: #555; }
</style></head><body>
<div class="rep-title">
    <div class="org"><?= e($config['org_name']) ?></div>
    <div>Monthly vehicle and driver report &middot; <?= e(date('F Y', strtotime($report['month'] . '-01'))) ?></div>
</div>
<?php include __DIR__ . '/_tables.php'; ?>
<div class="foot">Generated on <?= e(date('d M Y, H:i')) ?> by <?= e($generatedBy) ?> &middot; DSMS</div>
</body></html>
