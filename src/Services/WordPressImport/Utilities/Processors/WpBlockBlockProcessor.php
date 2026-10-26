<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors;

use DOMNode;
use Illuminate\Support\Facades\Log;
use Statamic\Entries\Entry;
use Statamic\Fieldtypes\RowId;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Tiptap\SetMarkupNonce;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\EntryQueryHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\WpBlockRegistry;

/**
 * Processes WordPress wp:block blocks into Statamic reusable content
 * relationships.
 *
 * Handles wp:block blocks (reusable blocks) by looking up the corresponding
 * Statamic entry ID from the wp_block post ID and creating either a Bard set
 * (for basic_page blueprint) or a replicator set (for landing_page blueprint).
 */
class WpBlockBlockProcessor implements BlockProcessorInterface
{
    /**
     * Cache of extracted wp_block post IDs for import triggering.
     *
     * Stores all wp_block post IDs in order of appearance, including
     * duplicates, to ensure correct import of referenced reusable blocks.
     *
     * @var string[]
     */
    protected static array $wpBlockPostIds = [];

    /**
     * Map of entry IDs to their deferred wp_block references for later processing.
     *
     * Structure: [
     *   'entry_id' => [
     *     [
     *       'wp_block_post_id' => '123',
     *       'collection_handle' => 'pages',
     *       'site' => 'default',
     *       'blueprint' => 'basic_page' | 'landing_page',
     *     ],
     *     ...
     *   ],
     *   ...
     * ]
     *
     * @var array<string, array<int, array<string, string>>>
     */
    protected static array $deferredWpBlockReferences = [];

    /**
     * Determines if the processor can handle the given DOM node.
     *
     * @param  DOMNode  $node  The DOM node to check
     * @param  string  $site  The site handle
     * @param  ?string  $pageSlug  The page slug (optional)
     * @return bool True if the node is a wp:block comment, false otherwise
     */
    public function canProcess(
        DOMNode $node,
        string $site,
        ?string $pageSlug
    ): bool {
        // Check if the node is a comment node
        if ($node->nodeType !== XML_COMMENT_NODE) {
            // Return false for non-comment nodes
            return false;
        }

        // Define regex pattern to match wp:block blocks with JSON attributes
        $pattern = '/\s*wp:block\s*({.+?})\s*(?:\/)?$/s';

        // Check if the comment content matches the wp:block pattern
        return \preg_match(
            $pattern,
            $node->nodeValue ?? ''
        ) === 1;
    }

    /**
     * Processes a wp:block block into a Bard set or replicator set.
     *
     * Looks up the Statamic entry ID for the wp_block post ID and creates
     * either a Bard set (for basic_page blueprint) or a replicator set (for
     * landing_page blueprint) with a relationship to the reusable content entry.
     *
     * @param  DOMNode  $node  The DOM node (wp:block comment)
     * @param  array<int|string, mixed>  $attributes  Block attributes from the wp:block comment
     * @param  string  $site  Site handle for asset paths
     * @param  AssetImporter  $assetImporter  Instance for importing files (unused)
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects (unused)
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects from XML (unused)
     * @param  ?string  $pageSlug  The page slug (optional)
     * @return array<int|string, mixed>|string|null Array with 'html', 'skipCount', and 'entryId' keys, Tiptap HTML string, or null if invalid
     */
    public function process(
        DOMNode $node,
        array $attributes,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = [],
        array $terms = [],
        ?string $pageSlug = null
    ): string|array|null {
        // Get the wp_block post ID from attributes
        $wpBlockPostId = self::extractScalarString(
            $attributes['ref'] ?? null
        );

        // ---
        // Track wp_block post ID for import triggering
        // ---

        // Check if wp_block post ID is available
        if ($wpBlockPostId !== '') {
            // Add wp_block post ID to tracking cache
            static::addWpBlockPostId($wpBlockPostId);
        }

        // Check if wp_block post ID is available
        if ($wpBlockPostId === '') {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'pageSlug' => $pageSlug ?? 'none',
                    'attributes' => $attributes,
                ]
            );

            // Log warning about missing ref attribute
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'WpBlockBlockProcessor::process: No ref attribute found in wp:block block',
                    $logData['page_url']
                ),
                $logData['context']
            );

            // Return null if no ref attribute available
            return null;
        }

        // Look up the Statamic entry ID for this wp_block post ID
        $entryId = WpBlockRegistry::getEntryId($wpBlockPostId);

        // Check if entry ID was found
        if ($entryId === null || $entryId === '') {
            // ---
            // Store for deferred processing if entry ID not found
            // ---

            // Get the current Statamic entry ID from processing context
            $statamicEntryId = ProcessingContext::getStatamicEntryId();

            // Get the current collection handle from processing context
            $collectionHandle = ProcessingContext::getCollectionHandle();

            // Check if we have context to store deferred reference
            if (
                $statamicEntryId !== null && $statamicEntryId !== ''
                && $collectionHandle !== null
                && $collectionHandle !== ''
            ) {
                // Store deferred wp_block reference for later processing
                static::storeDeferredWpBlockReference(
                    $statamicEntryId,
                    $wpBlockPostId,
                    $collectionHandle,
                    $site
                );
            }

            // ---
            // Return placeholder Bard set with import_wordpress_notes (similar to forms).
            // This ensures content/page_builder fields are created even when wp_block
            // entry is not yet available. The placeholder will be updated during deferred
            // processing.
            // ---
            $html = \sprintf(
                '<div data-set-type="reusable_content"%s data-set-attr-import_wordpress_notes="wp_block post ID %s"></div>',
                SetMarkupNonce::attribute(),
                htmlspecialchars($wpBlockPostId)
            );

            // ---
            // Return placeholder result with wpBlockPostId for placeholder replicator set
            // creation
            // ---
            return [
                'html' => $html,
                'skipCount' => 1, // Skip the comment node
                'entryId' => null, // No entry ID yet
                'wpBlockPostId' => $wpBlockPostId, // Store wp_block post ID for placeholder replicator set
            ];
        }

        // ---
        // Return Bard set format (HTML with data-set-type). This will be used by
        // TextProcessor for basic_page blueprint.
        // ---
        $html = \sprintf(
            '<div data-set-type="reusable_content"%s data-set-attr-content="%s"></div>',
            SetMarkupNonce::attribute(),
            htmlspecialchars($entryId)
        );

        // ---
        // Return processing result with HTML for Bard sets. The processor will also need
        // to support replicator sets for landing_page blueprint, which will be handled
        // separately.
        // ---
        return [
            'html' => $html,
            'skipCount' => 1, // Skip the comment node
            'entryId' => $entryId, // Store entry ID for replicator set creation
        ];
    }

    /**
     * Creates a replicator set for landing_page blueprint.
     *
     * This method is called by PageBuilderParser to create a replicator set instead
     * of a Bard set when processing content for landing_page blueprint.
     *
     * @param  string  $entryId  The Statamic entry ID for the reusable content
     * @return array<int|string, mixed> Replicator set array
     */
    public function createReplicatorSet(
        string $entryId
    ): array {
        // ---
        // Create replicator set array structure with generated row ID, block type, entry
        // ID in content array, and enabled flag
        // ---
        return [
            'id' => (new RowId)->generate(),
            'type' => 'uamsweb_block_reusable_content_landing_page',
            'content' => [$entryId],
            'enabled' => true,
        ];
    }

    /**
     * Creates a placeholder replicator set for landing_page blueprint when wp_block
     * entry ID is not yet available.
     *
     * This method is called by PageBuilderParser to create a placeholder replicator
     * set with import_wordpress_notes when the wp_block entry hasn't been imported
     * yet. Similar to how forms create placeholders with form IDs.
     *
     * @param  string  $wpBlockPostId  The WordPress wp_block post ID (ref value)
     * @return array<int|string, mixed> Placeholder replicator set array
     */
    public function createPlaceholderReplicatorSet(
        string $wpBlockPostId
    ): array {
        // ---
        // Create placeholder replicator set array structure with generated row ID,
        // block type, import_wordpress_notes instead of content array, and enabled
        // flag
        // ---
        return [
            'id' => (new RowId)->generate(),
            'type' => 'uamsweb_block_reusable_content_landing_page',
            'import_wordpress_notes' => "wp_block post ID {$wpBlockPostId}",
            'enabled' => true,
        ];
    }

    /**
     * Adds a wp_block post ID to the cache for import triggering.
     *
     * Appends the provided wp_block post ID to the static cache, allowing
     * duplicates to preserve the order of wp_block references in the content.
     *
     * @param  string  $wpBlockPostId  The wp_block post ID to add
     */
    public static function addWpBlockPostId(
        string $wpBlockPostId
    ): void {
        // ---
        // Only process non-empty wp_block post IDs
        // ---

        // Check if wp_block post ID is not empty
        if ($wpBlockPostId !== '') {
            // Add wp_block post ID to global cache for import triggering
            static::$wpBlockPostIds[] = $wpBlockPostId;
        }
    }

    /**
     * Gets all tracked wp_block post IDs.
     *
     * Returns the array of wp_block post IDs that have been encountered
     * during content processing, preserving order and duplicates.
     *
     * @return string[] Array of wp_block post IDs
     */
    public static function getWpBlockPostIds(): array
    {
        // Return all tracked wp_block post IDs
        return static::$wpBlockPostIds;
    }

    /**
     * Clears all tracked wp_block post IDs.
     *
     * Resets the static cache of wp_block post IDs, typically called
     * at the start of a new import cycle.
     */
    public static function clearWpBlockPostIds(): void
    {
        // Clear all tracked wp_block post IDs
        static::$wpBlockPostIds = [];
    }

    /**
     * Extracts wp_block post IDs from wp:block block references in raw content.
     *
     * Searches for wp:block comment blocks in raw encoded content and extracts
     * the 'ref' attribute value which contains the wp_block post ID. Uses the
     * same pattern matching logic as canProcess() but adapted for raw content
     * strings that include XML comment markers.
     *
     * @param  string  $contentEncoded  The encoded content to search
     * @return array<int|string, mixed> Array of unique wp_block post IDs found in content
     */
    public static function extractWpBlockPostIdsFromContent(
        string $contentEncoded
    ): array {
        // Initialize array to store wp_block post IDs
        $wpBlockPostIds = [];

        // Pattern to match wp:block blocks with JSON attributes in XML comments
        // Uses same core pattern as canProcess() but includes XML comment markers
        $pattern = '/<!--\s*wp:block\s*({.+?})\s*(?:\/)?-->/s';

        // Search for wp:block blocks in content
        if (
            \preg_match_all(
                $pattern,
                $contentEncoded,
                $matches,
                PREG_SET_ORDER
            ) > 0
        ) {
            // Extract wp_block post IDs from matches
            foreach ($matches as $match) {
                // Decode JSON attributes to extract ref value (same approach as process())
                $decodedAttributes = \json_decode(
                    $match[1],
                    true,
                    512
                );
                $attributes = \is_array($decodedAttributes) ? $decodedAttributes : [];

                // Check if ref attribute exists (same extraction as process())
                if (isset($attributes['ref'])) {
                    $ref = self::extractScalarString($attributes['ref']);

                    if ($ref === '') {
                        continue;
                    }

                    // Add wp_block post ID to array (convert to string)
                    $wpBlockPostIds[] = $ref;
                }
            }
        }

        // Return array of unique wp_block post IDs
        return \array_unique($wpBlockPostIds);
    }

    /**
     * Stores a deferred wp_block reference for later processing.
     *
     * When a wp_block entry ID is not found during content processing,
     * this method stores the reference so it can be resolved after all
     * wp_blocks have been imported.
     *
     * @param  string  $statamicEntryId  The Statamic entry ID that references the wp_block
     * @param  string  $wpBlockPostId  The WordPress wp_block post ID
     * @param  string  $collectionHandle  The collection handle
     * @param  string  $site  The site handle
     */
    protected static function storeDeferredWpBlockReference(
        string $statamicEntryId,
        string $wpBlockPostId,
        string $collectionHandle,
        string $site
    ): void {
        static::$deferredWpBlockReferences[$statamicEntryId] ??= [];

        // Add the deferred wp_block reference
        static::$deferredWpBlockReferences[$statamicEntryId][] = [
            'wp_block_post_id' => $wpBlockPostId,
            'collection_handle' => $collectionHandle,
            'site' => $site,
        ];
    }

    /**
     * Processes all deferred wp_block references after wp_blocks have been imported.
     *
     * Updates placeholder Bard sets and replicator sets that were created during
     * initial content processing to use actual reusable content entry IDs. This
     * is similar to how forms update placeholders - no content re-processing is
     * needed since placeholders are already in place.
     *
     * @param  string  $site  The site handle
     * @param  OutputInterface  $output  Console output for user feedback
     */
    public static function processDeferredWpBlockReferences(
        string $site,
        OutputInterface $output
    ): void {
        // ---
        // Update placeholder Bard sets and replicator sets in all entries to use actual
        // entry IDs (similar to how forms update placeholders)
        // ---
        static::updateBardSetsWithEntryIds(
            $site,
            $output
        );

        // Clear deferred wp_block references after processing
        static::$deferredWpBlockReferences = [];
    }

    /**
     * Gets all deferred wp_block references.
     *
     * Returns the map of entry IDs to their deferred wp_block references.
     * Used for unified deferred processing.
     *
     * @return array<int|string, mixed> Map of entry IDs to deferred reference arrays
     */
    public static function getDeferredWpBlockReferences(): array
    {
        // Return all deferred wp_block references
        return static::$deferredWpBlockReferences;
    }

    /**
     * Clears all deferred wp_block references.
     *
     * Resets the static cache of deferred wp_block references, typically called at
     * the start of a new import cycle.
     */
    public static function clearDeferredWpBlockReferences(): void
    {
        // Clear all deferred wp_block references
        static::$deferredWpBlockReferences = [];
    }

    /**
     * Update Bard sets in entries to use actual entry IDs instead of
     * import_wordpress_notes placeholders.
     *
     * This method should be called after wp_blocks are imported to update any
     * Bard sets that were created with import_wordpress_notes placeholders to
     * use the actual reusable content entry IDs. Similar to how forms update
     * placeholders.
     *
     * @param  string  $siteHandle  The Statamic site handle
     * @param  OutputInterface  $output  Console output interface
     */
    public static function updateBardSetsWithEntryIds(
        string $siteHandle,
        OutputInterface $output
    ): void {
        // ---
        // Get all wp_block post IDs that have been imported
        // ---

        // Get all wp_block mappings from registry
        $wpBlockMappings = WpBlockRegistry::getAllMappings();

        // Extract wp_block post IDs from mappings
        $wpBlockPostIds = \array_keys($wpBlockMappings);

        // Check if any wp_blocks were imported
        if ($wpBlockPostIds === []) {
            // Exit early if no wp_blocks were imported
            return;
        }

        // Log the start of placeholder updates (substage of deferred wp_block processing)
        $output->writeln('  - Updating wp_block placeholder Bard sets and replicator sets...');

        // Get all collections that might contain entries with wp_block Bard sets
        $collections = [
            'pages',
            'post',
            'people',
        ];

        // Initialize counter for total updated entries
        $totalUpdatedCount = 0;

        // Loop through each collection to update entries
        foreach ($collections as $collectionHandle) {
            // Query entries in this collection for the site
            $query = EntryQueryHelper::query();
            $query->where(
                'collection',
                $collectionHandle
            );
            $query->where(
                'site',
                $siteHandle
            );
            $entries = $query->get();

            // Initialize counter for entries updated in this collection
            $collectionUpdatedCount = 0;

            // Skip collection when query result is not iterable
            if (! \is_iterable($entries)) {
                continue;
            }

            // Loop through each entry in the collection
            foreach ($entries as $entry) {
                // Skip non-entry items returned by query results
                if (! $entry instanceof Entry) {
                    continue;
                }

                // Get entry data value object
                $entryData = $entry->data();

                // Skip entries with non-serializable data payloads
                if (
                    ! \is_object($entryData)
                    || ! \method_exists($entryData, 'toArray')
                ) {
                    continue;
                }

                // Get entry data as array
                $data = $entryData->toArray();

                // Skip entries whose data cannot be normalized to array
                if (! \is_array($data)) {
                    continue;
                }

                // Initialize flag to track if entry was updated
                $updated = false;

                // Check content field for Bard sets
                if (
                    isset($data['content'])
                    && \is_array($data['content'])
                ) {
                    // Update Bard sets in content field
                    $updatedContent = static::updateBardSetsInArray(
                        $data['content'],
                        $wpBlockPostIds
                    );

                    // Check if content was updated
                    if ($updatedContent !== $data['content']) {
                        // Update content field with updated Bard sets
                        $data['content'] = $updatedContent;

                        // Mark entry as updated
                        $updated = true;
                    }
                }

                // Check page_builder field for Bard sets and replicator sets
                if (
                    isset($data['page_builder'])
                    && \is_array($data['page_builder'])
                ) {
                    // Update Bard sets and replicator sets in page_builder field
                    $updatedPageBuilder = static::updateBardSetsInArray(
                        $data['page_builder'],
                        $wpBlockPostIds
                    );

                    // Check if page_builder was updated
                    if ($updatedPageBuilder !== $data['page_builder']) {
                        // Update page_builder field with updated sets
                        $data['page_builder'] = $updatedPageBuilder;

                        // Mark entry as updated
                        $updated = true;
                    }
                }

                // ---
                // Save the entry if it was updated
                // ---

                // Check if entry was updated
                if ($updated) {
                    // Reset initialPath to null so buildPath() is called instead
                    $entry->initialPath(null);

                    // Save entry with updated data
                    $entry->data($data);
                    $entry->save();

                    // Increment collection updated count
                    $collectionUpdatedCount++;
                }
            }

            // Check if any entries were updated in this collection
            if ($collectionUpdatedCount > 0) {
                // Inform user of collection update count
                $output->writeln(
                    "  - <info>Updated $collectionUpdatedCount ".($collectionUpdatedCount === 1 ? 'entry' : 'entries')." in '$collectionHandle' collection.</info>"
                );
            }

            // Add collection updated count to total
            $totalUpdatedCount += $collectionUpdatedCount;
        }

        // Check if any entries were updated across all collections
        if ($totalUpdatedCount > 0) {
            // Inform user of total update count
            $output->writeln(
                "Updated $totalUpdatedCount total ".($totalUpdatedCount === 1 ? 'entry' : 'entries').' with wp_block placeholder Bard sets and replicator sets.'
            );
        }
    }

    /**
     * Recursively update Bard sets and replicator sets in an array to replace
     * import_wordpress_notes with actual entry ID.
     *
     * Handles both Bard sets (in content field) and replicator sets (in
     * page_builder field) that contain placeholder wp_block references.
     *
     * @param  array<int|string, mixed>  $array  The array to search and update
     * @param  array<int|string, mixed>  $wpBlockPostIds  Array of wp_block post IDs that have been imported
     * @return array<int|string, mixed> The updated array
     */
    public static function updateBardSetsInArray(
        array $array,
        array $wpBlockPostIds
    ): array {
        // Initialize flag to track if array was updated
        $updated = false;

        // Initialize result array
        $result = [];

        // Loop through each key-value pair in the array
        foreach ($array as $key => $value) {
            // Check if value is an array
            if (\is_array($value)) {
                // ---
                // Check if this is a Bard set with reusable_content type and
                // import_wordpress_notes
                // ---
                if (
                    isset($value['type'])
                    && $value['type'] === 'set'
                    && isset($value['attrs'])
                    && \is_array($value['attrs'])
                    && isset($value['attrs']['values'])
                    && \is_array($value['attrs']['values'])
                    && isset($value['attrs']['values']['type'])
                    && $value['attrs']['values']['type'] === 'reusable_content'
                    && isset($value['attrs']['values']['import_wordpress_notes'])
                ) {
                    // Extract typed attrs and values for safe nested access
                    $attrs = $value['attrs'];
                    $values = $attrs['values'];

                    // Extract wp_block post ID from import_wordpress_notes
                    $importNote = self::extractScalarString(
                        $values['import_wordpress_notes']
                    );

                    // Initialize wp_block post ID variable
                    $wpBlockPostId = null;

                    // ---
                    // Check if import_wordpress_notes matches the pattern
                    // "wp_block post ID X"
                    // ---
                    if (
                        $importNote !== ''
                        &&
                        \preg_match(
                            '/wp_block post ID (\d+)/',
                            $importNote,
                            $matches
                        ) === 1
                    ) {
                        // Extract wp_block post ID from regex match
                        $wpBlockPostId = $matches[1];
                    }

                    // Check if we have an entry ID for this wp_block post ID
                    if ($wpBlockPostId !== null) {
                        // Get entry ID for wp_block post ID from registry
                        $entryId = WpBlockRegistry::getEntryId($wpBlockPostId);

                        // ---
                        // Update the Bard set to use entry ID instead of import_wordpress_notes
                        // ---

                        // Check if entry ID was found
                        if ($entryId !== null && $entryId !== '') {
                            // Get values array from Bard set
                            $updatedValues = $values;

                            // Remove import_wordpress_notes from values
                            unset($updatedValues['import_wordpress_notes']);

                            // Set content to entry ID
                            $updatedValues['content'] = $entryId;

                            // Create updated Bard set with entry ID
                            $result[$key] = [
                                'type' => 'set',
                                'attrs' => [
                                    'id' => $attrs['id'] ?? (new RowId)->generate(),
                                    'values' => $updatedValues,
                                ],
                            ];

                            // Mark array as updated
                            $updated = true;
                        } else { // Otherwise
                            // Keep the placeholder if entry ID not found
                            $result[$key] = $value;
                        }
                    } else { // Otherwise
                        // Keep the value if pattern doesn't match
                        $result[$key] = $value;
                    }
                } elseif (
                    isset($value['type'])
                    && $value['type'] === 'uamsweb_block_reusable_content_landing_page'
                    && isset($value['import_wordpress_notes'])
                ) {
                    // ---
                    // Check if this is a replicator set with reusable_content type and
                    // import_wordpress_notes
                    // ---

                    // Extract wp_block post ID from import_wordpress_notes
                    $importNote = self::extractScalarString(
                        $value['import_wordpress_notes']
                    );

                    // Initialize wp_block post ID variable
                    $wpBlockPostId = null;

                    // Check if import_wordpress_notes matches the pattern "wp_block post ID X"
                    if (
                        $importNote !== ''
                        &&
                        \preg_match(
                            '/wp_block post ID (\d+)/',
                            $importNote,
                            $matches
                        ) === 1
                    ) {
                        // Extract wp_block post ID from regex match
                        $wpBlockPostId = $matches[1];
                    }

                    // Check if we have an entry ID for this wp_block post ID
                    if ($wpBlockPostId !== null) {
                        // Get entry ID for wp_block post ID from registry
                        $entryId = WpBlockRegistry::getEntryId($wpBlockPostId);

                        // ---
                        // Update the replicator set to use content array instead of import_wordpress_notes
                        // ---

                        // Check if entry ID was found
                        if ($entryId !== null && $entryId !== '') {
                            // Copy replicator set into a mutable array
                            $replicatorValue = $value;

                            // Remove import_wordpress_notes from replicator set
                            unset($replicatorValue['import_wordpress_notes']);

                            // Set content array with entry ID
                            $replicatorValue['content'] = [$entryId];

                            // Store updated replicator set
                            $result[$key] = $replicatorValue;

                            // Mark array as updated
                            $updated = true;
                        } else { // Otherwise
                            // Keep the placeholder if entry ID not found
                            $result[$key] = $value;
                        }
                    } else { // Otherwise
                        // Keep the value if pattern doesn't match
                        $result[$key] = $value;
                    }
                } elseif (
                    isset($value['type'])
                    && $value['type'] === 'uamsweb_block_reusable_content_landing_page'
                    && isset($value['import_wordpress_notes'])
                ) {
                    // ---
                    // Check if this is a replicator set with reusable_content type and
                    // import_wordpress_notes
                    // ---

                    // Extract wp_block post ID from import_wordpress_notes
                    $importNote = self::extractScalarString(
                        $value['import_wordpress_notes']
                    );

                    // Initialize wp_block post ID variable
                    $wpBlockPostId = null;

                    // Check if import_wordpress_notes matches the pattern "wp_block post ID X"
                    if (
                        $importNote !== ''
                        &&
                        \preg_match(
                            '/wp_block post ID (\d+)/',
                            $importNote,
                            $matches
                        ) === 1
                    ) {
                        // Extract wp_block post ID from regex match
                        $wpBlockPostId = $matches[1];
                    }

                    // Check if we have an entry ID for this wp_block post ID
                    if ($wpBlockPostId !== null) {
                        // Get entry ID for wp_block post ID from registry
                        $entryId = WpBlockRegistry::getEntryId($wpBlockPostId);

                        // ---
                        // Update the replicator set to use content array instead of import_wordpress_notes
                        // ---

                        // Check if entry ID was found
                        if ($entryId !== null && $entryId !== '') {
                            // Copy replicator set into a mutable array
                            $replicatorValue = $value;

                            // Remove import_wordpress_notes from replicator set
                            unset($replicatorValue['import_wordpress_notes']);

                            // Set content array with entry ID
                            $replicatorValue['content'] = [$entryId];

                            // Store updated replicator set
                            $result[$key] = $replicatorValue;

                            // Mark array as updated
                            $updated = true;
                        } else { // Otherwise
                            // Keep the placeholder if entry ID not found
                            $result[$key] = $value;
                        }
                    } else { // Otherwise
                        // Keep the value if pattern doesn't match
                        $result[$key] = $value;
                    }
                } else { // Otherwise
                    // Recursively update nested arrays
                    $nestedResult = static::updateBardSetsInArray(
                        $value,
                        $wpBlockPostIds
                    );

                    // Check if nested array was updated
                    if ($nestedResult !== $value) {
                        // Use updated nested result
                        $result[$key] = $nestedResult;

                        // Mark array as updated
                        $updated = true;
                    } else { // Otherwise
                        // Keep original value if not updated
                        $result[$key] = $value;
                    }
                }
            } else { // Otherwise
                // Keep non-array values as-is
                $result[$key] = $value;
            }
        }

        // Return updated array if changes were made, otherwise return original
        return $updated ? $result : $array;
    }

    /**
     * Normalize scalar values into strings.
     *
     * @param  mixed  $value  Raw value to normalize
     * @return string Normalized scalar string or empty string
     */
    private static function extractScalarString(
        mixed $value
    ): string {
        // Return string values directly
        if (\is_string($value)) {
            return $value;
        }

        // Convert scalar values to strings
        if (\is_scalar($value)) {
            return (string) $value;
        }

        // Return empty string for non-scalar values
        return '';
    }
}
