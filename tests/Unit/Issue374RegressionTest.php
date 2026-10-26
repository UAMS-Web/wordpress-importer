<?php

declare(strict_types=1);

/**
 * Regression test for issue wordpress-importer-archive#374.
 *
 * In the `rangeMax`/`rangeMin` suppression map the
 * `statamic_attribute.based_on_attribute_value.default` entry should suppress
 * BOTH a literal `false` and a numeric-zero range bound. During the PHPStan
 * cleanup the integer-zero key (originally `false => null`, which PHP coerces
 * to the array key `0`) was wrongly dropped as a "duplicate" of the
 * `'false' => null` string key, so a numeric-zero range bound was no longer
 * suppressed and leaked through as a `max`/`min` attribute.
 *
 * The fix restores `0 => null` alongside `'false' => null` in both blocks.
 *
 * @see https://github.com/.../issues/374
 *
 * @command  php vendor/bin/pest tests/Unit/Issue374RegressionTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FieldtypeMapping;

it('suppresses a numeric-zero rangeMax so no max attribute is emitted', function (int|string $rangeMax): void {
    $result = FieldtypeMapping::mapFieldAttributes([
        'type' => 'number',
        'rangeMax' => $rangeMax,
    ]);

    expect($result)->not->toHaveKey('max');
})->with([
    'integer zero' => [0],
    'string zero' => ['0'],
]);

it('suppresses a numeric-zero rangeMin so no min attribute is emitted', function (int|string $rangeMin): void {
    $result = FieldtypeMapping::mapFieldAttributes([
        'type' => 'number',
        'rangeMin' => $rangeMin,
    ]);

    expect($result)->not->toHaveKey('min');
})->with([
    'integer zero' => [0],
    'string zero' => ['0'],
]);

it('preserves a non-zero range bound unchanged', function (array $fieldData, array $expected): void {
    expect(FieldtypeMapping::mapFieldAttributes($fieldData))->toBe($expected);
})->with([
    'rangeMax five' => [['type' => 'number', 'rangeMax' => '5'], ['max' => '5']],
    'rangeMin two' => [['type' => 'number', 'rangeMin' => '2'], ['min' => '2']],
]);

it('keeps a literal "false" range bound suppressed', function (string $rangeKey, string $absentAttribute): void {
    $result = FieldtypeMapping::mapFieldAttributes([
        'type' => 'number',
        $rangeKey => 'false',
    ]);

    expect($result)->not->toHaveKey($absentAttribute);
})->with([
    'rangeMax' => ['rangeMax', 'max'],
    'rangeMin' => ['rangeMin', 'min'],
]);
