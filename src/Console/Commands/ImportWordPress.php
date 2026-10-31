<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Console\Commands;

use App\Services\BardContentSanitizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File as LaravelFile;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Statamic\Entries\Collection as StatamicCollection;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Stache;
use Statamic\Globals\GlobalSet as StatamicGlobalSet;
use Statamic\Globals\Variables as StatamicVariables;
use Statamic\Stache\Query\EntryQueryBuilder;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\FailedAssetCollector;
use UAMSWeb\WordPressImporter\Services\WordPressImport\DeferredProcessing\DeferredProcessingCoordinator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators\KbEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\EntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormPluginCoordinator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Globals\DepartmentOrganizationDesignator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Globals\DepartmentOrganizationMap;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Globals\DepartmentOrganizationOutcome;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Globals\GlobalCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi\Client as MigrationApiClient;
use UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi\ContentTypeReads;
use UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi\MaterializationScope;
use UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi\MigrationApiException;
use UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi\StdinKeyReader;
use UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi\WxrMaterializer;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Navigation\NavigationMenuImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\FindADocLocationsParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\FindADocProvidersParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\LogoListParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\SplitExportPartLocator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlStreamParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\ContentTypeSelector;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Loaders\FormidableFormsLoader;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Loaders\GravityFormsLoader;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Loaders\NinjaFormsLoader;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings\Loader as MinervaKbSettingsLoader;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings\Selector as MinervaKbSettingsSelector;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\NavigationMenuSelector;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\SiteSelector;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\TaxonomyOverwriteBehaviorSelector;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Traits\HasPromptsSupport;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Traits\HasQuestionHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\ReusableBlocks\ReusableBlockCoordinator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Structures\TreeBuilder;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\BaseTaxonomyValidator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\Validators\LogosItemCategoriesValidator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\AssetReferenceUpdates;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\CollectionStructureManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentRoot;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\DatalessEntrySweeper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningDisplayService;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\PeopleShortcodeProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\SearchIndexUpdates;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\SiteManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\SiteResolver;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\VerificationWorkspace;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\WxrSanitizedStream;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

/**
 * Imports WordPress content from an XML export file into Statamic.
 *
 * This command orchestrates the WordPress import process by parsing the XML file,
 * checking for supported content types, prompting the user for import options,
 * and creating Statamic entries or global sets. It scans selected content types
 * for Classic blocks, alerts the user if found, and offers an option to stop or
 * continue with a note. It delegates specific tasks to service classes for
 * separation of concerns.
 *
 * Default assumptions for content type mappings:
 *   - 'page' -> Pages Collection ('pages')
 *   - 'post' -> Posts Collection ('post')
 *   - 'uamspeople' -> People Collection ('people')
 *   - 'redirect_rule' -> Redirects Global Set ('redirects')
 *   - 'tablepress_table' -> Tables Collection ('tables')
 *   - 'kb' -> Knowledge Base Articles Collection ('knowledge_bases')
 *   - 'logo-list-item' -> Logo List Items Collection ('logo_list_items')
 *   - 'advert' -> Classifieds Collection ('classifieds')
 *
 * After selecting a content type, it prompts to confirm the default destination
 * if applicable, auto-setting subsequent responses. If declined or unmapped, it
 * falls back to the full workflow.
 *
 * Note: This command checks for supported content types before prompting for a
 * site, exiting early if none are found. It handles enabling the target
 * collection or global set (e.g., 'pages' or 'redirects') for the selected site
 * during setup, including user prompts. Post-parsing enabling of additional
 * collections/taxonomies (e.g., 'people', 'people_group', 'region') due to
 * shortcodes like 'show-people' or blocks like 'wp:acf/fad-providers' is managed
 * by SiteConfigurator.php. After each cycle, it prompts to import more content,
 * looping if desired.
 *
 * Usage:
 * php artisan cache:clear && php artisan statamic:import:wordpress /path/to/export.xml
 *
 * The command automatically analyzes content item sizes and suggests memory
 * limits for large individual posts/pages that might cause memory exhaustion.
 *
 * While debugging, you can also clear the contents of `laravel.log` as part of
 * the command with this usage:
 *   - Windows environment:
 *     php artisan cache:clear && echo. > storage\logs\laravel.log && php -d memory_limit=1024M artisan statamic:import:wordpress /path/to/export.xml
 *   - Mac environment:
 *     php artisan cache:clear && > storage/logs/laravel.log && php -d memory_limit=1024M artisan statamic:import:wordpress /path/to/export.xml
 */
class ImportWordPress extends Command
{
    /**
     * Include the HasPromptsSupport trait to provide platform detection for Laravel
     * Prompts support.
     */
    use HasPromptsSupport;

    /**
     * Include the HasQuestionHelper trait to provide centralized question handling.
     */
    use HasQuestionHelper;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'statamic:import:wordpress {file? : The path to the WordPress XML export file, or the directory to materialize into when --source=api}
        {--source=wxr : Where content comes from: wxr (a local export file) or api (the migration API)}
        {--site= : The network site id to read, when --source=api. A positive whole number; the run is refused if it is anything else}
        {--modified-after= : Only read posts modified after this timestamp, when --source=api. YYYY-MM-DD, "YYYY-MM-DD HH:MM" (GMT, seconds optional), or ISO 8601; anything else is refused by the API}
        {--network= : The network WordPress address to read, when --source=api. An absolute https:// URL, supplying without a prompt what the run would otherwise ask for. Required by --key-from-stdin}
        {--key-from-stdin : Read that network\'s migration API key from the first line of standard input rather than asking for it, so an API-sourced run needs no terminal. Requires --network}
        {--unsupported-content=abort : Non-interactive policy for content containing unsupported blocks (Classic, Freeform, Details) or KB entries without topics: abort|skip|import}
        {--content-type= : The single content type to import, for an unattended run. Without it a non-interactive run imports the first supported type and reports the rest as untouched, exiting non-zero}
        {--minerva-generation= : The MinervaKB generation this site runs, v1 or v2, when --source=api, as read from the network plugins page. Needed only where the migration API serves no plugin header naming the generation and both generations\' settings rows are served, which is otherwise refused. A declaration the header contradicts is refused}
        {--verify : Run the whole import against a temporary copy of the content and assets, print the same counts, and leave the application\'s own directories unchanged}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Imports content from a WordPress XML export into Statamic';

    /**
     * Default content type to destination mappings.
     *
     * @var array<string, array{type: string, handle: string, name: string}>
     */
    protected array $defaultMappings;

    /**
     * Get the default content type to destination mappings.
     *
     * @return array<string, array{type: string, handle: string, name: string}>
     */
    public static function getDefaultMappings(): array
    {
        return [
            'page' => [
                'type' => 'collection',
                'handle' => 'pages',
                'name' => 'Pages',
            ],
            'post' => [
                'type' => 'collection',
                'handle' => 'posts',
                'name' => 'Posts',
            ],
            'uamspeople' => [
                'type' => 'collection',
                'handle' => 'people',
                'name' => 'People',
            ],
            'tshowcase' => [
                'type' => 'collection',
                'handle' => 'people',
                'name' => 'People',
            ],
            'redirect_rule' => [
                'type' => 'global',
                'handle' => 'redirects',
                'name' => 'Redirects',
            ],
            'tablepress_table' => [
                'type' => 'collection',
                'handle' => 'tables',
                'name' => 'Tables',
            ],
            'kb' => [
                'type' => 'collection',
                'handle' => 'knowledge_bases',
                'name' => 'Knowledge Base Articles',
            ],
            'logo-list-item' => [
                'type' => 'collection',
                'handle' => 'logo_list_items',
                'name' => 'Logo List Items',
            ],
            // WP Adverts listings, into the board built by UAMS-Web/uams-statamic-archive#2096
            'advert' => [
                'type' => 'collection',
                'handle' => 'classifieds',
                'name' => 'Classifieds',
            ],
            'wp_block' => [
                'type' => 'collection',
                'handle' => 'reusable_content',
                'name' => 'Reusable Blocks',
            ],
            'nav_menu_item' => [
                'type' => 'navigation',
                'handle' => 'primary_navigation',
                'name' => 'Navigation Menu Items',
            ],
        ];
    }

    /**
     * Get WordPress taxonomy to Statamic taxonomy mappings.
     *
     * @return array<string, array{type: string, handle: string, name: string}>
     */
    public static function getTaxonomyMappings(): array
    {
        return [
            'category' => [
                'type' => 'taxonomy',
                'handle' => 'post_categories',
                'name' => 'Post Categories',
            ],
            'post_tag' => [
                'type' => 'taxonomy',
                'handle' => 'post_tags',
                'name' => 'Post Tags',
            ],
            'logo-list-category' => [
                'type' => 'taxonomy',
                'handle' => 'logos_item_categories',
                'name' => 'Logos, Item Categories',
            ],
            'logo-list-label' => [
                'type' => 'taxonomy',
                'handle' => 'logos_item_labels',
                'name' => 'Logos, Item Labels',
            ],
            'uamspeople-categories' => [
                'type' => 'taxonomy',
                'handle' => 'people_groups',
                'name' => 'People, Groups',
            ],
            'tshowcase-categories' => [
                'type' => 'taxonomy',
                'handle' => 'people_groups',
                'name' => 'People, Groups',
            ],
            'kbtag' => [
                'type' => 'taxonomy',
                'handle' => 'knowledge_base_tags',
                'name' => 'Knowledge Base, Tags',
            ],
            'mkb_faq_category' => [
                'type' => 'taxonomy',
                'handle' => 'faq_categories',
                'name' => 'FAQ, Categories',
            ],
            'advert_category' => [
                'type' => 'taxonomy',
                'handle' => 'classified_categories',
                'name' => 'Classifieds, Categories',
            ],
        ];
    }

    /**
     * Maps WordPress handles to Statamic handles based on type.
     *
     * @param  string  $wordpressHandle  The WordPress handle (taxonomy or post type)
     * @param  string  $type  The type of mapping ('taxonomy' or 'collection')
     * @return ?string The corresponding Statamic handle, or null if not found
     *
     * @throws InvalidArgumentException If the type is not supported
     */
    public static function mapWordPressToStatamicHandle(
        string $wordpressHandle,
        string $type
    ): ?string {
        // Get the appropriate mappings based on type
        $mappings = match ($type) {
            // Handle taxonomy mappings
            'taxonomy' => self::getTaxonomyMappings(),

            // Handle collection mappings
            'collection' => self::getDefaultMappings(),

            // Throw exception for unsupported mapping types
            default => throw new InvalidArgumentException(
                "Unsupported mapping type '{$type}'. Supported types are: taxonomy, collection"
            ),
        };

        // Look up the specific mapping
        $mapping = $mappings[$wordpressHandle] ?? null;

        // Return handle if this is the correct type mapping
        return ($mapping !== null && $mapping['type'] === $type)
            ? $mapping['handle']
            : null;
    }

    /**
     * Supported content types for direct import.
     *
     * The 'wp_navigation' post type will not be supported. Added in WordPress 5.9,
     * this was the post type used to save the Navigation block's "items" (i.e., data).
     *
     * @var list<string>
     */
    protected array $supportedContentTypes = [
        'page',
        'post',
        'uamspeople',
        'tshowcase',
        'redirect_rule',
        'kb',
        'logo-list-item',
        'advert',
        'nav_menu_item',
    ];

    /**
     * The Bard content sanitizer service instance.
     */
    protected BardContentSanitizer $bardContentSanitizer;

    /**
     * Initial filesize of the app directory before import.
     *
     * @var int
     */
    protected $initialFilesize;

    /**
     * Create a new command instance with its dependencies.
     *
     * @param  XmlParser  $xmlParser  Service for parsing WordPress XML files
     * @param  SiteSelector  $siteSelector  Service for selecting the target site
     * @param  ContentTypeSelector  $contentTypeSelector  Service for selecting the content type
     * @param  TaxonomyOverwriteBehaviorSelector  $taxonomyOverwriteBehaviorSelector  Service for selecting taxonomy overwrite behavior
     * @param  MinervaKbSettingsSelector  $minervaKbSettingsSelector  Service for selecting MinervaKB settings
     * @param  MinervaKbSettingsLoader  $minervaKbSettingsLoader  Loader whose source this command configures
     * @param  GravityFormsLoader  $gravityFormsLoader  Gravity Forms sourcing seam, whose source this command configures
     * @param  NinjaFormsLoader  $ninjaFormsLoader  Ninja Forms sourcing seam, whose source this command configures
     * @param  FormidableFormsLoader  $formidableFormsLoader  Formidable Forms sourcing seam, whose source this command configures
     * @param  EntryCreator  $entryCreator  Service for creating Statamic entries
     * @param  GlobalCreator  $globalCreator  Service for creating/modifying Statamic global sets
     * @param  TreeBuilder  $treeBuilder  Service for building collection trees
     * @param  AssetImporter  $assetImporter  Service for importing assets
     * @param  KbEntryCreator  $kbEntryCreator  The KbEntryCreator instance
     * @param  FormPluginCoordinator  $formPluginCoordinator  The FormPluginCoordinator instance
     * @param  SiteManager  $siteManager  The SiteManager instance
     * @param  NavigationMenuSelector  $navigationMenuSelector  The NavigationMenuSelector instance
     * @param  NavigationMenuImporter  $navigationMenuImporter  The NavigationMenuImporter instance
     * @param  ReusableBlockCoordinator  $reusableBlockCoordinator  The ReusableBlockCoordinator instance
     * @param  CollectionStructureManager  $collectionStructureManager  The CollectionStructureManager instance
     * @param  DeferredProcessingCoordinator  $deferredProcessingCoordinator  The DeferredProcessingCoordinator instance
     * @param  BardContentSanitizer  $bardContentSanitizer  The Bard content sanitizer service instance
     */
    public function __construct(
        protected XmlParser $xmlParser,
        protected SiteSelector $siteSelector,
        protected ContentTypeSelector $contentTypeSelector,
        protected TaxonomyOverwriteBehaviorSelector $taxonomyOverwriteBehaviorSelector,
        protected MinervaKbSettingsSelector $minervaKbSettingsSelector,
        protected MinervaKbSettingsLoader $minervaKbSettingsLoader,
        protected EntryCreator $entryCreator,
        protected GlobalCreator $globalCreator,
        protected TreeBuilder $treeBuilder,
        protected AssetImporter $assetImporter,
        protected KbEntryCreator $kbEntryCreator,
        protected FormPluginCoordinator $formPluginCoordinator,
        protected SiteManager $siteManager,
        protected NavigationMenuSelector $navigationMenuSelector,
        protected NavigationMenuImporter $navigationMenuImporter,
        protected ReusableBlockCoordinator $reusableBlockCoordinator,
        protected CollectionStructureManager $collectionStructureManager,
        protected DeferredProcessingCoordinator $deferredProcessingCoordinator,
        BardContentSanitizer $bardContentSanitizer,
        protected GravityFormsLoader $gravityFormsLoader,
        protected NinjaFormsLoader $ninjaFormsLoader,
        protected FormidableFormsLoader $formidableFormsLoader
    ) {
        parent::__construct();
        $this->bardContentSanitizer = $bardContentSanitizer;

        // Initialize default mappings from static method
        $this->defaultMappings = self::getDefaultMappings();

        // Set form plugin coordinator on EntryCreator for post-processing
        $this->entryCreator->setFormPluginCoordinator($this->formPluginCoordinator);

        // Set reusable block coordinator on EntryCreator for post-processing
        $this->entryCreator->setReusableBlockCoordinator($this->reusableBlockCoordinator);

        // Set deferred processing coordinator on EntryCreator for post-processing
        $this->entryCreator->setDeferredProcessingCoordinator(
            $this->deferredProcessingCoordinator
        );

        // Set site manager on dependent services
        $this->entryCreator->setSiteManager($this->siteManager);
    }

    /**
     * When this run began, as a Unix timestamp with microseconds.
     *
     * Bounds the dataless-entry sweep to files this run produced (wordpress-importer-archive#1160).
     */
    private float $runStartedAt = 0.0;

    /**
     * Remove entry files this run materialized with no data in them, and report them.
     *
     * **The defect this answers wrote into a site the run never named** — 37 `knowledge_bases`
     * entries into a site with no content on disk, inside 70 milliseconds, each carrying an `id`
     * and a `blueprint` and nothing else. Nothing in the output, the summary or the log mentioned
     * them, and the host app rejects every one: saving a `knowledge_bases` entry with no Topic
     * parent throws from its own validation listener. So a run invented content that had never
     * existed, in a shape the app considers invalid.
     *
     * Because it writes into a site the run does not know about, the sweep cannot be scoped by
     * collection or site. It is scoped by **time** instead: only files written since this run
     * began. That bound is what keeps a delete from reaching content the run did not produce.
     *
     * The root comes from `ContentRoot`, never `base_path('content/…')` — the host serves a
     * disposable mirror under `APP_ENV=testing`, and a surface pointed at the wrong one deletes
     * content no run produced.
     */
    private function sweepDatalessEntries(): void
    {
        $removed = DatalessEntrySweeper::sweep(
            ContentRoot::collections(),
            $this->runStartedAt
        );

        if ($removed === []) {
            return;
        }

        foreach ($removed as $entry) {
            // Logged individually, because the collection, site, slug and id are what an operator
            // needs to tell an invented entry from one they were expecting.
            Log::warning(
                'ImportWordPress: removed a dataless entry file this run materialized.',
                $entry
            );

            ImportWarningManager::addWarning(
                'content',
                'dataless_entries_removed',
                $entry
            );
        }

        $this->warn(\sprintf(
            '  - Removed %d dataless entry file(s) this run materialized. See the warning summary.',
            \count($removed)
        ));
    }

    /**
     * Execute the console command.
     *
     * Thin wrapper that runs {@see self::runImport()} with two of Statamic's
     * per-save sweeps disabled for the duration of the import:
     *
     *   - {@see AssetReferenceUpdates}: the importer deletes the existing asset on
     *     each overwrite to force a fresh upload, which fires AssetDeleted and
     *     triggers a full scan of every entry, term, global, and user to null-out
     *     references. That sweep is unnecessary here (the importer rewrites asset
     *     references itself) and crashes on a single term with a null blueprint.
     *   - {@see SearchIndexUpdates}: every term/entry save fires Statamic's search
     *     `UpdateItemIndexes`, which rebuilds the `default` (all-content) index by
     *     scanning every searchable across all sites — ~12s per save. Disabling it
     *     for the run and rebuilding the indexes once at the end (see runImport())
     *     turns hundreds of full rebuilds into a single pass.
     *
     * Both restore their listeners afterwards — even if the import throws — so the
     * changes never leak into other commands sharing the process.
     *
     * @return int Exit code (0 for success, 1 for failure)
     */
    public function handle(): int
    {
        // Stamped before any work so the dataless-entry sweep can bound itself to files this
        // run produced. Anything older predates it and is not this run's to delete (wordpress-importer-archive#1160).
        $this->runStartedAt = \microtime(true);

        $run = fn (): int => AssetReferenceUpdates::withDisabled(
            fn (): int => SearchIndexUpdates::withDisabled(
                fn (): int => $this->runImport()
            )
        );

        return $this->isVerificationRun() ? $this->runInWorkspace($run) : $run();
    }

    /**
     * Whether this run leaves the application's own directories unchanged (#254).
     */
    private function isVerificationRun(): bool
    {
        return $this->option('verify') === true;
    }

    /**
     * Run the import against a temporary copy of what it writes, removing the copy however
     * the run ends (#254).
     *
     * @param  callable(): int  $run  The import
     * @return int The import's exit code, or 1 when the workspace cannot be set up
     */
    private function runInWorkspace(callable $run): int
    {
        try {
            $workspace = VerificationWorkspace::enter();
        } catch (RuntimeException $e) {
            $this->error("The verification run was refused: {$e->getMessage()} Nothing was imported.");

            return 1;
        }

        // The copies are newer than the old stamp, and the sweep bounds itself by it
        $this->runStartedAt = \microtime(true);

        $this->info('Verification run: writing to a temporary copy. This application\'s content and assets will not change.');

        try {
            return $run();
        } finally {
            if ($workspace->leave()) {
                $this->info('Verification run: the temporary copy was removed.');
            } else {
                $this->warn("Verification run: the temporary copy at '{$workspace->root()}' could not be removed. Delete it by hand.");
            }
        }
    }

    /**
     * Resolve and validate the --unsupported-content policy option.
     *
     * @return ?string The validated policy (abort|skip|import), or null when the supplied value is invalid
     */
    private function resolveUnsupportedContentPolicy(): ?string
    {
        // Read the raw option value from the console input
        $policy = $this->option('unsupported-content');

        // Treat a missing value as the default policy, as a bare --unsupported-content gives
        if ($policy === null) {
            return 'abort';
        }

        // Reject non-string values defensively. The console answers a string for an option
        // that takes a value, so this only types it.
        if (! is_string($policy)) {
            // @pest-mutate-ignore: RemoveEarlyReturn
            return null;
        }

        // Accept only the supported policy values. Every value compared is a string, so
        // a loose comparison accepts the same ones.
        // @pest-mutate-ignore: TrueToFalse
        return \in_array($policy, ['abort', 'skip', 'import'], true)
            ? $policy
            : null;
    }

    /**
     * Orchestrate the WordPress import process.
     *
     * Orchestrates the import process by:
     *   1. Validating the XML file path.
     *   2. Checking memory requirements and prompting user if needed.
     *   3. Parsing the XML to analyze content types.
     *   4. Checking for supported content types, exiting if none found.
     *   5. Prompting for site selection if supported content exists.
     *   6. Looping to prompt for content type, scanning for Classic blocks, and
     *      handling user choice.
     *   7. Confirming/enabling default destinations or falling back to full
     *      selection if applicable.
     *   8. Checking/enabling relevant taxonomies (e.g., people_group) for the site.
     *   9. Prompting for overwrite behaviors for the selected collection, assets,
     *      and tables (if TablePress content detected).
     *   10. Importing content and validating taxonomies.
     *   11. Asking to import more, repeating if yes.
     *   12. Clearing the Laravel cache and reporting completion when done.
     *
     * @return int Exit code (0 for success, 1 for failure)
     */
    private function runImport(): int
    {
        // ---
        // Resolve the content source (wordpress-importer-archive#587)
        // ---

        $source = $this->option('source');
        $source = \is_string($source) ? \strtolower($source) : 'wxr';

        if ($source !== 'wxr' && $source !== 'api') {
            $this->error("Unknown --source '{$source}'. Use 'wxr' or 'api'.");

            return 1;
        }

        // A file-sourced run reads MinervaKB settings from the file the operator picks, so a
        // declared generation would decide nothing there. Refused rather than ignored, so an
        // operator who passed it is not left believing it applied (wordpress-importer-archive#1235).
        $minervaGeneration = $this->option('minerva-generation');

        if ($source === 'wxr' && $minervaGeneration !== null && $minervaGeneration !== '') {
            $this->error('The --minerva-generation option applies only with --source=api.');

            return 1;
        }

        // The API source materializes WXR parts and rejoins the file flow below, so every
        // importer subsystem downstream reads real files exactly as it always has.
        if ($source === 'api') {
            $materialized = $this->materializeFromApi();

            if ($materialized === null) {
                return 1;
            }

            return $this->runImportForParts($materialized);
        }

        // Set the MinervaKB loader's source explicitly rather than relying on it being
        // unset: it is a container singleton, so a previous run in this process would
        // otherwise still be the source (wordpress-importer-archive#586).
        $this->minervaKbSettingsLoader->clearMigrationApiSource();
        $this->navigationMenuSelector->clearMigrationApiSource();
        $this->gravityFormsLoader->clearMigrationApiSource();
        $this->ninjaFormsLoader->clearMigrationApiSource();
        $this->formidableFormsLoader->clearMigrationApiSource();

        // A file-sourced export is whatever the operator exported, so no scope applies to it.
        // Cleared for the same reason as the seams above: it is process-global (wordpress-importer-archive#1179).
        MaterializationScope::clear();

        // Validate the XML file path
        $filePath = $this->argument('file');

        if (! is_string($filePath) || $filePath === '') {
            $this->error('A valid XML file path is required.');

            return 1;
        }

        // Check if the file does not exist
        if (! \file_exists($filePath)) {
            // Show error for missing file
            $this->error("The file '{$filePath}' does not exist.");

            // Exit with error status code
            return 1;
        }

        // ---
        // Resolve the split-export part-set (a plain single file has no siblings)
        // ---

        // Discover any numbered sibling parts of a split export from the given path
        $partSet = SplitExportPartLocator::locate($filePath);

        // Refuse to import a numbered sequence with a gap rather than a partial set
        if ($partSet->hasGap()) {
            // Format the missing part indices as .NNN.xml for a clear operator message
            $missingLabels = \implode(
                ', ',
                \array_map(
                    fn (int $index): string => sprintf('.%03d.xml', $index),
                    $partSet->missingParts
                )
            );

            // Show error naming the missing parts and abort the import
            $this->error("Split export is missing part(s): {$missingLabels}. Refusing to import a partial set — restore the missing part(s) and retry.");

            // Exit with error status code
            return 1;
        }

        // Resolve the ordered parts driving this import (one entry for a single file)
        $parts = $partSet->parts;

        // Inform the operator when a multi-part split export was detected
        if ($partSet->isSplit) {
            // Report how many parts will be imported together as one logical import
            $partCount = \count($parts);

            $this->info("Detected split export: importing {$partCount} parts as a single import.");
        }

        // Hand the resolved part-set to the shared import flow. The API source rejoins
        // here with materialized parts, so nothing below this point knows which it was.
        return $this->runImportForParts($parts);
    }

    /**
     * Resolve and validate the --minerva-generation option (wordpress-importer-archive#1235).
     *
     * @return string|false|null The declared generation, null when none was given, or false
     *                           when the value is invalid and the error has been reported
     */
    private function resolveMinervaGeneration(): string|false|null
    {
        // Read the raw option value from the console input
        $generation = $this->option('minerva-generation');

        // Treat a missing or empty value as no declaration
        if ($generation === null || $generation === '') {
            return null;
        }

        // Accept only a generation the settings loader knows, compared case-insensitively
        $normalized = \is_string($generation) ? \strtolower($generation) : null;

        if ($normalized !== null && isset(MinervaKbSettingsLoader::DECLARABLE_GENERATIONS[$normalized])) {
            return $normalized;
        }

        // `%s` formats a scalar as the cast does, so the cast only types it
        // @pest-mutate-ignore: RemoveStringCast
        $this->error(\sprintf(
            "The --minerva-generation option must be one of: %s, got '%s'.",
            \implode(', ', \array_keys(MinervaKbSettingsLoader::DECLARABLE_GENERATIONS)),
            \is_scalar($generation) ? (string) $generation : \get_debug_type($generation)
        ));

        return false;
    }

    /**
     * Fetch content from the migration API and write it out as WXR parts.
     *
     * @return list<string>|null The materialized part paths, or null when the fetch failed
     */
    private function materializeFromApi(): ?array
    {
        // The file argument names the output directory here rather than an input file; with
        // none given the parts land in a unique temporary directory that is reported below.
        $destination = $this->argument('file');

        if (! \is_string($destination) || $destination === '') {
            // The extra entropy only makes a collision less likely; either name is unique.
            // @pest-mutate-ignore: TrueToFalse
            $destination = \sys_get_temp_dir()
                .\DIRECTORY_SEPARATOR
                .\uniqid('wordpress-import-', true);
        }

        // Create the directory unless it already exists
        if (! \is_dir($destination) && ! \mkdir($destination, 0o775, true)) {
            // A directory another process created between the two calls serves as well
            if (! \is_dir($destination)) {
                $this->error("Could not create the directory '{$destination}' for materialized parts.");

                return null;
            }
        }

        $site = $this->option('site');
        $modifiedAfter = $this->option('modified-after');

        // Reject a `--site` that is not a positive integer rather than discarding it. The
        // old `is_numeric($site) ? (int) $site : null` silently fell back to the API's
        // default site, so `--site=uams-health` — an operator reaching for the network
        // slug — imported an entirely different site's content and reported success (wordpress-importer-archive#680).
        // `--source` is already rejected this way a few lines above; there was no reason
        // for the two to differ.
        //
        // The pattern is deliberately narrower than `is_numeric`: that accepts `3.5`,
        // `0x1A`, `1e3` and leading `+`, each of which `(int)` would quietly reshape into a
        // site id nobody asked for. The API's own route declares `minimum: 1`.
        $siteId = null;

        if ($site !== null && $site !== '') {
            if (! \is_string($site) || \preg_match('/^[1-9][0-9]*$/', $site) !== 1) {
                // `%s` formats a scalar as the cast does, so the cast only types it
                // @pest-mutate-ignore: RemoveStringCast
                $this->error(\sprintf(
                    "The --site option must be a positive whole number (the network blog id), got '%s'.",
                    \is_scalar($site) ? (string) $site : \get_debug_type($site)
                ));

                return null;
            }

            $siteId = (int) $site;
        }

        // Resolve the declared MinervaKB generation before asking for the network, so a typo
        // is reported before anything is fetched (wordpress-importer-archive#1235)
        $minervaGeneration = $this->resolveMinervaGeneration();

        if ($minervaGeneration === false) {
            return null;
        }

        $client = $this->askForNetwork();

        if (! $client instanceof MigrationApiClient) {
            return null;
        }

        try {
            // Point the MinervaKB settings loader at the same API. Its plugin configuration
            // lives in a `wp_options` row rather than in the content, so materialization
            // cannot carry it — it is fetched directly (wordpress-importer-archive#586).
            $this->minervaKbSettingsLoader->setMigrationApiSource(
                $client,
                $siteId,
                $minervaGeneration
            );

            // Point the navigation selector at the same API (wordpress-importer-archive#1145). Menu-location
            // assignments live in a `theme_mods_*` option rather than in the content, so
            // materialization cannot carry them; without this the run stops to ask the
            // operator to read the WordPress menu-locations screen.
            $this->navigationMenuSelector->setMigrationApiSource(
                $client,
                $siteId
            );

            // Point the Gravity Forms seam at the same API (wordpress-importer-archive#809). Form definitions live in
            // plugin tables rather than in the content, so materialization cannot carry them
            // either — they are fetched directly, exactly as the MinervaKB settings row is.
            $this->gravityFormsLoader->setMigrationApiSource(
                $client,
                $siteId
            );

            // And the Ninja Forms seam (wordpress-importer-archive#810). Ninja alone prompts once per referenced form
            // ID on the file path, because a `.nff` carries no ID; the endpoint serves one
            // with every form, so configuring this source is what retires that loop.
            $this->ninjaFormsLoader->setMigrationApiSource(
                $client,
                $siteId
            );

            // And the Formidable seam (wordpress-importer-archive#811). Its endpoint serves a JSON shape the XML export
            // does not, so this is the one seam whose API path translates; without this line
            // the selector sees no source and prompts for an XML export on an API run.
            $this->formidableFormsLoader->setMigrationApiSource(
                $client,
                $siteId
            );

            $this->info('Reading the migration API...');

            // The destination is currently the only sign a run is alive, and with no
            // directory argument it is a `sys_get_temp_dir()` path the run does not print
            // until it finishes — so an operator has to glob for it. Say it up front.
            $this->line("Writing WXR parts to {$destination}");

            // Clear before reading rather than relying on materialize() to overwrite it, so a
            // read that throws partway cannot leave a previous run's scope in force (wordpress-importer-archive#1179).
            MaterializationScope::clear();

            $materializer = $this->makeMaterializer(
                $client->withProgressReporter(function (string $message): void {
                    $this->line($message);
                }),
                $destination
            );

            // `--content-type` narrows the READ as well as the import (wordpress-importer-archive#1089). Without it the
            // whole site is fetched to import one type, which measured at roughly 16,000
            // records read for 25 imported. The materializer reads what the type declares in
            // `ContentTypeReads` (#109), so this narrows the fetch without dropping what the
            // import depends on.
            $scopedContentType = $this->option('content-type');

            $parts = $materializer->materialize(
                $siteId,
                \is_string($modifiedAfter) && $modifiedAfter !== '' ? $modifiedAfter : null,
                [],
                \is_string($scopedContentType) && \trim($scopedContentType) !== ''
                    ? \trim($scopedContentType)
                    : null
            );

            // Say so at the point of discovery rather than only in the end-of-run summary.
            // The whole failure this guards is that nobody notices (wordpress-importer-archive#688).
            $this->reportUnservedContent($materializer->unservedContent());

            // And what the redirect plugins served, with what the redirect domains
            // collection cannot express, so nothing a plugin rule did is dropped unseen (wordpress-importer-archive#1307)
            $this->reportRedirectPluginRules($materializer->redirectPluginRules());
        } catch (MigrationApiException $migrationApiException) {
            // A migration API failure is reported as itself rather than as a parse error;
            // the two have entirely different fixes.
            $this->error("Could not read the migration API: {$migrationApiException->getMessage()}");

            return null;
        }

        $partCount = \count($parts);

        // Name the directory: these files are the evidence for what was imported, and an
        // operator diffing them against a real export is how wordpress-importer-archive#588 gets proved.
        $this->info("Materialized {$partCount} WXR part(s) into {$destination}");

        return $parts;
    }

    /**
     * Ask the operator which network to read, and for the key that authenticates there.
     *
     * **Neither value is configured, and neither is accepted as a command-line option.**
     * The estate is 26 separate multisite networks, and each issues its own key from its
     * own admin page, so storing them would put 26 live credentials on disk for a job that
     * reads one network at a time. Passing the key as an option would be worse than
     * storing it: it would land in shell history and in the process list, readable by any
     * other account on the machine, and it would outlive the run in both.
     *
     * **The two are asked together because they only work together.** The plugin stores
     * its key per network, so a key from one network and an address from another
     * authenticate against nothing. Asking in one act is what stops them drifting apart —
     * which is the failure `wordpress-importer-archive#982` was filed about, where the address lived in
     * configuration and was edited between runs.
     *
     * @return MigrationApiClient|null A client bound to the named network, or null when
     *                                 the run cannot be set up
     */
    private function askForNetwork(): ?MigrationApiClient
    {
        // The unattended route, taken only when the operator opts in. It is checked before
        // the terminal guard below precisely because having no terminal is its whole
        // purpose -- a run that reaches the guard has not asked for this path.
        if ($this->option('key-from-stdin') === true) {
            return $this->networkFromStdin();
        }

        // A run with no terminal cannot be asked, and must not guess. Reading an unanswered
        // prompt as an empty key would send no credential, draw the API's `401`, and report
        // an operator's setup mistake as an authentication failure against a network that
        // is reachable and correctly configured.
        if (! $this->input->isInteractive()) {
            $this->error(
                'Reading the migration API needs the network address and its API key, and '
                .'both are asked at the prompt rather than stored. Re-run without '
                .'--no-interaction, or supply them without a terminal: pass the address as '
                .'--network=https://... and the key on the first line of standard input '
                .'with --key-from-stdin.'
            );

            return null;
        }

        // Honour `--network` here too. It is documented as "supplying without a prompt what
        // the run would otherwise ask for", and reading it only on the `--key-from-stdin`
        // path made that false on the path an operator is most likely to take: the flag was
        // accepted, ignored, and the address asked for anyway (wordpress-importer-archive#1088).
        $option = $this->option('network');
        $supplied = \is_string($option) ? \trim($option) : '';

        if ($supplied !== '') {
            $baseUrl = $supplied;
        } else {
            $answer = $this->ask('Which network? Paste its WordPress address, including https://');
            $baseUrl = \is_string($answer) ? $answer : '';
        }

        // Echo it back before the key is typed, whether it was typed or passed. Nothing else
        // in the run names the network, and under a per-network key the errors are
        // asymmetric: a typo that resolves nowhere is refused, and so is one that reaches a
        // network this key does not open — but an address the operator did not mean, whose
        // key they then paste, reads as a completely normal run against the wrong site's
        // content. That reasoning is about what the operator sees before spending a
        // credential, so it does not weaken when the address came from a flag.
        $this->line("  Reading: {$baseUrl}");

        $pasted = $this->secret("Paste that network's migration API key");
        $key = \is_string($pasted) ? $pasted : '';

        try {
            return MigrationApiClient::forRun($baseUrl, $key)
                ->withRetryReporter(function (string $message): void {
                    $this->warn($message);
                });
        } catch (MigrationApiException $migrationApiException) {
            $this->error("Could not read the migration API: {$migrationApiException->getMessage()}");

            return null;
        }
    }

    /**
     * Build the client from `--network` and the first line of standard input.
     *
     * **The unattended counterpart to `askForNetwork()`, and it keeps that method's two
     * guarantees rather than trading them away.** The key still reaches no configuration
     * file, no command-line option and no environment variable: it is consumed once from a
     * stream, which is neither shell history nor the process list. And the address and the
     * key are still supplied in one act, so they cannot drift apart between runs — the
     * failure `wordpress-importer-archive#982` was filed about, where the address lived in configuration and was
     * edited independently of the key.
     *
     * **The address is an option and the key is not, because only one of them is a
     * secret.** An `https://` URL in a process list discloses which network is being read,
     * which the run prints on screen anyway; a key there would be readable by any other
     * account on the machine and would outlive the run.
     *
     * @return MigrationApiClient|null A client bound to the named network, or null when
     *                                 the run cannot be set up
     */
    private function networkFromStdin(): ?MigrationApiClient
    {
        $option = $this->option('network');
        $baseUrl = \is_string($option) ? $option : '';

        // Refuse the pair rather than the key alone. Reading a key that no address can be
        // paired with would spend the operator's credential on a request that was never
        // going to be made, and the remedy names the flag that is missing rather than
        // leaving them to infer it from a validation failure further down.
        if (\trim($baseUrl) === '') {
            $this->error(
                '--key-from-stdin needs the network it belongs to. Pass the address as '
                .'--network=https://..., since a key from one network authenticates against '
                .'no other.'
            );

            return null;
        }

        // Name the network before the key is read, for the same reason the prompt echoes it:
        // an address the operator did not mean, whose key is then supplied, reads as a
        // completely normal run against the wrong site's content.
        $this->line("  Reading: {$baseUrl}");

        try {
            $key = StdinKeyReader::read($this->apiKeyStream());

            return MigrationApiClient::forRun($baseUrl, $key)
                ->withRetryReporter(function (string $message): void {
                    $this->warn($message);
                });
        } catch (MigrationApiException $migrationApiException) {
            $this->error("Could not read the migration API: {$migrationApiException->getMessage()}");

            return null;
        }
    }

    /**
     * Build the materializer that writes the API's content out as WXR parts.
     *
     * A method of its own so a test can stand in for the reads it makes.
     *
     * @param  MigrationApiClient  $client  The client to read through
     * @param  string  $destination  The directory to write the parts into
     */
    protected function makeMaterializer(
        MigrationApiClient $client,
        string $destination
    ): WxrMaterializer {
        // Build the materializer over the client and the directory
        return new WxrMaterializer(
            $client,
            $destination
        );
    }

    /**
     * The stream the `--key-from-stdin` route reads the API key from: standard input.
     *
     * A method of its own so a test can supply a key without reading the process's own
     * standard input.
     *
     * @return resource The stream to read the key from
     */
    protected function apiKeyStream()
    {
        // Read the key from standard input
        return \STDIN;
    }

    /**
     * Tell the operator about content the API will not serve.
     *
     * This is a warning rather than a refusal, and the choice is deliberate. The failure
     * being guarded is *silence*, not proceeding: an operator who knows a plugin is inactive
     * on the source site may legitimately want the rest of it, and refusing would block a
     * migration that is doing exactly what was asked. Saying so loudly removes the defect;
     * stopping would only trade it for a different one.
     *
     * It matches how wordpress-importer-archive#658 reports form references the supplied export cannot satisfy.
     *
     * @param  list<array{kind: string, name: string, records: int}>  $unserved  Stranded content
     */
    private function reportUnservedContent(
        array $unserved
    ): void {
        if ($unserved === []) {
            return;
        }

        $total = \array_sum(\array_column($unserved, 'records'));

        $this->warn(\sprintf(
            '%d record(s) on this site will NOT be imported: the API does not serve them.',
            $total
        ));

        foreach ($unserved as $entry) {
            $this->warn(\sprintf(
                '      %-14s %-24s %d record(s)',
                $entry['kind'],
                $entry['name'],
                $entry['records']
            ));
        }

        // Naming the likely cause matters: "not exposed" alone sends someone to the API's
        // configuration, and the usual reason is a plugin that is not active on the site
        // being read, which is somewhere else entirely.
        $this->warn(
            '      Usually this means the plugin registering that type is not active on the '
            .'site being read. See docs/migration-api-contract.md.'
        );
    }

    /**
     * Report what each redirect plugin on a vanity domain served (wordpress-importer-archive#1307).
     *
     * One line per plugin with the number of rules it serves and the number written as
     * `redirect_rule` items, which is the count a domain's imported entries are checked
     * against. Then every behavior the redirect domains collection cannot express, with the
     * number of rules carrying it, including the reason a rule was not written at all.
     *
     * @param  list<array{host: string, plugin: string, rules: int, written: int, unsupported: array<string, int>}>  $plugins  What each plugin served
     */
    private function reportRedirectPluginRules(
        array $plugins
    ): void {
        foreach ($plugins as $plugin) {
            $this->info(\sprintf(
                '%s on %s: %d rule(s) served, %d written as redirect rules.',
                $plugin['plugin'],
                $plugin['host'],
                $plugin['rules'],
                $plugin['written']
            ));

            if ($plugin['unsupported'] === []) {
                continue;
            }

            $this->warn('      The redirect domains collection cannot express:');

            foreach ($plugin['unsupported'] as $feature => $rules) {
                $this->warn(\sprintf('      %-60s %d rule(s)', $feature, $rules));
            }
        }
    }

    /**
     * Run the import for an already-resolved part-set.
     *
     * Both sources converge here: a local WXR export supplies its own parts, and the
     * migration API supplies parts materialized from it. Everything downstream reads real
     * files, which is why no importer subsystem had to change (wordpress-importer-archive#587).
     *
     * Protected so a test can stand in for the import itself while it covers what
     * leads to it.
     *
     * @param  list<string>  $parts  Ordered part paths (one entry for a single-file export)
     * @return int Exit code (0 for success, 1 for failure)
     */
    protected function runImportForParts(
        array $parts
    ): int {
        // Resolve the non-interactive policy for unsupported-content gates
        $unsupportedContentPolicy = $this->resolveUnsupportedContentPolicy();

        // Check if the supplied policy value is invalid
        if ($unsupportedContentPolicy === null) {
            // Show error for invalid option value
            $this->error('The --unsupported-content option must be one of: abort, skip, import.');

            // Exit with error status code
            return 1;
        }

        // Notify user that import is starting. Naming the first part rather than the file
        // argument keeps this true for both sources — the argument is an output directory
        // when the content came from the API.
        $this->info("Starting WordPress import from: {$parts[0]}");

        // Clear deferred processing data from previous imports
        LinkProcessor::clearDeferredData();

        // ---
        // Measure initial filesize of the entire application directory (when enabled)
        // ---

        // An if reads the setting as a boolean, so the cast only types it
        // @pest-mutate-ignore: RemoveBooleanCast
        if (
            (bool) config(
                'wordpress-import.measure_filesize',
                true
            )
        ) {
            // Display informational message to user about filesize measurement
            $this->info('Measuring initial filesize of the codebase...');

            // Get the absolute path to the application root directory for filesize calculation
            $applicationDirectory = base_path();

            // Calculate and store the initial filesize of the application directory
            $this->initialFilesize = $this->calculateDirectoryFilesize($applicationDirectory);

            // Format the initial filesize for display in multiple units
            $initialFormatted = $this->formatFilesize($this->initialFilesize);

            // Display the initial filesize to the user in megabytes
            $this->info("Initial application directory filesize: {$initialFormatted['mb']} MB");

            // Clean up variables to free up memory
            unset(
                $initialFormatted,
                $applicationDirectory
            );
        }

        // Clear all import warnings at the start of import
        ImportWarningManager::clearWarnings();

        // Clear failed asset URLs from any previous import sessions
        FailedAssetCollector::clearFailedUrls();

        // Clear processed terms cache for a fresh import session
        BaseTaxonomyValidator::clearCaches();

        // Clear taxonomy term caches for a fresh import session
        BaseEntryCreator::clearTaxonomyCaches();

        // Check memory requirements across every part and prompt user if needed. The check
        // is the first step to read the parts, so a part the reader cannot open or parse
        // fails here, as the reader's warning raised as an `ErrorException`, and is reported
        // the way the parse below reports it (#289). Only that exception is caught, so an
        // aborted prompt still ends the run as it did.
        try {
            $this->checkMemoryRequirements($parts);
        } catch (\ErrorException $errorException) {
            // Show error for the part the reader could not read
            $this->error("Failed to parse XML file: {$errorException->getMessage()}");

            // Exit with error status code
            return 1;
        }

        // Parse the XML part(s)
        try {
            // Parse XML part(s) using streaming parser
            $this->parseXmlFile($parts);
        } catch (\Exception $exception) {
            // Show error for failed XML parsing
            $this->error("Failed to parse XML file: {$exception->getMessage()}");

            // Exit with error status code
            return 1;
        }

        // Handle form plugin detection and user interaction
        $this->formPluginCoordinator->handleFormPluginDetection(
            $this->xmlParser,
            $this->input,
            $this->output,
            $this
        );

        // ---
        // Display content types found in XML
        // ---

        // Notify user that content types are being displayed
        $this->info('Content types found in the XML:');

        // Get all content types from the XML parser
        $allContentTypes = $this->xmlParser->getContentTypes();

        // Display content types in a table format
        $this->table(
            [
                'Content Type',
                'Count',
            ],
            $allContentTypes
        );

        // Initialize MinervaKB settings variable for later use when processing kb content
        $minervaKbSettings = null;

        // Extract content type names from the content types array for checking
        $xmlTypes = array_column(
            $allContentTypes,
            0
        );

        // Check if kb content type exists in the XML export. Content type names are
        // strings, so a loose comparison finds the same ones.
        // @pest-mutate-ignore: TrueToFalse
        if (
            \in_array(
                'kb',
                $xmlTypes,
                true
            )
        ) {
            // Prompt user for MinervaKB settings export file to map configuration values
            $minervaKbSettings = $this->minervaKbSettingsSelector->select(
                $this->input,
                $this->output,
                $this
            );
        }

        // ---
        // Check for supported content types before proceeding
        // ---

        // Filter content types to only include supported ones, comparing names as
        // strings either way
        // @pest-mutate-ignore: TrueToFalse
        $supportedContentTypes = \array_filter(
            $allContentTypes,
            fn (array $type): bool => \in_array(
                $type[0],
                $this->supportedContentTypes,
                true
            )
        );

        // Check if no supported content types were found
        if ($supportedContentTypes === []) {
            // Show error for missing supported content types
            $this->error('No supported content types (page, post, uamspeople, redirect_rule, tablepress_table, kb) found in the XML.');

            // Exit with error status code
            return 1;
        }

        // ---
        // Resolve --content-type, the unattended caller's way of naming what to import
        // ---

        // The handles actually present in this export, for validation and for the
        // untouched-types report at the end.
        // `nav_menu_item` is deliberately absent: `ContentTypeSelector` filters it out of
        // the selectable options because menu items ride along with the navigation pass
        // rather than being imported as a content type of their own. Including it here
        // would advertise a handle `--content-type=` cannot honour, and would list it as
        // "not imported" forever — telling a caller to re-run with a flag that fails.
        $availableContentTypes = \array_filter(
            \array_map(
                fn (array $type): string => $type[0],
                $supportedContentTypes
            ),
            fn (string $handle): bool => $handle !== 'nav_menu_item'
        );

        $requestedContentType = $this->option('content-type');
        $requestedContentType = \is_string($requestedContentType) && \trim($requestedContentType) !== ''
            ? \trim($requestedContentType)
            : null;

        // Refuse an unknown handle rather than silently importing something else. A typo
        // that fell through to the default selector would import the alphabetically first
        // type and report success, which is the defect this option exists to remove (wordpress-importer-archive#706).
        // Handles are strings, so a loose comparison refuses the same ones.
        // @pest-mutate-ignore: TrueToFalse
        if (
            $requestedContentType !== null
            && ! \in_array($requestedContentType, $availableContentTypes, true)
        ) {
            $this->error(
                \sprintf(
                    "'%s' is not a supported content type present in this export.",
                    $requestedContentType
                )
            );

            $this->info('Available: '.\implode(', ', $availableContentTypes).'.');

            // Exit with error status code
            return 1;
        }

        // ---
        // List unsupported content types found in the XML
        // ---

        // Extract content type names from the content types array
        $xmlTypes = array_column(
            $allContentTypes,
            0
        );

        // Find content types that are not in the supported list
        $unsupportedTypes = array_diff(
            $xmlTypes,
            $this->supportedContentTypes
        );

        // Check if unsupported types were found
        if ($unsupportedTypes !== []) {
            // Inform user about unsupported content types
            $this->comment('Unsupported content types: '.\implode(
                ', ',
                $unsupportedTypes
            ));

        } else { // Otherwise
            // Inform user that all content types are supported
            $this->comment('All content types in XML are supported.');
        }

        // ---
        // Select site
        // ---

        // Prompt user to select target site for import
        $site = $this->siteSelector->select(
            $this->input,
            $this->output,
            $this
        );

        // Store selected site in resolver for later use
        SiteResolver::setSelectedSite($site);

        // Check if the selected site is valid
        if (! $this->siteSelector->validate($site)) {
            // Show error for invalid site
            $this->error("The site '$site' does not exist in the configuration.");

            // Exit with error status code
            return 1;
        }

        // Confirm to user that the selected site is valid
        $this->info("Selected site '$site' is valid.");

        // Apply this site's Departmental News Hub designation (wordpress-importer-archive#768). Runs once per
        // site, before the content-type loop, because the designation is a property
        // of the site rather than of anything being imported into it.
        $this->designateDepartmentOrganization($site);

        // Get base blog URL from XML parser for asset URL processing
        $baseBlogUrl = $this->xmlParser->getBaseBlogUrl();

        // Get upload images configuration setting. Only the || below reads it, which
        // yields a boolean either way, so the cast only types it.
        // @pest-mutate-ignore: RemoveBooleanCast
        $uploadImages = (bool) config(
            'wordpress-import.upload_images',
            true
        );

        // Get upload files configuration setting, read the same way
        // @pest-mutate-ignore: RemoveBooleanCast
        $uploadFiles = (bool) config(
            'wordpress-import.upload_files',
            true
        );

        // Determine if asset uploads are enabled
        $assetsEnabled = $uploadImages || $uploadFiles;

        // Set the base blog URL on the AssetImporter for site-specific asset filtering
        $this->assetImporter->setBaseBlogUrl($baseBlogUrl);

        // Track content types already handled this run. In non-interactive mode the
        // selector deterministically returns the first option every iteration, so a
        // content type skipped at a gate (or at any other `continue` in the loop body)
        // would otherwise be re-selected and re-skipped forever; recording handled types
        // and excluding them lets the loop advance to the next type or terminate (wordpress-importer-archive#389).
        // Content types this run actually selected. Reported at the end so a run that
        // imported one type of seven cannot pass for a complete one (wordpress-importer-archive#706).
        /** @var list<string> $importedContentTypes */
        $importedContentTypes = [];

        // Content types abandoned at a destination gate. Tracked because a gate skip
        // discards *every* item of its type, and the command used to report that as a
        // clean success — a 39-page import that wrote nothing exited 0 (wordpress-importer-archive#704).
        /** @var list<string> $skippedAtGate */
        $skippedAtGate = [];

        // ---
        // Loop to process multiple content types. Each pass selects a type not yet
        // handled, so a run makes at most one pass per supported type; iterating the
        // supported types bounds the loop there, keeping it finite whatever its body does.
        // The empty-pool check below still ends a run that has handled every type before
        // its passes are spent.
        // ---
        foreach ($supportedContentTypes as $unused) {
            // Narrow the pool to types not yet handled this run, and to the requested
            // type when one was named. `nav_menu_item` is excluded by
            // `ContentTypeSelector` itself, so that filter is not duplicated here.
            //
            // Excluding handled types is wordpress-importer-archive#389's guard and it is load-bearing, not
            // bookkeeping: a `continue` from any skip gate below re-enters this loop
            // *before* `$continue` is ever assigned, so the deterministic selector
            // would return the same type forever. wordpress-importer-archive#707 removed it on the reasoning
            // that the continuation prompt ends the loop; the prompt is never reached
            // on that path. Restored with the empty-pool `break`, which is what
            // actually terminates the run (wordpress-importer-archive#711).
            // Handled types are names, compared as strings either way
            // @pest-mutate-ignore: TrueToFalse
            $selectableContentTypes = \array_values(
                \array_filter(
                    $supportedContentTypes,
                    fn (array $type): bool => ! \in_array($type[0], $importedContentTypes, true)
                        && ($requestedContentType === null || $type[0] === $requestedContentType)
                )
            );

            // Nothing left to select — every candidate has been handled, so end the
            // loop rather than re-offering a type the selector would return again.
            // The pass bound would end it as well, each remaining pass finding the same
            // empty pool, so continuing instead changes nothing a run can observe.
            if ($selectableContentTypes === []) {
                // @pest-mutate-ignore: BreakToContinue
                break;
            }

            // Select content type
            $contentType = $this->contentTypeSelector->select(
                $selectableContentTypes,
                $this->input,
                $this->output,
                $this
            );

            $importedContentTypes[] = $contentType;

            // Confirm selected content type to user
            $this->info("Selected content type: '$contentType'");

            // ---
            // Skip navigation menu items during main import loop. Navigation import will be
            // handled after all content types are imported to ensure all entries are fully
            // processed and cached before navigation import.
            // ---

            // Check if content type is navigation menu item
            if ($contentType === 'nav_menu_item') {
                // Inform user that navigation import is deferred until after content import
                $this->info('Navigation menu items will be imported after all content types are processed.');

                // Skip to the import completion prompt without processing navigation items
                goto import_complete;
            }

            // Scan for mixed Classic and Gutenberg block content
            $classicPages = $this->scanForClassicBlocks($contentType);

            // Check if Classic block pages were found
            if ($classicPages !== []) {
                // Warn user about detected Classic block content
                $this->warn("Mixed Classic and Gutenberg block content detected in '$contentType' content:");

                // Loop through Classic block pages to display details
                foreach ($classicPages as $page) {
                    // Display page details to user
                    $this->line(" - Title: '{$page['title']}', ID: {$page['id']}, Permalink: {$page['link']}");
                }

                // Warn user about mapping limitations and provide guidance
                $this->warn('Mixed Classic and Gutenberg block content is not currently mapped to the Statamic Bard format. To import it correctly, edit the original page(s) and choose the "Convert to blocks" option. Once the affected pages have been updated, re-run the WordPress export tool and then restart this importer with the new XML file.');

                // Resolve the policy: prompt when interactive, otherwise honor --unsupported-content
                $decision = $this->resolveUnsupportedContent(
                    "Would you like to continue importing '$contentType' content?",
                    $unsupportedContentPolicy,
                    $this->input,
                    $this->output,
                    $this
                );

                // Check if the run should abort on unsupported content
                if ($decision === 'abort') {
                    // Fail the run so unattended imports cannot silently drop content
                    $this->error("Aborting import of '$contentType' due to Classic block presence (see affected pages above).");

                    // Exit with error status code
                    return 1;
                }

                // Check if the content type should be skipped
                if ($decision !== 'import') {
                    // Inform user that import is being skipped
                    $this->info("Skipping import of '$contentType' due to Classic block presence.");

                    // Skip to next iteration of loop
                    continue;
                }

                // Pass Classic block pages to EntryCreator for note addition
                $this->entryCreator->setClassicBlockPages($classicPages); // Pass to EntryCreator for note addition
            }

            // Scan for Freeform blocks
            $freeformPages = $this->scanForFreeformBlocks($contentType);

            // Check if Freeform block pages were found
            if ($freeformPages !== []) {
                // Warn user about detected Freeform block content
                $this->warn("Freeform block content detected in '$contentType' content:");

                // Loop through Freeform block pages to display details
                foreach ($freeformPages as $page) {
                    // Display page details to user
                    $this->line(" - Title: '{$page['title']}', ID: {$page['id']}, Permalink: {$page['link']}");
                }

                // Warn user about mapping limitations and provide guidance
                $this->warn('Freeform blocks are not currently mapped to the Statamic Bard format. To import it correctly, edit the original page(s) and choose the "Convert to blocks" option. Once the affected pages have been updated, re-run the WordPress export tool and then restart this importer with the new XML file.');

                // Resolve the policy: prompt when interactive, otherwise honor --unsupported-content
                $decision = $this->resolveUnsupportedContent(
                    "Would you like to continue importing '$contentType' content?",
                    $unsupportedContentPolicy,
                    $this->input,
                    $this->output,
                    $this
                );

                // Check if the run should abort on unsupported content
                if ($decision === 'abort') {
                    // Fail the run so unattended imports cannot silently drop content
                    $this->error("Aborting import of '$contentType' due to Freeform block presence (see affected pages above).");

                    // Exit with error status code
                    return 1;
                }

                // Check if the content type should be skipped
                if ($decision !== 'import') {
                    // Inform user that import is being skipped
                    $this->info("Skipping import of '$contentType' due to Freeform block presence.");

                    // Skip to next iteration of loop
                    continue;
                }

                // Pass to EntryCreator for note addition
                $this->entryCreator->setFreeformBlockPages($freeformPages);
            }

            // Scan for Details blocks
            $detailsPages = $this->scanForDetailsBlocks($contentType);

            // Check if Details block pages were found
            if ($detailsPages !== []) {
                // Warn user about detected Details block content
                $this->warn("Details block content detected in '$contentType' content:");

                // Loop through Details block pages to display details
                foreach ($detailsPages as $page) {
                    // Display page details to user
                    $this->line(" - Title: '{$page['title']}', ID: {$page['id']}, Permalink: {$page['link']}");
                }

                // Warn user about mapping limitations and provide guidance
                $this->warn('Details blocks are not currently mapped to the Statamic Bard format. To import it correctly, edit the original page(s) and choose the "Convert to blocks" option. Once the affected pages have been updated, re-run the WordPress export tool and then restart this importer with the new XML file.');

                // Resolve the policy: prompt when interactive, otherwise honor --unsupported-content
                $decision = $this->resolveUnsupportedContent(
                    "Would you like to continue importing '$contentType' content?",
                    $unsupportedContentPolicy,
                    $this->input,
                    $this->output,
                    $this
                );

                // Check if the run should abort on unsupported content
                if ($decision === 'abort') {
                    // Fail the run so unattended imports cannot silently drop content
                    $this->error("Aborting import of '$contentType' due to Details block presence (see affected pages above).");

                    // Exit with error status code
                    return 1;
                }

                // Check if the content type should be skipped
                if ($decision !== 'import') {
                    // Inform user that import is being skipped
                    $this->info("Skipping import of '$contentType' due to Details block presence.");

                    // Skip to next iteration of loop
                    continue;
                }

                // Pass to EntryCreator for note addition
                $this->entryCreator->setDetailsBlockPages($detailsPages);
            }

            // ---
            // Scan for kb entries without associated topics
            // ---

            // Check if content type is knowledge base
            if ($contentType === 'kb') {
                // Call the method to scan for kb entries missing associated topics
                $kbWithoutTopics = $this->scanForKbWithoutTopics($contentType);

                // Check if any entries without topics were found
                if ($kbWithoutTopics !== []) {
                    // Warn the user about the detected entries without topics
                    $this->warn("Knowledge Base entries without associated topics detected in '$contentType' content:");

                    // ---
                    // List each affected entry with details for user reference
                    // ---

                    // Loop through entries without topics to display details
                    foreach ($kbWithoutTopics as $entry) {
                        // Display entry details to user
                        $this->line(" - Title: '{$entry['title']}', ID: {$entry['id']}, Permalink: {$entry['link']}");
                    }

                    // Provide guidance on resolving the issue before import
                    $this->warn('Knowledge Base entries without associated topics will not be imported. To include these entries, resolve the issue on the source site by assigning topics to all entries, then re-run the WordPress export tool and restart this importer with the new XML file.');

                    // ---
                    // Resolve the policy: prompt when interactive, otherwise honor --unsupported-content
                    // ---
                    $decision = $this->resolveUnsupportedContent(
                        "Would you like to continue importing '$contentType' content despite entries without topics?",
                        $unsupportedContentPolicy,
                        $this->input,
                        $this->output,
                        $this
                    );

                    // ---
                    // Abort, skip, or import the affected entries per the resolved decision
                    // ---

                    // Check if the run should abort on unsupported content
                    if ($decision === 'abort') {
                        // Fail the run so unattended imports cannot silently drop content
                        $this->error("Aborting import of '$contentType' due to entries without associated topics (see affected entries above).");

                        // Exit with error status code
                        return 1;
                    }

                    // Check if the content type should be skipped
                    if ($decision !== 'import') {
                        // Inform user that import is being skipped
                        $this->info("Skipping import of '$contentType' due to entries without associated topics.");

                        // Skip to next iteration of loop
                        continue;
                    }

                    // Pass the list of affected entries to EntryCreator for potential note addition
                    $this->entryCreator->setKbWithoutTopics($kbWithoutTopics);
                }
            }

            // Check for default mapping
            if (isset($this->defaultMappings[$contentType])) {
                // Get default mapping configuration for this content type
                $default = $this->defaultMappings[$contentType];

                // Extract default handle from mapping
                $defaultHandle = $default['handle'];

                // Extract default type from mapping
                $defaultType = $default['type'];

                // Set destination from default mapping
                $destination = ['type' => $defaultType, 'handle' => $defaultHandle];

                // Check if default type is collection
                if ($defaultType === 'collection') {
                    // Find collection by handle
                    $collection = Collection::findByHandle($defaultHandle);

                    // Check if collection does not exist
                    if ($collection === null) {
                        // Warn user that collection is missing
                        $this->warn("The '$defaultHandle' collection does not exist in Statamic.");

                        // Inform user that collection needs to be created
                        $this->info("Please create and configure the '$defaultHandle' collection before importing '$contentType' content.");

                        // Record the abandonment so the exit code reflects it (#291)
                        $skippedAtGate[] = "$contentType (needs the '$defaultHandle' collection)";

                        // Skip to next iteration of loop
                        continue;
                    }

                    // Check if collection is not enabled for the site
                    if (
                        ! $this->isCollectionEnabledForSite(
                            $site,
                            $defaultHandle
                        )
                    ) {
                        // Enable collection for the site
                        $this->enableCollectionForSite(
                            $site,
                            $defaultHandle
                        );

                        // Confirm to user that collection was enabled
                        $this->info("Enabled '$site' for collection '$defaultHandle'.");
                    }
                } elseif ($defaultType === 'global') { // Check if default type is global
                    // Find global set by handle
                    $global = GlobalSet::findByHandle($defaultHandle);

                    // Check if global set does not exist
                    if ($global === null) {
                        // Warn user that global set is missing
                        $this->warn("The '$defaultHandle' global set does not exist in Statamic.");

                        // Inform user that global set needs to be created
                        $this->info("Please create and configure the '$defaultHandle' global set before importing '$contentType' content.");

                        // Record the abandonment so the exit code reflects it (#291)
                        $skippedAtGate[] = "$contentType (needs the '$defaultHandle' global set)";

                        // Skip to next iteration of loop
                        continue;
                    }

                    // Check if global set is not enabled for the site
                    if (
                        ! $this->isGlobalEnabledForSite(
                            $site,
                            $defaultHandle
                        )
                    ) {
                        // Enable global set for the site
                        $this->enableGlobalForSite(
                            $site,
                            $defaultHandle
                        );

                        // Confirm to user that global set was enabled
                        $this->info("Enabled '$site' for global set '$defaultHandle'.");
                    }
                }
            } else {
                // Report a content type with no default mapping, and end the run (#213)
                $this->error("'$contentType' content has no default mapping to a Statamic collection or global set.");

                // Exit with error status code
                return 1;
            }

            $destinationType = $destination['type'];
            $destinationHandle = $destination['handle'];

            // Check and enable relevant taxonomies
            if (
                $destinationType === 'collection'
                && $contentType === 'page'
            ) {
                // Check logos_item_categories only if wp:acf/logo-list blocks are present
                if (
                    $this->hasAcfBlockType(
                        $contentType,
                        'logo-list'
                    )
                ) {
                    // Check if taxonomy enabling was successful
                    if (
                        ! $this->enableTaxonomyIfNeeded(
                            $site,
                            'logos_item_categories',
                            $contentType
                        )
                    ) {
                        // Inform user that import is being skipped
                        $this->info("Skipping import of '$contentType' as 'logos_item_categories' is not enabled for '$site'.");

                        // Record the abandonment so the exit code reflects it.
                        $skippedAtGate[] = "$contentType (needs 'logos_item_categories')";

                        // Skip to next iteration of loop
                        continue;
                    }
                }

                // ---
                // Check region only if wp:acf/fad-providers or wp:acf/fad-locations blocks are
                // present
                // ---
                if (
                    $this->hasAcfBlockType(
                        $contentType,
                        'fad-providers'
                    )
                    || $this->hasAcfBlockType(
                        $contentType,
                        'fad-locations'
                    )
                ) {
                    // Check if taxonomy enabling was successful
                    if (
                        ! $this->enableTaxonomyIfNeeded(
                            $site,
                            'regions',
                            $contentType
                        )
                    ) {
                        // Inform user that import is being skipped
                        $this->info("Skipping import of '$contentType' as 'regions' is not enabled for '$site'.");

                        // Record the abandonment so the exit code reflects it.
                        $skippedAtGate[] = "$contentType (needs 'regions')";

                        // Skip to next iteration of loop
                        continue;
                    }
                }

                // Check location_type only if wp:acf/fad-locations blocks are present
                if (
                    $this->hasAcfBlockType(
                        $contentType,
                        'fad-locations'
                    )
                ) {
                    // Check if taxonomy enabling was successful
                    if (
                        ! $this->enableTaxonomyIfNeeded(
                            $site,
                            'location_types',
                            $contentType
                        )
                    ) {
                        // Inform user that import is being skipped
                        $this->info("Skipping import of '$contentType' as 'location_types' is not enabled for '$site'.");

                        // Record the abandonment so the exit code reflects it.
                        $skippedAtGate[] = "$contentType (needs 'location_types')";

                        // Skip to next iteration of loop
                        continue;
                    }
                }
            }

            // Check people_groups for uamspeople, tshowcase, or pages with show-people shortcodes
            if (
                $destinationType === 'collection'
                && (
                    $contentType === 'uamspeople'
                    || $contentType === 'tshowcase'
                    || (
                        $contentType === 'page'
                        && $this->entryCreator->hasShowPeople($contentType)
                    )
                )
            ) {
                // Check if taxonomy enabling was successful
                if (
                    ! $this->enableTaxonomyIfNeeded(
                        $site,
                        'people_groups',
                        $contentType
                    )
                ) {
                    // Inform user that import is being skipped
                    $this->info("Skipping import of '$contentType' as 'people_groups' is not enabled for '$site'.");

                    // Record the abandonment so the exit code reflects it.
                    $skippedAtGate[] = "$contentType (needs 'people_groups')";

                    // Skip to next iteration of loop
                    continue;
                }
            }

            // Check classified_categories for WP Adverts listings
            if (
                $destinationType === 'collection'
                && $contentType === 'advert'
            ) {
                // Check if taxonomy enabling was successful
                if (
                    ! $this->enableTaxonomyIfNeeded(
                        $site,
                        'classified_categories',
                        $contentType
                    )
                ) {
                    // Inform user that import is being skipped
                    $this->info("Skipping import of '$contentType' as 'classified_categories' is not enabled for '$site'.");

                    // Record the abandonment so the exit code reflects it.
                    $skippedAtGate[] = "$contentType (needs 'classified_categories')";

                    // Skip to next iteration of loop
                    continue;
                }
            }

            // ---
            // Handle collection-specific imports
            // ---

            // Check if destination type is collection
            if ($destinationType === 'collection') {
                // ---
                // Prompt for entry overwrite behavior
                // ---

                // Define options for entry overwrite behavior
                $entryOptions = [
                    'Overwrite matching files',
                    'Skip matching files',
                    'Create an additional file',
                ];

                // Prompt user to select entry overwrite behavior
                $entryOverwriteChoice = $this->isPromptsSupported() && $this->input->isInteractive()
                    ? select(
                        label: "How should existing entries in the '{$destinationHandle}' collection with matching IDs be handled?",
                        options: $entryOptions,
                        default: $entryOptions[0]
                    )
                    : $this->choice(
                        "How should existing entries in the '{$destinationHandle}' collection with matching IDs be handled?",
                        $entryOptions,
                        0
                    );

                // Set entry overwrite behavior on EntryCreator. Both prompts answer one of the options,
                // a string, so the assertion only types it.
                // @pest-mutate-ignore: RemoveFunctionCall
                \assert(is_string($entryOverwriteChoice));
                $this->entryCreator->setEntryOverwriteBehavior($entryOverwriteChoice);

                // Prompt for taxonomy overwrite behavior
                $taxonomyOverwriteChoice = $this->taxonomyOverwriteBehaviorSelector->select(
                    $this->input,
                    $this->output,
                    $this
                );

                // Set the taxonomy overwrite behavior for the entry creator
                $this->entryCreator->setTaxonomyOverwriteBehavior($taxonomyOverwriteChoice);

                // ---
                // Prompt for asset overwrite behavior
                // ---

                // Check if asset uploads are enabled
                if ($assetsEnabled) {
                    // Define options for asset overwrite behavior
                    $assetOptions = [
                        'Overwrite matching files',
                        'Skip matching files',
                        'Create an additional file',
                    ];

                    // Prompt user to select asset overwrite behavior
                    $assetOverwriteChoice = $this->isPromptsSupported() && $this->input->isInteractive()
                        ? select(
                            label: 'How should existing assets in the relevant container be handled?',
                            options: $assetOptions,
                            default: $assetOptions[0]
                        )
                        : $this->choice(
                            'How should existing assets in the relevant container be handled?',
                            $assetOptions,
                            0
                        );

                    // Set asset overwrite behavior on AssetImporter. Both prompts answer one of the options,
                    // a string, so the assertion only types it.
                    // @pest-mutate-ignore: RemoveFunctionCall
                    \assert(is_string($assetOverwriteChoice));
                    $this->assetImporter->setAssetOverwriteBehavior($assetOverwriteChoice);
                } else { // Otherwise
                    // Inform user that asset uploads are disabled
                    $this->info('Asset uploads disabled (images and files off); skipping overwrite prompt.');

                    // Set default asset overwrite behavior to skip
                    $this->assetImporter->setAssetOverwriteBehavior('Skip matching files');
                }

                // ---
                // Prompt for table overwrite behavior if TablePress content is detected
                // ---

                // Check if TablePress content is present in this content type
                if ($this->hasTablePressContent($contentType)) {
                    // Define options for table overwrite behavior
                    $tableOptions = [
                        'Overwrite matching files',
                        'Skip matching files',
                        'Create an additional file',
                    ];

                    // Prompt user to select table overwrite behavior
                    $tableOverwriteChoice = $this->isPromptsSupported() && $this->input->isInteractive()
                        ? select(
                            label: 'How should existing table entries with matching IDs be handled?',
                            options: $tableOptions,
                            default: $tableOptions[0]
                        )
                        : $this->choice(
                            "How should existing entries in the 'tables' collection with matching IDs be handled?",
                            $tableOptions,
                            0
                        );

                    // Set table overwrite behavior on EntryCreator. Both prompts answer one of the options,
                    // a string, so the assertion only types it.
                    // @pest-mutate-ignore: RemoveFunctionCall
                    \assert(is_string($tableOverwriteChoice));
                    $this->entryCreator->setTableOverwriteBehavior($tableOverwriteChoice);
                }

                // ---
                // Set dependencies and create entries
                // ---

                // Set XML parser on EntryCreator
                $this->entryCreator->setXmlParser($this->xmlParser);

                // Set asset importer on EntryCreator
                $this->entryCreator->setAssetImporter($this->assetImporter);

                // Check if content type is not knowledge base
                if ($contentType !== 'kb') {
                    // Create entries for this content type
                    $this->entryCreator->createEntries(
                        $site,
                        $contentType,
                        $destinationHandle,
                        $this->xmlParser,
                        $this->assetImporter,
                        $this->output,
                        $baseBlogUrl,
                        $minervaKbSettings
                    );
                }

                // ---
                // Validate taxonomy terms after entry creation
                // ---

                // Check if content type is page
                if ($contentType === 'page') {
                    // Check if logo-list ACF blocks are present
                    if (
                        $this->hasAcfBlockType(
                            $contentType,
                            'logo-list'
                        )
                    ) {
                        // ---
                        // Validate logos_item_categories terms
                        // ---

                        // Create validator instance for logos item categories
                        $categoriesValidator = new LogosItemCategoriesValidator;

                        // Validate logos item categories terms
                        $categoriesValidator->validateTerms(
                            LogoListParser::getTermsUsed()['logos_item_categories'] ?? [],
                            $destinationHandle,
                            $site,
                            $this->output
                        );

                        // ---
                        // Labels are not validated: `logos_item_labels` is a
                        // site-scoped collection whose entries the term pass
                        // creates, and the block records only resolved entry
                        // ids (wordpress-importer-archive#1220).
                        // ---
                    }

                    // Note: Region and LocationType validators not yet implemented
                    // These will need to be created as specific validators in the future

                    // Check if fad-providers or fad-locations ACF blocks are present
                    if (
                        $this->hasAcfBlockType(
                            $contentType,
                            'fad-providers'
                        )
                        || $this->hasAcfBlockType(
                            $contentType,
                            'fad-locations'
                        )
                    ) {
                        // Warn user that Region taxonomy validation is not yet implemented
                        $this->output->writeln('<comment>Warning: Region taxonomy validation not yet implemented with new validator system.</comment>');
                    }

                    // Check if fad-locations ACF blocks are present
                    if (
                        $this->hasAcfBlockType(
                            $contentType,
                            'fad-locations'
                        )
                    ) {
                        // Warn user that LocationType taxonomy validation is not yet implemented
                        $this->output->writeln('<comment>Warning: LocationType taxonomy validation not yet implemented with new validator system.</comment>');
                    }
                } elseif ($contentType === 'post') { // Posts reference no taxonomy terms left to validate
                    // ---
                    // Post CATEGORIES are not validated here. `post_categories`
                    // stopped being a taxonomy in `UAMS-Web/uams-statamic-archive#1433`
                    // and is now a structured collection, so the validator that
                    // ran here scanned an absent `content/taxonomies/post_categories`,
                    // judged every category missing, and tried to create terms in
                    // a taxonomy that does not exist (wordpress-importer-archive#422). The entries the field
                    // references are created by the term pass instead.
                    // ---

                    // ---
                    // Post TAGS are not validated either, for the same reason:
                    // `UAMS-Web/uams-statamic-archive#2532` made `post_tags` a
                    // site-scoped structured collection, and the term pass
                    // creates the entries a post references (wordpress-importer-archive#1220).
                    // ---
                } elseif ($contentType === 'kb') { // Process knowledge base content with specialized KbEntryCreator
                    // Set taxonomy overwrite behavior for KbEntryCreator
                    $this->kbEntryCreator->setTaxonomyOverwriteBehavior($taxonomyOverwriteChoice);

                    // Process KbEntry content
                    $this->kbEntryCreator->process(
                        $this->xmlParser,
                        $this->assetImporter,
                        $this->output,
                        $baseBlogUrl,
                        $site,
                        $this->entryCreator->getSiteManager(),
                        $this->collectionStructureManager,
                        $minervaKbSettings
                    );
                }

                // ---
                // Only build tree for non-KbEntry content types
                // ---

                // Check if content type is not knowledge base
                if ($contentType !== 'kb') {
                    // Build and save collection tree structure
                    $this->treeBuilder->buildAndSaveTree(
                        $site,
                        $contentType,
                        $destinationHandle,
                        $this->entryCreator->getPageData(),
                        $this->output,
                        $baseBlogUrl
                    );
                }
            } elseif ($destinationType === 'global') { // Check if destination type is global
                // ---
                // Prompt for redirect overwrite behavior
                // ---

                // Check if content type is redirect rule
                if ($contentType === 'redirect_rule') {
                    // Define options for redirect overwrite behavior
                    $redirectOptions = [
                        'Overwrite matching sets',
                        'Skip matching sets',
                        'Create an additional set',
                    ];

                    // Prompt user to select redirect overwrite behavior
                    $redirectOverwriteChoice = $this->isPromptsSupported() && $this->input->isInteractive()
                        ? select(
                            label: 'How should existing redirect sets with matching source URLs be handled?',
                            options: $redirectOptions,
                            default: $redirectOptions[0]
                        )
                        : $this->choice(
                            'How should existing redirect sets with matching source URLs be handled?',
                            $redirectOptions,
                            0
                        );

                    // Set redirect overwrite behavior on GlobalCreator. Both prompts answer one of the options,
                    // a string, so the assertion only types it.
                    // @pest-mutate-ignore: RemoveFunctionCall
                    \assert(is_string($redirectOverwriteChoice));
                    $this->globalCreator->setGlobalOverwriteBehavior($redirectOverwriteChoice);
                }

                // Create global sets for this content type
                $this->globalCreator->createGlobals(
                    $site,
                    $contentType,
                    $destinationHandle,
                    $this->xmlParser,
                    $this->assetImporter,
                    $this->output
                );
            } else { // Otherwise
                // Inform user that taxonomy import is not yet implemented
                $this->info('Taxonomy import not implemented yet. Stopping this cycle.');
            }

            // ---
            // Reset term usage for next cycle
            // ---

            // Reset terms used by PeopleShortcodeProcessor
            PeopleShortcodeProcessor::resetTermsUsed();

            // Reset terms used by LogoListParser
            LogoListParser::resetTermsUsed();

            // Reset terms used by FindADocProvidersParser
            FindADocProvidersParser::resetTermsUsed();

            // Reset terms used by FindADocLocationsParser
            FindADocLocationsParser::resetTermsUsed();

            // Clear any deferred taxonomy terms queued during this cycle. The flush
            // before validation already drains the queue per site; this guards
            // against leakage across content-type cycles and test runs.
            BaseEntryCreator::clearDeferredTermQueue();

            // ---
            // Display failed asset downloads for this content type
            // ---

            // Check if there were any failed asset downloads
            if (FailedAssetCollector::hasFailedDownloads()) {
                // Add blank line for readability
                $this->line('');

                // Display formatted list of failed asset URLs
                $this->warn(FailedAssetCollector::formatFailedUrlsForDisplay());

                // Add blank line for readability
                $this->line('');
            }

            // Clear failed assets for next cycle
            FailedAssetCollector::clearFailedUrls();

            // Label for import complete
            import_complete:

            // Prompt to import more content
            $continue = $this->isPromptsSupported() && $this->input->isInteractive()
                ? confirm(
                    label: 'Would you like to import more content?',
                    default: false
                )
                : $this->confirm(
                    'Would you like to import more content?',
                    false
                );

            // End the loop when no more content is wanted
            if (! $continue) {
                break;
            }
        }

        // ---
        // Clear and refresh Stache cache before navigation import. This ensures all newly
        // created entries are properly indexed and findable.
        // ---
        Stache::clear();

        // ---
        // Log the cache clearing action to inform the user that the Stache has been
        // refreshed.
        // ---
        $this->info('Stache cache cleared before navigation import.');

        // Handle navigation menu import after all content types are imported
        // This ensures all entries are fully processed and cached before navigation import.
        // A run whose imported types none declare the pass skips it, so a redirect run,
        // scoped or picked interactively, neither reads the menus nor changes the site's
        // navigation setting (#109).
        if (ContentTypeReads::runsPass(ContentTypeReads::PASS_NAVIGATION, $importedContentTypes)) {
            $this->handleNavigationMenuImport();
        } else {
            $this->info('Skipping navigation menu import - no imported content type declares it.');
        }

        // ---
        // Process final deferred operations after all content types are imported
        // ---

        // ---
        // Process any remaining deferred internal links after all content types are
        // imported. This handles cross-post-type links that couldn't be resolved during
        // individual post type imports.
        // ---
        $this->deferredProcessingCoordinator->processFinal(
            $site,
            $this->output
        );

        // ---
        // Measure final filesize and display difference when user is done importing (when enabled)
        // ---

        // An if reads the setting as a boolean, so the cast only types it
        // @pest-mutate-ignore: RemoveBooleanCast
        if (
            (bool) config(
                'wordpress-import.measure_filesize',
                true
            )
        ) {
            // Notify user that final filesize measurement is starting
            $this->info('Measuring final filesize of the codebase...');

            // Get the absolute path to the application root directory
            $applicationDirectory = base_path();

            // Calculate final filesize of the application directory
            $finalFilesize = $this->calculateDirectoryFilesize($applicationDirectory);

            // Display the filesize difference to the user
            $this->displayFilesizeDifference($finalFilesize);

            // Clean up variables to free up memory
            unset(
                $finalFilesize,
                $applicationDirectory
            );
        }

        // Clear the selected site to avoid persistence
        SiteResolver::clearSelectedSite();

        // Clear form creation cache
        $this->formPluginCoordinator->clearAllCaches();

        // A verification run's cache is its own, and is dropped with the workspace
        if (! $this->isVerificationRun()) {
            // Clear Laravel cache
            Artisan::call('cache:clear');

            // Confirm to user that cache was cleared
            $this->info('Laravel cache cleared.');
        }

        // Sanitize Bard content (YAML separators and unquoted nbsp text) in all
        // entries so YAML is formatted properly when the import is completed. The pass
        // rewrites entries whichever run wrote them, so a run whose imported types none
        // declare it, such as a redirect run, leaves them alone (#109).
        if (ContentTypeReads::runsPass(ContentTypeReads::PASS_BARD_SANITIZATION, $importedContentTypes)) {
            $this->runBardContentSanitization();
        } else {
            $this->info('Skipping Bard content sanitization - no imported content type declares it.');
        }

        // Rebuild search indexes once now that all content is imported. Live
        // search-index updates were disabled for the run (see handle()), so this
        // single pass replaces the hundreds of per-save full rebuilds that would
        // otherwise have dominated the import. A verification run skips it: search
        // indexes live outside the workspace, so a rebuild would fill the host's real
        // indexes with content that is about to be deleted (#254). A run whose imported
        // types none declare the pass wrote nothing for reader-facing search, but its
        // redirect domain entries belong in the Control Panel's own index, so only that
        // one is rebuilt (#229).
        if ($this->isVerificationRun()) {
            $this->info('Skipping search index rebuild - a verification run leaves the indexes as they are.');
        } elseif (ContentTypeReads::runsPass(ContentTypeReads::PASS_SEARCH_INDEX, $importedContentTypes)) {
            SearchIndexUpdates::reindexAll($this->output);
        } else {
            $this->info('Rebuilding only the Control Panel search index - no imported content type declares the full rebuild.');
            SearchIndexUpdates::reindexAll($this->output, [SearchIndexUpdates::CP_INDEX]);
        }

        // Remove any dataless entry files this run materialized, before the summary is
        // rendered so the removals appear in it rather than after it (wordpress-importer-archive#1160).
        $this->sweepDatalessEntries();

        // Display all import warnings
        ImportWarningDisplayService::displayAllWarnings($this->output);

        // Notify user that import process is complete
        $this->info('Import process complete.');

        // A run imports one content type. That is the intended unit — the interactive
        // continuation prompt defaults to "no" as well — but an unattended caller that
        // named no type got one picked for it, and used to be told nothing about the
        // rest. Naming them, and failing, is what stops 1-of-7 passing for a whole
        // import (wordpress-importer-archive#706). A caller that *did* name a type asked for exactly this, so it
        // succeeds.
        $untouched = \array_diff(
            $availableContentTypes,
            $importedContentTypes
        );

        if ($requestedContentType === null && $untouched !== []) {
            $this->newLine();
            $this->error('This export contains supported content types that were not imported:');

            foreach ($untouched as $type) {
                $this->line("  - $type");
            }

            $this->info('Re-run with --content-type=<handle> for each, or import them interactively.');

            // Return failure so a partial import cannot be read as a complete one.
            return 1;
        }

        // A gate skip drops every item of its content type, so a run that skipped one
        // imported nothing for it. Reporting that as success is how 39 pages went
        // missing behind an exit 0 (wordpress-importer-archive#704) — name what was dropped and fail.
        if ($skippedAtGate !== []) {
            $this->newLine();
            $this->error('Nothing was imported for the following content types, because a destination was missing:');

            foreach ($skippedAtGate as $skipped) {
                $this->line("  - $skipped");
            }

            $this->warn('Create the missing destinations and re-run; the import is incomplete.');

            // Return failure so a caller, a script or CI cannot read this as a success.
            return 1;
        }

        // Return success status code
        return 0;
    }

    /**
     * Scan the selected content type for mixed Classic and Gutenberg block content.
     *
     * Checks each item's <content:encoded> for the presence of both Gutenberg block
     * comments (<!-- wp:) and freeform/classic content to identify mixed content.
     * Only warns when there's a mix - pure freeform/classic content is handled
     * normally with wpautop. Skips scanning for 'tablepress_table' and 'uamspeople'
     * content types, as their content is not intended for Bard parsing. Returns an
     * array of affected pages with details.
     *
     * @param  string  $contentType  The selected content type (e.g., 'page', 'post')
     * @return list<array{title: string, id: string, link: string}> Pages with mixed Classic and Gutenberg content
     */
    private function scanForClassicBlocks(
        string $contentType
    ): array {
        // Name the pages mixing Gutenberg blocks with freeform content. Content that is
        // all blocks or all freeform imports normally.
        return $this->pagesWhoseContent(
            $contentType,
            fn (string $content): bool => \preg_match(
                '/<!--\s*wp:/',
                $content
            ) === 1
                && $this->hasFreeformContentOutsideBlocks($content)
        );
    }

    /**
     * Check if content contains freeform content outside of Gutenberg blocks.
     *
     * Determines if the content has freeform HTML or text that exists outside of
     * Gutenberg block comments. This includes plain text, HTML tags, or any content
     * that isn't wrapped in <!-- wp: --> comments. Used for detecting mixed content
     * scenarios where Gutenberg blocks and freeform content coexist.
     *
     * @param  string  $content  The content to analyze
     * @return bool True if freeform content outside blocks is present, false otherwise
     */
    private function hasFreeformContentOutsideBlocks(
        string $content
    ): bool {
        // Remove all Gutenberg blocks using the improved method
        $contentWithoutBlocks = $this->removeAllGutenbergBlocks($content);

        // Trim whitespace and check if there's any remaining content
        $remainingContent = \trim($contentWithoutBlocks);

        // Return true if there is remaining content after block removal
        return $remainingContent !== '';
    }

    /**
     * Remove all Gutenberg blocks from content using a comprehensive approach.
     *
     * This method properly handles all types of Gutenberg blocks including:
     *   - Self-closing blocks: <!-- wp:block /-->
     *   - Opening/closing block pairs: <!-- wp:block --> content <!-- /wp:block -->
     *   - Nested blocks with proper matching of open/close tags
     *
     * IMPORTANT: The removal order is critical for proper nested block handling.
     * Opening/closing block pairs must be removed first, then self-closing blocks.
     * Removing self-closing blocks first would change the positions of
     * opening/closing pairs and break the position calculations for nested blocks.
     *
     * @param  string  $content  The content to process
     * @return string Content with all Gutenberg blocks removed
     */
    private function removeAllGutenbergBlocks(
        string $content
    ): string {
        // Handle opening/closing block pairs with proper nesting support first
        $content = $this->removeNestedGutenbergBlocks($content);

        // ---
        // Then remove, in order, all self-closing blocks (e.g., <!-- wp:acf/uams-content
        // {...} /-->), then any remaining opening and closing block comments (malformed or
        // incomplete). preg_replace() answers null only on a matching error, and with PCRE's
        // JIT on, as it is by default, the backtrack limit does not apply, so the fallback
        // only types the result.
        // ---
        $content = \preg_replace(
            [
                '/<!--\s*wp:.*?\/\s*-->/s',
                '/<!--\s*wp:.*?-->/s',
                '/<!--\s*\/wp:.*?-->/s',
            ],
            '',
            $content
        );

        // @pest-mutate-ignore: EmptyStringToNotEmpty
        return $content ?? '';
    }

    /**
     * Remove nested Gutenberg blocks by properly matching open and close comment tags.
     *
     * This method finds all Gutenberg block comment tags, matches opening tags with
     * their corresponding closing tags, and removes the entire block content
     * including any nested blocks within them.
     *
     * @param  string  $content  The content to process
     * @return string Content with nested Gutenberg blocks removed
     */
    private function removeNestedGutenbergBlocks(
        string $content
    ): string {
        // Find all Gutenberg block comment positions. An unmatched opening tag has
        // length 0, so cutting it removes nothing.
        $blockPositions = $this->findGutenbergBlockPositions($content);

        // ---
        // Sort positions by start position (descending) so we can remove from end to
        // beginning
        // ---
        usort(
            $blockPositions,
            fn (array $a, array $b): int => $b['start'] - $a['start']
        );

        // ---
        // Remove blocks from end to beginning to maintain position accuracy
        // ---

        // Loop through block positions to remove each block
        foreach ($blockPositions as $block) {
            // Remove block from content using substr_replace
            $content = substr_replace(
                $content,
                '',
                $block['start'],
                $block['length']
            );
        }

        // Return content with nested Gutenberg blocks removed
        return $content;
    }

    /**
     * Find all Gutenberg block positions in content, properly matching open/close
     * tags.
     *
     * This method identifies all Gutenberg block comment tags and matches opening
     * tags with their corresponding closing tags, handling nested blocks correctly.
     * It returns positions that include the entire block content (comments + HTML).
     *
     * @param  string  $content  The content to analyze
     * @return list<array{start: int, length: int, type: string}> Block positions with start, length, and type
     */
    private function findGutenbergBlockPositions(
        string $content
    ): array {
        // Initialize array to store block positions
        $blockPositions = [];

        // Initialize stack to track opening tags for nested blocks
        $openTagStack = [];

        // Find all Gutenberg block comment tags
        preg_match_all(
            '/<!--\s*(\/?wp:[^\s>]+(?:\s+[^>]*)?)\s*(?:\/)?\s*-->/',
            $content,
            $matches,
            PREG_OFFSET_CAPTURE
        );

        // Loop through all matched block tags
        foreach ($matches[0] as $index => $match) {
            // Extract the full matched comment string
            $fullMatch = $match[0];

            // Extract the position of the match in the content
            $position = $match[1];

            // Extract the block name from the match
            $blockName = $matches[1][$index][0];

            // Check if this is a self-closing tag
            // Self-closing blocks end with " /-->" (space-slash-dash-dash-greater)
            if (
                \preg_match(
                    '/\/\s*-->/',
                    $fullMatch
                ) === 1
            ) {
                // Self-closing block - add to positions immediately
                $blockPositions[] = [
                    'start' => $position,
                    'length' => \strlen($fullMatch),
                    'type' => 'self-closing',
                ];
            } elseif (
                str_contains(
                    $fullMatch,
                    '/wp:'
                )
            ) { // Check if this is a closing tag
                // Closing tag - find matching opening tag. An anchored pattern over a tag
                // name cannot fail, so the fallback only types the result.
                $blockType = \preg_replace(
                    '/^\/wp:/',
                    '',
                    $blockName
                );

                // @pest-mutate-ignore: EmptyStringToNotEmpty
                $blockType = \trim($blockType ?? '');

                // Initialize index for matching opening tag
                $matchingOpenIndex = null;

                // Look for the nearest matching opening tag in the stack
                foreach (\array_reverse($openTagStack, true) as $stackIndex => $openTag) {
                    // Check if this opening tag matches the closing tag type
                    if ($openTag['type'] === $blockType) {
                        // Store index of matching opening tag
                        $matchingOpenIndex = $stackIndex;

                        // Break out of the loop
                        break;
                    }
                }

                // Check if a matching opening tag was found
                if ($matchingOpenIndex !== null) {
                    // Get the matching opening tag from stack
                    $openTag = $openTagStack[$matchingOpenIndex];

                    // Calculate total block length including opening and closing tags
                    $blockLength = $position + \strlen($fullMatch) - $openTag['start'];

                    // Add the complete block to positions (including all content between tags)
                    $blockPositions[] = [
                        'start' => $openTag['start'],
                        'length' => $blockLength,
                        'type' => 'opening-closing',
                    ];

                    // Remove the matched opening tag from stack
                    array_splice(
                        $openTagStack,
                        $matchingOpenIndex,
                        1
                    );
                }
            } else { // Otherwise
                // ---
                // Opening tag - add to stack
                // ---

                // Extract block type by removing wp: prefix. Neither pattern over a tag name
                // can fail, so each fallback only types the result.
                $blockType = \preg_replace(
                    '/^wp:/',
                    '',
                    $blockName
                );

                // Remove attributes
                $blockType = \preg_replace(
                    '/\s+.*$/',
                    '',
                    // @pest-mutate-ignore: EmptyStringToNotEmpty
                    $blockType ?? ''
                );

                // @pest-mutate-ignore: EmptyStringToNotEmpty
                $blockType ??= '';

                // Add opening tag to stack for later matching
                $openTagStack[] = [
                    'start' => $position,
                    'type' => $blockType,
                ];
            }
        }

        // ---
        // Add any unmatched opening tags to positions (malformed blocks)
        // ---

        // Loop through unmatched opening tags
        foreach ($openTagStack as $openTag) {
            // Add unmatched opening tag to positions with zero length
            $blockPositions[] = [
                'start' => $openTag['start'],
                'length' => 0, // Will be handled by the fallback regex cleanup
                'type' => 'unmatched-opening',
            ];
        }

        // Return array of all block positions found
        return $blockPositions;
    }

    /**
     * Scan the selected content type for Details blocks.
     *
     * Checks each item's <content:encoded> for wp:details blocks to identify
     * Details block content. Details blocks create collapsible content sections
     * with summary text. Skips scanning for 'tablepress_table' and 'uamspeople'
     * content types, as their content is not intended for Bard parsing. Returns
     * an array of affected pages with details.
     *
     * @param  string  $contentType  The selected content type (e.g., 'page', 'post')
     * @return list<array{title: string, id: string, link: string}> Pages with Details blocks
     */
    private function scanForDetailsBlocks(
        string $contentType
    ): array {
        // Name the pages holding a Details block: wp:details
        return $this->pagesWhoseContent(
            $contentType,
            fn (string $content): bool => \preg_match(
                '/<!--\s*wp:details\b/',
                $content
            ) === 1
        );
    }

    /**
     * Scan the selected content type for Freeform blocks.
     *
     * Checks each item's <content:encoded> for wp:freeform blocks to identify
     * Freeform block content. Freeform blocks allow unrestricted HTML content
     * that bypasses Gutenberg's block restrictions. Skips scanning for
     * 'tablepress_table' and 'uamspeople' content types, as their content is
     * not intended for Bard parsing. Returns an array of affected pages with
     * details.
     *
     * @param  string  $contentType  The selected content type (e.g., 'page', 'post')
     * @return list<array{title: string, id: string, link: string}> Pages with Freeform blocks
     */
    private function scanForFreeformBlocks(
        string $contentType
    ): array {
        // Name the pages holding a Freeform block: wp:freeform
        return $this->pagesWhoseContent(
            $contentType,
            fn (string $content): bool => \preg_match(
                '/<!--\s*wp:freeform\b/',
                $content
            ) === 1
        );
    }

    /**
     * Scan the selected content type for kb entries without associated topics.
     *
     * @param  string  $contentType  The selected content type (e.g., 'kb')
     * @return list<array{title: string, id: string, link: string}> KB entries without associated topics
     */
    private function scanForKbWithoutTopics(
        string $contentType
    ): array {
        // Initialize an array to store entries without associated topics
        $kbWithoutTopics = [];

        // Iterate through each item to check for missing topics
        foreach ($this->xmlParser->getItems($contentType) as $item) {
            // Check for category elements with domain='kbtopic' to identify associated topics
            $categories = $item->xpath("category[@domain='kbtopic']");

            // ---
            // Check if there are no categories with domain='kbtopic', indicating no
            // associated topic
            // ---
            if ($categories === null || $categories === []) {
                // Add the entry details to the list of entries without topics
                $kbWithoutTopics[] = $this->pageSummary($item);
            }
        }

        // Return the list of entries without associated topics
        return $kbWithoutTopics;
    }

    /**
     * Name the pages of a content type whose content passes the given check.
     *
     * Skips 'tablepress_table' and 'uamspeople' content types, as their content is
     * not intended for Bard parsing.
     *
     * @param  string  $contentType  The selected content type (e.g., 'page', 'post')
     * @param  callable(string): bool  $holds  Whether an item's encoded content holds what is looked for
     * @return list<array{title: string, id: string, link: string}> The pages whose content holds it
     */
    private function pagesWhoseContent(
        string $contentType,
        callable $holds
    ): array {
        // Skip content types whose content is not Bard. The list holds only
        // strings, so the strict comparison only matches what a loose one would.
        // @pest-mutate-ignore: TrueToFalse
        if (
            \in_array(
                $contentType,
                [
                    'tablepress_table',
                    'uamspeople',
                ],
                true
            )
        ) {
            return [];
        }

        // Initialize array to store the pages found
        $pages = [];

        // Loop through items to check each one's encoded content
        foreach ($this->xmlParser->getItems($contentType) as $item) {
            // Check the encoded content from the WordPress XML item
            if (
                $holds(XpathHelper::getStringValue(
                    $item,
                    'content:encoded'
                ))
            ) {
                // Add the page to the list
                $pages[] = $this->pageSummary($item);
            }
        }

        // Return the pages found
        return $pages;
    }

    /**
     * Summarize an export item as the title, post ID and permalink a warning names it by.
     *
     * @param  \SimpleXMLElement  $item  The export item
     * @return array{title: string, id: string, link: string} The item's summary
     */
    private function pageSummary(
        \SimpleXMLElement $item
    ): array {
        // Decode HTML entities in the title and provide fallback
        $decodedTitle = html_entity_decode(
            (string) $item->title,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        // Name the page by its title, its WordPress post ID and its permalink
        return [
            'title' => $decodedTitle !== '' ? $decodedTitle : 'Untitled',
            'id' => XpathHelper::getStringValue(
                $item,
                'wp:post_id'
            ),
            'link' => (string) $item->link,
        ];
    }

    /**
     * Check if the content type contains TablePress shortcodes or blocks.
     *
     * Scans <content:encoded> for [table id=X /] shortcodes or wp:tablepress/table
     * blocks to determine if table imports are needed for content types like 'page'
     * or 'post'. Returns false for 'tablepress_table' content, as it does not
     * require shortcode/block detection. Normalizes content by decoding HTML
     * entities and trimming whitespace for accurate detection.
     *
     * @param  string  $contentType  The content type to scan (e.g., 'page', 'post')
     * @return bool True if TablePress content is found, false otherwise
     */
    private function hasTablePressContent(
        string $contentType
    ): bool {
        // Check if content type is tablepress_table
        if ($contentType === 'tablepress_table') {
            // Skip scanning for tablepress_table content type
            return false;
        }

        // Retrieve items for the content type
        $items = $this->xmlParser->getItems($contentType);

        // Loop through each item to check for TablePress content
        foreach ($items as $item) {
            // Extract the encoded content from the WordPress XML item
            $contentEncoded = XpathHelper::getStringValue(
                $item,
                'content:encoded'
            );

            // Decode HTML entities in the content
            $contentEncoded = html_entity_decode(
                $contentEncoded,
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );

            // Check for TablePress shortcodes or blocks
            $hasShortcode = \preg_match(
                '/\[table\s+id=/i',
                $contentEncoded
            ) === 1;

            // Check for TablePress Gutenberg blocks
            $hasBlock = \preg_match(
                '/<!--\s*wp:tablepress\/table\b/',
                $contentEncoded
            ) === 1;

            // If TablePress content is found, return true immediately
            if (
                $hasShortcode
                || $hasBlock
            ) {
                // TablePress content detected
                return true;
            }
        }

        // No TablePress content found in any items
        return false;
    }

    /**
     * Check if a collection is enabled for a site.
     *
     * @param  string  $site  The site handle
     * @param  string  $collectionHandle  The collection handle
     * @return bool True if enabled, false otherwise
     */
    private function isCollectionEnabledForSite(
        string $site,
        string $collectionHandle
    ): bool {
        // Check if collection is enabled for the site using SiteManager
        return $this->siteManager->isCollectionEnabledForSite(
            $site,
            $collectionHandle
        );
    }

    /**
     * Enable a collection for a site.
     *
     * @param  string  $site  The site handle
     * @param  string  $collectionHandle  The collection handle
     */
    private function enableCollectionForSite(
        string $site,
        string $collectionHandle
    ): void {
        // Enable collection for the site using SiteManager
        $this->siteManager->enableCollectionForSite(
            $site,
            $collectionHandle,
            $this->output
        );
    }

    /**
     * Check if a global set is enabled for a site.
     *
     * @param  string  $site  The site handle
     * @param  string  $globalHandle  The global set handle
     * @return bool True if enabled (variables exist for the site), false otherwise
     */
    private function isGlobalEnabledForSite(
        string $site,
        string $globalHandle
    ): bool {
        // Find global set by handle
        $global = GlobalSet::findByHandle($globalHandle);

        // Check if global set exists and has localization for the site
        return $global !== null && $global->in($site) !== null;
    }

    /**
     * Enable a global set for a site by creating its variables.
     *
     * Adds the site to the global set's sites and saves the set so Statamic
     * creates and persists the Variables (localization) for the site.
     *
     * @param  string  $site  The site handle
     * @param  string  $globalHandle  The global set handle
     */
    private function enableGlobalForSite(
        string $site,
        string $globalHandle
    ): void {
        // Find global set by handle
        $global = GlobalSet::findByHandle($globalHandle);

        // Check if the global set does not exist
        if (! $global instanceof StatamicGlobalSet) {
            // Skip the rest of the method
            return;
        }

        // Check if the global set is already localized for this site
        if ($global->in($site) !== null) {
            // Skip the rest of the method
            return;
        }

        // Add site to the global set's sites so Statamic creates Variables for it
        $sites = $global->sites();

        // A global set's sites() always answers a collection, so the assertion only
        // types it.
        // @pest-mutate-ignore: InstanceOfToTrue, RemoveFunctionCall
        \assert($sites instanceof \Illuminate\Support\Collection);

        $global->sites(
            $sites->merge([$site])->unique()->values()->all()
        );

        // Persist global set (creates and saves Variables for the new site)
        $global->save();
    }

    /**
     * Enable a taxonomy for a site if needed.
     *
     * Validates the existence of the taxonomy and checks if it is enabled for the
     * site. Enables it automatically if not already enabled.
     *
     * @param  string  $site  The site handle
     * @param  string  $statamicTaxonomyHandle  The Statamic taxonomy handle
     * @param  string  $contentType  The content type being imported
     * @return bool True if the taxonomy is enabled or successfully enabled, false otherwise
     */
    private function enableTaxonomyIfNeeded(
        string $site,
        string $statamicTaxonomyHandle,
        string $contentType
    ): bool {
        // Resolve the destination in whichever shape it takes. Asking only about a
        // taxonomy reported `logos_item_categories` and `people_groups` as missing
        // once `uams-statamic-archive#1863` converted them to structured collections, and
        // every caller of this method skips a whole content type on a false answer.
        $result = $this->siteManager->enableDestinationForSite(
            $site,
            $statamicTaxonomyHandle,
            $this->output
        );

        // Check if enabling the destination failed
        if (! $result) {
            // Warn the user that the destination is absent in both shapes, rather
            // than naming one of them and sending them to create the wrong thing.
            $this->warn("'$statamicTaxonomyHandle' does not exist in Statamic as a taxonomy or a collection.");

            // Inform user that the destination needs to be created
            $this->info("Please create and configure '$statamicTaxonomyHandle' before importing '$contentType' content.");

            // Return false to indicate failure
            return false;
        }

        // Return true to indicate success
        return true;
    }

    /**
     * Check if a specific ACF block type is present in the content of a given
     * content type.
     *
     * Scans the <content:encoded> of items for the specified ACF block
     * (e.g., 'wp:acf/logo-list') to determine if the associated taxonomy is relevant.
     *
     * @param  string  $contentType  The content type to scan (e.g., 'page')
     * @param  string  $acfBlockName  The ACF block name (e.g., 'logo-list', 'fad-providers')
     * @return bool True if the ACF block is found in any item's content, false otherwise
     */
    private function hasAcfBlockType(
        string $contentType,
        string $acfBlockName
    ): bool {
        // Retrieve items for the content type
        $items = $this->xmlParser->getItems($contentType);

        // Loop through each item to check for ACF block content
        foreach ($items as $item) {
            // Extract the encoded content from the WordPress XML item
            $contentEncoded = XpathHelper::getStringValue(
                $item,
                'content:encoded'
            );

            // Check if the content contains the specified ACF block
            if (
                \preg_match(
                    "/<!--\s*wp:acf\/{$acfBlockName}\s/",
                    $contentEncoded
                ) === 1
            ) {
                // ACF block found in content
                return true;
            }
        }

        // No ACF block found in any items
        return false;
    }

    /**
     * Parse the XML part(s) using streaming parser for memory efficiency.
     *
     * @param  list<string>  $parts  Ordered part paths (one entry for a single-file export)
     *
     * @throws \Exception If parsing fails
     */
    private function parseXmlFile(
        array $parts
    ): void {
        // Notify user that streaming parser is being used
        $this->info('Using streaming parser for memory efficiency.');

        // Parse large XML part(s) using streaming parser
        $this->parseLargeXmlFile($parts);
    }

    /**
     * Parse large XML part(s) using streaming parser.
     *
     * Streams every part so content-type counts are summed across the whole
     * part-set, while channel-level metadata (base blog URL) is taken from the
     * first part only, since it is duplicated in every part.
     *
     * @param  list<string>  $parts  Ordered part paths (one entry for a single-file export)
     *
     * @throws \Exception If parsing fails
     */
    private function parseLargeXmlFile(
        array $parts
    ): void {
        // Initialize a streaming parser spanning every part for content-type counts
        $streamParser = new XmlStreamParser($parts);

        // Initialize array to track content type counts
        $contentTypes = [];

        // Count all content types dynamically by streaming through items in every part
        foreach ($streamParser->streamAllItems() as $item) {
            // Extract the post type from the WordPress XML item
            $postType = XpathHelper::getStringValue(
                $item,
                'wp:post_type'
            );

            // Check if post type is not empty before counting
            if ($postType !== '') {
                // Increment the count for this post type
                $contentTypes[$postType] = ($contentTypes[$postType] ?? 0) + 1;
            }

            // Clean up variables to free up memory
            unset(
                $item
            );
        }

        // Set the content types in the regular parser for compatibility
        $this->xmlParser->setContentTypes($contentTypes);

        // Set every part path in the regular parser (item readers span all parts)
        $this->xmlParser->setFilePaths($parts);

        // Get base blog URL from a quick XMLReader scan of the first part
        $this->xmlParser->setBaseBlogUrl($this->getBaseBlogUrlFromStream($parts[0]));

        // Clean up variables to free up memory
        unset(
            $streamParser,
            $contentTypes
        );

        // Force garbage collection to reclaim memory from circular references. Collecting
        // changes no result.
        // @pest-mutate-ignore: RemoveFunctionCall
        gc_collect_cycles();
    }

    /**
     * Get base blog URL from XML file using streaming.
     *
     * @param  string  $filePath  Path to the XML file
     * @return string Base blog URL
     */
    private function getBaseBlogUrlFromStream(
        string $filePath
    ): string {
        // Open XML file for reading with appropriate flags
        $reader = \XMLReader::open(
            WxrSanitizedStream::wrap($filePath),
            null,
            LIBXML_NOCDATA
        );

        if ($reader === false) {
            return '';
        }

        // Wrap reading in try-finally to ensure cleanup
        try {
            // Loop through XML nodes
            while ($reader->read()) {
                // Check if current node is the base_blog_url element
                if (
                    $reader->nodeType === \XMLReader::ELEMENT
                    && $reader->name === 'wp:base_blog_url'
                ) {
                    // Read the element value
                    $reader->read();

                    // Extract URL value from the element
                    $url = $reader->value;

                    // Return the base blog URL
                    return $url;
                }
            }
        } finally {
            // Close the XMLReader to free resources. Closing changes no result.
            // @pest-mutate-ignore: RemoveMethodCall
            $reader->close();

            // Clean up variables to free up memory
            unset($reader);
        }

        // Return empty string if base blog URL not found
        return '';
    }

    /**
     * Check memory requirements and prompt user if needed.
     *
     * Analyzes the XML file to find the largest content items and current PHP
     * memory limit to determine if the import process might encounter memory
     * issues. Prompts the user to restart the command with increased memory
     * if needed.
     *
     * @param  list<string>  $parts  Ordered part paths (one entry for a single-file export)
     */
    private function checkMemoryRequirements(
        array $parts
    ): void {
        // Get current memory limit
        $currentMemoryLimit = $this->memoryLimit();

        // Convert memory limit string to bytes for comparison
        $currentMemoryBytes = $this->convertToBytes($currentMemoryLimit);

        // Display current memory limit to user
        $this->info("Current memory limit: {$currentMemoryLimit}");

        // ---
        // Calculate dynamic threshold based on current memory and multiplier
        // ---

        // Set multiplier for calculating recommended memory based on largest item
        $largestItemSizeMultiplier = 500;

        // Calculate threshold in bytes for considering items "large"; whole bytes,
        // since analyzeContentItemSizes() takes an int
        $dynamicThresholdBytes = \intdiv($currentMemoryBytes, $largestItemSizeMultiplier);

        // ---
        // Analyze content item sizes using streaming parser
        // ---

        // Notify user that content analysis is starting
        $this->info('Analyzing content item sizes...');

        // ---
        // Analyze every part, tracking the largest single item and total large-item
        // count. Only one part is read at a time, so peak memory stays bounded by the
        // largest single part rather than the combined part-set size.
        // ---

        // Initialize the largest item size seen across all parts. Every part is measured,
        // and a measurement is never negative, so a start above zero shows only below a
        // byte, which reads as 0MB either way.
        // @pest-mutate-ignore: IncrementInteger
        $largestItemSize = 0;

        // Initialize the total count of large items across all parts
        $largeItemsCount = 0;

        // Analyze each part in turn, folding its results into the running totals
        foreach ($parts as $part) {
            // Analyze this part's content item sizes to find its largest items
            $contentAnalysis = $this->analyzeContentItemSizes(
                $part,
                $dynamicThresholdBytes
            );

            // Keep the largest single item seen across every analyzed part
            $largestItemSize = max(
                $largestItemSize,
                $contentAnalysis['largestItemSize']
            );

            // Accumulate the count of large items across every analyzed part
            $largeItemsCount += $contentAnalysis['largeItemsCount'];
        }

        // ---
        // Calculate recommended memory based on largest content items
        // ---

        // Convert largest item size to megabytes for display
        $largestItemSizeMB = round(
            $largestItemSize / 1024 / 1024,
            2
        );

        // Calculate recommended memory in bytes based on largest item
        $recommendedMemoryBytes = $largestItemSize * $largestItemSizeMultiplier; // Multiplied for XML parsing overhead and processing

        // Convert recommended memory to megabytes for display
        $recommendedMemoryMB = round(
            $recommendedMemoryBytes / 1024 / 1024,
            2
        );

        // Convert dynamic threshold to megabytes for display
        $dynamicThresholdMB = round(
            $dynamicThresholdBytes / 1024 / 1024,
            2
        );

        // Display largest content item size to user
        $this->info("Largest content item: {$largestItemSizeMB}MB");

        // Check if we need more memory
        if ($currentMemoryBytes < $recommendedMemoryBytes) {
            // ---
            // Warn user about the large items found. An item needing more than the limit
            // is larger than the limit's threshold, so at least one was counted.
            // ---
            $this->warn("Found {$largeItemsCount} content items larger than {$dynamicThresholdMB}MB.");

            // Warn user about potential memory issues
            $this->warn('These items may cause memory exhaustion during XML parsing and processing.');

            // Warn user about large content items and recommended memory
            $this->warn("Large content items detected. Recommended memory: {$recommendedMemoryMB}MB");

            // Warn user that current memory limit may be insufficient
            $this->warn("Current memory limit ({$currentMemoryLimit}) may be insufficient for this import.");

            // Calculate suggested memory
            $suggestedMemory = $this->getSuggestedMemory($recommendedMemoryMB);

            // ---
            // Provide options to the user
            // ---

            // Define options for user to choose from
            $options = [
                'Restart with increased memory',
                'Continue with current memory limit',
                'Exit',
            ];

            // Prompt user to select how to proceed
            $choice = $this->isPromptsSupported() && $this->input->isInteractive()
                ? select(
                    label: 'How would you like to proceed?',
                    options: $options,
                    default: $options[0]
                )
                : $this->choice(
                    'How would you like to proceed?',
                    $options,
                    0
                );

            // Check if user chose to restart with increased memory
            if ($choice === 'Restart with increased memory') {
                // Inform user that command will restart
                $this->info('Restarting command with increased memory limit...');

                // Restart command with increased memory limit, pointing at the first
                // part — the locator re-discovers the whole part-set from it on rerun
                $this->restartWithIncreasedMemory(
                    $suggestedMemory,
                    $parts[0]
                );
            } elseif ($choice === 'Continue with current memory limit') { // Check if user chose to continue with current memory
                // Warn user to monitor for memory exhaustion
                $this->warn('Proceeding with current memory limit. Monitor for memory exhaustion.');
            } else { // Otherwise
                // Inform user that command is exiting
                $this->info('Exiting. Please restart the command with increased memory limit when ready.');

                // Exit with error status code
                $this->terminate(1);
            }
        } else { //  Otherwise
            // Inform user that memory limit appears sufficient
            $this->info('Memory limit appears sufficient for this import.');
        }
    }

    /**
     * The PHP memory limit the memory check compares against, as `ini_get()` gives it.
     *
     * A method of its own so a test can supply a limit without lowering the process's own,
     * which a test worker may already exceed.
     */
    protected function memoryLimit(): string
    {
        // Read the process's memory limit
        return ini_get('memory_limit');
    }

    /**
     * Convert memory limit string to bytes.
     *
     * @param  string  $memoryLimit  Memory limit string (e.g., "128M", "1G", "-1")
     * @return int Memory limit in bytes
     */
    private function convertToBytes(
        string $memoryLimit
    ): int {
        // Check if memory limit is unlimited
        if ($memoryLimit === '-1') {
            // Return maximum integer value for unlimited memory
            return PHP_INT_MAX;
        }

        // Extract unit character from end of string
        $unit = \strtolower(\substr(
            $memoryLimit,
            -1
        ));

        // Extract numeric value from beginning of string. A cast reads only the
        // leading digits, so the unit needs no stripping first.
        $value = (int) $memoryLimit;

        // Convert to bytes based on unit
        return match ($unit) {
            'k' => $value * 1024,
            'm' => $value * 1024 * 1024,
            'g' => $value * 1024 * 1024 * 1024,
            default => $value,
        };
    }

    /**
     * Analyze content item sizes in the XML file.
     *
     * Uses streaming parser to examine individual content items and identify
     * the largest ones that might cause memory issues during import.
     *
     * @param  string  $filePath  Path to the XML file
     * @param  int  $thresholdBytes  Threshold in bytes for considering items "large"
     * @return array{largestItemSize: int, largeItemsCount: int} Largest item size and large-item count
     */
    private function analyzeContentItemSizes(
        string $filePath,
        int $thresholdBytes = 50 * 1024
    ): array {
        // Open XML file for reading with appropriate flags
        $reader = \XMLReader::open(
            WxrSanitizedStream::wrap($filePath),
            null,
            LIBXML_NOCDATA
        );

        if ($reader === false) {
            return [
                'largestItemSize' => 0,
                'largeItemsCount' => 0,
            ];
        }

        // Initialize variable to track largest item size found
        $largestItemSize = 0;

        // Initialize counter for items exceeding threshold
        $largeItemsCount = 0;

        // Initialize flag to track if we're currently inside content:encoded element
        $inContentEncoded = false;

        // Initialize buffer to accumulate content for size calculation. Each item resets
        // it before it is measured, so content outside an item is never counted, and nor
        // is the start.
        // @pest-mutate-ignore: EmptyStringToNotEmpty
        $contentBuffer = '';

        // Loop through XML nodes
        while ($reader->read()) {
            // Get current node type
            $nodeType = $reader->nodeType;

            // Get current node name
            $nodeName = $reader->name;

            // Check if current node is an element
            if ($nodeType === \XMLReader::ELEMENT) {
                // Check if this is the start of an item element
                if ($nodeName === 'item') {
                    // Reset content buffer for new item
                    $contentBuffer = '';
                } elseif ($nodeName === 'content:encoded') { // Check if this is the start of content:encoded element
                    // Set flag to indicate we're inside content:encoded
                    $inContentEncoded = true;
                }
            } elseif ($nodeType === \XMLReader::END_ELEMENT) { // Check if current node is an end element
                // Check if this is the end of an item element
                if ($nodeName === 'item') {
                    // Finished processing an item, check its size
                    $itemSize = \strlen($contentBuffer);

                    // Keep the largest item size seen
                    $largestItemSize = max(
                        $largestItemSize,
                        $itemSize
                    );

                    // Check if this item exceeds the threshold
                    if ($itemSize > $thresholdBytes) {
                        // Increment counter for large items
                        $largeItemsCount++;
                    }
                } elseif ($nodeName === 'content:encoded') { // Check if this is the end of content:encoded element
                    // Reset flag to indicate we're no longer inside content:encoded
                    $inContentEncoded = false;
                }
            } elseif (
                $nodeType === \XMLReader::TEXT
                && $inContentEncoded
            ) { // Check if current node is text content
                // ---
                // Accumulate content size (limit buffer size to prevent memory issues)
                // ---

                // Check if buffer hasn't exceeded maximum size
                if (\strlen($contentBuffer) < 50 * 1024 * 1024) { // Max 50MB buffer
                    // Append text content to buffer
                    $contentBuffer .= $reader->value;
                }
            }
        }

        // Close the XMLReader to free resources. Closing changes no result.
        // @pest-mutate-ignore: RemoveMethodCall
        $reader->close();

        // Clean up variables to free up memory
        unset(
            $contentBuffer,
            $reader
        );

        // Force garbage collection to reclaim memory from circular references. Collecting
        // changes no result.
        // @pest-mutate-ignore: RemoveFunctionCall
        gc_collect_cycles();

        // Return analysis results
        return [
            'largestItemSize' => $largestItemSize,
            'largeItemsCount' => $largeItemsCount,
        ];
    }

    /**
     * Provide restart instructions with increased memory.
     *
     * @param  string  $memoryLimit  The memory limit to use
     * @param  string  $filePath  The XML file path
     */
    private function restartWithIncreasedMemory(
        string $memoryLimit,
        string $filePath
    ): void {
        // Display instructions to user for restarting with increased memory
        $this->line('');
        $this->info('Run this command with increased memory:');
        $this->line("php artisan cache:clear && > storage/logs/laravel.log && php -d memory_limit={$memoryLimit} artisan statamic:import:wordpress {$filePath}");
        $this->line('');
        $this->info('Exiting. Please run the command above with the increased memory limit.');

        // Exit with success status code
        $this->terminate(0);
    }

    /**
     * End the process with the given status, as the memory check's choices do.
     *
     * A method of its own so a test can stand in for ending the process.
     *
     * @param  int  $status  The exit status
     */
    protected function terminate(
        int $status
    ): never {
        // End the process with the status
        exit($status);
    }

    /**
     * Get suggested memory limit based on recommended memory.
     *
     * @param  float  $recommendedMemoryMB  Recommended memory in MB
     * @return string Suggested memory limit string
     */
    private function getSuggestedMemory(
        float $recommendedMemoryMB
    ): string {
        // Round up to nearest multiple of 128MB
        $roundedUp = ceil($recommendedMemoryMB / 128) * 128;

        // Return suggested memory limit as string with M suffix
        return "{$roundedUp}M";
    }

    /**
     * Run Bard content sanitization on all collection entries.
     *
     * Ensures YAML is formatted properly when the import is completed by
     * double-quoting text values containing `\_` (non-breaking space) and
     * quoting YAML document separators in Bard text nodes. Processes all
     * collections and only rewrites files that need sanitization.
     */
    private function runBardContentSanitization(): void
    {
        $this->info('Sanitizing Bard content in entries...');

        $entriesUpdated = 0;
        $collections = Collection::all();

        foreach ($collections as $collection) {
            // Collection::all() answers only collections, an entry query is always the
            // Stache's builder and answers a collection of entries, and an entry's path is
            // never empty, so each check below only types what it reads.
            // @pest-mutate-ignore: InstanceOfToTrue, ContinueToBreak
            if (! $collection instanceof StatamicCollection) {
                continue;
            }

            $entryQuery = Entry::query();

            // @pest-mutate-ignore: InstanceOfToTrue, RemoveFunctionCall
            \assert($entryQuery instanceof EntryQueryBuilder);

            $query = $entryQuery->where(
                'collection',
                $collection->handle()
            );

            // @pest-mutate-ignore: InstanceOfToTrue, RemoveFunctionCall
            \assert($query instanceof EntryQueryBuilder);

            $entries = $query->get();

            // @pest-mutate-ignore: InstanceOfToTrue, ContinueToBreak
            if (! $entries instanceof \Illuminate\Support\Collection) {
                continue;
            }

            foreach ($entries as $entry) {
                // @pest-mutate-ignore: InstanceOfToTrue, ContinueToBreak
                if (! $entry instanceof StatamicEntry) {
                    continue;
                }

                $filePath = $entry->path();

                // @pest-mutate-ignore: BooleanOrToBooleanAnd, EmptyStringToNotEmpty, ContinueToBreak
                if (! is_string($filePath) || $filePath === '') {
                    continue;
                }

                if (! LaravelFile::exists($filePath)) {
                    continue;
                }

                $fileContent = LaravelFile::get($filePath);
                $needsSanitization = $this->bardContentSanitizer
                    ->containsYamlSeparatorsInRawFile($fileContent)
                    || $this->bardContentSanitizer
                        ->containsUnquotedNbspTextInRawFile($fileContent);

                if ($needsSanitization) {
                    $sanitizedContent = $this->bardContentSanitizer
                        ->sanitizeRawFileContent($fileContent);
                    LaravelFile::put($filePath, $sanitizedContent);
                    $entriesUpdated++;
                }
            }
        }

        if ($entriesUpdated > 0) {
            $this->line("  Sanitized {$entriesUpdated} entr".($entriesUpdated === 1 ? 'y' : 'ies').'.');
        } else {
            $this->line('  No Bard content required sanitization.');
        }

        unset(
            $collections,
            $entriesUpdated
        );
    }

    /**
     * Handle navigation menu import process.
     *
     * Prompts user to confirm active primary navigation and select menu to import,
     * then imports the selected menu items into Statamic's primary_navigation.
     */
    private function handleNavigationMenuImport(): void
    {
        // Get the site handle
        $site = SiteResolver::getCurrentSite();

        // Check if no site was selected. The resolver falls back to the default site, so
        // it always answers one, and the check only types it.
        // @pest-mutate-ignore: RemoveMethodCall, RemoveEarlyReturn
        if ($site === null) {
            // Alert the user that no site was selected
            $this->error('No site selected for navigation import');

            // Exit the command
            return;
        }

        // Confirm if there's an active menu in the primary navigation position
        $hasActiveMenu = $this->navigationMenuSelector->confirmActivePrimaryNavigation(
            $this->xmlParser,
            $this->input,
            $this->output,
            $this
        );

        // Check if no menu was selected
        if (! $hasActiveMenu) {
            // Alert the user that no menu was selected
            $this->info('Skipping navigation menu import - no active menu in primary navigation position');

            // Exit the command
            return;
        }

        // Set manual_primary_navigation to true in uams_multisite_settings Global
        $this->setManualPrimaryNavigation($site);

        // Select which menu to import
        $selectedMenuSlug = $this->navigationMenuSelector->selectNavigationMenu(
            $this->xmlParser,
            $this->input,
            $this->output,
            $this
        );

        // Check if no menu was selected
        if ($selectedMenuSlug === null) {
            // Alert the user that no menu was selected
            $this->info('No menu selected - skipping navigation menu import');

            // Exit the command
            return;
        }

        // Import the selected menu
        $this->navigationMenuImporter->importNavigationMenu(
            $this->xmlParser,
            $selectedMenuSlug,
            $site,
            $this->output
        );
    }

    /**
     * Set manual_primary_navigation to true in uams_multisite_settings Global.
     *
     * Updates the localized uams_multisite_settings Global for the specified site
     * to indicate that manual primary navigation is enabled.
     *
     * @param  string  $site  The Statamic site handle
     */
    private function setManualPrimaryNavigation(
        string $site
    ): void {
        // Attempt to update the Global with error handling
        try {
            // Get the uams_multisite_settings Global
            $global = GlobalSet::findByHandle('uams_multisite_settings');

            // Check if the Global exists
            if ($global === null) {
                // Warn user that Global was not found
                $this->warn('uams_multisite_settings Global not found - skipping manual_primary_navigation setting');

                // Exit early since Global doesn't exist
                return;
            }

            // Get the localized version for the site
            $localizedGlobal = $global->in($site);

            // Check if the Global is available for the specified site
            if (! $localizedGlobal instanceof StatamicVariables) {
                // Warn user that Global is not available for this site
                $this->warn("uams_multisite_settings Global not available for site '{$site}' - skipping manual_primary_navigation setting");

                // Exit early since Global is not available for this site
                return;
            }

            // Set manual_primary_navigation to true
            $localizedGlobal->set(
                'manual_primary_navigation',
                true
            );

            // Save the changes to persist them
            $localizedGlobal->save();

            // Inform user of successful operation
            $this->info("Set manual_primary_navigation to true for site '{$site}'");
        }

        // Handle any exceptions that occur during Global operations
        catch (\Exception $exception) {
            // Warn user about the failure
            $this->warn("Failed to set manual_primary_navigation for site '{$site}': {$exception->getMessage()}");

            // Log the error for debugging purposes
            Log::error(
                'ImportWordPress::setManualPrimaryNavigation failed',
                [
                    'site' => $site,
                    'error' => $exception->getMessage(),
                ]
            );
        }
    }

    /**
     * Set this site's `department_organization`, opting it in as a news hub.
     *
     * Reports rather than throws. A designation that cannot be applied leaves the
     * site rendering its news page as a plain listing, which is the documented
     * degrade and not a reason to abandon an import — so every branch here ends in
     * output, and none of them alters the caller's control flow (wordpress-importer-archive#768).
     *
     * The crosswalk this reads ships empty pending its authoring, so a normal run
     * reports every site as unrecorded. That line is the point: it is the only
     * signal an operator gets that the designation step exists and has nothing to
     * apply yet.
     *
     * @param  string  $site  The Statamic site handle
     */
    private function designateDepartmentOrganization(
        string $site
    ): void {
        // Attempt the designation with error handling
        try {
            // Build the designator over the designations recorded in this package
            $designator = new DepartmentOrganizationDesignator(
                $this->departmentOrganizationMap()
            );

            // Apply whatever the crosswalk records for this site
            $designation = $designator->designate($site);

            // Report the outcome
            switch ($designation->outcome) {
                case DepartmentOrganizationOutcome::Designated:
                    $this->info("Designated site '$site' as a news hub for '{$designation->organizationSlug}'.");
                    // A continue inside a switch acts as a break.
                    // @pest-mutate-ignore: BreakToContinue
                    break;

                case DepartmentOrganizationOutcome::AlreadyDesignated:
                    $this->info("Site '$site' is already designated as a news hub for '{$designation->organizationSlug}'.");
                    // A continue inside a switch acts as a break.
                    // @pest-mutate-ignore: BreakToContinue
                    break;

                case DepartmentOrganizationOutcome::DeliberatelyUnmapped:
                    // A recorded rejection is a decision, not a gap - nothing to report
                    // A continue inside a switch acts as a break.
                    // @pest-mutate-ignore: BreakToContinue
                    break;

                case DepartmentOrganizationOutcome::NotRecorded:
                    $this->info("No department_organization designation is recorded for site '$site' - its news page will render as a plain listing.");
                    // A continue inside a switch acts as a break.
                    // @pest-mutate-ignore: BreakToContinue
                    break;

                case DepartmentOrganizationOutcome::DanglingReference:
                    $this->warn("Site '$site' is designated for organization '{$designation->organizationSlug}', which does not exist - designation not applied.");
                    // A continue inside a switch acts as a break.
                    // @pest-mutate-ignore: BreakToContinue
                    break;

                case DepartmentOrganizationOutcome::GlobalMissing:
                    $this->warn('uams_multisite_settings Global not found - skipping department_organization designation');
                    // A continue inside a switch acts as a break.
                    // @pest-mutate-ignore: BreakToContinue
                    break;

                case DepartmentOrganizationOutcome::SiteUnavailable:
                    $this->warn("uams_multisite_settings Global not available for site '$site' - skipping department_organization designation");
                    // A continue inside a switch acts as a break.
                    // @pest-mutate-ignore: BreakToContinue
                    break;
            }

            // Attribute the hub-audit failure this designation will cause, so it is
            // expected rather than discovered by whoever runs the audit later.
            if ($designation->isDesignated() && $designation->schemaSubjectMissing) {
                $this->warn("Organization '{$designation->organizationSlug}' has no schema_subject - 'multisite:audit-department-hubs' will report site '$site' until UAMS-Web/uams-statamic#197 authors it.");
            }
        }

        // Handle any exceptions that occur during the designation
        catch (\Exception $exception) {
            // Warn user about the failure
            $this->warn("Failed to set department_organization for site '$site': {$exception->getMessage()}");

            // Log the error for debugging purposes
            Log::error(
                'ImportWordPress::designateDepartmentOrganization failed',
                [
                    'site' => $site,
                    'error' => $exception->getMessage(),
                ]
            );
        }
    }

    /**
     * The site to organization crosswalk the designation applies: the one recorded in this
     * package.
     *
     * A method of its own so a test can supply a crosswalk with entries, since the recorded
     * one ships empty.
     */
    protected function departmentOrganizationMap(): DepartmentOrganizationMap
    {
        // Read the crosswalk recorded in this package
        return DepartmentOrganizationMap::recorded();
    }

    /**
     * Calculate the total filesize of a directory recursively.
     *
     * Recursively traverses the specified directory and calculates the total
     * size of all files within it. Uses decimal conversion (1 MB = 1000 KB)
     * as per project standards.
     *
     * @param  string  $directory  The directory path to calculate filesize for
     * @return int Total filesize in bytes
     */
    private function calculateDirectoryFilesize(
        string $directory
    ): int {
        // Initialize total size counter to zero
        $totalSize = 0;

        // Check if the directory exists and is accessible
        if (! \is_dir($directory)) {
            // Return zero if directory doesn't exist or isn't accessible
            return 0;
        }

        // Create recursive iterator to traverse directory and all subdirectories
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $directory,
                \RecursiveDirectoryIterator::SKIP_DOTS
            )
        );

        // Loop through all files and directories recursively
        foreach ($iterator as $file) {
            // Check if current item is a file (not a directory). The iterator yields
            // only SplFileInfo, so the instanceof check only types it.
            // @pest-mutate-ignore: InstanceOfToTrue
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                // Add file size to total size counter
                $totalSize += $file->getSize();
            }
        }

        // Clean up variables to free up memory
        unset(
            $iterator
        );

        // Return the total calculated size in bytes
        return $totalSize;
    }

    /**
     * Format filesize in multiple units for display.
     *
     * Converts bytes to kilobytes, megabytes, and gigabytes using decimal
     * conversion (1 MB = 1000 KB) as per project standards.
     *
     * @param  int  $bytes  The filesize in bytes
     * @return array{bytes: int, kb: float, mb: float, gb: float} Filesize in multiple units
     */
    private function formatFilesize(
        int $bytes
    ): array {
        // Return array with filesize converted to multiple units using decimal conversion
        return [
            'bytes' => $bytes,
            'kb' => round(
                $bytes / 1000,
                2
            ),
            'mb' => round(
                $bytes / (1000 * 1000),
                2
            ),
            'gb' => round(
                $bytes / (1000 * 1000 * 1000),
                2
            ),
        ];
    }

    /**
     * Display filesize difference between initial and final measurements.
     *
     * Calculates the difference between the initial and final filesize
     * measurements and displays the result in bytes, kilobytes, megabytes,
     * and gigabytes.
     *
     * @param  int  $finalFilesize  The final filesize in bytes
     */
    private function displayFilesizeDifference(
        int $finalFilesize
    ): void {
        // Calculate the difference between final and initial filesize measurements
        $difference = $finalFilesize - $this->initialFilesize;

        // Format the difference for display in multiple units
        $formatted = $this->formatFilesize($difference);

        // Display the filesize difference to the user in all units
        $this->line('');
        $this->info('Filesize difference after import:');
        $this->line("  Bytes: {$formatted['bytes']}");
        $this->line("  Kilobytes: {$formatted['kb']} KB");
        $this->line("  Megabytes: {$formatted['mb']} MB");
        $this->line("  Gigabytes: {$formatted['gb']} GB");
        $this->line('');

        // Clean up variables to free up memory
        unset(
            $formatted
        );
    }
}
