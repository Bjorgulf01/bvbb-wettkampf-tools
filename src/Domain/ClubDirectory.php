<?php
declare(strict_types=1);

namespace App\Domain;

use InvalidArgumentException;

/**
 * Ordnet den Vereinstext aus der Kaderliste einem Verein zu: über Langname, Kürzel oder Alias,
 * ohne Beachtung von Groß-/Kleinschreibung (auch ß/SS) und Leerraum am Rand; Leerraum im Text
 * zählt als ein Leerzeichen.
 * Reihenfolge: Langname vor Kürzel vor Alias. Passt ein Text auf einer Stufe zu mehreren Vereinen,
 * entscheidet die nächste Stufe (ein von Hand gepflegter Alias löst so einen Konflikt auf).
 * Bleibt er mehrdeutig, gilt er als unbekannt (die Datenprüfung meldet ihn), statt zu raten.
 * Unbekannte Vereine bekommen kein erfundenes Kürzel: Auf amtlichen Unterlagen stehen nur offizielle.
 */
final class ClubDirectory
{
    private const STATES = ['BER', 'BRA'];

    /** Leerraum einschließlich geschütztem Leerzeichen, Null-Breite-Zeichen und Byte-Order-Mark. */
    private const SPACE = '[\s\x{00A0}\x{2007}\x{202F}\x{200B}\x{FEFF}]';

    /** @var array<int, array{id:int, member_no:?string, name:string, short:string, state:string}> */
    private array $clubs = [];

    /** @var array{name: array<string, list<int>>, short: array<string, list<int>>, alias: array<string, list<int>>} */
    private array $index = ['name' => [], 'short' => [], 'alias' => []];

    /**
     * @param list<array{id:int, member_no:?string, name:string, short:string, state:?string, aliases:list<string>}> $clubs
     * @throws InvalidArgumentException bei doppelter id
     */
    public function __construct(array $clubs)
    {
        foreach ($clubs as $c) {
            $id = (int) $c['id'];
            if (isset($this->clubs[$id])) {
                throw new InvalidArgumentException('Verein mit id ' . $id . ' doppelt übergeben');
            }
            $memberNo = self::trim((string) ($c['member_no'] ?? ''));
            $state = (string) ($c['state'] ?? '');
            $this->clubs[$id] = [
                'id' => $id,
                'member_no' => $memberNo !== '' ? $memberNo : null,
                'name' => (string) $c['name'],
                'short' => (string) ($c['short'] ?? ''),
                // Nur exakt BER oder BRA; alles andere gilt als nicht gepflegt.
                'state' => in_array($state, self::STATES, true) ? $state : '',
            ];
            $this->add('name', (string) $c['name'], $id);
            $this->add('short', (string) ($c['short'] ?? ''), $id);
            foreach ($c['aliases'] ?? [] as $alias) {
                $this->add('alias', (string) $alias, $id);
            }
        }
    }

    /** @return array{club_id:?int, member_no:?string, name:string, short:string, state:string, known:bool} */
    public function lookup(string $text): array
    {
        $key = self::normalize($text);
        if ($key !== '') {
            foreach (['name', 'short', 'alias'] as $tier) {
                $ids = $this->index[$tier][$key] ?? [];
                if (count($ids) === 1) {
                    $c = $this->clubs[$ids[0]];
                    return [
                        'club_id' => $c['id'], 'member_no' => $c['member_no'], 'name' => $c['name'],
                        'short' => $c['short'], 'state' => $c['state'], 'known' => true,
                    ];
                }
            }
        }
        return [
            'club_id' => null, 'member_no' => null, 'name' => self::trim($text),
            'short' => '', 'state' => '', 'known' => false,
        ];
    }

    private function add(string $tier, string $text, int $id): void
    {
        $key = self::normalize($text);
        if ($key === '') {
            return;
        }
        $ids = $this->index[$tier][$key] ?? [];
        if (!in_array($id, $ids, true)) {
            $ids[] = $id;
        }
        $this->index[$tier][$key] = $ids;
    }

    /** Vergleichsschlüssel: Leerraum vereinheitlicht, Groß-/Kleinschreibung gefaltet (ß = ss). */
    public static function normalize(string $text): string
    {
        $t = preg_replace('/' . self::SPACE . '+/u', ' ', self::trim($text)) ?? $text;
        return mb_convert_case($t, MB_CASE_FOLD, 'UTF-8');
    }

    private static function trim(string $text): string
    {
        return preg_replace('/^' . self::SPACE . '+|' . self::SPACE . '+$/u', '', $text) ?? trim($text);
    }
}
