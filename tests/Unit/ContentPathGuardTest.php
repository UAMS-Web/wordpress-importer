<?php

declare(strict_types=1);

/**
 * Guards the whole class of defect wordpress-importer-archive#622 and wordpress-importer-archive#724 fixed, not just their sites.
 *
 * Content under `content/` moves under `APP_ENV=testing`: the host re-roots
 * every content-backed Stache store at a disposable mirror
 * (`UAMS-Web/uams-statamic-archive#1995`), so a path this package builds itself has to
 * follow the store or it reads — and deletes — the wrong tree.
 *
 * **Two mechanisms reach the wrong tree, and wordpress-importer-archive#622's acceptance criterion only
 * searched for one.** `base_path('content/…')` is the obvious form. The other
 * is a `content/…`-*relative* string handed to Statamic's default disk, which
 * is rooted at the app root and so resolves identically — verified under
 * `APP_ENV=testing`, where `File::disk()->exists('content/collections/pages/default/about-uams.md')`
 * returned true against the live submodule. That form contains no `base_path(`
 * at all, which is why four sites survived wordpress-importer-archive#622 and needed wordpress-importer-archive#724.
 *
 * A grep in a checklist is only true on the day someone runs it. This asserts
 * both forms are absent on every run of the standalone gate, which is the tier
 * that can see source without booting anything.
 */
$sourceFiles = static function (): array {
    $root = \dirname(__DIR__, 2).'/src';
    $found = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $found[] = $file->getPathname();
    }

    \sort($found);

    return $found;
};

/**
 * A file's PHP source with every comment removed.
 *
 * This repo documents the anti-patterns it forbids — `TreeBuilder`'s docblock
 * quotes `base_path('content/…')` while explaining why the code no longer does
 * it — so a guard that matched raw text would fire on its own documentation and
 * teach the next reader to delete the explanation. Tokenizing asserts about
 * code and leaves prose alone.
 */
$codeOnly = static function (string $path): string {
    $code = '';

    foreach (\token_get_all((string) \file_get_contents($path)) as $token) {
        if (\is_array($token)) {
            if ($token[0] === \T_COMMENT || $token[0] === \T_DOC_COMMENT) {
                continue;
            }

            $code .= $token[1];

            continue;
        }

        $code .= $token;
    }

    return $code;
};

it('finds the source tree it is supposed to be scanning', function () use ($sourceFiles): void {
    // A guard that silently scans nothing passes forever; prove it sees files.
    expect($sourceFiles())->not->toBeEmpty()
        ->and(\count($sourceFiles()))->toBeGreaterThan(100);
});

it('builds no content path with base_path()', function () use ($sourceFiles, $codeOnly): void {
    $offenders = [];

    foreach ($sourceFiles() as $path) {
        // ContentRoot is the one place allowed to name the app root: its
        // resolve() falls back to base_path() for paths outside content/.
        if (\str_ends_with($path, 'Utilities/ContentRoot.php')) {
            continue;
        }

        if (\preg_match('/base_path\(\s*[\'"]content/', $codeOnly($path)) === 1) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([], 'Resolve these through ContentRoot instead: '.\implode(', ', $offenders));
});

it('hands no content-relative path to a Statamic disk', function () use ($sourceFiles, $codeOnly): void {
    $offenders = [];

    foreach ($sourceFiles() as $path) {
        // The default disk is rooted at the app root, so a `content/…`-relative
        // path against it reaches the live submodule exactly as base_path would.
        if (\preg_match('/disk\(\)\s*->/', $codeOnly($path)) === 1) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([], 'These reach the app root rather than the run\'s content root: '.\implode(', ', $offenders));
});
