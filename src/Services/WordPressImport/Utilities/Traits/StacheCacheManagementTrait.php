<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Traits;

use Statamic\Facades\Stache as StacheFacade;

/**
 * Trait providing Stache cache management functionality.
 *
 * This trait provides methods to manage the Stache cache, specifically for
 * clearing cached paths to ensure entries are loaded from the correct file
 * paths (including date prefixes for dated collections).
 */
trait StacheCacheManagementTrait
{
    /**
     * Clear the paths cache and optionally the item cache for a specific collection.
     *
     * Clears the cached paths for the specified collection to ensure paths are
     * rebuilt with current file paths (including date prefixes for dated
     * collections). Optionally clears all cached entry objects to ensure entries
     * are reloaded fresh from disk with correct paths.
     *
     * @param  string  $collectionHandle  The collection handle (e.g., 'pages', 'post')
     * @param  bool  $clearItems  Whether to also clear cached entry objects (default: false)
     */
    protected function clearCollectionCache(
        string $collectionHandle,
        bool $clearItems = false
    ): void {
        // Get the entries store from the Stache
        $entriesStore = StacheFacade::store('entries');

        // Check if the entries store exists before accessing collection store
        if (
            ! is_object($entriesStore)
            || ! method_exists(
                $entriesStore,
                'store'
            )
        ) {
            return;
        }

        // Get the collection-specific store for this collection handle
        $collectionStore = $entriesStore->store($collectionHandle);

        // Check if the collection store exists before clearing cache
        if (
            is_object($collectionStore)
            && method_exists(
                $collectionStore,
                'clearCachedPaths'
            )
        ) {
            // Clear the cached paths to force rebuild with current file paths
            $collectionStore->clearCachedPaths();

            // Optionally clear all cached entry objects to ensure entries are
            // reloaded from disk with correct paths
            if (
                $clearItems
                && method_exists(
                    $collectionStore,
                    'clear'
                )
            ) {
                $collectionStore->clear();
            }
        }
    }
}
