<?php

declare(strict_types=1);

use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\TextProcessor;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/*
 * The handle an imported image set carries its alt text override under (#141).
 *
 * The host app's image set declares `alt_override`, and its templates render
 * `alt_override ?? image:alt`, so any other key leaves the override unread. The
 * top-level path round-trips the set through `data-set-attr-*` attributes, whose
 * names the DOM lowercases, so a camelCase key arrived there as `altoverride` and
 * on the InnerBlocks path as `altOverride`. Both paths run through the real image
 * pipeline here, with image uploads off so the source URL stands in for an asset.
 */

uses(StatamicTestCase::class);

beforeEach(function (): void {
    (new ReflectionProperty(TextProcessor::class, 'cachedProcessors'))->setValue(null, []);
});

afterEach(function (): void {
    (new ReflectionProperty(TextProcessor::class, 'cachedProcessors'))->setValue(null, []);
});

/**
 * The `values` of every set in a Bard node list, at any depth.
 *
 * @param  mixed  $node  A node, or a list of them
 * @return list<array<int|string, mixed>>
 */
function altOverrideSetValues(mixed $node): array
{
    if (! is_array($node)) {
        return [];
    }

    $found = [];

    // A set node carries its fields under attrs.values
    if (($node['type'] ?? null) === 'set' && is_array($node['attrs']['values'] ?? null)) {
        $found[] = $node['attrs']['values'];
    }

    foreach ($node as $child) {
        array_push($found, ...altOverrideSetValues($child));
    }

    return $found;
}

/**
 * Run a `wp:image` block whose alt text differs from its attachment's through
 * `processForBard()`, and return the values of every set it produced.
 *
 * @return list<array<int|string, mixed>>
 */
function altOverrideImageSets(bool $innerBlocks): array
{
    $importer = Mockery::mock(AssetImporter::class);
    $importer->shouldReceive('getUploadImages')->andReturnFalse();
    $importer->shouldIgnoreMissing();

    $html = '<!-- wp:image {"id":5} --><figure class="wp-block-image"><img src="https://example.com/a.jpg" alt="A nurse at work"/></figure><!-- /wp:image -->';

    return altOverrideSetValues(
        TextProcessor::processForBard($html, 'default', $importer, new BufferedOutput, false, $innerBlocks)
    );
}

it('writes the override under alt_override', function (bool $innerBlocks): void {
    $sets = altOverrideImageSets($innerBlocks);

    expect($sets)->toHaveCount(1)
        ->and($sets[0]['type'] ?? null)->toBe('image')
        ->and($sets[0]['alt_override'] ?? null)->toBe('A nurse at work')
        ->and($sets[0])->not->toHaveKey('altOverride')
        ->and($sets[0])->not->toHaveKey('altoverride');
})->with([
    'top-level content' => [false],
    'InnerBlocks' => [true],
]);

it('writes no override when the image has no alt text', function (bool $innerBlocks): void {
    $importer = Mockery::mock(AssetImporter::class);
    $importer->shouldReceive('getUploadImages')->andReturnFalse();
    $importer->shouldIgnoreMissing();

    $html = '<!-- wp:image {"id":5} --><figure class="wp-block-image"><img src="https://example.com/a.jpg" alt=""/></figure><!-- /wp:image -->';
    $sets = altOverrideSetValues(
        TextProcessor::processForBard($html, 'default', $importer, new BufferedOutput, false, $innerBlocks)
    );

    expect($sets)->toHaveCount(1)
        ->and($sets[0]['image'] ?? null)->toBe('https://example.com/a.jpg')
        ->and($sets[0])->not->toHaveKey('alt_override');
})->with([
    'top-level content' => [false],
    'InnerBlocks' => [true],
]);
