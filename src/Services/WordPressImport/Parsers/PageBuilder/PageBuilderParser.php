<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\PageBuilder;

use DOMNode;
use DOMNodeList;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\BardParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\HeadingDemoter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\WpBlockBlockProcessor;

/**
 * Orchestrates parsing of WordPress <content:encoded> into Statamic page_builder
 * replicator sets.
 *
 * Coordinates AcfBlockParser and NonAcfBlockParser to handle mixed ACF and
 * non-ACF content, producing a unified page_builder array for entries with at
 * least one ACF block (blueprint: landing_page). Accumulates non-ACF content into
 * single uamsweb_block_content sets between ACF blocks or at content boundaries,
 * preserving the original order of content as it appears in the XML.
 */
class PageBuilderParser
{
    /**
     * Instance of AcfBlockParser for ACF block parsing.
     */
    private AcfBlockParser $acfParser;

    /**
     * Instance of NonAcfBlockParser for non-ACF block parsing.
     */
    private NonAcfBlockParser $nonAcfParser;

    /**
     * Instance of MinervaKBBlockParser for MinervaKB block parsing.
     */
    private MinervaKBBlockParser $minervaKbParser;

    /**
     * Instance of WordPressCoreBlockParser for WordPress core block parsing.
     */
    private WordPressCoreBlockParser $wordPressCoreBlockParser;

    /**
     * Static variables for accumulating content across parseNode calls.
     * These need to be reset between different pages to prevent cross-contamination.
     *
     * @var array<int|string, mixed>
     */
    private static array $orderedSets = [];

    /**
     * Static accumulator for non-ACF content.
     *
     * @var array<int|string, mixed>
     */
    private static array $nonAcfAccumulator = [];

    /**
     * Constructs a new PageBuilderParser instance with its dependencies.
     *
     * Initializes parsers for ACF, non-ACF, MinervaKB, and WordPress core content,
     * optionally accepting custom instances for dependency injection. Provides
     * BardParser, LinkProcessor, and HeadingDemoter to NonAcfBlockParser for
     * Tiptap-based parsing and heading adjustments.
     *
     * @param  ?AcfBlockParser  $acfParser  Optional ACF parser instance
     * @param  ?NonAcfBlockParser  $nonAcfParser  Optional non-ACF parser instance
     * @param  ?MinervaKBBlockParser  $minervaKbParser  Optional MinervaKB parser instance
     * @param  ?WordPressCoreBlockParser  $wordPressCoreBlockParser  Optional WordPress core block parser instance
     * @param  ?BardParser  $bardParser  Optional Bard parser for non-ACF content
     * @param  ?LinkProcessor  $linkProcessor  Optional link processor for files
     * @param  ?HeadingDemoter  $headingDemoter  Optional heading demoter
     */
    public function __construct(
        ?AcfBlockParser $acfParser = null,
        ?NonAcfBlockParser $nonAcfParser = null,
        ?MinervaKBBlockParser $minervaKbParser = null,
        ?WordPressCoreBlockParser $wordPressCoreBlockParser = null,
        ?BardParser $bardParser = null,
        ?LinkProcessor $linkProcessor = null,
        ?HeadingDemoter $headingDemoter = null
    ) {
        // Initialize ACF parser with provided instance or create new one
        $this->acfParser = $acfParser ?? new AcfBlockParser;

        // Initialize non-ACF parser with provided instance or create new one
        $this->nonAcfParser = $nonAcfParser ?? new NonAcfBlockParser(
            $bardParser,
            $linkProcessor,
            $headingDemoter
        );

        // Initialize MinervaKB parser with provided instance or create new one
        $this->minervaKbParser = $minervaKbParser ?? new MinervaKBBlockParser;

        // Initialize WordPress core block parser with provided instance or create new one
        $this->wordPressCoreBlockParser = $wordPressCoreBlockParser ?? new WordPressCoreBlockParser;
    }

    /**
     * Resets static variables to prevent cross-contamination between pages.
     *
     * This method should be called at the beginning of processing each new page
     * to ensure that content from previous pages doesn't leak into the current page.
     */
    public function resetStaticVariables(): void
    {
        // Clear ordered sets to prevent cross-contamination
        self::$orderedSets = [];

        // Clear non-ACF accumulator to prevent cross-contamination
        self::$nonAcfAccumulator = [];

        // Reset non-ACF parser static variables
        $this->nonAcfParser->resetStaticVariables();
    }

    /**
     * Forces finalization of accumulated sets without processing a node.
     *
     * Appends any sets accumulated in self::$orderedSets to the content array
     * and finalizes any non-ACF content. This is useful when the last node has
     * already been processed but finalization didn't occur.
     *
     * @param  array<int|string, mixed>  &$content  The content array to append sets to
     * @param  string  $site  The Statamic site handle
     * @param  AssetImporter  $assetImporter  Instance for importing assets
     * @param  OutputInterface  $output  Console output for logging
     */
    public function forceFinalization(
        array &$content,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output
    ): void {
        // ---
        // Finalize non-ACF content if we have accumulated content
        // ---

        // Check if non-ACF content has been accumulated
        if (self::$nonAcfAccumulator !== []) {
            // Finalize accumulated non-ACF content into a set
            $nonAcfSet = $this->nonAcfParser->finalizeNonAcfContent(
                self::$nonAcfAccumulator,
                $content,
                $site,
                $assetImporter,
                $output
            );

            // Check if non-ACF set was created
            if ($nonAcfSet !== null && $nonAcfSet !== []) {
                // Add non-ACF set to ordered sets
                self::$orderedSets[] = $nonAcfSet;
            }

            // Clear non-ACF accumulator
            self::$nonAcfAccumulator = [];
        }

        // ---
        // Append collected sets to content
        // ---

        // Check if ordered sets have been accumulated
        if (self::$orderedSets !== []) {
            // Loop through ordered sets and append to content
            foreach (self::$orderedSets as $set) {
                // Append set to content array
                $content[] = $set;
            }

            // Clear ordered sets after appending
            self::$orderedSets = [];
        }
    }

    /**
     * Finalizes accumulated non-ACF content and adds it to ordered sets.
     *
     * Helper method to reduce duplication when finalizing non-ACF content before
     * adding page builder blocks (ACF, MinervaKB, wp:block) to ordered sets.
     *
     * @param  array<int|string, mixed>  &$content  The page_builder content array (passed by reference)
     * @param  string  $site  The Statamic site handle
     * @param  AssetImporter  $assetImporter  Instance for importing assets
     * @param  OutputInterface  $output  Console output for logging
     */
    private function finalizeNonAcfContentIfNeeded(
        array &$content,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output
    ): void {
        // Check if non-ACF content has been accumulated
        if (self::$nonAcfAccumulator !== []) {
            // Finalize accumulated non-ACF content into a set
            $nonAcfSet = $this->nonAcfParser->finalizeNonAcfContent(
                self::$nonAcfAccumulator,
                $content,
                $site,
                $assetImporter,
                $output
            );

            // Check if non-ACF set was created
            if ($nonAcfSet !== null && $nonAcfSet !== []) {
                // Add non-ACF set to ordered sets
                self::$orderedSets[] = $nonAcfSet;
            }

            // Clear non-ACF accumulator
            self::$nonAcfAccumulator = [];
        }
    }

    /**
     * Parses a DOM node into page_builder-compatible content.
     *
     * Processes nodes sequentially, collecting ACF and non-ACF sets in order, then
     * appending them to the content array to preserve XML sequence. Accumulates
     * non-ACF content until an ACF block or end of nodes. Passes items, terms, and
     * unaddressedBlocks to NonAcfBlockParser for shortcode/block processing and
     * suppression.
     *
     * @param  DOMNode  $node  The current DOM node to parse
     * @param  int  $index  The index of the current node in the node list
     * @param  DOMNodeList<DOMNode>  $nodes  The full list of nodes
     * @param  array<int|string, mixed>  &$content  The page_builder content array (passed by reference)
     * @param  string  $site  The site handle for asset subfolder naming
     * @param  AssetImporter  $assetImporter  The asset importer instance
     * @param  OutputInterface  $output  The console output interface
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects
     * @param  array<int|string, mixed>  $items  Array of all XML items for post ID mapping
     * @param  array<int|string, mixed>  &$unaddressedBlocks  List of unaddressed block types
     * @param  ?string  $pageSlug  The page slug (optional)
     * @return array<int|string, mixed> Result with 'processed' (bool) and 'skipCount' (int)
     */
    public function parseNode(
        DOMNode $node,
        int $index,
        DOMNodeList $nodes,
        array &$content,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = [],
        array $terms = [],
        array $items = [],
        array &$unaddressedBlocks = [],
        ?string $pageSlug = null
    ): array {
        // Initialize processed flag to track if node was handled
        $processed = false;

        // Initialize skip count to track how many nodes to skip
        $skipCount = 0;

        // ---
        // Handle wp:block blocks for landing_page blueprint (replicator sets). This must
        // be checked before ACF and non-ACF parsers.
        // ---

        // Create wp:block processor instance
        $wpBlockProcessor = new WpBlockBlockProcessor;

        // Check if processor can handle this node, which only a wp:block comment passes
        if (
            $wpBlockProcessor->canProcess(
                $node,
                $site,
                $pageSlug
            )
        ) {
            // ---
            // Finalize any accumulated non-ACF content before adding wp:block set
            // ---
            $this->finalizeNonAcfContentIfNeeded(
                $content,
                $site,
                $assetImporter,
                $output
            );

            // Decode the JSON attributes in the comment, which canProcess() found there
            $attributes = \preg_match('/{.+?}/s', $node->textContent, $matches) === 1
                ? \json_decode($matches[0], true)
                : null;

            // Process wp:block node
            $result = $wpBlockProcessor->process(
                $node,
                \is_array($attributes) ? $attributes : [],
                $site,
                $assetImporter,
                $output,
                $attachments,
                $terms,
                $pageSlug
            );

            // Check if processing was successful
            if ($result !== null) {
                // ---
                // Create a replicator set for landing_page blueprint with the entry ID, or a
                // placeholder with the wp_block post ID when the entry is not imported yet
                // ---
                self::$orderedSets[] = $result['entryId'] !== null
                    ? $wpBlockProcessor->createReplicatorSet($result['entryId'])
                    : $wpBlockProcessor->createPlaceholderReplicatorSet($result['wpBlockPostId']);
            } else { // Otherwise, log warning if the block has no ref
                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'page_slug' => $pageSlug ?? 'none',
                        'node_value' => \substr(
                            $node->textContent,
                            0,
                            200
                        ),
                    ]
                );

                // Log warning about null result
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        'PageBuilderParser::parseNode: wp:block processing returned null/false',
                        $logData['page_url']
                    ),
                    $logData['context']
                );
            }

            // Return early, skipping the comment node, even if the entry ID was not
            // found yet, since the post ID is tracked for later import
            return [
                'processed' => true,
                'skipCount' => 1,
            ];
        }

        // ---
        // Handle MinervaKB blocks for landing_page blueprint. This must be checked
        // after wp:block but before ACF blocks.
        // ---

        // Parse MinervaKB block node
        $minervaKbResult = $this->minervaKbParser->parseNode(
            $node,
            $index,
            $nodes,
            $site,
            $assetImporter,
            $output,
            $attachments,
            $terms,
            $pageSlug
        );

        // Check if MinervaKB block was processed
        if ($minervaKbResult['processed']) {
            // Finalize any accumulated non-ACF content before adding MinervaKB set
            $this->finalizeNonAcfContentIfNeeded(
                $content,
                $site,
                $assetImporter,
                $output
            );

            // ---
            // Check if MinervaKB set was created
            // ---

            // Extract MinervaKB set from parsing result
            $minervaKbSet = $minervaKbResult['set'];

            // Check if MinervaKB set was created
            if ($minervaKbSet !== null) {
                // Add MinervaKB set to ordered sets
                self::$orderedSets[] = $minervaKbSet;
            }

            // Mark node as processed
            $processed = true;

            // Set skip count from result
            $skipCount = $minervaKbResult['skipCount'];
        }

        // Check if MinervaKB block was processed, return early
        if ($processed) {
            // Return early with processed status and skip count
            return [
                'processed' => $processed,
                'skipCount' => $skipCount,
            ];
        }

        // ---
        // Handle WordPress core blocks for landing_page blueprint. This must be checked
        // after MinervaKB blocks but before ACF blocks.
        // ---

        // Parse WordPress core block node
        $wordPressCoreBlockResult = $this->wordPressCoreBlockParser->parseNode(
            $node,
            $index,
            $nodes,
            $site,
            $assetImporter,
            $output,
            $attachments,
            $terms,
            $pageSlug
        );

        // Check if WordPress core block was processed
        if ($wordPressCoreBlockResult['processed']) {
            // Finalize any accumulated non-ACF content before adding WordPress core block set
            $this->finalizeNonAcfContentIfNeeded(
                $content,
                $site,
                $assetImporter,
                $output
            );

            // ---
            // Check if WordPress core block set was created
            // ---

            // Extract WordPress core block set from parsing result
            $wordPressCoreBlockSet = $wordPressCoreBlockResult['set'];

            // Check if WordPress core block set was created
            if ($wordPressCoreBlockSet !== null) {
                // Add WordPress core block set to ordered sets
                self::$orderedSets[] = $wordPressCoreBlockSet;
            }

            // Mark node as processed
            $processed = true;

            // Set skip count from result
            $skipCount = $wordPressCoreBlockResult['skipCount'];
        }

        // Check if WordPress core block was processed, return early
        if ($processed) {
            // Return early with processed status and skip count
            return [
                'processed' => $processed,
                'skipCount' => $skipCount,
            ];
        }

        // ---
        // Handle ACF blocks for landing_page blueprint
        // ---

        // Parse ACF block node
        $acfResult = $this->acfParser->parseNode(
            $node,
            $index,
            $nodes,
            $site,
            $assetImporter,
            $output,
            $attachments,
            $terms,
            $items,
            $pageSlug
        );

        // Check if ACF block was processed
        if ($acfResult['processed']) {
            // Finalize any accumulated non-ACF content before adding ACF set
            $this->finalizeNonAcfContentIfNeeded(
                $content,
                $site,
                $assetImporter,
                $output
            );

            // Extract ACF set from parsing result
            $acfSet = $acfResult['set'];

            // Check if ACF set was created
            if ($acfSet !== null) {
                // Add ACF set to ordered sets
                self::$orderedSets[] = $acfSet;
            } else { // Otherwise
                // Get entry data for post_name access
                $entryData = ProcessingContext::getEntryData();

                // ---
                // Log warning if ACF block was processed but no set was created. This typically
                // occurs when SectionParser returns null because both the heading and body are
                // empty (e.g., self-closing blocks with no heading attribute or empty heading
                // field).
                // ---

                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'node_value' => \substr(
                            $node->textContent,
                            0,
                            200
                        ),
                        'index' => $index,
                        'nodes_length' => $nodes->length,
                        'acf_result_keys' => \array_keys($acfResult),
                        'acf_result_processed' => $acfResult['processed'],
                        'acf_result_skip_count' => $acfResult['skipCount'],
                        'wordpress_post_id' => ProcessingContext::getWordPressPostId(),
                        'wordpress_page_link' => ProcessingContext::getWordPressPageLink(),
                        'statamic_entry_id' => ProcessingContext::getStatamicEntryId(),
                        'post_name' => $entryData['post_name'] ?? null,
                    ]
                );

                // Log warning about ACF block processed but no set created
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        'PageBuilderParser::parseNode: ACF block processed but no set created',
                        $logData['page_url']
                    ),
                    $logData['context']
                );
            }

            // Mark node as processed
            $processed = true;

            // Set skip count from ACF result
            $skipCount = $acfResult['skipCount'];
        } else { // Otherwise
            // Parse non-ACF block node
            $nonAcfResult = $this->nonAcfParser->parseNode(
                $node,
                $index,
                $nodes,
                self::$nonAcfAccumulator,
                $site,
                $assetImporter,
                $output,
                $attachments,
                $terms,
                $items,
                $unaddressedBlocks,
                $pageSlug
            );

            // Check if non-ACF block was processed
            if ($nonAcfResult['processed']) {
                // Mark node as processed
                $processed = true;

                // Set skip count from non-ACF result
                $skipCount = $nonAcfResult['skipCount'];

                // ---
                // Add direct page_builder set (e.g., from single blogroll shortcode)
                // ---

                // Check if non-ACF parser returned a direct page_builder set
                if (isset($nonAcfResult['directSet'])) {
                    // Finalize the content ahead of the set, so the set lands after it
                    $this->finalizeNonAcfContentIfNeeded(
                        $content,
                        $site,
                        $assetImporter,
                        $output
                    );

                    // Append the direct set to ordered page_builder sets
                    self::$orderedSets[] = $nonAcfResult['directSet'];

                    // Start the content after the set with what follows its shortcode
                    if (isset($nonAcfResult['htmlAfterSet'])) {
                        self::$nonAcfAccumulator[] = ['html' => $nonAcfResult['htmlAfterSet']];
                    }
                }

                // ---
                // Check if the last item added to accumulator contains ACF blocks in HTML
                // (e.g., from unwrapped wp:group blocks). If so, parse them and add to
                // orderedSets, removing the ACF block HTML from the accumulator.
                // ---

                // Extract HTML content from the last item in the accumulator, if it is an
                // item with HTML
                $lastItem = self::$nonAcfAccumulator[\count(self::$nonAcfAccumulator) - 1] ?? null;
                $html = \is_array($lastItem) ? ($lastItem['html'] ?? null) : null;

                // Check if HTML contains ACF blocks
                if (
                    \is_string($html)
                    && \preg_match(
                        '/<!--\s*wp:acf\//',
                        $html
                    ) === 1
                ) {
                    // Parse ACF blocks from the HTML
                    $acfParseResult = $this->acfParser->parseAcfBlocksFromHtml(
                        $html,
                        $site,
                        $assetImporter,
                        $output,
                        $attachments,
                        $terms,
                        $items,
                        $pageSlug
                    );

                    // Check if any ACF sets were parsed
                    $acfSets = $acfParseResult['acfSets'] ?? [];

                    if (! \is_array($acfSets)) {
                        $acfSets = [];
                    }

                    if ($acfSets !== []) {
                        // ---
                        // Finalize any accumulated non-ACF content before adding ACF sets
                        // (accumulator is guaranteed non-empty at this point)
                        // ---

                        // Finalize accumulated non-ACF content into a set
                        $nonAcfSet = $this->nonAcfParser->finalizeNonAcfContent(
                            self::$nonAcfAccumulator,
                            $content,
                            $site,
                            $assetImporter,
                            $output
                        );

                        // Check if non-ACF set was successfully created
                        if ($nonAcfSet !== null && $nonAcfSet !== []) {
                            // Add finalized non-ACF set to ordered sets
                            self::$orderedSets[] = $nonAcfSet;
                        }

                        // Clear accumulator after finalizing content
                        self::$nonAcfAccumulator = [];

                        // ---
                        // Add ACF sets to ordered sets
                        // ---

                        // Loop through each parsed ACF set and add to ordered sets
                        foreach ($acfSets as $acfSet) {
                            // Add ACF set to ordered sets array
                            self::$orderedSets[] = $acfSet;
                        }

                        // ---
                        // Add remaining non-ACF HTML back to accumulator if it exists
                        // ---

                        // Extract and trim remaining HTML after ACF block extraction
                        $remainingHtmlRaw = $acfParseResult['remainingHtml'] ?? '';
                        $remainingHtml = \is_string($remainingHtmlRaw)
                            ? \trim($remainingHtmlRaw)
                            : '';

                        // Check if there is remaining HTML to add back
                        if ($remainingHtml !== '') {
                            // Add remaining non-ACF HTML back to accumulator
                            self::$nonAcfAccumulator[] = ['html' => $remainingHtml];
                        }
                    }
                }
            }
        }

        // Check if this is the last node or if we've reached the end of processing
        $isLastNode = ($index + $skipCount >= $nodes->length);

        // ---
        // Force finalization for the last node if it wasn't processed
        // ---

        // Check if this is the last node and it wasn't processed
        if ($index >= $nodes->length - 1 && $skipCount === 0) {
            // Set skip count to 1 to mark node as processed
            $skipCount = 1;

            // Mark as last node to trigger finalization
            $isLastNode = true;
        }

        // ---
        // Finalize non-ACF content if this is the last node and we have accumulated content
        // ---

        // Check if this is the last node and we have accumulated non-ACF content
        if ($isLastNode && self::$nonAcfAccumulator !== []) {
            // Finalize accumulated non-ACF content into a set
            $nonAcfSet = $this->nonAcfParser->finalizeNonAcfContent(
                self::$nonAcfAccumulator,
                $content,
                $site,
                $assetImporter,
                $output
            );

            // Check if non-ACF set was created
            if ($nonAcfSet !== null && $nonAcfSet !== []) {
                // Add non-ACF set to ordered sets
                self::$orderedSets[] = $nonAcfSet;
            }

            // Clear non-ACF accumulator after finalizing
            self::$nonAcfAccumulator = [];
        }

        // ---
        // Append collected sets at end of processing
        // ---

        // Check if this is the last node and we have ordered sets to append
        if ($isLastNode && self::$orderedSets !== []) {
            // Loop through ordered sets and append to content
            foreach (self::$orderedSets as $set) {
                // Append set to content array
                $content[] = $set;
            }

            // Clear ordered sets after appending
            self::$orderedSets = [];
        }

        // Return processing result with processed status and skip count
        return ['processed' => $processed, 'skipCount' => $skipCount];
    }
}
