<?php
declare(strict_types=1);

use App\Domain\BirthYear;

return [
    'ohne birth_date und birth_year bleibt alles unverändert' => function (): void {
        $r = BirthYear::reconcile(['email1' => 'a@example.org'], '2014-09-14');
        assertSame(['email1' => 'a@example.org'], $r['changes']);
        assertSame([], $r['invalid']);
    },
    'Geburtstag ohne Jahrgang setzt den Jahrgang' => function (): void {
        $r = BirthYear::reconcile(['birth_date' => '2014-09-14'], null);
        assertSame(2014, $r['changes']['birth_year']);
        assertSame([], $r['invalid']);
    },
    'Geburtstag mit passendem Jahrgang wird angenommen' => function (): void {
        $r = BirthYear::reconcile(['birth_date' => '2014-09-14', 'birth_year' => 2014], null);
        assertSame([], $r['invalid']);
        assertSame(['birth_date' => '2014-09-14', 'birth_year' => 2014], $r['changes']);
    },
    'Geburtstag mit abweichendem Jahrgang ist ungültig' => function (): void {
        $r = BirthYear::reconcile(['birth_date' => '2014-09-14', 'birth_year' => 2015], null);
        assertSame(['birth_year'], $r['invalid']);
    },
    'Jahrgang allein, der vom gespeicherten Geburtstag abweicht, ist ungültig' => function (): void {
        $r = BirthYear::reconcile(['birth_year' => 2015], '2014-09-14');
        assertSame(['birth_year'], $r['invalid']);
    },
    'Jahrgang allein, der zum gespeicherten Geburtstag passt, wird angenommen' => function (): void {
        $r = BirthYear::reconcile(['birth_year' => 2014], '2014-09-14');
        assertSame([], $r['invalid']);
        assertSame(['birth_year' => 2014], $r['changes']);
    },
    'Jahrgang ohne Geburtstag ist frei' => function (): void {
        $r = BirthYear::reconcile(['birth_year' => 2015], null);
        assertSame(2015, $r['changes']['birth_year']);
        assertSame([], $r['invalid']);
    },
    'Jahrgang null ohne Geburtstag bleibt null' => function (): void {
        $r = BirthYear::reconcile(['birth_year' => null], null);
        assertSame(['birth_year' => null], $r['changes']);
        assertSame([], $r['invalid']);
    },
    'geleerter Geburtstag lässt den Jahrgang stehen' => function (): void {
        $r = BirthYear::reconcile(['birth_date' => null], '2014-09-14');
        assertSame(['birth_date' => null], $r['changes']);
        assertTrue(!array_key_exists('birth_year', $r['changes']), 'kein birth_year in den Änderungen');
        assertSame([], $r['invalid']);
    },
    'neuer Geburtstag ersetzt den gespeicherten, der Jahrgang folgt' => function (): void {
        $r = BirthYear::reconcile(['birth_date' => '2013-01-02'], '2014-09-14');
        assertSame(2013, $r['changes']['birth_year']);
        assertSame([], $r['invalid']);
    },
    'Jahresgrenze: 31.12. und 01.01.' => function (): void {
        assertSame(2013, BirthYear::reconcile(['birth_date' => '2013-12-31'], null)['changes']['birth_year']);
        assertSame(2014, BirthYear::reconcile(['birth_date' => '2014-01-01'], null)['changes']['birth_year']);
    },
];
