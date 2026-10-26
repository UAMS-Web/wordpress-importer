<?php

declare(strict_types=1);

/**
 * Tests that a post's WordPress categories resolve to `post_categories` entry ids.
 *
 * `UAMS-Web/uams-statamic#1433` migrated `post_categories` from a flat taxonomy
 * to a structured collection and repointed the `posts` field from a `terms`
 * reference to an `entries` one. The importer kept writing site-prefixed term
 * slugs under a `post_category` key, so every imported post landed with a value
 * the field could not resolve, under a handle no blueprint defined (wordpress-importer-archive#422).
 *
 * `StructuredTermImporter` creates the collection entries during the term pass
 * and records each one in `TermDestinationRegistry`; the method under test turns
 * a post's own category nicenames into that list of entry ids. The registry is a
 * static in-memory map, so this needs no container and no Statamic boot.
 *
 * @command  php vendor/bin/pest tests/Unit/PostCategoryEntryReferenceTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators\PostEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\TermDestinationRegistry;

beforeEach(function (): void {
    TermDestinationRegistry::clear();

    $this->creator = new PostEntryCreator;

    // nicename => WordPress term id, the shape PostEntryCreator holds for 'category'
    $this->map = [
        'research' => '3851',
        'patient-care' => '3853',
        'education' => '1',
    ];

    // What the term pass records once each category is imported as an entry
    TermDestinationRegistry::registerCollectionEntry('category', '3851', 'post_categories', 'entry-research');
    TermDestinationRegistry::registerCollectionEntry('category', '3853', 'post_categories', 'entry-patient-care');
    TermDestinationRegistry::registerCollectionEntry('category', '1', 'post_categories', 'entry-education');
});

afterEach(function (): void {
    TermDestinationRegistry::clear();
});

it('resolves every category to its collection entry id', function (): void {
    $references = $this->creator->resolveCategoryEntryReferences(
        ['research', 'patient-care', 'education'],
        $this->map
    );

    expect($references)->toBe(['entry-research', 'entry-patient-care', 'entry-education']);
});

it('preserves the order it is given, so the primary category stays first', function (): void {
    // ---
    // The caller has already applied Yoast-primary-first ordering, and the
    // newsroom treats the first entry as the primary category shown on cards and
    // breadcrumbs. Re-sorting here would silently undo that.
    // ---
    $references = $this->creator->resolveCategoryEntryReferences(
        ['patient-care', 'research', 'education'],
        $this->map
    );

    expect($references)->toBe(['entry-patient-care', 'entry-research', 'entry-education']);
});

it('omits a category with no recorded destination rather than writing its slug', function (): void {
    // ---
    // `category` is a collection destination unconditionally, so there is no flat
    // taxonomy for a slug to resolve against — a slug fallback would restore the
    // dangling value this whole change removes.
    // ---
    TermDestinationRegistry::clear();
    TermDestinationRegistry::registerCollectionEntry('category', '3851', 'post_categories', 'entry-research');

    $references = $this->creator->resolveCategoryEntryReferences(
        ['research', 'patient-care'],
        $this->map
    );

    expect($references)->toBe(['entry-research'])
        ->and($references)->not->toContain('patient-care')
        ->and($references)->not->toContain('default_patient-care');
});

it('omits a category the term-id map does not know', function (): void {
    // A nicename the export carries on the post but never declares as a term
    $references = $this->creator->resolveCategoryEntryReferences(
        ['research', 'never-declared'],
        $this->map
    );

    expect($references)->toBe(['entry-research']);
});

it('omits a category whose term id is empty', function (mixed $termId): void {
    $references = $this->creator->resolveCategoryEntryReferences(
        ['research'],
        ['research' => $termId]
    );

    expect($references)->toBe([]);
})->with([
    'empty string' => [''],
    'whitespace' => ['   '],
    'null' => [null],
]);

it('collapses two nicenames that resolve to the same entry', function (): void {
    // Two WordPress terms can be merged into one Statamic entry by slug match
    TermDestinationRegistry::registerCollectionEntry('category', '3853', 'post_categories', 'entry-research');

    $references = $this->creator->resolveCategoryEntryReferences(
        ['research', 'patient-care'],
        $this->map
    );

    expect($references)->toBe(['entry-research']);
});

it('does not resolve a category through another taxonomy registration', function (): void {
    // ---
    // The registry is keyed by "{taxonomy}::{termId}", and WordPress term ids are
    // unique per term, not per taxonomy — a `post_tag` sharing an id with a
    // category must not leak into the category field.
    // ---
    TermDestinationRegistry::clear();
    TermDestinationRegistry::registerTaxonomyTerm('post_tag', '3851', 'post_tags', 'default_research');

    $references = $this->creator->resolveCategoryEntryReferences(
        ['research'],
        $this->map
    );

    expect($references)->toBe([]);
});

it('returns a list with sequential keys, not a gapped array', function (): void {
    // ---
    // The omissions above skip elements, so the result has to be re-keyed or it
    // serializes to a YAML map — which an `entries` field does not accept.
    // ---
    TermDestinationRegistry::clear();
    TermDestinationRegistry::registerCollectionEntry('category', '3853', 'post_categories', 'entry-patient-care');

    $references = $this->creator->resolveCategoryEntryReferences(
        ['research', 'patient-care', 'education'],
        $this->map
    );

    expect(array_keys($references))->toBe([0]);
});

it('returns nothing for a post with no categories', function (): void {
    expect($this->creator->resolveCategoryEntryReferences([], $this->map))->toBe([]);
});

it('returns nothing when the term pass recorded no destinations', function (): void {
    // The state on a run where the export declares no categories at all
    TermDestinationRegistry::clear();

    expect($this->creator->resolveCategoryEntryReferences(['research'], $this->map))->toBe([]);
});
