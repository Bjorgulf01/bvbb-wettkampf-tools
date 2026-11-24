# Entwicklungsablauf: Git-Abschluss und Webspace-Paket – Umsetzungsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

Ablage im Repository: `docs/superpowers/plans/2026-11-24-entwicklungsablauf.md`. Den Entwurf zuerst als `docs/superpowers/specs/2026-11-23-entwicklungsablauf-design.md` ablegen. Beide Ordner gibt es noch nicht, sie entstehen mit den Dateien.

**Goal:** Nach einem Task legt Claude Code den Pull-Request selbst an und führt ihn nach Torstens Freigabe zusammen. Ein Befehl baut ein Paket, das genau wie der Webspace aufgebaut ist.

**Architecture:** Teil 1 ist eine feste Arbeitsregel in `CLAUDE.md` plus eine Vorlage für Pull-Requests. Es gibt dafür kein Skript. Teil 2 ist die Klasse `Packager` in `tools/package/` (wird nicht ausgeliefert) mit einer dünnen Kommandozeile `tools/package.php`. Sie arbeitet mit einer Positivliste: Nur ausdrücklich genannte Quellen kommen ins Paket.

**Tech Stack:** PHP ≥ 8.1 ohne Composer (lokal und auf dem Webspace 8.4), eigener Test-Runner `php tests/run.php`, `gh` (GitHub CLI), optional `ZipArchive`.

**Spec:** `docs/superpowers/specs/2026-11-23-entwicklungsablauf-design.md`

## Global Constraints

- Ohne ausdrückliche Freigabe von Torsten im Chat wird nicht zusammengeführt (Weg A). Weg B ist nicht Teil dieses Plans.
- Kein Force-Push, kein direkter Commit auf `main`.
- Zugangsdaten nie in den Chat, ins Repository oder ins Paket.
- Nie im Paket: `config.php`, `sessions/`, `.htpasswd`, `tests/`, `tools/`, `docs/`, `.git`.
- Das Paket entsteht nur aus `main` ohne offene Änderungen.
- Läuft gleich auf dem Windows-PC und dem zweiten Entwicklungsrechner. Pfade im Paket, in der ZIP-Datei und in `HOCHLADEN.txt` immer mit `/`.
- Code läuft auf PHP 8.4 ohne Hinweise zu veralteten Funktionen. Parameter mit Standardwert `null` haben ein ausdrückliches `?` vor dem Typ.
- Kein automatisches Hochladen, kein automatisches Einspielen von Migrationen.

## Review Focus

1. Versteckte Dateien (`public/.htaccess`, `public/.user.ini`, `.htaccess` im Hauptordner) fehlen im Paket, weil `glob` sie überspringt. Erwartet: Sie sind im Ordner und in der ZIP-Datei. Test in Task 2.
2. `config.php` liegt versehentlich in `public/` oder `src/`. Erwartet: Abbruch mit Meldung, kein Paket. Test in Task 2.
3. Auf Windows entstehen Einträge mit `\` in der ZIP-Datei. Ein Linux-Hoster entpackt sie dann als Dateinamen mit Backslash. Erwartet: Nur `/`. Test in Task 2.
4. Ein alter Paketordner derselben Version enthält noch eine inzwischen gelöschte Datei. Erwartet: `build` weigert sich, in einen vorhandenen Ordner zu schreiben. Die Kommandozeile leert `dist/<Name>` vorher (nur innerhalb von `dist/`). Test in Task 2.
5. Freigabe kommt, aber der Pull-Request hat Konflikte, ist schon geschlossen, oder die Prüfungen auf GitHub sind rot. Erwartet: Kein Zusammenführen, Meldung an Torsten. Prüfung von Hand in Task 1, Step 5.

---

## Task 1: Abschluss eines Tasks in `CLAUDE.md` (Weg A)

**Files:**
- Modify oder Create: `CLAUDE.md` (neuer Abschnitt „Abschluss eines Tasks“)
- Create: `.github/pull_request_template.md`
- Create: `docs/superpowers/specs/2026-11-23-entwicklungsablauf-design.md` (Entwurf aus dem Projekt), `docs/superpowers/plans/2026-11-24-entwicklungsablauf.md` (dieser Plan)
- Modify: `STATUS.md` (Zeile „E1 Git-Abschluss“ in einer neuen Tabelle „Entwicklungsablauf“)

**Interfaces:**
- Produces: Den Ablauf, nach dem Task 2 und alle späteren Tasks abgeschlossen werden. Die Vorlage hat genau diese Überschriften: `## Was ist neu`, `## Tests`, `## Review`, `## Abweichungen vom Plan`, `## Was du prüfen solltest`.

- [ ] **Step 1: Voraussetzung prüfen.** `gh --version` und `gh auth status` ausführen. Erwartet: angemeldet bei github.com. Sonst anhalten und Torsten bitten, `gh` zu installieren und `gh auth login` selbst auszuführen. Keine Anmeldedaten abfragen.
- [ ] **Step 2: Methode beim Zusammenführen festlegen.** `git log --oneline -15 main` ansehen. Stehen dort Commits „Merge pull request #…“, gilt `--merge`. Gibt es nur einzelne Commits mit `(#N)` am Ende, gilt `--squash`. Das Ergebnis kommt als fester Wert in `CLAUDE.md`, damit es nicht jedes Mal neu abgelesen wird.
- [ ] **Step 3: `CLAUDE.md` und Vorlage schreiben.** Den Abschnitt nach Teil 1 des Entwurfs gliedern, als zwei nummerierte Listen: „Wenn Tests und Review grün sind“ (STATUS.md nachtragen → committen → `git push -u origin <zweig>` → `gh pr create --base main --title "<Task>: <Kurzbeschreibung>" --body-file <Datei nach Vorlage>` → PR-Nummer in STATUS.md, committen, pushen → anhalten, Link und Prüfpunkte melden) und „Wenn Torsten freigibt“. Freigabe heißt eine ausdrückliche Aussage von Torsten im Chat wie „passt, zusammenführen“. „Sieht gut aus“ ohne Bezug zum Zusammenführen zählt nicht, dann nachfragen. Vor dem Zusammenführen: `gh pr view <N> --json state,mergeable,statusCheckRollup`. Weiter nur, wenn `state` gleich `OPEN` ist, `mergeable` gleich `MERGEABLE` und keine Prüfung fehlgeschlagen ist. Dann `gh pr merge <N> <Methode aus Step 2> --delete-branch`, danach `git switch main`, `git pull --ff-only`, `git fetch --prune`, `git branch -d <zweig>`. Scheitert `--ff-only` oder `-d`, anhalten und melden, nichts erzwingen. Zum Schluss melden: `git status -sb`, `git log --oneline -3`, „Nächster Schritt“ aus STATUS.md. Dazu die Grenzen aus dem Entwurf als eigene Liste.
- [ ] **Step 4: Selbstanwendung.** Diesen Task mit dem neuen Ablauf abschließen: Zweig `dev-e1-git-flow`, Pull-Request über `gh`, anhalten. Erwartet: Torsten sieht einen Pull-Request mit allen fünf Überschriften und konkreten Prüfpunkten.
- [ ] **Step 5: Gegenprobe von Hand (Review Focus 5).** Vor Torstens Freigabe `gh pr view <N> --json state,mergeable` zeigen. Mit Torsten absprechen, ob er den Abbruchfall sehen will (zum Beispiel einen Test-Pull-Request ohne Freigabe schließen). Erwartet: Bei einem geschlossenen Pull-Request führt Claude Code nicht zusammen und meldet den Grund.
- [ ] **Step 6: Nach Freigabe** zusammenführen und `main` lokal nachziehen wie in `CLAUDE.md`. Erwartet: Torsten muss auf GitHub nichts klicken und lokal keinen Befehl eingeben.

Commit (in Step 4): `git commit -m "docs: task completion flow with gh (way A)"`

## Task 2: Webspace-Paket

**Files:**
- Create: `tools/package/Packager.php`, `tools/package.php`, `tests/PackagerTest.php`
- Modify: `.gitignore` (`dist/`), `README.md` (Abschnitt Auslieferung), `STATUS.md` (Zeile „E2 Paket“)

**Interfaces:**
- Consumes: Die Versionsangabe aus `src/Version.php` (vorhandene Konstante oder Methode, nicht ändern). Test-Helfer `assertSame`, `assertTrue`, `assertThrows`, `skip` aus Task 1 der Etappe 1.
- Produces: Namensraum `Tools\Package`, Klasse `Packager`, Datei wird im Test und in `tools/package.php` per `require_once` geladen (der Autoloader kennt nur `App\` auf `src/`).
  - `Packager::MAP` (Konstante), Quelle (relativ zum Repository) => Ziel (relativ zum Paket): `'public' => 'webseiten'`, `'src' => 'bvbb-app/src'`, `'migrations' => 'bvbb-app/migrations'`, `'bin' => 'bvbb-app/bin'`, `'.htaccess' => 'bvbb-app/.htaccess'`, `'config.example.php' => 'bvbb-app/config.example.php'`.
  - `Packager::FORBIDDEN_NAMES` = `['config.php', '.htpasswd', 'sessions']`. Ein Eintrag mit diesem Namen irgendwo unter einer Quelle führt zum Abbruch. `config.example.php` ist erlaubt.
  - `__construct(string $repoDir)`
  - `build(string $targetDir, string $version, string $commit, DateTimeImmutable $now): array{dir: string, zip: ?string, files: list<string>}`. `files` sind die Paketpfade mit `/`, sortiert. Wirft `RuntimeException`, wenn `$targetDir` schon existiert, eine Quelle aus `MAP` fehlt oder ein verbotener Name gefunden wird (die Meldung nennt den Pfad). Die ZIP-Datei liegt neben dem Ordner als `<Ordnername>.zip`, Einträge beginnen mit `<Ordnername>/`. Ohne `ZipArchive` ist `zip` gleich `null`.
  - `tools/package.php`: prüft `git rev-parse --abbrev-ref HEAD` = `main` und `git status --porcelain` leer, sonst Exit-Code 1 mit Meldung. Commit aus `git rev-parse --short HEAD`, Ziel `dist/bvbb-wettkampf-<Version>`. Ein vorhandener Ordner und eine vorhandene ZIP-Datei mit genau diesem Namen werden vorher gelöscht, nur wenn der Pfad innerhalb von `<repo>/dist/` liegt. Gibt Ordner, ZIP-Datei (oder den Hinweis „ZipArchive fehlt, nur Ordner gebaut“) und die Zahl der Dateien aus.

- [ ] **Step 1: Failing tests** in `tests/PackagerTest.php`. Jeder Test baut in einem temporären Ordner ein Mini-Repository mit `public/index.php`, `public/.htaccess`, `public/.user.ini`, `public/vendor/xlsx.full.min.js`, `src/Version.php`, `migrations/001_core.sql`, `migrations/002_seed_roles.sql`, `bin/migrate.php`, `.htaccess`, `config.example.php`, `config.php`, `sessions/sess_x`, `tests/XTest.php`. Er baut nach `<tmp>/out/bvbb-wettkampf-9.9.9` mit Version `9.9.9`, Commit `abc1234` und `now` = 2026-11-24 12:00 Europe/Berlin.
  - `test_maps_sources_to_webspace_layout`: `files` enthält `webseiten/index.php`, `webseiten/.htaccess`, `webseiten/.user.ini`, `webseiten/vendor/xlsx.full.min.js`, `bvbb-app/src/Version.php`, `bvbb-app/migrations/001_core.sql`, `bvbb-app/migrations/002_seed_roles.sql`, `bvbb-app/bin/migrate.php`, `bvbb-app/.htaccess`, `bvbb-app/config.example.php`, `HOCHLADEN.txt`. Jede Datei existiert im Ordner mit gleichem Inhalt wie die Quelle.
  - `test_never_contains_config_sessions_tests`: Kein Eintrag in `files` endet auf `config.php` (außer `config.example.php`) oder enthält `sessions/`, `tests/`, `.htpasswd`. Gilt auch, obwohl Hauptordner-`config.php` und `sessions/` im Mini-Repository liegen.
  - `test_aborts_on_config_in_source`: `public/config.php` angelegt → `assertThrows(RuntimeException)`, `$targetDir` existiert danach nicht. Dasselbe für `src/sessions/`.
  - `test_refuses_existing_target`: `$targetDir` vorher anlegen → `RuntimeException`.
  - `test_missing_source_aborts`: `bin/` fehlt → `RuntimeException`, Meldung enthält `bin`.
  - `test_hochladen_txt`: Enthält `9.9.9`, `abc1234`, `24.11.2026`, beide Migrationsnamen, jede Zeile aus `files` und die drei Schritte (Ordner hochladen, `schema_migrations` in phpMyAdmin vergleichen, `/api/health` aufrufen). Kein `\` in der Datei.
  - `test_zip_matches_folder`: Ohne `ZipArchive` `skip('ZipArchive fehlt')`. Sonst enthält die ZIP-Datei genau `bvbb-wettkampf-9.9.9/` + jeden Eintrag aus `files`, keiner enthält `\`.
  - `test_real_repository`: Baut das echte Repository in einen temporären Ordner (Version und Commit beliebig). `files` enthält `webseiten/index.php`, `webseiten/.htaccess`, `bvbb-app/src/Version.php` und für jede Datei `migrations/*.sql` den passenden Eintrag. Kein Eintrag enthält `config.php` außer `config.example.php`.
- [ ] **Step 2:** `php tests/run.php Packager`. Erwartet: FAIL, `Packager.php` fehlt.
- [ ] **Step 3: `Packager` implementieren.** Verzeichnisse mit `RecursiveDirectoryIterator` (`SKIP_DOTS`, ohne Filter auf führende Punkte) durchlaufen, damit versteckte Dateien mitkommen. Zuerst alle Quellen auf verbotene Namen prüfen, dann erst schreiben. Paketpfade intern immer mit `/` bilden (`str_replace(DIRECTORY_SEPARATOR, '/', …)`). Symbolische Links nicht folgen, sondern abbrechen.
- [ ] **Step 4:** `php tests/run.php Packager`. Erwartet: alle PASS (`test_zip_matches_folder` darf SKIPPED sein, dann in der Zusammenfassung erwähnen). Danach `php tests/run.php` komplett gegen `bvbb_test_pc`. Erwartet: alle bisherigen Tests weiter grün.
- [ ] **Step 5: `tools/package.php`, `.gitignore`, `README.md`.** README: Der Abschnitt zur Auslieferung beschreibt `php tools/package.php`, das Hochladen der beiden Ordner auf die gleichnamigen Ordner im Webspace, dass `config.php` und `sessions/` auf dem Server bleiben und nie im Paket sind, und dass Hochladen keine alten Dateien löscht (Dateiliste in `HOCHLADEN.txt`).
- [ ] **Step 6: Verifikation von Hand.** Auf dem Zweig mit offenen Änderungen `php tools/package.php` ausführen. Erwartet: Exit-Code 1, Meldung „nur aus main ohne offene Änderungen“. Der Lauf auf `main` folgt nach dem Zusammenführen (Step 8).
- [ ] **Step 7: Abschluss nach `CLAUDE.md`.** Commit `git commit -m "feat: webspace package builder"`, Pull-Request über `gh`, anhalten. Prüfpunkt für Torsten: ZIP-Datei öffnen, beide Ordner und `HOCHLADEN.txt` ansehen.
- [ ] **Step 8: Nach Freigabe und Zusammenführen** auf `main` `php tools/package.php` ausführen. Erwartet: `dist/bvbb-wettkampf-1.0.0/` und `.zip`, Ausgabe nennt die Dateizahl. Torsten lädt das Paket auf den Test-Webspace und ruft `/api/health` auf. Erwartet: `{"ok":true,"version":"1.0.0"}`, Anmeldung weiter möglich (`config.php` und Sitzungen unberührt). Ergebnis in STATUS.md (über einen kleinen Folge-Zweig nach demselben Ablauf).

## Offen, bewusst nicht in diesem Plan

- Weg B (automatisch zusammenführen, wenn alles grün ist), später als Absatz in `CLAUDE.md`.
- Versionsnummer automatisch erhöhen. Bis dahin ändert der Task, der eine neue Fassung ausliefert, `src/Version.php` selbst.
