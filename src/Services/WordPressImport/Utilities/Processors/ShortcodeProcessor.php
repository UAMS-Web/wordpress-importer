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
 * unhandled shortcodes as unaddressed nodes, suppressing those within specific
 * unaddressed blocks (e.g., [table] in wp:tablepress/table).
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
     * Suppression list mapping unaddressed block types to shortcodes to ignore.
     *
     * @var array<string, string[]>
     */
    private array $suppressedShortcodes = [
        'tablepress/table' => ['table'],
        'formidable/simple-form' => ['formidable'],
    ];

    /**
     * Extracts shortcode data from text.
     *
     * Parses shortcode syntax (e.g., [show-people attr="value"]) into a
     * standardized array.
     *
     * @param  string  $content  The raw text containing shortcodes
     * @param  OutputInterface  $output  Console output for logging errors
     * @return array<int|string, mixed> Array of shortcode data (e.g., [['name' => 'show-people', 'attributes' => [...], 'original' => '[...]']])
     */
    public function extract(
        string $content,
        OutputInterface $output
    ): array {
        $shortcodes = [];

        // Match shortcodes with optional content
        $shortcodePattern = '/\[([^\s]+)\s*([^\]]*?)\](?:((?:[^\[]|\[(?!\/\1\]))*?)\[\/\1\])?/s';
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

            $attrString = \trim($match[2]);
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
                    $key = self::extractScalarString($attr[1] ?? '');

                    if ($key === '') {
                        continue;
                    }

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
                        $rawValue = \trim($attr[4]);
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
     * Uses raw attributes for 'show-people' to preserve complex structures and
     * handler's parse method for others.
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
        $shortcodeAttributes = \is_array($shortcode['attributes'] ?? null)
            ? $shortcode['attributes']
            : [];
        $shortcodeContent = self::extractNullableString($shortcode['content'] ?? null);

        // Recognized-but-dropped shortcodes (e.g. subpage-list) emit nothing: the
        // page template renders their output on its own, so return an empty string
        // and let callers strip the original text. No warning — this is expected.
        if ($this->isDroppedShortcode($shortcodeName)) {
            return '';
        }

        // Get the handler configuration for this shortcode type
        $handler = $this->handlers[$shortcodeName] ?? null;

        // Extract the set type from the handler configuration
        $setType = $handler['set'] ?? 'unknown';

        // Check if handler has a custom toTiptapHtml method
        if ($handler !== null) {
            // Get the handler class name
            $handlerClass = $handler['class'];

            // ---
            // Create an instance of the handler class
            //
            // Note: Pass shortcode name to constructor only for PeopleShortcodeProcessor
            // ---
            // Pass shortcode name to constructor only for PeopleShortcodeProcessor
            $handlerInstance = ($handlerClass === PeopleShortcodeProcessor::class)
                ? new $handlerClass($shortcodeName)
                : new $handlerClass;

            // Check if the handler has a custom toTiptapHtml method
            if (
                method_exists(
                    $handlerInstance,
                    'toTiptapHtml'
                )
            ) {
                // Delegate to the custom handler implementation
                $customResult = $handlerInstance->toTiptapHtml(
                    $shortcode,
                    $site,
                    $assetImporter,
                    $output,
                    $attachments,
                    $terms,
                    $pageSlug
                );

                return \is_string($customResult) ? $customResult : '';
            }
        }

        // Initialize values array for default processing
        $values = [];

        // Process shortcode using the default handler if no custom method exists
        if ($handler !== null) {
            // Set ProcessingContext for the shortcode processor
            ProcessingContext::setShortcodeContext(
                $attachments,
                $shortcodeContent
            );

            // Get the handler class name for default processing
            $handlerClass = $handler['class'];

            // ---
            // Create an instance of the handler class for default processing
            //
            // Note: Pass shortcode name to constructor only for PeopleShortcodeProcessor
            // ---
            $handlerInstance = ($handlerClass === PeopleShortcodeProcessor::class)
                ? new $handlerClass($shortcodeName)
                : new $handlerClass;

            if (! $handlerInstance instanceof ShortcodeProcessorInterface) {
                return '';
            }

            // Parse the shortcode using the handler's parse method
            $result = $handlerInstance->parse(
                $shortcodeAttributes,
                $site,
                $assetImporter,
                $output,
                $terms,
                $pageSlug
            );

            // Handle both old Bard set structure and new simplified structure
            $values = self::extractSetValues($result);
        } else { // Handle case where no valid handler is found
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'handler' => $handler,
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
        }

        // Use shared utility method to generate Tiptap HTML
        return self::generateTiptapHtml(
            $values,
            $setType,
            $shortcodeContent ?? ''
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
                [],
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
            $handlerClass = $this->handlers[$name]['class'];
            // Pass shortcode name to constructor only for PeopleShortcodeProcessor
            $handler = ($handlerClass === PeopleShortcodeProcessor::class)
                ? new $handlerClass($name)
                : new $handlerClass;
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
            // Handle shortcodes that don't have page builder blocks
            if ($name === 'audio' || $name === 'accordion') {
                return null;
            }

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

            // Pass shortcode name to constructor only for PeopleShortcodeProcessor
            $handler = ($handlerClass === PeopleShortcodeProcessor::class)
                ? new $handlerClass($name)
                : new $handlerClass;
            if (! $handler instanceof ShortcodeProcessorInterface) {
                return null;
            }

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
     * Tracks unhandled shortcodes as unaddressed nodes, unless suppressed by
     * unaddressed blocks (e.g., [table] in wp:tablepress/table). Used by
     * ContentProcessor for non-ACF content.
     *
     * @param  string  $content  HTML content containing shortcodes
     * @param  string  $site  Statamic site handle
     * @param  AssetImporter  $assetImporter  Asset importer instance
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects from XML
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects from XML
     * @param  array<int|string, mixed>  $unaddressedBlocks  List of unaddressed block types for suppression
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
        $shortcodes = $this->extract(
            $content,
            $output
        );
        $processedContent = $content;
        $singleShortcode = $shortcodes[0] ?? [];
        $singleOriginal = \is_array($singleShortcode)
            ? self::extractScalarString($singleShortcode['original'] ?? null)
            : '';

        // Handle single shortcode in <p> tag
        if (
            \count($shortcodes) === 1
            && \preg_match(
                '/^<p>\s*'.preg_quote(
                    $singleOriginal,
                    '/'
                ).'\s*<\/p>$/s',
                \trim($content)
            ) === 1
        ) {
            $shortcode = $shortcodes[0];
            if (! \is_array($shortcode)) {
                return $processedContent;
            }

            $shortcodeName = self::extractScalarString($shortcode['name'] ?? null);

            if (isset($this->getHandlers()[$shortcodeName])) {
                return $this->toTiptapHtml(
                    $shortcode,
                    $site,
                    $assetImporter,
                    $output,
                    $attachments,
                    $terms,
                    $pageSlug
                );
            }
        }

        // Process all shortcodes
        foreach ($shortcodes as $shortcode) {
            if (! \is_array($shortcode)) {
                continue;
            }

            $shortcodeName = self::extractScalarString($shortcode['name'] ?? null);
            $shortcodeOriginal = self::extractScalarString($shortcode['original'] ?? null);

            if (isset($this->getHandlers()[$shortcodeName])) {
                $tiptapHtml = $this->toTiptapHtml(
                    $shortcode,
                    $site,
                    $assetImporter,
                    $output,
                    $attachments,
                    $terms,
                    $pageSlug
                );
                $processedContent = \str_replace(
                    $shortcodeOriginal,
                    $tiptapHtml,
                    $processedContent
                );
            } elseif ($this->isDroppedShortcode($shortcodeName)) {
                // Strip recognized-but-dropped shortcodes so their raw [shortcode]
                // text does not leak into imported content.
                $processedContent = \str_replace(
                    $shortcodeOriginal,
                    '',
                    $processedContent
                );
            } else {
                // Track unhandled shortcode if not suppressed
                $this->trackUnaddressedShortcode(
                    $shortcodeName,
                    $site,
                    $unaddressedBlocks,
                    $shortcodeOriginal,
                    $pageSlug
                );
            }
        }

        return $processedContent;
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
     * Tracks an unhandled shortcode as an unaddressed node, unless suppressed.
     *
     * Records unhandled shortcodes for reporting, skipping those in suppressed
     * blocks like wp:tablepress/table.
     *
     * @param  string  $shortcodeName  Name of the unhandled shortcode
     * @param  string  $site  Statamic site handle
     * @param  array<int|string, mixed>  $unaddressedBlocks  List of unaddressed block types
     * @param  string  $original  Original shortcode text (e.g., '[1]')
     * @param  ?string  $pageSlug  Page slug for logging
     */
    private function trackUnaddressedShortcode(
        string $shortcodeName,
        string $site,
        array $unaddressedBlocks,
        string $original,
        ?string $pageSlug = null
    ): void {
        // Check if the shortcode should be suppressed due to an unaddressed block
        foreach ($unaddressedBlocks as $blockType) {
            if (! \is_string($blockType)) {
                continue;
            }

            if (
                isset($this->suppressedShortcodes[$blockType])
                && \in_array(
                    $shortcodeName,
                    $this->suppressedShortcodes[$blockType],
                    true
                )
            ) {
                return;
            }
        }

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
        return \in_array(
            $name,
            $this->droppedShortcodes,
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
        if (\is_string($value)) {
            return $value;
        }

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
        if ($value === null) {
            return null;
        }

        $stringValue = self::extractScalarString($value);

        return $stringValue === '' ? null : $stringValue;
    }

    /**
     * Extract parsed set values from nested or flat parser results.
     *
     * @param  mixed  $result  Parser result payload
     * @return array<int|string, mixed> Normalized values array
     */
    private static function extractSetValues(
        mixed $result
    ): array {
        if (! \is_array($result)) {
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
