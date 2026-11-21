# Projektstatus

Diese Datei ist das gemeinsame Gedächtnis für beide Entwicklungsrechner. Claude Code liest sie zu Beginn jeder Sitzung und aktualisiert sie am Ende. Sie wird mit committet.

| Feld | Wert |
|---|---|
| Zuletzt bearbeitet | 21.11.2026, Windows-PC (Claude Code) |
| Aktueller Zweig | task-3-age-class |
| Letzte fertige Aufgabe | Task 3 |
| In Arbeit | nichts |

## Aufgabenstand (Etappe 1)

| Task | Stand | Zweig | Bemerkung |
|---|---|---|---|
| 0 Umgebung | fertig | | `check-env.php` meldet „Alles in Ordnung“ (21.11.2026, Windows-PC). Claude in der App erreicht Docker und NAS nicht, Tests mit Datenbank laufen auf dem PC |
| 1 Test-Runner, Konfiguration | fertig | task-1-test-runner | 4 Tests grün, auch im Docker-Container unter PHP 8.4 (21.11.2026) |
| 2 Datenbank, Migrationen | fertig | task-2-database | 13 Tests grün gegen `bvbb_test_pc` (PHP 8.4), Review erledigt, zusammengeführt (Pull-Request #2) |
| 3 Altersklasse | fertig | task-3-age-class | 8 Tests grün (PHP 8.4), Review erledigt, noch nicht zusammengeführt |
| 4 Vereinszuordnung | offen | | |
| 4b Ampel | offen | | |
| 5 Rechte | offen | | |
| 6 HTTP-Grundlage | offen | | |
| 7 Anmeldung, Sitzung, CSRF | offen | | |
| 8 Spieler-Schnittstelle | offen | | |
| 9 Datenprüfung, Import | offen | | |
| 9b Vereinsimport | zurückgestellt | | wartet auf Mitgliederliste |
| 10 Benutzer, Rollen, Protokoll | offen | | |
| 11 Oberfläche | offen | | |
| 12 Auslieferung | offen | | |

## Entscheidungen und Abweichungen vom Plan

- Task 1: Zusätzlich zu den drei geplanten Tests gibt es einen vierten (fromFile meldet fehlende oder ungültige Datei). Ein Lauf, in dem nur übersprungene Tests vorkommen, endet mit Exit-Code 0 und der Warnung, dass dies kein Erfolg ist.
- Task 2: Das Vereinsschema folgt der geänderten Fassung aus „Vereinsstammdaten aus der Mitgliederliste“ (`clubs.member_no`, `club_aliases`, `players.club_id`), die Rechte enthalten die Zeile `player.health_light` aus Task 4b. `clubs.member_no` darf bis Task 9b leer sein (eindeutig, wenn gesetzt), `clubs.name` ist nicht mehr eindeutig. `clubs.state` ist `BER`, `BRA` oder leer und wird nie automatisch gesetzt.
- Task 2: Jede Migrationsdatei trägt sich mit ihrer letzten Anweisung selbst in `schema_migrations` ein (ein Test prüft das). Der `Migrator` erkennt per phpMyAdmin eingespielte Dateien und wendet sie nicht noch einmal an.
- Task 2: Zusätzliche Tests: SQL wird korrekt an Semikolons zerlegt, eine fehlerhafte Migration wird nicht vermerkt, alle Tabellen nutzen InnoDB und `utf8mb4_unicode_ci`, die Rechte-Matrix wird vollständig geprüft.
- Task 2: `testPdo()` löscht nur, wenn der Datenbankname mit `bvbb_test` beginnt. Das schützt vor versehentlich falschen Zugangsdaten.
- Task 2, nach dem Review: `users.subject` vergleicht exakt (`ascii_nopad_bin`: Groß- und Kleinschreibung und Leerzeichen am Ende zählen), `clubs.state` lässt nur genau `BER` und `BRA` zu (`ascii_bin`). `Db::connect` setzt den Strict-Modus (zu lange Werte ergeben einen Fehler, statt abgeschnitten zu werden) und ergänzt `charset=utf8mb4` im DSN, falls er fehlt. Die Tabellen in 001 entstehen mit `CREATE TABLE IF NOT EXISTS`, damit nach einem Abbruch ein neuer Lauf möglich ist. `sportmed_note` und `antidoping_note` sind `TEXT`. Ausführbare Kommentare (`/*! … */`) in Migrationen werden mit einem Fehler abgelehnt.
- Task 2: Die Zeitzone der Datenbankverbindung ist nicht festgelegt (Serverstandard). Sie wird geklärt, sobald Zeitstempel angezeigt werden (Protokoll, Task 10).
- Task 3: Als Jahrgang zählt nur eine eigenständige Jahreszahl 19xx oder 20xx (nicht Teil einer längeren Zahl wie einer Excel-Seriennummer `41896`). Ein Jahrgang nach dem Turnierjahr gilt als unbrauchbar. In beiden Fällen gilt wie ohne Jahrgang die AK aus der Liste. Die Datenprüfung (Task 9) soll solche Jahrgänge melden.
- Task 3: Eine AK aus der Liste, die keine gültige Klasse ist (zum Beispiel `U10`), ergibt `''`. Der Prototyp lieferte hier `U10`. In der Testkaderliste kommen nur `U09` bis `U22`, `O22` und `U 09` vor. Die Datenprüfung (Task 9) soll ungültige AK-Werte melden.
- Task 3: `yearFromTerm` erkennt wie der Prototyp nur vierstellige Jahreszahlen 19xx und 20xx. Ein Termin mit zweistelliger Jahreszahl („02.–04.01.27“) ergibt das laufende Jahr. Im Prototyp lässt sich das AK-Jahr deshalb von Hand setzen, das gehört in Etappe 2 (Turniere).
- **Geklärt am 21.11.2026 (betrifft Task 5 und 8):** Laut Entwurf, Abschnitt 6, schreibt NADA/Sportmedizin die „Daten und Termine“. In Etappe 1 gilt aber der Plan (Abgrenzung): Die Textspalten SpoMed und Anti Doping (`player.private`) sehen nur Kaderpflege und Administrator, `health_staff` sieht nur die Ampel (`player.health_light`). Der Projektinhaber hat das bestätigt.

## Nächster Schritt

Task 3 zusammenführen (Pull-Request von `task-3-age-class`). Danach Task 4 (Vereinszuordnung), 4b (Ampel) oder 5 (Rechte), die voneinander unabhängig sind. Vorschlag: Task 4.
