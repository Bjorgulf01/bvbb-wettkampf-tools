<?php
declare(strict_types=1);

namespace App\Rbac;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Rechte je Rolle und Feldgruppe (Tabelle role_permissions). Eine Person mit mehreren Rollen
 * bekommt die Vereinigung der Rechte; ohne Rolle bekommt sie nichts. Schreiben schließt Lesen ein.
 * Unbekannte Rollen, Gruppen und Modi in den Rechten geben nichts frei.
 */
final class Permissions
{
    public const READ = 'read';
    public const WRITE = 'write';

    /** Felder je Gruppe. Die Reihenfolge hier bestimmt die Reihenfolge der Ausgabe. */
    public const FIELD_GROUPS = [
        'player.basic' => ['last_name', 'first_name', 'club_name', 'club_short'],
        'player.roster' => ['list_no', 'gender', 'birth_year', 'roster_status', 'active', 'training_site', 'state'],
        'player.health_light' => ['sportmed_status', 'antidoping_status'],
        'player.private' => ['birth_date', 'trainer_info', 'school', 'school_contact', 'sportmed_note', 'antidoping_note',
            'email1', 'email2', 'phone_player', 'phone_mother', 'phone_father', 'shirt_size'],
    ];

    /** Gruppen, die nie schreibbar sind, auch wenn die Datenbank es anders sagt (abgeleitete Ampel). */
    public const READ_ONLY_GROUPS = ['player.health_light'];

    /** Abgeleitete Felder: Kürzel und Bundesland kommen vom Verein, die Ampel aus den Textspalten. */
    public const READ_ONLY_FIELDS = ['club_short', 'state', 'sportmed_status', 'antidoping_status'];

    /** @var array<string, array<string, string>> Rolle => Gruppe => höchster Modus */
    private array $grants = [];

    /** @param list<array{role:string, group:string, mode:string}> $grants */
    public function __construct(array $grants)
    {
        foreach ($grants as $g) {
            $role = is_array($g) ? ($g['role'] ?? null) : null;
            $group = is_array($g) ? ($g['group'] ?? null) : null;
            $mode = is_array($g) ? ($g['mode'] ?? null) : null;
            // Unvollständige oder fehlerhafte Zeilen geben nichts frei.
            if (!is_string($role) || $role === '' || !is_string($group) || $group === ''
                || ($mode !== self::READ && $mode !== self::WRITE)) {
                continue;
            }
            if ($mode === self::WRITE && in_array($group, self::READ_ONLY_GROUPS, true)) {
                $mode = self::READ;
            }
            if (($this->grants[$role][$group] ?? null) !== self::WRITE) {
                $this->grants[$role][$group] = $mode;
            }
        }
    }

    public static function fromDb(PDO $pdo): self
    {
        $stmt = $pdo->query(
            'SELECT r.role_key AS role, p.perm_group AS `group`, p.mode AS mode
             FROM role_permissions p JOIN roles r ON r.id = p.role_id'
        );
        if ($stmt === false) {
            throw new RuntimeException('Rechte konnten nicht gelesen werden');
        }
        return new self($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param list<string> $roleKeys
     * @return list<string> erlaubte Felder, jedes genau einmal, in der Reihenfolge von FIELD_GROUPS
     */
    public function fieldsFor(array $roleKeys, string $mode): array
    {
        self::assertMode($mode);
        $fields = [];
        foreach (self::FIELD_GROUPS as $group => $groupFields) {
            if (!$this->can($roleKeys, $group, $mode)) {
                continue;
            }
            foreach ($groupFields as $f) {
                if ($mode === self::WRITE && in_array($f, self::READ_ONLY_FIELDS, true)) {
                    continue;
                }
                $fields[] = $f;
            }
        }
        return $fields;
    }

    /**
     * Prüfung je Felderliste, zum Beispiel aller Schlüssel einer Anfrage zum Speichern:
     * liefert die Felder, die nicht erlaubt oder unbekannt sind. Leer heißt: alles erlaubt.
     * Für das Speichern von Spielerdaten immer diese Methode (oder fieldsFor) verwenden, nie can():
     * can() prüft nur die Gruppe, abgeleitete Felder darin sind trotzdem nicht schreibbar.
     *
     * @param list<string> $roleKeys
     * @param list<string> $fields
     * @return list<string>
     */
    public function deniedFields(array $roleKeys, array $fields, string $mode): array
    {
        $allowed = array_flip($this->fieldsFor($roleKeys, $mode));
        $denied = [];
        foreach ($fields as $f) {
            if (!isset($allowed[$f])) {
                $denied[] = (string) $f;
            }
        }
        return $denied;
    }

    /**
     * Höchster Modus je Gruppe oder Bereich für diese Rollen (Vereinigung), nach Gruppe sortiert.
     * Gruppen ohne Recht fehlen. Für die Oberfläche (GET /api/me); geprüft wird trotzdem jede Anfrage.
     *
     * @param list<string> $roleKeys
     * @return array<string, string> Gruppe => read|write
     */
    public function groupsFor(array $roleKeys): array
    {
        $out = [];
        foreach ($roleKeys as $role) {
            if (!is_string($role)) {
                continue;
            }
            foreach ($this->grants[$role] ?? [] as $group => $mode) {
                if (($out[$group] ?? null) !== self::WRITE) {
                    $out[$group] = $mode;
                }
            }
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /**
     * Recht auf eine ganze Gruppe oder einen Bereich (clubs, users, audit).
     * Achtung: Ein Schreibrecht auf eine Gruppe gilt nicht für ihre READ_ONLY_FIELDS.
     *
     * @param list<string> $roleKeys
     */
    public function can(array $roleKeys, string $group, string $mode): bool
    {
        self::assertMode($mode);
        foreach ($roleKeys as $role) {
            if (!is_string($role)) {
                continue; // ungültiger Rollenschlüssel: wie eine unbekannte Rolle
            }
            $granted = $this->grants[$role][$group] ?? null;
            if ($granted === self::WRITE || ($granted === self::READ && $mode === self::READ)) {
                return true;
            }
        }
        return false;
    }

    private static function assertMode(string $mode): void
    {
        if ($mode !== self::READ && $mode !== self::WRITE) {
            throw new InvalidArgumentException('Unbekannter Modus: ' . $mode);
        }
    }
}
