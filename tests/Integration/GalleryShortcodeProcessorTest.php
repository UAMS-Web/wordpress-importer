<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Mockery\CompositeExpectation;
use Mockery\Expectation;
use Mockery\ExpectationInterface;
use Mockery\LegacyMockInterface;
use Mockery\MockInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\SameSitePolicy;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\GalleryShortcodeProcessor;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * Tests for GalleryShortcodeProcessor, which turns `[gallery ids="..."]` into a gallery set.
 *
 * `parse()` reads the comma-separated `ids` attribute, keeps the numeric ones, and imports
 * each through the image container. A shortcode with no usable id, or one that leaves fewer
 * than two images, logs a warning and returns a set with no images. `toPageBuilderBlock()`
 * carries that set into a `uamsweb_block_gallery` block.
 *
 * @command  php vendor/bin/pest tests/Integration/GalleryShortcodeProcessorTest.php
 */
uses(StatamicTestCase::class);

beforeEach(function (): void {
    ProcessingContext::clearAttachments();
    ProcessingContext::clearWordPressPageLink();
});

afterEach(function (): void {
    ProcessingContext::clearAttachments();
    ProcessingContext::clearWordPressPageLink();
});

/**
 * Unwrap the single Expectation of a Mockery shouldReceive() call.
 *
 * @param  LegacyMockInterface|MockInterface  $mock  The mock to expect a call on
 * @param  string  $method  The method expected to be called
 * @return Expectation The unwrapped expectation, ready to chain
 */
function galleryShortcodeExpects(LegacyMockInterface|MockInterface $mock, string $method): Expectation
{
    $composite = $mock->shouldReceive($method);

    if (! $composite instanceof CompositeExpectation) {
        throw new RuntimeException('Expected a Mockery CompositeExpectation.');
    }

    /** @var list<ExpectationInterface> $expectations */
    $expectations = new ReflectionClass(CompositeExpectation::class)->getProperty('_expectations')->getValue($composite);

    if (count($expectations) !== 1 || ! $expectations[0] instanceof Expectation) {
        throw new RuntimeException('Expected exactly one Mockery Expectation.');
    }

    return $expectations[0];
}

/**
 * An asset importer with uploads on, answering importImage() from a map of id to path.
 *
 * @param  array<int|string, ?string>  $paths  Each image id expected, and the path it imports to
 * @param  array<int|string, mixed>  $attachments  The attachments importImage() must receive
 * @param  BufferedOutput  $output  The output importImage() must receive
 * @return AssetImporter The mocked importer
 */
function galleryShortcodeImporter(array $paths, array $attachments, BufferedOutput $output): AssetImporter
{
    /** @var AssetImporter&MockInterface $importer */
    $importer = Mockery::mock(AssetImporter::class);
    galleryShortcodeExpects($importer, 'getUploadImages')->andReturnTrue();

    foreach ($paths as $id => $path) {
        galleryShortcodeExpects($importer, 'importImage')
            ->once()
            ->with(Mockery::mustBe((string) $id), 'default', $attachments, $output, SameSitePolicy::ALWAYS_IMPORT, null, 'images', false)
            ->andReturn($path);
    }

    return $importer;
}

/**
 * Capture every warning the processor logs.
 *
 * @return ArrayObject<int, array{message: string, context: array<int|string, mixed>}> The warnings, as they arrive
 */
function galleryShortcodeWarnings(): ArrayObject
{
    /** @var ArrayObject<int, array{message: string, context: array<int|string, mixed>}> $warnings */
    $warnings = new ArrayObject;

    Log::shouldReceive('warning')->andReturnUsing(function (string $message, array $context) use ($warnings): void {
        /** @var array<int|string, mixed> $context */
        $warnings->append(['message' => $message, 'context' => $context]);
    });

    return $warnings;
}

it('imports the numeric ids in order, trimmed, with the attachments in context', function (): void {
    $attachments = ['1' => ['url' => 'https://example.org/a.jpg']];
    ProcessingContext::setAttachments($attachments);
    $output = new BufferedOutput;
    $importer = galleryShortcodeImporter(['1' => 'a.jpg', '2' => 'b.jpg'], $attachments, $output);

    $set = new GalleryShortcodeProcessor()->parse(['ids' => ' 1, x , ,2 '], 'default', $importer, $output);

    expect($set)->toBe([
        'type' => 'gallery',
        'heading' => 'Image Gallery',
        'images' => ['a.jpg', 'b.jpg'],
        'more_query' => false,
    ]);
});

it('imports with no attachments when the context holds none', function (): void {
    $output = new BufferedOutput;
    $importer = galleryShortcodeImporter(['1' => 'a.jpg', '2' => 'b.jpg'], [], $output);

    $set = new GalleryShortcodeProcessor()->parse(['ids' => '1,2'], 'default', $importer, $output);

    expect($set['images'])->toBe(['a.jpg', 'b.jpg']);
});

it('warns and returns no images when the shortcode has no usable id', function (array $attributes): void {
    $warnings = galleryShortcodeWarnings();

    /** @var AssetImporter&MockInterface $importer */
    $importer = Mockery::mock(AssetImporter::class);
    galleryShortcodeExpects($importer, 'importImage')->never();

    $set = new GalleryShortcodeProcessor()->parse($attributes, 'default', $importer, new BufferedOutput, [], 'a-page');

    expect($set)->toBe([
        'type' => 'gallery',
        'heading' => 'Image Gallery',
        'images' => [],
        'more_query' => false,
    ]);

    expect($warnings)->toHaveCount(1);
    expect($warnings->getArrayCopy()[0]['message'])->toContain('GalleryShortcodeProcessor::parse: No valid image IDs found in gallery shortcode');
    expect($warnings->getArrayCopy()[0]['context'])->toMatchArray([
        'site' => 'default',
        'pageSlug' => 'a-page',
        'attributes' => $attributes,
    ]);
})->with([
    'no ids attribute' => [[]],
    'an empty ids attribute' => [['ids' => '']],
    'ids that are not a string' => [['ids' => 12]],
    'ids that are not numeric' => [['ids' => 'a, ,b']],
]);

it('names no page in the warning when there is no page slug', function (): void {
    $warnings = galleryShortcodeWarnings();

    /** @var AssetImporter&MockInterface $importer */
    $importer = Mockery::mock(AssetImporter::class);

    new GalleryShortcodeProcessor()->parse([], 'default', $importer, new BufferedOutput);

    expect($warnings->getArrayCopy()[0]['context']['pageSlug'] ?? null)->toBe('none');
});

// The dataset keys are the image ids, and each value is the path that id imports to
it('warns and returns no images when fewer than two images import', function (array $paths, int $imported): void {
    /** @var array<int|string, ?string> $paths */
    $warnings = galleryShortcodeWarnings();
    $output = new BufferedOutput;
    $importer = galleryShortcodeImporter($paths, [], $output);

    $ids = implode(',', array_keys($paths));
    $set = new GalleryShortcodeProcessor()->parse(['ids' => $ids], 'default', $importer, $output, [], 'a-page');

    expect($set)->toBe([
        'type' => 'gallery',
        'heading' => 'Image Gallery',
        'images' => [],
        'more_query' => false,
    ]);

    expect($warnings)->toHaveCount(1);
    expect($warnings->getArrayCopy()[0]['message'])->toContain('GalleryShortcodeProcessor::parse: Insufficient images for gallery shortcode');
    expect($warnings->getArrayCopy()[0]['context'])->toMatchArray([
        'site' => 'default',
        'pageSlug' => 'a-page',
        'image_count' => $imported,
        'expected_minimum' => 2,
    ]);
})->with([
    'one imported, one failed' => [['1' => 'a.jpg', '2' => null], 1],
    'one imported, one blank' => [['1' => 'a.jpg', '2' => ''], 1],
    'none imported' => [['1' => null, '2' => null], 0],
]);

it('names no page in the insufficient-images warning when there is no page slug', function (): void {
    $warnings = galleryShortcodeWarnings();
    $output = new BufferedOutput;
    $importer = galleryShortcodeImporter(['1' => null], [], $output);

    new GalleryShortcodeProcessor()->parse(['ids' => '1'], 'default', $importer, $output);

    expect($warnings->getArrayCopy()[0]['context']['pageSlug'] ?? null)->toBe('none');
});

it('builds a gallery block from the set', function (): void {
    $output = new BufferedOutput;
    $importer = galleryShortcodeImporter(['1' => 'a.jpg', '2' => 'b.jpg'], [], $output);

    $block = new GalleryShortcodeProcessor()->toPageBuilderBlock(['ids' => '1,2'], 'default', $importer, $output);

    expect($block['id'])->toBeString()->not->toBe('');

    unset($block['id']);

    expect($block)->toBe([
        'type' => 'uamsweb_block_gallery',
        'enabled' => true,
        'block_heading' => 'Image Gallery',
        'hide_block_heading' => false,
        'columns' => '3',
        'images' => ['a.jpg', 'b.jpg'],
        'crop' => 0,
        'background_color' => 'bg-auto',
        'modal' => true,
        'more_query' => false,
    ]);
});

it('passes the page slug and terms through to the set', function (): void {
    $warnings = galleryShortcodeWarnings();

    /** @var AssetImporter&MockInterface $importer */
    $importer = Mockery::mock(AssetImporter::class);

    $block = new GalleryShortcodeProcessor()->toPageBuilderBlock([], 'default', $importer, new BufferedOutput, [], 'a-page');

    expect($block['images'])->toBe([]);
    expect($warnings->getArrayCopy()[0]['context'])->toMatchArray(['site' => 'default', 'pageSlug' => 'a-page']);
});
