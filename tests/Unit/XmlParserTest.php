<?php

declare(strict_types=1);

use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;

/**
 * Tests for the parts of `XmlParser` the term and split-export tests leave
 * open: content-type counting, the loaded-XML item and attachment paths, tags
 * and categories, the setters, and every branch that returns nothing.
 *
 * @command  php vendor/bin/pest tests/Unit/XmlParserTest.php
 */
beforeEach(function (): void {
    // A self-contained export: two published pages, a trashed page, a virtual
    // post, a published post, two attachments (one trashed), one tag and one
    // category. Inlined so nothing depends on the private Exports corpus.
    $fixture = <<<'XML'
    <?xml version="1.0" encoding="UTF-8"?>
    <rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/" xmlns:content="http://purl.org/rss/1.0/modules/content/">
        <channel>
            <wp:base_blog_url>https://example.uams.edu</wp:base_blog_url>
            <wp:category>
                <wp:term_id>3</wp:term_id>
                <wp:category_nicename>news</wp:category_nicename>
            </wp:category>
            <wp:tag>
                <wp:term_id>4</wp:term_id>
                <wp:tag_slug>research</wp:tag_slug>
            </wp:tag>
            <item><title>Page A</title><wp:post_type>page</wp:post_type><wp:status>publish</wp:status></item>
            <item><title>Page B</title><wp:post_type>page</wp:post_type><wp:status>draft</wp:status></item>
            <item><title>Page C</title><wp:post_type>page</wp:post_type><wp:status>trash</wp:status></item>
            <item><title>Post A</title><wp:post_type>post</wp:post_type><wp:status>virtual</wp:status></item>
            <item><title>Post B</title><wp:post_type>post</wp:post_type><wp:status>publish</wp:status></item>
            <item><title>File A</title><wp:post_type>attachment</wp:post_type><wp:status>inherit</wp:status></item>
            <item><title>File B</title><wp:post_type>attachment</wp:post_type><wp:status>trash</wp:status></item>
        </channel>
    </rss>
    XML;

    $this->xmlPath = (string) tempnam(sys_get_temp_dir(), 'wxr_parser_');

    file_put_contents($this->xmlPath, $fixture);
});

afterEach(function (): void {
    if (isset($this->xmlPath) && is_file($this->xmlPath)) {
        unlink($this->xmlPath);
    }
});

/**
 * The titles of a list of item nodes, in order.
 *
 * @param  array<int|string, mixed>  $items
 * @return list<string>
 */
function xmlParserTitles(array $items): array
{
    $titles = [];

    foreach ($items as $item) {
        expect($item)->toBeInstanceOf(SimpleXMLElement::class);

        if ($item instanceof SimpleXMLElement) {
            $titles[] = (string) $item->title;
        }
    }

    return $titles;
}

/**
 * Run a call, collecting the PHP warnings it raises instead of reporting them.
 *
 * @return array{0: mixed, 1: list<string>} The call's result and each warning message
 */
function xmlParserCollectingWarnings(callable $call): array
{
    $warnings = [];

    set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
        $warnings[] = $message;

        return true;
    }, E_WARNING);

    try {
        return [$call(), $warnings];
    } finally {
        restore_error_handler();
    }
}

it('counts each content type, sorted, leaving out trashed and virtual items', function (): void {
    $xmlParser = new XmlParser;
    $xmlParser->parse($this->xmlPath);

    expect($xmlParser->getContentTypes())->toBe([
        ['attachment', 1],
        ['page', 2],
        ['post', 1],
    ]);
});

it('starts each parse from a fresh count', function (): void {
    $xmlParser = new XmlParser;
    $xmlParser->parse($this->xmlPath);
    $xmlParser->parse($this->xmlPath);

    expect($xmlParser->getContentTypes())->toBe([
        ['attachment', 1],
        ['page', 2],
        ['post', 1],
    ]);
});

it('throws when the file is not well-formed XML', function (): void {
    file_put_contents($this->xmlPath, '<rss><channel>');

    $xmlParser = new XmlParser;

    [$thrown, $warnings] = xmlParserCollectingWarnings(function () use ($xmlParser): ?Throwable {
        try {
            $xmlParser->parse($this->xmlPath);
        } catch (Throwable $throwable) {
            return $throwable;
        }

        return null;
    });

    if (! $thrown instanceof Exception) {
        throw new RuntimeException('Expected parse() to throw.');
    }

    expect($thrown->getMessage())->toBe('Failed to parse XML file.')
        ->and($warnings)->not->toBe([]);
});

it('reads items of one type from the loaded XML, leaving out trashed ones', function (): void {
    $xmlParser = new XmlParser;
    $xmlParser->parse($this->xmlPath);

    expect(xmlParserTitles($xmlParser->getItems('page')))->toBe(['Page A', 'Page B'])
        ->and(xmlParserTitles($xmlParser->getItems('post')))->toBe(['Post B']);
});

it('returns no items when the content type breaks the XPath query', function (): void {
    $xmlParser = new XmlParser;
    $xmlParser->parse($this->xmlPath);

    [$items, $warnings] = xmlParserCollectingWarnings(fn (): array => $xmlParser->getItems("pa'ge"));

    expect($items)->toBe([])
        ->and($warnings)->not->toBe([]);
});

it('returns no items when nothing was parsed and no path is set', function (): void {
    expect((new XmlParser)->getItems('page'))->toBe([]);
});

it('streams items when only a path is set', function (): void {
    $xmlParser = new XmlParser;
    $xmlParser->setFilePath($this->xmlPath);

    expect(xmlParserTitles($xmlParser->getItems('page')))->toBe(['Page A', 'Page B']);
});

it('returns the override items, keeping only XML nodes, until it is cleared', function (): void {
    $xmlParser = new XmlParser;
    $xmlParser->parse($this->xmlPath);

    $override = new SimpleXMLElement('<item><title>Override</title></item>');
    $xmlParser->setItems(['not a node', $override, 7]);

    expect(xmlParserTitles($xmlParser->getItems('page')))->toBe(['Override'])
        ->and(xmlParserTitles($xmlParser->getItems('post')))->toBe(['Override']);

    $xmlParser->setItems(null);

    expect(xmlParserTitles($xmlParser->getItems('page')))->toBe(['Page A', 'Page B']);
});

it('reads attachments from the loaded XML and caches them', function (): void {
    $xmlParser = new XmlParser;
    $xmlParser->parse($this->xmlPath);

    $first = $xmlParser->getAttachments();

    // A second read must come from the cache, so it survives the file going away
    unlink($this->xmlPath);

    expect(xmlParserTitles($first))->toBe(['File A'])
        ->and($xmlParser->getAttachments())->toBe($first);
});

it('streams attachments when only a path is set, and caches them', function (): void {
    $xmlParser = new XmlParser;
    $xmlParser->setFilePath($this->xmlPath);

    $first = $xmlParser->getAttachments();

    unlink($this->xmlPath);

    expect(xmlParserTitles($first))->toBe(['File A'])
        ->and($xmlParser->getAttachments())->toBe($first);
});

it('returns no attachments when nothing was parsed and no path is set', function (): void {
    expect((new XmlParser)->getAttachments())->toBe([]);
});

it('reads the tags and categories of the first part', function (): void {
    $xmlParser = new XmlParser;
    $xmlParser->setFilePath($this->xmlPath);

    $tags = $xmlParser->getTags();
    $categories = $xmlParser->getCategories();

    expect($tags)->toHaveCount(1)
        ->and($categories)->toHaveCount(1);

    $tag = $tags[0];
    $category = $categories[0];

    if (! $tag instanceof SimpleXMLElement || ! $category instanceof SimpleXMLElement) {
        throw new RuntimeException('Expected tag and category nodes.');
    }

    XpathHelper::registerWordPressNamespaces($tag);
    XpathHelper::registerWordPressNamespaces($category);

    expect(XpathHelper::getStringValue($tag, 'wp:tag_slug'))->toBe('research')
        ->and(XpathHelper::getStringValue($category, 'wp:category_nicename'))->toBe('news');
});

it('returns no terms, tags or categories without a path', function (): void {
    $xmlParser = new XmlParser;

    expect($xmlParser->getTerms())->toBe([])
        ->and($xmlParser->getTags())->toBe([])
        ->and($xmlParser->getCategories())->toBe([]);
});

it('returns no terms, tags or categories when the file cannot be opened', function (): void {
    $xmlParser = new XmlParser;
    $xmlParser->setFilePath($this->xmlPath.'.missing');

    $reads = [
        $xmlParser->getTerms(...),
        $xmlParser->getTags(...),
        $xmlParser->getCategories(...),
    ];

    foreach ($reads as $read) {
        [$result, $warnings] = xmlParserCollectingWarnings($read);

        expect($result)->toBe([])
            ->and($warnings)->not->toBe([]);
    }
});

it('exposes the parsed XML, and refuses before a parse', function (): void {
    $xmlParser = new XmlParser;

    expect(fn () => $xmlParser->getXml())
        ->toThrow(RuntimeException::class, 'XML has not been parsed yet.')
        ->and(fn () => $xmlParser->getBaseBlogUrl())
        ->toThrow(RuntimeException::class, 'XML has not been parsed yet.');

    $xmlParser->parse($this->xmlPath);

    expect((string) $xmlParser->getXml()->channel->item[0]->title)->toBe('Page A')
        ->and($xmlParser->getBaseBlogUrl())->toBe('https://example.uams.edu');
});

it('prefers a base blog URL that was set over the one in the XML', function (): void {
    $xmlParser = new XmlParser;
    $xmlParser->setBaseBlogUrl('https://override.uams.edu');

    expect($xmlParser->getBaseBlogUrl())->toBe('https://override.uams.edu');

    $xmlParser->parse($this->xmlPath);

    expect($xmlParser->getBaseBlogUrl())->toBe('https://override.uams.edu');
});

it('drops a content type whose key is not a string or whose count is not numeric', function (): void {
    $xmlParser = new XmlParser;
    // The rejected entries lead, so a loop that stopped at the first one
    // would keep nothing
    $xmlParser->setContentTypes([
        'bad' => 'many',
        5 => 4,
        'page' => '3',
        'post' => 2,
        'news' => 1.9,
    ]);

    expect($xmlParser->getContentTypes())->toBe([
        ['news', 1],
        ['page', 3],
        ['post', 2],
    ]);
});

it('keeps only string paths, re-indexed, when part paths are set', function (): void {
    $xmlParser = new XmlParser;
    $xmlParser->setFilePaths([3 => 'b.xml', 'x' => null, 9 => 'c.xml', 4 => 7]);

    expect($xmlParser->getFilePaths())->toBe(['b.xml', 'c.xml'])
        ->and($xmlParser->getFilePath())->toBe('b.xml');
});

it('reports no file path before one is set', function (): void {
    $xmlParser = new XmlParser;

    expect($xmlParser->getFilePath())->toBeNull()
        ->and($xmlParser->getFilePaths())->toBe([]);
});

it('reads WordPress elements bound to a prefix other than wp', function (): void {
    // The parser registers the `wp` prefix on the export URI, so an export
    // that binds that URI to another prefix still answers `wp:` queries.
    file_put_contents($this->xmlPath, <<<'XML'
    <?xml version="1.0" encoding="UTF-8"?>
    <rss version="2.0" xmlns:wpx="http://wordpress.org/export/1.2/">
        <channel>
            <item><title>Page A</title><wpx:post_type>page</wpx:post_type><wpx:status>publish</wpx:status></item>
            <item><title>Page B</title><wpx:post_type>page</wpx:post_type><wpx:status>trash</wpx:status></item>
        </channel>
    </rss>
    XML);

    $xmlParser = new XmlParser;
    $xmlParser->parse($this->xmlPath);

    expect($xmlParser->getContentTypes())->toBe([['page', 1]])
        ->and(xmlParserTitles($xmlParser->getItems('page')))->toBe(['Page A']);
});

it('reads attachments from the loaded XML of a single-file export, not from the file', function (): void {
    $xmlParser = new XmlParser;
    $xmlParser->parse($this->xmlPath);

    // With the file gone, only the loaded XML can still answer
    unlink($this->xmlPath);

    expect(xmlParserTitles($xmlParser->getAttachments()))->toBe(['File A']);
});

it('streams attachments from every part when one part of a split export was loaded', function (): void {
    $second = (string) tempnam(sys_get_temp_dir(), 'wxr_parser_');
    file_put_contents($second, <<<'XML'
    <?xml version="1.0" encoding="UTF-8"?>
    <rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">
        <channel>
            <item><title>File C</title><wp:post_type>attachment</wp:post_type><wp:status>inherit</wp:status></item>
        </channel>
    </rss>
    XML);

    try {
        $xmlParser = new XmlParser;
        $xmlParser->parse($this->xmlPath);
        $xmlParser->setFilePaths([$this->xmlPath, $second]);

        expect(xmlParserTitles($xmlParser->getAttachments()))->toBe(['File A', 'File C']);
    } finally {
        unlink($second);
    }
});

it('takes an unnamespaced term carrying any one WordPress term field', function (): void {
    file_put_contents($this->xmlPath, <<<'XML'
    <?xml version="1.0" encoding="UTF-8"?>
    <rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">
        <channel>
            <term><wp:term_id>1</wp:term_id></term>
            <term><wp:term_taxonomy>category</wp:term_taxonomy></term>
            <term><wp:term_slug>news</wp:term_slug></term>
            <term><name>Not a WordPress term</name></term>
        </channel>
    </rss>
    XML);

    $xmlParser = new XmlParser;
    $xmlParser->setFilePath($this->xmlPath);

    $terms = $xmlParser->getTerms();

    expect($terms)->toHaveCount(3);

    $fields = [];

    foreach ($terms as $term) {
        if (! $term instanceof SimpleXMLElement) {
            throw new RuntimeException('Expected term nodes.');
        }

        $fields[] = array_keys((array) $term->children('wp', true));
    }

    expect($fields)->toBe([['term_id'], ['term_taxonomy'], ['term_slug']]);
});
