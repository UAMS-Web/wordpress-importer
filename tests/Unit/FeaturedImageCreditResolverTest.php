<?php

declare(strict_types=1);

/**
 * Tests the gate that decides whether an imported entry gets
 * `featured_image_credit_query = true`.
 *
 * The toggle must follow the ASSET, not the postmeta: a credit that the import
 * pipeline strips or drops before it reaches the asset has to leave the toggle
 * on the blueprint's `false` default, or the entry ships an inert toggle. wordpress-importer-archive#439
 *
 * @command  php vendor/bin/pest tests/Unit/FeaturedImageCreditResolverTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators\PostEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\FeaturedImageCreditResolver;

/**
 * Build a WXR attachment `<item>` carrying the given postmeta fragments.
 *
 * @param  string  $postId  The attachment's WordPress post id
 * @param  string  $innerXml  Additional `<item>` body XML (postmeta, etc.)
 */
function creditAttachment(string $postId, string $innerXml = ''): SimpleXMLElement
{
    $xml = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<item xmlns:wp="http://wordpress.org/export/1.2/" '
        .'xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/">'
        .'<title><![CDATA[An Image]]></title>'
        .'<link>https://example.uams.edu/an-image/</link>'
        ."<wp:post_id>{$postId}</wp:post_id>"
        .'<wp:attachment_url><![CDATA[https://example.uams.edu/an-image.jpg]]></wp:attachment_url>'
        .$innerXml
        .'</item>';

    $item = simplexml_load_string($xml);

    if (! $item instanceof SimpleXMLElement) {
        throw new RuntimeException('Failed to parse attachment <item> fixture.');
    }

    return $item;
}

/**
 * Build a `<wp:postmeta>` fragment.
 */
function creditPostmeta(string $key, string $value): string
{
    return '<wp:postmeta>'
        ."<wp:meta_key><![CDATA[{$key}]]></wp:meta_key>"
        ."<wp:meta_value><![CDATA[{$value}]]></wp:meta_value>"
        .'</wp:postmeta>';
}

/**
 * Serialize attachment metadata carrying an EXIF/IPTC credit.
 */
function creditExifMeta(string $credit): string
{
    return \serialize([
        'width' => 1200,
        'height' => 675,
        'image_meta' => ['credit' => $credit],
    ]);
}

it('reports a credit from the structured media-credit postmeta', function (): void {
    $attachments = [creditAttachment('99', creditPostmeta('_media_credit', 'Bryan Clifton'))];

    expect(FeaturedImageCreditResolver::hasCredit('99', $attachments))->toBeTrue();
    expect(FeaturedImageCreditResolver::resolve('99', $attachments))->toBe('Bryan Clifton');
})->group('featured-image-credit');

it('falls back to the EXIF credit the asset would receive', function (): void {
    // `_media_credit` absent, EXIF present — the same precedence `AttachmentCreditResolver`
    // applies when writing the asset's `creator`. wordpress-importer-archive#436
    $attachments = [
        creditAttachment('99', creditPostmeta('_wp_attachment_metadata', creditExifMeta('Getty Images'))),
    ];

    expect(FeaturedImageCreditResolver::hasCredit('99', $attachments))->toBeTrue();
    expect(FeaturedImageCreditResolver::resolve('99', $attachments))->toBe('Getty Images');
})->group('featured-image-credit');

it('reports no credit for an uncredited featured image', function (): void {
    // The toggle stays on the blueprint default rather than shipping inert. wordpress-importer-archive#439
    $attachments = [creditAttachment('99')];

    expect(FeaturedImageCreditResolver::hasCredit('99', $attachments))->toBeFalse();
    expect(FeaturedImageCreditResolver::resolve('99', $attachments))->toBe('');
})->group('featured-image-credit');

it('treats a credit that never reaches the asset as no credit', function (string $credit): void {
    // The import pipeline strips HTML and drops what is left blank, so the
    // postmeta being non-empty is not the same question as the asset carrying a
    // `creator` — and it is the asset the toggle has to follow. wordpress-importer-archive#439
    $attachments = [creditAttachment('99', creditPostmeta('_media_credit', $credit))];

    expect(FeaturedImageCreditResolver::hasCredit('99', $attachments))->toBeFalse();
})->with([
    'whitespace only' => ['   '],
    'empty markup' => ['<em></em>'],
    'nested empty markup' => ['<p><span></span></p>'],
])->group('featured-image-credit');

it('keeps the text of a credit wrapped in markup', function (): void {
    // A real credit inside markup still reaches the asset, stripped — so the
    // toggle belongs on.
    $attachments = [creditAttachment('99', creditPostmeta('_media_credit', '<em>Bryan Clifton</em>'))];

    expect(FeaturedImageCreditResolver::hasCredit('99', $attachments))->toBeTrue();
    expect(FeaturedImageCreditResolver::resolve('99', $attachments))->toBe('Bryan Clifton');
})->group('featured-image-credit');

it('reports no credit when the thumbnail id matches no exported attachment', function (): void {
    $attachments = [creditAttachment('99', creditPostmeta('_media_credit', 'Bryan Clifton'))];

    expect(FeaturedImageCreditResolver::hasCredit('12345', $attachments))->toBeFalse();
})->group('featured-image-credit');

it('reports no credit for an absent or blank thumbnail id', function (string $thumbnailId): void {
    $attachments = [creditAttachment('99', creditPostmeta('_media_credit', 'Bryan Clifton'))];

    expect(FeaturedImageCreditResolver::hasCredit($thumbnailId, $attachments))->toBeFalse();
})->with([
    'empty' => [''],
    'whitespace' => ['   '],
])->group('featured-image-credit');

it('does not match a blank thumbnail id to an attachment with a blank post id', function (string $thumbnailId): void {
    // XpathHelper::findAttachmentById() compares the raw `wp:post_id`, and an
    // absent node reads as ''. Without the blank-id guard, a blank thumbnail id
    // would match such an attachment and report its credit.
    $postId = $thumbnailId === '' ? '' : "<wp:post_id>{$thumbnailId}</wp:post_id>";
    $xml = '<item xmlns:wp="http://wordpress.org/export/1.2/">'
        .$postId
        .creditPostmeta('_media_credit', 'Bryan Clifton')
        .'</item>';

    $attachment = simplexml_load_string($xml);

    if (! $attachment instanceof SimpleXMLElement) {
        throw new RuntimeException('Failed to parse attachment <item> fixture.');
    }

    expect(FeaturedImageCreditResolver::resolve($thumbnailId, [$attachment]))->toBe('');
})->with([
    'empty id, no post id node' => [''],
    'whitespace id, whitespace post id' => ['   '],
])->group('featured-image-credit');

it('reads the credit of the matching attachment, not a neighbour', function (): void {
    // A post's export carries every attachment on the site, so picking the wrong
    // one would set the toggle from an unrelated image's credit.
    $attachments = [
        creditAttachment('10', creditPostmeta('_media_credit', 'Someone Else')),
        creditAttachment('99'),
        creditAttachment('30', creditPostmeta('_media_credit', 'Another Person')),
    ];

    expect(FeaturedImageCreditResolver::hasCredit('99', $attachments))->toBeFalse();
    expect(FeaturedImageCreditResolver::resolve('10', $attachments))->toBe('Someone Else');
})->group('featured-image-credit');

it('survives an attachment list holding non-XML entries', function (): void {
    $attachments = [
        'not an element',
        null,
        creditAttachment('99', creditPostmeta('_media_credit', 'Bryan Clifton')),
    ];

    expect(FeaturedImageCreditResolver::hasCredit('99', $attachments))->toBeTrue();
})->group('featured-image-credit');

it('reports no credit for an empty attachment list', function (): void {
    expect(FeaturedImageCreditResolver::hasCredit('99', []))->toBeFalse();
})->group('featured-image-credit');

it('turns the entry toggle on only for a credited image that actually imported', function (
    ?string $featuredImagePath,
    bool $credited,
    bool $expected
): void {
    // The resolver answers "does this attachment carry a credit?"; this is the
    // entry-level decision on top of it, which additionally requires the featured
    // image to have imported at all. Both halves have to hold. wordpress-importer-archive#439
    $attachments = [
        creditAttachment(
            '99',
            $credited ? creditPostmeta('_media_credit', 'Bryan Clifton') : ''
        ),
    ];

    $decision = (new PostEntryCreator)->shouldShowFeaturedImageCredit(
        $featuredImagePath,
        '99',
        $attachments
    );

    expect($decision)->toBe($expected);
})->with([
    'imported and credited' => ['default/2020-05-photo.jpg', true, true],
    'imported but uncredited' => ['default/2020-05-photo.jpg', false, false],
    'credited but the image failed to import' => [null, true, false],
    'credited but the path came back empty' => ['', true, false],
    'neither' => [null, false, false],
])->group('featured-image-credit');
