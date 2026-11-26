# Kaderliste rund machen – kurzer Entwurf

Stand: 24.11.2026, von Torsten freigegeben

## Warum

Die Kaderliste läuft als Datenbank im Netz. Künftig wird sie nur noch dort gepflegt. Der Excel-Import wird nur noch einmal gebraucht, für die Übernahme der alten Liste. Damit die Liste im Alltag gut zu bedienen ist, wird sie lesbarer, eindeutiger und lässt sich sortieren und filtern.

## Ziele

1. **Schrift größer:** Überschriften und Inhalt je eine Stufe größer.
2. **„AK (Liste)“ fällt weg.** Es gibt nur noch die berechnete AK.
3. **Jahrgang aus dem Geburtstag (Variante A):** Ist ein Geburtstag eingetragen, setzt der Server den Jahrgang daraus, und das Feld ist gesperrt. Ohne Geburtstag kann der Jahrgang von Hand eingetragen werden.
4. **Kaderstatus NK1** kommt hinzu. Reihenfolge in Auswahl und Sortierung: NK1, NK2, LK, SK.
5. **Sortieren und Filtern (Weg 1, Filterleiste):** Ein Klick auf eine Spaltenüberschrift sortiert, ein zweiter Klick dreht die Reihenfolge um. Die Filterleiste über der Tabelle bietet Suche, AK, Jahrgang, Kaderstatus, Verein, m/w und Status.
6. **„JG (Datum)“ heißt künftig „Geburtstag“.**

## Nicht Teil dieser Änderung

- Filter in den Spaltenköpfen wie in Excel (Weg 2, verworfen: auf dem iPad schwer zu bedienen und mehr Aufwand)
- Filtern oder Sortieren auf dem Server, gespeicherte Filteransichten
- Änderungen an Rollen und Rechten
- Rückimport eines Exports (der Import wird nur noch einmal gebraucht)

## Was nicht passieren darf

- Ein Trainer sieht über Filter, Sortierung oder Export Felder, die er sonst nicht sieht, zum Beispiel den Geburtstag.
- Jahrgang und Geburtstag widersprechen sich.

## Wege

- **Weg 1, Filterleiste über der Tabelle (gewählt):** Auswahllisten mit den Werten, die in der Liste vorkommen, und eine Zählung „37 von 84 Spielern“. Gut auf dem iPad zu bedienen.
- **Weg 2, Autofilter in jeder Spaltenüberschrift:** Fühlt sich wie Excel an, aber die Spaltenköpfe werden eng und sind auf dem iPad schwer zu treffen. Deutlich mehr Aufwand.

Gewählt hat Torsten am 24.11.2026 Weg 1.

## Festlegungen

### Schrift
- Grundschrift und Überschriften in `public/css/app.css` je eine Stufe größer. Die heutigen Werte dort sind der Ausgangspunkt, die Größe des Inhalts wächst um etwa 12 bis 15 %. *(meine Festlegung)*
- Prüfen, dass die Tabelle auf dem iPad quer noch waagerecht scrollt und nichts überlappt.

### AK (Liste)
- `list_ak` verschwindet aus der Schnittstelle (Feldgruppe `player.roster`), aus der Oberfläche, aus dem Bearbeiten und aus dem Export. Die Spalte in der Datenbank bleibt mit ihren Werten erhalten. Es gibt keine Migration, die sie löscht. *(meine Festlegung, damit nichts verloren geht)*
- Die AK wird nur noch aus dem Jahrgang berechnet. Ohne Jahrgang bleibt die AK leer. Die Datenprüfung meldet dann `missing_birth_year`, und der Spieler erscheint im Filter unter „(leer)“. *(meine Festlegung: eine unsichtbare AK aus der Liste wäre verwirrend)*
- Die Befunde `ak_mismatch` und `bad_list_ak` entfallen. `training_site_looks_like_ak` bleibt.
- Der Import liest die Spalte AK weiter ein, damit die Prüfung der Spalten A bis V unverändert bleibt. Gespeichert wird der Wert aber nicht mehr.

### Jahrgang und Geburtstag
- Beim Speichern (Schnittstelle und Import) gilt: Ist nach dem Speichern ein `birth_date` gesetzt, wird `birth_year` immer auf dessen Jahr gesetzt.
- Wird `birth_year` mitgeschickt, obwohl ein `birth_date` gesetzt ist oder mitgeschickt wird, und weicht es von dessen Jahr ab, antwortet der Server mit 422 und `fields: ["birth_year"]`. Ein übereinstimmender Wert wird angenommen. *(meine Festlegung)*
- Wird der Geburtstag geleert, bleibt der Jahrgang stehen und lässt sich danach von Hand ändern. *(meine Festlegung)*
- Das Leeren der Spalte JG beim Import leert wie bisher auch `birth_year` (siehe STATUS.md, Task 9).
- In der Oberfläche ist das Feld Jahrgang beim Bearbeiten gesperrt, solange ein Geburtstag eingetragen ist. Ein kurzer Hinweis erklärt das: „wird aus dem Geburtstag übernommen“.
- Eine Migration gleicht die vorhandenen Daten einmal an: `birth_year` = Jahr von `birth_date`, wo ein Datum gesetzt ist. Diese Migration schreibt keinen Eintrag ins Änderungsprotokoll, sie wird im STATUS.md vermerkt.
- Das Protokoll nennt `birth_year` als geändertes Feld, wenn der Server es ableitet und sich der Wert dabei ändert.
- Rechte bleiben wie sie sind: `birth_date` gehört zu `player.private`, `birth_year` zu `player.roster`. Trainer sehen den Jahrgang, aber nie den Geburtstag.

### Kaderstatus NK1
- Die Auswahlliste enthält NK1, NK2, LK, SK. Gibt es auf dem Server eine Liste erlaubter Werte, kommt NK1 dort ebenfalls hinzu.
- Sortiert wird in der fachlichen Reihenfolge NK1, NK2, LK, SK und nicht alphabetisch. Leere Werte stehen am Ende.
- Offen (blockiert die Umsetzung nicht): die ausgeschriebene Bezeichnung von NK1 für die Begriffsliste im Projekt.

### Spaltenname
- „JG (Datum)“ heißt in Tabelle, Bearbeiten und Export „Geburtstag“, angezeigt als `TT.MM.JJJJ`. Der Spaltenkopf „JG“ im Import bleibt, weil er aus der alten Excel-Liste kommt.

### Sortieren
- Sortiert wird im Browser über die schon geladene Liste. Standard ist Name, dann Vorname.
- Ein Pfeil (▲ ▼) zeigt die Sortierung an. Die Spaltenköpfe sind Schaltflächen und mit der Tastatur bedienbar (`aria-sort`).
- Texte werden nach deutscher Schreibweise verglichen (`localeCompare` mit `de`, Umlaute richtig einsortiert). Die AK sortiert in der Reihenfolge U09 bis O19, der Kaderstatus wie oben. Leere Werte stehen in beiden Richtungen am Ende.

### Filterleiste
- Felder: Suche (Name oder Vorname, Teiltext, ohne Beachtung von Groß- und Kleinschreibung), AK, Jahrgang, Kaderstatus, Verein, m/w, Status (aktiv, passiv, alle).
- Jede Auswahlliste zeigt nur die Werte, die in der geladenen Liste vorkommen, dazu „alle“ und, falls vorhanden, „(leer)“. Mit „(leer)“ findet man zum Beispiel Spieler ohne Jahrgang.
- Filter wirken zusammen (und). Eine Zählung zeigt „37 von 84 Spielern“. „Filter zurücksetzen“ stellt alles auf den Standard zurück.
- Standard für Status ist „aktiv“ (von Torsten bestätigt am 24.11.2026).
- Ein Filter erscheint nur, wenn die Rolle die zugehörige Spalte sieht. Basis sieht also nur Suche und Verein.
- Die Filter bleiben erhalten, solange die Seite offen ist, auch nach dem Speichern eines Spielers. Sie werden nicht dauerhaft gespeichert. *(meine Festlegung)*

### Export
- Der Export enthält genau die sichtbaren Spalten, in der angezeigten Reihenfolge, mit den gefilterten Zeilen und der aktuellen Sortierung. Er ist nicht mehr als Import gedacht.

## Tests (Hinweis für die Umsetzung)
- PHP: `birth_year` wird aus `birth_date` abgeleitet (Anlegen, Ändern, Import). Ein abweichendes `birth_year` ergibt 422. Ohne Datum ist `birth_year` frei. `list_ak` fehlt in den Antworten, und ein Schreibversuch ergibt 403. NK1 wird angenommen. Die Befunde `ak_mismatch` und `bad_list_ak` kommen nicht mehr vor. Die AK ist ohne Jahrgang leer.
- Rauchtest (Playwright) und `tests/ui-checklist.md`: Sortieren, jeder Filter, „(leer)“, Zurücksetzen, Zählung, Export der gefilterten Liste, gesperrter Jahrgang, Trainer ohne Geburtstag-Filter.

## Für die Umsetzung offen
- Genaue Schriftgrößen (siehe oben), Gestaltung der Filterleiste im bestehenden Stil
- Ob es auf dem Server schon eine Liste erlaubter Werte für den Kaderstatus gibt
