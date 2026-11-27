<?php
declare(strict_types=1);

namespace App\Api;

use App\Auth\AuthGuard;
use App\Domain\ClubDirectory;
use App\Domain\ClubImport;
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
 * Vereinsliste, von Hand gepflegt und per Import aus nuLiga ergänzt (Task 9b, siehe import()).
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
    /** Schlüssel je Zeile beim Vereinsimport (die sieben Spalten aus dem nuLiga-Export). */
    private const IMPORT_KEYS = ['verein_nr', 'dbv_nr', 'name', 'short_name', 'print_name', 'status', 'is_federation'];
    private const MAX_IMPORT_ROWS = 3000;

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
        $router->add('POST', '/api/clubs/import', $this->import(...));
    }

    /**
     * POST /api/clubs/import  {rows:[…], dry_run:bool}  (Schreibrecht clubs)
     * Zeilen aus dem nuLiga-Export „Vereine (Stammdaten und Adressen)“ mit genau den Schlüsseln aus
     * IMPORT_KEYS. Ein anderer Schlüssel (etwa Bank- oder Adressfelder) ergibt 403 für die ganze Anfrage.
     * Antwort {create, update, unchanged, skipped, warnings}, bei dry_run und beim Übernehmen gleich.
     * Kürzel und Bundesland setzt der Import nie, Aliasnamen ergänzt er nur.
     */
    private function import(Request $r, array $params): Response
    {
        if (!$this->permissions->can($this->roles(), 'clubs', Permissions::WRITE)) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $body = $r->json;
        if (!is_array($body) || array_is_list($body) || !array_key_exists('rows', $body) || !array_key_exists('dry_run', $body)
            || array_diff(array_keys($body), ['rows', 'dry_run']) !== [] || !is_bool($body['dry_run'])
            || !is_array($body['rows']) || $body['rows'] === [] || !array_is_list($body['rows'])) {
            return Response::json(['error' => 'invalid_body'], 422);
        }
        $rows = $body['rows'];
        if (count($rows) > self::MAX_IMPORT_ROWS) {
            return Response::json(['error' => 'too_many_rows'], 413);
        }
        $denied = [];
        foreach ($rows as $row) {
            if (!is_array($row) || ($row !== [] && array_is_list($row))) {
                return Response::json(['error' => 'invalid_body'], 422);
            }
            foreach (array_keys($row) as $k) {
                if (!in_array($k, self::IMPORT_KEYS, true) && !in_array((string) $k, $denied, true)) {
                    $denied[] = (string) $k;
                }
            }
        }
        if ($denied !== []) {
            return Response::json(['error' => 'forbidden', 'fields' => $denied], 403);
        }
        $clean = [];
        $invalid = [];
        foreach ($rows as $i => $row) {
            [$c, $bad] = self::validateImportRow($row);
            foreach ($bad as $f) {
                $invalid[] = 'rows.' . $i . '.' . $f;
            }
            $clean[] = $c;
        }
        if ($invalid !== []) {
            return Response::json(['error' => 'invalid', 'fields' => $invalid], 422);
        }

        // Vorschau und Übernehmen laufen gleich: Der Import wird in einer Transaktion ausgeführt, gezählt wird,
        // was die Datenbank wirklich ändert (auch Spieler, die ihren Verein verlieren oder wechseln).
        // Bei dry_run wird danach alles zurückgerollt.
        $user = $this->guard->currentUser();
        $this->pdo->beginTransaction();
        try {
            $result = $this->applyImport($clean, $user?->id);
            $body['dry_run'] ? $this->pdo->rollBack() : $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return Response::json($result);
    }

    /**
     * Führt den Import innerhalb einer offenen Transaktion aus.
     * @param list<array<string, mixed>> $clean geprüfte Zeilen
     * @return array{create:int, update:int, unchanged:int, skipped:list<array>, warnings:list<array>, players:array{unlinked:int, moved:int}}
     */
    private function applyImport(array $clean, ?int $userId): array
    {
        $plan = ClubImport::plan($clean, $this->clubs->all());
        $before = $this->clubIdsOfPlayers();
        $updated = 0;
        $unchanged = $plan['unchanged'];
        foreach ($plan['create'] as $c) {
            $id = $this->clubs->create(['name' => $c['name'], 'active' => $c['active']]);
            $this->clubs->markImported($id, $c['member_no']);
            $fields = ['member_no', 'name', 'active'];
            if ($this->clubs->addAliases($id, $c['aliases']) !== []) {
                $fields[] = 'aliases';
            }
            $this->audit->log($userId, 'club_import_create', 'club', $id, 'fields: ' . implode(', ', $fields));
        }
        foreach ($plan['update'] as $u) {
            $memberNo = $u['data']['member_no'] ?? null;
            $fields = $this->clubs->update($u['id'], array_diff_key($u['data'], ['member_no' => true]));
            if ($memberNo !== null) {
                $fields[] = 'member_no';
            }
            // Ein Alias, den die Datenbank als doppelt ansieht (ohne Akzente verglichen), wird übergangen.
            if ($this->clubs->addAliases($u['id'], $u['add_aliases']) !== []) {
                $fields[] = 'aliases';
            }
            if ($fields === []) {
                $unchanged++;
                continue;
            }
            $updated++;
            $this->clubs->markImported($u['id'], $memberNo);
            $this->audit->log($userId, 'club_import_update', 'club', $u['id'], 'fields: ' . implode(', ', $fields));
        }
        $this->players->reassignClubs($this->clubs->directory());
        $unlinked = 0;
        $moved = 0;
        foreach ($this->clubIdsOfPlayers() as $id => $clubId) {
            $old = $before[$id] ?? null;
            if ($old !== null && $clubId === null) {
                $unlinked++;
            } elseif ($old !== null && $clubId !== $old) {
                $moved++;
            }
        }
        return [
            'create' => count($plan['create']), 'update' => $updated, 'unchanged' => $unchanged,
            'skipped' => $plan['skipped'], 'warnings' => $plan['warnings'],
            'players' => ['unlinked' => $unlinked, 'moved' => $moved],
        ];
    }

    /** @return array<int, ?int> Spieler-id => club_id */
    private function clubIdsOfPlayers(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT id, club_id FROM players')->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $out[(int) $p['id']] = $p['club_id'] !== null ? (int) $p['club_id'] : null;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private static function validateImportRow(array $row): array
    {
        $bad = [];
        $out = [];
        foreach (self::IMPORT_KEYS as $k) {
            if (!array_key_exists($k, $row)) {
                $bad[] = $k;
                continue;
            }
            $v = $row[$k];
            if ($k === 'is_federation') {
                if (is_bool($v)) {
                    $out[$k] = $v;
                } else {
                    $bad[] = $k;
                }
                continue;
            }
            $nullable = in_array($k, ['dbv_nr', 'short_name', 'print_name'], true);
            if ($v === null && $nullable) {
                $out[$k] = null;
                continue;
            }
            $t = self::text($v);
            $max = in_array($k, ['name', 'short_name', 'print_name'], true) ? 190 : 20;
            if ($t === null || mb_strlen($t, 'UTF-8') > $max) {
                $bad[] = $k;
                continue;
            }
            $out[$k] = $nullable && $t === '' ? null : $t;
        }
        return [$out, $bad];
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
