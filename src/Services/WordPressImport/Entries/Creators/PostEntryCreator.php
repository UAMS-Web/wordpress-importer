<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators;

use Illuminate\Support\Facades\Log;
use SimpleXMLElement;
use Statamic\Entries\Entry;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\AudioSeries\AudioSeriesConfig;
use UAMSWeb\WordPressImporter\Services\WordPressImport\AudioSeries\AudioSeriesManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\ContentStreamFactory;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\StructuredTermImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\TaxonomyDispositions;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\TermDestinationRegistry;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ExcerptResolver;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\FeaturedImageCreditResolver;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\FileProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\HtmlTagStripper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportProgress;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\PostAudioProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;

/**
 * Creates Statamic entries for WordPress 'post' content type.
 *
 * This class handles the complete import process for WordPress posts, including:
 *   - Content processing and conversion from WordPress format to Statamic Bard
 *   - Thumbnail and featured image import and mapping
 *   - Post category resolution to `post_categories` collection-entry references
 *   - SEO metadata extraction and mapping (titles, descriptions)
 *   - Entry status and visibility mapping
 *   - Slug generation and duplicate handling
 *   - Content validation and empty content detection
 *   - News release PDF import and blueprint switching (post -> news_release)
 *   - Audio post format detection and blueprint switching (post -> audio)
 *
 * The class uses streaming XML parsing for memory efficiency when processing
 * large WordPress exports and maintains tag-to-entry relationships for proper
 * taxonomy term creation and validation. When a post contains a
 * news_release_pdf_url meta key with a non-empty value, the entry blueprint
 * is automatically switched to 'news_release'.
 *
 * The WordPress PDF itself is NOT downloaded. The host app regenerates it from
 * its own template on save and owns the `news_release_pdf` field, so anything
 * imported here would be overwritten and then deleted by the host's own
 * container sweep (wordpress-importer-archive#620).
 *
 * When a post contains a category with domain="post_format" and
 * nicename="post-format-audio", the entry blueprint is automatically switched to
 * 'audio' and content is processed using PostAudioProcessor to extract audio
 * segments, transcripts, and accordion content.
 */
class PostEntryCreator extends BaseEntryCreator
{
    /**
     * Reorders a post's category nicenames so its Yoast primary category leads.
     *
     * The Statamic newsroom treats the first category as the primary one, shown on
     * cards and breadcrumbs. WordPress records the editor's choice in
     * `_yoast_wpseo_primary_category` as a TERM ID, while categories are extracted as
     * nicenames, so the id is resolved through the nicename-to-term-id map the creator
     * already builds.
     *
     * Document order is preserved whenever the choice cannot be honoured — no primary
     * recorded (common: the news export carries the key with an empty value on several
     * posts), an id absent from the map, or a primary naming a category this post does
     * not carry, which happens because audio-series categories are filtered out before
     * this point. Only the primary moves; the rest keep their relative order (wordpress-importer-archive#441).
     *
     * PHP keys an integer-string nicename such as `2024` as an int, so the key found
     * is read back as a string before it is compared with the post's nicenames.
     *
     * @param  list<string>  $categories  Category nicenames in WXR document order
     * @param  string  $primaryTermId  Raw `_yoast_wpseo_primary_category` value
     * @param  array<int|string, mixed>  $nicenameToTermId  Map of nicename => term id
     * @return list<string> Category nicenames, primary first when resolvable
     */
    public function orderCategoriesPrimaryFirst(
        array $categories,
        string $primaryTermId,
        array $nicenameToTermId
    ): array {
        // Normalize the recorded id before deciding whether a primary was chosen
        $primaryTermId = \trim($primaryTermId);

        // Treat a blank or zero id as no primary, keeping document order
        if (
            $primaryTermId === ''
            || $primaryTermId === '0'
        ) {
            return $categories;
        }

        $primaryKey = array_find_key(
            $nicenameToTermId,
            static fn (mixed $termId): bool => \is_scalar($termId) && (string) $termId === $primaryTermId
        );

        // ---
        // Keep document order when the primary cannot be placed: an id the export no
        // longer defines, or a category this post does not carry. Injecting it would
        // add a category the post lost.
        // ---
        if ($primaryKey === null) {
            return $categories;
        }

        $primaryNicename = (string) $primaryKey;

        if (! \in_array($primaryNicename, $categories, true)) {
            return $categories;
        }

        // Drop the first occurrence of the primary, leaving the rest in order
        $remaining = $categories;
        $position = \array_search($primaryNicename, $remaining, true);

        // ---
        // Remove only that entry so a duplicate nicename is not swallowed. The strict
        // in_array() above means the search always finds it, so `false` never arrives;
        // the guard is for the type, which unset() would otherwise index with a bool.
        // ---
        // @pest-mutate-ignore: FalseToTrue
        if ($position !== false) {
            unset($remaining[$position]);
        }

        // Spreading re-keys the integer keys, so the result is a list rather than a map
        return [
            $primaryNicename,
            ...$remaining,
        ];
    }

    /**
     * Resolves a post's WordPress categories to `post_categories` entry references.
     *
     * `UAMS-Web/uams-statamic-archive#1433` migrated `post_categories` from a flat
     * taxonomy to a structured collection and repointed the `posts` field from a
     * `terms` reference to an `entries` one, so the field now stores collection
     * **entry ids**. {@see StructuredTermImporter} creates those entries during
     * the term pass and records each one in {@see TermDestinationRegistry}; this
     * turns the post's own category nicenames into that list (wordpress-importer-archive#422).
     *
     * An unresolved category is OMITTED rather than falling back to its slug.
     * `category` is a {@see TaxonomyDispositions::SHAPE_COLLECTION} destination
     * unconditionally, so there is no flat taxonomy for a slug to resolve
     * against — writing one back would restore the dangling value this replaces.
     *
     * Order is the caller's, which is Yoast-primary-first by the time this runs
     * ({@see self::orderCategoriesPrimaryFirst()}); the newsroom treats the first
     * entry as the primary category. Duplicates are collapsed, since two
     * nicenames in a malformed export can name one term.
     *
     * @param  list<string>  $categoryNicenames  Category nicenames in the order they should be stored
     * @param  array<int|string, mixed>  $nicenameToTermIdMap  Nicename to WordPress term id
     * @return list<string> Statamic `post_categories` entry ids, in order, without duplicates
     */
    public function resolveCategoryEntryReferences(
        array $categoryNicenames,
        array $nicenameToTermIdMap
    ): array {
        return $this->resolveTermEntryReferences(
            'category',
            $categoryNicenames,
            $nicenameToTermIdMap
        );
    }

    /**
     * Resolves a post's term nicenames to the collection entry ids they import as.
     *
     * The general form of {@see self::resolveCategoryEntryReferences()}, used for
     * tags as well since `UAMS-Web/uams-statamic-archive#2532` made `post_tags` a
     * site-scoped collection (wordpress-importer-archive#1220). The same rules apply: an unresolved
     * nicename is omitted rather than written back as a slug, order is the
     * caller's, and duplicates collapse.
     *
     * @param  string  $wordpressTaxonomy  The WordPress taxonomy the nicenames belong to
     * @param  list<string>  $nicenames  Term nicenames in the order they should be stored
     * @param  array<int|string, mixed>  $nicenameToTermIdMap  Nicename to WordPress term id
     * @return list<string> Statamic entry ids, in order, without duplicates
     */
    public function resolveTermEntryReferences(
        string $wordpressTaxonomy,
        array $nicenames,
        array $nicenameToTermIdMap
    ): array {
        $entryReferences = [];

        foreach ($nicenames as $nicename) {
            // A nicename absent from the map has no term id to resolve through.
            $termId = $nicenameToTermIdMap[$nicename] ?? null;

            if (! \is_scalar($termId) || (string) $termId === '') {
                continue;
            }

            // ---
            // The registry builds its key by interpolation, so an int id and its string
            // form find the same destination; the cast only narrows the type.
            // ---
            // @pest-mutate-ignore: RemoveStringCast
            $entryId = TermDestinationRegistry::referenceFor(
                $wordpressTaxonomy,
                (string) $termId
            );

            // Unresolved, or resolved to something unusable: omit it.
            if (! \is_string($entryId) || $entryId === '') {
                continue;
            }

            // Two nicenames can resolve to one entry; store it once.
            if (\in_array($entryId, $entryReferences, true)) {
                continue;
            }

            $entryReferences[] = $entryId;
        }

        return $entryReferences;
    }

    /**
     * Decides whether an imported post should display its featured image's credit.
     *
     * `featured_image_credit_query` surfaces the credit wherever another block
     * renders this entry's featured image. The blueprint defaults it to `false`, a
     * default chosen for hand-authored content — applied to migrated content it
     * would hide the attribution the WordPress site displayed on every card and
     * listing until an editor turned it on entry by entry.
     *
     * Both conditions are required. The featured image has to have actually
     * imported, and the resulting asset has to carry a `creator`; setting the
     * toggle for an uncredited image writes something inert and noisy (wordpress-importer-archive#439).
     *
     * @param  ?string  $featuredImagePath  The imported featured image path, or null when it failed
     * @param  string  $thumbnailId  The WordPress `_thumbnail_id` the image came from
     * @param  array<int|string, mixed>  $attachments  Attachment `<item>` elements from the export
     * @return bool True when the entry should display the credit
     */
    public function shouldShowFeaturedImageCredit(
        ?string $featuredImagePath,
        string $thumbnailId,
        array $attachments
    ): bool {
        // A featured image that did not import has no credit to display
        if (
            $featuredImagePath === null
            || $featuredImagePath === ''
        ) {
            return false;
        }

        // Gate strictly on the imported asset actually carrying a credit
        return FeaturedImageCreditResolver::hasCredit(
            $thumbnailId,
            $attachments
        );
    }

    /**
     * Creates Statamic entries for WordPress post content type.
     *
     * Processes WordPress posts from XML export, handling content conversion,
     * thumbnail import, taxonomy mapping, and entry creation. Uses streaming
     * XML parser for memory efficiency when processing large datasets. Automatically
     * detects news release posts (those with news_release_pdf_url meta key) and
     * switches blueprint to 'news_release'. Also detects audio format posts (those
     * with category domain="post_format" nicename="post-format-audio") and switches
     * blueprint to 'audio' while processing content with PostAudioProcessor.
     *
     * The news release PDF is not downloaded and no asset is tagged for the entry;
     * the host app generates and owns that file (wordpress-importer-archive#620).
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
        // Build taxonomy handles: include post_tag only when tag import is enabled
        $taxonomyHandles = self::getTaxonomyHandlesForPostImport();

        // Initialize terms with standardized processing
        $this->initializeTerms(
            $xmlParser,
            $taxonomyHandles,
            $site,
            $output
        );

        // Open a streaming parser spanning every part. A source that can serve
        // none raises rather than importing nothing and reporting success (wordpress-importer-archive#662).
        $xmlStreamParser = ContentStreamFactory::open(
            $xmlParser,
            'PostEntryCreator::createEntries'
        );

        // Initialize array to store attachment data for thumbnail processing
        $attachments = [];

        // Loop through all attachment items to collect them for processing
        foreach ($xmlStreamParser->streamItems('attachment') as $attachment) {
            // Add each attachment to the collection for later use
            $attachments[] = $attachment;
        }

        // Loop through all post items to process each post entry
        $audioSeriesConfig = null;

        foreach (ImportProgress::track($output, 'post', $xmlStreamParser->streamItems('post'), static fn (): int => $xmlStreamParser->countItems('post')) as $post) {
            // Decode HTML entities and strip HTML tags from the post title
            $title = HtmlTagStripper::strip(
                html_entity_decode(
                    (string) $post->title,
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                )
            );

            // Extract the post slug (URL-friendly name) from WordPress XML
            $slug = XpathHelper::getStringValue(
                $post,
                'wp:post_name'
            );

            // Extract the post status from WordPress XML
            $status = XpathHelper::getStringValue(
                $post,
                'wp:status'
            );

            // Extract the post password from WordPress XML
            $postPassword = XpathHelper::getStringValue(
                $post,
                'wp:post_password'
            );

            // Extract the WordPress post ID from XML
            $postId = XpathHelper::getStringValue(
                $post,
                'wp:post_id'
            );

            // Extract the parent post ID from WordPress XML
            $parentId = XpathHelper::getStringValue(
                $post,
                'wp:post_parent'
            );

            // Extract the menu order from WordPress XML
            $menuOrder = XpathHelper::getIntValue(
                $post,
                'wp:menu_order'
            );

            // Extract the encoded content from WordPress XML
            $contentEncoded = XpathHelper::getStringValue(
                $post,
                'content:encoded'
            );

            // Extract post date with fallback to pubDate
            $postDate = $this->extractPostDateString($post);

            // Get the original WordPress permalink URL
            $originalWordPressUrl = (string) $post->link;

            // Get the post permalink URL
            $link = (string) $post->link;

            // Set the WordPress page link in processing context for accurate logging
            ProcessingContext::setWordPressPageLink($link);

            // Process post categories with site-specific prefixes
            $rawCategories = $this->extractXpathStringValues(
                $post,
                'category[@domain="category"]/@nicename'
            );

            // ---
            // Detect and process audio series categories, get config object for
            // PostAudioProcessor
            // ---
            $audioSeriesConfig = AudioSeriesManager::detectAndProcessAudioSeries(
                $rawCategories,
                $site,
                $output
            );

            // Extract slug for entry data series field
            $audioSeriesSlug = $audioSeriesConfig?->getSeriesFieldValue();

            // Filter out audio series categories from regular post category processing
            $filteredCategories = AudioSeriesManager::filterOutAudioSeriesCategories(
                $rawCategories,
                $audioSeriesConfig
            );

            // ---
            // Lead with the editor's Yoast primary category, since the newsroom treats the
            // first entry as primary on cards and breadcrumbs. Applied before the site
            // prefixes so the term-creation loop below and the stored list agree on order
            // (wordpress-importer-archive#441).
            // ---
            $categoryTermIdMap = $this->nicenameToTermIdMap['category'] ?? [];

            if (! \is_array($categoryTermIdMap)) {
                $categoryTermIdMap = [];
            }

            // ---
            // The nicenames are XPath strings, so the filter keeps every one and only
            // narrows the type; the keys it leaves reach nothing that reads them.
            // ---
            // @pest-mutate-ignore: UnwrapArrayValues, UnwrapArrayFilter
            $filteredCategories = \array_values(\array_filter($filteredCategories, \is_string(...)));

            $filteredCategories = $this->orderCategoriesPrimaryFirst(
                $filteredCategories,
                XpathHelper::getStringValue(
                    $post,
                    "wp:postmeta[wp:meta_key='_yoast_wpseo_primary_category']/wp:meta_value"
                ),
                $categoryTermIdMap
            );

            // ---
            // Resolve each category to the `post_categories` COLLECTION entry it
            // was imported as, rather than to a taxonomy term.
            // `UAMS-Web/uams-statamic-archive#1433` moved that destination from a flat
            // taxonomy to a structured collection and repointed the blueprint
            // field to an `entries` picker, so a term slug written here resolves
            // to nothing (wordpress-importer-archive#422). The term pass runs before any entry is created
            // (see `initializeTerms()`), so every destination is already recorded
            // in `TermDestinationRegistry` by the time this runs. A blank nicename
            // has no destination, so it is omitted there.
            // ---
            $categoryEntryReferences = $this->resolveCategoryEntryReferences(
                $filteredCategories,
                $categoryTermIdMap
            );

            // ---
            // Resolve each tag to the `post_tags` COLLECTION entry the term pass
            // imported it as, exactly as categories are resolved above.
            // `UAMS-Web/uams-statamic-archive#2532` converted `post_tags` into a
            // site-scoped structured collection whose field is an `entries`
            // picker, so a site-prefixed term slug resolves to nothing there
            // (wordpress-importer-archive#1220). Only when tag import is enabled.
            // ---
            $tagEntryReferences = [];
            $rawTags = [];

            // An `if` reads the value's truthiness, which the cast does not change
            // @pest-mutate-ignore: RemoveBooleanCast
            if (
                (bool) config(
                    'wordpress-import.import_tags',
                    true
                )
            ) {
                // Extract tag slugs from WordPress XML category elements with domain="post_tag"
                $rawTags = $this->extractXpathStringValues(
                    $post,
                    'category[@domain="post_tag"]/@nicename'
                );

                // Resolve the nicenames through their term ids to entry ids
                $tagTermIdMap = $this->nicenameToTermIdMap['post_tag'] ?? [];

                // A blank nicename has no destination, so it is omitted there
                $tagEntryReferences = $this->resolveTermEntryReferences(
                    'post_tag',
                    $rawTags,
                    \is_array($tagTermIdMap) ? $tagTermIdMap : []
                );
            }

            // Check for news release PDF URL
            $newsReleasePdfUrl = XpathHelper::getStringValue(
                $post,
                'wp:postmeta[wp:meta_key="news_release_pdf_url"]/wp:meta_value'
            );

            // Check for news release PDF
            $newsReleasePdf = XpathHelper::getStringValue(
                $post,
                'wp:postmeta[wp:meta_key="news_release_pdf"]/wp:meta_value'
            );

            // Check for media contact
            $mediaContact = XpathHelper::getStringValue(
                $post,
                'wp:postmeta[wp:meta_key="media_contact"]/wp:meta_value'
            );

            // ---
            // Check if post has news-release category. The needle is not numeric, so a
            // loose comparison matches exactly what the strict one does.
            // ---
            // @pest-mutate-ignore: TrueToFalse
            $hasNewsReleaseCategory = \in_array(
                'news-release',
                $rawCategories,
                true
            );

            // ---
            // Determine if this post should use the news_release blueprint based on any of:
            // - news_release_pdf_url meta_key has non-null, non-'null', non-empty value
            // - news_release_pdf meta_key has non-null, non-'null', non-empty value
            // - media_contact meta_key has non-null, non-'null', non-empty value
            // - category with nicename="news-release" exists
            // ---
            $isNewsRelease = (
                $newsReleasePdfUrl !== ''
                    && $newsReleasePdfUrl !== 'null'
            )
                || (
                    $newsReleasePdf !== ''
                    && $newsReleasePdf !== 'null'
                )
                || (
                    $mediaContact !== ''
                    && $mediaContact !== 'null'
                )
                || $hasNewsReleaseCategory;

            // Check for post format categories to determine blueprint
            $postFormatCategories = $this->extractXpathStringValues(
                $post,
                'category[@domain="post_format"]/@nicename'
            );

            // ---
            // Determine if this post should use the audio blueprint based on
            // post-format-audio presence. The needle is not numeric, so a loose
            // comparison matches exactly what the strict one does.
            // ---
            // @pest-mutate-ignore: TrueToFalse
            $isAudioFormat = \in_array(
                'post-format-audio',
                $postFormatCategories,
                true
            );

            // Initialize entry data array with WordPress post data mapped to Statamic fields
            $data = [
                'blueprint' => $this->determineBlueprint(
                    $isNewsRelease,
                    $isAudioFormat
                ),
                'title' => $title,
                'slug' => $slug,
                'sticky_query' => XpathHelper::getBoolValue(
                    $post,
                    'wp:is_sticky',
                    false
                ),
                // ---
                // Both handles are PLURAL. The host app renamed them in
                // `uams-statamic@3e8a1646b` ("use plural handles for posts
                // taxonomy fields") and the importer never followed, so every
                // imported post carried two keys no blueprint defined (wordpress-importer-archive#422).
                // ---
                'post_categories' => $categoryEntryReferences,
                'post_tags' => $tagEntryReferences,
                'post_format' => 'standard',
                'boilerplate_query' => false,
                'news_release_pdf' => null,
                'title_short' => null,
                'excerpt' => null,
                'featured_image_4_3' => null,
                'featured_image_1_1' => null,
                'scripts_head' => ['code' => null, 'mode' => 'htmlmixed'],
                'scripts_body' => ['code' => null, 'mode' => 'htmlmixed'],
                'scripts_body_position' => 'bottom',
                'robots_index_meta' => false,
                'robots_follow_meta' => false,
                'robots_odp_meta' => false,
                'robots_imageindex_meta' => false,
                'robots_archive_meta' => false,
                'robots_snippet_meta' => false,
                'robots_canonical_meta' => null,
                'hide_creator_query' => false,
                'series' => $audioSeriesSlug,
                'segments' => [],
                'intro' => [],
                'original_wordpress_url' => $originalWordPressUrl,
                // ---
                // Loose keys the migration redirect generator reads to synthesize
                // structural WordPress redirects: `?p=<id>` ugly permalinks and
                // `/author/<nicename>/` archives (wordpress-importer-archive#446).
                // ---
                ...XpathHelper::extractMigrationRedirectMetadata($post),
            ];

            // Map status and visibility fields
            $isScheduledPost = $this->mapStatusAndVisibility(
                $status,
                $postPassword,
                $postDate,
                $data,
                $collectionHandle,
                $output,
                wpmemBlock: $this->readWpMembersBlock($post)
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
            $data['slug'] = $entrySlug;

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

                // Skip to the next iteration of the loop
                continue;
            }

            // Log that we're processing this post
            $output->writeln("Processing post '$entrySlug' with content length: ".\strlen($contentEncoded));

            // Extract SEO title from postmeta for short title
            $seoTitle = XpathHelper::getStringValue(
                $post,
                'wp:postmeta[wp:meta_key="_seopress_titles_title"]/wp:meta_value'
            );

            if ($seoTitle !== '' && $seoTitle !== $data['title']) {
                // Strip HTML tags from SEO title
                $data['title_short'] = HtmlTagStripper::strip($seoTitle);
            }

            // Extract the encoded excerpt from WordPress XML
            $excerptEncoded = XpathHelper::getStringValue(
                $post,
                'excerpt:encoded'
            );

            // Extract SEO titles description from postmeta
            $seoTitlesDesc = XpathHelper::getStringValue(
                $post,
                'wp:postmeta[wp:meta_key="_seopress_titles_desc"]/wp:meta_value'
            );

            // Extract SEO meta description from postmeta
            $seoMetaDesc = XpathHelper::getStringValue(
                $post,
                'wp:postmeta[wp:meta_key="seo_meta_description"]/wp:meta_value'
            );

            // ---
            // Strip HTML tags from excerpt sources and set with fallback priority:
            // excerpt > SEO titles desc > SEO meta desc
            // ---
            $data['excerpt'] = ExcerptResolver::resolve(
                $excerptEncoded,
                $seoTitlesDesc,
                $seoMetaDesc
            );

            // Extract thumbnail ID from postmeta for featured image
            $thumbnailId = XpathHelper::getStringValue(
                $post,
                'wp:postmeta[wp:meta_key="_thumbnail_id"]/wp:meta_value'
            );

            // Check if a featured image thumbnail ID exists
            if ($thumbnailId !== '') {
                // Store thumbnail ID in entry data for block processors
                $data['_thumbnail_id'] = $thumbnailId;

                // Import the featured image file with graceful handling for disabled uploads
                $featuredImagePath = FileProcessor::importFileFromAttachmentWithDisabledUploadHandling(
                    $thumbnailId,
                    $attachments,
                    $site,
                    $assetImporter,
                    $output,
                    'images',
                    'featured image',
                    'PostEntryCreator::createEntries',
                    $originalWordPressUrl
                );

                // ---
                // Omit the handle when nothing landed, matching the pages and KB paths.
                // `importFileFromAttachmentWithDisabledUploadHandling()` returns `?string`
                // and both absent values are reachable — but NOT by the route the pages
                // path uses, and the difference is easy to get wrong.
                //
                // `FileProcessor::importFileWithDisabledUploadHandling()` tests
                // `if ($fileUrl === '') { return null; }` *before* it checks whether
                // uploads are disabled, so an attachment row with no URL yields `null`
                // here. `ImageProcessor`'s image helper guards the attachment *id* rather
                // than the URL and returns `wp:attachment_url` verbatim, which is why the
                // same row yields `''` on the pages path.
                //
                // On this path `''` arrives from the uploads-ENABLED branch instead:
                // `AssetImporter::importFile()` returns `OverwriteHandler::handleFileImport()`
                // unconditionally, and `AssetImporter` itself treats that return as
                // possibly empty (wordpress-importer-archive#754).
                // ---
                if ($featuredImagePath !== null && $featuredImagePath !== '') {
                    // Use the returned path, which may carry a -1 suffix
                    $data['featured_image_16_9'] = $featuredImagePath;
                }

                // Surface the photo credit the WordPress site displayed. wordpress-importer-archive#439
                if (
                    $this->shouldShowFeaturedImageCredit(
                        $featuredImagePath,
                        $thumbnailId,
                        $attachments
                    )
                ) {
                    $data['featured_image_credit_query'] = true;
                }
            }

            // ---
            // The WordPress news-release PDF is deliberately NOT downloaded (wordpress-importer-archive#620).
            // The host app regenerates this PDF from its own news-release template on
            // save, uploads it to the same container, and sets `news_release_pdf`
            // itself -- so anything imported here is overwritten on the entry's own
            // save. Worse, both host cleanup branches sweep the container and delete
            // every asset tagged for the entry that is not the one they just wrote, so
            // a downloaded file is removed moments after it lands.
            //
            // Measured across the corpus: roughly 2,400 posts carry a non-empty
            // `news_release_pdf_url`, so this was 2,400 fetches, container writes and
            // deletes per full import, discarded every time.
            //
            // `$isNewsRelease` is still needed above -- it selects the `news_release`
            // blueprint -- which is why the detection stays and only the download goes.
            // ---

            // Process content based on post format
            if ($isAudioFormat) {
                // Set additional audio entry defaults required by the post/audio template
                $data['excerpt_display_query'] = true;
                $data['title_options'] = 'normal';
                $data['cover_image_query'] = true;
                $data['series_boilerplate_display_query'] = true;
                $data['template'] = 'post/audio';

                // Use PostAudioProcessor for audio format posts
                // Initialize the post audio processor for specialized audio content handling
                $postAudioProcessor = new PostAudioProcessor;

                // Process the content to extract audio segments and intro content
                $audioContent = $postAudioProcessor->processAudioFormatContent(
                    $contentEncoded,
                    $site,
                    $assetImporter,
                    $output,
                    $entrySlug,
                    $title,
                    $audioSeriesConfig,
                    $postDate,
                    $postId
                );

                // Assign audio segments to the data array
                $data['segments'] = $audioContent['segments'];

                // Assign intro to the data array
                $data['intro'] = $audioContent['intro'];
            } else { // Handle non-audio format posts with standard content processing
                // Get WordPress terms from XML parser for ContentProcessor
                $terms = $xmlParser->getTerms();

                // Use standard content processing for other post formats
                // Process content using the standard ContentProcessor for Bard or page builder
                $parsedContent = $contentProcessor->process(
                    $contentEncoded,
                    $site,
                    $assetImporter,
                    $output,
                    $xmlParser,
                    $title !== '' ? $title : 'Untitled Post',
                    $postId,
                    $link,
                    $attachments,
                    $terms,
                    [],
                    $data,
                    $collectionHandle,
                    $entrySlug,
                    $entryId
                );

                // Check if the processed content is empty or contains only default message
                $parsedPostContent = $parsedContent['content'] ?? [];
                if (! \is_array($parsedPostContent)) {
                    $parsedPostContent = [];
                }

                $isEmptyContent = $this->isEmptyContent($parsedPostContent);

                // Assign content to data array only if it's not empty
                if (! $isEmptyContent) {
                    $data['content'] = $parsedPostContent;
                }
            }

            $metadataMapper->mapMetadata(
                $post,
                $data,
                $xmlParser,
                $assetImporter,
                $attachments,
                $output
            );

            // Add updated_at field from post modified date
            $this->addUpdatedAtField(
                $data,
                $post,
                'PostEntryCreator::createEntries'
            );

            // Register URL mapping for internal link resolution
            LinkProcessor::registerUrlMapping(
                $link,
                $postId
            );

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
            if ($entry instanceof Entry) {
                $this->addPageData(
                    $postId,
                    $entryId,
                    $parentId,
                    $menuOrder,
                    $title !== '' ? $title : 'Untitled Post',
                    null,
                    $entry
                );
            }

            // ---
            // Categories are deliberately NOT tracked here. That map existed to
            // feed a taxonomy-term validator, and `post_categories` is no longer
            // a taxonomy — the entries it references are created and validated by
            // the term pass instead (wordpress-importer-archive#422).
            // ---

            // Clean up variables to free up memory
            unset(
                $post,
                $title,
                $slug,
                $postPassword,
                $postDate,
                $postId,
                $parentId,
                $menuOrder,
                $contentEncoded,
                $postDate,
                $originalWordPressUrl,
                $link,
                $rawCategories,
                $categoryEntryReferences,
                $rawTags,
                $tagEntryReferences,
                $newsReleasePdfUrl,
                $isNewsRelease,
                $postFormatCategories,
                $isAudioFormat,
                $data,
                $excerptEncoded,
                $audioContent,
                $parsedContent,
                $isEmptyContent,
                $entry,
                $entrySlug,
                $entryDate,
                $entryId,
                $terms
            );

            // ---
            // Collect garbage after processing each post in long-running loop. This
            // bounds memory over a large export and changes no output.
            // ---
            // @pest-mutate-ignore: RemoveFunctionCall
            gc_collect_cycles();
        }

        // Create audio series taxonomy terms if audio series content was detected
        if ($audioSeriesConfig instanceof AudioSeriesConfig) {
            AudioSeriesManager::createAudioSeriesTerm(
                $audioSeriesConfig,
                $site,
                $output
            );
        }

        // Clean up variables to free up memory
        unset(
            $attachments,
            $audioSeriesConfig
        );

        // Return page data for tree building and other post-processing tasks
        return $this->pageData;
    }

    /**
     * Return taxonomy handles used for post import based on config.
     *
     * When wordpress-import.import_tags is true or unset, returns ['category',
     * 'post_tag']. When false, returns ['category'] only. Used by createEntries
     * and by tests to verify tag import is gated by config.
     *
     * @return array<int|string, mixed> WordPress taxonomy handles to initialize (e.g., ['category', 'post_tag'])
     */
    public static function getTaxonomyHandlesForPostImport(): array
    {
        $handles = ['category'];

        // An `if` reads the value's truthiness, which the cast does not change
        // @pest-mutate-ignore: RemoveBooleanCast
        if ((bool) config('wordpress-import.import_tags', true)) {
            $handles[] = 'post_tag';
        }

        return $handles;
    }

    /**
     * Extract XPath result values as normalized strings.
     *
     * @param  SimpleXMLElement  $post  XML post node
     * @param  string  $xpath  XPath expression to evaluate
     * @return list<string> Normalized XPath string values
     */
    private function extractXpathStringValues(
        SimpleXMLElement $post,
        string $xpath
    ): array {
        // Execute XPath query with safe empty-array fallback
        $elements = XpathHelper::xpathElements(
            $post,
            $xpath
        );

        // Build normalized values list from XPath results
        $values = [];
        foreach ($elements as $element) {
            $values[] = (string) $element;
        }

        return $values;
    }

    /**
     * Indicates whether this content type uses ShowPeople functionality.
     *
     * Posts do not use ShowPeople shortcodes, so this always returns false.
     * This method is required by the abstract parent class and is used by
     * the EntryCreator to determine if ShowPeople processing is needed.
     *
     * @return bool Always returns false for posts
     */
    public function hasShowPeople(): bool
    {
        return false; // Posts don't use ShowPeople
    }

    /**
     * Returns nothing: a post's categories are no longer taxonomy terms.
     *
     * {@see BaseEntryCreator::getPostCategories()} exists so the flat-taxonomy
     * validators can be handed the category-to-entries map a creator built. A
     * post's categories stopped being terms in `UAMS-Web/uams-statamic-archive#1433` —
     * they are `post_categories` collection entries, created and trees-built by
     * {@see StructuredTermImporter} during the term pass — so there is no term
     * map to hand over and nothing left to validate (wordpress-importer-archive#422).
     *
     * Kept rather than removed because the method is abstract on the base class
     * and every other creator still implements it against a real taxonomy.
     *
     * @return array<int|string, mixed> Always empty
     */
    public function getPostCategories(): array
    {
        return [];
    }

    /**
     * Returns the post tags collected for taxonomy validation, which is none.
     *
     * The tag-to-entries map existed to feed a taxonomy-term validator. Tags
     * became a site-scoped collection in `UAMS-Web/uams-statamic-archive#2532`, and the
     * term pass creates the entries a post references, so there is nothing left
     * to validate or create afterwards (wordpress-importer-archive#1220).
     *
     * @return array<int|string, mixed> Always empty
     */
    public function getPostTags(): array
    {
        return [];
    }

    /**
     * Determine the appropriate blueprint based on post format and content type.
     *
     * @param  bool  $isNewsRelease  Whether the post is a news release
     * @param  bool  $isAudioFormat  Whether the post has audio format
     * @return string The blueprint name to use
     */
    private function determineBlueprint(
        bool $isNewsRelease,
        bool $isAudioFormat
    ): string {
        if ($isNewsRelease) {
            return 'news_release';
        }

        if ($isAudioFormat) {
            return 'audio';
        }

        return 'post';
    }

    /**
     * Creates taxonomy terms from stored data with proper taxonomy mapping.
     *
     * Overrides the base implementation to map WordPress 'category' and 'post_tag'
     * taxonomies to Statamic 'post_categories' and 'post_tags' taxonomies.
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
        // Map WordPress taxonomies to Statamic taxonomies
        $statamicTaxonomy = match ($wordpressTaxonomyHandle) {
            'category' => 'post_categories',
            'post_tag' => 'post_tags',
            default => $wordpressTaxonomyHandle
        };

        // Call the parent method with the mapped taxonomy
        parent::createTaxonomyTermsFromStoredData(
            $siteSpecificData,
            $statamicTaxonomy,
            $site,
            $output
        );
    }

    /**
     * Validates the taxonomy terms used in post entries, of which there are none.
     *
     * Neither of a post's groupings is a taxonomy any more. **Categories** stopped
     * being taxonomy terms in `UAMS-Web/uams-statamic-archive#1433`: the validator that
     * used to run over them read an absent `content/taxonomies/post_categories`
     * directory, concluded every category was missing, and tried to create terms
     * in a taxonomy `Taxonomy::findByHandle()` returns null for (wordpress-importer-archive#422). **Tags**
     * followed in `UAMS-Web/uams-statamic-archive#2532` (#1220). The entries both fields
     * reference are created by {@see StructuredTermImporter} during the term pass,
     * before any post is written, so nothing is left to create afterwards.
     *
     * @param  string  $collectionHandle  The collection handle (e.g., 'posts')
     * @param  string  $site  The Statamic site handle
     * @param  OutputInterface  $output  Console output for logging
     */
    public function validateTaxonomies(
        string $collectionHandle,
        string $site,
        OutputInterface $output
    ): void {
        // Nothing to validate; see the docblock.
    }
}
