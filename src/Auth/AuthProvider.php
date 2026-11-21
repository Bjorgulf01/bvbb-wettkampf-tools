<?php
declare(strict_types=1);

namespace App\Auth;

use App\Http\Request;

/** Anmeldedienst: liefert nur die Identität, nie Rollen oder Rechte. */
interface AuthProvider
{
    public function identify(Request $r): ?Identity;
}
