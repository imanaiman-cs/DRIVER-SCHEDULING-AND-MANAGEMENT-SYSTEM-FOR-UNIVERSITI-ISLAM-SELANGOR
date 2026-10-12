<?php
/**
 * WelfareRoster - which driver is on 24-hour welfare duty each day (one driver per date).
 * A driver on duty is kept free for emergencies, so the recommender takes points off their score that day.
 */
class WelfareRoster extends Model
{
    public function month(string $month): array
    {
        $rows = $this->fetchAll(
            "SELECT w.*, u.name AS driver_name, pu.name AS replaced_name
               FROM welfare_roster w
               JOIN drivers d ON d.id = w.driver_id JOIN users u ON u.id = d.user_id
               LEFT JOIN drivers pd ON pd.id = w.replaced_from_driver_id LEFT JOIN users pu ON pu.id = pd.user_id
              WHERE w.duty_date BETWEEN ? AND ?",
            [$month . '-01', date('Y-m-t', strtotime($month . '-01'))]
        );
        $byDate = [];
        foreach ($rows as $r) { $byDate[$r['duty_date']] = $r; }
        return $byDate;
    }

    public function forDate(string $date): ?array
    {
        return $this->fetchOne('SELECT * FROM welfare_roster WHERE duty_date = ?', [$date]);
    }

    /** Put a driver on duty for a date; replacing keeps who was replaced and why. */
    public function set(string $date, int $driverId, ?int $replacedFrom = null, ?string $reason = null): void
    {
        $this->execute(
            'INSERT INTO welfare_roster (duty_date, driver_id, replaced_from_driver_id, replacement_reason) VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE driver_id = VALUES(driver_id), replaced_from_driver_id = VALUES(replaced_from_driver_id), replacement_reason = VALUES(replacement_reason)',
            [$date, $driverId, $replacedFrom, $reason]
        );
    }

    public function clear(string $date): void
    {
        $this->execute('DELETE FROM welfare_roster WHERE duty_date = ?', [$date]);
    }

    /** Active carpool drivers. */
    public function drivers(): array
    {
        return $this->fetchAll(
            "SELECT d.id, u.name FROM drivers d JOIN users u ON u.id = d.user_id WHERE d.is_active = 1 AND u.is_active = 1 AND d.driver_group = 'carpool' ORDER BY u.name"
        );
    }

    /** Duty days per driver in a date range (to keep the rotation fair). */
    public function dutyCounts(string $from, string $to): array
    {
        $out = [];
        foreach ($this->fetchAll('SELECT driver_id, COUNT(*) c FROM welfare_roster WHERE duty_date BETWEEN ? AND ? GROUP BY driver_id', [$from, $to]) as $r) {
            $out[(int)$r['driver_id']] = (int)$r['c'];
        }
        return $out;
    }
}
