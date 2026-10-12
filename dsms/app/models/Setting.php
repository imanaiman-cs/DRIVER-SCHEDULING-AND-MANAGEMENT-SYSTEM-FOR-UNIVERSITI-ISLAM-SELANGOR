<?php
/**
 * Setting - reads the editable values in the `settings` table
 * (recommendation weights, lapse hours, working hours, ...).
 * Keeping them in the database means the admin can tune them
 * without anyone touching the code.
 */
class Setting extends Model
{
    /** All settings as ['KEY' => 'value']. */
    public function all(): array
    {
        $rows = $this->fetchAll('SELECT setting_key, setting_value FROM settings ORDER BY setting_key');
        return array_column($rows, 'setting_value', 'setting_key');
    }

    /** One setting, or $default when the key does not exist. */
    public function get(string $key, ?string $default = null): ?string
    {
        $row = $this->fetchOne('SELECT setting_value FROM settings WHERE setting_key = ?', [$key]);
        return $row['setting_value'] ?? $default;
    }

    /** Change one existing setting (keys are fixed by the seed; the admin only edits values). */
    public function set(string $key, string $value): void
    {
        $this->execute('UPDATE settings SET setting_value = ? WHERE setting_key = ?', [$value, $key]);
    }

    /** A numeric setting with a safe fallback (so a missing row never breaks a rule). */
    public function int(string $key, int $default): int
    {
        $v = $this->get($key);
        return ($v !== null && is_numeric($v)) ? (int)$v : $default;
    }
}
