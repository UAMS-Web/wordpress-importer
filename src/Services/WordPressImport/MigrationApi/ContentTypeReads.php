<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi;

/**
 * What a run scoped to one content type reads, and which passes it runs afterwards (#109).
 *
 * **Why this exists.** A `--content-type=<type>` run used to read one shared closure whatever
 * the type was, and every read in it could stop the run. That closure was measured for types
 * whose bodies carry blocks, menus, tables and internal links (wordpress-importer-archive#1089,
 * wordpress-importer-archive#1175, wordpress-importer-archive#1179), and was over-broad for a
 * type without a body: the gouams.com redirect run for #93 read its 9 rules, then stopped on a
 * `posts/page` 500 it never needed, and a go.uams.edu redirect run changed a site's navigation
 * setting. So each type declares its own reads here, each entry with its reason, and the
 * materializer and the import command both read this one declaration.
 *
 * Three parts per type:
 *
 *   companions    post types read because the type's import looks items of them up out of
 *                 the export, and skips silently when one is absent
 *   link targets  post types read so `LinkProcessor` can turn a same-site URL into an entry
 *                 reference. A link whose target type was not read is reported through
 *                 `MaterializationScope` and left as a URL, never dropped
 *   passes        the passes after the content-type loop that the type needs
 *
 * **Two passes are declared: navigation and Bard sanitization.** Each works on more than the
 * run wrote. The navigation pass reads the export's menus and writes a site setting
 * (`manual_primary_navigation`); Bard sanitization reads every entry in every collection and
 * rewrites the files it flags. The other passes after the loop run on every run, for a
 * reason each: deferred links and the dataless sweep work only on what the run queued or
 * wrote, and the search reindex rebuilds the indexes that live updating was switched off for
 * during the import, which a redirect domain run's entries need as much as any other.
 *
 * A type with no declaration of its own carries a body, and gets the body declaration, which
 * is exactly what every scoped run read before #109.
 */
final class ContentTypeReads
{
    /**
     * The navigation pass: imports the primary menu and sets `manual_primary_navigation`.
     */
    public const string PASS_NAVIGATION = 'navigation';

    /**
     * The Bard sanitization pass: rewrites any entry file whose Bard text needs quoting.
     */
    public const string PASS_BARD_SANITIZATION = 'bard_sanitization';

    /**
     * Companions of every type with a body.
     *
     * Each of these is read out of the export by a type other than itself, and every one of
     * those readers skips silently when its item is absent, so dropping one imports cleanly
     * with a gap (wordpress-importer-archive#1089):
     *
     *   `attachment`        reached by id from featured images, image and file blocks, alt
     *                       text and credits, and by URL from image `src` attributes; a
     *                       missing one is media that silently fails. A scoped run narrows
     *                       it to what the items reference (wordpress-importer-archive#1175)
     *   `wp_block`          pages inline reusable blocks by looking the item up
     *   `nav_menu_item`     the navigation pass reads the menu from the export
     *   `tablepress_table`  `importTables()` runs after every non-table content type
     *
     * @var list<string>
     */
    public const array BODY_COMPANIONS = [
        'attachment',
        'wp_block',
        'nav_menu_item',
        'tablepress_table',
    ];

    /**
     * Companions a particular body-carrying type needs beyond the shared set.
     *
     * `kb` resolves its knowledge-base home page from `page` items in the export.
     *
     * @var array<string, list<string>>
     */
    public const array TYPE_COMPANIONS = [
        'kb' => ['page'],
    ];

    /**
     * Link targets of every type with a body, and of redirect rules on a site (wordpress-importer-archive#1179).
     *
     * **This list is a measurement, not a code path.** `LinkProcessor` resolves a link by
     * matching the target's URL against every item in the export, whatever its type, then
     * finds the Statamic entry from that item's type and id. So a link whose target's type was
     * not read stays unconverted, even when the entry already exists from an earlier run. The
     * API cannot fill the gap on demand: `/posts/<type>` offers no lookup by slug or URL.
     *
     * Measured across the 259 WXR site exports, on Windows, by matching each internal `href`
     * to an item in the same export: 905 source-to-target type pairs cross types, and **891**
     * of them land on these three types or on types already read or deliberately skipped. The
     * remaining 14 point at `sfwd-courses`, `kb`, `sfwd-lessons` and `tshowcase`. `provider`,
     * the costliest type a scoped read avoids, appears as a link target nowhere.
     *
     * @var list<string>
     */
    public const array LINK_TARGETS = [
        'page',
        'post',
        'uamspeople',
    ];

    /**
     * Companions of redirect rules on a Statamic site.
     *
     * `attachment` only, narrowed to what the rules reference as every scoped attachment read
     * is: a target can name a file or an attachment page, and an attachment page resolves
     * through its item. A rule has no body, so no block, menu or table is read for it.
     *
     * @var list<string>
     */
    public const array REDIRECT_RULE_COMPANIONS = [
        'attachment',
    ];

    /**
     * The redirect content type, the one type without a body.
     */
    private const string REDIRECT_RULE = 'redirect_rule';

    /**
     * The post types a type reads besides itself because its import looks them up.
     *
     * @param  string  $contentType  The requested content type
     * @param  bool  $redirectDomain  Whether the site is a listed vanity redirect domain
     * @return list<string> The companion post types, in declaration order
     */
    public static function companions(
        string $contentType,
        bool $redirectDomain
    ): array {
        if ($contentType === self::REDIRECT_RULE) {
            // A vanity domain is not a Statamic site, so its rules can name nothing the
            // import keeps from that site: its files are fetched by URL, never by item.
            return $redirectDomain ? [] : self::REDIRECT_RULE_COMPANIONS;
        }

        return \array_values(\array_unique(\array_merge(
            self::BODY_COMPANIONS,
            self::TYPE_COMPANIONS[$contentType] ?? []
        )));
    }

    /**
     * The post types a type reads so its same-site links can become entry references.
     *
     * **Redirect rules keep them on a site and drop them on a vanity domain.** Measured
     * across the 143 site exports carrying `redirect_rule` items (5,378 rules, Windows 11):
     * 975 targets are the URL of a `page`, `post` or `uamspeople` item in the same export,
     * so dropping the link targets on a site would turn each of those into a plain URL that
     * no longer follows a rename. None of the 143 is a vanity domain. A vanity domain's own
     * pages are never imported as entries, so an entry reference to one cannot exist, and
     * reading them there only adds endpoints that can stop the run (#93).
     *
     * @param  string  $contentType  The requested content type
     * @param  bool  $redirectDomain  Whether the site is a listed vanity redirect domain
     * @return list<string> The link-target post types
     */
    public static function linkTargets(
        string $contentType,
        bool $redirectDomain
    ): array {
        return $contentType === self::REDIRECT_RULE && $redirectDomain ? [] : self::LINK_TARGETS;
    }

    /**
     * The passes after the content-type loop that a run importing this type runs.
     *
     * Redirect rules run neither: their import writes the redirects global set or the
     * redirect domains collection, which carry no menu, no site setting and no Bard text.
     *
     * @param  string  $contentType  The requested content type
     * @return list<string> The declared passes
     */
    public static function passes(
        string $contentType
    ): array {
        return $contentType === self::REDIRECT_RULE
            ? []
            : [self::PASS_NAVIGATION, self::PASS_BARD_SANITIZATION];
    }

    /**
     * Whether a run that imported these types runs a declared pass.
     *
     * The pass runs when any imported type declares it, so a run that imported pages and
     * redirect rules still runs it. A run that imported nothing runs it as it did before
     * #109, since there is no declaration to read.
     *
     * @param  string  $pass  One of the `PASS_*` constants
     * @param  list<string>  $contentTypes  The types the run imported
     * @return bool True when the pass runs
     */
    public static function runsPass(
        string $pass,
        array $contentTypes
    ): bool {
        if ($contentTypes === []) {
            return true;
        }

        return array_any($contentTypes, fn (string $contentType): bool => \in_array($pass, self::passes($contentType), true));
    }
}
