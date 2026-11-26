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
        """Spaltenköpfe ohne Sortierpfeil."""
        return [re.sub(r"[▲▼]", "", t).strip() for t in self.page.locator(".sc thead th").all_inner_texts()]

    def table(self):
        """Angezeigte Tabelle: Köpfe (ohne die Spalte „Bearbeiten“) und Zeilen als Texte."""
        hdr = self.headers()
        skip = 1 if hdr and hdr[0] == "" else 0
        rows = self.page.locator(".sc tbody tr").evaluate_all(
            "trs => trs.map((tr) => [...tr.children].map((td) => td.innerText.trim()))")
        return hdr[skip:], [r[skip:] for r in rows]

    def filter_labels(self):
        """Filter der Leiste (data-filter der Beschriftung), in ihrer Reihenfolge."""
        return self.page.locator(".filters label").evaluate_all("ls => ls.map((l) => l.dataset.filter)")

    def close(self, label):
        check(f"{self.bname} {label}: keine Fehler in der Konsole", not self.errors, "; ".join(self.errors[:3]))
        check(f"{self.bname} {label}: keine Anfrage an fremde Server", not self.foreign, ", ".join(self.foreign[:3]))
        self.ctx.close()


def xlsx_text(path):
    with zipfile.ZipFile(path) as z:
        names = [n for n in z.namelist() if n.startswith("xl/")]
        return "".join(z.read(n).decode("utf-8", "replace") for n in names if n.endswith(".xml"))


def xlsx_rows(path):
    """Zeilen des ersten Blatts als Texte (SheetJS schreibt Texte direkt in die Zelle oder als sharedStrings)."""
    import html
    with zipfile.ZipFile(path) as z:
        sheet = z.read("xl/worksheets/sheet1.xml").decode("utf-8")
        shared = []
        if "xl/sharedStrings.xml" in z.namelist():
            sst = z.read("xl/sharedStrings.xml").decode("utf-8")
            shared = [html.unescape("".join(re.findall(r"<t[^>]*>([^<]*)</t>", si))) for si in re.findall(r"<si>(.*?)</si>", sst, re.S)]
    out = []
    for row in re.findall(r"<row[^>]*>(.*?)</row>", sheet, re.S):
        cells = []
        for attrs, inner in re.findall(r"<c([^>]*?)(?:/>|>(.*?)</c>)", row, re.S):
            t = re.search(r'\bt="([^"]+)"', attrs)
            v = re.search(r"<v>([^<]*)</v>", inner or "")
            if t and t.group(1) == "s" and v:
                cells.append(shared[int(v.group(1))])
            elif t and t.group(1) == "inlineStr":
                cells.append(html.unescape("".join(re.findall(r"<t[^>]*>([^<]*)</t>", inner))))
            else:
                cells.append(html.unescape(v.group(1)) if v else "")
        out.append(cells)
    return out


# Reine Funktionen aus public/js/roster-view.js, im Seitenkontext geprüft (erfundene Daten).
VIEW_JS = """async () => {
  const m = await import('/js/roster-view.js');
  const rows = (list) => list.map((o, i) => ({ id: i + 1, last_name: 'N' + i, first_name: 'V', ...o }));
  const names = ['Weißkopf', 'Özdemir', 'Müßig', 'Albers', 'Zander'].map((n) => ({ last_name: n }));
  const st = rows(['SK', 'LK', null, 'NK1', 'NK2'].map((s) => ({ roster_status: s })));
  const ak = rows(['O19', 'U09', '', 'U13'].map((a) => ({ ak: a })));
  const by = rows([2016, 2009, null, 2010].map((b) => ({ birth_year: b })));
  const mix = rows([
    { ak: 'U13', roster_status: 'LK', last_name: 'Müßig', birth_year: 2014, active: true },
    { ak: 'U13', roster_status: 'SK', last_name: 'Albers', birth_year: 2013, active: true },
    { ak: 'U15', roster_status: 'LK', last_name: 'Zander', birth_year: null, active: false },
  ]);
  const col = (list, k) => list.map((r) => r[k]);
  return {
    empty: m.EMPTY,
    keys: m.FILTER_KEYS,
    defaults: m.defaultFilters(),
    byName: col(m.sortRows(rows(names), 'last_name', 'asc'), 'last_name'),
    stAsc: col(m.sortRows(st, 'roster_status', 'asc'), 'roster_status'),
    stDesc: col(m.sortRows(st, 'roster_status', 'desc'), 'roster_status'),
    akAsc: col(m.sortRows(ak, 'ak', 'asc'), 'ak'),
    akDesc: col(m.sortRows(ak, 'ak', 'desc'), 'ak'),
    byAsc: col(m.sortRows(by, 'birth_year', 'asc'), 'birth_year'),
    byDesc: col(m.sortRows(by, 'birth_year', 'desc'), 'birth_year'),
    both: col(m.filterRows(mix, { ...m.defaultFilters(), active: '', ak: 'U13', roster_status: 'LK' }), 'last_name'),
    noYear: col(m.filterRows(mix, { ...m.defaultFilters(), active: '', birth_year: m.EMPTY }), 'last_name'),
    search: col(m.filterRows(mix, { ...m.defaultFilters(), search: 'müß' }), 'last_name'),
    activeOnly: col(m.filterRows(mix, m.defaultFilters()), 'last_name'),
    ignored: col(m.filterRows(rows([{ last_name: 'A' }]), { ...m.defaultFilters(), ak: 'U13' }), 'last_name'),
    yearOpts: m.filterOptions(mix, 'birth_year'),
    activeOpts: m.filterOptions(mix, 'active'),
    visible: m.visibleFilterKeys([{ id: 1, last_name: 'A', first_name: 'B', club_name: 'X', club_short: '' }]),
    unchanged: (() => { const r = rows(names); const before = r.map((x) => x.last_name); m.sortRows(r, 'last_name', 'desc'); return before.join() === r.map((x) => x.last_name).join(); })(),
  };
}"""


def check_view_module(page, bname):
    try:
        v = page.evaluate(VIEW_JS)
    except Exception as e:
        check(f"{bname} F0: Modul roster-view.js ladbar", False, str(e).splitlines()[0])
        return
    check(f"{bname} F1: Umlaute beim Sortieren (Review Focus 1)", v["byName"] == ["Albers", "Müßig", "Özdemir", "Weißkopf", "Zander"], v["byName"])
    check(f"{bname} F2: Kaderstatus aufsteigend NK1, NK2, LK, SK, leer", v["stAsc"] == ["NK1", "NK2", "LK", "SK", None], v["stAsc"])
    check(f"{bname} F2: Kaderstatus absteigend SK, LK, NK2, NK1, leer", v["stDesc"] == ["SK", "LK", "NK2", "NK1", None], v["stDesc"])
    check(f"{bname} F3: AK U09, U13, O19, leer am Ende", v["akAsc"] == ["U09", "U13", "O19", ""] and v["akDesc"] == ["O19", "U13", "U09", ""], (v["akAsc"], v["akDesc"]))
    check(f"{bname} F3: Jahrgang numerisch, leer am Ende in beiden Richtungen (Review Focus 3)",
          v["byAsc"] == [2009, 2010, 2016, None] and v["byDesc"] == [2016, 2010, 2009, None], (v["byAsc"], v["byDesc"]))
    check(f"{bname} F4: Filter wirken zusammen", v["both"] == ["Müßig"], v["both"])
    check(f"{bname} F4: „(leer)“ findet Spieler ohne Jahrgang", v["noYear"] == ["Zander"], v["noYear"])
    check(f"{bname} F4: Suche „müß“ findet Müßig", v["search"] == ["Müßig"], v["search"])
    check(f"{bname} F4: Standard blendet passive Spieler aus", sorted(v["activeOnly"]) == ["Albers", "Müßig"], v["activeOnly"])
    check(f"{bname} F4: Filter auf fehlenden Schlüssel wird ignoriert", v["ignored"] == ["A"], v["ignored"])
    check(f"{bname} F5: Jahrgang-Optionen numerisch, „(leer)“ am Ende",
          [o["value"] for o in v["yearOpts"]] == ["2013", "2014", v["empty"]] and v["yearOpts"][-1]["label"] == "(leer)", v["yearOpts"])
    check(f"{bname} F5: Status-Optionen aktiv, passiv", [o["label"] for o in v["activeOpts"]] == ["aktiv", "passiv"], v["activeOpts"])
    check(f"{bname} F6: Standardfilter", v["defaults"] == {"search": "", "ak": "", "birth_year": "", "roster_status": "", "club_name": "",
                                                          "gender": "", "active": "true"}, v["defaults"])
    check(f"{bname} F6: Filterschlüssel", v["keys"] == ["ak", "birth_year", "roster_status", "club_name", "gender", "active"], v["keys"])
    check(f"{bname} F6: sichtbare Filter für Basis nur Verein", v["visible"] == ["club_name"], v["visible"])
    check(f"{bname} F6: sortRows ändert die Liste nicht", v["unchanged"])


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
    check_view_module(pg, bname)
    check(f"{bname} S1: Kaderliste aktiv", pg.locator(".tile", has_text="Kaderliste").is_enabled())
    check(f"{bname} S1: Verwaltung aktiv", pg.locator(".tile", has_text="Verwaltung").is_enabled())
    check(f"{bname} S1: Turniere gesperrt", pg.locator(".tile", has_text="Turniere").is_disabled())
    s.open_roster()
    with pg.expect_file_chooser() as fc:
        pg.get_by_role("button", name="Import").click()
    with pg.expect_response(lambda r: "/api/players/import" in r.url, timeout=20000) as imp:
        fc.value.set_files(ROSTER)
    sent = imp.value.request.post_data_json["rows"]
    check(f"{bname} I6: Import ohne 403", imp.value.status == 200, imp.value.status)
    check(f"{bname} I6: Import sendet kein list_ak", not any("list_ak" in r for r in sent), sorted({k for r in sent for k in r}))
    panel = pg.locator(".panel")
    expect(panel).to_contain_text("Vorschau Import", timeout=20000)
    stats = panel.locator(".stats").inner_text()
    expected = "59 neu" if first else "0 neu"
    check(f"{bname} I1: Vorschau „{expected}“", expected.replace(" ", "") in stats.replace("\n", "").replace(" ", ""), stats)
    check(f"{bname} I1: Lübbe übersprungen (doppelt)", "Lübbe, Arvid" in panel.inner_text() and "doppelt in der Datei" in panel.inner_text())
    check(f"{bname} I1: kein Befund zur Listen-AK mehr", "berechnet U13" not in panel.inner_text())
    check(f"{bname} I2: Hinweis Gesundheitsdaten im Import", "keine Befunde oder medizinischen Angaben" in panel.inner_text())
    if first:
        pg.get_by_role("button", name="Übernehmen").click()
        expect(panel).to_contain_text("Import übernommen", timeout=20000)
    else:
        pg.get_by_role("button", name="Abbrechen").click()
    expect(pg.locator(".count")).to_contain_text(re.compile(r"\d+ von 59 Spielern"))
    check(f"{bname} I3: 59 Spieler in der Liste (Zählung „n von 59“, Standard nur aktive)", True)
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
    check(f"{bname} K13: Spalte „Geburtstag“, keine „AK (Liste)“ und kein „JG (Datum)“",
          "Geburtstag" in hdr and "AK (Liste)" not in hdr and "JG (Datum)" not in hdr, hdr)
    # Vergleichswert: bis Task 3 hatte der Tabelleninhalt 12px (app.css, th/td).
    size = pg.locator("tbody td").nth(1).evaluate("e => parseFloat(getComputedStyle(e).fontSize)")
    check(f"{bname} K14: Tabellenschrift größer als vorher (12px)", size > 12, size)

    # K15/K16: Kaderstatus-Auswahl und gesperrter Jahrgang im Bearbeiten-Dialog
    pg.locator("tbody button.link", has_text="Bearbeiten").first.click()
    dlg = pg.locator("dialog[open]")
    opts = dlg.locator('label[data-field="roster_status"] select option').evaluate_all("os => os.map((o) => o.value)")
    check(f"{bname} K15: Kaderstatus leer, NK1, NK2, LK, SK", opts == ["", "NK1", "NK2", "LK", "SK"], opts)
    by = dlg.locator('label[data-field="birth_year"] input')
    bd = dlg.locator('label[data-field="birth_date"] input')
    bd.fill("2014-09-14")
    check(f"{bname} K16: Jahrgang gesperrt mit Geburtstag", by.is_disabled() and by.input_value() == "2014", by.input_value())
    check(f"{bname} K16: Hinweis am Jahrgang", dlg.get_by_text("wird aus dem Geburtstag übernommen").is_visible())
    bd.fill("")
    check(f"{bname} K16: Geburtstag geleert, Jahrgang wieder bearbeitbar", by.is_enabled()
          and not dlg.get_by_text("wird aus dem Geburtstag übernommen").is_visible())
    day = "2013-01-0" + str(2 + ["chromium", "firefox", "webkit"].index(bname))  # je Browser ein anderes Datum
    bd.fill(day)
    with pg.expect_response(lambda r: "/api/players/" in r.url and r.request.method == "PUT") as put:
        dlg.get_by_role("button", name="Speichern").click()
    body = put.value.request.post_data_json
    check(f"{bname} K16: gesperrter Jahrgang wird nicht mitgeschickt", put.value.status == 200 and "birth_year" not in body
          and body.get("birth_date") == day, (put.value.status, body))
    expect(pg.locator("dialog[open]")).to_have_count(0)

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
    check(f"{bname} F7: coach-Export ohne Geburtstag (Review Focus 2)", "Geburtstag" not in xlsx_rows(path)[0], xlsx_rows(path)[0])
    check(f"{bname} F7: coach-Filterleiste Suche, AK, Jahrgang, Kaderstatus, Verein, m/w, Status (Review Focus 2)",
          s.filter_labels() == ["search", "ak", "birth_year", "roster_status", "club_name", "gender", "active"], s.filter_labels())
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
    check(f"{bname} F7: basis – Filter nur Suche und Verein", s.filter_labels() == ["search", "club_name"], s.filter_labels())
    s.close("basis")

    # ---- Kaderpflege: Sortieren, Filter, Zählung, Zurücksetzen, Export der Ansicht (Review Focus 4)
    s = Session(browser, bname, "Karl Kaderpflege")
    rows = s.open_roster()
    pg = s.page
    total = len(rows)
    flt = pg.locator(".filters")
    flt.locator('label[data-filter="ak"] select').select_option("U13")
    hdr, shown = s.table()
    u13 = [r for r in rows if r.get("ak") == "U13" and r.get("active") is True]
    check(f"{bname} F8: Filter U13 und aktiv – Zeilen stimmen", len(shown) == len(u13) and len(u13) > 0, (len(shown), len(u13)))
    check(f"{bname} F8: Zählung „n von m Spielern“", pg.locator(".count").inner_text() == f"{len(u13)} von {total} Spielern",
          pg.locator(".count").inner_text())
    name_col = pg.locator(".sc thead th", has_text="Name").first
    check(f"{bname} F8: Standard sortiert nach Name (▲, aria-sort ascending)",
          name_col.get_attribute("aria-sort") == "ascending" and "▲" in name_col.inner_text(), name_col.inner_text())
    first_col = pg.locator(".sc thead th", has_text="Vorname")
    first_col.locator("button").click()
    check(f"{bname} F9: Klick auf „Vorname“ zeigt ▲ und aria-sort ascending",
          "▲" in first_col.inner_text() and first_col.get_attribute("aria-sort") == "ascending", first_col.inner_text())
    first_col.locator("button").click()
    check(f"{bname} F9: zweiter Klick zeigt ▼ und aria-sort descending",
          "▼" in first_col.inner_text() and first_col.get_attribute("aria-sort") == "descending", first_col.inner_text())
    hdr, shown = s.table()
    firsts = [r[hdr.index("Vorname")] for r in shown]
    with pg.expect_download() as dl:
        pg.get_by_role("button", name="Export").click()
    path = os.path.join(OUT, f"{bname}-kaderpflege-u13.xlsx")
    dl.value.save_as(path)
    sheet = xlsx_rows(path)
    check(f"{bname} F10: Export – sichtbare Spalten mit Beschriftung", sheet[0] == hdr, (sheet[0][:6], hdr[:6]))
    check(f"{bname} F10: Export – angezeigte Zeilen in angezeigter Reihenfolge",
          [r[hdr.index("Vorname")] for r in sheet[1:]] == firsts and len(sheet) - 1 == len(shown), (len(sheet) - 1, len(shown)))
    # Spieler bei Filter „aktiv“ auf passiv setzen: Filter bleiben, Spieler verschwindet, Zählung sinkt um 1
    pg.locator("tbody button.link", has_text="Bearbeiten").first.click()
    dlg = pg.locator("dialog[open]")
    dlg.locator('label[data-field="active"] select').select_option("0")
    with pg.expect_response(lambda r: "/api/players?" in r.url):
        dlg.get_by_role("button", name="Speichern").click()
    expect(pg.locator(".count")).to_have_text(f"{len(u13) - 1} von {total} Spielern")
    check(f"{bname} F11: nach Speichern bleiben die Filter, Spieler verschwindet",
          flt.locator('label[data-filter="ak"] select').input_value() == "U13"
          and flt.locator('label[data-filter="active"] select').input_value() == "true" and len(s.table()[1]) == len(u13) - 1)
    pg.get_by_role("button", name="Filter zurücksetzen").click()
    check(f"{bname} F12: „Filter zurücksetzen“ stellt den Standard her",
          flt.locator('label[data-filter="ak"] select').input_value() == "" and flt.locator('label[data-filter="active"] select').input_value() == "true"
          and flt.locator('input[type="search"]').input_value() == "")
    s.close("kaderpflege")

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
