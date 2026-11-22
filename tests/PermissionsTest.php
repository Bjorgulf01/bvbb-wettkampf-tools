<?php
declare(strict_types=1);

use App\Migrator;
use App\Rbac\Permissions;

require_once __DIR__ . '/support/db.php';

/** Rechte-Matrix aus Task 2 und Task 4b, wie sie der Seed in die Datenbank schreibt. */
$grants = static function (): array {
    $matrix = [
        'admin'         => ['player.basic' => 'write', 'player.roster' => 'write', 'player.private' => 'write', 'player.health_light' => 'read', 'clubs' => 'write', 'users' => 'write', 'audit' => 'read'],
        'roster_editor' => ['player.basic' => 'write', 'player.roster' => 'write', 'player.private' => 'write', 'player.health_light' => 'read', 'clubs' => 'write'],
        'health_staff'  => ['player.basic' => 'read', 'player.roster' => 'read', 'player.health_light' => 'read', 'clubs' => 'read'],
        'reviewer'      => ['player.basic' => 'read', 'player.roster' => 'read', 'player.health_light' => 'read', 'clubs' => 'read', 'audit' => 'read'],
        'coach'         => ['player.basic' => 'read', 'player.roster' => 'read', 'player.health_light' => 'read', 'clubs' => 'read'],
        'basic'         => ['player.basic' => 'read', 'clubs' => 'read'],
    ];
    $out = [];
    foreach ($matrix as $role => $groups) {
        foreach ($groups as $group => $mode) {
            $out[] = ['role' => $role, 'group' => $group, 'mode' => $mode];
        }
    }
    return $out;
};

$p = static fn(): Permissions => new Permissions($grants());

$basic = ['last_name', 'first_name', 'club_name', 'club_short'];
$roster = ['list_no', 'list_ak', 'gender', 'birth_year', 'roster_status', 'active', 'training_site', 'state'];
$light = ['sportmed_status', 'antidoping_status'];
$private = ['birth_date', 'trainer_info', 'school', 'school_contact', 'sportmed_note', 'antidoping_note',
    'email1', 'email2', 'phone_player', 'phone_mother', 'phone_father', 'shirt_size'];

return [
    'coach liest einfache und erweiterte Daten und die Ampel, nichts Privates' => function () use ($p, $basic, $roster, $light, $private): void {
        $read = $p()->fieldsFor(['coach'], 'read');
        assertSame(array_merge($basic, $roster, $light), $read);
        foreach ($private as $f) {
            assertTrue(!in_array($f, $read, true), 'coach darf ' . $f . ' nicht lesen');
        }
        assertSame([], $p()->fieldsFor(['coach'], 'write'), 'coach schreibt nichts');
    },
    'basic liest nur einfache Stammdaten' => function () use ($p, $basic): void {
        assertSame($basic, $p()->fieldsFor(['basic'], 'read'));
        assertSame(false, $p()->can(['basic'], 'player.health_light', 'read'), 'keine Ampel für basic');
    },
    'roster_editor schreibt private Daten' => function () use ($p, $private): void {
        $write = $p()->fieldsFor(['roster_editor'], 'write');
        foreach ($private as $f) {
            assertTrue(in_array($f, $write, true), $f);
        }
        assertSame(true, $p()->can(['roster_editor'], 'player.private', 'write'));
        assertSame(true, $p()->can(['roster_editor'], 'player.private', 'read'), 'Schreiben schließt Lesen ein');
    },
    'mehrere Rollen ergeben die Vereinigung' => function () use ($p, $basic, $roster, $light, $private): void {
        $read = $p()->fieldsFor(['coach', 'roster_editor'], 'read');
        assertTrue(in_array('email1', $read, true), 'email1 über roster_editor');
        assertSame(array_merge($basic, $roster, $light, $private), $read, 'jedes Feld genau einmal, feste Reihenfolge');
        assertSame($read, $p()->fieldsFor(['roster_editor', 'coach'], 'read'), 'Reihenfolge der Rollen egal');
        assertSame(true, $p()->can(['basic', 'reviewer'], 'audit', 'read'));
    },
    'keine oder unbekannte Rolle ergibt nichts, ohne Ausnahme' => function () use ($p): void {
        assertSame([], $p()->fieldsFor([], 'read'));
        assertSame([], $p()->fieldsFor(['unknown'], 'read'));
        assertSame([], $p()->fieldsFor(['unknown'], 'write'));
        assertSame(false, $p()->can([], 'player.basic', 'read'));
        assertSame(false, $p()->can(['unknown'], 'player.basic', 'read'));
        assertSame([], (new Permissions([]))->fieldsFor(['admin'], 'read'));
    },
    'can für Bereiche ohne Felder' => function () use ($p): void {
        assertSame(true, $p()->can(['reviewer'], 'audit', 'read'));
        assertSame(false, $p()->can(['coach'], 'users', 'read'));
        assertSame(true, $p()->can(['admin'], 'users', 'write'));
        assertSame(false, $p()->can(['reviewer'], 'audit', 'write'));
        assertSame(false, $p()->can(['admin'], 'gibt_es_nicht', 'read'));
    },
    'Ampel und abgeleitete Felder sind nie schreibbar' => function () use ($p, $light): void {
        $write = $p()->fieldsFor(['admin', 'roster_editor'], 'write');
        foreach (array_merge($light, ['club_short', 'state']) as $f) {
            assertTrue(!in_array($f, $write, true), $f . ' darf nicht schreibbar sein');
        }
        assertTrue(in_array('club_name', $write, true));
        assertTrue(in_array('sportmed_note', $write, true), 'Rohtext bleibt für roster_editor schreibbar');
        // Auch ein versehentlich gesetztes Schreibrecht in der Datenbank öffnet die Ampel nicht.
        $q = new Permissions([['role' => 'admin', 'group' => 'player.health_light', 'mode' => 'write']]);
        assertSame(false, $q->can(['admin'], 'player.health_light', 'write'));
        assertSame([], $q->fieldsFor(['admin'], 'write'));
        assertSame($light, $q->fieldsFor(['admin'], 'read'), 'Lesen bleibt');
    },
    'ungültiger Modus wird abgelehnt oder ignoriert' => function () use ($p): void {
        assertThrows(fn() => $p()->fieldsFor(['admin'], 'delete'), InvalidArgumentException::class);
        assertThrows(fn() => $p()->can(['admin'], 'player.basic', 'WRITE'), InvalidArgumentException::class);
        $q = new Permissions([['role' => 'coach', 'group' => 'player.private', 'mode' => 'all']]);
        assertSame([], $q->fieldsFor(['coach'], 'read'), 'unbekannter Modus in den Rechten gibt nichts frei');
    },
    'deniedFields nennt nicht erlaubte und unbekannte Felder (Mass Assignment)' => function () use ($p): void {
        $perm = $p();
        assertSame([], $perm->deniedFields(['roster_editor'], ['last_name', 'email1', 'birth_year'], 'write'));
        assertSame(['state', 'club_short'], $perm->deniedFields(['roster_editor'], ['last_name', 'state', 'club_short'], 'write'), 'abgeleitete Felder');
        assertSame(['sportmed_status'], $perm->deniedFields(['admin'], ['sportmed_status'], 'write'), 'Ampel nie schreibbar');
        assertSame(['email1'], $perm->deniedFields(['coach'], ['last_name', 'email1'], 'read'));
        assertSame(['last_name'], $perm->deniedFields(['coach'], ['last_name'], 'write'));
        assertSame(['id', 'club_id', 'role'], $perm->deniedFields(['admin'], ['id', 'club_id', 'role'], 'write'), 'unbekannte Felder');
        assertSame(['last_name'], $perm->deniedFields([], ['last_name'], 'read'), 'ohne Rolle');
        assertSame([], $perm->deniedFields(['coach'], [], 'write'));
    },
    'fehlerhafte Rechtezeilen und Rollenschlüssel geben nichts frei' => function (): void {
        $q = new Permissions([
            ['group' => 'player.private', 'mode' => 'write'],
            ['role' => '', 'group' => 'player.private', 'mode' => 'write'],
            ['role' => 'coach', 'group' => '', 'mode' => 'read'],
            ['role' => 'coach', 'mode' => 'read'],
            'kein Array',
        ]);
        assertSame([], $q->fieldsFor([''], 'read'));
        assertSame([], $q->fieldsFor(['coach'], 'read'));
        $r = new Permissions([['role' => 'admin', 'group' => 'player.basic', 'mode' => 'read']]);
        assertSame([], $r->fieldsFor([['admin'], null, 5], 'read'), 'Rollen, die keine Zeichenketten sind, werden ignoriert');
        assertSame(false, $r->can([['admin']], 'player.basic', 'read'));
    },
    'groupsFor: höchster Modus je Gruppe, Vereinigung mehrerer Rollen, nach Gruppe sortiert' => function () use ($p): void {
        assertSame(['clubs' => 'read', 'player.basic' => 'read'], $p()->groupsFor(['basic']));
        assertSame(['clubs' => 'write', 'player.basic' => 'write', 'player.health_light' => 'read',
            'player.private' => 'write', 'player.roster' => 'write'], $p()->groupsFor(['coach', 'roster_editor']));
        assertSame([], $p()->groupsFor([]));
        assertSame([], $p()->groupsFor(['unbekannt', 5]));
    },
    'Feldgruppen überschneiden sich nicht' => function (): void {
        $all = array_merge(...array_values(Permissions::FIELD_GROUPS));
        assertSame(count($all), count(array_unique($all)));
    },
    'fromDb liest die Rechte aus dem Seed' => function () use ($p): void {
        $pdo = testPdo();
        (new Migrator($pdo, __DIR__ . '/../migrations'))->migrate();
        $db = Permissions::fromDb($pdo);
        foreach (['admin', 'roster_editor', 'health_staff', 'reviewer', 'coach', 'basic'] as $role) {
            foreach (['read', 'write'] as $mode) {
                assertSame($p()->fieldsFor([$role], $mode), $db->fieldsFor([$role], $mode), $role . ' ' . $mode);
            }
        }
        assertSame(true, $db->can(['reviewer'], 'audit', 'read'));
    },
];
