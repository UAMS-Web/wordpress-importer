<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Tests\Feature;

use Mockery\CompositeExpectation;
use Mockery\Expectation;
use Mockery\MockInterface;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\ExpectationFailedException;
use ReflectionClass;
use Statamic\Entries\Entry;
use Statamic\Facades\Collection;
use Statamic\Facades\Site;
use Statamic\Facades\Term as TermFacade;
use Statamic\Facades\YAML;
use Statamic\Stache\Query\EntryQueryBuilder;
use Statamic\Stache\Repositories\CollectionTreeRepository;
use Statamic\Stache\Stache;
use Statamic\Taxonomies\Term;
use Symfony\Component\Console\Output\NullOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators\LogoListItemEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators\PageEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators\PeopleEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators\PostEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\EntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Structures\TreeBuilder;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\CollectionStructureManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentRoot;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\SiteManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\SiteResolver;
use UAMSWeb\WordPressImporter\Tests\Support\ContentSubmoduleDiff;
use UAMSWeb\WordPressImporter\Tests\Support\ContentSubmoduleNotices;
use UAMSWeb\WordPressImporter\Tests\Support\ContentSubmoduleState;
use UAMSWeb\WordPressImporter\Tests\Support\IsolatedStateScratch;
use UAMSWeb\WordPressImporter\Tests\Support\TemporaryFileRegistry;

/**
 * Trait for WordPress import test utilities.
 *
 * Provides reusable methods for creating WordPress export XML content,
 * form plugin JSON data, and test file management in unit tests. This trait
 * centralizes test data generation and cleanup logic to avoid duplication
 * across test classes.
 */
trait WordPressImportTestTrait
{
    /**
     * Cached entry data for XML-based tests.
     *
     * One static array on {@see WordPressImportTestCase}, shared by every test
     * file that extends it, so each key is prefixed with the test file's own
     * class. That keeps one file from reading entry data another file built
     * under different config, which is what a per-class copy of the trait gave
     * before the base class existed.
     *
     * @var array<string, array<int|string, mixed>> Keyed by test class and cache key
     */
    protected static array $cachedEntryData = [];

    /**
     * Normalize mixed values to array shape for safe iteration and offsets.
     *
     * @param  mixed  $value  The candidate array value
     * @return array<int|string, mixed> The normalized array value
     */
    protected static function asArray(
        mixed $value
    ): array {
        if (! \is_array($value)) {
            return [];
        }

        /** @var array<int|string, mixed> $value */
        return $value;
    }

    /**
     * Normalize mixed values to strings for safe string-only operations.
     *
     * @param  mixed  $value  The candidate string value
     * @return ?string The normalized string value
     */
    protected static function stringValue(
        mixed $value
    ): ?string {
        if (! \is_string($value)) {
            return null;
        }

        return $value;
    }

    /**
     * Default configuration for flexible page item generation.
     *
     * @return array<string, mixed>
     */
    private function pageConfigDefaults(): array
    {
        return [
            'post_id' => 123,
            'title' => 'Test Page',
            'slug' => 'test-page',
            'content_type' => 'gutenberg',
            'form_plugin' => null,
            'form_type' => null,
            'form_id' => '1',
            'form_attributes' => '{"formId":"1","title":false,"description":false}',
            'form_shortcode' => '[gravityform id="1"]',
            'has_acf_blocks' => false,
            'post_type' => 'page',
            'status' => 'publish',
            'date' => '2024-12-09 16:04:12',
            'date_gmt' => '0000-00-00 00:00:00',
            'modified' => '2024-12-09 16:04:12',
            'modified_gmt' => '2024-12-09 22:04:12',
        ];
    }

    /**
     * Merge page configuration options with defaults.
     *
     * @param  array<string, mixed>  $options  Configuration options for the page item
     * @return array<string, mixed> The merged page configuration
     */
    private function mergePageConfig(
        array $options = []
    ): array {
        /** @var array<string, mixed> */
        return \array_merge(
            $this->pageConfigDefaults(),
            $options
        );
    }

    /**
     * Read a string value from page configuration.
     *
     * An `int` is accepted and cast, because a fixture helper naturally writes an id
     * as a number — `['post_id' => 456]`. Resolving that through `stringValue()`
     * alone returned `null` and handed back the default, so the fixture emitted an id
     * it did not name, and any assertion on the named id was checking a value the XML
     * never carried (wordpress-importer-archive#531).
     *
     * Anything that is neither a string nor an int still falls back to the default.
     * Stringifying the rest would trade one silent substitution for another: `false`
     * would become `''`, which reads as a legitimately empty element rather than as
     * the mistake it is.
     *
     * @param  array<string, mixed>  $config  The page configuration
     * @param  string  $key  The configuration key
     * @param  string  $default  The default when the value is neither a string nor an int
     * @return string The configuration string value
     */
    private function configString(
        array $config,
        string $key,
        string $default = ''
    ): string {
        // Read the raw configured value, if the key is present at all
        $value = $config[$key] ?? null;

        // Accept an id written as a number rather than as a string
        if (\is_int($value)) {
            return (string) $value;
        }

        return self::stringValue($value) ?? $default;
    }

    /**
     * Read a nullable string value from page configuration.
     *
     * @param  array<string, mixed>  $config  The page configuration
     * @param  string  $key  The configuration key
     * @return ?string The configuration string value, or null when absent
     */
    private function configNullableString(
        array $config,
        string $key
    ): ?string {
        return self::stringValue($config[$key] ?? null);
    }

    /**
     * Read a boolean value from page configuration.
     *
     * @param  array<string, mixed>  $config  The page configuration
     * @param  string  $key  The configuration key
     * @return bool The configuration boolean value
     */
    private function configBool(
        array $config,
        string $key
    ): bool {
        return (bool) ($config[$key] ?? false);
    }

    /**
     * Build entry data array from a Statamic entry instance.
     *
     * @return array<int|string, mixed> Entry data with id, slug, and title keys
     */
    private function entryDataFromEntry(
        Entry $entry
    ): array {
        $data = $entry->data();
        if (\is_object($data) && \method_exists($data, 'toArray')) {
            /** @var array<string, mixed> $entryData */
            $entryData = $data->toArray();
        } else {
            $entryData = self::asArray($data);
        }

        $entryData['id'] = $entry->id();
        $entryData['slug'] = $entry->slug();
        $entryData['title'] = $entry->value('title');

        return $entryData;
    }

    /**
     * Normalize glob() results to a string list for cleanup helpers.
     *
     * @param  string  $pattern  The glob pattern to match
     * @return array<int, string> Matched file paths
     */
    protected static function globMatches(
        string $pattern
    ): array {
        $matches = glob($pattern);

        if ($matches === false) {
            return [];
        }

        /** @var array<int, string> $matches */
        return $matches;
    }

    /**
     * Parse a YAML file into a normalized array shape.
     *
     * @param  string  $path  Absolute path to the YAML file
     * @return array<int|string, mixed> Parsed YAML contents
     */
    private static function parseYamlFile(
        string $path
    ): array {
        $contents = file_get_contents($path);

        if ($contents === false) {
            return [];
        }

        $parsed = \Symfony\Component\Yaml\Yaml::parse($contents);

        return self::asArray($parsed);
    }

    /**
     * Find an entry in a collection by site and slug.
     *
     * @param  \Statamic\Entries\Collection  $collection  The Statamic collection
     * @param  string  $site  The site handle
     * @param  string  $slug  The entry slug
     * @return ?Entry The matching entry, or null when not found
     */
    protected static function findEntryBySlug(
        \Statamic\Entries\Collection $collection,
        string $site,
        string $slug
    ): ?Entry {
        $query = $collection->queryEntries();
        if (! $query instanceof EntryQueryBuilder) {
            return null;
        }

        $siteQuery = $query->where(
            'site',
            $site
        );
        if (! $siteQuery instanceof EntryQueryBuilder) {
            return null;
        }

        $slugQuery = $siteQuery->where(
            'slug',
            $slug
        );
        if (! $slugQuery instanceof EntryQueryBuilder) {
            return null;
        }

        $entry = $slugQuery->first();

        return $entry instanceof Entry ? $entry : null;
    }

    /**
     * Get the XML header for WordPress export format.
     *
     * @return string The XML header
     */
    protected function getXmlHeader(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>
<!-- This is a WordPress eXtended RSS file generated by WordPress as an export of your site. -->
<!-- It contains information about your site\'s posts, pages, comments, categories, and other
content. -->
<!-- You may use this file to transfer that content from one site to another. -->
<!-- This file is not intended to serve as a complete backup of your site. -->

<!-- To import this information into a WordPress site follow these steps: -->
<!-- 1. Log in to that site as an administrator. -->
<!-- 2. Go to Tools: Import in the WordPress admin panel. -->
<!-- 3. Install the "WordPress" importer from the list. -->
<!-- 4. Activate & Run Importer. -->
<!-- 5. Upload this file using the form provided on that page. -->
<!-- 6. You will first be asked to map the authors in this export file to users -->
<!--    on the site. For each author, you may choose to map to an -->
<!--    existing user on the site or to create a new user. -->
<!-- 7. WordPress will then import each of the posts, pages, comments, categories, etc. -->
<!--    contained in this file into your site. -->

<!-- generator="WordPress/6.7.3" created="2025-09-09 13:40" -->
<rss version="2.0"
    xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"
    xmlns:content="http://purl.org/rss/1.0/modules/content/"
    xmlns:wfw="http://wellformedweb.org/CommentAPI/"
    xmlns:dc="http://purl.org/dc/elements/1.1/"
    xmlns:wp="http://wordpress.org/export/1.2/"
>

    <channel>
        <title>Web Services</title>
        <link>https://supplychain.uams.edu/</link>
        <description>UAMS</description>
        <pubDate>Tue, 09 Sep 2025 13:40:52 +0000</pubDate>
        <language>en-US</language>
        <wp:wxr_version>1.2</wp:wxr_version>
        <wp:base_site_url>https://uamsdev.com/</wp:base_site_url>
        <wp:base_blog_url>https://supplychain.uams.edu/</wp:base_blog_url>

        <wp:author>
            <wp:author_id>1</wp:author_id>
            <wp:author_login><![CDATA[uamsonline]]></wp:author_login>
            <wp:author_email><![CDATA[uamsonline@uams.edu]]></wp:author_email>
            <wp:author_display_name><![CDATA[uamsonline]]></wp:author_display_name>
            <wp:author_first_name><![CDATA[]]></wp:author_first_name>
            <wp:author_last_name><![CDATA[]]></wp:author_last_name>
        </wp:author>

        <generator>https://wordpress.org/?v=6.7.3</generator>';
    }

    /**
     * Get the XML footer for WordPress export format.
     *
     * @return string The XML footer
     */
    protected function getXmlFooter(): string
    {
        return '    </channel>
</rss>';
    }

    /**
     * Generate a flexible page item with configurable content and properties.
     *
     * Pass `thumbnail_id` to attach a WordPress featured image; the id must match
     * the `wp:post_id` of an attachment item in the same export. Omitted by
     * default, so the emitted XML is unchanged for every caller that does not ask.
     *
     * @param  array<string, mixed>  $options  Configuration options for the page item
     * @return string The page item XML
     */
    protected function generatePageItem(
        array $options = []
    ): string {
        $config = $this->mergePageConfig($options);

        // Generate content based on content type and form plugin
        $content = $this->generatePageContent($config);

        // Generate title and slug based on configuration
        $title = $this->generatePageTitle($config);
        $slug = $this->generatePageSlug($config);
        $postId = $this->configString(
            $config,
            'post_id',
            '123'
        );
        $date = $this->configString(
            $config,
            'date'
        );
        $dateGmt = $this->configString(
            $config,
            'date_gmt'
        );
        $modified = $this->configString(
            $config,
            'modified'
        );
        $modifiedGmt = $this->configString(
            $config,
            'modified_gmt'
        );
        $status = $this->configString(
            $config,
            'status',
            'publish'
        );
        $postType = $this->configString(
            $config,
            'post_type',
            'page'
        );

        // ---
        // Emit the featured-image postmeta only when a caller asks for one, so every
        // existing caller's page XML stays byte-identical to before — the same
        // opt-in shape the post item's featured-image credit uses (wordpress-importer-archive#433).
        // ---
        $thumbnailId = $this->configString(
            $config,
            'thumbnail_id'
        );

        // Build the `_thumbnail_id` postmeta block, or nothing when unconfigured
        $thumbnailPostmeta = $thumbnailId === ''
            ? ''
            : '
            <wp:postmeta>
                <wp:meta_key><![CDATA[_thumbnail_id]]></wp:meta_key>
                <wp:meta_value><![CDATA['.$thumbnailId.']]></wp:meta_value>
            </wp:postmeta>';

        return '        <item>
            <title><![CDATA['.$title.']]></title>
            <link>https://supplychain.uams.edu/'.$slug.'/</link>
            <pubDate></pubDate>
            <dc:creator><![CDATA[uamsonline]]></dc:creator>
            <guid isPermaLink="false">https://supplychain.uams.edu/?page_id='.$postId.'</guid>
            <description></description>
            <content:encoded><![CDATA['.$content.']]></content:encoded>
            <excerpt:encoded><![CDATA[]]></excerpt:encoded>
            <wp:post_id>'.$postId.'</wp:post_id>
            <wp:post_date><![CDATA['.$date.']]></wp:post_date>
            <wp:post_date_gmt><![CDATA['.$dateGmt.']]></wp:post_date_gmt>
            <wp:post_modified><![CDATA['.$modified.']]></wp:post_modified>
            <wp:post_modified_gmt><![CDATA['.$modifiedGmt.']]></wp:post_modified_gmt>
            <wp:comment_status><![CDATA[closed]]></wp:comment_status>
            <wp:ping_status><![CDATA[closed]]></wp:ping_status>
            <wp:post_name><![CDATA['.$slug.']]></wp:post_name>
            <wp:status><![CDATA['.$status.']]></wp:status>
            <wp:post_parent>0</wp:post_parent>
            <wp:menu_order>0</wp:menu_order>
            <wp:post_type><![CDATA['.$postType.']]></wp:post_type>
            <wp:post_password><![CDATA[]]></wp:post_password>
            <wp:is_sticky>0</wp:is_sticky>'.$thumbnailPostmeta.'
        </item>';
    }

    /**
     * Generate page content based on configuration.
     *
     * @param  array<string, mixed>  $config  Configuration options
     * @return string The page content
     */
    private function generatePageContent(
        array $config
    ): string {
        // If custom content is provided, use it directly
        $customContent = $this->configNullableString(
            $config,
            'content'
        );
        if ($customContent !== null && $customContent !== '') {
            return $customContent;
        }

        $content = '';

        // Add ACF blocks if requested
        if ($this->configBool($config, 'has_acf_blocks')) {
            $content .= '<!-- wp:acf/hero {"id":"block_123","name":"acf/hero","data":{"hero_heading":"Test Hero","hero_body":"Test hero content"},"align":"","mode":"preview"} /-->

';
        }

        // For Classic block content, don't wrap in Gutenberg block comments
        if ($this->configString($config, 'content_type') === 'classic') {
            $content .= $this->generateMainContent($config);

            return $content;
        }

        // Add introductory paragraph (only for non-form-plugin content)
        if (
            $this->configNullableString($config, 'form_plugin') === null
            || $this->configNullableString($config, 'form_type') === null
        ) {
            $introText = $this->generateIntroText($config);
            if ($introText !== '') {
                $content .= '<!-- wp:paragraph -->
<p>'.$introText.'</p>
<!-- /wp:paragraph -->

';
            }
        }

        // Add main content (includes form content if specified)
        $content .= $this->generateMainContent($config);

        // Add closing paragraph
        $content .= '<!-- wp:paragraph -->
<p>End of content.</p>
<!-- /wp:paragraph -->';

        return $content;
    }

    /**
     * Generate introductory text based on configuration.
     *
     * @param  array<string, mixed>  $config  Configuration options
     * @return string The introductory text
     */
    private function generateIntroText(
        array $config
    ): string {
        $formPlugin = $this->configNullableString(
            $config,
            'form_plugin'
        );
        $formType = $this->configNullableString(
            $config,
            'form_type'
        );

        if (
            $formPlugin !== null
            && $formType !== null
        ) {
            $pluginName = \ucfirst(
                \str_replace(
                    '_',
                    ' ',
                    $formPlugin
                )
            );
            $formTypeLabel = $formType === 'block' ? 'block' : 'shortcode';

            return "This page contains a {$pluginName} {$formTypeLabel}.";
        }

        switch ($this->configString($config, 'content_type')) {
            case 'classic':
                return 'This is a classic page with no Gutenberg blocks.';
            case 'row_blocks':
                return 'This is a row block page with Gutenberg group blocks.';
            case 'details_blocks':
                return 'This is a details block page with collapsible content.';
            case 'freeform_blocks':
                return 'This is a freeform block page with custom HTML content.';
            default:
                return 'This is a test page with Gutenberg blocks.';
        }
    }

    /**
     * Generate main content based on content type.
     *
     * @param  array<string, mixed>  $config  Configuration options
     * @return string The main content
     */
    private function generateMainContent(
        array $config
    ): string {
        // Handle form plugin content types first
        if (
            $this->configNullableString($config, 'form_plugin') !== null
            && $this->configNullableString($config, 'form_type') !== null
        ) {
            return $this->generateFormPluginContent($config);
        }

        // Handle standard content types
        switch ($this->configString($config, 'content_type')) {
            case 'classic':
                return '<p>It contains plain HTML content that would be detected as Classic block content.</p>';
            case 'row_blocks':
                return '<!-- wp:group {"layout":{"type":"constrained"}} -->
<div class="wp-block-group"><!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|50","right":"var:preset|spacing|50","bottom":"var:preset|spacing|50","left":"var:preset|spacing|50"}},"border":{"radius":"0px"}},"backgroundColor":"base-2","layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"center"}} -->
<div class="wp-block-group has-base-2-background-color has-background" style="border-radius:0px;padding-top:var(--wp--preset--spacing--50);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50);padding-left:var(--wp--preset--spacing--50)"><!-- wp:paragraph -->
<p>This is a row block page with Gutenberg group blocks.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group --></div>
<!-- /wp:group -->';
            case 'details_blocks':
                return '<!-- wp:details -->
<details class="wp-block-details"><summary>Click to expand</summary>
<!-- wp:paragraph -->
<p>This is a details block page with collapsible content.</p>
<!-- /wp:paragraph --></details>
<!-- /wp:details -->';
            case 'freeform_blocks':
                return '<!-- wp:freeform -->
<div class="wp-block-freeform">
<p>This is a freeform block page with custom HTML content.</p>
</div>
<!-- /wp:freeform -->';
            default: // gutenberg
                return '<!-- wp:paragraph -->
<p>Foo bar baz.</p>
<!-- /wp:paragraph -->';
        }
    }

    /**
     * Generate form plugin specific content.
     *
     * @param  array<string, mixed>  $config  Configuration options
     * @return string The form plugin content
     */
    private function generateFormPluginContent(
        array $config
    ): string {
        $pluginName = $this->configString(
            $config,
            'form_plugin'
        );
        $formType = $this->configString(
            $config,
            'form_type'
        );
        $formAttributes = $this->configString(
            $config,
            'form_attributes'
        );
        $formShortcode = $this->configString(
            $config,
            'form_shortcode'
        );

        // Generate content based on plugin and form type combination
        if ($pluginName === 'gravity_forms') {
            if ($formType === 'block') {
                return '<!-- wp:gravityforms/form '.$formAttributes.' /-->';
            } else { // Otherwise // shortcode
                return '<!-- wp:html -->
'.$formShortcode.'
<!-- /wp:html -->';
            }
        } elseif ($pluginName === 'formidable_forms') {
            if ($formType === 'block') {
                return '<!-- wp:formidable/simple-form '.$formAttributes.' /-->';
            } else { // Otherwise // shortcode
                return '<!-- wp:html -->
'.$formShortcode.'
<!-- /wp:html -->';
            }
        }

        // Fallback for unknown plugins
        return '<!-- wp:paragraph -->
<p>Unknown form plugin content.</p>
<!-- /wp:paragraph -->';
    }

    /**
     * Generate page title based on configuration.
     *
     * @param  array<string, mixed>  $config  Configuration options
     * @return string The page title
     */
    private function generatePageTitle(
        array $config
    ): string {
        $formPlugin = $this->configNullableString(
            $config,
            'form_plugin'
        );
        $formType = $this->configNullableString(
            $config,
            'form_type'
        );

        if (
            $formPlugin !== null
            && $formType !== null
        ) {
            $pluginName = \ucfirst(
                \str_replace(
                    '_',
                    ' ',
                    $formPlugin
                )
            );
            $formTypeLabel = \ucfirst($formType);

            return "{$pluginName} {$formTypeLabel} Page";
        }

        switch ($this->configString($config, 'content_type')) {
            case 'classic':
                return 'Classic Page';
            case 'row_blocks':
                return 'Row Block Page';
            case 'details_blocks':
                return 'Details Block Page';
            case 'freeform_blocks':
                return 'Freeform Block Page';
            default:
                return $this->configString(
                    $config,
                    'title',
                    'Test Page'
                );
        }
    }

    /**
     * Generate page slug based on configuration.
     *
     * @param  array<string, mixed>  $config  Configuration options
     * @return string The page slug
     */
    private function generatePageSlug(
        array $config
    ): string {
        $formPlugin = $this->configNullableString(
            $config,
            'form_plugin'
        );
        $formType = $this->configNullableString(
            $config,
            'form_type'
        );

        if (
            $formPlugin !== null
            && $formType !== null
        ) {
            $pluginSlug = \str_replace(
                '_',
                '-',
                $formPlugin
            );
            $formTypeSlug = \str_replace(
                '_',
                '-',
                $formType
            );

            return "{$pluginSlug}-{$formTypeSlug}-page";
        }

        switch ($this->configString($config, 'content_type')) {
            case 'classic':
                return 'classic-page';
            case 'row_blocks':
                return 'row-block-page';
            case 'details_blocks':
                return 'details-block-page';
            case 'freeform_blocks':
                return 'freeform-block-page';
            default:
                return $this->configString(
                    $config,
                    'slug',
                    'test-page'
                );
        }
    }

    /**
     * Get a basic Gutenberg page item (backward compatibility).
     *
     * @return string The page item XML
     */
    protected function getGutenbergPageItem(): string
    {
        return $this->generatePageItem(
            [
                'post_id' => 123,
                'title' => 'Foo',
                'slug' => 'foo',
            ]
        );
    }

    /**
     * Generate a page that will use basic_page blueprint (no ACF blocks).
     *
     * @param  array<string, mixed>  $options  Additional configuration options
     * @return string The page item XML
     */
    protected function generateBasicPageItem(
        array $options = []
    ): string {
        $defaults = [
            'post_id' => 900,
            'title' => 'Basic Page',
            'slug' => 'basic-page',
            'has_acf_blocks' => false,
            'content_type' => 'gutenberg',
        ];

        return $this->generatePageItem(
            \array_merge(
                $defaults,
                $options
            )
        );
    }

    /**
     * Generate a page that will use landing_page blueprint (with ACF blocks).
     *
     * @param  array<string, mixed>  $options  Additional configuration options
     * @return string The page item XML
     */
    protected function generateLandingPageItem(
        array $options = []
    ): string {
        $defaults = [
            'post_id' => 901,
            'title' => 'Landing Page',
            'slug' => 'landing-page',
            'has_acf_blocks' => true,
            'content_type' => 'gutenberg',
        ];

        return $this->generatePageItem(
            \array_merge(
                $defaults,
                $options
            )
        );
    }

    /**
     * Get a page item with a Gravity Forms block.
     *
     * @param  string  $formId  The form ID
     * @param  string  $blockAttributes  JSON attributes for the block
     * @return string The page item XML
     */
    private function getPageItemWithGravityFormsBlock(
        string $formId,
        string $blockAttributes
    ): string {
        return $this->generatePageItem([
            'post_id' => 789,
            'form_plugin' => 'gravity_forms',
            'form_type' => 'block',
            'form_id' => $formId,
            'form_attributes' => $blockAttributes,
        ]);
    }

    /**
     * Get a page item with a Gravity Forms shortcode.
     *
     * @param  string  $shortcode  The shortcode content
     * @return string The page item XML
     */
    private function getPageItemWithGravityFormsShortcode(
        string $shortcode
    ): string {
        return $this->generatePageItem([
            'post_id' => 790,
            'form_plugin' => 'gravity_forms',
            'form_type' => 'shortcode',
            'form_shortcode' => $shortcode,
        ]);
    }

    /**
     * Build XML content with the given items.
     *
     * @param  array<string>  $items  Array of item XML strings
     * @return string The complete XML content
     */
    protected function buildXmlContent(
        array $items = []
    ): string {
        $header = $this->getXmlHeader();
        $footer = $this->getXmlFooter();

        $itemsXml = \implode(
            "\n",
            $items
        );

        return $header."\n".$itemsXml."\n".$footer;
    }

    /**
     * Build XML content with a Gravity Forms block.
     *
     * @param  string  $formId  The form ID
     * @param  string  $blockAttributes  JSON attributes for the block
     * @return string The complete XML content
     */
    protected function buildXmlWithGravityFormsBlock(
        string $formId,
        string $blockAttributes
    ): string {
        $pageItem = $this->getPageItemWithGravityFormsBlock(
            $formId,
            $blockAttributes
        );

        return $this->buildXmlContent([$pageItem]);
    }

    /**
     * Build XML content with a Gravity Forms shortcode.
     *
     * @param  string  $shortcode  The shortcode content
     * @return string The complete XML content
     */
    protected function buildXmlWithGravityFormsShortcode(
        string $shortcode
    ): string {
        $pageItem = $this->getPageItemWithGravityFormsShortcode($shortcode);

        return $this->buildXmlContent([$pageItem]);
    }

    /**
     * Get an asset item.
     *
     * @return string The asset item XML
     */
    protected function getAssetItem(): string
    {
        return '        <item>
            <title><![CDATA[Test Image]]></title>
            <link>https://supplychain.uams.edu/test-image/</link>
            <pubDate></pubDate>
            <dc:creator><![CDATA[uamsonline]]></dc:creator>
            <guid isPermaLink="false">https://supplychain.uams.edu/?attachment_id=999</guid>
            <description></description>
            <content:encoded><![CDATA[]]></content:encoded>
            <excerpt:encoded><![CDATA[]]></excerpt:encoded>
            <wp:post_id>999</wp:post_id>
            <wp:post_date><![CDATA[2024-12-09 16:04:12]]></wp:post_date>
            <wp:post_date_gmt><![CDATA[0000-00-00 00:00:00]]></wp:post_date_gmt>
            <wp:post_modified><![CDATA[2024-12-09 16:04:12]]></wp:post_modified>
            <wp:post_modified_gmt><![CDATA[2024-12-09 22:04:12]]></wp:post_modified_gmt>
            <wp:comment_status><![CDATA[closed]]></wp:comment_status>
            <wp:ping_status><![CDATA[closed]]></wp:ping_status>
            <wp:post_name><![CDATA[test-image]]></wp:post_name>
            <wp:status><![CDATA[inherit]]></wp:status>
            <wp:post_parent>123</wp:post_parent>
            <wp:menu_order>0</wp:menu_order>
            <wp:post_type><![CDATA[attachment]]></wp:post_type>
            <wp:post_password><![CDATA[]]></wp:post_password>
            <wp:is_sticky>0</wp:is_sticky>
            <wp:attachment_url><![CDATA[https://www.w3.org/assets/logos/w3c/w3c-full.png]]></wp:attachment_url>
        </item>';
    }

    /**
     * Build XML content with Formidable Forms block.
     *
     * @param  string  $formId  The form ID
     * @param  string  $blockAttributes  The block attributes JSON
     * @return string The complete XML content
     */
    protected function buildXmlWithFormidableFormsBlock(
        string $formId,
        string $blockAttributes
    ): string {
        $pageItem = $this->getPageItemWithFormidableFormsBlock(
            $formId,
            $blockAttributes
        );

        return $this->buildXmlContent([$pageItem]);
    }

    /**
     * Build XML content with Formidable Forms shortcode.
     *
     * @param  string  $shortcode  The shortcode
     * @return string The complete XML content
     */
    protected function buildXmlWithFormidableFormsShortcode(
        string $shortcode
    ): string {
        $pageItem = $this->getPageItemWithFormidableFormsShortcode($shortcode);

        return $this->buildXmlContent([$pageItem]);
    }

    /**
     * Get a page item with Formidable Forms block.
     *
     * @param  string  $formId  The form ID
     * @param  string  $blockAttributes  The block attributes JSON
     * @return string The page item XML
     */
    private function getPageItemWithFormidableFormsBlock(
        string $formId,
        string $blockAttributes
    ): string {
        return $this->generatePageItem([
            'post_id' => 791,
            'form_plugin' => 'formidable_forms',
            'form_type' => 'block',
            'form_id' => $formId,
            'form_attributes' => $blockAttributes,
        ]);
    }

    /**
     * Get a page item with Formidable Forms shortcode.
     *
     * @param  string  $shortcode  The shortcode
     * @return string The page item XML
     */
    private function getPageItemWithFormidableFormsShortcode(
        string $shortcode
    ): string {
        return $this->generatePageItem([
            'post_id' => 792,
            'form_plugin' => 'formidable_forms',
            'form_type' => 'shortcode',
            'form_shortcode' => $shortcode,
        ]);
    }

    /**
     * Create a temporary XML file with the given content.
     *
     * @param  string  $content  The XML content
     * @return string The path to the temporary file
     */
    protected function createTemporaryXmlFile(
        string $content
    ): string {
        $tempFile = $this->createTempFile('test_wordpress_import_');
        file_put_contents(
            $tempFile,
            $content
        );

        return $tempFile;
    }

    /**
     * Normalize path separators to use the system's directory separator.
     *
     * This method replaces both forward slashes and backslashes with the
     * system's DIRECTORY_SEPARATOR constant to ensure cross-platform
     * compatibility.
     *
     * @param  string  $path  The path to normalize
     * @return string The normalized path
     */
    private static function normalizePathSeparators(
        string $path
    ): string {
        return \str_replace(
            [
                '\\',
                '/',
            ],
            DIRECTORY_SEPARATOR,
            $path
        );
    }

    /**
     * Delete files if they exist.
     *
     * This method takes an array of file paths and deletes each file
     * if it exists, providing a centralized way to handle file deletion
     * with existence checks.
     *
     * @param  array<int, string>  $files  Array of file paths to delete
     */
    private static function deleteFilesIfExist(
        array $files
    ): void {
        foreach ($files as $file) {
            if (\file_exists($file)) {
                unlink($file);
            }
        }
    }

    /**
     * Clean up items using both original and normalized versions.
     *
     * This method processes an array of items (file paths or glob patterns),
     * cleaning up both the original items and their normalized versions with
     * proper directory separators. It uses a callback function to process
     * each item and return the files to be deleted.
     *
     * @param  array<int, string>  $items  Array of items to process (paths or patterns)
     * @param  callable(string): array<int, string>  $itemProcessor  Callback function to process each item and return files to delete
     */
    protected static function cleanupItemsWithNormalizedVersions(
        array $items,
        callable $itemProcessor
    ): void {
        foreach ($items as $item) {
            // Process original item
            $files = $itemProcessor($item);
            self::deleteFilesIfExist($files);

            // Process normalized item
            $normalizedItem = self::normalizePathSeparators($item);
            if ($normalizedItem !== $item) {
                $files = $itemProcessor($normalizedItem);
                self::deleteFilesIfExist($files);
            }
        }
    }

    /**
     * Clean up test files using a flexible helper method.
     *
     * This method provides a reusable way to clean up test files by accepting
     * arrays of entry slugs, asset containers with filenames, and taxonomy terms to
     * clean up.
     *
     * It automatically generates glob patterns for entry files and taxonomy files
     * based on the provided parameters, ensuring comprehensive cleanup.
     *
     * @param  array<int, string>  $entrySlugs  Array of entry slugs to clean up via Statamic deletion
     * @param  string  $collectionHandle  The collection handle for entry cleanup (default: 'post')
     * @param  string  $site  The site handle for entry cleanup (default: 'default')
     * @param  array<string, array<int, string>>  $assetFiles  Array of asset containers with filenames
     *                                                         ['container' => ['file1.jpg', 'file2.jpg']]
     * @param  array<int, string>  $statamicTaxonomyTermObjects  Array of taxonomy term IDs to clean up
     * @param  bool|array<int, array<string, mixed>>  $restoreTreeFiles  Whether to restore tree files, or array of tree file configs (default: true).
     *                                                                   If true, uses default tree files. If array, each element should be:
     *                                                                   ['file' => 'path/to/file.yaml', 'testPatterns' => ['pattern1', 'pattern2']].
     */
    protected static function cleanupTestFilesWithPaths(
        array $entrySlugs = [],
        string $collectionHandle = 'posts',
        string $site = 'default',
        array $assetFiles = [],
        array $statamicTaxonomyTermObjects = [],
        bool|array $restoreTreeFiles = true
    ): void {
        // Clean up entries using Statamic's proper deletion method
        self::cleanupEntriesBySlug(
            $entrySlugs,
            $collectionHandle,
            $site
        );

        // Clean up specific asset files using the new structured format
        self::cleanupAssetsByContainer(
            $assetFiles,
            $site
        );

        // Generate and clean up glob patterns for entry files
        $entryGlobPatterns = self::generateEntryGlobPatterns(
            $entrySlugs,
            $collectionHandle,
            $site
        );
        self::cleanupItemsWithNormalizedVersions(
            $entryGlobPatterns,
            [self::class, 'globMatches']
        );

        // Generate and clean up glob patterns for taxonomy files
        $taxonomyGlobPatterns = self::generateTaxonomyGlobPatterns($statamicTaxonomyTermObjects);
        self::cleanupItemsWithNormalizedVersions(
            $taxonomyGlobPatterns,
            [self::class, 'globMatches']
        );

        // Clean up taxonomy terms
        foreach ($statamicTaxonomyTermObjects as $termId) {
            $term = TermFacade::find($termId);
            if ($term instanceof Term) {
                $term->delete();
            }
        }

        // Restore tree files if requested
        if ($restoreTreeFiles !== false) {
            self::restoreTreeFiles($restoreTreeFiles);
        }

        // ---
        // Several deletions above operate on raw files (glob/unlink) which the
        // Stache does not observe, so its file-backed index would keep serving
        // the just-removed entries/terms to the next test or data-provider row —
        // causing phantom "already exists" short-circuits and asset filename
        // collisions. Clear the Stache so the next read rebuilds from the
        // now-clean disk state. Re-warming is lazy (per store, on access).
        // ---
        \Statamic\Facades\Stache::clear();

        // ---
        // BaseEntryCreator memoizes processed terms, taxonomy imports, site
        // names and deferred-term state in static properties that otherwise
        // survive for the whole PHPUnit process. Left in place, a term created
        // by an earlier test stays cached as "already processed", so a later
        // test (whose disk file was just removed) skips re-creating it and reads
        // a stale slug-as-title. Reset the importer's static caches between
        // tests so each starts from a clean in-memory state.
        // ---
        BaseEntryCreator::clearTaxonomyCaches();
        BaseEntryCreator::clearDeferredTermQueue();
    }

    /**
     * Temporary directory holding the per-test snapshot of isolated content paths.
     */
    private static ?string $isolatedStateSnapshotDir = null;

    /**
     * Relative paths (under base_path) an importing test mutates and that must
     * be reset verbatim between tests. Slug-list cleanup cannot catch entries
     * the importer writes with generated IDs / dated filenames, nor the
     * collection trees it regenerates, so data-provider tests that re-import
     * the same fixture hundreds of times corrupt one another. Override in the
     * test class to declare the collection/tree/taxonomy dirs it touches.
     *
     * @return array<int, string>
     */
    protected static function isolatedStatePaths(): array
    {
        return [];
    }

    /**
     * Isolated-state paths captured for the current test run.
     *
     * Pest function-style tests cannot override the static
     * {@see self::isolatedStatePaths()} template method, so they pass their
     * paths directly to {@see self::snapshotIsolatedState()} instead; this
     * property remembers them so {@see self::restoreIsolatedState()} restores
     * the same set. Class-based callers leave it null and fall back to the
     * static override.
     *
     * @var array<int, string>|null
     */
    private ?array $isolatedStatePathsForRun = null;

    /**
     * The running process id.
     *
     * `getmypid()` is documented as able to return `false`; 0 is a pid no
     * snapshot directory can claim, so the sweep simply matches nothing rather
     * than mistaking an unknown owner for a dead one.
     *
     * @return int The process id, or 0 when unavailable
     */
    private static function currentPid(): int
    {
        $pid = \getmypid();

        return $pid === false ? 0 : $pid;
    }

    /**
     * Remove snapshot directories stranded by runs that are no longer alive.
     *
     * `restoreIsolatedState()` cleans up after any test that reaches its
     * `afterEach`; this covers the paths that never do. Ownership is decided by
     * the pid in the directory name, so a concurrent run in the sibling host
     * slot is never touched — and when liveness cannot be determined the entry
     * is treated as live, because sweeping is a delete and the directory is the
     * only copy of the content its owner is about to restore.
     */
    private static function reclaimAbandonedSnapshots(): void
    {
        $framework = storage_path('framework');
        $entries = \glob($framework.'/'.IsolatedStateScratch::PREFIX.'*', GLOB_ONLYDIR);

        if ($entries === false || $entries === []) {
            return;
        }

        $abandoned = IsolatedStateScratch::abandoned(
            \array_map('basename', $entries),
            self::currentPid(),
            static function (int $pid): bool {
                // No POSIX extension means no answer; the safe answer is "alive".
                if (! \function_exists('posix_kill')) {
                    return true;
                }

                return \posix_kill($pid, 0);
            }
        );

        foreach ($abandoned as $basename) {
            \exec('rm -rf '.\escapeshellarg($framework.'/'.$basename));
        }
    }

    /**
     * Snapshot the isolated-state paths so they can be restored after the test.
     *
     * The `content/` submodule's git is unusable in a copied worktree (its
     * gitdir pointer does not resolve), so `git restore` can never reset these
     * files — this captures the real files via an APFS copy-on-write clone
     * instead. Call from the test's setUp / `beforeEach`.
     *
     * @param  array<int, string>|null  $paths  Explicit paths to isolate (Pest
     *                                          function-style tests pass these
     *                                          because they cannot override the
     *                                          static template method); null
     *                                          falls back to the static override.
     */
    protected function snapshotIsolatedState(?array $paths = null): void
    {
        $paths ??= static::isolatedStatePaths();
        $this->isolatedStatePathsForRun = $paths;
        if ($paths === []) {
            return;
        }

        // Keep the snapshot on the same volume as the content so the copy-on-write
        // clone (cp -c) succeeds; a cross-volume clone would silently fall back/fail.
        self::$isolatedStateSnapshotDir = storage_path(
            'framework/'.IsolatedStateScratch::name(self::currentPid(), \uniqid())
        );
        @\mkdir(self::$isolatedStateSnapshotDir, 0777, true);

        // A test that errors out of beforeEach, or a run killed mid-file, never
        // reaches restoreIsolatedState(). Shutdown still runs on a fatal and on a
        // normal exit, so this keeps a completed run from stranding ~1.2 MB that
        // blocks `worktree-slots release`.
        $stranded = self::$isolatedStateSnapshotDir;
        \register_shutdown_function(static function () use ($stranded): void {
            if (\is_dir($stranded)) {
                \exec('rm -rf '.\escapeshellarg($stranded));
            }
        });

        foreach ($paths as $relativePath) {
            $source = ContentRoot::resolve($relativePath);
            $target = self::$isolatedStateSnapshotDir.'/'.\md5($relativePath);

            if (\file_exists($source)) {
                \exec('cp -ac '.\escapeshellarg($source).' '.\escapeshellarg($target).' 2>/dev/null');
            } else {
                // Record that the path was absent so the restore removes anything the test creates there.
                \file_put_contents($target.'.absent', '');
            }
        }
    }

    /**
     * Restore the snapshotted paths, discarding everything the test created
     * (entries with generated IDs, regenerated collection trees, created
     * terms), then clear the Stache and the importer's static caches so the
     * next test starts from a clean in-memory state too. Call from tearDown.
     */
    protected function restoreIsolatedState(): void
    {
        if (self::$isolatedStateSnapshotDir === null) {
            return;
        }

        foreach ($this->isolatedStatePathsForRun ?? static::isolatedStatePaths() as $relativePath) {
            $source = ContentRoot::resolve($relativePath);
            $target = self::$isolatedStateSnapshotDir.'/'.\md5($relativePath);
            $hasSnapshot = \file_exists($target);
            $wasAbsent = \file_exists($target.'.absent');

            // Safety: if neither a snapshot copy nor an absent-marker was recorded
            // (snapshot failed), do not delete the live path — leaving it polluted
            // is far safer than destroying real content with no way to restore it.
            if (! $hasSnapshot && ! $wasAbsent) {
                continue;
            }

            \exec('rm -rf '.\escapeshellarg($source));

            if ($hasSnapshot) {
                \exec('cp -ac '.\escapeshellarg($target).' '.\escapeshellarg($source).' 2>/dev/null');
            }
            // A '.absent' marker means the path did not exist at snapshot time; leave it removed.
        }

        \exec('rm -rf '.\escapeshellarg(self::$isolatedStateSnapshotDir));
        self::$isolatedStateSnapshotDir = null;
        $this->isolatedStatePathsForRun = null;

        \Statamic\Facades\Stache::clear();
        BaseEntryCreator::clearTaxonomyCaches();
        BaseEntryCreator::clearDeferredTermQueue();
    }

    /**
     * Whether {@see self::captureSubmoduleStates()} ran for this test.
     *
     * Distinguishes "there was no submodule to capture" from "the paired call
     * was never made", which otherwise both read as a silent pass.
     */
    private bool $submoduleGuardArmed = false;

    /**
     * Each guarded submodule's state before the current test ran, keyed by its
     * absolute path.
     *
     * A null value records a submodule the host DECLARES but this checkout has
     * not initialized. Keeping the key rather than skipping it is what makes
     * that state reportable instead of invisible.
     *
     * @var array<string, ?ContentSubmoduleState>
     */
    private array $submoduleStatesBefore = [];

    /**
     * How many submodules `.gitmodules` declared when the capture ran.
     *
     * Compared against what was actually captured, because a `capture()` that
     * throws partway leaves a SHORTER map behind while the armed flag still
     * reads true — and an assertion over three of four submodules would then
     * pass while presenting as a full check.
     */
    private int $submodulesDeclared = 0;

    /**
     * Capture every declared submodule's state before the test runs.
     *
     * Pair with {@see self::assertSubmodulesUnchanged()} in the same
     * file's `afterEach`.
     *
     * **The four submodules are guarded for two different reasons, and the
     * distinction matters when a failure appears.**
     *
     * For `content/` this proves a STRUCTURAL guarantee.
     * `UAMS-Web/uams-statamic-archive#1995` re-roots every content-backed Stache store —
     * and the Eloquent asset-metadata dual-write — at a disposable mirror under
     * `APP_ENV=testing`, so no Statamic save can reach the live submodule at all.
     * That re-rooting is keyed on `App\Support\ContentRoot::path()` and does
     * **not** cover a raw `base_path('content/…')`. This package had three of
     * those, one of which deletes files; wordpress-importer-archive#622 routed all three through
     * {@see ContentRoot}. So a `content/` failure is not ordinary litter — it
     * means a **new** raw path has appeared and written straight through the
     * mirror.
     *
     * For `public/assets`, `app/private/assets` and `users`, it is exactly
     * ordinary litter. Nothing re-roots an asset download: an import that fetches
     * a real image writes it into `public/assets` for real, and removing it is
     * opt-in through `cleanupTestFilesWithPaths()`'s `$assetFiles` list. A
     * failure there means the test that caused it did not clean up — which is
     * the whole of wordpress-importer-archive#764, filed after 15 real UAMS images sat in that submodule
     * for two days while every guard the band had reported clean.
     *
     * These paths must NOT be re-rooted at the mirror: the guard exists to watch
     * the live submodules, and pointing it at the mirror would make it assert
     * nothing.
     *
     * Cost, measured warm on macOS (git 2.39.5, Apple Git-154) against the
     * importer slot. `capture()` costs 0.065s for `content` over 3,233 files,
     * 0.063s for `public/assets` over 1,212, 0.054s for `app/private/assets`
     * over 486, and 0.048s for `users` over 4 — **0.230s** of captures per hook.
     * `declaredPaths()` adds 0.016s to the BEFORE hook only; the after hook
     * iterates the map it already holds and never calls it again. So a test pays
     * 0.246s + 0.230s = **0.476s** across both hooks, and a 54-test file about
     * 26s on top of a ~740s run. `capture()` issues three git calls per
     * submodule. Not yet measured on Windows.
     *
     * The `public/assets` count moved from 1,227 to 1,212 when wordpress-importer-archive#764 removed the
     * 15 images it was filed over, which is why an earlier figure does not
     * reproduce.
     */
    protected function captureSubmoduleStates(): void
    {
        // Every file in this band arms this in beforeEach, including the four that
        // never snapshot isolated state. Reclaiming here rather than inside
        // snapshotIsolatedState() is what lets a run of any file clear litter a
        // previous, different file stranded.
        self::reclaimAbandonedSnapshots();

        $this->submoduleGuardArmed = true;
        $this->submoduleStatesBefore = [];

        // Read the set to guard from `.gitmodules` rather than naming it here — see
        // ContentSubmoduleState::declaredPaths() for why a hard-coded list is what
        // produced the gap this closes
        $declared = ContentSubmoduleState::declaredPaths(base_path());
        $this->submodulesDeclared = \count($declared);

        foreach ($declared as $submodulePath) {
            $state = ContentSubmoduleState::capture($submodulePath);
            $this->submoduleStatesBefore[$submodulePath] = $state;

            // Say so out loud, once: a check whose only output is silence cannot be
            // told apart from one that never ran
            if ($state === null) {
                $this->noticeSubmodule(
                    'submodule guard: no initialized submodule at '.$submodulePath.' — nothing to check on this run.'
                );
            }
        }

        // ---
        // A host declaring none at all would make every assertion in the pair
        // vacuous, and a notice is not enough: the run still passes, which is
        // indistinguishable from a clean one. The host this band runs against
        // declares four, so zero means the probe is broken rather than that there
        // is nothing to guard.
        // ---
        if ($this->submoduleStatesBefore === []) {
            Assert::fail(
                base_path().'/.gitmodules declared no submodules, so the guard would have checked nothing '
                .'while reporting a clean run.'
            );
        }
    }

    /**
     * Assert the test left every guarded submodule as it found it.
     *
     * Call from the same file's `afterEach`, AFTER the cleanup and
     * {@see self::restoreIsolatedState()} have run — the guard's job is to
     * catch what those did not cover, so checking before them would report
     * every path the restore was about to put back.
     */
    protected function assertSubmodulesUnchanged(): void
    {
        // A missing capture is a wiring bug, not a clean run
        if (! $this->submoduleGuardArmed) {
            Assert::fail(
                'assertSubmodulesUnchanged() ran without a paired captureSubmoduleStates() in beforeEach, '
                .'so there is no before-state to compare against and the guard verified nothing.'
            );
        }

        // ---
        // A capture that threw partway leaves a shorter map than `.gitmodules`
        // declared. The loop below would then check the submodules it did reach
        // and pass, which reads exactly like a full clean run.
        // ---
        if (\count($this->submoduleStatesBefore) !== $this->submodulesDeclared) {
            Assert::fail(\sprintf(
                'The submodule guard captured %d of the %d submodules declared in .gitmodules, so it would have '
                .'checked a subset while reporting a clean run.',
                \count($this->submoduleStatesBefore),
                $this->submodulesDeclared
            ));
        }

        $failures = [];

        foreach ($this->submoduleStatesBefore as $submodulePath => $before) {
            // Nothing to guard here; already reported by the capture above
            if ($before === null) {
                continue;
            }

            $after = ContentSubmoduleState::capture($submodulePath);

            // The submodule went away mid-test — report it rather than diffing against nothing
            if ($after === null) {
                $this->noticeSubmodule(
                    'submodule guard: the submodule at '.$submodulePath.' became unreadable during the run.'
                );

                continue;
            }

            $diff = ContentSubmoduleDiff::between($before, $after);

            // Dirt that arrived with the checkout is named, once, and not charged here
            foreach ($diff->warnings($submodulePath) as $warning) {
                $this->noticeSubmodule($warning);
            }

            if ($diff->isClean()) {
                continue;
            }

            // ---
            // Collect rather than fail on the first: a test that writes into two
            // submodules should name both, or the second is discovered only after
            // the first is fixed and the multi-minute band is run again.
            // ---
            $failures[] = $diff->failureMessage($submodulePath);
        }

        if ($failures !== []) {
            Assert::fail(\implode("\n\n", $failures));
        }
    }

    /**
     * Report a submodule observation once per process.
     *
     * The registry lives on {@see ContentSubmoduleNotices} rather than on this
     * trait because a static declared on a trait is NOT shared by the classes
     * composing it — each gets its own copy, so the same line would still print
     * once per test file.
     *
     * @param  string  $notice  The message to print, deduplicated verbatim
     */
    private function noticeSubmodule(
        string $notice
    ): void {
        if (! ContentSubmoduleNotices::shouldReport($notice)) {
            return;
        }

        \fwrite(\STDERR, "\n".$notice."\n");
    }

    /**
     * Clean up entries by slug using Statamic's proper deletion method.
     *
     * This method provides a centralized way to clean up test entries by their slugs,
     * ensuring proper removal from Statamic's system before file cleanup.
     *
     * @param  array<int, string>  $entrySlugs  Array of entry slugs to clean up
     * @param  string  $collectionHandle  The collection handle for entry cleanup
     * @param  string  $site  The site handle for entry cleanup
     */
    private static function cleanupEntriesBySlug(
        array $entrySlugs,
        string $collectionHandle,
        string $site
    ): void {
        foreach ($entrySlugs as $slug) {
            $collection = Collection::find($collectionHandle);
            if ($collection === null) {
                continue;
            }

            $entry = self::findEntryBySlug(
                $collection,
                $site,
                $slug
            );

            if ($entry instanceof Entry) {
                try {
                    $entry->delete();
                } catch (\Exception $e) {
                    // Continue with other entries even if one fails
                    // This can happen with hierarchical entries where tree validation
                    // fails during deletion
                }
            }
        }
    }

    /**
     * Clean up assets by container and filename using structured format.
     *
     * Public asset containers use paths under public/assets/{container}/ per
     * config/filesystems.php. This method generates asset and metadata paths
     * accordingly.
     *
     * @param  array<string, array<int, string>>  $assetFiles  Array of asset containers with filenames
     *                                                         ['container' => ['file1.jpg', 'file2.jpg']]
     * @param  string  $site  The site handle for asset cleanup
     */
    private static function cleanupAssetsByContainer(
        array $assetFiles,
        string $site
    ): void {
        $filesToDelete = [];

        foreach ($assetFiles as $container => $filenames) {
            foreach ($filenames as $filename) {
                // Public asset containers use public/assets/{container}/...
                $containerPrefix = "assets/{$container}";

                // For audio and portraits containers, don't prepend the site handle to the filename
                if (
                    $container === 'audio'
                    || $container === 'portraits'
                ) {
                    // Add the main asset file
                    $filesToDelete[] = public_path("{$containerPrefix}/{$filename}");

                    // For audio and portraits containers, inject .meta/ directory after the right-most '/' in the filename
                    $lastSlashPos = strrpos(
                        $filename,
                        '/'
                    );
                    if ($lastSlashPos !== false) {
                        // Filename contains path separators, inject .meta/ after the right-most '/'
                        $pathPart = \substr(
                            $filename,
                            0,
                            $lastSlashPos + 1
                        );
                        $filePart = \substr(
                            $filename,
                            $lastSlashPos + 1
                        );
                        $metadataPath = "{$containerPrefix}/{$pathPart}.meta/{$filePart}.yaml";
                    } else { // Otherwise
                        // Filename has no path separators, use standard .meta/ directory
                        $metadataPath = "{$containerPrefix}/.meta/{$filename}.yaml";
                    }
                    $filesToDelete[] = public_path($metadataPath);
                } else { // Otherwise
                    // For other containers, prepend the site handle as before
                    // Add the main asset file
                    $filesToDelete[] = public_path("{$containerPrefix}/{$site}/{$filename}");

                    // Add the metadata file
                    $filesToDelete[] = public_path("{$containerPrefix}/{$site}/.meta/{$filename}.yaml");
                }
            }
        }

        // Delete all files
        self::deleteFilesIfExist($filesToDelete);
    }

    /**
     * Generate glob patterns for entry files based on slugs, collection, and site.
     *
     * This method creates glob patterns to match entry files that may have:
     *   - Numeric suffixes (e.g., sample-entry.1.md) created by Statamic's conflict
     *     resolution
     *   - Date prefixes for dated collections (e.g., 2025-10-31.kb.md)
     *
     * @param  array<int, string>  $entrySlugs  Array of entry slugs
     * @param  string  $collectionHandle  The collection handle
     * @param  string  $site  The site handle
     * @return array<int, string> Array of glob patterns for entry files
     */
    private static function generateEntryGlobPatterns(
        array $entrySlugs,
        string $collectionHandle,
        string $site
    ): array {
        $patterns = [];

        foreach ($entrySlugs as $slug) {
            if ($slug === '') {
                continue;
            }            // Pattern for non-dated entry without conflict resolution suffix
            // Matches: kb.md
            $patterns[] = "content/collections/{$collectionHandle}/{$site}/{$slug}.md";

            // Pattern for non-dated entry with conflict resolution suffix
            // Matches: kb.1.md, kb.2.md, etc.
            $patterns[] = "content/collections/{$collectionHandle}/{$site}/{$slug}.*.md";

            // Pattern for dated entry without conflict resolution suffix
            // Matches: 2025-10-31.kb.md
            $patterns[] = "content/collections/{$collectionHandle}/{$site}/*.{$slug}.md";

            // Pattern for dated entry with conflict resolution suffix
            // Matches: 2025-10-31.kb.1.md, 2025-10-31.kb.2.md, etc.
            $patterns[] = "content/collections/{$collectionHandle}/{$site}/*.{$slug}.*.md";
        }

        return $patterns;
    }

    /**
     * Generate glob patterns for taxonomy files based on taxonomy terms.
     *
     * This method creates glob patterns to match taxonomy term files based on
     * the taxonomy term IDs in the format 'taxonomy_handle::term_slug'.
     *
     * @param  array<int, string>  $statamicTaxonomyTermObjects  Array of Statamic taxonomy term objects
     * @return array<int, string> Array of glob patterns for taxonomy files
     */
    private static function generateTaxonomyGlobPatterns(
        array $statamicTaxonomyTermObjects
    ): array {
        $patterns = [];

        foreach ($statamicTaxonomyTermObjects as $termId) {
            // Parse taxonomy term ID format: 'taxonomy_handle::term_slug'
            if (
                \strpos(
                    $termId,
                    '::'
                ) !== false
            ) {
                [$taxonomyHandle, $termSlug] = \explode(
                    '::',
                    $termId,
                    2
                );
                $patterns[] = "content/taxonomies/{$taxonomyHandle}/{$termSlug}.yaml";
            }
        }

        return $patterns;
    }

    /**
     * Restore tree files from git if they contain test data.
     *
     * @param  bool|array<int, array<string, mixed>>  $treeFilesConfig  If true, uses default tree files. If array, each element should be:
     *                                                                  ['file' => 'path/to/file.yaml', 'testPatterns' => ['pattern1', 'pattern2'], 'deleteOnFailure' => bool]
     *                                                                  The 'deleteOnFailure' option (default: false) will delete the file if git
     *                                                                  restore fails or test data persists
     */
    private static function restoreTreeFiles(
        bool|array $treeFilesConfig = true
    ): void {
        /** @var array<int, array<string, mixed>> $treeFiles */
        $treeFiles = [];

        // Check if using default configuration (backward compatible)
        if ($treeFilesConfig === true) {
            // Use default pages.yaml configuration
            $treeFiles = [
                [
                    'file' => 'content/trees/collections/default/pages.yaml',
                    'testPatterns' => [
                        'foo',
                        'test',
                        'gravity-forms',
                        'formidable-forms',
                        'classic-page',
                        'row-block-page',
                        'details-block-page',
                        'freeform-block-page',
                        'kb-article',
                        'redirect-rule',
                        'basic-page',
                        'landing-page',
                        'form-block-direct-page',
                        'form-shortcode-block-direct-page',
                        'form-shortcode-text-direct-page',
                        'form-block-section-page',
                        'form-shortcode-block-section-page',
                        'form-shortcode-text-content-page',
                        'form-shortcode-gutenberg-direct-page',
                        'form-shortcode-content-direct-page',
                    ],
                    'deleteOnFailure' => false,
                ],
            ];
        } elseif (\is_array($treeFilesConfig)) { // Check if using custom configuration array
            // Use provided custom configuration
            $treeFiles = $treeFilesConfig;
        }

        // Loop through each tree file configuration
        foreach ($treeFiles as $config) {
            $configArray = self::asArray($config);
            $file = self::stringValue($configArray['file'] ?? null);

            if ($file === null) {
                continue;
            }

            // Extract test patterns (default to empty array if not provided)
            /** @var array<int, string> $testPatterns */
            $testPatterns = [];
            foreach (self::asArray($configArray['testPatterns'] ?? []) as $pattern) {
                $patternString = self::stringValue($pattern);
                if ($patternString !== null) {
                    $testPatterns[] = $patternString;
                }
            }

            // Extract deleteOnFailure flag (default to false if not provided)
            $deleteOnFailure = (bool) ($configArray['deleteOnFailure'] ?? false);

            // Check if the file exists before attempting cleanup
            if (\file_exists($file)) {
                // Read the file content to check for test data
                $content = file_get_contents($file);

                if ($content === false) {
                    continue;
                }

                // Initialize flag to track if test data is found
                $hasTestData = false;

                // Loop through test patterns to check if any match the file content
                foreach ($testPatterns as $pattern) {
                    // Check if pattern exists in file content
                    if (\str_contains(
                        $content,
                        $pattern
                    )) {
                        // Mark that test data was found
                        $hasTestData = true;

                        // Exit loop early since we found test data
                        break;
                    }
                }

                // ---
                // Check if file differs from git (handles cases where file is empty or
                // modified but doesn't match test patterns, e.g., tree files cleared
                // during import)
                // ---

                // Check if file is tracked in git and differs from git
                $differsFromGit = false;
                if (self::isWindows()) {
                    // Windows: check if file differs from git
                    exec(
                        'git diff --quiet '.escapeshellarg($file).' 2>nul',
                        $diffOutput,
                        $diffReturnCode
                    );
                    // git diff --quiet returns 0 if files are identical, 1 if they differ
                    $differsFromGit = $diffReturnCode === 1;
                } else { // Otherwise (Unix/Linux)
                    // Unix/Linux: check if file differs from git
                    exec(
                        'git diff --quiet '.escapeshellarg($file).' 2>/dev/null',
                        $diffOutput,
                        $diffReturnCode
                    );
                    // git diff --quiet returns 0 if files are identical, 1 if they differ
                    $differsFromGit = $diffReturnCode === 1;
                }

                // ---
                // Determine if restore should be attempted
                // ---

                // If deleteOnFailure is enabled, always try to restore/delete even if
                // patterns don't match. This handles cases where test files are created
                // but patterns don't match (e.g., tree files use IDs not slugs).
                // Also attempt restore if file differs from git (handles empty/modified
                // files that don't match test patterns).
                $shouldAttemptRestore = $hasTestData || $deleteOnFailure || $differsFromGit;

                // Check if test data was found in the file, if we should attempt restore
                // anyway, or if file differs from git
                if ($shouldAttemptRestore) {
                    // Try to restore from git first (if file exists in git)
                    // Use platform-specific command redirection
                    $returnCode = 0;

                    // Check if running on Windows to use platform-specific command redirection
                    if (self::isWindows()) {
                        // Windows: redirect stderr to nul and capture return code
                        exec(
                            'git restore '.escapeshellarg($file).' 2>nul',
                            $output,
                            $returnCode
                        );
                    } else { // Otherwise (Unix/Linux)
                        // Unix/Linux: redirect stderr to /dev/null and capture return code
                        exec(
                            'git restore '.escapeshellarg($file).' 2>/dev/null',
                            $output,
                            $returnCode
                        );
                    }

                    // If deleteOnFailure is enabled, check if restore failed or test data persists
                    if ($deleteOnFailure) {
                        // Check if git restore failed (file doesn't exist in git) or file still contains test data
                        $shouldDelete = false;
                        if ($returnCode !== 0) {
                            $shouldDelete = true;
                        } elseif (
                            $hasTestData
                            && $testPatterns !== []
                            && \strpos(
                                $content,
                                $testPatterns[0]
                            ) !== false
                        ) {
                            $shouldDelete = true;
                        }

                        if ($shouldDelete) {
                            // Delete the tree file created during tests
                            unlink($file);
                        }
                    }
                }
            }
        }
    }

    /**
     * Create a temporary Gravity Forms JSON export file for testing.
     *
     * @param  int  $formId  The form ID to create (default: 1)
     * @return string The path to the temporary file
     */
    protected function createGravityFormsJsonFile(
        int $formId = 1
    ): string {
        $gravityFormsJson = $this->getGravityFormsJsonContent($formId);
        $jsonPath = $this->createTempFile(
            'test_gravityforms_export_',
            '.json'
        );
        file_put_contents(
            $jsonPath,
            $gravityFormsJson
        );

        return $jsonPath;
    }

    /**
     * Get Gravity Forms JSON export content for testing.
     *
     * @param  int  $formId  The form ID to create
     * @return string The JSON content
     */
    private function getGravityFormsJsonContent(
        int $formId = 1
    ): string {
        $encoded = \json_encode([
            (string) $formId => [
                'id' => $formId,
                'title' => "Test Contact Form {$formId}",
                'description' => 'A simple test form with a single text field.',
                'labelPlacement' => 'top_label',
                'descriptionPlacement' => 'below',
                'maxEntriesMessage' => '',
                'button' => [
                    'type' => 'text',
                    'text' => 'Submit',
                    'imageUrl' => '',
                ],
                'fields' => [
                    [
                        'type' => 'text',
                        'id' => 1,
                        'label' => 'Name',
                        'adminLabel' => '',
                        'isRequired' => true,
                        'size' => 'medium',
                        'errorMessage' => '',
                        'inputs' => null,
                        'labelPlacement' => '',
                        'descriptionPlacement' => '',
                        'subLabelPlacement' => '',
                        'placeholder' => 'Enter your name',
                        'multipleFiles' => false,
                        'maxFiles' => '',
                        'calculationFormula' => '',
                        'calculationRounding' => '',
                        'enableCalculation' => '',
                        'disableQuantity' => false,
                        'displayAllCategories' => false,
                        'inputMask' => false,
                        'inputMaskValue' => '',
                        'allowsPrepopulate' => false,
                        'formId' => $formId,
                        'choices' => '',
                        'conditionalLogic' => '',
                        'displayOnly' => '',
                        'cssClass' => '',
                        'description' => '',
                        'inputMaskIsCustom' => '',
                        'maxLength' => '',
                        'inputName' => '',
                        'noDuplicates' => false,
                        'defaultValue' => '',
                        'enableAutocomplete' => false,
                        'autocompleteAttribute' => '',
                        'visibility' => 'visible',
                        'inputType' => '',
                        'fields' => '',
                    ],
                ],
            ],
        ], JSON_PRETTY_PRINT);

        return \is_string($encoded) ? $encoded : '{}';
    }

    /**
     * Create a temporary FormidableForms XML export file for testing.
     *
     * @param  int  $formId  The form ID to create (default: 1)
     * @return string The path to the temporary file
     */
    protected function createFormidableFormsXmlFile(
        int $formId = 1
    ): string {
        $formidableFormsXml = $this->getFormidableFormsXmlContent($formId);
        $xmlPath = $this->createTempFile('test_formidableforms_export_');
        file_put_contents(
            $xmlPath,
            $formidableFormsXml
        );

        return $xmlPath;
    }

    /**
     * Get FormidableForms XML export content for testing.
     *
     * @param  int  $formId  The form ID to create
     * @return string The XML content
     */
    private function getFormidableFormsXmlContent(
        int $formId = 1
    ): string {
        return '<?xml version="1.0" encoding="UTF-8"?>
<!-- generator="WordPress/6.7.3" created="2023-01-01 12:00" -->

<channel>
    <title>Test FormidableForms Export</title>
    <pubDate>Mon, 01 Jan 2023 12:00:00 +0000</pubDate>

    <form>
        <id>'.$formId.'</id>
        <form_key><![CDATA[test-form-'.$formId.']]></form_key>
        <name><![CDATA[Test Contact Form '.$formId.']]></name>
        <description><![CDATA[A simple test form with a single text field.]]></description>
        <created_at>2023-01-01 12:00:00</created_at>
        <logged_in>0</logged_in>
        <is_template>0</is_template>
        <editable>0</editable>
        <options><![CDATA[{"submit_html":"<div class=\"frm_submit\">\n[if back_button]<button type=\"submit\" name=\"frm_prev_page\" formnovalidate=\"formnovalidate\" class=\"frm_prev_page\" [back_hook]>[back_label]<\/button>[\/if back_button]\n<button class=\"frm_button_submit\" type=\"submit\"  [button_action]>[button_label]<\/button>\n[if save_draft]<a href=\"#\" tabindex=\"0\" class=\"frm_save_draft\" [draft_hook]>[draft_label]<\/a>[\/if save_draft]\n<\/div>"}]]></options>
        <status><![CDATA[published]]></status>
        <parent_form_id>0</parent_form_id>
        <field>
            <id>1</id>
            <field_key><![CDATA[testfield]]></field_key>
            <name><![CDATA[Name]]></name>
            <description><![CDATA[]]></description>
            <type><![CDATA[text]]></type>
            <default_value><![CDATA[]]></default_value>
            <field_order>1</field_order>
            <form_id>'.$formId.'</form_id>
            <required>1</required>
            <options><![CDATA[]]></options>
            <field_options><![CDATA[{"blank":"","classes":"frm_full","custom_html":"<div id=\"frm_field_[id]_container\" class=\"frm_form_field form-field [required_class][error_class]\">\n    <label for=\"field_[key]\" id=\"field_[key]_label\" class=\"frm_primary_label\">[field_name]\n        <span class=\"frm_required\" aria-hidden=\"true\">[required_label]<\/span>\n    <\/label>\n    [input]\n    [if description]<div class=\"frm_description\" id=\"frm_desc_field_[key]\">[description]<\/div>[\/if description]\n    [if error]<div class=\"frm_error\" role=\"alert\" id=\"frm_error_field_[key]\">[error]<\/div>[\/if error]\n<\/div>","in_section":0}]]></field_options>
        </field>
    </form>
</channel>';
    }

    /**
     * Assert that an entry contains a proper form set with the expected form ID.
     * For basic_page blueprints, checks for Bard sets in the 'content' field.
     * For landing_page blueprints, checks the 'page_builder' stream: either a form Bard
     * set inside a `uamsweb_block_content` body, or a dedicated `uamsweb_block_gravityform` /
     * `uamsweb_block_formidable` set from a `wp:shortcode` block (see ShortcodeBlockProcessor).
     *
     * @param  Entry  $entry  The entry to check
     * @param  string  $expectedFormId  The expected form ID
     * @param  string  $pluginName  The plugin name (e.g., 'Formidable Forms', 'Gravity Forms')
     * @param  bool  $formsWereCreated  Whether forms were created during import (affects expected attributes)
     */
    protected function assertEntryContainsFormBardSet(
        Entry $entry,
        string $expectedFormId,
        string $pluginName,
        bool $formsWereCreated = false
    ): void {
        $blueprint = $entry->get('blueprint');

        if ($blueprint === 'landing_page') {
            // For landing pages, inspect page_builder for form output (content blocks and/or plugin blocks)
            $pageBuilder = self::asArray($entry->get('page_builder'));
            self::assertNotEmpty(
                $pageBuilder,
                'Page builder should not be empty for landing_page blueprint'
            );

            // Find a form Bard set nested under any uamsweb_block_content block
            $formSet = null;
            foreach ($pageBuilder as $block) {
                $blockArray = self::asArray($block);
                if (
                    ($blockArray['type'] ?? null) !== 'uamsweb_block_content'
                    || ! isset($blockArray['body'])
                ) {
                    continue;
                }
                foreach (self::asArray($blockArray['body']) as $bodyItem) {
                    $bodyItemArray = self::asArray($bodyItem);
                    $bodyValues = self::asArray(
                        self::asArray($bodyItemArray['attrs'] ?? [])['values'] ?? []
                    );
                    if (
                        ($bodyItemArray['type'] ?? null) === 'set'
                        && (($bodyValues['type'] ?? null) === 'form')
                    ) {
                        $formSet = $bodyItemArray;
                        break 2;
                    }
                }
            }

            // When not inside uamsweb_block_content, wp:shortcode form maps to a dedicated replicator block
            if ($formSet === null) {
                $expectedFormBlockType = match ($pluginName) {
                    'Gravity Forms' => 'uamsweb_block_gravityform',
                    'Formidable Forms' => 'uamsweb_block_formidable',
                    default => null,
                };
                self::assertNotNull(
                    $expectedFormBlockType,
                    "Unsupported plugin name for landing page form block: {$pluginName}"
                );

                $formBlocks = \array_values(
                    \array_filter(
                        $pageBuilder,
                        static fn (mixed $block): bool => (self::asArray($block)['type'] ?? null) === $expectedFormBlockType
                    )
                );
                self::assertCount(
                    1,
                    $formBlocks,
                    "Page builder should contain exactly one {$expectedFormBlockType} block when the form is not in uamsweb_block_content"
                );

                $formBlock = self::asArray($formBlocks[0]);

                // When forms were created and form ID is provided, expect Statamic handle when the
                // shortcode ran after forms were registered; otherwise form_notes may still list
                // the WordPress form ID (import order / static handle cache timing).
                if (
                    $formsWereCreated
                    && $expectedFormId !== ''
                    && \is_numeric($expectedFormId)
                ) {
                    $notes = self::stringValue($formBlock['form_notes'] ?? null) ?? '';
                    $formHandle = self::stringValue($formBlock['form'] ?? null);
                    $hasFormField = $formHandle !== null && $formHandle !== '';
                    $hasHandleInNotes = \str_contains(
                        $notes,
                        'form handle:'
                    );
                    $hasIdInNotes = \str_contains(
                        $notes,
                        "{$pluginName} form ID {$expectedFormId}"
                    );
                    self::assertTrue(
                        $hasFormField
                        || $hasHandleInNotes
                        || $hasIdInNotes,
                        'When forms were created, form block should expose a handle, '
                            .'form handle in form_notes, or WordPress form ID in form_notes'
                    );
                } else {
                    $notes = self::stringValue($formBlock['form_notes'] ?? null) ?? '';
                    self::assertStringContainsString(
                        "{$pluginName} form ID {$expectedFormId}",
                        $notes,
                        "Form block should reference {$pluginName} form ID {$expectedFormId}"
                    );
                }

                return;
            }

            $formSetArray = self::asArray($formSet);
            self::assertEquals(
                'set',
                $formSetArray['type'],
                "Form set should have type 'set'"
            );
            self::assertArrayHasKey(
                'attrs',
                $formSetArray,
                'Form set should have attrs'
            );
            $attrs = self::asArray($formSetArray['attrs']);
            self::assertArrayHasKey(
                'values',
                $attrs,
                'Form set should have values'
            );

            $values = self::asArray($attrs['values']);
            self::assertEquals(
                'form',
                $values['type'],
                "Form set values should have type 'form'"
            );

            // When forms were created, expect Statamic handle when resolved; otherwise notes may
            // still list the WordPress form ID (handle cache timing during import).
            if (
                $formsWereCreated
                && $expectedFormId !== ''
                && \is_numeric($expectedFormId)
            ) {
                $formHandle = self::stringValue($values['form'] ?? null);
                $hasFormField = $formHandle !== null && $formHandle !== '';
                $importNotes = self::stringValue($values['import_wordpress_notes'] ?? null) ?? '';
                $hasIdNotes = \str_contains(
                    $importNotes,
                    "{$pluginName} form ID {$expectedFormId}"
                );
                self::assertTrue(
                    $hasFormField || $hasIdNotes,
                    'When forms were created, nested form set should use Statamic `form` '
                        .'or WordPress form ID in import_wordpress_notes'
                );
                if ($hasFormField) {
                    self::assertArrayNotHasKey(
                        'import_wordpress_notes',
                        $values,
                        'Form set should not have import_wordpress_notes when form handle is available'
                    );
                }
            } else { // Otherwise
                // Fallback to import_wordpress_notes when no forms were created or no valid form ID
                self::assertArrayHasKey(
                    'import_wordpress_notes',
                    $values,
                    'Form set should have import_wordpress_notes when no forms were created or no form ID provided'
                );
                self::assertStringContainsString(
                    "{$pluginName} form ID {$expectedFormId}",
                    self::stringValue($values['import_wordpress_notes'] ?? null) ?? '',
                    "Form set should reference {$pluginName} form ID {$expectedFormId}"
                );
            }
        } else { // Otherwise
            // For basic pages, check content field for Bard sets
            $content = self::asArray($entry->get('content'));
            self::assertNotEmpty(
                $content,
                'Content should not be empty for basic_page blueprint'
            );

            // Find the form set in the content
            $formSet = null;
            foreach ($content as $item) {
                $itemArray = self::asArray($item);
                $itemValues = self::asArray(
                    self::asArray($itemArray['attrs'] ?? [])['values'] ?? []
                );
                if (
                    ($itemArray['type'] ?? null) === 'set'
                    && (($itemValues['type'] ?? null) === 'form')
                ) {
                    $formSet = $itemArray;
                    break;
                }
            }

            self::assertNotNull(
                $formSet,
                'Content should contain a form Bard set for basic_page blueprint'
            );
            self::assertEquals(
                'set',
                $formSet['type'],
                "Form set should have type 'set'"
            );
            self::assertArrayHasKey(
                'attrs',
                $formSet,
                'Form set should have attrs'
            );
            $attrs = self::asArray($formSet['attrs']);
            self::assertArrayHasKey(
                'values',
                $attrs,
                'Form set should have values'
            );

            $values = self::asArray($attrs['values']);
            self::assertEquals(
                'form',
                $values['type'],
                "Form set values should have type 'form'"
            );

            // When forms were created and form ID is provided, expect a form handle instead of import_wordpress_notes
            if (
                $formsWereCreated
                && $expectedFormId !== ''
                && \is_numeric($expectedFormId)
            ) {
                self::assertArrayHasKey(
                    'form',
                    $values,
                    'Form set should have form attribute when forms were created and form ID is provided'
                );
                self::assertArrayNotHasKey(
                    'import_wordpress_notes',
                    $values,
                    'Form set should not have import_wordpress_notes when form handle is available'
                );
                // The form handle should be a string (we'll validate the actual value in specific tests)
                $formHandle = self::stringValue($values['form'] ?? null);
                self::assertNotNull(
                    $formHandle,
                    'Form attribute should be a string'
                );
                self::assertNotSame(
                    '',
                    $formHandle,
                    'Form attribute should not be empty'
                );
            } else { // Otherwise
                // Fallback to import_wordpress_notes when no forms were created or no valid form ID
                self::assertArrayHasKey(
                    'import_wordpress_notes',
                    $values,
                    'Form set should have import_wordpress_notes when no forms were created or no form ID provided'
                );
                self::assertStringContainsString(
                    "{$pluginName} form ID {$expectedFormId}",
                    self::stringValue($values['import_wordpress_notes'] ?? null) ?? '',
                    "Form set should reference {$pluginName} form ID {$expectedFormId}"
                );
            }
        }
    }

    /**
     * Setup mocks for landing page tests.
     */
    protected function setupLandingPageTestMocks(): void
    {
        // Mock the SiteManager to simulate that the 'pages' collection is already enabled for 'default' site
        /** @var SiteManager&MockInterface $siteManagerMock */
        $siteManagerMock = \Mockery::mock(SiteManager::class);
        $siteManagerMock->allows([
            'isCollectionEnabledForSite' => true,
            'enableCollectionForSite' => true,
        ]);

        // Bind the mock to the container
        app()->instance(
            SiteManager::class,
            $siteManagerMock
        );
    }

    /**
     * Setup mocks for basic page tests.
     */
    protected function setupBasicPageTestMocks(): void
    {
        // Mock the SiteManager to simulate that the 'pages' collection is already enabled for 'default' site
        /** @var SiteManager&MockInterface $siteManagerMock */
        $siteManagerMock = \Mockery::mock(SiteManager::class);
        $siteManagerMock->allows([
            'isCollectionEnabledForSite' => true,
            'enableCollectionForSite' => true,
        ]);

        // Bind the mock to the container
        app()->instance(
            SiteManager::class,
            $siteManagerMock
        );
    }

    /**
     * Generate a page item with form content based on variant and page type.
     *
     * @param  string  $variant  The variant type
     * @param  string  $formId  The form ID
     * @param  ?string  $blockAttributes  The block attributes JSON (for block variants)
     * @param  string  $pluginName  The plugin name ('formidable' or 'gravityforms')
     * @param  string  $pageType  The page type ('landing' or 'basic')
     * @param  array<string, mixed>  $options  Additional options
     * @return string The XML item
     */
    private function getPageItemWithFormVariant(
        string $variant,
        string $formId,
        ?string $blockAttributes,
        string $pluginName,
        string $pageType = 'landing',
        array $options = []
    ): string {
        $isLandingPage = $pageType === 'landing';

        $config = $this->mergePageConfig(
            \array_merge(
                [
                    'post_id' => 901,
                    'title' => $isLandingPage ? 'Landing Page' : 'Basic Page',
                    'slug' => 'form-'.\str_replace('_', '-', $variant).'-page',
                    'has_acf_blocks' => $isLandingPage,
                    'content_type' => 'gutenberg',
                ],
                $options
            )
        );

        // Start with appropriate initial content
        $content = $isLandingPage
            ? "<!-- wp:acf/call-out {\"id\":\"block_123\",\"name\":\"acf/call-out\",\"data\":{\"call_out_heading\":\"Contact Us\",\"call_out_body\":\"Get in touch with us for more information about our services and how we can help you.\",\"call_out_background_image\":\"\"}} /-->\n\n"
            : '';

        switch ($variant) {
            case 'block_direct':
                $blockName = $pluginName === 'formidable' ? 'formidable/simple-form' : 'gravityforms/form';
                $content .= "<!-- wp:{$blockName} {$blockAttributes} /-->";
                break;

            case 'block_section':
                if (! $isLandingPage) {
                    throw new \InvalidArgumentException("Variant 'block_section' is not supported for basic pages");
                }
                $blockName = $pluginName === 'formidable' ? 'formidable/simple-form' : 'gravityforms/form';
                $content .= '<!-- wp:acf/uams-section {"id":"block_456","name":"acf/uams-section","data":{"section_heading":"Contact Form","section_body":"Please fill out the form below","section_background_color":"bg-auto"}} -->';
                $content .= "<!-- wp:{$blockName} {$blockAttributes} /-->\n";
                $content .= '<!-- /wp:acf/uams-section -->';
                break;

            case 'shortcode_block_section':
                if (! $isLandingPage) {
                    throw new \InvalidArgumentException("Variant 'shortcode_block_section' is not supported for basic pages");
                }
                $shortcode = $pluginName === 'formidable' ? "[formidable id='{$formId}']" : "[gravityform id=\"{$formId}\"]";
                $content .= '<!-- wp:acf/uams-section {"id":"block_456","name":"acf/uams-section","data":{"section_heading":"Contact Form","section_body":"Please fill out the form below","section_background_color":"bg-auto"}} -->';
                $content .= "<!-- wp:shortcode -->\n{$shortcode}\n<!-- /wp:shortcode -->";
                $content .= '<!-- /wp:acf/uams-section -->';
                break;

            case 'shortcode_text_content':
                if (! $isLandingPage) {
                    throw new \InvalidArgumentException("Variant 'shortcode_text_content' is not supported for basic pages");
                }
                $shortcode = $pluginName === 'formidable' ? "[formidable id='{$formId}']" : "[gravityform id=\"{$formId}\"]";
                $escapedShortcode = \str_replace(
                    '"',
                    '\\"',
                    $shortcode
                );
                $content .= "<!-- wp:acf/uams-content {\"id\":\"block_789\",\"name\":\"acf/uams-content\",\"data\":{\"content_heading\":\"Contact Form\",\"content_content\":\"{$escapedShortcode}\",\"content_background_color\":\"bg-auto\"}} /-->";
                break;

            case 'shortcode_block_direct':
                $shortcode = $pluginName === 'formidable' ? "[formidable id='{$formId}']" : "[gravityform id=\"{$formId}\"]";
                $content .= "<!-- wp:shortcode -->\n{$shortcode}\n<!-- /wp:shortcode -->";
                break;

            case 'shortcode_text_direct':
                $shortcode = $pluginName === 'formidable' ? "[formidable id='{$formId}']" : "[gravityform id=\"{$formId}\"]";
                $content .= $shortcode;
                break;

            default:
                throw new \InvalidArgumentException("Unknown variant: {$variant}");
        }

        $config['content'] = $content;

        $result = $this->generatePageItem($config);

        return $result;
    }

    /**
     * Generate a landing page item with form content based on variant.
     *
     * @param  string  $variant  The variant type
     * @param  string  $formId  The form ID
     * @param  ?string  $blockAttributes  The block attributes JSON (for block variants)
     * @param  string  $pluginName  The plugin name ('formidable' or 'gravityforms')
     * @param  array<string, mixed>  $options  Additional options
     * @return string The XML item
     */
    protected function getLandingPageItemWithFormVariant(
        string $variant,
        string $formId,
        ?string $blockAttributes,
        string $pluginName,
        array $options = []
    ): string {
        return $this->getPageItemWithFormVariant(
            $variant,
            $formId,
            $blockAttributes,
            $pluginName,
            'landing',
            $options
        );
    }

    /**
     * Generate a basic page item with form content based on variant.
     *
     * @param  string  $variant  The variant type
     * @param  string  $formId  The form ID
     * @param  ?string  $blockAttributes  The block attributes JSON (for block variants)
     * @param  string  $pluginName  The plugin name ('gravityforms' or 'formidable')
     * @param  array<string, mixed>  $options  Additional options for the page item
     * @return string The XML item
     */
    protected function getBasicPageItemWithFormVariant(
        string $variant,
        string $formId,
        ?string $blockAttributes,
        string $pluginName,
        array $options = []
    ): string {
        return $this->getPageItemWithFormVariant(
            $variant,
            $formId,
            $blockAttributes,
            $pluginName,
            'basic',
            $options
        );
    }

    /**
     * Assert that an entry contains a Classic block note instead of processed content.
     *
     * This is used when content is detected as Classic block content and replaced
     * with a note.
     *
     * @param  Entry  $entry  The entry to check
     */
    protected function assertEntryContainsClassicBlockNote(
        Entry $entry
    ): void {
        $content = self::asArray($entry->get('content'));
        self::assertNotEmpty(
            $content,
            'Content should not be empty for basic_page blueprint'
        );

        // Check that the first paragraph contains the Classic block note
        $firstParagraph = self::asArray($content[0] ?? null);
        self::assertNotSame(
            [],
            $firstParagraph,
            'First paragraph should exist'
        );
        self::assertEquals(
            'paragraph',
            $firstParagraph['type'],
            "First paragraph should have type 'paragraph'"
        );

        $paragraphContent = self::asArray($firstParagraph['content'] ?? []);
        self::assertNotEmpty(
            $paragraphContent,
            'First paragraph should have content'
        );

        // Check for the "Note:" text
        $noteText = self::asArray($paragraphContent[0] ?? null);
        self::assertNotSame(
            [],
            $noteText,
            'Note text should exist'
        );
        self::assertEquals(
            'text',
            $noteText['type'],
            "Note text should have type 'text'"
        );
        self::assertEquals(
            'Note:',
            $noteText['text'],
            "Note text should be 'Note:'"
        );

        // Check for the Classic block message
        $messageText = self::asArray($paragraphContent[1] ?? null);
        self::assertNotSame(
            [],
            $messageText,
            'Message text should exist'
        );
        self::assertEquals(
            'text',
            $messageText['type'],
            "Message text should have type 'text'"
        );
        self::assertStringContainsString(
            "Classic block content which hasn't been mapped to Statamic Bard",
            self::stringValue($messageText['text'] ?? null) ?? '',
            'Message should mention Classic block content'
        );
    }

    /**
     * Clear temporary data used during tests.
     * This method should be called from tearDown() in test classes.
     */
    protected function clearTestData(): void
    {
        // Clear any temporary data
        ImportWarningManager::clearWarnings();
        SiteResolver::clearSelectedSite();
    }

    /**
     * Check if the current platform is Windows
     *
     * @return bool True if running on Windows, false otherwise
     */
    protected static function isWindows(): bool
    {
        return \str_starts_with(
            \strtolower(PHP_OS),
            'win'
        );
    }

    /**
     * Create a temporary file in the storage/temp directory for better process
     * isolation compatibility.
     *
     * @param  string  $prefix  The prefix for the temporary file
     * @param  string  $extension  The file extension (including the dot)
     * @return string The path to the created temporary file
     */
    protected function createTempFile(
        string $prefix,
        string $extension = '.xml'
    ): string {
        // ---
        // Delegated rather than inlined: `tempnam()` creates a file the caller
        // never writes to, so the helper leaked one path per call regardless of
        // how carefully the caller cleaned up. Putting the create-and-sweep pair
        // on one class is also what makes it testable in the standalone gate —
        // this trait is 3,500 lines of host-bound helpers, and composing it into
        // a bare probe class to reach nine lines of `tempnam()` handling drags
        // every one of them into static analysis (wordpress-importer-archive#783).
        // ---
        return TemporaryFileRegistry::create($prefix, $extension);
    }

    /**
     * Get all available sites from Statamic's actual configuration.
     *
     * This provides access to the real site configuration for more comprehensive
     * testing.
     *
     * @return array<string, string> Array of site handles mapped to site names
     */
    protected function getAvailableSites(): array
    {
        try {
            // Get the actual sites from Statamic
            $sites = Site::all();

            if ($sites instanceof \Illuminate\Support\Collection && $sites->isNotEmpty()) {
                /** @var array<string, string> $siteMap */
                $siteMap = $sites->mapWithKeys(
                    static function (mixed $site): array {
                        if (! \is_object($site) || ! \method_exists($site, 'handle') || ! \method_exists($site, 'name')) {
                            return [];
                        }

                        $handle = self::stringValue($site->handle());
                        $name = self::stringValue($site->name());
                        if ($handle === null || $name === null) {
                            return [];
                        }

                        return [$handle => $name];
                    }
                )->all();

                return $siteMap;
            }

            // Fallback to sites.yaml if Site facade doesn't work in tests
            $sitesConfigPath = resource_path('sites.yaml');
            if (\file_exists($sitesConfigPath)) {
                $sitesConfig = self::parseYamlFile($sitesConfigPath);
                /** @var array<string, string> $siteMap */
                $siteMap = collect($sitesConfig)->mapWithKeys(
                    static function (mixed $config, mixed $handle): array {
                        $handleString = self::stringValue($handle) ?? (string) $handle;
                        $configArray = self::asArray($config);

                        return [
                            $handleString => self::stringValue($configArray['name'] ?? null) ?? $handleString,
                        ];
                    }
                )->all();

                return $siteMap;
            }
        } catch (\Exception $e) {
            // If anything fails, fall back to a reasonable default
        }

        return ['default' => 'Default Site'];
    }

    /**
     * Generate XML using a callback function and create a mock entry for testing.
     *
     * This method calls the provided callback to generate an XML file, then uses
     * the production XML parsing and entry creation logic to create a realistic
     * mock entry. This ensures we test against the actual production data structure
     * without running the full import command.
     *
     * @param  array<string, mixed>  $xmlParameters  XML parameters for generating test XML
     * @param  callable(array<string, mixed>): string  $xmlGeneratorCallback  Callback function that generates XML file path from parameters
     * @return Entry The mock entry
     */
    protected function generateAndImportFromXmlCallback(
        array $xmlParameters,
        callable $xmlGeneratorCallback
    ): Entry {
        // Create a cache key from this test file's class and the XML parameters, to avoid recreating entries
        $cacheKey = static::class.'|'.$this->generateCacheKey($xmlParameters);

        // Check if we already have cached entry data for this test case
        if (isset(self::$cachedEntryData[$cacheKey])) {
            /** @var array<string, mixed> $xmlData */
            $xmlData = self::$cachedEntryData[$cacheKey];
        } else { // Otherwise
            // Call the provided callback to generate XML file
            $xmlPath = $xmlGeneratorCallback($xmlParameters);

            // Validate that the callback returned a valid file path
            self::assertFileExists(
                $xmlPath,
                'Generated XML file should exist'
            );

            // Use production classes to parse XML and create entry data
            $xmlData = $this->createEntryDataFromXml(
                $xmlPath,
                $xmlParameters
            );

            // Cache the entry data for reuse
            self::$cachedEntryData[$cacheKey] = $xmlData;

            // Clean up the temporary XML file
            if (\file_exists($xmlPath)) {
                unlink($xmlPath);
            }
        }

        // Create a mock entry that simulates what would be created by the import
        /** @var Entry&MockInterface $mockEntry */
        $mockEntry = \Mockery::mock(Entry::class);
        $mockEntry->allows([
            'data' => $xmlData,
            'id' => $xmlData['id'] ?? null,
            'slug' => $xmlData['slug'] ?? null,
            'title' => $xmlData['title'] ?? null,
        ]);

        // Statamic 6 reads fields through Entry::get(); back it with the same parsed
        // data so callers such as $entry->get('title') receive real values instead of
        // tripping Mockery's "no expectations were specified" on an unstubbed method.
        $this->singleMethodExpectation($mockEntry, 'get')->andReturnUsing(
            static fn (string $key, mixed $default = null): mixed => $xmlData[$key] ?? $default
        );

        return $mockEntry;
    }

    /**
     * Return the sole {@see Expectation} from a mock's shouldReceive() call.
     *
     * Mockery's shouldReceive() yields a CompositeExpectation wrapping a single
     * Expectation; unwrap it so chainable methods such as andReturnUsing() are
     * statically visible to PHPStan. Mirrors the reflection approach used in
     * TermCreationInNonDefaultSitesTest.
     *
     * @param  MockInterface  $mock  The Mockery mock
     * @param  string  $method  The method name to stub
     * @return Expectation The single underlying expectation
     */
    protected function singleMethodExpectation(
        MockInterface $mock,
        string $method
    ): Expectation {
        $composite = $mock->shouldReceive($method);
        self::assertInstanceOf(
            CompositeExpectation::class,
            $composite
        );

        $property = (new ReflectionClass(CompositeExpectation::class))
            ->getProperty('_expectations');
        $property->setAccessible(true);
        $expectations = $property->getValue($composite);
        self::assertIsArray($expectations);

        $expectation = reset($expectations);
        self::assertInstanceOf(
            Expectation::class,
            $expectation
        );

        return $expectation;
    }

    /**
     * Generate a cache key based on XML parameters.
     *
     * @param  array<string, mixed>  $xmlParameters  The XML parameters to create a cache key from
     * @return string The cache key
     */
    private function generateCacheKey(
        array $xmlParameters
    ): string {
        // Create a unique cache key based on the XML parameters
        // This ensures we cache different entries for different post types/configurations
        $keyParts = [];
        foreach ($xmlParameters as $key => $value) {
            $keyParts[] = $key.':'.(\is_string($value) ? $value : \json_encode($value));
        }

        return \md5(
            \implode(
                '|',
                $keyParts
            )
        );
    }

    /**
     * Create entry data from XML using production classes.
     *
     * This method uses the actual production XML parsing and entry creation logic
     * to create realistic entry data, ensuring our tests use the same data structure
     * as the production import process.
     *
     * @param  string  $xmlPath  Path to the XML file to parse
     * @param  array<string, mixed>  $xmlParameters  XML parameters for determining content type
     * @return array<int|string, mixed> The entry data created by production logic
     */
    private function createEntryDataFromXml(
        string $xmlPath,
        array $xmlParameters
    ): array {
        // Create production XML parser and parse the file
        $xmlParser = new XmlParser;
        $xmlParser->parse($xmlPath);

        // Determine content type from parameters
        $contentType = self::stringValue($xmlParameters['postType'] ?? null) ?? 'page';

        // Create shared dependencies for testing production behavior
        $assetImporter = new AssetImporter;
        $output = new NullOutput;
        $metadataMapper = new MetadataMapper;
        $contentProcessor = new ContentProcessor;
        $siteManager = new SiteManager;
        $collectionStructureManager = new CollectionStructureManager;
        $treeBuilder = new TreeBuilder(
            new CollectionTreeRepository(
                app(Stache::class)
            )
        );
        $entryCreatorBase = new EntryCreator(
            $metadataMapper,
            $contentProcessor,
            $siteManager,
            $collectionStructureManager,
            $treeBuilder
        );

        // Create appropriate entry creator and collection handle based on content type
        $entryCreator = null;
        $collectionHandle = null;

        switch ($contentType) {
            case 'logo-list-item':
                $entryCreator = new LogoListItemEntryCreator(
                    $siteManager,
                    $collectionStructureManager
                );
                $collectionHandle = 'logo_list_items';
                break;
            case 'post':
                $entryCreator = new PostEntryCreator;
                $collectionHandle = 'posts';
                break;
            case 'uamspeople':
                $entryCreator = new PeopleEntryCreator;
                $collectionHandle = 'people';
                break;
            case 'page':
            default:
                $entryCreator = new PageEntryCreator($entryCreatorBase);
                $collectionHandle = 'pages';
                break;
        }

        // Check if an entry with the expected slug already exists
        $expectedSlug = $this->getUniqueSlugForEntryVariant(
            $contentType,
            self::stringValue($xmlParameters['entryVariantType'] ?? null) ?? ''
        );
        $collection = Collection::find($collectionHandle);
        if ($collection === null) {
            self::fail("Collection '{$collectionHandle}' was not found while creating entry data from XML.");
        }

        $existingEntry = self::findEntryBySlug(
            $collection,
            'default',
            $expectedSlug
        );

        if ($existingEntry instanceof Entry) {
            return $this->entryDataFromEntry($existingEntry);
        }

        // Call the production createEntries method
        $entryCreator->createEntries(
            'default', // site
            $collectionHandle, // collection handle based on content type
            $xmlParser,
            $assetImporter,
            $output,
            $metadataMapper,
            $contentProcessor
        );

        // The production method returns page data, not entry data
        // We need to get the actual entry from Statamic using the expected slug
        $createdEntry = self::findEntryBySlug(
            $collection,
            'default',
            $expectedSlug
        );

        if (! $createdEntry instanceof Entry) {
            self::fail("Production createEntries method did not create an entry with slug '{$expectedSlug}'.");
        }

        return $this->entryDataFromEntry($createdEntry);
    }

    /**
     * Test individual YAML field validation using production methods.
     *
     * Each field is tested separately using real production output to make it easier
     * to identify which specific fields are failing during import validation.
     *
     * @param  string  $field  The name of the field to test
     * @param  mixed  $value  The expected value for the field (not used in production test)
     * @param  string  $type  The expected data type for the field
     * @param  array<string, mixed>  $xmlParameters  XML parameters for generating test XML (optional)
     * @param  ?callable(array<string, mixed>): string  $xmlGeneratorCallback  Callback function to generate XML from parameters (optional)
     * @param  string  $imageFieldName  The name of the image field for skip message (e.g., 'featured_image',
     *                                  'portrait')
     */
    protected function testIndividualYamlFieldValidation(
        string $field,
        mixed $value,
        string $type,
        array $xmlParameters = [],
        ?callable $xmlGeneratorCallback = null,
        string $imageFieldName = 'featured_image'
    ): void {
        // Use the parameters directly (PHPUnit maps associative array keys to method parameters)
        $fieldName = $field;
        $expectedValue = $value;
        $expectedType = $type;

        // Check if WordPress image imports are disabled and warn about potential test impact
        if (
            config(
                'wordpress-import.upload_images',
                true
            ) === false
        ) {
            Assert::markTestSkipped('WORDPRESS_IMPORT_IMAGES is set to false in .env. This may impact test outcomes, particularly for image and/or file field validation.');
        }

        // Generate XML and import it using the provided parameters and callback
        if ($xmlGeneratorCallback !== null) {
            $entry = $this->generateAndImportFromXmlCallback(
                $xmlParameters,
                $xmlGeneratorCallback
            );
        } else { // Otherwise
            // If no callback is provided, throw an error
            self::fail('XML generator callback is required. Please provide a callback function to generate XML from the parameters.');
        }

        $entryData = self::asArray($entry->data());

        // Test that the field exists in the actual production output
        // For nested fields (dot notation), check if the nested structure exists
        if (
            \strpos(
                $fieldName,
                '.'
            ) !== false
        ) {
            $actualValue = $this->getNestedValue(
                $entryData,
                $fieldName
            );
            self::assertNotNull(
                $actualValue,
                "Field '{$fieldName}' should exist in production output"
            );
        } else { // Otherwise
            self::assertArrayHasKey(
                $fieldName,
                $entryData,
                "Field '{$fieldName}' should exist in production output"
            );
        }

        // Extract the actual value for the field being tested
        // Support dot notation for nested field access (e.g., 'page_builder.0.type')
        $actualValue = $this->getNestedValue(
            $entryData,
            $fieldName
        );

        // Process placeholders in expected value before comparison
        $processedExpectedValue = $this->processPlaceholders(
            $expectedValue,
            $actualValue
        );

        // Validate the field based on its expected data type
        switch ($expectedType) {
            // Handle string field validation
            case 'string':
                // Verify the field value is a string
                self::assertIsString(
                    $actualValue,
                    "Field '{$fieldName}' should be a string"
                );

                // Verify the field value matches the expected value
                self::assertEquals(
                    $processedExpectedValue,
                    $actualValue,
                    "Field '{$fieldName}' should have expected value"
                );

                // Exit the switch statement
                break;

                // Handle integer field validation
            case 'integer':
                // Verify the field value is an integer
                self::assertIsInt(
                    $actualValue,
                    "Field '{$fieldName}' should be an integer"
                );

                // Verify the field value matches the expected value
                self::assertEquals(
                    $processedExpectedValue,
                    $actualValue,
                    "Field '{$fieldName}' should have expected value"
                );

                // Exit the switch statement
                break;

                // Handle boolean field validation
            case 'boolean':
                // Verify the field value is a boolean
                self::assertIsBool(
                    $actualValue,
                    "Field '{$fieldName}' should be a boolean"
                );

                // Verify the field value matches the expected value
                self::assertEquals(
                    $processedExpectedValue,
                    $actualValue,
                    "Field '{$fieldName}' should have expected value"
                );

                // Exit the switch statement
                break;

                // Handle array field validation
            case 'array':
                // Verify the field value is an array
                self::assertIsArray(
                    $actualValue,
                    "Field '{$fieldName}' should be an array"
                );

                // Normalize ProseMirror/Bard node attrs so comparison is semantic (actual
                // may have attrs with id=>null on nodes that expected omits)
                $normalizedActual = $this->stripProseMirrorAttrs(
                    self::asArray($actualValue)
                );
                $normalizedExpected = $this->stripProseMirrorAttrs(
                    self::asArray($processedExpectedValue)
                );

                // ---
                // Dump the pre-normalization values only when the assertion below
                // actually fails.
                //
                // The gate used to be `$normalizedExpected !== $normalizedActual`
                // while the assertion is `assertEquals`, which ignores key order and
                // compares scalars loosely. Any ordering or type difference inside a
                // normalized Bard tree therefore satisfied the assertion and still
                // printed a block headed "comparison failed" — 46 of them in one
                // passing run of the host band (wordpress-importer-archive#616), each reading exactly like a
                // regression.
                //
                // Catching the failure instead of predicting it means the two can
                // never disagree again: the trigger *is* the assertion.
                // ---
                try {
                    // Verify the field value matches the expected value
                    self::assertEquals(
                        $normalizedExpected,
                        $normalizedActual,
                        "Field '{$fieldName}' should have expected value"
                    );
                } catch (ExpectationFailedException $comparisonFailure) {
                    // The raw values are worth showing: assertEquals diffs the
                    // normalized pair, which hides what arrived before normalization
                    echo "\n=== DEBUG: Field '{$fieldName}' comparison failed ===\n";
                    echo "Expected value:\n";
                    var_dump($processedExpectedValue);
                    echo "\nActual value:\n";
                    var_dump($actualValue);
                    echo "==========================================\n\n";

                    throw $comparisonFailure;
                }

                // Exit the switch statement
                break;

                // Handle unknown field types
            default:
                // Fail the test for unsupported field types
                self::fail("Unknown expected type '{$expectedType}' for field '{$fieldName}'");
        }
    }

    /**
     * Validate every YAML field for a single import variant within one app boot.
     *
     * Delegates to {@see self::testIndividualYamlFieldValidation()} per field. The
     * underlying import is cached by XML parameters (see
     * {@see self::generateAndImportFromXmlCallback()}), so the variant is parsed once
     * and every field is asserted against that same result. This collapses what was
     * one full test — and one Statamic application boot — per field into one per
     * variant, which is what made these data-provider-heavy files take tens of
     * minutes and appear to hang (wordpress-importer-archive#391).
     *
     * @param  array<int|string, mixed>  $fieldAssertions  List of ['field' => string, 'value' => mixed, 'type' => string]
     * @param  array<string, mixed>  $xmlParameters  XML parameters shared by every field of the variant
     * @param  callable(array<string, mixed>): string  $xmlGeneratorCallback  Generates the variant's XML from the parameters
     */
    protected function testYamlFieldsForVariant(
        array $fieldAssertions,
        array $xmlParameters,
        callable $xmlGeneratorCallback
    ): void {
        foreach ($fieldAssertions as $assertion) {
            if (! \is_array($assertion)) {
                self::fail('Each field assertion must be an array.');
            }

            $field = $assertion['field'] ?? null;
            $type = $assertion['type'] ?? null;

            if (! \is_string($field) || ! \is_string($type)) {
                self::fail('Each field assertion needs string "field" and "type" entries.');
            }

            $this->testIndividualYamlFieldValidation(
                $field,
                $assertion['value'] ?? null,
                $type,
                $xmlParameters,
                $xmlGeneratorCallback
            );
        }
    }

    /**
     * Get a nested value from an array using dot notation.
     *
     * @param  array<int|string, mixed>  $data  The data array to extract from
     * @param  string  $fieldName  The field name with dot notation (e.g., 'page_builder.0.type')
     * @return mixed The nested value or null if not found
     */
    protected function getNestedValue(
        array $data,
        string $fieldName
    ): mixed {
        // If no dot notation, return the direct value
        if (
            \strpos(
                $fieldName,
                '.'
            ) === false
        ) {
            return $data[$fieldName] ?? null;
        }

        // Split by dots and traverse the array
        $keys = \explode(
            '.',
            $fieldName
        );
        $value = $data;

        foreach ($keys as $key) {
            if (! \is_array($value)) {
                return null;
            }

            // Handle numeric keys for arrays
            if (\is_numeric($key)) {
                $key = (int) $key;
            }

            if (! \array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        return $value;
    }

    /**
     * Process placeholder patterns in expected values for dynamic content validation.
     *
     * Supports patterns like:
     *   - '#rowId()' - expects an 8-character random ID
     *   - '#rowId(8)' - expects an 8-character random ID
     *   - '#rowId(12)' - expects a 12-character random ID
     *
     * @param  mixed  $expectedValue  The expected value that may contain placeholders
     * @param  mixed  $actualValue  The actual value to extract dynamic content from
     * @return mixed The processed expected value with placeholders replaced
     */
    protected function processPlaceholders(
        mixed $expectedValue,
        mixed $actualValue
    ): mixed {
        // Handle arrays recursively
        if (
            \is_array($expectedValue)
            && \is_array($actualValue)
        ) {
            $processed = [];
            foreach ($expectedValue as $key => $value) {
                $processed[$key] = $this->processPlaceholders(
                    $value,
                    $actualValue[$key] ?? null
                );
            }

            return $processed;
        }

        // Handle string placeholders
        if (
            \is_string($expectedValue)
            && \is_string($actualValue)
        ) {
            // Pattern: #rowId() or #rowId(8) or #rowId(12)
            if (
                \preg_match(
                    '/^#rowId(\((\d+)\))?$/',
                    $expectedValue,
                    $matches
                ) === 1
            ) {
                $expectedLength = isset($matches[2]) ? (int) $matches[2] : 8;
                $actualLength = \strlen($actualValue);

                // Verify the actual value has the expected length
                self::assertEquals(
                    $expectedLength,
                    $actualLength,
                    "Expected #rowId({$expectedLength}) but got string of length {$actualLength}"
                );

                // Return the actual value to match against itself
                return $actualValue;
            }
        }

        // Return unchanged if no placeholder pattern matched
        return $expectedValue;
    }

    /**
     * Get the unique slug for a given entry variant type.
     *
     * @param  string  $postType  The post type
     * @param  string  $entryVariantType  The entry variant type
     * @return string The unique slug for the entry variant type
     */
    protected function getUniqueSlugForEntryVariant(
        string $postType,
        string $entryVariantType
    ): string {
        $slugMap = [
            'post' => [
                'standard_v01' => 'standard-post-entry-v01',
                'standard_v02' => 'standard-post-entry-v02',
                'standard_v03' => 'standard-post-entry-v03',
                'standard_v04' => 'standard-post-entry-v04',
                'standard_v05' => 'standard-post-entry-v05',
                'standard_v06' => 'standard-post-entry-v06',
                'standard_v07' => 'standard-post-entry-v07',
                'standard_v08' => 'standard-post-entry-v08',
                'standard_v09' => 'standard-post-entry-v09',
                'news_release_v01' => 'news-release-post-entry-v01',
                'news_release_v02' => 'news-release-post-entry-v02',
                'audio_v01' => 'audio-post-entry-v01',
                'audio_v02' => 'audio-post-entry-v02',
                'audio_v03' => 'audio-post-entry-v03',
                'audio_v04' => 'audio-post-entry-v04',
                'audio_v05' => 'audio-post-entry-v05',
                'audio_v06' => 'audio-post-entry-v06',
                'audio_v07' => 'audio-post-entry-v07',
                'audio_v08' => 'audio-post-entry-v08',
                'audio_v09' => 'audio-post-entry-v09',
                'audio_v10' => 'audio-post-entry-v10',
                'audio_v11' => 'audio-post-entry-v11',
                'audio_v12a' => 'audio-post-entry-v12a',
                'audio_v12b' => 'audio-post-entry-v12b',
                'audio_v12c' => 'audio-post-entry-v12c',
                'audio_v12d' => 'audio-post-entry-v12d',
                'audio_v12e' => 'audio-post-entry-v12e',
                'audio_v12f' => 'audio-post-entry-v12f',
                'audio_v12g' => 'audio-post-entry-v12g',
                'audio_v12h' => 'audio-post-entry-v12h',
                'audio_v12i' => 'audio-post-entry-v12i',
                'audio_v13' => 'audio-post-entry-v13',
                'video_v01' => 'video-post-entry-v01',
                'video_v02' => 'video-post-entry-v02',
                'gallery_v01' => 'gallery-post-entry-v01',
                'gallery_v02' => 'gallery-post-entry-v02',
            ],
            'page' => [
                'landing' => 'landing-page-entry',
                'basic' => 'basic-page-entry',
                'logo_list_v01' => 'logo-list-page-entry-v01',
                'logo_list_v02' => 'logo-list-page-entry-v02',
                'logo_list_v03a' => 'logo-list-page-entry-v03a',
                'logo_list_v03b' => 'logo-list-page-entry-v03b',
                'logo_list_v04' => 'logo-list-page-entry-v04',
                'logo_list_v05' => 'logo-list-page-entry-v05',
                'logo_list_v06' => 'logo-list-page-entry-v06',
                'logo_list_v07' => 'logo-list-page-entry-v07',
                'logo_list_v08a' => 'logo-list-page-entry-v08a',
                'logo_list_v08b' => 'logo-list-page-entry-v08b',
                'logo_list_v09' => 'logo-list-page-entry-v09',
                'logo_list_v10' => 'logo-list-page-entry-v10',
                'logo_list_v11' => 'logo-list-page-entry-v11',
                'logo_list_v12' => 'logo-list-page-entry-v12',
                'logo_list_v13' => 'logo-list-page-entry-v13',
                'logo_list_v14a' => 'logo-list-page-entry-v14a',
                'logo_list_v14b' => 'logo-list-page-entry-v14b',
                'logo_list_v14c' => 'logo-list-page-entry-v14c',
                'logo_list_v14d' => 'logo-list-page-entry-v14d',
                'logo_list_v14e' => 'logo-list-page-entry-v14e',
                'logo_list_v14f' => 'logo-list-page-entry-v14f',
                'logo_list_v15a' => 'logo-list-page-entry-v15a',
                'logo_list_v15b' => 'logo-list-page-entry-v15b',
                'logo_list_v15c' => 'logo-list-page-entry-v15c',
                'logo_list_v16' => 'logo-list-page-entry-v16',
                'logo_list_v17a' => 'logo-list-page-entry-v17a',
                'logo_list_v17b' => 'logo-list-page-entry-v17b',
            ],
            'uamspeople' => [
                'standard' => 'people-entry',
            ],
            'kb' => [
                'article' => 'article-entry',
                'topic' => 'topic-entry',
                'knowledge_base' => 'knowledge-base-entry',
            ],
            'tablepress_table' => [
                'table' => 'table-entry',
            ],
            'logo-list-item' => [
                'standard_v01' => 'standard-logo-list-item-entry-v01',
            ],
        ];

        return $slugMap[$postType][$entryVariantType] ?? 'sample-entry';
    }

    /**
     * Strip ProseMirror/Bard node attrs that are empty or only id=>null so
     * comparison is semantic (actual content may include these, expected may not).
     *
     * @param  array<int|string, mixed>  $array  Bard/ProseMirror content array
     * @return array<int|string, mixed> Normalized array with attrs removed where redundant
     */
    private function stripProseMirrorAttrs(
        array $array
    ): array {
        $result = [];

        foreach ($array as $key => $value) {
            if ($key === 'attrs' && \is_array($value)) {
                /** @var array<string, mixed> $attrs */
                $attrs = $value;
                // Drop redundant null/empty ProseMirror node attrs the importer emits but
                // the expected structures omit: `id` on paragraph/code nodes and
                // `title`/`rel` on link marks all default to null and carry no meaning.
                foreach (['id', 'title', 'rel'] as $redundantAttr) {
                    if (
                        \array_key_exists($redundantAttr, $attrs)
                        && (
                            $attrs[$redundantAttr] === null
                            || $attrs[$redundantAttr] === ''
                        )
                    ) {
                        unset($attrs[$redundantAttr]);
                    }
                }

                // Remove the attrs key entirely when nothing meaningful remains
                if ($attrs === []) {
                    continue;
                }

                $value = $attrs;
            }

            $result[$key] = \is_array($value)
                ? $this->stripProseMirrorAttrs(self::asArray($value))
                : $value;
        }

        return $result;
    }
}
