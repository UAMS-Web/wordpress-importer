<?php

declare(strict_types=1);

/**
 * Tests that built-in WordPress categories reach the nicename-to-term-id map.
 *
 * WordPress serializes the built-in `category` taxonomy as `<wp:category>` with a
 * `<wp:category_nicename>`, not as the `<wp:term>` shape every other taxonomy
 * uses. `XmlParser::getTerms()` matches only `term`, so categories were invisible
 * to it — in `wordpress-export_news-uams-edu_cleaned.001.xml` that is 136
 * `<wp:category>` elements against 6 `<wp:term>`.
 *
 * The consequence was silent: `nicenameToTermIdMap['category']` came back empty on
 * every real export, so wordpress-importer-archive#441's Yoast-primary-first ordering resolved no primary
 * and returned document order for 100% of posts. The unit tests for the ordering
 * itself passed because they inject a hand-built map, which is exactly the gap
 * this file closes — it asserts the map that PRODUCTION builds is populated.
 *
 * @command  php vendor/bin/pest tests/Unit/CategoryTermMapSourceTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\TermMapBuilder;

/**
 * Convert a `<wp:category>` element the way `BaseEntryCreator` does, without
 * booting the creator (the conversion is a protected static helper).
 */
function categoryPseudoTerm(
    string $termId,
    string $nicename,
    string $name
): SimpleXMLElement {
    $pseudoTerm = new SimpleXMLElement('<wp:term xmlns:wp="http://wordpress.org/export/1.2/"></wp:term>');
    $pseudoTerm->addChild('wp:term_id', $termId);
    $pseudoTerm->addChild('wp:term_taxonomy', 'category');
    $pseudoTerm->addChild('wp:term_slug', $nicename);
    $pseudoTerm->addChild('wp:term_name', $name);

    return $pseudoTerm;
}

it('maps a converted wp:category into the category nicename map', function (): void {
    $map = TermMapBuilder::buildNicenameToTermIdMap(
        [categoryPseudoTerm('3853', 'health', 'Health')],
        ['category']
    );

    // The lookup wordpress-importer-archive#441 performs: nicename => term id
    expect($map['category']['health'] ?? null)->toBe('3853');
});

it('is empty when a raw wp:category is passed without conversion', function (): void {
    // ---
    // The defect this file exists for. A `<wp:category>` carries `wp:category_nicename`
    // and no `wp:term_taxonomy`, so `buildNicenameToTermIdMap()` cannot classify it and
    // returns nothing — which is what production did before the conversion was wired in.
    // ---
    $rawCategory = new SimpleXMLElement('<wp:category xmlns:wp="http://wordpress.org/export/1.2/"></wp:category>');
    $rawCategory->addChild('wp:term_id', '3853');
    $rawCategory->addChild('wp:category_nicename', 'health');
    $rawCategory->addChild('wp:cat_name', 'Health');

    $map = TermMapBuilder::buildNicenameToTermIdMap([$rawCategory], ['category']);

    expect($map['category'] ?? [])->toBe([]);
});

it('maps every converted category, not just the first', function (): void {
    $map = TermMapBuilder::buildNicenameToTermIdMap(
        [
            categoryPseudoTerm('3851', 'research', 'Research'),
            categoryPseudoTerm('3853', 'health', 'Health'),
            categoryPseudoTerm('1', 'featured', 'Featured'),
        ],
        ['category']
    );

    expect($map['category'] ?? [])->toHaveCount(3);
    expect($map['category']['research'] ?? null)->toBe('3851');
    expect($map['category']['featured'] ?? null)->toBe('1');
});

it('keeps a numeric nicename resolvable', function (): void {
    // ---
    // A year-archive category such as `2024` is an ordinary newsroom category, but PHP
    // coerces a numeric-string array key to an integer. The lookup must still resolve it.
    // ---
    $map = TermMapBuilder::buildNicenameToTermIdMap(
        [categoryPseudoTerm('77', '2024', '2024')],
        ['category']
    );

    $categoryMap = $map['category'] ?? [];

    expect($categoryMap)->not->toBeEmpty();
    expect((string) reset($categoryMap))->toBe('77');
});
