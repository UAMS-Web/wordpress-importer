<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities;

use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use ReflectionProperty;
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
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Scopes Statamic's live search-index updates off for a block of work, then
 * rebuilds the affected indexes once.
 *
 * Statamic's `UpdateItemIndexes` subscriber fires on every `EntrySaved`,
 * `TermSaved`, `AssetSaved`, etc. and calls `Search::updateWithinIndexes()` for
 * the saved item. For an index whose file is missing (or, on the `local` driver,
 * any insert into a large index file) that performs a full `Index::update()` —
 * deleting and rebuilding the entire index by scanning every searchable. This
 * site's `default` index uses `searchables: content` (every collection, taxonomy,
 * and asset container) across ~200 sites, so a single term or entry save costs
 * ~12 seconds. During a bulk import that creates hundreds of terms and entries,
 * that cost is paid once per save and dominates the whole run. See issue wordpress-importer-archive#46.
 *
 * Why forget the listener instead of a config flag?
 * -------------------------------------------------
 * `UpdateItemIndexes` is registered unconditionally in Statamic's search
 * `ServiceProvider::boot()` (`Event::subscribe(UpdateItemIndexes::class)`) with no
 * config gate, and — unlike `UpdateAssetReferences` — it is not a
 * `Statamic\Events\Subscriber`, so it has no `disable()`/`enable()` of its own.
 * The mechanism that does work is the one `Subscriber::disable()` uses: forget
 * exactly this subscriber's handlers, leaving every other listener on those events
 * intact. {@see self::forgetListener()} does that by filtering the dispatcher's
 * listener table the way Statamic's `forgetListener` macro does, so it needs no
 * macro. We re-`subscribe()` the class afterwards to restore it.
 *
 * Because removing the listener while indexing is genuinely needed would silently
 * skip search updates, {@see self::withDisabled()} only re-subscribes when the
 * disable actually took effect (mirrors the guard the StacheIndexUpdateToggle and
 * wordpress-importer-archive#45 work use): if the dispatcher cannot unbind listeners, the work simply runs
 * with indexing left on (no speedup, never a corrupted listener table).
 */
class SearchIndexUpdates
{
    /**
     * The events `UpdateItemIndexes` subscribes to, mapped to their handler id.
     *
     * Mirrors {@see UpdateItemIndexes::subscribe()} so {@see self::disable()}
     * forgets exactly these handlers and nothing else.
     *
     * @return array<class-string, string>
     */
    private static function listenerMap(): array
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
     * Run the given callback with Statamic's live search-index updates disabled.
     *
     * The subscriber is restored afterwards (even when the callback throws), but
     * only if the disable genuinely removed it — so an event dispatcher that
     * cannot unbind listeners is never left with duplicate handlers, and the work
     * still runs correctly (just without the speedup).
     *
     * Note: this disables *live* updates only. Imported content will not appear in
     * search until the indexes are rebuilt; callers should run
     * {@see self::reindexAll()} once after the import completes.
     *
     * @template T
     *
     * @param  callable(): T  $work  The work to run while search indexing is off.
     * @return T The callback's return value.
     */
    public static function withDisabled(callable $work): mixed
    {
        $before = self::listenerCount();

        self::disable();

        // Only re-subscribe if the handlers were actually removed.
        $disabled = self::listenerCount() < $before;

        try {
            return $work();
        } finally {
            if ($disabled) {
                self::enable();
            }
        }
    }

    /**
     * Rebuild every configured search index once.
     *
     * Used after a bulk import (run with {@see self::withDisabled()}) so imported
     * content becomes searchable. This is a single pass over the same indexes the
     * per-save listener would otherwise have thrashed once per save, so it is
     * strictly cheaper than leaving live updates on during the import. Per-index
     * failures are logged and skipped rather than aborting the run.
     *
     * @param  OutputInterface|null  $output  Optional console output for progress.
     */
    public static function reindexAll(?OutputInterface $output = null): void
    {
        $output?->writeln('      - Rebuilding search indexes for imported content...');

        foreach (Search::indexes() as $index) {
            if (! ($index instanceof Index)) {
                continue;
            }

            try {
                $index->update();

                $indexLabel = $index->name();

                if (! \is_string($indexLabel)) {
                    $indexLabel = 'unknown';
                }

                $output?->writeln("          - <info>Rebuilt search index '{$indexLabel}'.</info>");
            } catch (Throwable $e) {
                $indexLabel = $index->name();

                if (! \is_string($indexLabel)) {
                    $indexLabel = 'unknown';
                }

                Log::warning("SearchIndexUpdates: failed to rebuild search index '{$indexLabel}': ".$e->getMessage());

                $output?->writeln("          - <comment>Could not rebuild search index '{$indexLabel}': {$e->getMessage()}</comment>");
            }
        }
    }

    /**
     * Forget the `UpdateItemIndexes` handlers on every event it subscribes to.
     *
     * Uses {@see self::forgetListener()}, which mirrors the macro Statamic registers
     * on the dispatcher (the mechanism `Statamic\Events\Subscriber::disable()` relies
     * on) by filtering the listener table directly. Wrapped per-event so an
     * unrecognized dispatcher, or an event with no listeners, fails safe:
     * {@see self::withDisabled()} detects whether anything was actually removed
     * via the listener count.
     */
    private static function disable(): void
    {
        foreach (self::listenerMap() as $event => $listener) {
            try {
                self::forgetListener(
                    $event,
                    $listener
                );
            } catch (Throwable) {
                // Leave it bound; the count guard in withDisabled() decides enable().
            }
        }
    }

    /**
     * Remove a single event listener without Statamic's forgetListener macro.
     *
     * Mirrors the macro registered in Statamic's EventServiceProvider so PHPStan
     * can analyze the call graph without macro magic.
     *
     * @param  class-string  $event  The event class name
     * @param  string  $listener  The listener identifier to remove
     */
    private static function forgetListener(
        string $event,
        string $listener
    ): void {
        $events = app('events');

        if (! ($events instanceof Dispatcher)) {
            return;
        }

        $property = new ReflectionProperty(
            $events,
            'listeners'
        );

        $listenersRaw = $property->getValue($events);

        // An event with no listeners has nothing to forget. Without this return the
        // read below raises an undefined-key error, which disable() catches per event,
        // so the outcome is the same and these mutants cannot be told apart.
        // @pest-mutate-ignore: BooleanOrToBooleanAnd, RemoveEarlyReturn
        if (! \is_array($listenersRaw) || ! isset($listenersRaw[$event])) {
            return;
        }

        $eventListeners = $listenersRaw[$event];

        if (! \is_array($eventListeners)) {
            return;
        }

        $listenersRaw[$event] = \array_values(
            \array_filter(
                $eventListeners,
                static fn ($handler): bool => self::handlerIdentifier($handler) !== $listener
            )
        );

        $property->setValue(
            $events,
            $listenersRaw
        );
    }

    /**
     * Normalize a dispatcher handler to Statamic's `Class@method` identifier.
     *
     * @param  mixed  $handler  The raw listener registered on the dispatcher
     * @return string The normalized handler identifier, or empty when unknown
     */
    private static function handlerIdentifier(
        mixed $handler
    ): string {
        if (\is_string($handler)) {
            return $handler;
        }

        if (
            \is_array($handler)
            && isset($handler[0], $handler[1])
            && \is_string($handler[0])
            && \is_string($handler[1])
        ) {
            return $handler[0].'@'.$handler[1];
        }

        // Any value that is not a `Class@method` id keeps the handler bound, so a
        // different non-empty string behaves the same
        // @pest-mutate-ignore: EmptyStringToNotEmpty
        return '';
    }

    /**
     * Re-register the `UpdateItemIndexes` subscriber, restoring all its handlers.
     */
    private static function enable(): void
    {
        Event::subscribe(UpdateItemIndexes::class);
    }

    /**
     * Count the listeners currently bound to TermSaved — a proxy for whether the
     * `UpdateItemIndexes` handlers are bound (it subscribes to TermSaved). Used to
     * verify {@see self::disable()} took effect before {@see self::enable()} runs.
     */
    private static function listenerCount(): int
    {
        return \count(Event::getListeners(TermSaved::class));
    }
}
