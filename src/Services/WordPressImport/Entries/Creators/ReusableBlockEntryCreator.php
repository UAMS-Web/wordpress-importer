<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\Creators;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Statamic\Entries\Entry;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\HtmlTagStripper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportProgress;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\TextProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\WpBlockRegistry;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;

/**
 * Creates Statamic entries for WordPress 'wp_block' post type (reusable blocks).
 *
 * Handles the import of WordPress reusable blocks (wp_block post type) into the
 * reusable_content collection. Determines the appropriate blueprint (basic_page or
 * landing_page) based on the content, similar to how PageEntryCreator determines
 * blueprints for pages. Processes content into Bard format for basic_page blueprint
 * or page_builder format for landing_page blueprint.
 */
class ReusableBlockEntryCreator extends BaseEntryCreator
{
    /**
     * Creates Statamic entries from WordPress 'wp_block' content.
     *
     * Iterates through 'wp_block' items in the XML, extracting fields like title,
     * slug, and content, and mapping them to the 'reusable_content' collection
     * blueprint (basic_page or landing_page). Determines blueprint based on whether
     * ACF blocks are present in the content, similar to PageEntryCreator logic.
     * Generates unique slugs and IDs, skips trashed items, and processes content
     * into appropriate field formats.
     *
     * @param  string  $site  Site handle (e.g., 'default') for entry localization
     * @param  string  $collectionHandle  Collection handle (e.g., 'reusable_content')
     * @param  XmlParser  $xmlParser  Parses WordPress XML export data
     * @param  AssetImporter  $assetImporter  Handles asset imports
     * @param  OutputInterface  $output  Console output for logging progress
     * @param  MetadataMapper  $metadataMapper  Maps additional metadata to entries
     * @param  ContentProcessor  $contentProcessor  Processes content into field arrays
     * @param  array<int|string, mixed>|null  $minervaKbSettings  Optional MinervaKB settings from JSON file
     * @return array<int|string, mixed> Empty array (reusable blocks don't need page data for tree building)
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
        // ---
        // Get wp_block items from the parser (respects filtered items if set)
        // ---

        // Initialize array to track slug counts for duplicate handling
        $slugCounts = [];

        // ---
        // Get attachments and terms from XML parser for processing
        // ---

        // Get attachments from XML parser (uses streaming internally when XML not loaded)
        $attachments = $xmlParser->getAttachments();

        // Get WordPress terms from XML parser for content processing
        $terms = $xmlParser->getTerms();

        // Get wp_block items from parser (will use filtered items if set via setItems)
        $wpBlocks = $xmlParser->getItems('wp_block');

        // Loop through wp_block items from parser
        foreach (ImportProgress::track($output, 'wp_block', $wpBlocks) as $wpBlock) {
            // Decode HTML entities and strip HTML tags from title
            $title = HtmlTagStripper::strip(
                TextProcessor::decodeEntities(
                    (string) $wpBlock->title
                )
            );
            $title = $title !== '' ? $title : 'Untitled Reusable Block';

            // Extract the WordPress post ID from XML with fallback
            $postId = XpathHelper::getStringValue(
                $wpBlock,
                'wp:post_id',
                'unknown'
            );

            // ---
            // Extract fields with safety checks
            // ---

            // Extract slug from XML or generate from title and post ID
            $slug = XpathHelper::getStringValue(
                $wpBlock,
                'wp:post_name'
            );

            // Extract link for URL mapping (needed for log context)
            $link = (string) $wpBlock->link;

            // Set the WordPress page link in processing context for accurate logging
            ProcessingContext::setWordPressPageLink($link);

            // Check if slug is empty and generate from title and post ID
            if ($slug === '') {
                // Generate slug from title and post ID
                $slug = Str::slug("{$title}-{$postId}");

                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'site' => $site,
                        'title' => $title,
                    ],
                    $link
                );

                // Log warning about missing post name
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        "ReusableBlockEntryCreator::createEntries: Missing wp:post_name for wp_block ID '$postId', using generated slug '$slug'",
                        $logData['page_url']
                    ),
                    $logData['context']
                );
            }

            // Extract status from XML with default fallback
            $status = XpathHelper::getStringValue(
                $wpBlock,
                'wp:status',
                'publish'
            );

            // Extract post date with fallback to pubDate
            $postDate = $this->extractPostDateString($wpBlock);

            // ---
            // Skip items with statuses that should be excluded
            // ---

            // Check if item status should be skipped
            if (XpathHelper::shouldSkipStatus($status)) {
                // Output message about skipping item
                $output->writeln(
                    "<comment>Skipping wp_block with status '$status': $title (ID: $postId)</comment>"
                );

                // Skip to next item in loop
                continue;
            }

            // Extract the encoded content from WordPress XML
            $contentEncoded = XpathHelper::getStringValue(
                $wpBlock,
                'content:encoded'
            );

            // Extract postmeta for _thumbnail_id
            $thumbnailId = XpathHelper::getStringValue(
                $wpBlock,
                'wp:postmeta[wp:meta_key="_thumbnail_id"]/wp:meta_value'
            );

            // ---
            // Determine blueprint based on content (similar to PageEntryCreator)
            // ---

            // ---
            // Ask the processor rather than scanning for ACF blocks here: it sends any
            // page builder content to `page_builder`, so a block chosen as a basic page
            // on a narrower test would store neither field (#239).
            // ---
            $hasPageBuilderContent = $contentProcessor->hasPageBuilderContent($contentEncoded);

            // Choose the blueprint that holds the field the content will arrive in
            $blueprint = $hasPageBuilderContent
                ? 'reusable_content_landing_page'
                : 'reusable_content_basic_page';

            // Initialize entry data based on blueprint
            $data = [
                'blueprint' => $blueprint,
                'title' => $title,
                'site' => $site,
                'original_wordpress_url' => $link,
            ];

            // Store thumbnail ID in entry data for block processors
            if ($thumbnailId !== '') {
                $data['_thumbnail_id'] = $thumbnailId;
            }

            // Set published status from wp:status, unless WP-Members gates the block (wordpress-importer-archive#1241)
            $data['published'] = $this->publishUnlessWpMembersGated(
                $status === 'publish',
                $this->readWpMembersBlock($wpBlock),
                'ReusableBlockEntryCreator::createEntries',
                $output
            );

            // ---
            // Generate slug and ID
            // ---

            // Generate unique slug and entry ID
            [$entrySlug, $entryId] = $this->generateSlugAndId(
                $slug,
                $postId,
                $title
            );

            // Create entry date from post date (reusable_content collection doesn't use
            // scheduled post handling)
            $entryDate = $this->createEntryDate(
                $postDate,
                false
            );

            // Set entry ID in data array
            $data['id'] = $entryId;

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
                // Still register URL mapping for link resolution even for skipped entries
                LinkProcessor::registerUrlMapping(
                    $link,
                    $postId
                );

                // Register the existing entry, so a reference to this block resolves
                $this->registerBlockEntry(
                    $postId,
                    $existingEntry,
                    $entryId
                );

                // Skip to next item in loop
                continue;
            }

            // Log that we're processing this reusable block
            $output->writeln("Processing reusable content '$entrySlug' with content length: ".\strlen($contentEncoded));

            // ---
            // Process content based on blueprint
            // ---

            // Process content using ContentProcessor
            $parsedContent = $contentProcessor->process(
                $contentEncoded,
                $site,
                $assetImporter,
                $output,
                $xmlParser,
                $title,
                $postId,
                $link,
                $attachments,
                $terms,
                [], // items array (empty for now)
                $data,
                $collectionHandle,
                $entrySlug,
                $entryId
            );

            // Check if the block holds page builder content
            if ($hasPageBuilderContent) {
                // Get page_builder content from parsed content result
                $pageBuilderContent = $parsedContent['page_builder'];

                // Check if page_builder content is not empty before assigning
                if ($pageBuilderContent !== []) {
                    // Assign page_builder field to entry data for landing_page blueprint
                    $data['page_builder'] = $pageBuilderContent;
                }
            } else { // Otherwise
                // Resolve processed content as an array for empty-content checks
                $processedContent = $parsedContent['content'];
                $contentForCheck = \is_array($processedContent) ? $processedContent : [];

                // Check if the processed content is empty
                $isEmptyContent = $this->isEmptyContent($contentForCheck);

                // ---
                // Assign content to data array only if it's not empty
                // ---

                // Check if processed content is not empty
                if (! $isEmptyContent) {
                    // Assign content to data array for basic_page blueprint
                    $data['content'] = $parsedContent['content'];
                }
            }

            // Map additional metadata to entry data
            $metadataMapper->mapMetadata(
                $wpBlock,
                $data,
                $xmlParser,
                $assetImporter,
                $attachments,
                $output
            );

            // Register URL mapping for internal link resolution
            LinkProcessor::registerUrlMapping(
                $link,
                $postId
            );

            // Add updated_at field from post modified date
            $this->addUpdatedAtField(
                $data,
                $wpBlock,
                'ReusableBlockEntryCreator::createEntries'
            );

            // Save entry to Statamic collection
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

            // Check if entry was saved successfully
            if ($entry instanceof Entry) {
                // Register mapping in global registry for block processor access
                $this->registerBlockEntry(
                    $postId,
                    $entry,
                    $entryId
                );
            }

            // Unset loop variables to free memory
            unset(
                $wpBlock,
                $title,
                $postId,
                $slug,
                $status,
                $postDate,
                $link,
                $contentEncoded,
                $hasPageBuilderContent,
                $blueprint,
                $data,
                $entrySlug,
                $entryId,
                $entryDate,
                $existingEntry,
                $parsedContent,
                $isEmptyContent,
                $entry
            );
        }

        // Unset method variables to free memory
        unset(
            $slugCounts,
            $wpBlocks,
            $attachments,
            $terms
        );

        // Return empty array (reusable blocks don't need page data for tree building)
        return [];
    }

    /**
     * Register a block's entry in WpBlockRegistry under the entry's own id.
     *
     * That id, not the one generated for this run: a matched entry keeps its
     * existing id (wordpress-importer-archive#767), so a map keyed on the generated
     * id would name an entry that does not exist, the same stale-id failure
     * wordpress-importer-archive#761 fixed in the deferred related-articles pass.
     *
     * @param  string  $postId  The block's WordPress post ID
     * @param  Entry  $entry  The saved or existing entry
     * @param  string  $generatedEntryId  The id generated for this run, for an entry without its own
     */
    private function registerBlockEntry(
        string $postId,
        Entry $entry,
        string $generatedEntryId
    ): void {
        // Prefer the entry's own id
        $entryId = \is_string($entry->id()) ? $entry->id() : $generatedEntryId;

        // Register mapping in global registry for block processor access
        WpBlockRegistry::registerMapping(
            $postId,
            $entryId
        );
    }

    /**
     * Check if the content type uses ShowPeople shortcodes.
     *
     * @return bool Always false for reusable blocks
     */
    public function hasShowPeople(): bool
    {
        // Reusable blocks don't use ShowPeople shortcodes
        return false;
    }

    /**
     * Get post categories for validation (if applicable).
     *
     * @return array<int|string, mixed> Empty array for reusable blocks
     */
    public function getPostCategories(): array
    {
        // Reusable blocks don't use post categories
        return [];
    }

    /**
     * Get post tags for validation (if applicable).
     *
     * @return array<int|string, mixed> Empty array for reusable blocks
     */
    public function getPostTags(): array
    {
        // Reusable blocks don't use post tags
        return [];
    }

    /**
     * Validates taxonomy terms specific to this entry creator's content type.
     *
     * Reusable blocks don't use taxonomies, so this is a no-op.
     *
     * @param  string  $collectionHandle  The collection handle
     * @param  string  $site  The Statamic site handle
     * @param  OutputInterface  $output  Console output for logging
     */
    public function validateTaxonomies(
        string $collectionHandle,
        string $site,
        OutputInterface $output
    ): void {
        // Reusable blocks don't use taxonomies, so no validation needed
    }
}
