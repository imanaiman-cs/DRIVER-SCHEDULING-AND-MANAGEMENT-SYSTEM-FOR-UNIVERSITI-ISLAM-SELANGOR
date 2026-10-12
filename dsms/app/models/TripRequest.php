<?php
/**
 * TripRequest - a vehicle request (table `requests`) with its legs, documents and status changes.
 * Named TripRequest so it is never confused with an HTTP request.
 *
 * R2: one request = one trip = ONE vehicle and ONE departure/return pair.
 */
class TripRequest extends Model
{
    private const SELECT = "SELECT r.*, ru.name AS requester_name, ru.staff_id AS requester_staff_id, ru.phone AS requester_phone,
                                   ru.ptj_id AS requester_ptj_id, p.name AS ptj_name, au.name AS approver_name,
                                   v.reg_no, v.brand, v.model, v.vehicle_type, v.capacity, v.required_licence,
                                   pp.name AS pickup_name, cu.name AS cancelled_by_name
                              FROM requests r
                              JOIN users ru ON ru.id = r.requester_id
                              LEFT JOIN ptj p ON p.id = ru.ptj_id
                              LEFT JOIN users au ON au.id = r.approver_id
                              JOIN vehicles v ON v.id = r.vehicle_id
                              LEFT JOIN pickup_points pp ON pp.id = r.pickup_point_id
                              LEFT JOIN users cu ON cu.id = r.cancelled_by";

    public function find(int $id): ?array
    {
        return $this->fetchOne(self::SELECT . ' WHERE r.id = ?', [$id]);
    }

    public function legs(int $id): array
    {
        return $this->fetchAll(
            "SELECT l.*, u.name AS driver_name, u.phone AS driver_phone, d.user_id AS driver_user_id
               FROM request_legs l
               LEFT JOIN drivers d ON d.id = l.driver_id
               LEFT JOIN users u ON u.id = d.user_id
              WHERE l.request_id = ? ORDER BY l.leg_type = 'return', l.start_datetime",
            [$id]
        );
    }

    public function documents(int $id): array
    {
        return $this->fetchAll('SELECT * FROM request_documents WHERE request_id = ? ORDER BY id', [$id]);
    }

    public function document(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM request_documents WHERE id = ?', [$id]);
    }

    /**
     * List with optional filters: requester_id, status, q (destination / purpose / request no.), from, to.
     * Always newest departure first. Returns [rows, total] for paging.
     */
    public function search(array $f, int $limit, int $offset): array
    {
        $where = ['1=1']; $args = [];
        if (!empty($f['requester_id'])) { $where[] = 'r.requester_id = ?'; $args[] = (int)$f['requester_id']; }
        if (!empty($f['approver_id'])) { $where[] = 'r.approver_id = ?'; $args[] = (int)$f['approver_id']; }
        if (!empty($f['no_approver'])) { $where[] = 'r.approver_id IS NULL'; }
        if (!empty($f['decided']))     { $where[] = "r.status <> 'Pending Approval'"; }
        if (!empty($f['status']) && in_array($f['status'], RequestStatus::ALL, true)) { $where[] = 'r.status = ?'; $args[] = $f['status']; }
        if (!empty($f['q'])) {
            $where[] = '(r.destination_name LIKE ? OR r.purpose LIKE ? OR r.id = ?)';
            $like = '%' . addcslashes($f['q'], '%_\\') . '%';
            array_push($args, $like, $like, (int)$f['q']);
        }
        if (!empty($f['from'])) { $where[] = 'DATE(r.return_datetime) >= ?';   $args[] = $f['from']; }
        if (!empty($f['to']))   { $where[] = 'DATE(r.departure_datetime) <= ?'; $args[] = $f['to']; }
        $w = implode(' AND ', $where);

        $total = (int)$this->fetchOne("SELECT COUNT(*) c FROM requests r WHERE $w", $args)['c'];
        $rows  = $this->fetchAll(self::SELECT . " WHERE $w ORDER BY r.departure_datetime DESC, r.id DESC LIMIT " . (int)$limit . ' OFFSET ' . (int)$offset, $args);
        return [$rows, $total];
    }

    /**
     * Save a new request with its two legs, day holds and documents - all or nothing.
     * $d keys: requester_id, approver_id, vehicle_id, destination_name, full_address, purpose,
     *          passenger_count, passenger_names, contact_phone, pickup_point_id, departure, return (DateTimeImmutable)
     * $docs: rows already stored on disk by FileUpload::store().
     */
    public function create(array $d, array $docs, AvailabilityService $availability): int
    {
        return $this->transaction(function () use ($d, $docs, $availability): int {
            $dep = $d['departure']; $ret = $d['return'];
            $this->execute(
                'INSERT INTO requests (requester_id, approver_id, vehicle_id, destination_name, full_address, purpose,
                                       passenger_count, passenger_names, contact_phone, pickup_point_id,
                                       departure_datetime, return_datetime, status, submitted_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())',
                [$d['requester_id'], $d['approver_id'], $d['vehicle_id'], $d['destination_name'], $d['full_address'], $d['purpose'],
                 $d['passenger_count'], $d['passenger_names'], $d['contact_phone'], $d['pickup_point_id'],
                 $dep->format('Y-m-d H:i:s'), $ret->format('Y-m-d H:i:s'), RequestStatus::PENDING]
            );
            $id = (int)$this->db->lastInsertId();

            // R6: a trip is two legs (go and return); a driver is chosen per leg later.
            foreach (RequestRules::legWindows($dep, $ret) as $type => [$start, $end]) {
                $this->execute(
                    'INSERT INTO request_legs (request_id, leg_type, start_datetime, end_datetime) VALUES (?,?,?,?)',
                    [$id, $type, $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')]
                );
            }

            // R3: hold the vehicle for every day (the UNIQUE key makes a double booking impossible).
            $availability->placeHolds($id, (int)$d['vehicle_id'], RequestRules::days($dep, $ret));

            foreach ($docs as $doc) {
                $this->execute(
                    'INSERT INTO request_documents (request_id, file_path, original_name, mime_type, file_size) VALUES (?,?,?,?,?)',
                    [$id, $doc['file_path'], $doc['original_name'], $doc['mime_type'], $doc['file_size']]
                );
            }
            return $id;
        });
    }

    /**
     * Move a request to a new status. The ONLY method that writes requests.status.
     *   - checks the move is allowed (RequestStatus),
     *   - locks the row so two people cannot change it at once,
     *   - gives the vehicle back when the request is no longer active,
     *   - writes the audit log (R10) in the same transaction.
     *
     * $columns: extra columns to set, name => value. Names come from our own code, never from users.
     */
    public function changeStatus(int $id, string $to, string $auditAction, ?string $reason = null, array $columns = [], ?int $actorId = null, ?string $actorRole = null, bool $bySystem = false): void
    {
        $this->transaction(function () use ($id, $to, $auditAction, $reason, $columns, $actorId, $actorRole, $bySystem): void {
            $row = $this->fetchOne('SELECT status FROM requests WHERE id = ? FOR UPDATE', [$id]);
            if (!$row) {
                throw new DomainException('Request not found.');
            }
            RequestStatus::assertMove($row['status'], $to);

            $set  = ['status = ?', 'status_reason = ?'];
            $args = [$to, $reason];
            if (isset(RequestStatus::TIMESTAMP_COLUMN[$to])) {
                $set[] = RequestStatus::TIMESTAMP_COLUMN[$to] . ' = NOW()';
            }
            foreach ($columns as $name => $value) {
                $set[]  = "`$name` = ?";
                $args[] = $value;
            }
            $args[] = $id;
            $this->execute('UPDATE requests SET ' . implode(', ', $set) . ' WHERE id = ?', $args);

            if (RequestStatus::releasesVehicle($to)) {
                (new AvailabilityService())->releaseHolds($id);
            }
            if ($to === RequestStatus::CANCELLED) {
                // A cancelled trip frees its drivers too.
                $this->execute('UPDATE request_legs SET driver_id = NULL WHERE request_id = ?', [$id]);
            }

            $audit = new AuditLog();
            $bySystem ? $audit->recordSystem($auditAction, $id, $reason) : $audit->record($auditAction, $id, $reason, $actorId, $actorRole);
        });
    }

    /** The user ids of everyone who should hear about a change: requester, approver, assigned drivers. */
    public function peopleOf(array $request): array
    {
        $ids = [(int)$request['requester_id'], (int)($request['approver_id'] ?? 0)];
        foreach ($this->legs((int)$request['id']) as $leg) {
            $ids[] = (int)($leg['driver_user_id'] ?? 0);
        }
        return array_values(array_unique(array_filter($ids)));
    }
}
