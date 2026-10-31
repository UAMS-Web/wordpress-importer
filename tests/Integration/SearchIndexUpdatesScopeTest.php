<?php

declare(strict_types=1);

/**
 * Tests that `SearchIndexUpdates` takes Statamic's live search-index updates off for a
 * block of work, restores them afterwards, and rebuilds the indexes once on request.
 *
 * `tests/Feature/SearchIndexUpdatesTest.php` counts the listeners under the host app's
 * console kernel, which the package gate never boots, and skips where it decides a
 * dispatcher cannot unbind them. The guard no longer needs Statamic's
 * `forgetListener` macro: it filters the dispatcher's own listener table by reflection.
 * So this file binds a plain `Illuminate\Events\Dispatcher` and asserts the removal and
 * the restore directly, in the gate.
 *
 * @command  php vendor/bin/pest tests/Integration/SearchIndexUpdatesScopeTest.php
 */

use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\MockObject\MockObject;
use Statamic\Events\AssetDeleted;
use Statamic\Events\AssetSaved;
use Statamic\Events\EntryDeleted;
use Statamic\Events\EntrySaved;
use Statamic\Events\EntryScheduleReached;
use Statamic\Events\TermDeleted;
use Statamic\Events\TermSaved;
use Statamic\Events\UserDeleted;
use Statamic\Events\UserSaved;
use Statamic\Facades\Search;
use Statamic\Search\Index;
use Statamic\Search\UpdateItemIndexes;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\SearchIndexUpdates;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * Bind a fresh dispatcher as the application's event dispatcher.
 *
 * @param  bool  $subscribed  Whether to subscribe `UpdateItemIndexes` to it
 */
function searchIndexDispatcher(bool $subscribed = true): Dispatcher
{
    $dispatcher = new Dispatcher(app());

    app()->instance('events', $dispatcher);
    Event::clearResolvedInstance('events');

    if ($subscribed) {
        $dispatcher->subscribe(UpdateItemIndexes::class);
    }

    return $dispatcher;
}

/**
 * Append a raw handler to the dispatcher's listener table for an event, which is
 * what `listen()` stores, without `listen()`'s parameter type. Several handlers here
 * are shapes `listen()` does not declare, because they are what the guard must skip.
 */
function searchIndexBind(Dispatcher $dispatcher, string $event, mixed $listener): void
{
    $property = new ReflectionProperty($dispatcher, 'listeners');
    $listeners = $property->getValue($dispatcher);
    $listeners = \is_array($listeners) ? $listeners : [];
    $bound = \is_array($listeners[$event] ?? null) ? $listeners[$event] : [];
    $bound[] = $listener;
    $listeners[$event] = $bound;
    $property->setValue($dispatcher, $listeners);
}

/**
 * The events `UpdateItemIndexes` subscribes to, with the handler it registers on each.
 *
 * @return array<class-string, string>
 */
function searchIndexHandlers(): array
{
    return [
        EntrySaved::class => UpdateItemIndexes::class.'@update',
        EntryDeleted::class => UpdateItemIndexes::class.'@delete',
        EntryScheduleReached::class => UpdateItemIndexes::class.'@update',
        AssetSaved::class => UpdateItemIndexes::class.'@update',
        AssetDeleted::class => UpdateItemIndexes::class.'@delete',
        UserSaved::class => UpdateItemIndexes::class.'@update',
        UserDeleted::class => UpdateItemIndexes::class.'@delete',
        TermSaved::class => UpdateItemIndexes::class.'@update',
        TermDeleted::class => UpdateItemIndexes::class.'@delete',
    ];
}

/**
 * How many times each event carries the `UpdateItemIndexes` handler, read from the
 * dispatcher's raw listener table rather than through its wrapped closures.
 *
 * @return array<class-string, int>
 */
function searchIndexHandlerCounts(Dispatcher $dispatcher): array
{
    $listeners = (new ReflectionProperty($dispatcher, 'listeners'))->getValue($dispatcher);
    $counts = [];

    foreach (searchIndexHandlers() as $event => $handler) {
        $registered = \is_array($listeners) && \is_array($listeners[$event] ?? null) ? $listeners[$event] : [];
        $counts[$event] = \count(\array_filter($registered, static fn (mixed $listener): bool => $listener === $handler));
    }

    return $counts;
}

/**
 * The same count for every event.
 *
 * @return array<class-string, int>
 */
function searchIndexEvery(int $count): array
{
    return \array_map(static fn (): int => $count, searchIndexHandlers());
}

it('removes every handler for the work and restores each exactly once after', function (): void {
    $dispatcher = searchIndexDispatcher();
    $during = null;

    $result = SearchIndexUpdates::withDisabled(function () use ($dispatcher, &$during): string {
        $during = searchIndexHandlerCounts($dispatcher);

        return 'done';
    });

    expect($result)->toBe('done')
        ->and($during)->toBe(searchIndexEvery(0))
        ->and(searchIndexHandlerCounts($dispatcher))->toBe(searchIndexEvery(1));
});

it('removes the handler registered as a class and method pair', function (): void {
    $dispatcher = searchIndexDispatcher(subscribed: false);
    searchIndexBind($dispatcher, TermSaved::class, [UpdateItemIndexes::class, 'update']);
    $during = null;

    SearchIndexUpdates::withDisabled(function () use ($dispatcher, &$during): void {
        $listeners = (new ReflectionProperty($dispatcher, 'listeners'))->getValue($dispatcher);
        $during = \is_array($listeners) ? ($listeners[TermSaved::class] ?? null) : null;
    });

    expect($during)->toBe([]);
});

it('keeps every handler it cannot name as the subscriber', function (): void {
    // Each shape below would match the subscriber's `Class@update` id under a mutated
    // check, so each one pins a part of the pair test: a method that differs, halves
    // that are Stringable rather than strings, an ArrayAccess object, and pairs with
    // a missing half
    $named = static fn (string $name): Stringable => new class($name) implements Stringable
    {
        public function __construct(private readonly string $name) {}

        public function __toString(): string
        {
            return $this->name;
        }
    };

    $kept = [
        [UpdateItemIndexes::class, 'delete'],
        [$named(UpdateItemIndexes::class), 'update'],
        [UpdateItemIndexes::class, $named('update')],
        new ArrayObject([UpdateItemIndexes::class, 'update']),
        [UpdateItemIndexes::class],
        [1 => 'update'],
        static fn (): null => null,
    ];

    $dispatcher = searchIndexDispatcher(subscribed: false);
    searchIndexBind($dispatcher, TermSaved::class, [UpdateItemIndexes::class, 'update']);

    foreach ($kept as $listener) {
        searchIndexBind($dispatcher, TermSaved::class, $listener);
    }

    $during = null;

    SearchIndexUpdates::withDisabled(function () use ($dispatcher, &$during): void {
        $listeners = (new ReflectionProperty($dispatcher, 'listeners'))->getValue($dispatcher);
        $during = \is_array($listeners) ? ($listeners[TermSaved::class] ?? null) : null;
    });

    expect($during)->toBe($kept);
});

it('leaves a dispatcher that is not Laravel\'s untouched', function (): void {
    // A host may bind its own dispatcher; the guard must not rewrite a listener table
    // it does not own, even one with the same property name
    $events = new class
    {
        /** @var array<string, list<string>> */
        public array $listeners = [TermSaved::class => [UpdateItemIndexes::class.'@update']];

        public int $subscribed = 0;

        /**
         * The listeners registered for an event.
         *
         * @return list<string>
         */
        public function getListeners(string $event): array
        {
            return $this->listeners[$event] ?? [];
        }

        /**
         * Count a subscribe() call.
         */
        public function subscribe(mixed $subscriber): void
        {
            $this->subscribed++;
        }
    };

    app()->instance('events', $events);
    Event::clearResolvedInstance('events');

    SearchIndexUpdates::withDisabled(static fn (): null => null);

    expect($events->listeners)->toBe([TermSaved::class => [UpdateItemIndexes::class.'@update']])
        ->and($events->subscribed)->toBe(0);
});

it('leaves other listeners on the same events bound', function (): void {
    $dispatcher = searchIndexDispatcher();
    searchIndexBind($dispatcher, TermSaved::class, 'App\\Listeners\\Other@handle');
    searchIndexBind($dispatcher, TermSaved::class, ['App\\Listeners\\Pair', 'handle']);

    SearchIndexUpdates::withDisabled(function () use ($dispatcher): void {
        $listeners = (new ReflectionProperty($dispatcher, 'listeners'))->getValue($dispatcher);

        expect(\is_array($listeners) ? ($listeners[TermSaved::class] ?? null) : null)
            ->toBe(['App\\Listeners\\Other@handle', ['App\\Listeners\\Pair', 'handle']]);
    });
});

it('restores the handlers when the work throws', function (): void {
    $dispatcher = searchIndexDispatcher();

    expect(fn (): mixed => SearchIndexUpdates::withDisabled(
        static fn (): never => throw new RuntimeException('import blew up')
    ))->toThrow(RuntimeException::class, 'import blew up');

    expect(searchIndexHandlerCounts($dispatcher))->toBe(searchIndexEvery(1));
});

it('subscribes nothing afterwards when nothing was removed', function (): void {
    // With no handler bound there is nothing to remove, so re-subscribing afterwards
    // would add handlers the dispatcher never had
    $dispatcher = searchIndexDispatcher(subscribed: false);

    SearchIndexUpdates::withDisabled(static fn (): null => null);

    expect(searchIndexHandlerCounts($dispatcher))->toBe(searchIndexEvery(0));
});

/**
 * A mocked search index with a name and an update outcome.
 *
 * @param  MockObject&Index  $index  A fresh mock of the index
 * @param  mixed  $name  What name() returns
 * @param  ?Throwable  $failure  What update() throws, or null for success; a test that
 *                               expects a rebuild declares it with `expects($this->once())`
 */
function searchIndexStub(Index&MockObject $index, mixed $name, ?Throwable $failure = null): Index&MockObject
{
    $index->method('name')->willReturn($name);

    if ($failure !== null) {
        $index->method('update')->willThrowException($failure);
    }

    return $index;
}

it('rebuilds each index once and reports it', function (): void {
    $first = searchIndexStub($this->createMock(Index::class), 'default');
    $second = searchIndexStub($this->createMock(Index::class), 'cp');
    $first->expects($this->once())->method('update');
    $second->expects($this->once())->method('update');

    Search::shouldReceive('indexes')->once()->andReturn(collect([$first, 'not an index', $second]));

    $output = new BufferedOutput;
    SearchIndexUpdates::reindexAll($output);

    expect(\explode(PHP_EOL, \rtrim($output->fetch())))->toBe([
        '      - Rebuilding search indexes for imported content...',
        "          - Rebuilt search index 'default'.",
        "          - Rebuilt search index 'cp'.",
    ]);
});

it('logs a failed index and carries on to the next', function (): void {
    $log = Log::spy();

    $failing = searchIndexStub($this->createMock(Index::class), 'default', new RuntimeException('disk full'));
    $next = searchIndexStub($this->createMock(Index::class), 'cp');
    $next->expects($this->once())->method('update');

    Search::shouldReceive('indexes')->once()->andReturn(collect([$failing, $next]));

    $output = new BufferedOutput;
    SearchIndexUpdates::reindexAll($output);

    expect(\explode(PHP_EOL, \rtrim($output->fetch())))->toBe([
        '      - Rebuilding search indexes for imported content...',
        "          - Could not rebuild search index 'default': disk full",
        "          - Rebuilt search index 'cp'.",
    ]);

    $log->shouldHaveReceived('warning', ["SearchIndexUpdates: failed to rebuild search index 'default': disk full"]);
});

it('puts each failed index in the warning summary and no rebuilt one', function (): void {
    Log::spy();
    ImportWarningManager::clearWarnings();

    $failing = searchIndexStub($this->createMock(Index::class), 'audiences', new RuntimeException('Call to a member function collections() on null'));
    $next = searchIndexStub($this->createMock(Index::class), 'cp');

    Search::shouldReceive('indexes')->once()->andReturn(collect([$failing, $next]));

    SearchIndexUpdates::reindexAll();

    expect(ImportWarningManager::getWarnings('search', 'index_rebuild_failed'))->toBe([
        ['index' => 'audiences', 'error' => 'Call to a member function collections() on null'],
    ]);

    ImportWarningManager::clearWarnings();
});

it('labels an index whose name is not a string as unknown', function (?Throwable $failure, string $line): void {
    Log::spy();

    $index = searchIndexStub($this->createMock(Index::class), null, $failure);

    Search::shouldReceive('indexes')->once()->andReturn(collect([$index]));

    $output = new BufferedOutput;
    SearchIndexUpdates::reindexAll($output);

    expect(\explode(PHP_EOL, \rtrim($output->fetch()))[1] ?? null)->toBe($line);
})->with([
    'rebuilt' => [null, "          - Rebuilt search index 'unknown'."],
    'failed' => [new RuntimeException('boom'), "          - Could not rebuild search index 'unknown': boom"],
]);

it('rebuilds without output when none is given', function (): void {
    $index = searchIndexStub($this->createMock(Index::class), 'default');
    $index->expects($this->once())->method('update');

    Search::shouldReceive('indexes')->once()->andReturn(collect([$index]));

    SearchIndexUpdates::reindexAll();
});

it('logs only the failed index when no output is given', function (): void {
    $log = Log::spy();

    $rebuilt = searchIndexStub($this->createMock(Index::class), 'default');
    $rebuilt->expects($this->once())->method('update');
    $failing = searchIndexStub($this->createMock(Index::class), 'cp', new RuntimeException('disk full'));

    Search::shouldReceive('indexes')->once()->andReturn(collect([$rebuilt, $failing]));

    SearchIndexUpdates::reindexAll();

    // The index that rebuilt logs nothing, which writing to a missing output would break
    $log->shouldHaveReceived('warning', ["SearchIndexUpdates: failed to rebuild search index 'cp': disk full"]);
    $log->shouldNotHaveReceived('warning', [Mockery::on(
        static fn (mixed $message): bool => \is_string($message) && \str_contains($message, "'default'")
    )]);
});
