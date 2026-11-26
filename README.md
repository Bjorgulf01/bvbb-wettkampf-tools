# BVBB Wettkampf-Tools

Webanwendung des Badminton-Verbands Berlin-Brandenburg für die zentrale Kaderliste (Etappe 1), später Turnierabrechnung. PHP 8.4 ohne Framework, MariaDB, Oberfläche als statisches HTML und JavaScript. Entwurf und Plan: `docs/`. Stand der Arbeit: `docs/STATUS.md`.

## Aufbau auf dem Webspace

Der Webspace hat kein SSH. Hochgeladen wird per SFTP, die Datenbank wird per phpMyAdmin eingerichtet.

**Etappe 1 läuft nur auf dem Test-Webspace (`env` = `staging`) mit der Testanmeldung.** Die echte Anmeldung ist vertagt. Mit `production` gibt es deshalb noch keine Möglichkeit, sich anzumelden.

```
<Startordner des Webspace>/
├── webseiten/          öffentliches Verzeichnis: Inhalt von public/ aus dem Repository
│   ├── .htaccess       (versteckte Datei: Weiterleitung /api/, Sicherheits-Kopfzeilen, Sperren)
│   ├── .user.ini       (versteckte Datei: keine Fehlertexte im Browser)
│   ├── index.html
│   ├── index.php
│   ├── css/  js/  vendor/
└── bvbb-app/           Programmordner, NICHT öffentlich
    ├── .htaccess       (versteckte Datei, aus dem Hauptordner des Repositorys: sperrt alles)
    ├── config.php      (aus config.example.php, mit Zugangsdaten, nie ins Repository)
    ├── sessions/       (leerer Ordner, beschreibbar)
    ├── bin/
    ├── migrations/
    └── src/
```

- `webseiten/index.php` findet `bvbb-app/` neben dem öffentlichen Verzeichnis von selbst.
- **Versteckte Dateien:** SFTP-Programme blenden Dateien mit Punkt am Anfang oft aus. Die Anzeige versteckter Dateien einschalten und prüfen, dass `webseiten/.htaccess`, `webseiten/.user.ini` und `bvbb-app/.htaccess` auf dem Server liegen. Ohne `webseiten/.htaccess` gibt es keine Schnittstelle, keine Sicherheits-Kopfzeilen und keine Sperre versteckter Dateien. Änderungen an `.user.ini` wirken erst nach bis zu 5 Minuten.
- **Annahme:** `webseiten/` ist das Wurzelverzeichnis der Domain (Adressen wie `https://…/api/health`). In einem Unterordner der Domain funktioniert die Schnittstelle nicht.
- Hochgeladen wird nur das Paket aus `php tools/package.php` (Abschnitt „Paket bauen“). Es enthält nie `config.php`, `sessions/`, `.htpasswd`, `tests/`, `tools/`, `docs/` oder `.git/`.

## Paket bauen

```
docker compose -f tools/dev-env/docker-compose.dev.yml run --rm php php tools/package.php
```

- Läuft nur auf `main` ohne offene Änderungen, sonst Abbruch mit Meldung. So passt das Paket immer zu einem Commit.
- Ergebnis: `dist/bvbb-wettkampf-<Version>/` und daneben `dist/bvbb-wettkampf-<Version>.zip` mit `webseiten/`, `bvbb-app/` und `HOCHLADEN.txt` (Version, Commit, Datum, Migrationen, Dateiliste). Die Version steht in `src/Version.php`. Ein älteres Paket derselben Version in `dist/` wird vorher gelöscht.
- Die beiden Ordner kommen auf die gleichnamigen Ordner im Webspace. `bvbb-app/config.php` und `bvbb-app/sessions/` bleiben auf dem Server und werden nie überschrieben, weil sie nie im Paket sind. `bvbb-app/config.example.php` ist nur eine Vorlage ohne Zugangsdaten.
- Liegt `config.php`, `.htpasswd` oder ein Ordner `sessions` in einer Quelle (zum Beispiel `public/`), bricht der Bau ab. Ebenso bei jeder von Git ignorierten Datei in einer Quelle (zum Beispiel `.env` oder eine echte Mitgliederliste), weil `git status` sie nicht anzeigt.
- **Hochladen löscht keine alten Dateien.** Die Dateiliste in `HOCHLADEN.txt` zeigt, was dazugehört. Dateien auf dem Server, die dort nicht stehen, werden nicht mehr gebraucht.

## Erstes Einrichten

1. **Datenbank anlegen** im Kundenmenü des Hosters (MariaDB 10.11, Zeichensatz utf8mb4). Name, Benutzer, Passwort und Host notieren.
2. **Migrationen einspielen**: in phpMyAdmin die Datenbank wählen, „Importieren“, nacheinander `migrations/001_core.sql`, `migrations/002_seed_roles.sql` und `migrations/003_birth_year_from_date.sql`, in der Reihenfolge der Nummern. Jede Datei trägt sich am Ende selbst in die Tabelle `schema_migrations` ein. Danach müssen dort alle drei Dateinamen stehen. Ein versehentlicher zweiter Import derselben Datei bricht mit einem Fehler (doppelter Eintrag) ab und ändert nichts.
   - phpMyAdmin führt jede Anweisung einzeln aus, nicht als Ganzes. Bricht der Import von `002_seed_roles.sql` mittendrin ab (zum Beispiel Verbindung weg), im Reiter „SQL“ `DELETE FROM role_permissions; DELETE FROM roles;` ausführen (nur beim ersten Einrichten, solange es noch keine Benutzer gibt) und die Datei neu importieren. Bricht `001_core.sql` ab, die Datei einfach noch einmal importieren.
3. **Konfiguration**: `config.example.php` lokal nach `config.php` kopieren und ausfüllen:
   - `env`: `staging` auf dem Test-Webspace, `production` im Echtbetrieb.
   - `db`: Zugangsdaten aus Schritt 1.
   - `test_auth`: `true` nur auf dem Test-Webspace. Mit `production` und `true` startet der Server nicht (Antwort 500).
   - `first_admin_subject`: Kennung der Person, die bei ihrer **ersten** Anmeldung die Rolle admin bekommt. Auf dem Test-Webspace zum Beispiel `test:kaderpflege` (Testpersonen: `test:` plus Schlüssel). Hat sich die Person schon einmal angemeldet, wirkt der Eintrag nicht mehr; dann die Rolle in der Verwaltung vergeben.
   - `session_max_age`: Dauer einer Sitzung in Sekunden (Standard 8 Stunden).
   - `session_save_path`: eigener Ordner für Sitzungsdateien (Vorgabe `bvbb-app/sessions`).
4. **Hochladen** per SFTP: Paket bauen (Abschnitt „Paket bauen“), `webseiten/` und `bvbb-app/` aus dem Paket hochladen, `config.php` nach `bvbb-app/` legen. Den Ordner `bvbb-app/sessions/` anlegen.
5. **Prüfen** (Abschnitt „Prüfen nach dem Hochladen“).

## Aktualisieren

1. Neue Migrationsdateien (höhere Nummer als in `schema_migrations`) per phpMyAdmin importieren, in der Reihenfolge der Nummern. Zuerst, weil Migrationen das Schema nur erweitern und der alte Code damit weiterläuft.
2. Paket bauen und `webseiten/` und `bvbb-app/` aus dem Paket auf die gleichnamigen Ordner hochladen. `bvbb-app/src/` dabei vollständig ersetzen (alten Ordner vorher löschen), damit keine alten Dateien liegen bleiben. `config.php` und `sessions/` bleiben. **Nicht den ganzen Ordner `bvbb-app/` ersetzen** (sonst fehlen auch `config.php`, dann Status 500, und `sessions/`): Fehlt danach `bvbb-app/sessions/`, meldet `/api/health` `{"ok":false}`. Dann den leeren Ordner neu anlegen (Rechte 755, notfalls 775).
3. `GET /api/health` aufrufen.

**Von 1.0.0 auf 1.1.0:** neu ist `migrations/003_birth_year_from_date.sql`. Sie ändert kein Schema, sondern setzt bei jedem Spieler mit Geburtstag den Jahrgang auf das Jahr des Geburtstags (ohne Eintrag im Änderungsprotokoll). Danach steht `003_birth_year_from_date.sql` in `schema_migrations`. Sie läuft auch mit dem alten Code und kann deshalb wie üblich zuerst eingespielt werden. Zwischen dem Import der Migration und dem Hochladen niemanden Spieler bearbeiten lassen: 1.0.0 könnte sonst noch einen abweichenden Jahrgang speichern. Ist das doch passiert, das `UPDATE` aus der Datei nach dem Hochladen im Reiter „SQL“ noch einmal ausführen (ohne die letzte Zeile mit `schema_migrations`); es ändert nur abweichende Zeilen. `/api/health` meldet danach `"version":"1.1.0"`.

**Von 1.1.0 auf 1.2.0:** keine neue Migration. Neu ist der Vereinsimport aus nuLiga (Abschnitt „Vereine aus nuLiga importieren“). `/api/health` meldet danach `"version":"1.2.0"`.

Gibt es später SSH, ersetzt `php bvbb-app/bin/migrate.php` Schritt 1.

## Vereine aus nuLiga importieren

Im Reiter „Vereine“ der Kaderliste (Rolle `kaderpflege` oder `admin`) über „Vereine aus nuLiga importieren“.

- **Welcher Export:** In nuLiga der Export „Vereine (Stammdaten und Adressen)“ als CSV. Die Datei unverändert hochladen, nicht vorher in Excel öffnen und speichern. Die Spalten werden über ihren Namen in der Kopfzeile gefunden. Fehlt eine benötigte Spalte, bricht der Import ab und nennt sie.
- **Zeichensatz:** nuLiga exportiert Windows-1252. Das wird erkannt, ebenso UTF-8 (mit oder ohne BOM). Umlaute und ß kommen richtig an.
- **Was übertragen wird:** nur sieben Spalten (Vereinsnummer, DBV-Nummer, Name, Kurzname, Druckname, Status, „Verein ist Verband …“). **Bank-, Adress- und Kontaktdaten verlassen den Browser nicht.**
- **Was passiert:** Die DBV-Nummer (`08-` plus vierstellige Vereinsnummer) wird Mitgliedsnummer. Fehlt sie, wird sie aus der Vereinsnummer gebildet. Archiv-Vereine kommen als inaktiv dazu, Verbände nicht. Kurz- und Druckname werden Aliasnamen. Kürzel und Bundesland ändert der Import nie. Von Hand angelegte Vereine ohne Mitgliedsnummer werden über den Namen verknüpft.
- **Ablauf:** Zuerst zeigt eine Vorschau, was neu, geändert, unverändert und übersprungen ist, mit Hinweisen (zum Beispiel „umbenannt: alt → neu“). Erst „Übernehmen“ speichert. Danach bei allen aktiven Vereinen das Bundesland pflegen (Kästchen „nur ohne Bundesland“) und die Datenprüfung der Kaderliste aufrufen.
- Die echte Exportdatei gehört nie ins Repository. Zum Ausprobieren gibt es die erfundene Datei `tests/fixtures/nuliga/vereine-test.csv`.

## Prüfen nach dem Hochladen

| Prüfung | Erwartet |
|---|---|
| `https://…/api/health` | `{"ok":true,"version":"1.2.0"}`. Bei einem Datenbankfehler oder fehlendem Sitzungsordner `{"ok":false}` mit Status 503, ohne Details |
| `http://…` (ohne s) | leitet auf `https://…` um, **bevor** nach einem Passwort gefragt wird (siehe unten) |
| `https://…/.htaccess`, `https://…/.user.ini` | 403 |
| `https://…/src/Config.php`, `https://…/migrations/001_core.sql`, `https://…/config.php`, `https://…/bvbb-app/config.php` | 403 oder 404 |
| `config.php` kurz auf `env` = `production` und `test_auth` = `true` stellen | Seite meldet „Fehler auf dem Server“, `/api/health` antwortet 500, keine Testanmeldung. Danach zurückstellen |
| Entwicklerwerkzeuge, Speicher, Cookies | Sitzungs-Cookie `__Host-bvbb_session` mit `Secure`, `HttpOnly`, `SameSite=Lax` |
| Entwicklerwerkzeuge, Netzwerk, Antwort-Kopfzeilen | `Content-Security-Policy` mit `frame-ancestors 'none'`, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Strict-Transport-Security`, kein `X-Powered-By` |
| Anmeldung als jede Testperson, Import von `testdata/Testkaderliste_fiktiv.xlsx` | wie in `tests/ui-checklist.md` |

- Fehlen alle Sicherheits-Kopfzeilen, ist `mod_headers` beim Hoster nicht aktiv. Dann beim Hoster nachfragen.
- Fehlt nur `Strict-Transport-Security`, endet HTTPS wahrscheinlich bei einem Proxy des Hosters, und Apache sieht die Anfrage als `http`. Dann beim Hoster nachfragen.
- Antworten **alle** Seiten mit 500, im Fehlerprotokoll des Hosters nach „not allowed here“ suchen. Dann erlaubt der Hoster eine Anweisung in der `.htaccess` nicht (zum Beispiel `Options` oder `Require`), und die Zeile muss angepasst werden.
- **Die Anwendung braucht HTTPS.** Das Sitzungs-Cookie trägt `Secure` und das Präfix `__Host-`. Über `http://` nimmt der Browser es nicht an, und die Anmeldung scheitert ohne Meldung.

**HTTPS erzwingen:** Am besten mit dem HTTPS-Zwang im Kundenmenü des Hosters. Sonst in `webseiten/.htaccess` die zwei auskommentierten Zeilen einschalten. Danach `http://…` aufrufen: Es muss auf `https://…` umleiten. Gibt es eine Umleitungsschleife, die Zeilen wieder auskommentieren und beim Hoster nachfragen.

## Test-Webspace schützen

Mit eingeschalteter Testanmeldung kann sich jeder, der die Adresse kennt, als admin anmelden. Die Daten sind erfunden, trotzdem den Test-Webspace mit einem Passwort schützen. **Erst HTTPS erzwingen (oben), dann das Passwort einrichten.** Sonst fragt der Webserver das Passwort auch über `http://` ab, also unverschlüsselt.

- Am einfachsten über den „Verzeichnisschutz“ im Kundenmenü des Hosters für `webseiten/`.
- Oder von Hand: eine `.htpasswd` im Programmordner anlegen (Generator des Hosters) und in `webseiten/.htaccess` den auskommentierten Block am Ende einschalten. `AuthUserFile` braucht den absoluten Pfad auf dem Server. Den nennen das Kundenmenü oder die Hilfeseiten des Hosters.

Der Health-Check liegt dann ebenfalls hinter dem Passwort.

## Lokale Entwicklung

PHP 8.4 läuft in Docker, die Test-Datenbank auf dem NAS (`tools/dev-env/README.md`). Tests: `docker compose -f tools/dev-env/docker-compose.dev.yml run --rm php php tests/run.php`. Vorschau der Oberfläche: `tools/dev-env/seed-preview.php`, dann `docker compose -f tools/dev-env/docker-compose.dev.yml up web` und http://localhost:8080. Rauchtest im Browser: `tools/ui-smoke/README.md`. Die Tests und `seed-preview.php` löschen alle Tabellen der Test-Datenbank dieses Rechners.
