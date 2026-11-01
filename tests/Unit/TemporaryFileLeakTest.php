<?php

declare(strict_types=1);

use UAMSWeb\Aether\WordPressImporter\Tests\Support\TemporaryFileRegistry;

/**
 * Pins that a temporary file created for a test leaves nothing behind (wordpress-importer-archive#783).
 *
 * `tempnam()` CREATES the file it names, and the old helper returned
 * `<that path><extension>` — a different path — so every call stranded the stub.
 * Measured on macOS, 2026-08-27: 628 stubs in `sys_get_temp_dir()` against 18
 * orphaned exports, and the 628 came from the two call sites that DO unlink
 * their export. The leak was in the helper, so a caller cleaning up correctly
 * could not prevent it.
 *
 * **Every assertion here is about a path this test was handed.** An earlier
 * draft globbed a fixed prefix in `sys_get_temp_dir()` and asserted on the
 * count, which broke three ways at once: a second concurrent Pest run — the
 * repo's own local-CI slot alongside an author's `composer test` — failed it,
 * the cleanup then deleted that run's in-flight file, and on Windows `tempnam()`
 * keeps only the first three characters of a prefix, so the glob could not match
 * at all. Reading back what `create()` returned has none of those properties.
 *
 * @command  php vendor/bin/pest tests/Unit/TemporaryFileLeakTest.php
 */
it('leaves the export and not the stub tempnam created', function (): void {
    $path = TemporaryFileRegistry::create('wpimport_783_probe_');
    \file_put_contents($path, "export\n");

    // ---
    // The stub is the extensionless path `tempnam()` created and nothing writes
    // to. Deriving it from the returned path, rather than globbing, is what keeps
    // this assertion about this call.
    // ---
    $stub = \substr($path, 0, -\strlen('.xml'));

    expect(\is_file($path))->toBeTrue()
        ->and(\is_file($stub))->toBeFalse();

    @\unlink($path);
});

it('records the path so the exit sweep can reach it', function (): void {
    $path = TemporaryFileRegistry::create('wpimport_783_probe_');

    // The registry is what makes cleanup survive a test that dies before its own
    // afterEach; without this the file is nobody's
    expect(TemporaryFileRegistry::outstanding())->toContain($path);

    @\unlink($path);
});

it('sweeps a file the test never removed', function (): void {
    $path = TemporaryFileRegistry::create('wpimport_783_probe_');
    \file_put_contents($path, "abandoned\n");

    // ---
    // `sweep()` is process-wide, so it may also clear paths other tests in this
    // run registered — which is exactly its job. The assertion is on THIS file's
    // fate, not on the return count, so it cannot be perturbed by what else the
    // run happened to have outstanding.
    // ---
    TemporaryFileRegistry::sweep();

    expect(\is_file($path))->toBeFalse();
});

it('leaves nothing to sweep when the test cleaned up after itself', function (): void {
    // Drain what earlier tests in this process left outstanding, so the count below
    // is about this file alone
    TemporaryFileRegistry::sweep();

    $path = TemporaryFileRegistry::create('wpimport_783_probe_');
    \file_put_contents($path, "tidy\n");

    // The common case: a caller that removes its own export. The backstop must
    // tolerate it rather than counting or erroring on the missing file.
    \unlink($path);

    // ---
    // The count is the assertion, and it needs the registry drained first: sweep()
    // is process-wide, so an earlier test's outstanding path would otherwise be
    // counted here and this would pass for the wrong reason.
    //
    // Unlike the three cases above, no SINGLE mutation kills this one, and that
    // is a property of the code rather than a weakness here: the `is_file()`
    // guard and the `if (@unlink(...))` count independently produce 0 for a file
    // that is already gone, so removing either alone changes nothing. Verified on
    // macOS, 2026-08-27 — only dropping both together makes `sweep()` return 1
    // and this case go red.
    // ---
    expect(TemporaryFileRegistry::sweep())->toBe(0);
});
