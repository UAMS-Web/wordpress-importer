<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;

/**
 * Processes WordPress wp:shortcode blocks into Tiptap-compatible HTML for
 * Statamic Bard sets.
 */
class ShortcodeBlockProcessor implements BlockProcessorInterface
{
    /**
     * Shortcodes whose lone block returns html and a skip count with no page
     * builder set, the shape it had when the handler built one.
     *
     * @var list<string>
     */
    private const array HTML_ONLY_SHORTCODES = ['caption'];

    /**
     * The shortcode processor instance.
     */
    private ShortcodeProcessor $shortcodeProcessor;

    /**
     * Constructor to initialize dependencies.
     *
     * @param  ?ShortcodeProcessor  $shortcodeProcessor  The shortcode processor instance
     */
    public function __construct(
        ?ShortcodeProcessor $shortcodeProcessor = null
    ) {
        $this->shortcodeProcessor = $shortcodeProcessor ?? new ShortcodeProcessor;
    }

    /**
     * Determines if the processor can handle the given DOM node.
     *
     * @param  DOMNode  $node  The DOM node to check
     * @param  string  $site  The site handle
     * @param  ?string  $pageSlug  The page slug (optional)
     * @return bool True if the node is a wp:shortcode comment, false otherwise
     */
    public function canProcess(
        DOMNode $node,
        string $site,
        ?string $pageSlug
    ): bool {
        if ($node->nodeType !== XML_COMMENT_NODE) {
            return false;
        }

        return self::matchesOpeningShortcodeComment($node);
    }

    /**
     * Processes a wp:shortcode block into Tiptap-compatible HTML.
     *
     * Extracts shortcode content between wp:shortcode comments, processes
     * shortcodes with site and page context using ShortcodeProcessor, and returns
     * Tiptap-compatible HTML for Bard sets. Handles text and element nodes,
     * ignoring empty content.
     *
     * @param  DOMNode  $node  The DOM node (wp:shortcode comment)
     * @param  array<int|string, mixed>  $attributes  Block attributes (unused for wp:shortcode)
     * @param  string  $site  Site handle for shortcode processing
     * @param  AssetImporter  $assetImporter  Asset importer instance (unused)
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $attachments  Media attachments (unused)
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects from XML
     * @param  ?string  $pageSlug  The page slug for shortcode processing (optional)
     * @return array<int|string, mixed>|string|null Tiptap HTML string; or an array with 'html', 'skipCount' and 'page_builder_set'
     *                                              when a single shortcode returns a replicator block, and with 'html' and 'skipCount'
     *                                              alone for a single [caption]; or null if no valid content
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
        $shortcodeContent = self::blockContent($node);

        if ($shortcodeContent === '') {
            // ---
            // Collect node information for debugging
            // ---

            // Initialize node information array
            $nodeInfo = [];

            // Start at first sibling after wp:shortcode
            $debugNode = $node->nextSibling;

            // Initialize node count
            $nodeCount = 0;

            // Walk siblings until closing comment
            while (
                $debugNode instanceof DOMNode
                && $nodeCount < 10
                && ! self::matchesClosingShortcodeComment($debugNode)
            ) {
                // Add node information to array
                $nodeInfo[] = [
                    'type' => $debugNode::class,
                    'nodeType' => $debugNode->nodeType,
                    'content_preview' => \substr(
                        $debugNode->nodeValue ?? $debugNode->textContent ?? '',
                        0,
                        100
                    ),
                    'is_empty_after_trim' => $debugNode instanceof DOMText
                        ? \trim(
                            self::extractNodeValueString($debugNode->nodeValue)
                        ) === ''
                        : null,
                ];

                // Advance to next sibling
                $debugNode = $debugNode->nextSibling;

                // Increment node count
                $nodeCount++;
            }

            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'pageSlug' => $pageSlug ?? 'none',
                    'node_info' => $nodeInfo,
                    'nodes_checked' => $nodeCount,
                ]
            );

            // Log warning about missing content
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'ShortcodeBlockProcessor::process: No valid content found in wp:shortcode block',
                    $logData['page_url']
                ),
                $logData['context']
            );

            return null;
        }

        // Process shortcodes within the block
        $shortcodes = $this->shortcodeProcessor->extract(
            $shortcodeContent,
            $output
        );

        // Check if no shortcodes were extracted from content
        if (
            $shortcodes === []

        ) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'pageSlug' => $pageSlug ?? 'none',
                    'shortcode_content' => $shortcodeContent,
                    'content_length' => \strlen($shortcodeContent),
                    'content_preview' => \substr($shortcodeContent, 0, 200),
                    'starts_with_bracket' => \str_starts_with(\trim($shortcodeContent), '['),
                    'ends_with_bracket' => \str_ends_with(\trim($shortcodeContent), ']'),
                ]
            );

            // Log warning if no shortcodes were extracted from content
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'ShortcodeBlockProcessor::process: No shortcodes extracted from wp:shortcode block content',
                    $logData['page_url']
                ),
                $logData['context']
            );
        }

        // ---
        // When the block holds exactly one registered shortcode, try process(...,
        // 'page_builder'), and return the html with the page builder set when the
        // handler builds one, or the html alone for a caption
        // ---

        // Check if block contains exactly one shortcode
        if (\count($shortcodes) === 1) {
            // Get the single shortcode from the list
            $single = $shortcodes[0];

            // Guard against malformed shortcode payloads before accessing keys
            if (! \is_array($single)) {
                $single = [];
            }

            // Extract shortcode name for handler lookup
            $name = self::extractShortcodeName($single);

            // Check if a handler is registered for this shortcode
            if (isset($this->shortcodeProcessor->getHandlers()[$name])) {
                // Use the single shortcode for page_builder processing
                $shortcode = $single;

                // Clear id so handler does not treat as block-scoped
                $shortcode['id'] = '';

                // Clear link so handler does not treat as block-scoped
                $shortcode['link'] = '';

                // Process shortcode for page_builder; may return replicator set or null
                $pageBuilderBlock = $this->shortcodeProcessor->process(
                    $shortcode,
                    $site,
                    $assetImporter,
                    $output,
                    $terms,
                    'page_builder',
                    $attachments,
                    $pageSlug
                );

                // A replicator block (it has a type) is the page builder set
                $hasPageBuilderSet = isset($pageBuilderBlock['type']);

                // ---
                // Return the html with a skip count over the whole block, so the
                // caller does not read the block's inner nodes a second time, when
                // the handler builds a page builder set, or for a caption, which
                // builds none (#119) but keeps the shape it had when it did, so the
                // Bard route still adds its image once. Any other shortcode falls to
                // the loop below, which renders it itself. The name and the list are
                // strings, so in_array()'s strict flag, which phpstan-strict-rules
                // requires, changes no result.
                // ---
                // @pest-mutate-ignore: TrueToFalse
                if (
                    $hasPageBuilderSet
                    || \in_array($name, self::HTML_ONLY_SHORTCODES, true)
                ) {
                    // ---
                    // Generate Tiptap HTML so the Bard path (TextProcessor) can add
                    // this as content. Callers that need page_builder (e.g.,
                    // NonAcfBlockParser) use page_builder_set when there is one, and
                    // the html otherwise.
                    // ---
                    $tiptapHtmlForBard = $this->shortcodeProcessor->toTiptapHtml(
                        $single,
                        $site,
                        $assetImporter,
                        $output,
                        $attachments,
                        $terms,
                        $pageSlug
                    );

                    $result = [
                        'html' => $tiptapHtmlForBard,
                        'skipCount' => self::blockSkipCount($node),
                    ];

                    // Return the page builder set beside the html when there is one
                    if ($hasPageBuilderSet) {
                        $result['page_builder_set'] = $pageBuilderBlock;
                    }

                    return $result;
                }
            }
        }

        // Initialize processed content with original content
        $processedContent = $shortcodeContent;

        // Initialize flag to track if any shortcodes were processed
        $hasProcessedShortcode = false;

        // Process each shortcode and replace with Tiptap HTML
        foreach ($shortcodes as $shortcode) {
            // Skip malformed shortcode payloads to avoid invalid offset access.
            if (! \is_array($shortcode)) {
                continue;
            }

            // Extract common fields with strict scalar guards.
            $shortcodeName = self::extractShortcodeName($shortcode);
            $shortcodeOriginal = self::extractShortcodeOriginal($shortcode);

            // Check if a handler is registered for this shortcode
            if (isset($this->shortcodeProcessor->getHandlers()[$shortcodeName])) {
                // Convert shortcode to Tiptap HTML
                $tiptapHtml = $this->shortcodeProcessor->toTiptapHtml(
                    $shortcode,
                    $site,
                    $assetImporter,
                    $output,
                    $attachments,
                    $terms,
                    $pageSlug
                );

                // Check if Tiptap HTML was generated successfully
                if ($tiptapHtml !== '') {
                    // Replace shortcode with Tiptap HTML
                    $processedContent = \str_replace(
                        $shortcodeOriginal,
                        $tiptapHtml,
                        $processedContent
                    );
                    $hasProcessedShortcode = true;
                } else {
                    // Enhance log context with entry information
                    $logData = LogContextHelper::enhanceWithEntryContext(
                        [
                            'site' => $site,
                            'pageSlug' => $pageSlug ?? 'none',
                            'shortcode_name' => $shortcodeName,
                            'shortcode_original' => $shortcodeOriginal,
                        ]
                    );

                    // Log warning when shortcode processing returns empty HTML
                    Log::warning(
                        LogContextHelper::formatMessageWithEntry(
                            "ShortcodeBlockProcessor::process: toTiptapHtml returned empty string for shortcode '{$shortcodeName}'",
                            $logData['page_url']
                        ),
                        $logData['context']
                    );
                }
            } elseif ($this->shortcodeProcessor->isDroppedShortcode($shortcodeName)) {
                // ---
                // Recognized-but-dropped shortcode (e.g. subpage-list): strip it so
                // the literal [shortcode] text never reaches the imported body. The
                // Statamic page template renders this output on its own, so there is
                // nothing to convert and no warning to raise. See issue wordpress-importer-archive#317.
                // ---
                $processedContent = \str_replace(
                    $shortcodeOriginal,
                    '',
                    $processedContent
                );
                $hasProcessedShortcode = true;
            }
        }

        // A recognized-but-dropped shortcode that was the sole content of a wrapper
        // (e.g. "<p>[subpage-list]</p>") leaves an empty "<p></p>" behind. When deciding
        // whether anything meaningful survived, ignore such empty-paragraph residue so a
        // fully-dropped block contributes nothing rather than an empty paragraph. This is
        // scoped to runs that actually dropped a shortcode and only affects the emptiness
        // decision — the returned value is always the unmodified content. See issue wordpress-importer-archive#317.
        $remainingContent = $hasProcessedShortcode
            ? (string) \preg_replace(
                '~<p\b[^>]*>(?:\s|&nbsp;|&#160;|&#xA0;)*</p>~i',
                '',
                $processedContent
            )
            : $processedContent;
        // Check if we processed any shortcodes and meaningful content remains
        if ($hasProcessedShortcode
        && \trim($remainingContent) !== '') {
            // Return processed content when shortcodes were successfully converted
            return $processedContent;
        }

        // Check if we processed any shortcodes and meaningful content remains
        if (! $hasProcessedShortcode
        && \trim($processedContent) !== '') {
            // Check if content exists but no shortcodes were processed
            // Return original content if no shortcodes were processed but content exists
            return $processedContent;
        }

        // ---
        // We recognized and handled shortcode(s), but they intentionally produced no
        // output (e.g. a block whose only content was a dropped [subpage-list]).
        // Return an empty string, not null: callers treat an empty string as
        // "handled, nothing to add" and drop it silently, whereas null is logged by
        // TextProcessor::processForBard as a block-processor failure. See issue wordpress-importer-archive#317.
        // ---
        if ($hasProcessedShortcode) {
            return '';
        }

        // Return null only if content is truly empty
        return null;
    }

    /**
     * Determine whether a node matches wp:shortcode comment syntax.
     *
     * @param  DOMNode  $node  The node to evaluate
     * @return bool True when the node is an opening wp:shortcode comment
     */
    private static function matchesOpeningShortcodeComment(
        DOMNode $node
    ): bool {
        if (! $node instanceof DOMComment) {
            return false;
        }

        return \preg_match(
            '/^wp:shortcode\s*$/',
            \trim(
                self::extractNodeValueString($node->nodeValue)
            )
        ) === 1;
    }

    /**
     * Reads the content of a wp:shortcode block: its text, trimmed, and its elements'
     * HTML, from the opening comment to the closing one.
     *
     * process() returns this content unchanged when it converts no shortcode in it,
     * which is how a caller can tell that the block's own nodes still hold it.
     *
     * @param  DOMNode  $node  The opening wp:shortcode comment
     * @return string The block's content, trimmed
     */
    public static function blockContent(
        DOMNode $node
    ): string {
        // Collect the content of each node up to the closing comment
        $shortcodeContent = '';
        $currentNode = $node->nextSibling;

        while (
            $currentNode instanceof DOMNode
            && ! self::matchesClosingShortcodeComment($currentNode)
        ) {
            if ($currentNode instanceof DOMText) {
                $textContent = \trim(
                    self::extractNodeValueString($currentNode->nodeValue)
                );
                if ($textContent !== '') {
                    $shortcodeContent .= $textContent;
                }
            } elseif ($currentNode instanceof DOMElement) {
                $ownerDocument = $currentNode->ownerDocument;

                if ($ownerDocument instanceof DOMDocument) {
                    $nodeContent = $ownerDocument->saveHTML($currentNode);

                    if (\is_string($nodeContent)) {
                        $shortcodeContent .= $nodeContent;
                    }
                }
            }

            $currentNode = $currentNode->nextSibling;
        }

        // Each piece is trimmed text or an element's HTML, which saveHTML() writes
        // with no whitespace around it, so the content has none at its edges
        // @pest-mutate-ignore: UnwrapTrim
        return \trim($shortcodeContent);
    }

    /**
     * Counts the nodes from a wp:shortcode comment through its closing comment.
     *
     * @param  DOMNode  $node  The opening wp:shortcode comment
     * @return int The number of nodes to skip, the opening comment included
     */
    public static function blockSkipCount(
        DOMNode $node
    ): int {
        // Start skip count with the wp:shortcode comment node
        $skipCount = 1;

        // Start at first sibling after wp:shortcode
        $currentNode = $node->nextSibling;

        // Walk siblings until closing comment
        while (
            $currentNode instanceof DOMNode
            && ! self::matchesClosingShortcodeComment($currentNode)
        ) {
            // Increment skip count for each node before closing comment
            $skipCount++;

            // Advance to next sibling
            $currentNode = $currentNode->nextSibling;
        }

        // Include the closing comment when it was found
        if ($currentNode instanceof DOMNode) {
            $skipCount++;
        }

        return $skipCount;
    }

    /**
     * Determine whether a node matches /wp:shortcode comment syntax.
     *
     * @param  DOMNode  $node  The node to evaluate
     * @return bool True when the node is a closing wp:shortcode comment
     */
    private static function matchesClosingShortcodeComment(
        DOMNode $node
    ): bool {
        if (! $node instanceof DOMComment) {
            return false;
        }

        return \preg_match(
            '/^\/wp:shortcode\s*$/',
            \trim(
                self::extractNodeValueString($node->nodeValue)
            )
        ) === 1;
    }

    /**
     * Extract a safe string from a node value.
     *
     * @param  ?string  $nodeValue  Raw node value from DOM node
     * @return string Node value or empty string when absent
     */
    private static function extractNodeValueString(
        ?string $nodeValue
    ): string {
        return $nodeValue ?? '';
    }

    /**
     * Extract shortcode name as a safe scalar string.
     *
     * @param  array<int|string, mixed>  $shortcode  Shortcode payload
     * @return string Shortcode name or empty string when invalid
     */
    private static function extractShortcodeName(
        array $shortcode
    ): string {
        $name = $shortcode['name'] ?? '';

        return \is_string($name) ? $name : '';
    }

    /**
     * Extract original shortcode text as a safe scalar string.
     *
     * @param  array<int|string, mixed>  $shortcode  Shortcode payload
     * @return string Original shortcode text or empty string when invalid
     */
    private static function extractShortcodeOriginal(
        array $shortcode
    ): string {
        $original = $shortcode['original'] ?? '';

        return \is_string($original) ? $original : '';
    }
}
