<?php

declare(strict_types=1);

/**
 * Tests that `CollectionStructureManager` gives a structured collection a
 * structure and an empty tree for a site when either is missing, and leaves an
 * existing tree, an unstructured collection or a missing one alone (#1314).
 *
 * Routed to the light Statamic band for the Collection facade and the tree
 * repository, both replaced with test doubles so nothing is written.
 *
 * @command  php vendor/bin/pest tests/Integration/CollectionStructureManagerTest.php
 */

use Mockery\CompositeExpectation;
use Mockery\Expectation;
use Mockery\ExpectationInterface;
use Mockery\LegacyMockInterface;
use Mockery\MockInterface;
use Statamic\Contracts\Entries\CollectionRepository;
use Statamic\Contracts\Structures\CollectionTreeRepository;
use Statamic\Entries\Collection as StatamicCollection;
use Statamic\Facades\Blink;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Structures\CollectionStructure;
use Statamic\Structures\CollectionTree;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\CollectionStructureManager;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

beforeEach(function (): void {
    Blink::flush();
});

afterEach(function (): void {
    Blink::flush();
});

/**
 * Return the sole concrete Expectation held inside a CompositeExpectation so its
 * fluent methods are visible to PHPStan; the CompositeExpectation only proxies
 * them through `__call`.
 *
 * File-local for the reason `tests/Integration/TreeBuilderBranchTest.php` gives
 * for its own copy: a shared trait would read to the analyser as unused.
 *
 * @param  LegacyMockInterface|MockInterface  $mock  The mock to expect a call on
 * @param  string  $method  The method expected to be called
 * @return Expectation The unwrapped expectation, ready to chain
 */
function collectionStructureExpects(LegacyMockInterface|MockInterface $mock, string $method): Expectation
{
    $composite = $mock->shouldReceive($method);

    if (! $composite instanceof CompositeExpectation) {
        throw new RuntimeException('Expected a Mockery CompositeExpectation.');
    }

    /** @var list<ExpectationInterface> $expectations */
    $expectations = new ReflectionClass(CompositeExpectation::class)->getProperty('_expectations')->getValue($composite);

    if (\count($expectations) !== 1 || ! $expectations[0] instanceof Expectation) {
        throw new RuntimeException('Expected exactly one Mockery Expectation.');
    }

    return $expectations[0];
}

/**
 * Swap the Collection facade for a repository that expects one handle lookup.
 *
 * @param  string  $handle  The handle the manager should look up
 * @param  ?MockInterface  $collection  The collection double to hand back
 */
function collectionStructureFacade(string $handle, ?MockInterface $collection): void
{
    $repository = Mockery::mock(CollectionRepository::class);
    CollectionFacade::swap($repository);

    collectionStructureExpects($repository, 'findByHandle')->with($handle)->andReturn($collection);
}

/**
 * A collection reporting a structure, handing back the given structure object.
 *
 * @param  ?MockInterface  $structure  The structure double, or null for none
 * @return MockInterface The collection double
 */
function collectionStructureCollection(?MockInterface $structure): MockInterface
{
    $collection = Mockery::mock(StatamicCollection::class);
    collectionStructureExpects($collection, 'hasStructure')->andReturn(true);
    collectionStructureExpects($collection, 'structure')->withNoArgs()->andReturn($structure);

    return $collection;
}

/**
 * Run the manager and return what it printed.
 *
 * @param  string  $handle  The collection handle
 * @param  string  $site  The site
 * @return string The output
 */
function collectionStructureRun(string $handle, string $site = 'default'): string
{
    $output = new BufferedOutput;

    new CollectionStructureManager()->initializeStructureForSite($handle, $site, $output);

    // `BufferedOutput` ends each line with `PHP_EOL`, which is CRLF on Windows, so fold
    // line breaks to LF: the lines printed are under test, not how a console ends one
    return \preg_replace('/\R/', "\n", $output->fetch()) ?? '';
}

it('does nothing for a collection that does not exist', function (): void {
    collectionStructureFacade('missing', null);

    expect(collectionStructureRun('missing'))->toBe('');
});

it('does nothing for a collection without a structure', function (): void {
    $collection = Mockery::mock(StatamicCollection::class);
    collectionStructureExpects($collection, 'hasStructure')->once()->andReturn(false);
    $collection->shouldNotReceive('structure');
    $collection->shouldNotReceive('save');
    collectionStructureFacade('news', $collection);

    expect(collectionStructureRun('news'))->toBe('');
});

it('leaves a tree that already exists for the site', function (): void {
    $tree = Mockery::mock(CollectionTree::class);
    $tree->shouldNotReceive('save');

    $structure = Mockery::mock(CollectionStructure::class);
    collectionStructureExpects($structure, 'in')->once()->with('fr')->andReturn($tree);
    $structure->shouldNotReceive('makeTree');

    $collection = collectionStructureCollection($structure);
    $collection->shouldNotReceive('save');
    collectionStructureFacade('pages', $collection);

    expect(collectionStructureRun('pages', 'fr'))->toBe("Tree already exists for 'pages' in site 'fr'.\n");
});

it('creates and saves an empty tree when the site has none', function (): void {
    $tree = Mockery::mock(CollectionTree::class);
    collectionStructureExpects($tree, 'save')->once()->andReturn(true);

    $structure = Mockery::mock(CollectionStructure::class);
    collectionStructureExpects($structure, 'in')->once()->with('fr')->andReturnNull();
    collectionStructureExpects($structure, 'makeTree')->once()->with('fr', [])->andReturn($tree);

    collectionStructureFacade('pages', collectionStructureCollection($structure));

    expect(collectionStructureRun('pages', 'fr'))->toBe("No tree exists for 'pages' in site 'fr'. Creating a new empty tree.\n");
});

it('saves nothing when making the tree does not give a collection tree', function (): void {
    $structure = Mockery::mock(CollectionStructure::class);
    collectionStructureExpects($structure, 'in')->once()->andReturnNull();
    collectionStructureExpects($structure, 'makeTree')->once()->andReturnNull();

    collectionStructureFacade('pages', collectionStructureCollection($structure));

    expect(collectionStructureRun('pages', 'fr'))->toBe("No tree exists for 'pages' in site 'fr'. Creating a new empty tree.\n");
});

it('gives a structured collection without a structure object a new one, and saves it', function (): void {
    /** @var ArrayObject<string, CollectionStructure> $assigned */
    $assigned = new ArrayObject;
    $collection = collectionStructureCollection(null);
    collectionStructureExpects($collection, 'handle')->andReturn('services');
    collectionStructureExpects($collection, 'structure')->once()->with(Mockery::type(CollectionStructure::class))
        ->andReturnUsing(static function (CollectionStructure $structure) use ($assigned, $collection): MockInterface {
            $assigned['structure'] = $structure;

            return $collection;
        });
    collectionStructureExpects($collection, 'save')->once()->andReturn(true);
    collectionStructureFacade('services', $collection);

    // The new structure looks its tree up through the repository; an existing
    // tree there keeps the run from writing one
    $tree = Mockery::mock(CollectionTree::class);
    $tree->shouldNotReceive('save');

    $repository = Mockery::mock(CollectionTreeRepository::class);
    collectionStructureExpects($repository, 'find')->once()->with('services', 'default')->andReturn($tree);
    app()->instance(CollectionTreeRepository::class, $repository);

    expect(collectionStructureRun('services'))->toBe(
        "No structure exists for 'services'. Creating one.\n"
        ."Tree already exists for 'services' in site 'default'.\n"
    );

    $structure = $assigned['structure'] ?? null;

    expect($structure)->toBeInstanceOf(CollectionStructure::class)
        ->and($structure instanceof CollectionStructure ? $structure->handle() : null)->toBe('services');
});
