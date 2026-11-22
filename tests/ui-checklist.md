# Checkliste Oberfläche (Task 11)

Durchführung mit der lokalen Vorschau und erfundenen Daten:

1. `docker compose -f tools/dev-env/docker-compose.dev.yml run --rm php php tools/dev-env/seed-preview.php` (löscht die Test-Datenbank dieses Rechners und legt erfundene Vereine an)
2. `docker compose -f tools/dev-env/docker-compose.dev.yml up web`, dann http://localhost:8080
3. Als `Ada Verwaltung` (admin) `testdata/Testkaderliste_fiktiv.xlsx` importieren, danach die Rollen durchgehen.

Jeder Punkt in Firefox und Safari. Für „Netzwerk“ die Entwicklerwerkzeuge öffnen (Antwort von `GET /api/players`).

## Anmeldung und Sitzung

| # | Schritt | Erwartet | Firefox | Safari |
|---|---|---|---|---|
| A1 | Seite ohne Sitzung öffnen | Anmeldeseite mit den Testpersonen und ihren Rollen, Hinweis „Testanmeldung“ | | |
| A2 | Testanmeldung ausgeschaltet (`test_auth` false) | keine Personenliste, nur der Hinweis, dass die Anmeldung über den Anmeldedienst folgt | | |
| A3 | Als Person anmelden | Startseite mit Name und Rollen oben rechts | | |
| A4 | Abmelden | zurück zur Anmeldeseite; „Zurück“ im Browser zeigt keine Daten | | |
| A5 | Sitzung im Hintergrund beenden (zweiter Tab abmelden), dann im ersten Tab etwas laden | Anmeldeseite statt Fehlermeldung | | |
| A6 | Als `ohne-rolle` anmelden | Startseite ohne aktive Kachel, Hinweis „Keine Rolle zugewiesen“ | | |

## Startseite

| # | Schritt | Erwartet | Firefox | Safari |
|---|---|---|---|---|
| S1 | admin | Kaderliste und Verwaltung aktiv; Turniere, Jahresübersicht, NADA/Sportmedizin gesperrt mit „folgt“ | | |
| S2 | `trainer` (coach), `basis`, `pruefer`, `nada` | Kaderliste aktiv, Verwaltung nur für `pruefer` (nur Protokoll) | | |

## Kaderliste

| # | Schritt | Erwartet | Firefox | Safari |
|---|---|---|---|---|
| K1 | admin oder `kaderpflege` | alle Spalten (auch Kontakt, Schule, SpoMed, Anti Doping), AK und Ampel; Schaltflächen Neu, Bearbeiten, Import, Datenprüfung, Export | | |
| K2 | `trainer` (coach) | in Tabelle **und** Netzwerk-Antwort kein Kontaktfeld (keine E-Mail, kein Telefon, keine Schule, kein SpoMed-/Anti-Doping-Text), nur Ampel; keine Schaltflächen Bearbeiten, Neu, Import, Datenprüfung | | |
| K3 | `basis` | nur Name, Vorname, Verein, Vereinskürzel; keine AK, keine Ampel | | |
| K4 | `trainer-kaderpflege` (zwei Rollen) | wie Kaderpflege | | |
| K5 | Spalten | Reihenfolge wie in der Kaderliste; Spalten entstehen aus der Antwort, nicht aus einer festen Liste | | |
| K6 | Suche und Filter „nur aktive“ | Liste filtert ohne neue Anfrage; Zahl der angezeigten Spieler stimmt | | |
| K7 | Jahr ändern (zum Beispiel 2028) | AK wird neu berechnet (Jahrgang 2015: 2026 U13, 2028 U15) | | |
| K8 | Bearbeiten: Feld ändern, speichern | Wert bleibt nach dem Neuladen; im Protokoll steht nur der Feldname | | |
| K9 | Bearbeiten: SpoMed oder Anti Doping | deutlicher Hinweis „Nur Datum und Status eintragen, keine Befunde oder medizinischen Angaben.“ | | |
| K10 | Bearbeiten: ungültiger Wert (Nummer „abc“) | Meldung am Feld, nichts gespeichert | | |
| K11 | Neuer Spieler ohne Nachname | Meldung, nichts gespeichert | | |
| K12 | Umlaute und ß (Müßig, Grünwald) | korrekt in Tabelle, Bearbeiten und Export | | |

## Import

| # | Schritt | Erwartet | Firefox | Safari |
|---|---|---|---|---|
| I1 | `Testkaderliste_fiktiv.xlsx` wählen | Vorschau ohne Speichern: 59 neu, 0 geändert, übersprungen „Lübbe, Arvid“ (doppelt in der Datei) und die Platzhalterzeile; Befunde wie im Blatt „Hinweise“ (ohne `health_note_unclear`) | | |
| I2 | Hinweis im Import | „Nur Datum und Status eintragen, keine Befunde oder medizinischen Angaben.“ | | |
| I3 | „Übernehmen“ | Liste zeigt 59 Spieler; Jahrgänge aus der Spalte JG stimmen (Aberle, Britta: 2005) | | |
| I4 | dieselbe Datei erneut, Vorschau | 0 neu, 59 geändert, nichts doppelt angelegt | | |
| I5 | Datei ohne Kopfzeile „Name“/„Vorname“, mit fehlender oder zusätzlicher Spalte (A bis V), oder keine Excel-Datei | verständliche Meldung mit der abweichenden Spalte, keine Anfrage an den Server | | |
| I6 | Vorschau abbrechen | nichts gespeichert | | |

## Datenprüfung und Export

| # | Schritt | Erwartet | Firefox | Safari |
|---|---|---|---|---|
| D1 | Datenprüfung öffnen | Befunde mit deutschem Text, Name und Detail (zum Beispiel „Liste U11, berechnet U13“) | | |
| D2 | Klick auf einen Befund | springt zum Spieler und hebt die Zeile hervor | | |
| E1 | Export | `.xlsx` mit den angezeigten Spalten und deutschen Überschriften; coach-Export enthält keine Kontaktfelder | | |

## Vereine

| # | Schritt | Erwartet | Firefox | Safari |
|---|---|---|---|---|
| V1 | Reiter „Vereine“ als `trainer` | Liste nur lesend | | |
| V2 | als `kaderpflege`: Bundesland, Kürzel, Aliasnamen ändern, Verein anlegen, auf inaktiv setzen | gespeichert; kein Löschen möglich | | |
| V3 | Alias „Neuer Verein e.V.“ beim passenden Verein eintragen | Befund `unknown_club` für Lindemann, Yara verschwindet | | |

## Verwaltung

| # | Schritt | Erwartet | Firefox | Safari |
|---|---|---|---|---|
| U1 | admin: Rollen einer Person ändern | gespeichert, Eintrag „roles“ im Protokoll | | |
| U2 | admin: sich selbst als letztem admin die Rolle nehmen | Meldung „Der letzte aktive admin …“, nichts geändert | | |
| U3 | admin: Person deaktivieren | Person kann sich nicht mehr anmelden („inaktiv“) | | |
| U4 | `pruefer` | nur Protokoll, keine Benutzerliste | | |
| U5 | Protokoll | Zeit als `TT.MM.JJJJ HH:MM` in deutscher Zeit, wer, was, Objekt, nur Feldnamen | | |

## Allgemein

| # | Schritt | Erwartet | Firefox | Safari |
|---|---|---|---|---|
| G1 | Konsole der Entwicklerwerkzeuge | keine Fehler, keine Verstöße gegen die Content-Security-Policy | | |
| G2 | Netzwerk | keine Anfrage an fremde Server | | |
| G3 | dunkles Farbschema des Systems | lesbar | | |
| G4 | schmales Fenster (Telefon) | Tabelle scrollt waagerecht, Bedienelemente erreichbar | | |
