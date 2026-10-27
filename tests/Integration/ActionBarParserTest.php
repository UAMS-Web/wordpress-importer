<?php

declare(strict_types=1);

use Mockery\MockInterface;
use Symfony\Component\Console\Output\NullOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\ActionBarParser;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * Tests that `ActionBarParser` maps an ACF Action Bar block, and a
 * modules-template `uams_action_bar` module, onto the action-bar replicator set,
 * with one section item per counted section.
 *
 * Both entry points share one mapping, so each case runs through both.
 *
 * @command  php vendor/bin/pest tests/Integration/ActionBarParserTest.php
 */
uses(StatamicTestCase::class);

/**
 * An `AssetImporter` that imports nothing.
 */
function actionBarAssetImporter(): AssetImporter
{
    /** @var AssetImporter&MockInterface $assetImporter */
    $assetImporter = Mockery::mock(AssetImporter::class)->shouldIgnoreMissing();

    return $assetImporter;
}

/**
 * Strip a row id after checking its shape.
 *
 * @param  array<int|string, mixed>  $row  A set or an item
 * @return array<int|string, mixed> The row without its id
 */
function actionBarWithoutId(array $row): array
{
    expect($row['id'] ?? null)->toBeString()->toMatch('/^[A-Za-z0-9]{8}$/');

    unset($row['id']);

    return $row;
}

/**
 * Parse data through one of the two entry points.
 *
 * @param  string  $entry  `block` for `parse()`, `module` for `parseModule()`
 * @param  array<int|string, mixed>  $data  The block or module data
 * @return array<int|string, mixed> The replicator set, with no ids on it or its items
 */
function actionBarSet(string $entry, array $data): array
{
    $parser = new ActionBarParser;

    $set = $entry === 'block'
        ? $parser->parse(['data' => $data], 'default', actionBarAssetImporter(), new NullOutput)
        : $parser->parseModule($data, 'default', actionBarAssetImporter(), new NullOutput);

    expect($set)->toBeArray();
    \assert(\is_array($set));

    $set = actionBarWithoutId($set);
    $items = \is_array($set['items'] ?? null) ? $set['items'] : [];
    $set['items'] = array_map(
        fn (mixed $item): array => actionBarWithoutId(\is_array($item) ? $item : []),
        $items
    );

    return $set;
}

/**
 * The section items of a replicator set.
 *
 * @param  array<int|string, mixed>  $set  The replicator set
 * @return list<array<int|string, mixed>> Its items, each an array
 */
function actionBarItems(array $set): array
{
    $items = [];

    foreach (\is_array($set['items'] ?? null) ? $set['items'] : [] as $item) {
        $items[] = \is_array($item) ? $item : [];
    }

    return $items;
}

dataset('actionBarEntries', ['block', 'module']);

it('fills every default when the block sets nothing', function (string $entry): void {
    expect(actionBarSet($entry, []))->toBe([
        'type' => 'uamsweb_block_action_bar',
        'block_heading' => 'Important Links',
        'hide_block_heading' => true,
        'background_color' => 'bg-auto',
        'items' => [],
        'enabled' => true,
    ]);
})->with('actionBarEntries');

it('maps the heading and color, and one item per counted section', function (string $entry): void {
    expect(actionBarSet($entry, [
        'action_bar_heading' => 'Get <em>started</em>',
        'action_bar_background_color' => 'bg-red',
        'action_bar_section' => '2',
        'action_bar_section_0_action_bar_section_heading' => 'Apply <b>now</b>',
        'action_bar_section_0_action_bar_section_body' => '<p>Start here.</p>',
        'action_bar_section_0_action_bar_section_button_text' => '<span>Apply</span>',
        'action_bar_section_0_action_bar_section_button_url' => ['url' => 'https://example.com/apply', 'target' => '_self'],
        'action_bar_section_0_action_bar_section_button_description' => 'Apply <i>online</i>',
        'action_bar_section_1_action_bar_section_heading' => 'Visit',
        'action_bar_section_1_action_bar_section_button_url' => 'https://example.com/visit',
        'action_bar_section_2_action_bar_section_heading' => 'Not counted',
    ]))->toBe([
        'type' => 'uamsweb_block_action_bar',
        'block_heading' => 'Get started',
        'hide_block_heading' => true,
        'background_color' => 'bg-red',
        'items' => [
            [
                'heading' => 'Apply now',
                'body' => 'Start here.',
                'button_text' => 'Apply',
                'button_url' => 'https://example.com/apply',
                'button_target' => true,
                'button_link_description' => 'Apply <i>online</i>',
                'type' => 'section',
                'enabled' => true,
            ],
            [
                'heading' => 'Visit',
                'body' => '',
                'button_text' => '',
                'button_url' => 'https://example.com/visit',
                'button_target' => false,
                'button_link_description' => '',
                'type' => 'section',
                'enabled' => true,
            ],
        ],
        'enabled' => true,
    ]);
})->with('actionBarEntries');

it('reads the section count as an integer, a numeric string, or nothing', function (string $entry, mixed $count, int $expected): void {
    $data = ['action_bar_section' => $count];

    for ($i = 0; $i < 3; $i++) {
        $data["action_bar_section_{$i}_action_bar_section_heading"] = "Section $i";
    }

    expect(array_column(actionBarItems(actionBarSet($entry, $data)), 'heading'))
        ->toBe(array_slice(['Section 0', 'Section 1', 'Section 2'], 0, $expected));
})->with('actionBarEntries')->with([
    'an integer' => [3, 3],
    'a numeric string' => ['2', 2],
    'a non-numeric string' => ['two', 0],
    'a float' => [2.0, 0],
    'zero' => [0, 0],
    'negative' => [-1, 0],
]);

it('casts numbers in text fields and falls back for other types', function (string $entry): void {
    $set = actionBarSet($entry, [
        'action_bar_heading' => 2026,
        'action_bar_background_color' => ['bg-red'],
        'action_bar_section' => 1,
        'action_bar_section_0_action_bar_section_heading' => 1.5,
        'action_bar_section_0_action_bar_section_body' => ['Body'],
        'action_bar_section_0_action_bar_section_button_text' => true,
        'action_bar_section_0_action_bar_section_button_description' => 7,
    ]);

    $item = actionBarItems($set)[0] ?? [];

    expect($set['block_heading'])->toBe('2026')
        ->and($set['background_color'])->toBe('bg-auto')
        ->and($item['heading'] ?? null)->toBe('1.5')
        ->and($item['body'] ?? null)->toBe('')
        ->and($item['button_text'] ?? null)->toBe('')
        ->and($item['button_url'] ?? null)->toBe('')
        ->and($item['button_link_description'] ?? null)->toBe('7');
})->with('actionBarEntries');

it('keeps the default heading when the heading is not text', function (string $entry): void {
    expect(actionBarSet($entry, ['action_bar_heading' => null])['block_heading'])->toBe('Important Links');
})->with('actionBarEntries');

it('reads block attributes without a data key, and ignores a data key that is not an array', function (): void {
    $parser = new ActionBarParser;

    $bare = $parser->parse(['action_bar_heading' => 'Ignored'], 'default', actionBarAssetImporter(), new NullOutput);
    $scalar = $parser->parse(['data' => 'x'], 'default', actionBarAssetImporter(), new NullOutput);

    expect($bare['block_heading'])->toBe('Important Links')
        ->and($scalar['block_heading'])->toBe('Important Links');
});

it('gives each set and each item its own row id', function (string $entry): void {
    $parser = new ActionBarParser;
    $data = ['action_bar_section' => 2];
    $parse = fn (): array => $entry === 'block'
        ? $parser->parse(['data' => $data], 'default', actionBarAssetImporter(), new NullOutput)
        : (array) $parser->parseModule($data, 'default', actionBarAssetImporter(), new NullOutput);

    $first = $parse();
    $ids = array_map(
        fn (array $item): string => \is_string($item['id'] ?? null) ? $item['id'] : '',
        actionBarItems($first)
    );

    expect($first['id'] ?? null)->not->toBe($parse()['id'] ?? null)
        ->and($ids)->toHaveCount(2)
        ->and(array_unique($ids))->toHaveCount(2);
})->with('actionBarEntries');
