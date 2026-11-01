<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Tests\Support;

/**
 * Splits what a host-app import test wrote into a guarded submodule from
 * what was already sitting there when the test started.
 *
 * A before/after diff rather than a bare "is it dirty now", because these tests
 * run against a long-lived host slot that is reused. A bare check blames
 * whichever run happens to notice a file some earlier run stranded, which is
 * the "caught three runs later" confusion this exists to end — and worse, wordpress-importer-archive#464's
 * stale `kb-home` root went unnoticed long enough to be captured as a snapshot
 * baseline. Diffing makes the accusation specific: this test's litter fails the
 * test, and a checkout that arrived dirty is reported separately so a stale file
 * cannot red an innocent branch forever.
 *
 * **There is deliberately no allowlist of tolerated paths, and the host app is
 * the precedent for that rather than the counter-example.** Its equivalent gate
 * declares `KNOWN_CONTENT_LITTER`, but the list is **empty**, kept only so that
 * any future tolerated path has to be added deliberately and visibly — its
 * emptiness is pinned by a test there. Its one historical entry
 * (`models/assets/`) was retired by closing the write at its seam rather than
 * by tolerating it. This package holds the same line with no list at all,
 * because it has somewhere better to put an expected write: the path belongs in
 * that test's {@see
 * \UAMSWeb\Aether\WordPressImporter\Tests\Feature\WordPressImportTestTrait::snapshotIsolatedState()}
 * call, which restores it. (PHPStan at level max independently refuses the
 * empty-list form — `foreach.emptyArray` — so it could not be carried over inert.)
 *
 * @see  ContentSubmoduleState::capture()
 */
final class ContentSubmoduleDiff
{
    /**
     * Create a new diff result.
     *
     * @param  list<ContentSubmoduleChange>  $introduced  Paths this run dirtied
     * @param  list<ContentSubmoduleChange>  $preexisting  Paths that were already dirty before the run
     * @param  string|null  $headBefore  The submodule revision checked out before the run
     * @param  string|null  $headAfter  The submodule revision checked out after it
     */
    public function __construct(
        public readonly array $introduced,
        public readonly array $preexisting,
        public readonly ?string $headBefore,
        public readonly ?string $headAfter,
    ) {}

    /**
     * Classify the after-state against the before-state.
     *
     * @param  ContentSubmoduleState  $before  State captured before the test ran
     * @param  ContentSubmoduleState  $after  State captured after its teardown
     * @return self The classified difference
     */
    public static function between(
        ContentSubmoduleState $before,
        ContentSubmoduleState $after
    ): self {
        // Identify by path: the question is whether this path was already dirty,
        // not whether it was dirty in exactly the same way
        $existing = [];

        foreach ($before->changes as $change) {
            $existing[$change->path] = true;
        }

        return new self(
            \array_values(\array_filter(
                $after->changes,
                static fn (ContentSubmoduleChange $change): bool => ! isset($existing[$change->path])
            )),
            \array_values(\array_filter(
                $after->changes,
                static fn (ContentSubmoduleChange $change): bool => isset($existing[$change->path])
            )),
            $before->head,
            $after->head,
        );
    }

    /**
     * Determine whether the submodule's checked-out revision moved during the run.
     *
     * Only a move between two revisions git actually reported counts. An
     * unreadable revision on either side is a probe that failed, not a test
     * that checked something out, and blaming the run for it would make the
     * guard cry wolf on an environment problem.
     *
     * @return bool True when both revisions were readable and they differ
     */
    public function headMoved(): bool
    {
        return $this->headBefore !== null
            && $this->headAfter !== null
            && $this->headBefore !== $this->headAfter;
    }

    /**
     * Determine whether the run left the submodule as it found it.
     *
     * Pre-existing dirt does not count against the run, by design — see the
     * class docblock.
     *
     * @return bool True when nothing is attributable to this run
     */
    public function isClean(): bool
    {
        return $this->introduced === [] && ! $this->headMoved();
    }

    /**
     * Build the message a failing run reports, naming every path and the
     * command that restores the submodule.
     *
     * @param  string  $submodulePath  Absolute path to the submodule being reported
     * @return string The failure message; empty when {@see self::isClean()}
     */
    public function failureMessage(
        string $submodulePath
    ): string {
        if ($this->isClean()) {
            return '';
        }

        $lines = [];

        // Name the revision move first: it invalidates every path below it
        if ($this->headMoved()) {
            $lines[] = \sprintf(
                'The test moved the submodule at %s from %s to %s.',
                $submodulePath,
                (string) $this->headBefore,
                (string) $this->headAfter
            );
        }

        // Then each path the run introduced, with what its status implies
        if ($this->introduced !== []) {
            $lines[] = \sprintf(
                'The test wrote into the submodule at %s (%d path%s):',
                $submodulePath,
                \count($this->introduced),
                \count($this->introduced) === 1 ? '' : 's'
            );

            foreach ($this->introduced as $change) {
                $lines[] = '  '.$change->line();
                $lines[] = '       '.$change->describe();
            }

            // True wherever the write landed
            $lines[] = 'Cleanup that runs in `afterEach` cannot survive a run killed at its bound or a fatal, so the';
            $lines[] = 'file is stranded and every later run starts from a checkout that already carries it.';

            // ---
            // The remedy differs by submodule, and offering the wrong one costs more
            // than offering none. `content/` is guarded for a structural reason — a
            // raw `base_path('content/…')` write that the test-env re-rooting does
            // not cover — while the asset submodules collect ordinary per-test
            // litter that the cleanup list is supposed to remove (wordpress-importer-archive#764).
            // ---
            if (\str_ends_with($submodulePath, '/content')) {
                $lines[] = 'A test persisted content into the live submodule, where every later run — and every';
                $lines[] = '`statamic:stache:warm` — reads it as real content (wordpress-importer-archive#464).';
                $lines[] = '  Declare the path in this test\'s `snapshotIsolatedState()` call so `restoreIsolatedState()`';
                $lines[] = '  puts it back, rather than relying on slug-list cleanup to find what the import wrote.';
            } else {
                $lines[] = 'An import wrote a real asset here and nothing removed it.';
                $lines[] = '  Register the file in this test\'s `cleanupTestFilesWithPaths()` call — its `$assetFiles`';
                $lines[] = '  argument, keyed by container — or purge it directly where the name is built at run time';
                $lines[] = '  and a static list cannot express it.';
            }
        }

        // Finally the commands that put the checkout back
        $lines[] = 'Restore the submodule with:';

        foreach ($this->restoreCommands($submodulePath) as $command) {
            $lines[] = '  '.$command;
        }

        return \implode("\n", $lines);
    }

    /**
     * Build the warnings a run reports without failing on them.
     *
     * @param  string  $submodulePath  Absolute path to the submodule being reported
     * @return list<string> One message per non-fatal observation, empty when there are none
     */
    public function warnings(
        string $submodulePath
    ): array {
        // A checkout that arrived dirty is somebody else's residue, named but not charged
        if ($this->preexisting === []) {
            return [];
        }

        return [
            \sprintf(
                '%s arrived dirty (%d path%s), not attributed to this run: %s. Restore with: %s',
                $submodulePath,
                \count($this->preexisting),
                \count($this->preexisting) === 1 ? '' : 's',
                \implode(', ', \array_map(
                    static fn (ContentSubmoduleChange $change): string => $change->line(),
                    $this->preexisting
                )),
                \implode(' && ', $this->restoreCommands($submodulePath))
            ),
        ];
    }

    /**
     * Name the commands that put the submodule back the way it was.
     *
     * Which commands depends on what is actually wrong, and naming only one of
     * them sends the reader away with a sequence that leaves the checkout dirty:
     *
     * - `checkout -- .` cannot remove an untracked file; `clean -fd` can.
     * - `clean -fd` cannot restore a modified one; `checkout -- .` can.
     * - Neither touches the **index**. `checkout -- .` restores the working
     *   tree from the index, not from HEAD — so against a staged addition it
     *   exits 0 and changes nothing, and against a staged deletion it fails
     *   outright with `pathspec '.' did not match any file(s) known to git`.
     *   A staged change therefore needs the index cleared first, or the guard
     *   reports the same dirt on every subsequent run.
     *
     * @param  string  $submodulePath  Absolute path to the submodule being reported
     * @return list<string> The restore commands, in the order to run them
     */
    private function restoreCommands(
        string $submodulePath
    ): array {
        $commands = [];
        $hasUntracked = false;
        $hasTracked = false;
        $hasStaged = false;

        foreach (\array_merge($this->introduced, $this->preexisting) as $change) {
            if ($change->status === '??') {
                $hasUntracked = true;

                continue;
            }

            $hasTracked = true;

            // The index field is the FIRST character; anything but a space is staged
            if ($change->status !== '' && $change->status[0] !== ' ') {
                $hasStaged = true;
            }
        }

        // Clear the index first, or the worktree restore below is a no-op
        if ($hasStaged) {
            $commands[] = \sprintf('git -C %s restore --staged -- .', $submodulePath);
        }

        if ($hasTracked) {
            $commands[] = \sprintf('git -C %s checkout -- .', $submodulePath);
        }

        if ($hasUntracked) {
            $commands[] = \sprintf('git -C %s clean -fd', $submodulePath);
        }

        // A moved revision is put back by name, and only the before-state knows it
        if ($this->headMoved()) {
            $commands[] = \sprintf('git -C %s checkout %s', $submodulePath, (string) $this->headBefore);
        }

        return $commands;
    }
}
