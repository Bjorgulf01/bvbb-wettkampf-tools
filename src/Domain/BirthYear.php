<?php
declare(strict_types=1);

namespace App\Domain;

/**
 * Jahrgang aus dem Geburtstag (Entwurf „Kaderliste rund machen“, Variante A).
 * Ist nach dem Speichern ein Geburtstag gesetzt, ist der Jahrgang dessen Jahr. Ein mitgeschickter
 * Jahrgang, der davon abweicht, ist ungültig. Ohne Geburtstag ist der Jahrgang frei; wird der
 * Geburtstag geleert, bleibt der Jahrgang stehen.
 */
final class BirthYear
{
    /**
     * @param array<string, mixed> $changes geprüfte Felder einer Anfrage (birth_date als JJJJ-MM-TT oder null,
     *     birth_year als int oder null)
     * @param ?string $storedBirthDate gespeicherter Geburtstag, beim Anlegen null
     * @return array{changes: array<string, mixed>, invalid: list<string>}
     */
    public static function reconcile(array $changes, ?string $storedBirthDate): array
    {
        if (!array_key_exists('birth_date', $changes) && !array_key_exists('birth_year', $changes)) {
            return ['changes' => $changes, 'invalid' => []];
        }
        $date = array_key_exists('birth_date', $changes) ? $changes['birth_date'] : $storedBirthDate;
        if ($date === null) {
            return ['changes' => $changes, 'invalid' => []];
        }
        $year = (int) substr((string) $date, 0, 4);
        if (array_key_exists('birth_year', $changes) && $changes['birth_year'] !== $year) {
            return ['changes' => $changes, 'invalid' => ['birth_year']];
        }
        $changes['birth_year'] = $year;
        return ['changes' => $changes, 'invalid' => []];
    }
}
