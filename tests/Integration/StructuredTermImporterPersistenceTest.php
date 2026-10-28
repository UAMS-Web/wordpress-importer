<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Entries\Entry;
use Statamic\Events\CollectionTreeSaved;
use Statamic\Events\EntrySaved;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Site;
use Statamic\Facades\Stache;
use Statamic\Stache\Query\EntryQueryBuilder;
use Statamic\Stache\Stores\Store;
use Statamic\Structures\CollectionStructure;
use Statamic\Structures\CollectionTree;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\StructuredTermImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\TermDestinationRegistry;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\CollectionStructureManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\SiteManager;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * Tests the writes StructuredTermImporter makes against a real content store.
 *
 * `StructuredTermImporterTest` stubs every write so it can assert the parse, the
 * parent resolution and the tree shape. This file runs the same importer over a
 * real collection: the entry it creates or updates, the parent it stamps onto a
 * child, the tree it writes onto the collection's structure, and the two
 * collaborators it asks to enable the destination for the site.
 *
 * @command  php vendor/bin/pest tests/Integration/StructuredTermImporterPersistenceTest.php
 */
uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

beforeEach(function (): void {
    TermDestinationRegistry::clear();
    StructuredTermImporter::reset();

    // Multisite has to be on, or Statamic reports only the default site and the
    // second site has no structure tree.
    config()->set('statamic.system.multisite', true);

    Site::setSites([
        'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
        'news' => ['name' => 'News', 'locale' => 'en_US', 'url' => '/news/'],
    ]);

    // The destination the importer writes into, with a structure to hold the
    // tree, enabled for both sites.
    $collection = CollectionFacade::make('post_categories');
    $collection->sites(['default', 'news']);
    $collection->structure((new CollectionStructure)->maxDepth(null));
    $collection->save();

    // Record the two site claims rather than letting them rewrite the collection.
    app()->instance(SiteManager::class, new StructuredTermRecordingSiteManager);
    app()->instance(CollectionStructureManager::class, new StructuredTermRecordingStructureManager);
});

afterEach(function (): void {
    TermDestinationRegistry::clear();
    StructuredTermImporter::reset();
    Mockery::close();
});

/**
 * A site manager that records each collection it is asked to enable.
 */
final class StructuredTermRecordingSiteManager extends SiteManager
{
    /**
     * @var list<array{site: string, collection: string, output: ?OutputInterface}> Each call, in order
     */
    public array $calls = [];

    /**
     * Record the call rather than changing the collection.
     *
     * @param  string  $site  The site handle
     * @param  string  $collectionHandle  The collection handle
     * @param  ?OutputInterface  $output  Console output
     * @return bool Always true
     */
    public function enableCollectionForSite(
        string $site,
        string $collectionHandle,
        ?OutputInterface $output = null
    ): bool {
        $this->calls[] = ['site' => $site, 'collection' => $collectionHandle, 'output' => $output];

        return true;
    }
}

/**
 * A structure manager that records each structure it is asked to initialize.
 */
final class StructuredTermRecordingStructureManager extends CollectionStructureManager
{
    /**
     * @var list<array{collection: string, site: string, output: OutputInterface}> Each call, in order
     */
    public array $calls = [];

    /**
     * Record the call rather than changing the structure.
     *
     * @param  string  $collectionHandle  The collection handle
     * @param  string  $site  The site handle
     * @param  OutputInterface  $output  Console output
     */
    public function initializeStructureForSite(
        string $collectionHandle,
        string $site,
        OutputInterface $output
    ): void {
        $this->calls[] = ['collection' => $collectionHandle, 'site' => $site, 'output' => $output];
    }
}

/**
 * An importer whose protected writes can be called one at a time.
 */
final class StructuredTermPersistenceImporter extends StructuredTermImporter
{
    /**
     * Create or update a term's entry.
     *
     * @param  string  $slug  The term slug
     * @param  string  $title  The term name
     * @param  string  $collectionHandle  The destination collection handle
     * @param  string  $site  The Statamic site handle
     * @return ?string The entry id, or null when it could not be saved
     */
    public function entryFor(
        string $slug,
        string $title,
        string $collectionHandle,
        string $site
    ): ?string {
        return $this->createOrUpdateEntry($slug, $title, $collectionHandle, $site);
    }

    /**
     * Find a term's existing entry.
     *
     * @param  string  $slug  The term slug
     * @param  string  $collectionHandle  The destination collection handle
     * @param  string  $site  The Statamic site handle
     * @return ?Entry The existing entry, or null
     */
    public function existingEntry(
        string $slug,
        string $collectionHandle,
        string $site
    ): ?Entry {
        return $this->findExistingEntry($slug, $collectionHandle, $site);
    }

    /**
     * Write one entry's parent.
     *
     * @param  string  $entryId  The child entry id
     * @param  string  $parentEntryId  The parent entry id
     */
    public function parentFor(
        string $entryId,
        string $parentEntryId
    ): void {
        $this->setEntryParent($entryId, $parentEntryId);
    }

    /**
     * Write a tree onto a collection's structure.
     *
     * @param  string  $collectionHandle  The destination collection handle
     * @param  string  $site  The Statamic site handle
     * @param  array<int|string, array{entry: string, parent: string, title: string}>  $nodes  Flat nodes keyed by term id
     * @param  OutputInterface  $output  Console output
     */
    public function treeFor(
        string $collectionHandle,
        string $site,
        array $nodes,
        OutputInterface $output
    ): void {
        $this->saveTree($collectionHandle, $site, $nodes, $output);
    }
}

/**
 * Build a `<wp:term>` element in the shape the WXR export uses.
 *
 * @param  string  $termId  The WordPress term id
 * @param  string  $slug  The term slug
 * @param  string  $name  The term name
 * @param  string  $parentSlug  The parent term's slug
 * @return SimpleXMLElement The term element
 */
function persistedStructuredTerm(
    string $termId,
    string $slug,
    string $name,
    string $parentSlug = ''
): SimpleXMLElement {
    $term = new SimpleXMLElement('<wp:term xmlns:wp="http://wordpress.org/export/1.2/"></wp:term>');

    $term->addChild('wp:term_id', $termId);
    $term->addChild('wp:term_slug', $slug);
    $term->addChild('wp:term_name', $name);
    $term->addChild('wp:term_parent', $parentSlug);

    return $term;
}

/**
 * Read a saved entry back from the store.
 *
 * @param  ?string  $entryId  The entry id
 * @return Entry The entry
 */
function persistedStructuredEntry(
    ?string $entryId
): Entry {
    $entry = $entryId === null ? null : EntryFacade::find($entryId);

    if (! $entry instanceof Entry) {
        throw new RuntimeException("No entry was saved under '{$entryId}'.");
    }

    return $entry;
}

/**
 * Read the tree the destination collection holds for a site.
 *
 * @param  string  $site  The Statamic site handle
 * @return array<mixed> The tree
 */
function persistedStructuredTree(
    string $site
): array {
    $structure = CollectionFacade::findByHandle('post_categories')?->structure();
    $tree = $structure instanceof CollectionStructure ? $structure->in($site) : null;

    if (! $tree instanceof CollectionTree) {
        throw new RuntimeException("The collection has no tree for '{$site}'.");
    }

    $nested = $tree->tree();

    if (! \is_array($nested)) {
        throw new RuntimeException("The tree for '{$site}' is not a list.");
    }

    return $nested;
}

/**
 * Build an entry query whose first result is fixed, filtering nothing.
 *
 * @param  mixed  $first  What `first()` returns
 * @return EntryQueryBuilder The query
 */
function persistedStructuredQuery(
    mixed $first
): EntryQueryBuilder {
    $store = Stache::store('entries');

    if (! $store instanceof Store) {
        throw new RuntimeException('The Stache has no entries store.');
    }

    return new class($store, $first) extends EntryQueryBuilder
    {
        /**
         * Keep the fixed result beside the real store.
         *
         * @param  Store  $store  The entries store
         * @param  mixed  $result  What `first()` returns
         */
        public function __construct(
            Store $store,
            private readonly mixed $result
        ) {
            parent::__construct($store);
        }

        /**
         * Return the fixed result.
         *
         * @return mixed The fixed result
         */
        public function first(): mixed
        {
            return $this->result;
        }
    };
}

it('creates a published entry for a term in the collection and site', function (): void {
    $entryId = (new StructuredTermPersistenceImporter)->entryFor('health', 'Health', 'post_categories', 'news');

    $entry = persistedStructuredEntry($entryId);

    expect($entry->collectionHandle())->toBe('post_categories')
        ->and($entry->locale())->toBe('news')
        ->and($entry->slug())->toBe('health')
        ->and($entry->published())->toBeTrue()
        ->and($entry->get('title'))->toBe('Health');
});

it('updates the existing entry rather than creating a second', function (): void {
    $importer = new StructuredTermPersistenceImporter;

    $first = $importer->entryFor('health', 'Health', 'post_categories', 'default');
    $second = $importer->entryFor('health', 'Health and Wellness', 'post_categories', 'default');

    expect($second)->toBe($first)
        ->and(persistedStructuredEntry($second)->get('title'))->toBe('Health and Wellness')
        ->and(EntryFacade::whereCollection('post_categories'))->toHaveCount(1);
});

it('finds an existing entry only by its collection, site and slug', function (): void {
    $other = CollectionFacade::make('other');
    $other->sites(['default', 'news']);
    $other->save();

    $importer = new StructuredTermPersistenceImporter;
    $importer->entryFor('health', 'Health', 'other', 'default');
    $importer->entryFor('health', 'Health', 'post_categories', 'news');
    $importer->entryFor('wellness', 'Wellness', 'post_categories', 'default');

    expect($importer->existingEntry('health', 'post_categories', 'default'))->toBeNull();

    $match = $importer->entryFor('health', 'Health', 'post_categories', 'default');

    expect($importer->existingEntry('health', 'post_categories', 'default')?->id())->toBe($match);
});

it('logs and passes over a found entry that is not the concrete class', function (): void {
    EntryFacade::shouldReceive('query')->andReturn(persistedStructuredQuery(Mockery::mock(EntryContract::class)));
    $log = Log::spy();

    $found = (new StructuredTermPersistenceImporter)->existingEntry('health', 'post_categories', 'default');

    expect($found)->toBeNull();

    $log->shouldHaveReceived('debug', [
        'StructuredTermImporter::findExistingEntry: entry is not the concrete Statamic entry class.',
        [
            'collection' => 'post_categories',
            'site' => 'default',
            'slug' => 'health',
        ],
    ]);
});

it('logs nothing when no entry is found', function (): void {
    $log = Log::spy();

    $found = (new StructuredTermPersistenceImporter)->existingEntry('health', 'post_categories', 'default');

    expect($found)->toBeNull();

    $log->shouldNotHaveReceived('debug');
});

it('logs and returns no id when an entry cannot be made', function (): void {
    EntryFacade::shouldReceive('query')->andReturn(persistedStructuredQuery(null));
    EntryFacade::shouldReceive('make')->andReturn(Mockery::mock(EntryContract::class));
    $log = Log::spy();

    $entryId = (new StructuredTermPersistenceImporter)->entryFor('health', 'Health', 'post_categories', 'default');

    expect($entryId)->toBeNull();

    $log->shouldHaveReceived('error', [
        'StructuredTermImporter::createOrUpdateEntry: failed to instantiate a Statamic entry.',
        [
            'collection' => 'post_categories',
            'site' => 'default',
            'slug' => 'health',
        ],
    ]);
});

it('writes the parent entry id onto the child entry', function (): void {
    $importer = new StructuredTermPersistenceImporter;
    $parent = (string) $importer->entryFor('health', 'Health', 'post_categories', 'default');
    $child = (string) $importer->entryFor('cardiology', 'Cardiology', 'post_categories', 'default');

    Event::fake([EntrySaved::class]);

    $importer->parentFor($child, $parent);

    expect(persistedStructuredEntry($child)->get('parent'))->toBe($parent);

    Event::assertDispatched(
        EntrySaved::class,
        static fn (EntrySaved $event): bool => $event->entry instanceof Entry && $event->entry->id() === $child
    );
});

it('does not save a child whose parent is already recorded', function (): void {
    $importer = new StructuredTermPersistenceImporter;
    $parent = (string) $importer->entryFor('health', 'Health', 'post_categories', 'default');
    $child = (string) $importer->entryFor('cardiology', 'Cardiology', 'post_categories', 'default');
    $importer->parentFor($child, $parent);

    Event::fake([EntrySaved::class]);

    $importer->parentFor($child, $parent);

    Event::assertNotDispatched(EntrySaved::class);
});

it('does nothing for a child entry that does not exist', function (): void {
    Event::fake([EntrySaved::class]);

    (new StructuredTermPersistenceImporter)->parentFor('missing-entry', 'parent-entry');

    Event::assertNotDispatched(EntrySaved::class);
});

it('writes the nested tree onto the collection structure for the site', function (): void {
    $importer = new StructuredTermPersistenceImporter;
    $parent = (string) $importer->entryFor('health', 'Health', 'post_categories', 'news');
    $child = (string) $importer->entryFor('cardiology', 'Cardiology', 'post_categories', 'news');
    $output = new BufferedOutput;

    Event::fake([CollectionTreeSaved::class]);

    $importer->treeFor('post_categories', 'news', [
        '10' => ['entry' => $parent, 'parent' => '', 'title' => 'Health'],
        '20' => ['entry' => $child, 'parent' => '10', 'title' => 'Cardiology'],
    ], $output);

    expect(persistedStructuredTree('news'))->toBe([
        ['entry' => $parent, 'children' => [['entry' => $child]]],
    ])
        ->and(persistedStructuredTree('default'))->toBe([])
        ->and($output->fetch())->toBe('');

    Event::assertDispatched(CollectionTreeSaved::class);
});

it('reports a destination with no structure and writes no tree', function (): void {
    $flat = CollectionFacade::make('flat_categories');
    $flat->sites(['default']);
    $flat->save();

    $output = new BufferedOutput;

    (new StructuredTermPersistenceImporter)->treeFor('flat_categories', 'default', [
        '10' => ['entry' => 'entry-health', 'parent' => '', 'title' => 'Health'],
    ], $output);

    expect($output->fetch())->toBe(
        "  - Collection 'flat_categories' has no structure; term hierarchy not written.".\PHP_EOL
    );
});

it('reports a destination that does not exist', function (): void {
    $output = new BufferedOutput;

    (new StructuredTermPersistenceImporter)->treeFor('missing_categories', 'default', [
        '10' => ['entry' => 'entry-health', 'parent' => '', 'title' => 'Health'],
    ], $output);

    expect($output->fetch())->toBe(
        "  - Collection 'missing_categories' has no structure; term hierarchy not written.".\PHP_EOL
    );
});

it('imports terms as entries with their parents and tree', function (): void {
    $output = new BufferedOutput;

    $imported = (new StructuredTermImporter)->import(
        [
            persistedStructuredTerm('10', 'health', 'Health'),
            persistedStructuredTerm('20', 'cardiology', 'Cardiology', 'health'),
        ],
        'category',
        'post_categories',
        'default',
        $output
    );

    $health = (new StructuredTermPersistenceImporter)->existingEntry('health', 'post_categories', 'default');
    $cardiology = (new StructuredTermPersistenceImporter)->existingEntry('cardiology', 'post_categories', 'default');

    expect($imported)->toBe(2)
        ->and($health)->not->toBeNull()
        ->and($cardiology?->get('parent'))->toBe($health?->id())
        ->and(persistedStructuredTree('default'))->toBe([
            ['entry' => $health?->id(), 'children' => [['entry' => $cardiology?->id()]]],
        ]);
});

it('asks for the collection and its structure to be enabled for the site', function (): void {
    $output = new BufferedOutput;

    (new StructuredTermImporter)->import(
        [persistedStructuredTerm('10', 'health', 'Health')],
        'category',
        'post_categories',
        'news',
        $output
    );

    $sites = app(SiteManager::class);
    $structures = app(CollectionStructureManager::class);

    if (! $sites instanceof StructuredTermRecordingSiteManager || ! $structures instanceof StructuredTermRecordingStructureManager) {
        throw new RuntimeException('The recording managers are not bound.');
    }

    expect($sites->calls)->toBe([['site' => 'news', 'collection' => 'post_categories', 'output' => $output]])
        ->and($structures->calls)->toBe([['collection' => 'post_categories', 'site' => 'news', 'output' => $output]]);
});
