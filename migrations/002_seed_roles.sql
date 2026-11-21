-- 002_seed_roles: Rollen und Rechte nach Entwurf Abschnitt 6 (Plan Task 2 und Task 4b).
-- Einspielen per phpMyAdmin (Import) oder mit App\Migrator. Die Datei trägt sich am Ende selbst ein.
-- Die Datei enthält nur Datenänderungen und läuft deshalb vollständig in einer Transaktion.
-- write schließt read ein. Fehlt eine Zeile, hat die Rolle keinen Zugriff auf die Gruppe.

INSERT INTO roles (role_key) VALUES
    ('admin'),
    ('roster_editor'),
    ('health_staff'),
    ('reviewer'),
    ('coach'),
    ('basic');

INSERT INTO role_permissions (role_id, perm_group, mode)
SELECT r.id, g.perm_group, g.mode
FROM roles r
JOIN (
              SELECT 'admin'         AS role_key, 'player.basic'        AS perm_group, 'write' AS mode
    UNION ALL SELECT 'admin',         'player.roster',       'write'
    UNION ALL SELECT 'admin',         'player.private',      'write'
    UNION ALL SELECT 'admin',         'player.health_light', 'read'
    UNION ALL SELECT 'admin',         'clubs',               'write'
    UNION ALL SELECT 'admin',         'users',               'write'
    UNION ALL SELECT 'admin',         'audit',               'read'

    UNION ALL SELECT 'roster_editor', 'player.basic',        'write'
    UNION ALL SELECT 'roster_editor', 'player.roster',       'write'
    UNION ALL SELECT 'roster_editor', 'player.private',      'write'
    UNION ALL SELECT 'roster_editor', 'player.health_light', 'read'
    UNION ALL SELECT 'roster_editor', 'clubs',               'write'

    UNION ALL SELECT 'health_staff',  'player.basic',        'read'
    UNION ALL SELECT 'health_staff',  'player.roster',       'read'
    UNION ALL SELECT 'health_staff',  'player.health_light', 'read'
    UNION ALL SELECT 'health_staff',  'clubs',               'read'

    UNION ALL SELECT 'reviewer',      'player.basic',        'read'
    UNION ALL SELECT 'reviewer',      'player.roster',       'read'
    UNION ALL SELECT 'reviewer',      'player.health_light', 'read'
    UNION ALL SELECT 'reviewer',      'clubs',               'read'
    UNION ALL SELECT 'reviewer',      'audit',               'read'

    UNION ALL SELECT 'coach',         'player.basic',        'read'
    UNION ALL SELECT 'coach',         'player.roster',       'read'
    UNION ALL SELECT 'coach',         'player.health_light', 'read'
    UNION ALL SELECT 'coach',         'clubs',               'read'

    UNION ALL SELECT 'basic',         'player.basic',        'read'
    UNION ALL SELECT 'basic',         'clubs',               'read'
) g ON g.role_key = r.role_key;

INSERT INTO schema_migrations (filename) VALUES ('002_seed_roles.sql');
