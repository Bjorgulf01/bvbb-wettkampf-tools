<?php
declare(strict_types=1);

/*
 * Vorlage für config.php. Kopieren nach config.php im Programmordner (auf dem Webspace bvbb-app/,
 * nie in das öffentliche Verzeichnis) und ausfüllen. config.php wird nie committet.
 */
return [
    // development (lokal), staging (Test-Webspace) oder production (Echtbetrieb).
    'env' => 'production',

    // Datenbank (MariaDB, utf8mb4). Zugangsdaten aus dem Kundenmenü des Hosters.
    'db' => [
        'dsn' => 'mysql:host=DATENBANK-HOST;dbname=DATENBANK-NAME;charset=utf8mb4',
        'user' => 'BENUTZER',
        'pass' => 'PASSWORT',
    ],

    // Testanmeldung mit erfundenen Personen. Nur in development und staging; im Echtbetrieb startet
    // der Server mit true nicht. Im Echtbetrieb weglassen oder false.
    'test_auth' => false,

    // Kennung der Person, die bei ihrer ersten Anmeldung zusätzlich die Rolle admin bekommt.
    // Testpersonen: "test:" plus Schlüssel, zum Beispiel "test:admin" oder "test:kaderpflege".
    // Wirkt nur beim ersten Anlegen der Person; danach Rollen in der Verwaltung vergeben.
    'first_admin_subject' => '',

    // Höchste Dauer einer Sitzung ab der Anmeldung, in Sekunden (8 Stunden).
    'session_max_age' => 8 * 3600,

    // Eigener Ordner für Sitzungsdateien, beschreibbar und nicht öffentlich (empfohlen auf dem Webspace).
    // Ohne Eintrag nimmt PHP den Standardordner des Hosters.
    'session_save_path' => __DIR__ . '/sessions',
];
