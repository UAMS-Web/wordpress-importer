<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\DeferredProcessing;

/**
 * WordPress kb_page ids the knowledge base root took the place of.
 *
 * **What this exists for (wordpress-importer-archive#1171).** A MinervaKB home page is deliberately not imported as a
 * `pages` entry so the knowledge base can mount at its URL. A navigation item pointing at that
 * page was then dropped rather than re-pointed, so a site's primary navigation silently lost
 * its knowledge-base link — the ordinary case, since the skip exists precisely so the
 * knowledge base can occupy that URL.
 *
 * `NavigationMenuImporter::findStatamicEntryByWordPressId()` resolves a post type to exactly
 * one collection and searches only that. For `page` that is `pages`, and the replacement entry
 * lives in `knowledge_bases` — so the lookup cannot succeed by construction, and no re-ordering
 * of content types changes it.
 *
 * **Registered here rather than at the skip.** `PageEntryCreator` knows the kb_page was skipped
 * but not what replaced it: content types import in a user-selected order, so the knowledge
 * base root may not exist when the page is skipped — the same reason mounts are deferred.
 * `KbEntryCreator` is the only frame holding both halves at once.
 *
 * Deliberately a substitution registry rather than a lookup keyed on the id format. The root's
 * id does embed the WordPress post id, and two comments in the codebase say so, but that is a
 * convention for making re-imports idempotent rather than a contract for finding entries —
 * resolving navigation through it would make a renaming of that scheme silently drop links again.
 */
class KbPageSubstitutions
{
    /**
     * Substitutions, keyed by "<wordPressPageId>_<site>".
     *
     * @var array<string, string>
     */
    private static array $substitutions = [];

    /**
     * Record that a knowledge base root took the place of a kb_page.
     *
     * @param  string  $wordPressPageId  The WordPress post ID of the skipped kb_page
     * @param  string  $kbRootId  The knowledge base root entry ID that mounted at its URL
     * @param  string  $site  The site handle
     */
    public static function register(
        string $wordPressPageId,
        string $kbRootId,
        string $site
    ): void {
        if ($wordPressPageId === '' || $kbRootId === '') {
            return;
        }

        self::$substitutions[self::key($wordPressPageId, $site)] = $kbRootId;
    }

    /**
     * The knowledge base root that replaced a kb_page, or null when none did.
     *
     * @param  string  $wordPressPageId  The WordPress post ID the navigation item points at
     * @param  string  $site  The site handle
     */
    public static function resolve(
        string $wordPressPageId,
        string $site
    ): ?string {
        return self::$substitutions[self::key($wordPressPageId, $site)] ?? null;
    }

    /**
     * Every recorded substitution, for inspection and testing.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return self::$substitutions;
    }

    /**
     * Clear the registry.
     *
     * Process-wide state, so a run and a test suite both need it reset between cycles.
     */
    public static function clear(): void
    {
        self::$substitutions = [];
    }

    /**
     * The registry key for a page and site.
     *
     * Keyed per site because the same WordPress post id can be imported into more than one
     * site, and a navigation item resolves within its own.
     */
    private static function key(
        string $wordPressPageId,
        string $site
    ): string {
        return $wordPressPageId.'_'.$site;
    }
}
