<?php

declare(strict_types=1);

use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy as TaxonomyFacade;
use Statamic\Taxonomies\Taxonomy as StatamicTaxonomy;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\SiteManager;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

/**
 * Tests that a destination gate resolves a handle in whichever shape it takes.
 *
 * `uams-statamic#1863` converted `logos_item_categories` and `people_groups` from
 * flat taxonomies into structured collections. The gate resolved a taxonomy and
 * only a taxonomy, so it reported both as missing — and its callers skip an entire
 * content type on that answer. A 39-page import wrote nothing and exited `0` (wordpress-importer-archive#704).
 *
 * The two halves are asserted separately on purpose. That a collection-shaped
 * destination now passes is the fix; that a handle absent in *both* shapes still
 * fails is what stops the fix from turning a real misconfiguration into a silent
 * pass, which would be a worse defect than the one being repaired.
 *
 * **Not covered here: enabling a site on a destination that exists but is not yet
 * enabled for it.** Without `statamic.system.multisite`, Statamic reports the default
 * site as a destination's only site whatever its `sites` array holds, so that state
 * needs multisite switched on; `SiteManagerTest` does that and covers it.
 *
 * @command  php vendor/bin/pest tests/Integration/SiteManagerDestinationShapeTest.php
 */
beforeEach(function (): void {
    Site::setSites([
        'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
    ]);
});

it('accepts a destination that exists as a taxonomy', function (): void {
    $taxonomy = TaxonomyFacade::make('shape_probe_taxonomy');

    // `TaxonomyFacade::make()` is typed to the contract, which declares no save().
    expect($taxonomy)->toBeInstanceOf(StatamicTaxonomy::class);

    if ($taxonomy instanceof StatamicTaxonomy) {
        $taxonomy->sites(['default']);
        $taxonomy->save();
    }

    expect((new SiteManager)->enableDestinationForSite('default', 'shape_probe_taxonomy'))
        ->toBeTrue();
});

it('accepts a destination that exists as a collection', function (): void {
    // The regression: before wordpress-importer-archive#704 this returned false, and every caller skipped
    // its whole content type as a result.
    $collection = CollectionFacade::make('shape_probe_collection');
    $collection->sites(['default']);
    $collection->save();

    expect((new SiteManager)->enableDestinationForSite('default', 'shape_probe_collection'))
        ->toBeTrue();
});

it('refuses a handle that exists in neither shape', function (): void {
    // Failing closed here is correct, and is what keeps the fix from converting a
    // genuine misconfiguration into an import that quietly writes nothing.
    expect((new SiteManager)->enableDestinationForSite('default', 'shape_probe_absent'))
        ->toBeFalse();
});
