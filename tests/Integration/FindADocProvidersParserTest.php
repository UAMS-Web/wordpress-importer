<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use Symfony\Component\Console\Output\NullOutput;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\MigrationApi\MaterializationScope;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\Blocks\FindADocProvidersParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

/**
 * Tests that `FindADocProvidersParser` maps an ACF Find-a-Doc Providers block
 * onto the `uamsweb_block_fad_providers` set: its required fields, the
 * expertise, location and provider lookups against the export's items, the
 * region term paths, the optional more link, and the warning each skip logs.
 *
 * @command  php vendor/bin/pest tests/Integration/FindADocProvidersParserTest.php
 */
uses(StatamicTestCase::class);

beforeEach(function (): void {
    ProcessingContext::clearWordPressPageLink();
    FindADocProvidersParser::resetTermsUsed();
});

afterEach(function (): void {
    ProcessingContext::clearWordPressPageLink();
    FindADocProvidersParser::resetTermsUsed();
});

/**
 * An `AssetImporter` that imports nothing and knows no source blog.
 */
function fadAssetImporter(): AssetImporter
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
function fadItem(string $id, string $type, ?string $name): SimpleXMLElement
{
    $nameElement = $name === null ? '' : "<wp:post_name>{$name}</wp:post_name>";

    return new SimpleXMLElement(
        '<item xmlns:wp="http://wordpress.org/export/1.2/">'
        ."<wp:post_id>{$id}</wp:post_id><wp:post_type>{$type}</wp:post_type>{$nameElement}</item>"
    );
}

/**
 * An export term with an id, a slug and a parent slug.
 *
 * @param  string  $id  The term id
 * @param  string  $slug  The term slug
 * @param  string  $parent  The parent term's slug, or empty for none
 */
function fadTerm(string $id, string $slug, string $parent = ''): SimpleXMLElement
{
    return new SimpleXMLElement(
        '<wp:term xmlns:wp="http://wordpress.org/export/1.2/">'
        ."<wp:term_id>{$id}</wp:term_id><wp:term_slug>{$slug}</wp:term_slug><wp:term_parent>{$parent}</wp:term_parent></wp:term>"
    );
}

/**
 * Block data with the required fields and one provider filter.
 *
 * @param  array<int|string, mixed>  $fields  Fields to add or override
 * @return array<int|string, mixed> The block data
 */
function fadData(array $fields = []): array
{
    return array_merge([
        'block_fad_providers_heading' => 'Heading',
        'block_fad_providers_description' => 'Description',
        'block_fad_providers_filter_ids' => ['7'],
    ], $fields);
}

/**
 * The items the default data's provider filter resolves against.
 *
 * @return list<SimpleXMLElement> The export items
 */
function fadItems(): array
{
    return [fadItem('7', 'provider', 'dr-smith')];
}

/**
 * Parse block data.
 *
 * @param  array<int|string, mixed>  $data  The block data
 * @param  array<int|string, mixed>  $terms  The export terms
 * @param  array<int|string, mixed>|null  $items  The export items, or null for the default provider
 * @param  ?string  $pageSlug  The page slug
 * @return array<int|string, mixed> The replicator set without its id, or the empty skip result
 */
function fadParse(array $data, array $terms = [], ?array $items = null, ?string $pageSlug = 'page'): array
{
    $set = (new FindADocProvidersParser)->parse(
        ['data' => $data],
        'default',
        fadAssetImporter(),
        new NullOutput,
        $terms,
        $items ?? fadItems(),
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
function fadExpected(array $fields = []): array
{
    return array_merge([
        'type' => 'uamsweb_block_fad_providers',
        'block_heading' => 'Heading',
        'hide_block_heading' => false,
        'block_description' => 'Description',
        'background_color' => 'bg-white',
        'more_query' => false,
        'enable_filtering' => true,
        'enabled' => true,
        'providers' => ['dr-smith-7'],
    ], $fields);
}

it('maps every field of a full block', function (): void {
    expect(fadParse([
        'field_block_fad_providers_heading' => 'Our <b>doctors</b>',
        'field_block_fad_providers_description' => 'Find <i>one</i>',
        'field_block_fad_providers_background_color' => 'bg-gray',
        'field_block_fad_providers_filter_aoe' => ['1'],
        'field_block_fad_providers_filter_location' => ['2'],
        'field_block_fad_providers_filter_region' => ['3'],
        'field_block_fad_providers_filter_ids' => ['7'],
        'field_block_fad_providers_more' => '1',
        'field_block_fad_providers_more_text' => 'More <b>doctors</b>',
        'field_block_fad_providers_more_button_text' => 'See <b>all</b>',
        'field_block_fad_providers_more_button_url' => ['url' => 'https://example.org/find/', 'target' => '_blank'],
        'field_block_fad_providers_more_button_description' => 'All <b>providers</b>',
    ], [fadTerm('3', 'central')], [
        fadItem('1', 'expertise', 'cardiology'),
        fadItem('2', 'location', 'main-clinic'),
        fadItem('7', 'provider', 'dr-smith'),
    ]))->toBe([
        'type' => 'uamsweb_block_fad_providers',
        'block_heading' => 'Our doctors',
        'hide_block_heading' => false,
        'block_description' => 'Find one',
        'background_color' => 'bg-gray',
        'more_query' => true,
        'enable_filtering' => true,
        'enabled' => true,
        'expertise' => ['cardiology-1'],
        'locations' => ['main-clinic-2'],
        'regions' => ['central'],
        'providers' => ['dr-smith-7'],
        'more_heading' => 'More doctors',
        'more_button_text' => 'See all',
        'more_button_url' => 'https://example.org/find/',
        'more_button_target' => true,
        'more_button_link_description' => 'All providers',
    ]);
});

it('reads attributes that carry no data key, or a data key that is not an array', function (array $attributes): void {
    $set = (new FindADocProvidersParser)->parse($attributes, 'default', fadAssetImporter(), new NullOutput, [], fadItems());
    unset($set['id']);

    expect($set)->toBe(fadExpected());
})->with([
    'no data key' => [fadData()],
    'a data key that is not an array' => [array_merge(fadData(), ['data' => 'x'])],
]);

it('reads scalar text values as strings and defaults the rest', function (mixed $value, string $text, string $background): void {
    expect(fadParse(fadData([
        'block_fad_providers_heading' => $value,
        'block_fad_providers_description' => $value,
        'block_fad_providers_background_color' => $value,
    ])))->toBe(fadExpected([
        'block_heading' => $text,
        'block_description' => $text,
        'background_color' => $background,
    ]));
})->with([
    'an integer' => [5, '5', '5'],
    'true' => [true, '1', '1'],
]);

it('defaults a background color that is missing, null or not scalar', function (mixed $value): void {
    expect(fadParse(fadData(['block_fad_providers_background_color' => $value]))['background_color'] ?? null)
        ->toBe('bg-white');
})->with([
    'null' => [null],
    'an array' => [['bg-gray']],
]);

it('truncates the description to 290 characters', function (int $length, int $kept): void {
    expect(fadParse(fadData(['block_fad_providers_description' => str_repeat('a', $length)]))['block_description'] ?? null)
        ->toBe(str_repeat('a', $kept));
})->with([
    'one over' => [291, 290],
    'exactly' => [290, 290],
]);

it('skips a block missing a required field, with a warning', function (array $fields, string $reason): void {
    ProcessingContext::setWordPressPageLink('https://example.org/doctors/');

    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();

    expect(fadParse(fadData($fields)))->toBe([]);

    $logSpy->shouldHaveReceived('warning', [
        "FindADocProvidersParser::parse: Skipping wp:acf/fad-providers block due to missing required {$reason} (from entry: https://example.org/doctors/)",
        ['site' => 'default', 'page_slug' => 'page', 'entry_url' => 'https://example.org/doctors/'],
    ]);
})->with([
    'no heading' => [['block_fad_providers_heading' => null], 'heading'],
    'a heading of tags only' => [['block_fad_providers_heading' => '<b></b>'], 'heading'],
    'a heading that is an array' => [['block_fad_providers_heading' => ['x']], 'heading'],
    'no description' => [['block_fad_providers_description' => ''], 'description'],
    'no filter' => [['block_fad_providers_filter_ids' => []], 'filters'],
    'filters that are all empty' => [['block_fad_providers_filter_ids' => ['', null, ['7']]], 'filters'],
]);

/**
 * The set a block with only one filter maps to.
 *
 * @param  string  $setKey  The set key the filter maps to
 * @param  list<string>  $values  The mapped values
 * @return array<string, mixed> The replicator set without its id
 */
function fadOneFilter(string $setKey, array $values): array
{
    $set = fadExpected();
    unset($set['providers']);
    $set[$setKey] = $values;

    return $set;
}

it('keeps a block with any one filter', function (string $key, array $items, array $terms, array $expected): void {
    expect(fadParse([
        'block_fad_providers_heading' => 'Heading',
        'block_fad_providers_description' => 'Description',
        $key => ['5'],
    ], $terms, $items))->toBe($expected);
})->with([
    'expertise' => ['block_fad_providers_filter_aoe', [fadItem('5', 'expertise', 'aoe')], [], fadOneFilter('expertise', ['aoe-5'])],
    'a location' => ['block_fad_providers_filter_location', [fadItem('5', 'location', 'loc')], [], fadOneFilter('locations', ['loc-5'])],
    'a region' => ['block_fad_providers_filter_region', [], [fadTerm('5', 'north')], fadOneFilter('regions', ['north'])],
    'a provider' => ['block_fad_providers_filter_ids', [fadItem('5', 'provider', 'doc')], [], fadOneFilter('providers', ['doc-5'])],
]);

it('normalizes a filter into a list of non-empty string ids', function (mixed $filter, array $providers): void {
    expect(fadParse(fadData(['block_fad_providers_filter_ids' => $filter]), [], [
        fadItem('7', 'provider', 'a'),
        fadItem('8', 'provider', 'b'),
    ])['providers'] ?? null)->toBe($providers);
})->with([
    'a single string' => ['7', ['a-7']],
    'a single integer' => [8, ['b-8']],
    'a list with empty and non-scalar entries' => [['', 8, null, ['7'], '7'], ['b-8', 'a-7']],
]);

it('looks a post up by both its id and its type, skipping what is not an item', function (): void {
    expect(fadParse(fadData(), [], [
        'not an item',
        fadItem('7', 'expertise', 'wrong-type'),
        fadItem('70', 'provider', 'wrong-id'),
        fadItem('7', 'provider', 'right'),
        fadItem('7', 'provider', 'later'),
    ])['providers'] ?? null)->toBe(['right-7']);
});

it('warns about each filtered post it cannot find', function (string $key, string $type, string $label): void {
    ProcessingContext::setWordPressPageLink('https://example.org/doctors/');

    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();

    $set = fadParse(fadData([$key => ['9', '7']]), [], [fadItem('9', $type, null), fadItem('7', 'provider', 'dr-smith')]);

    expect($set)->not->toHaveKey($key === 'block_fad_providers_filter_aoe' ? 'expertise' : 'locations');

    $logSpy->shouldHaveReceived('warning', [
        "FindADocProvidersParser::parse: {$label} post ID '9' not found in XML items (from entry: https://example.org/doctors/)",
        ['site' => 'default', 'page_slug' => 'page', 'post_id' => '9', 'post_type' => $type, 'entry_url' => 'https://example.org/doctors/'],
    ]);
    $logSpy->shouldHaveReceived('warning', [
        "FindADocProvidersParser::parse: {$label} post ID '7' not found in XML items (from entry: https://example.org/doctors/)",
        ['site' => 'default', 'page_slug' => 'page', 'post_id' => '7', 'post_type' => $type, 'entry_url' => 'https://example.org/doctors/'],
    ]);
})->with([
    'expertise' => ['block_fad_providers_filter_aoe', 'expertise', 'Expertise'],
    'a location' => ['block_fad_providers_filter_location', 'location', 'Location'],
]);

it('warns about a provider it cannot find, and keeps the ones it can', function (): void {
    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();

    expect(fadParse(fadData(['block_fad_providers_filter_ids' => ['9', '7', '8']]), [], [
        fadItem('9', 'provider', ''),
        fadItem('7', 'provider', 'dr-smith'),
    ])['providers'] ?? null)->toBe(['dr-smith-7']);

    $logSpy->shouldHaveReceived('warning', [
        "FindADocProvidersParser::parse: Provider post ID '9' not found in XML items",
        ['site' => 'default', 'page_slug' => 'page', 'post_id' => '9', 'post_type' => 'provider'],
    ]);
    $logSpy->shouldHaveReceived('warning', [
        "FindADocProvidersParser::parse: Provider post ID '8' not found in XML items",
        ['site' => 'default', 'page_slug' => 'page', 'post_id' => '8', 'post_type' => 'provider'],
    ]);
});

it('builds each region slug from its ancestors', function (array $terms, array $regions): void {
    expect(fadParse(fadData(['block_fad_providers_filter_region' => ['3', '9']]), $terms)['regions'] ?? null)
        ->toBe($regions);
})->with([
    'a grandparent and a parent' => [
        [fadTerm('1', 'state'), fadTerm('2', 'central', 'state'), fadTerm('3', 'city', 'central')],
        ['state_central_city', 'term-9'],
    ],
    'a parent slug no term carries' => [[fadTerm('3', 'city', 'gone')], ['city', 'term-9']],
    'the first term carrying the parent slug' => [
        [fadTerm('3', 'city', 'p'), 'not a term', fadTerm('5', 'p'), fadTerm('6', 'p', 'top'), fadTerm('7', 'top')],
        ['p_city', 'term-9'],
    ],
    'a parent term with no id' => [[fadTerm('', 'p'), fadTerm('3', 'city', 'p')], ['city', 'term-9']],
    'a term with no slug' => [[fadTerm('3', ''), fadTerm('9', 'nine')], ['term-3', 'nine']],
    'a term with no id' => [[fadTerm('', 'x', ''), fadTerm('3', 'city')], ['city', 'term-9']],
    'a circular parent' => [[fadTerm('3', 'a', 'b'), fadTerm('9', 'b', 'a')], ['a_b_a', 'b_a_b']],
    'a term that is its own parent' => [[fadTerm('3', 'a', 'a')], ['a_a', 'term-9']],
]);

it('records the pages each region term is used on, once each', function (): void {
    fadParse(fadData(['block_fad_providers_filter_region' => ['3', '4']]), [], null, 'one');
    fadParse(fadData(['block_fad_providers_filter_region' => ['3']]), [], null, 'one');
    fadParse(fadData(['block_fad_providers_filter_region' => ['3']]), [], null, 'two');

    expect(FindADocProvidersParser::getTermsUsed())->toBe(['3' => ['one', 'two'], '4' => ['one']]);
});

it('records no region use without a page slug', function (?string $pageSlug): void {
    expect(fadParse(fadData(['block_fad_providers_filter_region' => ['3']]), [], null, $pageSlug)['regions'] ?? null)
        ->toBe(['term-3'])
        ->and(FindADocProvidersParser::getTermsUsed())->toBe([]);
})->with([
    'null' => [null],
    'empty' => [''],
]);

it('forgets the recorded region uses on reset', function (): void {
    fadParse(fadData(['block_fad_providers_filter_region' => ['3']]));
    FindADocProvidersParser::resetTermsUsed();

    expect(FindADocProvidersParser::getTermsUsed())->toBe([]);
});

it('adds the more link only when it is switched on exactly', function (mixed $more): void {
    expect(fadParse(fadData([
        'block_fad_providers_more' => $more,
        'block_fad_providers_more_text' => 'More',
        'block_fad_providers_more_button_text' => 'See all',
        'block_fad_providers_more_button_url' => ['url' => 'https://example.org/find/'],
    ])))->toBe(fadExpected());
})->with([
    'zero' => ['0'],
    'an integer one' => [1],
    'true' => [true],
    'missing' => [null],
]);

it('opens the more link in a new tab only for a _blank target', function (mixed $urlData, bool $target, ?string $description): void {
    $set = fadParse(fadData([
        'block_fad_providers_more' => '1',
        'block_fad_providers_more_text' => 'More',
        'block_fad_providers_more_button_text' => 'See all',
        'block_fad_providers_more_button_url' => $urlData,
        'block_fad_providers_more_button_description' => $description,
    ]));

    expect($set['more_button_target'] ?? null)->toBe($target)
        ->and($set)->not->toHaveKey('more_button_link_description');
})->with([
    'no target' => [['url' => 'https://example.org/find/'], false, null],
    'another target' => [['url' => 'https://example.org/find/', 'target' => '_self'], false, ''],
]);

it('skips a block whose more link is missing a field, with a warning', function (array $fields, array $context): void {
    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();

    expect(fadParse(fadData(array_merge([
        'block_fad_providers_more' => '1',
        'block_fad_providers_more_text' => 'More',
        'block_fad_providers_more_button_text' => 'See all',
        'block_fad_providers_more_button_url' => ['url' => 'https://example.org/find/'],
    ], $fields))))->toBe([]);

    $logSpy->shouldHaveReceived('warning', [
        'FindADocProvidersParser::parse: Skipping wp:acf/fad-providers block due to missing required more link fields',
        array_merge([
            'site' => 'default',
            'page_slug' => 'page',
            'more_heading' => 'More',
            'more_button_text' => 'See all',
            'more_button_url' => 'https://example.org/find/',
        ], $context),
    ]);
})->with([
    'no heading' => [['block_fad_providers_more_text' => '<b></b>'], ['more_heading' => '']],
    'no button text' => [['block_fad_providers_more_button_text' => null], ['more_button_text' => '']],
    'no button URL' => [['block_fad_providers_more_button_url' => ['target' => '_blank']], ['more_button_url' => '']],
    'a button URL that is not an array' => [['block_fad_providers_more_button_url' => 'https://example.org/find/'], ['more_button_url' => '']],
]);

it('tells numeric term ids apart while walking the ancestors', function (): void {
    expect(fadParse(fadData(['block_fad_providers_filter_region' => ['01']]), [
        fadTerm('01', 'a', 'b'),
        fadTerm('1', 'b', 'c'),
        fadTerm('2', 'c'),
    ])['regions'] ?? null)->toBe(['c_b_a']);
});

it('gives no parent to a term without a parent slug, even when a term has no slug', function (): void {
    expect(fadParse(fadData(['block_fad_providers_filter_region' => ['5']]), [
        fadTerm('5', ''),
        fadTerm('5', 'b'),
    ])['regions'] ?? null)->toBe(['b']);
});

it('tells numeric page slugs apart when recording region use', function (): void {
    fadParse(fadData(['block_fad_providers_filter_region' => ['3']]), [], null, '1');
    fadParse(fadData(['block_fad_providers_filter_region' => ['3']]), [], null, '01');

    expect(FindADocProvidersParser::getTermsUsed())->toBe(['3' => ['1', '01']]);
});

it('reports a provider whose type a scoped run did not read', function (): void {
    ImportWarningManager::clearWarnings();
    MaterializationScope::set(['page']);

    try {
        expect(fadParse(fadData(), [], [])['providers'] ?? null)->toBeNull();

        $warnings = ImportWarningManager::getWarningsByCategory('migration_api')['scope_unread_references'] ?? null;
        $warning = \is_array($warnings) && \is_array($warnings[0] ?? null) ? $warnings[0] : [];

        expect($warnings)->toBeArray()->toHaveCount(1)
            ->and($warning)->toMatchArray([
                'reader' => 'FindADocProvidersParser',
                'post_type' => 'provider',
                'post_id' => '7',
            ]);
    } finally {
        MaterializationScope::clear();
        ImportWarningManager::clearWarnings();
    }
});
