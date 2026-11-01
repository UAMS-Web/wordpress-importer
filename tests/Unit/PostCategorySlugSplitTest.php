<?php

declare(strict_types=1);

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Taxonomies\PostCategoryEntryResolver;

/**
 * Tests for PostCategoryEntryResolver::splitSlugs().
 *
 * A WordPress news block's `news_category` carries one slug or several separated
 * by commas — the export corpus holds values such as `college-of-pharmacy, jobs`
 * and `bee-award, daisy-award, daisy-nurse-leader-award, aotm`, with a space
 * after each separator. Splitting is pure string work with no lookup, so it sits
 * in the bare-TestCase band; the lookup itself is exercised in the Statamic band.
 *
 * @command  php vendor/bin/pest tests/Unit/PostCategorySlugSplitTest.php
 */
it('reads a single slug as one entry', function (): void {
    // The common case: one category, no separator
    expect(PostCategoryEntryResolver::splitSlugs('news'))
        ->toBe(['news']);
});

it('splits a comma-separated value and trims the separator whitespace', function (): void {
    // Corpus shape: the separator is followed by a space
    expect(PostCategoryEntryResolver::splitSlugs('college-of-pharmacy, jobs'))
        ->toBe(['college-of-pharmacy', 'jobs']);
});

it('splits a value naming four categories', function (): void {
    // The widest corpus value, to prove nothing is dropped past the second slug
    expect(PostCategoryEntryResolver::splitSlugs('bee-award, daisy-award, daisy-nurse-leader-award, aotm'))
        ->toBe(['bee-award', 'daisy-award', 'daisy-nurse-leader-award', 'aotm']);
});

it('preserves the order the block declared', function (): void {
    // Order is the block author's intent and is carried into the picker
    expect(PostCategoryEntryResolver::splitSlugs('zebra, alpha, middle'))
        ->toBe(['zebra', 'alpha', 'middle']);
});

it('drops empty segments left by a stray separator', function (): void {
    // A trailing or doubled comma must not produce an empty slug
    expect(PostCategoryEntryResolver::splitSlugs('news,, ,research,'))
        ->toBe(['news', 'research']);
});

it('collapses a slug repeated in the same value', function (): void {
    // The picker stores each reference once, so resolve each slug once
    expect(PostCategoryEntryResolver::splitSlugs('news, research, news'))
        ->toBe(['news', 'research']);
});

it('keeps reading past a repeat that is not the last slug', function (): void {
    // Dropping a duplicate must skip that one slug, not abandon the rest
    expect(PostCategoryEntryResolver::splitSlugs('news, research, news, jobs'))
        ->toBe(['news', 'research', 'jobs']);
});

it('does not treat two numerically-equal slugs as the same slug', function (): void {
    // PHP compares two numeric strings as numbers unless the check is strict,
    // which would silently collapse these into one category
    expect(PostCategoryEntryResolver::splitSlugs('1e2, 100'))
        ->toBe(['1e2', '100']);
});

it('reads an empty value as no categories at all', function (): void {
    // 6 of the corpus occurrences carry an empty `news_category`
    expect(PostCategoryEntryResolver::splitSlugs(''))
        ->toBe([]);
});

it('reads a whitespace-only value as no categories at all', function (): void {
    // Whitespace is not a slug, and must not become one
    expect(PostCategoryEntryResolver::splitSlugs('   '))
        ->toBe([]);
});

it('does not treat the taxonomy-prefixed form as a slug to split', function (): void {
    // The field stores entry ids now, not `post_categories::<slug>` term refs —
    // a prefixed value is a single opaque string, not two slugs
    expect(PostCategoryEntryResolver::splitSlugs('post_categories::news'))
        ->toBe(['post_categories::news']);
});
