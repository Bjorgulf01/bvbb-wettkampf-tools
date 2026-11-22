// Anmeldeseite. Erscheint nur ohne Sitzung (GET /api/me ergibt 401). Die Liste der Testpersonen gibt
// es nur, wenn der Server die Testanmeldung eingeschaltet hat; sonst liefert er 404.
import { api, ApiError, errorText } from './api.js';
import { el, clear } from './dom.js';

const ROLE_NAMES = {
  admin: 'Administrator', roster_editor: 'Kaderpflege', health_staff: 'NADA/Sportmedizin',
  reviewer: 'Prüfer', coach: 'Trainer', basic: 'Basis',
};

export function roleName(key) {
  return ROLE_NAMES[key] || key;
}

export async function renderLogin(root, onLoggedIn) {
  clear(root);
  const box = el('section', { class: 'login' }, el('h1', {}, 'BVBB Wettkampf-Tools'));
  root.append(box);
  let personas = null;
  try {
    personas = (await api('GET', '/api/auth/test-personas')).personas;
  } catch (e) {
    if (!(e instanceof ApiError && e.status === 404)) {
      box.append(el('p', { class: 'msg err' }, errorText(e)));
      return;
    }
  }
  if (!personas) {
    box.append(el('p', {}, 'Die Anmeldung über den Anmeldedienst folgt. Eine Testanmeldung ist auf diesem Server nicht eingeschaltet.'));
    return;
  }
  const status = el('p', { class: 'msg err', hidden: true });
  box.append(
    el('p', { class: 'warn' }, 'Testanmeldung: nur mit erfundenen Daten verwenden.'),
    el('p', { class: 'note' }, 'Als Testperson anmelden:'),
    el('div', { class: 'personas' }, personas.map((p) => el('button', {
      type: 'button',
      onclick: async () => {
        status.hidden = true;
        try {
          await api('POST', '/api/auth/test-login', { person: p.key });
          onLoggedIn();
        } catch (e) {
          status.textContent = errorText(e);
          status.hidden = false;
        }
      },
    }, el('span', {}, p.name), el('small', {}, p.roles.length ? p.roles.map(roleName).join(', ') : 'ohne Rolle')))),
    status,
  );
}
