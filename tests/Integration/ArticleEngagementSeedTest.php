<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use UAMSWeb\WordPressImporter\Services\WordPressImport\KnowledgeBase\ArticleEngagementSeeder;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * Tests the upsert into the app's sideband engagement store (issue wordpress-importer-archive#427).
 *
 * `article_engagement_counts` is owned by `uams-statamic` (its migration and
 * `ArticleEngagementService` ship there, from #286 / #287), so this package cannot
 * migrate it. These tests build the same shape on the Testbench connection — one
 * row per article entry id, `entry_id` unique, unsigned counters defaulting to
 * zero — and exercise the seeder against it.
 *
 * The unique `entry_id` is what makes the upsert idempotent: re-importing an
 * article carries the historical TOTAL, so it must overwrite rather than
 * accumulate.
 *
 * @command  php vendor/bin/pest tests/Integration/ArticleEngagementSeedTest.php
 */
uses(StatamicTestCase::class);

/**
 * Build a WXR `<item>` carrying the given postmeta pairs.
 *
 * Defined here rather than reused from the Unit tier's extraction test, so this
 * file still runs when scoped on its own.
 *
 * @param  array<string, string>  $meta  meta_key => meta_value
 */
function engagementSeedItem(array $meta): SimpleXMLElement
{
    $postmeta = '';

    foreach ($meta as $key => $value) {
        $postmeta .= '<wp:postmeta>'
            ."<wp:meta_key><![CDATA[{$key}]]></wp:meta_key>"
            ."<wp:meta_value><![CDATA[{$value}]]></wp:meta_value>"
            .'</wp:postmeta>';
    }

    $xml = <<<XML
    <rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">
        <channel>
            <item>
                <title>An article</title>
                {$postmeta}
            </item>
        </channel>
    </rss>
    XML;

    $rss = \simplexml_load_string($xml);

    // The fixture is authored here, so a parse failure is a test bug, not a data case
    if (! $rss instanceof SimpleXMLElement) {
        throw new RuntimeException('Test fixture XML failed to parse');
    }

    return $rss->channel->item[0];
}

/**
 * Build the app's `article_engagement_counts` table on the test connection.
 */
function createEngagementTable(): void
{
    Schema::create(
        'article_engagement_counts',
        function (Blueprint $table): void {
            $table->id();
            $table->string('entry_id')->unique();
            $table->unsignedBigInteger('views')->default(0);
            $table->unsignedInteger('likes')->default(0);
            $table->unsignedInteger('dislikes')->default(0);
            $table->timestamps();
        }
    );
}

/**
 * Read the single stored row for an entry id.
 *
 * @return ?object The row, or null when the article has none
 */
function engagementRow(string $entryId): ?object
{
    return DB::table('article_engagement_counts')
        ->where('entry_id', $entryId)
        ->first();
}

/**
 * Read one counter off a stored row as an int.
 *
 * The query builder returns a `stdClass` whose properties are `mixed`, so narrow
 * once here rather than casting at every assertion.
 *
 * @param  ?object  $row  The stored row, or null
 * @param  string  $column  The counter column to read
 * @return int The stored counter, or zero when unreadable
 */
function engagementCount(?object $row, string $column): int
{
    if ($row === null) {
        return 0;
    }

    // Cast through an array so the column can be looked up by name without a
    // dynamic property access
    $values = \get_object_vars($row);

    $value = $values[$column] ?? null;

    return \is_numeric($value) ? (int) $value : 0;
}

/**
 * Read a stored row's `created_at` as a string.
 *
 * @param  ?object  $row  The stored row, or null
 * @return ?string The timestamp, or null when unreadable
 */
function engagementCreatedAt(?object $row): ?string
{
    if ($row === null) {
        return null;
    }

    $value = \get_object_vars($row)['created_at'] ?? null;

    return \is_string($value) ? $value : null;
}

beforeEach(function (): void {
    // Start every test from a clean store
    Schema::dropIfExists('article_engagement_counts');
});

afterEach(function (): void {
    Schema::dropIfExists('article_engagement_counts');
});

it('writes the MinervaKB aggregates keyed by the entry id', function (): void {
    createEngagementTable();

    $written = ArticleEngagementSeeder::seed(
        engagementSeedItem([
            '_mkb_views' => '59',
            '_mkb_likes' => '4',
            '_mkb_dislikes' => '1',
        ]),
        'article-123'
    );

    expect($written)->toBeTrue();

    $row = engagementRow('article-123');

    expect($row)->not->toBeNull();
    expect(engagementCount($row, 'views'))->toBe(59);
    expect(engagementCount($row, 'likes'))->toBe(4);
    expect(engagementCount($row, 'dislikes'))->toBe(1);
})->group('kb-engagement');

it('overwrites rather than doubling on a re-import', function (): void {
    // The import carries the historical TOTAL, not a delta, so a second run must
    // leave exactly one row holding the source value.
    createEngagementTable();

    $item = engagementSeedItem([
        '_mkb_views' => '59',
        '_mkb_likes' => '4',
    ]);

    ArticleEngagementSeeder::seed($item, 'article-123');
    ArticleEngagementSeeder::seed($item, 'article-123');

    $rows = DB::table('article_engagement_counts')
        ->where('entry_id', 'article-123')
        ->get();

    expect($rows)->toHaveCount(1);
    expect(engagementCount($rows->first(), 'views'))->toBe(59);
    expect(engagementCount($rows->first(), 'likes'))->toBe(4);
})->group('kb-engagement');

it('refreshes a changed aggregate on a re-import', function (): void {
    createEngagementTable();

    ArticleEngagementSeeder::seed(
        engagementSeedItem(['_mkb_views' => '10']),
        'article-123'
    );
    ArticleEngagementSeeder::seed(
        engagementSeedItem(['_mkb_views' => '75']),
        'article-123'
    );

    expect(engagementCount(engagementRow('article-123'), 'views'))->toBe(75);
})->group('kb-engagement');

it('keeps the original created_at across a re-import', function (): void {
    // The update list omits `created_at`, so a re-import must not backdate a row the
    // runtime already created.
    createEngagementTable();

    ArticleEngagementSeeder::seed(
        engagementSeedItem(['_mkb_views' => '10']),
        'article-123'
    );

    $firstCreatedAt = engagementCreatedAt(engagementRow('article-123'));

    DB::table('article_engagement_counts')
        ->where('entry_id', 'article-123')
        ->update(['created_at' => '2020-01-01 00:00:00']);

    ArticleEngagementSeeder::seed(
        engagementSeedItem(['_mkb_views' => '75']),
        'article-123'
    );

    expect($firstCreatedAt)->not->toBeNull();
    expect(engagementCreatedAt(engagementRow('article-123')))->toBe('2020-01-01 00:00:00');
})->group('kb-engagement');

it('writes no row for an article with no source counts', function (): void {
    // A missing row already reads as all-zero through the app's service, so a zeroed
    // row would be noise.
    createEngagementTable();

    $written = ArticleEngagementSeeder::seed(
        engagementSeedItem(['_edit_last' => '3']),
        'article-123'
    );

    expect($written)->toBeFalse();
    expect(engagementRow('article-123'))->toBeNull();
    expect(DB::table('article_engagement_counts')->count())->toBe(0);
})->group('kb-engagement');

it('writes no row for an article whose counts are all zero', function (): void {
    createEngagementTable();

    $written = ArticleEngagementSeeder::seed(
        engagementSeedItem([
            '_mkb_views' => '0',
            '_mkb_likes' => '0',
            '_mkb_dislikes' => '0',
        ]),
        'article-123'
    );

    expect($written)->toBeFalse();
    expect(DB::table('article_engagement_counts')->count())->toBe(0);
})->group('kb-engagement');

it('writes no row when the entry id is blank', function (): void {
    createEngagementTable();

    expect(ArticleEngagementSeeder::seed(engagementSeedItem(['_mkb_views' => '9']), ''))
        ->toBeFalse();
    expect(DB::table('article_engagement_counts')->count())->toBe(0);
})->group('kb-engagement');

it('reclaims the row an article left behind under a previous entry id', function (): void {
    // The real re-import shape. `SlugGenerator` mints `<slug>-<postId>-<random>`
    // with a FRESH random tail each run, and `BaseEntryCreator::saveEntry()` deletes
    // the old entry under `Overwrite matching files` — so without pruning every
    // re-import orphans a row and the store grows by ~1,500 dead rows per run. wordpress-importer-archive#427
    createEngagementTable();

    $item = engagementSeedItem(['_mkb_views' => '59']);

    ArticleEngagementSeeder::seed($item, 'reset-my-password-4211-6JexXGs57BZYTsJJ');
    ArticleEngagementSeeder::seed($item, 'reset-my-password-4211-VNFQl2W02q84fw9g');

    $rows = DB::table('article_engagement_counts')->get();

    expect($rows)->toHaveCount(1);
    expect($rows->first()->entry_id ?? null)->toBe('reset-my-password-4211-VNFQl2W02q84fw9g');
    expect(engagementCount($rows->first(), 'views'))->toBe(59);
})->group('kb-engagement');

it('leaves a different article untouched when pruning', function (): void {
    // The prefix is `<slug>-<postId>-`, so a different post id — even with the same
    // slug — is a different article and must survive.
    createEngagementTable();

    ArticleEngagementSeeder::seed(
        engagementSeedItem(['_mkb_views' => '10']),
        'reset-my-password-4211-6JexXGs57BZYTsJJ'
    );
    ArticleEngagementSeeder::seed(
        engagementSeedItem(['_mkb_views' => '20']),
        'reset-my-password-9999-VNFQl2W02q84fw9g'
    );

    expect(DB::table('article_engagement_counts')->count())->toBe(2);
    expect(engagementCount(engagementRow('reset-my-password-4211-6JexXGs57BZYTsJJ'), 'views'))->toBe(10);
})->group('kb-engagement');

it('prunes nothing for an entry id this importer did not mint', function (string $entryId): void {
    // The prune only fires for `<slug>-<postId>-<16 random chars>`. Anything else —
    // a hand-written id, a runtime-created row — must be left strictly alone.
    createEngagementTable();

    DB::table('article_engagement_counts')->insert([
        'entry_id' => 'some-other-row',
        'views' => 7,
        'likes' => 0,
        'dislikes' => 0,
    ]);

    ArticleEngagementSeeder::seed(engagementSeedItem(['_mkb_views' => '5']), $entryId);

    expect(engagementCount(engagementRow('some-other-row'), 'views'))->toBe(7);
})->with([
    'no separator' => ['articleid'],
    'tail too short' => ['slug-4211-abc'],
    'tail too long' => ['slug-4211-'.\str_repeat('a', 20)],
    'only one separator' => ['slug-'.\str_repeat('a', 16)],
])->group('kb-engagement');

it('cannot widen the prune through LIKE metacharacters in a slug', function (): void {
    // A slug carrying `%` or `_` would otherwise turn the prefix match into a
    // wildcard that reaches unrelated articles.
    createEngagementTable();

    ArticleEngagementSeeder::seed(
        engagementSeedItem(['_mkb_views' => '11']),
        'a-and-b-1-'.\str_repeat('x', 16)
    );

    // `_` is a single-character LIKE wildcard, so an unescaped prefix would match
    // the row above and delete it
    ArticleEngagementSeeder::seed(
        engagementSeedItem(['_mkb_views' => '22']),
        'a_and_b-1-'.\str_repeat('y', 16)
    );

    expect(DB::table('article_engagement_counts')->count())->toBe(2);
    expect(engagementCount(engagementRow('a-and-b-1-'.\str_repeat('x', 16)), 'views'))->toBe(11);
})->group('kb-engagement');

it('keeps two articles in separate rows', function (): void {
    createEngagementTable();

    ArticleEngagementSeeder::seed(engagementSeedItem(['_mkb_views' => '10']), 'article-a');
    ArticleEngagementSeeder::seed(engagementSeedItem(['_mkb_views' => '20']), 'article-b');

    expect(engagementCount(engagementRow('article-a'), 'views'))->toBe(10);
    expect(engagementCount(engagementRow('article-b'), 'views'))->toBe(20);
})->group('kb-engagement');

it('degrades to a warning when the sideband table is absent', function (): void {
    // The migration ships with the app and may not have run — a fresh environment or
    // an import-first deploy. Losing historical counts beats aborting the import.
    $logSpy = Log::spy();

    $written = ArticleEngagementSeeder::seed(
        engagementSeedItem(['_mkb_views' => '59']),
        'article-123'
    );

    expect($written)->toBeFalse();
    expect(Schema::hasTable('article_engagement_counts'))->toBeFalse();

    $logSpy->shouldHaveReceived('warning', [
        Mockery::on(
            static fn (mixed $message): bool => \is_string($message)
                && \str_contains($message, 'Sideband engagement table is absent')
        ),
        Mockery::any(),
    ]);
})->group('kb-engagement');

it('writes no row when the entry id is only whitespace', function (): void {
    createEngagementTable();

    expect(ArticleEngagementSeeder::seed(engagementSeedItem(['_mkb_views' => '9']), '   '))->toBeFalse()
        ->and(DB::table('article_engagement_counts')->count())->toBe(0);
})->group('kb-engagement');

it('names the entry and the table when the sideband table is absent', function (): void {
    $logSpy = Log::spy();

    ArticleEngagementSeeder::seed(engagementSeedItem(['_mkb_views' => '59']), 'article-123');

    $logSpy->shouldHaveReceived('warning', [
        Mockery::any(),
        Mockery::on(static fn (mixed $context): bool => \is_array($context)
            && ($context['entry_id'] ?? null) === 'article-123'
            && ($context['table'] ?? null) === 'article_engagement_counts'),
    ]);
})->group('kb-engagement');

it('stamps a new row, and refreshes every tally and updated_at but not created_at on a re-import', function (): void {
    createEngagementTable();
    $id = 'reset-my-password-4211-'.\str_repeat('a', 16);

    $this->travelTo('2026-01-01 00:00:00');
    ArticleEngagementSeeder::seed(engagementSeedItem(['_mkb_views' => '1', '_mkb_likes' => '1', '_mkb_dislikes' => '1']), $id);

    $first = \get_object_vars(engagementRow($id) ?? new stdClass);

    $this->travelTo('2026-02-01 00:00:00');
    ArticleEngagementSeeder::seed(engagementSeedItem(['_mkb_views' => '5', '_mkb_likes' => '6', '_mkb_dislikes' => '7']), $id);

    $row = engagementRow($id);
    $second = \get_object_vars($row ?? new stdClass);

    expect([engagementCount($row, 'views'), engagementCount($row, 'likes'), engagementCount($row, 'dislikes')])->toBe([5, 6, 7])
        ->and($first['updated_at'] ?? null)->toStartWith('2026-01-01')
        ->and($second['updated_at'] ?? null)->toStartWith('2026-02-01')
        ->and($second['created_at'] ?? null)->toStartWith('2026-01-01');
})->group('kb-engagement');

it('prunes only rows of the same article whose id this importer minted', function (string $existing, string $written, bool $pruned): void {
    createEngagementTable();

    DB::table('article_engagement_counts')->insert(['entry_id' => $existing, 'views' => 7, 'likes' => 0, 'dislikes' => 0]);

    ArticleEngagementSeeder::seed(engagementSeedItem(['_mkb_views' => '5']), $written);

    expect(engagementRow($existing) === null)->toBe($pruned);
})->with([
    'the same article' => ['a-1-'.\str_repeat('x', 16), 'a-1-'.\str_repeat('y', 16), true],
    'an id whose tail is too short' => ['slug-4211-'.\str_repeat('x', 16), 'slug-4211-'.\str_repeat('y', 15), false],
    'an id with one separator' => ['slug-'.\str_repeat('x', 16), 'slug-'.\str_repeat('y', 16), false],
    'a post id that starts the same' => ['news-123-'.\str_repeat('x', 16), 'news-12-'.\str_repeat('y', 16), false],
    'a percent sign in the slug' => ['aXXb-1-'.\str_repeat('x', 16), 'a%b-1-'.\str_repeat('y', 16), false],
    'a backslash in the slug' => ['ab-1-'.\str_repeat('x', 16), 'a\\b-1-'.\str_repeat('y', 16), false],
    'the same article, with a percent sign in its slug' => ['a%b-1-'.\str_repeat('x', 16), 'a%b-1-'.\str_repeat('y', 16), true],
    'the same article, with an underscore in its slug' => ['a_b-1-'.\str_repeat('x', 16), 'a_b-1-'.\str_repeat('y', 16), true],
    'the same article, with a backslash in its slug' => ['a\\b-1-'.\str_repeat('x', 16), 'a\\b-1-'.\str_repeat('y', 16), true],
])->group('kb-engagement');
