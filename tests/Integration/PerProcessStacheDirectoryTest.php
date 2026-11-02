<?php

declare(strict_types=1);

use Statamic\Facades\Stache;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

/**
 * Pins the per-process fake Stache directory (issue wordpress-importer-archive#824).
 *
 * `AddonTestCase::setUp()` derives one fixed `tests/__fixtures__/dev-null` from the
 * addon service provider's path, so every process running the suite in one checkout
 * writes there. Two suites in one tree then corrupt each other — measured at 0 clean
 * runs out of 12 concurrent, against 12 of 12 sequential. `StatamicTestCase` moves
 * each process to its own `dev-null-<pid>`.
 *
 * **Without this file that change is invisible to the gate.** The standalone suite
 * returns byte-identical verdicts with the override present and absent — 2125 passed
 * either way — because nothing else asserts where a store writes. The defect it
 * prevents only appears when two processes race, which no suite run reproduces.
 *
 * The three assertions are one each for the ways the override can silently stop
 * working: the rename not happening, the directory not existing when a test writes to
 * it, and a store the re-point missed still pointing at the shared path.
 *
 * @command  php vendor/bin/pest tests/Integration/PerProcessStacheDirectoryTest.php
 */
it('gives this process its own fake Stache directory', function (): void {
    $directory = $this->fakeStacheDirectory;

    expect($directory)->toBeString();

    // ---
    // The suffix is the whole mechanism: two processes in one checkout derive the
    // same shared path from the service provider and are separated only by this.
    // ---
    expect($directory)->toEndWith('/dev-null-'.\getmypid());
});

it('creates that directory before a test writes to it', function (): void {
    $directory = $this->fakeStacheDirectory;

    if (! \is_string($directory) || $directory === '') {
        throw new RuntimeException('The fake Stache directory was not set.');
    }

    // ---
    // The parent only ever creates the fake directory in its teardown, so the first
    // test of a process would otherwise write into a path that does not exist. That
    // ordering is invisible in a suite where an earlier test already made it.
    // ---
    expect(\is_dir($directory))->toBeTrue();
});

it('points every Stache store inside it', function (): void {
    $directory = $this->fakeStacheDirectory;

    if (! \is_string($directory) || $directory === '') {
        throw new RuntimeException('The fake Stache directory was not set.');
    }

    $stores = [];

    Stache::stores()->each(function ($store) use (&$stores): void {
        $stores[$store->key()] = $store->directory();
    });

    // A store left behind writes to the shared path and reintroduces the collision
    expect($stores)->not->toBeEmpty();

    foreach ($stores as $key => $storeDirectory) {
        expect($storeDirectory)
            ->toStartWith($directory, "Store '$key' is not inside the per-process directory.");
    }
});
