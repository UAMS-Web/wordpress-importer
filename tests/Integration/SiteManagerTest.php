<?php

declare(strict_types=1);

/**
 * Tests that `SiteManager` reports whether a collection or taxonomy is enabled for a
 * site, enables it when it is not, says so to the operator, and keeps the site list
 * free of blanks and repeats.
 *
 * Multisite is switched on: without `statamic.system.multisite`, Statamic reports the
 * default site as a destination's only site whatever its `sites` list holds, so a
 * destination not yet enabled for a site cannot be constructed.
 *
 * @command  php vendor/bin/pest tests/Integration/SiteManagerTest.php
 */

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Statamic\Entries\Collection as StatamicCollection;
use Statamic\Events\CollectionSaved;
use Statamic\Events\TaxonomySaved;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy as TaxonomyFacade;
use Statamic\Taxonomies\Taxonomy as StatamicTaxonomy;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\SiteManager;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

/**
 * Save a collection enabled for the given sites.
 *
 * @param  list<string>  $sites
 */
function siteManagerCollection(string $handle, array $sites): void
{
    $collection = CollectionFacade::make($handle);
    $collection->sites($sites);
    $collection->save();
}

/**
 * Save a taxonomy enabled for the given sites.
 *
 * @param  list<string>  $sites
 */
function siteManagerTaxonomy(string $handle, array $sites): void
{
    $taxonomy = TaxonomyFacade::make($handle);

    if (! $taxonomy instanceof StatamicTaxonomy) {
        throw new RuntimeException('Could not make a taxonomy.');
    }

    $taxonomy->sites($sites);
    $taxonomy->save();
}

/**
 * The sites a saved collection or taxonomy is enabled for, read back fresh.
 *
 * @return list<mixed>
 */
function siteManagerSitesOf(string $handle): array
{
    $destination = CollectionFacade::findByHandle($handle) ?? TaxonomyFacade::findByHandle($handle);

    $sites = $destination instanceof StatamicCollection || $destination instanceof StatamicTaxonomy
        ? $destination->sites()
        : null;

    return $sites instanceof Collection ? \array_values($sites->all()) : [];
}

/**
 * Call one of SiteManager's private site-list helpers.
 */
function siteManagerPrivate(string $method, mixed ...$arguments): mixed
{
    return (new ReflectionMethod(SiteManager::class, $method))->invoke(new SiteManager, ...$arguments);
}

/**
 * The text SiteManager printed, with "\n" line endings.
 *
 * BufferedOutput ends each line with PHP_EOL, which is "\r\n" on Windows, so the text is
 * normalized and the expectations below hold on every platform (#133).
 *
 * @param  BufferedOutput  $output  The output SiteManager wrote to
 * @return string The printed text
 */
function siteManagerText(BufferedOutput $output): string
{
    return \str_replace(\PHP_EOL, "\n", $output->fetch());
}

beforeEach(function (): void {
    config()->set('statamic.system.multisite', true);

    Site::setSites([
        'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
        'other' => ['name' => 'Other', 'locale' => 'en_US', 'url' => '/other/'],
    ]);

    siteManagerCollection('pages', ['default']);
    siteManagerTaxonomy('topics', ['default']);
});

it('reports a collection enabled only for the sites it lists', function (): void {
    $manager = new SiteManager;

    expect($manager->isCollectionEnabledForSite('default', 'pages'))->toBeTrue()
        ->and($manager->isCollectionEnabledForSite('other', 'pages'))->toBeFalse()
        ->and($manager->isCollectionEnabledForSite('default', 'missing'))->toBeFalse();
});

it('reports a taxonomy enabled only for the sites it lists', function (): void {
    $manager = new SiteManager;

    expect($manager->isTaxonomyEnabledForSite('default', 'topics'))->toBeTrue()
        ->and($manager->isTaxonomyEnabledForSite('other', 'topics'))->toBeFalse()
        ->and($manager->isTaxonomyEnabledForSite('default', 'missing'))->toBeFalse();
});

it('enables a collection for a new site, saves it, keeps its others, and says so', function (): void {
    $output = new BufferedOutput;
    Event::fake([CollectionSaved::class]);

    $enabled = (new SiteManager)->enableCollectionForSite('other', 'pages', $output);

    Event::assertDispatched(CollectionSaved::class);

    expect($enabled)->toBeTrue()
        ->and(siteManagerSitesOf('pages'))->toBe(['default', 'other'])
        ->and(siteManagerText($output))->toBe(
            "Checking if the 'pages' Statamic collection is enabled for the 'other' site...\n"
            ."  - Enabled the 'pages' Statamic collection for the 'other' site.\n"
        );
});

it('leaves a collection already enabled for the site as it is, and says so', function (): void {
    $output = new BufferedOutput;
    Event::fake([CollectionSaved::class]);

    $enabled = (new SiteManager)->enableCollectionForSite('default', 'pages', $output);

    Event::assertNotDispatched(CollectionSaved::class);

    expect($enabled)->toBeTrue()
        ->and(siteManagerSitesOf('pages'))->toBe(['default'])
        ->and(siteManagerText($output))->toBe(
            "Checking if the 'pages' Statamic collection is enabled for the 'default' site...\n"
            ."  - The 'pages' Statamic collection is already enabled for the 'default' site.\n"
        );
});

it('refuses a missing collection, and says so', function (): void {
    $output = new BufferedOutput;

    expect((new SiteManager)->enableCollectionForSite('default', 'missing', $output))->toBeFalse()
        ->and(siteManagerText($output))->toBe(
            "Checking if the 'missing' Statamic collection is enabled for the 'default' site...\n"
            ."  - The 'missing' collection does not exist in Statamic.\n"
        );
});

it('enables a taxonomy for a new site, saves it, keeps its others, and says so', function (): void {
    $output = new BufferedOutput;
    Event::fake([TaxonomySaved::class]);

    $enabled = (new SiteManager)->enableTaxonomyForSite('other', 'topics', $output);

    Event::assertDispatched(TaxonomySaved::class);

    expect($enabled)->toBeTrue()
        ->and(siteManagerSitesOf('topics'))->toBe(['default', 'other'])
        ->and(siteManagerText($output))->toBe(
            "Checking if the 'topics' Statamic taxonomy is enabled for the 'other' site...\n"
            ."  - Enabled the 'topics' Statamic taxonomy for the 'other' site.\n"
        );
});

it('leaves a taxonomy already enabled for the site as it is, and says so', function (): void {
    $output = new BufferedOutput;
    Event::fake([TaxonomySaved::class]);

    $enabled = (new SiteManager)->enableTaxonomyForSite('default', 'topics', $output);

    Event::assertNotDispatched(TaxonomySaved::class);

    expect($enabled)->toBeTrue()
        ->and(siteManagerSitesOf('topics'))->toBe(['default'])
        ->and(siteManagerText($output))->toBe(
            "Checking if the 'topics' Statamic taxonomy is enabled for the 'default' site...\n"
            ."  - The 'topics' Statamic taxonomy is already enabled for the 'default' site.\n"
        );
});

it('refuses a missing taxonomy, and says so', function (): void {
    $output = new BufferedOutput;

    expect((new SiteManager)->enableTaxonomyForSite('default', 'missing', $output))->toBeFalse()
        ->and(siteManagerText($output))->toBe(
            "Checking if the 'missing' Statamic taxonomy is enabled for the 'default' site...\n"
            ."  - The 'missing' taxonomy does not exist in Statamic.\n"
        );
});

it('enables a destination in whichever shape it exists', function (): void {
    $manager = new SiteManager;

    expect($manager->enableDestinationForSite('other', 'topics'))->toBeTrue()
        ->and($manager->enableDestinationForSite('other', 'pages'))->toBeTrue()
        ->and(siteManagerSitesOf('topics'))->toBe(['default', 'other'])
        ->and(siteManagerSitesOf('pages'))->toBe(['default', 'other']);
});

it('names a destination that exists in neither shape', function (): void {
    $output = new BufferedOutput;

    expect((new SiteManager)->enableDestinationForSite('default', 'missing', $output))->toBeFalse()
        ->and(siteManagerText($output))->toBe("  - 'missing' exists in Statamic as neither a taxonomy nor a collection.\n");
});

it('works without an output to report to', function (): void {
    // Every message is optional; each path must run with none given
    $manager = new SiteManager;

    expect($manager->enableCollectionForSite('other', 'pages'))->toBeTrue()
        ->and($manager->enableCollectionForSite('other', 'pages'))->toBeTrue()
        ->and($manager->enableCollectionForSite('other', 'missing'))->toBeFalse()
        ->and($manager->enableTaxonomyForSite('other', 'topics'))->toBeTrue()
        ->and($manager->enableTaxonomyForSite('other', 'topics'))->toBeTrue()
        ->and($manager->enableTaxonomyForSite('other', 'missing'))->toBeFalse()
        ->and($manager->enableDestinationForSite('other', 'missing'))->toBeFalse();
});

it('reads a site list from a collection or an array, keeping only non-empty strings', function (mixed $sites, array $expected): void {
    expect(siteManagerPrivate('normalizeSiteList', $sites))->toBe($expected);
})->with([
    'a collection' => [collect(['default', 'other']), ['default', 'other']],
    'an array' => [['default', 'other'], ['default', 'other']],
    'blanks and non-strings' => [['default', '', 7, null, 'other'], ['default', 'other']],
    'neither' => ['default', []],
]);

it('compares site handles strictly', function (): void {
    // '1' and '01' are equal only under loose comparison
    expect(siteManagerPrivate('siteListContains', ['01'], '1'))->toBeFalse()
        ->and(siteManagerPrivate('siteListContains', ['01'], '01'))->toBeTrue();
});

it('merges a site into a list once, as a list', function (): void {
    expect(siteManagerPrivate('mergeSiteIntoList', ['default', 'default', 'other'], 'other'))
        ->toBe(['default', 'other'])
        ->and(siteManagerPrivate('mergeSiteIntoList', collect(['default']), 'other'))
        ->toBe(['default', 'other']);
});
