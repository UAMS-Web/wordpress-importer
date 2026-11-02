<?php

declare(strict_types=1);

/**
 * The cross-plugin FormPluginCoordinator: detection, form creation and the entry rewrite (#98).
 *
 * The three plugin coordinators are test doubles, so each test decides what a plugin detects,
 * supplies and creates. The block processors' static form-ID caches and the coordinator's
 * static handle caches are seeded through reflection and cleared after every test. The entry
 * rewrite runs against real entries; `PreventsSavingStacheItemsToDisk` points the Stache at a
 * scratch directory that is deleted on teardown, so they never land in the working tree.
 *
 * @command  php vendor/bin/pest tests/Integration/FormPluginCoordinatorTest.php
 */

use Illuminate\Console\Command;
use Statamic\Entries\Entry;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Form as FormFacade;
use Statamic\Facades\Site;
use Statamic\Forms\Form;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\FormCreationCoordinator as FormidableFormCreationCoordinator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\FormidableFormsCoordinator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormPluginCoordinator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FormCreationCoordinator as GravityFormCreationCoordinator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\GravityFormsCoordinator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms\FormCreationCoordinator as NinjaFormCreationCoordinator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms\NinjaFormsCoordinator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\FormidableFormsBlockProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\FormidableFormsShortcodeProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\GravityFormsBlockProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\GravityFormsShortcodeProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\NinjaFormsBlockProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\NinjaFormsShortcodeProcessor;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

/**
 * A coordinator that records each collection cache it clears.
 */
final class FpcoRecordingCoordinator extends FormPluginCoordinator
{
    /**
     * The collection handles cleared, with whether their items were cleared too.
     *
     * @var list<array{0: string, 1: bool}>
     */
    public array $cleared = [];

    /**
     * Record the clear, then clear as the coordinator does.
     *
     * @param  string  $collectionHandle  The collection handle
     * @param  bool  $clearItems  Whether to clear the cached entries too
     */
    protected function clearCollectionCache(string $collectionHandle, bool $clearItems = false): void
    {
        $this->cleared[] = [$collectionHandle, $clearItems];

        parent::clearCollectionCache($collectionHandle, $clearItems);
    }
}

/**
 * Seed a processor's static form-ID cache.
 *
 * @param  class-string  $processor  The processor class
 * @param  list<string>  $formIds  The form IDs it recorded
 */
function fpcoSeedFormIds(string $processor, array $formIds): void
{
    (new ReflectionClass($processor))->setStaticPropertyValue('formIds', $formIds);
}

/**
 * Read every processor's static form-ID cache.
 *
 * @return list<array<array-key, string>> The caches, Gravity Forms shortcode first
 */
function fpcoProcessorFormIds(): array
{
    return [
        GravityFormsShortcodeProcessor::getFormIds(), GravityFormsBlockProcessor::getFormIds(),
        FormidableFormsShortcodeProcessor::getFormIds(), FormidableFormsBlockProcessor::getFormIds(),
        NinjaFormsShortcodeProcessor::getFormIds(), NinjaFormsBlockProcessor::getFormIds(),
    ];
}

/**
 * Seed or read one of the coordinator's static handle caches.
 *
 * @param  string  $property  createdGravityFormsHandles, createdFormidableFormsHandles or createdNinjaFormsHandles
 * @param  ?array<array-key, string>  $handles  The handles to store, or null to only read
 * @return mixed The cache's value
 */
function fpcoHandles(string $property, ?array $handles = null): mixed
{
    $class = new ReflectionClass(FormPluginCoordinator::class);

    if ($handles !== null) {
        $class->setStaticPropertyValue($property, $handles);
    }

    return $class->getStaticPropertyValue($property);
}

/**
 * Seed the content's referenced form IDs per plugin.
 *
 * @param  FormPluginCoordinator  $coordinator  The coordinator
 * @param  array<string, array<array-key, list<string>>>  $referenced  Plugin => form ID => page slugs
 */
function fpcoSeedReferenced(FormPluginCoordinator $coordinator, array $referenced): void
{
    (new ReflectionProperty($coordinator, 'referencedFormPages'))->setValue($coordinator, $referenced);
}

/**
 * Read the content's referenced form IDs per plugin.
 *
 * @param  FormPluginCoordinator  $coordinator  The coordinator
 * @return mixed The recorded references
 */
function fpcoReferenced(FormPluginCoordinator $coordinator): mixed
{
    return (new ReflectionProperty($coordinator, 'referencedFormPages'))->getValue($coordinator);
}

/**
 * Make a Statamic form, as a plugin coordinator returns one.
 *
 * @param  ?string  $handle  The form handle, or null for a form without one
 * @return Form The form
 */
function fpcoForm(?string $handle): Form
{
    $form = FormFacade::make($handle);

    if (! $form instanceof Form) {
        throw new LogicException('FormFacade::make() returned no Statamic form.');
    }

    return $form;
}

/**
 * A Bard form set as the import first writes it, naming the WordPress form.
 *
 * @param  string  $note  The `import_wordpress_notes` value
 * @return array<string, mixed> The set
 */
function fpcoFormSet(string $note): array
{
    return ['type' => 'set', 'attrs' => ['id' => 'set-'.\md5($note), 'values' => ['type' => 'form', 'import_wordpress_notes' => $note]]];
}

/**
 * Save an entry in a collection, creating the collection when needed.
 *
 * @param  string  $collection  The collection handle
 * @param  string  $slug  The entry slug
 * @param  array<string, mixed>  $data  The entry data, beside a title made from the slug
 * @return string The entry ID
 */
function fpcoEntry(string $collection, string $slug, array $data): string
{
    if (CollectionFacade::find($collection) === null) {
        $made = CollectionFacade::make($collection);
        $made->sites(['default']);
        $made->save();
    }

    $entry = EntryFacade::make();

    if (! $entry instanceof Entry) {
        throw new LogicException('EntryFacade::make() returned no Statamic entry.');
    }

    $entry->collection($collection);
    $entry->locale('default');
    $entry->slug($slug);
    $entry->data(['title' => \ucfirst($slug), ...$data]);
    $entry->save();

    $id = $entry->id();

    if (! \is_string($id) || $id === '') {
        throw new LogicException("The entry '{$slug}' was saved without an ID.");
    }

    return $id;
}

/**
 * Walk into nested arrays, answering null at the first step that is not one.
 *
 * @param  mixed  $value  The value to walk into
 * @param  int|string  ...$keys  The keys to follow
 * @return mixed The value at the end of the path, or null
 */
function fpcoDig(mixed $value, int|string ...$keys): mixed
{
    foreach ($keys as $key) {
        if (! \is_array($value) || ! \array_key_exists($key, $value)) {
            return null;
        }

        $value = $value[$key];
    }

    return $value;
}

/**
 * Read the unresolved-form warnings recorded so far.
 *
 * @return list<mixed> The warnings
 */
function fpcoUnresolvedWarnings(): array
{
    $warnings = fpcoDig(ImportWarningManager::getWarningsByCategory('content'), 'unresolved_form_references');

    return \is_array($warnings) ? \array_values($warnings) : [];
}

beforeEach(function (): void {
    $this->gravity = $this::createStub(GravityFormsCoordinator::class);
    $this->formidable = $this::createStub(FormidableFormsCoordinator::class);
    $this->ninja = $this::createStub(NinjaFormsCoordinator::class);
    $this->coordinator = new FormPluginCoordinator($this->gravity, $this->formidable, $this->ninja);
    $this->output = new BufferedOutput;
    $this->assetImporter = $this::createStub(AssetImporter::class);
    ImportWarningManager::clearWarnings();
});

afterEach(function (): void {
    foreach ([GravityFormsShortcodeProcessor::class, GravityFormsBlockProcessor::class, FormidableFormsShortcodeProcessor::class, FormidableFormsBlockProcessor::class, NinjaFormsShortcodeProcessor::class, NinjaFormsBlockProcessor::class] as $processor) {
        $processor::clearFormIds();
    }

    foreach (['createdGravityFormsHandles', 'createdFormidableFormsHandles', 'createdNinjaFormsHandles'] as $property) {
        fpcoHandles($property, []);
    }

    ImportWarningManager::clearWarnings();
});

it('runs detection for each plugin with content and reports whether any loaded data', function (bool $gravity, bool $formidable, bool $ninja, bool $expected): void {
    $gravityCoordinator = $this->createMock(GravityFormsCoordinator::class);
    $formidableCoordinator = $this->createMock(FormidableFormsCoordinator::class);
    $ninjaCoordinator = $this->createMock(NinjaFormsCoordinator::class);
    $this->coordinator = new FormPluginCoordinator($gravityCoordinator, $formidableCoordinator, $ninjaCoordinator);

    $parser = $this::createStub(XmlParser::class);
    $input = new ArrayInput([]);
    $command = $this::createStub(Command::class);

    $gravityCoordinator->method('hasGravityFormsContent')->willReturn(true);
    $gravityCoordinator->expects($this->once())->method('handleGravityFormsDetection')->with($parser, $input, $this->output, $command)->willReturn($gravity);
    $gravityCoordinator->method('getGravityFormsFormIdsWithPages')->willReturn([3 => ['a']]);
    $formidableCoordinator->method('hasFormidableFormsContent')->willReturn(true);
    $formidableCoordinator->expects($this->once())->method('handleFormidableFormsDetection')->willReturn($formidable);
    $formidableCoordinator->method('getFormidableFormsFormIdsWithPages')->willReturn([4 => ['b']]);
    $ninjaCoordinator->method('hasNinjaFormsContent')->willReturn(true);
    $ninjaCoordinator->expects($this->once())->method('handleNinjaFormsDetection')->willReturn($ninja);
    $ninjaCoordinator->method('getNinjaFormsFormIdsWithPages')->willReturn([5 => ['c']]);

    expect($this->coordinator->handleFormPluginDetection($parser, $input, $this->output, $command))->toBe($expected)
        ->and(fpcoReferenced($this->coordinator))
        ->toBe(['Gravity Forms' => [3 => ['a']], 'Formidable Forms' => [4 => ['b']], 'Ninja Forms' => [5 => ['c']]]);
})->with([
    'none loaded' => [false, false, false, false],
    'only Gravity Forms' => [true, false, false, true],
    'only Formidable Forms' => [false, true, false, true],
    'only Ninja Forms' => [false, false, true, true],
]);

it('skips detection for a plugin whose content is absent', function (): void {
    $gravityCoordinator = $this->createMock(GravityFormsCoordinator::class);
    $formidableCoordinator = $this->createMock(FormidableFormsCoordinator::class);
    $ninjaCoordinator = $this->createMock(NinjaFormsCoordinator::class);
    $this->coordinator = new FormPluginCoordinator($gravityCoordinator, $formidableCoordinator, $ninjaCoordinator);

    $parser = $this::createStub(XmlParser::class);

    $gravityCoordinator->method('hasGravityFormsContent')->willReturn(false);
    $gravityCoordinator->expects($this->never())->method('handleGravityFormsDetection');
    $formidableCoordinator->method('hasFormidableFormsContent')->willReturn(false);
    $formidableCoordinator->expects($this->never())->method('handleFormidableFormsDetection');
    $ninjaCoordinator->method('hasNinjaFormsContent')->willReturn(false);
    $ninjaCoordinator->expects($this->never())->method('handleNinjaFormsDetection');

    expect($this->coordinator->handleFormPluginDetection($parser, new ArrayInput([]), $this->output, $this::createStub(Command::class)))->toBeFalse()
        ->and(fpcoReferenced($this->coordinator))->toBe([]);
});

it('clears every plugin\'s data and every created-handle cache', function (): void {
    $gravityCoordinator = $this->createMock(GravityFormsCoordinator::class);
    $formidableCoordinator = $this->createMock(FormidableFormsCoordinator::class);
    $ninjaCoordinator = $this->createMock(NinjaFormsCoordinator::class);
    $this->coordinator = new FormPluginCoordinator($gravityCoordinator, $formidableCoordinator, $ninjaCoordinator);

    fpcoHandles('createdGravityFormsHandles', ['1' => 'a']);
    fpcoHandles('createdFormidableFormsHandles', ['2' => 'b']);
    fpcoHandles('createdNinjaFormsHandles', ['3' => 'c']);

    $gravityCoordinator->expects($this->once())->method('clearGravityFormsData');
    $formidableCoordinator->expects($this->once())->method('clearFormidableFormsData');
    $ninjaCoordinator->expects($this->once())->method('clearNinjaFormsData');

    $this->coordinator->clearAllCaches();

    expect([fpcoHandles('createdGravityFormsHandles'), fpcoHandles('createdFormidableFormsHandles'), fpcoHandles('createdNinjaFormsHandles')])->toBe([[], [], []]);
});

it('creates each referenced form the supplied export contains, and caches its handle', function (): void {
    $gravityCoordinator = $this->createMock(GravityFormsCoordinator::class);
    $formidableCoordinator = $this->createMock(FormidableFormsCoordinator::class);
    $ninjaCoordinator = $this->createMock(NinjaFormsCoordinator::class);
    $this->coordinator = new FormPluginCoordinator($gravityCoordinator, $formidableCoordinator, $ninjaCoordinator);

    Site::setSites(['default' => ['name' => 'Main Site', 'url' => '/', 'locale' => 'en_US']]);

    // Each plugin has a form only a shortcode references (5, 3 and 9), one both reference, and
    // one (99, 50 and 70) the export lacks, which is filtered out before creation.
    fpcoSeedFormIds(GravityFormsShortcodeProcessor::class, ['7', '5']);
    fpcoSeedFormIds(GravityFormsBlockProcessor::class, ['7', '8', '99']);
    fpcoSeedFormIds(FormidableFormsShortcodeProcessor::class, ['3', '4']);
    fpcoSeedFormIds(FormidableFormsBlockProcessor::class, ['4', '50']);
    fpcoSeedFormIds(NinjaFormsShortcodeProcessor::class, ['9', '2', '6']);
    fpcoSeedFormIds(NinjaFormsBlockProcessor::class, ['6', '70']);

    $gravityCoordinator->method('getGravityFormsData')->willReturn(['not a form', ['id' => '7'], ['id' => 8], ['title' => 'no id'], ['id' => '5']]);
    $formidableCoordinator->method('getFormidableFormsData')->willReturn([['id' => 3], ['id' => 4]]);
    $ninjaCoordinator->method('getNinjaFormsData')->willReturn([2 => ['id' => 2], 6 => ['id' => 6.0], 9 => ['id' => '9']]);

    $gravityCoordinator->expects($this->exactly(3))->method('createGravityFormsFormIfNeeded')
        ->willReturnCallback(fn (int $id, string $name, string $site, AssetImporter $importer): ?Form => match (true) {
            $name !== 'Main Site' || $site !== 'default' || $importer !== $this->assetImporter => throw new LogicException('Unexpected arguments.'),
            $id === 7 => fpcoForm('gravity_seven'),
            $id === 5 => fpcoForm(null),
            default => null,
        });
    // Forms 5, 3 and 9 are created without a handle, so nothing is cached or reported for them,
    // and the forms after them are still processed.
    $formidableCoordinator->expects($this->exactly(2))->method('createFormidableFormsFormIfNeeded')
        ->willReturnCallback(static fn (int $id): Form => fpcoForm($id === 3 ? null : 'formidable_four'));
    $ninjaCoordinator->expects($this->exactly(3))->method('createNinjaFormsFormIfNeeded')
        ->willReturnCallback(static fn (int $id): Form => fpcoForm($id === 9 ? null : "ninja_{$id}"));

    $this->coordinator->processFormImports('default', $this->output, $this->assetImporter);

    expect(\str_replace(\PHP_EOL, "\n", $this->output->fetch()))->toBe(
        "Processing form imports...\n"
        ."Processing Gravity Forms form ID '7'...\n"
        ."  - Created form 'gravity_seven' in Statamic\n"
        ."Processing Gravity Forms form ID '5'...\n"
        ."Processing Gravity Forms form ID '8'...\n"
        ."Processing Formidable Forms form ID '3'...\n"
        ."Processing Formidable Forms form ID '4'...\n"
        ."  - Created form 'formidable_four' in Statamic\n"
        ."Processing Ninja Forms form ID '9'...\n"
        ."Processing Ninja Forms form ID '2'...\n"
        ."  - Created form 'ninja_2' in Statamic\n"
        ."Processing Ninja Forms form ID '6'...\n"
        ."  - Created form 'ninja_6' in Statamic\n"
    )
        ->and(fpcoHandles('createdGravityFormsHandles'))->toBe(['7' => 'gravity_seven'])
        ->and(fpcoHandles('createdFormidableFormsHandles'))->toBe(['4' => 'formidable_four'])
        ->and(fpcoHandles('createdNinjaFormsHandles'))->toBe(['2' => 'ninja_2', '6' => 'ninja_6'])
        // The processors' caches are spent once their forms are processed.
        ->and(fpcoProcessorFormIds())->toBe([[], [], [], [], [], []]);
});

it('creates every referenced form when no export was supplied to filter against', function (): void {
    $gravityCoordinator = $this->createMock(GravityFormsCoordinator::class);
    $formidableCoordinator = $this->createMock(FormidableFormsCoordinator::class);
    $ninjaCoordinator = $this->createMock(NinjaFormsCoordinator::class);
    $this->coordinator = new FormPluginCoordinator($gravityCoordinator, $formidableCoordinator, $ninjaCoordinator);

    fpcoSeedFormIds(GravityFormsBlockProcessor::class, ['7']);
    fpcoSeedFormIds(FormidableFormsBlockProcessor::class, ['3']);
    fpcoSeedFormIds(NinjaFormsBlockProcessor::class, ['2']);

    $gravityCoordinator->method('getGravityFormsData')->willReturn(null);
    $formidableCoordinator->method('getFormidableFormsData')->willReturn([]);
    $ninjaCoordinator->method('getNinjaFormsData')->willReturn(null);

    $gravityCoordinator->expects($this->once())->method('createGravityFormsFormIfNeeded')->with(7)->willReturn(null);
    $formidableCoordinator->expects($this->once())->method('createFormidableFormsFormIfNeeded')->with(3)->willReturn(null);
    $ninjaCoordinator->expects($this->once())->method('createNinjaFormsFormIfNeeded')->with(2)->willReturn(null);

    $this->coordinator->processFormImports('a_site_that_does_not_exist', $this->output, $this->assetImporter);

    expect(\str_contains($this->output->fetch(), 'Processing form imports...'))->toBeTrue();
});

it('names the site by its handle when Statamic has no such site', function (): void {
    $gravityCoordinator = $this->createMock(GravityFormsCoordinator::class);
    $this->coordinator = new FormPluginCoordinator($gravityCoordinator, $this->formidable, $this->ninja);

    fpcoSeedFormIds(GravityFormsBlockProcessor::class, ['7']);
    $gravityCoordinator->method('getGravityFormsData')->willReturn(null);
    $gravityCoordinator->expects($this->once())->method('createGravityFormsFormIfNeeded')->with(7, 'a_site_that_does_not_exist', 'a_site_that_does_not_exist')->willReturn(null);

    $this->coordinator->processFormImports('a_site_that_does_not_exist', $this->output, $this->assetImporter);
});

it('names the site by its handle when the site\'s name is empty', function (): void {
    $gravityCoordinator = $this->createMock(GravityFormsCoordinator::class);
    $this->coordinator = new FormPluginCoordinator($gravityCoordinator, $this->formidable, $this->ninja);

    Site::setSites(['default' => ['name' => '', 'url' => '/', 'locale' => 'en_US']]);
    fpcoSeedFormIds(GravityFormsBlockProcessor::class, ['7']);
    $gravityCoordinator->method('getGravityFormsData')->willReturn(null);
    $gravityCoordinator->expects($this->once())->method('createGravityFormsFormIfNeeded')->with(7, 'default', 'default')->willReturn(null);

    $this->coordinator->processFormImports('default', $this->output, $this->assetImporter);
});

it('hands the page data mapping to each plugin\'s creation coordinator only when there is one', function (array $mapping): void {
    $gravityCreation = $this->createMock(GravityFormCreationCoordinator::class);
    $formidableCreation = $this->createMock(FormidableFormCreationCoordinator::class);
    $ninjaCreation = $this->createMock(NinjaFormCreationCoordinator::class);
    $this->gravity->method('getFormCreationCoordinator')->willReturn($gravityCreation);
    $this->formidable->method('getFormCreationCoordinator')->willReturn($formidableCreation);
    $this->ninja->method('getFormCreationCoordinator')->willReturn($ninjaCreation);

    $gravityCreation->expects($mapping === [] ? $this->never() : $this->once())->method('setPageDataMapping')->with($mapping);
    $formidableCreation->expects($mapping === [] ? $this->never() : $this->once())->method('setPageDataMapping')->with($mapping);
    $ninjaCreation->expects($mapping === [] ? $this->never() : $this->once())->method('setPageDataMapping')->with($mapping);

    $this->coordinator->processFormImports('default', $this->output, $this->assetImporter, $mapping);
})->with([
    'a mapping' => [[12 => ['id' => 'entry-12']]],
    'no mapping' => [[]],
]);

it('skips a plugin whose creation coordinator is missing', function (): void {
    $this->gravity->method('getFormCreationCoordinator')->willReturn(null);
    $this->formidable->method('getFormCreationCoordinator')->willReturn(null);
    $this->ninja->method('getFormCreationCoordinator')->willReturn(null);

    $this->coordinator->processFormImports('default', $this->output, $this->assetImporter, [12 => ['id' => 'entry-12']]);

    expect($this->output->fetch())->toBe('');
});

it('reports each plugin\'s referenced forms its export lacks, comparing IDs as strings', function (string $plugin): void {
    fpcoSeedReferenced($this->coordinator, [$plugin => [7 => ['contact'], '07' => ['legacy']]]);
    $this->gravity->method('getGravityFormsData')->willReturn([['id' => '7']]);
    $this->formidable->method('getFormidableFormsData')->willReturn([['id' => '7']]);
    $this->ninja->method('getNinjaFormsData')->willReturn([['id' => '7']]);

    $this->coordinator->processFormImports('default', $this->output, $this->assetImporter);

    // '07' and '7' are equal as numbers, so only a strict string comparison reports it.
    expect(\str_replace(\PHP_EOL, "\n", $this->output->fetch()))->toBe(
        "  1 referenced {$plugin} form(s) are not in the supplied export:\n"
        ."      form 07  (page: legacy)\n"
        ."    These pages import with a placeholder note and no form.\n"
    );
})->with(['Gravity Forms', 'Formidable Forms', 'Ninja Forms']);

it('reports referenced forms the supplied export lacks, and stays quiet otherwise', function (): void {
    fpcoSeedReferenced($this->coordinator, [
        'Gravity Forms' => [7 => ['clinics/family-medicine'], '12' => ['about', 'research'], 13 => []],
        // Every Formidable Forms reference is satisfied, so it reports nothing.
        'Formidable Forms' => [3 => ['contact']],
        // No Ninja Forms export was supplied, so its references are not reconciled.
        'Ninja Forms' => [2 => ['join']],
    ]);
    $this->gravity->method('getGravityFormsData')->willReturn([['id' => '7']]);
    $this->formidable->method('getFormidableFormsData')->willReturn([['id' => 3]]);
    $this->ninja->method('getNinjaFormsData')->willReturn(null);

    $this->coordinator->processFormImports('default', $this->output, $this->assetImporter);

    expect(\str_replace(\PHP_EOL, "\n", $this->output->fetch()))->toBe(
        "  2 referenced Gravity Forms form(s) are not in the supplied export:\n"
        ."      form 12  (page: about, research)\n"
        ."      form 13  (no page recorded)\n"
        ."    These pages import with a placeholder note and no form.\n"
    );
});

it('rewrites and reports nothing when no form was created', function (): void {
    fpcoEntry('pages', 'dead', ['content' => [fpcoFormSet('Gravity Forms form ID 99')]]);
    $coordinator = new FpcoRecordingCoordinator($this->gravity, $this->formidable, $this->ninja);

    expect($coordinator->updateAllFormHandlesInEntries('default', $this->output))->toBe([])
        ->and(fpcoUnresolvedWarnings())->toBe([])
        ->and($coordinator->cleared)->toBe([]);
});

it('clears each form-bearing collection\'s cache, items included, before reading it', function (): void {
    fpcoHandles('createdGravityFormsHandles', ['7' => 'created_seven']);
    $coordinator = new FpcoRecordingCoordinator($this->gravity, $this->formidable, $this->ninja);

    $coordinator->updateAllFormHandlesInEntries('default', $this->output);

    expect($coordinator->cleared)->toBe([['pages', true], ['posts', true], ['people', true], ['tables', true]]);
});

it('rewrites form sets in content and page builder fields to the created handles', function (string $plugin, string $property): void {
    fpcoHandles($property, ['7' => 'created_seven']);
    $id = fpcoEntry('pages', 'contact', [
        'original_wordpress_url' => 'https://www.uams.edu/contact/',
        'content' => [['type' => 'paragraph'], fpcoFormSet("{$plugin} form ID 7"), fpcoFormSet("{$plugin} form ID 99")],
        'page_builder' => [['type' => 'section', 'body' => [fpcoFormSet("{$plugin} form ID 7")]]],
    ]);
    // An entry with nothing to rewrite is left out of the result.
    fpcoEntry('pages', 'about', ['content' => [['type' => 'paragraph']]]);

    $updated = $this->coordinator->updateAllFormHandlesInEntries('default', $this->output);

    expect(\array_keys($updated))->toBe([$id])
        ->and(fpcoDig($updated, $id, 'collection'))->toBe('pages')
        ->and(fpcoDig($updated, $id, 'form_updates'))->toBe([
            ['form_note' => "{$plugin} form ID 7", 'form_handle' => 'created_seven'],
            ['form_note' => "{$plugin} form ID 7", 'form_handle' => 'created_seven'],
        ])
        ->and(fpcoDig($updated, $id, 'data', 'content', 1, 'attrs', 'values'))->toBe(['type' => 'form', 'form' => 'created_seven'])
        // The unresolved set is left exactly as authored.
        ->and(fpcoDig($updated, $id, 'data', 'content', 2))->toBe(fpcoFormSet("{$plugin} form ID 99"))
        ->and(fpcoDig($updated, $id, 'data', 'page_builder', 0, 'body', 0, 'attrs', 'values', 'form'))->toBe('created_seven')
        ->and(fpcoUnresolvedWarnings())->toBe([[
            'entry_id' => $id,
            'entry_title' => 'Contact',
            'source_url' => 'https://www.uams.edu/contact/',
            'form_notes' => "{$plugin} form ID 99",
            'effect' => 'The Bard set was left exactly as authored, so the page renders without the form rather than failing. The import now completes.',
            'reason' => 'The migration API served no definition for this form, which is what a form deleted in WordPress looks like. A re-run will not resolve it: the reference is in the source content, so the form must be restored in WordPress or the embed removed from the post.',
        ]]);
})->with([
    'Gravity Forms' => ['Gravity Forms', 'createdGravityFormsHandles'],
    'Formidable Forms' => ['Formidable Forms', 'createdFormidableFormsHandles'],
    'Ninja Forms' => ['Ninja Forms', 'createdNinjaFormsHandles'],
]);

it('rewrites form sets in every form-bearing collection', function (string $collection): void {
    fpcoHandles('createdGravityFormsHandles', ['7' => 'created_seven']);
    $id = fpcoEntry($collection, 'contact', ['content' => [fpcoFormSet('Gravity Forms form ID 7')]]);

    expect(fpcoDig($this->coordinator->updateAllFormHandlesInEntries('default', $this->output), $id, 'collection'))->toBe($collection);
})->with(['pages', 'posts', 'people', 'tables']);

it('rewrites an entry whose only resolved set is in its content, and skips fields that are not lists', function (): void {
    fpcoHandles('createdGravityFormsHandles', ['7' => 'created_seven']);
    $id = fpcoEntry('pages', 'contact', ['content' => [fpcoFormSet('Gravity Forms form ID 7')], 'page_builder' => [['type' => 'section']]]);
    fpcoEntry('pages', 'text', ['content' => 'Gravity Forms form ID 7', 'page_builder' => 'Gravity Forms form ID 7']);

    $updated = $this->coordinator->updateAllFormHandlesInEntries('default', $this->output);
    $entry = fpcoDig($updated, $id, 'entry');

    expect(\array_keys($updated))->toBe([$id])
        ->and($entry instanceof Entry ? $entry->id() : null)->toBe($id);
});

it('leaves out an entry with nothing resolved even when its data holds a value unequal to itself', function (): void {
    fpcoHandles('createdGravityFormsHandles', ['7' => 'created_seven']);
    fpcoEntry('pages', 'chart', ['content' => [['type' => 'chart', 'attrs' => ['ratio' => \NAN]]], 'page_builder' => [['type' => 'section', 'ratio' => \NAN]]]);

    expect($this->coordinator->updateAllFormHandlesInEntries('default', $this->output))->toBe([]);
});

it('rebuilds a resolved set, keeps every nested update, and reads past an unresolved one', function (): void {
    fpcoHandles('createdGravityFormsHandles', ['7' => 'created_seven', '8' => 'created_eight']);
    $resolved = static fn (string $setId, int $form): array => ['type' => 'set', 'attrs' => ['id' => $setId, 'values' => ['type' => 'form', 'heading' => 'Ask', 'import_wordpress_notes' => "Gravity Forms form ID {$form}"]]];
    $id = fpcoEntry('pages', 'contact', [
        'content' => [fpcoFormSet('Gravity Forms form ID 99'), $resolved('set-a', 7)],
        'page_builder' => [
            ['type' => 'section', 'body' => [$resolved('set-b', 7), fpcoFormSet('Gravity Forms form ID 97')]],
            ['type' => 'section', 'body' => [$resolved('set-c', 8), fpcoFormSet('Gravity Forms form ID 98')]],
        ],
    ]);

    $updated = $this->coordinator->updateAllFormHandlesInEntries('default', $this->output);

    expect(fpcoDig($updated, $id, 'data', 'content', 1))->toBe(['type' => 'set', 'attrs' => ['id' => 'set-a', 'values' => ['type' => 'form', 'heading' => 'Ask', 'form' => 'created_seven']]])
        ->and(fpcoDig($updated, $id, 'form_updates'))->toBe([
            ['form_note' => 'Gravity Forms form ID 7', 'form_handle' => 'created_seven'],
            ['form_note' => 'Gravity Forms form ID 7', 'form_handle' => 'created_seven'],
            ['form_note' => 'Gravity Forms form ID 8', 'form_handle' => 'created_eight'],
        ])
        ->and(fpcoDig(fpcoUnresolvedWarnings(), 0, 'form_notes'))->toBe('Gravity Forms form ID 99, Gravity Forms form ID 97, Gravity Forms form ID 98');
});

it('reports each dead embed in an entry once, from either field', function (): void {
    fpcoHandles('createdGravityFormsHandles', ['7' => 'created_seven']);
    fpcoEntry('pages', 'dead', [
        'content' => [fpcoFormSet('Gravity Forms form ID 99'), fpcoFormSet('Gravity Forms form ID 99')],
        'page_builder' => [fpcoFormSet('Gravity Forms form ID 98')],
    ]);

    // Nothing resolved, so the entry is not rewritten, but its dead embeds are still reported.
    expect($this->coordinator->updateAllFormHandlesInEntries('default', $this->output))->toBe([])
        ->and(fpcoDig(fpcoUnresolvedWarnings(), 0, 'form_notes'))->toBe('Gravity Forms form ID 99, Gravity Forms form ID 98')
        ->and(fpcoDig(fpcoUnresolvedWarnings(), 0, 'source_url'))->toBeNull();
});

it('drops an empty note from the dead embeds it reports', function (): void {
    fpcoHandles('createdGravityFormsHandles', ['7' => 'created_seven']);
    fpcoEntry('pages', 'blank', ['content' => [fpcoFormSet(''), fpcoFormSet('Gravity Forms form ID 99')]]);

    $this->coordinator->updateAllFormHandlesInEntries('default', $this->output);

    expect(fpcoDig(fpcoUnresolvedWarnings(), 0, 'form_notes'))->toBe('Gravity Forms form ID 99');
});

it('leaves every set that is not a well-formed form set as it was', function (array $set): void {
    fpcoHandles('createdGravityFormsHandles', ['7' => 'created_seven']);
    fpcoEntry('pages', 'odd', ['content' => [$set]]);

    expect($this->coordinator->updateAllFormHandlesInEntries('default', $this->output))->toBe([])
        ->and(fpcoUnresolvedWarnings())->toBe([]);
})->with([
    'not a set' => [['type' => 'paragraph', 'attrs' => ['values' => ['type' => 'form', 'import_wordpress_notes' => 'Gravity Forms form ID 7']]]],
    'no attrs' => [['type' => 'set', 'values' => ['type' => 'form', 'import_wordpress_notes' => 'Gravity Forms form ID 7']]],
    'attrs not a list' => [['type' => 'set', 'attrs' => 'Gravity Forms form ID 7']],
    'no values' => [['type' => 'set', 'attrs' => ['id' => 'x']]],
    'values not a list' => [['type' => 'set', 'attrs' => ['values' => 'Gravity Forms form ID 7']]],
    'not a form' => [['type' => 'set', 'attrs' => ['values' => ['type' => 'image', 'import_wordpress_notes' => 'Gravity Forms form ID 7']]]],
    'no note' => [['type' => 'set', 'attrs' => ['values' => ['type' => 'form']]]],
    'a note that is not a string' => [['type' => 'set', 'attrs' => ['values' => ['type' => 'form', 'import_wordpress_notes' => 7]]]],
]);

it('reads a form ID that is a float, and none that is not a number or a string', function (): void {
    $method = new ReflectionMethod($this->coordinator, 'normalizeIdentifier');

    expect($method->invoke($this->coordinator, 1.5))->toBe('1.5')
        ->and($method->invoke($this->coordinator, 7))->toBe('7')
        ->and($method->invoke($this->coordinator, 'x'))->toBe('x')
        ->and($method->invoke($this->coordinator, null))->toBeNull()
        ->and($method->invoke($this->coordinator, ['7']))->toBeNull();
});
