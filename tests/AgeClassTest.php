<?php
declare(strict_types=1);

use App\Domain\AgeClass;

return [
    'Referenzwerte: Jahrgang 2008 und 2015' => function (): void {
        assertSame('U19', AgeClass::for('2008', '', 2026));
        assertSame('O19', AgeClass::for('2008', '', 2027));
        assertSame('U13', AgeClass::for('2015', '', 2026));
        assertSame('U13', AgeClass::for('2015', '', 2027));
        assertSame('U15', AgeClass::for('2015', '', 2028));
    },
    'Grenzen in 2026' => function (): void {
        foreach ([
            ['2018', 'U09'], ['2017', 'U11'], ['2016', 'U11'], ['2015', 'U13'],
            ['2013', 'U15'], ['2011', 'U17'], ['2009', 'U19'], ['2008', 'U19'], ['2007', 'O19'], ['1990', 'O19'],
        ] as [$jg, $ak]) {
            assertSame($ak, AgeClass::for($jg, '', 2026), 'Jahrgang ' . $jg);
        }
    },
    'Datum als Eingabe' => function (): void {
        assertSame('U13', AgeClass::for('14.09.2014', '', 2026));
        assertSame('U13', AgeClass::for(' 2014 ', '', 2026), 'Leerzeichen am Rand');
    },
    'Jahrgang hat Vorrang vor der AK aus der Liste' => function (): void {
        assertSame('U19', AgeClass::for('2008', 'U17', 2026));
    },
    'ohne Jahrgang gilt die AK aus der Liste' => function (): void {
        assertSame('U09', AgeClass::for('', 'U 09', 2026));
        assertSame('U09', AgeClass::for(null, 'u9', 2026));
        assertSame('U17', AgeClass::for('', 'U17', 2026));
        assertSame('O19', AgeClass::for('', 'U22', 2026));
        assertSame('O19', AgeClass::for('', 'O22', 2026));
        assertSame('O19', AgeClass::for('', 'O19', 2026));
        assertSame('', AgeClass::for('', '', 2026));
        assertSame('', AgeClass::for('', '   ', 2026));
    },
    'unbrauchbarer Jahrgang zählt als fehlend' => function (): void {
        assertSame('U13', AgeClass::for('41896', 'U13', 2026), 'Excel-Seriennummer ist kein Jahrgang');
        assertSame('U13', AgeClass::for('14092014', 'U13', 2026), 'Ziffernfolge ohne Trenner');
        assertSame('O19', AgeClass::for('2062', 'O22', 2026), 'Jahrgang nach dem Turnierjahr');
        assertSame('', AgeClass::for('2062', '', 2026));
        assertSame('U09', AgeClass::for('2026', '', 2026), 'Alter 0 ist noch gültig');
    },
    'unbrauchbare AK aus der Liste ergibt leer' => function (): void {
        assertSame('', AgeClass::for('', 'U10', 2026), 'keine gültige Altersklasse');
        assertSame('', AgeClass::for('', 'abc', 2026));
        assertSame('', AgeClass::for('kein Jahr', 'U', 2026));
    },
    'yearFromTerm nimmt die letzte Jahreszahl, sonst das Jahr von heute' => function (): void {
        $today = new DateTimeImmutable('2026-11-21');
        assertSame(2026, AgeClass::yearFromTerm('27.02.–01.03.2026', $today));
        assertSame(2027, AgeClass::yearFromTerm('30.12.2026–02.01.2027', $today));
        assertSame(2026, AgeClass::yearFromTerm('27.02.–01.03.', $today));
        assertSame(2026, AgeClass::yearFromTerm('', $today));
    },
];
