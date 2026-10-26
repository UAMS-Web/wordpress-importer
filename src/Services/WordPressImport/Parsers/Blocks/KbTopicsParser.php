<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks;

use Illuminate\Support\Facades\Log;
use Statamic\Fieldtypes\RowId;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\KbLinkRegistry;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;

/**
 * Parses WordPress wp:minervakb/topics blocks into Statamic page_builder
 * replicator sets.
 *
 * Converts the wp:minervakb/topics block attributes into a Statamic
 * uamsweb_block_kb_topics replicator set for the landing_page blueprint's
 * page_builder field. Handles field mapping, topic list processing, and
 * conditional field setting based on the mapping rules.
 */
class KbTopicsParser
{
    /**
     * Parses a WordPress wp:minervakb/topics block into a Statamic page_builder
     * replicator set.
     *
     * Maps block attributes to a uamsweb_block_kb_topics set, processing topic
     * lists, display settings, and conditional fields. Handles field mapping
     * according to the specified rules and omits empty optional fields.
     *
     * @param  array<int|string, mixed>  $attributes  The block attributes from the wp:minervakb/topics comment
     * @param  string  $site  The Statamic site handle (e.g., 'default') for asset paths
     * @param  AssetImporter  $assetImporter  Instance for importing images (unused in this block)
     * @param  OutputInterface  $output  Console output for logging progress and errors
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects from XML (unused in this block)
     * @return array<int|string, mixed> The Statamic uamsweb_block_kb_topics replicator set
     */
    public function parse(
        array $attributes,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = []
    ): array {
        // Initialize result array with required fields
        $result = [
            'id' => (new RowId)->generate(),
            'type' => 'uamsweb_block_kb_topics',
            'enabled' => true,
        ];

        // ---
        // Map title to block_heading
        // ---

        // Initialize title
        $title = $attributes['title'] ?? null;

        // Check if title is set
        if ($title !== null) {
            // Set block_heading to 'Popular Topics' if title is empty, 'Popular Topics', or 'Popular topics'
            $result['block_heading'] = (in_array($title, ['', 'Popular Topics', 'Popular topics'], true))
                ? 'Popular Topics'
                : $title;
        }

        // ---
        // Map titleSize to title_size
        // ---

        // Initialize titleSize
        $titleSize = $attributes['titleSize'] ?? null;

        // Check if titleSize is set
        if (
            $titleSize !== null
            && $titleSize !== []
            && \is_array($titleSize)
        ) {
            // Set title_size to titleSize
            $result['title_size'] = $titleSize;
        }

        // ---
        // Map titleColor to title_color
        // ---

        // Initialize titleColor
        $titleColor = $attributes['titleColor'] ?? null;

        // Check if titleColor is set
        if (
            $titleColor !== null
            && $titleColor !== ''
        ) {
            // Set title_color to titleColor
            $result['title_color'] = $titleColor;
        }

        // ---
        // Map topics to topic_source, topic_dynamic, and topic_list
        // ---

        // Initialize topics
        $topics = $attributes['topics'] ?? null;

        // Initialize topic source
        $topicSource = null;

        // Initialize topic dynamic
        $topicDynamic = [];

        // Initialize topic list
        $topicList = [];

        // Check if topics is set and is a string
        if ($topics !== null && \is_string($topics)) {
            // ---
            // Check if topics is a comma-separated list containing only numeric IDs
            // (no keywords like 'recent', 'updated', etc.)
            // ---
            if (\preg_match('/^\d+(,\d+)*$/', $topics) === 1) {
                // Split comma-separated IDs
                $topicIds = \explode(',', $topics);

                // Map WordPress term IDs to Statamic entry IDs
                foreach ($topicIds as $termId) {
                    // Trim term ID
                    $termId = \trim($termId);

                    // Skip empty term IDs
                    if ($termId === '') {
                        // Continue to next term ID
                        continue;
                    }

                    // Get Statamic entry ID for this term ID
                    $entryId = KbLinkRegistry::getKbtopicEntryIdByTermId($termId);

                    // Check if entry ID was found
                    if ($entryId !== null) {
                        // Add entry ID to topic list
                        $topicList[] = $entryId;
                    } else { // Otherwise, log warning about missing entry ID
                        // Enhance log context with entry information
                        $logData = LogContextHelper::enhanceWithEntryContext(
                            [
                                'site' => $site,
                                'term_id' => $termId,
                                'topics_string' => $topics,
                            ]
                        );

                        // Log warning about missing entry ID
                        Log::warning(
                            LogContextHelper::formatMessageWithEntry(
                                "KbTopicsParser::parse: Could not find Statamic entry ID for WordPress term ID '$termId'",
                                $logData['page_url']
                            ),
                            $logData['context']
                        );
                    }
                }

                // ---
                // Set topic_source to 'list' if we have any valid entry IDs
                // ---

                // Check if topic list is not empty
                if ($topicList !== []) {
                    // Set topic_source to 'list'
                    $topicSource = 'list';
                }
            } else { // Otherwise
                // ---
                // Parse comma-separated dynamic topic keywords and map them to
                // topic_dynamic values
                // ---

                // Split topics string by comma
                $topicKeywords = \explode(
                    ',',
                    $topics
                );

                // Process each keyword in order
                foreach ($topicKeywords as $keyword) {
                    // Trim keyword
                    $keyword = \trim($keyword);

                    // ---
                    // Skip empty keywords
                    // ---

                    // Check if keyword is empty
                    if ($keyword === '') {
                        // Continue to next keyword
                        continue;
                    }

                    // ---
                    // Map keyword to topic_dynamic value
                    // ---

                    // Check if keyword is 'recent'
                    if ($keyword === 'recent') {
                        // Add 'recently_published' to topic dynamic
                        $topicDynamic[] = 'recently_published';
                    } elseif ($keyword === 'updated') { // Check if keyword is 'updated'
                        // Add 'recently_updated' to topic dynamic
                        $topicDynamic[] = 'recently_updated';
                    } elseif ($keyword === 'top_views') { // Check if keyword is 'top_views'
                        // Add 'top_views' to topic dynamic
                        $topicDynamic[] = 'top_views';
                    } elseif ($keyword === 'top_likes') { // Check if keyword is 'top_likes'
                        // Add 'top_likes' to topic dynamic
                        $topicDynamic[] = 'top_likes';
                    }
                }

                // ---
                // Set topic_source to 'dynamic' if we have any valid dynamic topics
                // ---

                // Check if topic dynamic is not empty
                if ($topicDynamic !== []) {
                    // Set topic_source to 'dynamic'
                    $topicSource = 'dynamic';
                }
            }
        }

        // ---
        // Set topic_source if determined
        // ---

        // Check if topic source is not null
        if ($topicSource !== null) {
            // Set topic_source to topicSource
            $result['topic_source'] = $topicSource;
        }

        // ---
        // Set topic_dynamic if we have dynamic topic values
        // ---

        // Check if topic dynamic is not empty
        if ($topicDynamic !== []) {
            // Set topic_dynamic to topicDynamic
            $result['topic_dynamic'] = $topicDynamic;
        }

        // ---
        // Set topic_list if we have entry IDs
        // ---

        // Check if topic list is not empty
        if ($topicList !== []) {
            // Set topic_list to topicList
            $result['topic_list'] = $topicList;
        }

        // ---
        // Map view to topic_display_style
        // ---

        // Initialize view
        $view = $attributes['view'] ?? null;

        // Check if view is set and is either 'list' or 'box'
        if (
            $view !== null
            && ($view === 'list' || $view === 'box')
        ) {
            // Set topic_display_style to view
            $result['topic_display_style'] = $view;
        }

        // ---
        // Map columns to topic_columns
        // ---

        // Initialize columns
        $columns = $attributes['columns'] ?? null;

        // Check if columns is set
        if ($columns !== null) {
            // Check if columns is '1col'
            if ($columns === '1col') {
                // Set topic_columns to '1'
                $result['topic_columns'] = '1';
            } elseif ($columns === '2col') { // Check if columns is '2col'
                // Set topic_columns to '2'
                $result['topic_columns'] = '2';
            } elseif ($columns === '3col') { // Check if columns is '3col'
                // Set topic_columns to '3'
                $result['topic_columns'] = '3';
            } elseif ($columns === '4col') { // Check if columns is '4col'
                // Set topic_columns to '4'
                $result['topic_columns'] = '4';
            }
        }

        // ---
        // Map limit to topic_limit
        // ---

        // Initialize limit
        $limit = $attributes['limit'] ?? null;

        // Check if limit is set and topic source is not null
        if ($limit !== null && $topicSource !== null) {
            // Check if topic_source is 'dynamic' (supports limit)
            if ($topicSource === 'dynamic') {
                // Set topic_limit to limit if it's a positive number string, otherwise '4'
                $result['topic_limit'] = (
                    \is_string($limit)
                    && \preg_match('/^\d+$/', $limit) === 1
                    && (int) $limit > 0
                )
                    ? $limit
                    : '4';
            }
        }

        // ---
        // Map hideChildren to hide_children_query
        // ---

        // Initialize hideChildren
        $hideChildren = $attributes['hideChildren'] ?? null;

        // Check if hideChildren is set and topic source is not null
        if (
            $hideChildren !== null
            && $topicSource !== null
        ) {
            // Check if topic_source is 'dynamic' (supports hideChildren)
            if ($topicSource === 'dynamic') {
                // Set hide_children_query to hideChildren
                $result['hide_children_query'] = (bool) $hideChildren;
            }
        }

        // ---
        // Also handle hideChildren when topics is empty
        // ---

        // Check if hideChildren is set and topics is empty
        if (
            $hideChildren !== null
            && ($topics === null || $topics === '')
        ) {
            // Set hide_children_query to hideChildren
            $result['hide_children_query'] = (bool) $hideChildren;
        }

        // ---
        // Map showDescription to topic_description_query
        // ---

        // Initialize showDescription
        $showDescription = $attributes['showDescription'] ?? null;

        // Check if showDescription is set and is false
        if ($showDescription === false) {
            // Set topic_description_query to false
            $result['topic_description_query'] = false;
        }

        // ---
        // Map showAll to topic_show_all_link_query
        // ---

        // Initialize showAll
        $showAll = $attributes['showAll'] ?? null;

        // Check if showAll is set and is false
        if ($showAll === false) {
            // Set topic_show_all_link_query to false
            $result['topic_show_all_link_query'] = false;
        }

        // ---
        // Map showAllLabel to topic_show_all_link_text
        // ---

        // Initialize showAllLabel
        $showAllLabel = $attributes['showAllLabel'] ?? null;

        // Check if showAllLabel is set and showAll is true
        if (
            $showAllLabel !== null
            && $showAll === true
        ) {
            // Set topic_show_all_link_text to showAllLabel
            $result['topic_show_all_link_text'] = $showAllLabel;
        }

        // ---
        // Map showCount to article_count_query
        // ---

        // Initialize showCount
        $showCount = $attributes['showCount'] ?? null;

        // Check if showCount is set and is false
        if ($showCount === false) {
            // Set article_count_query to false
            $result['article_count_query'] = false;
        }

        // ---
        // Map article_query based on articlesLimit
        // ---

        // Initialize articlesLimit
        $articlesLimit = $attributes['articlesLimit'] ?? null;

        // Check if articlesLimit is set and is '0'
        if ($articlesLimit === '0') {
            // Set article_query to false
            $result['article_query'] = false;
        }

        // ---
        // Map article_all_query based on articlesLimit
        // ---

        // Check if articlesLimit is set and is '-1'
        if ($articlesLimit === '-1') {
            // Set article_all_query to true
            $result['article_all_query'] = true;
        }

        // ---
        // Map articlesLimit to article_limit
        // ---

        // Check if articlesLimit is set
        if ($articlesLimit !== null) {
            // Check if articlesLimit is '-1' or '0'
            if ($articlesLimit === '-1' || $articlesLimit === '0') {
                // Do not explicitly set for these values
            } elseif (
                \is_string($articlesLimit)
                && \preg_match('/^-\d+$/', $articlesLimit) === 1
            ) { // Check if articlesLimit is a negative number string
                // Set article_limit to '4'
                $result['article_limit'] = '4';
            } else { // Otherwise
                // Set article_limit to articlesLimit
                $result['article_limit'] = $articlesLimit;
            }
        }

        // ---
        // Set default to '4' if not explicitly set (only if article_query is not false)
        // ---

        // Check if article_limit is not set and article_query is not false
        if (
            ! isset($result['article_limit'])
            && ! isset($result['article_query'])
        ) {
            // Set article_limit to '4'
            $result['article_limit'] = '4';
        }

        // ---
        // Map topicColor to topic_color
        // ---

        // Initialize topicColor
        $topicColor = $attributes['topicColor'] ?? null;

        // Check if topicColor is set and is not empty
        if (
            $topicColor !== null
            && $topicColor !== ''
        ) {
            // Set topic_color to topicColor
            $result['topic_color'] = $topicColor;
        }

        // ---
        // Map forceTopicColor to topic_color_force_query
        // ---

        // Initialize forceTopicColor
        $forceTopicColor = $attributes['forceTopicColor'] ?? null;

        // Check if forceTopicColor is set
        if ($forceTopicColor !== null) {
            // Set topic_color_force_query to forceTopicColor
            $result['topic_color_force_query'] = (bool) $forceTopicColor;
        }

        // ---
        // Map boxItemBg to topic_box_item_bg_color
        // ---

        // Initialize boxItemBg
        $boxItemBg = $attributes['boxItemBg'] ?? null;

        // Check if boxItemBg is set and is not empty
        if (
            $boxItemBg !== null
            && $boxItemBg !== ''
        ) {
            // Set topic_box_item_bg_color to boxItemBg
            $result['topic_box_item_bg_color'] = $boxItemBg;
        }

        // ---
        // Map boxItemHoverBg to topic_box_item_bg_color_hover
        // ---

        // Initialize boxItemHoverBg
        $boxItemHoverBg = $attributes['boxItemHoverBg'] ?? null;

        // Check if boxItemHoverBg is set and is not empty
        if (
            $boxItemHoverBg !== null
            && $boxItemHoverBg !== ''
        ) {
            // Set topic_box_item_bg_color_hover to boxItemHoverBg
            $result['topic_box_item_bg_color_hover'] = $boxItemHoverBg;
        }

        // ---
        // Map countBg to article_count_bg_color
        // ---

        // Initialize countBg
        $countBg = $attributes['countBg'] ?? null;

        // Check if countBg is set and is not empty
        if (
            $countBg !== null
            && $countBg !== ''
        ) {
            // Set article_count_bg_color to countBg
            $result['article_count_bg_color'] = $countBg;
        }

        // ---
        // Map countColor to article_count_text_color
        // ---

        // Initialize countColor
        $countColor = $attributes['countColor'] ?? null;

        // Check if countColor is set and is not empty
        if (
            $countColor !== null
            && $countColor !== ''
        ) {
            // Set article_count_text_color to countColor
            $result['article_count_text_color'] = $countColor;
        }

        // ---
        // Map topic_graphic based on showTopicIcons and useTopicImage
        // ---

        // Initialize showTopicIcons
        $showTopicIcons = $attributes['showTopicIcons'] ?? null;

        // Initialize useTopicImage
        $useTopicImage = $attributes['useTopicImage'] ?? null;

        // Check if showTopicIcons is not explicitly set and useTopicImage is not explicitly set
        if (
            ! isset($attributes['showTopicIcons'])
            && ! isset($attributes['useTopicImage'])
        ) {
            // Set topic_graphic to 'icon'
            $result['topic_graphic'] = 'icon';
        } elseif ($showTopicIcons === true) { // Check if showTopicIcons is set and is true
            // Check if useTopicImage is set and is false or null
            if ($useTopicImage === false || $useTopicImage === null) {
                // Set topic_graphic to 'icon'
                $result['topic_graphic'] = 'icon';
            } elseif ($useTopicImage === true) { // Check if useTopicImage is set and is true
                // Set topic_graphic to 'featured_image'
                $result['topic_graphic'] = 'featured_image';
            } else { // Otherwise
                // Set topic_graphic to 'icon'
                $result['topic_graphic'] = 'icon';
            }
        } elseif ($showTopicIcons === false || $showTopicIcons === null) { // Check if showTopicIcons is set and is false or null
            // Set topic_graphic to 'none'
            $result['topic_graphic'] = 'none';
        } else { // Otherwise
            // Set topic_graphic to 'icon'
            $result['topic_graphic'] = 'icon';
        }

        // ---
        // Map topicIcon to topic_icon_class
        // ---

        // Initialize topicIcon
        $topicIcon = $attributes['topicIcon'] ?? null;

        // Check if topicIcon is set and is not 'fa-list-alt'
        if (
            $topicIcon !== null
            && $topicIcon !== 'fa-list-alt'
        ) {
            // Set topic_icon_class to topicIcon
            $result['topic_icon_class'] = $topicIcon;
        }

        // ---
        // Map forceTopicIcon to topic_icon_force_query
        // ---

        // Initialize forceTopicIcon
        $forceTopicIcon = $attributes['forceTopicIcon'] ?? null;

        // Check if forceTopicIcon is set and is not false
        if (
            $forceTopicIcon !== null
            && $forceTopicIcon !== false
        ) {
            // Set topic_icon_force_query to forceTopicIcon
            $result['topic_icon_force_query'] = (bool) $forceTopicIcon;
        }

        // ---
        // Map imageSize to topic_image_size
        // ---

        // Initialize imageSize
        $imageSize = $attributes['imageSize'] ?? null;

        // Check if imageSize is set and is not empty
        if (
            $imageSize !== null
            && $imageSize !== []
            && \is_array($imageSize)
        ) {
            // Set topic_image_size to imageSize
            $result['topic_image_size'] = $imageSize;
        }

        // ---
        // Map iconPaddingTop to topic_icon_padding_top
        // ---

        // Initialize iconPaddingTop
        $iconPaddingTop = $attributes['iconPaddingTop'] ?? null;

        // Check if iconPaddingTop is set and is not empty
        if (
            $iconPaddingTop !== null
            && $iconPaddingTop !== []
            && \is_array($iconPaddingTop)
        ) {
            // Set topic_icon_padding_top to iconPaddingTop
            $result['topic_icon_padding_top'] = $iconPaddingTop;
        }

        // ---
        // Map iconPaddingBottom to topic_icon_padding_bottom
        // ---

        // Initialize iconPaddingBottom
        $iconPaddingBottom = $attributes['iconPaddingBottom'] ?? null;

        // Check if iconPaddingBottom is set and is not empty
        if (
            $iconPaddingBottom !== null
            && $iconPaddingBottom !== []
            && \is_array($iconPaddingBottom)
        ) {
            // Set topic_icon_padding_bottom to iconPaddingBottom
            $result['topic_icon_padding_bottom'] = $iconPaddingBottom;
        }

        // ---
        // Map showArticleIcons to article_icon_query
        // ---

        // Initialize showArticleIcons
        $showArticleIcons = $attributes['showArticleIcons'] ?? null;

        // Check if showArticleIcons is set and is false
        if ($showArticleIcons === false) {
            // Set article_icon_query to false
            $result['article_icon_query'] = false;
        }

        // ---
        // Map articleIcon to article_icon_class
        // ---

        // Initialize articleIcon
        $articleIcon = $attributes['articleIcon'] ?? null;

        // Check if articleIcon is set and is not 'fa-book'
        if (
            $articleIcon !== null
            && $articleIcon !== 'fa-book'
        ) {
            // Set article_icon_class to articleIcon
            $result['article_icon_class'] = $articleIcon;
        }

        // Set default hide_block_heading
        $result['hide_block_heading'] = false;

        // Set default background_color
        $result['background_color'] = 'bg-auto';

        // ---
        // Set knowledge_base field from processing context if available
        // ---

        // Get knowledge_base ID from processing context
        $knowledgeBaseId = ProcessingContext::getKnowledgeBaseId();

        // Check if knowledge_base ID is available
        if ($knowledgeBaseId !== null) {
            // Set knowledge_base field to array with knowledge_base ID
            $result['knowledge_base'] = [$knowledgeBaseId];
        }

        // Clean up variables to free up memory
        unset(
            $title,
            $titleSize,
            $titleColor,
            $topics,
            $topicSource,
            $topicDynamic,
            $topicList,
            $view,
            $columns,
            $limit,
            $hideChildren,
            $showDescription,
            $showAll,
            $showAllLabel,
            $showCount,
            $articlesLimit,
            $topicColor,
            $forceTopicColor,
            $boxItemBg,
            $boxItemHoverBg,
            $countBg,
            $countColor,
            $showTopicIcons,
            $useTopicImage,
            $topicIcon,
            $forceTopicIcon,
            $imageSize,
            $iconPaddingTop,
            $iconPaddingBottom,
            $showArticleIcons,
            $articleIcon
        );

        // Return the parsed replicator set
        return $result;
    }
}
