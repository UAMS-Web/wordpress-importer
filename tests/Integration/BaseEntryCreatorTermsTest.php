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
use Statamic\Facades\YAML;
use Statamic\Stache\Repositories\TermRepository;
use Statamic\Stache\Stache as StacheInstance;
use Statamic\Stache\Stores\Store;
use Statamic\Taxonomies\Taxonomy;
use Statamic\Taxonomies\Term;
use Statamic\Taxonomies\TermCollection;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\BaseTaxonomyValidator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\PostCategoryEntryResolver;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\StructuredTermImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\TermDestinationRegistry;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\NewsArticleResolver;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\StacheIndexUpdateToggle;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\TaxonomySlugProcessor;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;
use UAMSWeb\WordPressImporter\Tests\Support\CompilesCleanly;

uses(StatamicTestCase::class);

/**
 * What the probe `BaseEntryCreator` adds, so the probe can be built inside a test.
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
    // Read the term files, as a fresh process would. Clearing and warming the
    // store in this one can read back only some of the terms saved since it warmed
    $values = [];

    $files = glob(termsDirectory('terms').'/probe_tax/*.yaml');

    foreach ($files === false ? [] : $files as $file) {
        $values['probe_tax::'.basename($file, '.yaml')] = YAML::parse((string) file_get_contents($file))[$field] ?? null;
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
 * Make the structured importer record what it is handed instead of importing it.
 *
 * @return ArrayObject<int, mixed> Each handover's WordPress taxonomy, destination and term slugs, filled as they arrive
 */
function termsHanded(): ArrayObject
{
    /** @var ArrayObject<int, mixed> $handed */
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
            $this->handed->append([$wordpressTaxonomy, $collectionHandle, termsSlugs($terms)]);

            return 0;
        }
    });

    return $handed;
}

/**
 * Run a callback with every PHP warning and notice thrown as an exception, as
 * Laravel's error handler throws them in an import.
 *
 * @param  callable(): mixed  $callback  The callback
 * @return mixed What it returns
 */
function termsStrict(callable $callback): mixed
{
    set_error_handler(static function (int $level, string $message): never {
        throw new ErrorException($message, 0, $level);
    });

    try {
        return $callback();
    } finally {
        restore_error_handler();
    }
}

/**
 * The fields of each pseudo term.
 *
 * @param  mixed  $pseudoTerms  What a pseudo-term builder answered
 * @return list<array<string, string>> Each term's child values, keyed by local name
 */
function termsFields(mixed $pseudoTerms): array
{
    $fields = [];

    foreach (is_array($pseudoTerms) ? $pseudoTerms : [] as $pseudoTerm) {
        $values = [];
        $children = $pseudoTerm instanceof SimpleXMLElement ? $pseudoTerm->children('wp', true) : null;

        foreach ($children ?? [] as $name => $child) {
            $values[$name] = (string) $child;
        }

        $fields[] = $values;
    }

    return $fields;
}

/**
 * A recorder standing in for the Stache's term store, answering one taxonomy store.
 *
 * @param  ArrayObject<int, string>  $called  The record of each clear and warm
 * @return object The term store
 */
function termsRecordingStore(ArrayObject $called): object
{
    return new class($called)
    {
        /**
         * Keep a record of the calls.
         *
         * @param  ArrayObject<int, string>  $called  The record
         */
        public function __construct(private readonly ArrayObject $called) {}

        /**
         * Answer a taxonomy store that records its clear and warm.
         *
         * @param  string  $handle  The taxonomy
         * @return object The taxonomy store
         */
        public function store(string $handle): object
        {
            return new class($this->called, $handle)
            {
                /**
                 * Keep the record and the taxonomy.
                 *
                 * @param  ArrayObject<int, string>  $called  The record
                 * @param  string  $handle  The taxonomy
                 */
                public function __construct(private readonly ArrayObject $called, private readonly string $handle) {}

                /**
                 * Record a clear.
                 */
                public function clear(): void
                {
                    $this->called->append("clear {$this->handle}");
                }

                /**
                 * Record a warm.
                 */
                public function warm(): void
                {
                    $this->called->append("warm {$this->handle}");
                }
            };
        }
    };
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
    CompilesCleanly::assert(BaseEntryCreator::class);
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

        // The skipped term is left for a later direct call to correct
        expect(termsLines($output))->toContain("- Skipped taxonomy term 'default_old' as it already exists.")
            ->and($probe->read('processedTermsCache'))->toBe(['probe_tax::default_old' => false, 'probe_tax::default_new' => true])
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
        $probe = termsProbe();
        $output = new BufferedOutput;

        $probe->call('createTaxonomyTermsFromStoredData', [['original' => 'same', 'name' => 'Same']], 'probe_tax', 'default', $output);

        expect(termsLines($output))->toBe(['- Taxonomy terms that already exist in Statamic:', '- probe_tax::default_same'])
            ->and($probe->read('processedTermsCache'))->toBe(['probe_tax::default_same' => false]);
    });

    it('numbers each additional term past the ones this run already queued', function (): void {
        termsSave('default_dup', 'Old title');
        $probe = termsProbe();
        $probe->setTaxonomyOverwriteBehavior('Create an additional term');

        $probe->call('createTaxonomyTermsFromStoredData', [['original' => 'dup', 'name' => 'First'], ['original' => 'dup', 'name' => 'Second']], 'probe_tax', 'default', new BufferedOutput);

        // Statamic's slug setter drops the dot of the importer's ".1" and ".2" (#193)
        expect(termsSaved())->toBe([
            'probe_tax::default_dup' => 'Old title',
            'probe_tax::default_dup1' => 'Default — First',
            'probe_tax::default_dup2' => 'Default — Second',
        ]);
    });

    it('reads a term whose position is not a number', function (): void {
        termsProbe()->call('createTaxonomyTermsFromStoredData', ['first' => ['original' => 'keyed', 'name' => 'Keyed']], 'probe_tax', 'default', new BufferedOutput);

        expect(termsSaved())->toBe(['probe_tax::default_keyed' => 'Default — Keyed']);
    });

    it('stores each term once, telling numeric slugs apart', function (): void {
        $data = [];
        $store = new ReflectionMethod(BaseEntryCreator::class, 'storeSiteSpecificTaxonomyData');
        $probe = termsProbe();

        foreach ([['10', '10', 'Ten'], ['10', '10', 'Ten'], ['1e1', '1e1', 'Ten']] as [$siteSpecific, $original, $name]) {
            $store->invokeArgs($probe, [&$data, $siteSpecific, $original, $name]);
        }

        expect($data)->toBe([
            ['site_specific' => '10', 'original' => '10', 'name' => 'Ten'],
            ['site_specific' => '1e1', 'original' => '1e1', 'name' => 'Ten'],
        ]);
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

        $probe->call('persistQueuedTermChanges', ['junk', ['term' => 'not a term', 'slug' => 'default_kept', 'title' => 'Wrong title', 'cacheKey' => 'probe_tax::default_kept'], ['term' => $term, 'slug' => 'default_kept', 'title' => 'New title', 'cacheKey' => 'probe_tax::default_kept']], [], 'other_blueprint', 'default', new BufferedOutput);

        expect(termsSaved())->toBe(['probe_tax::default_kept' => 'New title'])
            ->and(termsSaved('blueprint'))->toBe(['probe_tax::default_kept' => 'other_blueprint'])
            ->and($probe->read('processedTermsCache'))->toBe(['probe_tax::default_kept' => true]);
    });

    it('announces the creations only when there are no updates before them', function (bool $updates, array $announced): void {
        $term = termsSave('default_kept', 'Old title');
        $output = new BufferedOutput;
        $toUpdate = $updates ? [['term' => $term, 'slug' => 'default_kept', 'title' => 'New title', 'cacheKey' => 'probe_tax::default_kept']] : [];

        termsProbe()->call('persistQueuedTermChanges', $toUpdate, [['slug' => 'default_made', 'title' => 'Made', 'cacheKey' => 'probe_tax::default_made']], 'probe_tax', 'default', $output);

        expect(array_values(array_filter(termsLines($output), static fn (string $line): bool => str_ends_with($line, 'taxonomy terms...'))))->toBe($announced);
    })->with([
        'creations alone' => [false, ['- Creating new taxonomy terms...']],
        'updates first' => [true, ['- Updating existing taxonomy terms...']],
    ]);

    it('reports each update and creation it saves, and creates under the taxonomy blueprint', function (): void {
        $term = termsSave('default_kept', 'Old title');
        $output = new BufferedOutput;

        termsProbe()->call('persistQueuedTermChanges', [['term' => $term, 'slug' => 'default_kept', 'title' => 'New title', 'cacheKey' => 'probe_tax::default_kept']], [['slug' => 'default_made', 'title' => 'Made', 'cacheKey' => 'probe_tax::default_made']], 'probe_tax', 'default', $output);

        expect(termsLines($output))->toBe([
            '- Updating existing taxonomy terms...',
            '- Updated taxonomy term: probe_tax::default_kept with title: New title',
            "- Created 'probe_tax::default_made'",
        ])->and(termsSaved('blueprint'))->toBe(['probe_tax::default_kept' => 'probe_tax', 'probe_tax::default_made' => 'probe_tax']);
    });

    it('passes over a creation missing a field, or with one that is not a string', function (): void {
        $creations = [
            ['slug' => 'default_unnamed', 'cacheKey' => 'probe_tax::default_unnamed'],
            ['slug' => 5, 'title' => 'Numbered', 'cacheKey' => 'probe_tax::default_numbered'],
            ['slug' => 'default_titled', 'title' => 5, 'cacheKey' => 'probe_tax::default_titled'],
            ['slug' => 'default_keyed', 'title' => 'Keyed', 'cacheKey' => 5],
            ['slug' => 'default_made', 'title' => 'Made', 'cacheKey' => 'probe_tax::default_made'],
        ];

        termsProbe()->call('persistQueuedTermChanges', [], $creations, 'probe_tax', 'default', new BufferedOutput);

        expect(termsSaved())->toBe(['probe_tax::default_made' => 'Made']);
    });

    it('logs and creates nothing for a taxonomy that does not exist', function (): void {
        $logged = termsLog();
        $output = new BufferedOutput;

        termsProbe()->call('persistQueuedTermChanges', [], [['slug' => 'default_made', 'title' => 'Made', 'cacheKey' => 'no_such_tax::default_made']], 'no_such_tax', 'default', $output);

        expect(termsLines($output))->toBe(['- Creating new taxonomy terms...'])
            ->and($logged->getArrayCopy())->toBe([['error', "BaseEntryCreator::createTaxonomyTermsFromStoredData - Taxonomy 'no_such_tax' does not exist", []]]);
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

    it('pauses index updating while the work runs, and turns it back on', function (): void {
        termsToggleSupported(true);
        $during = new ArrayObject;

        termsProbe()->call('withTermIndexUpdatesDisabled', 'probe_tax', static function () use ($during): void {
            $during->append(Stache::shouldUpdateIndexes());
        });

        expect($during->getArrayCopy())->toBe([false])
            ->and(Stache::shouldUpdateIndexes())->toBeTrue();
    });

    it('rebuilds the term store after the work', function (): void {
        termsToggleSupported(true);
        termsLog();
        $called = new ArrayObject;
        termsStache(termsRecordingStore($called));

        termsProbe()->call('withTermIndexUpdatesDisabled', 'probe_tax', static function (): void {});

        expect($called->getArrayCopy())->toBe(['clear probe_tax', 'warm probe_tax']);
    });
});

describe('rebuilding the term store', function (): void {
    it('clears and warms the taxonomy\'s term store', function (): void {
        $logged = termsLog();
        $called = new ArrayObject;
        termsStache(termsRecordingStore($called));

        termsProbe()->call('rebuildTermStoreFromDisk', 'probe_tax');

        expect($called->getArrayCopy())->toBe(['clear probe_tax', 'warm probe_tax'])
            ->and($logged->getArrayCopy())->toBe([]);
    });

    it('rebuilds nothing for a taxonomy that does not exist', function (): void {
        $called = new ArrayObject;
        termsStache(termsRecordingStore($called));

        termsProbe()->call('rebuildTermStoreFromDisk', 'no_such_tax');

        expect($called->getArrayCopy())->toBe([]);
    });

    it('rebuilds nothing when the term store cannot name its stores', function (): void {
        $logged = termsLog();
        termsStache(new stdClass);

        termsProbe()->call('rebuildTermStoreFromDisk', 'probe_tax');

        expect($logged->getArrayCopy())->toBe([]);
    });

    it('rebuilds nothing when the taxonomy store cannot both clear and warm', function (string $has): void {
        $logged = termsLog();
        $called = new ArrayObject;
        $store = $has === 'clear'
            ? new class($called)
            {
                /**
                 * Keep a record of the calls.
                 *
                 * @param  ArrayObject<int, string>  $called  The record
                 */
                public function __construct(private readonly ArrayObject $called) {}

                /**
                 * Record a clear.
                 */
                public function clear(): void
                {
                    $this->called->append('clear');
                }
            }
        : new class($called)
        {
            /**
             * Keep a record of the calls.
             *
             * @param  ArrayObject<int, string>  $called  The record
             */
            public function __construct(private readonly ArrayObject $called) {}

            /**
             * Record a warm.
             */
            public function warm(): void
            {
                $this->called->append('warm');
            }
        };
        termsStache(new class($store)
        {
            /**
             * Keep the taxonomy store to answer.
             *
             * @param  object  $store  The taxonomy store
             */
            public function __construct(private readonly object $store) {}

            /**
             * Answer the taxonomy store.
             *
             * @param  string  $handle  The taxonomy
             * @return object The taxonomy store
             */
            public function store(string $handle): object
            {
                return $this->store;
            }
        });

        termsProbe()->call('rebuildTermStoreFromDisk', 'probe_tax');

        expect($called->getArrayCopy())->toBe([])
            ->and($logged->getArrayCopy())->toBe([]);
    })->with(['clear', 'warm']);

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
        $parser = new BaseEntryTermsTestParser(categories: ['junk', termsElement(['term_id' => '4', 'category_nicename' => 'news', 'cat_name' => 'News', 'category_parent' => 'world'], 'category')]);

        expect(termsFields(termsProbe()->call('categoryPseudoTerms', $parser)))->toBe([
            ['term_id' => '4', 'term_taxonomy' => 'category', 'term_slug' => 'news', 'term_name' => 'News', 'term_parent' => 'world'],
        ]);
    });

    it('converts each tag and passes over anything else', function (): void {
        $parser = new BaseEntryTermsTestParser(tags: ['junk', termsElement(['term_id' => '5', 'tag_slug' => 'blue', 'tag_name' => 'Blue'], 'tag')]);

        expect(termsFields(termsProbe()->call('tagPseudoTerms', $parser)))->toBe([
            ['term_id' => '5', 'term_taxonomy' => 'post_tag', 'term_slug' => 'blue', 'term_name' => 'Blue'],
        ]);
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
        $handed = termsHanded();

        termsProbe()->call('importTaxonomyTermsAtBeginning', new BaseEntryTermsTestParser([], $categories, $tags), [$taxonomy], 'default', new BufferedOutput);

        expect($handed->getArrayCopy())->toBe([$taxonomy === 'category' ? ['category', 'post_categories', ['news']] : ['post_tag', 'post_tags', ['blue']]]);
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

    it('imports a set of taxonomies once per site, whatever their order', function (string $firstSite, array $first, string $secondSite, array $second, bool $again): void {
        $probe = termsProbe();
        $parser = new BaseEntryTermsTestParser;
        $output = new BufferedOutput;

        $probe->call('importTaxonomyTermsAtBeginning', $parser, $first, $firstSite, new BufferedOutput);
        $probe->call('importTaxonomyTermsAtBeginning', $parser, $second, $secondSite, $output);

        expect(termsLines($output))->toBe($again ? ['Importing taxonomy terms at beginning of import process...', '- Taxonomy terms import completed.'] : []);
    })->with([
        'the same set in another order' => ['default', ['probe_tax', 'zz_other'], 'default', ['zz_other', 'probe_tax'], false],
        'the same set with a handle that is not a string' => ['default', ['probe_tax'], 'default', ['probe_tax', 7], false],
        'another set' => ['default', ['probe_tax'], 'default', ['zz_other'], true],
        'the same set for another site' => ['one', ['probe_tax'], 'two', ['probe_tax'], true],
        'a site and set that run together the same way' => ['ab', ['c'], 'a', ['bc'], true],
    ]);

    it('groups nothing for a handle that only loosely names a taxonomy', function (array $handles, array $terms): void {
        $handed = termsHanded();
        $output = new BufferedOutput;
        $category = termsElement(['term_id' => '4', 'category_nicename' => 'news', 'cat_name' => 'News', 'category_parent' => ''], 'category');
        $tag = termsElement(['term_id' => '5', 'tag_slug' => 'blue', 'tag_name' => 'Blue'], 'tag');

        termsProbe()->call('importTaxonomyTermsAtBeginning', new BaseEntryTermsTestParser($terms, [$category], [$tag]), $handles, 'default', $output);

        expect(termsLines($output))->toBe(['Importing taxonomy terms at beginning of import process...', '- Taxonomy terms import completed.'])
            ->and($handed->getArrayCopy())->toBe([]);
    })->with([
        'a number written another way' => [['10'], [termsTerm('1', 'odd', 'Odd', '1e1')]],
        'a value that is not a string' => [[true], []],
    ]);

    it('keeps the terms already grouped under category or post_tag when adding the pseudo terms', function (string $taxonomy, array $categories, array $tags, array $slugs): void {
        $handed = termsHanded();

        termsProbe()->call('importTaxonomyTermsAtBeginning', new BaseEntryTermsTestParser([termsTerm('1', 'from-terms', 'From terms', $taxonomy)], $categories, $tags), [$taxonomy], 'default', new BufferedOutput);

        expect($handed->getArrayCopy())->toBe([[$taxonomy, $taxonomy === 'category' ? 'post_categories' : 'post_tags', $slugs]]);
    })->with([
        'categories' => ['category', [termsElement(['term_id' => '4', 'category_nicename' => 'news', 'cat_name' => 'News', 'category_parent' => ''], 'category')], [], ['from-terms', 'news']],
        'tags' => ['post_tag', [], [termsElement(['term_id' => '5', 'tag_slug' => 'blue', 'tag_name' => 'Blue'], 'tag')], ['from-terms', 'blue']],
    ]);

    it('says a taxonomy has no terms and goes on to the next', function (): void {
        $handed = termsHanded();
        $output = new BufferedOutput;

        termsProbe()->call('importTaxonomyTermsAtBeginning', new BaseEntryTermsTestParser([], [], [termsElement(['term_id' => '5', 'tag_slug' => 'blue', 'tag_name' => 'Blue'], 'tag')]), ['category', 'post_tag'], 'default', $output);

        expect(termsLines($output))->toContain("- No 'category' taxonomy terms to process.")
            ->and($handed->getArrayCopy())->toBe([['post_tag', 'post_tags', ['blue']]]);
    });

    it('reports an unmapped taxonomy and goes on to the next', function (): void {
        termsToggleSupported(false);
        $output = new BufferedOutput;

        termsProbe()->call('importTaxonomyTermsAtBeginning', new BaseEntryTermsTestParser([termsTerm('1', 'links', 'Links', 'link_category'), termsTerm('2', 'kept', 'Kept')]), ['link_category', 'probe_tax'], 'default', $output);

        expect(termsLines($output))->toContain("- Skipping 'link_category': recorded as having no Statamic destination. 1 term(s) not imported.")
            ->toContain("- Processing 'probe_tax' taxonomy terms...")
            ->and(termsSaved())->toBe(['probe_tax::default_kept' => 'Default — Kept'])
            ->and(TermDestinationRegistry::all())->toBe(['probe_tax::2' => ['shape' => 'taxonomy', 'handle' => 'probe_tax', 'reference' => 'default_kept']]);
    });

    it('counts the terms of an unmapped taxonomy nobody asked for', function (): void {
        $output = new BufferedOutput;

        termsProbe()->call('importTaxonomyTermsAtBeginning', new BaseEntryTermsTestParser([termsTerm('1', 'one', 'One', 'link_category'), termsTerm('2', 'two', 'Two', 'link_category')]), ['probe_tax'], 'default', $output);

        expect(termsLines($output))->toContain("- Skipping 'link_category': recorded as having no Statamic destination. 2 term(s) not imported.");
    });

    it('hands a structured taxonomy over and goes on to the next', function (): void {
        termsToggleSupported(false);
        $handed = termsHanded();

        termsProbe()->call('importTaxonomyTermsAtBeginning', new BaseEntryTermsTestParser([termsTerm('7', 'filed', 'Filed', 'category'), termsTerm('8', 'kept', 'Kept')]), ['category', 'probe_tax'], 'default', new BufferedOutput);

        expect($handed->getArrayCopy())->toBe([['category', 'post_categories', ['filed']]])
            ->and(termsSaved())->toBe(['probe_tax::default_kept' => 'Default — Kept']);
    });

    it('builds the term maps from the terms, categories and tags, after importing them', function (): void {
        termsToggleSupported(false);
        termsHanded();
        $probe = termsProbe();
        $category = termsElement(['term_id' => '4', 'category_nicename' => 'news', 'cat_name' => 'News', 'category_parent' => ''], 'category');
        $tag = termsElement(['term_id' => '5', 'tag_slug' => 'blue', 'tag_name' => 'Blue'], 'tag');

        $probe->call('initializeTerms', new BaseEntryTermsTestParser([termsTerm('2', 'kept', 'Kept')], [$category], [$tag]), ['probe_tax', 'category', 'post_tag'], 'default', new BufferedOutput);

        $termMap = (new ReflectionProperty(BaseEntryCreator::class, 'termMap'))->getValue($probe);

        expect(termsSaved())->toBe(['probe_tax::default_kept' => 'Default — Kept'])
            // Keyed by WordPress term id: the term, then the category and the tag
            ->and(is_array($termMap) ? array_keys($termMap) : $termMap)->toBe([2, 4, 5]);
    });

    it('imports a term under its full hierarchical slug, and none without a slug', function (): void {
        termsToggleSupported(false);

        termsProbe()->call('importTaxonomyTermsAtBeginning', new BaseEntryTermsTestParser([termsTerm('1', 'parent', 'Parent'), termsTerm('2', 'child', 'Child', 'probe_tax', 'parent'), termsTerm('3', '', 'Slugless')]), ['probe_tax'], 'default', new BufferedOutput);

        expect(termsSaved())->toBe([
            'probe_tax::default_parent' => 'Default — Parent',
            'probe_tax::default_parent_child' => 'Default — Child',
        ]);
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

    it('records a term under its own slug when the map holds no full slug for it', function (array $termMap): void {
        termsStrict(static fn (): mixed => termsProbe()->call('registerFlatTermDestinations', [termsTerm('4', 'plain')], 'probe_tax', 'probe_tax', $termMap, 'default'));

        expect(TermDestinationRegistry::all())->toBe([
            'probe_tax::4' => ['shape' => 'taxonomy', 'handle' => 'probe_tax', 'reference' => 'default_plain'],
        ]);
    })->with([
        'no entry' => [[]],
        'an entry with no full slug' => [['plain' => ['parent' => '']]],
        'a full slug that is not a string' => [['plain' => ['full_slug' => 5]]],
    ]);

    it('maps each term element and passes over anything else', function (): void {
        $map = termsProbe()->call('buildHierarchicalTermMap', ['junk', termsTerm('3', 'child', parent: 'parent'), termsTerm('2', 'parent'), termsTerm('4', '')]);

        expect($map)->toBe([
            'child' => ['full_slug' => 'parent_child', 'parent' => 'parent'],
            'parent' => ['full_slug' => 'parent', 'parent' => ''],
        ]);
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

    it('answers an empty slug as itself even when the map has an entry for it', function (): void {
        expect(termsProbe()->fullSlug('', ['' => ['parent' => 'parent'], 'parent' => ['parent' => '']]))->toBe(['', []]);
    });

    it('tells numeric slugs apart when tracking the slugs it visited', function (): void {
        expect(termsProbe()->fullSlug('10', ['10' => ['parent' => '1e1'], '1e1' => ['parent' => '']]))->toBe(['1e1_10', ['10', '1e1']]);
    });

    it('ends at the slug itself for a term with no parent, or one the map does not hold', function (array $entry): void {
        expect(termsStrict(static fn (): array => termsProbe()->fullSlug('child', ['child' => $entry])))->toBe(['child', ['child']]);
    })->with([
        'no parent' => [[]],
        'a parent the map does not hold' => [['parent' => 'missing']],
    ]);
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
        $probe = termsProbe();
        $validatorCache = new ReflectionProperty(BaseTaxonomyValidator::class, 'existingTermsCache');
        $validatorCache->setValue(null, ['stale' => []]);
        $output = new BufferedOutput;

        expect(BaseEntryCreator::createTaxonomyTerm('probe_tax', 'some-new_term', 'default', $output))->toBeTrue()
            ->and(termsLines($output))->toBe(["- Created 'probe_tax::default_some-new_term'"])
            ->and(termsSaved())->toBe(['probe_tax::default_some-new_term' => 'Default — Some New Term'])
            ->and(termsSaved('blueprint'))->toBe(['probe_tax::default_some-new_term' => 'probe_tax'])
            ->and($probe->read('processedTermsCache'))->toBe(['probe_tax::default_some-new_term' => true])
            ->and($validatorCache->getValue())->toBe([]);
    });

    it('reports a term it cannot create for a structured destination, once', function (): void {
        $probe = termsProbe();
        $output = new BufferedOutput;

        expect(BaseEntryCreator::createTaxonomyTerm('post_categories', 'news', 'default', $output, 'News'))->toBeFalse()
            ->and(termsLines($output))->toBe(["Warning: cannot create 'default_news': 'post_categories' exists as neither a taxonomy nor a collection. Structured terms are created by the term pass, so this reference will not resolve."])
            ->and($probe->read('processedTermsCache'))->toBe(['post_categories::default_news' => true]);
    });

    it('reports a term it cannot create for a taxonomy that does not exist, once', function (): void {
        $probe = termsProbe();
        $output = new BufferedOutput;

        expect(BaseEntryCreator::createTaxonomyTerm('no_such_tax', 'news', 'default', $output, 'News'))->toBeFalse()
            ->and(termsLines($output))->toBe(["Warning: Taxonomy 'no_such_tax' does not exist. Skipping term creation for 'default_news'."])
            ->and($probe->read('processedTermsCache'))->toBe(['no_such_tax::default_news' => true]);
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

    it('reads a field that is not a string as empty', function (): void {
        $term = termsSave('default_counted', 'Counted');
        $term->set('title', 5);

        expect(termsProbe()->call('getTermStringValue', $term, 'title'))->toBe('');
    });

    it('reads no id from a value that is not an object, or whose id cannot be called', function (mixed $candidate): void {
        expect(termsProbe()->call('extractObjectId', $candidate))->toBeNull();
    })->with([
        'a number' => [7],
        'an empty id' => [termsImpostor('')],
        'an id that is not a string' => [new class
        {
            /**
             * An id that is a number.
             *
             * @return int The id
             */
            public function id(): int
            {
                return 5;
            }
        }],
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

describe('the term caches', function (): void {
    it('names a site from its name, or from its handle when it has none', function (string $site, string $name): void {
        Site::setSites([
            'default' => ['name' => 'Main Campus', 'locale' => 'en_US', 'url' => '/'],
            'blank_site' => ['name' => '', 'locale' => 'en_US', 'url' => '/blank/'],
        ]);

        expect(termsProbe()->call('getCachedSiteName', $site))->toBe($name);
    })->with([
        'a named site' => ['default', 'Main Campus'],
        'a site with an empty name' => ['blank_site', 'Blank Site'],
        'a site that does not exist' => ['new_site', 'New Site'],
    ]);

    it('groups each term once by id, and every term with no id', function (array $seenTermIds, array $seen): void {
        $probe = termsProbe();
        $group = new ReflectionMethod(BaseEntryCreator::class, 'addTermToTaxonomyGroup');
        /** @var array<string, mixed> $termsByTaxonomy */
        $termsByTaxonomy = [];

        foreach ([termsTerm('', 'first'), termsTerm('', 'second'), termsTerm('5', 'third'), termsTerm('5', 'fourth')] as $term) {
            $group->invokeArgs($probe, [&$termsByTaxonomy, &$seenTermIds, 'probe_tax', $term]);
        }

        expect(termsSlugs($termsByTaxonomy['probe_tax'] ?? []))->toBe(['first', 'second', 'third'])
            ->and($seenTermIds)->toBe($seen);
    })->with([
        'no ids seen yet' => [[], ['5' => true]],
        'an empty id already recorded' => [['' => true], ['' => true, '5' => true]],
    ]);

    it('clears the caches the term pass fills, here and in the resolvers it feeds', function (): void {
        $caches = [
            new ReflectionProperty(StructuredTermImporter::class, 'treeNodes'),
            new ReflectionProperty(PostCategoryEntryResolver::class, 'cache'),
            new ReflectionProperty(NewsArticleResolver::class, 'cache'),
        ];

        foreach ($caches as $cache) {
            $cache->setValue(null, ['stale' => 'stale']);
        }

        BaseEntryCreator::clearTaxonomyCaches();

        expect(array_map(static fn (ReflectionProperty $cache): mixed => $cache->getValue(), $caches))->toBe([[], [], []]);
    });
});
