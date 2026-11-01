<?php

declare(strict_types=1);

use App\Services\BardContentSanitizer;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\PendingCommand;
use Mockery\CompositeExpectation;
use Mockery\Expectation;
use Mockery\ExpectationInterface;
use Mockery\LegacyMockInterface;
use Mockery\MockInterface;
use PHPUnit\Framework\Assert;
use Statamic\Assets\AssetUploader;
use Statamic\Entries\Entry;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Stache\Query\EntryQueryBuilder;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;
use UAMSWeb\Aether\WordPressImporter\Console\Commands\ImportWordPress;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\FailedAssetCollector;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\DeferredProcessing\DeferredProcessingCoordinator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\Creators\KbEntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\EntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormPluginCoordinator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Globals\GlobalCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Navigation\NavigationMenuImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\Blocks\FindADocLocationsParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\Blocks\FindADocProvidersParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\Blocks\LogoListParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\ContentTypeSelector;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\Loaders\FormidableFormsLoader;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\Loaders\GravityFormsLoader;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\Loaders\NinjaFormsLoader;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings\Loader as MinervaKbSettingsLoader;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings\Selector as MinervaKbSettingsSelector;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\NavigationMenuSelector;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\SiteSelector;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\TaxonomyOverwriteBehaviorSelector;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\ReusableBlocks\ReusableBlockCoordinator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Structures\TreeBuilder;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Taxonomies\BaseTaxonomyValidator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\CollectionStructureManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\PeopleShortcodeProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\SiteManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\SiteResolver;
use UAMSWeb\Aether\WordPressImporter\Tests\Feature\WordPressImportTestCase;

/**
 * Order-independent Pest coverage for `statamic:import:wordpress` (issue wordpress-importer-archive#461).
 *
 * This file REPLACES the PHPUnit class of the same name. That class was not
 * ported — it was rebuilt — because a faithful port dropped from 31/32 to 11/32
 * in a full-file run while being byte-faithful per test. The old class passed
 * only through cumulative cross-test state, and three of its mechanisms were
 * false greens:
 *
 * - `assertCommandReachesUnexpectedQuestion()` asserted **only** inside its
 *   `catch`, so a run that succeeded made zero assertions and passed.
 * - `PendingCommand::run()` calls `verifyExpectations()` only after the kernel
 *   returns, so every `expectsOutput()` on a throw-to-halt test was decorative.
 * - Fourteen sites registered `expectsQuestion('How should existing taxonomy
 *   terms be handled?', …)` directly, and five more routed one through its
 *   `handlePrompt()` helper (removed with the rest of that harness in wordpress-importer-archive#533) — a
 *   question `TaxonomyOverwriteBehaviorSelector` never
 *   asks, because it returns `getDefault()` before any I/O under
 *   `runningUnitTests()`. Each stranded expectation desynced Laravel's
 *   `->ordered()` question chain.
 *
 * ## How order-independence is achieved
 *
 * Every test outside the per-option band drives the command through
 * `Artisan::call()` with `--no-interaction`, which resolves every prompt from a
 * default before any console I/O (`HasQuestionHelper` short-circuits, and
 * Symfony's `QuestionHelper` returns the default). No question is asked, so there
 * is no `->ordered()` chain to desync, and the run **completes** — every
 * assertion is then made unconditionally against the returned exit code and the
 * captured output, rather than through an expectation object that verifies only
 * if the run happens to finish. See `importCommandRun()` for why `$this->artisan()`
 * is the wrong instrument here.
 *
 * Each test makes exactly ONE run against a freshly built application, so a
 * double installed in the test body is always in place before the kernel resolves
 * the command. The per-option band cannot use `--no-interaction` (non-interactive
 * resolution always lands on option 0), so it installs a command built with
 * doubles through `Artisan::registerCommand()`, which replaces the kernel's
 * instance under the same name.
 *
 * ## Corrections to what the old file asserted
 *
 * Four of its assertions described behavior that does not exist. Each was green
 * because it was never verified:
 *
 * - **No Row block test.** `grep -rn 'scanForRow' src/` returns nothing; the old
 *   `test_block_scanning_with_row_blocks` asserted against a scan that does not
 *   exist.
 * - **The Classic banner reads `Mixed Classic and Gutenberg block content
 *   detected …`**, not `Classic block content detected …` — and the gate fires
 *   only on a genuine MIX, so this file builds a mixed fixture by hand. The
 *   trait's `getClassicPageItem()` emitted pure classic HTML and tripped nothing;
 *   it was removed in wordpress-importer-archive#533 along with the other orphaned fixture helpers.
 * - **The affected-page IDs are the fixtures' own**, not `123`. The trait used to
 *   drop a non-string `post_id`, so its block fixtures all emitted `123` while
 *   naming 456 / 790 / 791. This file passes them as strings, which sidestepped
 *   that; the coercion itself was fixed later in wordpress-importer-archive#531.
 * - **The six "tree branch" tests are not carried over.** After name
 *   normalization all six were byte-identical (same MD5) and exercised zero
 *   `TreeBuilder` branches. Real branch coverage belongs on `TreeBuilder`, not on
 *   the command — filed as a follow-up.
 *
 * @command  php artisan test vendor/uams-web/aether-wordpress-importer/tests/Feature/ImportWordPressCommandTest.php
 */
uses(WordPressImportTestCase::class);

/**
 * Entry slugs any fixture in this file can create, cleaned up after each test.
 *
 * @var array<int, string>
 */
const IMPORT_COMMAND_ENTRY_SLUGS = [
    'foo',
    'foo1',
    'foo.1',
    'mixed-classic-page',
    'details-block-page',
    'freeform-block-page',
    'basic-page',
    'landing-page',
];

/**
 * The overwrite options the command offers, in the order it offers them.
 *
 * Entry and asset prompts share this list, so one dataset drives both bands.
 *
 * @var array<int, string>
 */
const IMPORT_COMMAND_OVERWRITE_OPTIONS = [
    'Overwrite matching files',
    'Skip matching files',
    'Create an additional file',
];

/**
 * Message of the sentinel exception a double throws to halt a run at a known point.
 *
 * A dedicated message keeps the catch precise: any other `RuntimeException` is a
 * genuine failure and is re-thrown rather than swallowed as an expected halt.
 */
const IMPORT_COMMAND_HALT = 'import-wordpress-command-test-halt';

/**
 * Track a temporary fixture so `afterEach` removes it however the test ends.
 *
 * Fixtures are built inside each test rather than in a shared `beforeEach` so a
 * test that never runs an import does not inherit four temp files from setup.
 *
 * @param  ?string  $path  Path to track, or null to drain and reset the list
 * @return array<int, string> The tracked paths (the drained list when $path is null)
 */
function importCommandTempFiles(?string $path = null): array
{
    /** @var array<int, string> $paths */
    static $paths = [];

    // A null path drains the list so the caller can unlink what accumulated.
    if ($path === null) {
        $drained = $paths;
        $paths = [];

        return $drained;
    }

    $paths[] = $path;

    return $paths;
}

/**
 * Register an expectation on a mock and return the concrete `Expectation` behind it.
 *
 * `shouldReceive()` returns a `CompositeExpectation` whose fluent methods
 * (`once()`, `with()`, `andThrow()`, …) are `__call`-proxied and therefore
 * invisible to the analyser. Reaching the inner `Expectation`, which declares
 * them, is what keeps the chain statically sound at level max.
 *
 * This lives as a file-local function rather than a shared trait because Pest's
 * `uses(Trait::class)` is not modelled by the static analyser: a trait written
 * for this would read as used zero times and fail `trait.unused`.
 * `tests/Unit/AssetDownloaderTest.php` carries its own copy for the same reason.
 *
 * @param  LegacyMockInterface|MockInterface  $mock  The mock to expect a call on
 * @param  string  $method  The method expected to be called
 * @return Expectation The unwrapped expectation, ready to chain
 */
function importCommandExpectation(LegacyMockInterface|MockInterface $mock, string $method): Expectation
{
    $composite = $mock->shouldReceive($method);

    Assert::assertInstanceOf(
        CompositeExpectation::class,
        $composite
    );

    $property = (new ReflectionClass(CompositeExpectation::class))->getProperty('_expectations');
    $property->setAccessible(true);

    /** @var list<ExpectationInterface> $expectations */
    $expectations = $property->getValue($composite);

    if (\count($expectations) !== 1) {
        throw new RuntimeException('Expected exactly one Mockery expectation.');
    }

    $expectation = $expectations[0];

    if (! $expectation instanceof Expectation) {
        throw new RuntimeException('Expected a Mockery Expectation instance.');
    }

    return $expectation;
}

/**
 * Run the import command non-interactively and capture its exit code and output.
 *
 * Deliberately `Artisan::call()` and not `$this->artisan()`. The latter swaps the
 * console output for a Mockery double whose `askQuestion()` throws unless a
 * matching `expectsQuestion()` was registered — and the command's three inline
 * prompts (entry overwrite, asset overwrite, "import more content?") call
 * `choice()`/`confirm()` unconditionally, relying on Symfony's `QuestionHelper`
 * returning the default under a non-interactive input. Through a REAL output that
 * is exactly what happens, so the run needs no question chain at all. Through the
 * double it is a `BadMethodCallException`, which would drag the whole
 * order-dependent chain back in through the side door.
 *
 * The buffer is passed explicitly because the command calls
 * `Artisan::call('cache:clear')` shortly before it finishes, which replaces the
 * kernel's own `lastOutput` — `Artisan::output()` afterwards returns
 * "Application cache cleared successfully." and none of the import's output.
 *
 * @param  string  $file  Path to the WordPress export XML
 * @param  array<string, string>  $options  Extra console options (e.g. `--unsupported-content`)
 * @return array{exit: int, output: string} The exit code and everything the run printed
 */
function importCommandRun(string $file, array $options = []): array
{
    $buffer = new BufferedOutput;

    $exitCode = Artisan::call(
        'statamic:import:wordpress',
        array_merge(
            [
                'file' => $file,
                '--no-interaction' => true,
            ],
            $options
        ),
        $buffer
    );

    return [
        'exit' => $exitCode,
        'output' => $buffer->fetch(),
    ];
}

/**
 * Start the import command interactively, for the per-option band only.
 *
 * @param  TestCase  $test  The running test
 * @param  string  $file  Path to the WordPress export XML
 * @return PendingCommand The pending command, for the question chain
 */
function importCommandInteractive(TestCase $test, string $file): PendingCommand
{
    $command = $test->artisan(
        'statamic:import:wordpress',
        ['file' => $file]
    );

    Assert::assertInstanceOf(
        PendingCommand::class,
        $command
    );

    return $command;
}

/**
 * Install a command built with doubles under the real command name.
 *
 * `Artisan::registerCommand()` replaces whatever the kernel resolved for
 * `statamic:import:wordpress`, so the double is guaranteed to be the instance the
 * running command holds — no container binding, and no dependence on when the
 * console application was first built.
 *
 * Doubles are taken as `?object` and narrowed here rather than type-hinted
 * directly: a Mockery double IS an instance of the class it mocks, but the
 * analyser cannot see that through Mockery's variadic factory signature.
 *
 * @param  ?object  $entryCreator  An {@see EntryCreator} double, or null for the container's
 * @param  ?object  $assetImporter  An {@see AssetImporter} double, or null for the container's
 * @param  ?object  $treeBuilder  A {@see TreeBuilder} double, or null for the container's
 * @param  ?object  $siteManager  A {@see SiteManager} double, or null for the container's
 * @param  ?object  $globalCreator  A {@see GlobalCreator} double, or null for the container's
 * @param  ?object  $navigationMenuSelector  A {@see NavigationMenuSelector} double, or null for the container's
 */
function importCommandRegisterWithDoubles(
    ?object $entryCreator = null,
    ?object $assetImporter = null,
    ?object $treeBuilder = null,
    ?object $siteManager = null,
    ?object $globalCreator = null,
    ?object $navigationMenuSelector = null
): void {
    // Fall back to the container's own collaborator wherever no double is supplied.
    $entryCreatorDouble = $entryCreator ?? app(EntryCreator::class);
    $assetImporterDouble = $assetImporter ?? app(AssetImporter::class);
    $treeBuilderDouble = $treeBuilder ?? app(TreeBuilder::class);
    $siteManagerDouble = $siteManager ?? app(SiteManager::class);
    $globalCreatorDouble = $globalCreator ?? app(GlobalCreator::class);
    $navigationMenuSelectorDouble = $navigationMenuSelector ?? app(NavigationMenuSelector::class);

    // Fail loudly here rather than at the constructor if a caller passes the wrong
    // double — the resulting TypeError deep in the kernel would be far harder to read.
    Assert::assertInstanceOf(EntryCreator::class, $entryCreatorDouble);
    Assert::assertInstanceOf(AssetImporter::class, $assetImporterDouble);
    Assert::assertInstanceOf(TreeBuilder::class, $treeBuilderDouble);
    Assert::assertInstanceOf(SiteManager::class, $siteManagerDouble);
    Assert::assertInstanceOf(GlobalCreator::class, $globalCreatorDouble);
    Assert::assertInstanceOf(NavigationMenuSelector::class, $navigationMenuSelectorDouble);

    // Every collaborator a double does not replace is the container's own, so the
    // command under test differs from production in exactly one place.
    $command = new ImportWordPress(
        app(XmlParser::class),
        app(SiteSelector::class),
        app(ContentTypeSelector::class),
        app(TaxonomyOverwriteBehaviorSelector::class),
        app(MinervaKbSettingsSelector::class),
        app(MinervaKbSettingsLoader::class),
        $entryCreatorDouble,
        $globalCreatorDouble,
        $treeBuilderDouble,
        $assetImporterDouble,
        app(KbEntryCreator::class),
        app(FormPluginCoordinator::class),
        $siteManagerDouble,
        $navigationMenuSelectorDouble,
        app(NavigationMenuImporter::class),
        app(ReusableBlockCoordinator::class),
        app(CollectionStructureManager::class),
        app(DeferredProcessingCoordinator::class),
        app(BardContentSanitizer::class),
        app(GravityFormsLoader::class),
        app(NinjaFormsLoader::class),
        app(FormidableFormsLoader::class)
    );

    Artisan::registerCommand($command);
}

/**
 * Run something a double is expected to halt, and report whether it did.
 *
 * The halt is what keeps the per-option band cheap AND safe: a partial mock built
 * without its constructor must not be driven through a real import, so the run is
 * stopped at the assertion point instead.
 *
 * @param  callable(): mixed  $run  The run to perform
 * @return bool True when the run stopped at the sentinel
 */
function importCommandExpectHalt(callable $run): bool
{
    try {
        $run();
    } catch (RuntimeException $runtimeException) {
        // Anything that is not our sentinel is a real failure — never swallow it.
        if ($runtimeException->getMessage() !== IMPORT_COMMAND_HALT) {
            throw $runtimeException;
        }

        return true;
    }

    return false;
}

/**
 * Find an entry in the `pages` collection on the default site.
 *
 * @param  string  $slug  The entry slug
 * @return ?Entry The matching entry, or null when the query cannot be built or nothing matches
 */
function importCommandPagesEntry(string $slug): ?Entry
{
    $collection = CollectionFacade::find('pages');

    if ($collection === null) {
        return null;
    }

    $query = $collection->queryEntries();

    if (! $query instanceof EntryQueryBuilder) {
        return null;
    }

    $siteQuery = $query->where('site', 'default');

    if (! $siteQuery instanceof EntryQueryBuilder) {
        return null;
    }

    $slugQuery = $siteQuery->where('slug', $slug);

    if (! $slugQuery instanceof EntryQueryBuilder) {
        return null;
    }

    $entry = $slugQuery->first();

    return $entry instanceof Entry ? $entry : null;
}

/**
 * Read an entry's raw field data as a plain array.
 *
 * Statamic hands back a Collection (or a `Values` object) rather than an array,
 * so normalize it before asserting. Reading the raw data — instead of `get()` —
 * is what lets an assertion tell an absent key from one explicitly set to null.
 *
 * @param  ?Entry  $entry  The entry to read, or null when none matched
 * @return array<int|string, mixed> The entry's field data, empty when unreadable
 */
function importCommandEntryData(?Entry $entry): array
{
    if ($entry === null) {
        return [];
    }

    $data = $entry->data();

    // Normalize the Collection / Values object Statamic returns
    if ($data instanceof Arrayable) {
        return $data->toArray();
    }

    return \is_array($data) ? $data : [];
}

// Reset every process-static the command clears only INSIDE runImport(). A test
// that never reaches that point (a missing file, an invalid option) would
// otherwise inherit residue from whichever test ran before it — the precise
// coupling that made the old class order-dependent.
beforeEach(function (): void {
    // Guard the live `content/` submodule against anything this test leaves behind (wordpress-importer-archive#570)
    $this->captureSubmoduleStates();

    LinkProcessor::clearDeferredData();
    ImportWarningManager::clearWarnings();
    FailedAssetCollector::clearFailedUrls();
    BaseTaxonomyValidator::clearCaches();
    BaseEntryCreator::clearTaxonomyCaches();
    BaseEntryCreator::clearDeferredTermQueue();
    SiteResolver::clearSelectedSite();

    // All four parsers write a process-static term registry during a real import.
    PeopleShortcodeProcessor::resetTermsUsed();
    LogoListParser::resetTermsUsed();
    FindADocProvidersParser::resetTermsUsed();
    FindADocLocationsParser::resetTermsUsed();
});

afterEach(function (): void {
    // Remove the temp fixtures first: they are cheap and must go even if the
    // Statamic cleanup below throws.
    foreach (importCommandTempFiles(null) as $path) {
        if (file_exists($path)) {
            unlink($path);
        }

        // ---
        // The extensionless `tempnam()` sibling this used to remove by hand no
        // longer exists: since wordpress-importer-archive#783 the shared registry renames the reservation
        // onto the path the caller writes, so there is nothing left beside it.
        // ---
    }

    // Cleanup failures are logged, never thrown, so they cannot mask the real
    // failure of the test that just ran.
    try {
        $this::cleanupTestFilesWithPaths(
            IMPORT_COMMAND_ENTRY_SLUGS,
            'pages',
            'default',
            [],
            [],
            true
        );
    } catch (Exception $e) {
        error_log('ImportWordPressCommandTest cleanup error: '.$e->getMessage());
    }

    $this->clearTestData();

    // Last, so it judges what the cleanup above did NOT put back (wordpress-importer-archive#570).
    $this->assertSubmodulesUnchanged();
});

// ---
// Steps 1 and 2 — file and option validation, before anything is parsed
// ---

it('rejects a file path that does not exist', function (): void {
    $result = importCommandRun('/non/existent/file.xml');

    expect($result['exit'])->toBe(1);
    expect($result['output'])->toContain("The file '/non/existent/file.xml' does not exist.");
});

it('rejects an unsupported-content policy outside the allowed set', function (): void {
    // The option is validated before the file is parsed, so the fixture only has
    // to exist — this pins the guard, not the parse.
    $xmlPath = $this->createTemporaryXmlFile($this->buildXmlContent([$this->getGutenbergPageItem()]));
    importCommandTempFiles($xmlPath);

    $result = importCommandRun($xmlPath, ['--unsupported-content' => 'sometimes']);

    expect($result['exit'])->toBe(1);
    expect($result['output'])->toContain('The --unsupported-content option must be one of: abort, skip, import.');
});

// ---
// Steps 2-5 and 10-12 — parse, detect, select, create, complete, in one bounded run
// ---

it('parses the export, selects site and content type, imports, and completes', function (): void {
    $xmlPath = $this->createTemporaryXmlFile($this->buildXmlContent([$this->getGutenbergPageItem()]));
    importCommandTempFiles($xmlPath);

    // Asserting the whole spine in one COMPLETED run is the point. Every assertion
    // below is unconditional — there is no expectation object that only verifies
    // when the run happens to finish, which is what made the old file decorative.
    $result = importCommandRun($xmlPath);

    expect($result['exit'])->toBe(0);
    expect($result['output'])
        ->toContain("Starting WordPress import from: {$xmlPath}")
        ->toContain('Content types found in the XML:')
        ->toContain('All content types in XML are supported.')
        ->toContain("Selected site 'default' is valid.")
        // The selector strips the ' (N)' count on BOTH branches, so the emitted
        // value is 'page'. The old file asserted "page (1)" at ten-plus sites and
        // was green only because it threw before verification ran.
        ->toContain("Selected content type: 'page'")
        ->toContain('Laravel cache cleared.')
        ->toContain('Import process complete.');
});

it('creates the imported entry in the pages collection', function (): void {
    $xmlPath = $this->createTemporaryXmlFile($this->buildXmlContent([$this->getGutenbergPageItem()]));
    importCommandTempFiles($xmlPath);

    expect(importCommandRun($xmlPath)['exit'])->toBe(0);

    $entry = importCommandPagesEntry('foo');

    expect($entry)->not->toBeNull();
    expect($entry?->get('title'))->toBe('Foo');
});

// ---
// Step 6 — block scanning
// ---

/**
 * A page carrying BOTH a Gutenberg block and freeform markup outside it.
 *
 * `scanForClassicBlocks()` warns only on a MIX — it requires `<!-- wp:` *and*
 * content left over once the blocks are stripped. The trait's own
 * `getClassicPageItem()` emitted PURE classic HTML with no Gutenberg block at all,
 * so it never tripped the gate: the old `test_block_scanning_with_row_blocks`-style
 * assertion for Classic detection described an outcome that never occurred, and
 * passed regardless. That helper was removed in wordpress-importer-archive#533, having no callers left.
 */
const IMPORT_COMMAND_MIXED_CLASSIC_CONTENT = "<!-- wp:paragraph -->\n<p>A Gutenberg paragraph.</p>\n<!-- /wp:paragraph -->\n\n<p>Freeform classic markup outside any block.</p>";

/**
 * `generatePageItem()` config producing one page per unsupported-block gate.
 *
 * `post_id` is a STRING here for historical reasons rather than necessity. When this
 * file was written, `configString()` dropped any non-string value and fell back to
 * `'123'`, so the trait's `getClassicPageItem()` / `getDetailsBlockPageItem()` /
 * `getFreeformBlockPageItem()` helpers — which all passed integers, and which wordpress-importer-archive#533
 * has since removed — emitted post id
 * `123` rather than the 456 / 790 / 791 they named. The old file asserted those
 * numbers and passed anyway, because the assertions were never verified. Strings
 * were the workaround. `configString()` now accepts an int too (wordpress-importer-archive#531), so either
 * form works; these stay strings because the ids below are already correct and
 * changing them would churn the expectations for no gain.
 *
 * @var array<string, array<string, string>>
 */
const IMPORT_COMMAND_BLOCK_GATE_FIXTURES = [
    'classic' => [
        'post_id' => '456',
        'title' => 'Mixed Classic Page',
        'slug' => 'mixed-classic-page',
        'content' => IMPORT_COMMAND_MIXED_CLASSIC_CONTENT,
    ],
    'details' => [
        'post_id' => '790',
        'content_type' => 'details_blocks',
    ],
    'freeform' => [
        'post_id' => '791',
        'content_type' => 'freeform_blocks',
    ],
];

/**
 * Each gate with the detection banner, the affected-page line, and the abort message.
 */
dataset('unsupported block aborts', [
    'classic' => [
        IMPORT_COMMAND_BLOCK_GATE_FIXTURES['classic'],
        "Mixed Classic and Gutenberg block content detected in 'page' content:",
        " - Title: 'Mixed Classic Page', ID: 456, Permalink: https://supplychain.uams.edu/mixed-classic-page/",
        "Aborting import of 'page' due to Classic block presence (see affected pages above).",
    ],
    'details' => [
        IMPORT_COMMAND_BLOCK_GATE_FIXTURES['details'],
        "Details block content detected in 'page' content:",
        " - Title: 'Details Block Page', ID: 790, Permalink: https://supplychain.uams.edu/details-block-page/",
        "Aborting import of 'page' due to Details block presence (see affected pages above).",
    ],
    'freeform' => [
        IMPORT_COMMAND_BLOCK_GATE_FIXTURES['freeform'],
        "Freeform block content detected in 'page' content:",
        " - Title: 'Freeform Block Page', ID: 791, Permalink: https://supplychain.uams.edu/freeform-block-page/",
        "Aborting import of 'page' due to Freeform block presence (see affected pages above).",
    ],
]);

/**
 * Each gate with the message the `skip` policy must emit and the slug it must NOT create.
 */
dataset('unsupported block skips', [
    'classic' => [
        IMPORT_COMMAND_BLOCK_GATE_FIXTURES['classic'],
        "Skipping import of 'page' due to Classic block presence.",
        'mixed-classic-page',
    ],
    'details' => [
        IMPORT_COMMAND_BLOCK_GATE_FIXTURES['details'],
        "Skipping import of 'page' due to Details block presence.",
        'details-block-page',
    ],
    'freeform' => [
        IMPORT_COMMAND_BLOCK_GATE_FIXTURES['freeform'],
        "Skipping import of 'page' due to Freeform block presence.",
        'freeform-block-page',
    ],
]);

it('detects unsupported blocks and aborts under the default policy', function (
    array $pageConfig,
    string $detectionBanner,
    string $affectedLine,
    string $abortMessage
): void {
    /** @var array<string, string> $pageConfig */
    $xmlPath = $this->createTemporaryXmlFile(
        $this->buildXmlContent([$this->generatePageItem($pageConfig)])
    );
    importCommandTempFiles($xmlPath);

    // `abort` is the documented default, so no option is passed: this pins the
    // default as well as the branch.
    $result = importCommandRun($xmlPath);

    expect($result['exit'])->toBe(1);
    expect($result['output'])
        ->toContain($detectionBanner)
        ->toContain($affectedLine)
        // The remediation guidance is the operator-facing half of the gate: without
        // it the run names a problem and offers no way out. All three gates share
        // this sentence, so one fragment covers the dataset.
        ->toContain('not currently mapped to the Statamic Bard format')
        ->toContain($abortMessage);
})->with('unsupported block aborts');

it('skips the affected content type and exits cleanly under the skip policy', function (
    array $pageConfig,
    string $skipMessage,
    string $skippedSlug
): void {
    /** @var array<string, string> $pageConfig */
    $xmlPath = $this->createTemporaryXmlFile(
        $this->buildXmlContent([$this->generatePageItem($pageConfig)])
    );
    importCommandTempFiles($xmlPath);

    // Regression guard for wordpress-importer-archive#389: the only supported content type is skipped, so the
    // loop must find nothing left to select and terminate. Before the fix the
    // deterministic non-interactive selector re-returned the same type forever and
    // the run pegged a core — a hang, which surfaces here as a timeout, not a pass.
    $result = importCommandRun($xmlPath, ['--unsupported-content' => 'skip']);

    expect($result['exit'])->toBe(0);
    expect($result['output'])->toContain($skipMessage);

    // The message alone is not the contract — it is emitted one statement BEFORE the
    // `continue` that performs the skip, so deleting that `continue` would leave this
    // test green while the page imported anyway (exit 0 either way). The absence of
    // the entry is what actually pins the skip.
    expect(importCommandPagesEntry($skippedSlug))->toBeNull();
})->with('unsupported block skips');

it('imports the affected content type under the import policy', function (): void {
    $xmlPath = $this->createTemporaryXmlFile(
        $this->buildXmlContent([$this->generatePageItem(IMPORT_COMMAND_BLOCK_GATE_FIXTURES['details'])])
    );
    importCommandTempFiles($xmlPath);

    // The third policy branch: the gate is acknowledged and the import proceeds.
    // Nothing else in the suite reaches `$decision === 'import'`.
    $result = importCommandRun($xmlPath, ['--unsupported-content' => 'import']);

    expect($result['exit'])->toBe(0);
    expect($result['output'])
        ->not->toContain("Skipping import of 'page' due to Details block presence.");
    expect($result['output'])->toContain('Import process complete.');

    expect(importCommandPagesEntry('details-block-page'))->not->toBeNull();
});

// ---
// Step 7 — destination setup
// ---

it('leaves an already-enabled collection alone', function (): void {
    // No double: the host app's `pages` collection is already enabled for the
    // default site, so the real run takes the already-enabled branch. The omission
    // is only trustworthy because the run completes.
    $xmlPath = $this->createTemporaryXmlFile($this->buildXmlContent([$this->getGutenbergPageItem()]));
    importCommandTempFiles($xmlPath);

    $result = importCommandRun($xmlPath);

    expect($result['exit'])->toBe(0);
    expect($result['output'])->toContain('Import process complete.');
    expect($result['output'])->not->toContain("Enabled 'default' for collection 'pages'.");
});

it('enables the collection for the site when it is not enabled yet', function (): void {
    // SiteManager has no constructor, so a partial mock is safe to drive through a
    // complete run — unstubbed methods behave exactly as the real object.
    $siteManager = Mockery::mock(SiteManager::class);
    $siteManager->makePartial();

    importCommandExpectation($siteManager, 'isCollectionEnabledForSite')
        ->with('default', 'pages')
        ->andReturnFalse();

    // `atLeast()->once()`, not `once()`: the command's constructor hands the same
    // SiteManager to the EntryCreator, which enables the collection again on its own
    // path once this double reports it as disabled. Two calls is the real behavior;
    // pinning the count would assert an internal detail the command does not own.
    importCommandExpectation($siteManager, 'enableCollectionForSite')
        ->atLeast()
        ->once()
        ->with('default', 'pages', Mockery::any())
        ->andReturnTrue();

    // Installed through registerCommand(), NOT app()->instance(). A container
    // binding is only picked up if the kernel happens to resolve the command after
    // it — and it does not do so reliably: bound that way this test passed in a
    // full-file run and failed alone, with the real SiteManager reporting the
    // collection as already enabled. That is precisely the order dependence wordpress-importer-archive#461
    // exists to remove, so the double is handed to the command directly.
    importCommandRegisterWithDoubles(null, null, null, $siteManager);

    $xmlPath = $this->createTemporaryXmlFile($this->buildXmlContent([$this->getGutenbergPageItem()]));
    importCommandTempFiles($xmlPath);

    $result = importCommandRun($xmlPath);

    expect($result['exit'])->toBe(0);
    expect($result['output'])
        ->toContain("Enabled 'default' for collection 'pages'.")
        ->toContain('Import process complete.');
});

// ---
// Steps 8-9 — entry and asset overwrite behavior
// ---

it('resolves both overwrite prompts to the first option non-interactively', function (): void {
    // Pinning the non-interactive default is what lets every other test in this
    // file rely on it. The entry expectation is verified by Mockery at teardown;
    // the asset double halts the run once the second prompt has resolved.
    $entryCreator = Mockery::mock(EntryCreator::class);
    $entryCreator->makePartial();
    importCommandExpectation($entryCreator, 'setEntryOverwriteBehavior')
        ->once()
        ->with('Overwrite matching files');

    $assetImporter = Mockery::mock(AssetImporter::class);
    $assetImporter->makePartial();
    importCommandExpectation($assetImporter, 'setAssetOverwriteBehavior')
        ->once()
        ->with('Overwrite matching files')
        ->andThrow(new RuntimeException(IMPORT_COMMAND_HALT));

    importCommandRegisterWithDoubles($entryCreator, $assetImporter);

    $xmlPath = $this->createTemporaryXmlFile($this->buildXmlContent([$this->getGutenbergPageItem()]));
    importCommandTempFiles($xmlPath);

    expect(importCommandExpectHalt(fn (): array => importCommandRun($xmlPath)))->toBeTrue();
});

it('applies the selected entry overwrite option', function (string $option): void {
    // Only reachable interactively: `$this->choice()` returns option 0 whenever the
    // input is non-interactive, so options 1 and 2 have no other route.
    $entryCreator = Mockery::mock(EntryCreator::class);
    $entryCreator->makePartial();
    importCommandExpectation($entryCreator, 'setEntryOverwriteBehavior')
        ->once()
        ->with($option)
        ->andThrow(new RuntimeException(IMPORT_COMMAND_HALT));

    importCommandRegisterWithDoubles($entryCreator);

    $xmlPath = $this->createTemporaryXmlFile($this->buildXmlContent([$this->getGutenbergPageItem()]));
    importCommandTempFiles($xmlPath);

    // Exactly the questions the command asks, and no others. In particular there is
    // no taxonomy question: the selector never asks one under runningUnitTests(),
    // and the old file's stranded expectation for it desynced the ordered chain.
    $command = importCommandInteractive($this, $xmlPath)
        ->expectsQuestion('Which site would you like to import the content into?', 'default')
        ->expectsQuestion('Which content type would you like to import?', 'page (1)')
        ->expectsQuestion(
            "How should existing entries in the 'pages' collection with matching IDs be handled?",
            $option
        );

    expect(importCommandExpectHalt(fn (): int => $command->run()))->toBeTrue();
})->with(IMPORT_COMMAND_OVERWRITE_OPTIONS);

it('applies the selected asset overwrite option', function (string $option): void {
    $assetImporter = Mockery::mock(AssetImporter::class);
    $assetImporter->makePartial();
    importCommandExpectation($assetImporter, 'setAssetOverwriteBehavior')
        ->once()
        ->with($option)
        ->andThrow(new RuntimeException(IMPORT_COMMAND_HALT));

    importCommandRegisterWithDoubles(null, $assetImporter);

    $xmlPath = $this->createTemporaryXmlFile($this->buildXmlContent([$this->getGutenbergPageItem()]));
    importCommandTempFiles($xmlPath);

    $command = importCommandInteractive($this, $xmlPath)
        ->expectsQuestion('Which site would you like to import the content into?', 'default')
        ->expectsQuestion('Which content type would you like to import?', 'page (1)')
        ->expectsQuestion(
            "How should existing entries in the 'pages' collection with matching IDs be handled?",
            'Overwrite matching files'
        )
        ->expectsQuestion('How should existing assets in the relevant container be handled?', $option);

    expect(importCommandExpectHalt(fn (): int => $command->run()))->toBeTrue();
})->with(IMPORT_COMMAND_OVERWRITE_OPTIONS);

it('skips the asset overwrite prompt entirely when asset uploads are disabled', function (): void {
    Config::set('wordpress-import.upload_images', false);
    Config::set('wordpress-import.upload_files', false);

    // Both switches off: the command must not prompt and must force the skip
    // behavior. No double is needed — the message and the completed run are the
    // observable contract.
    $xmlPath = $this->createTemporaryXmlFile($this->buildXmlContent([$this->getGutenbergPageItem()]));
    importCommandTempFiles($xmlPath);

    $result = importCommandRun($xmlPath);

    expect($result['exit'])->toBe(0);
    expect($result['output'])
        ->toContain('Asset uploads disabled (images and files off); skipping overwrite prompt.')
        ->toContain('Import process complete.');
});

// ---
// Step 11 — tree building
// ---

it('builds the collection tree for the imported content type', function (): void {
    // The command's contract with TreeBuilder is "call it once, with the resolved
    // site, content type and destination". Which branch TreeBuilder then takes is
    // TreeBuilder's own concern — the old file's six identical "tree branch" tests
    // asserted nothing about any of them. A full mock is safe here because
    // buildAndSaveTree is the only method the command ever calls on it.
    $treeBuilder = Mockery::mock(TreeBuilder::class);
    importCommandExpectation($treeBuilder, 'buildAndSaveTree')
        ->once()
        ->with(
            'default',
            'page',
            'pages',
            Mockery::type('array'),
            Mockery::any(),
            Mockery::type('string')
        );

    importCommandRegisterWithDoubles(null, null, $treeBuilder);

    $xmlPath = $this->createTemporaryXmlFile($this->buildXmlContent([$this->getGutenbergPageItem()]));
    importCommandTempFiles($xmlPath);

    expect(importCommandRun($xmlPath)['exit'])->toBe(0);
});

// ---
// Blueprint selection
// ---

it('creates a page without ACF blocks using the basic_page blueprint', function (): void {
    $xmlPath = $this->createTemporaryXmlFile($this->buildXmlContent([$this->generateBasicPageItem()]));
    importCommandTempFiles($xmlPath);

    expect(importCommandRun($xmlPath)['exit'])->toBe(0);

    $entry = importCommandPagesEntry('basic-page');

    expect($entry)->not->toBeNull();
    expect($entry?->get('blueprint'))->toBe('basic_page');
    expect($entry?->get('template'))->toBe('pages/default');
    expect($entry?->get('title_options'))->toBe('graphic');
})->group('pages-blueprint-creation');

it('creates a page carrying ACF blocks using the landing_page blueprint', function (): void {
    $xmlPath = $this->createTemporaryXmlFile($this->buildXmlContent([$this->generateLandingPageItem()]));
    importCommandTempFiles($xmlPath);

    expect(importCommandRun($xmlPath)['exit'])->toBe(0);

    $entry = importCommandPagesEntry('landing-page');

    expect($entry)->not->toBeNull();
    expect($entry?->get('blueprint'))->toBe('landing_page');
    expect($entry?->get('template'))->toBe('pages/page_landing');
    expect($entry?->get('title_options'))->toBe('graphic');
})->group('pages-blueprint-creation');

// ---
// Featured image handle
// ---

it('imports a page featured image onto the featured_image_16_9 handle', function (): void {
    // ---
    // `UAMS-Web/uams-statamic-archive#1553` dropped the single `featured_image` field from
    // the `pages` blueprints in favor of the standard 3-handle cover-image set, so
    // a value written to the old handle is orphaned data — never augmented to an
    // asset, no `og:image`, no card thumbnail. Assert the handle, not just the
    // value: the import "succeeded" for the whole time it wrote the wrong key.
    // wordpress-importer-archive#433
    // ---

    // Disabling uploads resolves the image to its original URL straight from the
    // export, so the assertion needs no network round trip and no asset container.
    Config::set('wordpress-import.upload_images', false);
    Config::set('wordpress-import.upload_files', false);

    // The page's `_thumbnail_id` points at the attachment item's `wp:post_id`
    $xmlPath = $this->createTemporaryXmlFile($this->buildXmlContent([
        $this->generateBasicPageItem(['thumbnail_id' => 999]),
        $this->getAssetItem(),
    ]));
    importCommandTempFiles($xmlPath);

    expect(importCommandRun($xmlPath)['exit'])->toBe(0);

    $entry = importCommandPagesEntry('basic-page');

    expect($entry)->not->toBeNull();

    // Read the raw entry data so an absent key is distinguishable from a null one
    $data = importCommandEntryData($entry);

    expect(\array_key_exists('featured_image_16_9', $data))->toBeTrue();
    expect($data['featured_image_16_9'] ?? null)->toBe('https://www.w3.org/assets/logos/w3c/w3c-full.png');

    // ---
    // The retired handle must carry no value. This cannot also prove the absence of
    // a *null* scaffold: `Entry::fileData()` runs `Arr::removeNullValues()` for a root
    // entry, so a null would never have reached the file to be read back. The scaffold
    // is gone at the source, which is the half this assertion does not have to carry.
    // ---
    expect(\array_key_exists('featured_image', $data))->toBeFalse();
})->group('pages-blueprint-creation');

/**
 * Remove the featured-image fixture from the `images` container.
 *
 * The uploads-enabled case writes a real file into `public/assets`, which is a
 * tracked **git submodule** of the host worktree, and the shared `afterEach`
 * passes `[]` for `cleanupTestFilesWithPaths()`'s asset list — so nothing else in
 * this file removes it.
 *
 * Since wordpress-importer-archive#764 `assertSubmodulesUnchanged()` covers every submodule the host
 * declares rather than `content/` alone, so a file left here now FAILS the test
 * that left it instead of accumulating unseen. This purge is what keeps that
 * guard green, and it is still the right mechanism rather than a redundant one:
 * the guard reports litter, it does not remove it.
 *
 * Purging **before** the import matters as much as after. Statamic's uploader
 * appends `-<unixtime>` when the target name is taken
 * ({@see AssetUploader::uploadPath()}), so a file left by an
 * earlier run makes the next one land on a different path — the value under test
 * would drift every run while loose assertions stayed green.
 */
function importCommandPurgeFeaturedImageFixture(): void
{
    $directory = \public_path('assets/images/default');

    $images = \glob($directory.'/*w3c-full*.png');
    $sidecars = \glob($directory.'/.meta/*w3c-full*.yaml');

    foreach ((\is_array($images) ? $images : []) as $file) {
        @\unlink($file);
    }

    foreach ((\is_array($sidecars) ? $sidecars : []) as $meta) {
        @\unlink($meta);
    }
}

it('imports a page featured image onto featured_image_16_9 with uploads enabled', function (): void {
    // ---
    // The sibling case above runs with uploads DISABLED, which resolves the image
    // to its original WordPress URL and never touches an asset container. That is
    // the deterministic arm, and it is not the arm production takes: a real import
    // downloads the file, writes it into the container, and stores a
    // container-relative path. Nothing asserted which handle *that* value reached.
    //
    // The two arms write through one line in `FieldMapper::mapFields()`, so there
    // is no reason to suspect they differ — but "no reason to suspect" is exactly
    // the state the retired handle was in for as long as it was wrong. wordpress-importer-archive#610
    // ---
    Config::set('wordpress-import.upload_images', true);
    Config::set('wordpress-import.upload_files', true);

    // ---
    // Fake the fetch rather than reaching w3.org. `AssetDownloader` walks redirects
    // through `GuardedRedirectWalk`, which uses Laravel's HTTP client, so the whole
    // download is interceptable — the same technique `PostImportTest` uses for its
    // news-release PDF. A live URL would make this test depend on a third party
    // staying up and on the sandbox having egress.
    // ---
    Http::fake([
        'https://www.w3.org/*' => Http::response(
            (string) \base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
                true
            ),
            200,
            ['Content-Type' => 'image/png']
        ),
    ]);

    // Another case's cached path would otherwise satisfy this one vacuously
    AssetImporter::clearFileCache();

    // And a file stranded by an earlier run would push this one onto a
    // `-<unixtime>` path, so purge before importing as well as after.
    importCommandPurgeFeaturedImageFixture();

    // ---
    // `finally`, not a trailing statement. A failing assertion throws, and a purge
    // placed after the assertions would simply not run — leaving a real file in a
    // tracked submodule on exactly the day something is broken, which is the defect
    // this case exists to demonstrate. Measuring the success path twice and calling
    // it clean is the mistake that hid it.
    // ---
    try {
        $xmlPath = $this->createTemporaryXmlFile($this->buildXmlContent([
            $this->generateBasicPageItem(['thumbnail_id' => 999]),
            $this->getAssetItem(),
        ]));
        importCommandTempFiles($xmlPath);

        expect(importCommandRun($xmlPath)['exit'])->toBe(0);

        $entry = importCommandPagesEntry('basic-page');

        expect($entry)->not->toBeNull();

        $data = importCommandEntryData($entry);

        expect(\array_key_exists('featured_image_16_9', $data))->toBeTrue();

        // ---
        // The distinction this case exists to make: uploads-disabled stores an
        // absolute WordPress URL, uploads-enabled stores a container-relative asset
        // path. An entry holding the former is not renderable as an asset.
        //
        // Pinned exactly, as `PostImportTest` pins `default/2020-05-fpo_200.jpg`.
        // The fixture URL carries no `/wp-content/uploads/YYYY/MM/` segment, so
        // `AssetUtils::extractWordPressPathInfo()` falls back to the current year
        // and month — computed here rather than hard-coded, since a literal would
        // rot at the next month boundary. The leading purge is what keeps this
        // deterministic: without it the second run lands on `…-<unixtime>.png`.
        // ---
        expect($data['featured_image_16_9'] ?? null)
            ->toBe('default/'.\date('Y-m').'-w3c-full.png');

        expect(\array_key_exists('featured_image', $data))->toBeFalse();
    } finally {
        // Leave the container as it was found: this writes into a tracked submodule.
        importCommandPurgeFeaturedImageFixture();
    }
})->group('pages-blueprint-creation');

it('sets featured_image_credit_query on a page whose featured image carries a credit', function (): void {
    // ---
    // wordpress-importer-archive#439's rule, extended to `pages` by wordpress-importer-archive#603: the toggle is written when — and
    // only when — the imported asset actually carries a `creator`. Before wordpress-importer-archive#603 the
    // page path imported the image and never consulted the resolver, so a credited
    // WordPress image landed with its attribution hidden.
    // ---
    Config::set('wordpress-import.upload_images', false);
    Config::set('wordpress-import.upload_files', false);

    $xmlPath = $this->createTemporaryXmlFile($this->buildXmlContent([
        $this->generateBasicPageItem(['thumbnail_id' => 999]),
        importCommandCreditedAssetItem(),
    ]));
    importCommandTempFiles($xmlPath);

    expect(importCommandRun($xmlPath)['exit'])->toBe(0);

    $data = importCommandEntryData(importCommandPagesEntry('basic-page'));

    // The image has to have landed, or the toggle would be inert (wordpress-importer-archive#439).
    expect($data['featured_image_16_9'] ?? null)
        ->toBe('https://www.w3.org/assets/logos/w3c/w3c-full.png')
        ->and($data['featured_image_credit_query'] ?? null)->toBeTrue();
})->group('pages-blueprint-creation');

it('leaves featured_image_credit_query unset on a page whose featured image carries no credit', function (): void {
    // ---
    // The other half of wordpress-importer-archive#439's rule. `getAssetItem()` writes no postmeta at all, so
    // `AttachmentCreditResolver` finds neither `_media_credit` nor an EXIF fallback
    // and the asset lands with no `creator`. The toggle must stay on the blueprint
    // default rather than being written `false` — an inert toggle is what the rule
    // exists to prevent.
    // ---
    Config::set('wordpress-import.upload_images', false);
    Config::set('wordpress-import.upload_files', false);

    $xmlPath = $this->createTemporaryXmlFile($this->buildXmlContent([
        $this->generateBasicPageItem(['thumbnail_id' => 999]),
        $this->getAssetItem(),
    ]));
    importCommandTempFiles($xmlPath);

    expect(importCommandRun($xmlPath)['exit'])->toBe(0);

    $data = importCommandEntryData(importCommandPagesEntry('basic-page'));

    // Read the raw data so an absent key is distinguishable from a false one.
    expect($data['featured_image_16_9'] ?? null)
        ->toBe('https://www.w3.org/assets/logos/w3c/w3c-full.png')
        ->and(\array_key_exists('featured_image_credit_query', $data))->toBeFalse();
})->group('pages-blueprint-creation');

it('leaves featured_image_credit_query unset when a credited page image fails to import', function (): void {
    // ---
    // The other conjunct of wordpress-importer-archive#439's rule, and the one the two tests above cannot
    // reach: a credited attachment whose image does NOT land. With uploads
    // disabled the importer returns the attachment's URL as a string, so an
    // attachment row carrying no URL yields '' rather than null. The toggle must
    // not be written beside an image that is not there.
    //
    // Without this case, deleting the non-null/non-empty guard in FieldMapper left
    // every page test green.
    // ---
    Config::set('wordpress-import.upload_images', false);
    Config::set('wordpress-import.upload_files', false);

    $xmlPath = $this->createTemporaryXmlFile($this->buildXmlContent([
        $this->generateBasicPageItem(['thumbnail_id' => 999]),
        importCommandCreditedAssetItemWithoutUrl(),
    ]));
    importCommandTempFiles($xmlPath);

    expect(importCommandRun($xmlPath)['exit'])->toBe(0);

    $data = importCommandEntryData(importCommandPagesEntry('basic-page'));

    // ---
    // The credit resolves, but no image landed — so neither key is written.
    // `not->toBe(<url>)` was satisfiable by the empty string the importer used to
    // store here, so it asserted nothing; the key-absence form is the real claim.
    //
    // Note this assertion is insensitive to wordpress-importer-archive#754's gate by itself: `Entry::fileData()`
    // discards `null` and `''` alike, so the saved entry looks the same either way. It
    // pins the end state, not the mechanism — `PageFeaturedImageGateTest` pins the gate,
    // where the difference is actually observable.
    // ---
    expect(\array_key_exists('featured_image_16_9', $data))->toBeFalse()
        ->and(\array_key_exists('featured_image_credit_query', $data))->toBeFalse();
})->group('pages-blueprint-creation');

// ---
// WP-Members-gated pages (wordpress-importer-archive#1240). The creators pass each item's `_wpmem_block` to the status
// mapper, and that wiring runs only through a real import, so it is asserted here.
// ---

it('imports a published page WP-Members gates as private, and an open one as published', function (): void {
    $gatedMeta = '<wp:postmeta><wp:meta_key><![CDATA[_wpmem_block]]></wp:meta_key>'
        .'<wp:meta_value><![CDATA[1]]></wp:meta_value></wp:postmeta></item>';

    // The gated page carries `_wpmem_block` 1; the other is the control, with none
    $gated = \preg_replace(
        '#</item>\s*$#',
        $gatedMeta,
        $this->generateBasicPageItem(['post_id' => 1240, 'title' => 'Gated Page', 'slug' => 'gated-page'])
    );
    $open = $this->generateBasicPageItem(['post_id' => 1241, 'title' => 'Open Page', 'slug' => 'open-page']);

    expect($gated)->toContain('_wpmem_block');

    $xmlPath = $this->createTemporaryXmlFile($this->buildXmlContent([(string) $gated, $open]));
    importCommandTempFiles($xmlPath);

    $run = importCommandRun($xmlPath);

    expect($run['exit'])->toBe(0)
        ->and($run['output'])->toContain('Imported as private: WP-Members gates');

    expect(importCommandPagesEntry('gated-page')?->get('status_and_visibility'))->toBe('private')
        ->and(importCommandPagesEntry('open-page')?->get('status_and_visibility'))->toBe('published');
})->group('wpmem-block');

// ---
// `--network` on the interactive path (wordpress-importer-archive#1088).
//
// These live here rather than in `tests/Integration/` because the standalone harness cannot
// construct this command at all: its dependency graph reaches `App\Services\BardContentSanitizer`
// through `ContentProcessor`, and that class exists only in the host app. Measured, not
// inferred — a probe under `StatamicTestCase` fails with `Class "App\Services\BardContentSanitizer"
// not found`. So the six-job chain cannot execute a line of what these assert.
// ---

it('does not ask for the network address when --network supplies it', function (): void {
    // No `file` argument: under `--source=api` that positional is the DESTINATION DIRECTORY
    // to materialize into, not an export to read. Passing an existing file makes the command
    // fail in `mkdir()` long before anything here is observable.
    $command = $this->artisan('statamic:import:wordpress', [
        '--source' => 'api',
        '--network' => 'https://materialize.invalid',
    ]);

    Assert::assertInstanceOf(PendingCommand::class, $command);

    // The address is echoed whether it was typed or passed — the operator has to see which
    // network they are about to spend a credential against. Expecting the KEY question and
    // not the ADDRESS one is what proves the prompt was skipped: the chain is ordered, so a
    // still-asked address question desyncs it and the run fails.
    $command->expectsOutputToContain('  Reading: https://materialize.invalid')
        ->expectsQuestion("Paste that network's migration API key", 'not-a-real-key');

    $command->run();
})->group('network-option');

it('still asks for the network address when --network is absent', function (): void {
    $command = $this->artisan('statamic:import:wordpress', [
        '--source' => 'api',
    ]);

    Assert::assertInstanceOf(PendingCommand::class, $command);

    // The control. Without this arm, a change that skipped the prompt unconditionally would
    // pass the test above and break every ordinary run.
    $command->expectsQuestion('Which network? Paste its WordPress address, including https://', 'https://typed.invalid')
        ->expectsOutputToContain('  Reading: https://typed.invalid')
        ->expectsQuestion("Paste that network's migration API key", 'not-a-real-key');

    $command->run();
})->group('network-option');

// ---
// Every sourcing seam is pointed at the migration API on an `--source=api` run (wordpress-importer-archive#811).
//
// The seams are container singletons the command configures once the network is known, and
// the selectors read them by injection — so a seam the command forgets to configure sees no
// source and falls back to prompting for an export file, on a run that has no file. Nothing
// in the standalone gate can see that: the wiring lives in this command, which only boots
// here. Formidable's seam shipped without this line once, with a green gate behind it.
// ---

it('points every form-plugin seam at the migration API on an api run', function (): void {
    // The control: a fresh container has configured no seam.
    expect(app(GravityFormsLoader::class)->hasMigrationApiSource())->toBeFalse()
        ->and(app(NinjaFormsLoader::class)->hasMigrationApiSource())->toBeFalse()
        ->and(app(FormidableFormsLoader::class)->hasMigrationApiSource())->toBeFalse()
        ->and(app(MinervaKbSettingsLoader::class)->hasMigrationApiSource())->toBeFalse();

    $command = $this->artisan('statamic:import:wordpress', [
        '--source' => 'api',
        '--network' => 'https://materialize.invalid',
    ]);

    Assert::assertInstanceOf(PendingCommand::class, $command);

    // The seams are configured before the first network read, so the run's failure to reach
    // the unresolvable host is expected and comes after the state this asserts.
    $command->expectsQuestion("Paste that network's migration API key", 'not-a-real-key');

    $command->run();

    // Each seam is a singleton, so the instance the command configured is the one read here.
    expect(app(GravityFormsLoader::class)->hasMigrationApiSource())->toBeTrue()
        ->and(app(NinjaFormsLoader::class)->hasMigrationApiSource())->toBeTrue()
        ->and(app(FormidableFormsLoader::class)->hasMigrationApiSource())->toBeTrue()
        ->and(app(MinervaKbSettingsLoader::class)->hasMigrationApiSource())->toBeTrue();
})->group('network-option');

// ---
// `--minerva-generation` (wordpress-importer-archive#1235). The option is validated and handed to the MinervaKB settings
// loader by this command, which only boots here, so these are the only tests that can see it.
// ---

it('passes a declared MinervaKB generation to the settings loader, case-insensitively', function (): void {
    // The control: a fresh container has declared nothing.
    expect(app(MinervaKbSettingsLoader::class)->declaredGeneration())->toBeNull();

    $command = $this->artisan('statamic:import:wordpress', [
        '--source' => 'api',
        '--network' => 'https://materialize.invalid',
        '--minerva-generation' => 'V2',
    ]);

    Assert::assertInstanceOf(PendingCommand::class, $command);

    // The loader is configured before the first network read, so the run's failure to reach
    // the unresolvable host comes after the state this asserts.
    $command->expectsQuestion("Paste that network's migration API key", 'not-a-real-key');

    $command->run();

    expect(app(MinervaKbSettingsLoader::class)->declaredGeneration())->toBe('v2');
})->group('minerva-generation-option');

it('refuses an unknown MinervaKB generation before asking for anything', function (): void {
    $command = $this->artisan('statamic:import:wordpress', [
        '--source' => 'api',
        '--network' => 'https://materialize.invalid',
        '--minerva-generation' => 'v3',
    ]);

    Assert::assertInstanceOf(PendingCommand::class, $command);

    // No key question is expected: a typo is reported before any credential is asked for.
    $command->expectsOutputToContain("The --minerva-generation option must be one of: v1, v2, got 'v3'.")
        ->assertExitCode(1);

    $command->run();

    expect(app(MinervaKbSettingsLoader::class)->hasMigrationApiSource())->toBeFalse();
})->group('minerva-generation-option');

it('refuses --minerva-generation on a file-sourced run rather than ignoring it', function (): void {
    $command = $this->artisan('statamic:import:wordpress', [
        'file' => '/does/not/matter.xml',
        '--minerva-generation' => 'v1',
    ]);

    Assert::assertInstanceOf(PendingCommand::class, $command);

    $command->expectsOutputToContain('The --minerva-generation option applies only with --source=api.')
        ->assertExitCode(1);

    $command->run();
})->group('minerva-generation-option');

// ---
// Step 13 - the navigation and Bard passes run only for a type that declares them (#109)
// ---

it('runs the navigation pass for a scoped page run', function (): void {
    // The control for the redirect case below: without it, a selector double that was
    // never consulted would pass that test for a command that skipped the pass always.
    $selector = Mockery::mock(NavigationMenuSelector::class)->shouldIgnoreMissing();
    importCommandExpectation($selector, 'confirmActivePrimaryNavigation')->once()->andReturnFalse();
    importCommandRegisterWithDoubles(null, null, null, null, null, $selector);

    $xmlPath = $this->createTemporaryXmlFile($this->buildXmlContent([$this->getGutenbergPageItem()]));
    importCommandTempFiles($xmlPath);

    $result = importCommandRun($xmlPath, ['--content-type' => 'page']);

    expect($result['exit'])->toBe(0)
        ->and($result['output'])
        ->toContain('Skipping navigation menu import - no active menu in primary navigation position')
        ->toContain('Sanitizing Bard content in entries...');
});

it('skips the navigation and Bard passes for a redirect rule run, and never consults the menus', function (): void {
    // The export carries a page as well, so the skip is the declaration's and not the
    // export's. The global creator is a double, so no redirects global is written.
    $globals = Mockery::mock(GlobalCreator::class)->shouldIgnoreMissing();
    importCommandExpectation($globals, 'createGlobals')->once();
    $selector = Mockery::mock(NavigationMenuSelector::class)->shouldIgnoreMissing();
    $selector->shouldNotReceive('confirmActivePrimaryNavigation');
    $selector->shouldNotReceive('selectNavigationMenu');
    importCommandRegisterWithDoubles(null, null, null, null, $globals, $selector);

    $xmlPath = $this->createTemporaryXmlFile($this->buildXmlContent([
        $this->getGutenbergPageItem(),
        $this->generatePageItem(['post_id' => 124, 'title' => 'Old', 'slug' => 'old', 'post_type' => 'redirect_rule']),
    ]));
    importCommandTempFiles($xmlPath);

    $result = importCommandRun($xmlPath, ['--content-type' => 'redirect_rule']);

    expect($result['exit'])->toBe(0)
        ->and($result['output'])
        ->toContain("Selected content type: 'redirect_rule'")
        ->toContain('Skipping navigation menu import - no imported content type declares it.')
        ->toContain('Skipping Bard content sanitization - no imported content type declares it.')
        ->not->toContain('no active menu in primary navigation position')
        ->not->toContain('Sanitizing Bard content in entries...');
});

/**
 * An attachment `<item>` carrying a photo credit, matching `_thumbnail_id` 999.
 *
 * `WordPressImportTestTrait::getAssetItem()` writes no postmeta, so it exercises the
 * uncredited path. This is its credited twin: `_media_credit` is the structured key
 * `AttachmentCreditResolver` reads first, so the imported asset lands with a
 * non-empty `creator` and the page path sets `featured_image_credit_query` (wordpress-importer-archive#603).
 *
 * Defined here rather than parameterizing the trait helper, which several suites
 * share.
 *
 * @return string The attachment item XML
 */
function importCommandCreditedAssetItem(): string
{
    return '        <item>
        <title><![CDATA[Credited Test Image]]></title>
        <link>https://supplychain.uams.edu/credited-test-image/</link>
        <pubDate></pubDate>
        <dc:creator><![CDATA[uamsonline]]></dc:creator>
        <guid isPermaLink="false">https://supplychain.uams.edu/?attachment_id=999</guid>
        <description></description>
        <content:encoded><![CDATA[]]></content:encoded>
        <excerpt:encoded><![CDATA[]]></excerpt:encoded>
        <wp:post_id>999</wp:post_id>
        <wp:post_date><![CDATA[2024-12-09 16:04:12]]></wp:post_date>
        <wp:post_date_gmt><![CDATA[0000-00-00 00:00:00]]></wp:post_date_gmt>
        <wp:post_modified><![CDATA[2024-12-09 16:04:12]]></wp:post_modified>
        <wp:post_modified_gmt><![CDATA[2024-12-09 22:04:12]]></wp:post_modified_gmt>
        <wp:comment_status><![CDATA[closed]]></wp:comment_status>
        <wp:ping_status><![CDATA[closed]]></wp:ping_status>
        <wp:post_name><![CDATA[credited-test-image]]></wp:post_name>
        <wp:status><![CDATA[inherit]]></wp:status>
        <wp:post_parent>123</wp:post_parent>
        <wp:menu_order>0</wp:menu_order>
        <wp:post_type><![CDATA[attachment]]></wp:post_type>
        <wp:post_password><![CDATA[]]></wp:post_password>
        <wp:is_sticky>0</wp:is_sticky>
        <wp:attachment_url><![CDATA[https://www.w3.org/assets/logos/w3c/w3c-full.png]]></wp:attachment_url>
        <wp:postmeta>
            <wp:meta_key><![CDATA[_media_credit]]></wp:meta_key>
            <wp:meta_value><![CDATA[Bruce Wayne/UAMS]]></wp:meta_value>
        </wp:postmeta>
    </item>';
}

/**
 * A credited attachment `<item>` whose image cannot resolve to a URL.
 *
 * Carries `_media_credit`, so the credit resolves, but no `wp:attachment_url` — the
 * shape an attachment row takes once its file has left the media library. With
 * uploads disabled the importer returns that URL as a string, so this yields an
 * empty string rather than null, exercising the non-empty half of wordpress-importer-archive#439's first
 * conjunct.
 *
 * @return string The attachment item XML
 */
function importCommandCreditedAssetItemWithoutUrl(): string
{
    return '        <item>
        <title><![CDATA[Credited Image With No File]]></title>
        <link>https://supplychain.uams.edu/credited-no-file/</link>
        <pubDate></pubDate>
        <dc:creator><![CDATA[uamsonline]]></dc:creator>
        <guid isPermaLink="false">https://supplychain.uams.edu/?attachment_id=999</guid>
        <description></description>
        <content:encoded><![CDATA[]]></content:encoded>
        <excerpt:encoded><![CDATA[]]></excerpt:encoded>
        <wp:post_id>999</wp:post_id>
        <wp:post_date><![CDATA[2024-12-09 16:04:12]]></wp:post_date>
        <wp:post_date_gmt><![CDATA[0000-00-00 00:00:00]]></wp:post_date_gmt>
        <wp:post_modified><![CDATA[2024-12-09 16:04:12]]></wp:post_modified>
        <wp:post_modified_gmt><![CDATA[2024-12-09 22:04:12]]></wp:post_modified_gmt>
        <wp:comment_status><![CDATA[closed]]></wp:comment_status>
        <wp:ping_status><![CDATA[closed]]></wp:ping_status>
        <wp:post_name><![CDATA[credited-no-file]]></wp:post_name>
        <wp:status><![CDATA[inherit]]></wp:status>
        <wp:post_parent>123</wp:post_parent>
        <wp:menu_order>0</wp:menu_order>
        <wp:post_type><![CDATA[attachment]]></wp:post_type>
        <wp:post_password><![CDATA[]]></wp:post_password>
        <wp:is_sticky>0</wp:is_sticky>
        <wp:attachment_url><![CDATA[]]></wp:attachment_url>
        <wp:postmeta>
            <wp:meta_key><![CDATA[_media_credit]]></wp:meta_key>
            <wp:meta_value><![CDATA[Bruce Wayne/UAMS]]></wp:meta_value>
        </wp:postmeta>
    </item>';
}
