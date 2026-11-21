<?php
declare(strict_types=1);

namespace App\Auth;

use App\Http\Request;
use App\Http\Response;
use App\Repo\UserRepo;
use Closure;
use InvalidArgumentException;

/**
 * Prüft vor jedem Handler Anmeldung und CSRF-Token (vom Router gefragt).
 * Reihenfolge: erst die Sitzung (401), dann aktiv (403 inactive), dann das Token (403 csrf).
 */
final class AuthGuard
{
    private Closure $clock;
    private ?User $user = null;

    /** @param (callable(): int)|null $clock aktuelle Zeit in Sekunden, Standard time() */
    public function __construct(
        private Session $session,
        private UserRepo $users,
        private int $maxAge,
        ?callable $clock = null,
    ) {
        if ($maxAge <= 0) {
            throw new InvalidArgumentException('Sitzungsdauer muss positiv sein');
        }
        $this->clock = $clock !== null ? Closure::fromCallable($clock) : static fn(): int => time();
    }

    /**
     * @param array{auth?:bool, csrf?:bool} $opts
     * @return Response|null null heißt: weiter zum Handler
     */
    public function check(Request $r, array $opts): ?Response
    {
        $this->user = null;
        if ($opts['auth'] ?? true) {
            $uid = $this->session->userId();
            if ($uid === null) {
                return Response::json(['error' => 'unauthenticated'], 401);
            }
            if ($this->session->isExpired(($this->clock)(), $this->maxAge)) {
                $this->session->logout();
                return Response::json(['error' => 'session_expired'], 401);
            }
            $user = $this->users->findById($uid);
            if ($user === null) {
                $this->session->logout();
                return Response::json(['error' => 'unauthenticated'], 401);
            }
            if (!$user->active) {
                $this->session->logout();
                return Response::json(['error' => 'inactive'], 403);
            }
            $this->user = $user;
        }
        if ($opts['csrf'] ?? false) {
            if (!$this->session->verifyCsrf((string) $r->header('X-CSRF-Token'))) {
                return Response::json(['error' => 'csrf'], 403);
            }
        }
        return null;
    }

    /** Angemeldeter Benutzer der laufenden Anfrage (nach check), sonst null. */
    public function currentUser(): ?User
    {
        return $this->user;
    }
}
