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

    /**
     * Neueste Einträge zuerst, mit dem Namen der handelnden Person (null bei Einträgen des Systems).
     * at ist ISO 8601 in UTC (die Verbindung rechnet in UTC, siehe Db::connect).
     * @return list<array{id:int, at:string, user_id:?int, user_name:?string, action:string,
     *     object_type:string, object_id:?int, summary:string}>
     */
    public function recent(int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.at, a.user_id, u.name AS user_name, a.action, a.object_type, a.object_id, a.summary
             FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
             ORDER BY a.id DESC LIMIT ?'
        );
        $stmt->bindValue(1, max(0, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return array_map(static fn(array $r): array => [
            'id' => (int) $r['id'],
            'at' => str_replace(' ', 'T', (string) $r['at']) . 'Z',
            'user_id' => $r['user_id'] !== null ? (int) $r['user_id'] : null,
            'user_name' => $r['user_name'] !== null ? (string) $r['user_name'] : null,
            'action' => (string) $r['action'],
            'object_type' => (string) $r['object_type'],
            'object_id' => $r['object_id'] !== null ? (int) $r['object_id'] : null,
            'summary' => (string) $r['summary'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
