# Kaderliste rund machen – Umsetzungsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Größere Schrift, keine „AK (Liste)“ mehr, Jahrgang aus dem Geburtstag, Kaderstatus NK1, „Geburtstag“ statt „JG (Datum)“ sowie Sortieren und Filtern in der Kaderliste.

**Architecture:** Der Server nimmt `list_ak` aus der Schnittstelle, berechnet die AK nur noch aus dem Jahrgang und gleicht `birth_year` beim Speichern über eine reine Klasse `BirthYear` mit `birth_date` ab. In der Oberfläche liegen Sortieren, Filtern und die Werte der Auswahllisten als reine Funktionen in einem neuen Modul `public/js/roster-view.js`. `roster.js` nutzt dieses Modul für Tabelle und Export. Alles läuft im Browser über die schon geladene Liste, es gibt keine neue Schnittstelle.

**Tech Stack:** Bestehend: PHP 8.4 ohne Framework, eigener Test-Runner (`php tests/run.php`), MariaDB 10.11, statisches JavaScript ohne Build, SheetJS 0.20.3 lokal, Rauchtest mit Playwright in Docker (`tools/ui-smoke/`).

**Spec:** `claude/design-kaderliste-anpassungen.md` im Projekt, freigegeben am 24.11.2026. Vor dem Start mit diesem Plan nach `docs/superpowers/specs/2026-11-24-kaderliste-anpassungen-design.md` und `docs/superpowers/plans/2026-11-24-kaderliste-anpassungen.md` ins Repository legen und committen.

## Global Constraints

- Alle Regeln aus `umsetzungsplan-etappe-1.md` (Global Constraints) und die Entscheidungen im `STATUS.md` gelten weiter.
- Die Rechte ändern sich nicht. `birth_date` bleibt in `player.private`, `birth_year` in `player.roster`. Trainer sehen nie den Geburtstag, auch nicht über Filter, Sortierung oder Export.
- Die Spalte `players.list_ak` bleibt in der Datenbank mit ihren Werten erhalten. Keine Migration löscht sie.
- Kaderstatus-Reihenfolge überall: `NK1`, `NK2`, `LK`, `SK`, leer am Ende.
- AK-Reihenfolge: `U09`, `U11`, `U13`, `U15`, `U17`, `U19`, `O19`, leer am Ende.
- Beschriftungen in der Oberfläche: „Geburtstag“ (statt „JG (Datum)“), Anzeige `TT.MM.JJJJ`. Hinweis am gesperrten Jahrgang: „wird aus dem Geburtstag übernommen“. Zählung: „{n} von {m} Spielern“. Schaltfläche: „Filter zurücksetzen“. Wert für fehlende Angaben in den Auswahllisten: „(leer)“.
- Die Spaltenprüfung A bis V im Import bleibt unverändert. Die Kopfzeile „JG“ im Import bleibt.
- Die Oberfläche baut Inhalte nur über Textknoten (`dom.js`), nie über HTML aus Daten. Die CSP bleibt bei `'self'`.
- Firefox am PC und Safari auf dem iPad müssen funktionieren.
- Testläufe auf einem Rechner nie parallel starten.

## Review Focus

1. **Umlaute beim Sortieren:** „Özdemir“, „Müßig“ und „Weißkopf“ stehen dort, wo man sie im Deutschen erwartet, nicht hinter Z. Test in Task 4.
2. **Trainer:** Die Filterleiste zeigt keinen Filter für Felder, die ein Trainer nicht sieht, und der Export enthält keine privaten Spalten. Test in Task 4.
3. **Spieler ohne Jahrgang:** Die AK ist leer, der Spieler erscheint unter AK „(leer)“ und Jahrgang „(leer)“ und steht beim Sortieren in beiden Richtungen am Ende. Tests in Task 1 und Task 4.
4. **Bearbeiten bei gesetztem Filter:** Die Filter bleiben stehen. Wird ein Spieler bei Filter „aktiv“ auf passiv gesetzt, verschwindet er aus der Tabelle, und die Zählung stimmt. Test in Task 4.
5. **Widerspruch in einer Anfrage:** `PUT` mit `birth_date` `2014-09-14` und `birth_year` `2015` ergibt 422 `fields: ["birth_year"]`, und nichts wird gespeichert. Test in Task 2.

---

### Task 1: Server – AK (Liste) entfernen, NK1 zulassen

**Files:**
- Modify: `src/Rbac/Permissions.php` (`FIELD_GROUPS`: `list_ak` aus `player.roster` entfernen)
- Modify: `src/Api/PlayersController.php` (AK-Berechnung ohne Listen-AK)
- Modify: `src/Domain/PlayerValidator.php` (Befunde `ak_mismatch` und `bad_list_ak` entfernen, AK ohne Listen-AK)
- Modify: `src/Domain/KaderImport.php` (`list_ak` nicht mehr übernehmen)
- Modify: `src/Domain/PlayerInput.php` (nur falls es dort eine Liste erlaubter Kaderstatus-Werte gibt: `NK1` ergänzen)
- Test: `tests/PermissionsTest.php`, `tests/PlayersApiTest.php`, `tests/PlayerValidatorTest.php`, `tests/KaderImportTest.php`, `tests/ImportApiTest.php`

**Interfaces:**
- Consumes: `AgeClass::for(?string $birthYearOrDate, string $listAk, int $year): string` bleibt unverändert. Alle Aufrufer übergeben für `$listAk` jetzt `''`.
- Produces: `Permissions::FIELD_GROUPS['player.roster']` = die bisherige Liste in bisheriger Reihenfolge, nur ohne `list_ak`. `state` bleibt darin und bleibt nur lesbar. Die Befund-Codes sind danach in dieser Reihenfolge: `missing_birth_year`, `bad_birth_year`, `missing_club`, `unknown_club`, `bad_gender`, `missing_roster_status`, `training_site_looks_like_ak`, `duplicate_name`.

- [ ] **Step 1: Write the failing tests**
  - `PermissionsTest`: `list_ak` ist für keine Rolle in `fieldsFor(…, 'read')` enthalten.
  - `PlayersApiTest`: `roster_editor` bekommt bei `GET /api/players` keinen Schlüssel `list_ak`. `PUT /api/players/{id}` mit `{"list_ak":"U11"}` ergibt 403 `{error:"forbidden", fields:["list_ak"]}`. Ein Spieler ohne `birth_year`, dessen Datenbankzeile `list_ak` = `U13` hat, bekommt `ak` = `''`. `PUT` mit `{"roster_status":"NK1"}` ergibt 200, und `GET` liefert `NK1`.
  - `PlayerValidatorTest`: Spieler Jahrgang 2015 mit `list_ak` `U11`, Jahr 2026, ergibt keinen Befund `ak_mismatch`. `list_ak` `U10` ergibt keinen Befund `bad_list_ak`. Die Testfälle für beide Codes werden entfernt.
  - `KaderImportTest` / `ImportApiTest`: Eine Zeile mit `list_ak` ergibt 403 wie jedes andere nicht schreibbare Feld. Nach einem Import ohne `list_ak` bleibt der gespeicherte `list_ak` eines vorhandenen Spielers in der Datenbank unverändert (direkt per SQL geprüft).
- [ ] **Step 2: Run** `php tests/run.php Permissions`, `PlayersApi`, `PlayerValidator`, `KaderImport`, `ImportApi`. Erwartet: FAIL bei den neuen Prüfungen.
- [ ] **Step 3: Implement** Änderungen in den genannten Dateien. Steht in `PlayerInput` keine Liste erlaubter Werte für `roster_status`, bleibt die Datei unverändert und im `STATUS.md` steht: „Kaderstatus ist auf dem Server frei, die Auswahl begrenzt nur die Oberfläche.“
- [ ] **Step 4: Run** den gesamten Lauf `php tests/run.php` gegen `bvbb_test_pc`. Erwartet: alle Tests grün.
- [ ] **Step 5: Commit** `git commit -m "feat: drop list AK from API, age class from birth year only, allow NK1"`

### Task 2: Server – Jahrgang aus dem Geburtstag

**Files:**
- Create: `src/Domain/BirthYear.php`, `tests/BirthYearTest.php`
- Create: `migrations/NNN_birth_year_from_date.sql` (NNN = nächste freie Nummer)
- Modify: `src/Api/PlayersController.php` (POST, PUT), `src/Domain/KaderImport.php` beziehungsweise die Stelle, an der der Import die Änderungen je Spieler bildet
- Test: `tests/PlayersApiTest.php`, `tests/ImportApiTest.php`, `tests/DbTest.php`

**Interfaces:**
- Produces: `App\Domain\BirthYear::reconcile(array $changes, ?string $storedBirthDate): array{changes: array, invalid: list<string>}`.
  - `$changes` sind die schon geprüften Felder einer Anfrage (`birth_date` als `JJJJ-MM-TT` oder `null`, `birth_year` als `int` oder `null`). `$storedBirthDate` ist der gespeicherte Wert, beim Anlegen `null`.
  - Enthält `$changes` weder `birth_date` noch `birth_year`, kommt es unverändert zurück.
  - Wirksames Datum = `$changes['birth_date']`, wenn der Schlüssel vorhanden ist, sonst `$storedBirthDate`.
  - Ist das wirksame Datum gesetzt: Bei mitgeschicktem `birth_year`, das vom Jahr des Datums abweicht, kommt `invalid` = `['birth_year']` zurück. Sonst wird `changes['birth_year']` auf das Jahr gesetzt.
  - Ist das wirksame Datum `null`, bleibt `birth_year` wie mitgeschickt oder fehlt. Wird der Geburtstag geleert, bleibt der Jahrgang also stehen.
- Consumes: Die 422-Antwort mit `{error:"invalid", fields:[…]}` aus Task 8 (Etappe 1).

- [ ] **Step 1: Write the failing tests** `tests/BirthYearTest.php`:
  - `reconcile(['email1'=>'a@example.org'], '2014-09-14')` liefert `changes` unverändert, `invalid` `[]`.
  - `reconcile(['birth_date'=>'2014-09-14'], null)` liefert `changes['birth_year'] === 2014`.
  - `reconcile(['birth_date'=>'2014-09-14','birth_year'=>2014], null)` liefert `invalid` `[]`.
  - `reconcile(['birth_date'=>'2014-09-14','birth_year'=>2015], null)` liefert `invalid === ['birth_year']`.
  - `reconcile(['birth_year'=>2015], '2014-09-14')` liefert `invalid === ['birth_year']`.
  - `reconcile(['birth_year'=>2015], null)` liefert `changes['birth_year'] === 2015`.
  - `reconcile(['birth_date'=>null], '2014-09-14')` liefert `changes` ohne Schlüssel `birth_year`.
  - Zusätzlich in `PlayersApiTest`: `POST` mit `birth_date` `2014-09-14` ohne `birth_year` legt Jahrgang 2014 an. `PUT` mit `birth_date` `2013-01-02` setzt den Jahrgang auf 2013, und der Protokolleintrag nennt `birth_date` und `birth_year`. Review Focus 5: `PUT` mit `birth_date` `2014-09-14` und `birth_year` `2015` ergibt 422 `fields:["birth_year"]`, und `GET` zeigt die alten Werte.
  - Zusätzlich in `ImportApiTest`: Eine Zeile mit JG `14.09.2014` und abweichendem `birth_year` in der Datenbank speichert nach dem Import 2014. Eine Zeile mit JG `2015` (nur Jahr) speichert `birth_year` 2015 und `birth_date` leer.
  - Zusätzlich in `DbTest`: Nach `migrate()` hat ein vorher angelegter Spieler mit `birth_date` `2014-09-14` und `birth_year` 2015 den Jahrgang 2014. Ein Spieler ohne Datum behält seinen Jahrgang. Die Migration trägt sich in `schema_migrations` ein.
- [ ] **Step 2: Run** `php tests/run.php BirthYear`, `PlayersApi`, `ImportApi`, `Db`. Erwartet: FAIL.
- [ ] **Step 3: Implement** `BirthYear::reconcile`. Den Aufruf in POST, PUT und Import nach der Feldprüfung und der Rechteprüfung einbauen, vor dem Speichern und innerhalb der Transaktion. Ist `invalid` nicht leer, ergibt das 422, beim Import den Grund `invalid` mit `fields`. Migration: `UPDATE players … JOIN player_private … SET birth_year = YEAR(birth_date) WHERE birth_date IS NOT NULL AND (birth_year IS NULL OR birth_year <> YEAR(birth_date))`, am Ende der Selbsteintrag in `schema_migrations` wie bei den anderen Migrationen. Keine Einträge ins Änderungsprotokoll.
- [ ] **Step 4: Run** den gesamten Lauf `php tests/run.php`. Erwartet: alle Tests grün.
- [ ] **Step 5: Commit** `git commit -m "feat: derive birth year from birth date"`

### Task 3: Oberfläche – Schrift, Spalten, Bearbeiten

**Files:**
- Modify: `public/css/app.css`, `public/js/roster.js`
- Test: `tests/ui-checklist.md`, Rauchtest in `tools/ui-smoke/`

**Interfaces:**
- Consumes: Antworten aus Task 1 und 2 (kein `list_ak`, `roster_status` darf `NK1` sein, 422 `fields:["birth_year"]`).
- Produces: In `roster.js` die Konstante `ROSTER_STATUS_ORDER = ['NK1','NK2','LK','SK']`, die Task 4 importiert. Dazu die Spaltenbeschriftungen: `birth_date` → „Geburtstag“, `birth_year` → „Jahrgang“. „AK (Liste)“ gibt es nicht mehr.

- [ ] **Step 1: Write the failing smoke checks** im Rauchtest: Die Tabelle hat keinen Spaltenkopf „AK (Liste)“, aber einen Kopf „Geburtstag“ und keinen Kopf „JG (Datum)“. Die Auswahl Kaderstatus im Bearbeiten-Dialog hat genau die Optionen leer, NK1, NK2, LK, SK, in dieser Reihenfolge. Mit eingetragenem Geburtstag ist das Feld Jahrgang `disabled` und zeigt den Hinweis „wird aus dem Geburtstag übernommen“. Wird der Geburtstag im Dialog geleert, ist der Jahrgang sofort wieder bearbeitbar. Der Import der Testkaderliste läuft weiter ohne 403, sendet also kein `list_ak`. Die berechnete Schriftgröße des Tabelleninhalts ist größer als vorher (Vergleichswert aus dem bisherigen `app.css` im Test festhalten).
- [ ] **Step 2: Run** den Rauchtest wie in `tools/ui-smoke/` beschrieben. Erwartet: FAIL bei den neuen Prüfungen.
- [ ] **Step 3: Implement**
  - `app.css`: Grundschrift des Inhalts um eine Stufe größer (etwa 12 bis 15 %, Ausgangspunkt sind die heutigen Werte), Überschriften entsprechend eine Stufe größer. Die Tabelle scrollt auf dem iPad quer weiter waagerecht.
  - `roster.js`: Beschriftungen wie oben. Die Import-Zuordnung der Spalte „AK“ sendet nichts mehr, so wie bisher die Spalte „Alter“. Die Spaltenprüfung A bis V bleibt. Kaderstatus als Auswahl aus `ROSTER_STATUS_ORDER`. Ein vorhandener Wert, der nicht in der Liste steht, bleibt als zusätzliche Option erhalten, damit beim Öffnen nichts verloren geht. Jahrgang im Dialog gesperrt, solange das Geburtstagsfeld einen Wert hat. Beim Speichern wird `birth_year` nicht mitgeschickt, wenn es gesperrt ist.
- [ ] **Step 4: Run** den Rauchtest in Chromium, Firefox und WebKit. Erwartet: alle Prüfungen grün. `tests/ui-checklist.md` um die Punkte aus Step 1 ergänzen.
- [ ] **Step 5: Commit** `git commit -m "feat: larger type, birthday label, NK1, locked birth year in roster ui"`

### Task 4: Oberfläche – Sortieren, Filterleiste, Export

**Files:**
- Create: `public/js/roster-view.js`
- Modify: `public/js/roster.js`, `public/css/app.css`
- Test: Rauchtest in `tools/ui-smoke/` (die reinen Funktionen werden dort im Seitenkontext per `import('/js/roster-view.js')` geprüft), `tests/ui-checklist.md`

**Interfaces:**
- Consumes: `ROSTER_STATUS_ORDER` aus Task 3.
- Produces (`roster-view.js`, ES-Modul, keine DOM-Zugriffe):
  - `EMPTY = '__empty__'`, das ist der Wert für „(leer)“.
  - `FILTER_KEYS = ['ak','birth_year','roster_status','club_name','gender','active']`. Die Reihenfolge entspricht der Leiste, die Suche kommt davor.
  - `defaultFilters(): {search:'', ak:'', birth_year:'', roster_status:'', club_name:'', gender:'', active:'true'}`. Dabei bedeutet `''` „alle“, `active` ist `'true'`, `'false'` oder `''`.
  - `filterOptions(rows: object[], key: string): {value: string, label: string}[]`. Liefert die vorkommenden Werte ohne „alle“, sortiert wie `compareValues`, mit `{value: EMPTY, label: '(leer)'}` am Ende, wenn ein Wert leer ist. Bei `active` sind die Beschriftungen „aktiv“ und „passiv“.
  - `filterRows(rows: object[], filters: object): object[]`. Alle gesetzten Filter wirken zusammen (und). Die Suche prüft `last_name` und `first_name` auf Teiltext, ohne Beachtung von Groß- und Kleinschreibung. Ein Filter auf einen Schlüssel, den die Zeilen nicht haben, wird ignoriert.
  - `compareValues(key: string, a: any, b: any): number`. `ak` nach der AK-Reihenfolge, `roster_status` nach `ROSTER_STATUS_ORDER`, `birth_year` und `list_no` numerisch, `birth_date` als Datum, sonst `localeCompare(…, 'de', {sensitivity:'base'})`.
  - `sortRows(rows: object[], key: string, dir: 'asc'|'desc'): object[]`. Gibt eine neue Liste zurück. Leere Werte stehen in beiden Richtungen am Ende. Bei Gleichstand gilt Name, Vorname, id.
  - `visibleFilterKeys(rows: object[]): string[]`. Liefert nur die Schlüssel aus `FILTER_KEYS`, die in den Zeilen vorkommen.

- [ ] **Step 1: Write the failing tests** (im Rauchtest, Daten erfunden):
  - `sortRows` nach `last_name` asc mit „Weißkopf“, „Özdemir“, „Müßig“, „Albers“, „Zander“ ergibt Albers, Müßig, Özdemir, Weißkopf, Zander (Review Focus 1).
  - `sortRows` nach `roster_status` asc mit SK, LK, leer, NK1, NK2 ergibt NK1, NK2, LK, SK, leer. Bei desc ergibt es SK, LK, NK2, NK1, leer.
  - `sortRows` nach `ak` asc mit O19, U09, leer, U13 ergibt U09, U13, O19, leer. `birth_year` asc mit 2016, 2009, `null`, 2010 ergibt 2009, 2010, 2016, `null` (Review Focus 3).
  - `filterRows` mit `{ak:'U13', roster_status:'LK'}` liefert nur Zeilen, auf die beides zutrifft. `{birth_year: EMPTY}` liefert die Zeilen ohne Jahrgang. `{search:'müß'}` findet „Müßig“. `defaultFilters()` blendet passive Spieler aus.
  - `filterOptions(rows,'birth_year')` ist numerisch aufsteigend und endet mit „(leer)“, wenn ein Jahrgang fehlt.
  - Als `trainer` angemeldet: Die Leiste enthält Suche, AK, Jahrgang, Kaderstatus, Verein, m/w und Status und keinen Filter für private Felder. Der Export enthält keine Spalte „Geburtstag“ und keine Kontaktspalten (Review Focus 2). Als `basis` angemeldet: nur Suche und Verein.
  - Als `kaderpflege`: Bei Filter „U13“ zeigt die Zählung „{n} von {m} Spielern“ mit den richtigen Zahlen. Ein Klick auf „Name“ zeigt ▲, ein zweiter ▼, und `aria-sort` wechselt zwischen `ascending` und `descending`. Einen Spieler bei Filter Status „aktiv“ auf passiv setzen: Die Filter bleiben, der Spieler verschwindet, und die Zählung sinkt um 1 (Review Focus 4). „Filter zurücksetzen“ stellt `defaultFilters()` wieder her. Der Export der gefilterten Liste enthält genau die angezeigten Zeilen in der angezeigten Reihenfolge und genau die sichtbaren Spalten mit deren Beschriftung.
- [ ] **Step 2: Run** den Rauchtest. Erwartet: FAIL, Modul nicht gefunden.
- [ ] **Step 3: Implement** `roster-view.js` nach den Signaturen. In `roster.js`: Den Zustand `{filters, sortKey:'last_name', sortDir:'asc'}` im Speicher halten, ohne `localStorage`. Bei jeder Änderung neu darstellen: `filterRows`, dann `sortRows`. Die Filterleiste über der Tabelle mit `<label>` und `<select>`, Suche als `<input type="search">`. Die Spaltenköpfe sind `<button>` im `<th>` mit `aria-sort`. Nach dem Speichern eines Spielers die Liste neu laden und den Zustand behalten. Der Export nimmt die Zeilen und Spalten der aktuellen Ansicht. Die Leiste gestalten wie die übrige App, auf dem iPad umbrechend.
- [ ] **Step 4: Run** den Rauchtest in Chromium, Firefox und WebKit. Erwartet: alle Prüfungen grün. `tests/ui-checklist.md` um Sortieren, Filter, „(leer)“, Zurücksetzen, Zählung und Export ergänzen. Danach geht der Projektinhaber die Checkliste von Hand in Firefox und Safari auf dem iPad durch.
- [ ] **Step 5: Commit** `git commit -m "feat: sortable columns and filter bar in roster"`

### Task 5: Auslieferung

**Files:**
- Modify: `src/Version.php` (`1.1.0`), `README.md` (Hinweis auf die neue Migration), `STATUS.md`

- [ ] **Step 1:** Die Version auf `1.1.0` setzen. `HealthTest` erwartet `version` `1.1.0`.
- [ ] **Step 2: Run** `php tests/run.php` und den Rauchtest. Erwartet: alles grün.
- [ ] **Step 3:** `STATUS.md` ergänzen: Abschnitt „Kaderliste rund machen“ mit den Festlegungen aus dem Entwurf, dem Ergebnis zum Kaderstatus (Task 1, Step 3) und dem Hinweis, dass die AK ohne Jahrgang jetzt leer ist (bisher: AK aus der Liste).
- [ ] **Step 4: Verifikation auf dem Test-Webspace (Projektinhaber):** Die neue Migration per phpMyAdmin einspielen, die Dateien hochladen. `/api/health` muss `{"ok":true,"version":"1.1.0"}` liefern. Danach die neuen Punkte der `tests/ui-checklist.md` durchgehen.
- [ ] **Step 5: Commit** `git commit -m "chore: release 1.1.0"`
