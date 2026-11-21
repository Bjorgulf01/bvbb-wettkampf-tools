<?php
declare(strict_types=1);

namespace App\Auth;

/** Benutzer der App mit seinen Rollen aus der Datenbank. */
final class User
{
    /** @param list<string> $roles Rollenschlüssel, sortiert */
    public function __construct(
        public readonly int $id,
        public readonly string $subject,
        public readonly string $name,
        public readonly ?string $email,
        public readonly bool $active,
        public readonly array $roles,
    ) {
    }
}
