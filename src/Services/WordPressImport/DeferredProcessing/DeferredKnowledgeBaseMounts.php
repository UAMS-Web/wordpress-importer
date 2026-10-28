<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\DeferredProcessing;

use Illuminate\Support\Facades\Log;
use Statamic\Entries\Entry;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\EntryFinder;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;

/**
 * Registry of knowledge base mounts to apply after all content is imported.
 *
 * A MinervaKB "home page" (the WordPress page identified by the `kb_page`
 * setting) no longer becomes a `pages` entry. Instead the knowledge base root
 * entry mounts beneath that page's parent — it resolves at
 * `{parent_page_uri}/{kb_slug}`, which is the URL the WordPress page occupied.
 *
 * The mount target is the imported `pages` entry for the kb_page's WordPress
 * parent, which may not exist yet when the knowledge base is imported (content
 * types import in user-selected order). So the mount is recorded here during KB
 * import and applied in the final deferred pass, once every collection has been
 * imported and the parent page entry is guaranteed to exist if it was imported
 * at all.
 *
 * Mirrors the static deferred-registry pattern used by LinkProcessor and
 * WpBlockBlockProcessor.
 */
class DeferredKnowledgeBaseMounts
{
    /**
     * Pending mounts, each: ['kb_root_id' => ..., 'parent_wp_id' => ..., 'site' => ...].
     *
     * @var array<int, array{kb_root_id: string, parent_wp_id: string, site: string}>
     */
    private static array $mounts = [];

    /**
     * Record a knowledge base mount to apply during the final deferred pass.
     *
     * @param  string  $kbRootId  The knowledge base root entry ID (knowledge_bases collection)
     * @param  string  $parentWpId  The WordPress post ID of the kb_page's parent page
     * @param  string  $site  The site handle
     */
    public static function defer(
        string $kbRootId,
        string $parentWpId,
        string $site
    ): void {
        // Record the pending mount for resolution after all content is imported
        self::$mounts[] = [
            'kb_root_id' => $kbRootId,
            'parent_wp_id' => $parentWpId,
            'site' => $site,
        ];
    }

    /**
     * Return all pending mounts (primarily for testing/inspection).
     *
     * @return array<int, array{kb_root_id: string, parent_wp_id: string, site: string}>
     */
    public static function all(): array
    {
        // Expose the current registry contents
        return self::$mounts;
    }

    /**
     * Clear all pending mounts.
     */
    public static function clear(): void
    {
        // Reset the registry
        self::$mounts = [];
    }

    /**
     * Apply every pending mount for the given site.
     *
     * For each pending mount, resolves the kb_page's parent `pages` entry by its
     * WordPress ID and sets the knowledge base root's `mount_parent` field to it.
     * The host app recomputes the mounted subtree's URIs when `mount_parent`
     * changes, so no further work is needed here.
     *
     * Mounts for other sites are left untouched so their own final pass can
     * resolve them. A mount whose parent page or root entry cannot be found is
     * dropped with a warning — the knowledge base still resolves at its default
     * (unmounted) URL.
     *
     * @param  string  $site  The site handle whose mounts to resolve
     * @param  OutputInterface  $output  Console output interface
     */
    public static function resolve(
        string $site,
        OutputInterface $output
    ): void {
        // Nothing to do when no mounts are pending
        if (self::$mounts === []) {
            return;
        }

        // Mounts belonging to other sites are kept for their own final pass
        $remaining = [];

        // Apply each pending mount that targets the current site
        foreach (self::$mounts as $mount) {
            // Defer mounts for other sites to their own resolution pass
            if ($mount['site'] !== $site) {
                $remaining[] = $mount;

                continue;
            }

            // Resolve the parent page entry the knowledge base should mount under. A fresh
            // read would only drop the Stache's copy first and find the same entry.
            // @pest-mutate-ignore: FalseToTrue
            $parentEntry = EntryFinder::findEntryByWordPressId(
                'pages',
                $mount['parent_wp_id'],
                false,
                $site
            );

            // Without the parent page the knowledge base cannot be mounted
            if (! $parentEntry instanceof Entry) {
                // Warn the user and leave the knowledge base at its default URL
                $output->writeln("<comment>Warning: Could not mount knowledge base — its parent page (WordPress ID '{$mount['parent_wp_id']}') was not imported. The knowledge base will resolve at its default URL. Import the parent page and re-run to mount it.</comment>");

                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'kb_root_id' => $mount['kb_root_id'],
                        'parent_wordpress_post_id' => $mount['parent_wp_id'],
                        'site' => $site,
                    ]
                );

                // Log warning for debugging and monitoring purposes
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        "DeferredKnowledgeBaseMounts::resolve: Parent page (WordPress ID '{$mount['parent_wp_id']}') not found for site {$site}; knowledge base left unmounted.",
                        $logData['page_url']
                    ),
                    $logData['context']
                );

                continue;
            }

            // Load the knowledge base root entry to mount. A fresh read would only drop the
            // Stache's copy first and find the same entry.
            // @pest-mutate-ignore: FalseToTrue
            $kbRoot = EntryFinder::findEntryById(
                'knowledge_bases',
                $mount['kb_root_id'],
                false,
                $site
            );

            // Without the root entry there is nothing to mount
            if (! $kbRoot instanceof Entry) {
                // Warn and skip — the root entry should normally exist by now
                $output->writeln("<comment>Warning: Could not mount knowledge base — root entry '{$mount['kb_root_id']}' was not found.</comment>");

                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'kb_root_id' => $mount['kb_root_id'],
                        'site' => $site,
                    ]
                );

                // Log warning for debugging and monitoring purposes
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        "DeferredKnowledgeBaseMounts::resolve: Knowledge base root entry '{$mount['kb_root_id']}' not found for site {$site}.",
                        $logData['page_url']
                    ),
                    $logData['context']
                );

                continue;
            }

            // Set the mount reference; the host app restamps the subtree's URIs
            $data = self::entryDataToArray($kbRoot);
            $data['mount_parent'] = [$parentEntry->id()];

            // Reset initialPath so the entry path is rebuilt on save. Mounting changes no
            // slug, so the rebuilt path is the one the entry already has.
            // @pest-mutate-ignore: RemoveMethodCall
            $kbRoot->initialPath(null);

            // Persist the mount reference
            $kbRoot->data($data);
            $kbRoot->save();

            // Resolve slugs for user-facing output
            $kbRootSlug = $kbRoot->slug();
            $parentEntrySlug = $parentEntry->slug();

            // Inform the user the knowledge base was mounted
            $output->writeln(
                "<info>Mounted knowledge base '"
                .(is_string($kbRootSlug) ? $kbRootSlug : '')
                ."' beneath page '"
                .(is_string($parentEntrySlug) ? $parentEntrySlug : '')
                ."'.</info>"
            );
        }

        // Keep only mounts that belong to other sites
        self::$mounts = $remaining;
    }

    /**
     * Read a Statamic entry's data as a plain array.
     *
     * @param  Entry  $entry  The entry whose data should be read
     * @return array<int|string, mixed> Entry data, or an empty array when unavailable
     */
    private static function entryDataToArray(Entry $entry): array
    {
        // Read the entry's current data object
        $entryData = $entry->data();

        // Return an empty array when data() did not yield a readable object. An entry's
        // data() always answers with a collection, so this only narrows the type for
        // phpstan.
        // @pest-mutate-ignore: BooleanOrToBooleanAnd, RemoveEarlyReturn
        if (
            ! is_object($entryData)
            || ! method_exists(
                $entryData,
                'toArray'
            )
        ) {
            return [];
        }

        // Collect existing values into a plain array
        $data = $entryData->toArray();

        return is_array($data)
            ? $data
            : [];
    }
}
