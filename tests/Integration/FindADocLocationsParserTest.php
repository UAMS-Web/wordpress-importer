<?php

declare(strict_types=1);

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use Symfony\Component\Console\Output\NullOutput;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\MigrationApi\MaterializationScope;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\Blocks\FindADocLocationsParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

/**
 * Tests that `FindADocLocationsParser` maps an ACF Find-a-Doc Locations block
 * onto the `uamsweb_block_fad_locations` set: its required fields, the
 * location type and region term lookups, the expertise and location post
 * lookups against the export's items, the terms it records, the optional more
 * link, and the warning each skip and each miss logs.
 *
 * @command  php vendor/bin/pest tests/Integration/FindADocLocationsParserTest.php
 */
uses(StatamicTestCase::class);

beforeEach(function (): void {
    ProcessingContext::clearWordPressPageLink();
    FindADocLocationsParser::resetTermsUsed();
});

afterEach(function (): void {
    ProcessingContext::clearWordPressPageLink();
    FindADocLocationsParser::resetTermsUsed();
});

/**
 * An `AssetImporter` that imports nothing and knows no source blog.
 */
function fadlAssetImporter(): AssetImporter
{
    /** @var AssetImporter&MockInterface $assetImporter */
    $assetImporter = Mockery::mock(AssetImporter::class)->shouldIgnoreMissing();

    return $assetImporter;
}

/**
 * An export item with an id, a post type and a post name.
 *
 * @param  string  $id  The post id
 * @param  string  $type  The post type
 * @param  ?string  $name  The post name, or null for none
 */
function fadlItem(string $id, string $type, ?string $name): SimpleXMLElement
{
    $nameElement = $name === null ? '' : "<wp:post_name>{$name}</wp:post_name>";

    return new SimpleXMLElement(
        '<item xmlns:wp="http://wordpress.org/export/1.2/">'
        ."<wp:post_id>{$id}</wp:post_id><wp:post_type>{$type}</wp:post_type>{$nameElement}</item>"
    );
}

/**
 * An export term with an id, a taxonomy and a slug.
 *
 * @param  string  $id  The term id
 * @param  string  $taxonomy  The term taxonomy
 * @param  ?string  $slug  The term slug, or null for none
 */
function fadlTerm(string $id, string $taxonomy, ?string $slug): SimpleXMLElement
{
    $slugElement = $slug === null ? '' : "<wp:term_slug>{$slug}</wp:term_slug>";

    return new SimpleXMLElement(
        '<wp:term xmlns:wp="http://wordpress.org/export/1.2/">'
        ."<wp:term_id>{$id}</wp:term_id><wp:term_taxonomy>{$taxonomy}</wp:term_taxonomy>{$slugElement}</wp:term>"
    );
}

/**
 * Block data with the required fields and one location filter.
 *
 * @param  array<int|string, mixed>  $fields  Fields to add or override
 * @return array<int|string, mixed> The block data
 */
function fadlData(array $fields = []): array
{
    return array_merge([
        'block_fad_locations_heading' => 'Heading',
        'block_fad_locations_description' => 'Description',
        'block_fad_locations_filter_ids' => ['7'],
    ], $fields);
}

/**
 * The items the default data's location filter resolves against.
 *
 * @return list<SimpleXMLElement> The export items
 */
function fadlItems(): array
{
    return [fadlItem('7', 'location', 'main-clinic')];
}

/**
 * Parse block data.
 *
 * @param  array<int|string, mixed>  $data  The block data
 * @param  array<int|string, mixed>  $terms  The export terms
 * @param  array<int|string, mixed>|null  $items  The export items, or null for the default location
 * @param  ?string  $pageSlug  The page slug
 * @return array<int|string, mixed> The replicator set without its id, or the empty skip result
 */
function fadlParse(array $data, array $terms = [], ?array $items = null, ?string $pageSlug = 'page'): array
{
    $set = (new FindADocLocationsParser)->parse(
        ['data' => $data],
        'default',
        fadlAssetImporter(),
        new NullOutput,
        $terms,
        $items ?? fadlItems(),
        $pageSlug
    );

    if ($set !== []) {
        expect($set['id'] ?? null)->toBeString()->toMatch('/^[A-Za-z0-9]{8}$/');
        unset($set['id']);
    }

    return $set;
}

/**
 * The set the default data maps to, with the given fields changed.
 *
 * @param  array<string, mixed>  $fields  Fields to add or override
 * @return array<string, mixed> The replicator set without its id
 */
function fadlExpected(array $fields = []): array
{
    return array_merge([
        'type' => 'uamsweb_block_fad_locations',
        'enabled' => true,
        'block_heading' => 'Heading',
        'hide_block_heading' => false,
        'block_description' => 'Description',
        'background_color' => 'bg-white',
        'more_query' => false,
        'locations' => ['main-clinic-7'],
    ], $fields);
}

/**
 * The warning messages logged while running a callback.
 *
 * @param  callable(): mixed  $callback  The code to run
 * @return list<string> The warning messages, in the order logged
 */
function fadlWarnings(callable $callback): array
{
    $warnings = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$warnings): void {
        if ($event->level === 'warning') {
            $warnings[] = $event->message;
        }
    });

    $callback();

    return $warnings;
}

/**
 * The data for a block with a complete more link, with the given fields changed.
 *
 * @param  array<int|string, mixed>  $fields  Fields to add or override
 * @return array<int|string, mixed> The block data
 */
function fadlMoreData(array $fields = []): array
{
    return fadlData(array_merge([
        'block_fad_locations_more' => '1',
        'block_fad_locations_more_text' => 'More',
        'block_fad_locations_more_button_text' => 'See all',
        'block_fad_locations_more_button_url' => ['url' => 'https://example.org/find/'],
        'block_fad_locations_more_button_description' => 'All locations',
    ], $fields));
}

it('maps every field of a full block', function (): void {
    expect(fadlParse([
        'field_block_fad_locations_heading' => 'Our <b>clinics</b>',
        'field_block_fad_locations_description' => 'Find <i>one</i>',
        'field_block_fad_locations_background_color' => 'bg-gray',
        'field_block_fad_locations_filter_type' => ['1'],
        'field_block_fad_locations_filter_aoe' => ['2'],
        'field_block_fad_locations_filter_region' => ['3'],
        'field_block_fad_locations_filter_ids' => ['7'],
        'field_block_fad_locations_more' => '1',
        'field_block_fad_locations_more_text' => 'More <b>clinics</b>',
        'field_block_fad_locations_more_button_text' => 'See <b>all</b>',
        'field_block_fad_locations_more_button_url' => ['url' => 'https://example.org/find/', 'target' => '_blank'],
        'field_block_fad_locations_more_button_description' => 'All <b>locations</b>',
    ], [fadlTerm('1', 'location_type', 'clinic'), fadlTerm('3', 'region', 'central')], [
        fadlItem('2', 'expertise', 'cardiology'),
        fadlItem('7', 'location', 'main-clinic'),
    ]))->toBe([
        'type' => 'uamsweb_block_fad_locations',
        'enabled' => true,
        'block_heading' => 'Our clinics',
        'hide_block_heading' => false,
        'block_description' => 'Find one',
        'background_color' => 'bg-gray',
        'more_query' => true,
        'location_types' => ['clinic'],
        'expertise' => ['cardiology-2'],
        'regions' => ['central'],
        'locations' => ['main-clinic-7'],
        'more_heading' => 'More clinics',
        'more_button_text' => 'See all',
        'more_button_url' => 'https://example.org/find/',
        'more_button_target' => true,
        'more_button_link_description' => 'All locations',
    ]);
});

it('reads attributes that carry no data key', function (): void {
    $set = (new FindADocLocationsParser)->parse(fadlData(), 'default', fadlAssetImporter(), new NullOutput, [], fadlItems());
    unset($set['id']);

    expect($set)->toBe(fadlExpected());
});

it('reads a data key that is not an array as empty data, and skips the block', function (): void {
    expect((new FindADocLocationsParser)->parse(
        array_merge(fadlData(), ['data' => 'x']),
        'default',
        fadlAssetImporter(),
        new NullOutput,
        [],
        fadlItems()
    ))->toBe([]);
});

it('reads integer and float text values as strings', function (mixed $value, string $text): void {
    expect(fadlParse(fadlData([
        'block_fad_locations_heading' => $value,
        'block_fad_locations_description' => $value,
        'block_fad_locations_background_color' => $value,
    ])))->toBe(fadlExpected([
        'block_heading' => $text,
        'block_description' => $text,
        'background_color' => $text,
    ]));
})->with([
    'an integer' => [5, '5'],
    'a float' => [1.5, '1.5'],
]);

it('defaults a background color that is missing, null or not a string or number', function (mixed $value): void {
    expect(fadlParse(fadlData(['block_fad_locations_background_color' => $value]))['background_color'] ?? null)
        ->toBe('bg-white');
})->with([
    'null' => [null],
    'an array' => [['bg-gray']],
    'true' => [true],
]);

it('truncates the description to 290 characters', function (int $length, int $kept): void {
    expect(fadlParse(fadlData(['block_fad_locations_description' => str_repeat('a', $length)]))['block_description'] ?? null)
        ->toBe(str_repeat('a', $kept));
})->with([
    'one over' => [291, 290],
    'exactly' => [290, 290],
]);

it('skips a block missing a required field, with a warning', function (array $fields, string $reason): void {
    ProcessingContext::setWordPressPageLink('https://example.org/clinics/');

    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();
    $data = fadlData($fields);

    expect(fadlParse($data))->toBe([]);

    $logSpy->shouldHaveReceived('warning', [
        "FindADocLocationsParser::parse: Skipping wp:acf/fad-locations block due to missing required {$reason} (from entry: https://example.org/clinics/)",
        ['site' => 'default', 'page_slug' => 'page', 'available_keys' => array_keys($data), 'entry_url' => 'https://example.org/clinics/'],
    ]);
})->with([
    'no heading' => [['block_fad_locations_heading' => null], 'heading'],
    'a heading of tags only' => [['block_fad_locations_heading' => '<b></b>'], 'heading'],
    'a heading of true' => [['block_fad_locations_heading' => true], 'heading'],
    'no description' => [['block_fad_locations_description' => ''], 'description'],
    'no filter' => [['block_fad_locations_filter_ids' => []], 'filters'],
    'a filter that is not an array' => [['block_fad_locations_filter_ids' => '7'], 'filters'],
    'an empty background color' => [['block_fad_locations_background_color' => ''], 'background color'],
]);

it('stops at the first missing required field', function (): void {
    expect(fadlWarnings(fn (): array => fadlParse(['block_fad_locations_background_color' => ''])))->toBe([
        'FindADocLocationsParser::parse: Skipping wp:acf/fad-locations block due to missing required heading',
    ]);
});

/**
 * The set a block with only one filter maps to.
 *
 * @param  string  $setKey  The set key the filter maps to
 * @param  list<string>  $values  The mapped values
 * @return array<string, mixed> The replicator set without its id
 */
function fadlOneFilter(string $setKey, array $values): array
{
    $set = fadlExpected();
    unset($set['locations']);
    $set[$setKey] = $values;

    return $set;
}

it('keeps a block with any one filter', function (string $key, array $items, array $terms, array $expected): void {
    expect(fadlParse([
        'block_fad_locations_heading' => 'Heading',
        'block_fad_locations_description' => 'Description',
        $key => ['5'],
    ], $terms, $items))->toBe($expected);
})->with([
    'a location type' => ['block_fad_locations_filter_type', [], [fadlTerm('5', 'location_type', 'clinic')], fadlOneFilter('location_types', ['clinic'])],
    'expertise' => ['block_fad_locations_filter_aoe', [fadlItem('5', 'expertise', 'aoe')], [], fadlOneFilter('expertise', ['aoe-5'])],
    'a region' => ['block_fad_locations_filter_region', [], [fadlTerm('5', 'region', 'north')], fadlOneFilter('regions', ['north'])],
    'a location' => ['block_fad_locations_filter_ids', [fadlItem('5', 'location', 'loc')], [], fadlOneFilter('locations', ['loc-5'])],
]);

it('keeps a block whose filters resolve to nothing', function (): void {
    $expected = fadlExpected();
    unset($expected['locations']);

    expect(fadlParse(fadlData(['block_fad_locations_filter_ids' => [null]])))->toBe($expected);
});

it('reads string and integer filter ids and skips the rest', function (): void {
    expect(fadlParse(fadlData(['block_fad_locations_filter_ids' => [null, '7', 9.0, 8, ['7'], true]]), [], [
        fadlItem('7', 'location', 'a'),
        fadlItem('8', 'location', 'b'),
        fadlItem('9', 'location', 'c'),
        fadlItem('1', 'location', 'd'),
    ])['locations'] ?? null)->toBe(['a-7', 'b-8']);
});

it('reads string and integer term ids and skips the rest', function (string $key, string $taxonomy, string $setKey): void {
    expect(fadlParse(fadlData([$key => [null, '3', 5.0, 4, true]]), [
        fadlTerm('3', $taxonomy, 'a'),
        fadlTerm('4', $taxonomy, 'b'),
        fadlTerm('5', $taxonomy, 'c'),
        fadlTerm('1', $taxonomy, 'd'),
    ])[$setKey] ?? null)->toBe(['a', 'b']);
})->with([
    'location types' => ['block_fad_locations_filter_type', 'location_type', 'location_types'],
    'regions' => ['block_fad_locations_filter_region', 'region', 'regions'],
]);

it('looks a post up by both its id and its type, skipping what is not an item', function (string $key, string $type, string $setKey): void {
    expect(fadlParse(fadlData([$key => ['5']]), [], [
        'not an item',
        fadlItem('5', 'provider', 'wrong-type'),
        fadlItem('50', $type, 'wrong-id'),
        fadlItem('5', $type, 'right'),
        fadlItem('5', $type, 'later'),
        fadlItem('7', 'location', 'main-clinic'),
    ])[$setKey] ?? null)->toBe(['right-5']);
})->with([
    'expertise' => ['block_fad_locations_filter_aoe', 'expertise', 'expertise'],
    'a location' => ['block_fad_locations_filter_ids', 'location', 'locations'],
]);

it('looks a term up by both its id and its taxonomy, skipping what is not a term', function (string $key, string $taxonomy, string $setKey): void {
    expect(fadlParse(fadlData([$key => ['5']]), [
        'not a term',
        fadlTerm('5', 'category', 'wrong-taxonomy'),
        fadlTerm('50', $taxonomy, 'wrong-id'),
        fadlTerm('5', $taxonomy, 'right'),
        fadlTerm('5', $taxonomy, 'later'),
    ])[$setKey] ?? null)->toBe(['right']);
})->with([
    'a location type' => ['block_fad_locations_filter_type', 'location_type', 'location_types'],
    'a region' => ['block_fad_locations_filter_region', 'region', 'regions'],
]);

it('warns about each filtered post it cannot find, and keeps the ones it can', function (string $key, string $type, string $label, string $setKey): void {
    ProcessingContext::setWordPressPageLink('https://example.org/clinics/');

    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();

    expect(fadlParse(fadlData([$key => ['9', '5', '8']]), [], [
        fadlItem('9', $type, ''),
        fadlItem('5', $type, 'found'),
        fadlItem('8', $type, null),
        fadlItem('7', 'location', 'main-clinic'),
    ])[$setKey] ?? null)->toBe(['found-5']);

    foreach (['9', '8'] as $id) {
        $logSpy->shouldHaveReceived('warning', [
            "FindADocLocationsParser::parse: {$label} post ID '{$id}' not found in XML items for wp:acf/fad-locations block (from entry: https://example.org/clinics/)",
            ['site' => 'default', 'page_slug' => 'page', 'post_id' => $id, 'post_type' => $type, 'entry_url' => 'https://example.org/clinics/'],
        ]);
    }
})->with([
    'expertise' => ['block_fad_locations_filter_aoe', 'expertise', 'Expertise', 'expertise'],
    'a location' => ['block_fad_locations_filter_ids', 'location', 'Location', 'locations'],
]);

it('warns about each filtered term it cannot find, and keeps the ones it can', function (string $key, string $taxonomy, string $label, string $setKey): void {
    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();

    expect(fadlParse(fadlData([$key => ['9', '5', '8']]), [
        fadlTerm('9', $taxonomy, ''),
        fadlTerm('5', $taxonomy, 'found'),
    ])[$setKey] ?? null)->toBe(['found']);

    foreach (['9', '8'] as $id) {
        $logSpy->shouldHaveReceived('warning', [
            "FindADocLocationsParser::parse: {$label} term ID '{$id}' not found in XML terms for wp:acf/fad-locations block",
            ['site' => 'default', 'page_slug' => 'page', 'term_id' => $id],
        ]);
    }
})->with([
    'a location type' => ['block_fad_locations_filter_type', 'location_type', 'Location type', 'location_types'],
    'a region' => ['block_fad_locations_filter_region', 'region', 'Region', 'regions'],
]);

it('omits a filter whose ids all miss', function (): void {
    expect(fadlParse(fadlData(['block_fad_locations_filter_aoe' => ['9'], 'block_fad_locations_filter_type' => ['9']])))
        ->toBe(fadlExpected());
});

it('records each term it finds, with the page and the block link', function (): void {
    $terms = [fadlTerm('1', 'location_type', 'clinic'), fadlTerm('3', 'region', 'central')];

    fadlParse(fadlData([
        'block_fad_locations_filter_type' => ['1', '9'],
        'block_fad_locations_filter_region' => [3],
        'link' => 'https://example.org/a/',
    ]), $terms, null, 'one');

    expect(FindADocLocationsParser::getTermsUsed())->toBe([
        'location_type' => ['1' => ['slug' => 'one', 'link' => 'https://example.org/a/']],
        'region' => ['3' => ['slug' => 'one', 'link' => 'https://example.org/a/']],
    ]);

    // A later use of the same term replaces the record, and a page with no slug reads as unknown
    fadlParse(fadlData([
        'block_fad_locations_filter_type' => ['1'],
        'block_fad_locations_filter_region' => ['3'],
        'link' => ['x'],
    ]), $terms, null, null);

    expect(FindADocLocationsParser::getTermsUsed())->toBe([
        'location_type' => ['1' => ['slug' => 'unknown', 'link' => '']],
        'region' => ['3' => ['slug' => 'unknown', 'link' => '']],
    ]);
});

it('records the term link from the block data, not the normalized data', function (): void {
    fadlParse(fadlData([
        'block_fad_locations_filter_type' => ['1'],
        'link' => 5,
    ]), [fadlTerm('1', 'location_type', 'clinic')]);

    expect(FindADocLocationsParser::getTermsUsed())->toBe([
        'location_type' => ['1' => ['slug' => 'page', 'link' => '5']],
    ]);
});

it('forgets the recorded terms on reset', function (): void {
    fadlParse(fadlData(['block_fad_locations_filter_region' => ['3']]), [fadlTerm('3', 'region', 'central')]);
    FindADocLocationsParser::resetTermsUsed();

    expect(FindADocLocationsParser::getTermsUsed())->toBe([]);
});

it('adds the more link only when it reads as one', function (mixed $more, bool $query): void {
    $set = fadlParse(fadlMoreData(['block_fad_locations_more' => $more]));

    expect($set['more_query'] ?? null)->toBe($query)
        ->and(array_key_exists('more_heading', $set))->toBe($query);
})->with([
    'a string one' => ['1', true],
    'an integer one' => [1, true],
    'zero' => ['0', false],
    'true' => [true, false],
    'missing' => [null, false],
    'yes' => ['yes', false],
]);

it('maps the more link fields, stripping their tags', function (mixed $urlData, bool $target): void {
    expect(fadlParse(fadlMoreData([
        'block_fad_locations_more_text' => 'More <b>clinics</b>',
        'block_fad_locations_more_button_text' => 'See <i>all</i>',
        'block_fad_locations_more_button_url' => $urlData,
        'block_fad_locations_more_button_description' => '<b>All</b> locations',
    ])))->toBe(fadlExpected([
        'more_query' => true,
        'more_heading' => 'More clinics',
        'more_button_text' => 'See all',
        'more_button_url' => 'https://example.org/find/',
        'more_button_target' => $target,
        'more_button_link_description' => 'All locations',
    ]));
})->with([
    'no target' => [['url' => 'https://example.org/find/'], false],
    'a _blank target' => [['url' => 'https://example.org/find/', 'target' => '_blank'], true],
    'another target' => [['url' => 'https://example.org/find/', 'target' => '_self'], false],
    'a target that is not a string' => [['url' => 'https://example.org/find/', 'target' => ['_blank']], false],
]);

it('skips a block whose more link is missing a field, with a warning', function (array $fields, string $reason): void {
    $data = fadlMoreData($fields);

    expect(fadlWarnings(fn (): array => fadlParse($data)))
        ->toBe(["FindADocLocationsParser::parse: Skipping wp:acf/fad-locations block due to missing {$reason}"]);

    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();

    expect(fadlParse($data))->toBe([]);

    $logSpy->shouldHaveReceived('warning', [
        "FindADocLocationsParser::parse: Skipping wp:acf/fad-locations block due to missing {$reason}",
        ['site' => 'default', 'page_slug' => 'page', 'available_keys' => array_keys($data)],
    ]);
})->with([
    'no heading' => [['block_fad_locations_more_text' => '<b></b>'], 'required more heading'],
    'no button text' => [['block_fad_locations_more_button_text' => null], 'required more button text'],
    'no button URL' => [['block_fad_locations_more_button_url' => ['target' => '_blank']], 'or invalid required more button URL'],
    'a button URL that is not an array' => [['block_fad_locations_more_button_url' => 'https://example.org/find/'], 'or invalid required more button URL'],
    'no button description' => [['block_fad_locations_more_button_description' => ''], 'required more button description'],
    'every more field missing' => [[
        'block_fad_locations_more_text' => '',
        'block_fad_locations_more_button_text' => '',
        'block_fad_locations_more_button_url' => [],
        'block_fad_locations_more_button_description' => '',
    ], 'required more heading'],
]);

it('logs no warning for a block it maps', function (): void {
    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();

    expect(fadlParse(fadlMoreData()))->not->toBe([]);

    $logSpy->shouldNotHaveReceived('warning');
});

it('reports a post whose type a scoped run did not read', function (): void {
    ImportWarningManager::clearWarnings();
    MaterializationScope::set(['page']);

    try {
        expect(fadlParse(fadlData(), [], [])['locations'] ?? null)->toBeNull();

        $warnings = ImportWarningManager::getWarningsByCategory('migration_api')['scope_unread_references'] ?? null;
        $warning = \is_array($warnings) && \is_array($warnings[0] ?? null) ? $warnings[0] : [];

        expect($warnings)->toBeArray()->toHaveCount(1)
            ->and($warning)->toMatchArray([
                'reader' => 'FindADocLocationsParser',
                'post_type' => 'location',
                'post_id' => '7',
            ]);
    } finally {
        MaterializationScope::clear();
        ImportWarningManager::clearWarnings();
    }
});
