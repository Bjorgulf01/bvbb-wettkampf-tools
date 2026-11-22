<?php
declare(strict_types=1);

namespace App\Api;

use App\Auth\AuthGuard;
use App\Domain\ClubDirectory;
use App\Domain\PlayerInput;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Rbac\Permissions;
use App\Repo\AuditRepo;
use App\Repo\ClubRepo;
use App\Repo\PlayerRepo;
use PDO;
use PDOException;
use Throwable;

/**
 * Einfache Vereinsliste bis zum Import der Mitgliederliste (Task 9b).
 *  GET /api/clubs  {clubs:[…]}  alle Vereine mit Aliasnamen, auch inaktive (Leserecht clubs)
 *  PUT /api/clubs  {clubs:[{id?, name, short, state, active, aliases}]}  (Schreibrecht clubs)
 *      Einträge mit id ändern diesen Verein, Einträge ohne id legen einen an. Vereine, die fehlen,
 *      bleiben unverändert. Fehlt ein Schlüssel, bleibt der Wert. aliases ersetzt alle Aliasnamen.
 *      Antwort {created:int, updated:int, clubs:[…]}.
 * Kein DELETE: Vereine werden nur auf inaktiv gesetzt. Die Mitgliedsnummer ist hier nicht schreibbar.
 * Nach dem Speichern wird club_id aller Spieler aus ihrem Vereinstext neu abgeleitet.
 */
final class ClubsController
{
    private const KEYS = ['id', 'name', 'short', 'state', 'active', 'aliases'];
    private const STATES = ['BER', 'BRA'];

    private ClubRepo $clubs;
    private PlayerRepo $players;
    private AuditRepo $audit;

    public function __construct(
        private PDO $pdo,
        private Permissions $permissions,
        private AuthGuard $guard,
    ) {
        $this->clubs = new ClubRepo($pdo);
        $this->players = new PlayerRepo($pdo);
        $this->audit = new AuditRepo($pdo);
    }

    public function routes(Router $router): void
    {
        $router->add('GET', '/api/clubs', $this->list(...));
        $router->add('PUT', '/api/clubs', $this->save(...));
    }

    private function list(Request $r, array $params): Response
    {
        if (!$this->permissions->can($this->roles(), 'clubs', Permissions::READ)) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        return Response::json(['clubs' => $this->clubs->all()]);
    }

    private function save(Request $r, array $params): Response
    {
        if (!$this->permissions->can($this->roles(), 'clubs', Permissions::WRITE)) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $body = $r->json;
        $entries = $body['clubs'] ?? null;
        if (!is_array($body) || array_keys($body) !== ['clubs'] || !is_array($entries) || $entries === []
            || !array_is_list($entries)) {
            return Response::json(['error' => 'invalid_body'], 422);
        }
        // Unbekannte und nicht schreibbare Felder (member_no, imported_at): 403 für die ganze Anfrage.
        $denied = [];
        foreach ($entries as $e) {
            if (!is_array($e) || array_is_list($e)) {
                return Response::json(['error' => 'invalid_body'], 422);
            }
            foreach (array_keys($e) as $k) {
                if (!in_array($k, self::KEYS, true) && !in_array((string) $k, $denied, true)) {
                    $denied[] = (string) $k;
                }
            }
        }
        if ($denied !== []) {
            return Response::json(['error' => 'forbidden', 'fields' => $denied], 403);
        }

        $valid = [];
        $invalid = [];
        foreach ($entries as $i => $e) {
            [$entry, $bad] = $this->validate($e);
            foreach ($bad as $f) {
                $invalid[] = 'clubs.' . $i . '.' . $f;
            }
            $valid[] = $entry;
        }
        if ($invalid !== []) {
            return Response::json(['error' => 'invalid', 'fields' => $invalid], 422);
        }
        if (($dup = $this->duplicateNames($valid)) !== []) {
            return Response::json(['error' => 'duplicate_name', 'fields' => $dup], 422);
        }

        $user = $this->guard->currentUser();
        $counts = $this->transaction(function () use ($valid, $user): ?array {
            $created = 0;
            $updated = 0;
            foreach ($valid as ['id' => $id, 'data' => $data, 'aliases' => $aliases]) {
                $isNew = $id === null;
                if ($isNew) {
                    $id = $this->clubs->create($data);
                    $changed = array_keys($data);
                    $created++;
                } else {
                    $changed = $this->clubs->update($id, $data);
                }
                if ($aliases !== null) {
                    try {
                        if ($this->clubs->setAliases($id, $aliases)) {
                            $changed[] = 'aliases';
                        }
                    } catch (PDOException $ex) {
                        if ($ex->getCode() === '23000') {
                            return null; // Alias doppelt: alles zurückrollen
                        }
                        throw $ex;
                    }
                }
                if ($changed !== []) {
                    $this->audit->log($user?->id, $isNew ? 'create' : 'update', 'club', $id, 'fields: ' . implode(', ', $changed));
                    $updated += $isNew ? 0 : 1;
                }
            }
            $this->players->reassignClubs($this->clubs->directory());
            return ['created' => $created, 'updated' => $updated];
        });
        if ($counts === null) {
            return Response::json(['error' => 'duplicate_alias'], 422);
        }
        return Response::json($counts + ['clubs' => $this->clubs->all()]);
    }

    /**
     * Ein Name, der nach dem Speichern zweimal vorkäme (Vergleich wie bei der Zuordnung, auch mit
     * inaktiven Vereinen), machte die Zuordnung mehrdeutig: Spieler verlören Kürzel und Bundesland.
     * Geprüft wird der Stand nach dem Speichern, und nur Namen, die die Anfrage betrifft.
     * @param list<array{id:?int, data:array<string, mixed>}> $valid
     * @return list<string> Pfade der betroffenen Einträge (clubs.N.name)
     */
    private function duplicateNames(array $valid): array
    {
        $final = []; // Schlüssel => Name je Verein nach dem Speichern
        foreach ($this->clubs->all() as $c) {
            $final['db' . $c['id']] = $c['name'];
        }
        $touched = [];
        foreach ($valid as $i => $v) {
            $key = $v['id'] !== null ? 'db' . $v['id'] : 'new' . $i;
            if (isset($v['data']['name'])) {
                $final[$key] = $v['data']['name'];
                $touched[$key] = $i;
            }
        }
        $count = [];
        foreach ($final as $name) {
            $n = ClubDirectory::normalize($name);
            $count[$n] = ($count[$n] ?? 0) + 1;
        }
        $dup = [];
        foreach ($touched as $key => $i) {
            if ($count[ClubDirectory::normalize($final[$key])] > 1) {
                $dup[] = 'clubs.' . $i . '.name';
            }
        }
        return $dup;
    }

    /**
     * @param array<string, mixed> $e
     * @return array{0: array{id:?int, data:array<string, mixed>, aliases:?list<string>}, 1: list<string>}
     */
    private function validate(array $e): array
    {
        $bad = [];
        $id = null;
        if (array_key_exists('id', $e)) {
            if (is_int($e['id']) && $e['id'] > 0 && $this->clubs->exists($e['id'])) {
                $id = $e['id'];
            } else {
                $bad[] = 'id';
            }
        }
        $data = [];
        if (array_key_exists('name', $e) || $id === null && !in_array('id', $bad, true)) {
            $name = self::text($e['name'] ?? null);
            if ($name === null || $name === '' || mb_strlen($name, 'UTF-8') > 190) {
                $bad[] = 'name';
            } else {
                $data['name'] = $name;
            }
        }
        if (array_key_exists('short', $e)) {
            $short = $e['short'] === null ? '' : self::text($e['short']);
            if ($short === null || mb_strlen($short, 'UTF-8') > 20) {
                $bad[] = 'short';
            } else {
                $data['short'] = $short;
            }
        }
        if (array_key_exists('state', $e)) {
            $state = $e['state'] === '' ? null : $e['state'];
            if ($state !== null && !in_array($state, self::STATES, true)) {
                $bad[] = 'state';
            } else {
                $data['state'] = $state;
            }
        }
        if (array_key_exists('active', $e)) {
            if (is_bool($e['active'])) {
                $data['active'] = $e['active'];
            } else {
                $bad[] = 'active';
            }
        }
        $aliases = null;
        if (array_key_exists('aliases', $e)) {
            $aliases = [];
            if (!is_array($e['aliases']) || !array_is_list($e['aliases'])) {
                $bad[] = 'aliases';
            } else {
                foreach ($e['aliases'] as $a) {
                    $a = self::text($a);
                    if ($a === null || mb_strlen($a, 'UTF-8') > 190) {
                        $bad[] = 'aliases';
                        break;
                    }
                    if ($a !== '' && !in_array($a, $aliases, true)) {
                        $aliases[] = $a;
                    }
                }
            }
        }
        return [['id' => $id, 'data' => $data, 'aliases' => $aliases], $bad];
    }

    /** Text ohne Leerraum am Rand; null bei Nicht-Text, ungültigem UTF-8 oder Steuerzeichen. */
    private static function text(mixed $v): ?string
    {
        if (!is_string($v) || !mb_check_encoding($v, 'UTF-8') || preg_match('/\p{Cc}/u', $v) === 1) {
            return null;
        }
        return (string) preg_replace(PlayerInput::EDGE_SPACE, '', $v);
    }

    /** @return list<string> */
    private function roles(): array
    {
        return $this->guard->currentUser()?->roles ?? [];
    }

    /**
     * @template T
     * @param callable(): T $fn Rückgabe null rollt zurück
     * @return T
     */
    private function transaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn();
            $result === null ? $this->pdo->rollBack() : $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
