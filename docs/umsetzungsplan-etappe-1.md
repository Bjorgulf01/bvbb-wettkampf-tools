# Etappe 1: Grundgerüst und Kaderliste – Umsetzungsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ein lauffähiger Server mit Datenbank, Testanmeldung, Rollen mit Feldrechten, Änderungsprotokoll und zentraler Kaderliste (Pflege, Excel-Import, Datenprüfung).

**Architecture:** PHP 8.1 ohne Framework. Ein Einstiegspunkt (`public/index.php`) leitet an einen kleinen Router, der JSON-Schnittstellen bedient. Die Rechenlogik (Altersklasse, Vereinszuordnung, Rechte, Datenprüfung) liegt in reinen Klassen ohne Datenbankzugriff und ist einzeln testbar. Rechte stehen als Tabelle in der Datenbank, und der Server liefert nur erlaubte Felder aus. Das Frontend ist statisches HTML und JavaScript, das nur die Schnittstelle anspricht.

**Tech Stack:** PHP ≥ 8.1 (PDO, ohne Composer), MariaDB oder MySQL (utf8mb4; Zielumgebung laut Hosting-Check: PHP 8.4, MariaDB 10.11, Apache mit .htaccess, CREATE und DROP erlaubt, kein SSH), eigener Test-Runner (`php tests/run.php`), SheetJS 0.18.5 als lokale Datei für Excel im Browser.

**Spec:** [Entwurf: Wettkampf-Tools mit zentraler Datenhaltung (BVBB)](https://claude.ai/artifact/Xgh2gbZC29bgjMhYfvYh3D), Abschnitte 5 bis 10 und 13 (Etappe 1).

## Global Constraints

- PHP ab Version 8.1, MySQL oder MariaDB, HTTPS, Serverstandort Deutschland oder EU.
- Rechteprüfung auf dem Server bei jeder Anfrage, auch auf Feldebene. Ausblenden in der Oberfläche genügt nicht.
- Datenbankzugriffe nur über vorbereitete Abfragen. Konfigurationsdatei und Zugangsdaten liegen außerhalb des öffentlichen Verzeichnisses.
- Rollen und Rechte stehen in der Datenbank. Eine Person kann mehrere Rollen haben. Der Anmeldedienst liefert nur die Identität.
- Die Testanmeldung läuft nur bei ausdrücklicher Einstellung, und der Server startet nicht, wenn sie im Echtbetrieb an ist.
- Spieler werden nie gelöscht, sondern auf passiv gesetzt.
- Altersklasse: Alter = Kalenderjahr minus Jahrgang. U09, U11, U13, U15, U17, U19 gelten bis unter 9, 11, 13, 15, 17 und 19 Jahren, ab 19 gilt O19. Das Jahr kommt aus dem Turniertermin. Ohne Jahrgang gilt die AK aus der Liste.
- Das Bundesland (BER oder BRA) hängt am Verein. Vereine stammen aus der Mitgliederliste der Verbandssoftware, mit der Mitgliedsnummer als Schlüssel. Schreibvarianten aus der Kaderliste werden als Alias einem Verein zugeordnet. Vereine werden nie gelöscht, nur auf inaktiv gesetzt, und das Bundesland bleibt bei einem Import erhalten.
- Änderungsprotokoll für Kader und Rollen, ohne Gesundheitsinhalte.
- Testdaten sind erfunden. Echte Daten kommen erst nach Sicherheitsprüfung und Vertrag zur Auftragsverarbeitung.
- Firefox am PC und Safari auf dem iPad müssen funktionieren.

## Review Focus

1. Umlaute und ß (Müßig, Köppen, Weißkopf) überstehen Speichern und Laden unverändert. Test in Task 2.
2. Ein Trainer schickt beim Speichern ein Feld mit, das er nicht schreiben darf (zum Beispiel `email1`): erwartet 403, nichts wird gespeichert. Test in Task 8.
3. Eine Person mit mehreren Rollen bekommt die Vereinigung der Rechte. Eine Person ohne Rolle bekommt nichts, aber keinen Serverfehler. Tests in Task 5 und Task 8.
4. Excel-Import mit Leerzeichen am Rand („Marx “), doppelten Namen in der Datei und einem unbekannten Verein: kein Absturz, Meldung in der Vorschau. Test in Task 9.
5. Abgelaufene Sitzung und Anfrage ohne CSRF-Token: 401 beziehungsweise 403, nie 500. Test in Task 7.

## Abgrenzung

Nicht in Etappe 1: Betreuerliste (kommt mit den Turnieren in Etappe 2), Turniere, NADA und Sportmedizin als eigene Tabellen (Etappe 3), echte Anmeldung über die Digitale Geschäftsstelle, serverseitiger Excel-Export (der Export läuft im Browser). Die Excel-Spalten „SpoMed“ und „Anti Doping“ werden bis Etappe 3 als Textfelder in `player_private` geführt und sind nur für Kaderpflege und Administrator sichtbar. Die daraus abgeleitete Ampel sehen ab Etappe 1 alle Rollen außer Basis (Task 4b).

## Dateistruktur

| Pfad | Aufgabe |
| --- | --- |
| `public/index.php` | Einstiegspunkt, baut Request, Router, Sitzung |
| `public/` (Oberfläche) | `index.html`, `js/*.js`, `vendor/xlsx.full.min.js` |
| `src/autoload.php`, `src/Config.php` | Laden von Klassen und Konfiguration |
| `src/Db.php`, `src/Migrator.php`, `migrations/*.sql` | Verbindung und Schema |
| `src/Domain/AgeClass.php`, `ClubDirectory.php`, `PlayerValidator.php` | Rechenlogik ohne Datenbank |
| `src/Rbac/Permissions.php` | Rechte pro Rolle und Feldgruppe |
| `src/Http/Request.php`, `Response.php`, `Router.php` | HTTP-Grundlage |
| `src/Auth/*.php` | Identität, Testanmeldung, Sitzung, Wache |
| `src/Repo/*.php` | Datenbankzugriff je Objektart |
| `src/Api/*Controller.php` | Schnittstellen |
| `tests/` | `run.php`, `support/`, `*Test.php` |
| `config.example.php`, `.htaccess`, `bin/migrate.php`, `README.md` | Auslieferung |

Bezeichner im Code sind englisch. Zuordnung zum Entwurf: `players` = spieler, `player_private` = spieler\_privat, `clubs` = vereine, `users`, `roles`, `role_permissions` = benutzer, rollen, rechte, `audit_log` = aenderungsprotokoll.

## Task 1: Test-Runner und Konfiguration

**Files:**

- Create: `tests/run.php`, `tests/support/assert.php`, `src/autoload.php`, `src/Config.php`, `tests/ConfigTest.php`

**Interfaces:**

- Produces: `php tests/run.php [filter]` (Exit-Code 1 bei Fehlern, „SKIPPED“ bei `skip()`). Hilfsfunktionen `assertSame(mixed $expected, mixed $actual, string $msg = ''): void`, `assertTrue(bool $v, string $msg = ''): void`, `assertThrows(callable $fn, string $class): void`, `skip(string $why): never`. Eine Testdatei gibt `array<string, callable>` zurück.
- Produces: `App\Config::__construct(array $values)`, `static fromFile(string $path): self` (Datei gibt ein Array zurück), `get(string $key, mixed $default = null): mixed` mit Punktschreibweise (`db.dsn`).

* [ ] **Step 1:** Runner, Autoload (`App\` auf `src/`) und Assert-Helfer schreiben. Prüfung: `php tests/run.php` ohne Tests meldet „0 tests“ und Exit-Code 0.
* [ ] **Step 2: Failing test** `tests/ConfigTest.php`: `get('db.dsn')` liefert den Wert, ein fehlender Schlüssel liefert den Default, `fromFile` lädt eine temporäre Datei mit `return ['env'=>'development'];`.
* [ ] **Step 3:** Ausführen: `php tests/run.php Config`. Erwartet: FAIL, Klasse nicht gefunden.
* [ ] **Step 4:** `Config` nach den Signaturen implementieren.
* [ ] **Step 5:** Ausführen. Erwartet: 3 passed. Commit: `git commit -m "feat: test runner and config"`

## Task 2: Datenbank, Migrationen, Basisschema

**Files:**

- Create: `src/Db.php`, `src/Migrator.php`, `migrations/001_core.sql`, `migrations/002_seed_roles.sql`, `tests/support/db.php`, `tests/DbTest.php`

**Interfaces:**

- Consumes: `Config::get`.
- Produces: `Db::connect(Config $c): PDO` (Schlüssel `db.dsn`, `db.user`, `db.pass`; `ERRMODE_EXCEPTION`, `ATTR_EMULATE_PREPARES => false`, utf8mb4).
- Produces: `Migrator::__construct(PDO $pdo, string $dir)`, `migrate(): array` (Liste der neu angewendeten Dateinamen, Verlauf in `schema_migrations(filename PK, applied_at)`).
- Produces: `testPdo(): PDO` in `tests/support/db.php`. Liest `TEST_DB_DSN`, `TEST_DB_USER`, `TEST_DB_PASS`, ruft `skip()` wenn nicht gesetzt, löscht alle Tabellen und gibt die saubere Verbindung zurück.
- Produces: Tabellen `users(id, subject UNIQUE, name, email, active, created_at)`, `roles(id, role_key UNIQUE)`, `user_roles(user_id, role_id)`, `role_permissions(role_id, perm_group, mode)`, `players(id, last_name, first_name, club_name, list_no, list_ak, gender, birth_year, roster_status, active, training_site, created_at, updated_at)`, `player_private(player_id PK, birth_date, trainer_info, school, school_contact, sportmed_note, antidoping_note, email1, email2, phone_player, phone_mother, phone_father, shirt_size)`, `clubs(id, name UNIQUE, short, state)`, `audit_log(id, at, user_id, action, object_type, object_id, summary)`. Rollenschlüssel: `admin`, `roster_editor` (Kaderpflege), `health_staff` (NADA/Sportmedizin), `reviewer` (Prüfer), `coach` (Trainer), `basic` (Basis). `mode` ist `read` oder `write`.
- Seed (Migration 002), Rechte nach Entwurf Abschnitt 6, `W` = write, `R` = read:

| perm\_group | admin | roster\_editor | health\_staff | reviewer | coach | basic |
| --- | --- | --- | --- | --- | --- | --- |
| `player.basic` | W | W | R | R | R | R |
| `player.roster` | W | W | R | R | R | – |
| `player.private` | W | W | – | – | – | – |
| `clubs` | W | W | R | R | R | R |
| `users` | W | – | – | – | – | – |
| `audit` | R | – | – | R | – | – |

- [ ] **Step 1: Failing test** `tests/DbTest.php`: `migrate()` auf leerer Datenbank liefert `['001_core.sql','002_seed_roles.sql']`, der zweite Aufruf `[]`. Rollen-Schlüssel exakt `['admin','basic','coach','health_staff','reviewer','roster_editor']` (sortiert). Die Zahl der Rechte für `coach` auf `player.private` ist 0. Hin- und Rückweg: Spieler mit `last_name` ‘Müßig’, ‘Köppen’, ‘Weißkopf’, ‘Möllendorf’ speichern und laden ergibt dieselben Zeichen.
- [ ] **Step 2:** `TEST_DB_DSN=... php tests/run.php Db`. Erwartet: FAIL, `Migrator` nicht gefunden.
- [ ] **Step 3:** `Db`, `Migrator` und die beiden SQL-Dateien implementieren. Tabellen mit `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`. Migrationen laufen je Datei in einer Transaktion, wo die Datenbank es zulässt.
- [ ] **Step 4:** Ausführen. Erwartet: alle Tests PASS. Ohne gesetzte Test-Datenbank: SKIPPED mit Hinweis.
- [ ] **Step 5:** Commit: `git commit -m "feat: database schema, migrator, role seed"`

## Task 3: Altersklasse

**Files:**

- Create: `src/Domain/AgeClass.php`, `tests/AgeClassTest.php`

**Interfaces:**

- Produces: `AgeClass::for(?string $birthYearOrDate, string $listAk, int $year): string` (`'U09'`, `'U11'`, `'U13'`, `'U15'`, `'U17'`, `'U19'`, `'O19'` oder `''`).
- Produces: `AgeClass::yearFromTerm(string $term, DateTimeImmutable $today): int` (letzte vierstellige Jahreszahl im Text, sonst Jahr von `$today`).

* [ ] **Step 1: Failing test:**
  - `for('2008','',2026) === 'U19'`, `for('2008','',2027) === 'O19'`
  - `for('2015','',2026) === 'U13'`, `for('2015','',2027) === 'U13'`, `for('2015','',2028) === 'U15'`
  - Grenzen in 2026: Jahrgang 2018 ergibt `'U09'`, 2017 ergibt `'U11'`
  - Datum als Eingabe: `for('14.09.2014','',2026) === 'U13'`
  - ohne Jahrgang aus der Liste: `for('','U 09',2026) === 'U09'`, `for('','U22',2026) === 'O19'`, `for('','O22',2026) === 'O19'`, `for('','',2026) === ''`
  - `yearFromTerm('27.02.–01.03.2026', $today) === 2026`, ohne Jahreszahl das Jahr von `$today`
* [ ] **Step 2:** `php tests/run.php AgeClass`. Erwartet: FAIL, Klasse nicht gefunden.
* [ ] **Step 3:** Implementieren. Das Alter ist Jahr minus Jahrgang. `U` + Zahl gilt, solange das Alter kleiner als die Zahl ist. Der erste Treffer aus `[9,11,13,15,17,19]` entscheidet.
* [ ] **Step 4:** Ausführen. Erwartet: PASS.
* [ ] **Step 5:** Commit: `git commit -m "feat: age class by calendar year"`

## Task 4: Vereinszuordnung

**Files:**

- Create: `src/Domain/ClubDirectory.php`, `tests/ClubDirectoryTest.php`

**Interfaces:**

- Produces: `ClubDirectory::__construct(list<array{name:string,short:string,state:string}> $clubs)`, `lookup(string $clubName): array{short:string, state:string, known:bool}`.

* [ ] **Step 1: Failing test** mit Einträgen ‘SV Berliner Brauereien’ (SVBB, BER), ‘TSV-Spandau’ (TSV Sp, BER), ‘BC Potsdam’ (BCP, BRA):
  - `lookup('SV Berliner Brauereien')` ergibt SVBB, BER, known=true
  - Groß- und Kleinschreibung und Leerzeichen am Rand sind egal: `lookup('  tsv-spandau ')` ergibt TSV Sp
  - `lookup('BC Potsdam')['state'] === 'BRA'`
  - unbekannt: `lookup('Neuer Verein e.V.')` ergibt short `NVE`, state `''`, known=false
  - leer: `lookup('')` ergibt short `''`, known=false
* [ ] **Step 2:** `php tests/run.php ClubDirectory`. Erwartet: FAIL.
* [ ] **Step 3:** Implementieren. Kürzel unbekannter Vereine = Anfangsbuchstaben der Teile, getrennt an Leerzeichen, Bindestrich und Schrägstrich, groß geschrieben.
* [ ] **Step 4:** Ausführen. Erwartet: PASS.
* [ ] **Step 5:** Commit: `git commit -m "feat: club directory"`

## Task 4b: Ampel aus den Textspalten SpoMed und Anti Doping

**Files:**

- Create: `src/Domain/HealthStatus.php`, `tests/HealthStatusTest.php`

**Interfaces:**

- Produces: `HealthStatus::fromNote(?string $note, int $year, DateTimeImmutable $today): string` mit den Werten `done`, `scheduled`, `open`, `unclear`.
- Produces: Feldgruppe `player.health_light` mit den abgeleiteten, nur lesbaren Feldern `sportmed_status` und `antidoping_status`. Der Rohtext bleibt in `player.private`, denn er kann Namen und Gründe enthalten.

Regeln \[von dir bestätigt\], geprüft an den echten Werten der Kaderliste. Die Regeln gelten von oben nach unten, der erste Treffer zählt:

| Wert in der Liste | Ergebnis |
| --- | --- |
| leer | `open` |
| „Erledigt“ (Groß-/Kleinschreibung egal) | `done` |
| „/“ | `open (bestätigt: noch nichts gepflegt`) |
| „abgesagt“ | `open` |
| enthält „kein“ („Kein neuer Termin, weil …“) | `unclear` |
| enthält „angefragt“, „kommt bald“ oder „Termin“ („Neuer Termin 10.06“, „03.04.2025 (Termin verschoben … angefragt)“) | `scheduled` |
| vollständiges Datum im Jahr `$year`, nach `$today` | `scheduled` |
| vollständiges Datum im Jahr `$year`, nicht nach `$today` | `done` |
| vollständiges Datum aus einem früheren Jahr („26.02.2025“) | `open` (fällig im Jahr `$year`) |
| Datum ohne Jahr („23.2.“) | `unclear` |
| anderer Text („U09“) | `unclear` |

„Erledigt“ ohne Datum gilt für das Jahr, in dem die Liste gepflegt wird. Zum Jahreswechsel trägt die Kaderpflege die beiden Spalten neu ein.

- [ ] **Step 1: Failing test:** je eine Prüfung pro Tabellenzeile mit `$year = 2026` und `$today = 2026-11-16`, dazu `null`, „erledigt“ in Kleinbuchstaben und „30.06.2026“ (ergibt `done`). Die Werte stehen als Liste `[Eingabe, erwartetes Ergebnis]` im Test.
- [ ] **Step 2:** `php tests/run.php HealthStatus`. Erwartet: FAIL, Klasse nicht gefunden.
- [ ] **Step 3:** `HealthStatus::fromNote` nach der Tabelle implementieren. Datumsformate: `TT.MM.JJJJ`, `TT.MM.JJ`, `TT.MM.`.
- [ ] **Step 4:** Ausführen. Erwartet: PASS.
- [ ] **Step 5:** Commit: `git commit -m "feat: health traffic light from note columns"`

**Änderungen an anderen Tasks:**

- Task 2, Seed: Zeile `player.health_light` mit R für `admin`, `roster_editor`, `health_staff`, `reviewer`, `coach`, – für `basic`. Test: `coach` hat `player.health_light` lesend.
- Task 5, `FIELD_GROUPS`: `'player.health_light' => ['sportmed_status','antidoping_status']`. Diese Gruppe ist nie schreibbar. Test: Ein Versuch, `sportmed_status` zu schreiben, ergibt 403.
- Task 8: `GET /api/players` berechnet beide Felder aus `sportmed_note` und `antidoping_note` (Jahr aus `year`, Stichtag heute). Test: `coach` bekommt `sportmed_status`, aber nicht `sportmed_note`. `basic` bekommt keines von beiden.
- Task 9: neuer Befund `health_note_unclear` für Werte mit dem Ergebnis `unclear`.
- Task 11: zusätzliche Spalten „SpoMed“ und „Anti Doping“ als Ampelpunkt mit Text (grün erledigt, gelb terminiert, rot offen, grau unklar), damit die Farbe nicht allein trägt.

## Task 5: Rechte

**Files:**

- Create: `src/Rbac/Permissions.php`, `tests/PermissionsTest.php`

**Interfaces:**

- Produces: `Permissions::FIELD_GROUPS` (Konstante): `'player.basic' => ['last_name','first_name','club_name']`, `'player.roster' => ['list_no','list_ak','gender','birth_year','roster_status','active','training_site']`, `'player.private' => ['birth_date','trainer_info','school','school_contact','sportmed_note','antidoping_note','email1','email2','phone_player','phone_mother','phone_father','shirt_size']`.
- Produces: `Permissions::__construct(list<array{role:string,group:string,mode:string}> $grants)`, `fieldsFor(array $roleKeys, string $mode): list<string>`, `can(array $roleKeys, string $group, string $mode): bool`. Schreiben schließt Lesen ein. Unbekannte Rollenschlüssel werden ignoriert.
- Produces: `Permissions::fromDb(PDO $pdo): self` (liest `role_permissions` mit `roles`).

* [ ] **Step 1: Failing test** mit der Matrix aus Task 2:
  - `coach` liest `player.basic` und `player.roster`, aber kein Feld aus `player.private`
  - `basic` liest nur `player.basic`
  - `roster_editor` darf `player.private` schreiben
  - `['coach','roster_editor']` ergibt die Vereinigung, also auch `email1`
  - `[]` und `['unknown']` ergeben `[]`, ohne Ausnahme
  - `can(['reviewer'],'audit','read') === true`, `can(['coach'],'users','read') === false`
* [ ] **Step 2:** `php tests/run.php Permissions`. Erwartet: FAIL.
* [ ] **Step 3:** Implementieren. `fromDb` ist durch einen Test in Task 8 abgedeckt.
* [ ] **Step 4:** Ausführen. Erwartet: PASS.
* [ ] **Step 5:** Commit: `git commit -m "feat: field-level permissions"`

## Task 6: HTTP-Grundlage

**Files:**

- Create: `src/Http/Request.php`, `src/Http/Response.php`, `src/Http/Router.php`, `tests/RouterTest.php`

**Interfaces:**

- Produces: `Request::__construct(string $method, string $path, array $query = [], array $headers = [], ?array $json = null)`, `Request::fromGlobals(): self`, `header(string $name): ?string` (ohne Beachtung der Großschreibung).
- Produces: `Response::json(array $body, int $status = 200): self`, `status`, `body`, `send(): void`.
- Produces: `Router::__construct(?AuthGuard $guard = null)`, `add(string $method, string $pattern, callable $handler, array $opts = []): void` (Muster mit `{id}`, Handler `fn(Request $r, array $params): Response`, Optionen `auth`, `csrf`), `dispatch(Request $r): Response`.

* [ ] **Step 1: Failing test:** Pfad `/api/players/{id}` liefert `$params['id'] === '7'`. Unbekannter Pfad ergibt 404, bekannter Pfad mit falscher Methode 405. Wirft ein Handler `new Exception('geheim')`, ist die Antwort 500 mit Body `{"error":"server_error"}` ohne den Text der Ausnahme.
* [ ] **Step 2:** `php tests/run.php Router`. Erwartet: FAIL.
* [ ] **Step 3:** Implementieren. Der Guard (Task 7) wird vor dem Handler gefragt, wenn `auth` oder `csrf` gesetzt ist.
* [ ] **Step 4:** Ausführen. Erwartet: PASS.
* [ ] **Step 5:** Commit: `git commit -m "feat: http request, response, router"`

## Task 7: Anmeldung, Sitzung, CSRF

**Files:**

- Create: `src/Auth/Identity.php`, `AuthProvider.php`, `TestAuthProvider.php`, `Session.php`, `AuthGuard.php`, `src/Repo/UserRepo.php`, `src/Api/AuthController.php`, `tests/AuthTest.php`
- Modify: `src/Config.php` (Methode `assertSafe`)

**Interfaces:**

- Produces: `Identity(string $subject, string $name, string $email)`. `interface AuthProvider { identify(Request $r): ?Identity }`. `TestAuthProvider::__construct(array $personas)` (Schlüssel => `[name, email, roles]`, erfundene Personen je Rolle), `identify` liest `person` aus dem JSON.
- Produces: `User(id, subject, name, email, active, roles)`. `UserRepo::upsert(Identity $i, array $initialRoleKeys = []): User` (Rollen nur beim ersten Anlegen), `findById(int $id): ?User`.
- Produces: `Session::__construct(array &$store)`, `userId(): ?int`, `login(int $userId, int $now): void`, `logout(): void`, `csrfToken(): string`, `verifyCsrf(string $t): bool` (`hash_equals`), `isExpired(int $now, int $maxAge): bool`.
- Produces: `AuthGuard::__construct(Session $s, UserRepo $u, int $maxAge)`, `check(Request $r, array $opts): ?Response`, `currentUser(): ?User`.
- Produces: `Config::assertSafe(): void`, wirft `UnsafeConfig`, wenn `env === 'production'` und `test_auth === true`. Konfig-Schlüssel `first_admin_subject`.
- Produces Endpunkte: `POST /api/auth/test-login` (nur registriert, wenn `test_auth` gesetzt), `POST /api/auth/logout`, `GET /api/me` (Antwort `id`, `name`, `roles`, `csrf`).

* [ ] **Step 1: Failing test:**
  - `assertSafe` wirft bei `production` mit `test_auth=true` und wirft nicht bei `development`
  - ohne Sitzung: 401 mit `{"error":"unauthenticated"}`; abgelaufene Sitzung: 401 (nicht 500)
  - `POST` ohne gültigen Header `X-CSRF-Token`: 403 mit `{"error":"csrf"}`; mit Token: Handler läuft
  - inaktive Person: 403 `{"error":"inactive"}`
  - Test-Login einer Person mit `first_admin_subject` bekommt `admin`; jede andere Person nur ihre Test-Rollen. Eine Person ohne Rollen bekommt `/api/me` mit leerer Rollenliste und Status 200
* [ ] **Step 2:** `php tests/run.php Auth`. Erwartet: FAIL.
* [ ] **Step 3:** Implementieren. Sitzungs-Cookie `HttpOnly`, `SameSite=Lax`, `Secure` außer in `development`. Dieser Teil ist Aufgabe von `public/index.php` und wird in Task 12 von Hand geprüft.
* [ ] **Step 4:** Ausführen. Erwartet: PASS.
* [ ] **Step 5:** Commit: `git commit -m "feat: auth guard, test login, csrf"`

## Task 8: Spieler-Schnittstelle

**Files:**

- Create: `src/Repo/PlayerRepo.php`, `src/Repo/AuditRepo.php`, `src/Api/PlayersController.php`, `tests/PlayersApiTest.php`

**Interfaces:**

- Consumes: `Permissions::fieldsFor`, `can`, `fromDb`, `AuthGuard::currentUser`, `AgeClass::for`.
- Produces: `PlayerRepo::list(array $fields, ?bool $activeOnly = null): array`, `find(int $id, array $fields): ?array`, `create(array $data): int`, `update(int $id, array $data): list<string>` (Namen der geänderten Felder). Felder aus `player.private` liegen in `player_private`.
- Produces: `AuditRepo::log(?int $userId, string $action, string $objectType, int $objectId, string $summary): void`. Die Zusammenfassung nennt nur Feldnamen, nie Werte.
- Produces Endpunkte: `GET /api/players?year=YYYY` (jede Zeile enthält `id`, die erlaubten Felder und, wenn `player.roster` lesbar ist, das berechnete `ak`), `GET /api/players/{id}`, `POST /api/players`, `PUT /api/players/{id}`. Kein `DELETE`. Wer ein Feld sendet, das er nicht schreiben darf, bekommt 403 für die ganze Anfrage, und nichts wird gespeichert.

* [ ] **Step 1: Failing test** (erfundene Spieler, je eine Person pro Rolle):
  - `coach` bekommt je Zeile genau die Schlüssel von `player.basic`, `player.roster`, `id`, `ak`, und keinen Schlüssel aus `player.private`
  - `basic` bekommt nur `id` und `player.basic`
  - `roster_editor` bekommt `player.private` mit
  - Person ohne Rolle: 200 und leere Liste, kein Serverfehler
  - `coach` sendet `PUT` mit `{"email1":"x@example.org"}`: 403, und der gespeicherte Wert ist unverändert
  - `coach` sendet `PUT` mit einem erlaubten Lesefeld `{"club_name":"X"}`: ebenfalls 403, weil er kein Schreibrecht hat
  - `roster_editor` setzt `active` auf `false`: 200, der Spieler bleibt in `GET /api/players` enthalten. `DELETE /api/players/1` ergibt 405
  - der Protokolleintrag zum `PUT` nennt `email1` als geändertes Feld, enthält den Wert nicht
  - `fromDb` liefert dieselben Rechte wie die Seed-Matrix aus Task 2
* [ ] **Step 2:** `TEST_DB_DSN=... php tests/run.php PlayersApi`. Erwartet: FAIL.
* [ ] **Step 3:** Implementieren. Die Controller bilden die erlaubten Felder aus `Permissions` und geben nie mehr aus. Beim Schreiben wird zuerst geprüft, danach gespeichert, in einer Transaktion.
* [ ] **Step 4:** Ausführen. Erwartet: PASS.
* [ ] **Step 5:** Commit: `git commit -m "feat: players api with field-level access and audit"`

## Task 9: Vereine, Datenprüfung, Excel-Import

**Files:**

- Create: `src/Repo/ClubRepo.php`, `src/Domain/PlayerValidator.php`, `src/Domain/KaderImport.php`, `src/Api/ClubsController.php`, `tests/PlayerValidatorTest.php`, `tests/KaderImportTest.php`, `tests/ImportApiTest.php`
- Modify: `src/Api/PlayersController.php`

**Interfaces:**

- Consumes: `AgeClass::for`, `ClubDirectory::lookup`, `PlayerRepo`, `AuditRepo`.
- Produces: `ClubRepo::all(): list<array{name,short,state}>`, `replaceAll(list<array> $clubs): void`. Endpunkte `GET /api/clubs` und `PUT /api/clubs` (Schreibrecht `clubs`).
- Produces: `PlayerValidator::issues(list<array> $players, int $year, ClubDirectory $clubs): list<array{code:string, player:string, detail:string}>`. Codes: `missing_birth_year`, `missing_club`, `unknown_club`, `bad_gender`, `missing_roster_status`, `ak_mismatch`, `training_site_looks_like_ak`, `duplicate_name`.
- Produces: `KaderImport::normalizeRow(array $row): array` (trimmt, macht aus ‘U 09’ ‘U09’, leitet birth\_year aus birth\_date ab (die vierstellige Jahreszahl), leere Felder zu `null`). `KaderImport::plan(list<array> $rows, list<array> $existing): array{create:list<array>, update:list<array{id:int,data:array}>, skipped:list<array{row:int,reason:string}>}`. Abgleich über Nachname und Vorname ohne Groß-/Kleinschreibung.
- Produces Endpunkte: `GET /api/players/issues?year=YYYY` und `POST /api/players/import` mit Body `{rows:[...], dry_run:bool}` und Antwort `{create:int, update:int, skipped:[...], issues:[...]}`. Der Import braucht Schreibrecht auf `player.basic`, `player.roster` und `player.private`. Spieler, die nicht in der Datei stehen, bleiben unverändert. `dry_run` schreibt nichts, `apply` läuft in einer Transaktion.

* [ ] **Step 1: Failing test:**
  - `ak_mismatch`: Spieler Jahrgang 2015, `list_ak` ‘U11’, Jahr 2026: ein Befund mit `detail` ‘Liste U11, berechnet U13’
  - Pro Code ein Spieler, der genau diesen Befund auslöst, und ein Spieler ohne Befund
  - `normalizeRow(['last_name'=>'Marx ','first_name'=>'Jannick ','list_ak'=>'U 09'])` liefert `Marx`, `Jannick`, `U09`
  - Zeile mit `gender` ‘O22’ (Platzhalterzeile aus der Excel-Liste) und Zeile ohne Nachnamen landen in `skipped`
  - Aus birth\_date „14.09.2014“ wird birth\_year 2014; zwei gleiche Namen in einer Datei: erste wird verarbeitet, zweite steht in `skipped` mit Grund `duplicate_in_file`
  - unbekannter Verein: Import läuft, `issues` enthält `unknown_club`
  - `dry_run` verändert die Datenbank nicht, `apply` mit anschließendem `GET` zeigt die neuen Spieler. `coach` ruft `POST /api/players/import` auf: 403
* [ ] **Step 2:** `php tests/run.php PlayerValidator`, `KaderImport`, `ImportApi`. Erwartet: FAIL.
* [ ] **Step 3:** Implementieren. Der Import übernimmt die Regeln der Datenprüfung aus dem Prototyp (Version 1.5.0).
* [ ] **Step 4:** Ausführen. Erwartet: PASS.
* [ ] **Step 5:** Commit: `git commit -m "feat: clubs, player validation, kader import"`

## Task 10: Benutzer, Rollen, Protokoll

**Files:**

- Create: `src/Api/UsersController.php`, `src/Api/AuditController.php`, `tests/UsersApiTest.php`
- Modify: `src/Repo/UserRepo.php`, `src/Repo/AuditRepo.php`

**Interfaces:**

- Produces: `UserRepo::all(): list<User>`, `setRoles(int $userId, array $roleKeys): void`, `setActive(int $userId, bool $active): void`. `AuditRepo::recent(int $limit = 100): list<array>`.
- Produces Endpunkte: `GET /api/users`, `PUT /api/users/{id}/roles` mit `{roles:[...]}`, `PUT /api/users/{id}` mit `{active:bool}` (alle nur mit Schreibrecht `users`), `GET /api/audit?limit=N` (Leserecht `audit`).

* [ ] **Step 1: Failing test:**
  - `admin` setzt Rollen und das Ergebnis erscheint in `GET /api/users`. Ein Protokolleintrag „roles“ existiert
  - unbekannter Rollenschlüssel: 422
  - dem letzten `admin` die Rolle `admin` zu entziehen: 409, nichts geändert
  - `coach` ruft `GET /api/users`: 403. `reviewer` ruft `GET /api/audit`: 200
  - eine Person mit `active=false` bekommt bei der Anmeldung 403 `inactive`
* [ ] **Step 2:** `php tests/run.php UsersApi`. Erwartet: FAIL.
* [ ] **Step 3:** Implementieren.
* [ ] **Step 4:** Ausführen. Erwartet: PASS.
* [ ] **Step 5:** Commit: `git commit -m "feat: users, roles, audit read"`

## Task 11: Oberfläche

**Files:**

- Create: `public/index.html`, `public/js/api.js`, `public/js/login.js`, `public/js/home.js`, `public/js/roster.js`, `public/vendor/xlsx.full.min.js` (lokale Kopie von SheetJS 0.18.5)
- Test: `tests/ui-checklist.md`

**Interfaces:**

- Consumes: alle Endpunkte aus Task 7 bis 10.
- Produces: `api(method, path, body)` in `api.js` (setzt `X-CSRF-Token`, wirft bei 401 zur Anmeldeseite). `COLUMN_KEYS` in `roster.js` ordnet die 22 Excel-Spalten der Kaderliste, in Reihenfolge, den Schlüsseln der Schnittstelle zu: Nummer=`list_no`, Name=`last_name`, Vorname=`first_name`, AK=`list_ak`, m/w=`gender`, Kaderstatus=`roster_status`, Status=`active`, Verein=`club_name`, JG=`birth_date`, Trainer Mail/Verein=`trainer_info`, Schule/Klasse/Lehrer=`school`, Kontakt Schulbefreiungen=`school_contact`, Trainingsort=`training_site`, Alter=(nicht gespeichert), SpoMed=`sportmed_note`, Anti Doping=`antidoping_note`, email 1=`email1`, email 2=`email2`, Telefon Spieler=`phone_player`, Telefon Mutter=`phone_mother`, Telefon Vater=`phone_father`, T-Shirt Größe=`shirt_size`. `birth_year` wird beim Import vom Server aus `birth_date` abgeleitet.

* [ ] **Step 1:** Checkliste `tests/ui-checklist.md` mit erwarteten Ergebnissen schreiben (siehe Step 4).
* [ ] **Step 2:** Anmeldeseite (nur sichtbar, wenn `GET /api/me` 401 liefert und die Testanmeldung aktiv ist), Startseite mit Kacheln nach Rolle (Kaderliste und Verwaltung aktiv, alle anderen als „folgt“ gesperrt), Kaderliste.
* [ ] **Step 3:** Die Kaderliste baut ihre Spalten aus den Schlüsseln der Antwort, nicht aus einer festen Liste. Bearbeiten nur, wenn die Antwort das Feld enthält und `GET /api/me` das Schreibrecht ausweist. Import mit Vorschau (Aufruf mit `dry_run`, Anzeige von Zahlen und Befunden) und „Übernehmen“. Datenprüfung als Panel mit Klick zum Spieler. Export als Excel aus den geladenen Daten.
* [ ] **Step 4: Verifikation:** Mit Testdaten und je einer Testperson pro Rolle durchgehen. `coach`: im Netzwerk-Tab und in der Tabelle kein Kontaktfeld, keine Bearbeiten-Schaltfläche. `basic`: nur Name und Verein. `roster_editor`: alle Spalten, Import mit Vorschau, Datenprüfung zeigt Befunde. In Firefox und Safari durchführen. Erwartet: alle Punkte der Checkliste erfüllt. Falls ein Browser im Build verfügbar ist, zusätzlich ein automatischer Rauchtest.
* [ ] **Step 5:** Commit: `git commit -m "feat: roster ui"`

## Task 12: Auslieferung

**Files:**

- Create: `config.example.php`, `.htaccess`, `bin/migrate.php`, `README.md`, `tests/HealthTest.php`
- Modify: `public/index.php`

**Interfaces:**

- Produces: `GET /api/health` ohne Anmeldung: `{"ok":true,"version":"1.0.0"}`, bei Datenbankfehler `{"ok":false}` mit 503, nie Details.
- Produces: `bin/migrate.php` (Aufruf `php bin/migrate.php`) wendet ausstehende Migrationen an.
- Produces: `config.example.php` mit Schlüsseln `env`, `db.dsn`, `db.user`, `db.pass`, `test_auth`, `first_admin_subject`, `session_max_age`.

* [ ] **Step 1: Failing test** `tests/HealthTest.php`: `GET /api/health` liefert `ok: true` und keine Schlüssel außer `ok` und `version`.
* [ ] **Step 2:** `php tests/run.php Health`. Erwartet: FAIL.
* [ ] **Step 3:** Implementieren. `public/index.php` ruft `Config::assertSafe()` vor dem Routing auf und antwortet bei einer Ausnahme mit 500 und einem allgemeinen Text. `.htaccess` verweigert den Zugriff auf alles außer `public/`. `README.md` beschreibt das Hochladen per SFTP, die Lage der Konfigurationsdatei außerhalb des öffentlichen Verzeichnisses und die Migration. Ergebnis des Hosting-Checks: PHP 8.4, alle Erweiterungen vorhanden, HTTPS aktiv, Ordner oberhalb des öffentlichen Verzeichnisses beschreibbar. Daraus folgt die Auslieferung: Der Inhalt von public/ kommt in das öffentliche Verzeichnis (webseiten/). Alles andere (src/, migrations/, config.php) liegt in einem Ordner darunter und darüber, zum Beispiel bvbb-app/, den der Webserver nicht ausliefert. public/index.php lädt den Rest über einen relativen Pfad. Der Code muss auf PHP 8.4 ohne Hinweise zu veralteten Funktionen laufen, Parameter mit Standardwert null brauchen also ein ausdrückliches Fragezeichen vor dem Typ. Der Rewrite-Test auf dem Webspace ist gelaufen: URL-Umschreibung und .htaccess funktionieren, der Router braucht also nur saubere Adressen wie /api/players.
* [ ] **Step 4: Verifikation auf dem Test-Webspace:**
  - `GET /api/health` ergibt `ok: true`
  - Zugriff auf `/src/Config.php`, `/migrations/001_core.sql` und `/config.php` ergibt 403 oder 404
  - mit `env=production` und `test_auth=true` antwortet die Seite mit 500 und zeigt keine Testanmeldung
  - Sitzungs-Cookie trägt in den Entwicklerwerkzeugen `Secure`, `HttpOnly`, `SameSite=Lax`
  - Anmeldung als jede Testperson, Import der erfundenen Testliste
* [ ] **Step 5:** Commit: `git commit -m "feat: deployment files and health check"`

## Offene Punkte für dich (erste Fassung, Antworten siehe „Geklärt nach der Freigabe“)

1. Hat dein Webspace SSH oder einen PHP-Aufruf auf der Kommandozeile? Ohne ihn braucht `bin/migrate.php` eine zeitlich begrenzte Alternative, zum Beispiel einen geschützten Aufruf über die Webseite, der danach wieder entfernt wird.
2. Sind die Textspalten „SpoMed“ und „Anti Doping“ bis Etappe 3 nur für Kaderpflege und Administrator sichtbar, oder soll die Ampel für Trainer schon jetzt aus ihnen abgeleitet werden?
3. Welche Felder zählen zu „einfach“ und „erweitert“? Der Plan nimmt die Aufteilung aus Task 2 an. Sie lässt sich später in `role_permissions` und `FIELD_GROUPS` ändern.

## Geklärt nach der Freigabe

1. Der Webspace hat kein SSH, bei Bedarf wird es gebucht. Migrationen werden deshalb per phpMyAdmin als SQL-Datei importiert. Jede Migrationsdatei trägt sich am Ende selbst in `schema_migrations` ein. `bin/migrate.php` bleibt für den Fall, dass SSH dazukommt (Task 2 und Task 12).
2. Die Ampel für NADA und Sportmedizin wird schon in Etappe 1 aus den beiden Textspalten abgeleitet (Task 4b).
3. Die Aufteilung der Felder in „einfach“ und „erweitert“ aus Task 2 ist bestätigt. Außerdem geklärt: „/“ in SpoMed und Anti Doping bedeutet „noch nichts gepflegt“ (rot, open), der Zustand na entfällt. „Erledigt“ ohne Datum gilt für das laufende Kalenderjahr. Die Mitgliederliste (Task 9b) und die Zählregel der Behördenstatistik kommen später.

## Vereinsstammdaten aus der Mitgliederliste

Die Mitgliederliste der Verbandssoftware (Langname, Kürzel, Mitgliedsnummer) ist die führende Quelle für Vereine. Sie wird in der App nicht von Hand gepflegt, sondern per Excel- oder CSV-Upload eingespielt. Die Abschnitte oben gelten mit diesen Änderungen. Die Mitgliedsnummer ist der Schlüssel für Turnierabrechnung und später die Meldegeldweiterberechnung. Begriff: Der Verband ist der BVBB und umfasst Berlin und Brandenburg. Die Zuordnung zu Berlin (BER) oder Brandenburg (BRA) ist das Bundesland und steht im Feld state des Vereins. Es entscheidet, welches Amt fördert, denn Brandenburger Spieler werden nicht durch Berlin gefördert und umgekehrt. Das Bundesland wird in der App am Verein gepflegt und nie automatisch gesetzt. Maßgeblich ist der Verein, nicht der Wohnort des Spielers \[Du\]. Ein Spieler bekommt kein eigenes Bundesland-Feld. Später möglich: Beim Import schlägt die App aus der Postleitzahl des Vereins ein Bundesland vor, das jemand bestätigen muss.

**Änderungen an anderen Tasks:**

- Task 2, Schema: `clubs(id, member_no UNIQUE, name, short, state, active, imported_at)` und `club_aliases(club_id, alias UNIQUE)`. In `players` kommt `club_id` (leer erlaubt) neben `club_name` hinzu. `club_name` bleibt als Originaltext aus der Kaderliste erhalten.
- Task 4, `ClubDirectory`: Der Konstruktor bekommt `list<array{id:int, member_no:string, name:string, short:string, state:string, aliases:list<string>}>`. `lookup(string $text): array{club_id:?int, member_no:?string, name:string, short:string, state:string, known:bool}` findet über Langname, Kürzel oder Alias, ohne Beachtung von Groß-/Kleinschreibung und Leerzeichen am Rand. **Unbekannte Vereine bekommen kein erfundenes Kürzel mehr:** `short` ist leer und `known` ist false. Der Test mit „Neuer Verein e.V.“ erwartet jetzt `short === ''`. Auf amtlichen Unterlagen dürfen nur offizielle Kürzel stehen.
- Task 8: Die Spielerliste enthält zusätzlich die abgeleiteten, nur lesbaren Felder `club_short` (Gruppe `player.basic`) und `state` (Gruppe `player.roster`).
- Task 9, `KaderImport`: Beim Import wird `club_name` über `ClubDirectory` aufgelöst und `club_id` gesetzt. Ohne Treffer bleibt `club_id` leer, und der Befund `unknown_club` erscheint. Die Datenprüfung meldet außerdem alle Spieler ohne `club_id`.
- Task 11: Neue Ansicht „Vereine“ (Kachel oder Reiter in der Kaderliste) mit Liste, Upload, Vorschau und Eingabe von Bundesland und Aliasnamen.
- Rechte: Schreibrecht `clubs` für `admin` und `roster_editor`, Leserecht für alle.

## Task 9b: Vereinsimport aus der Mitgliederliste

**Files:**

- Create: `src/Domain/ClubImport.php`, `tests/ClubImportTest.php`, `tests/ClubImportApiTest.php`
- Modify: `src/Repo/ClubRepo.php`, `src/Api/ClubsController.php`

**Interfaces:**

- Produces: `ClubImport::plan(list<array{member_no:string, name:string, short:string, active?:bool}> $rows, list<array> $existing): array{create:list<array>, update:list<array{id:int,data:array}>, skipped:list<array{row:int,reason:string}>}`. Abgleich über `member_no`. Ein Update ändert nur `name`, `short` und `active`, nie `state`.
- Produces Endpunkte: `POST /api/clubs/import` mit `{rows:[...], dry_run:bool}`, `PUT /api/clubs/{id}` mit `{state, aliases}`, `GET /api/clubs`.

* [ ] **Step 1: Failing test:**
  - neue Mitgliedsnummer legt einen Verein an
  - bekannte Mitgliedsnummer ändert Namen und Kürzel, `state` bleibt wie gesetzt
  - Zeile ohne Mitgliedsnummer und doppelte Mitgliedsnummer in der Datei stehen in `skipped`
  - Vereine, die nicht in der Datei stehen, bleiben unverändert und werden nicht gelöscht
  - `dry_run` schreibt nichts. `coach` ruft den Import auf: 403
  - Kaderimport löst ‘TSV-Spandau’ über einen Alias auf den Verein auf. ‘Neuer Verein e.V.’ ergibt `club_id` leer und den Befund `unknown_club`
* [ ] **Step 2:** `php tests/run.php ClubImport`. Erwartet: FAIL.
* [ ] **Step 3:** Implementieren. Der Import nimmt nur die Spalten Mitgliedsnummer, Langname, Kürzel und Status. Alle anderen Spalten der Mitgliederliste (Ansprechpartner, Adressen, Bankdaten) werden nicht eingelesen.
* [ ] **Step 4:** Ausführen. Erwartet: PASS.
* [ ] **Step 5:** Commit: `git commit -m "feat: club master data import"`
