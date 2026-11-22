// Start der Oberfläche: Anmeldung prüfen, Startseite mit Kacheln nach Rolle, Navigation über #/….
// Was angezeigt wird, richtet sich nach GET /api/me. Geprüft werden die Rechte trotzdem bei jeder
// Anfrage auf dem Server; die Oberfläche blendet nur aus, was ohnehin abgelehnt würde.
import { api, setCsrf, setUnauthorizedHandler, errorText } from './api.js';
import { el, clear, flash } from './dom.js';
import { renderLogin, roleName } from './login.js';
import { renderRoster } from './roster.js';
import { renderAdmin } from './admin.js';

const root = document.getElementById('app');
let me = null;

/** Rechte aus /api/me: can('clubs', 'write'). */
function can(group, mode = 'read') {
  const m = me && me.permissions ? me.permissions[group] : undefined;
  return m === 'write' || (m === 'read' && mode === 'read');
}

function session() {
  return {
    me,
    can,
    canImport: () => ['player.basic', 'player.roster', 'player.private'].every((g) => can(g, 'write')),
  };
}

async function start() {
  try {
    me = await api('GET', '/api/me');
  } catch (e) {
    me = null;
    if (e.status === 401) return; // die Anmeldeseite zeigt schon der Handler aus api.js
    clear(root).append(el('p', { class: 'msg err' }, errorText(e)));
    return;
  }
  setCsrf(me.csrf);
  document.getElementById('bar').hidden = false;
  document.getElementById('who').textContent =
    me.name + ' · ' + (me.roles.length ? me.roles.map(roleName).join(', ') : 'ohne Rolle');
  route();
}

function showLogin() {
  me = null;
  setCsrf(null);
  for (const d of document.querySelectorAll('dialog')) d.close(); // offene Formulare nicht über der Anmeldeseite lassen
  document.getElementById('bar').hidden = true;
  if (location.hash) history.replaceState(null, '', location.pathname);
  renderLogin(root, start);
}

const TILES = [
  { key: 'tournaments', icon: '€', title: 'Turniere', note: 'folgt' },
  { key: 'roster', icon: '☰', title: 'Kaderliste', hash: '#/kader', show: () => can('player.basic') },
  { key: 'year', icon: '∑', title: 'Jahresübersicht', note: 'folgt' },
  { key: 'health', icon: '✚', title: 'NADA/Sportmedizin', note: 'folgt' },
  { key: 'admin', icon: '⚙', title: 'Verwaltung', hash: '#/verwaltung', show: () => can('users', 'write') || can('audit') },
];

function renderHome() {
  setTitle('Wettkampf-Tools');
  const active = TILES.filter((t) => t.hash && t.show());
  clear(root).append(el('section', { class: 'hw' },
    el('h1', {}, 'Wettkampf-Tools'),
    el('p', {}, 'Badminton-Verband Berlin-Brandenburg e.V.'),
    me.roles.length ? null : el('p', { class: 'msg' }, 'Keine Rolle zugewiesen. Bitte bei der Verwaltung melden.'),
    el('div', { class: 'tiles' }, TILES.map((t) => {
      const on = active.includes(t);
      return el('button', {
        type: 'button', class: 'tile', disabled: !on,
        onclick: on ? () => { location.hash = t.hash; } : null,
      }, el('span', { class: 'ti', 'aria-hidden': 'true' }, t.icon), el('b', {}, t.title),
      el('small', {}, on ? (t.key === 'admin' && !can('users', 'write') ? 'Protokoll' : 'öffnen') : (t.note || 'kein Zugriff')));
    })),
  ));
}

function setTitle(text) {
  document.getElementById('bar-title').textContent = text;
  document.title = text + ' · BVBB';
}

function route() {
  if (!me) return;
  const h = location.hash;
  if (h.startsWith('#/kader') && can('player.basic')) {
    setTitle('Kaderliste');
    renderRoster(root, session(), h.startsWith('#/kader/vereine') ? 'clubs' : 'players');
  } else if (h === '#/verwaltung' && (can('users', 'write') || can('audit'))) {
    setTitle('Verwaltung');
    renderAdmin(root, session());
  } else {
    renderHome();
  }
}

setUnauthorizedHandler(showLogin);
window.addEventListener('hashchange', route);
document.getElementById('nav-home').addEventListener('click', () => {
  if (location.hash) location.hash = ''; // löst route() aus
  else renderHome();
});

/**
 * Abmelden. Die Anmeldeseite erscheint erst, wenn der Server die Sitzung beendet hat. Ist das Token
 * veraltet (zum Beispiel nach einer Anmeldung in einem anderen Tab), wird es einmal neu geholt.
 */
document.getElementById('logout').addEventListener('click', async () => {
  try {
    await api('POST', '/api/auth/logout');
  } catch (e) {
    if (e.status === 401) return; // keine Sitzung mehr: Anmeldeseite ist schon da
    try {
      setCsrf((await api('GET', '/api/me')).csrf);
      await api('POST', '/api/auth/logout');
    } catch (e2) {
      if (e2.status === 401) return;
      flash('Abmelden fehlgeschlagen: ' + errorText(e2) + ' Bitte das Browserfenster schließen.', 'err');
      return;
    }
  }
  showLogin();
});
start();
