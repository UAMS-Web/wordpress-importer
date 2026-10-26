<?php

declare(strict_types=1);

/**
 * Tests that a built-in WordPress category keeps its parent edge on import.
 *
 * WordPress serializes the built-in `category` taxonomy as `<wp:category>` rather
 * than the `<wp:term>` shape every other taxonomy uses, so `BaseEntryCreator`
 * converts each one into a pseudo `<wp:term>` before the term pass sees it (wordpress-importer-archive#441).
 * That conversion dropped `<wp:category_parent>`, and nothing noticed because
 * nothing tested the conversion at all.
 *
 * The consequence is only visible two steps later: `StructuredTermImporter`
 * reads `<wp:term_parent>` to build the `post_categories` collection tree and to
 * stamp each child's `parent` field — the field the app's `:parent:is` child
 * query reads. With the edge missing, every category imported as a root, the
 * tree came out flat, and a nested category's children were unreachable while
 * the Control Panel still showed a plausible-looking list (wordpress-importer-archive#422).
 *
 * Measured on the 259-export corpus: 19 exports carry 146 nested categories, and
 * 16 of those declare their categories ONLY as `<wp:category>`.
 *
 * @command  php vendor/bin/pest tests/Unit/CategoryPseudoTermParentTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;

/**
 * Build a `<wp:category>` element in the shape a real WXR export writes.
 */
function categoryElement(string $termId, string $nicename, string $name, string $parent): SimpleXMLElement
{
    $xml = <<<XML
        <wp:category xmlns:wp="http://wordpress.org/export/1.2/">
            <wp:term_id>{$termId}</wp:term_id>
            <wp:category_nicename><![CDATA[{$nicename}]]></wp:category_nicename>
            <wp:category_parent><![CDATA[{$parent}]]></wp:category_parent>
            <wp:cat_name><![CDATA[{$name}]]></wp:cat_name>
        </wp:category>
        XML;

    return new SimpleXMLElement($xml);
}

/**
 * Read a `wp:`-namespaced child off the generated pseudo-term.
 */
function pseudoTermValue(SimpleXMLElement $pseudoTerm, string $child): string
{
    $matches = $pseudoTerm->xpath('wp:'.$child);

    return is_array($matches) && isset($matches[0])
        ? (string) $matches[0]
        : '';
}

/**
 * Run a `<wp:category>` element through the protected converter under test.
 */
function convertCategory(SimpleXMLElement $category): SimpleXMLElement
{
    $converted = (new ReflectionMethod(
        BaseEntryCreator::class,
        'categoryToPseudoTerm'
    ))->invoke(null, $category);

    if (! $converted instanceof SimpleXMLElement) {
        throw new RuntimeException('categoryToPseudoTerm() must return a SimpleXMLElement.');
    }

    return $converted;
}

it('carries a nested category parent across as the term parent', function (): void {
    // ---
    // The shape observed verbatim in the corpus: `communityprograms` under
    // `ichi`. Both elements hold a NICENAME, so the carry-over is direct — the
    // tree builder resolves that slug back to a term id itself.
    // ---
    $pseudoTerm = convertCategory(categoryElement('133', 'communityprograms', 'Community Programs', 'ichi'));

    expect(pseudoTermValue($pseudoTerm, 'term_parent'))->toBe('ichi');
});

it('leaves a top-level category with an empty parent, not a missing element', function (): void {
    // ---
    // An absent element and an empty one are not the same to the tree builder: it
    // reads `''` as "no parent" and anything else as a slug to resolve. A missing
    // element would also read as `''`, but emitting it keeps the pseudo-term the
    // same shape as a real `<wp:term>`, which is the whole point of the conversion.
    // ---
    $pseudoTerm = convertCategory(categoryElement('1', 'news', 'News', ''));

    expect(pseudoTermValue($pseudoTerm, 'term_parent'))->toBe('')
        ->and($pseudoTerm->xpath('wp:term_parent'))->toHaveCount(1);
});

it('still carries every field the term pass already depended on', function (): void {
    // Guards against the parent being added by replacing one of the others
    $pseudoTerm = convertCategory(categoryElement('133', 'communityprograms', 'Community Programs', 'ichi'));

    expect(pseudoTermValue($pseudoTerm, 'term_id'))->toBe('133')
        ->and(pseudoTermValue($pseudoTerm, 'term_taxonomy'))->toBe('category')
        ->and(pseudoTermValue($pseudoTerm, 'term_slug'))->toBe('communityprograms')
        ->and(pseudoTermValue($pseudoTerm, 'term_name'))->toBe('Community Programs');
});

it('produces a pseudo-term the tree builder reads as a child', function (): void {
    // ---
    // The end-to-end claim, one step further than the conversion: two categories
    // in a parent/child pair must produce the parent EDGE the tree is built from.
    // `StructuredTermImporter` keys nodes by term id and resolves the parent
    // nicename back to one, so the edge only exists if both halves survive.
    // ---
    $parent = convertCategory(categoryElement('7', 'ichi', 'ICHI', ''));
    $child = convertCategory(categoryElement('133', 'communityprograms', 'Community Programs', 'ichi'));

    $slugToTermId = [
        pseudoTermValue($parent, 'term_slug') => pseudoTermValue($parent, 'term_id'),
    ];

    $resolvedParentTermId = $slugToTermId[pseudoTermValue($child, 'term_parent')] ?? '';

    expect($resolvedParentTermId)->toBe('7')
        ->and(pseudoTermValue($parent, 'term_parent'))->toBe('');
});
