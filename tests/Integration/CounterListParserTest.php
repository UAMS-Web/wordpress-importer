<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use Symfony\Component\Console\Output\NullOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\CounterListParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;
use UAMSWeb\WordPressImporter\Tests\Support\FakeAssetImporter;

/**
 * Tests that `CounterListParser` maps an ACF Counter List block onto the
 * counter replicator set: one item per valid counted section, skipping an
 * invalid section, and the whole block when the heading, the custom start or
 * every item is missing.
 *
 * @command  php vendor/bin/pest tests/Integration/CounterListParserTest.php
 */
uses(StatamicTestCase::class);

// The warnings read the page link from a process-static
beforeEach(function (): void {
    ProcessingContext::clearWordPressPageLink();
});

afterEach(function (): void {
    ProcessingContext::clearWordPressPageLink();
});

/**
 * Parse block attributes.
 *
 * @param  array<int|string, mixed>  $attributes  The block attributes
 * @return array<int|string, mixed> The replicator set, or `[]` when skipped
 */
function counterParse(array $attributes): array
{
    return (new CounterListParser)->parse($attributes, 'default', new FakeAssetImporter, new NullOutput);
}

/**
 * Strip a row id after checking its shape.
 *
 * @param  array<int|string, mixed>  $row  A set or an item
 * @return array<int|string, mixed> The row without its id
 */
function counterWithoutId(array $row): array
{
    expect($row['id'] ?? null)->toBeString()->toMatch('/^[A-Za-z0-9]{8}$/');

    unset($row['id']);

    return $row;
}

/**
 * Parse block data and strip the ids from the set and its items.
 *
 * @param  array<int|string, mixed>  $data  The block data
 * @return array<int|string, mixed> The replicator set without ids
 */
function counterSet(array $data): array
{
    $set = counterWithoutId(counterParse(['data' => $data]));
    $items = \is_array($set['items'] ?? null) ? $set['items'] : [];
    $set['items'] = array_map(
        fn (mixed $item): array => counterWithoutId(\is_array($item) ? $item : []),
        $items
    );

    return $set;
}

/**
 * The items of a replicator set.
 *
 * @param  array<int|string, mixed>  $set  The replicator set
 * @return list<array<int|string, mixed>> Its items, each an array
 */
function counterItems(array $set): array
{
    $items = [];

    foreach (\is_array($set['items'] ?? null) ? $set['items'] : [] as $item) {
        $items[] = \is_array($item) ? $item : [];
    }

    return $items;
}

/**
 * The indexed fields of one counter section.
 *
 * @param  int  $index  The section index
 * @param  array<int|string, mixed>  $fields  Field values keyed by the name after `counter_list_section_`
 * @return array<string, mixed> The prefixed fields
 */
function counterSection(int $index, array $fields): array
{
    $prefixed = [];

    foreach ($fields as $name => $value) {
        $prefixed["counter_list_section_{$index}_counter_list_section_{$name}"] = $value;
    }

    return $prefixed;
}

/**
 * The fields of a section that passes every check.
 *
 * @param  int  $index  The section index
 * @param  array<int|string, mixed>  $overrides  Fields replacing the valid ones
 * @return array<string, mixed> The prefixed fields
 */
function counterValidSection(int $index, array $overrides = []): array
{
    return counterSection($index, [
        'title' => "Count $index",
        'unit' => 'patient',
        'units' => 'patients',
        'rate' => '2.5',
        ...$overrides,
    ]);
}

it('maps the block and each valid counted section onto the set', function (): void {
    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();

    expect(counterSet([
        'counter_list_heading' => 'By the <b>numbers</b>',
        'counter_list_hide_heading' => '1',
        'counter_list_description' => "  Fish &amp; chips \n",
        'counter_list_start' => 'year',
        'counter_list_background_color' => 'bg-red',
        'counter_list_section' => '2',
        ...counterValidSection(0, ['title' => 'Beds <i>filled</i>', 'rate' => 3]),
        ...counterValidSection(1, ['start_inherit' => '0', 'start_override' => 'week', 'rate' => '0']),
        ...counterValidSection(2),
    ]))->toBe([
        'type' => 'uamsweb_block_counter',
        'block_heading' => 'By the numbers',
        'hide_block_heading' => true,
        'start' => 'year',
        'background_color' => 'bg-red',
        'items' => [
            [
                'type' => 'new_set',
                'heading' => 'Beds filled',
                'unit' => 'patient',
                'units' => 'patients',
                'rate' => 3.0,
                'start_inherit' => true,
                'enabled' => true,
            ],
            [
                'type' => 'new_set',
                'heading' => 'Count 1',
                'unit' => 'patient',
                'units' => 'patients',
                'rate' => 0.0,
                'start_inherit' => false,
                'enabled' => true,
                'start_override' => 'week',
            ],
        ],
        'enabled' => true,
        'block_description' => 'Fish & chips',
    ]);

    $logSpy->shouldNotHaveReceived('warning');
});

it('fills the defaults and leaves out the optional fields', function (): void {
    expect(counterSet([
        'counter_list_heading' => 'Heading',
        'counter_list_section' => 1,
        ...counterValidSection(0),
    ]))->toBe([
        'type' => 'uamsweb_block_counter',
        'block_heading' => 'Heading',
        'hide_block_heading' => false,
        'start' => 'day',
        'background_color' => 'bg-auto',
        'items' => [[
            'type' => 'new_set',
            'heading' => 'Count 0',
            'unit' => 'patient',
            'units' => 'patients',
            'rate' => 2.5,
            'start_inherit' => true,
            'enabled' => true,
        ]],
        'enabled' => true,
    ]);
});

it('reads the hide-heading flag only when it is the string 1', function (mixed $hide, bool $expected): void {
    expect(counterSet([
        'counter_list_heading' => 'Heading',
        'counter_list_hide_heading' => $hide,
        'counter_list_section' => 1,
        ...counterValidSection(0),
    ])['hide_block_heading'])->toBe($expected);
})->with([
    'the string 1' => ['1', true],
    'the integer 1' => [1, true],
    'the string 0' => ['0', false],
    'true' => [true, false],
]);

it('includes the custom start when the block start is custom', function (): void {
    $set = counterSet([
        'counter_list_heading' => 'Heading',
        'counter_list_start' => 'custom',
        'counter_list_start_custom' => '2020-01-01',
        'counter_list_section' => 1,
        ...counterValidSection(0),
    ]);

    expect($set['start'])->toBe('custom')
        ->and($set['start_custom'] ?? null)->toBe('2020-01-01');
});

it('leaves the custom start out when the block start is not custom', function (): void {
    expect(array_key_exists('start_custom', counterSet([
        'counter_list_heading' => 'Heading',
        'counter_list_start' => 'year',
        'counter_list_start_custom' => '2020-01-01',
        'counter_list_section' => 1,
        ...counterValidSection(0),
    ])))->toBeFalse();
});

it('maps the ACF field keys onto the standard names, over the standard keys', function (): void {
    $set = counterSet([
        'counter_list_heading' => 'Overridden',
        'field_counter_list_heading_b' => 'From the field key',
        'field_counter_list_hide_heading_b' => '1',
        'field_counter_list_description_b' => 'Described',
        'field_counter_list_start_b' => 'custom',
        'field_counter_list_start_custom_b' => '2021-02-03',
        'field_counter_list_background_color_b' => 'bg-blue',
        'field_counter_list_section_b' => '1',
        ...counterValidSection(0),
    ]);

    expect($set['block_heading'])->toBe('From the field key')
        ->and($set['hide_block_heading'])->toBeTrue()
        ->and($set['block_description'] ?? null)->toBe('Described')
        ->and($set['start'])->toBe('custom')
        ->and($set['start_custom'] ?? null)->toBe('2021-02-03')
        ->and($set['background_color'])->toBe('bg-blue')
        ->and(counterItems($set))->toHaveCount(1);
});

it('reads the section count as an integer, a numeric string, or nothing', function (mixed $count, int $expected): void {
    $data = [
        'counter_list_heading' => 'Heading',
        'counter_list_section' => $count,
        ...counterValidSection(0),
        ...counterValidSection(1),
        ...counterValidSection(2),
    ];

    if ($expected === 0) {
        expect(counterParse(['data' => $data]))->toBe([]);

        return;
    }

    expect(array_column(counterItems(counterSet($data)), 'heading'))
        ->toBe(array_slice(['Count 0', 'Count 1', 'Count 2'], 0, $expected));
})->with([
    'an integer' => [3, 3],
    'a numeric string' => ['2', 2],
    'a decimal string' => ['1.9', 1],
    'a float' => [2.0, 2],
    'a non-numeric string' => ['two', 0],
    'missing' => [null, 0],
]);

it('casts numbers in text fields and falls back for other types', function (): void {
    $set = counterSet([
        'counter_list_heading' => 2026,
        'counter_list_description' => ['Ignored'],
        'counter_list_start' => ['custom'],
        'counter_list_background_color' => 7,
        'counter_list_section' => 1,
        ...counterSection(0, ['title' => 1.5, 'unit' => 12, 'units' => 'patients', 'rate' => '4']),
    ]);

    expect($set['block_heading'])->toBe('2026')
        ->and(array_key_exists('block_description', $set))->toBeFalse()
        ->and($set['start'])->toBe('day')
        ->and($set['background_color'])->toBe('7')
        ->and(counterItems($set)[0]['heading'] ?? null)->toBe('1.5')
        ->and(counterItems($set)[0]['unit'] ?? null)->toBe('12')
        ->and(counterItems($set)[0]['rate'] ?? null)->toBe(4.0);
});

it('treats a non-text start-inherit as inheriting', function (): void {
    expect(counterItems(counterSet([
        'counter_list_heading' => 'Heading',
        'counter_list_section' => 1,
        ...counterValidSection(0, ['start_inherit' => ['0']]),
    ]))[0]['start_inherit'] ?? null)->toBeTrue();
});

it('includes the section override, and its custom start when the override is custom', function (): void {
    $items = counterItems(counterSet([
        'counter_list_heading' => 'Heading',
        'counter_list_section' => 2,
        ...counterValidSection(0, ['start_inherit' => '0', 'start_override' => 'custom', 'start_custom' => '2019-05-06']),
        ...counterValidSection(1, ['start_inherit' => '1', 'start_override' => 'custom', 'start_custom' => '2019-05-06']),
    ]));

    expect($items[0]['start_inherit'] ?? null)->toBeFalse()
        ->and($items[0]['start_override'] ?? null)->toBe('custom')
        ->and($items[0]['start_override_custom'] ?? null)->toBe('2019-05-06')
        ->and($items[1]['start_inherit'] ?? null)->toBeTrue()
        ->and(array_key_exists('start_override', $items[1]))->toBeFalse()
        ->and(array_key_exists('start_override_custom', $items[1]))->toBeFalse();
});

it('skips a section missing a required field, naming what it has', function (array $overrides, string $heading, bool $hasUnit, bool $hasUnits, bool $hasRate): void {
    ProcessingContext::setWordPressPageLink('https://example.org/stats/');

    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();

    $items = counterItems(counterSet([
        'counter_list_heading' => 'Heading',
        'counter_list_section' => 2,
        ...counterValidSection(0, $overrides),
        ...counterValidSection(1),
    ]));

    expect(array_column($items, 'heading'))->toBe(['Count 1']);

    $logSpy->shouldHaveReceived('warning', [
        'CounterListParser::processItems: Skipping invalid counter_list_section item (from entry: https://example.org/stats/)',
        [
            'site' => 'default',
            'heading' => $heading,
            'has_unit' => $hasUnit,
            'has_units' => $hasUnits,
            'has_rate' => $hasRate,
            'entry_url' => 'https://example.org/stats/',
        ],
    ]);
})->with([
    'no title' => [['title' => '<b></b>'], '', true, true, true],
    'no unit' => [['unit' => ''], 'Count 0', false, true, true],
    'no units' => [['units' => ''], 'Count 0', true, false, true],
    'a non-numeric rate' => [['rate' => 'fast'], 'Count 0', true, true, false],
]);

it('skips a section that does not inherit the start and names no override', function (): void {
    ProcessingContext::setWordPressPageLink('https://example.org/stats/');

    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();

    $items = counterItems(counterSet([
        'counter_list_heading' => 'Heading',
        'counter_list_section' => 2,
        ...counterValidSection(0, ['start_inherit' => '0']),
        ...counterValidSection(1),
    ]));

    expect(array_column($items, 'heading'))->toBe(['Count 1']);

    $logSpy->shouldHaveReceived('warning', [
        'CounterListParser::processItems: Skipping invalid counter_list_section item due to missing start_override (from entry: https://example.org/stats/)',
        ['site' => 'default', 'heading' => 'Count 0', 'entry_url' => 'https://example.org/stats/'],
    ]);
});

it('skips a section with a custom override and no custom start', function (string $inherit): void {
    ProcessingContext::setWordPressPageLink('https://example.org/stats/');

    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();

    $items = counterItems(counterSet([
        'counter_list_heading' => 'Heading',
        'counter_list_section' => 2,
        ...counterValidSection(0, ['start_inherit' => $inherit, 'start_override' => 'custom']),
        ...counterValidSection(1),
    ]));

    expect(array_column($items, 'heading'))->toBe(['Count 1']);

    $logSpy->shouldHaveReceived('warning', [
        'CounterListParser::processItems: Skipping invalid counter_list_section item due to missing start_custom (from entry: https://example.org/stats/)',
        ['site' => 'default', 'heading' => 'Count 0', 'entry_url' => 'https://example.org/stats/'],
    ]);
})->with([
    'not inheriting' => ['0'],
    'inheriting' => ['1'],
]);

it('skips the block when its heading is empty', function (): void {
    ProcessingContext::setWordPressPageLink('https://example.org/stats/');

    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();

    expect(counterParse(['data' => [
        'counter_list_heading' => '<span></span>',
        'counter_list_section' => 1,
        ...counterValidSection(0),
    ]]))->toBe([]);

    $logSpy->shouldHaveReceived('warning', [
        'CounterListParser::parse: Skipping wp:acf/counter-list block due to missing required heading (from entry: https://example.org/stats/)',
        ['site' => 'default', 'entry_url' => 'https://example.org/stats/'],
    ]);
});

it('skips the block when its start is custom and has no custom start', function (): void {
    ProcessingContext::setWordPressPageLink('https://example.org/stats/');

    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();

    expect(counterParse(['data' => [
        'counter_list_heading' => 'Heading',
        'counter_list_start' => 'custom',
        'counter_list_section' => 1,
        ...counterValidSection(0),
    ]]))->toBe([]);

    $logSpy->shouldHaveReceived('warning', [
        'CounterListParser::parse: Skipping wp:acf/counter-list block due to missing start_custom (from entry: https://example.org/stats/)',
        ['site' => 'default', 'start' => 'custom', 'entry_url' => 'https://example.org/stats/'],
    ]);
});

it('skips the block when no section survives', function (array $data): void {
    ProcessingContext::setWordPressPageLink('https://example.org/stats/');

    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();

    expect(counterParse(['data' => ['counter_list_heading' => 'Heading', ...$data]]))->toBe([]);

    $logSpy->shouldHaveReceived('warning', [
        'CounterListParser::parse: No valid items for wp:acf/counter-list block; skipping (from entry: https://example.org/stats/)',
        ['site' => 'default', 'entry_url' => 'https://example.org/stats/'],
    ]);
})->with([
    'no sections' => [[]],
    'only invalid sections' => [['counter_list_section' => 1, ...counterValidSection(0, ['unit' => ''])]],
]);

it('reads block attributes without a data key, and ignores a data key that is not an array', function (): void {
    $bare = counterParse([
        'counter_list_heading' => 'Bare',
        'counter_list_section' => 1,
        ...counterValidSection(0),
    ]);
    $scalar = counterParse([
        'data' => 'x',
        'counter_list_heading' => 'Scalar',
        'counter_list_section' => 1,
        ...counterValidSection(0),
    ]);

    expect($bare['block_heading'] ?? null)->toBe('Bare')
        ->and($scalar['block_heading'] ?? null)->toBe('Scalar');
});

it('gives each set and each item its own row id', function (): void {
    $data = ['data' => [
        'counter_list_heading' => 'Heading',
        'counter_list_section' => 2,
        ...counterValidSection(0),
        ...counterValidSection(1),
    ]];

    $first = counterParse($data);
    $ids = array_map(
        fn (array $item): string => \is_string($item['id'] ?? null) ? $item['id'] : '',
        counterItems($first)
    );

    expect($first['id'] ?? null)->not->toBe(counterParse($data)['id'] ?? null)
        ->and($ids)->toHaveCount(2)
        ->and(array_unique($ids))->toHaveCount(2);
});
