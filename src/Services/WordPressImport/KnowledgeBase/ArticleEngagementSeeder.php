<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\KnowledgeBase;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use SimpleXMLElement;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;

/**
 * Seeds historical MinervaKB engagement tallies into the app's sideband store.
 *
 * `UAMS-Web/uams-statamic-archive#286` / `#287` moved knowledge base article view / like /
 * dislike counts OFF the entry YAML and into an app-owned SQLite table,
 * `article_engagement_counts` — one row per article entry id — so a per-view
 * increment never rewrites content in the git submodule, never re-indexes the
 * Stache, and never races on a YAML save. The runtime endpoints populate it; a
 * fresh import therefore left every article at zero and discarded years of
 * MinervaKB signal, emptying the migrated "Most Viewed" / "Most Liked" blocks and
 * reordering the featured articles (wordpress-importer-archive#427).
 *
 * The source values are plain integer aggregates in the WXR postmeta —
 * `_mkb_views`, `_mkb_likes` and `_mkb_dislikes`, one row per article that has
 * any. The parallel `_mkb_*_meta` keys hold a per-day breakdown the sideband
 * store has no column for, and are deliberately ignored.
 *
 * This is the one place in the importer that writes to an app-owned database
 * table rather than to content, so it is deliberately defensive: a missing table
 * is a no-op, not a fatal, exactly as the app's own `ArticleEngagementService`
 * treats it.
 */
class ArticleEngagementSeeder
{
    /**
     * The app-owned table holding one engagement row per article entry id.
     */
    private const string TABLE = 'article_engagement_counts';

    /**
     * The MinervaKB postmeta key holding the aggregate view count.
     */
    private const string META_VIEWS = '_mkb_views';

    /**
     * The MinervaKB postmeta key holding the aggregate like count.
     */
    private const string META_LIKES = '_mkb_likes';

    /**
     * The MinervaKB postmeta key holding the aggregate dislike count.
     */
    private const string META_DISLIKES = '_mkb_dislikes';

    /**
     * Reads the MinervaKB engagement aggregates from an article's postmeta.
     *
     * Pure: no database, no side effects, so the mapping from a source record to
     * the seeded row is testable on its own.
     *
     * A value that is absent, blank, non-numeric, or negative reads as zero. The
     * store's columns are unsigned, and MinervaKB has no notion of a negative
     * tally, so a garbage value must not reach the insert.
     *
     * @param  SimpleXMLElement  $item  The `wp:post` element for the article
     * @return array{views: int, likes: int, dislikes: int} The aggregate tallies
     */
    public static function extractCounts(
        SimpleXMLElement $item
    ): array {
        // Start every tally at zero so an article with no meta reads as untouched
        $counts = [
            'views' => 0,
            'likes' => 0,
            'dislikes' => 0,
        ];

        // Map each source postmeta key onto the column it seeds
        $keyToColumn = [
            self::META_VIEWS => 'views',
            self::META_LIKES => 'likes',
            self::META_DISLIKES => 'dislikes',
        ];

        // Extract all postmeta elements from the article XML
        $postmeta = XpathHelper::xpathElements(
            $item,
            'wp:postmeta'
        );

        // Iterate through each postmeta element to find the engagement aggregates
        foreach ($postmeta as $meta) {
            // Get the meta_key value
            $metaKey = XpathHelper::getStringValue(
                $meta,
                'wp:meta_key'
            );

            // Skip any key that does not seed a column
            if (! isset($keyToColumn[$metaKey])) {
                continue;
            }

            // Get the raw aggregate, which MinervaKB stores as a plain integer string
            $metaValue = XpathHelper::getStringValue(
                $meta,
                'wp:meta_value'
            );

            // Treat a blank or non-numeric aggregate as zero. A numeric string may carry
            // surrounding whitespace, and a blank one is not numeric (#102).
            if (! \is_numeric($metaValue)) {
                continue;
            }

            // Floor at zero: the store's columns are unsigned
            $counts[$keyToColumn[$metaKey]] = \max(
                0,
                (int) $metaValue
            );
        }

        // Return the aggregate tallies
        return $counts;
    }

    /**
     * The exact length of the random suffix `SlugGenerator` appends to an entry id.
     *
     * `StatamicStr::random()` returns 16 characters. Requiring exactly that before
     * pruning is what keeps the prefix match from ever reaching an id this importer
     * did not mint.
     */
    // PCOV records no line for a class constant, so the mutation plugin never runs a mutant of
    // this value; 15 and 17 by hand each fail ArticleEngagementSeedTest (#102)
    // @pest-mutate-ignore: IncrementInteger, DecrementInteger
    private const int ENTRY_ID_RANDOM_LENGTH = 16;

    /**
     * Upserts an article's MinervaKB tallies into the sideband store.
     *
     * Keyed on `entry_id`, which the table declares unique, so re-seeding the SAME
     * entry id overwrites rather than doubling — the import carries the historical
     * total, not a delta. `created_at` is written on insert only, so a re-import
     * does not backdate a row the runtime already created.
     *
     * The entry id is not stable across imports, though.
     * `SlugGenerator::generateSlugAndId()` mints `<slug>-<postId>-<random>` with a
     * fresh `StatamicStr::random()` every run, and under the default `Overwrite
     * matching files` behavior `BaseEntryCreator::saveEntry()` DELETES the previous
     * entry and writes a new one under the new id. The upsert's conflict target
     * therefore never fires across runs, so each re-import would insert a fresh row
     * and orphan the last one — roughly 1,500 dead rows per run across the corpus.
     * `pruneSupersededRows()` closes that: the row this article left behind under a
     * previous id is removed before the new one is written.
     *
     * An article whose export carries no engagement meta writes no row at all. A
     * missing row already reads as all-zero through the app's
     * `ArticleEngagementService`, so a zeroed row would be pure noise.
     *
     * @param  SimpleXMLElement  $item  The `wp:post` element for the article
     * @param  string  $entryId  The Statamic entry id the row is keyed by
     * @return bool Whether a row was written
     */
    public static function seed(
        SimpleXMLElement $item,
        string $entryId
    ): bool {
        // An unidentified entry cannot key a row
        if (\trim($entryId) === '') {
            return false;
        }

        // Read the aggregates the export carries
        $counts = self::extractCounts($item);

        // ---
        // Skip an article with nothing to record. A missing row already reads as
        // all-zero through the app's service, so writing one would add noise without
        // adding information.
        // ---
        if (
            $counts['views'] === 0
            && $counts['likes'] === 0
            && $counts['dislikes'] === 0
        ) {
            return false;
        }

        // ---
        // Degrade to a no-op when the sideband table is absent. The importer runs
        // inside the host app, but the migration that creates this table ships with
        // the app and may not have run yet — a fresh environment, or a deploy whose
        // ordering puts the import first. Losing historical counts is a far smaller
        // failure than aborting the whole import.
        // ---
        if (! Schema::hasTable(self::TABLE)) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'entry_id' => $entryId,
                    'table' => self::TABLE,
                ]
            );

            // Log warning so the skipped seeding is accounted for
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'ArticleEngagementSeeder::seed: Sideband engagement table is absent; skipping',
                    $logData['page_url']
                ),
                $logData['context']
            );

            return false;
        }

        // Drop rows this article left behind under a previous entry id
        self::pruneSupersededRows($entryId);

        // Stamp both timestamps for the insert branch
        $now = \now();

        // ---
        // Upsert on the unique `entry_id`. The update list deliberately omits
        // `created_at`, so a re-import refreshes the tallies without rewriting when
        // the row first appeared.
        // ---
        DB::table(self::TABLE)->upsert(
            [
                [
                    'entry_id' => $entryId,
                    'views' => $counts['views'],
                    'likes' => $counts['likes'],
                    'dislikes' => $counts['dislikes'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ],
            ['entry_id'],
            [
                'views',
                'likes',
                'dislikes',
                'updated_at',
            ]
        );

        // Clean up local variables to free up memory
        unset(
            $counts,
            $now
        );

        return true;
    }

    /**
     * Removes rows the same article left behind under a previous entry id.
     *
     * The importer mints `<slug>-<postId>-<random>`, so every id for one article
     * shares the `<slug>-<postId>-` prefix and differs only in the 16-character
     * random tail. Deleting that prefix — minus the id being written now — reclaims
     * exactly the rows whose entries no longer exist.
     *
     * The match is deliberately narrow. It runs only for an id whose tail is exactly
     * `StatamicStr::random()`'s 16 characters, and the prefix is escaped before it
     * reaches `LIKE`, so a slug containing `%` or `_` cannot widen the delete into
     * unrelated articles.
     *
     * @param  string  $entryId  The entry id about to be written
     */
    private static function pruneSupersededRows(
        string $entryId
    ): void {
        // Locate the separator before the random tail
        $separator = \strrpos(
            $entryId,
            '-'
        );

        // An id with no separator is not one this importer minted. The separator count
        // below refuses such an id as well, so this only narrows the type for the
        // arithmetic that follows.
        // @pest-mutate-ignore: FalseToTrue, RemoveEarlyReturn
        if ($separator === false) {
            return;
        }

        // The random tail has to be exactly the length Statamic generates
        if (\strlen($entryId) - $separator - 1 !== self::ENTRY_ID_RANDOM_LENGTH) {
            return;
        }

        // Everything up to and including the separator identifies the article
        $prefix = \substr(
            $entryId,
            0,
            $separator + 1
        );

        // A `<slug>-<postId>-` prefix always carries at least one more separator
        if (\substr_count($prefix, '-') < 2) {
            return;
        }

        // ---
        // Escape the LIKE metacharacters so a slug carrying `%`, `_`, or the escape
        // character itself cannot broaden the pattern beyond this one article.
        // ---
        $escaped = \str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $prefix
        );

        // Delete every sibling row except the id being written now
        DB::table(self::TABLE)
            ->whereRaw(
                'entry_id LIKE ? ESCAPE ?',
                [$escaped.'%', '\\']
            )
            ->where(
                'entry_id',
                '!=',
                $entryId
            )
            ->delete();
    }
}
