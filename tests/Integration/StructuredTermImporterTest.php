<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Statamic\Entries\Collection;
use Statamic\Facades\Collection as CollectionFacade;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Structures\TreeBuilder;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\StructuredTermImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\TermDestinationRegistry;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * Tests that a hierarchical WordPress taxonomy imports as structured collection
 * entries with its parent tree intact (issue wordpress-importer-archive#13).
 *
 * The subtlety this file exists for: `wp:term_parent` holds the parent's **slug**,
 * while {@see TreeBuilder} keys parents by the same id it keys nodes by. Handing
 * the tree a slug where it expects an id produces no error — every node just
 * silently lands top-level, and the hierarchy the import was for is gone. The
 * resolution is asserted directly rather than inferred from a saved tree.
 *
 * Entry persistence is stubbed at {@see StructuredTermImporter::createOrUpdateEntry()}
 * so these assertions run without a real content store; what is under test is the
 * parse, the parent resolution, the tree shape, and the registry writes.
 *
 * @command  php vendor/bin/pest tests/Integration/StructuredTermImporterTest.php
 */
uses(StatamicTestCase::class);

beforeEach(function (): void {
    TermDestinationRegistry::clear();
    StructuredTermImporter::reset();
});

afterEach(function (): void {
    TermDestinationRegistry::clear();
    StructuredTermImporter::reset();
    Mockery::close();
});

/**
 * An importer whose entry persistence is stubbed to a deterministic id.
 */
final class StructuredTermImporterProbe extends StructuredTermImporter
{
    /**
     * @var array<int, array{slug: string, title: string}> Every entry write attempted
     */
    public array $written = [];

    /**
     * @var array<int|string, mixed> The flat nodes handed to the tree writer
     */
    public array $captured = [];

    /**
     * @var int How many times a tree was written
     */
    public int $saves = 0;

    /**
     * Return a deterministic id without touching the content store.
     *
     * @param  string  $slug  The term slug
     * @param  string  $title  The term name
     * @param  string  $collectionHandle  The destination collection handle
     * @param  string  $site  The Statamic site handle
     * @return ?string The stubbed entry id, or null for a slug listed as unsaved
     */
    protected function createOrUpdateEntry(
        string $slug,
        string $title,
        string $collectionHandle,
        string $site
    ): ?string {
        $this->written[] = ['slug' => $slug, 'title' => $title];

        if (\in_array($slug, $this->unsaved, true)) {
            return null;
        }

        return 'entry-'.$slug;
    }

    /**
     * @var list<string> Slugs whose entry write is reported as failed
     */
    public array $unsaved = [];

    /**
     * Record the nodes rather than writing them onto a real structure.
     *
     * @param  string  $collectionHandle  The destination collection handle
     * @param  string  $site  The Statamic site handle
     * @param  array<int|string, array{entry: string, parent: string, title: string}>  $nodes  Flat nodes keyed by term id
     * @param  OutputInterface  $output  Console output
     */
    protected function saveTree(
        string $collectionHandle,
        string $site,
        array $nodes,
        OutputInterface $output
    ): void {
        $this->captured = $nodes;
        $this->saves++;
    }

    /**
     * @var array<int, string> Each "{collection}::{site}" claimed, in call order
     */
    public array $enabled = [];

    /**
     * @var array<string, string> Parent entry id written, keyed by child entry id
     */
    public array $parents = [];

    /**
     * Record the parent write rather than persisting it.
     *
     * @param  string  $entryId  The child entry id
     * @param  string  $parentEntryId  The parent entry id
     */
    protected function setEntryParent(
        string $entryId,
        string $parentEntryId
    ): void {
        $this->parents[$entryId] = $parentEntryId;
    }

    /**
     * Record the site claim rather than mutating a real collection.
     *
     * @param  string  $collectionHandle  The destination collection handle
     * @param  string  $site  The Statamic site handle
     * @param  OutputInterface  $output  Console output
     */
    protected function enableDestinationForSite(
        string $collectionHandle,
        string $site,
        OutputInterface $output
    ): void {
        $this->enabled[] = $collectionHandle.'::'.$site;
    }

    /**
     * Read one field of a captured node, keeping the assertions typed.
     *
     * @param  string  $termId  The WordPress term id keying the node
     * @param  string  $field  The field to read
     * @return ?string The field value, or null when absent
     */
    public function nodeField(
        string $termId,
        string $field
    ): ?string {
        $node = $this->captured[$termId] ?? null;

        if (! \is_array($node)) {
            return null;
        }

        $value = $node[$field] ?? null;

        return \is_string($value) ? $value : null;
    }
}

/**
 * Build a `<wp:term>` element in the shape the WXR export uses.
 *
 * @param  string  $termId  The WordPress term id
 * @param  string  $slug  The term slug
 * @param  string  $name  The term name
 * @param  string  $parentSlug  The parent term's SLUG, as WordPress records it
 * @return SimpleXMLElement The term element
 */
function structuredTerm(
    string $termId,
    string $slug,
    string $name = '',
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
 * Point the Collection facade at a collection that exists.
 */
function structuredTermCollectionExists(): void
{
    CollectionFacade::shouldReceive('findByHandle')
        ->andReturn(Mockery::mock(Collection::class));
}

/**
 * Run the importer over a set of terms with the persistence stubbed.
 *
 * @param  list<mixed>  $terms  The term elements, and anything else an export can hold
 * @param  string  $wordpressTaxonomy  The WordPress taxonomy handle
 * @param  string  $collectionHandle  The destination collection handle
 * @param  ?StructuredTermImporterProbe  $importer  Reuse an importer to model a second pass
 * @return array{importer: StructuredTermImporterProbe, tree: StructuredTermImporterProbe, imported: int}
 */
function runStructuredTermImport(
    array $terms,
    string $wordpressTaxonomy = 'category',
    string $collectionHandle = 'post_categories',
    ?StructuredTermImporterProbe $importer = null
): array {
    $importer ??= new StructuredTermImporterProbe;

    $imported = $importer->import(
        $terms,
        $wordpressTaxonomy,
        $collectionHandle,
        'default',
        new BufferedOutput
    );

    return ['importer' => $importer, 'tree' => $importer, 'imported' => $imported];
}

it('resolves the parent slug to the parent term id in the tree', function (): void {
    structuredTermCollectionExists();

    // `wp:term_parent` is a SLUG; TreeBuilder keys parents by term ID. If the
    // slug were passed straight through, the child would land top-level.
    $result = runStructuredTermImport([
        structuredTerm('10', 'health', 'Health'),
        structuredTerm('20', 'cardiology', 'Cardiology', parentSlug: 'health'),
    ]);

    expect($result['tree']->nodeField('20', 'parent'))->toBe('10')
        ->and($result['tree']->nodeField('10', 'parent'))->toBe('')
        ->and($result['tree']->nodeField('20', 'entry'))->toBe('entry-cardiology');
});

it('registers each term against the collection entry it created', function (): void {
    structuredTermCollectionExists();

    runStructuredTermImport([
        structuredTerm('10', 'health', 'Health'),
        structuredTerm('20', 'cardiology', 'Cardiology', parentSlug: 'health'),
    ]);

    $healthDestination = TermDestinationRegistry::resolve('category', '10');

    expect(TermDestinationRegistry::referenceFor('category', '10'))->toBe('entry-health')
        ->and(TermDestinationRegistry::referenceFor('category', '20'))->toBe('entry-cardiology')
        ->and($healthDestination)->not->toBeNull()
        ->and($healthDestination['handle'] ?? null)->toBe('post_categories');
});

it('leaves a term top-level when its parent is absent from the export', function (): void {
    structuredTermCollectionExists();

    // A partial export can name a parent it does not contain. Dropping the term
    // would lose content; orphaning it to the root keeps it reachable.
    $result = runStructuredTermImport([
        structuredTerm('20', 'cardiology', 'Cardiology', parentSlug: 'not-in-this-export'),
    ]);

    expect($result['tree']->nodeField('20', 'parent'))->toBe('')
        ->and($result['imported'])->toBe(1);
});

it('titles an entry by its slug when the term has no name', function (): void {
    structuredTermCollectionExists();

    $result = runStructuredTermImport([
        structuredTerm('10', 'health'),
    ]);

    expect($result['importer']->written[0]['title'])->toBe('health');
});

it('skips terms that carry no id or no slug', function (): void {
    structuredTermCollectionExists();

    $result = runStructuredTermImport([
        structuredTerm('', 'no-id', 'No Id'),
        structuredTerm('30', '', 'No Slug'),
        structuredTerm('10', 'health', 'Health'),
    ]);

    expect($result['imported'])->toBe(1)
        ->and($result['importer']->written)->toHaveCount(1)
        ->and($result['importer']->written[0]['slug'])->toBe('health');
});

it('keeps the first occurrence when a term id repeats', function (): void {
    structuredTermCollectionExists();

    $result = runStructuredTermImport([
        structuredTerm('10', 'health', 'Health'),
        structuredTerm('10', 'health-duplicate', 'Health Duplicate'),
    ]);

    expect($result['imported'])->toBe(1)
        ->and($result['importer']->written[0]['slug'])->toBe('health');
});

it('does not site-prefix the entry slug', function (): void {
    structuredTermCollectionExists();

    // Collection entries already live under the site's directory, and each of
    // these collections builds its public URL from `slug` and `parent_uri` — a
    // prefix would leak the site handle into the route.
    $result = runStructuredTermImport([
        structuredTerm('10', 'health', 'Health'),
    ]);

    expect($result['importer']->written[0]['slug'])->toBe('health');
});

it('skips the taxonomy and reports nothing when the destination collection is missing', function (): void {
    CollectionFacade::shouldReceive('findByHandle')->andReturn(null);

    $result = runStructuredTermImport([
        structuredTerm('10', 'health', 'Health'),
    ]);

    expect($result['imported'])->toBe(0)
        ->and($result['tree']->saves)->toBe(0)
        ->and($result['importer']->written)->toBe([])
        ->and(TermDestinationRegistry::all())->toBe([]);
});

it('builds no tree when the export carries no usable terms', function (): void {
    structuredTermCollectionExists();

    $result = runStructuredTermImport([]);

    expect($result['imported'])->toBe(0)
        ->and($result['tree']->saves)->toBe(0);
});

it('carries a three-level chain through to the tree', function (): void {
    structuredTermCollectionExists();

    $result = runStructuredTermImport([
        structuredTerm('10', 'health', 'Health'),
        structuredTerm('20', 'cardiology', 'Cardiology', parentSlug: 'health'),
        structuredTerm('30', 'surgery', 'Surgery', parentSlug: 'cardiology'),
    ]);

    expect($result['tree']->nodeField('30', 'parent'))->toBe('20')
        ->and($result['tree']->nodeField('20', 'parent'))->toBe('10')
        ->and($result['tree']->nodeField('10', 'parent'))->toBe('')
        ->and($result['imported'])->toBe(3);
});

it('routes the people taxonomies into the shared people_groups collection', function (): void {
    structuredTermCollectionExists();

    runStructuredTermImport(
        [structuredTerm('40', 'faculty', 'Faculty')],
        'uamspeople-categories',
        'people_groups'
    );

    expect(TermDestinationRegistry::resolve('uamspeople-categories', '40'))->toBe([
        'shape' => 'collection',
        'handle' => 'people_groups',
        'reference' => 'entry-faculty',
    ]);
});

it('keeps the first taxonomy in the tree when a second shares the destination', function (): void {
    structuredTermCollectionExists();

    // `uamspeople-categories` and `tshowcase-categories` both land in
    // `people_groups`, and they arrive in separate passes. Writing only the
    // second pass's nodes would leave the first taxonomy's entries created but
    // outside the structure — present on disk, unreachable by the route.
    $importer = new StructuredTermImporterProbe;

    runStructuredTermImport(
        [structuredTerm('40', 'faculty', 'Faculty')],
        'uamspeople-categories',
        'people_groups',
        $importer
    );

    runStructuredTermImport(
        [structuredTerm('50', 'showcase', 'Showcase')],
        'tshowcase-categories',
        'people_groups',
        $importer
    );

    expect($importer->nodeField('40', 'entry'))->toBe('entry-faculty')
        ->and($importer->nodeField('50', 'entry'))->toBe('entry-showcase')
        ->and($importer->captured)->toHaveCount(2);
});

it('does not leak one destination\'s nodes into another', function (): void {
    structuredTermCollectionExists();

    $importer = new StructuredTermImporterProbe;

    runStructuredTermImport(
        [structuredTerm('40', 'faculty', 'Faculty')],
        'uamspeople-categories',
        'people_groups',
        $importer
    );

    runStructuredTermImport(
        [structuredTerm('10', 'health', 'Health')],
        'category',
        'post_categories',
        $importer
    );

    expect($importer->captured)->toHaveCount(1)
        ->and($importer->nodeField('10', 'entry'))->toBe('entry-health');
});

it('claims the destination collection for the site before writing any entry', function (): void {
    structuredTermCollectionExists();

    // An entry is saved with `locale($site)`, so its file lands under
    // `content/collections/{handle}/{site}/`. Statamic's CollectionEntriesStore
    // rejects any file whose site segment is not in the collection's own `sites:`
    // list, so without this claim the entries are written, the run reports
    // success, and the next cold Stache build drops them from the index.
    $result = runStructuredTermImport([
        structuredTerm('10', 'health', 'Health'),
    ]);

    expect($result['importer']->enabled)->toBe(['post_categories::default'])
        ->and($result['importer']->written)->not->toBeEmpty();
});

it('claims each destination and site pair it writes into', function (): void {
    structuredTermCollectionExists();

    $importer = new StructuredTermImporterProbe;

    runStructuredTermImport(
        [structuredTerm('40', 'faculty', 'Faculty')],
        'uamspeople-categories',
        'people_groups',
        $importer
    );

    $importer->import(
        [structuredTerm('60', 'research', 'Research')],
        'category',
        'post_categories',
        'news',
        new BufferedOutput
    );

    expect($importer->enabled)->toBe([
        'people_groups::default',
        'post_categories::news',
    ]);
});

it('reports progress as it saves the terms (#103)', function (): void {
    structuredTermCollectionExists();

    $output = new BufferedOutput;

    (new StructuredTermImporterProbe)->import(
        [
            structuredTerm('10', 'health', 'Health'),
            structuredTerm('20', 'cardiology', 'Cardiology', parentSlug: 'health'),
        ],
        'category',
        'post_categories',
        'default',
        $output
    );

    $lines = \array_values(\array_filter(
        \explode(\PHP_EOL, $output->fetch()),
        static fn (string $line): bool => \str_starts_with($line, 'Progress: ')
    ));

    expect($lines)->toBe([
        'Progress: post_categories terms saved 0 of 2 (0%)',
        'Progress: post_categories terms saved 1 of 2 (50%)',
        'Progress: post_categories terms saved 2 of 2 (100%)',
    ]);
});

it('does not claim a site when the destination collection is missing', function (): void {
    CollectionFacade::shouldReceive('findByHandle')->andReturn(null);

    $result = runStructuredTermImport([
        structuredTerm('10', 'health', 'Health'),
    ]);

    expect($result['importer']->enabled)->toBe([]);
});

it('does not merge one site\'s terms into another site\'s tree', function (): void {
    structuredTermCollectionExists();

    // The accumulator is keyed by collection AND site. Dropping the site would
    // pool every site's terms into one tree — each site's structure would then
    // list entries that do not belong to it.
    $importer = new StructuredTermImporterProbe;

    $importer->import(
        [structuredTerm('10', 'health', 'Health')],
        'category',
        'post_categories',
        'default',
        new BufferedOutput
    );

    $importer->import(
        [structuredTerm('60', 'research', 'Research')],
        'category',
        'post_categories',
        'news',
        new BufferedOutput
    );

    expect($importer->captured)->toHaveCount(1)
        ->and($importer->nodeField('60', 'entry'))->toBe('entry-research')
        ->and($importer->nodeField('10', 'entry'))->toBeNull();
});

it('stamps the parent entry id onto each child entry', function (): void {
    structuredTermCollectionExists();

    // The tree alone does not satisfy the app's `:parent:is="<id>"` child query,
    // which reads a data field — a tree-only parent renders correctly in the CP
    // and returns nothing on the front end (wordpress-importer-archive#422).
    $result = runStructuredTermImport([
        structuredTerm('10', 'health', 'Health'),
        structuredTerm('20', 'cardiology', 'Cardiology', parentSlug: 'health'),
        structuredTerm('30', 'surgery', 'Surgery', parentSlug: 'cardiology'),
    ]);

    expect($result['importer']->parents)->toBe([
        'entry-cardiology' => 'entry-health',
        'entry-surgery' => 'entry-cardiology',
    ]);
});

it('stamps no parent on a root or an orphan', function (): void {
    structuredTermCollectionExists();

    $result = runStructuredTermImport([
        structuredTerm('10', 'health', 'Health'),
        structuredTerm('40', 'stray', 'Stray', parentSlug: 'not-in-this-export'),
    ]);

    expect($result['importer']->parents)->toBe([]);
});

it('nests the tree by parent edge', function (): void {
    $tree = StructuredTermImporter::buildNestedTree([
        '10' => ['entry' => 'entry-health', 'parent' => '', 'title' => 'Health'],
        '20' => ['entry' => 'entry-cardiology', 'parent' => '10', 'title' => 'Cardiology'],
        '30' => ['entry' => 'entry-surgery', 'parent' => '20', 'title' => 'Surgery'],
    ]);

    expect($tree)->toBe([
        [
            'entry' => 'entry-health',
            'children' => [
                [
                    'entry' => 'entry-cardiology',
                    'children' => [
                        ['entry' => 'entry-surgery'],
                    ],
                ],
            ],
        ],
    ]);
});

it('promotes a term whose parent is not in the export to a root', function (): void {
    $tree = StructuredTermImporter::buildNestedTree([
        '20' => ['entry' => 'entry-cardiology', 'parent' => '99', 'title' => 'Cardiology'],
    ]);

    expect($tree)->toBe([['entry' => 'entry-cardiology']]);
});

it('keeps both terms of a parent cycle instead of losing them', function (): void {
    // Two terms naming each other. Without the visited guard this recurses until
    // the stack runs out; without promoting unreached nodes, neither term is
    // reachable from a root and both vanish from the tree silently — which is
    // worse than the crash, because the import still reports success.
    $tree = StructuredTermImporter::buildNestedTree([
        '10' => ['entry' => 'entry-a', 'parent' => '20', 'title' => 'A'],
        '20' => ['entry' => 'entry-b', 'parent' => '10', 'title' => 'B'],
    ]);

    $flattened = json_encode($tree);

    expect($flattened)->toContain('entry-a')
        ->and($flattened)->toContain('entry-b');
});

it('treats a term that is its own parent as a root', function (): void {
    $tree = StructuredTermImporter::buildNestedTree([
        '10' => ['entry' => 'entry-a', 'parent' => '10', 'title' => 'A'],
    ]);

    expect($tree)->toBe([['entry' => 'entry-a']]);
});

it('orders siblings by title so a re-import is stable', function (): void {
    $tree = StructuredTermImporter::buildNestedTree([
        '10' => ['entry' => 'entry-zebra', 'parent' => '', 'title' => 'Zebra'],
        '20' => ['entry' => 'entry-alpha', 'parent' => '', 'title' => 'Alpha'],
    ]);

    expect($tree)->toBe([
        ['entry' => 'entry-alpha'],
        ['entry' => 'entry-zebra'],
    ]);
});

it('does not stamp a term that is its own parent as its own parent', function (): void {
    structuredTermCollectionExists();

    // ---
    // The mirror of "treats a term that is its own parent as a root": the tree
    // builder already drops this edge, so the stamper has to as well, or the two
    // disagree and `:parent:is="<id>"` returns the entry as its own child. The
    // edge became live for categories once `categoryToPseudoTerm()` started
    // carrying `<wp:category_parent>` (wordpress-importer-archive#422).
    // ---
    $result = runStructuredTermImport([
        structuredTerm('10', 'health', 'Health', parentSlug: 'health'),
    ]);

    expect($result['importer']->parents)->toBe([])
        ->and($result['tree']->nodeField('10', 'parent'))->toBe('10');
});

it('drops the accumulated tree nodes when the taxonomy caches are cleared', function (): void {
    structuredTermCollectionExists();

    // ---
    // Nodes accumulate across passes ON PURPOSE within one run, so two taxonomies
    // sharing a destination both reach the tree. That accumulator is process
    // static, and `clearTaxonomyCaches()` marks the boundary between import
    // sessions — it resets the "the term pass already ran" flag and the
    // destination registry, so it has to reset this too. Otherwise a later run
    // publishes a tree still carrying the earlier run's nodes, whose entries may
    // no longer exist (wordpress-importer-archive#422).
    // ---
    $first = new StructuredTermImporterProbe;
    runStructuredTermImport([structuredTerm('40', 'faculty', 'Faculty')], 'category', 'post_categories', $first);
    expect($first->captured)->toHaveCount(1);

    BaseEntryCreator::clearTaxonomyCaches();

    $second = new StructuredTermImporterProbe;
    runStructuredTermImport([structuredTerm('50', 'showcase', 'Showcase')], 'category', 'post_categories', $second);

    expect($second->nodeField('50', 'entry'))->toBe('entry-showcase')
        ->and($second->nodeField('40', 'entry'))->toBeNull()
        ->and($second->captured)->toHaveCount(1);
});

it('reports a missing destination collection on the console and in the log', function (): void {
    CollectionFacade::shouldReceive('findByHandle')->andReturn(null);
    $log = Log::spy();

    $output = new BufferedOutput;

    $imported = (new StructuredTermImporterProbe)->import(
        [structuredTerm('10', 'health', 'Health')],
        'category',
        'post_categories',
        'default',
        $output
    );

    expect($imported)->toBe(0)
        ->and($output->fetch())->toBe(
            "  - Collection 'post_categories' not found; skipping 'category' terms.".\PHP_EOL
        );

    $log->shouldHaveReceived('warning', [
        'StructuredTermImporter::import: destination collection is missing.',
        [
            'wordpress_taxonomy' => 'category',
            'collection' => 'post_categories',
            'site' => 'default',
        ],
    ]);
});

it('claims no site and prints nothing when the export carries no usable terms', function (): void {
    structuredTermCollectionExists();

    $importer = new StructuredTermImporterProbe;
    $output = new BufferedOutput;

    $imported = $importer->import(
        [structuredTerm('', 'no-id', 'No Id')],
        'category',
        'post_categories',
        'default',
        $output
    );

    expect($imported)->toBe(0)
        ->and($importer->enabled)->toBe([])
        ->and($output->fetch())->toBe('');
});

it('reports how many terms it imported into the collection', function (): void {
    structuredTermCollectionExists();

    $output = new BufferedOutput;

    (new StructuredTermImporterProbe)->import(
        [
            structuredTerm('10', 'health', 'Health'),
            structuredTerm('20', 'cardiology', 'Cardiology', parentSlug: 'health'),
        ],
        'category',
        'post_categories',
        'default',
        $output
    );

    expect(\explode(\PHP_EOL, $output->fetch()))
        ->toContain("  - Imported 2 'category' term(s) into 'post_categories'.");
});

it('skips a term whose entry was not saved and imports the terms after it', function (): void {
    structuredTermCollectionExists();

    $importer = new StructuredTermImporterProbe;
    $importer->unsaved = ['health'];

    $imported = $importer->import(
        [
            structuredTerm('10', 'health', 'Health'),
            structuredTerm('20', 'cardiology', 'Cardiology'),
        ],
        'category',
        'post_categories',
        'default',
        new BufferedOutput
    );

    expect($imported)->toBe(1)
        ->and($importer->captured)->toBe([
            20 => ['entry' => 'entry-cardiology', 'parent' => '', 'title' => 'Cardiology'],
        ])
        ->and(TermDestinationRegistry::referenceFor('category', '10'))->toBeNull()
        ->and(TermDestinationRegistry::referenceFor('category', '20'))->toBe('entry-cardiology');
});

it('writes no tree and reports nothing when no entry was saved', function (): void {
    structuredTermCollectionExists();

    $importer = new StructuredTermImporterProbe;
    $importer->unsaved = ['health'];
    $output = new BufferedOutput;

    $imported = $importer->import(
        [structuredTerm('10', 'health', 'Health')],
        'category',
        'post_categories',
        'default',
        $output
    );

    expect($imported)->toBe(0)
        ->and($importer->saves)->toBe(0)
        ->and($importer->parents)->toBe([])
        ->and(\str_contains($output->fetch(), 'Imported'))->toBeFalse();
});

it('stamps the later children when an earlier one names an unsaved parent', function (): void {
    structuredTermCollectionExists();

    // A parent whose entry was not saved is not a node, so its child has no
    // parent entry to record; the children after it still have theirs.
    $importer = new StructuredTermImporterProbe;
    $importer->unsaved = ['stray-parent'];

    $importer->import(
        [
            structuredTerm('5', 'stray-parent', 'Stray Parent'),
            structuredTerm('6', 'stray-child', 'Stray Child', parentSlug: 'stray-parent'),
            structuredTerm('10', 'health', 'Health'),
            structuredTerm('20', 'cardiology', 'Cardiology', parentSlug: 'health'),
        ],
        'category',
        'post_categories',
        'default',
        new BufferedOutput
    );

    expect($importer->parents)->toBe(['entry-cardiology' => 'entry-health']);
});

it('stamps the later children when an earlier term names itself', function (): void {
    structuredTermCollectionExists();

    $importer = new StructuredTermImporterProbe;

    $importer->import(
        [
            structuredTerm('10', 'health', 'Health', parentSlug: 'health'),
            structuredTerm('20', 'cardiology', 'Cardiology', parentSlug: 'health'),
        ],
        'category',
        'post_categories',
        'default',
        new BufferedOutput
    );

    expect($importer->parents)->toBe(['entry-cardiology' => 'entry-health']);
});

it('records each node\'s title for the tree', function (): void {
    structuredTermCollectionExists();

    $result = runStructuredTermImport([
        structuredTerm('10', 'health', 'Health'),
    ]);

    expect($result['tree']->nodeField('10', 'title'))->toBe('Health');
});

it('nests a child listed ahead of its parent', function (): void {
    $tree = StructuredTermImporter::buildNestedTree([
        '20' => ['entry' => 'entry-cardiology', 'parent' => '10', 'title' => 'Cardiology'],
        '10' => ['entry' => 'entry-health', 'parent' => '', 'title' => 'Health'],
    ]);

    expect($tree)->toBe([
        ['entry' => 'entry-health', 'children' => [['entry' => 'entry-cardiology']]],
    ]);
});

it('keeps a parent cycle that follows a real root', function (): void {
    $tree = StructuredTermImporter::buildNestedTree([
        '1' => ['entry' => 'entry-root', 'parent' => '', 'title' => 'Root'],
        '10' => ['entry' => 'entry-a', 'parent' => '20', 'title' => 'A'],
        '20' => ['entry' => 'entry-b', 'parent' => '10', 'title' => 'B'],
    ]);

    expect($tree)->toBe([
        ['entry' => 'entry-a', 'children' => [['entry' => 'entry-b']]],
        ['entry' => 'entry-root'],
    ]);
});

it('keeps the sibling after the edge that closes a parent cycle', function (): void {
    // Promoting A reaches B, whose children are A (already placed, closing the
    // cycle) and then C, which has to be placed under B rather than lost there.
    $tree = StructuredTermImporter::buildNestedTree([
        '10' => ['entry' => 'entry-a', 'parent' => '20', 'title' => 'A'],
        '20' => ['entry' => 'entry-b', 'parent' => '10', 'title' => 'B'],
        '30' => ['entry' => 'entry-c', 'parent' => '20', 'title' => 'C'],
    ]);

    expect($tree)->toBe([
        [
            'entry' => 'entry-a',
            'children' => [
                ['entry' => 'entry-b', 'children' => [['entry' => 'entry-c']]],
            ],
        ],
    ]);
});

it('orders a promoted root among the real roots by title', function (): void {
    $tree = StructuredTermImporter::buildNestedTree([
        '10' => ['entry' => 'entry-zebra', 'parent' => '', 'title' => 'Zebra'],
        '20' => ['entry' => 'entry-alpha', 'parent' => '99', 'title' => 'Alpha'],
    ]);

    expect($tree)->toBe([
        ['entry' => 'entry-alpha'],
        ['entry' => 'entry-zebra'],
    ]);
});

it('orders children by title, not by entry id or input order', function (): void {
    // The entry ids sort the opposite way to the titles, so a sort on the id
    // and no sort at all both give the wrong order.
    $tree = StructuredTermImporter::buildNestedTree([
        '10' => ['entry' => 'entry-parent', 'parent' => '', 'title' => 'Parent'],
        '20' => ['entry' => 'entry-a', 'parent' => '10', 'title' => 'Zebra'],
        '30' => ['entry' => 'entry-b', 'parent' => '10', 'title' => 'Alpha'],
        '40' => ['entry' => 'entry-y', 'parent' => '', 'title' => 'Apex'],
    ]);

    expect($tree)->toBe([
        ['entry' => 'entry-y'],
        [
            'entry' => 'entry-parent',
            'children' => [
                ['entry' => 'entry-b'],
                ['entry' => 'entry-a'],
            ],
        ],
    ]);
});

it('skips an item that is not a term element and imports the terms after it', function (): void {
    structuredTermCollectionExists();

    $result = runStructuredTermImport([
        'not-a-term',
        structuredTerm('10', 'health', 'Health'),
    ]);

    expect($result['imported'])->toBe(1)
        ->and($result['importer']->written)->toBe([['slug' => 'health', 'title' => 'Health']]);
});

it('imports the terms after a repeated term id', function (): void {
    structuredTermCollectionExists();

    $result = runStructuredTermImport([
        structuredTerm('10', 'health', 'Health'),
        structuredTerm('10', 'health-duplicate', 'Health Duplicate'),
        structuredTerm('20', 'cardiology', 'Cardiology'),
    ]);

    expect($result['imported'])->toBe(2)
        ->and(\array_column($result['importer']->written, 'slug'))->toBe(['health', 'cardiology']);
});

it('keeps an orphan ahead of a root of the same title, in export order', function (): void {
    // The sort is stable, so roots of one title stay in the order they were
    // grouped. An orphan is grouped with the roots as it is read, not appended
    // after them, so it keeps its place in the export.
    $tree = StructuredTermImporter::buildNestedTree([
        '20' => ['entry' => 'entry-orphan', 'parent' => '99', 'title' => 'Health'],
        '10' => ['entry' => 'entry-root', 'parent' => '', 'title' => 'Health'],
    ]);

    expect($tree)->toBe([
        ['entry' => 'entry-orphan'],
        ['entry' => 'entry-root'],
    ]);
});

it('keeps a self-parented term ahead of a root of the same title, in export order', function (): void {
    $tree = StructuredTermImporter::buildNestedTree([
        '20' => ['entry' => 'entry-self', 'parent' => '20', 'title' => 'Health'],
        '10' => ['entry' => 'entry-root', 'parent' => '', 'title' => 'Health'],
    ]);

    expect($tree)->toBe([
        ['entry' => 'entry-self'],
        ['entry' => 'entry-root'],
    ]);
});
