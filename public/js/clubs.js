// Reiter „Vereine“: Vereinsliste, von Hand gepflegt und per Import aus nuLiga ergänzt (Task 9b).
// Lesen mit Leserecht clubs, Ändern, Anlegen und Import mit Schreibrecht clubs. Vereine werden nie gelöscht,
// nur auf inaktiv gesetzt. Gespeichert werden nur geänderte Einträge (PUT /api/clubs).
import { api, errorText, ApiError } from './api.js';
import { el, clear, flash } from './dom.js';
import { decodeText, parseCsv, mapClubRows } from './csv.js';

const STATES = [['', '–'], ['BER', 'Berlin'], ['BRA', 'Brandenburg']];

const SKIP_TEXT = {
  federation: 'Verband, nicht importiert',
  missing_name: 'Name fehlt',
  bad_verein_nr: 'Vereinsnummer ungültig',
  member_no_mismatch: 'DBV-Nummer passt nicht zur Vereinsnummer',
  duplicate_in_file: 'doppelt in der Datei',
  bad_status: 'Status weder „aktiv“ noch „Archiv“',
  ambiguous_name: 'mehrere Vereine ohne Mitgliedsnummer mit diesem Namen',
  duplicate_name: 'Name gehört schon einem anderen Verein',
};

const WARN_TEXT = {
  renamed: (d) => `umbenannt: ${d}`,
  member_no_derived: (d) => `DBV-Nummer aus Vereinsnummer gebildet: ${d}`,
  alias_conflict: (d) => `Alias „${d}“ gehört schon einem anderen Verein, nicht übernommen`,
  linked_by_name: (d) => `über den Namen mit „${d}“ verknüpft`,
  name_matches_alias: (d) => `der Name ist Kürzel oder Alias von „${d}“; Spieler mit diesem Vereinstext wechseln zu diesem Verein`,
};

/** Spieler, deren Zuordnung sich durch den Import ändert (vom Server berechnet), als Warnung. */
function playersNote(p, applied) {
  if (!p || (!p.unlinked && !p.moved)) return null;
  const parts = [];
  if (p.unlinked) {
    parts.push(applied
      ? `${p.unlinked} Spieler haben durch den Import ihren Verein verloren (Datenprüfung: „Verein nicht in der Vereinsliste“).`
      : `${p.unlinked} Spieler verlieren durch den Import ihren Verein (Datenprüfung: „Verein nicht in der Vereinsliste“).`);
  }
  if (p.moved) {
    parts.push(applied
      ? `${p.moved} Spieler sind zu einem anderen Verein gewechselt.`
      : `${p.moved} Spieler wechseln durch den Import zu einem anderen Verein.`);
  }
  return el('p', { class: 'warn' }, parts.join(' '));
}

/** imported: nach dem Übernehmen {file, parsed, res}, damit das Ergebnis über der neuen Liste stehen bleibt. */
export async function renderClubs(root, sess, imported = null) {
  let clubs;
  try {
    clubs = (await api('GET', '/api/clubs')).clubs;
  } catch (e) {
    if (e instanceof ApiError && e.status === 401) return;
    clear(root).append(el('p', { class: 'msg err' }, errorText(e)));
    return;
  }
  const canWrite = sess.can('clubs', 'write');
  clear(root);
  const tbody = el('tbody');
  const entries = []; // {orig, inputs}
  const msg = el('p', { class: 'msg err', hidden: true });

  const trs = []; // {club, tr} für den Filter „nur ohne Bundesland“
  const addRow = (c) => {
    if (!canWrite) {
      const tr = el('tr', {}, el('td', {}, c.name), el('td', {}, c.short), el('td', {}, stateName(c.state)),
        el('td', {}, c.active ? 'aktiv' : 'inaktiv'), el('td', {}, c.aliases.join(', ')), el('td', {}, c.member_no || ''));
      trs.push({ club: c, tr });
      tbody.append(tr);
      return;
    }
    const inputs = {
      name: el('input', { type: 'text', value: c.name, 'aria-label': 'Name' }),
      short: el('input', { type: 'text', value: c.short, maxlength: '20', 'aria-label': 'Kürzel' }),
      state: el('select', { 'aria-label': 'Bundesland' }, STATES.map(([v, t]) => el('option', { value: v, selected: c.state === v }, t))),
      active: el('input', { type: 'checkbox', checked: c.active, 'aria-label': 'aktiv' }),
      aliases: el('input', { type: 'text', value: c.aliases.join('; '), 'aria-label': 'Aliasnamen' }),
    };
    entries.push({ orig: c, inputs });
    const tr = el('tr', {}, el('td', {}, inputs.name), el('td', {}, inputs.short), el('td', {}, inputs.state),
      el('td', {}, el('label', {}, inputs.active, ' aktiv')), el('td', {}, inputs.aliases), el('td', {}, c.member_no || ''));
    if (c.id !== undefined) trs.push({ club: c, tr });
    tbody.append(tr);
    return inputs;
  };

  // Hinweis und Filter: aktive Vereine ohne Bundesland (gespeicherter Stand).
  const noState = (c) => c.active && !c.state;
  const missingState = clubs.filter(noState).length;
  const onlyNoState = el('input', { type: 'checkbox', onchange: () => {
    for (const { club, tr } of trs) tr.hidden = onlyNoState.checked && !noState(club);
  } });
  const stateHint = missingState
    ? el('p', { class: 'msg clubs-state-hint' }, `Bei ${missingState} aktiven Vereinen fehlt das Bundesland. `,
      el('label', {}, onlyNoState, ' nur ohne Bundesland'))
    : null;

  // Import aus nuLiga: Datei lesen, Vorschau (dry_run), dann Übernehmen oder Abbrechen.
  const importBox = el('div', { class: 'clubs-import' });
  const showImport = (node) => clear(importBox).append(node);
  const showResult = (file, parsed, res, applied) => {
    const line = (n) => parsed.lines[n - 1];
    const who = (n) => (parsed.rows[n - 1] || {}).name || '';
    const at = (n) => `Zeile ${line(n)}${who(n) ? ` (${who(n)})` : ''}: `;
    const box = el('section', { class: 'panel' },
      el('h2', {}, applied ? `Vereinsimport übernommen: ${file}` : `Vorschau Vereinsimport: ${file} (noch nicht gespeichert)`),
      el('div', { class: 'stats' },
        el('span', {}, el('b', {}, String(res.create)), ' neu'),
        el('span', {}, el('b', {}, String(res.update)), ' geändert'),
        el('span', {}, el('b', {}, String(res.unchanged)), ' unverändert'),
        el('span', {}, el('b', {}, String(res.skipped.length)), ' übersprungen')),
      playersNote(res.players, applied),
      res.skipped.length ? [el('h3', {}, 'Übersprungene Zeilen'),
        el('ul', {}, res.skipped.map((s) => el('li', {}, at(s.row) + (SKIP_TEXT[s.reason] || s.reason))))] : null,
      res.warnings.length ? [el('h3', {}, 'Hinweise'),
        el('ul', {}, res.warnings.map((w) => el('li', {}, at(w.row) + (WARN_TEXT[w.code] ? WARN_TEXT[w.code](w.detail) : w.code))))] : null,
      el('p', { class: 'msg' }, 'Kürzel und Bundesland ändert der Import nicht. Bank- und Adressdaten werden nicht übertragen.'),
      el('div', { class: 'actions' },
        el('button', { type: 'button', onclick: () => clear(importBox) }, applied ? 'Schließen' : 'Abbrechen'),
        applied ? null : el('button', { type: 'button', class: 'p', onclick: async (e) => {
          e.target.disabled = true;
          try {
            const done = await api('POST', '/api/clubs/import', { rows: parsed.rows, dry_run: false });
            flash('Vereinsimport übernommen.');
            renderClubs(root, sess, { file, parsed, res: done });
          } catch (err) {
            e.target.disabled = false;
            flash(errorText(err), 'err');
          }
        } }, 'Übernehmen')));
    showImport(box);
    box.scrollIntoView({ block: 'start' });
  };
  const readImport = async (file) => {
    let parsed;
    try {
      parsed = mapClubRows(parseCsv(decodeText(await file.arrayBuffer())));
    } catch (e) {
      showImport(el('p', { class: 'msg err' }, 'Die Datei konnte nicht gelesen werden: ' + e.message));
      return;
    }
    if (parsed.missing.length) {
      showImport(el('p', { class: 'msg err' }, parsed.missing.map((c) => `Spalte ${c} fehlt.`).join(' ')
        + ' Erwartet wird der nuLiga-Export „Vereine (Stammdaten und Adressen)“ als CSV.'));
      return;
    }
    if (!parsed.rows.length) {
      showImport(el('p', { class: 'msg err' }, 'Die Datei enthält keine Vereine.'));
      return;
    }
    try {
      showResult(file.name, parsed, await api('POST', '/api/clubs/import', { rows: parsed.rows, dry_run: true }), false);
    } catch (e) {
      showImport(el('p', { class: 'msg err' }, errorText(e)));
    }
  };
  const pickImport = () => {
    const input = el('input', { type: 'file', accept: '.csv,text/csv', hidden: true });
    input.addEventListener('change', () => { if (input.files[0]) readImport(input.files[0]); input.remove(); });
    document.body.append(input);
    input.click();
  };

  clubs.forEach(addRow);

  const save = async () => {
    msg.hidden = true;
    const changes = [];
    const rowOf = []; // Index in changes => Eintrag in der Tabelle (für Fehlermeldungen)
    for (const entry of entries) {
      const { orig, inputs } = entry;
      rowOf[changes.length] = entry;
      const now = {
        name: inputs.name.value.trim(),
        short: inputs.short.value.trim(),
        state: inputs.state.value,
        active: inputs.active.checked,
        aliases: inputs.aliases.value.split(';').map((a) => a.trim()).filter(Boolean),
      };
      if (orig.id === undefined) {
        if (now.name) changes.push(now);
        continue;
      }
      const diff = { id: orig.id };
      for (const k of ['name', 'short', 'state', 'active']) if (now[k] !== orig[k]) diff[k] = now[k];
      if (now.aliases.join('\n') !== orig.aliases.join('\n')) diff.aliases = now.aliases;
      if (Object.keys(diff).length > 1) changes.push(diff);
    }
    if (!changes.length) { flash('Keine Änderungen.'); return; }
    try {
      const res = await api('PUT', '/api/clubs', { clubs: changes });
      flash(`Gespeichert: ${res.created} neu, ${res.updated} geändert.`);
      renderClubs(root, sess);
    } catch (e) {
      // Pfade wie clubs.2.state auf den Verein und die Spalte zurückführen, Feld markieren.
      const names = { name: 'Name', short: 'Kürzel', state: 'Bundesland', active: 'Status', aliases: 'Aliasnamen', id: 'Verein' };
      const where = [];
      for (const f of (e.body && e.body.fields) || []) {
        const m = /^clubs\.(\d+)\.(\w+)$/.exec(f);
        const entry = m && rowOf[Number(m[1])];
        if (!entry) continue;
        const input = entry.inputs[m[2]] || entry.inputs.name;
        input.classList.add('bad');
        input.setAttribute('aria-invalid', 'true');
        where.push(`${entry.inputs.name.value.trim() || 'neuer Verein'}: ${names[m[2]] || m[2]}`);
      }
      msg.textContent = where.length
        ? errorText(new ApiError(e.status, { error: e.body.error })) + ' Betroffen: ' + where.join('; ')
        : errorText(e);
      msg.hidden = false;
    }
  };

  root.append(
    el('div', { class: 'toolbar' },
      el('span', { class: 'count' }, `${clubs.length} Vereine`),
      canWrite ? el('button', { type: 'button', onclick: () => {
        addRow({ name: '', short: '', state: '', active: true, aliases: [], member_no: null }).name.focus();
      } }, 'Verein hinzufügen') : null,
      canWrite ? el('button', { type: 'button', class: 'p', onclick: save }, 'Speichern') : null,
      canWrite ? el('button', { type: 'button', onclick: pickImport }, 'Vereine aus nuLiga importieren') : null),
    canWrite ? el('p', { class: 'msg' }, 'Aliasnamen mit Semikolon trennen. Vereine werden nicht gelöscht, sondern auf inaktiv gesetzt. '
      + 'Das Bundesland entscheidet, welches Amt fördert, und wird nie automatisch gesetzt.') : null,
    canWrite ? importBox : null,
    canWrite ? stateHint : null,
    msg,
    el('div', { class: 'sc' }, el('table', { class: 'clubs' },
      el('thead', {}, el('tr', {}, ['Name', 'Kürzel', 'Bundesland', 'Status', 'Aliasnamen', 'Mitgliedsnummer'].map((h) => el('th', {}, h)))),
      tbody)),
  );
  if (imported && canWrite) showResult(imported.file, imported.parsed, imported.res, true);
}

function stateName(s) {
  return (STATES.find(([v]) => v === (s || '')) || [null, s])[1];
}
