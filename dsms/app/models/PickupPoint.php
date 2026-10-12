<?php
/** PickupPoint - where passengers are collected. Deactivate instead of deleting (old requests use them). */
class PickupPoint extends Model
{
    public function all(bool $activeOnly = false): array
    {
        return $this->fetchAll('SELECT * FROM pickup_points ' . ($activeOnly ? 'WHERE is_active = 1 ' : '') . 'ORDER BY name');
    }
    public function active(): array { return $this->all(true); }
    public function isActive(int $id): bool { return (bool)$this->fetchOne('SELECT id FROM pickup_points WHERE id = ? AND is_active = 1', [$id]); }
    public function find(int $id): ?array { return $this->fetchOne('SELECT * FROM pickup_points WHERE id = ?', [$id]); }
    public function nameTaken(string $name, int $exceptId = 0): bool
    {
        return (bool)$this->fetchOne('SELECT id FROM pickup_points WHERE name = ? AND id <> ?', [$name, $exceptId]);
    }
    public function create(string $name): void { $this->execute('INSERT INTO pickup_points (name) VALUES (?)', [$name]); }
    public function rename(int $id, string $name): void { $this->execute('UPDATE pickup_points SET name = ? WHERE id = ?', [$name, $id]); }
    public function setActive(int $id, bool $active): void { $this->execute('UPDATE pickup_points SET is_active = ? WHERE id = ?', [$active ? 1 : 0, $id]); }
}
