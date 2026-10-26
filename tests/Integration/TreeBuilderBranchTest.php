<?php

declare(strict_types=1);

use Mockery\CompositeExpectation;
use Mockery\Expectation;
use Mockery\ExpectationInterface;
use Mockery\LegacyMockInterface;
use Mockery\MockInterface;
use Statamic\Contracts\Entries\CollectionRepository;
use Statamic\Entries\Collection as StatamicCollection;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Stache\Repositories\CollectionTreeRepository;
use Statamic\Structures\CollectionStructure;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Structures\TreeBuilder;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * Covers `TreeBuilder::buildAndSaveTree()`'s refusal branches and its happy path
 * directly (issue wordpress-importer-archive#530).
 *
 * The retired PHPUnit command test carried six `test_tree_building_*` cases named
 * for these branches. After name normalization all six bodies were byte-identical:
 * each ran the import command up to an interactive prompt and asserted it got that
 * far. None constructed a structureless collection, none pre-marked a tree as
 * processed, and none asserted anything about a saved tree — the branch names were
 * aspirational and the coverage was zero. wordpress-importer-archive#461 deliberately did not carry them over;
 * this file covers them on `TreeBuilder` itself, where they belong.
 *
 * Base class: `StatamicTestCase` (`tests/Integration`). The branches need the
 * `Collection` facade and a booted container, but no real collections, entries or
 * Stache content — the two collaborators that would otherwise demand them,
 * `clearCollectionCache()` and `cleanupDuplicateEntryFiles()`, both guard themselves
 * and no-op when the store and the content directory are absent, which is the case
 * under Testbench. That is squarely the light-Statamic band described in
 * `tests/CLAUDE.md`, and it runs in package CI where a `tests/Feature` version
 * would not.
 *
 * **Mutation residue.** Run:
 *
 *     vendor/bin/pest tests/Integration/TreeBuilderBranchTest.php --mutate \
 *       --path=src/Services/WordPressImport/Structures \
 *       --class="UAMSWeb\WordPressImporter\Services\WordPressImport\Structures\TreeBuilder" \
 *       --covered-only
 *
 * Every mutant inside `buildAndSaveTree()` itself is killed except three, and all
 * three are recorded rather than closed because none is closable from this tier:
 *
 * - `in_array($key, self::$processedCollections, true)` → `false`. The haystack is a
 *   list of `"handle:site"` strings and the needle is one too, so strict and loose
 *   comparison cannot differ. An equivalent mutant.
 * - Removing the `clearCollectionCache()` call, and removing the
 *   `cleanupDuplicateEntryFiles()` call. Both no-op under Testbench — the first
 *   returns early because there is no Stache entries store, the second because the
 *   content directory does not exist — so neither has an observable effect to
 *   assert. Killing them needs the booted host app and real content on disk, which
 *   is the `tests/Feature` tier, and wordpress-importer-archive#530 is explicitly test-only.
 *
 * Survivors beyond `buildAndSaveTree()` (in `buildTree()` and the private helpers)
 * are outside this ticket's stated scope, which is the six branches of the public
 * method plus the tree shape it produces.
 *
 * @command  php vendor/bin/pest tests/Integration/TreeBuilderBranchTest.php
 */
uses(StatamicTestCase::class);

beforeEach(function (): void {
    // The processed-collections guard is process-static: reset it going in...
    TreeBuilder::resetProcessedCollections();
});

afterEach(function (): void {
    // ...and going out, so no test inherits it and none leaks it to the next file.
    TreeBuilder::resetProcessedCollections();
    Mockery::close();
});

/**
 * A stand-in for Statamic's collection tree that records what was saved to it.
 *
 * `buildAndSaveTree()` reaches the tree through `method_exists()` rather than a type
 * hint, so a plain object carrying the two method names satisfies it.
 */
final class TreeBuilderProbeTree
{
    /**
     * @var array<int|string, mixed> The tree handed to `tree()`
     */
    public array $saved = [];

    /**
     * @var int How many times the builder persisted this tree
     */
    public int $saves = 0;

    /**
     * Record the tree the builder assembled.
     *
     * @param  array<int|string, mixed>  $tree  The nested tree structure
     * @return self This instance, for chaining
     */
    public function tree(array $tree): self
    {
        $this->saved = $tree;

        return $this;
    }

    /**
     * Record a persist call.
     */
    public function save(): void
    {
        $this->saves++;
    }
}

/**
 * A tree-shaped object missing `save()`, to exercise the capability guard.
 */
final class TreeBuilderProbeTreeWithoutSave
{
    /**
     * Accept a tree the builder should never hand over.
     *
     * @param  array<int|string, mixed>  $tree  The nested tree structure
     * @return self This instance, for chaining
     */
    public function tree(array $tree): self
    {
        return $this;
    }
}

/**
 * A tree-shaped object missing `tree()`, to exercise the other half of the guard.
 */
final class TreeBuilderProbeTreeWithoutTree
{
    /**
     * Accept a persist call the builder should never make.
     */
    public function save(): void {}
}

/**
 * Return the sole concrete Expectation held inside a CompositeExpectation so its
 * fluent methods (`->once()`, `->andReturn()`, …) are visible to PHPStan — the
 * CompositeExpectation only proxies them through `__call`.
 *
 * File-local rather than shared: Pest's `uses(Trait::class)` is not modelled by the
 * static analyser, so a trait written for this would read as used zero times and
 * fail `trait.unused`. `tests/Unit/AssetDownloaderTest.php` and
 * `tests/Feature/ImportWordPressCommandTest.php` each carry their own copy for the
 * same reason.
 *
 * @param  LegacyMockInterface|MockInterface  $mock  The mock to expect a call on
 * @param  string  $method  The method expected to be called
 * @return Expectation The unwrapped expectation, ready to chain
 */
function treeBuilderExpects(LegacyMockInterface|MockInterface $mock, string $method): Expectation
{
    $composite = $mock->shouldReceive($method);

    if (! $composite instanceof CompositeExpectation) {
        throw new RuntimeException('Expected a Mockery CompositeExpectation.');
    }

    $property = (new ReflectionClass(CompositeExpectation::class))->getProperty('_expectations');
    $property->setAccessible(true);

    /** @var list<ExpectationInterface> $expectations */
    $expectations = $property->getValue($composite);

    if (\count($expectations) !== 1) {
        throw new RuntimeException('Expected exactly one Mockery expectation.');
    }

    $expectation = $expectations[0];

    if (! $expectation instanceof Expectation) {
        throw new RuntimeException('Expected a Mockery Expectation instance.');
    }

    return $expectation;
}

/**
 * Build a `TreeBuilder` over a mocked tree repository.
 *
 * The repository is never exercised by these branches — the builder reaches the tree
 * through the collection's structure — so a bare mock is enough.
 *
 * @return TreeBuilder The builder under test
 */
function treeBuilderUnderTest(): TreeBuilder
{
    /** @var CollectionTreeRepository&MockInterface $repository */
    $repository = Mockery::mock(CollectionTreeRepository::class);

    return new TreeBuilder($repository);
}

/**
 * Point the `Collection` facade at a collection built to order.
 *
 * @param  bool  $hasStructure  Whether the collection reports a structure
 * @param  bool  $treeExists  Whether the structure already holds a tree for the site
 * @return TreeBuilderProbeTree The tree the structure hands back
 */
function fakeStructuredCollection(bool $hasStructure = true, bool $treeExists = true): TreeBuilderProbeTree
{
    $probe = new TreeBuilderProbeTree;

    $structure = Mockery::mock(CollectionStructure::class);
    treeBuilderExpects($structure, 'in')->andReturn($treeExists ? $probe : null);
    treeBuilderExpects($structure, 'makeTree')->andReturn($probe);

    $collection = Mockery::mock(StatamicCollection::class);
    treeBuilderExpects($collection, 'hasStructure')->andReturn($hasStructure);
    treeBuilderExpects($collection, 'structure')->andReturn($structure);
    treeBuilderExpects($collection, 'save')->andReturnNull();

    fakeCollectionRepository()->andReturn($collection);

    return $probe;
}

/**
 * Swap the `Collection` facade for a mock repository and expect a handle lookup.
 *
 * @return Expectation The `findByHandle` expectation, ready to chain a return value
 */
function fakeCollectionRepository(): Expectation
{
    $repository = Mockery::mock(CollectionRepository::class);
    CollectionFacade::swap($repository);

    return treeBuilderExpects($repository, 'findByHandle');
}

/**
 * Read the `entry` handle of a node at a given position in a saved tree.
 *
 * @param  array<int|string, mixed>  $tree  The saved tree
 * @param  int  $index  The top-level position to read
 * @return ?string The entry handle, or null when absent
 */
function treeBuilderEntryAt(array $tree, int $index): ?string
{
    $node = $tree[$index] ?? null;

    if (! \is_array($node)) {
        return null;
    }

    $entry = $node['entry'] ?? null;

    return \is_string($entry) ? $entry : null;
}

/**
 * A parent page and its child, in the shape `EntryCreator` hands over.
 *
 * @return array<int|string, mixed> Page data keyed by WordPress post id
 */
function nestedPageData(): array
{
    return [
        '10' => [
            'entry' => 'entry-parent',
            'parent' => '0',
            'menu_order' => 1,
            'title' => 'Parent',
            'link' => 'https://example.test/parent/',
        ],
        '11' => [
            'entry' => 'entry-child',
            'parent' => '10',
            'menu_order' => 1,
            'title' => 'Child',
            'link' => 'https://example.test/parent/child/',
        ],
    ];
}

it('refuses when the destination collection does not exist', function (): void {
    fakeCollectionRepository()->once()->andReturnNull();

    $output = new BufferedOutput;

    treeBuilderUnderTest()->buildAndSaveTree('default', 'page', 'pages', nestedPageData(), $output);

    // The refusal is reported, not swallowed.
    expect($output->fetch())->toContain("Collection 'pages' not found.");
})->group('tree-builder');

it('refuses when the collection exists but has no structure', function (): void {
    $probe = fakeStructuredCollection(hasStructure: false);

    $output = new BufferedOutput;

    treeBuilderUnderTest()->buildAndSaveTree('default', 'page', 'pages', nestedPageData(), $output);

    // A flat collection has no tree to save, and the refusal is silent by design.
    expect($probe->saves)->toBe(0);
    expect($output->fetch())->toBe('');
})->group('tree-builder');

it('creates a tree when the structure has none for the target site', function (): void {
    $probe = fakeStructuredCollection(treeExists: false);

    $output = new BufferedOutput;

    treeBuilderUnderTest()->buildAndSaveTree('default', 'page', 'pages', nestedPageData(), $output);

    expect($output->fetch())->toContain("No tree exists for 'pages' in site 'default'. Creating a new one.");
    expect($probe->saves)->toBe(1);
})->group('tree-builder');

it('skips a collection and site it already processed this run', function (): void {
    $probe = fakeStructuredCollection();

    $builder = treeBuilderUnderTest();
    $output = new BufferedOutput;

    $builder->buildAndSaveTree('default', 'page', 'pages', nestedPageData(), $output);
    expect($probe->saves)->toBe(1);

    // ---
    // The guard is keyed on "collection:site", so the second call is a no-op. This is
    // what stops a multi-content-type import rebuilding the same tree repeatedly.
    // ---
    $builder->buildAndSaveTree('default', 'page', 'pages', nestedPageData(), $output);
    expect($probe->saves)->toBe(1);
})->group('tree-builder');

it('rebuilds for a different site even once one site is processed', function (): void {
    $probe = fakeStructuredCollection();

    $builder = treeBuilderUnderTest();
    $output = new BufferedOutput;

    $builder->buildAndSaveTree('default', 'page', 'pages', nestedPageData(), $output);
    // A different site is a different key, so the guard must not swallow it.
    $builder->buildAndSaveTree('spanish', 'page', 'pages', nestedPageData(), $output);

    expect($probe->saves)->toBe(2);
})->group('tree-builder');

it('nests a child page under its parent', function (): void {
    $probe = fakeStructuredCollection();

    treeBuilderUnderTest()->buildAndSaveTree('default', 'page', 'pages', nestedPageData(), new BufferedOutput);

    expect($probe->saved)->toBe([
        [
            'entry' => 'entry-parent',
            'children' => [
                ['entry' => 'entry-child'],
            ],
        ],
    ]);
})->group('tree-builder');

it('promotes a page whose parent is absent from the import to the top level', function (): void {
    // ---
    // A partial export can name a parent it does not contain. Dropping the child
    // would lose an imported page silently, so it lands at the top level instead.
    // ---
    $probe = fakeStructuredCollection();

    $orphaned = [
        '11' => [
            'entry' => 'entry-orphan',
            'parent' => '999',
            'menu_order' => 1,
            'title' => 'Orphan',
            'link' => 'https://example.test/orphan/',
        ],
    ];

    treeBuilderUnderTest()->buildAndSaveTree('default', 'page', 'pages', $orphaned, new BufferedOutput);

    expect($probe->saved)->toBe([
        ['entry' => 'entry-orphan'],
    ]);
})->group('tree-builder');

it('makes the front page the root when its link matches the base blog URL', function (): void {
    $probe = fakeStructuredCollection();

    $pageData = nestedPageData();
    $pageData['9'] = [
        'entry' => 'entry-home',
        'parent' => '0',
        'menu_order' => 0,
        'title' => 'Home',
        'link' => 'https://example.test/',
    ];

    treeBuilderUnderTest()->buildAndSaveTree(
        'default',
        'page',
        'pages',
        $pageData,
        new BufferedOutput,
        'https://example.test'
    );

    // The front page is unshifted to the front, ahead of the sorted remainder.
    expect(treeBuilderEntryAt($probe->saved, 0))->toBe('entry-home');
    expect(treeBuilderEntryAt($probe->saved, 1))->toBe('entry-parent');
})->group('tree-builder');

it('sorts top-level pages by menu order', function (): void {
    $probe = fakeStructuredCollection();

    $pageData = [
        '10' => [
            'entry' => 'entry-second',
            'parent' => '0',
            'menu_order' => 2,
            'title' => 'Second',
            'link' => 'https://example.test/second/',
        ],
        '11' => [
            'entry' => 'entry-first',
            'parent' => '0',
            'menu_order' => 1,
            'title' => 'First',
            'link' => 'https://example.test/first/',
        ],
    ];

    treeBuilderUnderTest()->buildAndSaveTree('default', 'page', 'pages', $pageData, new BufferedOutput);

    expect(treeBuilderEntryAt($probe->saved, 0))->toBe('entry-first');
    expect(treeBuilderEntryAt($probe->saved, 1))->toBe('entry-second');
})->group('tree-builder');

it('sorts a menu-order tie by title', function (): void {
    // ---
    // The comparator falls through to `strcmp()` on the titles only when the two
    // orders are identical, which no other case here reaches.
    // ---
    $probe = fakeStructuredCollection();

    $pageData = [
        '10' => [
            'entry' => 'entry-zulu',
            'parent' => '0',
            'menu_order' => 1,
            'title' => 'Zulu',
            'link' => 'https://example.test/zulu/',
        ],
        '11' => [
            'entry' => 'entry-alpha',
            'parent' => '0',
            'menu_order' => 1,
            'title' => 'Alpha',
            'link' => 'https://example.test/alpha/',
        ],
    ];

    treeBuilderUnderTest()->buildAndSaveTree('default', 'page', 'pages', $pageData, new BufferedOutput);

    expect(treeBuilderEntryAt($probe->saved, 0))->toBe('entry-alpha');
    expect(treeBuilderEntryAt($probe->saved, 1))->toBe('entry-zulu');
})->group('tree-builder');

it('orders a zero menu_order ahead of a positive one', function (): void {
    // ---
    // Deliberately 0 vs 1 rather than 1 vs 2: the comparator returns the DIFFERENCE
    // of the two orders, and only a pair whose sum and difference disagree in sign
    // distinguishes that from returning their sum.
    // ---
    $probe = fakeStructuredCollection();

    $pageData = [
        '10' => [
            'entry' => 'entry-one',
            'parent' => '0',
            'menu_order' => 1,
            'title' => 'One',
            'link' => 'https://example.test/one/',
        ],
        '11' => [
            'entry' => 'entry-zero',
            'parent' => '0',
            'menu_order' => 0,
            'title' => 'Zero',
            'link' => 'https://example.test/zero/',
        ],
    ];

    treeBuilderUnderTest()->buildAndSaveTree('default', 'page', 'pages', $pageData, new BufferedOutput);

    expect(treeBuilderEntryAt($probe->saved, 0))->toBe('entry-zero');
    expect(treeBuilderEntryAt($probe->saved, 1))->toBe('entry-one');
})->group('tree-builder');

it('treats a page with no menu_order as order zero', function (): void {
    // Pins the comparator's `?? 0` default, which decides where an unordered page lands.
    $probe = fakeStructuredCollection();

    $pageData = [
        '10' => [
            'entry' => 'entry-ordered',
            'parent' => '0',
            'menu_order' => 5,
            'title' => 'Ordered',
            'link' => 'https://example.test/ordered/',
        ],
        '11' => [
            'entry' => 'entry-unordered',
            'parent' => '0',
            'title' => 'Unordered',
            'link' => 'https://example.test/unordered/',
        ],
    ];

    treeBuilderUnderTest()->buildAndSaveTree('default', 'page', 'pages', $pageData, new BufferedOutput);

    expect(treeBuilderEntryAt($probe->saved, 0))->toBe('entry-unordered');
    expect(treeBuilderEntryAt($probe->saved, 1))->toBe('entry-ordered');
})->group('tree-builder');

it('ties a page with no menu_order against an explicit zero', function (string $unorderedTitle, string $orderedTitle, string $first): void {
    // ---
    // The absent-order default is exactly 0, not merely "small": a missing
    // `menu_order` must TIE with an explicit 0 and fall through to the title, rather
    // than sorting above or below it. Both title orderings are exercised, so a
    // default that drifted either way would change one of them.
    // ---
    $probe = fakeStructuredCollection();

    $pageData = [
        '10' => [
            'entry' => 'entry-unordered',
            'parent' => '0',
            'title' => $unorderedTitle,
            'link' => 'https://example.test/unordered/',
        ],
        '11' => [
            'entry' => 'entry-zero',
            'parent' => '0',
            'menu_order' => 0,
            'title' => $orderedTitle,
            'link' => 'https://example.test/zero/',
        ],
    ];

    treeBuilderUnderTest()->buildAndSaveTree('default', 'page', 'pages', $pageData, new BufferedOutput);

    expect(treeBuilderEntryAt($probe->saved, 0))->toBe($first);
})->with([
    'unordered sorts first on title' => ['Alpha', 'Zulu', 'entry-unordered'],
    'explicit zero sorts first on title' => ['Zulu', 'Alpha', 'entry-zero'],
])->group('tree-builder');

it('refuses when the structure is not a CollectionStructure', function (): void {
    // ---
    // A defensive guard: `structure()` is typed loosely enough to hand back something
    // else, and the builder must not try to read a tree out of it.
    // ---
    $collection = Mockery::mock(StatamicCollection::class);
    treeBuilderExpects($collection, 'hasStructure')->andReturn(true);
    treeBuilderExpects($collection, 'structure')->andReturn(new stdClass);
    treeBuilderExpects($collection, 'save')->andReturnNull();

    fakeCollectionRepository()->andReturn($collection);

    $output = new BufferedOutput;

    treeBuilderUnderTest()->buildAndSaveTree('default', 'page', 'pages', nestedPageData(), $output);

    // It returns before announcing anything.
    expect($output->fetch())->toBe('');
})->group('tree-builder');

it('saves nothing when the tree object cannot persist itself', function (): void {
    // A tree-shaped object without `save()` must not be written to.
    $structure = Mockery::mock(CollectionStructure::class);
    treeBuilderExpects($structure, 'in')->andReturn(new TreeBuilderProbeTreeWithoutSave);
    treeBuilderExpects($structure, 'makeTree')->andReturnNull();

    $collection = Mockery::mock(StatamicCollection::class);
    treeBuilderExpects($collection, 'hasStructure')->andReturn(true);
    treeBuilderExpects($collection, 'structure')->andReturn($structure);
    treeBuilderExpects($collection, 'save')->andReturnNull();

    fakeCollectionRepository()->andReturn($collection);

    $output = new BufferedOutput;

    treeBuilderUnderTest()->buildAndSaveTree('default', 'page', 'pages', nestedPageData(), $output);

    // The run still completes and reports; it simply skips the write.
    expect($output->fetch())->toContain("Updated tree structure for 'pages' in site 'default'.");
})->group('tree-builder');

it('saves nothing when the tree object cannot accept a tree', function (): void {
    // The other half of the same guard: `save()` present, `tree()` absent.
    $structure = Mockery::mock(CollectionStructure::class);
    treeBuilderExpects($structure, 'in')->andReturn(new TreeBuilderProbeTreeWithoutTree);
    treeBuilderExpects($structure, 'makeTree')->andReturnNull();

    $collection = Mockery::mock(StatamicCollection::class);
    treeBuilderExpects($collection, 'hasStructure')->andReturn(true);
    treeBuilderExpects($collection, 'structure')->andReturn($structure);
    treeBuilderExpects($collection, 'save')->andReturnNull();

    fakeCollectionRepository()->andReturn($collection);

    $output = new BufferedOutput;

    treeBuilderUnderTest()->buildAndSaveTree('default', 'page', 'pages', nestedPageData(), $output);

    expect($output->fetch())->toContain("Updated tree structure for 'pages' in site 'default'.");
})->group('tree-builder');

it('reports the updated tree once it has saved', function (): void {
    fakeStructuredCollection();

    $output = new BufferedOutput;

    treeBuilderUnderTest()->buildAndSaveTree('default', 'page', 'pages', nestedPageData(), $output);

    expect($output->fetch())->toContain("Updated tree structure for 'pages' in site 'default'.");
})->group('tree-builder');
