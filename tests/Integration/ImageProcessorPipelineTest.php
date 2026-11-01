<?php

declare(strict_types=1);

/**
 * Tests that `ImageProcessor` runs its steps in order over one shared context, returns
 * the image set the last step leaves, and warns with the entry's context when no step
 * produces one. Also covers the empty-id and upload-enabled paths of
 * `importImageWithDisabledUploadHandling()`.
 *
 * The steps themselves have their own tests; here they are replaced with recording
 * steps by reflection, so each assertion is about the coordination.
 *
 * @command  php vendor/bin/pest tests/Integration/ImageProcessorPipelineTest.php
 */

use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\SameSitePolicy;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImageProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\ImageProcessor\AlignmentExtractor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\ImageProcessor\AltTextProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\ImageProcessor\AssetImporterProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\ImageProcessor\BardSetBuilder;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\ImageProcessor\CaptionExtractor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\ImageProcessor\HtmlParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\ImageProcessor\ImageProcessingStepInterface;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * A pipeline step that records the context it receives and merges in a fixed result.
 */
class ImageProcessorPipelineRecordingStep implements ImageProcessingStepInterface
{
    /**
     * Every context this step was handed, with the call's site and page slug.
     *
     * @var list<array{array<int|string, mixed>, string, array<int|string, mixed>, ?string}>
     */
    public array $calls = [];

    /**
     * Configure what the step adds to the context.
     *
     * @param  array<int|string, mixed>  $adds  Keys merged into the context it returns
     */
    public function __construct(private readonly array $adds = []) {}

    /**
     * Record the call and return the context with this step's keys merged in.
     *
     * @param  array<int|string, mixed>  $context
     * @param  array<int|string, mixed>  $attachments
     * @return array<int|string, mixed>
     */
    public function process(
        array $context,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = [],
        ?string $pageSlug = null
    ): array {
        $this->calls[] = [$context, $site, $attachments, $pageSlug];

        return \array_merge($context, $this->adds);
    }
}

/**
 * An ImageProcessor whose pipeline is exactly the given steps.
 *
 * @param  list<ImageProcessingStepInterface>  $steps
 */
function imagePipelineWith(array $steps): ImageProcessor
{
    $processor = new ImageProcessor;
    (new ReflectionProperty($processor, 'processors'))->setValue($processor, $steps);

    return $processor;
}

afterEach(function (): void {
    ProcessingContext::clearWordPressPageLink();
});

it('builds the default pipeline in order', function (): void {
    $steps = (new ReflectionProperty(ImageProcessor::class, 'processors'))->getValue(new ImageProcessor);

    expect(\is_array($steps) ? \array_map(static fn (mixed $step): string => \is_object($step) ? $step::class : \gettype($step), $steps) : null)->toBe([
        HtmlParser::class,
        AssetImporterProcessor::class,
        AlignmentExtractor::class,
        CaptionExtractor::class,
        AltTextProcessor::class,
        BardSetBuilder::class,
    ]);
});

it('hands each step the context the previous one returned, with the call\'s inputs', function (): void {
    $first = new ImageProcessorPipelineRecordingStep(['parsed' => true]);
    $last = new ImageProcessorPipelineRecordingStep(['imageSet' => ['type' => 'set']]);

    $result = imagePipelineWith([$first, $last])->processImage(
        '<img src="a.jpg">',
        'default',
        $this::createStub(AssetImporter::class),
        new BufferedOutput,
        ['an attachment'],
        'a-page',
        ['id' => 7]
    );

    expect($result)->toBe(['type' => 'set'])
        ->and($first->calls)->toBe([[
            ['html' => '<img src="a.jpg">', 'blockAttributes' => ['id' => 7]],
            'default',
            ['an attachment'],
            'a-page',
        ]])
        ->and($last->calls[0][0] ?? null)->toBe([
            'html' => '<img src="a.jpg">',
            'blockAttributes' => ['id' => 7],
            'parsed' => true,
        ]);
});

it('starts with empty block attributes when none are given', function (): void {
    $step = new ImageProcessorPipelineRecordingStep;

    imagePipelineWith([$step])->processImage(
        '<img>',
        'default',
        $this::createStub(AssetImporter::class),
        new BufferedOutput
    );

    expect($step->calls[0][0] ?? null)->toBe(['html' => '<img>', 'blockAttributes' => []]);
});

it('returns the steps added after construction', function (): void {
    $processor = imagePipelineWith([]);
    $processor->addProcessor(new ImageProcessorPipelineRecordingStep(['imageSet' => ['added' => true]]));

    expect($processor->processImage('<img>', 'default', $this::createStub(AssetImporter::class), new BufferedOutput))
        ->toBe(['added' => true]);
});

it('returns null for an image set that is not an array', function (): void {
    $result = imagePipelineWith([new ImageProcessorPipelineRecordingStep(['imageSet' => 'not a set'])])
        ->processImage('<img>', 'default', $this::createStub(AssetImporter::class), new BufferedOutput);

    expect($result)->toBeNull();
});

it('warns with the site, slug and a short snippet when no step produces an image set', function (): void {
    $log = Log::spy();
    // One character over the limit is enough to be cut
    $html = \str_repeat('a', 100).'T';

    $result = imagePipelineWith([new ImageProcessorPipelineRecordingStep])
        ->processImage($html, 'default', $this::createStub(AssetImporter::class), new BufferedOutput, [], 'a-page');

    expect($result)->toBeNull();

    $log->shouldHaveReceived('warning', [
        'ImageProcessor::processImage: Image processing pipeline failed to produce an image set',
        ['site' => 'default', 'pageSlug' => 'a-page', 'html_snippet' => \str_repeat('a', 100).'...'],
    ]);
});

it('keeps a snippet of exactly 100 characters whole and names a missing slug', function (): void {
    $log = Log::spy();
    $html = \str_repeat('b', 100);

    imagePipelineWith([])->processImage($html, 'default', $this::createStub(AssetImporter::class), new BufferedOutput);

    $log->shouldHaveReceived('warning', [
        'ImageProcessor::processImage: Image processing pipeline failed to produce an image set',
        ['site' => 'default', 'pageSlug' => 'none', 'html_snippet' => $html],
    ]);
});

it('names the entry being imported in the warning', function (): void {
    $log = Log::spy();
    ProcessingContext::setWordPressPageLink('https://example.edu/a-page/');

    imagePipelineWith([])->processImage('<img>', 'default', $this::createStub(AssetImporter::class), new BufferedOutput);

    $log->shouldHaveReceived('warning', [
        'ImageProcessor::processImage: Image processing pipeline failed to produce an image set (from entry: https://example.edu/a-page/)',
        [
            'site' => 'default',
            'pageSlug' => 'none',
            'html_snippet' => '<img>',
            'entry_url' => 'https://example.edu/a-page/',
        ],
    ]);
});

it('returns null for an empty image id without asking the importer', function (): void {
    $importer = $this->createMock(AssetImporter::class);
    $importer->expects($this->never())->method('getUploadImages');
    $importer->expects($this->never())->method('importImage');

    expect(ImageProcessor::importImageWithDisabledUploadHandling(
        '',
        'default',
        $importer,
        new BufferedOutput,
        [],
        'an image',
        'Test::context'
    ))->toBeNull();
});

it('imports through the importer, with no fallback url, when uploads are enabled', function (): void {
    $output = new BufferedOutput;
    $importer = $this->createMock(AssetImporter::class);
    $importer->method('getUploadImages')->willReturn(true);
    $importer->expects($this->once())
        ->method('importImage')
        ->with('42', 'default', ['an attachment'], $output, SameSitePolicy::ALWAYS_IMPORT, null, 'images', false)
        ->willReturn('images/a.jpg');

    expect(ImageProcessor::importImageWithDisabledUploadHandling(
        '42',
        'default',
        $importer,
        $output,
        ['an attachment'],
        'an image',
        'Test::context'
    ))->toBe('images/a.jpg')
        ->and($output->fetch())->toBe('');
});
