<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Traits\DisabledUploadHandlingTrait;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * Tests the upload-disabled check and the attachment lookup that `DisabledUploadHandlingTrait`
 * gives the image and file processors.
 *
 * @command  php vendor/bin/pest tests/Integration/DisabledUploadHandlingTraitTest.php
 */
uses(StatamicTestCase::class);

beforeEach(function (): void {
    ProcessingContext::clearWordPressPageLink();
    ProcessingContext::clearFormConfigContext();
});

/**
 * Exposes the trait's protected statics to the tests.
 */
final class DisabledUploadTraitHost
{
    use DisabledUploadHandlingTrait {
        checkUploadsDisabled as public;
        findAttachmentById as public;
    }
}

/**
 * An asset importer whose two upload switches are set as given.
 */
function disabledUploadImporter(bool $images, bool $files): AssetImporter
{
    // The importer reads both switches from config when it is built.
    config([
        'wordpress-import.upload_images' => $images,
        'wordpress-import.upload_files' => $files,
    ]);

    return new AssetImporter;
}

/**
 * One WXR attachment item with the given post id.
 */
function disabledUploadAttachment(string $postId, string $url): SimpleXMLElement
{
    return new SimpleXMLElement(
        '<item xmlns:wp="http://wordpress.org/export/1.2/">'
        ."<wp:post_id>{$postId}</wp:post_id><wp:attachment_url>{$url}</wp:attachment_url></item>"
    );
}

it('reads the switch that matches the upload type', function (string $type, bool $images, bool $files, bool $disabled): void {
    $output = new BufferedOutput;

    expect(DisabledUploadTraitHost::checkUploadsDisabled(disabledUploadImporter($images, $files), $output, 'the hero', $type))
        ->toBe($disabled)
        ->and($output->fetch())->toBe($disabled ? '  - '.\ucfirst($type).' uploads disabled. Using original URL for the hero.'.PHP_EOL : '');
})->with([
    'images off' => ['image', false, true, true],
    'images on' => ['image', true, false, false],
    'files off' => ['file', true, false, true],
    'files on' => ['file', false, true, false],
]);

it('finds the attachment past entries that are not XML items', function (): void {
    $match = disabledUploadAttachment('42', 'https://example.test/a.jpg');
    $log = Log::spy();

    $found = DisabledUploadTraitHost::findAttachmentById(
        '42',
        ['not an item', disabledUploadAttachment('41', 'https://example.test/b.jpg'), $match],
        'default',
        'the hero',
        'Image block',
        disabledUploadImporter(true, true),
        'image'
    );

    expect($found)->toBe(['attachment' => $match, 'url' => 'https://example.test/a.jpg']);
    $log->shouldNotHaveReceived('warning');
});

it('warns about a missing attachment only when uploads of that type are on', function (string $type, bool $images, bool $files, bool $warns): void {
    $warnings = [];
    Log::shouldReceive('warning')->andReturnUsing(function (string $message, array $context) use (&$warnings): void {
        $warnings[] = [$message, $context];
    });

    $found = DisabledUploadTraitHost::findAttachmentById(
        '42',
        [disabledUploadAttachment('41', 'https://example.test/b.jpg')],
        'default',
        'the hero',
        'Image block',
        disabledUploadImporter($images, $files),
        $type
    );

    expect($found)->toBeNull()
        ->and($warnings)->toBe($warns ? [[
            'Image block: No attachment found for the hero ID',
            ['site' => 'default', 'attachment_id' => '42', 'description' => 'the hero', 'context' => 'Image block'],
        ]] : []);
})->with([
    'images on' => ['image', true, false, true],
    'images off' => ['image', false, true, false],
    'files on' => ['file', false, true, true],
    'files off' => ['file', true, false, false],
]);
