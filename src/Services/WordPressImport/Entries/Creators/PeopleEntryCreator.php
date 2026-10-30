<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators;

use App\Fieldtypes\Phone;
use Illuminate\Support\Str;
use Statamic\Entries\Entry;
use Statamic\Fieldtypes\RowId;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\ContentStreamFactory;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\TermDestinationRegistry;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\Validators\PeopleGroupValidator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\FileProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\HtmlTagStripper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportProgress;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\TaxonomySlugProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\TermLookupHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\TextProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;

/**
 * Creates Statamic entries for WordPress 'uamspeople' and 'tshowcase' content
 * types.
 *
 * Handles mapping and creation of person entries into the 'people' collection,
 * including Bard parsing for bio fields, taxonomy validation for people_group,
 * and asset import for portraits.
 *
 * Taxonomy Handle Mapping:
 *   - WordPress Source: 'uamspeople-categories' or 'tshowcase-categories' (from
 *     XML domain attribute)
 *   - Statamic Target: 'people_groups' (for taxonomy operations and lookups)
 *
 * The code parses WordPress XML using the source taxonomy handle but performs
 * all Statamic operations using the target taxonomy handle.
 */
class PeopleEntryCreator extends BaseEntryCreator
{
    /**
     * Meta key prefix for this post type (e.g., '_ps' for uamspeople, '_ts' for
     * tshowcase).
     *
     * @var string The meta key prefix
     */
    private string $metaKeyPrefix;

    /**
     * WordPress taxonomy handle for this post type (e.g., 'uamspeople-categories',
     * 'tshowcase-categories').
     *
     * @var string The WordPress taxonomy handle
     */
    private string $wordpressTaxonomyHandle;

    /**
     * Stores normalized people group term data.
     *
     * @var list<array{site_specific: string, original: string, name: string}>
     *                                                                         Stored people group definitions
     */
    private array $siteSpecificPeopleGroups = [];

    /**
     * Constructor to configure the entry creator for a specific post type.
     *
     * @param  string  $postType  The WordPress post type (e.g., 'uamspeople', 'tshowcase')
     */
    public function __construct(
        /**
         * WordPress post type being processed (e.g., 'uamspeople', 'tshowcase').
         */
        private string $postType = 'uamspeople'
    ) {
        // ---
        // Configure meta key prefix and taxonomy handle based on post type
        // ---

        // Check if this is a tshowcase post type
        if ($this->postType === 'tshowcase') {
            // Set meta key prefix for tshowcase post type
            $this->metaKeyPrefix = '_ts';

            // Set WordPress taxonomy handle for tshowcase post type
            $this->wordpressTaxonomyHandle = 'tshowcase-categories';
        } else { // Otherwise
            // Set meta key prefix for uamspeople post type
            $this->metaKeyPrefix = '_ps';

            // Set WordPress taxonomy handle for uamspeople post type
            $this->wordpressTaxonomyHandle = 'uamspeople-categories';
        }
    }

    /**
     * Normalize a phone number using the Phone fieldtype.
     *
     * @param  string  $phoneNumber  The phone number to normalize
     * @return string The normalized phone number in E.164 format
     */
    private function normalizePhoneNumber(
        string $phoneNumber
    ): string {
        // Create a Phone fieldtype instance with default config for US national format
        $phoneFieldtype = $this->phoneFieldtype();

        // Configure phone fieldtype for US national format
        $config = [
            'preset' => 'tel-national',
            'country' => 'US',
        ];

        // Use the fieldtype's normalizeValue method to convert to E.164
        $normalized = $phoneFieldtype->normalizeValue(
            $phoneNumber,
            $config
        );

        // Clean up variables to free up memory
        unset(
            $phoneFieldtype,
            $config
        );

        // Return the normalized phone number in E.164 format
        return $normalized;
    }

    /**
     * The fieldtype that normalizes a phone number.
     *
     * The class is the host application's, so a standalone test supplies its own
     * through this seam to see the configuration it is given.
     *
     * @return Phone The fieldtype
     */
    protected function phoneFieldtype(): Phone
    {
        return new Phone;
    }

    /**
     * Builds the `job_title` replicator sets for a WordPress `position` postmeta value.
     *
     * `job_title` is a replicator on the `person` blueprint whose sets are grouped into
     * Academic and Institutional roles. WordPress carries no structure to drive the
     * structured sets (they need department-taxonomy resolution), so a free-form position
     * maps to the `free_text` set — `title` plus the replicator envelope. The set-group
     * key is a Control Panel affordance and is deliberately absent from the stored value,
     * matching entries authored in the app.
     *
     * @param  string  $position  Raw `position` postmeta value
     * @return list<array<string, mixed>> Replicator sets, empty when there is no title
     */
    public function buildJobTitleSets(
        string $position
    ): array {
        // Normalize surrounding whitespace before deciding whether a title exists
        $title = \trim($position);

        // Skip a value that carries no text so the field is omitted rather than empty
        if ($title === '') {
            return [];
        }

        // ---
        // Treated as a single role: splitting a multi-role string (e.g.
        // "Director, Pediatrics; Professor") is out of scope for wordpress-importer-archive#31, since the
        // WordPress value carries no delimiter contract to rely on.
        // ---
        return [
            [
                'id' => (new RowId)->generate(),
                'title' => $title,
                'type' => 'free_text',
                'enabled' => true,
            ],
        ];
    }

    /**
     * Lands an imported person video on a handle the `person` blueprint defines.
     *
     * The blueprint carries four prefixed pairs — `academic_`, `clinical_`,
     * `institutional_` and `research_` — and no bare `video_url`. WordPress records
     * no such context for `_psyoutube` or `_psvimeo`, so there is no correct
     * placement to recover and any routing is a guess. `institutional_` is the
     * recorded default (wordpress-importer-archive#501); the provenance note is what lets an editor tell a
     * default apart from a context that was actually read, so the two are written
     * together or not at all.
     *
     * YouTube wins when a person carries both keys, because it is by far the
     * commoner one. The runner-up is named in the note rather than dropped, so a
     * second URL stays recoverable from the entry.
     *
     * @param  array<int|string, mixed>  $data  The entry data to populate, by reference
     * @param  ?string  $youtubeUrl  The non-empty `_psyoutube` value, when present
     * @param  ?string  $vimeoUrl  The non-empty `_psvimeo` value, when present
     */
    public function applyPersonVideo(
        array &$data,
        ?string $youtubeUrl,
        ?string $vimeoUrl
    ): void {
        // Nothing to land when the person carries neither key
        if ($youtubeUrl === null && $vimeoUrl === null) {
            return;
        }

        // Prefer YouTube; Vimeo fills the slot only when there is no YouTube URL
        $videoUrl = $youtubeUrl ?? $vimeoUrl;

        // Resolve the title to a safe, non-empty string for link text generation
        $title = $data['title'] ?? null;
        $personTitle = \is_string($title) && $title !== ''
            ? $title
            : 'Untitled Person';

        // Set the video URL on the blueprint-defined institutional handle
        $data['institutional_video_url'] = $videoUrl;

        // Strip HTML tags from video link text (constructed with person's name)
        $data['institutional_video_link_text'] = HtmlTagStripper::strip(
            "Video Referencing {$personTitle}"
        );

        // Record that the context was defaulted rather than read from WordPress
        $note = 'Video imported to the Institutional context; WordPress recorded no '
            .'academic, clinical, institutional or research context for it.';

        // Name the runner-up so a second video is recoverable rather than dropped
        if ($youtubeUrl !== null && $vimeoUrl !== null) {
            $note .= ' A second video was not imported, because the blueprint carries '
                ."one URL per context: {$vimeoUrl}";
        }

        // Append the note to the entry's import provenance
        $this->appendImportNote(
            $data,
            $note
        );
    }

    /**
     * Appends a provenance note to the entry's `import_wordpress_notes`.
     *
     * `import_wordpress_notes` is a `text` field on the `uamsweb_common` fieldset —
     * a single string, not a list — so an existing note is extended rather than
     * replaced or turned into an array.
     *
     * @param  array<int|string, mixed>  $data  The entry data to populate, by reference
     * @param  string  $note  The note to append
     */
    private function appendImportNote(
        array &$data,
        string $note
    ): void {
        // Read any note already recorded for this entry
        $existing = $data['import_wordpress_notes'] ?? null;

        // Extend an existing note rather than overwriting it
        $data['import_wordpress_notes'] = \is_string($existing) && $existing !== ''
            ? $existing.' '.$note
            : $note;
    }

    /**
     * Creates Statamic entries for WordPress 'uamspeople' content type.
     *
     * Processes WordPress XML data to create person entries in the specified Statamic
     * collection. Handles comprehensive data mapping including:
     *   - Basic person information (title, slug, bio, contact details)
     *   - People group taxonomy assignments with hierarchical slug support
     *   - Asset import for portrait images
     *   - Metadata mapping for custom fields
     *   - Content processing for bio and bio_short fields
     *   - Deferred people_group field updates after taxonomy creation
     *
     * The method uses a two-phase approach:
     *   1. Create all person entries with basic data
     *   2. Create taxonomy terms and update entries with people_group fields
     *
     * @param  string  $site  The Statamic site handle (e.g., 'cancer_education')
     * @param  string  $collectionHandle  The collection handle (e.g., 'people')
     * @param  XmlParser  $xmlParser  Parser instance for accessing XML data and terms
     * @param  AssetImporter  $assetImporter  Importer for handling file and image assets
     * @param  OutputInterface  $output  Console output interface for progress logging
     * @param  MetadataMapper  $metadataMapper  Mapper for processing WordPress metadata
     * @param  ContentProcessor  $contentProcessor  Processor for bio content transformation
     * @param  array<int|string, mixed>|null  $minervaKbSettings  Optional MinervaKB settings from JSON file
     * @return array<int|string, mixed> Array of page data for tree building with keys: post_id, entry_id, parent_id, menu_order, title, slug, entry
     */
    public function createEntries(
        string $site,
        string $collectionHandle,
        XmlParser $xmlParser,
        AssetImporter $assetImporter,
        OutputInterface $output,
        MetadataMapper $metadataMapper,
        ContentProcessor $contentProcessor,
        ?array $minervaKbSettings = null
    ): array {
        $this->initializeTerms(
            $xmlParser,
            [
                $this->wordpressTaxonomyHandle,
            ],
            $site,
            $output
        );

        // Open a streaming parser spanning every part. A source that can serve
        // none raises rather than importing nothing and reporting success (wordpress-importer-archive#662).
        $xmlStreamParser = ContentStreamFactory::open(
            $xmlParser,
            'PeopleEntryCreator::createEntries'
        );

        // Initialize array to store attachment data
        $attachments = [];

        // Loop through all attachment items to collect attachment data
        foreach ($xmlStreamParser->streamItems('attachment') as $attachment) {
            // Add each attachment to the attachments array
            $attachments[] = $attachment;
        }

        // Loop through all items of this post type to process each person
        foreach (ImportProgress::track($output, $this->postType, $xmlStreamParser->streamItems($this->postType), fn (): int => $xmlStreamParser->countItems($this->postType)) as $person) {
            // Decode HTML entities and strip HTML tags from the person's title
            $title = HtmlTagStripper::strip(
                html_entity_decode(
                    (string) $person->title,
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                )
            );

            // Extract the post name/slug from WordPress data
            $slug = XpathHelper::getStringValue(
                $person,
                'wp:post_name'
            );

            // Extract the post status (publish, draft, etc.)
            $status = XpathHelper::getStringValue(
                $person,
                'wp:status',
                'publish'
            );

            // Extract the post password if set
            $postPassword = XpathHelper::getStringValue(
                $person,
                'wp:post_password'
            );

            // Extract the WordPress post ID
            $postId = XpathHelper::getStringValue(
                $person,
                'wp:post_id',
                'unknown'
            );

            // Extract the parent post ID for hierarchical relationships
            $parentId = XpathHelper::getStringValue(
                $person,
                'wp:post_parent',
                '0'
            );

            // Extract the menu order for sorting
            $menuOrder = XpathHelper::getIntValue(
                $person,
                'wp:menu_order'
            );

            // Extract the encoded content (bio) from WordPress
            $contentEncoded = XpathHelper::getStringValue(
                $person,
                'content:encoded'
            );

            // Extract post date with fallback to pubDate
            $postDate = $this->extractPostDateString($person);
            $postDateGmt = $this->extractPostDateGmtString($person);

            // Extract the original WordPress URL for reference
            $originalWordPressUrl = (string) $person->link;

            // Set the WordPress page link in processing context for accurate logging
            ProcessingContext::setWordPressPageLink($originalWordPressUrl);

            // Check if the slug is empty and generate a fallback
            if ($slug === '') {
                // Generate a slug from the title or use a fallback with post ID
                $slug = Str::slug(
                    $title !== '' ? $title : "person-{$postId}"
                );
            }

            // Initialize the entry data array with default values
            $data = [
                'blueprint' => 'person',
                'title' => $title !== '' ? $title : 'Untitled Person',
                'slug' => $slug,
                'sort_text_alpha' => null,
                'job_title' => null,
                'people_groups' => [],
                'priority' => 0,
                'bio' => null,
                'bio_short' => null,
                'email' => null,
                'telephone' => null,
                'fax' => null,
                'location' => null,
                'clinical_profile_url' => null,
                'clinical_profile_link_text' => null,
                'academic_profile_url' => null,
                'academic_profile_link_text' => null,
                'research_profile_url' => null,
                'research_profile_link_text' => null,
                'twitter' => null,
                'institutional_video_url' => null,
                'institutional_video_link_text' => null,
                'portrait' => null,
                'original_wordpress_url' => $originalWordPressUrl,
            ];

            // Map status and visibility fields
            $isScheduledPost = $this->mapStatusAndVisibility(
                $status,
                $postPassword,
                $postDate,
                $data,
                $collectionHandle,
                $output,
                $postDateGmt,
                wpmemBlock: $this->readWpMembersBlock($person)
            );

            // Generate slug and ID with date handling if needed
            [$entrySlug, $entryId] = $this->generateSlugAndId(
                $slug,
                $postId,
                $title
            );

            // Create the entry date object for Statamic
            $entryDate = $this->createEntryDate(
                $postDate,
                $isScheduledPost
            );

            // Set the entry ID in the data array
            $data['id'] = $entryId;

            // Set the entry slug in the data array
            $data['slug'] = $entrySlug;

            // Log the processing of this person entry
            $output->writeln("Processing person '$entrySlug' with content length: ".\strlen($contentEncoded));

            // ---
            // Extract all category elements with the configured taxonomy domain (the source
            // taxonomy)
            // ---
            $categoryElements = XpathHelper::xpathElements(
                $person,
                "category[@domain=\"{$this->wordpressTaxonomyHandle}\"]"
            );

            // Initialize array to store site-specific people group slugs
            $peopleGroups = [];

            // Resolve nicename-to-term map for this taxonomy with array safety
            $taxonomyNicenameToTermIdMap =
                $this->nicenameToTermIdMap[$this->wordpressTaxonomyHandle] ?? [];

            // Fall back to an empty map when source data is unexpectedly invalid
            if (! \is_array($taxonomyNicenameToTermIdMap)) {
                $taxonomyNicenameToTermIdMap = [];
            }

            // Process each category element to extract slug and name
            foreach ($categoryElements as $category) {
                // Extract the category slug from the nicename attribute
                $group = (string) $category['nicename'];

                // Extract the category name from CDATA content
                $groupName = (string) $category;

                // ---
                // Get term ID from nicename mapping. The map and the term map are built
                // from the same terms, so a nicename with no id has no term to look up
                // by slug either, and falls back to itself below.
                //
                // Note: Uses the configured WordPress taxonomy handle for mapping
                // ---
                $termIdValue = $taxonomyNicenameToTermIdMap[$group] ?? null;

                // An id no term has finds nothing in either lookup below, whatever it is
                // @pest-mutate-ignore: EmptyStringToNotEmpty
                $termId = \is_string($termIdValue) ? $termIdValue : '';

                // Get the full hierarchical slug using ID-based lookup
                $resolvedFullSlug = TermLookupHelper::getFullSlugFromMapById(
                    $this->termMap,
                    $termId
                );

                // Use category slug as fallback when lookup result is empty
                $fullSlug = ($resolvedFullSlug !== '')
                    ? $resolvedFullSlug
                    : $group;

                // Add site prefix to create site-specific taxonomy slugs
                $siteSpecificSlug = TaxonomySlugProcessor::addSitePrefixToSlug(
                    $fullSlug,
                    $site
                );

                // ---
                // `people_groups` became a structured collection in
                // `UAMS-Web/uams-statamic-archive#1863`, so the blueprint's field is an
                // `entries` fieldtype expecting an entry id — a term slug written
                // here no longer resolves to anything. The term pass runs before
                // any entry is created, so the destination is already recorded.
                // Fall back to the slug when the taxonomy is still flat.
                // ---
                $destinationReference = TermDestinationRegistry::referenceFor(
                    $this->wordpressTaxonomyHandle,
                    $termId
                );

                // Add the resolved destination to the people groups array
                $peopleGroups[] = $destinationReference ?? $siteSpecificSlug;

                // Store normalized site-specific groups for getPeopleGroups() method
                $termData = [
                    'site_specific' => $siteSpecificSlug,
                    'original' => $fullSlug,
                    'name' => $groupName,
                ];

                // Avoid duplicate term records during repeated category processing
                if (
                    ! \in_array(
                        $termData,
                        $this->siteSpecificPeopleGroups,
                        true
                    )
                ) {
                    $this->siteSpecificPeopleGroups[] = $termData;
                }

                // Clean up variables to free up memory
                unset(
                    $category,
                    $fullSlug,
                    $group,
                    $groupName,
                    $siteSpecificSlug
                );
            }

            // Set the people_groups field directly in the data array for immediate saving
            $data['people_groups'] = $peopleGroups;

            // Check if entry date was not set and add date string if needed
            if (! $entryDate instanceof \DateTime) {
                // Set the date string in the data array
                $data['date'] = $postDate;
            }

            // ---
            // `_psyoutube` and `_psvimeo` both describe the person's video, and the
            // `person` blueprint carries one URL slot per context. Collect both across
            // the meta loop and resolve once it has finished, so the winner is decided
            // by a stated rule rather than by the order postmeta happens to appear in
            // the export (wordpress-importer-archive#501).
            // ---
            $youtubeVideoUrl = null;
            $vimeoVideoUrl = null;

            // Map postmeta fields
            $metaNodes = XpathHelper::xpathElements($person, 'wp:postmeta');

            // Loop through all post meta nodes to extract custom field data
            foreach ($metaNodes as $meta) {
                // Extract the meta key from the XML node
                $key = XpathHelper::getStringValue(
                    $meta,
                    'wp:meta_key'
                );

                // Extract the meta value from the XML node
                $value = XpathHelper::getStringValue(
                    $meta,
                    'wp:meta_value'
                );

                // ---
                // Check if this key matches our expected pattern (starts with configured prefix)
                // ---

                // Get the configured meta key prefix for this post type
                $metaKeyPrefix = $this->metaKeyPrefix;

                // Check if the meta key starts with the configured prefix
                if (
                    str_starts_with($key, $metaKeyPrefix)
                ) {
                    // Extract the suffix after the prefix
                    $suffix = \substr(
                        $key,
                        \strlen($metaKeyPrefix)
                    );

                    // Process meta fields based on suffix
                    switch ($suffix) {
                        // Handle sort text field
                        case 'sorttext':
                            // Set the alphabetical sort text
                            $data['sort_text_alpha'] = \trim($value);

                            // Exit this case
                            break;

                            // Handle position/job title field
                        case 'position':
                            // ---
                            // `job_title` is a replicator on the `person` blueprint, not a
                            // string, so a bare value produces an entry the Control Panel
                            // cannot render. Omit the field entirely when the postmeta
                            // carries no text, rather than writing an empty set (wordpress-importer-archive#31).
                            // ---
                            $jobTitleSets = $this->buildJobTitleSets($value);

                            // Only write the field when there is a role to record
                            if ($jobTitleSets !== []) {
                                $data['job_title'] = $jobTitleSets;
                            }

                            // Exit this case
                            break;

                            // Handle email field
                        case 'email':
                            // Set the email address
                            $data['email'] = $value;

                            // Exit this case
                            break;

                            // Handle location field
                        case 'location':
                            // Set the location
                            $data['location'] = $value;

                            // Exit this case
                            break;

                            // Handle telephone field
                        case 'tel':
                            // Set the normalized telephone number
                            $data['telephone'] = $this->normalizePhoneNumber($value);

                            // Exit this case
                            break;

                            // Handle fax field
                        case 'fax':
                            // Set the normalized fax number
                            $data['fax'] = $this->normalizePhoneNumber($value);

                            // Exit this case
                            break;

                            // Handle personal/clinical profile URL field
                        case 'personal':
                            // Set the clinical profile URL
                            $data['clinical_profile_url'] = $value;

                            // Exit this case
                            break;

                            // Handle personal profile anchor text field
                        case 'personalanchor':
                            // Set the clinical profile link text with fallback
                            $data['clinical_profile_link_text'] = $value !== ''
                                ? $value
                                : 'UAMS Health Provider Profile';

                            // Exit this case
                            break;

                            // Handle department/academic web URL field
                        case 'deptweb':
                            // Set the academic profile URL
                            $data['academic_profile_url'] = $value;

                            // Exit this case
                            break;

                            // Handle academic profile anchor text field
                        case 'deptwebanchor':
                            // Strip HTML tags from academic profile link text and set with fallback
                            $data['academic_profile_link_text'] = HtmlTagStripper::strip(
                                $value !== ''
                                    ? $value
                                    : 'UAMS Academic Profile'
                            );

                            // Exit this case
                            break;

                            // Handle research/tri web URL field
                        case 'triweb':
                            // Set the research profile URL
                            $data['research_profile_url'] = $value;

                            // Exit this case
                            break;

                            // Handle research profile anchor text field
                        case 'triwebanchor':
                            // Strip HTML tags from research profile link text and set with fallback
                            $data['research_profile_link_text'] = HtmlTagStripper::strip(
                                $value !== ''
                                    ? $value
                                    : 'UAMS Research Profile'
                            );

                            // Exit this case
                            break;

                            // Handle Twitter field
                        case 'twitter':
                            // Set the Twitter URL
                            $data['twitter'] = $value;

                            // Exit this case
                            break;

                            // Handle YouTube field
                        case 'youtube':
                            // Capture only; resolved after the loop by applyPersonVideo()
                            if ($value !== '') {
                                $youtubeVideoUrl = $value;
                            }

                            // Exit this case
                            break;

                            // Handle Vimeo field
                        case 'vimeo':
                            // Capture only; resolved after the loop by applyPersonVideo()
                            if ($value !== '') {
                                $vimeoVideoUrl = $value;
                            }

                            // Exit this case
                            break;

                            // Handle sort priority field
                        case 'sortpriority':
                            // Set the priority as an integer
                            $data['priority'] = (int) $value; // Map as integer

                            // Exit this case
                            break;

                            // Handle free HTML/bio short field
                        case 'freehtml':
                            // ---
                            // Check if the bio short content is not empty. Blank content
                            // converts to nothing, which the emptiness check drops, so the
                            // guard only saves the work.
                            // ---
                            // @pest-mutate-ignore: EmptyStringToNotEmpty
                            if ($value !== '') {
                                // Set collection handle in processing context for link processing
                                ProcessingContext::setCollectionHandle($collectionHandle);

                                // Set Statamic entry ID in processing context for link processing
                                ProcessingContext::setStatamicEntryId($entryId);

                                // ---
                                // Process the bio short content for Bard format. As InnerBlocks
                                // content its block comments would be stripped first, but the
                                // converter drops them anyway, so the flag changes no output
                                // (measured on bare, attributed, ACF, separator, html and
                                // shortcode blocks).
                                // ---
                                // @pest-mutate-ignore: FalseToTrue
                                $parsedBioShort = TextProcessor::processForBard(
                                    $value,
                                    $site,
                                    $assetImporter,
                                    $output,
                                    true, // Apply WpAutoP for paragraph formatting
                                    false,
                                    $attachments,
                                    $xmlParser->getTerms(),
                                    $entrySlug,
                                    $entryId,
                                    $collectionHandle
                                );

                                // Check if the parsed content is valid and not empty
                                if (! $this->isEmptyContent($parsedBioShort)) {
                                    // Set the bio short content
                                    $data['bio_short'] = $parsedBioShort;
                                }
                            }

                            // Exit this case
                            break;

                    }
                }

                // ---
                // Handle thumbnail/portrait image field (same for both post types)
                // ---

                // Check if this is the thumbnail ID meta key
                if ($key === '_thumbnail_id') {
                    // Store thumbnail ID in entry data for block processors
                    $data['_thumbnail_id'] = $value;

                    // Import the portrait image with graceful handling for disabled uploads
                    $portraitPath = FileProcessor::importFileFromAttachmentWithDisabledUploadHandling(
                        $value,
                        $attachments,
                        '', // Empty site for portraits container root
                        $assetImporter,
                        $output,
                        'portraits',
                        'portrait',
                        'PeopleEntryCreator::createEntries',
                        $originalWordPressUrl
                    );

                    // Set the portrait filename (basename only)
                    $data['portrait'] = ($portraitPath !== null && $portraitPath !== '') ? basename($portraitPath) : null;
                }

                // Clean up variables to free up memory
                unset(
                    $key,
                    $meta,
                    $value
                );
            }

            // Land any imported video on a handle the `person` blueprint defines
            $this->applyPersonVideo(
                $data,
                $youtubeVideoUrl,
                $vimeoVideoUrl
            );

            // Parse <content:encoded> for bio
            $parsedBio = $contentProcessor->process(
                $contentEncoded,
                $site,
                $assetImporter,
                $output,
                $xmlParser,
                $title !== '' ? $title : 'Untitled Person',
                $postId,
                $originalWordPressUrl,
                $attachments,
                $xmlParser->getTerms(),
                [],
                $data,
                $collectionHandle,
                $entrySlug,
                $entryId
            );

            // Check the converted bio itself, not the whole converter result, which is
            // never empty; an empty bio leaves the field at its null default (#257)
            $parsedBioContent = $parsedBio['content'] ?? [];
            if (! \is_array($parsedBioContent)) {
                $parsedBioContent = [];
            }

            if (! $this->isEmptyContent($parsedBioContent)) {
                // Set the bio content in the data array
                $data['bio'] = $parsedBioContent;
            }

            // Map WordPress metadata to Statamic fields
            $metadataMapper->mapMetadata(
                $person,
                $data,
                $xmlParser,
                $assetImporter,
                $attachments,
                $output
            );

            // Add updated_at field from post modified date
            $this->addUpdatedAtField(
                $data,
                $person,
                'PeopleEntryCreator::createEntries'
            );

            // Save the entry to Statamic
            $entry = $this->saveEntry(
                $collectionHandle,
                $site,
                $entrySlug,
                $data,
                $output,
                $entryDate,
                null,
                $postDate
            );

            // Check if the entry was successfully saved
            if ($entry instanceof Entry) {
                // Add the entry data to the page data array
                $this->addPageData(
                    $postId,
                    $entryId,
                    $parentId,
                    $menuOrder,
                    $title !== '' ? $title : 'Untitled Person',
                    null,
                    $entry
                );
            }

            // Clean up variables to free up memory
            unset(
                $data,
                $entry,
                $entryDate,
                $entryId,
                $entrySlug,
                $isScheduledPost,
                $menuOrder,
                $originalWordPressUrl,
                $parentId,
                $parsedBio,
                $parsedBioContent,
                $parsedBioShort,
                $peopleGroups,
                $portraitPath,
                $postDate,
                $postDateGmt,
                $postId,
                $postPassword,
                $slug,
                $status,
                $title
            );
        }

        // Clean up variables to free up memory
        unset(
            $attachments,
            $xmlStreamParser
        );

        // ---
        // Force garbage collection after processing all entries. This bounds memory
        // and changes no output.
        // ---
        // @pest-mutate-ignore: RemoveFunctionCall
        gc_collect_cycles();

        // Return the collected page data for tree building
        return $this->pageData;
    }

    /**
     * Creates taxonomy terms from stored data with proper taxonomy mapping.
     *
     * Overrides the base implementation to map WordPress taxonomy handles
     * ('uamspeople-categories' or 'tshowcase-categories') to Statamic 'people_groups'
     * taxonomy.
     *
     * @param  array<int|string, mixed>  $siteSpecificData  Array of term data to create
     * @param  string  $wordpressTaxonomyHandle  The WordPress taxonomy handle
     * @param  string  $site  The Statamic site handle
     * @param  OutputInterface  $output  Console output for logging
     */
    protected function createTaxonomyTermsFromStoredData(
        array $siteSpecificData,
        string $wordpressTaxonomyHandle,
        string $site,
        OutputInterface $output
    ): void {
        // Map WordPress taxonomy handles to Statamic 'people_groups'
        $statamicTaxonomy = (
            $wordpressTaxonomyHandle === 'uamspeople-categories'
            || $wordpressTaxonomyHandle === 'tshowcase-categories'
        ) ? 'people_groups' : $wordpressTaxonomyHandle;

        // Call the parent method with the mapped taxonomy
        parent::createTaxonomyTermsFromStoredData(
            $siteSpecificData,
            $statamicTaxonomy,
            $site,
            $output
        );
    }

    /**
     * Determines if this entry creator uses the ShowPeople functionality.
     *
     * People entries don't use ShowPeople functionality, so this always
     * returns false. This method is required by the abstract parent class.
     *
     * @return bool Always returns false for people entries
     */
    public function hasShowPeople(): bool
    {
        return false;
    }

    /**
     * Returns the post categories data collected during entry creation.
     *
     * People entries don't use post categories, so this always returns an
     * empty array. This method is required by the abstract parent class.
     *
     * @return array<int|string, mixed> Always returns an empty array for people entries
     */
    public function getPostCategories(): array
    {
        return [];
    }

    /**
     * Returns the post tags data collected during entry creation.
     *
     * People entries don't use post tags, so this always returns an empty array.
     * This method is required by the abstract parent class.
     *
     * @return array<int|string, mixed> Always returns an empty array for people entries
     */
    public function getPostTags(): array
    {
        return [];
    }

    /**
     * Returns the site-specific people group slugs that were processed during entry
     * creation.
     *
     * Extracts and returns all the site-specific people group slugs from the internal
     * storage array. This method is typically called after all entries have been
     * created to provide a summary of which people groups were encountered and
     * processed.
     *
     * The returned array contains site-prefixed slugs
     * (e.g., 'cancer-education-travel-awards') that can be used for reporting,
     * validation, or further processing by calling code.
     *
     * Note: This method preserves the internal storage array for potential reuse
     * during validation processes.
     *
     * @return array<int|string, mixed> Array of site-specific people group slugs that were processed
     */
    public function getPeopleGroups(): array
    {
        // ---
        // Convert the site-specific people groups to the format expected by
        // BaseTaxonomyValidator. BaseTaxonomyValidator expects an associative array where
        // keys are original term names (without site prefix) and values are arrays of
        // usage details (array of arrays format).
        // ---
        $result = [];

        // Group people groups by their original term names (without site prefix)
        foreach ($this->siteSpecificPeopleGroups as $groupData) {
            // Extract normalized values from typed term records
            $siteSpecificValue = $groupData['site_specific'];
            $originalTerm = $groupData['original'] !== ''
                ? $groupData['original']
                : $siteSpecificValue;
            $groupName = $groupData['name'] !== ''
                ? $groupData['name']
                : $originalTerm;

            $result[$originalTerm] ??= [];

            // Add usage details for this term
            $result[$originalTerm][] = [
                'page_slug' => 'people-entries', // Generic page slug since these come from people entries
                'original' => $originalTerm,
                'name' => $groupName,
                'site_specific' => $siteSpecificValue,
            ];
        }

        // ---
        // Return the result of the people groups extraction.
        //
        // Note: Don't clear the data here as it may be needed for validation
        // ---
        return $result;
    }

    /**
     * Validates people_group taxonomy terms used in people entries.
     *
     * Validates that all people_group terms referenced in people entries exist
     * in the Statamic people_group taxonomy. Automatically creates missing terms
     * to resolve any validation issues.
     *
     * @param  string  $collectionHandle  The collection handle (e.g., 'people')
     * @param  string  $site  The Statamic site handle
     * @param  OutputInterface  $output  Console output for logging
     */
    public function validateTaxonomies(
        string $collectionHandle,
        string $site,
        OutputInterface $output
    ): void {
        // Get the people groups that were processed during entry creation
        $peopleGroups = $this->getPeopleGroups();

        // Create a validator for people group taxonomy terms
        $validator = new PeopleGroupValidator;

        // ---
        // Set context to 'entries' to display accurate validation messages
        // for people entries (vs. people shortcodes)
        // ---
        $validator->setContext('entries');

        // Validate that all people groups referenced in entries exist
        $validator->validateTerms(
            $peopleGroups,
            $collectionHandle,
            $site,
            $output
        );
    }

    /**
     * Returns the site-specific people group slugs for testing purposes.
     *
     * This method extracts the site-specific slugs from the stored people groups
     * data and returns them as a simple array for testing and validation.
     *
     * @return array<int|string, mixed> Array of site-specific people group slugs
     */
    public function getSiteSpecificPeopleGroupSlugs(): array
    {
        // Initialize array to store site-specific slugs
        $slugs = [];

        // Loop through stored people group data to extract site-specific slugs
        foreach ($this->siteSpecificPeopleGroups as $groupData) {
            // Add non-empty site-specific slugs to the results array
            if ($groupData['site_specific'] !== '') {
                $slugs[] = $groupData['site_specific'];
            }
        }

        // Return unique slugs to avoid duplicates
        return array_unique($slugs);
    }
}
