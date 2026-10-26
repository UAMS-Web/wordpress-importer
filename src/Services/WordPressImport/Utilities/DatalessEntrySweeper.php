<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Yaml\Yaml;

/**
 * Removes entry files a run materialized with no data in them.
 *
 * **What this exists for (wordpress-importer-archive#1160).** An import into one site wrote 37 `knowledge_bases`
 * entries into a *different* site — one it was not asked to touch and which had no content
 * on disk — inside 70 milliseconds. Each file carried an `id` and a `blueprint` and nothing
 * else: no title, no body, no data of any kind. Nothing in the run's output, its warning
 * summary or the log mentioned them.
 *
 * They are not a stale cache re-serialized. The ids were **freshly minted**, and the host
 * app rejects every one of them — saving one through the normal path throws `Article entries
 * must have a Topic parent` from its own validation listener. So the run invented content
 * that had never existed, in a shape the app considers invalid, and did it without firing
 * `EntrySaving`.
 *
 * **Why this removes rather than refuses.** The write happens inside Statamic's
 * `CollectionEntriesStore::writeItemToDisk()` during a Stache flush, so there is no call site
 * this package owns where it could be declined. Refusing it would mean registering a
 * subclassed store — which `uams-statamic` would receive on a routine `^0.20` update without
 * that repository deciding to, and which would couple it to `statamic/cms` internals it pins
 * exactly. Recorded on wordpress-importer-archive#1160 and in `docs/DECISIONS.md`; widening the `TreeBuilder`
 * paths-cache clear stays available and is insufficient alone while the trigger is unknown.
 *
 * **Three conditions gate the delete, and all three are checked.** Stated here because this
 * class removes files and the next reader auditing it should not have to derive them: the file
 * was written since the run began; its front matter parsed and carries nothing but `id` and
 * `blueprint`; and it has no body. An earlier version described all three and checked only the
 * first two — the front-matter split discarded the body, so `id` + `blueprint` + real prose was
 * eligible for deletion while this paragraph said it was not.
 *
 * **The sweep is bounded by modification time on purpose.** The defect writes into a site the
 * run never names, so it cannot be scoped by collection or site — but it can be scoped to
 * what the run itself touched. Anything older than the run is somebody else's, and this
 * deletes files.
 */
class DatalessEntrySweeper
{
    /**
     * Keys that do not, on their own, make an entry worth keeping.
     *
     * `id` is not evidence of content — every stub has one — and `blueprint` is the only
     * other key the observed files carried.
     *
     * @var list<string>
     */
    private const array INERT_KEYS = ['id', 'blueprint'];

    /**
     * Whether parsed front matter describes an entry carrying no data.
     *
     * Separated from the filesystem walk so the judgement can be tested directly, which is
     * what wordpress-importer-archive#1160's fifth criterion asks for: the trigger reproduced once in three attempts,
     * so a test that depends on provoking it would be a test that usually proves nothing.
     *
     * @param  array<int|string, mixed>  $frontMatter
     */
    public static function isDataless(array $frontMatter): bool
    {
        // A loose comparison gives the same answer: a key is an int or a string, and PHP 8
        // compares an int with a non-numeric string as strings, so 0 never equals 'id'.
        // @pest-mutate-ignore: TrueToFalse
        return array_all($frontMatter, fn ($value, $key): bool => \in_array($key, self::INERT_KEYS, true));
    }

    /**
     * Parse the YAML front matter of a Statamic content file.
     *
     * Returns null when the file is not front-matter-shaped, which is treated as "not mine to
     * judge" rather than as an empty entry — the difference decides whether a file is deleted.
     *
     * @return ?array<int|string, mixed>
     */
    public static function frontMatter(string $contents): ?array
    {
        if (! \str_starts_with($contents, '---')) {
            return null;
        }

        // Only the first two parts are read, so a larger limit splits the same front matter.
        // @pest-mutate-ignore: IncrementInteger
        $parts = \preg_split('/^---\s*$/m', $contents, 3);

        if (! \is_array($parts) || \count($parts) < 2) {
            return null;
        }

        try {
            $parsed = Yaml::parse($parts[1]);
        } catch (\Throwable) {
            // Unparseable front matter is not an empty entry.
            return null;
        }

        return \is_array($parsed) ? $parsed : null;
    }

    /**
     * The body of a Statamic content file — everything after the front matter.
     *
     * **Separate from `frontMatter()` because the front matter alone cannot answer the
     * question this class asks.** The docblock above describes its targets as carrying "no
     * title, no body, no data of any kind", and for a while only the first two were checked:
     * the split discarded the body and a file with `id`, `blueprint` and real prose was
     * eligible for deletion while the documentation said otherwise.
     *
     * Returns an empty string when there is no body, and when the file is not
     * front-matter-shaped at all — that second case never reaches a delete, because
     * `frontMatter()` refuses it first.
     */
    public static function body(string $contents): string
    {
        if (! \str_starts_with($contents, '---')) {
            return '';
        }

        $parts = \preg_split('/^---\s*$/m', $contents, 3);

        return \is_array($parts) && \count($parts) >= 3 ? \trim($parts[2]) : '';
    }

    /**
     * Remove every dataless entry file written at or after the given time.
     *
     * @param  string  $collectionsRoot  Resolved by `ContentRoot::collections()`, never `base_path()`
     * @param  float  $since  Unix timestamp; files older than this are left alone
     * @return list<array{path: string, collection: string, site: string, slug: string, id: string}>
     */
    public static function sweep(string $collectionsRoot, float $since): array
    {
        if (! \is_dir($collectionsRoot)) {
            return [];
        }

        $removed = [];

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($collectionsRoot, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            // The iterator yields only SplFileInfo, so the type check guards PHPStan's view of
            // it rather than any entry a sweep can meet.
            // @pest-mutate-ignore: InstanceOfToTrue
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'md' || ! $file->isFile()) {
                continue;
            }

            // Bounded to this run. Anything older predates it and is not ours to delete.
            if ($file->getMTime() < $since) {
                continue;
            }

            $path = $file->getPathname();

            // A file can be listed and still be unreadable (its permissions, or a lock). The
            // failed read becomes an empty string, which is not front-matter-shaped, so the
            // file is left alone below and the sweep carries on.
            //
            // The unreadable-file test kills RemoveStringCast when run directly. Under
            // `pest --mutate` it cannot: the plugin's stream wrapper stats an unreadable path
            // as absent, so isFile() skips the file before this read.
            // @pest-mutate-ignore: RemoveStringCast
            $contents = (string) \file_get_contents($path);

            $frontMatter = self::frontMatter($contents);

            // A file whose front matter could not be read is left alone deliberately.
            if ($frontMatter === null || ! self::isDataless($frontMatter)) {
                continue;
            }

            // A body is data, whatever the front matter says. Checked because this class
            // claims to target files with "no body", and for a while it did not look.
            if (self::body($contents) !== '') {
                continue;
            }

            $descriptor = self::describe($collectionsRoot, $path, $frontMatter);

            if (@\unlink($path)) {
                $removed[] = $descriptor;
            }
        }

        return $removed;
    }

    /**
     * Describe a file for the log and the warning summary.
     *
     * The collection and site come from the path rather than the file, because a dataless
     * entry carries neither — which is the whole problem with it.
     *
     * @param  array<int|string, mixed>  $frontMatter
     * @return array{path: string, collection: string, site: string, slug: string, id: string}
     */
    private static function describe(string $collectionsRoot, string $path, array $frontMatter): array
    {
        $relative = \ltrim(\str_replace('\\', '/', \substr($path, \strlen($collectionsRoot))), '/');
        $segments = \explode('/', $relative);
        $id = $frontMatter['id'] ?? null;

        return [
            'path' => $relative,
            'collection' => $segments[0],
            // A collection with no multi-site split has the file directly beneath it.
            'site' => \count($segments) > 2 ? $segments[1] : '(none)',
            'slug' => \basename($path, '.md'),
            'id' => \is_string($id) ? $id : '(none)',
        ];
    }
}
