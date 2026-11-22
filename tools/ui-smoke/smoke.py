"""
Automatischer Rauchtest der Oberfläche (Task 11) mit Playwright in Chromium, Firefox und WebKit.
Nur Entwicklungswerkzeug, läuft gegen die lokale Vorschau mit erfundenen Daten. Ablauf: tools/ui-smoke/README.md.

Der erste Browser importiert testdata/Testkaderliste_fiktiv.xlsx; die anderen sehen danach dieselbe
Datenbank (Vorschau zeigt 0 neu, 59 vorhanden). Ergebnis: eine Zeile je Prüfung, Exit-Code 1 bei Fehlern.
"""
import os
import re
import sys
import zipfile

from playwright.sync_api import sync_playwright, expect

BASE = os.environ.get("BASE_URL", "http://web:8080")
ROSTER = os.environ.get("ROSTER_FILE", "/app/testdata/Testkaderliste_fiktiv.xlsx")
OUT = os.environ.get("OUT_DIR", "/tmp/ui-smoke")
HOST = re.match(r"https?://[^/]+", BASE).group(0)
CONTACT_KEYS = ["email1", "email2", "phone_player", "phone_mother", "phone_father", "school", "school_contact",
                "sportmed_note", "antidoping_note", "trainer_info", "birth_date", "shirt_size"]

failures = []


def check(name, cond, detail=""):
    print(("PASS  " if cond else "FAIL  ") + name + ("" if cond else f"  ({detail})"))
    if not cond:
        failures.append(name)


class Session:
    """Eigener Browser-Kontext je Person (eigene Cookies), sammelt Konsolenfehler und fremde Anfragen."""

    def __init__(self, browser, bname, person_name):
        self.ctx = browser.new_context(accept_downloads=True, locale="de-DE", timezone_id="Europe/Berlin")
        self.page = self.ctx.new_page()
        self.errors = []
        self.foreign = []
        self.bname = bname
        # Erwartete Fehlerantworten (401 vor der Anmeldung, absichtlich ausgelöste 409 und 422) meldet der
        # Browser als „Failed to load resource“; alles andere (Skriptfehler, CSP-Verstöße) zählt.
        expected = re.compile(r"Failed to load resource: the server responded with a status of (401|409|422)\b")
        self.page.on("console", lambda m: self.errors.append(m.text) if m.type == "error" and not expected.search(m.text) else None)
        self.page.on("pageerror", lambda e: self.errors.append(str(e)))
        self.page.on("request", lambda r: self.foreign.append(r.url) if not r.url.startswith(HOST) and not r.url.startswith("blob:") and not r.url.startswith("data:") else None)
        self.page.goto(BASE + "/")
        self.page.get_by_role("button", name=re.compile(re.escape(person_name))).click()
        expect(self.page.locator("#who")).to_contain_text(person_name)

    def open_roster(self):
        with self.page.expect_response(lambda r: "/api/players?" in r.url) as info:
            self.page.locator(".tile", has_text="Kaderliste").click()
        expect(self.page.locator(".count")).to_be_visible()
        return info.value.json()["players"]

    def headers(self):
        return [t.strip() for t in self.page.locator(".sc thead th").all_inner_texts()]

    def close(self, label):
        check(f"{self.bname} {label}: keine Fehler in der Konsole", not self.errors, "; ".join(self.errors[:3]))
        check(f"{self.bname} {label}: keine Anfrage an fremde Server", not self.foreign, ", ".join(self.foreign[:3]))
        self.ctx.close()


def xlsx_text(path):
    with zipfile.ZipFile(path) as z:
        names = [n for n in z.namelist() if n.startswith("xl/")]
        return "".join(z.read(n).decode("utf-8", "replace") for n in names if n.endswith(".xml"))


HEAD = ["Nummer", "Name", "Vorname", "AK", "m/w", "Kaderstatus", "Status", "Verein", "JG", "Trainer Mail/Verein",
        "Schule /Klasse /Lehrer", "Kontakt Schulbefreiungen (Name&E-Mail)", "Trainingsort", "Alter", "SpoMed",
        "Anti Doping", "email 1 (Hauptadresse)", "email 2", "Spieler", "Mutter", "Vater", ""]


def make_shifted_xlsx(page, bname, drop, extra):
    """Kaderliste mit verschobenen Spalten, im Browser mit SheetJS erzeugt (erfundene Werte)."""
    head = list(HEAD)
    row = [99, "Testfall", "Spalte", "U13", "m", "SK", "aktiv", "BC Potsdam", "2015", "", "", "", "BSP", "", "Befundtext", "", "", "", "", "", "", ""]
    if drop is not None:
        del head[drop]
        del row[drop]
    if extra is not None:
        head.insert(4, extra)
        row.insert(4, "x")
    b64 = page.evaluate("""async ([head, row]) => {
        const X = window.XLSX || await new Promise((res) => { const s = document.createElement('script');
            s.src = 'vendor/xlsx.full.min.js'; s.onload = () => res(window.XLSX); document.head.append(s); });
        const wb = X.utils.book_new();
        X.utils.book_append_sheet(wb, X.utils.aoa_to_sheet([['Testliste'], [], head, row]), 'Kader');
        return X.write(wb, { type: 'base64', bookType: 'xlsx' });
    }""", [head, row])
    import base64
    path = os.path.join(OUT, f"{bname}-shifted-{drop}-{bool(extra)}.xlsx")
    with open(path, "wb") as f:
        f.write(base64.b64decode(b64))
    return path


def run(p, bname, first):
    browser = getattr(p, bname).launch()
    page_ctx = browser.new_context()
    page = page_ctx.new_page()
    page.goto(BASE + "/")
    expect(page.get_by_text("Testanmeldung: nur mit erfundenen Daten verwenden.")).to_be_visible()
    check(f"{bname} A1: Anmeldeseite mit Testpersonen", page.get_by_role("button", name=re.compile("Ada Verwaltung")).count() == 1)
    page_ctx.close()

    # ---- admin: Startseite, Import, Datenprüfung, Bearbeiten, Export, Verwaltung
    s = Session(browser, bname, "Ada Verwaltung")
    pg = s.page
    check(f"{bname} S1: Kaderliste aktiv", pg.locator(".tile", has_text="Kaderliste").is_enabled())
    check(f"{bname} S1: Verwaltung aktiv", pg.locator(".tile", has_text="Verwaltung").is_enabled())
    check(f"{bname} S1: Turniere gesperrt", pg.locator(".tile", has_text="Turniere").is_disabled())
    s.open_roster()
    with pg.expect_file_chooser() as fc:
        pg.get_by_role("button", name="Import").click()
    fc.value.set_files(ROSTER)
    panel = pg.locator(".panel")
    expect(panel).to_contain_text("Vorschau Import", timeout=20000)
    stats = panel.locator(".stats").inner_text()
    expected = "59 neu" if first else "0 neu"
    check(f"{bname} I1: Vorschau „{expected}“", expected.replace(" ", "") in stats.replace("\n", "").replace(" ", ""), stats)
    check(f"{bname} I1: Lübbe übersprungen (doppelt)", "Lübbe, Arvid" in panel.inner_text() and "doppelt in der Datei" in panel.inner_text())
    check(f"{bname} I1: Befund AK", "Liste U11, berechnet U13" in panel.inner_text())
    check(f"{bname} I2: Hinweis Gesundheitsdaten im Import", "keine Befunde oder medizinischen Angaben" in panel.inner_text())
    if first:
        pg.get_by_role("button", name="Übernehmen").click()
        expect(panel).to_contain_text("Import übernommen", timeout=20000)
    else:
        pg.get_by_role("button", name="Abbrechen").click()
    expect(pg.locator(".count")).to_contain_text("59 von 59")
    check(f"{bname} I3: 59 Spieler in der Liste", True)
    # I5: verschobene Spalten (eine fehlt, eine zu viel) werden abgelehnt, ohne Anfrage an den Server
    for label, drop, extra in [("Spalte K fehlt", 10, None), ("zusätzliche Spalte", None, "LK bis U22")]:
        path = make_shifted_xlsx(pg, bname, drop, extra)
        calls = []
        pg.on("request", lambda r: calls.append(r.url) if "/api/players/import" in r.url else None)
        with pg.expect_file_chooser() as fc:
            pg.get_by_role("button", name="Import").click()
        fc.value.set_files(path)
        expect(pg.locator("p.msg.err")).to_contain_text("passen nicht zur Kaderliste", timeout=10000)
        check(f"{bname} I5: {label} – abgelehnt, nichts gesendet", not calls, calls)
    hdr = s.headers()
    check(f"{bname} K1: alle Spalten für admin", all(h in hdr for h in ["Name", "AK", "E-Mail 1", "SpoMed", "SpoMed (Ampel)", "Telefon Mutter"]), hdr)
    check(f"{bname} K12: Umlaute", pg.locator("tbody").get_by_text("Müßig").count() >= 1)

    pg.get_by_role("button", name="Datenprüfung").click()
    expect(panel).to_contain_text("Datenprüfung")
    link = panel.locator("button.link").first
    name = link.inner_text()
    link.click()
    check(f"{bname} D2: Klick auf Befund hebt Zeile hervor", pg.locator("tr.hl").count() == 1 and name.split(",")[0] in pg.locator("tr.hl").inner_text())

    pg.locator("tbody button.link", has_text="Bearbeiten").first.click()
    dlg = pg.locator("dialog[open]")
    check(f"{bname} K9: Hinweis an SpoMed und Anti Doping", dlg.get_by_text("Nur Datum und Status eintragen, keine Befunde oder medizinischen Angaben.").count() == 2)
    dlg.locator('label[data-field="list_no"] input').fill("abc")
    dlg.get_by_role("button", name="Speichern").click()
    expect(dlg.locator('label[data-field="list_no"]')).to_have_class(re.compile("bad"))
    check(f"{bname} K10: ungültiger Wert wird am Feld gemeldet", True)
    dlg.locator('label[data-field="training_site"] input').fill("LLZ " + bname)
    dlg.locator('label[data-field="list_no"] input').fill(str(70 + ["chromium", "firefox", "webkit"].index(bname)))
    with pg.expect_response(lambda r: "/api/players/" in r.url and r.request.method == "PUT") as put:
        dlg.get_by_role("button", name="Speichern").click()
    check(f"{bname} K8: Speichern schickt nur geänderte Felder", sorted(put.value.request.post_data_json.keys()) == ["list_no", "training_site"], put.value.request.post_data_json)
    expect(pg.locator("tbody")).to_contain_text("LLZ " + bname)

    with pg.expect_download() as dl:
        pg.get_by_role("button", name="Export").click()
    path = os.path.join(OUT, f"{bname}-admin.xlsx")
    dl.value.save_as(path)
    text = xlsx_text(path)
    check(f"{bname} E1: Export mit Überschriften und Umlauten", re.match(r"Kaderliste_\d{4}-\d{2}-\d{2}\.xlsx", dl.value.suggested_filename) is not None
          and "E-Mail 1" in text and "Müßig" in text, dl.value.suggested_filename)

    pg.locator("#nav-home").click()
    pg.locator(".tile", has_text="Verwaltung").click()
    expect(pg.get_by_text("Benutzer und Rollen")).to_be_visible()
    expect(pg.get_by_text("Änderungsprotokoll")).to_be_visible()
    times = pg.locator(".panel").nth(1).locator("tbody tr td:first-child").all_inner_texts()
    check(f"{bname} U5: Protokollzeit TT.MM.JJJJ HH:MM", bool(times) and all(re.fullmatch(r"\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}", t) for t in times), times[:2])
    me_row = pg.locator("tr", has_text="(ich)")
    me_row.locator('input[value="admin"]').uncheck()
    me_row.get_by_role("button", name="Rollen speichern").click()
    expect(pg.locator("#flash")).to_contain_text("letzte aktive admin")
    check(f"{bname} U2: letzter admin geschützt", True)
    s.close("admin")

    # ---- coach: keine Kontaktfelder, nichts bearbeiten
    s = Session(browser, bname, "Tina Training")
    rows = s.open_roster()
    leaked = sorted({k for r in rows for k in r if k in CONTACT_KEYS})
    check(f"{bname} K2: coach – Netzwerk ohne Kontaktfelder", not leaked, leaked)
    hdr = s.headers()
    check(f"{bname} K2: coach – Tabelle ohne Kontaktspalten", not any(h in hdr for h in ["E-Mail 1", "Telefon Spieler", "SpoMed", "Schule/Klasse/Lehrer"]), hdr)
    check(f"{bname} K2: coach – Ampel sichtbar", "SpoMed (Ampel)" in hdr, hdr)
    pg = s.page
    check(f"{bname} K2: coach – keine Bearbeiten/Import/Datenprüfung/Neu",
          all(pg.get_by_role("button", name=n).count() == 0 for n in ["Bearbeiten", "Import", "Datenprüfung", "Neuer Spieler"]))
    with pg.expect_download() as dl:
        pg.get_by_role("button", name="Export").click()
    path = os.path.join(OUT, f"{bname}-coach.xlsx")
    dl.value.save_as(path)
    check(f"{bname} E1: coach-Export ohne Kontaktfelder", "E-Mail" not in xlsx_text(path) and "example.org" not in xlsx_text(path))
    pg.get_by_role("button", name="Vereine").click()
    expect(pg.locator("table.clubs")).to_be_visible()
    check(f"{bname} V1: Vereine für coach nur lesend", pg.locator("table.clubs input").count() == 0)
    check(f"{bname} S2: coach ohne Verwaltung", pg.locator("#nav-home").count() == 1)
    pg.locator("#nav-home").click()
    check(f"{bname} S2: coach – Kachel Verwaltung gesperrt", pg.locator(".tile", has_text="Verwaltung").is_disabled())
    pg.locator("#logout").click()
    expect(pg.get_by_text("Testanmeldung: nur mit erfundenen Daten verwenden.")).to_be_visible()
    check(f"{bname} A4: Abmelden führt zur Anmeldeseite", True)
    s.close("coach")

    # ---- basis: nur Name, Vorname, Verein, Kürzel
    s = Session(browser, bname, "Bert Basis")
    s.open_roster()
    check(f"{bname} K3: basis – nur Grunddaten", s.headers() == ["Name", "Vorname", "Verein", "Kürzel"], s.headers())
    s.close("basis")

    # ---- Prüfer: nur Protokoll
    s = Session(browser, bname, "Paul Prüfer")
    s.page.locator(".tile", has_text="Verwaltung").click()
    expect(s.page.get_by_text("Änderungsprotokoll")).to_be_visible()
    check(f"{bname} U4: Prüfer ohne Benutzerliste", s.page.get_by_text("Benutzer und Rollen").count() == 0)
    s.close("pruefer")

    # ---- ohne Rolle
    s = Session(browser, bname, "Olga Ohnerolle")
    check(f"{bname} A6: ohne Rolle – Hinweis, keine aktive Kachel",
          s.page.get_by_text("Keine Rolle zugewiesen").count() == 1 and s.page.locator(".tile:enabled").count() == 0)
    s.close("ohne-rolle")

    # ---- Kaderpflege: Vereine bearbeiten, zwei Rollen wie Kaderpflege
    s = Session(browser, bname, "Theo Doppelrolle")
    s.open_roster()
    check(f"{bname} K4: zwei Rollen – Import sichtbar", s.page.get_by_role("button", name="Import").count() == 1)
    s.page.get_by_role("button", name="Vereine").click()
    expect(s.page.locator("table.clubs")).to_be_visible()
    check(f"{bname} V2: Vereine bearbeitbar", s.page.locator("table.clubs input").count() > 0
          and s.page.get_by_role("button", name="Speichern").count() == 1)
    s.close("doppelrolle")

    browser.close()


def main():
    os.makedirs(OUT, exist_ok=True)
    names = sys.argv[1:] or ["chromium", "firefox", "webkit"]
    with sync_playwright() as p:
        for i, name in enumerate(names):
            print(f"--- {name}")
            try:
                run(p, name, first=(i == 0))
            except Exception as e:  # Abbruch eines Browsers zählt als Fehler, die anderen laufen weiter
                check(f"{name}: Ablauf ohne Abbruch", False, f"{type(e).__name__}: {str(e).splitlines()[0]}")
    print(f"\n{len(failures)} Fehler")
    sys.exit(1 if failures else 0)


if __name__ == "__main__":
    main()
