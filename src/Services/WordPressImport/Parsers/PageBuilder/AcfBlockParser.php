<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\PageBuilder;

use DOMElement;
use DOMNameSpaceNode;
use DOMNode;
use DOMNodeList;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\ActionBarParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\CallOutParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\ContentParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\CounterListParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\CtaParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\FindADocLocationsParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\FindADocProvidersParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\GalleryParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\LinkListParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\LiveWhaleParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\LogoListParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\NewsParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\OverlayParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\SectionParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\SideBySideParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\StackedParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\AcfFieldNormalizer;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\BlockProcessorHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\ShortcodeProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\WpAutoP;

/**
 * Parses WordPress ACF blocks into Statamic page_builder replicator sets.
 *
 * Handles ACF blocks (action-bar, uams-section, uams-content, cta) in mixed
 * content scenarios where WordPress pages/posts contain both ACF and non-ACF
 * blocks. Delegates parsing to specialized block parsers and supports single-line
 * and multi-line JSON attributes in XML comments. Returns parsed sets for
 * PageBuilderParser to order.
 */
class AcfBlockParser
{
    /**
     * Instance of SectionParser for parsing uams-section blocks.
     */
    private SectionParser $sectionParser;

    /**
     * Instance of ContentParser for parsing uams-content blocks.
     */
    private ContentParser $contentParser;

    /**
     * Instance of ActionBarParser for parsing action-bar blocks.
     */
    private ActionBarParser $actionBarParser;

    /**
     * Instance of CtaParser for parsing cta blocks.
     */
    private CtaParser $ctaParser;

    /**
     * Instance of CallOutParser for parsing call-out blocks.
     */
    private CallOutParser $callOutParser;

    /**
     * Instance of SideBySideParser for parsing image-side blocks.
     */
    private SideBySideParser $sideBySideParser;

    /**
     * Instance of StackedParser for parsing text-stacked blocks.
     */
    private StackedParser $stackedParser;

    /**
     * Instance of OverlayParser for parsing text-overlay blocks.
     */
    private OverlayParser $overlayParser;

    /**
     * Instance of NewsParser for parsing uams-news blocks.
     */
    private NewsParser $newsParser;

    /**
     * Instance of LiveWhaleParser for parsing LiveWhale Calendar blocks.
     */
    private LiveWhaleParser $liveWhaleParser;

    /**
     * Instance of LinkListParser for parsing Link List blocks.
     */
    private LinkListParser $linkListParser;

    /**
     * Instance of CounterListParser for parsing Counter List blocks.
     */
    private CounterListParser $counterListParser;

    /**
     * Instance of GalleryParser for parsing Gallery blocks.
     */
    private GalleryParser $galleryParser;

    /**
     * Instance of LogoListParser for parsing Logo List blocks.
     */
    private LogoListParser $logoListParser;

    /**
     * Instance of FindADocProvidersParser for parsing Find-a-Doc Providers blocks.
     */
    private FindADocProvidersParser $findADocProvidersParser;

    /**
     * Instance of FindADocLocationsParser for parsing Find-a-Doc Locations blocks.
     */
    private FindADocLocationsParser $findADocLocationsParser;

    /**
     * Constructs a new AcfBlockParser instance with its dependencies.
     *
     * Initializes parsers for each supported ACF block type. Optionally accepts
     * custom instances for dependency injection, defaulting to new instances. Also
     * supports LinkProcessor, WpAutoP, and ShortcodeProcessor for ContentParser,
     * SectionParser, CtaParser, CallOutParser, and shortcode blocks.
     *
     * @param  ?SectionParser  $sectionParser  Optional parser for uams-section blocks
     * @param  ?ContentParser  $contentParser  Optional parser for uams-content blocks
     * @param  ?ActionBarParser  $actionBarParser  Optional parser for action-bar blocks
     * @param  ?CtaParser  $ctaParser  Optional parser for cta blocks
     * @param  ?LinkProcessor  $linkProcessor  Optional link processor for file imports
     * @param  ?WpAutoP  $wpAutoP  Optional WpAutoP instance for WYSIWYG formatting
     * @param  ?ShortcodeProcessor  $shortcodeProcessor  Optional shortcode processor instance
     * @param  ?CallOutParser  $callOutParser  Optional parser for call-out blocks
     * @param  ?SideBySideParser  $sideBySideParser  Optional parser for image-side blocks
     * @param  ?StackedParser  $stackedParser  Optional parser for text-stacked blocks
     * @param  ?OverlayParser  $overlayParser  Optional parser for text-overlay blocks
     * @param  ?NewsParser  $newsParser  Optional parser for uams-news blocks
     * @param  ?LiveWhaleParser  $liveWhaleParser  Optional parser for LiveWhale Calendar blocks
     * @param  ?LinkListParser  $linkListParser  Optional parser for Link List blocks
     * @param  ?CounterListParser  $counterListParser  Optional parser for Counter List blocks
     * @param  ?GalleryParser  $galleryParser  Optional parser for uams-gallery blocks
     * @param  ?LogoListParser  $logoListParser  Optional parser for Logo List blocks
     * @param  ?FindADocProvidersParser  $findADocProvidersParser  Optional parser for Find-a-Doc Providers blocks
     * @param  ?FindADocLocationsParser  $findADocLocationsParser  Optional parser for Find-a-Doc Locations blocks
     */
    public function __construct(
        ?SectionParser $sectionParser = null,
        ?ContentParser $contentParser = null,
        ?ActionBarParser $actionBarParser = null,
        ?CtaParser $ctaParser = null,
        ?LinkProcessor $linkProcessor = null,
        ?WpAutoP $wpAutoP = null,
        ?ShortcodeProcessor $shortcodeProcessor = null,
        ?CallOutParser $callOutParser = null,
        ?SideBySideParser $sideBySideParser = null,
        ?StackedParser $stackedParser = null,
        ?OverlayParser $overlayParser = null,
        ?NewsParser $newsParser = null,
        ?LiveWhaleParser $liveWhaleParser = null,
        ?LinkListParser $linkListParser = null,
        ?CounterListParser $counterListParser = null,
        ?GalleryParser $galleryParser = null,
        ?LogoListParser $logoListParser = null,
        ?FindADocProvidersParser $findADocProvidersParser = null,
        ?FindADocLocationsParser $findADocLocationsParser = null
    ) {
        $this->sectionParser = $sectionParser ?? new SectionParser;
        $this->contentParser = $contentParser ?? new ContentParser;
        $this->actionBarParser = $actionBarParser ?? new ActionBarParser;
        $this->ctaParser = $ctaParser ?? new CtaParser(
            null,
            $linkProcessor,
            $wpAutoP,
            $shortcodeProcessor
        );
        $this->callOutParser = $callOutParser ?? new CallOutParser;
        $this->sideBySideParser = $sideBySideParser ?? new SideBySideParser;
        $this->stackedParser = $stackedParser ?? new StackedParser;
        $this->overlayParser = $overlayParser ?? new OverlayParser;
        $this->newsParser = $newsParser ?? new NewsParser;
        $this->liveWhaleParser = $liveWhaleParser ?? new LiveWhaleParser;
        $this->linkListParser = $linkListParser ?? new LinkListParser;
        $this->counterListParser = $counterListParser ?? new CounterListParser;
        $this->galleryParser = $galleryParser ?? new GalleryParser($wpAutoP);
        $this->logoListParser = $logoListParser ?? new LogoListParser;
        $this->findADocProvidersParser = $findADocProvidersParser ?? new FindADocProvidersParser;
        $this->findADocLocationsParser = $findADocLocationsParser ?? new FindADocLocationsParser;
    }

    /**
     * Parses a DOM node into page_builder-compatible ACF block content.
     *
     * Processes XML comment nodes containing ACF block definitions
     * (e.g., wp:acf/uams-content), delegating to the appropriate block parser based
     * on the block type. Also processes div.lwcw (LiveWhale Calendar Widget) element
     * nodes, treating them like wp:acf/livewhale-calendar with the ID taken from the
     * data-options attribute. Returns a result array indicating whether the node was
     * processed, how many nodes to skip, and the parsed replicator set (if any).
     * Supports both self-closing and paired ACF blocks with JSON attributes.
     *
     * @param  DOMNode  $node  The current DOM node to parse (XML comment or element, e.g. div.lwcw)
     * @param  int  $index  The index of the current node in the node list
     * @param  DOMNodeList<DOMNameSpaceNode|DOMNode>  $nodes  The full list of
     *                                                        nodes for context
     * @param  string  $site  The Statamic site handle for asset paths (e.g., 'student_information_system')
     * @param  AssetImporter  $assetImporter  Instance for importing images or linked files
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects from the WordPress XML
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects from the WordPress XML
     * @param  array<int|string, mixed>  $items  Array of SimpleXMLElement item objects from XML for post ID mapping
     * @param  ?string  $pageSlug  The page slug (optional)
     * @return array{processed: bool, skipCount: int, set: ?array<int|string, mixed>} Result with processed status, skip count, and replicator set
     */
    public function parseNode(
        DOMNode $node,
        int $index,
        DOMNodeList $nodes,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = [],
        array $terms = [],
        array $items = [],
        ?string $pageSlug = null
    ): array {
        $processed = false;
        $skipCount = 0;
        $set = null;

        if ($node->nodeType === XML_COMMENT_NODE) {
            $comment = \trim($this->getNodeText($node));
            $normalizedComment = \preg_replace(
                '/\s+/',
                ' ',
                $comment
            );
            $normalizedComment = \is_string($normalizedComment) ? $normalizedComment : '';

            // ---
            // Surface a malformed ACF block-comment JSON failure distinctly. Every
            // block branch below coerces a failed json_decode to an empty attribute
            // array, so a genuinely undecodable block would otherwise vanish without
            // explanation (surfacing only as a misleading downstream "empty body"
            // warning). This reports the loss without altering parsing. See issue wordpress-importer-archive#290.
            // ---
            $this->warnOnUndecodableAcfBlockJson(
                $normalizedComment,
                $pageSlug
            );

            // ---
            // Skip closing tags (they start with /wp:acf/)
            // ---

            // Check if the comment is a closing tag for an ACF block
            if (\preg_match('/^\/wp:acf\//', $normalizedComment) === 1) {
                // ---
                // Return early without processing. Closing tags are handled by the loop that
                // searches for them when processing paired blocks, not as opening tags.
                // ---
                return [
                    'processed' => false,
                    'skipCount' => 0,
                    'set' => null,
                ];
            }

            if (
                \preg_match(
                    '/wp:acf\/action-bar(?:\s+({.+?}))?\s*(?:\/)?$/',
                    $normalizedComment,
                    $matches
                ) === 1
            ) {
                if (
                    \preg_match(
                        '/\/\s*$/',
                        $normalizedComment
                    ) === 1
                ) {
                    $attributes = \json_decode(
                        $matches[1] ?? '',
                        true
                    );

                    // Ensure attributes is always an array (json_decode can return null)
                    $attributes = \is_array($attributes) ? $attributes : [];

                    $set = $this->actionBarParser->parse(
                        $attributes,
                        $site,
                        $assetImporter,
                        $output
                    );
                    $processed = true;
                    $skipCount = 1;
                } else { // Otherwise
                    $attributes = \json_decode(
                        $matches[1] ?? '',
                        true
                    );

                    // Ensure attributes is always an array (json_decode can return null)
                    $attributes = \is_array($attributes) ? $attributes : [];

                    $set = $this->actionBarParser->parse(
                        $attributes,
                        $site,
                        $assetImporter,
                        $output
                    );
                    $processed = true;
                    $skipCount = 1;
                    for ($i = $index + 1; $i < $nodes->length; $i++) {
                        $skipCount++;
                        if (
                            $this->isCommentNode($nodes->item($i))
                            && \preg_match(
                                '/\/wp:acf\/action-bar/',
                                $this->getNodeText($nodes->item($i))
                            ) === 1
                        ) {
                            break;
                        }
                    }
                }
            } elseif (
                \preg_match(
                    '/wp:acf\/uams-section(?:\s+({.+?}))?\s*(?:\/)?$/',
                    $normalizedComment,
                    $matches
                ) === 1
            ) {
                if (
                    \preg_match(
                        '/\/\s*$/',
                        $normalizedComment
                    ) === 1
                ) {
                    $attributes = \json_decode(
                        $matches[1] ?? '',
                        true
                    );

                    // Ensure attributes is always an array (json_decode can return null)
                    $attributes = \is_array($attributes) ? $attributes : [];

                    $set = $this->sectionParser->parse(
                        $attributes,
                        [],
                        $site,
                        $assetImporter,
                        $output,
                        $attachments,
                        $terms,
                        $pageSlug
                    );
                    $processed = true;
                    $skipCount = 1;
                } else { // Otherwise
                    $attributes = \json_decode(
                        $matches[1] ?? '',
                        true
                    );

                    // Ensure attributes is always an array (json_decode can return null)
                    $attributes = \is_array($attributes) ? $attributes : [];

                    $innerContent = $this->parseInnerContent(
                        $nodes,
                        $index,
                        $site,
                        $assetImporter,
                        $output,
                        $attachments,
                        $terms,
                        $pageSlug
                    );
                    $set = $this->sectionParser->parse(
                        $attributes,
                        $innerContent,
                        $site,
                        $assetImporter,
                        $output,
                        $attachments,
                        $terms,
                        $pageSlug
                    );
                    $processed = true;
                    $skipCount = 1;
                    for ($i = $index + 1; $i < $nodes->length; $i++) {
                        $skipCount++;
                        if (
                            $this->isCommentNode($nodes->item($i))
                            && \preg_match(
                                '/\/wp:acf\/uams-section/',
                                $this->getNodeText($nodes->item($i))
                            ) === 1
                        ) {
                            break;
                        }
                    }
                }
            } elseif (
                \preg_match(
                    '/wp:acf\/uams-content(?:\s+({.+?}))?\s*(?:\/)?$/',
                    $normalizedComment,
                    $matches
                ) === 1
            ) {
                if (
                    \preg_match(
                        '/\/\s*$/',
                        $normalizedComment
                    ) === 1
                ) {
                    $attributes = \json_decode(
                        $matches[1] ?? '',
                        true
                    );

                    // Ensure attributes is always an array (json_decode can return null)
                    $attributes = \is_array($attributes) ? $attributes : [];

                    $set = $this->contentParser->parse(
                        $attributes,
                        [],
                        $site,
                        $assetImporter,
                        $output,
                        $attachments,
                        $terms
                    );
                    $processed = true;
                    $skipCount = 1;
                } else { // Otherwise
                    $attributes = \json_decode(
                        $matches[1] ?? '',
                        true
                    );

                    // Ensure attributes is always an array (json_decode can return null)
                    $attributes = \is_array($attributes) ? $attributes : [];

                    $innerContent = $this->parseInnerContent(
                        $nodes,
                        $index,
                        $site,
                        $assetImporter,
                        $output,
                        $attachments,
                        $terms,
                        $pageSlug
                    );
                    $set = $this->contentParser->parse(
                        $attributes,
                        $innerContent,
                        $site,
                        $assetImporter,
                        $output,
                        $attachments,
                        $terms
                    );
                    $processed = true;
                    $skipCount = 1;
                    for ($i = $index + 1; $i < $nodes->length; $i++) {
                        $skipCount++;
                        if (
                            $this->isCommentNode($nodes->item($i))
                            && \preg_match(
                                '/\/wp:acf\/uams-content/',
                                $this->getNodeText($nodes->item($i))
                            ) === 1
                        ) {
                            break;
                        }
                    }
                }
            } elseif (
                \preg_match(
                    '/wp:acf\/cta\s*({.+?})\s*(?:\/)?$/s',
                    $normalizedComment,
                    $matches
                ) === 1
            ) {
                if (
                    \preg_match(
                        '/\/\s*$/',
                        $normalizedComment
                    ) === 1
                ) {
                    $attributes = \json_decode(
                        $matches[1],
                        true,
                        512
                    );
                    $set = $this->ctaParser->parse(
                        \is_array($attributes) ? $attributes : [],
                        $site,
                        $assetImporter,
                        $output,
                        $attachments,
                        $terms
                    );
                    $processed = true;
                    $skipCount = 1;
                } else { // Otherwise
                    $attributes = \json_decode(
                        $matches[1],
                        true,
                        512
                    );
                    $set = $this->ctaParser->parse(
                        \is_array($attributes) ? $attributes : [],
                        $site,
                        $assetImporter,
                        $output,
                        $attachments,
                        $terms
                    );
                    $processed = true;
                    $skipCount = 1;
                    for ($i = $index + 1; $i < $nodes->length; $i++) {
                        $skipCount++;
                        if (
                            $this->isCommentNode($nodes->item($i))
                            && \preg_match(
                                '/\/wp:acf\/cta/',
                                $this->getNodeText($nodes->item($i))
                            ) === 1
                        ) {
                            break;
                        }
                    }
                }
            } elseif (
                \preg_match(
                    '/wp:acf\/call-out\s*({.+?})\s*(?:\/)?$/s',
                    $normalizedComment,
                    $matches
                ) === 1
            ) {
                // Handle wp:acf/call-out block
                if (
                    \preg_match(
                        '/\/\s*$/',
                        $normalizedComment
                    ) === 1
                ) {
                    // Self-closing block
                    $attributes = \json_decode(
                        $matches[1],
                        true,
                        512
                    );
                    $set = $this->callOutParser->parse(
                        \is_array($attributes) ? $attributes : [],
                        $site,
                        $assetImporter,
                        $output,
                        $attachments
                    );
                    $processed = true;
                    $skipCount = 1;
                } else { // Otherwise
                    // Paired block (not typically used for call-out, but supported)
                    $attributes = \json_decode(
                        $matches[1],
                        true,
                        512
                    );
                    $set = $this->callOutParser->parse(
                        \is_array($attributes) ? $attributes : [],
                        $site,
                        $assetImporter,
                        $output,
                        $attachments
                    );
                    $processed = true;
                    $skipCount = 1;
                    for ($i = $index + 1; $i < $nodes->length; $i++) {
                        $skipCount++;
                        if (
                            $this->isCommentNode($nodes->item($i))
                            && \preg_match(
                                '/\/wp:acf\/call-out/',
                                $this->getNodeText($nodes->item($i))
                            ) === 1
                        ) {
                            break;
                        }
                    }
                }
            } elseif (
                \preg_match(
                    '/wp:acf\/image-side\s*({.+?})\s*(?:\/)?$/s',
                    $normalizedComment,
                    $matches
                ) === 1
            ) {
                // Handle wp:acf/image-side block
                if (
                    \preg_match(
                        '/\/\s*$/',
                        $normalizedComment
                    ) === 1
                ) {
                    // Self-closing block
                    $attributes = \json_decode(
                        $matches[1],
                        true,
                        512
                    );
                    $set = $this->sideBySideParser->parse(
                        \is_array($attributes) ? $attributes : [],
                        $site,
                        $assetImporter,
                        $output,
                        $attachments
                    );
                    $processed = true;
                    $skipCount = 1;
                } else { // Otherwise
                    // Paired block (not typically used, but supported)
                    $attributes = \json_decode(
                        $matches[1],
                        true,
                        512
                    );
                    $set = $this->sideBySideParser->parse(
                        \is_array($attributes) ? $attributes : [],
                        $site,
                        $assetImporter,
                        $output,
                        $attachments
                    );
                    $processed = true;
                    $skipCount = 1;
                    for ($i = $index + 1; $i < $nodes->length; $i++) {
                        $skipCount++;
                        if (
                            $this->isCommentNode($nodes->item($i))
                            && \preg_match(
                                '/\/wp:acf\/image-side/',
                                $this->getNodeText($nodes->item($i))
                            ) === 1
                        ) {
                            break;
                        }
                    }
                }
            } elseif (
                \preg_match(
                    '/wp:acf\/text-stacked\s*({.+?})\s*(?:\/)?$/s',
                    $normalizedComment,
                    $matches
                ) === 1
            ) {
                // Handle wp:acf/text-stacked block
                $attributes = \json_decode(
                    $matches[1],
                    true,
                    512
                );

                // Ensure attributes is always an array (json_decode can return null)
                $attributes = \is_array($attributes) ? $attributes : [];

                // ---
                // Check if all items have no images and determine fallback conversion
                // ---

                // Extract data from attributes (handle both 'data' key and direct attributes)
                $data = isset($attributes['data']) && \is_array($attributes['data'])
                    ? $attributes['data']
                    : $attributes;

                // Create temporary StackedParser instance to access field mappings
                $tempStackedParser = new StackedParser;

                // Normalize field names using StackedParser field mappings
                $normalizedData = AcfFieldNormalizer::normalize(
                    $data,
                    $tempStackedParser->getFieldMappings(),
                    $tempStackedParser->getNestedStructures()
                );

                // Check if all items have no images
                $allItemsHaveNoImages = StackedParser::allItemsHaveNoImages(
                    $normalizedData
                );

                // Initialize variable to store if all items have URLs
                $allItemsHaveUrls = false;

                // Check if all items have URLs (only relevant if no images)
                if ($allItemsHaveNoImages) {
                    // Check if all items have URLs
                    $allItemsHaveUrls = StackedParser::allItemsHaveUrls(
                        $normalizedData
                    );
                }

                // ---
                // Determine conversion strategy based on item properties
                // ---

                // Check if should convert to link-list (no images and all have URLs)
                if (
                    $allItemsHaveNoImages
                    && $allItemsHaveUrls
                ) {
                    // ---
                    // Convert to link-list attributes and parse as link-list
                    // ---

                    // Convert stacked block attributes to link-list block attributes
                    $linkListAttributes = StackedParser::convertToLinkListAttributes(
                        $attributes
                    );

                    // Parse converted attributes as link-list block
                    $set = $this->linkListParser->parse(
                        $linkListAttributes,
                        $site,
                        $assetImporter,
                        $output,
                        $attachments
                    );

                    // Check if link-list parsing resulted in empty set (no valid items)
                    if (
                        $set === []
                        || (
                            isset($set['items'])
                            && $set['items'] === []
                        )
                    ) {
                        // ---
                        // Fallback: Convert to uams-section if link-list parsing failed
                        // ---

                        // Convert stacked block to uams-section format
                        $sectionFormat = StackedParser::convertToSectionFormat(
                            $attributes
                        );
                        $sectionAttributes = isset($sectionFormat['attributes'])
                            && \is_array($sectionFormat['attributes'])
                                ? $sectionFormat['attributes']
                                : [];
                        $sectionInnerContent = isset($sectionFormat['innerContent'])
                            && \is_array($sectionFormat['innerContent'])
                                ? $sectionFormat['innerContent']
                                : [];

                        // Parse as uams-section block
                        $set = $this->sectionParser->parse(
                            $sectionAttributes,
                            $sectionInnerContent,
                            $site,
                            $assetImporter,
                            $output,
                            $attachments,
                            $terms,
                            $pageSlug
                        );
                    }

                    // Mark block as processed
                    $processed = true;

                    // Set skip count to 1 for self-closing block
                    $skipCount = 1;

                    // Check if block is paired (not self-closing)
                    if (
                        \preg_match(
                            '/\/\s*$/',
                            $normalizedComment
                        ) !== 1
                    ) {
                        // ---
                        // Paired block - find closing tag
                        // ---

                        // Loop through nodes to find closing comment tag
                        for ($i = $index + 1; $i < $nodes->length; $i++) {
                            // Increment skip count for each node
                            $skipCount++;

                            // Check if current node is the closing comment tag
                            if (
                                $this->isCommentNode($nodes->item($i))
                                && \preg_match(
                                    '/\/wp:acf\/text-stacked/',
                                    $this->getNodeText($nodes->item($i))
                                ) === 1
                            ) {
                                // Found closing tag, exit loop
                                break;
                            }
                        }
                    }
                } elseif ($allItemsHaveNoImages) { // Check if should convert to uams-section (no images and no URLs; URLs already excluded by prior branch)
                    // ---
                    // Convert to uams-section format and parse as uams-section
                    // ---

                    // Convert stacked block to uams-section format
                    $sectionFormat = StackedParser::convertToSectionFormat(
                        $attributes
                    );
                    $sectionAttributes = isset($sectionFormat['attributes'])
                        && \is_array($sectionFormat['attributes'])
                            ? $sectionFormat['attributes']
                            : [];
                    $sectionInnerContent = isset($sectionFormat['innerContent'])
                        && \is_array($sectionFormat['innerContent'])
                            ? $sectionFormat['innerContent']
                            : [];

                    // Parse as uams-section block
                    $set = $this->sectionParser->parse(
                        $sectionAttributes,
                        $sectionInnerContent,
                        $site,
                        $assetImporter,
                        $output,
                        $attachments,
                        $terms,
                        $pageSlug
                    );

                    // Mark block as processed
                    $processed = true;

                    // Set skip count to 1 for self-closing block
                    $skipCount = 1;

                    // Check if block is paired (not self-closing)
                    if (
                        \preg_match(
                            '/\/\s*$/',
                            $normalizedComment
                        ) !== 1
                    ) {
                        // ---
                        // Paired block - find closing tag
                        // ---

                        // Loop through nodes to find closing comment tag
                        for ($i = $index + 1; $i < $nodes->length; $i++) {
                            // Increment skip count for each node
                            $skipCount++;

                            // Check if current node is the closing comment tag
                            if (
                                $this->isCommentNode($nodes->item($i))
                                && \preg_match(
                                    '/\/wp:acf\/text-stacked/',
                                    $this->getNodeText($nodes->item($i))
                                ) === 1
                            ) {
                                // Found closing tag, exit loop
                                break;
                            }
                        }
                    }
                } else { // Otherwise
                    // Parse as normal text-stacked block
                    if (
                        \preg_match(
                            '/\/\s*$/',
                            $normalizedComment
                        ) === 1
                    ) {
                        // ---
                        // Self-closing block
                        // ---

                        // Parse attributes as text-stacked block
                        $set = $this->stackedParser->parse(
                            $attributes,
                            $site,
                            $assetImporter,
                            $output,
                            $attachments
                        );

                        // Check if text-stacked parsing resulted in empty items
                        if (
                            $set === []
                            || (
                                isset($set['items'])
                                && $set['items'] === []
                            )
                        ) {
                            // ---
                            // Fallback: Convert to uams-section if text-stacked parsing failed
                            // ---

                            // Convert stacked block to uams-section format
                            $sectionFormat = StackedParser::convertToSectionFormat(
                                $attributes
                            );
                            $sectionAttributes = isset($sectionFormat['attributes'])
                                && \is_array($sectionFormat['attributes'])
                                    ? $sectionFormat['attributes']
                                    : [];
                            $sectionInnerContent = isset($sectionFormat['innerContent'])
                                && \is_array($sectionFormat['innerContent'])
                                    ? $sectionFormat['innerContent']
                                    : [];

                            // Parse as uams-section block
                            $set = $this->sectionParser->parse(
                                $sectionAttributes,
                                $sectionInnerContent,
                                $site,
                                $assetImporter,
                                $output,
                                $attachments,
                                $terms,
                                $pageSlug
                            );
                        }

                        // Mark block as processed
                        $processed = true;

                        // Set skip count to 1 for self-closing block
                        $skipCount = 1;
                    } else { // Otherwise
                        // ---
                        // Paired block (not typically used, but supported)
                        // ---

                        // Parse attributes as text-stacked block
                        $set = $this->stackedParser->parse(
                            $attributes,
                            $site,
                            $assetImporter,
                            $output,
                            $attachments
                        );

                        // Check if text-stacked parsing resulted in empty items
                        if (
                            $set === []
                            || (
                                isset($set['items'])
                                && $set['items'] === []
                            )
                        ) {
                            // ---
                            // Fallback: Convert to uams-section if text-stacked parsing failed
                            // ---

                            // Convert stacked block to uams-section format
                            $sectionFormat = StackedParser::convertToSectionFormat(
                                $attributes
                            );
                            $sectionAttributes = isset($sectionFormat['attributes'])
                                && \is_array($sectionFormat['attributes'])
                                    ? $sectionFormat['attributes']
                                    : [];
                            $sectionInnerContent = isset($sectionFormat['innerContent'])
                                && \is_array($sectionFormat['innerContent'])
                                    ? $sectionFormat['innerContent']
                                    : [];

                            // Parse as uams-section block
                            $set = $this->sectionParser->parse(
                                $sectionAttributes,
                                $sectionInnerContent,
                                $site,
                                $assetImporter,
                                $output,
                                $attachments,
                                $terms,
                                $pageSlug
                            );
                        }

                        // Mark block as processed
                        $processed = true;

                        // Set skip count to 1 for opening tag
                        $skipCount = 1;

                        // Loop through nodes to find closing comment tag
                        for ($i = $index + 1; $i < $nodes->length; $i++) {
                            // Increment skip count for each node
                            $skipCount++;

                            // Check if current node is the closing comment tag
                            if (
                                $this->isCommentNode($nodes->item($i))
                                && \preg_match(
                                    '/\/wp:acf\/text-stacked/',
                                    $this->getNodeText($nodes->item($i))
                                ) === 1
                            ) {
                                // Found closing tag, exit loop
                                break;
                            }
                        }
                    }
                }
            } elseif (
                \preg_match(
                    '/wp:acf\/text-overlay\s*({.+?})\s*(?:\/)?$/s',
                    $normalizedComment,
                    $matches
                ) === 1
            ) {
                // Handle wp:acf/text-overlay block
                if (
                    \preg_match(
                        '/\/\s*$/',
                        $normalizedComment
                    ) === 1
                ) {
                    // Self-closing block
                    $attributes = \json_decode(
                        $matches[1],
                        true,
                        512
                    );
                    $set = $this->overlayParser->parse(
                        \is_array($attributes) ? $attributes : [],
                        $site,
                        $assetImporter,
                        $output,
                        $attachments
                    );
                    $processed = true;
                    $skipCount = 1;
                } else { // Otherwise
                    // Paired block (not typically used, but supported)
                    $attributes = \json_decode(
                        $matches[1],
                        true,
                        512
                    );
                    $set = $this->overlayParser->parse(
                        \is_array($attributes) ? $attributes : [],
                        $site,
                        $assetImporter,
                        $output,
                        $attachments
                    );
                    $processed = true;
                    $skipCount = 1;
                    for ($i = $index + 1; $i < $nodes->length; $i++) {
                        $skipCount++;
                        if (
                            $this->isCommentNode($nodes->item($i))
                            && \preg_match(
                                '/\/wp:acf\/text-overlay/',
                                $this->getNodeText($nodes->item($i))
                            ) === 1
                        ) {
                            break;
                        }
                    }
                }
            } elseif (
                \preg_match(
                    '/wp:acf\/uams-news\s*({.+?})\s*(?:\/)?$/s',
                    $normalizedComment,
                    $matches
                ) === 1
            ) {
                // Handle wp:acf/uams-news block
                if (
                    \preg_match(
                        '/\/\s*$/',
                        $normalizedComment
                    ) === 1
                ) {
                    // Self-closing block
                    $attributes = \json_decode(
                        $matches[1],
                        true,
                        512
                    );
                    $set = $this->newsParser->parse(
                        \is_array($attributes) ? $attributes : [],
                        $site,
                        $assetImporter,
                        $output,
                        $attachments
                    );
                    $processed = true;
                    $skipCount = 1;
                } else { // Otherwise
                    // Paired block (not typically used, but supported)
                    $attributes = \json_decode(
                        $matches[1],
                        true,
                        512
                    );
                    $set = $this->newsParser->parse(
                        \is_array($attributes) ? $attributes : [],
                        $site,
                        $assetImporter,
                        $output,
                        $attachments
                    );
                    $processed = true;
                    $skipCount = 1;
                    for ($i = $index + 1; $i < $nodes->length; $i++) {
                        $skipCount++;
                        if (
                            $this->isCommentNode($nodes->item($i))
                            && \preg_match(
                                '/\/wp:acf\/uams-news/',
                                $this->getNodeText($nodes->item($i))
                            ) === 1
                        ) {
                            break;
                        }
                    }
                }
            } elseif (
                \preg_match(
                    '/wp:acf\/livewhale-calendar\s*({.+?})\s*(?:\/)?$/s',
                    $normalizedComment,
                    $matches
                ) === 1
            ) {
                // Handle wp:acf/livewhale-calendar block
                if (
                    \preg_match(
                        '/\/\s*$/',
                        $normalizedComment
                    ) === 1
                ) {
                    // Self-closing block
                    $attributes = \json_decode(
                        $matches[1],
                        true,
                        512
                    );
                    $set = $this->liveWhaleParser->parse(
                        \is_array($attributes) ? $attributes : [],
                        $site,
                        $assetImporter,
                        $output,
                        $attachments
                    );
                    $processed = true;
                    $skipCount = 1;
                } else { // Otherwise
                    // Paired block (not typically used, but supported)
                    $attributes = \json_decode(
                        $matches[1],
                        true,
                        512
                    );
                    $set = $this->liveWhaleParser->parse(
                        \is_array($attributes) ? $attributes : [],
                        $site,
                        $assetImporter,
                        $output,
                        $attachments
                    );
                    $processed = true;
                    $skipCount = 1;
                    for ($i = $index + 1; $i < $nodes->length; $i++) {
                        $skipCount++;
                        if (
                            $this->isCommentNode($nodes->item($i))
                            && \preg_match(
                                '/\/wp:acf\/livewhale-calendar/',
                                $this->getNodeText($nodes->item($i))
                            ) === 1
                        ) {
                            break;
                        }
                    }
                }
            } elseif (
                \preg_match(
                    '/wp:acf\/link-list\s*({.+?})\s*(?:\/)?$/s',
                    $normalizedComment,
                    $matches
                ) === 1
            ) {
                // Handle wp:acf/link-list block
                if (
                    \preg_match(
                        '/\/\s*$/',
                        $normalizedComment
                    ) === 1
                ) {
                    // Self-closing block
                    $attributes = \json_decode(
                        $matches[1],
                        true,
                        512
                    );
                    $set = $this->linkListParser->parse(
                        \is_array($attributes) ? $attributes : [],
                        $site,
                        $assetImporter,
                        $output,
                        $attachments
                    );
                    $processed = true;
                    $skipCount = 1;
                } else { // Otherwise
                    // Paired block (not typically used, but supported)
                    $attributes = \json_decode(
                        $matches[1],
                        true,
                        512
                    );
                    $set = $this->linkListParser->parse(
                        \is_array($attributes) ? $attributes : [],
                        $site,
                        $assetImporter,
                        $output,
                        $attachments
                    );
                    $processed = true;
                    $skipCount = 1;
                    for ($i = $index + 1; $i < $nodes->length; $i++) {
                        $skipCount++;
                        if (
                            $this->isCommentNode($nodes->item($i))
                            && \preg_match(
                                '/\/wp:acf\/link-list/',
                                $this->getNodeText($nodes->item($i))
                            ) === 1
                        ) {
                            break;
                        }
                    }
                }
            } elseif (
                \preg_match(
                    '/wp:acf\/counter-list\s*({.+?})\s*(?:\/)?$/s',
                    $normalizedComment,
                    $matches
                ) === 1
            ) {
                // Handle wp:acf/counter-list block
                if (
                    \preg_match(
                        '/\/\s*$/',
                        $normalizedComment
                    ) === 1
                ) {
                    // Self-closing block
                    $attributes = \json_decode(
                        $matches[1],
                        true,
                        512
                    );
                    $set = $this->counterListParser->parse(
                        \is_array($attributes) ? $attributes : [],
                        $site,
                        $assetImporter,
                        $output,
                        $attachments
                    );
                    $processed = true;
                    $skipCount = 1;
                } else { // Otherwise
                    // Paired block (not typically used, but supported)
                    $attributes = \json_decode(
                        $matches[1],
                        true,
                        512
                    );
                    $set = $this->counterListParser->parse(
                        \is_array($attributes) ? $attributes : [],
                        $site,
                        $assetImporter,
                        $output,
                        $attachments
                    );
                    $processed = true;
                    $skipCount = 1;
                    for ($i = $index + 1; $i < $nodes->length; $i++) {
                        $skipCount++;
                        if (
                            $this->isCommentNode($nodes->item($i))
                            && \preg_match(
                                '/\/wp:acf\/counter-list/',
                                $this->getNodeText($nodes->item($i))
                            ) === 1
                        ) {
                            break;
                        }
                    }
                }
            } elseif (
                \preg_match(
                    '/wp:acf\/uams-gallery\s*({.+?})\s*(?:\/)?$/s',
                    $normalizedComment,
                    $matches
                ) === 1
            ) {
                // Handle wp:acf/uams-gallery block
                if (
                    \preg_match(
                        '/\/\s*$/',
                        $normalizedComment
                    ) === 1
                ) {
                    // Self-closing block
                    $attributes = \json_decode(
                        $matches[1],
                        true,
                        512
                    );
                    $sourceUrl = null;
                    if (
                        isset($items[0])
                        && \is_object($items[0])
                        && isset($items[0]->link)
                        && \is_string($items[0]->link)
                    ) {
                        $sourceUrl = $items[0]->link;
                    }

                    $set = $this->galleryParser->parse(
                        \is_array($attributes) ? $attributes : [],
                        $site,
                        $assetImporter,
                        $output,
                        $attachments,
                        $terms,
                        $sourceUrl
                    );
                    $processed = true;
                    $skipCount = 1;
                } else { // Otherwise
                    // Paired block (not typically used, but supported)
                    $attributes = \json_decode(
                        $matches[1],
                        true,
                        512
                    );
                    $sourceUrl = null;
                    if (
                        isset($items[0])
                        && \is_object($items[0])
                        && isset($items[0]->link)
                        && \is_string($items[0]->link)
                    ) {
                        $sourceUrl = $items[0]->link;
                    }

                    $set = $this->galleryParser->parse(
                        \is_array($attributes) ? $attributes : [],
                        $site,
                        $assetImporter,
                        $output,
                        $attachments,
                        $terms,
                        $sourceUrl
                    );
                    $processed = true;
                    $skipCount = 1;
                    for ($i = $index + 1; $i < $nodes->length; $i++) {
                        $skipCount++;
                        if (
                            $this->isCommentNode($nodes->item($i))
                            && \preg_match(
                                '/\/wp:acf\/uams-gallery/',
                                $this->getNodeText($nodes->item($i))
                            ) === 1
                        ) {
                            break;
                        }
                    }
                }
            } elseif (
                \preg_match(
                    '/wp:acf\/logo-list\s*({.+?})\s*(?:\/)?$/s',
                    $normalizedComment,
                    $matches
                ) === 1
            ) {
                // Handle wp:acf/logo-list block
                if (
                    \preg_match(
                        '/\/\s*$/',
                        $normalizedComment
                    ) === 1
                ) {
                    // Self-closing block
                    $attributes = \json_decode(
                        $matches[1],
                        true,
                        512
                    );
                    $set = $this->logoListParser->parse(
                        \is_array($attributes) ? $attributes : [],
                        $site,
                        $assetImporter,
                        $output,
                        $terms,
                        $pageSlug
                    );
                    $processed = true;
                    $skipCount = 1;
                } else { // Otherwise
                    // Paired block (not typically used, but supported)
                    $attributes = \json_decode(
                        $matches[1],
                        true,
                        512
                    );
                    $set = $this->logoListParser->parse(
                        \is_array($attributes) ? $attributes : [],
                        $site,
                        $assetImporter,
                        $output,
                        $terms,
                        $pageSlug
                    );
                    $processed = true;
                    $skipCount = 1;
                    for ($i = $index + 1; $i < $nodes->length; $i++) {
                        $skipCount++;
                        if (
                            $this->isCommentNode($nodes->item($i))
                            && \preg_match(
                                '/\/wp:acf\/logo-list/',
                                $this->getNodeText($nodes->item($i))
                            ) === 1
                        ) {
                            break;
                        }
                    }
                }
            } elseif (
                \preg_match(
                    '/wp:acf\/fad-providers\s*({.+?})\s*(?:\/)?$/s',
                    $normalizedComment,
                    $matches
                ) === 1
            ) {
                // Handle wp:acf/fad-providers block
                if (
                    \preg_match(
                        '/\/\s*$/',
                        $normalizedComment
                    ) === 1
                ) {
                    // Self-closing block
                    $attributes = \json_decode(
                        $matches[1],
                        true,
                        512
                    );
                    $set = $this->findADocProvidersParser->parse(
                        \is_array($attributes) ? $attributes : [],
                        $site,
                        $assetImporter,
                        $output,
                        $terms,
                        $items,
                        $pageSlug
                    );
                    $processed = true;
                    $skipCount = 1;
                } else { // Otherwise
                    // Paired block (not typically used, but supported)
                    $attributes = \json_decode(
                        $matches[1],
                        true,
                        512
                    );
                    $set = $this->findADocProvidersParser->parse(
                        \is_array($attributes) ? $attributes : [],
                        $site,
                        $assetImporter,
                        $output,
                        $terms,
                        $items,
                        $pageSlug
                    );
                    $processed = true;
                    $skipCount = 1;
                    for ($i = $index + 1; $i < $nodes->length; $i++) {
                        $skipCount++;
                        if (
                            $this->isCommentNode($nodes->item($i))
                            && \preg_match(
                                '/\/wp:acf\/fad-providers/',
                                $this->getNodeText($nodes->item($i))
                            ) === 1
                        ) {
                            break;
                        }
                    }
                }
            } elseif (
                \preg_match(
                    '/wp:acf\/fad-locations\s*({.+?})\s*(?:\/)?$/s',
                    $normalizedComment,
                    $matches
                ) === 1
            ) {
                // Handle wp:acf/fad-locations block
                if (
                    \preg_match(
                        '/\/\s*$/',
                        $normalizedComment
                    ) === 1
                ) {
                    // Self-closing block
                    $attributes = \json_decode(
                        $matches[1],
                        true,
                        512
                    );
                    $set = $this->findADocLocationsParser->parse(
                        \is_array($attributes) ? $attributes : [],
                        $site,
                        $assetImporter,
                        $output,
                        $terms,
                        $items,
                        $pageSlug
                    );
                    $processed = true;
                    $skipCount = 1;
                } else { // Otherwise
                    // Paired block (not typically used, but supported)
                    $attributes = \json_decode(
                        $matches[1],
                        true,
                        512
                    );
                    $set = $this->findADocLocationsParser->parse(
                        \is_array($attributes) ? $attributes : [],
                        $site,
                        $assetImporter,
                        $output,
                        $terms,
                        $items,
                        $pageSlug
                    );
                    $processed = true;
                    $skipCount = 1;
                    for ($i = $index + 1; $i < $nodes->length; $i++) {
                        $skipCount++;
                        if (
                            $this->isCommentNode($nodes->item($i))
                            && \preg_match(
                                '/\/wp:acf\/fad-locations/',
                                $this->getNodeText($nodes->item($i))
                            ) === 1
                        ) {
                            break;
                        }
                    }
                }
            } elseif (
                \preg_match(
                    '/\/wp:acf\/(uams-section|action-bar|uams-content|cta|call-out|image-side|text-stacked|text-overlay|uams-news|livewhale-calendar|link-list|counter-list|uams-gallery|logo-list|fad-providers|fad-locations)/',
                    $comment
                ) === 1
            ) {
                // Handle closing tags for all supported ACF blocks
                $processed = true;
            }
        } elseif (
            $node->nodeType === XML_ELEMENT_NODE
            && $node instanceof DOMElement
            && $node->nodeName === 'div'
        ) { // Check if the node is a div element (for div.lwcw LiveWhale widget)
            // ---
            // Handle div.lwcw (LiveWhale Calendar Widget) - treat same as
            // wp:acf/livewhale-calendar, with ID from data-options
            // ---

            // Read the element's class attribute
            $classAttr = $node->getAttribute('class');

            // Read the data-options attribute (e.g., id=34&format=html)
            $dataOptions = $node->getAttribute('data-options');

            // Check if the div has class lwcw and non-empty data-options
            if (
                \preg_match(
                    '/\blwcw\b/',
                    $classAttr
                ) === 1
                && $dataOptions !== ''
            ) {
                // ---
                // Parse id from data-options (e.g., "id=34&amp;format=html" -> 34)
                // ---

                // Decode HTML entities in data-options for parse_str
                $optionsString = \str_replace('&amp;', '&', $dataOptions);

                // Parse data-options into key-value params and extract id
                \parse_str($optionsString, $params);

                // Cast widget id from params to integer; use 0 if missing
                $widgetId = isset($params['id']) ? (int) $params['id'] : 0;

                // Check if a valid widget id was found
                if ($widgetId > 0) {
                    // Build attributes in Gutenberg block shape for LiveWhaleParser
                    $attributes = [
                        'data' => [
                            'livewhale_heading' => 'Placeholder Block Heading — Please Replace',
                            'livewhale_id' => (string) $widgetId,
                            'livewhale_background_color' => 'bg-auto',
                        ],
                    ];

                    // Parse into a LiveWhale replicator set
                    $set = $this->liveWhaleParser->parse(
                        $attributes,
                        $site,
                        $assetImporter,
                        $output,
                        $attachments
                    );

                    // If parsing succeeded, mark processed and skip div + script
                    if ($set !== []) {
                        // Mark this node as processed
                        $processed = true;

                        // Skip the div and the following script node
                        $skipCount = 2;
                    }
                }
            }
        }

        // Return result with processed flag, skip count, and replicator set
        return [
            'processed' => $processed,
            'skipCount' => $skipCount,
            'set' => $set,
        ];
    }

    /**
     * Returns node text content as a safe string.
     *
     * @param  DOMNameSpaceNode|DOMNode|null  $node  Node whose text should be read
     * @return string The node text, or empty string when missing
     */
    private function getNodeText(
        DOMNameSpaceNode|DOMNode|null $node
    ): string {
        if (! $node instanceof DOMNode) {
            return '';
        }

        return \is_string($node->nodeValue) ? $node->nodeValue : '';
    }

    /**
     * Determines whether a node is an XML comment node.
     *
     * @param  DOMNameSpaceNode|DOMNode|null  $node  Candidate node
     * @return bool True when node is an XML comment
     */
    private function isCommentNode(
        DOMNameSpaceNode|DOMNode|null $node
    ): bool {
        return $node instanceof DOMNode && $node->nodeType === XML_COMMENT_NODE;
    }

    /**
     * Logs a distinct warning when an ACF block comment carries attribute JSON that
     * fails to decode.
     *
     * Each block branch in {@see self::parseNode()} coerces a failed `json_decode`
     * to an empty attribute array, so a malformed block would otherwise vanish
     * silently and surface only as a misleading downstream "empty body" warning
     * (issue wordpress-importer-archive#290). This validates the JSON once up front and, on failure, logs the
     * block type and decode error so the loss is traceable. It never alters parsing
     * — it only reports — and applies to every ACF block type parsed from comment
     * JSON.
     *
     * @param  string  $normalizedComment  The whitespace-collapsed comment text
     * @param  ?string  $pageSlug  The page slug for log context (optional)
     */
    private function warnOnUndecodableAcfBlockJson(
        string $normalizedComment,
        ?string $pageSlug
    ): void {
        // Only opening ACF blocks that actually carry a JSON payload can fail to decode.
        if (
            \preg_match(
                '/^wp:acf\/([^\s{]+)\s+({.+})\s*\/?\s*$/s',
                $normalizedComment,
                $matches
            ) !== 1
        ) {
            // No JSON payload (closing tag, attribute-less block, or non-ACF comment).
            return;
        }

        // Extract the block type and raw JSON payload from the comment.
        $blockType = $matches[1];
        $json = $matches[2];

        // Decode purely to detect failure; the real parsing happens per-block above.
        \json_decode(
            $json,
            true,
            512
        );

        // Nothing to report when the JSON is well-formed.
        if (\json_last_error() === JSON_ERROR_NONE) {
            // JSON decoded cleanly; the block will parse normally.
            return;
        }

        // Capture the decode error message for the log.
        $jsonError = \json_last_error_msg();

        // Enhance log context with entry information.
        $logData = LogContextHelper::enhanceWithEntryContext(
            [
                'block_type' => "wp:acf/{$blockType}",
                'page_slug' => $pageSlug,
                'json_error' => $jsonError,
                'json_snippet' => \substr($json, 0, 200).(\strlen($json) > 200 ? '...' : ''),
            ]
        );

        // Log a distinct, diagnosable warning so the dropped block is not silent.
        Log::warning(
            LogContextHelper::formatMessageWithEntry(
                "AcfBlockParser: failed to json_decode wp:acf/{$blockType} block comment ({$jsonError}); block attributes are empty",
                $logData['page_url']
            ),
            $logData['context']
        );
    }

    /**
     * Parses ACF blocks from HTML string content.
     *
     * Extracts and parses ACF block comments from HTML strings (e.g., from
     * unwrapped wp:group blocks). Handles both self-closing and paired ACF blocks.
     * Returns an array of parsed ACF sets and the remaining non-ACF HTML content.
     *
     * @param  string  $html  The HTML string containing ACF block comments
     * @param  string  $site  The Statamic site handle
     * @param  AssetImporter  $assetImporter  Instance for importing assets
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects
     * @param  array<int|string, mixed>  $items  Array of SimpleXMLElement item objects
     * @param  ?string  $pageSlug  The page slug (optional)
     * @return array<int|string, mixed> Array with 'acfSets' (array of parsed ACF sets) and 'remainingHtml' (string of non-ACF HTML content)
     */
    public function parseAcfBlocksFromHtml(
        string $html,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = [],
        array $terms = [],
        array $items = [],
        ?string $pageSlug = null
    ): array {
        // ---
        // Leave libxml's internal-errors mode as the caller had it (wordpress-importer-archive#1354). The
        // mode is process-wide, and both the block load below and the Bard parser
        // the block parsers drive (ueberdosis/tiptap-php's DOMParser) switch it on.
        // ---
        $previousInternalErrors = \libxml_use_internal_errors();

        try {
            return $this->parseAcfBlocksFromHtmlContent(
                $html,
                $site,
                $assetImporter,
                $output,
                $attachments,
                $terms,
                $items,
                $pageSlug
            );
        } finally {
            // Put back the mode the caller had
            \libxml_use_internal_errors($previousInternalErrors);
        }
    }

    /**
     * Parses ACF blocks from HTML string content, for parseAcfBlocksFromHtml().
     *
     * parseAcfBlocksFromHtml() wraps this to restore libxml's internal-errors
     * mode afterwards.
     *
     * @param  string  $html  The HTML string containing ACF block comments
     * @param  string  $site  The Statamic site handle
     * @param  AssetImporter  $assetImporter  Instance for importing assets
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects
     * @param  array<int|string, mixed>  $items  Array of SimpleXMLElement item objects
     * @param  ?string  $pageSlug  The page slug
     * @return array<int|string, mixed> Array with 'acfSets' and 'remainingHtml'
     */
    private function parseAcfBlocksFromHtmlContent(
        string $html,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments,
        array $terms,
        array $items,
        ?string $pageSlug
    ): array {
        // Initialize array to store parsed ACF sets
        $acfSets = [];

        // ---
        // Extract all ACF block opening comments from the HTML string
        // Pattern matches: <!-- wp:acf/block-name {attributes} --> or <!-- wp:acf/block-name {attributes} /-->
        // ---

        // Define regex pattern to match ACF block comments
        $acfBlockPattern = '/<!--\s*(wp:acf\/(?:uams-section|action-bar|uams-content|cta|call-out|image-side|text-stacked|text-overlay|uams-news|livewhale-calendar|link-list|counter-list|uams-gallery|logo-list|fad-providers|fad-locations)(?:\s+({.+?}))?\s*(?:\/)?)\s*-->/s';

        // Initialize array to store regex matches
        $matches = [];

        // Find all ACF block comments in HTML string
        \preg_match_all(
            $acfBlockPattern,
            $html,
            $matches,
            PREG_OFFSET_CAPTURE
        );

        // Check if any ACF blocks were found
        if ($matches[0] === []) {
            // Return empty ACF sets and original HTML
            return [
                'acfSets' => [],
                'remainingHtml' => $html,
            ];
        }

        // ---
        // Process each ACF block found in the HTML
        // ---

        // Track position in HTML string for extracting content between blocks
        $lastPosition = 0;

        // Initialize string to accumulate remaining non-ACF HTML content
        $processedHtml = '';

        // Loop through each matched ACF block comment
        foreach ($matches[0] as $idx => $match) {
            // Get position of match in HTML string
            $matchPosition = $match[1];

            // Get full matched comment string
            $fullComment = $match[0];

            // Extract block comment portion (without surrounding comment markers)
            $blockComment = $matches[1][$idx][0];

            // Check if this is a self-closing block
            $isSelfClosing = \preg_match(
                '/\/\s*-->/',
                $fullComment
            );

            // ---
            // Add HTML before this ACF block to remaining HTML
            // ---

            // Extract HTML content between last processed position and current block
            $beforeBlock = \substr(
                $html,
                $lastPosition,
                $matchPosition - $lastPosition
            );

            // Append HTML before block to processed HTML string
            $processedHtml .= $beforeBlock;

            // ---
            // For paired blocks, find the closing comment
            // ---

            // Initialize variable for block inner content
            $blockContent = '';

            // Calculate end position of opening comment in HTML string
            $endPosition = $matchPosition + \strlen($fullComment);

            // Initialize block type variable
            $blockType = null;

            // Check if block is paired (not self-closing)
            if ($isSelfClosing === false) {
                // ---
                // Extract block type from comment
                // ---

                // Extract block type name from comment string
                if (
                    \preg_match(
                        '/wp:acf\/([^\s{]+)/',
                        $blockComment,
                        $blockTypeMatch
                    ) === 1
                ) {
                    // Store extracted block type name
                    $blockType = $blockTypeMatch[1];

                    // Build regex pattern to find closing comment for this block type
                    $closingPattern = '/<!--\s*\/wp:acf\/'.\preg_quote(
                        $blockType,
                        '/'
                    ).'\s*-->/s';

                    // ---
                    // Find the closing comment
                    // ---

                    // Search for closing comment after opening comment position
                    if (
                        \preg_match(
                            $closingPattern,
                            $html,
                            $closingMatch,
                            PREG_OFFSET_CAPTURE,
                            $endPosition
                        ) === 1
                    ) {
                        // Get position of closing comment in HTML string
                        $closingPosition = $closingMatch[0][1];

                        // Get full closing comment string
                        $closingComment = $closingMatch[0][0];

                        // Extract content between opening and closing comments
                        $blockContent = \substr(
                            $html,
                            $endPosition,
                            $closingPosition - $endPosition
                        );

                        // Update end position to after closing comment
                        $endPosition = $closingPosition + \strlen($closingComment);
                    }
                }
            }

            // ---
            // Parse the ACF block by creating a temporary DOM with the full block
            // (opening comment, content, and closing comment if paired)
            // ---

            // Create temporary DOM document for parsing this block
            $tempDoc = new \DOMDocument(
                '1.0',
                'UTF-8'
            );

            // Start with opening comment as base HTML
            $blockHtml = $fullComment;

            // For paired blocks, append content and closing comment
            if ($isSelfClosing === false) {
                // Append block content and closing comment to HTML
                $blockHtml .= "{$blockContent}<!-- /wp:acf/{$blockType} -->";
            }

            // Wrap block HTML in full document structure for DOM parsing
            $tempHtml = "<!DOCTYPE html><html><head><meta charset=\"UTF-8\"></head><body>{$blockHtml}</body></html>";

            // ---
            // Load HTML into DOM document with flags to preserve structure. No
            // LIBXML_PARSEHUGE (wordpress-importer-archive#1273): this parses one ACF block, not a whole post,
            // and no ACF block in the exports corpus nests past libxml's default
            // limits, so the defaults keep a hostile export from exhausting memory.
            // ---
            // ---
            // Collect libxml warnings internally while loading, then restore the
            // caller's mode (wordpress-importer-archive#1354). The mode is process-wide, so leaving it on
            // would silence every later libxml warning in the import, including
            // the invalid-expression warning XpathHelper logs.
            // ---
            $previousInternalErrors = \libxml_use_internal_errors(true);

            try {
                $tempDoc->loadHTML(
                    $tempHtml,
                    LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
                );
            } finally {
                // Clear any libxml errors that occurred during parsing
                \libxml_clear_errors();

                // Put back the mode the body had. parseAcfBlocksFromHtml() restores
                // the caller's mode in its own finally, so no test can observe this
                // call except from inside the body; it keeps warnings visible while
                // the block parsers run.
                // @pest-mutate-ignore: RemoveFunctionCall
                \libxml_use_internal_errors($previousInternalErrors);
            }

            // ---
            // Find the opening comment node in the temporary DOM
            // ---

            // Create XPath instance for querying DOM nodes
            $xpath = new \DOMXPath($tempDoc);

            // Query all comment nodes in the temporary DOM
            $commentNodes = $xpath->query('//comment()');

            // Query all nodes under body element
            $tempNodes = $xpath->query('//body/node()');

            // Check if opening comment node was found in temporary DOM
            if (
                $commentNodes !== false
                && $commentNodes->length > 0
                && $tempNodes !== false
            ) {
                // Get the first comment node (opening ACF block comment)
                $commentNode = $commentNodes->item(0);

                if (! $commentNode instanceof DOMNode) {
                    continue;
                }

                // Parse the ACF block using parseNode
                $acfResult = $this->parseNode(
                    $commentNode,
                    0,
                    $tempNodes,
                    $site,
                    $assetImporter,
                    $output,
                    $attachments,
                    $terms,
                    $items,
                    $pageSlug
                );

                // Check if ACF block was successfully parsed
                if (
                    $acfResult['processed']
                    && isset($acfResult['set'])

                ) {
                    // Add parsed ACF set to results
                    $acfSets[] = $acfResult['set'];
                }
            }

            // Update last position to after this block (including closing comment if paired)
            $lastPosition = $endPosition;
        }

        // Add remaining HTML after the last ACF block
        $processedHtml .= \substr(
            $html,
            $lastPosition
        );

        // Return parsed ACF sets and remaining HTML
        return [
            'acfSets' => $acfSets,
            'remainingHtml' => $processedHtml,
        ];
    }

    /**
     * Collects inner content of an ACF block until its closing comment.
     *
     * Gathers HTML fragments between an ACF block's opening and closing XML comments,
     * returning them as an array for inclusion in the replicator set. Processes
     * WordPress block comments (e.g., wp:embed, wp:image) using block processors
     * before collecting HTML fragments. Used for non-self-closing ACF blocks to
     * capture nested content.
     *
     * @param  DOMNodeList<DOMNameSpaceNode|DOMNode>  $nodes  The full list of
     *                                                        DOM nodes
     * @param  int  $startIndex  The index of the opening comment node
     * @param  string  $site  The Statamic site handle for context
     * @param  AssetImporter  $assetImporter  Instance for importing linked files
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects
     * @param  ?string  $pageSlug  The page slug (optional)
     * @return array<int|string, mixed> Array of HTML fragments (e.g., ['html' => '...'])
     */
    private function parseInnerContent(
        DOMNodeList $nodes,
        int $startIndex,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = [],
        array $terms = [],
        ?string $pageSlug = null
    ): array {
        $innerContent = [];
        $i = $startIndex + 1;

        while ($i < $nodes->length) {
            $node = $nodes->item($i);
            if (! $node instanceof DOMNode) {
                $i++;

                continue;
            }

            if (
                $node->nodeType === XML_COMMENT_NODE
                && \preg_match(
                    '/\/wp:acf\/(uams-section|action-bar|uams-content|cta)/',
                    \trim($this->getNodeText($node))
                ) === 1
            ) {
                break;
            }

            // Check if this is a WordPress block comment node
            if ($node->nodeType === XML_COMMENT_NODE) {
                // Process node using helper with common processors
                $result = BlockProcessorHelper::processBlockNodeWithCommonProcessors(
                    $node,
                    $site,
                    $assetImporter,
                    $output,
                    $attachments,
                    $terms,
                    $pageSlug
                );

                // Check if block was successfully processed by a processor
                if ($result !== null && $result !== []) {
                    $skipCount = isset($result['skipCount']) && \is_int($result['skipCount'])
                        ? $result['skipCount']
                        : 0;

                    // Add processed HTML to inner content array
                    $innerContent[] = [
                        'html' => $result['html'],
                        'processedNode' => $result['processedNode'] ?? null,
                    ];

                    // Advance index by skip count to skip processed nodes
                    $i += $skipCount;
                } else { // Otherwise
                    // Convert unprocessed comment node to HTML fragment
                    $htmlFragment = BlockProcessorHelper::convertNodeToHtmlFragment($node);

                    // Check if HTML conversion succeeded
                    if ($htmlFragment !== null) {
                        // Add raw HTML to inner content array
                        $innerContent[] = $htmlFragment;
                    }

                    // Advance index by one for unprocessed comment
                    $i++;
                }
            } else { // Otherwise
                // Convert non-comment node to HTML fragment
                $htmlFragment = BlockProcessorHelper::convertNodeToHtmlFragment($node);

                // Check if HTML conversion succeeded
                if ($htmlFragment !== null) {
                    // Add HTML to inner content array
                    $innerContent[] = $htmlFragment;
                }

                // Advance index by one for this node
                $i++;
            }
        }

        return $innerContent;
    }
}
