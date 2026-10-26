<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies;

use Illuminate\Support\Facades\Log;
use SimpleXMLElement;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Entries\Entry;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Structures\CollectionStructure;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Structures\TreeBuilder;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\CollectionStructureManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\EntryQueryHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\SiteManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;

/**
 * Imports a hierarchical WordPress taxonomy into a structured Statamic collection.
 *
 * `UAMS-Web/uams-statamic#252` settled that hierarchy is delivered by native
 * structured collections, and `#1863` converted `people_groups` and
 * `logos_item_categories` into them alongside the existing `post_categories`.
 * A term in one of those taxonomies is therefore a collection **entry**, not a
 * taxonomy term, and its `wp:term_parent` becomes a parent/child edge in the
 * collection's tree.
 *
 * Two details are easy to get wrong and are handled deliberately here:
 *
 *   - **`wp:term_parent` holds the parent's *slug*, not its id.** The tree keys
 *     parents by the same id it keys nodes by, so the slug is resolved back to a
 *     term id before the tree is assembled. A parent naming a term absent from
 *     the export is left top-level rather than dropped.
 *   - **Slugs are not site-prefixed.** The flat-taxonomy path prefixes slugs
 *     because Statamic terms share one namespace across sites; collection
 *     entries do not — they already live under `content/collections/{handle}/{site}/`
 *     — and prefixing would leak the site handle into the entry's public URL,
 *     which each of these collections builds from `slug` and `parent_uri`.
 *
 * The tree is written here rather than through {@see TreeBuilder}. That builder
 * dedupes on "{collection}:{site}" and returns early on a second call, which
 * would discard the tree for the second taxonomy sharing a destination, and it
 * deletes duplicate entry files — a page-import behavior with no business
 * running over terms.
 *
 * Re-import is idempotent: an entry is matched by collection, site and slug
 * before a new one is created, so a second run updates rather than duplicates.
 */
class StructuredTermImporter
{
    /**
     * Flat tree nodes accumulated per destination, keyed "{collection}::{site}".
     *
     * Two WordPress taxonomies can share one destination — `uamspeople-categories`
     * and `tshowcase-categories` both land in `people_groups` — and they are
     * imported in separate passes. The nodes are accumulated so the second pass
     * rewrites the whole tree rather than a tree containing only its own terms.
     *
     * @var array<string, array<int|string, array{entry: string, parent: string, title: string}>>
     */
    private static array $treeNodes = [];

    /**
     * Reset the accumulated tree nodes for a new import run.
     */
    public static function reset(): void
    {
        self::$treeNodes = [];
    }

    /**
     * Import a WordPress taxonomy's terms as entries in a structured collection.
     *
     * @param  array<int|string, mixed>  $terms  The `<wp:term>` elements for one taxonomy
     * @param  string  $wordpressTaxonomy  The WordPress taxonomy handle
     * @param  string  $collectionHandle  The destination Statamic collection handle
     * @param  string  $site  The Statamic site handle
     * @param  OutputInterface  $output  Console output for logging
     * @return int The number of terms imported
     */
    public function import(
        array $terms,
        string $wordpressTaxonomy,
        string $collectionHandle,
        string $site,
        OutputInterface $output
    ): int {
        // A missing destination is a configuration problem in the host app, not
        // a reason to abort the whole import — report it and skip the taxonomy.
        if (CollectionFacade::findByHandle($collectionHandle) === null) {
            $output->writeln(
                "  - <error>Collection '{$collectionHandle}' not found; skipping '{$wordpressTaxonomy}' terms.</error>"
            );

            Log::warning(
                'StructuredTermImporter::import: destination collection is missing.',
                [
                    'wordpress_taxonomy' => $wordpressTaxonomy,
                    'collection' => $collectionHandle,
                    'site' => $site,
                ]
            );

            return 0;
        }

        // Normalize the export's terms into a flat, addressable shape first. The
        // parent edge is a slug, so every term has to be seen before any edge
        // can be resolved.
        $parsedTerms = $this->parseTerms($terms);

        if ($parsedTerms === []) {
            return 0;
        }

        // ---
        // Claim the destination for this site BEFORE writing any entry into it.
        // An entry is saved with `locale($site)`, which puts its file under
        // `content/collections/{handle}/{site}/`, but Statamic's
        // `CollectionEntriesStore::getItemFilter()` rejects any file whose site
        // segment is not in the collection's own `sites:` list. Skipping this
        // writes files the next cold Stache build silently drops from the index,
        // after the run has already reported success — and the destinations are
        // enabled for far fewer sites than the content that references them.
        // ---
        $this->enableDestinationForSite(
            $collectionHandle,
            $site,
            $output
        );

        // Map slug to term id so `wp:term_parent` can be resolved to the id the
        // tree keys nodes by.
        $termIdBySlug = [];

        foreach ($parsedTerms as $termId => $parsedTerm) {
            $termIdBySlug[$parsedTerm['slug']] = (string) $termId;
        }

        $treeData = [];

        foreach ($parsedTerms as $termId => $parsedTerm) {
            $entryId = $this->createOrUpdateEntry(
                $parsedTerm['slug'],
                $parsedTerm['name'],
                $collectionHandle,
                $site
            );

            if ($entryId === null) {
                continue;
            }

            // Record where this term landed so entry/nav/Bard wiring can point
            // at it later.
            TermDestinationRegistry::registerCollectionEntry(
                $wordpressTaxonomy,
                (string) $termId,
                $collectionHandle,
                $entryId
            );

            // Resolve the parent slug to a term id. An unknown parent leaves the
            // node top-level rather than discarding it.
            $parentTermId = $parsedTerm['parent'] !== ''
                ? ($termIdBySlug[$parsedTerm['parent']] ?? '')
                : '';

            $treeData[(string) $termId] = [
                'entry' => $entryId,
                'parent' => $parentTermId,
                'title' => $parsedTerm['name'],
            ];
        }

        if ($treeData === []) {
            return 0;
        }

        // ---
        // Merge into whatever this destination already holds before rewriting the
        // tree. `uamspeople-categories` and `tshowcase-categories` both target
        // `people_groups`, so writing only this pass's nodes would drop the other
        // taxonomy's terms out of the tree — their entries would exist but sit
        // outside the structure, unreachable by the collection's route.
        //
        // array_replace(), never the spread operator: term ids are numeric, and
        // spreading RENUMBERS integer keys, which would silently destroy the very
        // ids the parent edges and the registry are keyed by.
        // ---
        $destinationKey = $collectionHandle.'::'.$site;
        self::$treeNodes[$destinationKey] = \array_replace(
            self::$treeNodes[$destinationKey] ?? [],
            $treeData
        );

        // ---
        // Stamp the parent onto each child's own data as well as into the tree.
        // The tree gives Statamic the hierarchy, but the app's child-category
        // query is `:parent:is="<id>"`, which reads a queryable `parent` FIELD —
        // a tree-only parent leaves that query returning nothing while the CP
        // shows a correct nesting, so the omission looks fine from the inside.
        // Runs after every entry exists, since a child can be parsed before its
        // parent and needs the parent's entry id, not its term id.
        // ---
        $this->stampParents(self::$treeNodes[$destinationKey]);

        $this->saveTree(
            $collectionHandle,
            $site,
            self::$treeNodes[$destinationKey],
            $output
        );

        $imported = \count($treeData);

        $output->writeln(
            "  - Imported {$imported} '{$wordpressTaxonomy}' term(s) into '{$collectionHandle}'."
        );

        // Free the per-taxonomy working set; an export can carry thousands of
        // terms and this runs inside the import's long-lived process.
        unset(
            $parsedTerms,
            $termIdBySlug,
            $treeData
        );

        gc_collect_cycles();

        return $imported;
    }

    /**
     * Write each child's parent ENTRY id onto its own entry data.
     *
     * `UAMS-Web/wordpress-importer-archive#422` records why this is not optional: the
     * app resolves child categories with `:parent:is="<id>"`, a query over a
     * data field, so a parent recorded only in the structure tree satisfies the
     * Control Panel's nesting while returning nothing to the front end.
     *
     * @param  array<int|string, array{entry: string, parent: string, title: string}>  $nodes  Flat nodes keyed by term id
     */
    protected function stampParents(
        array $nodes
    ): void {
        foreach ($nodes as $termId => $node) {
            $parentTermId = $node['parent'];

            // A root has no parent to record.
            if ($parentTermId === '') {
                continue;
            }

            // ---
            // A term naming ITSELF as its parent is skipped, the same way
            // {@see self::buildNestedTree()} treats it as no parent at all.
            // Without this the two disagree: the tree would show a root while the
            // entry carried its own id in `parent`, so `:parent:is="<id>"` would
            // return the entry as its own child. Not reachable from the 259-export
            // corpus (0 occurrences) and WordPress's own UI forbids it, but the
            // edge became live for categories when `categoryToPseudoTerm()`
            // started carrying `<wp:category_parent>`, and the guard is cheaper
            // than the asymmetry.
            // ---
            if ($parentTermId === (string) $termId) {
                continue;
            }

            // Resolve the parent TERM id to the parent ENTRY id the query needs.
            $parentEntryId = $nodes[$parentTermId]['entry'] ?? null;

            if (! \is_string($parentEntryId) || $parentEntryId === '') {
                continue;
            }

            $this->setEntryParent(
                $node['entry'],
                $parentEntryId
            );
        }
    }

    /**
     * Persist a single entry's `parent` field.
     *
     * @param  string  $entryId  The child entry id
     * @param  string  $parentEntryId  The parent entry id
     */
    protected function setEntryParent(
        string $entryId,
        string $parentEntryId
    ): void {
        $entry = EntryFacade::find($entryId);

        if (! $entry instanceof Entry) {
            return;
        }

        // Skip the write when it would not change anything, so a re-import does
        // not re-save every nested term.
        if ($entry->get('parent') === $parentEntryId) {
            return;
        }

        $entry->set('parent', $parentEntryId);
        $entry->save();
    }

    /**
     * Enable the destination collection for this site and initialize its structure.
     *
     * The same two steps every other site-scoped write in this importer performs
     * before creating entries (see `EntryCreator::enableCollectionAndStructureForContentType()`).
     * The collaborators are resolved from the container rather than injected so
     * the class stays constructible without arguments, and this sits behind its
     * own method so a test can substitute it.
     *
     * @param  string  $collectionHandle  The destination Statamic collection handle
     * @param  string  $site  The Statamic site handle
     * @param  OutputInterface  $output  Console output for logging
     */
    protected function enableDestinationForSite(
        string $collectionHandle,
        string $site,
        OutputInterface $output
    ): void {
        // Add the site to the collection's own `sites:` list if it is absent.
        app(SiteManager::class)->enableCollectionForSite(
            $site,
            $collectionHandle,
            $output
        );

        // Give the collection a structure for this site so the tree has somewhere
        // to be written; `CollectionStructure::existsIn()` is false until then.
        app(CollectionStructureManager::class)->initializeStructureForSite(
            $collectionHandle,
            $site,
            $output
        );
    }

    /**
     * Assemble a nested Statamic tree from flat parent edges.
     *
     * A node whose parent is empty, unknown, or itself becomes a root, so a
     * partial export orphans a term to the top level rather than losing it. The
     * visited set makes a parent cycle terminate: the first node of the cycle
     * reached is promoted to a root instead of recursing forever.
     *
     * @param  array<int|string, array{entry: string, parent: string, title: string}>  $nodes  Flat nodes keyed by term id
     * @return list<array{entry: string, children?: list<mixed>}> The nested tree
     */
    public static function buildNestedTree(
        array $nodes
    ): array {
        // Group each term id under its parent term id.
        $childrenByParent = [];

        foreach ($nodes as $termId => $node) {
            $parent = $node['parent'];

            // A parent naming a term this export does not contain, or naming the
            // term itself, is treated as no parent at all.
            if ($parent === '' || ! isset($nodes[$parent]) || $parent === (string) $termId) {
                $childrenByParent[''][] = (string) $termId;

                continue;
            }

            $childrenByParent[$parent][] = (string) $termId;
        }

        $visited = [];

        $tree = self::branch(
            '',
            $nodes,
            $childrenByParent,
            $visited
        );

        // ---
        // Promote anything the root pass never reached. Every member of a parent
        // cycle is unreachable from the roots, so without this a malformed export
        // would make those terms vanish from the tree entirely — the silent loss
        // this import is supposed to prevent.
        // ---
        foreach ($nodes as $termId => $node) {
            if (isset($visited[(string) $termId])) {
                continue;
            }

            $visited[(string) $termId] = true;

            $branch = ['entry' => $node['entry']];

            $children = self::branch(
                (string) $termId,
                $nodes,
                $childrenByParent,
                $visited
            );

            if ($children !== []) {
                $branch['children'] = $children;
            }

            $tree[] = $branch;
        }

        // Re-sort so promoted roots interleave deterministically with real ones.
        \usort(
            $tree,
            static fn (array $first, array $second): int => \strcmp(
                self::titleOf($first, $nodes),
                self::titleOf($second, $nodes)
            )
        );

        return $tree;
    }

    /**
     * Build one level of the tree and recurse into its children.
     *
     * @param  string  $parentId  The parent term id, or '' for the roots
     * @param  array<int|string, array{entry: string, parent: string, title: string}>  $nodes  Flat nodes keyed by term id
     * @param  array<string, list<string>>  $childrenByParent  Term ids grouped by parent term id
     * @param  array<string, bool>  &$visited  Term ids already placed, guarding cycles
     * @return list<array{entry: string, children?: list<mixed>}> The nodes at this level
     */
    private static function branch(
        string $parentId,
        array $nodes,
        array $childrenByParent,
        array &$visited
    ): array {
        $level = [];

        foreach ($childrenByParent[$parentId] ?? [] as $termId) {
            // A cycle would otherwise recurse until the stack runs out.
            if (isset($visited[$termId])) {
                continue;
            }

            $visited[$termId] = true;

            $branch = ['entry' => $nodes[$termId]['entry']];

            $children = self::branch(
                $termId,
                $nodes,
                $childrenByParent,
                $visited
            );

            if ($children !== []) {
                $branch['children'] = $children;
            }

            $level[] = $branch;
        }

        // Order siblings by title so a re-import produces a stable tree.
        \usort(
            $level,
            static fn (array $first, array $second): int => \strcmp(
                self::titleOf($first, $nodes),
                self::titleOf($second, $nodes)
            )
        );

        return $level;
    }

    /**
     * Resolve a branch's title for sorting.
     *
     * @param  array{entry: string, children?: list<mixed>}  $branch  The branch to read
     * @param  array<int|string, array{entry: string, parent: string, title: string}>  $nodes  Flat nodes keyed by term id
     * @return string The title, or the entry id when no node matches
     */
    private static function titleOf(
        array $branch,
        array $nodes
    ): string {
        foreach ($nodes as $node) {
            if ($node['entry'] === $branch['entry']) {
                return $node['title'];
            }
        }

        return $branch['entry'];
    }

    /**
     * Write the assembled tree onto the destination collection's structure.
     *
     * Deliberately does not reuse {@see TreeBuilder}: that builder dedupes on
     * "{collection}:{site}" and returns early on a second call, which would drop
     * the second taxonomy sharing a destination, and it also deletes duplicate
     * entry files — a page-import behavior with no business running over terms.
     *
     * @param  string  $collectionHandle  The destination Statamic collection handle
     * @param  string  $site  The Statamic site handle
     * @param  array<int|string, array{entry: string, parent: string, title: string}>  $nodes  Flat nodes keyed by term id
     * @param  OutputInterface  $output  Console output for logging
     */
    protected function saveTree(
        string $collectionHandle,
        string $site,
        array $nodes,
        OutputInterface $output
    ): void {
        $collection = CollectionFacade::findByHandle($collectionHandle);

        if ($collection === null || ! $collection->hasStructure()) {
            $output->writeln(
                "  - <comment>Collection '{$collectionHandle}' has no structure; term hierarchy not written.</comment>"
            );

            return;
        }

        $structure = $collection->structure();

        if (! $structure instanceof CollectionStructure) {
            return;
        }

        $tree = $structure->in($site);

        $tree ??= $structure->makeTree($site, []);

        if (
            ! \is_object($tree)
            || ! \method_exists($tree, 'tree')
            || ! \method_exists($tree, 'save')
        ) {
            return;
        }

        $tree->tree(self::buildNestedTree($nodes));
        $tree->save();
    }

    /**
     * Reduce the export's term elements to id, slug, name and parent slug.
     *
     * Terms without an id or a slug cannot be addressed or routed, so they are
     * skipped. A repeated id keeps its first occurrence, matching the
     * deduplication the flat-taxonomy path applies.
     *
     * Keys are `int|string` rather than `string` because PHP coerces a numeric
     * string array key to an integer, and every WordPress term id is numeric.
     *
     * @param  array<int|string, mixed>  $terms  The `<wp:term>` elements for one taxonomy
     * @return array<int|string, array{slug: string, name: string, parent: string}> Parsed terms keyed by WordPress term id
     */
    private function parseTerms(
        array $terms
    ): array {
        $parsed = [];

        foreach ($terms as $term) {
            if (! $term instanceof SimpleXMLElement) {
                continue;
            }

            $termId = XpathHelper::getStringValue($term, 'wp:term_id');
            $slug = XpathHelper::getStringValue($term, 'wp:term_slug');

            if ($termId === '' || $slug === '') {
                continue;
            }

            if (isset($parsed[$termId])) {
                continue;
            }

            $name = XpathHelper::getStringValue($term, 'wp:term_name');

            $parsed[$termId] = [
                'slug' => $slug,
                // Fall back to the slug so an entry is never titled an empty
                // string in the Control Panel.
                'name' => $name !== '' ? $name : $slug,
                'parent' => XpathHelper::getStringValue($term, 'wp:term_parent'),
            ];
        }

        return $parsed;
    }

    /**
     * Create the collection entry for a term, or update the one already there.
     *
     * @param  string  $slug  The term slug, used as the entry slug
     * @param  string  $title  The term name, used as the entry title
     * @param  string  $collectionHandle  The destination Statamic collection handle
     * @param  string  $site  The Statamic site handle
     * @return ?string The Statamic entry id, or null when it could not be saved
     */
    protected function createOrUpdateEntry(
        string $slug,
        string $title,
        string $collectionHandle,
        string $site
    ): ?string {
        $entry = $this->findExistingEntry(
            $slug,
            $collectionHandle,
            $site
        );

        if (! $entry instanceof Entry) {
            $made = EntryFacade::make();

            if (! $made instanceof Entry) {
                Log::error(
                    'StructuredTermImporter::createOrUpdateEntry: failed to instantiate a Statamic entry.',
                    [
                        'collection' => $collectionHandle,
                        'site' => $site,
                        'slug' => $slug,
                    ]
                );

                return null;
            }

            $entry = $made;
            $entry->collection($collectionHandle);
            $entry->locale($site);
            $entry->slug($slug);
            $entry->published(true);
        }

        $entry->set('title', $title);
        $entry->save();

        $entryId = $entry->id();

        return \is_string($entryId) ? $entryId : null;
    }

    /**
     * Find an existing entry for a term slug in this collection and site.
     *
     * Filtering by collection, locale and slug before taking the first result
     * makes the match deterministic — the slug is unique within a collection and
     * site, so this is a lookup rather than a sample.
     *
     * @param  string  $slug  The term slug
     * @param  string  $collectionHandle  The destination Statamic collection handle
     * @param  string  $site  The Statamic site handle
     * @return ?Entry The existing entry, or null when there is none
     */
    protected function findExistingEntry(
        string $slug,
        string $collectionHandle,
        string $site
    ): ?Entry {
        // Build the query in statements rather than a chain: the builder's
        // `where()` is untyped, so chaining loses the typed receiver.
        $query = EntryQueryHelper::query();
        $query->where('collection', $collectionHandle);
        $query->where('locale', $site);
        $query->where('slug', $slug);

        $existing = $query->first();

        if ($existing instanceof Entry) {
            return $existing;
        }

        // A repository can hand back the contract rather than the concrete
        // class; anything else is not usable here.
        if ($existing instanceof EntryContract) {
            Log::debug(
                'StructuredTermImporter::findExistingEntry: entry is not the concrete Statamic entry class.',
                [
                    'collection' => $collectionHandle,
                    'site' => $site,
                    'slug' => $slug,
                ]
            );
        }

        return null;
    }
}
