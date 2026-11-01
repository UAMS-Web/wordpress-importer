<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Navigation;

use SimpleXMLElement;
use Statamic\Entries\Entry;
use UAMSWeb\Aether\WordPressImporter\Console\Commands\ImportWordPress;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Taxonomies\StructuredTermImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Taxonomies\TaxonomyDispositions;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Taxonomies\TermDestinationRegistry;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\EntryQueryHelper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;

/**
 * Resolves a WordPress taxonomy navigation item to the Statamic entry it links to.
 *
 * A Statamic navigation branch references an **entry** or a literal URL, and
 * nothing else. So the only WordPress taxonomies a navigation item can survive
 * for are the ones that import into a *collection* — where the term became an
 * entry with an id to point at. A term that imported into a flat Statamic
 * taxonomy has no navigation representation at all, and the honest answer is to
 * drop the item and say why, not to synthesize a URL string that stops being
 * true the moment a route changes.
 *
 * Resolution is therefore a check on the destination's **shape** rather than on
 * the taxonomy's name, which is what lets `kbtopic` and `category` — and the
 * three other structured taxonomies, the day one appears in a menu — travel the
 * same path. `UAMS-Web/wordpress-importer-archive#12` pins that decision.
 *
 * ## Why there is a second lookup
 *
 * {@see TermDestinationRegistry} is a PHP static populated by the term pass,
 * which runs inside `BaseEntryCreator::initializeTerms()` — so `category`
 * destinations exist only when the `post` import ran **in this same process**.
 * A full import satisfies that: navigation is deferred until after every
 * content type. A *navigation-only* re-run does not, and neither does a split
 * WXR import whose posts and menus were separate commands. In both, the
 * registry is simply empty and every category item would be dropped as
 * unresolvable.
 *
 * So a registry miss falls back to the saved content: the export gives the term
 * id its slug, and the slug identifies the entry {@see StructuredTermImporter}
 * created — which is safe precisely because that importer slugs collection
 * entries with the raw WordPress term slug, unprefixed. `PostCategoryEntryResolver`
 * exists for the same reason on the news-block path.
 *
 * **The fallback is scoped to one site, and that is a constraint rather than a
 * simplification.** These collections set `propagate: false` and their per-site
 * entries carry no `origin`, so an entry id belonging to another site is a
 * value the host drops on read. A navigation branch pointing at one would look
 * correct in the tree and render nothing.
 */
final class TaxonomyNavigationResolver
{
    /**
     * Build a resolver over the term slugs an export declares.
     *
     * @param  array<string, array<string, string>>  $slugsByTermId  WordPress taxonomy to a term id => slug map
     */
    private function __construct(
        private readonly array $slugsByTermId,
    ) {}

    /**
     * Build a resolver primed with the term slugs the export declares.
     *
     * Only the taxonomies {@see TaxonomyDispositions::structuredTaxonomies()}
     * names are mapped. Every other taxonomy is unreachable by the fallback
     * anyway. `post_tag` became one of those in wordpress-importer-archive#1220, so its terms are mapped
     * too, at 5,760 across the export corpus; a menu linking to a tag archive
     * is otherwise unresolvable whenever the registry is cold.
     *
     * @param  XmlParser  $xmlParser  Parser for the WordPress export being imported
     * @return self A resolver that can consult both the registry and the saved entries
     */
    public static function fromExport(
        XmlParser $xmlParser
    ): self {
        $structured = TaxonomyDispositions::structuredTaxonomies();
        $slugsByTermId = [];

        // ---
        // `<wp:term>` carries every taxonomy except the two WordPress built-ins.
        // ---
        foreach ($xmlParser->getTerms() as $term) {
            // The parser answers with elements only, so this only narrows the type for phpstan
            // @pest-mutate-ignore: InstanceOfToTrue, ContinueToBreak
            if (! $term instanceof SimpleXMLElement) {
                continue;
            }

            $taxonomy = XpathHelper::getStringValue(
                $term,
                'wp:term_taxonomy'
            );

            // Taxonomy handles are strings, so in_array()'s strict flag, which phpstan-strict-rules
            // requires, changes no result
            // @pest-mutate-ignore: TrueToFalse
            if (! \in_array($taxonomy, $structured, true)) {
                continue;
            }

            self::recordSlug(
                $slugsByTermId,
                $taxonomy,
                XpathHelper::getStringValue($term, 'wp:term_id'),
                XpathHelper::getStringValue($term, 'wp:term_slug')
            );
        }

        // ---
        // The built-in `category` taxonomy is serialized as `<wp:category>` with
        // `<wp:category_nicename>` instead, so `getTerms()` never returns it.
        // Across the 259-export corpus that is 1,870 `<wp:category>` elements in
        // 258 files against 149 `<wp:term>` ones — i.e. skipping this branch
        // would lose the fallback on nearly every real export (the same trap
        // `wordpress-importer-archive#441` fixed for the term maps).
        // ---
        // Taxonomy handles are strings, so in_array()'s strict flag, which phpstan-strict-rules
        // requires, changes no result
        // @pest-mutate-ignore: TrueToFalse
        if (\in_array('category', $structured, true)) {
            foreach ($xmlParser->getCategories() as $category) {
                // The parser answers with elements only, so this only narrows the type for phpstan
                // @pest-mutate-ignore: InstanceOfToTrue, ContinueToBreak
                if (! $category instanceof SimpleXMLElement) {
                    continue;
                }

                self::recordSlug(
                    $slugsByTermId,
                    'category',
                    XpathHelper::getStringValue($category, 'wp:term_id'),
                    XpathHelper::getStringValue($category, 'wp:category_nicename')
                );
            }
        }

        // ---
        // The built-in `post_tag` taxonomy is serialized as `<wp:tag>` with
        // `<wp:tag_slug>`, the same trap as categories. It needs this branch now
        // that tags import into a collection (wordpress-importer-archive#1220).
        // ---
        // Taxonomy handles are strings, so in_array()'s strict flag, which phpstan-strict-rules
        // requires, changes no result
        // @pest-mutate-ignore: TrueToFalse
        if (\in_array('post_tag', $structured, true)) {
            foreach ($xmlParser->getTags() as $tag) {
                // The parser answers with elements only, so this only narrows the type for phpstan
                // @pest-mutate-ignore: InstanceOfToTrue, ContinueToBreak
                if (! $tag instanceof SimpleXMLElement) {
                    continue;
                }

                self::recordSlug(
                    $slugsByTermId,
                    'post_tag',
                    XpathHelper::getStringValue($tag, 'wp:term_id'),
                    XpathHelper::getStringValue($tag, 'wp:tag_slug')
                );
            }
        }

        return new self($slugsByTermId);
    }

    /**
     * Build a resolver with no export to fall back on.
     *
     * Consults {@see TermDestinationRegistry} only. This is the resolver a
     * caller gets when navigation items are created without the import having
     * primed one, so the registry path still works rather than fataling.
     *
     * @return self A registry-only resolver
     */
    public static function registryOnly(): self
    {
        return new self([]);
    }

    /**
     * Resolve a WordPress `(taxonomy, term_id)` pair to a navigable Statamic entry.
     *
     * @param  string  $wordpressTaxonomy  The WordPress taxonomy handle from `_menu_item_object`
     * @param  string  $termId  The WordPress term id from `_menu_item_object_id`
     * @param  string  $site  The Statamic site the navigation is being imported into
     * @return TaxonomyNavigationDestination The entry to link, or the reason there is none
     */
    public function resolve(
        string $wordpressTaxonomy,
        string $termId,
        string $site
    ): TaxonomyNavigationDestination {
        if ($wordpressTaxonomy === '' || $termId === '') {
            return TaxonomyNavigationDestination::skipped(
                'incomplete_menu_item',
                'the menu item names no taxonomy or no term id, so there is nothing to resolve.'
            );
        }

        // ---
        // The term pass records where every term landed. Trust it first: it is
        // authoritative, it covers this run's own imports, and for `kbtopic` it
        // is the only source there is.
        // ---
        $destination = TermDestinationRegistry::resolve(
            $wordpressTaxonomy,
            $termId
        );

        if ($destination !== null) {
            if ($destination['shape'] === TaxonomyDispositions::SHAPE_COLLECTION) {
                // ---
                // A recorded destination with an empty reference is a term the
                // term pass registered but could not save. Writing it anyway
                // produces a branch with neither an entry nor a URL, which
                // renders as an unclickable label rather than as a visible
                // failure — so treat it as unresolved and fall through to the
                // saved-entry lookup, which may well find it.
                // ---
                if ($destination['reference'] !== '') {
                    return TaxonomyNavigationDestination::entry($destination['reference']);
                }

                return $this->resolveFromSavedEntries(
                    $wordpressTaxonomy,
                    $termId,
                    $site
                );
            }

            return TaxonomyNavigationDestination::skipped(
                'flat_destination',
                "'{$wordpressTaxonomy}' imports as a term in the flat Statamic taxonomy "
                    ."'{$destination['handle']}', and a navigation branch can only reference "
                    .'an entry or a literal URL.'
            );
        }

        return $this->resolveFromSavedEntries(
            $wordpressTaxonomy,
            $termId,
            $site
        );
    }

    /**
     * Resolve a term against the content already on disk, the registry having missed.
     *
     * @param  string  $wordpressTaxonomy  The WordPress taxonomy handle
     * @param  string  $termId  The WordPress term id
     * @param  string  $site  The Statamic site the navigation is being imported into
     * @return TaxonomyNavigationDestination The entry to link, or the reason there is none
     */
    private function resolveFromSavedEntries(
        string $wordpressTaxonomy,
        string $termId,
        string $site
    ): TaxonomyNavigationDestination {
        // ---
        // `kbtopic` has a collection destination but no entry in the dispositions
        // table, because `KbLinkRegistry` owns its mappings. Those are built by
        // the KB import and do not outlive it, so there is nothing on disk this
        // resolver could match a kbtopic term id against.
        // ---
        if (TermDestinationRegistry::delegatesToKbLinkRegistry($wordpressTaxonomy)) {
            return TaxonomyNavigationDestination::skipped(
                'kbtopic_term_not_imported',
                "no Statamic entry is registered for '{$wordpressTaxonomy}' term {$termId}. Those "
                    .'mappings are built by the knowledge-base import and last only for that run, '
                    .'so import the KB content in the same command as the navigation.'
            );
        }

        // ---
        // Anything else that is not a structured taxonomy has no entry to point
        // at, whether because it imports flat or because nothing maps it at all.
        //
        // Being *recorded* is not the same as importing flat: a taxonomy recorded
        // as SHAPE_UNMAPPED is recorded precisely because it has no destination.
        // Testing isRecorded() alone would report those as `flat_destination` and
        // send an operator looking for a taxonomy nothing declares.
        // ---
        if (! TaxonomyDispositions::isStructured($wordpressTaxonomy)) {
            $importsAsFlatTaxonomy =
                TaxonomyDispositions::isRecorded($wordpressTaxonomy)
                && ! TaxonomyDispositions::isUnmapped($wordpressTaxonomy);

            return TaxonomyNavigationDestination::skipped(
                $importsAsFlatTaxonomy
                    ? 'flat_destination'
                    : 'no_statamic_destination',
                $importsAsFlatTaxonomy
                    ? "'{$wordpressTaxonomy}' imports as a flat Statamic taxonomy term, and a "
                        .'navigation branch can only reference an entry or a literal URL.'
                    : "'{$wordpressTaxonomy}' has no Statamic destination, so its terms are not "
                        .'imported at all.'
            );
        }

        $slug = $this->slugsByTermId[$wordpressTaxonomy][$termId] ?? null;

        if ($slug === null) {
            return TaxonomyNavigationDestination::skipped(
                'term_absent_from_export',
                "the export declares no '{$wordpressTaxonomy}' term with id {$termId}, so the "
                    .'menu item points at a term this import never saw.'
            );
        }

        $collectionHandle = ImportWordPress::mapWordPressToStatamicHandle(
            $wordpressTaxonomy,
            'taxonomy'
        );

        // Every structured taxonomy maps to a handle, so this only narrows the type for phpstan
        if ($collectionHandle === null) {
            // @pest-mutate-ignore: RemoveEarlyReturn
            return TaxonomyNavigationDestination::skipped(
                'no_statamic_destination',
                "'{$wordpressTaxonomy}' is recorded as structured but maps to no Statamic handle."
            );
        }

        $entryId = self::entryIdFor(
            $collectionHandle,
            $slug,
            $site
        );

        if ($entryId === null) {
            return TaxonomyNavigationDestination::skipped(
                'not_imported_into_site',
                "no '{$collectionHandle}' entry is slugged '{$slug}' on the '{$site}' site. Import "
                    .'the content type that creates those terms into this site, then re-run the '
                    .'navigation import.'
            );
        }

        return TaxonomyNavigationDestination::entry($entryId);
    }

    /**
     * Record one term id => slug pair, keeping the first declaration.
     *
     * @param  array<string, array<string, string>>  $slugsByTermId  The map being built, by reference
     * @param  string  $taxonomy  The WordPress taxonomy handle
     * @param  string  $termId  The WordPress term id
     * @param  string  $slug  The WordPress term slug
     */
    private static function recordSlug(
        array &$slugsByTermId,
        string $taxonomy,
        string $termId,
        string $slug
    ): void {
        // A term with no slug names no entry. One with no id is never looked up, since a menu
        // item with no term id stops at the incomplete check in resolve().
        if ($slug === '') {
            return;
        }

        // A term id is unique within its taxonomy, so a repeat is a duplicated
        // declaration rather than a second term; keep the first, as the term
        // importer does.
        if (isset($slugsByTermId[$taxonomy][$termId])) {
            return;
        }

        $slugsByTermId[$taxonomy][$termId] = $slug;
    }

    /**
     * Look up a collection entry by slug on one site.
     *
     * @param  string  $collectionHandle  The Statamic collection handle
     * @param  string  $slug  The entry slug, which is the raw WordPress term slug
     * @param  string  $site  The Statamic site handle
     * @return ?string The entry id, or null when that site has no such entry
     */
    private static function entryIdFor(
        string $collectionHandle,
        string $slug,
        string $site
    ): ?string {
        // Build the query in statements rather than a chain: the builder's
        // `where()` is untyped, so chaining loses the typed receiver. Collection,
        // locale and slug identify one entry, so `first()` is a lookup rather
        // than a sample.
        $query = EntryQueryHelper::query();
        $query->where('collection', $collectionHandle);
        $query->where('locale', $site);
        $query->where('slug', $slug);

        $entry = $query->first();

        $entryId = $entry instanceof Entry
            ? $entry->id()
            : null;

        // A saved entry always has an id
        return \is_string($entryId) ? $entryId : null;
    }
}
