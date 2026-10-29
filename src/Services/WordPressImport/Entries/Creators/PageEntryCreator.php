<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Statamic\Entries\Entry;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\EntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\ModulesParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\ContentStreamFactory;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\PostCategoryEntryResolver;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\Validators\PeopleGroupValidator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\EntryQueryHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ExcerptResolver;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\FieldMapper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\HtmlTagStripper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportProgress;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\PeopleShortcodeProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\WpBlockBlockProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\TextProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;

/**
 * Creates Statamic entries for WordPress 'page' content type.
 *
 * Handles the specific mapping and creation logic for pages, including ACF block
 * detection, integration with ShowPeople shortcodes, and adding notes for Classic
 * block content when full parsing is not implemented. Uses the parent EntryCreator
 * to check for Classic blocks. Skips items with 'trash' or 'virtual' statuses to
 * prevent importing them into Statamic.
 */
class PageEntryCreator extends BaseEntryCreator
{
    /**
     * Flag indicating if show-people shortcodes are present.
     */
    private bool $hasShowPeople = false;

    /**
     * Constructs the PageEntryCreator with its dependencies.
     *
     * Initializes the parent EntryCreator to access methods like hasClassicBlock.
     *
     * @param  EntryCreator  $entryCreator  The parent entry creator instance
     */
    public function __construct(private EntryCreator $entryCreator) {}

    /**
     * Creates Statamic entries from WordPress 'page' content.
     *
     * Iterates through 'page' items in the XML, extracting fields like title, slug,
     * and content, and mapping them to the 'pages' collection blueprint
     * (basic_page or landing_page). Handles ACF blocks, adds notes for Classic
     * blocks, and processes metadata. Generates unique slugs and IDs, skips
     * trashed items, and logs warnings for missing required fields. Skips the
     * MinervaKB home page (kb_page) so the knowledge base can mount at its URL
     * instead (see wordpress-importer-archive#297).
     *
     * @param  string  $site  Site handle (e.g., 'default') for entry localization
     * @param  string  $collectionHandle  Collection handle (e.g., 'pages')
     * @param  XmlParser  $xmlParser  Parses WordPress XML export data
     * @param  AssetImporter  $assetImporter  Handles asset imports
     * @param  OutputInterface  $output  Console output for logging progress
     * @param  MetadataMapper  $metadataMapper  Maps additional metadata to entries
     * @param  ContentProcessor  $contentProcessor  Processes content into field arrays
     * @param  array<int|string, mixed>|null  $minervaKbSettings  Optional MinervaKB settings from JSON file
     * @return array<int|string, mixed> Page data for tree building, mapping post IDs to entry IDs
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
        // Import the post taxonomies BEFORE any page is parsed.
        //
        // A news block in a page's `page_builder` stores its category as a
        // `post_categories` **entry id**, resolved through
        // {@see PostCategoryEntryResolver}. Those entries are created by the term
        // pass, which belonged to `PostEntryCreator` alone — and the operator
        // selects content types one at a time, so on a first import the page is
        // routinely parsed before any category entry exists. The block was then
        // written with no `category` key at all, which the host reads as "no
        // filter" and renders as *every* news post rather than the one category
        // WordPress asked for. Running the pass here prevents the miss instead of
        // repairing it afterwards, and covers the `[blogroll]` shortcode on the
        // same terms (wordpress-importer-archive#624).
        //
        // The handle list is `PostEntryCreator`'s own, deliberately.
        // `importTaxonomyTermsAtBeginning()` dedups on the site plus the WHOLE
        // sorted taxonomy list, so passing a narrower `['category']` would key
        // differently and make the post pass redo the entire term import,
        // structured `category` tree included. Passing the same list makes the
        // key byte-identical, so the post pass's own call becomes a no-op and the
        // total work for a page+post import is unchanged.
        // ---
        $this->initializeTerms(
            $xmlParser,
            PostEntryCreator::getTaxonomyHandlesForPostImport(),
            $site,
            $output
        );

        // ---
        // Import the logo list taxonomies before any page is parsed, for the
        // same reason. A logo-list block stores its category and label filters as
        // `logos_item_categories` and `logos_item_labels` entry ids, and
        // `ImportWordPress` imports pages before logo list items, so without this
        // no block in a run could resolve either filter (wordpress-importer-archive#1220).
        //
        // `importTaxonomyTermsAtBeginning()` rather than `initializeTerms()`: the
        // latter rebuilds this creator's term maps for the handles it is given,
        // which would replace the post taxonomies' maps built just above. The
        // handle list is `LogoListItemEntryCreator`'s own, so its pass later in
        // the run keys identically and is a no-op.
        // ---
        $this->importTaxonomyTermsAtBeginning(
            $xmlParser,
            LogoListItemEntryCreator::getTaxonomyHandlesForLogoListImport(),
            $site,
            $output
        );
        // Open a streaming parser spanning every part. A source that can serve
        // none raises rather than importing nothing and reporting success (wordpress-importer-archive#662).
        $xmlStreamParser = ContentStreamFactory::open(
            $xmlParser,
            'PageEntryCreator::createEntries'
        );

        // Get attachments using streaming for processing
        $attachments = [];
        foreach ($xmlStreamParser->streamItems('attachment') as $attachment) {
            $attachments[] = $attachment;
        }

        $terms = $xmlParser->getTerms(); // Now always uses streaming

        foreach (ImportProgress::track($output, 'page', $xmlStreamParser->streamItems('page'), static fn (): int => $xmlStreamParser->countItems('page')) as $page) {
            $hasAcfBlocks = false;

            // Decode HTML entities and strip HTML tags from title
            $title = HtmlTagStripper::strip(
                html_entity_decode(
                    (string) $page->title,
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                )
            );
            $title = $title !== '' ? $title : 'Untitled Page';

            // Extract the WordPress post ID from XML with fallback
            $postId = XpathHelper::getStringValue(
                $page,
                'wp:post_id',
                'unknown'
            );

            $link = (string) $page->link;

            // Extract fields with safety checks
            $slugNodes = XpathHelper::xpathElements($page, 'wp:post_name');
            $slug = $slugNodes !== [] ? (string) $slugNodes[0] : Str::slug($title.'-'.$postId);
            if ($slugNodes === []) {
                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'site' => $site,
                        'title' => $title,
                    ],
                    $link
                );

                // Log warning for missing post name
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        "PageEntryCreator::createEntries: Missing wp:post_name for page ID '$postId', using generated slug '$slug'",
                        $logData['page_url']
                    ),
                    $logData['context']
                );
            }

            $statusNodes = XpathHelper::xpathElements($page, 'wp:status');
            $status = $statusNodes !== [] ? (string) $statusNodes[0] : 'publish';
            if ($statusNodes === []) {
                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'site' => $site,
                        'title' => $title,
                    ],
                    $link
                );

                // Log warning for missing status
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        "PageEntryCreator::createEntries: Missing wp:status for page ID '$postId', defaulting to 'publish'",
                        $logData['page_url']
                    ),
                    $logData['context']
                );
            }

            // Extract the post password, blank when the page has none
            $postPassword = XpathHelper::getStringValue(
                $page,
                'wp:post_password'
            );

            // Extract post date with fallback to pubDate
            $postDate = $this->extractPostDateString($page);

            $parentNodes = XpathHelper::xpathElements($page, 'wp:post_parent');
            $parentId = $parentNodes !== [] ? (string) $parentNodes[0] : '0';

            // Extract the menu order, zero when the page has none
            $menuOrder = XpathHelper::getIntValue(
                $page,
                'wp:menu_order'
            );

            // Extract the encoded content from WordPress XML
            $contentEncoded = XpathHelper::getStringValue(
                $page,
                'content:encoded'
            );

            // Set the WordPress page link in processing context for accurate logging
            ProcessingContext::setWordPressPageLink($link);

            // Extract postmeta for template and modules
            $postmeta = [];
            foreach (XpathHelper::xpathElements($page, 'wp:postmeta') as $meta) {
                // Extract the meta key from WordPress postmeta
                $key = XpathHelper::getStringValue(
                    $meta,
                    'wp:meta_key'
                );

                // Extract the meta value from WordPress postmeta
                $value = XpathHelper::getStringValue(
                    $meta,
                    'wp:meta_value'
                );

                $postmeta[$key] = $value;
            }

            $hasModulesTemplate = ($postmeta['_wp_page_template'] ?? null) === 'templates/modules.php';
            $hasUamswpModules = isset($postmeta['uamswp_modules']);

            // Extract thumbnail ID from postmeta for featured image
            $thumbnailId = $postmeta['_thumbnail_id'] ?? null;

            // ---
            // Resolve the required `excerpt` summary with the precedence the post
            // importer uses: author excerpt > SEO titles desc > SEO meta desc (wordpress-importer-archive#429)
            // ---
            $excerpt = ExcerptResolver::resolve(
                XpathHelper::getStringValue($page, 'excerpt:encoded'),
                $postmeta['_seopress_titles_desc'] ?? '',
                $postmeta['seo_meta_description'] ?? ''
            );

            // Skip posts with classifieds_type meta key
            if (isset($postmeta['classifieds_type'])) {
                continue;
            }

            // ---
            // Initialize variable to check if this page matches kb_page from MinervaKB
            // settings
            // ---
            $isKnowledgeBaseHomePage = false;

            // ---
            // Check MinervaKB settings for a kb_page naming this page. A blank or false
            // value names no page, even for a page whose post id is blank.
            // ---
            $kbPageValue = $minervaKbSettings['kb_page'] ?? null;

            if (
                \is_scalar($kbPageValue)
                && $kbPageValue !== ''
                && $kbPageValue !== false
                && (string) $kbPageValue === $postId
            ) {
                // Mark this page as the knowledge base home page
                $isKnowledgeBaseHomePage = true;
            }

            // ---
            // Skip the MinervaKB home page entirely: it is no longer materialized
            // as a `pages` entry. The knowledge base root mounts at this URL
            // instead (see wordpress-importer-archive#297 and KbEntryCreator). Still register the URL
            // mapping so links to the kb_page resolve to the knowledge base root,
            // whose entry ID embeds this same WordPress post ID.
            // ---
            if ($isKnowledgeBaseHomePage) {
                // Register the WordPress URL so internal links still resolve
                LinkProcessor::registerUrlMapping(
                    $link,
                    $postId
                );

                // Inform the user this page is handled by the knowledge base mount
                $output->writeln("<info>Skipping page ID '{$postId}' — the knowledge base will mount at this URL instead of a knowledge_base_home page.</info>");

                // Move on without creating a `pages` entry for the kb_page
                continue;
            }

            // All remaining pages use the basic_page blueprint
            $blueprint = 'basic_page';

            // Initialize entry data based on blueprint
            $data = [
                'blueprint' => $blueprint,
                'template' => 'pages/default',
                'title' => $title,
                'title_options' => 'normal',
                'subsection' => false,
                'hide_breadcrumbs' => false,
                'hide_from_menu' => false,
                'hide_child_menu' => false,
                'header_graphic' => ['image' => null, 'body' => null],
                'site' => $site,
                'scripts_head' => ['code' => null, 'mode' => 'htmlmixed'],
                'scripts_body' => ['code' => null, 'mode' => 'htmlmixed'],
                'robots_index_meta' => false,
                'robots_follow_meta' => false,
                'robots_odp_meta' => false,
                'robots_imageindex_meta' => false,
                'robots_archive_meta' => false,
                'robots_snippet_meta' => false,
                'excerpt' => $excerpt,
                'original_wordpress_url' => $link,
                // ---
                // Loose keys for the migration redirect generator; a page's `?page_id=<id>`
                // ugly permalink needs the numeric post id, which is otherwise only folded
                // into the Statamic entry id (wordpress-importer-archive#446).
                // ---
                ...XpathHelper::extractMigrationRedirectMetadata($page),
            ];

            // Store thumbnail ID in entry data for block processors
            if ($thumbnailId !== null && $thumbnailId !== '') {
                $data['_thumbnail_id'] = $thumbnailId;
            }

            // Map status and visibility fields
            $isScheduledPost = $this->mapStatusAndVisibility(
                $status,
                $postPassword,
                $postDate,
                $data,
                $collectionHandle,
                $output,
                wpmemBlock: $this->readWpMembersBlock($page)
            );

            // Generate slug and ID with date handling if needed
            [$entrySlug, $entryId] = $this->generateSlugAndId(
                $slug,
                $postId,
                $title
            );
            $entryDate = $this->createEntryDate(
                $postDate,
                $isScheduledPost
            );
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

                // Log that the entry was skipped
                $output->writeln("<comment>Skipped entry '$entrySlug' as it already exists.</comment>");

                // Add page data with the existing entry's ID so the tree can be built correctly
                $existingEntryId = $this->normalizeEntryId(
                    $existingEntry->id(),
                    $entryId
                );
                $this->addPageData(
                    $postId,
                    $existingEntryId,
                    $parentId,
                    $menuOrder,
                    $title,
                    $link,
                    $existingEntry
                );

                // Skip to the next iteration of the loop
                continue;
            }

            // Log that we're processing this page
            $output->writeln("Processing page '$entrySlug' with content length: ".\strlen($contentEncoded));

            // Check for show-people shortcodes to set hasShowPeople flag
            if (
                \preg_match(
                    '/\[show-people.*?]/',
                    $contentEncoded
                ) === 1
            ) {
                $this->hasShowPeople = true;
            }

            // ---
            // Check if content contains page builder blocks (including hero) BEFORE
            // removing hero block. This ensures pages with only hero blocks are still
            // detected as landing pages.
            // ---
            $hasAcfBlocksIncludingHero = $this->checkForPageBuilderBlocks(
                $contentEncoded,
                $xmlParser
            );

            // Get page_title_options value from postmeta for hero detection
            $pageTitleOptions = $postmeta['page_title_options'] ?? null;

            // ---
            // Check if page_title_options is hidden/none/graphic and hero block/module exists
            // ---

            // Initialize flag to track if hero should be used for title
            $shouldUseHeroForTitle = false;

            // Initialize variable to store hero block data
            $heroBlockData = null;

            // Initialize variable to store hero module data
            $heroModuleData = null;

            // ---
            // Check if page_title_options is one of the values that should trigger hero
            // detection
            // ---
            // The needles are not numeric, so a loose comparison matches the strict one
            // @pest-mutate-ignore: TrueToFalse
            if (
                \in_array(
                    $pageTitleOptions,
                    [
                        'hidden',
                        'none',
                        'graphic',
                    ],
                    true
                )
            ) {
                // Check for wp:acf/hero block in content
                $heroBlockData = $this->extractHeroBlockData(
                    $contentEncoded
                );

                // Check for modules_hero module if using modules template
                if (
                    $hasModulesTemplate
                    && $hasUamswpModules
                ) {
                    // Extract hero module data from postmeta
                    $heroModuleData = $this->extractHeroModuleData(
                        $postmeta['uamswp_modules'],
                        $postmeta
                    );
                }

                // ---
                // Set flag if hero data found
                // ---

                // Check if either hero block or module data was found
                if (
                    $heroBlockData !== null
                    || $heroModuleData !== null
                ) {
                    // Set flag to use hero for title
                    $shouldUseHeroForTitle = true;
                }
            }

            // ---
            // If hero should be used for title, remove hero block from content
            // ---

            // ---
            // Check if hero block data exists, which is only read when the hero is used
            // for the title
            // ---
            if ($heroBlockData !== null) {
                // Remove hero block from content before processing
                $contentEncoded = $this->removeHeroBlockFromContent(
                    $contentEncoded
                );
            }

            // If this is a modules template page, use ModulesParser and skip <content:encoded>
            if ($hasModulesTemplate && $hasUamswpModules) {
                // Parse the serialized uamswp_modules array
                $modulesData = @\unserialize($postmeta['uamswp_modules'], ['allowed_classes' => false]);
                if (! \is_array($modulesData)) {
                    Log::error(
                        'PageEntryCreator::createEntries: Failed to unserialize uamswp_modules for page',
                        [
                            'postId' => $postId,
                            'slug' => $slug,
                            'uamswp_modules' => $postmeta['uamswp_modules'],
                        ]
                    );
                    $modulesData = [];
                }

                // A title of punctuation alone generates a blank slug, so keep the title
                $data['post_name'] = $entrySlug !== '' ? $entrySlug : $title;

                // Set entry data in processing context for module parsers
                ProcessingContext::setEntryData($data);

                // Set collection handle in processing context for module parsers
                ProcessingContext::setCollectionHandle($collectionHandle);

                // Set Statamic entry ID in processing context for module parsers
                ProcessingContext::setStatamicEntryId($entryId);

                // ---
                // Set XML path in processing context for module parsers (required for link
                // processing)
                // ---
                ProcessingContext::setXmlPath($xmlParser->getFilePath());

                // If hero should be used for title, filter out modules_hero from modulesData
                if (
                    $shouldUseHeroForTitle
                    && $heroModuleData !== null
                ) {
                    // ---
                    // Filter out modules_hero from modules data array, preserving original
                    // array keys to maintain mapping between array indices and postmeta
                    // prefixes (e.g., index 3 must map to uamswp_modules_3_*)
                    // ---
                    $modulesData = \array_filter(
                        $modulesData,
                        fn ($moduleType): bool => $moduleType !== 'modules_hero'
                    );

                    // Note: Do NOT call array_values() here as it would re-index the array
                    // and break the mapping between array indices and postmeta field prefixes
                }

                $modulesParser = new ModulesParser;
                $data['page_builder'] = $modulesParser->parseModules(
                    $modulesData,
                    $site,
                    $assetImporter,
                    $output,
                    $attachments,
                    $xmlStreamParser,
                    $postmeta
                );
                // Do not set 'content' field
            } else { // Otherwise
                // ---
                // Check if content contains page builder blocks (direct or in referenced
                // wp_block posts). Use the check that includes hero blocks (done before
                // removal) to ensure pages with only hero blocks are still detected as
                // landing pages.
                // ---
                $hasAcfBlocks = $this->checkForPageBuilderBlocks(
                    $contentEncoded,
                    $xmlParser
                );

                // ---
                // If hero block was removed, we need to check if there were ACF blocks including
                // the hero block. If the only ACF block was the hero block, hasAcfBlocks will be
                // false after removal, but hasAcfBlocksIncludingHero will be true.
                // ---
                if (
                    ! $hasAcfBlocks
                    && $hasAcfBlocksIncludingHero
                ) {
                    // Hero block was the only ACF block, so use the pre-removal check result
                    $hasAcfBlocks = true;
                }

                // ---
                // Check if page should use landing_page blueprint (has ACF blocks and is not
                // knowledge base home)
                // ---
                if (
                    $hasAcfBlocks
                ) {
                    $data['blueprint'] = 'landing_page';
                    $data['template'] = 'pages/page_landing';
                    $data['title_options'] = 'graphic';
                }

                // ---
                // Handle Classic, Row or Freeform block content, checked in that order:
                // the page gets a note naming the block in place of its content, and
                // its ACF blocks are still processed (e.g. landing pages with ACF
                // blocks and Classic Editor shortcodes)
                // ---
                $unmappedBlock = $this->findUnmappedBlock($postId);

                if ($unmappedBlock !== null) {
                    $data['content'] = $this->unmappedBlockNote(
                        $unmappedBlock['kind'],
                        $unmappedBlock['info']
                    );

                    $this->processAcfBlocksForPageBuilder(
                        $hasAcfBlocks,
                        $contentProcessor,
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
                        $data,
                        $collectionHandle,
                        $entrySlug,
                        $entryId
                    );
                } else {
                    // ---
                    // Set knowledge_base ID in processing context for all pages,
                    // so block parsers can access it when processing MinervaKB blocks
                    // ---
                    // Resolve the knowledge base entry ID for this site
                    $knowledgeBaseEntryId = $this->findKnowledgeBaseEntryId($site);

                    // Check if knowledge_base entry ID was found
                    if ($knowledgeBaseEntryId !== null) {
                        // Set knowledge_base ID in processing context
                        ProcessingContext::setKnowledgeBaseId($knowledgeBaseEntryId);
                    }

                    // Process content, including XML parser for table linking
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
                    // Normalize parsed content field before empty-content checks
                    $parsedBodyContent = $parsedContent['content'] ?? [];
                    if (! \is_array($parsedBodyContent)) {
                        $parsedBodyContent = [];
                    }

                    $isEmptyContent = $this->isEmptyContent($parsedBodyContent);

                    // The assignment above is unconditional for this branch, so
                    // page_builder is always set here, so the warning that used to
                    // guard the opposite case was unreachable.
                    if ($hasAcfBlocks) {
                        $data['page_builder'] = $parsedContent['page_builder'];
                    }

                    if (! $isEmptyContent) {
                        $data['content'] = $parsedContent['content'];
                    }
                }
            }

            // Map metadata and override title_options if needed
            $metadataMapper->mapMetadata(
                $page,
                $data,
                $xmlParser,
                $assetImporter,
                $attachments,
                $output
            );

            // ---
            // Override title_options to graphic for specific basic_page entries
            // ---
            $this->overrideTitleOptionsForBasicPages(
                $data,
                $parentId,
                $link,
                $xmlParser,
                $assetImporter
            );

            // Loop through postmeta to process page title options
            foreach (XpathHelper::xpathElements($page, 'wp:postmeta') as $meta) {
                // Extract the meta key from WordPress postmeta
                $key = XpathHelper::getStringValue(
                    $meta,
                    'wp:meta_key'
                );

                // Extract the meta value from WordPress postmeta
                $value = XpathHelper::getStringValue(
                    $meta,
                    'wp:meta_value'
                );

                // Check if page title options should be set to graphic
                if (
                    $key === 'page_title_options'
                    && $value === 'none'
                    && $hasAcfBlocks
                ) {
                    // Set title options to graphic for pages with ACF blocks
                    $data['title_options'] = 'graphic';
                }
            }

            // ---
            // If hero should be used for title, set title_options to hero and map hero data
            // ---

            // Check if hero should be used for title
            if ($shouldUseHeroForTitle) {
                // Use hero block data if available, otherwise use module data
                $heroData = $heroBlockData ?? $heroModuleData;

                // Set title_options to hero
                $data['title_options'] = 'hero';

                // Map hero data to header_hero field when hero data is valid
                if (\is_array($heroData)) {
                    $this->mapHeroDataToHeaderHero(
                        $heroData,
                        $data,
                        $site,
                        $assetImporter,
                        $attachments,
                        $output
                    );
                }
            }

            // Register URL mapping for internal link resolution
            LinkProcessor::registerUrlMapping(
                $link,
                $postId
            );

            // Validate page entry fields before saving
            $this->validatePageEntryFields(
                $entrySlug,
                $data
            );

            // Add updated_at field from post modified date
            $this->addUpdatedAtField(
                $data,
                $page,
                'PageEntryCreator::createEntries'
            );

            // Save the page entry to the collection
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

            // ---
            // Check if the entry was successfully saved or found saveEntry now returns the
            // existing entry when found with "Skip matching files" behavior, so we always
            // have an entry to add to pageData
            // ---
            if ($entry instanceof Entry) {
                // Add page data to tracking arrays for hierarchy management
                // Use the actual entry ID from the saved entry object to ensure
                // we use the correct ID that exists in the filesystem
                $actualEntryId = $this->normalizeEntryId(
                    $entry->id(),
                    $entryId
                );

                // Log if there's an ID mismatch
                if ($actualEntryId !== $entryId) {
                    // Enhance log context with entry information
                    $logData = LogContextHelper::enhanceWithEntryContext(
                        [
                            'postId' => $postId,
                            'generated_entry_id' => $entryId,
                            'actual_entry_id' => $actualEntryId,
                            'slug' => $entrySlug,
                        ],
                        $link
                    );

                    // Log warning for ID mismatch
                    Log::warning(
                        LogContextHelper::formatMessageWithEntry(
                            'PageEntryCreator::createEntries: Entry ID mismatch detected',
                            $logData['page_url']
                        ),
                        $logData['context']
                    );
                }

                $this->addPageData(
                    $postId,
                    $actualEntryId,
                    $parentId,
                    $menuOrder,
                    $title,
                    $link,
                    $entry
                );
            } else {
                // ---
                // If saveEntry returned null (should be rare, only for other skip scenarios), log
                // a warning and try to find the entry as a fallback. This ensures the page is
                // still added to pageData for tree building.
                // ---

                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'postId' => $postId,
                        'entryId' => $entryId,
                        'slug' => $entrySlug,
                        'site' => $site,
                        'collection' => $collectionHandle,
                    ],
                    $link
                );

                // Log warning about unexpected null return from saveEntry
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        'PageEntryCreator::createEntries: saveEntry returned null for page, attempting to find existing entry',
                        $logData['page_url']
                    ),
                    $logData['context']
                );

                // Try to find the existing entry as a fallback
                $existingEntry = $this->findExistingEntry(
                    $collectionHandle,
                    $site,
                    $entryId,
                    $entrySlug
                );

                // If we found the existing entry, add it to pageData
                if ($existingEntry instanceof Entry) {
                    // Resolve existing entry ID to a guaranteed string value
                    $existingEntryId = $this->normalizeEntryId(
                        $existingEntry->id(),
                        $entryId
                    );
                    $this->addPageData(
                        $postId,
                        $existingEntryId,
                        $parentId,
                        $menuOrder,
                        $title,
                        $link,
                        $existingEntry
                    );
                } else { // Otherwise
                    // ---
                    // If we still can't find it, add it to pageData with the generated entry ID
                    // anyway so the tree can be built. The entry may exist but not be findable due to
                    // cache issues.
                    // ---

                    // Enhance log context with entry information
                    $logData = LogContextHelper::enhanceWithEntryContext(
                        [
                            'postId' => $postId,
                            'entryId' => $entryId,
                            'slug' => $entrySlug,
                        ],
                        $link
                    );

                    // Log warning that existing entry could not be found
                    Log::warning(
                        LogContextHelper::formatMessageWithEntry(
                            'PageEntryCreator::createEntries: Could not find existing entry, adding to pageData with generated ID',
                            $logData['page_url']
                        ),
                        $logData['context']
                    );

                    // Add page to pageData with generated ID as fallback to ensure tree can be built
                    $this->addPageData(
                        $postId,
                        $entryId,
                        $parentId,
                        $menuOrder,
                        $title,
                        $link
                    );
                }
            }
        }

        // Return the collected page data for hierarchy processing
        return $this->pageData;
    }

    /**
     * Indicates if the content contains show-people shortcodes.
     *
     * Returns true if any page content includes a [show-people] shortcode.
     *
     * @return bool True if show-people shortcodes are present, false otherwise
     */
    public function hasShowPeople(): bool
    {
        return $this->hasShowPeople;
    }

    /**
     * Retrieves post categories for taxonomy validation.
     *
     * Returns an empty array, as pages do not use post_category terms.
     *
     * @return array<int|string, mixed> Empty array, as no categories are applicable
     */
    public function getPostCategories(): array
    {
        return [];
    }

    /**
     * Returns the post tags data collected during entry creation.
     *
     * Page entries don't use post tags, so this always returns an empty array.
     * This method is required by the abstract parent class.
     *
     * @return array<int|string, mixed> Always returns an empty array for page entries
     */
    public function getPostTags(): array
    {
        return [];
    }

    /**
     * Retrieves people_group terms referenced in show-people shortcodes.
     *
     * Returns the people_group terms that were extracted from [show-people]
     * shortcodes during content processing by PeopleShortcodeProcessor.
     *
     * @return array<int|string, mixed> Associative array of people_group terms and their associated page slugs
     */
    public function getPeopleGroups(): array
    {
        // Use the centralized term tracking from PeopleShortcodeProcessor
        return PeopleShortcodeProcessor::getTermsUsed();
    }

    /**
     * Finds the first block on a page that is not mapped to Statamic Bard.
     *
     * Classic, Row and Freeform block content is checked in that order, and the
     * first found is returned, so a later check is not made once one matches.
     *
     * @param  string  $postId  The WordPress post ID
     * @return array{kind: string, info: array<int|string, mixed>}|null The block's kind and details, or null when there is none
     */
    private function findUnmappedBlock(
        string $postId
    ): ?array {
        // Check each block kind in turn, stopping at the first found
        foreach (['Classic', 'Row', 'Freeform'] as $kind) {
            $info = match ($kind) {
                'Classic' => $this->entryCreator->hasClassicBlock($postId),
                'Row' => $this->entryCreator->hasRowBlock($postId),
                default => $this->entryCreator->hasFreeformBlock($postId),
            };

            if ($info !== null && $info !== []) {
                return ['kind' => $kind, 'info' => $info];
            }
        }

        return null;
    }

    /**
     * Builds the Bard note a page gets in place of block content it cannot map.
     *
     * @param  string  $kind  The block's kind, as WordPress names it (Classic, Row or Freeform)
     * @param  array<int|string, mixed>  $info  The block's title, ID and link
     * @return list<array<string, mixed>> The note, as Bard nodes
     */
    private function unmappedBlockNote(
        string $kind,
        array $info
    ): array {
        // Normalize block details for safe string interpolation
        $blockTitle = $this->normalizeMixedString(
            $info['title'] ?? null
        );
        $blockId = $this->normalizeMixedString(
            $info['id'] ?? null
        );

        return [
            [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'marks' => [
                            ['type' => 'bold'],
                        ],
                        'text' => 'Note:',
                    ],
                    [
                        'type' => 'text',
                        'text' => " This page contains WordPress {$kind} block content which hasn't been mapped to Statamic Bard.",
                    ],
                ],
            ],
            [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'Original details:'],
                ],
            ],
            [
                'type' => 'bulletList',
                'content' => [
                    [
                        'type' => 'listItem',
                        'content' => [
                            [
                                'type' => 'paragraph',
                                'content' => [
                                    ['type' => 'text', 'text' => "Title: \"{$blockTitle}\""],
                                ],
                            ],
                        ],
                    ],
                    [
                        'type' => 'listItem',
                        'content' => [
                            [
                                'type' => 'paragraph',
                                'content' => [
                                    ['type' => 'text', 'text' => "ID: {$blockId}"],
                                ],
                            ],
                        ],
                    ],
                    [
                        'type' => 'listItem',
                        'content' => [
                            [
                                'type' => 'paragraph',
                                'content' => [
                                    ['type' => 'text', 'text' => 'Permalink: '],
                                    [
                                        'type' => 'text',
                                        'marks' => [
                                            ['type' => 'link', 'attrs' => [
                                                'href' => $info['link'],
                                                'rel' => null,
                                                'target' => '_blank',
                                                'title' => null,
                                            ]],
                                        ],
                                        'text' => $info['link'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Process ACF blocks from content and populate page_builder field.
     *
     * This method extracts ACF blocks from content even when Classic, Row, Details
     * and Freeform block content is detected, ensuring landing pages with mixed
     * content types have their page_builder field populated. If no ACF blocks are
     * present, this method returns early without processing.
     *
     * @param  bool  $hasAcfBlocks  Whether ACF blocks are present in the content
     * @param  ContentProcessor  $contentProcessor  The content processor instance
     * @param  string  $contentEncoded  The encoded content to process
     * @param  string  $site  The site handle
     * @param  AssetImporter  $assetImporter  The asset importer instance
     * @param  OutputInterface  $output  The output interface
     * @param  XmlParser  $xmlParser  The XML parser instance
     * @param  string  $title  The page title
     * @param  string  $postId  The WordPress post ID
     * @param  string  $link  The WordPress page link
     * @param  array<int|string, mixed>  $attachments  The attachments array
     * @param  array<int|string, mixed>  $terms  The terms array
     * @param  array<int|string, mixed>  $data  The entry data array (passed by reference)
     * @param  string  $collectionHandle  The collection handle
     * @param  string  $entrySlug  The entry slug
     * @param  string  $entryId  The entry ID
     */
    private function processAcfBlocksForPageBuilder(
        bool $hasAcfBlocks,
        ContentProcessor $contentProcessor,
        string $contentEncoded,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        XmlParser $xmlParser,
        string $title,
        string $postId,
        string $link,
        array $attachments,
        array $terms,
        array &$data,
        string $collectionHandle,
        string $entrySlug,
        string $entryId
    ): void {
        // Check if no ACF blocks are present
        if (! $hasAcfBlocks) {
            // Return early without processing
            return;
        }

        // Process the content
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

        // ---
        // Add the page_builder field to the entry data. This will ensure that the
        // page_builder field is populated even if no ACF blocks are present.
        // ---
        $data['page_builder'] = $parsedContent['page_builder'];
    }

    /**
     * Validates page collection entries for proper blueprint and field combinations.
     *
     * Checks for common issues with page entries:
     *   - landing_page blueprint without page_builder field
     *   - basic_page blueprint without content field
     *   - landing_page blueprint with content field (should use page_builder)
     *   - basic_page blueprint with page_builder field (should use content)
     *
     * @param  string  $entrySlug  The entry slug for logging
     * @param  array<int|string, mixed>  $data  The entry data to validate
     */
    private function validatePageEntryFields(
        string $entrySlug,
        array $data
    ): void {
        // Extract blueprint type from entry data
        $blueprint = $data['blueprint'] ?? 'basic_page';

        // Check if content field exists and is not empty
        $hasContent = isset($data['content']) && $data['content'] !== [];

        // Check if page_builder field exists and is not empty
        $hasPageBuilder = isset($data['page_builder']) && $data['page_builder'] !== [];

        // Get the original source URL from processing context
        $sourceUrl = ProcessingContext::getWordPressPageLink();

        // Validate landing_page blueprint field requirements
        if ($blueprint === 'landing_page') {
            // Check if landing_page blueprint is missing required page_builder field
            if (! $hasPageBuilder) {
                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'entry_slug' => $entrySlug,
                        'blueprint' => $blueprint,
                        'has_content' => $hasContent,
                        'has_page_builder' => $hasPageBuilder,
                        'source_url' => $sourceUrl,
                    ],
                    $sourceUrl
                );

                // Log warning for missing page_builder field on landing_page
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        'Page entry created with landing_page blueprint but no page_builder field',
                        $logData['page_url']
                    ),
                    $logData['context']
                );
            }

            // Check if landing_page blueprint has unexpected content field
            if ($hasContent) {
                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'entry_slug' => $entrySlug,
                        'blueprint' => $blueprint,
                        'has_content' => $hasContent,
                        'has_page_builder' => $hasPageBuilder,
                        'source_url' => $sourceUrl,
                    ],
                    $sourceUrl
                );

                // Log warning for content field on landing_page blueprint
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        'Page entry created with landing_page blueprint but has content field',
                        $logData['page_url']
                    ),
                    $logData['context']
                );
            }
        }

        // Validate basic_page blueprint field requirements
        if ($blueprint === 'basic_page') {
            // Check if basic_page blueprint is missing required content field
            if (! $hasContent) {
                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'entry_slug' => $entrySlug,
                        'blueprint' => $blueprint,
                        'has_content' => $hasContent,
                        'has_page_builder' => $hasPageBuilder,
                        'source_url' => $sourceUrl,
                    ],
                    $sourceUrl
                );

                // Log warning for missing content field on basic_page
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        'Page entry created with basic_page blueprint but no content field',
                        $logData['page_url']
                    ),
                    $logData['context']
                );
            }

            // Check if basic_page blueprint has unexpected page_builder field
            if ($hasPageBuilder) {
                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'entry_slug' => $entrySlug,
                        'blueprint' => $blueprint,
                        'has_content' => $hasContent,
                        'has_page_builder' => $hasPageBuilder,
                        'source_url' => $sourceUrl,
                    ],
                    $sourceUrl
                );

                // Log warning for page_builder field on basic_page blueprint
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        'Page entry created with basic_page blueprint but has page_builder field',
                        $logData['page_url']
                    ),
                    $logData['context']
                );
            }
        }
    }

    /**
     * Validates people_group taxonomy terms used in pages with show-people shortcodes.
     *
     * Validates that all people_group terms referenced in show-people shortcodes
     * exist in the Statamic people_group taxonomy. Automatically creates missing
     * terms to resolve any validation issues.
     *
     * @param  string  $collectionHandle  The collection handle (e.g., 'pages')
     * @param  string  $site  The Statamic site handle
     * @param  OutputInterface  $output  Console output for logging
     */
    public function validateTaxonomies(
        string $collectionHandle,
        string $site,
        OutputInterface $output
    ): void {
        // Only validate people groups if this page contains show-people shortcodes
        if ($this->hasShowPeople()) {
            // Create a validator for people group taxonomy terms
            $validator = new PeopleGroupValidator;

            // ---
            // Set context to 'shortcodes' to display accurate validation messages
            // for people shortcodes (vs. people entries)
            // ---
            // The validator names shortcodes when no context is set as well
            // @pest-mutate-ignore: RemoveMethodCall
            $validator->setContext('shortcodes');

            // Validate that all people groups referenced in shortcodes exist
            $validator->validateTerms(
                $this->getPeopleGroups(),
                $collectionHandle,
                $site,
                $output
            );
        }
    }

    /**
     * Checks if the content string contains page builder blocks.
     *
     * Scans the raw HTML content string for wp:acf/*, wp:minervakb/*, wp:rss block
     * comments, LiveWhale widget HTML (div.lwcw with data-options id=), or
     * blogroll shortcode to determine if the content should be processed as a
     * landing_page blueprint.
     * This is more reliable than checking the DOM because comments inside
     * elements (e.g., inside wp:group divs) may not be preserved correctly when
     * loaded into a DOMDocument.
     *
     * @param  string  $content  The raw HTML content string to check
     * @return bool True if page builder blocks (wp:acf/*, wp:minervakb/*, wp:rss, div.lwcw
     *              LiveWhale widget, or blogroll shortcode) are present, false otherwise
     */
    private function hasPageBuilderBlocks(
        string $content
    ): bool {
        // Check if content contains ACF blocks
        if (
            \preg_match(
                '/<!--\s*wp:acf\//',
                $content
            ) === 1
        ) {
            // Return true if ACF blocks found
            return true;
        }

        // Check if content contains MinervaKB blocks
        if (
            \preg_match(
                '/<!--\s*wp:minervakb\//',
                $content
            ) === 1
        ) {
            // Return true if MinervaKB blocks found
            return true;
        }

        // Check if content contains wp:rss blocks
        if (
            \preg_match(
                '/<!--\s*wp:rss/',
                $content
            ) === 1
        ) {
            // Return true if wp:rss blocks found
            return true;
        }

        // Check if content contains LiveWhale widget HTML (div.lwcw with data-options id=)
        if (
            \preg_match(
                '/\bclass=["\']lwcw["\']/',
                $content
            ) === 1
            && \preg_match(
                '/data-options=["\'][^"\']*\bid=\d+/',
                $content
            ) === 1
        ) {
            // Return true if LiveWhale widget markup found
            return true;
        }

        // Check if content contains blogroll shortcode (imported as uamsweb_block_news)
        // Return false if no page builder blocks found
        // Return true if blogroll shortcode found
        return \preg_match(
            '/\[blogroll\s/',
            $content
        ) === 1;
    }

    /**
     * Checks if content contains page builder blocks, including in referenced
     * wp_block posts.
     *
     * First checks for direct page builder blocks (ACF, MinervaKB, or wp:rss) in the
     * content. If none are found, extracts wp:block references and checks the content
     * of each referenced wp_block post for page builder blocks. This ensures that
     * pages using wp:block blocks that reference wp_block posts containing page
     * builder blocks are correctly identified as landing pages.
     *
     * @param  string  $contentEncoded  The encoded content to check
     * @param  XmlParser  $xmlParser  The XML parser instance for accessing wp_block posts
     * @return bool True if page builder blocks are found (directly or in referenced wp_block posts)
     */
    private function checkForPageBuilderBlocks(
        string $contentEncoded,
        XmlParser $xmlParser
    ): bool {
        // Check if content contains direct page builder blocks
        if ($this->hasPageBuilderBlocks($contentEncoded)) {
            // Return true if direct page builder blocks found
            return true;
        }

        // Extract wp:block post IDs from content using shared pattern logic
        $wpBlockPostIds = WpBlockBlockProcessor::extractWpBlockPostIdsFromContent(
            $contentEncoded
        );

        // Get XML file path for finding wp_block items
        $xmlPath = $xmlParser->getFilePath();

        // ---
        // Return early when XML path is unavailable. A run streams its pages from the
        // export's files, so the parser always has one; the check narrows the type.
        // ---
        // @pest-mutate-ignore: BooleanOrToBooleanAnd, EmptyStringToNotEmpty, FalseToTrue, RemoveEarlyReturn
        if (! \is_string($xmlPath) || $xmlPath === '') {
            return false;
        }

        // Check each referenced wp_block post for page builder blocks
        foreach ($wpBlockPostIds as $wpBlockPostId) {
            // Normalize wp:block ID to string before XML lookup
            $normalizedWpBlockPostId = $this->normalizeMixedString($wpBlockPostId);

            // Find wp_block item by post ID
            $wpBlockItem = XpathHelper::findItemByPostId(
                $xmlPath,
                $normalizedWpBlockPostId,
                'wp_block'
            );

            // Check if wp_block item was found
            if (! $wpBlockItem instanceof \SimpleXMLElement) {
                // Skip to next wp_block if not found
                continue;
            }

            // Extract content from wp_block item
            $wpBlockContent = XpathHelper::getStringValue(
                $wpBlockItem,
                'content:encoded'
            );

            // Check if wp_block content contains page builder blocks
            if ($this->hasPageBuilderBlocks($wpBlockContent)) {
                // Return true if page builder blocks found in referenced wp_block
                return true;
            }
        }

        // Return false if no page builder blocks found in any referenced wp_block
        return false;
    }

    /**
     * Extracts hero block data from wp:acf/hero block in content.
     *
     * Searches for wp:acf/hero blocks in the content and extracts the hero data
     * attributes. Returns the hero data array if found, or null if not found.
     *
     * @param  string  $contentEncoded  The encoded content to search
     * @return array<int|string, mixed>|null The hero data array or null if not found
     */
    private function extractHeroBlockData(
        string $contentEncoded
    ): ?array {
        // Check if content contains wp:acf/hero block. Find the opening comment tag.
        if (
            \preg_match(
                '/<!--\s*wp:acf\/hero\s+({)/s',
                $contentEncoded,
                $matches,
                PREG_OFFSET_CAPTURE
            ) !== 1
        ) {
            // Return null if no hero block found
            return null;
        }

        // ---
        // Find the position where JSON starts
        // ---

        // Get the offset position where the JSON object starts
        $jsonStart = $matches[1][1];

        // Extract the JSON string starting from the opening brace
        $jsonString = \substr(
            $contentEncoded,
            $jsonStart
        );

        // ---
        // Extract complete JSON using shared utility method that handles string escaping
        // and nested braces
        // ---

        // Extract balanced JSON using shared method from TextProcessor
        $completeJsonString = TextProcessor::extractCompleteJson($jsonString);

        // Decode JSON attributes
        $attributes = \json_decode(
            $completeJsonString,
            true
        );

        // Extract hero data from attributes, none when the JSON did not decode
        $heroData = \is_array($attributes) ? ($attributes['data'] ?? null) : null;

        // ---
        // Return null if no hero data found
        // ---

        // Check if hero data array is empty
        if (! \is_array($heroData) || $heroData === []) {
            // Return null if no hero data found
            return null;
        }

        // Return hero data
        return $heroData;
    }

    /**
     * Extracts hero module data from modules_hero module in postmeta.
     *
     * Searches for modules_hero module in the uamswp_modules array and extracts the
     * hero module data from postmeta. Returns the hero data array if found, or null
     * if not found.
     *
     * @param  string  $serializedModules  The serialized uamswp_modules value
     * @param  array<int|string, mixed>  $postmeta  The postmeta array
     * @return array<int|string, mixed>|null The hero data array or null if not found
     */
    private function extractHeroModuleData(
        string $serializedModules,
        array $postmeta
    ): ?array {
        // Unserialize the uamswp_modules array from postmeta
        $modulesData = @\unserialize($serializedModules, ['allowed_classes' => false]);

        // Check if unserialize was successful
        if (! \is_array($modulesData)) {
            // Return null if unserialize failed
            return null;
        }

        $heroModuleIndex = array_find_key($modulesData, fn ($moduleType): bool => $moduleType === 'modules_hero');

        // ---
        // Return null if modules_hero not found
        // ---

        // Check if hero module index was not found
        if ($heroModuleIndex === null) {
            // Return null if modules_hero not found
            return null;
        }

        // ---
        // Extract module data for modules_hero
        // ---

        // ---
        // Extract module data using shared method from ModulesParser
        // ---
        $moduleData = ModulesParser::extractModuleDataFromPostmeta(
            $postmeta,
            (int) $heroModuleIndex
        );

        // ---
        // Return null if no module data found
        // ---

        // Check if module data array is empty
        if ($moduleData === []) {
            // Return null if no module data found
            return null;
        }

        // Return module data
        return $moduleData;
    }

    /**
     * Maps hero data to header_hero field.
     *
     * Converts hero block or module data to the header_hero field format used by
     * Statamic. Handles field name normalization and processes images, links, and
     * other hero attributes. Supports both hero block format (hero_0_hero_heading)
     * and module format (hero_heading). Uses FieldMapper::processHeroFields() to
     * avoid code duplication.
     *
     * @param  array<int|string, mixed>  $heroData  The hero data from block or module
     * @param  array<int|string, mixed>  &$data  The entry data array (passed by reference)
     * @param  string  $site  The site handle
     * @param  AssetImporter  $assetImporter  The asset importer instance
     * @param  array<int|string, mixed>  $attachments  Array of attachments
     * @param  OutputInterface  $output  The console output
     */
    private function mapHeroDataToHeaderHero(
        array $heroData,
        array &$data,
        string $site,
        AssetImporter $assetImporter,
        array $attachments,
        OutputInterface $output
    ): void {
        // ---
        // Find hero slide index (usually 0 for first hero)
        // ---

        // Initialize hero index to default value
        $heroIndex = 0;

        // Loop through hero data to find the slide index
        foreach ($heroData as $key => $value) {
            // Check if key matches hero block format with index
            if (
                \preg_match(
                    '/^hero_(\d+)_hero_(.+)$/',
                    (string) $key,
                    $matches
                ) === 1
            ) {
                // Extract and convert hero index from matched pattern
                $heroIndex = (int) $matches[1];

                // Exit loop once index is found
                break;
            }
        }

        // ---
        // Normalize hero data field names
        // ---

        // Initialize array to store normalized hero fields
        $normalizedHeroFields = [];

        // Loop through hero data to normalize field names
        foreach ($heroData as $key => $value) {
            // Initialize variable to store extracted field name
            $fieldName = null;

            // Check if key matches hero block format with index
            if (
                \preg_match(
                    '/^hero_\d+_hero_(.+)$/',
                    (string) $key,
                    $matches
                ) === 1
            ) {
                // Hero block format: hero_0_hero_heading
                $fieldName = $matches[1];
            } elseif (
                \preg_match(
                    '/^hero_(.+)$/',
                    (string) $key,
                    $matches
                ) === 1
            ) { // Check if key matches hero module format without index
                // Module format: hero_heading
                $fieldName = $matches[1];
            }

            // Check if field name was extracted
            if ($fieldName !== null) {
                // Store normalized field name and value
                $normalizedHeroFields[$fieldName] = $value;
            }
        }

        // ---
        // Process hero fields using shared method from FieldMapper
        // ---

        // Create FieldMapper instance to use shared hero processing method
        $fieldMapper = new FieldMapper;

        // Process hero fields using shared method
        $fieldMapper->processHeroFields(
            $normalizedHeroFields,
            $heroIndex,
            $data,
            $site,
            $assetImporter,
            $attachments,
            $output
        );
    }

    /**
     * Removes wp:acf/hero block from content.
     *
     * Removes the wp:acf/hero block comment and any associated closing tags from the
     * content string. Returns the modified content.
     *
     * @param  string  $contentEncoded  The encoded content
     * @return string The content with hero block removed
     */
    private function removeHeroBlockFromContent(
        string $contentEncoded
    ): string {
        // ---
        // Remove wp:acf/hero block (self-closing or paired). Match self-closing or
        // opening tag with JSON attributes (may span multiple lines). Use a more
        // permissive pattern that handles nested braces.
        // ---
        $contentWithoutHeroOpen = \preg_replace(
            '/<!--\s*wp:acf\/hero\s+({.*?})\s*(?:\/)?-->/s',
            '',
            $contentEncoded
        );
        if (\is_string($contentWithoutHeroOpen)) {
            $contentEncoded = $contentWithoutHeroOpen;
        }

        // Remove closing tag if it exists
        $contentWithoutHeroClose = \preg_replace(
            '/<!--\s*\/wp:acf\/hero\s*-->/s',
            '',
            $contentEncoded
        );
        if (\is_string($contentWithoutHeroClose)) {
            return $contentWithoutHeroClose;
        }

        // Return modified content
        return $contentEncoded;
    }

    /**
     * Override title_options to graphic for specific basic_page entries.
     *
     * Sets title_options to 'graphic' for basic_page entries that meet specific
     * criteria:
     *   - The homepage (if blueprint is basic_page and title_options would be normal)
     *   - Top-level pages (if blueprint is basic_page and title_options would be
     *     normal)
     *   - Entries with subsection: true (if blueprint is basic_page and title_options
     *     would be normal)
     *   - Immediate children of entries with subsection: true (if blueprint is
     *     basic_page and title_options would be normal)
     *
     * @param  array<int|string, mixed>  &$data  The entry data array (passed by reference)
     * @param  string  $parentId  The parent post ID
     * @param  string  $link  The page link URL
     * @param  XmlParser  $xmlParser  The XML parser instance
     * @param  AssetImporter  $assetImporter  The asset importer instance
     */
    private function overrideTitleOptionsForBasicPages(
        array &$data,
        string $parentId,
        string $link,
        XmlParser $xmlParser,
        AssetImporter $assetImporter
    ): void {
        // ---
        // Only apply to basic_page blueprint entries
        // ---

        // Check if blueprint is not basic_page
        if (($data['blueprint'] ?? null) !== 'basic_page') {
            // Return early if not basic_page blueprint
            return;
        }

        // ---
        // Only override if title_options would otherwise be normal
        // ---

        // Check if title_options is not normal
        if (($data['title_options'] ?? null) !== 'normal') {
            // Return early if title_options is not normal
            return;
        }

        // Get base blog URL for homepage detection
        $baseBlogUrl = $assetImporter->getBaseBlogUrl();

        // ---
        // Check if this is the homepage (identified by link matching base blog URL)
        // ---

        // Initialize homepage flag
        $isHomepage = false;

        // Check if base blog URL is available
        if ($baseBlogUrl !== null) {
            // Normalize link by removing trailing slash
            $normalizedLink = \rtrim(
                $link,
                '/'
            );

            // Normalize base URL by removing trailing slash
            $normalizedBaseUrl = \rtrim(
                $baseBlogUrl,
                '/'
            );

            // Check if normalized link matches base URL
            if ($normalizedLink === $normalizedBaseUrl) {
                // Set homepage flag to true
                $isHomepage = true;
            }
        }

        // ---
        // Check if this is a top-level page
        // ---

        // Initialize top-level page flag
        $isTopLevelPage = false;

        // ---
        // Check if page has no parent (post_parent is 0). Pages with no parent are
        // top-level pages.
        // ---
        if ($parentId === '0') {
            // Set top-level page flag to true
            $isTopLevelPage = true;
        }

        // Check if this entry has subsection: true
        $hasSubsection = ($data['subsection'] ?? null) === true;

        // ---
        // Check if this is an immediate child of an entry with subsection: true
        // ---

        // Initialize subsection child flag
        $isSubsectionChild = false;

        // Check if page has a parent
        if ($parentId !== '0') {
            // Resolve XML path for parent page lookup
            $xmlPath = $xmlParser->getFilePath();

            // ---
            // Skip parent lookup when XML path is unavailable. A run streams its pages
            // from the export's files, so the parser always has one; the check narrows
            // the type.
            // ---
            // @pest-mutate-ignore: BooleanOrToBooleanAnd, EmptyStringToNotEmpty, RemoveEarlyReturn
            if (! \is_string($xmlPath) || $xmlPath === '') {
                return;
            }

            // Find the parent page in XML
            $parentPage = XpathHelper::findItemByPostId(
                $xmlPath,
                $parentId,
                'page'
            );

            // Check if parent page was found
            if ($parentPage instanceof \SimpleXMLElement) {
                // ---
                // Extract postmeta from parent page
                // ---

                // Initialize parent postmeta array
                $parentPostmeta = [];

                // Loop through parent page postmeta elements
                foreach (XpathHelper::xpathElements($parentPage, 'wp:postmeta') as $meta) {
                    // Extract meta key from postmeta element
                    $key = XpathHelper::getStringValue(
                        $meta,
                        'wp:meta_key'
                    );

                    // Extract meta value from postmeta element
                    $value = XpathHelper::getStringValue(
                        $meta,
                        'wp:meta_value'
                    );

                    // Store meta key-value pair in parent postmeta array
                    $parentPostmeta[$key] = $value;
                }

                // Check if parent has page_subsection set to '1'
                if (($parentPostmeta['page_subsection'] ?? null) === '1') {
                    // Set subsection child flag to true
                    $isSubsectionChild = true;
                }
            }
        }

        // ---
        // Set title_options to graphic if any condition is met
        // ---

        // Check if any condition for graphic title is met
        if (
            $isHomepage
            || $isTopLevelPage
            || $hasSubsection
            || $isSubsectionChild
        ) {
            // Set title_options to graphic
            $data['title_options'] = 'graphic';
        }
    }

    /**
     * Find the knowledge base entry ID for the given site.
     *
     * @param  string  $site  Site handle to filter knowledge base entries
     * @return string|null The knowledge base entry ID when found, otherwise null
     */
    private function findKnowledgeBaseEntryId(
        string $site
    ): ?string {
        // Build knowledge base entry query with explicit filters
        $query = EntryQueryHelper::query();
        $query->where(
            'collection',
            'knowledge_bases'
        );
        $query->where(
            'blueprint',
            'knowledge_base'
        );
        $query->where(
            'slug',
            'kb'
        );
        $query->where(
            'site',
            $site
        );

        // Return the first matching entry's ID, or null when there is none
        $entry = $query->first();
        $entryId = $entry instanceof Entry ? $entry->id() : null;

        return \is_string($entryId) ? $entryId : null;
    }

    /**
     * Normalize mixed values to non-empty strings.
     *
     * @param  mixed  $value  Value to normalize
     * @return string Normalized string value, or empty string when invalid
     */
    private function normalizeMixedString(
        mixed $value
    ): string {
        // Return empty string when value is not scalar
        if (! \is_scalar($value)) {
            return '';
        }

        // Cast scalar value to string
        return (string) $value;
    }

    /**
     * Normalize an entry ID and fall back to generated ID.
     *
     * @param  mixed  $entryId  Entry ID value from Statamic entry object
     * @param  string  $fallbackId  Generated fallback ID
     * @return string Guaranteed non-empty entry ID string
     */
    private function normalizeEntryId(
        mixed $entryId,
        string $fallbackId
    ): string {
        // Normalize entry ID to a string
        $normalizedEntryId = $this->normalizeMixedString($entryId);

        // ---
        // Return normalized ID when available, otherwise fallback ID. A saved entry's
        // id is never blank, so the fallback is for the type the entry declares.
        // ---
        // @pest-mutate-ignore: EmptyStringToNotEmpty
        return $normalizedEntryId !== '' ? $normalizedEntryId : $fallbackId;
    }
}
