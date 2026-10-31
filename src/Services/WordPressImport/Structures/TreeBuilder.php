<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Structures;

use Illuminate\Support\Facades\File as IlluminateFileFacade;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\Collection;
use Statamic\Facades\Path;
use Statamic\Facades\Stache as StacheFacade;
use Statamic\Stache\Repositories\CollectionTreeRepository;
use Statamic\Structures\CollectionStructure;
use Statamic\Yaml\Yaml;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentRoot;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Traits\StacheCacheManagementTrait;

/**
 * Builds and saves the nested tree structure for Statamic collections.
 *
 * Constructs a hierarchical tree from WordPress page data based on parent-child
 * relationships and menu order, then saves it to the collection's tree structure
 * for multi-site organization using Statamic's CollectionTree and its repository.
 */
class TreeBuilder
{
    /**
     * Use Stache cache management functionality.
     */
    use StacheCacheManagementTrait;

    /**
     * Tracks collections and sites processed in the current import session.
     *
     * @var array<int|string, mixed>
     */
    protected static $processedCollections = [];

    /**
     * Create a new TreeBuilder instance with its dependencies.
     *
     * @param  CollectionTreeRepository  $treeRepository  Repository for managing collection trees
     */
    public function __construct(protected CollectionTreeRepository $treeRepository) {}

    /**
     * Reset the processed collections cache for a new import session.
     */
    public static function resetProcessedCollections(): void
    {
        self::$processedCollections = [];
    }

    /**
     * Build and save the collection tree structure from page data with front-page
     * detection.
     *
     * Creates a nested tree, identifies the front page by matching its link to the
     * base blog URL, sets it as the root, and sorts remaining pages by wp:menu_order
     * and title. Skips tree-building for collections without a structure or if
     * already processed in the current session.
     *
     * @param  string  $site  The selected site handle (e.g., 'student_information_system')
     * @param  string  $contentType  The selected content type (e.g., 'page', 'post')
     * @param  string  $collectionHandle  The handle of the collection (e.g., 'pages', 'post')
     * @param  array<int|string, mixed>  $pageData  Page data from EntryCreator, with entry IDs, parents, orders, and links
     * @param  OutputInterface  $output  Console output interface for logging
     * @param  ?string  $baseBlogUrl  The base blog URL from the XML (e.g., 'https://sis.uams.edu')
     */
    public function buildAndSaveTree(
        string $site,
        string $contentType,
        string $collectionHandle,
        array $pageData,
        OutputInterface $output,
        ?string $baseBlogUrl = null
    ): void {
        $collection = Collection::findByHandle($collectionHandle);
        if ($collection === null) {
            $output->writeln("<error>Collection '$collectionHandle' not found.</error>");

            return;
        }

        // Skip tree-building for collections without a structure
        if (! $collection->hasStructure()) {
            return;
        }

        // Check if tree has already been built for this collection and site
        $key = "$collectionHandle:$site";

        // Every key is a "handle:site" string, so strict and loose comparison agree.
        // @pest-mutate-ignore: TrueToFalse
        if (
            \in_array(
                $key,
                self::$processedCollections,
                true
            )
        ) {
            return;
        }

        $tree = $this->buildTree(
            $pageData,
            $baseBlogUrl
        );

        // A collection that has a structure always answers one, made from its contents when
        // none is held, so anything but a CollectionStructure here is refused rather than built
        $structure = $collection->structure();

        if (! $structure instanceof CollectionStructure) {
            return;
        }

        $collectionTree = $structure->in($site);
        if (! $collectionTree) {
            $output->writeln("<comment>No tree exists for '$collectionHandle' in site '$site'. Creating a new one.</comment>");
            $collectionTree = $structure->makeTree(
                $site,
                []
            );
        }

        // ---
        // Clear the paths cache for the entries store to ensure paths are
        // rebuilt with current file paths (including date prefixes for dated
        // collections). This prevents stale paths from causing stub entries
        // to be created when Entry::find() is called with IDs that have
        // updated file paths.
        // ---
        $this->clearCollectionCache(
            $collectionHandle
        );

        if (
            \is_object($collectionTree)
            && \method_exists($collectionTree, 'tree')
            && \method_exists($collectionTree, 'save')
        ) {
            $collectionTree->tree($tree);
            $collectionTree->save();
        }

        $output->writeln("<info>Updated tree structure for '$collectionHandle' in site '$site'.</info>");

        // Clean up any duplicate entry files (same ID, different paths)
        $this->cleanupDuplicateEntryFiles(
            $collectionHandle,
            $site,
            $output
        );

        // Mark this collection and site as processed
        self::$processedCollections[] = $key;
    }

    /**
     * Build a nested tree structure from page data with front-page root detection.
     *
     * Identifies the front page by matching its link to the base blog URL, sets it as
     * the root, organizes remaining pages hierarchically using wp:post_parent, and
     * sorts by wp:menu_order and title. Ensures all pages are included, either as
     * children of their parent, as top-level entries if the parent is missing, or
     * recursively assigned to nested parents.
     *
     * @param  array<int|string, mixed>  $pageData  Array of page data with 'entry', 'parent', 'menu_order', 'title', and 'link'
     * @param  ?string  $baseBlogUrl  The base blog URL to identify the front page
     * @return array<int|string, mixed> The nested tree structure with front page as root if detected
     */
    protected function buildTree(
        array $pageData,
        ?string $baseBlogUrl = null
    ): array {
        $tree = [];
        $children = [];
        $frontPage = null;
        $unassigned = []; // Track pages not assigned to children or tree

        // Step 1: Identify front page and separate it
        if ($baseBlogUrl !== null && $baseBlogUrl !== '') {
            foreach ($pageData as $postId => $data) {
                if (! \is_array($data)) {
                    continue;
                }

                $link = self::extractNullableString($data['link'] ?? null);

                if (
                    $link !== null
                    && \rtrim(
                        $link,
                        '/'
                    ) === \rtrim(
                        $baseBlogUrl,
                        '/'
                    )
                ) {
                    $frontPage = $data;
                    unset($pageData[$postId]);
                    break;
                }
            }
        }

        // Step 2: Build hierarchy for remaining pages
        foreach ($pageData as $postId => $data) {
            if (! \is_array($data)) {
                continue;
            }

            $parentId = self::extractScalarString($data['parent'] ?? null);
            $entryId = self::extractScalarString($data['entry'] ?? null);
            $link = self::extractNullableString($data['link'] ?? null);

            if ($entryId === '') {
                continue;
            }

            if ($parentId !== '' && $parentId !== '0') {
                if (isset($pageData[$parentId])) {
                    $parentNode = $pageData[$parentId];

                    if (! \is_array($parentNode)) {
                        continue;
                    }

                    $parentEntry = self::extractScalarString($parentNode['entry'] ?? null);

                    if ($parentEntry === '') {
                        continue;
                    }

                    $children[$parentEntry][] = $data;
                } else {
                    $unassigned[$postId] = $data;

                    // ---
                    // Enhance log context with entry information. Use the actual page's link URL if
                    // available, not the current ProcessingContext URL.
                    // ---
                    $logData = LogContextHelper::enhanceWithEntryContext(
                        [
                            'postId' => $postId,
                            'entry' => $entryId,
                            'parentId' => $parentId,
                            'parentInPageData' => isset($pageData[$parentId]),
                            'totalPagesInData' => \count($pageData),
                        ],
                        $link
                    );

                    // Log warning about parent not found
                    Log::warning(
                        LogContextHelper::formatMessageWithEntry(
                            'TreeBuilder::buildTree: Parent not found for page; marking as unassigned',
                            $logData['page_url']
                        ),
                        $logData['context']
                    );
                }
            } else {
                $tree[] = $data;
            }
        }

        // Step 3: Sort top-level tree
        $tree = $this->sortTreeLevel($tree);

        // Step 4: Recursively assign children and clean nodes
        $assignedEntries = []; // Track entries added to tree
        $tree = $this->assignChildrenRecursively(
            $tree,
            $children,
            $assignedEntries
        );

        // Step 5: Add unassigned pages to top-level tree, each with its descendants
        $addedUnassigned = [];
        $orphans = [];
        foreach ($unassigned as $data) {
            $entryId = self::extractScalarString($data['entry'] ?? null);

            // Both sides are entry strings, so strict and loose comparison agree.
            // @pest-mutate-ignore: TrueToFalse
            if (
                ! \in_array(
                    $entryId,
                    $assignedEntries,
                    true
                )
            ) {
                $orphans[] = $data;
                $addedUnassigned[] = $entryId;
            }
        }

        // Attach the orphans' descendants as step 4 does the roots', keeping the orphans
        // in import order after the sorted roots (#251)
        $tree = [
            ...$tree,
            ...$this->assignChildrenRecursively(
                $orphans,
                $children,
                $assignedEntries
            ),
        ];

        // ---
        // Step 6: Verify all pages are included. The front page was removed from
        // $pageData in step 1, so only another page sharing its entry can name it here,
        // and the front page itself is never missing. A repeated entry collapses to one
        // key in the map below.
        // ---
        $missingEntries = array_diff(
            self::normalizeStringList(array_column(
                $pageData,
                'entry'
            )),
            self::normalizeStringList($assignedEntries),
            $addedUnassigned,
            $frontPage !== null ? [self::extractScalarString($frontPage['entry'] ?? null)] : []
        );
        if ($missingEntries !== []) {
            $missingEntriesMap = array_combine(
                $missingEntries,
                $missingEntries
            ); // Use entry IDs as keys

            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                ['missing_entries' => $missingEntriesMap]
            );

            // Log warning about missing entries
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'TreeBuilder::buildTree: Pages missing from final tree',
                    $logData['page_url']
                ),
                $logData['context']
            );
        }

        // Step 7: Prepend front page as root if found
        if ($frontPage !== null) {
            $frontNode = $this->cleanNode($frontPage);
            $frontEntry = self::extractScalarString($frontNode['entry'] ?? null);

            // No child is filed under an empty entry, so an empty one finds none
            if (\is_array($children[$frontEntry] ?? null)) {
                $frontNode['children'] = $this->sortTreeLevel($children[$frontEntry]);
                foreach ($frontNode['children'] as &$child) {
                    if (\is_array($child)) {
                        $child = $this->cleanNode($child);
                    }
                }
            }

            array_unshift(
                $tree,
                $frontNode
            );
        }

        return $tree;
    }

    /**
     * Recursively assign children to nodes and clean them.
     *
     * Processes each node in the tree, assigns its children from the children array,
     * sorts them, and recursively processes each child's children. Cleans nodes to
     * include only 'entry' and 'children' keys. Tracks assigned entries to verify
     * inclusion in the final tree.
     *
     * A node whose entry is already on its own ancestor path is left out and logged:
     * children are filed by entry ID, so it would nest its ancestor's children under
     * itself again, without end (#301).
     *
     * @param  array<int|string, mixed>  $nodes  The array of nodes to process
     * @param  array<int|string, mixed>  $children  The array of children indexed by parent entry ID
     * @param  array<int|string, mixed>  &$assignedEntries  Reference to array tracking assigned entries
     * @param  list<string>  $ancestors  The entry IDs of the nodes above these, root first
     * @return array<int|string, mixed> The processed nodes with children assigned
     */
    protected function assignChildrenRecursively(
        array $nodes,
        array $children,
        array &$assignedEntries,
        array $ancestors = []
    ): array {
        $repeated = [];

        foreach ($nodes as $key => &$node) {
            // Every node reaching here is a page buildTree() already accepted: an array
            // with a non-empty entry. The two guards only type what follows.
            // @pest-mutate-ignore: ContinueToBreak
            if (! \is_array($node)) {
                continue;
            }

            $entry = self::extractScalarString($node['entry'] ?? null);

            // @pest-mutate-ignore: EmptyStringToNotEmpty, ContinueToBreak
            if ($entry === '') {
                continue;
            }

            // Leave out a node that repeats an ancestor's entry, and say so
            if (
                \in_array(
                    $entry,
                    $ancestors,
                    true
                )
            ) {
                $repeated[] = $key;

                // Log the repeated entry and the path it was found on
                $logData = LogContextHelper::enhanceWithEntryContext(
                    ['entry' => $entry, 'ancestors' => $ancestors]
                );
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        'TreeBuilder::buildTree: Page shares its entry with an ancestor; left out of the tree',
                        $logData['page_url']
                    ),
                    $logData['context']
                );

                continue;
            }

            $assignedEntries[] = $entry;
            if (
                isset($children[$entry])
                && \is_array($children[$entry])
            ) {
                $node['children'] = $this->sortTreeLevel($children[$entry]);
                // Recursively assign children to this node's children
                $node['children'] = $this->assignChildrenRecursively(
                    $node['children'],
                    $children,
                    $assignedEntries,
                    [...$ancestors, $entry]
                );

                // A node whose every child was left out is a leaf
                if ($node['children'] === []) {
                    unset($node['children']);
                }
            }

            $node = $this->cleanNode($node);
        }

        unset($node);

        // Drop the repeated nodes, keeping the level a list
        if ($repeated !== []) {
            foreach ($repeated as $key) {
                unset($nodes[$key]);
            }

            $nodes = \array_values($nodes);
        }

        return $nodes;
    }

    /**
     * Sort a level of tree nodes by menu order and then title.
     *
     * @param  array<int|string, mixed>  $nodes  Array of nodes to sort
     * @return array<int|string, mixed> Sorted nodes
     */
    protected function sortTreeLevel(
        array $nodes
    ): array {
        usort(
            $nodes,
            fn ($a, $b): int => self::compareTreeNodes(
                $a,
                $b
            )
        );

        return $nodes;
    }

    /**
     * Clean a tree node to include only 'entry' and 'children' keys.
     *
     * @param  array<int|string, mixed>  $node  The node to clean
     * @return array<int|string, mixed> Cleaned node with only 'entry' and optional 'children'
     */
    protected function cleanNode(
        array $node
    ): array {
        $entryId = self::extractScalarString($node['entry'] ?? null);
        $cleaned = ['entry' => $entryId];
        if (
            isset($node['children'])
            && \is_array($node['children'])
        ) {
            $cleaned['children'] = $node['children'];
        }

        return $cleaned;
    }

    /**
     * Clean up duplicate entry files that share the same entry ID.
     *
     * Scans all files in the collection directory, groups them by entry ID from
     * their YAML frontmatter, and removes duplicate files. For dated collections,
     * keeps the file with the date prefix. For non-dated collections, keeps the
     * file that matches the paths index. Logs deleted files for debugging.
     *
     * **It scans the run's own content root, not `content/` (wordpress-importer-archive#622).** Its job is
     * de-duplicating what this run just wrote, so it has to look where the run
     * wrote. Reading `base_path('content/…')` broke that twice over under
     * `APP_ENV=testing`: it scanned the live submodule the run never touches,
     * while the paths index it compares against holds mirror paths — so the
     * "keep the canonical file" branch below could never match, and any
     * duplicate group would have fallen through to an arbitrary
     * `$fileGroup[0]`. Resolving both through {@see ContentRoot} makes the scan
     * and the index one tree by construction.
     *
     * @param  string  $collectionHandle  The collection handle
     * @param  string  $site  The site handle
     * @param  OutputInterface  $output  Console output interface for logging
     */
    protected function cleanupDuplicateEntryFiles(
        string $collectionHandle,
        string $site,
        OutputInterface $output
    ): void {
        // Follow the run's own content root, so the scan and the paths index
        // below are read from one tree rather than two
        $collectionPath = ContentRoot::collections()."/{$collectionHandle}/{$site}";

        if (! IlluminateFileFacade::exists($collectionPath)) {
            return;
        }

        $files = IlluminateFileFacade::files($collectionPath);
        $filesById = [];

        // Group files by entry ID
        foreach ($files as $file) {
            $filePath = $file->getPathname();
            // An empty file parses to no id, so it is passed over below
            $contents = IlluminateFileFacade::get($filePath);

            try {
                $data = app(Yaml::class)->parse($contents);
                $entryId = self::extractScalarString($data['id'] ?? null);

                if ($entryId !== '') {
                    $filesById[$entryId] ??= [];

                    $filesById[$entryId][] = [
                        'path' => $filePath,
                        'filename' => $file->getFilename(),
                    ];
                }
            } catch (\Exception $e) {
                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'file' => $filePath,
                        'error' => $e->getMessage(),
                    ]
                );

                // Skip files that can't be parsed
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        'TreeBuilder::cleanupDuplicateEntryFiles: Failed to parse file',
                        $logData['page_url']
                    ),
                    $logData['context']
                );

                continue;
            }
        }

        // Get collection to check if it's dated
        $collection = Collection::findByHandle($collectionHandle);
        $isDated = $collection !== null && $collection->dated();

        // Get paths index to determine canonical paths
        // The entries store and its collection stores always carry these methods, so the
        // checks only type the calls.
        $entriesStore = StacheFacade::store('entries');

        // @pest-mutate-ignore: BooleanAndToBooleanOr
        $collectionStore = (
            \is_object($entriesStore)
            && \method_exists($entriesStore, 'store')
        ) ? $entriesStore->store($collectionHandle) : null;

        // @pest-mutate-ignore: BooleanAndToBooleanOr
        $pathsIndex = (
            \is_object($collectionStore)
            && \method_exists($collectionStore, 'paths')
        ) ? $collectionStore->paths() : null;

        // Process each group of files with the same ID
        foreach ($filesById as $entryId => $fileGroup) {
            // A group of one deletes nothing whether or not it is passed over here
            // @pest-mutate-ignore: SmallerOrEqualToSmaller, DecrementInteger
            if (\count($fileGroup) <= 1) {
                continue;
            }

            // Determine which file to keep
            $fileToKeep = null;
            $filesToDelete = [];

            if ($isDated) {
                // For dated collections, keep the file with date prefix
                foreach ($fileGroup as $fileInfo) {
                    $filename = $fileInfo['filename'];
                    // Date-prefixed files match pattern: YYYY-MM-DD-HHMM.slug.md
                    if (
                        \preg_match(
                            '/^\d{4}-\d{2}-\d{2}-\d{4}\..+\.md$/',
                            $filename
                        ) === 1
                    ) {
                        $fileToKeep = $fileInfo;
                        break;
                    }
                }

                // If no date-prefixed file found, keep the one matching paths index
                if (
                    $fileToKeep === null
                    && \is_object($pathsIndex)
                    && \method_exists($pathsIndex, 'get')
                ) {
                    $canonicalPath = $pathsIndex->get($entryId);
                    foreach ($fileGroup as $fileInfo) {
                        if (self::isSamePath($fileInfo['path'], $canonicalPath)) {
                            $fileToKeep = $fileInfo;

                            // Paths are unique, so no later file matches the index too
                            // @pest-mutate-ignore: BreakToContinue
                            break;
                        }
                    }
                }

                $fileToKeep ??= $fileGroup[0];
            } else {
                // For non-dated collections, keep the file matching paths index
                // The paths index is always a collection, so the check only types the call
                // @pest-mutate-ignore: BooleanAndToBooleanOr
                if (
                    \is_object($pathsIndex)
                    && \method_exists($pathsIndex, 'get')
                ) {
                    $canonicalPath = $pathsIndex->get($entryId);
                    foreach ($fileGroup as $fileInfo) {
                        if (self::isSamePath($fileInfo['path'], $canonicalPath)) {
                            $fileToKeep = $fileInfo;

                            // Paths are unique, so no later file matches the index too
                            // @pest-mutate-ignore: BreakToContinue
                            break;
                        }
                    }
                }

                $fileToKeep ??= $fileGroup[0];
            }

            // Mark other files for deletion
            foreach ($fileGroup as $fileInfo) {
                if ($fileInfo['path'] !== $fileToKeep['path']) {
                    $filesToDelete[] = $fileInfo;
                }
            }

            // Delete duplicate files
            foreach ($filesToDelete as $fileInfo) {
                // `delete()` returns false for a file it cannot remove, and rarely throws
                try {
                    $error = IlluminateFileFacade::delete($fileInfo['path'])
                        ? null
                        : 'The filesystem could not delete the file';
                } catch (\Exception $e) {
                    $error = $e->getMessage();
                }

                // A file still on disk is reported as a failure, and the group moves on
                if ($error !== null) {
                    $context = [
                        'collection' => $collectionHandle,
                        'site' => $site,
                        'entry_id' => $entryId,
                        'file' => $fileInfo['path'],
                        'error' => $error,
                    ];

                    Log::error(
                        'TreeBuilder::cleanupDuplicateEntryFiles: Failed to delete duplicate file',
                        $context
                    );
                    ImportWarningManager::addWarning('content', 'duplicate_entry_file_not_deleted', $context);

                    continue;
                }

                $output->writeln(
                    "<comment>Deleted duplicate entry file: {$fileInfo['filename']} (ID: {$entryId})</comment>"
                );
                Log::info(
                    'TreeBuilder::cleanupDuplicateEntryFiles: Deleted duplicate file',
                    [
                        'collection' => $collectionHandle,
                        'site' => $site,
                        'entry_id' => $entryId,
                        'deleted_file' => $fileInfo['filename'],
                        'kept_file' => $fileToKeep['filename'],
                    ]
                );
            }
        }
    }

    /**
     * Whether a file on disk is the one the Stache's paths index names.
     *
     * The two spell a Windows path differently: the Stache tidies its paths to forward
     * slashes, and a directory listing ends them with a backslash (#186).
     *
     * @param  string  $path  The file's path, from a directory listing
     * @param  mixed  $indexed  What the paths index holds for the entry
     * @return bool True when both name the same file
     */
    private static function isSamePath(string $path, mixed $indexed): bool
    {
        return \is_string($indexed) && Path::tidy($path) === Path::tidy($indexed);
    }

    /**
     * Normalize scalar values into strings.
     *
     * @param  mixed  $value  Raw value to normalize
     * @return string Normalized scalar string or empty string
     */
    private static function extractScalarString(
        mixed $value
    ): string {
        if (\is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * Normalize nullable scalar values into nullable strings.
     *
     * @param  mixed  $value  Raw value to normalize
     * @return string|null Normalized nullable string
     */
    private static function extractNullableString(
        mixed $value
    ): ?string {
        $stringValue = self::extractScalarString($value);

        return $stringValue === '' ? null : $stringValue;
    }

    /**
     * Normalize list-like values to string arrays.
     *
     * @param  array<int|string, mixed>  $values  Raw values list
     * @return array<int, string> Normalized string list
     */
    private static function normalizeStringList(
        array $values
    ): array {
        $normalized = [];

        foreach ($values as $value) {
            $stringValue = self::extractScalarString($value);

            if ($stringValue === '') {
                continue;
            }

            $normalized[] = $stringValue;
        }

        return $normalized;
    }

    /**
     * Compare two tree nodes by menu order, then title.
     *
     * @param  mixed  $firstNode  First node candidate
     * @param  mixed  $secondNode  Second node candidate
     * @return int Sort comparison result
     */
    private static function compareTreeNodes(
        mixed $firstNode,
        mixed $secondNode
    ): int {
        [$firstOrder, $firstTitle] = self::sortKey($firstNode);
        [$secondOrder, $secondTitle] = self::sortKey($secondNode);

        $byOrder = $firstOrder <=> $secondOrder;

        return $byOrder !== 0 ? $byOrder : strcmp($firstTitle, $secondTitle);
    }

    /**
     * A node's menu order and title, for sorting.
     *
     * @param  mixed  $node  The node candidate
     * @return array{int, string} The menu order, 0 when absent, and the title, '' when absent
     */
    private static function sortKey(
        mixed $node
    ): array {
        $nodeArray = \is_array($node) ? $node : [];

        return [
            (int) self::extractScalarString($nodeArray['menu_order'] ?? null),
            self::extractScalarString($nodeArray['title'] ?? null),
        ];
    }
}
