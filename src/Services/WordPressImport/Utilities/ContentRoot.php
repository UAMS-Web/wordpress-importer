<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities;

use RuntimeException;
use Statamic\Facades\Stache as StacheFacade;

/**
 * The filesystem root that flat-file content resolves under.
 *
 * The host app reads the `content/` git submodule in production and a
 * disposable mirror of it under `APP_ENV=testing`, so no test can write into
 * the submodule (`UAMS-Web/uams-statamic-archive#1995`). That re-rooting is applied to
 * every content-backed Stache store, and a `base_path('content/…')` in this
 * package does not follow it.
 *
 * Re-rooting one surface without the other is not a partial fix but a
 * destructive one: a surface still pointed at `content/` while the run writes
 * to the mirror reads content the run did not produce, and — where it deletes
 * — deletes content no run produced.
 *
 * **The store is the source of truth, not a copy of the host's rule.** This
 * resolver asks Statamic where each content directory actually is rather than
 * re-deriving it from the environment, so the host can move the root again
 * without this package having to be told. Duplicating the host's
 * `testing`-vs-production branch here would put the drift back on the first
 * day the two disagree.
 */
class ContentRoot
{
    /**
     * The Statamic store that owns each `content/` subdirectory.
     *
     * Keyed by the subdirectory as it appears under `content/`, longest path
     * first so `trees/collections` is matched before a hypothetical `trees`.
     * Mirrors the host's `config/statamic/stache.php`, which points every one
     * of these at its shared content root.
     *
     * @var array<string, string>
     */
    // A constant initializer is never reported as an executed line, so Pest runs no test
    // against a mutant here. Removing any entry by hand fails its row of the
    // "re-roots each content subdirectory" dataset in ContentRootTest.
    // @pest-mutate-ignore: RemoveArrayItem
    private const array STORE_BY_SUBDIRECTORY = [
        'trees/collections' => 'collection-trees',
        'trees/navigation' => 'nav-trees',
        'collections' => 'entries',
        'taxonomies' => 'taxonomies',
        'navigation' => 'navigation',
        'globals' => 'globals',
        'assets' => 'asset-containers',
    ];

    /**
     * The directory collection entries are written to.
     *
     * Absolute, with no trailing separator, so callers append their own.
     *
     * @return string The absolute collections directory
     */
    public static function collections(): string
    {
        return self::storeDirectory('entries');
    }

    /**
     * The absolute path of one collection entry's file.
     *
     * Collection entries live at `<collections>/<collection>/<site>/<slug>.md`.
     * Building that here keeps the four table-entry call sites (wordpress-importer-archive#724) off a
     * `content/…`-relative string handed to Statamic's default disk, which is
     * rooted at the app root and therefore does not follow the test-env
     * re-root any more than `base_path()` does.
     *
     * @param  string  $collection  The collection handle, e.g. `tables`
     * @param  string  $site  The site handle
     * @param  string  $slug  The entry slug, without the `.md` extension
     * @return string The absolute path to the entry file
     */
    public static function collectionEntry(
        string $collection,
        string $site,
        string $slug
    ): string {
        return self::collections()."/{$collection}/{$site}/{$slug}.md";
    }

    /**
     * The directory taxonomy terms are written to.
     *
     * Absolute, with no trailing separator, so callers append their own.
     *
     * @return string The absolute taxonomies directory
     */
    public static function taxonomies(): string
    {
        return self::storeDirectory('taxonomies');
    }

    /**
     * Resolve a repo-relative path, re-rooting anything under `content/`.
     *
     * Paths outside `content/` — `public/assets/images`, say — are not content
     * and resolve through `base_path()` unchanged; only the content tree moves
     * under test.
     *
     * @param  string  $repoRelativePath  A path relative to the app root, e.g.
     *                                    `content/collections/posts`
     * @return string The absolute path, with no trailing separator
     *
     * @throws RuntimeException When the path is under `content/` but no
     *                          Statamic store owns that subdirectory
     */
    public static function resolve(string $repoRelativePath): string
    {
        // Normalize separators and strip any leading or trailing slash
        $path = \trim(\str_replace('\\', '/', $repoRelativePath), '/');

        // Anything outside the content tree is not re-rooted
        if (! str_starts_with($path.'/', 'content/')) {
            // setBasePath() strips any trailing separator and the path is already trimmed,
            // so base_path() never ends in one and the trim changes no result; it keeps
            // the no-trailing-separator contract from resting on Laravel's behavior.
            // @pest-mutate-ignore: UnwrapRtrim
            return \rtrim(base_path($path), '/');
        }

        // Drop the `content/` prefix to get the subdirectory the stores own
        $withinContent = \substr($path, \strlen('content/'));

        // Match the longest owning subdirectory first
        foreach (self::STORE_BY_SUBDIRECTORY as $subdirectory => $storeKey) {
            if (
                $withinContent !== $subdirectory
                && ! str_starts_with($withinContent, $subdirectory.'/')
            ) {
                continue;
            }

            // Re-root the remainder under the store's own directory
            $remainder = \trim(\substr($withinContent, \strlen($subdirectory)), '/');
            $directory = self::storeDirectory($storeKey);

            return $remainder === '' ? $directory : $directory.'/'.$remainder;
        }

        // Fail visibly rather than silently resolving back onto the submodule
        throw new RuntimeException(
            "ContentRoot: no Statamic store owns 'content/{$withinContent}'. "
            .'Add its store to ContentRoot::STORE_BY_SUBDIRECTORY rather than '
            .'falling back to base_path(), which does not follow the test-env re-root.'
        );
    }

    /**
     * Read a Stache store's configured directory.
     *
     * The `Stache` facade is annotated `@method static mixed store($key)`, so
     * the result is narrowed here — once — rather than at each call site.
     *
     * @param  string  $storeKey  The Stache store key, e.g. `entries`
     * @return string The store's absolute directory, with no trailing separator
     *
     * @throws RuntimeException When the store is missing or has no directory
     */
    private static function storeDirectory(string $storeKey): string
    {
        // Ask Statamic for the store rather than rebuilding the path
        $store = StacheFacade::store($storeKey);

        if (
            ! \is_object($store)
            || ! \method_exists($store, 'directory')
        ) {
            throw new RuntimeException(
                "ContentRoot: the Stache store '{$storeKey}' is unavailable, so the "
                .'content root cannot be resolved.'
            );
        }

        // Statamic returns the directory with a trailing separator
        $directory = $store->directory();

        if (
            ! \is_string($directory)
            || $directory === ''
        ) {
            throw new RuntimeException(
                "ContentRoot: the Stache store '{$storeKey}' has no configured directory."
            );
        }

        // Normalize so callers can append their own separator
        return \rtrim(\str_replace('\\', '/', $directory), '/');
    }
}
