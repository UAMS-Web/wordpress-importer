<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Tests\Support;

use RuntimeException;

/**
 * Process-wide record of the temporary files a test run created, swept at exit.
 *
 * `WordPressImportTestTrait::createTempFile()` builds a path from `tempnam()`
 * and hands it to a caller that writes an export to it. Whether that caller
 * removes the file afterwards is per-test and optional, and an `afterEach`
 * cannot run at all for a test that dies partway — so files accumulated in
 * `sys_get_temp_dir()` indefinitely (wordpress-importer-archive#783).
 *
 * **This lives on a class rather than on the trait for a reason that is easy to
 * get wrong.** A static declared on a trait is not shared by the classes that
 * compose it: each composing class gets its OWN copy, so a registry kept there
 * would sweep per test class and register one shutdown handler per file in the
 * band. A static on a normal class is genuinely one per process.
 *
 * The sweep is a backstop, not a replacement for per-test cleanup: a test that
 * removes its own export keeps the directory clean *while the band runs*, which
 * matters across a 27-file run. This only guarantees nothing survives the
 * process.
 */
final class TemporaryFileRegistry
{
    /**
     * Every path handed out this process that still needs removing.
     *
     * @var list<string>
     */
    private static array $paths = [];

    /**
     * Whether the exit sweep has been registered.
     *
     * Registered lazily on first use rather than eagerly, so a run that creates
     * no temporary file installs no handler.
     */
    private static bool $sweepRegistered = false;

    /**
     * Create a temporary file and record it for removal.
     *
     * `tempnam()` CREATES the file it names, and callers want
     * `<that path><extension>` — a DIFFERENT path. Returning that without
     * removing the stub left two files behind per call, and only one of them
     * was ever anybody's to clean up. Measured on macOS, 2026-08-27: 628 stubs
     * in `sys_get_temp_dir()` against 18 orphaned exports, and the 628 came
     * from the two call sites that DO unlink their export — the leak was here,
     * so cleaning up correctly could not prevent it (wordpress-importer-archive#783).
     *
     * `rename()` moves the file onto the name the caller will use in one step,
     * with no intermediate state in which both names exist.
     *
     * **It does not keep the stub's name reserved, and nothing here does.** Once
     * renamed, the extensionless name is free, so a later `tempnam()` could in
     * principle be handed it again and rename onto the same export. The old
     * helper avoided that only by leaking the stub forever — the leak *was* the
     * reservation. This trades a collision whose probability is `tempnam()`'s
     * randomness for not stranding a file on every call, and `$path` itself is
     * never at risk from `tempnam()`, which only ever returns extensionless
     * names. `WxrSanitizerTest` reached the same technique independently; this
     * promotes it to the shared helper the leak actually lives in.
     *
     * @param  string  $prefix  The filename prefix
     * @param  string  $extension  Appended to the generated path
     * @return string The path to write to
     *
     * @throws RuntimeException When a temporary file cannot be created at all
     */
    public static function create(
        string $prefix,
        string $extension = '.xml'
    ): string {
        $stub = \tempnam(
            \sys_get_temp_dir(),
            $prefix
        );

        // A temp directory that cannot be written to is a harness failure, and
        // returning `false.xml` would present as a test failure instead
        if ($stub === false) {
            throw new RuntimeException(\sprintf(
                'Could not create a temporary file for prefix "%s" in %s.',
                $prefix,
                \sys_get_temp_dir()
            ));
        }

        $path = $stub.$extension;

        // A rename that fails leaves the stub where it is; carrying on would hand
        // back a path nothing reserved
        if (! \rename($stub, $path)) {
            @\unlink($stub);

            throw new RuntimeException(\sprintf(
                'Could not move the temporary file for prefix "%s" onto "%s".',
                $prefix,
                $path
            ));
        }

        self::remember($path);

        return $path;
    }

    /**
     * Record a path for removal at process exit.
     *
     * @param  string  $path  The absolute path to remove
     */
    public static function remember(
        string $path
    ): void {
        self::$paths[] = $path;

        if (self::$sweepRegistered) {
            return;
        }

        self::$sweepRegistered = true;

        \register_shutdown_function(static function (): void {
            self::sweep();
        });
    }

    /**
     * Remove every recorded path that still exists.
     *
     * Public so a test can invoke the backstop directly rather than ending the
     * process to observe it.
     *
     * @return int How many files were removed
     */
    public static function sweep(): int
    {
        $removed = 0;

        foreach (self::$paths as $path) {
            // A caller that cleaned up after itself leaves nothing to do here

            if (! \is_file($path)) {
                continue;
            }

            if (@\unlink($path)) {
                $removed++;
            }
        }

        self::$paths = [];

        return $removed;
    }

    /**
     * The paths recorded and not yet swept.
     *
     * @return list<string> The outstanding paths
     */
    public static function outstanding(): array
    {
        return self::$paths;
    }
}
