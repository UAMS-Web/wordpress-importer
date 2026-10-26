<?php

declare(strict_types=1);

/**
 * Tests that a gallery which cannot satisfy the block's required-field contract
 * is reported at import time rather than failing silently at render.
 *
 * `uamsweb_block_gallery` renders only when every required field has a value, so
 * a gallery missing `block_description` or carrying a count outside 2-6 imports
 * "successfully" and then displays nothing. The decision on wordpress-importer-archive#512 is to import it
 * anyway and report it — skipping would delete content the source site
 * displayed, and synthesizing a description would invent editorial copy.
 *
 * @command  php vendor/bin/pest tests/Integration/GalleryContractReportingTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\GalleryParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * It sits in the Integration band because the assembly step generates a Statamic
 * `RowId` and reports through `LogContextHelper`, both of which need the booted
 * container.
 */
uses(StatamicTestCase::class);

/**
 * Build the assembled replicator set for a gallery and return the warnings raised.
 *
 * Drives the private assembly step through reflection so the assertion sees the
 * FINAL set — `block_description` present or absent, `images` counted — which is
 * exactly what the block's contract is evaluated against.
 *
 * @param  array<int|string, mixed>|null  $description  Bard description payload, or null when absent
 * @param  int  $imageCount  How many imported images the gallery carries
 * @return array<int|string, mixed> The assembled replicator set
 */
function galleryContractSet(?array $description, int $imageCount): array
{
    $parser = new GalleryParser;
    $method = (new ReflectionClass($parser))->getMethod('buildReplicatorSet');
    $method->setAccessible(true);

    $fields = [
        'heading' => 'A Gallery',
        'hideHeading' => false,
        'description' => $description,
        'columns' => '3',
        'crop' => '1',
        'backgroundColor' => 'bg-auto',
        'modal' => true,
        'moreQuery' => false,
        'moreHeading' => '',
        'moreButtonText' => '',
        'moreButtonUrl' => '',
        'moreButtonTarget' => false,
        'moreButtonDescription' => '',
    ];

    $images = [];

    for ($i = 0; $i < $imageCount; $i++) {
        $images[] = "site::images/photo-{$i}.jpg";
    }

    $result = $method->invoke(
        $parser,
        $fields,
        $images,
        'supply_chain',
        'https://example.uams.edu/a-page/'
    );

    // A non-array result is a broken fixture; fail loudly (and narrow for PHPStan)
    if (! \is_array($result)) {
        throw new RuntimeException('Expected buildReplicatorSet() to return an array.');
    }

    return $result;
}

/**
 * Build a gallery replicator set and return the warnings it raised.
 *
 * @param  array<int|string, mixed>|null  $description
 * @return list<array<int|string, mixed>>
 */
function galleryContractWarnings(?array $description, int $imageCount): array
{
    galleryContractSet($description, $imageCount);

    $warnings = ImportWarningManager::getWarnings('content', 'unrenderable_gallery_blocks');

    $rows = [];

    foreach ($warnings as $warning) {
        if (\is_array($warning)) {
            $rows[] = $warning;
        }
    }

    return $rows;
}

/**
 * A minimal Bard description payload, as processWysiwyg() produces.
 *
 * @return array<int|string, mixed>
 */
function galleryDescription(): array
{
    return [
        [
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => 'About this gallery.']],
        ],
    ];
}

beforeEach(function (): void {
    ImportWarningManager::clearWarnings();
});

afterEach(function (): void {
    ImportWarningManager::clearWarnings();
});

it('stays silent for a gallery that satisfies the contract', function (int $imageCount): void {
    expect(galleryContractWarnings(galleryDescription(), $imageCount))->toBeEmpty();
})->with([
    'two images' => [2],
    'three images' => [3],
    'six images' => [6],
]);

it('reports a gallery carrying no description', function (): void {
    // 198 of the 307 corpus galleries (64%) have no `gallery_description`, so they
    // import and then render nothing. wordpress-importer-archive#512
    $warnings = galleryContractWarnings(null, 3);

    expect($warnings)->toHaveCount(1);
    expect($warnings[0]['missing_fields'] ?? null)->toBe(['block_description']);
    expect($warnings[0]['block_type'] ?? null)->toBe('uamsweb_block_gallery');
    expect($warnings[0]['heading'] ?? null)->toBe('A Gallery');
    expect($warnings[0]['image_count'] ?? null)->toBe(3);
});

it('reports a single-image gallery without dropping it', function (): void {
    // wordpress-importer-archive#291 keeps the importer's one-image floor; the block still wants 2-6, so the
    // disagreement is reported rather than resolved by deleting the gallery. wordpress-importer-archive#512
    $warnings = galleryContractWarnings(galleryDescription(), 1);

    expect($warnings)->toHaveCount(1);
    expect($warnings[0]['missing_fields'] ?? null)->toBe(['images']);
    expect($warnings[0]['image_count'] ?? null)->toBe(1);
});

it('reports both problems on one gallery in a single warning', function (): void {
    $warnings = galleryContractWarnings(null, 1);

    expect($warnings)->toHaveCount(1);
    expect($warnings[0]['missing_fields'] ?? null)->toBe(['block_description', 'images']);
});

it('reports a gallery carrying more images than the block renders', function (): void {
    $warnings = galleryContractWarnings(galleryDescription(), 7);

    expect($warnings)->toHaveCount(1);
    expect($warnings[0]['missing_fields'] ?? null)->toBe(['images']);
    expect($warnings[0]['image_count'] ?? null)->toBe(7);
});

it('reports an empty description payload as missing', function (): void {
    // processWysiwyg() returns null for empty content, but an empty array would
    // equally fail the block's required-field guard.
    $warnings = galleryContractWarnings([], 3);

    expect($warnings)->toHaveCount(1);
    expect($warnings[0]['missing_fields'] ?? null)->toBe(['block_description']);
});

it('still builds the gallery it reports', function (): void {
    // The decision is warn-and-import: the block has to survive so an editor can
    // finish it. Skipping would silently delete content the source site showed.
    $result = galleryContractSet(null, 1);

    expect($result['type'] ?? null)->toBe('uamsweb_block_gallery');
    expect($result['block_heading'] ?? null)->toBe('A Gallery');
    expect($result['images'] ?? null)->toBe(['site::images/photo-0.jpg']);
    expect($result['enabled'] ?? null)->toBeTrue();

    // The warning is the signal; the block is not silently completed
    expect($result)->not->toHaveKey('block_description');
    expect(ImportWarningManager::getWarnings('content', 'unrenderable_gallery_blocks'))->toHaveCount(1);
});

it('does not invent a description for the gallery it reports', function (): void {
    // Synthesizing editorial copy across 198 entries was rejected. wordpress-importer-archive#512
    $warnings = galleryContractWarnings(null, 3);

    expect($warnings)->toHaveCount(1);

    $reason = $warnings[0]['reason'] ?? '';
    expect($reason)->toBeString();
    expect($reason)->toContain('imports but displays nothing');
});
