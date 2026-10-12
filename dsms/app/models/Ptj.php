<?php
/** Ptj - departments / units (PTJ). */
class Ptj extends Model
{
    public function all(): array
    {
        return $this->fetchAll('SELECT p.*, (SELECT COUNT(*) FROM users u WHERE u.ptj_id = p.id) AS user_count FROM ptj p ORDER BY p.name');
    }
    public function find(int $id): ?array { return $this->fetchOne('SELECT * FROM ptj WHERE id = ?', [$id]); }
    public function codeTaken(string $code, int $exceptId = 0): bool
    {
        return (bool)$this->fetchOne('SELECT id FROM ptj WHERE code = ? AND id <> ?', [$code, $exceptId]);
    }
    public function create(string $code, string $name): void { $this->execute('INSERT INTO ptj (code, name) VALUES (?, ?)', [$code, $name]); }
    public function update(int $id, string $code, string $name): void { $this->execute('UPDATE ptj SET code = ?, name = ? WHERE id = ?', [$code, $name, $id]); }
    public function delete(int $id): void { $this->execute('DELETE FROM ptj WHERE id = ?', [$id]); }
}
