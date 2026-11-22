// Zugriff auf die Schnittstelle. Setzt das CSRF-Token bei allen ändernden Aufrufen und meldet 401
// an die Startlogik (zurück zur Anmeldeseite). Fehler kommen als ApiError mit Status und Antwort.

let csrf = null;
let onUnauthorized = () => {};

export class ApiError extends Error {
  constructor(status, body) {
    super(`HTTP ${status}${body && body.error ? ': ' + body.error : ''}`);
    this.status = status;
    this.body = body || {};
  }
}

export function setCsrf(token) {
  csrf = token;
}

export function setUnauthorizedHandler(fn) {
  onUnauthorized = fn;
}

export async function api(method, path, body) {
  const headers = { Accept: 'application/json' };
  const opts = { method, headers, credentials: 'same-origin', cache: 'no-store' };
  if (body !== undefined) {
    headers['Content-Type'] = 'application/json';
    opts.body = JSON.stringify(body);
  }
  if (method !== 'GET' && csrf) {
    headers['X-CSRF-Token'] = csrf;
  }
  let res;
  try {
    res = await fetch(path, opts);
  } catch (e) {
    throw new ApiError(0, { error: 'network' });
  }
  let data = null;
  try {
    data = await res.json();
  } catch (e) {
    data = null;
  }
  if (res.status === 401) {
    onUnauthorized();
    throw new ApiError(401, data);
  }
  if (!res.ok) {
    throw new ApiError(res.status, data);
  }
  return data;
}

/** Abfrageteil einer Adresse aus einem Objekt (Werte werden kodiert). */
export function query(params) {
  const q = new URLSearchParams();
  for (const [k, v] of Object.entries(params)) {
    if (v !== undefined && v !== null && v !== '') q.set(k, String(v));
  }
  const s = q.toString();
  return s ? '?' + s : '';
}

/** Verständliche Meldung zu einem Fehler der Schnittstelle. */
export function errorText(e) {
  if (!(e instanceof ApiError)) return 'Unerwarteter Fehler im Browser.';
  const code = e.body.error;
  const texts = {
    network: 'Keine Verbindung zum Server.',
    forbidden: 'Dafür fehlt das Recht.',
    csrf: 'Sicherheitsprüfung fehlgeschlagen. Bitte die Seite neu laden.',
    inactive: 'Dieses Konto ist inaktiv.',
    not_found: 'Nicht gefunden.',
    invalid: 'Ungültige Eingabe.',
    invalid_body: 'Ungültige Anfrage.',
    last_admin: 'Der letzte aktive admin kann die Rolle admin nicht abgeben und nicht deaktiviert werden.',
    unknown_role: 'Unbekannte Rolle.',
    duplicate_name: 'Dieser Vereinsname kommt schon vor.',
    duplicate_alias: 'Dieser Aliasname gehört schon zu einem Verein.',
    too_many_rows: 'Die Datei hat zu viele Zeilen (höchstens 3000).',
    too_large: 'Die Datei ist zu groß.',
    server_error: 'Fehler auf dem Server.',
    session_expired: 'Die Sitzung ist abgelaufen. Bitte neu anmelden.',
    unauthenticated: 'Nicht angemeldet. Bitte neu anmelden.',
  };
  let text = texts[code] || `Fehler (${e.status}).`;
  if (Array.isArray(e.body.fields) && e.body.fields.length) {
    text += ' Felder: ' + e.body.fields.join(', ');
  }
  return text;
}
