<?php

declare(strict_types=1);

/**
 * Tests the taxonomy-term half of `BaseEntryCreator`: the flat term pass and
 * the queued changes it saves, the paused-index wrapper and the term-store
 * rebuild, the pseudo terms built from categories and tags, the term pass at
 * the start of an import, the hierarchical slugs, saving a term safely,
 * creating a term directly, and the deferred term queue.
 *
 * A probe subclass exposes the protected and private steps. The term pass
 * rebuilds the term store from disk after it saves, so the taxonomy and its
 * terms are kept in a directory of the test's own, and are read back from it.
 *
 * @command  php vendor/bin/pest tests/Integration/BaseEntryCreatorTermsTest.php
 */

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Statamic\Events\TermSaving;
use Statamic\Facades\Site;
use Statamic\Facades\Stache;
use Statamic\Facades\Taxonomy as TaxonomyFacade;
use Statamic\Facades\Term as TermFacade;
use Statamic\Stache\Repositories\TermRepository;
use Statamic\Stache\Stache as StacheInstance;
use Statamic\Stache\Stores\AggregateStore;
use Statamic\Stache\Stores\Store;
use Statamic\Taxonomies\LocalizedTerm;
use Statamic\Taxonomies\Taxonomy;
use Statamic\Taxonomies\Term;
use Statamic\Taxonomies\TermCollection;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\StructuredTermImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\TermDestinationRegistry;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\StacheIndexUpdateToggle;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\TaxonomySlugProcessor;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * What the probe `BaseEntryCreator` adds, so the probe can be built inside a test.
 *
 * Building the probe as an anonymous class, the first time a test asks for one,
 * loads `BaseEntryCreator` under Laravel's error handler, which turns a compile
 * warning (such as a `continue` mutated into a switch) into an exception.
 */
interface BaseEntryTermsTestProbe
{
    /**
     * Call a protected or private method.
     *
     * @param  string  $method  The method
     * @param  mixed  ...$arguments  Its arguments
     * @return mixed What it returns
     */
    public function call(string $method, mixed ...$arguments): mixed;

    /**
     * Call `getFullTermSlug()`, returning the visited list it fills as well.
     *
     * @param  string  $termSlug  The slug
     * @param  array<int|string, mixed>  $termMap  The term map
     * @param  array<int|string, mixed>  $visited  The slugs visited so far
     * @return array{0: string, 1: array<int|string, mixed>} The slug, and the visited list
     */
    public function fullSlug(string $termSlug, array $termMap, array $visited = []): array;

    /**
     * Read a static property.
     *
     * @param  string  $property  The property
     * @return mixed Its value
     */
    public function read(string $property): mixed;

    /**
     * Write a static property.
     *
     * @param  string  $property  The property
     * @param  mixed  $value  Its new value
     */
    public function write(string $property, mixed $value): void;
}

/**
 * A parser answering the terms, categories and tags it was given.
 */
final class BaseEntryTermsTestParser extends XmlParser
{
    /**
     * Keep what each read answers.
     *
     * @param  array<int|string, mixed>  $terms  What getTerms() answers
     * @param  array<int|string, mixed>  $categories  What getCategories() answers
     * @param  array<int|string, mixed>  $tags  What getTags() answers
     */
    public function __construct(
        private readonly array $terms = [],
        private readonly array $categories = [],
        private readonly array $tags = []
    ) {}

    /**
     * The terms.
     *
     * @return array<int|string, mixed> The terms
     */
    public function getTerms(): array
    {
        return $this->terms;
    }

    /**
     * The categories.
     *
     * @return array<int|string, mixed> The categories
     */
    public function getCategories(): array
    {
        return $this->categories;
    }

    /**
     * The tags.
     *
     * @return array<int|string, mixed> The tags
     */
    public function getTags(): array
    {
        return $this->tags;
    }
}

/**
 * Build a probe `BaseEntryCreator`.
 *
 * @return BaseEntryCreator&BaseEntryTermsTestProbe The probe
 */
function termsProbe(): BaseEntryCreator&BaseEntryTermsTestProbe
{
    return new class extends BaseEntryCreator implements BaseEntryTermsTestProbe
    {
        /**
         * Create no entries.
         *
         * @param  string  $site  The site
         * @param  string  $collectionHandle  The collection
         * @param  XmlParser  $xmlParser  The parser
         * @param  AssetImporter  $assetImporter  The importer
         * @param  OutputInterface  $output  Console output
         * @param  MetadataMapper  $metadataMapper  The mapper
         * @param  ContentProcessor  $contentProcessor  The converter
         * @param  ?array<int|string, mixed>  $minervaKbSettings  The MinervaKB settings
         * @return array<int|string, mixed> No page data
         */
        public function createEntries(string $site, string $collectionHandle, XmlParser $xmlParser, AssetImporter $assetImporter, OutputInterface $output, MetadataMapper $metadataMapper, ContentProcessor $contentProcessor, ?array $minervaKbSettings = null): array
        {
            return [];
        }

        /**
         * No show-people shortcode.
         *
         * @return bool Always false
         */
        public function hasShowPeople(): bool
        {
            return false;
        }

        /**
         * No categories.
         *
         * @return array<int|string, mixed> Always empty
         */
        public function getPostCategories(): array
        {
            return [];
        }

        /**
         * No tags.
         *
         * @return array<int|string, mixed> Always empty
         */
        public function getPostTags(): array
        {
            return [];
        }

        /**
         * Validate nothing.
         *
         * @param  string  $collectionHandle  The collection
         * @param  string  $site  The site
         * @param  OutputInterface  $output  Console output
         */
        public function validateTaxonomies(string $collectionHandle, string $site, OutputInterface $output): void {}

        /**
         * Call a protected or private method.
         *
         * @param  string  $method  The method
         * @param  mixed  ...$arguments  Its arguments
         * @return mixed What it returns
         */
        public function call(string $method, mixed ...$arguments): mixed
        {
            $reflection = new ReflectionMethod(BaseEntryCreator::class, $method);

            return $reflection->invoke($reflection->isStatic() ? null : $this, ...$arguments);
        }

        /**
         * Call `getFullTermSlug()`, returning the visited list it fills as well.
         *
         * @param  string  $termSlug  The slug
         * @param  array<int|string, mixed>  $termMap  The term map
         * @param  array<int|string, mixed>  $visited  The slugs visited so far
         * @return array{0: string, 1: array<int|string, mixed>} The slug, and the visited list
         */
        public function fullSlug(string $termSlug, array $termMap, array $visited = []): array
        {
            $slug = $this->getFullTermSlug($termSlug, $termMap, $visited);

            return [$slug, $visited];
        }

        /**
         * Read a static property.
         *
         * @param  string  $property  The property
         * @return mixed Its value
         */
        public function read(string $property): mixed
        {
            return (new ReflectionProperty(BaseEntryCreator::class, $property))->getValue();
        }

        /**
         * Write a static property.
         *
         * @param  string  $property  The property
         * @param  mixed  $value  Its new value
         */
        public function write(string $property, mixed $value): void
        {
            (new ReflectionProperty(BaseEntryCreator::class, $property))->setValue(null, $value);
        }
    };
}

/**
 * Read a Stache store's directory, or point it at another one.
 *
 * @param  string  $store  The store
 * @param  ?string  $directory  The directory to point it at, or null to leave it
 * @return string The directory
 */
function termsDirectory(string $store, ?string $directory = null): string
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

/**
 * Save a term in the `probe_tax` taxonomy.
 *
 * @param  string  $slug  The slug
 * @param  string  $title  The title
 * @param  string  $blueprint  The blueprint
 * @return Term The term
 */
function termsSave(string $slug, string $title, string $blueprint = 'probe_tax'): Term
{
    $term = TermFacade::make();

    if (! $term instanceof Term) {
        throw new RuntimeException('Expected a term.');
    }

    $term->taxonomy('probe_tax');
    $term->slug($slug);
    $term->in('default');
    $term->set('title', $title);
    $term->set('blueprint', $blueprint);
    $term->save();

    return $term;
}

/**
 * The `probe_tax` terms, as a fresh run would read them from what was saved.
 *
 * @param  string  $field  The field to read from each
 * @return array<string, mixed> The field's values, keyed by term id
 */
function termsSaved(string $field = 'title'): array
{
    $terms = Stache::store('terms');
    $store = $terms instanceof AggregateStore ? $terms->store('probe_tax') : null;

    if (! $store instanceof Store) {
        throw new RuntimeException('No probe_tax term store.');
    }

    $store->clear();
    $store->warm();

    $values = [];

    foreach (TermFacade::whereTaxonomy('probe_tax') as $term) {
        $id = $term instanceof LocalizedTerm ? $term->id() : null;

        if (is_string($id)) {
            $values[$id] = $term->get($field);
        }
    }

    ksort($values);

    return $values;
}

/**
 * Build a `<wp:term>`, `<wp:category>` or `<wp:tag>` element.
 *
 * @param  array<string, string>  $children  Child values, keyed by local name
 * @param  string  $name  The element name
 * @return SimpleXMLElement The element
 */
function termsElement(array $children, string $name = 'term'): SimpleXMLElement
{
    $element = new SimpleXMLElement("<wp:{$name} xmlns:wp=\"http://wordpress.org/export/1.2/\"></wp:{$name}>");

    foreach ($children as $child => $value) {
        $element->addChild("wp:{$child}", $value);
    }

    return $element;
}

/**
 * Build a `<wp:term>` element.
 *
 * @param  string  $id  The term id
 * @param  string  $slug  The slug
 * @param  string  $name  The name
 * @param  string  $taxonomy  The taxonomy
 * @param  string  $parent  The parent slug
 * @return SimpleXMLElement The element
 */
function termsTerm(string $id, string $slug, string $name = '', string $taxonomy = 'probe_tax', string $parent = ''): SimpleXMLElement
{
    return termsElement(['term_id' => $id, 'term_taxonomy' => $taxonomy, 'term_slug' => $slug, 'term_name' => $name, 'term_parent' => $parent]);
}

/**
 * The slugs of pseudo terms.
 *
 * @param  mixed  $pseudoTerms  What a pseudo-term builder answered
 * @return list<string> The slugs
 */
function termsSlugs(mixed $pseudoTerms): array
{
    $slugs = [];

    foreach (is_array($pseudoTerms) ? $pseudoTerms : [] as $pseudoTerm) {
        $slugs[] = $pseudoTerm instanceof SimpleXMLElement ? (string) $pseudoTerm->children('wp', true)->term_slug : 'not an element';
    }

    return $slugs;
}

/**
 * Set whether `StacheIndexUpdateToggle` reports itself supported.
 *
 * @param  ?bool  $supported  The answer, or null to probe again
 */
function termsToggleSupported(?bool $supported): void
{
    (new ReflectionProperty(StacheIndexUpdateToggle::class, 'supported'))->setValue(null, $supported);
}

/**
 * Collect what is logged at each level.
 *
 * @return ArrayObject<int, array{0: string, 1: mixed, 2: mixed}> The level, message and context of each line, filled as they are logged
 */
function termsLog(): ArrayObject
{
    /** @var ArrayObject<int, array{0: string, 1: mixed, 2: mixed}> $logged */
    $logged = new ArrayObject;

    foreach (['warning', 'error', 'info', 'debug'] as $level) {
        Log::shouldReceive($level)->andReturnUsing(static function (mixed $message, mixed $context = []) use ($logged, $level): void {
            $logged->append([$level, $message, $context]);
        });
    }

    return $logged;
}

/**
 * Read the console output, with its lines trimmed and the progress lines left out.
 *
 * @param  BufferedOutput  $output  The output
 * @return list<string> The lines
 */
function termsLines(BufferedOutput $output): array
{
    $lines = array_map(trim(...), explode(PHP_EOL, $output->fetch()));

    return array_values(array_filter($lines, static fn (string $line): bool => $line !== '' && ! str_starts_with($line, 'Progress:')));
}

/**
 * Make every term save throw.
 *
 * @param  Throwable  $failure  What a save throws
 */
function termsFailSaves(Throwable $failure): void
{
    Event::listen(TermSaving::class, static function () use ($failure): never {
        throw $failure;
    });
}

/**
 * Answer the given values for the `probe_tax` preload.
 *
 * @param  list<mixed>  $preloaded  What `whereTaxonomy()` answers
 */
function termsPreload(array $preloaded): void
{
    TermFacade::swap(new class(app(StacheInstance::class), $preloaded) extends TermRepository
    {
        /**
         * Keep the preload to answer.
         *
         * @param  StacheInstance  $stache  The Stache
         * @param  list<mixed>  $preloaded  What `whereTaxonomy()` answers
         */
        public function __construct(StacheInstance $stache, private readonly array $preloaded)
        {
            parent::__construct($stache);
        }

        /**
         * Answer the preload.
         *
         * @param  string  $handle  The taxonomy
         * @return TermCollection The preload
         */
        public function whereTaxonomy(string $handle): TermCollection
        {
            return new TermCollection($this->preloaded);
        }
    });
}

/**
 * Answer a Stache whose term store is the given value, and whose index flag cannot be turned back on.
 *
 * @param  mixed  $termStore  What `store('terms')` answers
 */
function termsStache(mixed $termStore): void
{
    Stache::swap(new class(app(StacheInstance::class), $termStore)
    {
        /**
         * Keep the Stache to forward to, and the term store to answer.
         *
         * @param  StacheInstance  $stache  The Stache everything else goes to
         * @param  mixed  $termStore  What `store('terms')` answers
         */
        public function __construct(private readonly StacheInstance $stache, private readonly mixed $termStore) {}

        /**
         * Forward anything else to the Stache.
         *
         * @param  string  $method  The method
         * @param  array<int|string, mixed>  $arguments  Its arguments
         * @return mixed What the Stache answers
         */
        public function __call(string $method, array $arguments): mixed
        {
            $forward = [$this->stache, $method];

            if (! is_callable($forward)) {
                throw new BadMethodCallException("The Stache has no {$method}().");
            }

            return $forward(...$arguments);
        }

        /**
         * Answer the term store, or forward the rest.
         *
         * @param  string  $key  The store
         * @return mixed The term store
         */
        public function store(string $key): mixed
        {
            return $key === 'terms' ? $this->termStore : $this->stache->store($key);
        }

        /**
         * Pause nothing.
         */
        public function disableUpdatingIndexes(): void {}

        /**
         * Never update indexes.
         *
         * @return bool Always false
         */
        public function shouldUpdateIndexes(): bool
        {
            return false;
        }
    });
}

/**
 * A preloaded value with the given id, standing in for a term.
 *
 * @param  ?string  $id  The id
 * @return object The value
 */
function termsImpostor(?string $id): object
{
    return new class($id)
    {
        /**
         * Keep the id.
         *
         * @param  ?string  $id  The id
         */
        public function __construct(private readonly ?string $id) {}

        /**
         * The id.
         *
         * @return ?string The id
         */
        public function id(): ?string
        {
            return $this->id;
        }
    };
}

beforeEach(function (): void {
    config(['statamic.search.indexes' => []]);
    Site::setSites(['default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/']]);
    BaseEntryCreator::clearTaxonomyCaches();
    BaseEntryCreator::clearDeferredTermQueue();
    termsToggleSupported(null);
    $this->stache = app(StacheInstance::class);
    $this->termRepository = app(TermRepository::class);

    // Keep the taxonomy and its terms in a directory of this test's own
    $this->taxonomyDirectories = [termsDirectory('taxonomies'), termsDirectory('terms')];
    $this->root = sys_get_temp_dir().'/base-entry-terms-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->root);
    termsDirectory('taxonomies', $this->root);
    termsDirectory('terms', $this->root);

    $taxonomy = TaxonomyFacade::make('probe_tax');

    if (! $taxonomy instanceof Taxonomy) {
        throw new RuntimeException('Expected a taxonomy.');
    }

    $taxonomy->sites(['default']);
    $taxonomy->save();
});

afterEach(function (): void {
    BaseEntryCreator::clearTaxonomyCaches();
    BaseEntryCreator::clearDeferredTermQueue();
    termsToggleSupported(null);
    Stache::swap($this->stache);
    TermFacade::swap($this->termRepository);

    [$taxonomies, $terms] = $this->taxonomyDirectories;
    termsDirectory('taxonomies', $taxonomies);
    termsDirectory('terms', $terms);

    $root = $this->root ?? null;

    if (is_string($root)) {
        File::deleteDirectory($root);
    }
});

describe('the flat term pass', function (): void {
    it('does nothing for no terms, not even naming the site', function (): void {
        $probe = termsProbe();
        $output = new BufferedOutput;

        $probe->call('createTaxonomyTermsFromStoredData', [], 'probe_tax', 'nowhere', $output);

        expect($output->fetch())->toBe('')
            ->and($probe->read('siteNamesCache'))->toBe([]);
    });

    it('skips an entry that is not an array and creates the rest', function (): void {
        termsProbe()->call('createTaxonomyTermsFromStoredData', ['junk', ['original' => 'kept', 'name' => 'Kept']], 'probe_tax', 'default', new BufferedOutput);

        expect(termsSaved())->toBe(['probe_tax::default_kept' => 'Default — Kept']);
    });

    it('names a term from its slug when the entry carries no name', function (): void {
        termsProbe()->call('createTaxonomyTermsFromStoredData', [['original' => 'my-new_term']], 'probe_tax', 'default', new BufferedOutput);

        expect(termsSaved())->toBe(['probe_tax::default_my-new_term' => 'Default — My New Term']);
    });

    it('reads an entry with no slug as an empty slug', function (): void {
        termsProbe()->call('createTaxonomyTermsFromStoredData', [['name' => 'Nameless']], 'probe_tax', 'default', new BufferedOutput);

        $slug = TaxonomySlugProcessor::addSitePrefixToSlug('', 'default');

        expect(termsSaved())->toBe(["probe_tax::{$slug}" => 'Default — Nameless']);
    });

    it('passes over a term this run already processed and creates the rest', function (): void {
        $probe = termsProbe();
        $probe->write('processedTermsCache', ['probe_tax::default_done' => true]);

        $probe->call('createTaxonomyTermsFromStoredData', [['original' => 'done', 'name' => 'Done'], ['original' => 'fresh', 'name' => 'Fresh']], 'probe_tax', 'default', new BufferedOutput);

        expect(termsSaved())->toBe(['probe_tax::default_fresh' => 'Default — Fresh']);
    });

    it('queues a repeated slug once and creates the rest', function (): void {
        termsProbe()->call('createTaxonomyTermsFromStoredData', [['original' => 'twice', 'name' => 'First'], ['original' => 'twice', 'name' => 'Second'], ['original' => 'once', 'name' => 'Once']], 'probe_tax', 'default', new BufferedOutput);

        expect(termsSaved())->toBe([
            'probe_tax::default_once' => 'Default — Once',
            'probe_tax::default_twice' => 'Default — First',
        ]);
    });

    it('skips a matching term and creates the rest, under Skip matching terms', function (): void {
        termsSave('default_old', 'Old title');
        $probe = termsProbe();
        $probe->setTaxonomyOverwriteBehavior('Skip matching terms');
        $output = new BufferedOutput;

        $probe->call('createTaxonomyTermsFromStoredData', [['original' => 'old', 'name' => 'Old'], ['original' => 'new', 'name' => 'New']], 'probe_tax', 'default', $output);

        expect(termsLines($output))->toContain("- Skipped taxonomy term 'default_old' as it already exists.")
            ->and(termsSaved())->toBe([
                'probe_tax::default_new' => 'Default — New',
                'probe_tax::default_old' => 'Old title',
            ]);
    });

    it('names an additional term from its slug, under Create an additional term', function (): void {
        termsSave('default_my-old_term', 'Old title');
        $probe = termsProbe();
        $probe->setTaxonomyOverwriteBehavior('Create an additional term');

        $probe->call('createTaxonomyTermsFromStoredData', [['original' => 'my-old_term']], 'probe_tax', 'default', new BufferedOutput);

        expect(termsSaved())->toBe([
            'probe_tax::default_my-old_term' => 'Old title',
            // Statamic's slug setter drops the dot of the importer's ".1" (#193)
            'probe_tax::default_my-old_term1' => 'Default — My Old Term',
        ]);
    });

    it('retitles a matching term from its slug, under Overwrite matching terms', function (): void {
        termsSave('default_my-old_term', 'Old title');

        termsProbe()->call('createTaxonomyTermsFromStoredData', [['original' => 'my-old_term']], 'probe_tax', 'default', new BufferedOutput);

        expect(termsSaved())->toBe(['probe_tax::default_my-old_term' => 'Default — My Old Term']);
    });

    it('lists a matching term that needs no change', function (): void {
        termsSave('default_same', 'Default — Same');
        $output = new BufferedOutput;

        termsProbe()->call('createTaxonomyTermsFromStoredData', [['original' => 'same', 'name' => 'Same']], 'probe_tax', 'default', $output);

        expect(termsLines($output))->toBe(['- Taxonomy terms that already exist in Statamic:', '- probe_tax::default_same']);
    });

    it('passes over a preloaded value that is not a term, and one with no id', function (string $behavior): void {
        termsPreload([termsImpostor(null), termsImpostor('probe_tax::default_impostor')]);
        termsToggleSupported(false);
        $probe = termsProbe();
        $probe->setTaxonomyOverwriteBehavior($behavior);

        $probe->call('createTaxonomyTermsFromStoredData', [['original' => 'impostor', 'name' => 'Impostor'], ['original' => 'real', 'name' => 'Real']], 'probe_tax', 'default', new BufferedOutput);

        expect($probe->read('processedTermsCache'))->toBe(['probe_tax::default_real' => true]);
    })->with(['Create an additional term', 'Overwrite matching terms']);

    it('reads past a preloaded value with no id to the terms after it', function (): void {
        $kept = termsSave('default_kept', 'Kept title');
        termsPreload([termsImpostor(null), $kept->in('default')]);
        termsToggleSupported(false);
        $probe = termsProbe();
        $probe->setTaxonomyOverwriteBehavior('Skip matching terms');
        $output = new BufferedOutput;

        $probe->call('createTaxonomyTermsFromStoredData', [['original' => 'kept', 'name' => 'Kept']], 'probe_tax', 'default', $output);

        expect(termsLines($output))->toBe(["- Skipped taxonomy term 'default_kept' as it already exists."]);
    });
});

describe('saving the queued term changes', function (): void {
    it('passes over an update that is not one and saves the rest', function (): void {
        $term = termsSave('default_kept', 'Old title');
        $probe = termsProbe();

        $probe->call('persistQueuedTermChanges', ['junk', ['term' => $term, 'slug' => 'default_kept', 'title' => 'New title', 'cacheKey' => 'probe_tax::default_kept']], [], 'probe_tax', 'default', new BufferedOutput);

        expect(termsSaved())->toBe(['probe_tax::default_kept' => 'New title'])
            ->and($probe->read('processedTermsCache'))->toBe(['probe_tax::default_kept' => true]);
    });

    it('passes over a creation that is not one and saves the rest', function (): void {
        termsProbe()->call('persistQueuedTermChanges', [], ['junk', ['slug' => 'default_made', 'title' => 'Made', 'cacheKey' => 'probe_tax::default_made']], 'probe_tax', 'default', new BufferedOutput);

        expect(termsSaved())->toBe(['probe_tax::default_made' => 'Made']);
    });

    it('logs and creates nothing for a site that does not exist', function (): void {
        $logged = termsLog();

        termsProbe()->call('persistQueuedTermChanges', [], [['slug' => 'nowhere_made', 'title' => 'Made', 'cacheKey' => 'probe_tax::nowhere_made']], 'probe_tax', 'nowhere', new BufferedOutput);

        expect(termsSaved())->toBe([])
            ->and($logged->getArrayCopy())->toBe([['error', "BaseEntryCreator::createTaxonomyTermsFromStoredData - Site 'nowhere' does not exist", []]]);
    });
});

describe('pausing the index updates', function (): void {
    it('runs the work with indexing left on when the toggle is not supported', function (): void {
        termsToggleSupported(false);
        $ran = new ArrayObject;

        termsProbe()->call('withTermIndexUpdatesDisabled', 'probe_tax', static function () use ($ran): void {
            $ran->append('ran');
        });

        expect($ran->getArrayCopy())->toBe(['ran']);
    });

    it('logs when indexing cannot be turned back on', function (): void {
        termsToggleSupported(true);
        $logged = termsLog();
        termsStache(new stdClass);

        termsProbe()->call('withTermIndexUpdatesDisabled', 'probe_tax', static function (): void {});

        expect($logged->getArrayCopy())->toBe([['error', 'BaseEntryCreator: failed to re-enable Stache index updating after term import; the Stache may be stale until the next full warm.', []]]);
    });
});

describe('rebuilding the term store', function (): void {
    it('rebuilds nothing when the term store cannot name its stores', function (): void {
        $logged = termsLog();
        termsStache(new stdClass);

        termsProbe()->call('rebuildTermStoreFromDisk', 'probe_tax');

        expect($logged->getArrayCopy())->toBe([]);
    });

    it('logs a rebuild that fails', function (): void {
        $logged = termsLog();
        termsStache(new class
        {
            /**
             * Fail to find the store.
             *
             * @param  string  $handle  The taxonomy
             */
            public function store(string $handle): never
            {
                throw new RuntimeException("no {$handle} store");
            }
        });

        termsProbe()->call('rebuildTermStoreFromDisk', 'probe_tax');

        expect($logged->getArrayCopy())->toBe([['warning', "BaseEntryCreator: failed to rebuild term store 'probe_tax' after bulk term save: no probe_tax store", []]]);
    });
});

describe('the pseudo terms', function (): void {
    it('converts each category and passes over anything else', function (): void {
        $parser = new BaseEntryTermsTestParser(categories: ['junk', termsElement(['term_id' => '4', 'category_nicename' => 'news', 'cat_name' => 'News', 'category_parent' => ''], 'category')]);

        expect(termsSlugs(termsProbe()->call('categoryPseudoTerms', $parser)))->toBe(['news']);
    });

    it('converts each tag and passes over anything else', function (): void {
        $parser = new BaseEntryTermsTestParser(tags: ['junk', termsElement(['term_id' => '5', 'tag_slug' => 'blue', 'tag_name' => 'Blue'], 'tag')]);

        expect(termsSlugs(termsProbe()->call('tagPseudoTerms', $parser)))->toBe(['blue']);
    });
});

describe('the term pass at the start of an import', function (): void {
    it('says nothing when no taxonomy is asked for', function (): void {
        $output = new BufferedOutput;

        termsProbe()->call('importTaxonomyTermsAtBeginning', new BaseEntryTermsTestParser([termsTerm('1', 'a')]), [], 'default', $output);

        expect($output->fetch())->toBe('');
    });

    it('passes over a term that is not an element and imports the rest', function (): void {
        termsToggleSupported(false);

        termsProbe()->call('importTaxonomyTermsAtBeginning', new BaseEntryTermsTestParser(['junk', termsTerm('1', 'kept', 'Kept')]), ['probe_tax'], 'default', new BufferedOutput);

        expect(termsSaved())->toBe(['probe_tax::default_kept' => 'Default — Kept']);
    });

    it('passes over a category or tag that is not an element and groups the rest', function (string $taxonomy, array $categories, array $tags): void {
        // The structured importer records what it was handed instead of importing it
        $handed = new ArrayObject;
        app()->instance(StructuredTermImporter::class, new class($handed) extends StructuredTermImporter
        {
            /**
             * Keep a record of what is handed over.
             *
             * @param  ArrayObject<int, mixed>  $handed  The record
             */
            public function __construct(private readonly ArrayObject $handed) {}

            /**
             * Record the terms handed over.
             *
             * @param  array<int|string, mixed>  $terms  The terms
             * @param  string  $wordpressTaxonomy  The WordPress taxonomy
             * @param  string  $collectionHandle  The collection
             * @param  string  $site  The site
             * @param  OutputInterface  $output  Console output
             * @return int No entries imported
             */
            public function import(array $terms, string $wordpressTaxonomy, string $collectionHandle, string $site, OutputInterface $output): int
            {
                $this->handed->append([$wordpressTaxonomy, termsSlugs($terms)]);

                return 0;
            }
        });

        termsProbe()->call('importTaxonomyTermsAtBeginning', new BaseEntryTermsTestParser([], $categories, $tags), [$taxonomy], 'default', new BufferedOutput);

        expect($handed->getArrayCopy())->toBe([[$taxonomy, [$taxonomy === 'category' ? 'news' : 'blue']]]);
    })->with([
        'categories' => ['category', ['junk', termsElement(['term_id' => '4', 'category_nicename' => 'news', 'cat_name' => 'News', 'category_parent' => ''], 'category')], []],
        'tags' => ['post_tag', [], ['junk', termsElement(['term_id' => '5', 'tag_slug' => 'blue', 'tag_name' => 'Blue'], 'tag')]],
    ]);

    it('passes over a taxonomy whose handle reads as a number and imports the rest', function (): void {
        termsToggleSupported(false);
        $output = new BufferedOutput;

        termsProbe()->call('importTaxonomyTermsAtBeginning', new BaseEntryTermsTestParser([termsTerm('1', 'counted', 'Counted', '7'), termsTerm('2', 'kept', 'Kept')]), ['7', 'probe_tax'], 'default', $output);

        expect(termsLines($output))->not->toContain("- Processing '7' taxonomy terms...")
            ->and(termsSaved())->toBe(['probe_tax::default_kept' => 'Default — Kept']);
    });
});

describe('the flat term destinations', function (): void {
    it('records each term with an id and a slug, and passes over anything else', function (): void {
        $terms = ['junk', termsTerm('', 'idless'), termsTerm('9', ''), termsTerm('3', 'child')];

        termsProbe()->call('registerFlatTermDestinations', $terms, 'probe_tax', 'probe_tax', ['child' => ['full_slug' => 'parent_child']], 'default');

        expect(TermDestinationRegistry::all())->toBe([
            'probe_tax::3' => ['shape' => 'taxonomy', 'handle' => 'probe_tax', 'reference' => 'default_parent_child'],
        ]);
    });

    it('maps each term element and passes over anything else', function (): void {
        $map = termsProbe()->call('buildHierarchicalTermMap', ['junk', termsTerm('3', 'child', parent: 'parent'), termsTerm('2', 'parent')]);

        expect($map)->toBeArray()->toHaveKeys(['child', 'parent']);
    });
});

describe('the hierarchical slug', function (): void {
    it('answers the slug itself for an empty slug or one not in the map', function (string $slug): void {
        expect(termsProbe()->fullSlug($slug, ['other' => ['parent' => '']]))->toBe([$slug, []]);
    })->with(['', 'missing']);

    it('answers a slug whose map entry is not an array without visiting it', function (): void {
        expect(termsProbe()->fullSlug('odd', ['odd' => 'not an array']))->toBe(['odd', []]);
    });

    it('stops at a slug it has already visited', function (): void {
        expect(termsProbe()->fullSlug('child', ['child' => ['parent' => 'parent'], 'parent' => ['parent' => '']], ['child']))->toBe(['child', ['child']]);
    });
});

describe('saving a term safely', function (): void {
    it('reports a search index failure as a save', function (): void {
        $term = termsSave('default_a', 'A');
        termsFailSaves(new JsonException('Syntax error', 4));
        $logged = termsLog();
        $output = new BufferedOutput;

        $saved = termsProbe()->call('saveTaxonomyTermSafely', $term, 'probe_tax', 'default_a', $output);

        expect($saved)->toBeTrue()
            ->and(termsLines($output))->toBe(["Warning: Taxonomy term 'probe_tax::default_a' was saved but search index update failed (corrupted search index). Import will continue."])
            ->and($logged->getArrayCopy())->toBe([[
                'warning',
                "BaseEntryCreator::saveTaxonomyTermSafely: Search index JSON error when saving taxonomy term 'probe_tax::default_a'. The term was saved but search indexing failed.",
                ['taxonomy' => 'probe_tax', 'term_slug' => 'default_a', 'error' => 'Syntax error', 'error_code' => 4],
            ]]);
    });

    it('reports any other failure as no save', function (): void {
        $term = termsSave('default_a', 'A');
        $failure = new RuntimeException('Disk full', 28);
        termsFailSaves($failure);
        $logged = termsLog();
        $output = new BufferedOutput;

        $saved = termsProbe()->call('saveTaxonomyTermSafely', $term, 'probe_tax', 'default_a', $output);

        expect($saved)->toBeFalse()
            ->and(termsLines($output))->toBe(["Error: Failed to save taxonomy term 'probe_tax::default_a': Disk full"])
            ->and($logged->getArrayCopy())->toBe([[
                'error',
                "BaseEntryCreator::saveTaxonomyTermSafely: Unexpected error when saving taxonomy term 'probe_tax::default_a'",
                ['taxonomy' => 'probe_tax', 'term_slug' => 'default_a', 'error' => 'Disk full', 'error_code' => 28, 'trace' => $failure->getTraceAsString()],
            ]]);
    });
});

describe('creating a term directly', function (): void {
    it('answers true at once for a term this run already brought to its final state', function (): void {
        termsProbe()->write('processedTermsCache', ['probe_tax::default_done' => true]);
        $output = new BufferedOutput;

        expect(BaseEntryCreator::createTaxonomyTerm('probe_tax', 'done', 'default', $output, 'Done'))->toBeTrue()
            ->and(termsSaved())->toBe([])
            ->and($output->fetch())->toBe('');
    });

    it('names a new term from its slug when no name is given', function (): void {
        expect(BaseEntryCreator::createTaxonomyTerm('probe_tax', 'some-new_term', 'default', new BufferedOutput))->toBeTrue()
            ->and(termsSaved())->toBe(['probe_tax::default_some-new_term' => 'Default — Some New Term']);
    });

    it('updates an existing term whose title or blueprint differs', function (string $title, string $blueprint): void {
        termsSave('default_some-old_term', $title, $blueprint);
        $probe = termsProbe();
        $output = new BufferedOutput;

        expect(BaseEntryCreator::createTaxonomyTerm('probe_tax', 'some-old_term', 'default', $output))->toBeTrue()
            ->and(termsLines($output))->toBe(['- Updated taxonomy term: probe_tax::default_some-old_term with title: Default — Some Old Term'])
            ->and($probe->read('processedTermsCache'))->toBe(['probe_tax::default_some-old_term' => true])
            ->and(termsSaved())->toBe(['probe_tax::default_some-old_term' => 'Default — Some Old Term'])
            ->and(termsSaved('blueprint'))->toBe(['probe_tax::default_some-old_term' => 'probe_tax']);
    })->with([
        'the title' => ['Old title', 'probe_tax'],
        'the blueprint' => ['Default — Some Old Term', 'other_blueprint'],
    ]);

    it('leaves an existing term titled from its slug when no name is given', function (): void {
        termsSave('default_some-old_term', 'Default — Some Old Term');
        $probe = termsProbe();
        $output = new BufferedOutput;

        expect(BaseEntryCreator::createTaxonomyTerm('probe_tax', 'some-old_term', 'default', $output))->toBeFalse()
            ->and(termsLines($output))->toBe(['Taxonomy term already exists: probe_tax::default_some-old_term'])
            ->and($probe->read('processedTermsCache'))->toBe(['probe_tax::default_some-old_term' => false]);
    });

    it('answers false when the term fails to save', function (bool $existing): void {
        if ($existing) {
            termsSave('default_failing', 'Old title');
        }

        termsFailSaves(new RuntimeException('Disk full'));
        termsLog();
        $probe = termsProbe();
        $output = new BufferedOutput;

        expect(BaseEntryCreator::createTaxonomyTerm('probe_tax', 'failing', 'default', $output, 'Failing'))->toBeFalse()
            ->and(termsLines($output))->toBe(["Error: Failed to save taxonomy term 'probe_tax::default_failing': Disk full"])
            ->and($probe->read('processedTermsCache'))->toBe(['probe_tax::default_failing' => false]);
    })->with(['an existing term' => [true], 'a new term' => [false]]);
});

describe('the deferred term queue', function (): void {
    it('queues nothing for a map entry that is not an array', function (): void {
        $probe = termsProbe();

        BaseEntryCreator::queueTaxonomyTermFromMap('odd', ['odd' => 'not an array'], 'probe_tax', 'default');

        expect($probe->read('deferredTermQueue'))->toBe([]);
    });

    it('queues the slug and name in order of preference', function (array $entry, string $slug, string $name): void {
        $probe = termsProbe();

        BaseEntryCreator::queueTaxonomyTermFromMap('the-key', ['the-key' => $entry], 'probe_tax', 'default', 'label');

        expect($probe->read('deferredTermQueue'))->toBe(['probe_tax' => ['default' => [$slug => ['original' => $slug, 'name' => $name]]]]);
    })->with([
        'term slug and name' => [['full_slug' => 7, 'term_slug' => 'short', 'label' => 7, 'name' => 'Name'], 'short', 'Name'],
        'the key, unnamed' => [['term_slug' => 7, 'name' => 7], 'the-key', ''],
    ]);

    it('creates each taxonomy queued for the site and drains it', function (): void {
        $probe = termsProbe();
        BaseEntryCreator::queueTaxonomyTerm('elsewhere_tax', 'away', 'other');
        BaseEntryCreator::queueTaxonomyTerm('probe_tax', 'queued', 'default', 'Queued');
        BaseEntryCreator::queueTaxonomyTerm('probe_tax', 'second', 'default', 'Second');
        BaseEntryCreator::queueTaxonomyTerm('probe_tax', 'later', 'other', 'Later');

        $probe->flushDeferredTaxonomyTerms('default', new BufferedOutput);

        expect(termsSaved())->toBe([
            'probe_tax::default_queued' => 'Default — Queued',
            'probe_tax::default_second' => 'Default — Second',
        ])->and($probe->read('deferredTermQueue'))->toBe([
            'elsewhere_tax' => ['other' => ['away' => ['original' => 'away', 'name' => '']]],
            'probe_tax' => ['other' => ['later' => ['original' => 'later', 'name' => 'Later']]],
        ]);
    });
});

describe('reading a term', function (): void {
    it('reads a field as empty when the term has no default-locale form', function (): void {
        $term = new class extends Term
        {
            /**
             * No default-locale form.
             *
             * @return null Always null
             */
            public function inDefaultLocale()
            {
                return null;
            }
        };

        expect(termsProbe()->call('getTermStringValue', $term, 'title'))->toBe('');
    });

    it('reads no id from a value that is not an object, or whose id cannot be called', function (mixed $candidate): void {
        expect(termsProbe()->call('extractObjectId', $candidate))->toBeNull();
    })->with([
        'a number' => [7],
        'a private id' => [new class
        {
            /**
             * An id only this class can read.
             *
             * @return string The id
             */
            private function id(): string
            {
                return 'hidden';
            }

            /**
             * Read the id, so it is not unused.
             *
             * @return string The id
             */
            public function own(): string
            {
                return $this->id();
            }
        }],
    ]);
});
