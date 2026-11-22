// Verwaltung: Benutzer und Rollen (Schreibrecht users, nur admin) und Änderungsprotokoll (Leserecht audit).
import { api, errorText, ApiError } from './api.js';
import { el, clear, flash, formatDateTime } from './dom.js';
import { roleName } from './login.js';

const ACTIONS = {
  create: 'angelegt', update: 'geändert', import_create: 'beim Import angelegt', import_update: 'beim Import geändert',
  user_created: 'Person angelegt (erste Anmeldung)', roles: 'Rollen geändert', activate: 'aktiviert', deactivate: 'deaktiviert',
};
const OBJECTS = { player: 'Spieler', club: 'Verein', user: 'Person' };

export async function renderAdmin(root, sess) {
  clear(root);
  const usersBox = el('div');
  const auditBox = el('div');
  root.append(usersBox, auditBox);
  if (sess.can('users', 'write')) await renderUsers(usersBox, sess);
  if (sess.can('audit')) await renderAudit(auditBox);
}

async function renderUsers(box, sess) {
  let data;
  try {
    data = await api('GET', '/api/users');
  } catch (e) {
    if (!(e instanceof ApiError && e.status === 401)) clear(box).append(el('p', { class: 'msg err' }, errorText(e)));
    return;
  }
  const reload = () => renderUsers(box, sess);
  clear(box).append(el('section', { class: 'panel' },
    el('h2', {}, 'Benutzer und Rollen'),
    el('p', {}, 'Name und E-Mail kommen aus dem Anmeldedienst. Personen werden nicht gelöscht, nur deaktiviert.'),
    el('div', { class: 'sc' }, el('table', { class: 'users' },
      el('thead', {}, el('tr', {}, ['Name', 'E-Mail', 'Rollen', 'Status', ''].map((h) => el('th', {}, h)))),
      el('tbody', {}, data.users.map((u) => {
        const boxes = data.role_keys.map((k) => el('input', { type: 'checkbox', value: k, checked: u.roles.includes(k) }));
        const saveRoles = async () => {
          const roles = boxes.filter((b) => b.checked).map((b) => b.value);
          try {
            await api('PUT', `/api/users/${u.id}/roles`, { roles });
          } catch (e) {
            flash(errorText(e), 'err');
            reload();
            return;
          }
          // Eigene Rollen geändert: Seite neu laden, damit Kacheln und Rechte stimmen.
          if (u.id === sess.me.id) { location.reload(); return; }
          flash('Rollen gespeichert.');
          reload();
        };
        const toggle = async () => {
          try {
            await api('PUT', `/api/users/${u.id}`, { active: !u.active });
          } catch (e) {
            flash(errorText(e), 'err');
            reload();
            return;
          }
          if (u.id === sess.me.id) { location.reload(); return; } // selbst deaktiviert: Sitzung ist beendet
          flash(u.active ? 'Person deaktiviert.' : 'Person aktiviert.');
          reload();
        };
        return el('tr', { class: u.active ? null : 'passive' },
          el('td', {}, u.name, u.id === sess.me.id ? ' (ich)' : ''),
          el('td', {}, u.email || ''),
          el('td', {}, el('div', { class: 'roles' }, boxes.map((b, i) => el('label', {}, b, roleName(data.role_keys[i])))),
            el('button', { type: 'button', onclick: saveRoles }, 'Rollen speichern')),
          el('td', {}, u.active ? 'aktiv' : 'inaktiv'),
          el('td', {}, el('button', { type: 'button', onclick: toggle }, u.active ? 'Deaktivieren' : 'Aktivieren')));
      })))),
  ));
}

async function renderAudit(box) {
  let entries;
  try {
    entries = (await api('GET', '/api/audit?limit=200')).entries;
  } catch (e) {
    if (!(e instanceof ApiError && e.status === 401)) clear(box).append(el('p', { class: 'msg err' }, errorText(e)));
    return;
  }
  clear(box).append(el('section', { class: 'panel' },
    el('h2', {}, 'Änderungsprotokoll (neueste 200)'),
    el('p', {}, 'Das Protokoll nennt nur Feldnamen und Rollen, keine Werte und keine Gesundheitsinhalte.'),
    el('div', { class: 'sc' }, el('table', {},
      el('thead', {}, el('tr', {}, ['Zeit', 'Wer', 'Was', 'Objekt', 'Details'].map((h) => el('th', {}, h)))),
      el('tbody', {}, entries.map((a) => el('tr', {},
        el('td', {}, formatDateTime(a.at)),
        el('td', {}, a.user_name || 'System'),
        el('td', {}, ACTIONS[a.action] || a.action),
        el('td', {}, `${OBJECTS[a.object_type] || a.object_type} ${a.object_id ?? ''}`.trim()),
        el('td', { class: 'wrap' }, a.summary))))))));
}
