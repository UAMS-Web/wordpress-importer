<?php

declare(strict_types=1);

use Symfony\Component\Console\Output\NullOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\NewsParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\PostCategoryEntryResolver;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\NewsArticleResolver;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\BlogrollShortcodeProcessor;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * Tests that every writer of a `uamsweb_block_news` set matches the reshaped
 * fieldset (`UAMS-Web/uams-statamic#76`, this package's #417).
 *
 * The three writers are `NewsParser::parse()` (ACF blocks),
 * `NewsParser::parseModule()` (the modules template) and
 * `BlogrollShortcodeProcessor::toPageBuilderBlock()` (the `[blogroll]`
 * shortcode). All three must emit `source_sites`, and none may emit the retired
 * `local` or `advanced_cat` keys.
 *
 * Routed to the light Statamic band because the sets are built with Statamic's
 * `RowId`, which needs a booted container. No `post_categories` entries exist
 * here, so what these tests assert is the emitted shape and that an unresolvable
 * selection is omitted rather than written as a slug the picker cannot read;
 * `PostCategoryEntryResolverTest` creates real entries and covers the hits.
 *
 * @command  php vendor/bin/pest tests/Integration/NewsBlockFieldsetShapeTest.php
 */
beforeEach(function (): void {
    // Real parser and processor under test
    $this->parser = new NewsParser;
    $this->blogroll = new BlogrollShortcodeProcessor;

    // Mock AssetImporter — no asset work happens in this block
    $this->assetImporter = $this->createMock(AssetImporter::class);

    // Silent console output
    $this->output = new NullOutput;

    // These statics are process-wide; start each test from a clean slate.
    // `NewsArticleResolver` matters here even though this file creates no `posts`
    // entries: its assertions are *absences*, so a memoized hit left by another
    // file would turn them red for a reason that has nothing to do with them.
    PostCategoryEntryResolver::clear();
    NewsArticleResolver::clearCache();
    ImportWarningManager::clearWarnings();
});

afterEach(function (): void {
    // Do not leak resolver memoization or warnings into the next test
    PostCategoryEntryResolver::clear();
    NewsArticleResolver::clearCache();
    ImportWarningManager::clearWarnings();
});

/**
 * Build the normalized field payload for a news block.
 *
 * @param  array<string, string>  $overrides  Field values replacing the defaults
 * @return array<string, string> The block's ACF field data
 */
function newsBlockData(array $overrides = []): array
{
    return \array_merge(
        [
            'news_format' => 'grid',
            'news_title' => 'News and Announcements',
            'news_hide_title' => '0',
            'news_category' => '',
            'news_offset' => '0',
            'news_background_color' => 'bg-auto',
            'news_hide_img' => '0',
            'news_hide_author' => '0',
            'news_hide_date' => '0',
            'news_include_link' => '0',
            'news_local' => '0',
            'news_advanced_cat' => '',
        ],
        $overrides
    );
}

/**
 * Wrap block field data the way the ACF block parser receives it.
 *
 * @param  array<string, string>  $overrides  Field values replacing the defaults
 * @return array<string, mixed> The block attributes
 */
function newsBlockAttributes(array $overrides = []): array
{
    return ['data' => newsBlockData($overrides)];
}

it('emits source_sites instead of the retired local flag', function (): void {
    // Parse a block WordPress did not mark local
    $set = $this->parser->parse(
        newsBlockAttributes(),
        'default',
        $this->assetImporter,
        $this->output
    );

    // The fieldset default is news-only, and `local` no longer exists
    expect($set['source_sites'] ?? null)->toBe(['news'])
        ->and($set)->not->toHaveKey('local');
});

it('adds the current site to source_sites when WordPress marked the block local', function (): void {
    // 233 of 297 corpus occurrences carry news_local="1"
    $set = $this->parser->parse(
        newsBlockAttributes(['news_local' => '1']),
        'default',
        $this->assetImporter,
        $this->output
    );

    // Both tokens are stored; `current` is resolved by the host at render time
    expect($set['source_sites'] ?? null)->toBe(['news', 'current']);
});

it('reads the short news_bgcolor spelling of the background colour', function (): void {
    // 49 of 297 corpus blocks carry only this spelling — 38 bg-white, 11 bg-gray
    $set = $this->parser->parse(
        ['data' => [
            'news_format' => 'grid',
            'news_bgcolor' => 'bg-white',
            'news_local' => '0',
        ]],
        'default',
        $this->assetImporter,
        $this->output
    );

    // Reading only the long spelling imported all 49 as `bg-auto`
    expect($set['background_color'] ?? null)->toBe('bg-white');
});

it('prefers the long background-colour spelling when a block carries both', function (): void {
    // The corpus never does, but the two must not fight non-deterministically
    $set = $this->parser->parse(
        ['data' => [
            'news_format' => 'grid',
            'news_background_color' => 'bg-gray',
            'news_bgcolor' => 'bg-white',
            'news_local' => '0',
        ]],
        'default',
        $this->assetImporter,
        $this->output
    );

    expect($set['background_color'] ?? null)->toBe('bg-gray');
});

it('still defaults the background colour when neither spelling is present', function (): void {
    $set = $this->parser->parse(
        ['data' => ['news_format' => 'grid', 'news_local' => '0']],
        'default',
        $this->assetImporter,
        $this->output
    );

    expect($set['background_color'] ?? null)->toBe('bg-auto');
});

it('never emits advanced_cat, even when WordPress carried a value for it', function (): void {
    // 19 corpus occurrences carry a non-empty news_advanced_cat
    $set = $this->parser->parse(
        newsBlockAttributes(['news_advanced_cat' => 'EHS-Research-Blog']),
        'default',
        $this->assetImporter,
        $this->output
    );

    // The reshaped fieldset dropped the field with no replacement
    expect($set)->not->toHaveKey('advanced_cat');
});

it('omits category rather than writing a slug the entries picker cannot read', function (): void {
    // No post_categories entry exists in this band, so nothing resolves
    $set = $this->parser->parse(
        newsBlockAttributes(['news_category' => 'department-news']),
        'default',
        $this->assetImporter,
        $this->output
    );

    // A bare slug would be a dangling reference, so the key is absent entirely
    expect($set)->not->toHaveKey('category');
});

it('omits category on the side format too, not just the list formats', function (): void {
    // `side` sets category through its own branch, which must match
    $set = $this->parser->parse(
        newsBlockAttributes([
            'news_format' => 'side',
            'news_category' => 'department-news',
        ]),
        'default',
        $this->assetImporter,
        $this->output
    );

    // Same rule on the branch the list formats do not reach
    expect($set['format'] ?? null)->toBe('side')
        ->and($set)->not->toHaveKey('category');
});

it('omits an unresolvable article_id and records a warning naming the dropped id', function (): void {
    // The `full` format features one post, picked by Statamic entry id
    $set = $this->parser->parse(
        newsBlockAttributes([
            'news_format' => 'full',
            'news_article_id' => '163336',
        ]),
        'default',
        $this->assetImporter,
        $this->output
    );

    // A raw WordPress id would be a dangling reference in the picker
    expect($set)->not->toHaveKey('article_id');

    // The loss is surfaced rather than silent
    $warnings = ImportWarningManager::getWarnings(
        'content',
        'unresolvable_news_article_id'
    );

    expect($warnings)->toHaveCount(1);

    // The warning names the id that was dropped, so it can be re-linked
    $warning = $warnings[0] ?? null;

    expect($warning)->toBeArray();

    $droppedId = \is_array($warning)
        ? ($warning['wordpress_post_id'] ?? null)
        : null;

    expect($droppedId)->toBe('163336');
});

it('records no article warning when the block never named an article', function (): void {
    // Every news_article_id in the export corpus is empty
    $this->parser->parse(
        newsBlockAttributes(['news_format' => 'full']),
        'default',
        $this->assetImporter,
        $this->output
    );

    // Nothing was dropped, so nothing is reported
    expect(ImportWarningManager::getWarnings(
        'content',
        'unresolvable_news_article_id'
    ))->toBe([]);
});

it('builds the same shape from the modules template as from an ACF block', function (): void {
    // The two entry points share one builder, so their output must not drift
    $data = newsBlockData(['news_local' => '1', 'news_offset' => '4']);

    $fromAcf = $this->parser->parse(
        ['data' => $data],
        'default',
        $this->assetImporter,
        $this->output
    );

    $fromModule = $this->parser->parseModule(
        $data,
        'default',
        $this->assetImporter,
        $this->output
    );

    // The generated row id is unique per set, so compare everything else
    unset($fromAcf['id'], $fromModule['id']);

    expect($fromModule)->toBe($fromAcf);
});

it('emits source_sites from the blogroll shortcode writer as well', function (): void {
    // The third writer of this set type tracks the same fieldset
    $set = $this->blogroll->toPageBuilderBlock(
        ['style' => 'default', 'image' => 'show', 'date' => 'show', 'author' => 'hide'],
        'default',
        $this->assetImporter,
        $this->output
    );

    // A blogroll is the placement's own posts, so both tokens are selected
    expect($set['source_sites'] ?? null)->toBe(['news', 'current'])
        ->and($set)->not->toHaveKey('local');
});

it('does not park a numeric blogroll category in the retired advanced_cat field', function (): void {
    // A numeric [blogroll category=…] is a WordPress term id, not a slug
    $set = $this->blogroll->toPageBuilderBlock(
        ['style' => 'default', 'category' => '42'],
        'default',
        $this->assetImporter,
        $this->output
    );

    // The field it used to land in no longer exists, and 42 is not an entry id
    expect($set)->not->toHaveKey('advanced_cat')
        ->and($set)->not->toHaveKey('category');
});

it('omits an unresolvable blogroll category slug rather than writing it raw', function (): void {
    // category_name is always a slug, and still has to resolve to an entry id
    $set = $this->blogroll->toPageBuilderBlock(
        ['style' => 'default', 'category_name' => 'department-news'],
        'default',
        $this->assetImporter,
        $this->output
    );

    // Nothing resolves in this band, so the picker is left unset
    expect($set)->not->toHaveKey('category');
});
