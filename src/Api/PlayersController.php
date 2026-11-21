<?php
declare(strict_types=1);

namespace App\Api;

use App\Auth\AuthGuard;
use App\Domain\AgeClass;
use App\Domain\HealthStatus;
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
 * Kein DELETE: Spieler werden nur auf passiv gesetzt.
 * Wer ein Feld sendet, das er nicht schreiben darf (oder das es nicht gibt), bekommt 403 für die
 * ganze Anfrage, und nichts wird gespeichert. club_id wird beim Speichern aus club_name abgeleitet.
 */
final class PlayersController
{
    /**
     * Prüfregeln je schreibbarem Feld: Typ, Grenze, Pflicht.
     * string: Länge in Zeichen; int: [min, max]; leere Texte werden zu null (außer bei Pflichtfeldern).
     */
    private const RULES = [
        'last_name' => ['string', 100, true],
        'first_name' => ['string', 100, true],
        'club_name' => ['string', 190, false],
        'list_no' => ['int', [0, 99999], false],
        'list_ak' => ['string', 5, false],
        'gender' => ['string', 10, false],
        'birth_year' => ['int', [1900, 2100], false],
        'roster_status' => ['string', 10, false],
        'active' => ['bool', null, true],
        'training_site' => ['string', 100, false],
        'birth_date' => ['date', null, false],
        'trainer_info' => ['string', 10000, false],
        'school' => ['string', 190, false],
        'school_contact' => ['string', 500, false],
        // Bis Etappe 3 Textspalten aus der Kaderliste: nur Datum und Status, keine Befunde. Kurz gehalten.
        'sportmed_note' => ['string', 200, false],
        'antidoping_note' => ['string', 200, false],
        'email1' => ['string', 190, false],
        'email2' => ['string', 190, false],
        'phone_player' => ['string', 50, false],
        'phone_mother' => ['string', 50, false],
        'phone_father' => ['string', 50, false],
        'shirt_size' => ['string', 10, false],
    ];

    /** Freitextfelder, in denen Zeilenumbruch und Tabulator erlaubt sind. */
    private const MULTILINE = ['trainer_info', 'sportmed_note', 'antidoping_note'];

    /** Leerraum am Rand, auch geschützte und Null-Breite-Leerzeichen und BOM aus Excel. */
    private const EDGE_SPACE = '/^[\s\x{00A0}\x{2007}\x{202F}\x{200B}\x{FEFF}]+|[\s\x{00A0}\x{2007}\x{202F}\x{200B}\x{FEFF}]+$/u';

    private const TZ = 'Europe/Berlin';

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
        [$data, $invalid] = self::validate($body);
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
        [$data, $invalid] = self::validate($body);
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

    /**
     * Prüft und normalisiert die Werte.
     * @param array<string, mixed> $body
     * @return array{0: array<string, mixed>, 1: list<string>} [Daten, ungültige Felder]
     */
    private static function validate(array $body): array
    {
        $data = [];
        $invalid = [];
        foreach ($body as $field => $value) {
            [$type, $limit, $required] = self::RULES[$field];
            $ok = true;
            switch ($type) {
                case 'string':
                    if ($value === null) {
                        $value = null;
                    } elseif (is_string($value) && mb_check_encoding($value, 'UTF-8')) {
                        $value = (string) preg_replace(self::EDGE_SPACE, '', $value);
                        $control = in_array($field, self::MULTILINE, true) ? '/[^\P{Cc}\t\n\r]/u' : '/\p{Cc}/u';
                        if (mb_strlen($value, 'UTF-8') > $limit || preg_match($control, $value) === 1) {
                            $ok = false;
                        } elseif ($value === '' && $field !== 'club_name') {
                            $value = null;
                        }
                    } else {
                        $ok = false;
                    }
                    break;
                case 'int':
                    if (is_string($value) && preg_match('/^\d{1,9}$/', $value) === 1) {
                        $value = (int) $value;
                    }
                    if ($value !== null && (!is_int($value) || $value < $limit[0] || $value > $limit[1])) {
                        $ok = false;
                    }
                    break;
                case 'bool':
                    $ok = is_bool($value);
                    break;
                case 'date':
                    if ($value === '' ) {
                        $value = null;
                    }
                    if ($value !== null) {
                        $ok = is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1
                            && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
                    }
                    break;
            }
            if ($ok && $required && $value === null) {
                $ok = false;
            }
            if ($ok) {
                $data[$field] = $value;
            } else {
                $invalid[] = (string) $field;
            }
        }
        return [$data, $invalid];
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
