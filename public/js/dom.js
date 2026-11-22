// Kleine Hilfen für den Aufbau der Seite. Texte werden nur als Text eingesetzt (nie als HTML),
// damit Werte aus der Datenbank oder einer Excel-Datei keinen Code ausführen können.

/**
 * el('button', {class: 'p', onclick: fn}, 'Text', kind, …)
 * Attribute: class, for, Ereignisse (on…), dataset (Objekt), sonstige als Attribut; false/null wird weggelassen.
 */
export function el(tag, attrs = {}, ...children) {
  const node = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs || {})) {
    if (v === false || v === null || v === undefined) continue;
    if (k.startsWith('on')) {
      if (typeof v === 'function') node.addEventListener(k.slice(2), v); // Text als Ereignis wird nie gesetzt
    }
    else if (k === 'dataset') Object.assign(node.dataset, v);
    else if (k === 'value') node.value = v;
    else if (k === 'checked') node.checked = !!v;
    else if (k === 'class') node.className = v;
    else node.setAttribute(k, v === true ? '' : String(v));
  }
  append(node, children);
  return node;
}

function append(node, children) {
  for (const c of children) {
    if (c === null || c === undefined || c === false) continue;
    if (Array.isArray(c)) append(node, c);
    else node.append(c instanceof Node ? c : document.createTextNode(String(c)));
  }
}

export function clear(node) {
  while (node.firstChild) node.firstChild.remove();
  return node;
}

/** Datum JJJJ-MM-TT als TT.MM.JJJJ (ohne Zeitzone: reine Zeichenkette). */
export function formatDate(iso) {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || '');
  return m ? `${m[3]}.${m[2]}.${m[1]}` : (iso || '');
}

/** Zeitstempel in UTC (ISO 8601) als TT.MM.JJJJ HH:MM in deutscher Zeit. */
export function formatDateTime(isoUtc) {
  const d = new Date(isoUtc);
  if (Number.isNaN(d.getTime())) return isoUtc || '';
  const p = new Intl.DateTimeFormat('de-DE', {
    timeZone: 'Europe/Berlin', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
  }).formatToParts(d);
  const v = Object.fromEntries(p.map((x) => [x.type, x.value]));
  return `${v.day}.${v.month}.${v.year} ${v.hour}:${v.minute}`;
}

/** Kurze Meldung oben auf der Seite. */
export function flash(text, kind = 'ok') {
  const box = document.getElementById('flash');
  box.textContent = text;
  box.className = 'msg ' + kind;
  box.hidden = false;
  clearTimeout(flash.timer);
  flash.timer = setTimeout(() => { box.hidden = true; }, kind === 'err' ? 8000 : 4000);
}

/** Lädt SheetJS (lokale Kopie in vendor/) erst, wenn Import oder Export es brauchen. */
export function loadXlsx() {
  if (window.XLSX) return Promise.resolve(window.XLSX);
  if (!loadXlsx.promise) {
    loadXlsx.promise = new Promise((resolve, reject) => {
      const s = document.createElement('script');
      s.src = 'vendor/xlsx.full.min.js';
      s.onload = () => (window.XLSX ? resolve(window.XLSX) : reject(new Error('SheetJS fehlt')));
      s.onerror = () => { loadXlsx.promise = null; reject(new Error('SheetJS konnte nicht geladen werden')); };
      document.head.append(s);
    });
  }
  return loadXlsx.promise;
}
