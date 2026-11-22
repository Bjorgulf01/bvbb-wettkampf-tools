<?php
declare(strict_types=1);

namespace App\Api;

use App\Auth\AuthGuard;
use App\Domain\AgeClass;
use App\Domain\HealthStatus;
use App\Domain\KaderImport;
use App\Domain\PlayerInput;
use App\Domain\PlayerValidator;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Rbac\Permissions;
use App\Repo\AuditRepo;
use App\Repo\ClubRepo;
use App\Repo\PlayerRepo;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/**
 * Spieler-Schnittstelle mit Rechten auf Feldebene.
 *  GET  /api/players?year=JJJJ   {players:[…]}  je Zeile id, die lesbaren Felder, ak (mit player.roster)
 *  GET  /api/players/{id}        {player:{…}}
 *  POST /api/players             201 {id}
 *  PUT  /api/players/{id}        {id, changed:[…]}
 *  GET  /api/players/issues?year=JJJJ  {issues:[…]}  Datenprüfung aller Spieler
 *  POST /api/players/import      {rows:[…], dry_run:bool, year?}  →  {create, update, skipped:[…], issues:[…]}
 *       Datenprüfung und Import nur mit Schreibrecht auf player.basic, player.roster und player.private.
 *       dry_run schreibt nichts, sonst läuft der Import in einer Transaktion. Spieler, die nicht in
 *       der Datei stehen, bleiben unverändert.
 * Kein DELETE: Spieler werden nur auf passiv gesetzt.
 * Wer ein Feld sendet, das er nicht schreiben darf (oder das es nicht gibt), bekommt 403 für die
 * ganze Anfrage, und nichts wird gespeichert. club_id wird beim Speichern aus club_name abgeleitet.
 */
final class PlayersController
{
    private const TZ = 'Europe/Berlin';

    /** Obergrenze je Import (Kaderliste: etwa 100 Zeilen; Schutz für memory_limit und Laufzeit). */
    private const MAX_IMPORT_ROWS = 3000;

    /** Felder, die die Datenprüfung braucht. */
    private const CHECK_FIELDS = ['last_name', 'first_name', 'club_name', 'list_ak', 'gender', 'birth_year',
        'roster_status', 'training_site'];

    private PlayerRepo $players;
    private ClubRepo $clubs;
    private AuditRepo $audit;
    private Closure $clock;

    public function __construct(
        private PDO $pdo,
        private Permissions $permissions,
        private AuthGuard $guard,
        ?callable $clock = null,
    ) {
        $this->players = new PlayerRepo($pdo);
        $this->clubs = new ClubRepo($pdo);
        $this->audit = new AuditRepo($pdo);
        $this->clock = $clock !== null ? Closure::fromCallable($clock) : static fn(): int => time();
    }

    public function routes(Router $router): void
    {
        $router->add('GET', '/api/players', $this->list(...));
        // Vor /api/players/{id}: der Router nimmt die erste passende Route.
        $router->add('GET', '/api/players/issues', $this->issues(...));
        $router->add('POST', '/api/players/import', $this->import(...));
        $router->add('GET', '/api/players/{id}', $this->show(...));
        $router->add('POST', '/api/players', $this->create(...));
        $router->add('PUT', '/api/players/{id}', $this->update(...));
    }

    private function list(Request $r, array $params): Response
    {
        $year = $this->year($r);
        if ($year === null) {
            return Response::json(['error' => 'invalid', 'fields' => ['year']], 422);
        }
        $read = $this->permissions->fieldsFor($this->roles(), Permissions::READ);
        if ($read === []) {
            return Response::json(['players' => []]);
        }
        $rows = $this->players->list($this->storedFieldsFor($read));
        return Response::json(['players' => array_map(fn(array $row) => $this->present($row, $read, $year), $rows)]);
    }

    private function show(Request $r, array $params): Response
    {
        $id = self::id($params['id'] ?? '');
        $year = $this->year($r);
        if ($year === null) {
            return Response::json(['error' => 'invalid', 'fields' => ['year']], 422);
        }
        $read = $this->permissions->fieldsFor($this->roles(), Permissions::READ);
        if ($read === []) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $row = $id !== null ? $this->players->find($id, $this->storedFieldsFor($read)) : null;
        if ($row === null) {
            return Response::json(['error' => 'not_found'], 404);
        }
        return Response::json(['player' => $this->present($row, $read, $year)]);
    }

    private function create(Request $r, array $params): Response
    {
        $body = self::object($r->json);
        if ($body === null) {
            return Response::json(['error' => 'invalid_body'], 422);
        }
        // Pflichtfelder vor der Rechteprüfung ergänzen: Wer Name und Vorname nicht schreiben darf, bekommt 403.
        $body += ['last_name' => null, 'first_name' => null];
        if (($denied = $this->denied($body)) !== null) {
            return $denied;
        }
        [$data, $invalid] = PlayerInput::validate($body);
        if ($invalid !== []) {
            return Response::json(['error' => 'invalid', 'fields' => $invalid], 422);
        }
        $data['club_name'] ??= '';
        $user = $this->guard->currentUser();

        $id = $this->transaction(function () use ($data, $user): int {
            $data['club_id'] = $this->clubs->directory()->lookup($data['club_name'])['club_id'];
            $id = $this->players->create($data);
            $this->audit->log($user?->id, 'create', 'player', $id, 'fields: ' . implode(', ', array_keys($data)));
            return $id;
        });
        return Response::json(['id' => $id], 201);
    }

    private function update(Request $r, array $params): Response
    {
        $id = self::id($params['id'] ?? '');
        $body = self::object($r->json);
        if ($body === null) {
            return Response::json(['error' => 'invalid_body'], 422);
        }
        if (($denied = $this->denied($body)) !== null) {
            return $denied;
        }
        [$data, $invalid] = PlayerInput::validate($body);
        if ($invalid !== []) {
            return Response::json(['error' => 'invalid', 'fields' => $invalid], 422);
        }
        if ($id === null || !$this->players->exists($id)) {
            return Response::json(['error' => 'not_found'], 404);
        }
        $user = $this->guard->currentUser();

        $changed = $this->transaction(function () use ($id, $data, $user): array {
            if (array_key_exists('club_name', $data)) {
                $data['club_name'] ??= '';
                $data['club_id'] = $this->clubs->directory()->lookup($data['club_name'])['club_id'];
            }
            $changed = $this->players->update($id, $data);
            if ($changed !== []) {
                $this->audit->log($user?->id, 'update', 'player', $id, 'fields: ' . implode(', ', $changed));
            }
            return $changed;
        });
        return Response::json(['id' => $id, 'changed' => $changed]);
    }

    private function issues(Request $r, array $params): Response
    {
        if (!$this->canImport()) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $year = $this->year($r);
        if ($year === null) {
            return Response::json(['error' => 'invalid', 'fields' => ['year']], 422);
        }
        $rows = $this->players->list(self::CHECK_FIELDS);
        return Response::json(['issues' => PlayerValidator::issues($rows, $year, $this->clubs->directory())]);
    }

    private function import(Request $r, array $params): Response
    {
        if (!$this->canImport()) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $body = $r->json;
        $rows = $body['rows'] ?? null;
        $year = $body['year'] ?? (int) $this->today()->format('Y');
        if (!is_array($body) || array_diff(array_keys($body), ['rows', 'dry_run', 'year']) !== []
            || !is_array($rows) || !array_is_list($rows) || !is_bool($body['dry_run'] ?? null)
            || !is_int($year) || $year < 1900 || $year > 2099) {
            return Response::json(['error' => 'invalid_body'], 422);
        }
        if (count($rows) > self::MAX_IMPORT_ROWS) {
            return Response::json(['error' => 'too_many_rows', 'max' => self::MAX_IMPORT_ROWS], 413);
        }
        // Wie beim Speichern: ein nicht schreibbares oder unbekanntes Feld in irgendeiner Zeile ergibt 403.
        $keys = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                foreach (array_keys($row) as $k) {
                    $keys[(string) $k] = true;
                }
            }
        }
        if (($denied = $this->denied($keys)) !== null) {
            return $denied;
        }
        $dryRun = $body['dry_run'];
        $user = $this->guard->currentUser();

        $run = function () use ($rows, $year, $dryRun, $user): array {
            $plan = KaderImport::plan($rows, $this->players->list(['last_name', 'first_name']));
            $directory = $this->clubs->directory();
            $checked = [];
            foreach ($plan['update'] as &$u) {
                if (array_key_exists('club_name', $u['data'])) {
                    $u['data']['club_name'] ??= '';
                    $u['data']['club_id'] = $directory->lookup($u['data']['club_name'])['club_id'];
                }
                $checked[] = array_merge((array) $this->players->find($u['id'], self::CHECK_FIELDS), $u['data']);
            }
            unset($u);
            foreach ($plan['create'] as &$c) {
                $c['club_name'] ??= '';
                $c['club_id'] = $directory->lookup($c['club_name'])['club_id'];
                $checked[] = $c + ['id' => null];
            }
            unset($c);

            if (!$dryRun) {
                foreach ($plan['update'] as ['id' => $id, 'data' => $data]) {
                    $changed = $this->players->update($id, $data);
                    if ($changed !== []) {
                        $this->audit->log($user?->id, 'import_update', 'player', $id, 'fields: ' . implode(', ', $changed));
                    }
                }
                foreach ($plan['create'] as $data) {
                    $id = $this->players->create($data);
                    $this->audit->log($user?->id, 'import_create', 'player', $id, 'fields: ' . implode(', ', array_keys($data)));
                }
            }
            return [
                'create' => count($plan['create']),
                'update' => count($plan['update']),
                'skipped' => $plan['skipped'],
                'issues' => PlayerValidator::issues($checked, $year, $directory),
            ];
        };
        return Response::json($dryRun ? $run() : $this->transaction($run));
    }

    /** Import und Datenprüfung: nur wer alle Kaderdaten schreiben darf (Kaderpflege, Administrator). */
    private function canImport(): bool
    {
        foreach (['player.basic', 'player.roster', 'player.private'] as $group) {
            if (!$this->permissions->can($this->roles(), $group, Permissions::WRITE)) {
                return false;
            }
        }
        return true;
    }

    /** 403 mit den Feldnamen, wenn ein Feld nicht schreibbar oder unbekannt ist. */
    private function denied(array $body): ?Response
    {
        $denied = $this->permissions->deniedFields($this->roles(), array_map('strval', array_keys($body)), Permissions::WRITE);
        return $denied === [] ? null : Response::json(['error' => 'forbidden', 'fields' => $denied], 403);
    }

    /**
     * Gespeicherte Felder, die für die Ausgabe gebraucht werden: die lesbaren, für die Ampel die
     * Textspalten und für die AK Jahrgang und Listen-AK. Hilfsfelder werden vor der Ausgabe entfernt.
     * @param list<string> $read
     * @return list<string>
     */
    private function storedFieldsFor(array $read): array
    {
        $fields = array_values(array_diff($read, ['sportmed_status', 'antidoping_status']));
        if (in_array('sportmed_status', $read, true) || in_array('antidoping_status', $read, true)) {
            $fields[] = 'sportmed_note';
            $fields[] = 'antidoping_note';
        }
        if ($this->permissions->can($this->roles(), 'player.roster', Permissions::READ)) {
            $fields[] = 'birth_year';
            $fields[] = 'list_ak';
        }
        return array_values(array_unique($fields));
    }

    /**
     * Baut die Ausgabe: id, genau die lesbaren Felder und, mit player.roster, die AK.
     * @param array<string, mixed> $row
     * @param list<string> $read
     * @return array<string, mixed>
     */
    private function present(array $row, array $read, int $year): array
    {
        $today = $this->today();
        $out = ['id' => $row['id']];
        foreach ($read as $f) {
            $out[$f] = match ($f) {
                'sportmed_status' => HealthStatus::fromNote($row['sportmed_note'] ?? null, $year, $today),
                'antidoping_status' => HealthStatus::fromNote($row['antidoping_note'] ?? null, $year, $today),
                default => $row[$f] ?? null,
            };
        }
        if ($this->permissions->can($this->roles(), 'player.roster', Permissions::READ)) {
            $by = $row['birth_year'] ?? null;
            $out['ak'] = AgeClass::for($by !== null ? (string) $by : null, (string) ($row['list_ak'] ?? ''), $year);
        }
        return $out;
    }

    /** @return list<string> */
    private function roles(): array
    {
        return $this->guard->currentUser()?->roles ?? [];
    }

    private function year(Request $r): ?int
    {
        $y = $r->query['year'] ?? null;
        if ($y === null || $y === '') {
            return (int) $this->today()->format('Y');
        }
        return is_string($y) && preg_match('/^(19|20)\d{2}$/', $y) === 1 ? (int) $y : null;
    }

    private function today(): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . ($this->clock)()))->setTimezone(new DateTimeZone(self::TZ));
    }

    private static function id(string $raw): ?int
    {
        return preg_match('/^[1-9]\d{0,9}$/', $raw) === 1 && (int) $raw <= 4294967295 ? (int) $raw : null;
    }

    /**
     * Der Body muss Felder enthalten. Eine Liste wird nicht gesondert behandelt: ihre Schlüssel
     * (0, 1, …) sind unbekannte Felder und ergeben wie jedes unbekannte Feld 403.
     */
    private static function object(?array $json): ?array
    {
        return $json === null || $json === [] ? null : $json;
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function transaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn();
            $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
