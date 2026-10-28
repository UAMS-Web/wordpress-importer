<?php

declare(strict_types=1);

/**
 * Tests that `BardSetBuilder` turns an imported image into a Bard image set,
 * centered unless the context says otherwise, with a caption and an alt
 * override only when they are non-empty (#1314).
 *
 * @command  php vendor/bin/pest tests/Unit/BardSetBuilderTest.php
 */

use Symfony\Component\Console\Output\NullOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\ImageProcessor\BardSetBuilder;

/**
 * Run the builder over a context.
 *
 * @param  array<mixed>  $context  The image context
 * @return array<string, mixed> The context after building
 */
function bardSetBuilderBuild(array $context): array
{
    $assetImporter = new ReflectionClass(AssetImporter::class)->newInstanceWithoutConstructor();

    /** @var array<string, mixed> $result */
    $result = new BardSetBuilder()->process($context, 'default', $assetImporter, new NullOutput);

    return $result;
}

/**
 * The values of the set the builder made.
 *
 * @param  array<string, mixed>  $context  The image context
 * @return mixed The set's values
 */
function bardSetBuilderValues(array $context): mixed
{
    $set = bardSetBuilderBuild($context)['imageSet'] ?? null;
    $attrs = \is_array($set) ? $set['attrs'] ?? null : null;

    return \is_array($attrs) ? $attrs['values'] ?? null : null;
}

it('builds an image set with a fresh row id and keeps the rest of the context', function (): void {
    $result = bardSetBuilderBuild(['imagePath' => 'images/a.jpg', 'keep' => 'me']);
    $set = $result['imageSet'] ?? null;

    expect($result['keep'] ?? null)->toBe('me')
        ->and($set)->toBeArray()
        ->and(\is_array($set) ? $set['type'] ?? null : null)->toBe('set');

    $attrs = \is_array($set) ? $set['attrs'] ?? null : null;

    expect($attrs)->toBeArray()
        ->and(\is_array($attrs) ? \array_keys($attrs) : null)->toBe(['id', 'values'])
        ->and(\is_array($attrs) ? $attrs['id'] : null)->toBeString()->toMatch('/^[A-Za-z0-9]{8}$/')
        ->and(\is_array($attrs) ? $attrs['values'] : null)->toBe(['type' => 'image', 'image' => 'images/a.jpg', 'align' => 'aligncenter']);
});

it('keeps the alignment the context sets', function (): void {
    expect(bardSetBuilderValues(['imagePath' => 'images/a.jpg', 'align' => 'alignleft']))
        ->toBe(['type' => 'image', 'image' => 'images/a.jpg', 'align' => 'alignleft']);
});

it('adds a non-empty caption and alt override', function (): void {
    expect(bardSetBuilderValues(['imagePath' => 'images/a.jpg', 'caption' => 'A clinic', 'altOverride' => 'A nurse']))
        ->toBe(['type' => 'image', 'image' => 'images/a.jpg', 'align' => 'aligncenter', 'caption' => 'A clinic', 'alt_override' => 'A nurse']);
});

it('leaves out an empty caption and an empty or null alt override', function (array $context): void {
    expect(bardSetBuilderValues(['imagePath' => 'images/a.jpg'] + $context))
        ->toBe(['type' => 'image', 'image' => 'images/a.jpg', 'align' => 'aligncenter']);
})->with([
    'an empty caption' => [['caption' => '']],
    'an empty alt override' => [['altOverride' => '']],
    'a null alt override' => [['altOverride' => null]],
]);

it('gives each set its own row id', function (): void {
    $first = bardSetBuilderBuild(['imagePath' => 'images/a.jpg'])['imageSet'] ?? null;
    $second = bardSetBuilderBuild(['imagePath' => 'images/a.jpg'])['imageSet'] ?? null;

    expect($first)->not->toBe($second);
});

it('leaves the context alone when there is no image path', function (array $context): void {
    expect(bardSetBuilderBuild($context))->toBe($context);
})->with([
    'no image path' => [['caption' => 'A clinic']],
    'a null image path' => [['imagePath' => null, 'caption' => 'A clinic']],
]);
