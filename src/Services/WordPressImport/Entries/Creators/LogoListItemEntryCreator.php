<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators;

use Exception;
use Illuminate\Support\Facades\Log;
use SimpleXMLElement;
use Statamic\Entries\Entry;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\ContentStreamFactory;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\BaseTaxonomyValidator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\TermDestinationRegistry;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\Validators\LogosItemCategoriesValidator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\CollectionStructureManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\FileProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\HtmlTagStripper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportProgress;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\SiteManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\TaxonomySlugProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\TermLookupHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;

/**
 * Creates Statamic entries for WordPress 'logo-list-item' content type.
 *
 * This class handles the complete import process for WordPress logo list items,
 * including:
 *   - Logo metadata mapping (title, image, link, link text, link target)
 *   - Logo image import and processing
 *   - Logo category and label taxonomy term creation with site-specific prefixes
 *   - Content processing for short and long descriptions
 *   - Entry status and visibility mapping
 *   - Slug generation and duplicate handling
 *   - Menu order handling for logo list organization
 *
 * The class uses streaming XML parsing for memory efficiency when processing
 * large WordPress exports and maintains taxonomy-to-entry relationships for
 * proper taxonomy term creation and validation.
 */
class LogoListItemEntryCreator extends BaseEntryCreator
{
    /**
     * @var array<string, array<int, string>> Associative array mapping
     *                                        category slugs to arrays of entry slugs. Used to track which entries
     *                                        belong to each category for validation and taxonomy term creation
     *                                        purposes. Format: ['category-slug' => ['entry-slug-1', ...]]
     */
    private array $logoCategories = [];

    /**
     * Returns the WordPress taxonomies a logo list import needs imported first.
     *
     * Shared with {@see PageEntryCreator}, whose logo-list blocks reference the
     * same terms by entry id. `importTaxonomyTermsAtBeginning()` dedups on the
     * site plus the whole sorted list, so both callers passing this one list is
     * what makes the second pass in a run a no-op (wordpress-importer-archive#1220).
     *
     * @return list<string> WordPress taxonomy handles
     */
    public static function getTaxonomyHandlesForLogoListImport(): array
    {
        return [
            'logo-list-label',
            'logo-list-category',
        ];
    }

    /**
     * Constructs the LogoListItemEntryCreator with its dependencies.
     *
     * @param  SiteManager  $siteManager  The site manager instance
     * @param  CollectionStructureManager  $collectionStructureManager  The collection structure manager instance
     */
    public function __construct(private SiteManager $siteManager, private CollectionStructureManager $collectionStructureManager) {}

    /**
     * Creates Statamic entries for WordPress logo-list-item content type.
     *
     * Processes WordPress logo list items from XML export, handling logo metadata
     * mapping, image import, taxonomy mapping, and entry creation. Uses streaming
     * XML parser for memory efficiency when processing large datasets.
     *
     * Note: Tree building is handled by the parent EntryCreator after all entry types
     * are processed
     *
     * @param  string  $site  The Statamic site handle where entries will be created
     * @param  string  $collectionHandle  The collection handle for the entries
     * @param  XmlParser  $xmlParser  Parser instance for accessing WordPress XML data
     * @param  AssetImporter  $assetImporter  Service for importing and processing assets
     * @param  OutputInterface  $output  Console output interface for progress logging
     * @param  MetadataMapper  $metadataMapper  Service for mapping WordPress metadata
     * @param  ContentProcessor  $contentProcessor  Service for processing post content
     * @param  array<int|string, mixed>|null  $minervaKbSettings  Optional MinervaKB settings from JSON file
     * @return array<int|string, mixed> Array of page data containing entry relationships and metadata
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
        // Initialize terms with standardized processing
        $this->initializeTerms(
            $xmlParser,
            self::getTaxonomyHandlesForLogoListImport(),
            $site,
            $output
        );

        // Display progress message to inform user of current operation
        $output->writeln('Processing logo list items...');

        // Enable the logo_list_items collection for the site
        $this->siteManager->enableCollectionForSite(
            $site,
            'logo_list_items',
            $output
        );

        // Initialize structure for the logo_list_items collection
        $this->collectionStructureManager->initializeStructureForSite(
            'logo_list_items',
            $site,
            $output
        );

        // Open a streaming parser spanning every part. A source that can serve
        // none raises rather than importing nothing and reporting success (wordpress-importer-archive#662).
        $xmlStreamParser = ContentStreamFactory::open(
            $xmlParser,
            'LogoListItemEntryCreator::createEntries'
        );

        // Get attachments using streaming for logo image processing
        // Initialize attachments array
        $attachments = [];

        // Loop through all attachment items to collect them for logo image processing
        foreach ($xmlStreamParser->streamItems('attachment') as $attachment) {
            // Add attachment to attachments array
            $attachments[] = $attachment;
        }

        // Get all items for post ID mapping
        $items = [];
        foreach ($xmlStreamParser->streamAllItems() as $item) {
            $items[] = $item;
        }

        // Initialize processed count
        $processedCount = 0;

        // Initialize skipped count
        $skippedCount = 0;

        // Loop through all logo-list-item posts from the XML stream
        foreach (ImportProgress::track($output, 'logo-list-item', $xmlStreamParser->streamItems('logo-list-item'), static fn (): int => $xmlStreamParser->countItems('logo-list-item')) as $logoListItem) {
            // Attempt to process the logo list item
            try {
                // Process the current logo list item and get the result
                $result = $this->processLogoListItem(
                    $logoListItem,
                    $site,
                    $collectionHandle,
                    $assetImporter,
                    $output,
                    $contentProcessor,
                    $attachments,
                    $this->termMap,
                    $xmlParser->getTerms(),
                    $items,
                    $xmlParser
                );

                // Check whether the logo list item was successfully processed
                if ($result) {
                    // Increment the processed count for successful entries
                    $processedCount++;
                } else { // Otherwise
                    // Increment the skipped count for entries that were skipped
                    $skippedCount++;
                }
            }

            // Handle any exceptions that occur during processing
            catch (Exception $e) {
                // Log the error with detailed information for debugging
                Log::error(
                    'LogoListItemEntryCreator::createEntries: Error processing logo list item',
                    [
                        'post_id' => XpathHelper::getStringValue(
                            $logoListItem,
                            'wp:post_id',
                            'unknown'
                        ),
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]
                );

                // Display error message to the user
                $output->writeln("<error>Error processing logo list item: {$e->getMessage()}</error>");

                // Increment the skipped count for failed entries
                $skippedCount++;
            }

            // Clean up variables to free up memory
            unset(
                $logoListItem,
                $result
            );

            // ---
            // Collect garbage after each item, as the other creators do. This bounds
            // memory over a large export and changes no output.
            // ---
            // @pest-mutate-ignore: RemoveFunctionCall
            gc_collect_cycles();
        }

        // Clear taxonomy validator cache to ensure newly created terms are found during validation
        BaseTaxonomyValidator::clearCaches();

        // Display completion message with processing statistics
        $output->writeln("<info>Logo list item processing complete: {$processedCount} processed, {$skippedCount} skipped</info>");

        // Clean up variables to free up memory
        unset(
            $xmlStreamParser,
            $attachments,
            $processedCount,
            $skippedCount,
            $collectionHandle,
            $output,
            $site
        );

        // Force final garbage collection, which bounds memory and changes no output
        // @pest-mutate-ignore: RemoveFunctionCall
        gc_collect_cycles();

        // Return the collected page data for tree building
        return $this->getPageData();
    }

    /**
     * Process a single logo list item entry.
     *
     * @param  SimpleXMLElement  $logoListItem  The logo list item XML element
     * @param  string  $site  The site handle
     * @param  string  $collectionHandle  The collection handle
     * @param  AssetImporter  $assetImporter  The asset importer
     * @param  OutputInterface  $output  The output interface
     * @param  ContentProcessor  $contentProcessor  The content processor
     * @param  array<int|string, mixed>  $attachments  Array of attachment items
     * @param  array<int|string, mixed>  $termMap  Map of term IDs to term data
     * @param  array<int|string, mixed>  $terms  Array of term items
     * @param  array<int|string, mixed>  $items  Array of all items
     * @param  XmlParser  $xmlParser  The XML parser instance
     * @return bool True if entry was processed successfully, false if skipped
     */
    private function processLogoListItem(
        SimpleXMLElement $logoListItem,
        string $site,
        string $collectionHandle,
        AssetImporter $assetImporter,
        OutputInterface $output,
        ContentProcessor $contentProcessor,
        array $attachments,
        array $termMap,
        array $terms,
        array $items,
        XmlParser $xmlParser
    ): bool {
        // Extract post ID from the WordPress XML element
        $postId = XpathHelper::getStringValue(
            $logoListItem,
            'wp:post_id'
        );

        // Extract post name from the WordPress XML element
        $postName = XpathHelper::getStringValue(
            $logoListItem,
            'wp:post_name'
        );

        // Decode HTML entities and strip HTML tags from title
        $title = HtmlTagStripper::strip(
            html_entity_decode(
                (string) $logoListItem->title,
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            )
        );
        $title = $title !== '' ? $title : 'Untitled';

        // Extract status from the WordPress XML element
        $status = XpathHelper::getStringValue(
            $logoListItem,
            'wp:status',
            'publish'
        );

        // Extract post password from the WordPress XML element
        $postPassword = XpathHelper::getStringValue(
            $logoListItem,
            'wp:post_password'
        );

        // Extract post date with fallback to pubDate
        $postDate = $this->extractPostDateString($logoListItem);

        // Extract menu order from the WordPress XML element
        $menuOrder = XpathHelper::getIntValue(
            $logoListItem,
            'wp:menu_order'
        );

        // Extract original WordPress URL from the WordPress XML element
        $originalWordPressUrl = (string) $logoListItem->link;

        // Set the WordPress page link in processing context for accurate logging
        ProcessingContext::setWordPressPageLink($originalWordPressUrl);

        // Check if the post ID is empty ($title is normalized to a non-empty value above)
        if ($postId === '') {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'post_id' => $postId,
                    'title' => $title,
                ],
                $originalWordPressUrl
            );

            // Log warning about missing required data
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'LogoListItemEntryCreator::processLogoListItem: Skipping logo list item with missing post ID or title',
                    $logData['page_url']
                ),
                $logData['context']
            );

            // Skip the logo list item
            return false;
        }

        // Generate unique slug and ID
        [$entrySlug, $entryId] = $this->generateSlugAndId(
            $postName,
            $postId,
            $title
        );

        // Initialize content length counter for logging purposes
        $contentLength = 0;

        // Loop through all postmeta nodes to extract content metadata
        foreach (XpathHelper::xpathElements($logoListItem, 'wp:postmeta') as $meta) {
            // Extract key from the WordPress XML element
            $key = XpathHelper::getStringValue(
                $meta,
                'wp:meta_key'
            );

            // ---
            // Check if the metadata key is for content fields. The needles are not
            // numeric, so a loose comparison matches exactly what the strict one does.
            // ---
            // @pest-mutate-ignore: TrueToFalse
            if (
                \in_array(
                    $key,
                    [
                        'logo_shortdescr',
                        'logo_longdescr',
                    ],
                    true
                )
            ) {
                // Extract value from the WordPress XML element
                $value = XpathHelper::getStringValue(
                    $meta,
                    'wp:meta_value'
                );

                // Add the content length to the total counter
                $contentLength += \strlen($value);
            }
        }

        // Display parent message for the logo list item being processed
        $output->writeln("Processing logo list item '$entrySlug' with content length: $contentLength");

        // Check if entry already exists before processing content
        $existingEntry = $this->findExistingEntry(
            $collectionHandle,
            $site,
            $entryId,
            $entrySlug
        );

        // Check if entry exists and we should skip it
        if (
            $existingEntry instanceof Entry
            && $this->entryOverwriteBehavior === 'Skip matching files'
        ) {
            // Log that the entry was skipped
            $output->writeln("<comment>Skipped entry '$entrySlug' as it already exists.</comment>");

            // Return false to indicate the entry was not processed
            return false;
        }

        // Initialize entry data array
        $data = [];

        // Map status and visibility
        $isScheduledPost = $this->mapStatusAndVisibility(
            $status,
            $postPassword,
            $postDate,
            $data,
            $collectionHandle,
            $output,
            wpmemBlock: $this->readWpMembersBlock($logoListItem)
        );

        // Set blueprint
        $data['blueprint'] = 'logo_list_item';

        // Set title
        $data['title'] = $title;

        // Set ID
        $data['id'] = $entryId;

        // Map logo-specific metadata
        $this->mapLogoMetadata(
            $logoListItem,
            $data,
            $assetImporter,
            $attachments,
            $output,
            $termMap,
            $site,
            $originalWordPressUrl
        );

        // Process content for short and long descriptions
        $this->processLogoContent(
            $logoListItem,
            $data,
            $contentProcessor,
            $assetImporter,
            $output,
            $entrySlug,
            $title,
            $postId,
            $originalWordPressUrl,
            $attachments,
            $terms,
            $items,
            $collectionHandle,
            $xmlParser,
            $entryId
        );

        // Handle menu order
        // Set key to 'default-general' since the source XML does not specify a key
        $data['order_values'] = [
            'default-general' => (string) $menuOrder,
        ];

        // Add original WordPress URL
        $data['original_wordpress_url'] = $originalWordPressUrl;

        // Create entry date for scheduled posts
        $entryDate = $this->createEntryDate(
            $postDate,
            $isScheduledPost
        );

        // Add updated_at field from post modified date
        $this->addUpdatedAtField(
            $data,
            $logoListItem,
            'LogoListItemEntryCreator::processLogoListItem'
        );

        // Save the entry
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

        // Check if the entry was saved
        if ($entry instanceof Entry) {
            // Add page data for tree building
            $this->addPageData(
                $postId,
                $entryId,
                '0',
                $menuOrder,
                $title,
                $originalWordPressUrl,
                $entry
            );

            // Track taxonomy relationships
            $this->trackTaxonomyRelationships(
                $logoListItem,
                $entrySlug,
                $termMap,
                $site
            );
        }

        // Clean up variables to free up memory
        unset(
            $postId,
            $postName,
            $title,
            $status,
            $postPassword,
            $postDate,
            $menuOrder,
            $originalWordPressUrl,
            $entrySlug,
            $entryId,
            $contentLength,
            $isScheduledPost,
            $data,
            $entryDate,
        );

        // Return the saved status
        return $entry instanceof Entry;
    }

    /**
     * Map logo-specific metadata from WordPress to Statamic fields.
     *
     * @param  SimpleXMLElement  $logoListItem  The logo list item XML element
     * @param  array<int|string, mixed>  &$data  The entry data array to populate
     * @param  AssetImporter  $assetImporter  The asset importer
     * @param  array<int|string, mixed>  $attachments  Array of attachment items
     * @param  OutputInterface  $output  The output interface
     * @param  array<int|string, mixed>  $termMap  Map of term IDs to term data
     * @param  string  $site  The site handle
     * @param  string  $originalWordPressUrl  The original WordPress URL
     */
    private function mapLogoMetadata(
        SimpleXMLElement $logoListItem,
        array &$data,
        AssetImporter $assetImporter,
        array $attachments,
        OutputInterface $output,
        array $termMap,
        string $site,
        string $originalWordPressUrl
    ): void {
        // Initialize meta data array
        $metaData = [];

        // Loop through all postmeta nodes to extract metadata
        foreach (XpathHelper::xpathElements($logoListItem, 'wp:postmeta') as $meta) {
            // Extract key from the WordPress XML element
            $key = XpathHelper::getStringValue(
                $meta,
                'wp:meta_key'
            );

            // Extract value from the WordPress XML element
            $value = XpathHelper::getStringValue(
                $meta,
                'wp:meta_value'
            );

            // Add key and value to the meta data array
            $metaData[$key] = $value;
        }

        // Map logo title
        $logoTitle = $metaData['logo_title'] ?? '';

        // Check if the logo title is not empty
        if ($logoTitle !== '') {
            // Set logo title
            $data['logo_title'] = $logoTitle;
        }

        // Map logo image
        $logoImageId = $metaData['logo_image'] ?? '';

        // Check if the logo image ID is not empty
        if ($logoImageId !== '') {
            // Import the logo image file with graceful handling for disabled uploads
            $logoImagePath = FileProcessor::importFileFromAttachmentWithDisabledUploadHandling(
                $logoImageId,
                $attachments,
                $site,
                $assetImporter,
                $output,
                'logos_unrestricted',
                'logo image',
                'LogoListItemEntryCreator::mapLogoMetadata',
                $originalWordPressUrl
            );

            // Set the logo image path in the data array
            $data['logo_image'] = $logoImagePath;
        }

        // Map logo link
        $logoLink = $metaData['logo_link'] ?? '';

        // Check if the logo link is not empty
        if ($logoLink !== '') {
            // Set logo link
            $data['logo_link'] = $logoLink;
        }

        // Map logo link text
        $logoLinkText = $metaData['logo_link_text'] ?? '';

        // Check if the logo link text is not empty
        if ($logoLinkText !== '') {
            // Set logo link text
            $data['logo_link_text'] = $logoLinkText;
        }

        // Map logo link target
        $logoLinkTarget = $metaData['logo_link_target'] ?? '';

        // Check if the logo link target is not empty
        if ($logoLinkTarget !== '') {
            // ---
            // Set the logo link target. Convert '_blank' to true for Statamic field
            // (cast_booleans: true handles conversion).
            // ---
            $data['logo_link_target'] = $logoLinkTarget === '_blank';
        }

        // ---
        // Map logo category. An absent or blank value parses to no categories, so
        // the default it falls back to cannot be told apart.
        // ---
        // @pest-mutate-ignore: EmptyStringToNotEmpty
        $categories = $this->parseSerializedArray($metaData['logo_category'] ?? '');

        if ($categories !== []) {
            $categorySlugs = [];

            // Convert category IDs to slugs using TermLookupHelper
            // Collected references, whichever shape the destination turns out to be
            $mappedCategoryTerms = [];

            foreach ($categories as $categoryId) {
                // Normalize category ID value to a string before term map lookup
                $normalizedCategoryId = $this->normalizeTermId($categoryId);

                // Skip category IDs that cannot be normalized to a string value
                if ($normalizedCategoryId === null) {
                    continue;
                }

                // ---
                // `logos_item_categories` became a structured collection in
                // `UAMS-Web/uams-statamic-archive#1863`, so the blueprint field is an
                // `entries` fieldtype holding entry ids — a `handle::slug`
                // taxonomy reference no longer resolves to anything. The term
                // pass runs first, so the destination is already recorded.
                // ---
                $destinationReference = TermDestinationRegistry::referenceFor(
                    'logo-list-category',
                    $normalizedCategoryId
                );

                if ($destinationReference !== null) {
                    $mappedCategoryTerms[] = $destinationReference;

                    continue;
                }

                // Use TermLookupHelper to get the full hierarchical slug directly by ID
                $fullSlug = TermLookupHelper::getFullSlugFromMapById(
                    $termMap,
                    $normalizedCategoryId
                );
                if ($fullSlug !== '') {
                    $categorySlugs[] = $fullSlug;
                }
            }

            // Anything unresolved falls back to the flat taxonomy reference shape,
            // site-prefixed with proper sluggification
            foreach ($categorySlugs as $categorySlug) {
                $mappedCategoryTerms[] = 'logos_item_categories::'.TaxonomySlugProcessor::addSitePrefixToSlug(
                    $categorySlug,
                    $site
                );
            }

            // Store mapped category references when at least one exists
            if ($mappedCategoryTerms !== []) {
                $data['logos_item_categories'] = $mappedCategoryTerms;
            }
        }

        // Map logo label, normalizing its ID value before resolving it
        $normalizedLabelId = $this->normalizeTermId($metaData['logo_label'] ?? null);

        if ($normalizedLabelId !== null) {
            // ---
            // `logos_item_labels` became a site-scoped structured collection in
            // `UAMS-Web/uams-statamic-archive#2533`, so the field is an `entries` picker
            // holding entry ids, and a `logos_item_labels::slug` reference no
            // longer resolves to anything. The term pass runs first and records
            // each label's entry. An unresolved label is omitted rather than
            // written back as a slug, which would restore the dangling value this
            // replaces (wordpress-importer-archive#1220).
            // ---
            $labelReference = TermDestinationRegistry::referenceFor(
                'logo-list-label',
                $normalizedLabelId
            );

            if ($labelReference !== null) {
                $data['logos_item_labels'] = [$labelReference];
            }
        }

        // Clean up variables to free up memory
        unset(
            $metaData,
            $logoTitle,
            $logoImageId,
            $logoImagePath,
            $logoLink,
            $logoLinkText,
            $logoLinkTarget,
            $categories,
            $categorySlugs,
            $normalizedLabelId
        );
    }

    /**
     * Process logo content for short and long descriptions.
     *
     * @param  SimpleXMLElement  $logoListItem  The logo list item XML element
     * @param  array<int|string, mixed>  &$data  The entry data array to populate
     * @param  ContentProcessor  $contentProcessor  The content processor
     * @param  AssetImporter  $assetImporter  The asset importer
     * @param  OutputInterface  $output  The output interface
     * @param  string  $entrySlug  The entry slug
     * @param  string  $title  The entry title
     * @param  string  $postId  The post ID
     * @param  string  $originalWordPressUrl  The original WordPress URL
     * @param  array<int|string, mixed>  $attachments  Array of attachment items
     * @param  array<int|string, mixed>  $terms  Array of term items
     * @param  array<int|string, mixed>  $items  Array of all items
     * @param  string  $collectionHandle  The collection handle
     * @param  XmlParser  $xmlParser  The XML parser instance
     * @param  string  $entryId  The entry ID
     */
    private function processLogoContent(
        SimpleXMLElement $logoListItem,
        array &$data,
        ContentProcessor $contentProcessor,
        AssetImporter $assetImporter,
        OutputInterface $output,
        string $entrySlug,
        string $title,
        string $postId,
        string $originalWordPressUrl,
        array $attachments,
        array $terms,
        array $items,
        string $collectionHandle,
        XmlParser $xmlParser,
        string $entryId
    ): void {
        // Initialize meta data array
        $metaData = [];

        // Loop through all postmeta nodes to extract metadata
        foreach (XpathHelper::xpathElements($logoListItem, 'wp:postmeta') as $meta) {
            // Extract key from the WordPress XML element
            $key = XpathHelper::getStringValue(
                $meta,
                'wp:meta_key'
            );

            // Extract value from the WordPress XML element
            $value = XpathHelper::getStringValue(
                $meta,
                'wp:meta_value'
            );

            // Add key and value to the meta data array
            $metaData[$key] = $value;
        }

        // Process short description
        $shortDescr = $metaData['logo_shortdescr'] ?? '';

        // Check if the short description is not empty
        if ($shortDescr !== '') {
            // Set short description
            $data['logo_shortdescr'] = $shortDescr;
        }

        // Extract thumbnail ID from meta data for block processors
        $thumbnailId = $metaData['_thumbnail_id'] ?? null;

        // Check if thumbnail ID exists before storing in entry data
        if ($thumbnailId !== null && $thumbnailId !== '') {
            // Store thumbnail ID in entry data for block processors
            $data['_thumbnail_id'] = $thumbnailId;
        }

        // Process long description
        $longDescr = $metaData['logo_longdescr'] ?? '';

        // Check if the long description is not empty
        if ($longDescr !== '') {
            // Convert to Bard format if needed
            $processedContent = $contentProcessor->process(
                $longDescr,
                'default', // site
                $assetImporter,
                $output,
                $xmlParser,
                $title,
                $postId,
                $originalWordPressUrl,
                $attachments,
                $terms,
                $items,
                $data,
                $collectionHandle,
                $entrySlug,
                $entryId
            );

            // Check if the processed content is not empty
            if (
                isset($processedContent['content'])
                && $processedContent['content'] !== []
            ) {
                // Set long description in Bard format
                $data['logo_longdescr'] = $processedContent['content'];
            }
        }

        // Clean up variables to free up memory
        unset(
            $metaData,
            $shortDescr,
            $longDescr,
            $processedContent
        );
    }

    /**
     * Track taxonomy relationships for logo categories and labels.
     *
     * @param  SimpleXMLElement  $logoListItem  The logo list item XML element
     * @param  string  $entrySlug  The entry slug
     * @param  array<int|string, mixed>  $termMap  Map of term IDs to term data
     * @param  string  $site  The site handle
     */
    private function trackTaxonomyRelationships(
        SimpleXMLElement $logoListItem,
        string $entrySlug,
        array $termMap,
        string $site
    ): void {
        // Initialize meta data array
        $metaData = [];

        // Loop through all postmeta nodes to extract metadata
        foreach (XpathHelper::xpathElements($logoListItem, 'wp:postmeta') as $meta) {
            // Extract key from the WordPress XML element
            $key = XpathHelper::getStringValue(
                $meta,
                'wp:meta_key'
            );

            // Extract value from the WordPress XML element
            $value = XpathHelper::getStringValue(
                $meta,
                'wp:meta_value'
            );

            // Add key and value to the meta data array
            $metaData[$key] = $value;
        }

        // ---
        // Map logo category. An absent or blank value parses to no categories, so
        // the default it falls back to cannot be told apart.
        // ---
        // @pest-mutate-ignore: EmptyStringToNotEmpty
        $categories = $this->parseSerializedArray($metaData['logo_category'] ?? '');

        // Loop through each category ID in the parsed array
        foreach ($categories as $categoryId) {
            // Normalize category ID value to a string before term map lookup
            $normalizedCategoryId = $this->normalizeTermId($categoryId);

            // Skip category IDs that cannot be normalized to a string value
            if ($normalizedCategoryId === null) {
                continue;
            }

            // ---
            // Check if the category ID is set in the term map. The term map holds only
            // terms with a slug, so each resolves to a full slug, its own when it has
            // no parent.
            // ---
            if (
                isset($termMap[$normalizedCategoryId])
                && \is_array($termMap[$normalizedCategoryId])
            ) {
                // Use TermLookupHelper to get the full hierarchical slug using ID-based lookup
                $fullSlug = TermLookupHelper::getFullSlugFromMapById(
                    $termMap,
                    $normalizedCategoryId
                );

                // Add site prefix to create the site-specific taxonomy slug
                $siteSpecificSlug = TaxonomySlugProcessor::addSitePrefixToSlug(
                    $fullSlug,
                    $site
                );

                // Add site-specific category slug to logo categories for validation
                $this->logoCategories[$siteSpecificSlug][] = $entrySlug;
            }
        }

        // ---
        // Labels are deliberately NOT tracked here. That map fed a taxonomy-term
        // validator, and `logos_item_labels` became a site-scoped collection in
        // `UAMS-Web/uams-statamic-archive#2533`; the term pass creates the entries a label
        // field references before any item is written (wordpress-importer-archive#1220).
        // ---

        // Clean up variables to free up memory
        unset(
            $metaData,
            $categories,
            $categoryId,
        );
    }

    /**
     * Normalize a term ID value to a string.
     *
     * @param  mixed  $termId  The raw term ID value
     * @return string|null The normalized term ID, or null when invalid
     */
    private function normalizeTermId(
        mixed $termId
    ): ?string {
        // ---
        // Return a string ID as-is. A blank one resolves to nothing: no destination
        // and no term-map entry has a blank id.
        // ---
        if (\is_string($termId)) {
            return $termId;
        }

        // Check if the term ID is an integer value
        if (\is_int($termId)) {
            // Convert integer IDs to string form
            return (string) $termId;
        }

        // Return null for unsupported term ID types
        return null;
    }

    /**
     * Parse a serialized PHP array string.
     *
     * @param  string  $serialized  The serialized array string
     * @return array<int|string, mixed> The parsed array
     */
    private function parseSerializedArray(
        string $serialized
    ): array {
        // Unserialize the serialized array
        $unserialized = @\unserialize($serialized, ['allowed_classes' => false]);

        // Return the unserialized array if it is an array, otherwise return an empty array
        return \is_array($unserialized) ? $unserialized : [];
    }

    /**
     * Check if the content type uses ShowPeople shortcodes.
     */
    public function hasShowPeople(): bool
    {
        return false;
    }

    /**
     * Check if any logo list items have classic block content.
     *
     * Logo list items typically don't contain classic block content.
     *
     * @return bool Always returns false for logo list items
     */
    public function hasClassicBlock(): bool
    {
        return false;
    }

    /**
     * Get logo categories for validation.
     *
     * @return array<int|string, mixed>
     */
    public function getPostCategories(): array
    {
        return $this->logoCategories;
    }

    /**
     * Get logo labels for validation, of which there are none.
     *
     * `logos_item_labels` is a site-scoped collection since
     * `UAMS-Web/uams-statamic-archive#2533`, and the term pass creates its entries, so
     * no label is left for a validator to create (wordpress-importer-archive#1220).
     *
     * @return array<int|string, mixed> Always empty
     */
    public function getPostTags(): array
    {
        return [];
    }

    /**
     * Validates logos_item_categories terms used in logo list entries.
     *
     * Validates that every logos_item_categories term referenced in a logo list
     * entry exists in its destination. Labels are not validated: the term pass
     * creates the `logos_item_labels` entries a label field references (wordpress-importer-archive#1220).
     *
     * @param  string  $collectionHandle  The collection handle (e.g., 'logo_list_items')
     * @param  string  $site  The Statamic site handle
     * @param  OutputInterface  $output  Console output for logging
     */
    public function validateTaxonomies(
        string $collectionHandle,
        string $site,
        OutputInterface $output
    ): void {
        // Create a validator for logos item categories taxonomy terms
        $categoriesValidator = new LogosItemCategoriesValidator;

        // Validate that all logos item categories referenced in entries exist
        $categoriesValidator->validateTerms(
            $this->getPostCategories(),
            $collectionHandle,
            $site,
            $output
        );
    }
}
