<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\GalleryParser;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * Tests the gallery post-import minimum-image guard (issue wordpress-importer-archive#291).
 *
 * `GalleryParser::processImages()` used to discard an entire gallery block when
 * fewer than two valid images survived import (uploads enabled), throwing away a
 * gallery that imported exactly one valid image. The minimum is now one: a
 * gallery is kept as long as at least one image imported successfully, and only a
 * zero-valid-image gallery is dropped (and still warns). This also aligns the
 * post-import minimum with the intake check, which already accepts a single image.
 *
 * The image import is exercised through Statamic's real `ImageProcessor` static
 * helper, which — with uploads enabled — delegates to `AssetImporter::importImage()`.
 * The tests mock that method to make an image "import" succeed or fail, and invoke
 * the private `processImages()` via reflection. `Log::spy()` needs the framework's
 * per-test facade reset, so this runs in the light (booted-Statamic) band.
 *
 * @command  php vendor/bin/pest tests/Integration/GalleryParserMinimumImagesTest.php
 */
uses(StatamicTestCase::class);

afterEach(function (): void {
    Mockery::close();
});

/**
 * Invoke the private GalleryParser::processImages() with the given image ids and a
 * mocked AssetImporter, returning the validated string image paths.
 *
 * @param  array<int|string, string|int>  $imageIds
 * @return array<int, string>
 */
function invokeGalleryProcessImages(array $imageIds, AssetImporter $assetImporter): array
{
    $method = new ReflectionMethod(GalleryParser::class, 'processImages');
    $method->setAccessible(true);

    $rawResult = $method->invoke(
        new GalleryParser,
        $imageIds,
        'medicine_radiology',
        $assetImporter,
        new BufferedOutput,
        [], // attachments
        'https://medicine.uams.edu/radiology/residencies/resident-scrapbook/',
    );

    expect($rawResult)->toBeArray();

    // expect()->toBeArray() does not narrow for PHPStan; guard before iterating.
    if (! \is_array($rawResult)) {
        return [];
    }

    $validated = [];

    foreach ($rawResult as $imagePath) {
        expect($imagePath)->toBeString();

        // expect()->toBeString() does not narrow for PHPStan; guard before appending.
        if (\is_string($imagePath)) {
            $validated[] = $imagePath;
        }
    }

    return $validated;
}

it('keeps a gallery that imports exactly one valid image', function (): void {
    $logSpy = Log::spy();

    // A typed PHPUnit mock (AssetImporter&MockObject) satisfies the AssetImporter
    // parameter without any Mockery-specific expectation API.
    $assetImporter = $this->createMock(AssetImporter::class);
    $assetImporter->method('getUploadImages')->willReturn(true);
    $assetImporter->expects($this->once())->method('importImage')->willReturn('assets::gallery/photo-1.jpg');

    $result = invokeGalleryProcessImages(['101'], $assetImporter);

    expect($result)->toBe(['assets::gallery/photo-1.jpg']);

    // The "no valid images" warning must not fire for a one-image gallery.
    $logSpy->shouldNotHaveReceived('warning');
});

it('drops a gallery that imports zero valid images and still warns', function (): void {
    $logSpy = Log::spy();

    // A typed PHPUnit mock (AssetImporter&MockObject) satisfies the AssetImporter
    // parameter without any Mockery-specific expectation API.
    $assetImporter = $this->createMock(AssetImporter::class);
    $assetImporter->method('getUploadImages')->willReturn(true);

    // Import fails for every id, so no valid image survives.
    $assetImporter->method('importImage')->willReturn(null);

    $result = invokeGalleryProcessImages(['101'], $assetImporter);

    expect($result)->toBe([]);

    // The genuine zero-image case still warns. The production call passes a
    // log-context array as the second argument, so the matcher list must account
    // for it — a one-argument matcher never matches the two-argument
    // Log::warning($message, $context) call.
    $logSpy->shouldHaveReceived('warning', [
        Mockery::on(
            fn (mixed $message): bool => \is_string($message)
                && \str_contains(
                    $message,
                    'No valid images after import when uploads are enabled'
                )
        ),
        Mockery::any(),
    ]);
});
