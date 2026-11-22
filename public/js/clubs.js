// Reiter „Vereine“: einfache Vereinsliste bis zum Import der Mitgliederliste (Task 9b).
// Lesen mit Leserecht clubs, Ändern und Anlegen mit Schreibrecht clubs. Vereine werden nie gelöscht,
// nur auf inaktiv gesetzt. Gespeichert werden nur geänderte Einträge (PUT /api/clubs).
import { api, errorText, ApiError } from './api.js';
import { el, clear, flash } from './dom.js';

const STATES = [['', '–'], ['BER', 'Berlin'], ['BRA', 'Brandenburg']];

export async function renderClubs(root, sess) {
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

  const addRow = (c) => {
    if (!canWrite) {
      tbody.append(el('tr', {}, el('td', {}, c.name), el('td', {}, c.short), el('td', {}, stateName(c.state)),
        el('td', {}, c.active ? 'aktiv' : 'inaktiv'), el('td', {}, c.aliases.join(', ')), el('td', {}, c.member_no || '')));
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
    tbody.append(el('tr', {}, el('td', {}, inputs.name), el('td', {}, inputs.short), el('td', {}, inputs.state),
      el('td', {}, el('label', {}, inputs.active, ' aktiv')), el('td', {}, inputs.aliases), el('td', {}, c.member_no || '')));
    return inputs;
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
      canWrite ? el('button', { type: 'button', class: 'p', onclick: save }, 'Speichern') : null),
    canWrite ? el('p', { class: 'msg' }, 'Aliasnamen mit Semikolon trennen. Vereine werden nicht gelöscht, sondern auf inaktiv gesetzt. '
      + 'Das Bundesland entscheidet, welches Amt fördert, und wird nie automatisch gesetzt.') : null,
    msg,
    el('div', { class: 'sc' }, el('table', { class: 'clubs' },
      el('thead', {}, el('tr', {}, ['Name', 'Kürzel', 'Bundesland', 'Status', 'Aliasnamen', 'Mitgliedsnummer'].map((h) => el('th', {}, h)))),
      tbody)),
  );
}

function stateName(s) {
  return (STATES.find(([v]) => v === (s || '')) || [null, s])[1];
}
