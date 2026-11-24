# Entwicklungsablauf: Git-Abschluss und Paket für den Webspace (kurzer Entwurf)

Stand 23.11.2026 · zur Freigabe · Ablage im Repository: `docs/superpowers/specs/2026-11-23-entwicklungsablauf-design.md`

Kennzeichnung: **[Du]** = so von dir gesagt. **[Vorschlag]** = von mir entschieden, bitte prüfen.

## Warum

Nach jedem Task legt Torsten heute den Pull-Request in der GitHub-Oberfläche an, führt ihn dort mit mehreren Klicks zusammen und holt danach `main` lokal nach. Beim Hochladen wählt er Ordner einzeln aus, weil das Repository anders aufgebaut ist als der Webspace. Ziel: Am Ende bleibt nur der prüfende Blick **[Du]**.

## Ansätze

- **A (gewählt [Du]):** Claude Code legt den Pull-Request selbst an. Torsten prüft ihn auf GitHub und gibt mit einem Satz frei, danach erledigt Claude Code den Rest. Die Freigabe bleibt bei Torsten.
- B: Vollautomatisch zusammenführen, wenn Tests und Review grün sind. Vorgemerkt für später, wenn mehr Erfahrung da ist **[Du]**.
- C: Ohne Pull-Request direkt in `main`. Verworfen, weil dann die Ansicht der Änderungen zum Prüfen fehlt.
- Paket: Ein Befehl erzeugt einen Ordner im Aufbau des Webspace **[Du: „so kenne ich das“]**.

## Teil 1: Abschluss eines Tasks (Weg A)

Festgehalten als Abschnitt „Abschluss eines Tasks“ in `CLAUDE.md`, damit beide Entwicklungsrechner gleich arbeiten.

**Wenn Tests und Review grün sind:**
1. STATUS.md auf dem Task-Zweig nachtragen, committen, Zweig hochladen.
2. Pull-Request mit `gh pr create` anlegen. Die Beschreibung hat feste Abschnitte: Was ist neu, Tests (Anzahl, Datenbank, PHP-Version), Review-Ergebnis, Abweichungen vom Plan, **Was du prüfen solltest** (zwei bis fünf konkrete Punkte).
3. Nummer des Pull-Requests in STATUS.md eintragen und nachschieben.
4. Anhalten und Link plus Prüfpunkte melden. **Ohne ausdrückliche Freigabe wird nicht zusammengeführt.**

**Wenn Torsten freigibt („passt, zusammenführen“ oder Ähnliches):**
1. Prüfen, ob der Pull-Request offen und konfliktfrei ist. Sonst anhalten und melden.
2. Zusammenführen mit `gh pr merge --delete-branch`, mit derselben Methode wie bei den bisherigen Pull-Requests **[Vorschlag: an der Historie ablesen]**.
3. Lokal: `git switch main`, `git pull --ff-only`, `git fetch --prune`, lokalen Task-Zweig löschen.
4. Kurz melden: `git status`, die letzten drei Commits auf `main`, der nächste Schritt aus STATUS.md.

**Grenzen [Vorschlag]:** Kein Force-Push, kein direkter Commit auf `main`. Bei Konflikten, fehlgeschlagenen Prüfungen auf GitHub oder einem unerwartet veränderten `main` hält Claude Code an und fragt nach. Kein zusätzliches Skript, weil die Befehle kurz sind und auf Windows und dem zweiten Rechner gleich laufen.

**Voraussetzung (Torsten, einmalig je Rechner):** `gh` installieren und mit `gh auth login` anmelden. Zugangsdaten nie in den Chat oder ins Repository.

## Teil 2: Paket für den Webspace

**Aufruf:** `php tools/package.php` **[Vorschlag: PHP, weil es auf beiden Rechnern schon läuft]**.

**Ergebnis:** `dist/bvbb-wettkampf-<Version>/` und daneben dieselbe Struktur als ZIP. Die Version kommt aus `src/Version.php`. `dist/` steht in `.gitignore`.

```
bvbb-wettkampf-1.0.1/
  webseiten/      Inhalt von public/ (mit .htaccess, .user.ini, vendor/)
  bvbb-app/       src/, migrations/, bin/, .htaccess aus dem Hauptordner, config.example.php
  HOCHLADEN.txt   Version, Commit, Datum, Liste aller Migrationen, Dateiliste
```

**Nie im Paket:** `config.php`, `sessions/`, `.htpasswd`, `tests/`, `tools/`, `docs/`, `.git`. Damit kann ein Hochladen die Konfiguration und die Sitzungen auf dem Server nicht überschreiben.

**Schutz [Vorschlag]:** Das Paket entsteht nur aus einem sauberen Stand auf `main` (keine offenen Änderungen), sonst bricht das Skript mit einer Meldung ab. So passt das Paket immer zu einem Commit. Steht `config.php` versehentlich in einem der Quellordner, bricht es ebenfalls ab.

**HOCHLADEN.txt** erklärt in wenigen Zeilen: beide Ordner auf die gleichnamigen Ordner im Webspace legen, danach in phpMyAdmin `schema_migrations` mit der Migrationsliste vergleichen und fehlende Dateien importieren, dann `/api/health` aufrufen. Die Dateiliste hilft, Dateien zu finden, die auf dem Server liegen, aber nicht mehr gebraucht werden (Hochladen löscht nichts).

**Test:** Ein Test baut das Paket in einen temporären Ordner und prüft, dass `webseiten/index.php`, `bvbb-app/src/Version.php` und alle Migrationen enthalten sind und dass `config.php` und `sessions/` fehlen.

**README.md:** Der Abschnitt zur Auslieferung beschreibt künftig das Paket statt der Ordnerauswahl.

## Nicht-Ziele

- Kein automatisches Hochladen auf den Webspace (kein SSH, Zugangsdaten bleiben bei Torsten).
- Kein automatisches Einspielen von Migrationen.
- Weg B kommt später, die Regel in `CLAUDE.md` lässt sich dann um einen Absatz erweitern.

## Dem Entwickler überlassen

Genaue Texte der Meldungen, Aufbau von `tools/package.php`. Falls `ZipArchive` auf einem Rechner fehlt: Ordner trotzdem bauen und darauf hinweisen, statt abzubrechen.
