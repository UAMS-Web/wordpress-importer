<?php

declare(strict_types=1);

use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\TaxonomyDispositions;

/**
 * `TaxonomyDispositions` is a table of decisions: each WordPress taxonomy's destination shape,
 * and the Statamic handles that are collections rather than taxonomies. Every row is pinned
 * here, so a row lost or changed fails a test that names it (#102).
 *
 * @command  php vendor/bin/pest tests/Unit/TaxonomyDispositionsTableTest.php
 */
it('records each WordPress taxonomy with its destination shape', function (string $taxonomy, string $shape): void {
    expect(TaxonomyDispositions::isRecorded($taxonomy))->toBeTrue()
        ->and(TaxonomyDispositions::shapeFor($taxonomy))->toBe($shape);
})->with([
    'category' => ['category', TaxonomyDispositions::SHAPE_COLLECTION],
    'uamspeople-categories' => ['uamspeople-categories', TaxonomyDispositions::SHAPE_COLLECTION],
    'tshowcase-categories' => ['tshowcase-categories', TaxonomyDispositions::SHAPE_COLLECTION],
    'logo-list-category' => ['logo-list-category', TaxonomyDispositions::SHAPE_COLLECTION],
    'post_tag' => ['post_tag', TaxonomyDispositions::SHAPE_COLLECTION],
    'logo-list-label' => ['logo-list-label', TaxonomyDispositions::SHAPE_COLLECTION],
    'kbtag' => ['kbtag', TaxonomyDispositions::SHAPE_COLLECTION],
    'mkb_faq_category' => ['mkb_faq_category', TaxonomyDispositions::SHAPE_COLLECTION],
    'advert_category' => ['advert_category', TaxonomyDispositions::SHAPE_TAXONOMY],
    'tshowcase-taxonomy' => ['tshowcase-taxonomy', TaxonomyDispositions::SHAPE_UNMAPPED],
    'tshowcase-ctaxonomy' => ['tshowcase-ctaxonomy', TaxonomyDispositions::SHAPE_UNMAPPED],
    'tshowcase-dtaxonomy' => ['tshowcase-dtaxonomy', TaxonomyDispositions::SHAPE_UNMAPPED],
    'uamspeople-taxonomy' => ['uamspeople-taxonomy', TaxonomyDispositions::SHAPE_UNMAPPED],
    'uamspeople-ctaxonomy' => ['uamspeople-ctaxonomy', TaxonomyDispositions::SHAPE_UNMAPPED],
    'uamspeople-dtaxonomy' => ['uamspeople-dtaxonomy', TaxonomyDispositions::SHAPE_UNMAPPED],
    'wp_theme' => ['wp_theme', TaxonomyDispositions::SHAPE_UNMAPPED],
    'link_category' => ['link_category', TaxonomyDispositions::SHAPE_UNMAPPED],
    'content_audit' => ['content_audit', TaxonomyDispositions::SHAPE_UNMAPPED],
    'seopress_404_cat' => ['seopress_404_cat', TaxonomyDispositions::SHAPE_UNMAPPED],
    'workflow_state' => ['workflow_state', TaxonomyDispositions::SHAPE_UNMAPPED],
    'wp_pattern_category' => ['wp_pattern_category', TaxonomyDispositions::SHAPE_UNMAPPED],
]);

it('lists the structured taxonomies in table order', function (): void {
    expect(TaxonomyDispositions::structuredTaxonomies())->toBe([
        'category',
        'uamspeople-categories',
        'tshowcase-categories',
        'logo-list-category',
        'post_tag',
        'logo-list-label',
        'kbtag',
        'mkb_faq_category',
    ]);
});

it('names each Statamic collection handle as a structured destination, and no taxonomy', function (string $handle, bool $structured): void {
    expect(TaxonomyDispositions::isStructuredDestination($handle))->toBe($structured);
})->with([
    'post_categories' => ['post_categories', true],
    'people_groups' => ['people_groups', true],
    'logos_item_categories' => ['logos_item_categories', true],
    'post_tags' => ['post_tags', true],
    'logos_item_labels' => ['logos_item_labels', true],
    'knowledge_base_tags' => ['knowledge_base_tags', true],
    'faq_categories' => ['faq_categories', true],
    'a taxonomy' => ['classified_categories', false],
]);
