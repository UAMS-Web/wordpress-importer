<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\DeferredProcessing;

use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Statamic\Entries\Entry;
use Statamic\Facades\Stache as StacheFacade;
use Statamic\Stache\Stores\Store;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormPluginCoordinator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\EntryFinder;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\TablePressShortcodeProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\WpBlockBlockProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\WpBlockRegistry;

/**
 * Coordinates deferred processing operations after content import.
 *
 * Handles re-processing of content to resolve deferred references such as
 * table links, wp_block references, form handles, and internal links. Manages
 * both per-content-type deferred processing and final deferred processing
 * after all content types are imported.
 *
 * Follows the Single Responsibility Principle by focusing solely on
 * coordinating deferred processing operations.
 */
class DeferredProcessingCoordinator
{
    /**
     * Content processor instance.
     */
    private ?ContentProcessor $contentProcessor = null;

    /**
     * Form plugin coordinator instance.
     */
    private ?FormPluginCoordinator $formPluginCoordinator = null;

    /**
     * XML parser instance.
     */
    private ?XmlParser $xmlParser = null;

    /**
     * Asset importer instance.
     */
    private ?AssetImporter $assetImporter = null;

    /**
     * Page data mapping (WordPress post ID to Statamic entry data).
     *
     * @var array<int|string, mixed>|null
     */
    private ?array $pageData = null;

    /**
     * Constructs the DeferredProcessingCoordinator.
     */
    public function __construct()
    {
        // Initialize with no dependencies (set via setters)
    }

    /**
     * Sets the content processor instance.
     *
     * @param  ContentProcessor  $contentProcessor  The content processor instance
     */
    public function setContentProcessor(
        ContentProcessor $contentProcessor
    ): void {
        // Store content processor instance
        $this->contentProcessor = $contentProcessor;
    }

    /**
     * Sets the form plugin coordinator instance.
     *
     * @param  FormPluginCoordinator  $formPluginCoordinator  The form plugin coordinator instance
     */
    public function setFormPluginCoordinator(
        FormPluginCoordinator $formPluginCoordinator
    ): void {
        // Store form plugin coordinator instance
        $this->formPluginCoordinator = $formPluginCoordinator;
    }

    /**
     * Sets the XML parser instance.
     *
     * @param  XmlParser  $xmlParser  The XML parser instance
     */
    public function setXmlParser(
        XmlParser $xmlParser
    ): void {
        // Store XML parser instance
        $this->xmlParser = $xmlParser;
    }

    /**
     * Sets the asset importer instance.
     *
     * @param  AssetImporter  $assetImporter  The asset importer instance
     */
    public function setAssetImporter(
        AssetImporter $assetImporter
    ): void {
        // Store asset importer instance
        $this->assetImporter = $assetImporter;
    }

    /**
     * Sets the page data mapping.
     *
     * @param  array<int|string, mixed>  $pageData  Page data mapping (WordPress post ID to Statamic entry data)
     */
    public function setPageData(
        array $pageData
    ): void {
        // Store page data mapping
        $this->pageData = $pageData;
    }

    /**
     * Processes deferred operations for a content type.
     *
     * Handles table linking, wp_block references, and form processing after
     * the initial content import for a specific content type. This ensures
     * that deferred references can be resolved once the referenced items
     * (tables, wp_blocks, forms) are available.
     *
     * @param  string  $site  The site handle
     * @param  string  $collectionHandle  The collection handle
     * @param  OutputInterface  $output  Console output interface
     *
     * @throws Exception If required dependencies are not set
     */
    public function processPerContentType(
        string $site,
        string $collectionHandle,
        OutputInterface $output
    ): void {
        // ---
        // Ensure dependencies are set
        // ---

        // Check if required dependencies are missing
        if (
            ! $this->contentProcessor instanceof ContentProcessor
            || ! $this->formPluginCoordinator instanceof FormPluginCoordinator
            || ! $this->xmlParser instanceof XmlParser
            || ! $this->assetImporter instanceof AssetImporter
            || $this->pageData === null
        ) {
            // Throw exception if dependencies are missing
            throw new Exception(
                'Required dependencies not set for deferred processing.'
            );
        }

        // Hold the checked dependencies, which each step below is handed
        $contentProcessor = $this->contentProcessor;
        $formPluginCoordinator = $this->formPluginCoordinator;
        $xmlParser = $this->xmlParser;
        $assetImporter = $this->assetImporter;
        $pageData = $this->pageData;

        // ---
        // Process form imports
        // ---

        // Trigger Statamic form creation for all referenced Gravity Forms and
        // Formidable Forms after Bard content is parsed
        $formPluginCoordinator->processFormImports(
            $site,
            $output,
            $assetImporter,
            $pageData
        );

        // ---
        // Process deferred actions sequentially (Option 1 approach)
        // ---

        // 1. Process table updates
        $this->processTableUpdates(
            $site,
            $collectionHandle,
            $output,
            $contentProcessor,
            $xmlParser,
            $pageData
        );

        // 2. Process wp_block updates
        $this->processWpBlockUpdates(
            $site,
            $collectionHandle,
            $output,
            $pageData
        );

        // 3. Process form updates
        $this->processFormUpdates(
            $site,
            $output,
            $formPluginCoordinator
        );

        // 4. Process internal link updates
        $this->processInternalLinkUpdates(
            $site,
            $collectionHandle,
            $output,
            $assetImporter,
            $pageData
        );
    }

    /**
     * Processes final deferred operations after all content types are imported.
     *
     * Handles deferred internal links that couldn't be resolved during
     * individual content type imports. This includes cross-post-type links
     * that require all entries to be imported before resolution.
     *
     * @param  string  $site  The site handle
     * @param  OutputInterface  $output  Console output interface
     */
    public function processFinal(
        string $site,
        OutputInterface $output
    ): void {
        // ---
        // Process any remaining deferred internal links after all content types
        // are imported. This handles cross-post-type links that couldn't be
        // resolved during individual post type imports.
        // ---
        LinkProcessor::processDeferredInternalLinks(
            $site,
            $output,
            true // This is the final call after all content types are imported
        );

        // ---
        // Apply any deferred knowledge base mounts now that every collection has
        // been imported and the parent `pages` entries are guaranteed to exist if
        // they were imported at all (see wordpress-importer-archive#297).
        // ---
        DeferredKnowledgeBaseMounts::resolve(
            $site,
            $output
        );

        // Clear deferred processing data after all imports are complete
        LinkProcessor::clearDeferredData();
    }

    /**
     * Processes table updates for all entries that need them.
     *
     * Links table entries in content and page_builder fields by replacing
     * TablePress shortcodes with actual table entry references.
     *
     * @param  string  $site  The site handle
     * @param  string  $collectionHandle  The collection handle
     * @param  OutputInterface  $output  Console output interface
     * @param  ContentProcessor  $contentProcessor  The content processor that links the tables
     * @param  XmlParser  $xmlParser  The XML parser holding the export's tables
     * @param  array<int|string, mixed>  $pageData  Page data mapping (WordPress post ID to Statamic entry data)
     */
    private function processTableUpdates(
        string $site,
        string $collectionHandle,
        OutputInterface $output,
        ContentProcessor $contentProcessor,
        XmlParser $xmlParser,
        array $pageData
    ): void {
        // ---
        // Collect entries that need table updates
        // ---

        // Cache table IDs before processing
        $cachedTableIds = TablePressShortcodeProcessor::getTableIds();

        // Check if table updates are needed
        if ($cachedTableIds === []) {
            // No table updates needed, return early
            return;
        }

        // Inform user that table updates are starting
        $output->writeln('Processing table updates...');

        // Build map of entry IDs to table IDs
        $tableUpdatesByEntryId = [];

        // Loop through pageData to map post IDs to entry IDs for table linking
        foreach ($pageData as $postId => $page) {
            // Skip malformed page records
            if (! is_array($page)) {
                continue;
            }

            // Resolve the Statamic entry ID for this page record
            $entryId = self::entryIdAsString($page['entry'] ?? null);

            // Check if entry is missing from page data
            if ($entryId === null) {
                // Skip entries without entry IDs
                continue;
            }

            // Get table IDs for this post ID
            $pageTableIds = TablePressShortcodeProcessor::getTableIdsForPage(
                (string) $postId
            );

            // Check if this entry has table IDs to link
            if ($pageTableIds !== []) {
                // Map entry ID to table IDs
                $tableUpdatesByEntryId[$entryId] = [
                    'entry_id' => $entryId,
                    'table_ids' => $pageTableIds,
                    'page' => $page,
                    'collection_handle' => $collectionHandle,
                ];
            }
        }

        // Check if there are any entries to process
        if ($tableUpdatesByEntryId === []) {
            // No entries need table updates, return early
            return;
        }

        // ---
        // Process table updates for each entry
        // ---

        // Process each entry that needs table updates
        foreach ($tableUpdatesByEntryId as $entryInfo) {
            // Extract entry ID
            $entryId = $entryInfo['entry_id'];
            $tableIds = $entryInfo['table_ids'];
            $page = $entryInfo['page'];
            $entryCollectionHandle = $entryInfo['collection_handle'];

            // Display processing message for this entry
            $output->writeln(
                "Re-processing entry ID '{$entryId}' in the '{$entryCollectionHandle}' collection"
            );

            // Load entry (use cached object if available, otherwise query)
            $entry = $this->loadEntry(
                $entryId,
                $entryCollectionHandle,
                $site,
                $page,
                $output
            );

            // Check if entry was found
            if (! $entry instanceof Entry) {
                // Skip to next entry
                continue;
            }

            // Get fresh data from entry
            $data = self::entryDataToArray($entry);

            // ---
            // Point the shared logging context at the entry actually being linked.
            // linkTableEntries reads the page link from ProcessingContext when logging
            // warnings; without this, any warning would name whichever entry was
            // processed last during creation rather than this one.
            // ---
            ProcessingContext::setWordPressPageLink(
                self::optionalString($page['link'] ?? null)
            );

            // Link table entries in content and page_builder fields
            $parsedContent = $contentProcessor->linkTableEntries(
                self::arrayValue($data['content'] ?? []),
                self::arrayValue($data['page_builder'] ?? []),
                $site,
                $tableIds,
                $data,
                $entryCollectionHandle,
                $xmlParser
            );

            // Update content field with linked table entries
            $data['content'] = $parsedContent['bardContent'];

            // Update page_builder field with linked table entries
            $data['page_builder'] = $parsedContent['pageBuilderContent'];

            // Inform user that table links were updated
            $output->writeln('  - <info>Updated table links.</info>');

            // Reset initialPath to null so buildPath() is called instead
            $entry->initialPath(null);

            // Save entry with updated data
            $entry->data($data);
            $entry->save();

            // Inform user that entry was saved
            $output->writeln('  - <info>Saved entry with table updates.</info>');
        }

        // Clear table IDs after table linking is complete
        TablePressShortcodeProcessor::clearTableIds();
    }

    /**
     * Processes wp_block updates for all entries that need them.
     *
     * Updates Bard sets and replicator sets in content and page_builder fields
     * to replace import_wordpress_notes placeholders with actual reusable_block
     * entry IDs.
     *
     * @param  string  $site  The site handle
     * @param  string  $collectionHandle  The collection handle
     * @param  OutputInterface  $output  Console output interface
     * @param  array<int|string, mixed>  $pageData  Page data mapping (WordPress post ID to Statamic entry data)
     */
    private function processWpBlockUpdates(
        string $site,
        string $collectionHandle,
        OutputInterface $output,
        array $pageData
    ): void {
        // ---
        // Collect entries that need wp_block updates
        // ---

        // Get deferred wp_block references (map of entry IDs to deferred reference arrays)
        $wpBlockUpdatesByEntryId = WpBlockBlockProcessor::getDeferredWpBlockReferences();

        // Check if wp_block updates are needed
        if ($wpBlockUpdatesByEntryId === []) {
            // No wp_block updates needed, return early
            return;
        }

        // Inform user that wp_block updates are starting
        $output->writeln('Processing wp_block updates...');

        // Get wp_block mappings to get post IDs
        $wpBlockMappings = WpBlockRegistry::getAllMappings();

        // Get wp_block post IDs from mappings. updateBardSetsInArray() reads the
        // registry rather than this list, so keys and values behave alike.
        // @pest-mutate-ignore: UnwrapArrayKeys
        $wpBlockPostIds = \array_keys($wpBlockMappings);

        // Build map of entry IDs that need wp_block updates
        $entriesToProcess = [];

        // Loop through pageData to find entries with wp_block references
        foreach ($pageData as $postId => $page) {
            // Skip malformed page records
            if (! is_array($page)) {
                continue;
            }

            // Resolve the Statamic entry ID for this page record
            $entryId = self::entryIdAsString($page['entry'] ?? null);

            // Check if entry is missing from page data
            if ($entryId === null) {
                // Skip entries without entry IDs
                continue;
            }

            // Check if this entry has wp_block updates
            if (isset($wpBlockUpdatesByEntryId[$entryId])) {
                // Map entry ID to wp_block update info
                $entriesToProcess[$entryId] = [
                    'entry_id' => $entryId,
                    'page' => $page,
                    'collection_handle' => $collectionHandle,
                ];
            }

            unset(
                $postId,
                $page,
                $entryId
            );
        }

        // Check if there are any entries to process
        if ($entriesToProcess === []) {
            // No entries need wp_block updates, return early
            return;
        }

        // ---
        // Process wp_block updates for each entry
        // ---

        // Process each entry that needs wp_block updates
        foreach ($entriesToProcess as $entryInfo) {
            // Extract entry ID
            $entryId = $entryInfo['entry_id'];
            $page = $entryInfo['page'];
            $entryCollectionHandle = $entryInfo['collection_handle'];

            // Display processing message for this entry
            $output->writeln(
                "Re-processing entry ID '{$entryId}' in the '{$entryCollectionHandle}' collection"
            );

            // Load entry (use cached object if available, otherwise query)
            $entry = $this->loadEntry(
                $entryId,
                $entryCollectionHandle,
                $site,
                $page,
                $output
            );

            // Check if entry was found
            if (! $entry instanceof Entry) {
                // Skip to next entry
                continue;
            }

            // Get fresh data from entry
            $data = self::entryDataToArray($entry);

            // ---
            // Store original values for comparison
            // ---

            // Store original content array for comparison before processing updates
            $originalContent = self::arrayValue($data['content'] ?? []);

            // Store original page_builder array for comparison before processing updates
            $originalPageBuilder = self::arrayValue($data['page_builder'] ?? []);

            // Update Bard sets and replicator sets in content field
            $updatedContent = WpBlockBlockProcessor::updateBardSetsInArray(
                $originalContent,
                $wpBlockPostIds
            );

            // Update page_builder field for Bard sets and replicator sets
            $updatedPageBuilder = WpBlockBlockProcessor::updateBardSetsInArray(
                $originalPageBuilder,
                $wpBlockPostIds
            );

            // Check if content was changed using serialization for deep array comparison
            $contentChanged = \serialize($updatedContent) !== \serialize($originalContent);

            // Check if page_builder was changed using serialization for deep array comparison
            $pageBuilderChanged = \serialize($updatedPageBuilder) !== \serialize($originalPageBuilder);

            // Check if entry was updated (either content or page_builder changed)
            if (
                $contentChanged
                || $pageBuilderChanged
            ) {
                // Update content field with updated Bard sets
                $data['content'] = $updatedContent;

                // Update page_builder field with updated sets
                $data['page_builder'] = $updatedPageBuilder;

                // Inform user that wp_block updates were applied
                $output->writeln('  - <info>Applied wp_block updates: updated wp_block placeholders with reusable_block entry IDs.</info>');

                // Reset initialPath to null so buildPath() is called instead
                $entry->initialPath(null);

                // Save entry with updated data
                $entry->data($data);
                $entry->save();

                // Inform user that entry was saved
                $output->writeln('  - <info>Saved entry with wp_block updates.</info>');
            }

            // Unset loop variables to free memory
            unset(
                $entryInfo,
                $entryId,
                $page,
                $entryCollectionHandle,
                $entry,
                $data,
                $originalContent,
                $originalPageBuilder,
                $updatedContent,
                $updatedPageBuilder,
                $contentChanged,
                $pageBuilderChanged
            );
        }

        // Unset method variables to free memory
        unset(
            $wpBlockUpdatesByEntryId,
            $wpBlockMappings,
            $wpBlockPostIds,
            $entriesToProcess
        );
    }

    /**
     * Processes form updates for all entries that need them.
     *
     * Updates Bard sets and replicator sets in content and page_builder fields
     * to replace import_wordpress_notes placeholders with actual form handles.
     *
     * @param  string  $site  The site handle
     * @param  OutputInterface  $output  Console output interface
     * @param  FormPluginCoordinator  $formPluginCoordinator  The coordinator that updates the form handles
     */
    private function processFormUpdates(
        string $site,
        OutputInterface $output,
        FormPluginCoordinator $formPluginCoordinator
    ): void {
        // ---
        // Get entries that need form handle updates (all entries in relevant
        // collections). This returns a map of entry IDs to entry data for
        // entries that were updated, including form update information for
        // each entry.
        //
        // Note: updateAllFormHandlesInEntries() processes all entries and
        // replaces form placeholders with actual form handles. The individual
        // "Applied form update" messages are logged during the save loop below.
        // ---
        $formUpdatesByEntryId = $formPluginCoordinator->updateAllFormHandlesInEntries(
            $site,
            $output
        );

        // Check if form updates are needed
        if ($formUpdatesByEntryId === []) {
            // No form updates needed, return early
            return;
        }

        // Inform user that form updates are starting
        $output->writeln('Processing form updates...');

        // Process each entry that needs form updates
        foreach ($formUpdatesByEntryId as $entryId => $entryData) {
            // Skip malformed form update records
            if (! is_array($entryData)) {
                continue;
            }

            // Get entry from form updates
            $entry = $entryData['entry'] ?? null;
            $collectionHandle = $entryData['collection'] ?? null;
            $formUpdates = $entryData['form_updates'] ?? [];

            // Skip records without a writable entry and collection handle
            if (
                ! $entry instanceof Entry
                || ! is_string($collectionHandle)
            ) {
                continue;
            }

            // Inform user that we're re-processing this entry
            $output->writeln(
                "Re-processing entry ID '{$entryId}' in the '{$collectionHandle}' collection"
            );

            // Inform user that cached entry object is being used
            $output->writeln('  - <comment>Using cached entry object.</comment>');

            // Log form updates for this entry
            if (is_array($formUpdates)) {
                foreach ($formUpdates as $formUpdate) {
                    // Skip malformed individual form update records
                    if (! is_array($formUpdate)) {
                        continue;
                    }

                    $formNote = is_string($formUpdate['form_note'] ?? null)
                        ? $formUpdate['form_note']
                        : '';
                    $formHandle = is_string($formUpdate['form_handle'] ?? null)
                        ? $formUpdate['form_handle']
                        : '';

                    // Inform user that form update was applied
                    $output->writeln(
                        "  - <info>Applied form update: {$formNote} -> {$formHandle}</info>"
                    );
                }
            }

            $updatedData = $entryData['data'] ?? null;

            // Skip entries without updated data to persist
            if (! is_array($updatedData)) {
                continue;
            }

            // Reset initialPath to null so buildPath() is called instead
            $entry->initialPath(null);

            // Save entry with updated form handles
            $entry->data($updatedData);
            $entry->save();

            // Inform user that entry was saved
            $output->writeln('  - <info>Saved entry with form updates.</info>');
        }
    }

    /**
     * Processes internal link updates for all entries that need them.
     *
     * Resolves deferred internal links by replacing WordPress URLs with
     * Statamic entry references in the content field.
     *
     * @param  string  $site  The site handle
     * @param  string  $collectionHandle  The collection handle
     * @param  OutputInterface  $output  Console output interface
     * @param  AssetImporter  $assetImporter  The asset importer for links to attachments
     * @param  array<int|string, mixed>  $pageData  Page data mapping (WordPress post ID to Statamic entry data)
     */
    private function processInternalLinkUpdates(
        string $site,
        string $collectionHandle,
        OutputInterface $output,
        AssetImporter $assetImporter,
        array $pageData
    ): void {
        // Get deferred internal links (map of entry IDs to deferred link arrays)
        $deferredLinkUpdatesByEntryId = LinkProcessor::getDeferredInternalLinks();

        // Check if internal link updates are needed
        if ($deferredLinkUpdatesByEntryId === []) {
            // No internal link updates needed, return early
            return;
        }

        // Inform user that internal link updates are starting
        $output->writeln('Resolving deferred internal links for entries in current post type...');

        // Build map of entry IDs that need internal link updates
        $entriesToProcess = [];

        // Loop through deferred links to collect entry IDs
        foreach ($deferredLinkUpdatesByEntryId as $entryId => $deferredLinks) {
            // Skip empty deferred link lists
            if ($deferredLinks === []) {
                continue;
            }

            // An array key is an int or a string, so the id is never missing; this
            // only types it.
            $entryIdString = self::entryIdAsString($entryId);

            if ($entryIdString === null) {
                // @pest-mutate-ignore: ContinueToBreak
                continue;
            }

            // Get the source collection handle from the first deferred link
            // (all deferred links for an entry should have the same source collection)
            $firstDeferredLink = $deferredLinks[0] ?? null;
            $entryCollectionHandle = $collectionHandle;

            if ($firstDeferredLink !== null) {
                $entryCollectionHandle = $firstDeferredLink['source_collection_handle'];
            }

            // ---
            // Only process entries that belong to the current collection being processed.
            // This prevents attempting to load entries from other collections that don't
            // exist in the current collection context.
            // ---

            // Check if entry collection handle is not the current collection being processed
            if ($entryCollectionHandle !== $collectionHandle) {
                // Skip entries from other collections
                continue;
            }

            // Check if entry is in pageData
            $page = null;
            foreach ($pageData as $pageRecord) {
                if (! is_array($pageRecord)) {
                    continue;
                }

                $pageEntryId = self::entryIdAsString($pageRecord['entry'] ?? null);

                if ($pageEntryId === $entryIdString) {
                    $page = $pageRecord;
                    break;
                }
            }

            // Map entry ID to internal link update info
            $entriesToProcess[$entryIdString] = [
                'entry_id' => $entryIdString,
                'page' => $page,
                'collection_handle' => $entryCollectionHandle,
                'deferred_links' => $deferredLinks,
            ];
        }

        // Check if there are any entries to process
        if ($entriesToProcess === []) {
            // No entries need internal link updates, return early
            return;
        }

        // ---
        // Process internal link updates for each entry
        // ---

        // Process each entry that needs internal link updates
        foreach ($entriesToProcess as $entryInfo) {
            // Extract entry ID
            $entryId = $entryInfo['entry_id'];
            $page = $entryInfo['page'];
            $entryCollectionHandle = $entryInfo['collection_handle'];
            $deferredLinks = $entryInfo['deferred_links'];

            // Display processing message for this entry
            $output->writeln(
                "Re-processing entry ID '{$entryId}' in the '{$entryCollectionHandle}' collection"
            );

            // Load entry (use cached object if available, otherwise query)
            $entry = $this->loadEntry(
                $entryId,
                $entryCollectionHandle,
                $site,
                $page,
                $output
            );

            // Check if entry was found
            if (! $entry instanceof Entry) {
                // Skip to next entry
                continue;
            }

            // Get fresh data from entry
            $data = self::entryDataToArray($entry);

            // Process deferred links for this entry using shared method
            $processingResult = LinkProcessor::processDeferredLinksForEntry(
                $entry,
                $deferredLinks,
                $site,
                $output,
                false, // Not final call - this is per-content-type processing
                $assetImporter // Pass AssetImporter for attachment processing
            );

            // ---
            // Extract processing results
            // ---

            // Extract updated content field from processing results
            $content = $processingResult['content'];

            // Extract updated page_builder field from processing results
            $pageBuilder = $processingResult['page_builder'];

            // Extract content update flag from processing results
            $contentUpdated = $processingResult['content_updated'];

            // Extract page_builder update flag from processing results
            $pageBuilderUpdated = $processingResult['page_builder_updated'];

            // Extract processed link URLs from processing results
            $processedLinkUrls = $processingResult['processed_link_urls'];

            // ---
            // Update content and page_builder if they were modified
            // ---

            // Check if content or page_builder fields were modified during processing
            if (
                $contentUpdated
                || $pageBuilderUpdated
            ) {
                // Check if content field was modified during processing
                if ($contentUpdated) {
                    // Update content field with resolved links
                    $data['content'] = $content;
                }

                // Check if page_builder field was modified during processing
                if ($pageBuilderUpdated) {
                    // Update page_builder field with resolved links
                    $data['page_builder'] = $pageBuilder;
                }

                // Reset initialPath to null so buildPath() is called instead
                $entry->initialPath(null);

                // Save entry with updated data
                $entry->data($data);
                $entry->save();

                // Inform user that entry was saved
                $output->writeln('  - <info>Saved entry with internal link updates.</info>');
            }

            // ---
            // Remove successfully processed links from LinkProcessor's deferred links array
            // ---

            // Check if any links were successfully processed
            if ($processedLinkUrls !== []) {
                // Get the deferred links array from LinkProcessor
                $allDeferredLinks = LinkProcessor::getDeferredInternalLinks();

                // Check if this entry has deferred links in the global array
                if (isset($allDeferredLinks[$entryId])) {
                    $entryDeferredLinks = $allDeferredLinks[$entryId];

                    // Remove successfully processed links by matching URLs
                    foreach ($entryDeferredLinks as $index => $linkData) {
                        $linkUrl = $linkData['url'];

                        // Check if this link URL was successfully processed. Both sides are
                        // strings, so a loose comparison matches the same URLs.
                        // @pest-mutate-ignore: TrueToFalse
                        if (
                            \in_array(
                                $linkUrl,
                                $processedLinkUrls,
                                true
                            )
                        ) {
                            // Remove the link from the deferred links array
                            unset($entryDeferredLinks[$index]);
                        }
                    }

                    // Re-index the array to remove gaps
                    $allDeferredLinks[$entryId] = \array_values($entryDeferredLinks);

                    // ---
                    // Remove the entry entirely if no deferred links remain
                    // ---

                    // Check if no deferred links remain for this entry
                    if ($allDeferredLinks[$entryId] === []) {
                        // Remove the entry from the deferred links array
                        unset($allDeferredLinks[$entryId]);
                    }

                    // Update LinkProcessor's deferred links array
                    LinkProcessor::setDeferredInternalLinks($allDeferredLinks);
                }
            }

            // Unset loop variables
            unset(
                $entryInfo,
                $entryId,
                $page,
                $entryCollectionHandle,
                $deferredLinks,
                $entry,
                $data,
                $processingResult,
                $content,
                $pageBuilder,
                $contentUpdated,
                $pageBuilderUpdated,
                $processedLinkUrls
            );
        }

        // Unset method variables
        unset(
            $deferredLinkUpdatesByEntryId,
            $entriesToProcess
        );
    }

    /**
     * Loads an entry with retry logic.
     *
     * Uses cached entry object if available, otherwise queries the entry with
     * retry logic to handle cache timing issues.
     *
     * @param  string  $entryId  The entry ID
     * @param  string  $collectionHandle  The collection handle
     * @param  string  $site  The site handle
     * @param  array<int|string, mixed>|null  $page  Optional page data with cached entry object
     * @param  OutputInterface  $output  Console output interface
     * @return ?Entry The entry or null if not found
     */
    private function loadEntry(
        string $entryId,
        string $collectionHandle,
        string $site,
        ?array $page,
        OutputInterface $output
    ): ?Entry {
        // Initialize entry variable
        $entry = null;

        // Check if cached entry object is available from page data
        if (isset($page['entry_object'])) {
            // Use cached entry object when it is a valid Entry instance
            $cachedEntry = $page['entry_object'];

            if ($cachedEntry instanceof Entry) {
                $entry = $cachedEntry;

                // Inform user that cached entry object is being used
                $output->writeln('  - <comment>Using cached entry object.</comment>');
            }
        }

        // Query the entry when no valid cached object is available
        if (! $entry instanceof Entry) {
            // Retry querying the entry until found, for at most seven attempts. A fixed
            // range keeps the loop finite whatever its body does.
            foreach (\range(1, 7) as $attempt) {
                // Query entry by collection, site, and ID
                $entry = EntryFinder::findEntryById(
                    $collectionHandle,
                    $entryId,
                    false,
                    $site
                );

                // Check if entry was found
                if ($entry instanceof Entry) {
                    // Break out of retry loop since entry was found. A later attempt would
                    // only find the same entry again, without a warning or a wait.
                    // @pest-mutate-ignore: BreakToContinue
                    break;
                }

                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'site' => $site,
                        'collection' => $collectionHandle,
                        'retry_count' => $attempt,
                    ]
                );

                // Log warning for failed query attempt
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        "DeferredProcessingCoordinator::loadEntry: Entry '$entryId' not found in '$collectionHandle' collection on attempt {$attempt}",
                        $logData['page_url']
                    ),
                    $logData['context']
                );

                // Wait 500ms before the next retry attempt
                Sleep::usleep(500000);
            }

            // Rebuild the Stache's entries store and look once more if still not found
            if (! $entry instanceof Entry) {
                // Statamic always registers the entries store, so the check only types it
                $store = StacheFacade::store('entries');

                // @pest-mutate-ignore: InstanceOfToTrue
                if ($store instanceof Store) {
                    // Clear Stache cache
                    $store->clear();

                    // Warm Stache cache
                    $store->warm();

                    // Wait for cache to be ready
                    Sleep::usleep(500000); // 500ms delay after re-warm

                    // Query entry again after cache re-warm
                    $entry = EntryFinder::findEntryById(
                        $collectionHandle,
                        $entryId,
                        true,
                        $site
                    );
                }
            }
        }

        // Check if entry was found
        if (! $entry instanceof Entry) {
            // Display error message to user
            $output->writeln(
                "<error>Failed to find entry '$entryId' in '$collectionHandle' collection in site '$site'.</error>"
            );

            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'collection' => $collectionHandle,
                    'entry_id' => $entryId,
                ]
            );

            // Log warning for failed entry lookup
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    "DeferredProcessingCoordinator::loadEntry: Failed to find entry '$entryId' in '$collectionHandle' collection in site '$site'",
                    $logData['page_url']
                ),
                $logData['context']
            );
        }

        // Return the entry (or null if not found)
        return $entry;
    }

    /**
     * Normalize an entry ID value from mixed page data.
     *
     * @param  mixed  $entryId  Raw entry ID value
     * @return string|null Normalized entry ID, or null when invalid
     */
    private static function entryIdAsString(mixed $entryId): ?string
    {
        if (is_string($entryId)) {
            return $entryId;
        }

        if (is_int($entryId)) {
            return (string) $entryId;
        }

        return null;
    }

    /**
     * Normalize a mixed value to a string or null.
     *
     * @param  mixed  $value  Raw value
     * @return string|null String value, or null when unavailable
     */
    private static function optionalString(mixed $value): ?string
    {
        return is_string($value)
            ? $value
            : null;
    }

    /**
     * Normalize a mixed value to an array.
     *
     * @param  mixed  $value  Raw value
     * @return array<int|string, mixed> Array value, or an empty array
     */
    private static function arrayValue(mixed $value): array
    {
        return is_array($value)
            ? $value
            : [];
    }

    /**
     * Read a Statamic entry's data as a plain array.
     *
     * @param  Entry  $entry  The entry whose data should be read
     * @return array<int|string, mixed> Entry data, or an empty array when unavailable
     */
    private static function entryDataToArray(Entry $entry): array
    {
        // Read the entry's current data. With no argument, data() always answers the
        // entry's collection of values, so the check only types it.
        $entryData = $entry->data();

        // @pest-mutate-ignore: InstanceOfToTrue
        return $entryData instanceof Collection
            ? $entryData->toArray()
            : [];
    }
}
