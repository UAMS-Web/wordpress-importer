<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Tests\Support;

use RuntimeException;

/**
 * Where the private export corpus is, for the tests that read it (wordpress-importer-archive#1426).
 *
 * The corpus lives in `UAMS-Web/wordpress-importer-exports`, which is private, so
 * this repository does not carry it. A test that reads it takes the location of a
 * local clone from the `WORDPRESS_IMPORTER_EXPORTS` environment variable, and skips
 * when the variable is unset, which is its normal state in a slot and in the gate.
 *
 * A variable that is set but names no directory is a misconfiguration rather than
 * an absent corpus, so it throws instead of skipping: a skip there would read as
 * the corpus test having nothing to run.
 */
final class ExportsCorpus
{
    /** The environment variable naming a local clone of the corpus. */
    public const ENV = 'WORDPRESS_IMPORTER_EXPORTS';

    /** The skip message for a test that needs the corpus while the variable is unset. */
    public const SKIP_MESSAGE = 'Set WORDPRESS_IMPORTER_EXPORTS to a clone of UAMS-Web/wordpress-importer-exports to run this test.';

    /**
     * The corpus root, or a path under it, or null when the variable is unset.
     *
     * @param  string  $relative  A path under the corpus root, such as `WordPress`
     * @return string|null The path, or null when `WORDPRESS_IMPORTER_EXPORTS` is unset or empty
     *
     * @throws RuntimeException When the variable is set but names no directory
     */
    public static function path(string $relative = ''): ?string
    {
        // Unset or empty means the corpus is not available here
        $root = \getenv(self::ENV);

        if (! \is_string($root) || \trim($root) === '') {
            return null;
        }

        // Set, so it has to be a real directory
        $root = \rtrim($root, '/\\');

        if (! \is_dir($root)) {
            throw new RuntimeException(\sprintf(
                '%s is set to %s, which is not a directory. Point it at a clone of '
                .'UAMS-Web/wordpress-importer-exports, or unset it to skip the corpus tests.',
                self::ENV,
                $root
            ));
        }

        return $relative === '' ? $root : $root.'/'.\ltrim($relative, '/\\');
    }
}
