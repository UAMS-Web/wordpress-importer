<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\MigrationApi;

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;

/**
 * What the current run's materialized export covers, for readers that look items up in it.
 *
 * **Why this exists (wordpress-importer-archive#1179).** A run given `--content-type` reads a closure of post types
 * rather than the whole site, and two readers look items up in the export by something other
 * than the type they are importing. `LinkProcessor` resolves a link by the target's URL,
 * whatever its type, and the Find-a-Doc parsers resolve filter ids to `expertise`, `location`
 * and `provider` items. When the target's type was not read, both fail exactly as they would
 * on a broken link or a deleted provider, and a scoped run would report that loss as nothing
 * at all.
 *
 * So a scoped run records what it read here, and a reader that misses asks before staying
 * quiet. **An unscoped run records nothing and reports nothing extra**, because a miss there
 * is a genuine absence, which those readers already handle.
 *
 * Process-global by design, like `ImportWarningManager`: one command run materializes one
 * export. It is set after materialization and cleared on every file-sourced run, so a value
 * cannot leak from one run into the next in the same process.
 */
final class MaterializationScope
{
    /**
     * The post types a scoped run read, or null when the run was not scoped.
     *
     * @var list<string>|null
     */
    private static ?array $postTypes = null;

    /**
     * Whether the scoped run read a listed vanity redirect domain, which is not a Statamic site.
     *
     * Read only while a scope is set, and `set()` always assigns it, so `clear()` leaves it.
     */
    // Never read before set() assigns it, so its starting value is never seen
    // @pest-mutate-ignore: FalseToTrue
    private static bool $redirectDomain = false;

    /**
     * Links already reported this run, keyed by URL.
     *
     * One unresolved URL is often linked from several fields or entries, and each occurrence
     * reaches `convertInternalPageLink()`. Without this, it is reported once per occurrence,
     * and an operator counting unconverted links counts the same link several times. (A
     * deferred page-builder scan was once a second path to the same URL, #1197; it never
     * reached real data and was removed in wordpress-importer-archive#1205.)
     *
     * @var array<string, true>
     */
    private static array $reportedLinks = [];

    /**
     * Record that this run read only the given post types.
     *
     * @param  list<string>|null  $postTypes  The post types read, or null for a whole-site read
     * @param  bool  $redirectDomain  Whether the site is a listed vanity redirect domain
     */
    public static function set(
        ?array $postTypes,
        bool $redirectDomain = false
    ): void {
        self::$postTypes = $postTypes;
        self::$redirectDomain = $redirectDomain;
        self::$reportedLinks = [];
    }

    /**
     * Forget any recorded scope, returning to the whole-site assumption.
     */
    public static function clear(): void
    {
        self::$postTypes = null;
        self::$reportedLinks = [];
    }

    /**
     * Whether the current run read only part of the site.
     *
     * @return bool True for a scoped run
     */
    public static function isScoped(): bool
    {
        return self::$postTypes !== null;
    }

    /**
     * The post types the current scoped run read.
     *
     * @return list<string>|null The post types read, or null for a whole-site read
     */
    public static function postTypes(): ?array
    {
        return self::$postTypes;
    }

    /**
     * Report a link a scoped run could not resolve.
     *
     * The target's type is unknown, because finding it is exactly what failed, so this cannot
     * say for certain the scope was the cause. It says the link was unresolved in a run that
     * did not read the whole site, which is what an operator needs to decide whether to re-run
     * unscoped. Nothing is reported on an unscoped run, where the same miss is a broken link.
     *
     * Each URL is reported once per run, however many times it occurs.
     *
     * On a vanity redirect domain the consequence is narrower. Its pages are never imported as
     * entries, so a link to a page or post there stays a URL in any run; only an attachment
     * page, which resolves through its attachment item to an asset, converts in an unscoped
     * run, and a vanity read of redirect rules reads no attachments.
     *
     * @param  string  $url  The link that could not be resolved
     */
    public static function reportUnresolvedLink(
        string $url
    ): void {
        if (self::$postTypes === null || isset(self::$reportedLinks[$url])) {
            return;
        }

        // Only the key is read, by isset(), which holds for false as for true
        // @pest-mutate-ignore: TrueToFalse
        self::$reportedLinks[$url] = true;

        ImportWarningManager::addWarning(
            'migration_api',
            'scope_unresolved_links',
            [
                'url' => $url,
                'read_post_types' => self::$postTypes,
                'consequence' => self::$redirectDomain
                    ? 'the link was left unconverted; this site is a vanity redirect domain, whose pages are never imported, so an unscoped run converts it only if its target is an attachment page'
                    : 'the link was left unconverted; if its target is of a type this run did not read, an unscoped run converts it',
            ]
        );
    }

    /**
     * Report a lookup of a specific type that failed because that type was not read.
     *
     * Silent when the run was not scoped, and silent when the type WAS read, since a miss is
     * then a genuine absence rather than something the scope removed.
     *
     * @param  string  $reader  The component that looked the item up, for the report
     * @param  string  $postType  The post type it looked for
     * @param  string  $postId  The WordPress post id it looked for
     */
    public static function reportUnreadType(
        string $reader,
        string $postType,
        string $postId
    ): void {
        // Post types are strings, so in_array()'s strict flag, which phpstan-strict-rules
        // requires, changes no result
        // @pest-mutate-ignore: TrueToFalse
        if (self::$postTypes === null || \in_array($postType, self::$postTypes, true)) {
            return;
        }

        ImportWarningManager::addWarning(
            'migration_api',
            'scope_unread_references',
            [
                'reader' => $reader,
                'post_type' => $postType,
                'post_id' => $postId,
                'read_post_types' => self::$postTypes,
                'consequence' => 'the reference was dropped because this run did not read that type; an unscoped run resolves it',
            ]
        );
    }
}
