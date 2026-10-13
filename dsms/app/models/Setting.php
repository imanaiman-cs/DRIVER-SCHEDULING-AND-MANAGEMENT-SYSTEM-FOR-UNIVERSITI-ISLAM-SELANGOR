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

    /** Change one existing setting (a key that an older seed did not have is created on first save). */
    public function set(string $key, string $value): void
    {
        $this->execute('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', [$key, $value]);
    }

    /** A numeric setting with a safe fallback (so a missing row never breaks a rule). */
    public function int(string $key, int $default): int
    {
        $v = $this->get($key);
        return ($v !== null && is_numeric($v)) ? (int)$v : $default;
    }
}
