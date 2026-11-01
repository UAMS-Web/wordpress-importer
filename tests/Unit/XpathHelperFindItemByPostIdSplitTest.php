<?php

declare(strict_types=1);

/**
 * Tests that findItemByPostId resolves an item living in any part of a split
 * export from a single part path — the cross-part reference case (wordpress-importer-archive#365).
 *
 * @command  php vendor/bin/pest tests/Unit/XpathHelperFindItemByPostIdSplitTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;

beforeEach(function (): void {
    // Create an isolated scratch directory for this test's part fixtures
    $this->workingDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('wxr_finditem_', true);
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
 * Write a WXR part carrying items with the given ids and post types.
 *
 * @param  string  $directory  The scratch directory to write the part into
 * @param  string  $name  The part filename (e.g. 'news.000.xml')
 * @param  list<array{id: int, type: string}>  $items  Items for this part
 * @return string The absolute path to the written part
 */
function writeFindItemPart(
    string $directory,
    string $name,
    array $items
): string {
    // Render each item node for this part
    $itemXml = '';

    foreach ($items as $item) {
        $itemXml .= sprintf(
            "\n<item><title>Item %1\$d</title><wp:post_id>%1\$d</wp:post_id>".
            '<wp:post_type>%2$s</wp:post_type><wp:status>publish</wp:status></item>',
            $item['id'],
            $item['type']
        );
    }

    // Assemble the standalone WXR document for this part
    $document = sprintf(
        '<?xml version="1.0" encoding="UTF-8"?>'."\n".
        '<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">'."\n".
        '<channel>%s'."\n".'</channel>'."\n".'</rss>',
        $itemXml
    );

    // Write the assembled part into the scratch directory
    $path = $directory.DIRECTORY_SEPARATOR.$name;
    file_put_contents(
        $path,
        $document
    );

    return $path;
}

it('finds an item in a later part from the first part path', function (): void {
    // Part 0 holds item 101; part 1 holds the target attachment 202
    $part0 = writeFindItemPart(
        $this->workingDirectory,
        'news.000.xml',
        [['id' => 101, 'type' => 'post']]
    );
    writeFindItemPart(
        $this->workingDirectory,
        'news.001.xml',
        [['id' => 202, 'type' => 'attachment']]
    );

    // Search using only the first part path — siblings are auto-discovered
    $item = XpathHelper::findItemByPostId(
        $part0,
        '202',
        'attachment'
    );

    expect($item)->not->toBeNull();

    // Narrow away the nullable before reading fields back through XpathHelper
    $postId = $item !== null ? XpathHelper::getStringValue($item, 'wp:post_id') : null;
    $postType = $item !== null ? XpathHelper::getStringValue($item, 'wp:post_type') : null;

    expect($postId)->toBe('202')
        ->and($postType)->toBe('attachment');
});

it('returns null when no part contains the id', function (): void {
    $part0 = writeFindItemPart(
        $this->workingDirectory,
        'news.000.xml',
        [['id' => 101, 'type' => 'post']]
    );
    writeFindItemPart(
        $this->workingDirectory,
        'news.001.xml',
        [['id' => 102, 'type' => 'post']]
    );

    $item = XpathHelper::findItemByPostId(
        $part0,
        '999'
    );

    expect($item)->toBeNull();
});

it('resolves an item from a single-file export unchanged', function (): void {
    $only = writeFindItemPart(
        $this->workingDirectory,
        'single.xml',
        [['id' => 500, 'type' => 'page']]
    );

    $item = XpathHelper::findItemByPostId(
        $only,
        '500',
        'page'
    );

    expect($item)->not->toBeNull();

    // Narrow away the nullable before reading the field back through XpathHelper
    $postId = $item !== null ? XpathHelper::getStringValue($item, 'wp:post_id') : null;

    expect($postId)->toBe('500');
});
