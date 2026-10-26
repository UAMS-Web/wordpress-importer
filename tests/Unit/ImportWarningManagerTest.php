<?php

declare(strict_types=1);

/**
 * Tests that `ImportWarningManager` records warnings by category and type,
 * drops the duplicates it knows how to recognize, and counts, lists and clears
 * what it holds (#1314).
 *
 * The manager is process-wide static state that many tests use, so every case
 * starts and ends with it cleared.
 *
 * @command  php vendor/bin/pest tests/Unit/ImportWarningManagerTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;

beforeEach(function (): void {
    ImportWarningManager::clearWarnings();
});

afterEach(function (): void {
    ImportWarningManager::clearWarnings();
});

it('starts empty', function (): void {
    expect(ImportWarningManager::hasWarnings())->toBeFalse()
        ->and(ImportWarningManager::getWarningCount())->toBe(0)
        ->and(ImportWarningManager::getAllWarnings())->toBe([])
        ->and(ImportWarningManager::getWarningsByCategory('content'))->toBe([])
        ->and(ImportWarningManager::getWarnings('content', 'anything'))->toBe([]);
});

it('records warnings by category and type, in order, keeping repeats of other types', function (): void {
    ImportWarningManager::addWarning('content', 'unrenderable_gallery_blocks', ['page_url' => 'a']);
    ImportWarningManager::addWarning('content', 'unrenderable_gallery_blocks', ['page_url' => 'a']);
    ImportWarningManager::addWarning('content', 'other', ['n' => 1]);
    ImportWarningManager::addWarning('forms', 'other', ['n' => 2]);

    expect(ImportWarningManager::getWarnings('content', 'unrenderable_gallery_blocks'))->toBe([['page_url' => 'a'], ['page_url' => 'a']])
        ->and(ImportWarningManager::getWarningsByCategory('content'))->toBe([
            'unrenderable_gallery_blocks' => [['page_url' => 'a'], ['page_url' => 'a']],
            'other' => [['n' => 1]],
        ])
        ->and(ImportWarningManager::getAllWarnings())->toBe([
            'content' => [
                'unrenderable_gallery_blocks' => [['page_url' => 'a'], ['page_url' => 'a']],
                'other' => [['n' => 1]],
            ],
            'forms' => ['other' => [['n' => 2]]],
        ])
        ->and(ImportWarningManager::hasWarnings())->toBeTrue()
        ->and(ImportWarningManager::getWarningCount())->toBe(4);
});

it('drops a duplicate of a de-duplicated type, and keeps one that differs in any key', function (string $category, string $type, array $base, string $key): void {
    /** @var array<string, mixed> $base */
    ImportWarningManager::addWarning($category, $type, $base);
    ImportWarningManager::addWarning($category, $type, $base);

    expect(ImportWarningManager::getWarnings($category, $type))->toHaveCount(1);

    $different = $base;
    $different[$key] = 'something else';
    ImportWarningManager::addWarning($category, $type, $different);

    expect(ImportWarningManager::getWarnings($category, $type))->toHaveCount(2);
})->with([
    'unmapped fieldtypes, field type' => ['forms', 'unmapped_fieldtypes', ['field_type' => 'signature', 'form_id' => 3], 'field_type'],
    'unmapped fieldtypes, form' => ['forms', 'unmapped_fieldtypes', ['field_type' => 'signature', 'form_id' => 3], 'form_id'],
    'unsupported attributes, field type' => ['forms', 'unsupported_attributes', ['field_type' => 'text', 'attribute_name' => 'mask', 'form_id' => 3], 'field_type'],
    'unsupported attributes, attribute' => ['forms', 'unsupported_attributes', ['field_type' => 'text', 'attribute_name' => 'mask', 'form_id' => 3], 'attribute_name'],
    'unsupported attributes, form' => ['forms', 'unsupported_attributes', ['field_type' => 'text', 'attribute_name' => 'mask', 'form_id' => 3], 'form_id'],
    'unmapped subfields, field type' => ['forms', 'unmapped_subfields', ['field_type' => 'name', 'subfield_label' => 'Prefix', 'attribute_name' => 'prefix', 'form_id' => 3], 'field_type'],
    'unmapped subfields, label' => ['forms', 'unmapped_subfields', ['field_type' => 'name', 'subfield_label' => 'Prefix', 'attribute_name' => 'prefix', 'form_id' => 3], 'subfield_label'],
    'unmapped subfields, attribute' => ['forms', 'unmapped_subfields', ['field_type' => 'name', 'subfield_label' => 'Prefix', 'attribute_name' => 'prefix', 'form_id' => 3], 'attribute_name'],
    'unmapped subfields, form' => ['forms', 'unmapped_subfields', ['field_type' => 'name', 'subfield_label' => 'Prefix', 'attribute_name' => 'prefix', 'form_id' => 3], 'form_id'],
    'malformed shortcodes, name' => ['content', 'malformed_shortcodes', ['shortcode_name' => 'gallery', 'attribute' => 'ids', 'original' => '[gallery]', 'page_url' => 'p'], 'shortcode_name'],
    'malformed shortcodes, attribute' => ['content', 'malformed_shortcodes', ['shortcode_name' => 'gallery', 'attribute' => 'ids', 'original' => '[gallery]', 'page_url' => 'p'], 'attribute'],
    'malformed shortcodes, original' => ['content', 'malformed_shortcodes', ['shortcode_name' => 'gallery', 'attribute' => 'ids', 'original' => '[gallery]', 'page_url' => 'p'], 'original'],
    'malformed shortcodes, page' => ['content', 'malformed_shortcodes', ['shortcode_name' => 'gallery', 'attribute' => 'ids', 'original' => '[gallery]', 'page_url' => 'p'], 'page_url'],
]);

it('treats two warnings that both lack the compared keys as duplicates', function (string $category, string $type): void {
    ImportWarningManager::addWarning($category, $type, ['note' => 'first']);
    ImportWarningManager::addWarning($category, $type, ['note' => 'second']);

    expect(ImportWarningManager::getWarnings($category, $type))->toBe([['note' => 'first']]);
})->with([
    ['forms', 'unmapped_fieldtypes'],
    ['forms', 'unsupported_attributes'],
    ['forms', 'unmapped_subfields'],
    ['content', 'malformed_shortcodes'],
]);

it('compares a missing key with an empty or null one as the manager defaults it', function (string $category, string $type, array $explicit): void {
    /** @var array<string, mixed> $explicit */
    ImportWarningManager::addWarning($category, $type, []);
    ImportWarningManager::addWarning($category, $type, $explicit);

    expect(ImportWarningManager::getWarnings($category, $type))->toHaveCount(1);
})->with([
    'unmapped fieldtypes' => ['forms', 'unmapped_fieldtypes', ['field_type' => '', 'form_id' => null]],
    'unsupported attributes' => ['forms', 'unsupported_attributes', ['field_type' => '', 'attribute_name' => '', 'form_id' => null]],
    'unmapped subfields' => ['forms', 'unmapped_subfields', ['field_type' => '', 'subfield_label' => '', 'attribute_name' => null, 'form_id' => null]],
    'malformed shortcodes' => ['content', 'malformed_shortcodes', ['shortcode_name' => '', 'attribute' => '', 'original' => '', 'page_url' => null]],
]);

it('keeps checking past an earlier warning that differs', function (string $category, string $type, array $first, array $second): void {
    /** @var array<string, mixed> $first */
    /** @var array<string, mixed> $second */
    ImportWarningManager::addWarning($category, $type, $first);
    ImportWarningManager::addWarning($category, $type, $second);
    ImportWarningManager::addWarning($category, $type, $second);

    expect(ImportWarningManager::getWarnings($category, $type))->toBe([$first, $second]);
})->with([
    'unmapped fieldtypes' => ['forms', 'unmapped_fieldtypes', ['field_type' => 'a', 'form_id' => 1], ['field_type' => 'b', 'form_id' => 1]],
    'unsupported attributes' => ['forms', 'unsupported_attributes', ['field_type' => 'a', 'attribute_name' => 'x', 'form_id' => 1], ['field_type' => 'b', 'attribute_name' => 'x', 'form_id' => 1]],
    'unmapped subfields' => ['forms', 'unmapped_subfields', ['field_type' => 'a', 'subfield_label' => 'L', 'attribute_name' => 'x', 'form_id' => 1], ['field_type' => 'b', 'subfield_label' => 'L', 'attribute_name' => 'x', 'form_id' => 1]],
    'malformed shortcodes' => ['content', 'malformed_shortcodes', ['shortcode_name' => 'a', 'attribute' => 'x', 'original' => 'o', 'page_url' => 'p'], ['shortcode_name' => 'b', 'attribute' => 'x', 'original' => 'o', 'page_url' => 'p']],
]);

it('de-duplicates a type only within its own category', function (): void {
    ImportWarningManager::addWarning('content', 'unmapped_fieldtypes', ['field_type' => 'a']);
    ImportWarningManager::addWarning('content', 'unmapped_fieldtypes', ['field_type' => 'a']);
    ImportWarningManager::addWarning('forms', 'malformed_shortcodes', ['shortcode_name' => 'a']);
    ImportWarningManager::addWarning('forms', 'malformed_shortcodes', ['shortcode_name' => 'a']);

    expect(ImportWarningManager::getWarnings('content', 'unmapped_fieldtypes'))->toHaveCount(2)
        ->and(ImportWarningManager::getWarnings('forms', 'malformed_shortcodes'))->toHaveCount(2);
});

it('clears one category, leaving the others', function (): void {
    ImportWarningManager::addWarning('content', 'a', ['n' => 1]);
    ImportWarningManager::addWarning('forms', 'b', ['n' => 2]);

    ImportWarningManager::clearWarningsByCategory('content');

    expect(ImportWarningManager::getAllWarnings())->toBe(['forms' => ['b' => [['n' => 2]]]]);
});

it('clears one type, leaving its category and the other types', function (): void {
    ImportWarningManager::addWarning('content', 'a', ['n' => 1]);
    ImportWarningManager::addWarning('content', 'b', ['n' => 2]);

    ImportWarningManager::clearWarningsByCategoryAndType('content', 'a');

    expect(ImportWarningManager::getAllWarnings())->toBe(['content' => ['b' => [['n' => 2]]]]);
});

it('leaves an emptied category behind when its last type is cleared', function (): void {
    ImportWarningManager::addWarning('content', 'a', ['n' => 1]);

    ImportWarningManager::clearWarningsByCategoryAndType('content', 'a');

    expect(ImportWarningManager::getAllWarnings())->toBe(['content' => []])
        ->and(ImportWarningManager::hasWarnings())->toBeTrue()
        ->and(ImportWarningManager::getWarningCount())->toBe(0);
});

it('does nothing when clearing a type in a category it does not hold', function (): void {
    ImportWarningManager::addWarning('forms', 'b', ['n' => 2]);

    ImportWarningManager::clearWarningsByCategoryAndType('content', 'a');

    expect(ImportWarningManager::getAllWarnings())->toBe(['forms' => ['b' => [['n' => 2]]]]);
});

it('clears everything', function (): void {
    ImportWarningManager::addWarning('content', 'a', ['n' => 1]);

    ImportWarningManager::clearWarnings();

    expect(ImportWarningManager::hasWarnings())->toBeFalse()
        ->and(ImportWarningManager::getAllWarnings())->toBe([]);
});
