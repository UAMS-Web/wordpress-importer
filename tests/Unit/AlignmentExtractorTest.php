<?php

declare(strict_types=1);

/**
 * Tests that `AlignmentExtractor` sets an image's alignment from the block
 * attribute first, then the figure's class, then the image's class, and centers
 * it when none says otherwise (#1314).
 *
 * @command  php vendor/bin/pest tests/Unit/AlignmentExtractorTest.php
 */

use Symfony\Component\Console\Output\NullOutput;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\ImageProcessor\AlignmentExtractor;

/**
 * An element with an optional class, owned by a fresh document.
 *
 * @param  string  $tag  The element name
 * @param  ?string  $class  The class attribute, or null for none
 * @return DOMElement The element
 */
function alignmentElement(string $tag, ?string $class): DOMElement
{
    $element = (new DOMDocument)->createElement($tag);

    if ($class !== null) {
        $element->setAttribute('class', $class);
    }

    return $element;
}

/**
 * Run the extractor over a context.
 *
 * @param  array<string, mixed>  $context  The image context
 * @return array<string, mixed> The context with `align` set
 */
function alignmentProcess(array $context): array
{
    $assetImporter = (new ReflectionClass(AssetImporter::class))->newInstanceWithoutConstructor();

    /** @var array<string, mixed> $result */
    $result = (new AlignmentExtractor)->process($context, 'default', $assetImporter, new NullOutput);

    return $result;
}

it('takes the alignment from the block attribute first', function (string $align, string $expected): void {
    $result = alignmentProcess([
        'blockAttributes' => ['align' => $align],
        'figure' => alignmentElement('figure', 'alignleft'),
        'img' => alignmentElement('img', 'alignright'),
    ]);

    expect($result['align'] ?? null)->toBe($expected);
})->with([
    'right' => ['right', 'alignright'],
    'full' => ['full', 'alignwide'],
    'center' => ['center', 'aligncenter'],
]);

it('takes the alignment from the figure class when the block has none', function (mixed $blockAttributes): void {
    $context = [
        'figure' => alignmentElement('figure', 'wp-block-image alignright size-large'),
        'img' => alignmentElement('img', 'alignleft'),
    ];

    if ($blockAttributes !== 'absent') {
        $context['blockAttributes'] = $blockAttributes;
    }

    expect(alignmentProcess($context)['align'] ?? null)->toBe('alignright');
})->with([
    'no block attributes' => ['absent'],
    'an empty align' => [['align' => '']],
    'a non-string align' => [['align' => 3]],
    'block attributes that are not an array' => ['right'],
]);

it('takes the alignment from the image class when the figure has none', function (mixed $figure): void {
    $context = ['img' => alignmentElement('img', 'alignleft wp-image-1953')];

    if ($figure !== 'absent') {
        $context['figure'] = $figure;
    }

    expect(alignmentProcess($context)['align'] ?? null)->toBe('alignleft');
})->with([
    'no figure' => ['absent'],
    'a figure with no class' => [alignmentElement('figure', null)],
    'a figure that is not an element' => ['<figure class="alignright">'],
]);

it('centers the image when nothing gives an alignment', function (array $context): void {
    /** @var array<string, mixed> $context */
    expect(alignmentProcess($context)['align'] ?? null)->toBe('aligncenter');
})->with([
    'an empty context' => [[]],
    'an image with no class' => [['img' => alignmentElement('img', null)]],
    'an image that is not an element' => [['img' => '<img class="alignright">']],
    'a class naming no alignment' => [['img' => alignmentElement('img', 'wp-image-1953')]],
]);

it('keeps the rest of the context as it was', function (): void {
    $img = alignmentElement('img', 'alignright');

    $result = alignmentProcess(['img' => $img, 'src' => 'https://example.org/a.jpg', 'align' => 'old']);

    expect($result)->toBe(['img' => $img, 'src' => 'https://example.org/a.jpg', 'align' => 'alignright']);
});
