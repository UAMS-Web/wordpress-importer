<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\AcfGalleryBlockProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\BlockProcessorInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\EmbedProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\FileBlockProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\FormidableFormsBlockProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\GravityFormsBlockProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\ImageProcessor\WpImageBlockProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\ImageProcessor\WpPostFeaturedImageBlockProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\NinjaFormsBlockProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\QuoteProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\ShortcodeBlockProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\ShortcodeProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\TablePressBlockProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\TableProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\WpBlockBlockProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\WpDetailsBlockProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\WpGalleryBlockProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\WpGroupBlockProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\WpSpacerBlockProcessor;

/**
 * Utility class for processing text content during WordPress import.
 *
 * Provides methods for text manipulation, DOM handling, and unified Bard field
 * processing. Fully removes processed [caption] shortcodes to prevent redundant
 * image sets and text nodes. Strips <span> tags with data-ccp-props attributes
 * to avoid erroneous line breaks in Bard output. Processes wp:image blocks and
 * standalone images consistently across non-ACF and InnerBlocks content.
 */
class TextProcessor
{
    /**
     * Static cache for processor instances to avoid repeated instantiation.
     *
     * @var array<string, object>
     */
    private static array $cachedProcessors = [];

    /**
     * Decodes HTML entities in a string to ensure proper UTF-8 character rendering.
     *
     * Uses ENT_QUOTES | ENT_HTML5 flags to decode both single and double quotes, as
     * well as HTML5-specific entities, ensuring compatibility with WordPress
     * content. Returns the decoded string, preserving UTF-8 encoding.
     *
     * @param  string  $content  The input string containing HTML entities
     * @return string The decoded string with proper UTF-8 characters
     */
    public static function decodeEntities(
        string $content
    ): string {
        return html_entity_decode(
            $content,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
    }

    /**
     * Decodes HTML entities everywhere except inside HTML comments.
     *
     * WordPress block markers carry their attributes as JSON inside the comment
     * (e.g. `<!-- wp:acf/uams-content {"data":{...}} /-->`). Running a blanket
     * entity decode over that JSON corrupts it: `&quot;` becomes a bare `"` that
     * lands unescaped inside a JSON string value, so `json_decode` fails, the block
     * is silently dropped, and only a misleading "empty body" warning is logged
     * (issue wordpress-importer-archive#290). This preserves comment interiors verbatim so block-comment JSON
     * reaches `json_decode` intact, while decoding entities in all other content
     * exactly as {@see self::decodeEntities()} would. Entities in the JSON's field
     * values are still decoded downstream when each block parser runs them through
     * {@see self::processForBard()} or {@see HtmlTagStripper::strip()}.
     *
     * @param  string  $content  The raw content string to decode
     * @return string The content with entities decoded outside of HTML comments
     */
    public static function decodeEntitiesPreservingComments(
        string $content
    ): string {
        // Split on HTML comments, keeping the comments as captured delimiters so we
        // can decode the segments between them and leave the comments untouched.
        $segments = \preg_split(
            '/(<!--.*?-->)/s',
            $content,
            flags: PREG_SPLIT_DELIM_CAPTURE
        );

        // Fall back to a full decode if splitting failed (e.g. a PCRE limit was hit)
        // rather than silently skipping entity decoding for the whole document.
        if ($segments === false) {
            return self::decodeEntities($content);
        }

        // Reassemble, decoding only the non-comment segments.
        $result = '';
        foreach ($segments as $segment) {
            // Captured comment delimiters start with the comment opener; keep them as-is.
            if (str_starts_with($segment, '<!--')) {
                $result .= $segment;
            } else {
                $result .= self::decodeEntities($segment);
            }
        }

        return $result;
    }

    /**
     * Normalizes a string by removing control characters.
     *
     * Removes control characters (U+0000 to U+001F, U+0080 to U+009F) for clean text
     * while preserving non-breaking spaces (U+00A0) and other valid UTF-8 characters.
     * The input is assumed to already be valid UTF-8 to avoid double-encoding issues.
     *
     * @param  string  $content  The input string to normalize
     * @return string The normalized string with control characters removed
     */
    public static function normalizeUtf8(
        string $content
    ): string {
        // Remove control characters (U+0000 to U+001F, U+0080 to U+009F)
        // but preserve non-breaking spaces (U+00A0) which are valid UTF-8
        $normalized = \preg_replace(
            '/[\x00-\x08\x0B\x0C\x0E-\x1F\x80-\x9F]/u',
            '',
            $content
        );

        // Preserve the original input if regex replacement fails
        if ($normalized === null) {
            return $content;
        }

        // Return the normalized UTF-8 string
        return $normalized;
    }

    /**
     * Loads HTML into a DOMDocument with UTF-8 encoding.
     *
     * Initializes a DOMDocument, suppresses parsing errors, and loads the HTML
     * content wrapped in a <div> to ensure a single root element. Preserves
     * Gutenberg block comments and interleaved elements (e.g., lists) to avoid
     * premature tag closure. Uses UTF-8 encoding and specific LIBXML flags to
     * handle WordPress content correctly. Clears libxml errors after loading.
     *
     * @param  string  $html  The HTML content to load
     * @return DOMDocument The initialized DOMDocument with loaded HTML
     */
    public static function loadHtml(
        string $html
    ): DOMDocument {
        // Create a new DOMDocument with UTF-8 encoding
        $doc = new DOMDocument(
            '1.0',
            'UTF-8'
        );

        // Enable formatted output for better readability. Nothing is parsed into
        // this document, so its whitespace setting has no effect.
        $doc->formatOutput = true;

        // Convert HTML entities to UTF-8 equivalents
        $html = html_entity_decode(
            $html,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        // ---
        // Convert every non-ASCII character to a numeric HTML entity. DOMDocument
        // assumes Latin-1 for the string handed to loadHTML(), so a raw multi-byte
        // character comes back out of saveHTML() mojibaked; pre-encoding is what
        // keeps the round-trip clean.
        //
        // This replaces mb_convert_encoding(…, 'HTML-ENTITIES', …), which PHP 8.4
        // deprecates. That produced *named* entities where one existed (`&mdash;`)
        // and numeric ones otherwise, while this produces numeric throughout.
        // For well-formed markup the difference is intermediate-only — loadHTML()
        // decodes both forms to the same character, so the final saveHTML() output
        // is byte-identical (verified by differential fuzzing, 4000 documents).
        //
        // The one case where output does differ is malformed markup in which a
        // '<' is immediately followed by a multi-byte character, e.g. "<日x". libxml
        // invents a bogus element whose *name* contains the entity text, and a
        // name is never entity-decoded, so the two spellings survive into the
        // output. Both results are equally garbage for an input that was already
        // broken, so this is a difference in the wreckage rather than a regression.
        // ---

        // Encode from the first non-ASCII code point. Starting at DEL (0x7F) would
        // also encode it, which libxml decodes back to DEL.
        // @pest-mutate-ignore: DecrementInteger
        $firstEncoded = 0x80;

        // Encode up to the last Unicode code point; none lies past it
        // @pest-mutate-ignore: IncrementInteger
        $lastEncoded = 0x10FFFF;

        $html = mb_encode_numericentity(
            $html,
            [$firstEncoded, $lastEncoded, 0, 0x1FFFFF],
            'UTF-8'
        );

        // Create a root div element to contain all content
        $root = $doc->createElement('div');

        // Append the root element to the document
        $doc->appendChild($root);

        // Wrap HTML in a temporary container to ensure a single root
        $wrappedHtml = '<div>'.$html;
        $wrappedHtml .= '</div>';

        // Load HTML into a temporary document with specific flags
        $tempDoc = new DOMDocument(
            '1.0',
            'UTF-8'
        );

        // The temporary document keeps whitespace by default and is never serialized,
        // so neither its whitespace nor its output format is set

        // ---
        // LIBXML flags for HTML parsing (wordpress-importer-archive#1273). No LIBXML_NOENT: entity
        // substitution is not wanted on post content, and dropping it changed none
        // of the 82,459 post bodies in the exports corpus. LIBXML_PARSEHUGE stays
        // because real content needs it: three inside.uams.edu/clinicaleducation
        // pages carry runs of unclosed <a><strong> that nest 318 to 786 elements
        // deep, past libxml's default 256, and without the flag their text is lost.
        // ---
        // The load below is @-suppressed, so libxml's errors and warnings never
        // surface whether or not these two flags are set
        // @pest-mutate-ignore: BitwiseOrToBitwiseAnd
        $flags = LIBXML_NOERROR | LIBXML_NOWARNING;
        $flags |= LIBXML_PARSEHUGE | LIBXML_COMPACT | LIBXML_NONET | LIBXML_NSCLEAN;

        // Check if content contains WordPress block comments that need CDATA
        // preservation. libxml's HTML parser creates no CDATA sections, so the flag
        // changes nothing it builds: measured identical with and without it for
        // CDATA in a paragraph, a script, a style, an SVG and a block comment.
        // @pest-mutate-ignore: IfNegated, RemoveNot
        if (! self::containsWordPressBlockComments($wrappedHtml)) {
            // Add LIBXML_NOCDATA flag to disable CDATA parsing for non-block content
            $flags |= LIBXML_NOCDATA;
        }

        // Load HTML with conditional CDATA handling
        @$tempDoc->loadHTML(
            $wrappedHtml,
            $flags
        );

        // Import nodes from the temporary document's <div>
        $div = $tempDoc->getElementsByTagName('div')->item(0);

        // Check if the div element exists in the temporary document
        if ($div !== null) {
            // Iterate through all child nodes of the div
            foreach ($div->childNodes as $node) {
                // Import the node into the main document
                $importedNode = $doc->importNode(
                    $node,
                    true
                );

                // Append the imported node to the root element
                $root->appendChild($importedNode);

                // Clean up variables to free up memory
                unset(
                    $importedNode,
                    $node
                );
            }
        }

        // Clear libxml errors, including any recorded before this load
        libxml_clear_errors();

        // Clean up variables to free up memory
        unset(
            $html,
            $root,
            $wrappedHtml,
            $tempDoc,
            $div
        );

        // Return the initialized DOMDocument
        return $doc;
    }

    /**
     * Extracts inner HTML from a DOMDocument's root <div>.
     *
     * Retrieves the HTML content of the child nodes within the root <div> element
     * of the provided DOMDocument, ensuring proper UTF-8 encoding. Returns the
     * concatenated HTML string of all child nodes.
     *
     * @param  DOMDocument  $doc  The DOMDocument containing the loaded HTML
     * @return string The inner HTML content of the root <div>
     */
    public static function getInnerHtml(
        DOMDocument $doc
    ): string {
        // Get the root div element from the document
        $div = $doc->getElementsByTagName('div')->item(0);

        // Initialize the inner HTML string
        $innerHTML = '';

        // Check if the div element exists
        if ($div !== null) {
            // Iterate through all child nodes of the div
            foreach ($div->childNodes as $child) {
                // Append the HTML representation of each child node
                $innerHTML .= $doc->saveHTML($child);

                // Clean up variables to free up memory
                unset($child);
            }
        }

        // Clean up variables to free up memory
        unset($div);

        // Return the normalized inner HTML content
        return self::normalizeUtf8($innerHTML);
    }

    /**
     * Collects and initializes all block processors.
     *
     * Returns an array of block processor instances for handling specific
     * WordPress block types (e.g., images, shortcodes, tables).
     *
     * @return array<int, BlockProcessorInterface> Array of block processors
     */
    private static function getBlockProcessors(): array
    {
        return [
            new WpGroupBlockProcessor, // Process groups first to unwrap content
            new WpDetailsBlockProcessor, // Process details blocks to unwrap content
            new WpSpacerBlockProcessor, // Strip spacer blocks
            new ShortcodeBlockProcessor,
            new FileBlockProcessor,
            new EmbedProcessor,
            new QuoteProcessor,
            new TableProcessor,
            new WpImageBlockProcessor,
            new WpPostFeaturedImageBlockProcessor,
            new WpGalleryBlockProcessor,
            new AcfGalleryBlockProcessor,
            new TablePressBlockProcessor,
            new GravityFormsBlockProcessor,
            new FormidableFormsBlockProcessor,
            new NinjaFormsBlockProcessor,
            new WpBlockBlockProcessor,
        ];
    }

    /**
     * Processes text content for Statamic Bard fields.
     *
     * Removes WordPress block comments before trimming to avoid residual whitespace.
     * Strips leading/trailing whitespace and newlines to prevent empty paragraph
     * nodes. When $applyWpAutoP is true, applies WpAutoP early (before shortcode
     * and link processing) so wpautop structure in content:encoded (double line
     * breaks as paragraph boundaries) is recognized and converted to <p> tags.
     * Replaces [caption] shortcodes early to avoid redundant image sets and text
     * nodes. Strips <span> tags with data-ccp-props attributes to prevent
     * erroneous line breaks. Processes wp:image blocks and standalone images using
     * WpImageBlockProcessor for consistent handling. Processes remaining HTML,
     * handling links. Skips processed shortcode content in DOM traversal to avoid
     * duplication. Filters out empty paragraph nodes from final Bard content.
     *
     * @param  string  $html  The input HTML content
     * @param  string  $site  The Statamic site handle
     * @param  AssetImporter  $assetImporter  Instance for importing files
     * @param  OutputInterface  $output  Console output for logging
     * @param  bool  $applyWpAutoP  Apply WpAutoP formatting
     * @param  bool  $isInnerBlocks  Is InnerBlocks content
     * @param  array<int|string, mixed>  $attachments  Array of attachment objects
     * @param  array<int|string, mixed>  $terms  Array of terms for processing
     * @param  ?string  $pageSlug  Page slug for logging
     * @param  ?string  $statamicEntryId  The Statamic entry ID being processed (for deferred processing)
     * @param  ?string  $collectionHandle  The Statamic collection handle (optional)
     * @param  array<int|string, mixed>  $preProcessedNodes  Pre-processed DOM nodes keyed by index
     * @param  ?string  $xmlPath  Optional XML file path for link resolution
     * @return array<int|string, mixed> The Bard-compatible ProseMirror JSON
     */
    public static function processForBard(
        string $html,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        bool $applyWpAutoP = false,
        bool $isInnerBlocks = false,
        array $attachments = [],
        array $terms = [],
        ?string $pageSlug = null,
        ?string $statamicEntryId = null,
        ?string $collectionHandle = null,
        array $preProcessedNodes = [],
        ?string $xmlPath = null
    ): array {
        // Check if processors are already cached to avoid repeated instantiation
        if (! isset(self::$cachedProcessors['bardParser'])) {
            // Initialize the Bard parser for converting HTML to ProseMirror JSON
            self::$cachedProcessors['bardParser'] = new BardParser;

            // Initialize the link processor for handling internal links
            self::$cachedProcessors['linkProcessor'] = new LinkProcessor;

            // Initialize the WordPress auto-paragraph processor
            self::$cachedProcessors['wpAutoP'] = new WpAutoP;

            // Initialize the shortcode processor for handling WordPress shortcodes
            self::$cachedProcessors['shortcodeProcessor'] = new ShortcodeProcessor;

            // Initialize the image processor for handling image imports
            self::$cachedProcessors['imageProcessor'] = new ImageProcessor;
        }

        // Get the cached Bard parser instance
        /** @var BardParser $bardParser */
        $bardParser = self::$cachedProcessors['bardParser'];

        // Get the cached link processor instance
        /** @var LinkProcessor $linkProcessor */
        $linkProcessor = self::$cachedProcessors['linkProcessor'];

        // Get the cached WordPress auto-paragraph processor instance
        /** @var WpAutoP $wpAutoP */
        $wpAutoP = self::$cachedProcessors['wpAutoP'];

        // Get the cached shortcode processor instance
        /** @var ShortcodeProcessor $shortcodeProcessor */
        $shortcodeProcessor = self::$cachedProcessors['shortcodeProcessor'];

        // Get the cached image processor instance
        /** @var ImageProcessor $imageProcessor */
        $imageProcessor = self::$cachedProcessors['imageProcessor'];

        // Check if this is InnerBlocks content that needs special handling
        if ($isInnerBlocks) {
            // Use regex to strip opening/closing block comments (e.g., <!-- wp:image -->)
            $strippedHtml = \preg_replace(
                '/<!--\s*wp:[^\s]+(?:\s*{[^}]*})?\s*-->|<!--\s*\/wp:[^\s]+\s*-->/',
                '',
                $html
            );

            // Preserve original content if block-comment stripping fails
            $html = $strippedHtml ?? $html;
        }

        // Trim whitespace and newlines to avoid empty paragraph nodes
        $html = \trim($html);

        // Check if the HTML content is empty after trimming
        if ($html === '') {
            // Return empty array if no content to process
            return [];
        }

        // Get all block processors
        $blockProcessors = self::getBlockProcessors();

        // Normalize UTF-8 encoding to ensure valid characters
        $processedContent = self::normalizeUtf8($html);

        // Decode HTML entities for proper character rendering
        $processedContent = self::decodeEntities($processedContent);

        // ---
        // Handle content that is only a single wp:shortcode block.
        //
        // When the entire content is just a wp:shortcode block (e.g., a page with only
        // [show-people ...]), process it via the shortcode processor directly and
        // return. This avoids relying on DOM parsing, which can fail to preserve
        // comment/text structure and cause empty content to be imported.
        // ---

        // Initialize inner shortcode text when entire post content is a single shortcode
        $shortcodeOnlyInner = null;

        // ---
        // Initialize optional outputs from shortcode-only handling for end-of-method cleanup
        // ---

        // Initialize array for matching bare form shortcodes
        $bareFormShortcodeMatch = [];

        // Initialize variable for storing processed shortcode HTML
        $shortcodeOnlyHtml = null;

        // Initialize array for matching shortcode blocks
        $shortcodeOnlyMatch = [];

        // Initialize variable for storing parsed Bard content
        $shortcodeOnlyParsed = null;

        // Check if the content matches the expected pattern for a single wp:shortcode block
        if (
            \preg_match(
                '/^\s*<!--\s*wp:shortcode\s*-->\s*(.*?)\s*<!--\s*\/wp:shortcode\s*-->\s*$/s',
                $processedContent,
                $shortcodeOnlyMatch
            ) === 1
        ) {
            // Trim the inner content of the shortcode block. The pattern leaves the
            // whitespace around it outside the group, but not a NUL, which survives
            // normalizeUtf8() in content that is not valid UTF-8.
            $shortcodeOnlyInner = \trim($shortcodeOnlyMatch[1]);
        } elseif ( // Check if the content matches the expected pattern for a bare form shortcode
            \preg_match(
                '/^\s*(\[(?:gravityform|formidable|ninja_form)\s+id\s*=\s*["\']?\d+["\']?[^\]]*\])\s*$/s',
                $processedContent,
                $bareFormShortcodeMatch
            ) === 1
        ) {
            // ---
            // Classic Editor sometimes stores only a bare form shortcode in post_content
            // (no Gutenberg wp:shortcode wrapper). DOM-based Bard parsing drops it unless
            // we resolve it here (mirrors the wp:shortcode-only path above).
            // ---
            $shortcodeOnlyInner = $bareFormShortcodeMatch[1];
        }

        // Check if the post is shortcode-only content that should bypass full DOM traversal
        if (
            $shortcodeOnlyInner !== null
            && $shortcodeOnlyInner !== ''
        ) {
            // Process the shortcode string into Tiptap HTML
            $shortcodeOnlyHtml = $shortcodeProcessor->processShortcodesInContent(
                $shortcodeOnlyInner,
                $site,
                $assetImporter,
                $output,
                $attachments,
                $terms,
                [],
                $pageSlug
            );

            // Check if the processed shortcode HTML differs from the raw shortcode; an
            // empty conversion parses to nothing and falls through below
            if ($shortcodeOnlyHtml !== $shortcodeOnlyInner) {
                // Parse the processed shortcode HTML into Bard content
                $shortcodeOnlyParsed = $bardParser->parse($shortcodeOnlyHtml);

                // Check if the parsed Bard content is not empty and not null
                if (
                    $shortcodeOnlyParsed !== []
                    && $shortcodeOnlyParsed[0] !== null
                ) {
                    // Return the parsed Bard content
                    return $shortcodeOnlyParsed;
                }
            }
        }

        // Remove span tags with data-ccp-props attributes while preserving content
        $processedContent = preg_replace(
            '/<span\s+data-ccp-props="[^"]*"\s*?>(.*?)<\/span>/is',
            '$1',
            $processedContent
        );

        $processedContent ??= $html;

        // ---
        // Extract shortcodes from processed content.
        //
        // To prevent duplicate Bard sets, we must ensure shortcodes inside
        // <!-- wp:shortcode --> ... <!-- /wp:shortcode --> blocks are NOT processed
        // in this early replacement loop. They should only be handled by the
        // ShortcodeBlockProcessor in the block processor loop below.
        //
        // We scan for all such blocks, record their offsets, and skip any shortcodes
        // whose position falls within these blocks.
        // ---

        // Initialize formatted content for further processing
        $formattedContent = $processedContent;

        // ---
        // Apply WpAutoP early for Classic Editor (wpautop) content so paragraph
        // boundaries (double line breaks) are converted to <p> tags before any
        // processing that might alter newlines. Ensures content:encoded from
        // non-Gutenberg WordPress is imported with correct paragraph structure.
        // ---

        // Check if WpAutoP should be applied
        if ($applyWpAutoP) {
            // Convert numeric HTML entities for proper WpAutoP processing
            $formattedContent = preg_replace_callback(
                '/&#(\d+);/',
                fn ($matches): string => \chr((int) $matches[1]),
                $formattedContent
            );

            $formattedContent ??= $processedContent;

            // Decode HTML entities for proper WpAutoP processing
            $formattedContent = html_entity_decode(
                $formattedContent,
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );

            // Apply WpAutoP to the formatted content
            $formattedContent = $wpAutoP->process($formattedContent);
        }

        // Extract shortcode block positions to prevent duplicate processing
        $shortcodeBlocks = self::extractBlockPositions(
            $formattedContent,
            '/<!--\s*wp:shortcode\s*-->(.*?)<!--\s*\/wp:shortcode\s*-->/is'
        );

        // ---
        // Extract gallery blocks from processed content.
        //
        // To prevent LinkProcessor from treating gallery image href attributes as file
        // links, we must ensure href attributes inside
        // <!-- wp:gallery --> ... <!-- /wp:gallery --> blocks are NOT processed by the
        // LinkProcessor. They should only be handled by the WpGalleryBlockProcessor using
        // attachment IDs.
        //
        // We scan for all such blocks, record their offsets, and skip any href attributes
        // whose position falls within these blocks.
        // ---

        // ---
        // Extract gallery block positions to prevent LinkProcessor from processing href
        // attributes
        // ---
        $galleryBlocks = self::extractBlockPositions(
            $formattedContent,
            '/<!--\s*wp:gallery\s*({.*?})\s*-->(.*?)<!--\s*\/wp:gallery\s*-->/is'
        );

        // Extract all shortcodes from the formatted content
        $shortcodes = $shortcodeProcessor->extract(
            $formattedContent,
            $output
        );

        // Track processed images to avoid duplicates
        $processedImages = [];

        // Initialize array to store Bard content nodes
        $bardContent = [];

        // Process shortcodes early to replace with Tiptap HTML or remove
        foreach ($shortcodes as $shortcode) {
            // Skip malformed shortcode payloads that are missing expected keys
            if (
                ! \is_array($shortcode)
                || ! \is_string($shortcode['original'] ?? null)
            ) {
                continue;
            }

            // Find the position of this shortcode in the content
            $shortcodePos = \strpos(
                $formattedContent,
                $shortcode['original']
            );

            // Check if the shortcode is within a wp:shortcode block
            if (
                self::isInsideBlock(
                    $shortcodePos,
                    $shortcodeBlocks
                )
            ) {
                // Skip processing here; ShortcodeBlockProcessor will handle it
                continue;
            }

            // ---
            // `[table]` deliberately has no branch here. It used to get one, keyed on a
            // strict quoted-only regex, that called TablePressShortcodeProcessor::addTableId
            // before falling through to the same toTiptapHtml() call the generic branch
            // below makes — and toTiptapHtml() reaches addTableId() anyway via
            // TablePressShortcodeProcessor::parse(). The branch was therefore identical to
            // the generic one apart from registering the id a second time, under a
            // DIFFERENT page key ($statamicEntryId, where parse() uses the WordPress post
            // id). addTableId() dedupes per page key, so the mismatched keys bypassed it
            // and wrote two things nothing wanted: a $tableIdsByPage[$statamicEntryId]
            // bucket no consumer reads (DeferredProcessingCoordinator looks table ids up by
            // WordPress post id), and a duplicate append to the global getTableIds() cache.
            //
            // Removing it changed no consumer's result — verified per fixture rather than
            // assumed. getTableIdsForPage(<wp post id>), the list ContentProcessor::linkTableEntries
            // is actually handed, was already correct beforehand, and the global cache's
            // three consumers are an array_unique() plus two emptiness checks, so the
            // duplicate was inert rather than harmful. What the deletion buys is one
            // registrar instead of two, and no dead per-entry state (wordpress-importer-archive#308). Leave `[table]`
            // to the generic branch; parse() is the single registrar.
            // ---
            if (($shortcode['name'] ?? null) === 'caption') { // Check if this is a caption shortcode containing images
                // Load shortcode content into DOMDocument for parsing
                // Content that is not a string holds no image, read as empty text;
                // any other text without an image reads the same
                // @pest-mutate-ignore: EmptyStringToNotEmpty
                $shortcodeContent = \is_string($shortcode['content'] ?? null)
                    ? $shortcode['content']
                    : '';
                $doc = self::loadHtml($shortcodeContent);

                // Find all <img> tags in the shortcode content
                $images = $doc->getElementsByTagName('img');

                // Check if an image is found in the caption shortcode
                if ($images->length > 0) {
                    // Extract HTML of the first <img> tag
                    $imgHtml = self::nodeHtml(
                        $doc,
                        $images->item(0)
                    );

                    // Track image to prevent duplicate processing
                    $processedImages[$imgHtml] = $imgHtml;

                    // Convert shortcode to Tiptap-compatible HTML to maintain order
                    $tiptapHtml = $shortcodeProcessor->toTiptapHtml(
                        $shortcode,
                        $site,
                        $assetImporter,
                        $output,
                        $attachments,
                        $terms,
                        $pageSlug
                    );

                    // Replace shortcode with Tiptap HTML in content
                    $formattedContent = \str_replace(
                        $shortcode['original'],
                        $tiptapHtml,
                        $formattedContent
                    );
                }
            } else { // Otherwise
                // Convert shortcode to Tiptap-compatible HTML
                $tiptapHtml = $shortcodeProcessor->toTiptapHtml(
                    $shortcode,
                    $site,
                    $assetImporter,
                    $output,
                    $attachments,
                    $terms,
                    $pageSlug
                );

                // Replace shortcode with Tiptap HTML in content
                $formattedContent = \str_replace(
                    $shortcode['original'],
                    $tiptapHtml,
                    $formattedContent
                );
            }

            // Clean up variables to free up memory ($matches went with the
            // `[table]` branch that used to define it)
            unset(
                $shortcode,
                $shortcodePos,
                $tiptapHtml,
                $doc,
                $images,
                $imgHtml
            );
        }

        // Combine shortcode and gallery blocks as excluded blocks for preprocessing
        $excludedBlocks = \array_merge(
            $shortcodeBlocks,
            $galleryBlocks
        );

        // ---
        // Preprocess content to remove href attributes from excluded blocks before
        // LinkProcessor runs
        // ---
        $formattedContent = self::removeHrefFromExcludedBlocks(
            $formattedContent,
            $excludedBlocks
        );

        // Get XML path from ProcessingContext if not provided
        $xmlPathForLinks = $xmlPath ?? ProcessingContext::getXmlPath();

        // Process links and file references in the content
        $formattedContent = $linkProcessor->process(
            $formattedContent,
            $site,
            $assetImporter,
            $output,
            $statamicEntryId,
            $collectionHandle,
            $xmlPathForLinks
        );

        // Load the processed content into a DOMDocument for parsing
        $doc = self::loadHtml($formattedContent);

        // Initialize array to track processed nodes by HTML content
        $processedNodes = [];

        // Loop through pre-processed nodes to build tracking array
        foreach ($preProcessedNodes as $preProcessedNode) {
            // Skip non-node values from preprocessed node input
            if (! $preProcessedNode instanceof DOMNode) {
                continue;
            }

            // Convert node to HTML string for tracking
            if ($preProcessedNode->ownerDocument instanceof DOMDocument) {
                $processedNodes[] = $preProcessedNode->ownerDocument->saveHTML($preProcessedNode);
            }
        }

        // Initialize string to accumulate content
        $currentContent = '';

        // Initialize array to store block attributes
        $blockAttributes = [];

        // Get all child nodes from the root div element
        $rootDiv = $doc->getElementsByTagName('div')->item(0);

        // loadHtml() always appends the root, so this never returns
        if ($rootDiv === null) {
            // @pest-mutate-ignore: RemoveEarlyReturn
            return [];
        }

        $nodes = $rootDiv->childNodes;

        // Iterate through all nodes in the document
        foreach ($nodes as $node) {
            // Convert current node to HTML string
            $nodeHtml = self::nodeHtml(
                $doc,
                $node
            );

            // Check if node has already been processed
            if (
                \in_array(
                    $nodeHtml,
                    $processedNodes,
                    true
                )
            ) {
                // Skip to the next node if already processed
                continue;
            }

            // Check for block comments
            if ($node instanceof DOMComment) {
                // Read the comment's text once
                $commentText = \trim($node->data);

                // Initialize array to store regex matches
                $matches = [];

                // Check if this is a WordPress block comment
                if (
                    \preg_match(
                        '/^wp:([^\s]+)(?:\s*({.*?}))?\s*.*$/',
                        $commentText,
                        $matches
                    ) === 1
                ) {
                    // Extract the block type from the comment
                    $blockType = $matches[1];

                    // Initialize block attributes array
                    $blockAttributes = [];

                    // Check if block attributes are present in the comment
                    if (isset($matches[2])) {
                        // Extract JSON from the WordPress comment using proper parsing
                        $jsonString = self::extractJsonFromWordPressComment(
                            $commentText,
                            $site,
                            $blockType,
                            $pageSlug
                        );

                        // Decode the JSON attributes
                        $decoded = \json_decode(
                            $jsonString,
                            true
                        );

                        // Check if JSON decoding was successful; a failed decode is not an array
                        if (\is_array($decoded)) {
                            // Store the decoded attributes
                            $blockAttributes = $decoded;
                        } else { // Otherwise
                            // Enhance log context with entry information
                            $logData = LogContextHelper::enhanceWithEntryContext(
                                [
                                    'site' => $site,
                                    'pageSlug' => $pageSlug ?? 'none',
                                    'block_type' => $blockType,
                                    'json_string' => $jsonString,
                                    'json_error' => json_last_error_msg(),
                                ]
                            );

                            // Log JSON parsing error for debugging
                            Log::warning(
                                LogContextHelper::formatMessageWithEntry(
                                    'TextProcessor::processForBard: Failed to parse block attributes JSON',
                                    $logData['page_url']
                                ),
                                $logData['context']
                            );
                        }
                    }

                    // Process block with appropriate processor
                    foreach ($blockProcessors as $processor) {
                        // Check if this processor can handle the current block
                        if (
                            $processor->canProcess(
                                $node,
                                $site,
                                $pageSlug
                            )
                        ) {
                            // Parse the content accumulated ahead of the block first
                            self::flushContent(
                                $currentContent,
                                $bardContent,
                                $bardParser
                            );

                            // Process the block
                            $result = $processor->process(
                                $node,
                                $blockAttributes,
                                $site,
                                $assetImporter,
                                $output,
                                $attachments,
                                $terms,
                                $pageSlug
                            );

                            // Check if the result is an array
                            if (\is_array($result)) {

                                // Read the parts of the result this loop uses
                                $resultBardContent = $result['bardContent'] ?? null;
                                $resultHtml = $result['html'] ?? null;
                                $processedNode = $result['processedNode'] ?? null;
                                $skipCount = $result['skipCount'] ?? null;

                                // Check if the result contains Bard content directly (e.g., from wp:group)
                                if (
                                    \is_array($resultBardContent)
                                    && $resultBardContent !== []
                                ) {
                                    // Merge the Bard content directly into the main Bard content array
                                    $bardContent = \array_merge(
                                        $bardContent,
                                        $resultBardContent
                                    );
                                } elseif (\is_string($resultHtml)) { // Check if the result contains HTML content

                                    // ---
                                    // Parse the HTML and keep every node it holds: a flattened
                                    // accordion in a wp:shortcode block is a heading followed by
                                    // paragraphs. HTML that parses to nothing adds no node.
                                    // ---
                                    foreach ($bardParser->parse($resultHtml) as $parsedContent) {
                                        $bardContent[] = $parsedContent;
                                    }
                                }

                                // Track the processed node's HTML. Every processor that returns
                                // a node returns one of this document (FileBlockProcessor can
                                // return null). Without the check a null node would add an
                                // empty string, which no node's HTML equals.
                                // @pest-mutate-ignore: InstanceOfToTrue
                                if ($processedNode instanceof DOMNode) {
                                    $processedNodes[] = self::nodeHtml(
                                        $doc,
                                        $processedNode
                                    );
                                }

                                // Check if the result specifies nodes to skip
                                if (\is_int($skipCount)) {
                                    // Skip the specified number of nodes
                                    for ($j = 0; $j < $skipCount; $j++) {
                                        // Get the next sibling node
                                        $nextNode = $node->nextSibling;

                                        // Check if the next node exists
                                        if ($nextNode instanceof DOMNode) {
                                            // Mark the next node as processed
                                            $processedNodes[] = $doc->saveHTML($nextNode);

                                            // Move to the next node
                                            $node = $nextNode;
                                        }
                                    }
                                }
                            } elseif (\is_string($result)) { // Check if the result is a string
                                // Parse the HTML string to ProseMirror JSON
                                $parsedResult = $bardParser->parse($result);

                                // Check if parsing produced valid content
                                if ($parsedResult !== []) {
                                    // Add every parsed node to Bard content
                                    foreach ($parsedResult as $parsedNode) {
                                        $bardContent[] = $parsedNode;
                                    }
                                } elseif ( // Check if HTML string is not empty but parsing failed
                                    // A processor returns a string only with content in it, or empty,
                                    // so no whitespace-only string reaches this check
                                    // @pest-mutate-ignore: UnwrapTrim
                                    \trim($result) !== ''
                                ) {
                                    // Enhance log context with entry information
                                    $logData = LogContextHelper::enhanceWithEntryContext(
                                        [
                                            'site' => $site,
                                            'pageSlug' => $pageSlug ?? 'none',
                                            'block_type' => $blockType,
                                            'result_length' => \strlen($result),
                                            'result_preview' => \substr($result, 0, 100),
                                        ]
                                    );

                                    // Log warning when HTML string is not empty but parsing failed
                                    Log::warning(
                                        LogContextHelper::formatMessageWithEntry(
                                            "TextProcessor::processForBard: Block processor returned HTML but BardParser returned empty result for block type '$blockType'",
                                            $logData['page_url']
                                        ),
                                        $logData['context']
                                    );
                                }
                            } elseif ($result === null) { // Check if block processor returns null
                                // Enhance log context with entry information
                                $logData = LogContextHelper::enhanceWithEntryContext(
                                    [
                                        'site' => $site,
                                        'pageSlug' => $pageSlug ?? 'none',
                                        'block_type' => $blockType,
                                    ]
                                );

                                // Log warning when block processor returns null
                                Log::warning(
                                    LogContextHelper::formatMessageWithEntry(
                                        "TextProcessor::processForBard: Block processor returned null for block type '$blockType'",
                                        $logData['page_url']
                                    ),
                                    $logData['context']
                                );
                            }

                            // ---
                            // After processing a wp:shortcode block, if the next sibling is a text node
                            // containing a raw shortcode (e.g., [show-people ...]), mark it as processed so
                            // it is not accumulated into $currentContent. This prevents an erroneous text
                            // node from being added to Bard content.
                            // ---

                            // Check if this is a shortcode block
                            if ($blockType === 'shortcode') {
                                // Get the next sibling node
                                $nextNode = $node->nextSibling;

                                // Check if the next node is a text node
                                if ($nextNode instanceof DOMText) {
                                    // Get the trimmed text content
                                    $text = \trim($nextNode->textContent);

                                    // Check if the text looks like a shortcode
                                    if (
                                        \preg_match(
                                            '/^\[.+\]$/s',
                                            $text
                                        ) === 1
                                    ) {
                                        // Mark the next node as processed
                                        $processedNodes[] = $doc->saveHTML($nextNode);
                                    }
                                }
                            }

                            // The block is handled, so move to the next node
                            continue 2;
                        }
                    }
                }
            }

            // Check if this is a figure element with block attributes. Only an element
            // is named `figure`, so the instanceof check only narrows the type.
            // @pest-mutate-ignore: InstanceOfToTrue
            if (
                $node instanceof DOMElement
                && $node->nodeName === 'figure'
                && $blockAttributes !== []
            ) {
                // Extract the HTML of the first <img> in the <figure>, or '' for none
                $imgHtml = self::nodeHtml(
                    $doc,
                    $node->getElementsByTagName('img')->item(0)
                );

                // Check if image exists and hasn't been processed yet
                if (
                    $imgHtml !== ''
                    && ! isset($processedImages[$imgHtml])
                ) {
                    // Process <figure> with ImageProcessor, passing block attributes
                    $imageSet = $imageProcessor->processImage(
                        $nodeHtml,
                        $site,
                        $assetImporter,
                        $output,
                        $attachments,
                        $pageSlug,
                        $blockAttributes
                    );

                    // Check if the image set was successfully created
                    if ($imageSet !== null && $imageSet !== []) {
                        // Add the image set to Bard content
                        $bardContent[] = $imageSet;

                        // Track processed image to prevent duplicates
                        $processedImages[$imgHtml] = $imgHtml;
                    }
                }

                // Clear block attributes after processing
                $blockAttributes = [];

                // Track processed node
                $processedNodes[] = $nodeHtml;

                // Skip to next node
                continue;
            }

            // Check if this is a figure element containing an image; the instanceof
            // check only narrows the type, as above
            // @pest-mutate-ignore: InstanceOfToTrue
            if (
                $node instanceof DOMElement
                && $node->nodeName === 'figure'
                && $node->getElementsByTagName('img')->length > 0
            ) {
                // Extract the HTML of the first <img> in the figure for tracking
                $imgHtml = self::nodeHtml(
                    $doc,
                    $node->getElementsByTagName('img')->item(0)
                );

                // Check if image hasn't been processed yet
                if (! isset($processedImages[$imgHtml])) {
                    // Parse the content accumulated ahead of the image first
                    self::flushContent(
                        $currentContent,
                        $bardContent,
                        $bardParser
                    );

                    // Process figure with ImageProcessor (no block attributes)
                    $imageSet = $imageProcessor->processImage(
                        $nodeHtml,
                        $site,
                        $assetImporter,
                        $output,
                        $attachments,
                        $pageSlug,
                        []
                    );

                    // Check if the image set was successfully created
                    if ($imageSet !== null && $imageSet !== []) {
                        // Add the image set to Bard content
                        $bardContent[] = $imageSet;

                        // Track processed image
                        $processedImages[$imgHtml] = $imgHtml;
                    }
                }

                // Track processed node
                $processedNodes[] = $nodeHtml;

                // Skip to next node
                continue;
            }

            // Check if this is a standalone img element; only an element is named
            // `img`, so the instanceof check only narrows the type
            // @pest-mutate-ignore: InstanceOfToTrue
            if (
                $node instanceof DOMElement
                && $node->nodeName === 'img'
            ) {
                // Check if image hasn't been processed yet
                if (! isset($processedImages[$nodeHtml])) {
                    // Parse the content accumulated ahead of the image first
                    self::flushContent(
                        $currentContent,
                        $bardContent,
                        $bardParser
                    );

                    // Process image with ImageProcessor (no block attributes)
                    $imageSet = $imageProcessor->processImage(
                        $nodeHtml,
                        $site,
                        $assetImporter,
                        $output,
                        $attachments,
                        $pageSlug,
                        []
                    );

                    // Check if the image set was successfully created
                    if ($imageSet !== null && $imageSet !== []) {
                        // Add the image set to Bard content
                        $bardContent[] = $imageSet;

                        // Track processed image
                        $processedImages[$nodeHtml] = $nodeHtml;
                    }
                }

                // Track processed node
                $processedNodes[] = $nodeHtml;

                // Skip to next node
                continue;
            }

            // Check if this is a closing wp:image comment
            if (
                $node instanceof DOMComment
                && \preg_match(
                    '/^\/wp:image\s*$/',
                    \trim($node->data)
                ) === 1
            ) {
                // Clear block attributes to reset state
                $blockAttributes = [];

                // Track processed node
                $processedNodes[] = $nodeHtml;

                // Skip to next node
                continue;
            }

            // Accumulate non-image, non-block content for later parsing
            $currentContent .= $nodeHtml;
        }

        // Parse any remaining content
        self::flushContent(
            $currentContent,
            $bardContent,
            $bardParser
        );

        // Filter out empty paragraph nodes from final Bard content
        $filteredContent = \array_filter(
            $bardContent,
            fn ($node): bool => ! (
                \is_array($node)
                && ($node['type'] ?? null) === 'paragraph'
                && (($node['content'] ?? []) === [])
            )
        );

        // ---
        // Apply Bard Texstyle attributes to Bard content
        //
        // Extract HTML attributes (class, style, id, start, reversed) from the
        // formatted content and map them to Bard Texstyle attributes
        // (clearboth, id, start, reversed, list_style) for applicable node types.
        // ---
        $mappedContent = BardTexstyleAttributeMapper::mapAttributes(
            \array_values($filteredContent),
            $formattedContent
        );

        // Clean up variables to free up memory
        unset(
            $bardContent,
            $bardParser,
            $blockAttributes,
            $blockProcessors,
            $currentContent,
            $doc,
            $filteredContent,
            $formattedContent,
            $imageProcessor,
            $linkProcessor,
            $nodes,
            $processedContent,
            $processedImages,
            $processedNodes,
            $shortcodeBlocks,
            $shortcodeProcessor,
            $shortcodes,
            $wpAutoP
        );

        // Trigger garbage collection to manage memory usage over a long import; it
        // changes no result
        // @pest-mutate-ignore: RemoveFunctionCall
        gc_collect_cycles();

        // Return mapped content as indexed array
        return $mappedContent;
    }

    /**
     * Extracts JSON attributes from a WordPress block comment.
     *
     * WordPress block comments have the format:
     * <!-- wp:blockType {"json":"attributes"} -->
     *
     * This method properly parses the comment to extract the complete JSON
     * attributes, handling nested objects and arrays correctly. Also fixes common
     * character encoding issues that can cause JSON parsing failures.
     *
     * @param  string  $commentContent  The content of the HTML comment
     * @param  string  $site  The site handle for logging
     * @param  string  $blockType  The block type for logging
     * @param  ?string  $pageSlug  The page slug for logging
     * @return string The complete JSON string, or empty string if not found
     */
    public static function extractJsonFromWordPressComment(
        string $commentContent,
        string $site,
        string $blockType,
        ?string $pageSlug = null
    ): string {
        // Find the first opening brace after the block type
        $bracePos = \strpos(
            $commentContent,
            '{'
        );

        // Check if no opening brace was found in the comment content
        if ($bracePos === false) {
            // Return empty string if no JSON attributes are present
            return '';
        }

        // Extract everything from the first brace to the end
        $jsonString = \substr(
            $commentContent,
            $bracePos
        );

        // Use brace counting to find the complete JSON
        return self::extractCompleteJson(
            $jsonString,
            $site,
            $blockType,
            $pageSlug
        );
    }

    /**
     * Extracts complete JSON from a potentially truncated JSON string using brace
     * counting.
     *
     * WordPress block comments may contain nested JSON objects that get truncated by
     * regex patterns. This method uses brace counting to ensure the complete JSON
     * object is extracted, even with nested structures. Properly handles braces
     * inside string literals and escaped characters to avoid false matches.
     *
     * @param  string  $jsonString  The potentially truncated JSON string starting from the opening brace
     * @param  string  $site  The site handle for logging (unused, kept for backward compatibility)
     * @param  string  $blockType  The block type for logging (unused, kept for backward compatibility)
     * @param  ?string  $pageSlug  The page slug for logging (unused, kept for backward compatibility)
     * @return string The complete JSON string
     */
    public static function extractCompleteJson(
        string $jsonString,
        // Unused, as the docblock says, so its default changes nothing
        // @pest-mutate-ignore: EmptyStringToNotEmpty
        string $site = '',
        // Unused, as the docblock says, so its default changes nothing
        // @pest-mutate-ignore: EmptyStringToNotEmpty
        string $blockType = '',
        ?string $pageSlug = null
    ): string {
        // Initialize counter to track nested brace depth
        $braceCount = 0;

        // Initialize variable to store the end position of the JSON object
        $jsonEnd = 0;

        // Initialize flag to track if we're inside a string literal
        $inString = false;

        // Initialize flag to track if the next character is escaped
        $escapeNext = false;

        // ---
        // Loop through each character in the JSON string to find the matching closing
        // brace
        // ---
        for ($i = 0; $i < \strlen($jsonString); $i++) {
            // Get the current character
            $char = $jsonString[$i];

            // ---
            // Handle string escaping
            // ---

            // Check if the previous character was an escape character
            if ($escapeNext) {
                // Reset escape flag and skip processing this character
                $escapeNext = false;

                // Continue to next character
                continue;
            }

            // Check if current character is an escape character
            if ($char === '\\') {
                // Set flag to escape the next character
                $escapeNext = true;

                // Continue to next character
                continue;
            }

            // ---
            // Track string boundaries
            // ---

            // Check if current character is a quote. Escaped characters are
            // already handled and skipped above, so $escapeNext is false here.
            if ($char === '"') {
                // Toggle string flag to track if we're inside or outside a string
                $inString = ! $inString;

                // Continue to next character
                continue;
            }

            // ---
            // Only count braces outside of strings
            // ---

            // Check if we're not inside a string literal
            if (! $inString) {
                // Check if current character is an opening brace
                if ($char === '{') {
                    // Increment brace counter
                    $braceCount++;
                } elseif ($char === '}') { // Check if current character is a closing brace
                    // Decrement brace counter
                    $braceCount--;

                    // Check if all braces are balanced
                    if ($braceCount === 0) {
                        // Set end position to one character after the closing brace
                        $jsonEnd = $i + 1;

                        // Exit the loop
                        break;
                    }
                }
            }
        }

        // Check if complete JSON was found
        if ($jsonEnd !== 0) {
            // Extract and return the balanced JSON
            return \substr(
                $jsonString,
                0,
                $jsonEnd
            );
        }

        // Clean up variables to free up memory
        unset(
            $braceCount,
            $jsonEnd,
            $inString,
            $escapeNext,
            $i,
            $char
        );

        // Return the original string if no complete JSON found
        return $jsonString;
    }

    /**
     * Checks if content contains WordPress block comments that require CDATA
     * preservation.
     *
     * WordPress block comments can contain special characters that get corrupted
     * when LIBXML_NOCDATA is used. This method detects the presence of such
     * content to determine whether CDATA should be preserved.
     *
     * @param  string  $content  The content to check
     * @return bool True if WordPress block comments are detected, false otherwise
     */
    public static function containsWordPressBlockComments(
        string $content
    ): bool {
        // Check for WordPress block comment patterns
        return
            str_contains(
                $content,
                '<!-- wp:'
            )
            || str_contains(
                $content,
                '<!-- /wp:'
            );
    }

    /**
     * Returns a node's HTML, or an empty string when there is no node.
     *
     * @param  DOMDocument  $doc  The document the node belongs to
     * @param  ?DOMNode  $node  The node, or null for none
     * @return string The node's HTML, or '' for no node
     */
    private static function nodeHtml(
        DOMDocument $doc,
        ?DOMNode $node
    ): string {
        // Read the node's HTML; there is none for no node
        $html = $node instanceof DOMNode ? $doc->saveHTML($node) : false;

        // Return the HTML, or '' for no node. saveHTML() throws for a node from another
        // document rather than returning false, and every caller passes $doc's own.
        return $html === false ? '' : $html;
    }

    /**
     * Parses the content accumulated so far onto the Bard content and clears it.
     *
     * Content that is only whitespace is kept, so it joins whatever comes next.
     *
     * @param  string  &$currentContent  The accumulated HTML, cleared once parsed
     * @param  array<int|string, mixed>  &$bardContent  The Bard content to append to
     * @param  BardParser  $bardParser  The parser for the accumulated HTML
     */
    private static function flushContent(
        string &$currentContent,
        array &$bardContent,
        BardParser $bardParser
    ): void {
        // Keep whitespace-only content to join the next node
        if (\trim($currentContent) === '') {
            return;
        }

        // Append the parsed content and clear the buffer
        $bardContent = \array_merge(
            $bardContent,
            $bardParser->parse($currentContent)
        );
        $currentContent = '';
    }

    /**
     * Checks whether a position falls inside any of the given blocks.
     *
     * @param  int|false  $position  The position, or false when it was not found
     * @param  array<int, array{int, int}>  $blocks  Block start/end offsets
     * @return bool True when the position is at or after a block's start and before its end
     */
    private static function isInsideBlock(
        int|false $position,
        array $blocks
    ): bool {
        // A position that was not found is inside no block
        if ($position === false) {
            return false;
        }

        // Check each block for the position
        foreach ($blocks as [$blockStart, $blockEnd]) {
            // Check whether the position falls inside this block
            if ($position >= $blockStart && $position < $blockEnd) {
                return true;
            }
        }

        // Return false when no block holds the position
        return false;
    }

    /**
     * Extracts block positions from content using a regex pattern.
     *
     * This helper method provides a DRY way to extract block positions for various
     * WordPress block types (e.g., shortcode, gallery) to prevent duplicate
     * processing or unwanted processing by other processors.
     *
     * @param  string  $content  The content to search for blocks
     * @param  string  $pattern  The regex pattern to match blocks
     * @return array<int, array{int, int}> Block start/end offsets
     */
    private static function extractBlockPositions(
        string $content,
        string $pattern
    ): array {
        // Initialize array to store block positions
        $blockPositions = [];

        // Find the blocks matching the pattern; none, or a failed match, leaves no
        // matches to read
        preg_match_all(
            $pattern,
            $content,
            $blockMatches,
            PREG_OFFSET_CAPTURE | PREG_SET_ORDER
        );

        // Process each found block
        foreach ($blockMatches as $blockMatch) {
            // Get the start position of the block
            $blockStart = $blockMatch[0][1];

            // Calculate the end position of the block
            $blockEnd = $blockStart + \strlen($blockMatch[0][0]);

            // Store the block position for later reference
            $blockPositions[] = [
                $blockStart,
                $blockEnd,
            ];

            // Clean up variables to free up memory
            unset(
                $blockMatch,
                $blockStart,
                $blockEnd
            );
        }

        // Return the collected block positions
        return $blockPositions;
    }

    /**
     * Removes href attributes from excluded blocks to prevent LinkProcessor from
     * processing them.
     *
     * This method uses the block positions to remove href attributes from <a> tags
     * within those blocks. This prevents the LinkProcessor from treating links
     * within these blocks as file downloads. These blocks should be handled by
     * their specific processors instead.
     *
     * @param  string  $content  The HTML content to process
     * @param  array<int, array{int, int}>  $excludedBlocks  Block offsets
     * @return string The content with href attributes removed from excluded blocks
     */
    private static function removeHrefFromExcludedBlocks(
        string $content,
        array $excludedBlocks
    ): string {
        // Process blocks in reverse order to maintain correct positions
        $excludedBlocks = \array_reverse($excludedBlocks);

        // Loop through each excluded block to remove href attributes
        foreach ($excludedBlocks as $block) {
            // Extract block start and end positions
            [$blockStart, $blockEnd] = $block;

            // Extract the block content
            $blockContent = \substr(
                $content,
                $blockStart,
                $blockEnd - $blockStart
            );

            // Remove href attributes from <a> tags within the block
            $modifiedBlock = \preg_replace(
                '/<a\s+([^>]*?)href\s*=\s*["\'][^"\']*["\']([^>]*?)>/i',
                '<a $1$2>',
                $blockContent
            );

            $modifiedBlock ??= $blockContent;

            // Replace the original block with the modified version
            $content = substr_replace(
                $content,
                $modifiedBlock,
                $blockStart,
                $blockEnd - $blockStart
            );
        }

        return $content;
    }
}
