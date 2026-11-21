# Projektstatus

Diese Datei ist das gemeinsame Gedächtnis für beide Entwicklungsrechner. Claude Code liest sie zu Beginn jeder Sitzung und aktualisiert sie am Ende. Sie wird mit committet.

| Feld | Wert |
|---|---|
| Zuletzt bearbeitet | 21.11.2026, Windows-PC (über Claude-App) |
| Aktueller Zweig | task-1-test-runner |
| Letzte fertige Aufgabe | Task 1 |
| In Arbeit | nichts |

## Aufgabenstand (Etappe 1)

| Task | Stand | Zweig | Bemerkung |
|---|---|---|---|
| 0 Umgebung | teilweise | | Git und Dateien geprüft (Repo, noreply-Adresse, Hook-Pfad). Docker, PHP 8.4 und NAS-Datenbank in dieser Sitzung nicht erreichbar, `check-env.php` bitte auf dem PC ausführen |
| 1 Test-Runner, Konfiguration | fertig | task-1-test-runner | 4 Tests grün unter PHP 8.3.6, noch nicht unter PHP 8.4 gelaufen |
| 2 Datenbank, Migrationen | offen | | |
| 3 Altersklasse | offen | | |
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

## Nächster Schritt

Auf dem PC `docker compose -f tools/dev-env/docker-compose.dev.yml run --rm php php tests/run.php` ausführen (PHP 8.4), dann Task 2 (Datenbank, Migrationen).
