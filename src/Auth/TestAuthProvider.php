<?php
declare(strict_types=1);

namespace App\Auth;

use App\Http\Request;

/**
 * Testanmeldung ohne Passwort mit erfundenen Personen. Läuft nur, wenn test_auth ausdrücklich
 * eingeschaltet ist (Config::assertSafe verhindert das im Echtbetrieb).
 * Die Kennung (subject) einer Testperson ist "test:" plus ihr Schlüssel.
 */
final class TestAuthProvider implements AuthProvider
{
    /** @param array<string, array{0:string, 1:string, 2:list<string>}> $personas Schlüssel => [Name, E-Mail, Rollen] */
    public function __construct(private array $personas)
    {
    }

    /** Erfundene Personen je Rolle, dazu eine mit zwei Rollen und eine ohne Rolle. */
    public static function defaultPersonas(): array
    {
        return [
            'admin' => ['Ada Verwaltung', 'admin@example.org', ['admin']],
            'kaderpflege' => ['Karl Kaderpflege', 'kaderpflege@example.org', ['roster_editor']],
            'nada' => ['Nora Nada', 'nada@example.org', ['health_staff']],
            'pruefer' => ['Paul Prüfer', 'pruefer@example.org', ['reviewer']],
            'trainer' => ['Tina Training', 'trainer@example.org', ['coach']],
            'basis' => ['Bert Basis', 'basis@example.org', ['basic']],
            'trainer-kaderpflege' => ['Theo Doppelrolle', 'doppelrolle@example.org', ['coach', 'roster_editor']],
            'ohne-rolle' => ['Olga Ohnerolle', 'ohne-rolle@example.org', []],
        ];
    }

    /**
     * Für die Anmeldeseite: Schlüssel, Name und Rollen der Testpersonen, ohne E-Mail-Adresse.
     * @return list<array{key:string, name:string, roles:list<string>}>
     */
    public function personas(): array
    {
        $out = [];
        foreach ($this->personas as $key => [$name, , $roles]) {
            $out[] = ['key' => (string) $key, 'name' => $name, 'roles' => array_values($roles)];
        }
        return $out;
    }

    /** Liest "person" aus dem JSON-Body. Unbekannt oder fehlend: null. */
    public function identify(Request $r): ?Identity
    {
        $key = $r->json['person'] ?? null;
        if (!is_string($key) || !isset($this->personas[$key])) {
            return null;
        }
        [$name, $email] = $this->personas[$key];
        return new Identity('test:' . $key, $name, $email);
    }

    /** @return list<string> Test-Rollen der Person (nur für das erste Anlegen) */
    public function rolesOf(Identity $i): array
    {
        $key = substr($i->subject, strlen('test:'));
        return array_values($this->personas[$key][2] ?? []);
    }
}
