-- 003_birth_year_from_date: Jahrgang einmal an den Geburtstag angleichen („Kaderliste rund machen“, Task 2).
-- Einspielen per phpMyAdmin (Import) oder mit App\Migrator. Die Datei trägt sich am Ende selbst ein.
-- Nur Datenänderungen, läuft deshalb vollständig in einer Transaktion. Schreibt keinen Eintrag ins
-- Änderungsprotokoll (vermerkt in docs/STATUS.md). Ein versehentlicher zweiter Import bricht beim
-- Selbsteintrag mit einem Fehler (doppelter Eintrag) ab und ändert nichts.

UPDATE players p
JOIN player_private pp ON pp.player_id = p.id
SET p.birth_year = YEAR(pp.birth_date)
WHERE pp.birth_date IS NOT NULL
  AND (p.birth_year IS NULL OR p.birth_year <> YEAR(pp.birth_date));

INSERT INTO schema_migrations (filename) VALUES ('003_birth_year_from_date.sql');
