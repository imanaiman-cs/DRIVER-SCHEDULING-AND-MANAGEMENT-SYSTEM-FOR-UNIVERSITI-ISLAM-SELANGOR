<?php
/**
 * DriversController - UC016 Manage Driver.
 * The driver ACCOUNT is created under Users (role = driver); this page holds what
 * the scheduler needs: group, licence classes and leave / MC.
 */
class DriversController extends Controller
{
    public function index(): void
    {
        $this->adminOnly();
        $this->view('drivers/index', ['title' => 'Drivers', 'rows' => (new Driver())->all()]);
    }

    public function edit(): void
    {
        $this->adminOnly();
        $model  = new Driver();
        $driver = $model->find($this->getInt('id'));
        if (!$driver) {
            $this->flashRedirect('danger', 'Driver not found.', 'drivers/index');
        }
        $this->view('drivers/edit', [
            'title'    => 'Driver: ' . $driver['name'],
            'driver'   => $driver,
            'licences' => $model->licences((int)$driver['id']),
            'leaves'   => $model->leaves((int)$driver['id']),
        ]);
    }

    public function save(): void
    {
        $this->adminOnly();
        $this->requirePost();
        $model = new Driver();
        $id    = $this->postInt('id');
        $driver = $model->find($id);
        if (!$driver) {
            $this->flashRedirect('danger', 'Driver not found.', 'drivers/index');
        }
        $group = $this->post('driver_group');
        if (!isset(Driver::GROUPS[$group])) {
            $this->flashRedirect('danger', 'Please choose a valid driver group.', 'drivers/edit', ['id' => $id]);
        }
        // Only accept known licence classes, whatever the browser sent.
        $licences = array_values(array_intersect(Driver::LICENCES, (array)($_POST['licences'] ?? [])));

        $model->saveProfile($id, $group, $licences);
        (new AuditLog())->record('driver_update', null, "Driver {$driver['staff_id']}: group $group, licences " . ($licences ? implode('/', $licences) : 'none'));
        $this->flashRedirect('success', 'Driver profile saved.', 'drivers/edit', ['id' => $id]);
    }

    /** Record leave / MC. Trips already assigned in that period are listed as a warning (not blocked). */
    public function addLeave(): void
    {
        $this->adminOnly();
        $this->requirePost();
        $model  = new Driver();
        $id     = $this->postInt('id');
        $driver = $model->find($id);
        if (!$driver) {
            $this->flashRedirect('danger', 'Driver not found.', 'drivers/index');
        }
        $start = $this->post('start_date');
        $end   = $this->post('end_date');
        $type  = $this->post('type') === 'mc' ? 'mc' : 'leave';
        $note  = $this->post('note');

        if (!self::validDate($start) || !self::validDate($end) || $end < $start) {
            $this->flashRedirect('danger', 'Please enter a valid start date and an end date that is not before it.', 'drivers/edit', ['id' => $id]);
        }
        $model->addLeave($id, $start, $end, $type, $note !== '' ? mb_substr($note, 0, 255) : null);
        (new AuditLog())->record('driver_leave', null, "Driver {$driver['staff_id']}: $type $start to $end");

        $affected = $model->affectedLegs($id, $start, $end);
        if ($affected) {
            $list = implode('; ', array_map(
                fn($a) => "Request #{$a['request_id']} ({$a['destination_name']}, " . fmt_datetime($a['start_datetime']) . ')',
                $affected
            ));
            $this->flashRedirect('warning', "Leave saved, but this driver is already assigned to: $list. Please change the driver.", 'drivers/edit', ['id' => $id]);
        }
        $this->flashRedirect('success', 'Leave saved.', 'drivers/edit', ['id' => $id]);
    }

    public function deleteLeave(): void
    {
        $this->adminOnly();
        $this->requirePost();
        $id = $this->postInt('id');
        (new Driver())->deleteLeave($this->postInt('leave_id'), $id);
        $this->flashRedirect('success', 'Leave removed.', 'drivers/edit', ['id' => $id]);
    }

    public static function validDate(string $d): bool
    {
        $dt = DateTime::createFromFormat('Y-m-d', $d);
        return $dt && $dt->format('Y-m-d') === $d;
    }
}
