<?php

declare(strict_types=1);

/**
 * Tests that `AcfFieldNormalizer` copies ACF field-key data to its standard names,
 * from the top level and from named nested structures, and builds field mappings
 * from a prefix (#1314).
 *
 * @command  php vendor/bin/pest tests/Unit/AcfFieldNormalizerTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\AcfFieldNormalizer;

it('returns the data unchanged when there are no mappings', function (): void {
    $data = ['side_heading' => 'H', 'side_list_0_text' => 'row', 3 => 'indexed'];

    expect(AcfFieldNormalizer::normalize($data))->toBe($data);
});

it('copies a mapped field to its standard name and keeps the original key', function (): void {
    $result = AcfFieldNormalizer::normalize(
        ['field_side_heading_b' => 'About', 'other' => 1],
        ['field_side_heading_b' => 'side_heading']
    );

    expect($result)->toBe([
        'field_side_heading_b' => 'About',
        'other' => 1,
        'side_heading' => 'About',
    ]);
});

it('lets a mapped field overwrite a standard name already present', function (): void {
    $result = AcfFieldNormalizer::normalize(
        ['side_heading' => 'old', 'field_side_heading_b' => 'new'],
        ['field_side_heading_b' => 'side_heading']
    );

    expect($result['side_heading'] ?? null)->toBe('new');
});

it('skips a mapping whose field is absent or null', function (): void {
    $result = AcfFieldNormalizer::normalize(
        ['field_b' => null],
        ['field_a' => 'a', 'field_b' => 'b']
    );

    expect($result)->toBe(['field_b' => null]);
});

it('skips mappings whose key or target is not a string', function (): void {
    $result = AcfFieldNormalizer::normalize(
        [0 => 'zero', 'field_x' => 'X'],
        [0 => 'zero_name', 'field_x' => 5]
    );

    expect($result)->toBe([0 => 'zero', 'field_x' => 'X']);
});

it('keeps mapping after a mapping it has to skip', function (): void {
    $result = AcfFieldNormalizer::normalize(
        ['field_x' => 'X', 'field_y' => 'Y'],
        ['field_x' => 5, 'field_y' => 'y']
    );

    expect($result['y'] ?? null)->toBe('Y');
});

it('copies mapped keys out of a nested structure', function (): void {
    $result = AcfFieldNormalizer::normalize(
        ['field_group' => ['field_side_text_b' => 'Body', 'unmapped' => 'u']],
        ['field_side_text_b' => 'side_text'],
        ['field_group']
    );

    expect($result)->toBe([
        'field_group' => ['field_side_text_b' => 'Body', 'unmapped' => 'u'],
        'side_text' => 'Body',
    ]);
});

it('copies a nested null value, unlike a top-level one', function (): void {
    $result = AcfFieldNormalizer::normalize(
        ['group' => ['field_a' => null]],
        ['field_a' => 'a'],
        ['group']
    );

    expect($result)->toHaveKey('a')
        ->and($result['a'])->toBeNull();
});

it('skips a nested key whose mapping target is not a string', function (): void {
    $result = AcfFieldNormalizer::normalize(
        ['group' => ['field_a' => 'A', 7 => 'seven']],
        ['field_a' => ['not', 'a', 'string'], 7 => 'seven_name'],
        ['group']
    );

    expect($result)->toBe(['group' => ['field_a' => 'A', 7 => 'seven']]);
});

it('skips nested structure names that are absent, non-string or not arrays', function (): void {
    $data = ['scalar_group' => 'text', 'empty' => null];

    $result = AcfFieldNormalizer::normalize(
        $data,
        ['field_a' => 'a'],
        ['missing', 3, 'scalar_group', 'empty']
    );

    expect($result)->toBe($data);
});

it('keeps reading nested structures after one it has to skip', function (): void {
    $result = AcfFieldNormalizer::normalize(
        ['group' => ['field_a' => 'A']],
        ['field_a' => 'a'],
        ['missing', 'group']
    );

    expect($result['a'] ?? null)->toBe('A');
});

it('skips a non-string nested structure name even when that key holds an array', function (): void {
    $result = AcfFieldNormalizer::normalize(
        [3 => ['field_a' => 'A']],
        ['field_a' => 'a'],
        [3]
    );

    expect($result)->not->toHaveKey('a');
});

it('reads an absent nested structure without raising a warning', function (): void {
    \set_error_handler(static function (int $severity, string $message): bool {
        throw new ErrorException($message, 0, $severity);
    });

    try {
        $result = AcfFieldNormalizer::normalize(['other' => 1], ['field_a' => 'a'], ['missing']);
    } finally {
        \restore_error_handler();
    }

    expect($result)->toBe(['other' => 1]);
});

it('builds field mappings from a prefix', function (): void {
    expect(AcfFieldNormalizer::createFieldMappings('side', ['heading', 'image_position']))->toBe([
        'field_side_heading_b' => 'side_heading',
        'field_side_image_position_b' => 'side_image_position',
    ]);
});

it('skips non-string field names when building mappings', function (): void {
    expect(AcfFieldNormalizer::createFieldMappings('cta', ['text', 4, null, 'url']))->toBe([
        'field_cta_text_b' => 'cta_text',
        'field_cta_url_b' => 'cta_url',
    ]);
});

it('builds no mappings from no field names', function (): void {
    expect(AcfFieldNormalizer::createFieldMappings('cta', []))->toBe([]);
});
