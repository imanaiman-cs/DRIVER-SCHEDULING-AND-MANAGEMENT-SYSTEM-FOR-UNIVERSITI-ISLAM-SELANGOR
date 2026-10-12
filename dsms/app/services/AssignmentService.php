<?php
/**
 * AssignmentService - putting drivers on a trip (R6, R7).
 *
 * Flow:   Approved --(admin assigns)--> Driver Assigned --(head admin approves)--> Confirmed
 *         Approved --(admin marks self-drive)--> Confirmed   (the requester drives; no driver legs)
 *
 * Every driver choice is checked on the SERVER again, whatever the recommendation page showed:
 *   BLOCK   : not an active carpool driver (R8), wrong licence for the vehicle (R5), on leave
 *   WARNING : another job in the same time slot (R5) - allowed only if the admin ticks "assign anyway"
 */
class AssignmentService extends Model
{
    /**
     * Check one driver for one leg.
     * @return array{errors: string[], warnings: string[]}
     */
    public function check(array $leg, array $request, int $driverId): array
    {
        $errors = []; $warnings = [];
        $driver = $this->fetchOne(
            "SELECT d.id, d.driver_group, d.is_active, u.name, u.is_active AS user_active FROM drivers d JOIN users u ON u.id = d.user_id WHERE d.id = ?",
            [$driverId]
        );
        if (!$driver || !$driver['is_active'] || !$driver['user_active']) {
            return ['errors' => ['That driver is not available (not an active driver).'], 'warnings' => []];
        }
        $name = $driver['name'];
        $av = new AvailabilityService();
        if ($driver['driver_group'] !== 'carpool') {
            $errors[] = "$name is a management driver and cannot be used for normal requests.";
        }
        if (!$av->driverHasLicence($driverId, $request['required_licence'])) {
            $errors[] = "$name does not have the {$request['required_licence']} licence needed for {$request['reg_no']}.";
        }
        if ($leave = $av->driverLeave($driverId, substr($leg['start_datetime'], 0, 10), substr($leg['end_datetime'], 0, 10))) {
            $errors[] = "$name is on " . ($leave['type'] === 'mc' ? 'MC' : 'leave') . ' on ' . fmt_date($leg['start_datetime']) . '.';
        }
        foreach ($av->driverClashes($driverId, $leg['start_datetime'], $leg['end_datetime'], (int)$request['id']) as $clash) {
            $warnings[] = "$name already has: $clash";
        }
        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * Validate a choice for every leg. $choices = [legId => driverId].
     * @return array{errors: string[], warnings: string[]}
     */
    public function checkAll(array $request, array $legs, array $choices): array
    {
        $errors = []; $warnings = [];
        foreach ($legs as $leg) {
            $label = ucfirst($leg['leg_type']) . ' leg';
            $driverId = (int)($choices[$leg['id']] ?? 0);
            if ($driverId < 1) {
                $errors[] = "Please choose a driver for the $label.";
                continue;
            }
            $r = $this->check($leg, $request, $driverId);
            foreach ($r['errors'] as $m)   { $errors[]   = "$label: $m"; }
            foreach ($r['warnings'] as $m) { $warnings[] = "$label: $m"; }
        }
        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /** Save the drivers and move Approved -> Driver Assigned, all or nothing. */
    public function assign(array $request, array $legs, array $choices, array $actor): void
    {
        $this->transaction(function () use ($request, $legs, $choices, $actor): void {
            foreach ($legs as $leg) {
                $this->execute('UPDATE request_legs SET driver_id = ? WHERE id = ? AND request_id = ?', [(int)$choices[$leg['id']], $leg['id'], $request['id']]);
            }
            (new TripRequest())->changeStatus((int)$request['id'], RequestStatus::ASSIGNED, 'assign', $this->summary($legs, $choices), [], (int)$actor['id'], $actor['role']);
        });
    }

    /** Self-drive: the requester drives, so there is nothing to assign. Approved -> Confirmed. */
    public function selfDrive(array $request, array $actor): void
    {
        (new TripRequest())->changeStatus((int)$request['id'], RequestStatus::CONFIRMED, 'self_drive', 'Self-drive: no driver assigned', ['is_self_drive' => 1], (int)$actor['id'], $actor['role']);
    }

    /** Head admin approves the assignment: Driver Assigned -> Confirmed. */
    public function confirm(array $request, array $actor): void
    {
        (new TripRequest())->changeStatus((int)$request['id'], RequestStatus::CONFIRMED, 'confirm_assignment', null, [], (int)$actor['id'], $actor['role']);
    }

    /**
     * R7: change the driver of one leg and LOG it (previous driver, new driver, reason, who, when).
     * Allowed while the request is Driver Assigned or Confirmed and the leg has not started.
     */
    public function changeDriver(array $request, array $leg, int $newDriverId, string $reason, array $actor): void
    {
        $this->transaction(function () use ($request, $leg, $newDriverId, $reason, $actor): void {
            $current = $this->fetchOne('SELECT r.status, l.driver_id FROM request_legs l JOIN requests r ON r.id = l.request_id WHERE l.id = ? AND r.id = ? FOR UPDATE', [$leg['id'], $request['id']]);
            if (!$current || !in_array($current['status'], [RequestStatus::ASSIGNED, RequestStatus::CONFIRMED], true)) {
                throw new DomainException('The driver can only be changed while the request is Driver Assigned or Confirmed.');
            }
            $this->execute('UPDATE request_legs SET driver_id = ? WHERE id = ?', [$newDriverId, $leg['id']]);
            $this->execute(
                'INSERT INTO driver_replacements (request_id, leg_id, previous_driver_id, new_driver_id, reason, changed_by) VALUES (?,?,?,?,?,?)',
                [$request['id'], $leg['id'], $current['driver_id'], $newDriverId, $reason, $actor['id']]
            );
            (new AuditLog())->record('change_driver', (int)$request['id'], ucfirst($leg['leg_type']) . " leg: $reason", (int)$actor['id'], $actor['role']);
        });
    }

    public function replacements(int $requestId): array
    {
        return $this->fetchAll(
            "SELECT p.*, l.leg_type, pu.name AS previous_name, nu.name AS new_name, cu.name AS changed_by_name
               FROM driver_replacements p
               JOIN request_legs l ON l.id = p.leg_id
               LEFT JOIN drivers pd ON pd.id = p.previous_driver_id LEFT JOIN users pu ON pu.id = pd.user_id
               JOIN drivers nd ON nd.id = p.new_driver_id JOIN users nu ON nu.id = nd.user_id
               JOIN users cu ON cu.id = p.changed_by
              WHERE p.request_id = ? ORDER BY p.changed_at, p.id",
            [$requestId]
        );
    }

    /** The user_id behind a driver id (to notify them). */
    public function userIdOfDriver(int $driverId): ?int
    {
        $row = $this->fetchOne('SELECT user_id FROM drivers WHERE id = ?', [$driverId]);
        return $row ? (int)$row['user_id'] : null;
    }

    private function summary(array $legs, array $choices): string
    {
        $names = [];
        foreach ($legs as $leg) {
            $row = $this->fetchOne('SELECT u.name FROM drivers d JOIN users u ON u.id = d.user_id WHERE d.id = ?', [(int)$choices[$leg['id']]]);
            $names[] = $leg['leg_type'] . ': ' . ($row['name'] ?? '?');
        }
        return implode('; ', $names);
    }
}
