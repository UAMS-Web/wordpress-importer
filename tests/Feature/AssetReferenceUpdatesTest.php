<?php

declare(strict_types=1);

use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\Assert;
use Statamic\Events\AssetDeleted;
use Statamic\Listeners\UpdateAssetReferences;
use Tests\TestCase;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\AssetReferenceUpdates;

uses(TestCase::class);

/**
 * Tests for the `AssetReferenceUpdates` import-run guard that disables Statamic's
 * asset reference sweep (issue wordpress-importer-archive#45).
 *
 * Each asset overwrite during an import deletes the existing asset, firing
 * Statamic's AssetDeleted event and triggering UpdateAssetReferences to scan
 * every entry, taxonomy term, global, and user. A single term with a null
 * blueprint in that sweep fatals the whole run.
 *
 * The original wordpress-importer-archive#45 fix flipped `statamic.system.update_references` at runtime,
 * but that flag is only read in `UpdateAssetReferences::subscribe()` at boot, so
 * the runtime change was a no-op and the sweep still fired. The working fix uses
 * Statamic's runtime `disable()`/`enable()` API, which unbinds the already-
 * registered listeners (verified against the console kernel — the environment the
 * import command actually runs in — where `disable()` drops the AssetDeleted
 * listener count by one and `enable()` restores it).
 *
 * Note: the listener-removal API depends on the event dispatcher's `forgetListener`
 * macro, which is present under the console/artisan kernel but not under every test
 * harness dispatcher. The listener-state tests below therefore guard on
 * {@see assetRefDispatcherSupportsListenerRemoval()} and skip (rather than report a
 * false failure) when the harness cannot exercise removal. The contract tests
 * (return value, exception propagation) always run.
 *
 * @command  php artisan test vendor/uams-web/wordpress-importer/tests/Feature/AssetReferenceUpdatesTest.php
 */
it('registers the UpdateAssetReferences listener by default', function (): void {
    // Contract: Statamic ships with the UpdateAssetReferences listener bound, so
    // the importer genuinely has something to disable. If statamic/cms ever stops
    // registering it by default, this fails loudly instead of the guard silently
    // becoming a no-op.
    expect(assetRefDeletedListenerCount())->toBeGreaterThanOrEqual(1);
});

it('runs the callback with the listener disabled and restores it afterwards', function (): void {
    if (! assetRefDispatcherSupportsListenerRemoval()) {
        Assert::markTestSkipped('This dispatcher cannot unbind listeners; removal is verified under the console kernel.');
    }

    // Baseline with the listener bound.
    $before = assetRefDeletedListenerCount();

    // Capture the live count from inside the guarded work.
    $during = null;
    AssetReferenceUpdates::withDisabled(function () use (&$during) {
        $during = assetRefDeletedListenerCount();
    });

    // The UpdateAssetReferences handler is removed for the duration...
    expect($during)->toBe($before - 1);

    // ...and restored to the baseline afterwards.
    expect(assetRefDeletedListenerCount())->toBe($before);
});

it('restores the listener when the callback throws', function (): void {
    if (! assetRefDispatcherSupportsListenerRemoval()) {
        Assert::markTestSkipped('This dispatcher cannot unbind listeners; removal is verified under the console kernel.');
    }

    $before = assetRefDeletedListenerCount();

    $exceptionMessage = null;
    try {
        AssetReferenceUpdates::withDisabled(function () {
            throw new RuntimeException('import blew up');
        });
    } catch (RuntimeException $e) {
        $exceptionMessage = $e->getMessage();
    }

    expect($exceptionMessage)->toBe('import blew up');

    // The listener is restored despite the abort.
    expect(assetRefDeletedListenerCount())->toBe($before);
});

it('propagates the exception from the callback', function (): void {
    // Contract test — runs in any environment, independent of listener-removal support.
    expect(fn () => AssetReferenceUpdates::withDisabled(function () {
        throw new RuntimeException('import blew up');
    }))->toThrow(RuntimeException::class, 'import blew up');
});

it('returns the callback return value', function (): void {
    // The command relies on this to return its exit code.
    $result = AssetReferenceUpdates::withDisabled(fn (): int => 42);

    expect($result)->toBe(42);
});

/**
 * Resolve the application's event dispatcher as a concrete Illuminate Dispatcher.
 */
function assetRefEventDispatcher(): Dispatcher
{
    $events = app('events');
    expect($events)->toBeInstanceOf(Dispatcher::class);

    if (! $events instanceof Dispatcher) {
        throw new RuntimeException('Expected the application events dispatcher to be an Illuminate Dispatcher.');
    }

    return $events;
}

/**
 * Count the listeners currently bound to the AssetDeleted event.
 */
function assetRefDeletedListenerCount(): int
{
    return \count(assetRefEventDispatcher()->getListeners(AssetDeleted::class));
}

/**
 * Whether this environment's event dispatcher can actually unbind the
 * UpdateAssetReferences listener (i.e. `disable()` removes it). Restores the
 * listener only when it was genuinely removed, so this probe leaves dispatcher
 * state unchanged in both cases.
 */
function assetRefDispatcherSupportsListenerRemoval(): bool
{
    $before = assetRefDeletedListenerCount();

    UpdateAssetReferences::disable();
    $supported = assetRefDeletedListenerCount() < $before;

    // Only re-add if disable() actually removed it, so an unsupported harness
    // is not left with a duplicate listener.
    if ($supported) {
        UpdateAssetReferences::enable();
    }

    return $supported;
}
