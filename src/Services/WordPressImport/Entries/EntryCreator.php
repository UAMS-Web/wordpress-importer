<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Entries;

use DateTime;
use Exception;
use Illuminate\Support\Facades\File as LaravelFileFacade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Statamic\Facades\Collection;
use Statamic\Facades\Stache as StacheFacade;
use Statamic\Stache\Stores\Store;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\AudioSeries\AudioSeriesManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\DeferredProcessing\DeferredProcessingCoordinator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators\AdvertEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators\LogoListItemEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators\PageEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators\PeopleEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators\PostEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators\ReusableBlockEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators\TablePressEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormPluginCoordinator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\ReusableBlocks\ReusableBlockCoordinator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Structures\TreeBuilder;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\CollectionStructureManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentRoot;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportProgress;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\TablePressShortcodeProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\SiteManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\SlugGenerator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;

/**
 * Coordinates the creation of Statamic entries from WordPress content.
 *
 * Orchestrates the import process by delegating type-specific entry creation to
 * dedicated creator classes (e.g., PageEntryCreator, TablePressEntryCreator),
 * ensuring adherence to the Single Responsibility Principle (SRP) and
 * scalability. Manages dependencies, Classic block detection, and post-processing
 * tasks like taxonomy validation and reporting unaddressed nodes. Handles table
 * imports for shortcode/block references or direct 'tablepress_table' content.
 * Enables collections (e.g., 'tables', 'people') and taxonomies
 * (e.g., 'people_groups') for the site when specific content types
 * (e.g., TablePress, show-people shortcodes) are detected, using type-specific
 * configuration.
 */
class EntryCreator
{
    /**
     * The path to a table's TablePress export ID, within its item.
     */
    private const string EXPORT_ID_PATH = 'wp:postmeta[wp:meta_key="_tablepress_export_table_id"]/wp:meta_value';

    /**
     * Collected page data for tree building.
     *
     * @var array<int|string, mixed>
     */
    protected $pageData = [];

    /**
     * Overwrite behavior for entry imports.
     *
     * @var string
     */
    protected $entryOverwriteBehavior = 'Overwrite matching files';

    /**
     * Overwrite behavior for table imports.
     *
     * @var string
     */
    protected $tableOverwriteBehavior = 'Overwrite matching files';

    /**
     * The overwrite behavior for taxonomy term imports.
     *
     * @var string
     */
    protected $taxonomyOverwriteBehavior = 'Overwrite matching terms';

    /**
     * Pages with Classic block content.
     *
     * @var array<int|string, mixed>
     */
    protected $classicBlockPages = [];

    /**
     * Pages with Row block content.
     *
     * @var array<int|string, mixed>
     */
    protected $rowBlockPages = [];

    /**
     * Pages with Details block content.
     *
     * @var array<int|string, mixed>
     */
    protected $detailsBlockPages = [];

    /**
     * Pages with Freeform block content.
     *
     * @var array<int|string, mixed>
     */
    protected $freeformBlockPages = [];

    /**
     * Knowledge Base entries without associated topics.
     *
     * @var array<int|string, mixed>
     */
    protected $kbWithoutTopics = [];

    /**
     * XML parser instance.
     *
     * Null until set via setXmlParser().
     *
     * @var ?XmlParser
     */
    protected $xmlParser;

    /**
     * Asset importer instance.
     *
     * Null until set via setAssetImporter().
     *
     * @var ?AssetImporter
     */
    protected $assetImporter;

    /**
     * Form Plugin coordinator instance.
     *
     * @var FormPluginCoordinator
     */
    protected $formPluginCoordinator;

    /**
     * Reusable block coordinator instance.
     */
    protected ?ReusableBlockCoordinator $reusableBlockCoordinator = null;

    /**
     * Deferred processing coordinator instance.
     */
    protected ?DeferredProcessingCoordinator $deferredProcessingCoordinator = null;

    /**
     * Constructs the EntryCreator with dependencies.
     *
     * Initializes services for metadata mapping, content processing, site
     * configuration, taxonomy validation, and tree building.
     *
     * @param  MetadataMapper  $metadataMapper  Maps metadata to Statamic fields
     * @param  ContentProcessor  $contentProcessor  Processes content into field arrays
     * @param  SiteManager  $siteManager  Manages site configurations
     * @param  CollectionStructureManager  $collectionStructureManager  Manages collection structures
     * @param  TreeBuilder  $treeBuilder  Builds collection trees
     */
    public function __construct(protected MetadataMapper $metadataMapper, protected ContentProcessor $contentProcessor, protected SiteManager $siteManager, protected CollectionStructureManager $collectionStructureManager, protected TreeBuilder $treeBuilder) {}

    /**
     * Sets the overwrite behavior for entry imports.
     *
     * Configures how existing entries with matching IDs are handled: overwrite,
     * skip, or create additional entries.
     *
     * @param  string  $behavior  The behavior: 'Overwrite matching files', 'Skip matching files', or
     *                            'Create an additional file'
     */
    public function setEntryOverwriteBehavior(
        string $behavior
    ): void {
        $this->entryOverwriteBehavior = $behavior;
    }

    /**
     * Sets the overwrite behavior for table imports.
     *
     * Configures how existing table entries with matching IDs are handled:
     * overwrite, skip, or create additional entries.
     *
     * @param  string  $behavior  The behavior: 'Overwrite matching files', 'Skip matching files', or
     *                            'Create an additional file'
     */
    public function setTableOverwriteBehavior(
        string $behavior
    ): void {
        $this->tableOverwriteBehavior = $behavior;
    }

    /**
     * Sets the overwrite behavior for taxonomy term imports.
     *
     * Configures how existing taxonomy terms are handled: overwrite, skip, or
     * create additional terms.
     *
     * @param  string  $behavior  The behavior: 'Overwrite matching terms', 'Skip matching terms', or
     *                            'Create an additional term'
     */
    public function setTaxonomyOverwriteBehavior(
        string $behavior
    ): void {
        $this->taxonomyOverwriteBehavior = $behavior;
    }

    /**
     * Sets the XML parser instance.
     *
     * Stores the XML parser for use in table imports.
     *
     * @param  XmlParser  $xmlParser  The XML parser instance
     */
    public function setXmlParser(
        XmlParser $xmlParser
    ): void {
        $this->xmlParser = $xmlParser;
    }

    /**
     * Sets the asset importer instance.
     *
     * Stores the asset importer for use in table imports.
     *
     * @param  AssetImporter  $assetImporter  The asset importer instance
     */
    public function setAssetImporter(
        AssetImporter $assetImporter
    ): void {
        $this->assetImporter = $assetImporter;
    }

    /**
     * Sets the list of pages with Classic blocks for note addition.
     *
     * Stores pages identified as containing Classic block content for adding notes
     * during entry creation.
     *
     * @param  array<int|string, mixed>  $classicBlockPages  Array of pages with 'title', 'id', and 'link'
     */
    public function setClassicBlockPages(
        array $classicBlockPages
    ): void {
        $this->classicBlockPages = $classicBlockPages;
    }

    /**
     * Sets the list of pages with Row blocks for note addition.
     *
     * Stores pages identified as containing Row block content for adding notes
     * during entry creation.
     *
     * @param  array<int|string, mixed>  $rowBlockPages  Array of pages with 'title', 'id', and 'link'
     */
    public function setRowBlockPages(
        array $rowBlockPages
    ): void {
        $this->rowBlockPages = $rowBlockPages;
    }

    /**
     * Set pages with Details block content.
     *
     * @param  array<int|string, mixed>  $detailsBlockPages  Array of pages with Details blocks
     */
    public function setDetailsBlockPages(
        array $detailsBlockPages
    ): void {
        $this->detailsBlockPages = $detailsBlockPages;
    }

    /**
     * Set pages with Freeform block content.
     *
     * @param  array<int|string, mixed>  $freeformBlockPages  Array of pages with Freeform blocks
     */
    public function setFreeformBlockPages(
        array $freeformBlockPages
    ): void {
        $this->freeformBlockPages = $freeformBlockPages;
    }

    /**
     * Sets the list of Knowledge Base entries without associated topics.
     *
     * Stores entries identified as missing topic associations for adding notes
     * during entry creation.
     *
     * @param  array<int|string, mixed>  $kbWithoutTopics  Array of entries with 'title', 'id', and 'link'
     */
    public function setKbWithoutTopics(
        array $kbWithoutTopics
    ): void {
        $this->kbWithoutTopics = $kbWithoutTopics;
    }

    /**
     * Sets the Form Plugin coordinator instance.
     *
     * @param  FormPluginCoordinator  $formPluginCoordinator  The Form Plugin coordinator instance
     */
    public function setFormPluginCoordinator(
        FormPluginCoordinator $formPluginCoordinator
    ): void {
        $this->formPluginCoordinator = $formPluginCoordinator;
    }

    /**
     * Sets the Reusable Block coordinator instance.
     *
     * @param  ReusableBlockCoordinator  $reusableBlockCoordinator  The Reusable Block coordinator instance
     */
    public function setReusableBlockCoordinator(
        ReusableBlockCoordinator $reusableBlockCoordinator
    ): void {
        // Store reusable block coordinator instance
        $this->reusableBlockCoordinator = $reusableBlockCoordinator;
    }

    /**
     * Sets the deferred processing coordinator instance.
     *
     * @param  DeferredProcessingCoordinator  $deferredProcessingCoordinator  The deferred processing coordinator instance
     */
    public function setDeferredProcessingCoordinator(
        DeferredProcessingCoordinator $deferredProcessingCoordinator
    ): void {
        // Store deferred processing coordinator instance
        $this->deferredProcessingCoordinator = $deferredProcessingCoordinator;
    }

    /**
     * Sets the site manager instance.
     *
     * @param  SiteManager  $siteManager  The site manager
     */
    public function setSiteManager(
        SiteManager $siteManager
    ): void {
        $this->siteManager = $siteManager;
    }

    /**
     * Checks if a page has Classic block content.
     *
     * Determines if a page with the given post ID contains Classic block content.
     *
     * @param  string  $postId  The WordPress post ID
     * @return array<int|string, mixed>|null Page details if it has Classic block content, null otherwise
     */
    public function hasClassicBlock(
        string $postId
    ): ?array {
        return self::findPage(
            $this->classicBlockPages,
            $postId
        );
    }

    /**
     * Check if a post has Row block content.
     *
     * @param  string  $postId  The WordPress post ID
     * @return array<int|string, mixed>|null Array with page details if found, null otherwise
     */
    public function hasRowBlock(
        string $postId
    ): ?array {
        return self::findPage(
            $this->rowBlockPages,
            $postId
        );
    }

    /**
     * Check if a post has Details block content.
     *
     * @param  string  $postId  The WordPress post ID
     * @return array<int|string, mixed>|null Array with page details if found, null otherwise
     */
    public function hasDetailsBlock(
        string $postId
    ): ?array {
        return self::findPage(
            $this->detailsBlockPages,
            $postId
        );
    }

    /**
     * Check if a post has Freeform block content.
     *
     * @param  string  $postId  The WordPress post ID
     * @return array<int|string, mixed>|null Array with page details if found, null otherwise
     */
    public function hasFreeformBlock(
        string $postId
    ): ?array {
        return self::findPage(
            $this->freeformBlockPages,
            $postId
        );
    }

    /**
     * Find the page with a post ID in a list of block pages.
     *
     * @param  array<int|string, mixed>  $pages  Pages with 'title', 'id', and 'link'
     * @param  string  $postId  The WordPress post ID
     * @return array<int|string, mixed>|null The page, or null when none has the ID
     */
    private static function findPage(
        array $pages,
        string $postId
    ): ?array {
        foreach ($pages as $page) {
            if (\is_array($page) && ($page['id'] ?? null) === $postId) {
                return $page;
            }
        }

        return null;
    }

    /**
     * Checks if the content type has show-people shortcodes.
     *
     * Delegates to the appropriate creator's hasShowPeople method based on the
     * content type.
     *
     * @param  string  $contentType  The WordPress content type (e.g., 'page', 'uamspeople')
     * @return bool True if show-people shortcodes are present, false otherwise
     */
    public function hasShowPeople(
        string $contentType
    ): bool {
        $creator = $this->getCreator($contentType);

        return $creator->hasShowPeople();
    }

    /**
     * Imports table entries for TablePress content.
     *
     * For content types like 'page' or 'post', imports 'tablepress_table' items
     * matching IDs from TablePressShortcodeProcessor, triggered when shortcodes/
     * blocks are detected. For 'tablepress_table' content, imports all items
     * directly. Applies configured overwrite behavior and clears table IDs after
     * import to prevent reuse. Deduplicates table IDs for import but preserves
     * order for linking. Resets the XML parser's item list to null after all
     * imports to ensure subsequent operations access all table items via XPath.
     * Ensures entry files are written with correct slugs
     * ({site}_tablepress-{exportId}) and deletes any incorrect slug files. Adds
     * delay between imports to ensure filesystem writes are complete.
     *
     * @param  string  $site  The site handle (e.g., 'default')
     * @param  OutputInterface  $output  Console output for logging
     * @param  ?string  $contentType  The content type being imported (optional)
     *
     * @throws Exception If XML parser or asset importer is not set
     */
    public function importTables(
        string $site,
        OutputInterface $output,
        ?string $contentType = null
    ): void {
        // Ensure dependencies are set
        if ($this->xmlParser === null || $this->assetImporter === null) {
            throw new Exception('XML parser or asset importer not set for table imports.');
        }

        // Initialize dependencies
        $xmlParser = $this->xmlParser;
        $assetImporter = $this->assetImporter;
        $tables = $xmlParser->getItems('tablepress_table');
        // ---
        // Tables map their meta through a mapper of their own, so their keys stay out
        // of the content type's unaddressed-key report. They convert no content, so
        // they take this creator's converter, which they never call.
        // ---
        $creator = new TablePressEntryCreator;
        $creator->setOverwriteBehavior($this->tableOverwriteBehavior);

        // Determine import mode based on content type
        if ($contentType === 'tablepress_table') {
            // Direct import for tablepress_table content
            if ($tables === []) {
                $output->writeln('<comment>No tablepress_table items found in XML; skipping table imports.</comment>');

                return;
            }

            // Import all tablepress_table items
            $creator->createEntries(
                $site,
                'tables',
                $xmlParser,
                $assetImporter,
                $output,
                new MetadataMapper,
                $this->contentProcessor
            );

            // Log completion
            $output->writeln('<info>Imported '.\count($tables).' table(s).</info>');
        } else {
            // Import tables referenced by shortcodes/blocks
            $tableIds = TablePressShortcodeProcessor::getTableIds(); // Preserve order for linking

            // Skip if no table IDs are found
            if ($tableIds === []) {
                return;
            }

            // Initialize array to store tables grouped by export ID
            $tablesByExportId = [];

            // Loop through all available tables to collect those that match our criteria
            foreach ($tables as $table) {
                // Read the table's export ID from its meta
                $exportId = XpathHelper::getStringValue(
                    $table,
                    self::EXPORT_ID_PATH
                );

                // Check if this table's export ID is in our target list
                if (\in_array(
                    $exportId,
                    $tableIds,
                    true
                )) {
                    // Add this table to the collection for this export ID
                    $tablesByExportId[$exportId][] = $table;
                }
            }

            // Initialize array to store tables that need to be processed
            $tablesToProcess = [];

            // ---
            // For each export ID, select the table with the most recent post_date
            // ---

            // Loop through tables grouped by export ID
            foreach ($tablesByExportId as $exportId => $matchingTables) {
                // ---
                // Track the most recent table and its date
                // ---

                // Initialize variable to track the most recent table found
                $mostRecentTable = null;

                // Initialize variable to track the most recent date found
                $mostRecentDate = null;

                // Loop through all tables with this export ID to find the most recent
                foreach ($matchingTables as $table) {
                    // Extract the post date from the table item
                    $postDateStr = XpathHelper::getStringValue(
                        $table,
                        'wp:post_date'
                    );

                    // ---
                    // Skip if post date is empty
                    // ---

                    // Check if post date string is empty
                    if ($postDateStr === '') {
                        // Skip to next iteration if post date is empty
                        continue;
                    }

                    // Parse the post date string to a DateTime object
                    try {
                        $postDate = new DateTime($postDateStr);

                        // Check if this is the most recent date found so far; any date is
                        // later than none
                        if ($postDate > $mostRecentDate) {
                            // ---
                            // Update the most recent table and date
                            // ---

                            // Assign current table as the most recent table
                            $mostRecentTable = $table;

                            // Assign current date as the most recent date
                            $mostRecentDate = $postDate;
                        }
                    } catch (Exception) {
                        // Skip tables with invalid date format
                        continue;
                    }
                }

                // ---
                // If we found a table with a valid date, add it to processing queue
                // ---

                // Check if a table with valid date was found
                if ($mostRecentTable !== null) {
                    // Add the most recent table to processing queue
                    $tablesToProcess[] = $mostRecentTable;
                } else {
                    // ---
                    // If no table had a valid date, use the first one as fallback; an
                    // export ID is grouped only once a table is added to it
                    // ---
                    $tablesToProcess[] = $matchingTables[0];
                }
            }

            // Process each table individually
            foreach (ImportProgress::track($output, 'tablepress_table', $tablesToProcess) as $table) {
                // Extract the WordPress post ID from the table item
                $postId = XpathHelper::getStringValue(
                    $table,
                    'wp:post_id'
                );

                // Read the table's export ID from its meta
                $exportId = XpathHelper::getStringValue(
                    $table,
                    self::EXPORT_ID_PATH
                );

                // Create a new XML parser instance for this specific table to avoid state conflicts
                $tableXmlParser = new XmlParser;

                // ---
                // Skip a table the parser has no file for. Every table shares the
                // parser's path, so a missing one skips them all either way.
                // ---
                // @pest-mutate-ignore: ContinueToBreak
                if ($xmlParser->getFilePath() === null) {
                    continue;
                }

                // ---
                // Point the isolated parser at every export part so its item and
                // attachment readers span the whole split export (cross-part refs).
                // The table import reads only the items set below, so the parser
                // needs no parse of its own.
                // ---
                $tableXmlParser->setFilePaths($xmlParser->getFilePaths());

                // Override the parser's items to only include this specific table
                $tableXmlParser->setItems([$table]);

                // Create the table entry using the isolated parser instance
                $creator->createEntries(
                    $site,
                    'tables',
                    $tableXmlParser,
                    $assetImporter,
                    $output,
                    new MetadataMapper,
                    $this->contentProcessor
                );

                // Generate an entry ID in the TablePressEntryCreator format, for the report
                [, $entryId] = SlugGenerator::generateSlugAndId(
                    "{$site}_tablepress-{$exportId}",
                    $postId
                );

                // Define the expected entry file path, on the tree this run writes to
                $entryPath = ContentRoot::collectionEntry(
                    'tables',
                    $site,
                    "{$site}_tablepress-{$exportId}"
                );

                // Define the path for any incorrectly named entry files
                $incorrectPath = ContentRoot::collectionEntry(
                    'tables',
                    $site,
                    "{$site}_tablepress-{$exportId}-{$postId}"
                );

                // Check if any incorrect slug file exists
                if (LaravelFileFacade::exists($incorrectPath)) {
                    // Delete any incorrect slug file
                    LaravelFileFacade::delete($incorrectPath);
                }

                $retryCount = 5;
                $retryDelay = 300000; // 300ms
                $fileExists = false;
                for ($i = 0; $i < $retryCount; $i++) {
                    // ---
                    // Check if entry file exists with retry mechanism. A file found
                    // stays found, and the loop waits only after a miss.
                    // ---
                    // @pest-mutate-ignore: BreakToContinue
                    if (LaravelFileFacade::exists($entryPath)) {
                        $fileExists = true;
                        break;
                    }

                    Sleep::usleep($retryDelay);
                }

                if ($fileExists) {
                    // Report the outcome the run actually had
                    $output->writeln('  - <info>Table entry imported successfully.</info>');
                } else {
                    // Enhance log context with entry information
                    $logData = LogContextHelper::enhanceWithEntryContext(
                        [
                            'site' => $site,
                            'entryId' => $entryId,
                            'path' => $entryPath,
                        ]
                    );

                    // Log warning for entry file not found
                    Log::warning(
                        LogContextHelper::formatMessageWithEntry(
                            'EntryCreator::importTables: Entry file not found after retries',
                            $logData['page_url']
                        ),
                        $logData['context']
                    );

                    // ---
                    // Say so on the console too. The entry itself was saved through
                    // Statamic before this poll ran, so the import did not fail — what
                    // this reports is that the file is not at the canonical path, which
                    // is a naming discrepancy worth an operator's attention rather than
                    // a reason to stop.
                    // ---
                    $output->writeln(
                        '  - <comment>Table entry saved, but no file appeared at '
                        .$entryPath.' after '.$retryCount.' attempts.</comment>'
                    );

                    // Surface it in the end-of-run warning report, which is this
                    // importer's established channel for "finished, but not cleanly"
                    ImportWarningManager::addWarning(
                        'content',
                        'table_entry_file_missing',
                        [
                            'site' => $site,
                            'entry_id' => $entryId,
                            'path' => $entryPath,
                            'attempts' => $retryCount,
                            'reason' => 'The table entry was saved through Statamic, but no file '
                                .'appeared at the canonical path. The entry most likely landed under '
                                .'a different slug.',
                        ]
                    );
                }

                // Add delay to ensure filesystem writes are complete
                Sleep::usleep(300000); // 300ms delay

                // Clean up variables to free up memory
                unset($tableXmlParser);
            }
        }
    }

    /**
     * Creates entries for a specific WordPress content type.
     *
     * Delegates entry creation to the appropriate creator class based on content
     * type, applying the configured overwrite behavior and collecting page data
     * for tree building. Processes content to generate Bard or page_builder sets,
     * imports referenced tables, and links table entries to table sets to ensure
     * accurate table_entry IDs. Enables collections (e.g., 'tables') and
     * taxonomies (e.g., 'people_groups') for the site based on detected content
     * type ('tablepress' or 'show-people'). Manages taxonomy validation and tree
     * building for structured collections. Warms the Stache cache after entry
     * creation and re-warms if entries are not found during table linking to
     * handle indexing delays. Verifies entry file existence using the correct
     * slug, checks for TablePress shortcodes using processor data, and retries
     * queries to handle filesystem and indexing delays.
     *
     * @param  string  $site  The site handle
     * @param  string  $contentType  The WordPress content type (e.g., 'page', 'post')
     * @param  string  $collectionHandle  The Statamic collection handle (e.g., 'pages')
     * @param  XmlParser  $xmlParser  The XML parser instance
     * @param  AssetImporter  $assetImporter  The asset importer instance
     * @param  OutputInterface  $output  Console output interface
     * @param  ?string  $baseBlogUrl  The base blog URL from XML (optional)
     * @param  array<int|string, mixed>|null  $minervaKbSettings  Optional MinervaKB settings from JSON file
     */
    public function createEntries(
        string $site,
        string $contentType,
        string $collectionHandle,
        XmlParser $xmlParser,
        AssetImporter $assetImporter,
        OutputInterface $output,
        ?string $baseBlogUrl = null,
        ?array $minervaKbSettings = null
    ): void {
        // Set dependencies for table imports
        $this->setXmlParser($xmlParser);
        $this->setAssetImporter($assetImporter);

        // ---
        // Clear tracked wp_block post IDs at the start of processing
        // ---

        // Check if reusable block coordinator is available
        if ($this->reusableBlockCoordinator instanceof ReusableBlockCoordinator) {
            // Set XML parser on coordinator
            $this->reusableBlockCoordinator->setXmlParser($xmlParser);

            // Set asset importer on coordinator
            $this->reusableBlockCoordinator->setAssetImporter($assetImporter);

            // Clear tracked wp_block post IDs
            $this->reusableBlockCoordinator->clearWpBlockPostIds();
        }

        // Enable collection and initialize structure based on content type
        $this->enableCollectionAndStructureForContentType(
            $site,
            $output,
            $contentType,
            $collectionHandle
        );

        // Get the appropriate creator (getCreator() throws for unsupported types)
        $creator = $this->getCreator($contentType);

        // Configure entry overwrite behavior for the creator
        $creator->setEntryOverwriteBehavior($this->entryOverwriteBehavior);

        // Configure taxonomy overwrite behavior for the creator
        $creator->setTaxonomyOverwriteBehavior($this->taxonomyOverwriteBehavior);

        // Create entries and collect page data
        // Pass MinervaKB settings to PageEntryCreator for kb_page blueprint detection
        $this->pageData = $creator->createEntries(
            $site,
            $collectionHandle,
            $xmlParser,
            $assetImporter,
            $output,
            $this->metadataMapper,
            $this->contentProcessor,
            $minervaKbSettings
        );

        // Warm the Stache cache for the collection to ensure entries are indexed
        $store = StacheFacade::store('entries');
        if ($store instanceof Store) {
            $store->clear();
            $store->warm();
            $collection = Collection::findByHandle($collectionHandle);
            if ($collection === null) {
                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'site' => $site,
                        'collection_handle' => $collectionHandle,
                    ]
                );

                // Log warning for missing collection
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        "EntryCreator::createEntries: Could not find '$collectionHandle' collection for cache warming",
                        $logData['page_url']
                    ),
                    $logData['context']
                );
            }

            // Add delay to ensure filesystem writes and indexing are complete
            Sleep::usleep(1500000); // 1500ms delay
        } else {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                ]
            );

            // Log warning for Stache store access failure
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'EntryCreator::createEntries: Could not access Stache store for cache warming',
                    $logData['page_url']
                ),
                $logData['context']
            );
        }

        // ---
        // Build and save tree for structured collections immediately after entries are created
        // and cache is warmed, before other operations that might query entries. This ensures
        // the tree is complete and saved before validateTree() is called during queries,
        // preventing stub entries from being created for entries that exist but aren't in the tree.
        //
        // IMPORTANT: buildAndSaveTree() must be called BEFORE any Collection::findByHandle()
        // calls, as accessing the collection triggers tree validation which creates stub entries
        // for entries that exist in the filesystem but aren't in the tree yet.
        // ---
        $this->treeBuilder->buildAndSaveTree(
            $site,
            $contentType,
            $collectionHandle,
            $this->pageData,
            $output,
            $baseBlogUrl
        );

        // ---
        // The parser term registry is deliberately NOT reset here. `ImportWordPress`
        // reads it immediately after this method returns, to hand the recorded terms
        // to `LogosItemCategoriesValidator` — so a reset at this point emptied the
        // registry before its only consumer ran, and the validator received `[]` on
        // every import (wordpress-importer-archive#529). Labels no longer pass through it (wordpress-importer-archive#1220). The import cycle owns
        // that lifecycle and already resets all four parsers at the end of its
        // content-type loop, which is after the read.
        // ---

        // Enable collections/taxonomies for pages with show-people shortcodes
        if (
            $contentType === 'page'
            && $creator->hasShowPeople()
        ) {
            $this->enableCollectionAndStructureForContentType(
                $site,
                $output,
                'people',
                'people'
            );
        }

        // Create any taxonomy terms queued on-demand during content processing
        // (people/team and logo shortcodes, etc.) in one batched pass per taxonomy
        // before validation runs. Queuing + batching here avoids the cold Stache
        // index rebuild that per-term inline creation triggered (~7–9s per term
        // when the taxonomy is attached to a collection). Runs before
        // validateTaxonomies so validators see the freshly created terms.
        $creator->flushDeferredTaxonomyTerms(
            $site,
            $output
        );

        // Validate taxonomies specific to this content type
        $creator->validateTaxonomies(
            $collectionHandle,
            $site,
            $output
        );

        // Call importTables for non-tablepress_table content after content processing
        if ($contentType !== 'tablepress_table') {
            // Cache table IDs before import
            $cachedTableIds = TablePressShortcodeProcessor::getTableIds();
            $this->importTables(
                $site,
                $output,
                $contentType
            );

            // Enable 'tables' collection if TablePress content is detected
            if ($cachedTableIds !== []) {
                // Enable the tables collection for the site
                $this->siteManager->enableCollectionForSite(
                    $site,
                    'tables',
                    $output
                );

                // Initialize structure for the tables collection
                $this->collectionStructureManager->initializeStructureForSite(
                    'tables',
                    $site,
                    $output
                );
            }

            // ---
            // Process reusable block imports if coordinator is available
            // ---

            // Check if reusable block coordinator is available
            if ($this->reusableBlockCoordinator instanceof ReusableBlockCoordinator) {
                // Process reusable block imports for the site
                $this->reusableBlockCoordinator->processReusableBlockImports(
                    $site,
                    $output
                );
            }

            // ---
            // Process deferred operations for this content type
            // ---

            // Check if deferred processing coordinator is available
            if ($this->deferredProcessingCoordinator instanceof DeferredProcessingCoordinator) {
                // ---
                // Set dependencies on deferred processing coordinator
                // ---

                // Set content processor on deferred processing coordinator
                $this->deferredProcessingCoordinator->setContentProcessor(
                    $this->contentProcessor
                );

                // Set form plugin coordinator on deferred processing coordinator
                $this->deferredProcessingCoordinator->setFormPluginCoordinator(
                    $this->formPluginCoordinator
                );

                // Set XML parser on deferred processing coordinator
                $this->deferredProcessingCoordinator->setXmlParser(
                    $xmlParser
                );

                // Set asset importer on deferred processing coordinator
                $this->deferredProcessingCoordinator->setAssetImporter(
                    $assetImporter
                );

                // Set page data mapping on deferred processing coordinator
                $this->deferredProcessingCoordinator->setPageData(
                    $this->pageData
                );

                // Process deferred operations (wp_block references, forms, table linking)
                $this->deferredProcessingCoordinator->processPerContentType(
                    $site,
                    $collectionHandle,
                    $output
                );
            }
        }

        // Report unaddressed XML keys
        if ($this->metadataMapper->getUnaddressedKeys() !== []) {
            $output->writeln('<comment>Unaddressed XML keys found:</comment>');
            $unaddressedKeys = array_filter(
                $this->metadataMapper->getUnaddressedKeys(),
                is_string(...)
            );
            foreach (array_unique($unaddressedKeys) as $key) {
                $output->writeln("- $key");
            }
        }

        // Report unaddressed nodes
        $unaddressedNodes = $this->contentProcessor->getUnaddressedNodes();
        if ($unaddressedNodes !== []) {
            $collection = Collection::findByHandle($collectionHandle);
            $collectionTitle = $collection !== null ? $collection->title() : null;
            $collectionName = is_string($collectionTitle) && $collectionTitle !== ''
                ? $collectionTitle
                : $collectionHandle;
            $output->writeln('<comment>Unaddressed nodes found in <content:encoded>:</comment>');
            foreach ($unaddressedNodes as $nodeType => $pages) {
                $uniquePages = [];
                foreach ($pages as $page) {
                    $key = $page['id'];
                    $uniquePages[$key] ??= $page;
                }

                $output->writeln("- $nodeType");
                foreach ($uniquePages as $page) {
                    $slug = $page['slug'] ?? basename($page['link']);
                    $output->writeln("    - $collectionName entry '$slug'");
                    if (
                        isset($page['original'])
                        && str_starts_with($nodeType, 'shortcode:')
                    ) {
                        $output->writeln("        - {$page['original']}");
                    }
                }
            }
        }
    }

    /**
     * Gets the appropriate entry creator instance for the content type.
     *
     * Instantiates the creator class based on the content type, ensuring type-
     * specific logic is encapsulated.
     *
     * @param  string  $contentType  The WordPress content type
     * @return BaseEntryCreator The creator instance
     *
     * @throws Exception If the content type is unsupported
     */
    protected function getCreator(
        string $contentType
    ): BaseEntryCreator {
        // Route content type to appropriate entry creator
        switch ($contentType) {
            // Handle WordPress page content type
            case 'page':
                // Return page entry creator with reference to this entry creator
                return new PageEntryCreator($this);

                // Handle WordPress post content type
            case 'post':
                // Return post entry creator
                return new PostEntryCreator;

                // Handle WordPress uamspeople content type
            case 'uamspeople':
                // Return people entry creator configured for uamspeople post type
                return new PeopleEntryCreator('uamspeople');

                // Handle WordPress tshowcase content type
            case 'tshowcase':
                // Return people entry creator configured for tshowcase post type
                return new PeopleEntryCreator('tshowcase');

                // Handle WordPress tablepress_table content type
            case 'tablepress_table':
                // Return table press entry creator
                return new TablePressEntryCreator;

                // Handle WordPress logo-list-item content type
            case 'logo-list-item':
                // Return logo list item entry creator with required dependencies
                return new LogoListItemEntryCreator(
                    $this->siteManager,
                    $this->collectionStructureManager
                );

                // Handle WordPress Adverts listings
            case 'advert':
                // Return the classifieds entry creator
                return new AdvertEntryCreator;

                // Handle WordPress wp_block content type
            case 'wp_block':
                // Return reusable block entry creator
                return new ReusableBlockEntryCreator;

                // Handle WordPress kb content type
            case 'kb':
                // Throw exception as kb entries should be created directly
                throw new Exception("KbEntryCreator should be used directly for 'kb' content type, not through EntryCreator::getCreator()");
                // Handle unsupported content types
            default:
                // Throw exception for unsupported content type
                throw new Exception("Unsupported content type: {$contentType}");
        }
    }

    /**
     * Gets the collected page data for tree building.
     *
     * Returns the page data array, including links for front-page detection.
     *
     * @return array<int|string, mixed> The page data array
     */
    public function getPageData(): array
    {
        return $this->pageData;
    }

    /**
     * Gets the content processor instance.
     *
     * @return ContentProcessor The content processor instance
     */
    public function getContentProcessor(): ContentProcessor
    {
        return $this->contentProcessor;
    }

    /**
     * Gets the people groups used by uamspeople content.
     *
     * Retrieves people_group terms associated with uamspeople entries.
     *
     * @return array<int|string, mixed> Associative array of people_group terms and page slugs
     */
    public function getPeopleGroups(): array
    {
        $creator = $this->getCreator('uamspeople');

        return $creator->getPeopleGroups();
    }

    /**
     * Gets the post categories used by post content.
     *
     * Retrieves post_category terms associated with post entries.
     *
     * @return array<int|string, mixed> Associative array of post_category terms and page slugs
     */
    public function getPostCategories(): array
    {
        // Get the PostEntryCreator instance for post content type
        $creator = $this->getCreator('post');

        // Return the post categories data collected during entry creation
        return $creator->getPostCategories();
    }

    /**
     * Gets the post tags used by post content.
     *
     * Retrieves post_tag terms associated with post entries.
     *
     * @return array<int|string, mixed> Associative array of post_tag terms and page slugs
     */
    public function getPostTags(): array
    {
        // Get the PostEntryCreator instance for post content type
        $creator = $this->getCreator('post');

        // Return the post tags data collected during entry creation
        return $creator->getPostTags();
    }

    /**
     * Gets the SiteManager instance.
     *
     * @return SiteManager The SiteManager instance
     */
    public function getSiteManager(): SiteManager
    {
        return $this->siteManager;
    }

    /**
     * Enable collection and initialize structure based on content type.
     *
     * Handles content-type specific logic for enabling collections and taxonomies,
     * including structure initialization. Replaces the functionality previously
     * provided by SiteConfigurator.
     *
     * @param  string  $site  The site handle
     * @param  OutputInterface  $output  Console output interface for logging
     * @param  string  $contentType  The content type (e.g., 'tables', 'people', 'pages')
     * @param  string  $collectionHandle  The collection handle
     */
    private function enableCollectionAndStructureForContentType(
        string $site,
        OutputInterface $output,
        string $contentType,
        string $collectionHandle
    ): void {
        // Check if we're processing tables content type
        if ($contentType === 'tables') {
            // Enable the tables collection for the specified site
            $this->siteManager->enableCollectionForSite(
                $site,
                'tables',
                $output
            );

            // Initialize the structure for the tables collection
            $this->collectionStructureManager->initializeStructureForSite(
                'tables',
                $site,
                $output
            );
        } elseif ($contentType === 'people') { // Check if we're processing people content type
            // Enable the people collection for the specified site
            $this->siteManager->enableCollectionForSite(
                $site,
                'people',
                $output
            );

            // Initialize the structure for the people collection
            $this->collectionStructureManager->initializeStructureForSite(
                'people',
                $site,
                $output
            );

            // Enable the people_groups taxonomy for the specified site
            $this->siteManager->enableTaxonomyForSite(
                $site,
                'people_groups',
                $output
            );
        } elseif (AudioSeriesManager::isAudioSeriesTaxonomy($contentType)) { // Check if we're processing an audio series taxonomy
            // Delegate audio series taxonomy enabling to AudioSeriesManager
            AudioSeriesManager::enableAudioSeriesTaxonomy(
                $contentType,
                $site,
                $output,
                $this->siteManager
            );
        } else { // Handle all other content types (pages, posts, etc.)
            // Enable the specified collection for the site
            $this->siteManager->enableCollectionForSite(
                $site,
                $collectionHandle,
                $output
            );

            // Initialize the structure for the specified collection
            $this->collectionStructureManager->initializeStructureForSite(
                $collectionHandle,
                $site,
                $output
            );
        }
    }
}
