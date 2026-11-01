<?php

declare(strict_types=1);

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\KnowledgeBase\ArticleEngagementSeeder;

/**
 * Tests the MinervaKB → sideband-store mapping (issue wordpress-importer-archive#427).
 *
 * MinervaKB stores its per-article aggregates as plain integer strings in the WXR
 * postmeta: `_mkb_views`, `_mkb_likes` and `_mkb_dislikes`. The parallel
 * `_mkb_*_meta` keys hold a per-day breakdown (`{"1750636800":1}`) the store has
 * no column for.
 *
 * `extractCounts()` is pure, so the mapping is covered here without a database;
 * the upsert itself is covered in `tests/Integration/ArticleEngagementSeedTest.php`.
 *
 * @command  php vendor/bin/pest tests/Unit/ArticleEngagementExtractionTest.php
 */

/**
 * Build a WXR `<item>` carrying the given postmeta pairs.
 *
 * @param  array<string, string>  $meta  meta_key => meta_value
 */
function articleEngagementItem(array $meta): SimpleXMLElement
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

it('reads the three MinervaKB aggregates', function (): void {
    $counts = ArticleEngagementSeeder::extractCounts(
        articleEngagementItem([
            '_mkb_views' => '59',
            '_mkb_likes' => '4',
            '_mkb_dislikes' => '1',
        ])
    );

    expect($counts)->toBe([
        'views' => 59,
        'likes' => 4,
        'dislikes' => 1,
    ]);
})->group('kb-engagement');

it('reads a views-only article, which is the common corpus shape', function (): void {
    // Views outnumber likes and dislikes by roughly six to one across the
    // corpus, so most articles carry views alone.
    $counts = ArticleEngagementSeeder::extractCounts(
        articleEngagementItem(['_mkb_views' => '41'])
    );

    expect($counts)->toBe([
        'views' => 41,
        'likes' => 0,
        'dislikes' => 0,
    ]);
})->group('kb-engagement');

it('reads all zeroes for an article with no engagement meta', function (): void {
    $counts = ArticleEngagementSeeder::extractCounts(
        articleEngagementItem(['_edit_last' => '3'])
    );

    expect($counts)->toBe([
        'views' => 0,
        'likes' => 0,
        'dislikes' => 0,
    ]);
})->group('kb-engagement');

it('ignores the per-day breakdown keys', function (): void {
    // `_mkb_views_meta` is a JSON date histogram; the store has no column for it and
    // it must not be mistaken for the aggregate.
    $counts = ArticleEngagementSeeder::extractCounts(
        articleEngagementItem([
            '_mkb_views_meta' => '{"1586390400":6,"1588118400":10}',
            '_mkb_likes_meta' => '{"1586390400":1}',
            '_mkb_views' => '16',
        ])
    );

    expect($counts)->toBe([
        'views' => 16,
        'likes' => 0,
        'dislikes' => 0,
    ]);
})->group('kb-engagement');

it('treats an unusable aggregate as zero', function (string $value): void {
    // The store's columns are unsigned and MinervaKB has no negative tally, so a
    // garbage value must never reach the insert.
    $counts = ArticleEngagementSeeder::extractCounts(
        articleEngagementItem(['_mkb_views' => $value])
    );

    expect($counts['views'])->toBe(0);
})->with([
    'empty' => [''],
    'whitespace' => ['   '],
    'non-numeric' => ['many'],
    'negative' => ['-5'],
    'json' => ['{"1586390400":6}'],
])->group('kb-engagement');

it('truncates a decimal aggregate to a whole tally', function (): void {
    expect(ArticleEngagementSeeder::extractCounts(
        articleEngagementItem(['_mkb_views' => '12.9'])
    )['views'])->toBe(12);
})->group('kb-engagement');

it('trims a padded aggregate', function (): void {
    expect(ArticleEngagementSeeder::extractCounts(
        articleEngagementItem(['_mkb_likes' => '  7  '])
    )['likes'])->toBe(7);
})->group('kb-engagement');

it('reads the aggregates after one it cannot use', function (): void {
    // An unusable value skips only its own tally (#102)
    expect(ArticleEngagementSeeder::extractCounts(
        articleEngagementItem(['_mkb_views' => 'many', '_mkb_likes' => '3', '_mkb_dislikes' => "\n2\n"])
    ))->toBe(['views' => 0, 'likes' => 3, 'dislikes' => 2]);
})->group('kb-engagement');
