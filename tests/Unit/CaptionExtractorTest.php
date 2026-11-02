<?php

declare(strict_types=1);

/**
 * Tests for CaptionExtractor, the image-pipeline step that sets
 * `$context['caption']` from the first `<figcaption>` in the figure (#1314).
 *
 * A `[caption]` shortcode carries an attachment ID and no figure, and its
 * caption is read elsewhere, so the step leaves it empty.
 *
 * @command  php vendor/bin/pest tests/Unit/CaptionExtractorTest.php
 */

use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\ImageProcessor\CaptionExtractor;

/**
 * Parse a fragment and return its first `<figure>`.
 *
 * @param  string  $html  The fragment, containing a figure
 * @return DOMElement The figure
 */
function captionFigure(string $html): DOMElement
{
    $document = new DOMDocument;

    // libxml's HTML parser warns about HTML5 tags such as <figure>, so collect
    // the warnings here rather than depend on the mode an earlier test left on,
    // and put the caller's mode back afterwards
    $previousLibxmlMode = libxml_use_internal_errors(true);

    try {
        $document->loadHTML('<div>'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previousLibxmlMode);
    }

    $figure = $document->getElementsByTagName('figure')->item(0);

    if (! $figure instanceof DOMElement) {
        throw new RuntimeException('Fixture has no figure');
    }

    return $figure;
}

/**
 * Run the step over a context.
 *
 * @param  array<int|string, mixed>  $context  The pipeline context
 * @return array<int|string, mixed> The context the step returns
 */
function extractCaption(array $context): array
{
    return (new CaptionExtractor)->process(
        $context,
        'default',
        (new ReflectionClass(AssetImporter::class))->newInstanceWithoutConstructor(),
        new BufferedOutput
    );
}

it('reads the trimmed text of the first figcaption, keeping the rest of the context', function (): void {
    $figure = captionFigure('<figure><img src="a.jpg"><figcaption>  A <em>caption</em> here  </figcaption><figcaption>Second</figcaption></figure>');

    expect(extractCaption(['figure' => $figure, 'align' => 'alignleft']))->toBe([
        'figure' => $figure,
        'align' => 'alignleft',
        'caption' => 'A caption here',
    ]);
});

it('finds a figcaption nested inside the figure', function (): void {
    $figure = captionFigure('<figure><div><figcaption>Nested</figcaption></div></figure>');

    expect(extractCaption(['figure' => $figure])['caption'] ?? null)->toBe('Nested');
});

it('reads the figure even when block attributes carry an attachment ID', function (): void {
    $figure = captionFigure('<figure><figcaption>Block caption</figcaption></figure>');

    expect(extractCaption(['figure' => $figure, 'blockAttributes' => ['id' => 12]])['caption'] ?? null)->toBe('Block caption');
});

it('sets an empty caption when there is no figcaption to read', function (array $context): void {
    expect(extractCaption($context)['caption'] ?? null)->toBe('');
})->with([
    'figure without a figcaption' => fn (): array => ['figure' => captionFigure('<figure><img src="a.jpg"></figure>')],
    'figure that is not an element' => [['figure' => 'not an element']],
    'no figure and no block attributes' => [[]],
    'no figure, block attributes without an ID' => [['blockAttributes' => ['align' => 'left']]],
    'no figure, block attributes that are not an array' => [['blockAttributes' => 'id=5']],
]);

it('leaves a caption shortcode\'s caption empty, keeping its attachment ID', function (): void {
    expect(extractCaption(['blockAttributes' => ['id' => 42], 'img' => 'kept']))->toBe([
        'blockAttributes' => ['id' => 42],
        'img' => 'kept',
        'caption' => '',
    ]);
});

it('overwrites a caption already in the context', function (): void {
    expect(extractCaption(['caption' => 'stale'])['caption'] ?? null)->toBe('');
});
