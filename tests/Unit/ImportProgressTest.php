<?php

declare(strict_types=1);

use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportProgress;

/**
 * The progress lines every import loop writes (#103).
 *
 * @command  php vendor/bin/pest tests/Unit/ImportProgressTest.php
 */

/**
 * Run a loop over the items through track(), and return what it yielded and what it wrote.
 *
 * @param  iterable<int|string, mixed>  $items
 * @param  ?Closure(int|string, mixed): bool  $body  Returns false to stop the loop early
 * @return array{yielded: array<int|string, mixed>, lines: list<string>}
 */
function importProgressRun(
    iterable $items,
    ?int $total = null,
    ?Closure $body = null,
    ?Closure $clock = null,
    bool $decorated = false
): array {
    $output = new BufferedOutput(decorated: $decorated);
    $yielded = [];

    foreach (ImportProgress::track($output, 'page', $items, $total, $clock ?? static fn (): float => 0.0) as $key => $item) {
        $yielded[$key] = $item;

        if ($body instanceof Closure && $body($key, $item) === false) {
            break;
        }
    }

    // Console output ends lines with PHP_EOL, which is \r\n on Windows
    $text = \str_replace(\PHP_EOL, "\n", $output->fetch());

    return [
        'yielded' => $yielded,
        'lines' => $text === '' ? [] : \explode("\n", \rtrim($text, "\n")),
    ];
}

it('reports the start, each tenth and the end of a loop', function (): void {
    $run = importProgressRun(\range(1, 20));

    expect($run['lines'])->toBe(\array_map(
        static fn (int $done): string => \sprintf('Progress: page %d of 20 (%d%%)', $done, $done * 5),
        [0, 2, 4, 6, 8, 10, 12, 14, 16, 18, 20]
    ));
});

it('reports every item of a loop shorter than ten', function (): void {
    expect(importProgressRun(['a', 'b', 'c'])['lines'])->toBe([
        'Progress: page 0 of 3 (0%)',
        'Progress: page 1 of 3 (33%)',
        'Progress: page 2 of 3 (66%)',
        'Progress: page 3 of 3 (100%)',
    ]);
});

it('reports once per tenth when the total does not divide by ten', function (): void {
    $lines = importProgressRun(\range(1, 25))['lines'];

    expect($lines)->toHaveCount(11)
        ->and($lines[0])->toBe('Progress: page 0 of 25 (0%)')
        ->and($lines[1])->toBe('Progress: page 3 of 25 (12%)')
        ->and($lines[10])->toBe('Progress: page 25 of 25 (100%)');
});

it('reports after a quiet stretch, between tenths', function (): void {
    $now = 0.0;

    // Item 5 of 100 takes longer than the interval
    $run = importProgressRun(
        \range(1, 100),
        body: function (int|string $key) use (&$now): bool {
            if ($key === 4) {
                $now += ImportProgress::INTERVAL_SECONDS;
            }

            return true;
        },
        clock: function () use (&$now): float {
            return $now;
        }
    );

    expect($run['lines'])->toContain('Progress: page 5 of 100 (5%)')
        ->and($run['lines'])->not->toContain('Progress: page 6 of 100 (6%)')
        ->and($run['lines'])->toHaveCount(12);
});

it('does not report a stretch shorter than the interval', function (): void {
    $now = 0.0;

    $run = importProgressRun(
        \range(1, 100),
        body: function (int|string $key) use (&$now): bool {
            if ($key === 4) {
                $now += ImportProgress::INTERVAL_SECONDS - 0.5;
            }

            return true;
        },
        clock: function () use (&$now): float {
            return $now;
        }
    );

    expect($run['lines'])->not->toContain('Progress: page 5 of 100 (5%)')
        ->and($run['lines'])->toHaveCount(11);
});

it('counts an item the loop skips, so a loop with skips still reaches its total', function (): void {
    $output = new BufferedOutput;
    $processed = 0;

    foreach (ImportProgress::track($output, 'page', \range(1, 4), null, static fn (): float => 0.0) as $item) {
        // Skip every other item, as a creator does with `continue`
        if ($item % 2 === 0) {
            continue;
        }

        $processed++;
    }

    expect($processed)->toBe(2)
        ->and($output->fetch())->toEndWith('Progress: page 4 of 4 (100%)'.\PHP_EOL);
});

it('writes no closing line for a loop that stops early', function (): void {
    $run = importProgressRun(\range(1, 4), body: static fn (int|string $key): bool => $key !== 1);

    expect($run['lines'])->toBe([
        'Progress: page 0 of 4 (0%)',
        'Progress: page 1 of 4 (25%)',
    ]);
});

it('yields every item with its key, from an array and from a generator', function (): void {
    $generator = (static function (): Generator {
        yield 'first' => 1;
        yield 'second' => 2;
    })();

    expect(importProgressRun(['x' => 1, 'y' => 2])['yielded'])->toBe(['x' => 1, 'y' => 2])
        ->and(importProgressRun($generator, 2)['yielded'])->toBe(['first' => 1, 'second' => 2]);
});

it('writes nothing for a loop of one item or none', function (array $items): void {
    $run = importProgressRun($items);

    expect($run['lines'])->toBe([])
        ->and($run['yielded'])->toBe($items);
})->with([
    'none' => [[]],
    'one' => [['k' => 'only']],
]);

it('refuses a streamed source without a total', function (): void {
    importProgressRun((static function (): Generator {
        yield 1;
    })());
})->throws(InvalidArgumentException::class, 'A total is required');

it('writes plain text with no control sequences, even to a decorated output', function (): void {
    $output = new BufferedOutput(decorated: true);

    foreach (ImportProgress::track($output, '<info>redirect_rule</info>', [1, 2], null, static fn (): float => 0.0) as $item) {
        // Consume the loop
    }

    // Only the line ending may carry a carriage return, and only where PHP_EOL does
    $text = \str_replace(\PHP_EOL, "\n", $output->fetch());

    expect($text)->not->toContain("\e")
        ->and($text)->not->toContain("\r")
        ->and($text)->toContain('Progress: <info>redirect_rule</info> 2 of 2 (100%)');
});

it('reports after a quiet stretch of ten seconds, and not of less', function (float $stretch, bool $reported): void {
    $now = 0.0;

    $run = importProgressRun(
        \range(1, 100),
        body: function (int|string $key) use (&$now, $stretch): bool {
            if ($key === 4) {
                $now += $stretch;
            }

            return true;
        },
        clock: function () use (&$now): float {
            return $now;
        }
    );

    expect(\in_array('Progress: page 5 of 100 (5%)', $run['lines'], true))->toBe($reported);
})->with([
    'ten seconds' => [10.0, true],
    'nine and a half' => [9.5, false],
    'eleven seconds' => [11.0, true],
]);

it('reads seconds from the system clock when given none', function (): void {
    $output = new BufferedOutput;

    foreach (ImportProgress::track($output, 'page', \range(1, 20)) as $item) {
        // Each item takes no time to speak of
    }

    // Only the start and the ten tenths; a clock a billion times too fast would
    // report every item
    expect(\substr_count($output->fetch(), 'Progress:'))->toBe(11);
});
