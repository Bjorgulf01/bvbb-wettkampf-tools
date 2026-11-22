<?php
declare(strict_types=1);

/*
 * Richtet die Test-Datenbank dieses Rechners für die lokale Vorschau ein: löscht ALLE Tabellen,
 * spielt die Migrationen ein und legt erfundene Vereine an (Namen wie in testdata/Testkaderliste_fiktiv.xlsx,
 * Kürzel leer). Spieler kommen über den Import in der Oberfläche dazu.
 * Aufruf: docker compose -f tools/dev-env/docker-compose.dev.yml run --rm php php tools/dev-env/seed-preview.php
 * Nur für Datenbanken, deren Name mit bvbb_test beginnt.
 */

require __DIR__ . '/../../src/autoload.php';
require __DIR__ . '/../../tests/support/assert.php';
require __DIR__ . '/../../tests/support/db.php';

use App\Migrator;

$pdo = testPdo(); // prüft den Namen der Datenbank und löscht alle Tabellen
(new Migrator($pdo, __DIR__ . '/../../migrations'))->migrate();

$clubs = [
    ['SV Berliner Brauereien', 'BER', ['SVBB']],
    ['Berliner SC', 'BER', []],
    ['Pro Sport Berlin', 'BER', []],
    ['SG EBT Berlin', 'BER', []],
    ['BC Tempelhof', 'BER', []],
    ['TSV Tempelhof-Mariendorf', 'BER', []],
    ['SC Siemensstadt', 'BER', []],
    ['Zehlendorfer TSV', 'BER', []],
    ['TSV Spandau', 'BER', ['TSV-Spandau']],
    ['BG Neukölln', 'BER', []],
    ['BC Eintracht Südring', 'BER', []],
    ['Vfb Kiefholz', 'BER', []],
    ['BC Potsdam', 'BRA', []],
    ['SC Brandenburg', 'BRA', []],
    ['BC Schöneiche/Rüdersdorf', 'BRA', []],
    ['SG RW Neuenhagen', 'BRA', []],
];
$club = $pdo->prepare("INSERT INTO clubs (name, short, state) VALUES (?, '', ?)");
$alias = $pdo->prepare('INSERT INTO club_aliases (club_id, alias) VALUES (?, ?)');
foreach ($clubs as [$name, $state, $aliases]) {
    $club->execute([$name, $state]);
    $id = (int) $pdo->lastInsertId();
    foreach ($aliases as $a) {
        $alias->execute([$id, $a]);
    }
}
echo 'Vorschau bereit: ', count($clubs), " erfundene Vereine, keine Spieler.\n";
