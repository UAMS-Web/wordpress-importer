<?php

declare(strict_types=1);

/**
 * The per-plugin form creation coordinators and the FormCreationCoordinatorTrait they share (#98).
 *
 * Each coordinator finds one WordPress form in the loaded export data, hands it to
 * FormCreator, configures its email notifications, and caches the result for the rest of the
 * import. The Feature suite exercises that through real imports, which the gate never runs, so
 * these tests drive the coordinators directly with FormCreator and the configuration manager
 * replaced by test doubles. Statamic is booted only for the `Form` objects and the `Log` facade.
 *
 * @command  php vendor/bin/pest tests/Integration/FormCreationCoordinatorTest.php
 */

use Illuminate\Support\Facades\Log;
use Statamic\Facades\Form as FormFacade;
use Statamic\Forms\Form;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Creators\FormCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\BaseDataManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\FormConfigurationManager as FormidableFormConfigurationManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\FormCreationCoordinator as FormidableFormCreationCoordinator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\FormidableFormsCoordinator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FormConfigurationManager as GravityFormConfigurationManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FormCreationCoordinator as GravityFormCreationCoordinator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\GravityFormsCoordinator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms\FormConfigurationManager as NinjaFormConfigurationManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms\FormCreationCoordinator as NinjaFormCreationCoordinator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms\NinjaFormsCoordinator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

beforeEach(function (): void {
    $this->dataManager = $this::createStub(BaseDataManager::class);
    $this->creator = $this::createStub(FormCreator::class);
    $this->assetImporter = $this::createStub(AssetImporter::class);
});

afterEach(function (): void {
    ProcessingContext::clearWordPressPageLink();
    ProcessingContext::clearFormConfigContext();
});

/**
 * The configuration manager class each plugin's coordinator takes.
 *
 * @param  string  $plugin  formidable, gravity or ninja
 * @return class-string<FormidableFormConfigurationManager|GravityFormConfigurationManager|NinjaFormConfigurationManager> The class
 */
function fccConfigurationClass(string $plugin): string
{
    return match ($plugin) {
        'formidable' => FormidableFormConfigurationManager::class,
        'gravity' => GravityFormConfigurationManager::class,
        default => NinjaFormConfigurationManager::class,
    };
}

/**
 * Build one plugin's coordinator over the given collaborators.
 *
 * @param  string  $plugin  formidable, gravity or ninja
 * @param  BaseDataManager  $dataManager  The data manager
 * @param  FormidableFormConfigurationManager|GravityFormConfigurationManager|NinjaFormConfigurationManager  $configuration  The plugin's configuration manager
 * @param  ?FormCreator  $creator  A FormCreator to swap in for the one the constructor builds
 * @return FormidableFormCreationCoordinator|GravityFormCreationCoordinator|NinjaFormCreationCoordinator The coordinator
 */
function fccCoordinator(
    string $plugin,
    BaseDataManager $dataManager,
    FormidableFormConfigurationManager|GravityFormConfigurationManager|NinjaFormConfigurationManager $configuration,
    ?FormCreator $creator = null
): FormidableFormCreationCoordinator|GravityFormCreationCoordinator|NinjaFormCreationCoordinator {
    $coordinator = match (true) {
        $configuration instanceof FormidableFormConfigurationManager && $plugin === 'formidable' => new FormidableFormCreationCoordinator($dataManager, $configuration),
        $configuration instanceof GravityFormConfigurationManager && $plugin === 'gravity' => new GravityFormCreationCoordinator($dataManager, $configuration),
        $configuration instanceof NinjaFormConfigurationManager && $plugin === 'ninja' => new NinjaFormCreationCoordinator($dataManager, $configuration),
        default => throw new LogicException("No configuration manager for {$plugin}."),
    };

    // FormCreator is built inside the constructor, so a double is swapped in afterwards.
    if ($creator instanceof FormCreator) {
        (new ReflectionProperty($coordinator, 'formCreator'))->setValue($coordinator, $creator);
    }

    return $coordinator;
}

/**
 * Call the coordinator's plugin-specific create method.
 *
 * @param  FormidableFormCreationCoordinator|GravityFormCreationCoordinator|NinjaFormCreationCoordinator  $coordinator  The coordinator
 * @param  int  $formId  The WordPress form ID
 * @param  array<int|string, mixed>  $formsData  The loaded export data
 * @param  AssetImporter  $assetImporter  The asset importer
 * @return ?Form The created form
 */
function fccCreate(
    FormidableFormCreationCoordinator|GravityFormCreationCoordinator|NinjaFormCreationCoordinator $coordinator,
    int $formId,
    array $formsData,
    AssetImporter $assetImporter
): ?Form {
    return match (true) {
        $coordinator instanceof FormidableFormCreationCoordinator => $coordinator->createStatamicFormFromFormidableForm($formId, $formsData, 'Main Site', 'default', $assetImporter),
        $coordinator instanceof GravityFormCreationCoordinator => $coordinator->createStatamicFormFromGravityForm($formId, $formsData, 'Main Site', 'default', $assetImporter),
        default => $coordinator->createStatamicFormFromNinjaForm($formId, $formsData, 'Main Site', 'default', $assetImporter),
    };
}

/**
 * Export data in the shape each plugin stores it, holding the target form and its neighbors.
 *
 * Gravity Forms and Formidable Forms keep a list of forms that is scanned for a matching
 * `id`; Ninja Forms keys one export per form ID.
 *
 * @param  string  $plugin  formidable, gravity or ninja
 * @return array{data: array<int|string, mixed>, form: array<string, string>}
 */
function fccExport(string $plugin): array
{
    $form = ['id' => '7', 'title' => 'Contact Us'];

    if ($plugin === 'ninja') {
        return ['data' => [3 => ['title' => 'Other'], 7 => $form], 'form' => $form];
    }

    // A non-array entry and two non-matching forms precede the target, so the scan has to
    // keep going past each of them.
    return ['data' => ['not a form', ['title' => 'No id'], ['id' => 3, 'title' => 'Other'], $form], 'form' => $form];
}

/**
 * The names each plugin passes on: cache prefix, FormCreator plugin name and log label.
 *
 * @param  string  $plugin  formidable, gravity or ninja
 * @return array{prefix: string, name: string, label: string}
 */
function fccNames(string $plugin): array
{
    return match ($plugin) {
        'formidable' => ['prefix' => 'formidable_forms', 'name' => 'formidable-forms', 'label' => 'Formidable Forms'],
        'gravity' => ['prefix' => 'gravity_forms', 'name' => 'gravity-forms', 'label' => 'Gravity Forms'],
        default => ['prefix' => 'ninja_forms', 'name' => 'ninja-forms', 'label' => 'Ninja Forms'],
    };
}

dataset('form plugins', ['formidable', 'gravity', 'ninja']);

it('creates the form from the matching export, configures its email and caches it', function (string $plugin): void {
    $creator = $this->createMock(FormCreator::class);

    $configuration = $this->createMock(fccConfigurationClass($plugin));
    $coordinator = fccCoordinator($plugin, $this->dataManager, $configuration, $creator);
    ['data' => $data, 'form' => $form] = fccExport($plugin);
    $statamicForm = FormFacade::make('contact_us');

    // The whole export is passed as the last argument, so a nested form can reach its siblings.
    $creator->expects($this->once())->method('createForm')->with($form, 'Main Site', 'default', fccNames($plugin)['name'], null, $data)->willReturn($statamicForm);
    $configuration->expects($this->once())->method('configureFormEmailSettings')->with($statamicForm, $form, 'default', $this->assetImporter);

    expect(fccCreate($coordinator, 7, $data, $this->assetImporter))->toBe($statamicForm)
        ->and($coordinator->getCreatedFormsCount())->toBe(1)
        ->and($coordinator->getCreatedFormHandle(7))->toBe('contact_us')
        ->and($coordinator->getCreatedFormHandle(3))->toBeNull()
        // A second request for the same form is answered from the cache without creating it again.
        ->and(fccCreate($coordinator, 7, $data, $this->assetImporter))->toBe($statamicForm);

    // The cache key carries the plugin prefix.
    $cached = (new ReflectionProperty($coordinator, 'createdForms'))->getValue($coordinator);

    expect(\is_array($cached) ? \array_keys($cached) : null)->toBe([fccNames($plugin)['prefix'].'_7']);

    $coordinator->clearCreatedFormsCache();

    expect($coordinator->getCreatedFormsCount())->toBe(0)
        ->and($coordinator->getCreatedFormHandle(7))->toBeNull();
})->with('form plugins');

it('logs a warning and creates nothing when the form is not in the export', function (string $plugin, int $formId): void {
    $creator = $this->createMock(FormCreator::class);

    $coordinator = fccCoordinator($plugin, $this->dataManager, $this::createStub(fccConfigurationClass($plugin)), $creator);
    ['data' => $data] = fccExport($plugin);
    $names = fccNames($plugin);
    $log = Log::spy();
    ProcessingContext::setWordPressPageLink('https://www.uams.edu/contact/');

    $creator->expects($this->never())->method('createForm');

    expect(fccCreate($coordinator, $formId, $data, $this->assetImporter))->toBeNull()
        ->and($coordinator->getCreatedFormsCount())->toBe(0);

    $log->shouldHaveReceived('warning', [
        "FormCreationCoordinator::createStatamicFormFromWordPressForm: {$names['label']} form ID {$formId} not found in provided data (from entry: https://www.uams.edu/contact/)",
        Mockery::on(static fn (mixed $context): bool => \is_array($context)
            && ($context['form_id'] ?? null) === $formId
            && ($context['plugin_prefix'] ?? null) === $names['prefix']
            && ($context['entry_url'] ?? null) === 'https://www.uams.edu/contact/'),
    ]);
})->with('form plugins')->with([
    'an ID no form carries' => [99],
    // Form ID 0 is what a non-numeric `id` would cast to, so this guards the numeric check.
    'form ID 0' => [0],
]);

it('treats an empty Ninja Forms export as not found', function (): void {
    $creator = $this->createMock(FormCreator::class);

    $coordinator = fccCoordinator('ninja', $this->dataManager, $this::createStub(NinjaFormConfigurationManager::class), $creator);
    Log::spy();

    $creator->expects($this->never())->method('createForm');

    expect(fccCreate($coordinator, 7, [7 => []], $this->assetImporter))->toBeNull()
        ->and(fccCreate($coordinator, 8, [8 => 'not an export'], $this->assetImporter))->toBeNull();
});

it('does not match a Gravity Forms or Formidable Forms form whose id is not numeric', function (string $plugin): void {
    $creator = $this->createMock(FormCreator::class);

    $coordinator = fccCoordinator($plugin, $this->dataManager, $this::createStub(fccConfigurationClass($plugin)), $creator);
    Log::spy();

    // (int) 'abc' is 0, so without the numeric check this form would answer a request for 0.
    $creator->expects($this->never())->method('createForm');

    expect(fccCreate($coordinator, 0, [['id' => 'abc', 'title' => 'Not numeric']], $this->assetImporter))->toBeNull();
})->with(['formidable', 'gravity']);

it('neither configures nor caches a form FormCreator could not create', function (string $plugin): void {
    $creator = $this->createMock(FormCreator::class);

    $configuration = $this->createMock(fccConfigurationClass($plugin));
    $coordinator = fccCoordinator($plugin, $this->dataManager, $configuration, $creator);
    ['data' => $data] = fccExport($plugin);

    $creator->expects($this->exactly(2))->method('createForm')->willReturn(null);
    $configuration->expects($this->never())->method('configureFormEmailSettings');

    expect(fccCreate($coordinator, 7, $data, $this->assetImporter))->toBeNull()
        ->and($coordinator->getCreatedFormsCount())->toBe(0)
        // Nothing was cached, so a second request tries again.
        ->and(fccCreate($coordinator, 7, $data, $this->assetImporter))->toBeNull();
})->with('form plugins');

it('reports no handle for a cached form that has none', function (string $plugin): void {
    $coordinator = fccCoordinator($plugin, $this->dataManager, $this::createStub(fccConfigurationClass($plugin)), $this->creator);
    ['data' => $data] = fccExport($plugin);

    $this->creator->method('createForm')->willReturn(FormFacade::make());

    fccCreate($coordinator, 7, $data, $this->assetImporter);

    expect($coordinator->getCreatedFormsCount())->toBe(1)
        ->and($coordinator->getCreatedFormHandle(7))->toBeNull();
})->with('form plugins');

it('passes the page data mapping on to the configuration manager', function (string $plugin): void {
    $configuration = $this->createMock(fccConfigurationClass($plugin));
    $coordinator = fccCoordinator($plugin, $this->dataManager, $configuration);
    $mapping = [12 => ['id' => 'entry-12']];

    $configuration->expects($this->once())->method('setPageDataMapping')->with($mapping);

    $coordinator->setPageDataMapping($mapping);

    expect((new ReflectionProperty($coordinator, 'pageDataMapping'))->getValue($coordinator))->toBe($mapping);
})->with('form plugins');

it('accepts only its own plugin coordinator, and hands it to FormCreator', function (string $plugin): void {
    // Built without a coordinator; the FormCreator its constructor made is the one read back.
    $coordinator = fccCoordinator($plugin, $this->dataManager, $this::createStub(fccConfigurationClass($plugin)));
    $creator = (new ReflectionProperty($coordinator, 'formCreator'))->getValue($coordinator);
    $own = match ($plugin) {
        'formidable' => $this::createStub(FormidableFormsCoordinator::class),
        'gravity' => $this::createStub(GravityFormsCoordinator::class),
        default => $this::createStub(NinjaFormsCoordinator::class),
    };
    $other = $plugin === 'gravity' ? $this::createStub(FormidableFormsCoordinator::class) : $this::createStub(GravityFormsCoordinator::class);

    if (! $creator instanceof FormCreator) {
        throw new LogicException('The constructor built no FormCreator.');
    }

    $coordinator->setCoordinator($other);

    expect($coordinator->getCoordinator())->toBeNull()
        ->and((new ReflectionProperty(FormCreator::class, 'coordinator'))->getValue($creator))->toBeNull();

    $coordinator->setCoordinator($own);

    expect($coordinator->getCoordinator())->toBe($own)
        ->and((new ReflectionProperty(FormCreator::class, 'coordinator'))->getValue($creator))->toBe($own);
})->with('form plugins');

it('starts with the coordinator it was constructed with', function (string $plugin): void {
    $coordinator = match ($plugin) {
        'formidable' => new FormidableFormCreationCoordinator($this->dataManager, $this::createStub(FormidableFormConfigurationManager::class), $own = $this::createStub(FormidableFormsCoordinator::class)),
        'gravity' => new GravityFormCreationCoordinator($this->dataManager, $this::createStub(GravityFormConfigurationManager::class), $own = $this::createStub(GravityFormsCoordinator::class)),
        default => new NinjaFormCreationCoordinator($this->dataManager, $this::createStub(NinjaFormConfigurationManager::class), $own = $this::createStub(NinjaFormsCoordinator::class)),
    };

    // The constructor gives the same coordinator to the FormCreator it builds.
    $creator = (new ReflectionProperty($coordinator, 'formCreator'))->getValue($coordinator);

    expect($coordinator->getCoordinator())->toBe($own)
        ->and($creator)->toBeInstanceOf(FormCreator::class)
        ->and(\is_object($creator) ? (new ReflectionProperty(FormCreator::class, 'coordinator'))->getValue($creator) : null)->toBe($own);
})->with('form plugins');
