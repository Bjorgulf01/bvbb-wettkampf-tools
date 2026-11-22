<?php
declare(strict_types=1);

namespace App\Api;

use App\Auth\AuthGuard;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Rbac\Permissions;
use App\Repo\AuditRepo;
use PDO;

/**
 * Änderungsprotokoll lesen (Leserecht audit):
 *  GET /api/audit?limit=N  {entries:[{id, at, user_id, user_name, action, object_type, object_id, summary}]}
 * Neueste zuerst, limit 1 bis 1000 (Standard 100). at ist ISO 8601 in UTC.
 * Die Einträge nennen nur Feldnamen und Rollenschlüssel, nie Werte oder Gesundheitsinhalte.
 */
final class AuditController
{
    private const DEFAULT_LIMIT = 100;
    private const MAX_LIMIT = 1000;

    private AuditRepo $audit;

    public function __construct(
        PDO $pdo,
        private Permissions $permissions,
        private AuthGuard $guard,
    ) {
        $this->audit = new AuditRepo($pdo);
    }

    public function routes(Router $router): void
    {
        $router->add('GET', '/api/audit', $this->list(...));
    }

    private function list(Request $r, array $params): Response
    {
        if (!$this->permissions->can($this->guard->currentUser()?->roles ?? [], 'audit', Permissions::READ)) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $limit = self::DEFAULT_LIMIT;
        if (array_key_exists('limit', $r->query)) {
            $raw = $r->query['limit'];
            if (!is_string($raw) || preg_match('/^[1-9]\d{0,3}$/', $raw) !== 1 || (int) $raw > self::MAX_LIMIT) {
                return Response::json(['error' => 'invalid', 'fields' => ['limit']], 422);
            }
            $limit = (int) $raw;
        }
        return Response::json(['entries' => $this->audit->recent($limit)]);
    }
}
