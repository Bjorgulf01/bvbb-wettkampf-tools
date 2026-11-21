<?php
declare(strict_types=1);

use App\Domain\HealthStatus;

/** Prüft eine Liste [Eingabe, erwartetes Ergebnis] mit Jahr 2026 und Stichtag 16.11.2026. */
$check = static function (array $cases): void {
    $today = new DateTimeImmutable('2026-11-16');
    foreach ($cases as [$note, $expected]) {
        assertSame($expected, HealthStatus::fromNote($note, 2026, $today), var_export($note, true));
    }
};

return [
    'Regeltabelle aus dem Plan' => function () use ($check): void {
        $check([
            ['', 'open'],
            ['Erledigt', 'done'],
            ['/', 'open'],
            ['abgesagt', 'open'],
            ['Kein neuer Termin, weil …', 'unclear'],
            ['Neuer Termin 10.06', 'scheduled'],
            ['03.04.2025 (Termin verschoben … angefragt)', 'scheduled'],
            ['angefragt', 'scheduled'],
            ['kommt bald', 'scheduled'],
            ['15.12.2026', 'scheduled'],
            ['12.03.2026', 'done'],
            ['26.02.2025', 'open'],
            ['23.2.', 'unclear'],
            ['U09', 'unclear'],
        ]);
    },
    'Zusatzfälle aus dem Plan' => function () use ($check): void {
        $check([
            [null, 'open'],
            ['erledigt', 'done'],
            ['30.06.2026', 'done'],
        ]);
    },
    'Schreibweisen und Leerraum' => function () use ($check): void {
        $check([
            ['   ', 'open'],
            [' ERLEDIGT ', 'done'],
            [' / ', 'open'],
            ['Abgesagt', 'open'],
            ['KEIN Termin', 'unclear'],
            ['Termin', 'scheduled'],
            ['30.06.26', 'done'],
            ['1.7.2026', 'done'],
            ['23.02.', 'unclear'],
        ]);
    },
    'Stichtag selbst zählt als erledigt' => function () use ($check): void {
        $check([
            ['16.11.2026', 'done'],
            ['17.11.2026', 'scheduled'],
        ]);
    },
    'Datum aus einem späteren Jahr oder ungültiges Datum ist unklar' => function () use ($check): void {
        $check([
            ['10.01.2027', 'unclear'],
            ['31.02.2026', 'unclear'],
        ]);
    },
    'Erledigt mit Datum folgt dem Datum' => function () use ($check): void {
        $check([
            ['Erledigt 12.03.2026', 'done'],
            ['erledigt am 26.02.2025', 'open'],
        ]);
    },
    'abgesagt zählt auch als Teil des Textes und vor Termin und Datum' => function () use ($check): void {
        $check([
            ['abgesagt 12.03.2026', 'open'],
            ['Abgesagt am 03.03.26', 'open'],
            ['Termin abgesagt', 'open'],
            ['abgesagt, neuer Termin 10.06', 'open'],
        ]);
    },
    'Erledigt mit Satzzeichen am Ende' => function () use ($check): void {
        $check([
            ['Erledigt.', 'done'],
            ['erledigt!', 'done'],
            ['Erledigt ✓', 'done'],
        ]);
    },
    'ungültiges UTF-8 ist unklar' => function () use ($check): void {
        $check([
            ["Termin 12.03.2026 \xFC", 'unclear'],
            ["\xFC", 'unclear'],
        ]);
    },
    'Werte aus der Testkaderliste' => function () use ($check): void {
        $check([
            ['11.04.25', 'open'],
            ['14.09.2026', 'done'],
            ['Angefragt 02.01', 'scheduled'],
            ['angefragt 27.10', 'scheduled'],
            ['Neuer Termin im Sept 2026 (aktuelle Untersuchung ist gültig)', 'scheduled'],
            ['Kein neuer Termin, weil Spieler den LZ verlässt', 'unclear'],
        ]);
    },
    'Ergebnis ist immer einer der vier Werte' => function (): void {
        $today = new DateTimeImmutable('2026-11-16');
        foreach (['x', '12', '2026', "Erledigt\n", '..', '99.99.9999'] as $note) {
            assertTrue(
                in_array(HealthStatus::fromNote($note, 2026, $today), ['done', 'scheduled', 'open', 'unclear'], true),
                var_export($note, true)
            );
        }
    },
];
