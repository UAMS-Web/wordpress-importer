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
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\NewsArticleResolver;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

/**
 * Tests that the parser actually puts a resolved article id **into the set**.
 *
 * `NewsArticleResolverTest` covers the lookup and `NewsBlockFieldsetShapeTest`
 * covers the emitted shape, but neither covers the wire between them: with no
 * matching `posts` entry in scope every shape assertion is an *absence*, so
 * severing the parser's call to the resolver — or dropping the assignment to
 * `$result['article_id']` — leaves both files green. These tests create a real
 * entry and assert the id reaches the set. This mirrors
 * `NewsBlockCategoryWiringTest`, which exists for the same reason on the
 * `category` field.
 *
 * `PreventsSavingStacheItemsToDisk` redirects the Stache at a scratch directory
 * that {@see AddonTestCase} deletes on teardown.
 *
 * @command  php vendor/bin/pest tests/Integration/NewsBlockArticleWiringTest.php
 */
beforeEach(function (): void {
    // A second site is needed to prove the resolution is placement-scoped
    Site::setSites([
        'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
        'news' => ['name' => 'News', 'locale' => 'en_US', 'url' => '/news/'],
    ]);

    $collection = CollectionFacade::make('posts');
    $collection->sites(['default', 'news']);
    $collection->save();

    $this->parser = new NewsParser;
    $this->assetImporter = $this->createMock(AssetImporter::class);
    $this->output = new NullOutput;

    NewsArticleResolver::clearCache();
    ImportWarningManager::clearWarnings();
});

afterEach(function (): void {
    NewsArticleResolver::clearCache();
    ImportWarningManager::clearWarnings();
});

/**
 * Create a `posts` entry carrying a `wordpress_post_id` and return its id.
 *
 * @param  int  $wordPressPostId  The WordPress post id to store
 * @param  string  $site  The site handle
 * @param  string  $slug  The entry slug
 * @return string The created entry's id
 */
function makeWiringArticle(
    int $wordPressPostId,
    string $site = 'default',
    string $slug = 'a-news-article'
): string {
    $entry = EntryFacade::make();

    if (! $entry instanceof Entry) {
        throw new RuntimeException("Could not make an entry for '$slug'.");
    }

    $entry->collection('posts');
    $entry->locale($site);
    $entry->slug($slug);
    $entry->set('title', $slug);
    $entry->set('wordpress_post_id', $wordPressPostId);
    $entry->published(true);
    $entry->save();

    $id = $entry->id();

    if (! \is_string($id) || $id === '') {
        throw new RuntimeException("The entry for '$slug' was saved without an id.");
    }

    return $id;
}

/**
 * Build the ACF block data for a `full`-format news block.
 *
 * @param  array<string, string>  $overrides  Field values replacing the defaults
 * @return array<string, string> The block's ACF field data
 */
function articleWiringBlockData(array $overrides = []): array
{
    return \array_merge(
        [
            'news_format' => 'full',
            'news_title' => 'News',
            'news_hide_title' => '0',
            'news_article_id' => '',
            'news_background_color' => 'bg-auto',
            'news_hide_img' => '0',
            'news_hide_author' => '0',
            'news_hide_date' => '0',
            'news_local' => '0',
        ],
        $overrides
    );
}

/**
 * The `unresolvable_news_article_id` warnings recorded so far.
 *
 * @return list<array<mixed, mixed>> The matching warnings
 */
function unresolvableArticleWarnings(): array
{
    $matching = [];

    foreach (ImportWarningManager::getWarnings('content', 'unresolvable_news_article_id') as $warning) {
        if (\is_array($warning)) {
            $matching[] = $warning;
        }
    }

    return $matching;
}

it('writes the resolved article id into the ACF block set', function (): void {
    $entryId = makeWiringArticle(163336);

    $set = $this->parser->parse(
        ['data' => articleWiringBlockData(['news_article_id' => '163336'])],
        'default',
        $this->assetImporter,
        $this->output
    );

    // The wire from parser to resolver to the emitted set
    expect($set['article_id'] ?? null)->toBe([$entryId]);
});

it('records no warning when the article resolves', function (): void {
    makeWiringArticle(163336);

    $this->parser->parse(
        ['data' => articleWiringBlockData(['news_article_id' => '163336'])],
        'default',
        $this->assetImporter,
        $this->output
    );

    expect(unresolvableArticleWarnings())->toBe([]);
});

it('omits article_id and warns when the post was never imported', function (): void {
    $set = $this->parser->parse(
        ['data' => articleWiringBlockData(['news_article_id' => '163336'])],
        'default',
        $this->assetImporter,
        $this->output
    );

    // Degrading, not dangling — the picker gets nothing rather than a bad id
    expect($set)->not->toHaveKey('article_id');
    expect(unresolvableArticleWarnings())->toHaveCount(1);
});

it('resolves the article on the site the block is being imported into', function (): void {
    // The same WordPress id on two sites must not cross over
    makeWiringArticle(163336, 'default', 'a-default-article');
    $newsId = makeWiringArticle(163336, 'news', 'a-news-article');

    $set = $this->parser->parse(
        ['data' => articleWiringBlockData(['news_article_id' => '163336'])],
        'news',
        $this->assetImporter,
        $this->output
    );

    expect($set['article_id'] ?? null)->toBe([$newsId]);
});

it('omits article_id for an empty selection without warning', function (): void {
    $set = $this->parser->parse(
        ['data' => articleWiringBlockData()],
        'default',
        $this->assetImporter,
        $this->output
    );

    // Every `news_article_id` in the export corpus is the empty string; an empty
    // picker is not a dropped reference and must not be reported as one.
    expect($set)->not->toHaveKey('article_id');
    expect(unresolvableArticleWarnings())->toBe([]);
});

it('reports a well-formed id that matched nothing as a lookup that missed', function (): void {
    $this->parser->parse(
        ['data' => articleWiringBlockData(['news_article_id' => '163336'])],
        'default',
        $this->assetImporter,
        $this->output
    );

    $warnings = unresolvableArticleWarnings();

    expect($warnings)->toHaveCount(1);
    expect($warnings[0]['reason'] ?? '')->toContain('No `posts` entry on this site carries');
});

it('reports a malformed id as one that was never looked up', function (): void {
    // No query is issued for a value the resolver's contract turns away, so the
    // report must not say an entry search came back empty.
    $this->parser->parse(
        ['data' => articleWiringBlockData(['news_article_id' => 'not-a-number'])],
        'default',
        $this->assetImporter,
        $this->output
    );

    $warnings = unresolvableArticleWarnings();

    expect($warnings)->toHaveCount(1);
    expect($warnings[0]['reason'] ?? '')->toContain('none was looked up');
    expect($warnings[0]['reason'] ?? '')->not->toContain('No `posts` entry on this site carries');
});

it('reports a padded id as malformed rather than as a miss', function (): void {
    $this->parser->parse(
        ['data' => articleWiringBlockData(['news_article_id' => ' 163336 '])],
        'default',
        $this->assetImporter,
        $this->output
    );

    expect((unresolvableArticleWarnings()[0]['reason'] ?? ''))->toContain('none was looked up');
});
