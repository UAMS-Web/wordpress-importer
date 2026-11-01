<?php

declare(strict_types=1);

use Illuminate\Events\Dispatcher;
use Statamic\Events\TermSaved;
use Statamic\Search\UpdateItemIndexes;
use Tests\TestCase;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\SearchIndexUpdates;

uses(TestCase::class);

/**
 * Tests for the `SearchIndexUpdates` import-run guard that disables Statamic's
 * live search-index updates (issue wordpress-importer-archive#46).
 *
 * Every term/entry save fires Statamic's `UpdateItemIndexes` subscriber, which
 * calls `Search::updateWithinIndexes()`. For this site's `default` index
 * (`searchables: content` across every collection/taxonomy/asset container, on
 * the `local` driver) that performs a full index rebuild scanning every
 * searchable — ~12s per save. Across a bulk import of hundreds of terms and
 * entries that cost dominates the run. `SearchIndexUpdates::withDisabled()`
 * unbinds the subscriber for the run; the command rebuilds the indexes once at
 * the end instead (a single pass rather than one rebuild per save).
 *
 * `UpdateItemIndexes` is not a `Statamic\Events\Subscriber`, so it has no
 * `disable()`/`enable()` of its own. The guard forgets its handlers by filtering
 * the dispatcher's listener table the way the `forgetListener` macro Statamic
 * registers does, then re-subscribes the class to restore them. The listener-state
 * tests here guard on {@see searchIndexDispatcherSupportsListenerRemoval()} and skip
 * where the harness cannot exercise removal; the package gate asserts removal and
 * restore directly in `tests/Integration/SearchIndexUpdatesScopeTest.php`. The
 * contract tests (return value, exception propagation) always run.
 *
 * @command  php artisan test vendor/uams-web/aether-wordpress-importer/tests/Feature/SearchIndexUpdatesTest.php
 */
it('registers the UpdateItemIndexes listener on TermSaved by default', function (): void {
    // Statamic should register the UpdateItemIndexes listener on TermSaved by default.
    expect(searchIndexTermSavedListenerCount())->toBeGreaterThanOrEqual(1);
});

it('runs the callback with search indexing disabled and restores the listener after', function (): void {
    if (! searchIndexDispatcherSupportsListenerRemoval()) {
        TestCase::markTestSkipped('This dispatcher cannot unbind listeners; removal is verified under the console kernel.');
    }

    $before = searchIndexTermSavedListenerCount();

    $during = null;
    SearchIndexUpdates::withDisabled(function () use (&$during): void {
        $during = searchIndexTermSavedListenerCount();
    });

    // The UpdateItemIndexes listener should be unbound inside the callback.
    expect($during)->toBe($before - 1);

    // The UpdateItemIndexes listener should be restored after the callback.
    expect(searchIndexTermSavedListenerCount())->toBe($before);
});

it('restores the listener when the callback throws', function (): void {
    if (! searchIndexDispatcherSupportsListenerRemoval()) {
        TestCase::markTestSkipped('This dispatcher cannot unbind listeners; removal is verified under the console kernel.');
    }

    $before = searchIndexTermSavedListenerCount();

    $exceptionMessage = null;
    try {
        SearchIndexUpdates::withDisabled(function (): void {
            throw new RuntimeException('import blew up');
        });
    } catch (RuntimeException $e) {
        $exceptionMessage = $e->getMessage();
    }

    expect($exceptionMessage)->toBe('import blew up');

    // The UpdateItemIndexes listener should be restored even when the callback throws.
    expect(searchIndexTermSavedListenerCount())->toBe($before);
});

it('propagates the exception from the callback', function (): void {
    expect(function (): void {
        SearchIndexUpdates::withDisabled(function (): void {
            throw new RuntimeException('import blew up');
        });
    })->toThrow(RuntimeException::class, 'import blew up');
});

it('returns the callback return value', function (): void {
    $result = SearchIndexUpdates::withDisabled(fn (): int => 42);

    expect($result)->toBe(42);
});

/**
 * Resolve the application event dispatcher as a concrete Illuminate dispatcher.
 */
function searchIndexEventDispatcher(): Dispatcher
{
    $events = app('events');

    expect($events)->toBeInstanceOf(Dispatcher::class);

    if (! $events instanceof Dispatcher) {
        throw new RuntimeException('Expected the application events binding to be an Illuminate\Events\Dispatcher.');
    }

    return $events;
}

/**
 * Count the listeners currently bound to the TermSaved event. UpdateItemIndexes
 * subscribes to TermSaved, so this drops by one when the guard disables it.
 */
function searchIndexTermSavedListenerCount(): int
{
    return \count(searchIndexEventDispatcher()->getListeners(TermSaved::class));
}

/**
 * Whether this environment's event dispatcher can actually unbind the
 * UpdateItemIndexes listener. Restores the listener only when it was genuinely
 * removed, so the probe leaves dispatcher state unchanged either way.
 */
function searchIndexDispatcherSupportsListenerRemoval(): bool
{
    $before = searchIndexTermSavedListenerCount();

    searchIndexForgetTermSavedListener(UpdateItemIndexes::class.'@update');
    $supported = searchIndexTermSavedListenerCount() < $before;

    // Only re-subscribe if the forget actually removed it, so an unsupported
    // harness is not left with duplicate listeners.
    if ($supported) {
        searchIndexEventDispatcher()->subscribe(UpdateItemIndexes::class);
    }

    return $supported;
}

/**
 * Remove a single TermSaved listener without relying on Statamic's macro.
 *
 * @param  string  $listener  The listener identifier to remove
 */
function searchIndexForgetTermSavedListener(string $listener): void
{
    $events = searchIndexEventDispatcher();
    $property = new ReflectionProperty($events, 'listeners');
    $property->setAccessible(true);

    $listenersRaw = $property->getValue($events);

    if (! \is_array($listenersRaw) || ! isset($listenersRaw[TermSaved::class])) {
        return;
    }

    $eventListeners = $listenersRaw[TermSaved::class];

    if (! \is_array($eventListeners)) {
        return;
    }

    $listenersRaw[TermSaved::class] = \array_values(
        \array_filter(
            $eventListeners,
            static function (mixed $handler) use ($listener): bool {
                if (\is_string($handler)) {
                    return $handler !== $listener;
                }

                if (
                    \is_array($handler)
                    && isset($handler[0], $handler[1])
                    && \is_string($handler[0])
                    && \is_string($handler[1])
                ) {
                    return ($handler[0].'@'.$handler[1]) !== $listener;
                }

                return true;
            }
        )
    );

    $property->setValue(
        $events,
        $listenersRaw
    );
}
