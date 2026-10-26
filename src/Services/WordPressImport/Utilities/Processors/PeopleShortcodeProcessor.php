<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors;

use Illuminate\Support\Facades\Log;
use Statamic\Fieldtypes\RowId;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\Traits\DefaultTiptapHtmlTrait;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\TaxonomySlugProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\TermMapBuilder;

/**
 * Processes WordPress show-people and show-team shortcodes into Statamic people
 * Bard sets.
 *
 * Handles parsing of [show-people ...] and [show-team ...] shortcode attributes
 * into Statamic-compatible people Bard set configurations for Bard fields.
 * Creates people_group taxonomy terms using the DRY approach via
 * BaseEntryCreator, tracks terms for validation by TaxonomyValidator, and maps
 * category slugs to full hierarchical paths.
 *
 * Taxonomy Handle Mapping:
 *   - WordPress Source: 'uamspeople-categories' (for show-people) or
 *     'tshowcase-categories' (for show-team) (from XML domain attribute)
 *   - Statamic Target: 'people_groups' (for taxonomy operations and lookups)
 */
class PeopleShortcodeProcessor implements ShortcodeProcessorInterface
{
    /**
     * Provides default implementation of toTiptapHtml method.
     *
     * This trait handles the standard conversion of shortcode data to
     * Tiptap-compatible HTML, including content processing and HTML
     * structure generation.
     */
    use DefaultTiptapHtmlTrait;

    /**
     * WordPress taxonomy handle for this shortcode type.
     *
     * @var string The WordPress taxonomy handle (e.g., 'uamspeople-categories', 'tshowcase-categories')
     */
    private string $wordpressTaxonomyHandle;

    /**
     * The `order_by` values the host app's People Showcase block offers.
     *
     * Read from `uamsweb_common_people_block_set.yaml` in `uams-statamic`. `date` left
     * the list when people became undated (`UAMS-Web/uams-statamic#2552`).
     *
     * @var list<string>
     */
    private const array ORDER_BY_VALUES = ['none', 'title', 'sortpriority', 'sorttext', 'modified'];

    /**
     * WordPress `orderby` values with a same-meaning `order_by` value in the host app.
     *
     * The plugin sorts `none` by `menu_order`, so `menu_order` is the same order.
     *
     * @var array<string, string>
     */
    private const array ORDER_BY_ALIASES = ['menu_order' => 'none'];

    /**
     * The `order_by` value a missing `orderby` imports as.
     */
    private const string ORDER_BY_DEFAULT = 'sortpriority';

    /**
     * The `order_by` value an unsupported `orderby` imports as.
     *
     * The host block's sort `switch` sends any value it does not list to `order`, the
     * same sort as `none`, so this keeps what such a block already renders.
     */
    private const string ORDER_BY_UNSUPPORTED = 'none';

    /**
     * Array to store terms and their associated page slugs.
     *
     * @var array<string, list<string>>
     */
    private static array $termsUsed = [];

    /**
     * Constructor to configure the processor for a specific shortcode type.
     *
     * @param  string  $shortcodeName  The shortcode name (e.g., 'show-people', 'show-team')
     */
    public function __construct(
        string $shortcodeName = 'show-people'
    ) {
        // ---
        // Configure taxonomy handle based on shortcode name
        // ---

        // Set WordPress taxonomy handle based on shortcode type
        $this->wordpressTaxonomyHandle = ($shortcodeName === 'show-team')
            ? 'tshowcase-categories'
            : 'uamspeople-categories';
    }

    /**
     * Normalize attribute values (trim and lowercase) for term and config matching.
     *
     * Trims whitespace and lowercases string values so category and other
     * slug-like values (e.g., layout, style) match term maps and config regardless
     * of source casing or surrounding spaces (e.g., category='pulmonology-fellows '
     * is treated as 'pulmonology-fellows').
     *
     * @param  array<int|string, mixed>  $attributes  Parsed shortcode attributes
     * @return array<int|string, mixed> Attributes with string values trimmed and lowercased
     */
    private function normalizeAttributeValuesToLowercase(array $attributes): array
    {
        // Initialize an empty array to store the normalized attributes
        $normalized = [];

        // Loop through each attribute to normalize its value
        foreach ($attributes as $key => $value) {
            // Trim and lowercase the value if it is a string
            $normalized[$key] = \is_string($value)
                ? \mb_strtolower(\trim($value), 'UTF-8')
                : $value;
        }

        // Return the normalized attributes
        return $normalized;
    }

    /**
     * Builds a map of term slugs to their full hierarchical slugs using the
     * centralized TermMapBuilder.
     *
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term
     *                                           objects from XML
     * @return array<string, array{full_slug: string, parent: mixed, name: mixed}>
     *                                                                             Slug map
     */
    private function buildTermMap(
        array $terms
    ): array {
        // ---
        // Use the centralized TermMapBuilder to build the proper hierarchical term map.
        //
        // Note: Uses the configured WordPress taxonomy handle for mapping.
        // ---
        $idBasedTermMap = TermMapBuilder::buildTermMap(
            $terms,
            [
                $this->wordpressTaxonomyHandle,
            ]
        );

        // Convert from ID-based map to slug-based map for backward compatibility
        $slugBasedTermMap = [];
        foreach ($idBasedTermMap as $termData) {
            // Read the normalized slug value from the current term data.
            $slug = $termData['term_slug'];
            $slugBasedTermMap[$slug] = [
                'full_slug' => $termData['full_slug'],
                'parent' => $termData['term_parent'],
                'name' => $termData['term_name'],
            ];
        }

        // Clean up variables to free up memory
        unset($idBasedTermMap);

        // Return the slug-based term map
        return $slugBasedTermMap;
    }

    /**
     * Read a shortcode attribute as a string value.
     *
     * @param  array<int|string, mixed>  $attributes  Parsed shortcode attributes
     * @param  string  $key  Attribute key to read
     * @param  string  $default  Fallback value when the attribute is not a string
     * @return string The validated string attribute value
     */
    private function getStringAttribute(
        array $attributes,
        string $key,
        string $default = ''
    ): string {
        // Return the string value when available.
        if (isset($attributes[$key]) && \is_string($attributes[$key])) {
            return $attributes[$key];
        }

        // Fall back when the attribute is missing or not a string.
        return $default;
    }

    /**
     * Map a WordPress `orderby` attribute to an `order_by` value the host app offers.
     *
     * A supported value passes through, and `menu_order` maps to `none`. Anything else
     * imports as `none` and is logged, which is how the host block already renders a
     * value it does not list. That includes `date`: the plugin sorted it newest first
     * on the post date, and the undated host app has no date order, so no supported
     * value reproduces it (wordpress-importer-archive#1233).
     *
     * @param  string  $orderBy  The shortcode's `orderby` attribute, or an empty string
     * @param  string  $site  The site handle for context
     * @param  ?string  $pageSlug  The slug of the page being processed
     * @return string A supported `order_by` value
     */
    private function resolveOrderBy(
        string $orderBy,
        string $site,
        ?string $pageSlug
    ): string {
        // A missing attribute takes the default without a warning
        if ($orderBy === '') {
            return self::ORDER_BY_DEFAULT;
        }

        // Pass a supported value through, or map it to its host equivalent
        if (\in_array($orderBy, self::ORDER_BY_VALUES, true)) {
            return $orderBy;
        }

        if (isset(self::ORDER_BY_ALIASES[$orderBy])) {
            return self::ORDER_BY_ALIASES[$orderBy];
        }

        // Enhance log context with entry information
        $logData = LogContextHelper::enhanceWithEntryContext(
            [
                'site' => $site,
                'page_slug' => $pageSlug ?? 'none',
                'orderby' => $orderBy,
                'order_by' => self::ORDER_BY_UNSUPPORTED,
            ]
        );

        // Log the unsupported value and the default it imports as
        Log::warning(
            LogContextHelper::formatMessageWithEntry(
                "PeopleShortcodeProcessor::resolveOrderBy: orderby='$orderBy' has no People Showcase equivalent, importing as '".self::ORDER_BY_UNSUPPORTED."'",
                $logData['page_url']
            ),
            $logData['context']
        );

        return self::ORDER_BY_UNSUPPORTED;
    }

    /**
     * Read a shortcode attribute as an integer value.
     *
     * @param  array<int|string, mixed>  $attributes  Parsed shortcode attributes
     * @param  string  $key  Attribute key to read
     * @param  int  $default  Fallback value when the attribute is not numeric
     * @return int The validated integer attribute value
     */
    private function getIntegerAttribute(
        array $attributes,
        string $key,
        int $default = 0
    ): int {
        // Return the value directly when it is already an integer.
        if (isset($attributes[$key]) && \is_int($attributes[$key])) {
            return $attributes[$key];
        }

        // Convert numeric strings to integers for shortcode compatibility.
        if (
            isset($attributes[$key])
            && \is_string($attributes[$key])
            && \is_numeric($attributes[$key])
        ) {
            return (int) $attributes[$key];
        }

        // Fall back when the attribute is missing or invalid.
        return $default;
    }

    /**
     * Parse a WordPress show-people shortcode into a Statamic people Bard set.
     *
     * Maps category slugs to full hierarchical paths and configures the Bard set
     * with fields including order_by for sorting, derived from the WordPress
     * shortcode's orderby attribute.
     *
     * @param  array<int|string, mixed>  $attributes  Parsed shortcode attributes
     * @param  string  $site  The site handle for context
     * @param  AssetImporter  $assetImporter  Instance for importing linked files (unused)
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects from XML
     * @param  ?string  $pageSlug  The slug of the page being processed
     * @return array<int|string, mixed> The Statamic people Bard set configuration
     */
    public function parse(
        array $attributes,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $terms = [],
        ?string $pageSlug = null
    ): array {
        // Normalize attribute values to lowercase for term and config matching
        $attributes = $this->normalizeAttributeValuesToLowercase($attributes);

        // ---
        // Build term map for people_group taxonomy.
        //
        // Note: This uses the Statamic taxonomy handle, not the WordPress source taxonomy.
        // ---
        $termMap = $this->buildTermMap($terms);

        // Track terms used by this shortcode
        // Extract the category attribute from the shortcode attributes
        $category = $this->getStringAttribute(
            $attributes,
            'category'
        );

        // Split the category string into an array and filter out empty values
        $categories = \array_filter(
            \explode(
                ',',
                $category
            ),
            static fn (string $value): bool => $value !== ''
        );

        // Determine if multiple groups are being used
        $multipleGroups = \count($categories) > 1;

        // Map categories to site-specific slugs
        // Initialize an empty array to store the site-specific categories
        $siteSpecificCategories = [];

        // Loop through each category term to process it
        foreach ($categories as $term) {
            // Trim whitespace from term slug before lookup
            $term = \trim($term);

            // Check if term exists in term map
            if (! isset($termMap[$term])) {
                // Only log warning if term map is not empty (terms were found)
                if ($termMap !== []) {
                    // Enhance log context with entry information
                    $logData = LogContextHelper::enhanceWithEntryContext(
                        [
                            'site' => $site,
                            'page_slug' => $pageSlug ?? 'none',
                            'term_slug' => $term,
                            'available_term_slugs' => \array_keys($termMap),
                        ]
                    );

                    // Log warning about missing term in taxonomy
                    Log::warning(
                        LogContextHelper::formatMessageWithEntry(
                            "PeopleShortcodeProcessor::parse: Term slug '$term' not found in term map for people_group taxonomy",
                            $logData['page_url']
                        ),
                        $logData['context']
                    );

                    // Display a warning message to the user
                    $output->writeln("<comment>Warning: People group term '$term' not found - skipping</comment>");
                }

                // Continue to the next category
                continue;
            }

            // Extract the full slug from the term map
            $fullSlug = $termMap[$term]['full_slug'];

            // Create site-specific slug for the Bard set configuration
            $siteSpecificSlug = TaxonomySlugProcessor::addSitePrefixToSlug(
                $fullSlug,
                $site
            );

            // Add the site-specific slug to the categories array
            $siteSpecificCategories[] = $siteSpecificSlug;

            // ---
            // Queue this people_group term for a single batched creation pass run
            // after all pages are processed (see EntryCreator::createEntries, which
            // calls flushDeferredTaxonomyTerms() before validateTaxonomies()).
            //
            // Creating each term inline here previously routed through
            // BaseEntryCreator::createTaxonomyTerm(), whose per-term find()/save()
            // interleaving forced a cold Stache associations rebuild on every term
            // (~7–9s each when people_groups is attached to the people collection).
            // Queuing lets createTaxonomyTermsFromStoredData() preload existing terms
            // once and save with index updates paused — the same fast path the
            // upfront post/people imports already use. The shared queue is taxonomy-
            // and shortcode-agnostic, so every on-demand caller benefits.
            //
            // Always uses 'people_groups' as the Statamic taxonomy handle.
            // ---
            BaseEntryCreator::queueTaxonomyTermFromMap(
                $term,
                $termMap,
                'people_groups',
                $site,
                'name'
            );

            // Check if the page slug is set and the term is set
            if (
                $pageSlug !== null && $pageSlug !== ''
                && $fullSlug !== ''
            ) {
                self::$termsUsed[$fullSlug] ??= [];

                // Check if the page slug is not in the terms used array
                if (
                    ! \in_array(
                        $pageSlug,
                        self::$termsUsed[$fullSlug],
                        true
                    )
                ) {
                    // Add the page slug to the terms used array
                    self::$termsUsed[$fullSlug][] = $pageSlug;
                }
            }
        }

        // Extract the display attribute from the attributes
        $display = $this->getStringAttribute(
            $attributes,
            'display'
        );

        // Extract the display items from the display attribute
        $displayItems = \array_filter(
            \explode(
                ',',
                $display
            ),
            static fn (string $value): bool => $value !== ''
        );

        // Initialize an empty array to store the display detail
        $displayDetail = [];

        // Initialize an empty array to store the valid display options
        $validDisplayOptions = [
            'name',
            'photo',
            'freehtml',
            'position',
            'email',
            'location',
            'telephone',
            'fax',
            'website',
            'deptweb',
            'triweb',
            'youtube',
        ];

        // Initialize groups filter value
        $groupsFilter = 'inactive';

        // Define valid filter types for groups_filter
        $validFilterTypes = [
            'filter',
            'enhance-filter',
            'isotope-filter',
            'dropdown-filter',
        ];

        // Loop through each display item to validate it
        foreach ($displayItems as $item) {
            // Check if the item is a valid filter type
            if (
                \in_array(
                    $item,
                    $validFilterTypes,
                    true
                )
            ) {
                // Set the groups filter value
                $groupsFilter = $item;
            } elseif (
                \in_array(
                    $item,
                    $validDisplayOptions,
                    true
                )
            ) { // Check if the item is in the valid display options
                // Add the item to the display detail array
                $displayDetail[] = $item;
            }
        }

        // Extract the url attribute from the attributes
        $url = $this->getStringAttribute(
            $attributes,
            'url'
        );

        // Initialize entry link query to false
        $entryLinkQuery = false;

        // Initialize an empty array to store the entry link url
        $entryLinkUrl = null;

        // Initialize entry link target to false
        $entryLinkTarget = false;

        // Process the URL attribute to determine link behavior
        switch ($url) {
            // Handle active link to entry in same window
            case 'active':
                // Enable entry link query
                $entryLinkQuery = true;

                // Set link URL to entry
                $entryLinkUrl = 'entry';

                // Set target to same window
                $entryLinkTarget = false;

                // Exit the switch statement
                break;

                // Handle active link to entry in new window
            case 'active_new':
                // Enable entry link query
                $entryLinkQuery = true;

                // Set link URL to entry
                $entryLinkUrl = 'entry';

                // Set target to new window
                $entryLinkTarget = true;

                // Exit the switch statement
                break;

                // Handle active link to clinical profile in same window
            case 'active_custom':
                // Enable entry link query
                $entryLinkQuery = true;

                // Set link URL to clinical profile
                $entryLinkUrl = 'clinical_profile';

                // Set target to same window
                $entryLinkTarget = false;

                // Exit the switch statement
                break;

                // Handle active link to clinical profile in new window
            case 'active_custom_new':
                // Enable entry link query
                $entryLinkQuery = true;

                // Set link URL to clinical profile
                $entryLinkUrl = 'clinical_profile';

                // Set target to new window
                $entryLinkTarget = true;

                // Exit the switch statement
                break;

                // Handle other URL types (no link)
            case 'active_user':
            case 'full_image':
            case 'lightbox':
            default:
                // Disable entry link query
                $entryLinkQuery = false;

                // Set link URL to null
                $entryLinkUrl = null;

                // Set target to same window
                $entryLinkTarget = false;

                // Exit the switch statement
                break;
        }

        // Build the values array for the Statamic people Bard set
        // Use site-specific category values when terms are available
        $groupValue = $multipleGroups ? $siteSpecificCategories : ($siteSpecificCategories[0] ?? null);

        // Fallback to original category values if no site-specific terms were found.
        // An empty $groupValue can be [] (multiple-groups branch) or null
        // (single-group branch), so both cases must be handled while preserving
        // the single-vs-multiple distinction.
        if (($groupValue === [] || $groupValue === null) && $categories !== []) {
            // Use original category values as fallback when site-specific terms are unavailable
            $groupValue = $multipleGroups ? $categories : \reset($categories);
        }

        $values = [
            'type' => 'people',
            'multiple_groups_selection' => $multipleGroups,
            $multipleGroups ? 'group_multiple' : 'group_single' => $groupValue,
            'id_include' => $this->getIntegerAttribute(
                $attributes,
                'ids'
            ),
            'id_exclude' => $this->getIntegerAttribute(
                $attributes,
                'exclude'
            ),
            'order_by' => $this->resolveOrderBy(
                $this->getStringAttribute($attributes, 'orderby'),
                $site,
                $pageSlug
            ),
            'limit' => $this->getIntegerAttribute(
                $attributes,
                'limit'
            ),
            'pagination' => false,
            'display_detail' => $displayDetail !== [] ? $displayDetail : ['name', 'photo', 'position'],
            'entry_link_query' => $entryLinkQuery,
            'entry_link_url' => $entryLinkUrl,
            'entry_link_target' => $entryLinkTarget,
            'load_a_layout_preset' => 'uams',
            'layout' => $this->getStringAttribute(
                $attributes,
                'layout',
                'grid'
            ),
            'columns' => 1,
            'groups_filter' => $groupsFilter,
            'theme' => 'white-card-theme',
            'composition' => 'img-left',
            'image_shape' => 'img-square',
            'image_effect' => false,
            'text_alignment' => 'text-left',
        ];

        // Build the final result array for the Statamic Bard set
        $result = [
            'type' => 'set',
            'attrs' => [
                'id' => (new RowId)->generate(),
                'values' => $values,
            ],
        ];

        // Clean up variables to free up memory
        unset(
            $termMap,
            $category,
            $categories,
            $multipleGroups,
            $siteSpecificCategories,
            $display,
            $displayItems,
            $displayDetail,
            $validDisplayOptions,
            $validFilterTypes,
            $groupsFilter,
            $url,
            $entryLinkQuery,
            $entryLinkUrl,
            $entryLinkTarget,
            $values
        );

        // Return the completed result array
        return $result;
    }

    /**
     * Get the terms used by show-people shortcodes with their page slugs.
     *
     * @return array<int|string, mixed> Associative array of terms and page slugs
     */
    public static function getTermsUsed(): array
    {
        return self::$termsUsed;
    }

    /**
     * Reset the terms used array (for testing or cleanup).
     */
    public static function resetTermsUsed(): void
    {
        // Clear the terms used array
        self::$termsUsed = [];
    }

    /**
     * Build a page builder block for a show-people shortcode.
     *
     * @param  array<int|string, mixed>  $attributes  Parsed shortcode attributes
     * @param  string  $site  The site handle for context
     * @param  AssetImporter  $assetImporter  Instance for importing linked files (unused)
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects from XML
     * @param  ?string  $pageSlug  The slug of the page being processed
     * @return array<int|string, mixed> The page builder block structure
     */
    public function toPageBuilderBlock(
        array $attributes,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $terms = [],
        ?string $pageSlug = null
    ): array {
        // Normalize attribute values to lowercase for term and config matching
        $attributes = $this->normalizeAttributeValuesToLowercase($attributes);

        // Parse the shortcode attributes to get the Bard set
        $set = $this->parse(
            $attributes,
            $site,
            $assetImporter,
            $output,
            $terms,
            $pageSlug
        );

        // Read the values array from attrs when present and well-formed.
        $values = $set;
        if (isset($set['attrs']) && \is_array($set['attrs'])) {
            $attrs = $set['attrs'];
            if (isset($attrs['values']) && \is_array($attrs['values'])) {
                $values = $attrs['values'];
            }
        }

        // Return the page builder block structure
        return [
            'id' => (new RowId)->generate(),
            'type' => 'uamsweb_block_show-people',
            'enabled' => true,
            'category' => $attributes['category'] ?? null,
            'order_by' => $values['order_by'] ?? 'sorttext',
            'layout' => $values['layout'] ?? 'grid',
        ];
    }
}
