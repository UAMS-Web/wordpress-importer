<?php

declare(strict_types=1);

/**
 * Tests photo-credit resolution from WXR attachment postmeta.
 *
 * Covers the `uams-2020` media-credit precedence: structured `_media_credit`
 * first, then the EXIF/IPTC `image_meta.credit` inside `_wp_attachment_metadata`.
 *
 * @command  php vendor/bin/pest tests/Unit/AttachmentCreditResolverTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\AttachmentCreditResolver;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;

/**
 * Load a minimal WXR attachment `<item>` with namespace prefixes registered.
 *
 * @param  string  $innerXml  XML body for `<item>` (postmeta, etc.), no outer `<item>` wrapper
 * @return SimpleXMLElement Parsed `<item>` element for credit resolution
 */
function loadCreditAttachmentItem(string $innerXml): SimpleXMLElement
{
    // Build a namespaced fragment matching WXR exports
    $xml = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<item xmlns:wp="http://wordpress.org/export/1.2/" '
        .'xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/">'
        .$innerXml
        .'</item>';

    // Parse into SimpleXML for the resolver
    $item = simplexml_load_string($xml);

    // A false result is a broken fixture; fail loudly (and narrow for PHPStan)
    if (! $item instanceof SimpleXMLElement) {
        throw new RuntimeException('Failed to parse attachment <item> fixture.');
    }

    return $item;
}

/**
 * Build a `<wp:postmeta>` fragment for the given key and value.
 *
 * @param  string  $key  The postmeta key
 * @param  string  $value  The postmeta value (embedded as CDATA)
 * @return string A `<wp:postmeta>` XML fragment
 */
function postmeta(string $key, string $value): string
{
    // Wrap both key and value in CDATA to mirror real exports
    return '<wp:postmeta>'
        ."<wp:meta_key><![CDATA[{$key}]]></wp:meta_key>"
        ."<wp:meta_value><![CDATA[{$value}]]></wp:meta_value>"
        .'</wp:postmeta>';
}

/**
 * Serialize a WordPress attachment metadata array carrying an `image_meta.credit`.
 *
 * @param  string  $credit  The EXIF/IPTC credit value
 * @return string PHP-serialized `_wp_attachment_metadata`
 */
function attachmentMetaWithCredit(string $credit): string
{
    // Mirror the real `_wp_attachment_metadata` shape (dimensions + image_meta)
    return serialize([
        'width' => 4000,
        'height' => 3000,
        'image_meta' => [
            'aperture' => '0',
            'credit' => $credit,
            'camera' => 'Test Camera',
        ],
    ]);
}

it('prefers the structured media credit over the EXIF fallback when both exist', function (): void {
    // Attachment carrying both a structured credit and an EXIF credit
    $item = loadCreditAttachmentItem(
        postmeta('_media_credit', 'Structured Studio')
        .postmeta(
            '_wp_attachment_metadata',
            attachmentMetaWithCredit('EXIF Photographer')
        )
    );

    // The structured field must win
    expect(AttachmentCreditResolver::computeFromAttachment($item))->toBe('Structured Studio');
});

it('resolves a structured media credit when no EXIF metadata is present', function (): void {
    // Attachment carrying only the structured credit
    $item = loadCreditAttachmentItem(
        postmeta('_media_credit', 'Jane Q. Doe')
    );

    expect(AttachmentCreditResolver::computeFromAttachment($item))->toBe('Jane Q. Doe');
});

it('falls back to the EXIF image_meta credit when no media credit is present', function (): void {
    // Attachment carrying only the EXIF credit inside serialized metadata
    $item = loadCreditAttachmentItem(
        postmeta(
            '_wp_attachment_metadata',
            attachmentMetaWithCredit('Ansel Adams')
        )
    );

    expect(AttachmentCreditResolver::computeFromAttachment($item))->toBe('Ansel Adams');
});

it('treats a blank media credit as absent and uses the EXIF fallback', function (): void {
    // Structured credit present but whitespace-only; EXIF carries the real value
    $item = loadCreditAttachmentItem(
        postmeta('_media_credit', '   ')
        .postmeta(
            '_wp_attachment_metadata',
            attachmentMetaWithCredit('EXIF Photographer')
        )
    );

    expect(AttachmentCreditResolver::computeFromAttachment($item))->toBe('EXIF Photographer');
});

it('returns an empty string when no credit is present anywhere', function (): void {
    // Attachment with unrelated metadata only (no credit sources)
    $item = loadCreditAttachmentItem(
        postmeta('_wp_attachment_image_alt', 'Alt text only')
        .postmeta(
            '_wp_attachment_metadata',
            serialize(['width' => 100, 'height' => 100])
        )
    );

    expect(AttachmentCreditResolver::computeFromAttachment($item))->toBe('');
});

it('trims surrounding whitespace from the resolved credit', function (): void {
    // Structured credit padded with whitespace
    $item = loadCreditAttachmentItem(
        postmeta('_media_credit', '  Padded Studio  ')
    );

    expect(AttachmentCreditResolver::computeFromAttachment($item))->toBe('Padded Studio');

    // The EXIF fallback credit is trimmed as well
    expect(
        AttachmentCreditResolver::parseExifCredit(
            serialize(['image_meta' => ['credit' => '  Ansel Adams  ']])
        )
    )->toBe('Ansel Adams');
});

it('rejects malformed or non-string EXIF credit values', function (): void {
    // Empty serialized string yields no credit
    expect(AttachmentCreditResolver::parseExifCredit(''))->toBe('');

    // Non-array serialized payload yields no credit
    expect(AttachmentCreditResolver::parseExifCredit(serialize('not-an-array')))->toBe('');

    // Metadata without an image_meta array yields no credit
    expect(
        AttachmentCreditResolver::parseExifCredit(
            serialize(['width' => 10, 'height' => 10])
        )
    )->toBe('');

    // A present-but-non-array image_meta yields no credit
    expect(
        AttachmentCreditResolver::parseExifCredit(
            serialize(['image_meta' => 'not-an-array'])
        )
    )->toBe('');

    // An image_meta array missing the credit key yields no credit
    expect(
        AttachmentCreditResolver::parseExifCredit(
            serialize(['image_meta' => ['aperture' => '2.8']])
        )
    )->toBe('');

    // A non-string credit value is ignored
    expect(
        AttachmentCreditResolver::parseExifCredit(
            serialize(['image_meta' => ['credit' => ['array', 'value']]])
        )
    )->toBe('');
});

it('surfaces the resolved credit under the creator key via extractAttachmentData', function (): void {
    // Attachment with a structured credit
    $mediaCreditItem = loadCreditAttachmentItem(
        '<wp:attachment_url><![CDATA[https://example.org/photo.jpg]]></wp:attachment_url>'
        .postmeta('_media_credit', 'Wire Studio')
    );

    // The extraction array exposes the credit as `creator`
    expect(XpathHelper::extractAttachmentData($mediaCreditItem)['creator'])->toBe('Wire Studio');

    // Attachment relying on the EXIF fallback
    $exifItem = loadCreditAttachmentItem(
        '<wp:attachment_url><![CDATA[https://example.org/photo.jpg]]></wp:attachment_url>'
        .postmeta(
            '_wp_attachment_metadata',
            attachmentMetaWithCredit('EXIF Wire')
        )
    );

    // The fallback credit also flows through extraction
    expect(XpathHelper::extractAttachmentData($exifItem)['creator'])->toBe('EXIF Wire');
});
