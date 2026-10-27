<?php

declare(strict_types=1);

/**
 * Tests that re-importing a flat taxonomy acts on the terms that already exist
 * (#188): `Overwrite matching terms` updates a term whose title or blueprint
 * differs, and `Create an additional term` creates a suffixed term beside it.
 *
 * The term pass is protected, so a probe subclass runs it. The terms the first
 * import saves are the ones the second import's preload reads back, so the
 * taxonomy is kept in a directory of the test's own: the pass rebuilds the term
 * store from disk after it saves.
 *
 * @command  php vendor/bin/pest tests/Integration/FlatTermReimportTest.php
 */

use Illuminate\Support\Facades\File;
use Statamic\Facades\Site;
use Statamic\Facades\Stache;
use Statamic\Facades\Taxonomy as TaxonomyFacade;
use Statamic\Facades\Term as TermFacade;
use Statamic\Stache\Stores\AggregateStore;
use Statamic\Stache\Stores\Store;
use Statamic\Taxonomies\LocalizedTerm;
use Statamic\Taxonomies\Taxonomy;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators\KbEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\EntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings\Selector as MinervaKbSettingsSelector;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * A knowledge base creator whose flat term pass a test can run.
 */
final class FlatTermReimportProbe extends KbEntryCreator
{
    /**
     * Run the term pass for the given terms.
     *
     * @param  list<array{original: string, name: string}>  $terms
     */
    public function importTerms(array $terms, BufferedOutput $output): void
    {
        $siteSpecificData = [];

        foreach ($terms as $term) {
            $this->storeSiteSpecificTaxonomyData($siteSpecificData, $term['original'], $term['original'], $term['name']);
        }

        $this->createTaxonomyTermsFromStoredData($siteSpecificData, 'tags', 'default', $output);
    }
}

/**
 * Read a Stache store's directory, or point it at another one.
 */
function flatTermDirectory(string $store, ?string $directory = null): string
{
    $stacheStore = Stache::store($store);

    if (! $stacheStore instanceof Store) {
        throw new RuntimeException("No {$store} store.");
    }

    if ($directory !== null) {
        $stacheStore->directory($directory);
    }

    return $stacheStore->directory();
}

beforeEach(function (): void {
    Site::setSites(['default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/']]);
    BaseEntryCreator::clearTaxonomyCaches();

    // Keep the taxonomy and its terms in a directory of this test's own
    $this->taxonomyDirectories = [flatTermDirectory('taxonomies'), flatTermDirectory('terms')];
    $this->root = sys_get_temp_dir().'/flat-term-reimport-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->root);
    flatTermDirectory('taxonomies', $this->root);
    flatTermDirectory('terms', $this->root);

    $taxonomy = TaxonomyFacade::make('tags');

    if (! $taxonomy instanceof Taxonomy) {
        throw new RuntimeException('Expected a taxonomy.');
    }

    $taxonomy->sites(['default']);
    $taxonomy->save();
});

afterEach(function (): void {
    BaseEntryCreator::clearTaxonomyCaches();
    Mockery::close();

    [$taxonomies, $terms] = $this->taxonomyDirectories;
    flatTermDirectory('taxonomies', $taxonomies);
    flatTermDirectory('terms', $terms);

    $root = $this->root ?? null;

    if (is_string($root)) {
        File::deleteDirectory($root);
    }
});

/**
 * Import the terms with the given behavior, as a fresh run would, and return what
 * was written.
 *
 * @param  list<array{original: string, name: string}>  $terms
 */
function flatTermImport(array $terms, string $behavior = 'Overwrite matching terms'): string
{
    $entryCreator = Mockery::mock(EntryCreator::class);
    $selector = Mockery::mock(MinervaKbSettingsSelector::class);

    if (! $entryCreator instanceof EntryCreator || ! $selector instanceof MinervaKbSettingsSelector) {
        throw new RuntimeException('Could not build the creator collaborators.');
    }

    // Each run starts with the session caches a new process would have
    BaseEntryCreator::clearTaxonomyCaches();

    $probe = new FlatTermReimportProbe($entryCreator, $selector);
    $probe->setTaxonomyOverwriteBehavior($behavior);
    $output = new BufferedOutput;
    $probe->importTerms($terms, $output);

    return $output->fetch();
}

/**
 * The titles of the taxonomy's terms, by slug.
 *
 * @return array<string, mixed>
 */
function flatTermTitles(): array
{
    // Read the terms as a fresh run would, from what was saved
    $terms = Stache::store('terms');
    $store = $terms instanceof AggregateStore ? $terms->store('tags') : null;

    if (! $store instanceof Store) {
        throw new RuntimeException('No tags term store.');
    }

    $store->clear();
    $store->warm();

    $titles = [];

    foreach (TermFacade::whereTaxonomy('tags') as $term) {
        $slug = $term instanceof LocalizedTerm ? $term->slug() : null;

        if (is_string($slug)) {
            $titles[$slug] = $term->get('title');
        }
    }

    ksort($titles);

    return $titles;
}

/**
 * The saved term with the given slug.
 */
function flatTermNamed(string $slug): LocalizedTerm
{
    foreach (TermFacade::whereTaxonomy('tags') as $term) {
        if ($term instanceof LocalizedTerm && $term->slug() === $slug) {
            return $term;
        }
    }

    throw new RuntimeException("No term {$slug}.");
}

it('updates the existing terms whose title differs on a re-import', function (): void {
    flatTermImport([['original' => 'news', 'name' => 'News'], ['original' => 'events', 'name' => 'Events']]);

    $output = flatTermImport([['original' => 'news', 'name' => 'Latest News'], ['original' => 'events', 'name' => 'Upcoming Events']]);

    expect(flatTermTitles())->toBe(['default_events' => 'Default — Upcoming Events', 'default_news' => 'Default — Latest News'])
        ->and($output)->toContain('      - Updating existing taxonomy terms...'.PHP_EOL)
        ->and($output)->toContain('Progress: tags terms updated 2 of 2 (100%)'.PHP_EOL)
        ->and($output)->toContain('Updated taxonomy term: tags::default_news with title: Default — Latest News');
});

it('updates an existing term whose blueprint differs on a re-import', function (): void {
    flatTermImport([['original' => 'news', 'name' => 'News']]);
    $term = flatTermNamed('default_news');
    $term->set('blueprint', 'other');
    $term->save();

    $output = flatTermImport([['original' => 'news', 'name' => 'News']]);

    expect(flatTermNamed('default_news')->get('blueprint'))->toBe('tags')
        ->and($output)->toContain('Updated taxonomy term: tags::default_news with title: Default — News');
});

it('leaves an existing term that already matches', function (): void {
    flatTermImport([['original' => 'news', 'name' => 'News']]);

    $output = flatTermImport([['original' => 'news', 'name' => 'News']]);

    expect($output)->not->toContain('Updating existing taxonomy terms')
        ->and($output)->toContain('          - tags::default_news'.PHP_EOL);
});

it('creates a suffixed term beside an existing one', function (): void {
    flatTermImport([['original' => 'news', 'name' => 'News']]);

    $output = flatTermImport([['original' => 'news', 'name' => 'News']], 'Create an additional term');

    // Statamic's slug setter drops the dot of the importer's "default_news.1"
    expect(flatTermTitles())->toBe(['default_news' => 'Default — News', 'default_news1' => 'Default — News'])
        ->and($output)->toContain("Created 'tags::default_news.1'");
});

it('makes the same additional term again on a third run', function (): void {
    // Characterization of #193: the suffix it looks for is the importer's
    // dotted one, which Statamic never stores, so a later run builds the same
    // slug again instead of the next one
    flatTermImport([['original' => 'news', 'name' => 'News']]);
    flatTermImport([['original' => 'news', 'name' => 'News']], 'Create an additional term');

    $output = flatTermImport([['original' => 'news', 'name' => 'News']], 'Create an additional term');

    expect(flatTermTitles())->toBe(['default_news' => 'Default — News', 'default_news1' => 'Default — News'])
        ->and($output)->toContain("Created 'tags::default_news.1'");
});
