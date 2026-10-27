<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators;

use DateTime;
use Illuminate\Support\Facades\File as LaravelFileFacade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Statamic\Entries\Entry;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Stache\Query\EntryQueryBuilder;
use Symfony\Component\Console\Output\OutputInterface;
use Tiptap\Editor;
use Tiptap\Nodes\Document;
use Tiptap\Nodes\Paragraph;
use Tiptap\Nodes\Text;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\ContentStreamFactory;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlStreamParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentRoot;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\EntryQueryHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\HtmlTagStripper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportProgress;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\TablePressTableDataRepairer;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;

/**
 * Converts WordPress 'tablepress_table' content into Statamic entries.
 *
 * This class processes WordPress XML export data for the 'tablepress_table' post
 * type, mapping it to entries in the Statamic 'tables' collection based on the
 * 'table.yaml' blueprint. It handles JSON-encoded table data from
 * `<content:encoded>`, table options from `_tablepress_table_options`, and
 * Bard-formatted descriptions from `<excerpt:encoded>`. Slugs are generated as
 * `{site}_tablepress-{tableExportId}`, and IDs are unique as
 * `{site}_tablepress-{tableExportId}-{postId}`.
 *
 * The class also processes the '_tablepress_table_visibility' meta key to filter
 * out hidden rows and columns from the table data. Rows and columns marked as
 * hidden (0) in the visibility arrays are removed from the final table entry.
 */
class TablePressEntryCreator extends BaseEntryCreator
{
    /**
     * TablePress Premium feature gates with no destination on the Statamic table.
     *
     * Keyed by the `_tablepress_table_options` key that switches the feature on,
     * valued by the name TablePress gives it in its own UI so a warning reads the
     * way an editor's TablePress screen does.
     *
     * The two Premium modules that DO have destinations — Column Filter Dropdowns
     * (`datatables_columnfilterwidgets`) and Responsive Tables stacking
     * (`responsive`) — are deliberately absent: mapTableOptions() imports both.
     * `responsive` is still checked separately, because only two of its four modes
     * reach a Statamic field.
     *
     * Corpus state (1,392 tables carrying an options payload, measured 2026-08-03):
     * every gate below is off everywhere except `datatables_column_filter`, set to
     * `input` on one table, and `datatables_columnfilterwidgets_group_terms` on
     * another. Premium options appear on 24 tables across 3 sites at all (wordpress-importer-archive#21).
     *
     * @var array<string, string>
     */
    private const array UNSUPPORTED_PREMIUM_FEATURES = [
        'datatables_auto_filter' => 'Automatic Filtering',
        'datatables_buttons' => 'Export Buttons',
        'datatables_column_filter' => 'Individual Column Filtering',
        'datatables_columnfilterwidgets_group_terms' => 'Column Filter Dropdowns (grouped terms)',
        'datatables_custom_commands' => 'Custom DataTables Commands',
        'datatables_fixedcolumns' => 'Fixed Columns',
        'datatables_fixedheader' => 'Fixed Header',
        'datatables_fuzzysearch' => 'Fuzzy Search',
        'datatables_inverted_filter' => 'Inverted Filtering',
        'datatables_pagination_loadmore_button' => 'Load More Button',
        'datatables_scrollx_buttons' => 'Horizontal Scrolling Buttons',
        'datatables_searchbuilder' => 'Search Builder',
        'datatables_searchhighlight' => 'Search Highlighting',
        'datatables_serverside_processing' => 'Server-side Processing',
        'filter' => 'Row Filtering',
        'responsive_scroll_buttons' => 'Responsive Scrolling Buttons',
        'row_highlight' => 'Cell Highlighting',
    ];

    /**
     * Overwrite behavior for table entries.
     *
     * @var string
     */
    protected $overwriteBehavior = 'Overwrite matching files';

    /**
     * Cache of imported table entry IDs.
     *
     * @var array<string, string>
     */
    protected static $tableCache = [];

    /**
     * Sets the overwrite behavior for table entry imports.
     *
     * Configures how existing entries with matching IDs are handled: overwrite,
     * skip, or create additional entries with modified slugs.
     *
     * @param  string  $behavior  The behavior: 'Overwrite matching files', 'Skip matching files', or
     *                            'Create an additional file'
     */
    public function setOverwriteBehavior(
        string $behavior
    ): void {
        $this->overwriteBehavior = $behavior;
    }

    /**
     * Clears the table entry cache.
     *
     * Resets the cache of imported table entry IDs to prevent reuse across import
     * runs.
     */
    public static function clearTableCache(): void
    {
        self::$tableCache = [];
    }

    /**
     * Creates Statamic entries from WordPress 'tablepress_table' content.
     *
     * Iterates through 'tablepress_table' items in the XML, extracting relevant
     * fields (e.g., title, status, JSON table data) and mapping them to the
     * 'tables' collection blueprint. Generates slugs as
     * `{site}_tablepress-{tableExportId}` and IDs as
     * `{site}_tablepress-{tableExportId}-{postId}`, processes table options, and
     * formats descriptions as Bard content. Skips trashed items and logs warnings
     * for missing '_tablepress_export_table_id'. Ensures no duplicate entries are
     * created with incorrect slugs by enforcing correct slug format.
     *
     * @param  string  $site  Site handle (e.g., 'default') for entry localization
     * @param  string  $collectionHandle  Collection handle ('tables')
     * @param  XmlParser  $xmlParser  Parses WordPress XML export data
     * @param  AssetImporter  $assetImporter  Handles asset imports (unused here)
     * @param  OutputInterface  $output  Console output for logging progress
     * @param  MetadataMapper  $metadataMapper  Maps additional metadata to entries
     * @param  ContentProcessor  $contentProcessor  Processes content (unused here)
     * @param  array<int|string, mixed>|null  $minervaKbSettings  Optional MinervaKB settings from JSON file
     * @return array<int|string, mixed> Page data for tree building, mapping post IDs to entry IDs
     */
    public function createEntries(
        string $site,
        string $collectionHandle,
        XmlParser $xmlParser,
        AssetImporter $assetImporter,
        OutputInterface $output,
        MetadataMapper $metadataMapper,
        ContentProcessor $contentProcessor,
        ?array $minervaKbSettings = null
    ): array {
        // Check if XML parser has specific items set (for individual table processing)
        $tablesToProcess = $xmlParser->getItems('tablepress_table');

        // Initialize streaming parser variable for potential use
        $xmlStreamParser = null;

        // Check if no specific items are set, indicating we should process all tables
        if ($tablesToProcess === []) {
            // Open a streaming parser spanning every part. A source that can serve
            // none raises rather than importing nothing and reporting success (wordpress-importer-archive#662).
            $xmlStreamParser = ContentStreamFactory::open(
                $xmlParser,
                'TablePressEntryCreator::createEntries'
            );

            // Get all tablepress_table items using the streaming parser
            $tablesToProcess = $xmlStreamParser->streamItems('tablepress_table');
        }

        // A stream is counted only if its progress will print
        $tableCount = $xmlStreamParser instanceof XmlStreamParser
            ? static fn (): int => $xmlStreamParser->countItems('tablepress_table')
            : null;

        // Loop through each table item to process and create entries
        foreach (ImportProgress::track($output, 'tablepress_table', $tablesToProcess, $tableCount) as $table) {
            // Decode HTML entities and strip HTML tags from title
            $title = HtmlTagStripper::strip(
                html_entity_decode(
                    (string) $table->title,
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                )
            );

            // Extract the table status from WordPress XML
            $status = XpathHelper::getStringValue(
                $table,
                'wp:status'
            );

            // Extract the table password from WordPress XML
            $postPassword = XpathHelper::getStringValue(
                $table,
                'wp:post_password'
            );

            // Extract the WordPress post ID from XML
            $postId = XpathHelper::getStringValue(
                $table,
                'wp:post_id'
            );

            // Extract the encoded content from WordPress XML
            $contentEncoded = XpathHelper::getStringValue(
                $table,
                'content:encoded'
            );

            // Extract the encoded excerpt from WordPress XML
            $excerptEncoded = XpathHelper::getStringValue(
                $table,
                'excerpt:encoded'
            );

            // Extract post date with fallback to pubDate
            $postDate = $this->extractPostDateString($table);

            // Get the original WordPress permalink URL
            $originalWordPressUrl = (string) $table->link;

            // Set the WordPress page link in processing context for accurate logging
            ProcessingContext::setWordPressPageLink($originalWordPressUrl);

            // Check if status should be skipped
            if (XpathHelper::shouldSkipStatus($status)) {
                // Skip to next item in loop
                continue;
            }

            // Initialize table export ID for TablePress table identification
            $tableExportId = '';

            // Loop through postmeta to find TablePress export table ID
            foreach (XpathHelper::xpathElements($table, 'wp:postmeta') as $meta) {
                // Extract the meta key from WordPress postmeta
                $key = XpathHelper::getStringValue(
                    $meta,
                    'wp:meta_key'
                );

                // Check if this is the TablePress export table ID meta key
                if ($key === '_tablepress_export_table_id') {
                    // Extract the table export ID from the meta value
                    $tableExportId = XpathHelper::getStringValue(
                        $meta,
                        'wp:meta_value'
                    );

                    // Break out of the loop once we find the table ID
                    break;
                }
            }

            // Log and skip if export ID is missing
            if ($tableExportId === '') {
                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'site' => $site,
                        'title' => $title !== '' ? $title : 'Untitled Table',
                    ],
                    $originalWordPressUrl
                );

                // Log warning for missing export table ID
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        "TablePressEntryCreator::createEntries: Missing _tablepress_export_table_id for table ID '$postId', skipping.",
                        $logData['page_url']
                    ),
                    $logData['context']
                );

                continue;
            }

            // Create base slug combining site and export ID
            $baseSlug = "{$site}_tablepress-{$tableExportId}";

            // Set default entry data based on 'table.yaml' blueprint
            $data = [
                'blueprint' => 'table',
                'title' => $title !== '' ? $title : 'Untitled Table',
                'caption' => $title !== '' ? $title : 'Untitled Table',
                'first_row_headers' => true,
                'first_column_headers' => false,
                'fixed_width_table_cells' => false,
                'sorting_enable' => false,
                'search_enable' => false,
                'pagination_enable' => false,
                'info_enable' => false,
                'horizontal_scroll_enable' => true,
                'original_wordpress_url' => $originalWordPressUrl,
            ];

            // Map WordPress status and visibility to Statamic fields
            $isScheduledPost = $this->mapStatusAndVisibility(
                $status,
                $postPassword,
                $postDate,
                $data,
                $collectionHandle,
                $output,
                wpmemBlock: $this->readWpMembersBlock($table)
            );

            // Generate unique slug and ID for the entry
            [$entrySlug, $entryId] = $this->generateSlugAndId(
                $baseSlug,
                $postId,
                $title
            );
            $entryDate = $this->createEntryDate(
                $postDate,
                $isScheduledPost
            );
            $data['id'] = $entryId;

            // Log progress for the current table
            $output->writeln("Processing table '$entrySlug' with content length: ".\strlen($contentEncoded));

            // Convert excerpt to Bard format for 'description' field
            if ($excerptEncoded !== '') {
                $editor = new Editor([
                    'extensions' => [
                        new Document,
                        new Paragraph,
                        new Text,
                    ],
                ]);
                $html = html_entity_decode(
                    $excerptEncoded,
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                );
                $document = $editor->setContent($html)->getDocument();
                if (
                    \is_array($document)
                    && isset($document['content'])
                    && \is_array($document['content'])
                    && $document['content'] !== []
                ) {
                    $data['description'] = $document['content'];
                }
            }

            // Parse JSON table data from content:encoded
            $tableData = [];
            if ($contentEncoded !== '') {
                // Decode the payload, repairing unescaped HTML-attribute quotes when needed
                $decodeResult = TablePressTableDataRepairer::decode($contentEncoded);
                $decoded = $decodeResult['data'];

                // Note when a repair pass recovered an otherwise-dropped table
                if ($decodeResult['repaired']) {
                    $output->writeln(
                        "Repaired unescaped HTML-attribute quotes in table '$entrySlug' (ID: $postId)"
                    );
                }

                if (\is_array($decoded) && $decoded !== []) {
                    $rows = $decoded;
                    // Map each row to the blueprint's table_data structure
                    foreach ($rows as $row) {
                        if (! \is_array($row)) {
                            // Enhance log context with entry information
                            $logData = LogContextHelper::enhanceWithEntryContext(
                                [
                                    'row' => $row,
                                    'table_id' => $postId,
                                    'slug' => $entrySlug,
                                ],
                                $originalWordPressUrl
                            );

                            // Log warning for invalid row data
                            Log::warning(
                                LogContextHelper::formatMessageWithEntry(
                                    'TablePressEntryCreator::createEntries: Invalid row data, expected array of cells',
                                    $logData['page_url']
                                ),
                                $logData['context']
                            );

                            continue;
                        }

                        $tableData[] = [
                            'cells' => \array_values(
                                \array_map(
                                    fn ($cell): mixed => $cell === '' ? null : $cell,
                                    $row
                                )
                            ),
                        ];
                    }
                } else {
                    // Enhance log context with entry information and parse diagnostics
                    $logData = LogContextHelper::enhanceWithEntryContext(
                        [
                            'content' => \substr(
                                $contentEncoded,
                                0,
                                100
                            ).'...',
                            'decoded' => $decoded,
                            'json_error' => $decodeResult['error'],
                            'json_error_offset' => $decodeResult['offset'],
                        ],
                        $originalWordPressUrl
                    );

                    // Start from the base warning for an empty or unparseable table
                    $warningMessage = "TablePressEntryCreator::createEntries: Invalid table data JSON for table '$entrySlug' (ID: $postId)";

                    // Append the parse error (and offset) when the payload failed to decode
                    if ($decodeResult['error'] !== null) {
                        $warningMessage .= ": {$decodeResult['error']}";

                        // Include the failure offset so the next defect class can be located
                        if ($decodeResult['offset'] !== null) {
                            $warningMessage .= " at byte offset {$decodeResult['offset']}";
                        }
                    }

                    // Log warning for invalid JSON data
                    Log::warning(
                        LogContextHelper::formatMessageWithEntry(
                            $warningMessage,
                            $logData['page_url']
                        ),
                        $logData['context']
                    );
                }
            }

            // Initialize table options array for TablePress configuration
            $tableOptions = [];

            // Initialize table visibility array for filtering hidden rows/columns
            $tableVisibility = [];

            // Loop through postmeta to find TablePress table options and visibility
            foreach (XpathHelper::xpathElements($table, 'wp:postmeta') as $meta) {
                // Extract the meta key from WordPress postmeta
                $key = XpathHelper::getStringValue(
                    $meta,
                    'wp:meta_key'
                );

                // Check if this is the TablePress table options meta key
                if ($key === '_tablepress_table_options') {
                    // Extract the table options JSON from the meta value
                    $optionsJson = XpathHelper::getStringValue(
                        $meta,
                        'wp:meta_value'
                    );

                    // Decode the JSON options with fallback to empty array
                    $decodedTableOptions = \json_decode(
                        $optionsJson,
                        true
                    );
                    $tableOptions = \is_array($decodedTableOptions) ? $decodedTableOptions : [];
                }

                // Check if this is the TablePress table visibility meta key
                if ($key === '_tablepress_table_visibility') {
                    // Extract the table visibility JSON from the meta value
                    $visibilityJson = XpathHelper::getStringValue(
                        $meta,
                        'wp:meta_value'
                    );

                    // Decode the JSON visibility data with fallback to empty array
                    $decodedTableVisibility = \json_decode(
                        $visibilityJson,
                        true
                    );
                    $tableVisibility = \is_array($decodedTableVisibility) ? $decodedTableVisibility : [];
                }
            }

            // Track if we added a header row for visibility filtering
            $addedHeaderRow = false;

            // Add empty header row if table_head is false
            if (
                $tableData !== []
                && ! $this->getBooleanOption(
                    $tableOptions,
                    'table_head',
                    true
                )
            ) {
                // Get column count from first row
                $columnCount = \count($tableData[0]['cells']);

                // Add empty header row at beginning of table data
                array_unshift(
                    $tableData,
                    [
                        'cells' => \array_fill(
                            0,
                            $columnCount,
                            null
                        ),
                    ]
                );

                // Mark that header row was added
                $addedHeaderRow = true;
            }

            // Check if table data and visibility data are both available for filtering
            if (
                $tableData !== []
                && $this->isTableVisibilityArray($tableVisibility)
            ) {
                // Apply visibility filtering to remove hidden rows and columns
                $tableData = $this->filterHiddenRowsAndColumns(
                    $tableData,
                    $tableVisibility,
                    $entrySlug,
                    $postId,
                    $addedHeaderRow
                );
            }

            // Assign table_data if valid
            if ($tableData !== []) {
                $data['table_data'] = $tableData;
            }

            // Map table options to blueprint fields, reporting anything Premium
            // this table switched on that the Statamic table cannot carry. wordpress-importer-archive#21
            if ($tableOptions !== []) {
                $data = \array_merge(
                    $data,
                    $this->mapTableOptions(
                        $tableOptions,
                        $entrySlug,
                        $postId
                    )
                );
            }

            // Map additional metadata (currently none)
            $metadataMapper->mapMetadata(
                $table,
                $data,
                $xmlParser,
                $assetImporter,
                [],
                $output
            );

            // Add updated_at field from post modified date
            $this->addUpdatedAtField(
                $data,
                $table,
                'TablePressEntryCreator::createEntries'
            );

            // Save the entry with the generated data
            $entry = $this->saveEntry(
                $collectionHandle,
                $site,
                $entrySlug,
                $data,
                $output,
                $entryDate,
                null,
                $postDate
            );
            if ($entry instanceof Entry) {
                // Store page data for tree building
                $this->addPageData(
                    $postId,
                    $entryId,
                    '0',
                    0,
                    $title !== '' ? $title : 'Untitled Table',
                    null,
                    $entry
                );
            }
        }

        // Check if streaming parser was used and clean it up to prevent memory leaks
        if ($xmlStreamParser instanceof XmlStreamParser) {
            // Clean up variables to free up memory
            unset($xmlStreamParser);
        }

        // Return collected page data for tree building
        return $this->pageData;
    }

    /**
     * Saves a Statamic entry with overwrite behavior handling.
     *
     * Checks for existing entries with matching IDs or slugs and applies the
     * configured overwrite behavior: overwrite, skip, or create additional entries
     * with modified slugs. Deletes any entries with incorrect slugs
     * ({site}_tablepress-{tableExportId}-{postId}) to prevent duplicates. Caches
     * the entry ID for reuse in the current import run. Determines the published
     * status from status_and_visibility if $published is null, defaulting to true
     * for TablePress entries when the field is absent, as tables are typically
     * published.
     *
     * Checks the collection configuration to determine if dates are enabled
     * (`date: true`). If the collection is dated, sets the entry date to ensure
     * entries are created with dated filenames (e.g., `2025-11-05.foo.md`). If the
     * collection is not dated, entries are created with non-dated filenames (e.g.,
     * `foo.md`).
     *
     * @param  string  $collectionHandle  The collection handle ('tables')
     * @param  string  $site  The site handle
     * @param  string  $entrySlug  The entry slug ({site}_tablepress-{tableExportId})
     * @param  array<int|string, mixed>  $data  The entry data
     * @param  OutputInterface  $output  Console output for logging
     * @param  ?DateTime  $entryDate  The entry date object or null
     * @param  ?bool  $published  Whether the entry is published, or null to infer
     * @param  ?string  $postDateStr  Optional WordPress post date string to use if collection is dated but
     *                                $entryDate is null
     * @return ?Entry The created entry object, or null if skipped
     */
    protected function saveEntry(
        string $collectionHandle,
        string $site,
        string $entrySlug,
        array $data,
        OutputInterface $output,
        ?DateTime $entryDate = null,
        ?bool $published = null,
        ?string $postDateStr = null
    ): ?Entry {
        // Validate and normalize the entry ID before saving
        if (! isset($data['id']) || ! \is_string($data['id'])) {
            Log::warning(
                'TablePressEntryCreator::saveEntry: Missing or invalid entry ID in data payload.'
            );

            return null;
        }

        $entryId = $data['id'];

        // Initialize the Statamic entry
        $collection = CollectionFacade::findByHandle($collectionHandle);
        $entry = EntryFacade::make();
        if (! $entry instanceof Entry) {
            throw new \RuntimeException(
                'TablePressEntryCreator::saveEntry: Failed to instantiate Statamic entry.'
            );
        }

        $entry->collection($collection);
        $entry->locale($site);
        $entry->slug($entrySlug);
        $entry->id($entryId);

        // Determine published status: use status_and_visibility if present,
        // otherwise default to true for TablePress entries
        if ($published === null) {
            $published = isset($data['status_and_visibility']) && \in_array(
                $data['status_and_visibility'],
                ['published', 'private', 'scheduled'],
                true
            );
            if (! isset($data['status_and_visibility'])) {
                $published = true; // Fallback for TablePress: tables are typically published
            }
        }

        $entry->published($published);

        // Extract export ID and post ID from entry ID
        $parts = \explode(
            '_tablepress-',
            $entryId
        );
        if (\count($parts) === 2) {
            // Handle new 3-part ID format: {site}_tablepress-{exportId}-{postId}-{random}
            $remainingParts = \explode(
                '-',
                $parts[1]
            );
            $exportId = $remainingParts[0];
            $postId = $remainingParts[1];
            $incorrectSlug = "{$site}_tablepress-{$exportId}-{$postId}";
            // Resolve on the tree this run writes to, not the live submodule
            $incorrectPath = ContentRoot::collectionEntry(
                'tables',
                $site,
                $incorrectSlug
            );
            // Delete any incorrect slug file
            if (LaravelFileFacade::exists($incorrectPath)) {
                LaravelFileFacade::delete($incorrectPath);
            }
        }

        // Check for existing entry with matching ID or slug
        $existingByIdQuery = $this->makeEntryQuery();
        $existingByIdQuery->where(
            'collection',
            $collectionHandle
        );
        $existingByIdQuery->where(
            'id',
            $entryId
        );
        $existingById = $existingByIdQuery->first();

        $existingBySlugQuery = $this->makeEntryQuery();
        $existingBySlugQuery->where(
            'collection',
            $collectionHandle
        );
        $existingBySlugQuery->where(
            'slug',
            $entrySlug
        );
        $existingBySlugQuery->where(
            'id',
            '!=',
            $entryId
        );
        $existingBySlug = $existingBySlugQuery->first();

        // Handle overwrite behavior
        if ($existingById || $existingBySlug) {
            switch ($this->overwriteBehavior) {
                case 'Skip matching files':
                    $output->writeln("<comment>Skipped entry '$entrySlug' as it already exists.</comment>");

                    return null;
                case 'Create an additional file':
                    $counter = 1;
                    $newSlug = $entrySlug;
                    while (
                        $this->countEntriesBySlug(
                            $collectionHandle,
                            $newSlug
                        ) > 0
                    ) {
                        $newSlug = "$entrySlug.$counter";
                        $counter++;
                    }

                    $entry->slug($newSlug);
                    $entryId = $this->generateUniqueId(
                        $newSlug,
                        $entryId
                    );
                    $data['id'] = $entryId;
                    $entry->data($data);
                    break;
                case 'Overwrite matching files':
                    if ($existingById instanceof Entry) {
                        $existingById->delete();
                    }

                    if ($existingBySlug instanceof Entry) {
                        $existingBySlug->delete();
                    }

                    break;
            }
        }

        // Check if the collection is dated based on configuration
        $isCollectionDated = $collection !== null && $collection->dated();

        // Determine the date to use for the entry
        $finalEntryDate = $entryDate;

        // If collection is dated but no entry date is provided, create one
        if (
            $isCollectionDated
            && ! $finalEntryDate instanceof DateTime
        ) {
            // Try to create date from post date string if provided
            if ($postDateStr !== null && $postDateStr !== '') {
                try {
                    $finalEntryDate = new DateTime($postDateStr);
                } catch (\Exception $e) {
                    // Enhance log context with entry information
                    $logData = LogContextHelper::enhanceWithEntryContext(
                        [
                            'collection_handle' => $collectionHandle,
                            'post_date_str' => $postDateStr,
                            'error' => $e->getMessage(),
                        ]
                    );

                    // Log warning for failed date parsing
                    Log::warning(
                        LogContextHelper::formatMessageWithEntry(
                            'TablePressEntryCreator::saveEntry: Failed to parse post date string',
                            $logData['page_url']
                        ),
                        $logData['context']
                    );
                }
            }

            $finalEntryDate ??= new DateTime;
        }

        // Set the date on the entry if we have one
        if ($finalEntryDate instanceof DateTime) {
            // Only set date if the collection is dated
            if ($isCollectionDated) {
                $entry->date($finalEntryDate);
            } else {
                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'collection_handle' => $collectionHandle,
                        'collection_dated' => $isCollectionDated,
                    ]
                );

                // Log warning for skipping date setting
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        'TablePressEntryCreator::saveEntry: Skipping date setting - collection not dated',
                        $logData['page_url']
                    ),
                    $logData['context']
                );
            }
        }

        // Save the entry
        $entry->data($data);
        $entry->save();

        $output->writeln("  - <info>Saving entry with slug '$entrySlug' for collection '$collectionHandle'</info>");

        // Cache the entry ID
        $savedEntryId = $entry->id();
        self::$tableCache[$entryId] = \is_string($savedEntryId)
            ? $savedEntryId
            : $entryId;

        return $entry;
    }

    /**
     * Generates a unique ID for an entry.
     *
     * Combines the slug and a UUID to ensure uniqueness, preserving the original
     * ID structure for table entries.
     *
     * @param  string  $slug  The entry slug
     * @param  string  $originalId  The original ID
     * @return string The unique ID
     */
    protected function generateUniqueId(
        string $slug,
        string $originalId
    ): string {
        return $slug.'-'.Str::uuid();
    }

    /**
     * Indicates if the content type uses 'show-people' shortcodes.
     *
     * Returns false, as 'tablepress_table' content does not use 'show-people'
     * shortcodes, which are specific to other content types like pages.
     *
     * @return bool Always false for this content type
     */
    public function hasShowPeople(): bool
    {
        return false;
    }

    /**
     * Retrieves post categories for taxonomy validation.
     *
     * Returns an empty array, as 'tablepress_table' content does not use post
     * categories, which are relevant for content types like 'post'.
     *
     * @return array<int|string, mixed> Empty array, as no categories are applicable
     */
    public function getPostCategories(): array
    {
        return [];
    }

    /**
     * Retrieves post tags for taxonomy validation.
     *
     * Returns an empty array, as 'tablepress_table' content does not use post
     * tags, which are relevant for content types like 'post'.
     *
     * @return array<int|string, mixed> Empty array, as no tags are applicable
     */
    public function getPostTags(): array
    {
        return [];
    }

    /**
     * Validates taxonomy terms for TablePress entries.
     *
     * TablePress entries don't use any taxonomies, so this method does nothing.
     * This method is required by the abstract parent class.
     *
     * @param  string  $collectionHandle  The collection handle
     * @param  string  $site  The Statamic site handle
     * @param  OutputInterface  $output  Console output for logging
     */
    public function validateTaxonomies(
        string $collectionHandle,
        string $site,
        OutputInterface $output
    ): void {
        // TablePress entries don't use any taxonomies, so no validation is needed
    }

    /**
     * Filters hidden rows and columns from table data based on visibility settings.
     *
     * Removes rows and columns marked as hidden (0) in the visibility array.
     * Logs warnings for invalid visibility data and provides detailed information
     * about the filtering process for debugging purposes. Handles cases where
     * a header row was added programmatically by adjusting visibility indices.
     *
     * @param  list<array{cells: list<mixed>}>  $tableData  The table data array with 'cells' structure
     * @param  array{rows: array<int, int>, columns: array<int, int>}  $visibility  The visibility
     *                                                                              data with row and column arrays
     * @param  string  $entrySlug  The entry slug for logging context
     * @param  string  $postId  The WordPress post ID for logging context
     * @param  bool  $addedHeaderRow  Whether a header row was added programmatically
     * @return list<array{cells: list<mixed>}> The filtered table data
     */
    private function filterHiddenRowsAndColumns(
        array $tableData,
        array $visibility,
        string $entrySlug,
        string $postId,
        bool $addedHeaderRow = false
    ): array {
        // Extract rows visibility array from visibility data
        $rowsVisibility = $visibility['rows'];

        // Extract columns visibility array from visibility data
        $columnsVisibility = $visibility['columns'];

        // Check if visibility arrays contain only valid values (0 or 1)
        if (
            ! $this->isValidVisibilityArray($rowsVisibility)
            || ! $this->isValidVisibilityArray($columnsVisibility)
        ) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'rows_visibility' => $rowsVisibility,
                    'columns_visibility' => $columnsVisibility,
                    'expected_values' => [0, 1],
                ]
            );

            // Log warning about invalid values and return original data
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    "TablePressEntryCreator::filterHiddenRowsAndColumns: Invalid visibility values for table '$entrySlug' (ID: $postId)",
                    $logData['page_url']
                ),
                $logData['context']
            );

            // Return original table data unchanged
            return $tableData;
        }

        // Calculate original table dimensions for logging purposes
        $originalRowCount = \count($tableData);

        // Calculate original column count from first row if table has data
        $originalColumnCount = $tableData !== [] ? \count($tableData[0]['cells']) : 0;

        // Initialize array to store filtered table data
        $filteredTableData = [];

        // Loop through each row in the original table data
        foreach ($tableData as $rowIndex => $row) {
            // Calculate the visibility index, accounting for added header row
            $visibilityIndex = $addedHeaderRow ? $rowIndex - 1 : $rowIndex;

            // Check if this is the added header row
            if (
                $addedHeaderRow
                && $rowIndex === 0
            ) {
                // Always include added header row in filtered data
                $filteredTableData[] = $row;

                // Skip visibility check for added header row
                continue;
            }

            // Check if this row should be visible (1) or hidden (0)
            if (
                $visibilityIndex >= 0
                && isset($rowsVisibility[$visibilityIndex])
                && $rowsVisibility[$visibilityIndex] === 1
            ) {
                // Row is visible, keep it
                $filteredTableData[] = $row;
            }
        }

        // Loop through each row in the filtered table data to filter columns
        foreach ($filteredTableData as $rowIndex => $row) {
            // Initialize array to store visible cells for this row
            $filteredCells = [];

            // Loop through each cell in the current row
            foreach ($row['cells'] as $columnIndex => $cell) {
                // Check if this column should be visible (1) or hidden (0)
                if (
                    isset($columnsVisibility[$columnIndex])
                    && $columnsVisibility[$columnIndex] === 1
                ) {
                    // Column is visible, keep the cell
                    $filteredCells[] = $cell;
                }
            }

            // Update the row with only visible cells
            $filteredTableData[$rowIndex]['cells'] = $filteredCells;
        }

        // Calculate filtered table dimensions for logging purposes
        $filteredRowCount = \count($filteredTableData);

        // Calculate filtered column count from first row if table has data
        $filteredColumnCount = $filteredTableData !== [] ? \count($filteredTableData[0]['cells']) : 0;

        // Calculate number of hidden rows for logging
        $hiddenRows = $originalRowCount - $filteredRowCount;

        // Calculate number of hidden columns for logging
        $hiddenColumns = $originalColumnCount - $filteredColumnCount;

        // Check if any rows or columns were filtered out
        if (
            $hiddenRows > 0
            || $hiddenColumns > 0
        ) {
            // Log detailed information about the filtering results
            Log::info(
                "TablePressEntryCreator::filterHiddenRowsAndColumns: Filtered table '$entrySlug' (ID: $postId)",
                [
                    'original_dimensions' => "{$originalRowCount}x{$originalColumnCount}",
                    'filtered_dimensions' => "{$filteredRowCount}x{$filteredColumnCount}",
                    'hidden_rows' => $hiddenRows,
                    'hidden_columns' => $hiddenColumns,
                ]
            );
        }

        // Clean up variables to free up memory
        unset(
            $rowsVisibility,
            $columnsVisibility,
            $originalRowCount,
            $originalColumnCount,
            $filteredRowCount,
            $filteredColumnCount,
            $hiddenRows,
            $hiddenColumns
        );

        // Return the filtered table data
        return $filteredTableData;
    }

    /**
     * Validates that a visibility array contains only 0s and 1s.
     *
     * @param  array<int, int>  $visibilityArray  The visibility array to validate
     * @return bool True if valid, false otherwise
     */
    private function isValidVisibilityArray(array $visibilityArray): bool
    {
        return array_all($visibilityArray, fn (int $value): bool => $value === 0 || $value === 1);
    }

    /**
     * Validates TablePress visibility payload structure.
     *
     * @param  array<int|string, mixed>  $visibility  Raw visibility metadata payload
     *
     * @phpstan-assert-if-true array{rows: array<int, int>, columns: array<int, int>} $visibility
     *
     * @return bool True when rows and columns are arrays
     */
    private function isTableVisibilityArray(
        array $visibility
    ): bool {
        // Verify expected keys and array payloads for rows and columns
        return isset($visibility['rows'], $visibility['columns'])
            && \is_array($visibility['rows'])
            && \is_array($visibility['columns']);
    }

    /**
     * Maps a `_tablepress_table_options` payload onto the Statamic table fields.
     *
     * Covers the free-tier options (footers, striping, row hover, and the
     * DataTables sort / filter / paginate / info / scroll-x family) plus the
     * Premium "Tier 1" options whose Statamic fields landed in
     * `UAMS-Web/uams-statamic#243`: Column Filter Dropdowns and Responsive
     * Tables stacking.
     *
     * The two Premium families sit on different sides of the DataTables gate.
     * Column Filter Dropdowns is a DataTables widget, so it can only apply when
     * `use_datatables` is on. Responsive Tables is a standalone TablePress
     * module, so stacking is mapped regardless.
     *
     * A companion field is written only when its toggle is on: the host fieldset
     * shows `column_filter_columns` and `responsive_stack_breakpoint` only when
     * their respective toggles equal true, so writing them otherwise stores data
     * the control panel can never reach.
     *
     * Any Premium feature the mapping could not carry is reported here too, once
     * the mapped result is known — see warnUnsupportedPremiumFeatures(), which
     * needs that result to tell a `responsive` mode that reached a field from one
     * that did not (wordpress-importer-archive#21).
     *
     * @param  array<int|string, mixed>  $tableOptions  Raw table options payload
     * @param  string  $entrySlug  The table's entry slug, for reporting context
     * @param  string  $postId  The WordPress post id, for reporting context
     * @return array<string, mixed> Statamic field values keyed by field handle
     */
    public function mapTableOptions(
        array $tableOptions,
        string $entrySlug = '',
        string $postId = ''
    ): array {
        $data = [];

        // Map the options that apply whether or not DataTables is enabled
        $data['last_row_footers'] = $this->getBooleanOption(
            $tableOptions,
            'table_foot'
        );
        $data['striped'] = $this->getBooleanOption(
            $tableOptions,
            'alternating_row_colors'
        );
        $data['row_hover_highlighting'] = $this->getBooleanOption(
            $tableOptions,
            'row_hover'
        );

        $useDatatables = $this->getBooleanOption(
            $tableOptions,
            'use_datatables'
        );

        if ($useDatatables) {
            // Enable DataTables features if applicable
            $data['sorting_enable'] = $this->getBooleanOption(
                $tableOptions,
                'datatables_sort'
            );
            $data['search_enable'] = $this->getBooleanOption(
                $tableOptions,
                'datatables_filter'
            );
            $data['pagination_enable'] = $this->getBooleanOption(
                $tableOptions,
                'datatables_paginate'
            );
            $data['info_enable'] = $this->getBooleanOption(
                $tableOptions,
                'datatables_info'
            );
            $data['horizontal_scroll_enable'] = $this->getBooleanOption(
                $tableOptions,
                'datatables_scrollx'
            );

            // Set pagination options if enabled
            if ($this->getBooleanOption($tableOptions, 'datatables_paginate')) {
                $data['pagination_count'] = $this->getIntegerOption(
                    $tableOptions,
                    'datatables_paginate_entries',
                    10
                );
                $data['pagination_change_enable'] = $this->getBooleanOption(
                    $tableOptions,
                    'datatables_lengthchange'
                );
            }

            // Map the Premium Column Filter Dropdowns widget
            $data['column_filter_enable'] = $this->getBooleanOption(
                $tableOptions,
                'datatables_columnfilterwidgets'
            );

            // Carry the filtered column letters only while the filter is on
            if ($data['column_filter_enable']) {
                $columns = $this->normalizeColumnList(
                    $this->getStringOption(
                        $tableOptions,
                        'datatables_columnfilterwidgets_columns'
                    )
                );

                // Skip a list that holds no usable column letters
                if ($columns !== '') {
                    $data['column_filter_columns'] = $columns;
                }
            }
        } else {
            // Disable DataTables features if not used
            $data['sorting_enable'] = false;
            $data['search_enable'] = false;
            $data['pagination_enable'] = false;
            $data['info_enable'] = false;
            $data['horizontal_scroll_enable'] = false;

            // Column Filter Dropdowns is a DataTables widget, so it cannot apply
            $data['column_filter_enable'] = false;
        }

        // ---
        // Map the Premium Responsive Tables module. Only the stacking modes get a
        // toggle: `scroll` is horizontal scrolling, which already has its own
        // field fed by `datatables_scrollx`, and `flip` transposes rather than
        // stacks. Across the 1392 tables in the export corpus the single
        // `responsive=scroll` table also sets `datatables_scrollx=true`, so
        // leaving `scroll` to that field loses nothing.
        // ---
        $responsive = \strtolower(
            \trim(
                $this->getStringOption(
                    $tableOptions,
                    'responsive'
                )
            )
        );
        $data['responsive_stack_enable'] = \in_array(
            $responsive,
            [
                'modal',
                'collapse',
            ],
            true
        );

        // Carry the breakpoint only while stacking is on
        if ($data['responsive_stack_enable']) {
            $breakpoint = \strtolower(
                \trim(
                    $this->getStringOption(
                        $tableOptions,
                        'responsive_breakpoint'
                    )
                )
            );

            // ---
            // The Statamic field offers phone and tablet only, and TablePress
            // defaults this option to phone, so an unrecognized value falls back
            // rather than writing something the select cannot represent.
            // ---
            $data['responsive_stack_breakpoint'] = $breakpoint === 'tablet'
                ? 'tablet'
                : 'phone';
        }

        // Report anything Premium the mapping above could not carry
        $this->warnUnsupportedPremiumFeatures(
            $tableOptions,
            $data,
            $entrySlug,
            $postId
        );

        return $data;
    }

    /**
     * Reports TablePress Premium features a table enables that Statamic cannot express.
     *
     * `UAMS-Web/uams-statamic#243` shipped destinations for two Premium modules —
     * Column Filter Dropdowns and Responsive Tables stacking — both mapped by
     * mapTableOptions(). Every other Premium module is a feature the imported table
     * silently loses, so an enabled one is named here rather than disappearing.
     *
     * Only the feature *gates* are listed. Premium writes a great many companion
     * options that carry a non-empty default whether or not their feature is on
     * (`datatables_column_filter_position` is `table_head` on all 14 corpus tables
     * that have it, `responsive_breakpoint` on all 14, and the fuzzy-search
     * threshold on all 10) — warning on those would report every Premium table as
     * broken while telling an editor nothing.
     *
     * Measured over the 1,392 corpus tables carrying an options payload, this fires
     * twice: one table sets `datatables_column_filter` to `input`, and one groups
     * its column-filter terms.
     *
     * @param  array<int|string, mixed>  $tableOptions  Raw table options payload
     * @param  array<string, mixed>  $mapped  The Statamic field values mapTableOptions() produced
     * @param  string  $entrySlug  The table's entry slug, for identifying the table
     * @param  string  $postId  The WordPress post id, for identifying the table
     */
    public function warnUnsupportedPremiumFeatures(
        array $tableOptions,
        array $mapped,
        string $entrySlug,
        string $postId
    ): void {
        // Report each unsupported Premium feature this table switched on
        foreach (self::UNSUPPORTED_PREMIUM_FEATURES as $option => $feature) {
            if (! $this->isPremiumFeatureEnabled($tableOptions, $option)) {
                continue;
            }

            ImportWarningManager::addWarning(
                'content',
                'unsupported_table_features',
                [
                    'table_slug' => $entrySlug,
                    'post_id' => $postId,
                    'option' => $option,
                    'feature' => $feature,
                    'reason' => \sprintf(
                        'The TablePress Premium "%s" feature has no field on the Statamic '
                            .'table, so it was not imported and needs a manual port.',
                        $feature
                    ),
                ]
            );
        }

        // ---
        // Report a responsive mode that reached no Statamic field. `modal` and
        // `collapse` become the stacking toggle and `scroll` is meant to be carried
        // by `horizontal_scroll_enable`, while `flip` transposes the table into a
        // column-per-row layout that nothing here can express.
        //
        // `scroll` is only ACTUALLY carried when the table separately enables the
        // DataTables horizontal scroll, because that is the only thing feeding
        // `horizontal_scroll_enable` — a table that sets `responsive=scroll` with
        // `use_datatables` off, or with `datatables_scrollx` off, declares
        // horizontal scrolling and gets none. So the test is what the mapping
        // PRODUCED, not which mode was requested.
        // ---
        $responsive = \strtolower(
            \trim(
                $this->getStringOption(
                    $tableOptions,
                    'responsive'
                )
            )
        );

        $responsiveCarried = match ($responsive) {
            'modal', 'collapse' => ($mapped['responsive_stack_enable'] ?? false) === true,
            'scroll' => ($mapped['horizontal_scroll_enable'] ?? false) === true,
            default => false,
        };

        // An empty value is the module being off, which loses nothing
        if (
            $responsive !== ''
            && ! $responsiveCarried
        ) {
            ImportWarningManager::addWarning(
                'content',
                'unsupported_table_features',
                [
                    'table_slug' => $entrySlug,
                    'post_id' => $postId,
                    'option' => 'responsive',
                    'feature' => \sprintf(
                        'Responsive Tables (%s mode)',
                        $responsive
                    ),
                    'reason' => 'The Statamic table offers stacking and horizontal '
                        .'scrolling only, so this responsive mode was not imported and '
                        .'needs a manual port.',
                ]
            );
        }
    }

    /**
     * Determines whether a TablePress Premium feature gate is switched on.
     *
     * TablePress serializes these gates inconsistently — a real boolean for the
     * DataTables extensions (`datatables_searchhighlight`), an empty string for the
     * off state of the string-valued ones (`datatables_column_filter` is `''` when
     * off and `input` when on), and occasionally `'0'`. Everything that is not one
     * of those off-states counts as on.
     *
     * @param  array<int|string, mixed>  $tableOptions  Raw table options payload
     * @param  string  $option  The option key to test
     * @return bool True when the feature is switched on
     */
    private function isPremiumFeatureEnabled(
        array $tableOptions,
        string $option
    ): bool {
        // An absent option is the feature not being present at all
        if (! \array_key_exists($option, $tableOptions)) {
            return false;
        }

        $value = $tableOptions[$option];

        // A non-scalar payload is not a gate value; treat it as off
        if (! \is_scalar($value)) {
            return false;
        }

        if (\is_bool($value)) {
            return $value;
        }

        // Normalize the string and numeric off-states TablePress writes
        return ! \in_array(
            \strtolower(\trim((string) $value)),
            [
                '',
                '0',
                'false',
            ],
            true
        );
    }

    /**
     * Normalizes a TablePress column-letter list for the Statamic text field.
     *
     * TablePress writes this list either bare (`A,C,D,E,I`) or spaced
     * (`A, C, D, E, F`) — both forms occur in the export corpus — while the
     * Statamic field expects bare comma-separated spreadsheet letters. Trims each
     * entry, drops the empty ones a stray or doubled separator leaves behind, and
     * rejoins.
     *
     * @param  string  $columns  Raw column list from the options payload
     * @return string Normalized list, or an empty string when nothing usable remains
     */
    private function normalizeColumnList(
        string $columns
    ): string {
        // Split on the separator TablePress writes between column letters
        $parts = \explode(
            ',',
            $columns
        );

        // Trim each entry and drop the empties a stray separator leaves behind
        $parts = \array_filter(
            \array_map(
                '\trim',
                $parts
            ),
            static fn (string $part): bool => $part !== ''
        );

        return \implode(
            ',',
            $parts
        );
    }

    /**
     * Retrieves a string option value from a TablePress options payload.
     *
     * @param  array<int|string, mixed>  $options  Raw table options payload
     * @param  string  $key  Option key to read
     * @param  string  $default  Fallback value when option is missing or invalid
     * @return string Normalized string option value
     */
    private function getStringOption(
        array $options,
        string $key,
        string $default = ''
    ): string {
        // Return default when option key is absent from the payload
        if (! isset($options[$key])) {
            return $default;
        }

        // Return string values as-is
        if (\is_string($options[$key])) {
            return $options[$key];
        }

        // Fallback for unsupported option value types
        return $default;
    }

    /**
     * Retrieves a boolean option value from a TablePress options payload.
     *
     * @param  array<int|string, mixed>  $options  Raw table options payload
     * @param  string  $key  Option key to read
     * @param  bool  $default  Fallback value when option is missing or invalid
     * @return bool Normalized boolean option value
     */
    private function getBooleanOption(
        array $options,
        string $key,
        bool $default = false
    ): bool {
        // Return default when option key is absent from the payload
        if (! isset($options[$key])) {
            return $default;
        }

        // Normalize scalar values to boolean and fallback for unsupported values
        return \is_scalar($options[$key]) ? (bool) $options[$key] : $default;
    }

    /**
     * Retrieves an integer option value from a TablePress options payload.
     *
     * @param  array<int|string, mixed>  $options  Raw table options payload
     * @param  string  $key  Option key to read
     * @param  int  $default  Fallback value when option is missing or invalid
     * @return int Normalized integer option value
     */
    private function getIntegerOption(
        array $options,
        string $key,
        int $default
    ): int {
        // Return default when option key is absent from the payload
        if (! isset($options[$key])) {
            return $default;
        }

        // Return integer values as-is
        if (\is_int($options[$key])) {
            return $options[$key];
        }

        // Convert digit-only string values to integers
        if (\is_string($options[$key]) && ctype_digit($options[$key])) {
            return (int) $options[$key];
        }

        // Fallback for unsupported option value types
        return $default;
    }

    /**
     * Creates a typed entry query builder for static analysis safety.
     *
     * @return EntryQueryBuilder Entry query builder instance
     */
    private function makeEntryQuery(): EntryQueryBuilder
    {
        // Create and return the typed query builder using the project helper
        return EntryQueryHelper::query();
    }

    /**
     * Counts table entries by slug for duplicate handling.
     *
     * @param  string  $collectionHandle  Target Statamic collection handle
     * @param  string  $slug  Entry slug to count
     * @return int Number of entries matching the slug
     */
    private function countEntriesBySlug(
        string $collectionHandle,
        string $slug
    ): int {
        // Create a typed query for collection and slug criteria
        $query = $this->makeEntryQuery();
        $query->where(
            'collection',
            $collectionHandle
        );
        $query->where(
            'slug',
            $slug
        );

        // Normalize the count result to an integer
        $count = $query->count();

        return \is_int($count) ? $count : 0;
    }
}
