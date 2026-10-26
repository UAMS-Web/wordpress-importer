<?php

declare(strict_types=1);

use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\FieldMapper;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * Build a WXR attachment `<item>` with the given post id and attachment URL.
 *
 * @param  string  $postId  The attachment's WordPress post id
 * @param  string  $attachmentUrl  The `wp:attachment_url` body; empty models a row whose file has left the media library
 */
function pageFeaturedImageAttachment(string $postId, string $attachmentUrl): SimpleXMLElement
{
    // Build the attachment item, declaring the `wp:` namespace the helpers query through
    $xml = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<item xmlns:wp="http://wordpress.org/export/1.2/">'
        .'<title><![CDATA[An Image]]></title>'
        ."<wp:post_id>{$postId}</wp:post_id>"
        ."<wp:attachment_url><![CDATA[{$attachmentUrl}]]></wp:attachment_url>"
        .'</item>';

    $item = simplexml_load_string($xml);

    // Fail loudly rather than letting a malformed fixture read as a passing assertion
    if (! $item instanceof SimpleXMLElement) {
        throw new RuntimeException('Failed to parse the attachment <item> fixture.');
    }

    return $item;
}

/**
 * Run `FieldMapper::mapFields()` for a page carrying `_thumbnail_id`, with image
 * uploads disabled, and return the `$data` array it wrote into.
 *
 * @param  string  $attachmentUrl  The URL the attachment row carries
 * @param  string  $attachmentPostId  The attachment row's post id; a non-matching value models a missing attachment
 * @return array<int|string, mixed> The mapped entry data, as `mapFields()` left it
 */
function pageFeaturedImageMappedData(string $attachmentUrl, string $attachmentPostId = '999'): array
{
    // Disable uploads so the resolver takes the branch that returns the attachment URL
    config()->set('wordpress-import.upload_images', false);
    config()->set('wordpress-import.upload_files', false);

    // ---
    // `mapFields()` writes through a reference. Seed only `site`, which is what
    // `PageEntryCreator` now passes for this handle: wordpress-importer-archive#754 removed its
    // `'featured_image_16_9' => null` initialiser, without which the gate below could
    // never omit the key — it would only leave the seeded null in place.
    // ---
    $data = ['site' => 'default'];

    $mapper = new FieldMapper;

    $mapper->mapFields(
        ['_thumbnail_id' => '999'],
        $data,
        'page',
        app(XmlParser::class),
        new AssetImporter,
        [pageFeaturedImageAttachment($attachmentPostId, $attachmentUrl)],
        new BufferedOutput
    );

    return $data;
}

it('omits featured_image_16_9 when the attachment yields an empty url', function (): void {
    // ---
    // wordpress-importer-archive#754. With uploads disabled, `importImageWithDisabledUploadHandling()` returns
    // the attachment's `wp:attachment_url` as a string — so a row whose file has left
    // the media library yields `''`, not null. The pages path used to store that empty
    // string under an asset handle; it now omits the key, matching the gate
    // `KbEntryCreator` has carried since wordpress-importer-archive#751.
    //
    // This assertion lives here rather than only in the host-app subset because the
    // behaviour is INVISIBLE there: Statamic's `fileData()` strips empty strings, so a
    // gated and an ungated importer save byte-identical entries. A host test reading
    // saved entry data therefore passes whether or not the gate exists, and cannot be
    // mutation-verified. `mapFields()` writes through a reference, so the gate's effect
    // is observable here — and a mutation of it is caught.
    // ---
    expect(pageFeaturedImageMappedData(''))->not->toHaveKey('featured_image_16_9');
});

it('omits featured_image_16_9 when no attachment matches the thumbnail id', function (): void {
    // ---
    // The other half of the gate. `importImageWithDisabledUploadHandling()` returns
    // `null` — not `''` — when `findAttachmentById()` finds no row for the id, so
    // `$featuredImagePath !== null` is the condition that fires here. Without this case
    // that half is a surviving mutant: deleting it leaves the suite green, because the
    // empty-url case above only exercises `!== ''`.
    // ---
    expect(pageFeaturedImageMappedData('https://example.uams.edu/an-image.jpg', '12345'))
        ->not->toHaveKey('featured_image_16_9');
});

it('writes featured_image_16_9 when the attachment yields a url', function (): void {
    // ---
    // The positive control. Without it the assertion above would pass just as happily
    // against a `mapFields()` that never writes the handle under any circumstances,
    // which is the failure mode wordpress-importer-archive#609 spent a whole ticket removing from `KbImportTest`.
    // ---
    $data = pageFeaturedImageMappedData('https://example.uams.edu/an-image.jpg');

    expect($data)->toHaveKey('featured_image_16_9')
        ->and($data['featured_image_16_9'])->toBe('https://example.uams.edu/an-image.jpg');
});
