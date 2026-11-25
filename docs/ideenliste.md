# Ideenliste

Ideen für später, die noch nicht eingeplant sind. Je Idee: Stand, worum es geht, warum, nächster Schritt. Wo eine Idee schon in einem anderen Dokument beschrieben ist, steht hier nur die Kurzfassung mit Verweis. Die Einzelheiten bleiben im Quelldokument.

Stand: offen · in Prüfung · eingeplant (mit Verweis auf Plan oder Etappe) · verworfen (mit Grund)

## Übersicht

| Idee | Bereich | Stand | Quelle |
| --- | --- | --- | --- |
| Entwicklungsumgebung: Editor statt nur Terminal | Entwicklung | offen | hier |
| Weg B: automatisch zusammenführen | Entwicklung | offen, vorgemerkt | `claude/entwicklungsablauf-design.md` |
| Turnierabrechnung | Fachlich | eingeplant, Etappe 2 | Prototyp „Wettkampf-Tools“ (Artefakt), `entwurf.md` Abschnitte 2, 7.3 und 13 |
| NADA und Sportmedizin | Fachlich | offen, später | `entwurf.md` Abschnitt 7.5 |
| Bericht je Turnier | Fachlich | offen, später | `entwurf.md` Abschnitt 7.5 |
| Meldegeldweiterberechnung | Fachlich | offen, Anforderungen fehlen | `entwurf.md` Abschnitt 7.5 |

---

## Entwicklung

### Entwicklungsumgebung: Editor statt nur Terminal

**Stand:** offen · aufgenommen 24.11.2026

**Worum es geht:** Auf beiden Entwicklungsrechnern (Windows und Mac) eine richtige Arbeitsumgebung einrichten, statt Claude Code nur im Terminal laufen zu lassen. Visual Studio und Xcode passen nicht, weil sie für C#/.NET bzw. Apple-Apps gemacht sind und bei PHP und MySQL nichts bringen.

**Kandidaten:**
- **Visual Studio Code (Favorit):** kostenlos, läuft gleich auf Windows und Mac. Erweiterungen: Claude Code für VS Code (Änderungen als Vorher/Nachher-Vergleich vor dem Übernehmen), PHP Intelephense (Sprung zur Definition, Vorschläge, Fehler beim Tippen), die eingebaute Git-Ansicht (geänderte Dateien und Diffs) und optional PHP Debug (Xdebug).
- **PhpStorm (JetBrains):** speziell für PHP, mit Datenbankzugriff direkt im Editor statt über phpMyAdmin, Claude Code lässt sich ebenfalls einbinden. Kostet Geld, bei gemeinnützigen Projekten eventuell Lizenz möglich. Schwerer zu lernen.

**Warum:** Passt zum Entwicklungsablauf (Weg A), bei dem Torsten am Ende nur noch prüft. Dateien und Änderungen sind im Editor besser zu überblicken. Was heute im Terminal läuft (`gh`, `php tools/package.php`), bleibt gleich und läuft im eingebauten Terminal weiter.

**Nächster Schritt:** Kurze Einrichtungsanleitung für beide Rechner schreiben (Erweiterungen, Einstellungen, Claude Code verbinden). Danach auf einem Rechner ausprobieren.

### Weg B: automatisch zusammenführen

**Stand:** offen, vorgemerkt · Quelle: `claude/entwicklungsablauf-design.md` (Abschnitte „Ansätze“ und „Nicht-Ziele“)

**Worum es geht:** Claude Code führt einen Pull-Request ohne ausdrückliche Freigabe selbst zusammen, wenn Tests und Review grün sind. Heute gilt Weg A: Torsten prüft und gibt frei.

**Warum später:** Erst mehr Erfahrung mit Weg A sammeln.

**Nächster Schritt:** Nach einigen Tasks mit Weg A entscheiden. Dann die Regel „Abschluss eines Tasks“ in `CLAUDE.md` um einen Absatz erweitern.

## Fachlich

### Turnierabrechnung

**Stand:** eingeplant, Etappe 2 · Quelle: Prototyp „Wettkampf-Tools“ (Artefakt auf claude.ai, Version 1.5.0 laut Entwurf), `entwurf.md` Abschnitte 2, 7.3 und 13

**Worum es geht:** Damit hat alles angefangen. Der Prototyp ist eine einzelne HTML-Seite mit Turnierabrechnung und druckfertigem A4-Dokument, dazu Kaderliste, Excel-Import und -Export, berechneter Altersklasse und Datenprüfung. Er speichert nur im Browser oder als heruntergeladene Datei und ist Vorlage für Oberfläche und Rechenlogik, nicht Produktivcode.

**In der App (Etappe 2):** Planung (Termin, Athleten, Eigenanteil, Betreuer), Abrechnung mit Ausgaben, Einnahmen und Zahler, Aufteilung Berlin/Brandenburg, Erstattung je Trainer, Druckansicht im bisherigen Layout und Prüfablauf, auf Basis der zentralen Kaderliste. Ab „zur Prüfung“ werden die Spielerdaten eingefroren.

**Abnahme:** Das Beispiel Friedrichsdorf muss dieselben Beträge ergeben wie heute (Ausgaben 1.458,61 €, Einnahmen 843,50 €, Zuschuss 615,11 €, Berlin 546,76 €, Brandenburg 68,35 €, Erstattung 5,20 €).

**Nächster Schritt:** Nach Abschluss von Etappe 1 den Umsetzungsplan für Etappe 2 schreiben. Dabei Rechenlogik und Druckansicht aus dem Prototyp übernehmen.

### NADA und Sportmedizin

**Stand:** offen, später · Quelle: `entwurf.md` Abschnitt 7.5

**Kurzfassung:** Je Athlet und Kalenderjahr das Datum der NADA-Schulung, der letzten sportmedizinischen Untersuchung und der nächste Termin. Daraus folgt der Stand erledigt, terminiert oder offen. Trainer sehen nur die Ampel. Es werden keine Befunde gespeichert. Datenmodell vorgesehen als Tabellen `nada` und `sportmedizin`.

### Bericht je Turnier

**Stand:** offen, später · Quelle: `entwurf.md` Abschnitt 7.5

**Kurzfassung:** Ergebnisprotokoll und fünf bis sechs Zeilen zur Entwicklung der Athleten, später eventuell mit KI-Zusammenfassung. Setzt eine datenschutzrechtliche Klärung voraus. Im Datenmodell ist ein Berichtstext am Turnier vorgesehen.

**Nächster Schritt:** Datenschutzfrage klären, bevor spezifiziert wird.

### Meldegeldweiterberechnung

**Stand:** offen · Quelle: `entwurf.md` Abschnitt 7.5

**Kurzfassung:** Anforderungen fehlen noch.

**Nächster Schritt:** Klären, wer Meldegeld an wen weiterberechnet und auf welcher Grundlage.
