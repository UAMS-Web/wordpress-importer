<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\PageBuilder;

use DOMComment;
use DOMNode;
use DOMNodeList;
use Statamic\Fieldtypes\RowId;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\BardParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\BlockProcessorHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\HeadingDemoter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\FormidableFormsBlockProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\GravityFormsBlockProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\NinjaFormsBlockProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\ShortcodeBlockProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\ShortcodeProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\TablePressBlockProcessor;

/**
 * Parses WordPress non-ACF blocks into Statamic uamsweb_block_content replicator
 * sets.
 *
 * Handles non-ACF blocks (e.g., wp:heading, wp:paragraph) in mixed content
 * scenarios, processing shortcodes and blocks (e.g., wp:tablepress/table) into
 * Bard sets within the body field. Converts accumulated HTML to Bard format using
 * Tiptap, applying link preprocessing and heading demotion.
 */
class NonAcfBlockParser
{
    /**
     * Instance of BardParser for converting HTML to Bard format.
     */
    private BardParser $bardParser;

    /**
     * Instance of LinkProcessor for preprocessing links in HTML content.
     */
    private LinkProcessor $linkProcessor;

    /**
     * Instance of HeadingDemoter for demoting heading levels in Bard content.
     */
    private HeadingDemoter $headingDemoter;

    /**
     * Instance of ShortcodeProcessor for processing shortcodes in content.
     */
    private ShortcodeProcessor $shortcodeProcessor;

    /**
     * Instance of TablePressBlockProcessor for wp:tablepress/table blocks.
     */
    private TablePressBlockProcessor $tablePressBlockProcessor;

    /**
     * Instance of GravityFormsBlockProcessor for wp:gravityforms/form blocks.
     */
    private GravityFormsBlockProcessor $gravityFormsProcessor;

    /**
     * Instance of FormidableFormsBlockProcessor for wp:formidable/simple-form blocks.
     */
    private FormidableFormsBlockProcessor $formidableFormsProcessor;

    /**
     * Instance of NinjaFormsBlockProcessor for wp:ninja-forms/form blocks.
     */
    private NinjaFormsBlockProcessor $ninjaFormsProcessor;

    /**
     * Instance of ShortcodeBlockProcessor for wp:shortcode blocks.
     */
    private ShortcodeBlockProcessor $shortcodeBlockProcessor;

    /**
     * Static variable to track if we're inside a Formidable Forms block.
     * This needs to be reset between pages to prevent cross-contamination.
     */
    private static bool $inFormidableBlock = false;

    /**
     * Constructs a new NonAcfBlockParser instance with its dependencies.
     *
     * Initializes the parser with instances for Bard parsing, link processing,
     * heading demotion, shortcode processing, and block processors. Creates new
     * instances if not provided.
     *
     * @param  ?BardParser  $bardParser  Bard parser for Tiptap parsing
     * @param  ?LinkProcessor  $linkProcessor  Link processor for file imports
     * @param  ?HeadingDemoter  $headingDemoter  Heading demoter for adjustments
     * @param  ?ShortcodeProcessor  $shortcodeProcessor  Shortcode processor
     * @param  ?TablePressBlockProcessor  $tablePressBlockProcessor  TablePress processor
     * @param  ?GravityFormsBlockProcessor  $gravityFormsProcessor  Gravity Forms processor
     * @param  ?FormidableFormsBlockProcessor  $formidableFormsProcessor  Formidable processor
     * @param  ?NinjaFormsBlockProcessor  $ninjaFormsProcessor  Ninja Forms processor
     * @param  ?ShortcodeBlockProcessor  $shortcodeBlockProcessor  Shortcode block processor
     */
    public function __construct(
        ?BardParser $bardParser = null,
        ?LinkProcessor $linkProcessor = null,
        ?HeadingDemoter $headingDemoter = null,
        ?ShortcodeProcessor $shortcodeProcessor = null,
        ?TablePressBlockProcessor $tablePressBlockProcessor = null,
        ?GravityFormsBlockProcessor $gravityFormsProcessor = null,
        ?FormidableFormsBlockProcessor $formidableFormsProcessor = null,
        ?ShortcodeBlockProcessor $shortcodeBlockProcessor = null,
        ?NinjaFormsBlockProcessor $ninjaFormsProcessor = null
    ) {
        $this->bardParser = $bardParser ?? new BardParser;
        $this->linkProcessor = $linkProcessor ?? new LinkProcessor;
        $this->headingDemoter = $headingDemoter ?? new HeadingDemoter;
        $this->shortcodeProcessor = $shortcodeProcessor ?? new ShortcodeProcessor;
        $this->tablePressBlockProcessor = $tablePressBlockProcessor ?? new TablePressBlockProcessor;
        $this->gravityFormsProcessor = $gravityFormsProcessor ?? new GravityFormsBlockProcessor;
        $this->formidableFormsProcessor = $formidableFormsProcessor ?? new FormidableFormsBlockProcessor;
        $this->shortcodeBlockProcessor = $shortcodeBlockProcessor ?? new ShortcodeBlockProcessor($this->shortcodeProcessor);
        $this->ninjaFormsProcessor = $ninjaFormsProcessor ?? new NinjaFormsBlockProcessor;
    }

    /**
     * Resets static variables to prevent cross-contamination between pages.
     *
     * This method should be called at the beginning of processing each new page
     * to ensure that state from previous pages doesn't leak into the current page.
     */
    public function resetStaticVariables(): void
    {
        self::$inFormidableBlock = false;
    }

    /**
     * Parses a DOM node into accumulated non-ACF content with shortcode/block
     * processing.
     *
     * Processes specific blocks (e.g., wp:tablepress/table) and shortcodes in HTML
     * elements (e.g., <p>[table id=1 /]</p>), storing Tiptap-compatible HTML in
     * the accumulator. Skips inner <div> nodes in wp:formidable/simple-form blocks
     * to prevent duplicate processing of [formidable] shortcodes. Skips ACF blocks
     * for AcfBlockParser. Relies on finalizeNonAcfContent for replicator set creation.
     *
     * @param  DOMNode  $node  The current DOM node to parse
     * @param  int  $index  The index of the current node in the node list
     * @param  DOMNodeList<DOMNode>  $nodes  The full list of nodes for context
     * @param  array<int|string, mixed>  &$accumulator  The array to accumulate HTML fragments
     * @param  string  $site  The Statamic site handle for asset paths
     * @param  AssetImporter  $assetImporter  Instance for importing linked files
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects
     * @param  array<int|string, mixed>  $items  Array of SimpleXMLElement items for post ID mapping
     * @param  array<int|string, mixed>  $unaddressedBlocks  List of unaddressed block types
     * @param  ?string  $pageSlug  The page slug (optional)
     * @return array{processed: bool, skipCount: int, directSet?: array<int|string, mixed>, htmlAfterSet?: string} Result with processed flag, skip count, and optional direct set
     */
    public function parseNode(
        DOMNode $node,
        int $index,
        DOMNodeList $nodes,
        array &$accumulator,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = [],
        array $terms = [],
        array $items = [],
        array $unaddressedBlocks = [],
        ?string $pageSlug = null
    ): array {
        $processed = false;
        $skipCount = 0;

        // Handle wp:* comment nodes for specific blocks
        if ($node->nodeType === XML_COMMENT_NODE) {
            $comment = \trim($node->textContent);
            $attributes = [];
            if (
                \preg_match(
                    '/{.+?}/s',
                    $comment,
                    $matches
                ) === 1
            ) {
                $decodedAttributes = \json_decode(
                    $matches[0],
                    true
                );
                $attributes = \is_array($decodedAttributes) ? $decodedAttributes : [];
            }

            if (
                $this->tablePressBlockProcessor->canProcess(
                    $node,
                    $site,
                    $pageSlug
                )
            ) {
                $result = $this->tablePressBlockProcessor->process(
                    $node,
                    $attributes,
                    $site,
                    $assetImporter,
                    $output,
                    $attachments,
                    $terms,
                    $pageSlug
                );
                $resultHtml = $this->extractHtmlFromResult($result);
                if ($resultHtml !== null) {
                    $accumulator[] = ['html' => $resultHtml];
                    $processed = true;
                    $skipCount = $this->resolveSkipCount($result);
                }
            } elseif (
                $this->gravityFormsProcessor->canProcess(
                    $node,
                    $site,
                    $pageSlug
                )
            ) {
                $result = $this->gravityFormsProcessor->process(
                    $node,
                    $attributes,
                    $site,
                    $assetImporter,
                    $output,
                    $attachments,
                    $terms,
                    $pageSlug
                );
                $resultHtml = $this->extractHtmlFromResult($result);
                if ($resultHtml !== null) {
                    $accumulator[] = ['html' => $resultHtml];
                    $processed = true;
                    $skipCount = $this->resolveSkipCount($result);
                }
            } elseif (
                $this->formidableFormsProcessor->canProcess(
                    $node,
                    $site,
                    $pageSlug
                )
            ) {
                $result = $this->formidableFormsProcessor->process(
                    $node,
                    $attributes,
                    $site,
                    $assetImporter,
                    $output,
                    $attachments,
                    $terms,
                    $pageSlug
                );
                $resultHtml = $this->extractHtmlFromResult($result);
                if ($resultHtml !== null) {
                    $accumulator[] = ['html' => $resultHtml];
                    $processed = true;
                    $skipCount = $this->resolveSkipCount($result);
                    self::$inFormidableBlock = true; // Mark that we're inside a Formidable Forms block
                }
            } elseif (
                $this->ninjaFormsProcessor->canProcess(
                    $node,
                    $site,
                    $pageSlug
                )
            ) {
                // Process Ninja Forms blocks, which record the referenced form ID for
                // the form-import pass and emit a placeholder in its place
                $result = $this->ninjaFormsProcessor->process(
                    $node,
                    $attributes,
                    $site,
                    $assetImporter,
                    $output,
                    $attachments,
                    $terms,
                    $pageSlug
                );
                $resultHtml = $this->extractHtmlFromResult($result);
                if ($resultHtml !== null) {
                    $accumulator[] = ['html' => $resultHtml];
                    $processed = true;
                    $skipCount = $this->resolveSkipCount($result);
                }
            } elseif (
                $this->shortcodeBlockProcessor->canProcess(
                    $node,
                    $site,
                    $pageSlug
                )
            ) {
                // Process shortcode blocks using the shortcode block processor
                $result = $this->shortcodeBlockProcessor->process(
                    $node,
                    $attributes,
                    $site,
                    $assetImporter,
                    $output,
                    $attachments,
                    $terms,
                    $pageSlug
                );

                // Check if shortcode produced a page_builder set (e.g., single blogroll)
                if (
                    \is_array($result)
                    && isset($result['page_builder_set'])
                    && \is_array($result['page_builder_set'])
                ) {
                    // Use skip count from shortcode processor result
                    $skipCount = $this->resolveSkipCount($result);

                    // The set stands alone, so what comes before its shortcode joins
                    // the content ahead of it (#180)
                    if (\is_string($result['html_before'] ?? null)) {
                        $accumulator[] = ['html' => $result['html_before']];
                    }

                    // Return result with direct set for PageBuilderParser
                    $directResult = [
                        'processed' => true,
                        'skipCount' => $skipCount,
                        'directSet' => $result['page_builder_set'],
                    ];

                    // What comes after the shortcode can only follow the set
                    if (\is_string($result['html_after'] ?? null)) {
                        $directResult['htmlAfterSet'] = $result['html_after'];
                    }

                    return $directResult;
                }

                // Check if shortcode processing was successful and add to accumulator
                if ($result !== null && $result !== '') {
                    $htmlOutput = \is_array($result)
                        ? (
                            isset($result['html']) && \is_string($result['html'])
                                ? $result['html']
                                : ''
                        )
                        : $result;

                    // Add shortcode output as one HTML fragment in accumulator
                    $accumulator[] = [
                        'html' => $htmlOutput,
                    ];

                    // Mark that we processed this block
                    $processed = true;

                    // Skip past wp:shortcode comment in node list
                    $skipCount = 1;

                    // Loop from next node to find closing comment
                    for ($i = $index + 1; $i < $nodes->length; $i++) {
                        // Increment skip count for each node
                        $skipCount++;

                        $candidateNode = $nodes->item($i);

                        // Check if this node is the closing wp:shortcode comment
                        if (
                            $candidateNode instanceof DOMComment
                            && \preg_match(
                                '/^\/wp:shortcode\s*$/',
                                \trim($candidateNode->data)
                            ) === 1
                        ) {
                            // Stop counting when closing comment is found
                            break;
                        }
                    }
                }
            } else { // Otherwise
                // Process node using helper with common block processors
                $result = BlockProcessorHelper::processBlockNodeWithCommonProcessors(
                    $node,
                    $site,
                    $assetImporter,
                    $output,
                    $attachments,
                    $terms,
                    $pageSlug
                );

                // Check if block was successfully processed by a common processor
                $resultHtml = $this->extractHtmlFromFragment($result);

                if ($resultHtml !== null) {
                    // Add the processed HTML to the accumulator
                    $accumulator[] = ['html' => $resultHtml];

                    // Mark this node as processed
                    $processed = true;

                    // Set the skip count from the processor result
                    $skipCount = $this->resolveSkipCount($result);
                }
            }

            // Check if node is unprocessed and is closing Formidable Forms block tag
            if (
                ! $processed
                && \preg_match(
                    '/^\/wp:formidable\/simple-form\s*$/',
                    $comment
                ) === 1
            ) {
                self::$inFormidableBlock = false; // End of Formidable Forms block
                $processed = true;
                $skipCount = 1;
            }
        }

        // Skip <div> nodes containing [formidable] shortcodes if inside a Formidable Forms block
        if (self::$inFormidableBlock && $node->nodeType === XML_ELEMENT_NODE && $node->nodeName === 'div') {
            $htmlFragment = BlockProcessorHelper::convertNodeToHtmlFragment($node);
            $formidableHtml = $this->extractHtmlFromFragment($htmlFragment);
            if (
                $formidableHtml !== null
                && \preg_match(
                    '/<div>\s*\[formidable\s+id="\d+"\s*[^]]*\]\s*<\/div>/',
                    $formidableHtml
                ) === 1
            ) {
                return ['processed' => true, 'skipCount' => 1];
            }
        }

        // Handle HTML elements (e.g., <p>, <h2>)
        if ($node->nodeType === XML_ELEMENT_NODE) {
            $htmlFragment = BlockProcessorHelper::convertNodeToHtmlFragment($node);
            $htmlContent = $this->extractHtmlFromFragment($htmlFragment);
            if ($htmlContent !== null) {
                // Process shortcodes within the HTML
                $processedHtml = $this->shortcodeProcessor->processShortcodesInContent(
                    $htmlContent,
                    $site,
                    $assetImporter,
                    $output,
                    $attachments,
                    $terms,
                    $unaddressedBlocks,
                    $pageSlug
                );
                $accumulator[] = ['html' => $processedHtml];
                $processed = true;
                $skipCount = 1;
            }
        }

        // Handle text nodes
        if ($node->nodeType === XML_TEXT_NODE && \trim($node->textContent) !== '') {
            $htmlFragment = BlockProcessorHelper::convertNodeToHtmlFragment($node);
            $htmlContent = $this->extractHtmlFromFragment($htmlFragment);
            if ($htmlContent !== null) {
                // Process shortcodes within the text
                $processedHtml = $this->shortcodeProcessor->processShortcodesInContent(
                    $htmlContent,
                    $site,
                    $assetImporter,
                    $output,
                    $attachments,
                    $terms,
                    $unaddressedBlocks,
                    $pageSlug
                );
                $accumulator[] = ['html' => $processedHtml];
                $processed = true;
                $skipCount = 1;
            }
        }

        return ['processed' => $processed, 'skipCount' => $skipCount];
    }

    /**
     * Finalizes accumulated non-ACF content into a uamsweb_block_content set.
     *
     * Concatenates processed HTML fragments (including Bard set HTML), preprocesses
     * links, parses into Bard format with Tiptap, and applies heading demotion.
     * Returns the replicator set for page_builder, or null if no content.
     *
     * @param  array<int|string, mixed>  $nonAcfContent  Accumulated HTML fragments
     * @param  array<int|string, mixed>  &$content  The page_builder content array (unused)
     * @param  string  $site  The Statamic site handle for asset paths
     * @param  AssetImporter  $assetImporter  Instance for importing linked files
     * @param  OutputInterface  $output  Console output for logging
     * @return array<int|string, mixed>|null The uamsweb_block_content replicator set, or null if empty
     */
    public function finalizeNonAcfContent(
        array $nonAcfContent,
        array &$content,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output
    ): ?array {
        // Concatenate HTML fragments
        $htmlFragments = [];
        foreach ($nonAcfContent as $fragment) {
            if (
                \is_array($fragment)
                && isset($fragment['html'])
                && \is_string($fragment['html'])
            ) {
                $htmlFragments[] = $fragment['html'];
            }
        }

        $html = \implode(
            '',
            $htmlFragments
        );

        // Check if the concatenated HTML is empty or only contains whitespace
        if ((\trim($html) === '')) {
            return null;
        }

        // Preprocess links for file imports
        $processedHtml = $this->linkProcessor->process(
            $html,
            $site,
            $assetImporter,
            $output,
            ProcessingContext::getStatamicEntryId(),
            ProcessingContext::getCollectionHandle(),
            ProcessingContext::getXmlPath()
        );

        // Parse with Tiptap into Bard format
        $bardContent = $this->bardParser->parse($processedHtml);

        // Check if the Bard content is empty after parsing
        if ($bardContent === []) {
            return null;
        }

        // Apply heading demotion (h2→h3, h6→bold paragraph)
        $finalizedContent = $this->headingDemoter->demote($bardContent);

        // Final check: ensure the finalized content is not empty
        if ($finalizedContent === []) {
            return null;
        }

        // Return replicator set
        return [
            'id' => (new RowId)->generate(),
            'type' => 'uamsweb_block_content',
            'block_heading' => 'Placeholder',
            'hide_block_heading' => false,
            'background_color' => 'bg-auto',
            'body' => $finalizedContent,
            'enabled' => true,
        ];
    }

    /**
     * Resolve skipCount from a processor result.
     *
     * @param  mixed  $result  Raw processor result
     * @return int Normalized skip count value
     */
    private function resolveSkipCount(
        mixed $result
    ): int {
        return \is_array($result)
            && isset($result['skipCount'])
            && \is_int($result['skipCount'])
            ? $result['skipCount']
            : 1;
    }

    /**
     * Extract HTML string from a processor result.
     *
     * @param  mixed  $result  Raw processor result
     * @return string|null HTML content when available
     */
    private function extractHtmlFromResult(
        mixed $result
    ): ?string {
        if (
            ! \is_array($result)
            || ! isset($result['html'])
            || ! \is_string($result['html'])
        ) {
            return null;
        }

        return $result['html'];
    }

    /**
     * Extract HTML string from a fragment array.
     *
     * @param  array<int|string, mixed>|null  $fragment  HTML fragment array
     * @return string|null HTML content when available
     */
    private function extractHtmlFromFragment(
        ?array $fragment
    ): ?string {
        $html = $fragment['html'] ?? null;

        return \is_string($html) ? $html : null;
    }
}
