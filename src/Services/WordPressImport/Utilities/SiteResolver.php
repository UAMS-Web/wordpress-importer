<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities;

use Statamic\Entries\Entry;
use Statamic\Facades\Site;
use Statamic\Sites\Site as StatamicSite;

/**
 * Utility class for resolving the current site handle in the WordPress import
 * context.
 *
 * Provides a centralized way to retrieve the site handle, prioritizing the site
 * selected during the import command, then entry data, Statamic's multi-site
 * configuration, and finally falling back to 'default'.
 */
class SiteResolver
{
    /**
     * The site handle selected during the import command.
     *
     * @var ?string
     */
    protected static $selectedSite;

    /**
     * Get the current site handle.
     *
     * Checks the command-selected site first, then an entry's site field,
     * Statamic's current site, and defaults to 'default' if undetermined.
     *
     * @param  ?Entry  $entry  Optional entry to extract site from
     * @return ?string The site handle or null if undetermined
     */
    public static function getCurrentSite(
        ?Entry $entry = null
    ): ?string {
        // Prioritize command-selected site
        if (static::$selectedSite !== null && static::$selectedSite !== '') {
            return static::$selectedSite;
        }

        // Check entry's site field if provided
        if ($entry instanceof Entry && $entry->has('site')) {
            $site = $entry->get('site');

            if (is_string($site)) {
                return $site;
            }

            return 'default';
        }

        // Fall back to Statamic's current site
        $currentSite = Site::current();

        if ($currentSite instanceof StatamicSite) {
            $handle = $currentSite->handle();

            return is_string($handle) ? $handle : 'default';
        }

        // Default fallback
        return 'default';
    }

    /**
     * Set the site handle selected during the import command.
     *
     * @param  string  $site  The site handle to set
     */
    public static function setSelectedSite(
        string $site
    ): void {
        static::$selectedSite = $site;
    }

    /**
     * Clear the selected site (useful for testing or new import runs).
     */
    public static function clearSelectedSite(): void
    {
        static::$selectedSite = null;
    }
}
