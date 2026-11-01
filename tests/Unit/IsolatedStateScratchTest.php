<?php

declare(strict_types=1);

use UAMSWeb\Aether\WordPressImporter\Tests\Support\IsolatedStateScratch;

/**
 * Pins which snapshot directories are safe to reclaim (wordpress-importer-archive#739).
 *
 * Sweeping is a delete, and the host band runs in two slots, so the cost of a
 * wrong answer is asymmetric: reclaiming a live run's snapshot destroys the
 * only copy of the content it is about to restore. Every case below is about
 * failing closed.
 *
 * @command  php vendor/bin/pest tests/Unit/IsolatedStateScratchTest.php
 */
$alwaysDead = static fn (int $pid): bool => false;
$alwaysAlive = static fn (int $pid): bool => true;

it('round-trips the process id through the directory name', function (): void {
    $name = IsolatedStateScratch::name(4242, 'abc123');

    expect($name)->toStartWith(IsolatedStateScratch::PREFIX)
        ->and(IsolatedStateScratch::pidOf($name))->toBe(4242);
});

it('reclaims a directory whose owner is gone', function () use ($alwaysDead): void {
    $swept = IsolatedStateScratch::abandoned(
        [IsolatedStateScratch::name(111, 'a'), IsolatedStateScratch::name(222, 'b')],
        999,
        $alwaysDead
    );

    expect($swept)->toBe(['wpimport-iso-111-a', 'wpimport-iso-222-b']);
});

it('never reclaims a directory a live process owns', function () use ($alwaysAlive): void {
    expect(IsolatedStateScratch::abandoned([IsolatedStateScratch::name(111, 'a')], 999, $alwaysAlive))
        ->toBe([]);
});

it('never reclaims its own directory, even if the probe says dead', function () use ($alwaysDead): void {
    // The current process is definitionally alive; a probe that says otherwise is
    // wrong, and acting on it would delete the snapshot this run restores from.
    expect(IsolatedStateScratch::abandoned([IsolatedStateScratch::name(999, 'mine')], 999, $alwaysDead))
        ->toBe([]);
});

it('leaves alone anything it did not name', function (string $basename) use ($alwaysDead): void {
    expect(IsolatedStateScratch::pidOf($basename))->toBeNull()
        ->and(IsolatedStateScratch::abandoned([$basename], 999, $alwaysDead))->toBe([]);
})->with([
    'an unrelated directory' => ['cache'],
    'a near-miss prefix' => ['wpimport-isolated-1-a'],
    'the prefix with no pid' => ['wpimport-iso-'],
    'a non-numeric pid' => ['wpimport-iso-abc-a'],
    'an empty pid segment' => ['wpimport-iso--a'],
    'a pid with no suffix' => ['wpimport-iso-123'],
    'laravel session storage' => ['sessions'],
]);

it('sweeps only the dead entries out of a mixed set', function (): void {
    $swept = IsolatedStateScratch::abandoned(
        [
            IsolatedStateScratch::name(111, 'dead'),
            IsolatedStateScratch::name(222, 'alive'),
            IsolatedStateScratch::name(999, 'mine'),
            'unrelated-dir',
        ],
        999,
        static fn (int $pid): bool => $pid === 222
    );

    expect($swept)->toBe(['wpimport-iso-111-dead']);
});
