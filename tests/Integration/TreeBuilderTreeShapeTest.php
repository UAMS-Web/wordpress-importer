<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Collection as IlluminateCollection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File as IlluminateFileFacade;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use Statamic\Contracts\Entries\CollectionRepository;
use Statamic\Entries\Collection as StatamicCollection;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Stache as StacheFacade;
use Statamic\Stache\Repositories\CollectionTreeRepository;
use Statamic\Structures\CollectionStructure;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Structures\TreeBuilder;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

/**
 * Tests the tree `TreeBuilder::buildTree()` assembles, and the duplicate-file cleanup that
 * runs after the tree is saved.
 *
 * `TreeBuilderBranchTest` covers `buildAndSaveTree()`'s refusals and a few tree shapes through
 * a saved tree. This file calls `buildTree()` directly, so each skip, default and warning can
 * be asserted on its own, and runs `cleanupDuplicateEntryFiles()` against a stand-in entries
 * store whose directory and paths index the test controls.
 *
 * @command  php vendor/bin/pest tests/Integration/TreeBuilderTreeShapeTest.php
 */
uses(StatamicTestCase::class);

beforeEach(function (): void {
    TreeBuilder::resetProcessedCollections();
    ProcessingContext::clearWordPressPageLink();
    ProcessingContext::clearFormConfigContext();
    ImportWarningManager::clearWarnings();
});

afterEach(function (): void {
    TreeBuilder::resetProcessedCollections();
    Mockery::close();

    foreach (TreeShapeTemporaryRoots::$roots as $root) {
        (new Filesystem)->deleteDirectory($root);
    }

    TreeShapeTemporaryRoots::$roots = [];
});

/**
 * The temporary content roots a test made, removed after it.
 */
final class TreeShapeTemporaryRoots
{
    /**
     * @var list<string> The roots to remove
     */
    public static array $roots = [];
}

/**
 * A tree builder whose protected steps can be called one at a time.
 */
final class TreeShapeBuilder extends TreeBuilder
{
    /**
     * Build a tree from page data.
     *
     * @param  array<int|string, mixed>  $pageData  Page data keyed by WordPress post id
     * @param  ?string  $baseBlogUrl  The base blog URL
     * @return array<int|string, mixed> The tree
     */
    public function treeFor(
        array $pageData,
        ?string $baseBlogUrl = null
    ): array {
        return $this->buildTree($pageData, $baseBlogUrl);
    }

    /**
     * Remove the duplicate entry files for a collection and site.
     *
     * @param  string  $collectionHandle  The collection handle
     * @param  string  $site  The site handle
     * @param  OutputInterface  $output  Console output
     */
    public function cleanup(
        string $collectionHandle,
        string $site,
        OutputInterface $output
    ): void {
        $this->cleanupDuplicateEntryFiles($collectionHandle, $site, $output);
    }
}

/**
 * A tree builder over a mocked tree repository, which none of these steps reads.
 */
function treeShapeBuilder(): TreeShapeBuilder
{
    /** @var CollectionTreeRepository&MockInterface $repository */
    $repository = Mockery::mock(CollectionTreeRepository::class);

    return new TreeShapeBuilder($repository);
}

/**
 * A stand-in for one collection's Stache store: a paths index the test fills, and a count of
 * the times its cached paths were cleared.
 */
final class TreeShapeCollectionStore
{
    /**
     * @var array<string, string> The canonical file path for each entry id
     */
    public array $paths = [];

    /**
     * @var int How many times the cached paths were cleared
     */
    public int $cleared = 0;

    /**
     * Count a cache clear.
     */
    public function clearCachedPaths(): void
    {
        $this->cleared++;
    }

    /**
     * The paths index.
     *
     * @return IlluminateCollection<string, string> The canonical file path for each entry id
     */
    public function paths(): IlluminateCollection
    {
        return new IlluminateCollection($this->paths);
    }
}

/**
 * A stand-in for the Stache entries store: the content directory the cleanup scans, and the
 * one collection store it hands out.
 */
final class TreeShapeEntriesStore
{
    /**
     * Keep the directory and the collection store.
     *
     * @param  string  $directory  The entries directory
     * @param  TreeShapeCollectionStore  $collection  The collection store to hand out
     */
    public function __construct(
        public readonly string $directory,
        public readonly TreeShapeCollectionStore $collection
    ) {}

    /**
     * The entries directory, with the trailing separator Statamic gives it.
     */
    public function directory(): string
    {
        return $this->directory.'/';
    }

    /**
     * The collection store, whatever handle is asked for.
     *
     * @param  string  $handle  The collection handle
     */
    public function store(string $handle): TreeShapeCollectionStore
    {
        return $this->collection;
    }
}

/**
 * Point the Stache facade's entries store at a fresh temporary directory.
 *
 * @return array{dir: string, store: TreeShapeCollectionStore} The site's directory, and the collection store
 */
function treeShapeStache(): array
{
    $root = \rtrim(\sys_get_temp_dir(), '/\\').'/wpimport-tree-'.\uniqid();
    $dir = $root.'/pages/default';
    \mkdir($dir, 0777, true);

    $collectionStore = new TreeShapeCollectionStore;
    $entries = new TreeShapeEntriesStore($root, $collectionStore);

    StacheFacade::shouldReceive('store')->with('entries')->andReturn($entries);

    TreeShapeTemporaryRoots::$roots[] = $root;

    return ['dir' => $dir, 'store' => $collectionStore];
}

/**
 * Point the Collection facade at a mocked `pages` collection.
 *
 * @param  bool  $dated  Whether the collection is dated
 * @param  ?object  $tree  The tree its structure holds, or null for a collection with no structure
 */
function treeShapeCollection(bool $dated = false, ?object $tree = null): void
{
    $structure = Mockery::mock(CollectionStructure::class);
    $structure->allows(['in' => $tree, 'makeTree' => $tree]);

    $collection = Mockery::mock(StatamicCollection::class);
    $collection->allows(['hasStructure' => $tree !== null, 'structure' => $structure, 'dated' => $dated, 'save' => null]);

    $repository = Mockery::mock(CollectionRepository::class);
    $repository->allows(['findByHandle' => $collection]);
    CollectionFacade::swap($repository);
}

/**
 * Write an entry file.
 *
 * @param  string  $dir  The site's directory
 * @param  string  $name  The file name
 * @param  ?string  $id  The entry id, or null for a file with none
 * @return string The file's path
 */
function treeShapeFile(string $dir, string $name, ?string $id): string
{
    $path = $dir.'/'.$name;
    \file_put_contents($path, $id === null ? "---\ntitle: No id\n---\n" : "---\nid: {$id}\ntitle: {$name}\n---\n");

    return $path;
}

/**
 * The files left in a directory, by name.
 *
 * @param  string  $dir  The directory
 * @return list<string> The file names, sorted
 */
function treeShapeFilesIn(string $dir): array
{
    $found = \glob($dir.'/*');
    $names = \array_map(static fn (string $path): string => \basename($path), \is_array($found) ? $found : []);
    \sort($names);

    return $names;
}

/**
 * Collect the warnings the log receives.
 *
 * @return ArrayObject<int, array{string, mixed}> Each warning's message and context, as they arrive
 */
function treeShapeWarnings(): ArrayObject
{
    /** @var ArrayObject<int, array{string, mixed}> $warnings */
    $warnings = new ArrayObject;

    Event::listen(MessageLogged::class, static function (MessageLogged $logged) use ($warnings): void {
        if ($logged->level === 'warning') {
            $warnings->append([$logged->message, $logged->context]);
        }
    });

    return $warnings;
}

/**
 * One page, in the shape `EntryCreator` hands over.
 *
 * @param  string  $entry  The entry id
 * @param  string  $parent  The parent's WordPress post id, '0' for none
 * @param  int  $order  The menu order
 * @param  ?string  $link  The page's link
 * @return array{entry: string, parent: string, menu_order: int, title: string, link: ?string}
 */
function treeShapePage(string $entry, string $parent = '0', int $order = 0, ?string $link = null): array
{
    return ['entry' => $entry, 'parent' => $parent, 'menu_order' => $order, 'title' => \ucfirst($entry), 'link' => $link];
}

it('skips a row that is not a page, and builds the pages after it', function (?string $baseBlogUrl): void {
    $tree = treeShapeBuilder()->treeFor([
        '1' => 'not a page',
        '2' => treeShapePage('about', link: 'https://x.test/about/'),
    ], $baseBlogUrl);

    expect($tree)->toBe([['entry' => 'about']]);
})->with(['with a base URL' => ['https://x.test'], 'without one' => [null]]);

it('takes the first page whose link is the base URL as the front page, ignoring trailing slashes', function (): void {
    $warnings = treeShapeWarnings();

    $tree = treeShapeBuilder()->treeFor([
        '1' => treeShapePage('no-link', order: 1),
        '2' => treeShapePage('home', order: 5, link: 'https://x.test'),
        '3' => treeShapePage('also-home', order: 2, link: 'https://x.test/'),
    ], 'https://x.test/');

    expect($tree)->toBe([['entry' => 'home'], ['entry' => 'no-link'], ['entry' => 'also-home']])
        ->and($warnings->getArrayCopy())->toBe([]);
});

it('finds no front page for an empty base URL', function (): void {
    // With an empty base URL, a link of "/" would otherwise trim to the same empty string
    $tree = treeShapeBuilder()->treeFor([
        '1' => treeShapePage('slash', order: 5, link: '/'),
        '2' => treeShapePage('first', order: 1),
    ], '');

    expect($tree)->toBe([['entry' => 'first'], ['entry' => 'slash']]);
});

it('does not take a page with an empty link as the front page of "/"', function (): void {
    $tree = treeShapeBuilder()->treeFor([
        '1' => treeShapePage('empty-link', order: 5, link: ''),
        '2' => treeShapePage('first', order: 1),
    ], '/');

    expect($tree)->toBe([['entry' => 'first'], ['entry' => 'empty-link']]);
});

it('skips a page with no entry, builds the pages after it, and does not report it missing', function (): void {
    $warnings = treeShapeWarnings();

    $tree = treeShapeBuilder()->treeFor([
        '1' => treeShapePage(''),
        '2' => ['entry' => [], 'parent' => '0', 'title' => 'Array entry'],
        '3' => treeShapePage('about'),
    ]);

    expect($tree)->toBe([['entry' => 'about']])
        ->and($warnings->getArrayCopy())->toBe([]);
});

it('finds the front page after a row that is not a page', function (): void {
    $tree = treeShapeBuilder()->treeFor([
        '1' => 'not a page',
        '2' => treeShapePage('about', order: 1),
        '3' => treeShapePage('home', order: 9, link: 'https://x.test/'),
    ], 'https://x.test');

    expect($tree)->toBe([['entry' => 'home'], ['entry' => 'about']]);
});

it('files no child under a parent with an empty entry, even beside a front page with an empty entry', function (): void {
    $tree = treeShapeBuilder()->treeFor([
        '1' => treeShapePage('', link: 'https://x.test/'),
        '2' => treeShapePage(''),
        '3' => treeShapePage('child', parent: '2'),
    ], 'https://x.test');

    expect($tree)->toBe([['entry' => '']]);
});

it('places a page with an empty parent at the top level as a root', function (): void {
    $warnings = treeShapeWarnings();

    $tree = treeShapeBuilder()->treeFor([
        '1' => ['entry' => 'empty-parent', 'parent' => '', 'menu_order' => 5, 'title' => 'Empty parent'],
        '2' => treeShapePage('later', order: 9),
        '3' => treeShapePage('first', order: 1),
    ]);

    expect($tree)->toBe([['entry' => 'first'], ['entry' => 'empty-parent'], ['entry' => 'later']])
        ->and($warnings->getArrayCopy())->toBe([]);
});

it('skips a child whose parent row is not a page or has no entry, and keeps the rest', function (): void {
    $warnings = treeShapeWarnings();

    $tree = treeShapeBuilder()->treeFor([
        '1' => 'not a page',
        '2' => treeShapePage('child-of-junk', parent: '1'),
        '3' => treeShapePage(''),
        '4' => treeShapePage('child-of-empty', parent: '3'),
        '5' => treeShapePage('parent'),
        '6' => treeShapePage('child', parent: '5'),
    ]);

    expect($tree)->toBe([['entry' => 'parent', 'children' => [['entry' => 'child']]]])
        ->and(\array_column($warnings->getArrayCopy(), 0))->toBe(['TreeBuilder::buildTree: Pages missing from final tree']);
});

it('logs an orphan with its post id, entry, parent and the size of the import', function (): void {
    $warnings = treeShapeWarnings();

    $tree = treeShapeBuilder()->treeFor([
        '7' => treeShapePage('orphan', parent: '99', link: 'https://x.test/orphan/'),
        '8' => treeShapePage('root', order: 9),
    ]);

    expect($tree)->toBe([['entry' => 'root'], ['entry' => 'orphan']])
        ->and($warnings->getArrayCopy())->toBe([[
            'TreeBuilder::buildTree: Parent not found for page; marking as unassigned (from entry: https://x.test/orphan/)',
            [
                'postId' => 7,
                'entry' => 'orphan',
                'parentId' => '99',
                'parentInPageData' => false,
                'totalPagesInData' => 2,
                'entry_url' => 'https://x.test/orphan/',
            ],
        ]]);
});

/**
 * A page whose parent is not in the import goes to the top level with its descendants nested
 * under it, as a root page does (#251).
 */
it('keeps the descendants of an orphaned page under it', function (): void {
    $warnings = treeShapeWarnings();

    $tree = treeShapeBuilder()->treeFor([
        '2' => treeShapePage('section', parent: '99', link: 'https://x.test/section/'),
        '3' => treeShapePage('page', parent: '2'),
        '4' => treeShapePage('subpage', parent: '3'),
    ]);

    expect($tree)->toBe([
        ['entry' => 'section', 'children' => [
            ['entry' => 'page', 'children' => [['entry' => 'subpage']]],
        ]],
    ])->and(\array_column($warnings->getArrayCopy(), 0))->toBe([
        'TreeBuilder::buildTree: Parent not found for page; marking as unassigned (from entry: https://x.test/section/)',
    ]);
});

/**
 * The front page leaves the page data before the hierarchy is built, so its children are
 * orphans: each stays at the top level, after the front page, with its own children (#251).
 */
it('keeps the children of a child of the front page', function (): void {
    $warnings = treeShapeWarnings();

    $tree = treeShapeBuilder()->treeFor([
        '1' => treeShapePage('home', link: 'https://x.test/'),
        '2' => treeShapePage('child', parent: '1', link: 'https://x.test/child/'),
        '3' => treeShapePage('grand', parent: '2', link: 'https://x.test/child/grand/'),
    ], 'https://x.test');

    expect($tree)->toBe([
        ['entry' => 'home'],
        ['entry' => 'child', 'children' => [['entry' => 'grand']]],
    ])->and(\array_column($warnings->getArrayCopy(), 0))->toBe([
        'TreeBuilder::buildTree: Parent not found for page; marking as unassigned (from entry: https://x.test/child/)',
    ]);
});

it('sorts the children of an orphaned page by menu order, and keeps orphans after the roots in import order', function (): void {
    $tree = treeShapeBuilder()->treeFor([
        '1' => treeShapePage('late-orphan', parent: '98'),
        '2' => treeShapePage('zebra', parent: '1', order: 2),
        '3' => treeShapePage('alpha', parent: '1', order: 1),
        '4' => treeShapePage('root', order: 5),
        '5' => treeShapePage('early-orphan', parent: '97'),
    ]);

    expect($tree)->toBe([
        ['entry' => 'root'],
        ['entry' => 'late-orphan', 'children' => [['entry' => 'alpha'], ['entry' => 'zebra']]],
        ['entry' => 'early-orphan'],
    ]);
});

/**
 * Children are filed under their parent's entry id, so a page sharing its entry id with an
 * ancestor would have that ancestor's children nested under it again, without end. It is
 * left out and logged, and the rest of the tree is built (#301).
 *
 * @param  array<int|string, mixed>  $pageData  The pages
 * @param  array<int|string, mixed>  $expected  The tree
 * @param  list<string>  $ancestors  The path the repeated entry was found on
 */
it('leaves out a page that shares its entry id with an ancestor, and builds the rest', function (array $pageData, array $expected, array $ancestors): void {
    $warnings = treeShapeWarnings();

    $tree = treeShapeBuilder()->treeFor($pageData);

    $repeats = \array_values(\array_filter(
        $warnings->getArrayCopy(),
        static fn (array $warning): bool => $warning[0] === 'TreeBuilder::buildTree: Page shares its entry with an ancestor; left out of the tree',
    ));

    expect($tree)->toBe($expected)
        ->and($repeats)->toBe([[
            'TreeBuilder::buildTree: Page shares its entry with an ancestor; left out of the tree',
            ['entry' => 'e', 'ancestors' => $ancestors],
        ]]);
})->with([
    'a child of a root' => [
        ['1' => treeShapePage('e'), '2' => treeShapePage('e', parent: '1')],
        [['entry' => 'e']],
        ['e'],
    ],
    'a grandchild of a root, beside a sibling' => [
        [
            '1' => treeShapePage('e'),
            '2' => treeShapePage('c', parent: '1'),
            '3' => treeShapePage('e', parent: '2', order: 1),
            '4' => treeShapePage('s', parent: '2', order: 2),
        ],
        [['entry' => 'e', 'children' => [['entry' => 'c', 'children' => [['entry' => 's']]]]]],
        ['e', 'c'],
    ],
    'a child of an orphan' => [
        ['1' => treeShapePage('e', parent: '99'), '2' => treeShapePage('e', parent: '1')],
        [['entry' => 'e']],
        ['e'],
    ],
]);

it('adds no orphan whose entry a root already holds', function (): void {
    $tree = treeShapeBuilder()->treeFor([
        '1' => treeShapePage('dup'),
        '2' => treeShapePage('dup', parent: '99'),
        '3' => treeShapePage('other', parent: '98'),
    ]);

    expect($tree)->toBe([['entry' => 'dup'], ['entry' => 'other']]);
});

it('matches an ancestor\'s entry exactly, so a numeric-looking id is not taken for another', function (): void {
    // '01' and '1' are equal only under loose comparison
    $tree = treeShapeBuilder()->treeFor([
        '1' => treeShapePage('1'),
        '2' => treeShapePage('01', parent: '1'),
    ]);

    expect($tree)->toBe([['entry' => '1', 'children' => [['entry' => '01']]]]);
});

it('reports every page a parent cycle leaves out of the tree', function (): void {
    $warnings = treeShapeWarnings();

    $tree = treeShapeBuilder()->treeFor([
        '0' => treeShapePage(''),
        '1' => treeShapePage('root'),
        '2' => treeShapePage('cycle-a', parent: '3'),
        '3' => treeShapePage('cycle-b', parent: '2'),
        '4' => ['parent' => '0', 'title' => 'No entry key'],
    ]);

    expect($tree)->toBe([['entry' => 'root']])
        ->and($warnings->getArrayCopy())->toBe([[
            'TreeBuilder::buildTree: Pages missing from final tree',
            ['missing_entries' => ['cycle-a' => 'cycle-a', 'cycle-b' => 'cycle-b']],
        ]]);
});

it('does not report the front page as missing when another page in a cycle shares its entry', function (): void {
    $warnings = treeShapeWarnings();

    treeShapeBuilder()->treeFor([
        '1' => treeShapePage('home', link: 'https://x.test/'),
        '2' => treeShapePage('home', parent: '3'),
        '3' => treeShapePage('cycle-b', parent: '2'),
    ], 'https://x.test');

    expect($warnings->getArrayCopy())->toBe([[
        'TreeBuilder::buildTree: Pages missing from final tree',
        ['missing_entries' => ['cycle-b' => 'cycle-b']],
    ]]);
});

it('nests the children filed under the front page\'s entry beneath it, cleaned and sorted', function (): void {
    // A second page sharing the front page's entry is the one way a child is filed under it,
    // since the front page itself has left the page data by then
    $tree = treeShapeBuilder()->treeFor([
        '1' => treeShapePage('home', link: 'https://x.test/'),
        '2' => treeShapePage('home', order: 3),
        '3' => treeShapePage('zebra', parent: '2', order: 2),
        '4' => treeShapePage('alpha', parent: '2', order: 1),
    ], 'https://x.test');

    expect($tree[0])->toBe(['entry' => 'home', 'children' => [['entry' => 'alpha'], ['entry' => 'zebra']]]);
});

it('sorts by menu order, then title, reading a missing order as 0 and a missing title as empty', function (): void {
    $tree = treeShapeBuilder()->treeFor([
        '1' => ['entry' => 'b-order-2', 'parent' => '0', 'menu_order' => 2, 'title' => 'B'],
        '2' => ['entry' => 'titled', 'parent' => '0', 'title' => 'Alpha'],
        '3' => ['entry' => 'untitled', 'parent' => '0'],
        '4' => ['entry' => 'a-order-2', 'parent' => '0', 'menu_order' => '2', 'title' => 'A'],
        '5' => ['entry' => 'order-1', 'parent' => '0', 'menu_order' => 1, 'title' => 'Zed'],
        '6' => ['entry' => 'array-title', 'parent' => '0', 'menu_order' => 1, 'title' => ['not', 'text']],
    ]);

    expect(\array_column($tree, 'entry'))->toBe(['untitled', 'titled', 'array-title', 'order-1', 'a-order-2', 'b-order-2']);
});

it('clears the collection\'s cached paths and removes duplicate files when it saves a tree', function (): void {
    ['dir' => $dir, 'store' => $store] = treeShapeStache();
    treeShapeFile($dir, 'a.md', 'dupe');
    $kept = treeShapeFile($dir, 'b.md', 'dupe');
    $store->paths = ['dupe' => $kept];

    $tree = new class
    {
        /**
         * Accept the tree.
         *
         * @param  array<int|string, mixed>  $tree  The tree
         */
        public function tree(array $tree): self
        {
            return $this;
        }

        /**
         * Accept the save.
         */
        public function save(): void {}
    };

    treeShapeCollection(tree: $tree);
    $output = new BufferedOutput;

    treeShapeBuilder()->buildAndSaveTree('default', 'page', 'pages', [
        '1' => treeShapePage('about'),
    ], $output);

    expect($store->cleared)->toBe(1)
        ->and(treeShapeFilesIn($dir))->toBe(['b.md'])
        ->and($output->fetch())->toContain('Deleted duplicate entry file: a.md (ID: dupe)');
});

it('keeps the indexed file of a duplicate pair, and logs what it removed and kept', function (): void {
    ['dir' => $dir, 'store' => $store] = treeShapeStache();
    treeShapeCollection();
    $log = Log::spy();

    treeShapeFile($dir, 'a.md', 'dupe');
    $store->paths = ['dupe' => treeShapeFile($dir, 'b.md', 'dupe')];
    $output = new BufferedOutput;

    treeShapeBuilder()->cleanup('pages', 'default', $output);

    expect(treeShapeFilesIn($dir))->toBe(['b.md'])
        ->and($output->fetch())->toBe('Deleted duplicate entry file: a.md (ID: dupe)'.\PHP_EOL);

    $log->shouldHaveReceived('info', [
        'TreeBuilder::cleanupDuplicateEntryFiles: Deleted duplicate file',
        [
            'collection' => 'pages',
            'site' => 'default',
            'entry_id' => 'dupe',
            'deleted_file' => 'a.md',
            'kept_file' => 'b.md',
        ],
    ]);
});

it('keeps the first file of a duplicate pair the index does not name', function (bool $dated): void {
    ['dir' => $dir] = treeShapeStache();
    treeShapeCollection(dated: $dated);

    treeShapeFile($dir, 'a.md', 'dupe');
    treeShapeFile($dir, 'b.md', 'dupe');

    treeShapeBuilder()->cleanup('pages', 'default', new BufferedOutput);

    expect(treeShapeFilesIn($dir))->toBe(['a.md']);
})->with(['an undated collection' => [false], 'a dated one' => [true]]);

it('keeps the indexed file of an undated pair in a dated collection', function (): void {
    ['dir' => $dir, 'store' => $store] = treeShapeStache();
    treeShapeCollection(dated: true);

    treeShapeFile($dir, 'a.md', 'dupe');
    $store->paths = ['dupe' => treeShapeFile($dir, 'b.md', 'dupe')];

    treeShapeBuilder()->cleanup('pages', 'default', new BufferedOutput);

    expect(treeShapeFilesIn($dir))->toBe(['b.md']);
});

it('keeps the indexed file when the paths index spells it with other slashes', function (): void {
    ['dir' => $dir, 'store' => $store] = treeShapeStache();
    treeShapeCollection();
    treeShapeFile($dir, 'a.md', 'dupe');
    $store->paths = ['dupe' => \str_replace('/', '\\', treeShapeFile($dir, 'b.md', 'dupe'))];

    treeShapeBuilder()->cleanup('pages', 'default', new BufferedOutput);

    expect(treeShapeFilesIn($dir))->toBe(['b.md']);
});

it('keeps the indexed file in an undated collection, even when another is date-prefixed', function (): void {
    ['dir' => $dir, 'store' => $store] = treeShapeStache();
    treeShapeCollection();

    treeShapeFile($dir, '2024-01-02-1200.news.md', 'dupe');
    $store->paths = ['dupe' => treeShapeFile($dir, 'news.md', 'dupe')];

    treeShapeBuilder()->cleanup('pages', 'default', new BufferedOutput);

    expect(treeShapeFilesIn($dir))->toBe(['news.md']);
});

it('keeps the first date-prefixed file of a group in a dated collection, whatever the index names', function (): void {
    ['dir' => $dir, 'store' => $store] = treeShapeStache();
    treeShapeCollection(dated: true);

    treeShapeFile($dir, '2024-01-02-1200.news.md', 'dupe');
    treeShapeFile($dir, '2024-03-04-0900.news.md', 'dupe');
    $store->paths = ['dupe' => treeShapeFile($dir, 'news.md', 'dupe')];

    treeShapeBuilder()->cleanup('pages', 'default', new BufferedOutput);

    expect(treeShapeFilesIn($dir))->toBe(['2024-01-02-1200.news.md']);
});

it('passes over empty files, unparseable files, files with no id and single files', function (): void {
    ['dir' => $dir] = treeShapeStache();
    treeShapeCollection();
    $warnings = treeShapeWarnings();

    \file_put_contents($dir.'/0-empty.md', '');
    $bad = $dir.'/1-bad.md';
    \file_put_contents($bad, "---\nid: [unclosed\n---\n");
    treeShapeFile($dir, '2-no-id.md', null);
    treeShapeFile($dir, '3-no-id.md', null);
    treeShapeFile($dir, '4-single.md', 'single');
    treeShapeFile($dir, '5-a.md', 'dupe');
    treeShapeFile($dir, '6-b.md', 'dupe');

    treeShapeBuilder()->cleanup('pages', 'default', new BufferedOutput);

    expect(treeShapeFilesIn($dir))->toBe(['0-empty.md', '1-bad.md', '2-no-id.md', '3-no-id.md', '4-single.md', '5-a.md'])
        ->and(\array_column($warnings->getArrayCopy(), 0))->toBe(['TreeBuilder::cleanupDuplicateEntryFiles: Failed to parse file']);

    $context = $warnings[0][1] ?? null;

    expect(\str_replace('\\', '/', \is_array($context) && \is_string($context['file'] ?? null) ? $context['file'] : ''))->toBe(\str_replace('\\', '/', $bad))
        ->and(\is_array($context) && \is_string($context['error'] ?? null) && $context['error'] !== '')->toBeTrue();
});

it('logs a file it cannot delete, and moves on', function (): void {
    ['dir' => $dir, 'store' => $store] = treeShapeStache();
    treeShapeCollection();
    $log = Log::spy();

    $first = treeShapeFile($dir, 'a.md', 'dupe');
    $store->paths = ['dupe' => treeShapeFile($dir, 'b.md', 'dupe')];

    // A filesystem whose deletes fail
    IlluminateFileFacade::swap(new class extends Filesystem
    {
        /**
         * Refuse every delete.
         *
         * @param  string|array<mixed>  $paths  The paths to delete
         */
        public function delete($paths): bool
        {
            throw new RuntimeException('locked');
        }
    });

    try {
        treeShapeBuilder()->cleanup('pages', 'default', new BufferedOutput);
    } finally {
        // Put the real filesystem back, so the temporary directory can be removed
        IlluminateFileFacade::swap(new Filesystem);
    }

    $log->shouldHaveReceived('error', [
        'TreeBuilder::cleanupDuplicateEntryFiles: Failed to delete duplicate file',
        [
            'collection' => 'pages',
            'site' => 'default',
            'entry_id' => 'dupe',
            'file' => \str_replace('\\', '/', \dirname($first)).\DIRECTORY_SEPARATOR.\basename($first),
            'error' => 'locked',
        ],
    ]);

    expect(ImportWarningManager::getWarnings('content', 'duplicate_entry_file_not_deleted'))->toHaveCount(1);
});

it('reports a delete that returns false as a failure, and still deletes the rest of the group', function (): void {
    ['dir' => $dir] = treeShapeStache();
    treeShapeCollection();
    $log = Log::spy();

    treeShapeFile($dir, 'a.md', 'dupe');
    treeShapeFile($dir, 'b.md', 'dupe');
    treeShapeFile($dir, 'c.md', 'dupe');
    $output = new BufferedOutput;

    // A filesystem that behaves as Laravel's does for a file it cannot remove
    $filesystem = new class extends Filesystem
    {
        /** @var mixed The path of the file that could not be removed */
        public mixed $stuck = null;

        /**
         * Return false for `b.md`, and delete anything else.
         *
         * @param  string|array<mixed>  $paths  The paths to delete
         */
        public function delete($paths): bool
        {
            if (\is_string($paths) && \basename($paths) === 'b.md') {
                $this->stuck = $paths;

                return false;
            }

            return parent::delete($paths);
        }
    };

    IlluminateFileFacade::swap($filesystem);

    try {
        treeShapeBuilder()->cleanup('pages', 'default', $output);
    } finally {
        // Put the real filesystem back, so the temporary directory can be removed
        IlluminateFileFacade::swap(new Filesystem);
    }

    $context = [
        'collection' => 'pages',
        'site' => 'default',
        'entry_id' => 'dupe',
        'file' => $filesystem->stuck,
        'error' => 'The filesystem could not delete the file',
    ];

    expect($filesystem->stuck)->toBeString()
        ->and(treeShapeFilesIn($dir))->toBe(['a.md', 'b.md'])
        ->and($output->fetch())->toBe('Deleted duplicate entry file: c.md (ID: dupe)'.\PHP_EOL)
        ->and(ImportWarningManager::getWarnings('content', 'duplicate_entry_file_not_deleted'))->toBe([$context]);

    $log->shouldHaveReceived('error', ['TreeBuilder::cleanupDuplicateEntryFiles: Failed to delete duplicate file', $context]);
    $log->shouldNotHaveReceived('info', [
        'TreeBuilder::cleanupDuplicateEntryFiles: Deleted duplicate file',
        [
            'collection' => 'pages',
            'site' => 'default',
            'entry_id' => 'dupe',
            'deleted_file' => 'b.md',
            'kept_file' => 'a.md',
        ],
    ]);
    $log->shouldHaveReceived('info', [
        'TreeBuilder::cleanupDuplicateEntryFiles: Deleted duplicate file',
        [
            'collection' => 'pages',
            'site' => 'default',
            'entry_id' => 'dupe',
            'deleted_file' => 'c.md',
            'kept_file' => 'a.md',
        ],
    ]);
});
