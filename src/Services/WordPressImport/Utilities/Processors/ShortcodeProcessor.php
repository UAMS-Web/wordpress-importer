<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors;

use Illuminate\Support\Facades\Log;
use Statamic\Fieldtypes\RowId;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Tiptap\SetMarkupNonce;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;

/**
 * Processes WordPress shortcodes into data for Statamic Bard sets or page builder
 * blocks.
 *
 * Extracts shortcode name and attributes from raw text, preparing data for Tiptap
 * parsing (Bard sets) or page builder replicator sets. Handles malformed
 * shortcodes by logging errors and skipping. Supports extensible handlers for
 * specific shortcodes and integrates with term tracking for validation. Tracks
 * unhandled shortcodes as unaddressed nodes, except the recognized-but-dropped
 * ones, which emit nothing.
 */
class ShortcodeProcessor
{
    /**
     * Registered shortcode handlers mapped to set types.
     *
     * @var array<string, array{set: string, class: string}>
     */
    private array $handlers = [
        'show-people' => ['set' => 'people', 'class' => PeopleShortcodeProcessor::class],
        'show-team' => ['set' => 'people', 'class' => PeopleShortcodeProcessor::class],
        'gravityform' => ['set' => 'form', 'class' => GravityFormsShortcodeProcessor::class],
        'formidable' => ['set' => 'form', 'class' => FormidableFormsShortcodeProcessor::class],
        'ninja_form' => ['set' => 'form', 'class' => NinjaFormsShortcodeProcessor::class],
        'table' => ['set' => 'table', 'class' => TablePressShortcodeProcessor::class],
        'caption' => ['set' => 'image', 'class' => CaptionShortcodeProcessor::class],
        'audio' => ['set' => 'audio', 'class' => AudioShortcodeProcessor::class],
        'accordion' => ['set' => 'accordion', 'class' => AccordionShortcodeProcessor::class],
        'gallery' => ['set' => 'gallery', 'class' => GalleryShortcodeProcessor::class],
        'blogroll' => ['set' => 'news', 'class' => BlogrollShortcodeProcessor::class],
    ];

    /**
     * Shortcodes that are recognized but intentionally produce no output.
     *
     * "Recognize-and-drop": these are stripped from imported content rather than
     * converted to a Bard set or page builder block. Registering a name here stops
     * extract() from skipping it — skipping leaks the literal [shortcode] text into
     * the imported body and logs a spurious "No shortcodes extracted" warning — and
     * stops it being reported as an unaddressed shortcode.
     *
     * - subpage-list: a legacy WordPress shortcode that rendered a list of the
     *   current page's child pages (used throughout the NICU Guidebook page
     *   hierarchy as navigation; always `[subpage-list link=""]`, no per-instance
     *   config). The Statamic page template already renders subpage navigation from
     *   the page hierarchy on its own — see the host app's
     *   resources/views/layout/navigation/subpages/_subpages.antlers.html, included
     *   by pages/default and pages/page_landing — so emitting a child-page block
     *   here would duplicate it. Dropped. (See issue wordpress-importer-archive#317.)
     *
     * @var string[]
     */
    private array $droppedShortcodes = [
        'subpage-list',
    ];

    /**
     * Tracks unaddressed shortcodes for reporting.
     *
     * @var array<string, array<array{id: string, slug: ?string, link: string, original: string}>>
     */
    protected $unaddressedNodes = [];

    /**
     * Page ID for unaddressed node tracking.
     *
     * @var string
     */
    protected $pageId = '';

    /**
     * Page link for unaddressed node tracking.
     *
     * @var string
     */
    protected $pageLink = '';

    /**
     * Extracts shortcode data from text.
     *
     * Parses shortcode syntax (e.g., [show-people attr="value"]) into a
     * standardized array.
     *
     * @param  string  $content  The raw text containing shortcodes
     * @param  OutputInterface  $output  Console output for logging errors
     * @return array<int|string, mixed> Registered and dropped shortcodes only (e.g., [['name' => 'show-people', 'attributes' => [...], 'content' => '', 'original' => '[...]']])
     */
    public function extract(
        string $content,
        OutputInterface $output
    ): array {
        return $this->extractShortcodes($content);
    }

    /**
     * Extracts shortcode data from text, typed for this class's own callers.
     *
     * @param  string  $content  The raw text containing shortcodes
     * @return list<array{name: string, attributes: array<string, string|true>, content: string, original: string}> Registered and dropped shortcodes only
     */
    private function extractShortcodes(
        string $content
    ): array {
        $shortcodes = [];

        // Match shortcodes with optional content. The name stops at whitespace, "]"
        // and "/", none of which WordPress's add_shortcode() accepts in a name, so a
        // shortcode written with no attributes ends at its own bracket instead of
        // running on to the next one in the text
        $shortcodePattern = '/\[([^\s\]\/]+)\s*([^\]]*?)\](?:((?:[^\[]|\[(?!\/\1\]))*?)\[\/\1\])?/s';
        preg_match_all(
            $shortcodePattern,
            $content,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $name = $match[1];
            // Skip names that are neither registered handlers nor recognized drops.
            // Recognized-but-dropped shortcodes (e.g. subpage-list) must still be
            // extracted so callers can strip them; skipping here would leak the raw
            // [shortcode] text and trigger a spurious "No shortcodes extracted" warning.
            if (! isset($this->handlers[$name]) && ! $this->isDroppedShortcode($name)) {
                continue;
            }

            // The attribute pattern skips surrounding whitespace on its own
            $attrString = $match[2];
            $original = $match[0];
            $innerContent = $match[3] ?? '';

            $attributes = [];
            $attrPattern = '/(\w+)\s*=\s*(?:(["\'])(.*?)(?<!\\\\)\2|([^\s"\']+))|(\w+)/s';
            preg_match_all(
                $attrPattern,
                $attrString,
                $attrMatches,
                PREG_SET_ORDER
            );

            foreach ($attrMatches as $attr) {
                if (isset($attr[5])) {
                    $key = $attr[5];
                    $value = true;
                } else {
                    // Without group 5 the named alternative matched, and it always
                    // captures a non-empty \w+ key in group 1, so the default never applies.
                    // @pest-mutate-ignore: EmptyStringToNotEmpty
                    $key = $attr[1] ?? '';

                    // The value alternation is either quoted (groups 2/3) or unquoted
                    // (group 4); the two are mutually exclusive, so the presence of
                    // group 4 cleanly distinguishes an unquoted value from a quoted one.
                    if (isset($attr[4])) {
                        // ---
                        // Unquoted values absorb a glued-on self-closing slash when the
                        // shortcode is written without a space before it (e.g. [table id=2/]),
                        // because [^\s"']+ stops only at whitespace. Strip trailing slashes so
                        // the value matches its intended form, and surface the source defect as
                        // an import warning so it stays visible instead of being silently repaired.
                        // ---
                        $rawValue = $attr[4];
                        $value = \rtrim($rawValue, '/');

                        // Record a warning only when a slash was actually stripped.
                        if ($value !== $rawValue) {
                            $this->recordMalformedShortcodeWarning(
                                $name,
                                $key,
                                $rawValue,
                                $value,
                                $original
                            );
                        }
                    } else {
                        // Quoted value: left intact, including any legitimate trailing slash.
                        // Group 3 always takes part in a quoted match, even when empty,
                        // so the default never applies.
                        // @pest-mutate-ignore: EmptyStringToNotEmpty
                        $value = $attr[3] ?? '';
                    }
                }

                $attributes[$key] = $value;
            }

            $shortcodes[] = [
                'name' => $name,
                'attributes' => $attributes,
                'content' => $innerContent,
                'original' => $original,
            ];
        }

        return $shortcodes;
    }

    /**
     * Converts shortcode data to HTML for Tiptap setContent.
     *
     * Delegates to the registered handler's toTiptapHtml(). A shortcode with no
     * handler is logged and kept as an 'unknown' set carrying its content.
     *
     * @param  array<int|string, mixed>  $shortcode  Shortcode data (['name' => '...', 'attributes' => [...]])
     * @param  string  $site  Site handle for context
     * @param  AssetImporter  $assetImporter  Instance for importing linked files
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $attachments  Array of attachment data from XML
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects from XML
     * @param  ?string  $pageSlug  Page slug for term tracking
     * @return string HTML representation of the shortcode
     */
    public function toTiptapHtml(
        array $shortcode,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = [],
        array $terms = [],
        ?string $pageSlug = null
    ): string {
        $shortcodeName = self::extractScalarString($shortcode['name'] ?? null);

        // Recognized-but-dropped shortcodes (e.g. subpage-list) emit nothing: the
        // page template renders their output on its own, so return an empty string
        // and let callers strip the original text. No warning, since this is expected.
        if ($this->isDroppedShortcode($shortcodeName)) {
            return '';
        }

        // Without a handler, warn and keep the content in an unknown set
        if (! isset($this->handlers[$shortcodeName])) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'handler' => null,
                    'site' => $site,
                    'pageSlug' => $pageSlug,
                ]
            );

            // Log warning for unhandled shortcode types
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    "ShortcodeProcessor::toTiptapHtml: No valid handler for shortcode '{$shortcodeName}'",
                    $logData['page_url']
                ),
                $logData['context']
            );

            return self::generateTiptapHtml(
                [],
                'unknown',
                self::extractScalarString($shortcode['content'] ?? null)
            );
        }

        // A registered class that is not a shortcode processor renders nothing
        $handler = $this->instantiateHandler($shortcodeName);
        if (! $handler instanceof ShortcodeProcessorInterface) {
            return '';
        }

        // Delegate to the handler's own Tiptap rendering
        return $handler->toTiptapHtml(
            $shortcode,
            $site,
            $assetImporter,
            $output,
            $attachments,
            $terms,
            $pageSlug
        );
    }

    /**
     * Generates Tiptap-compatible HTML from shortcode values.
     *
     * Shared utility method for converting shortcode data into Tiptap HTML.
     * Handles attribute building and HTML structure generation consistently
     * across all shortcode processors.
     *
     * @param  array<int|string, mixed>  $values  The shortcode values to convert
     * @param  string  $setType  The set type (e.g., 'image', 'people')
     * @param  string  $content  The content to include in the HTML (empty string to exclude)
     * @return string Tiptap-compatible HTML
     */
    public static function generateTiptapHtml(
        array $values,
        string $setType,
        string $content = ''
    ): string {
        // Initialize attributes string for HTML generation
        $attrs = '';

        // Loop through all values to build HTML attributes
        foreach ($values as $key => $value) {
            // Check if this key should be skipped or value is null
            if (
                $key === 'type'
                || $value === null
            ) {
                continue;
            }

            // ---
            // Handle array values by base64-encoding JSON to avoid HTML/DOM truncation
            // of long or quote-heavy attribute values (e.g., display_detail).
            // ---

            // Check if the value is an array
            if (\is_array($value)) {
                // Encode the value as JSON
                $jsonValue = \json_encode($value);

                if ($jsonValue === false) {
                    continue;
                }

                // Encode the JSON value as base64
                $encodedValue = \base64_encode($jsonValue);

                $attributeKey = \is_string($key) ? $key : (string) $key;

                // Create HTML attribute with single quotes to avoid quote conflicts
                $attrString = \sprintf(
                    " data-set-attr-%s='%s'",
                    htmlspecialchars($attributeKey),
                    // Base64 output holds no character htmlspecialchars() changes.
                    // @pest-mutate-ignore: UnwrapHtmlspecialchars
                    htmlspecialchars(
                        $encodedValue,
                        ENT_QUOTES,
                        'UTF-8'
                    )
                );

                // Append the attribute string to the attributes collection
                $attrs .= $attrString;
            } else { // Otherwise
                // Create HTML attribute for scalar values
                $attrString = \sprintf(
                    ' data-set-attr-%s="%s"',
                    htmlspecialchars(\is_string($key) ? $key : (string) $key),
                    htmlspecialchars(
                        \is_bool($value)
                            ? ($value ? 'true' : 'false')
                            : (\is_scalar($value) ? (string) $value : '')
                    )
                );

                // Append the attribute string to the attributes collection
                $attrs .= $attrString;
            }
        }

        // Generate the HTML structure
        $html = \sprintf(
            '<div data-set-type="%s"%s%s>%s</div>',
            htmlspecialchars($setType),
            SetMarkupNonce::attribute(),
            $attrs,
            htmlspecialchars($content)
        );

        // Return the generated Tiptap-compatible HTML
        return $html;
    }

    /**
     * Processes shortcode data into a Bard set or page builder block.
     *
     * Delegates to registered handlers (e.g., PeopleShortcodeProcessor) or handles
     * directly for simple cases. For page builder blocks, includes fields like
     * order_by, derived from the WordPress shortcode's orderby attribute.
     *
     * @param  array<int|string, mixed>  $shortcode  Shortcode data (['name' => '...', 'attributes' => [...]])
     * @param  string  $site  The site handle for asset paths
     * @param  AssetImporter  $assetImporter  Instance for importing files
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects from XML
     * @param  string  $outputType  'bard' for Bard set, 'page_builder' for replicator set
     * @param  array<int|string, mixed>  $attachments  Array of attachment objects for image processing
     * @param  ?string  $pageSlug  Optional page slug for term tracking
     * @return array<int|string, mixed>|null The parsed Bard set or page builder block, or null if unhandled
     */
    public function process(
        array $shortcode,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $terms = [],
        string $outputType = 'bard',
        array $attachments = [],
        ?string $pageSlug = null
    ): ?array {
        $shortcodeName = self::extractScalarString($shortcode['name'] ?? null);
        $shortcodeAttributes = \is_array($shortcode['attributes'] ?? null)
            ? $shortcode['attributes']
            : [];
        $shortcodeContent = self::extractNullableString($shortcode['content'] ?? null);
        $shortcodeOriginal = self::extractScalarString($shortcode['original'] ?? null);

        // Set page context for unaddressed node tracking
        $this->pageId = self::extractScalarString($shortcode['id'] ?? null);
        $this->pageLink = self::extractScalarString($shortcode['link'] ?? null);

        $name = $shortcodeName;
        if (! isset($this->handlers[$name])) {
            // Recognized-but-dropped shortcodes emit nothing and are not unaddressed.
            if ($this->isDroppedShortcode($name)) {
                return null;
            }

            // Track unhandled shortcode
            $this->trackUnaddressedShortcode(
                $name,
                $site,
                $shortcodeOriginal,
                $pageSlug
            );

            return null;
        }

        if ($outputType === 'bard') {
            // Handle shortcodes that don't have Bard sets
            if ($name === 'audio' || $name === 'accordion') {
                return null;
            }

            // Set ProcessingContext for the shortcode processor
            ProcessingContext::setShortcodeContext(
                $attachments,
                $shortcodeContent
            );
            $handler = $this->instantiateHandler($name);
            if (! $handler instanceof ShortcodeProcessorInterface) {
                return null;
            }

            $result = $handler->parse(
                $shortcodeAttributes,
                $site,
                $assetImporter,
                $output,
                $terms,
                $pageSlug
            );
            // Handle other shortcodes that return arrays
            $values = self::extractSetValues($result);
            // Handle nested structures
            $bardSet = [
                'type' => 'set',
                'attrs' => [
                    'id' => (new RowId)->generate(),
                    'values' => $values,
                ],
            ];

            return $bardSet;
        }

        if ($outputType === 'page_builder') {
            // For 'page_builder', delegate to the handler's toPageBuilderBlock() method.
            $handlerClass = $this->handlers[$name]['class'];
            // Check if handler is CaptionShortcodeProcessor which requires content parameter
            if ($handlerClass === CaptionShortcodeProcessor::class) {
                // Create caption handler instance
                $captionHandler = new CaptionShortcodeProcessor;

                // Caption requires content as an argument (content comes before pageSlug)
                return $captionHandler->toPageBuilderBlock(
                    $shortcodeAttributes,
                    $site,
                    $assetImporter,
                    $output,
                    $terms,
                    $shortcodeContent,
                    $pageSlug
                );
            }

            // Handlers without a page builder block (audio, accordion) return null
            $handler = $this->instantiateHandler($name);
            if (! \method_exists($handler, 'toPageBuilderBlock')) {
                return null;
            }

            // Call toPageBuilderBlock on handler for non-caption shortcodes
            $pageBuilderBlock = $handler->toPageBuilderBlock(
                $shortcodeAttributes,
                $site,
                $assetImporter,
                $output,
                $terms,
                $pageSlug
            );

            return \is_array($pageBuilderBlock) ? $pageBuilderBlock : null;
        }

        // Enhance log context with entry information
        $logData = LogContextHelper::enhanceWithEntryContext(
            [
                'site' => $site,
                'pageSlug' => $pageSlug,
                'outputType' => $outputType,
                'shortcode_name' => $name,
            ]
        );

        // Log warning about unhandled output type
        Log::warning(
            LogContextHelper::formatMessageWithEntry(
                "ShortcodeProcessor::process: Unhandled outputType '$outputType' for shortcode '$name'",
                $logData['page_url']
            ),
            $logData['context']
        );

        return null;
    }

    /**
     * Processes shortcodes in content, converting supported ones to Tiptap HTML.
     *
     * Recognized-but-dropped shortcodes are stripped. Unregistered ones are left
     * in place untouched; extract() does not return them, so they are not
     * tracked as unaddressed here.
     *
     * @param  string  $content  HTML content containing shortcodes
     * @param  string  $site  Statamic site handle
     * @param  AssetImporter  $assetImporter  Asset importer instance
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects from XML
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects from XML
     * @param  array<int|string, mixed>  $unaddressedBlocks  Unused; kept for existing callers
     * @param  ?string  $pageSlug  Page slug for logging
     * @return string Processed content with shortcodes replaced or left as-is
     */
    public function processShortcodesInContent(
        string $content,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = [],
        array $terms = [],
        array $unaddressedBlocks = [],
        ?string $pageSlug = null
    ): string {
        $shortcodes = $this->extractShortcodes(
            $content
        );

        // A lone registered shortcode wrapped in <p> becomes the handler markup alone
        if (
            \count($shortcodes) === 1
            && isset($this->handlers[$shortcodes[0]['name']])
            && \preg_match(
                '/^<p>\s*'.preg_quote(
                    $shortcodes[0]['original'],
                    '/'
                ).'\s*<\/p>$/s',
                \trim($content)
            ) === 1
        ) {
            return $this->toTiptapHtml(
                $shortcodes[0],
                $site,
                $assetImporter,
                $output,
                $attachments,
                $terms,
                $pageSlug
            );
        }

        // ---
        // Replace each shortcode in place. extract() returns only registered and
        // dropped shortcodes, and toTiptapHtml() renders a dropped one as '', which
        // strips its raw [shortcode] text from the imported content.
        // ---
        $processedContent = $content;
        foreach ($shortcodes as $shortcode) {
            $processedContent = self::replaceShortcode(
                $processedContent,
                $shortcode['original'],
                $this->toTiptapHtml(
                    $shortcode,
                    $site,
                    $assetImporter,
                    $output,
                    $attachments,
                    $terms,
                    $pageSlug
                )
            );
        }

        return $processedContent;
    }

    /**
     * Replaces a shortcode's original text with its rendered HTML.
     *
     * HTML that closes the surrounding paragraph before it, or opens a new one
     * after it (a flattened accordion does both), also takes the whitespace and
     * line breaks wpautop left on that side of the shortcode. Left in place, a break would
     * end the closed paragraph, or start the new one, as a blank line.
     *
     * @param  string  $content  The content holding the shortcode
     * @param  string  $original  The shortcode's original text
     * @param  string  $html  The HTML to put in its place
     * @return string The content with every occurrence replaced
     */
    public static function replaceShortcode(
        string $content,
        string $original,
        string $html
    ): string {
        // Take the breaks on the side where the HTML closes or opens a paragraph
        $before = \str_starts_with($html, '</p>') ? '(?:\s|<br\s*\/?>)*' : '';
        $after = \str_ends_with($html, '<p>') ? '(?:\s|<br\s*\/?>)*' : '';

        // With neither this is a plain replacement; a failed pattern falls back to one
        return \preg_replace_callback(
            '/'.$before.\preg_quote($original, '/').$after.'/',
            static fn (): string => $html,
            $content
        ) ?? \str_replace($original, $html, $content);
    }

    /**
     * Records a malformed self-closing shortcode as an import warning.
     *
     * Fired by extract() when a glued-on self-closing slash is stripped from an
     * unquoted attribute value (e.g. [table id=2/] → id="2"). Names the affected
     * entry via ProcessingContext so source-content defects stay visible to whoever
     * runs the import instead of being silently repaired.
     *
     * @param  string  $shortcodeName  The shortcode name (e.g. 'table')
     * @param  string  $attribute  The attribute whose value was normalized (e.g. 'id')
     * @param  string  $rawValue  The value as parsed, with the trailing slash (e.g. '2/')
     * @param  string  $normalizedValue  The value after stripping the slash (e.g. '2')
     * @param  string  $original  The original shortcode text (e.g. '[table id=2/]')
     */
    private function recordMalformedShortcodeWarning(
        string $shortcodeName,
        string $attribute,
        string $rawValue,
        string $normalizedValue,
        string $original
    ): void {
        // Resolve the affected entry URL from the current processing context
        $logData = LogContextHelper::enhanceWithEntryContext([]);

        // Add warning to centralized warning manager
        ImportWarningManager::addWarning(
            'content',
            'malformed_shortcodes',
            [
                'shortcode_name' => $shortcodeName,
                'attribute' => $attribute,
                'raw_value' => $rawValue,
                'normalized_value' => $normalizedValue,
                'original' => $original,
                'page_url' => $logData['page_url'],
            ]
        );
    }

    /**
     * Tracks an unhandled shortcode as an unaddressed node.
     *
     * Records the shortcode for reporting and adds an import warning.
     *
     * @param  string  $shortcodeName  Name of the unhandled shortcode
     * @param  string  $site  Statamic site handle
     * @param  string  $original  Original shortcode text (e.g., '[1]')
     * @param  ?string  $pageSlug  Page slug for logging
     */
    private function trackUnaddressedShortcode(
        string $shortcodeName,
        string $site,
        string $original,
        ?string $pageSlug = null
    ): void {
        // Track the unhandled shortcode with original text
        $this->unaddressedNodes["shortcode:$shortcodeName"][] = [
            'id' => $this->pageId,
            'slug' => $pageSlug,
            'link' => $this->pageLink,
            'original' => $original,
        ];

        // Add warning to centralized warning manager
        ImportWarningManager::addWarning(
            'content',
            'unaddressed_shortcodes',
            [
                'shortcode_name' => $shortcodeName,
                'original' => $original,
                'site' => $site,
                'page_slug' => $pageSlug,
                'page_id' => $this->pageId,
                'page_link' => $this->pageLink,
            ]
        );
    }

    /**
     * Gets unaddressed shortcodes for reporting.
     *
     * Returns the list of unhandled shortcodes with their context.
     *
     * @return array<string, array<array{id: string, slug: ?string, link: string, original: string}>>
     */
    public function getUnaddressedNodes(): array
    {
        return $this->unaddressedNodes;
    }

    /**
     * Registers a new shortcode handler.
     *
     * Adds a handler for a specific shortcode, mapping it to a set type and
     * handler class.
     *
     * @param  string  $name  The shortcode name (e.g., 'show-people')
     * @param  string  $setType  The Bard set type (e.g., 'people')
     * @param  string  $handlerClass  The handler class
     */
    public function registerHandler(
        string $name,
        string $setType,
        string $handlerClass
    ): void {
        $this->handlers[$name] = ['set' => $setType, 'class' => $handlerClass];
    }

    /**
     * Instantiates the registered handler class for a shortcode.
     *
     * PeopleShortcodeProcessor takes the shortcode name, because show-team and
     * show-people read different WordPress taxonomies.
     *
     * @param  string  $name  A registered shortcode name
     * @return object The handler instance
     */
    private function instantiateHandler(
        string $name
    ): object {
        $handlerClass = $this->handlers[$name]['class'];

        return ($handlerClass === PeopleShortcodeProcessor::class)
            ? new $handlerClass($name)
            : new $handlerClass;
    }

    /**
     * Returns the registered shortcode handlers.
     *
     * Provides the list of configured shortcode handlers.
     *
     * @return array<string, array{set: string, class: string}>
     */
    public function getHandlers(): array
    {
        return $this->handlers;
    }

    /**
     * Determines whether a shortcode is recognized but intentionally dropped.
     *
     * Dropped shortcodes (e.g. subpage-list) are stripped from imported content
     * instead of being converted to a set or block, and are not reported as
     * unaddressed. See {@see $droppedShortcodes} for the per-shortcode rationale.
     *
     * @param  string  $name  The shortcode name (e.g. 'subpage-list')
     * @return bool True if the shortcode is registered as a recognized drop
     */
    public function isDroppedShortcode(string $name): bool
    {
        // Names and the dropped list are both strings, so strict and loose agree.
        return \in_array(
            $name,
            $this->droppedShortcodes,
            // @pest-mutate-ignore: TrueToFalse
            true
        );
    }

    /**
     * Normalize scalar values into strings.
     *
     * @param  mixed  $value  Raw value to normalize
     * @return string Normalized scalar string or empty string
     */
    private static function extractScalarString(
        mixed $value
    ): string {
        if (\is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * Normalize scalar values into nullable strings.
     *
     * @param  mixed  $value  Raw value to normalize
     * @return string|null Normalized nullable string
     */
    private static function extractNullableString(
        mixed $value
    ): ?string {
        $stringValue = self::extractScalarString($value);

        return $stringValue === '' ? null : $stringValue;
    }

    /**
     * Extract parsed set values from nested or flat parser results.
     *
     * @param  array<int|string, mixed>|null  $result  Parser result payload, null when the parser produced none
     * @return array<int|string, mixed> Normalized values array
     */
    private static function extractSetValues(
        ?array $result
    ): array {
        if ($result === null) {
            return [];
        }

        if (
            isset($result['attrs'])
            && \is_array($result['attrs'])
            && isset($result['attrs']['values'])
            && \is_array($result['attrs']['values'])
        ) {
            return $result['attrs']['values'];
        }

        return $result;
    }
}
