# Entwurf: Wettkampf-Tools mit zentraler Datenhaltung

*Entwurfsversion 0.1 · 15.11.2026 · zur Freigabe*

Kennzeichnung: **\[Du\]** = so von dir gesagt. **\[Vorschlag\]** = von mir ergänzt oder entschieden, bitte prüfen und im Zweifel streichen.

## 1. Worum es geht

Der BVBB rechnet Turniere bisher in Excel-Dateien ab. In jeder Datei steht ein eigenes Datenblatt mit Spielern und Vereinen, das von Hand nachgepflegt wird. Daneben gibt es eine große, separate Excel-Kaderliste. Beides ist fehleranfällig. Nach dem Turnier prüft der Vizepräsident Leistungssport die Abrechnung. Am Jahresende muss der Verband gegenüber den Sportämtern von Berlin und Brandenburg die Fördersumme mit Turnierkosten belegen und dazu Statistiken liefern.

**Ziel \[Du\]:** Eine zentrale Kaderliste und eine zentrale Betreuerliste sind die einzige Quelle. Die Turnierabrechnung nutzt diese Daten direkt. Mehrere Trainer können pro Turnier abrechnen, und die App zeigt, wer wie viel zurückbekommt. Am Jahresende liefert sie Kosten und Statistik für die Behörden. NADA-Schulung, sportmedizinische Untersuchung und ein Bericht je Turnier kommen später dazu.

Nutzer: 5 bis 7 Personen. Beteiligt sind Trainer und Betreuer, die Kaderpflege, der Vizepräsident Leistungssport als Prüfer und eine Person im Verband für NADA und Sportmedizin.

## 2. Ausgangslage: der Prototyp

Es gibt einen Prototyp als einzelne HTML-Seite (Version 1.5.0, auf claude.ai veröffentlicht). Er hat eine Startseite mit Kacheln, eine Turnierabrechnung mit druckfertigem A4-Dokument, eine Kaderliste mit 22 Spalten, Excel-Import und -Export, eine berechnete Altersklasse und eine Datenprüfung. Er speichert nur im Browser des jeweiligen Geräts oder als heruntergeladene Datei.

Der Prototyp dient als Vorlage für Oberfläche und Rechenlogik, nicht als Produktivcode. Seine Grenzen sind der Anlass für diesen Entwurf: Kein Gerät sieht die Daten des anderen, und es gibt weder Anmeldung noch Rechte.

## 3. Ziele, Nicht-Ziele, Anti-Ziele

### Ziele

1. **Zentrale Kaderliste \[Du\].** Sie ist die einzige Quelle für Spielerdaten und ersetzt die große Excel-Datei. Der Excel-Import bleibt für den Übergang.
2. **Zentrale Betreuerliste \[Du\].** Betreuer werden nur noch ausgewählt, nie von Hand eingetragen.
3. **Turnier mit Planung und Abrechnung \[Du\].** Planung: Termin, Athleten, Eigenanteil je Athlet, Betreuer. Abrechnung: Ausgaben und Einnahmen, Zahler, Aufteilung Berlin/Brandenburg und Erstattung je Trainer.
4. **Mehrere Trainer pro Turnier \[Du\].** Die App zeigt zum Beispiel „Trainer A bekommt 127 € zurück, Trainer B 150 €“. Zahlungen über die Kreditkarte des Landestrainers gelten als bereits bezahlt.
5. **Druckfertige Abrechnung \[Du\].** Sie bleibt im bisherigen Layout, ergänzt um die Erstattung je Trainer.
6. **Jahresübersicht \[Du\].** Sie enthält Kosten je Turnier und Behörde, die Auswahl der Turniere zum Einreichen, den Vergleich mit der Fördersumme und die Statistik männlich/weiblich nach Kaderstatus und Verein.
7. **Rollen \[Du\].** Siehe Abschnitt 6.
8. **Anmeldung über einen vorhandenen Dienst \[Du\].** Niemand bekommt ein weiteres Passwort. Bevorzugt wird die Digitale Geschäftsstelle, Rückfall ist die Nextcloud. Bis das geklärt ist, läuft eine Testanmeldung.
9. **Änderungsprotokoll \[Vorschlag\].** Es zeigt, wer wann was geändert hat.

### Nicht-Ziele im ersten Schritt

- Gleichzeitiges Bearbeiten derselben Abrechnung muss nicht abgesichert werden \[Du\]. Die Trainer stimmen sich ab.
- NADA-Schulung und Sportmedizin kommen nach der Turnierabrechnung. Das Datenmodell wird aber jetzt dafür vorbereitet \[Du\].
- Ergebnisprotokoll und Entwicklungsbericht je Turnier, auch mit KI-Hilfe, kommen später. Das Datenmodell lässt Platz dafür \[Du\].
- Die Meldegeldweiterberechnung ist ein eigenes, späteres Projekt. Dafür fehlt noch eine Vorlage.
- Belege werden nicht in der App hochgeladen. Sie bleiben in der Nextcloud \[Vorschlag\].

### Anti-Ziele (wäre ein Misserfolg, auch wenn alles läuft)

- Ein weiteres Login mit eigenem Passwort für die Nutzer.
- Trainer oder die Basisrolle können Kontaktdaten oder Gesundheitsdaten über die Schnittstelle abrufen, auch wenn die Oberfläche sie nicht anzeigt.
- Befunde oder medizinische Ergebnisse werden gespeichert. Es gibt nur Datum und Status.
- Ein bereits abgerechnetes Turnier ändert sich, weil später ein Spieler den Verein wechselt.
- Die Testanmeldung ist im Echtbetrieb eingeschaltet.
- Echte Daten von Kindern liegen in der Testphase auf dem Server.

## 4. Randbedingungen

- **Hosting:** Der Verband kann die Nextcloud nicht selbst verwalten, ein IT-Dienstleister hostet sie. Die App läuft auf einem eigenen Webhosting mit PHP ab Version 8.1 und MySQL oder MariaDB. Es braucht HTTPS, Serverstandort Deutschland oder EU, einen Vertrag zur Auftragsverarbeitung und tägliche Sicherung.
- **Browser:** Firefox am PC und Safari auf dem iPad müssen funktionieren. Direktes Schreiben in Ordner geht dort nicht, deshalb geht alles über den Server.
- **Datenschutz:** Die Daten betreffen Minderjährige, und NADA und Sportmedizin sind Gesundheitsdaten. Die Löschfristen und das Verzeichnis der Verarbeitungstätigkeiten muss der Verband klären.
- **Zeitbasis:** Alles rechnet in Kalenderjahren. Das gilt für die Altersklasse, die Förderplanung, NADA und Sportmedizin \[Du\].
- **Prüfung:** Bevor echte Daten auf den Server kommen, sieht jemand mit Sicherheitserfahrung die Lösung an. Das ist keine Option, sondern eine Bedingung.
- **Testbarkeit:** Die echte Anmeldung gegen die Digitale Geschäftsstelle lässt sich erst prüfen, wenn der Anbieter einen Zugang freigibt. Die Anbindung an das echte Hosting kann nur der Verband testen.

## 5. Ansätze und Entscheidung

| Ansatz | Kurzbeschreibung | Bewertung |
| --- | --- | --- |
| A: Nextcloud-Dateien per WebDAV | Kaderliste und Turniere sind Dateien in der Nextcloud, die App liest und schreibt sie direkt. | **Verworfen.** Dateien kennen keine Rechte auf einzelne Felder. Trainer sollen bei NADA und Sportmedizin nur eine Ampel sehen, die Verwaltung alle Daten. Außerdem müsste der Hoster die Nextcloud für fremde Adressen öffnen. |
| B: Datenbank der Claude-Plattform | Schnell, ohne eigenen Server. | **Nur als Test mit erfundenen Daten.** Jeder Nutzer bräuchte ein Konto in der Claude-Organisation, und Daten Minderjähriger lägen dort. |
| C: Eigener kleiner Server | PHP und MySQL auf eigenem Webhosting mit eigener Rechteprüfung. Die Belege bleiben in der Nextcloud. | **Gewählt.** Du hast diesen Weg als Plan B genannt und im Gespräch bestätigt. Rechte auf einzelne Felder sind möglich, und die Lösung ist unabhängig vom Nextcloud-Hoster. |

Eine eigene Nextcloud-App wäre eleganter, setzt aber voraus, dass der Hoster Apps und Datenbankänderungen erlaubt. Das ist nach deiner Einschätzung unsicher. Die Anmeldung wird deshalb als austauschbarer Baustein gebaut. So wäre ein späterer Umzug in die Nextcloud möglich.

## 6. Rollen und Rechte

Eine Person kann mehrere Rollen haben \[Vorschlag\]. Die Rechte stehen in einer Tabelle der Datenbank, nicht fest im Programm. Kommt eine Rolle dazu, ändert sich nur diese Tabelle.

**Rollen \[Du, Vorschlag zur Verteilung\]:** Administrator, Kaderpflege, NADA/Sportmedizin, Prüfer (Vizepräsident Leistungssport), Trainer (erweiterte Kadersicht) und Basis (nur einfache Stammdaten).

**L** = lesen, **S** = schreiben (schließt Lesen ein), **–** = kein Zugriff.

| Bereich | Admin | Kader-pflege | NADA/ SpoMed | Prüfer | Trainer | Basis |
| --- | --- | --- | --- | --- | --- | --- |
| Einfache Stammdaten (Name, Verein) | S | S | L | L | L | L |
| Erweiterte Kaderdaten (Jahrgang, AK, m/w, Kaderstatus) | S | S | L | L | L | – |
| Kontaktdaten und Schule | S | S | – | – | – | – |
| NADA/Sportmedizin: Ampel (erledigt, terminiert, offen) | L | L | L | L | L | – |
| NADA/Sportmedizin: Daten und Termine | S | – | S | – | – | – |
| Betreuerliste | S | S | – | L | L | – |
| Turniere, bei denen man Betreuer ist | S | – | – | L | S | – |
| Alle Turniere | S | – | – | L | – | – |
| Prüfstatus setzen, Turniere zum Einreichen markieren | S | – | – | S | – | – |
| Jahresübersicht und Statistik | S | – | – | S | – | – |
| Benutzer und Rollen verwalten | S | – | – | – | – | – |
| Änderungsprotokoll | L | – | – | L | – | – |

Die Server-Schnittstelle liefert nicht erlaubte Felder gar nicht erst aus. Ausblenden in der Oberfläche genügt nicht.

## 7. Funktionsbereiche

### 7.1 Startseite

Kacheln wie im Prototyp, je nach Rolle sichtbar: Turniere, Kaderliste, Jahresübersicht, später NADA/Sportmedizin und Verwaltung.

### 7.2 Kaderliste

- Pflege aller Spalten der bisherigen Excel-Liste, mit Suche und Filter.
- Excel-Import (Übergang) mit Vorschau und Datenprüfung vor dem Übernehmen, außerdem Export.
- **Altersklasse \[Du\]:** Sie wird berechnet aus dem Alter im Kalenderjahr, also Jahr minus Jahrgang. U09, U11, U13, U15, U17, U19 gelten bis unter 9, 11, 13, 15, 17 und 19 Jahren, ab 19 gilt O19. Das Jahr kommt aus dem Turniertermin. Fehlt der Jahrgang, gilt die AK aus der Liste.
- **Vereine:** Eine Vereinsliste ordnet Schreibweisen einem Kürzel und einem Verband (BER oder BRA) zu. Der Verband hängt am Verein.
- **Datenprüfung:** Sie meldet fehlenden Jahrgang, Verein, m/w oder Kaderstatus, abweichende AK, doppelte Namen und Vereine ohne Zuordnung.
- Spieler werden nie gelöscht, sondern auf passiv gesetzt. Sonst verschwinden sie aus alten Turnieren \[Vorschlag\].

### 7.3 Turnier

- **Planung:** Maßnahme, Ort, Termin, Athleten (jeder nur einmal), Betreuer, Eigenanteil je Athlet als eigenes Feld \[Du\].
- **Abrechnung:** Ausgaben mit Datum, Kostenpunkt, Beschreibung, Betrag, Zahler (Kreditkarte oder Betreuer) und Fremdwährung. Einnahmen als Zeilen mit Betrag. Der Eigenanteil kann Einnahmen vorbefüllen \[Vorschlag\].
- **Aufteilung:** Pro Kopf nach Verband auf Berlin und Brandenburg, wie im bisherigen Sheet. Berlin wird auf den Cent gerundet, Brandenburg erhält den Rest.
- **Erstattung je Trainer:** Auslagen eines Betreuers ergeben seine Erstattung. Kreditkartenzahlungen sind bereits bezahlt. Die Summe steht im Ausdruck.
- **Druckansicht:** Layout wie bisher, mit Kopf, Logo, Teilnehmerliste mit Verein, Verband, m/w, Jahrgang, AK und Kaderstatus, Ausgaben, Einnahmen, Aufteilung, Erstattungen und Unterschriftsfeldern.
- **Ablauf \[Vorschlag\]:** Planung, in Abrechnung, zur Prüfung, geprüft. Der Trainer reicht ein, der Prüfer setzt „geprüft“ oder gibt mit Kommentar zurück. Ab „zur Prüfung“ ist die Abrechnung gesperrt.
- **Spielerdaten im Turnier \[Du, Vorschlag zum Zeitpunkt\]:** Beim Abrechnen werden Verein, Verband, Kaderstatus und Jahrgang aus der Kaderliste in das Turnier kopiert und dort eingefroren. Spätere Änderungen betreffen das Turnier nicht. Der Zeitpunkt des Einfrierens steht in Abschnitt 11.

### 7.4 Jahresübersicht

- Kalenderjahr wählen. Je Turnier stehen Kosten, getrennt nach Berlin und Brandenburg, und der Prüfstatus in einer Tabelle.
- Der Prüfer oder Administrator markiert die Turniere, die bei der Behörde eingereicht werden. Die Summe der markierten Turniere steht der Fördersumme je Behörde gegenüber.
- Statistik je Behörde: Zahl der männlichen und weiblichen Kaderathleten, aufgeschlüsselt nach Kaderstatus und Verein, aus den eingefrorenen Turnierdaten. Export als Excel und druckfähige Ansicht.

### 7.5 Später

- **NADA und Sportmedizin:** je Athlet und Kalenderjahr das Datum der Schulung, das Datum der letzten Untersuchung und der nächste Termin. Daraus entsteht der Stand erledigt, terminiert oder offen. Nur die Ampel ist für Trainer sichtbar. Es werden keine Befunde gespeichert.
- **Bericht je Turnier:** Ergebnisprotokoll und fünf bis sechs Zeilen zur Entwicklung der Athleten, später eventuell mit KI-Zusammenfassung. Das setzt eine datenschutzrechtliche Klärung voraus.
- **Meldegeldweiterberechnung:** Anforderungen fehlen noch.

## 8. Datenmodell (Überblick)

| Tabelle | Inhalt |
| --- | --- |
| benutzer, rollen, rechte | Anmeldekennung des Anmeldedienstes, Name, E-Mail, Rollen, Rechtetabelle |
| spieler | Name, Vorname, Geschlecht, Jahrgang, Verein, Kaderstatus, aktiv oder passiv, Trainingsort |
| spieler\_privat | Geburtsdatum, Kontaktdaten, Schule, T-Shirt-Größe, getrennt geschützt |
| vereine | Name, Schreibvarianten, Kürzel, Verband (BER oder BRA) |
| betreuer | Name, optional Verknüpfung zum Benutzer |
| turniere | Maßnahme, Ort, Start, Ende, Kalenderjahr, Konto, Status, verantwortlicher Trainer, Markierung „einreichen“, später Berichtstext |
| turnier\_teilnehmer | Spieler, eingefrorene Daten (Kürzel, Verband, Kaderstatus, Jahrgang, Geschlecht), Eigenanteil, nicht teilgenommen |
| turnier\_betreuer | Zuordnung Turnier und Betreuer |
| ausgaben, einnahmen | Turnier, Datum, Kostenpunkt, Beschreibung, Betrag, Zahler, Fremdwährung |
| foerderung | Kalenderjahr, Behörde (Berlin oder Brandenburg), Fördersumme |
| aenderungsprotokoll | wer, wann, was, Objekt; ohne Gesundheitsinhalte |
| später: nada, sportmedizin | Spieler, Kalenderjahr, Daten und Termine, kein Befund |

Beträge werden gespeichert, Summen, Erstattungen und Aufteilung werden immer berechnet \[Vorschlag\]. Ein Turnier hat damit nur einen Stand, und es kann keine abweichende gespeicherte Summe geben.

## 9. Anmeldung

- Die App meldet Personen über OAuth2 oder OpenID Connect an. Bevorzugt wird die Digitale Geschäftsstelle, weil alle Verbandsnutzer dort ein Konto haben \[Du\]. Rückfall ist die Nextcloud. Der Anmeldedienst ist eine Einstellung: Adresse, Client-Kennung und Geheimnis.
- Der Dienst sagt nur, wer die Person ist. **Die Rollen stehen in der App-Datenbank** und werden vom Administrator vergeben \[Vorschlag\].
- Der erste Administrator wird bei der Einrichtung in der Server-Konfiguration eingetragen.
- Sitzungen laufen über sichere Cookies (nur HTTPS, nicht per Skript lesbar) mit Schutz gegen fremde Anfragen.
- **Testanmeldung \[Du, Vorschlag zur Absicherung\]:** Eine Seite mit erfundenen Personen je Rolle erlaubt Tests ohne Passwort. Sie läuft nur, wenn sie ausdrücklich in der Konfiguration eingeschaltet ist, und der Server startet nicht, wenn sie im Echtbetrieb an ist.

## 10. Sicherheit und Datenschutz

- Rechteprüfung auf dem Server bei jeder Anfrage, auch auf Feldebene.
- Datenbankzugriffe nur über vorbereitete Abfragen. Konfigurationsdatei und Zugangsdaten liegen außerhalb des öffentlichen Verzeichnisses.
- Keine Befunde, keine Gesundheitsinhalte. Gespeichert werden nur Datum und Status.
- Änderungsprotokoll für Kader, Turniere, Rollen und Prüfstatus.
- Testdaten sind erfunden. Echte Daten kommen erst nach der Sicherheitsprüfung und dem Vertrag zur Auftragsverarbeitung.
- Offen sind das Sicherungs- und Löschkonzept, die Aufbewahrungsfristen und der Umgang mit Spielern, die den Kader verlassen.

## 11. Offene Fragen, nur du kannst sie beantworten

1. **Sichtbare Felder je Rolle:** Welche Felder zählen zu „einfache Stammdaten“ und welche zu „erweiterte Kaderdaten“? Brauchen Trainer Kontaktdaten der Athleten, etwa bei einer Fahrt? Meine Annahme: nein.\
-> das ist aktuell zu früh, da skann ich erst entscheiden wenn ich alle Datenfelder konsolidiert habe. grundsätzlich werden aber alle erhalten bleiben.
2. **Statistik für die Behörden:** Zählt ein Athlet zur Statistik, wenn er an einem eingereichten Turnier teilgenommen hat, oder sind es alle Kaderathleten des Jahres?\
-> ein Athlet zählt immer dann wenn er teilgenommen hat!
3. **Zeitpunkt des Einfrierens:** Meine Annahme ist, dass Spielerdaten beim Wechsel auf „zur Prüfung“ eingefroren werden. Bis dahin ziehen sie aktuelle Änderungen aus der Kaderliste. Passt das, oder soll es schon beim Hinzufügen des Athleten passieren?\
-> das passt genau!
4. **Aufteilung auf die Behörden:** Sie läuft wie im bisherigen Sheet pro Kopf nach Bundesland. Gilt das auch für die Fördermeldung, oder rechnet das Sportamt anders? \
-> Ja es gilt nach Bundesland!
5. **Wer trägt ein:** Darf jeder Betreuer eines Turniers eigene Auslagen eintragen, oder trägt der verantwortliche Trainer für alle ein? \
-> Jeder Betreuer darf eintragen!
6. **Prüfablauf:** Reicht „zur Prüfung, geprüft oder zurückgegeben mit Kommentar“, oder gibt es weitere Stufen, etwa „bei der Behörde eingereicht“? \
-> nein da sreicht, hier müssen wir nicht overengineeren! Es wird am Ende eine von mir später zu definierende Form erstellt!
7. **Hosting:** Wer wählt den Anbieter, schließt den Vertrag und kümmert sich um Updates und Sicherung? \
-> spannende Neuerung, ich habe eigenen Webspace (25GB) und eine MariaDB. Das bedeutet wir können mit diesem Setup beginnen um die Funktionalität zu enwickeln.
8. **Anmeldung:** Antwort des Anbieters der Digitalen Geschäftsstelle, ob OAuth2 oder OpenID Connect möglich ist, und die Antwort der Nextcloud-IT.\
-> tendenz hier zu DiGS(digitale Geshäftsstelle) da wir dies inHouse machen, das machen wir aber später, ich woill für mich erstmal funktional eine Basis abgebildet haben
9. **Ampel, Eintrag „/“:** In den Spalten SpoMed und Anti Doping steht bei vielen Spielern „/“. Bedeutet das „nicht erforderlich“ (dann grau) oder „noch nichts“ (dann rot)? Meine Annahme: nicht erforderlich.
10. **Behördenstatistik:** Zählt ein Athlet, wenn er an irgendeinem Turnier des Jahres teilgenommen hat, oder nur, wenn das Turnier beim Amt eingereicht wird?
11. **„Erledigt“ ohne Datum:** Es gilt für das laufende Kalenderjahr, und die Kaderpflege trägt die beiden Spalten zum Jahreswechsel neu ein. Passt das?
12. **Mitgliederliste der Vereine:** Welche Spalten hat sie? Steht dort schon das Bundesland oder die Postleitzahl des Vereins?
13. **Entwicklungsrechner:** Auf welchem Rechner läuft Claude Code, und sind dort PHP, Docker oder MariaDB vorhanden?

## 12. Entscheidungen (alle bestätigt)

Von dir bestätigt: Punkt 1 bis 9 (Punkt 9 per Kommentar).

- Eine Person kann mehrere Rollen haben.
- Rollen liegen in der App-Datenbank, der Anmeldedienst liefert nur die Identität.
- Spieler werden nie gelöscht, sondern auf passiv gesetzt.
- Das Geburtsdatum liegt nur im geschützten Bereich, in der Kaderliste steht der Jahrgang.
- Beträge werden gespeichert, alle Summen und Erstattungen werden berechnet.
- Die Belege bleiben in der Nextcloud und werden nicht in der App hochgeladen.
- Ablauf einer Abrechnung: Planung, in Abrechnung, zur Prüfung, geprüft.
- PHP ohne schweres Framework mit kleiner JSON-Schnittstelle, bestehende Oberfläche als Grundlage, Rechenlogik in einem eigenen Modul mit Tests.
- Entwicklung in Claude Code in einem Git-Repository, damit Versionsverlauf und Tests als Dateien vorliegen. Dieses Dokument bleibt die gemeinsame Beschreibung.

## 13. Etappen und Abnahme

Reihenfolge \[Du, Tausch von 2 und 3 übernommen\]:

1. **Grundgerüst:** Server, Datenbank, Testanmeldung, Rollen, Benutzerverwaltung, Änderungsprotokoll und Kaderliste mit Import, Export, Vereinsliste und Datenprüfung.
2. **Turnierabrechnung:** Planung, Abrechnung, Erstattung je Trainer, Druckansicht und Prüfablauf, auf Basis der zentralen Kaderliste.
3. **NADA und Sportmedizin:** Daten, Termine und die Ampel für Trainer.
4. **Jahresübersicht:** Kosten je Behörde, Markierung zum Einreichen, Fördersumme und Statistik.
5. **Bericht je Turnier.**
6. **Meldegeldweiterberechnung.**

**Abnahme mit automatischen Tests, zuerst geschrieben und dann umgesetzt:**

- Altersklasse: Jahrgang 2008 ist 2026 U19 und ab 2027 O19. Jahrgang 2015 ist 2026 und 2027 U13 und ab 2028 U15.
- Das Beispiel Friedrichsdorf ergibt Ausgaben 1.458,61 €, Einnahmen 843,50 €, Zuschuss 615,11 €, Berlin 546,76 €, Brandenburg 68,35 € und Erstattung 5,20 €.
- Rechte: Jede Rolle erhält über die Schnittstelle nur die erlaubten Felder. Ein Trainer bekommt keine Kontaktdaten.
- Ein Athlet lässt sich in einem Turnier nur einmal auswählen.
- Ein eingefrorenes Turnier ändert sich nicht, wenn die Kaderliste geändert wird.
- Der Server startet nicht, wenn die Testanmeldung im Echtbetrieb eingeschaltet ist.

Eine Etappe gilt erst als fertig, wenn ihre Tests laufen und du sie in der Testumgebung selbst ausprobiert hast.

## 14. Was dem Entwickler überlassen bleibt

Genaue Oberflächengestaltung, Namen der Datenbankfelder, die Aufteilung des Programmcodes, Fehlermeldungen und Texte. Entscheidungen dazu trifft der Entwickler im Sinne dieses Dokuments und nennt sie dir im Ergebnis.
