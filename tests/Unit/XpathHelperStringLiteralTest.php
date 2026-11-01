<?php

declare(strict_types=1);

/**
 * Tests the XPath string-literal builder and that findItemByPostId resists XPath
 * injection through a crafted post id — hardening reused by the split-export
 * per-part search (wordpress-importer-archive#365).
 *
 * Converted from PHPUnit to Pest (wordpress-importer-archive#319); mutation of the covered `XpathHelper`
 * paths kills 53/53 (100%) — every killable mutant is dead.
 *
 * @command  php vendor/bin/pest tests/Unit/XpathHelperStringLiteralTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;

it('builds a literal that selects exactly the node whose text equals the value', function (string $value): void {
    // Build a document whose <v> nodes include the target value and a decoy.
    $document = new SimpleXMLElement(
        sprintf('<root><v>%s</v><v>decoy-value</v></root>', $value)
    );

    // Query for the node whose text equals the value via the generated literal.
    $literal = XpathHelper::xpathStringLiteral($value);
    $matches = $document->xpath('//v[text()='.$literal.']');

    expect($matches)->toBeArray()->toHaveCount(1);

    // Narrow the array|false xpath() result before reading the matched node.
    $matches = is_array($matches) ? $matches : [];

    expect((string) ($matches[0] ?? ''))->toBe($value);
})->with([
    // One row per branch of the literal builder (no &, <, or > in any value).
    'no quotes' => ['https://uams-news.test/story'],
    'single quotes only' => ["O'Brien's page"],
    'double quotes only' => ['say "hello"'],
    'both quote styles' => ['it\'s a "mixed" value'],
]);

it('resists XPath injection through a crafted post id', function (): void {
    // A single-part export containing one unrelated post (id 101).
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('wxr_xpathlit_', true).'.xml';
    file_put_contents(
        $path,
        '<?xml version="1.0" encoding="UTF-8"?>'."\n"
        .'<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">'."\n"
        .'<channel><item><title>Post</title><wp:post_id>101</wp:post_id>'
        .'<wp:post_type>post</wp:post_type><wp:status>publish</wp:status></item></channel>'."\n"
        .'</rss>'
    );

    // A classic injection payload must NOT break out to match the unrelated item.
    $item = XpathHelper::findItemByPostId($path, "999' or '1'='1");

    expect($item)->toBeNull();

    unlink($path);
});
