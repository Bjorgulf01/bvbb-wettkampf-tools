-- 001_core: Basisschema Etappe 1
-- Einspielen per phpMyAdmin (Import) oder mit App\Migrator. Die Datei trägt sich am Ende selbst
-- in schema_migrations ein. CREATE TABLE IF NOT EXISTS erlaubt nach einem Abbruch einen neuen Lauf.
-- Ein zweiter vollständiger Import bricht beim Selbsteintrag ab (doppelter Schlüssel) und ändert nichts.
-- Kennungen (subject, ascii_nopad_bin) und Bundesland (state, ascii_bin) vergleichen exakt.

CREATE TABLE IF NOT EXISTS schema_migrations (
    filename   VARCHAR(190) NOT NULL PRIMARY KEY,
    applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Benutzer: Identität aus dem Anmeldedienst (subject), Rollen stehen in user_roles.
CREATE TABLE IF NOT EXISTS users (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    subject    VARCHAR(190) CHARACTER SET ascii COLLATE ascii_nopad_bin NOT NULL,
    name       VARCHAR(190) NOT NULL,
    email      VARCHAR(190) NULL,
    active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_subject (subject)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roles (
    id       INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    role_key VARCHAR(40)  NOT NULL,
    UNIQUE KEY uq_roles_key (role_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_roles (
    user_id INT UNSIGNED NOT NULL,
    role_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, role_id),
    CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users (id),
    CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ein Eintrag je Rolle und Feldgruppe. write schließt read ein.
CREATE TABLE IF NOT EXISTS role_permissions (
    role_id    INT UNSIGNED NOT NULL,
    perm_group VARCHAR(60)  NOT NULL,
    mode       ENUM('read', 'write') NOT NULL,
    PRIMARY KEY (role_id, perm_group),
    CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vereine: Schlüssel ist die Mitgliedsnummer aus der Mitgliederliste (bis Task 9b noch leer erlaubt).
-- Vereine werden nie gelöscht, nur auf inaktiv gesetzt. state wird von Hand gepflegt, nie automatisch.
CREATE TABLE IF NOT EXISTS clubs (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    member_no   VARCHAR(20)  NULL,
    name        VARCHAR(190) NOT NULL,
    short       VARCHAR(20)  NOT NULL DEFAULT '',
    state       CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NULL,
    active      TINYINT(1)   NOT NULL DEFAULT 1,
    imported_at DATETIME     NULL,
    UNIQUE KEY uq_clubs_member_no (member_no),
    CONSTRAINT ck_clubs_state CHECK (state IS NULL OR state IN ('BER', 'BRA'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Schreibvarianten aus der Kaderliste, je Variante genau ein Verein.
CREATE TABLE IF NOT EXISTS club_aliases (
    club_id INT UNSIGNED NOT NULL,
    alias   VARCHAR(190) NOT NULL,
    PRIMARY KEY (club_id, alias),
    UNIQUE KEY uq_club_aliases_alias (alias),
    CONSTRAINT fk_club_aliases_club FOREIGN KEY (club_id) REFERENCES clubs (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Spieler werden nie gelöscht, nur auf passiv gesetzt (active = 0).
-- club_name ist der Originaltext aus der Kaderliste, club_id die Zuordnung (leer, wenn unbekannt).
CREATE TABLE IF NOT EXISTS players (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    last_name     VARCHAR(100) NOT NULL,
    first_name    VARCHAR(100) NOT NULL,
    club_id       INT UNSIGNED NULL,
    club_name     VARCHAR(190) NOT NULL DEFAULT '',
    list_no       INT UNSIGNED NULL,
    list_ak       VARCHAR(5)   NULL,
    gender        VARCHAR(10)  NULL,
    birth_year    SMALLINT UNSIGNED NULL,
    roster_status VARCHAR(10)  NULL,
    active        TINYINT(1)   NOT NULL DEFAULT 1,
    training_site VARCHAR(100) NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY ix_players_name (last_name, first_name),
    KEY ix_players_club (club_id),
    CONSTRAINT fk_players_club FOREIGN KEY (club_id) REFERENCES clubs (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Getrennt geschützte Daten. sportmed_note und antidoping_note sind bis Etappe 3 Freitext
-- aus der Kaderliste: nur Datum und Status, keine Befunde oder medizinischen Inhalte.
CREATE TABLE IF NOT EXISTS player_private (
    player_id       INT UNSIGNED NOT NULL PRIMARY KEY,
    birth_date      DATE         NULL,
    trainer_info    TEXT         NULL,
    school          VARCHAR(190) NULL,
    school_contact  VARCHAR(500) NULL,
    sportmed_note   TEXT         NULL,
    antidoping_note TEXT         NULL,
    email1          VARCHAR(190) NULL,
    email2          VARCHAR(190) NULL,
    phone_player    VARCHAR(50)  NULL,
    phone_mother    VARCHAR(50)  NULL,
    phone_father    VARCHAR(50)  NULL,
    shirt_size      VARCHAR(10)  NULL,
    CONSTRAINT fk_player_private_player FOREIGN KEY (player_id) REFERENCES players (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Änderungsprotokoll für Kader und Rollen, ohne Gesundheitsinhalte.
CREATE TABLE IF NOT EXISTS audit_log (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    user_id     INT UNSIGNED NULL,
    action      VARCHAR(40)  NOT NULL,
    object_type VARCHAR(40)  NOT NULL,
    object_id   INT UNSIGNED NULL,
    summary     VARCHAR(500) NOT NULL DEFAULT '',
    KEY ix_audit_object (object_type, object_id),
    KEY ix_audit_at (at),
    CONSTRAINT fk_audit_log_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (filename) VALUES ('001_core.sql');
