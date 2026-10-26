<?php

declare(strict_types=1);

/**
 * The Formidable Forms, Gravity Forms and Ninja Forms coordinators and the FormPluginCoordinatorTrait they share (#98).
 *
 * Each coordinator is a facade over its plugin's detector, ID extractor, data store, prompt
 * handler and form creation coordinator. These tests replace every collaborator with a mock
 * and check what each public method hands to which one, and what comes back. Statamic is
 * booted only for the `Form` objects and the `Log` facade the missing-data path writes to.
 *
 * The three coordinators name their methods after the plugin, so each test reaches them
 * through the per-plugin closures in `fpcOps()` rather than by calling them directly.
 *
 * @command  php vendor/bin/pest tests/Integration/FormPluginCoordinatorsTest.php
 */

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\MockObject\MockObject;
use Statamic\Facades\Form as FormFacade;
use Statamic\Forms\Form;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Creators\FormCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\BaseContentDetector;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\BaseDataManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\BaseFormIdExtractor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormPluginFormCreationCoordinatorContract;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormPluginUserInteractionHandlerContract;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * Narrow a mock built by a test to the class it was built from.
 *
 * @template T of object
 *
 * @param  mixed  $mock  The mock
 * @param  class-string<T>  $class  The class it mocks
 * @return T&MockObject The mock, typed
 */
function fpcTyped(mixed $mock, string $class): object
{
    if (! $mock instanceof MockObject || ! $mock instanceof $class) {
        throw new LogicException("Expected a mock of {$class}.");
    }

    return $mock;
}

/**
 * One plugin's coordinator, built over mocked collaborators.
 *
 * @param  string  $plugin  formidable, gravity or ninja
 * @param  Closure(class-string): object  $mock  Builds a mock of a class; made in the test, where createMock() is callable
 * @param  bool  $injectCreation  Pass a mocked creation coordinator rather than let the coordinator build its own
 * @return array{coordinator: FormidableForms\FormidableFormsCoordinator|GravityForms\GravityFormsCoordinator|NinjaForms\NinjaFormsCoordinator, detector: BaseContentDetector&MockObject, extractor: BaseFormIdExtractor&MockObject, data: BaseDataManager&MockObject, prompts: FormPluginUserInteractionHandlerContract&MockObject, creation: (FormidableForms\FormCreationCoordinator&MockObject)|(GravityForms\FormCreationCoordinator&MockObject)|(NinjaForms\FormCreationCoordinator&MockObject)|null}
 */
function fpcBuild(string $plugin, Closure $mock, bool $injectCreation = true): array
{
    if ($plugin === 'formidable') {
        $detector = fpcTyped($mock(FormidableForms\ContentDetector::class), FormidableForms\ContentDetector::class);
        $extractor = fpcTyped($mock(FormidableForms\FormIdExtractor::class), FormidableForms\FormIdExtractor::class);
        $data = fpcTyped($mock(FormidableForms\DataManager::class), FormidableForms\DataManager::class);
        $prompts = fpcTyped($mock(FormidableForms\UserInteractionHandler::class), FormidableForms\UserInteractionHandler::class);
        $creation = $injectCreation ? fpcTyped($mock(FormidableForms\FormCreationCoordinator::class), FormidableForms\FormCreationCoordinator::class) : null;
        $coordinator = new FormidableForms\FormidableFormsCoordinator($detector, $extractor, $data, $prompts, $creation);
    } elseif ($plugin === 'gravity') {
        $detector = fpcTyped($mock(GravityForms\ContentDetector::class), GravityForms\ContentDetector::class);
        $extractor = fpcTyped($mock(GravityForms\FormIdExtractor::class), GravityForms\FormIdExtractor::class);
        $data = fpcTyped($mock(GravityForms\DataManager::class), GravityForms\DataManager::class);
        $prompts = fpcTyped($mock(GravityForms\UserInteractionHandler::class), GravityForms\UserInteractionHandler::class);
        $creation = $injectCreation ? fpcTyped($mock(GravityForms\FormCreationCoordinator::class), GravityForms\FormCreationCoordinator::class) : null;
        $coordinator = new GravityForms\GravityFormsCoordinator($detector, $extractor, $data, $prompts, $creation);
    } else {
        $detector = fpcTyped($mock(NinjaForms\ContentDetector::class), NinjaForms\ContentDetector::class);
        $extractor = fpcTyped($mock(NinjaForms\FormIdExtractor::class), NinjaForms\FormIdExtractor::class);
        $data = fpcTyped($mock(NinjaForms\DataManager::class), NinjaForms\DataManager::class);
        $prompts = fpcTyped($mock(NinjaForms\UserInteractionHandler::class), NinjaForms\UserInteractionHandler::class);
        $creation = $injectCreation ? fpcTyped($mock(NinjaForms\FormCreationCoordinator::class), NinjaForms\FormCreationCoordinator::class) : null;
        $coordinator = new NinjaForms\NinjaFormsCoordinator($detector, $extractor, $data, $prompts, $creation);
    }

    return ['coordinator' => $coordinator, 'detector' => $detector, 'extractor' => $extractor, 'data' => $data, 'prompts' => $prompts, 'creation' => $creation];
}

/**
 * The plugin-named public methods of one coordinator, as closures over the shared signature.
 *
 * @param  string  $plugin  formidable, gravity or ninja
 * @return array{label: string, title: string, creation: class-string, createFrom: non-empty-string, setData: Closure, getData: Closure, clearData: Closure, hasContent: Closure, idsWithPages: Closure, detect: Closure, createIfNeeded: Closure}
 */
function fpcOps(string $plugin): array
{
    return match ($plugin) {
        'formidable' => [
            'label' => 'Formidable Forms',
            'title' => 'name',
            'creation' => FormidableForms\FormCreationCoordinator::class,
            'createFrom' => 'createStatamicFormFromFormidableForm',
            'setData' => static fn (FormidableForms\FormidableFormsCoordinator $c, ?array $forms) => $c->setFormidableFormsData($forms),
            'getData' => static fn (FormidableForms\FormidableFormsCoordinator $c): ?array => $c->getFormidableFormsData(),
            'clearData' => static fn (FormidableForms\FormidableFormsCoordinator $c) => $c->clearFormidableFormsData(),
            'hasContent' => static fn (FormidableForms\FormidableFormsCoordinator $c, XmlParser $p): bool => $c->hasFormidableFormsContent($p),
            'idsWithPages' => static fn (FormidableForms\FormidableFormsCoordinator $c, XmlParser $p): array => $c->getFormidableFormsFormIdsWithPages($p),
            'detect' => static fn (FormidableForms\FormidableFormsCoordinator $c, XmlParser $p, ArrayInput $i, BufferedOutput $o, Command $cmd): bool => $c->handleFormidableFormsDetection($p, $i, $o, $cmd),
            'createIfNeeded' => static fn (FormidableForms\FormidableFormsCoordinator $c, int $id, AssetImporter $a): ?Form => $c->createFormidableFormsFormIfNeeded($id, 'Main Site', 'default', $a),
        ],
        'gravity' => [
            'label' => 'Gravity Forms',
            'title' => 'title',
            'creation' => GravityForms\FormCreationCoordinator::class,
            'createFrom' => 'createStatamicFormFromGravityForm',
            'setData' => static fn (GravityForms\GravityFormsCoordinator $c, ?array $forms) => $c->setGravityFormsData($forms),
            'getData' => static fn (GravityForms\GravityFormsCoordinator $c): ?array => $c->getGravityFormsData(),
            'clearData' => static fn (GravityForms\GravityFormsCoordinator $c) => $c->clearGravityFormsData(),
            'hasContent' => static fn (GravityForms\GravityFormsCoordinator $c, XmlParser $p): bool => $c->hasGravityFormsContent($p),
            'idsWithPages' => static fn (GravityForms\GravityFormsCoordinator $c, XmlParser $p): array => $c->getGravityFormsFormIdsWithPages($p),
            'detect' => static fn (GravityForms\GravityFormsCoordinator $c, XmlParser $p, ArrayInput $i, BufferedOutput $o, Command $cmd): bool => $c->handleGravityFormsDetection($p, $i, $o, $cmd),
            'createIfNeeded' => static fn (GravityForms\GravityFormsCoordinator $c, int $id, AssetImporter $a): ?Form => $c->createGravityFormsFormIfNeeded($id, 'Main Site', 'default', $a),
        ],
        default => [
            'label' => 'Ninja Forms',
            'title' => 'title',
            'creation' => NinjaForms\FormCreationCoordinator::class,
            'createFrom' => 'createStatamicFormFromNinjaForm',
            'setData' => static fn (NinjaForms\NinjaFormsCoordinator $c, ?array $forms) => $c->setNinjaFormsData($forms),
            'getData' => static fn (NinjaForms\NinjaFormsCoordinator $c): ?array => $c->getNinjaFormsData(),
            'clearData' => static fn (NinjaForms\NinjaFormsCoordinator $c) => $c->clearNinjaFormsData(),
            'hasContent' => static fn (NinjaForms\NinjaFormsCoordinator $c, XmlParser $p): bool => $c->hasNinjaFormsContent($p),
            'idsWithPages' => static fn (NinjaForms\NinjaFormsCoordinator $c, XmlParser $p): array => $c->getNinjaFormsFormIdsWithPages($p),
            'detect' => static fn (NinjaForms\NinjaFormsCoordinator $c, XmlParser $p, ArrayInput $i, BufferedOutput $o, Command $cmd): bool => $c->handleNinjaFormsDetection($p, $i, $o, $cmd),
            'createIfNeeded' => static fn (NinjaForms\NinjaFormsCoordinator $c, int $id, AssetImporter $a): ?Form => $c->createNinjaFormsFormIfNeeded($id, 'Main Site', 'default', $a),
        ],
    };
}

beforeEach(function (): void {
    $this->mock = function (string $class): object {
        if (! \class_exists($class)) {
            throw new LogicException("No class {$class} to mock.");
        }

        return $this->createMock($class);
    };
    $this->parser = $this->createMock(XmlParser::class);
    $this->assetImporter = $this->createMock(AssetImporter::class);
});

dataset('form plugins', ['formidable', 'gravity', 'ninja']);

it('passes the export data to and from its data manager', function (string $plugin): void {
    ['coordinator' => $coordinator, 'data' => $data] = fpcBuild($plugin, $this->mock);
    $ops = fpcOps($plugin);
    $forms = [['id' => 1]];

    $data->expects($this->once())->method('setFormsData')->with($forms);
    $data->expects($this->once())->method('getFormsData')->willReturn($forms);
    $data->expects($this->once())->method('clearFormsData');

    $ops['setData']($coordinator, $forms);

    expect($ops['getData']($coordinator))->toBe($forms);

    $ops['clearData']($coordinator);
})->with('form plugins');

it('asks its content detector whether the export uses the plugin', function (string $plugin, bool $found): void {
    ['coordinator' => $coordinator, 'detector' => $detector] = fpcBuild($plugin, $this->mock);

    $detector->expects($this->once())->method('hasFormPluginContent')->with($this->parser)->willReturn($found);

    expect(fpcOps($plugin)['hasContent']($coordinator, $this->parser))->toBe($found);
})->with('form plugins')->with([true, false]);

it('re-keys the referenced form IDs and drops an empty one', function (string $plugin): void {
    ['coordinator' => $coordinator, 'extractor' => $extractor] = fpcBuild($plugin, $this->mock);

    // The empty ID sits between two real ones, so the scan has to continue past it.
    $extractor->expects($this->once())->method('getFormIdsUsedWithPageSlugs')->with($this->parser)->willReturn([
        7 => ['contact'],
        '' => ['orphan'],
        '12' => ['apply', 'apply-again'],
    ]);

    expect(fpcOps($plugin)['idsWithPages']($coordinator, $this->parser))->toBe([
        7 => ['contact'],
        12 => ['apply', 'apply-again'],
    ]);
})->with('form plugins');

it('hands detection to its prompt handler and returns what it decided', function (string $plugin, bool $loaded): void {
    ['coordinator' => $coordinator, 'prompts' => $prompts] = fpcBuild($plugin, $this->mock);
    $input = new ArrayInput([]);
    $output = new BufferedOutput;
    $command = $this->createMock(Command::class);

    $prompts->expects($this->once())->method('handleFormPluginDetection')->with($this->parser, $input, $output, $command)->willReturn($loaded);

    expect(fpcOps($plugin)['detect']($coordinator, $this->parser, $input, $output, $command))->toBe($loaded);
})->with('form plugins')->with([true, false]);

it('names the export key that holds a form title', function (string $plugin): void {
    ['coordinator' => $coordinator] = fpcBuild($plugin, $this->mock);

    expect($coordinator->getFormTitleAttributeName())->toBe(fpcOps($plugin)['title']);
})->with('form plugins');

it('creates the form through its creation coordinator once the export is loaded', function (string $plugin): void {
    ['coordinator' => $coordinator, 'data' => $data, 'creation' => $creation] = fpcBuild($plugin, $this->mock);
    $ops = fpcOps($plugin);
    $forms = [7 => ['id' => 7, 'title' => 'Contact']];
    $form = FormFacade::make('contact');

    $data->expects($this->once())->method('getFormsData')->willReturn($forms);
    $creation?->expects($this->once())->method($ops['createFrom'])->with(7, $forms, 'Main Site', 'default', $this->assetImporter)->willReturn($form);

    expect($ops['createIfNeeded']($coordinator, 7, $this->assetImporter))->toBe($form);
})->with('form plugins');

it('logs an error and creates nothing when the export is not loaded', function (string $plugin): void {
    ['coordinator' => $coordinator, 'data' => $data, 'creation' => $creation] = fpcBuild($plugin, $this->mock);
    $ops = fpcOps($plugin);
    $log = Log::spy();

    $data->expects($this->once())->method('getFormsData')->willReturn(null);
    $creation?->expects($this->never())->method($ops['createFrom']);

    expect($ops['createIfNeeded']($coordinator, 7, $this->assetImporter))->toBeNull();

    $log->shouldHaveReceived('error', ["{$ops['label']}Coordinator: {$ops['label']} data is not loaded. Cannot create form for ID 7."]);
})->with('form plugins');

it('creates nothing without a creation coordinator of its own plugin', function (string $plugin, string $state): void {
    ['coordinator' => $coordinator, 'data' => $data] = fpcBuild($plugin, $this->mock);

    // The property is nullable and typed to the shared contract, so these guards cover
    // states the constructors never produce; reflection is the only way to reach them.
    $replacement = $state === 'none' ? null : new class implements FormPluginFormCreationCoordinatorContract
    {
        /**
         * Report no created form.
         *
         * @param  int  $formId  The WordPress form ID
         * @return ?string Always null
         */
        public function getCreatedFormHandle(int $formId): ?string
        {
            return null;
        }

        /**
         * Accept and ignore the coordinator.
         *
         * @param  object  $coordinator  The plugin coordinator
         */
        public function setCoordinator(object $coordinator): void {}
    };
    (new ReflectionProperty($coordinator, 'formCreationCoordinator'))->setValue($coordinator, $replacement);

    $data->expects($this->never())->method('getFormsData');

    expect(fpcOps($plugin)['createIfNeeded']($coordinator, 7, $this->assetImporter))->toBeNull();
})->with('form plugins')->with(['none', 'another implementation']);

it('reports the created form handle its creation coordinator holds', function (string $plugin): void {
    ['coordinator' => $coordinator, 'creation' => $creation] = fpcBuild($plugin, $this->mock);

    if (! $coordinator instanceof FormidableForms\FormidableFormsCoordinator && ! $coordinator instanceof GravityForms\GravityFormsCoordinator) {
        throw new LogicException('Only the Formidable Forms and Gravity Forms coordinators report handles.');
    }

    $creation?->expects($this->once())->method('getCreatedFormHandle')->with(7)->willReturn('default_contact');

    expect($coordinator->getCreatedFormHandle(7))->toBe('default_contact');

    (new ReflectionProperty($coordinator, 'formCreationCoordinator'))->setValue($coordinator, null);

    expect($coordinator->getCreatedFormHandle(7))->toBeNull();
})->with(['formidable', 'gravity']);

it('builds its own creation coordinator when none is given, and links it back', function (string $plugin): void {
    ['coordinator' => $coordinator] = fpcBuild($plugin, $this->mock, injectCreation: false);
    $creation = $coordinator->getFormCreationCoordinator();

    expect($creation)->toBeInstanceOf(fpcOps($plugin)['creation']);

    if (! $creation instanceof FormidableForms\FormCreationCoordinator && ! $creation instanceof GravityForms\FormCreationCoordinator && ! $creation instanceof NinjaForms\FormCreationCoordinator) {
        throw new LogicException('Expected a plugin form creation coordinator.');
    }

    // The FormCreator inside reads form titles through the coordinator, so it must hold it too.
    $creator = (new ReflectionProperty($creation, 'formCreator'))->getValue($creation);

    expect($creation->getCoordinator())->toBe($coordinator)
        ->and($creator)->toBeInstanceOf(FormCreator::class)
        ->and(\is_object($creator) ? (new ReflectionProperty(FormCreator::class, 'coordinator'))->getValue($creator) : null)->toBe($coordinator);
})->with('form plugins');

it('keeps the creation coordinator it was given, and links it back', function (string $plugin): void {
    // Built here rather than through fpcBuild(), so the constructor's call can be expected.
    if ($plugin === 'formidable') {
        $creation = $this->createMock(FormidableForms\FormCreationCoordinator::class);
        $creation->expects($this->once())->method('setCoordinator')->with(PHPUnit\Framework\Assert::isInstanceOf(FormidableForms\FormidableFormsCoordinator::class));
        $coordinator = new FormidableForms\FormidableFormsCoordinator($this->createMock(FormidableForms\ContentDetector::class), $this->createMock(FormidableForms\FormIdExtractor::class), $this->createMock(FormidableForms\DataManager::class), $this->createMock(FormidableForms\UserInteractionHandler::class), $creation);
    } elseif ($plugin === 'gravity') {
        $creation = $this->createMock(GravityForms\FormCreationCoordinator::class);
        $creation->expects($this->once())->method('setCoordinator')->with(PHPUnit\Framework\Assert::isInstanceOf(GravityForms\GravityFormsCoordinator::class));
        $coordinator = new GravityForms\GravityFormsCoordinator($this->createMock(GravityForms\ContentDetector::class), $this->createMock(GravityForms\FormIdExtractor::class), $this->createMock(GravityForms\DataManager::class), $this->createMock(GravityForms\UserInteractionHandler::class), $creation);
    } else {
        $creation = $this->createMock(NinjaForms\FormCreationCoordinator::class);
        $creation->expects($this->once())->method('setCoordinator')->with(PHPUnit\Framework\Assert::isInstanceOf(NinjaForms\NinjaFormsCoordinator::class));
        $coordinator = new NinjaForms\NinjaFormsCoordinator($this->createMock(NinjaForms\ContentDetector::class), $this->createMock(NinjaForms\FormIdExtractor::class), $this->createMock(NinjaForms\DataManager::class), $this->createMock(NinjaForms\UserInteractionHandler::class), $creation);
    }

    expect($coordinator->getFormCreationCoordinator())->toBe($creation);
})->with('form plugins');

it('replaces its creation coordinator and links the new one back', function (string $plugin): void {
    ['coordinator' => $coordinator] = fpcBuild($plugin, $this->mock);

    if ($coordinator instanceof FormidableForms\FormidableFormsCoordinator) {
        $replacement = $this->createMock(FormidableForms\FormCreationCoordinator::class);
        $replacement->expects($this->once())->method('setCoordinator')->with($coordinator);
        $coordinator->setFormCreationCoordinator($replacement);
    } elseif ($coordinator instanceof GravityForms\GravityFormsCoordinator) {
        $replacement = $this->createMock(GravityForms\FormCreationCoordinator::class);
        $replacement->expects($this->once())->method('setCoordinator')->with($coordinator);
        $coordinator->setFormCreationCoordinator($replacement);
    } else {
        $replacement = $this->createMock(NinjaForms\FormCreationCoordinator::class);
        $replacement->expects($this->once())->method('setCoordinator')->with($coordinator);
        $coordinator->setFormCreationCoordinator($replacement);
    }

    expect($coordinator->getFormCreationCoordinator())->toBe($replacement);
})->with('form plugins');
