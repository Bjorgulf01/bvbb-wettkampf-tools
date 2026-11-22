<?php
declare(strict_types=1);

namespace App\Repo;

use App\Domain\ClubDirectory;
use InvalidArgumentException;
use PDO;

/**
 * Spieler mit ihren privaten Daten (Tabelle player_private) und den vom Verein abgeleiteten
 * Feldern club_short und state. Spieler werden nie gelöscht, nur auf passiv gesetzt.
 * Die Rechteprüfung liegt beim Aufrufer: Hier werden genau die verlangten Felder gelesen.
 */
final class PlayerRepo
{
    public const PLAYER_COLUMNS = ['last_name', 'first_name', 'club_id', 'club_name', 'list_no', 'list_ak', 'gender',
        'birth_year', 'roster_status', 'active', 'training_site'];
    public const PRIVATE_COLUMNS = ['birth_date', 'trainer_info', 'school', 'school_contact', 'sportmed_note',
        'antidoping_note', 'email1', 'email2', 'phone_player', 'phone_mother', 'phone_father', 'shirt_size'];
    /** Nur lesbar, vom Verein abgeleitet. */
    public const CLUB_COLUMNS = ['club_short' => "COALESCE(c.short, '')", 'state' => "COALESCE(c.state, '')"];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param list<string> $fields
     * @return list<array<string, mixed>> je Zeile id und die verlangten Felder, sortiert nach Name
     */
    public function list(array $fields, ?bool $activeOnly = null): array
    {
        $sql = $this->select($fields);
        $args = [];
        if ($activeOnly !== null) {
            $sql .= ' WHERE p.active = ?';
            $args[] = $activeOnly ? 1 : 0;
        }
        $stmt = $this->pdo->prepare($sql . ' ORDER BY p.last_name, p.first_name, p.id');
        $stmt->execute($args);
        return array_map(self::typed(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param list<string> $fields
     * @return array<string, mixed>|null
     */
    public function find(int $id, array $fields): ?array
    {
        $stmt = $this->pdo->prepare($this->select($fields) . ' WHERE p.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::typed($row);
    }

    public function exists(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM players WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetchColumn() !== false;
    }

    /** @param array<string, mixed> $data gespeicherte Felder (geprüft), mindestens last_name und first_name */
    public function create(array $data): int
    {
        [$player, $private] = $this->split($data);
        $cols = array_keys($player);
        $this->pdo->prepare(
            'INSERT INTO players (' . implode(', ', $cols) . ') VALUES (' . self::marks(count($cols)) . ')'
        )->execute(array_map(self::db(...), array_values($player)));
        $id = (int) $this->pdo->lastInsertId();
        $privCols = array_merge(['player_id'], array_keys($private));
        $this->pdo->prepare(
            'INSERT INTO player_private (' . implode(', ', $privCols) . ') VALUES (' . self::marks(count($privCols)) . ')'
        )->execute(array_merge([$id], array_map(self::db(...), array_values($private))));
        return $id;
    }

    /**
     * Speichert nur Felder, deren Wert sich ändert.
     * @param array<string, mixed> $data gespeicherte Felder (geprüft)
     * @return list<string> Namen der geänderten Felder, in der Reihenfolge von $data
     */
    public function update(int $id, array $data): array
    {
        $current = $this->find($id, array_keys($data));
        if ($current === null) {
            throw new InvalidArgumentException('Spieler nicht gefunden');
        }
        $changed = [];
        foreach ($data as $field => $value) {
            if ($current[$field] !== $value) {
                $changed[$field] = $value;
            }
        }
        [$player, $private] = $this->split($changed);
        if ($player !== []) {
            $set = implode(', ', array_map(static fn(string $c): string => $c . ' = ?', array_keys($player)));
            $this->pdo->prepare('UPDATE players SET ' . $set . ' WHERE id = ?')
                ->execute(array_merge(array_map(self::db(...), array_values($player)), [$id]));
        }
        if ($private !== []) {
            $cols = array_keys($private);
            $updates = implode(', ', array_map(static fn(string $c): string => $c . ' = VALUES(' . $c . ')', $cols));
            $this->pdo->prepare(
                'INSERT INTO player_private (player_id, ' . implode(', ', $cols) . ') VALUES (?, ' . self::marks(count($cols))
                . ') ON DUPLICATE KEY UPDATE ' . $updates
            )->execute(array_merge([$id], array_map(self::db(...), array_values($private))));
        }
        return array_keys($changed);
    }

    /**
     * Leitet club_id aller Spieler neu aus club_name ab, zum Beispiel nach einer Änderung der Vereine.
     * @return int Zahl der geänderten Spieler
     */
    public function reassignClubs(ClubDirectory $clubs): int
    {
        $update = $this->pdo->prepare('UPDATE players SET club_id = ? WHERE id = ?');
        $n = 0;
        foreach ($this->pdo->query('SELECT id, club_id, club_name FROM players')->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $clubId = $clubs->lookup((string) $p['club_name'])['club_id'];
            $current = $p['club_id'] !== null ? (int) $p['club_id'] : null;
            if ($clubId !== $current) {
                $update->execute([$clubId, (int) $p['id']]);
                $n++;
            }
        }
        return $n;
    }

    /** @param list<string> $fields */
    private function select(array $fields): string
    {
        $parts = ['p.id AS id'];
        foreach (array_unique($fields) as $f) {
            if (in_array($f, self::PLAYER_COLUMNS, true)) {
                $parts[] = 'p.' . $f . ' AS ' . $f;
            } elseif (in_array($f, self::PRIVATE_COLUMNS, true)) {
                $parts[] = 'pp.' . $f . ' AS ' . $f;
            } elseif (isset(self::CLUB_COLUMNS[$f])) {
                $parts[] = self::CLUB_COLUMNS[$f] . ' AS ' . $f;
            } else {
                throw new InvalidArgumentException('Unbekanntes Feld: ' . $f);
            }
        }
        return 'SELECT ' . implode(', ', $parts)
            . ' FROM players p LEFT JOIN player_private pp ON pp.player_id = p.id LEFT JOIN clubs c ON c.id = p.club_id';
    }

    /**
     * @param array<string, mixed> $data
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function split(array $data): array
    {
        $player = [];
        $private = [];
        foreach ($data as $f => $v) {
            if (in_array($f, self::PLAYER_COLUMNS, true)) {
                $player[$f] = $v;
            } elseif (in_array($f, self::PRIVATE_COLUMNS, true)) {
                $private[$f] = $v;
            } else {
                throw new InvalidArgumentException('Feld nicht speicherbar: ' . $f);
            }
        }
        return [$player, $private];
    }

    private static function marks(int $n): string
    {
        return implode(', ', array_fill(0, $n, '?'));
    }

    /**
     * Einheitliche Typen: Zahlen als int, active als bool, Text als string, null bleibt null.
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function typed(array $row): array
    {
        foreach ($row as $k => $v) {
            if ($v === null) {
                continue;
            }
            $row[$k] = match ($k) {
                'id', 'club_id', 'list_no', 'birth_year' => (int) $v,
                'active' => (bool) $v,
                default => (string) $v,
            };
        }
        return $row;
    }

    private static function db(mixed $v): mixed
    {
        return is_bool($v) ? (int) $v : $v;
    }
}
