// Ansicht der Kaderliste: Sortieren, Filtern und die Werte der Auswahllisten als reine Funktionen
// (keine DOM-Zugriffe). Alles läuft im Browser über die schon geladene Liste; der Server liefert nur
// Felder, die die Rolle lesen darf, deshalb gibt es auch nur Filter für diese Felder.

/** Kaderstatus in fachlicher Reihenfolge (Auswahl und Sortierung). roster.js exportiert ihn weiter. */
export const ROSTER_STATUS_ORDER = ['NK1', 'NK2', 'LK', 'SK'];

/** Altersklassen in fachlicher Reihenfolge. */
const AK_ORDER = ['U09', 'U11', 'U13', 'U15', 'U17', 'U19', 'O19'];

/** Wert der Auswahl „(leer)“. */
export const EMPTY = '__empty__';

/** Filter der Leiste in ihrer Reihenfolge; die Suche kommt davor. */
export const FILTER_KEYS = ['ak', 'birth_year', 'roster_status', 'club_name', 'gender', 'active'];

const NUMERIC = ['birth_year', 'list_no'];

/** Standard: alles anzeigen außer passiven Spielern. '' heißt „alle“. */
export function defaultFilters() {
  return { search: '', ak: '', birth_year: '', roster_status: '', club_name: '', gender: '', active: 'true' };
}

function isEmpty(v) {
  return v === null || v === undefined || (typeof v === 'string' && v.trim() === '');
}

/** Wert einer Zeile als Wert der Auswahlliste. */
function filterValue(key, v) {
  if (isEmpty(v)) return EMPTY;
  if (key === 'active') return v ? 'true' : 'false';
  return String(v);
}

function rankIn(order, v) {
  const i = order.indexOf(v);
  return i < 0 ? order.length : i;
}

/** Vergleich zweier nicht leerer Werte einer Spalte. */
export function compareValues(key, a, b) {
  if (key === 'ak' || key === 'roster_status') {
    const order = key === 'ak' ? AK_ORDER : ROSTER_STATUS_ORDER;
    const d = rankIn(order, a) - rankIn(order, b);
    if (d !== 0) return d;
  }
  if (NUMERIC.includes(key) || (typeof a === 'number' && typeof b === 'number')) {
    const d = Number(a) - Number(b);
    if (!Number.isNaN(d) && d !== 0) return d;
  }
  if (key === 'birth_date') return String(a) < String(b) ? -1 : (String(a) > String(b) ? 1 : 0);
  if (typeof a === 'boolean' && typeof b === 'boolean') return a === b ? 0 : (a ? -1 : 1); // aktiv vor passiv
  return String(a).localeCompare(String(b), 'de', { sensitivity: 'base' });
}

const byName = (a, b) => compareValues('last_name', a.last_name ?? '', b.last_name ?? '')
  || compareValues('first_name', a.first_name ?? '', b.first_name ?? '')
  || (Number(a.id) || 0) - (Number(b.id) || 0);

/** Neue, sortierte Liste. Leere Werte stehen in beiden Richtungen am Ende; Gleichstand: Name, Vorname, id. */
export function sortRows(rows, key, dir) {
  const sign = dir === 'desc' ? -1 : 1;
  return [...rows].sort((a, b) => {
    const ea = isEmpty(a[key]);
    const eb = isEmpty(b[key]);
    if (ea !== eb) return ea ? 1 : -1;
    const d = ea ? 0 : sign * compareValues(key, a[key], b[key]);
    return d || byName(a, b);
  });
}

/** Zeilen, auf die alle gesetzten Filter zutreffen. Filter auf Schlüssel, die eine Zeile nicht hat, gelten nicht. */
export function filterRows(rows, filters) {
  const q = (filters.search || '').trim().toLocaleLowerCase('de-DE');
  return rows.filter((r) => {
    if (q && !['last_name', 'first_name'].some((k) => typeof r[k] === 'string' && r[k].toLocaleLowerCase('de-DE').includes(q))) {
      return false;
    }
    return FILTER_KEYS.every((k) => {
      const f = filters[k];
      if (f === '' || f === undefined || !(k in r)) return true;
      return filterValue(k, r[k]) === f;
    });
  });
}

/** Vorkommende Werte einer Spalte ohne „alle“, sortiert wie beim Sortieren, „(leer)“ am Ende. */
export function filterOptions(rows, key) {
  const seen = new Map();
  let hasEmpty = false;
  for (const r of rows) {
    if (!(key in r)) continue;
    if (isEmpty(r[key])) { hasEmpty = true; continue; }
    const value = filterValue(key, r[key]);
    if (!seen.has(value)) seen.set(value, r[key]);
  }
  const opts = [...seen.entries()]
    .sort(([, a], [, b]) => compareValues(key, a, b))
    .map(([value, raw]) => ({ value, label: key === 'active' ? (raw ? 'aktiv' : 'passiv') : String(raw) }));
  if (hasEmpty) opts.push({ value: EMPTY, label: '(leer)' });
  return opts;
}

/** Filter, deren Spalte in den Zeilen vorkommt (also für diese Rolle lesbar ist). */
export function visibleFilterKeys(rows) {
  return FILTER_KEYS.filter((k) => rows.some((r) => k in r));
}
