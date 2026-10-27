<?php

declare(strict_types=1);

/**
 * Tests that `TermMapBuilder` turns WXR `<wp:term>` elements into the term map entry
 * creators read: which terms it keeps, how it resolves a parent slug to an id, how it
 * builds the full hierarchical slug, and the nicename-to-id map per taxonomy.
 *
 * @command  php vendor/bin/pest tests/Unit/TermMapBuilderTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\TermMapBuilder;

/**
 * A `<wp:term>` element; a null field is left out of the element entirely.
 */
function termMapTerm(
    ?string $id,
    ?string $slug,
    string $taxonomy = 'category',
    string $parent = '',
    string $name = ''
): SimpleXMLElement {
    $namespace = 'http://wordpress.org/export/1.2/';
    $term = new SimpleXMLElement('<wp:term xmlns:wp="'.$namespace.'"></wp:term>');

    $fields = [
        'term_id' => $id,
        'term_taxonomy' => $taxonomy,
        'term_slug' => $slug,
        'term_name' => $name,
        'term_parent' => $parent,
    ];

    foreach ($fields as $field => $value) {
        if ($value !== null) {
            $term->addChild('wp:'.$field, $value, $namespace);
        }
    }

    return $term;
}

/**
 * The full_slug of every term in a map, keyed by id.
 *
 * @param  array<int|string, array<string, string>>  $map
 * @return array<int|string, string>
 */
function termMapFullSlugs(array $map): array
{
    return \array_map(static fn (array $data): string => $data['full_slug'] ?? '', $map);
}

/**
 * One field of one term's record, or null when the term or field is absent.
 *
 * @param  array<int|string, array<string, string>>  $map
 */
function termMapField(array $map, string $id, string $field): ?string
{
    return $map[$id][$field] ?? null;
}

/**
 * A logo list label carrying the slug `research`.
 */
function termMapLabel(): SimpleXMLElement
{
    return termMapTerm('5', 'research', taxonomy: 'logo-list-label');
}

/**
 * A logo list category carrying the same slug `research`.
 */
function termMapCategory(): SimpleXMLElement
{
    return termMapTerm('9', 'research', taxonomy: 'logo-list-category');
}

/**
 * A logo list category whose parent slug is `research`.
 */
function termMapChild(): SimpleXMLElement
{
    return termMapTerm('12', 'partners', taxonomy: 'logo-list-category', parent: 'research');
}

it('maps each term to its full record', function (): void {
    $map = TermMapBuilder::buildTermMap([
        termMapTerm('5', 'news', name: 'News'),
        termMapTerm('6', 'events', parent: 'news', name: 'Events'),
    ]);

    expect($map)->toBe([
        '5' => [
            'term_id' => '5',
            'term_taxonomy' => 'category',
            'term_slug' => 'news',
            'term_name' => 'News',
            'term_parent' => '',
            'parent_id' => '',
            'full_slug' => 'news',
        ],
        '6' => [
            'term_id' => '6',
            'term_taxonomy' => 'category',
            'term_slug' => 'events',
            'term_name' => 'Events',
            'term_parent' => 'news',
            'parent_id' => '5',
            'full_slug' => 'news_events',
        ],
    ]);
});

it('skips non-elements and terms missing an id or a slug, and keeps going', function (): void {
    // Each skipped item comes before a kept one, so stopping at it would lose the rest
    $map = TermMapBuilder::buildTermMap([
        'not an element',
        termMapTerm('', 'no-id'),
        termMapTerm(null, 'absent-id'),
        termMapTerm('7', ''),
        termMapTerm('8', null),
        termMapTerm('9', 'kept'),
    ]);

    expect(\array_keys($map))->toBe([9]);
});

it('does not treat an empty parent slug as a parent to look up', function (): void {
    // A term with an empty slug is in the input; a root term must not resolve to it
    $map = TermMapBuilder::buildTermMap([
        termMapTerm('9', ''),
        termMapTerm('10', 'root'),
    ]);

    expect(termMapField($map, '10', 'parent_id'))->toBe('');
});

it('finds a parent past non-elements in the input', function (): void {
    $map = TermMapBuilder::buildTermMap([
        termMapTerm('11', 'child', parent: 'parent'),
        'not an element',
        termMapTerm('12', 'parent'),
    ]);

    expect(termMapField($map, '11', 'parent_id'))->toBe('12')
        ->and(termMapField($map, '11', 'full_slug'))->toBe('parent_child');
});

it('resolves a parent slug to the term in the child\'s own taxonomy, whatever the export order', function (array $terms): void {
    // WordPress slugs are unique only within a taxonomy, so a label and a category can
    // share one; the category's child must find the category, never the label
    $map = TermMapBuilder::buildTermMap($terms, ['logo-list-category']);

    expect(termMapField($map, '12', 'parent_id'))->toBe('9')
        ->and(termMapField($map, '12', 'full_slug'))->toBe('research_partners');
})->with([
    'the other taxonomy first' => [fn (): array => [termMapLabel(), termMapCategory(), termMapChild()]],
    'the own taxonomy first' => [fn (): array => [termMapCategory(), termMapLabel(), termMapChild()]],
    'the child first' => [fn (): array => [termMapChild(), termMapLabel(), termMapCategory()]],
]);

it('resolves a parent slug to the first term in the child\'s taxonomy that carries it', function (): void {
    // WordPress keeps a slug unique within a taxonomy, so this only arises in a
    // malformed export; the first match stays the parent, as before
    $map = TermMapBuilder::buildTermMap([
        termMapTerm('16', 'child', parent: 'shared'),
        termMapTerm('17', 'shared'),
        termMapTerm('18', 'shared'),
    ]);

    expect(termMapField($map, '16', 'parent_id'))->toBe('17');
});

it('resolves a parent slug the same way with no taxonomy filter', function (): void {
    $map = TermMapBuilder::buildTermMap([
        termMapTerm('13', 'shared', taxonomy: 'post_tag'),
        termMapTerm('14', 'child', parent: 'shared'),
        termMapTerm('15', 'shared'),
    ]);

    expect(termMapField($map, '14', 'parent_id'))->toBe('15')
        ->and(termMapField($map, '14', 'full_slug'))->toBe('shared_child');
});

it('keeps only the named taxonomies when given a filter, and keeps going past the rest', function (): void {
    $map = TermMapBuilder::buildTermMap([
        termMapTerm('16', 'a-tag', taxonomy: 'post_tag'),
        termMapTerm('17', 'a-category'),
    ], ['category']);

    expect(\array_keys($map))->toBe([17]);
});

it('compares taxonomy names to the filter strictly', function (): void {
    // An integer handle equals the numeric taxonomy name only under loose comparison
    $map = TermMapBuilder::buildTermMap([termMapTerm('18', 'numbered', taxonomy: '1')], [1]);

    expect($map)->toBe([]);
});

it('builds the full slug from every ancestor', function (): void {
    $map = TermMapBuilder::buildTermMap([
        termMapTerm('20', 'leaf', parent: 'branch'),
        termMapTerm('21', 'branch', parent: 'trunk'),
        termMapTerm('22', 'trunk'),
    ]);

    expect(termMapFullSlugs($map))->toBe([20 => 'trunk_branch_leaf', 21 => 'trunk_branch', 22 => 'trunk']);
});

it('keeps an empty parent id when the parent slug names a term only in another taxonomy', function (array $filter): void {
    $map = TermMapBuilder::buildTermMap([
        termMapTerm('23', 'child', parent: 'tagged'),
        termMapTerm('24', 'tagged', taxonomy: 'post_tag'),
    ], $filter);

    expect(termMapField($map, '23', 'parent_id'))->toBe('')
        ->and(termMapField($map, '23', 'full_slug'))->toBe('child');
})->with([
    'with the child\'s taxonomy as the filter' => [['category']],
    'with no filter' => [[]],
]);

it('stops a full slug at a parent cycle', function (): void {
    $map = TermMapBuilder::buildTermMap([
        termMapTerm('25', 'a', parent: 'b'),
        termMapTerm('26', 'b', parent: 'a'),
    ]);

    expect(termMapFullSlugs($map))->toBe([25 => 'a_b_a', 26 => 'b_a_b']);
});

it('tells ids apart that are equal only as numbers', function (): void {
    // '1' and '01' compare equal loosely; the cycle check must not mistake one for the other
    $map = TermMapBuilder::buildTermMap([
        termMapTerm('1', 'child', parent: 'middle'),
        termMapTerm('01', 'middle', parent: 'top'),
        termMapTerm('2', 'top'),
    ]);

    expect(termMapField($map, '1', 'full_slug'))->toBe('top_middle_child');
});

it('maps nicenames to ids for each named taxonomy', function (): void {
    $map = TermMapBuilder::buildNicenameToTermIdMap([
        termMapTerm('30', 'news'),
        termMapTerm('31', 'featured', taxonomy: 'post_tag'),
        termMapTerm('32', 'events'),
    ], ['category', 'post_tag', 'unused']);

    expect($map)->toBe([
        'category' => ['news' => '30', 'events' => '32'],
        'post_tag' => ['featured' => '31'],
        'unused' => [],
    ]);
});

it('skips taxonomy handles that are not non-empty strings, and keeps going', function (): void {
    $map = TermMapBuilder::buildNicenameToTermIdMap([termMapTerm('33', 'news')], ['', 7, 'category']);

    expect($map)->toBe(['category' => ['news' => '33']]);
});

it('skips non-elements and terms missing an id or a slug in the nicename map, and keeps going', function (): void {
    $map = TermMapBuilder::buildNicenameToTermIdMap([
        'not an element',
        termMapTerm('', 'no-id'),
        termMapTerm('34', ''),
        termMapTerm('35', 'kept'),
    ], ['category']);

    expect($map)->toBe(['category' => ['kept' => '35']]);
});
