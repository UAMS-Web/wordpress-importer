<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\TablePressShortcodeProcessor;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * Tests for TablePressShortcodeProcessor reading the `id` attribute of a `[table]` shortcode.
 *
 * `parse()` and `toPageBuilderBlock()` take a string or integer id. A missing, blank or other
 * id logs a warning, registers nothing for linking, and still returns a table set, because
 * the rest of the page does not depend on it. Registration through the real shortcode path
 * is `TableShortcodeIdRegistrationTest`.
 *
 * @command  php vendor/bin/pest tests/Integration/TablePressShortcodeProcessorTest.php
 */
uses(StatamicTestCase::class);

beforeEach(function (): void {
    TablePressShortcodeProcessor::clearTableIds();
    ProcessingContext::clearWordPressPostId();
    ProcessingContext::clearWordPressPageLink();
});

afterEach(function (): void {
    TablePressShortcodeProcessor::clearTableIds();
    ProcessingContext::clearWordPressPostId();
    ProcessingContext::clearWordPressPageLink();
});

/**
 * Capture every warning the processor logs.
 *
 * @return ArrayObject<int, array{message: string, context: array<int|string, mixed>}> The warnings, as they arrive
 */
function tableShortcodeWarnings(): ArrayObject
{
    /** @var ArrayObject<int, array{message: string, context: array<int|string, mixed>}> $warnings */
    $warnings = new ArrayObject;

    Log::shouldReceive('warning')->andReturnUsing(function (string $message, array $context) use ($warnings): void {
        /** @var array<int|string, mixed> $context */
        $warnings->append(['message' => $message, 'context' => $context]);
    });

    return $warnings;
}

/**
 * A processor whose parse() answers with a given set, for the shapes the real one never returns.
 *
 * @param  mixed  $set  The value parse() returns, typed as the array its signature declares
 * @return TablePressShortcodeProcessor The processor
 */
function tableProcessorParsing(mixed $set): TablePressShortcodeProcessor
{
    if (! is_array($set)) {
        throw new RuntimeException('Expected a set array.');
    }

    /** @var array<string, mixed> $set */
    return new class($set) extends TablePressShortcodeProcessor
    {
        /**
         * Hold the set parse() returns.
         *
         * @param  array<string, mixed>  $set  The set
         */
        public function __construct(private readonly array $set) {}

        /**
         * Return the held set.
         *
         * @param  array<int|string, mixed>  $attributes  Ignored
         * @param  string  $site  Ignored
         * @param  AssetImporter  $assetImporter  Ignored
         * @param  OutputInterface  $output  Ignored
         * @param  array<int|string, mixed>  $terms  Ignored
         * @param  string|null  $pageSlug  Ignored
         * @return array<string, mixed> The held set
         */
        public function parse(
            array $attributes,
            string $site,
            AssetImporter $assetImporter,
            OutputInterface $output,
            array $terms = [],
            ?string $pageSlug = null
        ): array {
            return $this->set;
        }
    };
}

it('warns and registers nothing when the shortcode has no usable id', function (array $attributes): void {
    $warnings = tableShortcodeWarnings();

    $set = new TablePressShortcodeProcessor()->parse($attributes, 'default', new AssetImporter, new NullOutput, [], 'a-page');

    expect($set['type'])->toBe('set');
    expect(TablePressShortcodeProcessor::getTableIds())->toBe([]);
    expect($warnings)->toHaveCount(1);
    expect($warnings->getArrayCopy()[0]['message'])->toContain("TablePressShortcodeProcessor::parse: Missing 'id' attribute in [table] shortcode");
    expect($warnings->getArrayCopy()[0]['context'])->toMatchArray(['site' => 'default', 'pageSlug' => 'a-page']);
})->with([
    'no id attribute' => [[]],
    'a blank id' => [['id' => '']],
    'an id that is neither a string nor an integer' => [['id' => 1.5]],
]);

it('names no page in the warning when there is no page slug', function (): void {
    $warnings = tableShortcodeWarnings();

    new TablePressShortcodeProcessor()->parse([], 'default', new AssetImporter, new NullOutput);

    expect($warnings->getArrayCopy()[0]['context']['pageSlug'] ?? null)->toBe('none');
});

it('registers an integer id as a string, without a warning', function (): void {
    Log::shouldReceive('warning')->never();

    new TablePressShortcodeProcessor()->parse(['id' => 7], 'default', new AssetImporter, new NullOutput);

    expect(TablePressShortcodeProcessor::getTableIds())->toBe(['7']);
});

it('registers the id under the WordPress post being processed', function (): void {
    ProcessingContext::setWordPressPostId('42');

    new TablePressShortcodeProcessor()->parse(['id' => '7'], 'default', new AssetImporter, new NullOutput);

    expect(TablePressShortcodeProcessor::getTableIdsForPage('42'))->toBe(['7']);
});

it('keeps every distinct table on a page, and each one once', function (): void {
    ProcessingContext::setWordPressPostId('42');
    $processor = new TablePressShortcodeProcessor;

    // `07` and `7` name different tables, though PHP compares them as equal numbers
    foreach (['7', '07', '7'] as $id) {
        $processor->parse(['id' => $id], 'default', new AssetImporter, new NullOutput);
    }

    expect(TablePressShortcodeProcessor::getTableIdsForPage('42'))->toBe(['7', '07']);
    expect(TablePressShortcodeProcessor::getTableIds())->toBe(['7', '07']);
});

it('returns the table set values for linking to fill in', function (): void {
    $set = new TablePressShortcodeProcessor()->parse(['id' => '7'], 'default', new AssetImporter, new NullOutput);

    $attrs = $set['attrs'] ?? null;

    if (! is_array($attrs)) {
        throw new RuntimeException('Expected set attrs.');
    }

    expect($attrs['id'])->toBeString()->not->toBe('');
    expect($attrs['values'])->toBe([
        'type' => 'table',
        'create_query' => 'collection',
        'table_entry' => null,
        'heading_query' => false,
        'description_query' => false,
        'heading_level' => 'h2',
        'align' => 'aligncenter',
    ]);
});

it('builds a table block carrying the id as a string', function (mixed $id, string $expected): void {
    $block = new TablePressShortcodeProcessor()->toPageBuilderBlock(['id' => $id], 'default', new AssetImporter, new NullOutput);

    expect($block['id'])->toBeString()->not->toBe('');

    unset($block['id']);

    expect($block)->toBe([
        'type' => 'uamsweb_block_table',
        'enabled' => true,
        'table_id' => $expected,
        'align' => 'aligncenter',
    ]);
})->with([
    'a string id' => ['7', '7'],
    'an integer id' => [7, '7'],
]);

it('builds a table block with no table id when the shortcode has none', function (): void {
    tableShortcodeWarnings();

    $block = new TablePressShortcodeProcessor()->toPageBuilderBlock([], 'default', new AssetImporter, new NullOutput);

    expect($block['table_id'])->toBeNull();
});

it('takes the block alignment from the parsed set', function (mixed $set, string $expected): void {
    $block = tableProcessorParsing($set)->toPageBuilderBlock(['id' => '7'], 'default', new AssetImporter, new NullOutput);

    expect($block['align'])->toBe($expected);
})->with([
    'an alignment on the set' => [['attrs' => ['values' => ['align' => 'alignleft']]], 'alignleft'],
    'an alignment that is not a string' => [['attrs' => ['values' => ['align' => 3]]], 'aligncenter'],
    'values with no alignment' => [['attrs' => ['values' => []]], 'aligncenter'],
    'values that are not an array' => [['attrs' => ['values' => 'alignleft']], 'aligncenter'],
    'attrs with no values' => [['attrs' => []], 'aligncenter'],
    'attrs that are not an array' => [['attrs' => 'alignleft'], 'aligncenter'],
    'no attrs' => [[], 'aligncenter'],
]);
