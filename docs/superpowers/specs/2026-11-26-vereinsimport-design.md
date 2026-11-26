# Vereinsimport aus nuLiga – Entwurf

Stand: 26.11.2026, von Torsten freigegeben. Gehört zum Plan `docs/superpowers/plans/2026-11-26-vereinsimport.md` (Task 9b). Die Festlegungen stehen im Plan im Abschnitt „Festlegungen“.

Quelle: Projektdokument `beispieldaten/README.md` (claude.ai-Projekt „BVBB-Wettkampf-Tools“). Die echte Exportdatei liegt nur im Projekt und kommt nicht ins Repository. Testdaten werden erfunden.

## Grundlage: nuLiga-Export `vereine-stammdaten-2026-11-26.csv`

- **Quelle:** nuLiga, Export „Vereine (Stammdaten und Adressen)“, Stand 26.11.2026, von Torsten
- **Originalformat:** CSV, Trennzeichen `;`, Zeichensatz **Windows-1252** (nicht UTF-8), Zeilenende CRLF, 48 Spalten, 107 Vereine, am Ende eine Leerzeile. Ein Zeichen ist schon im Export verloren gegangen: „Vorspiel ? Queerer Sportverein Berlin“ (vermutlich ein Gedankenstrich).
- **Im Projekt abgelegt:** als UTF-8 mit LF umgewandelt. Torsten hat die Adressspalten geleert. Claude hat diese 18 Spalten ganz entfernt: `Gläubiger-ID`, alle `Bank…`-Spalten (BLZ, BIC, Name, Kontonummer, IBAN, Kontoinhaber), `Zahlungsart`, alle `Adresse…`-Spalten. Übrig sind 30 Spalten.
- **Wofür:** Stammdaten der Vereine und Grundlage für die Vereinszuordnung (Task 9b Vereinsimport, Matching von Vereinsnamen aus Kaderliste, Turnieren und Spond).

**Wichtige Spalten**

| Spalte | Inhalt | Beobachtung |
| --- | --- | --- |
| `VereinNr` | Vereinsnummer | immer gefüllt, eindeutig. Mit führender Null (`01`, `0815`), also als Text behandeln. Steckt in der `DBVNr` |
| `DBVNr` | Nummer beim DBV, **Mitgliedsnummer** (`clubs.member_no`) | `08-` plus vierstellige VereinNr, bei 10 Vereinen leer (Hobbyliga, Test- und Sondervereine) |
| `LSBNr` | Nummer beim Landessportbund | 13 leer, meist wie DBVNr, teils abweichend (`13588`, `10815`, `61006`) |
| `DebitorNr` | Buchhaltung | 6 leer, einmal doppelt (`10086` bei Glienicke alt und neu) |
| `VereinName` | Name | eindeutig, Umlaute und ß, einzelne Tippfehler („Indonesicher“) |
| `VereinKurzName` | kürzere Form des Namens | eindeutig, 4 leer. Meist wie der Name, teils mit Zusatz „(Berlin)“ oder „(Brandenburg)“ |
| `VereinDruckname` | sehr kurzes Kürzel | eindeutig, aber bei 13 aktiven Vereinen leer. Teils mit Apostroph („H'dorf“, „W'see“) |
| `VereinSortierName` | | nur einmal gefüllt („svbb“) |
| `Status` | `aktiv` oder `Archiv` | 85 aktiv, 22 Archiv. Archiv hat meist ein `Austrittsdatum` |
| `Bemerkungen` | Langname mit „e.V.“, Registergericht, Geschichte | enthält frühere Namen und frühere Vereinsnummern |

**Besonderheiten für den Import**

- **Kein Bundesland.** `Region` ist überall „Berlin-Brandenburg“. BER oder BRA muss wie geplant in der App gepflegt werden. Im Originalexport stehen Postleitzahlen. Damit könnte die App später ein Bundesland vorschlagen, das jemand bestätigt (siehe Plan Etappe 1, „Vereinsstammdaten“).
- **Vereinsnummern werden wiederverwendet.** Laut `Bemerkungen` lief unter Nr. 35 früher „TSV Wittenau“ (heute BC Matchpoint Berlin), unter Nr. 66 früher „BSG Pneumant Fürstenwalde“ (heute SV Dallgow) und unter Nr. 79 früher „KSC Strausberg“ (heute BSV Werder). KSC Strausberg hat heute die Nr. 510. Ein Import, der nur über die Nummer abgleicht, würde so einen Verein still umbenennen. Ein deutlicher Namenswechsel bei gleicher Nummer sollte in der Vorschau auffallen.
- **Neue Nummer für denselben Verein:** SV Glienicke/Nordbahn steht als Nr. 523 „(ALT)“ im Archiv und als Nr. 86 aktiv, mit derselben DebitorNr.
- **Sonderfälle:** „Test Verein CP“ (Nr. 0815, Archiv), „SV Spielberechtigung e.V.“ (Nr. 9999, als Verband markiert, aktiv mit Austrittsdatum), Hobbyliga-Vereine ab Nr. 501 (meist Archiv, teils ohne DBVNr) und eine Grundschule als Mitglied (Nr. 84).
- **Ja/Nein-Werte uneinheitlich:** teils `0`/`1`, teils `ja`/`nein`, teils `Nein`.

**Geklärt am 26.11.2026 (Torsten), gilt für Task 9b Vereinsimport**

1. **Mitgliedsnummer ist die `DBVNr`** (`clubs.member_no`, zum Beispiel `08-0040`). Die `VereinNr` steckt immer darin. Geprüft an dieser Datei: Wo eine `DBVNr` steht, ist sie ausnahmslos `08-` plus die vierstellig aufgefüllte `VereinNr`. Ohne `DBVNr` sind nur 10 Zeilen, alle im Archiv außer Nr. 9999.
   - *Vorschlag Claude:* Fehlt die `DBVNr`, bildet der Import sie aus der `VereinNr` nach derselben Regel (`501` ergibt `08-0501`) und zeigt das in der Vorschau an. Weicht eine vorhandene `DBVNr` von der Regel ab, meldet die Vorschau das, statt zu raten.
   - *Vorschlag Claude:* „SV Spielberechtigung e.V.“ (Nr. 9999, als Verband markiert) und „Test Verein CP“ (Nr. 0815) werden übersprungen, mit Grund in der Vorschau.
2. **Kürzel nicht aus nuLiga übernehmen.** Kurzname und Druckname sind in nuLiga nicht durchgehend richtig gepflegt und dienen nur als Ergänzung. Das Kürzel (`clubs.short`) wird in der App gepflegt. Der Import setzt oder überschreibt es nie, Abweichung vom Plan Task 9b („ein Update ändert `name`, `short` und `active`“).
   - *Vorschlag Claude:* `VereinKurzName` und `VereinDruckname` kommen als Aliasnamen hinzu, wenn sie sich vom Namen unterscheiden und keinem anderen Verein gehören. Das verbessert das Matching von Schreibweisen aus Kaderliste, Turnieren und Spond. Vorhandene, von Hand gepflegte Aliasnamen bleiben.
3. **Archiv-Vereine werden mit importiert, als inaktiv** (`active = false`, `Status` = `Archiv`). Nach der Regel aus Task 8 werden inaktive Vereine Spielern nicht neu zugeordnet. Sie bleiben für alte Turniere und die Vereinshistorie erhalten.

**Hinweis für Claude Code:** Diese Entscheidungen beim Start von Task 9b in `STATUS.md` übernehmen. Testdaten für den Import werden erfunden (`tests/fixtures/nuliga/`), im Aufbau wie dieser Export: Windows-1252, `;`, CRLF, alle 48 Spalten des Originals. Bank- und Adressspalten mit erfundenen Werten gefüllt, damit sich prüfen lässt, dass sie nicht gesendet werden (siehe Plan). Dazu Fälle für fehlende `DBVNr`, wiederverwendete Nummer mit neuem Namen, Archiv, Umlaute und ß sowie Sondervereine.
