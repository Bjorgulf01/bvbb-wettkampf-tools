// Kaderliste: Tabelle, Bearbeiten, Import mit Vorschau, Datenprüfung, Export; Reiter „Vereine“.
// Die Spalten entstehen aus den Schlüsseln der Antwort von GET /api/players: Was der Server nicht
// liefert (weil die Rolle es nicht lesen darf), gibt es hier nicht. Bearbeiten nur für Felder, die die
// Antwort enthält und die laut GET /api/me schreibbar sind.
import { api, query, errorText, ApiError } from './api.js';
import { el, clear, formatDate, flash, loadXlsx } from './dom.js';
import { renderClubs } from './clubs.js';
import {
  ROSTER_STATUS_ORDER, defaultFilters, filterOptions, filterRows, sortRows, visibleFilterKeys,
} from './roster-view.js';

/**
 * Die 22 Spalten der Excel-Kaderliste in ihrer Reihenfolge; null: Spalte wird nicht übernommen
 * (AK und Alter: die AK berechnet der Server aus dem Jahrgang).
 */
export const COLUMN_KEYS = [
  'list_no', 'last_name', 'first_name', null, 'gender', 'roster_status', 'active', 'club_name',
  'birth_date', 'trainer_info', 'school', 'school_contact', 'training_site', null, 'sportmed_note',
  'antidoping_note', 'email1', 'email2', 'phone_player', 'phone_mother', 'phone_father', 'shirt_size',
];

/**
 * Erwartete Kopfzeile, je Spalte A bis V ein Muster für den Text ohne Leerraum in Kleinbuchstaben,
 * dazu die Anzeige für die Meldung. Alle 22 Spalten werden geprüft: Fehlt eine Spalte oder kommt eine
 * hinzu, verschieben sich sonst alle folgenden Felder, und zum Beispiel Text aus SpoMed landete in einem
 * Feld, das Trainer sehen dürfen.
 */
const HEADERS = [
  [/^nummer$/, 'Nummer'], [/^name$/, 'Name'], [/^vorname$/, 'Vorname'], [/^ak$/, 'AK'], [/^m\/w$/, 'm/w'],
  [/^kaderstatus$/, 'Kaderstatus'], [/^status$/, 'Status'], [/^verein$/, 'Verein'], [/^jg$/, 'JG'],
  [/^trainer/, 'Trainer Mail/Verein'], [/^schule/, 'Schule/Klasse/Lehrer'], [/^kontaktschul/, 'Kontakt Schulbefreiungen'],
  [/^trainingsort$/, 'Trainingsort'], [/^alter$/, 'Alter'], [/^spomed$/, 'SpoMed'], [/^antidoping$/, 'Anti Doping'],
  [/^e-?mail1/, 'email 1'], [/^e-?mail2/, 'email 2'], [/^(telefon)?spieler$/, 'Spieler'], [/^(telefon)?mutter$/, 'Mutter'],
  [/^(telefon)?vater$/, 'Vater'], [/^(t-?shirt.*)?$/, 'T-Shirt Größe oder leer'],
];

const LABELS = {
  list_no: 'Nummer', last_name: 'Name', first_name: 'Vorname', ak: 'AK', gender: 'm/w',
  roster_status: 'Kaderstatus', active: 'Status', club_name: 'Verein', club_short: 'Kürzel', state: 'Bundesland',
  birth_year: 'Jahrgang', birth_date: 'Geburtstag', trainer_info: 'Trainer Mail/Verein', school: 'Schule/Klasse/Lehrer',
  school_contact: 'Kontakt Schulbefreiungen', training_site: 'Trainingsort', sportmed_status: 'SpoMed (Ampel)',
  sportmed_note: 'SpoMed', antidoping_status: 'Anti Doping (Ampel)', antidoping_note: 'Anti Doping',
  email1: 'E-Mail 1', email2: 'E-Mail 2', phone_player: 'Telefon Spieler', phone_mother: 'Telefon Mutter',
  phone_father: 'Telefon Vater', shirt_size: 'T-Shirt-Größe',
};

/** Anzeigereihenfolge wie in der Kaderliste. Felder, die hier fehlen, kommen ans Ende. */
const ORDER = ['list_no', 'last_name', 'first_name', 'ak', 'gender', 'roster_status', 'active',
  'club_name', 'club_short', 'state', 'birth_year', 'birth_date', 'trainer_info', 'school', 'school_contact',
  'training_site', 'sportmed_status', 'sportmed_note', 'antidoping_status', 'antidoping_note', 'email1', 'email2',
  'phone_player', 'phone_mother', 'phone_father', 'shirt_size'];

const LIGHT = { done: ['●', 'erledigt'], scheduled: ['●', 'terminiert'], open: ['●', 'offen'], unclear: ['?', 'unklar'] };
const LONG = ['trainer_info', 'school_contact', 'sportmed_note', 'antidoping_note'];
const HEALTH_NOTES = ['sportmed_note', 'antidoping_note'];
const HEALTH_HINT = 'Nur Datum und Status eintragen, keine Befunde oder medizinischen Angaben.';
const BIRTH_YEAR_HINT = 'wird aus dem Geburtstag übernommen';

/** Kaderstatus in fachlicher Reihenfolge; steht in roster-view.js (ohne Ringabhängigkeit) und wird hier weitergegeben. */
export { ROSTER_STATUS_ORDER };

/** Beschriftungen der Filterleiste. */
const FILTER_LABELS = { ak: 'AK', birth_year: 'Jahrgang', roster_status: 'Kaderstatus', club_name: 'Verein', gender: 'm/w', active: 'Status' };

export const ISSUE_TEXT = {
  missing_birth_year: 'Jahrgang fehlt', bad_birth_year: 'Jahrgang ungültig', missing_club: 'Verein fehlt',
  unknown_club: 'Verein nicht in der Vereinsliste', bad_gender: 'm/w fehlt oder ungültig',
  missing_roster_status: 'Kaderstatus fehlt', training_site_looks_like_ak: 'Trainingsort enthält eine AK',
  duplicate_name: 'Name doppelt',
};

const SKIP_TEXT = {
  placeholder: 'Platzhalter- oder Legendenzeile', missing_last_name: 'Name fehlt', missing_first_name: 'Vorname fehlt',
  invalid: 'ungültige Werte', duplicate_in_file: 'Name doppelt in der Datei (die erste Zeile zählt)',
  ambiguous_match: 'Name in der Datenbank mehrfach vorhanden, nicht zugeordnet',
};

export function renderRoster(root, sess, tab) {
  clear(root);
  root.append(el('nav', { class: 'tabs', 'aria-label': 'Ansicht' },
    el('button', { type: 'button', 'aria-selected': String(tab === 'players'), onclick: () => { location.hash = '#/kader'; } }, 'Spieler'),
    sess.can('clubs') ? el('button', { type: 'button', 'aria-selected': String(tab === 'clubs'), onclick: () => { location.hash = '#/kader/vereine'; } }, 'Vereine') : null,
  ));
  const body = el('div');
  root.append(body);
  if (tab === 'clubs' && sess.can('clubs')) renderClubs(body, sess);
  else new PlayersView(body, sess).load();
}

class PlayersView {
  constructor(root, sess) {
    this.root = root;
    this.sess = sess;
    this.year = new Date().getFullYear();
    this.rows = [];
    // Ansicht bleibt erhalten, solange die Seite offen ist (auch nach dem Speichern); nicht dauerhaft gespeichert.
    this.filters = defaultFilters();
    this.sortKey = 'last_name';
    this.sortDir = 'asc';
    this.highlight = null;
    this.writable = new Set(sess.me.write_fields || []);
  }

  async load() {
    try {
      const res = await api('GET', '/api/players' + query({ year: this.year }));
      this.rows = res.players;
    } catch (e) {
      if (e instanceof ApiError && e.status === 401) return;
      clear(this.root).append(el('p', { class: 'msg err' }, errorText(e)));
      return;
    }
    this.render();
  }

  columns() {
    const keys = [];
    for (const r of this.rows) for (const k of Object.keys(r)) if (k !== 'id' && !keys.includes(k)) keys.push(k);
    const rank = (k) => (ORDER.includes(k) ? ORDER.indexOf(k) : ORDER.length);
    return keys.sort((a, b) => rank(a) - rank(b));
  }

  /** Angezeigte Zeilen: gefiltert, dann sortiert (Tabelle und Export). */
  visibleRows() {
    return sortRows(filterRows(this.rows, this.filters), this.sortKey, this.sortDir);
  }

  /** Filterleiste: Suche (Name, Vorname) und je lesbarer Spalte eine Auswahl mit den vorkommenden Werten. */
  filterBar() {
    this.controls = {};
    const changed = () => this.renderTable();
    const search = el('input', { type: 'search', placeholder: 'Name oder Vorname', value: this.filters.search,
      oninput: (e) => { this.filters.search = e.target.value; changed(); } });
    this.controls.search = search;
    const bar = el('div', { class: 'filters', role: 'search' },
      el('label', { dataset: { filter: 'search' } }, 'Suche', search));
    for (const key of visibleFilterKeys(this.rows)) {
      const opts = filterOptions(this.rows, key);
      const current = this.filters[key];
      if (current !== '' && !opts.some((o) => o.value === current)) {
        opts.push({ value: current, label: key === 'active' ? (current === 'true' ? 'aktiv' : 'passiv') : current });
      }
      const sel = el('select', { onchange: (e) => { this.filters[key] = e.target.value; changed(); } },
        el('option', { value: '' }, 'alle'),
        opts.map((o) => el('option', { value: o.value }, o.label)));
      sel.value = current;
      this.controls[key] = sel;
      bar.append(el('label', { dataset: { filter: key } }, FILTER_LABELS[key], sel));
    }
    bar.append(el('button', { type: 'button', onclick: () => { this.setFilters(defaultFilters()); } }, 'Filter zurücksetzen'));
    return bar;
  }

  /** Filter setzen und die Bedienelemente nachziehen (ohne den Bereich darüber neu aufzubauen). */
  setFilters(filters) {
    this.filters = filters;
    for (const [k, c] of Object.entries(this.controls || {})) c.value = filters[k] ?? '';
    this.renderTable();
  }

  sortBy(key) {
    if (this.sortKey === key) this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
    else { this.sortKey = key; this.sortDir = 'asc'; }
    this.renderTable();
  }

  render() {
    clear(this.root);
    const cols = this.columns();
    const canEdit = this.writable.size > 0;
    const yearSel = el('select', { 'aria-label': 'Jahr', onchange: (e) => { this.year = Number(e.target.value); this.load(); } },
      [-1, 0, 1, 2].map((d) => {
        const y = new Date().getFullYear() + d;
        return el('option', { value: String(y), selected: y === this.year }, String(y));
      }));
    this.countNode = el('span', { class: 'count', 'aria-live': 'polite' });
    this.root.append(el('div', { class: 'toolbar' },
      el('label', {}, 'Jahr', yearSel),
      this.countNode,
      canEdit && this.writable.has('last_name') && this.writable.has('first_name')
        ? el('button', { type: 'button', onclick: () => this.edit(null, cols) }, 'Neuer Spieler') : null,
      this.sess.canImport() ? el('button', { type: 'button', onclick: () => this.pickImport() }, 'Import') : null,
      this.sess.canImport() ? el('button', { type: 'button', onclick: () => this.showIssues() }, 'Datenprüfung') : null,
      el('button', { type: 'button', onclick: () => this.export() }, 'Export'),
    ));
    if (this.rows.length) this.root.append(this.filterBar());
    this.panel = el('div');
    this.root.append(this.panel);
    this.tableBox = el('div', { class: 'sc' });
    this.root.append(this.tableBox);
    this.renderTable(cols);
  }

  /** Spaltenkopf als Schaltfläche; ▲ ▼ und aria-sort zeigen die Sortierung. */
  head(c) {
    const active = this.sortKey === c;
    const asc = this.sortDir === 'asc';
    return el('th', { scope: 'col', 'aria-sort': active ? (asc ? 'ascending' : 'descending') : null },
      el('button', { type: 'button', class: 'sort', onclick: () => this.sortBy(c) },
        LABELS[c] || c, active ? el('span', { class: 'arrow', 'aria-hidden': 'true' }, asc ? ' ▲' : ' ▼') : null));
  }

  renderTable(cols = this.columns(), rows = this.visibleRows()) {
    this.countNode.textContent = `${rows.length} von ${this.rows.length} Spielern`;
    clear(this.tableBox);
    if (!this.rows.length) {
      this.tableBox.append(el('p', { class: 'msg' }, 'Keine Spieler vorhanden.'));
      return;
    }
    const canEdit = this.writable.size > 0;
    const table = el('table', {},
      el('thead', {}, el('tr', {}, canEdit ? el('th', {}, '') : null, cols.map((c) => this.head(c)))),
      el('tbody', {}, rows.map((r) => el('tr', {
        id: 'p' + r.id,
        class: [r.id === this.highlight ? 'hl' : '', r.active === false ? 'passive' : ''].join(' ').trim() || null,
      },
      canEdit ? el('td', {}, el('button', { type: 'button', class: 'link', onclick: () => this.edit(r, cols) }, 'Bearbeiten')) : null,
      cols.map((c) => this.cell(c, r[c]))))),
    );
    this.tableBox.append(table);
    if (this.highlight) document.getElementById('p' + this.highlight)?.scrollIntoView({ block: 'center' });
  }

  cell(key, v) {
    if (key.endsWith('_status') && LIGHT[v]) {
      const [sym, text] = LIGHT[v];
      return el('td', { title: text }, el('span', { class: 'light ' + v, 'aria-hidden': 'true' }, sym), ' ', text);
    }
    const cls = LONG.includes(key) ? 'wrap' : (typeof v === 'number' ? 'num' : null);
    return el('td', { class: cls }, display(key, v));
  }

  // ---------- Bearbeiten ----------

  edit(row, cols) {
    const isNew = row === null;
    const fields = ORDER.filter((k) => this.writable.has(k) && (isNew || cols.includes(k)));
    const inputs = {};
    const form = el('div', { class: 'form' });
    for (const k of fields) {
      const input = inputFor(k, isNew ? (k === 'active' ? true : null) : row[k]);
      inputs[k] = input;
      const label = el('label', { class: LONG.includes(k) ? 'full' : null, dataset: { field: k } }, LABELS[k] || k, input,
        el('span', { class: 'fe' }));
      if (HEALTH_NOTES.includes(k)) label.append(el('span', { class: 'warn' }, HEALTH_HINT));
      form.append(label);
    }
    // Jahrgang gesperrt, solange ein Geburtstag eingetragen ist (der Server übernimmt das Jahr).
    const yearLocked = () => Boolean(inputs.birth_date && inputs.birth_year && inputs.birth_date.value !== '');
    if (inputs.birth_date && inputs.birth_year) {
      const hint = el('span', { class: 'hint', hidden: true }, BIRTH_YEAR_HINT);
      form.querySelector('label[data-field="birth_year"]').append(hint);
      const sync = () => {
        const locked = yearLocked();
        inputs.birth_year.disabled = locked;
        hint.hidden = !locked;
        if (locked) inputs.birth_year.value = inputs.birth_date.value.slice(0, 4);
      };
      inputs.birth_date.addEventListener('input', sync);
      inputs.birth_date.addEventListener('change', sync);
      sync();
    }
    const msg = el('p', { class: 'msg err', hidden: true });
    const dlg = el('dialog', {},
      el('h2', {}, isNew ? 'Neuer Spieler' : `${row.last_name || ''}, ${row.first_name || ''}`),
      form, msg,
      el('div', { class: 'actions' },
        el('button', { type: 'button', onclick: () => { dlg.close(); } }, 'Abbrechen'),
        el('button', { type: 'button', class: 'p', onclick: () => save() }, 'Speichern')));
    const save = async () => {
      for (const l of form.querySelectorAll('label')) { l.classList.remove('bad'); l.querySelector('.fe').textContent = ''; }
      msg.hidden = true;
      const body = {};
      for (const k of fields) {
        if (k === 'birth_year' && yearLocked()) continue; // gesperrt: nicht mitschicken
        const v = readInput(k, inputs[k]);
        if (isNew ? v !== null : !same(v, row[k])) body[k] = v;
      }
      if (!isNew && !Object.keys(body).length) { dlg.close(); return; }
      try {
        if (isNew) await api('POST', '/api/players', body);
        else await api('PUT', '/api/players/' + row.id, body);
      } catch (e) {
        for (const f of (e.body && e.body.fields) || []) {
          const l = form.querySelector(`label[data-field="${CSS.escape(f)}"]`);
          if (l) { l.classList.add('bad'); l.querySelector('.fe').textContent = 'ungültig'; }
        }
        msg.textContent = errorText(e);
        msg.hidden = false;
        return;
      }
      dlg.close();
      flash(isNew ? 'Spieler angelegt.' : 'Gespeichert.');
      this.highlight = isNew ? null : row.id;
      this.load();
    };
    dlg.addEventListener('close', () => dlg.remove());
    document.body.append(dlg);
    dlg.showModal();
  }

  // ---------- Import ----------

  pickImport() {
    const input = el('input', { type: 'file', accept: '.xlsx,.xls,.xlsm', hidden: true });
    input.addEventListener('change', () => { if (input.files[0]) this.readFile(input.files[0]); input.remove(); });
    document.body.append(input);
    input.click();
  }

  async readFile(file) {
    let parsed;
    try {
      const XLSX = await loadXlsx();
      const wb = XLSX.read(await file.arrayBuffer(), { type: 'array', cellDates: false });
      parsed = parseRoster(XLSX, wb);
    } catch (e) {
      this.showPanel(el('p', { class: 'msg err' }, 'Die Datei konnte nicht gelesen werden: ' + e.message));
      return;
    }
    if (parsed.error) {
      this.showPanel(el('p', { class: 'msg err' }, parsed.error));
      return;
    }
    let preview;
    try {
      preview = await api('POST', '/api/players/import', { rows: parsed.rows, dry_run: true, year: this.year });
    } catch (e) {
      this.showPanel(el('p', { class: 'msg err' }, errorText(e)));
      return;
    }
    this.showPreview(file.name, parsed, preview);
  }

  showPreview(name, parsed, res, applied = false) {
    const excelRow = (n) => parsed.excelRows[n - 1];
    const who = (n) => {
      const r = parsed.rows[n - 1] || {};
      return [r.last_name, r.first_name].filter((x) => typeof x === 'string' && x.trim()).join(', ');
    };
    const box = el('section', { class: 'panel' },
      el('h2', {}, applied ? `Import übernommen: ${name}` : `Vorschau Import: ${name} (noch nicht gespeichert)`),
      el('p', { class: 'warn' }, 'SpoMed und Anti Doping: ' + HEALTH_HINT),
      el('div', { class: 'stats' },
        el('span', {}, el('b', {}, String(res.create)), applied ? ' angelegt' : ' neu'),
        el('span', {}, el('b', {}, String(res.update)), applied ? ' zugeordnet und aktualisiert' : ' vorhanden (werden aktualisiert)'),
        el('span', {}, el('b', {}, String(res.skipped.length)), ' übersprungen')),
      res.skipped.length ? [el('h3', {}, 'Übersprungene Zeilen'), el('ul', {}, res.skipped.map((s) => el('li', {},
        `Excel-Zeile ${excelRow(s.row)}`, who(s.row) ? ` (${who(s.row)})` : '', ': ', SKIP_TEXT[s.reason] || s.reason,
        s.fields ? ' – ' + s.fields.map((f) => LABELS[f] || f).join(', ') : '')))] : null,
      issuesList(res.issues, null),
      el('div', { class: 'actions' },
        el('button', { type: 'button', onclick: () => clear(this.panel) }, applied ? 'Schließen' : 'Abbrechen'),
        applied ? null : el('button', { type: 'button', class: 'p', onclick: async (e) => {
          e.target.disabled = true;
          try {
            const done = await api('POST', '/api/players/import', { rows: parsed.rows, dry_run: false, year: this.year });
            flash('Import übernommen.');
            await this.load();
            this.showPreview(name, parsed, done, true);
          } catch (err) {
            e.target.disabled = false;
            flash(errorText(err), 'err');
          }
        } }, 'Übernehmen')));
    this.showPanel(box);
  }

  showPanel(node) {
    clear(this.panel).append(node);
    this.panel.scrollIntoView({ block: 'start' });
  }

  // ---------- Datenprüfung ----------

  async showIssues() {
    let res;
    try {
      res = await api('GET', '/api/players/issues' + query({ year: this.year }));
    } catch (e) {
      flash(errorText(e), 'err');
      return;
    }
    this.showPanel(el('section', { class: 'panel' },
      el('h2', {}, `Datenprüfung ${this.year}: ${res.issues.length} Befunde`),
      issuesList(res.issues, (id) => this.jumpTo(id)),
      el('div', { class: 'actions' }, el('button', { type: 'button', onclick: () => clear(this.panel) }, 'Schließen'))));
  }

  /** Springt zum Spieler eines Befunds: alle Filter auf „alle“ (auch passive), damit er sichtbar ist. */
  jumpTo(id) {
    this.highlight = id;
    this.setFilters({ ...defaultFilters(), active: '' });
  }

  // ---------- Export ----------

  /** Export der aktuellen Ansicht: sichtbare Spalten, gefilterte Zeilen, aktuelle Sortierung. */
  async export(cols = this.columns()) {
    try {
      const XLSX = await loadXlsx();
      const aoa = [cols.map((c) => LABELS[c] || c)];
      for (const r of this.visibleRows()) {
        aoa.push(cols.map((c) => {
          const v = r[c];
          if (c.endsWith('_status') && LIGHT[v]) return LIGHT[v][1];
          if (typeof v === 'number') return v;
          return display(c, v);
        }));
      }
      const wb = XLSX.utils.book_new();
      XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(aoa), 'Kaderliste');
      const d = new Date();
      const stamp = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
      XLSX.writeFile(wb, `Kaderliste_${stamp}.xlsx`);
    } catch (e) {
      flash('Export fehlgeschlagen: ' + e.message, 'err');
    }
  }
}

/** Liste der Befunde; mit onPick werden Namen zu Schaltflächen, die zum Spieler springen. */
function issuesList(issues, onPick) {
  if (!issues.length) return el('p', {}, 'Keine Befunde.');
  return [el('h3', {}, 'Befunde der Datenprüfung'), el('ul', {}, issues.map((i) => el('li', {},
    onPick && i.id ? el('button', { type: 'button', class: 'link', onclick: () => onPick(i.id) }, i.player) : i.player,
    ': ', ISSUE_TEXT[i.code] || i.code, i.detail ? ` (${i.detail})` : '')))];
}

/**
 * Liest das erste Blatt mit der Kopfzeile der Kaderliste (in den ersten zehn Zeilen), prüft alle 22 Spalten und ordnet jede Zeile
 * nach COLUMN_KEYS zu. Zellwerte bleiben roh (Zahl, Text); JG kommt als Excel-Seriennummer oder
 * Text, nie als JavaScript-Datum (der Server deutet sie, ohne dass die Zeitzone den Tag verschiebt).
 */
export function parseRoster(XLSX, wb) {
  if (wb.Workbook && wb.Workbook.WBProps && wb.Workbook.WBProps.date1904) {
    return { error: 'Die Datei nutzt das Datumssystem 1904 (ältere Mac-Excel-Dateien). Bitte in Excel auf das Datumssystem 1900 umstellen und neu speichern.' };
  }
  let firstError = null;
  for (const name of wb.SheetNames) {
    const res = parseSheet(XLSX, wb.Sheets[name]);
    if (!res.error) return res;
    if (!firstError || res.headerSeen) firstError = res;
    if (res.headerSeen) break;
  }
  return firstError || { error: 'Die Datei enthält kein Blatt.' };
}

const colName = (c) => String.fromCharCode(65 + c);
const headerNorm = (v) => (v === null || v === undefined ? '' : String(v)).replace(/\s+/g, '').toLowerCase();

function parseSheet(XLSX, sheet) {
  if (!sheet || !sheet['!ref']) return { error: 'Das Blatt ist leer.' };
  // Immer ab A1 lesen, damit Spalten- und Zeilennummern denen in Excel entsprechen.
  const end = XLSX.utils.decode_range(sheet['!ref']).e;
  const all = XLSX.utils.sheet_to_json(sheet, { header: 1, raw: true, defval: null, blankrows: true,
    range: { s: { r: 0, c: 0 }, e: end } });
  const headerIdx = all.slice(0, 10).findIndex((r) => headerNorm(r[1]) === 'name' && headerNorm(r[2]) === 'vorname');
  if (headerIdx < 0) {
    return { error: 'Kopfzeile der Kaderliste nicht gefunden: In den ersten zehn Zeilen braucht Spalte B „Name“ und Spalte C „Vorname“.' };
  }
  const head = all[headerIdx];
  const wrong = [];
  HEADERS.forEach(([re, label], c) => {
    if (!re.test(headerNorm(head[c]))) wrong.push(`${colName(c)}: „${head[c] ?? ''}“ statt „${label}“`);
  });
  for (let c = HEADERS.length; c < head.length; c++) {
    if (headerNorm(head[c]) !== '') wrong.push(`${colName(c)}: zusätzliche Spalte „${head[c]}“`);
  }
  if (wrong.length) {
    return { headerSeen: true, error: `Die Spalten in Zeile ${headerIdx + 1} passen nicht zur Kaderliste (A bis V). `
      + 'Nichts wurde gesendet. Abweichungen: ' + wrong.join('; ') };
  }
  const rows = [];
  const excelRows = [];
  all.slice(headerIdx + 1).forEach((r, i) => {
    const row = {};
    let filled = false;
    COLUMN_KEYS.forEach((key, c) => {
      if (!key) return;
      let v = r[c] === undefined ? null : r[c];
      if (typeof v === 'string' && v.trim() === '') v = null;
      if (typeof v === 'boolean') v = v ? 'ja' : 'nein';
      if (v !== null) filled = true;
      row[key] = v;
    });
    if (filled) {
      rows.push(row);
      excelRows.push(headerIdx + 2 + i);
    }
  });
  if (!rows.length) return { error: 'Die Datei enthält unter der Kopfzeile keine Daten.' };
  return { rows, excelRows };
}

function display(key, v) {
  if (v === null || v === undefined) return '';
  if (key === 'active') return v ? 'aktiv' : 'passiv';
  if (key === 'birth_date') return formatDate(v);
  if (key.endsWith('_status')) return LIGHT[v] ? LIGHT[v][1] : String(v);
  return String(v);
}

function inputFor(key, v) {
  if (key === 'active') return el('select', {}, el('option', { value: '1', selected: v !== false }, 'aktiv'),
    el('option', { value: '0', selected: v === false }, 'passiv'));
  if (key === 'gender') return select(['', 'm', 'w'], v);
  if (key === 'roster_status') return select(['', ...ROSTER_STATUS_ORDER], v);
  if (key === 'birth_date') return el('input', { type: 'date', value: v || '' });
  if (key === 'list_no' || key === 'birth_year') return el('input', { type: 'text', inputmode: 'numeric', value: v ?? '' });
  if (LONG.includes(key)) return el('textarea', { rows: '3', maxlength: HEALTH_NOTES.includes(key) ? '200' : null, value: v ?? '' });
  return el('input', { type: key.startsWith('email') ? 'email' : 'text', value: v ?? '' });
}

function select(values, v) {
  const opts = values.includes(v ?? '') ? values : [...values, v];
  return el('select', {}, opts.map((o) => el('option', { value: o, selected: (v ?? '') === o }, o === '' ? '–' : o)));
}

/** Wert aus dem Formular: leer wird null, Zahlen werden Zahlen (sonst Text, die Prüfung lehnt ihn ab). */
function readInput(key, input) {
  const raw = input.value;
  if (key === 'active') return raw === '1';
  const t = raw.trim();
  if (t === '') return null;
  if (key === 'list_no' || key === 'birth_year') return /^\d+$/.test(t) ? Number(t) : t;
  return t;
}

/** Vergleich mit dem gespeicherten Wert; Zeilenumbrüche aus Excel (\r\n) gelten wie die des Formulars (\n). */
function same(a, b) {
  const n = (v) => (typeof v === 'string' ? v.replace(/\r\n?/g, '\n') : (v ?? null));
  return n(a) === n(b);
}
