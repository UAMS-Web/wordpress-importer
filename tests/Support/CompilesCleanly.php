<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Tests\Support;

use PHPUnit\Framework\Assert;
use ReflectionClass;
use RuntimeException;

/**
 * Fails a test when a class's source compiles with a warning.
 *
 * A `continue` that targets a `switch` compiles to a `break` with a warning, and
 * Laravel's error handler turns that warning into an exception when an import
 * loads the class. A test cannot rely on loading the class itself to see it: a
 * test file that declares a subclass at its top level loads the class before any
 * handler is set, and the warning is then only printed. So the source is read as
 * the running process reads it, which under a mutation run is the mutated copy,
 * and linted in a process of its own.
 */
final class CompilesCleanly
{
    /**
     * The classes already linted in this process, keyed by name.
     *
     * @var array<class-string, true>
     */
    private static array $linted = [];

    /**
     * Fail unless the class's source compiles without a warning, once per process.
     *
     * @param  class-string  $class  The class to lint
     */
    public static function assert(string $class): void
    {
        if (isset(self::$linted[$class])) {
            return;
        }

        self::$linted[$class] = true;

        $source = (new ReflectionClass($class))->getFileName();
        $copy = tempnam(sys_get_temp_dir(), 'compiles-cleanly-');

        if (! is_string($source) || ! is_string($copy)) {
            throw new RuntimeException("Cannot lint {$class}.");
        }

        // Read the source the way the running process does, then lint the copy
        file_put_contents($copy, (string) file_get_contents($source));
        $lines = [];
        exec(escapeshellarg(PHP_BINARY).' -d display_errors=1 -d error_reporting=-1 -l '.escapeshellarg($copy).' 2>&1', $lines, $status);
        unlink($copy);

        $warnings = array_values(array_filter($lines, static fn (string $line): bool => str_contains($line, 'Warning')));

        Assert::assertSame([0, []], [$status, $warnings], "{$class} does not compile cleanly.");
    }
}
