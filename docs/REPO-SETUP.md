# Repository einrichten (gehostet, privat)

Der gemeinsame Stand des Projekts liegt in einem **privaten Repository** bei einem Git-Anbieter, zum Beispiel GitHub oder GitLab. Beide Anbieter sitzen in den USA. Im Repository liegen nur Code, Dokumente und erfundene Testdaten, niemals echte Personendaten. Das sichert der Schutz in `tools/git-hooks/pre-commit` ab. EU-Anbieter gibt es ebenfalls, deren aktuelle Bedingungen sind hier nicht geprüft.

## Einmalig: Repository anlegen (auf dem ersten Rechner)

1. Beim Anbieter ein Konto mit **Zwei-Faktor-Anmeldung** anlegen oder das vorhandene absichern.
2. Neues Repository erstellen: **privat**, ohne README, ohne `.gitignore`, ohne Lizenz (alles liegt schon im Paket).
3. Im entpackten Projektordner (dem mit `CLAUDE.md`):

       git init -b main
       git config core.hooksPath tools/git-hooks
       git add .
       git commit -m "chore: initial handover package"
       git remote add origin <ADRESSE-DES-REPOSITORYS>
       git push -u origin main

   Beim ersten Push meldet sich ein Browserfenster zur Anmeldung (Git Credential Manager) oder du nutzt einen SSH-Schlüssel nach der Anleitung des Anbieters.
4. Vorher die Dokumente nach `docs/` exportieren (siehe `docs/README.md`).

## Auf jedem weiteren Rechner (zum Beispiel dem MacBook)

       git clone <ADRESSE-DES-REPOSITORYS> bvbb-wettkampf-tools
       cd bvbb-wettkampf-tools
       git config core.hooksPath tools/git-hooks

Danach `tools/dev-env/env.example` nach `tools/dev-env/.env` kopieren (mit `dbname=bvbb_test_mac` auf dem Mac) und die Umgebung wie in `tools/dev-env/README.md` prüfen.

## Täglich

       git pull                      # zu Beginn der Sitzung
       git switch -c task-3-age-class   # ein Zweig pro Aufgabe
       ...                           # arbeiten, committen
       git push -u origin task-3-age-class   # am Ende der Sitzung

Zusammengeführt wird nach grünen Tests und Review. Optional: den Hauptzweig `main` beim Anbieter schützen, sodass nur über Pull Requests zusammengeführt werden kann.

## Der Schutz gegen echte Daten

`tools/git-hooks/pre-commit` bricht einen Commit ab, wenn eine Datei gestaged ist, die nach echten Daten oder Zugangsdaten aussieht: `config.php`, `.env`, Excel- und CSV-Dateien außerhalb von `testdata/`, Dateien mit `Stammdaten_` oder `Mitglieder` im Namen und Datenbank-Dumps. Er ist ein Netz gegen Versehen, keine Garantie. Prüfe vor jedem Commit trotzdem `git status`, und umgehe den Schutz nie mit `--no-verify`.

Sind doch einmal echte Daten oder Passwörter in einen Commit gelangt, nicht einfach in einem neuen Commit löschen. Sie bleiben im Verlauf. Dann sofort melden, Passwörter ändern und den Verlauf bereinigen.
