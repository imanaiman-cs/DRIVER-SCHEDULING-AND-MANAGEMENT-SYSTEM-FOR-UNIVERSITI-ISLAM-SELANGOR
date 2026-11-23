<?php
// ============================================================
// UIS Driver Scheduling and Management System
// help.php  -  short guide for the signed-in role
// ============================================================
$current_page = 'help.php';
require_once 'config/database.php';
require_once 'includes/admin_page.php';
requireLogin();
$role = $_SESSION['role'] ?? 'staff';

$statuses = [
    'pending' => 'Waiting for the Head of Department. After two days without a decision it becomes Lapsed.',
    'approved' => 'The Head of Department approved. The Administrative Assistant now chooses the driver(s).',
    'assigned' => 'A driver was chosen. The Head of Admin must approve the assignment.',
    'processed' => 'Approved by the Head of Admin. Driver and vehicle are final; the driver and you are told.',
    'rejected' => 'The Head of Department rejected the request. The reason is shown.',
    'cancelled' => 'Cancelled (the reason is shown). The vehicle is released.',
    'lapsed' => 'Not approved within two days. The vehicle is released.',
];
$steps = [
    'staff' => ['Open <strong>Request a Vehicle</strong>. Enter departure and return (date and time) and the number of passengers.',
                'Choose one of the vehicles that are free in that period. Fill in the destination (name and full address), purpose, passenger names, phone number and waiting location. Attach PDF, JPG or PNG files (5 MB each) if you have them.',
                'A trip less than 2 days away asks you to confirm. A trip less than 1 day away cannot be requested online: use the paper form BPTK/BPI/2025/PIN.5.',
                'Follow the status in <strong>My Requests</strong>. You can cancel your own request before the trip starts (the reason is optional).'],
    'driver' => ['Open <strong>My Tasks</strong>: your upcoming tasks in date order, and the Past Tasks tab. You only see confirmed tasks.',
                 'Each task shows the date, time, vehicle, full destination address, waiting location, purpose and the officer name and phone number.',
                 'You do not confirm tasks or update the trip status. Like everyone you can also request a vehicle for yourself.'],
    'supervisor' => ['Open <strong>Vehicle Approvals</strong>. You only see requests of your own PTJ.',
                     'Approve, or reject with a reason. You can add a comment, and cancel a request (reason required).',
                     'A request from a Head of Department is approved by his own head.'],
    'admin' => ['<strong>Vehicle Requests</strong>: Approved requests need a driver. Press <em>Assign driver</em>: the recommendation (fewer tasks and fewer tasks outside working hours rank higher) is shown first; you choose the outbound driver, then use the same driver and vehicle for the return trip or choose a separate driver and vehicle.',
                'The Head of Admin approves every assignment. A Confirmed trip can have a driver replaced (reason required) until the task starts.',
                '<strong>Approval reminders</strong> are automatic: a request still waiting for the Head of Department after 24 hours is reminded once (Head of Department and staff member, in the system and by e-mail). The request shows when the reminder went out.',
                '<strong>Internal Tasks</strong>, <strong>Welfare Duty</strong> and the <strong>Schedule Calendar</strong>; <strong>Drivers</strong> and <strong>Vehicles</strong> (never deleted, only deactivated); <strong>Reports</strong> by month (screen, print, PDF, Excel).'],
];
$steps['head_admin'] = array_merge($steps['admin'], ['<strong>Approve Assignments</strong> (Head of Admin only): approve or reject each assignment, also the ones you made yourself. A rejection needs a reason.']);
if (uisLang() === 'ms') {                         // the steps hold markup, so BM is written out here in full
    $steps = [
        'staff' => ['Buka <strong>Mohon Kenderaan</strong>. Masukkan masa pergi dan balik (tarikh dan masa) serta bilangan penumpang.',
                    'Pilih salah satu kenderaan yang bebas dalam tempoh itu. Isi destinasi (nama dan alamat penuh), tujuan, nama penumpang, nombor telefon dan lokasi menunggu. Lampirkan fail PDF, JPG atau PNG (5 MB setiap satu) jika ada.',
                    'Perjalanan yang kurang daripada 2 hari lagi akan meminta pengesahan anda. Perjalanan yang kurang daripada 1 hari lagi tidak boleh dimohon dalam talian: gunakan borang kertas BPTK/BPI/2025/PIN.5.',
                    'Ikuti status dalam <strong>Permohonan Saya</strong>. Anda boleh membatalkan permohonan sendiri sebelum perjalanan bermula (sebab tidak wajib).'],
        'driver' => ['Buka <strong>Tugasan Saya</strong>: tugasan akan datang mengikut tarikh, dan tab Tugasan Lepas. Anda hanya melihat tugasan yang telah disahkan.',
                     'Setiap tugasan menunjukkan tarikh, masa, kenderaan, alamat penuh destinasi, lokasi menunggu, tujuan serta nama dan nombor telefon pegawai.',
                     'Anda tidak perlu mengesahkan tugasan atau mengemas kini status perjalanan. Seperti semua orang, anda juga boleh memohon kenderaan untuk diri sendiri.'],
        'supervisor' => ['Buka <strong>Kelulusan Kenderaan</strong>. Anda hanya melihat permohonan PTJ anda sendiri.',
                         'Luluskan, atau tolak dengan sebab. Anda boleh menambah komen, dan membatalkan permohonan (sebab wajib).',
                         'Permohonan daripada Ketua Jabatan diluluskan oleh ketuanya sendiri.'],
        'admin' => ['<strong>Permohonan Kenderaan</strong>: permohonan yang Diluluskan memerlukan pemandu. Tekan <em>Tugaskan pemandu</em>: cadangan (kurang tugasan dan kurang tugasan di luar waktu bekerja berada di atas) dipaparkan dahulu; anda memilih pemandu perjalanan pergi, kemudian guna pemandu dan kenderaan yang sama untuk perjalanan balik atau pilih pemandu dan kenderaan lain.',
                    'Ketua Pentadbiran meluluskan setiap penugasan. Pemandu bagi perjalanan Disahkan boleh ditukar (sebab wajib) sehingga tugasan bermula.',
                    '<strong>Peringatan Kelulusan</strong>: hantar peringatan secara manual untuk permohonan yang menunggu Ketua Jabatan.',
                    '<strong>Tugasan Dalaman</strong>, <strong>Tugas Kebajikan</strong> dan <strong>Kalendar Jadual</strong>; <strong>Pemandu</strong> dan <strong>Kenderaan</strong> (tidak pernah dipadam, hanya dinyahaktifkan); <strong>Laporan</strong> bulanan (skrin, cetak, PDF, Excel).'],
    ];
    $steps['head_admin'] = array_merge($steps['admin'], ['<strong>Lulus Penugasan</strong> (Ketua Pentadbiran sahaja): luluskan atau tolak setiap penugasan, termasuk yang anda buat sendiri. Penolakan memerlukan sebab.']);
}
adminPageStart('Help');
?>
    <h1 class="text-2xl font-extrabold tracking-tight mb-1"><i class="fas fa-circle-question me-2 text-[0.8em] text-primary"></i>Help</h1>
    <p class="text-base-content/60 text-sm">You are signed in as <strong><?= htmlspecialchars(roleLabel($role)) ?></strong>. Every role is also Staff and can request a vehicle.</p>
    <div class="card border border-base-300 bg-base-100 mb-4"><div class="card-body">
        <h2 class="text-base font-bold">What to do</h2>
        <ol class="mb-0"><?php foreach ($steps[$role] ?? $steps['staff'] as $s): ?><li class="mb-1"><?= $s ?></li><?php endforeach; ?></ol>
    </div></div>
    <div class="card border border-base-300 bg-base-100"><div class="card-body">
        <h2 class="text-base font-bold">Request statuses</h2>
        <table class="table mb-0"><tbody>
        <?php foreach ($statuses as $k => $text): ?>
            <tr><td class="whitespace-nowrap"><?= requestStatusBadge($k) ?></td><td><?= htmlspecialchars($text) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
    </div></div>
<?php adminPageEnd(); ?>
