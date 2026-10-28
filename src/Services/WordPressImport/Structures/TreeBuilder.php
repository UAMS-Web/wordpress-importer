<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Structures;

use Illuminate\Support\Facades\File as IlluminateFileFacade;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\Collection;
use Statamic\Facades\Stache as StacheFacade;
use Statamic\Stache\Repositories\CollectionTreeRepository;
use Statamic\Structures\CollectionStructure;
use Statamic\Yaml\Yaml;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentRoot;
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

        $structure = $collection->structure();
        if (! $structure) {
            $output->writeln("<comment>No structure exists for '$collectionHandle'. Creating one.</comment>");
            $structure = (new CollectionStructure)->handle($collectionHandle);
            $collection->structure($structure);
            $collection->save();
        }

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

        // Step 5: Add unassigned pages to top-level tree
        $addedUnassigned = [];
        foreach ($unassigned as $data) {
            $entryId = self::extractScalarString($data['entry'] ?? null);

            if (
                ! \in_array(
                    $entryId,
                    $assignedEntries,
                    true
                )
            ) {
                $tree[] = $this->cleanNode($data);
                $addedUnassigned[] = $entryId;
            }
        }

        // Step 6: Verify all pages are included
        // A repeated entry collapses to one key in the map below, so the list is not deduplicated
        $allEntries = \array_merge(
            self::normalizeStringList(array_column(
                $pageData,
                'entry'
            )),
            $frontPage !== null ? [self::extractScalarString($frontPage['entry'] ?? null)] : []
        );
        $missingEntries = self::normalizeStringList(array_diff(
            $allEntries,
            self::normalizeStringList($assignedEntries),
            self::normalizeStringList($addedUnassigned),
            $frontPage !== null ? [self::extractScalarString($frontPage['entry'] ?? null)] : []
        ));
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

            if (
                $frontEntry !== ''
                && \is_array($children[$frontEntry] ?? null)
            ) {
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
     * @param  array<int|string, mixed>  $nodes  The array of nodes to process
     * @param  array<int|string, mixed>  $children  The array of children indexed by parent entry ID
     * @param  array<int|string, mixed>  &$assignedEntries  Reference to array tracking assigned entries
     * @return array<int|string, mixed> The processed nodes with children assigned
     */
    protected function assignChildrenRecursively(
        array $nodes,
        array $children,
        array &$assignedEntries
    ): array {
        foreach ($nodes as &$node) {
            if (! \is_array($node)) {
                continue;
            }

            $entry = self::extractScalarString($node['entry'] ?? null);

            if ($entry === '') {
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
                    $assignedEntries
                );
            }

            $node = $this->cleanNode($node);
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
            $contents = IlluminateFileFacade::get($filePath);

            if ($contents === '') {
                continue;
            }

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
        $entriesStore = StacheFacade::store('entries');
        $collectionStore = (
            \is_object($entriesStore)
            && \method_exists($entriesStore, 'store')
        ) ? $entriesStore->store($collectionHandle) : null;
        $pathsIndex = (
            \is_object($collectionStore)
            && \method_exists($collectionStore, 'paths')
        ) ? $collectionStore->paths() : null;

        // Process each group of files with the same ID
        foreach ($filesById as $entryId => $fileGroup) {
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
                        if ($fileInfo['path'] === $canonicalPath) {
                            $fileToKeep = $fileInfo;
                            break;
                        }
                    }
                }

                $fileToKeep ??= $fileGroup[0];
            } else {
                // For non-dated collections, keep the file matching paths index
                if (
                    \is_object($pathsIndex)
                    && \method_exists($pathsIndex, 'get')
                ) {
                    $canonicalPath = $pathsIndex->get($entryId);
                    foreach ($fileGroup as $fileInfo) {
                        if ($fileInfo['path'] === $canonicalPath) {
                            $fileToKeep = $fileInfo;
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
                try {
                    IlluminateFileFacade::delete($fileInfo['path']);
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
                } catch (\Exception $e) {
                    Log::error(
                        'TreeBuilder::cleanupDuplicateEntryFiles: Failed to delete duplicate file',
                        [
                            'collection' => $collectionHandle,
                            'site' => $site,
                            'entry_id' => $entryId,
                            'file' => $fileInfo['path'],
                            'error' => $e->getMessage(),
                        ]
                    );
                }
            }
        }
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
        if (\is_string($value)) {
            return $value;
        }

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
        if ($value === null) {
            return null;
        }

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
        $firstNodeArray = \is_array($firstNode) ? $firstNode : [];
        $secondNodeArray = \is_array($secondNode) ? $secondNode : [];

        $firstOrder = (int) self::extractScalarString($firstNodeArray['menu_order'] ?? 0);
        $secondOrder = (int) self::extractScalarString($secondNodeArray['menu_order'] ?? 0);

        if ($firstOrder === $secondOrder) {
            return strcmp(
                self::extractScalarString($firstNodeArray['title'] ?? ''),
                self::extractScalarString($secondNodeArray['title'] ?? '')
            );
        }

        return $firstOrder - $secondOrder;
    }
}
