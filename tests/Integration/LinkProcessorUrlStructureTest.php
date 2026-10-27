<?php

declare(strict_types=1);

/**
 * Tests that `LinkProcessor` finds the WordPress item an unpublished URL names by
 * its structure: the path after the site's base URL, matched from its last
 * segment back through the item's parent, or, for a post, through the date or
 * the category in the URL; and that an ambiguous or incomplete match is no match.
 *
 * Published items match their exact `<link>` first, so only unpublished ones are
 * considered here. The method is private, so it is reached by reflection.
 *
 * @command  php vendor/bin/pest tests/Integration/LinkProcessorUrlStructureTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * A WordPress item for the export.
 *
 * @param  array<string, string>  $fields  Item fields by element name, e.g. `wp:post_date`
 * @param  list<string>  $categories  Category nicenames
 */
function urlStructureItem(string $id, string $name, string $parent = '0', string $type = 'page', string $status = 'draft', array $fields = [], array $categories = []): string
{
    $xml = "<item><wp:post_id>{$id}</wp:post_id><wp:post_name>{$name}</wp:post_name><wp:post_parent>{$parent}</wp:post_parent>"
        ."<wp:post_type>{$type}</wp:post_type><wp:status>{$status}</wp:status>";

    foreach ($fields as $element => $value) {
        $xml .= "<{$element}>{$value}</{$element}>";
    }

    foreach ($categories as $category) {
        $xml .= "<category domain=\"category\" nicename=\"{$category}\"><![CDATA[{$category}]]></category>";
    }

    return $xml.'</item>';
}

/**
 * Find the item the URL names among the given items.
 *
 * @param  list<string>  $items
 * @return mixed The post data, or null
 */
function urlStructureFind(string $url, array $items, string $baseUrl = 'https://example.com/site'): mixed
{
    $xml = new SimpleXMLElement(
        '<rss xmlns:wp="http://wordpress.org/export/1.2/"><channel>'
        .($baseUrl !== '' ? "<wp:base_blog_url>{$baseUrl}</wp:base_blog_url>" : '')
        .implode('', $items).'</channel></rss>'
    );
    XpathHelper::registerWordPressNamespaces($xml);

    // The host app turns a warning into an exception, so a warning fails the test
    set_error_handler(static function (int $level, string $message): never {
        throw new ErrorException($message, 0, $level);
    });

    try {
        return (new ReflectionMethod(LinkProcessor::class, 'findPostDataByUrlStructure'))->invoke(new LinkProcessor, $url, $xml);
    } finally {
        restore_error_handler();
    }
}

/**
 * Find the item the URL names, recording the warnings raised instead of failing.
 *
 * @param  list<string>  $items
 * @return array{found: mixed, warnings: list<string>}
 */
function urlStructureFindWithWarnings(string $url, array $items): array
{
    $warnings = new ArrayObject;

    // libxml reports a bad query as a warning only with its internal errors off
    $internal = libxml_use_internal_errors(false);

    set_error_handler(static function (int $level, string $message) use ($warnings): bool {
        $warnings->append($message);

        return true;
    });

    try {
        $xml = new SimpleXMLElement(
            '<rss xmlns:wp="http://wordpress.org/export/1.2/"><channel><wp:base_blog_url>https://example.com/site</wp:base_blog_url>'
            .implode('', $items).'</channel></rss>'
        );
        XpathHelper::registerWordPressNamespaces($xml);

        $found = (new ReflectionMethod(LinkProcessor::class, 'findPostDataByUrlStructure'))->invoke(new LinkProcessor, $url, $xml);
    } finally {
        restore_error_handler();
        libxml_use_internal_errors($internal);
    }

    return ['found' => $found, 'warnings' => urlStructureStrings($warnings->getArrayCopy())];
}

/**
 * A dataset's list of strings, typed.
 *
 * @return list<string>
 */
function urlStructureStrings(mixed $value): array
{
    if (! is_array($value)) {
        throw new InvalidArgumentException('Expected a list of strings.');
    }

    $strings = [];

    foreach ($value as $string) {
        if (! is_string($string)) {
            throw new InvalidArgumentException('Expected a list of strings.');
        }

        $strings[] = $string;
    }

    return $strings;
}

/**
 * A dataset's fields by element name, typed.
 *
 * @return array<string, string>
 */
function urlStructureFields(mixed $value): array
{
    if (! is_array($value)) {
        throw new InvalidArgumentException('Expected fields by element name.');
    }

    $fields = [];

    foreach ($value as $element => $field) {
        if (! is_string($element) || ! is_string($field)) {
            throw new InvalidArgumentException('Expected fields by element name.');
        }

        $fields[$element] = $field;
    }

    return $fields;
}

it('finds an unpublished root-level page by its slug', function (string $url): void {
    expect(urlStructureFind($url, [urlStructureItem('5', 'about')]))->toBe(['post_id' => '5', 'post_name' => 'about', 'post_type' => 'page']);
})->with([
    'with a trailing slash' => ['https://example.com/site/about/'],
    'over plain http' => ['http://example.com/site/about'],
    'protocol-relative' => ['//example.com/site/about'],
    'with a query' => ['https://example.com/site/about?x=1'],
    'with a fragment' => ['https://example.com/site/about#team'],
    'with a query and a fragment' => ['https://example.com/site/about/?x=1#team'],
]);

it('finds nothing for a URL outside the site or naming no page', function (string $url, string $baseUrl): void {
    expect(urlStructureFind($url, [urlStructureItem('5', 'about')], $baseUrl))->toBeNull();
})->with([
    'another site' => ['https://example.org/site/about', 'https://example.com/site'],
    'the site root' => ['https://example.com/site/', 'https://example.com/site'],
    'only a query' => ['https://example.com/site/?x=1', 'https://example.com/site'],
    'only a fragment' => ['https://example.com/site/#top', 'https://example.com/site'],
    'an export with no base URL' => ['https://example.com/site/about', ''],
    'no item with the slug' => ['https://example.com/site/contact', 'https://example.com/site'],
]);

it('finds nothing for a published page, which matches its link instead', function (): void {
    expect(urlStructureFind('https://example.com/site/about', [urlStructureItem('5', 'about', status: 'publish')]))->toBeNull();
});

it('finds nothing at the root for a page that has a parent, or for two root pages with the slug', function (array $items): void {
    expect(urlStructureFind('https://example.com/site/about', urlStructureStrings($items)))->toBeNull();
})->with([
    'a child page' => [[urlStructureItem('5', 'about', '3')]],
    'a page with no parent field value' => [[urlStructureItem('5', 'about', '')]],
    'two root pages' => [[urlStructureItem('5', 'about'), urlStructureItem('6', 'about', status: 'pending')]],
]);

it('picks the one root page among published and child pages with the slug', function (): void {
    $items = [urlStructureItem('4', 'about', status: 'publish'), urlStructureItem('5', 'about'), urlStructureItem('6', 'about', '3')];

    expect(urlStructureFind('https://example.com/site/about', $items))->toBe(['post_id' => '5', 'post_name' => 'about', 'post_type' => 'page']);
});

it('finds a child page whose parent is the root page the URL names before it', function (): void {
    $items = [urlStructureItem('3', 'parent', status: 'publish'), urlStructureItem('9', 'team', '7'), urlStructureItem('8', 'team', '3')];

    expect(urlStructureFind('https://example.com/site/parent//team', $items))->toBe(['post_id' => '8', 'post_name' => 'team', 'post_type' => 'page']);
});

it('finds nothing for a child page whose parent does not fit the URL', function (array $items): void {
    expect(urlStructureFind('https://example.com/site/parent/team', urlStructureStrings($items)))->toBeNull();
})->with([
    'a parent with another slug' => [[urlStructureItem('3', 'other'), urlStructureItem('8', 'team', '3')]],
    'a parent that has a parent of its own' => [[urlStructureItem('3', 'parent', '1'), urlStructureItem('8', 'team', '3')]],
    'a parent missing from the export' => [[urlStructureItem('8', 'team', '3')]],
    'a page at the root' => [[urlStructureItem('3', 'parent'), urlStructureItem('8', 'team')]],
    'two children of the parent' => [[urlStructureItem('3', 'parent'), urlStructureItem('8', 'team', '3'), urlStructureItem('9', 'team', '3', status: 'pending')]],
]);

it('checks only the parent of a page nested deeper than two levels', function (): void {
    // The grandparent segment is not compared with anything
    $items = [urlStructureItem('2', 'grand'), urlStructureItem('3', 'parent', '2'), urlStructureItem('8', 'team', '3')];

    expect(urlStructureFind('https://example.com/site/anything/parent/team', $items))->toBe(['post_id' => '8', 'post_name' => 'team', 'post_type' => 'page']);
});

it('finds an unpublished post by the date in its URL', function (array $fields): void {
    $items = [urlStructureItem('7', 'news', type: 'post', fields: urlStructureFields($fields)), urlStructureItem('8', 'news', type: 'post', fields: ['wp:post_date' => '2024-02-03 09:00:00'])];

    expect(urlStructureFind('https://example.com/site/2024/01/02/news', $items))->toBe(['post_id' => '7', 'post_name' => 'news', 'post_type' => 'post']);
})->with([
    'the post date' => [['wp:post_date' => '2024-01-02 23:59:59']],
    'the publish date when the post date is empty' => [['wp:post_date' => '', 'pubDate' => 'Tue, 02 Jan 2024 10:00:00 +0000']],
]);

it('finds nothing by date for two posts on the date, and skips posts with no date or a bad one', function (array $items, mixed $found): void {
    expect(urlStructureFind('https://example.com/site/2024/01/02/news', urlStructureStrings($items)))->toBe($found);
})->with([
    'two posts on the date' => [[
        urlStructureItem('7', 'news', type: 'post', fields: ['wp:post_date' => '2024-01-02 08:00:00']),
        urlStructureItem('8', 'news', type: 'post', fields: ['wp:post_date' => '2024-01-02 09:00:00']),
    ], null],
    'a post with no date beside one on the date' => [[
        urlStructureItem('6', 'news', type: 'post'),
        urlStructureItem('7', 'news', type: 'post', fields: ['wp:post_date' => 'not a date']),
        urlStructureItem('8', 'news', type: 'post', fields: ['wp:post_date' => '2024-01-02 09:00:00']),
    ], ['post_id' => '8', 'post_name' => 'news', 'post_type' => 'post']],
    'a post on another day, month or year' => [[
        urlStructureItem('6', 'news', type: 'post', fields: ['wp:post_date' => '2024-01-03 09:00:00']),
        urlStructureItem('7', 'news', type: 'post', fields: ['wp:post_date' => '2024-02-02 09:00:00']),
        urlStructureItem('8', 'news', type: 'post', fields: ['wp:post_date' => '2023-01-02 09:00:00']),
    ], null],
]);

it('finds an unpublished post by the category in its URL', function (): void {
    $items = [
        urlStructureItem('6', 'news', type: 'post', categories: ['events']),
        urlStructureItem('7', 'news', type: 'post', categories: ['campus', 'research']),
    ];

    expect(urlStructureFind('https://example.com/site/research/news', $items))->toBe(['post_id' => '7', 'post_name' => 'news', 'post_type' => 'post']);
});

it('finds nothing by category for no post or two posts in it', function (array $categories): void {
    $items = [];

    foreach ($categories as $nicenames) {
        $nicenames = urlStructureStrings($nicenames);
        $items[] = urlStructureItem((string) (count($nicenames) + 6), 'news', type: 'post', categories: $nicenames);
    }

    expect(urlStructureFind('https://example.com/site/research/news', $items))->toBeNull();
})->with([
    'none in the category' => [[['campus']]],
    'two in the category' => [[['research'], ['research', 'campus']]],
]);

it('reads a post category only from a category element, not a tag', function (): void {
    $item = '<item><wp:post_id>7</wp:post_id><wp:post_name>news</wp:post_name><wp:post_parent>0</wp:post_parent><wp:post_type>post</wp:post_type><wp:status>draft</wp:status>'
        .'<category domain="post_tag" nicename="research"><![CDATA[research]]></category></item>';

    expect(urlStructureFind('https://example.com/site/research/news', [$item]))->toBeNull();
});

it('falls back to the parent for a post whose date or category does not fit', function (): void {
    // A post with a parent page named like the first segment
    $items = [urlStructureItem('3', 'research'), urlStructureItem('7', 'news', '3', 'post')];

    expect(urlStructureFind('https://example.com/site/research/news', $items))->toBeNull()
        ->and(urlStructureFind('https://example.com/site/2024/01/02/news', [urlStructureItem('7', 'news', type: 'post', fields: ['wp:post_date' => '2023-05-05'])]))->toBeNull();
});

it('finds a single-segment post at the root, without the date or category checks', function (): void {
    expect(urlStructureFind('https://example.com/site/news', [urlStructureItem('7', 'news', type: 'post')]))->toBe(['post_id' => '7', 'post_name' => 'news', 'post_type' => 'post']);
});

it('finds nothing for a match missing its id or type', function (string $url, string $item): void {
    expect(urlStructureFind($url, [$item]))->toBeNull();
})->with([
    'a root page without an id' => ['https://example.com/site/about', '<item><wp:post_id></wp:post_id><wp:post_name>about</wp:post_name><wp:post_parent>0</wp:post_parent><wp:post_type>page</wp:post_type></item>'],
    'a root page without a type' => ['https://example.com/site/about', '<item><wp:post_id>5</wp:post_id><wp:post_name>about</wp:post_name><wp:post_parent>0</wp:post_parent><wp:post_type></wp:post_type></item>'],
    'a dated post without an id' => ['https://example.com/site/2024/01/02/news', '<item><wp:post_id></wp:post_id><wp:post_name>news</wp:post_name><wp:post_type>post</wp:post_type><wp:post_date>2024-01-02</wp:post_date></item>'],
]);

it('finds nothing when the export has no base URL, even for a URL that would name a root page', function (): void {
    expect(urlStructureFind('https://about', [urlStructureItem('5', 'about')], ''))->toBeNull();
});

it('finds nothing for a URL that is only question marks after the site', function (): void {
    expect(urlStructureFind('https://example.com/site/??', [urlStructureItem('5', 'about')]))->toBeNull();
});

it('reads a fragment straight after the site as the slug', function (): void {
    // Characterization of #160: the leading slash is trimmed before the fragment
    // is cut, and strtok() skips a leading #, so the fragment is taken for a slug
    expect(urlStructureFind('https://example.com/site/#top', [urlStructureItem('5', 'top')]))
        ->toBe(['post_id' => '5', 'post_name' => 'top', 'post_type' => 'page']);
});

it('finds nothing for a slug holding an ampersand', function (): void {
    // Characterization of #160: the slug is entity-escaped inside the query, so
    // the query compares against "a&amp;b" and never matches the stored "a&b"
    expect(urlStructureFind('https://example.com/site/a&b', [urlStructureItem('5', 'a&amp;b')]))->toBeNull();
});

it('finds nothing, with a warning, for a slug holding an apostrophe', function (): void {
    // Characterization of #160: the apostrophe ends the query's string literal
    $result = urlStructureFindWithWarnings("https://example.com/site/o'brien", [urlStructureItem('5', "o'brien")]);

    expect($result['found'])->toBeNull()
        ->and($result['warnings'])->not->toBe([]);
});

it('finds nothing, with a warning, for a child whose parent ID holds an apostrophe', function (): void {
    // Characterization of #160: the parent ID is not escaped inside the query
    $result = urlStructureFindWithWarnings('https://example.com/site/parent/team', [urlStructureItem('3', 'parent'), urlStructureItem('8', 'team', "3'")]);

    expect($result['found'])->toBeNull()
        ->and($result['warnings'])->not->toBe([]);
});

it('skips a candidate that does not fit and goes on to the next', function (array $items): void {
    expect(urlStructureFind('https://example.com/site/parent/team', urlStructureStrings($items)))
        ->toBe(['post_id' => '8', 'post_name' => 'team', 'post_type' => 'page']);
})->with([
    'a page at the root first' => [[urlStructureItem('6', 'team'), urlStructureItem('3', 'parent'), urlStructureItem('8', 'team', '3')]],
    'a page whose parent is missing first' => [[urlStructureItem('6', 'team', '99'), urlStructureItem('3', 'parent'), urlStructureItem('8', 'team', '3')]],
    'a page whose parent has another slug first' => [[urlStructureItem('4', 'other'), urlStructureItem('6', 'team', '4'), urlStructureItem('3', 'parent'), urlStructureItem('8', 'team', '3')]],
    'a page whose parent is not at the root first' => [[urlStructureItem('4', 'parent', '1'), urlStructureItem('6', 'team', '4'), urlStructureItem('3', 'parent'), urlStructureItem('8', 'team', '3')]],
]);

it('does not take a root page for the child of an item whose ID is 0', function (): void {
    expect(urlStructureFind('https://example.com/site/parent/team', [urlStructureItem('0', 'parent'), urlStructureItem('8', 'team')]))->toBeNull();
});

it('finds nothing by date for a post with no date, whatever the date in the URL', function (): void {
    // A post with no date is skipped rather than read as today
    $url = 'https://example.com/site/'.date('Y/m/d').'/news';

    expect(urlStructureFind($url, [urlStructureItem('7', 'news', type: 'post')]))->toBeNull();
});

it('finds nothing by date for a post on another day, month or year', function (string $date): void {
    expect(urlStructureFind('https://example.com/site/2024/01/02/news', [urlStructureItem('7', 'news', type: 'post', fields: ['wp:post_date' => $date])]))->toBeNull();
})->with([
    'another day' => ['2024-01-03 09:00:00'],
    'another month' => ['2024-02-02 09:00:00'],
    'another year' => ['2023-01-02 09:00:00'],
]);

it('finds nothing for a post matched by its category that has no post ID', function (): void {
    $item = '<item><wp:post_id></wp:post_id><wp:post_name>news</wp:post_name><wp:post_parent>0</wp:post_parent><wp:post_type>post</wp:post_type><wp:status>draft</wp:status>'
        .'<category domain="category" nicename="research"><![CDATA[research]]></category></item>';

    expect(urlStructureFind('https://example.com/site/research/news', [$item]))->toBeNull();
});
