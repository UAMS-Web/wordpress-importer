<?php

declare(strict_types=1);

/**
 * Tests XmlParser over a split export: items concatenate across parts, cross-part
 * attachments resolve, and channel metadata is read once — not multiplied (wordpress-importer-archive#365).
 *
 * @command  php vendor/bin/pest tests/Unit/XmlParserSplitExportTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;

/**
 * Write a WXR part whose channel metadata is fixed (duplicated across parts) and
 * whose <item> nodes are the supplied partition slice.
 *
 * @param  string  $workingDirectory  Absolute path to the scratch directory holding the part fixtures
 * @param  string  $name  The part filename (e.g. 'news.000.xml')
 * @param  list<array{type: string, id: int, title: string}>  $items  Item slice for this part
 * @return string The absolute path to the written part
 */
function splitExportWritePart(
    string $workingDirectory,
    string $name,
    array $items
): string {
    // Build the duplicated channel metadata shared by every part
    $channelMeta = <<<'XML'
<wp:base_blog_url>https://uams-news.test</wp:base_blog_url>
<wp:author><wp:author_id>2</wp:author_id><wp:author_login>todd</wp:author_login></wp:author>
<wp:category><wp:term_id>10</wp:term_id><wp:cat_name><![CDATA[News]]></wp:cat_name><wp:category_nicename>news</wp:category_nicename></wp:category>
<wp:category><wp:term_id>11</wp:term_id><wp:cat_name><![CDATA[Research]]></wp:cat_name><wp:category_nicename>research</wp:category_nicename></wp:category>
<wp:tag><wp:term_id>20</wp:term_id><wp:tag_slug>flu</wp:tag_slug><wp:tag_name><![CDATA[Flu]]></wp:tag_name></wp:tag>
<wp:tag><wp:term_id>21</wp:term_id><wp:tag_slug>heart</wp:tag_slug><wp:tag_name><![CDATA[Heart]]></wp:tag_name></wp:tag>
<wp:tag><wp:term_id>22</wp:term_id><wp:tag_slug>brain</wp:tag_slug><wp:tag_name><![CDATA[Brain]]></wp:tag_name></wp:tag>
<wp:term><wp:term_id>30</wp:term_id><wp:term_taxonomy>category</wp:term_taxonomy><wp:term_slug>news</wp:term_slug><wp:term_name><![CDATA[News]]></wp:term_name></wp:term>
XML;

    // Render each item node for this part's partition slice
    $itemXml = '';

    foreach ($items as $item) {
        $itemXml .= sprintf(
            "\n<item><title>%s</title><wp:post_id>%d</wp:post_id><wp:post_type>%s</wp:post_type><wp:status>publish</wp:status></item>",
            $item['title'],
            $item['id'],
            $item['type']
        );
    }

    // Assemble the full standalone WXR document for this part
    $document = sprintf(
        '<?xml version="1.0" encoding="UTF-8"?>'."\n".
        '<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wp="http://wordpress.org/export/1.2/">'."\n".
        '<channel>'."\n".'%s%s'."\n".'</channel>'."\n".'</rss>',
        $channelMeta,
        $itemXml
    );

    // Write the assembled part to the scratch directory
    $path = $workingDirectory.DIRECTORY_SEPARATOR.$name;
    file_put_contents(
        $path,
        $document
    );

    return $path;
}

/**
 * Build a two-part split export whose posts and attachments are partitioned.
 *
 * @param  string  $workingDirectory  Absolute path to the scratch directory holding the part fixtures
 * @return XmlParser A parser wired to both parts via setFilePaths()
 */
function makeSplitExportTwoPartParser(string $workingDirectory): XmlParser
{
    // Part 0: post 101 + attachment 201
    $part0 = splitExportWritePart(
        $workingDirectory,
        'news.000.xml',
        [
            ['type' => 'post', 'id' => 101, 'title' => 'Post A'],
            ['type' => 'attachment', 'id' => 201, 'title' => 'Attachment A'],
        ]
    );

    // Part 1: post 102 + attachment 202
    $part1 = splitExportWritePart(
        $workingDirectory,
        'news.001.xml',
        [
            ['type' => 'post', 'id' => 102, 'title' => 'Post B'],
            ['type' => 'attachment', 'id' => 202, 'title' => 'Attachment B'],
        ]
    );

    // Wire the parser to both parts through the streaming (non-parse) path
    $xmlParser = new XmlParser;
    $xmlParser->setFilePaths([$part0, $part1]);

    return $xmlParser;
}

beforeEach(function (): void {
    // Create an isolated scratch directory for this test's part fixtures
    $this->workingDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('wxr_xmlparser_', true);
    mkdir($this->workingDirectory);
});

afterEach(function (): void {
    // Remove every fixture file, then the scratch directory itself
    $entries = glob($this->workingDirectory.DIRECTORY_SEPARATOR.'*');

    if ($entries === false) {
        $entries = [];
    }

    foreach ($entries as $entry) {
        unlink($entry);
    }

    rmdir($this->workingDirectory);
});

it('concatenates post items across every part, not just the first', function (): void {
    $xmlParser = makeSplitExportTwoPartParser($this->workingDirectory);

    $posts = $xmlParser->getItems('post');

    // Collect the WordPress post IDs streamed from both parts
    $postIds = array_map(
        fn ($post): string => XpathHelper::getStringValue($post, 'wp:post_id'),
        $posts
    );

    expect($posts)->toHaveCount(2)
        ->and($postIds)->toEqualCanonicalizing(['101', '102']);
});

it('resolves attachments from every part (cross-part reference support)', function (): void {
    $xmlParser = makeSplitExportTwoPartParser($this->workingDirectory);

    $attachments = $xmlParser->getAttachments();

    // Collect the attachment post IDs streamed from both parts
    $attachmentIds = array_map(
        fn ($attachment): string => XpathHelper::getStringValue($attachment, 'wp:post_id'),
        $attachments
    );

    expect($attachmentIds)->toEqualCanonicalizing(['201', '202']);
});

it('reads channel metadata once from the first part, not multiplied by parts', function (): void {
    $xmlParser = makeSplitExportTwoPartParser($this->workingDirectory);

    // Two categories exist per part; the deduped read must return two, not four
    expect($xmlParser->getCategories())->toHaveCount(2)
        // Three tags exist per part; the deduped read must return three, not six
        ->and($xmlParser->getTags())->toHaveCount(3)
        // One term exists per part; the deduped read must return one, not two
        ->and($xmlParser->getTerms())->toHaveCount(1);
});

it('exposes the first part via getFilePath() and all parts via getFilePaths()', function (): void {
    $part0 = splitExportWritePart($this->workingDirectory, 'news.000.xml', []);
    $part1 = splitExportWritePart($this->workingDirectory, 'news.001.xml', []);

    $xmlParser = new XmlParser;
    $xmlParser->setFilePaths([$part0, $part1]);

    expect($xmlParser->getFilePath())->toBe($part0)
        ->and($xmlParser->getFilePaths())->toBe([$part0, $part1]);
});

it('streams exactly its own items for a single-file export — behavior unchanged', function (): void {
    $only = splitExportWritePart(
        $this->workingDirectory,
        'single.xml',
        [
            ['type' => 'post', 'id' => 900, 'title' => 'Only Post'],
        ]
    );

    $xmlParser = new XmlParser;
    $xmlParser->setFilePath($only);

    expect($xmlParser->getItems('post'))->toHaveCount(1)
        ->and($xmlParser->getFilePaths())->toBe([$only])
        ->and($xmlParser->getCategories())->toHaveCount(2);
});
