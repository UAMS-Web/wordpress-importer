<?php

declare(strict_types=1);

use Statamic\Entries\Entry;
use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Site;
use Statamic\Facades\Stache;
use Statamic\Facades\Taxonomy as TaxonomyFacade;
use Statamic\Facades\Term as TermFacade;
use Statamic\Fields\Blueprint;
use Statamic\Taxonomies\LocalizedTerm;
use Statamic\Taxonomies\Taxonomy;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\SameSitePolicy;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\Creators\AdvertEntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Taxonomies\TermDestinationRegistry;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ContentRoot;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;
use UAMSWeb\Aether\WordPressImporter\Tests\Support\TemporaryFileRegistry;

uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

/**
 * Imports WP Adverts listings into the `classifieds` collection through the production creator (wordpress-importer-archive#774).
 *
 * The export is synthetic and deliberately so: the real classifieds export carries
 * staff names, email addresses and phone numbers, which do not belong in this
 * repository. Each listing below reproduces a SHAPE measured in that export instead,
 * named where it is built. `AdvertCorpusImportTest` runs the same creator over the
 * real export when `WORDPRESS_IMPORTER_EXPORTS` names a clone of the corpus.
 *
 * Image uploads are disabled, so each image resolves to its attachment URL rather
 * than being downloaded. The URLs are distinct per attachment, which is what lets
 * the gallery's order be read back.
 *
 * **Writes reach disk, deliberately, in a directory of this process's own.** The
 * flat term pass saves terms with Stache index updating paused and then rebuilds
 * the term store FROM DISK, which is what makes the terms visible again. Terms are
 * read back through a rebuild from disk too, so a title that was set on the
 * in-memory term but never saved does not pass.
 *
 * `PreventsSavingStacheItemsToDisk` does not stop those writes: it moves every
 * store's directory, and `StatamicTestCase` makes that directory per-process
 * (`tests/__fixtures__/dev-null-<pid>/content/`). Measured with a probe under the
 * trait: a saved term's YAML is on disk there, a clear and warm of the store finds
 * it, and with the file deleted the same clear and warm does not.
 *
 * The per-process directory is what lets this file run beside another process
 * (wordpress-importer-archive#1375). It used to write into the tracked `tests/__fixtures__/content/` tree,
 * which every process in a checkout shares. `AdvertCorpusImportTest` writes the
 * same `classifieds` collection and `classified_categories` taxonomy there, so a
 * parallel run that put the two files in different workers let one's `afterEach`
 * delete what the other was still reading. Measured with two concurrent runs of
 * this file alone in one checkout: 5 and 3 of 18 tests failed before the change,
 * and 18 of 18 passed in each, three rounds running, after it.
 *
 * Base class: `StatamicTestCase` (`tests/Integration`). A real collection, taxonomy
 * and term blueprint are reachable under Testbench, so this runs in the standalone
 * gate.
 *
 * @command  php vendor/bin/pest tests/Integration/AdvertImportTest.php
 */
beforeEach(function (): void {
    // ---
    // No search indexes, set before anything is saved. These saves reach disk, and on
    // disk every save updates the Testbench workbench's search index files, which
    // every other test shares. An index left unreadable by an interrupted run then
    // fails this file's saves with a JsonException that has nothing to do with the
    // import.
    // ---
    config(['statamic.search.indexes' => []]);

    Site::setSites([
        'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
    ]);

    // Dated, as the host's `classifieds` collection is
    $collection = CollectionFacade::make('classifieds');
    $collection->sites(['default']);
    $collection->dated(true);
    $collection->taxonomies(['classified_categories']);
    $collection->save();

    // ---
    // Two blueprints, so the one an entry is saved under is a choice the import makes.
    // `another` sorts first, and a collection falls back to its first blueprint, so an
    // entry saved without naming `classified` would land on the wrong one.
    // ---
    foreach (['another', 'classified'] as $handle) {
        $blueprint = BlueprintFacade::make($handle);
        $blueprint->setNamespace('collections.classifieds');
        $blueprint->setContents([
            'fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text']],
            ],
        ]);
        $blueprint->save();
    }

    $taxonomy = TaxonomyFacade::make('classified_categories');

    if (! $taxonomy instanceof Taxonomy) {
        throw new RuntimeException('Could not make the classified_categories taxonomy.');
    }

    $taxonomy->sites(['default']);
    $taxonomy->save();

    // The host's term blueprint carries `description`, which the import fills
    $termBlueprint = BlueprintFacade::make('classified_category');
    $termBlueprint->setNamespace('taxonomies.classified_categories');
    $termBlueprint->setContents([
        'fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'description', 'field' => ['type' => 'textarea']],
        ],
    ]);
    $termBlueprint->save();

    // Resolve every image to its URL rather than downloading it
    config(['wordpress-import.upload_images' => false, 'wordpress-import.upload_files' => false]);

    ImportWarningManager::clearWarnings();
});

afterEach(function (): void {
    // Process-wide state the import writes, reset as production resets it per command
    LinkProcessor::clearDeferredData();
    BaseEntryCreator::clearTaxonomyCaches();
    TermDestinationRegistry::clear();
    ImportWarningManager::clearWarnings();
    ProcessingContext::clearXmlPath();
    ProcessingContext::clearWordPressPageLink();

    // Blueprints are not Stache items, so their YAML outlives the process unless deleted
    foreach (['collections.classifieds.another', 'collections.classifieds.classified', 'taxonomies.classified_categories.classified_category'] as $handle) {
        $blueprint = BlueprintFacade::find($handle);

        if ($blueprint instanceof Blueprint) {
            $blueprint->delete();
        }
    }

    TemporaryFileRegistry::sweep();

    // ---
    // Remove what this file wrote into the per-process content tree, as whole
    // trees: a second run writes suffixed copies beside the first, which a list of
    // slugs would miss. Teardown deletes the directory as well, but through
    // `deleteDirectory()`, which reports success whether or not it removed anything.
    // ---
    advertRemoveTree(ContentRoot::collections().'/classifieds');
    advertRemoveTree(ContentRoot::collections().'/classifieds.yaml');
    advertRemoveTree(ContentRoot::taxonomies().'/classified_categories');
    advertRemoveTree(ContentRoot::taxonomies().'/classified_categories.yaml');
});

it('imports every listing, publishing only the live one', function (): void {
    advertImportRun();

    // Every status is imported; only `publish` is live
    expect(advertEntry('bike-for-sale')->published())->toBeTrue()
        ->and(advertEntry('old-truck')->published())->toBeFalse()
        ->and(advertEntry('unfinished-post')->published())->toBeFalse()
        ->and(advertEntryIds())->toHaveCount(3);
});

it('maps every populated listing field to its blueprint handle', function (): void {
    advertImportRun();

    $entry = advertEntry('bike-for-sale');

    expect($entry->get('title'))->toBe('Bike & Helmet')
        ->and($entry->get('price'))->toBe(25.0)
        ->and($entry->get('contact_name'))->toBe('Pat Poster')
        ->and($entry->get('contact_email'))->toBe('poster@example.test')
        ->and($entry->get('contact_phone'))->toBe('501-555-0100')
        ->and($entry->get('location'))->toBe('Little Rock')
        // 1742180400 is 2025-03-17 03:00 UTC, still the 16th in Little Rock
        ->and($entry->get('expiration_date'))->toBe('2025-03-16')
        ->and($entry->get('description'))->toBe(advertBody())
        ->and($entry->get('account'))->toBeNull();

    // Saved under the classified blueprint, not the collection's first
    $blueprint = $entry->blueprint();

    expect($blueprint instanceof Blueprint ? $blueprint->handle() : null)->toBe('classified');

    // The entry id embeds the WordPress post id, as every importer id does
    expect($entry->id())->toBeString()->toContain('-101-');

    // What the migration redirect generator and the change tracking read
    expect($entry->get('original_wordpress_url'))->toBe('https://example.test/classifieds/advert/bike-for-sale/')
        ->and($entry->get('wordpress_post_id'))->toBe(101)
        ->and($entry->get('updated_at'))->toBeInt();

    // No WordPress-side keys leak through under their source names
    expect($entry->get('adverts_price'))->toBeNull()
        ->and($entry->get('_expiration_date'))->toBeNull();

    // And nothing was reported: every value converted
    expect(ImportWarningManager::getWarnings('content', 'unconverted_advert_prices'))->toBe([])
        ->and(ImportWarningManager::getWarnings('content', 'unconverted_advert_expiration_dates'))->toBe([]);
});

it('leaves out optional fields a listing never filled', function (): void {
    advertImportRun();

    // Shaped like the 8 listings without a price and the 101 without a phone
    $entry = advertEntry('old-truck');

    expect($entry->get('price'))->toBeNull()
        ->and($entry->has('contact_phone'))->toBeFalse()
        ->and($entry->has('location'))->toBeFalse()
        ->and($entry->get('contact_email'))->toBe('driver@example.test');
});

it('imports the featured image and the gallery in stored order, all twelve images kept', function (): void {
    advertImportRun();

    $entry = advertEntry('bike-for-sale');

    // The featured image leads the stored order, so the gallery is the other eleven
    expect($entry->get('featured_image'))->toBe(advertImageUrl(9001))
        ->and($entry->get('gallery'))->toBe(\array_map(advertImageUrl(...), \range(9002, 9012)));
});

it('keeps the gallery order around a featured image stored mid-list, skipping a missing attachment', function (): void {
    advertImportRun();

    $entry = advertEntry('unfinished-post');

    // Stored order [9014, 9013, 9999, 9015]: 9013 is featured and 9999 is not in the export
    expect($entry->get('featured_image'))->toBe(advertImageUrl(9013))
        ->and($entry->get('gallery'))->toBe([advertImageUrl(9014), advertImageUrl(9015)]);
});

it('imports a listing with no images cleanly', function (): void {
    advertImportRun();

    // Shaped like the 19 listings that carry no images at all
    $entry = advertEntry('old-truck');

    expect($entry->has('featured_image'))->toBeFalse()
        ->and($entry->has('gallery'))->toBeFalse();
});

it('leaves out an image the asset importer returned nothing for', function (): void {
    // ---
    // `AssetImporter::importFile()` can return an empty path on its uploads-enabled
    // branch (wordpress-importer-archive#754). The featured image of `unfinished-post` and the second gallery
    // image of `bike-for-sale` come back empty here; neither may be stored as ''.
    // ---
    advertImportRun(assetImporter: new AdvertEmptyPathImporter([advertImageUrl(9013), advertImageUrl(9002)]));

    expect(advertEntry('unfinished-post')->get('featured_image'))->toBeNull()
        ->and(advertEntry('unfinished-post')->has('featured_image'))->toBeFalse()
        ->and(advertEntry('bike-for-sale')->get('gallery'))->toBe(\array_map(advertImageUrl(...), \range(9003, 9012)));
});

it('files each listing under the category terms the import created, omitting one that does not resolve', function (): void {
    advertImportRun();

    $general = TermDestinationRegistry::referenceFor('advert_category', '44');
    $reallocation = TermDestinationRegistry::referenceFor('advert_category', '48');
    $auto = TermDestinationRegistry::referenceFor('advert_category', '26');

    // The fixture guard: every category resolved, so the comparisons below are not null against null
    expect($general)->toBeString()
        ->and($reallocation)->toBeString()
        ->and($auto)->toBeString();

    // ---
    // `unfinished-post` names a category the export never declares BEFORE one it does:
    // the undeclared one is dropped rather than written as null, and resolution carries
    // on to the next rather than stopping at it.
    // ---
    expect(advertEntry('bike-for-sale')->get('classified_categories'))->toBe([$general, $reallocation])
        ->and(advertEntry('old-truck')->get('classified_categories'))->toBe([$auto])
        ->and(advertEntry('unfinished-post')->get('classified_categories'))->toBe([$general]);
});

it('imports every declared category, used or not, with its plain title and description', function (): void {
    $output = advertImportRun()['output'];

    // All declared terms exist, including two no listing uses, titled as WordPress names them
    foreach ([
        '44' => 'General',
        '48' => 'UAMS Property Reallocation',
        '26' => 'Auto & Marine',
        '40' => 'Wanted',
        '41' => "Kids' Stuff",
    ] as $termId => $title) {
        expect(advertTerm((string) $termId)->get('title'))->toBe($title);
    }

    // The one category with rules in WordPress carries them across, trimmed
    expect(advertTerm('48')->get('description'))->toBe('Items purchased with UAMS funds, offered to other departments.')
        ->and(advertTerm('44')->get('description'))->toBeNull()
        ->and($output)->toContain("Finished category '".TermDestinationRegistry::referenceFor('advert_category', '44')."' as 'General'.");
});

it('leaves a category an editor changed alone on re-import, and finishes one still as imported', function (): void {
    advertImportRun();

    // An editor renames one category, blanks another's title and writes a description on a third
    advertEditTerm('44', 'title', 'Not General');
    advertEditTerm('40', 'title', '');
    advertEditTerm('26', 'title', 'Auto &amp; Marine');
    advertEditTerm('48', 'description', 'Rules an editor wrote.');

    // Re-import, keeping existing terms rather than overwriting them
    BaseEntryCreator::clearTaxonomyCaches();
    $second = advertImportRun(taxonomyBehavior: 'Skip matching terms')['output'];

    // ---
    // The rename ends with the WordPress name, which is the case a check that did not
    // anchor on the importer's own " — " prefix would clobber. The blank title and the
    // still-encoded one are the importer's to finish; the description is the editor's.
    // ---
    expect(advertTerm('44')->get('title'))->toBe('Not General')
        ->and(advertTerm('40')->get('title'))->toBe('Wanted')
        ->and(advertTerm('26')->get('title'))->toBe('Auto & Marine')
        ->and(advertTerm('48')->get('description'))->toBe('Rules an editor wrote.');

    // Only the two terms that needed finishing were written; the rest were left untouched
    expect(\substr_count($second, 'Finished category'))->toBe(2)
        ->and($second)->toContain("as 'Wanted'.")
        ->and($second)->toContain("as 'Auto & Marine'.");
});

it('fills a category description an editor left blank', function (): void {
    advertImportRun();

    advertEditTerm('48', 'description', '   ');

    BaseEntryCreator::clearTaxonomyCaches();
    advertImportRun(taxonomyBehavior: 'Skip matching terms');

    expect(advertTerm('48')->get('description'))->toBe('Items purchased with UAMS funds, offered to other departments.');
});

it('keeps a category once when a listing names it twice, and carries on to the next', function (): void {
    advertImportRun(advertExport(['bike-categories' => '<category domain="advert_category" nicename="general"><![CDATA[General]]></category>
  <category domain="advert_category" nicename="general"><![CDATA[General]]></category>
  <category domain="advert_category" nicename="uams-property-reallocation"><![CDATA[UAMS Property Reallocation]]></category>']));

    expect(advertEntry('bike-for-sale')->get('classified_categories'))->toBe([
        TermDestinationRegistry::referenceFor('advert_category', '44'),
        TermDestinationRegistry::referenceFor('advert_category', '48'),
    ]);
});

it('reports no page data for a listing that was not saved', function (): void {
    $creator = new class extends AdvertEntryCreator
    {
        /**
         * Fail every save.
         *
         * @param  string  $collectionHandle  The collection
         * @param  string  $site  The site
         * @param  string  $entrySlug  The slug
         * @param  array<int|string, mixed>  $data  The data
         * @param  OutputInterface  $output  The output
         * @param  ?DateTime  $entryDate  The date
         * @param  ?bool  $published  Whether published
         * @param  ?string  $postDateStr  The post date
         */
        protected function saveEntry(
            string $collectionHandle,
            string $site,
            string $entrySlug,
            array $data,
            OutputInterface $output,
            ?DateTime $entryDate = null,
            ?bool $published = null,
            ?string $postDateStr = null
        ): ?Entry {
            return null;
        }
    };

    expect(advertImportRun(creator: $creator)['pageData'])->toBe([]);
});

it('finishes a category after one it has no record of', function (): void {
    advertImportRun();
    advertEditTerm('40', 'title', '');

    // Term 999 was never written, so the pass has no destination for it
    $path = \sys_get_temp_dir().'/advert-terms-'.\bin2hex(\random_bytes(6)).'.xml';
    \file_put_contents($path, '<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/"><channel>
<wp:term><wp:term_id>999</wp:term_id><wp:term_taxonomy>advert_category</wp:term_taxonomy><wp:term_slug>gone</wp:term_slug><wp:term_name>Gone</wp:term_name></wp:term>
<wp:term><wp:term_id>40</wp:term_id><wp:term_taxonomy>advert_category</wp:term_taxonomy><wp:term_slug>wanted</wp:term_slug><wp:term_name><![CDATA[Wanted ]]></wp:term_name></wp:term>
</channel></rss>');
    $parser = new XmlParser;
    $parser->parse($path);

    // The parser reads the file as it is asked for terms, so it stays until then
    (new ReflectionMethod(AdvertEntryCreator::class, 'finishCategoryTerms'))->invoke(new AdvertEntryCreator, $parser, 'default', new BufferedOutput);
    \unlink($path);

    expect(advertTerm('40')->get('title'))->toBe('Wanted');
});

it('has no people, post categories or post tags, and nothing further to validate', function (): void {
    $creator = new AdvertEntryCreator;
    $output = new BufferedOutput;
    $creator->validateTaxonomies('classifieds', 'default', $output);

    expect($creator->hasShowPeople())->toBeFalse()
        ->and($creator->getPostCategories())->toBe([])
        ->and($creator->getPostTags())->toBe([])
        ->and($output->fetch())->toBe('');
});

it('skips listings that already exist when told to', function (): void {
    // Skipping applies only to listings that exist, so a first run under it imports all three
    advertImportRun(entryBehavior: 'Skip matching files');

    expect(advertEntryIds())->toHaveCount(3);

    // Start the second run with no URL registered, so what it registers is its own
    LinkProcessor::clearDeferredData();

    $second = advertImportRun(entryBehavior: 'Skip matching files');

    // Nothing new, nothing processed, and each listing's URL still registered for links
    expect(advertEntryIds())->toHaveCount(3)
        ->and($second['output'])->not->toContain('Processing advert')
        ->and(\substr_count($second['output'], 'Skipped entry'))->toBe(3)
        ->and(advertUrlMap())->toHaveKey('https://example.test/classifieds/advert/bike-for-sale/');
});

it('reports what it did as page data and registers each listing URL', function (): void {
    $run = advertImportRun();

    $row = advertPageRow($run['pageData'], 101);

    expect($row['entry'] ?? null)->toBe(advertEntry('bike-for-sale')->id())
        ->and($row['parent'] ?? null)->toBe('0')
        ->and($row['menu_order'] ?? null)->toBe(0)
        ->and($row['title'] ?? null)->toBe('Bike & Helmet');

    expect(advertUrlMap()['https://example.test/classifieds/advert/old-truck/'] ?? null)->toBe('102')
        ->and($run['output'])->toContain("Processing advert '".advertSlug('bike-for-sale')."'...")
        // The context names the listing being processed, so the last one is left in it
        ->and(ProcessingContext::getWordPressPageLink())->toBe('https://example.test/classifieds/advert/unfinished-post/');
});

it('falls back to a placeholder title where a listing has none', function (): void {
    $run = advertImportRun(advertExport(['truck-title' => '']));

    // The body converter and the page data both receive the placeholder rather than ''
    expect($run['titles'])->toBe(['Bike & Helmet', 'Untitled Advert', 'Unfinished Post'])
        ->and(advertPageRow($run['pageData'], 102)['title'] ?? null)->toBe('Untitled Advert');
});

it('leaves the description out when the body converts to nothing', function (): void {
    advertImportRun(body: []);

    expect(advertEntry('bike-for-sale')->has('description'))->toBeFalse();
});

it('reports a price it cannot convert rather than dropping it silently', function (): void {
    advertImportRun(advertExport(['bike-price' => 'OBO']));

    // The price field stays empty, and the loss is on the warning summary
    expect(advertEntry('bike-for-sale')->get('price'))->toBeNull()
        ->and(ImportWarningManager::getWarnings('content', 'unconverted_advert_prices'))->toBe([
            ['entry' => advertSlug('bike-for-sale'), 'site' => 'default', 'value' => 'OBO'],
        ]);
});

it('treats a blank price as no price rather than an unconvertible one', function (): void {
    advertImportRun(advertExport(['bike-price' => '   ']));

    expect(advertEntry('bike-for-sale')->get('price'))->toBeNull()
        ->and(ImportWarningManager::getWarnings('content', 'unconverted_advert_prices'))->toBe([]);
});

it('reports a listing without an expiration date, which the blueprint requires', function (): void {
    advertImportRun(advertExport(['truck-expiration' => '']));

    expect(advertEntry('old-truck')->get('expiration_date'))->toBeNull()
        ->and(ImportWarningManager::getWarnings('content', 'unconverted_advert_expiration_dates'))->toBe([
            ['entry' => advertSlug('old-truck'), 'site' => 'default', 'value' => ''],
        ]);
});

/**
 * Delete a file, or a directory and everything under it, when it exists.
 *
 * @param  string  $path  The path to remove
 */
function advertRemoveTree(string $path): void
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

        advertRemoveTree($path.'/'.$child);
    }

    \rmdir($path);
}

/**
 * Run the creator over an export.
 *
 * @param  string|null  $export  The export path; the standard fixture when null
 * @param  AssetImporter|null  $assetImporter  The asset importer; a default one when null
 * @param  string  $entryBehavior  The entry overwrite behavior
 * @param  string  $taxonomyBehavior  The taxonomy overwrite behavior
 * @param  list<array<string, mixed>>|null  $body  The Bard body the converter returns; one paragraph when null
 * @return array{output: string, pageData: array<int|string, mixed>, titles: list<string>} What the run printed, returned and converted
 */
function advertImportRun(
    ?string $export = null,
    ?AssetImporter $assetImporter = null,
    string $entryBehavior = 'Overwrite matching files',
    string $taxonomyBehavior = 'Overwrite matching terms',
    ?array $body = null,
    ?AdvertEntryCreator $creator = null
): array {
    $parser = new XmlParser;
    $parser->parse($export ?? advertExport());

    $contentProcessor = AdvertBodyConverter::returning($body ?? advertBody());
    $output = new BufferedOutput;

    $creator ??= new AdvertEntryCreator;
    $creator->setEntryOverwriteBehavior($entryBehavior);
    $creator->setTaxonomyOverwriteBehavior($taxonomyBehavior);

    $pageData = $creator->createEntries(
        'default',
        'classifieds',
        $parser,
        $assetImporter ?? new AssetImporter,
        $output,
        new MetadataMapper,
        $contentProcessor
    );

    return [
        'output' => $output->fetch(),
        'pageData' => $pageData,
        'titles' => $contentProcessor->titles,
    ];
}

/**
 * The Bard body the converter returns by default.
 *
 * @return list<array<string, mixed>> A one-paragraph Bard document
 */
function advertBody(): array
{
    return [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Gently used.']]]];
}

/**
 * The URL an attachment id resolves to with uploads disabled.
 *
 * @param  int  $attachmentId  The attachment id
 * @return string The attachment URL
 */
function advertImageUrl(int $attachmentId): string
{
    return "https://example.test/classifieds/wp-content/uploads/image-{$attachmentId}.jpg";
}

/**
 * The one `classifieds` entry whose slug starts with the given listing slug.
 *
 * @param  string  $slug  The WordPress post name
 * @return Entry The entry
 */
function advertEntry(string $slug): Entry
{
    $matches = [];

    foreach (EntryFacade::all() as $entry) {
        if (
            $entry instanceof Entry
            && $entry->collectionHandle() === 'classifieds'
            && \is_string($entry->slug())
            && \str_starts_with($entry->slug(), $slug)
        ) {
            $matches[] = $entry;
        }
    }

    // Exactly one, or the fields read below could belong to another listing
    expect($matches)->toHaveCount(1);

    $entry = $matches[0] ?? null;

    if (! $entry instanceof Entry) {
        throw new RuntimeException("No classifieds entry for '{$slug}'.");
    }

    return $entry;
}

/**
 * The slug of the one `classifieds` entry whose slug starts with the given listing slug.
 *
 * @param  string  $slug  The WordPress post name
 * @return string The entry slug
 */
function advertSlug(string $slug): string
{
    $entrySlug = advertEntry($slug)->slug();

    return \is_string($entrySlug) ? $entrySlug : '';
}

/**
 * One row of the page data a run returned.
 *
 * @param  array<int|string, mixed>  $pageData  The page data
 * @param  int  $postId  The WordPress post id
 * @return array<int|string, mixed> The row
 */
function advertPageRow(array $pageData, int $postId): array
{
    $row = $pageData[$postId] ?? ($pageData[(string) $postId] ?? null);

    expect($row)->toBeArray();

    return \is_array($row) ? $row : [];
}

/**
 * The ids of every `classifieds` entry.
 *
 * @return list<string> The entry ids
 */
function advertEntryIds(): array
{
    $ids = [];

    foreach (EntryFacade::all() as $entry) {
        if ($entry instanceof Entry && $entry->collectionHandle() === 'classifieds' && \is_string($entry->id())) {
            $ids[] = $entry->id();
        }
    }

    return $ids;
}

/**
 * The `classified_categories` term a WordPress category was imported as, read back from disk.
 *
 * The taxonomy's store is cleared and warmed first, so the term is the one saved to
 * disk rather than whatever the import left in memory.
 *
 * @param  string  $termId  The WordPress term id
 * @return LocalizedTerm The term
 */
function advertTerm(string $termId): LocalizedTerm
{
    $store = Stache::store('terms');
    $taxonomyStore = \is_object($store) && \method_exists($store, 'store') ? $store->store('classified_categories') : null;

    if (\is_object($taxonomyStore) && \method_exists($taxonomyStore, 'clear') && \method_exists($taxonomyStore, 'warm')) {
        $taxonomyStore->clear();
        $taxonomyStore->warm();
    }

    $slug = TermDestinationRegistry::referenceFor('advert_category', $termId);

    $term = \is_string($slug) ? TermFacade::find('classified_categories::'.$slug) : null;

    if (! $term instanceof LocalizedTerm) {
        throw new RuntimeException("No classified category for WordPress term {$termId}.");
    }

    $localized = $term->in('default');

    return $localized instanceof LocalizedTerm ? $localized : $term;
}

/**
 * Change one field on an imported category and save it, as an editor would.
 *
 * @param  string  $termId  The WordPress term id
 * @param  string  $field  The field to change
 * @param  string  $value  The new value
 */
function advertEditTerm(string $termId, string $field, string $value): void
{
    $term = advertTerm($termId);
    $term->set($field, $value);
    $term->save();
}

/**
 * The URL-to-post-id map the import registered for internal link resolution.
 *
 * @return array<int|string, mixed> The map
 */
function advertUrlMap(): array
{
    $map = (new ReflectionProperty(LinkProcessor::class, 'urlToPostIdMap'))->getValue();

    return \is_array($map) ? $map : [];
}

/**
 * Write the fixture export, and return its path.
 *
 * Three listings, each reproducing a shape measured in the classifieds export:
 *
 *   - `bike-for-sale`, published: every field filled, two categories, an entity in
 *     its title, and twelve images with the featured image first, as the largest
 *     listings carry
 *   - `old-truck`, expired: no price, phone, location or images
 *   - `unfinished-post`, draft: a category the export never declares ahead of one it
 *     does, and a stored
 *     order that places the featured image second and names an attachment the
 *     export does not carry
 *
 * and five declared categories, two of them used by no listing: `Wanted`, stored
 * with a trailing space, and `Kids' Stuff`, stored with an encoded apostrophe.
 *
 * @param  array<string, string>  $overrides  `bike-price`, `truck-title` or `truck-expiration` replace those values
 * @return string The export path
 */
function advertExport(array $overrides = []): string
{
    $price = $overrides['bike-price'] ?? '25';
    $truckTitle = $overrides['truck-title'] ?? 'Old Truck';
    $truckExpiration = $overrides['truck-expiration'] ?? '1719763200';
    $bikeCategories = $overrides['bike-categories'] ?? '<category domain="advert_category" nicename="general"><![CDATA[General]]></category>
  <category domain="advert_category" nicename="uams-property-reallocation"><![CDATA[UAMS Property Reallocation]]></category>';

    // Attachments 9001 to 9015, each at its own URL
    $attachments = '';

    foreach (\range(9001, 9015) as $id) {
        $parent = $id <= 9012 ? 101 : 103;
        $url = advertImageUrl($id);
        $attachments .= <<<XML
  <item>
    <title>image-{$id}</title>
    <link>https://example.test/classifieds/image-{$id}/</link>
    <wp:post_id>{$id}</wp:post_id>
    <wp:post_parent>{$parent}</wp:post_parent>
    <wp:status>inherit</wp:status>
    <wp:post_type>attachment</wp:post_type>
    <wp:attachment_url>{$url}</wp:attachment_url>
  </item>

XML;
    }

    $bikeOrder = \json_encode(\range(9001, 9012), JSON_THROW_ON_ERROR);

    $path = TemporaryFileRegistry::create('advert-import');

    \file_put_contents($path, <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"
  xmlns:content="http://purl.org/rss/1.0/modules/content/"
  xmlns:wp="http://wordpress.org/export/1.2/">
<channel>
<title>Classifieds</title>
<link>https://example.test/classifieds</link>
<wp:base_blog_url>https://example.test/classifieds</wp:base_blog_url>
<wp:term><wp:term_id>44</wp:term_id><wp:term_taxonomy>advert_category</wp:term_taxonomy><wp:term_slug>general</wp:term_slug><wp:term_name><![CDATA[General]]></wp:term_name></wp:term>
<wp:term><wp:term_id>48</wp:term_id><wp:term_taxonomy>advert_category</wp:term_taxonomy><wp:term_slug>uams-property-reallocation</wp:term_slug><wp:term_name><![CDATA[UAMS Property Reallocation]]></wp:term_name><wp:term_description><![CDATA[Items purchased with UAMS funds, offered to other departments.
  ]]></wp:term_description></wp:term>
<wp:term><wp:term_id>26</wp:term_id><wp:term_taxonomy>advert_category</wp:term_taxonomy><wp:term_slug>auto-marine</wp:term_slug><wp:term_name><![CDATA[Auto &amp; Marine]]></wp:term_name></wp:term>
<wp:term><wp:term_id>40</wp:term_id><wp:term_taxonomy>advert_category</wp:term_taxonomy><wp:term_slug>wanted</wp:term_slug><wp:term_name><![CDATA[Wanted ]]></wp:term_name></wp:term>
<wp:term><wp:term_id>41</wp:term_id><wp:term_taxonomy>advert_category</wp:term_taxonomy><wp:term_slug>kids-stuff</wp:term_slug><wp:term_name><![CDATA[Kids&#039; Stuff]]></wp:term_name></wp:term>
<item>
  <title>Bike &amp;amp; Helmet</title>
  <link>https://example.test/classifieds/advert/bike-for-sale/</link>
  <content:encoded><![CDATA[<p>Gently used.</p>]]></content:encoded>
  <wp:post_id>101</wp:post_id>
  <wp:post_name>bike-for-sale</wp:post_name>
  <wp:post_date>2025-02-14 09:00:00</wp:post_date>
  <wp:post_modified>2025-02-20 10:30:00</wp:post_modified>
  <wp:status>publish</wp:status>
  <wp:post_type>advert</wp:post_type>
  {$bikeCategories}
  <wp:postmeta><wp:meta_key>adverts_person</wp:meta_key><wp:meta_value><![CDATA[Pat Poster]]></wp:meta_value></wp:postmeta>
  <wp:postmeta><wp:meta_key>adverts_email</wp:meta_key><wp:meta_value><![CDATA[poster@example.test]]></wp:meta_value></wp:postmeta>
  <wp:postmeta><wp:meta_key>adverts_phone</wp:meta_key><wp:meta_value><![CDATA[501-555-0100]]></wp:meta_value></wp:postmeta>
  <wp:postmeta><wp:meta_key>adverts_price</wp:meta_key><wp:meta_value><![CDATA[{$price}]]></wp:meta_value></wp:postmeta>
  <wp:postmeta><wp:meta_key>adverts_location</wp:meta_key><wp:meta_value><![CDATA[Little Rock]]></wp:meta_value></wp:postmeta>
  <wp:postmeta><wp:meta_key>_expiration_date</wp:meta_key><wp:meta_value><![CDATA[1742180400]]></wp:meta_value></wp:postmeta>
  <wp:postmeta><wp:meta_key>_adverts_account</wp:meta_key><wp:meta_value><![CDATA[]]></wp:meta_value></wp:postmeta>
  <wp:postmeta><wp:meta_key>_thumbnail_id</wp:meta_key><wp:meta_value><![CDATA[9001]]></wp:meta_value></wp:postmeta>
  <wp:postmeta><wp:meta_key>_adverts_attachments_order</wp:meta_key><wp:meta_value><![CDATA[{$bikeOrder}]]></wp:meta_value></wp:postmeta>
</item>
<item>
  <title>{$truckTitle}</title>
  <link>https://example.test/classifieds/advert/old-truck/</link>
  <content:encoded><![CDATA[<p>Runs.</p>]]></content:encoded>
  <wp:post_id>102</wp:post_id>
  <wp:post_name>old-truck</wp:post_name>
  <wp:post_date>2024-06-01 12:00:00</wp:post_date>
  <wp:status>expired</wp:status>
  <wp:post_type>advert</wp:post_type>
  <category domain="advert_category" nicename="auto-marine"><![CDATA[Auto &amp; Marine]]></category>
  <wp:postmeta><wp:meta_key>adverts_person</wp:meta_key><wp:meta_value><![CDATA[Dana Driver]]></wp:meta_value></wp:postmeta>
  <wp:postmeta><wp:meta_key>adverts_email</wp:meta_key><wp:meta_value><![CDATA[driver@example.test]]></wp:meta_value></wp:postmeta>
  <wp:postmeta><wp:meta_key>_expiration_date</wp:meta_key><wp:meta_value><![CDATA[{$truckExpiration}]]></wp:meta_value></wp:postmeta>
</item>
<item>
  <title>Unfinished Post</title>
  <link>https://example.test/classifieds/advert/unfinished-post/</link>
  <content:encoded><![CDATA[<p>Draft.</p>]]></content:encoded>
  <wp:post_id>103</wp:post_id>
  <wp:post_name>unfinished-post</wp:post_name>
  <wp:post_date>2025-05-01 08:00:00</wp:post_date>
  <wp:status>draft</wp:status>
  <wp:post_type>advert</wp:post_type>
  <category domain="advert_category" nicename="no-such-category"><![CDATA[No Such Category]]></category>
  <category domain="advert_category" nicename="general"><![CDATA[General]]></category>
  <wp:postmeta><wp:meta_key>adverts_person</wp:meta_key><wp:meta_value><![CDATA[Sam Seller]]></wp:meta_value></wp:postmeta>
  <wp:postmeta><wp:meta_key>adverts_email</wp:meta_key><wp:meta_value><![CDATA[seller@example.test]]></wp:meta_value></wp:postmeta>
  <wp:postmeta><wp:meta_key>_expiration_date</wp:meta_key><wp:meta_value><![CDATA[1748736000]]></wp:meta_value></wp:postmeta>
  <wp:postmeta><wp:meta_key>_thumbnail_id</wp:meta_key><wp:meta_value><![CDATA[9013]]></wp:meta_value></wp:postmeta>
  <wp:postmeta><wp:meta_key>_adverts_attachments_order</wp:meta_key><wp:meta_value><![CDATA[[9014,9013,9999,9015]]]></wp:meta_value></wp:postmeta>
</item>
{$attachments}</channel></rss>
XML);

    return $path;
}

/**
 * A body converter that records the title each listing was converted under.
 *
 * The Bard conversion is `ContentProcessor`'s own and is tested elsewhere; the real
 * class cannot be built outside the host app. This returns a fixed body and keeps
 * the title argument, which is what the creator's placeholder title is passed as.
 */
final class AdvertBodyConverter extends ContentProcessor
{
    /**
     * The titles each call was made with, in order.
     *
     * @var list<string>
     */
    public array $titles = [];

    /**
     * The Bard body every call returns.
     *
     * @var list<array<string, mixed>>
     */
    public array $body = [];

    /**
     * Build the converter without running `ContentProcessor`'s constructor.
     *
     * That constructor builds a sanitizer from the host app's own namespace, which
     * does not exist under Testbench, and nothing this stub does needs it.
     *
     * @param  list<array<string, mixed>>  $body  The Bard body to return
     * @return self The converter
     */
    public static function returning(array $body): self
    {
        $converter = (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $converter->body = $body;

        return $converter;
    }

    /**
     * Record the title and return the fixed body.
     *
     * @param  string  $content  The listing's HTML
     * @param  string  $site  The site handle
     * @param  AssetImporter  $assetImporter  The asset importer
     * @param  OutputInterface  $output  Console output
     * @param  XmlParser  $xmlParser  The parser
     * @param  string  $pageTitle  The listing title, or its placeholder
     * @param  string  $pageId  The WordPress post id
     * @param  string  $pageLink  The listing URL
     * @param  array<int|string, mixed>  $attachments  Attachment elements
     * @param  array<int|string, mixed>  $terms  Term elements
     * @param  array<int|string, mixed>  $items  Items
     * @param  array<int|string, mixed>  $data  The entry data so far
     * @param  string  $collectionHandle  The collection handle
     * @param  ?string  $pageSlug  The entry slug
     * @param  ?string  $statamicEntryId  The entry id
     * @return array{content: mixed, page_builder: array<int|string, mixed>} The fixed body
     */
    public function process(
        string $content,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        XmlParser $xmlParser,
        string $pageTitle = '',
        string $pageId = '',
        string $pageLink = '',
        array $attachments = [],
        array $terms = [],
        array $items = [],
        array $data = [],
        string $collectionHandle = '',
        ?string $pageSlug = null,
        ?string $statamicEntryId = null
    ): array {
        $this->titles[] = $pageTitle;

        return ['content' => $this->body, 'page_builder' => []];
    }
}

/**
 * An asset importer with uploads enabled that returns an empty path for chosen URLs.
 *
 * Every other URL comes back unchanged, as the uploads-disabled path would return it.
 */
final class AdvertEmptyPathImporter extends AssetImporter
{
    /**
     * Create the importer.
     *
     * @param  list<string>  $emptyFor  The URLs to return an empty path for
     */
    public function __construct(
        private readonly array $emptyFor
    ) {
        config(['wordpress-import.upload_images' => true, 'wordpress-import.upload_files' => true]);

        parent::__construct();
    }

    /**
     * Return an empty path for a chosen URL, and the URL itself otherwise.
     *
     * @param  string  $url  The file URL
     * @param  string  $site  The site handle
     * @param  OutputInterface  $output  Console output
     * @param  string  $container  The asset container
     * @param  SameSitePolicy  $sameSitePolicy  The same-site policy
     * @param  ?string  $pageUrl  The page URL
     * @param  ?string  $title  The file title
     * @param  ?array<int|string, mixed>  $additionalFileMetadata  Extra metadata
     * @param  ?string  $attachmentPageUrl  The attachment page URL
     * @return string The path
     */
    public function importFile(
        string $url,
        string $site,
        OutputInterface $output,
        string $container = 'files',
        SameSitePolicy $sameSitePolicy = SameSitePolicy::SKIP_DIFFERENT_SITE,
        ?string $pageUrl = null,
        ?string $title = null,
        ?array $additionalFileMetadata = null,
        ?string $attachmentPageUrl = null
    ): string {
        return \in_array($url, $this->emptyFor, true) ? '' : $url;
    }
}

it('reports progress through the listings, leaving the per-listing lines as they were (#103)', function (): void {
    $run = advertImportRun();

    $progress = \array_values(\array_filter(
        \explode(\PHP_EOL, $run['output']),
        static fn (string $line): bool => \preg_match('/^Progress: advert \d/', $line) === 1
    ));

    expect($progress)->toBe([
        'Progress: advert 0 of 3 (0%)',
        'Progress: advert 1 of 3 (33%)',
        'Progress: advert 2 of 3 (66%)',
        'Progress: advert 3 of 3 (100%)',
    ])->and($run['output'])->toContain("Processing advert '".advertSlug('bike-for-sale')."'...");
});

it('reports progress through the category term passes, counting only advert categories (#182)', function (): void {
    // A term of another taxonomy, which the finishing pass leaves out of its total
    $export = advertExport();
    $xml = (string) \file_get_contents($export);
    // Insert it once, before the first item
    $patched = \preg_replace(
        '/<item>/',
        '<wp:term><wp:term_id>90</wp:term_id><wp:term_taxonomy>category</wp:term_taxonomy><wp:term_slug>news</wp:term_slug><wp:term_name><![CDATA[News]]></wp:term_name></wp:term>'."\n<item>",
        $xml,
        1,
        $replaced
    );

    if (! \is_string($patched) || $replaced === 0) {
        throw new RuntimeException('The export carries no item to insert the term before.');
    }

    \file_put_contents($export, $patched);

    $output = advertImportRun($export)['output'];

    expect($output)->toContain('Progress: classified_categories terms checked 0 of 5 (0%)'.\PHP_EOL)
        ->and($output)->toContain('Progress: classified_categories terms created 0 of 5 (0%)'.\PHP_EOL)
        ->and($output)->toContain('Progress: advert category terms finished 0 of 5 (0%)'.\PHP_EOL)
        ->and($output)->toContain('Progress: advert category terms finished 5 of 5 (100%)'.\PHP_EOL);
});
