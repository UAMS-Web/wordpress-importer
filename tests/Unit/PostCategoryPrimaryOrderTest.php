<?php

declare(strict_types=1);

/**
 * Tests Yoast-primary-first ordering of a post's `post_categories`.
 *
 * The Statamic newsroom uses a first-selected-category-wins convention for the
 * category shown on cards and breadcrumbs (`UAMS-Web/uams-statamic-archive#1609`), but
 * `PostEntryCreator` extracts categories in WXR document order — so the
 * "primary" was whatever WordPress happened to serialize first, not the
 * editor's choice (wordpress-importer-archive#441).
 *
 * WordPress stores the choice in `_yoast_wpseo_primary_category` as a term ID,
 * while the extraction works in nicenames, so reordering has to go through the
 * nicename-to-term-id map the creator already builds.
 *
 * Values observed in `wordpress-export_news-uams-edu_cleaned.001.xml` (23
 * occurrences) include real ids such as `3853`, `3851`, and `1` — and several
 * that are EMPTY, which must read as "no primary chosen" rather than a term
 * whose id is the empty string.
 *
 * @command  php vendor/bin/pest tests/Unit/PostCategoryPrimaryOrderTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators\PostEntryCreator;

beforeEach(function (): void {
    $this->creator = new PostEntryCreator;

    // nicename => term id, the shape PostEntryCreator already holds for 'category'
    $this->map = [
        'research' => '3851',
        'patient-care' => '3853',
        'education' => '1',
    ];
});

it('moves the Yoast primary category to the front', function (): void {
    $ordered = $this->creator->orderCategoriesPrimaryFirst(
        ['research', 'patient-care', 'education'],
        '3853',
        $this->map
    );

    expect($ordered)->toBe(['patient-care', 'research', 'education']);
});

it('preserves the relative order of the remaining categories', function (): void {
    // Only the primary moves; everything else keeps WXR document order
    $ordered = $this->creator->orderCategoriesPrimaryFirst(
        ['research', 'patient-care', 'education'],
        '1',
        $this->map
    );

    expect($ordered)->toBe(['education', 'research', 'patient-care']);
});

it('leaves the list untouched when the primary is already first', function (): void {
    $ordered = $this->creator->orderCategoriesPrimaryFirst(
        ['research', 'patient-care'],
        '3851',
        $this->map
    );

    expect($ordered)->toBe(['research', 'patient-care']);
});

it('keeps document order when no primary is recorded', function (string $primary): void {
    // ---
    // Empty is the common real case: several posts in the news export carry the
    // meta key with an empty CDATA value, which means the editor never chose one.
    // ---
    $ordered = $this->creator->orderCategoriesPrimaryFirst(
        ['research', 'patient-care', 'education'],
        $primary,
        $this->map
    );

    expect($ordered)->toBe(['research', 'patient-care', 'education']);
})->with([
    'empty string' => [''],
    'whitespace' => ['   '],
    'zero' => ['0'],
]);

it('keeps document order when the primary id is not in the term map', function (): void {
    // A stale Yoast id pointing at a term the export no longer defines
    $ordered = $this->creator->orderCategoriesPrimaryFirst(
        ['research', 'patient-care'],
        '999999',
        $this->map
    );

    expect($ordered)->toBe(['research', 'patient-care']);
});

it('keeps document order when the primary category is not on the post', function (): void {
    // ---
    // Reachable in practice: audio-series categories are filtered out of the list
    // before this point, so the Yoast primary can name a category the post no
    // longer carries. Injecting it would import a category the post lost.
    // ---
    $ordered = $this->creator->orderCategoriesPrimaryFirst(
        ['research', 'patient-care'],
        '1',
        $this->map
    );

    expect($ordered)->toBe(['research', 'patient-care']);
});

it('returns a list with sequential keys, not a gapped array', function (): void {
    // A gapped array would serialize to a YAML map instead of a list
    $ordered = $this->creator->orderCategoriesPrimaryFirst(
        ['research', 'patient-care', 'education'],
        '3853',
        $this->map
    );

    expect(array_keys($ordered))->toBe([0, 1, 2]);
});

it('handles the trivial shapes without error', function (array $categories, string $primary): void {
    // Narrow the dataset's loosely-typed array to the list<string> the method declares
    $list = array_values(
        array_filter(
            $categories,
            static fn (mixed $slug): bool => is_string($slug)
        )
    );

    expect($this->creator->orderCategoriesPrimaryFirst($list, $primary, $this->map))
        ->toBe($list);
})->with([
    'no categories' => [[], '3853'],
    'single category, is primary' => [['research'], '3851'],
    'single category, not primary' => [['research'], '3853'],
]);

it('does not duplicate a category that appears twice', function (): void {
    // Defensive: a malformed export listing the same nicename twice
    $ordered = $this->creator->orderCategoriesPrimaryFirst(
        ['research', 'patient-care', 'research'],
        '3853',
        $this->map
    );

    expect($ordered)->toBe(['patient-care', 'research', 'research']);
});
