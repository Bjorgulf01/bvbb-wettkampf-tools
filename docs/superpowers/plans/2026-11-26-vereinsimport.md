# Task 9b: Vereinsimport aus nuLiga – Umsetzungsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Die Vereine kommen per Upload des nuLiga-Exports „Vereine (Stammdaten und Adressen)“ (CSV) in die App, mit DBV-Nummer als Mitgliedsnummer, Aliasnamen für das Matching, Archiv-Vereinen als inaktiv und einer Vorschau vor dem Übernehmen.

**Architecture:** Der Browser liest die CSV-Datei (Windows-1252 oder UTF-8), prüft die Kopfzeile und schickt nur sieben benötigte Spalten je Zeile an den Server. Bank- und Adressspalten verlassen den Browser nicht. Der Server plant den Import in einer reinen Klasse `ClubImport` (ohne Datenbank) und wendet ihn in einer Transaktion an. Danach leitet er `club_id` aller Spieler neu ab, wie schon bei `PUT /api/clubs`. Ersetzt den Abschnitt „Task 9b“ im Plan von Etappe 1.

**Tech Stack:** Bestehend: PHP 8.4 ohne Framework, eigener Test-Runner (`php tests/run.php`), MariaDB 10.11, statisches JavaScript ohne Build, Rauchtest mit Playwright in Docker (`tools/ui-smoke/`). Kein SheetJS für diese Datei (siehe Task 3).

**Spec:** Im Projekt `beispieldaten/README.md`, Abschnitt `nuliga/vereine-stammdaten-2026-11-26.csv` mit „Besonderheiten für den Import“ und „Geklärt am 26.11.2026“. Dazu der Abschnitt „Festlegungen“ unten. Vor dem Start beide als `docs/superpowers/specs/2026-11-26-vereinsimport-design.md` (README-Abschnitt plus „Festlegungen“) und `docs/superpowers/plans/2026-11-26-vereinsimport.md` ins Repository legen. Die echte Exportdatei kommt **nicht** ins Repository.

## Festlegungen

Von Torsten am 26.11.2026 entschieden:
- **Mitgliedsnummer** (`clubs.member_no`) ist die `DBVNr`, Form `08-NNNN`. Die `VereinNr` steckt immer darin.
- **Kürzel** (`clubs.short`) setzt oder ändert der Import nie. Es wird in der App gepflegt. Kurzname und Druckname aus nuLiga sind nur Ergänzung.
- **Archiv-Vereine** werden als inaktiv importiert (`active = false`).
- **Bundesland** (`clubs.state`) setzt der Import nie (unverändert aus Etappe 1).

Von Claude vorgeschlagen, mit dem Plan am 26.11.2026 von Torsten freigegeben:
- Fehlt die `DBVNr`, wird sie aus der `VereinNr` gebildet: `08-` plus die Nummer, links mit Nullen auf vier Stellen aufgefüllt (`501` ergibt `08-0501`). Die Vorschau meldet das. Weicht eine vorhandene `DBVNr` von dieser Regel ab, wird die Zeile übersprungen.
- Zeilen mit „Verein ist Verband, Bezirk oder Kreis bzw. Region“ = `1` (im Export nur „SV Spielberechtigung e.V.“) werden übersprungen. Der „Test Verein CP“ kommt als Archiv-Verein inaktiv mit. Eine Sonderregel nur für ihn gibt es nicht. Das ist eine Änderung gegenüber dem Vorschlag im README.
- `VereinKurzName` und `VereinDruckname` werden als Aliasnamen ergänzt, wenn sie sich vom Namen unterscheiden und keinem anderen Verein als Name oder Alias gehören. Vorhandene Aliasnamen bleiben.
- Eine Namensänderung bei bekannter Mitgliedsnummer erscheint in der Vorschau als Warnung mit altem und neuem Namen (Fall „Nummer wiederverwendet“).
- Vorhandene Vereine ohne Mitgliedsnummer, die von Hand angelegt wurden, werden über den Namen verknüpft, wenn genau ein solcher Verein denselben Namen oder Alias trägt. Sie bekommen dann die Mitgliedsnummer und behalten Kürzel, Bundesland und Aliasnamen.

## Global Constraints

- Alle Regeln aus `umsetzungsplan-etappe-1.md` (Global Constraints) und die Entscheidungen im `STATUS.md` gelten weiter, insbesondere: Vereine werden nie gelöscht, `state` wird nie automatisch gesetzt, Rechteprüfung auf dem Server, Protokoll nur mit Feldnamen.
- Nur die sieben Spalten aus Task 3 gehen an den Server. Bank-, Adress- und Kontaktspalten werden im Browser nicht weiterverarbeitet.
- Testdaten sind erfunden: erfundene Vereinsnamen und Nummern, im Aufbau wie der echte Export (Windows-1252, `;`, CRLF, alle 48 Spalten in Originalreihenfolge, Bank- und Adressspalten mit erfundenen Werten gefüllt, damit sich prüfen lässt, dass sie nicht gesendet werden).
- `VereinNr` und `DBVNr` sind Text (führende Nullen bleiben: `01`, `0815`).
- Normalisierung von Namen und Aliasnamen immer über `ClubDirectory::normalize` (Groß- und Kleinschreibung einschließlich ß/SS, Leerraum).
- Firefox am PC und Safari auf dem iPad müssen funktionieren. Testläufe auf einem Rechner nie parallel starten.

## Review Focus

1. **Umlaute aus Windows-1252:** Eine Datei mit „Köpenicker“, „Grünau“, „Weißensee“ in Windows-1252 kommt unverändert an, nicht als „K?penicker“. Eine UTF-8-Datei mit BOM wird ebenso richtig gelesen. Test in Task 3.
2. **Führende Nullen:** `VereinNr` `07` ergibt `08-0007`, nicht `08-0700` oder `08-7`. Test in Task 1.
3. **Zweiter Import derselben Datei:** Er ändert nichts (`create` 0, `update` 0), legt keine doppelten Aliasnamen an und schreibt keinen Protokolleintrag. Test in Task 2.
4. **Von Hand gepflegte Daten:** Ein Verein mit von Hand gesetztem Kürzel, Bundesland und Alias behält alle drei nach dem Import, auch wenn nuLiga einen anderen Kurznamen liefert. Test in Task 2.
5. **Kopfzeile geändert:** Fehlt eine benötigte Spalte (zum Beispiel weil nuLiga sie umbenennt), bricht der Import vor dem Senden ab und nennt die Spalte. Zusätzliche Spalten stören nicht. Test in Task 3.

---

### Task 1: `ClubImport` – Planung ohne Datenbank

**Files:**
- Create: `src/Domain/ClubImport.php`, `tests/ClubImportTest.php`

**Interfaces:**
- Consumes: `ClubDirectory::normalize(string $s): string`.
- Produces: `App\Domain\ClubImport::memberNo(string $vereinNr, ?string $dbvNr): array{member_no: ?string, derived: bool, error: ?string}`
  - `vereinNr` getrimmt, muss aus 1 bis 4 Ziffern bestehen, sonst `error` = `bad_verein_nr`.
  - `dbvNr` leer oder `null`: `member_no` = `'08-' . str_pad($vereinNr, 4, '0', STR_PAD_LEFT)`, `derived` = `true`.
  - `dbvNr` gesetzt und gleich diesem Wert: `derived` = `false`. Sonst `error` = `member_no_mismatch`.
- Produces: `App\Domain\ClubImport::plan(list<array> $rows, list<array> $existing): array` mit
  - Eingabe `rows`: je Zeile `{verein_nr: string, dbv_nr: ?string, name: string, short_name: ?string, print_name: ?string, status: string, is_federation: bool}`.
  - Eingabe `existing`: je Verein `{id: int, member_no: ?string, name: string, short: ?string, state: ?string, active: bool, aliases: list<string>}`.
  - Ausgabe `{create: list<{row:int, member_no, name, active, aliases:list<string>}>, update: list<{row:int, id:int, data:{name?, active?, member_no?}, add_aliases:list<string>}>, unchanged: int, skipped: list<{row:int, reason:string, fields?:list<string>}>, warnings: list<{row:int, code:string, detail:string}>}`.
  - `row` ist die Position in `rows` ab 1.
- Regeln von oben nach unten, je Zeile:
  1. `is_federation` ergibt `skipped` `federation`.
  2. Leerer `name` ergibt `skipped` `missing_name`. `memberNo` mit `error` ergibt `skipped` mit diesem Grund.
  3. Eine Mitgliedsnummer, die schon in einer früheren gültigen Zeile vorkam, ergibt `skipped` `duplicate_in_file`.
  4. `status` ist `aktiv` (ergibt `active = true`) oder `Archiv` (ergibt `false`), ohne Beachtung der Großschreibung. Alles andere ergibt `skipped` `bad_status`.
  5. Abgleich: zuerst über `member_no` mit `existing`. Ohne Treffer über den normalisierten Namen mit genau einem vorhandenen Verein **ohne** `member_no` (Name oder Alias). Dann gibt es eine Warnung `linked_by_name`, und `data.member_no` wird gesetzt. Mehrere solche Treffer ergeben `skipped` `ambiguous_name`. Ohne Treffer wird angelegt.
  6. Ein neuer Verein, dessen normalisierter Name schon einem anderen vorhandenen oder neu angelegten Verein gehört, ergibt `skipped` `duplicate_name`. Dasselbe gilt, wenn ein Update dazu führen würde.
  7. Bei einem Update: `data` enthält nur Felder, die sich ändern. Eine Namensänderung ergibt die Warnung `renamed` mit `detail` „alt → neu“. `short` und `state` kommen nie in `data` vor.
  8. Aliasnamen: Kandidaten sind `short_name` und `print_name`, getrimmt und nicht leer. Ein Kandidat entfällt, wenn sein normalisierter Wert dem eigenen Namen oder einem eigenen vorhandenen Alias entspricht. Gehört er einem anderen Verein als Name oder Alias, entfällt er mit der Warnung `alias_conflict`. Doppelte Kandidaten zählen einmal.
  9. Eine abgeleitete Mitgliedsnummer ergibt die Warnung `member_no_derived`.
  10. Ein Update ohne `data` und ohne `add_aliases` zählt zu `unchanged`.
  - Vereine aus `existing`, die nicht in `rows` stehen, kommen in der Ausgabe nicht vor und bleiben unverändert.

- [ ] **Step 1: Write the failing tests** `tests/ClubImportTest.php` (erfundene Vereine):
  - `memberNo('40','08-0040')` ergibt `08-0040`, `derived` false. `memberNo('07','')` ergibt `08-0007`, `derived` true (Review Focus 2). `memberNo('0815',null)` ergibt `08-0815`. `memberNo('40','08-0041')` ergibt `error` `member_no_mismatch`. `memberNo('4a',null)` und `memberNo('12345',null)` ergeben `bad_verein_nr`.
  - Neue Zeile „TSV Musterstadt“ mit `aktiv` steht in `create` mit `active` true. Mit `Archiv` steht sie mit `active` false darin.
  - Vorhandener Verein `08-0040` „SV Altname“ mit `short` `SVA`, `state` `BER`, Alias `Altname`. Zeile `40` mit Name „SV Neuname“: `update.data` = `{name: 'SV Neuname'}`, Warnung `renamed` mit `detail` `SV Altname → SV Neuname`. In `data` gibt es keinen Schlüssel `short` oder `state`.
  - Dieselbe Zeile mit unverändertem Namen und ohne neue Aliasnamen erhöht `unchanged` um 1, und es gibt weder Update noch Warnung.
  - Zeile mit `is_federation` true steht in `skipped` als `federation`. Zeile ohne Namen steht dort als `missing_name`. Zwei Zeilen mit `VereinNr` `40` und `040` ergeben für die zweite `duplicate_in_file`.
  - Vorhandener Verein ohne `member_no` „BC Beispiel“ und Zeile `55` „bc beispiel“: Update mit `data.member_no` `08-0055` und Warnung `linked_by_name`. Zwei solche vorhandenen Vereine mit demselben Namen ergeben `ambiguous_name`.
  - Neue Zeile, deren Name einem vorhandenen Verein mit anderer `member_no` gehört, ergibt `duplicate_name`.
  - Aliasnamen: `short_name` „SV Neuname (Berlin)“ und `print_name` „Neun“ werden ergänzt. `short_name` gleich dem Namen (anders geschrieben, etwa „sv neuname“) entfällt ohne Warnung. `print_name` „SVA“, das ein anderer Verein als Alias hat, entfällt mit `alias_conflict`.
  - Abgeleitete Nummer ergibt die Warnung `member_no_derived`. `status` „aufgelöst“ ergibt `bad_status`.
- [ ] **Step 2: Run** `php tests/run.php ClubImport`. Erwartet: FAIL, Klasse nicht gefunden.
- [ ] **Step 3: Implement** `ClubImport::memberNo` und `ClubImport::plan` nach den Regeln oben.
- [ ] **Step 4: Run** `php tests/run.php ClubImport`. Erwartet: PASS.
- [ ] **Step 5: Commit** `git commit -m "feat: club import planning from nuLiga export"`

### Task 2: Schnittstelle `POST /api/clubs/import`

**Files:**
- Modify: `src/Repo/ClubRepo.php`, `src/Api/ClubsController.php`, Registrierung der Route (wo die übrigen Club-Routen registriert sind)
- Create: `tests/ClubImportApiTest.php`
- Modify, nur falls nötig: neue Migration `migrations/NNN_club_member_no_length.sql`, falls `clubs.member_no` weniger als 7 Zeichen fasst (vorher im Schema prüfen)

**Interfaces:**
- Consumes: `ClubImport::plan` (Task 1). Vorhandenes `ClubRepo::create`, `update`, `setAliases`. Die vorhandene Neuableitung von `club_id` aller Spieler nach `PUT /api/clubs`. `AuditRepo::log`. `Permissions`.
- Produces: Endpunkt `POST /api/clubs/import` mit Body `{rows: list, dry_run: bool}`.
  - Rechte: Schreibrecht `clubs` (admin, roster_editor), sonst 403.
  - `dry_run` ist Pflicht und muss boolesch sein, sonst 422 `invalid_body`. Mehr als 3000 Zeilen ergeben 413.
  - Jede Zeile muss ein Objekt mit genau den Schlüsseln aus Task 1 sein. Ein unbekannter Schlüssel in irgendeiner Zeile ergibt 403 `{error:"forbidden", fields:[…]}`, wie beim Kaderimport. Damit kommen Bank- oder Adressfelder nie durch. Falsche Typen ergeben 422 mit `fields` (Pfad wie `rows.3.name`).
  - Texte werden wie in `PlayerInput` getrimmt (auch geschützte Leerzeichen und BOM). Steuerzeichen ergeben 422. Längen wie im Schema.
  - Antwort `{create:int, update:int, unchanged:int, skipped:[…], warnings:[…]}`, bei `dry_run` und beim Übernehmen gleich.
  - Übernehmen in einer Transaktion: anlegen, ändern, Aliasnamen ergänzen (nie entfernen), `imported_at` bei allen angelegten und geänderten Vereinen setzen. Danach `club_id` aller Spieler neu ableiten.
  - Protokoll je Verein `club_import_create` beziehungsweise `club_import_update` mit Feldnamen (`fields: name, active, aliases`), ohne Werte. Ohne Änderung kein Eintrag.

- [ ] **Step 1: Write the failing tests** `tests/ClubImportApiTest.php` gegen `bvbb_test_pc`:
  - `roster_editor` importiert drei Zeilen (neu aktiv, neu Archiv, Verband) mit `dry_run` true: Antwort `create` 2, `skipped` mit `federation`. `GET /api/clubs` ist unverändert.
  - Dieselbe Anfrage mit `dry_run` false: `GET /api/clubs` enthält beide Vereine, `member_no` gesetzt, der Archiv-Verein mit `active` false, `short` und `state` leer.
  - Review Focus 3: Ein zweiter Lauf mit denselben Zeilen ergibt `create` 0, `update` 0, `unchanged` 2. Die Zahl der Aliasnamen bleibt gleich, und das Protokoll hat keinen neuen Eintrag.
  - Review Focus 4: Vorhandener Verein mit `short` `SVA`, `state` `BRA` und Alias `Sankt A`. Import mit neuem Kurznamen und Drucknamen: `short` bleibt `SVA`, `state` bleibt `BRA`, `Sankt A` bleibt, die neuen Aliasnamen kommen dazu.
  - Ein Spieler mit `club_name` gleich dem neuen Druck-Alias eines Vereins hat nach dem Übernehmen dessen `club_id` (Neuableitung). Ein Spieler bei einem Verein, der durch den Import inaktiv wird, verliert die Zuordnung (`club_id` leer, Befund `unknown_club`), wie in Task 8 festgelegt.
  - Eine Zeile mit zusätzlichem Schlüssel `iban` ergibt 403 `fields:["iban"]`, und nichts wird gespeichert. `coach` ruft den Endpunkt auf: 403. Ohne `dry_run`: 422. 3001 Zeilen: 413.
  - Der Protokolleintrag zu einem geänderten Verein nennt `name` und `aliases`, aber keinen Vereinsnamen.
- [ ] **Step 2: Run** `php tests/run.php ClubImportApi`. Erwartet: FAIL.
- [ ] **Step 3: Implement** Endpunkt und gegebenenfalls `ClubRepo::addAliases(int $clubId, list<string> $aliases): void` (ergänzt nur und überspringt vorhandene). Die vorhandene Neuableitung von `club_id` wiederverwenden, nicht kopieren.
- [ ] **Step 4: Run** den gesamten Lauf `php tests/run.php`. Erwartet: alle Tests grün.
- [ ] **Step 5: Commit** `git commit -m "feat: club import endpoint with preview and audit"`

### Task 3: Oberfläche – Upload, Vorschau, Übernehmen

**Files:**
- Create: `public/js/csv.js`, `tests/fixtures/nuliga/vereine-test.csv`
- Modify: `public/js/clubs.js`, `public/css/app.css`, Rauchtest in `tools/ui-smoke/`, `tests/ui-checklist.md`

**Interfaces:**
- Consumes: `POST /api/clubs/import` (Task 2), `GET /api/clubs`, `GET /api/me` (`permissions.clubs`).
- Produces (`csv.js`, ES-Modul ohne DOM-Zugriffe):
  - `decodeText(buffer: ArrayBuffer): string`. Zuerst `TextDecoder('utf-8', {fatal: true})`, bei Fehler `TextDecoder('windows-1252')`. Ein BOM am Anfang wird entfernt.
  - `parseCsv(text: string, delimiter = ';'): string[][]`. Felder in Anführungszeichen (auch mit `;`, Zeilenumbruch und `""` darin), CRLF und LF, völlig leere Zeilen werden übergangen. Alle Werte bleiben Text.
  - `NULIGA_CLUB_COLUMNS = {verein_nr: 'VereinNr', dbv_nr: 'DBVNr', name: 'VereinName', short_name: 'VereinKurzName', print_name: 'VereinDruckname', status: 'Status', is_federation: 'Verein ist Verband, Bezirk oder Kreis bzw. Region'}`.
  - `mapClubRows(table: string[][]): {rows: object[], lines: number[], missing: string[]}`. Spalten werden über den Namen in der Kopfzeile gefunden, nicht über die Position. `missing` nennt fehlende Spalten. `lines` ist die Zeilennummer in der Datei je Zeile (für Meldungen). Leere Werte werden zu `null` (außer `name`, `verein_nr` und `status`, die als `''` bleiben). `is_federation` ist `true` genau bei `'1'`. Andere Spalten werden nicht übernommen.

- [ ] **Step 1: Write the fixture and failing smoke checks**
  - Fixture `tests/fixtures/nuliga/vereine-test.csv`: Windows-1252, `;`, CRLF, Kopfzeile mit allen 48 Spalten des Originals in Originalreihenfolge (aus `beispieldaten/README.md` beziehungsweise der Spaltenliste im Export). Etwa 15 erfundene Vereine. Abgedeckt: Umlaute und ß („Köpenicker Testclub“, „SG Grünau Test“, „BSV Weißensee Test“), führende Null (`07`), Archiv mit Austrittsdatum, fehlende `DBVNr`, eine abweichende `DBVNr`, ein Verband (`1`), ein Name mit `;` in Anführungszeichen, Bemerkungen mit Zeilenumbruch in Anführungszeichen. Gefüllte erfundene Bankspalten (`DE00…`) und eine erfundene E-Mail-Adresse.
  - Rauchtest-Prüfungen:
    - `decodeText` einer Windows-1252-Datei liefert „Köpenicker“. Eine UTF-8-Datei mit BOM liefert dasselbe (Review Focus 1).
    - `parseCsv` behandelt `;` und Zeilenumbruch in Anführungszeichen richtig.
    - `mapClubRows` mit fehlender Spalte `DBVNr` meldet `missing: ['DBVNr']`. Der Import zeigt „Spalte DBVNr fehlt“ und sendet nichts (Review Focus 5). Eine zusätzliche Spalte am Ende stört nicht.
    - Als `kaderpflege`: Datei wählen, die Vorschau zeigt die Zahlen „neu“, „geändert“, „unverändert“ und „übersprungen“, die Liste der übersprungenen Zeilen mit Excel-Zeilennummer und Grund sowie die Warnungen (umbenannt mit alt → neu, Nummer gebildet, Alias-Konflikt, über den Namen verknüpft).
    - Im Netzwerk-Tab enthält die Anfrage an `/api/clubs/import` weder `DE00` noch `@`.
    - „Übernehmen“ aktualisiert die Vereinsliste. Danach erscheint der Hinweis „Bei {n} aktiven Vereinen fehlt das Bundesland“ mit einem Filter, der genau diese Vereine zeigt.
    - Als `trainer`: Es gibt keine Schaltfläche für den Import.
- [ ] **Step 2: Run** den Rauchtest. Erwartet: FAIL, Modul nicht gefunden.
- [ ] **Step 3: Implement**
  - `csv.js` nach den Signaturen.
  - In `clubs.js` die Schaltfläche „Mitgliederliste aus nuLiga importieren“, nur mit Schreibrecht `clubs`, `<input type="file" accept=".csv,text/csv">`. Ablauf: Datei lesen, `decodeText`, `parseCsv`, `mapClubRows`, bei `missing` abbrechen. Sonst Vorschau per `dry_run`, dann „Übernehmen“ und „Abbrechen“.
  - Meldungen aus `row` über `lines` in Dateizeilen umrechnen. Texte für die Gründe und Warnungen auf Deutsch, zum Beispiel `federation` als „Verband, nicht importiert“ oder `member_no_derived` als „DBV-Nummer aus Vereinsnummer gebildet“.
  - Die Vereinsliste zeigt die Spalte „Mitgliedsnummer“ (`member_no`), falls noch nicht vorhanden. Dazu ein Filter „ohne Bundesland“.
  - Inhalte nur über Textknoten (`dom.js`).
- [ ] **Step 4: Run** den Rauchtest in Chromium, Firefox und WebKit. Erwartet: alle Prüfungen grün. `tests/ui-checklist.md` um Import, Vorschau, Abbruch bei fehlender Spalte und Bundesland-Filter ergänzen.
- [ ] **Step 5: Commit** `git commit -m "feat: nuLiga club import in clubs view"`

### Task 4: Auslieferung und Echtdaten

**Files:**
- Modify: `src/Version.php`, `tests/HealthTest.php`, `README.md`, `STATUS.md`

- [ ] **Step 1:** Version um eins erhöhen: `1.2.0`, wenn die Kaderlisten-Anpassungen als `1.1.0` ausgeliefert sind, sonst die nächste freie Nummer. `HealthTest` anpassen.
- [ ] **Step 2: Run** `php tests/run.php` und den Rauchtest. Erwartet: alles grün.
- [ ] **Step 3:** `STATUS.md`: Task 9b auf fertig setzen, die Festlegungen dieses Plans unter „Entscheidungen und Abweichungen“ eintragen (Abweichung vom Plan Etappe 1: CSV statt Excel, `short` wird nicht übernommen, Archiv als inaktiv). `README.md`: kurzer Abschnitt „Vereine aus nuLiga importieren“ (welcher Export, dass Windows-1252 erkannt wird, dass Bankdaten nicht übertragen werden).
- [ ] **Step 4: Verifikation auf dem Test-Webspace (Projektinhaber):** gegebenenfalls Migration per phpMyAdmin einspielen, dann Dateien hochladen. `/api/health` muss die neue Version liefern. Dann den echten nuLiga-Export hochladen:
  - **Vorschau:** 106 Vereine neu oder verknüpft (107 Zeilen minus ein Verband), „SV Spielberechtigung“ übersprungen, 9 Warnungen „Nummer gebildet“ (8 Archiv-Vereine und der Testverein), keine Zeile mit `member_no_mismatch`. Vereine, die beim Testen von Hand angelegt wurden, erscheinen als „über den Namen verknüpft“ oder, bei abweichender Schreibweise, als neu. Doppelte hier vor dem Übernehmen von Hand bereinigen.
  - **Übernehmen:** Danach das Bundesland bei allen aktiven Vereinen pflegen (Filter „ohne Bundesland“) und die Datenprüfung der Kaderliste aufrufen. Die Zahl `unknown_club` sollte sinken.
- [ ] **Step 5: Commit** `git commit -m "chore: release with nuLiga club import"`
