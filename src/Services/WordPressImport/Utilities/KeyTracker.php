<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities;

use SimpleXMLElement;

/**
 * Tracks WordPress metadata keys during import, reporting unaddressed keys.
 *
 * Maintains lists of ignored, mapped, and unaddressed keys to provide visibility
 * into the metadata mapping process.
 */
class KeyTracker
{
    /**
     * List of WordPress meta keys to ignore during import.
     *
     * @var array<int|string, mixed>
     */
    // A property initializer is never reported as an executed line, so Pest runs no
    // test against a mutant here. Removing an entry that does not start with `_` by
    // hand fails its row of KeyTrackerAddressedKeysTest, which lists every such entry
    // in tests/Fixtures/key-tracker/, except the uamswp_modules_N_geo_valid entries,
    // which trackKeys() re-adds from the module-key pattern whenever they appear. One
    // that starts with `_` changes no report, since trackKeys() leaves every underscore
    // key out of it; getIgnoredMetaKeys() would list one fewer, and nothing in this
    // package or the host app reads it.
    // @pest-mutate-ignore: RemoveArrayItem
    protected $ignoredMetaKeys = [
        '__thumbnail_id',
        '_action_menu',
        '_action_menu_0_action_link',
        '_action_menu_0_action_link_icon',
        '_action_menu_0_action_link_page',
        '_action_menu_0_action_link_title',
        '_action_menu_0_action_link_url',
        '_action_menu_1_action_link',
        '_action_menu_1_action_link_icon',
        '_action_menu_1_action_link_page',
        '_action_menu_1_action_link_title',
        '_action_menu_1_action_link_url',
        '_action_menu_2_action_link',
        '_action_menu_2_action_link_icon',
        '_action_menu_2_action_link_page',
        '_action_menu_2_action_link_title',
        '_action_menu_2_action_link_url',
        '_action_menu_3_action_link',
        '_action_menu_3_action_link_icon',
        '_action_menu_3_action_link_page',
        '_action_menu_3_action_link_title',
        '_action_menu_3_action_link_url',
        '_action_menu_4_action_link',
        '_action_menu_4_action_link_icon',
        '_action_menu_4_action_link_page',
        '_action_menu_4_action_link_title',
        '_action_menu_4_action_link_url',
        '_action_menu_5_action_link',
        '_action_menu_5_action_link_icon',
        '_action_menu_5_action_link_page',
        '_action_menu_5_action_link_title',
        '_action_menu_5_action_link_url',
        '_action_menu_active',
        '_custom_footer_script',
        '_custom_header_script',
        '_edit_last',
        '_home_image_add_button',
        '_home_image_button_text',
        '_home_image_dark_text',
        '_home_image_external',
        '_home_image_external_url',
        '_home_image_internal',
        '_home_image_internal_url',
        '_home_image_mobile',
        '_home_image_title',
        '_home_page_slider',
        '_home_slides',
        '_home_slides_0_home_slide_button_text',
        '_home_slides_0_home_slide_desktop',
        '_home_slides_0_home_slide_external',
        '_home_slides_0_home_slide_external_link',
        '_home_slides_0_home_slide_internal',
        '_home_slides_0_home_slide_internal_link',
        '_home_slides_0_home_slide_mobile',
        '_home_slides_0_home_slide_text',
        '_home_slides_0_home_slide_text_color',
        '_home_slides_0_home_slide_title',
        '_home_slides_1_home_slide_button_text',
        '_home_slides_1_home_slide_desktop',
        '_home_slides_1_home_slide_external',
        '_home_slides_1_home_slide_external_link',
        '_home_slides_1_home_slide_internal',
        '_home_slides_1_home_slide_internal_link',
        '_home_slides_1_home_slide_mobile',
        '_home_slides_1_home_slide_text',
        '_home_slides_1_home_slide_text_color',
        '_home_slides_1_home_slide_title',
        '_home_slides_2_home_slide_button_text',
        '_home_slides_2_home_slide_desktop',
        '_home_slides_2_home_slide_external',
        '_home_slides_2_home_slide_external_link',
        '_home_slides_2_home_slide_internal',
        '_home_slides_2_home_slide_internal_link',
        '_home_slides_2_home_slide_mobile',
        '_home_slides_2_home_slide_text',
        '_home_slides_2_home_slide_text_color',
        '_home_slides_2_home_slide_title',
        '_home_slides_3_home_slide_button_text',
        '_home_slides_3_home_slide_desktop',
        '_home_slides_3_home_slide_external',
        '_home_slides_3_home_slide_external_link',
        '_home_slides_3_home_slide_internal',
        '_home_slides_3_home_slide_internal_link',
        '_home_slides_3_home_slide_mobile',
        '_home_slides_3_home_slide_text',
        '_home_slides_3_home_slide_text_color',
        '_home_slides_3_home_slide_title',
        '_home_slides_4_home_slide_button_text',
        '_home_slides_4_home_slide_desktop',
        '_home_slides_4_home_slide_external',
        '_home_slides_4_home_slide_external_link',
        '_home_slides_4_home_slide_internal',
        '_home_slides_4_home_slide_internal_link',
        '_home_slides_4_home_slide_mobile',
        '_home_slides_4_home_slide_text',
        '_home_slides_4_home_slide_text_color',
        '_home_slides_4_home_slide_title',
        '_home_slides_5_home_slide_button_text',
        '_home_slides_5_home_slide_desktop',
        '_home_slides_5_home_slide_external',
        '_home_slides_5_home_slide_external_link',
        '_home_slides_5_home_slide_internal',
        '_home_slides_5_home_slide_internal_link',
        '_home_slides_5_home_slide_mobile',
        '_home_slides_5_home_slide_text',
        '_home_slides_5_home_slide_text_color',
        '_home_slides_5_home_slide_title',
        '_links_to',
        '_links_to_target',
        '_oembed_d8b34608f3886644c67917973e4770c3',
        '_page_cover_image',
        '_page_description',
        '_page_hero',
        '_page_hero_geo_valid',
        '_page_hero_hero',
        '_page_hide_breadcrumbs',
        '_page_hide_child_menu',
        '_page_hide_from_menu',
        '_page_nav_alt_name',
        '_page_subsection',
        '_page_title_options',
        '_post_hide_author',
        '_post_title_options',
        '_ps_lastname',
        '_psdeptweb',
        '_psdeptwebanchor',
        '_psemail',
        '_psfax',
        '_psfreehtml',
        '_pslocation',
        '_pspersonal',
        '_pspersonalanchor',
        '_psposition',
        '_pssortpriority',
        '_pssorttext',
        '_pstel',
        '_pstriweb',
        '_pstriwebanchor',
        '_pstwitter',
        '_psuser',
        '_psyoutube',
        '_relevanssi_related_posts',
        '_seo_canonical_url',
        '_seo_custom_redirect_url',
        '_seo_document_title',
        '_seo_meta_description',
        '_seo_meta_keywords',
        '_seopress_analysis_data',
        '_seopress_analysis_target_kw',
        '_seopress_redirections_logged_status',
        '_seopress_redirections_type',
        '_seopress_redirections_value',
        '_seopress_robots_breadcrumbs',
        '_seopress_robots_canonical',
        '_seopress_robots_primary_cat',
        '_seopress_social_fb_desc',
        '_seopress_social_fb_img',
        '_seopress_social_fb_img_attachment_id',
        '_seopress_social_fb_img_height',
        '_seopress_social_fb_img_width',
        '_seopress_social_fb_title',
        '_seopress_social_twitter_desc',
        '_seopress_social_twitter_img',
        '_seopress_social_twitter_img_attachment_id',
        '_seopress_social_twitter_img_height',
        '_seopress_social_twitter_img_width',
        '_seopress_social_twitter_title',
        '_seopress_titles_desc',
        '_seopress_titles_title',
        '_seopress_video',
        '_show_image_single',
        '_uamspeople_page_template',
        '_uamswp_modules',
        '_uamswp_modules_0_geo_valid',
        '_uamswp_modules_1_geo_valid',
        '_uamswp_modules_2_geo_valid',
        '_uamswp_modules_3_geo_valid',
        '_uamswp_modules_4_geo_valid',
        '_uamswp_modules_5_geo_valid',
        '_uamswp_modules_6_geo_valid',
        '_wxr_import_has_attachment_refs',
        '_wxr_import_user_slug',
        'action_menu',
        'action_menu_0_action_link',
        'action_menu_0_action_link_icon',
        'action_menu_0_action_link_page',
        'action_menu_0_action_link_title',
        'action_menu_0_action_link_url',
        'action_menu_1_action_link',
        'action_menu_1_action_link_icon',
        'action_menu_1_action_link_page',
        'action_menu_1_action_link_title',
        'action_menu_1_action_link_url',
        'action_menu_2_action_link',
        'action_menu_2_action_link_icon',
        'action_menu_2_action_link_page',
        'action_menu_2_action_link_title',
        'action_menu_2_action_link_url',
        'action_menu_3_action_link',
        'action_menu_3_action_link_icon',
        'action_menu_3_action_link_page',
        'action_menu_3_action_link_title',
        'action_menu_3_action_link_url',
        'action_menu_4_action_link',
        'action_menu_4_action_link_icon',
        'action_menu_4_action_link_page',
        'action_menu_4_action_link_title',
        'action_menu_4_action_link_url',
        'action_menu_5_action_link',
        'action_menu_5_action_link_icon',
        'action_menu_5_action_link_page',
        'action_menu_5_action_link_title',
        'action_menu_5_action_link_url',
        'action_menu_active',
        'avada_post_views_count',
        'bbconv_not_converted',
        'breadcrumb',
        'criticalcss_cache',
        'custom_footer_script',
        'custom_header_script',
        'dsq_thread_id',
        'eg_custom_meta_216',
        'eg_html5_ratio',
        'eg_settings_custom_meta_element',
        'eg_settings_custom_meta_setting',
        'eg_settings_custom_meta_skin',
        'eg_settings_custom_meta_style',
        'eg_soundcloud_ratio',
        'eg_sources_html5_mp4',
        'eg_sources_html5_ogv',
        'eg_sources_html5_webm',
        'eg_sources_iframe',
        'eg_sources_image',
        'eg_sources_soundcloud',
        'eg_sources_vimeo',
        'eg_sources_wistia',
        'eg_sources_youtube',
        'eg_vimeo_ratio',
        'eg_wistia_ratio',
        'eg_youtube_ratio',
        'enclosure',
        'event_attendance_mode',
        'event_begin',
        'event_color',
        'event_end',
        'event_status',
        'footer',
        'footnotes',
        'fusion_builder_content',
        'fusion_builder_content_backup',
        'fusion_builder_converted',
        'fusion_builder_status',
        'header_title_bar',
        'header_transparency',
        'hide_title',
        'home_image_add_button',
        'home_image_button_text',
        'home_image_dark_text',
        'home_image_external',
        'home_image_external_url',
        'home_image_internal',
        'home_image_internal_url',
        'home_image_mobile',
        'home_image_title',
        'home_page_slider',
        'home_remove_breadcrumbs',
        'home_slides',
        'home_slides_0_home_slide_button_text',
        'home_slides_0_home_slide_desktop',
        'home_slides_0_home_slide_external',
        'home_slides_0_home_slide_external_link',
        'home_slides_0_home_slide_internal',
        'home_slides_0_home_slide_internal_link',
        'home_slides_0_home_slide_mobile',
        'home_slides_0_home_slide_text',
        'home_slides_0_home_slide_text_color',
        'home_slides_0_home_slide_title',
        'home_slides_1_home_slide_button_text',
        'home_slides_1_home_slide_desktop',
        'home_slides_1_home_slide_external',
        'home_slides_1_home_slide_external_link',
        'home_slides_1_home_slide_internal',
        'home_slides_1_home_slide_internal_link',
        'home_slides_1_home_slide_mobile',
        'home_slides_1_home_slide_text',
        'home_slides_1_home_slide_text_color',
        'home_slides_1_home_slide_title',
        'home_slides_2_home_slide_button_text',
        'home_slides_2_home_slide_desktop',
        'home_slides_2_home_slide_external',
        'home_slides_2_home_slide_external_link',
        'home_slides_2_home_slide_internal',
        'home_slides_2_home_slide_internal_link',
        'home_slides_2_home_slide_mobile',
        'home_slides_2_home_slide_text',
        'home_slides_2_home_slide_text_color',
        'home_slides_2_home_slide_title',
        'home_slides_3_home_slide_button_text',
        'home_slides_3_home_slide_desktop',
        'home_slides_3_home_slide_external',
        'home_slides_3_home_slide_external_link',
        'home_slides_3_home_slide_internal',
        'home_slides_3_home_slide_internal_link',
        'home_slides_3_home_slide_mobile',
        'home_slides_3_home_slide_text',
        'home_slides_3_home_slide_text_color',
        'home_slides_3_home_slide_title',
        'home_slides_4_home_slide_button_text',
        'home_slides_4_home_slide_desktop',
        'home_slides_4_home_slide_external',
        'home_slides_4_home_slide_external_link',
        'home_slides_4_home_slide_internal',
        'home_slides_4_home_slide_internal_link',
        'home_slides_4_home_slide_mobile',
        'home_slides_4_home_slide_text',
        'home_slides_4_home_slide_text_color',
        'home_slides_4_home_slide_title',
        'home_slides_5_home_slide_button_text',
        'home_slides_5_home_slide_desktop',
        'home_slides_5_home_slide_external',
        'home_slides_5_home_slide_external_link',
        'home_slides_5_home_slide_internal',
        'home_slides_5_home_slide_internal_link',
        'home_slides_5_home_slide_mobile',
        'home_slides_5_home_slide_text',
        'home_slides_5_home_slide_text_color',
        'home_slides_5_home_slide_title',
        'kd_featured-image-0_page_id',
        'kd_featured-image-0_post_id',
        'kd_featured-image-1_page_id',
        'kd_featured-image-1_post_id',
        'kd_featured-image-2_page_id',
        'kd_featured-image-2_post_id',
        'kd_featured-image-3_page_id',
        'kd_featured-image-3_post_id',
        'kd_featured-image-4_page_id',
        'kd_featured-image-4_post_id',
        'kd_featured-image-5_page_id',
        'kd_featured-image-5_post_id',
        'layout',
        'layout_footer',
        'layout_header',
        'media_url',
        'nbt_block_post',
        'nested_pages_status',
        'np_link_target',
        'np_nav_css_classes',
        'np_nav_status',
        'np_nav_title',
        'np_title_attribute',
        'otw_grid_manager_content',
        'page_hero',
        'page_hero_geo_valid',
        'page_hero_hero',
        'parent',
        'physician_full_name',
        'post_cover_image',
        'post_custom_link',
        'post_title_options',
        'pricat_get_categories',
        'pyre_author_info',
        'pyre_avada_rev_styles',
        'pyre_demo_slider',
        'pyre_display_copyright',
        'pyre_display_footer',
        'pyre_display_header',
        'pyre_displayed_menu',
        'pyre_elasticslider',
        'pyre_fallback',
        'pyre_fimg_height',
        'pyre_fimg_width',
        'pyre_footer_100_width',
        'pyre_header_100_width',
        'pyre_header_bg',
        'pyre_header_bg_color',
        'pyre_header_bg_full',
        'pyre_header_bg_opacity',
        'pyre_header_bg_repeat',
        'pyre_hundredp_padding',
        'pyre_image_rollover_icons',
        'pyre_link_icon_url',
        'pyre_main_bottom_padding',
        'pyre_main_top_padding',
        'pyre_page_bg',
        'pyre_page_bg_color',
        'pyre_page_bg_full',
        'pyre_page_bg_layout',
        'pyre_page_bg_repeat',
        'pyre_page_title',
        'pyre_page_title_100_width',
        'pyre_page_title_bar_bg',
        'pyre_page_title_bar_bg_color',
        'pyre_page_title_bar_bg_full',
        'pyre_page_title_bar_bg_retina',
        'pyre_page_title_bar_borders_color',
        'pyre_page_title_bg_parallax',
        'pyre_page_title_breadcrumbs_search_bar',
        'pyre_page_title_custom_subheader',
        'pyre_page_title_custom_subheader_text_size',
        'pyre_page_title_custom_text',
        'pyre_page_title_font_color',
        'pyre_page_title_height',
        'pyre_page_title_line_height',
        'pyre_page_title_mobile_height',
        'pyre_page_title_text',
        'pyre_page_title_text_alignment',
        'pyre_page_title_text_size',
        'pyre_portfolio_column_spacing',
        'pyre_portfolio_content_length',
        'pyre_portfolio_excerpt',
        'pyre_portfolio_featured_image_size',
        'pyre_portfolio_filters',
        'pyre_portfolio_text_layout',
        'pyre_portfolio_width_100',
        'pyre_post_comments',
        'pyre_post_links_target',
        'pyre_post_meta',
        'pyre_post_pagination',
        'pyre_related_posts',
        'pyre_revslider',
        'pyre_share_box',
        'pyre_show_first_featured_image',
        'pyre_sidebar_bg_color',
        'pyre_sidebar_position',
        'pyre_slider',
        'pyre_slider_position',
        'pyre_slider_type',
        'pyre_video',
        'pyre_wide_page_bg',
        'pyre_wide_page_bg_color',
        'pyre_wide_page_bg_full',
        'pyre_wide_page_bg_repeat',
        'pyre_wooslider',
        'restrict_roles',
        'sbg_selected_sidebar',
        'sbg_selected_sidebar_2',
        'sbg_selected_sidebar_2_replacement',
        'sbg_selected_sidebar_replacement',
        'seo_canonical_url',
        'seo_custom_redirect_url',
        'seo_document_title',
        'seo_meta_description',
        'seo_meta_keywords',
        'show_image_single',
        'sidebar',
        'skin',
        'slide_template',
        'st_cached',
        'st_cached_time',
        'toggle_page_title',
        'uamswp_modules',
        'uamswp_modules_0_geo_valid',
        'uamswp_modules_1_geo_valid',
        'uamswp_modules_2_geo_valid',
        'uamswp_modules_3_geo_valid',
        'uamswp_modules_4_geo_valid',
        'uamswp_modules_5_geo_valid',
        'uamswp_modules_6_geo_valid',
        'wdsi-admin',
        'wdsi-do_not_show',
        'wdsi-message_override',
        'wdsi-type',
        'wdsi_advanced',
        'wdsi_appearance',
        'wdsi_behavior',
        'wdsi_closing',
        'wdsi_color_scheme',
        'wdsi_css',
        'wdsi_do_not_show',
        'wdsi_mailchimp',
        'wdsi_message_id',
        'wdsi_message_override',
        'wdsi_options_page',
        'wdsi_position',
        'wdsi_services',
        'wdsi_show_after',
        'wdsi_show_for',
        'wdsi_width',
        'wp_last_modified_info',
        'wpautop',
        'wplmi_shortcode',

        // ---
        // Retired legacy theme/plugin postmeta (wordpress-importer-archive#314).
        //
        // Audited in the UAMS Caregiving "Teacher's Section" export: both keys
        // appear on every page item but carry no migratable content, so they
        // are ignored (discarded) rather than mapped to a Statamic field. If a
        // non-empty value for either key turns up in a future export, unserialize
        // /inspect it and map the meaningful sub-keys instead of blanket-ignoring.
        // ---

        // `pgopts` is a serialized PHP page-options blob (`a:51:{…}`) from a
        // retired theme; every audited value unserializes to empties/theme
        // defaults ("nodata", "none", "random", …). We discard the whole blob
        // rather than store the serialized string opaquely — it is understood as
        // a serialized structure, not text.
        'pgopts',

        // `Order` is a legacy redirect/ordering postmeta; every audited value is
        // an empty CDATA node, so there is nothing to migrate.
        'Order',
    ];

    /**
     * Top-level WordPress XML keys handled by entry creators or parser.
     *
     * @var array<int|string, mixed>
     */
    // Annotated for the same reason as $ignoredMetaKeys, against the same test.
    // trackKeys() collects only unprefixed and `wp:` children, so removing the
    // content:, dc: or excerpt: entry changes nothing for an export that declares its
    // namespace prefixes, as WXR does.
    // @pest-mutate-ignore: RemoveArrayItem
    protected $topLevelXmlKeys = [
        'category',
        'content:encoded',
        'dc:creator',
        'description',
        'excerpt:encoded',
        'guid',
        'link',
        'pubDate',
        'title',
        'wp:comment', // Ignored
        'wp:comment_status',
        'wp:is_sticky',
        'wp:menu_order',
        'wp:ping_status',
        'wp:post_date',
        'wp:post_date_gmt',
        'wp:post_id',
        'wp:post_modified',
        'wp:post_modified_gmt',
        'wp:post_name',
        'wp:post_parent',
        'wp:post_password',
        'wp:post_type',
        'wp:status',
    ];

    /**
     * Metadata keys mapped to Statamic fields.
     *
     * @var array<int|string, mixed>
     */
    protected $mappedMetaKeys = [];

    /**
     * Array to collect unaddressed XML keys for reporting.
     *
     * @var array<int|string, mixed>
     */
    protected $unaddressedKeys = [];

    /**
     * Track WordPress metadata keys during import.
     *
     * Updates ignored and mapped key lists based on parsing and mapping results,
     * and identifies unaddressed keys from the XML.
     *
     * @param  SimpleXMLElement  $page  The WordPress page, post, or uamspeople item XML element
     * @param  array<int|string, mixed>  $metaKeys  All meta keys extracted from postmeta nodes
     * @param  array<int|string, mixed>  $mappedKeys  Keys mapped to Statamic fields
     */
    public function trackKeys(
        SimpleXMLElement $page,
        array $metaKeys,
        array $mappedKeys
    ): void {
        // Update mapped keys
        $this->mappedMetaKeys = \array_merge(
            $this->mappedMetaKeys,
            $mappedKeys
        );

        // Pre-process to ignore ACF field keys for hero slides and modules
        foreach (XpathHelper::xpathElements($page, 'wp:postmeta') as $meta) {
            // Extract the meta key from WordPress postmeta
            $key = XpathHelper::getStringValue(
                $meta,
                'wp:meta_key'
            );

            // ---
            // Check if key matches hero slide field pattern and add to ignored
            // list if not already present
            // ---
            // Every key the pattern admits starts with a letter or `_`, so a loose match
            // equals a strict one.
            // @pest-mutate-ignore: TrueToFalse
            if (
                \preg_match(
                    '/^_page_hero_hero_(\d+)_hero_(.+)$/',
                    $key
                ) === 1
                && ! \in_array(
                    $key,
                    $this->ignoredMetaKeys,
                    true
                )
            ) {
                // Add matching hero slide field key to ignored list
                $this->ignoredMetaKeys[] = $key;
            }

            // ---
            // Check if key matches modules field pattern and add to ignored
            // list if not already present
            // ---
            // Every key the pattern admits starts with a letter or `_`, so a loose match
            // equals a strict one.
            // @pest-mutate-ignore: TrueToFalse
            if (
                \preg_match(
                    '/^uamswp_modules_(\d+)_(.+)$/',
                    $key
                ) === 1
                && ! \in_array(
                    $key,
                    $this->ignoredMetaKeys,
                    true
                )
            ) {
                // Add matching modules field key to ignored list
                $this->ignoredMetaKeys[] = $key;
            }

            // ---
            // Check if key matches ACF modules field pattern and add to
            // ignored list if not already present
            // ---
            // Every key the pattern admits starts with a letter or `_`, so a loose match
            // equals a strict one.
            // @pest-mutate-ignore: TrueToFalse
            if (
                \preg_match(
                    '/^_uamswp_modules_(\d+)_(.+)$/',
                    $key
                ) === 1
                && ! \in_array(
                    $key,
                    $this->ignoredMetaKeys,
                    true
                )
            ) {
                // Add matching ACF modules field key to ignored list
                $this->ignoredMetaKeys[] = $key;
            }
        }

        // Collect top-level keys
        $topLevelKeys = [];
        foreach ($page->children() as $child) {
            $topLevelKeys[] = $child->getName();
        }

        foreach ($page->children('wp', true) as $wpChild) {
            $key = "wp:{$wpChild->getName()}";
            if ($key !== 'wp:postmeta') {
                $topLevelKeys[] = $key;
            }
        }

        // Identify unaddressed keys
        // Re-index, so a meta key keyed by name cannot overwrite an earlier report below
        $allKeys = \array_values(
            \array_filter(
                \array_merge(
                    $topLevelKeys,
                    $metaKeys
                ),
                \is_string(...)
            )
        );
        $addressedKeys = \array_filter(
            \array_merge(
                $this->topLevelXmlKeys,
                $this->mappedMetaKeys,
                $this->ignoredMetaKeys
            ),
            \is_string(...)
        );
        $this->unaddressedKeys = \array_merge(
            $this->unaddressedKeys,
            \array_filter(
                \array_diff(
                    $allKeys,
                    $addressedKeys
                ),
                fn (string $key): bool => ! \str_starts_with(
                    $key,
                    '_'
                )
            )
        );
    }

    /**
     * Retrieve unaddressed XML keys collected during tracking.
     *
     * @return array<int|string, mixed> Array of unaddressed keys
     */
    public function getUnaddressedKeys(): array
    {
        return $this->unaddressedKeys;
    }

    /**
     * Retrieve ignored meta keys.
     *
     * @return array<int|string, mixed> Array of ignored keys
     */
    public function getIgnoredMetaKeys(): array
    {
        return $this->ignoredMetaKeys;
    }
}
