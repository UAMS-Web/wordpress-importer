<?php

declare(strict_types=1);

use Statamic\Facades\Stache as StacheFacade;
use Statamic\Stache\Stores\Store;
use Tests\TestCase;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\StacheIndexUpdateToggle;

uses(TestCase::class);

afterEach(function (): void {
    // Never leave the Stache with indexing disabled for subsequent tests, and
    // clear the memoized support probe.
    StacheIndexUpdateToggle::enable();
    StacheIndexUpdateToggle::flushSupportCache();
});

/**
 * Contract tests pinning the statamic/cms behavior the importer's term-creation
 * speedup (issue wordpress-importer-archive#46) depends on.
 *
 * The importer pauses Stache index updates while bulk-saving terms and re-enables
 * them afterward. Statamic exposes a public `disableUpdatingIndexes()` but **no**
 * public re-enable, so {@see StacheIndexUpdateToggle} restores the protected
 * `updateIndexes` flag via reflection. These tests assert that contract holds, so
 * a future `composer update` that changes it fails CI loudly instead of silently
 * regressing the import.
 *
 * @command  php artisan test vendor/uams-web/aether-wordpress-importer/tests/Feature/StacheIndexUpdateToggleTest.php
 */
it('reports itself supported on the installed statamic', function (): void {
    StacheIndexUpdateToggle::flushSupportCache();

    // StacheIndexUpdateToggle should support the installed statamic/cms.
    expect(StacheIndexUpdateToggle::isSupported())->toBeTrue();
});

it('round-trips the flag when disabled then enabled', function (): void {
    // Index updating should be on by default.
    expect(StacheFacade::shouldUpdateIndexes())->toBeTrue();

    StacheIndexUpdateToggle::disable();

    // disable() should turn the flag off via the public API.
    expect(StacheFacade::shouldUpdateIndexes())->toBeFalse();

    // enable() should report success.
    expect(StacheIndexUpdateToggle::enable())->toBeTrue();

    // enable() should restore the flag (the reflection re-enable the fix relies on).
    expect(StacheFacade::shouldUpdateIndexes())->toBeTrue();
});

it('respects the disabled flag when warming a store', function (): void {
    StacheIndexUpdateToggle::disable();

    try {
        // The 'taxonomies' store always exists in a Statamic app.
        $store = StacheFacade::store('taxonomies');
        expect($store)->toBeInstanceOf(Store::class);

        if (! $store instanceof Store) {
            throw new RuntimeException('Expected the taxonomies store to be a Store instance.');
        }

        $store->warm();

        // warm() must not silently re-enable index updating.
        expect(StacheFacade::shouldUpdateIndexes())->toBeFalse();
    } finally {
        StacheIndexUpdateToggle::enable();
    }
});
