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
 * completes, when an item completes INTERVAL_SECONDS or more after the last line,
 * and when the last item completes. Nothing is written while one item is still
 * being processed, however long it takes. An item counts as complete when the loop asks for the next one, so
 * an item the loop skips with `continue` still counts, and the count reaches the
 * total whenever the loop runs to its end.
 */
final class ImportProgress
{
    /**
     * The longest a loop goes without a progress line, in seconds, while items are completing.
     */
    public const INTERVAL_SECONDS = 10.0;

    /**
     * Yield each item of an import loop's source, reporting progress as they complete.
     *
     * Nothing is written for a loop of one item or none: it finishes in one item's
     * time, and a call that imports one table at a time would otherwise print a pair
     * of lines per table.
     *
     * @template TKey
     * @template TValue
     *
     * @param  OutputInterface  $output  The console output the import writes to
     * @param  string  $label  What is being imported, usually the content type (e.g., `page`)
     * @param  iterable<TKey, TValue>  $items  The loop's source
     * @param  ?int  $total  The number of items, required when `$items` is not an array
     * @param  ?Closure(): float  $clock  Seconds on a monotonic clock; tests pass their own
     * @return \Generator<TKey, TValue, mixed, void>
     */
    public static function track(
        OutputInterface $output,
        string $label,
        iterable $items,
        ?int $total = null,
        ?Closure $clock = null
    ): \Generator {
        // An array knows its own size; any other source must be told it
        $total ??= \is_array($items) ? \count($items) : null;

        if ($total === null) {
            throw new \InvalidArgumentException("A total is required to track progress over a streamed source ({$label}).");
        }

        // A single item finishes in one item's time, so a line about it would add nothing
        if ($total <= 1) {
            yield from $items;

            return;
        }

        $clock ??= static fn (): float => hrtime(true) / 1e9;

        $done = 0;
        $reported = 0;
        $reportedAt = $clock();

        self::report($output, $label, 0, $total);

        foreach ($items as $key => $item) {
            yield $key => $item;

            $done++;

            // Report on a new tenth, on the last item, or after a long quiet stretch
            if (
                self::tenth($done, $total) > self::tenth($reported, $total)
                || $done === $total
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
