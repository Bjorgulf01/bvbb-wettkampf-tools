<?php
declare(strict_types=1);

namespace App;

use RuntimeException;

/** Die Konfiguration ist für den Betrieb unsicher (zum Beispiel Testanmeldung im Echtbetrieb). */
final class UnsafeConfig extends RuntimeException
{
}
