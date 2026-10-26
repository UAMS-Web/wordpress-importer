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
use UAMSWeb\WordPressImporter\Services\WordPressImport\Tiptap\SetMarkupNonce;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\AcfGalleryBlockProcessor;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * Tests for AcfGalleryBlockProcessor, which turns an `acf/uams-gallery` block into a Bard `gallery` set.
 *
 * The block's ACF `data` carries the image ids (a list, or a comma-separated
 * string), the heading, a description split into paragraphs on blank lines, and the
 * "more" section with its button. The images are imported, and when fewer than two
 * import, or there are no ids, a warning is logged and an empty gallery set is
 * returned in place of the block.
 *
 * @command  php vendor/bin/pest tests/Integration/AcfGalleryBlockProcessorTest.php
 */
uses(StatamicTestCase::class);

beforeEach(function (): void {
    ProcessingContext::clearWordPressPageLink();
    ProcessingContext::clearFormConfigContext();
});

afterEach(function (): void {
    ProcessingContext::clearWordPressPageLink();
});

/**
 * An asset importer with image uploads on that imports the given ids to the given paths.
 *
 * @param  array<int|string, mixed>  $paths  What importImage() returns, keyed by image id
 * @param  array<int|string, mixed>  $attachments  The attachments the processor must pass on
 * @param  BufferedOutput  $output  The output the processor must pass on
 * @return AssetImporter The mocked importer
 */
function acfGalleryImporter(array $paths, array $attachments, BufferedOutput $output): AssetImporter
{
    /** @var AssetImporter&MockInterface $importer */
    $importer = Mockery::mock(AssetImporter::class);
    acfGalleryExpects($importer, 'getUploadImages')->andReturnTrue();

    foreach ($paths as $id => $path) {
        acfGalleryExpects($importer, 'importImage')
            ->once()
            ->with((string) $id, 'default', $attachments, $output, SameSitePolicy::ALWAYS_IMPORT, null, 'images', false)
            ->andReturn($path);
    }

    return $importer;
}

/**
 * Return the sole concrete Expectation held inside a CompositeExpectation so its
 * fluent methods are visible to PHPStan; the CompositeExpectation only proxies
 * them through `__call`.
 *
 * File-local for the reason `tests/Integration/TreeBuilderBranchTest.php` gives
 * for its own copy: a shared trait would read to the analyser as unused.
 *
 * @param  LegacyMockInterface|MockInterface  $mock  The mock to expect a call on
 * @param  string  $method  The method expected to be called
 * @return Expectation The unwrapped expectation, ready to chain
 */
function acfGalleryExpects(LegacyMockInterface|MockInterface $mock, string $method): Expectation
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
 * Run the processor.
 *
 * @param  array<int|string, mixed>  $attributes  The block's attributes
 * @param  AssetImporter  $importer  The asset importer
 * @param  BufferedOutput  $output  The console output
 * @param  array<int|string, mixed>  $attachments  The attachments
 * @param  ?DOMNode  $node  The block comment
 * @return ?array<int|string, mixed> The processor's result
 */
function processAcfGallery(array $attributes, AssetImporter $importer, BufferedOutput $output, array $attachments = [], ?DOMNode $node = null): ?array
{
    return (new AcfGalleryBlockProcessor)->process(
        $node ?? new DOMComment(' wp:acf/uams-gallery {} /'),
        $attributes,
        'default',
        $importer,
        $output,
        $attachments,
        [],
        'about'
    );
}

/**
 * Collect every Log::warning() call as a [message, context] pair.
 *
 * @return ArrayObject<int, array{0: string, 1: array<string, mixed>}> The calls, filled as they happen
 */
function acfGalleryWarnings(): ArrayObject
{
    $warnings = new ArrayObject;

    Log::shouldReceive('warning')->andReturnUsing(function (string $message, array $context) use ($warnings): void {
        $warnings->append([$message, $context]);
    });

    return $warnings;
}

/**
 * The empty gallery set the processor writes when it cannot build one.
 */
function acfGalleryEmptySet(): string
{
    return '<div data-set-type="gallery"'.SetMarkupNonce::attribute()
        .' data-set-attr-heading="Image Gallery" data-set-attr-images=\'[]\' data-set-attr-more_query="false"></div>';
}

/**
 * The gallery set markup for the given attribute values, already escaped.
 *
 * @param  array<string, string>  $values  Escaped attribute values, keyed by set field
 * @return string The expected markup
 */
function acfGallerySet(array $values): string
{
    $values += [
        'heading' => 'Image Gallery',
        'images' => '[&quot;a.jpg&quot;,&quot;b.jpg&quot;]',
        'more_query' => '',
        'description' => '[]',
        'more_heading' => '',
        'more_button_text' => '',
        'more_button_url' => '',
        'more_button_target' => '',
        'more_button_link_description' => '',
    ];

    $attrs = '';

    foreach (['heading', 'images', 'more_query', 'description', 'more_heading', 'more_button_text', 'more_button_url', 'more_button_target', 'more_button_link_description'] as $key) {
        $attrs .= \in_array($key, ['images', 'description'], true)
            ? " data-set-attr-{$key}='{$values[$key]}'"
            : " data-set-attr-{$key}=\"{$values[$key]}\"";
    }

    return '<div data-set-type="gallery"'.SetMarkupNonce::attribute().$attrs.'></div>';
}

it('accepts only an acf/uams-gallery comment', function (string $comment, bool $expected): void {
    expect((new AcfGalleryBlockProcessor)->canProcess(new DOMComment($comment), 'default', null))->toBe($expected);
})->with([
    'block with attributes' => [' wp:acf/uams-gallery {"name":"acf/uams-gallery"} /', true],
    'attributes on the next line' => [" wp:acf/uams-gallery\n{} ", true],
    'no attributes' => [' wp:acf/uams-gallery ', false],
    'another block with the same prefix' => [' wp:acf/uams-gallery-grid {} ', false],
    'another ACF block' => [' wp:acf/uams-video {} ', false],
    'closing comment' => [' /wp:acf/uams-gallery ', false],
]);

it('refuses a node that is not a comment', function (): void {
    expect((new AcfGalleryBlockProcessor)->canProcess(new DOMText('wp:acf/uams-gallery {}'), 'default', null))->toBeFalse();
});

it('maps every ACF field into the gallery set', function (): void {
    $attachments = ['attachment list'];
    $output = new BufferedOutput;
    $node = new DOMComment(' wp:acf/uams-gallery {} /');

    $result = processAcfGallery(
        ['data' => [
            'gallery_images' => [1, '2', null, '', ['3'], 4],
            'gallery_heading' => 'Team & "friends"',
            'gallery_description' => "  First paragraph  \r\n\r\nSecond\nline\n\n   \n\nThird ",
            'gallery_more' => '1',
            'gallery_more_text' => 5,
            'gallery_more_button_text' => 'See more',
            'gallery_more_button_url' => ['url' => 'https://example.test/more?a=1&b=2', 'target' => '_blank'],
            'gallery_more_button_description' => "Ann's album",
        ]],
        acfGalleryImporter(['1' => 'a.jpg', '2' => 'b.jpg', '4' => "c/o'd.jpg"], $attachments, $output),
        $output,
        $attachments,
        $node
    );

    expect($result)->toBe([
        'html' => acfGallerySet([
            'heading' => 'Team &amp; &quot;friends&quot;',
            'images' => '[&quot;a.jpg&quot;,&quot;b.jpg&quot;,&quot;c\/o&#039;d.jpg&quot;]',
            'more_query' => '1',
            'description' => '[{&quot;type&quot;:&quot;paragraph&quot;,&quot;content&quot;:[{&quot;type&quot;:&quot;text&quot;,&quot;text&quot;:&quot;First paragraph&quot;}]},'
                .'{&quot;type&quot;:&quot;paragraph&quot;,&quot;content&quot;:[{&quot;type&quot;:&quot;text&quot;,&quot;text&quot;:&quot;Second\nline&quot;}]},'
                .'{&quot;type&quot;:&quot;paragraph&quot;,&quot;content&quot;:[{&quot;type&quot;:&quot;text&quot;,&quot;text&quot;:&quot;Third&quot;}]}]',
            'more_heading' => '5',
            'more_button_text' => 'See more',
            'more_button_url' => 'https://example.test/more?a=1&amp;b=2',
            'more_button_target' => '1',
            'more_button_link_description' => 'Ann&#039;s album',
        ]),
        'processedNode' => $node,
        'skipCount' => 1,
    ]);
});

it('falls back to the defaults for fields that are missing or of the wrong type', function (array $data): void {
    $output = new BufferedOutput;

    $result = processAcfGallery(
        ['data' => ['gallery_images' => [1, 2]] + $data],
        acfGalleryImporter(['1' => 'a.jpg', '2' => 'b.jpg'], [], $output),
        $output
    );

    expect($result['html'] ?? null)->toBe(acfGallerySet([]));
})->with([
    'no optional fields' => [[]],
    'fields of the wrong type' => [[
        'gallery_heading' => ['Team'],
        'gallery_description' => ['text'],
        'gallery_more' => true,
        'gallery_more_button_url' => 'https://example.test/more',
    ]],
    'empty or off values' => [[
        'gallery_description' => '',
        'gallery_more' => '0',
        'gallery_more_button_url' => ['url' => '', 'target' => '_self'],
    ]],
    'a description of blank paragraphs' => [[
        'gallery_description' => "  \n\n \r\n\r\n ",
    ]],
]);

it('reads a heading given as a number, and turns more on for the number 1', function (): void {
    $output = new BufferedOutput;

    $result = processAcfGallery(
        ['data' => ['gallery_images' => [1, 2], 'gallery_heading' => 2026, 'gallery_more' => 1]],
        acfGalleryImporter(['1' => 'a.jpg', '2' => 'b.jpg'], [], $output),
        $output
    );

    expect($result['html'] ?? null)->toBe(acfGallerySet(['heading' => '2026', 'more_query' => '1']));
});

it('reads comma-separated image ids', function (): void {
    $output = new BufferedOutput;

    $result = processAcfGallery(
        ['data' => ['gallery_images' => ' 1, 2 ,, 3 ,']],
        acfGalleryImporter(['1' => 'a.jpg', '2' => 'b.jpg', '3' => 'c.jpg'], [], $output),
        $output
    );

    expect($result['html'] ?? null)->toBe(acfGallerySet(['images' => '[&quot;a.jpg&quot;,&quot;b.jpg&quot;,&quot;c.jpg&quot;]']));
});

it('warns and returns an empty gallery when there are no image ids', function (array $attributes): void {
    ProcessingContext::setWordPressPageLink('https://example.test/about/');
    $warnings = acfGalleryWarnings();
    /** @var AssetImporter&MockInterface $importer */
    $importer = Mockery::mock(AssetImporter::class);
    $importer->shouldNotReceive('importImage');
    $node = new DOMComment(' wp:acf/uams-gallery {} /');

    $result = processAcfGallery($attributes, $importer, new BufferedOutput, [], $node);

    expect($result)->toBe(['html' => acfGalleryEmptySet(), 'processedNode' => $node, 'skipCount' => 1])
        ->and($warnings->getArrayCopy())->toBe([[
            'AcfGalleryBlockProcessor::process: No image IDs found in ACF gallery block (from entry: https://example.test/about/)',
            [
                'site' => 'default',
                'pageSlug' => 'about',
                'attributes' => $attributes,
                'entry_url' => 'https://example.test/about/',
            ],
        ]]);
})->with([
    'no data' => [[]],
    'data that is not a list' => [['data' => 'gallery']],
    'no gallery_images' => [['data' => ['gallery_heading' => 'Team']]],
    'empty gallery_images string' => [['data' => ['gallery_images' => '']]],
    'gallery_images of commas' => [['data' => ['gallery_images' => ' , ,']]],
    'gallery_images of unusable values' => [['data' => ['gallery_images' => [null, '', 1.5]]]],
    'gallery_images that is a number' => [['data' => ['gallery_images' => 12]]],
]);

it('warns and returns an empty gallery when fewer than two images import', function (array $paths, int $count): void {
    $warnings = acfGalleryWarnings();
    $output = new BufferedOutput;
    $node = new DOMComment(' wp:acf/uams-gallery {} /');

    $result = processAcfGallery(
        ['data' => ['gallery_images' => array_map('strval', array_keys($paths))]],
        acfGalleryImporter($paths, [], $output),
        $output,
        [],
        $node
    );

    expect($result)->toBe(['html' => acfGalleryEmptySet(), 'processedNode' => $node, 'skipCount' => 1])
        ->and($warnings->getArrayCopy())->toBe([[
            'AcfGalleryBlockProcessor::process: Insufficient images for ACF gallery block',
            ['site' => 'default', 'pageSlug' => 'about', 'image_count' => $count, 'expected_minimum' => 2],
        ]]);
})->with([
    'one of two imports' => [['1' => 'a.jpg', '2' => null], 1],
    'a single image' => [['1' => 'a.jpg'], 1],
    'none import' => [['1' => null, '2' => ''], 0],
]);
