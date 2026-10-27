<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\LogoListParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\TermDestinationRegistry;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * Pins the parts of `LogoListParser::parse()` that
 * `LogoListBlockImportTest` leaves to chance: the full emitted set, the
 * more-link fields, every sort key, scalar normalization of each attribute,
 * the exact context of each warning, and the filter loops over several ids.
 *
 * Shares its fixture helpers (`logoListAttributes()`, `logoListFixtureTerms()`,
 * `logoListAssetImporter()`) with `LogoListBlockImportTest` through
 * `tests/Support/logo-list-test-support.php`.
 *
 * @command  php vendor/bin/pest tests/Integration/LogoListParserSetTest.php
 */
require_once __DIR__.'/../Support/logo-list-test-support.php';

uses(StatamicTestCase::class);

// The parser writes three process-statics; clear all of them on both sides so
// no case reads residue from another.
beforeEach(function (): void {
    LogoListParser::resetTermsUsed();
    BaseEntryCreator::clearDeferredTermQueue();
    TermDestinationRegistry::clear();
    ProcessingContext::clearWordPressPageLink();
});

afterEach(function (): void {
    LogoListParser::resetTermsUsed();
    BaseEntryCreator::clearDeferredTermQueue();
    TermDestinationRegistry::clear();
    ProcessingContext::clearWordPressPageLink();
});

/**
 * Parse block data through the real parser, with a caller-chosen page slug.
 *
 * @param  array<string, mixed>  $overrides  Data keys layered over `logoListAttributes()`
 * @return array<int|string, mixed>
 */
function logoListSet(array $overrides = [], ?string $pageSlug = 'logo-list-set-test'): array
{
    return (new LogoListParser)->parse(
        logoListAttributes($overrides),
        'default',
        logoListAssetImporter(),
        new BufferedOutput,
        logoListFixtureTerms(),
        $pageSlug,
    );
}

/**
 * The set with its random row ids removed, so it can be compared whole.
 *
 * @param  array<int|string, mixed>  $set
 * @return array<int|string, mixed>
 */
function logoListSetWithoutIds(array $set): array
{
    expect($set['id'] ?? null)->toBeString()->toHaveLength(8);
    unset($set['id']);

    /** @var array<int, array<string, mixed>> $sort */
    $sort = $set['logo_list_sort'] ?? [];

    foreach ($sort as $index => $row) {
        expect($row['id'] ?? null)->toBeString()->toHaveLength(8);
        unset($sort[$index]['id']);
    }

    $set['logo_list_sort'] = $sort;

    return $set;
}

/**
 * Every term the parser queued for the batched creation pass.
 *
 * @return array<string, array<string, array<string, mixed>>>
 */
function logoListDeferredQueue(): array
{
    $property = new ReflectionProperty(BaseEntryCreator::class, 'deferredTermQueue');

    /** @var array<string, array<string, array<string, mixed>>> $queue */
    $queue = $property->getValue();

    return $queue;
}

/**
 * Assert a warning was logged with exactly this message and this context.
 *
 * @param  array<string, mixed>  $context
 */
function expectLogoListWarning(mixed $logSpy, string $message, array $context): void
{
    /** @var MockInterface $logSpy */
    $logSpy->shouldHaveReceived('warning', [$message, $context]);
}

it('emits every field of a fully populated grid block', function (): void {
    TermDestinationRegistry::registerCollectionEntry('logo-list-label', '31', 'logos_item_labels', 'entry-label-31');

    $set = logoListSet([
        'block_logo_list_heading' => '<strong>Partners</strong>',
        'block_logo_list_hide_heading' => '1',
        'block_logo_list_description' => '<p>Our partners</p>',
        'block_logo_list_background_color' => 'bg-white',
        'block_logo_list_filter_ids' => ['7908'],
        'block_logo_list_filter_logic' => 'AND',
        'block_logo_list_labels_ids' => ['31'],
        'block_logo_list_labels_logic' => 'IN',
        'block_logo_list_featured' => '1',
        'block_logo_list_layout' => 'grid',
        'block_logo_list_width' => 'alignwide',
        'block_logo_list_titles' => '1',
        'block_logo_list_labels' => '1',
        'block_logo_list_shortdescr' => '1',
        'block_logo_list_links' => '1',
        'block_logo_list_links_target' => '0',
        'block_logo_list_order' => ['meta_value|DESC'],
        'block_logo_list_max' => '6',
        'block_logo_list_more' => '1',
        'block_logo_list_more_text' => '<em>More partners</em>',
        'block_logo_list_more_button_text' => '<b>See all</b>',
        'block_logo_list_more_button_url' => ['url' => 'https://example.org/partners', 'target' => '_blank'],
        'block_logo_list_more_button_description' => '<span>Every partner</span>',
    ]);

    expect(logoListSetWithoutIds($set))->toBe([
        'type' => 'uamsweb_block_logo_list',
        'block_heading' => 'Partners',
        'hide_block_heading' => true,
        'background_color' => 'bg-white',
        'logo_list_filter_category' => ['default_2024-best-of-northwest-arkansas'],
        'logo_list_filter_category_logic' => 'all',
        'logo_list_featured' => true,
        'logo_list_layout' => 'grid',
        'logo_list_labels' => true,
        'logo_list_links' => true,
        'logo_list_sort' => [
            ['type' => 'sort_option', 'enabled' => true, 'sort_by' => 'logo_title', 'sort_order' => 'desc'],
        ],
        'more_query' => true,
        'enabled' => true,
        'block_description' => 'Our partners',
        'logo_list_filter_label' => ['entry-label-31'],
        'logo_list_filter_label_logic' => 'any',
        'logo_list_width' => 'alignwide',
        'logo_list_titles' => true,
        'logo_list_shortdescr' => true,
        'logo_list_links_target' => false,
        'logo_list_max' => 6,
        'more_heading' => 'More partners',
        'more_button_text' => 'See all',
        'more_button_url' => 'https://example.org/partners',
        'more_button_target' => true,
        'more_button_link_description' => 'Every partner',
    ]);
})->group('blocks', 'logo-list');

it('reads attributes passed without a data wrapper', function (): void {
    /** @var array<string, mixed> $data */
    $data = logoListAttributes(['block_logo_list_heading' => 'Top level'])['data'];

    $set = (new LogoListParser)->parse(
        $data,
        'default',
        logoListAssetImporter(),
        new BufferedOutput,
        logoListFixtureTerms(),
    );

    expect($set['block_heading'] ?? null)->toBe('Top level');
})->group('blocks', 'logo-list');

it('treats a non-array data key as an empty block', function (): void {
    $logSpy = Log::spy();

    $set = (new LogoListParser)->parse(
        ['data' => 'not an array', 'block_logo_list_heading' => 'Ignored'],
        'default',
        logoListAssetImporter(),
        new BufferedOutput,
        logoListFixtureTerms(),
    );

    expect($set)->toBe([]);

    expectLogoListWarning(
        $logSpy,
        'LogoListParser::parse: Skipping wp:acf/logo-list block due to missing required heading',
        ['site' => 'default', 'page_slug' => null],
    );
})->group('blocks', 'logo-list');

it('applies the documented defaults when optional attributes are absent', function (): void {
    $set = (new LogoListParser)->parse(
        ['data' => [
            'block_logo_list_heading' => 'Defaults',
            'block_logo_list_filter_ids' => ['7908'],
            'block_logo_list_links' => '1',
        ]],
        'default',
        logoListAssetImporter(),
        new BufferedOutput,
        logoListFixtureTerms(),
    );

    expect(logoListSetWithoutIds($set))->toBe([
        'type' => 'uamsweb_block_logo_list',
        'block_heading' => 'Defaults',
        'hide_block_heading' => false,
        'background_color' => 'bg-auto',
        'logo_list_filter_category' => ['default_2024-best-of-northwest-arkansas'],
        'logo_list_filter_category_logic' => 'any',
        'logo_list_featured' => false,
        'logo_list_layout' => 'grid',
        'logo_list_labels' => false,
        'logo_list_links' => true,
        'logo_list_sort' => [
            ['type' => 'sort_option', 'enabled' => true, 'sort_by' => 'title', 'sort_order' => 'asc'],
        ],
        'more_query' => false,
        'enabled' => true,
        'logo_list_width' => 'alignfull',
        'logo_list_titles' => false,
        'logo_list_shortdescr' => false,
        'logo_list_links_target' => true,
    ]);
})->group('blocks', 'logo-list');

it('falls back to the default sort when the order is an empty list', function (): void {
    $set = logoListSet(['block_logo_list_order' => []]);

    expect(logoListSetWithoutIds($set)['logo_list_sort'] ?? null)->toBe([
        ['type' => 'sort_option', 'enabled' => true, 'sort_by' => 'title', 'sort_order' => 'asc'],
    ]);
})->group('blocks', 'logo-list');

it('maps every sort key in order and skips unusable entries', function (): void {
    $set = logoListSet([
        'block_logo_list_order' => [
            'meta_value|ASC',
            '',
            7,
            null,
            'title|DESC',
            'menu_order|ASC',
            'rand|DESC',
        ],
    ]);

    expect(logoListSetWithoutIds($set)['logo_list_sort'] ?? null)->toBe([
        ['type' => 'sort_option', 'enabled' => true, 'sort_by' => 'logo_title', 'sort_order' => 'asc'],
        ['type' => 'sort_option', 'enabled' => true, 'sort_by' => 'title', 'sort_order' => 'desc'],
        ['type' => 'sort_option', 'enabled' => true, 'sort_by' => 'order_values', 'sort_key' => 'default-general', 'sort_order' => 'asc'],
        ['type' => 'sort_option', 'enabled' => true, 'sort_by' => 'random'],
    ]);
})->group('blocks', 'logo-list');

it('scopes the menu-order sort key to the site handle', function (): void {
    $set = (new LogoListParser)->parse(
        logoListAttributes(['block_logo_list_order' => ['menu_order|DESC']]),
        'patient_care',
        logoListAssetImporter(),
        new BufferedOutput,
        logoListFixtureTerms(),
    );

    /** @var array<int, array<string, mixed>> $sort */
    $sort = $set['logo_list_sort'] ?? [];

    expect($sort[0]['sort_key'] ?? null)->toBe('patient_care-general');
})->group('blocks', 'logo-list');

it('gives every sort row its own id', function (): void {
    $set = logoListSet(['block_logo_list_order' => ['title|ASC', 'rand|ASC']]);

    /** @var array<int, array<string, mixed>> $sort */
    $sort = $set['logo_list_sort'] ?? [];

    expect($sort[0]['id'] ?? null)->toBeString()->toHaveLength(8)
        ->and($sort[1]['id'] ?? null)->toBeString()->toHaveLength(8)
        ->and($sort[0]['id'] ?? null)->not->toBe($sort[1]['id'] ?? null);
})->group('blocks', 'logo-list');

it('normalizes integer and float attribute values to strings', function (): void {
    $set = logoListSet([
        'block_logo_list_heading' => 2024,
        'block_logo_list_description' => 1.5,
        'block_logo_list_hide_heading' => 1,
        'block_logo_list_featured' => 1.0,
    ]);

    expect($set['block_heading'] ?? null)->toBe('2024')
        ->and($set['block_description'] ?? null)->toBe('1.5')
        ->and($set['hide_block_heading'] ?? null)->toBeTrue()
        // PHP writes the float 1.0 as "1", so it reads as enabled
        ->and($set['logo_list_featured'] ?? null)->toBeTrue();
})->group('blocks', 'logo-list');

it('falls back to the default for attribute values that are not scalar', function (): void {
    $set = logoListSet([
        'block_logo_list_background_color' => ['bg-white'],
        'block_logo_list_layout' => null,
        'block_logo_list_links' => '1',
        'block_logo_list_links_target' => true,
        'block_logo_list_description' => false,
    ]);

    expect($set['background_color'] ?? null)->toBe('bg-auto')
        ->and($set['logo_list_layout'] ?? null)->toBe('grid')
        ->and($set['logo_list_links_target'] ?? null)->toBeTrue()
        ->and($set)->not->toHaveKey('block_description');
})->group('blocks', 'logo-list');

it('reads the optional max from every scalar shape', function (mixed $max, ?int $expected): void {
    $set = logoListSet(['block_logo_list_max' => $max]);

    if ($expected === null) {
        expect($set)->not->toHaveKey('logo_list_max');

        return;
    }

    expect($set['logo_list_max'] ?? null)->toBe($expected);
})->with([
    'an integer' => [8, 8],
    'a numeric string' => ['12', 12],
    'a float' => [4.9, 4],
    'zero' => [0, 0],
    'an empty string' => ['', null],
    'null' => [null, null],
    'a list' => [[3], null],
    'a boolean' => [true, null],
])->group('blocks', 'logo-list');

it('omits the description when it strips to nothing', function (): void {
    $set = logoListSet(['block_logo_list_description' => '<p></p>']);

    expect($set)->not->toHaveKey('block_description');
})->group('blocks', 'logo-list');

it('cuts the description at the first 290 bytes', function (): void {
    $description = implode('', array_map(
        static fn (int $index): string => (string) ($index % 10),
        range(0, 399)
    ));

    $set = logoListSet(['block_logo_list_description' => $description]);

    expect($set['block_description'] ?? null)->toBe(substr($description, 0, 290));
})->group('blocks', 'logo-list');

it('emits the more-link fields without a description or a new-tab target', function (): void {
    $set = logoListSet([
        'block_logo_list_more' => '1',
        'block_logo_list_more_text' => 'More',
        'block_logo_list_more_button_text' => 'See all',
        'block_logo_list_more_button_url' => ['url' => 'https://example.org/all', 'target' => ''],
    ]);

    expect($set['more_heading'] ?? null)->toBe('More')
        ->and($set['more_button_text'] ?? null)->toBe('See all')
        ->and($set['more_button_url'] ?? null)->toBe('https://example.org/all')
        ->and($set['more_button_target'] ?? null)->toBeFalse()
        ->and($set)->not->toHaveKey('more_button_link_description');
})->group('blocks', 'logo-list');

it('treats a more-link with no target key as opening in the same tab', function (): void {
    $set = logoListSet([
        'block_logo_list_more' => '1',
        'block_logo_list_more_text' => 'More',
        'block_logo_list_more_button_text' => 'See all',
        'block_logo_list_more_button_url' => ['url' => 'https://example.org/all'],
    ]);

    expect($set['more_button_target'] ?? null)->toBeFalse();
})->group('blocks', 'logo-list');

it('ignores the more-link fields when the more query is off', function (): void {
    $set = logoListSet([
        'block_logo_list_more' => '0',
        'block_logo_list_more_text' => 'More',
        'block_logo_list_more_button_text' => 'See all',
        'block_logo_list_more_button_url' => ['url' => 'https://example.org/all', 'target' => '_blank'],
    ]);

    expect($set['more_query'] ?? null)->toBeFalse()
        ->and($set)->not->toHaveKey('more_heading')
        ->and($set)->not->toHaveKey('more_button_url');
})->group('blocks', 'logo-list');

it('skips a more-link block missing any one required field, and names all three', function (array $more, array $expectedContext): void {
    /** @var array<string, mixed> $more */
    /** @var array<string, mixed> $expectedContext */
    $logSpy = Log::spy();

    $set = logoListSet(array_merge([
        'block_logo_list_more' => '1',
        'block_logo_list_more_text' => 'More',
        'block_logo_list_more_button_text' => 'See all',
        'block_logo_list_more_button_url' => ['url' => 'https://example.org/all'],
    ], $more));

    expect($set)->toBe([]);

    expectLogoListWarning(
        $logSpy,
        'LogoListParser::parse: Skipping wp:acf/logo-list block due to missing required more link fields',
        array_merge([
            'site' => 'default',
            'page_slug' => 'logo-list-set-test',
            'more_heading' => 'More',
            'more_button_text' => 'See all',
            'more_button_url' => 'https://example.org/all',
        ], $expectedContext),
    );
})->with([
    'no heading' => [['block_logo_list_more_text' => ''], ['more_heading' => '']],
    'no button text' => [['block_logo_list_more_button_text' => '<i></i>'], ['more_button_text' => '']],
    'no url key' => [['block_logo_list_more_button_url' => ['target' => '_blank']], ['more_button_url' => '']],
    'an empty url' => [['block_logo_list_more_button_url' => ['url' => '']], ['more_button_url' => '']],
    'a url that is not a string' => [['block_logo_list_more_button_url' => ['url' => ['x']]], ['more_button_url' => '']],
])->group('blocks', 'logo-list');

it('names the site and page in the missing-heading warning', function (): void {
    $logSpy = Log::spy();

    expect(logoListSet(['block_logo_list_heading' => '<p> </p>'], 'about-us'))->toBe([]);

    expectLogoListWarning(
        $logSpy,
        'LogoListParser::parse: Skipping wp:acf/logo-list block due to missing required heading',
        ['site' => 'default', 'page_slug' => 'about-us'],
    );
})->group('blocks', 'logo-list');

it('names the site and page in the missing-categories warning', function (): void {
    $logSpy = Log::spy();

    expect(logoListSet(['block_logo_list_filter_ids' => '7908'], 'about-us'))->toBe([]);

    expectLogoListWarning(
        $logSpy,
        'LogoListParser::parse: Skipping wp:acf/logo-list block due to missing required filter categories',
        ['site' => 'default', 'page_slug' => 'about-us'],
    );
})->group('blocks', 'logo-list');

it('names the orders it could not map in the invalid-sort warning', function (): void {
    $logSpy = Log::spy();

    expect(logoListSet(['block_logo_list_order' => ['bogus|ASC', '']], 'about-us'))->toBe([]);

    expectLogoListWarning(
        $logSpy,
        'LogoListParser::parse: Skipping wp:acf/logo-list block due to invalid sort order',
        ['site' => 'default', 'page_slug' => 'about-us', 'orders' => ['bogus|ASC', '']],
    );
})->group('blocks', 'logo-list');

it('appends the page link to a warning when one is known', function (): void {
    ProcessingContext::setWordPressPageLink('https://uamshealth.com/about-us/');
    $logSpy = Log::spy();

    expect(logoListSet(['block_logo_list_heading' => ''], 'about-us'))->toBe([]);

    expectLogoListWarning(
        $logSpy,
        'LogoListParser::parse: Skipping wp:acf/logo-list block due to missing required heading (from entry: https://uamshealth.com/about-us/)',
        ['site' => 'default', 'page_slug' => 'about-us', 'entry_url' => 'https://uamshealth.com/about-us/'],
    );
})->group('blocks', 'logo-list');

it('resolves integer filter ids and skips ids that are neither strings nor integers', function (): void {
    $set = logoListSet(['block_logo_list_filter_ids' => [null, ['7256'], 7908, 1.5, '7256']]);

    expect($set['logo_list_filter_category'] ?? null)->toBe([
        'default_2024-best-of-northwest-arkansas',
        'default_uams-health-stuff_official-orthopaedics-sports-medicine-provider_northwest-arkansas',
    ]);
})->group('blocks', 'logo-list');

it('keeps resolving filter ids after an unknown one', function (): void {
    $set = logoListSet(['block_logo_list_filter_ids' => ['999999', '7908']]);

    expect($set['logo_list_filter_category'] ?? null)->toBe(['default_2024-best-of-northwest-arkansas']);
})->group('blocks', 'logo-list');

it('lists recorded entry ids before the slugs of unrecorded terms', function (): void {
    TermDestinationRegistry::registerCollectionEntry('logo-list-category', '7256', 'logos_item_categories', 'entry-id-7256');

    $set = logoListSet(['block_logo_list_filter_ids' => ['7908', '7256', 7254]]);

    expect($set['logo_list_filter_category'] ?? null)->toBe([
        'entry-id-7256',
        'default_2024-best-of-northwest-arkansas',
        'default_uams-health-stuff',
    ]);
})->group('blocks', 'logo-list');

it('queues only the unrecorded category terms for creation', function (): void {
    TermDestinationRegistry::registerCollectionEntry('logo-list-category', '7908', 'logos_item_categories', 'entry-id-7908');

    logoListSet(['block_logo_list_filter_ids' => ['7908', '7256']]);

    expect(logoListDeferredQueue())->toBe([
        'logos_item_categories' => [
            'default' => [
                'uams-health-stuff_official-orthopaedics-sports-medicine-provider_northwest-arkansas' => [
                    'original' => 'uams-health-stuff_official-orthopaedics-sports-medicine-provider_northwest-arkansas',
                    'name' => 'Northwest Arkansas',
                ],
            ],
        ],
    ]);
})->group('blocks', 'logo-list');

it('records a term only for an unrecorded category, and once per page', function (): void {
    TermDestinationRegistry::registerCollectionEntry('logo-list-category', '7908', 'logos_item_categories', 'entry-id-7908');

    logoListSet(['block_logo_list_filter_ids' => ['7908', '7254', '7254']], 'page-one');
    logoListSet(['block_logo_list_filter_ids' => ['7254']], 'page-two');
    logoListSet(['block_logo_list_filter_ids' => ['7254']], 'page-one');

    expect(LogoListParser::getTermsUsed())->toBe([
        'logos_item_categories' => [
            'default_uams-health-stuff' => ['page-one', 'page-two'],
        ],
    ]);
})->group('blocks', 'logo-list');

it('compares page slugs strictly when recording a term', function (): void {
    logoListSet(['block_logo_list_filter_ids' => ['7908']], '1');
    logoListSet(['block_logo_list_filter_ids' => ['7908']], '01');

    expect(LogoListParser::getTermsUsed()['logos_item_categories']['default_2024-best-of-northwest-arkansas'] ?? null)
        ->toBe(['1', '01']);
})->group('blocks', 'logo-list');

it('records no term for an empty page slug', function (): void {
    logoListSet(['block_logo_list_filter_ids' => ['7908']], '');

    expect(LogoListParser::getTermsUsed())->toBe([]);
})->group('blocks', 'logo-list');

it('keeps resolving labels after a malformed or unrecorded one, and counts the losses', function (): void {
    TermDestinationRegistry::registerCollectionEntry('logo-list-label', '31', 'logos_item_labels', 'entry-label-31');
    TermDestinationRegistry::registerCollectionEntry('logo-list-label', 32, 'logos_item_labels', 'entry-label-32');
    TermDestinationRegistry::registerCollectionEntry('logo-list-label', '33', 'logos_item_labels', '');
    $logSpy = Log::spy();

    $set = logoListSet(['block_logo_list_labels_ids' => [null, '999', '31', '33', ['31'], 32]], 'about-us');

    expect($set['logo_list_filter_label'] ?? null)->toBe(['entry-label-31', 'entry-label-32']);

    expectLogoListWarning(
        $logSpy,
        'LogoListParser::parse: Omitting logo list filter labels with no imported logos_item_labels entry',
        ['site' => 'default', 'page_slug' => 'about-us', 'unresolved_labels' => 2],
    );
})->group('blocks', 'logo-list');

it('stores a label entry once, comparing entry ids strictly', function (): void {
    TermDestinationRegistry::registerCollectionEntry('logo-list-label', '31', 'logos_item_labels', '1');
    TermDestinationRegistry::registerCollectionEntry('logo-list-label', '32', 'logos_item_labels', '01');
    TermDestinationRegistry::registerCollectionEntry('logo-list-label', '34', 'logos_item_labels', '1');
    $logSpy = Log::spy();

    $set = logoListSet(['block_logo_list_labels_ids' => ['31', '32', '34']]);

    expect($set['logo_list_filter_label'] ?? null)->toBe(['1', '01']);

    $logSpy->shouldNotHaveReceived('warning');
})->group('blocks', 'logo-list');

it('does not treat a label term listed among the filter ids as a category', function (): void {
    // WordPress term ids are unique across taxonomies, so a label id can only
    // reach the category filter through malformed block data. It must not be
    // emitted as a category slug, or queued into the category taxonomy.
    $xml = simplexml_load_string(<<<'XML'
        <rss xmlns:wp="http://wordpress.org/export/1.2/"><channel>
            <wp:term>
                <wp:term_id>41</wp:term_id>
                <wp:term_taxonomy>logo-list-label</wp:term_taxonomy>
                <wp:term_slug>featured-partner</wp:term_slug>
                <wp:term_parent></wp:term_parent>
                <wp:term_name>Featured Partner</wp:term_name>
            </wp:term>
        </channel></rss>
        XML);

    if ($xml === false) {
        throw new RuntimeException('Expected the inline label term to parse.');
    }

    $terms = [...logoListFixtureTerms()];

    foreach ($xml->channel->children('wp', true)->term as $term) {
        $terms[] = $term;
    }

    $set = (new LogoListParser)->parse(
        logoListAttributes(['block_logo_list_filter_ids' => ['41', '7908']]),
        'default',
        logoListAssetImporter(),
        new BufferedOutput,
        $terms,
        'logo-list-set-test',
    );

    expect($set['logo_list_filter_category'] ?? null)->toBe(['default_2024-best-of-northwest-arkansas'])
        ->and(array_keys(logoListDeferredQueue()['logos_item_categories']['default'] ?? []))
        ->toBe(['2024-best-of-northwest-arkansas']);
})->group('blocks', 'logo-list');

it('keeps the parent in a category slug when the parent slug is also a label slug', function (): void {
    // A label carrying the parent's slug comes first in the export; the parent
    // lookup stays within the category taxonomy, so the slug keeps the category
    // chain (#130).
    $xml = simplexml_load_string(<<<'XML'
        <rss xmlns:wp="http://wordpress.org/export/1.2/"><channel>
            <wp:term>
                <wp:term_id>5</wp:term_id>
                <wp:term_taxonomy>logo-list-label</wp:term_taxonomy>
                <wp:term_slug>research</wp:term_slug>
                <wp:term_parent></wp:term_parent>
                <wp:term_name>Research label</wp:term_name>
            </wp:term>
            <wp:term>
                <wp:term_id>9</wp:term_id>
                <wp:term_taxonomy>logo-list-category</wp:term_taxonomy>
                <wp:term_slug>research</wp:term_slug>
                <wp:term_parent></wp:term_parent>
                <wp:term_name>Research</wp:term_name>
            </wp:term>
            <wp:term>
                <wp:term_id>12</wp:term_id>
                <wp:term_taxonomy>logo-list-category</wp:term_taxonomy>
                <wp:term_slug>partners</wp:term_slug>
                <wp:term_parent>research</wp:term_parent>
                <wp:term_name>Partners</wp:term_name>
            </wp:term>
        </channel></rss>
        XML);

    if ($xml === false) {
        throw new RuntimeException('Expected the inline terms to parse.');
    }

    $terms = [];

    foreach ($xml->channel->children('wp', true)->term as $term) {
        $terms[] = $term;
    }

    $set = (new LogoListParser)->parse(
        logoListAttributes(['block_logo_list_filter_ids' => ['12']]),
        'default',
        logoListAssetImporter(),
        new BufferedOutput,
        $terms,
        'logo-list-set-test',
    );

    expect($set['logo_list_filter_category'] ?? null)->toBe(['default_research_partners'])
        ->and(array_keys(logoListDeferredQueue()['logos_item_categories']['default'] ?? []))
        ->toBe(['research_partners']);
})->group('blocks', 'logo-list');
