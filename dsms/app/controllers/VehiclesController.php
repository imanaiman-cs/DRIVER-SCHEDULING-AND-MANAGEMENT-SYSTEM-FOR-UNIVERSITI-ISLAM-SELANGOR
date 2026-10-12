<?php
/**
 * VehiclesController - UC017 Manage Vehicle.
 * No hard delete: a request always points at its vehicle, so a vehicle is set to Inactive instead.
 * Maintenance periods block booking (R4); trips already booked in that period are shown as a warning.
 */
class VehiclesController extends Controller
{
    public function index(): void
    {
        $this->adminOnly();
        $this->view('vehicles/index', ['title' => 'Vehicles', 'rows' => (new Vehicle())->all()]);
    }

    public function edit(): void
    {
        $this->adminOnly();
        $model   = new Vehicle();
        $id      = $this->getInt('id');
        $vehicle = $id ? $model->find($id) : null;
        if ($id && !$vehicle) {
            $this->flashRedirect('danger', 'Vehicle not found.', 'vehicles/index');
        }
        $this->form($vehicle, $vehicle ?? [], [], $id ? $model->maintenance($id) : []);
    }

    private function form(?array $vehicle, array $values, array $errors, array $maintenance = []): void
    {
        $this->view('vehicles/edit', [
            'title' => $vehicle ? 'Vehicle: ' . $vehicle['reg_no'] : 'Add vehicle',
            'vehicle' => $vehicle, 'v' => $values, 'errors' => $errors, 'maintenance' => $maintenance,
        ]);
    }

    public function save(): void
    {
        $this->adminOnly();
        $this->requirePost();
        $model = new Vehicle();
        $id    = $this->postInt('id');
        $existing = $id ? $model->find($id) : null;
        if ($id && !$existing) {
            $this->flashRedirect('danger', 'Vehicle not found.', 'vehicles/index');
        }

        $d = [
            'reg_no'          => strtoupper(preg_replace('/\s+/', ' ', $this->post('reg_no'))),
            'brand'           => $this->post('brand'),
            'model'           => $this->post('model'),
            'vehicle_type'    => $this->post('vehicle_type'),
            'capacity'        => $this->postInt('capacity'),
            'road_tax_expiry' => $this->post('road_tax_expiry') ?: null,
            'status'          => $this->post('status') === 'Inactive' ? 'Inactive' : 'Available',
        ];
        $errors = [];
        if ($d['reg_no'] === '' || !preg_match('/^[A-Z0-9 ]{2,20}$/', $d['reg_no'])) { $errors['reg_no'] = 'Enter a registration number (letters, numbers and spaces).'; }
        elseif ($model->regTaken($d['reg_no'], $id)) { $errors['reg_no'] = 'This registration number already exists.'; }
        if ($d['brand'] === '') { $errors['brand'] = 'Brand is required.'; }
        if ($d['model'] === '') { $errors['model'] = 'Model is required.'; }
        if (!in_array($d['vehicle_type'], Vehicle::TYPES, true)) { $errors['vehicle_type'] = 'Choose a vehicle type.'; }
        if ($d['capacity'] < 1 || $d['capacity'] > 100) { $errors['capacity'] = 'Capacity must be between 1 and 100.'; }
        if ($d['road_tax_expiry'] !== null && !DriversController::validDate($d['road_tax_expiry'])) { $errors['road_tax_expiry'] = 'Invalid date.'; }

        if ($errors) {
            $this->form($existing, $d + ['id' => $id], $errors, $id ? $model->maintenance($id) : []);
            return;
        }

        $newId = $model->save($id, $d);
        (new AuditLog())->record($existing ? 'vehicle_update' : 'vehicle_create', null, "Vehicle {$d['reg_no']} ({$d['status']})");

        // Taking a vehicle out of service while trips still need it: tell the admin.
        if ($existing && $d['status'] === 'Inactive' && $existing['status'] !== 'Inactive') {
            $open = $model->affectedRequests($newId, date('Y-m-d'), '9999-12-31');
            if ($open) {
                $ids = implode(', ', array_map(fn($r) => '#' . $r['id'], $open));
                $this->flashRedirect('warning', "Vehicle set to Inactive, but these requests still use it: $ids. Please review them.", 'vehicles/edit', ['id' => $newId]);
            }
        }
        $this->flashRedirect('success', 'Vehicle saved.', 'vehicles/edit', ['id' => $newId]);
    }

    public function addMaintenance(): void
    {
        $this->adminOnly();
        $this->requirePost();
        $model = new Vehicle();
        $id = $this->postInt('id');
        $vehicle = $model->find($id);
        if (!$vehicle) {
            $this->flashRedirect('danger', 'Vehicle not found.', 'vehicles/index');
        }
        $start = $this->post('start_date');
        $end   = $this->post('end_date');
        if (!DriversController::validDate($start) || !DriversController::validDate($end) || $end < $start) {
            $this->flashRedirect('danger', 'Please enter a valid start date and an end date that is not before it.', 'vehicles/edit', ['id' => $id]);
        }
        $workshop = $this->post('workshop');
        $note     = $this->post('note');
        $model->addMaintenance($id, $start, $end, $workshop !== '' ? mb_substr($workshop, 0, 120) : null, $note !== '' ? mb_substr($note, 0, 255) : null);
        (new AuditLog())->record('vehicle_maintenance', null, "Vehicle {$vehicle['reg_no']}: maintenance $start to $end");

        $affected = $model->affectedRequests($id, $start, $end);
        if ($affected) {
            $list = implode('; ', array_map(fn($r) => "Request #{$r['id']} ({$r['destination_name']}, " . fmt_datetime($r['departure_datetime']) . ", {$r['status']})", $affected));
            $this->flashRedirect('warning', "Maintenance saved, but these requests fall in that period: $list. Please contact the requesters or change the vehicle.", 'vehicles/edit', ['id' => $id]);
        }
        $this->flashRedirect('success', 'Maintenance period saved.', 'vehicles/edit', ['id' => $id]);
    }

    public function deleteMaintenance(): void
    {
        $this->adminOnly();
        $this->requirePost();
        $id = $this->postInt('id');
        (new Vehicle())->deleteMaintenance($this->postInt('maintenance_id'), $id);
        $this->flashRedirect('success', 'Maintenance period removed.', 'vehicles/edit', ['id' => $id]);
    }
}
