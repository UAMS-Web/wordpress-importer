<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\TaxonomyDispositions;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\TermDestinationRegistry;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\KbLinkRegistry;
use UAMSWeb\WordPressImporter\Tests\Support\ExportsCorpus;

/**
 * Tests the taxonomy disposition table and the term destination registry (issue wordpress-importer-archive#13).
 *
 * `UAMS-Web/uams-statamic#1863` converted `people_groups` and
 * `logos_item_categories` into structured collections, joining `post_categories`.
 * The importer's mapping table still types all of them `taxonomy`, so the
 * disposition recorded here is what routes a term to a collection entry instead
 * of a taxonomy term — get it wrong and the terms are written to a handle
 * `Taxonomy::findByHandle()` no longer resolves, which fails silently.
 *
 * Both classes are pure PHP: the disposition table is a constant lookup, and the
 * registry is an in-memory map whose only collaborator, `KbLinkRegistry`, is
 * itself static. Neither touches the container.
 *
 * @command  php vendor/bin/pest tests/Unit/TaxonomyDestinationTest.php
 */
beforeEach(function (): void {
    TermDestinationRegistry::clear();
    KbLinkRegistry::clear();
});

afterEach(function (): void {
    TermDestinationRegistry::clear();
    KbLinkRegistry::clear();
});

it('routes every hierarchical WordPress taxonomy to a collection', function (string $wordpressTaxonomy): void {
    expect(TaxonomyDispositions::isStructured($wordpressTaxonomy))->toBeTrue()
        ->and(TaxonomyDispositions::shapeFor($wordpressTaxonomy))
        ->toBe(TaxonomyDispositions::SHAPE_COLLECTION);
})->with([
    'category',
    'uamspeople-categories',
    'tshowcase-categories',
    'logo-list-category',
]);

it('routes the four site-scoped WordPress taxonomies to a collection', function (string $wordpressTaxonomy): void {
    // Flat in WordPress, but a Statamic term is shared across sites, so wordpress-importer-archive#1215
    // moved these to collections, where an entry belongs to one site (wordpress-importer-archive#1220).
    expect(TaxonomyDispositions::isStructured($wordpressTaxonomy))->toBeTrue()
        ->and(TaxonomyDispositions::shapeFor($wordpressTaxonomy))
        ->toBe(TaxonomyDispositions::SHAPE_COLLECTION);
})->with([
    'post_tag',
    'logo-list-label',
    'kbtag',
    'mkb_faq_category',
]);

it('leaves the inherently flat taxonomies as taxonomies', function (string $wordpressTaxonomy): void {
    expect(TaxonomyDispositions::isStructured($wordpressTaxonomy))->toBeFalse()
        ->and(TaxonomyDispositions::shapeFor($wordpressTaxonomy))
        ->toBe(TaxonomyDispositions::SHAPE_TAXONOMY);
})->with([
    'advert_category',
]);

it('treats an unrecorded taxonomy as flat without claiming it was recorded', function (): void {
    // Defaulting to flat preserves the pre-existing behavior for anything the
    // decision did not disposition; isRecorded() keeps that distinguishable.
    //
    // The probe is a handle WordPress will never emit, deliberately. Every real
    // unrecorded handle is unrecorded only until its owning ticket settles —
    // `advert_category` stood here until wordpress-importer-archive#743 — so pinning this case to one of
    // them makes the test expire silently the day that decision lands.
    expect(TaxonomyDispositions::shapeFor('not-a-wordpress-taxonomy'))
        ->toBe(TaxonomyDispositions::SHAPE_TAXONOMY)
        ->and(TaxonomyDispositions::isRecorded('not-a-wordpress-taxonomy'))->toBeFalse()
        ->and(TaxonomyDispositions::isRecorded('post_tag'))->toBeTrue();
});

it('lists exactly the structured taxonomies', function (): void {
    expect(TaxonomyDispositions::structuredTaxonomies())
        ->toEqualCanonicalizing([
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

it('recognizes a structured destination from the Statamic handle alone', function (string $statamicHandle): void {
    // `createTaxonomyTermsFromStoredData()` only ever sees the destination
    // handle, so this is what stops it writing terms into a collection.
    expect(TaxonomyDispositions::isStructuredDestination($statamicHandle))->toBeTrue();
})->with([
    'post_categories',
    'people_groups',
    'logos_item_categories',
    'post_tags',
    'logos_item_labels',
    'knowledge_base_tags',
    'faq_categories',
]);

it('does not mistake a flat taxonomy handle for a collection', function (string $statamicHandle): void {
    expect(TaxonomyDispositions::isStructuredDestination($statamicHandle))->toBeFalse();
})->with([
    'classified_categories',
]);

it('covers every structured taxonomy with a structured destination', function (): void {
    // The two tables are maintained separately, so a taxonomy dispositioned to a
    // collection whose handle is absent from the destination list would route
    // correctly through the term pass and still be written by a direct caller.
    $destinations = [
        'category' => 'post_categories',
        'uamspeople-categories' => 'people_groups',
        'tshowcase-categories' => 'people_groups',
        'logo-list-category' => 'logos_item_categories',
        'post_tag' => 'post_tags',
        'logo-list-label' => 'logos_item_labels',
        'kbtag' => 'knowledge_base_tags',
        'mkb_faq_category' => 'faq_categories',
    ];

    foreach (TaxonomyDispositions::structuredTaxonomies() as $wordpressTaxonomy) {
        expect($destinations)->toHaveKey($wordpressTaxonomy)
            ->and(TaxonomyDispositions::isStructuredDestination($destinations[$wordpressTaxonomy]))
            ->toBeTrue();
    }
});

it('sends both people taxonomies to the same destination', function (): void {
    // `tshowcase-categories` shares `people_groups` rather than getting a
    // collection of its own, per the pinned decision.
    expect(TaxonomyDispositions::shapeFor('tshowcase-categories'))
        ->toBe(TaxonomyDispositions::shapeFor('uamspeople-categories'));
});

it('resolves a term registered as a collection entry', function (): void {
    TermDestinationRegistry::registerCollectionEntry('category', '42', 'post_categories', 'entry-abc');

    expect(TermDestinationRegistry::resolve('category', '42'))->toBe([
        'shape' => TaxonomyDispositions::SHAPE_COLLECTION,
        'handle' => 'post_categories',
        'reference' => 'entry-abc',
    ])->and(TermDestinationRegistry::referenceFor('category', '42'))->toBe('entry-abc');
});

it('resolves a term registered as a flat taxonomy term', function (): void {
    TermDestinationRegistry::registerTaxonomyTerm('post_tag', '7', 'post_tags', 'news-tag');

    expect(TermDestinationRegistry::resolve('post_tag', '7'))->toBe([
        'shape' => TaxonomyDispositions::SHAPE_TAXONOMY,
        'handle' => 'post_tags',
        'reference' => 'news-tag',
    ]);
});

it('treats an integer and a string term id as the same term', function (): void {
    // Callers hand ids over from XML as strings and from PHP arrays as ints;
    // keying them apart would silently lose half the lookups.
    TermDestinationRegistry::registerCollectionEntry('category', 42, 'post_categories', 'entry-abc');

    expect(TermDestinationRegistry::referenceFor('category', '42'))->toBe('entry-abc');
});

it('does not confuse two taxonomies that share a term id', function (): void {
    TermDestinationRegistry::registerCollectionEntry('category', '1', 'post_categories', 'entry-cat');
    TermDestinationRegistry::registerCollectionEntry('logo-list-category', '1', 'logos_item_categories', 'entry-logo');

    expect(TermDestinationRegistry::referenceFor('category', '1'))->toBe('entry-cat')
        ->and(TermDestinationRegistry::referenceFor('logo-list-category', '1'))->toBe('entry-logo');
});

it('keeps two terms of the same taxonomy apart', function (): void {
    // The sibling case to the one above, and the one that actually bites: if the
    // storage key dropped the term id, every term in a taxonomy would collapse
    // onto one entry and the last import would win silently.
    TermDestinationRegistry::registerCollectionEntry('category', '10', 'post_categories', 'entry-health');
    TermDestinationRegistry::registerCollectionEntry('category', '20', 'post_categories', 'entry-cardiology');

    expect(TermDestinationRegistry::referenceFor('category', '10'))->toBe('entry-health')
        ->and(TermDestinationRegistry::referenceFor('category', '20'))->toBe('entry-cardiology')
        ->and(TermDestinationRegistry::all())->toHaveCount(2);
});

it('exposes the destinations it recorded', function (): void {
    TermDestinationRegistry::registerCollectionEntry('category', '10', 'post_categories', 'entry-health');

    expect(TermDestinationRegistry::all())->toHaveCount(1)
        ->and(TermDestinationRegistry::all())->toBe([
            'category::10' => [
                'shape' => TaxonomyDispositions::SHAPE_COLLECTION,
                'handle' => 'post_categories',
                'reference' => 'entry-health',
            ],
        ]);
});

it('returns null for a term that was never registered', function (): void {
    expect(TermDestinationRegistry::resolve('category', '999'))->toBeNull()
        ->and(TermDestinationRegistry::referenceFor('category', '999'))->toBeNull();
});

it('delegates kbtopic lookups to KbLinkRegistry rather than absorbing them', function (): void {
    // The decision on wordpress-importer-archive#13 keeps KbLinkRegistry the owner of kbtopic; this
    // registry forwards so callers still see one API. Registering nothing here
    // and still resolving proves the delegation is real.
    KbLinkRegistry::registerKbtopicMappingByTermId('55', 'kb-entry-1');

    expect(TermDestinationRegistry::referenceFor('kbtopic', '55'))->toBe('kb-entry-1')
        ->and(TermDestinationRegistry::resolve('kbtopic', '55'))->toBe([
            'shape' => TaxonomyDispositions::SHAPE_COLLECTION,
            'handle' => 'knowledge_bases',
            'reference' => 'kb-entry-1',
        ])
        ->and(TermDestinationRegistry::all())->toBe([]);
});

it('returns null for an unknown kbtopic term rather than a partial destination', function (): void {
    expect(TermDestinationRegistry::resolve('kbtopic', '404'))->toBeNull();
});

it('clears every recorded destination', function (): void {
    TermDestinationRegistry::registerCollectionEntry('category', '1', 'post_categories', 'entry-cat');

    TermDestinationRegistry::clear();

    expect(TermDestinationRegistry::all())->toBe([])
        ->and(TermDestinationRegistry::referenceFor('category', '1'))->toBeNull();
});

it('records both plugins\' secondary taxonomies as deliberately unmapped', function (string $wordpressTaxonomy): void {
    // Recorded, but with no destination — the absence IS the decision (wordpress-importer-archive#741),
    // which is what separates these from a handle nobody has considered.
    expect(TaxonomyDispositions::isUnmapped($wordpressTaxonomy))->toBeTrue()
        ->and(TaxonomyDispositions::isRecorded($wordpressTaxonomy))->toBeTrue()
        ->and(TaxonomyDispositions::isStructured($wordpressTaxonomy))->toBeFalse()
        ->and(TaxonomyDispositions::shapeFor($wordpressTaxonomy))
        ->toBe(TaxonomyDispositions::SHAPE_UNMAPPED);
})->with([
    'tshowcase-taxonomy',
    'tshowcase-ctaxonomy',
    'tshowcase-dtaxonomy',
    'uamspeople-taxonomy',
    'uamspeople-ctaxonomy',
    'uamspeople-dtaxonomy',
]);

it('leaves the routed taxonomies unmapped-free', function (string $wordpressTaxonomy): void {
    // The bound on the other side: recording the secondary taxonomies must not
    // capture the main one each plugin routes to `people_groups`.
    expect(TaxonomyDispositions::isUnmapped($wordpressTaxonomy))->toBeFalse();
})->with([
    'tshowcase-categories',
    'uamspeople-categories',
    'category',
    'post_tag',
    'kbtag',
]);

it('records the WordPress and plugin internals as unmapped', function (string $wordpressTaxonomy): void {
    // None of these describes editorial content or has a Statamic destination.
    // Recording them is what lets a `false` from isRecorded() mean "nobody has
    // looked at this" rather than "looked at and declined" (wordpress-importer-archive#743).
    expect(TaxonomyDispositions::isUnmapped($wordpressTaxonomy))->toBeTrue()
        ->and(TaxonomyDispositions::isRecorded($wordpressTaxonomy))->toBeTrue()
        ->and(TaxonomyDispositions::isStructured($wordpressTaxonomy))->toBeFalse();
})->with([
    'wp_theme',
    'link_category',
    'content_audit',
    'seopress_404_cat',
    'workflow_state',
    'wp_pattern_category',
]);

it('does not treat an unrecorded taxonomy as deliberately unmapped', function (): void {
    // `shapeFor()` still defaults an unrecorded handle to flat, so a taxonomy
    // from a site nobody has exported yet imports rather than vanishing.
    expect(TaxonomyDispositions::isUnmapped('not-a-wordpress-taxonomy'))->toBeFalse()
        ->and(TaxonomyDispositions::shapeFor('not-a-wordpress-taxonomy'))
        ->toBe(TaxonomyDispositions::SHAPE_TAXONOMY);
});

it('leaves no taxonomy in the corpus unrecorded and unowned', function (): void {
    // ---
    // The catch-the-next-one test (wordpress-importer-archive#743). Every taxonomy the WXR corpus declares
    // must either carry a recorded disposition or be one of the handles a named
    // ticket owns. A handle in neither set is one nobody has decided about, and
    // the point is to fail here rather than have it discovered during an import.
    //
    // Skipped when WORDPRESS_IMPORTER_EXPORTS is unset, which is its normal
    // state: the corpus is private and lives outside this repository, so the
    // gate must not start requiring a clone of it (wordpress-importer-archive#1426).
    // ---
    $corpus = ExportsCorpus::path('WordPress');

    if ($corpus === null) {
        Assert::markTestSkipped(ExportsCorpus::SKIP_MESSAGE);
    }

    // A clone with no exports is a broken clone, not an absent corpus
    $exports = \glob($corpus.'/*.xml');

    expect($exports)->toBeArray()->not->toBeEmpty();

    /** @var list<string> $exports */

    // Handles deliberately left unrecorded, each with a ticket that owns the call.
    $ownedElsewhere = [
        'kbtopic',              // bespoke path in KbEntryCreator; never a Statamic taxonomy
        'nav_menu',             // menus import as nav_menu_item posts; recording it would report them as lost
        'post_format',          // PostEntryCreator reads it to pick the entry variant; never a destination
        'ld_course_category',   // #24 — open research spike on LearnDash
        'ld_lesson_category',   // #24
    ];

    $declared = [];

    // Collect every taxonomy the corpus declares, across all exports.
    foreach ($exports as $export) {
        $contents = \file_get_contents($export);

        if ($contents === false) {
            continue;
        }

        // ---
        // Both encodings, deliberately. WordPress writes CDATA, but many exports
        // carry the bare form — and `WxrMaterializer` emits ONLY the bare form,
        // through `XMLWriter::writeElement()`, so a CDATA-only pattern is blind to
        // every export the migration-API path materializes.
        // ---
        \preg_match_all(
            '/<wp:term_taxonomy>(?:<!\[CDATA\[)?([^\]<]+)/',
            $contents,
            $matches
        );

        foreach ($matches[1] as $taxonomy) {
            $declared[$taxonomy] = true;
        }

        // ---
        // A taxonomy can reach an item WITHOUT declaring a <wp:term> block —
        // `post_format` and `wp_pattern_category` appear only as a category
        // domain. Scanning term declarations alone leaves those invisible to a
        // guard whose whole job is to have no blind spots.
        // ---
        \preg_match_all(
            '/<category domain="([^"]+)"/',
            $contents,
            $domains
        );

        foreach ($domains[1] as $taxonomy) {
            $declared[$taxonomy] = true;
        }

        unset($contents, $matches, $domains);
    }

    // Anything neither recorded nor owned is an undecided handle.
    $undecided = \array_values(
        \array_filter(
            \array_keys($declared),
            static fn (string $taxonomy): bool => ! TaxonomyDispositions::isRecorded($taxonomy)
                && ! \in_array($taxonomy, $ownedElsewhere, true)
        )
    );

    // ---
    // A floor the scan must clear, not merely "it found something". `not->toBe([])`
    // is satisfied by one match in one file out of 259, so a pattern that quietly
    // stops seeing most of the corpus still passes — which is exactly how the
    // CDATA-only version missed `workflow_state` across six exports.
    //
    // These four span both encodings and several export families, so a narrowing
    // pattern breaks at least one of them.
    // ---
    expect(\array_keys($declared))
        ->toContain('post_tag')
        ->toContain('category')
        ->toContain('nav_menu')
        ->toContain('workflow_state')
        ->toContain('post_format')
        ->and($undecided)->toBe([]);
});
