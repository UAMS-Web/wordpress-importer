<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities;

/**
 * Maps a WordPress news block's "local" flag to the reshaped `source_sites` field.
 *
 * `UAMS-Web/uams-statamic#76` replaced the block's boolean `local` — a flag on a
 * remote `news.uams.edu` syndication bridge — with `source_sites`, a required
 * checkboxes field naming which site(s) the block queries. The two tokens mirror
 * the host's own selector: `news` is the migrated `news.uams.edu` content, and
 * `current` is resolved to the placement's site at render time.
 *
 * WordPress `news_local === '1'` meant "also include this site's own posts", so
 * it maps to both tokens; anything else keeps the fieldset's `news`-only default.
 * The field is `required`, so a value is always emitted.
 *
 * **This governs which sites the block *queries*. Where its category selection
 * is looked up is a separate question, and the two now agree rather than
 * conflicting.** The host resolves `current` to the placement's site at render
 * time and can query any site's posts; since `uams-statamic#2165` the `category`
 * field sets `select_across_sites`, so an id from the `news` site survives the
 * read instead of being dropped. {@see PostCategoryEntryResolver} resolves
 * against the placement's site first and that site second, which is the same
 * pair this field names.
 *
 * **Note the collision.** The `news` spelled here is a **checkbox option token**; the identically
 * spelled `PostCategoryEntryResolver::SYNDICATING_SITE` is a **Statamic site
 * handle**. They coincide today and are not the same kind of thing.
 */
final class NewsBlockSourceSites
{
    /**
     * The token for the migrated `news.uams.edu` content.
     */
    public const string SITE_NEWS = 'news';

    /**
     * The placement-relative token the host resolves at render time.
     */
    public const string SITE_CURRENT = 'current';

    /**
     * Build the stored `source_sites` value for a block.
     *
     * @param  bool  $includesCurrentSite  Whether WordPress `news_local` was set
     * @return list<string> The checkboxes value
     */
    public static function forLocalFlag(
        bool $includesCurrentSite
    ): array {
        // The `news` token is always present — it is the fieldset default and the
        // host falls back to it when the selection is empty.
        if ($includesCurrentSite) {
            return [self::SITE_NEWS, self::SITE_CURRENT];
        }

        return [self::SITE_NEWS];
    }
}
