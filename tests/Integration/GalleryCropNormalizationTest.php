<?php

declare(strict_types=1);

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\Blocks\GalleryParser;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

/**
 * Tests the gallery `crop` value normalization (issue wordpress-importer-archive#430).
 *
 * The `crop` radio in `uamsweb_block_gallery.yaml` declares NUMERIC option keys —
 * `1`, `0`, `1.7778`, `1.3333`, `0.75` — while WordPress stores the value as a
 * string and spells the None option `none`. Emitting the string form left the
 * stored value's type diverging from the blueprint, so the Control Panel radio did
 * not mark the imported option as selected on edit.
 *
 * The end-to-end proof lives in `tests/Feature/GalleryBlockImportRenderTest.php`,
 * which renders the parser's output through the real partial — but that tier is
 * host-app-bound and this package's CI does not run it. These pure-unit cases keep
 * the conversion itself covered by `composer test`, so a regression is caught by
 * the local gate rather than only by a host run.
 *
 * It sits in the Integration band because the non-numeric fallback logs a warning
 * through `LogContextHelper`, which needs the booted container. Both bands run in
 * this package's CI, so the coverage goal is unaffected.
 *
 * @command  php vendor/bin/pest tests/Integration/GalleryCropNormalizationTest.php
 */
uses(StatamicTestCase::class);

/**
 * Invoke the private crop normalizer via reflection.
 */
function galleryNormalizeCrop(string $cropValue): int|float
{
    $method = new ReflectionMethod(GalleryParser::class, 'normalizeCropValue');
    $method->setAccessible(true);

    $result = $method->invoke(new GalleryParser, $cropValue, 'site');

    return \is_int($result) || \is_float($result) ? $result : -1;
}

it('maps every crop value the export corpus carries onto its radio key', function (
    string $wordPressValue,
    int|float $expected
): void {
    // These five are the only `gallery_crop` values in the corpus's WordPress/*.xml
    $result = galleryNormalizeCrop($wordPressValue);

    expect($result)->toBe($expected);
})->with([
    'none' => ['none', 0],
    '1:1 square' => ['1', 1],
    '16:9 landscape' => ['1.7778', 1.7778],
    '4:3 landscape' => ['1.3333', 1.3333],
    '3:4 portrait' => ['0.75', 0.75],
])->group('gallery');

it('emits an integer for a whole-number key and a float for a ratio', function (): void {
    // The fieldset splits the two — `1` and `0` are integers, the ratios floats — so
    // an int-valued string must not become a float.
    expect(galleryNormalizeCrop('1'))->toBeInt();
    expect(galleryNormalizeCrop('none'))->toBeInt();
    expect(galleryNormalizeCrop('1.7778'))->toBeFloat();
    expect(galleryNormalizeCrop('0.75'))->toBeFloat();
})->group('gallery');

it('never emits the string form the blueprint cannot match', function (string $wordPressValue): void {
    $result = galleryNormalizeCrop($wordPressValue);

    expect($result)->not->toBeString();
})->with(['none', '1', '1.7778', '1.3333', '0.75', ''])->group('gallery');

it('treats a blank or padded crop value as None', function (string $wordPressValue): void {
    expect(galleryNormalizeCrop($wordPressValue))->toBe(0);
})->with([
    'empty' => [''],
    'whitespace' => ['   '],
    'padded none' => ['  none  '],
])->group('gallery');

it('falls back to None for a value that cannot be an option key', function (string $wordPressValue): void {
    // A non-numeric value could not match any declared option, so None — which is
    // also the field's own default — is the only safe landing.
    expect(galleryNormalizeCrop($wordPressValue))->toBe(0);
})->with([
    'word' => ['square'],
    'markup' => ['<script>'],
    'hex' => ['0x10'],
    'trailing unit' => ['16px'],
])->group('gallery');

it('casts a numeric key the blueprint might add later', function (): void {
    // The conversion casts rather than whitelisting, so a crop key added to the
    // fieldset in future still imports with the right type.
    expect(galleryNormalizeCrop('2.5'))->toBe(2.5);
    expect(galleryNormalizeCrop('3'))->toBe(3);
})->group('gallery');
