<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Tests\Support;

/**
 * One path git reports as differing from a guarded submodule's HEAD.
 *
 * Parsed out of `git status --porcelain -z --untracked-files=all`, one entry
 * per path. The status is the raw two-character porcelain field so the value
 * object stays a faithful record of what git said; {@see self::describe()}
 * turns it into the sentence a failing test needs to read.
 *
 * @see  ContentSubmoduleState::fromPorcelain()
 */
final class ContentSubmoduleChange
{
    /**
     * Create a new change record.
     *
     * @param  string  $status  The two-character porcelain status field (`??`, ` M`, `R `, …)
     * @param  string  $path  Path relative to the submodule root, unquoted (the `-z` form has no escaping)
     * @param  string|null  $origin  For a rename or copy, the path it came from; null otherwise
     */
    public function __construct(
        public readonly string $status,
        public readonly string $path,
        public readonly ?string $origin = null,
    ) {}

    /**
     * Say what the status means for the import test, not what it means to git.
     *
     * The reader's question is always "why does this path matter", and `??` is
     * the one that has to be spelled out: an entry a test imported and failed
     * to clean up is untracked, so it reads as an addition while being the
     * exact residue this check exists to catch (wordpress-importer-archive#464).
     *
     * @return string A sentence naming what the status implies for the run
     */
    public function describe(): string
    {
        // An untracked path is the import residue this guard exists to catch
        if ($this->status === '??') {
            return 'on disk but absent from the submodule\'s HEAD — an import a test did not clean up, or stray scratch';
        }

        // A deletion means the test removed real content rather than adding to it
        if (\str_contains($this->status, 'D')) {
            return 'tracked by the submodule\'s HEAD but missing from the working tree — the test deleted real content';
        }

        // A modification means the test rewrote a file that belongs to the host
        if (\str_contains($this->status, 'M') || \str_contains($this->status, 'T')) {
            return 'content differs from the submodule\'s HEAD — the test rewrote a file the host owns';
        }

        return 'differs from the submodule\'s HEAD';
    }

    /**
     * Render the `XY path` line a report lists this change on.
     *
     * @return string The status, the path, and the origin path when it is a rename or copy
     */
    public function line(): string
    {
        // Name the origin too, or a rename reads as an unexplained new path
        $origin = $this->origin !== null ? ' (from '.$this->origin.')' : '';

        return $this->status.' '.$this->path.$origin;
    }
}
