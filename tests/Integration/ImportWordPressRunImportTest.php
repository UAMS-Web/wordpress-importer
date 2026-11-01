<?php

declare(strict_types=1);

use App\Services\BardContentSanitizer;
use Illuminate\Console\Command;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Laravel\Prompts\Prompt;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Facades\Stache;
use Statamic\Globals\GlobalSet as ConcreteGlobalSet;
use Statamic\Stache\Stores\Store;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Console\Commands\ImportWordPress;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\FailedAssetCollector;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\DeferredProcessing\DeferredProcessingCoordinator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\Creators\KbEntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\EntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormPluginCoordinator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Globals\GlobalCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\Blocks\FindADocLocationsParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\Blocks\FindADocProvidersParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\Blocks\LogoListParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\ContentTypeSelector;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings\Selector as MinervaKbSettingsSelector;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\NavigationMenuSelector;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\SiteSelector;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\TaxonomyOverwriteBehaviorSelector;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Structures\TreeBuilder;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Taxonomies\BaseTaxonomyValidator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\CollectionStructureManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ContentRoot;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\PeopleShortcodeProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\SiteManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\SiteResolver;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;
use UAMSWeb\Aether\WordPressImporter\Tests\Support\LfBufferedOutput;

uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

// The sanitizer is the host app's; standalone, the package's stub stands in for it
if (! class_exists(BardContentSanitizer::class)) {
    require_once __DIR__.'/../Support/IdeStubs/App/Services/BardContentSanitizer.php';
}

/**
 * Tests `ImportWordPress::runImportForParts()`, the import of a resolved part-set, end to end
 * through its stages: the setup, the content types found and the site chosen, the gates that
 * stop a content type for unsupported content or a missing destination, the import of each
 * type into a collection or a global set, and the steps after the last type.
 *
 * Every collaborator the command hands work to is a recording stand-in that writes what it
 * was asked into one shared log, so a case reads the order of a run as well as its output.
 * The export is real, and so are collections, global sets and the Stache, kept off disk.
 *
 * @command  php vendor/bin/pest tests/Integration/ImportWordPressRunImportTest.php
 */

/**
 * The calls the stand-ins received, in order.
 */
final class IwriLog
{
    /**
     * Each call, as its name and what it was given.
     *
     * @var list<array{string, list<mixed>}>
     */
    public static array $calls = [];

    /**
     * Record a call.
     */
    public static function add(string $name, mixed ...$arguments): void
    {
        self::$calls[] = [$name, array_values($arguments)];
    }

    /**
     * The names of the calls received, in order.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (array $call): string => $call[0], self::$calls);
    }

    /**
     * The arguments of each call with the given name.
     *
     * @return list<list<mixed>>
     */
    public static function of(string $name): array
    {
        return array_values(array_map(
            static fn (array $call): array => $call[1],
            array_filter(self::$calls, static fn (array $call): bool => $call[0] === $name)
        ));
    }
}

/**
 * The command, reading an unlimited memory limit so the memory check never prompts.
 */
final class IwriCommand extends ImportWordPress
{
    /**
     * Whether Laravel Prompts asks the questions, as on macOS and Linux, or Symfony does, as on
     * Windows without WSL.
     */
    public bool $prompts = true;

    /**
     * {@inheritDoc}
     */
    protected function isPromptsSupported(): bool
    {
        return $this->prompts;
    }

    /**
     * {@inheritDoc}
     */
    protected function memoryLimit(): string
    {
        return '-1';
    }
}

/**
 * A form coordinator that records its calls.
 */
final class IwriForms extends FormPluginCoordinator
{
    /**
     * {@inheritDoc}
     */
    public function handleFormPluginDetection(XmlParser $xmlParser, InputInterface $input, OutputInterface $output, Command $command): bool
    {
        IwriLog::add('forms.detect');

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function clearAllCaches(): void
    {
        IwriLog::add('forms.clear');
    }
}

/**
 * A site selector that answers the given site and judges it as told.
 */
final class IwriSites extends SiteSelector
{
    /**
     * The site to answer.
     */
    public string $site = 'default';

    /**
     * Whether the site is valid.
     */
    public bool $valid = true;

    /**
     * {@inheritDoc}
     */
    public function select(InputInterface $input, OutputInterface $output, Command $command): string
    {
        IwriLog::add('site.select');

        return $this->site;
    }

    /**
     * {@inheritDoc}
     */
    public function validate(string $siteHandle): bool
    {
        IwriLog::add('site.validate', $siteHandle);

        return $this->valid;
    }
}

/**
 * A content type selector that records what it offers and answers the first offered.
 */
final class IwriTypes extends ContentTypeSelector
{
    /**
     * A type to answer whenever it is offered, ahead of the first.
     */
    public static ?string $prefer = null;

    /**
     * {@inheritDoc}
     */
    public function select(array $contentTypes, InputInterface $input, OutputInterface $output, Command $command): string
    {
        $handles = array_map(static fn (mixed $type): mixed => is_array($type) ? $type[0] : $type, $contentTypes);
        IwriLog::add('types.select', $handles);

        if (self::$prefer !== null && in_array(self::$prefer, $handles, true)) {
            return self::$prefer;
        }

        $first = $handles[0] ?? '';

        return is_string($first) ? $first : '';
    }
}

/**
 * A taxonomy overwrite selector answering one behavior.
 */
final class IwriTaxonomyOverwrite extends TaxonomyOverwriteBehaviorSelector
{
    /**
     * {@inheritDoc}
     */
    public function select(InputInterface $input, OutputInterface $output, Command $command): string
    {
        IwriLog::add('taxonomyOverwrite.select');

        return 'Overwrite existing taxonomy terms';
    }
}

/**
 * A MinervaKB settings selector answering fixed settings.
 */
final class IwriMinerva extends MinervaKbSettingsSelector
{
    /**
     * {@inheritDoc}
     */
    public function select(InputInterface $input, OutputInterface $output, Command $command): array
    {
        IwriLog::add('minerva.select');

        return ['kb_slug' => 'kb'];
    }
}

/**
 * An entry creator that records what it is told and creates nothing.
 */
final class IwriEntries extends EntryCreator
{
    /**
     * What hasShowPeople() answers.
     */
    public bool $showPeople = false;

    /**
     * Run while entries are created, to leave what a real import leaves behind.
     */
    public static ?Closure $onCreate = null;

    /**
     * The site manager it hands out.
     */
    public SiteManager $sites;

    /**
     * {@inheritDoc}
     */
    public function setClassicBlockPages(array $classicBlockPages): void
    {
        IwriLog::add('entries.classic', $classicBlockPages);
    }

    /**
     * {@inheritDoc}
     */
    public function setFreeformBlockPages(array $freeformBlockPages): void
    {
        IwriLog::add('entries.freeform', $freeformBlockPages);
    }

    /**
     * {@inheritDoc}
     */
    public function setDetailsBlockPages(array $detailsBlockPages): void
    {
        IwriLog::add('entries.details', $detailsBlockPages);
    }

    /**
     * {@inheritDoc}
     */
    public function setKbWithoutTopics(array $kbWithoutTopics): void
    {
        IwriLog::add('entries.kbWithoutTopics', $kbWithoutTopics);
    }

    /**
     * {@inheritDoc}
     */
    public function hasShowPeople(string $contentType): bool
    {
        IwriLog::add('entries.hasShowPeople', $contentType);

        return $this->showPeople;
    }

    /**
     * {@inheritDoc}
     */
    public function setEntryOverwriteBehavior(string $behavior): void
    {
        IwriLog::add('entries.entryOverwrite', $behavior);
    }

    /**
     * {@inheritDoc}
     */
    public function setTaxonomyOverwriteBehavior(string $behavior): void
    {
        IwriLog::add('entries.taxonomyOverwrite', $behavior);
    }

    /**
     * {@inheritDoc}
     */
    public function setTableOverwriteBehavior(string $behavior): void
    {
        IwriLog::add('entries.tableOverwrite', $behavior);
    }

    /**
     * {@inheritDoc}
     */
    public function setXmlParser(XmlParser $xmlParser): void
    {
        IwriLog::add('entries.xmlParser', $xmlParser);
    }

    /**
     * {@inheritDoc}
     */
    public function setAssetImporter(AssetImporter $assetImporter): void
    {
        IwriLog::add('entries.assetImporter', $assetImporter);
    }

    /**
     * {@inheritDoc}
     */
    public function createEntries(string $site, string $contentType, string $collectionHandle, XmlParser $xmlParser, AssetImporter $assetImporter, OutputInterface $output, ?string $baseBlogUrl = null, ?array $minervaKbSettings = null): void
    {
        IwriLog::add('entries.create', $site, $contentType, $collectionHandle, $xmlParser, $assetImporter, $output, $baseBlogUrl, $minervaKbSettings);

        if (self::$onCreate instanceof Closure) {
            (self::$onCreate)();
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getPageData(): array
    {
        return ['12' => ['entry' => 'about-12-abc']];
    }

    /**
     * {@inheritDoc}
     */
    public function getSiteManager(): SiteManager
    {
        return $this->sites;
    }
}

/**
 * A knowledge base creator that records what it is told.
 */
final class IwriKb extends KbEntryCreator
{
    /**
     * {@inheritDoc}
     */
    public function setTaxonomyOverwriteBehavior(string $behavior): void
    {
        IwriLog::add('kb.taxonomyOverwrite', $behavior);
    }

    /**
     * {@inheritDoc}
     */
    public function process(XmlParser $xmlParser, AssetImporter $assetImporter, OutputInterface $output, string $baseBlogUrl, string $site, SiteManager $siteManager, CollectionStructureManager $collectionStructureManager, ?array $minervaKbSettings = null): void
    {
        IwriLog::add('kb.process', $xmlParser, $assetImporter, $output, $baseBlogUrl, $site, $siteManager, $collectionStructureManager, $minervaKbSettings);
    }
}

/**
 * A tree builder that records what it is asked to build.
 */
final class IwriTree extends TreeBuilder
{
    /**
     * {@inheritDoc}
     */
    public function buildAndSaveTree(string $site, string $contentType, string $collectionHandle, array $pageData, OutputInterface $output, ?string $baseBlogUrl = null): void
    {
        IwriLog::add('tree.build', $site, $contentType, $collectionHandle, $pageData, $output, $baseBlogUrl);
    }
}

/**
 * A global creator that records what it is asked to create.
 */
final class IwriGlobals extends GlobalCreator
{
    /**
     * {@inheritDoc}
     */
    public function setGlobalOverwriteBehavior(string $behavior): void
    {
        IwriLog::add('globals.overwrite', $behavior);
    }

    /**
     * {@inheritDoc}
     */
    public function createGlobals(string $site, string $contentType, string $globalHandle, XmlParser $xmlParser, AssetImporter $assetImporter, OutputInterface $output): void
    {
        IwriLog::add('globals.create', $site, $contentType, $globalHandle);
        IwriLog::add('globals.localized', GlobalSet::findByHandle($globalHandle)?->in($site) !== null);
    }
}

/**
 * An asset importer that records the settings it is given.
 */
final class IwriAssets extends AssetImporter
{
    /**
     * {@inheritDoc}
     */
    public function setBaseBlogUrl(?string $baseBlogUrl): void
    {
        IwriLog::add('assets.baseBlogUrl', $baseBlogUrl);
    }

    /**
     * {@inheritDoc}
     */
    public function setAssetOverwriteBehavior(string $behavior): void
    {
        IwriLog::add('assets.overwrite', $behavior);
    }
}

/**
 * A deferred processing coordinator that records the final pass.
 */
final class IwriDeferred extends DeferredProcessingCoordinator
{
    /**
     * {@inheritDoc}
     */
    public function processFinal(string $site, OutputInterface $output): void
    {
        IwriLog::add('deferred.final', $site);
    }
}

/**
 * A site manager that answers as told about collections and destinations.
 */
final class IwriSiteManager extends SiteManager
{
    /**
     * Whether a collection is enabled for the site already.
     */
    public bool $enabled = true;

    /**
     * The destination handles that cannot be enabled.
     *
     * @var list<string>
     */
    public array $missingDestinations = [];

    /**
     * {@inheritDoc}
     */
    public function isCollectionEnabledForSite(string $site, string $collectionHandle): bool
    {
        IwriLog::add('sites.isEnabled', $site, $collectionHandle);

        return $this->enabled;
    }

    /**
     * {@inheritDoc}
     */
    public function enableCollectionForSite(string $site, string $collectionHandle, ?OutputInterface $output = null): bool
    {
        IwriLog::add('sites.enable', $site, $collectionHandle);

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function enableDestinationForSite(string $site, string $handle, ?OutputInterface $output = null): bool
    {
        IwriLog::add('sites.enableDestination', $site, $handle);

        return ! in_array($handle, $this->missingDestinations, true);
    }
}

/**
 * A navigation selector that finds no active menu.
 */
final class IwriNavigation extends NavigationMenuSelector
{
    /**
     * {@inheritDoc}
     */
    public function confirmActivePrimaryNavigation(XmlParser $xmlParser, InputInterface $input, OutputInterface $output, Command $command): bool
    {
        IwriLog::add('navigation.confirm');

        return false;
    }
}

beforeEach(function (): void {
    IwriLog::$calls = [];
    IwriEntries::$onCreate = null;
    IwriTypes::$prefer = null;
    config(['wordpress-import.measure_filesize' => false]);
    Site::setSites([
        'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
        'other' => ['name' => 'Other', 'locale' => 'en_US', 'url' => '/other/'],
    ]);
    $this->root = sys_get_temp_dir().'/iwri-'.bin2hex(random_bytes(6));
    mkdir($this->root);
});

afterEach(function (): void {
    SiteResolver::clearSelectedSite();
    exec('rm -rf '.escapeshellarg($this->root));
});

/**
 * Save a collection for the given sites.
 *
 * @param  list<string>  $sites  The site handles
 */
function iwriCollection(string $handle, array $sites = ['default']): void
{
    $collection = CollectionFacade::make($handle);
    $collection->sites($sites);
    $collection->save();
}

/**
 * Save a global set for the given sites.
 *
 * @param  list<string>  $sites  The site handles
 */
function iwriGlobal(string $handle, array $sites = ['default']): void
{
    $global = new ConcreteGlobalSet;
    $global->handle($handle);
    $global->sites($sites);
    $global->save();
}

/**
 * Write an export part of the given items, and answer its path.
 *
 * Each item is a post type, or an array of its `type`, `title`, `id`, `content` and `topics`
 * (the `kbtopic` slugs).
 *
 * @param  list<string|array<string, mixed>>  $items  The items
 */
function iwriPart(mixed $test, array $items, string $name = 'site.xml', string $baseBlogUrl = 'https://old.test'): string
{
    $root = is_object($test) ? ($test->root ?? null) : null;

    if (! is_string($root)) {
        throw new LogicException('No test directory.');
    }

    $xml = '';

    foreach ($items as $index => $item) {
        $item = is_string($item) ? ['type' => $item] : $item;
        $topics = '';

        foreach (is_array($item['topics'] ?? null) ? $item['topics'] : [] as $topic) {
            $topics .= '<category domain="kbtopic" nicename="'.(is_string($topic) ? $topic : '').'"><![CDATA[Topic]]></category>';
        }

        $field = static fn (string $key, string $default): string => is_string($item[$key] ?? null) ? $item[$key] : $default;

        $xml .= '<item><title><![CDATA['.$field('title', 'Item '.$index).']]></title><link>https://old.test/'.$index.'/</link>'
            .'<wp:post_id>'.$field('id', (string) (100 + $index)).'</wp:post_id>'
            .'<wp:post_type><![CDATA['.$field('type', 'page').']]></wp:post_type><wp:status><![CDATA[publish]]></wp:status>'
            .'<content:encoded><![CDATA['.$field('content', '<p>Text</p>').']]></content:encoded>'.$topics.'</item>';
    }

    $path = $root.'/'.$name;
    file_put_contents($path, '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wp="http://wordpress.org/export/1.2/"><channel>'
        .'<wp:base_blog_url>'.$baseBlogUrl.'</wp:base_blog_url>'.$xml.'</channel></rss>');

    return $path;
}

/**
 * A stand-in built without its constructor, which its recording methods do not need.
 *
 * @template T of object
 *
 * @param  class-string<T>  $class  The stand-in's class
 * @return T
 */
function iwriBare(string $class): object
{
    return (new ReflectionClass($class))->newInstanceWithoutConstructor();
}

/**
 * The command, built by the container with recording stand-ins for every collaborator it
 * hands work to, the given arguments and options, and the buffer behind its output.
 *
 * @param  array<string, mixed>  $parameters  The arguments and options, as `ArrayInput` takes them
 * @return array{IwriCommand, LfBufferedOutput, array{sites: IwriSites, entries: IwriEntries, siteManager: IwriSiteManager}}
 */
function iwriCommand(array $parameters = []): array
{
    $command = app(IwriCommand::class);
    $sites = new IwriSites;
    $siteManager = new IwriSiteManager;
    $entries = iwriBare(IwriEntries::class);
    $entries->sites = $siteManager;
    $stand = [
        'formPluginCoordinator' => iwriBare(IwriForms::class),
        'siteSelector' => $sites,
        'contentTypeSelector' => iwriBare(IwriTypes::class),
        'taxonomyOverwriteBehaviorSelector' => iwriBare(IwriTaxonomyOverwrite::class),
        'minervaKbSettingsSelector' => iwriBare(IwriMinerva::class),
        'entryCreator' => $entries,
        'kbEntryCreator' => iwriBare(IwriKb::class),
        'treeBuilder' => iwriBare(IwriTree::class),
        'globalCreator' => iwriBare(IwriGlobals::class),
        'assetImporter' => new IwriAssets('https://old.test'),
        'deferredProcessingCoordinator' => new IwriDeferred,
        'siteManager' => $siteManager,
        'navigationMenuSelector' => iwriBare(IwriNavigation::class),
        'bardContentSanitizer' => new BardContentSanitizer,
    ];

    foreach ($stand as $property => $stub) {
        (new ReflectionProperty(ImportWordPress::class, $property))->setValue($command, $stub);
    }

    $input = new ArrayInput($parameters, $command->getDefinition());
    $input->setInteractive(false);
    $buffer = new LfBufferedOutput;
    $command->setInput($input);
    $command->setOutput(new OutputStyle($input, $buffer));

    return [$command, $buffer, ['sites' => $sites, 'entries' => $entries, 'siteManager' => $siteManager]];
}

/**
 * Run the import of the given parts, and answer its status and its output with plain line
 * endings.
 *
 * @param  list<string>  $parts  The part paths
 * @return array{int, string}
 */
function iwriRun(ImportWordPress $command, LfBufferedOutput $buffer, array $parts): array
{
    $status = (new ReflectionMethod(ImportWordPress::class, 'runImportForParts'))->invoke($command, $parts);

    return [is_int($status) ? $status : -1, str_replace(PHP_EOL, "\n", $buffer->fetch())];
}

it('imports pages into the pages collection, building their tree, then runs the closing steps', function (): void {
    iwriCollection('pages');
    $part = iwriPart($this, ['page', 'page']);
    [$command, $buffer] = iwriCommand();

    [$status, $output] = iwriRun($command, $buffer, [$part]);

    expect($status)->toBe(0)
        ->and(IwriLog::names())->toBe([
            'forms.detect',
            'site.select',
            'site.validate',
            'assets.baseBlogUrl',
            'types.select',
            'sites.isEnabled',
            'entries.entryOverwrite',
            'taxonomyOverwrite.select',
            'entries.taxonomyOverwrite',
            'assets.overwrite',
            'entries.xmlParser',
            'entries.assetImporter',
            'entries.create',
            'tree.build',
            'navigation.confirm',
            'deferred.final',
            'forms.clear',
        ])
        ->and(IwriLog::of('entries.create')[0][0] ?? null)->toBe('default')
        ->and(IwriLog::of('entries.create')[0][1] ?? null)->toBe('page')
        ->and(IwriLog::of('entries.create')[0][2] ?? null)->toBe('pages')
        ->and(IwriLog::of('entries.create')[0][6] ?? null)->toBe('https://old.test')
        ->and(IwriLog::of('tree.build')[0])->toMatchArray([0 => 'default', 1 => 'page', 2 => 'pages', 3 => ['12' => ['entry' => 'about-12-abc']], 5 => 'https://old.test'])
        ->and($output)->toBe(
            "Starting WordPress import from: {$part}\n"
            ."Current memory limit: -1\n"
            ."Analyzing content item sizes...\n"
            ."Largest content item: 0MB\n"
            ."Memory limit appears sufficient for this import.\n"
            ."Using streaming parser for memory efficiency.\n"
            ."Content types found in the XML:\n"
            ."+--------------+-------+\n"
            ."| Content Type | Count |\n"
            ."+--------------+-------+\n"
            ."| page         | 2     |\n"
            ."+--------------+-------+\n"
            ."All content types in XML are supported.\n"
            ."Selected site 'default' is valid.\n"
            ."No department_organization designation is recorded for site 'default' - its news page will render as a plain listing.\n"
            ."Selected content type: 'page'\n"
            ."Stache cache cleared before navigation import.\n"
            ."Skipping navigation menu import - no active menu in primary navigation position\n"
            ."Laravel cache cleared.\n"
            ."Sanitizing Bard content in entries...\n"
            ."  No Bard content required sanitization.\n"
            ."      - Rebuilding search indexes for imported content...\n"
            ."          - Rebuilt search index 'default'.\n"
            ."          - Rebuilt search index 'cp'.\n"
            ."Import process complete.\n"
        )
        ->and(SiteResolver::getCurrentSite())->toBe('default');
});

it('refuses an unsupported-content policy it does not know, before anything runs', function (): void {
    [$command, $buffer] = iwriCommand(['--unsupported-content' => 'ignore']);

    [$status, $output] = iwriRun($command, $buffer, [iwriPart($this, ['page'])]);

    expect($status)->toBe(1)
        ->and(IwriLog::names())->toBe([])
        ->and($output)->toBe("The --unsupported-content option must be one of: abort, skip, import.\n");
});

it('measures the codebase before and after the import when asked to', function (): void {
    config(['wordpress-import.measure_filesize' => true]);
    iwriCollection('pages');
    [$command, $buffer] = iwriCommand();

    [$status, $output] = iwriRun($command, $buffer, [iwriPart($this, ['page'])]);

    expect($status)->toBe(0)
        ->and($output)->toMatch('/Measuring initial filesize of the codebase\.\.\.\nInitial application directory filesize: [0-9.]+ MB\n/')
        ->and($output)->toMatch('/Measuring final filesize of the codebase\.\.\.\n\nFilesize difference after import:\n  Bytes: -?[0-9]+\n/')
        ->and((new ReflectionProperty(ImportWordPress::class, 'initialFilesize'))->getValue($command))->toBeGreaterThan(0);
});

/**
 * An unreadable part ends the run with the parse step's message and status 1, whichever step
 * reads it first (#289). The memory check reads the parts before the parse, so it is the one
 * that meets the reader's warning. libxml reports through PHP warnings only while its internal
 * error buffering is off, as it is in a fresh process like the command's, so the test sets
 * that state rather than inheriting whatever an earlier test left.
 */
it('ends the run with the parse step\'s message for an export it cannot read', function (string $kind, string $reader, string $detail): void {
    $part = $this->root.'/broken.xml';

    if ($kind === 'malformed') {
        file_put_contents($part, '<?xml version="1.0"?><rss><channel><item><wp:post_type>page</wp:post_type>');
    }

    [$command, $buffer] = iwriCommand();
    $buffering = libxml_use_internal_errors(false);

    try {
        [$status, $output] = iwriRun($command, $buffer, [$part]);
    } finally {
        libxml_use_internal_errors($buffering);
    }

    expect($status)->toBe(1)
        ->and(IwriLog::names())->toBe([])
        ->and($output)->toContain("Analyzing content item sizes...\nFailed to parse XML file: {$reader}")
        ->and($output)->toContain($detail)
        ->and($output)->not->toContain('Using streaming parser');
})->with([
    'missing' => ['missing', 'XMLReader::open(): Unable to open source data', 'Unable to open source data'],
    'malformed' => ['malformed', 'XMLReader::read(): ', 'Namespace prefix wp on post_type is not defined'],
]);

it('stops when the export holds no supported content type', function (): void {
    [$command, $buffer] = iwriCommand();

    [$status, $output] = iwriRun($command, $buffer, [iwriPart($this, ['attachment', 'revision'])]);

    expect($status)->toBe(1)
        ->and(IwriLog::names())->toBe(['forms.detect'])
        ->and($output)->toEndWith("No supported content types (page, post, uamspeople, redirect_rule, tablepress_table, kb) found in the XML.\n");
});

it('lists the content types it does not import', function (): void {
    iwriCollection('pages');
    [$command, $buffer] = iwriCommand();

    [, $output] = iwriRun($command, $buffer, [iwriPart($this, ['page', 'attachment', 'revision'])]);

    expect($output)->toContain("Unsupported content types: attachment, revision\n")
        ->and($output)->not->toContain('All content types in XML are supported.');
});

it('asks for the MinervaKB settings when the export holds knowledge base articles, and hands them on', function (): void {
    iwriCollection('pages');
    [$command, $buffer] = iwriCommand(['--content-type' => 'page']);

    [$status] = iwriRun($command, $buffer, [iwriPart($this, ['page', ['type' => 'kb', 'topics' => ['billing']]])]);

    expect($status)->toBe(0)
        ->and(IwriLog::of('minerva.select'))->toHaveCount(1)
        ->and(IwriLog::of('entries.create')[0][7] ?? null)->toBe(['kb_slug' => 'kb']);
});

it('asks for no MinervaKB settings without knowledge base articles', function (): void {
    iwriCollection('pages');
    [$command, $buffer] = iwriCommand();

    iwriRun($command, $buffer, [iwriPart($this, ['page'])]);

    expect(IwriLog::of('minerva.select'))->toBe([])
        ->and(IwriLog::of('entries.create')[0])->toHaveKey(7)
        ->and(IwriLog::of('entries.create')[0][7])->toBeNull();
});

it('refuses a --content-type the export does not hold, naming those it does', function (string $requested): void {
    [$command, $buffer] = iwriCommand(['--content-type' => $requested]);

    [$status, $output] = iwriRun($command, $buffer, [iwriPart($this, ['page', 'post', 'nav_menu_item'])]);

    expect($status)->toBe(1)
        ->and(IwriLog::names())->toBe(['forms.detect'])
        ->and($output)->toEndWith("'{$requested}' is not a supported content type present in this export.\nAvailable: page, post.\n");
})->with(['kb', 'nav_menu_item', 'pages']);

it('imports only the content type --content-type names, trimmed, and succeeds', function (): void {
    iwriCollection('posts');
    [$command, $buffer] = iwriCommand(['--content-type' => '  post ']);

    [$status] = iwriRun($command, $buffer, [iwriPart($this, ['page', 'post'])]);

    expect($status)->toBe(0)
        ->and(IwriLog::of('types.select'))->toBe([[['post']]])
        ->and(IwriLog::of('entries.create')[0][1] ?? null)->toBe('post');
});

it('offers every supported type when --content-type is blank', function (): void {
    iwriCollection('pages');
    [$command, $buffer] = iwriCommand(['--content-type' => '   ']);

    iwriRun($command, $buffer, [iwriPart($this, ['page', 'post'])]);

    expect(IwriLog::of('types.select'))->toBe([[['page', 'post']]]);
});

it('stops when the selected site is not configured', function (): void {
    [$command, $buffer, $stands] = iwriCommand();
    $stands['sites']->site = 'nowhere';
    $stands['sites']->valid = false;

    [$status, $output] = iwriRun($command, $buffer, [iwriPart($this, ['page'])]);

    expect($status)->toBe(1)
        ->and(IwriLog::names())->toBe(['forms.detect', 'site.select', 'site.validate'])
        ->and(IwriLog::of('site.validate'))->toBe([['nowhere']])
        ->and(SiteResolver::getCurrentSite())->toBe('nowhere')
        ->and($output)->toEndWith("The site 'nowhere' does not exist in the configuration.\n");
});

it('tells the importers the export\'s base blog URL', function (): void {
    iwriCollection('pages');
    [$command, $buffer] = iwriCommand();

    iwriRun($command, $buffer, [iwriPart($this, ['page'], 'site.xml', 'https://network.test/site')]);

    expect(IwriLog::of('assets.baseBlogUrl'))->toBe([['https://network.test/site']])
        ->and(IwriLog::of('entries.create')[0][6] ?? null)->toBe('https://network.test/site');
});

it('skips the asset overwrite question when no asset is uploaded', function (bool $images, bool $files, string $behavior, bool $asked): void {
    config(['wordpress-import.upload_images' => $images, 'wordpress-import.upload_files' => $files]);
    iwriCollection('pages');
    [$command, $buffer] = iwriCommand();

    [, $output] = iwriRun($command, $buffer, [iwriPart($this, ['page'])]);

    expect(IwriLog::of('assets.overwrite'))->toBe([[$behavior]])
        ->and(str_contains($output, 'Asset uploads disabled (images and files off); skipping overwrite prompt.'))->toBe(! $asked);
})->with([
    'both off' => [false, false, 'Skip matching files', false],
    'images on' => [true, false, 'Overwrite matching files', true],
    'files on' => [false, true, 'Overwrite matching files', true],
]);

it('asks how to treat matching tables only for content holding TablePress', function (string $content, array $behaviors): void {
    iwriCollection('pages');
    [$command, $buffer] = iwriCommand();

    iwriRun($command, $buffer, [iwriPart($this, [['type' => 'page', 'content' => $content]])]);

    expect(IwriLog::of('entries.tableOverwrite'))->toBe($behaviors);
})->with([
    'a table' => ['[table id=3 /]', [['Overwrite matching files']]],
    'none' => ['<p>Text</p>', []],
]);

/**
 * Content holding the block the given unsupported-content gate stops for.
 *
 * @return array<string, string>
 */
function iwriGateContent(): array
{
    return [
        'Classic' => '<!-- wp:paragraph --><p>Block</p><!-- /wp:paragraph -->Classic text',
        'Freeform' => '<!-- wp:freeform -->x<!-- /wp:freeform -->',
        'Details' => '<!-- wp:details -->x<!-- /wp:details -->',
    ];
}

it('aborts the run on unsupported block content by default, naming each page', function (string $gate, string $warning): void {
    iwriCollection('pages');
    [$command, $buffer] = iwriCommand();

    [$status, $output] = iwriRun($command, $buffer, [iwriPart($this, [['type' => 'page', 'title' => 'R&amp;D', 'id' => '7', 'content' => iwriGateContent()[$gate]]])]);

    expect($status)->toBe(1)
        ->and(IwriLog::of('entries.create'))->toBe([])
        ->and($output)->toContain("{$warning} detected in 'page' content:\n - Title: 'R&D', ID: 7, Permalink: https://old.test/0/\n")
        ->and($output)->toContain('not currently mapped to the Statamic Bard format.')
        ->and($output)->toEndWith("Aborting import of 'page' due to {$gate} block presence (see affected pages above).\n");
})->with([
    'Classic' => ['Classic', 'Mixed Classic and Gutenberg block content'],
    'Freeform' => ['Freeform', 'Freeform block content'],
    'Details' => ['Details', 'Details block content'],
]);

it('skips a content type with unsupported block content when told to, and goes on to the next', function (string $gate): void {
    iwriCollection('pages');
    iwriCollection('posts');
    [$command, $buffer] = iwriCommand(['--unsupported-content' => 'skip']);

    [$status, $output] = iwriRun($command, $buffer, [iwriPart($this, [['type' => 'page', 'content' => iwriGateContent()[$gate]], 'post'])]);

    expect($status)->toBe(0)
        ->and(IwriLog::of('types.select'))->toBe([[['page', 'post']], [['post']]])
        ->and(array_map(static fn (array $call): mixed => $call[1], IwriLog::of('entries.create')))->toBe(['post'])
        ->and($output)->toContain("Skipping import of 'page' due to {$gate} block presence.\n");
})->with(['Classic', 'Freeform', 'Details']);

it('imports unsupported block content when told to, handing the pages to the entry creator', function (string $gate, string $call): void {
    iwriCollection('pages');
    [$command, $buffer] = iwriCommand(['--unsupported-content' => 'import']);

    [$status] = iwriRun($command, $buffer, [iwriPart($this, [['type' => 'page', 'title' => 'Page', 'id' => '7', 'content' => iwriGateContent()[$gate]]])]);

    expect($status)->toBe(0)
        ->and(IwriLog::of($call))->toBe([[[['title' => 'Page', 'id' => '7', 'link' => 'https://old.test/0/']]]])
        ->and(IwriLog::of('entries.create'))->toHaveCount(1);
})->with([
    'Classic' => ['Classic', 'entries.classic'],
    'Freeform' => ['Freeform', 'entries.freeform'],
    'Details' => ['Details', 'entries.details'],
]);

it('hands the entry creator nothing about content with no unsupported blocks', function (): void {
    iwriCollection('pages');
    [$command, $buffer] = iwriCommand();

    iwriRun($command, $buffer, [iwriPart($this, ['page'])]);

    expect(array_intersect(IwriLog::names(), ['entries.classic', 'entries.freeform', 'entries.details', 'entries.kbWithoutTopics']))->toBe([]);
});

it('imports knowledge base articles through the knowledge base creator, with no entries and no tree', function (): void {
    iwriCollection('knowledge_bases');
    [$command, $buffer] = iwriCommand();

    [$status] = iwriRun($command, $buffer, [iwriPart($this, [['type' => 'kb', 'topics' => ['billing']]])]);

    expect($status)->toBe(0)
        ->and(IwriLog::of('entries.create'))->toBe([])
        ->and(IwriLog::of('tree.build'))->toBe([])
        ->and(IwriLog::of('kb.taxonomyOverwrite'))->toBe([['Overwrite existing taxonomy terms']])
        ->and(IwriLog::of('kb.process')[0][3] ?? null)->toBe('https://old.test')
        ->and(IwriLog::of('kb.process')[0][4] ?? null)->toBe('default')
        ->and(IwriLog::of('kb.process')[0][7] ?? null)->toBe(['kb_slug' => 'kb']);
});

it('aborts, skips or imports knowledge base articles without a topic as the policy says', function (string $policy, int $status, string $line, array $handed): void {
    iwriCollection('knowledge_bases');
    [$command, $buffer] = iwriCommand(['--unsupported-content' => $policy]);

    [$code, $output] = iwriRun($command, $buffer, [iwriPart($this, [['type' => 'kb', 'title' => 'How', 'id' => '7'], ['type' => 'kb', 'topics' => ['billing']]])]);

    expect($code)->toBe($status)
        ->and($output)->toContain("Knowledge Base entries without associated topics detected in 'kb' content:\n - Title: 'How', ID: 7, Permalink: https://old.test/0/\n")
        ->and($output)->toContain($line)
        ->and(IwriLog::of('entries.kbWithoutTopics'))->toBe($handed);
})->with([
    'abort' => ['abort', 1, "Aborting import of 'kb' due to entries without associated topics (see affected entries above).\n", []],
    'skip' => ['skip', 0, "Skipping import of 'kb' due to entries without associated topics.\n", []],
    'import' => ['import', 0, "Selected content type: 'kb'\n", [[[['title' => 'How', 'id' => '7', 'link' => 'https://old.test/0/']]]]],
]);

/**
 * Today's behavior: the store is looked up by the collection handle rather than as a child of
 * `entries`, so it is never found (#292).
 */
it('enables the destination collection for the site, with no Stache store clear (#292)', function (): void {
    iwriCollection('pages', ['other']);
    [$command, $buffer, $stands] = iwriCommand();
    $stands['siteManager']->enabled = false;

    [$status, $output] = iwriRun($command, $buffer, [iwriPart($this, ['page'])]);

    expect($status)->toBe(0)
        ->and(IwriLog::of('sites.enable'))->toBe([['default', 'pages']])
        ->and($output)->toContain("Enabled 'default' for collection 'pages'.\n")
        ->and($output)->not->toContain("Stache cache for 'pages'");
});

it('leaves an enabled destination collection alone', function (): void {
    iwriCollection('pages');
    [$command, $buffer] = iwriCommand();

    [, $output] = iwriRun($command, $buffer, [iwriPart($this, ['page'])]);

    expect(IwriLog::of('sites.enable'))->toBe([])
        ->and($output)->not->toContain('Enabled ');
});

/**
 * A content type whose destination collection does not exist imports nothing, so it is named
 * with the types skipped at a gate and the run fails (#291).
 */
it('skips a content type whose destination collection does not exist, and fails the run', function (): void {
    [$command, $buffer] = iwriCommand(['--content-type' => 'page']);

    [$status, $output] = iwriRun($command, $buffer, [iwriPart($this, ['page'])]);

    expect($status)->toBe(1)
        ->and(IwriLog::of('entries.create'))->toBe([])
        ->and($output)->toContain("The 'pages' collection does not exist in Statamic.\nPlease create and configure the 'pages' collection before importing 'page' content.\n")
        ->and($output)->toEndWith("Nothing was imported for the following content types, because a destination was missing:\n  - page (needs the 'pages' collection)\nCreate the missing destinations and re-run; the import is incomplete.\n");
});

it('imports redirect rules into the redirects global set, asking how to treat matching sets', function (): void {
    iwriGlobal('redirects');
    [$command, $buffer] = iwriCommand();

    [$status, $output] = iwriRun($command, $buffer, [iwriPart($this, ['redirect_rule'])]);

    expect($status)->toBe(0)
        ->and(IwriLog::of('globals.overwrite'))->toBe([['Overwrite matching sets']])
        ->and(IwriLog::of('globals.create'))->toBe([['default', 'redirect_rule', 'redirects']])
        ->and(IwriLog::of('entries.create'))->toBe([])
        ->and($output)->toContain("Skipping navigation menu import - no imported content type declares it.\n")
        ->and($output)->toContain("Skipping Bard content sanitization - no imported content type declares it.\n")
        ->and($output)->toContain("Rebuilding only the Control Panel search index - no imported content type declares the full rebuild.\n")
        ->and($output)->toContain("Rebuilt search index 'cp'.")
        ->and($output)->not->toContain("Rebuilt search index 'default'.")
        ->and(IwriLog::of('navigation.confirm'))->toBe([]);
});

it('enables the redirects global set for the site', function (): void {
    iwriGlobal('redirects', ['other']);
    [$command, $buffer] = iwriCommand();

    [, $output] = iwriRun($command, $buffer, [iwriPart($this, ['redirect_rule'])]);

    expect($output)->toContain("Enabled 'default' for global set 'redirects'.\n");
});

/**
 * As for a missing collection, a missing global set is named and the run fails (#291).
 */
it('skips redirect rules when the redirects global set does not exist, and fails the run', function (): void {
    [$command, $buffer] = iwriCommand(['--content-type' => 'redirect_rule']);

    [$status, $output] = iwriRun($command, $buffer, [iwriPart($this, ['redirect_rule'])]);

    expect($status)->toBe(1)
        ->and(IwriLog::of('globals.create'))->toBe([])
        ->and($output)->toContain("The 'redirects' global set does not exist in Statamic.\nPlease create and configure the 'redirects' global set before importing 'redirect_rule' content.\n")
        ->and($output)->toEndWith("Nothing was imported for the following content types, because a destination was missing:\n  - redirect_rule (needs the 'redirects' global set)\nCreate the missing destinations and re-run; the import is incomplete.\n");
});

it('enables the taxonomies a page\'s blocks need, skipping the pages when one is missing', function (string $block, string $handle, array $asked): void {
    iwriCollection('pages');
    [$command, $buffer, $stands] = iwriCommand();
    $stands['siteManager']->missingDestinations = [$handle];

    [$status, $output] = iwriRun($command, $buffer, [iwriPart($this, [['type' => 'page', 'content' => "<!-- wp:acf/{$block} {\"id\":\"b\"} /-->"]])]);

    expect($status)->toBe(1)
        ->and(array_map(static fn (array $call): mixed => $call[1], IwriLog::of('sites.enableDestination')))->toBe($asked)
        ->and(IwriLog::of('entries.create'))->toBe([])
        ->and($output)->toContain("Skipping import of 'page' as '{$handle}' is not enabled for 'default'.\n")
        ->and($output)->toEndWith(
            "Nothing was imported for the following content types, because a destination was missing:\n"
            ."  - page (needs '{$handle}')\n"
            ."Create the missing destinations and re-run; the import is incomplete.\n"
        );
})->with([
    'a logo list' => ['logo-list', 'logos_item_categories', ['logos_item_categories']],
    'providers' => ['fad-providers', 'regions', ['regions']],
    'locations, missing regions' => ['fad-locations', 'regions', ['regions']],
    'locations, missing location types' => ['fad-locations', 'location_types', ['regions', 'location_types']],
]);

it('imports pages once every taxonomy their blocks need is enabled', function (): void {
    iwriCollection('pages');
    [$command, $buffer] = iwriCommand();

    [$status, $output] = iwriRun($command, $buffer, [iwriPart($this, [['type' => 'page', 'content' => '<!-- wp:acf/logo-list {"id":"a"} /--><!-- wp:acf/fad-locations {"id":"b"} /-->']])]);

    expect($status)->toBe(0)
        ->and(array_map(static fn (array $call): mixed => $call[1], IwriLog::of('sites.enableDestination')))->toBe(['logos_item_categories', 'regions', 'location_types'])
        ->and(IwriLog::of('entries.create'))->toHaveCount(1)
        ->and($output)->toContain("Warning: Region taxonomy validation not yet implemented with new validator system.\nWarning: LocationType taxonomy validation not yet implemented with new validator system.\n");
});

it('warns that only region validation is missing for provider blocks', function (): void {
    iwriCollection('pages');
    [$command, $buffer] = iwriCommand();

    [, $output] = iwriRun($command, $buffer, [iwriPart($this, [['type' => 'page', 'content' => '<!-- wp:acf/fad-providers {"id":"b"} /-->']])]);

    expect($output)->toContain('Warning: Region taxonomy validation not yet implemented')
        ->and($output)->not->toContain('LocationType');
});

it('needs people groups for people and showcases, and not for pages showing people', function (string $type, string $collection, bool $asked): void {
    iwriCollection($collection);
    [$command, $buffer, $stands] = iwriCommand();
    $stands['entries']->showPeople = true;
    $stands['siteManager']->missingDestinations = ['people_groups'];

    [$status, $output] = iwriRun($command, $buffer, [iwriPart($this, [$type])]);

    expect($status)->toBe($asked ? 1 : 0)
        ->and(str_contains($output, "Skipping import of '{$type}' as 'people_groups' is not enabled for 'default'."))->toBe($asked)
        ->and(IwriLog::of('entries.hasShowPeople'))->toBe([]);
})->with([
    'people' => ['uamspeople', 'people', true],
    'a showcase' => ['tshowcase', 'people', true],
    'pages' => ['page', 'pages', false],
    'posts' => ['post', 'posts', false],
]);

it('needs classified categories for classifieds', function (): void {
    iwriCollection('classifieds');
    [$command, $buffer, $stands] = iwriCommand();
    $stands['siteManager']->missingDestinations = ['classified_categories'];

    [$status, $output] = iwriRun($command, $buffer, [iwriPart($this, ['advert'])]);

    expect($status)->toBe(1)
        ->and($output)->toContain("Skipping import of 'advert' as 'classified_categories' is not enabled for 'default'.\n");
});

it('reports the failed asset downloads of a content type, then forgets them', function (): void {
    iwriCollection('pages');
    IwriEntries::$onCreate = static function (): void {
        FailedAssetCollector::addFailedUrl('https://old.test/a.jpg', 'https://old.test/page/');
    };
    [$command, $buffer] = iwriCommand();

    [, $output] = iwriRun($command, $buffer, [iwriPart($this, ['page'])]);

    expect($output)->toContain('https://old.test/a.jpg')
        ->and(FailedAssetCollector::hasFailedDownloads())->toBeFalse();
});

it('reports the supported content types a run left untouched, and fails', function (): void {
    iwriCollection('pages');
    [$command, $buffer] = iwriCommand();

    [$status, $output] = iwriRun($command, $buffer, [iwriPart($this, ['page', 'post', 'redirect_rule'])]);

    expect($status)->toBe(1)
        ->and($output)->toEndWith(
            "Import process complete.\n\n"
            ."This export contains supported content types that were not imported:\n"
            ."  - post\n"
            ."  - redirect_rule\n"
            ."Re-run with --content-type=<handle> for each, or import them interactively.\n"
        );
});

it('defers navigation menu items to the navigation pass', function (): void {
    [$command, $buffer] = iwriCommand();

    [$status, $output] = iwriRun($command, $buffer, [iwriPart($this, ['nav_menu_item'])]);

    expect($status)->toBe(0)
        ->and(IwriLog::of('entries.create'))->toBe([])
        ->and($output)->toContain("Selected content type: 'nav_menu_item'\nNavigation menu items will be imported after all content types are processed.\n");
});

it('stops at a content type with no default mapping', function (): void {
    [$command, $buffer] = iwriCommand();
    (new ReflectionProperty(ImportWordPress::class, 'supportedContentTypes'))->setValue($command, ['event']);

    [$status, $output] = iwriRun($command, $buffer, [iwriPart($this, ['event'])]);

    expect($status)->toBe(1)
        ->and($output)->toEndWith("'event' content has no default mapping to a Statamic collection or global set.\n");
});

it('stops the cycle for a destination that is neither a collection nor a global set', function (): void {
    [$command, $buffer] = iwriCommand();
    $mappings = ImportWordPress::getDefaultMappings();
    $mappings['page'] = ['type' => 'taxonomy', 'handle' => 'pages', 'name' => 'Pages'];
    (new ReflectionProperty(ImportWordPress::class, 'defaultMappings'))->setValue($command, $mappings);

    [$status, $output] = iwriRun($command, $buffer, [iwriPart($this, ['page'])]);

    expect($status)->toBe(0)
        ->and(IwriLog::of('entries.create'))->toBe([])
        ->and($output)->toContain("Taxonomy import not implemented yet. Stopping this cycle.\n");
});

it('runs the final deferred pass for the site, and forgets the site afterwards', function (): void {
    iwriCollection('pages', ['other']);
    [$command, $buffer, $stands] = iwriCommand();
    $stands['sites']->site = 'other';

    iwriRun($command, $buffer, [iwriPart($this, ['page'])]);

    expect(IwriLog::of('deferred.final'))->toBe([['other']])
        ->and(IwriLog::of('entries.create')[0][0] ?? null)->toBe('other');
});

/**
 * A Stache store keyed as a collection is, so a lookup of a collection's store by its handle
 * would find one. It records each clear.
 */
final class IwriPagesStore extends Store
{
    /**
     * How many times the store was cleared.
     */
    public static int $cleared = 0;

    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return 'pages';
    }

    /**
     * {@inheritDoc}
     */
    public function getItem(mixed $key): mixed
    {
        return null;
    }

    /**
     * {@inheritDoc}
     *
     * @return Collection<int, mixed>
     */
    public function getItemValues(mixed $keys, mixed $valueIndex, mixed $keyIndex): Collection
    {
        return collect();
    }

    /**
     * {@inheritDoc}
     */
    public function clear(): void
    {
        self::$cleared++;
    }
}

/**
 * Run the import of the given parts inside `Command::run()`, answering its prompts from the
 * given lines, and answer its status and output. The prompts are routed to Symfony questions
 * on every platform there (see `tests/CLAUDE.md`).
 *
 * @param  list<string>  $parts  The part paths
 * @return array{int, string}
 */
function iwriInteractive(ImportWordPress $command, array $parts, string $answers): array
{
    $stream = fopen('php://memory', 'r+');
    assert(is_resource($stream));
    fwrite($stream, $answers);
    rewind($stream);

    $input = new ArrayInput([]);
    $input->setStream($stream);
    $buffer = new LfBufferedOutput;

    $runner = new class($command, $parts) extends Command
    {
        protected $signature = 'test:iwri-run';

        /**
         * The status the import answered.
         */
        public int $status = -1;

        /**
         * @param  list<string>  $parts  The part paths
         */
        public function __construct(private readonly ImportWordPress $import, private readonly array $parts)
        {
            parent::__construct();
            $this->setDefinition($import->getDefinition());
        }

        public function handle(): int
        {
            $this->import->setInput($this->input);
            $this->import->setOutput($this->output);
            (new ReflectionProperty(Command::class, 'components'))->setValue($this->import, $this->components);
            $status = (new ReflectionMethod(ImportWordPress::class, 'runImportForParts'))->invoke($this->import, $this->parts);
            $this->status = is_int($status) ? $status : -1;

            return self::SUCCESS;
        }
    };

    $runner->setLaravel(app());
    $command->setLaravel(app());
    $runner->run($input, $buffer);

    return [$runner->status, str_replace(PHP_EOL, "\n", $buffer->fetch())];
}

it('starts from a clean slate: no deferred links, warnings, failed downloads or term caches from before', function (): void {
    iwriCollection('pages');
    LinkProcessor::setDeferredInternalLinks(['stale-1' => []]);
    ImportWarningManager::addWarning('content', 'dataless_entries_removed', ['path' => 'stale-warning-path']);
    FailedAssetCollector::addFailedUrl('https://old.test/stale.jpg', 'https://old.test/');
    (new ReflectionProperty(BaseTaxonomyValidator::class, 'existingTermsCache'))->setValue(null, ['stale' => []]);
    (new ReflectionProperty(BaseEntryCreator::class, 'siteNamesCache'))->setValue(null, ['stale' => 'Stale']);
    [$command, $buffer] = iwriCommand();

    [, $output] = iwriRun($command, $buffer, [iwriPart($this, ['page'])]);

    expect(LinkProcessor::getDeferredInternalLinks())->toBe([])
        ->and($output)->not->toContain('stale-warning-path')
        ->and($output)->not->toContain('stale.jpg')
        ->and((new ReflectionProperty(BaseTaxonomyValidator::class, 'existingTermsCache'))->getValue())->toBe([])
        ->and((new ReflectionProperty(BaseEntryCreator::class, 'siteNamesCache'))->getValue())->toBe([]);
});

it('measures the codebase and uploads assets when the settings are not given', function (): void {
    $settings = config('wordpress-import');
    config(['wordpress-import' => array_diff_key(is_array($settings) ? $settings : [], array_flip(['measure_filesize', 'upload_images', 'upload_files']))]);
    iwriCollection('pages');
    [$command, $buffer] = iwriCommand();

    [, $output] = iwriRun($command, $buffer, [iwriPart($this, ['page'])]);

    expect($output)->toContain('Measuring initial filesize of the codebase...')
        ->and($output)->toContain('Measuring final filesize of the codebase...')
        ->and(IwriLog::of('assets.overwrite'))->toBe([['Overwrite matching files']]);
});

it('says why knowledge base articles without a topic will not be imported', function (): void {
    iwriCollection('knowledge_bases');
    [$command, $buffer] = iwriCommand(['--unsupported-content' => 'skip']);

    [, $output] = iwriRun($command, $buffer, [iwriPart($this, [['type' => 'kb', 'title' => 'How', 'id' => '7']])]);

    expect($output)->toContain('Knowledge Base entries without associated topics will not be imported. To include these entries, resolve the issue on the source site by assigning topics to all entries, then re-run the WordPress export tool and restart this importer with the new XML file.');
});

/**
 * @param  list<array<string, mixed>|string>  $items  The items of the type skipped
 * @param  string  $skipped  The type skipped, which the selector answers first
 * @param  Closure(IwriSiteManager): void  $setUp  Readies the gate that skips them
 */
it('goes on to the next content type after one skipped at any gate', function (array $items, string $skipped, Closure $setUp): void {
    iwriCollection('posts');
    [$command, $buffer, $stands] = iwriCommand(['--unsupported-content' => 'skip']);
    $setUp($stands['siteManager']);
    IwriTypes::$prefer = $skipped;
    /** @var list<array<string, mixed>|string> $items */
    iwriRun($command, $buffer, [iwriPart($this, [...$items, 'post'])]);

    expect(IwriLog::of('types.select')[0][0] ?? null)->toContain($skipped)
        ->and(array_map(static fn (array $call): mixed => $call[1], IwriLog::of('entries.create')))->toBe(['post']);
})->with([
    'a knowledge base article without a topic' => [[['type' => 'kb', 'id' => '7']], 'kb', static function (IwriSiteManager $siteManager): void {
        iwriCollection('knowledge_bases');
    }],
    'a missing destination collection' => [['page'], 'page', static function (IwriSiteManager $siteManager): void {}],
    'a missing destination global set' => [['redirect_rule'], 'redirect_rule', static function (IwriSiteManager $siteManager): void {}],
    'a missing logo list taxonomy' => [[['type' => 'page', 'content' => '<!-- wp:acf/logo-list {"id":"b"} /-->']], 'page', static function (IwriSiteManager $siteManager): void {
        iwriCollection('pages');
        $siteManager->missingDestinations = ['logos_item_categories'];
    }],
    'a missing regions taxonomy' => [[['type' => 'page', 'content' => '<!-- wp:acf/fad-providers {"id":"b"} /-->']], 'page', static function (IwriSiteManager $siteManager): void {
        iwriCollection('pages');
        $siteManager->missingDestinations = ['regions'];
    }],
    'a missing location types taxonomy' => [[['type' => 'page', 'content' => '<!-- wp:acf/fad-locations {"id":"b"} /-->']], 'page', static function (IwriSiteManager $siteManager): void {
        iwriCollection('pages');
        $siteManager->missingDestinations = ['location_types'];
    }],
    'missing people groups' => [['uamspeople'], 'uamspeople', static function (IwriSiteManager $siteManager): void {
        iwriCollection('people');
        $siteManager->missingDestinations = ['people_groups'];
    }],
    'missing classified categories' => [['advert'], 'advert', static function (IwriSiteManager $siteManager): void {
        iwriCollection('classifieds');
        $siteManager->missingDestinations = ['classified_categories'];
    }],
]);

/**
 * @param  array<string, bool>  $settings  The upload settings given; the other is left out
 */
it('uploads assets when either upload setting is left out, as it defaults to on', function (array $settings): void {
    $config = config('wordpress-import');
    config(['wordpress-import' => [...array_diff_key(is_array($config) ? $config : [], array_flip(['upload_images', 'upload_files'])), ...$settings]]);
    iwriCollection('pages');
    [$command, $buffer] = iwriCommand();

    iwriRun($command, $buffer, [iwriPart($this, ['page'])]);

    expect(IwriLog::of('assets.overwrite'))->toBe([['Overwrite matching files']]);
})->with([
    'images left out, files off' => [['upload_files' => false]],
    'files left out, images off' => [['upload_images' => false]],
]);

/**
 * Enabling a collection clears no store, even one keyed by the collection's handle: the only
 * clear is the one before the navigation pass (#292).
 */
it('clears no Stache store when it enables a collection', function (): void {
    iwriCollection('pages', ['other']);
    IwriPagesStore::$cleared = 0;
    Stache::registerStore(new IwriPagesStore);
    [$command, $buffer, $stands] = iwriCommand();
    $stands['siteManager']->enabled = false;

    [, $output] = iwriRun($command, $buffer, [iwriPart($this, ['page'])]);

    expect($output)->toContain("Enabled 'default' for collection 'pages'.\n")
        ->and($output)->not->toContain("Stache cache for 'pages'")
        ->and(IwriPagesStore::$cleared)->toBe(1);
});

it('clears the Stache before the navigation pass', function (): void {
    iwriCollection('pages');
    IwriPagesStore::$cleared = 0;
    Stache::registerStore(new IwriPagesStore);
    [$command, $buffer] = iwriCommand();

    iwriRun($command, $buffer, [iwriPart($this, ['page'])]);

    expect(IwriPagesStore::$cleared)->toBe(1);
});

it('localizes the redirects global set for the site it enables', function (): void {
    iwriGlobal('redirects', ['other']);
    [$command, $buffer] = iwriCommand();
    expect(GlobalSet::findByHandle('redirects')?->in('default'))->toBeNull();

    iwriRun($command, $buffer, [iwriPart($this, ['redirect_rule'])]);

    expect(IwriLog::of('globals.localized'))->toBe([[true]]);
});

it('asks for the taxonomies a page\'s blocks need only for pages', function (): void {
    iwriCollection('posts');
    [$command, $buffer, $stands] = iwriCommand();
    $stands['siteManager']->missingDestinations = ['logos_item_categories', 'regions', 'location_types'];

    [$status] = iwriRun($command, $buffer, [iwriPart($this, [['type' => 'post', 'content' => '<!-- wp:acf/logo-list {"id":"a"} /--><!-- wp:acf/fad-locations {"id":"b"} /-->']])]);

    expect($status)->toBe(0)
        ->and(IwriLog::of('sites.enableDestination'))->toBe([])
        ->and(IwriLog::of('entries.create'))->toHaveCount(1);
});

it('answers each overwrite question with its first option when it cannot ask', function (): void {
    iwriCollection('pages');
    iwriGlobal('redirects');
    [$command, $buffer] = iwriCommand();

    iwriRun($command, $buffer, [iwriPart($this, [['type' => 'page', 'content' => '[table id=3 /]']])]);
    [$command, $buffer] = iwriCommand();
    iwriRun($command, $buffer, [iwriPart($this, ['redirect_rule'], 'redirects.xml')]);

    expect(IwriLog::of('entries.entryOverwrite'))->toBe([['Overwrite matching files']])
        ->and(IwriLog::of('assets.overwrite'))->toBe([['Overwrite matching files']])
        ->and(IwriLog::of('entries.tableOverwrite'))->toBe([['Overwrite matching files']])
        ->and(IwriLog::of('globals.overwrite'))->toBe([['Overwrite matching sets']]);
});

/**
 * A question as the given prompt mechanism renders it, followed by its answer line.
 *
 * @param  list<string>  $options  The options, in order
 */
function iwriQuestion(bool $prompts, string $label, array $options, string $default): string
{
    if ($prompts) {
        $lines = array_map(static fn (int $index, string $option): string => '  '.$option.' '.str_repeat('.', 73 - strlen($option)).' '.$index.'  ', array_keys($options), $options);

        return "  {$label} [{$default}]\n".implode("\n", $lines)."\n\u{276F} \n";
    }

    $lines = array_map(static fn (int $index, string $option): string => "  [{$index}] {$option}", array_keys($options), $options);

    return " {$label} [{$default}]:\n".implode("\n", $lines)."\n > \n";
}

it('asks each overwrite question, and whether to import more, when it can', function (bool $prompts, string $tableLabel): void {
    iwriCollection('pages');
    iwriGlobal('redirects');
    [$command] = iwriCommand();
    $command->prompts = $prompts;
    $files = ['Overwrite matching files', 'Skip matching files', 'Create an additional file'];
    $sets = ['Overwrite matching sets', 'Skip matching sets', 'Create an additional set'];
    $more = $prompts
        ? "  Would you like to import more content? (yes/no) [no]\n\u{276F} \n"
        : " Would you like to import more content? (yes/no) [no]:\n > \n";

    // The questions pad their options to the terminal, and a Windows console is wider than 80
    $columns = \getenv('COLUMNS');
    \putenv('COLUMNS=80');

    try {
        // Pages: entries, assets and tables all "Create", then import more. Redirects: sets
        // "Create", then press Enter to stop at the default, no.
        [$status, $output] = iwriInteractive($command, [iwriPart($this, [['type' => 'page', 'content' => '[table id=3 /]'], 'redirect_rule'])], "2\n2\n2\nyes\n2\n\n");
    } finally {
        \putenv($columns === false ? 'COLUMNS' : "COLUMNS={$columns}");
    }

    expect($status)->toBe(0)
        ->and(IwriLog::of('entries.entryOverwrite'))->toBe([['Create an additional file']])
        ->and(IwriLog::of('assets.overwrite'))->toBe([['Create an additional file']])
        ->and(IwriLog::of('entries.tableOverwrite'))->toBe([['Create an additional file']])
        ->and(IwriLog::of('globals.overwrite'))->toBe([['Create an additional set']])
        ->and(IwriLog::of('types.select'))->toBe([[['page', 'redirect_rule']], [['redirect_rule']]])
        ->and($output)->toContain("Selected content type: 'page'\n\n"
            .iwriQuestion($prompts, "How should existing entries in the 'pages' collection with matching IDs be handled?", $files, $files[0])
            .iwriQuestion($prompts, 'How should existing assets in the relevant container be handled?', $files, $files[0])
            .iwriQuestion($prompts, $tableLabel, $files, $files[0])
            .$more
            ."Selected content type: 'redirect_rule'\n\n"
            .iwriQuestion($prompts, 'How should existing redirect sets with matching source URLs be handled?', $sets, $sets[0])
            .$more);
})->with([
    'Laravel Prompts' => [true, 'How should existing table entries with matching IDs be handled?'],
    'Symfony questions' => [false, "How should existing entries in the 'tables' collection with matching IDs be handled?"],
]);

it('shows the next question after a command runs inside the import', function (bool $prompts): void {
    iwriCollection('pages');
    [$command] = iwriCommand();
    $command->prompts = $prompts;

    // A host listener on a synchronous queue runs its command while an entry saves,
    // and a question drawn into that command's buffer would wait on the test's STDIN, so
    // the test fails here rather than blocking there
    IwriEntries::$onCreate = static function () use ($command): void {
        Artisan::call('env');

        // A test run leaves the console events off, so this says what a host app's run does
        event(new CommandFinished('env', new ArrayInput([]), new LfBufferedOutput, 0));

        expect((new ReflectionMethod(Prompt::class, 'output'))->invoke(null))
            ->toBe((new ReflectionProperty(Command::class, 'output'))->getValue($command));
    };

    [$status, $output] = iwriInteractive($command, [iwriPart($this, [['type' => 'page']])], "\n\n\n\n");

    expect($status)->toBe(0)
        ->and($output)->toContain('Would you like to import more content?');
})->with([
    'Laravel Prompts' => [true],
    'Symfony questions' => [false],
]);

it('leaves Laravel Prompts alone once the import has ended', function (): void {
    iwriCollection('pages');
    [$command] = iwriCommand();

    iwriInteractive($command, [iwriPart($this, [['type' => 'page']])], "\n\n\n\n");

    // Later commands in the process own their prompts, as this one's output shows
    $later = new LfBufferedOutput;
    Prompt::setOutput($later);
    event(new CommandFinished('later', new ArrayInput([]), $later, 0));

    $output = new ReflectionMethod(Prompt::class, 'output');

    expect($output->invoke(null))->toBe($later);
});

it('takes the first option of each overwrite question when its answer is the default', function (): void {
    iwriCollection('pages');
    [$command] = iwriCommand();

    [$status] = iwriInteractive($command, [iwriPart($this, [['type' => 'page', 'content' => '[table id=3 /]']])], "\n\n\n\n");

    expect($status)->toBe(0)
        ->and(IwriLog::of('entries.entryOverwrite'))->toBe([['Overwrite matching files']])
        ->and(IwriLog::of('assets.overwrite'))->toBe([['Overwrite matching files']])
        ->and(IwriLog::of('entries.tableOverwrite'))->toBe([['Overwrite matching files']]);
});

it('validates the logo list categories a page\'s blocks used', function (bool $termsUsed, string $line): void {
    iwriCollection('pages');
    IwriEntries::$onCreate = $termsUsed
        ? static function (): void {
            (new ReflectionProperty(LogoListParser::class, 'termsUsed'))->setValue(null, ['logos_item_categories' => ['partners' => ['name' => 'Partners']]]);
        }
    : null;
    [$command, $buffer] = iwriCommand();

    [, $output] = iwriRun($command, $buffer, [iwriPart($this, [['type' => 'page', 'content' => '<!-- wp:acf/logo-list {"id":"a"} /-->']])]);

    expect($output)->toContain("Validating logo list entries that reference 'logos_item_categories' taxonomy terms...\n")
        ->and($output)->toContain($line);
})->with([
    'none used' => [false, "  - No logo list entries found that reference 'logos_item_categories' taxonomy terms.\n"],
    'one used' => [true, "  - Checking if any taxonomy terms referenced in logo list entries don't exist in the Statamic 'logos_item_categories' taxonomy...\n"],
]);

it('forgets the terms each content type used before the next', function (): void {
    iwriCollection('pages');
    IwriEntries::$onCreate = static function (): void {
        foreach ([
            PeopleShortcodeProcessor::class,
            LogoListParser::class,
            FindADocProvidersParser::class,
            FindADocLocationsParser::class,
        ] as $class) {
            (new ReflectionProperty($class, 'termsUsed'))->setValue(null, ['taxonomy' => ['term' => []]]);
        }

        (new ReflectionProperty(BaseEntryCreator::class, 'deferredTermQueue'))->setValue(null, ['default' => ['queued']]);
    };
    [$command, $buffer] = iwriCommand();

    iwriRun($command, $buffer, [iwriPart($this, ['page'])]);

    foreach ([
        PeopleShortcodeProcessor::class,
        LogoListParser::class,
        FindADocProvidersParser::class,
        FindADocLocationsParser::class,
    ] as $class) {
        expect((new ReflectionProperty($class, 'termsUsed'))->getValue())->toBe([], $class);
    }

    expect((new ReflectionProperty(BaseEntryCreator::class, 'deferredTermQueue'))->getValue())->toBe([]);
});

it('sets the failed downloads off with a blank line on either side', function (): void {
    iwriCollection('pages');
    IwriEntries::$onCreate = static function (): void {
        FailedAssetCollector::addFailedUrl('https://old.test/a.jpg', 'https://old.test/page/');
    };
    [$command, $buffer] = iwriCommand();

    [, $output] = iwriRun($command, $buffer, [iwriPart($this, ['page'])]);

    $formatted = str_replace(PHP_EOL, "\n", (function (): string {
        FailedAssetCollector::addFailedUrl('https://old.test/a.jpg', 'https://old.test/page/');
        $text = FailedAssetCollector::formatFailedUrlsForDisplay();
        FailedAssetCollector::clearFailedUrls();

        return $text;
    })());

    expect($output)->toContain("Selected content type: 'page'\n\n".strip_tags($formatted)."\n\nStache cache cleared before navigation import.\n");
});

it('clears the application cache after the import', function (): void {
    iwriCollection('pages');
    Cache::put('iwri-stale', 'yes');
    [$command, $buffer] = iwriCommand();

    iwriRun($command, $buffer, [iwriPart($this, ['page'])]);

    expect(Cache::get('iwri-stale'))->toBeNull();
});

it('sweeps the dataless entries the run made, and shows the warnings it gathered', function (): void {
    iwriCollection('pages');
    $root = ContentRoot::collections();
    $dataless = $root.'/pages/default/invented.md';
    IwriEntries::$onCreate = static function () use ($dataless): void {
        @mkdir(dirname($dataless), 0777, true);
        file_put_contents($dataless, "---\nid: invented-1\nblueprint: page\n---\n");
    };
    [$command, $buffer] = iwriCommand();
    (new ReflectionProperty(ImportWordPress::class, 'runStartedAt'))->setValue($command, (float) (time() - 60));

    [, $output] = iwriRun($command, $buffer, [iwriPart($this, ['page'])]);

    expect(file_exists($dataless))->toBeFalse()
        ->and($output)->toContain("  - Removed 1 dataless entry file(s) this run materialized. See the warning summary.\n")
        ->and($output)->toContain('content - dataless_entries_removed:'."\n".' - {"path":"pages\\/default\\/invented.md","collection":"pages","site":"default","slug":"invented","id":"invented-1"}'."\n");
});

it('sets the gate report off with a blank line', function (): void {
    iwriCollection('pages');
    [$command, $buffer, $stands] = iwriCommand();
    $stands['siteManager']->missingDestinations = ['logos_item_categories'];

    [, $output] = iwriRun($command, $buffer, [iwriPart($this, [['type' => 'page', 'content' => '<!-- wp:acf/logo-list {"id":"b"} /-->']])]);

    expect($output)->toContain("Import process complete.\n\nNothing was imported for the following content types");
});

it('forgets the selected site after the run', function (): void {
    iwriCollection('pages', ['other']);
    [$command, $buffer, $stands] = iwriCommand();
    $stands['sites']->site = 'other';

    iwriRun($command, $buffer, [iwriPart($this, ['page'])]);

    expect(SiteResolver::getCurrentSite())->toBe('default');
});

it('ends the run when asked to import more with nothing left to import', function (): void {
    iwriCollection('pages');
    [$command] = iwriCommand();

    [$status, $output] = iwriInteractive($command, [iwriPart($this, ['page'])], "\n\nyes\n");

    expect($status)->toBe(0)
        ->and(IwriLog::of('types.select'))->toBe([[['page']]])
        ->and($output)->toContain('Import process complete.');
});
