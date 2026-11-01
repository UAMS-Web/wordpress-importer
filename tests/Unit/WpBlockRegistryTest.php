<?php

declare(strict_types=1);

/**
 * Tests for WpBlockRegistry, the process-wide map from a WordPress `wp_block`
 * post ID to the Statamic entry ID it was imported as (#1314).
 *
 * @command  php vendor/bin/pest tests/Unit/WpBlockRegistryTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\WpBlockRegistry;

beforeEach(fn () => WpBlockRegistry::clear());

afterEach(fn () => WpBlockRegistry::clear());

it('returns the entry ID registered for a block, and null for one never registered', function (): void {
    WpBlockRegistry::registerMapping('101', 'entry-a');

    expect(WpBlockRegistry::getEntryId('101'))->toBe('entry-a')
        ->and(WpBlockRegistry::getEntryId('102'))->toBeNull();
});

it('keeps every mapping in registration order, a later registration replacing the entry ID', function (): void {
    WpBlockRegistry::registerMapping('101', 'entry-a');
    WpBlockRegistry::registerMapping('205', 'entry-b');
    WpBlockRegistry::registerMapping('101', 'entry-c');

    expect(WpBlockRegistry::getAllMappings())->toBe(['101' => 'entry-c', '205' => 'entry-b']);
});

it('forgets every mapping on clear', function (): void {
    WpBlockRegistry::registerMapping('101', 'entry-a');

    WpBlockRegistry::clear();

    expect(WpBlockRegistry::getAllMappings())->toBe([])
        ->and(WpBlockRegistry::getEntryId('101'))->toBeNull();
});
