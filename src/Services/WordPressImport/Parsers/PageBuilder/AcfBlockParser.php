<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\PageBuilder;

use DOMComment;
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
 * Handles the ACF blocks listed in {@see self::BLOCK_TYPES}, and LiveWhale
 * Calendar widget divs, in mixed content scenarios where WordPress pages/posts
 * contain both ACF and non-ACF blocks. Delegates parsing to specialized block
 * parsers and supports single-line
 * and multi-line JSON attributes in XML comments. Returns parsed sets for
 * PageBuilderParser to order.
 */
class AcfBlockParser
{
    /**
     * The supported ACF block types, in the order a comment is matched against
     * them, each mapped to whether its block comment may omit the attribute JSON.
     */
    private const array BLOCK_TYPES = [
        'action-bar' => true,
        'uams-section' => true,
        'uams-content' => true,
        'cta' => false,
        'call-out' => false,
        'image-side' => false,
        'text-stacked' => false,
        'text-overlay' => false,
        'uams-news' => false,
        'livewhale-calendar' => false,
        'link-list' => false,
        'counter-list' => false,
        'uams-gallery' => false,
        'logo-list' => false,
        'fad-providers' => false,
        'fad-locations' => false,
    ];

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
        // Parse an ACF block comment
        if ($node->nodeType === XML_COMMENT_NODE) {
            return $this->parseCommentNode(
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
        }

        // Parse a LiveWhale Calendar Widget div
        if (
            $node instanceof DOMElement
            && $node->nodeName === 'div'
        ) {
            return $this->parseLiveWhaleWidget(
                $node,
                $site,
                $assetImporter,
                $output,
                $attachments
            );
        }

        // Leave any other node to the caller
        return self::unprocessed();
    }

    /**
     * Parses an XML comment node that may open an ACF block.
     *
     * Matches the comment against each supported block type in turn, decodes the
     * block's attribute JSON, and hands the block to its parser. A self-closing
     * block spans its own node; a paired block runs through its closing comment.
     * A comment that only carries a supported block's closing tag after other text
     * is marked processed with nothing to skip.
     *
     * @param  DOMNode  $node  The comment node
     * @param  int  $index  The index of the comment in the node list
     * @param  DOMNodeList<DOMNameSpaceNode|DOMNode>  $nodes  The full list of nodes
     * @param  string  $site  The Statamic site handle
     * @param  AssetImporter  $assetImporter  Instance for importing images or linked files
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects
     * @param  array<int|string, mixed>  $items  Array of SimpleXMLElement item objects
     * @param  ?string  $pageSlug  The page slug
     * @return array{processed: bool, skipCount: int, set: ?array<int|string, mixed>} Result with processed status, skip count, and replicator set
     */
    private function parseCommentNode(
        DOMNode $node,
        int $index,
        DOMNodeList $nodes,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments,
        array $terms,
        array $items,
        ?string $pageSlug
    ): array {
        // Collapse the comment's whitespace so a block written across lines reads as one.
        // preg_replace() returns null only on a PCRE error, which this pattern cannot
        // raise on a string subject, so the default is never used.
        // @pest-mutate-ignore: EmptyStringToNotEmpty
        $comment = \preg_replace('/\s+/', ' ', \trim($this->getNodeText($node))) ?? '';

        // ---
        // Surface a malformed ACF block-comment JSON failure distinctly. A failed
        // json_decode leaves the block with empty attributes, so a genuinely
        // undecodable block would otherwise vanish without explanation (surfacing
        // only as a misleading downstream "empty body" warning). This reports the
        // loss without altering parsing. See issue wordpress-importer-archive#290.
        // ---
        $this->warnOnUndecodableAcfBlockJson(
            $comment,
            $pageSlug
        );

        // Leave a closing comment to the count of the paired block it closes
        if (\preg_match('/^\/wp:acf\//', $comment) === 1) {
            return self::unprocessed();
        }

        foreach (self::BLOCK_TYPES as $type => $attributesOptional) {
            // Match the opening comment, whose attribute JSON only some blocks may omit
            $pattern = $attributesOptional
                ? '/wp:acf\/'.$type.'(?:\s+({.+?}))?\s*(?:\/)?$/'
                : '/wp:acf\/'.$type.'\s*({.+?})\s*(?:\/)?$/';

            if (\preg_match($pattern, $comment, $matches) !== 1) {
                continue;
            }

            // Decode the attributes, treating JSON that is not an object as none
            $attributes = isset($matches[1]) ? \json_decode($matches[1], true) : null;
            $attributes = \is_array($attributes) ? $attributes : [];

            // A comment ending in a slash closes its own block
            $selfClosing = \preg_match('/\/\s*$/', $comment) === 1;

            return [
                'processed' => true,
                'skipCount' => $selfClosing ? 1 : $this->countThroughClosingComment($nodes, $index, $type),
                'set' => $this->parseBlock(
                    $type,
                    $attributes,
                    $selfClosing,
                    $index,
                    $nodes,
                    $site,
                    $assetImporter,
                    $output,
                    $attachments,
                    $terms,
                    $items,
                    $pageSlug
                ),
            ];
        }

        // Mark a supported block's closing tag that follows other text as processed
        if (
            \preg_match(
                '/\/wp:acf\/('.\implode('|', \array_keys(self::BLOCK_TYPES)).')/',
                $comment
            ) === 1
        ) {
            return [
                'processed' => true,
                'skipCount' => 0,
                'set' => null,
            ];
        }

        // Leave a comment that names no supported block to the caller
        return self::unprocessed();
    }

    /**
     * Hands an ACF block's attributes to the parser for its block type.
     *
     * @param  key-of<self::BLOCK_TYPES>  $type  The block type
     * @param  array<int|string, mixed>  $attributes  The decoded block attributes
     * @param  bool  $selfClosing  Whether the block closes itself, and so has no inner content
     * @param  int  $index  The index of the opening comment in the node list
     * @param  DOMNodeList<DOMNameSpaceNode|DOMNode>  $nodes  The full list of nodes
     * @param  string  $site  The Statamic site handle
     * @param  AssetImporter  $assetImporter  Instance for importing images or linked files
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects
     * @param  array<int|string, mixed>  $items  Array of SimpleXMLElement item objects
     * @param  ?string  $pageSlug  The page slug
     * @return ?array<int|string, mixed> The replicator set the block parser returns
     */
    private function parseBlock(
        string $type,
        array $attributes,
        bool $selfClosing,
        int $index,
        DOMNodeList $nodes,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments,
        array $terms,
        array $items,
        ?string $pageSlug
    ): ?array {
        // Collect a paired section or content block's inner content only when it is needed
        $innerContent = fn (): array => $selfClosing
            ? []
            : $this->parseInnerContent(
                $nodes,
                $index,
                $site,
                $assetImporter,
                $output,
                $attachments,
                $terms,
                $pageSlug
            );

        return match ($type) {
            'action-bar' => $this->actionBarParser->parse($attributes, $site, $assetImporter, $output),
            'uams-section' => $this->sectionParser->parse($attributes, $innerContent(), $site, $assetImporter, $output, $attachments, $terms, $pageSlug),
            'uams-content' => $this->contentParser->parse($attributes, $innerContent(), $site, $assetImporter, $output, $attachments, $terms),
            'cta' => $this->ctaParser->parse($attributes, $site, $assetImporter, $output, $attachments, $terms),
            'call-out' => $this->callOutParser->parse($attributes, $site, $assetImporter, $output, $attachments),
            'image-side' => $this->sideBySideParser->parse($attributes, $site, $assetImporter, $output, $attachments),
            'text-stacked' => $this->parseTextStacked($attributes, $site, $assetImporter, $output, $attachments, $terms, $pageSlug),
            'text-overlay' => $this->overlayParser->parse($attributes, $site, $assetImporter, $output, $attachments),
            'uams-news' => $this->newsParser->parse($attributes, $site, $assetImporter, $output, $attachments),
            'livewhale-calendar' => $this->liveWhaleParser->parse($attributes, $site, $assetImporter, $output, $attachments),
            'link-list' => $this->linkListParser->parse($attributes, $site, $assetImporter, $output, $attachments),
            'counter-list' => $this->counterListParser->parse($attributes, $site, $assetImporter, $output, $attachments),
            'uams-gallery' => $this->galleryParser->parse($attributes, $site, $assetImporter, $output, $attachments, $terms, $this->firstItemLink($items)),
            'logo-list' => $this->logoListParser->parse($attributes, $site, $assetImporter, $output, $terms, $pageSlug),
            'fad-providers' => $this->findADocProvidersParser->parse($attributes, $site, $assetImporter, $output, $terms, $items, $pageSlug),
            'fad-locations' => $this->findADocLocationsParser->parse($attributes, $site, $assetImporter, $output, $terms, $items, $pageSlug),
        };
    }

    /**
     * Counts the nodes a paired ACF block spans: from its opening comment through
     * the first later comment that carries its closing tag, or to the last node
     * when there is none.
     *
     * @param  DOMNodeList<DOMNameSpaceNode|DOMNode>  $nodes  The full list of nodes
     * @param  int  $index  The index of the opening comment
     * @param  string  $type  The block type
     * @return int The number of nodes the block spans
     */
    private function countThroughClosingComment(
        DOMNodeList $nodes,
        int $index,
        string $type
    ): int {
        // item() returns null one past the end, which is not a comment, so reading
        // that far finds nothing more.
        // @pest-mutate-ignore: SmallerToSmallerOrEqual
        for ($i = $index + 1; $i < $nodes->length; $i++) {
            $candidate = $nodes->item($i);

            // Stop at the comment carrying this block's closing tag
            if (
                $candidate instanceof DOMComment
                && \preg_match('/\/wp:acf\/'.$type.'/', $this->getNodeText($candidate)) === 1
            ) {
                return $i - $index + 1;
            }
        }

        // Run to the last node when the closing comment is missing
        return $nodes->length - $index;
    }

    /**
     * Parses a text-stacked block, as a link list or a section when its items
     * suit one better.
     *
     * A block whose items carry no images becomes a link list when every item has
     * a URL, and a section otherwise. A Stacked or link-list parse that yields no
     * items falls back to a section.
     *
     * @param  array<int|string, mixed>  $attributes  The decoded block attributes
     * @param  string  $site  The Statamic site handle
     * @param  AssetImporter  $assetImporter  Instance for importing images or linked files
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects
     * @param  ?string  $pageSlug  The page slug
     * @return ?array<int|string, mixed> The replicator set
     */
    private function parseTextStacked(
        array $attributes,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments,
        array $terms,
        ?string $pageSlug
    ): ?array {
        // Read the items from the data array, or from the attributes when there is none
        $data = isset($attributes['data']) && \is_array($attributes['data'])
            ? $attributes['data']
            : $attributes;

        // Normalize field names using StackedParser field mappings
        $stackedParser = new StackedParser;
        $normalizedData = AcfFieldNormalizer::normalize(
            $data,
            $stackedParser->getFieldMappings(),
            $stackedParser->getNestedStructures()
        );

        // Choose the parser the items suit
        if (! StackedParser::allItemsHaveNoImages($normalizedData)) {
            $set = $this->stackedParser->parse(
                $attributes,
                $site,
                $assetImporter,
                $output,
                $attachments
            );
        } elseif (StackedParser::allItemsHaveUrls($normalizedData)) {
            $set = $this->linkListParser->parse(
                StackedParser::convertToLinkListAttributes($attributes),
                $site,
                $assetImporter,
                $output,
                $attachments
            );
        } else {
            return $this->parseStackedAsSection(
                $attributes,
                $site,
                $assetImporter,
                $output,
                $attachments,
                $terms,
                $pageSlug
            );
        }

        // Fall back to a section when the parse yields no items
        if (
            $set === []
            || ($set['items'] ?? null) === []
        ) {
            return $this->parseStackedAsSection(
                $attributes,
                $site,
                $assetImporter,
                $output,
                $attachments,
                $terms,
                $pageSlug
            );
        }

        return $set;
    }

    /**
     * Parses a text-stacked block as a uams-section block.
     *
     * @param  array<int|string, mixed>  $attributes  The decoded block attributes
     * @param  string  $site  The Statamic site handle
     * @param  AssetImporter  $assetImporter  Instance for importing images or linked files
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects
     * @param  ?string  $pageSlug  The page slug
     * @return ?array<int|string, mixed> The replicator set
     */
    private function parseStackedAsSection(
        array $attributes,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments,
        array $terms,
        ?string $pageSlug
    ): ?array {
        // Convert the stacked block to uams-section attributes and inner content
        $section = StackedParser::convertToSectionFormat($attributes);

        return $this->sectionParser->parse(
            $section['attributes'],
            $section['innerContent'],
            $site,
            $assetImporter,
            $output,
            $attachments,
            $terms,
            $pageSlug
        );
    }

    /**
     * Returns the link of the first WordPress item, the page a gallery came from.
     *
     * @param  array<int|string, mixed>  $items  Array of SimpleXMLElement item objects
     * @return ?string The link, or null when the first item carries no string link
     */
    private function firstItemLink(array $items): ?string
    {
        // Read the link of the first item, when it is an object
        $firstItem = $items[0] ?? null;
        $link = \is_object($firstItem) ? ($firstItem->link ?? null) : null;

        return \is_string($link) ? $link : null;
    }

    /**
     * Parses a div.lwcw LiveWhale Calendar Widget as a LiveWhale block.
     *
     * Treats the widget like wp:acf/livewhale-calendar, with the calendar ID read
     * from its data-options attribute (e.g., "id=34&amp;format=html"). The widget
     * and the script node after it are skipped when the LiveWhale parser returns a
     * set.
     *
     * @param  DOMElement  $node  The div element
     * @param  string  $site  The Statamic site handle
     * @param  AssetImporter  $assetImporter  Instance for importing images or linked files
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects
     * @return array{processed: bool, skipCount: int, set: ?array<int|string, mixed>} Result with processed status, skip count, and replicator set
     */
    private function parseLiveWhaleWidget(
        DOMElement $node,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments
    ): array {
        // Leave a div that is not a LiveWhale widget to the caller
        if (\preg_match('/\blwcw\b/', $node->getAttribute('class')) !== 1) {
            return self::unprocessed();
        }

        // Read the widget id from data-options, whose ampersands may be encoded
        \parse_str(
            \str_replace('&amp;', '&', $node->getAttribute('data-options')),
            $params
        );
        $widgetId = (int) ($params['id'] ?? null);

        // Leave a widget without a usable id to the caller
        if ($widgetId <= 0) {
            return self::unprocessed();
        }

        // Parse the widget into a LiveWhale replicator set
        $set = $this->liveWhaleParser->parse(
            [
                'data' => [
                    'livewhale_heading' => "Placeholder Block Heading \u{2014} Please Replace",
                    'livewhale_id' => (string) $widgetId,
                    'livewhale_background_color' => 'bg-auto',
                ],
            ],
            $site,
            $assetImporter,
            $output,
            $attachments
        );

        // Leave the widget unprocessed when the parser returns no set
        if ($set === []) {
            return [
                'processed' => false,
                'skipCount' => 0,
                'set' => [],
            ];
        }

        // Skip the div and the script node after it
        return [
            'processed' => true,
            'skipCount' => 2,
            'set' => $set,
        ];
    }

    /**
     * The result for a node this parser leaves to its caller.
     *
     * @return array{processed: false, skipCount: 0, set: null} The unprocessed result
     */
    private static function unprocessed(): array
    {
        return [
            'processed' => false,
            'skipCount' => 0,
            'set' => null,
        ];
    }

    /**
     * Returns node text content as a safe string.
     *
     * @param  DOMNode  $node  Node whose text should be read
     * @return string The node text, or empty string when it has none
     */
    private function getNodeText(
        DOMNode $node
    ): string {
        // Every caller passes a comment node, whose nodeValue is always a string, so
        // the fallback only narrows the type.
        // @pest-mutate-ignore: EmptyStringToNotEmpty
        return \is_string($node->nodeValue) ? $node->nodeValue : '';
    }

    /**
     * Logs a distinct warning when an ACF block comment carries attribute JSON that
     * fails to decode.
     *
     * Each block in {@see self::parseCommentNode()} coerces a failed `json_decode`
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
        \json_decode($json);

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
     * unwrapped wp:group blocks). Each block is parsed from its opening comment
     * alone, so a paired block's inner content and closing comment stay in the
     * remaining HTML (tracked in wordpress-importer#201). Returns an array of
     * parsed ACF sets and the remaining non-ACF HTML content.
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
     * mode afterwards. Each block is parsed from its opening comment alone, so a
     * paired block's inner content and closing comment stay in the remaining HTML
     * (tracked in wordpress-importer#201).
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
        // Find every opening comment of a supported block, self-closing or not
        \preg_match_all(
            '/<!--\s*wp:acf\/(?:'.\implode('|', \array_keys(self::BLOCK_TYPES)).')(?:\s+{.+?})?\s*\/?\s*-->/s',
            $html,
            $matches,
            PREG_OFFSET_CAPTURE
        );

        $acfSets = [];
        $remainingHtml = '';
        $lastPosition = 0;

        foreach ($matches[0] as [$comment, $position]) {
            // Keep the HTML between the previous block and this one
            $remainingHtml .= \substr($html, $lastPosition, $position - $lastPosition);
            $lastPosition = $position + \strlen($comment);

            // Parse the block, keeping any set its parser returns
            $set = $this->parseBlockComment(
                $comment,
                $site,
                $assetImporter,
                $output,
                $attachments,
                $terms,
                $items,
                $pageSlug
            );

            if ($set !== null) {
                $acfSets[] = $set;
            }
        }

        // Keep the HTML after the last block
        return [
            'acfSets' => $acfSets,
            'remainingHtml' => $remainingHtml.\substr($html, $lastPosition),
        ];
    }

    /**
     * Parses one ACF block comment taken from an HTML string.
     *
     * Loads the comment into a temporary DOM so it can be parsed like a node of a
     * whole post.
     *
     * @param  string  $comment  The block comment, with its comment markers
     * @param  string  $site  The Statamic site handle
     * @param  AssetImporter  $assetImporter  Instance for importing assets
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects
     * @param  array<int|string, mixed>  $items  Array of SimpleXMLElement item objects
     * @param  ?string  $pageSlug  The page slug
     * @return ?array<int|string, mixed> The replicator set, or null when the block yields none
     */
    private function parseBlockComment(
        string $comment,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments,
        array $terms,
        array $items,
        ?string $pageSlug
    ): ?array {
        // Create temporary DOM document for parsing this block
        $document = new \DOMDocument(
            '1.0',
            'UTF-8'
        );

        // ---
        // Load the comment with flags to preserve structure. No LIBXML_PARSEHUGE
        // (wordpress-importer-archive#1273): this parses one ACF block comment, not a
        // whole post, so the defaults keep a hostile export from exhausting memory.
        //
        // Collect libxml warnings internally while loading, then restore the
        // caller's mode (wordpress-importer-archive#1354). The mode is process-wide, so leaving it on
        // would silence every later libxml warning in the import, including
        // the invalid-expression warning XpathHelper logs.
        // ---
        $previousInternalErrors = \libxml_use_internal_errors(true);

        try {
            // The markup names its own doctype, html and body, so neither flag
            // changes the document it gives.
            // @pest-mutate-ignore: BitwiseOrToBitwiseAnd
            $document->loadHTML(
                "<!DOCTYPE html><html><head><meta charset=\"UTF-8\"></head><body>{$comment}</body></html>",
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

        $body = $document->getElementsByTagName('body')->item(0);

        // The markup above always gives the document a body whose first child is the
        // matched comment, so this guard only narrows the types.
        // @pest-mutate-ignore: InstanceOfToTrue, RemoveNullSafeOperator
        if (! $body?->firstChild instanceof DOMNode) {
            return null;
        }

        // Parse the block as the first node of the temporary body
        $result = $this->parseNode(
            $body->firstChild,
            0,
            $body->childNodes,
            $site,
            $assetImporter,
            $output,
            $attachments,
            $terms,
            $items,
            $pageSlug
        );

        return $result['processed'] ? $result['set'] : null;
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

        // item() returns null one past the end, which the guard below skips, so
        // reading that far adds nothing.
        // @pest-mutate-ignore: SmallerToSmallerOrEqual
        while ($i < $nodes->length) {
            $node = $nodes->item($i);

            // The loop bound keeps item() in range, so this guard only narrows the type
            // @pest-mutate-ignore: InstanceOfToTrue
            if (! $node instanceof DOMNode) {
                $i++;

                continue;
            }

            if (
                $node->nodeType === XML_COMMENT_NODE
                && \preg_match(
                    '/\/wp:acf\/(uams-section|action-bar|uams-content|cta)/',
                    $this->getNodeText($node)
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
                    $skipCount = \is_int($result['skipCount'] ?? null)
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
