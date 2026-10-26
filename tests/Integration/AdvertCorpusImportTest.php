<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use Statamic\Entries\Entry;
use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy as TaxonomyFacade;
use Statamic\Facades\Term as TermFacade;
use Statamic\Fields\Blueprint;
use Statamic\Taxonomies\LocalizedTerm;
use Statamic\Taxonomies\Taxonomy;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators\AdvertEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\TermDestinationRegistry;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentRoot;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;
use UAMSWeb\WordPressImporter\Tests\Support\ExportsCorpus;

uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

/**
 * Imports the real classifieds export through `AdvertEntryCreator` and checks it against the export's own counts (wordpress-importer-archive#774).
 *
 * `AdvertImportTest` pins each mapping on a synthetic export. This runs the same
 * creator over `WordPress/wordpress-export_inside-uams-edu_classifieds.xml` from the
 * private export corpus and checks the totals #774 and `UAMS-Web/uams-statamic#2096`
 * measured there: 229 listings, 39 of them live, 23 declared categories, 19 listings
 * without images and three with twelve.
 *
 * **Skipped when `WORDPRESS_IMPORTER_EXPORTS` is unset, which is its normal
 * state** in a slot and in the gate, exactly as the corpus guard in
 * `tests/Unit/TaxonomyDestinationTest.php` is. With access to the private
 * `UAMS-Web/wordpress-importer-exports`, set the variable to a clone of it
 * (see `ExportsCorpus`).
 *
 * Writes reach disk in this process's own content directory, for the reasons
 * `AdvertImportTest`'s docblock gives, and are removed in `afterEach`. Writing to
 * the shared tracked `tests/__fixtures__/content/` tree instead is what let this
 * file and `AdvertImportTest` delete each other's data in a parallel run (wordpress-importer-archive#1375).
 *
 * @command  php vendor/bin/pest tests/Integration/AdvertCorpusImportTest.php
 */
beforeEach(function (): void {
    // No search indexes, set before anything is saved, for the reason `AdvertImportTest` gives
    config(['statamic.search.indexes' => []]);

    Site::setSites([
        'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
    ]);

    $collection = CollectionFacade::make('classifieds');
    $collection->sites(['default']);
    $collection->dated(true);
    $collection->taxonomies(['classified_categories']);
    $collection->save();

    $blueprint = BlueprintFacade::make('classified');
    $blueprint->setNamespace('collections.classifieds');
    $blueprint->setContents(['fields' => [['handle' => 'title', 'field' => ['type' => 'text']]]]);
    $blueprint->save();

    $taxonomy = TaxonomyFacade::make('classified_categories');

    if (! $taxonomy instanceof Taxonomy) {
        throw new RuntimeException('Could not make the classified_categories taxonomy.');
    }

    $taxonomy->sites(['default']);
    $taxonomy->save();

    $termBlueprint = BlueprintFacade::make('classified_category');
    $termBlueprint->setNamespace('taxonomies.classified_categories');
    $termBlueprint->setContents([
        'fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'description', 'field' => ['type' => 'textarea']],
        ],
    ]);
    $termBlueprint->save();

    // Resolve every image to its URL rather than downloading 564 of them
    config(['wordpress-import.upload_images' => false, 'wordpress-import.upload_files' => false]);

    ImportWarningManager::clearWarnings();
});

afterEach(function (): void {
    Mockery::close();

    LinkProcessor::clearDeferredData();
    BaseEntryCreator::clearTaxonomyCaches();
    TermDestinationRegistry::clear();
    ImportWarningManager::clearWarnings();
    ProcessingContext::clearXmlPath();
    ProcessingContext::clearWordPressPageLink();

    foreach (['collections.classifieds.classified', 'taxonomies.classified_categories.classified_category'] as $handle) {
        $blueprint = BlueprintFacade::find($handle);

        if ($blueprint instanceof Blueprint) {
            $blueprint->delete();
        }
    }

    // Remove what the import wrote into the per-process content tree
    advertCorpusRemoveTree(ContentRoot::collections().'/classifieds');
    advertCorpusRemoveTree(ContentRoot::collections().'/classifieds.yaml');
    advertCorpusRemoveTree(ContentRoot::taxonomies().'/classified_categories');
    advertCorpusRemoveTree(ContentRoot::taxonomies().'/classified_categories.yaml');
});

it('imports the classifieds export to the counts the export itself carries', function (): void {
    $export = ExportsCorpus::path('WordPress/wordpress-export_inside-uams-edu_classifieds.xml');

    if ($export === null) {
        Assert::markTestSkipped(ExportsCorpus::SKIP_MESSAGE);
    }

    // A clone without this export is a broken clone, not an absent corpus
    expect($export)->toBeFile();

    $parser = new XmlParser;
    $parser->parse($export);

    // The Bard conversion is `ContentProcessor`'s own; stubbed as in `AdvertImportTest`
    $contentProcessor = Mockery::mock(ContentProcessor::class, [
        'process' => ['content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Body.']]]], 'page_builder' => []],
    ]);

    if (! $contentProcessor instanceof ContentProcessor) {
        throw new RuntimeException('Could not build the content processor stub.');
    }

    (new AdvertEntryCreator)->createEntries(
        'default',
        'classifieds',
        $parser,
        new AssetImporter,
        new BufferedOutput,
        new MetadataMapper,
        $contentProcessor
    );

    $entries = [];

    foreach (EntryFacade::all() as $entry) {
        if ($entry instanceof Entry && $entry->collectionHandle() === 'classifieds') {
            $entries[] = $entry;
        }
    }

    // Every listing, not only the live ones (#2096's history decision)
    expect($entries)->toHaveCount(229)
        ->and(\count(\array_filter($entries, static fn (Entry $entry): bool => $entry->published() === true)))->toBe(39);

    // Every listing carries an expiration date, and every one of the 221 prices converted
    expect(\count(\array_filter($entries, static fn (Entry $entry): bool => \is_string($entry->get('expiration_date')))))->toBe(229)
        ->and(\count(\array_filter($entries, static fn (Entry $entry): bool => \is_float($entry->get('price')))))->toBe(221)
        ->and(ImportWarningManager::getWarnings('content', 'unconverted_advert_prices'))->toBe([])
        ->and(ImportWarningManager::getWarnings('content', 'unconverted_advert_expiration_dates'))->toBe([]);

    // Images: none on 19, and all twelve on each of the three largest listings
    $imageCounts = \array_map(
        static function (Entry $entry): int {
            $gallery = $entry->get('gallery');

            return ($entry->has('featured_image') ? 1 : 0) + (\is_array($gallery) ? \count($gallery) : 0);
        },
        $entries
    );

    expect(\count(\array_filter($imageCounts, static fn (int $count): bool => $count === 0)))->toBe(19)
        ->and(\count(\array_filter($imageCounts, static fn (int $count): bool => $count === 12)))->toBe(3);

    // All 23 declared categories exist, titled as WordPress names them
    $terms = [];

    foreach (TermDestinationRegistry::all() as $key => $destination) {
        if (! \str_starts_with($key, 'advert_category::')) {
            continue;
        }

        $term = TermFacade::find($destination['handle'].'::'.$destination['reference']);

        if (! $term instanceof LocalizedTerm) {
            continue;
        }

        $localized = $term->in('default');
        $terms[] = $localized instanceof LocalizedTerm ? $localized : $term;
    }

    $titles = [];

    foreach ($terms as $term) {
        $titles[] = $term->get('title');
    }

    expect($terms)->toHaveCount(23)
        ->and($titles)->toContain('Auto & Marine')
        ->and(\array_filter($titles, static fn (mixed $title): bool => ! \is_string($title) || \str_contains($title, ' — ') || \str_contains($title, '&amp;')))->toBe([]);

    // The 22 categories listings use are all referenced by some listing
    $referenced = [];

    foreach ($entries as $entry) {
        $categories = $entry->get('classified_categories');

        foreach (\is_array($categories) ? $categories : [] as $slug) {
            $referenced[\is_string($slug) ? $slug : ''] = true;
        }
    }

    expect($referenced)->toHaveCount(22);
});

/**
 * Delete a file, or a directory and everything under it, when it exists.
 *
 * @param  string  $path  The path to remove
 */
function advertCorpusRemoveTree(string $path): void
{
    if (\is_file($path) || \is_link($path)) {
        \unlink($path);

        return;
    }

    if (! \is_dir($path)) {
        return;
    }

    $children = \scandir($path);

    foreach (\is_array($children) ? $children : [] as $child) {
        if ($child === '.' || $child === '..') {
            continue;
        }

        advertCorpusRemoveTree($path.'/'.$child);
    }

    \rmdir($path);
}
