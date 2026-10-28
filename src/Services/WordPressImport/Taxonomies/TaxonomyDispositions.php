<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies;

use UAMSWeb\WordPressImporter\Console\Commands\ImportWordPress;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;

/**
 * Records the destination *shape* each WordPress taxonomy imports into.
 *
 * A WordPress taxonomy lands in Statamic as one of three things: a flat taxonomy
 * of terms, a **structured collection** whose entries carry the parent/child
 * tree, or **nowhere at all** — recorded as {@see self::SHAPE_UNMAPPED} because
 * the decision is that it has no destination.
 *
 * The third shape is the one to keep in mind when reading a caller. Testing
 * {@see self::isRecorded()} as a proxy for "imports flat" was correct while there
 * were two shapes and is wrong now: an unmapped taxonomy is recorded *precisely*
 * because nothing receives it. Ask {@see self::isUnmapped()} — or compare
 * {@see self::shapeFor()} against the constant you mean — rather than inferring a
 * shape from mere presence in the table. `UAMS-Web/uams-statamic#252` settled that hierarchy is delivered by
 * native structured collections rather than a separate "Structured Taxonomies"
 * concept, and `UAMS-Web/uams-statamic#1863` converted `people_groups` and
 * `logos_item_categories` from flat taxonomies into exactly that.
 *
 * That conversion is why this class exists. The destination *handles* were
 * already correct in {@see ImportWordPress::getTaxonomyMappings()},
 * but every entry there is typed `taxonomy`, so the importer kept writing
 * taxonomy terms to four handles that are no longer taxonomies —
 * `Taxonomy::findByHandle('people_groups')` now returns null. Handles stay in
 * that table (one source of truth); the shape lives here, and the two are read
 * together.
 *
 * Dispositions are the ones pinned on `UAMS-Web/wordpress-importer-archive#13`. A
 * taxonomy absent from this table has no recorded disposition and is treated as
 * flat, which is the pre-existing behavior.
 */
final class TaxonomyDispositions
{
    /**
     * The destination is a flat Statamic taxonomy of terms.
     */
    public const string SHAPE_TAXONOMY = 'taxonomy';

    /**
     * The destination is a structured Statamic collection carrying a tree.
     */
    public const string SHAPE_COLLECTION = 'collection';

    /**
     * The taxonomy has no Statamic destination, by decision rather than omission.
     *
     * Its terms are not imported. The import reports what was discarded so the
     * loss is visible; see wordpress-importer-archive#741 and `docs/DECISIONS.md`.
     */
    public const string SHAPE_UNMAPPED = 'unmapped';

    /**
     * WordPress taxonomy handle to destination shape.
     *
     * `tshowcase-categories` shares `people_groups` with `uamspeople-categories`
     * rather than getting a collection of its own, per the pinned decision.
     *
     * @var array<string, string>
     */
    // PCOV records no line for a class constant, so the mutation plugin never runs a mutant of
    // these items; removing each one by hand fails TaxonomyDispositionsTableTest (#102)
    // @pest-mutate-ignore: RemoveArrayItem
    private const array SHAPES = [
        // Structured — hierarchy preserved as a collection tree.
        'category' => self::SHAPE_COLLECTION,
        'uamspeople-categories' => self::SHAPE_COLLECTION,
        'tshowcase-categories' => self::SHAPE_COLLECTION,
        'logo-list-category' => self::SHAPE_COLLECTION,

        // ---
        // Site-scoped — flat in WordPress, but a Statamic taxonomy term is shared
        // by every site the taxonomy is enabled on, so a flat destination had to
        // carry the site in each title and slug. `wordpress-importer-archive#1215` moved these four to
        // collections, where an entry belongs to one site and keeps its plain
        // name; the host converted each (`UAMS-Web/uams-statamic#2532`–`#2535`).
        // ---
        'post_tag' => self::SHAPE_COLLECTION,
        'logo-list-label' => self::SHAPE_COLLECTION,
        'kbtag' => self::SHAPE_COLLECTION,
        'mkb_faq_category' => self::SHAPE_COLLECTION,

        // Flat — inherently non-hierarchical in WordPress.
        // WP Adverts' categories are flat in WordPress and land in the flat
        // `classified_categories` taxonomy UAMS-Web/uams-statamic#2096 built (#774).
        'advert_category' => self::SHAPE_TAXONOMY,

        // Unmapped — recorded as having no Statamic destination. Both Team
        // Showcase and its UAMSWP fork expose three optional secondary
        // taxonomies behind per-site toggles; none has a destination, and
        // recording them stops a toggle turning into a silent loss.
        'tshowcase-taxonomy' => self::SHAPE_UNMAPPED,
        'tshowcase-ctaxonomy' => self::SHAPE_UNMAPPED,
        'tshowcase-dtaxonomy' => self::SHAPE_UNMAPPED,
        'uamspeople-taxonomy' => self::SHAPE_UNMAPPED,
        'uamspeople-ctaxonomy' => self::SHAPE_UNMAPPED,
        'uamspeople-dtaxonomy' => self::SHAPE_UNMAPPED,

        // ---
        // WordPress and plugin internals. None describes editorial content, none
        // has a Statamic destination, and nothing else imports what they hold —
        // so the term pass reporting their terms as discarded is accurate.
        //
        // `workflow_state` is representative: its terms are `Initial Draft`,
        // `In Progress` and `Final`, an editorial workflow's own bookkeeping
        // rather than anything a reader sees.
        //
        // `nav_menu` deliberately does NOT belong here, though it looks like it
        // should. Navigation IS imported, through `nav_menu_item` posts read
        // straight from the export by
        // {@see \UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\NavigationMenuSelector}.
        // Recording it would make the term pass announce "N term(s) not
        // imported" for menus that imported fine — so it is left unrecorded and
        // tracked as owned-elsewhere, exactly as `kbtopic` is.
        // ---
        'wp_theme' => self::SHAPE_UNMAPPED,
        'link_category' => self::SHAPE_UNMAPPED,
        'content_audit' => self::SHAPE_UNMAPPED,
        'seopress_404_cat' => self::SHAPE_UNMAPPED,
        'workflow_state' => self::SHAPE_UNMAPPED,
        'wp_pattern_category' => self::SHAPE_UNMAPPED,
    ];

    /**
     * Statamic handles that are structured collections rather than taxonomies.
     *
     * The inverse of the collection rows in {@see self::SHAPES}, kept explicitly
     * so a caller holding only the *destination* handle — which is all
     * {@see BaseEntryCreator::createTaxonomyTermsFromStoredData()}
     * receives — can still tell the two apart.
     *
     * @var list<string>
     */
    // PCOV records no line for a class constant, so the mutation plugin never runs a mutant of
    // these items; removing each one by hand fails TaxonomyDispositionsTableTest (#102)
    // @pest-mutate-ignore: RemoveArrayItem
    private const array STRUCTURED_DESTINATIONS = [
        'post_categories',
        'people_groups',
        'logos_item_categories',
        'post_tags',
        'logos_item_labels',
        'knowledge_base_tags',
        'faq_categories',
    ];

    /**
     * Determine whether a Statamic handle names a structured collection.
     *
     * @param  string  $statamicHandle  The Statamic destination handle
     * @return bool True when the handle is a collection, not a taxonomy
     */
    public static function isStructuredDestination(
        string $statamicHandle
    ): bool {
        // The handles are strings, so in_array()'s strict flag, which phpstan-strict-rules
        // requires, changes no result
        // @pest-mutate-ignore: TrueToFalse
        return \in_array(
            $statamicHandle,
            self::STRUCTURED_DESTINATIONS,
            true
        );
    }

    /**
     * Return the destination shape for a WordPress taxonomy.
     *
     * @param  string  $wordpressTaxonomy  The WordPress taxonomy handle
     * @return string One of the SHAPE_* constants; SHAPE_TAXONOMY when unrecorded
     */
    public static function shapeFor(
        string $wordpressTaxonomy
    ): string {
        return self::SHAPES[$wordpressTaxonomy] ?? self::SHAPE_TAXONOMY;
    }

    /**
     * Determine whether a WordPress taxonomy imports into a structured collection.
     *
     * @param  string  $wordpressTaxonomy  The WordPress taxonomy handle
     * @return bool True when the destination is a structured collection
     */
    public static function isStructured(
        string $wordpressTaxonomy
    ): bool {
        return self::shapeFor($wordpressTaxonomy) === self::SHAPE_COLLECTION;
    }

    /**
     * Determine whether a WordPress taxonomy is recorded as having no destination.
     *
     * Distinct from an unrecorded handle: {@see self::isRecorded()} is true for
     * these, because the absence of a destination is the decision.
     *
     * @param  string  $wordpressTaxonomy  The WordPress taxonomy handle
     * @return bool True when the taxonomy is deliberately unmapped
     */
    public static function isUnmapped(
        string $wordpressTaxonomy
    ): bool {
        return self::shapeFor($wordpressTaxonomy) === self::SHAPE_UNMAPPED;
    }

    /**
     * Determine whether a WordPress taxonomy has a recorded disposition.
     *
     * Distinguishes "recorded as flat" from "not in the table", which
     * {@see self::shapeFor()} deliberately collapses.
     *
     * @param  string  $wordpressTaxonomy  The WordPress taxonomy handle
     * @return bool True when the taxonomy appears in the disposition table
     */
    public static function isRecorded(
        string $wordpressTaxonomy
    ): bool {
        return isset(self::SHAPES[$wordpressTaxonomy]);
    }

    /**
     * Return every WordPress taxonomy that imports into a structured collection.
     *
     * @return list<string> The structured WordPress taxonomy handles
     */
    public static function structuredTaxonomies(): array
    {
        return \array_keys(
            \array_filter(
                self::SHAPES,
                static fn (string $shape): bool => $shape === self::SHAPE_COLLECTION
            )
        );
    }
}
