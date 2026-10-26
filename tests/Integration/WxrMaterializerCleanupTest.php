<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi\Client;
use UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi\MigrationApiException;
use UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi\WxrMaterializer;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;
use UamsWeb\WxrCleanup\Transform;

/**
 * The WXR cleaner runs on migration API content for the sites whose exports it cleans (wordpress-importer-archive#1303).
 *
 * The WXR workflow cleans `news.uams.edu`'s export with `uams-web/wordpress-wxr-cleanup`
 * before importing it. Content read through the migration API did not pass through the
 * cleaner, so the same post mapped differently depending on its source. These tests read the
 * materialized parts back with the real `XmlParser`, the class the importer reads exports with.
 *
 * The other half is what must NOT happen: the cleaner removes every HTML comment, Gutenberg
 * block delimiters included, so a site that is not listed has to come through untouched.
 *
 * @command  php vendor/bin/pest tests/Integration/WxrMaterializerCleanupTest.php
 */
uses(StatamicTestCase::class);

/**
 * A post body carrying each kind of markup the cleaner exists for.
 *
 * Word-paste noise, a caption shortcode wrapped in a paragraph with a linked image, a layout
 * table around the body, and a YouTube embed.
 */
const CLEANUP_RAW_BODY = '<table width="600" border="0"><tbody><tr><td>'
    .'<p class="MsoNormal"><span style="font-family: Arial;">LITTLE ROCK – The &ldquo;new&rdquo; clinic opened -- today.<o:p></o:p></span></p>'
    .'<p>[caption id="attachment_42" align="alignleft" width="300"]<a href="https://news.uams.edu/wp-content/uploads/a.jpg"><img src="https://news.uams.edu/wp-content/uploads/a.jpg" alt="A" /></a> A caption.[/caption]</p>'
    .'<p><embed src="https://www.youtube.com/v/dQw4w9WgXcQ" type="application/x-shockwave-flash" width="425" height="350"></embed></p>'
    .'</td></tr></tbody></table>';

beforeEach(function (): void {
    $this->workingDirectory = \sys_get_temp_dir().\DIRECTORY_SEPARATOR.\uniqid('wxr_clean_', true);
    \mkdir($this->workingDirectory);
});

afterEach(function (): void {
    $entries = \glob($this->workingDirectory.\DIRECTORY_SEPARATOR.'*');

    foreach ($entries === false ? [] : $entries as $entry) {
        \unlink($entry);
    }

    \rmdir($this->workingDirectory);
});

/**
 * Fake a single-site migration API whose site reports the given base blog URL.
 *
 * @param  string  $baseBlogUrl  The site's `base_blog_url`
 * @param  list<array<string, mixed>>  $posts  Post payloads served from `posts/post`
 */
function cleanupFakeApi(string $baseBlogUrl, array $posts): void
{
    Http::fake([
        '*/sites' => Http::response([[
            'id' => 1,
            'title' => 'Site',
            'link' => $baseBlogUrl,
            'description' => 'A site',
            'language' => 'en-US',
            'wxr_version' => '1.2',
            'base_site_url' => $baseBlogUrl,
            'base_blog_url' => $baseBlogUrl,
        ]], 200),
        '*/post-types*' => Http::response([['name' => 'post', 'exposed' => true]], 200),
        '*/taxonomies*' => Http::response([], 200),
        '*/authors*' => Http::response([], 200, ['X-WP-Total' => '0']),
        '*/posts/post*' => Http::response($posts, 200, ['X-WP-Total' => (string) \count($posts)]),
    ]);
}

/**
 * A post payload with the given title, body and excerpt.
 *
 * @return array<string, mixed>
 */
function cleanupPost(string $title, string $content, string $excerpt): array
{
    return [
        'id' => 501,
        'type' => 'post',
        'slug' => 'a-post',
        'status' => 'publish',
        'title' => $title,
        'author' => ['id' => 2, 'login' => 'todd', 'display_name' => 'Todd Example'],
        'date_created' => '2026-01-02 03:04:05',
        'date_created_gmt' => '2026-01-02 09:04:05',
        'date_modified' => '2026-01-02 03:04:05',
        'date_modified_gmt' => '2026-01-02 09:04:05',
        'content' => $content,
        'excerpt' => $excerpt,
        'parent' => 0,
        'menu_order' => 0,
        'guid' => 'https://news.uams.edu/?p=501',
        'link' => 'https://news.uams.edu/a-post/',
        'is_sticky' => false,
        'ping_status' => 'closed',
        'comment_status' => 'closed',
        'post_password' => '',
        'terms' => [],
        'meta' => [],
    ];
}

/**
 * Materialize the faked site and return its one post's title, body and excerpt as parsed.
 *
 * @return array{title: string, content: string, excerpt: string}
 */
function cleanupMaterializedPost(string $directory): array
{
    $paths = (new WxrMaterializer(new Client('https://example.test', 'shhh'), $directory))->materialize(1);

    return cleanupParsedPost($paths);
}

/**
 * Read the one post in the given WXR files as the importer's parser sees it.
 *
 * @param  list<string>  $paths  WXR file paths
 * @return array{title: string, content: string, excerpt: string}
 */
function cleanupParsedPost(array $paths): array
{
    $parser = new XmlParser;
    $parser->setFilePaths($paths);

    $item = $parser->getItems('post')[0];

    return [
        'title' => (string) $item->title,
        'content' => (string) $item->children('content', true)->encoded,
        'excerpt' => (string) $item->children('excerpt', true)->encoded,
    ];
}

/**
 * The same post as it reaches the importer from a cleaned export.
 *
 * Writes an export-shaped WXR holding the raw fields, cleans it exactly as the CLI does
 * (`Transform::withDefaultRules()->apply()`, which is all `bin/wxr-cleanup` runs), and parses
 * the result with the importer's parser.
 *
 * @return array{title: string, content: string, excerpt: string}
 */
function cleanupExportPost(string $directory, string $title, string $content, string $excerpt): array
{
    $cdata = static fn (string $value): string => '<![CDATA['.\str_replace(']]>', ']]]]><![CDATA[>', $value).']]>';

    $export = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<rss version="2.0" xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wfw="http://wellformedweb.org/CommentAPI/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:wp="http://wordpress.org/export/1.2/">'
        .'<channel><title>Site</title><link>https://news.uams.edu</link><wp:wxr_version>1.2</wp:wxr_version>'
        .'<wp:base_site_url>https://news.uams.edu</wp:base_site_url><wp:base_blog_url>https://news.uams.edu</wp:base_blog_url>'
        .'<item><title>'.$cdata($title).'</title><link>https://news.uams.edu/a-post/</link>'
        .'<dc:creator>'.$cdata('todd').'</dc:creator><guid>https://news.uams.edu/?p=501</guid>'
        .'<content:encoded>'.$cdata($content).'</content:encoded><excerpt:encoded>'.$cdata($excerpt).'</excerpt:encoded>'
        .'<wp:post_id>501</wp:post_id><wp:post_name>'.$cdata('a-post').'</wp:post_name><wp:status>publish</wp:status>'
        .'<wp:post_type>post</wp:post_type></item></channel></rss>';

    $path = $directory.\DIRECTORY_SEPARATOR.'export-cleaned.xml';
    \file_put_contents($path, Transform::withDefaultRules()->apply($export));

    try {
        return cleanupParsedPost([$path]);
    } finally {
        \unlink($path);
    }
}

it('maps a listed site\'s API post exactly as its cleaned export would', function (): void {
    $title = 'The &ldquo;new&rdquo; clinic -- opens';
    $excerpt = '<p>LITTLE ROCK – The <span style="color:red">clinic</span> opened.</p>';

    cleanupFakeApi('https://news.uams.edu', [cleanupPost($title, CLEANUP_RAW_BODY, $excerpt)]);

    $fromApi = cleanupMaterializedPost($this->workingDirectory);
    $fromExport = cleanupExportPost($this->workingDirectory, $title, CLEANUP_RAW_BODY, $excerpt);

    // Identical to the cleaned export, field by field
    expect($fromApi)->toBe($fromExport);

    // And genuinely cleaned, so the equality above is not two raw copies agreeing
    expect($fromApi['content'])->not->toBe(CLEANUP_RAW_BODY)
        ->not->toContain('MsoNormal')
        ->not->toContain('<table')
        ->not->toContain('<span')
        ->not->toContain('<embed')
        ->toContain('https://www.youtube.com/watch?v=dQw4w9WgXcQ')
        ->toContain('[caption id="attachment_42"');
});

it('leaves an unlisted site\'s block content untouched', function (): void {
    $body = '<!-- wp:paragraph --><p class="MsoNormal">Body -- copy.</p><!-- /wp:paragraph -->'
        .'<!-- wp:acf/hero {"name":"acf/hero"} /-->';

    cleanupFakeApi('https://health.uams.edu', [cleanupPost('A title', $body, 'An excerpt.')]);

    $post = cleanupMaterializedPost($this->workingDirectory);

    expect($post['content'])->toBe($body)
        ->and($post['title'])->toBe('A title')
        ->and($post['excerpt'])->toBe('An excerpt.');
});

it('cleans only the sites the configuration lists', function (): void {
    config(['wordpress-import.wxr_cleanup.sites' => ['health.uams.edu']]);

    cleanupFakeApi('https://news.uams.edu', [cleanupPost('A title', CLEANUP_RAW_BODY, '')]);

    expect(cleanupMaterializedPost($this->workingDirectory)['content'])->toBe(CLEANUP_RAW_BODY);
});

it('decides by the host of the site\'s base_blog_url', function (array $channel, bool $cleans): void {
    expect(WxrMaterializer::cleansSite($channel))->toBe($cleans);
})->with([
    'the listed host' => [['base_blog_url' => 'https://news.uams.edu'], true],
    'the listed host with a path and a different case' => [['base_blog_url' => 'https://NEWS.uams.edu/archive/'], true],
    'another host' => [['base_blog_url' => 'https://health.uams.edu'], false],
    'a host that only ends with the listed one' => [['base_blog_url' => 'https://oldnews.uams.edu'], false],
    'no base_blog_url' => [[], false],
    'a URL with no host' => [['base_blog_url' => 'news.uams.edu'], false],
]);

it('refuses a list that is not a list', function (): void {
    config(['wordpress-import.wxr_cleanup.sites' => 'news.uams.edu']);

    expect(WxrMaterializer::cleansSite(['base_blog_url' => 'https://news.uams.edu']))->toBeFalse();
});

it('refuses to overwrite a part the cleaner could not read', function (): void {
    // The cleaner hides libxml errors, so an unparseable part comes back as an empty document.
    // Writing that over the part would silently drop every item it held.
    $path = $this->workingDirectory.\DIRECTORY_SEPARATOR.'wxr.000.xml';
    $broken = '<rss><channel><item><title>One</title></item><item><title>Two</title></item>';
    \file_put_contents($path, $broken);

    $materializer = new WxrMaterializer(new Client('https://example.test', 'shhh'), $this->workingDirectory);
    $cleanPart = Closure::bind(function (string $part): void {
        $this->cleanParts([$part]);
    }, $materializer, WxrMaterializer::class);

    if ($cleanPart === null) {
        throw new RuntimeException('Could not bind to the materializer.');
    }

    expect(fn () => $cleanPart($path))->toThrow(MigrationApiException::class, 'returned 0 of its 2 items');
    expect(\file_get_contents($path))->toBe($broken);
});
