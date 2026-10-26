<?php

declare(strict_types=1);

/**
 * Tests what `FileProcessor::importFileFromAttachmentWithDisabledUploadHandling()` hands
 * to the asset importer for a WordPress attachment.
 *
 * The method finds the attachment by id, then passes its file URL, title, image metadata
 * and attachment-page URL on to `AssetImporter::importFile()`. Each argument is asserted
 * here as the importer receives it, because a wrong one does not fail the import: it
 * lands as a blank title, a lost photo credit or a missing migration redirect
 * (wordpress-importer-archive#435, wordpress-importer-archive#446).
 *
 * @command  php vendor/bin/pest tests/Unit/FileProcessorAttachmentImportTest.php
 */

use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\FileProcessor;

/**
 * Build a WXR attachment `<item>` from a post id and extra body XML.
 *
 * @param  ?string  $postId  The `wp:post_id`, or null to leave the node out
 * @param  string  $innerXml  Additional `<item>` body XML
 */
function fileImportAttachment(?string $postId, string $innerXml = ''): SimpleXMLElement
{
    $xml = '<item xmlns:wp="http://wordpress.org/export/1.2/">'
        .($postId === null ? '' : "<wp:post_id>{$postId}</wp:post_id>")
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
function fileImportPostmeta(string $key, string $value): string
{
    return '<wp:postmeta>'
        ."<wp:meta_key><![CDATA[{$key}]]></wp:meta_key>"
        ."<wp:meta_value><![CDATA[{$value}]]></wp:meta_value>"
        .'</wp:postmeta>';
}

/**
 * Run the import against a mocked asset importer that records each importFile() call.
 *
 * The mock is built by the test (createMock() is a TestCase method) and configured here.
 * A mock skips AssetImporter's constructor, which reads Laravel config.
 *
 * @param  MockObject&AssetImporter  $importer  A fresh mock of the asset importer
 * @param  array<int|string, mixed>  $attachments  The export's attachment items
 * @param  bool  $uploadFiles  Whether file uploads are enabled
 * @return array{result: ?string, calls: list<list<mixed>>}
 */
function fileImportRun(
    AssetImporter&MockObject $importer,
    string $attachmentId,
    array $attachments,
    bool $uploadFiles = true
): array {
    $calls = [];

    $importer->method('getUploadFiles')->willReturn($uploadFiles);
    $importer->method('importFile')->willReturnCallback(
        static function (mixed ...$arguments) use (&$calls): string {
            $calls[] = \array_values($arguments);

            return 'files/imported.pdf';
        }
    );

    $result = FileProcessor::importFileFromAttachmentWithDisabledUploadHandling(
        $attachmentId,
        $attachments,
        'default',
        $importer,
        new BufferedOutput,
        'files'
    );

    return ['result' => $result, 'calls' => $calls];
}

it('returns nothing for a blank attachment id, even beside an attachment with no post id', function (): void {
    // An absent `wp:post_id` reads as '', so without the blank-id guard a blank id would
    // match that attachment and import it.
    $attachments = [fileImportAttachment(null, '<wp:attachment_url>https://example.uams.edu/a.pdf</wp:attachment_url>')];

    $run = fileImportRun($this->createMock(AssetImporter::class), '', $attachments);

    expect($run['result'])->toBeNull()
        ->and($run['calls'])->toBe([]);
});

it('returns nothing when no attachment matches the id', function (): void {
    $attachments = [fileImportAttachment('10', '<wp:attachment_url>https://example.uams.edu/a.pdf</wp:attachment_url>')];

    // Uploads off, so the missing attachment is not logged and no container is needed
    $run = fileImportRun($this->createMock(AssetImporter::class), '99', $attachments, uploadFiles: false);

    expect($run['result'])->toBeNull()
        ->and($run['calls'])->toBe([]);
});

it('hands the importer the file url, title, metadata and attachment page', function (): void {
    $attachments = [fileImportAttachment(
        '99',
        '<title>Annual Report</title>'
        .'<link>  https://example.uams.edu/report/attachment/annual/  </link>'
        .'<wp:attachment_url>https://example.uams.edu/wp-content/uploads/annual.pdf</wp:attachment_url>'
        .fileImportPostmeta('_media_credit', 'UAMS Communications')
    )];

    $run = fileImportRun($this->createMock(AssetImporter::class), '99', $attachments);

    expect($run['result'])->toBe('files/imported.pdf')
        ->and($run['calls'])->toHaveCount(1);

    [$url, $site, , $container, , $pageUrl, $title, $metadata, $attachmentPage] = $run['calls'][0];

    expect($url)->toBe('https://example.uams.edu/wp-content/uploads/annual.pdf')
        ->and($site)->toBe('default')
        ->and($container)->toBe('files')
        ->and($pageUrl)->toBeNull()
        ->and($title)->toBe('Annual Report')
        ->and($metadata)->toBe(['creator' => 'UAMS Communications'])
        // The page url is trimmed before it is handed on
        ->and($attachmentPage)->toBe('https://example.uams.edu/report/attachment/annual/');
});

it('passes null rather than an empty value for absent metadata and attachment page', function (): void {
    $attachments = [fileImportAttachment(
        '99',
        '<wp:attachment_url>https://example.uams.edu/wp-content/uploads/plain.pdf</wp:attachment_url>'
    )];

    $run = fileImportRun($this->createMock(AssetImporter::class), '99', $attachments);

    [, , , , , , , $metadata, $attachmentPage] = $run['calls'][0];

    expect($metadata)->toBeNull()
        ->and($attachmentPage)->toBeNull();
});

it('returns the attachment url without importing when file uploads are disabled', function (): void {
    $attachments = [fileImportAttachment(
        '99',
        '<wp:attachment_url>https://example.uams.edu/wp-content/uploads/annual.pdf</wp:attachment_url>'
    )];

    $run = fileImportRun($this->createMock(AssetImporter::class), '99', $attachments, uploadFiles: false);

    expect($run['result'])->toBe('https://example.uams.edu/wp-content/uploads/annual.pdf')
        ->and($run['calls'])->toBe([]);
});

it('falls back to the attachment link when the export carries no file url', function (): void {
    // An attachment exported without `wp:attachment_url` still names itself through `<link>`
    $attachments = [fileImportAttachment('99', '<link>https://example.uams.edu/files/annual.pdf</link>')];

    $run = fileImportRun($this->createMock(AssetImporter::class), '99', $attachments);

    expect($run['calls'])->toHaveCount(1)
        ->and($run['calls'][0][0])->toBe('https://example.uams.edu/files/annual.pdf');
});

it('imports nothing for a blank file url', function (bool $uploadFiles): void {
    $importer = $this->createMock(AssetImporter::class);
    $importer->method('getUploadFiles')->willReturn($uploadFiles);
    $importer->expects($this->never())->method('importFile');

    expect(FileProcessor::importFileWithDisabledUploadHandling('', 'default', $importer, new BufferedOutput, 'files'))
        ->toBeNull();
})->with([
    'uploads enabled' => [true],
    'uploads disabled' => [false],
]);
