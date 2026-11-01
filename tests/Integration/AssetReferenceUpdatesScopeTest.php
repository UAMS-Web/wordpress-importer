<?php

declare(strict_types=1);

/**
 * Tests that `AssetReferenceUpdates::withDisabled()` takes Statamic's asset reference
 * sweep off for the work and puts it back afterwards, through a dispatcher that records
 * what Statamic's subscriber asks of it.
 *
 * `tests/Feature/AssetReferenceUpdatesTest.php` counts the live listeners under the host
 * app's console kernel, and skips where a dispatcher cannot unbind them, so none of it
 * runs in the package gate. This file runs in the gate: the recording dispatcher defines
 * `forgetListener()` itself, so what is asserted is that the guard calls `disable()`
 * before the work and `enable()` after it, not that a given dispatcher can unbind.
 *
 * The dispatcher starts with no listeners, so the sweep's events having listeners after
 * the work is what shows `enable()` re-subscribed them.
 *
 * @command  php vendor/bin/pest tests/Integration/AssetReferenceUpdatesScopeTest.php
 */

use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Statamic\Events\AssetDeleted;
use Statamic\Events\AssetReplaced;
use Statamic\Events\AssetSaved;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\AssetReferenceUpdates;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * A dispatcher that records the events whose listeners the subscriber forgets.
 */
class AssetReferenceRecordingDispatcher extends Dispatcher
{
    /**
     * The events forgotten, in order.
     *
     * @var list<string>
     */
    public array $forgotten = [];

    /**
     * Record a listener being forgotten.
     */
    public function forgetListener(string $event, mixed $listener): void
    {
        $this->forgotten[] = $event;
    }
}

/**
 * Bind a fresh recording dispatcher as the application's event dispatcher.
 */
function assetReferenceRecorder(): AssetReferenceRecordingDispatcher
{
    $recorder = new AssetReferenceRecordingDispatcher(app());

    app()->instance('events', $recorder);
    Event::clearResolvedInstance('events');

    return $recorder;
}

/**
 * The three asset events the sweep listens on.
 *
 * @return list<string>
 */
function assetReferenceEvents(): array
{
    return [AssetSaved::class, AssetReplaced::class, AssetDeleted::class];
}

/**
 * Whether each of the sweep's events has a listener on the dispatcher.
 *
 * @return list<bool>
 */
function assetReferenceSubscribed(Dispatcher $dispatcher): array
{
    return \array_map(
        static fn (string $event): bool => $dispatcher->hasListeners($event),
        assetReferenceEvents()
    );
}

it('forgets the sweep before the work and re-subscribes it after', function (): void {
    $recorder = assetReferenceRecorder();
    $forgottenDuring = null;
    $subscribedDuring = null;

    $result = AssetReferenceUpdates::withDisabled(function () use ($recorder, &$forgottenDuring, &$subscribedDuring): string {
        $forgottenDuring = $recorder->forgotten;
        $subscribedDuring = assetReferenceSubscribed($recorder);

        return 'done';
    });

    expect($result)->toBe('done')
        ->and($forgottenDuring)->toBe(assetReferenceEvents())
        ->and($subscribedDuring)->toBe([false, false, false])
        ->and(assetReferenceSubscribed($recorder))->toBe([true, true, true]);
});

it('re-subscribes the sweep when the work throws', function (): void {
    $recorder = assetReferenceRecorder();

    expect(fn (): mixed => AssetReferenceUpdates::withDisabled(
        static fn (): never => throw new RuntimeException('import blew up')
    ))->toThrow(RuntimeException::class, 'import blew up');

    expect($recorder->forgotten)->toBe(assetReferenceEvents())
        ->and(assetReferenceSubscribed($recorder))->toBe([true, true, true]);
});
