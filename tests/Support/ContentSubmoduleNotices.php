<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Tests\Support;

/**
 * Process-wide record of `content/` observations already reported.
 *
 * A checkout that arrived dirty is dirty for every test in every file, so
 * reporting it per test would print the same lines dozens of times in a passing
 * run — the noise wordpress-importer-archive#616 tracks.
 *
 * **This lives on a class rather than on the trait for a reason that is easy to
 * get wrong.** A static declared on a trait is not shared by the classes that
 * compose it: each composing class gets its OWN copy, so a registry kept there
 * would deduplicate per test class and still print the same line once per file.
 * A static on a normal class is genuinely one per process, which is what the
 * guarantee needs.
 */
final class ContentSubmoduleNotices
{
    /**
     * Messages already printed this process, keyed by the message itself.
     *
     * Keyed by message rather than by kind so a second, genuinely different
     * observation still gets through.
     *
     * @var array<string, true>
     */
    private static array $reported = [];

    /**
     * Record a notice, reporting whether this call is the first to see it.
     *
     * @param  string  $notice  The message, deduplicated verbatim
     * @return bool True when the caller should print it
     */
    public static function shouldReport(
        string $notice
    ): bool {
        if (isset(self::$reported[$notice])) {
            return false;
        }

        self::$reported[$notice] = true;

        return true;
    }

    /**
     * Forget every recorded notice.
     *
     * Exists so a test can prove the deduplication rather than inherit whatever
     * an earlier test in the same process happened to report.
     */
    public static function reset(): void
    {
        self::$reported = [];
    }
}
