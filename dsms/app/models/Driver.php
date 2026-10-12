<?php
/** Driver - the driver profile (group, licence classes, leave/MC) that sits on top of a user account. */
class Driver extends Model
{
    public const LICENCES = ['B2', 'D', 'E', 'H', 'GDL', 'PSV'];
    public const GROUPS   = ['carpool' => 'Carpool', 'management' => 'Management'];

    public function all(): array
    {
        return $this->fetchAll(
            "SELECT d.id, d.user_id, d.driver_group, d.is_active, u.name, u.staff_id, u.phone,
                    (SELECT GROUP_CONCAT(l.licence_class ORDER BY l.licence_class SEPARATOR ', ')
                       FROM driver_licences l WHERE l.driver_id = d.id) AS licences,
                    (SELECT COUNT(*) FROM driver_leaves lv
                      WHERE lv.driver_id = d.id AND CURDATE() BETWEEN lv.start_date AND lv.end_date) AS on_leave_today
               FROM drivers d JOIN users u ON u.id = d.user_id
              ORDER BY d.is_active DESC, d.driver_group, u.name"
        );
    }

    public function find(int $id): ?array
    {
        return $this->fetchOne(
            'SELECT d.*, u.name, u.staff_id, u.phone, u.email FROM drivers d JOIN users u ON u.id = d.user_id WHERE d.id = ?',
            [$id]
        );
    }

    public function licences(int $id): array
    {
        return array_column($this->fetchAll('SELECT licence_class FROM driver_licences WHERE driver_id = ?', [$id]), 'licence_class');
    }

    /** Group and licence classes are saved together so a driver is never half-configured. */
    public function saveProfile(int $id, string $group, array $licences): void
    {
        $this->db->beginTransaction();
        try {
            $this->execute('UPDATE drivers SET driver_group = ? WHERE id = ?', [$group, $id]);
            $this->execute('DELETE FROM driver_licences WHERE driver_id = ?', [$id]);
            foreach ($licences as $class) {
                $this->execute('INSERT INTO driver_licences (driver_id, licence_class) VALUES (?, ?)', [$id, $class]);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function leaves(int $id): array
    {
        return $this->fetchAll('SELECT * FROM driver_leaves WHERE driver_id = ? ORDER BY start_date DESC', [$id]);
    }

    public function addLeave(int $id, string $start, string $end, string $type, ?string $note): void
    {
        $this->execute(
            'INSERT INTO driver_leaves (driver_id, start_date, end_date, type, note) VALUES (?, ?, ?, ?, ?)',
            [$id, $start, $end, $type, $note]
        );
    }

    public function deleteLeave(int $leaveId, int $driverId): void
    {
        $this->execute('DELETE FROM driver_leaves WHERE id = ? AND driver_id = ?', [$leaveId, $driverId]);
    }

    /**
     * Confirmed / assigned trip legs that fall inside a leave period.
     * Shown as a warning only: the admin decides whether to change the driver (UC in Step 6).
     */
    public function affectedLegs(int $id, string $start, string $end): array
    {
        return $this->fetchAll(
            "SELECT r.id AS request_id, r.destination_name, r.status, l.leg_type, l.start_datetime, l.end_datetime
               FROM request_legs l JOIN requests r ON r.id = l.request_id
              WHERE l.driver_id = ? AND r.status IN ('Driver Assigned','Confirmed')
                AND DATE(l.start_datetime) <= ? AND DATE(l.end_datetime) >= ?
              ORDER BY l.start_datetime",
            [$id, $end, $start]
        );
    }
}
