<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Taxonomy;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Centralized service for managing site configurations for collections and
 * taxonomies.
 *
 * This service eliminates DRY violations by providing unified methods for
 * checking and enabling sites for collections and taxonomies. It standardizes the
 * approach to site management across the WordPress import system.
 */
class SiteManager
{
    /**
     * Check if a site is enabled for a collection.
     *
     * @param  string  $site  The site handle
     * @param  string  $collectionHandle  The collection handle
     * @return bool True if enabled, false otherwise
     */
    public function isCollectionEnabledForSite(
        string $site,
        string $collectionHandle,
    ): bool {
        // Get the collection by its handle
        /** @var ?\Statamic\Entries\Collection $collection */
        $collection = CollectionFacade::findByHandle($collectionHandle);

        // Return true if collection exists and is enabled for the specified site
        return $collection !== null
            && $this->siteListContains(
                $collection->sites(),
                $site
            );
    }

    /**
     * Check if a site is enabled for a taxonomy.
     *
     * @param  string  $site  The site handle
     * @param  string  $statamicTaxonomyHandle  The Statamic taxonomy handle
     * @return bool True if enabled, false otherwise
     */
    public function isTaxonomyEnabledForSite(
        string $site,
        string $statamicTaxonomyHandle
    ): bool {
        // Get the taxonomy by its handle
        /** @var ?\Statamic\Taxonomies\Taxonomy $taxonomy */
        $taxonomy = Taxonomy::findByHandle($statamicTaxonomyHandle);

        // Return true if taxonomy exists and is enabled for the specified site
        return $taxonomy !== null
            && $this->siteListContains(
                $taxonomy->sites(),
                $site
            );
    }

    /**
     * Enable a site for an import destination, whichever shape it takes.
     *
     * Resolves the handle as a taxonomy first and as a collection second, because
     * the two are interchangeable destinations from the importer's point of view
     * and several have migrated from the former to the latter — `uams-statamic-archive#1863`
     * converted `logos_item_categories` and `people_groups` into structured
     * collections, joining `post_categories`. A caller that asks only about a
     * taxonomy reports a converted destination as missing, and the callers that
     * gate a whole content type on the answer then skip every item of it.
     *
     * Failing closed on a destination that exists in neither shape is correct;
     * failing closed on one that exists in the other shape is the defect this
     * exists to remove.
     *
     * @param  string  $site  The site handle
     * @param  string  $handle  The destination handle, in either shape
     * @param  ?OutputInterface  $output  Console output interface for logging (optional)
     * @return bool True when the destination exists and the site is enabled for it
     */
    public function enableDestinationForSite(
        string $site,
        string $handle,
        ?OutputInterface $output = null
    ): bool {
        // Resolve the shape before announcing anything, so a converted destination
        // is never described to the operator as a missing taxonomy.
        if (Taxonomy::findByHandle($handle) !== null) {
            return $this->enableTaxonomyForSite(
                $site,
                $handle,
                $output
            );
        }

        if (CollectionFacade::findByHandle($handle) !== null) {
            return $this->enableCollectionForSite(
                $site,
                $handle,
                $output
            );
        }

        // Absent in both shapes: a genuine misconfiguration, and the one case
        // where refusing to import is right.
        if ($output instanceof OutputInterface) {
            $output->writeln(
                "  - <error>'$handle' exists in Statamic as neither a taxonomy nor a "
                .'collection.</error>'
            );
        }

        return false;
    }

    /**
     * Enable a site for a collection if not already enabled.
     *
     * @param  string  $site  The site handle
     * @param  string  $collectionHandle  The collection handle
     * @param  ?OutputInterface  $output  Console output interface for logging (optional)
     * @return bool True if enabled or already enabled, false if collection not found
     */
    public function enableCollectionForSite(
        string $site,
        string $collectionHandle,
        ?OutputInterface $output = null
    ): bool {
        // Display progress message to inform user of current operation
        if ($output instanceof OutputInterface) {
            $output->writeln("Checking if the '$collectionHandle' Statamic collection is enabled for the '$site' site...");
        }

        // Get the collection by its handle
        /** @var ?\Statamic\Entries\Collection $collection */
        $collection = CollectionFacade::findByHandle($collectionHandle);

        // Check if the collection exists
        if ($collection === null) {
            // Check if output interface is available for error logging
            if ($output instanceof OutputInterface) {
                // Log error message about missing collection
                $output->writeln("  - <error>The '$collectionHandle' collection does not exist in Statamic.</error>");
            }

            // Return false to indicate failure
            return false;
        }

        // Check if the collection is not already enabled for the site
        if (
            ! $this->isCollectionEnabledForSite(
                $site,
                $collectionHandle
            )
        ) {
            // Add site to the collection's sites (Statamic v6: sites array on collection)
            $collection->sites(
                $this->mergeSiteIntoList(
                    $collection->sites(),
                    $site
                )
            );

            // Persist collection so the new site is enabled
            $collection->save();

            // Check if output interface is available for success logging
            if ($output instanceof OutputInterface) {
                // Log success message about enabling the collection
                $output->writeln("  - <info>Enabled the '$collectionHandle' Statamic collection for the '$site' site.</info>");
            }

            // Return true to indicate successful enablement
            return true;
        }

        // Otherwise, the collection is already enabled for the site
        // Check if output interface is available for info logging
        if ($output instanceof OutputInterface) {
            // Log info message about collection already being enabled
            $output->writeln("  - <comment>The '$collectionHandle' Statamic collection is already enabled for the '$site' site.</comment>");
        }

        // Return true to indicate the collection is already enabled
        return true;
    }

    /**
     * Enable a site for a taxonomy if not already enabled.
     *
     * @param  string  $site  The site handle
     * @param  string  $statamicTaxonomyHandle  The Statamic taxonomy handle
     * @param  ?OutputInterface  $output  Console output interface for logging (optional)
     * @return bool True if enabled or already enabled, false if taxonomy not found
     */
    public function enableTaxonomyForSite(
        string $site,
        string $statamicTaxonomyHandle,
        ?OutputInterface $output = null
    ): bool {
        // Display progress message to inform user of current operation
        if ($output instanceof OutputInterface) {
            $output->writeln("Checking if the '$statamicTaxonomyHandle' Statamic taxonomy is enabled for the '$site' site...");
        }

        // Get the taxonomy by its handle
        /** @var ?\Statamic\Taxonomies\Taxonomy $taxonomy */
        $taxonomy = Taxonomy::findByHandle($statamicTaxonomyHandle);

        // Check if the taxonomy exists
        if ($taxonomy === null) {
            // Check if output interface is available for error logging
            if ($output instanceof OutputInterface) {
                // Log error message about missing taxonomy
                $output->writeln("  - <error>The '$statamicTaxonomyHandle' taxonomy does not exist in Statamic.</error>");
            }

            // Return false to indicate failure
            return false;
        }

        // Check if the taxonomy is not already enabled for the site
        if (
            ! $this->isTaxonomyEnabledForSite(
                $site,
                $statamicTaxonomyHandle
            )
        ) {
            // Add site to the taxonomy's sites (Statamic v6: sites array on taxonomy)
            $taxonomy->sites(
                $this->mergeSiteIntoList(
                    $taxonomy->sites(),
                    $site
                )
            );

            // Persist taxonomy so the new site is enabled
            $taxonomy->save();

            // Check if output interface is available for success logging
            if ($output instanceof OutputInterface) {
                // Log success message about enabling the taxonomy
                $output->writeln("  - <info>Enabled the '$statamicTaxonomyHandle' Statamic taxonomy for the '$site' site.</info>");
            }

            // Return true to indicate successful enablement
            return true;
        }

        // Otherwise, the taxonomy is already enabled for the site
        // Check if output interface is available for info logging
        if ($output instanceof OutputInterface) {
            // Log info message about taxonomy already being enabled
            $output->writeln("  - <comment>The '$statamicTaxonomyHandle' Statamic taxonomy is already enabled for the '$site' site.</comment>");
        }

        // Return true to indicate the taxonomy is already enabled
        return true;
    }

    /**
     * Normalize Statamic sites() return value to a list of site handles.
     *
     * @param  mixed  $sites  Collection or array of site handles from Statamic
     * @return list<string> Normalized site handle list
     */
    private function normalizeSiteList(
        mixed $sites
    ): array {
        if ($sites instanceof Collection) {
            $siteList = $sites->all();
        } elseif (\is_array($sites)) {
            $siteList = $sites;
        } else {
            return [];
        }

        $normalized = [];

        foreach ($siteList as $siteHandle) {
            if (\is_string($siteHandle) && $siteHandle !== '') {
                $normalized[] = $siteHandle;
            }
        }

        return $normalized;
    }

    /**
     * Check whether a normalized site list contains the given site handle.
     *
     * @param  mixed  $sites  Collection or array of site handles from Statamic
     * @param  string  $site  The site handle to find
     */
    private function siteListContains(
        mixed $sites,
        string $site
    ): bool {
        return \in_array(
            $site,
            $this->normalizeSiteList($sites),
            true
        );
    }

    /**
     * Merge a site handle into a Statamic sites list without duplicates.
     *
     * @param  mixed  $sites  Collection or array of site handles from Statamic
     * @param  string  $site  The site handle to add
     * @return list<string> Updated site handle list
     */
    private function mergeSiteIntoList(
        mixed $sites,
        string $site
    ): array {
        return \array_values(
            \array_unique(
                [
                    ...$this->normalizeSiteList($sites),
                    $site,
                ]
            )
        );
    }
}
