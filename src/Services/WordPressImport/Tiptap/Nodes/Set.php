<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Tiptap\Nodes;

use DOMAttr;
use DOMElement;
use Statamic\Fieldtypes\RowId;
use Tiptap\Core\Node;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Tiptap\Nodes\Sets\GenericSetRenderer;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Tiptap\Nodes\Sets\PullQuote;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Tiptap\Nodes\Sets\Shortcode;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Tiptap\Nodes\Sets\Table;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Tiptap\SetMarkupNonce;

/**
 * Tiptap node for parsing Statamic Bard sets from HTML elements.
 */
class Set extends Node
{
    /** @var mixed */
    public static $name = 'set';

    /** @var mixed */
    public static $priority = 200;

    /** @var array<int, array<int|string, mixed>>|null */
    private static ?array $cachedConfigs = null;

    /**
     * Defines HTML parsing rules for set nodes.
     *
     * Returns cached configurations if available, otherwise builds parsing
     * rules for div[data-set-type] elements and shortcodes.
     *
     * @return array<int, array<int|string, mixed>> Array of parsing configurations
     */
    public function parseHTML(): array
    {
        if (self::$cachedConfigs !== null) {
            return self::$cachedConfigs;
        }

        $shortcode = new Shortcode;

        $configs = \array_merge(
            [
                [
                    // ---
                    // Match on the nonce, not on `data-set-type`.
                    //
                    // Selecting the nonce means a `div[data-set-type]` typed into
                    // WordPress post content never matches this rule at all, so
                    // Tiptap treats it as an ordinary div and its children parse
                    // as normal content. Rejecting inside `getAttrs` instead would
                    // still mint a `set` node, leaving an attribute-less set in the
                    // entry with the author's text stranded inside it.
                    // ---
                    'tag' => 'div['.SetMarkupNonce::ATTRIBUTE.']',
                    'getAttrs' => function (DOMElement $DOMElement): ?array {
                        // ---
                        // Only trust set markup this importer generated.
                        //
                        // `data-set-*` is our own serialization, so a div carrying
                        // it inside WordPress post content was typed by an author,
                        // not emitted by us — parsing it would copy attacker HTML
                        // into an entry field the host app renders unescaped (wordpress-importer-archive#494).
                        // ---

                        // Reject any set markup without the current run's nonce
                        if (
                            ! SetMarkupNonce::matches(
                                $DOMElement->getAttribute(SetMarkupNonce::ATTRIBUTE)
                            )
                        ) {
                            return null;
                        }

                        $setType = $DOMElement->getAttribute('data-set-type');
                        if (
                            ! \in_array(
                                $setType,
                                [
                                    'file',
                                    'people',
                                    'pull_quote',
                                    'table',
                                    'image',
                                    // The three platform-specific video sets that
                                    // replaced the retired `video` set (wordpress-importer-archive#298)
                                    'youtube',
                                    'vimeo',
                                    'box',
                                    'form',
                                    'gallery',
                                    'reusable_content',
                                ],
                                true
                            )
                        ) {
                            return null;
                        }

                        $values = ['type' => $setType];

                        // Define boolean and integer fields per set type
                        $booleanFields = [];
                        $integerFields = [];

                        if ($setType === 'people') {
                            $booleanFields = [
                                'multiple_groups_selection',
                                'entry_link_query',
                                'pagination',
                                'entry_link_target',
                                'image_effect',
                            ];
                            $integerFields = [
                                'limit',
                            ];
                        } elseif ($setType === 'table') {
                            // Get boolean fields from data-set-boolean-fields attribute
                            $booleanFieldsAttr = $DOMElement->getAttribute('data-set-boolean-fields');
                            $booleanFields = $booleanFieldsAttr !== '' ? \explode(
                                ',',
                                $booleanFieldsAttr
                            ) : [
                                'first_row_headers',
                                'first_column_headers',
                                'last_row_footers',
                            ];

                            // ---
                            // Add heading_query to boolean fields. They use TrueFalse fieldtype and may not
                            // be in data-set-boolean-fields.
                            // ---

                            // Check if heading_query is not already in the boolean fields array
                            if (
                                ! \in_array(
                                    'heading_query',
                                    $booleanFields,
                                    true
                                )
                            ) {
                                // Add heading_query to boolean fields for proper type conversion
                                $booleanFields[] = 'heading_query';
                            }

                            // ---
                            // Add description_query to boolean fields. They use TrueFalse fieldtype and may
                            // not be in data-set-boolean-fields.
                            // ---

                            // Check if description_query is not already in the boolean fields array
                            if (
                                ! \in_array(
                                    'description_query',
                                    $booleanFields,
                                    true
                                )
                            ) {
                                // Add description_query to boolean fields for proper type conversion
                                $booleanFields[] = 'description_query';
                            }
                        } elseif ($setType === 'form') { // Check if this is a form set
                            // ---
                            // Form sets use TrueFalse fieldtype for heading_query and description_query
                            // ---
                            // Initialize boolean fields array for form sets
                            $booleanFields = [
                                'heading_query',
                                'description_query',
                            ];
                        } elseif ($setType === 'gallery') { // Check if this is a gallery set
                            // ---
                            // Gallery sets use the TrueFalse fieldtype for more_query and
                            // more_button_target, so coerce them back from their serialized strings.
                            // ---
                            // Initialize boolean fields array for gallery sets
                            $booleanFields = [
                                'more_query',
                                'more_button_target',
                            ];
                        }

                        /** @var DOMAttr $attr */
                        foreach ($DOMElement->attributes as $attr) {
                            if (
                                \preg_match(
                                    '/^data-set-attr-(.+)$/',
                                    $attr->name,
                                    $matches
                                ) === 1
                            ) {
                                $key = $matches[1];
                                $value = $attr->value;

                                // Check if this is a field that needs JSON decoding for specific set types
                                if (
                                    \in_array(
                                        $key,
                                        [
                                            'quote',
                                            'table_data',
                                            'display_detail',
                                            'group_multiple',
                                        ],
                                        true
                                    )
                                    && \in_array(
                                        $setType,
                                        [
                                            'pull_quote',
                                            'table',
                                            'people',
                                        ],
                                        true
                                    )
                                ) {
                                    // Decode the JSON attribute value
                                    $decoded = self::decodeJsonAttr($value);

                                    // Check if the display_detail is truncated/invalid for people
                                    if (
                                        $key === 'display_detail'
                                        && $setType === 'people'
                                        && $decoded === null
                                        && \str_starts_with(
                                            \trim($value),
                                            '['
                                        )
                                    ) {
                                        // Use default display_detail for people when JSON is truncated/invalid
                                        $values[$key] = [
                                            'name',
                                            'photo',
                                            'position',
                                        ];
                                    } else {
                                        // Use decoded value or fallback to original string
                                        $values[$key] = $decoded ?? $value;
                                    }
                                } elseif (
                                    $key === 'images'
                                    && $setType === 'gallery'
                                ) { // Check if this is the images field for gallery sets
                                    // Decode the JSON attribute value
                                    $decoded = self::decodeJsonAttr($value);

                                    // Use decoded value or fallback to original string
                                    $values[$key] = $decoded ?? $value;
                                } elseif (
                                    $key === 'description'
                                    && $setType === 'gallery'
                                ) { // Check if this is the description field for gallery sets
                                    // Decode the JSON attribute value
                                    $decoded = self::decodeJsonAttr($value);

                                    // Use decoded value or fallback to original string
                                    $values[$key] = $decoded ?? $value;
                                } elseif (
                                    \in_array(
                                        $key,
                                        $booleanFields,
                                        true
                                    )
                                ) { // Check if this is a boolean field that needs conversion
                                    // Serializers emit booleans via htmlspecialchars(), so a true
                                    // becomes '1' and a false becomes '' (or the literal 'false'/'true');
                                    // coerce every truthy/falsy spelling back to a real boolean.
                                    // The attribute always arrives as a string, so only the string
                                    // spellings are listed — a strict in_array() can never match an
                                    // int or bool needle here.
                                    $values[$key] = \in_array(
                                        $value,
                                        ['true', '1'],
                                        true
                                    )
                                        ? true
                                        : (
                                            \in_array(
                                                $value,
                                                ['false', '0', ''],
                                                true
                                            )
                                                ? false
                                                : $value
                                        );
                                } elseif ( // Check if this is an integer field that needs conversion
                                    \in_array(
                                        $key,
                                        $integerFields,
                                        true
                                    )
                                ) {
                                    $values[$key] = \is_numeric($value) ? (int) $value : $value;
                                } else { // Otherwise
                                    $values[$key] = $value; // Keep as string unless explicitly cast
                                }
                            } elseif (
                                \preg_match(
                                    '/^data-set-integer-(.+)$/',
                                    $attr->name,
                                    $matches
                                ) === 1
                            ) { // Check if this is an integer attribute
                                $key = $matches[1];
                                $value = $attr->value;
                                $values[$key] = \is_numeric($value) ? (int) $value : $value;
                            }
                        }

                        return [
                            'id' => (new RowId)->generate(),
                            'values' => $values,
                        ];
                    },
                ],
            ],
            $shortcode->getShortcodeConfig()
        );

        self::$cachedConfigs = self::normalizeParseConfigs($configs);

        return self::$cachedConfigs;
    }

    /**
     * Normalize parseHTML configuration entries to typed arrays.
     *
     * @param  list<mixed>  $configs  Raw parse configuration list
     * @return array<int, array<int|string, mixed>> Normalized configuration list
     */
    private static function normalizeParseConfigs(
        array $configs
    ): array {
        $normalized = [];

        foreach ($configs as $config) {
            if (\is_array($config)) {
                $normalized[] = $config;
            }
        }

        return $normalized;
    }

    /**
     * Decode a JSON or base64-encoded-JSON attribute value to an array.
     *
     * Tries json_decode first (backward compatibility). If that fails, tries
     * base64_decode then json_decode so attributes encoded by
     * ShortcodeProcessor::generateTiptapHtml are parsed correctly and long
     * arrays (e.g., display_detail) are not truncated by the HTML/DOM layer.
     *
     * @param  string  $value  Raw attribute value (JSON string or base64 of JSON)
     * @return array<int|string, mixed>|null Decoded array or null if decoding failed
     */
    private static function decodeJsonAttr(
        string $value
    ): ?array {
        // Decode the value as JSON
        $decoded = \json_decode($value, true);

        // Check if the decoded value is an array
        if (
            $decoded !== null
            && \is_array($decoded)
        ) {
            // Return the decoded value if it is an array
            return $decoded;
        }

        // Check if the value is empty
        if ($value === '') {
            // Return null if the value is empty
            return null;
        }

        // Decode the base64-encoded value
        $raw = \base64_decode($value, true);

        // Check if the decoded value is false
        if ($raw === false) {
            // Return null if the decoded value is false
            return null;
        }

        // Decode the base64-encoded value as JSON
        $decoded = \json_decode($raw, true);

        // Check if the decoded value is an array
        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * Renders a set node to HTML for Tiptap processing.
     *
     * Delegates rendering to appropriate renderer based on set type.
     *
     * @param  mixed  $node  The Tiptap node object containing set data
     * @param  array<int|string, mixed>  $HTMLAttributes  Additional HTML attributes to merge
     * @return array<int|string, mixed> HTML rendering configuration
     */
    public function renderHTML(
        $node,
        array $HTMLAttributes = []
    ): array {
        if (! \is_object($node) || ! isset($node->attrs) || ! \is_object($node->attrs)) {
            return ['tag' => '<div>'];
        }

        $values = $node->attrs->values ?? null;
        $typeRaw = \is_object($values) ? ($values->type ?? '') : '';
        $type = \is_string($typeRaw) ? $typeRaw : '';

        return match ($type) {
            'people', 'file', 'youtube', 'vimeo', 'box', 'image', 'form', 'gallery', 'reusable_content' => new GenericSetRenderer($type)->renderHTML($node),
            'pull_quote' => (new PullQuote)->renderHTML($node),
            'table' => (new Table)->renderHTML($node),
            default => ['tag' => '<div>'],
        };
    }

    /**
     * Converts a set node to ProseMirror JSON format.
     *
     * @param  object  $node  The node being converted
     * @return array<int|string, mixed> The ProseMirror JSON representation of the node
     */
    public function toProseMirror(
        object $node
    ): array {
        if (
            isset($node->attrs)
            && \is_object($node->attrs)
            && isset($node->attrs->values)
        ) {
            $attrsId = $node->attrs->id ?? null;
            $id = \is_string($attrsId) || \is_numeric($attrsId)
                ? (string) $attrsId
                : '';

            return [
                'type' => 'set',
                'attrs' => [
                    'id' => $id,
                    'values' => (array) $node->attrs->values,
                ],
            ];
        }

        return [
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'text' => '[unknown set]'],
            ],
        ];
    }
}
