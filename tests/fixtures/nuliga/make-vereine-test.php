<?php
declare(strict_types=1);

/*
 * Erzeugt vereine-test.csv: erfundene Vereine im Aufbau des nuLiga-Exports „Vereine (Stammdaten und Adressen)“
 * (Windows-1252, Trennzeichen ;, CRLF, alle 48 Spalten in Originalreihenfolge, Leerzeile am Ende).
 * Alle Namen, Nummern, Bank- und Adressdaten sind erfunden. Aufruf:
 *   docker compose -f tools/dev-env/docker-compose.dev.yml run --rm php php tests/fixtures/nuliga/make-vereine-test.php
 */

const COLUMNS = ['Verband', 'Region', 'VereinNr', 'DebitorNr', 'DBVNr', 'LSBNr', 'VereinName', 'VereinKurzName',
    'VereinDruckname', 'VereinSortierName', 'Externe-Nr.', 'Vereinsregister-Nr.', 'Gläubiger-ID', 'Gruendungsjahr',
    'Gründungsjahr Abteilung', 'Eintrittsdatum', 'Verein ist Verband, Bezirk oder Kreis bzw. Region',
    'Verein ist Schule, Institution, Veranstalter oder Sonstiges', 'Personalisierte Anmeldung', 'Vereinslogin gesperrt',
    'Sperre der nuTurnier Funktionalität', 'Vereinsart Mitgliedsverein/Nicht-Mitgliedsverein', 'ReinerBadmintonverein',
    'Mehrspartenverein', 'Freizeitverein', 'Spielgemeinschaft', 'Spielbetrieb', 'Status', 'Austrittsdatum', 'Bemerkungen',
    'BankBLZ', 'BankBIC', 'BankName', 'BankKontoNummer', 'BankKontoIBAN', 'BankKontoInhaber', 'Zahlungsart',
    'AdresseName', 'AdresseStrasse', 'AdressePLZ', 'AdresseOrt', 'AdresseEMail1', 'AdresseEMail2', 'AdresseHomepage',
    'AdresseTelefonPrivat', 'AdresseTelefonGeschaeft', 'AdresseTelefonMobil', 'BeitrittMoeglich'];

/** [VereinNr, DBVNr, Name, Kurzname, Druckname, Status, Verband?, Austrittsdatum, Bemerkungen] */
$clubs = [
    ['01', '08-0001', 'SV Berliner Brauereien', 'SV Berliner Brauereien (Berlin)', 'SVBB', 'aktiv', '0', '', ''],
    ['13', '08-0013', 'BC Potsdam', 'BC Potsdam', 'BCP', 'aktiv', '0', '', ''],
    ['07', '08-0007', 'Köpenicker Testclub', 'Köpenicker TC', "Köp'TC", 'aktiv', '0', '', ''],
    ['21', '08-0021', 'SG Grünau Test', 'SG Grünau', '', 'aktiv', '0', '', ''],
    ['22', '08-0022', 'BSV Weißensee Test', 'BSV Weißensee', "W'see", 'aktiv', '0', '', ''],
    ['30', '08-0031', 'SC Abweichung Test', '', '', 'aktiv', '0', '', ''],
    ['40', '08-0040', 'Verein; mit Semikolon Test', 'Semikolon', '', 'aktiv', '0', '', ''],
    ['41', '08-0041', 'BC Bemerkung Test', '', 'BC Bem.', 'aktiv', '0', '',
        "Früher „BC Altname Test“\r\nunter Nr. 12, Registergericht Musterstadt"],
    ['42', '08-0042', 'BC Konflikt Test', 'BC Konflikt', 'SVBB', 'aktiv', '0', '', ''],
    ['501', '', 'Hobbyliga Testverein', '', '', 'Archiv', '0', '30.06.2019', ''],
    ['523', '', 'TSG Altstadt Test (ALT)', '', '', 'Archiv', '0', '31.12.2020', 'Neue Nummer 86'],
    ['0815', '', 'Test Verein CP', '', '', 'Archiv', '0', '01.01.2015', ''],
    ['9999', '', 'SV Spielberechtigung Test e.V.', '', '', 'aktiv', '1', '01.01.2010', ''],
];

$quote = static function (string $v): string {
    return preg_match('/[;"\r\n]/', $v) === 1 ? '"' . str_replace('"', '""', $v) . '"' : $v;
};

$lines = [implode(';', array_map($quote, COLUMNS))];
foreach ($clubs as $i => [$nr, $dbv, $name, $kurz, $druck, $status, $verband, $austritt, $bem]) {
    $values = array_fill_keys(COLUMNS, '');
    $values['Verband'] = 'BVBB';
    $values['Region'] = 'Berlin-Brandenburg';
    $values['VereinNr'] = $nr;
    $values['DebitorNr'] = (string) (10000 + $i);
    $values['DBVNr'] = $dbv;
    $values['LSBNr'] = $dbv;
    $values['VereinName'] = $name;
    $values['VereinKurzName'] = $kurz;
    $values['VereinDruckname'] = $druck;
    $values['Gläubiger-ID'] = 'DE00ZZZ0000000' . sprintf('%04d', $i);
    $values['Gruendungsjahr'] = '1990';
    $values['Eintrittsdatum'] = '01.01.1991';
    $values['Verein ist Verband, Bezirk oder Kreis bzw. Region'] = $verband;
    $values['Verein ist Schule, Institution, Veranstalter oder Sonstiges'] = '0';
    $values['Personalisierte Anmeldung'] = 'ja';
    $values['Vereinslogin gesperrt'] = 'Nein';
    $values['Sperre der nuTurnier Funktionalität'] = 'nein';
    $values['Vereinsart Mitgliedsverein/Nicht-Mitgliedsverein'] = 'Mitgliedsverein';
    $values['ReinerBadmintonverein'] = '1';
    $values['Mehrspartenverein'] = '0';
    $values['Freizeitverein'] = '0';
    $values['Spielgemeinschaft'] = '0';
    $values['Spielbetrieb'] = 'ja';
    $values['Status'] = $status;
    $values['Austrittsdatum'] = $austritt;
    $values['Bemerkungen'] = $bem;
    $values['BankBLZ'] = '10000000';
    $values['BankBIC'] = 'TESTDEFFXXX';
    $values['BankName'] = 'Testbank Musterstadt';
    $values['BankKontoNummer'] = sprintf('%010d', 1000 + $i);
    $values['BankKontoIBAN'] = 'DE00100000000000' . sprintf('%06d', 1000 + $i);
    $values['BankKontoInhaber'] = $name;
    $values['Zahlungsart'] = 'Lastschrift';
    $values['AdresseName'] = 'Erika Mustermann';
    $values['AdresseStrasse'] = 'Musterstraße ' . ($i + 1);
    $values['AdressePLZ'] = '10' . sprintf('%03d', $i);
    $values['AdresseOrt'] = 'Musterstadt';
    $values['AdresseEMail1'] = 'verein' . $i . '@example.org';
    $values['AdresseHomepage'] = 'https://verein' . $i . '.example.org';
    $values['AdresseTelefonMobil'] = '0170 0000' . sprintf('%03d', $i);
    $values['BeitrittMoeglich'] = '1';
    $lines[] = implode(';', array_map($quote, array_values($values)));
}

$csv = implode("\r\n", $lines) . "\r\n\r\n";
$out = mb_convert_encoding($csv, 'Windows-1252', 'UTF-8');
file_put_contents(__DIR__ . '/vereine-test.csv', $out);
echo count($clubs), " erfundene Vereine geschrieben (Windows-1252).\n";
