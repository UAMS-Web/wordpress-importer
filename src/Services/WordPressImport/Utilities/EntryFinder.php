<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities;

use Statamic\Entries\Entry;
use Statamic\Facades\Stache as StacheFacade;

/**
 * Utility class for finding entries by various criteria.
 *
 * This class provides centralized methods for finding entries in both dated
 * and non-dated collections, handling the complexities of entry lookup
 * across different collection configurations.
 *
 * The class follows the Single Responsibility Principle by focusing solely
 * on entry finding operations, and the DRY principle by centralizing this
 * common pattern.
 */
class EntryFinder
{
    /**
     * Find an entry by ID using standard Statamic query syntax.
     *
     * This method provides a centralized way to find entries by their ID across
     * different collections and sites. It uses standard Statamic query patterns
     * and includes optional cache refresh functionality to ensure fresh data
     * when needed.
     *
     * The method supports both custom IDs (containing hyphens and WordPress IDs)
     * and Statamic's default IDs (UUIDs or simple strings). The cache refresh
     * functionality ensures that newly saved entries are immediately available
     * for querying, which is particularly important for dated collections where
     * the entry's date information needs to be properly cached.
     *
     * @param  string  $collectionHandle  The collection handle to search within
     * @param  string  $id  The entry ID to find (custom or default Statamic ID)
     * @param  bool  $forceFresh  Whether to force a fresh query by clearing cache (optional)
     * @param  ?string  $site  The site handle for explicit site filtering (optional)
     * @return ?Entry The found entry or null if not found
     */
    public static function findEntryById(
        string $collectionHandle,
        string $id,
        bool $forceFresh = false,
        ?string $site = null
    ): ?Entry {
        // Handle cache refresh if explicitly requested
        // This is useful when querying immediately after saving an entry
        // to ensure the Stache cache has been updated with the latest data
        if ($forceFresh) {
            // Clear the collection entry from Stache to force a fresh load
            self::forgetStacheEntry(
                $collectionHandle,
                $id
            );
        }

        // Build the base query using standard Statamic syntax
        // Start with Entry::query() and filter by collection handle
        $query = EntryQueryHelper::query();
        $query->where(
            'collection',
            $collectionHandle
        );

        // Add site filtering if a specific site is provided
        // This ensures we only search within the specified site/locale
        if ($site !== null && $site !== '') {
            $query->where(
                'site',
                $site
            );
        }

        // Execute the query to find the entry by ID
        // Returns the first matching entry or null if not found
        $query->where(
            'id',
            $id
        );
        $result = $query->first();

        // Return the resolved entry only when query returns an Entry instance. An
        // entry query's first() returns an Entry or null, and null passes through
        // either branch, so the check only narrows the type.
        // @pest-mutate-ignore: InstanceOfToTrue
        return $result instanceof Entry ? $result : null;
    }

    /**
     * Find an entry by slug using standard Statamic query syntax.
     *
     * This method provides a centralized way to find entries by their slug across
     * different collections and sites. It uses standard Statamic query patterns
     * and includes optional cache refresh functionality to ensure fresh data
     * when needed.
     *
     * The method supports both custom slugs and Statamic's default slugs. The cache
     * refresh functionality ensures that newly saved entries are immediately
     * available for querying, which is particularly important for dated collections
     * where the entry's date information needs to be properly cached.
     *
     * @param  string  $collectionHandle  The collection handle to search within
     * @param  string  $slug  The entry slug to find
     * @param  bool  $forceFresh  Whether to force a fresh query by clearing cache (optional)
     * @param  ?string  $site  The site handle for explicit site filtering (optional)
     * @return ?Entry The found entry or null if not found
     */
    public static function findEntryBySlug(
        string $collectionHandle,
        string $slug,
        bool $forceFresh = false,
        ?string $site = null
    ): ?Entry {
        // Handle cache refresh if explicitly requested
        // This is useful when querying immediately after saving an entry
        // to ensure the Stache cache has been updated with the latest data
        if ($forceFresh) {
            // Clear the collection entry from Stache to force a fresh load
            self::forgetStacheEntry(
                $collectionHandle,
                $slug
            );
        }

        // Build the base query using standard Statamic syntax
        // Start with Entry::query() and filter by collection handle
        $query = EntryQueryHelper::query();
        $query->where(
            'collection',
            $collectionHandle
        );

        // Add site filtering if a specific site is provided
        // This ensures we only search within the specified site/locale
        if ($site !== null && $site !== '') {
            $query->where(
                'site',
                $site
            );
        }

        // Execute the query to find the entry by slug
        // Returns the first matching entry or null if not found
        $query->where(
            'slug',
            $slug
        );
        $result = $query->first();

        // Return the resolved entry only when query returns an Entry instance. An
        // entry query's first() returns an Entry or null, and null passes through
        // either branch, so the check only narrows the type.
        // @pest-mutate-ignore: InstanceOfToTrue
        return $result instanceof Entry ? $result : null;
    }

    /**
     * Find an entry by WordPress post ID using standard Statamic query syntax.
     *
     * This method provides a centralized way to find entries by their embedded
     * WordPress post ID across different collections and sites. It uses standard
     * Statamic query patterns and includes optional cache refresh functionality to
     *  ensure fresh data when needed.
     *
     * The method extracts the WordPress post ID from the entry ID format:
     * {slug}-{wordpress_id}-{randomString}. This is the standard format used
     * throughout the WordPress import system for maintaining WordPress-to-Statamic ID
     * relationships.
     *
     * @param  string  $collectionHandle  The collection handle to search within
     * @param  string  $wordPressId  The WordPress post ID to find
     * @param  bool  $forceFresh  Whether to force a fresh query by clearing cache (optional)
     * @param  ?string  $site  The site handle for explicit site filtering (optional)
     * @return ?Entry The found entry or null if not found
     */
    public static function findEntryByWordPressId(
        string $collectionHandle,
        string $wordPressId,
        bool $forceFresh = false,
        ?string $site = null
    ): ?Entry {
        // Build the base query using standard Statamic syntax
        // Start with Entry::query() and filter by collection handle
        $query = EntryQueryHelper::query();
        $query->where(
            'collection',
            $collectionHandle
        );

        // Add site filtering if a specific site is provided
        // This ensures we only search within the specified site/locale
        if ($site !== null && $site !== '') {
            $query->where(
                'site',
                $site
            );
        }

        // Get all entries and search for the one with matching WordPress ID
        $entries = $query->get();

        // Return null when query result is not iterable. An entry query's get()
        // returns a collection, so no test reaches this return.
        if (! \is_iterable($entries)) {
            // @pest-mutate-ignore: RemoveEarlyReturn
            return null;
        }

        // Build regex pattern for the WordPress ID suffix
        $pattern = '/-'.preg_quote($wordPressId, '/').'-[a-zA-Z0-9]+$/';

        // Find entry with matching WordPress ID in the entry ID
        // Entry IDs are formatted as: {slug}-{wordpress_id}-{randomString}
        foreach ($entries as $entry) {
            // Skip items that are not valid Entry instances. The query yields only
            // entries, so the check narrows the type and the skip is never taken.
            // @pest-mutate-ignore: InstanceOfToTrue
            if (! $entry instanceof Entry) {
                // @pest-mutate-ignore: ContinueToBreak
                continue;
            }

            // Handle cache refresh if explicitly requested
            if ($forceFresh) {
                // Extract the collection handle for Stache invalidation
                $entryCollectionHandle = $entry->collectionHandle();

                // Extract entry identifier for Stache invalidation
                $entryIdentifier = $entry->id();

                // Skip cache invalidation when identifiers are not strings (an entry
                // file whose YAML reads `id: 123` loads with an integer id)
                if (
                    ! \is_string($entryCollectionHandle)
                    || ! \is_string($entryIdentifier)
                ) {
                    continue;
                }

                self::forgetStacheEntry(
                    $entryCollectionHandle,
                    $entryIdentifier
                );
            }

            // Extract WordPress ID from entry ID for pattern matching
            $entryId = $entry->id();

            // Skip entries with non-string IDs
            if (! \is_string($entryId)) {
                continue;
            }

            // Return the first entry whose ID contains the WordPress suffix
            if (
                \preg_match(
                    $pattern,
                    $entryId
                ) === 1
            ) {
                return $entry;
            }
        }

        // Return null when no matching entry is found
        return null;
    }

    /**
     * Remove a specific entry key from the Stache entry store.
     *
     * @param  string  $collectionHandle  Collection handle for the entry store
     * @param  string  $entryKey  Entry key or identifier to forget
     */
    private static function forgetStacheEntry(
        string $collectionHandle,
        string $entryKey
    ): void {
        // Resolve the top-level Stache entries store
        $entriesStore = StacheFacade::store('entries');

        // Stop when the entries store object is unavailable. Statamic always
        // registers the entries store as an aggregate store, so neither half is true.
        // @pest-mutate-ignore: BooleanOrToBooleanAnd
        if (! \is_object($entriesStore) || ! \method_exists($entriesStore, 'store')) {
            // @pest-mutate-ignore: RemoveEarlyReturn
            return;
        }

        // Resolve the scoped store for the collection handle
        $collectionStore = $entriesStore->store($collectionHandle);

        // Stop when the collection store does not support entry invalidation. The
        // aggregate store always returns a child store with forgetItem().
        // @pest-mutate-ignore: BooleanOrToBooleanAnd
        if (! \is_object($collectionStore) || ! \method_exists($collectionStore, 'forgetItem')) {
            return;
        }

        // Forget the specific entry key from Stache cache
        $collectionStore->forgetItem($entryKey);
    }
}
