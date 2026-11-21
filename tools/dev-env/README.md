# Entwicklungsumgebung

**Ziel:** Auf dem Windows-PC und dem MacBook gleich arbeiten, mit derselben Datenbank-Software wie beim Hosting. PHP 8.4 läuft in Docker auf dem jeweiligen Rechner, die Test-Datenbank (MariaDB 10.11) läuft auf dem NAS.

> Diese Dateien wurden ohne Docker und ohne PHP erstellt und sind **noch nicht ausgeführt worden**. Der erste Lauf ist der Test. Fehlermeldungen einfach an Claude Code weitergeben.

## Was gebraucht wird

| Was | Wo | Hinweis |
|---|---|---|
| Git | PC und Mac | Git für Windows ist für Claude Code optional, aber nützlich |
| Docker Desktop | PC und Mac | Windows mit WSL-2-Backend, Mac passend zum Chip. Lizenzbedingungen für deine Nutzung prüfen |
| Claude Code | PC und Mac | Windows 10 (ab 1809) oder macOS 13+. Anleitung: https://code.claude.com/docs/en/setup |
| MariaDB 10.11 | NAS (Portainer) | nur Testdaten |

Der Webspace dient später als Test-Umgebung für die Auslieferung (Task 12), nicht für die Entwicklung.

## Eine Datenbank-Software, eine Datenbank pro Rechner

Du testest auf beiden Rechnern gegen **denselben Datenbankserver**, damit Version und Verhalten gleich sind. Jeder Rechner bekommt aber **seine eigene Datenbank** darauf. Der Grund: Jeder Testlauf löscht alle Tabellen seiner Datenbank. Liefen PC und Mac gleichzeitig oder kurz nacheinander gegen dieselbe Datenbank, würden sie sich gegenseitig die Testdaten wegnehmen, und Tests würden scheinbar zufällig fehlschlagen.

## Einrichtung

1. **NAS:** In Portainer *Stacks → Add stack*, Inhalt von `nas-mariadb-stack.yml` im Web-Editor einfügen, unter *Environment variables* `MARIADB_ROOT_PASSWORD` und `MARIADB_PASSWORD` setzen, Stack starten und warten, bis der Container „healthy“ ist. Der Stack legt `bvbb_test_pc` an.
2. **Zweite Datenbank (einmalig):** In Portainer *Containers → bvbb-mariadb-test → Console → /bin/sh*, dann (das Root-Passwort wird abgefragt):

       mariadb -uroot -p -e "CREATE DATABASE bvbb_test_mac CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE bvbb_test_local CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON bvbb_test_mac.* TO 'bvbb_test'@'%'; GRANT ALL ON bvbb_test_local.* TO 'bvbb_test'@'%'; FLUSH PRIVILEGES;"

3. **Netzwerk:** Dem NAS im Router eine feste Adresse geben. Port 3307 nur im Heimnetz erreichbar lassen, **keine Portfreigabe im Router**.
4. **Rechner:** Git und Docker Desktop installieren. Projektordner an einen normalen Ort (zum Beispiel `C:\dev\bvbb-wettkampf-tools`), nicht in OneDrive, iCloud oder auf ein Netzlaufwerk. Zum gemeinsamen Stand siehe `docs/REPO-SETUP.md`.
5. **Zugangsdaten:** `tools/dev-env/env.example` nach `tools/dev-env/.env` kopieren, NAS-Adresse und Passwort eintragen, `dbname` passend zum Rechner (`bvbb_test_pc` oder `bvbb_test_mac`).
6. **Test der Umgebung** (im Projektordner):

       docker compose -f tools/dev-env/docker-compose.dev.yml run --rm php php tools/dev-env/check-env.php

   Erwartet: PHP 8.4, alle Erweiterungen, Verbindung hergestellt, MariaDB 10.11, utf8mb4, Umlaute in Ordnung, „Alles in Ordnung.“
7. **Claude Code starten** im Projektordner (`claude`). Erster Auftrag: „Lies `CLAUDE.md`, `docs/STATUS.md` und die Dokumente in `docs/`. Mach Aufgabe 0, danach Task 1 des Plans.“

## Täglicher Gebrauch

    docker compose -f tools/dev-env/docker-compose.dev.yml run --rm php php tests/run.php
    docker compose -f tools/dev-env/docker-compose.dev.yml up web      # Vorschau: http://localhost:8080

## Unterwegs ohne Zugang zum NAS

Das NAS ist nur im Heimnetz erreichbar. Unterwegs gibt es zwei Wege:
- **Lokale Ersatz-Datenbank:** `docker compose -f tools/dev-env/docker-compose.dev.yml --profile local up -d db-local` starten und in `.env` die auskommentierten Werte für `bvbb_test_local` verwenden. Sie hat dieselbe MariaDB-Version.
- **VPN ins Heimnetz** (zum Beispiel über den Router oder das NAS), falls vorhanden. Das NAS bleibt dabei weiter nicht aus dem Internet erreichbar.

## Sicherheit

- Die Datenbank enthält **nur erfundene Daten**. Der Test-Benutzer hat Rechte nur auf die Test-Datenbanken.
- Niemals die Zugangsdaten der echten Datenbank oder des Webspace in `.env` eintragen. Die Tests löschen alle Tabellen.
- Passwörter gehören nicht in Chat oder Repository. `.env` steht in `.gitignore` und wird vom Commit-Schutz blockiert.

## Grenzen

Die lokale Vorschau läuft mit dem eingebauten PHP-Server. Das Verhalten von Apache und `.htaccess` wird erst auf dem Test-Webspace geprüft (Task 12).
