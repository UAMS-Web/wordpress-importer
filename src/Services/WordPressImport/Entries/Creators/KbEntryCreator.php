<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\Creators;

use Carbon\Carbon;
use DateTime;
use Exception;
use Illuminate\Support\Facades\Log;
use SimpleXMLElement;
use Statamic\Entries\Collection as EntryCollection;
use Statamic\Entries\Entry;
use Statamic\Facades\Blink;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Site as SiteFacade;
use Statamic\Fieldtypes\RowId;
use Statamic\Sites\Site;
use Statamic\Structures\CollectionStructure;
use Statamic\Structures\Tree;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\DeferredProcessing\DeferredKnowledgeBaseMounts;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\DeferredProcessing\KbPageSubstitutions;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\EntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\KnowledgeBase\ArticleEngagementSeeder;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\ContentStreamFactory;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings\Selector as MinervaKbSettingsSelector;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Taxonomies\TermDestinationRegistry;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\CollectionStructureManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\EntryFieldUpdater;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\EntryFinder;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\FeaturedImageCreditResolver;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\FieldDefaultHelper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\FileProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\HtmlTagStripper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImageProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportProgress;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\KbLinkRegistry;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\RestrictionHelper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\SiteManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\TermMapBuilder;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\TextProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;

/**
 * Processes MinervaKB articles from WordPress XML export into Statamic
 * knowledge_bases collection.
 *
 * This processor handles the hierarchical structure of MinervaKB content:
 *   - Creates a top-level knowledge base entry for the site
 *   - Creates topic entries as children of the knowledge base
 *   - Creates article entries as children of topics
 *
 * The processor maps MinervaKB's taxonomy-based structure to Statamic's
 * hierarchical collection structure, where each article can only belong to
 * one topic (the first topic in the article's category list).
 *
 * The import process follows this order of operations:
 *   1. Create the tree for the knowledge_bases collection
 *   2. Create the knowledge_base entry, adding it to the tree at top-level
 *   3. Scan wp:term elements for kbtopic taxonomy terms
 *   4. Create topic entries for top-level topics (no parent)
 *   5. Create topic entries for child topics (with parent)
 *   6. Create article entries for each kb post
 *   7. Add article entries to the tree as children of their respective topics
 */
class KbEntryCreator extends BaseEntryCreator
{
    /**
     * The knowledge base entry ID for the current site.
     * Stores the ID of the top-level knowledge base entry that serves as the root
     * of the hierarchical structure for all topics and articles.
     */
    protected string $knowledgeBaseId = '';

    /**
     * Map of WordPress term IDs to Statamic entry IDs for topics.
     * Used to maintain the relationship between WordPress taxonomy terms and
     * their corresponding Statamic topic entries during the import process.
     *
     * @var array<string, string>
     */
    protected array $topicIdMap = [];

    /**
     * Map of WordPress post IDs to Statamic entry IDs for articles.
     * Used to maintain the relationship between WordPress posts and their
     * corresponding Statamic article entries during the import process.
     *
     * The key type is `int|string` because it is not a choice: the post id is
     * read as a string, and PHP casts a numeric string array key to an integer
     * on assignment, so an ordinary id such as `"13"` is stored under `13`.
     * Lookups cast the same way, which is why `isset($map[$wpPostId])` in
     * {@see self::processDeferredRelatedArticles()} matches despite the
     * mismatch — but the declared `array<string, string>` described a shape
     * this property never holds (wordpress-importer-archive#761).
     *
     * @var array<int|string, string>
     */
    protected array $articleIdMap = [];

    /**
     * Map of Statamic entry IDs to their related articles data for deferred
     * processing. Stores related article information that needs to be processed after
     * all articles are imported to ensure all entry IDs are available for
     * cross-referencing.
     *
     * @var array<string, array{wp_post_ids: list<string>, slug: ?string, date: ?Carbon}>
     */
    protected array $deferredRelatedArticles = [];

    /**
     * Tracks slug occurrences for uniqueness across topics and articles.
     * Prevents duplicate slugs by maintaining a count of how many times each
     * slug has been used, allowing for automatic slug suffixing when needed.
     *
     * @var array<string, int>
     */
    protected array $slugCounts = [];

    /**
     * Ordered parent/child relationships collected during the import.
     *
     * The knowledge_bases collection tree is assembled once at the end of the
     * import ({@see self::buildAndSaveKbTree()}) instead of being appended entry
     * by entry, because Statamic's CollectionStructure::validateTree() prunes any
     * tree entry not present in its (Blink-cached) collection entry list — and
     * that cache goes stale as the importer creates entries, silently dropping
     * topics so their articles' parents "do not exist in this structure".
     * Building once, from the now-complete entry set, also keeps the tree
     * consistent for the host app's ValidateKnowledgeBaseTree listener. See wordpress-importer-archive#464.
     *
     * @var list<array{entry: string, parent: string|null}>
     */
    protected array $pendingTreeNodes = [];

    /**
     * Collection handle for the deferred tree build, captured as nodes collect.
     */
    protected ?string $pendingTreeCollectionHandle = null;

    /**
     * Site handle for the deferred tree build, captured as nodes collect.
     */
    protected ?string $pendingTreeSite = null;

    /**
     * Whether sitewide content restrictions are enabled for the knowledge base.
     */
    protected bool $hasSitewideRestrictions = false;

    /**
     * Whether content restrictions are enabled for the knowledge base.
     */
    protected bool $hasRestrictions = false;

    /**
     * Create a new KbEntryCreator instance.
     * Initializes the entry creator with a reference to the parent EntryCreator
     * for accessing shared functionality and utilities.
     *
     * @param  EntryCreator  $entryCreator  The parent entry creator for shared functionality
     * @param  MinervaKbSettingsSelector  $minervaKbSettingsSelector  Service for selecting MinervaKB settings
     */
    public function __construct(protected EntryCreator $entryCreator, protected MinervaKbSettingsSelector $minervaKbSettingsSelector) {}

    /**
     * Reset the collected tree relationships.
     *
     * Called at the start of an import so the deferred tree build
     * ({@see self::buildAndSaveKbTree()}) starts from a clean slate.
     */
    protected function clearTreeCache(): void
    {
        $this->pendingTreeNodes = [];
        $this->pendingTreeCollectionHandle = null;
        $this->pendingTreeSite = null;
    }

    /**
     * Process MinervaKB content from the XML export.
     *
     * This method orchestrates the entire MinervaKB import process:
     *   1. Creates the top-level knowledge base entry
     *   2. Processes topics from wp:term elements
     *   3. Processes articles from wp:post elements
     *   4. Builds the hierarchical structure
     *
     * @param  XmlParser  $xmlParser  Service for parsing WordPress XML files
     * @param  AssetImporter  $assetImporter  Service for importing assets
     * @param  OutputInterface  $output  Console output interface
     * @param  string  $baseBlogUrl  Base URL of the WordPress blog
     * @param  string  $site  Selected site handle
     * @param  SiteManager  $siteManager  Service for managing site configurations
     * @param  CollectionStructureManager  $collectionStructureManager  Service for managing collection structures
     * @param  array<int|string, mixed>|null  $minervaKbSettings  Optional MinervaKB settings from JSON file
     */
    public function process(
        XmlParser $xmlParser,
        AssetImporter $assetImporter,
        OutputInterface $output,
        string $baseBlogUrl,
        string $site,
        SiteManager $siteManager,
        CollectionStructureManager $collectionStructureManager,
        ?array $minervaKbSettings = null
    ): void {
        // Define the collection handle for knowledge bases - this is the target collection
        $collectionHandle = 'knowledge_bases';

        // Clear any cached tree to ensure fresh lookups for this import session
        $this->clearTreeCache();

        // Display start message for MinervaKB processing to inform user of progress
        $output->writeln('<info>Processing MinervaKB content...</info>');

        // ---
        // Initialize terms with standardized processing while avoiding importing
        // 'kbtopic' as a Statamic taxonomy. We only import Statamic taxonomies
        // for items that should map to Statamic taxonomies (e.g., 'kbtag').
        // We still need 'kbtopic' available in the term maps to build topic
        // entries as Statamic entries under the knowledge base tree.
        // Include 'kbtag' only when tag import is enabled via config.
        // ---

        // Initialize taxonomy handles with FAQ category; kbtag added when tag import enabled
        $taxonomyHandles = ['mkb_faq_category'];

        // Check if tag import is enabled before including kbtag; an `if` reads the
        // setting the same with or without the cast
        // @pest-mutate-ignore: RemoveBooleanCast
        if (
            (bool) config(
                'wordpress-import.import_tags',
                true
            )
        ) {
            // Include kbtag in taxonomy handles when tag import is enabled
            $taxonomyHandles[] = 'kbtag';
        }

        // Import taxonomy terms at the beginning of the KB import process
        $this->importTaxonomyTermsAtBeginning(
            $xmlParser,
            $taxonomyHandles,
            $site,
            $output
        );

        // Get all terms from the XML once so we can build maps used by topics and tags
        $terms = $xmlParser->getTerms();

        // ---
        // Build a term map of the 'kbtopic' terms, which become topic entries;
        // 'kbtopic' is not imported as a Statamic taxonomy
        // ---
        $this->termMap = TermMapBuilder::buildTermMap(
            $terms,
            ['kbtopic']
        );

        // ---
        // Build a nicename-to-term ID map to resolve an article's topic and tag
        // slugs (nicenames) back to their term IDs
        // ---
        $this->nicenameToTermIdMap = TermMapBuilder::buildNicenameToTermIdMap(
            $terms,
            [
                'kbtopic',
                'kbtag',
            ]
        );

        // Free the local terms variable; maps are stored on the instance
        unset($terms);

        // Enable the knowledge_bases collection for the site
        $siteManager->enableCollectionForSite(
            $site,
            $collectionHandle,
            $output
        );

        // Initialize structure for the knowledge_bases collection
        $collectionStructureManager->initializeStructureForSite(
            $collectionHandle,
            $site,
            $output
        );

        // Create the tree structure for the knowledge_bases collection
        $this->createInitialTreeStructure(
            $output,
            $site,
            $collectionHandle
        );

        // ---
        // Resolve the MinervaKB home page (kb_page) so the knowledge base root
        // can adopt its slug and mount at its URL position. The kb_page is no
        // longer materialized as a separate `pages` entry; the knowledge base
        // root owns that URL instead (see wordpress-importer-archive#297).
        // ---
        $kbHome = $this->resolveKnowledgeBaseHomePageInfo(
            $xmlParser,
            $output,
            $minervaKbSettings
        );

        // Create the top-level knowledge base entry
        $this->createKnowledgeBaseEntry(
            $output,
            $site,
            $collectionHandle,
            $minervaKbSettings,
            $kbHome
        );

        // ---
        // Defer mounting the knowledge base beneath the kb_page's parent page.
        // The parent `pages` entry may not be imported yet (content types import
        // in user-selected order), so the mount is applied in the final deferred
        // pass once every collection has been imported.
        // ---
        if (
            $kbHome !== null
            && $kbHome['parent_wp_id'] !== ''
            && $kbHome['parent_wp_id'] !== '0'
        ) {
            DeferredKnowledgeBaseMounts::defer(
                $this->knowledgeBaseId,
                $kbHome['parent_wp_id'],
                $site
            );
        }

        // ---
        // Record what this root replaced, so navigation can follow it
        // ---

        // **Registered here because this is the only frame holding both halves (wordpress-importer-archive#1171).**
        // `PageEntryCreator` knows it skipped the kb_page and why, but not what took its place —
        // content types import in a user-selected order, so the root may not exist yet when the
        // page is skipped. A navigation item pointing at the kb_page was otherwise dropped
        // outright, because the resolver searches `pages` alone and the replacement is in
        // `knowledge_bases`.
        //
        // Registered even when the mount above is not deferred: a knowledge base with no parent
        // page still replaces its kb_page, and its navigation item is just as droppable.
        if ($kbHome !== null) {
            KbPageSubstitutions::register(
                $kbHome['wp_id'],
                $this->knowledgeBaseId,
                $site
            );
        }

        // Process topics from wp:term elements with kbtopic taxonomy
        $this->processTopics(
            $xmlParser,
            $output,
            $site,
            $collectionHandle,
            $minervaKbSettings
        );

        // Set MinervaKB settings in processing context for link processing
        ProcessingContext::setMinervaKbSettings($minervaKbSettings);

        // Process articles from wp:post elements with kb post type
        $this->processArticles(
            $xmlParser,
            $output,
            $site,
            $assetImporter,
            $collectionHandle
        );

        // Assemble and persist the collection tree now that every knowledge base,
        // topic, and article entry exists (see buildAndSaveKbTree() / wordpress-importer-archive#464).
        $this->buildAndSaveKbTree($output);

        // ---
        // Resolve deferred related articles only AFTER the tree is persisted. The
        // order is load-bearing rather than stylistic (wordpress-importer-archive#761).
        //
        // This pass re-saves an already-created article to add `related_articles`.
        // The host app's `ValidateKnowledgeBaseEntry` listener resolves an article's
        // parent one of two ways: for an entry still absent from the repository it
        // reads `parent` off the entry data, but for one that already exists it looks
        // the entry up in the `collection::knowledge_bases` structure tree. A re-save
        // always takes the second path, so with the tree unwritten `$tree->find()`
        // returns null, the listener throws `Article entries must have a Topic
        // parent`, and `EntryFieldUpdater::updateEntryFields()` reports a failed update.
        //
        // The resolution itself was never broken — it produced the correct entry ids
        // and the rejected save discarded them, which is why every link in the lookup
        // chain reads as sound.
        // ---
        $this->processDeferredRelatedArticles(
            $output,
            $site,
            $collectionHandle
        );

        // Display completion message
        $output->writeln('<info>MinervaKB import complete!</info>');

        // ---
        // Display deferred restriction messages. If sitewide restrictions are enabled,
        // inform the user to review and manually configure them on the top-level
        // knowledge base entry.
        // ---
        if ($this->hasSitewideRestrictions) {
            $output->writeln('<comment>Note: This knowledge base has sitewide content restrictions enabled. Review the MinervaKB settings on the source site to identify the sitewide content restrictions. Then, manually configure those same restrictions on the top-level knowledge base entry in Statamic to ensure consistent application across the site.</comment>');
        }

        // ---
        // If any topic or article-specific restrictions are enabled, inform the user to
        // review and manually configure them on the corresponding entries.
        // ---
        if ($this->hasRestrictions) {
            $output->writeln('<comment>Note: Examine the knowledge base topics and articles on the source site to identify any configured content restrictions. For any topics or articles with restrictions, manually apply the same restrictions to their corresponding entries imported into Statamic to maintain consistent access control.</comment>');
        }
    }

    /**
     * Create the initial tree structure for the knowledge_bases collection.
     *
     * This method ensures the collection has a structure defined and creates
     * an empty tree for the site. This is step 1 of the import process.
     *
     * @param  OutputInterface  $output  Console output interface
     * @param  string  $site  Selected site handle
     * @param  string  $collectionHandle  The collection handle (e.g., 'knowledge_bases')
     */
    protected function createInitialTreeStructure(
        OutputInterface $output,
        string $site,
        string $collectionHandle
    ): void {
        // Display progress message to inform user of current operation
        $output->writeln('Creating initial tree structure for knowledge_bases...');

        // Get the knowledge_bases collection from Statamic's collection registry
        $collection = CollectionFacade::findByHandle($collectionHandle); // Attempt to retrieve the collection object by its handle
        if ($collection === null) {
            // ---
            // If the collection does not exist, display an error message to the user in the
            // console
            // ---
            $output->writeln("<error>Collection '{$collectionHandle}' not found.</error>");

            // Log the error for debugging and monitoring purposes, including the site context
            Log::error("KbEntryCreator::createInitialTreeStructure: Collection '{$collectionHandle}' not found for site {$site}.");

            // Exit the method early since the collection is required for further processing
            return;
        }

        // Give the collection a structure if it has none - required for hierarchical content
        $structure = $collection->structure();
        if (! $structure instanceof CollectionStructure) {
            // Inform user that structure is being created
            $output->writeln("<comment>Collection '{$collectionHandle}' has no structure defined. Creating one.</comment>");

            // ---
            // Create new CollectionStructure instance and assign it to the collection,
            // which gives it the collection's handle
            // ---
            $structure = new CollectionStructure;
            $collection->structure($structure);

            // Save the collection with the new structure
            $collection->save();
        }

        // Get the tree for the specific site
        $collectionTree = $structure->in($site);

        if (! $collectionTree) {
            // Inform user that tree is being created for the site
            $output->writeln("<comment>No tree exists for '{$collectionHandle}' in site '$site'. Creating a new one.</comment>");

            // Create a new empty tree for the site
            $collectionTree = $structure->makeTree(
                $site,
                []
            );

            // Save the new tree to persist it; makeTree() always makes a Tree, so the
            // check only narrows the type
            // @pest-mutate-ignore: InstanceOfToTrue
            if ($collectionTree instanceof Tree) {
                $collectionTree->save();
            }
        }

        // Display success message confirming tree structure creation
        $output->writeln("<info>Initial tree structure created for '{$collectionHandle}' in site '$site'.</info>");
    }

    /**
     * Implements entry creation interface for WordPress knowledge base content.
     *
     * This method does not perform active processing or creation of entries. The
     * processing of kb content is managed by the process() method within this class.
     *
     * @param  string  $site  The site handle
     * @param  string  $collectionHandle  The collection handle (e.g., 'knowledge_bases')
     * @param  XmlParser  $xmlParser  Service for parsing WordPress XML files
     * @param  AssetImporter  $assetImporter  Service for importing assets
     * @param  OutputInterface  $output  Console output interface
     * @param  MetadataMapper  $metadataMapper  Service for mapping metadata
     * @param  ContentProcessor  $contentProcessor  Service for processing content
     * @param  array<int|string, mixed>|null  $minervaKbSettings  Optional MinervaKB settings from JSON file
     * @return array<int|string, mixed> Empty array as page data is managed by this class
     */
    public function createEntries(
        string $site,
        string $collectionHandle,
        XmlParser $xmlParser,
        AssetImporter $assetImporter,
        OutputInterface $output,
        MetadataMapper $metadataMapper,
        ContentProcessor $contentProcessor,
        ?array $minervaKbSettings = null
    ): array {
        return [];
    }

    /**
     * Check if the content has 'show-people' shortcodes.
     *
     * @return bool Always false for knowledge base content
     */
    public function hasShowPeople(): bool
    {
        return false;
    }

    /**
     * Get post categories for validation.
     *
     * @return array<int|string, mixed> Empty array as knowledge base content doesn't use post categories
     */
    public function getPostCategories(): array
    {
        return [];
    }

    /**
     * Get post tags for validation.
     *
     * @return array<int|string, mixed> Empty array as knowledge base content doesn't use post tags
     */
    public function getPostTags(): array
    {
        return [];
    }

    /**
     * Get people groups for validation.
     *
     * @return array<int|string, mixed> Empty array as knowledge base content doesn't use people groups
     */
    public function getPeopleGroups(): array
    {
        return [];
    }

    /**
     * Add an entry to the collection tree.
     *
     * This helper method adds an entry to the collection tree structure.
     * It optimizes performance by caching the tree instance to avoid
     * repeated lookups for the same collection and site.
     *
     * @param  string  $entryId  The ID of the entry to add to the tree
     * @param  string  $collectionHandle  The collection handle
     * @param  string  $site  The site handle
     * @param  ?string  $parentId  The parent entry ID (null for top-level entries)
     */
    protected function addEntryToTree(
        string $entryId,
        string $collectionHandle,
        string $site,
        ?string $parentId = null
    ): void {
        // Record the parent/child relationship only. The tree is assembled and
        // saved once, at the end of the import, by buildAndSaveKbTree() — see the
        // $pendingTreeNodes docblock and wordpress-importer-archive#464 for why the build is deferred.
        $this->pendingTreeCollectionHandle = $collectionHandle;
        $this->pendingTreeSite = $site;
        $this->pendingTreeNodes[] = [
            'entry' => $entryId,
            'parent' => $parentId,
        ];
    }

    /**
     * Assemble and persist the knowledge_bases collection tree from the
     * parent/child relationships collected during the import.
     *
     * Builds the full nested tree (parent-first) once, from the now-complete set
     * of created entries, and saves it a single time. Deferring the build to the
     * end — instead of appending each entry as it is created — avoids Statamic's
     * CollectionStructure::validateTree() pruning entries whose creation
     * post-dated its Blink-cached collection entry list, and keeps the tree
     * consistent for the host app's ValidateKnowledgeBaseTree listener. See wordpress-importer-archive#464.
     *
     * @param  OutputInterface  $output  Console output for progress feedback
     */
    protected function buildAndSaveKbTree(OutputInterface $output): void
    {
        if (
            $this->pendingTreeNodes === []
            || $this->pendingTreeCollectionHandle === null
            || $this->pendingTreeSite === null
        ) {
            return;
        }

        $collectionHandle = $this->pendingTreeCollectionHandle;
        $site = $this->pendingTreeSite;

        // Group child entries by parent id (top-level entries key under ''), and
        // detect "external" parents — ids referenced as a parent but never
        // recorded here, chiefly the knowledge base root, which may already exist
        // and so is not re-added to the tree during this import. Anchor those at
        // the top level so their descendants nest beneath them.
        $recorded = \array_flip(\array_column(
            $this->pendingTreeNodes,
            'entry'
        ));

        $childrenByParent = [];
        $externalRoots = [];
        foreach ($this->pendingTreeNodes as $node) {
            $parentKey = $node['parent'] ?? '';
            if (
                $parentKey !== ''
                && ! isset($recorded[$parentKey])
                && ! isset($externalRoots[$parentKey])
            ) {
                $externalRoots[$parentKey] = $parentKey;
            }

            $childrenByParent[$parentKey][] = $node['entry'];
        }

        foreach ($externalRoots as $rootId) {
            $childrenByParent[''][] = $rootId;
        }

        // Assemble the nested [['entry' => id, 'children' => [...]]] structure,
        // parent-first, guarding against cycles and duplicate references.
        $visited = [];
        $nestedTree = $this->assembleTreeBranches(
            '',
            $childrenByParent,
            $visited
        );

        // CollectionStructure::validateTree() prunes the tree against a
        // request-scoped (Blink) cache of the collection's entries that predates
        // the entries just created; flush Blink so the save — and the host
        // ValidateKnowledgeBaseTree listener that fires on it — validate against
        // the complete, current entry set. This runs once per KB import, immediately
        // before the tree save, so the cost is negligible. See wordpress-importer-archive#464. (It is no longer
        // the import's last write: the deferred related-articles pass re-saves entries
        // after it, which is deliberate and load-bearing — see createEntries() / wordpress-importer-archive#761.)
        //
        // Tree::save() forgets the tree-entries cache itself, so only the host's listener,
        // which this package's tests do not run, can read the cache this flush clears.
        // @pest-mutate-ignore: RemoveMethodCall
        Blink::flush();

        $structure = CollectionFacade::findByHandle($collectionHandle)?->structure();
        if (! $structure instanceof CollectionStructure) {
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'collection_handle' => $collectionHandle,
                    'site' => $site,
                ]
            );
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    "KbEntryCreator::buildAndSaveKbTree: Collection '{$collectionHandle}' not found or has no structure for site {$site}.",
                    $logData['page_url']
                ),
                $logData['context']
            );

            return;
        }

        // A site the collection has no tree for has nothing to save into
        $tree = $structure->in($site);
        if (! $tree instanceof Tree) {
            return;
        }

        $tree->tree($nestedTree);
        $tree->save();

        $output->writeln('<info>Knowledge base tree structure saved.</info>');
    }

    /**
     * Recursively assemble tree branches under a parent id, parent-first.
     *
     * @param  string  $parentKey  Parent entry id, or '' for top-level entries
     * @param  array<string, list<string>>  $childrenByParent  Child entry ids keyed by parent id
     * @param  array<string, string>  $visited  Entry ids already placed, keyed by id (guards cycles/duplicates)
     * @return list<array<string, mixed>> The nested branch structure
     */
    private function assembleTreeBranches(
        string $parentKey,
        array $childrenByParent,
        array &$visited
    ): array {
        $branches = [];

        foreach ($childrenByParent[$parentKey] ?? [] as $entryId) {
            if (isset($visited[$entryId])) {
                continue;
            }

            $visited[$entryId] = $entryId;

            $branch = ['entry' => $entryId];
            $children = $this->assembleTreeBranches(
                $entryId,
                $childrenByParent,
                $visited
            );
            if ($children !== []) {
                $branch['children'] = $children;
            }

            $branches[] = $branch;
        }

        return $branches;
    }

    /**
     * Create the top-level knowledge base entry for the site.
     *
     * This method creates a single knowledge base entry that serves as the
     * root of the hierarchical structure. The entry uses the site name to
     * create a descriptive title and includes default configuration for
     * search and topic display functionality. Statamic will automatically
     * add it to the tree at the top level since it has no parent.
     *
     * @param  OutputInterface  $output  Console output interface
     * @param  string  $site  Selected site handle
     * @param  string  $collectionHandle  The collection handle (e.g., 'knowledge_bases')
     * @param  array<int|string, mixed>|null  $minervaKbSettings  Optional MinervaKB settings from JSON file
     * @param  array{wp_id: string, slug: string, title: string, parent_wp_id: string}|null  $kbHome  Optional knowledge base home page info
     */
    protected function createKnowledgeBaseEntry(
        OutputInterface $output,
        string $site,
        string $collectionHandle,
        ?array $minervaKbSettings = null,
        ?array $kbHome = null
    ): void {
        // Display progress message to inform user of current operation
        $output->writeln('Creating knowledge base entry...');

        // Get site name using Site facade for descriptive title creation
        // ---
        // Statamic cannot save an entry in a site it does not know, so an import into
        // one fails at the save whether the name falls back or not; the check only
        // narrows the type
        // ---
        $siteInstance = SiteFacade::get($site);
        // @pest-mutate-ignore: InstanceOfToTrue
        $siteName = self::mixedToString(
            $siteInstance instanceof Site ? $siteInstance->name() : null,
            'Unknown Site'
        );

        // Create knowledge base title using site name for better identification
        $knowledgeBaseTitle = "{$siteName} Knowledge Base";

        // ---
        // When a MinervaKB home page (kb_page) is configured, the root adopts its
        // slug and embeds its WordPress ID so the kb_page's URL is preserved and
        // links to it resolve to the knowledge base root. Otherwise fall back to
        // the standard 'kb' slug with a generated ID.
        // ---
        $slug = 'kb';
        $entryId = null;

        if ($kbHome !== null) {
            [$slug, $entryId] = $this->generateSlugAndId(
                $kbHome['slug'],
                $kbHome['wp_id'],
                $kbHome['title']
            );
        }

        // ---
        // Check if knowledge base entry already exists to prevent duplicates. A fresh
        // lookup finds the same entry a cached one does here.
        // ---
        // @pest-mutate-ignore: FalseToTrue
        $existingEntry = EntryFinder::findEntryBySlug(
            $collectionHandle,
            $slug,
            false,
            $site
        );

        if ($existingEntry instanceof Entry) {
            // ---
            // Store the existing entry's ID for use as the root of the knowledge base
            // hierarchy. A saved entry always has a string ID, so the fallback is never read.
            // ---
            // @pest-mutate-ignore: EmptyStringToNotEmpty
            $this->knowledgeBaseId = self::mixedToString(
                $existingEntry->id(),
                ''
            );

            // ---
            // Exit the method early since the knowledge base entry already exists and does
            // not need to be recreated
            // ---
            return;
        }

        // Create knowledge base entry data with default configuration
        $entryData = [
            'blueprint' => 'knowledge_base', // Specify the blueprint for this entry type
            'title' => $knowledgeBaseTitle, // Set the descriptive title
            'knowledge_base_search_query' => true, // Enable search functionality
            'topic_label' => 'Topics', // Label for topics section
            'topic_limit' => -1, // Show all topics (-1 means unlimited)
            'show_all_topics_label' => 'Show All', // Label for "show all" link
            'topic_description_query' => true, // Enable topic descriptions
            'topic_image_query' => true, // Enable topic images
            'restriction_enabled' => false, // Disable content restrictions by default
            'topic_search_query' => false, // Disable search on topic pages by default
            'subtopic_label' => 'Subtopics', // Label for subtopics section
            'subtopic_limit' => -1, // Show all subtopics (-1 means unlimited)
            'subtopic_description_query' => true, // Enable subtopic descriptions
            'subtopic_image_query' => true, // Enable subtopic images
            'show_all_subtopics_label' => 'Show All', // Label for "show all subtopics" link
            'topic_article_label' => 'Articles', // Label for articles section
            'topic_article_count' => -1, // Show all articles (-1 means unlimited)
            'topic_article_show_all_label' => 'Show All', // Label for "show all articles" link
            'topic_article_last_modified_query' => false, // Disable last modified date on topic articles by default
            'topic_article_description_query' => true, // Enable article descriptions
            'topic_article_image_query' => true, // Enable article images
            'article_last_modified_query' => true, // Enable last modified date on articles
            'article_creator_query' => false, // Disable article author by default
            'article_estimated_reading_time_query' => false, // Disable reading time by default
            'toc_query' => false, // Disable table of contents by default
            'attachments_label' => 'Attachments', // Label for attachments section
            'related_articles_query' => true, // Enable related articles by default
            'related_articles_label' => 'Related Articles', // Label for related articles section
            'hide_from_menu' => false, // Show in menu by default
        ];

        // Override with MinervaKB settings if available
        if (
            $minervaKbSettings !== null
        ) {
            // ---
            // Extract knowledge base settings specific to the current site from the MinervaKB
            // settings
            // ---
            $kbSettings = $this->minervaKbSettingsSelector->extractKnowledgeBaseSettings(
                $minervaKbSettings,
                $siteName
            );

            // Loop through each field and value in the extracted settings
            foreach ($kbSettings as $field => $value) {
                // If the value is not null, override the corresponding field in the entry data
                if ($value !== null) {
                    $entryData[$field] = $value;
                }
            }
        }

        // Set default search values only when search is enabled
        if ($entryData['knowledge_base_search_query'] === true) {
            $entryData['knowledge_base_search_title'] ??= 'Search the Knowledge Base';

            $entryData['knowledge_base_search_placeholder'] ??= 'e.g., Guidelines';
        }

        // Fetch the default value for the template field from the knowledge_base blueprint
        // Get the blueprint name for the current entry
        $blueprint = 'knowledge_base';

        // Retrieve the default template value for this blueprint and collection
        $templateDefault = FieldDefaultHelper::getDefaultValueForBlueprintField(
            "collections.{$collectionHandle}.{$blueprint}",
            'template'
        );

        // Assign the default template value to the entry data array
        $entryData['template'] = $templateDefault;

        // ---
        // Fetch the default value for the title_options field from the knowledge_base
        // blueprint
        // ---
        $titleOptionsDefault = FieldDefaultHelper::getDefaultValueForBlueprintField(
            "collections.{$collectionHandle}.{$blueprint}",
            'title_options'
        );

        // Check if title options default value is available from the blueprint
        if ($titleOptionsDefault !== null) {
            // Assign the default title options value to the entry data
            $entryData['title_options'] = $titleOptionsDefault;

            // ---
            // Check if the title options value is set to 'graphic' mode, which requires
            // additional header graphic fields
            // ---
            if ($titleOptionsDefault === 'graphic') {
                // ---
                // Initialize the header_graphic field with empty body and image values for
                // graphic title mode
                // ---
                $entryData['header_graphic'] = [
                    'body' => null,
                    'image' => null,
                ];
            }
        }

        // Create current date for the knowledge base entry
        $currentDate = new DateTime;

        // Create the entry using Statamic's Entry facade; the facade always makes an
        // Entry, so the assertion only narrows the type
        $entry = EntryFacade::make();
        // @pest-mutate-ignore: InstanceOfToTrue, RemoveFunctionCall
        \assert($entry instanceof Entry);
        $entry->collection($collectionHandle);
        $entry->locale($site);
        // A new entry is published already; this states it
        // @pest-mutate-ignore: RemoveMethodCall
        $entry->published(true);
        $entry->slug($slug);

        // Use the kb_page-derived ID (with embedded WordPress post ID) when set
        // so links to the kb_page resolve to the root and re-imports are idempotent
        if ($entryId !== null) {
            $entry->id($entryId);
        }

        // Only set date if the collection is dated (supports date-based entries)
        // ---
        // Statamic cannot make an entry for a collection that does not exist, so the
        // collection is always found and its checks only narrow the type. The cast
        // keeps a truthy dated value, such as 1 read from YAML, reading as dated.
        // ---
        $entryCollection = $entry->collection();
        // @pest-mutate-ignore: InstanceOfToTrue, RemoveBooleanCast
        if ($entryCollection instanceof EntryCollection && (bool) $entryCollection->dated()) {
            $entry->date(Carbon::instance($currentDate));
        } else {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'collection_handle' => $entry->collectionHandle(),
                    // @pest-mutate-ignore: InstanceOfToTrue
                    'collection_dated' => $entryCollection instanceof EntryCollection ? $entryCollection->dated() : 'unknown',
                ]
            );

            // Log warning if collection doesn't support dates
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'KbEntryCreator::createKnowledgeBaseEntry: Skipping date setting - collection not dated',
                    $logData['page_url']
                ),
                $logData['context']
            );
        }

        // Set the entry data and save to database
        $entry->data($entryData);
        $entry->save();

        // ---
        // Get the generated ID from the saved entry for future reference. A saved
        // entry always has a string ID, so the fallback is never read.
        // ---
        // @pest-mutate-ignore: EmptyStringToNotEmpty
        $this->knowledgeBaseId = self::mixedToString(
            $entry->id(),
            ''
        );

        // Add the entry to the tree as a top-level entry (no parent)
        $this->addEntryToTree(
            $this->knowledgeBaseId,
            $collectionHandle,
            $site
        );

        // Display success message with entry details
        $output->writeln("<info>Created knowledge base entry '$slug' (ID: {$this->knowledgeBaseId}) for site '$site'.</info>");

        // Store restriction information for deferred display
        // Determine if any content restrictions are enabled for this knowledge base entry
        $this->hasRestrictions = ($entryData['restriction_enabled']) === true;

        // ---
        // Determine if sitewide restrictions are enabled (must have restrictions and
        // global restriction flag set). The settings extractor sets the flag whenever
        // it enables restrictions, so its fallback is never read.
        // ---
        // @pest-mutate-ignore: FalseToTrue
        $this->hasSitewideRestrictions = $this->hasRestrictions && ($entryData['restriction_global_query'] ?? false) === true;
    }

    /**
     * Process topics from wp:term elements in the XML.
     *
     * This method extracts all kbtopic terms from the WordPress XML export,
     * builds a hierarchical structure based on parent-child relationships,
     * and creates topic entries in Statamic. Statamic will automatically
     * handle tree updates when entries are saved with parent fields set.
     *
     * @param  XmlParser  $xmlParser  Service for parsing WordPress XML files
     * @param  OutputInterface  $output  Console output interface
     * @param  string  $site  Selected site handle
     * @param  string  $collectionHandle  The collection handle (e.g., 'knowledge_bases')
     * @param  array<int|string, mixed>|null  $minervaKbSettings  Optional MinervaKB settings from JSON file
     */
    protected function processTopics(
        XmlParser $xmlParser,
        OutputInterface $output,
        string $site,
        string $collectionHandle,
        ?array $minervaKbSettings = null
    ): void {
        // Display progress message to inform user of current operation
        $output->writeln('Processing topics...');

        // ---
        // The term map holds only kbtopic terms (see process()), each an array from
        // TermMapBuilder; the filter only narrows the type
        // ---
        $kbtopicTermMap = \array_filter(
            $this->termMap,
            \is_array(...)
        );

        // Check if any kbtopic terms were found in the XML
        if ($kbtopicTermMap === []) {
            // If no kbtopic terms were found, inform the user via console output
            $output->writeln('No kbtopic terms found in XML.');

            // Exit the method early since there are no topics to process
            return;
        }

        // ---
        // Display the number of kbtopic terms found to the user in the console for
        // immediate feedback
        // ---
        $output->writeln('Found '.\count($kbtopicTermMap).' kbtopic terms.');

        // Step 2: Recursively create topics in parent-before-child order
        // This ensures parent topics exist before creating child topics
        $created = []; // Track which topics have already been created to avoid duplicates
        $inProgress = []; // Track the topics waiting on their parent, so a parent cycle ends

        // ---
        // Define a recursive closure to create topics in parent-before-child order,
        // ensuring the hierarchy is built correctly
        // ---
        $createTopicRecursively = function (int|string $termId, string $parentId) use (
            &$createTopicRecursively,
            &$created,
            &$inProgress,
            $kbtopicTermMap,
            $output,
            $site,
            $collectionHandle,
            $xmlParser,
            $minervaKbSettings
        ): string {
            // If topic already created, return its ID to avoid duplicates
            if (isset($created[$termId])) {
                return $created[$termId];
            }

            // Get the current term data from the map using its ID
            $termData = $kbtopicTermMap[$termId];

            // ---
            // Get the parent term ID from the term data (already resolved by TermMapBuilder).
            // No term has the fallback's ID either way.
            // ---
            // @pest-mutate-ignore: EmptyStringToNotEmpty
            $parentTermId = self::mixedToString(
                $termData['parent_id'] ?? null,
                ''
            );

            // Mark this topic as waiting on its parent
            $inProgress[$termId] = true;

            // A parent still waiting on this topic can never be created first (#281)
            $inCycle = isset($inProgress[$parentTermId]);

            if ($inCycle) {
                $this->reportTopicParentCycle(
                    (string) $termId,
                    $parentTermId,
                    $site
                );
            }

            // ---
            // Create a parent topic first and file this topic under it. A term whose
            // parent is not a topic, or is in a cycle with it, is filed under the
            // knowledge base instead.
            // ---
            $parentEntryId = ! $inCycle && isset($kbtopicTermMap[$parentTermId])
                ? $createTopicRecursively(
                    $parentTermId,
                    $parentId
                )
                : $parentId;

            // A later topic may name this one as an ordinary parent
            unset($inProgress[$termId]);

            // Create this topic, and store its entry ID to avoid duplicates
            $created[$termId] = $this->createTopicEntryFromData(
                $termData,
                $parentEntryId,
                $output,
                $site,
                $collectionHandle,
                $xmlParser,
                $minervaKbSettings
            );

            // ---
            // Return the entry ID of the topic just created, so it can be used as a parent
            // for any child topics
            // ---
            return $created[$termId];
        };

        // Process each topic term, starting the recursive creation process
        foreach (ImportProgress::track($output, 'kb topics', $kbtopicTermMap) as $termId => $termData) {
            // Start recursive creation with knowledge base as the parent
            $createTopicRecursively(
                $termId,
                $this->knowledgeBaseId
            );
        }

        // ---
        // Statamic will automatically update the tree when entries are saved with parent
        // fields set.
        // ---

        // Display completion message with counts for user feedback
        $output->writeln('Processed '.\count($kbtopicTermMap).' kbtopic terms, created '.\count($this->topicIdMap).' topic entries.');
    }

    /**
     * Report a topic that is filed under the knowledge base because of a parent cycle.
     *
     * @param  string  $termId  The WordPress term ID of the topic
     * @param  string  $parentTermId  The WordPress term ID of the parent it names
     * @param  string  $site  Selected site handle
     */
    private function reportTopicParentCycle(
        string $termId,
        string $parentTermId,
        string $site
    ): void {
        // Log the cycle for debugging
        Log::warning(
            "KbEntryCreator::processTopics: kbtopic term {$termId} names parent term {$parentTermId} in a parent cycle, so it is filed under the knowledge base",
            [
                'term_id' => $termId,
                'parent_term_id' => $parentTermId,
                'site' => $site,
            ]
        );

        // Report it in the run's summary
        ImportWarningManager::addWarning(
            'content',
            'kb_topic_parent_cycles',
            [
                'term_id' => $termId,
                'parent_term_id' => $parentTermId,
                'site' => $site,
            ]
        );
    }

    /**
     * Create a single topic entry from term data.
     *
     * This method creates a Statamic entry for a topic from term data
     * provided by TermMapBuilder. It extracts the topic name, slug, and
     * description, generates a unique entry ID, and saves the entry with
     * appropriate metadata.
     *
     * @param  array<int|string, mixed>  $termData  The term data from TermMapBuilder
     * @param  string  $parentId  Parent entry ID
     * @param  OutputInterface  $output  Console output interface
     * @param  string  $site  Selected site handle
     * @param  string  $collectionHandle  The collection handle (e.g., 'knowledge_bases')
     * @param  ?XmlParser  $xmlParser  Optional XML parser for extracting term descriptions
     * @param  array<int|string, mixed>|null  $minervaKbSettings  Optional MinervaKB settings from JSON file
     * @return string The created topic entry ID
     */
    protected function createTopicEntryFromData(
        array $termData,
        string $parentId,
        OutputInterface $output,
        string $site,
        string $collectionHandle,
        ?XmlParser $xmlParser = null,
        ?array $minervaKbSettings = null
    ): string {
        // Extract the unique WordPress term ID from the term data
        $termId = self::mixedToString(
            $termData['term_id'] ?? null,
            ''
        );
        if ($termId === '') {
            return '';
        }

        // Extract the URL-friendly slug from the term data
        $termSlug = self::mixedToString(
            $termData['term_slug'] ?? null,
            ''
        );
        if ($termSlug === '') {
            return '';
        }

        // Extract and strip HTML tags from the display name
        $termName = HtmlTagStripper::strip(
            self::mixedToString(
                $termData['term_name'] ?? null,
                ''
            )
        );

        // ---
        // Extract description from term XML element (wp:term_description or termmeta)
        // ---

        // Initialize description as empty; will be set if found in XML
        $description = '';

        // Check if XML parser is available
        if ($xmlParser instanceof XmlParser) {
            // Get the term element from XML by term ID
            $termElement = $this->findTermElementById(
                $xmlParser,
                $termId
            );

            // Check if term element was found
            if ($termElement instanceof SimpleXMLElement) {
                // Extract description from the term element (wp:term_description or termmeta)
                $description = $this->extractTermDescription($termElement);
            }
        }

        // ---
        // Strip HTML tags from description and truncate to excerpt field limit if it exceeds
        // 160 characters
        // ---
        $description = $this->truncateToExcerptLimit(HtmlTagStripper::strip($description));

        // ---
        // Generate slug and ID using BaseEntryCreator for consistency with other entry
        // types
        // ---
        [$entrySlug, $entryId] = $this->generateSlugAndId(
            $termSlug,
            $termId,
            $termName
        );

        // Create topic entry data with required fields
        $entryData = [
            'blueprint' => 'topic', // Specify the blueprint for topic entries
            'title' => $termName, // Set the topic title from WordPress term name
            'excerpt' => $description, // Set the excerpt from the description
            'parent' => $parentId, // Set the parent relationship for hierarchical structure
        ];

        // Get the blueprint name for the topic entry
        $blueprint = 'topic';

        // Get the default template
        $templateDefault = FieldDefaultHelper::getDefaultValueForBlueprintField(
            "collections.{$collectionHandle}.{$blueprint}",
            'template'
        );

        // Assign the template to the entry data
        $entryData['template'] = $templateDefault;

        // Fetch the default value for the title_options field from the topic blueprint
        $titleOptionsDefault = FieldDefaultHelper::getDefaultValueForBlueprintField(
            "collections.{$collectionHandle}.{$blueprint}",
            'title_options'
        );

        // Check if title options default value is available from the blueprint
        if ($titleOptionsDefault !== null) {
            // Assign the default title options value to the entry data
            $entryData['title_options'] = $titleOptionsDefault;

            // ---
            // Check if the title options value is set to 'graphic' mode, which requires
            // additional header graphic fields
            // ---
            if ($titleOptionsDefault === 'graphic') {
                // ---
                // Initialize the header_graphic field with empty body and image values for
                // graphic title mode
                // ---
                $entryData['header_graphic'] = [
                    'body' => null,
                    'image' => null,
                ];
            }
        }

        // Set the custom entry ID for consistency
        $entryData['id'] = $entryId;

        // Create current date for the topic entry
        $currentDate = new DateTime;

        // ---
        // Save topic entry using BaseEntryCreator's saveEntry method for consistent
        // handling
        // ---
        $savedTopic = $this->saveEntry(
            $collectionHandle,
            $site,
            $entrySlug,
            $entryData,
            $output,
            $currentDate,
            true
        );

        // ---
        // Adopt the id the entry was SAVED under, before anything downstream reads
        // `$entryId`. Overwriting a matched entry preserves its existing id (wordpress-importer-archive#767),
        // so the lookup below, `addEntryToTree()`, `topicIdMap` and the two
        // `KbLinkRegistry` registrations would otherwise all name an entry that was
        // just deleted — and `LinkProcessor::convertKbLink()` reads that registry to
        // emit `statamic://entry::<id>`, so the dangling reference wordpress-importer-archive#767 exists to
        // remove would simply reappear for knowledge-base content.
        //
        // Reassigning `$entryId` rather than introducing a second variable is
        // deliberate: every downstream use in this method is below this point, so
        // there is no path left that can still read the generated id.
        //
        // A topic that was not saved keeps the generated id and stays out of the tree.
        // ---
        if ($savedTopic instanceof Entry) {
            $entryId = self::mixedToString(
                $savedTopic->id(),
                $entryId
            );

            // Add the entry to the tree as a child of the specified parent
            $this->addEntryToTree(
                $entryId,
                $collectionHandle,
                $site,
                $parentId
            );
        }

        // ---
        // Store the mapping from WordPress term ID to Statamic entry ID for future
        // reference
        // ---
        $this->topicIdMap[$termId] = $entryId;

        // Register the kbtopic slug mapping for link processing
        KbLinkRegistry::registerKbtopicMappingBySlug(
            $termSlug,
            $entryId
        );

        // Register the kbtopic term ID mapping for block processing
        KbLinkRegistry::registerKbtopicMappingByTermId(
            $termId,
            $entryId
        );

        // Clean up variables to free up memory
        unset(
            $termId,
            $termSlug,
            $termName,
            $description,
            $entrySlug,
            $entryData,
            $blueprint,
            $templateDefault,
            $titleOptionsDefault,
            $currentDate
        );

        // Return the created entry ID for use in parent-child relationships
        return $entryId;
    }

    /**
     * Process articles from wp:post elements in the XML.
     *
     * This method extracts all kb post type elements from the WordPress
     * XML export and creates Statamic entries for each article. It
     * associates articles with their respective topics and updates the
     * tree structure.
     *
     * @param  XmlParser  $xmlParser  Service for parsing WordPress XML files
     * @param  OutputInterface  $output  Console output interface
     * @param  string  $site  Selected site handle
     * @param  AssetImporter  $assetImporter  Service for importing assets
     * @param  string  $collectionHandle  The collection handle (e.g., 'knowledge_bases')
     */
    protected function processArticles(
        XmlParser $xmlParser,
        OutputInterface $output,
        string $site,
        AssetImporter $assetImporter,
        string $collectionHandle
    ): void {
        // Display progress message to inform user of current operation
        $output->writeln('Processing articles...');

        // Get all kb items from the XML export for processing
        $items = $xmlParser->getItems('kb'); // Retrieve all items of type 'kb' from the XML export
        $articleCount = 0; // Initialize a counter to track the number of articles created

        // Process each kb item to create article entries
        foreach (ImportProgress::track($output, 'kb', $items) as $item) {
            // Verify this is actually a kb post type to avoid processing other content types
            $postType = XpathHelper::getStringValue(
                $item,
                'wp:post_type'
            );

            // ---
            // Only process items where the post type is 'kb', as other types are not relevant
            // for knowledge base articles
            // ---
            if ($postType !== 'kb') {
                continue;
            }

            // Create article entry for this item
            $this->createArticleEntry(
                $item,
                $output,
                $site,
                $xmlParser,
                $assetImporter,
                $collectionHandle
            );

            // Increment counter for user feedback
            $articleCount++;
        }

        // Display completion message with count for user feedback
        $output->writeln("Created {$articleCount} article entries.");
    }

    /**
     * Create a single article entry.
     *
     * This method creates a Statamic entry for an article from a wp:post
     * element. It extracts the article title, content, excerpt, and
     * metadata, finds the associated topic, and saves the entry with
     * appropriate parent relationship.
     *
     * @param  SimpleXMLElement  $item  The wp:post element
     * @param  OutputInterface  $output  Console output interface
     * @param  string  $site  Selected site handle
     * @param  XmlParser  $xmlParser  Service for parsing WordPress XML files
     * @param  AssetImporter  $assetImporter  Service for importing assets
     * @param  string  $collectionHandle  The collection handle (e.g., 'knowledge_bases')
     */
    protected function createArticleEntry(
        SimpleXMLElement $item,
        OutputInterface $output,
        string $site,
        XmlParser $xmlParser,
        AssetImporter $assetImporter,
        string $collectionHandle
    ): void {
        // Extract the WordPress post ID from the XML item
        $postId = XpathHelper::getStringValue(
            $item,
            'wp:post_id'
        );

        // Extract the WordPress post name (slug) from the XML item
        $postName = XpathHelper::getStringValue(
            $item,
            'wp:post_name'
        );

        // Decode HTML entities and strip HTML tags from the title, provide fallback
        $title = HtmlTagStripper::strip(
            html_entity_decode(
                (string) $item->title,
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            )
        );
        $title = $title !== '' ? $title : 'Untitled';

        // Extract the encoded content from the WordPress XML item
        $content = XpathHelper::getStringValue(
            $item,
            'content:encoded'
        );

        // Extract post date with fallback to pubDate
        $postDate = $this->extractPostDateString($item);

        // Extract the post status with default fallback to 'publish'
        $postStatus = XpathHelper::getStringValue(
            $item,
            'wp:status',
            'publish'
        );

        // Check if this is a published kb post with empty content
        if (
            $postStatus === 'publish'
            && $content === ''
        ) {
            // Set placeholder content for published kb posts with empty content
            $content = '<p>Placeholder Article Content — Please Replace</p>';
        }

        // Get the original WordPress permalink URL
        $originalWordPressUrl = (string) $item->link;

        // Set the WordPress page link in processing context for accurate logging
        ProcessingContext::setWordPressPageLink($originalWordPressUrl);

        // Find the first topic for this article to establish parent-child relationship
        $topicId = $this->findArticleTopic($item);

        // Check if a topic was found for this article
        if ($topicId === null) {
            // Skip articles without topics as they need a parent in the hierarchical structure
            return;
        }

        // Initialize collection for secondary topic entry IDs (additional kbtopic terms)
        $secondaryTopicIds = [];

        // Retrieve all kbtopic category elements on this item
        $kbtopicCategories = $item->xpath('category[@domain="kbtopic"]');

        // Process each category; the first, the primary topic, is left out below
        foreach (\is_array($kbtopicCategories) ? $kbtopicCategories : [] as $category) {
            // Resolve the topic entry this category names
            $topicEntryId = $this->findTopicEntryId((string) $category['nicename']);

            // ---
            // Append only valid, non-primary topic IDs, once each. The IDs are
            // never numeric, so a loose search would find the same.
            // ---
            // @pest-mutate-ignore: TrueToFalse
            if (
                $topicEntryId !== null
                && $topicEntryId !== $topicId
                && ! \in_array($topicEntryId, $secondaryTopicIds, true)
            ) {
                // Store this secondary topic entry ID
                $secondaryTopicIds[] = $topicEntryId;
            }
        }

        // Extract the excerpt, stripping HTML tags; the stripper also trims it
        $excerpt = HtmlTagStripper::strip(XpathHelper::getStringValue(
            $item,
            'excerpt:encoded'
        ));

        // ---
        // Collect the article's tags as `knowledge_base_tags` ENTRY ids.
        // `UAMS-Web/uams-statamic-archive#2534` converted the tags into a site-scoped
        // structured collection and the article's `tags` field into an `entries`
        // picker, so a `knowledge_base_tags::slug` term id resolves to nothing
        // there (wordpress-importer-archive#1220). The term pass runs before any article is written and
        // records each tag's entry; an unresolved tag is omitted rather than
        // written back as a slug. Only when tag import is enabled.
        // ---
        $tagTermIds = [];

        // An `if` reads the setting the same with or without the cast
        // @pest-mutate-ignore: RemoveBooleanCast
        if (
            (bool) config(
                'wordpress-import.import_tags',
                true
            )
        ) {
            // Find all WordPress categories on the item that belong to the 'kbtag' taxonomy
            $kbtagCategories = $item->xpath('category[@domain="kbtag"]');

            foreach (\is_array($kbtagCategories) ? $kbtagCategories : [] as $category) {
                // Resolve the nicename (slug) to its WordPress term id
                $termId = self::getNestedMapString(
                    $this->nicenameToTermIdMap,
                    'kbtag',
                    (string) $category['nicename']
                );

                // Resolve the term id to the entry the term pass imported it as
                $entryId = $termId === null
                    ? null
                    : TermDestinationRegistry::referenceFor(
                        'kbtag',
                        $termId
                    );

                // ---
                // Unresolved, or already stored: omit it. The IDs are never numeric,
                // so a loose search would find the same.
                // ---
                // @pest-mutate-ignore: TrueToFalse
                if ($entryId === null || $entryId === '' || \in_array($entryId, $tagTermIds, true)) {
                    continue;
                }

                $tagTermIds[] = $entryId;
            }
        }

        // Get attachments from _mkb_article_attachments postmeta for file attachments
        $processedAttachments = $this->getArticleAttachments(
            $item,
            $site,
            $assetImporter,
            $output,
            $xmlParser,
            $originalWordPressUrl
        );

        // ---
        // Generate slug and ID using BaseEntryCreator for consistency with other entry
        // types
        // ---
        [$entrySlug, $entryId] = $this->generateSlugAndId(
            $postName,
            $postId,
            $title
        );

        // Keep the generated ID, to tell an entry this run saved from one it skipped
        $generatedEntryId = $entryId;

        // ---
        // Extract thumbnail ID from postmeta before content processing.
        // ---

        // Initialize thumbnail ID variable for processing.
        $thumbnailId = null;

        // Extract all postmeta elements from the article XML
        $postmeta = XpathHelper::xpathElements($item, 'wp:postmeta');

        // Iterate through each postmeta element to find _thumbnail_id
        foreach ($postmeta as $meta) {
            // Get the meta_key value
            $metaKey = XpathHelper::getStringValue(
                $meta,
                'wp:meta_key'
            );

            // ---
            // Check if the current meta key is '_thumbnail_id', which indicates
            // that this meta element contains the ID of the featured image (attachment)
            // associated with the article or entry.
            // ---
            if ($metaKey === '_thumbnail_id') {
                // Get the meta_value, which should be the attachment ID
                $thumbnailId = XpathHelper::getStringValue(
                    $meta,
                    'wp:meta_value'
                );

                // Stop searching after finding the relevant meta_key
                break;
            }
        }

        // ---
        // Process content through TextProcessor for Bard format conversion
        // ---

        // Initialize the processed content array; will be populated if content is present
        $processedContent = [];

        // Only process content if it is not empty after trimming
        if (\trim($content) !== '') {
            // Set collection handle in processing context for link processing
            ProcessingContext::setCollectionHandle($collectionHandle);

            // Set Statamic entry ID in processing context for link processing
            ProcessingContext::setStatamicEntryId($entryId);

            // ---
            // Create temporary entry data with _thumbnail_id for block processors
            // ---

            // Initialize temporary entry data array for block processors
            $tempEntryData = [];

            // Check if thumbnail ID exists before storing in entry data
            if ($thumbnailId !== null && $thumbnailId !== '') {
                // Store thumbnail ID in temporary entry data for block processors
                $tempEntryData['_thumbnail_id'] = $thumbnailId;
            }

            // Set entry data in processing context for block processors
            ProcessingContext::setEntryData($tempEntryData);

            // Get XML attachments for image processing during content conversion
            $xmlAttachments = $xmlParser->getAttachments();

            // Use terms that were already retrieved for term map building
            $terms = $xmlParser->getTerms();

            // Set XML path in processing context for link processing
            ProcessingContext::setXmlPath($xmlParser->getFilePath());

            // Process content through TextProcessor for Bard format conversion
            $processedContent = TextProcessor::processForBard(
                $content,
                $site,
                $assetImporter,
                $output,
                false, // Do not apply WpAutoP formatting for knowledge base content
                false, // Not InnerBlocks content
                $xmlAttachments,
                $terms,
                $entrySlug, // Page slug for logging purposes
                $entryId,
                $collectionHandle,
                [], // preProcessedNodes
                $xmlParser->getFilePath() // Pass XML path for link resolution
            );
        }

        // ---
        // Create date object for scheduled posts (knowledge bases don't use
        // status_and_visibility)
        // ---

        // Initialize the entry date to null; will be set if a valid post date is provided
        $entryDate = null;

        // Only attempt to create a DateTime object if a post date is present
        if ($postDate !== '') {
            // Attempt to create a DateTime object from the post date string
            try {
                $entryDate = new DateTime($postDate);
            } catch (Exception $e) {
                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'site' => $site,
                        'error' => $e->getMessage(),
                    ],
                    $originalWordPressUrl
                );

                // Log warning for invalid date formats but continue processing
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        "KbEntryCreator::createArticleEntry: Invalid date format for article '{$title}' (ID: {$postId}): {$postDate}",
                        $logData['page_url']
                    ),
                    $logData['context']
                );
            }
        }

        // Fetch the default value for the title_options field from the article blueprint
        $titleOptionsDefault = FieldDefaultHelper::getDefaultValueForBlueprintField(
            "collections.{$collectionHandle}.article",
            'title_options'
        );

        // ---
        // Map restriction_query from _mkb_restrict_role postmeta
        // ---

        // ---
        // Initialize restrictionQuery as null; will determine value based on postmeta or
        // fallback
        // ---
        $restrictionQuery = null;

        // Initialize roles array to store restriction roles for later processing
        $restrictionRoles = null;

        // Extract all postmeta elements from the article XML
        $postmeta = XpathHelper::xpathElements($item, 'wp:postmeta');

        // Iterate through each postmeta element to find _mkb_restrict_role
        foreach ($postmeta as $meta) {
            // Get the meta_key value
            $metaKey = XpathHelper::getStringValue(
                $meta,
                'wp:meta_key'
            );

            // ---
            // If the meta key is '_mkb_restrict_role', process the restriction roles for this
            // article
            // ---
            if ($metaKey === '_mkb_restrict_role') {
                // Get the meta_value, which should be a JSON array
                $metaValue = XpathHelper::getStringValue(
                    $meta,
                    'wp:meta_value'
                );

                // Decode the value as JSON to get the roles array
                $roles = \json_decode(
                    $metaValue,
                    true
                );

                if (
                    \is_array($roles)
                    && \count($roles) > 0
                ) {
                    // Store roles for later processing
                    $restrictionRoles = $roles;

                    // ---
                    // If the only role is 'guest', set restriction_query to false. Otherwise, set
                    // to true.
                    // ---
                    $restrictionQuery = (
                        \count($roles) === 1
                        && (
                            in_array($roles[0], ['guest', '', null], true)
                        )
                    ) ? false : true;
                }

                // ---
                // If roles is empty or decoding fails, do not set restrictionQuery here;
                // fallback will handle it.
                // ---

                // Stop searching after finding the relevant meta_key
                break;
            }
        }

        $restrictionQuery ??= FieldDefaultHelper::getDefaultValueForBlueprintField(
            "collections.{$collectionHandle}.article",
            'restriction_query'
        );

        // Fetch the default value for the template field from the article blueprint
        $templateDefault = FieldDefaultHelper::getDefaultValueForBlueprintField(
            "collections.{$collectionHandle}.article",
            'template'
        );

        // Get the default value for title_options from the article blueprint
        $titleOptionsDefault = FieldDefaultHelper::getDefaultValueForBlueprintField(
            "collections.{$collectionHandle}.article",
            'title_options'
        );

        // ---
        // Initialize featured image variable for processing. The credit flag is read
        // only beside an imported image, which always sets it first.
        // ---
        $featuredImage = null;
        // @pest-mutate-ignore: FalseToTrue
        $featuredImageHasCredit = false;

        // ---
        // If thumbnail ID exists, import the featured image. A blank ID imports no
        // image, so the blank check only saves the search.
        // ---
        // @pest-mutate-ignore: EmptyStringToNotEmpty
        if (
            $thumbnailId !== null && $thumbnailId !== ''

        ) {
            // ---
            // Stream through attachments without loading all into memory. The
            // factory refuses a source with no parts rather than yielding none.
            // ---
            $streamParser = ContentStreamFactory::open(
                $xmlParser,
                'KbEntryCreator::createArticleEntry'
            );

            // Initialize variable to hold the found attachment
            $xmlAttachment = null;

            // Stream each attachment <item> node one at a time
            foreach ($streamParser->streamItems('attachment') as $attachment) {
                // Check if this attachment's post_id matches the thumbnail ID
                if (
                    XpathHelper::getStringValue(
                        $attachment,
                        'wp:post_id'
                    ) === $thumbnailId
                ) {
                    // If found, store the found attachment XML element for later use (e.g., to
                    // extract the image URL or other metadata for the featured image)
                    $xmlAttachment = $attachment;

                    // Exit the loop immediately after finding the relevant attachment to avoid
                    // unnecessary iterations and ensure only the first match is used
                    break;
                }
            }

            // If a matching attachment was found, import it as the featured image
            if ($xmlAttachment !== null) {
                // Import the featured image with graceful handling for disabled uploads
                $featuredImage = ImageProcessor::importImageWithDisabledUploadHandling(
                    $thumbnailId,
                    $site,
                    $assetImporter,
                    $output,
                    [$xmlAttachment],
                    'featured image',
                    'KbEntryCreator::createEntries'
                );

                // Resolve the credit from the same attachment the image came from.
                $featuredImageHasCredit = FeaturedImageCreditResolver::hasCredit(
                    $thumbnailId,
                    [$xmlAttachment]
                );
            }
        }

        // Create entry data
        $entryData = [
            'title' => $title, // Set the entry title using the extracted or processed title value
            'title_options' => $titleOptionsDefault, // Set the default title options for the entry (e.g., display settings)
            'content' => $processedContent, // Set the processed content for the entry, formatted for Bard (Statamic's rich text)
            'parent' => $topicId, // Set the parent topic ID for this entry to establish hierarchy in the knowledge base
            'template' => $templateDefault, // Set the default template to be used for rendering this entry
            'restriction_query' => $restrictionQuery, // Set the restriction query, if any, to control access to this entry
            'original_wordpress_url' => $originalWordPressUrl, // Set the original WordPress URL for reference
        ];

        // Check if there are any secondary topics to set
        if ($secondaryTopicIds !== []) {
            // Set the secondary topics for the entry
            $entryData['secondary_topic'] = $secondaryTopicIds;
        }

        // Check if there are any tags to set
        if ($tagTermIds !== []) {
            // Set the tags for the entry
            $entryData['tags'] = $tagTermIds;
        }

        // Only set excerpt if it is non-empty; a blank excerpt saved is dropped as empty
        // @pest-mutate-ignore: EmptyStringToNotEmpty
        if ($excerpt !== '') {
            // Store original excerpt before truncation
            $originalExcerpt = $excerpt;

            // Check if the excerpt exceeds the character limit
            if (mb_strlen($excerpt) > 160) {
                // Truncate excerpt to excerpt field limit (157 characters + '...')
                $excerpt = $this->truncateToExcerptLimit($excerpt);

                // Initialize import_wordpress_notes as an array (not set earlier in $entryData)
                $entryData['import_wordpress_notes'] = [];

                // Add original excerpt value to import_wordpress_notes
                $entryData['import_wordpress_notes'][] = "Original excerpt value: {$originalExcerpt}";
            }

            // Set the excerpt (truncated if needed)
            $entryData['excerpt'] = $excerpt;
        }

        // ---
        // Only set featured_image_16_9 if it was successfully imported. The empty
        // check is not redundant: with uploads disabled,
        // importImageWithDisabledUploadHandling() returns the attachment's URL as a
        // string, and an attachment row whose file is gone yields '' rather than
        // null — which would otherwise take the credit toggle beside no image.
        // ---
        if ($featuredImage !== null && $featuredImage !== '') {
            $entryData['featured_image_16_9'] = $featuredImage;

            // ---
            // Surface the photo credit the WordPress site displayed. Gated on the
            // image having imported AND the asset carrying a `creator`; an
            // uncredited image stays on the blueprint default rather than taking
            // an inert toggle (wordpress-importer-archive#439).
            // ---
            if ($featuredImageHasCredit) {
                $entryData['featured_image_credit_query'] = true;
            }
        }

        // ---
        // Store thumbnail ID in entry data for block processors. A null or blank
        // value saved is dropped as empty, so the checks only keep it out.
        // ---
        // @pest-mutate-ignore: BooleanAndToBooleanOr, EmptyStringToNotEmpty
        if ($thumbnailId !== null && $thumbnailId !== '') {
            $entryData['_thumbnail_id'] = $thumbnailId;
        }

        // Only set attachment if there are attachments to set
        if ($processedAttachments !== []) {
            $entryData['attachment'] = $processedAttachments;
        }

        // ---
        // If restriction_query is true (not guest-only) and roles were found, set
        // restriction_category, restriction_user_roles, and format roles for
        // import_wordpress_notes
        // ---
        if (
            $restrictionQuery === true
            && $restrictionRoles !== null
        ) {
            // Apply restriction fields using shared helper
            RestrictionHelper::applyRestrictionFields(
                $entryData,
                $restrictionRoles
            );
        }

        // Set the entry ID for the article entry
        $entryData['id'] = $entryId;

        // Add updated_at field from post modified date
        $this->addUpdatedAtField(
            $entryData,
            $item,
            'KbEntryCreator::createArticleEntry'
        );

        // Save entry using BaseEntryCreator's saveEntry method
        $savedEntry = $this->saveEntry(
            $collectionHandle,
            $site,
            $entrySlug,
            $entryData,
            $output,
            $entryDate,
            // Published only when live in WordPress and not gated by WP-Members (wordpress-importer-archive#1241)
            $this->publishUnlessWpMembersGated(
                $postStatus === 'publish',
                $this->readWpMembersBlock($item),
                'KbEntryCreator::createArticleEntry',
                $output
            ),
            $postDate
        );

        // ---
        // Adopt the id the entry was SAVED under, before anything downstream reads
        // `$entryId`. Overwriting a matched entry preserves its existing id (wordpress-importer-archive#767),
        // so the lookup below, `addEntryToTree()`, `articleIdMap`, the deferred
        // related-articles pass keyed on it, and `KbLinkRegistry::registerKbPostMapping()`
        // would otherwise all name an entry that was just deleted. That is the
        // stale-id failure wordpress-importer-archive#761 fixed, arriving by a different route.
        //
        // It also matters more than a missing lookup: Statamic's `Entry::delete()`
        // on a structured collection moves every child page up to the parent and
        // removes the node before saving the tree, so the pre-existing hierarchy is
        // actively pruned rather than left stale — with nothing to rebuild it if
        // `addEntryToTree()` is skipped.
        //
        // An article that was not saved keeps the generated id and stays out of the tree.
        // ---
        if ($savedEntry instanceof Entry) {
            $entryId = self::mixedToString(
                $savedEntry->id(),
                $entryId
            );

            // Add the article entry to the tree as a child of the topic
            $this->addEntryToTree(
                $entryId,
                $collectionHandle,
                $site,
                $topicId
            );
        }

        // ---
        // Record the related articles for the deferred pass under the id just
        // adopted. The pass looks the entry up by this key, so recording it under
        // the generated id lost the related articles of every re-imported article.
        //
        // An existing article this run was told to skip keeps the related articles
        // it has: the pass would otherwise save over a list an editor may have changed.
        // ---
        $skipped = $this->entryOverwriteBehavior === 'Skip matching files'
            && $entryId !== $generatedEntryId;

        if (! $skipped) {
            $this->getRelatedArticles(
                $item,
                $entryId,
                $entrySlug,
                ($entryDate instanceof DateTime) ? Carbon::instance($entryDate) : null
            );
        }

        // Store the mapping from WordPress post ID to Statamic entry ID
        $this->articleIdMap[$postId] = $entryId;

        // ---
        // Carry the historical MinervaKB view / like / dislike aggregates into the
        // app's sideband `article_engagement_counts` store. These are NOT entry
        // fields — the app deliberately keeps them out of the YAML (#286 / #287) —
        // so this is a direct upsert, idempotent across re-imports, and a no-op when
        // the export carries no counts (wordpress-importer-archive#427).
        //
        // Keyed on the id `saveEntry()` actually PERSISTED, which `$entryId` holds
        // from the adoption above, not the one generated. Under `Skip matching files`
        // it returns the pre-existing entry and never writes the generated id at all,
        // so seeding that id would leave a row pointing at an entry that does not
        // exist while the live article stayed at zero.
        // ---
        ArticleEngagementSeeder::seed(
            $item,
            $entryId
        );

        // Register the kb post slug mapping for link processing
        KbLinkRegistry::registerKbPostMapping(
            $postName,
            $entryId
        );

        // Clean up variables to free up memory
        unset(
            $restrictionRoles
        );
    }

    /**
     * Find the topic ID for an article based on its categories.
     *
     * Articles are associated with only one topic: the first kbtopic
     * category in the article's category list.
     *
     * @param  SimpleXMLElement  $item  The wp:post element
     * @return ?string The topic entry ID or null if no topic found
     */
    protected function findArticleTopic(
        SimpleXMLElement $item
    ): ?string {
        // Find categories with domain="kbtopic", and take the first
        $categories = $item->xpath('category[@domain="kbtopic"]');
        $firstCategory = \is_array($categories) ? ($categories[0] ?? null) : null;

        // An article with no topic category has no topic
        if (! $firstCategory instanceof SimpleXMLElement) {
            return null;
        }

        // Resolve the topic entry the category's nicename (slug) names
        return $this->findTopicEntryId((string) $firstCategory['nicename']);
    }

    /**
     * Find the entry ID of the topic created for a kbtopic nicename.
     *
     * A topic's entry slug is its term's slug, and every kbtopic term is in
     * the nicename map, so a nicename the map does not name names no topic.
     *
     * @param  string  $nicename  The kbtopic category nicename (slug)
     * @return ?string The topic entry ID, or null when no topic was created for it
     */
    private function findTopicEntryId(
        string $nicename
    ): ?string {
        // Resolve the nicename to its WordPress term ID
        $termId = self::getNestedMapString(
            $this->nicenameToTermIdMap,
            'kbtopic',
            $nicename
        );

        // Map the term ID to the topic entry created for it
        return $termId === null ? null : ($this->topicIdMap[$termId] ?? null);
    }

    /**
     * Get article attachments from _mkb_article_attachments postmeta.
     *
     * This method extracts attachment data from the article's postmeta and processes
     * both asset attachments (by ID) and external files (with URL and label).
     * It builds the replicator structure for the attachment field.
     *
     * @param  SimpleXMLElement  $item  The wp:post element
     * @param  string  $site  Selected site handle
     * @param  AssetImporter  $assetImporter  Service for importing assets
     * @param  OutputInterface  $output  Console output interface
     * @param  XmlParser  $xmlParser  Service for parsing WordPress XML files
     * @return list<array<int|string, mixed>> Array of processed attachments for the replicator field
     */
    protected function getArticleAttachments(
        SimpleXMLElement $item,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        XmlParser $xmlParser,
        ?string $pageUrl = null
    ): array {
        $processedAttachments = []; // Initialize an array to store processed attachment data for the article

        // Extract all postmeta elements from the article XML
        $postmeta = XpathHelper::xpathElements($item, 'wp:postmeta');

        // Iterate through each postmeta element to find _mkb_article_attachments
        foreach ($postmeta as $meta) {
            // Get the meta_key value
            $metaKey = XpathHelper::getStringValue(
                $meta,
                'wp:meta_key'
            );

            // ---
            // Check if the current meta key is '_mkb_article_attachments', which indicates
            // that this meta element contains attachment data (e.g., files) associated with
            // the article
            // ---
            if ($metaKey === '_mkb_article_attachments') {
                // Get the meta_value, which should be a serialized array
                $metaValue = XpathHelper::getStringValue(
                    $meta,
                    'wp:meta_value'
                );

                // Unserialize the value to get the attachments array
                $attachmentData = @\unserialize($metaValue, ['allowed_classes' => false]);

                // Ensure that the decoded attachment data is an array before processing
                if (\is_array($attachmentData)) {
                    // Get XML attachments for asset import
                    $xmlAttachments = $xmlParser->getAttachments();

                    // Process each attachment in the array
                    foreach ($attachmentData as $attachmentItem) {
                        // Check if this is an asset attachment (numeric string)
                        if (\is_numeric($attachmentItem)) {
                            // This is an asset attachment ID, find the attachment URL and import as file
                            $attachmentUrl = $this->findAttachmentUrl(
                                (string) $attachmentItem,
                                $xmlAttachments
                            );

                            // ---
                            // Import only an attachment the export carries; the file import
                            // helper imports nothing for a blank URL
                            // ---
                            if ($attachmentUrl !== null) {
                                // Use the file import helper which handles disabled uploads gracefully
                                $assetPath = FileProcessor::importFileWithDisabledUploadHandling(
                                    $attachmentUrl,
                                    $site,
                                    $assetImporter,
                                    $output,
                                    'files',
                                    'attachment',
                                    'KbEntryCreator::createEntries',
                                    $pageUrl
                                );

                                // Check if the asset path was successfully determined (e.g., the file
                                // was imported or located). Only proceed if a valid asset path exists.
                                if ($assetPath !== null && $assetPath !== '') {
                                    // Add asset set to replicator
                                    $processedAttachments[] = [
                                        'id' => (new RowId)->generate(),
                                        'type' => 'asset',
                                        'enabled' => true,
                                        'asset' => $assetPath,
                                    ];
                                }
                            }
                        } elseif (
                            \is_string($attachmentItem)
                            && str_starts_with($attachmentItem, 'EXTERNAL')
                        ) {
                            // ---
                            // Check if the attachment item is a string that starts with 'EXTERNAL',
                            // indicating it represents an external file reference rather than a standard
                            // attachment. If so, parse the EXTERNAL string to extract label and URL.
                            // ---

                            // This is an external file, parse the EXTERNAL string
                            $externalData = $this->parseExternalAttachment($attachmentItem);

                            // Check if the external data was successfully parsed from the EXTERNAL string.
                            if ($externalData !== null && $externalData !== []) {
                                // Add external_file set to replicator
                                $attachmentSet = [
                                    'id' => (new RowId)->generate(),
                                    'type' => 'external_file',
                                    'enabled' => true,
                                    'label' => $externalData['label'],
                                    'url' => $externalData['url'],
                                ];

                                // ---
                                // Note: Size is parsed from EXTERNAL string but not stored
                                // in the replicator item
                                // ---

                                // Add the attachment set to the processed attachments array
                                $processedAttachments[] = $attachmentSet;
                            }
                        }
                    }
                }

                // Stop searching after finding the relevant meta_key
                break;
            }
        }

        // ---
        // Return the array of processed attachments, which may include imported assets and
        // external file references, to be used in the entry's replicator field or
        // elsewhere.
        // ---
        return $processedAttachments;
    }

    /**
     * Find the attachment URL for a given attachment ID.
     *
     * This method searches through the XML attachments to find the URL
     * for a specific attachment ID.
     *
     * @param  string  $attachmentId  The attachment ID to search for
     * @param  array<int|string, mixed>  $xmlAttachments  Array of XML attachment elements
     * @return ?string The attachment URL or null if not found
     */
    protected function findAttachmentUrl(
        string $attachmentId,
        array $xmlAttachments
    ): ?string {
        // ---
        // Iterate through all provided XML attachment elements to find the one that
        // matches the requested attachment ID.
        // ---
        foreach ($xmlAttachments as $attachment) {
            if (! $attachment instanceof SimpleXMLElement) {
                continue;
            }

            // Extract the post ID from the current attachment XML element
            $postId = XpathHelper::getStringValue(
                $attachment,
                'wp:post_id'
            );

            // Check if the extracted post ID matches the requested attachment ID
            if ($postId === $attachmentId) {
                // If a match is found, return the attachment URL from the XML element
                return XpathHelper::getStringValue(
                    $attachment,
                    'wp:attachment_url'
                );
            }
        }

        // If no matching attachment is found after iterating all elements, return null
        return null;
    }

    /**
     * Parse external attachment string to extract URL, label, and size.
     *
     * Parses strings in the format: EXTERNAL{{url}}LABEL{{label}}SIZE{{size}}
     * where LABEL and SIZE are optional. The keys are directly concatenated
     * to the values without any separators.
     *
     * @param  string  $externalString  The external attachment string
     * @return array<int|string, mixed>|null Array with 'url', 'label', and optionally 'size' keys, or null if parsing fails
     */
    protected function parseExternalAttachment(
        string $externalString
    ): ?array {
        // Check if the string starts with EXTERNAL{{
        if (
            ! str_starts_with($externalString, 'EXTERNAL{{')
        ) {
            return null;
        }

        // Remove the EXTERNAL{{ prefix
        $remaining = \substr(
            $externalString,
            10
        );

        // Find the position of LABEL{{ if it exists
        $labelPos = \strpos(
            $remaining,
            'LABEL{{'
        );

        // Find the position of SIZE{{ if it exists
        $sizePos = \strpos(
            $remaining,
            'SIZE{{'
        );

        // Extract the URL (everything before LABEL{{ or SIZE{{ or the end)
        $urlEndPos = \strlen($remaining);

        // ---
        // If LABEL{{ is found in the string, set the end position of the URL to the
        // position where LABEL{{ starts. This means the URL ends right before the label
        // section.
        // ---
        if ($labelPos !== false) {
            $urlEndPos = $labelPos;
        } elseif ($sizePos !== false) {
            // ---
            // If LABEL{{ is not found but SIZE{{ is found, set the end position of the URL to
            // the position where SIZE{{ starts. This means the URL ends right before the size
            // section.
            // ---

            $urlEndPos = $sizePos;
        }

        // Extract the URL substring from the start of the string up to
        // $urlEndPos, and trim any leading or trailing whitespace to ensure a clean URL
        // value.
        $url = \trim(
            \substr(
                $remaining,
                0,
                $urlEndPos
            )
        );

        // Remove all trailing closing braces from the URL if present.
        $url = $this->removeTrailingClosingBraces($url);

        // Extract the label if LABEL{{ exists
        // Default to URL as label
        $label = $url;

        // If LABEL{{ is found, extract the label value from the string
        if ($labelPos !== false) {
            // Calculate the start position of the label content (after "LABEL{{")
            $labelStart = $labelPos + 7;

            // Initialize label end position to the end of the string
            $labelEndPos = \strlen($remaining);

            // If SIZE{{ comes after LABEL{{, set label end to start of SIZE{{; a SIZE{{
            // that is absent (false) never does
            if ($sizePos > $labelPos) {
                $labelEndPos = $sizePos;
            }

            // Extract the label content substring between label start and label end
            $labelContent = \substr(
                $remaining,
                $labelStart,
                $labelEndPos - $labelStart
            );

            // Remove all trailing closing braces if present at the end of the label content.
            $labelContent = $this->removeTrailingClosingBraces($labelContent);

            // Trim whitespace from the extracted label content for a clean label value
            $label = \trim($labelContent);
        }

        // Extract the size if SIZE{{ exists
        $size = null;

        // If SIZE{{ is found, extract the size value from the string
        if ($sizePos !== false) {
            // Calculate the start position of the size content (after "SIZE{{")
            $sizeStart = $sizePos + 6;

            // Extract the size content substring from size start to the end of the string
            $sizeContent = \substr(
                $remaining,
                $sizeStart
            );

            // Remove all trailing closing braces if present at the end of the size content.
            $sizeContent = $this->removeTrailingClosingBraces($sizeContent);

            // Trim whitespace from the extracted size content for a clean size value
            $size = \trim($sizeContent);
        }

        // Return the parsed URL, label, and size as an associative array
        $result = [
            'url' => $url,
            'label' => $label,
        ];

        // Check if the size was extracted from the EXTERNAL string
        if ($size !== null) {
            // Add size to the result array
            $result['size'] = $size;
        }

        // Return the result array
        return $result;
    }

    /**
     * Truncate text to excerpt field limit of 160 characters.
     *
     * If the text exceeds 160 characters, it will be truncated to 157
     * characters and '...' will be appended, resulting in a total of 160
     * characters. Uses multibyte-safe string functions for proper handling
     * of Unicode characters.
     *
     * @param  string  $text  The text to truncate
     * @return string The truncated text (157 characters + '...' if truncated, or original text if 160 characters or less)
     */
    protected function truncateToExcerptLimit(
        string $text
    ): string {
        // Check if text exceeds character limit for excerpt field
        if (mb_strlen($text) > 160) {
            // ---
            // Truncate text to 157 characters and append '...' to meet field
            // requirements (total 160 characters)
            // ---
            return mb_substr(
                $text,
                0,
                157
            ).'...';
        }

        // Return original text if within limit
        return $text;
    }

    /**
     * Remove all trailing closing braces from a string.
     *
     * This method removes trailing closing braces (either '}}' pairs or single '}'
     * characters) from the end of a string. It continues removing braces until
     * no more trailing braces are found.
     *
     * @param  string  $string  The string to clean
     * @return string The string with trailing closing braces removed
     */
    protected function removeTrailingClosingBraces(
        string $string
    ): string {
        // Remove every trailing closing brace
        return \rtrim(
            $string,
            '}'
        );
    }

    /**
     * Get related articles from postmeta.
     *
     * This method extracts related article data from the article's
     * postmeta. It looks for the _mkb_related_articles meta key and
     * unserializes the stored data to get related article information.
     * The actual resolution of WordPress post IDs to Statamic entry IDs
     * is deferred until after all articles are imported.
     *
     * @param  SimpleXMLElement  $item  The wp:post element
     * @param  string  $entryId  The Statamic entry ID for this article
     * @param  ?string  $entrySlug  The entry slug for proper lookup
     * @param  ?Carbon  $entryDate  The entry date for proper lookup
     */
    protected function getRelatedArticles(
        SimpleXMLElement $item,
        string $entryId,
        ?string $entrySlug = null,
        ?Carbon $entryDate = null
    ): void {
        // Initialize an array to hold related article WordPress post IDs
        $relatedArticleIds = [];
        // Extract all postmeta elements from the article XML
        $postmeta = XpathHelper::xpathElements($item, 'wp:postmeta');

        // Search for related articles metadata
        foreach ($postmeta as $meta) {
            // Extract the meta key from the current postmeta element
            $metaKey = XpathHelper::getStringValue(
                $meta,
                'wp:meta_key'
            );

            // Check if the meta key indicates related articles metadata
            if ($metaKey === '_mkb_related_articles') {
                // Extract the meta value, which should be a serialized array of related post IDs
                $metaValue = XpathHelper::getStringValue(
                    $meta,
                    'wp:meta_value'
                );

                // Attempt to unserialize the meta value
                $unserialized = @\unserialize($metaValue, ['allowed_classes' => false]);

                // If unserialization is successful and yields an array, process related articles
                if (\is_array($unserialized)) {
                    $normalizedPostIds = [];
                    foreach ($unserialized as $unserializedPostId) {
                        $normalizedPostId = self::mixedToString(
                            $unserializedPostId,
                            ''
                        );
                        if ($normalizedPostId === '') {
                            continue;
                        }

                        $normalizedPostIds[] = $normalizedPostId;
                    }

                    // Store the WordPress post IDs for deferred processing
                    $relatedArticleIds = $normalizedPostIds;

                    // Store this entry's related articles data for later processing
                    $this->deferredRelatedArticles[$entryId] = [
                        'wp_post_ids' => $normalizedPostIds,
                        'slug' => $entrySlug,
                        'date' => $entryDate,
                    ];
                }

                // Exit the loop after finding and processing the related articles metadata
                break;
            }
        }

        $this->deferredRelatedArticles[$entryId] ??= [
            'wp_post_ids' => $relatedArticleIds,
            'slug' => $entrySlug,
            'date' => $entryDate,
        ];
    }

    /**
     * Process deferred related articles after all articles are imported.
     *
     * This method resolves WordPress post IDs to Statamic entry IDs for
     * related articles and updates the entries with the resolved data.
     * It is called after all articles have been imported to ensure that
     * all entry IDs are available for cross-referencing.
     *
     * The method performs the following operations:
     *   - Iterates through all deferred related articles data
     *   - Resolves WordPress post IDs to Statamic entry IDs using the article ID map
     *   - Uses forced fresh cache lookup to ensure correct entry retrieval with
     *     original dates
     *   - Updates each entry's related_articles field with resolved entry IDs
     *   - Tracks and reports on successful updates and unmapped references
     *   - Provides detailed logging for monitoring and debugging
     *
     * This deferred processing approach ensures that all articles exist
     * before attempting to establish relationships between them, preventing
     * broken references in the knowledge base structure.
     *
     * @param  OutputInterface  $output  Console output interface for user feedback
     * @param  string  $site  Selected site handle for context and logging
     * @param  string  $collectionHandle  The collection handle (e.g., 'knowledge_bases')
     */
    protected function processDeferredRelatedArticles(
        OutputInterface $output,
        string $site,
        string $collectionHandle
    ): void {
        // Early return if no deferred related articles to process
        if ($this->deferredRelatedArticles === []) {
            return;
        }

        // Display progress message to user and log operation start
        $output->writeln('Processing deferred related articles...'); // Inform user

        // Initialize counters for tracking processing results
        $processedCount = 0; // Number of entries successfully updated
        $unmappedCount = 0; // Number of related articles that could not be resolved

        // Iterate over each entry that has deferred related articles
        foreach (ImportProgress::track($output, 'kb related articles', $this->deferredRelatedArticles) as $entryId => $relatedArticlesData) {
            // ---
            // Use EntryFinder to get the correct entry with proper path handling. Force
            // fresh cache lookup to ensure we get the correct entry with original date;
            // a cached copy of an entry this import just saved is the same entry.
            // ---
            // @pest-mutate-ignore: TrueToFalse
            $entry = EntryFinder::findEntryById(
                $collectionHandle,
                $entryId,
                true,
                $site
            );

            if (! $entry instanceof Entry) {
                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'entry_id' => $entryId,
                        'site' => $site,
                    ]
                );

                // Log a warning if the entry could not be found and skip to the next
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        "KbEntryCreator::processDeferredRelatedArticles: Entry with ID '{$entryId}' not found when processing deferred related articles for site {$site}.",
                        $logData['page_url']
                    ),
                    $logData['context']
                );

                // Skip this entry if not found
                continue;
            }

            // Initialize arrays to track resolved and unmapped article references
            $resolvedRelatedArticles = []; // Statamic entry IDs that were successfully resolved
            $unmappedArticles = []; // WordPress post IDs that could not be resolved

            // Resolve each WordPress post ID to its corresponding Statamic entry ID
            $wpPostIds = $relatedArticlesData['wp_post_ids'];
            foreach ($wpPostIds as $wpPostId) {
                // Check if the WordPress post ID has a corresponding Statamic entry ID
                if (isset($this->articleIdMap[$wpPostId])) {
                    // Add the resolved Statamic entry ID to the related articles array
                    $resolvedRelatedArticles[] = $this->articleIdMap[$wpPostId];
                } else {
                    // Track unmapped references for reporting and debugging
                    $unmappedArticles[] = $wpPostId;

                    // Increment unmapped count
                    $unmappedCount++;
                }
            }

            // Update the entry with resolved related articles if any were found
            if ($resolvedRelatedArticles !== []) {
                // Use the EntryFieldUpdater utility to update the field
                $success = EntryFieldUpdater::updateEntryFields(
                    $entry,
                    ['related_articles' => $resolvedRelatedArticles]
                );

                // ---
                // `updateEntryFields()` returns the save's own boolean result when it
                // saves, and the updated `Entry` only when `$save` is false; either way
                // `false` means the update did not land.
                // This call takes the saving default, so testing `instanceof Entry`
                // never matched — every SUCCESSFUL update logged an error and left
                // `$processedCount` at zero, so the console reported "Processed 0
                // entries with related articles" on a run that updated them all.
                // Test the failure sentinel instead, which is correct for both
                // return shapes (wordpress-importer-archive#761).
                // ---
                if ($success !== false) {
                    // Increment processed count
                    $processedCount++;
                } else {
                    // Log an error if the update failed
                    $entryTitle = self::mixedToString(
                        $entry->get('title'),
                        ''
                    );
                    Log::error("KbEntryCreator::processDeferredRelatedArticles: Failed to update entry '{$entryTitle}' (ID: {$entryId}) with related articles for site {$site}.");
                }
            }

            // Log warnings for unmapped articles to help identify missing content
            if ($unmappedArticles !== []) {
                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'entry_id' => $entryId,
                        'entry_title' => self::mixedToString(
                            $entry->get('title'),
                            ''
                        ),
                        'unmapped_articles' => $unmappedArticles,
                        'site' => $site,
                    ]
                );

                // Log warning for unmapped articles
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        "KbEntryCreator::processDeferredRelatedArticles: Entry '".self::mixedToString(
                            $entry->get('title'),
                            ''
                        )."' (ID: {$entryId}) has unmapped related articles: ".\implode(
                            ', ',
                            $unmappedArticles
                        )." for site {$site}.",
                        $logData['page_url']
                    ),
                    $logData['context']
                );
            }
        }

        // Display final processing results to user
        $output->writeln("Processed {$processedCount} entries with related articles.");

        // Warn the user if any related article references could not be resolved
        if ($unmappedCount > 0) {
            $output->writeln("<comment>Warning: {$unmappedCount} related article references could not be resolved.</comment>");
        }
    }

    /**
     * Resolve the MinervaKB home page (kb_page) details from the XML export.
     *
     * Looks up the WordPress page whose post ID matches the kb_page MinervaKB
     * setting and returns its slug, title, and parent post ID. These let the
     * knowledge base root adopt the kb_page's slug/URL and mount beneath its
     * parent page. The kb_page is no longer materialized as a `pages` entry
     * (see wordpress-importer-archive#297); the knowledge base root owns that URL instead.
     *
     * @param  XmlParser  $xmlParser  Service for parsing WordPress XML files
     * @param  OutputInterface  $output  Console output interface
     * @param  array<int|string, mixed>|null  $minervaKbSettings  Optional MinervaKB settings from JSON file
     * @return array{wp_id: string, slug: string, title: string, parent_wp_id: string}|null The kb_page details (wp_id, slug, title, parent_wp_id), or null when not configured/found
     */
    protected function resolveKnowledgeBaseHomePageInfo(
        XmlParser $xmlParser,
        OutputInterface $output,
        ?array $minervaKbSettings = null
    ): ?array {
        // Extract kb_page value from settings; no settings means no kb_page
        $kbPageId = self::mixedToString(
            $minervaKbSettings['kb_page'] ?? null,
            ''
        );

        // No kb_page configured means the root keeps its default slug/URL
        if ($kbPageId === '') {
            return null;
        }

        // Display progress message
        $output->writeln("Resolving knowledge base home page (WordPress ID: {$kbPageId})...");

        // Find the page post type item in XML with matching wp:post_id
        $xmlPageItem = $this->findPageInXml(
            $xmlParser,
            $kbPageId
        );

        // Without the XML item the root falls back to its default slug/URL
        if (! $xmlPageItem instanceof SimpleXMLElement) {
            $output->writeln("<comment>Warning: MinervaKB home page (WordPress ID '{$kbPageId}') was not found in the XML export; the knowledge base will use its default slug and URL.</comment>");

            return null;
        }

        // Extract the WordPress post name (slug) from the XML item
        $postName = XpathHelper::getStringValue(
            $xmlPageItem,
            'wp:post_name'
        );

        // Decode HTML entities and strip HTML tags from the page title
        $title = HtmlTagStripper::strip(
            html_entity_decode(
                (string) $xmlPageItem->title,
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            )
        );
        $title = $title !== '' ? $title : 'Untitled Page';

        // Extract the WordPress parent post ID so the root can mount beneath it
        $parentWpId = XpathHelper::getStringValue(
            $xmlPageItem,
            'wp:post_parent'
        );

        // Return the resolved kb_page details for the knowledge base root
        return [
            'wp_id' => $kbPageId,
            'slug' => $postName,
            'title' => $title,
            'parent_wp_id' => $parentWpId,
        ];
    }

    /**
     * Find a page post type item in the XML with matching wp:post_id.
     *
     * Uses XpathHelper to locate the item element with the specified post ID
     * and post type 'page'.
     *
     * @param  XmlParser  $xmlParser  Service for parsing WordPress XML files
     * @param  string  $postId  The WordPress post ID to find
     * @return ?SimpleXMLElement The found page item or null if not found
     */
    protected function findPageInXml(
        XmlParser $xmlParser,
        string $postId
    ): ?SimpleXMLElement {
        // Get the XML file path
        $xmlPath = $xmlParser->getFilePath();
        if (! \is_string($xmlPath) || $xmlPath === '') {
            return null;
        }

        // Use shared utility method to find the page item by post ID
        $item = XpathHelper::findItemByPostId(
            $xmlPath,
            $postId,
            'page'
        );

        // Check if item was found
        if (! $item instanceof SimpleXMLElement) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'wordpress_post_id' => $postId,
                    'xml_path' => $xmlPath,
                ]
            );

            // Log warning for debugging and monitoring purposes
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    "KbEntryCreator::findPageInXml: Page with WordPress ID '{$postId}' not found in XML file: {$xmlPath}",
                    $logData['page_url']
                ),
                $logData['context']
            );
        }

        // Clean up variables to free up memory
        unset($xmlPath);

        // Return the found page item or null
        return $item;
    }

    /**
     * Extract term description from XML term element.
     *
     * This method attempts to extract description from two sources in priority order:
     *   1. wp:term_description element (standard WordPress term description)
     *   2. wp:termmeta with wp:meta_key = 'description' (custom meta field)
     *
     * @param  SimpleXMLElement  $termElement  The XML term element
     * @return string The extracted description or empty string if not found
     */
    protected function extractTermDescription(
        SimpleXMLElement $termElement
    ): string {
        // First, try to get description from wp:term_description element
        $description = XpathHelper::getStringValue(
            $termElement,
            'wp:term_description'
        );

        // Check if wp:term_description has content
        if (\trim($description) !== '') {
            // Return the description value from wp:term_description
            return $description;
        }

        // ---
        // Fall back to wp:termmeta with wp:meta_key = 'description'
        // ---

        // Get all termmeta elements for the term
        $termmeta = $termElement->xpath('wp:termmeta');

        // Check if no termmeta elements were found
        if (\is_array($termmeta)) {
            // Loop through each termmeta element to find description
            foreach ($termmeta as $meta) {
                // Extract the meta key
                $metaKey = XpathHelper::getStringValue(
                    $meta,
                    'wp:meta_key'
                );

                // Return the first 'description' meta value; a blank one strips to empty
                if ($metaKey === 'description') {
                    return XpathHelper::getStringValue(
                        $meta,
                        'wp:meta_value'
                    );
                }
            }
        }

        // Return empty string if no description was found
        return '';
    }

    /**
     * Find a term element in XML by term ID.
     *
     * This method searches through all terms in the XML parser to find
     * the term element with the matching term ID.
     *
     * @param  XmlParser  $xmlParser  Service for parsing WordPress XML files
     * @param  string  $termId  The WordPress term ID to find
     * @return ?SimpleXMLElement The found term element or null if not found
     */
    protected function findTermElementById(
        XmlParser $xmlParser,
        string $termId
    ): ?SimpleXMLElement {
        // Get all terms from the XML parser
        $terms = $xmlParser->getTerms();

        // Iterate through all terms to find the one with matching term ID
        foreach ($terms as $term) {
            // Every term the parser returns is an element; this only narrows the type
            // @pest-mutate-ignore: InstanceOfToTrue, ContinueToBreak
            if (! $term instanceof SimpleXMLElement) {
                continue;
            }

            // Extract the term ID from the current term element
            $currentTermId = XpathHelper::getStringValue(
                $term,
                'wp:term_id'
            );

            // Check if the term ID matches
            if ($currentTermId === $termId) {
                // Return the matching term element
                return $term;
            }
        }

        // Return null if no matching term was found
        return null;
    }

    /**
     * Resolve a nested map value as a string.
     *
     * @param  array<int|string, mixed>  $map  The nested map
     * @param  string  $firstKey  The first-level key
     * @param  string  $secondKey  The second-level key
     */
    private static function getNestedMapString(
        array $map,
        string $firstKey,
        string $secondKey
    ): ?string {
        // TermMapBuilder stores each term ID as a non-empty string
        $firstLevel = $map[$firstKey] ?? null;
        $value = \is_array($firstLevel) ? ($firstLevel[$secondKey] ?? null) : null;

        return \is_string($value) ? $value : null;
    }

    /**
     * Normalize a mixed value to string.
     *
     * @param  mixed  $value  Value to normalize
     * @param  string  $defaultValue  Fallback value
     */
    private static function mixedToString(
        mixed $value,
        string $defaultValue
    ): string {
        if (\is_string($value)) {
            return $value;
        }

        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }

        return $defaultValue;
    }

    /**
     * Validates taxonomy terms for knowledge base entries.
     *
     * Knowledge base entries don't use any taxonomies, so this method does nothing.
     * This method is required by the abstract parent class.
     *
     * @param  string  $collectionHandle  The collection handle
     * @param  string  $site  The Statamic site handle
     * @param  OutputInterface  $output  Console output for logging
     */
    public function validateTaxonomies(
        string $collectionHandle,
        string $site,
        OutputInterface $output
    ): void {
        // Knowledge base entries don't use any taxonomies, so no validation is needed
    }
}
