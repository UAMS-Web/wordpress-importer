<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Tests\Support;

use RuntimeException;

/**
 * One of the host app's submodules at one moment: its checked-out revision
 * plus every path git reports as differing from it.
 *
 * Captured either side of a host-app import test so {@see ContentSubmoduleDiff}
 * can say what the test itself wrote, rather than what happened to be sitting
 * in the checkout already.
 *
 * **This watches the live submodules, and what a failure means differs by
 * which one.** For `content/`, `UAMS-Web/uams-statamic#1995` re-roots every
 * content-backed Stache store, and the asset-metadata dual-write, at a
 * disposable mirror under `APP_ENV=testing` — so a Stache save cannot reach it
 * at all. What that re-rooting does **not** cover is a raw
 * `base_path('content/…')`; this package had three of those until wordpress-importer-archive#622 routed
 * them through the Stache stores. A `content/` failure therefore reports a
 * breach of the host's structural guarantee rather than ordinary litter.
 *
 * For the asset submodules it is the opposite: nothing re-roots an asset
 * download, so an import that fetches a real image writes it for real and a
 * failure there means the test did not clean up (wordpress-importer-archive#764).
 *
 * Both halves of the snapshot are needed. The host's `.gitmodules` sets
 * `ignore = dirty` on `content`, so a plain `git status` at the app root
 * reports nothing at all; and the submodule's own status is blind to a checkout
 * that moved to a different revision while staying clean.
 *
 * @see  ContentSubmoduleDiff::between()
 */
final class ContentSubmoduleState
{
    /**
     * Seconds to wait for a single read from the git subprocess before giving up.
     *
     * A bound rather than an unlimited wait because this runs in the `beforeEach`
     * and `afterEach` of every wired host-app test, inside a band already bounded
     * at 250–750s — a wedged child here would present as "the suite is slow"
     * rather than as an error.
     */
    private const READ_TIMEOUT_SECONDS = 30;

    /**
     * Create a new state record.
     *
     * @param  string|null  $head  The submodule's checked-out revision, or null when git could not report one
     * @param  list<ContentSubmoduleChange>  $changes  Every path differing from that revision
     */
    public function __construct(
        public readonly ?string $head,
        public readonly array $changes,
    ) {}

    /**
     * Read the submodule's current state from git.
     *
     * @param  string  $submodulePath  Absolute path to the submodule to read
     * @return ?self The state, or null when there is no initialized submodule there to guard
     *
     * @throws RuntimeException If a submodule is present but git could not be asked about it
     */
    public static function capture(
        string $submodulePath
    ): ?self {
        // An absent or uninitialized submodule has nothing to guard. This is the
        // ONLY silent null: past this point a probe that fails is a probe
        // failure, and a check that silently verifies nothing is the bug it guards.
        if (! \file_exists($submodulePath.'/.git')) {
            return null;
        }

        // ---
        // `rev-parse HEAD` cannot tell you a submodule is absent: in an empty
        // submodule directory it walks UP to the enclosing superproject and
        // answers for that, so an uninitialized submodule reports the app's own
        // HEAD and reads as a revision mismatch rather than "not initialized".
        // Confirm the directory is its own repository before trusting anything
        // it reports.
        // ---
        $toplevel = self::git($submodulePath, ['rev-parse', '--show-toplevel']);

        if ($toplevel['status'] !== 0) {
            throw new RuntimeException(\sprintf(
                '`git rev-parse --show-toplevel` in %s exited %d, so it could not be confirmed as its own '
                .'repository and the guard would have verified nothing.',
                $submodulePath,
                $toplevel['status']
            ));
        }

        $resolved = \realpath($submodulePath);
        $reported = \realpath(\trim($toplevel['output']));

        if ($resolved === false || $reported !== $resolved) {
            throw new RuntimeException(\sprintf(
                '%s carries a .git but git reports its repository root as %s, so it is not a submodule of its '
                .'own and the guard would have judged the wrong tree.',
                $submodulePath,
                \trim($toplevel['output'])
            ));
        }

        // ---
        // `--untracked-files=all` rather than the default: an import writes
        // whole directories of entries, and the default collapses those to the
        // containing directory, which names none of the files it left behind.
        // `-z` rather than the default: porcelain v1 quotes and C-escapes any
        // path with a space, a quote or a non-ASCII byte, so reading the
        // default form correctly means reversing that escaping first.
        // ---
        $status = self::git($submodulePath, ['status', '--porcelain', '-z', '--untracked-files=all']);

        // A git that failed printing nothing is indistinguishable from a clean tree
        if ($status['status'] !== 0) {
            throw new RuntimeException(\sprintf(
                '`git status` in %s exited %d, so the test\'s effect on that submodule could not be checked.',
                $submodulePath,
                $status['status']
            ));
        }

        $head = self::git($submodulePath, ['rev-parse', 'HEAD']);

        return self::fromPorcelain(
            $status['output'],
            $head['status'] === 0 ? \trim($head['output']) : null
        );
    }

    /**
     * Every submodule path the repository at `$repositoryRoot` declares.
     *
     * Read from `.gitmodules` through git rather than hard-coded, because the
     * gap this closes was created by a submodule being ADDED after the guard was
     * written: `content/` was watched and the three that arrived later were not
     * (wordpress-importer-archive#764). A hard-coded list would reproduce that failure the next time the
     * host gains one, and would do it silently.
     *
     * Paths come back absolute, in declaration order. A repository declaring no
     * submodules — or having no `.gitmodules` at all — yields an empty list
     * rather than an error, since that is a legitimate state rather than a
     * probe failure.
     *
     * @param  string  $repositoryRoot  Absolute path to the superproject's root
     * @return list<string> Absolute paths to each declared submodule
     */
    public static function declaredPaths(
        string $repositoryRoot
    ): array {
        $gitmodules = $repositoryRoot.'/.gitmodules';

        // No `.gitmodules` means no submodules declared — not a failure
        if (! \file_exists($gitmodules)) {
            return [];
        }

        // ---
        // Present but unreadable is a probe failure, and the status check below
        // CANNOT see it: git exits 1 — the same status it uses for "no matching
        // keys" — and explains itself with `warning:` on stderr, which `git()`
        // discards. Measured on git 2.39.5 (macOS): `chmod 000` gives
        // `warning: unable to access '.gitmodules': Permission denied`, and a
        // directory at that path gives `Is a directory`; both exit 1, where a
        // malformed file exits 128. So this precondition is the only thing that
        // separates "declares nothing" from "could not be read" (wordpress-importer-archive#764).
        // ---
        if (! \is_file($gitmodules) || ! \is_readable($gitmodules)) {
            throw new RuntimeException(\sprintf(
                '%s exists but is not a readable file, so the set of submodules to guard could not be '
                .'determined — and git reports that as exit 1, which is indistinguishable from declaring none.',
                $gitmodules
            ));
        }

        // ---
        // `--get-regexp` over the file rather than over the repository config:
        // `git config --get-regexp submodule\..*\.path` reads the *local* config,
        // which lists only submodules this checkout has initialized. `.gitmodules`
        // is the declaration, so an uninitialized submodule is still named — and
        // `capture()` returning null for it is then a reported state rather than
        // an invisible omission.
        //
        // `--null` rather than the default line form. The default separates key
        // from value with a SPACE, and both halves may contain one: a submodule
        // named `odd name` with path `public/with space` prints
        // `submodule.odd name.path public/with space`, which a split on the first
        // space reads as the path `name.path public/with space`. The guard would
        // then watch a directory that does not exist and silently stop watching
        // the one that does. `--null` emits `key\nvalue\0`, which has no such
        // ambiguity.
        // ---
        $config = self::git(
            $repositoryRoot,
            ['config', '--null', '--file', '.gitmodules', '--get-regexp', '^submodule\\..*\\.path$']
        );

        // ---
        // git config exits 1 for "no matching keys", which is a legitimate answer —
        // but NOT only for that: it is also how git reports a file it could not
        // read, which is why the readability precondition above runs first rather
        // than leaving this check to catch everything.
        //
        // Any other non-zero status is a probe failure: a malformed `.gitmodules`
        // exits 128, and `git()` answers -1 both when the process could not start
        // and when it hit the read timeout — the latter printing nothing at all.
        // git's own diagnostics go to stderr, which `git()` discards, so status is
        // the only signal left and an unchecked one leaves the guard reporting
        // "no submodules declared" while verifying nothing (wordpress-importer-archive#764).
        // ---
        if ($config['status'] !== 0 && $config['status'] !== 1) {
            throw new RuntimeException(\sprintf(
                '`git config --null --file .gitmodules --get-regexp` in %s exited %d, so the set of submodules '
                .'to guard could not be determined and the guard would have silently watched none.',
                $repositoryRoot,
                $config['status']
            ));
        }

        $paths = [];

        // Each NUL-terminated record is `submodule.<name>.path` then a newline then the path
        foreach (\explode("\0", $config['output']) as $record) {
            if ($record === '') {
                continue;
            }

            $newline = \strpos($record, "\n");

            // ---
            // A record with no newline is a `path` key with no value at all —
            // `\tpath` with no `=`, which git accepts and emits as a bare
            // `submodule.a.path`. An empty `path =` emits the newline and an empty
            // value, caught just below. Neither can name a directory to guard, so
            // both are dropped rather than guessed at; the count check downstream
            // compares against what this returns, so dropping one cannot present as
            // a partial capture.
            // ---
            if ($newline === false) {
                continue;
            }

            $relative = \substr($record, $newline + 1);

            if ($relative === '') {
                continue;
            }

            $paths[] = $repositoryRoot.'/'.$relative;
        }

        // ---
        // Deduplicate, preserving declaration order. git does NOT reject two
        // submodules declaring the same `path` — verified on git 2.39.5 (macOS):
        // `--get-regexp` returns both. The captured map is keyed by path, so a
        // duplicate would collapse there while the declared count did not, and the
        // partial-capture check would fail with an entirely misleading cause
        // ("captured 1 of the 2 declared") rather than naming the real problem.
        // ---
        return \array_values(\array_unique($paths));
    }

    /**
     * Parse `git status --porcelain -z --untracked-files=all` output.
     *
     * Each entry is `XY <path>`; a rename or copy adds a SECOND field holding
     * the origin path. Consuming that field here is what stops it being read as
     * a further entry with two characters sliced off the front of its path.
     *
     * @param  string  $porcelain  The NUL-separated status output
     * @param  string|null  $head  The revision those paths differ from
     * @return self The parsed state
     */
    public static function fromPorcelain(
        string $porcelain,
        ?string $head
    ): self {
        // The trailing NUL leaves an empty final field; drop it and any other
        $fields = \array_values(\array_filter(
            \explode("\0", $porcelain),
            static fn (string $field): bool => $field !== ''
        ));

        $changes = [];
        $count = \count($fields);

        for ($index = 0; $index < $count; $index++) {
            $field = $fields[$index];

            // Shorter than `XY p` is not an entry git wrote; skipping beats
            // slicing a path out of something that is not one
            if (\strlen($field) < 4) {
                continue;
            }

            $status = \substr($field, 0, 2);
            $origin = null;

            // Consume the rename/copy origin so it is not read as its own entry
            if (\str_contains($status, 'R') || \str_contains($status, 'C')) {
                $index++;
                $origin = $fields[$index] ?? null;
            }

            $changes[] = new ContentSubmoduleChange($status, \substr($field, 3), $origin);
        }

        return new self($head, $changes);
    }

    /**
     * Run a git command inside the submodule.
     *
     * Two details are load-bearing rather than stylistic:
     *
     * The array command form bypasses the shell entirely, so a path containing
     * a space or a quote needs no escaping and cannot be re-split — and it is
     * portable, where a `2>/dev/null` redirect appended to a shell string is
     * not (`cmd.exe` reads `/dev/null` as the path `\dev\null`, fails to open
     * it, and returns 1 without running git at all).
     *
     * Both pipes are drained CONCURRENTLY. Reading stdout to EOF first and
     * stderr afterwards deadlocks whenever git writes more than one pipe buffer
     * (~64 KiB) to stderr — one `warning: could not open directory …` per
     * unreadable directory will do it: the child blocks writing into a full
     * stderr pipe, so it never closes stdout, so the parent never returns.
     *
     * @param  string  $submodulePath  Absolute path to run the command in
     * @param  list<string>  $arguments  Git arguments after the `-C <path>` pair
     * @return array{status: int, output: string} The exit code and stdout
     */
    private static function git(
        string $submodulePath,
        array $arguments
    ): array {
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $pipes = [];
        $process = \proc_open(
            \array_merge(['git', '-C', $submodulePath], $arguments),
            $descriptors,
            $pipes
        );

        // No git on PATH, or the process could not be started
        if (! \is_resource($process)) {
            return ['status' => -1, 'output' => ''];
        }

        \stream_set_blocking($pipes[1], false);
        \stream_set_blocking($pipes[2], false);

        $output = '';
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        $timedOut = false;

        while ($open !== []) {
            $read = \array_values($open);
            $write = null;
            $except = null;

            $ready = \stream_select($read, $write, $except, self::READ_TIMEOUT_SECONDS);

            // A select error, or a child that has gone quiet without exiting
            if ($ready === false || $ready === 0) {
                $timedOut = true;

                break;
            }

            foreach ($read as $stream) {
                $chunk = \fread($stream, 8192);

                // Keep only stdout; stderr is drained purely so it cannot fill
                if (\is_string($chunk) && $chunk !== '' && $stream === $pipes[1]) {
                    $output .= $chunk;
                }

                if (\feof($stream)) {
                    $open = \array_filter($open, static fn ($candidate): bool => $candidate !== $stream);
                }
            }
        }

        \fclose($pipes[1]);
        \fclose($pipes[2]);

        // Do not wait on a child that stopped talking; proc_close would block
        if ($timedOut) {
            \proc_terminate($process);
            \proc_close($process);

            return ['status' => -1, 'output' => ''];
        }

        return [
            'status' => \proc_close($process),
            'output' => $output,
        ];
    }
}
