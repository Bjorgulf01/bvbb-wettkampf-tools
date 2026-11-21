<?php
declare(strict_types=1);

/** Wird von assert*-Funktionen geworfen, wenn eine Prüfung scheitert. */
final class AssertionFailure extends Exception
{
}

/** Wird von skip() geworfen. Ein übersprungener Test zählt nie als Erfolg. */
final class SkipTest extends Exception
{
}

function assertSame(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new AssertionFailure(
            ($msg !== '' ? $msg . ': ' : '')
            . 'erwartet ' . var_export($expected, true)
            . ', erhalten ' . var_export($actual, true)
        );
    }
}

function assertTrue(bool $v, string $msg = ''): void
{
    if ($v !== true) {
        throw new AssertionFailure($msg !== '' ? $msg : 'erwartet true, erhalten false');
    }
}

/** Prüft, dass $fn eine Ausnahme der Klasse $class (oder einer Unterklasse) wirft. */
function assertThrows(callable $fn, string $class): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof $class) {
            return;
        }
        throw new AssertionFailure(
            'erwartet Ausnahme ' . $class . ', erhalten ' . $e::class . ': ' . $e->getMessage()
        );
    }
    throw new AssertionFailure('erwartet Ausnahme ' . $class . ', es wurde keine geworfen');
}

function skip(string $why): never
{
    throw new SkipTest($why);
}
