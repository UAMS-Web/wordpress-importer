<?php

declare(strict_types=1);

use Symfony\Component\Console\Output\NullOutput;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\Blocks\NewsParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Taxonomies\PostCategoryEntryResolver;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\NewsArticleResolver;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\NewsBlockSourceSites;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * Tests the whole `uamsweb_block_news` set `NewsParser` builds, field by field.
 *
 * The wiring and fieldset-shape tests assert which keys a set carries; these
 * pin each value, the per-format field list, the ACF field-key mapping, the
 * defaults, and the warning a dropped article reference records. Routed to the
 * light Statamic band because the set id comes from Statamic's `RowId`. No
 * `posts` or `post_categories` entries exist here, so every category and
 * article lookup misses.
 *
 * @command  php vendor/bin/pest tests/Integration/NewsParserSetTest.php
 */
beforeEach(function (): void {
    // Real parser under test, with a mocked importer it never calls
    $this->parser = new NewsParser;
    $this->assetImporter = $this::createStub(AssetImporter::class);
    $this->output = new NullOutput;

    // Resolver memoization and warnings are process-wide statics
    PostCategoryEntryResolver::clear();
    NewsArticleResolver::clearCache();
    ImportWarningManager::clearWarnings();
    ProcessingContext::clearWordPressPageLink();
});

afterEach(function (): void {
    // Do not leak statics into the next test
    PostCategoryEntryResolver::clear();
    NewsArticleResolver::clearCache();
    ImportWarningManager::clearWarnings();
    ProcessingContext::clearWordPressPageLink();
});

/**
 * Every news field set to a value that differs from its default.
 *
 * @param  array<string, mixed>  $overrides  Field values replacing these
 * @return array<string, mixed> The block's normalized ACF field data
 */
function newsSetFullData(array $overrides = []): array
{
    return \array_merge(
        [
            'news_format' => 'grid',
            'news_title' => '<strong>Campus</strong> News',
            'news_hide_title' => '1',
            'news_description' => '<p>Latest stories</p>',
            'news_count' => '7',
            'news_offset' => '3',
            'news_background_color' => 'bg-gray',
            'news_hide_img' => '1',
            'news_hide_author' => '1',
            'news_hide_date' => '1',
            'news_include_link' => '1',
            'news_position' => 'right',
            'news_local' => '1',
        ],
        $overrides
    );
}

/**
 * Parse a news block and drop its generated row id, sorting the keys.
 *
 * @param  NewsParser  $parser  The parser under test
 * @param  AssetImporter  $assetImporter  The unused importer
 * @param  array<int|string, mixed>  $attributes  The block attributes
 * @return array<int|string, mixed> The set without `id`, keys sorted
 */
function newsSetWithoutId(
    NewsParser $parser,
    AssetImporter $assetImporter,
    array $attributes
): array {
    $set = $parser->parse($attributes, 'default', $assetImporter, new NullOutput);

    // The id is random, so it is checked on its own rather than compared
    expect($set['id'] ?? null)->toBeString()->not->toBe('');
    unset($set['id']);
    \ksort($set);

    return $set;
}

/**
 * The fields every format carries, for a block built from `newsSetFullData()`.
 *
 * @param  string  $format  The block format
 * @return array<string, mixed> The common fields
 */
function newsSetBase(string $format): array
{
    return [
        'background_color' => 'bg-gray',
        'enabled' => true,
        'format' => $format,
        'source_sites' => NewsBlockSourceSites::forLocalFlag(true),
        'type' => 'uamsweb_block_news',
    ];
}

it('writes each format\'s own field list, with every value carried through', function (string $format, array $extra): void {
    $expected = \array_merge(newsSetBase($format), $extra);
    \ksort($expected);

    expect(newsSetWithoutId($this->parser, $this->assetImporter, ['data' => newsSetFullData(['news_format' => $format])]))
        ->toBe($expected);
})->with([
    'grid' => ['grid', [
        'block_description' => 'Latest stories',
        'block_heading' => 'Campus News',
        'hide_block_heading' => true,
        'hide_creator_query' => true,
        'hide_date' => true,
        'hide_img' => true,
        'include_link' => true,
        'offset' => 3,
    ]],
    'cards' => ['cards', [
        'block_description' => 'Latest stories',
        'block_heading' => 'Campus News',
        'hide_block_heading' => true,
        'hide_creator_query' => true,
        'hide_date' => true,
        'hide_img' => true,
        'include_link' => true,
        'offset' => 3,
    ]],
    'headlines' => ['headlines', [
        'block_description' => 'Latest stories',
        'block_heading' => 'Campus News',
        'count' => 7,
        'hide_block_heading' => true,
        'hide_creator_query' => true,
        'hide_date' => true,
        'hide_img' => true,
        'offset' => 3,
    ]],
    'excerpts' => ['excerpts', [
        'block_description' => 'Latest stories',
        'block_heading' => 'Campus News',
        'count' => 7,
        'hide_block_heading' => true,
        'hide_creator_query' => true,
        'hide_date' => true,
        'hide_img' => true,
        'offset' => 3,
    ]],
    'side' => ['side', [
        'include_link' => true,
        'offset' => 3,
        'position' => 'right',
    ]],
    'full' => ['full', [
        'hide_creator_query' => true,
    ]],
    'an unknown format' => ['carousel', [
        'hide_creator_query' => true,
    ]],
]);

it('treats a non-string format as unknown rather than matching it loosely', function (): void {
    // `true == 'grid'` is true under loose comparison, so only a strict match
    // keeps a boolean format out of every format's field list
    $expected = \array_merge(newsSetBase('placeholder'), ['format' => true, 'hide_creator_query' => true]);
    \ksort($expected);

    expect(newsSetWithoutId($this->parser, $this->assetImporter, ['data' => newsSetFullData(['news_format' => true])]))
        ->toBe($expected);
});

it('reads every field from its ACF field key', function (): void {
    $keyed = [];

    // Re-key each normalized name to the `field_<name>_b` form WordPress stores
    foreach (newsSetFullData(['news_format' => 'headlines', 'news_include_link' => '0']) as $name => $value) {
        $keyed['field_'.$name.'_b'] = $value;
    }

    // The background color also arrives under the short spelling's key
    $keyed['field_news_bgcolor_b'] = $keyed['field_news_background_color_b'];
    unset($keyed['field_news_background_color_b']);

    $fromKeys = newsSetWithoutId($this->parser, $this->assetImporter, ['data' => $keyed]);

    expect($fromKeys)->toMatchArray([
        'format' => 'headlines',
        'block_heading' => 'Campus News',
        'hide_block_heading' => true,
        'block_description' => 'Latest stories',
        'count' => 7,
        'offset' => 3,
        'background_color' => 'bg-gray',
        'hide_img' => true,
        'hide_creator_query' => true,
        'hide_date' => true,
        'source_sites' => NewsBlockSourceSites::forLocalFlag(true),
    ]);
});

it('reads the include-link, position and long background-color keys', function (): void {
    $set = newsSetWithoutId($this->parser, $this->assetImporter, ['data' => [
        'field_news_format_b' => 'side',
        'field_news_include_link_b' => '1',
        'field_news_position_b' => 'right',
        'field_news_background_color_b' => 'bg-white',
    ]]);

    expect($set)->toMatchArray([
        'include_link' => true,
        'position' => 'right',
        'background_color' => 'bg-white',
    ]);
});

it('reads the article id from its ACF field key', function (): void {
    newsSetWithoutId($this->parser, $this->assetImporter, ['data' => [
        'field_news_format_b' => 'full',
        'field_news_article_id_b' => '163336',
    ]]);

    // The lookup ran on the keyed value, and missed
    expect(ImportWarningManager::getWarnings('content', 'unresolvable_news_article_id'))->toHaveCount(1);
});

it('reads the category from its ACF field key', function (): void {
    newsSetWithoutId($this->parser, $this->assetImporter, ['data' => [
        'field_news_format_b' => 'grid',
        'field_news_category_b' => 'research',
    ]]);

    // The resolver saw the keyed slug, and missed
    expect(ImportWarningManager::getWarnings('content', 'unresolvable_news_category'))->toHaveCount(1);
});

it('falls back to the documented defaults for an empty block', function (): void {
    $expected = [
        'background_color' => 'bg-auto',
        'enabled' => true,
        'format' => 'grid',
        'hide_creator_query' => false,
        'hide_date' => false,
        'hide_img' => false,
        'include_link' => false,
        'source_sites' => NewsBlockSourceSites::forLocalFlag(false),
        'type' => 'uamsweb_block_news',
    ];

    expect(newsSetWithoutId($this->parser, $this->assetImporter, ['data' => []]))->toBe($expected)
        // An absent category is not an unresolved one
        ->and(ImportWarningManager::getAllWarnings())->toBe([]);
});

it('defaults the side position to left', function (): void {
    expect(newsSetWithoutId($this->parser, $this->assetImporter, ['data' => ['news_format' => 'side']])['position'] ?? null)
        ->toBe('left');
});

it('reads a block whose fields sit at the top level, without a data key', function (): void {
    expect(newsSetWithoutId($this->parser, $this->assetImporter, ['news_format' => 'side'])['format'] ?? null)
        ->toBe('side');
});

it('builds the default set when the data key is not an array', function (): void {
    expect(newsSetWithoutId($this->parser, $this->assetImporter, ['data' => 'not an array'])['format'] ?? null)
        ->toBe('grid');
});

it('omits a heading, description, count and offset that are not usable values', function (): void {
    $set = newsSetWithoutId($this->parser, $this->assetImporter, ['data' => newsSetFullData([
        'news_format' => 'headlines',
        'news_title' => ['not', 'a', 'string'],
        'news_description' => ['not', 'a', 'string'],
        'news_count' => 'several',
        'news_offset' => 'a few',
    ])]);

    expect($set)->not->toHaveKeys(['block_heading', 'hide_block_heading', 'block_description', 'count', 'offset']);
});

it('omits the side offset when it is not numeric', function (): void {
    expect(newsSetWithoutId($this->parser, $this->assetImporter, ['data' => newsSetFullData([
        'news_format' => 'side',
        'news_offset' => 'a few',
    ])]))->not->toHaveKey('offset');
});

it('omits a heading and description that strip to nothing', function (): void {
    $set = newsSetWithoutId($this->parser, $this->assetImporter, ['data' => newsSetFullData([
        'news_title' => '<br>',
        'news_description' => '<p></p>',
    ])]);

    expect($set)->not->toHaveKeys(['block_heading', 'hide_block_heading', 'block_description']);
});

it('passes a numeric category to the resolver as a string', function (): void {
    newsSetWithoutId($this->parser, $this->assetImporter, ['data' => newsSetFullData(['news_category' => 42])]);

    $warnings = ImportWarningManager::getWarnings('content', 'unresolvable_news_category');

    expect($warnings)->toHaveCount(1);
});

it('ignores a category that is not a scalar', function (): void {
    $set = newsSetWithoutId($this->parser, $this->assetImporter, ['data' => newsSetFullData(['news_category' => ['research']])]);

    expect($set)->not->toHaveKey('category')
        ->and(ImportWarningManager::getAllWarnings())->toBe([]);
});

it('reports nothing for an article selection that is null or an empty list', function (mixed $articleId): void {
    newsSetWithoutId($this->parser, $this->assetImporter, ['data' => ['news_format' => 'full', 'news_article_id' => $articleId]]);

    expect(ImportWarningManager::getAllWarnings())->toBe([]);
})->with([
    'null' => [null],
    'an empty list' => [[]],
]);

it('records the full warning for a well-formed article id that matched nothing', function (): void {
    ProcessingContext::setWordPressPageLink('https://www.uams.edu/about/');

    newsSetWithoutId($this->parser, $this->assetImporter, ['data' => ['news_format' => 'full', 'news_article_id' => 163336]]);

    expect(ImportWarningManager::getWarnings('content', 'unresolvable_news_article_id'))->toBe([[
        'site' => 'default',
        'wordpress_post_id' => '163336',
        'page_url' => 'https://www.uams.edu/about/',
        'reason' => 'No `posts` entry on this site carries this WordPress post id, so the uamsweb_block_news '
            .'`article_id` reference was dropped rather than written as a dangling one. These ids '
            .'usually name posts on news.uams.edu, which import into their own Statamic site — this '
            .'lookup is site-scoped, so importing that export will not by itself resolve the '
            .'reference. Re-link the featured article in the Control Panel.',
    ]]);
});

it('records the full warning for an article id that is not a WordPress post id', function (mixed $articleId, string $reported): void {
    newsSetWithoutId($this->parser, $this->assetImporter, ['data' => ['news_format' => 'full', 'news_article_id' => $articleId]]);

    expect(ImportWarningManager::getWarnings('content', 'unresolvable_news_article_id'))->toBe([[
        'site' => 'default',
        'wordpress_post_id' => $reported,
        'page_url' => null,
        'reason' => 'This uamsweb_block_news `article_id` value is not shaped like a WordPress post id — that '
            .'is a positive integer with no padding — so no entry could carry it and none was looked '
            .'up. The reference was dropped rather than written as a dangling one. Re-link the '
            .'featured article in the Control Panel.',
    ]]);
})->with([
    'a word' => ['not-a-number', 'not-a-number'],
    'a list, reported by its type' => [['163336'], 'array'],
]);
