<?php
declare(strict_types=1);

namespace App\Auth;

use Closure;

/**
 * Sitzungsdaten über einem Speicher (in Betrieb $_SESSION, in Tests ein Array).
 * Die Sitzung läuft ab der Anmeldung höchstens maxAge Sekunden (keine Verlängerung durch Aktivität).
 * Bei An- und Abmeldung wird die Sitzungs-ID erneuert (Schutz gegen Session Fixation), bei der
 * Anmeldung entsteht außerdem ein neues CSRF-Token.
 */
final class Session
{
    private Closure $renewId;

    /**
     * @param array<string, mixed> $store
     * @param (callable(): void)|null $renewId erneuert die Sitzungs-ID; Standard: session_regenerate_id(true),
     *        wenn eine PHP-Sitzung läuft
     */
    public function __construct(private array &$store, ?callable $renewId = null)
    {
        $this->renewId = $renewId !== null ? Closure::fromCallable($renewId) : static function (): void {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
        };
    }

    public function userId(): ?int
    {
        $uid = $this->store['uid'] ?? null;
        return is_int($uid) && $uid > 0 ? $uid : null;
    }

    public function login(int $userId, int $now): void
    {
        ($this->renewId)();
        $this->store = ['uid' => $userId, 'login_at' => $now, 'csrf' => self::newToken()];
    }

    public function logout(): void
    {
        $this->store = [];
        ($this->renewId)();
    }

    public function csrfToken(): string
    {
        if (!is_string($this->store['csrf'] ?? null) || strlen($this->store['csrf']) !== 64) {
            $this->store['csrf'] = self::newToken();
        }
        return $this->store['csrf'];
    }

    public function verifyCsrf(string $t): bool
    {
        $stored = $this->store['csrf'] ?? null;
        return is_string($stored) && $stored !== '' && $t !== '' && hash_equals($stored, $t);
    }

    public function isExpired(int $now, int $maxAge): bool
    {
        $at = $this->store['login_at'] ?? null;
        return !is_int($at) || $now - $at > $maxAge;
    }

    private static function newToken(): string
    {
        return bin2hex(random_bytes(32));
    }
}
