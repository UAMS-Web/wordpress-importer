<?php

declare(strict_types=1);

/**
 * An import must leave behind no entry carrying no data beyond `blueprint` (wordpress-importer-archive#1160).
 *
 * A run into one site materialized 37 `knowledge_bases` entries into a *different* site —
 * one it was not asked to touch, with no content on disk — inside 70 milliseconds. Each held
 * an `id` and a `blueprint` and nothing else, the ids were freshly minted rather than cached,
 * and the host app rejects every one of them.
 *
 * The trigger reproduced **once in three attempts** and is still unidentified, so these tests
 * are deliberately independent of it: they exercise the judgement and the sweep directly.
 *
 * @command  php vendor/bin/pest tests/Unit/DatalessEntrySweeperTest.php
 */

use PHPUnit\Framework\Assert;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\DatalessEntrySweeper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningDisplayService;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;

it('calls an entry dataless only when nothing but inert keys remain', function (array $frontMatter, bool $expected): void {
    expect(DatalessEntrySweeper::isDataless($frontMatter))->toBe($expected);
})->with([
    // The observed shape, verbatim from the reported files.
    'id and blueprint only' => [['id' => '3961f51c', 'blueprint' => 'article'], true],
    'blueprint alone' => [['blueprint' => 'article'], true],
    'id alone' => [['id' => '3961f51c'], true],
    'empty' => [[], true],

    // Controls: anything else is data and must survive.
    'carries a title' => [['id' => 'x', 'blueprint' => 'article', 'title' => 'A page'], false],
    'carries a falsy value' => [['id' => 'x', 'blueprint' => 'article', 'published' => false], false],
    'carries an empty string' => [['id' => 'x', 'blueprint' => 'article', 'title' => ''], false],
    'carries an empty array' => [['id' => 'x', 'blueprint' => 'article', 'topics' => []], false],
]);

it('refuses to judge a file whose front matter it cannot read', function (): void {
    // A file that is not front-matter-shaped is not an empty entry, and the difference
    // decides whether it is deleted.
    expect(DatalessEntrySweeper::frontMatter('just some text'))->toBeNull();
    expect(DatalessEntrySweeper::frontMatter(''))->toBeNull();
    expect(DatalessEntrySweeper::frontMatter("---\n  bad:\n - : :\n---\n"))->toBeNull();

    // Control: a well-formed one parses.
    expect(DatalessEntrySweeper::frontMatter("---\nid: abc\nblueprint: article\n---\n"))
        ->toBe(['id' => 'abc', 'blueprint' => 'article']);
});

it('removes dataless files this run wrote and leaves everything else alone', function (): void {
    $root = \sys_get_temp_dir().'/wt-dataless-'.\bin2hex(\random_bytes(4));
    $dir = $root.'/knowledge_bases/health_employee_assistance_program';
    \mkdir($dir, 0o777, true);

    $write = function (string $name, string $body) use ($dir): string {
        $path = $dir.'/'.$name;
        \file_put_contents($path, $body);

        return $path;
    };

    $stub = $write('alcoholism.md', "---\nid: 3961f51c\nblueprint: article\n---\n");
    $real = $write('real-article.md', "---\nid: aaa\nblueprint: article\ntitle: A real one\n---\n");
    $older = $write('older-stub.md', "---\nid: bbb\nblueprint: article\n---\n");

    // The bound is what stops this deleting content the run did not produce: the older stub
    // is dataless *and* predates the run, so it is not this run's to remove.
    \touch($older, \time() - 3600);
    $since = \time() - 60;

    try {
        $removed = DatalessEntrySweeper::sweep($root, (float) $since);

        expect($removed)->toHaveCount(1);
        expect($removed[0]['collection'])->toBe('knowledge_bases');
        expect($removed[0]['site'])->toBe('health_employee_assistance_program');
        expect($removed[0]['slug'])->toBe('alcoholism');
        expect($removed[0]['id'])->toBe('3961f51c');

        expect(\file_exists($stub))->toBeFalse();
        // Controls, both of which would be data loss if removed.
        expect(\file_exists($real))->toBeTrue();
        expect(\file_exists($older))->toBeTrue();
    } finally {
        $leftovers = \glob($dir.'/*.md');

        foreach (\is_array($leftovers) ? $leftovers : [] as $leftover) {
            \unlink($leftover);
        }
        @\rmdir($dir);
        @\rmdir($root.'/knowledge_bases');
        @\rmdir($root);
    }
});

it('reports nothing and removes nothing on a clean run', function (): void {
    $root = \sys_get_temp_dir().'/wt-dataless-clean-'.\bin2hex(\random_bytes(4));
    $dir = $root.'/posts/default';
    \mkdir($dir, 0o777, true);
    $real = $dir.'/a-post.md';
    \file_put_contents($real, "---\nid: ccc\nblueprint: post\ntitle: Fine\n---\n");

    try {
        expect(DatalessEntrySweeper::sweep($root, (float) (\time() - 60)))->toBe([]);
        expect(\file_exists($real))->toBeTrue();
    } finally {
        @\unlink($real);
        @\rmdir($dir);
        @\rmdir($root.'/posts');
        @\rmdir($root);
    }
});

it('returns an empty list when the collections root does not exist', function (): void {
    expect(DatalessEntrySweeper::sweep('/no/such/path/at/all', 0.0))->toBe([]);
});

it('names removed dataless entries in the end-of-run warning summary', function (): void {
    ImportWarningManager::clearWarnings();

    try {
        ImportWarningManager::addWarning('content', 'dataless_entries_removed', [
            'path' => 'knowledge_bases/health_employee_assistance_program/alcoholism.md',
            'collection' => 'knowledge_bases',
            'site' => 'health_employee_assistance_program',
            'slug' => 'alcoholism',
            'id' => '3961f51c-3005-40b1-81c9-b57fd0971568',
        ]);

        $output = new BufferedOutput;
        ImportWarningDisplayService::displayAllWarnings($output);
        $rendered = $output->fetch();

        // Its own section, beside the existing ones, rather than a line lost mid-run.
        expect($rendered)->toContain('dataless_entries_removed');
        // The fields an operator needs to tell an invented entry from an expected one.
        expect($rendered)->toContain('knowledge_bases');
        expect($rendered)->toContain('health_employee_assistance_program');
        expect($rendered)->toContain('3961f51c');
    } finally {
        ImportWarningManager::clearWarnings();
    }
});

it('omits the section entirely when no dataless entry was removed', function (): void {
    ImportWarningManager::clearWarnings();

    try {
        // Control: an unrelated section on the same run still renders, so the absence below
        // is a real omission rather than an empty summary.
        ImportWarningManager::addWarning('content', 'unrenderable_gallery_blocks', [
            'entry' => 'https://example.com/a-page/',
        ]);

        $output = new BufferedOutput;
        ImportWarningDisplayService::displayAllWarnings($output);
        $rendered = $output->fetch();

        expect($rendered)->not->toContain('dataless_entries_removed');
        expect($rendered)->toContain('unrenderable_gallery_blocks');
    } finally {
        ImportWarningManager::clearWarnings();
    }
});

it('leaves a file it cannot parse alone rather than deleting it', function (): void {
    // The most dangerous branch in the sweeper: a file whose front matter cannot be read is
    // not an empty entry, and treating it as one deletes content nobody judged. Asserted at
    // the sweep rather than only at `frontMatter()`, because a mutation that inverts the
    // null check leaves the parser's own tests green.
    $root = \sys_get_temp_dir().'/wt-dataless-unreadable-'.\bin2hex(\random_bytes(4));
    $dir = $root.'/posts/default';
    \mkdir($dir, 0o777, true);

    $unparseable = $dir.'/broken.md';
    \file_put_contents($unparseable, "---\n  bad:\n - : :\n---\n");

    $notFrontMatter = $dir.'/plain.md';
    \file_put_contents($notFrontMatter, "just some text, no front matter\n");

    // Control: a genuine stub in the same directory and the same window still goes, so the
    // survivals above are the null guard and not a sweep that did nothing.
    $stub = $dir.'/stub.md';
    \file_put_contents($stub, "---\nid: ddd\nblueprint: post\n---\n");

    try {
        $removed = DatalessEntrySweeper::sweep($root, (float) (\time() - 60));

        expect($removed)->toHaveCount(1);
        expect($removed[0]['slug'])->toBe('stub');

        expect(\file_exists($unparseable))->toBeTrue();
        expect(\file_exists($notFrontMatter))->toBeTrue();
        expect(\file_exists($stub))->toBeFalse();
    } finally {
        foreach ([$unparseable, $notFrontMatter, $stub] as $leftover) {
            if (\file_exists($leftover)) {
                \unlink($leftover);
            }
        }
        @\rmdir($dir);
        @\rmdir($root.'/posts');
        @\rmdir($root);
    }
});

it('keeps a file whose front matter is inert but which carries a body', function (): void {
    // The class targets files with "no title, no body, no data of any kind", and for a while
    // only the first two were checked: the front-matter split discarded the body entirely, so
    // `id` + `blueprint` + real prose was eligible for deletion while the docblock said it was
    // not. No fixture carried a body, so neither the tests nor any mutation could see it.
    $root = \sys_get_temp_dir().'/wt-dataless-body-'.\bin2hex(\random_bytes(4));
    $dir = $root.'/posts/default';
    \mkdir($dir, 0o777, true);

    $withBody = $dir.'/has-body.md';
    \file_put_contents($withBody, "---\nid: eee\nblueprint: post\n---\n## A heading\n\nAnd a paragraph.\n");

    // Control: a true stub in the same directory and window still goes, so the survival above
    // is the body guard rather than a sweep that did nothing.
    $stub = $dir.'/stub.md';
    \file_put_contents($stub, "---\nid: fff\nblueprint: post\n---\n");

    // Control: whitespace after the separator is not a body.
    $blankBody = $dir.'/blank-body.md';
    \file_put_contents($blankBody, "---\nid: ggg\nblueprint: post\n---\n\n   \n");

    try {
        $removed = DatalessEntrySweeper::sweep($root, (float) (\time() - 60));
        $slugs = \array_column($removed, 'slug');

        \sort($slugs);
        expect($slugs)->toBe(['blank-body', 'stub']);

        expect(\file_exists($withBody))->toBeTrue();
        expect(\file_exists($stub))->toBeFalse();
        expect(\file_exists($blankBody))->toBeFalse();
    } finally {
        foreach ([$withBody, $stub, $blankBody] as $leftover) {
            if (\file_exists($leftover)) {
                \unlink($leftover);
            }
        }
        @\rmdir($dir);
        @\rmdir($root.'/posts');
        @\rmdir($root);
    }
});

it('reads the body only after the front matter', function (): void {
    expect(DatalessEntrySweeper::body("---\nid: a\n---\nsome prose\n"))->toBe('some prose');
    expect(DatalessEntrySweeper::body("---\nid: a\n---\n"))->toBe('');
    expect(DatalessEntrySweeper::body("---\nid: a\n---\n\n  \n"))->toBe('');
    // Not front-matter-shaped: no body to speak of, and `frontMatter()` refuses it first.
    expect(DatalessEntrySweeper::body('plain text'))->toBe('');
});

/**
 * Run a callback with warnings turned into exceptions, so a read of an absent key fails
 * rather than warns.
 *
 * @param  Closure(): mixed  $callback  The code to run
 * @return mixed What the callback returned
 */
function datalessSweeperStrictly(Closure $callback): mixed
{
    set_error_handler(static function (int $severity, string $message): never {
        throw new ErrorException($message, 0, $severity);
    });

    try {
        return $callback();
    } finally {
        restore_error_handler();
    }
}

/**
 * Make an empty collections root, and return its path.
 */
function datalessSweeperRoot(): string
{
    $root = \sys_get_temp_dir().'/wt-dataless-edge-'.\bin2hex(\random_bytes(4));
    \mkdir($root, 0o777, true);

    return $root;
}

/**
 * Remove a collections root and everything under it.
 */
function datalessSweeperRemove(string $root): void
{
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        if ($item instanceof SplFileInfo) {
            $item->isDir() ? @\rmdir($item->getPathname()) : @\unlink($item->getPathname());
        }
    }

    @\rmdir($root);
}

it('reads front matter that has no closing delimiter', function (): void {
    expect(datalessSweeperStrictly(fn (): ?array => DatalessEntrySweeper::frontMatter("---\nid: abc\n")))
        ->toBe(['id' => 'abc']);
});

it('reads only the first document of front matter, whatever the body holds', function (): void {
    expect(DatalessEntrySweeper::frontMatter("---\nid: abc\n---\nbody\n---\nmore\n"))->toBe(['id' => 'abc']);
});

it('refuses a file that starts with dashes but has no delimiter line', function (): void {
    expect(datalessSweeperStrictly(fn (): ?array => DatalessEntrySweeper::frontMatter('---x')))->toBeNull();
});

it('refuses a file whose delimiter comes after its first line', function (): void {
    expect(DatalessEntrySweeper::frontMatter("title: x\n---\nid: abc\n"))->toBeNull()
        ->and(DatalessEntrySweeper::body("title: x\n---\nid: abc\n---\nprose\n"))->toBe('');
});

it('reads the whole body, including a later delimiter line', function (): void {
    expect(DatalessEntrySweeper::body("---\nid: abc\n---\nbody\n---\nmore\n"))->toBe("body\n---\nmore");
});

it('reads no body when the front matter is never closed', function (): void {
    expect(datalessSweeperStrictly(fn (): string => DatalessEntrySweeper::body("---\nid: abc\n")))->toBe('');
});

it('sweeps only Markdown files', function (): void {
    $root = datalessSweeperRoot();
    \mkdir($root.'/posts/default', 0o777, true);
    $text = $root.'/posts/default/stub.txt';
    \file_put_contents($text, "---\nid: abc\nblueprint: post\n---\n");

    try {
        expect(DatalessEntrySweeper::sweep($root, 0.0))->toBe([])
            ->and(\file_exists($text))->toBeTrue();
    } finally {
        datalessSweeperRemove($root);
    }
});

it('sweeps a file written at the very second the run began', function (): void {
    $root = datalessSweeperRoot();
    \mkdir($root.'/posts/default', 0o777, true);
    $stub = $root.'/posts/default/stub.md';
    \file_put_contents($stub, "---\nid: abc\nblueprint: post\n---\n");
    $started = \time() - 30;
    \touch($stub, $started);
    \clearstatcache();

    try {
        expect(DatalessEntrySweeper::sweep($root, (float) $started))->toHaveCount(1)
            ->and(\file_exists($stub))->toBeFalse();
    } finally {
        datalessSweeperRemove($root);
    }
});

it('keeps sweeping past a file that predates the run', function (): void {
    $root = datalessSweeperRoot();
    \mkdir($root.'/posts/default', 0o777, true);
    $older = $root.'/posts/default/a-older.md';
    \file_put_contents($older, "---\nid: old\nblueprint: post\n---\n");
    \touch($older, \time() - 3600);

    // Several newer stubs, named to list after it. Where listing is alphabetical (NTFS) the
    // older one is never the last file the sweep reaches; elsewhere the filesystem decides,
    // and the test still holds, only less often catching a sweep that stops at it.
    $newer = [];

    foreach (['b', 'c', 'd', 'e', 'f'] as $letter) {
        $newer[] = $path = $root.'/posts/default/'.$letter.'-stub.md';
        \file_put_contents($path, "---\nid: {$letter}\nblueprint: post\n---\n");
    }

    try {
        expect(DatalessEntrySweeper::sweep($root, (float) (\time() - 60)))->toHaveCount(5)
            ->and(\file_exists($older))->toBeTrue()
            ->and(\array_filter($newer, 'file_exists'))->toBe([]);
    } finally {
        datalessSweeperRemove($root);
    }
});

it('describes a file that sits directly beneath its collection as belonging to no site', function (): void {
    $root = datalessSweeperRoot();
    \mkdir($root.'/pages', 0o777, true);
    \file_put_contents($root.'/pages/stub.md', "---\nblueprint: page\n---\n");

    try {
        expect(DatalessEntrySweeper::sweep($root, 0.0))->toBe([[
            'path' => 'pages/stub.md',
            'collection' => 'pages',
            'site' => '(none)',
            'slug' => 'stub',
            'id' => '(none)',
        ]]);
    } finally {
        datalessSweeperRemove($root);
    }
});

it('refuses a dash-led file without reading past the split it made', function (): void {
    $warnings = [];
    set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
        $warnings[] = $message;

        return true;
    });

    try {
        $frontMatter = DatalessEntrySweeper::frontMatter('---x');
    } finally {
        restore_error_handler();
    }

    expect($frontMatter)->toBeNull()
        ->and($warnings)->toBe([]);
});

it('keeps sweeping past a file that is not Markdown', function (): void {
    $root = datalessSweeperRoot();
    \mkdir($root.'/posts/default', 0o777, true);
    \file_put_contents($root.'/posts/default/a-notes.txt', "---\nid: a\n---\n");

    foreach (['b', 'c', 'd'] as $letter) {
        \file_put_contents($root.'/posts/default/'.$letter.'-stub.md', "---\nid: {$letter}\n---\n");
    }

    try {
        expect(DatalessEntrySweeper::sweep($root, 0.0))->toHaveCount(3)
            ->and(\file_exists($root.'/posts/default/a-notes.txt'))->toBeTrue();
    } finally {
        datalessSweeperRemove($root);
    }
});

/**
 * Deny this process read access to a file, and report whether the denial took.
 *
 * Windows ignores `chmod()` for reads, so there the denial is an ACL entry. A process that
 * can read the file anyway (root, say) gets `false`, and the caller skips.
 */
function datalessSweeperDenyRead(string $path): bool
{
    if (\PHP_OS_FAMILY === 'Windows') {
        \exec('icacls '.\escapeshellarg($path).' /deny '.\escapeshellarg((string) \getenv('USERNAME').':(R)').' 2>NUL', $output, $status);
    } else {
        \chmod($path, 0);
    }

    \clearstatcache();

    // The probe read warns when the denial took; that warning is the expected outcome.
    set_error_handler(static fn (): bool => true);

    try {
        return \file_get_contents($path) === false;
    } finally {
        restore_error_handler();
    }
}

/**
 * Give this process read access to a file again, so its root can be removed.
 */
function datalessSweeperAllowRead(string $path): void
{
    if (\PHP_OS_FAMILY === 'Windows') {
        \exec('icacls '.\escapeshellarg($path).' /remove:d '.\escapeshellarg((string) \getenv('USERNAME')).' 2>NUL');
    } else {
        \chmod($path, 0o644);
    }
}

it('leaves a file it cannot read alone, without failing the sweep', function (): void {
    $root = datalessSweeperRoot();
    \mkdir($root.'/posts/default', 0o777, true);
    $locked = $root.'/posts/default/locked.md';
    \file_put_contents($locked, "---\nid: abc\nblueprint: post\n---\n");

    try {
        if (! datalessSweeperDenyRead($locked)) {
            Assert::markTestSkipped('This process can read the file whatever its permissions say.');
        }

        // The failed read warns; swallow it so the run is about the sweep, not the warning.
        set_error_handler(static fn (): bool => true);

        try {
            $removed = DatalessEntrySweeper::sweep($root, 0.0);
        } finally {
            restore_error_handler();
        }

        datalessSweeperAllowRead($locked);

        expect($removed)->toBe([])
            ->and(\file_exists($locked))->toBeTrue();
    } finally {
        datalessSweeperAllowRead($locked);
        datalessSweeperRemove($root);
    }
});
