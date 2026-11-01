<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\Blocks;

use Illuminate\Support\Facades\Log;
use Statamic\Fieldtypes\RowId;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\AcfFieldNormalizer;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\HtmlTagStripper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\TextProcessor;

/**
 * Parses WordPress ACF counter-list blocks into Statamic page_builder replicator
 * sets.
 *
 * Converts the ACF counter-list block attributes (e.g., counter_list_heading,
 * counter_list_start, counter_list_section) into a Statamic uamsweb_block_counter
 * replicator set for the landing_page blueprint's page_builder field. Handles
 * textarea fields as plain text, processes the counter_list_section repeater for
 * items, and manages date and time fields. Uses AcfFieldNormalizer for consistent
 * field mapping across different WordPress ACF data structures.
 */
class CounterListParser
{
    /**
     * Reads a scalar field from an array and normalizes it to a string.
     *
     * @param  array<int|string, mixed>  $data  The source data array
     * @param  string  $key  The array key to read
     * @param  string  $default  The fallback value when the key is missing or invalid
     * @return string The normalized string value
     */
    private function readStringField(
        array $data,
        string $key,
        string $default = ''
    ): string {
        // Read the candidate value from the source data.
        $value = $data[$key] ?? $default;

        // Return the value when it is already a string.
        if (is_string($value)) {
            return $value;
        }

        // Return numeric scalars as their string representation.
        if (is_numeric($value)) {
            return (string) $value;
        }

        // Fall back to the default for all unsupported value types.
        return $default;
    }

    /**
     * Defines the mapping between field-based ACF field names and standard field
     * names.
     *
     * This method provides the field mappings needed by AcfFieldNormalizer to handle
     * the conversion of WordPress ACF field names like field_counter_list_heading_b
     * to standard names like counter_list_heading.
     *
     * @return array<int|string, mixed> The field mappings for counter list blocks
     */
    private function getFieldMappings(): array
    {
        return [
            'field_counter_list_heading_b' => 'counter_list_heading',
            'field_counter_list_hide_heading_b' => 'counter_list_hide_heading',
            'field_counter_list_description_b' => 'counter_list_description',
            'field_counter_list_start_b' => 'counter_list_start',
            'field_counter_list_start_custom_b' => 'counter_list_start_custom',
            'field_counter_list_background_color_b' => 'counter_list_background_color',
            'field_counter_list_section_b' => 'counter_list_section',
        ];
    }

    /**
     * Defines which nested structures should be processed for counter list blocks.
     *
     * This method provides the nested structure mappings needed by AcfFieldNormalizer
     * to handle complex ACF block structures.
     *
     * @return array<int|string, mixed> The nested structure mappings for counter list blocks
     */
    private function getNestedStructures(): array
    {
        return [
            // No nested structures for counter list blocks
        ];
    }

    /**
     * Processes the body textarea content as plain text.
     *
     * The counter_list_description field in WordPress is a textarea, not WYSIWYG, so
     * it's processed as plain text without shortcode or Bard parsing. Uses
     * TextProcessor to decode HTML entities for consistent special character handling.
     *
     * @param  string  $bodyContent  The raw textarea content
     * @return string The processed plain text content
     */
    private function processBody(
        string $bodyContent
    ): string {
        // Decode HTML entities and trim whitespace using TextProcessor
        return \trim(TextProcessor::decodeEntities($bodyContent));
    }

    /**
     * Normalizes the counter_list_section repeater data from indexed keys to an array
     * of sections.
     *
     * Transforms the flat key-value array
     * (e.g., counter_list_section_0_counter_list_section_title) into an array of
     * section arrays, grouping fields by their index.
     *
     * @param  array<int|string, mixed>  $data  The raw attributes array containing counter_list_section fields
     * @return array<int|string, mixed> Array of section arrays, each containing fields for one item
     */
    private function normalizeSections(
        array $data
    ): array {
        $sections = [];
        // Read and validate the section count before iterating. A count of -1
        // runs the loop below as many times as 0 does.
        // @pest-mutate-ignore: DecrementInteger
        $sectionCountValue = $data['counter_list_section'] ?? 0;
        // @pest-mutate-ignore: DecrementInteger
        $sectionCount = is_numeric($sectionCountValue) ? (int) $sectionCountValue : 0;

        for ($i = 0; $i < $sectionCount; $i++) {
            // Build one normalized section payload from indexed fields.
            $section = [
                'counter_list_section_title' => $this->readStringField(
                    $data,
                    "counter_list_section_{$i}_counter_list_section_title"
                ),
                'counter_list_section_unit' => $this->readStringField(
                    $data,
                    "counter_list_section_{$i}_counter_list_section_unit"
                ),
                'counter_list_section_units' => $this->readStringField(
                    $data,
                    "counter_list_section_{$i}_counter_list_section_units"
                ),
                'counter_list_section_rate' => $this->readStringField(
                    $data,
                    "counter_list_section_{$i}_counter_list_section_rate"
                ),
                'counter_list_section_start_inherit' => $this->readStringField(
                    $data,
                    "counter_list_section_{$i}_counter_list_section_start_inherit",
                    '1'
                ),
                'counter_list_section_start_override' => $this->readStringField(
                    $data,
                    "counter_list_section_{$i}_counter_list_section_start_override"
                ),
                'counter_list_section_start_custom' => $this->readStringField(
                    $data,
                    "counter_list_section_{$i}_counter_list_section_start_custom"
                ),
            ];

            // Append the normalized section payload to the collection.
            $sections[] = $section;
        }

        return $sections;
    }

    /**
     * Processes the counter_list_section repeater from WordPress to Statamic format.
     *
     * Converts the counter_list_section repeater into a Statamic items replicator,
     * mapping title, unit, units, rate, and start fields. Validates required fields
     * and handles conditional date/time overrides.
     *
     * @param  array<int|string, mixed>  $sections  The counter_list_section repeater data
     * @param  string  $site  The Statamic site handle
     * @return array<int|string, mixed> The processed items replicator sets
     */
    private function processItems(
        array $sections,
        string $site
    ): array {
        $result = [];
        foreach ($sections as $section) {
            // Skip malformed section rows before reading keyed values.
            if (! is_array($section)) {
                continue;
            }

            // Map fields
            $heading = HtmlTagStripper::strip(
                $this->readStringField(
                    $section,
                    'counter_list_section_title'
                )
            );
            $unit = $this->readStringField(
                $section,
                'counter_list_section_unit'
            );
            $units = $this->readStringField(
                $section,
                'counter_list_section_units'
            );
            $rateValue = $section['counter_list_section_rate'] ?? null;
            $rate = is_numeric($rateValue) ? (float) $rateValue : null;
            $startInherit = $this->readStringField(
                $section,
                'counter_list_section_start_inherit',
                '1'
            ) === '1';
            $startOverride = $this->readStringField(
                $section,
                'counter_list_section_start_override'
            );
            $startCustom = $this->readStringField(
                $section,
                'counter_list_section_start_custom'
            );

            // Validate required fields
            if ($heading === '' || $unit === '' || $units === '' || $rate === null) {
                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'site' => $site,
                        'heading' => $heading,
                        'has_unit' => $unit !== '',
                        'has_units' => $units !== '',
                        'has_rate' => $rate !== null,
                    ]
                );

                // Log warning about invalid item
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        'CounterListParser::processItems: Skipping invalid counter_list_section item',
                        $logData['page_url']
                    ),
                    $logData['context']
                );

                continue;
            }

            // Validate conditional start fields
            if ($startInherit === false && $startOverride === '') {
                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'site' => $site,
                        'heading' => $heading,
                    ]
                );

                // Log warning about missing start_override
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        'CounterListParser::processItems: Skipping invalid counter_list_section item due to missing start_override',
                        $logData['page_url']
                    ),
                    $logData['context']
                );

                continue;
            }

            if ($startOverride === 'custom' && $startCustom === '') {
                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'site' => $site,
                        'heading' => $heading,
                    ]
                );

                // Log warning about missing start_custom
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        'CounterListParser::processItems: Skipping invalid counter_list_section item due to missing start_custom',
                        $logData['page_url']
                    ),
                    $logData['context']
                );

                continue;
            }

            $item = [
                'id' => (new RowId)->generate(), // Unique ID for replicator set
                'type' => 'new_set', // Statamic replicator set type
                'heading' => $heading, // Required text
                'unit' => $unit, // Required text, 90-char limit
                'units' => $units, // Required text, 90-char limit
                'rate' => $rate, // Required float
                'start_inherit' => $startInherit, // Required TrueFalse
                'enabled' => true, // Standard for replicator sets
            ];

            // Include conditional fields if applicable
            if ($startInherit === false) {
                $item['start_override'] = $startOverride; // Required if start_inherit=false
                if ($startOverride === 'custom') {
                    $item['start_override_custom'] = $startCustom; // Required if start_override=custom
                }
            }

            $result[] = $item;
        }

        return $result;
    }

    /**
     * Parses a WordPress ACF counter-list block into a Statamic page_builder
     * replicator set.
     *
     * Maps block attributes to a uamsweb_block_counter set, processing the
     * counter_list_section repeater for items. Handles text fields as plain text,
     * manages date and time fields, and omits empty optional fields. Uses
     * AcfFieldNormalizer for consistent field mapping across different WordPress
     * ACF data structures.
     *
     * @param  array<int|string, mixed>  $attributes  The block attributes from the wp:acf/counter-list comment
     * @param  string  $site  The Statamic site handle (e.g., 'default') for asset paths
     * @param  AssetImporter  $assetImporter  Instance for importing images (unused in this block)
     * @param  OutputInterface  $output  Console output for logging progress and errors
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects from XML (unused in this block)
     * @return array<int|string, mixed> The Statamic uamsweb_block_counter replicator set
     */
    public function parse(
        array $attributes,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = []
    ): array {
        // Handle attributes with or without 'data' key
        $data = $attributes['data'] ?? $attributes;
        if (! is_array($data)) {
            $data = $attributes;
        }

        // Normalize field names using AcfFieldNormalizer
        $normalizedData = AcfFieldNormalizer::normalize(
            $data,
            $this->getFieldMappings(),
            $this->getNestedStructures()
        );

        // Map basic fields using normalized data
        $heading = HtmlTagStripper::strip(
            $this->readStringField(
                $normalizedData,
                'counter_list_heading'
            )
        );
        $hideHeading = $this->readStringField(
            $normalizedData,
            'counter_list_hide_heading',
            '0'
        ) === '1';
        $descriptionValue = $normalizedData['counter_list_description'] ?? '';
        $description = is_string($descriptionValue)
            ? $this->processBody($descriptionValue)
            : '';
        $start = $this->readStringField(
            $normalizedData,
            'counter_list_start',
            'day'
        );
        $startCustom = $this->readStringField(
            $normalizedData,
            'counter_list_start_custom'
        );
        $backgroundColor = $this->readStringField(
            $normalizedData,
            'counter_list_background_color',
            'bg-auto'
        );

        // Validate required heading and start fields
        if ($heading === '') {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                ['site' => $site]
            );

            // Log warning about missing heading
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'CounterListParser::parse: Skipping wp:acf/counter-list block due to missing required heading',
                    $logData['page_url']
                ),
                $logData['context']
            );

            return [];
        }

        if ($start === 'custom' && $startCustom === '') {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'start' => $start,
                ]
            );

            // Log warning about missing start_custom
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'CounterListParser::parse: Skipping wp:acf/counter-list block due to missing start_custom',
                    $logData['page_url']
                ),
                $logData['context']
            );

            return [];
        }

        // Normalize and process items repeater
        $items = [];
        $sections = $this->normalizeSections($normalizedData);
        if ($sections !== []) {
            $items = $this->processItems(
                $sections,
                $site
            );
        }

        // Skip block if no valid items (required field)
        if ($items === []) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                ['site' => $site]
            );

            // Log warning about no valid items
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'CounterListParser::parse: No valid items for wp:acf/counter-list block; skipping',
                    $logData['page_url']
                ),
                $logData['context']
            );

            return [];
        }

        // Build the replicator set
        $result = [
            'id' => (new RowId)->generate(), // Unique ID for replicator set
            'type' => 'uamsweb_block_counter', // Statamic fieldset handle
            'block_heading' => $heading, // Required text
            'hide_block_heading' => $hideHeading, // Hidden field, mapped or default
            'start' => $start, // Required radio
            'background_color' => $backgroundColor, // Required select
            'items' => $items, // Required replicator
            'enabled' => true, // Standard for replicator sets
        ];

        // Include optional fields if non-empty
        if ($description !== '') {
            $result['block_description'] = $description; // Optional textarea
        }

        if ($start === 'custom') {
            $result['start_custom'] = $startCustom; // Required if start=custom
        }

        return $result;
    }
}
