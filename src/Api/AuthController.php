<?php
declare(strict_types=1);

namespace App\Api;

use App\Auth\AuthGuard;
use App\Auth\Session;
use App\Auth\TestAuthProvider;
use App\Auth\User;
use App\Config;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Repo\UserRepo;
use Closure;

/**
 * Anmeldung, Abmeldung und eigene Angaben.
 *  POST /api/auth/test-login  {person}  nur registriert, wenn ein Test-Anbieter übergeben wird
 *  POST /api/auth/logout      mit CSRF-Token
 *  GET  /api/me               {id, name, roles, csrf}
 * Die Person mit first_admin_subject bekommt beim ersten Anlegen zusätzlich die Rolle admin.
 */
final class AuthController
{
    private Closure $clock;

    public function __construct(
        private Session $session,
        private UserRepo $users,
        private AuthGuard $guard,
        private ?TestAuthProvider $testAuth,
        private Config $config,
        ?callable $clock = null,
    ) {
        $this->clock = $clock !== null ? Closure::fromCallable($clock) : static fn(): int => time();
    }

    public function routes(Router $router): void
    {
        if ($this->testAuth !== null && $this->config->testAuthEnabled()) {
            // Ohne Sitzung und Token erreichbar; Schutz gegen fremde Seiten: nur Content-Type application/json.
            $router->add('POST', '/api/auth/test-login', $this->testLogin(...), ['auth' => false, 'csrf' => false]);
        }
        // Das Token prüft der Handler selbst: Ohne Sitzung (zum Beispiel abgelaufen) ist das Abmelden schon erledigt.
        $router->add('POST', '/api/auth/logout', $this->logout(...), ['auth' => false, 'csrf' => false]);
        $router->add('GET', '/api/me', $this->me(...));
    }

    private function testLogin(Request $r, array $params): Response
    {
        $identity = $this->testAuth?->identify($r);
        if ($identity === null) {
            return Response::json(['error' => 'unknown_person'], 400);
        }
        $roles = $this->testAuth->rolesOf($identity);
        $firstAdmin = $this->config->get('first_admin_subject');
        if (is_string($firstAdmin) && $firstAdmin !== '' && hash_equals($firstAdmin, $identity->subject)) {
            $roles[] = 'admin';
        }
        $user = $this->users->upsert($identity, $roles);
        if (!$user->active) {
            $this->session->logout();
            return Response::json(['error' => 'inactive'], 403);
        }
        $this->session->login($user->id, ($this->clock)());
        return Response::json($this->describe($user));
    }

    private function logout(Request $r, array $params): Response
    {
        if ($this->session->userId() !== null && !$this->session->verifyCsrf((string) $r->header('X-CSRF-Token'))) {
            return Response::json(['error' => 'csrf'], 403);
        }
        $this->session->logout();
        return Response::json(['ok' => true]);
    }

    private function me(Request $r, array $params): Response
    {
        $user = $this->guard->currentUser();
        if ($user === null) {
            return Response::json(['error' => 'unauthenticated'], 401);
        }
        return Response::json($this->describe($user));
    }

    /** @return array{id:int, name:string, roles:list<string>, csrf:string} */
    private function describe(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'roles' => array_values($user->roles),
            'csrf' => $this->session->csrfToken(),
        ];
    }
}
