<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities;

use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Structures\CollectionStructure;
use Statamic\Structures\CollectionTree;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Utility class for managing collection structures during WordPress imports.
 *
 * Handles initialization of collection structures and trees to ensure
 * structured collections have proper tree configurations for each site.
 */
class CollectionStructureManager
{
    /**
     * Initialize collection structure and tree for a site if needed.
     *
     * Ensures that collections with structures have proper tree configurations
     * for the specified site. Creates structure and tree if they don't exist.
     *
     * @param  string  $collectionHandle  The collection handle
     * @param  string  $site  The site handle
     * @param  OutputInterface  $output  Console output interface for logging
     */
    public function initializeStructureForSite(
        string $collectionHandle,
        string $site,
        OutputInterface $output
    ): void {
        // Get the collection by its handle
        $collection = CollectionFacade::findByHandle($collectionHandle);

        // Check if collection exists and has a structure
        if (
            $collection !== null
            && $collection->hasStructure()
        ) {
            $rawStructure = $collection->structure();

            if ($rawStructure instanceof CollectionStructure) {
                $structure = $rawStructure;
            } else {
                // Log message about creating new structure
                $output->writeln("<comment>No structure exists for '$collectionHandle'. Creating one.</comment>");

                // Create new collection structure with the collection handle
                $structure = new CollectionStructure;
                $structure->handle($collectionHandle);

                // Assign the structure to the collection
                $collection->structure($structure);

                // Save the collection with the new structure
                $collection->save();
            }

            // Get the collection tree for the specified site
            $collectionTree = $structure->in($site);

            // Check if tree exists for the site
            if ($collectionTree instanceof CollectionTree) {
                // Log message about existing tree
                $output->writeln("<comment>Tree already exists for '$collectionHandle' in site '$site'.</comment>");
            } else {
                // Log message about creating new tree
                $output->writeln("<comment>No tree exists for '$collectionHandle' in site '$site'. Creating a new empty tree.</comment>");

                // Create new empty tree for the site
                $newTree = $structure->makeTree(
                    $site,
                    []
                );

                if ($newTree instanceof CollectionTree) {
                    // Save the new tree
                    $newTree->save();
                }
            }
        }
    }
}
