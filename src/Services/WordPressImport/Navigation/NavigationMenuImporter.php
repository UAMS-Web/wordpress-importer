<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Navigation;

use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use SimpleXMLElement;
use Statamic\Entries\Entry;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Nav as NavFacade;
use Statamic\Structures\Nav as NavStructure;
use Statamic\Structures\NavTree;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Console\Commands\ImportWordPress;
use UAMSWeb\WordPressImporter\Services\WordPressImport\DeferredProcessing\KbPageSubstitutions;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\EntryFinder;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\HtmlTagStripper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;

/**
 * Imports WordPress navigation menu items into Statamic navigation.
 *
 * This service handles the import of nav_menu_item posts from WordPress XML
 * exports into Statamic's primary_navigation. It processes different menu item
 * types:
 *   - 'custom': Custom URLs using _menu_item_url
 *   - 'post_type': Links to WordPress post types (pages, posts, kb, etc.)
 *   - 'taxonomy': Links to a WordPress taxonomy term. Resolved by
 *     {@see TaxonomyNavigationResolver}, which links the entry the term
 *     imported into and reports the ones with no entry to link
 *
 * The importer maintains the hierarchical structure using
 * _menu_item_menu_item_parent and only imports items from the selected menu based
 * on category domain="nav_menu".
 */
class NavigationMenuImporter
{
    /**
     * Hierarchy item data used while constructing the menu tree.
     *
     * @phpstan-type HierarchyItem array{
     *     item: SimpleXMLElement,
     *     parent_id: string,
     *     menu_order: int,
     *     children: list<string>
     * }
     * @phpstan-type HierarchyMap array<string, HierarchyItem>
     * @phpstan-type NavItem array{id: string, title?: string, entry?: string, url?: string}
     * @phpstan-type TreeItem array<string, mixed>
     */

    /**
     * Cache for entry lookups to avoid repeated Statamic facade calls.
     *
     * @var array<string, Entry>
     */
    private static array $entryCache = [];

    /**
     * Resolver for taxonomy navigation items, primed from the export being imported.
     *
     * Held per instance rather than statically: it is derived from one export,
     * and a second import in the same process must not inherit the first one's
     * term slugs. {@see self::taxonomyResolver()} supplies a registry-only
     * resolver until {@see self::importNavigationMenu()} primes this.
     */
    private ?TaxonomyNavigationResolver $taxonomyResolver = null;

    /**
     * Import navigation menu items from WordPress XML into Statamic navigation.
     *
     * Processes nav_menu_item posts from the specified menu and creates corresponding
     * Statamic navigation items in the primary_navigation. Handles different menu
     * item types and maintains hierarchical structure.
     *
     * @param  XmlParser  $xmlParser  Parser instance for accessing WordPress XML data
     * @param  string  $selectedMenuSlug  The slug of the selected menu to import
     * @param  string  $site  The Statamic site handle
     * @param  OutputInterface  $output  Console output interface for progress logging
     */
    public function importNavigationMenu(
        XmlParser $xmlParser,
        string $selectedMenuSlug,
        string $site,
        OutputInterface $output
    ): void {
        // Log that the navigation menu import is starting for the selected menu
        $output->writeln("Starting navigation menu import for the '{$selectedMenuSlug}' menu...");

        // ---
        // Prime the taxonomy resolver from this export before any item is built.
        // It reads the term slugs the export declares, which is what lets a
        // taxonomy item still resolve when the term pass did not run in this
        // process — a navigation-only re-run, or a split WXR import.
        // ---
        $this->taxonomyResolver = TaxonomyNavigationResolver::fromExport($xmlParser);

        // Get all nav_menu_item posts
        $navMenuItems = $xmlParser->getItems('nav_menu_item');

        // Check if there are no nav_menu_item items
        if ($navMenuItems === []) {
            // Log that no nav_menu_item items were found
            $output->writeln("  - <comment>No 'nav_menu_item' items found</comment>");

            // Return early if no nav_menu_item items were found
            return;
        }

        // Count the total number of nav_menu_item items
        $navMenuItemsCount = \count($navMenuItems);

        // Log the total number of nav_menu_item items
        $output->writeln('  - Found '.$navMenuItemsCount." total 'nav_menu_item' ".($navMenuItemsCount === 1 ? 'item' : 'items').'.');

        // Filter items for the selected menu
        $filteredItems = $this->filterItemsForMenu(
            $navMenuItems,
            $selectedMenuSlug
        );

        // Check if there are no filtered items
        if ($filteredItems === []) {
            // Log that no filtered items were found
            $output->writeln("  - <comment>No 'nav_menu_item' items found for the '{$selectedMenuSlug}' menu</comment>");

            // Return early if no filtered items were found
            return;
        }

        // Count the number of filtered items
        $filteredItemsCount = \count($filteredItems);

        // Log the number of filtered items
        $output->writeln('  - Found '.$filteredItemsCount." 'nav_menu_item' ".($filteredItemsCount === 1 ? 'item' : 'items')." for the '{$selectedMenuSlug}' menu.");

        // Get the Statamic navigation
        /** @var ?NavStructure $navigation */
        $navigation = NavFacade::findByHandle('primary_navigation');

        // Check if navigation was found
        if ($navigation === null) {
            // Log error and return early if navigation not found
            $output->writeln('  - <error>Primary navigation not found in Statamic</error>');

            // Return early since navigation is required
            return;
        }

        // Get the localized navigation for the site
        $localizedNav = $navigation->in($site);

        // ---
        // Create and save a new tree for the site when localized navigation is not available
        // ---

        // Check if localized navigation is not available
        if (! ($localizedNav instanceof NavTree)) {
            // Log that primary navigation is not available and that a tree will be created
            $output->writeln("  - <comment>Primary navigation not available for site: {$site}. Creating tree.</comment>");

            // Create new empty tree for the site
            $newTree = $navigation->makeTree(
                $site,
                []
            );

            // Skip import when Statamic did not return a valid navigation tree.
            if (! ($newTree instanceof NavTree)) {
                $output->writeln("  - <error>Could not create primary navigation tree for site: {$site}</error>");

                return;
            }

            // Store the validated tree for the remaining import flow.
            $localizedNav = $newTree;

            // Save the new tree so it is available for the site
            $localizedNav->save();
        }

        // Clear existing navigation items for this site
        $this->clearExistingNavigationItems(
            $localizedNav,
            $output
        );

        // Build hierarchy map
        $hierarchyMap = $this->buildHierarchyMap($filteredItems);

        // Import navigation items
        $this->importNavigationItems(
            $hierarchyMap,
            $localizedNav,
            $site,
            $output,
            $selectedMenuSlug
        );

        // Clear caches
        $this->clearCaches();

        // Log completion message
        $output->writeln('<info>Navigation menu import completed</info>');
    }

    /**
     * Filter nav_menu_item posts for the selected menu.
     *
     * @param  list<SimpleXMLElement>  $navMenuItems  Array of nav_menu_item posts
     * @param  string  $selectedMenuSlug  The slug of the selected menu
     * @return list<SimpleXMLElement> Filtered array of nav_menu_item posts
     */
    private function filterItemsForMenu(
        array $navMenuItems,
        string $selectedMenuSlug
    ): array {
        // Initialize array to store filtered items
        $filteredItems = [];

        // Loop through each navigation menu item
        foreach ($navMenuItems as $item) {
            // ---
            // Check if this item belongs to the selected menu
            // ---

            // Get all menu categories for this item
            $menuCategories = $item->xpath("category[@domain='nav_menu']");

            // Skip items that do not expose a valid categories array.
            if (! \is_array($menuCategories)) {
                continue;
            }

            // Loop through menu categories to find matching menu
            foreach ($menuCategories as $category) {
                // Extract the menu slug from the category
                $menuSlug = (string) ($category['nicename'] ?? '');

                // Check if this item belongs to the selected menu
                if ($menuSlug === $selectedMenuSlug) {
                    // Add item to filtered list
                    $filteredItems[] = $item;

                    // Break out of loop since we found a match
                    break;
                }
            }
        }

        // Return filtered items
        return $filteredItems;
    }

    /**
     * Build hierarchy map from nav_menu_item posts.
     *
     * @param  list<SimpleXMLElement>  $navMenuItems  Array of nav_menu_item posts
     * @return array<string, array{item: SimpleXMLElement, parent_id: string, menu_order: int, children: list<string>}> Hierarchy map with parent-child relationships
     */
    private function buildHierarchyMap(
        array $navMenuItems
    ): array {
        // Initialize hierarchy map array
        $hierarchyMap = [];

        // Loop through each navigation menu item to build hierarchy
        foreach ($navMenuItems as $item) {
            // Extract the menu item ID from WordPress XML
            $itemId = XpathHelper::getStringValue(
                $item,
                'wp:post_id'
            );

            // ---
            // Extract the parent menu item ID from postmeta, normalizing the
            // sentinel WordPress writes for a top-level item.
            //
            // WordPress stores `_menu_item_menu_item_parent` as **`0`** for an
            // item with no parent, not as an empty string, and every check
            // downstream tests `=== ''`. Normalizing here rather than at each of
            // those three sites keeps one definition of "top level".
            //
            // Left unnormalized this is not a cosmetic mismatch: a `'0'` parent
            // makes an item neither top-level nor a child of anything in the
            // map, so it is dropped without being imported, counted, or logged —
            // and its own children go with it. Across the 259-export corpus that
            // is 3,938 items encoded `0` against 69 with the meta absent, i.e.
            // nearly every menu came out empty.
            // ---
            $parentId = $this->normalizeParentId(
                $this->getMenuItemMeta(
                    $item,
                    '_menu_item_menu_item_parent'
                )
            );

            // Extract the menu order from WordPress XML
            $menuOrder = XpathHelper::getIntValue(
                $item,
                'wp:menu_order'
            );

            // Check if item ID was found
            if ($itemId !== '') {
                // Add item to hierarchy map with its data
                $hierarchyMap[$itemId] = [
                    'item' => $item,
                    'parent_id' => $parentId,
                    'menu_order' => $menuOrder,
                    'children' => [],
                ];
            }
        }

        // Loop through hierarchy map to establish parent-child links
        foreach ($hierarchyMap as $itemId => $itemData) {
            // Get the parent ID for this item
            $parentId = $itemData['parent_id'];

            // Check if item has a valid parent in the hierarchy map
            if (
                $parentId !== ''
                && isset($hierarchyMap[$parentId])
            ) {
                // Add this item as a child of its parent
                $hierarchyMap[$parentId]['children'][] = $itemId;
            }
        }

        // Return the complete hierarchy map
        return $hierarchyMap;
    }

    /**
     * Clear existing navigation items for the site.
     *
     * @param  NavTree  $localizedNav  The localized navigation
     * @param  OutputInterface  $output  Console output interface
     */
    private function clearExistingNavigationItems(
        NavTree $localizedNav,
        OutputInterface $output
    ): void {
        // Get existing navigation items
        $existingItems = $localizedNav->tree();

        // Normalize existing items to an array before counting/clearing.
        if (! \is_array($existingItems)) {
            $existingItems = [];
        }

        // Check if there are existing items to clear
        if ($existingItems !== []) {
            // Log the number of items being cleared
            $output->writeln('<comment>Clearing '.\count($existingItems).' existing navigation items</comment>');

            // Clear the navigation tree
            $localizedNav->tree([]);

            // Save the cleared navigation
            $localizedNav->save();
        }
    }

    /**
     * Import navigation items with proper hierarchy.
     *
     * @param  array<string, array{item: SimpleXMLElement, parent_id: string, menu_order: int, children: list<string>}>  $hierarchyMap  Hierarchy map with parent-child relationships
     * @param  NavTree  $localizedNav  The localized navigation
     * @param  string  $site  The Statamic site handle
     * @param  OutputInterface  $output  Console output interface
     * @param  string  $selectedMenuSlug  The selected menu slug for logging
     */
    private function importNavigationItems(
        array $hierarchyMap,
        NavTree $localizedNav,
        string $site,
        OutputInterface $output,
        string $selectedMenuSlug
    ): void {
        // Initialize array to store imported navigation items
        $importedItems = [];

        // ---
        // Initialize counters for tracking processing results
        // ---

        // Number of navigation items successfully created
        $processedCount = 0;

        // Number of navigation items that were skipped
        $skippedCount = 0;

        // Loop through hierarchy map to process top-level items
        foreach ($hierarchyMap as $itemId => $itemData) {
            // Check if this is a top-level item (no parent)
            if ($itemData['parent_id'] === '') {
                // Create navigation item from WordPress menu item
                $navItem = $this->createNavigationItem(
                    $itemData['item'],
                    $site,
                    $output
                );

                // Check if navigation item was created successfully
                if ($navItem !== null) {
                    // Store the imported item
                    $importedItems[$itemId] = $navItem;

                    // Increment processed count
                    $processedCount++;
                } else { // Otherwise
                    // Increment skipped count
                    $skippedCount++;
                }
            }
        }

        // Import child items
        $childResults = $this->importChildItems(
            $hierarchyMap,
            $importedItems,
            $site,
            $output
        );

        // Add child item counts to totals
        $processedCount += $childResults['processed'];

        // Add child skipped counts to totals
        $skippedCount += $childResults['skipped'];

        // ---
        // Neither pass looks at an item whose parent chain never reaches a
        // top-level item, so such an item leaves no trace at all — it is not
        // imported, not skipped, and not logged. Report it: a drop nobody counts
        // is exactly what let the `_menu_item_menu_item_parent` defect above
        // hide 3,938 corpus items behind a "completed" message.
        // ---
        $unreachableItems = $this->itemsWithUnreachableParents($hierarchyMap);

        if ($unreachableItems !== []) {
            $output->writeln(
                '  - <comment>Not imported: '.\count($unreachableItems)
                    .' navigation item(s) name a parent outside this menu ('
                    .\implode(', ', $unreachableItems).')</comment>'
            );

            ImportWarningManager::addWarning(
                'navigation',
                'unreachable_menu_item',
                [
                    'menu' => $selectedMenuSlug,
                    'site' => $site,
                    'wordpress_item_ids' => \implode(', ', $unreachableItems),
                    'reason' => 'Each item names a `_menu_item_menu_item_parent` that is not among '
                        ."this menu's own items, so it has no place in the tree.",
                ]
            );
        }

        // Build the tree structure
        $tree = $this->buildNavigationTree(
            $hierarchyMap,
            $importedItems
        );

        // Remove internal WordPress menu item ID fields before saving
        $tree = $this->removeInternalFields($tree);

        // Set the tree structure on the navigation
        $localizedNav->tree($tree);

        // Save the navigation with updated tree
        $localizedNav->save();

        // Display comprehensive results to user
        $output->writeln('<info>Navigation import completed:</info>');
        $output->writeln("  - Successfully imported: {$processedCount} navigation items");
        if ($skippedCount > 0) {
            $output->writeln("  - <comment>Skipped: {$skippedCount} navigation items (missing entries or invalid data)</comment>");
        }
    }

    /**
     * Import child navigation items recursively.
     *
     * @param  array<string, array{item: SimpleXMLElement, parent_id: string, menu_order: int, children: list<string>}>  $hierarchyMap  Hierarchy map with parent-child relationships
     * @param  array<string, array{id: string, title?: string, entry?: string, url?: string}>  $importedItems  Array of imported navigation items
     * @param  string  $site  The Statamic site handle
     * @param  OutputInterface  $output  Console output interface
     * @return array{processed: int, skipped: int} Statistics about the import process
     */
    private function importChildItems(
        array $hierarchyMap,
        array &$importedItems,
        string $site,
        OutputInterface $output
    ): array {
        // ---
        // Initialize counters for tracking processing results
        // ---

        // Number of child items successfully created
        $processedCount = 0;

        // Child items that could not be created, keyed by item id.
        //
        // **A set rather than a counter, because the loop below revisits them (wordpress-importer-archive#1171).** An item
        // that fails to create is never added to `$importedItems`, so its `! isset()` guard stays
        // true and it is retried on every pass of the `while ($changed)` loop — incrementing a
        // counter each time. One dropped item reported as three, and the count is what an
        // operator uses to judge whether a navigation import is worth re-running.
        $skippedIds = [];

        // Flag to track if any items were processed in this iteration
        $changed = true;

        // Continue processing while items are being added
        while ($changed) {
            // Reset changed flag for this iteration
            $changed = false;

            // Loop through hierarchy map to find processable child items
            foreach ($hierarchyMap as $itemId => $itemData) {
                // Check if item hasn't been imported, has a parent, and parent is imported
                if (
                    ! isset($importedItems[$itemId])
                    && ! isset($skippedIds[$itemId])
                    && $itemData['parent_id'] !== ''
                    && isset($importedItems[$itemData['parent_id']])
                ) {
                    // Create navigation item from WordPress menu item
                    $navItem = $this->createNavigationItem(
                        $itemData['item'],
                        $site,
                        $output
                    );

                    // Check if navigation item was created successfully
                    if ($navItem !== null) {
                        // Store the imported item
                        $importedItems[$itemId] = $navItem;

                        // Increment processed count
                        $processedCount++;

                        // Mark that changes were made
                        $changed = true;
                    } else { // Otherwise
                        // Record the id rather than counting, so a retry cannot count it twice.
                        $skippedIds[$itemId] = true;
                    }
                }
            }
        }

        // Return processing statistics
        return [
            'processed' => $processedCount,
            'skipped' => \count($skippedIds),
        ];
    }

    /**
     * Build navigation tree structure from imported items.
     *
     * @param  array<string, array{item: SimpleXMLElement, parent_id: string, menu_order: int, children: list<string>}>  $hierarchyMap  Hierarchy map with parent-child relationships
     * @param  array<string, array{id: string, title?: string, entry?: string, url?: string}>  $importedItems  Array of imported navigation items
     * @return list<array<string, mixed>> Navigation tree structure
     */
    private function buildNavigationTree(
        array $hierarchyMap,
        array $importedItems
    ): array {
        // Initialize tree array
        $tree = [];

        // Loop through hierarchy map to build tree structure
        foreach ($hierarchyMap as $itemId => $itemData) {
            // Check if this is a top-level item that was successfully imported
            if (
                $itemData['parent_id'] === ''
                && isset($importedItems[$itemId])
            ) {
                // Build tree item with its children
                $treeItem = $this->buildTreeItem(
                    $itemId,
                    $hierarchyMap,
                    $importedItems
                );

                // Check if tree item was built successfully
                if ($treeItem !== null && $treeItem !== []) {
                    // Add tree item to tree array
                    $tree[] = $treeItem;
                }
            }
        }

        // Sort top-level items by menu order
        usort(
            $tree,
            fn (array $a, array $b): int => $this->compareTreeItems(
                $a,
                $b,
                $hierarchyMap
            )
        );

        // Return the sorted tree structure
        return $tree;
    }

    /**
     * Build a single tree item with its children.
     *
     * @param  int|string  $itemId  The WordPress menu item ID; an integer when it came from an array key, since PHP stores numeric-string keys as integers
     * @param  array<string, array{item: SimpleXMLElement, parent_id: string, menu_order: int, children: list<string>}>  $hierarchyMap  Hierarchy map with parent-child relationships
     * @param  array<string, array{id: string, title?: string, entry?: string, url?: string}>  $importedItems  Array of imported navigation items
     * @return array<string, mixed>|null Tree item structure or null if item not found
     */
    private function buildTreeItem(
        int|string $itemId,
        array $hierarchyMap,
        array $importedItems
    ): ?array {
        // Normalize an integer array key back to the string id
        $itemId = (string) $itemId;

        // Check if imported item exists for this ID
        if (! isset($importedItems[$itemId])) {
            // Return null if item not found
            return null;
        }

        // Get the imported navigation item
        $navItem = $importedItems[$itemId];

        // Initialize tree item with ID and WordPress menu item ID
        $treeItem = [
            'id' => $navItem['id'],
            '_wordpress_menu_item_id' => $itemId,
        ];

        // Check if entry exists and is not empty
        if (
            isset($navItem['entry'])
            && $navItem['entry'] !== ''
        ) {
            // Add entry reference to tree item
            $treeItem['entry'] = $navItem['entry'];
        }

        // Check if title exists and is not empty
        if (
            isset($navItem['title'])
            && $navItem['title'] !== ''
        ) {
            // Add title to tree item
            $treeItem['title'] = $navItem['title'];
        }

        // Check if URL exists and is not empty
        if (
            isset($navItem['url'])
            && $navItem['url'] !== ''
        ) {
            // Add URL to tree item
            $treeItem['url'] = $navItem['url'];
        }

        // Add children array at the end
        $treeItem['children'] = [];

        // ---
        // Add children
        // ---

        // Check if item has children in hierarchy map
        if (isset($hierarchyMap[$itemId]['children'])) {
            // Initialize children array
            $children = [];

            // Loop through child IDs to build child tree items
            foreach ($hierarchyMap[$itemId]['children'] as $childId) {
                // Recursively build child tree item
                $childTreeItem = $this->buildTreeItem(
                    $childId,
                    $hierarchyMap,
                    $importedItems
                );

                // Check if child tree item was built successfully
                if ($childTreeItem !== null && $childTreeItem !== []) {
                    // Add child to children array
                    $children[] = $childTreeItem;
                }
            }

            // Sort children by menu order
            usort(
                $children,
                fn (array $a, array $b): int => $this->compareTreeItems(
                    $a,
                    $b,
                    $hierarchyMap
                )
            );

            // Assign sorted children to tree item
            $treeItem['children'] = $children;
        }

        // Return the complete tree item
        return $treeItem;
    }

    /**
     * Create a Statamic navigation item from a WordPress nav_menu_item post.
     *
     * @param  SimpleXMLElement  $item  The nav_menu_item post
     * @param  string  $site  The Statamic site handle
     * @param  OutputInterface  $output  Console output interface
     * @return array{id: string, title?: string, entry?: string, url?: string}|null The created navigation item array or null if failed
     */
    private function createNavigationItem(
        SimpleXMLElement $item,
        string $site,
        OutputInterface $output
    ): ?array {
        // Extract the WordPress post ID for this navigation item
        $itemId = XpathHelper::getStringValue(
            $item,
            'wp:post_id'
        );

        // Decode HTML entities and strip HTML tags from the navigation item title
        $title = HtmlTagStripper::strip(
            html_entity_decode(
                (string) $item->title,
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            )
        );

        // Get the menu item type (custom, post_type, taxonomy, etc.)
        $menuItemType = $this->getMenuItemMeta(
            $item,
            '_menu_item_type'
        );

        // Get the menu item object (page, post, category, etc.)
        $menuItemObject = $this->getMenuItemMeta(
            $item,
            '_menu_item_object'
        );

        // Get the menu item object ID (the ID of the referenced object)
        $menuItemObjectId = $this->getMenuItemMeta(
            $item,
            '_menu_item_object_id'
        );

        // Get the menu item URL (for custom links)
        $menuItemUrl = $this->getMenuItemMeta(
            $item,
            '_menu_item_url'
        );

        // Log the processing of this navigation item with its details
        $output->writeln('Processing navigation item (Title: '.($title).', ID: '.($itemId).', Type: '.($menuItemType).')...');

        // Create navigation item based on type
        switch ($menuItemType) {
            // Handle custom menu items (external links)
            case 'custom':
                // Create a custom navigation item with the provided URL
                return $this->createCustomNavigationItem(
                    $title,
                    $menuItemUrl,
                    $site,
                    $output
                );

                // Handle post type menu items (pages, posts, etc.)
            case 'post_type':
                // Create a post type navigation item referencing the object
                return $this->createPostTypeNavigationItem(
                    $title,
                    $menuItemObject,
                    $menuItemObjectId,
                    $site,
                    $output
                );

                // Handle taxonomy menu items (resolved to the entry the term imported into)
            case 'taxonomy':
                // Create a taxonomy navigation item if it's a kbtopic
                return $this->createTaxonomyNavigationItem(
                    $title,
                    $menuItemObject,
                    $menuItemObjectId,
                    $site,
                    $output
                );

                // Handle unknown menu item types
            default:
                // Log the unknown menu item type for debugging
                $output->writeln("  - <comment>Unknown menu item type '{$menuItemType}' for item</comment>");

                // Return null for unsupported menu item types
                return null;
        }
    }

    /**
     * Create a custom navigation item (URL link).
     *
     * @param  string  $title  The navigation item title
     * @param  string  $url  The custom URL
     * @param  string  $site  The Statamic site handle
     * @param  OutputInterface  $output  Console output interface
     * @return array{id: string, title?: string, entry?: string, url?: string}|null The created navigation item array or null if failed
     */
    private function createCustomNavigationItem(
        string $title,
        string $url,
        string $site,
        OutputInterface $output
    ): ?array {
        // Check if URL is provided
        if ($url === '') {
            // Log that URL is missing and skip item
            $output->writeln('  - <comment>Custom navigation item has no URL (skipping navigation item)</comment>');

            // Return null to indicate failure
            return null;
        }

        // Attempt to create the navigation item
        try {
            // Create navigation item as array structure
            $navItem = [
                'id' => Str::uuid()->toString(),
            ];

            // Check if title is provided
            if ($title !== '') {
                // Add title to navigation item
                $navItem['title'] = $title;
            }

            // Add URL to navigation item ($url is guaranteed non-empty by the guard above)
            $navItem['url'] = $url;

            // Log successful creation
            $output->writeln("  - <info>Created nav item: '{$url}'</info>");

            // Return the created navigation item
            return $navItem;
        } catch (Exception $exception) {
            // Log error message to console
            $output->writeln("  - <error>Failed to create custom navigation item: {$exception->getMessage()}</error>");

            // Log error details for debugging
            Log::error(
                'NavigationMenuImporter::createCustomNavigationItem failed',
                [
                    'title' => $title,
                    'url' => $url,
                    'site' => $site,
                    'error' => $exception->getMessage(),
                ]
            );

            // Return null to indicate failure
            return null;
        }
    }

    /**
     * Create a post type navigation item (entry link).
     *
     * @param  string  $title  The navigation item title
     * @param  string  $postType  The WordPress post type
     * @param  string  $postId  The WordPress post ID
     * @param  string  $site  The Statamic site handle
     * @param  OutputInterface  $output  Console output interface
     * @return array{id: string, title?: string, entry?: string, url?: string}|null The created navigation item array or null if failed
     */
    private function createPostTypeNavigationItem(
        string $title,
        string $postType,
        string $postId,
        string $site,
        OutputInterface $output
    ): ?array {
        // Find the corresponding Statamic entry
        $entry = $this->findStatamicEntryByWordPressId(
            $postId,
            $postType,
            $site
        );

        // Check if entry was found
        if (! $entry instanceof Entry) {
            // Log that entry was not found and skip item
            $output->writeln("  - <comment>Could not find Statamic entry for WordPress {$postType} ID {$postId} (skipping navigation item)</comment>");

            // ---
            // Record the drop in the end-of-run warning summary
            // ---

            // **Reported here rather than where the lookup fails (wordpress-importer-archive#1171).** This frame knows the
            // item's title, its target post and the site; the resolver beneath it knows only an
            // id. A navigation link lost mid-run was previously one line in an 800-line log,
            // while the summary an operator actually reads carried no section for it — and the
            // navigation renders as though it were complete.
            ImportWarningManager::addWarning(
                'content',
                'unresolved_navigation_items',
                [
                    'wordpress_post_id' => $postId,
                    'post_type' => $postType,
                    'title' => $title === '' ? '(untitled)' : $title,
                    'site' => $site,
                    'effect' => 'The navigation item was dropped, so this link is absent from the '
                        .'imported menu. The menu renders without it rather than failing.',
                ]
            );

            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'wordpress_post_id' => $postId,
                    'post_type' => $postType,
                    'title' => $title,
                    'site' => $site,
                ]
            );

            // Log warning for debugging and monitoring
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'NavigationMenuImporter::createPostTypeNavigationItem: Could not find Statamic entry',
                    $logData['page_url']
                ),
                $logData['context']
            );

            // Return null to indicate failure
            return null;
        }

        // Attempt to create the navigation item
        try {
            // Create navigation item as array structure
            $navItem = [
                'id' => Str::uuid()->toString(),
            ];

            // Check if title is provided
            if ($title !== '') {
                // Add title to navigation item
                $navItem['title'] = $title;
            }

            // Add entry reference to navigation item ($entry is guaranteed non-null by the guard above)
            // Normalize the Statamic entry ID to a string value.
            $entryId = $this->normalizeStringIdentifier(
                $entry->id()
            );

            // Skip entries that do not expose a valid string/integer ID.
            if ($entryId === null || $entryId === '') {
                $output->writeln("  - <comment>Entry for WordPress {$postType} ID {$postId} has no usable ID (skipping navigation item)</comment>");

                return null;
            }

            $navItem['entry'] = $entryId;

            // Log successful creation
            $output->writeln("  - <info>Created link to entry: '{$entryId}'</info>");

            // Return the created navigation item
            return $navItem;
        } catch (Exception $exception) {
            // Log error message to console
            $output->writeln("  - <error>Failed to create navigation item: {$exception->getMessage()}</error>");

            // Log error details for debugging
            Log::error(
                'NavigationMenuImporter::createPostTypeNavigationItem failed',
                [
                    'title' => $title,
                    'post_type' => $postType,
                    'post_id' => $postId,
                    'site' => $site,
                    'error' => $exception->getMessage(),
                ]
            );

            // Return null to indicate failure
            return null;
        }
    }

    /**
     * Find Statamic entry by WordPress post ID and type.
     *
     * @param  string  $wordPressId  The WordPress post ID
     * @param  string  $postType  The WordPress post type
     * @param  string  $site  The Statamic site handle
     * @return ?Entry The found entry or null if not found
     */
    private function findStatamicEntryByWordPressId(
        string $wordPressId,
        string $postType,
        string $site
    ): ?Entry {
        // Build cache key from post type, WordPress ID, and site
        $cacheKey = "{$postType}_{$wordPressId}_{$site}";

        // Check if entry is already cached
        if (isset(self::$entryCache[$cacheKey])) {
            // Return cached entry
            return self::$entryCache[$cacheKey];
        }

        // Get centralized collection mappings from ImportWordPress command
        $collectionHandle = ImportWordPress::mapWordPressToStatamicHandle(
            $postType,
            'collection'
        );

        // Check if collection handle was found
        if ($collectionHandle === null || $collectionHandle === '') {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'post_type' => $postType,
                    'wordpress_id' => $wordPressId,
                    'site' => $site,
                ]
            );

            // Log warning for unknown post type
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'NavigationMenuImporter::findStatamicEntryByWordPressId: Unknown post type',
                    $logData['page_url']
                ),
                $logData['context']
            );

            // Return null if collection handle not found
            return null;
        }

        // Use the centralized EntryFinder to find entry by WordPress ID
        $entry = EntryFinder::findEntryByWordPressId(
            $collectionHandle,
            $wordPressId,
            false,
            $site
        );

        // ---
        // Follow a kb_page the knowledge base replaced
        // ---

        // **A MinervaKB home page is deliberately never a `pages` entry (wordpress-importer-archive#1171).** The knowledge
        // base mounts at its URL instead, so the lookup above cannot succeed for it: this method
        // resolves a post type to exactly one collection, `page` maps to `pages`, and the entry
        // that exists is in `knowledge_bases`. Re-ordering the content types does not help.
        //
        // The item is therefore dropped and the site's primary navigation silently loses its
        // knowledge-base link — the ordinary case, since the skip exists precisely so the
        // knowledge base can occupy that URL.
        if (! $entry instanceof Entry) {
            $substituteId = KbPageSubstitutions::resolve($wordPressId, $site);

            if ($substituteId !== null) {
                $substitute = EntryFacade::find($substituteId);

                if ($substitute instanceof Entry) {
                    $entry = $substitute;
                }
            }
        }

        // Cache the entry for future lookups
        if ($entry instanceof Entry) {
            self::$entryCache[$cacheKey] = $entry;
        }

        // Return the found entry
        return $entry instanceof Entry ? $entry : null;
    }

    /**
     * Get menu item meta value from WordPress nav_menu_item post.
     *
     * @param  SimpleXMLElement  $item  The nav_menu_item post
     * @param  string  $metaKey  The meta key to retrieve
     * @return string The meta value or empty string if not found
     */
    private function getMenuItemMeta(
        SimpleXMLElement $item,
        string $metaKey
    ): string {
        // Query XML for postmeta elements matching the meta key
        $metaElements = $item->xpath("wp:postmeta[wp:meta_key='{$metaKey}']/wp:meta_value");

        // Return early when xpath does not produce a valid array result.
        if (! \is_array($metaElements) || ! isset($metaElements[0])) {
            return '';
        }

        // Return the first meta value found.
        return (string) $metaElements[0];
    }

    /**
     * Compare two tree items for sorting.
     *
     * @param  array<string, mixed>  $a  First tree item
     * @param  array<string, mixed>  $b  Second tree item
     * @param  array<string, array{item: SimpleXMLElement, parent_id: string, menu_order: int, children: list<string>}>  $hierarchyMap  The hierarchy map with menu orders
     * @return int Comparison result (-1, 0, or 1)
     */
    private function compareTreeItems(
        array $a,
        array $b,
        array $hierarchyMap
    ): int {
        // ---
        // Find the menu order for each item by looking up their original item IDs
        // ---

        // Get menu order for first item
        $orderA = $this->getMenuOrderForTreeItem(
            $a,
            $hierarchyMap
        );

        // Get menu order for second item
        $orderB = $this->getMenuOrderForTreeItem(
            $b,
            $hierarchyMap
        );

        // Check if menu orders are the same
        if ($orderA === $orderB) {
            // ---
            // If menu orders are the same, sort by title
            // ---

            // Get title from first item, default to empty string
            $titleA = $this->extractStringFromTreeItem(
                $a,
                'title'
            );

            // Get title from second item, default to empty string
            $titleB = $this->extractStringFromTreeItem(
                $b,
                'title'
            );

            // Return string comparison result
            return strcmp(
                $titleA,
                $titleB
            );
        }

        // Return numeric comparison result
        return $orderA - $orderB;
    }

    /**
     * Get the menu order for a tree item by finding its original WordPress item ID.
     *
     * Uses the stored WordPress menu item ID in the tree item to directly look up the
     * menu order from the hierarchy map, avoiding unreliable title/entry matching.
     *
     * @param  array<string, mixed>  $treeItem  The tree item (should contain '_wordpress_menu_item_id')
     * @param  array<string, array{item: SimpleXMLElement, parent_id: string, menu_order: int, children: list<string>}>  $hierarchyMap  The hierarchy map with menu orders
     * @return int The menu order or 0 if not found
     */
    private function getMenuOrderForTreeItem(
        array $treeItem,
        array $hierarchyMap
    ): int {
        // Check if the tree item has the WordPress menu item ID stored
        $wordPressItemId = $this->extractStringFromTreeItem(
            $treeItem,
            '_wordpress_menu_item_id'
        );
        if ($wordPressItemId !== '') {
            // Extract the WordPress menu item ID from the tree item
            // Check if the hierarchy map contains this item
            if (isset($hierarchyMap[$wordPressItemId])) {
                // Return the menu order from the hierarchy map
                return $hierarchyMap[$wordPressItemId]['menu_order'];
            }
        }

        // ---
        // Fallback to title/entry matching for backwards compatibility (though this
        // should rarely be needed)
        // ---

        // Iterate through hierarchy map to find matching item by title and entry
        foreach ($hierarchyMap as $itemData) {
            // Get the original XML item
            $item = $itemData['item'];

            // Decode HTML entities and strip HTML tags from the navigation item title
            $title = HtmlTagStripper::strip(
                html_entity_decode(
                    (string) $item->title,
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                )
            );

            // ---
            // Match by title and entry ID if available
            // ---

            // Get title from tree item, default to empty string
            $treeItemTitle = $this->extractStringFromTreeItem(
                $treeItem,
                'title'
            );

            // Check if titles match
            if ($title === $treeItemTitle) {
                // ---
                // For entries, we need to match by the entry ID
                // ---

                // Check if tree item has an entry
                if (
                    $this->extractStringFromTreeItem(
                        $treeItem,
                        'entry'
                    ) !== ''
                ) {
                    // ---
                    // For entries, we need to match by the entry ID
                    // ---

                    // Get the menu item object ID from WordPress meta
                    $menuItemObjectId = $this->getMenuItemMeta(
                        $item,
                        '_menu_item_object_id'
                    );

                    // Get the menu item object type from WordPress meta
                    $menuItemObject = $this->getMenuItemMeta(
                        $item,
                        '_menu_item_object'
                    );

                    // ---
                    // This is a simplified match - in practice, we might need more
                    // sophisticated matching
                    // ---

                    // Check if both object ID and object type are present
                    if (
                        $menuItemObjectId !== ''
                        && $menuItemObject !== ''
                    ) {
                        // Return the menu order for this item
                        return $itemData['menu_order'];
                    }
                } else { // Otherwise
                    // For custom URLs, match by title and URL
                    $url = $this->getMenuItemMeta(
                        $item,
                        '_menu_item_url'
                    );

                    // Check if URLs match
                    if (
                        $this->extractStringFromTreeItem(
                            $treeItem,
                            'url'
                        ) === $url
                    ) {
                        // Return the menu order for this item
                        return $itemData['menu_order'];
                    }
                }
            }
        }

        // Return default menu order if not found
        return 0;
    }

    /**
     * Create a taxonomy navigation item.
     *
     * Resolves the WordPress `(taxonomy, term_id)` pair through
     * {@see TaxonomyNavigationResolver} and links the Statamic entry that term
     * imported into. The check is on the destination's *shape*, not on the
     * taxonomy's name, so `kbtopic` and `category` — and any other taxonomy that
     * imports into a collection — travel one path.
     *
     * A term with no entry to point at is skipped and **reported**, because a
     * dropped navigation item is invisible in the result: the menu simply comes
     * out one item short, with nothing in the tree to say an item was ever
     * there.
     *
     * @param  string  $title  The navigation item title
     * @param  string  $taxonomy  The WordPress taxonomy name
     * @param  string  $termId  The WordPress term ID
     * @param  string  $site  The Statamic site handle
     * @param  OutputInterface  $output  Console output interface
     * @return array{id: string, title?: string, entry?: string, url?: string}|null The created navigation item array or null if failed
     */
    private function createTaxonomyNavigationItem(
        string $title,
        string $taxonomy,
        string $termId,
        string $site,
        OutputInterface $output
    ): ?array {
        // ---
        // Resolve where this term landed in Statamic, if it landed anywhere.
        //
        // The lookup can reach the content store, so it is wrapped: one menu
        // item failing to resolve must cost that item, not the rest of the menu.
        // ---
        try {
            $destination = $this->taxonomyResolver()->resolve(
                $taxonomy,
                $termId,
                $site
            );
        } catch (Exception $exception) {
            $destination = TaxonomyNavigationDestination::skipped(
                'resolution_failed',
                'resolving the term failed: '.$exception->getMessage()
            );
        }

        $entryId = $destination->entryId;

        // Report and skip when there is no entry a navigation branch can reference.
        if ($entryId === null) {
            $this->reportSkippedTaxonomyItem(
                $title,
                $taxonomy,
                $termId,
                $site,
                $destination,
                $output
            );

            return null;
        }

        // Build the navigation item around the resolved entry
        $navItem = [
            'id' => Str::uuid()->toString(),
        ];

        // ---
        // Carry the WordPress title only when the menu item set one. An empty
        // title is how WordPress says "render the term's own name", and omitting
        // it lets Statamic do the same from the entry rather than labelling the
        // branch with an empty string.
        // ---
        if ($title !== '') {
            // Add the title to the navigation item
            $navItem['title'] = $title;
        }

        // Add the entry reference
        $navItem['entry'] = $entryId;

        // Log successful creation of navigation item
        $output->writeln("  - <info>Created link to entry: '{$entryId}'</info>");

        // Return the created navigation item
        return $navItem;
    }

    /**
     * Report a taxonomy navigation item that could not be linked.
     *
     * Writes to all three channels on purpose. The console line is what the
     * operator watching the import sees; the log entry carries the machine
     * -readable cause for a later audit; and the warning goes in the end-of-run
     * summary, which is the only one of the three still on screen when a
     * multi-thousand-item import finishes.
     *
     * @param  string  $title  The navigation item title
     * @param  string  $taxonomy  The WordPress taxonomy name
     * @param  string  $termId  The WordPress term ID
     * @param  string  $site  The Statamic site handle
     * @param  TaxonomyNavigationDestination  $destination  The unresolved destination carrying the cause
     * @param  OutputInterface  $output  Console output interface
     */
    private function reportSkippedTaxonomyItem(
        string $title,
        string $taxonomy,
        string $termId,
        string $site,
        TaxonomyNavigationDestination $destination,
        OutputInterface $output
    ): void {
        // Name the item by its title where it has one, by its term where it does not.
        $label = $title !== ''
            ? "'{$title}'"
            : "(untitled, {$taxonomy} term {$termId})";

        // Fall back to a generic cause so a reason is never simply absent.
        $reason = $destination->skipReason ?? 'no reason was recorded.';
        $reasonCode = $destination->skipCode ?? 'unknown';

        // Tell the operator watching the import
        $output->writeln("  - <comment>Skipping taxonomy navigation item {$label}: {$reason}</comment>");

        // Enhance log context with entry information
        $logData = LogContextHelper::enhanceWithEntryContext(
            [
                'wordpress_term_id' => $termId,
                'taxonomy' => $taxonomy,
                'title' => $title,
                'site' => $site,
                'reason_code' => $reasonCode,
                'reason' => $reason,
            ]
        );

        // Log warning for debugging and monitoring
        Log::warning(
            LogContextHelper::formatMessageWithEntry(
                'NavigationMenuImporter::createTaxonomyNavigationItem: dropped a taxonomy navigation item',
                $logData['page_url']
            ),
            $logData['context']
        );

        // Surface it in the end-of-run summary, where the operator will see it
        ImportWarningManager::addWarning(
            'navigation',
            'dropped_taxonomy_item',
            [
                'title' => $title !== ''
                    ? $title
                    : '(untitled)',
                'taxonomy' => $taxonomy,
                'term_id' => $termId,
                'site' => $site,
                'reason_code' => $reasonCode,
                'reason' => $reason,
            ]
        );
    }

    /**
     * Return the resolver used for taxonomy navigation items.
     *
     * {@see self::importNavigationMenu()} primes one from the export so the
     * registry miss can fall back to the saved entries. A caller that reaches an
     * item without that priming still gets a working registry-only resolver
     * rather than a fatal.
     *
     * @return TaxonomyNavigationResolver The resolver for this import
     */
    private function taxonomyResolver(): TaxonomyNavigationResolver
    {
        return $this->taxonomyResolver ??= TaxonomyNavigationResolver::registryOnly();
    }

    /**
     * Remove internal fields from tree items before saving.
     *
     * Removes fields that are only used for internal processing (like
     * `_wordpress_menu_item_id`) and should not be saved to the navigation tree.
     *
     * @param  array<int|string, mixed>  $tree  The navigation tree structure
     * @return array<int|string, mixed> The cleaned tree structure
     */
    private function removeInternalFields(array $tree): array
    {
        // Loop through each tree item to remove internal fields
        foreach ($tree as &$item) {
            // Skip malformed tree nodes.
            if (! \is_array($item)) {
                continue;
            }

            // Remove the internal WordPress menu item ID field
            unset($item['_wordpress_menu_item_id']);

            // Check if item has children to process recursively
            if (
                isset($item['children'])
                && \is_array($item['children'])
            ) {
                // Recursively process children to remove internal fields
                $item['children'] = $this->removeInternalFields($item['children']);
            }
        }

        unset($item);

        // Return the cleaned tree structure
        return $tree;
    }

    /**
     * Clear caches to free memory.
     */
    private function clearCaches(): void
    {
        // Clear entry cache
        self::$entryCache = [];

        // Drop the export-derived taxonomy resolver with it
        $this->taxonomyResolver = null;

        // Force garbage collection to free memory
        gc_collect_cycles();
    }

    /**
     * Find items whose parent chain never reaches a top-level item in this menu.
     *
     * @param  array<string, array{item: SimpleXMLElement, parent_id: string, menu_order: int, children: list<string>}>  $hierarchyMap  Hierarchy map with parent-child relationships
     * @return list<string> The WordPress item ids that have no place in the tree
     */
    private function itemsWithUnreachableParents(
        array $hierarchyMap
    ): array {
        $unreachable = [];

        foreach ($hierarchyMap as $itemId => $itemData) {
            $parentId = $itemData['parent_id'];

            // Ancestors already walked, so malformed export data cannot loop.
            $seen = [];

            while ($parentId !== '') {
                if (
                    isset($seen[$parentId])
                    || ! isset($hierarchyMap[$parentId])
                ) {
                    $unreachable[] = $itemId;

                    break;
                }

                $seen[$parentId] = true;
                $parentId = $hierarchyMap[$parentId]['parent_id'];
            }
        }

        return $unreachable;
    }

    /**
     * Normalize a WordPress menu-item parent id to the importer's "no parent" form.
     *
     * WordPress writes `0` for a top-level item; this importer represents that
     * as an empty string, which is what {@see self::importNavigationItems()},
     * {@see self::importChildItems()} and {@see self::buildNavigationTree()}
     * each test for.
     *
     * @param  string  $parentId  The raw `_menu_item_menu_item_parent` value
     * @return string The parent id, or an empty string for a top-level item
     */
    private function normalizeParentId(
        string $parentId
    ): string {
        return $parentId === '0'
            ? ''
            : $parentId;
    }

    /**
     * Normalize mixed identifier values into a string ID.
     *
     * @param  mixed  $identifier  Mixed identifier value
     * @return string|null Normalized identifier or null for unsupported types
     */
    private function normalizeStringIdentifier(mixed $identifier): ?string
    {
        // Return identifiers that are already strings.
        if (\is_string($identifier)) {
            return $identifier;
        }

        // Convert integer identifiers into strings.
        if (\is_int($identifier)) {
            return (string) $identifier;
        }

        // Return null for unsupported identifier types.
        return null;
    }

    /**
     * Extract a string value from a tree item array key.
     *
     * @param  array<string, mixed>  $treeItem  Tree item data
     * @param  string  $key  Tree item key to read
     * @return string Normalized string value or empty string when unavailable
     */
    private function extractStringFromTreeItem(
        array $treeItem,
        string $key
    ): string {
        // Read the raw value from the tree item.
        $value = $treeItem[$key] ?? null;

        // Return string values directly.
        if (\is_string($value)) {
            return $value;
        }

        // Convert integer values into strings for comparisons.
        if (\is_int($value)) {
            return (string) $value;
        }

        // Return empty string when the key is missing or unsupported.
        return '';
    }
}
