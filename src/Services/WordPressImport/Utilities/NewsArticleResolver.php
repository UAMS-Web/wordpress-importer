<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities;

use Statamic\Entries\Entry;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Taxonomies\PostCategoryEntryResolver;

/**
 * Resolves a WordPress post id to the `posts` entry that post became.
 *
 * `uamsweb_block_news`'s `full` format picks its featured article through an
 * `entries` field over `posts`, while WordPress stores that selection as a raw
 * post id. The map between them already exists and needed no new field: since
 * wordpress-importer-archive#446 every imported post carries `wordpress_post_id` in its entry data, spread
 * in by {@see XpathHelper::extractMigrationRedirectMetadata()} for the migration
 * redirect generator. Nothing in *this importer* read it back off a saved entry
 * until now — the host app does, in `MigrationRedirectGenerator::…` — which is
 * why `UAMS-Web/wordpress-importer-archive#621` was filed believing no durable id
 * existed.
 *
 * Verified against `uams-statamic@origin/main` before relying on it: the key
 * persists in entry data as an `integer` with no blueprint field defining it, it
 * survives serialization to the entry file, and an `Entry::query()` `where()` on
 * it matches by int and by string alike.
 *
 * **Only hits are memoized.** A miss is deliberately re-queried, for the reason
 * {@see PostCategoryEntryResolver}
 * gives for the same decision: the news block is parsed during the `page` import,
 * and `page` runs before `post`, so an id absent on one pass can exist by the
 * next. Caching the miss would pin the earlier answer for the rest of the run.
 *
 * The sibling adds "an entry, once found, cannot stop existing mid-run"; that
 * sentence is true of `post_categories` and **false here**, so it is not repeated.
 * `StructuredTermImporter` re-saves a category entry in place, keeping its id,
 * while a re-imported post is *deleted and recreated* with a fresh random id
 * under the default `Overwrite matching files` behavior
 * ({@see BaseEntryCreator}).
 * So a hit resolved during the `page` pass can name an entry the `post` pass of
 * the same run then replaces. Clearing the cache would not help — the id is
 * already written into the block by then, and re-querying would only return the
 * pre-delete entry again.
 *
 * That is a property of the importer's delete-and-recreate re-import model, not
 * of this class: `LinkProcessor::convertInternalPageLink()` writes a
 * `statamic://entry::<id>` reference on a hit the same way, and nothing repairs
 * those either. Tracked as `UAMS-Web/wordpress-importer-archive#767` rather than papered
 * over here, because the fix belongs in the entry-creation model both surfaces
 * share.
 */
final class NewsArticleResolver
{
    /**
     * The collection a news article's `article_id` picker selects from.
     */
    private const string COLLECTION = 'posts';

    /**
     * Memoized hits, keyed by site and WordPress post id.
     *
     * @var array<string, string>
     */
    private static array $cache = [];

    /**
     * The Statamic entry id for a WordPress post id, or null when none matches.
     *
     * @param  string  $wordPressPostId  The raw WordPress post id
     * @param  string  $siteHandle  The Statamic site the block is being imported into
     * @return string|null The entry id, or null when nothing matches
     */
    public static function entryIdFor(
        string $wordPressPostId,
        string $siteHandle
    ): ?string {
        if (! self::isWellFormedId($wordPressPostId)) {
            return null;
        }

        $cacheKey = $siteHandle.'::'.$wordPressPostId;

        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $entryId = self::queryEntryId($wordPressPostId, $siteHandle);

        if ($entryId !== null) {
            self::$cache[$cacheKey] = $entryId;
        }

        return $entryId;
    }

    /**
     * Forget memoized hits.
     *
     * Called between imports so one run's entry ids cannot answer another's.
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * Whether this raw ACF value is shaped like a WordPress post id at all.
     *
     * Adopts two of the three rules {@see XpathHelper::extractMigrationRedirectMetadata()}
     * applies before stamping — `ctype_digit` and greater than zero — because a
     * value failing either can name no stamped entry. It deliberately does **not**
     * adopt the third. The writer `trim()`s first, and it is right to: its input is
     * the text of a `wp:post_id` XML element, where surrounding whitespace is a
     * pretty-printing artifact of the export. This reader's input is an ACF
     * attribute value, where padding is malformed data rather than formatting, so
     * ` 163336 ` is refused as a non-selection.
     *
     * That refusal is a real choice, not a filter over values that could not have
     * matched: `queryEntryId()` casts with `(int)`, and a probe issuing the same
     * query with `(int) ' 163336 '` matched the entry stamped `163336`. Padding is
     * turned away on purpose. `NewsArticleResolverTest` pins both halves — the
     * padded refusal, and the leading-zero id the cast does normalize.
     *
     * @param  string  $wordPressPostId  The raw value from the block
     * @return bool True when the value could name a stamped entry
     */
    public static function isWellFormedId(
        string $wordPressPostId
    ): bool {
        // The empty check states what ctype_digit() already refuses on PHP 8, and a digit
        // string compares with 0 numerically with or without the cast.
        // @pest-mutate-ignore: EmptyStringToNotEmpty, RemoveIntegerCast
        return $wordPressPostId !== ''
            && \ctype_digit($wordPressPostId)
            && (int) $wordPressPostId > 0;
    }

    /**
     * Query the `posts` collection for an entry carrying this WordPress id.
     *
     * **An ambiguous match is resolved deterministically rather than arbitrarily.**
     * Two entries can carry one id. Duplicating an entry in the CP copies the key;
     * and a post re-imported under a **changed slug** produces a second entry,
     * because `BaseEntryCreator::findExistingEntry()` matches on slug *and* post id
     * together, so a slug that has moved makes the earlier entry invisible to it.
     * (Re-importing an *unchanged* post does not: that path matches, deletes and
     * recreates — which is wordpress-importer-archive#767's subject, not a duplicate.) `first()` on a
     * multi-row result returns whichever the Stache happens to order first, which
     * is the "assert against the first match" trap `tests/CLAUDE.md` forbids. The
     * lowest entry id wins instead. What that guarantees is narrow and worth
     * stating exactly: for a *given set of matching entries* the same one is
     * chosen every time, independent of the order the query returns them. It is
     * not reproducible across machines — a Statamic entry id is random per
     * creation, so two imports of one export produce different ids. It is not the
     * oldest either;
     * a Statamic entry id does not sort chronologically. Picking a defensibly
     * correct one is impossible from here, so the tie is broken stably and the
     * ambiguity is reported for a human to remove at the source.
     *
     * @param  string  $wordPressPostId  The raw WordPress post id
     * @param  string  $siteHandle  The Statamic site to search
     * @return string|null The entry id, or null when nothing matches
     */
    private static function queryEntryId(
        string $wordPressPostId,
        string $siteHandle
    ): ?string {
        // Built in statements rather than a chain: the builder's `where()` is
        // untyped, so chaining loses the typed receiver.
        $query = EntryQueryHelper::query();
        $query->where('collection', self::COLLECTION);
        $query->where('locale', $siteHandle);
        // Queried as an int, the shape the writer stores. A Control-Panel edit of
        // the same key yields a *string*, and that still matches because the
        // Stache **stringifies both sides and compares as strings** — measured on
        // statamic/cms 6.26.0, the pinned build at the time (macOS): stored `int(163336)`
        // matched needles `163336` and `'163336'`, and missed `'0163336'` and
        // `'163336.0'`, which a numeric comparison would have matched. The cast
        // above is therefore what normalizes a leading-zero id, not the query —
        // and it normalizes it exactly as the writer would have. The
        // string-valued case in `NewsArticleResolverTest` pins the cross-type
        // match. Spelling the lookup both ways instead was tried and removed: on
        // this build neither half can be distinguished from the other, so it was
        // redundancy no test could hold to account.
        $query->where('wordpress_post_id', (int) $wordPressPostId);

        $matches = $query->get();
        $entryIds = [];

        // `get()` is untyped on the builder, so narrow once here rather than
        // trusting it at the loop.
        if (! \is_iterable($matches)) {
            return null;
        }

        foreach ($matches as $entry) {
            if (! $entry instanceof Entry) {
                continue;
            }

            $entryId = $entry->id();

            if (\is_string($entryId) && $entryId !== '') {
                $entryIds[] = $entryId;
            }
        }

        if ($entryIds === []) {
            return null;
        }

        if (\count($entryIds) > 1) {
            self::warnAmbiguous($wordPressPostId, $siteHandle, $entryIds);
        }

        \sort($entryIds);

        return $entryIds[0];
    }

    /**
     * Report that one WordPress post id names more than one entry.
     *
     * @param  string  $wordPressPostId  The id that matched more than once
     * @param  string  $siteHandle  The Statamic site that was searched
     * @param  list<string>  $entryIds  Every entry that claimed it
     */
    private static function warnAmbiguous(
        string $wordPressPostId,
        string $siteHandle,
        array $entryIds
    ): void {
        ImportWarningManager::addWarning(
            'content',
            'ambiguous_wordpress_post_id',
            [
                'site' => $siteHandle,
                'wordpress_post_id' => $wordPressPostId,
                'entry_ids' => $entryIds,
                'page_url' => ProcessingContext::getWordPressPageLink(),
                'reason' => 'More than one `posts` entry carries this WordPress post id, so a reference to it '
                    .'is ambiguous. The lowest entry id was used, which is stable but may not be the one '
                    .'intended. Duplicating an entry in the Control Panel copies the id, and so does '
                    .'re-importing a post whose slug has changed since the last import. Remove whichever '
                    .'entry is the stale one.',
            ]
        );
    }
}
