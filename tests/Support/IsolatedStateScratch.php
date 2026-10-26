<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Tests\Support;

use UAMSWeb\WordPressImporter\Tests\Feature\WordPressImportTestTrait;

/**
 * Names and reclaims the per-test snapshot directories
 * {@see WordPressImportTestTrait::snapshotIsolatedState()}
 * writes under `storage/framework/`.
 *
 * `restoreIsolatedState()` already removes the directory it created, so a test
 * that reaches its `afterEach` leaves nothing behind. What leaks is every path
 * that never reaches it — a test erroring out of `beforeEach`, a run killed
 * mid-file, a bounded command whose timeout fires. Each of those strands ~1.2 MB
 * across 231 files, and because the directories are untracked at the host root
 * they make `scripts/worktree-slots.mjs release` refuse the slot until someone
 * removes them by hand (wordpress-importer-archive#739).
 *
 * The name carries the owning process id precisely so a later run can tell an
 * abandoned directory from one a concurrent run is still using — the host band
 * supports two slots, so "delete every sibling" would be wrong.
 */
final class IsolatedStateScratch
{
    /**
     * Directory-name prefix, unique enough to sweep on.
     */
    public const PREFIX = 'wpimport-iso-';

    /**
     * Build the directory name for a run.
     *
     * @param  int  $pid  The owning process id
     * @param  string  $unique  A per-call suffix, so one process may hold several
     * @return string The directory basename
     */
    public static function name(int $pid, string $unique): string
    {
        return self::PREFIX.$pid.'-'.$unique;
    }

    /**
     * Read the owning process id back out of a directory basename.
     *
     * @param  string  $basename  A directory basename
     * @return int|null The process id, or null when the name is not ours
     */
    public static function pidOf(string $basename): ?int
    {
        if (! \str_starts_with($basename, self::PREFIX)) {
            return null;
        }

        $remainder = \substr($basename, \strlen(self::PREFIX));
        $separator = \strpos($remainder, '-');

        if ($separator === false || $separator === 0) {
            return null;
        }

        $pid = \substr($remainder, 0, $separator);

        // A non-numeric segment is not a name this class produced; leaving it
        // alone is the safe reading, since sweeping is a delete.
        return \ctype_digit($pid) ? (int) $pid : null;
    }

    /**
     * Select the directories no live run owns.
     *
     * Fails closed in both directions that matter: anything whose name this
     * class did not produce is left alone, and so is anything belonging to the
     * current process or to a process still running. Only a directory whose
     * owner is provably gone is reclaimed.
     *
     * @param  array<int, string>  $basenames  Directory basenames to consider
     * @param  int  $currentPid  The running process, never swept
     * @param  callable(int): bool  $isRunning  Answers whether a pid is alive
     * @return list<string> The basenames safe to remove
     */
    public static function abandoned(
        array $basenames,
        int $currentPid,
        callable $isRunning
    ): array {
        $abandoned = [];

        foreach ($basenames as $basename) {
            $pid = self::pidOf($basename);

            if ($pid === null || $pid === $currentPid) {
                continue;
            }

            if ($isRunning($pid)) {
                continue;
            }

            $abandoned[] = $basename;
        }

        return $abandoned;
    }
}
