<?php
/**
 * SystemCheck - used by the Step 1 home page to prove the skeleton works:
 * database reachable, tables created, seed data loaded.
 */
class SystemCheck extends Model
{
    /** Tables DSMS expects (taken from database/dsms.sql). */
    public const TABLES = [
        'ptj', 'users', 'drivers', 'driver_licences', 'driver_leaves', 'vehicles',
        'vehicle_maintenance', 'pickup_points', 'requests', 'request_documents',
        'request_legs', 'vehicle_day_holds', 'request_comments', 'driver_replacements',
        'internal_tasks', 'welfare_roster', 'reminders', 'notifications', 'audit_log', 'settings',
    ];

    /** @return array<string,int> row count of every expected table (-1 = table missing) */
    public function tableCounts(): array
    {
        $counts = [];
        foreach (self::TABLES as $table) {
            // Table names come from the constant above, never from user input.
            try {
                $counts[$table] = (int)$this->fetchOne("SELECT COUNT(*) AS c FROM `$table`")['c'];
            } catch (PDOException $e) {
                $counts[$table] = -1;
            }
        }
        return $counts;
    }

    /** Requests grouped by status, for a quick look at the demo data. */
    public function requestsByStatus(): array
    {
        return $this->fetchAll('SELECT status, COUNT(*) AS total FROM requests GROUP BY status ORDER BY total DESC');
    }
}
