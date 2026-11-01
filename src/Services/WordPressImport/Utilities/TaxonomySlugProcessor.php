<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities;

/**
 * Utility class for processing taxonomy slugs with site prefixes.
 *
 * Provides common functionality for adding site prefixes to taxonomy slugs,
 * ensuring consistency across different parsers and entry creators.
 */
class TaxonomySlugProcessor
{
    /**
     * Adds site prefix to a taxonomy slug, preserving hierarchy structure when
     * present.
     *
     * This method always uses underscores to separate the site prefix from the
     * term slug to maintain consistent hierarchy structure for taxonomy terms:
     *   - For root-level terms like 'department-of-audiology', adds the site
     *     prefix with an underscore:
     *     'site-department-of-audiology'
     *   - For hierarchical terms like 'department_term', adds the site prefix
     *     with an underscore:
     *     'site-department_term'
     *
     * @param  string  $slug  The taxonomy slug to process (may be hierarchical or simple)
     * @param  string  $site  The Statamic site handle
     * @return string The site-specific slug with preserved hierarchy
     */
    public static function addSitePrefixToSlug(
        string $slug,
        string $site
    ): string {
        // Convert site handle to slug format
        $siteHandleForSlug = \str_replace(
            '_',
            '-',
            $site
        );

        // ---
        // Always use underscores to separate site prefix from term slug for taxonomy
        // terms to maintain consistent hierarchy structure, regardless of whether the
        // term itself has children
        // ---
        return "{$siteHandleForSlug}_{$slug}";
    }

    /**
     * Adds site prefix to taxonomy slugs for site-specific taxonomy terms.
     *
     * @param  array<int|string, mixed>  $slugs  Array of taxonomy slugs to process
     * @param  string  $site  The Statamic site handle
     * @return array<int|string, mixed> Array of site-specific slugs
     */
    public static function addSitePrefixToSlugs(
        array $slugs,
        string $site
    ): array {
        return \array_map(
            fn ($slug) => \is_string($slug)
                ? self::addSitePrefixToSlug(
                    $slug,
                    $site
                )
                : $slug,
            $slugs
        );
    }
}
