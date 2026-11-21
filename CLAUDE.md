# BVBB Wettkampf-Tools

Webanwendung des Badminton-Verbands Berlin-Brandenburg (BVBB) für Turnierabrechnung und eine zentrale Kaderliste. Ersetzt Excel-Dateien. Nutzer sind 5 bis 7 Personen (Trainer, Kaderpflege, Prüfer, Administrator). Antworte dem Projektinhaber auf Deutsch.

## Zuerst lesen

0. `docs/STATUS.md`: aktueller Stand und nächster Schritt (zuerst lesen).
1. `docs/entwurf.md`: freigegebener Entwurf (Ziele, Rollen, Datenmodell, Etappen). Maßgeblich für das Was und Warum.
2. `docs/umsetzungsplan-etappe-1.md`: Aufgaben mit Tests. Maßgeblich für das Wie. Arbeite die Aufgaben der Reihe nach ab (1, 2, 3, 4, 4b, 5 bis 9, 9b, 10 bis 12).
3. `prototype/turnierabrechnung.html`: funktionierender Prototyp (eine HTML-Seite, Version 1.5.1). Er ist die Vorlage für Rechenlogik und Oberfläche, **kein Produktivcode**.
4. Widerspricht der Plan dem Entwurf, gilt der Entwurf. Melde den Widerspruch, bevor du weiterarbeitest. Ändere Entwurf und Plan nicht stillschweigend.

## Arbeitsweise

- Nutze die Superpowers-Skills: `test-driven-development` (Test zuerst, sehen, dass er scheitert), `verification-before-completion` (nichts als fertig melden ohne Testlauf und sichtbares Ergebnis), `requesting-code-review` nach jeder Aufgabe, `systematic-debugging` bei Fehlern. Für neue Themen, die nicht im Plan stehen, zuerst `brainstorming`.
- Ein Commit je Aufgabe, Nachrichten wie im Plan (`feat: ...`).
- Die **Review-Fokus-Fälle** im Plan (Umlaute, Mass Assignment, mehrere oder keine Rollen, Import-Randfälle, Sitzung und CSRF) sind Pflichttests, keine Option.
- Keine Frameworks und keine Laufzeit-Abhängigkeiten ohne Rückfrage. Der Server hat kein Composer und kein SSH. Entwicklungswerkzeuge sind lokal erlaubt.
- Frage nach, wenn Anforderungen unklar sind. Rate bei Rechten und Datenschutz nie.

## Arbeiten auf mehreren Rechnern

Das Projekt wird auf einem Windows-PC und einem MacBook entwickelt, teils abwechselnd, teils zeitgleich. Der gemeinsame Stand liegt im Git-Repository, nie in einem Synchronisationsordner.

- **Gehostetes privates Repository** (`origin`). Einrichtung: `docs/REPO-SETUP.md`. Auf jeder Kopie des Projekts einmal `git config core.hooksPath tools/git-hooks` ausführen. Der Schutz in `tools/git-hooks/pre-commit` verhindert, dass echte Daten oder Zugangsdaten committet werden. Umgehe ihn nie mit `--no-verify`.
- **Zu Beginn jeder Sitzung:** `git pull`, dann `docs/STATUS.md` lesen.
- **Am Ende jeder Sitzung:** `docs/STATUS.md` aktualisieren (Stand der Aufgaben, Abweichungen vom Plan, nächster Schritt), committen und `git push`.
- **Ein Zweig pro Aufgabe** (`task-3-age-class`), zusammengeführt nach grünen Tests und Review. Arbeite nie auf dem Hauptzweig, wenn du nicht sicher bist, dass der andere Rechner nicht gerade an derselben Stelle arbeitet.
- **Jeder Rechner nutzt seine eigene Test-Datenbank** auf dem NAS (`bvbb_test_pc`, `bvbb_test_mac`), damit sich Testläufe nicht stören.
- **Dateinamen exakt wie Klassennamen** (Groß- und Kleinschreibung). Windows und macOS unterscheiden sie nicht, der Webserver schon.
- Aufgaben 1 und 2 macht ein Rechner allein. Danach sind 3, 4, 4b und 5 voneinander unabhängig.

## Zielumgebung (Ergebnis des Hosting-Checks)

| Punkt | Wert |
|---|---|
| PHP | 8.4.27, `fpm-fcgi`; Code muss ohne Deprecation-Hinweise laufen (zum Beispiel `?Typ $x = null` statt `Typ $x = null`) |
| Erweiterungen | pdo, pdo_mysql, mbstring, json, session, openssl, ctype, hash |
| Datenbank | MariaDB 10.11.18, utf8mb4, InnoDB, `CREATE` und `DROP` erlaubt. Sortierung im Schema ausdrücklich setzen (zum Beispiel `utf8mb4_unicode_ci`) |
| Webserver | Apache, `.htaccess` und URL-Umschreibung funktionieren (getestet) |
| Limits | memory_limit 128M, post_max_size 75M, max_execution_time 60 s |
| HTTPS | aktiv |
| Zugang | nur SFTP und phpMyAdmin, **kein SSH**. Migrationen werden als SQL-Datei per phpMyAdmin importiert; jede Migrationsdatei trägt sich am Ende selbst in `schema_migrations` ein |
| Verzeichnisse | Der Inhalt von `public/` kommt in das öffentliche Verzeichnis `webseiten/`. `src/`, `migrations/` und `config.php` liegen in einem Ordner darüber (zum Beispiel `bvbb-app/`), den der Webserver nicht ausliefert. Der Ordner darüber ist beschreibbar |

## Lokale Entwicklungsumgebung (Aufgabe 0)

Entscheidung des Projektinhabers: PHP 8.4 läuft in Docker auf dem jeweiligen Rechner, die Test-Datenbank (MariaDB 10.11) läuft auf seinem NAS (`tools/dev-env/nas-mariadb-stack.yml`, Port 3307, nur im Heimnetz). Arbeitsrechner sind ein Windows-PC und eventuell ein MacBook. **Jeder Rechner nutzt seine eigene Datenbank auf diesem Server** (`bvbb_test_pc`, `bvbb_test_mac`), weil die Tests alle Tabellen ihrer Datenbank löschen. Unterwegs gibt es das Profil `local` (`bvbb_test_local`). Der Webspace ist nur Test-Umgebung für die Auslieferung (Task 12). Einrichtung: `tools/dev-env/README.md`.

Aufgabe 0 heißt prüfen, nicht installieren: `git --version`, `docker --version`, dann `docker compose -f tools/dev-env/docker-compose.dev.yml run --rm php php tools/dev-env/check-env.php`. Es muss „Alles in Ordnung.“ melden. Fehlt etwas, melde es dem Projektinhaber und schlage die Schritte vor. Installiere nichts ohne Rückfrage. Die Dateien in `tools/dev-env/` wurden ohne Docker und ohne PHP erstellt und sind **ungetestet**. Behebe Fehler darin und melde sie.

Datenbanktests lesen `TEST_DB_DSN`, `TEST_DB_USER`, `TEST_DB_PASS` (aus `tools/dev-env/.env`, die Compose-Datei reicht sie in den Container). **Nie** gegen die Datenbank des Webspace testen und nie die Zugangsdaten anderer Datenbanken eintragen. Tests laufen mit `php tests/run.php [Filter]`, im Container über `docker compose -f tools/dev-env/docker-compose.dev.yml run --rm php php tests/run.php`. Ohne Test-Datenbank werden Tests als SKIPPED gemeldet, und das darf nie als Erfolg zählen.

## Harte Regeln

- **Keine echten Personendaten** im Repository, in Tests, in Commits oder Logs. Testdaten sind erfunden und liegen in `testdata/`. Echte Daten (Kaderliste, Mitgliederliste) werden nie committet.
- Rechteprüfung auf dem Server bei jeder Anfrage, auch auf Feldebene. Nicht erlaubte Felder werden gar nicht erst ausgeliefert.
- Datenbankzugriffe nur mit vorbereiteten Abfragen. `config.php` mit Zugangsdaten nie committen, nur `config.example.php`.
- Testanmeldung nur bei ausdrücklicher Einstellung. Der Server startet nicht, wenn sie im Echtbetrieb an ist.
- Spieler und Vereine werden nie gelöscht, nur auf passiv oder inaktiv gesetzt.
- Keine Befunde oder medizinischen Inhalte speichern, nur Datum und Status.
- Keine Aufrufe externer Dienste und keine externen Skripte von fremden Servern.

## Begriffe

- **Verband** = der BVBB insgesamt. **Bundesland** (`BER` oder `BRA`) steht im Feld `state` am **Verein** und entscheidet, welches Amt fördert. Ein Spieler hat kein eigenes Bundesland-Feld.
- **Kaderstatus:** `LK` Landeskader, `SK` Sparringkader, `NK2` Bundeskader.
- **Altersklasse:** Alter = Kalenderjahr minus Jahrgang. `U09`, `U11`, `U13`, `U15`, `U17`, `U19` gelten bis unter 9, 11, 13, 15, 17 und 19 Jahren, danach `O19`. Das Jahr kommt aus dem Turniertermin. Ohne Jahrgang gilt die AK aus der Liste (`U22` und `O22` werden zu `O19`).
- **Ampel:** Stand von NADA-Schulung und sportmedizinischer Untersuchung (`done`, `scheduled`, `open`, `unclear`; der Eintrag „/“ bedeutet „noch nichts gepflegt“ und ergibt `open`), abgeleitet aus den Textspalten SpoMed und Anti Doping (Task 4b). Trainer sehen nur die Ampel, nie den Rohtext.
- **Einfrieren:** Spielerdaten werden beim Wechsel eines Turniers auf „zur Prüfung“ in das Turnier kopiert und ändern sich danach nicht mehr (ab Etappe 2).
- Alles rechnet in **Kalenderjahren**.

## Sprache und Stil

- Bezeichner im Code englisch (`players`, `state`, `roster_status`). Oberfläche, Meldungen und Kommentare für Anwender auf Deutsch.
- In Anzeigetexten typografische Zeichen: „…“ und ’. Datum `TT.MM.JJJJ`, Beträge `1.234,56 €`.
- Beträge werden gespeichert, Summen, Erstattungen und Aufteilung immer berechnet. Aufteilung Berlin/Brandenburg pro Kopf nach dem Bundesland des Vereins; Berlin wird auf den Cent gerundet, Brandenburg erhält den Rest.

## Referenzwerte aus dem Prototyp (müssen in Tests auftauchen)

Beispielabrechnung im Prototyp (9 Teilnehmer, davon 8 Berlin und 1 Brandenburg): Ausgaben 1.458,61 €, Einnahmen 843,50 €, Zuschuss 615,11 €. Berlin: Ausgaben 1.296,54 €, Einnahmen 749,78 €, Zuschuss 546,76 €. Brandenburg: 162,07 €, 93,72 €, 68,35 €. Erstattung an eine Person, die privat verauslagt hat: 5,20 €; Rest über die Kreditkarte des Verbands.

AK-Beispiele: Jahrgang 2008 ist 2026 `U19` und 2027 `O19`. Jahrgang 2015 ist 2026 und 2027 `U13` und 2028 `U15`.

## Was im Prototyp nicht übernommen wird

- Speichern im Browser (`localStorage`) und als JSON-Datei. Alles läuft künftig über die Datenbank.
- Erfundene Vereinskürzel. Unbekannte Vereine bekommen kein Kürzel, nur den Befund `unknown_club`.
- Spalte „LK bis U22“ gibt es nicht mehr.

## Offene und vertagte Punkte

1. **Task 9b zurückstellen:** Die Spalten der Mitgliederliste der Vereine liegen noch nicht vor und kommen später. Bis dahin Task 9b auslassen. Die Vereinszuordnung läuft in der Zwischenzeit über eine einfache Vereinsliste (Task 4, Task 9).
2. **Zählregel der Behördenstatistik** (Jahresübersicht, spätere Etappe) kommt später mit der Auswertung.
3. **Bewusst vertagt:** die Felder je Rolle (bis dahin gilt die Matrix aus Task 2), die Form des Prüfablaufs und die echte Anmeldung (Tendenz Digitale Geschäftsstelle).
4. **Beim Projektinhaber:** Punkt 9 in Abschnitt 12 des Entwurfs (Entwicklung in Claude Code) ist noch nicht ausdrücklich bestätigt, und ein Änderungsvorschlag in Frage 4 wartet auf Annahme.

## Inhalt dieses Ordners

- `docs/`: Entwurf und Umsetzungsplan (aus den Dokumenten exportiert, siehe `docs/README.md`).
- `prototype/`: Prototyp mit erfundenen Beispieldaten.
- `testdata/Testkaderliste_fiktiv.xlsx`: erfundene Kaderliste im Format der echten Liste, mit absichtlichen Fehlern. Das Blatt „Hinweise“ nennt die erwarteten Befunde der Datenprüfung.
- `tools/dev-env/`: Docker-Dateien, Anleitung und Umgebungstest für die Entwicklung (Portainer-Stack für die NAS-Datenbank, Compose-Datei, Umgebungstest).
- `tools/hosting-check/`: `phpcheck.php` und `rewrite-test/`, einmalig auf dem Webspace benutzt. Nicht ausliefern.
