<?php
declare(strict_types=1);

namespace App\Repo;

use App\Auth\Identity;
use App\Auth\User;
use InvalidArgumentException;
use PDO;
use PDOException;
use Throwable;

/** Benutzer und ihre Rollen. Benutzer werden nie gelöscht, nur auf inaktiv gesetzt. */
final class UserRepo
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Legt die Person beim ersten Mal an, sonst aktualisiert sie Name und E-Mail aus dem Anmeldedienst.
     * Rollen werden nur beim ersten Anlegen vergeben und dann ins Änderungsprotokoll geschrieben
     * (nur Rollenschlüssel, keine Namen oder E-Mail-Adressen).
     *
     * @param list<string> $initialRoleKeys
     * @throws InvalidArgumentException bei unbekanntem Rollenschlüssel
     */
    public function upsert(Identity $i, array $initialRoleKeys = []): User
    {
        // Kennung des Anmeldedienstes: 1 bis 190 sichtbare ASCII-Zeichen (Spalte ascii_nopad_bin).
        if (preg_match('/^[\x21-\x7E]{1,190}$/', $i->subject) !== 1) {
            throw new InvalidArgumentException('Ungültige Kennung vom Anmeldedienst');
        }
        $name = mb_substr($i->name, 0, 190, 'UTF-8');
        $email = $i->email !== '' && strlen($i->email) <= 190 ? $i->email : null;

        $existing = $this->idBySubject($i->subject);
        if ($existing !== null) {
            // Daten inaktiver Personen bleiben unverändert.
            $this->pdo->prepare('UPDATE users SET name = ?, email = ? WHERE id = ? AND active = 1')
                ->execute([$name, $email, $existing]);
            return $this->findById($existing) ?? throw new \LogicException('Benutzer verschwunden');
        }

        $roleKeys = array_values(array_unique(array_map('strval', $initialRoleKeys)));
        sort($roleKeys, SORT_STRING);

        $this->pdo->beginTransaction();
        try {
            $roleIds = $this->roleIds($roleKeys);
            $this->pdo->prepare('INSERT INTO users (subject, name, email) VALUES (?, ?, ?)')
                ->execute([$i->subject, $name, $email]);
            $id = (int) $this->pdo->lastInsertId();
            $ins = $this->pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
            foreach ($roleIds as $roleId) {
                $ins->execute([$id, $roleId]);
            }
            (new AuditRepo($this->pdo))->log(
                null,
                'user_created',
                'user',
                $id,
                'roles: ' . ($roleKeys === [] ? '(keine)' : implode(', ', $roleKeys))
            );
            $this->pdo->commit();
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            // Gleichzeitige erste Anmeldung derselben Person: der andere Aufruf hat sie schon angelegt.
            $raced = $e->getCode() === '23000' ? $this->idBySubject($i->subject) : null;
            if ($raced === null) {
                throw $e;
            }
            return $this->findById($raced) ?? throw $e;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $this->findById($id) ?? throw new \LogicException('Benutzer nicht angelegt');
    }

    public function findById(int $id): ?User
    {
        $stmt = $this->pdo->prepare('SELECT id, subject, name, email, active FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $roles = $this->pdo->prepare(
            'SELECT r.role_key FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? ORDER BY r.role_key'
        );
        $roles->execute([$id]);
        return new User(
            (int) $row['id'],
            (string) $row['subject'],
            (string) $row['name'],
            $row['email'] !== null ? (string) $row['email'] : null,
            (bool) $row['active'],
            array_map('strval', $roles->fetchAll(PDO::FETCH_COLUMN)),
        );
    }

    /** @return list<User> alle Personen, auch inaktive, nach Name sortiert */
    public function all(): array
    {
        $roles = [];
        foreach ($this->pdo->query(
            'SELECT ur.user_id, r.role_key FROM user_roles ur JOIN roles r ON r.id = ur.role_id ORDER BY r.role_key'
        )->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $roles[(int) $row['user_id']][] = (string) $row['role_key'];
        }
        $users = [];
        foreach ($this->pdo->query('SELECT id, subject, name, email, active FROM users ORDER BY name, id')
            ->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['id'];
            $users[] = new User(
                $id,
                (string) $row['subject'],
                (string) $row['name'],
                $row['email'] !== null ? (string) $row['email'] : null,
                (bool) $row['active'],
                $roles[$id] ?? [],
            );
        }
        return $users;
    }

    /** @return list<string> alle Rollenschlüssel, sortiert */
    public function roleKeys(): array
    {
        return array_map('strval', $this->pdo->query('SELECT role_key FROM roles ORDER BY role_key')
            ->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Ersetzt die Rollen einer Person. Läuft in der Transaktion des Aufrufers.
     * @param list<string> $roleKeys
     * @throws InvalidArgumentException bei unbekanntem Rollenschlüssel (vor jeder Änderung)
     */
    public function setRoles(int $userId, array $roleKeys): void
    {
        $roleIds = $this->roleIds(array_values(array_unique($roleKeys)));
        $this->pdo->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$userId]);
        $ins = $this->pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
        foreach ($roleIds as $roleId) {
            $ins->execute([$userId, $roleId]);
        }
    }

    public function setActive(int $userId, bool $active): void
    {
        $this->pdo->prepare('UPDATE users SET active = ? WHERE id = ?')->execute([$active ? 1 : 0, $userId]);
    }

    /**
     * Sperrt die Zeile der Rolle admin bis zum Ende der Transaktion. Jede Änderung, die einen admin
     * entfernen kann, nimmt zuerst diese eine Sperre; solche Änderungen laufen dadurch nacheinander.
     */
    public function lockAdminRole(): void
    {
        $this->pdo->prepare("SELECT id FROM roles WHERE role_key = 'admin' FOR UPDATE")->execute();
    }

    /** @return list<int> Ids der aktiven Personen mit der Rolle admin */
    public function activeAdminIds(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
             WHERE r.role_key = 'admin' AND u.active = 1 ORDER BY u.id"
        );
        $stmt->execute();
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function idBySubject(string $subject): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE subject = ?');
        $stmt->execute([$subject]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /**
     * @param list<string> $roleKeys
     * @return list<int>
     */
    private function roleIds(array $roleKeys): array
    {
        if ($roleKeys === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($roleKeys), '?'));
        $stmt = $this->pdo->prepare('SELECT role_key, id FROM roles WHERE role_key IN (' . $marks . ')');
        $stmt->execute($roleKeys);
        $found = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $unknown = array_diff($roleKeys, array_keys($found));
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unbekannte Rolle: ' . implode(', ', $unknown));
        }
        return array_map('intval', array_values($found));
    }
}
