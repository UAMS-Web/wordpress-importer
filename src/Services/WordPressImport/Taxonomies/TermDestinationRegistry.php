<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Taxonomies;

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\KbLinkRegistry;

/**
 * Resolves a WordPress `(taxonomy, term_id)` pair to where it landed in Statamic.
 *
 * A WordPress term imports into one of two destinations — a term in a flat
 * Statamic taxonomy, or an entry in a structured collection (see
 * {@see TaxonomyDispositions}). Everything downstream of the term-import pass
 * that needs to *reference* a term — entry taxonomy fields, navigation items,
 * Bard sets — needs to know which of the two it got and what to point at. This
 * registry is that lookup, populated during the term pass and read afterwards.
 *
 * **`kbtopic` is delegated, not absorbed.** Per the decision on
 * `UAMS-Web/wordpress-importer-archive#13`, `KbLinkRegistry` stays the owner of the
 * kbtopic mappings — it is a working, tested path with its own callers, and
 * folding it in would be refactor risk for no user-visible gain. This registry
 * forwards kbtopic lookups to it so callers still see a single API.
 *
 * The registry is static because the import runs as one long-lived process and
 * the term pass and its consumers are far apart in the call graph. {@see clear()}
 * resets it between runs; the importer's other registries follow the same shape.
 */
final class TermDestinationRegistry
{
    /**
     * WordPress taxonomy that resolves through {@see KbLinkRegistry}.
     */
    private const string DELEGATED_TAXONOMY = 'kbtopic';

    /**
     * Resolved destinations keyed by "{taxonomy}::{termId}".
     *
     * @var array<string, array{shape: string, handle: string, reference: string}>
     */
    private static array $destinations = [];

    /**
     * Record that a term landed as a term in a flat Statamic taxonomy.
     *
     * @param  string  $wordpressTaxonomy  The WordPress taxonomy handle
     * @param  string|int  $termId  The WordPress term id
     * @param  string  $taxonomyHandle  The Statamic taxonomy handle
     * @param  string  $termSlug  The Statamic term slug
     */
    public static function registerTaxonomyTerm(
        string $wordpressTaxonomy,
        string|int $termId,
        string $taxonomyHandle,
        string $termSlug
    ): void {
        self::$destinations[self::key($wordpressTaxonomy, $termId)] = [
            'shape' => TaxonomyDispositions::SHAPE_TAXONOMY,
            'handle' => $taxonomyHandle,
            'reference' => $termSlug,
        ];
    }

    /**
     * Record that a term landed as an entry in a structured collection.
     *
     * @param  string  $wordpressTaxonomy  The WordPress taxonomy handle
     * @param  string|int  $termId  The WordPress term id
     * @param  string  $collectionHandle  The Statamic collection handle
     * @param  string  $entryId  The Statamic entry id
     */
    public static function registerCollectionEntry(
        string $wordpressTaxonomy,
        string|int $termId,
        string $collectionHandle,
        string $entryId
    ): void {
        self::$destinations[self::key($wordpressTaxonomy, $termId)] = [
            'shape' => TaxonomyDispositions::SHAPE_COLLECTION,
            'handle' => $collectionHandle,
            'reference' => $entryId,
        ];
    }

    /**
     * Resolve where a WordPress term landed in Statamic.
     *
     * @param  string  $wordpressTaxonomy  The WordPress taxonomy handle
     * @param  string|int  $termId  The WordPress term id
     * @return ?array{shape: string, handle: string, reference: string} The destination, or null when unresolved
     */
    public static function resolve(
        string $wordpressTaxonomy,
        string|int $termId
    ): ?array {
        // `kbtopic` is owned by KbLinkRegistry; forward rather than duplicate it.
        if ($wordpressTaxonomy === self::DELEGATED_TAXONOMY) {
            $entryId = KbLinkRegistry::getKbtopicEntryIdByTermId($termId);

            if ($entryId === null) {
                return null;
            }

            return [
                'shape' => TaxonomyDispositions::SHAPE_COLLECTION,
                'handle' => 'knowledge_bases',
                'reference' => $entryId,
            ];
        }

        return self::$destinations[self::key($wordpressTaxonomy, $termId)] ?? null;
    }

    /**
     * Determine whether a WordPress taxonomy resolves through {@see KbLinkRegistry}.
     *
     * `kbtopic` is the one taxonomy whose destination is a collection without
     * appearing in {@see TaxonomyDispositions}, because `KbLinkRegistry` owns it
     * (see the class docblock). A caller reasoning about *shape* from the
     * dispositions table alone would therefore read `kbtopic` as flat, which it
     * is not — this tells it to trust {@see self::resolve()} instead.
     *
     * @param  string  $wordpressTaxonomy  The WordPress taxonomy handle
     * @return bool True when lookups for this taxonomy are forwarded
     */
    public static function delegatesToKbLinkRegistry(
        string $wordpressTaxonomy
    ): bool {
        return $wordpressTaxonomy === self::DELEGATED_TAXONOMY;
    }

    /**
     * Resolve only the Statamic reference for a WordPress term.
     *
     * @param  string  $wordpressTaxonomy  The WordPress taxonomy handle
     * @param  string|int  $termId  The WordPress term id
     * @return ?string The term slug or entry id, or null when unresolved
     */
    public static function referenceFor(
        string $wordpressTaxonomy,
        string|int $termId
    ): ?string {
        return self::resolve($wordpressTaxonomy, $termId)['reference'] ?? null;
    }

    /**
     * Return every destination recorded in this registry.
     *
     * Excludes the delegated `kbtopic` mappings, which {@see KbLinkRegistry} owns.
     *
     * @return array<string, array{shape: string, handle: string, reference: string}> Destinations keyed by "{taxonomy}::{termId}"
     */
    public static function all(): array
    {
        return self::$destinations;
    }

    /**
     * Reset the registry for a new import run.
     */
    public static function clear(): void
    {
        self::$destinations = [];
    }

    /**
     * Build the storage key for a taxonomy/term pair.
     *
     * @param  string  $wordpressTaxonomy  The WordPress taxonomy handle
     * @param  string|int  $termId  The WordPress term id
     * @return string The composite key
     */
    private static function key(
        string $wordpressTaxonomy,
        string|int $termId
    ): string {
        return $wordpressTaxonomy.'::'.$termId;
    }
}
