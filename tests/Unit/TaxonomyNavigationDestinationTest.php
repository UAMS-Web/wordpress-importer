<?php

declare(strict_types=1);

/**
 * Tests the value a taxonomy navigation import returns for one term: either the
 * entry it resolved to, or the code and reason it was skipped (#1314).
 *
 * @command  php vendor/bin/pest tests/Unit/TaxonomyNavigationDestinationTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Navigation\TaxonomyNavigationDestination;

it('carries the entry it resolved to and no skip', function (): void {
    $destination = TaxonomyNavigationDestination::entry('entry-1');

    expect($destination->entryId)->toBe('entry-1')
        ->and($destination->skipCode)->toBeNull()
        ->and($destination->skipReason)->toBeNull()
        ->and($destination->isResolved())->toBeTrue();
});

it('carries the skip code and reason and no entry', function (): void {
    $destination = TaxonomyNavigationDestination::skipped('no_entry', 'The term has no entry.');

    expect($destination->entryId)->toBeNull()
        ->and($destination->skipCode)->toBe('no_entry')
        ->and($destination->skipReason)->toBe('The term has no entry.')
        ->and($destination->isResolved())->toBeFalse();
});
