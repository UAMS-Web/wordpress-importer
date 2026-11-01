<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use UAMSWeb\Aether\WordPressImporter\Tests\Support\AdoptedStacheDirectoryCase;

uses(AdoptedStacheDirectoryCase::class, PreventsSavingStacheItemsToDisk::class);

/**
 * Pins that an adopted per-process Stache directory is emptied (issue wordpress-importer-archive#832).
 *
 * wordpress-importer-archive#828 gives each process its own `dev-null-<pid>` and creates it only when absent,
 * so an existing one was adopted with its contents intact. Teardown removes it on a
 * normal run, which leaves exactly one way to reach that branch: a run killed
 * mid-test, at a pid the operating system later reissues to another test process in
 * the same checkout. The first test of that process would then read what the killed
 * run left.
 *
 * **Seeding that state requires planting before the whole `setUp()` chain**, which is
 * why this file uses its own case class rather than a `beforeEach` — Pest's runs
 * after `StatamicTestCase::setUp()` has already prepared the directory, far too late
 * to observe what it did. The case class lives in `Support/` because it extends
 * `StatamicTestCase` and is therefore late-bound; see its own docblock.
 *
 * @command  php vendor/bin/pest tests/Integration/AdoptedStacheDirectoryTest.php
 */
it('empties a per-process directory left behind by a killed run', function (): void {
    // ---
    // The planted file stands for whatever a killed run left — a Stache entry, a
    // half-written index. Its survival is the defect: the first test of this process
    // would resolve content that belongs to a run nobody can account for.
    // ---
    expect(AdoptedStacheDirectoryCase::$plantsLanded)->toBeTrue();
    expect(\file_exists(AdoptedStacheDirectoryCase::$plantedFile))->toBeFalse();
});

it('empties nested content too, not just the top level', function (): void {
    expect(AdoptedStacheDirectoryCase::$plantsLanded)->toBeTrue();
    expect(\file_exists(AdoptedStacheDirectoryCase::$plantedNested))->toBeFalse();

    // The directory itself must survive — the run about to happen writes into it
    $directory = $this->fakeStacheDirectory;

    if (! \is_string($directory) || $directory === '') {
        throw new RuntimeException('The fake Stache directory was not set.');
    }

    expect(\is_dir($directory))->toBeTrue();
});

it('removes a symlink without following it out of the directory', function (): void {
    // ---
    // The emptying is a recursive delete, so this is the case that matters most:
    // `is_dir()` is true for a link to a directory, and recursing on that test would
    // delete the contents of whatever it points at. Measured — without the
    // `is_link()` branch, the file below is destroyed.
    //
    // Stands down where the fixture could not be built. Creating a symlink on Windows
    // needs elevation or Developer Mode; without either, `symlink()` returns false with
    // `Permission denied`. **Asserting anyway would be worse than skipping**: with no
    // link planted, both assertions below are vacuously true — nothing was ever
    // pointed at the outside file, and `is_link()` is false because there is no link —
    // so the test would report PASS while pinning nothing at all. That is the exact
    // shape `$plantsLanded` exists to prevent, one level up.
    // ---
    if (! AdoptedStacheDirectoryCase::$linkLanded) {
        Assert::markTestSkipped(
            'symlink() was denied: planting the link needs elevation or Developer Mode on Windows.'
        );
    }

    expect(AdoptedStacheDirectoryCase::$plantsLanded)->toBeTrue();
    expect(\file_exists(AdoptedStacheDirectoryCase::$plantedOutside))->toBeTrue();

    // The link itself is removed, so nothing stale is left pointing anywhere
    expect(\is_link(AdoptedStacheDirectoryCase::$plantedLink))->toBeFalse();
});

it('leaves the shared directory and its tracked .gitkeep alone', function (): void {
    // ---
    // The emptying must never reach the SHARED path. Its `.gitkeep` is tracked, so a
    // regression here shows up as a spurious deletion in somebody's next commit.
    // ---
    $shared = \dirname(__DIR__).'/__fixtures__/dev-null';

    expect(\is_dir($shared))->toBeTrue();
    expect(\file_exists($shared.'/.gitkeep'))->toBeTrue();
});
