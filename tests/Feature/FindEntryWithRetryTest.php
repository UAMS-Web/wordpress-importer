<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Statamic\Entries\Entry;
use Statamic\Facades\Stache as StacheFacade;
use Tests\TestCase;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;

uses(TestCase::class);

afterEach(function (): void {
    Mockery::close();
});

/**
 * Tests for the deferred-link entry retry helper after wordpress-importer-archive#306.
 *
 * `findEntryWithRetry` used to clear the *entire* `entries` Stache store on
 * every first-attempt miss, forcing a full rebuild of
 * `storage/framework/cache/data/stache/keys/entries` per missed deferred link.
 * That per-miss `clear()` was a performance drain on large imports and the
 * source of the wordpress-importer-archive#173 cache-write crash that wordpress-importer-archive#305 could only tolerate. wordpress-importer-archive#306
 * replaces it with a targeted, per-entry invalidation: every retry attempt now
 * forces the finder's `forceFresh` path (a single `forgetItem()` for just that
 * entry) and the helper never touches the aggregate store itself.
 *
 * These tests pin that contract — a link that misses on its first attempt still
 * resolves, every attempt uses the targeted fresh path, and the helper never
 * asks the Stache for the `entries` store (i.e. never triggers a full-store
 * clear).
 *
 * @command  php artisan test vendor/uams-web/wordpress-importer/tests/Feature/FindEntryWithRetryTest.php
 */
it('resolves a deferred link that misses on its first attempt using only targeted invalidation', function (): void {
    // Spy the Stache facade so we can prove the helper never reaches for the
    // aggregate 'entries' store (the old per-miss clear() path).
    $stacheSpy = StacheFacade::spy();

    // A stand-in for the eventually-found entry.
    $entry = Mockery::mock(Entry::class);

    // Record the forceFresh flag passed to each attempt; miss once, then hit.
    $forceFreshCalls = [];
    $findCallback = function (bool $forceFresh) use (&$forceFreshCalls, $entry) {
        $forceFreshCalls[] = $forceFresh;

        // First attempt misses, second attempt resolves.
        return \count($forceFreshCalls) === 1 ? null : $entry;
    };

    // Use a zero retry delay so the test does not sleep.
    $result = LinkProcessor::findEntryWithRetry($findCallback, 2, 0);

    // The retry resolves to the entry.
    expect($result)->toBe($entry);

    // Every attempt forced the targeted fresh path — no cached fallback.
    expect($forceFreshCalls)->toBe([true, true]);

    // The helper never asked the Stache for the 'entries' store, so no
    // full-store clear()/rebuild was triggered on the miss.
    $stacheSpy->shouldNotHaveReceived('store');
});

it('returns a first-attempt hit without retrying', function (): void {
    $stacheSpy = StacheFacade::spy();

    $entry = Mockery::mock(Entry::class);

    $calls = 0;
    $findCallback = function (bool $forceFresh) use (&$calls, $entry) {
        $calls++;

        return $entry;
    };

    $result = LinkProcessor::findEntryWithRetry($findCallback, 2, 0);

    expect($result)->toBe($entry);

    // A first-attempt hit should not retry.
    expect($calls)->toBe(1);
    $stacheSpy->shouldNotHaveReceived('store');
});

it('returns null when every attempt misses', function (): void {
    $stacheSpy = StacheFacade::spy();

    $calls = 0;
    $findCallback = function (bool $forceFresh) use (&$calls) {
        $calls++;

        return null;
    };

    $result = LinkProcessor::findEntryWithRetry($findCallback, 2, 0);

    expect($result)->toBeNull();

    // Every retry attempt should run when all miss.
    expect($calls)->toBe(2);
    $stacheSpy->shouldNotHaveReceived('store');
});

it('tolerates a transient invalidation failure and retries', function (): void {
    // Swallow and record the warning the guard emits.
    $logSpy = Log::spy();

    $entry = Mockery::mock(Entry::class);

    $calls = 0;
    $findCallback = function (bool $forceFresh) use (&$calls, $entry) {
        $calls++;

        // First attempt blows up mid-invalidation (e.g. Windows sharing
        // violation), second attempt succeeds.
        if ($calls === 1) {
            throw new RuntimeException('Permission denied');
        }

        return $entry;
    };

    $result = LinkProcessor::findEntryWithRetry($findCallback, 2, 0);

    // A transient failure must not abort resolution.
    expect($result)->toBe($entry);
    $logSpy->shouldHaveReceived('warning');
});
