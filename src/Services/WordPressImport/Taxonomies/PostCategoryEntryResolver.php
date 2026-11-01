<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Taxonomies;

use Illuminate\Support\Str;
use Statamic\Entries\Entry;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\EntryQueryHelper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;

/**
 * Resolves WordPress category slugs to `post_categories` collection-entry ids.
 *
 * The `uamsweb_block_news` fieldset stores its category selection as an
 * `entries` picker over the `post_categories` **collection**, so a block that
 * carries a WordPress category slug has to be resolved to the id of the entry
 * that slug imported into. {@see StructuredTermImporter} creates those entries
 * during the term pass and {@see TermDestinationRegistry} records them — but
 * that registry is keyed by WordPress term id and lives only for the run that
 * populated it, and a news block routinely references categories that belong
 * to a *different* export imported in a *different* run (the block sits on one
 * site's page and filters posts syndicated from `news`). So this resolver goes
 * to the saved entries instead, which is the only lookup that survives the run
 * boundary.
 *
 * **Resolution tries the placement's own site first and the syndicating site
 * second, and the second half is only safe because the host field opts in.**
 * Statamic's `Entries` fieldtype localizes its stored ids to the parent entry's
 * site before anything reads them, unless the field sets
 * `select_across_sites` — which `uamsweb_block_news`'s `category` field now
 * does (`uams-statamic-archive#2165`):
 *
 * ```php
 * // vendor/statamic/cms/src/Fieldtypes/Entries.php::queryBuilder()
 * $shouldLocalize = ! $this->canSelectAcrossSites();
 * ... ->when($shouldLocalize, fn ($entries) => $entries
 *         ->map(fn ($entry) => $entry->in($site))
 *         ->filter())
 * ```
 *
 * `->when($shouldLocalize, ...)` is the whole of it: with the flag set the
 * closure never runs, so `->in($site)` — the only such call in that file — is
 * skipped and an id from another site survives the read. **`propagate` does not
 * enter this path at all**, which is what settles the question `wordpress-importer-archive#628` recorded
 * as the one observation that could sink this approach: `post_categories` sets
 * `propagate: false`, and it does not matter, because the call that consults
 * localization is bypassed rather than answered. `augment()` and
 * `shallowAugment()` both read through the same `queryBuilder()`.
 *
 * Before that flag shipped, writing a cross-site id stored a value the host
 * silently dropped — a block that looked category-filtered in its YAML and
 * rendered completely unfiltered. That is the failure this class used to avoid
 * by refusing to resolve; it is now avoided by the host keeping the value.
 *
 * Lookups are memoized for the life of the run: one page can carry dozens of
 * news blocks naming the same handful of categories.
 *
 * **A miss no longer means "too early", and no longer means "cross-site"
 * either.** `PageEntryCreator` runs the post taxonomies before it parses any
 * page, so by the time a block asks, every category the export declares for
 * this site exists (wordpress-importer-archive#624); and a category belonging to the news site now
 * resolves rather than being refused (wordpress-importer-archive#628). What is left is a slug no export
 * imported so far declares on either site, and the warning below says that
 * rather than sending an operator to re-run an import that cannot help.
 *
 * **The corpus figures that used to sit here — 13 of 41 — were superseded by
 * `wordpress-importer-archive#628`'s re-derivation and are deliberately not restated.** That query was
 * never recorded, its successor counted 104 unresolvable of 429 references
 * across all 259 exports, and the figure is an upper bound on the
 * Statamic-scoped count because it groups by `base_blog_url`. The live query
 * lives on `wordpress-importer-archive#628`; a number copied here would go stale without it.
 */
final class PostCategoryEntryResolver
{
    /**
     * The Statamic collection WordPress categories import into.
     */
    public const string COLLECTION = 'post_categories';

    /**
     * The Statamic site holding the migrated `news.uams.edu` content.
     *
     * The news block always queries this site — `news` is the `source_sites`
     * default and the host falls back to it when the selection is empty — so it
     * is the one other site whose categories a block can meaningfully filter by.
     * Resolving against any site would be worse than not resolving: a category
     * from a site the block never queries filters against posts that cannot
     * appear, which renders empty rather than unfiltered.
     *
     * Verified against the host's `resources/sites.yaml`, where `news` is a real
     * site handle (`News (Communications and Marketing)`) rather than only the
     * field token {@see NewsBlockSourceSites::SITE_NEWS}. The two are spelled the
     * same and are different things: that one names a checkbox option, this one
     * names a site.
     */
    public const string SYNDICATING_SITE = 'news';

    /**
     * Resolved entry ids keyed by "{site}::{slug}". Misses are not stored.
     *
     * @var array<string, string>
     */
    private static array $cache = [];

    /**
     * Resolve a raw WordPress category value to `post_categories` entry ids.
     *
     * The raw value is the block's own `news_category` string, which carries
     * one slug or several separated by commas (observed in the export corpus:
     * `college-of-pharmacy, jobs`, with the separator followed by a space).
     *
     * Any slug that does not resolve is reported through
     * {@see ImportWarningManager} rather than dropped in silence, because the
     * host reads an **absent** `category` as "no filter at all" — so a lost
     * slug does not narrow the block, it widens it from one category to every
     * news post. That inversion is invisible in the imported YAML.
     *
     * @param  string  $rawCategoryValue  The WordPress category slug, or comma-separated slugs
     * @param  string  $siteHandle  The Statamic site the block is being imported into
     * @return list<string> The entry ids, in the order the block declared the slugs, without duplicates
     */
    public static function referencesFor(
        string $rawCategoryValue,
        string $siteHandle
    ): array {
        // Resolve each slug against the placement's own site, keeping the order
        // the block declared them in and recording the ones that miss.
        $entryIds = [];
        $unresolved = [];

        foreach (self::splitSlugs($rawCategoryValue) as $slug) {
            $entryId = self::entryIdFor($slug, $siteHandle);

            if ($entryId === null) {
                $unresolved[] = $slug;

                continue;
            }

            // Two slugs can name the same entry, and the picker stores each
            // reference once. Entry ids are strings, so the strict flag, which
            // phpstan-strict-rules requires, changes no result.
            // @pest-mutate-ignore: TrueToFalse
            if (\in_array($entryId, $entryIds, true)) {
                continue;
            }

            $entryIds[] = $entryId;
        }

        if ($unresolved !== []) {
            self::warnUnresolved($unresolved, $siteHandle, $entryIds === []);
        }

        return $entryIds;
    }

    /**
     * Report category slugs that did not resolve to an entry.
     *
     * @param  list<string>  $slugs  The slugs that did not resolve
     * @param  string  $siteHandle  The Statamic site that was searched
     * @param  bool  $lostEveryFilter  Whether the block was left with no category filter at all
     */
    private static function warnUnresolved(
        array $slugs,
        string $siteHandle,
        bool $lostEveryFilter
    ): void {
        // Name the slugs and say plainly what the block will now render, since
        // a widened block looks identical to one that never had a filter.
        ImportWarningManager::addWarning(
            'content',
            'unresolvable_news_category',
            [
                'site' => $siteHandle,
                'category_slugs' => \implode(', ', $slugs),
                'page_url' => ProcessingContext::getWordPressPageLink(),
                'effect' => $lostEveryFilter
                    ? 'The block lost its category filter entirely and will render every news post.'
                    : 'The block kept its other categories; only the slugs named here were dropped.',
                'reason' => 'No `'.self::COLLECTION.'` entry exists for these slugs on this site '
                    .'or on the `'.self::SYNDICATING_SITE.'` site the block syndicates from. '
                    .'The page import creates the categories before it parses any block and the '
                    .'cross-site case is now resolved against the syndicating site, so a re-run '
                    .'will NOT help: no export imported so far declares this slug on either site '
                    .'(see wordpress-importer-archive#628). '
                    .'Set the category on the block in the Control Panel, or import an export that '
                    .'declares it.',
            ]
        );
    }

    /**
     * Split a raw WordPress category value into its individual slugs.
     *
     * @param  string  $rawCategoryValue  The WordPress category slug, or comma-separated slugs
     * @return list<string> The trimmed, non-empty, de-duplicated slugs in source order
     */
    public static function splitSlugs(
        string $rawCategoryValue
    ): array {
        // Reduce the raw value to the distinct slugs it names.
        $slugs = [];

        foreach (\explode(',', $rawCategoryValue) as $candidate) {
            $slug = \trim($candidate);

            if ($slug === '') {
                continue;
            }

            if (\in_array($slug, $slugs, true)) {
                continue;
            }

            $slugs[] = $slug;
        }

        return $slugs;
    }

    /**
     * Reset the memoized lookups for a new import run.
     */
    public static function clear(): void
    {
        self::$cache = [];
    }

    /**
     * Look up the `post_categories` entry id for a slug on one site.
     *
     * @param  string  $slug  The category slug
     * @param  string  $siteHandle  The Statamic site handle
     * @return ?string The entry id, or null when the site has no entry for that slug
     */
    private static function entryIdFor(
        string $slug,
        string $siteHandle
    ): ?string {
        $cacheKey = "{$siteHandle}::{$slug}";

        // Only hits are memoized. A miss is deliberately re-queried, because
        // `clearTaxonomyCaches()` runs once per command rather than between
        // content types, so a category absent during one pass can exist by the
        // next — the term pass that creates `post_categories` entries belongs
        // to the `post` import, which runs after `page`. Caching the miss would
        // pin the earlier answer for the rest of the run. An entry, once found,
        // cannot stop existing mid-run, so caching the hit is safe.
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $entryId = self::queryEntryId($slug, $siteHandle);

        // ---
        // Retry against the slugified value when the raw one misses.
        //
        // The block's stored value is *supposed* to be a category slug and
        // usually is, but WordPress lets a display name through. **Case is not
        // what this is for** — the Stache slug query is already case-insensitive,
        // so the corpus's two `News` values match a `news` entry with no help
        // from here (verified; see TermPassDedupKeyTest's sibling assertion in
        // this file). What it does cover is a name whose punctuation differs
        // from its slug — `Faculty News` against `faculty-news` — which an exact
        // match cannot reach at any casing.
        //
        // No such value exists in the sampled corpus today. It is kept as cheap
        // depth for a class the query genuinely cannot handle, costs one extra
        // lookup only after a real miss, and is skipped when the two forms are
        // identical (wordpress-importer-archive#624).
        // ---
        if ($entryId === null) {
            $normalized = Str::slug($slug);

            // Skipping an empty or unchanged form only saves a lookup that would miss
            // @pest-mutate-ignore: BooleanAndToBooleanOr, EmptyStringToNotEmpty
            if ($normalized !== '' && $normalized !== $slug) {
                $entryId = self::queryEntryId($normalized, $siteHandle);
            }
        }

        // ---
        // Fall back to the syndicating site.
        //
        // `uams-statamic-archive#2165` put `select_across_sites: true` on the block's
        // `category` field, which is what makes this safe: the fieldtype's only
        // localization step is skipped when that flag is set, so an id belonging
        // to another site survives the read instead of being dropped. Verified in
        // `vendor/statamic/cms/src/Fieldtypes/Entries.php` — `$shouldLocalize =
        // ! $this->canSelectAcrossSites()` gates the single `->in($site)` call in
        // the file, and `augment()` reads through the same `queryBuilder()`.
        //
        // The placement's own site is still tried first. A block naming a
        // category that exists on both sites means the local one, and preferring
        // the syndicate would silently change which entry an already-correct
        // import resolves to.
        // ---
        if ($entryId === null && $siteHandle !== self::SYNDICATING_SITE) {
            $entryId = self::queryEntryId($slug, self::SYNDICATING_SITE);

            if ($entryId === null) {
                $normalized = Str::slug($slug);

                // Skipping an empty or unchanged form only saves a lookup that would miss
                // @pest-mutate-ignore: BooleanAndToBooleanOr, EmptyStringToNotEmpty
                if ($normalized !== '' && $normalized !== $slug) {
                    $entryId = self::queryEntryId($normalized, self::SYNDICATING_SITE);
                }
            }
        }

        // A miss is not memoized; caching a null would read as a miss through isset() anyway,
        // so this only keeps the cache's type
        if ($entryId === null) {
            // @pest-mutate-ignore: RemoveEarlyReturn
            return null;
        }

        self::$cache[$cacheKey] = $entryId;

        return $entryId;
    }

    /**
     * Query one site's `post_categories` collection for an entry with this slug.
     *
     * @param  string  $slug  The slug to match exactly
     * @param  string  $siteHandle  The Statamic site handle
     * @return ?string The entry id, or null when that site has no such entry
     */
    private static function queryEntryId(
        string $slug,
        string $siteHandle
    ): ?string {
        // Build the query in statements rather than a chain: the builder's
        // `where()` is untyped, so chaining loses the typed receiver. Collection,
        // locale and slug identify one entry, so `first()` is a lookup rather
        // than a sample.
        $query = EntryQueryHelper::query();
        $query->where('collection', self::COLLECTION);
        $query->where('locale', $siteHandle);
        $query->where('slug', $slug);

        $entry = $query->first();

        $entryId = $entry instanceof Entry
            ? $entry->id()
            : null;

        // A saved entry always has an id
        return \is_string($entryId) ? $entryId : null;
    }
}
