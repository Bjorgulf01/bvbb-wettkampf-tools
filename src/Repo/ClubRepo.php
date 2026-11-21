<?php
declare(strict_types=1);

namespace App\Repo;

use App\Domain\ClubDirectory;
use PDO;

/** Vereine. Vereine werden nie gelöscht, nur auf inaktiv gesetzt. */
final class ClubRepo
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Aktive Vereine mit Aliasnamen als Verzeichnis für die Zuordnung des Vereinstexts.
     * Inaktive Vereine werden nicht mehr neu zugeordnet; ihr Text gilt dann als unbekannt.
     */
    public function directory(): ClubDirectory
    {
        $clubs = $this->pdo->query('SELECT id, member_no, name, short, state FROM clubs WHERE active = 1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $aliases = [];
        foreach ($this->pdo->query('SELECT club_id, alias FROM club_aliases')->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $aliases[(int) $a['club_id']][] = (string) $a['alias'];
        }
        $list = [];
        foreach ($clubs as $c) {
            $c['aliases'] = $aliases[(int) $c['id']] ?? [];
            $list[] = $c;
        }
        return new ClubDirectory($list);
    }
}
