<?php
declare(strict_types=1);

namespace App\Api;

use App\Auth\AuthGuard;
use App\Auth\User;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Rbac\Permissions;
use App\Repo\AuditRepo;
use App\Repo\UserRepo;
use PDO;
use PDOException;
use Throwable;

/**
 * Benutzerverwaltung, alle Endpunkte nur mit Schreibrecht users:
 *  GET /api/users             {users:[{id, name, email, active, roles}], role_keys:[…]}
 *  PUT /api/users/{id}/roles  {roles:[…]}   ersetzt die Rollen
 *  PUT /api/users/{id}        {active:bool} aktiv oder inaktiv
 * Antwort der PUT-Aufrufe: {user:{…}}. Kein DELETE: Personen werden nie gelöscht.
 * Name und E-Mail kommen aus dem Anmeldedienst und sind hier nicht schreibbar; die Kennung
 * des Anmeldedienstes wird nicht ausgeliefert.
 * Der letzte aktive admin kann die Rolle admin nicht verlieren und nicht deaktiviert werden (409).
 */
final class UsersController
{
    private const MAX_ROLES = 20;

    private UserRepo $users;
    private AuditRepo $audit;

    public function __construct(
        private PDO $pdo,
        private Permissions $permissions,
        private AuthGuard $guard,
    ) {
        $this->users = new UserRepo($pdo);
        $this->audit = new AuditRepo($pdo);
    }

    public function routes(Router $router): void
    {
        $router->add('GET', '/api/users', $this->list(...));
        $router->add('PUT', '/api/users/{id}/roles', $this->setRoles(...));
        $router->add('PUT', '/api/users/{id}', $this->setActive(...));
    }

    private function list(Request $r, array $params): Response
    {
        if (!$this->allowed()) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        return Response::json([
            'users' => array_map(self::describe(...), $this->users->all()),
            'role_keys' => $this->users->roleKeys(),
        ]);
    }

    private function setRoles(Request $r, array $params): Response
    {
        if (!$this->allowed()) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $id = self::id($params['id'] ?? '');
        $body = $r->json;
        if (($error = self::checkKeys($body, 'roles')) !== null) {
            return $error;
        }
        $roles = $body['roles'];
        if (!is_array($roles) || !array_is_list($roles) || count($roles) > self::MAX_ROLES
            || array_filter($roles, static fn(mixed $k): bool => is_string($k) && $k !== ''
                && mb_check_encoding($k, 'UTF-8') && mb_strlen($k, 'UTF-8') <= 40) !== $roles) {
            return Response::json(['error' => 'invalid', 'fields' => ['roles']], 422);
        }
        $roles = array_values(array_unique($roles));
        sort($roles, SORT_STRING);
        $unknown = array_values(array_diff($roles, $this->users->roleKeys()));
        if ($unknown !== []) {
            return Response::json(['error' => 'unknown_role', 'roles' => $unknown], 422);
        }

        return $this->change($id, function (User $target, array $admins) use ($roles): ?string {
            $current = $target->roles;
            sort($current, SORT_STRING); // gleiche Sortierung wie $roles, nicht die der Datenbank
            if ($current === $roles) {
                return null;
            }
            if (in_array('admin', $current, true) && !in_array('admin', $roles, true)
                && self::isLastActiveAdmin($target, $admins)) {
                return 'last_admin';
            }
            $this->users->setRoles($target->id, $roles);
            $this->audit->log($this->guard->currentUser()?->id, 'roles', 'user', $target->id,
                'roles: ' . ($roles === [] ? '(keine)' : implode(', ', $roles)));
            return null;
        });
    }

    private function setActive(Request $r, array $params): Response
    {
        if (!$this->allowed()) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $id = self::id($params['id'] ?? '');
        $body = $r->json;
        if (($error = self::checkKeys($body, 'active')) !== null) {
            return $error;
        }
        $active = $body['active'];
        if (!is_bool($active)) {
            return Response::json(['error' => 'invalid', 'fields' => ['active']], 422);
        }

        return $this->change($id, function (User $target, array $admins) use ($active): ?string {
            if ($target->active === $active) {
                return null;
            }
            if (!$active && in_array('admin', $target->roles, true) && self::isLastActiveAdmin($target, $admins)) {
                return 'last_admin';
            }
            $this->users->setActive($target->id, $active);
            $this->audit->log($this->guard->currentUser()?->id, $active ? 'activate' : 'deactivate', 'user', $target->id, '');
            return null;
        });
    }

    /**
     * Führt die Änderung in einer Transaktion aus, bei einem Deadlock höchstens einmal neu.
     * Gegen gleichzeitige Änderungen, die beide den letzten admin entfernen, sperrt jede Änderung
     * zuerst die Zeile der Rolle admin (eine einzige Sperre, deshalb keine Sperrkette über mehrere
     * Tabellen). Die Transaktion läuft mit READ COMMITTED: Die Lesezugriffe nach der Sperre sehen den
     * zuletzt gespeicherten Stand und nicht einen älteren Schnappschuss.
     * @param callable(User, list<int>): ?string $fn bekommt die Person und die Ids der aktiven admins;
     *     liefert einen Konfliktcode (409, alles zurückgerollt) oder null
     */
    private function change(?int $id, callable $fn): Response
    {
        for ($attempt = 1; ; $attempt++) {
            $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED'); // gilt nur für die nächste Transaktion
            $this->pdo->beginTransaction();
            try {
                $this->users->lockAdminRole();
                $target = $id !== null ? $this->users->findById($id) : null;
                if ($target === null) {
                    $this->pdo->rollBack();
                    return Response::json(['error' => 'not_found'], 404);
                }
                $conflict = $fn($target, $this->users->activeAdminIds());
                if ($conflict !== null) {
                    $this->pdo->rollBack();
                    return Response::json(['error' => $conflict], 409);
                }
                $this->pdo->commit();
                break;
            } catch (PDOException $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                if ($e->getCode() !== '40001' || $attempt >= 2) {
                    throw $e; // 40001: Deadlock, die Datenbank hat die Transaktion abgebrochen
                }
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            }
        }
        $user = $this->users->findById($target->id) ?? throw new \LogicException('Benutzer verschwunden');
        return Response::json(['user' => self::describe($user)]);
    }

    /** @param list<int> $admins Ids der aktiven admins, unter der Sperre gelesen */
    private static function isLastActiveAdmin(User $target, array $admins): bool
    {
        return $target->active && array_diff($admins, [$target->id]) === [];
    }

    /**
     * Der Body muss genau das eine Feld enthalten. Andere Felder (name, email, subject, …) sind
     * nicht schreibbar und ergeben 403 für die ganze Anfrage, ein fehlendes Feld 422.
     */
    private static function checkKeys(mixed $body, string $key): ?Response
    {
        if (!is_array($body) || $body !== [] && array_is_list($body)) {
            return Response::json(['error' => 'invalid_body'], 422);
        }
        $denied = array_values(array_map('strval', array_diff(array_keys($body), [$key])));
        if ($denied !== []) {
            return Response::json(['error' => 'forbidden', 'fields' => $denied], 403);
        }
        if (!array_key_exists($key, $body)) {
            return Response::json(['error' => 'invalid', 'fields' => [$key]], 422);
        }
        return null;
    }

    /** @return array{id:int, name:string, email:?string, active:bool, roles:list<string>} */
    private static function describe(User $u): array
    {
        return ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'active' => $u->active, 'roles' => array_values($u->roles)];
    }

    private function allowed(): bool
    {
        return $this->permissions->can($this->guard->currentUser()?->roles ?? [], 'users', Permissions::WRITE);
    }

    private static function id(string $raw): ?int
    {
        return preg_match('/^[1-9]\d{0,9}$/', $raw) === 1 && (int) $raw <= 4294967295 ? (int) $raw : null;
    }
}
