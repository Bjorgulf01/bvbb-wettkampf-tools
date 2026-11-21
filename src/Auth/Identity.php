<?php
declare(strict_types=1);

namespace App\Auth;

/** Wer die Person ist, laut Anmeldedienst. Rollen stehen nicht hier, sondern in der App-Datenbank. */
final class Identity
{
    public function __construct(
        public readonly string $subject,
        public readonly string $name,
        public readonly string $email,
    ) {
    }
}
