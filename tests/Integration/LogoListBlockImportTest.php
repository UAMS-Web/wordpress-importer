<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\LogoListParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\PageBuilder\AcfBlockParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\TermDestinationRegistry;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * Tests the Logo List page builder BLOCK at the parser tier (issue #6).
 *
 * `tests/Feature/PageBuilderBlockImportTest.php` already drives 27 logo-list
 * variants (`logo_list_v01`…`v17b`) end-to-end through the host app. What those
 * do NOT reach is covered here:
 *
 * - a **hierarchical** category term. Every host variant filters on a flat term
 *   (100 / 200); the verified fixture carries a three-deep chain
 *   (7254 → 7255 → 7256) whose joined `full_slug` is the single most breakable
 *   value the parser produces.
 * - the four `return []` **skip guards**. A dropped block is indistinguishable
 *   from an absent one downstream, so each guard is pinned to its own warning.
 * - the divergence between an **empty** filter list (skip) and an
 *   **unresolvable** one (emit, with an empty category array and no warning).
 * - the process-static term registry `LogoListParser::$termsUsed`. Production
 *   does reset it — `EntryCreator.php:993` after each tree build, and
 *   `ImportWordPress.php:1733` at the end of each content-type cycle — but no
 *   test harness in the repo clears it, so it leaks between tests.
 *
 * Rendering the imported block through the host Antlers partial is deliberately
 * NOT attempted — `_uamsweb_block_logo_list.antlers.html` currently rejects every
 * block the importer emits (loose `!= null` against bool-`false` toggles, a
 * truthy `logo_list_labels` requirement versus a `false` default, and a
 * `logo_list_filter_label_logic` requirement the importer satisfies only when
 * label filters exist). That is the bug class `UAMS-Web/uams-statamic-archive#1514`
 * repaired for the gallery partial; it is filed separately and is out of scope
 * for this test-only ticket.
 *
 * Expected values were pinned by observing the parser's real output against the
 * fixture, then justified against the source — not hand-derived.
 *
 * @command  php vendor/bin/pest tests/Integration/LogoListBlockImportTest.php
 */
require_once __DIR__.'/../Support/logo-list-test-support.php';

uses(StatamicTestCase::class);

// The parser writes two process-statics and nothing in the harness clears them.
// Both hooks, not one: `afterEach` alone would still let the first test in this
// file read residue left by whatever ran before it.
beforeEach(function (): void {
    LogoListParser::resetTermsUsed();
    BaseEntryCreator::clearDeferredTermQueue();
});

afterEach(function (): void {
    LogoListParser::resetTermsUsed();
    BaseEntryCreator::clearDeferredTermQueue();
});

/**
 * The fixture page's raw `content:encoded`, carrying both logo-list blocks.
 */
function logoListFixtureHtml(): string
{
    $xmlParser = new XmlParser;
    $xmlParser->parse(logoListFixturePath());

    $items = $xmlParser->getItems('page');
    $page = $items[0] ?? null;

    // Fail loudly rather than returning '' — an empty document would make every
    // dispatch assertion below vacuously true.
    if (! $page instanceof SimpleXMLElement) {
        throw new RuntimeException('Expected a page item in the logo-list fixture.');
    }

    return (string) $page->children('content', true)->encoded;
}

/**
 * Parse one block's attributes through the real `LogoListParser`.
 *
 * @param  array<string, mixed>  $attributes
 * @return array<string, mixed>
 */
function parseLogoListBlock(array $attributes, ?string $pageSlug = 'logo-list-test'): array
{
    /** @var array<string, mixed> $set */
    $set = (new LogoListParser)->parse(
        $attributes,
        'default',
        logoListAssetImporter(),
        new BufferedOutput,
        logoListFixtureTerms(),
        $pageSlug,
    );

    return $set;
}

/**
 * Route the fixture's whole `content:encoded` through the real ACF dispatcher.
 *
 * @return array<int, array<string, mixed>> The emitted replicator sets
 */
function parseLogoListFixtureViaDispatcher(): array
{
    $result = (new AcfBlockParser)->parseAcfBlocksFromHtml(
        logoListFixtureHtml(),
        'default',
        logoListAssetImporter(),
        new BufferedOutput,
        [],
        logoListFixtureTerms(),
        [],
        'logo-list-test',
    );

    /** @var array<int, array<string, mixed>> $sets */
    $sets = $result['acfSets'] ?? [];

    return $sets;
}

it('routes both wp:acf/logo-list blocks in the verified fixture through AcfBlockParser', function (): void {
    // Drive the real dispatch path rather than calling LogoListParser directly:
    // the routing regex requires a JSON payload, and this is what a WXR hits.
    $sets = parseLogoListFixtureViaDispatcher();

    // Both blocks must survive. A skipped block returns [] and would silently
    // reduce this count, so the count guards against a dropped block.
    expect($sets)->toHaveCount(2);
    expect($sets[0]['type'] ?? null)->toBe('uamsweb_block_logo_list');
    expect($sets[1]['type'] ?? null)->toBe('uamsweb_block_logo_list');

    // Distinct blocks, not the same one twice.
    expect($sets[0]['block_heading'] ?? null)
        ->toBe('UAMS Orthopaedic Wins Gold in the 2024 Best of Northwest Arkansas');
    expect($sets[1]['block_heading'] ?? null)
        ->toBe('Official Orthopaedics & Sports Medicine Provider');

    // Each carries its own resolved category filter.
    expect($sets[0]['logo_list_filter_category'] ?? null)
        ->toBe(['default_2024-best-of-northwest-arkansas']);
    expect($sets[1]['logo_list_filter_category'] ?? null)
        ->toBe(['default_uams-health-stuff_official-orthopaedics-sports-medicine-provider_northwest-arkansas']);
})->group('blocks', 'logo-list');

it('resolves a three-deep hierarchical category term to its full prefixed slug', function (): void {
    // Term 7256 (`northwest-arkansas`) sits under 7255 under 7254. The emitted
    // value is the joined ancestor chain with the site prefix — the highest-value
    // assertion in this file, and untested anywhere else in the suite.
    $set = parseLogoListBlock(logoListAttributes([
        'block_logo_list_filter_ids' => ['7256'],
    ]));

    expect($set['logo_list_filter_category'] ?? null)->toBe([
        'default_uams-health-stuff_official-orthopaedics-sports-medicine-provider_northwest-arkansas',
    ]);
})->group('blocks', 'logo-list');

it('emits the collection entry id the term pass recorded, not a slug', function (): void {
    // wordpress-importer-archive#569. `logo-list-category` is a structured collection in
    // `TaxonomyDispositions`, and the host declares
    // `logo_list_filter_category` as `type: entries` over a collection — so a
    // term slug does not resolve in that field at all. The term pass runs
    // before any block parses, so the destination is already recorded here.
    TermDestinationRegistry::clear();
    TermDestinationRegistry::registerCollectionEntry(
        'logo-list-category',
        '7256',
        'logos_item_categories',
        'entry-id-7256'
    );

    try {
        $set = parseLogoListBlock(logoListAttributes([
            'block_logo_list_filter_ids' => ['7256'],
        ]));

        expect($set['logo_list_filter_category'] ?? null)->toBe(['entry-id-7256']);
    } finally {
        TermDestinationRegistry::clear();
    }
})->group('blocks', 'logo-list');

it('leaves an entry id unprefixed, where a slug would be site-prefixed', function (): void {
    // The slug path runs the value through `addSitePrefixToSlugs()`. An id put
    // through the same call would be corrupted, so the two are accumulated
    // separately and merged only after prefixing.
    TermDestinationRegistry::clear();
    TermDestinationRegistry::registerCollectionEntry(
        'logo-list-category',
        '7256',
        'logos_item_categories',
        'entry-id-7256'
    );

    try {
        $set = parseLogoListBlock(logoListAttributes([
            'block_logo_list_filter_ids' => ['7256'],
        ]));
        $emitted = $set['logo_list_filter_category'] ?? [];

        // Narrow before indexing rather than widening a type; the parser's
        // return is `mixed` to the analyser
        $values = \is_array($emitted) ? \array_values($emitted) : [];
        $first = \is_string($values[0] ?? null) ? $values[0] : '';

        expect($emitted)->toBe(['entry-id-7256'])
            ->and($first)->not->toStartWith('default_');
    } finally {
        TermDestinationRegistry::clear();
    }
})->group('blocks', 'logo-list');

it('still emits the prefixed slug for a term the registry never recorded', function (): void {
    // The flat fallback has to survive: a term whose destination is a taxonomy,
    // or whose pass never ran, must keep resolving to the hierarchical slug.
    TermDestinationRegistry::clear();

    $set = parseLogoListBlock(logoListAttributes([
        'block_logo_list_filter_ids' => ['7256'],
    ]));

    expect($set['logo_list_filter_category'] ?? null)->toBe([
        'default_uams-health-stuff_official-orthopaedics-sports-medicine-provider_northwest-arkansas',
    ]);
})->group('blocks', 'logo-list');

it('emits the label entry ids the term pass recorded, in order, unprefixed', function (): void {
    // wordpress-importer-archive#1220. `logos_item_labels` is a site-scoped collection since
    // `UAMS-Web/uams-statamic-archive#2533`, and the block's label filter is an
    // `entries` picker, so only entry ids resolve there.
    TermDestinationRegistry::clear();
    TermDestinationRegistry::registerCollectionEntry('logo-list-label', '31', 'logos_item_labels', 'entry-label-31');
    TermDestinationRegistry::registerCollectionEntry('logo-list-label', '32', 'logos_item_labels', 'entry-label-32');

    try {
        $set = parseLogoListBlock(logoListAttributes([
            'block_logo_list_labels_ids' => ['32', '31'],
            'block_logo_list_labels_logic' => 'AND',
        ]));

        expect($set['logo_list_filter_label'] ?? null)->toBe(['entry-label-32', 'entry-label-31'])
            ->and($set['logo_list_filter_label_logic'] ?? null)->toBe('all');
    } finally {
        TermDestinationRegistry::clear();
    }
})->group('blocks', 'logo-list');

it('omits an unrecorded label rather than writing its slug, and warns', function (): void {
    // A slug fallback would restore the dangling value wordpress-importer-archive#1220 removes, so an
    // unresolved label is dropped and the block keeps the ones that resolved.
    TermDestinationRegistry::clear();
    TermDestinationRegistry::registerCollectionEntry('logo-list-label', '31', 'logos_item_labels', 'entry-label-31');

    $logSpy = Log::spy();

    try {
        $set = parseLogoListBlock(logoListAttributes([
            'block_logo_list_labels_ids' => ['31', '999'],
        ]));

        expect($set['logo_list_filter_label'] ?? null)->toBe(['entry-label-31']);

        $logSpy->shouldHaveReceived('warning', [
            Mockery::on(
                static fn (mixed $message): bool => \is_string($message)
                    && \str_contains($message, 'no imported logos_item_labels entry')
            ),
            Mockery::any(),
        ]);
    } finally {
        TermDestinationRegistry::clear();
    }
})->group('blocks', 'logo-list');

it('emits no label filter when no label resolves, and queues no flat term', function (): void {
    TermDestinationRegistry::clear();
    Log::spy();

    $set = parseLogoListBlock(logoListAttributes([
        'block_logo_list_labels_ids' => ['999'],
    ]));

    // No entries means no filter, so the logic key is not emitted either
    expect($set)->not->toHaveKey('logo_list_filter_label')
        ->and($set)->not->toHaveKey('logo_list_filter_label_logic')
        ->and(LogoListParser::getTermsUsed())->not->toHaveKey('logos_item_labels');
})->group('blocks', 'logo-list');

it('maps a multi-key sort list in order, with rand carrying no sort order', function (): void {
    // The fixture's second block: menu_order|DESC, rand|ASC, title|ASC.
    $set = parseLogoListBlock(logoListAttributes([
        'block_logo_list_order' => ['menu_order|DESC', 'rand|ASC', 'title|ASC'],
    ]));

    /** @var array<int, array<string, mixed>> $sort */
    $sort = $set['logo_list_sort'] ?? [];

    expect($sort)->toHaveCount(3);

    // menu_order maps to order_values and gains a site-scoped sort key. That key
    // joins with a HYPHEN, while category slugs join with an underscore.
    expect($sort[0]['sort_by'] ?? null)->toBe('order_values');
    expect($sort[0]['sort_key'] ?? null)->toBe('default-general');
    expect($sort[0]['sort_order'] ?? null)->toBe('desc');

    // `rand` is the one branch emitting NO sort_order. Asserting its absence is
    // what catches a regression that emits a stray '' or 'asc'.
    expect($sort[1]['sort_by'] ?? null)->toBe('random');
    expect($sort[1])->not->toHaveKey('sort_order');

    expect($sort[2]['sort_by'] ?? null)->toBe('title');
    expect($sort[2]['sort_order'] ?? null)->toBe('asc');

    // Every row is an enabled replicator set.
    foreach ($sort as $row) {
        expect($row['type'] ?? null)->toBe('sort_option');
        expect($row['enabled'] ?? null)->toBeTrue();
    }
})->group('blocks', 'logo-list');

it('drops a sort entry whose key is not recognized', function (): void {
    // `mapSortOrders()` `continue 2`s on an unknown key. With one good entry left
    // the block still emits — proving the entry is dropped, not the whole block.
    $set = parseLogoListBlock(logoListAttributes([
        'block_logo_list_order' => ['bogus|ASC', 'title|ASC'],
    ]));

    /** @var array<int, array<string, mixed>> $sort */
    $sort = $set['logo_list_sort'] ?? [];

    expect($sort)->toHaveCount(1);
    expect($sort[0]['sort_by'] ?? null)->toBe('title');
})->group('blocks', 'logo-list');

it('maps AND filter logic to all and every other value to any', function (): void {
    // LogoListParser.php:466 — `$filterLogic === 'AND' ? 'all' : 'any'`. The
    // fixture only exercises the 'any' side, so 'all' is pinned separately.
    $anySet = parseLogoListBlock(logoListAttributes([
        'block_logo_list_filter_logic' => 'IN',
    ]));
    $allSet = parseLogoListBlock(logoListAttributes([
        'block_logo_list_filter_logic' => 'AND',
    ]));

    expect($anySet['logo_list_filter_category_logic'] ?? null)->toBe('any');
    expect($allSet['logo_list_filter_category_logic'] ?? null)->toBe('all');
})->group('blocks', 'logo-list');

it('emits the grid-only and conditional keys exactly when their conditions hold', function (): void {
    // The first fixture block: grid layout, links off, no labels, no max, no more.
    $set = parseLogoListBlock(logoListAttributes());

    expect($set['type'] ?? null)->toBe('uamsweb_block_logo_list');
    expect($set['background_color'] ?? null)->toBe('bg-gray');
    expect($set['hide_block_heading'] ?? null)->toBeFalse();
    expect($set['logo_list_featured'] ?? null)->toBeFalse();
    expect($set['logo_list_layout'] ?? null)->toBe('grid');
    expect($set['logo_list_labels'] ?? null)->toBeFalse();
    expect($set['logo_list_links'] ?? null)->toBeFalse();
    expect($set['more_query'] ?? null)->toBeFalse();
    expect($set['enabled'] ?? null)->toBeTrue();

    // `id` is random per row, so pin its shape rather than a literal value.
    expect($set['id'] ?? null)->toBeString()->toHaveLength(8);

    // Grid layout emits these three.
    expect($set['logo_list_width'] ?? null)->toBe('alignfull');
    expect($set['logo_list_titles'] ?? null)->toBeFalse();
    expect($set['logo_list_shortdescr'] ?? null)->toBeFalse();

    // Omissions matter as much as presence: each key below is emitted only under a
    // condition this block does not meet. Without these, a regression that always
    // emitted every key would pass.
    expect($set)->not->toHaveKey('logo_list_filter_label');
    expect($set)->not->toHaveKey('logo_list_filter_label_logic');
    expect($set)->not->toHaveKey('logo_list_max');
    expect($set)->not->toHaveKey('logo_list_links_target');
    expect($set)->not->toHaveKey('more_heading');
    expect($set)->not->toHaveKey('more_button_text');
    expect($set)->not->toHaveKey('more_button_url');
    expect($set)->not->toHaveKey('more_button_target');
})->group('blocks', 'logo-list');

it('omits the grid-only keys when the layout is not grid', function (): void {
    // The three keys above are gated on `$layout === 'grid'` (LogoListParser.php:551).
    // Every other test here — and the verified fixture, and the attribute's own
    // default — uses `grid`, so without this the condition is unpinned: mutating it
    // to `true` survives. That contradicts this file's own standard, stated in the
    // previous test: a regression that always emitted every key must not pass.
    $set = parseLogoListBlock(logoListAttributes([
        'block_logo_list_layout' => 'carousel',
    ]));

    expect($set['logo_list_layout'] ?? null)->toBe('carousel');

    expect($set)->not->toHaveKey('logo_list_width');
    expect($set)->not->toHaveKey('logo_list_titles');
    expect($set)->not->toHaveKey('logo_list_shortdescr');
})->group('blocks', 'logo-list');

it('emits the link target only when links are enabled', function (): void {
    // `block_logo_list_links_target` defaults to '1' (true when absent), but the
    // key is emitted only when links are on, so the default is unobservable until
    // links are enabled.
    $withLinks = parseLogoListBlock(logoListAttributes([
        'block_logo_list_links' => '1',
    ]));

    expect($withLinks['logo_list_links'] ?? null)->toBeTrue();
    expect($withLinks['logo_list_links_target'] ?? null)->toBeTrue();
})->group('blocks', 'logo-list');

it('emits the optional max only when the source supplies one', function (): void {
    $withMax = parseLogoListBlock(logoListAttributes([
        'block_logo_list_max' => '12',
    ]));

    expect($withMax['logo_list_max'] ?? null)->toBe(12);
})->group('blocks', 'logo-list');

it('truncates the block description to 290 bytes', function (): void {
    $set = parseLogoListBlock(logoListAttributes([
        'block_logo_list_description' => str_repeat('a', 400),
    ]));

    // NOTE: the parser uses substr(), not mb_substr(). This pins the byte bound
    // only; a multibyte description can still be cut mid-codepoint. That gap is
    // real and is called out in the PR rather than papered over here.
    expect($set['block_description'] ?? null)->toBe(str_repeat('a', 290));
})->group('blocks', 'logo-list');

it('emits an empty category filter when a filter id resolves to no term', function (): void {
    // The empty-ids guard runs BEFORE the term lookup, so an id that is present
    // but unresolvable takes a different path: the block still emits, with an
    // empty category array and no warning. Characterization of a real divergence.
    $logSpy = Log::spy();

    $set = parseLogoListBlock(logoListAttributes([
        'block_logo_list_filter_ids' => ['999999'],
    ]));

    expect($set)->not->toBe([]);
    expect($set['logo_list_filter_category'] ?? null)->toBe([]);

    $logSpy->shouldNotHaveReceived('warning');
})->group('blocks', 'logo-list');

it('records each used category term against the page slug', function (): void {
    parseLogoListBlock(logoListAttributes([
        'block_logo_list_filter_ids' => ['7256'],
    ]));

    $slug = 'default_uams-health-stuff_official-orthopaedics-sports-medicine-provider_northwest-arkansas';

    /** @var array<string, array<string, list<string>>> $used */
    $used = LogoListParser::getTermsUsed();

    expect($used['logos_item_categories'] ?? [])->toHaveKey($slug);
    expect($used['logos_item_categories'][$slug])->toBe(['logo-list-test']);
})->group('blocks', 'logo-list');

it('has already mutated the term registry when a later guard drops the block', function (): void {
    // CHARACTERIZATION, not an endorsement. The term loop runs before the sort
    // guard, so a block that is ultimately skipped still leaves its terms behind.
    // Pinned so the coupling becomes visible if the guard order ever changes.
    $set = parseLogoListBlock(logoListAttributes([
        'block_logo_list_filter_ids' => ['7256'],
        'block_logo_list_order' => ['bogus|ASC'],
    ]));

    expect($set)->toBe([]);
    expect(LogoListParser::getTermsUsed()['logos_item_categories'] ?? [])->not->toBe([]);
})->group('blocks', 'logo-list');

it('does not record terms when no page slug is supplied', function (): void {
    // The registry write is gated on a non-empty page slug.
    parseLogoListBlock(logoListAttributes([
        'block_logo_list_filter_ids' => ['7256'],
    ]), null);

    expect(LogoListParser::getTermsUsed())->toBe([]);
})->group('blocks', 'logo-list');

/**
 * Each of the parser's four `return []` guards, paired with its warning fragment.
 *
 * A dropped block is indistinguishable from an absent one downstream, so
 * asserting `[]` alone would pass for the wrong reason — every case pins the
 * specific warning too.
 */
dataset('logo list skip guards', [
    'missing heading' => [
        ['block_logo_list_heading' => ''],
        'missing required heading',
    ],
    'no filter categories' => [
        ['block_logo_list_filter_ids' => []],
        'missing required filter categories',
    ],
    'sort list maps to nothing' => [
        ['block_logo_list_order' => ['bogus|ASC']],
        'invalid sort order',
    ],
    'more query without its required fields' => [
        ['block_logo_list_more' => '1'],
        'missing required more link fields',
    ],
]);

it('skips the block and warns', function (array $overrides, string $expectedFragment): void {
    /** @var array<string, mixed> $overrides */
    $logSpy = Log::spy();

    $set = parseLogoListBlock(logoListAttributes($overrides));

    expect($set)->toBe([]);

    // The Log::warning call sites all pass ($message, $context).
    $logSpy->shouldHaveReceived('warning', [
        Mockery::on(
            static fn (mixed $message): bool => \is_string($message)
                && \str_contains($message, $expectedFragment)
        ),
        Mockery::any(),
    ]);
})->with('logo list skip guards')->group('blocks', 'logo-list');
