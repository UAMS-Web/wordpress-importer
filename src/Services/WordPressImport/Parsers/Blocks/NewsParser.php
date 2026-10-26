<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks;

use Statamic\Fieldtypes\RowId;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlStreamParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\PostCategoryEntryResolver;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\AcfFieldNormalizer;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\HtmlTagStripper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\NewsArticleResolver;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\NewsBlockSourceSites;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;

/**
 * Parses WordPress ACF uams-news blocks into Statamic page_builder replicator
 * sets.
 *
 * Converts the ACF uams-news block attributes (e.g., news_format, news_title,
 * news_category, news_article_id) into a Statamic uamsweb_block_news replicator
 * set for the landing_page blueprint's page_builder field. Handles conditional
 * fields based on the format (e.g., headlines, full, side) and omits empty
 * optional fields.
 *
 * The destination fieldset was reshaped by `UAMS-Web/uams-statamic#76` from a
 * remote `news.uams.edu` syndication bridge into a native cross-site query of
 * the local `posts` collection, so three of the fields this parser used to
 * write no longer exist in the shape they were written in:
 *
 *   - `local` became `source_sites`, a checkboxes array of site tokens.
 *   - `advanced_cat` was dropped with no replacement.
 *   - `category` became an `entries` picker over the `post_categories`
 *     **collection**, so a WordPress category slug now resolves to an entry id
 *     via {@see PostCategoryEntryResolver}. (The ticket that specified this work
 *     predates `UAMS-Web/wordpress-importer-archive#422` and called for a
 *     `terms_search_index` picker holding `post_categories::<slug>` strings;
 *     the fieldset moved to a collection before this landed, and the fieldset
 *     is the authority.)
 *
 * `article_id` was reshaped the same way — an `entries` picker over `posts` —
 * and resolves through {@see NewsArticleResolver} against the `wordpress_post_id`
 * every imported post already carries. A reference the import has not seen is
 * dropped rather than written as a dangling id; see {@see resolveArticleId()}.
 */
class NewsParser
{
    /**
     * Defines the mapping between field-based ACF field names and standard field
     * names.
     *
     * This method provides the field mappings needed by AcfFieldNormalizer to handle
     * the conversion of WordPress ACF field names like field_news_title_b to standard
     * names like news_title.
     *
     * @return array<int|string, mixed> The field mappings for news blocks
     */
    private function getFieldMappings(): array
    {
        return [
            'field_news_format_b' => 'news_format',
            'field_news_title_b' => 'news_title',
            'field_news_hide_title_b' => 'news_hide_title',
            'field_news_description_b' => 'news_description',
            'field_news_category_b' => 'news_category',
            'field_news_count_b' => 'news_count',
            'field_news_offset_b' => 'news_offset',
            'field_news_background_color_b' => 'news_background_color',
            'field_news_hide_img_b' => 'news_hide_img',
            'field_news_hide_author_b' => 'news_hide_author',
            'field_news_hide_date_b' => 'news_hide_date',
            'field_news_include_link_b' => 'news_include_link',
            'field_news_position_b' => 'news_position',
            'field_news_article_id_b' => 'news_article_id',
            'field_news_local_b' => 'news_local',
            // Both spellings of the background-colour field reach the same
            // normalized name; the corpus uses one or the other, never both.
            'field_news_bgcolor_b' => 'news_background_color',
            // `field_news_advanced_cat_b` is deliberately absent: the reshaped
            // fieldset dropped `advanced_cat` with no replacement, so nothing
            // reads the normalized name any more.
        ];
    }

    /**
     * Defines which nested structures should be processed for news blocks.
     *
     * This method provides the nested structure mappings needed by AcfFieldNormalizer
     * to handle complex ACF block structures.
     *
     * @return array<int|string, mixed> The nested structure mappings for news blocks
     */
    private function getNestedStructures(): array
    {
        return [
            // No nested structures for news blocks
        ];
    }

    /**
     * Parses a WordPress ACF uams-news block into a Statamic page_builder replicator
     * set.
     *
     * Maps block attributes to a uamsweb_block_news set, including format, heading,
     * category, article ID, and display options. Processes text fields and omits
     * empty or inapplicable fields based on the format. Uses AcfFieldNormalizer
     * for consistent field mapping across different WordPress ACF data structures.
     *
     * @param  array<int|string, mixed>  $attributes  The block attributes from the wp:acf/uams-news comment
     * @param  string  $site  The Statamic site handle (e.g., 'default') for asset paths
     * @param  AssetImporter  $assetImporter  Instance for importing images (unused in this block)
     * @param  OutputInterface  $output  Console output for logging progress and errors
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects from XML (unused in this block)
     * @return array<int|string, mixed> The Statamic uamsweb_block_news replicator set
     */
    public function parse(
        array $attributes,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = []
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

        // Build the set from the normalized data — shared with parseModule()
        return $this->buildSet(
            $normalizedData,
            $site
        );
    }

    /**
     * Parses WordPress modules template news_grid data into Statamic page_builder
     * format.
     *
     * This method handles the conversion of modules template data (from
     * uamswp_modules) to Statamic page_builder format, as opposed to the parse()
     * method which handles ACF block data. The modules template uses a different data
     * structure with prefixed field names. Uses AcfFieldNormalizer for consistent
     * field mapping.
     *
     * @param  array<int|string, mixed>  $moduleData  The module data from uamswp_modules
     * @param  string  $site  The site handle for asset processing
     * @param  AssetImporter  $assetImporter  The asset importer instance
     * @param  OutputInterface  $output  The console output interface
     * @param  array<int|string, mixed>  $attachments  Array of attachment data for image processing
     * @param  ?XmlStreamParser  $xmlParser  The XML stream parser for additional data access
     * @param  array<int|string, mixed>  $terms  Array of taxonomy terms (unused in this parser)
     * @return array<int|string, mixed>|null The page_builder block or null if parsing failed
     */
    public function parseModule(
        array $moduleData,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = [],
        ?XmlStreamParser $xmlParser = null,
        array $terms = []
    ): ?array {
        // Normalize field names using AcfFieldNormalizer
        $normalizedData = AcfFieldNormalizer::normalize(
            $moduleData,
            $this->getFieldMappings(),
            $this->getNestedStructures()
        );

        // Build the set from the normalized data — shared with parse()
        return $this->buildSet(
            $normalizedData,
            $site
        );
    }

    /**
     * Builds the uamsweb_block_news replicator set from normalized field data.
     *
     * The ACF-block and modules-template paths differ only in how they reach
     * normalized data, so both funnel through here rather than carrying two
     * copies of the mapping that drift apart.
     *
     * @param  array<int|string, mixed>  $normalizedData  Field data with AcfFieldNormalizer names applied
     * @param  string  $site  The Statamic site handle being imported into
     * @return array<int|string, mixed> The Statamic uamsweb_block_news replicator set
     */
    private function buildSet(
        array $normalizedData,
        string $site
    ): array {
        // Map basic fields using normalized data
        $format = $normalizedData['news_format'] ?? 'grid';
        $titleRaw = $normalizedData['news_title'] ?? '';
        $heading = HtmlTagStripper::strip(
            \is_string($titleRaw) ? $titleRaw : ''
        );
        $hideHeading = ($normalizedData['news_hide_title'] ?? '0') === '1';
        $descriptionRaw = $normalizedData['news_description'] ?? null;
        $description = \is_string($descriptionRaw)
            ? HtmlTagStripper::strip($descriptionRaw)
            : '';
        $local = ($normalizedData['news_local'] ?? '0') === '1';
        $countRaw = $normalizedData['news_count'] ?? null;
        $count = \is_numeric($countRaw) ? (int) $countRaw : '';
        $offsetRaw = $normalizedData['news_offset'] ?? null;
        $offset = \is_numeric($offsetRaw) ? (int) $offsetRaw : '';
        $hideImg = ($normalizedData['news_hide_img'] ?? '0') === '1';
        $hideAuthor = ($normalizedData['news_hide_author'] ?? '0') === '1';
        $hideDate = ($normalizedData['news_hide_date'] ?? '0') === '1';
        $includeLink = ($normalizedData['news_include_link'] ?? '0') === '1';
        $position = $normalizedData['news_position'] ?? 'left';

        // `news_bgcolor` is an older spelling of the same field and the two are
        // disjoint across the corpus — 49 of 297 blocks carry only the short
        // one, and reading just the long one imported all 49 as `bg-auto`
        // instead of the `bg-white` / `bg-gray` they were authored with.
        $backgroundColor = $normalizedData['news_background_color']
            ?? $normalizedData['news_bgcolor']
            ?? 'bg-auto';

        // Resolve the WordPress category slug(s) to post_categories entry ids
        // on this site — see PostCategoryEntryResolver for why only this one
        $categoryRaw = $normalizedData['news_category'] ?? '';
        $categoryReferences = \is_scalar($categoryRaw)
            ? PostCategoryEntryResolver::referencesFor(
                (string) $categoryRaw,
                $site
            )
            : [];

        // Build the replicator set, omitting empty or inapplicable fields
        $result = [
            'id' => (new RowId)->generate(), // Unique ID for replicator set
            'type' => 'uamsweb_block_news', // Statamic fieldset handle
            'format' => $format, // Required radio field
            'source_sites' => NewsBlockSourceSites::forLocalFlag($local), // Required checkboxes
            'background_color' => $backgroundColor, // Required select
            'enabled' => true, // Standard for replicator sets
        ];

        // Include conditional fields based on format
        if (
            \in_array(
                $format,
                [
                    'headlines',
                    'excerpts',
                    'cards',
                    'grid',
                ],
                true
            )
        ) {
            if ($heading !== '') {
                $result['block_heading'] = $heading; // Required for these formats
                $result['hide_block_heading'] = $hideHeading; // Required for these formats
            }

            if ($description !== '') {
                $result['block_description'] = $description; // Optional
            }

            if ($categoryReferences !== []) {
                $result['category'] = $categoryReferences; // Optional for non-full formats
            }

            if ($offset !== '') {
                $result['offset'] = $offset; // Optional for non-full formats
            }

            $result['hide_img'] = $hideImg; // Required for these formats
            $result['hide_date'] = $hideDate; // Required for these formats
        }

        if (
            $format !== 'side'
        ) {
            $result['hide_creator_query'] = $hideAuthor; // Required for non-side formats
        }

        if (
            \in_array(
                $format,
                [
                    'cards',
                    'grid',
                    'side',
                ],
                true
            )
        ) {
            $result['include_link'] = $includeLink; // Required for these formats
        }

        if ($format === 'side') {
            $result['position'] = $position; // Required for side format
            if ($categoryReferences !== []) {
                $result['category'] = $categoryReferences; // Optional for non-full formats
            }

            if ($offset !== '') {
                $result['offset'] = $offset; // Optional for non-full formats
            }
        }

        if ($format === 'full') {
            // The `full` format features one post, picked by entry id
            $articleReference = $this->resolveArticleId(
                $normalizedData['news_article_id'] ?? '',
                $site
            );

            if ($articleReference !== []) {
                $result['article_id'] = $articleReference; // Optional for full format
            }

            $result['hide_creator_query'] = $hideAuthor; // Required for full format
        }

        if (
            \in_array(
                $format,
                [
                    'headlines',
                    'excerpts',
                ],
                true
            )
        ) {
            if ($count !== '') {
                $result['count'] = $count; // Optional for these formats
            }
        }

        return $result;
    }

    /**
     * Resolves the WordPress featured-article id to a `posts` entry reference.
     *
     * The reshaped field is an `entries` picker holding a Statamic entry id, and
     * WordPress carries the selection as a raw post id. The map between them is
     * `wordpress_post_id`, which {@see XpathHelper::extractMigrationRedirectMetadata()}
     * has stamped into the data of every imported post and page since wordpress-importer-archive#446 for the
     * host app's migration redirect generator. Nothing in *this importer* had ever
     * read it back off a saved entry, which is why
     * `UAMS-Web/wordpress-importer-archive#621` was filed believing no durable id existed.
     * {@see NewsArticleResolver} is the lookup.
     *
     * **A reference nothing matches is dropped, not written.** The picker cannot
     * resolve an id that names no entry, so an unmatched selection degrades the
     * block to "no featured article" and records a warning for a human, rather
     * than persisting a dangling id.
     *
     * Two structural reasons a first import commonly finds nothing, both recorded
     * on wordpress-importer-archive#621 and neither fixed here: the block is parsed during the `page` import
     * and `page` runs before `post`, so a post in the same export does not exist
     * yet; and the ids overwhelmingly name posts on `news.uams.edu`, which arrive
     * in a different export **and import into a different Statamic site**, while
     * this lookup is site-scoped like every sibling resolver. Every
     * `news_article_id` in the 259-file export corpus is the empty string, so no
     * content that exists today is waiting on either.
     *
     * @param  mixed  $articleId  The raw WordPress post id from the block
     * @param  string  $site  The Statamic site handle being imported into
     * @return list<string> The matching entry id, or empty when nothing matches
     */
    private function resolveArticleId(
        mixed $articleId,
        string $site
    ): array {
        // Nothing to report when the block never named an article
        if (
            in_array($articleId, [null, '', []], true)
        ) {
            return [];
        }

        // A non-scalar value is still a selection that is being dropped, so it
        // is reported too rather than falling through the scalar guard unseen.
        $reportedId = \is_scalar($articleId)
            ? (string) $articleId
            : \gettype($articleId);

        // A scalar id can be looked up; a non-scalar one never matches, and falls
        // through to the warning below carrying its type for the reader.
        if (\is_scalar($articleId)) {
            $entryId = NewsArticleResolver::entryIdFor((string) $articleId, $site);

            if ($entryId !== null) {
                return [$entryId];
            }
        }

        // The two ways a reference is dropped are not the same event, and the
        // report must not claim a lookup that never ran: a malformed value is
        // turned away by `isWellFormedId()` before any query is issued.
        $reason = \is_scalar($articleId) && NewsArticleResolver::isWellFormedId((string) $articleId)
            ? 'No `posts` entry on this site carries this WordPress post id, so the uamsweb_block_news '
                .'`article_id` reference was dropped rather than written as a dangling one. These ids '
                .'usually name posts on news.uams.edu, which import into their own Statamic site — this '
                .'lookup is site-scoped, so importing that export will not by itself resolve the '
                .'reference. Re-link the featured article in the Control Panel.'
            : 'This uamsweb_block_news `article_id` value is not shaped like a WordPress post id — that '
                .'is a positive integer with no padding — so no entry could carry it and none was looked '
                .'up. The reference was dropped rather than written as a dangling one. Re-link the '
                .'featured article in the Control Panel.';

        // Surface the dropped reference so the loss is visible rather than silent
        ImportWarningManager::addWarning(
            'content',
            'unresolvable_news_article_id',
            [
                'site' => $site,
                'wordpress_post_id' => $reportedId,
                'page_url' => ProcessingContext::getWordPressPageLink(),
                'reason' => $reason,
            ]
        );

        return [];
    }
}
