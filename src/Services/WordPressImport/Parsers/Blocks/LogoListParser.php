<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\Blocks;

use Illuminate\Support\Facades\Log;
use Statamic\Fieldtypes\RowId;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Taxonomies\TermDestinationRegistry;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\HtmlTagStripper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\TaxonomySlugProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\TermMapBuilder;

/**
 * Parses WordPress ACF Logo List blocks into Statamic page_builder replicator
 * sets.
 *
 * Converts the ACF Logo List block attributes (e.g., block_logo_list_heading,
 * block_logo_list_filter_ids) into a Statamic uamsweb_block_logo_list replicator
 * set for the landing_page blueprint's page_builder field. Resolves the category
 * and label filters to the collection entries the term pass imported them as
 * (with a flat fallback for categories only), maps sort orders to a replicator
 * set, and handles conditional fields for layout and "more" link. Tracks
 * category terms for validation.
 */
class LogoListParser
{
    /**
     * Array to store taxonomy terms and their associated page slugs for validation.
     *
     * @var array<string, array<string, array<string>>>
     */
    private static array $termsUsed = [];

    /**
     * Maps WordPress block_logo_list_order values to Statamic logo_list_sort
     * replicator sets.
     *
     * @param  array<int|string, mixed>  $orders  Array of sort order strings (e.g., ['title|ASC', 'rand|ASC'])
     * @param  string  $site  The Statamic site handle
     * @return array<int|string, mixed> Array of Statamic sort_option replicator sets
     */
    private function mapSortOrders(
        array $orders,
        string $site
    ): array {
        $result = [];
        foreach ($orders as $order) {
            // Skip invalid sort configuration values.
            if (! \is_string($order) || $order === '') {
                continue;
            }

            [$sortBy, $sortOrder] = \explode(
                '|',
                $order
            );
            $set = [
                'id' => (new RowId)->generate(),
                'type' => 'sort_option',
                'enabled' => true,
            ];

            switch ($sortBy) {
                case 'meta_value':
                    $set['sort_by'] = 'logo_title';
                    $set['sort_order'] = \strtolower($sortOrder);
                    break;
                case 'title':
                    $set['sort_by'] = 'title';
                    $set['sort_order'] = \strtolower($sortOrder);
                    break;
                case 'menu_order':
                    $set['sort_by'] = 'order_values';
                    $set['sort_key'] = "{$site}-general";
                    $set['sort_order'] = \strtolower($sortOrder);
                    break;
                case 'rand':
                    $set['sort_by'] = 'random';
                    // No sort_order for random
                    break;
                default:
                    continue 2; // Skip invalid orders and continue outer loop
            }

            $result[] = $set;
        }

        return $result;
    }

    /**
     * Parses a WordPress ACF Logo List block into a Statamic page_builder replicator
     * set.
     *
     * Maps block attributes to a uamsweb_block_logo_list set, processing taxonomy
     * terms, sort orders, and conditional fields. Validates required fields, omits
     * empty optional fields, and tracks taxonomy terms for validation.
     *
     * @param  array<int|string, mixed>  $attributes  The block attributes from the wp:acf/logo-list comment
     * @param  string  $site  The Statamic site handle (e.g., 'default') for asset paths
     * @param  AssetImporter  $assetImporter  Instance for importing images (unused)
     * @param  OutputInterface  $output  Console output for logging progress and errors
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects from XML
     * @param  ?string  $pageSlug  The page slug for taxonomy term tracking (optional)
     * @return array<int|string, mixed> The Statamic uamsweb_block_logo_list replicator set
     */
    public function parse(
        array $attributes,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $terms = [],
        ?string $pageSlug = null
    ): array {
        // Handle attributes with or without 'data' key
        $data = $this->resolveDataAttributes($attributes);

        // ---
        // Build the term map over both logo list taxonomies, as the item
        // creator does, so both sides work from one map. A parent slug resolves
        // within its child's taxonomy, so a label never enters a category's full
        // slug. The category filter below accepts only category terms from it.
        // ---
        $termMap = TermMapBuilder::buildTermMap(
            $terms,
            [
                'logo-list-category',
                'logo-list-label',
            ]
        );

        // Map basic fields
        $heading = HtmlTagStripper::strip(
            $this->getStringAttribute(
                $data,
                'block_logo_list_heading'
            )
        );
        $hideHeading = $this->getBooleanStringFlag(
            $data,
            'block_logo_list_hide_heading'
        );
        $description = HtmlTagStripper::strip(
            $this->getStringAttribute(
                $data,
                'block_logo_list_description'
            )
        );
        $backgroundColor = $this->getStringAttribute(
            $data,
            'block_logo_list_background_color',
            'bg-auto'
        );
        $filterIds = $this->getArrayAttribute(
            $data,
            'block_logo_list_filter_ids'
        );
        $filterLogic = $this->getStringAttribute(
            $data,
            'block_logo_list_filter_logic',
            'IN'
        );
        $labelsIds = $this->getArrayAttribute(
            $data,
            'block_logo_list_labels_ids'
        );
        $labelsLogic = $this->getStringAttribute(
            $data,
            'block_logo_list_labels_logic',
            'IN'
        );
        $featured = $this->getBooleanStringFlag(
            $data,
            'block_logo_list_featured'
        );
        $layout = $this->getStringAttribute(
            $data,
            'block_logo_list_layout',
            'grid'
        );
        $width = $this->getStringAttribute(
            $data,
            'block_logo_list_width',
            'alignfull'
        );
        $titles = $this->getBooleanStringFlag(
            $data,
            'block_logo_list_titles'
        );
        $labels = $this->getBooleanStringFlag(
            $data,
            'block_logo_list_labels'
        );
        $shortdescr = $this->getBooleanStringFlag(
            $data,
            'block_logo_list_shortdescr'
        );
        $links = $this->getBooleanStringFlag(
            $data,
            'block_logo_list_links'
        );
        $linksTarget = $this->getBooleanStringFlag(
            $data,
            'block_logo_list_links_target',
            '1'
        );
        $order = $this->getArrayAttribute(
            $data,
            'block_logo_list_order'
        );
        if ($order === []) {
            $order = ['title|ASC'];
        }

        $max = $this->getOptionalIntAttribute(
            $data,
            'block_logo_list_max'
        );
        $moreQuery = $this->getBooleanStringFlag(
            $data,
            'block_logo_list_more'
        );
        $moreHeading = HtmlTagStripper::strip(
            $this->getStringAttribute(
                $data,
                'block_logo_list_more_text'
            )
        );
        $moreButtonText = HtmlTagStripper::strip(
            $this->getStringAttribute(
                $data,
                'block_logo_list_more_button_text'
            )
        );
        $moreButtonUrlData = $this->getArrayAttribute(
            $data,
            'block_logo_list_more_button_url'
        );
        $originalMoreButtonUrl = $this->normalizeScalarToString(
            $moreButtonUrlData['url'] ?? ''
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
        $moreButtonTarget = ($moreButtonUrlData['target'] ?? null) === '_blank';
        $moreButtonDescription = HtmlTagStripper::strip(
            $this->getStringAttribute(
                $data,
                'block_logo_list_more_button_description'
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

            // Log warning about missing heading
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'LogoListParser::parse: Skipping wp:acf/logo-list block due to missing required heading',
                    $logData['page_url']
                ),
                $logData['context']
            );

            return [];
        }

        if ($filterIds === []) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'page_slug' => $pageSlug,
                ]
            );

            // Log warning about missing filter categories
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'LogoListParser::parse: Skipping wp:acf/logo-list block due to missing required filter categories',
                    $logData['page_url']
                ),
                $logData['context']
            );

            return [];
        }

        // Map taxonomy terms and track for validation
        // Initialize an empty array to store the filter categories
        $filterCategories = [];

        // ---
        // Entry ids resolved through the term pass, kept apart from the slug
        // path below because they must NOT be site-prefixed — a prefix would
        // corrupt an id. `logo-list-category` is a structured collection
        // (`TaxonomyDispositions`), and the host field is `type: entries`, so a
        // term slug does not resolve there at all.
        // ---
        $filterCategoryReferences = [];

        // Loop through the filter IDs
        foreach ($filterIds as $id) {
            // Skip invalid term ID values from malformed block data.
            if (! \is_string($id) && ! \is_int($id)) {
                continue;
            }

            // Normalize term ID to string for map lookups and deferred queues.
            $termId = (string) $id;

            // Skip unknown term IDs, and label terms, which are not categories.
            if (
                ! isset($termMap[$termId])
                || $termMap[$termId]['term_taxonomy'] !== 'logo-list-category'
            ) {
                continue;
            }

            // Prefer the collection entry the term pass already imported this
            // term into; the pass runs first, so the destination is recorded.
            $destinationReference = TermDestinationRegistry::referenceFor(
                'logo-list-category',
                $termId
            );

            if ($destinationReference !== null) {
                $filterCategoryReferences[] = $destinationReference;

                continue;
            }

            // Get the full term slug from the pre-computed term map. It is never
            // empty: the builder drops a term without a slug, and the full slug
            // always ends in the term's own.
            $fullSlug = $termMap[$termId]['full_slug'];

            // Add the full term slug to the filter categories
            $filterCategories[] = $fullSlug;

            // Queue the taxonomy term for a single batched creation pass (see
            // BaseEntryCreator::flushDeferredTaxonomyTerms()), instead of creating
            // each one inline and paying a cold Stache index rebuild per term.
            BaseEntryCreator::queueTaxonomyTermFromMap(
                $termId,
                $termMap,
                'logos_item_categories',
                $site
            );

            // Check if the page slug is set
            if ($pageSlug !== null && $pageSlug !== '') {
                // Add site prefix to the full hierarchical slug for validation
                $siteSpecificSlug = TaxonomySlugProcessor::addSitePrefixToSlug(
                    $fullSlug,
                    $site
                );

                self::$termsUsed['logos_item_categories'][$siteSpecificSlug] ??= [];

                // Check if the page slug is not in the terms used array
                if (
                    ! \in_array(
                        $pageSlug,
                        self::$termsUsed['logos_item_categories'][$siteSpecificSlug],
                        true
                    )
                ) {
                    // Add the page slug to the terms used array
                    self::$termsUsed['logos_item_categories'][$siteSpecificSlug][] = $pageSlug;
                }
            }
        }

        // Add site prefix to category slugs — the unresolved fallback path only
        $filterCategories = TaxonomySlugProcessor::addSitePrefixToSlugs(
            $filterCategories,
            $site
        );

        // Entry ids lead, unresolved slugs follow, matching the shape
        // LogoListItemEntryCreator writes for the sibling field
        if ($filterCategoryReferences !== []) {
            $filterCategories = \array_merge(
                $filterCategoryReferences,
                $filterCategories
            );
        }

        // ---
        // Resolve each label to the `logos_item_labels` COLLECTION entry the term
        // pass imported it as. `UAMS-Web/uams-statamic-archive#2533` converted the labels
        // into a site-scoped collection and the block's filter field into an
        // `entries` picker, so a site-prefixed term slug resolves to nothing there
        // (wordpress-importer-archive#1220). Entry ids are never site-prefixed; a prefix would corrupt one.
        // ---
        $filterLabels = [];

        // Count labels with no recorded destination so the loss is reported once.
        $unresolvedLabelCount = 0;

        foreach ($labelsIds as $id) {
            // Skip invalid term ID values from malformed block data.
            if (! \is_string($id) && ! \is_int($id)) {
                continue;
            }

            $labelReference = TermDestinationRegistry::referenceFor(
                'logo-list-label',
                $id
            );

            // ---
            // An unresolved label is omitted rather than written back as a slug,
            // which would restore the dangling value this replaces. A label is
            // recorded only when the run's term pass covered `logo-list-label`,
            // which the logo list item import does.
            // ---
            if ($labelReference === null || $labelReference === '') {
                $unresolvedLabelCount++;

                continue;
            }

            // Two ids can resolve to one entry; store it once.
            if (! \in_array($labelReference, $filterLabels, true)) {
                $filterLabels[] = $labelReference;
            }
        }

        if ($unresolvedLabelCount > 0) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'page_slug' => $pageSlug,
                    'unresolved_labels' => $unresolvedLabelCount,
                ]
            );

            // Report the omitted labels; the block keeps the ones that resolved
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'LogoListParser::parse: Omitting logo list filter labels with no imported logos_item_labels entry',
                    $logData['page_url']
                ),
                $logData['context']
            );
        }

        // Map filter logic
        $categoryLogic = $filterLogic === 'AND' ? 'all' : 'any';
        $labelLogic = $labelsLogic === 'AND' ? 'all' : 'any';

        // Map sort orders
        $sortOptions = $this->mapSortOrders(
            $order,
            $site
        );
        if ($sortOptions === []) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'page_slug' => $pageSlug,
                    'orders' => $order,
                ]
            );

            // Log warning about invalid sort order
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'LogoListParser::parse: Skipping wp:acf/logo-list block due to invalid sort order',
                    $logData['page_url']
                ),
                $logData['context']
            );

            return [];
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

            // Log warning about missing more link fields
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'LogoListParser::parse: Skipping wp:acf/logo-list block due to missing required more link fields',
                    $logData['page_url']
                ),
                $logData['context']
            );

            return [];
        }

        // Build the replicator set
        $result = [
            'id' => (new RowId)->generate(), // Unique ID for replicator set
            'type' => 'uamsweb_block_logo_list', // Statamic fieldset handle
            'block_heading' => $heading, // Required text
            'hide_block_heading' => $hideHeading, // Required TrueFalse
            'background_color' => $backgroundColor, // Required select
            'logo_list_filter_category' => $filterCategories, // Required entries (collection), slug fallback
            'logo_list_filter_category_logic' => $categoryLogic, // Required radio
            'logo_list_featured' => $featured, // Optional TrueFalse
            'logo_list_layout' => $layout, // Required radio
            'logo_list_labels' => $labels, // Required TrueFalse
            'logo_list_links' => $links, // Required TrueFalse
            'logo_list_sort' => $sortOptions, // Required replicator
            'more_query' => $moreQuery, // Required TrueFalse
            'enabled' => true, // Standard for replicator sets
        ];

        // Include optional and conditional fields
        if ($description !== '') {
            $result['block_description'] = \substr(
                $description,
                0,
                290
            ); // Truncate to 290 chars
        }

        if ($filterLabels !== []) {
            $result['logo_list_filter_label'] = $filterLabels; // Optional entries (collection logos_item_labels)
            $result['logo_list_filter_label_logic'] = $labelLogic; // Required if labels present
        }

        if ($layout === 'grid') {
            $result['logo_list_width'] = $width; // Required for grid
            $result['logo_list_titles'] = $titles; // Required for grid
            $result['logo_list_shortdescr'] = $shortdescr; // Required for grid
        }

        if ($links) {
            $result['logo_list_links_target'] = $linksTarget; // Required if links enabled
        }

        if ($max !== null) {
            $result['logo_list_max'] = $max; // Optional integer
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

        // Clean up variables to free up memory
        unset(
            $data,
            $termMap,
            $heading,
            $hideHeading,
            $description,
            $backgroundColor,
            $filterIds,
            $filterLogic,
            $labelsIds,
            $labelsLogic,
            $featured,
            $layout,
            $width,
            $titles,
            $labels,
            $shortdescr,
            $links,
            $linksTarget,
            $order,
            $max,
            $moreQuery,
            $moreHeading,
            $moreButtonText,
            $moreButtonUrlData,
            $moreButtonUrl,
            $moreButtonTarget,
            $moreButtonDescription,
            $filterCategories,
            $filterLabels,
            $categoryLogic,
            $labelLogic,
            $sortOptions
        );

        // Return the result
        return $result;
    }

    /**
     * Gets the taxonomy terms used by Logo List blocks with their page slugs.
     *
     * @return array<string, array<string, array<string>>> Associative array of taxonomies, terms, and page slugs
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
     * Resolve block attributes data payload into a predictable array shape.
     *
     * @param  array<int|string, mixed>  $attributes  Raw block attributes
     * @return array<int|string, mixed> Normalized data payload
     */
    private function resolveDataAttributes(array $attributes): array
    {
        // Use nested "data" payload when present.
        $data = $attributes['data'] ?? $attributes;

        // Return normalized array data or fallback to empty array.
        return \is_array($data) ? $data : [];
    }

    /**
     * Get a string attribute value with scalar normalization and fallback.
     *
     * @param  array<int|string, mixed>  $data  Normalized block data payload
     * @param  string  $key  Attribute key to fetch
     * @param  string  $default  Fallback value when key is missing or invalid
     * @return string Normalized string value
     */
    private function getStringAttribute(
        array $data,
        string $key,
        string $default = ''
    ): string {
        // Return the normalized value for the requested attribute key.
        return $this->normalizeScalarToString(
            $data[$key] ?? $default,
            $default
        );
    }

    /**
     * Get an array attribute value or fallback to an empty array.
     *
     * @param  array<int|string, mixed>  $data  Normalized block data payload
     * @param  string  $key  Attribute key to fetch
     * @return array<int|string, mixed> Normalized array value
     */
    private function getArrayAttribute(
        array $data,
        string $key
    ): array {
        // Read the raw value from the data payload.
        $value = $data[$key] ?? [];

        // Return normalized array data or fallback to empty array.
        return \is_array($value) ? $value : [];
    }

    /**
     * Get a boolean flag represented as "1"/"0" style string value.
     *
     * @param  array<int|string, mixed>  $data  Normalized block data payload
     * @param  string  $key  Attribute key to fetch
     * @param  string  $default  Fallback string value for missing flags
     * @return bool True when value equals "1", otherwise false
     */
    private function getBooleanStringFlag(
        array $data,
        string $key,
        string $default = '0'
    ): bool {
        // Convert the configured flag value into a normalized string.
        $value = $this->getStringAttribute(
            $data,
            $key,
            $default
        );

        // Return true only for enabled "1" values.
        return $value === '1';
    }

    /**
     * Get an optional integer attribute value from scalar data.
     *
     * @param  array<int|string, mixed>  $data  Normalized block data payload
     * @param  string  $key  Attribute key to fetch
     * @return int|null Parsed integer value or null when value is empty/invalid
     */
    private function getOptionalIntAttribute(
        array $data,
        string $key
    ): ?int {
        // Read the raw value from the data payload.
        $value = $data[$key] ?? null;

        // Return null for missing or empty-string values.
        if ($value === null || $value === '') {
            return null;
        }

        // Return integer value for numeric scalar inputs.
        if (\is_int($value)) {
            return $value;
        }

        // Convert numeric strings or floats to integers.
        if (\is_string($value) || \is_float($value)) {
            return (int) $value;
        }

        // Return null for unsupported value types.
        return null;
    }

    /**
     * Normalize scalar values into strings with a default fallback.
     *
     * @param  mixed  $value  Raw scalar value from block data
     * @param  string  $default  Fallback value for unsupported types
     * @return string Normalized scalar string
     */
    private function normalizeScalarToString(
        mixed $value,
        string $default = ''
    ): string {
        // Return string values directly.
        if (\is_string($value)) {
            return $value;
        }

        // Convert integer values into strings.
        if (\is_int($value)) {
            return (string) $value;
        }

        // Convert float values into strings.
        if (\is_float($value)) {
            return (string) $value;
        }

        // Return fallback for unsupported value types.
        return $default;
    }
}
