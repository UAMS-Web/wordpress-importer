<?php

declare(strict_types=1);

/**
 * Tests the WordPress metadata stamped for the migration redirect generator.
 *
 * `UAMS-Web/uams-statamic#1641` synthesizes structural WordPress redirects —
 * `?p=<id>` / `?page_id=<id>` ugly permalinks, `/author/<nicename>/` archives,
 * and attachment pages. It can only do that if the importer stamps the values
 * those URLs derive from as queryable keys. Today the numeric post id is folded
 * into the Statamic entry id (`<slug>-<postId>-<random>`) rather than stored,
 * and the author nicename is discarded entirely (wordpress-importer-archive#446).
 *
 * These are loose data keys, not blueprint fields — no host blueprint declares
 * `wordpress_post_id`, `wordpress_author_login`, or
 * `wordpress_attachment_page_url`, exactly as none declares the
 * `original_wordpress_url` the importer already stamps successfully.
 *
 * The author key is deliberately `wordpress_author_login`, not a nicename:
 * `dc:creator` carries the WordPress `user_login` (`Ashley`), while an author
 * archive URL uses the `user_nicename` slug (`ashley`). WXR exports no
 * `wp:author_nicename` anywhere in the corpus, so the importer cannot resolve
 * one and must not claim to.
 *
 * @command  php vendor/bin/pest tests/Unit/MigrationRedirectMetadataTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;

/**
 * Build a WXR `<item>` carrying the given post id and author nicename.
 */
function migrationRedirectItem(
    ?string $postId = '4207',
    ?string $creator = 'jane-doe'
): SimpleXMLElement {
    $postIdNode = $postId === null ? '' : "<wp:post_id>{$postId}</wp:post_id>";
    $creatorNode = $creator === null ? '' : "<dc:creator><![CDATA[{$creator}]]></dc:creator>";

    $xml = <<<XML
    <rss version="2.0"
         xmlns:dc="http://purl.org/dc/elements/1.1/"
         xmlns:wp="http://wordpress.org/export/1.2/">
        <channel>
            <item>
                <title>A post</title>
                {$creatorNode}
                {$postIdNode}
            </item>
        </channel>
    </rss>
    XML;

    $rss = simplexml_load_string($xml);

    // The fixture is authored here, so a parse failure is a test bug, not a data case
    if (! $rss instanceof SimpleXMLElement) {
        throw new RuntimeException('Test fixture XML failed to parse');
    }

    return $rss->channel->item[0];
}

it('stamps the numeric WordPress post id', function (): void {
    $metadata = XpathHelper::extractMigrationRedirectMetadata(migrationRedirectItem());

    // Numeric so `?p=<id>` lookups compare against an integer, not a string
    expect($metadata['wordpress_post_id'] ?? null)->toBe(4207);
});

it('stamps the author login', function (): void {
    $metadata = XpathHelper::extractMigrationRedirectMetadata(migrationRedirectItem());

    expect($metadata['wordpress_author_login'] ?? null)->toBe('jane-doe');
});

it('omits the post id when the item carries none', function (): void {
    $metadata = XpathHelper::extractMigrationRedirectMetadata(
        migrationRedirectItem(null, 'jane-doe')
    );

    expect($metadata)->not->toHaveKey('wordpress_post_id');
    expect($metadata['wordpress_author_login'] ?? null)->toBe('jane-doe');
});

it('omits the author when the item carries none', function (): void {
    $metadata = XpathHelper::extractMigrationRedirectMetadata(
        migrationRedirectItem('4207', null)
    );

    expect($metadata)->not->toHaveKey('wordpress_author_login');
    expect($metadata['wordpress_post_id'] ?? null)->toBe(4207);
});

it('omits a post id that is not numeric', function (string $postId): void {
    // A non-numeric id cannot drive a `?p=` redirect, so it is not stamped
    expect(XpathHelper::extractMigrationRedirectMetadata(migrationRedirectItem($postId)))
        ->not->toHaveKey('wordpress_post_id');
})->with([
    'empty' => [''],
    'whitespace' => ['   '],
    'non-numeric' => ['abc'],
    'zero' => ['0'],
]);

it('omits a blank author login', function (string $creator): void {
    expect(XpathHelper::extractMigrationRedirectMetadata(migrationRedirectItem('4207', $creator)))
        ->not->toHaveKey('wordpress_author_login');
})->with([
    'empty' => [''],
    'whitespace' => ['   '],
]);

it('trims the author login', function (): void {
    $metadata = XpathHelper::extractMigrationRedirectMetadata(
        migrationRedirectItem('4207', '  jane-doe  ')
    );

    expect($metadata['wordpress_author_login'])->toBe('jane-doe');
});

it('returns nothing for an item carrying neither', function (): void {
    expect(XpathHelper::extractMigrationRedirectMetadata(migrationRedirectItem(null, null)))
        ->toBe([]);
});
