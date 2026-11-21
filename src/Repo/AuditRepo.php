<?php
declare(strict_types=1);

namespace App\Repo;

use PDO;

/**
 * Änderungsprotokoll. Die Zusammenfassung nennt nur Feldnamen oder Rollenschlüssel, nie Werte,
 * und nie Gesundheitsinhalte.
 */
final class AuditRepo
{
    public function __construct(private PDO $pdo)
    {
    }

    public function log(?int $userId, string $action, string $objectType, int $objectId, string $summary): void
    {
        $this->pdo->prepare(
            'INSERT INTO audit_log (user_id, action, object_type, object_id, summary) VALUES (?, ?, ?, ?, ?)'
        )->execute([$userId, $action, $objectType, $objectId, mb_substr($summary, 0, 500, 'UTF-8')]);
    }
}
