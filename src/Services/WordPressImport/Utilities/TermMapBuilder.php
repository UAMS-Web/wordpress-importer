<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities;

use SimpleXMLElement;

/**
 * Shared utility for building term maps from WordPress XML terms.
 *
 * Provides a unified approach to building term maps with hierarchical slug
 * support, parent ID resolution, and taxonomy filtering. Used by entry creators
 * and parsers to ensure consistent term data structure across the WordPress
 * import system.
 */
class TermMapBuilder
{
    /**
     * Builds a unified term map from WordPress XML terms with hierarchical slug
     * support.
     *
     * Creates a standardized term map structure that includes:
     *   - Basic term data (ID, taxonomy, slug, name, parent)
     *   - Resolved parent IDs (converted from parent slugs, within the child's
     *     taxonomy)
     *   - Pre-computed full hierarchical slugs
     *   - Optional taxonomy filtering
     *
     * The method performs two passes:
     *   1. First pass: Extract basic term data and resolve parent IDs
     *   2. Second pass: Build full hierarchical slugs recursively
     *
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects from XML
     * @param  array<int|string, mixed>  $wordpressTaxonomyHandles  Optional array of WordPress taxonomy names to filter by
     * @return array<string, array{term_id: string, term_taxonomy: string, term_slug: string, term_name: string, term_parent: string, parent_id: string, full_slug: string}> Map of term ID to unified term data structure with keys:
     *                                                                                                                                                                       - 'term_id': The WordPress term ID
     *                                                                                                                                                                       - 'term_taxonomy': The WordPress term taxonomy
     *                                                                                                                                                                       - 'term_slug': The base slug of the term
     *                                                                                                                                                                       - 'term_name': The display name of the term
     *                                                                                                                                                                       - 'term_parent': The original parent slug from XML
     *                                                                                                                                                                       - 'parent_id': The resolved parent term ID
     *                                                                                                                                                                       - 'full_slug': The full hierarchical slug path
     */
    public static function buildTermMap(
        array $terms,
        array $wordpressTaxonomyHandles = []
    ): array {
        // Initialize term map
        $termMap = [];

        // First pass: Build basic term map with parent slug to ID resolution
        foreach ($terms as $term) {
            if (! ($term instanceof SimpleXMLElement)) {
                continue;
            }

            // Extract basic term data using helper method
            $termData = self::extractTermData($term);

            // Extract individual term properties from the data array
            $termId = $termData['term_id'];

            // Extract term taxonomy type
            $termTaxonomy = $termData['term_taxonomy'];

            // Extract term slug for URL-friendly identifier
            $termSlug = $termData['term_slug'];

            // Extract term display name
            $termName = $termData['term_name'];

            // Extract parent term slug for hierarchy resolution
            $termParent = $termData['term_parent'];

            // Skip terms with missing required data
            if (
                ($termId === '')
                || ($termSlug === '')
            ) {
                // Continue to next term if ID or slug is missing
                continue;
            }

            // Apply taxonomy filter if specified
            if (
                $wordpressTaxonomyHandles !== []
                && ! \in_array(
                    $termTaxonomy,
                    $wordpressTaxonomyHandles,
                    true
                )
            ) {
                // Skip terms that don't match the specified taxonomies
                continue;
            }

            // Initialize parent ID as empty
            $parentId = '';

            // Convert parent slug to parent ID if parent exists
            if ($termParent !== '') {
                // ---
                // Search the terms for the parent by slug. WordPress slugs are unique
                // only within a taxonomy, and a parent always shares its child's
                // taxonomy, so a term in another taxonomy is never the parent, even
                // when it carries the same slug and comes first in the export.
                // ---
                foreach ($terms as $parentTerm) {
                    if (! ($parentTerm instanceof SimpleXMLElement)) {
                        continue;
                    }

                    // Extract parent term slug from XML
                    $parentTermSlug = XpathHelper::getStringValue(
                        $parentTerm,
                        'wp:term_slug'
                    );

                    // Extract parent term taxonomy from XML
                    $parentTermTaxonomy = XpathHelper::getStringValue(
                        $parentTerm,
                        'wp:term_taxonomy'
                    );

                    // Check if this term matches the parent slug in the child's taxonomy
                    if (
                        $parentTermSlug === $termParent
                        && $parentTermTaxonomy === $termTaxonomy
                    ) {
                        // Extract parent term ID and break the loop
                        $parentId = XpathHelper::getStringValue(
                            $parentTerm,
                            'wp:term_id'
                        );

                        // Exit the parent search loop
                        break;
                    }
                }
            }

            // Add term to map with unified structure
            $termMap[$termId] = [
                'term_id' => $termId,
                'term_taxonomy' => $termTaxonomy,
                'term_slug' => $termSlug,
                'term_name' => $termName,
                'term_parent' => $termParent,
                'parent_id' => $parentId,
                // The second pass overwrites full_slug for every term, and the key is
                // last either way, so dropping this initial value changes nothing; it
                // keeps the record's shape complete.
                // @pest-mutate-ignore: RemoveArrayItem
                'full_slug' => $termSlug,
            ];
        }

        // Second pass: Build full hierarchical slugs
        foreach ($termMap as $termId => &$data) {
            // Generate full hierarchical slug path for each term
            $data['full_slug'] = self::getFullTermSlug(
                $termId,
                $termMap
            );
        }

        // Return the complete term map with hierarchical slugs
        return $termMap;
    }

    /**
     * Builds a mapping from category nicenames to term IDs for efficient ID-based
     * lookups.
     *
     * This method creates a mapping from category nicenames (slugs) to their
     * corresponding term IDs by cross-referencing wp:term elements. This enables
     * entry creators to use ID-based lookups instead of slug-based searches for
     * better performance.
     *
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects from XML
     * @param  array<int|string, mixed>  $wordpressTaxonomyHandles  Array of WordPress taxonomy handles to map
     * @return array<string, array<string, string>> Map of nicename => term_id for each taxonomy
     */
    public static function buildNicenameToTermIdMap(
        array $terms,
        array $wordpressTaxonomyHandles
    ): array {
        $nicenameToTermIdMap = [];

        // Build mapping for each taxonomy
        foreach ($wordpressTaxonomyHandles as $taxonomy) {
            if (! \is_string($taxonomy) || $taxonomy === '') {
                continue;
            }

            $nicenameToTermIdMap[$taxonomy] = [];

            // Process each term to build the mapping
            foreach ($terms as $term) {
                if (! ($term instanceof SimpleXMLElement)) {
                    continue;
                }

                // Extract term data using helper method
                $termData = self::extractTermData($term);

                // Extract individual term properties
                $termId = $termData['term_id'];
                $termTaxonomy = $termData['term_taxonomy'];
                $termSlug = $termData['term_slug'];

                // Skip terms with missing required data
                if (
                    ($termId === '')
                    || ($termSlug === '')
                ) {
                    continue;
                }

                // Only process terms for the current taxonomy
                if ($termTaxonomy === $taxonomy) {
                    // Map nicename (slug) to term ID
                    $nicenameToTermIdMap[$taxonomy][$termSlug] = $termId;
                }
            }
        }

        return $nicenameToTermIdMap;
    }

    /**
     * Constructs the full slug path for a term, recursively prepending ancestor slugs.
     *
     * Recursively traverses the term hierarchy to build a complete slug path
     * that includes all ancestor slugs. Uses a visited array to prevent infinite
     * recursion in case of circular parent references.
     *
     * @param  int|string  $termId  The term ID to build the full slug for; an integer when it came from an array key, since PHP stores numeric-string keys as integers
     * @param  array<string, array{term_id: string, term_taxonomy: string, term_slug: string, term_name: string, term_parent: string, parent_id: string, full_slug: string}>  $termMap  Map of term IDs to their data including parent information
     * @param  array<int|string, mixed>  $visited  Array to track visited term IDs to prevent infinite recursion
     * @return string The full slug path (e.g., 'grandparent_parent_term') or term slug if no parent
     */
    private static function getFullTermSlug(
        int|string $termId,
        array $termMap,
        array &$visited = []
    ): string {
        // Normalize an integer array key back to the string id
        $termId = (string) $termId;

        // Get term data from the term map. Every id passed here is a key of the map
        // (the build pass iterates the map, and a parent is followed only when it is
        // one), so no test reaches the fallback; it narrows the type for PHPStan.
        $term = self::extractTermRecord(
            $termId,
            $termMap
        );

        if ($term === null) {
            // @pest-mutate-ignore: RemoveEarlyReturn
            return "term-$termId";
        }

        // Extract the base slug for this term
        $slug = $term['term_slug'];

        // Prevent infinite recursion due to circular parent references
        if (
            \in_array(
                $termId,
                $visited,
                true
            )
        ) {
            // Return base slug if we've already visited this term
            return $slug;
        }

        // Mark this term as visited to prevent circular references
        $visited[] = $termId;

        // Get the parent term ID for hierarchy traversal
        $parentId = $term['parent_id'];

        // Check if parent exists and build hierarchical slug. No term with an empty id
        // reaches the map, so an empty parent id is never a key of it.
        if (isset($termMap[$parentId])) {
            // Recursively get the full slug for the parent term
            $ancestorSlug = self::getFullTermSlug(
                $parentId,
                $termMap,
                $visited
            );

            // Combine ancestor slug with current term slug; no slug in the map is empty
            return "{$ancestorSlug}_{$slug}";
        }

        // Store result before clearing memory
        $result = $slug;

        // Return the base slug if no parent exists
        return $result;
    }

    /**
     * Extracts term data from a SimpleXMLElement term object.
     *
     * @param  SimpleXMLElement  $term  The term XML element
     * @return array{term_id: string, term_taxonomy: string, term_slug: string, term_name: string, term_parent: string} Associative array with term data
     */
    private static function extractTermData(
        SimpleXMLElement $term
    ): array {
        return [
            'term_id' => XpathHelper::getStringValue(
                $term,
                'wp:term_id'
            ),
            'term_taxonomy' => XpathHelper::getStringValue(
                $term,
                'wp:term_taxonomy'
            ),
            'term_slug' => XpathHelper::getStringValue(
                $term,
                'wp:term_slug'
            ),
            'term_name' => XpathHelper::getStringValue(
                $term,
                'wp:term_name'
            ),
            'term_parent' => XpathHelper::getStringValue(
                $term,
                'wp:term_parent'
            ),
        ];
    }

    /**
     * Extract and validate minimal term map record data.
     *
     * @param  string  $termId  The term ID to look up
     * @param  array<string, array{term_id: string, term_taxonomy: string, term_slug: string, term_name: string, term_parent: string, parent_id: string, full_slug: string}>  $termMap  Validated term map
     * @return array{term_slug: string, parent_id: string}|null Minimal term data for slug building
     */
    private static function extractTermRecord(
        string $termId,
        array $termMap
    ): ?array {
        if (! isset($termMap[$termId])) {
            // @pest-mutate-ignore: RemoveEarlyReturn
            return null;
        }

        return [
            'term_slug' => $termMap[$termId]['term_slug'],
            'parent_id' => $termMap[$termId]['parent_id'],
        ];
    }
}
