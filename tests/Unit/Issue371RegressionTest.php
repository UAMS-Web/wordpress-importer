<?php

declare(strict_types=1);

/**
 * Regression test for issue wordpress-importer-archive#371.
 *
 * The comment-node value passed to parseNode() must be trimmed before the
 * ^-anchored closing-tag regex (/^\/wp:acf\//) is applied. WordPress WXR
 * exports frequently emit ACF block comments padded with surrounding
 * whitespace (e.g. "<!-- /wp:acf/uams-section -->"), so the raw nodeValue is
 * " /wp:acf/uams-section ". Without trimming, the leading space prevents the
 * ^-anchored regex from matching and the closing tag is no longer skipped via
 * the early return, regressing paired-block handling.
 *
 * @command  php vendor/bin/pest tests/Unit/Issue371RegressionTest.php
 */

use Symfony\Component\Console\Output\NullOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\PageBuilder\AcfBlockParser;

/**
 * Build a comment node carrying $text inside a wrapper element, returning the
 * comment plus the wrapper's (non-null) child node list.
 *
 * @return array{0: DOMComment, 1: DOMNodeList<DOMNode>}
 */
function acfCommentNode(string $text): array
{
    $doc = new DOMDocument;
    $wrapper = $doc->createElement('div');
    $doc->appendChild($wrapper);
    $comment = $doc->createComment($text);
    $wrapper->appendChild($comment);

    // Access childNodes on the DOMElement directly so the list type is non-null.
    return [$comment, $wrapper->childNodes];
}

it('skips an ACF closing-tag comment via the early return, regardless of surrounding whitespace', function (string $commentText): void {
    $parser = new AcfBlockParser;

    // Mock the AssetImporter to avoid its config()-dependent constructor; the
    // closing-tag branch returns before any AssetImporter method is used.
    $assetImporter = $this::createStub(AssetImporter::class);

    [$comment, $nodes] = acfCommentNode($commentText);

    $result = $parser->parseNode(
        $comment,
        0,
        $nodes,
        'default',
        $assetImporter,
        new NullOutput,
        [],
        [],
        [],
        'some-slug'
    );

    expect($result['processed'])->toBeFalse()
        ->and($result['skipCount'])->toBe(0)
        ->and($result['set'])->toBeNull();
})->with([
    'whitespace-padded' => [' /wp:acf/uams-section '],
    'unpadded' => ['/wp:acf/uams-section'],
]);
