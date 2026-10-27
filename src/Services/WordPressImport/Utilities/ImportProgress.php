<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities;

use Closure;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reports how far an import loop has got, as plain-text lines.
 *
 * Every loop over items in an import wraps its source in track(), so a long run
 * shows that it is advancing and roughly how far along it is. The lines are
 * ordinary output rather than a redrawn bar, so they interleave with the
 * per-item lines creators already write and stay readable in a log file that
 * output was redirected to:
 *
 *     Progress: page 0 of 312 (0%)
 *     Progress: page 32 of 312 (10%)
 *     ...
 *     Progress: page 312 of 312 (100%)
 *
 * A line is written before the first item, each time another tenth of the items
 * completes (the last item always completes the tenth tenth), and when an item
 * completes INTERVAL_SECONDS or more after the last line. Nothing is written while one item is still
 * being processed, however long it takes. An item counts as complete when the loop asks for the next one, so
 * an item the loop skips with `continue` still counts, and the count reaches the
 * total whenever the loop runs to its end.
 */
final class ImportProgress
{
    // Line coverage never reaches a constant's value, so its mutants count as
    // uncovered whatever the tests do; a test pins ten seconds as a literal
    // @pest-mutate-ignore: DecrementFloat, IncrementFloat
    /**
     * The longest a loop goes without a progress line, in seconds, while items are completing.
     */
    public const float INTERVAL_SECONDS = 10.0;

    /**
     * Yield each item of an import loop's source, reporting progress as they complete.
     *
     * Nothing is written for a loop of one item or none: it finishes in one item's
     * time, and a call that imports one table at a time would otherwise print a pair
     * of lines per table. Nothing is written either when the output is quiet or
     * silent (`--quiet`, a `NullOutput`), and then a total given as a closure is
     * never called, so a streamed loop does not read its export a second time to
     * count lines nobody will see.
     *
     * @template TKey
     * @template TValue
     *
     * @param  OutputInterface  $output  The console output the import writes to
     * @param  string  $label  What is being imported, usually the content type (e.g., `page`)
     * @param  iterable<TKey, TValue>  $items  The loop's source
     * @param  int|(Closure(): int)|null  $total  The number of items, or a closure that counts them, required when `$items` is not an array
     * @param  ?Closure(): float  $clock  Seconds on a monotonic clock; tests pass their own
     * @return \Generator<TKey, TValue, mixed, void>
     */
    public static function track(
        OutputInterface $output,
        string $label,
        iterable $items,
        int|Closure|null $total = null,
        ?Closure $clock = null
    ): \Generator {
        // An array knows its own size; any other source must be told it
        $total ??= \is_array($items) ? \count($items) : null;

        if ($total === null) {
            throw new \InvalidArgumentException("A total is required to track progress over a streamed source ({$label}).");
        }

        // A line at normal verbosity would not print, so neither count nor report
        if ($output->getVerbosity() < OutputInterface::VERBOSITY_NORMAL) {
            yield from $items;

            return;
        }

        // Count a streamed source only now that its lines will print
        if ($total instanceof Closure) {
            $total = $total();
        }

        // A single item finishes in one item's time, so a line about it would add nothing
        if ($total <= 1) {
            yield from $items;

            return;
        }

        // A billion nanoseconds is a second; one more or less in the divisor moves a
        // reading by a billionth
        // @pest-mutate-ignore: DecrementFloat, IncrementFloat
        $clock ??= static fn (): float => hrtime(true) / 1e9;

        $done = 0;

        // Starting at -1 instead would change nothing: intdiv() truncates toward
        // zero, so -1 is in the first tenth for a total over ten, and below
        // every count's tenth otherwise
        // @pest-mutate-ignore: DecrementInteger
        $reported = 0;
        $reportedAt = $clock();

        self::report($output, $label, 0, $total);

        foreach ($items as $key => $item) {
            yield $key => $item;

            $done++;

            // Report on a new tenth, which the last item always starts, or after a long quiet stretch
            if (
                self::tenth($done, $total) > self::tenth($reported, $total)
                || $clock() - $reportedAt >= self::INTERVAL_SECONDS
            ) {
                self::report($output, $label, $done, $total);
                $reported = $done;
                $reportedAt = $clock();
            }
        }
    }

    /**
     * Which tenth of the total a count has reached.
     *
     * @param  int  $done  Items completed
     * @param  int  $total  Items in all
     * @return int From 0 to 10
     */
    private static function tenth(
        int $done,
        int $total
    ): int {
        return intdiv($done * 10, $total);
    }

    /**
     * Write one progress line.
     *
     * The line carries no formatting tags, so it is plain text whether or not the
     * output is decorated.
     *
     * @param  OutputInterface  $output  The console output
     * @param  string  $label  What is being imported
     * @param  int  $done  Items completed
     * @param  int  $total  Items in all
     */
    private static function report(
        OutputInterface $output,
        string $label,
        int $done,
        int $total
    ): void {
        $percent = intdiv($done * 100, $total);

        $output->writeln(
            \sprintf('Progress: %s %d of %d (%d%%)', $label, $done, $total, $percent),
            OutputInterface::OUTPUT_RAW
        );
    }
}
