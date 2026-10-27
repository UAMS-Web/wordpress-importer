<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities;

use App\Services\BardContentSanitizer;
use DOMComment;
use DOMDocument;
use DOMNode;
use DOMNodeList;
use DOMText;
use Illuminate\Support\Facades\File as LaravelFileFacade;
use Illuminate\Support\Facades\Log;
use Statamic\Entries\Entry as StatamicEntry;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\PageBuilder\PageBuilderParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\ShortcodeProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\WpGroupBlockProcessor;

/**
 * Processes WordPress content into Statamic field arrays.
 *
 * Orchestrates the conversion of WordPress `<content:encoded>` data into
 * Statamic-compatible field arrays for basic_page (Bard) or landing_page
 * (page_builder) content. Delegates block processing to specific processors, uses
 * TextProcessor for unified Bard field processing with WpAutoP formatting for
 * non-ACF content, processing raw content directly to preserve structural
 * newlines.
 *
 * Handles shortcodes in non-block content.
 */
class ContentProcessor
{
    /**
     * Parser for ACF-based page builder content.
     */
    private PageBuilderParser $pageBuilderParser;

    /**
     * Processor for WordPress shortcodes in non-block content.
     */
    private ShortcodeProcessor $shortcodeProcessor;

    /**
     * Tracks unaddressed DOM nodes for reporting.
     *
     * @var array<string, array<array{id: string, slug: ?string, link: string, original?: string}>>
     */
    protected $unaddressedNodes = [];

    /**
     * Bard content sanitizer for handling YAML separators.
     *
     * @var BardContentSanitizer
     */
    private $bardContentSanitizer;

    /**
     * Constructs the ContentProcessor with dependencies.
     *
     * Initializes the page builder parser, shortcode processor, and Bard content
     * sanitizer for handling various WordPress block types.
     *
     * @param  ?PageBuilderParser  $pageBuilderParser  Parser for ACF blocks
     * @param  ?ShortcodeProcessor  $shortcodeProcessor  Processor for shortcodes
     */
    public function __construct(
        ?PageBuilderParser $pageBuilderParser = null,
        ?ShortcodeProcessor $shortcodeProcessor = null
    ) {
        $this->pageBuilderParser = $pageBuilderParser ?? new PageBuilderParser;
        $this->shortcodeProcessor = $shortcodeProcessor ?? new ShortcodeProcessor;

        // Initialize Bard content sanitizer for handling YAML separators
        $this->bardContentSanitizer = new BardContentSanitizer;
    }

    /**
     * Checks if the content string contains ACF blocks.
     *
     * Scans the raw HTML content string for wp:acf/*, wp:minervakb/*, or wp:rss block
     * comments to determine if the content should be processed as a landing_page
     * blueprint. This is more reliable than checking the DOM because comments inside
     * elements (e.g., inside wp:group divs) may not be preserved correctly when
     * loaded into a DOMDocument.
     *
     * This scan is performed before DOM creation and before group block unwrapping.
     * While wp:group blocks are unwrapped during DOM processing (via
     * WpGroupBlockProcessor), the unwrapping happens after DOM creation. If comments
     * inside div elements are lost during DOM loading, the unwrapping cannot recover
     * them. Additionally, the detection must occur before processing begins to
     * determine which parser to use (PageBuilderParser vs TextProcessor). Therefore,
     * scanning the raw HTML string remains the most reliable method for detecting ACF
     * blocks, regardless of nesting within group blocks.
     *
     * @param  string  $content  The raw HTML content string
     * @return bool True if wp:acf/* blocks, wp:minervakb/* blocks, wp:rss blocks, or div.lwcw
     *              LiveWhale widget HTML are present, false otherwise
     */
    private function hasAcfBlocksInContent(
        string $content
    ): bool {
        // Check if content contains wp:acf/* block comments
        $matches = \preg_match(
            '/<!--\s*wp:acf\//',
            $content,
            $matchResults,
            PREG_OFFSET_CAPTURE
        );

        // Check if ACF blocks were found
        if ($matches === 1) {
            // Return true if ACF blocks were found
            return true;
        }

        // Check if content contains wp:minervakb/* block comments
        $matches = \preg_match(
            '/<!--\s*wp:minervakb\//',
            $content,
            $matchResults,
            PREG_OFFSET_CAPTURE
        );

        // Check if MinervaKB blocks were found
        if ($matches === 1) {
            // Return true if MinervaKB blocks were found
            return true;
        }

        // Check if content contains wp:rss block comments
        $matches = \preg_match(
            '/<!--\s*wp:rss/',
            $content,
            $matchResults,
            PREG_OFFSET_CAPTURE
        );

        // Check if wp:rss blocks were found
        if ($matches === 1) {
            // Return true if wp:rss blocks were found
            return true;
        }

        // Check if content contains LiveWhale widget HTML (div.lwcw with data-options id=)
        if (
            \preg_match(
                '/\bclass=["\']lwcw["\']/',
                $content
            ) === 1
            && \preg_match(
                '/data-options=["\'][^"\']*\bid=\d+/',
                $content
            ) === 1
        ) {
            // Return true if LiveWhale widget markup found
            return true;
        }

        // Check if content contains blogroll shortcode (imported as uamsweb_block_news)
        // Return false if no page builder content found
        // Return true if blogroll shortcode found
        return \preg_match(
            '/\[blogroll\s/',
            $content
        ) === 1;
    }

    /**
     * Links table entries to Bard and page builder sets.
     *
     * Updates table sets (type: table) in Bard or page_builder arrays by linking
     * table_entry fields to tables collection entries based on table IDs. Searches
     * nested body fields in page_builder sets (e.g., uamsweb_block_content) to
     * handle table sets within them. Sets heading_query and description_query based
     * on XML table options and includes heading_level (h3 for landing_page, h2 for
     * others) only when heading_query is true. Processes all table IDs in order,
     * including duplicates, to ensure correct mapping. Preserves XML parser's item
     * list state. Uses filesystem lookup with correct slug paths for landing_page
     * blueprints and site-scoped query with fallback for others.
     *
     * @param  array<int|string, mixed>  $bardContent  The Bard content array
     * @param  array<int|string, mixed>  $pageBuilderContent  The page builder content array
     * @param  string  $site  The site handle
     * @param  array<int|string, mixed>  $tableIds  Array of table IDs from shortcodes/blocks in content order
     * @param  array<int|string, mixed>  $data  Entry data including blueprint
     * @param  string  $collectionHandle  The collection handle
     * @param  XmlParser  $xmlParser  The XML parser instance
     * @return array<int|string, mixed> Array with updated 'bardContent' and 'pageBuilderContent'
     */
    public function linkTableEntries(
        array $bardContent,
        array $pageBuilderContent,
        string $site,
        array $tableIds,
        array $data,
        string $collectionHandle,
        XmlParser $xmlParser
    ): array {
        // Retrieve tablepress_table items
        $items = $xmlParser->getItems('tablepress_table');

        // Build a mapping of tableId to postId from XML
        $tableIdToPostId = [];
        foreach ($items as $item) {
            $meta = $item->xpath('wp:postmeta[wp:meta_key="_tablepress_export_table_id"]/wp:meta_value');
            $tableId = null;

            // Read table ID from metadata xpath result
            if (
                \is_array($meta)
                && isset($meta[0])
            ) {
                $tableId = (string) $meta[0];
            }

            // Extract the WordPress post ID from XML
            $postId = XpathHelper::getStringValue(
                $item,
                'wp:post_id'
            );

            if ($tableId !== null && $tableId !== '') {
                $tableIdToPostId[$tableId] = [
                    'postId' => $postId,
                ];
                // Retrieve table options
                $optionsMeta = $item->xpath('wp:postmeta[wp:meta_key="_tablepress_table_options"]/wp:meta_value');
                if (
                    \is_array($optionsMeta)
                    && isset($optionsMeta[0])
                ) {
                    $decodedOptions = \json_decode(
                        (string) $optionsMeta[0],
                        true
                    );
                    $tableIdToPostId[$tableId]['options'] = \is_array($decodedOptions)
                        ? $decodedOptions
                        : [];
                }
            }
        }

        // Determine if using filesystem lookup (for landing_page blueprint)
        $useFilesystem = ($data['blueprint'] ?? null) === 'landing_page';

        // Update both Bard and page builder content arrays
        $tableIdIndex = 0;
        $updatedBardContent = $this->updateTableSets(
            $bardContent,
            $tableIdIndex,
            $site,
            $tableIds,
            $tableIdToPostId,
            $data,
            $collectionHandle,
            $useFilesystem
        );
        $bardTableIdIndex = $tableIdIndex;
        $tableIdIndex = 0;
        $updatedPageBuilderContent = $this->updateTableSets(
            $pageBuilderContent,
            $tableIdIndex,
            $site,
            $tableIds,
            $tableIdToPostId,
            $data,
            $collectionHandle,
            $useFilesystem
        );
        $pageBuilderTableIdIndex = $tableIdIndex;

        // Use the maximum index from both (they should be processed independently)
        $finalTableIdIndex = \max($bardTableIdIndex, $pageBuilderTableIdIndex);

        // Log warning if table IDs remain unlinked
        if ($finalTableIdIndex < \count($tableIds)) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'unlinkedTableIds' => \array_slice(
                        $tableIds,
                        $finalTableIdIndex
                    ),
                ]
            );

            // Log warning about unlinked table IDs
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'ContentProcessor::linkTableEntries: Table IDs not linked to sets',
                    $logData['page_url']
                ),
                $logData['context']
            );
        }

        // Return updated content arrays
        return [
            'bardContent' => $updatedBardContent,
            'pageBuilderContent' => $updatedPageBuilderContent,
        ];
    }

    /**
     * Updates the table sets in a content array, recursing into nested bodies.
     *
     * @param  array<int|string, mixed>  $sets  The sets to update
     * @param  int  $tableIdIndex  The index of the next table ID to link, advanced as IDs are used
     * @param  string  $site  The site handle
     * @param  array<int|string, mixed>  $tableIds  The table IDs in content order
     * @param  array<int|string, array{postId: string, options?: array<int|string, mixed>}>  $tableIdToPostId  Post ID and options by table ID
     * @param  array<int|string, mixed>  $data  Entry data including blueprint
     * @param  string  $collectionHandle  The collection handle
     * @param  bool  $useFilesystem  Whether to look entries up on the filesystem only
     * @return array<int|string, mixed> The updated sets
     */
    private function updateTableSets(
        array $sets,
        int &$tableIdIndex,
        string $site,
        array $tableIds,
        array $tableIdToPostId,
        array $data,
        string $collectionHandle,
        bool $useFilesystem
    ): array {
        $updatedSets = $sets;

        // Iterate through sets to find table sets or nested body fields
        foreach ($updatedSets as &$set) {
            // Skip non-array sets
            if (! \is_array($set)) {
                continue;
            }

            // Read set metadata used for table-set matching
            $setType = $set['type'] ?? null;
            $setType = \is_string($setType) ? $setType : null;
            $setAttrs = $set['attrs'] ?? [];
            $setAttrs = \is_array($setAttrs) ? $setAttrs : [];
            $setValues = $setAttrs['values'] ?? [];
            $setValues = \is_array($setValues) ? $setValues : [];
            $setValueType = $setValues['type'] ?? null;
            $setValueType = \is_string($setValueType) ? $setValueType : null;
            $setCreateQuery = $setValues['create_query'] ?? null;
            $setCreateQuery = \is_string($setCreateQuery) ? $setCreateQuery : null;

            // Check for table set with create_query: collection
            if (
                $setType === 'set'
                && $setValueType === 'table'
                && $setCreateQuery === 'collection'
            ) {
                // Get the next table ID in order
                if ($tableIdIndex < \count($tableIds)) {
                    $tableIdValue = $tableIds[$tableIdIndex];
                    $tableId = \is_scalar($tableIdValue) ? (string) $tableIdValue : '';
                    $tableIdIndex++;
                } else {
                    // Enhance log context with entry information
                    $logData = LogContextHelper::enhanceWithEntryContext(
                        [
                            'site' => $site,
                            'set' => $set,
                        ]
                    );

                    // Log warning about no more table IDs available
                    Log::warning(
                        LogContextHelper::formatMessageWithEntry(
                            'ContentProcessor::linkTableEntries: No more table IDs available to link table set',
                            $logData['page_url']
                        ),
                        $logData['context']
                    );

                    continue;
                }

                // Get postId and options from mapping
                $tableMeta = $tableIdToPostId[$tableId] ?? [];
                $postId = $tableMeta['postId'] ?? null;
                $postId = \is_string($postId) ? $postId : null;
                $options = $tableMeta['options'] ?? [];

                // Retrieve table entry, or the id read from its file
                $entry = null;
                $fileEntryId = null;

                // Check if we have a valid WordPress post ID for the table
                if ($postId !== null && $postId !== '') {
                    // ---
                    // Find existing table entry by base slug and post ID to avoid generating new
                    // random ID
                    // ---
                    $baseSlug = "{$site}_tablepress-{$tableId}";

                    // Check if we should use database queries instead of filesystem lookup
                    if (! $useFilesystem) {
                        // Initialize query builder for entry lookup
                        $entryQuery = EntryQueryHelper::query();

                        // ---
                        // Try querying the entry for non-landing_page blueprints. Search by slug first,
                        // then by post ID pattern to find the existing entry.
                        // ---
                        $entryQuery->where(
                            'slug',
                            $baseSlug
                        );
                        $entryQuery->where(
                            'site',
                            $site
                        );
                        $entryQuery->where(
                            'collection',
                            'tables'
                        );
                        $entry = $entryQuery->first();

                        // Check if slug-based lookup failed and try ID pattern matching
                        if (! $entry instanceof StatamicEntry) {
                            // Initialize fallback query builder for ID pattern lookup
                            $fallbackEntryQuery = EntryQueryHelper::query();

                            // Search by ID pattern as fallback when slug lookup fails
                            $fallbackEntryQuery->where(
                                'site',
                                $site
                            );
                            $fallbackEntryQuery->where(
                                'collection',
                                'tables'
                            );
                            $fallbackEntryQuery->where(
                                'id',
                                'like',
                                "{$baseSlug}-{$postId}-%"
                            );
                            $entry = $fallbackEntryQuery->first();
                        }
                    }

                    // Check if database lookup failed and try filesystem lookup
                    if (! $entry instanceof StatamicEntry) {
                        // Use filesystem lookup (primary for landing_page, fallback otherwise)
                        $entryPath = ContentRoot::collectionEntry(
                            'tables',
                            $site,
                            "{$site}_tablepress-{$tableId}"
                        );

                        // Check if the table entry file exists on the filesystem
                        if (LaravelFileFacade::exists($entryPath)) {
                            // Read the file to get the actual entry ID
                            $fileContent = LaravelFileFacade::get($entryPath);

                            // Check if we can extract the entry ID from the file content
                            if (
                                \preg_match(
                                    '/^id:\s*(.+)$/m',
                                    $fileContent,
                                    $matches
                                ) === 1
                            ) {
                                // Extract and clean the entry ID from the file; only the id
                                // is linked, so no entry is built from it
                                $fileEntryId = \trim($matches[1]);
                            } else { // Otherwise, log error if ID extraction failed
                                // Enhance log context with entry information
                                $logData = LogContextHelper::enhanceWithEntryContext(
                                    [
                                        'site' => $site,
                                        'tableId' => $tableId,
                                        'postId' => $postId,
                                        'path' => $entryPath,
                                    ]
                                );

                                // Log warning when entry ID cannot be extracted from file
                                Log::warning(
                                    LogContextHelper::formatMessageWithEntry(
                                        'ContentProcessor::linkTableEntries: Could not extract entry ID from file',
                                        $logData['page_url']
                                    ),
                                    $logData['context']
                                );
                            }
                        } else { // Otherwise, log error if file doesn't exist
                            // Enhance log context with entry information
                            $logData = LogContextHelper::enhanceWithEntryContext(
                                [
                                    'site' => $site,
                                    'tableId' => $tableId,
                                    'postId' => $postId,
                                    'path' => $entryPath,
                                ]
                            );

                            // Log warning when table entry file is not found on filesystem
                            Log::warning(
                                LogContextHelper::formatMessageWithEntry(
                                    'ContentProcessor::linkTableEntries: Table entry file not found',
                                    $logData['page_url']
                                ),
                                $logData['context']
                            );
                        }
                    }
                } else { // Otherwise, log error if no post ID is available
                    // Enhance log context with entry information
                    $logData = LogContextHelper::enhanceWithEntryContext(
                        [
                            'site' => $site,
                            'tableId' => $tableId,
                        ]
                    );

                    // Log warning when no WordPress post ID is found for the table
                    Log::warning(
                        LogContextHelper::formatMessageWithEntry(
                            "ContentProcessor::linkTableEntries: No postId found for table ID '$tableId'",
                            $logData['page_url']
                        ),
                        $logData['context']
                    );
                }

                // Assign table entry ID or null if not found
                $set['attrs'] = $setAttrs;
                $set['attrs']['values'] = $setValues;
                $set['attrs']['values']['table_entry'] = $entry instanceof StatamicEntry
                    ? $entry->id()
                    : $fileEntryId;

                // Set heading_query based on table options
                $set['attrs']['values']['heading_query'] = ($options['print_name'] ?? false) === true;

                // Set description_query based on table options
                $set['attrs']['values']['description_query'] = ($options['print_description'] ?? false) === true;

                // Check if heading should be displayed and set appropriate heading level
                if ($set['attrs']['values']['heading_query']) {
                    // Set heading level based on blueprint type (h3 for landing_page, h2 for others)
                    $set['attrs']['values']['heading_level'] = ($collectionHandle === 'pages' && ($data['blueprint'] ?? null) === 'landing_page') ? 'h3' : 'h2';
                } else { // Otherwise, remove heading_level if heading is not displayed
                    // Remove heading_level field when heading_query is false
                    unset(
                        $set['attrs']['values']['heading_level']
                    );
                }
            } elseif ($setType === 'uamsweb_block_table') {
                // ---
                // The page_builder path emits a self-contained table block that already
                // carries its own table_id (no Bard set to link), so it needs no further
                // processing here. It still consumes one of the page's table ids, though,
                // so advance the index to avoid falsely reporting a fully-imported
                // `[table id=N /]` shortcode as unlinked.
                // ---
                // Past the last id this matches the guard above, which reads the
                // index only against the count
                $tableIdIndex++;
            }

            // Check if set has nested body content to process recursively
            if (
                isset($set['body'])
                && \is_array($set['body'])
            ) {
                // Recursively process nested body fields for table sets
                $set['body'] = $this->updateTableSets(
                    $set['body'],
                    $tableIdIndex,
                    $site,
                    $tableIds,
                    $tableIdToPostId,
                    $data,
                    $collectionHandle,
                    $useFilesystem
                );
            }
        }

        // Unset reference to avoid unintended modifications
        unset($set);

        // Return the updated sets array
        return $updatedSets;
    }

    /**
     * Processes WordPress content into Statamic field arrays.
     *
     * Converts raw WordPress content into Bard-compatible ProseMirror JSON for
     * basic_page or page_builder arrays for landing_page with ACF blocks. Handles
     * shortcodes, parses HTML with TextProcessor for non-ACF content, and ensures
     * proper formatting. Applies WpAutoP formatting to non-ACF content to convert
     * double line breaks to paragraphs and single line breaks to hard breaks, using
     * raw content to preserve structural newlines. Logs errors for malformed HTML
     * and returns empty arrays for empty input. Defers table entry linking to
     * post-import processing. Uses TextProcessor for HTML entity decoding to ensure
     * consistent special character handling. Passes items and terms to
     * PageBuilderParser for term mapping in non-ACF shortcode processing.
     *
     * @param  string  $content  Raw WordPress content from <content:encoded>
     * @param  string  $site  The Statamic site handle
     * @param  AssetImporter  $assetImporter  Asset importer for media
     * @param  OutputInterface  $output  Console output for logging
     * @param  XmlParser  $xmlParser  The XML parser instance
     * @param  string  $pageTitle  Page title for context (default: '')
     * @param  string  $pageId  Page ID for unaddressed node tracking (default: '')
     * @param  string  $pageLink  Page permalink for tracking (default: '')
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects
     * @param  array<int|string, mixed>  $items  All XML items for post ID mapping
     * @param  array<int|string, mixed>  $data  Entry data including blueprint
     * @param  string  $collectionHandle  The collection handle
     * @param  ?string  $pageSlug  Page slug for logging (optional)
     * @param  ?string  $statamicEntryId  The Statamic entry ID being processed (for deferred processing)
     * @return array{content: mixed, page_builder: array<int|string, mixed>} Bard content and page_builder sets
     */
    public function process(
        string $content,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        XmlParser $xmlParser,
        string $pageTitle = '',
        string $pageId = '',
        string $pageLink = '',
        array $attachments = [],
        array $terms = [],
        array $items = [],
        array $data = [],
        string $collectionHandle = '',
        ?string $pageSlug = null,
        ?string $statamicEntryId = null
    ): array {
        // ---
        // Leave libxml's internal-errors mode as the caller had it (wordpress-importer-archive#1354). The
        // mode is process-wide, and both this class and the Bard parser it drives
        // (ueberdosis/tiptap-php's DOMParser) switch it on, so without this every
        // later libxml warning in the import is silenced, including the
        // invalid-expression warning XpathHelper logs.
        // ---
        $previousInternalErrors = libxml_use_internal_errors();

        try {
            return $this->processContent(
                $content,
                $site,
                $assetImporter,
                $output,
                $xmlParser,
                $pageTitle,
                $pageId,
                $pageLink,
                $attachments,
                $terms,
                $items,
                $data,
                $collectionHandle,
                $pageSlug,
                $statamicEntryId
            );
        } finally {
            // Put back the mode the caller had
            libxml_use_internal_errors($previousInternalErrors);
        }
    }

    /**
     * Processes WordPress content into Statamic field arrays, for process().
     *
     * process() wraps this to restore libxml's internal-errors mode afterwards.
     *
     * @param  string  $content  Raw WordPress content from <content:encoded>
     * @param  string  $site  The Statamic site handle
     * @param  AssetImporter  $assetImporter  Asset importer for media
     * @param  OutputInterface  $output  Console output for logging
     * @param  XmlParser  $xmlParser  The XML parser instance
     * @param  string  $pageTitle  Page title for context
     * @param  string  $pageId  Page ID for unaddressed node tracking
     * @param  string  $pageLink  Page permalink for tracking
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects
     * @param  array<int|string, mixed>  $items  All XML items for post ID mapping
     * @param  array<int|string, mixed>  $data  Entry data including blueprint
     * @param  string  $collectionHandle  The collection handle
     * @param  ?string  $pageSlug  Page slug for logging
     * @param  ?string  $statamicEntryId  The Statamic entry ID being processed
     * @return array{content: mixed, page_builder: array<int|string, mixed>} Bard content and page_builder sets
     */
    private function processContent(
        string $content,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        XmlParser $xmlParser,
        string $pageTitle,
        string $pageId,
        string $pageLink,
        array $attachments,
        array $terms,
        array $items,
        array $data,
        string $collectionHandle,
        ?string $pageSlug,
        ?string $statamicEntryId
    ): array {
        // ---
        // Run the body with libxml warnings visible, as it did before wordpress-importer-archive#1354 when
        // the load below switched the mode back off. A caller may arrive with it
        // on (a direct Bard parse elsewhere leaves it on); process() restores the
        // caller's mode afterwards, so only code inside the body can observe
        // this call.
        // ---
        // @pest-mutate-ignore: RemoveFunctionCall
        libxml_use_internal_errors(false);

        // Set the XML path in the processing context
        ProcessingContext::setXmlPath($xmlParser->getFilePath());

        // Set the current page link in the processing context
        ProcessingContext::setWordPressPageLink($pageLink);

        $data['post_name'] ??= $pageSlug ?? ($pageTitle !== '' ? $pageTitle : ($pageId !== '' ? $pageId : 'unknown'));

        // Set the entry data in the processing context
        ProcessingContext::setEntryData($data);

        // Set the collection handle in the processing context
        ProcessingContext::setCollectionHandle($collectionHandle);

        // Set the current Statamic entry ID in the processing context
        ProcessingContext::setStatamicEntryId($statamicEntryId);

        // Set the current WordPress post ID in the processing context
        ProcessingContext::setWordPressPostId($pageId);

        // Reset static variables to prevent cross-contamination between pages
        $this->pageBuilderParser->resetStaticVariables();

        // Check if the input content is empty or only whitespace
        if (\trim($content) === '') {
            // Return empty arrays for both content and page_builder to skip processing
            return ['content' => [], 'page_builder' => []];
        }

        // ---
        // Decode HTML entities for proper special-character handling, but leave the
        // interior of HTML comments untouched. WordPress block markers carry their
        // attributes as JSON inside the comment (e.g.
        // <!-- wp:acf/uams-content {"data":{…}} /-->); decoding entities there would
        // turn &quot; into a bare " and corrupt that JSON, so json_decode fails and
        // the block is silently dropped (issue wordpress-importer-archive#290). Field values inside the JSON
        // are still entity-decoded downstream when each block parser runs them
        // through TextProcessor::processForBard() or HtmlTagStripper::strip().
        // ---
        $content = TextProcessor::decodeEntitiesPreservingComments($content);

        // ---
        // Check for ACF blocks in the raw content string before loading into DOM.
        // This is more reliable than checking the DOM because comments inside elements
        // (e.g., inside wp:group divs) may not be preserved correctly when loaded into
        // a DOMDocument.
        // ---
        $hasAcfBlocks = $this->hasAcfBlocksInContent($content);

        // Initialize DOMDocument for parsing HTML content
        $doc = new DOMDocument(
            '1.0',
            'UTF-8'
        );
        // Wrap content in a full HTML structure to ensure valid parsing
        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>'.$content;
        $html .= '</body></html>';

        // ---
        // LIBXML_PARSEHUGE stays because real content needs it (wordpress-importer-archive#1273): three
        // inside.uams.edu/clinicaleducation pages carry runs of unclosed
        // <a><strong> that nest 318 to 786 elements deep, past libxml's default
        // 256, and without the flag their text is lost. Use LIBXML_NOCDATA only if
        // the content doesn't contain WordPress block comments.
        // ---
        // The wrapper above supplies the html and body elements and a doctype, so
        // these two flags change nothing it builds
        // @pest-mutate-ignore: BitwiseOrToBitwiseAnd
        $flags = LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD;
        $flags |= LIBXML_PARSEHUGE;

        // Check if content contains WordPress block comments that need CDATA
        // preservation. libxml's HTML parser creates no CDATA sections, so the flag
        // changes nothing it builds (measured in TextProcessor::loadHtml()).
        // @pest-mutate-ignore: IfNegated, RemoveNot
        if (! TextProcessor::containsWordPressBlockComments($html)) {
            // Add LIBXML_NOCDATA flag to disable CDATA parsing for non-block content
            $flags |= LIBXML_NOCDATA;
        }

        // ---
        // Suppress warnings for malformed HTML to handle WordPress content
        // gracefully, and restore the caller's mode afterwards on both paths
        // (wordpress-importer-archive#1354). The mode is process-wide; leaving it on would silence every
        // later libxml warning in the import.
        // ---
        // The load is @-suppressed, so turning internal errors off instead changes
        // nothing it reports; the mode is restored below either way
        // @pest-mutate-ignore: TrueToFalse
        $previousInternalErrors = libxml_use_internal_errors(true);

        try {
            // Load HTML with conditional CDATA handling
            $loaded = @$doc->loadHTML(
                $html,
                $flags
            );

            // Handle parsing errors. A non-empty document with its own html and body
            // elements always loads under @, so the body of this check never runs.
            // @pest-mutate-ignore: RemoveMethodCall, CoalesceRemoveLeft, ConcatRemoveLeft, ConcatRemoveRight, ConcatSwitchSides, UnwrapArrayMap, UnwrapTrim, RemoveArrayItem, RemoveEarlyReturn
            if (! $loaded) {
                Log::error(
                    "ContentProcessor::process: Failed to load HTML for site '$site' (slug: ".($pageSlug ?? 'none').') due to parsing errors',
                    [
                        'errors' => \array_map(
                            fn (\LibXMLError $error): string => \trim($error->message).' at line '.$error->line,
                            libxml_get_errors()
                        ),
                    ]
                );

                // Return empty arrays to skip further processing
                return ['content' => [], 'page_builder' => []];
            }
        } finally {
            // Clear libxml errors to prevent interference with future operations.
            // Restoring the body's mode below turns internal errors off, which
            // clears them as well.
            // @pest-mutate-ignore: RemoveFunctionCall
            libxml_clear_errors();

            // Put back the mode the body had. process() restores the caller's mode
            // in its own finally, so no test can observe this call except from
            // inside the body; it keeps warnings visible for the rest of the page.
            // @pest-mutate-ignore: RemoveFunctionCall
            libxml_use_internal_errors($previousInternalErrors);
        }

        // Read the body element from the parsed document
        $body = $doc->getElementsByTagName('body')->item(0);

        // Return empty arrays when the body element is missing. The wrapper above
        // always supplies one, so this never returns.
        if ($body === null) {
            // @pest-mutate-ignore: RemoveArrayItem, RemoveEarlyReturn
            return ['content' => [], 'page_builder' => []];
        }

        // Read direct child nodes from the body element
        $nodes = $body->childNodes;

        // ---
        // Check if blueprint is landing_page (which may have ACF blocks in referenced
        // wp_block posts)
        // ---
        $isLandingPage = ($data['blueprint'] ?? null) === 'landing_page';

        // Define blocks handled by Tiptap to exclude from unaddressed nodes
        $addressedBlocks = ['paragraph', 'heading', 'list', 'separator'];

        // Initialize content arrays
        $bardContent = [];
        $pageBuilderContent = [];

        // ---
        // Process content with ACF blocks
        //
        // For pages: process ACF blocks as page builder content
        //
        // For reusable_content: process ACF blocks as page builder content when using
        // reusable_content_landing_page blueprint
        //
        // For posts and other content types: process ACF blocks as Bard content
        //
        // Also use PageBuilderParser for landing_page/reusable_content_landing_page
        // blueprint even if direct ACF blocks aren't found (e.g., when ACF blocks are
        // in referenced wp_block posts)
        // ---

        // ---
        // Check if collection is reusable_content with reusable_content_landing_page
        // blueprint
        // ---
        $isReusableContentLandingPage =
            $collectionHandle === 'reusable_content'
            && ($data['blueprint'] ?? null) === 'reusable_content_landing_page';

        // ---
        // Check if content should be processed with PageBuilderParser (has ACF blocks or
        // uses landing_page blueprint) and collection supports page builder.
        //
        // Note: wp:group blocks are unwrapped during DOM processing by
        // WpGroupBlockProcessor (used by both PageBuilderParser via NonAcfBlockParser and
        // TextProcessor for Bard content). This preserves group attributes
        // (like align="wide") for table alignment functionality.
        // ---
        if (
            (
                $hasAcfBlocks
                || $isLandingPage
                || $isReusableContentLandingPage
            )
            && (
                $collectionHandle === 'pages'
                || $collectionHandle === 'reusable_content'
            )
        ) {
            // Track unaddressed blocks for shortcode suppression
            $unaddressedBlocks = [];
            // Process nodes for page_builder content
            $i = 0;

            // Visit each node in turn; the list returns null past its end
            while (($node = $nodes->item($i)) instanceof DOMNode) {
                // Parse node using PageBuilderParser to handle ACF blocks
                $result = $this->pageBuilderParser->parseNode(
                    $node,
                    $i,
                    $nodes,
                    $pageBuilderContent,
                    $site,
                    $assetImporter,
                    $output,
                    $attachments,
                    $terms,
                    $items,
                    $unaddressedBlocks,
                    $pageSlug
                );

                // Check for unhandled WordPress blocks (wp:*)
                if (
                    ($result['processed'] ?? false) !== true
                    && $node instanceof DOMComment
                    && \preg_match(
                        '/^wp:([^\s]+)/',
                        \trim($node->data),
                        $matches
                    ) === 1
                ) {
                    // Extract block type (e.g., 'tablepress/table')
                    $blockType = $matches[1];
                    // Skip blocks handled by Tiptap. A block type and these names are
                    // all non-numeric strings, so strict and loose comparison agree.
                    // @pest-mutate-ignore: TrueToFalse
                    if (
                        \in_array(
                            $blockType,
                            $addressedBlocks,
                            true
                        )
                    ) {
                        $i += self::nodeStep($result);

                        continue;
                    }

                    // Track unhandled block
                    $this->unaddressedNodes[$blockType][] = [
                        'id' => $pageId,
                        'slug' => $pageSlug,
                        'link' => $pageLink,
                    ];
                    $unaddressedBlocks[] = $blockType;

                    // Enhance log context with entry information
                    $logData = LogContextHelper::enhanceWithEntryContext(
                        [
                            'site' => $site,
                            'page_slug' => $pageSlug,
                            'block_type' => $blockType,
                            'node_value' => \substr(
                                $node->data,
                                0,
                                100
                            ).(\strlen($node->data) > 100 ? '...' : ''),
                        ]
                    );

                    // Log unhandled block
                    Log::warning(
                        LogContextHelper::formatMessageWithEntry(
                            "ContentProcessor::process: Unaddressed block '$blockType' found in page '$pageSlug' for site '$site'",
                            $logData['page_url']
                        ),
                        $logData['context']
                    );
                }

                // Increment index by skip count (for block pairs) or 1
                $i += self::nodeStep($result);
            }

            // ---
            // Final call to parseNode to trigger finalization logic, but only if the last
            // node is not an ACF block.
            //
            // This finalization logic addresses a specific issue with mixed ACF and non-ACF
            // content where accumulated non-ACF content between ACF blocks might not be
            // properly finalized and added to the page_builder array. The final call ensures
            // that any remaining non-ACF content is processed and included in the final
            // output.
            //
            // However, we must prevent duplication of ACF blocks by checking if the last node
            // is already an ACF block. If the last node is an ACF block, it has already been
            // processed during the main loop, and making another call would cause the block
            // to be duplicated. This check prevents the duplication issue while preserving
            // the mixed content finalization functionality.
            // ---
            // ---
            // Read the last node; an empty list has none
            $finalNode = $nodes->item($nodes->length - 1);
            $isLastNodeAcf = false;
            $isLastNodeProcessedBlock = false;

            // Check if the last node is an ACF block
            if ($finalNode instanceof DOMComment) {
                $comment = \trim($finalNode->data);
                $isLastNodeAcf = \preg_match(
                    '/^wp:acf\//',
                    $comment
                ) === 1;
                // ---
                // Also check if it's a non-ACF block that would be processed and return
                // skipCount > 0
                //
                // Include wp:block, wp:minervakb/*, and wp:rss to prevent reprocessing when it's
                // the last node.
                // ---
                $isLastNodeProcessedBlock = \preg_match(
                    '/^wp:(tablepress\/table|gravityforms\/form|formidable\/simple-form|shortcode|block|minervakb\/|rss)/',
                    $comment
                ) === 1;
            }

            // Check if the last node is a text node that contains shortcodes (would be
            // processed)
            $isLastNodeProcessedText = $finalNode instanceof DOMText
                && \preg_match(
                    '/\[(gravityform|formidable)\s+id=["\']?\d+["\']?[^]]*\]/',
                    $finalNode->textContent
                ) === 1;

            // ---
            // Only make the final call if the last node is not an ACF block AND not a
            // processed non-ACF block AND not processed text.
            //
            // This prevents duplication while ensuring proper finalization of mixed content.
            // ---
            if (
                $isLastNodeAcf === false
                && $isLastNodeProcessedBlock === false
                && $isLastNodeProcessedText === false
                && $finalNode instanceof DOMNode
            ) {
                $this->pageBuilderParser->parseNode(
                    $finalNode,
                    $nodes->length - 1,
                    $nodes,
                    $pageBuilderContent,
                    $site,
                    $assetImporter,
                    $output,
                    $attachments,
                    $terms,
                    $items,
                    $unaddressedBlocks,
                    $pageSlug
                );
            } elseif ($isLastNodeAcf) {
                // ---
                // Ensure finalization happens even when last node is an ACF block
                // This ensures that sets accumulated in PageBuilderParser::$orderedSets
                // are appended to pageBuilderContent without reprocessing the node
                // ---

                // Force finalization without reprocessing the node to avoid duplication
                $this->pageBuilderParser->forceFinalization(
                    $pageBuilderContent,
                    $site,
                    $assetImporter,
                    $output
                );
            }

            // ---
            // Always ensure finalization happens after processing all nodes. This ensures
            // that sets accumulated in PageBuilderParser::$orderedSets (e.g., from wp:block
            // blocks) are appended to pageBuilderContent. This is needed because wp:block
            // blocks add sets to $orderedSets but may not trigger finalization in parseNode
            // if the last node check fails.
            //
            // However, if the last node was an ACF block, forceFinalization was already
            // called above, so we skip calling it again to avoid duplication.
            // ---
            if ($isLastNodeAcf === false) {
                $this->pageBuilderParser->forceFinalization(
                    $pageBuilderContent,
                    $site,
                    $assetImporter,
                    $output
                );
            }

            // ---
            // Sanitize page_builder content to prevent YAML parsing issues with document
            // separators
            // ---
            $pageBuilderContent = $this->sanitizePageBuilderContent($pageBuilderContent);

            // Return page_builder content with empty Bard content
            return ['content' => [], 'page_builder' => $pageBuilderContent];
        }

        // Detect Gutenberg block comments (e.g., <!-- wp:list -->, <!-- wp:paragraph -->)
        $hasGutenbergBlocks = self::hasBlockComments($nodes);

        // ---
        // Only apply WpAutoP for Classic Editor content (no ACF blocks, no Gutenberg
        // blocks).
        //
        // If content is Classic Editor (no ACF, no Gutenberg blocks), apply WpAutoP.
        //
        // If content is Gutenberg blocks (but not ACF), do NOT apply WpAutoP.
        // ---
        $applyWpAutoP = ! $hasAcfBlocks && ! $hasGutenbergBlocks;

        // ---
        // Process content as Bard content if:
        // 1. No ACF blocks, OR
        // 2. ACF blocks but collection handle is NOT 'page' (posts and other content
        //    types should use Bard content)
        // ---
        if (
            ! $hasAcfBlocks
            || $collectionHandle !== 'page'
        ) {
            $bardContent = TextProcessor::processForBard(
                $content,
                $site,
                $assetImporter,
                $output,
                $applyWpAutoP, // Only true for Classic Editor content
                false,
                $attachments,
                $terms,
                $pageSlug,
                $statamicEntryId,
                $collectionHandle,
                [], // preProcessedNodes
                $xmlParser->getFilePath() // Pass XML path for link resolution
            );
        }

        // Sanitize Bard content to prevent YAML parsing issues with document separators
        $bardContent = $this->sanitizeBardFieldContent($bardContent);

        // Return processed Bard content with empty page_builder array
        return ['content' => $bardContent, 'page_builder' => []];
    }

    /**
     * The number of nodes to move past after a page builder parse.
     *
     * @param  array<int|string, mixed>  $result  The parse result
     * @return int The result's skip count, or 1 when it has none
     */
    private static function nodeStep(
        array $result
    ): int {
        $skipCount = $result['skipCount'] ?? null;

        return \is_int($skipCount) && $skipCount !== 0 ? $skipCount : 1;
    }

    /**
     * Checks whether any of the nodes is a Gutenberg block comment.
     *
     * @param  DOMNodeList<DOMNode>  $nodes  The nodes to check
     * @return bool True when a `wp:` block comment is among them
     */
    private static function hasBlockComments(
        DOMNodeList $nodes
    ): bool {
        foreach ($nodes as $node) {
            if (
                $node instanceof DOMComment
                && \preg_match(
                    '/^wp:[^\s]+/',
                    \trim($node->data)
                ) === 1
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Gets unaddressed nodes for reporting.
     *
     * Merges unaddressed nodes from this processor and the shortcode processor.
     *
     * @return array<string, array<array{id: string, slug: ?string, link: string, original?: string}>>
     */
    public function getUnaddressedNodes(): array
    {
        // Initialize merged output with processor-level nodes
        $mergedNodes = $this->unaddressedNodes;

        // Merge shortcode processor nodes by block type
        foreach ($this->shortcodeProcessor->getUnaddressedNodes() as $type => $nodes) {
            $mergedNodes[$type] ??= [];

            // Merge node entries for this block type
            foreach ($nodes as $nodeInfo) {
                $mergedNodes[$type][] = $nodeInfo;
            }
        }

        // Return merged unaddressed node map
        return $mergedNodes;
    }

    /**
     * Sanitizes page builder content to prevent YAML parsing issues.
     *
     * Recursively processes page builder content arrays and sanitizes any Bard
     * fields (typically in 'body' or 'content' fields) to prevent YAML document
     * separator issues.
     *
     * @param  array<int|string, mixed>  $content  The page builder content array to sanitize
     * @return array<int|string, mixed> The sanitized page builder content
     */
    private function sanitizePageBuilderContent(
        array $content
    ): array {
        $sanitized = [];

        foreach ($content as $item) {
            if (! \is_array($item)) {
                $sanitized[] = $item;

                continue;
            }

            // Sanitize Bard fields that commonly appear in page builder blocks
            $bardFields = ['body', 'content', 'text', 'description'];
            foreach ($bardFields as $field) {
                if (isset($item[$field]) && \is_array($item[$field])) {
                    $item[$field] = $this->sanitizeBardFieldContent($item[$field]);
                }
            }

            // Recursively sanitize nested content arrays
            if (isset($item['content']) && \is_array($item['content'])) {
                $item['content'] = $this->sanitizePageBuilderContent($item['content']);
            }

            $sanitized[] = $item;
        }

        return $sanitized;
    }

    /**
     * Sanitizes a Bard field value with string-keyed wrapper normalization.
     *
     * @param  array<int|string, mixed>  $bardField  The Bard field payload
     * @return array<int|string, mixed> The sanitized Bard field payload
     */
    private function sanitizeBardFieldContent(array $bardField): array
    {
        // ---
        // Sanitize the Bard node list directly. BardContentSanitizer::sanitize()
        // takes and returns a list of Bard nodes, escaping YAML separators in each
        // text node. It must NOT be wrapped in a ['content' => ...] envelope: the
        // sanitizer re-indexes its output to a numeric list (so the 'content' key
        // never survives) and, because the wrapped list has no top-level 'type' or
        // 'content' key, the inner text nodes are never visited at all. The earlier
        // wrapper therefore both dropped the key — flattening every populated body
        // to [] — and silently skipped sanitization (wordpress-importer-archive#392).
        // ---

        // Sanitize the Bard field; coerce a null result (null/empty input) to an array
        $sanitizedField = $this->bardContentSanitizer->sanitize($bardField);

        // Return the sanitized list, or an empty array when nothing came back
        return $sanitizedField ?? [];
    }
}
