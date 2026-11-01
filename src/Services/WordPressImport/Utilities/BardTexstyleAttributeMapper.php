<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Support\Str;

/**
 * Maps HTML attributes to Bard Texstyle attributes during WordPress import.
 *
 * Extracts HTML attributes (class, style, id, start, reversed) from DOM elements
 * and maps them to corresponding Bard Texstyle attributes (clearboth, id, start,
 * reversed, list_style) for applicable Bard node types.
 *
 * Bard Texstyle also accepts an id on `blockquote` and `image`, and this reads
 * neither: `BardParser` does not register those nodes, so an import never holds
 * one (#149).
 */
class BardTexstyleAttributeMapper
{
    /**
     * Normalize whitespace while guaranteeing a string return type.
     *
     * @param  string  $value  The string to normalize
     * @return string The normalized string
     */
    private static function normalizeWhitespace(string $value): string
    {
        // Normalize repeated whitespace to a single space
        $normalized = \preg_replace(
            '/\s+/',
            ' ',
            \trim($value)
        );

        // Return normalized value when preg_replace succeeds
        if (\is_string($normalized)) {
            return $normalized;
        }

        // Return empty string when preg_replace fails
        return '';
    }

    /**
     * Split a whitespace-delimited class string into individual class tokens.
     *
     * @return list<string>
     */
    private static function splitWhitespaceClasses(string $classNames): array
    {
        // Split the class string on whitespace boundaries. The trim also strips the
        // NUL a block comment's JSON className can carry, which `\s` does not match.
        $classes = \preg_split(
            '/\s+/',
            \trim($classNames)
        );

        // Return an empty list when preg_split fails
        return $classes !== false ? $classes : [];
    }

    /**
     * Valid list style values for ordered lists.
     *
     * Maps class names and style values to Bard Texstyle list_style attribute
     * values. Used for both class-based and style-based list style extraction.
     *
     * @var array<string, string>
     */
    // A property initializer is never reported as an executed line; removing an
    // entry by hand fails its list-style rows in BardTexstyleAttributeMapperTest.
    // @pest-mutate-ignore: RemoveArrayItem
    private static array $listStyleMap = [
        'decimal' => 'decimal',
        'upper-alpha' => 'upper-alpha',
        'lower-alpha' => 'lower-alpha',
        'upper-roman' => 'upper-roman',
        'lower-roman' => 'lower-roman',
        'outline' => 'outline',
    ];

    /**
     * Class names that mark a heading or horizontal rule as clearboth.
     *
     * @var list<string>
     */
    // A constant initializer is never reported as an executed line; removing an
    // entry by hand fails its row of the clearboth tests in
    // BardTexstyleAttributeMapperTest, for an element and for a block comment.
    // @pest-mutate-ignore: RemoveArrayItem
    private const array CLEARBOTH_CLASSES = [
        'clear',
        'clear-both',
        'clearboth',
    ];

    /**
     * The attributes Statamic's LinkMark::addAttributes() defines for a link mark.
     *
     * @var array<string>
     */
    // A property initializer is never reported as an executed line. A test
    // compares this list with LinkMark's own, so removing an entry, or Statamic
    // adding one, fails it.
    // @pest-mutate-ignore: RemoveArrayItem
    private static array $standardLinkAttributes = [
        'href',
        'target',
        'title',
        'rel',
    ];

    /**
     * Maps HTML attributes to Bard Texstyle attributes for Bard content nodes.
     *
     * Processes Bard content array and extracts HTML attributes from the original
     * HTML to add Bard Texstyle attributes. Handles clearboth, id, start, reversed,
     * and list_style attributes for applicable node types.
     *
     * @param  array<int|string, mixed>  $bardContent  The Bard content array (ProseMirror JSON format)
     * @param  string  $html  The original HTML content
     * @return array<int|string, mixed> The Bard content with Texstyle attributes added
     */
    public static function mapAttributes(
        array $bardContent,
        string $html
    ): array {
        // Check if Bard content is empty. Mapping no nodes returns them unchanged
        // too, so this only saves loading the HTML.
        if ($bardContent === []) {
            // Return empty content early
            // @pest-mutate-ignore: RemoveEarlyReturn
            return $bardContent;
        }

        // Load HTML into DOMDocument for attribute extraction
        $doc = TextProcessor::loadHtml($html);

        // Extract attribute mappings from HTML
        $attributeMap = self::extractAttributeMap($doc);

        // Extract link mark attribute mappings from HTML anchor tags
        $linkAttributeMap = self::extractLinkAttributeMap($doc);

        // Apply attributes to Bard content
        $mappedContent = self::applyAttributes(
            $bardContent,
            $attributeMap,
            $linkAttributeMap
        );

        // Clean up variables to free up memory
        unset(
            $doc,
            $attributeMap,
            $linkAttributeMap
        );

        // Return mapped content with attributes applied
        return $mappedContent;
    }

    /**
     * Extracts attribute mappings from HTML DOM.
     *
     * Traverses the DOM to extract HTML attributes and Gutenberg block comment
     * attributes, then creates a mapping structure that can be matched to Bard
     * nodes. Uses element content and structure to match elements to Bard nodes.
     *
     * @param  DOMDocument  $doc  The DOMDocument containing the HTML
     * @return array<int|string, mixed> Array of attribute mappings keyed by element signature
     */
    private static function extractAttributeMap(
        DOMDocument $doc
    ): array {
        // Initialize array to store attribute mappings
        $attributeMap = [];

        // Get root div element
        $root = $doc->getElementsByTagName('div')->item(0);

        // Check if root element exists. TextProcessor::loadHtml() always appends
        // one, so this never returns.
        if ($root === null) {
            // Return empty attribute map if no root element
            // @pest-mutate-ignore: RemoveEarlyReturn
            return $attributeMap;
        }

        // Extract attributes from Gutenberg block comments and map to elements
        $blockCommentAttributes = self::extractBlockCommentAttributes($root);

        // Traverse DOM recursively to extract attributes from HTML elements
        self::traverseDomForAttributes(
            $root,
            $attributeMap,
            $blockCommentAttributes
        );

        // Clean up variables to free up memory
        unset(
            $root,
            $blockCommentAttributes
        );

        // Return attribute map with extracted attributes
        return $attributeMap;
    }

    /**
     * Extracts link mark attribute mappings from HTML anchor tags.
     *
     * Traverses the DOM to extract target, title, and rel attributes from anchor
     * tags, then creates a mapping structure that can be matched to link marks in
     * Bard content. Uses href and text content to match anchor tags to link marks.
     *
     * @param  DOMDocument  $doc  The DOMDocument containing the HTML
     * @return array<int|string, mixed> Array of link attribute mappings keyed by link signature
     */
    private static function extractLinkAttributeMap(
        DOMDocument $doc
    ): array {
        // Initialize array to store link attribute mappings
        $linkAttributeMap = [];

        // Get all anchor tags
        $links = $doc->getElementsByTagName('a');

        // Loop through each anchor tag
        foreach ($links as $link) {
            // Get link signature for matching
            $linkSignature = self::getLinkSignature($link);

            // Extract link attributes (target, title, rel)
            $linkAttributes = self::extractLinkAttributes($link);

            // Check if attributes were found
            if ($linkAttributes !== []) {
                // Store attributes keyed by signature
                $linkAttributeMap[$linkSignature] = $linkAttributes;
            }
        }

        // Clean up variables to free up memory
        unset(
            $links,
            $link,
            $linkSignature,
            $linkAttributes
        );

        // Return link attribute map with extracted attributes
        return $linkAttributeMap;
    }

    /**
     * Gets a signature for a link to use for matching.
     *
     * Creates a unique signature based on href and text content to match HTML
     * anchor tags to link marks in Bard content.
     *
     * @param  DOMElement  $link  The anchor element
     * @return string The link signature
     */
    private static function getLinkSignature(
        DOMElement $link
    ): string {
        // Get href attribute
        $href = $link->getAttribute('href');

        // Get text content and normalize whitespace
        $textContent = self::normalizeWhitespace($link->textContent);

        // Generate hash from href and text content
        $signatureHash = \substr(
            \md5("{$href}|{$textContent}"),
            0,
            8
        );

        // Build signature: link:{hash}
        return "link:{$signatureHash}";
    }

    /**
     * Extracts target, title, and rel attributes from an anchor element.
     *
     * Examines the anchor element's target, title, and rel attributes and
     * returns them in an array format suitable for mapping to link marks.
     *
     * @param  DOMElement  $link  The anchor element
     * @return array<int|string, mixed> Array of link attributes (target, title, rel)
     */
    private static function extractLinkAttributes(
        DOMElement $link
    ): array {
        // Initialize attributes array
        $attributes = [];

        // Extract target attribute
        $target = $link->getAttribute('target');

        // Check if target is not empty
        if ($target !== '') {
            // Add target to attributes
            $attributes['target'] = $target;
        }

        // Extract title attribute
        $title = $link->getAttribute('title');

        // Check if title is not empty
        if ($title !== '') {
            // Add title to attributes
            $attributes['title'] = $title;
        }

        // Extract rel attribute
        $rel = $link->getAttribute('rel');

        // Check if rel is not empty
        if ($rel !== '') {
            // Add rel to attributes
            $attributes['rel'] = $rel;
        }

        // Return extracted attributes
        return $attributes;
    }

    /**
     * Extracts attributes from Gutenberg block comments and maps them to elements.
     *
     * Finds Gutenberg block comments (e.g., `<!-- wp:heading {"className":"clear"} -->`)
     * and extracts their JSON attributes, then maps them to the corresponding HTML
     * elements that follow the comments.
     *
     * @param  DOMNode  $root  The root DOM node to search
     * @return array<int|string, mixed> Array mapping element signatures to block comment attributes
     */
    private static function extractBlockCommentAttributes(
        DOMNode $root
    ): array {
        // Initialize array to store block comment attributes
        $blockCommentAttributes = [];

        // Traverse nodes recursively to find block comments
        self::traverseForBlockComments(
            $root,
            $blockCommentAttributes
        );

        // Return block comment attributes mapped by signature
        return $blockCommentAttributes;
    }

    /**
     * Recursively traverses DOM nodes to find Gutenberg block comments.
     *
     * Visits each node and extracts attributes from block comments, mapping them
     * to the corresponding HTML elements.
     *
     * @param  DOMNode  $node  The DOM node to traverse
     * @param  array<int|string, mixed>  &$blockCommentAttributes  Reference to the block comment attributes array
     */
    private static function traverseForBlockComments(
        DOMNode $node,
        array &$blockCommentAttributes
    ): void {
        // Check if this is a comment node
        if ($node instanceof DOMComment) {
            // Check if this is a Gutenberg block comment
            $commentContent = \trim($node->data);

            // Extract block type using regex
            if (
                \preg_match(
                    '/^wp:([^\s]+)/',
                    $commentContent,
                    $typeMatches
                ) === 1
            ) {
                // Extract JSON attributes using TextProcessor method, passing the
                // block type. The extractor reads neither the site nor the block type.
                // @pest-mutate-ignore: DecrementInteger, EmptyStringToNotEmpty
                $jsonString = TextProcessor::extractJsonFromWordPressComment(
                    $commentContent,
                    '',
                    $typeMatches[1]
                );

                // Decode JSON attributes; a comment without any, or with JSON that
                // does not decode to an array, carries none
                $decoded = \json_decode(
                    $jsonString,
                    true
                );
                $jsonAttributes = \is_array($decoded) ? $decoded : [];

                // Find the corresponding HTML element (next element after comment)
                $element = self::findElementAfterComment($node);

                // Check if element was found
                if ($element instanceof DOMElement) {
                    // Get element signature for matching
                    $signature = self::getElementSignature($element);

                    // Extract attributes from block comment
                    $attributes = self::extractBlockCommentElementAttributes(
                        $element,
                        $jsonAttributes
                    );

                    // Check if attributes were found
                    if ($attributes !== []) {
                        // Store attributes keyed by signature
                        $blockCommentAttributes[$signature] = $attributes;
                    }
                }

                // Clean up variables to free up memory
                unset(
                    $typeMatches,
                    $jsonString,
                    $decoded,
                    $jsonAttributes,
                    $element,
                    $signature,
                    $attributes,
                    $commentContent
                );
            }
        }

        // Traverse child nodes recursively
        if ($node->hasChildNodes()) {
            // Loop through child nodes
            foreach ($node->childNodes as $child) {
                // Recursively traverse child nodes for block comments
                self::traverseForBlockComments(
                    $child,
                    $blockCommentAttributes
                );
            }
        }
    }

    /**
     * Finds the HTML element that follows a Gutenberg block comment.
     *
     * Searches for the next element node after a block comment, which is typically
     * the HTML element that corresponds to the block.
     *
     * @param  DOMNode  $commentNode  The comment node
     * @return DOMElement|null The element node if found, null otherwise
     */
    private static function findElementAfterComment(
        DOMNode $commentNode
    ): ?DOMElement {
        // Start with the next sibling
        $currentNode = $commentNode->nextSibling;

        // Traverse siblings to find the first element node
        while ($currentNode instanceof DOMNode) {
            // Check if this is an element node
            if ($currentNode instanceof DOMElement) {
                // Return the element node
                return $currentNode;
            }

            // Move to next sibling
            $currentNode = $currentNode->nextSibling;
        }

        // Return null if no element node found
        return null;
    }

    /**
     * Extracts Bard Texstyle attributes from Gutenberg block comment JSON.
     *
     * Examines the block comment's JSON attributes (className, reversed, start)
     * and the corresponding HTML element to extract values that should be mapped
     * to Bard Texstyle attributes.
     *
     * @param  DOMElement  $element  The HTML element
     * @param  array<int|string, mixed>  $jsonAttributes  The JSON attributes from the block comment
     * @return array<int|string, mixed> Array of Bard Texstyle attributes
     */
    private static function extractBlockCommentElementAttributes(
        DOMElement $element,
        array $jsonAttributes
    ): array {
        // Initialize attributes array
        $attributes = [];

        // Get tag name, which the HTML parser has already lowercased
        $tagName = $element->tagName;

        // ---
        // Extract className from block comment and use for clearboth/list_style
        // detection
        // ---
        $className = $jsonAttributes['className'] ?? null;

        // Check if className is present
        if ($className !== null && \is_string($className)) {
            // Split className into individual classes
            $classes = self::splitWhitespaceClasses($className);

            // ---
            // Check for clearboth classes for heading and hr elements
            // ---
            if (
                \preg_match(
                    '/^h[1-6]$/',
                    $tagName
                ) === 1
                || $tagName === 'hr'
            ) {
                // Set clearboth when any class matches
                if (\array_intersect($classes, self::CLEARBOTH_CLASSES) !== []) {
                    $attributes['clearboth'] = 'clearboth';
                }
            }

            // Check for list_style classes for ol elements
            if ($tagName === 'ol') {
                // Loop through classes to find matching list_style class
                foreach ($classes as $classValue) {
                    // Check if class maps to a list_style value
                    if (isset(self::$listStyleMap[$classValue])) {
                        // Set list_style attribute from class map
                        $attributes['list_style'] = self::$listStyleMap[$classValue];

                        // Exit loop after first match
                        break;
                    }
                }
            }
        }

        // ---
        // Extract reversed attribute from block comment for ol elements
        // ---

        // Check if element is an ordered list
        if ($tagName === 'ol') {
            // Check if reversed is present in block comment
            if (isset($jsonAttributes['reversed'])) {
                // Convert to boolean (block comment has boolean value). The cast
                // only satisfies PHPStan's strict boolean rule; the check below
                // reads the value the same way without it.
                // @pest-mutate-ignore: RemoveBooleanCast
                $reversed = (bool) $jsonAttributes['reversed'];

                // Check if reversed is true
                if ($reversed) {
                    // Set reversed attribute
                    $attributes['reversed'] = true;
                }
            }

            // Extract start attribute from block comment
            if (isset($jsonAttributes['start'])) {
                // Read start value from block comment
                $startValue = $jsonAttributes['start'];

                // Convert to integer when the value is already numeric
                if (\is_int($startValue)) {
                    $start = $startValue;
                } elseif (
                    \is_string($startValue)
                    && \is_numeric($startValue)
                ) {
                    $start = (int) $startValue;
                } else {
                    // Any value that is not above 0 is dropped below
                    // @pest-mutate-ignore: DecrementInteger
                    $start = 0;
                }

                // Check if start is valid
                if ($start > 0) {
                    // Set start attribute
                    $attributes['start'] = $start;
                }
            }
        }

        // Return extracted attributes
        return $attributes;
    }

    /**
     * Recursively traverses DOM nodes to extract attributes.
     *
     * Visits each element node and extracts applicable attributes, storing them
     * in the attribute map keyed by element signature. Merges attributes from
     * Gutenberg block comments with HTML element attributes.
     *
     * @param  DOMNode  $node  The DOM node to traverse
     * @param  array<int|string, mixed>  &$attributeMap  Reference to the attribute map array
     * @param  array<int|string, mixed>  $blockCommentAttributes  Attributes from Gutenberg block comments
     */
    private static function traverseDomForAttributes(
        DOMNode $node,
        array &$attributeMap,
        array $blockCommentAttributes = []
    ): void {
        // Check if this is an element node
        if ($node instanceof DOMElement) {
            // Get element signature for matching
            $signature = self::getElementSignature($node);

            // Extract attributes for this element from HTML
            $attributes = self::extractElementAttributes($node);

            // Check if there are block comment attributes for this element
            if (isset($blockCommentAttributes[$signature])) {
                // Get block comment attributes for this signature
                $signatureBlockAttributes = $blockCommentAttributes[$signature];

                // Skip merge when block comment attributes are invalid
                if (! \is_array($signatureBlockAttributes)) {
                    $signatureBlockAttributes = [];
                }

                // ---
                // Merge block comment attributes with HTML attributes. Block comment attributes
                // take precedence for overlapping keys.
                // ---
                $attributes = [
                    ...$attributes,
                    ...$signatureBlockAttributes,
                ];
            }

            // Check if attributes were found
            if ($attributes !== []) {
                // Store attributes keyed by signature
                $attributeMap[$signature] = $attributes;
            }
        }

        // Traverse child nodes recursively
        if ($node->hasChildNodes()) {
            // Loop through child nodes
            foreach ($node->childNodes as $child) {
                // Recursively traverse child nodes for attributes
                self::traverseDomForAttributes(
                    $child,
                    $attributeMap,
                    $blockCommentAttributes
                );
            }
        }
    }

    /**
     * Gets a signature for an element to use for matching.
     *
     * Creates a unique signature based on element tag name, text content, and
     * position to match HTML elements to Bard nodes.
     *
     * @param  DOMElement  $element  The DOM element
     * @return string The element signature
     */
    private static function getElementSignature(
        DOMElement $element
    ): string {
        // Get tag name, which the HTML parser has already lowercased
        $tagName = $element->tagName;

        // Get text content and normalize whitespace
        $textContent = self::normalizeWhitespace($element->textContent);

        // ---
        // Get level for headings
        // ---

        // Initialize level variable
        $level = '';

        // Check if element is a heading
        if (
            \preg_match(
                '/^h[1-6]$/',
                $tagName
            ) === 1
        ) {
            // Extract heading level from tag name
            $level = \substr(
                $tagName,
                1
            );
        }

        // Build signature using shared helper method
        return self::buildSignature(
            $tagName,
            $level,
            $textContent
        );
    }

    /**
     * Builds a signature string from tag name, level, and text content.
     *
     * Creates a unique signature by combining tag name, optional heading level,
     * and a hash of the text content. Used by both getElementSignature and
     * getBardNodeSignature to avoid code duplication.
     *
     * @param  string  $tagName  The HTML tag name
     * @param  string  $level  The heading level (empty string if not a heading)
     * @param  string  $textContent  The text content to hash
     * @return string The complete signature
     */
    private static function buildSignature(
        string $tagName,
        string $level,
        string $textContent
    ): string {
        // ---
        // Normalize tag name for headings to use 'h' instead of 'h1', 'h2', etc.
        // This ensures consistent signatures between HTML elements and Bard nodes
        // ---

        // Use 'h' as the base tag name for headings, otherwise use the tag name
        // as-is. Both callers pass a level only for a heading.
        $signature = $level !== '' ? 'h' : $tagName;

        // ---
        // Add level for headings
        // ---

        // Check if level was extracted. Both sides of a match build their
        // signature here, so a change that applies to both still pairs the same
        // nodes with the same elements.
        // @pest-mutate-ignore: EmptyStringToNotEmpty, ConcatEqualToEqual
        if ($level !== '') {
            // Append level to signature
            $signature .= ":{$level}";
        }

        // ---
        // Add text content hash
        // ---

        // Normalize text content to handle whitespace differences
        $normalizedText = self::normalizeWhitespace($textContent);

        // Generate hash from normalized text content (truncated for uniqueness)
        $textHash = \substr(
            \md5($normalizedText),
            0,
            8
        );

        // Append text hash to signature
        $signature .= ":{$textHash}";

        // Return complete signature
        return $signature;
    }

    /**
     * Extracts Bard Texstyle attributes from an HTML element.
     *
     * Examines the element's class, style, id, start, and reversed attributes
     * to extract values that should be mapped to Bard Texstyle attributes.
     *
     * @param  DOMElement  $element  The DOM element
     * @return array<int|string, mixed> Array of Bard Texstyle attributes
     */
    private static function extractElementAttributes(
        DOMElement $element
    ): array {
        // Initialize attributes array
        $attributes = [];

        // Get tag name, which the HTML parser has already lowercased
        $tagName = $element->tagName;

        // ---
        // Extract clearboth attribute for heading and hr elements
        // ---

        // Check if element is a heading or horizontal rule
        if (
            \preg_match(
                '/^h[1-6]$/',
                $tagName
            ) === 1
            || $tagName === 'hr'
        ) {
            // Extract clearboth attribute from element
            $clearboth = self::extractClearbothAttribute($element);

            // Check if clearboth was found
            if ($clearboth !== null) {
                // Add clearboth to attributes
                $attributes['clearboth'] = $clearboth;
            }
        }

        // ---
        // Extract id attribute for applicable elements
        // ---

        // Check if element supports id attribute
        if (
            \preg_match(
                '/^h[1-6]$/',
                $tagName
            ) === 1
            || $tagName === 'p'
            || $tagName === 'ul'
            || $tagName === 'ol'
            || $tagName === 'pre'
            || $tagName === 'hr'
            || $tagName === 'li'
        ) {
            // Extract id attribute from element
            $id = self::extractIdAttribute($element);

            // Check if id was found
            if ($id !== null) {
                // Add id to attributes
                $attributes['id'] = $id;
            }
        }

        // ---
        // Extract start, reversed, and list_style attributes for ol elements
        // ---

        // Check if element is an ordered list
        if ($tagName === 'ol') {
            // Extract start attribute from element
            $start = self::extractStartAttribute($element);

            // Check if start was found
            if ($start !== null) {
                // Add start to attributes
                $attributes['start'] = $start;
            }

            // Extract reversed attribute from element
            $reversed = self::extractReversedAttribute($element);

            // Check if reversed was found
            if ($reversed !== null) {
                // Add reversed to attributes
                $attributes['reversed'] = $reversed;
            }

            // Extract list_style attribute from element
            $listStyle = self::extractListStyleAttribute($element);

            // Check if list_style was found
            if ($listStyle !== null) {
                // Add list_style to attributes
                $attributes['list_style'] = $listStyle;
            }
        }

        // Return extracted attributes
        return $attributes;
    }

    /**
     * Extracts clearboth attribute from element class and style attributes.
     *
     * Checks for matching class values (clear, clear-both, clearboth) or style
     * values (clear: both) and returns 'clearboth' if found, null otherwise.
     *
     * @param  DOMElement  $element  The DOM element
     * @return string|null 'clearboth' if found, null otherwise
     */
    private static function extractClearbothAttribute(
        DOMElement $element
    ): ?string {
        // Split class into individual classes
        $classes = self::splitWhitespaceClasses($element->getAttribute('class'));

        // Return clearboth value if any class matches
        if (\array_intersect($classes, self::CLEARBOTH_CLASSES) !== []) {
            return 'clearboth';
        }

        // ---
        // Check for matching style patterns
        //
        // Match: clear: both, clear: both;, clear:both, clear:both;
        // ---
        if (
            \preg_match(
                '/\bclear\s*:\s*both\b/i',
                $element->getAttribute('style')
            ) === 1
        ) {
            // Return clearboth value if style pattern matches
            return 'clearboth';
        }

        // Return null if no clearboth attribute found
        return null;
    }

    /**
     * Extracts id attribute from element.
     *
     * Returns the id attribute value if present, null otherwise.
     *
     * @param  DOMElement  $element  The DOM element
     * @return string|null The id value if found, null otherwise
     */
    private static function extractIdAttribute(
        DOMElement $element
    ): ?string {
        // Get id attribute
        $id = $element->getAttribute('id');

        // Check if id is not empty
        if ($id !== '') {
            // Return id value
            return $id;
        }

        // Return null if no id attribute found
        return null;
    }

    /**
     * Extracts start attribute from ol element.
     *
     * Returns the start attribute value if present, null otherwise.
     *
     * @param  DOMElement  $element  The DOM element
     * @return int|null The start value if found, null otherwise
     */
    private static function extractStartAttribute(
        DOMElement $element
    ): ?int {
        // Get start attribute
        $start = $element->getAttribute('start');

        // Convert to integer; an absent or non-numeric start reads as 0
        $startInt = (int) $start;

        // Check if conversion was successful
        if ($startInt > 0) {
            // Return start value as integer
            return $startInt;
        }

        // Return null if no start attribute found
        return null;
    }

    /**
     * Extracts reversed attribute from ol element.
     *
     * Returns true if reversed attribute is present and valid, null otherwise.
     * Valid values: 'reversed', empty string, or attribute present without value.
     *
     * @param  DOMElement  $element  The DOM element
     * @return bool|null True if reversed is present and valid, null otherwise
     */
    private static function extractReversedAttribute(
        DOMElement $element
    ): ?bool {
        // Check if reversed attribute exists
        if ($element->hasAttribute('reversed')) {
            // Get reversed attribute value
            $reversed = $element->getAttribute('reversed');

            // ---
            // Check if value is empty, 'reversed', or attribute is present
            // (HTML boolean attributes can be present without a value)
            // ---
            if (
                $reversed === ''
                || \strtolower($reversed) === 'reversed'
            ) {
                // Return true if reversed attribute is valid
                return true;
            }
        }

        // Return null if no reversed attribute found
        return null;
    }

    /**
     * Extracts list_style attribute from ol element class and style attributes.
     *
     * Checks for matching class values or style values and maps them to Bard
     * Texstyle list_style values.
     *
     * @param  DOMElement  $element  The DOM element
     * @return string|null The list_style value if found, null otherwise
     */
    private static function extractListStyleAttribute(
        DOMElement $element
    ): ?string {
        // Split class into individual classes
        $classes = self::splitWhitespaceClasses($element->getAttribute('class'));

        // Loop through classes to find matching list_style class
        foreach ($classes as $classValue) {
            // Check if class maps to a list_style value
            if (isset(self::$listStyleMap[$classValue])) {
                // Return list_style value from class map
                return self::$listStyleMap[$classValue];
            }
        }

        // Get style attribute; the patterns below allow any whitespace
        $style = $element->getAttribute('style');

        // ---
        // Map style values to list_style values
        // Match: list-style-type: decimal, list-style: decimal *, etc.
        // The * in list-style: decimal * means any word(s) after decimal
        // Match patterns: list-style-type: decimal, list-style: decimal,
        // list-style: decimal inside, list-style: decimal inside;
        // ---

        // Loop through list style values to check for matches
        foreach (self::$listStyleMap as $value => $listStyleValue) {
            // Escape special regex characters in the value
            $escapedValue = \preg_quote(
                $value,
                '/'
            );

            // ---
            // Check for list-style-type pattern
            // ---

            // Build regex pattern for list-style-type
            $typePattern = "/\blist-style-type\s*:\s*{$escapedValue}\b/i";

            // Check if pattern matches the style
            if (
                \preg_match(
                    $typePattern,
                    $style
                ) === 1
            ) {
                // Return list_style value if pattern matches
                return $listStyleValue;
            }

            // ---
            // Check for list-style pattern (with optional words after value)
            // ---

            // Build regex pattern for list-style
            $stylePattern = "/\blist-style\s*:\s*{$escapedValue}(?:\s+\w+)?(?:\s*;|\s|$)/i";

            // Check if pattern matches the style
            if (
                \preg_match(
                    $stylePattern,
                    $style
                ) === 1
            ) {
                // Return list_style value if pattern matches
                return $listStyleValue;
            }
        }

        // Return null if no list_style attribute found
        return null;
    }

    /**
     * Applies extracted attributes to Bard content nodes.
     *
     * Matches Bard nodes to HTML elements and applies the extracted attributes
     * to the appropriate Bard nodes. Also applies link mark attributes from
     * HTML anchor tags to link marks in text nodes.
     *
     * @param  array<int|string, mixed>  $bardContent  The Bard content array
     * @param  array<int|string, mixed>  $attributeMap  The attribute map from HTML elements
     * @param  array<int|string, mixed>  $linkAttributeMap  The link attribute map from HTML anchor tags
     * @return array<int|string, mixed> The Bard content with attributes applied
     */
    private static function applyAttributes(
        array $bardContent,
        array $attributeMap,
        array $linkAttributeMap = []
    ): array {
        // Process each Bard node
        foreach ($bardContent as &$node) {
            // Skip non-array nodes
            if (! \is_array($node)) {
                continue;
            }

            // Get node type
            $nodeType = $node['type'] ?? null;
            $nodeType = \is_string($nodeType) ? $nodeType : null;

            // Check if node type is applicable
            if ($nodeType === null) {
                // Skip nodes without type
                continue;
            }

            // ---
            // Initialize attrs if not present
            // ---

            // Check if attrs array exists
            if (
                ! isset($node['attrs'])
                || ! \is_array($node['attrs'])
            ) {
                // Initialize empty attrs array
                $node['attrs'] = [];
            }

            // Get node signature for matching
            $signature = self::getBardNodeSignature(
                $node,
                $nodeType
            );

            // Get applicable attributes for this node type
            $applicableAttributes = self::getApplicableAttributes($nodeType);

            // Initialize all applicable attributes to null
            foreach ($applicableAttributes as $attrKey) {
                $node['attrs'][$attrKey] ??= null;
            }

            // Check if attributes exist for this signature
            if (isset($attributeMap[$signature])) {
                // Get attributes for this signature
                $attributes = $attributeMap[$signature];

                // Skip invalid attribute sets
                if (! \is_array($attributes)) {
                    $attributes = [];
                }

                // ---
                // Apply attributes to node
                // ---

                // Loop through attributes to apply to node
                foreach ($attributes as $key => $value) {
                    // Skip non-string attribute keys. Every key is a name this class
                    // sets, so none is skipped.
                    if (! \is_string($key)) {
                        // @pest-mutate-ignore: ContinueToBreak
                        continue;
                    }

                    // Check if attribute is applicable for this node type
                    if (
                        self::isAttributeApplicable(
                            $nodeType,
                            $key
                        )
                    ) {
                        // Set attribute value on node
                        $node['attrs'][$key] = $value;
                    }
                }
            }

            // ---
            // Process link marks in text nodes
            // ---

            // Check if this is a text node with marks array
            if (
                $nodeType === 'text'
                && isset($node['marks'])
                && \is_array($node['marks'])
            ) {
                // Process each mark in the marks array
                foreach ($node['marks'] as &$mark) {
                    // Skip non-array marks
                    if (! \is_array($mark)) {
                        continue;
                    }

                    // Check if this is a link mark
                    if (($mark['type'] ?? null) === 'link') {
                        // ---
                        // Initialize attrs if not present
                        // ---

                        // Check if attrs array is not present
                        if (
                            ! isset($mark['attrs'])
                            || ! \is_array($mark['attrs'])
                        ) {
                            // Initialize empty attrs array
                            $mark['attrs'] = [];
                        }

                        // Get standard link mark attributes
                        $linkAttributes = self::getStandardLinkAttributes();

                        // ---
                        // Initialize all standard link attributes to null if not set
                        // ---

                        // Loop through standard link attributes
                        foreach ($linkAttributes as $attrKey) {
                            $mark['attrs'][$attrKey] ??= null;
                        }

                        // Apply link attributes from HTML anchor tags
                        self::applyLinkAttributes(
                            $mark,
                            $linkAttributeMap,
                            $node
                        );
                    }
                }
            }

            // ---
            // Recursively process child nodes
            // ---

            // Check if node has content array
            if (
                isset($node['content'])
                && \is_array($node['content'])
            ) {
                // ---
                // Recursively apply attributes to child nodes. Skip text nodes and other nodes
                // that don't need attribute processing.
                // ---
                $node['content'] = \array_map(
                    function ($childNode) use ($attributeMap, $linkAttributeMap) {
                        // Return non-array nodes unchanged
                        if (! \is_array($childNode)) {
                            return $childNode;
                        }

                        // ---
                        // Skip text nodes and other non-element nodes
                        // ---

                        // Get child node type
                        $childType = $childNode['type'] ?? null;

                        // Check if child is text or hardBreak node
                        if (
                            $childType === 'text'
                            || $childType === 'hardBreak'
                        ) {
                            // ---
                            // Process link marks in text nodes
                            // ---

                            // Check if child is text node with marks array
                            if (
                                $childType === 'text'
                                && isset($childNode['marks'])
                                && \is_array($childNode['marks'])
                            ) {
                                // Process each mark in the marks array
                                foreach ($childNode['marks'] as &$mark) {
                                    // Skip non-array marks
                                    if (! \is_array($mark)) {
                                        continue;
                                    }

                                    // Check if this is a link mark
                                    if (($mark['type'] ?? null) === 'link') {
                                        // ---
                                        // Initialize attrs if not present
                                        // ---

                                        // Check if attrs array is not present
                                        if (
                                            ! isset($mark['attrs'])
                                            || ! \is_array($mark['attrs'])
                                        ) {
                                            // Initialize empty attrs array
                                            $mark['attrs'] = [];
                                        }

                                        // Get standard link mark attributes
                                        $linkAttributes = self::getStandardLinkAttributes();

                                        // ---
                                        // Initialize all standard link attributes to null if not set
                                        // ---

                                        // Loop through standard link attributes
                                        foreach ($linkAttributes as $attrKey) {
                                            $mark['attrs'][$attrKey] ??= null;
                                        }

                                        // Apply link attributes from HTML anchor tags
                                        self::applyLinkAttributes(
                                            $mark,
                                            $linkAttributeMap,
                                            $childNode
                                        );
                                    }
                                }
                            }

                            // Return text node as-is without further processing
                            return $childNode;
                        }

                        // Process non-text nodes recursively
                        return self::applyAttributes(
                            [$childNode],
                            $attributeMap,
                            $linkAttributeMap
                        )[0];
                    },
                    $node['content']
                );
            }
        }

        unset($node);

        // Return Bard content with attributes applied
        return $bardContent;
    }

    /**
     * Gets a signature for a Bard node to use for matching.
     *
     * Creates a signature based on node type, level (for headings), and text
     * content to match Bard nodes to HTML elements.
     *
     * @param  array<int|string, mixed>  $node  The Bard node
     * @param  string  $nodeType  The node's type
     * @return string The node signature
     */
    private static function getBardNodeSignature(
        array $node,
        string $nodeType
    ): string {
        // Map Bard node types to HTML tag names. A heading needs no entry: its level
        // makes its signature start with `h`, as an HTML heading's does, and one
        // without a level matches no element either way.
        $tagMap = [
            'paragraph' => 'p',
            'bulletList' => 'ul',
            'orderedList' => 'ol',
            'codeBlock' => 'pre',
            'horizontalRule' => 'hr',
            'listItem' => 'li',
        ];

        // Get tag name
        $tagName = \is_string($tagMap[$nodeType] ?? null)
            ? $tagMap[$nodeType]
            : $nodeType;

        // ---
        // Get level for headings
        // ---

        // Initialize level variable
        $level = '';

        // Check if node is a heading
        if ($nodeType === 'heading') {
            // Read heading attributes from the node
            $nodeAttributes = $node['attrs'] ?? [];

            // Extract heading level from node attributes
            if (
                \is_array($nodeAttributes)
                && isset($nodeAttributes['level'])
            ) {
                $levelValue = $nodeAttributes['level'];

                // A level that is not a scalar matches no heading. Only a `<heading>`
                // element could share the fallback's signature, and it never carries
                // attributes, so the fallback's value changes nothing.
                // @pest-mutate-ignore: EmptyStringToNotEmpty
                $level = \is_scalar($levelValue) ? (string) $levelValue : '';
            }
        }

        // Get text content
        $textContent = self::extractBardNodeText($node);

        // Build signature using shared helper method
        return self::buildSignature(
            $tagName,
            $level,
            $textContent
        );
    }

    /**
     * Extracts text content from a Bard node.
     *
     * Recursively extracts all text content from a Bard node and its children.
     *
     * @param  array<int|string, mixed>  $node  The Bard node
     * @return string The extracted text content
     */
    private static function extractBardNodeText(
        array $node
    ): string {
        // Start from the node's own text when it is a string
        $nodeText = $node['text'] ?? null;
        $text = \is_string($nodeText) ? $nodeText : '';

        // Check if node has content
        if (
            isset($node['content'])
            && \is_array($node['content'])
        ) {
            // Loop through child nodes
            foreach ($node['content'] as $child) {
                // Recursively extract text from child nodes
                if (\is_array($child)) {
                    $text .= self::extractBardNodeText($child);
                }
            }
        }

        // Return extracted text content
        return $text;
    }

    /**
     * Gets applicable attributes for a node type.
     *
     * Returns an array of attribute keys that can be applied to the given node
     * type based on Bard Texstyle configuration. Dynamically reads from the
     * config file to avoid hard-coding attribute definitions.
     *
     * @param  string  $nodeType  The Bard node type (camelCase, e.g., 'orderedList')
     * @return array<string> Array of applicable attribute keys
     */
    private static function getApplicableAttributes(
        string $nodeType
    ): array {
        // Convert Bard node type (camelCase) to config key (snake_case). Bard's
        // `bulletList` is the one type Bard Texstyle configures under another name.
        $configKey = $nodeType === 'bulletList'
            ? 'unordered_list'
            : Str::snake($nodeType);

        // Get attributes from config file
        $attributes = \config('statamic.bard_texstyle.attributes', []);

        // Return empty list when config value is not an array
        if (! \is_array($attributes)) {
            return [];
        }

        // Check if attributes exist for this config key
        if (! isset($attributes[$configKey])) {
            // Return empty array if no attributes defined
            return [];
        }

        // Get attribute keys for this node type
        $nodeAttributes = $attributes[$configKey];

        // Return empty list when node attributes are not an array
        if (! \is_array($nodeAttributes)) {
            return [];
        }

        // Get attribute keys for this node type
        $attributeKeys = \array_keys($nodeAttributes);

        // Keep only string keys expected by downstream methods
        $stringAttributeKeys = \array_filter(
            $attributeKeys,
            \is_string(...)
        );

        // Return attribute keys
        return $stringAttributeKeys;
    }

    /**
     * Checks if an attribute is applicable for a node type.
     *
     * Validates that the attribute can be applied to the given node type based
     * on Bard Texstyle configuration.
     *
     * @param  string  $nodeType  The Bard node type
     * @param  string  $attributeKey  The attribute key
     * @return bool True if attribute is applicable, false otherwise
     */
    private static function isAttributeApplicable(
        string $nodeType,
        string $attributeKey
    ): bool {
        // Get applicable attributes for node type
        $applicableAttributes = self::getApplicableAttributes($nodeType);

        // Check if attribute is applicable for this node type. Both sides are
        // this class's fixed attribute names, none numeric, so strict and loose
        // comparison agree.
        // @pest-mutate-ignore: TrueToFalse
        return \in_array(
            $attributeKey,
            $applicableAttributes,
            true
        );
    }

    /**
     * Applies link attributes from HTML anchor tags to a link mark.
     *
     * Matches the link mark to an HTML anchor tag using href and text content,
     * then applies the target and title attributes if found.
     *
     * @param  array<int|string, mixed>  &$mark  Reference to the link mark array
     * @param  array<int|string, mixed>  $linkAttributeMap  The link attribute map from HTML anchor tags
     * @param  array<int|string, mixed>  $textNode  The text node containing the link mark
     */
    private static function applyLinkAttributes(
        array &$mark,
        array $linkAttributeMap,
        array $textNode
    ): void {
        // Read mark attrs
        $markAttributes = $mark['attrs'] ?? [];

        // Stop when mark attrs are not an array. Both callers have made them one,
        // so this never returns.
        if (! \is_array($markAttributes)) {
            // @pest-mutate-ignore: RemoveEarlyReturn
            return;
        }

        // Get href from link mark
        $href = $markAttributes['href'] ?? null;

        // Stop when href is absent or not a string; a link cannot match without one
        if (! \is_string($href)) {
            return;
        }

        // Get text content from text node
        $textContent = $textNode['text'] ?? '';
        $textContent = \is_string($textContent) ? $textContent : '';

        // Generate link signature for matching
        $linkSignature = self::getLinkSignatureFromMark(
            $href,
            $textContent
        );

        // Check if link attributes exist for this signature
        if (isset($linkAttributeMap[$linkSignature])) {
            // Get attributes for this link
            $attributes = $linkAttributeMap[$linkSignature];

            // Stop when link attributes are not an array
            if (! \is_array($attributes)) {
                return;
            }

            // ---
            // Apply target attribute if present
            // ---

            // Check if target attribute is present
            if (isset($attributes['target'])) {
                // Set target attribute when value is a string
                if (\is_string($attributes['target'])) {
                    $markAttributes['target'] = $attributes['target'];
                }
            }

            // ---
            // Apply title attribute if present
            // ---

            // Check if title attribute is present
            if (isset($attributes['title'])) {
                // Set title attribute when value is a string
                if (\is_string($attributes['title'])) {
                    $markAttributes['title'] = $attributes['title'];
                }
            }

            // ---
            // Apply rel attribute if present
            // ---

            // Check if rel attribute is present
            if (isset($attributes['rel'])) {
                // Set rel attribute when value is a string
                if (\is_string($attributes['rel'])) {
                    $markAttributes['rel'] = $attributes['rel'];
                }
            }
        }

        // Persist updated mark attrs
        $mark['attrs'] = $markAttributes;
    }

    /**
     * Gets a link signature from href and text content.
     *
     * Creates a signature for matching link marks to HTML anchor tags using
     * the same algorithm as getLinkSignature.
     *
     * @param  string  $href  The href attribute value
     * @param  string  $textContent  The text content
     * @return string The link signature
     */
    private static function getLinkSignatureFromMark(
        string $href,
        string $textContent
    ): string {
        // Normalize text content whitespace
        $normalizedText = self::normalizeWhitespace($textContent);

        // Generate hash from href and text content
        $signatureHash = \substr(
            \md5("{$href}|{$normalizedText}"),
            0,
            8
        );

        // Build signature: link:{hash}
        return "link:{$signatureHash}";
    }

    /**
     * Gets the attributes Statamic's LinkMark defines for a link mark.
     *
     * @return array<string> Array of standard link mark attribute keys
     */
    private static function getStandardLinkAttributes(): array
    {
        return self::$standardLinkAttributes;
    }
}
