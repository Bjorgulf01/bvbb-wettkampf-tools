# Rauchtest der Oberfläche

Automatischer Durchlauf der wichtigsten Punkte aus `tests/ui-checklist.md` in Chromium, Firefox und WebKit (Engine von Safari) mit Playwright in Docker. Nur Entwicklungswerkzeug, kommt nicht auf den Server. Er ersetzt den Durchgang von Hand in Firefox und Safari nicht.

Aus dem Projektordner (Git Bash unter Windows: `MSYS_NO_PATHCONV=1` voranstellen):

```sh
docker build -t bvbb-ui-smoke tools/ui-smoke                     # einmalig, etwa 2 GB
docker compose -f tools/dev-env/docker-compose.dev.yml run --rm php php tools/dev-env/seed-preview.php
docker compose -f tools/dev-env/docker-compose.dev.yml up -d web
docker run --rm --network dev-env_default --ipc=host -v "$PWD:/app:ro" bvbb-ui-smoke python /app/tools/ui-smoke/smoke.py
```

`seed-preview.php` löscht alle Tabellen der Test-Datenbank dieses Rechners. Nicht gleichzeitig mit `tests/run.php` starten. Einzelne Browser: Namen anhängen, zum Beispiel `… smoke.py firefox`. Der erste Browser importiert die Testkaderliste, die weiteren sehen dieselben Daten (Vorschau 0 neu).
