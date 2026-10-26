<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\SameSitePolicy;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Tiptap\SetMarkupNonce;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\WpGalleryBlockProcessor;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * Tests for WpGalleryBlockProcessor, which turns a core `wp:gallery` block into a Bard `gallery` set.
 *
 * `canProcess()` accepts a `wp:gallery` comment carrying a JSON object on one line.
 * `process()` reads the first figure after the comment (bare, or inside a following
 * div), takes the image ids from the `ids` attribute or else from the figure's
 * `img[data-id]` elements, imports each one, and writes a gallery set when at least
 * two images import. A missing figure, no ids and too few images each log a warning
 * and return null.
 *
 * @command  php vendor/bin/pest tests/Integration/WpGalleryBlockProcessorTest.php
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
 * Parse an HTML fragment and return its first top-level node.
 *
 * @param  string  $html  The fragment to parse
 * @return DOMNode The fragment's first node
 */
function galleryOpeningNode(string $html): DOMNode
{
    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument;
    $document->loadHTML('<div id="root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $first = $document->getElementById('root')?->firstChild;

    if (! $first instanceof DOMNode) {
        throw new RuntimeException('Fixture has no nodes');
    }

    return $first;
}

/**
 * An asset importer with image uploads on that imports the given ids to the given paths.
 *
 * Every importImage() call must carry the gallery's site, attachments, output and
 * import settings.
 *
 * @param  array<string, ?string>  $paths  What importImage() returns, keyed by image id
 * @param  array<int|string, mixed>  $attachments  The attachments the processor must pass on
 * @param  BufferedOutput  $output  The output the processor must pass on
 * @return AssetImporter The mocked importer
 */
function galleryImporter(array $paths, array $attachments, BufferedOutput $output): AssetImporter
{
    $importer = Mockery::mock(AssetImporter::class);
    $importer->shouldReceive('getUploadImages')->andReturnTrue();

    foreach ($paths as $id => $path) {
        $importer->shouldReceive('importImage')
            ->once()
            ->with((string) $id, 'default', $attachments, $output, SameSitePolicy::ALWAYS_IMPORT, null, 'images', false)
            ->andReturn($path);
    }

    return $importer;
}

/**
 * Run the processor over a node.
 *
 * @param  DOMNode  $node  The opening comment
 * @param  array<int|string, mixed>  $attributes  The block's attributes
 * @param  AssetImporter  $importer  The asset importer
 * @param  BufferedOutput  $output  The console output
 * @param  array<int|string, mixed>  $attachments  The attachments
 * @param  ?string  $pageSlug  The page slug
 * @return ?array<int|string, mixed> The processor's result
 */
function processGallery(DOMNode $node, array $attributes, AssetImporter $importer, BufferedOutput $output, array $attachments = [], ?string $pageSlug = null): ?array
{
    return (new WpGalleryBlockProcessor)->process($node, $attributes, 'default', $importer, $output, $attachments, [], $pageSlug);
}

/**
 * Collect every Log::warning() call as a [message, context] pair.
 *
 * @return ArrayObject<int, array{0: string, 1: array<string, mixed>}> The calls, filled as they happen
 */
function galleryWarnings(): ArrayObject
{
    /** @var ArrayObject<int, array{0: string, 1: array<string, mixed>}> $warnings */
    $warnings = new ArrayObject;

    Log::shouldReceive('warning')->andReturnUsing(function (string $message, array $context) use ($warnings): void {
        $warnings->append([$message, $context]);
    });

    return $warnings;
}

/**
 * The gallery set markup for an already-escaped images attribute.
 *
 * @param  string  $images  The data-set-attr-images value, already escaped
 * @return string The expected markup
 */
function gallerySet(string $images): string
{
    return '<div data-set-type="gallery"'.SetMarkupNonce::attribute()
        .' data-set-attr-heading="Image Gallery"'
        ." data-set-attr-images='".$images."'"
        .' data-set-attr-more_query="false"></div>';
}

it('accepts only a one-line wp:gallery comment carrying a JSON object', function (string $html, bool $expected): void {
    expect((new WpGalleryBlockProcessor)->canProcess(galleryOpeningNode($html), 'default', null))->toBe($expected);
})->with([
    'block with ids' => ['<!-- wp:gallery {"ids":[1,2]} -->', true],
    'empty object' => ['<!-- wp:gallery {} -->', true],
    'no attributes' => ['<!-- wp:gallery -->', false],
    'attributes over several lines' => ["<!-- wp:gallery {\"ids\":\n[1,2]} -->", false],
    'closing comment' => ['<!-- /wp:gallery -->', false],
    'another block' => ['<!-- wp:image {"id":1} -->', false],
    'text after the object' => ['<!-- wp:gallery {"ids":[1]} x -->', false],
    'element, not comment' => ['<p>wp:gallery {"ids":[1]}</p>', false],
]);

it('imports the ids from the attributes and writes a gallery set', function (): void {
    $attachments = ['attachment list'];
    $output = new BufferedOutput;
    $node = galleryOpeningNode(
        '<!-- wp:gallery {"ids":[1,2]} -->'
        .'<figure class="wp-block-gallery first"><img data-id="9"></figure>'
        .'<figure class="second"></figure>'
    );

    $result = processGallery(
        $node,
        ['ids' => [1, '2', null, ['3'], 4, 5]],
        galleryImporter(['1' => 'images/a.jpg', '2' => "images/o'b.jpg", '4' => null, '5' => ''], $attachments, $output),
        $output,
        $attachments
    );

    expect($result)->toBeArray()
        ->and($result['html'] ?? null)->toBe(gallerySet('[&quot;images\/a.jpg&quot;,&quot;images\/o&#039;b.jpg&quot;]'))
        ->and($result['skipCount'] ?? null)->toBe(2)
        ->and($result['processedNode'] ?? null)->toBeInstanceOf(DOMElement::class)
        ->and(($result['processedNode'] ?? null)?->getAttribute('class'))->toBe('wp-block-gallery first');
});

it('finds the figure inside a following div', function (): void {
    $output = new BufferedOutput;
    $node = galleryOpeningNode(
        '<!-- wp:gallery {"ids":[1,2]} -->'
        .'<div class="wrap"><figure class="wrapped"></figure><figure class="later"></figure></div>'
        .'<figure class="sibling"></figure>'
    );

    $result = processGallery($node, ['ids' => [1, 2]], galleryImporter(['1' => 'a.jpg', '2' => 'b.jpg'], [], $output), $output);

    expect(($result['processedNode'] ?? null)?->getAttribute('class'))->toBe('wrapped');
});

it('reads the ids from the figure images when the attributes have none', function (array $attributes): void {
    $output = new BufferedOutput;
    $node = galleryOpeningNode(
        '<!-- wp:gallery {} -->'
        .'<figure><img data-id="7"><img data-id=""><img data-id="abc"><img src="x.jpg"><figure><img data-id="8"></figure></figure>'
    );

    $result = processGallery($node, $attributes, galleryImporter(['7' => 'seven.jpg', '8' => 'eight.jpg'], [], $output), $output);

    expect($result['html'] ?? null)->toBe(gallerySet('[&quot;seven.jpg&quot;,&quot;eight.jpg&quot;]'));
})->with([
    'no ids attribute' => [[]],
    'empty ids' => [['ids' => []]],
    'ids that are not a list' => [['ids' => '7,8']],
]);

it('uses the attachment URL when image uploads are off', function (): void {
    config(['wordpress-import.upload_images' => false, 'wordpress-import.upload_files' => true]);
    $output = new BufferedOutput;
    $attachments = [];

    foreach ([11 => 'https://example.test/a.jpg', 12 => 'https://example.test/b.jpg'] as $id => $url) {
        $attachments[] = new SimpleXMLElement(
            '<item xmlns:wp="http://wordpress.org/export/1.2/">'
            ."<wp:post_id>{$id}</wp:post_id><wp:attachment_url>{$url}</wp:attachment_url></item>"
        );
    }

    $result = processGallery(
        galleryOpeningNode('<!-- wp:gallery {"ids":[11,12]} --><figure></figure>'),
        ['ids' => [11, 12]],
        new AssetImporter,
        $output,
        $attachments
    );

    expect($result['html'] ?? null)->toBe(gallerySet(
        '[&quot;https:\/\/example.test\/a.jpg&quot;,&quot;https:\/\/example.test\/b.jpg&quot;]'
    ));
});

it('warns and returns null when fewer than two images import', function (array $paths, int $count): void {
    $warnings = galleryWarnings();
    $output = new BufferedOutput;

    $result = processGallery(
        galleryOpeningNode('<!-- wp:gallery {} --><figure></figure>'),
        ['ids' => array_map('intval', array_keys($paths))],
        galleryImporter($paths, [], $output),
        $output,
        [],
        'about'
    );

    expect($result)->toBeNull()
        ->and($warnings->getArrayCopy())->toBe([[
            'WpGalleryBlockProcessor::process: Insufficient images for gallery block',
            ['site' => 'default', 'pageSlug' => 'about', 'image_count' => $count, 'expected_minimum' => 2],
        ]]);
})->with([
    'one of two imports' => [['1' => 'a.jpg', '2' => null], 1],
    'a single image' => [['1' => 'a.jpg'], 1],
    'none import' => [['1' => null, '2' => ''], 0],
]);

it('warns and returns null when no image ids can be found', function (): void {
    ProcessingContext::setWordPressPageLink('https://example.test/about/');
    $warnings = galleryWarnings();
    $importer = Mockery::mock(AssetImporter::class);
    $importer->shouldNotReceive('importImage');

    $result = processGallery(
        galleryOpeningNode('<!-- wp:gallery {} --><figure><img src="x.jpg"></figure>'),
        ['linkTo' => 'none'],
        $importer,
        new BufferedOutput
    );

    expect($result)->toBeNull()
        ->and($warnings->getArrayCopy())->toBe([[
            'WpGalleryBlockProcessor::process: No image IDs found in wp:gallery block (from entry: https://example.test/about/)',
            [
                'site' => 'default',
                'pageSlug' => 'none',
                'attributes' => ['linkTo' => 'none'],
                'entry_url' => 'https://example.test/about/',
            ],
        ]]);
});

it('warns with a snippet of the next sibling when no figure follows the comment', function (string $next, string $snippet): void {
    $warnings = galleryWarnings();
    $importer = Mockery::mock(AssetImporter::class);
    $importer->shouldNotReceive('importImage');

    $result = processGallery(galleryOpeningNode('<!-- wp:gallery {"ids":[1]} -->'.$next), ['ids' => [1]], $importer, new BufferedOutput);

    expect($result)->toBeNull()
        ->and($warnings->getArrayCopy())->toBe([[
            'WpGalleryBlockProcessor::process: No valid wp:gallery figure found after comment',
            ['site' => 'default', 'pageSlug' => 'none', 'attributes' => ['ids' => [1]], 'next_sibling_snippet' => $snippet],
        ]]);
})->with([
    'no sibling' => ['', 'none'],
    'a short sibling' => ['<p>Caption</p>', '<p>Caption</p>'],
    'a sibling of exactly 100 characters' => [
        '<p>'.str_repeat('x', 93).'</p>',
        '<p>'.str_repeat('x', 93).'</p>',
    ],
    'a sibling of 101 characters' => [
        '<p>'.str_repeat('x', 94).'</p>',
        '<p>'.str_repeat('x', 94).'</p...',
    ],
]);

it('returns null for a comment that belongs to no document', function (): void {
    $warnings = galleryWarnings();

    expect(processGallery(new DOMComment(' wp:gallery {} '), ['ids' => [1, 2]], Mockery::mock(AssetImporter::class), new BufferedOutput))->toBeNull()
        ->and($warnings->getArrayCopy())->toBe([]);
});
