<?php
declare(strict_types=1);

namespace App\Repo;

use App\Domain\ClubDirectory;
use InvalidArgumentException;
use PDO;

/** Vereine. Vereine werden nie gelöscht, nur auf inaktiv gesetzt. */
final class ClubRepo
{
    /** Von Hand pflegbare Felder bis zum Import der Mitgliederliste (Task 9b). */
    public const EDITABLE = ['name', 'short', 'state', 'active'];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Aktive Vereine mit Aliasnamen als Verzeichnis für die Zuordnung des Vereinstexts.
     * Inaktive Vereine werden nicht mehr neu zugeordnet; ihr Text gilt dann als unbekannt.
     */
    public function directory(): ClubDirectory
    {
        return new ClubDirectory(array_values(array_filter($this->all(), static fn(array $c): bool => $c['active'])));
    }

    /**
     * Alle Vereine, auch inaktive, sortiert nach Name. state ist '' (nicht gepflegt), 'BER' oder 'BRA'.
     * @return list<array{id:int, member_no:?string, name:string, short:string, state:string, active:bool, aliases:list<string>}>
     */
    public function all(): array
    {
        $aliases = [];
        foreach ($this->pdo->query('SELECT club_id, alias FROM club_aliases ORDER BY alias, club_id')->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $aliases[(int) $a['club_id']][] = (string) $a['alias'];
        }
        $list = [];
        $rows = $this->pdo->query('SELECT id, member_no, name, short, state, active FROM clubs ORDER BY name, id')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $c) {
            $id = (int) $c['id'];
            $list[] = [
                'id' => $id,
                'member_no' => $c['member_no'] !== null ? (string) $c['member_no'] : null,
                'name' => (string) $c['name'],
                'short' => (string) $c['short'],
                'state' => (string) ($c['state'] ?? ''),
                'active' => (bool) $c['active'],
                'aliases' => $aliases[$id] ?? [],
            ];
        }
        return $list;
    }

    public function exists(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM clubs WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetchColumn() !== false;
    }

    /** @param array{name:string, short?:string, state?:?string, active?:bool} $data */
    public function create(array $data): int
    {
        $this->pdo->prepare('INSERT INTO clubs (name, short, state, active) VALUES (?, ?, ?, ?)')->execute([
            $data['name'], $data['short'] ?? '', $data['state'] ?? null, (int) ($data['active'] ?? true),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Speichert nur Felder, deren Wert sich ändert.
     * @param array<string, mixed> $data Felder aus EDITABLE (geprüft); state null heißt nicht gepflegt
     * @return list<string> geänderte Felder
     */
    public function update(int $id, array $data): array
    {
        $stmt = $this->pdo->prepare('SELECT name, short, state, active FROM clubs WHERE id = ?');
        $stmt->execute([$id]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($current === false) {
            throw new InvalidArgumentException('Verein nicht gefunden');
        }
        $current['active'] = (bool) $current['active'];
        $changed = [];
        foreach ($data as $field => $value) {
            if (!in_array($field, self::EDITABLE, true)) {
                throw new InvalidArgumentException('Feld nicht speicherbar: ' . $field);
            }
            if ($current[$field] !== $value) {
                $changed[$field] = is_bool($value) ? (int) $value : $value;
            }
        }
        if ($changed !== []) {
            $set = implode(', ', array_map(static fn(string $c): string => $c . ' = ?', array_keys($changed)));
            $this->pdo->prepare('UPDATE clubs SET ' . $set . ' WHERE id = ?')->execute([...array_values($changed), $id]);
        }
        return array_keys($changed);
    }

    /**
     * Ersetzt die Aliasnamen eines Vereins. Ein Alias, den schon ein anderer Verein hat (Vergleich
     * nach der Sortierung der Datenbank), ergibt eine PDOException mit SQLSTATE 23000.
     * @param list<string> $aliases
     * @return bool ob sich etwas geändert hat
     */
    public function setAliases(int $id, array $aliases): bool
    {
        $stmt = $this->pdo->prepare('SELECT alias FROM club_aliases WHERE club_id = ? ORDER BY alias');
        $stmt->execute([$id]);
        $current = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $new = $aliases;
        sort($current, SORT_STRING);
        sort($new, SORT_STRING);
        if ($current === $new) {
            return false;
        }
        $this->pdo->prepare('DELETE FROM club_aliases WHERE club_id = ?')->execute([$id]);
        $insert = $this->pdo->prepare('INSERT INTO club_aliases (club_id, alias) VALUES (?, ?)');
        foreach ($aliases as $alias) {
            $insert->execute([$id, $alias]);
        }
        return true;
    }
}
