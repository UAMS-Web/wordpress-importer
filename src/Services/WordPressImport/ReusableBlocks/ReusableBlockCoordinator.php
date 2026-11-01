<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\ReusableBlocks;

use Exception;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\Creators\ReusableBlockEntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\CollectionStructureManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\WpBlockBlockProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\SiteManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;

/**
 * Coordinates reusable block (wp_block) import operations.
 *
 * Handles importing WordPress wp_block post items that are referenced by
 * wp:block blocks encountered during content processing. Manages the
 * lifecycle of wp_block imports including tracking post IDs, importing
 * entries, and enabling the reusable_content collection.
 *
 * Follows the Single Responsibility Principle by focusing solely on
 * reusable block import coordination.
 */
class ReusableBlockCoordinator
{
    /**
     * XML parser instance.
     */
    private ?XmlParser $xmlParser = null;

    /**
     * Asset importer instance.
     */
    private ?AssetImporter $assetImporter = null;

    /**
     * How the run handles an entry that already exists.
     */
    private string $entryOverwriteBehavior = 'Overwrite matching files';

    /**
     * Constructs the ReusableBlockCoordinator with dependencies.
     *
     * @param  SiteManager  $siteManager  Site manager for collection operations
     * @param  CollectionStructureManager  $collectionStructureManager  Collection structure manager for structure initialization
     */
    public function __construct(private SiteManager $siteManager, private CollectionStructureManager $collectionStructureManager) {}

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
     * Sets how the run handles an entry that already exists.
     *
     * @param  string  $behavior  The entry overwrite behavior chosen for the run
     */
    public function setEntryOverwriteBehavior(
        string $behavior
    ): void {
        // Store the behavior for the creator of each import
        $this->entryOverwriteBehavior = $behavior;
    }

    /**
     * Clears tracked wp_block post IDs at the start of processing.
     *
     * Should be called at the beginning of each import cycle to ensure
     * a clean state.
     */
    public function clearWpBlockPostIds(): void
    {
        // Clear all tracked wp_block post IDs
        WpBlockBlockProcessor::clearWpBlockPostIds();
    }

    /**
     * Processes reusable block imports for a site.
     *
     * Imports wp_block items that were referenced by wp:block blocks
     * during content processing. Enables the reusable_content collection
     * if any wp_block items were detected. Clears tracked wp_block post
     * IDs after processing.
     *
     * @param  string  $site  The site handle
     * @param  OutputInterface  $output  Console output interface
     *
     * @throws Exception If XML parser or asset importer is not set
     */
    public function processReusableBlockImports(
        string $site,
        OutputInterface $output
    ): void {
        // Cache wp_block post IDs before import
        $cachedWpBlockPostIds = WpBlockBlockProcessor::getWpBlockPostIds();

        // Check if no wp_block content is detected
        if ($cachedWpBlockPostIds === []) {
            // No wp_block items to import, return early
            return;
        }

        // Inform user that reusable block import phase is starting
        $output->writeln('Processing reusable content imports...');

        // Enable the reusable_content collection for the site
        $this->siteManager->enableCollectionForSite(
            $site,
            'reusable_content',
            $output
        );

        // Initialize structure for the reusable_content collection
        $this->collectionStructureManager->initializeStructureForSite(
            'reusable_content',
            $site,
            $output
        );

        // Import wp_block items that were referenced in content
        $this->importWpBlocks(
            $site,
            $output
        );

        // Clear wp_block post IDs after processing is complete
        WpBlockBlockProcessor::clearWpBlockPostIds();
    }

    /**
     * Imports wp_block items referenced by wp:block blocks in content.
     *
     * Processes wp_block post items that are referenced by wp:block blocks
     * encountered during content processing. Creates entries in the
     * 'reusable_content' collection for each referenced wp_block post ID.
     * Handles recursive wp_block references by processing newly discovered
     * wp_blocks until no new ones are found.
     *
     * @param  string  $site  The site handle
     * @param  OutputInterface  $output  Console output interface
     *
     * @throws Exception If XML parser or asset importer is not set
     */
    private function importWpBlocks(
        string $site,
        OutputInterface $output
    ): void {
        // ---
        // Ensure dependencies are set
        // ---

        // Check if XML parser or asset importer is not set
        if (
            ! $this->xmlParser instanceof XmlParser
            || ! $this->assetImporter instanceof AssetImporter
        ) {
            // Throw exception if dependencies are missing
            throw new Exception(
                'XML parser or asset importer not set for wp_block imports.'
            );
        }

        // ---
        // Initialize dependencies
        // ---

        // Get XML parser instance
        $xmlParser = $this->xmlParser;

        // Get asset importer instance
        $assetImporter = $this->assetImporter;

        // Get all wp_block items from XML
        $wpBlocks = $xmlParser->getItems('wp_block');

        // Create reusable block entry creator instance
        $creator = $this->entryCreator();

        // Handle existing blocks as the run handles its other entries
        $creator->setEntryOverwriteBehavior($this->entryOverwriteBehavior);

        // ---
        // Track imported post IDs globally to avoid infinite loops
        // ---

        // Initialize array to track imported wp_block post IDs across iterations
        $importedPostIds = [];

        // Initialize counter for imported wp_blocks
        $importedCount = 0;

        // ---
        // Process wp_blocks in a loop until no new ones are discovered. Continue
        // processing until no new wp_blocks are found.
        // ---

        // Set maximum iterations to prevent infinite loops
        $maxIterations = 100;

        // Whether every pass found new wp_blocks, so the limit rather than an empty pass
        // ended the loop
        $exhausted = true;

        // Loop until max iterations reached or no new wp_blocks found. Each pass is
        // counted as it starts, so no early exit can skip the count.
        foreach (\range(1, $maxIterations) as $pass) {
            // ---
            // Get wp_block post IDs that were encountered during content processing
            // (includes newly discovered ones from previous iteration)
            // ---
            $wpBlockPostIds = WpBlockBlockProcessor::getWpBlockPostIds();

            // ---
            // Filter out already processed wp_blocks. A repeated id needs no deduplicating
            // here, since each block is queued once below however often it is named.
            // ---

            // Filter to only include wp_blocks that haven't been processed yet
            $newWpBlockPostIds = \array_diff(
                $wpBlockPostIds,
                $importedPostIds
            );

            // Check if no new wp_block post IDs to process
            if ($newWpBlockPostIds === []) {
                // Record that an empty pass, not the limit, ended the loop
                $exhausted = false;

                // Break loop if no new wp_blocks found. Continuing instead would spend the
                // remaining passes on the same empty result and end the loop the same way.
                // @pest-mutate-ignore: BreakToContinue
                break;
            }

            // Initialize array to store wp_blocks that need to be processed
            $wpBlocksToProcess = [];

            // Loop through all available wp_blocks to filter those that match our criteria
            foreach ($wpBlocks as $wpBlock) {
                // Extract the WordPress post ID from the wp_block item
                $postId = XpathHelper::getStringValue(
                    $wpBlock,
                    'wp:post_id'
                );

                // ---
                // Check if this wp_block's post ID is in our target list and hasn't been
                // processed yet. Post ids are strings, so the strict flags, which
                // phpstan-strict-rules requires, change no result.
                // ---

                // Whether the post ID is in the target list
                // @pest-mutate-ignore: TrueToFalse
                $isTarget = \in_array(
                    $postId,
                    $newWpBlockPostIds,
                    true
                );

                // Whether the post ID has already been processed
                // @pest-mutate-ignore: TrueToFalse
                $isProcessed = \in_array(
                    $postId,
                    $importedPostIds,
                    true
                );

                if ($isTarget && ! $isProcessed) {
                    // Add this wp_block to our processing queue
                    $wpBlocksToProcess[] = $wpBlock;

                    // Mark this post ID as processed to avoid duplicates
                    $importedPostIds[] = $postId;
                }
            }

            // Process each wp_block individually
            foreach ($wpBlocksToProcess as $wpBlock) {
                // Extract the WordPress post ID from the wp_block item
                $postId = XpathHelper::getStringValue(
                    $wpBlock,
                    'wp:post_id'
                );

                // ---
                // Create a new XML parser instance for this specific wp_block to avoid state
                // conflicts
                // ---
                $wpBlockXmlParser = new XmlParser;

                // Resolve the source XML file path before parsing
                $filePath = $xmlParser->getFilePath();

                // Skip this wp_block when the parser has no file path. Every block shares
                // the parser, so each one after it would be skipped too.
                if ($filePath === null) {
                    // @pest-mutate-ignore: ContinueToBreak
                    continue;
                }

                // Parse the XML file with the new parser instance
                $wpBlockXmlParser->parse($filePath);

                // Point the isolated parser at every export part so its item and
                // attachment readers span the whole split export (cross-part refs)
                $wpBlockXmlParser->setFilePaths($xmlParser->getFilePaths());

                // Override the parser's items to only include this specific wp_block
                $wpBlockXmlParser->setItems([$wpBlock]);

                // Create the wp_block entry using the isolated parser instance
                $creator->createEntries(
                    $site,
                    'reusable_content',
                    $wpBlockXmlParser,
                    $assetImporter,
                    $output,
                    new MetadataMapper,
                    new ContentProcessor
                );

                // Increment the count of successfully imported wp_blocks
                $importedCount++;

                // Wait for the filesystem writes to complete
                $this->waitForWrites();

                // Clean up variables to free up memory
                unset($wpBlockXmlParser);
            }

            // Clean up iteration variables
            unset(
                $wpBlocksToProcess,
                $wpBlockPostIds,
                $newWpBlockPostIds
            );
        }

        // Check if max iterations reached (safety check)
        if ($exhausted) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'importedCount' => $importedCount,
                    'importedPostIds' => $importedPostIds,
                ]
            );

            // Log warning about potential infinite loop
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    "ReusableBlockCoordinator::importWpBlocks: Reached maximum iterations ($maxIterations). Possible circular wp_block references.",
                    $logData['page_url']
                ),
                $logData['context']
            );
        }

        // Clean up variables to free up memory
        unset(
            $importedPostIds,
            $xmlParser,
            $assetImporter,
            $wpBlocks,
            $creator
        );
    }

    /**
     * Make the creator that writes each reusable block's entry.
     *
     * A method rather than an inline `new` so a test can record what is written without
     * writing it (#102).
     *
     * @return ReusableBlockEntryCreator A fresh creator
     */
    protected function entryCreator(): ReusableBlockEntryCreator
    {
        return new ReusableBlockEntryCreator;
    }

    /**
     * Wait for the filesystem writes of the entry just created to complete.
     *
     * A method so a test need not wait 300ms for each block it imports (#102).
     */
    protected function waitForWrites(): void
    {
        // A pause has no outcome a test can read, only a duration
        // @pest-mutate-ignore: RemoveFunctionCall, IncrementInteger, DecrementInteger
        usleep(300000);
    }
}
