<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors;

use Statamic\Fieldtypes\RowId;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\PostCategoryEntryResolver;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\NewsBlockSourceSites;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\Traits\DefaultTiptapHtmlTrait;

/**
 * Processes WordPress blogroll shortcodes into Statamic uamsweb_block_news
 * page_builder sets.
 *
 * Maps [blogroll ...] attributes (style, image, date, author, category,
 * category_name) to uamsweb_block_news replicator sets. Treats blogroll like
 * wp:rss and ACF blocks for page builder detection and flow.
 */
class BlogrollShortcodeProcessor implements ShortcodeProcessorInterface
{
    use DefaultTiptapHtmlTrait;

    /**
     * Parse a WordPress blogroll shortcode for Bard (placeholder).
     *
     * When content is processed for Bard, returns a minimal set so
     * toTiptapHtml can produce placeholder HTML. Primary output is
     * toPageBuilderBlock.
     *
     * @param  array<int|string, mixed>  $attributes  Parsed shortcode attributes
     * @param  string  $site  The site handle for context
     * @param  AssetImporter  $assetImporter  Instance for importing linked files (unused)
     * @param  OutputInterface  $output  Console output for logging (unused)
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects (unused)
     * @param  ?string  $pageSlug  The slug of the page being processed (unused)
     * @return array<int|string, mixed> Minimal set for Bard placeholder
     */
    public function parse(
        array $attributes,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $terms = [],
        ?string $pageSlug = null
    ): array {
        // Return minimal placeholder for Bard; primary output is toPageBuilderBlock
        return [
            'type' => 'news',
            'format' => $this->mapStyleToFormat(
                $this->styleAttribute($attributes)
            ),
        ];
    }

    /**
     * Build a page builder block for a blogroll shortcode.
     *
     * Maps shortcode attributes to uamsweb_block_news set. Uses style for
     * format, image/date/author for hide_* flags, and category/category_name for
     * include_link and the category selection.
     *
     * This is the third writer of a `uamsweb_block_news` set, alongside
     * `NewsParser::parse()` and `NewsParser::parseModule()`, so it tracks the
     * same reshaped fieldset (`UAMS-Web/uams-statamic-archive#76`): `source_sites`
     * replaces `local`, `advanced_cat` is gone, and `category` holds
     * `post_categories` entry ids rather than a slug.
     *
     * @param  array<int|string, mixed>  $attributes  Parsed shortcode attributes
     * @param  string  $site  The site handle for context
     * @param  AssetImporter  $assetImporter  Instance for importing linked files (unused)
     * @param  OutputInterface  $output  Console output for logging (unused)
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects (unused)
     * @param  ?string  $pageSlug  The slug of the page being processed (unused)
     * @return array<int|string, mixed> The uamsweb_block_news replicator set
     */
    public function toPageBuilderBlock(
        array $attributes,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $terms = [],
        ?string $pageSlug = null
    ): array {
        // Map shortcode style to uamsweb_block_news format
        $format = $this->mapStyleToFormat(
            $this->styleAttribute($attributes)
        );

        // Derive hide_img from shortcode image attribute
        $hideImg = $this->attributeImpliesHide($attributes['image'] ?? null);

        // Derive hide_date from shortcode date attribute
        $hideDate = $this->attributeImpliesHide($attributes['date'] ?? null);

        // Derive hide_creator_query from shortcode author attribute
        $hideAuthor = $this->attributeImpliesHide($attributes['author'] ?? null);

        // Read category attribute (numeric ID or slug)
        $categoryValue = $attributes['category'] ?? null;

        // Read category_name attribute (slug)
        $categoryNameValue = $attributes['category_name'] ?? null;

        // Derive include_link from category or category_name presence
        $includeLink = $this->categoryImpliesIncludeLink(
            $categoryValue,
            $categoryNameValue
        );

        // Build base uamsweb_block_news set per fieldset and user requirements
        $result = [
            'id' => (new RowId)->generate(),
            'type' => 'uamsweb_block_news',
            'format' => $format,
            'block_heading' => 'Placeholder Block Heading — Please Replace',
            'hide_block_heading' => false,
            'block_description' => null,
            // A blogroll is always the placement's own posts, so it selects both
            // the news site and the current one.
            'source_sites' => NewsBlockSourceSites::forLocalFlag(true),
            'offset' => 0,
            'hide_img' => $hideImg,
            'hide_date' => $hideDate,
            'hide_creator_query' => $hideAuthor,
            'include_link' => $includeLink,
            'background_color' => 'bg-auto',
            'enabled' => true,
        ];

        // ---
        // Resolve the category selection to post_categories entry ids
        // ---

        // Prefer category_name, which is always a slug; fall back to category,
        // which is a slug only when it is not a bare WordPress term id.
        $categorySlugs = $this->categorySlugValue(
            $categoryValue,
            $categoryNameValue,
            $site
        );

        // Resolve the slug(s) on this site; the resolver reports any miss
        $categoryReferences = PostCategoryEntryResolver::referencesFor(
            $categorySlugs,
            $site
        );

        // Only set the field when something actually resolved
        if ($categoryReferences !== []) {
            $result['category'] = $categoryReferences;
        }

        // Return the uamsweb_block_news replicator set
        return $result;
    }

    /**
     * Reduce the shortcode's two category attributes to a slug-shaped value.
     *
     * `category_name` is always a slug and wins when both are present, matching
     * the precedence this processor has always applied. `category` is a slug
     * only when it is not numeric — a bare number is a WordPress term id, which
     * used to be parked in the now-removed `advanced_cat` field and cannot be
     * resolved here, because the term it names belongs to a taxonomy pass this
     * shortcode has no handle for.
     *
     * @param  mixed  $categoryValue  The shortcode `category` attribute
     * @param  mixed  $categoryNameValue  The shortcode `category_name` attribute
     * @param  string  $site  The Statamic site handle being imported into
     * @return string The slug (or comma-separated slugs), or an empty string
     */
    private function categorySlugValue(
        mixed $categoryValue,
        mixed $categoryNameValue,
        string $site
    ): string {
        // `category_name` is unambiguously a slug, so it takes precedence
        if (
            \is_scalar($categoryNameValue)
            && $categoryNameValue !== ''
        ) {
            return (string) $categoryNameValue;
        }

        // A non-numeric `category` is a slug; a numeric one is a term id
        if (\is_scalar($categoryValue)) {
            if (! \is_numeric($categoryValue)) {
                return (string) $categoryValue;
            }

            // A bare term id has nowhere to go now that `advanced_cat` is gone,
            // so report it rather than letting the filter vanish unannounced.
            ImportWarningManager::addWarning(
                'content',
                'unresolvable_blogroll_category_id',
                [
                    'site' => $site,
                    'wordpress_term_id' => (string) $categoryValue,
                    'page_url' => ProcessingContext::getWordPressPageLink(),
                    'reason' => 'The [blogroll] `category` attribute is a WordPress term id, which the '
                        .'reshaped uamsweb_block_news fieldset has no field for — it used to be stored '
                        .'in the retired `advanced_cat`. The block will render every news post; set its '
                        .'category in the Control Panel.',
                ]
            );
        }

        return '';
    }

    /**
     * Resolve the shortcode style attribute as a string.
     *
     * @param  array<int|string, mixed>  $attributes  Parsed shortcode attributes
     * @return string Style attribute value or default
     */
    private function styleAttribute(
        array $attributes
    ): string {
        $style = $attributes['style'] ?? 'default';

        if (\is_scalar($style)) {
            return (string) $style;
        }

        return 'default';
    }

    /**
     * Map shortcode style attribute to uamsweb_block_news format.
     *
     * @param  string  $style  Value of style attribute (e.g., card, default)
     * @return string Format key (cards, grid, etc.)
     */
    private function mapStyleToFormat(string $style): string
    {
        // Normalize style to lowercase for comparison
        $s = \strtolower(\trim($style));

        // Keep a known format key, and fall back to grid for anything else
        return match ($s) {
            'card' => 'cards',
            'headlines', 'excerpts', 'full', 'side' => $s,
            default => 'grid',
        };
    }

    /**
     * Return true if attribute value means "hide" (hide_img, hide_date,
     * hide_creator_query).
     *
     * @param  mixed  $value  Attribute value (e.g., hide, show, true, false)
     * @return bool True to hide, false to show
     */
    private function attributeImpliesHide($value): bool
    {
        // Check if value is boolean; hide when false, show when true
        if (\is_bool($value)) {
            return ! $value;
        }

        if (! \is_scalar($value)) {
            return false;
        }

        // Normalize string value to lowercase for comparison
        $s = \strtolower(\trim((string) $value));

        // Return true (hide) for hide/false/no/off/0
        // Default to show for unknown values
        return \in_array(
            $s,
            [
                'hide',
                'false',
                'no',
                'off',
                '0',
            ],
            true
        );
    }

    /**
     * Return true if category or category_name implies include_link.
     *
     * @param  mixed  $category  Value of category attribute (e.g., 37 or slug)
     * @param  mixed  $categoryName  Value of category_name attribute (slug)
     * @return bool True if category/category_name is set and non-empty and not 0/false
     */
    private function categoryImpliesIncludeLink($category, $categoryName): bool
    {
        // Prefer category, fall back to category_name
        $cat = $category ?? $categoryName;

        // Trim and cast to string for empty/zero/false checks
        if (! \is_scalar($cat)) {
            return false;
        }

        $s = \trim((string) $cat);

        // Return false for empty, "0", or "false"
        // Category or category_name is set and non-empty; include link
        return ! ($s === '' || $s === '0' || \strtolower($s) === 'false');
    }
}
