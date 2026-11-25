<?php
// ============================================================
// SDD_CLASS_202 - VehicleController (Domain Layer, SDD_PKG_200)
// Rules for adding, viewing, updating and deactivating vehicles (UC002). A vehicle with upcoming bookings cannot go
// Under Maintenance or be deactivated until each booking is moved to another vehicle (UC002C 3.2, UC002D 3.1).
// ============================================================

class VehicleController
{
    public function listVehicles(array $filter = []): array
    {
        return Vehicle::findAll($filter);
    }

    public function getVehicle(int $vehicleID): ?array
    {
        return Vehicle::findByID($vehicleID);
    }

    /** UC002A: checks the plate number is unique and adds the vehicle (Active). */
    public function addVehicle(array $f): array
    {
        $errors = [];
        $plateNo = strtoupper(preg_replace('/\s+/', ' ', trim((string)($f['plate_no'] ?? ''))));
        $d = ['plate_no' => $plateNo, 'type' => (string)($f['type'] ?? ''), 'brand' => trim((string)($f['brand'] ?? '')), 'model' => trim((string)($f['model'] ?? '')),
              'fuel_type' => (string)($f['fuel_type'] ?? ''), 'capacity' => (int)($f['capacity'] ?? 0), 'licence_required' => (string)($f['licence_required'] ?? ''),
              'current_mileage' => trim((string)($f['current_mileage'] ?? '')), 'fuel_level' => (string)($f['fuel_level'] ?? '')];
        if ($plateNo === '' || mb_strlen($plateNo) > 15) { $errors['plate_no'] = 'Enter the plate number.'; }
        if (!in_array($d['type'], Vehicle::TYPES, true)) { $errors['type'] = 'Select the vehicle type.'; }
        if ($d['brand'] === '') { $errors['brand'] = 'Enter the brand.'; }
        if ($d['model'] === '') { $errors['model'] = 'Enter the model.'; }
        if (!in_array($d['fuel_type'], Vehicle::FUEL_TYPES, true)) { $errors['fuel_type'] = 'Select the fuel type.'; }
        if ($d['capacity'] < 1 || $d['capacity'] > 100) { $errors['capacity'] = 'Enter the seating capacity.'; }
        if (!isset(Driver::LICENCE_CLASSES[$d['licence_required']])) { $errors['licence_required'] = 'Select the required licence class.'; }
        if ($d['current_mileage'] === '' || !ctype_digit($d['current_mileage'])) { $errors['current_mileage'] = 'Enter the current mileage.'; }
        if (!in_array($d['fuel_level'], Vehicle::FUEL_LEVELS, true)) { $errors['fuel_level'] = 'Select the current fuel level.'; }
        if ($errors) { return wfFail('Please fill in all required fields.', ['errors' => $errors]); }
        if (Vehicle::findByPlate($plateNo)) { return wfFail('The vehicle already exists.', ['errors' => ['plate_no' => 'This plate number is already registered.']]); }
        $id = Vehicle::create($d);
        return wfOk("The vehicle $plateNo was added with the Active status.", ['vehicle_id' => $id]);
    }

    /** UC002C: updates the current fuel level, current mileage and status (Active or Under Maintenance). */
    public function updateVehicle(int $vehicleID, array $f): array
    {
        $v = Vehicle::findByID($vehicleID);
        if (!$v || $v['status'] === Vehicle::INACTIVE) { return wfFail('Only an active vehicle can be updated.'); }
        $errors = [];
        $mil = trim((string)($f['current_mileage'] ?? '')); $lvl = (string)($f['fuel_level'] ?? ''); $st = (string)($f['status'] ?? '');
        if ($mil === '' || !ctype_digit($mil)) { $errors['current_mileage'] = 'Enter the current mileage.'; }
        elseif ((int)$mil < (int)$v['current_mileage']) { $errors['current_mileage'] = 'The mileage is not valid: it is lower than the current mileage (' . number_format((int)$v['current_mileage']) . ' km).'; }
        if (!in_array($lvl, Vehicle::FUEL_LEVELS, true)) { $errors['fuel_level'] = 'Select the fuel level.'; }
        if (!in_array($st, [Vehicle::ACTIVE, Vehicle::MAINTENANCE], true)) { $errors['status'] = 'Select the status.'; }
        if ($errors) { return wfFail('Please correct the highlighted fields.', ['errors' => $errors]); }
        if ($st === Vehicle::MAINTENANCE && $v['status'] !== Vehicle::MAINTENANCE && ($b = $this->blockingBookings($v))) {
            return wfFail('The vehicle has ' . count($b) . ' upcoming booking(s). Assign another vehicle to each booking first.', ['bookings' => $b]);
        }
        Vehicle::update($vehicleID, ['current_mileage' => (int)$mil, 'fuel_level' => $lvl, 'status' => $st]);
        return wfOk('The vehicle ' . $v['plate_no'] . ' was updated.');
    }

    /** UC002D: sets the vehicle to Inactive if it has no future booking. */
    public function deactivateVehicle(int $vehicleID): array
    {
        $v = Vehicle::findByID($vehicleID);
        if (!$v || $v['status'] === Vehicle::INACTIVE) { return wfFail('The vehicle is already inactive.'); }
        if ($b = $this->blockingBookings($v)) {
            return wfFail('The vehicle has ' . count($b) . ' upcoming booking(s). Assign another vehicle to each booking first.', ['bookings' => $b]);
        }
        Vehicle::deactivate($vehicleID);
        return wfOk('The vehicle ' . $v['plate_no'] . ' was deactivated. It is no longer offered when a request is submitted.');
    }

    /**
     * Upcoming bookings of a vehicle: reservations that hold it, and internal tasks and welfare days that use it.
     * Each: kind (reservation|task), id, start, end, label, link.
     */
    public function upcomingBookings(int $vehicleID): array
    {
        $out = [];
        foreach (VehicleReservation::findUpcomingByVehicle($vehicleID) as $r) {
            $out[] = ['kind' => 'reservation', 'id' => (int)$r['reservation_id'], 'start' => $r['start_datetime'], 'end' => $r['end_datetime'], 'type' => $r['vehicle_type'],
                      'label' => 'Request #' . $r['request_id'] . ' (' . $r['request_status'] . '), ' . $r['requester_name'] . ' to ' . $r['destination'], 'link' => 'staff/request.php?id=' . $r['request_id']];
        }
        foreach (Task::findUpcomingByVehicle($vehicleID) as $t) {
            $out[] = ['kind' => 'task', 'id' => (int)$t['task_id'], 'start' => $t['start_datetime'], 'end' => $t['end_datetime'], 'source' => $t['task_source'],
                      'label' => ($t['task_source'] === Task::WELFARE ? 'Welfare duty' : $t['task_type']) . ($t['description'] ? ': ' . $t['description'] : ''), 'link' => 'admin/task.php?id=' . $t['task_id']];
        }
        usort($out, fn($a, $b) => strcmp($a['start'], $b['start']));
        return $out;
    }

    /**
     * The bookings that stop the vehicle leaving service: the ones that hold it, and, when one unit fewer of its type
     * would not cover a reservation that has no vehicle yet, that reservation too.
     */
    private function blockingBookings(array $v): array
    {
        $b = $this->upcomingBookings((int)$v['vehicle_id']);
        if ($v['status'] === Vehicle::ACTIVE) {
            foreach (VehicleReservation::findUpcomingByType($v['type']) as $r) {
                if ($r['vehicle_id'] === null && VehicleReservation::countUnitsLeft($v['type'], $r['start_datetime'], $r['end_datetime'], (int)$r['request_id']) - 1 < 1) {
                    $b[] = ['kind' => 'shortage', 'id' => (int)$r['reservation_id'], 'start' => $r['start_datetime'], 'end' => $r['end_datetime'],
                            'label' => 'Request #' . $r['request_id'] . ' needs every ' . $v['type'] . ' of that period', 'link' => 'staff/request.php?id=' . $r['request_id']];
                }
            }
        }
        return $b;
    }

    /** Task reports with a vehicle problem in the last two weeks. */
    public function recentProblems(): array
    {
        return TaskReport::findRecentProblems(14);
    }

    /** The latest task reports of the vehicle. */
    public function latestReports(int $vehicleID): array
    {
        return TaskReport::findByVehicle($vehicleID, 10);
    }

    /** Vehicles of the type that are free for a reservation's period (the Assistant chooses one, UC003 step 3). */
    public function freeForReservation(array $reservation): array
    {
        return Vehicle::findFree($reservation['vehicle_type'], $reservation['start_datetime'], $reservation['end_datetime'], (int)$reservation['reservation_id']);
    }

    /** Other vehicles a booking can move to (same type for a reservation; car or van for welfare duty). */
    public function alternatives(array $booking, array $vehicle): array
    {
        if ($booking['kind'] === 'reservation') { return Vehicle::findFree($vehicle['type'], $booking['start'], $booking['end'], $booking['id']); }
        if ($booking['kind'] !== 'task') { return []; }
        $list = array_filter(Vehicle::findFree(null, $booking['start'], $booking['end'], 0, $booking['id']), fn($x) => (int)$x['vehicle_id'] !== (int)$vehicle['vehicle_id']);
        if (($booking['source'] ?? '') === Task::WELFARE) { $list = array_filter($list, fn($x) => in_array($x['type'], ['Car', 'Van'], true)); }
        return array_values($list);
    }

    /** Moves one booking to another vehicle. */
    public function moveBooking(string $kind, int $id, int $newVehicleID): array
    {
        $nv = Vehicle::findByID($newVehicleID);
        if (!$nv || $nv['status'] !== Vehicle::ACTIVE) { return wfFail('Select another vehicle.'); }
        $notifier = new NotificationService();
        if ($kind === 'reservation') {
            $r = VehicleReservation::findByID($id);
            if (!$r || $r['status'] !== VehicleReservation::ACTIVE) { return wfFail('Unknown booking.'); }
            if ($nv['type'] !== $r['vehicle_type']) { return wfFail('Select a vehicle of the same type (' . $r['vehicle_type'] . ').'); }
            if (!in_array($newVehicleID, array_map('intval', array_column(Vehicle::findFree($nv['type'], $r['start_datetime'], $r['end_datetime'], $id), 'vehicle_id')), true)) {
                return wfFail($nv['plate_no'] . ' is not free for that period.');
            }
            foreach (Task::findByRequest((int)$r['request_id']) as $t) {
                if ((int)$t['vehicle_id'] === (int)$r['vehicle_id'] && $t['driver_id'] && !Driver::hasLicence(Driver::findByID((int)$t['driver_id']), $nv['licence_required'], substr($t['end_datetime'], 0, 10))) {
                    return wfFail('The assigned driver does not hold the licence for ' . $nv['plate_no'] . '.');
                }
            }
            Database::beginTransaction();
            $old = $r['plate_no'];
            foreach (Task::findByRequest((int)$r['request_id']) as $t) {
                if ((int)$t['vehicle_id'] === (int)$r['vehicle_id']) { Task::update((int)$t['task_id'], ['vehicle_id' => $newVehicleID]); }
            }
            VehicleReservation::setVehicle($id, $newVehicleID);
            $req = Request::findByID((int)$r['request_id']);
            RequestStatusHistory::record((int)$r['request_id'], $req['status'], $req['status'], (int)$_SESSION['user_id'], "Vehicle changed: $old -> {$nv['plate_no']}");
            Database::commit();
            $users = [(int)$req['requester_id']];
            foreach (Task::findByRequest((int)$r['request_id']) as $t) { if ($t['status'] === Task::APPROVED) { $users[] = (int)$t['driver_id']; } }
            $notifier->notify($users, 'The vehicle of request #' . $r['request_id'] . " was changed from $old to {$nv['plate_no']}.", 'staff/request.php?id=' . $r['request_id']);
            return wfOk("The booking was moved to {$nv['plate_no']}.");
        }
        $t = Task::findByID($id);
        if (!$t || !in_array($t['task_source'], [Task::INTERNAL, Task::WELFARE], true)) { return wfFail('Unknown booking.'); }
        if ($t['task_source'] === Task::WELFARE && !in_array($nv['type'], ['Car', 'Van'], true)) { return wfFail('Only cars and vans can be used for welfare duty.'); }
        if (!in_array($newVehicleID, array_map('intval', array_column(Vehicle::findFree(null, $t['start_datetime'], $t['end_datetime'], 0, $id), 'vehicle_id')), true)) {
            return wfFail($nv['plate_no'] . ' is not free for that period.');
        }
        if ($t['driver_id'] && !Driver::hasLicence(Driver::findByID((int)$t['driver_id']), $nv['licence_required'], substr($t['end_datetime'], 0, 10))) {
            return wfFail('The assigned driver does not hold the licence for ' . $nv['plate_no'] . '.');
        }
        Task::update($id, ['vehicle_id' => $newVehicleID]);
        if ($t['driver_id']) { $notifier->notify([(int)$t['driver_id']], 'The vehicle of your task on ' . fmtDT($t['start_datetime']) . " was changed to {$nv['plate_no']}.", 'driver/task.php?id=' . $id); }
        return wfOk("The booking was moved to {$nv['plate_no']}.");
    }
}
