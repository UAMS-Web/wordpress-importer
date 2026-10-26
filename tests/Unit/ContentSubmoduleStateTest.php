<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use UAMSWeb\WordPressImporter\Tests\Support\ContentSubmoduleChange;
use UAMSWeb\WordPressImporter\Tests\Support\ContentSubmoduleDiff;
use UAMSWeb\WordPressImporter\Tests\Support\ContentSubmoduleState;

/**
 * Run a git command, returning its exit status and trimmed stdout.
 *
 * `proc_open` with an array command rather than `exec()` with a shell string:
 * the shell form needs a redirect to keep git's stderr out of the test output,
 * and `2>/dev/null` is POSIX-only — `cmd.exe` reads it as the path `\dev\null`,
 * fails to open it, and returns 1 WITHOUT running git. Every test in this file
 * would then read the probe below as "git is not available" and self-skip,
 * leaving a green run that verified nothing on a platform this package
 * supports.
 *
 * @param  string  $directory  Directory to run the command in
 * @param  list<string>  $arguments  Git arguments after the `-C <dir>` pair
 * @return array{status: int, output: string} The exit code and trimmed stdout
 */
function submoduleGit(string $directory, array $arguments): array
{
    $pipes = [];
    $process = \proc_open(
        \array_merge(['git', '-C', $directory], $arguments),
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );

    if (! \is_resource($process)) {
        return ['status' => -1, 'output' => ''];
    }

    // Sequential reads are safe HERE, unlike in ContentSubmoduleState::git():
    // these run against throwaway fixtures holding a handful of files, so
    // neither stream can approach the 64 KiB pipe buffer that deadlocks a
    // stdout-first read. That helper faces a 3,200-file live submodule and
    // drains both pipes concurrently for exactly that reason.
    $output = \stream_get_contents($pipes[1]);
    \stream_get_contents($pipes[2]);
    \fclose($pipes[1]);
    \fclose($pipes[2]);

    return [
        'status' => \proc_close($process),
        'output' => \trim(\is_string($output) ? $output : ''),
    ];
}

/**
 * Commit everything currently in a repository, with an identity of our own so
 * the run does not depend on the machine's git config.
 *
 * @param  string  $directory  Repository to commit in
 * @param  string  $message  The commit message
 */
function submoduleCommit(string $directory, string $message): void
{
    submoduleGit($directory, ['add', '-A']);
    submoduleGit($directory, [
        '-c', 'user.email=guard@example.invalid',
        '-c', 'user.name=Guard Fixture',
        '-c', 'commit.gpgsign=false',
        'commit', '--quiet', '-m', $message,
    ]);
}

/**
 * Capture a fixture submodule's state, failing the test if it reads as absent.
 *
 * The production call is nullable by design — an uninitialized submodule is not
 * an error — but every test below has just built a real one, so a null there is
 * a broken fixture rather than a case to handle.
 *
 * @param  string  $path  The submodule directory
 * @return ContentSubmoduleState The captured state
 */
function captureSubmodule(string $path): ContentSubmoduleState
{
    $state = ContentSubmoduleState::capture($path);

    if ($state === null) {
        Assert::fail('Expected an initialized submodule at '.$path.'.');
    }

    return $state;
}

beforeEach(function (): void {
    // The guard shells out to git; without it there is nothing to exercise
    if (submoduleGit(\sys_get_temp_dir(), ['--version'])['status'] !== 0) {
        Assert::markTestSkipped('git is not available on PATH.');
    }

    $root = \sys_get_temp_dir().'/wpimport-submodule-guard-'.\getmypid().'-'.\uniqid();
    \mkdir($root.'/origin', 0777, true);
    \mkdir($root.'/host', 0777, true);

    // The repository the submodule is cloned from
    submoduleGit($root.'/origin', ['-c', 'init.defaultBranch=main', 'init', '--quiet']);
    \file_put_contents($root.'/origin/collections.yaml', "title: Posts\n");
    submoduleCommit($root.'/origin', 'seed');

    // The superproject, carrying that repository at `content` exactly as the
    // host app does — so `content/.git` is a gitdir POINTER FILE, not a
    // directory, which is the shape the guard actually meets in production.
    submoduleGit($root.'/host', ['-c', 'init.defaultBranch=main', 'init', '--quiet']);
    submoduleGit($root.'/host', [
        '-c', 'protocol.file.allow=always',
        'submodule', 'add', '--quiet', $root.'/origin', 'content',
    ]);
    // ---
    // A SECOND submodule, at a nested path, because `content/` being the only one
    // guarded is the whole of wordpress-importer-archive#764 — and because a nested path is the shape
    // `public/assets` and `app/private/assets` actually have, which a flat
    // sibling would not exercise.
    // ---
    \mkdir($root.'/origin-assets', 0777, true);
    submoduleGit($root.'/origin-assets', ['-c', 'init.defaultBranch=main', 'init', '--quiet']);
    \file_put_contents($root.'/origin-assets/placeholder.txt', "asset\n");
    submoduleCommit($root.'/origin-assets', 'seed');

    submoduleGit($root.'/host', [
        '-c', 'protocol.file.allow=always',
        'submodule', 'add', '--quiet', $root.'/origin-assets', 'public/assets',
    ]);

    submoduleCommit($root.'/host', 'add content and public/assets submodules');

    $this->guardRoot = $root;
    $this->content = $root.'/content-unused';
});

afterEach(function (): void {
    if (\is_string($this->guardRoot ?? null) && \str_contains($this->guardRoot, 'wpimport-submodule-guard-')) {
        \exec('rm -rf '.\escapeshellarg($this->guardRoot));
    }
});

it('reads the revision and a clean tree from a real submodule', function (): void {
    $content = $this->guardRoot.'/host/content';

    // The shape the host app really has: a gitdir pointer file, not a directory
    expect(\is_file($content.'/.git'))->toBeTrue();

    $state = ContentSubmoduleState::capture($content);

    expect($state)->toBeInstanceOf(ContentSubmoduleState::class);
    expect(captureSubmodule($content)->head)->toBe(submoduleGit($content, ['rev-parse', 'HEAD'])['output']);
    expect(captureSubmodule($content)->changes)->toBe([]);
});

it('catches a file the run wrote into the submodule', function (): void {
    $content = $this->guardRoot.'/host/content';
    $before = captureSubmodule($content);

    // Stand in for an import that persisted an entry and never cleaned it up
    \mkdir($content.'/collections/posts', 0777, true);
    \file_put_contents($content.'/collections/posts/imported.md', "---\ntitle: Imported\n---\n");

    $after = captureSubmodule($content);
    $diff = ContentSubmoduleDiff::between($before, $after);

    expect($diff->isClean())->toBeFalse();
    expect(\array_map(fn (ContentSubmoduleChange $c): string => $c->path, $diff->introduced))
        ->toBe(['collections/posts/imported.md']);
    expect($diff->failureMessage($content))->toContain('collections/posts/imported.md');
    expect($diff->failureMessage($content))->toContain('git -C '.$content.' clean -fd');
});

it('names every file of a written directory rather than just the directory', function (): void {
    // `--untracked-files=all` is what makes this true; the default collapses
    // them to `collections/posts/` and names none of the files left behind.
    $content = $this->guardRoot.'/host/content';
    $before = captureSubmodule($content);

    \mkdir($content.'/collections/posts', 0777, true);
    \file_put_contents($content.'/collections/posts/one.md', "one\n");
    \file_put_contents($content.'/collections/posts/two.md', "two\n");

    $diff = ContentSubmoduleDiff::between($before, captureSubmodule($content));

    expect(\array_map(fn (ContentSubmoduleChange $c): string => $c->path, $diff->introduced))
        ->toBe(['collections/posts/one.md', 'collections/posts/two.md']);
});

it('catches a path with a space in it, end to end through git', function (): void {
    $content = $this->guardRoot.'/host/content';
    $before = captureSubmodule($content);

    \file_put_contents($content.'/two words.md', "spaced\n");

    $diff = ContentSubmoduleDiff::between($before, captureSubmodule($content));

    // Unquoted and unescaped: porcelain v1 would have rendered this `"two words.md"`
    expect(\array_map(fn (ContentSubmoduleChange $c): string => $c->path, $diff->introduced))
        ->toBe(['two words.md']);
});

it('catches a tracked file the run rewrote', function (): void {
    $content = $this->guardRoot.'/host/content';
    $before = captureSubmodule($content);

    \file_put_contents($content.'/collections.yaml', "title: Rewritten\n");

    $diff = ContentSubmoduleDiff::between($before, captureSubmodule($content));

    expect($diff->isClean())->toBeFalse();
    expect($diff->introduced[0]->path)->toBe('collections.yaml');
    expect($diff->failureMessage($content))->toContain('git -C '.$content.' checkout -- .');
});

it('does not charge the run for a file that was already there when it started', function (): void {
    $content = $this->guardRoot.'/host/content';

    // Stranded by some earlier run, before this one captured anything
    \file_put_contents($content.'/stranded.md', "left behind\n");

    $before = captureSubmodule($content);
    $after = captureSubmodule($content);
    $diff = ContentSubmoduleDiff::between($before, $after);

    expect($diff->isClean())->toBeTrue();
    expect($diff->introduced)->toBe([]);
    expect($diff->preexisting[0]->path)->toBe('stranded.md');
    expect($diff->warnings($content)[0])->toContain('arrived dirty');
});

it('charges only the new file when the checkout also arrived dirty', function (): void {
    $content = $this->guardRoot.'/host/content';

    \file_put_contents($content.'/stranded.md', "left behind\n");
    $before = captureSubmodule($content);

    \file_put_contents($content.'/imported.md', "this run\n");
    $diff = ContentSubmoduleDiff::between($before, captureSubmodule($content));

    expect(\array_map(fn (ContentSubmoduleChange $c): string => $c->path, $diff->introduced))->toBe(['imported.md']);
    expect(\array_map(fn (ContentSubmoduleChange $c): string => $c->path, $diff->preexisting))->toBe(['stranded.md']);
});

it('catches a submodule checked out onto another revision', function (): void {
    $content = $this->guardRoot.'/host/content';
    $before = captureSubmodule($content);

    // The shape a status probe alone is blind to: the tree is clean afterwards,
    // but it is clean against a DIFFERENT commit.
    \file_put_contents($content.'/collections.yaml', "title: Moved\n");
    submoduleCommit($content, 'move the submodule forward');

    $after = captureSubmodule($content);
    $diff = ContentSubmoduleDiff::between($before, $after);

    expect($after->changes)->toBe([]);
    expect($diff->headMoved())->toBeTrue();
    expect($diff->isClean())->toBeFalse();
    expect($diff->failureMessage($content))->toContain('git -C '.$content.' checkout '.$before->head);
});

it('returns null for an uninitialized submodule rather than answering for the superproject', function (): void {
    $host = $this->guardRoot.'/host';
    $content = $host.'/content';

    // Deinitialize, leaving the empty directory the gitlink still points at
    submoduleGit($host, ['submodule', 'deinit', '-f', 'content']);

    expect(\file_exists($content.'/.git'))->toBeFalse();

    // The trap, demonstrated rather than asserted from memory: `rev-parse HEAD`
    // inside the empty directory walks UP and answers for the superproject, so
    // a guard trusting it would compare the app's revision to the submodule's.
    expect(submoduleGit($content, ['rev-parse', 'HEAD'])['output'])
        ->toBe(submoduleGit($host, ['rev-parse', 'HEAD'])['output']);

    expect(ContentSubmoduleState::capture($content))->toBeNull();
});

it('returns null for a plain directory that is not a repository of its own', function (): void {
    $host = $this->guardRoot.'/host';
    \mkdir($host.'/not-a-submodule');

    expect(ContentSubmoduleState::capture($host.'/not-a-submodule'))->toBeNull();
});

it('returns null for a path that does not exist', function (): void {
    expect(ContentSubmoduleState::capture($this->guardRoot.'/host/absent'))->toBeNull();
});

it('refuses a directory whose git reports a different repository root', function (): void {
    // The branch the `--show-toplevel` check exists for, and the only shape that
    // reaches it: a `.git` whose gitdir sets `core.worktree` elsewhere. Without
    // the check, `capture()` reports the OTHER repository's revision and status
    // as though they were the content submodule's.
    $root = $this->guardRoot;
    \mkdir($root.'/elsewhere', 0777, true);
    submoduleGit($root.'/elsewhere', ['-c', 'init.defaultBranch=main', 'init', '--quiet']);
    \file_put_contents($root.'/elsewhere/other.txt', "other\n");
    submoduleCommit($root.'/elsewhere', 'a different repository');
    submoduleGit($root.'/elsewhere/.git', ['config', 'core.worktree', $root.'/elsewhere']);

    $decoy = $root.'/host/decoy';
    \mkdir($decoy);
    \file_put_contents($decoy.'/.git', 'gitdir: '.$root.'/elsewhere/.git'."\n");

    // Demonstrate the misdirection rather than asserting it from memory
    expect(submoduleGit($decoy, ['rev-parse', '--show-toplevel'])['output'])
        ->toBe(submoduleGit($root.'/elsewhere', ['rev-parse', '--show-toplevel'])['output']);

    expect(fn (): ?ContentSubmoduleState => ContentSubmoduleState::capture($decoy))
        ->toThrow(RuntimeException::class);
});

it('refuses a .git that points at a gitdir which does not exist', function (): void {
    // A broken pointer must fail loudly. Returning null here would disarm the
    // guard for the whole run behind a single deduplicated notice.
    $decoy = $this->guardRoot.'/host/broken';
    \mkdir($decoy);
    \file_put_contents($decoy.'/.git', 'gitdir: '.$this->guardRoot.'/no-such-gitdir'."\n");

    expect(fn (): ?ContentSubmoduleState => ContentSubmoduleState::capture($decoy))
        ->toThrow(RuntimeException::class);
});

it('declares every submodule in .gitmodules, not just the first', function (): void {
    $host = $this->guardRoot.'/host';

    $paths = ContentSubmoduleState::declaredPaths($host);

    // ---
    // Absolute and in declaration order. The order matters only for reporting,
    // but pinning it makes an accidental sort visible.
    // ---
    expect($paths)->toBe([
        $host.'/content',
        $host.'/public/assets',
    ]);
});

it('catches a file written into a submodule other than content', function (): void {
    $host = $this->guardRoot.'/host';
    $assets = $host.'/public/assets';

    $before = captureSubmodule($assets);

    // The shape wordpress-importer-archive#764 was filed for: a real file left in public/assets
    \file_put_contents($assets.'/2019-11-stray-image.jpg', "not really a jpg\n");

    $after = captureSubmodule($assets);
    $diff = ContentSubmoduleDiff::between($before, $after);

    expect($diff->isClean())->toBeFalse();
    expect($diff->failureMessage($assets))->toContain('2019-11-stray-image.jpg');

    // ---
    // The message must offer the remedy that fits this submodule. Content's advice
    // — declare the path in snapshotIsolatedState() — does not apply to an asset an
    // import downloaded, and sending someone to the wrong fix costs more than
    // sending them nowhere.
    // ---
    expect($diff->failureMessage($assets))
        ->toContain('cleanupTestFilesWithPaths()')
        ->and($diff->failureMessage($assets))->not->toContain('snapshotIsolatedState()')
        ->and($diff->failureMessage($assets))->not->toContain('statamic:stache:warm');

    // And the content submodule still gets its own, so the branch is a fork rather
    // than a replacement
    $content = $this->guardRoot.'/host/content';
    $contentBefore = captureSubmodule($content);
    \file_put_contents($content.'/stray.yaml', "title: Stray\n");

    expect(ContentSubmoduleDiff::between($contentBefore, captureSubmodule($content))->failureMessage($content))
        ->toContain('snapshotIsolatedState()')
        ->and(ContentSubmoduleDiff::between($contentBefore, captureSubmodule($content))->failureMessage($content))
        ->not->toContain('cleanupTestFilesWithPaths()');

    \unlink($content.'/stray.yaml');

    // ---
    // The other direction, which is the half that makes the assertion mean
    // something: with the file removed the same comparison is clean, so the
    // failure above is the file and not the fixture.
    // ---
    \unlink($assets.'/2019-11-stray-image.jpg');

    expect(ContentSubmoduleDiff::between($before, captureSubmodule($assets))->isClean())->toBeTrue();
});

it('still declares a submodule this checkout has not initialized', function (): void {
    $host = $this->guardRoot.'/host';

    // Deinitialize one, exactly as an on-demand submodule sits in a fresh slot
    submoduleGit($host, ['submodule', 'deinit', '--force', 'public/assets']);

    // ---
    // `.gitmodules` is the DECLARATION, so the path is still named — which is what
    // lets the guard report "declared but not initialized" rather than quietly
    // watching a smaller set than it appears to.
    // ---
    expect(ContentSubmoduleState::declaredPaths($host))->toContain($host.'/public/assets');

    // And capture() answers null for it rather than throwing
    expect(ContentSubmoduleState::capture($host.'/public/assets'))->toBeNull();
});

it('returns no paths for a repository declaring no submodules', function (): void {
    $bare = $this->guardRoot.'/no-submodules';
    \mkdir($bare, 0777, true);
    submoduleGit($bare, ['-c', 'init.defaultBranch=main', 'init', '--quiet']);

    expect(ContentSubmoduleState::declaredPaths($bare))->toBe([]);
});

it('throws when git cannot read .gitmodules rather than reporting none declared', function (): void {
    $broken = $this->guardRoot.'/broken-gitmodules';
    \mkdir($broken, 0777, true);
    submoduleGit($broken, ['-c', 'init.defaultBranch=main', 'init', '--quiet']);

    // A section header git cannot parse. It exits 128 and prints to STDERR, which
    // the helper discards — so the status is the only signal there is.
    \file_put_contents($broken.'/.gitmodules', "[submodule \"content\"\n  path = content\n");

    // ---
    // The failure this pins: returning [] here reads to the caller as "this host
    // declares no submodules", the guard then watches nothing, and the run passes.
    // A probe that cannot answer must say so (wordpress-importer-archive#764).
    // ---
    expect(fn (): array => ContentSubmoduleState::declaredPaths($broken))
        ->toThrow(RuntimeException::class);
});

it('parses a submodule whose name and path contain spaces', function (): void {
    $spaced = $this->guardRoot.'/spaced';
    \mkdir($spaced, 0777, true);
    submoduleGit($spaced, ['-c', 'init.defaultBranch=main', 'init', '--quiet']);

    \file_put_contents(
        $spaced.'/.gitmodules',
        "[submodule \"content\"]\n\tpath = content\n\turl = x\n"
        ."[submodule \"odd name\"]\n\tpath = public/with space\n\turl = y\n"
    );

    // ---
    // `git config --get-regexp`'s default form separates key from value with a
    // space, and BOTH halves may contain one — `submodule.odd name.path public/with
    // space`. Splitting on the first space yields the path `name.path public/with
    // space`, so the guard would watch a directory that does not exist and quietly
    // stop watching the one that does. `--null` is what makes this unambiguous.
    // ---
    expect(ContentSubmoduleState::declaredPaths($spaced))->toBe([
        $spaced.'/content',
        $spaced.'/public/with space',
    ]);
});

it('collapses two submodules declaring the same path', function (): void {
    $duplicated = $this->guardRoot.'/duplicated';
    \mkdir($duplicated, 0777, true);
    submoduleGit($duplicated, ['-c', 'init.defaultBranch=main', 'init', '--quiet']);

    // ---
    // git does NOT reject this — verified on git 2.39.5 (macOS): `--get-regexp`
    // happily returns both. Left undeduplicated the captured map, which is keyed by
    // path, would collapse to one entry while the declared count stayed at two, and
    // the partial-capture check would then fail claiming the guard captured 1 of 2
    // — a real failure with an entirely wrong cause attached.
    // ---
    \file_put_contents(
        $duplicated.'/.gitmodules',
        "[submodule \"a\"]\n\tpath = shared\n\turl = x\n"
        ."[submodule \"b\"]\n\tpath = shared\n\turl = y\n"
    );

    expect(ContentSubmoduleState::declaredPaths($duplicated))->toBe([$duplicated.'/shared']);
});

it('throws when .gitmodules exists but cannot be read', function (): void {
    $unreadable = $this->guardRoot.'/unreadable';
    \mkdir($unreadable, 0777, true);
    submoduleGit($unreadable, ['-c', 'init.defaultBranch=main', 'init', '--quiet']);
    \file_put_contents($unreadable.'/.gitmodules', "[submodule \"content\"]\n\tpath = content\n\turl = x\n");

    \chmod($unreadable.'/.gitmodules', 0000);

    // Running as root, or on a filesystem ignoring the mode, leaves it readable —
    // in which case there is nothing to assert rather than something that passed
    if (\is_readable($unreadable.'/.gitmodules')) {
        \chmod($unreadable.'/.gitmodules', 0644);
        Assert::markTestSkipped('the mode did not take effect, so the unreadable case cannot be built here.');
    }

    // ---
    // git reports this as exit **1** — the same status it uses for "no matching
    // keys" — explaining itself only with `warning: unable to access` on stderr,
    // which the helper discards. So the status check cannot tell the two apart and
    // would answer "declares no submodules" for a file it simply could not open.
    // ---
    try {
        expect(fn (): array => ContentSubmoduleState::declaredPaths($unreadable))
            ->toThrow(RuntimeException::class);
    } finally {
        \chmod($unreadable.'/.gitmodules', 0644);
    }
});

it('throws when .gitmodules is a directory rather than a file', function (): void {
    $directory = $this->guardRoot.'/gitmodules-is-a-dir';
    \mkdir($directory, 0777, true);
    submoduleGit($directory, ['-c', 'init.defaultBranch=main', 'init', '--quiet']);

    // The other shape git answers with exit 1 and a bare `warning:` — indistinguishable
    // from an empty declaration by status alone
    \mkdir($directory.'/.gitmodules', 0777, true);

    expect(fn (): array => ContentSubmoduleState::declaredPaths($directory))
        ->toThrow(RuntimeException::class);
});
