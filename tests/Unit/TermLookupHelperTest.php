<?php

declare(strict_types=1);

/**
 * Tests that `TermLookupHelper` reads a term's name and full slug from the
 * export's term map, by taxonomy and slug or by term id, and falls back sensibly
 * when the term or the field is missing (#1314).
 *
 * @command  php vendor/bin/pest tests/Unit/TermLookupHelperTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\TermLookupHelper;

/**
 * A term map keyed by term id, as the importer builds it.
 *
 * @return array<int|string, mixed> The term map
 */
function termLookupMap(): array
{
    return [
        '5' => ['term_taxonomy' => 'category', 'term_slug' => 'news', 'term_name' => 'News', 'full_slug' => 'about/news'],
        '6' => ['term_taxonomy' => 'post_tag', 'term_slug' => 'news', 'term_name' => 'News Tag', 'full_slug' => 'news-tag'],
        '7' => ['term_taxonomy' => 'category', 'term_slug' => 'no-name', 'term_name' => '', 'full_slug' => ''],
        '8' => ['term_taxonomy' => 'category', 'term_slug' => 'bare'],
        '9' => 'not an array',
    ];
}

it('reads the name of the term matching both taxonomy and slug', function (): void {
    expect(TermLookupHelper::getTermNameFromMap(termLookupMap(), 'news', 'category'))->toBe('News')
        ->and(TermLookupHelper::getTermNameFromMap(termLookupMap(), 'news', 'post_tag'))->toBe('News Tag');
});

it('builds a name from the slug when the term or its name is missing', function (string $slug, string $taxonomy, string $expected): void {
    expect(TermLookupHelper::getTermNameFromMap(termLookupMap(), $slug, $taxonomy))->toBe($expected);
})->with([
    'no such term' => ['patient-care_tips', 'category', 'Patient Care Tips'],
    'the slug in another taxonomy' => ['bare', 'post_tag', 'Bare'],
    'an empty name' => ['no-name', 'category', 'No Name'],
    'no name key' => ['bare', 'category', 'Bare'],
]);

it('skips entries in the map that are not arrays', function (): void {
    $map = ['1' => 'loose', '2' => ['term_taxonomy' => 'category', 'term_slug' => 'news', 'term_name' => 'Latest News']];

    expect(TermLookupHelper::getTermNameFromMap($map, 'news', 'category'))->toBe('Latest News');
});

it('reads the full slug of the term matching both taxonomy and slug', function (): void {
    expect(TermLookupHelper::getFullSlugFromMap(termLookupMap(), 'news', 'category'))->toBe('about/news')
        ->and(TermLookupHelper::getFullSlugFromMap(termLookupMap(), 'news', 'post_tag'))->toBe('news-tag');
});

it('falls back to the slug when the term or its full slug is missing', function (string $slug, string $taxonomy): void {
    expect(TermLookupHelper::getFullSlugFromMap(termLookupMap(), $slug, $taxonomy))->toBe($slug);
})->with([
    'no such term' => ['missing', 'category'],
    'an empty full slug' => ['no-name', 'category'],
    'no full slug key' => ['bare', 'category'],
]);

it('reads the full slug by term id, falling back to the term slug', function (string $termId, string $expected): void {
    expect(TermLookupHelper::getFullSlugFromMapById(termLookupMap(), $termId))->toBe($expected);
})->with([
    'a full slug' => ['5', 'about/news'],
    'an empty full slug' => ['7', 'no-name'],
    'no full slug key' => ['8', 'bare'],
    'an entry that is not an array' => ['9', ''],
    'an unknown id' => ['404', ''],
]);

it('reads nothing by id from an entry that only reads like an array', function (): void {
    $map = ['3' => new ArrayObject(['full_slug' => 'about/news', 'term_slug' => 'news', 'term_name' => 'News'])];

    expect(TermLookupHelper::getFullSlugFromMapById($map, '3'))->toBe('')
        ->and(TermLookupHelper::getTermNameFromMapById($map, '3'))->toBe('');
});

it('reads no full slug by id when the term has neither slug', function (): void {
    expect(TermLookupHelper::getFullSlugFromMapById(['3' => ['term_slug' => 4]], '3'))->toBe('');
});

it('reads the term name by term id', function (string $termId, string $expected): void {
    expect(TermLookupHelper::getTermNameFromMapById(termLookupMap(), $termId))->toBe($expected);
})->with([
    'a name' => ['5', 'News'],
    'an empty name' => ['7', ''],
    'no name key' => ['8', ''],
    'an entry that is not an array' => ['9', ''],
    'an unknown id' => ['404', ''],
]);
