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
 * Parses WordPress ACF Find-a-Doc Providers blocks into Statamic page_builder
 * replicator sets.
 *
 *   - Converts the ACF Find-a-Doc Providers block attributes
 *     (e.g., field_block_fad_providers_heading,
 *     field_block_fad_providers_filter_ids) into a Statamic
 *     uamsweb_block_fad_providers replicator set for the landing_page blueprint's
 *     page_builder field. Processes post IDs for expertise, locations, and
 *     providers, mapping them to Statamic entry IDs (post_name-post_id), and
 *     tracks region taxonomy terms for validation. Validates required fields and
 *     handles conditional "more" link fields.
 *   - Normalizes ACF field names using `AcfFieldNormalizer` utility for
 *     consistent field mapping across different WordPress ACF data structures.
 */
class FindADocProvidersParser
{
    /**
     * Array to store region taxonomy terms and their associated page slugs for
     * validation.
     *
     * @var array<string, array<string>>
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
            'field_block_fad_providers_heading' => 'block_fad_providers_heading',
            'field_block_fad_providers_description' => 'block_fad_providers_description',
            'field_block_fad_providers_background_color' => 'block_fad_providers_background_color',
            'field_block_fad_providers_filter_aoe' => 'block_fad_providers_filter_aoe',
            'field_block_fad_providers_filter_location' => 'block_fad_providers_filter_location',
            'field_block_fad_providers_filter_region' => 'block_fad_providers_filter_region',
            'field_block_fad_providers_filter_ids' => 'block_fad_providers_filter_ids',
            'field_block_fad_providers_more' => 'block_fad_providers_more',
            'field_block_fad_providers_more_text' => 'block_fad_providers_more_text',
            'field_block_fad_providers_more_button_text' => 'block_fad_providers_more_button_text',
            'field_block_fad_providers_more_button_url' => 'block_fad_providers_more_button_url',
            'field_block_fad_providers_more_button_description' => 'block_fad_providers_more_button_description',
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
     * Builds a map of term IDs to their slugs and parent IDs from XML terms.
     *
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects from XML
     * @return array<string, array{slug: string, parent_id: string}> Associative array
     *                                                               mapping term IDs to slug and parent ID
     */
    private function buildTermMap(
        array $terms
    ): array {
        // Initialize term map
        $termMap = [];

        // Loop through terms to build the term mapping
        foreach ($terms as $term) {
            // Skip invalid term payloads that are not XML elements
            if (! $term instanceof SimpleXMLElement) {
                continue;
            }

            // Extract the term ID from WordPress XML
            $id = XpathHelper::getStringValue(
                $term,
                'wp:term_id'
            );

            // Extract the term slug from WordPress XML
            $slug = XpathHelper::getStringValue(
                $term,
                'wp:term_slug'
            );

            // Extract the parent term slug from WordPress XML
            $parentSlug = XpathHelper::getStringValue(
                $term,
                'wp:term_parent'
            );

            // Initialize parent ID variable
            $parentId = '';

            // Check if this term has a parent slug
            if ($parentSlug !== '') {
                // Loop through terms to find the parent term
                foreach ($terms as $parentTerm) {
                    // Skip invalid parent term payloads
                    if (! $parentTerm instanceof SimpleXMLElement) {
                        continue;
                    }

                    // Check if this term matches the parent slug
                    if (
                        XpathHelper::getStringValue(
                            $parentTerm,
                            'wp:term_slug'
                        ) === $parentSlug
                    ) {
                        // Extract the parent term ID
                        $parentId = XpathHelper::getStringValue(
                            $parentTerm,
                            'wp:term_id'
                        );

                        // Break out of the loop once parent is found
                        break;
                    }
                }
            }

            // Check if we have valid ID and slug before adding to map
            if ($id !== '' && $slug !== '') {
                // Add the term to the mapping with its slug and parent ID
                $termMap[$id] = [
                    'slug' => $slug,
                    'parent_id' => $parentId,
                ];
            }
        }

        // Return the completed term mapping
        return $termMap;
    }

    /**
     * Constructs the full slug path for a term, recursively prepending ancestor slugs.
     *
     * @param  string  $termId  The term ID
     * @param  array<string, array{slug: string, parent_id: string}>  $termMap  Map
     *                                                                          of term IDs to their slugs and parent IDs
     * @param  array<int, string>  $visited  Term IDs visited in current recursion
     * @return string The full slug path (e.g., 'grandparent_parent_term') or term slug if no parent
     */
    private function getFullTermSlug(
        string $termId,
        array $termMap,
        array &$visited = []
    ): string {
        if ($termId === '' || ! isset($termMap[$termId])) {
            return "term-$termId"; // Fallback if term not found
        }

        $term = $termMap[$termId];
        $slug = $term['slug'];

        // Prevent infinite recursion due to circular parent references
        if (
            \in_array(
                $termId,
                $visited,
                true
            )
        ) {
            return $slug;
        }

        $visited[] = $termId;

        $parentId = $term['parent_id'];
        if ($parentId !== '' && isset($termMap[$parentId])) {
            $ancestorSlug = $this->getFullTermSlug(
                $parentId,
                $termMap,
                $visited
            );

            return $ancestorSlug !== '' ? "{$ancestorSlug}_{$slug}" : $slug;
        }

        return $slug;
    }

    /**
     * Retrieves the post_name for a given post ID and post type from XML items.
     *
     * @param  string  $postId  The WordPress post ID
     * @param  string  $postType  The WordPress post type (e.g., 'expertise', 'location', 'provider')
     * @param  array<int|string, mixed>  $items  Array of SimpleXMLElement item objects from XML
     * @return ?string The post_name if found, null otherwise
     */
    private function getPostName(
        string $postId,
        string $postType,
        array $items
    ): ?string {
        foreach ($items as $item) {
            // Skip invalid item payloads that are not XML elements
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
        MaterializationScope::reportUnreadType('FindADocProvidersParser', $postType, $postId);

        return null;
    }

    /**
     * Parses a WordPress ACF Find-a-Doc Providers block into a Statamic page_builder
     * replicator set.
     *
     *   - Maps block attributes to a uamsweb_block_fad_providers set, processing post
     *     IDs for expertise, locations, and providers into Statamic entry IDs
     *     (post_name-post_id), and region taxonomy terms into full hierarchical
     *     slugs. Validates required fields, omits empty optional fields, and tracks
     *     region terms for validation.
     *   - Uses AcfFieldNormalizer for consistent field mapping across different
     *     WordPress ACF data structures.
     *
     * @param  array<int|string, mixed>  $attributes  The block attributes from the wp:acf/fad-providers comment
     * @param  string  $site  The Statamic site handle (e.g., 'default') for asset paths
     * @param  AssetImporter  $assetImporter  Instance for importing images (unused)
     * @param  OutputInterface  $output  Console output for logging progress and errors
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects from XML
     * @param  array<int|string, mixed>  $items  Array of SimpleXMLElement item objects from XML for post ID mapping
     * @param  ?string  $pageSlug  The page slug for taxonomy term tracking (optional)
     * @return array<int|string, mixed> The Statamic uamsweb_block_fad_providers replicator set
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
        $data = isset($attributes['data']) && \is_array($attributes['data'])
            ? $attributes['data']
            : $attributes;

        // Normalize field names using AcfFieldNormalizer
        $normalizedData = AcfFieldNormalizer::normalize(
            $data,
            $this->getFieldMappings(),
            $this->getNestedStructures()
        );

        // Map basic fields using normalized keys
        $heading = HtmlTagStripper::strip(
            $this->readStringValue(
                $normalizedData,
                'block_fad_providers_heading'
            )
        );
        $description = HtmlTagStripper::strip(
            $this->readStringValue(
                $normalizedData,
                'block_fad_providers_description'
            )
        );
        $backgroundColor = $this->readStringValue(
            $normalizedData,
            'block_fad_providers_background_color',
            'bg-white'
        );
        $filterAoe = $this->normalizeIdList(
            $normalizedData['block_fad_providers_filter_aoe'] ?? []
        );
        $filterLocation = $this->normalizeIdList(
            $normalizedData['block_fad_providers_filter_location'] ?? []
        );
        $filterRegion = $this->normalizeIdList(
            $normalizedData['block_fad_providers_filter_region'] ?? []
        );
        $filterIds = $this->normalizeIdList(
            $normalizedData['block_fad_providers_filter_ids'] ?? []
        );
        $moreQuery = ($normalizedData['block_fad_providers_more'] ?? '0') === '1';
        $moreHeading = HtmlTagStripper::strip(
            $this->readStringValue(
                $normalizedData,
                'block_fad_providers_more_text'
            )
        );
        $moreButtonText = HtmlTagStripper::strip(
            $this->readStringValue(
                $normalizedData,
                'block_fad_providers_more_button_text'
            )
        );
        $moreButtonUrlData = $normalizedData['block_fad_providers_more_button_url'] ?? [];
        if (! \is_array($moreButtonUrlData)) {
            $moreButtonUrlData = [];
        }

        $originalMoreButtonUrl = $this->readStringValue(
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
        $moreButtonTarget = isset($moreButtonUrlData['target']) && $moreButtonUrlData['target'] === '_blank';
        $moreButtonDescription = HtmlTagStripper::strip(
            $this->readStringValue(
                $normalizedData,
                'block_fad_providers_more_button_description'
            )
        );

        // Validate required fields
        if ($heading === '') {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'page_slug' => $pageSlug,
                ]
            );

            // Log warning about missing required heading
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'FindADocProvidersParser::parse: Skipping wp:acf/fad-providers block due to missing required heading',
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
                ]
            );

            // Log warning about missing required description
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'FindADocProvidersParser::parse: Skipping wp:acf/fad-providers block due to missing required description',
                    $logData['page_url']
                ),
                $logData['context']
            );

            return [];
        }

        if ($filterAoe === [] && $filterLocation === [] && $filterRegion === [] && $filterIds === []) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'page_slug' => $pageSlug,
                ]
            );

            // Log warning about missing required filters
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'FindADocProvidersParser::parse: Skipping wp:acf/fad-providers block due to missing required filters',
                    $logData['page_url']
                ),
                $logData['context']
            );

            return [];
        }

        // Map expertise post IDs to Statamic entry IDs
        $expertise = [];
        foreach ($filterAoe as $id) {
            $postName = $this->getPostName(
                $id,
                'expertise',
                $items
            );
            if ($postName !== null && $postName !== '') {
                $expertise[] = "{$postName}-{$id}";
            } else {
                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'site' => $site,
                        'page_slug' => $pageSlug,
                        'post_id' => $id,
                        'post_type' => 'expertise',
                    ]
                );

                // Log warning about missing post
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        "FindADocProvidersParser::parse: Expertise post ID '$id' not found in XML items",
                        $logData['page_url']
                    ),
                    $logData['context']
                );
            }
        }

        // Map location post IDs to Statamic entry IDs
        $locations = [];
        foreach ($filterLocation as $id) {
            $postName = $this->getPostName(
                $id,
                'location',
                $items
            );
            if ($postName !== null && $postName !== '') {
                $locations[] = "{$postName}-{$id}";
            } else {
                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'site' => $site,
                        'page_slug' => $pageSlug,
                        'post_id' => $id,
                        'post_type' => 'location',
                    ]
                );

                // Log warning about missing post
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        "FindADocProvidersParser::parse: Location post ID '$id' not found in XML items",
                        $logData['page_url']
                    ),
                    $logData['context']
                );
            }
        }

        // Map provider post IDs to Statamic entry IDs
        $providers = [];
        foreach ($filterIds as $id) {
            $postName = $this->getPostName(
                $id,
                'provider',
                $items
            );
            if ($postName !== null && $postName !== '') {
                $providers[] = "{$postName}-{$id}";
            } else {
                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'site' => $site,
                        'page_slug' => $pageSlug,
                        'post_id' => $id,
                        'post_type' => 'provider',
                    ]
                );

                // Log warning about missing post
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        "FindADocProvidersParser::parse: Provider post ID '$id' not found in XML items",
                        $logData['page_url']
                    ),
                    $logData['context']
                );
            }
        }

        // Map and track region taxonomy terms
        $regions = [];
        $termMap = $this->buildTermMap($terms);
        foreach ($filterRegion as $id) {
            $fullSlug = $this->getFullTermSlug(
                $id,
                $termMap
            );
            $regions[] = $fullSlug;
            if ($pageSlug !== null && $pageSlug !== '') {
                self::$termsUsed[$id] ??= [];

                if (
                    ! \in_array(
                        $pageSlug,
                        self::$termsUsed[$id],
                        true
                    )
                ) {
                    self::$termsUsed[$id][] = $pageSlug;
                }
            }
        }

        // Validate "more" link fields if enabled
        if ($moreQuery && ($moreHeading === '' || $moreButtonText === '' || $moreButtonUrl === '')) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'page_slug' => $pageSlug,
                    'more_heading' => $moreHeading,
                    'more_button_text' => $moreButtonText,
                    'more_button_url' => $moreButtonUrl,
                ]
            );

            // Log warning about missing required more link fields
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'FindADocProvidersParser::parse: Skipping wp:acf/fad-providers block due to missing required more link fields',
                    $logData['page_url']
                ),
                $logData['context']
            );

            return [];
        }

        // Build the replicator set
        $result = [
            'id' => (new RowId)->generate(), // Unique ID for replicator set
            'type' => 'uamsweb_block_fad_providers', // Statamic fieldset handle
            'block_heading' => $heading, // Required text
            'hide_block_heading' => false, // Default to false, not supported in WordPress
            'block_description' => \substr($description, 0, 290), // Required textarea, truncate to 290 chars
            'background_color' => $backgroundColor, // Required select
            'more_query' => $moreQuery, // Required TrueFalse
            // The WordPress theme's provider block filtered facets over
            // admin-ajax.php unconditionally, so filtering was effectively always
            // on. The Statamic toggle defaults to false (a static list), which would
            // silently turn filtering off for every imported block — set it to
            // preserve the source behavior. The ACF payload carries no per-block
            // toggle to map through. wordpress-importer-archive#421
            'enable_filtering' => true,
            'enabled' => true, // Standard for replicator sets
        ];

        // Include optional fields
        if ($expertise !== []) {
            $result['expertise'] = $expertise; // Optional entries
        }

        if ($locations !== []) {
            $result['locations'] = $locations; // Optional entries
        }

        if ($regions !== []) {
            $result['regions'] = $regions; // Optional terms_search_index
        }

        if ($providers !== []) {
            $result['providers'] = $providers; // Optional entries
        }

        if ($moreQuery) {
            $result['more_heading'] = $moreHeading; // Required if more_query=true
            $result['more_button_text'] = $moreButtonText; // Required if more_query=true
            $result['more_button_url'] = $moreButtonUrl; // Required if more_query=true
            $result['more_button_target'] = $moreButtonTarget; // Required if more_query=true
            if ($moreButtonDescription !== '') {
                $result['more_button_link_description'] = $moreButtonDescription; // Optional
            }
        }

        return $result;
    }

    /**
     * Gets the region taxonomy terms used by Find-a-Doc Providers blocks with their
     * page slugs.
     *
     * @return array<string, array<string>> Associative array of region terms and page slugs
     */
    public static function getTermsUsed(): array
    {
        return self::$termsUsed;
    }

    /**
     * Resets the terms used array (for testing or cleanup).
     */
    public static function resetTermsUsed(): void
    {
        self::$termsUsed = [];
    }

    /**
     * Reads a string value from an associative payload.
     *
     * @param  array<int|string, mixed>  $data  Payload to read from
     * @param  string  $key  Key to read
     * @param  string  $default  Fallback value when key is absent
     * @return string Normalized string value
     */
    private function readStringValue(
        array $data,
        string $key,
        string $default = ''
    ): string {
        // Return default when key is missing from payload
        if (! isset($data[$key])) {
            return $default;
        }

        // Return existing string values unchanged
        if (\is_string($data[$key])) {
            return $data[$key];
        }

        // Convert scalar payloads to strings for downstream use
        if (\is_scalar($data[$key])) {
            return (string) $data[$key];
        }

        // Fallback for unsupported value types
        return $default;
    }

    /**
     * Normalizes mixed filter input into a list of string IDs.
     *
     * @param  mixed  $value  Raw filter value from ACF data
     * @return array<int, string> Normalized list of string IDs
     */
    private function normalizeIdList(
        mixed $value
    ): array {
        // Wrap scalar values to preserve legacy single-value payloads
        if (! \is_array($value)) {
            $value = ($value === null || $value === '') ? [] : [$value];
        }

        // Normalize list entries into non-empty string IDs
        $normalized = [];
        foreach ($value as $item) {
            if (\is_scalar($item)) {
                $id = (string) $item;
                if ($id !== '') {
                    $normalized[] = $id;
                }
            }
        }

        // Return normalized ID list
        return $normalized;
    }
}
