<?php
/**
 * ReportsController - UC018 Monthly report: on screen, printable, and as a PDF (Dompdf).
 */
class ReportsController extends Controller
{
    public function index(): void
    {
        $this->adminOnly();
        $month = $this->month();
        $this->view('reports/index', [
            'title' => 'Monthly report', 'report' => (new Report())->build($month), 'month' => $month,
            'prev' => date('Y-m', strtotime("$month-01 -1 month")), 'next' => date('Y-m', strtotime("$month-01 +1 month")),
        ]);
    }

    public function pdf(): void
    {
        $this->adminOnly();
        if (!class_exists('Dompdf\\Dompdf')) {
            $this->flashRedirect('danger', 'The PDF library is not installed. Run "composer install" in the dsms folder.', 'reports/index', ['month' => $this->month()]);
        }
        // A PDF is binary: one stray warning printed before it (Dompdf triggers PHP 8.4 "deprecated"
        // notices on its first run) would corrupt the whole file. So nothing may be printed here.
        ini_set('display_errors', '0');
        $month  = $this->month();
        $report = (new Report())->build($month);

        ob_start();
        View::render('reports/pdf', ['title' => 'Monthly report ' . $month, 'report' => $report, 'generatedBy' => Auth::user()['name']], '');
        $html = ob_get_clean();

        $options = new Dompdf\Options();
        $options->set('isRemoteEnabled', false);          // the PDF never fetches anything from the internet
        $options->set('isPhpEnabled', false);
        $options->set('chroot', APP_ROOT);
        $pdf = new Dompdf\Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();

        (new AuditLog())->record('report_pdf', null, "Monthly report $month downloaded");
        while (ob_get_level() > 0) { ob_end_clean(); }      // drop anything that was printed before
        $pdf->stream("DSMS_Report_$month.pdf", ['Attachment' => true]);
        exit;
    }

    private function month(): string
    {
        $m = (string)($_GET['month'] ?? '');
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) ? $m : date('Y-m');
    }
}
