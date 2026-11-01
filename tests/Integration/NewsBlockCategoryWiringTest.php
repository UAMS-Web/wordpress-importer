<?php

declare(strict_types=1);

use Statamic\Entries\Entry;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Site;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Symfony\Component\Console\Output\NullOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\NewsParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\PostCategoryEntryResolver;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\BlogrollShortcodeProcessor;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

/**
 * Tests that each writer actually puts resolved category ids **into the set**.
 *
 * `NewsBlockFieldsetShapeTest` and `PostCategoryEntryResolverTest` between them
 * cover the emitted shape and the lookup, but neither covers the wire between
 * them: with no `post_categories` entries in scope every shape assertion is an
 * *absence*, so severing the parser's call to the resolver — or dropping the
 * assignment to `$result['category']` — leaves both files green. These tests
 * create real entries and assert the id reaches the set, for all three writers.
 *
 * `PreventsSavingStacheItemsToDisk` redirects the Stache at a scratch directory
 * that {@see AddonTestCase} deletes on teardown.
 *
 * @command  php vendor/bin/pest tests/Integration/NewsBlockCategoryWiringTest.php
 */
beforeEach(function (): void {
    // A second site is needed to prove the resolution is placement-scoped
    Site::setSites([
        'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
        'news' => ['name' => 'News', 'locale' => 'en_US', 'url' => '/news/'],
    ]);

    $collection = CollectionFacade::make('post_categories');
    $collection->sites(['default', 'news']);
    $collection->save();

    $this->parser = new NewsParser;
    $this->blogroll = new BlogrollShortcodeProcessor;
    $this->assetImporter = $this::createStub(AssetImporter::class);
    $this->output = new NullOutput;

    PostCategoryEntryResolver::clear();
    ImportWarningManager::clearWarnings();
});

afterEach(function (): void {
    PostCategoryEntryResolver::clear();
    ImportWarningManager::clearWarnings();
});

/**
 * Create a `post_categories` entry and return its id.
 *
 * @param  string  $slug  The entry slug
 * @param  string  $site  The site handle
 * @return string The created entry's id
 */
function makeWiringCategory(
    string $slug,
    string $site = 'default'
): string {
    $entry = EntryFacade::make();

    if (! $entry instanceof Entry) {
        throw new RuntimeException("Could not make an entry for '$slug'.");
    }

    $entry->collection('post_categories');
    $entry->locale($site);
    $entry->slug($slug);
    $entry->set('title', $slug);
    $entry->published(true);
    $entry->save();

    $id = $entry->id();

    if (! \is_string($id) || $id === '') {
        throw new RuntimeException("The entry for '$slug' was saved without an id.");
    }

    return $id;
}

/**
 * Build the ACF block data for a news block.
 *
 * @param  array<string, string>  $overrides  Field values replacing the defaults
 * @return array<string, string> The block's ACF field data
 */
function wiringBlockData(array $overrides = []): array
{
    return \array_merge(
        [
            'news_format' => 'grid',
            'news_title' => 'News',
            'news_hide_title' => '0',
            'news_category' => '',
            'news_offset' => '0',
            'news_background_color' => 'bg-auto',
            'news_hide_img' => '0',
            'news_hide_author' => '0',
            'news_hide_date' => '0',
            'news_include_link' => '0',
            'news_local' => '0',
        ],
        $overrides
    );
}

it('writes the resolved category id into the ACF block set', function (): void {
    $entryId = makeWiringCategory('department-news');

    $set = $this->parser->parse(
        ['data' => wiringBlockData(['news_category' => 'department-news'])],
        'default',
        $this->assetImporter,
        $this->output
    );

    // The wire from parser to resolver to the emitted set
    expect($set['category'] ?? null)->toBe([$entryId]);
});

it('writes every resolved id from a comma-separated category value', function (): void {
    $pharmacy = makeWiringCategory('college-of-pharmacy');
    $jobs = makeWiringCategory('jobs');

    $set = $this->parser->parse(
        ['data' => wiringBlockData(['news_category' => 'college-of-pharmacy, jobs'])],
        'default',
        $this->assetImporter,
        $this->output
    );

    expect($set['category'] ?? null)->toBe([$pharmacy, $jobs]);
});

it('writes the resolved category id on the side format too', function (): void {
    $entryId = makeWiringCategory('research');

    // `side` assigns `category` through its own branch, which must stay wired
    $set = $this->parser->parse(
        ['data' => wiringBlockData([
            'news_format' => 'side',
            'news_category' => 'research',
        ])],
        'default',
        $this->assetImporter,
        $this->output
    );

    expect($set['format'] ?? null)->toBe('side')
        ->and($set['category'] ?? null)->toBe([$entryId]);
});

it('writes the resolved category id from the modules template path', function (): void {
    $entryId = makeWiringCategory('research');

    $set = $this->parser->parseModule(
        wiringBlockData(['news_category' => 'research']),
        'default',
        $this->assetImporter,
        $this->output
    );

    expect($set['category'] ?? null)->toBe([$entryId]);
});

it('resolves the block against its own site, not the news site', function (): void {
    // Both sites own the slug; only the placement's id may be stored, because
    // the host localizes the field's ids to the parent entry's site
    $newsId = makeWiringCategory('research', 'news');
    $defaultId = makeWiringCategory('research', 'default');

    $set = $this->parser->parse(
        ['data' => wiringBlockData([
            'news_category' => 'research',
            'news_local' => '1',
        ])],
        'default',
        $this->assetImporter,
        $this->output
    );

    expect($set['category'] ?? null)->toBe([$defaultId])
        ->and($set['category'] ?? [])->not->toContain($newsId);
});

it('writes the resolved category id from the blogroll shortcode', function (): void {
    $entryId = makeWiringCategory('department-news');

    $set = $this->blogroll->toPageBuilderBlock(
        ['style' => 'default', 'category_name' => 'department-news'],
        'default',
        $this->assetImporter,
        $this->output
    );

    expect($set['category'] ?? null)->toBe([$entryId]);
});

it('writes a non-numeric blogroll category attribute as a resolved id', function (): void {
    $entryId = makeWiringCategory('research');

    // `category` is a slug when it is not a bare term id
    $set = $this->blogroll->toPageBuilderBlock(
        ['style' => 'default', 'category' => 'research'],
        'default',
        $this->assetImporter,
        $this->output
    );

    expect($set['category'] ?? null)->toBe([$entryId]);
});

it('lets category_name win over category, as it always has', function (): void {
    $fromName = makeWiringCategory('department-news');
    makeWiringCategory('research');

    $set = $this->blogroll->toPageBuilderBlock(
        [
            'style' => 'default',
            'category' => 'research',
            'category_name' => 'department-news',
        ],
        'default',
        $this->assetImporter,
        $this->output
    );

    expect($set['category'] ?? null)->toBe([$fromName]);
});

it('reports a numeric blogroll category id instead of dropping it silently', function (): void {
    $set = $this->blogroll->toPageBuilderBlock(
        ['style' => 'default', 'category' => '37'],
        'default',
        $this->assetImporter,
        $this->output
    );

    // A WordPress term id is not an entry id and has no field left to live in
    expect($set)->not->toHaveKey('category')
        ->and($set)->not->toHaveKey('advanced_cat');

    $warnings = ImportWarningManager::getWarnings(
        'content',
        'unresolvable_blogroll_category_id'
    );

    expect($warnings)->toHaveCount(1);

    $warning = $warnings[0] ?? null;
    $termId = \is_array($warning) ? ($warning['wordpress_term_id'] ?? null) : null;

    expect($termId)->toBe('37');
});
