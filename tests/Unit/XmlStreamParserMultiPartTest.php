<?php

declare(strict_types=1);

/**
 * Tests XmlStreamParser over multiple parts: items stream in part order, and a
 * single string path still behaves as a one-part stream (wordpress-importer-archive#365).
 *
 * @command  php vendor/bin/pest tests/Unit/XmlStreamParserMultiPartTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlStreamParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;

beforeEach(function (): void {
    // Create an isolated scratch directory for this test's part fixtures
    $this->workingDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('wxr_stream_', true);
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

/**
 * Write a minimal WXR part carrying a single post with the given id.
 *
 * @param  string  $workingDirectory  The scratch directory to write the part into
 * @param  string  $name  The part filename
 * @param  int  $postId  The WordPress post id for this part's lone item
 * @return string The absolute path to the written part
 */
function writePostPart(
    string $workingDirectory,
    string $name,
    int $postId
): string {
    // Assemble a minimal standalone WXR document with one post item
    $document = sprintf(
        '<?xml version="1.0" encoding="UTF-8"?>'."\n".
        '<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">'."\n".
        '<channel><item><title>Post %1$d</title><wp:post_id>%1$d</wp:post_id>'.
        '<wp:post_type>post</wp:post_type><wp:status>publish</wp:status></item></channel>'."\n".
        '</rss>',
        $postId
    );

    // Write the part into the scratch directory
    $path = $workingDirectory.DIRECTORY_SEPARATOR.$name;
    file_put_contents(
        $path,
        $document
    );

    return $path;
}

it('iterates parts in the given order, concatenating their items', function (): void {
    $part0 = writePostPart($this->workingDirectory, 'news.000.xml', 101);
    $part1 = writePostPart($this->workingDirectory, 'news.001.xml', 102);
    $part2 = writePostPart($this->workingDirectory, 'news.002.xml', 103);

    $streamParser = new XmlStreamParser([$part0, $part1, $part2]);

    // Collect streamed post ids in the exact order yielded
    $postIds = [];

    foreach ($streamParser->streamItems('post') as $post) {
        $postIds[] = XpathHelper::getStringValue($post, 'wp:post_id');
    }

    expect($postIds)->toBe(['101', '102', '103']);
});

it('constructs a one-part stream from a single string path (backward compatible)', function (): void {
    $only = writePostPart($this->workingDirectory, 'single.xml', 500);

    $streamParser = new XmlStreamParser($only);

    // Collect streamed post ids from the lone part
    $postIds = [];

    foreach ($streamParser->streamAllItems() as $item) {
        $postIds[] = XpathHelper::getStringValue($item, 'wp:post_id');
    }

    expect($postIds)->toBe(['500'])
        ->and($streamParser->getFilePaths())->toBe([$only])
        ->and($streamParser->getFilePath())->toBe($only);
});
