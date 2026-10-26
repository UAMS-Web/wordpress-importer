<?php

declare(strict_types=1);

use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\FileProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImageProcessor;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * Build a WXR attachment `<item>`.
 *
 * @param  string  $attachmentUrl  The `wp:attachment_url` body
 * @param  string  $link  The `<link>` body, which the file helper falls back to
 */
function resolverDomainAttachment(string $attachmentUrl, string $link = ''): SimpleXMLElement
{
    $xml = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<item xmlns:wp="http://wordpress.org/export/1.2/">'
        .'<title><![CDATA[An Image]]></title>'
        ."<link>{$link}</link>"
        .'<wp:post_id>999</wp:post_id>'
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
 * Disable uploads and return a fresh importer plus a discard sink.
 *
 * @return array{0: AssetImporter, 1: BufferedOutput}
 */
function resolverDomainHarness(): array
{
    config()->set('wordpress-import.upload_images', false);
    config()->set('wordpress-import.upload_files', false);

    return [new AssetImporter, new BufferedOutput];
}

it('resolves an empty attachment url to an empty string on the pages path', function (): void {
    // ---
    // wordpress-importer-archive#754. `ImageProcessor` guards the attachment *id*, checks uploads-disabled, then
    // returns `wp:attachment_url` verbatim — so an attachment row whose file has left
    // the media library yields `''`. This is what `FieldMapper`'s `!== ''` arm exists
    // for, and the reason the pages gate cannot be written as `!== null` alone.
    // ---
    [$assetImporter, $output] = resolverDomainHarness();

    $resolved = ImageProcessor::importImageWithDisabledUploadHandling(
        '999',
        'default',
        $assetImporter,
        $output,
        [resolverDomainAttachment('')],
        'featured image',
        'FeaturedImageResolverDomainTest'
    );

    expect($resolved)->toBe('');
});

it('resolves an empty attachment url to null on the posts path', function (): void {
    // ---
    // The same input, the other helper, the opposite answer — and the whole reason this
    // file exists. `FileProcessor::importFileWithDisabledUploadHandling()` tests
    // `if ($fileUrl === '') { return null; }` BEFORE it consults the uploads-disabled
    // setting, so the row that yields `''` above yields `null` here.
    //
    // Two successive versions of wordpress-importer-archive#754 asserted the pages mechanism applied to the posts
    // path. It does not, and three review agents had to re-derive that by hand. Pinning
    // it means the next reader is corrected by a red test rather than by a comment.
    // ---
    [$assetImporter, $output] = resolverDomainHarness();

    $resolved = FileProcessor::importFileFromAttachmentWithDisabledUploadHandling(
        '999',
        [resolverDomainAttachment('')],
        'default',
        $assetImporter,
        $output,
        'images',
        'featured image',
        'FeaturedImageResolverDomainTest'
    );

    expect($resolved)->toBeNull();
});

it('falls back to the attachment link on the posts path', function (): void {
    // ---
    // The `<link>` fallback is why the posts helper cannot see an empty URL the way the
    // pages helper does: `importFileFromAttachmentWithDisabledUploadHandling()` resolves
    // `$fileUrl` from `wp:attachment_url` and then from `<link>`, so an empty URL is only
    // empty when both are. Without this case the null result above could be read as
    // "the helper ignores the row", which is a different and wrong lesson.
    // ---
    [$assetImporter, $output] = resolverDomainHarness();

    $resolved = FileProcessor::importFileFromAttachmentWithDisabledUploadHandling(
        '999',
        [resolverDomainAttachment('', 'https://example.uams.edu/an-image/')],
        'default',
        $assetImporter,
        $output,
        'images',
        'featured image',
        'FeaturedImageResolverDomainTest'
    );

    expect($resolved)->toBe('https://example.uams.edu/an-image/');
});
