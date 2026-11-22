<?php
declare(strict_types=1);

namespace App\Domain;

/**
 * Prüft und normalisiert Werte gespeicherter Spielerfelder (Schnittstelle und Import).
 * Die Rechteprüfung liegt beim Aufrufer: Unbekannte Felder müssen vorher abgelehnt sein.
 */
final class PlayerInput
{
    /**
     * Prüfregeln je schreibbarem Feld: Typ, Grenze, Pflicht.
     * string: Länge in Zeichen; int: [min, max]; leere Texte werden zu null (außer bei Pflichtfeldern).
     */
    public const RULES = [
        'last_name' => ['string', 100, true],
        'first_name' => ['string', 100, true],
        'club_name' => ['string', 190, false],
        'list_no' => ['int', [0, 99999], false],
        'list_ak' => ['string', 5, false],
        'gender' => ['string', 10, false],
        'birth_year' => ['int', [1900, 2100], false],
        'roster_status' => ['string', 10, false],
        'active' => ['bool', null, true],
        'training_site' => ['string', 100, false],
        'birth_date' => ['date', null, false],
        'trainer_info' => ['string', 10000, false],
        'school' => ['string', 190, false],
        'school_contact' => ['string', 500, false],
        // Bis Etappe 3 Textspalten aus der Kaderliste: nur Datum und Status, keine Befunde. Kurz gehalten.
        'sportmed_note' => ['string', 200, false],
        'antidoping_note' => ['string', 200, false],
        'email1' => ['string', 190, false],
        'email2' => ['string', 190, false],
        'phone_player' => ['string', 50, false],
        'phone_mother' => ['string', 50, false],
        'phone_father' => ['string', 50, false],
        'shirt_size' => ['string', 10, false],
    ];

    /** Freitextfelder, in denen Zeilenumbruch und Tabulator erlaubt sind. */
    private const MULTILINE = ['trainer_info', 'sportmed_note', 'antidoping_note'];

    /** Leerraum am Rand, auch geschützte und Null-Breite-Leerzeichen und BOM aus Excel. */
    public const EDGE_SPACE = '/^[\s\x{00A0}\x{2007}\x{202F}\x{200B}\x{FEFF}]+|[\s\x{00A0}\x{2007}\x{202F}\x{200B}\x{FEFF}]+$/u';

    /**
     * @param array<string, mixed> $body nur Felder aus RULES
     * @return array{0: array<string, mixed>, 1: list<string>} [Daten, ungültige Felder in der Reihenfolge von $body]
     */
    public static function validate(array $body): array
    {
        $data = [];
        $invalid = [];
        foreach ($body as $field => $value) {
            [$type, $limit, $required] = self::RULES[$field];
            $ok = true;
            switch ($type) {
                case 'string':
                    if ($value === null) {
                        $value = null;
                    } elseif (is_string($value) && mb_check_encoding($value, 'UTF-8')) {
                        $value = (string) preg_replace(self::EDGE_SPACE, '', $value);
                        $control = in_array($field, self::MULTILINE, true) ? '/[^\P{Cc}\t\n\r]/u' : '/\p{Cc}/u';
                        if (mb_strlen($value, 'UTF-8') > $limit || preg_match($control, $value) === 1) {
                            $ok = false;
                        } elseif ($value === '' && $field !== 'club_name') {
                            $value = null;
                        }
                    } else {
                        $ok = false;
                    }
                    break;
                case 'int':
                    if (is_string($value) && preg_match('/^\d{1,9}$/', $value) === 1) {
                        $value = (int) $value;
                    }
                    if ($value !== null && (!is_int($value) || $value < $limit[0] || $value > $limit[1])) {
                        $ok = false;
                    }
                    break;
                case 'bool':
                    $ok = is_bool($value);
                    break;
                case 'date':
                    if ($value === '') {
                        $value = null;
                    }
                    if ($value !== null) {
                        $ok = is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1
                            && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
                    }
                    break;
            }
            if ($ok && $required && $value === null) {
                $ok = false;
            }
            if ($ok) {
                $data[$field] = $value;
            } else {
                $invalid[] = (string) $field;
            }
        }
        return [$data, $invalid];
    }
}
