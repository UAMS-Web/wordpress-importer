<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities;

use Statamic\Fieldtypes\RowId;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;

/**
 * Maps WordPress metadata keys to Statamic fields.
 *
 * Handles direct mappings, special cases, and complex field transformations
 * (e.g., header_hero slides) for Statamic entries or global sets.
 */
class FieldMapper
{
    /**
     * Mapping of WordPress meta keys to Statamic field names for direct import.
     *
     * @var array<int|string, mixed>
     */
    // Keys are looked up after their leading underscore is stripped, so each entry
    // also maps its underscored form. A property initializer is never reported as an
    // executed line; removing an entry by hand fails "maps the direct keys, with or
    // without a leading underscore" in FieldMapperTest.
    // @pest-mutate-ignore: RemoveArrayItem
    protected $directMapMetaKeys = [
        'genesis_scripts' => 'scripts_head',
        'genesis_scripts_body' => 'scripts_body',
        'genesis_scripts_body_position' => 'scripts_body_position',
        'seopress_robots_canonical_meta' => 'robots_canonical_meta',
    ];

    /**
     * Map WordPress metadata to Statamic fields.
     *
     * Processes a key-value array of metadata, mapping it to the provided Statamic
     * data array.
     *
     * @param  array<int|string, mixed>  $metaData  Associative array of WordPress meta keys and values
     * @param  array<int|string, mixed>  &$data  The Statamic entry or global set data array to populate
     * @param  string  $postType  The WordPress post type (e.g., 'page', 'post')
     * @param  XmlParser  $xmlParser  The XML parser instance for accessing the full XML
     * @param  AssetImporter  $assetImporter  The asset importer instance for handling files and images
     * @param  array<int|string, mixed>  $attachments  Array of attachment items from the XML
     * @param  OutputInterface  $output  Console output interface for logging
     * @return array<int|string, mixed> Array of mapped meta keys
     */
    public function mapFields(
        array $metaData,
        array &$data,
        string $postType,
        XmlParser $xmlParser,
        AssetImporter $assetImporter,
        array $attachments,
        OutputInterface $output
    ): array {
        // Initialize mapped keys array
        $mappedKeys = [];

        // Initialize hero slides array
        $heroSlides = [];

        // ---
        // WordPress stores hero data as flat meta keys (e.g., page_hero_hero_0_hero_title,
        // page_hero_hero_1_hero_image). Aggregate those keys into $heroSlides by slide
        // index so processHeroFields() can handle each slide as a unit.
        // ---

        // Loop through each meta data item
        foreach ($metaData as $key => $value) {
            // Skip non-string meta keys for hero pattern extraction
            if (! \is_string($key)) {
                continue;
            }

            // Check if key matches hero slide field pattern
            if (
                \preg_match(
                    '/^page_hero_hero_(\d+)_hero_(.+)$/',
                    $key,
                    $matches
                ) === 1
            ) {
                // Extract hero index from matched pattern, so `01` and `1` are one slide
                $index = (int) $matches[1];

                // Extract hero field from matched pattern
                $field = $matches[2];

                // Store hero field value in hero slides array
                $heroSlides[$index][$field] = $value;

                // Track this key as mapped
                $mappedKeys[] = $key;
            }
        }

        // ---
        // Map hero slides to header_hero
        // ---

        // Loop through each hero slide
        foreach ($heroSlides as $index => $slide) {
            $siteHandle = self::extractScalarString($data['site'] ?? '');

            // The site is the same for every slide, so stopping here or skipping each
            // slide in turn processes none of them either way
            if ($siteHandle === '') {
                // @pest-mutate-ignore: ContinueToBreak
                continue;
            }

            // Process hero fields using shared method
            $this->processHeroFields(
                $slide,
                $index,
                $data,
                $siteHandle,
                $assetImporter,
                $attachments,
                $output
            );
        }

        // ---
        // Map all remaining meta to Statamic fields. Hero keys are skipped (handled
        // above); then ACF references, direct-map keys, and special-case keys are
        // processed.
        // ---

        // Loop through each meta data item
        foreach ($metaData as $key => $value) {
            // Skip non-string meta keys for pattern and switch matching
            if (! \is_string($key)) {
                continue;
            }

            // Normalize mixed meta values to scalar strings when needed
            $valueString = self::extractScalarString($value);

            // Check if key matches hero slide field pattern. A hero key matches no
            // case below either, so this skip only saves the work.
            // @pest-mutate-ignore: IncrementInteger
            if (
                \preg_match(
                    '/^page_hero_hero_\d+_hero_/',
                    $key
                ) === 1
            ) {
                // Skip this field reference - the actual value should come from post_hide_author
                continue;
            }

            // ---
            // Check if this is a WordPress ACF field reference that contains a field name
            // instead of the actual value
            // ---
            if (
                \str_starts_with(
                    $key,
                    '_'
                )
                && \str_starts_with(
                    $valueString,
                    'field_'
                )
                && \substr(
                    $key,
                    1
                ) === \substr(
                    $valueString,
                    7
                ) // Remove '_' from key and 'field_' from value, then compare
            ) {
                // Track this key as processed to avoid duplicate processing
                $mappedKeys[] = $key;

                // Skip this field reference - the actual value should come from post_hide_author
                continue;
            }

            // Strip leading underscore from key to get canonical meta key name
            $normalizedKey = \ltrim(
                $key,
                '_'
            );

            // Check if key is in direct map meta keys
            if (isset($this->directMapMetaKeys[$normalizedKey])) {
                $targetField = $this->directMapMetaKeys[$normalizedKey];

                // Skip a target that is not a field handle, which only a subclass
                // overriding the map can supply
                if (! \is_string($targetField) || $targetField === '') {
                    continue;
                }

                // Use Code fieldtype structure for script fields (matches PageEntryCreator and
                // Statamic code field persistence)
                if (
                    $targetField === 'scripts_head'
                    || $targetField === 'scripts_body'
                ) {
                    $data[$targetField] = [
                        'code' => $value ?? '',
                        'mode' => 'htmlmixed',
                    ];
                } else {
                    $data[$targetField] = $value;
                }

                // Track this key as mapped
                $mappedKeys[] = $key;

                // Skip this field reference - the actual value should come from post_hide_author
                continue;
            }

            // Handle special-case meta keys that require custom mapping
            switch ($key) {
                // Handle post hide author field
                case 'post_hide_author':
                case '_post_hide_author':
                    // ---
                    // Map post_hide_author value to Statamic hide_creator_query field.
                    // WordPress stores '1'/'0'; cast to bool for Statamic true_false field.
                    // ---
                    $data['hide_creator_query'] = $value === '1';

                    // Track this key as mapped
                    $mappedKeys[] = $key;

                    // Exit this case
                    break;

                    // Handle SEO robots boolean fields
                case 'seopress_robots_index_meta':
                case '_seopress_robots_index_meta':
                case 'seopress_robots_follow_meta':
                case '_seopress_robots_follow_meta':
                case 'seopress_robots_odp_meta':
                case '_seopress_robots_odp_meta':
                case 'seopress_robots_imageindex_meta':
                case '_seopress_robots_imageindex_meta':
                case 'seopress_robots_archive_meta':
                case '_seopress_robots_archive_meta':
                case 'seopress_robots_snippet_meta':
                case '_seopress_robots_snippet_meta':
                    // Determine the target Statamic field for the current SEO robots key
                    $targetSeoRobotsField = match ($normalizedKey) {
                        'seopress_robots_index_meta' => 'robots_index_meta',
                        'seopress_robots_follow_meta' => 'robots_follow_meta',
                        'seopress_robots_odp_meta' => 'robots_odp_meta',
                        'seopress_robots_imageindex_meta' => 'robots_imageindex_meta',
                        'seopress_robots_archive_meta' => 'robots_archive_meta',
                        'seopress_robots_snippet_meta' => 'robots_snippet_meta',
                        default => null,
                    };

                    // Check if a target field was resolved for this SEO robots key
                    if ($targetSeoRobotsField !== null) {
                        // Cast WordPress '1'/'0' values to booleans for Statamic true_false fields
                        $data[$targetSeoRobotsField] = $value === '1';
                    }

                    // Track this key as mapped
                    $mappedKeys[] = $key;

                    // Exit this case
                    break;

                    // Handle page title options field
                case 'page_title_options':
                    // Map title options to Statamic format
                    $data['title_options'] = $this->mapTitleOptions($valueString);

                    // Track this key as mapped
                    $mappedKeys[] = $key;

                    // Exit this case
                    break;

                    // Handle page subsection field
                case 'page_subsection':
                    // ---
                    // Set subsection value, defaulting to false if empty or not '1'. WordPress uses
                    // '1' for true, empty string or other values for false.
                    // ---
                    $data['subsection'] = $value === '1';

                    // Track this key as mapped
                    $mappedKeys[] = $key;

                    // Exit this case
                    break;

                    // Handle page navigation alt name field
                case 'page_nav_alt_name':
                    // Strip HTML tags from navigation alt name
                    $data['nav_alt_name'] = HtmlTagStripper::strip($valueString);

                    // Track this key as mapped
                    $mappedKeys[] = $key;

                    // Exit this case
                    break;

                    // Handle page hide from menu field
                case 'page_hide_from_menu':
                    // ---
                    // Set hide from menu value; preserve true if already set by 'parent' meta.
                    // WordPress uses '1' for true, empty string or other values for false.
                    // ---
                    // The cast only satisfies PHPStan's strict boolean rule; `||` yields a
                    // bool whichever way
                    // @pest-mutate-ignore: RemoveBooleanCast
                    $data['hide_from_menu'] = (bool) ($data['hide_from_menu'] ?? false) || $value === '1';

                    // Track this key as mapped
                    $mappedKeys[] = $key;

                    // Exit this case
                    break;

                    // Handle parent meta: when 'on', hide page from menu (WordPress “parent” page)
                case 'parent':
                    // ---
                    // When parent meta is 'on', set hide from menu so the page does not
                    // appear in navigation (WordPress “parent” placeholder page).
                    // ---
                    if ($value === 'on') {
                        $data['hide_from_menu'] = true;
                    }

                    // Track this key as mapped
                    $mappedKeys[] = $key;

                    // Exit this case
                    break;

                    // Handle page hide child menu field
                case 'page_hide_child_menu':
                    // ---
                    // Set hide child menu value, defaulting to false if empty or not '1'. WordPress
                    // uses '1' for true, empty string or other values for false.
                    // ---
                    $data['hide_child_menu'] = $value === '1';

                    // Track this key as mapped
                    $mappedKeys[] = $key;

                    // Exit this case
                    break;

                    // Handle page hide breadcrumbs field
                case 'page_hide_breadcrumbs':
                    // ---
                    // Set hide breadcrumbs value; preserve true if already set by
                    // 'breadcrumb' meta. WordPress uses '1' for true.
                    // ---
                    // The cast only satisfies PHPStan, as for hide_from_menu
                    // @pest-mutate-ignore: RemoveBooleanCast
                    $data['hide_breadcrumbs'] = (bool) ($data['hide_breadcrumbs'] ?? false) || $value === '1';

                    // Track this key as mapped
                    $mappedKeys[] = $key;

                    // Exit this case
                    break;

                    // Handle breadcrumb meta: when 'on', hide breadcrumbs in Statamic
                case 'breadcrumb':
                    // ---
                    // When breadcrumb meta is 'on', set hide breadcrumbs so the page
                    // does not show breadcrumbs.
                    // ---
                    if ($value === 'on') {
                        $data['hide_breadcrumbs'] = true;
                    }

                    // Track this key as mapped
                    $mappedKeys[] = $key;

                    // Exit this case
                    break;

                    // Handle include boilerplate field
                case 'include_boilerplate':
                    // ---
                    // Set boilerplate query value, defaulting to false if empty or not '1'. WordPress
                    // uses '1' for true, empty string or other values for false.
                    // ---
                    $data['boilerplate_query'] = $value === '1';

                    // Track this key as mapped
                    $mappedKeys[] = $key;

                    // Exit this case
                    break;

                    // Handle media contact field
                case 'media_contact':
                    // Normalize value to handle potential null cases defensively
                    $normalizedValue = $valueString;

                    // ---
                    // Only set media contact if value is not empty and not the string 'null'
                    // ---

                    // Check if the normalized value is not empty and not the string 'null'
                    if (
                        $normalizedValue !== ''
                        && $normalizedValue !== 'null'
                    ) {
                        // Strip 'Media ' prefix to match fieldset key format
                        $strippedContact = \preg_replace(
                            '/^Media\s+/',
                            '',
                            $normalizedValue
                        );

                        // Lowercase the remainder, or store an empty key when the
                        // replacement fails at a PCRE limit
                        $mediaContactKey = \strtolower($strippedContact ?? '');

                        // Set the mapped media contact key in entry data
                        $data['media_contact'] = $mediaContactKey;
                    }

                    // Track this key as mapped
                    $mappedKeys[] = $key;

                    // Exit this case
                    break;

                    // Handle news release PDF URL field
                case 'news_release_pdf_url':

                    // Handle news release PDF attachment ID field
                case 'news_release_pdf':
                    // ---
                    // This key is used in PostEntryCreator to determine the blueprint. It no
                    // longer drives a PDF import; the host owns that file (wordpress-importer-archive#620).
                    // Track as mapped to indicate it's addressed, even though the actual mapping
                    // happens in PostEntryCreator.
                    // ---
                    $mappedKeys[] = $key;

                    // Exit this case
                    break;

                    // Handle page description field
                case 'page_description':
                    // Normalize value to handle potential null cases defensively
                    $normalizedValue = $valueString;

                    // Normalize header_graphic container to an array payload
                    $headerGraphic = \is_array($data['header_graphic'] ?? null)
                        ? $data['header_graphic']
                        : [];

                    // Strip HTML tags from header graphic body, nullifying if empty
                    $headerGraphic['body'] = $normalizedValue === '' ? null : HtmlTagStripper::strip($normalizedValue);
                    $data['header_graphic'] = $headerGraphic;

                    // Track this key as mapped
                    $mappedKeys[] = $key;

                    // Exit this case
                    break;

                    // Handle page cover image field
                case 'page_cover_image':
                    $siteHandle = self::extractScalarString($data['site'] ?? '');

                    // Normalize header_graphic container to an array payload
                    $headerGraphic = \is_array($data['header_graphic'] ?? null)
                        ? $data['header_graphic']
                        : [];

                    // Import image with disabled upload handling
                    $headerGraphic['image'] = ImageProcessor::importImageWithDisabledUploadHandling(
                        $valueString,
                        $siteHandle,
                        $assetImporter,
                        $output,
                        $attachments,
                        'page cover image',
                        'FieldMapper::mapFields'
                    );
                    $data['header_graphic'] = $headerGraphic;

                    // Track this key as mapped
                    $mappedKeys[] = $key;

                    // Exit this case
                    break;

                    // Handle WordPress page template field
                case '_wp_page_template':
                    // Normalize value to handle potential null cases defensively
                    $normalizedValue = $valueString;

                    // ---
                    // Apply landing page blueprint/template only for these WordPress
                    // templates; otherwise keep basic page blueprint/template
                    // ---
                    // Strings on both sides, so strict and loose comparison agree
                    // @pest-mutate-ignore: TrueToFalse
                    $isLandingPageTemplate = \in_array(
                        $normalizedValue,
                        [
                            'templates/modules.php',
                            'templates/page_landing.php',
                            'templates/marketing.php',
                        ],
                        true
                    );

                    // Check if this is a landing page template
                    if ($isLandingPageTemplate) {
                        // Set blueprint to landing page
                        $data['blueprint'] = 'landing_page';

                        // Set template to landing page template
                        $data['template'] = 'pages/page_landing';
                    }

                    // Track this key as mapped
                    $mappedKeys[] = $key;

                    // Exit this case
                    break;

                    // Handle WordPress thumbnail ID field
                case '_thumbnail_id':
                    // Normalize value to handle potential null cases defensively
                    $thumbnailValue = $valueString;

                    $siteHandle = self::extractScalarString($data['site'] ?? '');

                    // Check if this is a page. An empty thumbnail imports nothing, so
                    // the value is not checked here.
                    if ($postType === 'page') {
                        // Import image with disabled upload handling
                        $featuredImagePath = ImageProcessor::importImageWithDisabledUploadHandling(
                            $thumbnailValue,
                            $siteHandle,
                            $assetImporter,
                            $output,
                            $attachments,
                            'featured image',
                            'FieldMapper::mapFields'
                        );

                        // ---
                        // Omit the handle when nothing landed, matching the gate
                        // `KbEntryCreator` has carried since wordpress-importer-archive#751. Both `null` and
                        // `''` are reachable: the uploads-disabled branch returns the
                        // attachment's URL as a string, so a row whose file has left
                        // the media library yields an empty one.
                        //
                        // `featured_image_16_9` is an asset field on 14 blueprints, and
                        // an empty string is not a thing to hand one. This gate only
                        // omits the key because wordpress-importer-archive#754 also removed the
                        // `'featured_image_16_9' => null` seed from `PageEntryCreator`'s
                        // initialisation array — with the seed in place, gating merely
                        // left the seeded null and the key stayed present.
                        //
                        // The saved entry is unchanged either way: `Entry::fileData()`
                        // discards `null` and `''` alike. The point is that `$data` never
                        // carries a meaningless value under an asset handle, and that the
                        // importer does not lean on Statamic's strip to launder its own
                        // output. Pinned in `PageFeaturedImageGateTest`, which asserts at
                        // this boundary because a host-app test reading the saved entry
                        // cannot distinguish the two (wordpress-importer-archive#754).
                        // ---
                        if ($featuredImagePath !== null && $featuredImagePath !== '') {
                            $data['featured_image_16_9'] = $featuredImagePath;
                        }

                        // ---
                        // Surface the photo credit the WordPress site displayed.
                        // Both conditions are required: the image has to have
                        // actually imported, and the resulting asset has to carry
                        // a `creator` — setting the toggle for an uncredited image
                        // writes something inert (wordpress-importer-archive#439).
                        // ---
                        if (
                            $featuredImagePath !== null
                            && $featuredImagePath !== ''
                            && FeaturedImageCreditResolver::hasCredit(
                                $thumbnailValue,
                                $attachments
                            )
                        ) {
                            $data['featured_image_credit_query'] = true;
                        }
                    }

                    // Track this key as mapped
                    $mappedKeys[] = $key;

                    // Exit this case
                    break;

                    // Handle landing page heading field
                case 'page_landing_page_heading':
                    // Normalize value to handle potential null cases defensively
                    $normalizedValue = $valueString;

                    // Normalize header_marketing_landing_page to an array payload
                    $landingHeader = \is_array($data['header_marketing_landing_page'] ?? null)
                        ? $data['header_marketing_landing_page']
                        : [];

                    // Strip HTML tags from landing page heading, nullifying if empty
                    $landingHeader['heading'] = $normalizedValue === '' ? null : HtmlTagStripper::strip($normalizedValue);
                    $data['header_marketing_landing_page'] = $landingHeader;

                    // Track this key as mapped
                    $mappedKeys[] = $key;

                    // Exit this case
                    break;

                    // Handle landing page description field
                case 'page_landing_page_description':
                    // Normalize value to handle potential null cases defensively
                    $normalizedValue = $valueString;

                    // Normalize header_marketing_landing_page to an array payload
                    $landingHeader = \is_array($data['header_marketing_landing_page'] ?? null)
                        ? $data['header_marketing_landing_page']
                        : [];

                    // Strip HTML tags from landing page body, nullifying if empty
                    $landingHeader['body'] = $normalizedValue === '' ? null : HtmlTagStripper::strip($normalizedValue);
                    $data['header_marketing_landing_page'] = $landingHeader;

                    // Track this key as mapped
                    $mappedKeys[] = $key;

                    // Exit this case
                    break;

                    // Handle landing page cover image field
                case 'page_landing_page_cover_image':
                    $siteHandle = self::extractScalarString($data['site'] ?? '');

                    // Normalize header_marketing_landing_page to an array payload
                    $landingHeader = \is_array($data['header_marketing_landing_page'] ?? null)
                        ? $data['header_marketing_landing_page']
                        : [];

                    // Import image with disabled upload handling
                    $landingHeader['image'] = ImageProcessor::importImageWithDisabledUploadHandling(
                        $valueString,
                        $siteHandle,
                        $assetImporter,
                        $output,
                        $attachments,
                        'landing page cover image',
                        'FieldMapper::mapFields'
                    );
                    $data['header_marketing_landing_page'] = $landingHeader;

                    // Track this key as mapped
                    $mappedKeys[] = $key;

                    // Exit this case
                    break;

                    // Handle landing page mobile cover image field
                case 'page_landing_page_cover_image_mobile':
                    // Normalize value to handle potential null cases defensively
                    $mobileImageValue = $valueString;

                    $siteHandle = self::extractScalarString($data['site'] ?? '');

                    // Normalize header_marketing_landing_page to an array payload
                    $landingHeader = \is_array($data['header_marketing_landing_page'] ?? null)
                        ? $data['header_marketing_landing_page']
                        : [];

                    // Check if mobile cover image value is provided
                    if ($mobileImageValue !== '') {
                        // Import image with disabled upload handling
                        $landingHeader['image_mobile'] = ImageProcessor::importImageWithDisabledUploadHandling(
                            $mobileImageValue,
                            $siteHandle,
                            $assetImporter,
                            $output,
                            $attachments,
                            'landing page mobile cover image',
                            'FieldMapper::mapFields'
                        );
                    }

                    $data['header_marketing_landing_page'] = $landingHeader;

                    // Track this key as mapped
                    $mappedKeys[] = $key;

                    // Exit this case
                    break;
            }
        }

        // ---
        // Add default header_marketing_landing_page for landing_page blueprint
        // ---

        // Check if blueprint is landing_page but header_marketing_landing_page was not set
        if (
            ($data['blueprint'] ?? null) === 'landing_page'
            && ! isset($data['header_marketing_landing_page'])
        ) {
            // Initialize default header_marketing_landing_page structure with null values
            $data['header_marketing_landing_page'] = [
                'heading' => null,
                'body' => null,
                'image' => null,
                'image_mobile' => null,
            ];
        }

        // Return the list of meta keys that were mapped
        return $mappedKeys;
    }

    /**
     * Processes hero field data and maps it to header_hero field format.
     *
     * Takes an array of hero field data (with normalized field names like 'heading',
     * 'body', etc.) and processes each field to populate the header_hero array. This
     * method is used both for processing page_hero fields from postmeta and for
     * processing hero data from ACF blocks or modules.
     *
     * @param  array<int|string, mixed>  $heroFields  Associative array of hero field names and values
     *                                                (e.g., ['heading' => '...', 'body' => '...'])
     * @param  int  $heroIndex  The index of the hero slide
     * @param  array<int|string, mixed>  &$data  The entry data array (passed by reference)
     * @param  string  $site  The site handle
     * @param  AssetImporter  $assetImporter  The asset importer instance
     * @param  array<int|string, mixed>  $attachments  Array of attachments
     * @param  OutputInterface  $output  The console output
     */
    public function processHeroFields(
        array $heroFields,
        int $heroIndex,
        array &$data,
        string $site,
        AssetImporter $assetImporter,
        array $attachments,
        OutputInterface $output
    ): void {
        // ---
        // Initialize header_hero array if not exists
        // ---

        // Check if header_hero array exists in data
        if (
            ! isset($data['header_hero'])
            || ! \is_array($data['header_hero'])
        ) {
            // Initialize header_hero array
            $data['header_hero'] = [];
        }

        // Initialize hero slide entry
        $data['header_hero'][$heroIndex] = [
            'id' => (new RowId)->generate(),
            'type' => 'new_set',
            'enabled' => true,
        ];

        // Loop through each field in the hero data
        foreach ($heroFields as $field => $value) {
            if (! \is_string($field)) {
                continue;
            }

            $valueString = self::extractScalarString($value);

            // Process each field based on its name
            switch ($field) {
                // Handle heading field
                case 'heading':
                    // Strip HTML tags from heading text
                    $data['header_hero'][$heroIndex]['heading'] = HtmlTagStripper::strip($valueString);

                    // Exit this case
                    break;

                    // Handle body field
                case 'body':
                    // Strip HTML tags from body text
                    $data['header_hero'][$heroIndex]['body'] = HtmlTagStripper::strip($valueString);

                    // Exit this case
                    break;

                    // Handle button text field
                case 'button_text':
                    // Strip HTML tags from button text
                    $data['header_hero'][$heroIndex]['button_text'] = HtmlTagStripper::strip($valueString);

                    // Exit this case
                    break;

                    // Handle button URL field
                case 'button_url':
                    // Extract original URL from link data
                    $originalUrl = LinkHelper::getUrl($value);

                    // Process URL through LinkProcessor to handle internal links and file imports
                    $data['header_hero'][$heroIndex]['button_url'] = LinkProcessor::processUrlString(
                        $originalUrl,
                        $site,
                        $assetImporter,
                        $output,
                        ProcessingContext::getStatamicEntryId(),
                        ProcessingContext::getCollectionHandle()
                    );

                    // Set the button target
                    $data['header_hero'][$heroIndex]['button_target'] = LinkHelper::getTarget($value);

                    // Exit this case
                    break;

                    // Handle button description field
                case 'button_description':
                    // Strip HTML tags from button link description
                    $data['header_hero'][$heroIndex]['button_link_description'] = HtmlTagStripper::strip($valueString);

                    // Exit this case
                    break;

                    // Handle enabled field (inverted from source disable for replicator sets)
                case 'disable':
                    // Set the enabled flag by inverting the source disable value
                    $data['header_hero'][$heroIndex]['enabled'] = $value !== '1';

                    // Exit this case
                    break;

                    // Handle background color field
                case 'background_color':
                    // Set the background color
                    $data['header_hero'][$heroIndex]['background_color'] = $valueString;

                    // Exit this case
                    break;

                    // Handle desktop image field
                case 'image_desktop':
                    // Import image with disabled upload handling
                    $data['header_hero'][$heroIndex]['image_desktop'] = ImageProcessor::importImageWithDisabledUploadHandling(
                        $valueString,
                        $site,
                        $assetImporter,
                        $output,
                        $attachments,
                        'header hero desktop image',
                        'FieldMapper::processHeroFields'
                    );

                    // Exit this case
                    break;

                    // Handle tablet image field
                case 'image_tablet':
                    // Check if tablet image value is provided
                    if ($valueString !== '') {
                        // Import image with disabled upload handling
                        $data['header_hero'][$heroIndex]['image_tablet'] = ImageProcessor::importImageWithDisabledUploadHandling(
                            $valueString,
                            $site,
                            $assetImporter,
                            $output,
                            $attachments,
                            'header hero tablet image',
                            'FieldMapper::processHeroFields'
                        );
                    }

                    // Exit this case
                    break;

                    // Handle mobile image field
                case 'image_mobile':
                    // Check if mobile image value is provided
                    if ($valueString !== '') {
                        // Import image with disabled upload handling
                        $data['header_hero'][$heroIndex]['image_mobile'] = ImageProcessor::importImageWithDisabledUploadHandling(
                            $valueString,
                            $site,
                            $assetImporter,
                            $output,
                            $attachments,
                            'header hero mobile image',
                            'FieldMapper::processHeroFields'
                        );
                    }

                    // Exit this case
                    break;

                    // Handle image alt text field
                case 'image_alt_text':
                    // Check if alt text value is provided
                    if ($valueString !== '') {
                        // Strip HTML tags from alt text
                        $data['header_hero'][$heroIndex]['image_alt_text'] = HtmlTagStripper::strip($valueString);
                    }

                    // Exit this case
                    break;
            }
        }
    }

    /**
     * Map WordPress page_title_options values to Statamic title_options values.
     *
     * @param  string  $value  The WordPress title option value
     * @return string The corresponding Statamic title option value
     */
    protected function mapTitleOptions(
        string $value
    ): string {
        // Convert WordPress title option value to Statamic title_options value
        return match ($value) {
            'none' => 'normal',
            'hidden' => 'hidden',
            'graphic' => 'graphic',
            'landingpage' => 'marketing-landing-page',
            'hero' => 'hero',
            default => 'normal',
        };
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
}
