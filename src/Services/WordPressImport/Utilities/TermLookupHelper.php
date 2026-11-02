<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities;

/**
 * Helper class for looking up term data from term maps.
 *
 * Provides consistent methods for extracting term names and full slugs
 * from term maps built by TermMapBuilder, eliminating code duplication
 * across entry creators.
 */
class TermLookupHelper
{
    /**
     * Finds term data in term map by matching slug and taxonomy.
     *
     * This method provides a DRY way to search through term maps for terms
     * that match both the specified slug and taxonomy.
     *
     * @param  array<int|string, mixed>  $termMap  The term map built by TermMapBuilder
     * @param  string  $termSlug  The term slug to look up
     * @param  string  $wordpressTaxonomyHandle  The WordPress taxonomy handle to match against
     * @return array<int|string, mixed>|null The term data array if found, null otherwise
     */
    private static function findTermInMap(
        array $termMap,
        string $termSlug,
        string $wordpressTaxonomyHandle
    ): ?array {
        // Search through the term map for a term with matching slug and taxonomy
        foreach ($termMap as $termData) {
            if (! \is_array($termData)) {
                continue;
            }

            // Check if this term matches the specified taxonomy and slug
            if (
                ($termData['term_taxonomy'] ?? null) === $wordpressTaxonomyHandle
                && ($termData['term_slug'] ?? null) === $termSlug
            ) {
                // Return the term data if found
                return $termData;
            }
        }

        // Return null if not found
        return null;
    }

    /**
     * Gets term name from term map by looking up the term with matching slug and
     * taxonomy.
     *
     * Searches through the term map for a term that matches both the slug
     * and taxonomy, returning the term name if found or generating a fallback
     * name from the slug.
     *
     * @param  array<int|string, mixed>  $termMap  The term map built by TermMapBuilder
     * @param  string  $termSlug  The term slug to look up
     * @param  string  $wordpressTaxonomyHandle  The WordPress taxonomy handle to match against
     * @return string The term name, or generated name if not found
     */
    public static function getTermNameFromMap(
        array $termMap,
        string $termSlug,
        string $wordpressTaxonomyHandle
    ): string {
        // Find the term data in the map
        $termData = self::findTermInMap(
            $termMap,
            $termSlug,
            $wordpressTaxonomyHandle
        );

        // Check if term data was found in the map
        // A matched term always carries its taxonomy and slug, so it is never empty
        if ($termData !== null) {
            $termName = $termData['term_name'] ?? null;

            // Return the term name from the found data
            if (\is_string($termName) && $termName !== '') {
                return $termName;
            }
        }

        // Fallback: generate name from slug if not found
        return \ucwords(
            \str_replace(
                [
                    '-',
                    '_',
                ],
                ' ',
                $termSlug
            )
        );
    }

    /**
     * Gets the full hierarchical slug from term map by looking up the term with
     * matching slug and taxonomy.
     *
     * Searches through the term map for a term that matches both the slug
     * and taxonomy, returning the full hierarchical slug if found or the
     * original slug as fallback.
     *
     * @param  array<int|string, mixed>  $termMap  The term map built by TermMapBuilder
     * @param  string  $termSlug  The term slug to look up
     * @param  string  $wordpressTaxonomyHandle  The WordPress taxonomy handle to match against
     * @return string The full hierarchical slug, or original slug if not found
     */
    public static function getFullSlugFromMap(
        array $termMap,
        string $termSlug,
        string $wordpressTaxonomyHandle
    ): string {
        // Find the term data in the map
        $termData = self::findTermInMap(
            $termMap,
            $termSlug,
            $wordpressTaxonomyHandle
        );

        // Check if term data was found in the map
        // A matched term always carries its taxonomy and slug, so it is never empty
        if ($termData !== null) {
            $fullSlug = $termData['full_slug'] ?? null;

            // Return the full hierarchical slug from the found data
            if (\is_string($fullSlug) && $fullSlug !== '') {
                return $fullSlug;
            }
        }

        // Fallback: return original slug if not found
        return $termSlug;
    }

    /**
     * Gets the full hierarchical slug from term map by looking up the term with
     * the specified term ID.
     *
     * Directly accesses the term map using the term ID as the key, which is
     * more efficient than searching by slug and taxonomy. This method is
     * optimized for cases where you already have the term ID from WordPress
     * postmeta or other sources.
     *
     * @param  array<int|string, mixed>  $termMap  The term map built by TermMapBuilder
     * @param  string  $termId  The term ID to look up
     * @return string The full hierarchical slug, or empty string if not found
     */
    public static function getFullSlugFromMapById(
        array $termMap,
        string $termId
    ): string {
        // Check if the term ID exists in the map
        if (isset($termMap[$termId])) {
            // Get the term data directly by ID
            $termData = $termMap[$termId];

            // Only an array entry is read, so an object that reads like one
            // (ArrayAccess) does not stand in for a term
            if (! \is_array($termData)) {
                return '';
            }

            $fullSlug = $termData['full_slug'] ?? null;

            if (\is_string($fullSlug) && $fullSlug !== '') {
                return $fullSlug;
            }

            $termSlug = $termData['term_slug'] ?? null;

            // Return the full hierarchical slug if available, otherwise fall back to term slug
            return \is_string($termSlug) ? $termSlug : '';
        }

        // Return empty string if term ID not found
        return '';
    }

    /**
     * Gets the term name from term map by looking up the term with the
     * specified term ID.
     *
     * Directly accesses the term map using the term ID as the key, which is
     * more efficient than searching by slug and taxonomy. This method is
     * optimized for cases where you already have the term ID from WordPress
     * postmeta or other sources.
     *
     * @param  array<int|string, mixed>  $termMap  The term map built by TermMapBuilder
     * @param  string  $termId  The term ID to look up
     * @return string The term name, or empty string if not found
     */
    public static function getTermNameFromMapById(
        array $termMap,
        string $termId
    ): string {
        // Check if the term ID exists in the map
        if (isset($termMap[$termId])) {
            // Get the term data directly by ID
            $termData = $termMap[$termId];

            // Only an array entry is read, so an object that reads like one
            // (ArrayAccess) does not stand in for a term
            if (! \is_array($termData)) {
                return '';
            }

            $termName = $termData['term_name'] ?? null;

            // Return the term name if available
            return \is_string($termName) ? $termName : '';
        }

        // Return empty string if term ID not found
        return '';
    }
}
