<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks;

use Illuminate\Support\Facades\Log;
use SimpleXMLElement;
use Statamic\Fieldtypes\RowId;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi\MaterializationScope;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\AcfFieldNormalizer;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\HtmlTagStripper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;

/**
 * Parses WordPress ACF Find-a-Doc Locations blocks into Statamic page_builder
 * replicator sets.
 *
 *   - Handles the `wp:acf/fad-locations` block, mapping its attributes to the
 *     Statamic `uamsweb_block_fad_locations` fieldset. Validates required fields,
 *     tracks `location_type` and `region` taxonomy terms for validation, and
 *     resolves `expertise` and `locations` post IDs to the format
 *     `<wp:post_name>-<wp:post_id>` using the provided XML items.
 *   - Normalizes ACF field names using `AcfFieldNormalizer` utility for
 *     consistent field mapping across different WordPress ACF data structures.
 */
class FindADocLocationsParser
{
    /**
     * Stores term IDs used in the block for taxonomy validation.
     *
     * @var array<string, array<string, array{slug: string, link: string}>>
     */
    private static array $termsUsed = [];

    /**
     * Defines the mapping between field-based ACF field names and standard field
     * names.
     *
     * @return array<string, string> The field mappings
     */
    private function getFieldMappings(): array
    {
        return [
            'field_block_fad_locations_heading' => 'block_fad_locations_heading',
            'field_block_fad_locations_description' => 'block_fad_locations_description',
            'field_block_fad_locations_background_color' => 'block_fad_locations_background_color',
            'field_block_fad_locations_filter_type' => 'block_fad_locations_filter_type',
            'field_block_fad_locations_filter_aoe' => 'block_fad_locations_filter_aoe',
            'field_block_fad_locations_filter_region' => 'block_fad_locations_filter_region',
            'field_block_fad_locations_filter_ids' => 'block_fad_locations_filter_ids',
            'field_block_fad_locations_more' => 'block_fad_locations_more',
            'field_block_fad_locations_more_text' => 'block_fad_locations_more_text',
            'field_block_fad_locations_more_button_text' => 'block_fad_locations_more_button_text',
            'field_block_fad_locations_more_button_url' => 'block_fad_locations_more_button_url',
            'field_block_fad_locations_more_button_description' => 'block_fad_locations_more_button_description',
        ];
    }

    /**
     * Defines which nested structures should be processed.
     *
     * @return array<string> The nested structure keys
     */
    private function getNestedStructures(): array
    {
        return [];
    }

    /**
     * Parses a Find-a-Doc Locations block into a Statamic replicator set.
     *
     *   - Maps WordPress ACF fields to Statamic fields, validates required fields,
     *     and resolves post IDs for `expertise` and `locations`. Tracks
     *     `location_type` and `region` terms for validation. Returns a replicator set
     *     for the `uamsweb_block_fad_locations` fieldset or an empty array if
     *     validation fails.
     *   - Uses AcfFieldNormalizer for consistent field mapping across different
     *     WordPress ACF data structures.
     *
     * @param  array<int|string, mixed>  $attributes  ACF block attributes from the XML comment (e.g., `block_fad_locations_heading`)
     * @param  string  $site  Statamic site handle (e.g., 'student_information_system')
     * @param  AssetImporter  $assetImporter  Instance for importing linked files
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects from the WordPress XML
     * @param  array<int|string, mixed>  $items  Array of SimpleXMLElement item objects from XML for post ID mapping
     * @param  ?string  $pageSlug  Page slug for logging and term tracking (optional)
     * @return array<int|string, mixed> Replicator set for `page_builder` or empty array if validation fails
     */
    public function parse(
        array $attributes,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $terms = [],
        array $items = [],
        ?string $pageSlug = null
    ): array {
        // Handle attributes with or without 'data' key
        $data = $attributes['data'] ?? $attributes;
        if (! \is_array($data)) {
            $data = [];
        }

        // Normalize field names using AcfFieldNormalizer
        $normalizedData = AcfFieldNormalizer::normalize(
            $data,
            $this->getFieldMappings(),
            $this->getNestedStructures()
        );

        // Map basic fields using normalized keys
        $heading = HtmlTagStripper::strip(
            $this->getStringFieldValue(
                $normalizedData,
                'block_fad_locations_heading'
            )
        );
        $description = HtmlTagStripper::strip(
            $this->getStringFieldValue(
                $normalizedData,
                'block_fad_locations_description'
            )
        );
        $backgroundColor = $this->getStringFieldValue(
            $normalizedData,
            'block_fad_locations_background_color',
            'bg-white'
        );
        $filterType = $this->getArrayFieldValue(
            $normalizedData,
            'block_fad_locations_filter_type'
        );
        $filterAoe = $this->getArrayFieldValue(
            $normalizedData,
            'block_fad_locations_filter_aoe'
        );
        $filterRegion = $this->getArrayFieldValue(
            $normalizedData,
            'block_fad_locations_filter_region'
        );
        $filterIds = $this->getArrayFieldValue(
            $normalizedData,
            'block_fad_locations_filter_ids'
        );
        $moreQuery = $this->getStringFieldValue(
            $normalizedData,
            'block_fad_locations_more',
            '0'
        ) === '1';
        $moreHeading = HtmlTagStripper::strip(
            $this->getStringFieldValue(
                $normalizedData,
                'block_fad_locations_more_text'
            )
        );
        $moreButtonText = HtmlTagStripper::strip(
            $this->getStringFieldValue(
                $normalizedData,
                'block_fad_locations_more_button_text'
            )
        );
        $moreButtonUrlData = $this->getArrayFieldValue(
            $normalizedData,
            'block_fad_locations_more_button_url'
        );
        $originalMoreButtonUrl = $this->getStringFieldValue(
            $moreButtonUrlData,
            'url'
        );

        // Process URL through LinkProcessor to handle internal links and file imports
        $moreButtonUrl = LinkProcessor::processUrlString(
            $originalMoreButtonUrl,
            $site,
            $assetImporter,
            $output,
            ProcessingContext::getStatamicEntryId(),
            ProcessingContext::getCollectionHandle()
        );
        $moreButtonTarget = $this->getStringFieldValue(
            $moreButtonUrlData,
            'target'
        ) === '_blank';
        $moreButtonDescription = HtmlTagStripper::strip(
            $this->getStringFieldValue(
                $normalizedData,
                'block_fad_locations_more_button_description'
            )
        );

        // Validate required fields
        if ($heading === '') {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'page_slug' => $pageSlug,
                    'available_keys' => \array_keys($normalizedData),
                ]
            );

            // Log warning about missing required heading
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'FindADocLocationsParser::parse: Skipping wp:acf/fad-locations block due to missing required heading',
                    $logData['page_url']
                ),
                $logData['context']
            );

            return [];
        }

        if ($description === '') {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'page_slug' => $pageSlug,
                    'available_keys' => \array_keys($normalizedData),
                ]
            );

            // Log warning about missing required description
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'FindADocLocationsParser::parse: Skipping wp:acf/fad-locations block due to missing required description',
                    $logData['page_url']
                ),
                $logData['context']
            );

            return [];
        }

        if ($filterType === [] && $filterAoe === [] && $filterRegion === [] && $filterIds === []) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'page_slug' => $pageSlug,
                    'available_keys' => \array_keys($normalizedData),
                ]
            );

            // Log warning about missing required filters
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'FindADocLocationsParser::parse: Skipping wp:acf/fad-locations block due to missing required filters',
                    $logData['page_url']
                ),
                $logData['context']
            );

            return [];
        }

        if ($backgroundColor === '') {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'page_slug' => $pageSlug,
                    'available_keys' => \array_keys($normalizedData),
                ]
            );

            // Log warning about missing required background color
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'FindADocLocationsParser::parse: Skipping wp:acf/fad-locations block due to missing required background color',
                    $logData['page_url']
                ),
                $logData['context']
            );

            return [];
        }

        // Map location types (taxonomy: location_type)
        $locationTypes = [];
        foreach ($filterType as $termId) {
            $termIdString = $this->normalizeIdentifier(
                $termId
            );
            if ($termIdString === null) {
                continue;
            }

            $termSlug = $this->getTermSlug(
                $termIdString,
                $terms,
                'location_type'
            );
            if ($termSlug !== null && $termSlug !== '') {
                $locationTypes[] = $termSlug;
                // Track term for validation
                self::$termsUsed['location_type'][$termIdString] = [
                    'slug' => $pageSlug ?? 'unknown',
                    'link' => $this->getStringFieldValue(
                        $data,
                        'link'
                    ),
                ];
            } else {
                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'site' => $site,
                        'page_slug' => $pageSlug,
                        'term_id' => $termIdString,
                    ]
                );

                // Log warning about missing term
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        "FindADocLocationsParser::parse: Location type term ID '$termIdString' not found in XML terms for wp:acf/fad-locations block",
                        $logData['page_url']
                    ),
                    $logData['context']
                );
            }
        }

        // Map areas of expertise (post_type: expertise)
        $expertise = [];
        foreach ($filterAoe as $postId) {
            $postIdString = $this->normalizeIdentifier(
                $postId
            );
            if ($postIdString === null) {
                continue;
            }

            $postName = $this->getPostName(
                $postIdString,
                'expertise',
                $items
            );
            if ($postName !== null && $postName !== '') {
                $expertise[] = "{$postName}-{$postIdString}";
            } else {
                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'site' => $site,
                        'page_slug' => $pageSlug,
                        'post_id' => $postIdString,
                        'post_type' => 'expertise',
                    ]
                );

                // Log warning about missing post
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        "FindADocLocationsParser::parse: Expertise post ID '$postIdString' not found in XML items for wp:acf/fad-locations block",
                        $logData['page_url']
                    ),
                    $logData['context']
                );
            }
        }

        // Map regions (taxonomy: region)
        $regions = [];
        foreach ($filterRegion as $termId) {
            $termIdString = $this->normalizeIdentifier(
                $termId
            );
            if ($termIdString === null) {
                continue;
            }

            $termSlug = $this->getTermSlug(
                $termIdString,
                $terms,
                'region'
            );
            if ($termSlug !== null && $termSlug !== '') {
                $regions[] = $termSlug;
                // Track term for validation
                self::$termsUsed['region'][$termIdString] = [
                    'slug' => $pageSlug ?? 'unknown',
                    'link' => $this->getStringFieldValue(
                        $data,
                        'link'
                    ),
                ];
            } else {
                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'site' => $site,
                        'page_slug' => $pageSlug,
                        'term_id' => $termIdString,
                    ]
                );

                // Log warning about missing term
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        "FindADocLocationsParser::parse: Region term ID '$termIdString' not found in XML terms for wp:acf/fad-locations block",
                        $logData['page_url']
                    ),
                    $logData['context']
                );
            }
        }

        // Map locations (post_type: location)
        $locations = [];
        foreach ($filterIds as $postId) {
            $postIdString = $this->normalizeIdentifier(
                $postId
            );
            if ($postIdString === null) {
                continue;
            }

            $postName = $this->getPostName(
                $postIdString,
                'location',
                $items
            );
            if ($postName !== null && $postName !== '') {
                $locations[] = "{$postName}-{$postIdString}";
            } else {
                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'site' => $site,
                        'page_slug' => $pageSlug,
                        'post_id' => $postIdString,
                        'post_type' => 'location',
                    ]
                );

                // Log warning about missing post
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        "FindADocLocationsParser::parse: Location post ID '$postIdString' not found in XML items for wp:acf/fad-locations block",
                        $logData['page_url']
                    ),
                    $logData['context']
                );
            }
        }

        // Validate "more" link fields if enabled
        if ($moreQuery) {
            if ($moreHeading === '') {
                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'site' => $site,
                        'page_slug' => $pageSlug,
                        'available_keys' => \array_keys($normalizedData),
                    ]
                );

                // Log warning about missing required more heading
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        'FindADocLocationsParser::parse: Skipping wp:acf/fad-locations block due to missing required more heading',
                        $logData['page_url']
                    ),
                    $logData['context']
                );

                return [];
            }

            if ($moreButtonText === '') {
                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'site' => $site,
                        'page_slug' => $pageSlug,
                        'available_keys' => \array_keys($normalizedData),
                    ]
                );

                // Log warning about missing required more button text
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        'FindADocLocationsParser::parse: Skipping wp:acf/fad-locations block due to missing required more button text',
                        $logData['page_url']
                    ),
                    $logData['context']
                );

                return [];
            }

            if ($moreButtonUrl === '') {
                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'site' => $site,
                        'page_slug' => $pageSlug,
                        'available_keys' => \array_keys($normalizedData),
                    ]
                );

                // Log warning about missing or invalid required more button URL
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        'FindADocLocationsParser::parse: Skipping wp:acf/fad-locations block due to missing or invalid required more button URL',
                        $logData['page_url']
                    ),
                    $logData['context']
                );

                return [];
            }

            if ($moreButtonDescription === '') {
                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'site' => $site,
                        'page_slug' => $pageSlug,
                        'available_keys' => \array_keys($normalizedData),
                    ]
                );

                // Log warning about missing required more button description
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        'FindADocLocationsParser::parse: Skipping wp:acf/fad-locations block due to missing required more button description',
                        $logData['page_url']
                    ),
                    $logData['context']
                );

                return [];
            }
        }

        // Build the replicator set
        $result = [
            'id' => (new RowId)->generate(), // Unique ID for replicator set
            'type' => 'uamsweb_block_fad_locations', // Statamic fieldset handle
            'enabled' => true, // Standard for replicator sets
            'block_heading' => $heading, // Required text
            'hide_block_heading' => false, // Default to false, not supported in WordPress
            'block_description' => \substr($description, 0, 290), // Required textarea, truncate to 290 chars
            'background_color' => $backgroundColor, // Required select
            'more_query' => $moreQuery, // Required TrueFalse
        ];

        // Include optional fields
        if ($locationTypes !== []) {
            $result['location_types'] = $locationTypes; // Optional terms_search_index
        }

        if ($expertise !== []) {
            $result['expertise'] = $expertise; // Optional entries
        }

        if ($regions !== []) {
            $result['regions'] = $regions; // Optional terms_search_index
        }

        if ($locations !== []) {
            $result['locations'] = $locations; // Optional entries
        }

        if ($moreQuery) {
            $result['more_heading'] = $moreHeading; // Required if more_query=true
            $result['more_button_text'] = $moreButtonText; // Required if more_query=true
            $result['more_button_url'] = $moreButtonUrl; // Required if more_query=true
            $result['more_button_target'] = $moreButtonTarget; // Required if more_query=true
            $result['more_button_link_description'] = $moreButtonDescription; // Required if more_query=true
        }

        return $result;
    }

    /**
     * Retrieves the slug for a taxonomy term by its ID and taxonomy type.
     *
     * Searches the provided terms for a matching term ID and taxonomy, returning the
     * term slug.
     *
     * @param  string  $termId  The term ID from the WordPress XML
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects from the WordPress XML
     * @param  string  $statamicTaxonomyHandle  The Statamic taxonomy handle (e.g., 'location_type', 'region')
     * @return ?string The term slug or null if not found
     */
    private function getTermSlug(
        string $termId,
        array $terms,
        string $statamicTaxonomyHandle
    ): ?string {
        // Loop through terms to find matching term ID and taxonomy
        foreach ($terms as $term) {
            if (! $term instanceof SimpleXMLElement) {
                continue;
            }

            // Extract the term ID from WordPress XML
            $id = XpathHelper::getStringValue(
                $term,
                'wp:term_id'
            );

            // Extract the term taxonomy from WordPress XML
            $termTaxonomy = XpathHelper::getStringValue(
                $term,
                'wp:term_taxonomy'
            );

            // Check if this term matches the requested ID and taxonomy
            if (
                $id === $termId
                && $termTaxonomy === $statamicTaxonomyHandle
            ) {
                // Return the term slug for the matching term
                return XpathHelper::getStringValue(
                    $term,
                    'wp:term_slug'
                );
            }
        }

        // Return null if no matching term is found
        return null;
    }

    /**
     * Retrieves the post name for a given post ID and post type from XML items.
     *
     * Searches the provided items for a matching post ID and post type, returning the
     * post name.
     *
     * @param  string  $postId  The post ID from the WordPress XML
     * @param  string  $postType  The post type (e.g., 'expertise', 'location')
     * @param  array<int|string, mixed>  $items  Array of SimpleXMLElement item objects from the WordPress XML
     * @return ?string The post name or null if not found
     */
    private function getPostName(
        string $postId,
        string $postType,
        array $items
    ): ?string {
        foreach ($items as $item) {
            if (! $item instanceof SimpleXMLElement) {
                continue;
            }

            // Extract the item ID from WordPress XML
            $itemId = XpathHelper::getStringValue(
                $item,
                'wp:post_id'
            );

            // Extract the item post type from WordPress XML
            $itemType = XpathHelper::getStringValue(
                $item,
                'wp:post_type'
            );

            // Check if this item matches the requested ID and post type
            if (
                $itemId === $postId
                && $itemType === $postType
            ) {
                // Return the post name for the matching item
                return XpathHelper::getStringValue(
                    $item,
                    'wp:post_name'
                );
            }
        }

        // A scoped run may not have read this type at all, in which case the miss is the
        // scope rather than a deleted item, and says so rather than dropping quietly (wordpress-importer-archive#1179).
        MaterializationScope::reportUnreadType('FindADocLocationsParser', $postType, $postId);

        return null;
    }

    /**
     * Returns a string field value from a normalized block data array.
     *
     * @param  array<int|string, mixed>  $data  The source block data
     * @param  string  $field  The field handle to read
     * @param  string  $default  The fallback value when no string is available
     * @return string The normalized string value
     */
    private function getStringFieldValue(
        array $data,
        string $field,
        string $default = ''
    ): string {
        $value = $data[$field] ?? $default;

        if (\is_string($value)) {
            return $value;
        }

        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }

        return $default;
    }

    /**
     * Returns an array field value from a normalized block data array.
     *
     * @param  array<int|string, mixed>  $data  The source block data
     * @param  string  $field  The field handle to read
     * @return array<int|string, mixed> The normalized array value
     */
    private function getArrayFieldValue(
        array $data,
        string $field
    ): array {
        $value = $data[$field] ?? [];

        return \is_array($value) ? $value : [];
    }

    /**
     * Converts a mixed identifier from ACF data into a string value.
     *
     * @param  mixed  $value  The source identifier value
     * @return ?string Normalized identifier, or null when unsupported
     */
    private function normalizeIdentifier(
        mixed $value
    ): ?string {
        if (\is_string($value)) {
            return $value;
        }

        if (\is_int($value)) {
            return (string) $value;
        }

        return null;
    }

    /**
     * Returns the terms used in parsed blocks for taxonomy validation.
     *
     * @return array<string, array<string, array{slug: string, link: string}>> Terms used, keyed by taxonomy and term ID
     */
    public static function getTermsUsed(): array
    {
        return self::$termsUsed;
    }

    /**
     * Resets the terms used for a new import cycle.
     */
    public static function resetTermsUsed(): void
    {
        self::$termsUsed = [];
    }
}
