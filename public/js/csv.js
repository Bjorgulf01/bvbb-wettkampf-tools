// CSV lesen (nuLiga-Export „Vereine (Stammdaten und Adressen)“), ohne DOM-Zugriffe.
// Übernommen werden nur die sieben benötigten Spalten. Bank-, Adress- und Kontaktspalten verlassen den Browser nie.

/** Spalte des nuLiga-Exports je Feld der Schnittstelle (gefunden über den Namen in der Kopfzeile). */
export const NULIGA_CLUB_COLUMNS = {
  verein_nr: 'VereinNr',
  dbv_nr: 'DBVNr',
  name: 'VereinName',
  short_name: 'VereinKurzName',
  print_name: 'VereinDruckname',
  status: 'Status',
  is_federation: 'Verein ist Verband, Bezirk oder Kreis bzw. Region',
};

/** Diese Felder bleiben leer als '' (alle anderen werden leer zu null). */
const KEEP_EMPTY = ['verein_nr', 'name', 'status'];

/** Bytes als Text: zuerst UTF-8 (streng), sonst Windows-1252 (so exportiert nuLiga). Ein BOM am Anfang wird entfernt. */
export function decodeText(buffer) {
  let text;
  try {
    text = new TextDecoder('utf-8', { fatal: true }).decode(buffer);
  } catch {
    text = new TextDecoder('windows-1252').decode(buffer);
  }
  return text.charCodeAt(0) === 0xFEFF ? text.slice(1) : text;
}

/**
 * CSV-Text als Tabelle. Felder in Anführungszeichen dürfen Trennzeichen, Zeilenumbrüche und "" enthalten.
 * CRLF und LF; völlig leere Zeilen werden übergangen. Alle Werte bleiben Text.
 */
export function parseCsv(text, delimiter = ';') {
  const rows = [];
  let row = [];
  let field = '';
  let quoted = false;
  let fieldStarted = false; // auch ein leeres Feld in Anführungszeichen ("") zählt als Inhalt
  const endField = () => { row.push(field); field = ''; fieldStarted = false; };
  const endRow = () => {
    endField();
    if (row.length > 1 || row[0] !== '') rows.push(row);
    row = [];
  };
  for (let i = 0; i < text.length; i++) {
    const ch = text[i];
    if (quoted) {
      if (ch !== '"') field += ch;
      else if (text[i + 1] === '"') { field += '"'; i++; }
      else quoted = false;
    } else if (ch === '"' && field === '' && !fieldStarted) {
      quoted = true;
      fieldStarted = true;
    } else if (ch === delimiter) {
      endField();
    } else if (ch === '\r' || ch === '\n') {
      if (ch === '\r' && text[i + 1] === '\n') i++;
      endRow();
    } else {
      field += ch;
    }
  }
  if (field !== '' || fieldStarted || row.length) endRow();
  return rows;
}

/**
 * Tabelle (erste Zeile = Kopfzeile) als Zeilen für POST /api/clubs/import.
 * rows: je Datenzeile genau die Schlüssel aus NULIGA_CLUB_COLUMNS; is_federation ist true bei „1“ oder „ja“.
 * lines: Zeilennummer je Datenzeile, wie Excel sie beim Öffnen der Datei zeigt (Kopfzeile = 1).
 * missing: fehlende Spalten (dann sind rows und lines leer).
 */
export function mapClubRows(table) {
  const head = (table[0] || []).map((h) => h.trim());
  const at = {};
  const missing = [];
  for (const [key, col] of Object.entries(NULIGA_CLUB_COLUMNS)) {
    at[key] = head.indexOf(col);
    if (at[key] < 0) missing.push(col);
  }
  if (missing.length) return { rows: [], lines: [], missing };
  const rows = table.slice(1).map((cells) => {
    const row = {};
    for (const key of Object.keys(NULIGA_CLUB_COLUMNS)) {
      const v = (cells[at[key]] ?? '').trim();
      if (key === 'is_federation') row[key] = v === '1' || v.toLowerCase() === 'ja';
      else row[key] = v === '' && !KEEP_EMPTY.includes(key) ? null : v;
    }
    return row;
  });
  return { rows, lines: rows.map((_, i) => i + 2), missing };
}
