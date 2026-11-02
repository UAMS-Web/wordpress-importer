<?php

declare(strict_types=1);

/**
 * Tests that `LinkProcessor` finds the WordPress item a URL names in an export on
 * disk: from the URL cache, by an exact `<link>` in any part, by the URL's
 * structure, or by the page ID in the page's own HTML; that it finds an item by
 * its post ID; and that it splits a URL into its base, query and fragment.
 *
 * The methods are private, so they are reached by reflection.
 *
 * @command  php vendor/bin/pest tests/Integration/LinkProcessorFindPostDataByUrlTest.php
 */

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

beforeEach(function (): void {
    // A link that falls through to fetching its page must not reach the network
    Http::preventStrayRequests();
    LinkProcessor::clearDeferredData();

    $this->exportDirectory = sys_get_temp_dir().'/link-processor-find-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->exportDirectory);
});

afterEach(function (): void {
    LinkProcessor::clearDeferredData();

    File::deleteDirectory($this->exportDirectory);
});

/**
 * A WordPress item for the export.
 */
function findByUrlItem(string $id, string $name, string $link = '', string $status = 'draft', string $type = 'page'): string
{
    return "<item><link>{$link}</link><wp:post_id>{$id}</wp:post_id><wp:post_name>{$name}</wp:post_name>"
        ."<wp:post_parent>0</wp:post_parent><wp:post_type>{$type}</wp:post_type><wp:status>{$status}</wp:status></item>";
}

/**
 * Write an export file holding the given items, and return its path.
 */
function findByUrlExport(string $path, string ...$items): string
{
    File::put(
        $path,
        '<?xml version="1.0" encoding="UTF-8"?><rss xmlns:wp="http://wordpress.org/export/1.2/"><channel>'
        .'<wp:base_blog_url>http://93.184.216.34/site</wp:base_blog_url>'.implode('', $items).'</channel></rss>'
    );

    return $path;
}

/**
 * Call one of the processor's private methods.
 */
function findByUrlCall(string $method, mixed ...$arguments): mixed
{
    // The host app turns a warning into an exception, so a warning fails the test
    set_error_handler(static function (int $level, string $message): never {
        throw new ErrorException($message, 0, $level);
    });

    try {
        return (new ReflectionMethod(LinkProcessor::class, $method))->invoke(new LinkProcessor, ...$arguments);
    } finally {
        restore_error_handler();
    }
}

/**
 * The directory this test writes its export files to.
 */
function findByUrlDirectory(mixed $test): string
{
    $directory = is_object($test) ? ($test->exportDirectory ?? null) : null;

    return is_string($directory) ? $directory : throw new LogicException('No export directory.');
}

it('finds an item by its exact link, and caches its post ID', function (): void {
    $path = findByUrlExport(findByUrlDirectory($this).'/export.xml', findByUrlItem('5', 'about', 'http://93.184.216.34/site/about/', 'publish'));

    expect(findByUrlCall('findPostDataByUrl', 'http://93.184.216.34/site/about/', $path))
        ->toBe(['post_id' => '5', 'post_name' => 'about', 'post_type' => 'page']);

    // The cache now answers for the URL, even once the item has left the export
    findByUrlExport($path, findByUrlItem('5', 'renamed', status: 'publish'));

    expect(findByUrlCall('findPostDataByUrl', 'http://93.184.216.34/site/about/', $path))
        ->toBe(['post_id' => '5', 'post_name' => 'renamed', 'post_type' => 'page']);
});

it('searches for the final URL after redirects when there is one', function (?string $finalUrl, mixed $found): void {
    // A miss falls back to fetching the page
    Http::fake(['*' => Http::response('')]);
    $path = findByUrlExport(findByUrlDirectory($this).'/export.xml', findByUrlItem('5', 'about', 'http://93.184.216.34/site/about/', 'publish'));

    expect(findByUrlCall('findPostDataByUrl', 'http://93.184.216.34/site/old/', $path, $finalUrl))->toBe($found);
})->with([
    'a final URL' => ['http://93.184.216.34/site/about/', ['post_id' => '5', 'post_name' => 'about', 'post_type' => 'page']],
    'an empty final URL' => ['', null],
    'no final URL' => [null, null],
]);

it('searches the export when the URL is cached with an empty post ID', function (): void {
    $path = findByUrlExport(findByUrlDirectory($this).'/export.xml', findByUrlItem('5', 'about', 'http://93.184.216.34/site/about/', 'publish'));
    LinkProcessor::registerUrlMapping('http://93.184.216.34/site/about/', '');

    expect(findByUrlCall('findPostDataByUrl', 'http://93.184.216.34/site/about/', $path))
        ->toBe(['post_id' => '5', 'post_name' => 'about', 'post_type' => 'page']);
});

it('finds nothing for an exact link match missing a required field', function (string $item): void {
    $path = findByUrlExport(findByUrlDirectory($this).'/export.xml', $item);

    expect(findByUrlCall('findPostDataByUrl', 'http://93.184.216.34/site/about/', $path))->toBeNull();
})->with([
    'no post ID' => ['<item><link>http://93.184.216.34/site/about/</link><wp:post_name>about</wp:post_name><wp:post_type>page</wp:post_type></item>'],
    'no post name' => ['<item><link>http://93.184.216.34/site/about/</link><wp:post_id>5</wp:post_id><wp:post_type>page</wp:post_type></item>'],
    'no post type' => ['<item><link>http://93.184.216.34/site/about/</link><wp:post_id>5</wp:post_id><wp:post_name>about</wp:post_name></item>'],
]);

it('finds an unpublished item by the URL structure, caches it, and fetches nothing', function (): void {
    Http::fake();
    $path = findByUrlExport(findByUrlDirectory($this).'/export.xml', findByUrlItem('5', 'about'));

    expect(findByUrlCall('findPostDataByUrl', 'http://93.184.216.34/site/about/', $path))
        ->toBe(['post_id' => '5', 'post_name' => 'about', 'post_type' => 'page']);

    Http::assertNothingSent();

    // The cache now answers for the URL
    findByUrlExport($path, findByUrlItem('5', 'renamed'));

    expect(findByUrlCall('findPostDataByUrl', 'http://93.184.216.34/site/about/', $path))
        ->toBe(['post_id' => '5', 'post_name' => 'renamed', 'post_type' => 'page']);
});

it('prefers an exact link in a later part to a structure match in an earlier one', function (): void {
    $directory = findByUrlDirectory($this);
    $first = findByUrlExport($directory.'/export.001.xml', findByUrlItem('5', 'about'));
    findByUrlExport($directory.'/export.002.xml', findByUrlItem('6', 'about-us', 'http://93.184.216.34/site/about/', 'publish'));

    expect(findByUrlCall('findPostDataByUrl', 'http://93.184.216.34/site/about/', $first))
        ->toBe(['post_id' => '6', 'post_name' => 'about-us', 'post_type' => 'page']);
});

it('keeps the first part\'s structure match when a later part has none', function (): void {
    Http::fake();
    $directory = findByUrlDirectory($this);
    $first = findByUrlExport($directory.'/export.001.xml', findByUrlItem('5', 'about'));
    findByUrlExport($directory.'/export.002.xml', findByUrlItem('6', 'contact'));

    expect(findByUrlCall('findPostDataByUrl', 'http://93.184.216.34/site/about/', $first))
        ->toBe(['post_id' => '5', 'post_name' => 'about', 'post_type' => 'page']);

    Http::assertNothingSent();
});

it('skips a part that cannot be parsed and searches the next', function (): void {
    $directory = findByUrlDirectory($this);
    File::put($directory.'/export.001.xml', 'not xml');
    findByUrlExport($directory.'/export.002.xml', findByUrlItem('6', 'about', 'http://93.184.216.34/site/about/', 'publish'));

    // simplexml_load_file() warns about the part it cannot parse
    set_error_handler(static fn (): bool => true);

    try {
        $found = (new ReflectionMethod(LinkProcessor::class, 'findPostDataByUrl'))
            ->invoke(new LinkProcessor, 'http://93.184.216.34/site/about/', $directory.'/export.001.xml');
    } finally {
        restore_error_handler();
    }

    expect($found)->toBe(['post_id' => '6', 'post_name' => 'about', 'post_type' => 'page']);
});

it('falls back to the page ID in the page\'s HTML', function (string $body, mixed $found): void {
    Http::fake(['*' => Http::response($body)]);
    $path = findByUrlExport(findByUrlDirectory($this).'/export.xml', findByUrlItem('5', 'renamed', status: 'publish'));

    expect(findByUrlCall('findPostDataByUrl', 'http://93.184.216.34/site/about/', $path))->toBe($found);

    Http::assertSentCount(1);
})->with([
    'a page ID in the export' => ['<body class="page page-id-5 x">', ['post_id' => '5', 'post_name' => 'renamed', 'post_type' => 'page']],
    'a page ID not in the export' => ['<body class="page-id-9">', null],
    'no page ID' => ['<body class="home">', null],
]);

it('finds an item by its post ID, with an empty name for a draft', function (): void {
    $path = findByUrlExport(findByUrlDirectory($this).'/export.xml', findByUrlItem('5', '', type: 'post'));

    expect(findByUrlCall('findPostNameById', '5', $path))->toBe(['post_id' => '5', 'post_name' => '', 'post_type' => 'post']);
});

it('finds nothing by post ID for an item that is absent or has no post type', function (string $id): void {
    $path = findByUrlExport(
        findByUrlDirectory($this).'/export.xml',
        '<item><wp:post_id>5</wp:post_id><wp:post_name>about</wp:post_name></item>'
    );

    expect(findByUrlCall('findPostNameById', $id, $path))->toBeNull();
})->with([
    'absent' => ['6'],
    'no post type' => ['5'],
]);

it('splits a URL into its base, query and fragment', function (string $url, array $parts): void {
    expect(findByUrlCall('parseUrlComponents', $url))->toBe($parts);
})->with([
    'a plain URL' => ['https://x.test/a/', ['base' => 'https://x.test/a/', 'fragment' => null, 'query' => null]],
    'a query' => ['https://x.test/a/?q=1', ['base' => 'https://x.test/a/', 'fragment' => null, 'query' => '?q=1']],
    'a fragment' => ['https://x.test/a/#top', ['base' => 'https://x.test/a/', 'fragment' => '#top', 'query' => null]],
    'a query and a fragment, each holding its separator again' => ['https://x.test/a/?q=1?r#f#g', ['base' => 'https://x.test/a/', 'fragment' => '#f#g', 'query' => '?q=1?r']],
    'a question mark inside the fragment' => ['https://x.test/a/#f?q', ['base' => 'https://x.test/a/', 'fragment' => '#f?q', 'query' => null]],
]);

it('finds an item by the exact link it holds an ampersand in, without fetching the page', function (): void {
    // The URL is compared as text, not as escaped markup (#160)
    Http::fake(['*' => Http::response('')]);
    $path = findByUrlExport(findByUrlDirectory($this).'/export.xml', findByUrlItem('5', 'about', 'http://93.184.216.34/site/about/?a=1&amp;b=2', 'publish'));

    expect(findByUrlCall('findPostDataByUrl', 'http://93.184.216.34/site/about/?a=1&b=2', $path))
        ->toBe(['post_id' => '5', 'post_name' => 'about', 'post_type' => 'page']);

    Http::assertNothingSent();
});

it('finds an item by structure in an export that binds the WordPress namespace to another prefix', function (): void {
    $path = findByUrlDirectory($this).'/export.xml';
    File::put(
        $path,
        '<?xml version="1.0" encoding="UTF-8"?><rss xmlns:wordpress="http://wordpress.org/export/1.2/"><channel>'
        .'<wordpress:base_blog_url>http://93.184.216.34/site</wordpress:base_blog_url>'
        .'<item><wordpress:post_id>5</wordpress:post_id><wordpress:post_name>about</wordpress:post_name><wordpress:post_parent>0</wordpress:post_parent>'
        .'<wordpress:post_type>page</wordpress:post_type><wordpress:status>draft</wordpress:status></item></channel></rss>'
    );

    expect(findByUrlCall('findPostDataByUrl', 'http://93.184.216.34/site/about/', $path))
        ->toBe(['post_id' => '5', 'post_name' => 'about', 'post_type' => 'page']);
});
