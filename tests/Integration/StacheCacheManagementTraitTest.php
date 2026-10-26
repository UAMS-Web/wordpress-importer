<?php

declare(strict_types=1);

/**
 * Tests that the Stache cache trait clears one collection's cached paths, and its
 * cached entries only when asked, and does nothing when a store it needs is
 * missing or lacks the method (#1314).
 *
 * @command  php vendor/bin/pest tests/Integration/StacheCacheManagementTraitTest.php
 */

use Statamic\Facades\Stache as StacheFacade;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Traits\StacheCacheManagementTrait;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * A class using the trait, exposing its protected method.
 */
final class StacheCacheManagementTraitTestUser
{
    use StacheCacheManagementTrait;

    /**
     * Call the trait's protected method.
     */
    public function clear(string $collectionHandle, bool $clearItems = false): void
    {
        $this->clearCollectionCache($collectionHandle, $clearItems);
    }
}

/**
 * A collection store that records which of its clearing methods were called.
 */
final class StacheCacheManagementTraitTestCollectionStore
{
    /**
     * The clearing calls received, in order.
     *
     * @var list<string>
     */
    public array $calls = [];

    /**
     * Record a paths clear.
     */
    public function clearCachedPaths(): void
    {
        $this->calls[] = 'clearCachedPaths';
    }

    /**
     * Record an items clear.
     */
    public function clear(): void
    {
        $this->calls[] = 'clear';
    }
}

/**
 * A collection store that can clear its paths but has no items clear.
 */
final class StacheCacheManagementTraitTestPathsOnlyStore
{
    /**
     * Whether the paths were cleared.
     */
    public bool $pathsCleared = false;

    /**
     * Record a paths clear.
     */
    public function clearCachedPaths(): void
    {
        $this->pathsCleared = true;
    }
}

/**
 * An entries store that hands back a configured collection store and records
 * which collection was asked for.
 */
final class StacheCacheManagementTraitTestEntriesStore
{
    /**
     * The collection handles asked for.
     *
     * @var list<string>
     */
    public array $requested = [];

    public function __construct(
        private readonly mixed $collectionStore
    ) {}

    /**
     * Return the configured collection store.
     */
    public function store(string $handle): mixed
    {
        $this->requested[] = $handle;

        return $this->collectionStore;
    }
}

/**
 * Make the Stache facade return the given value for the entries store.
 */
function stacheCacheTraitEntriesStore(mixed $entriesStore): void
{
    StacheFacade::shouldReceive('store')->with('entries')->andReturn($entriesStore);
}

it('clears the named collection\'s cached paths and leaves its entries alone by default', function (): void {
    $collectionStore = new StacheCacheManagementTraitTestCollectionStore;
    $entriesStore = new StacheCacheManagementTraitTestEntriesStore($collectionStore);
    stacheCacheTraitEntriesStore($entriesStore);

    (new StacheCacheManagementTraitTestUser)->clear('posts');

    expect($entriesStore->requested)->toBe(['posts'])
        ->and($collectionStore->calls)->toBe(['clearCachedPaths']);
});

it('also clears the cached entries when asked', function (): void {
    $collectionStore = new StacheCacheManagementTraitTestCollectionStore;
    stacheCacheTraitEntriesStore(new StacheCacheManagementTraitTestEntriesStore($collectionStore));

    (new StacheCacheManagementTraitTestUser)->clear('posts', true);

    expect($collectionStore->calls)->toBe(['clearCachedPaths', 'clear']);
});

it('clears the paths of a store that has no entries clear, even when asked to clear entries', function (): void {
    $collectionStore = new StacheCacheManagementTraitTestPathsOnlyStore;
    stacheCacheTraitEntriesStore(new StacheCacheManagementTraitTestEntriesStore($collectionStore));

    (new StacheCacheManagementTraitTestUser)->clear('posts', true);

    expect($collectionStore->pathsCleared)->toBeTrue();
});

it('does nothing when the entries store is missing or cannot hand out a collection store', function (mixed $entriesStore): void {
    stacheCacheTraitEntriesStore($entriesStore);

    // Calling store() on either value would throw, so nothing thrown shows it was not called
    $thrown = null;

    try {
        (new StacheCacheManagementTraitTestUser)->clear('posts', true);
    } catch (Throwable $throwable) {
        $thrown = $throwable::class.': '.$throwable->getMessage();
    }

    expect($thrown)->toBeNull();
})->with([
    'no entries store' => [null],
    'an entries store with no store()' => [new stdClass],
]);

it('does nothing when the collection store is missing or cannot clear its paths', function (mixed $collectionStore): void {
    $entriesStore = new StacheCacheManagementTraitTestEntriesStore($collectionStore);
    stacheCacheTraitEntriesStore($entriesStore);

    (new StacheCacheManagementTraitTestUser)->clear('posts', true);

    expect($entriesStore->requested)->toBe(['posts']);
})->with([
    'no collection store' => [null],
    'a collection store with no clearCachedPaths()' => [new stdClass],
]);
