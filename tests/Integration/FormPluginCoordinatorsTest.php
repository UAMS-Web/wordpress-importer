<?php

declare(strict_types=1);

/**
 * The Formidable Forms, Gravity Forms and Ninja Forms coordinators and the FormPluginCoordinatorTrait they share (#98).
 *
 * Each coordinator is a facade over its plugin's detector, ID extractor, data store, prompt
 * handler and form creation coordinator. These tests replace every collaborator with a test double
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
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use Statamic\Facades\Form as FormFacade;
use Statamic\Forms\Form;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Creators\FormCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\BaseContentDetector;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\BaseDataManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\BaseFormIdExtractor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormPluginFormCreationCoordinatorContract;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormPluginUserInteractionHandlerContract;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * Narrow a test double built by a test to the class it was built from.
 *
 * @template T of object
 *
 * @param  mixed  $double  The double
 * @param  class-string<T>  $class  The class it stands in for
 * @return T&Stub The double, typed
 */
function fpcTyped(mixed $double, string $class): object
{
    if (! $double instanceof Stub || ! $double instanceof $class) {
        throw new LogicException("Expected a test double of {$class}.");
    }

    return $double;
}

/**
 * Narrow a role a test named to fpcBuild() to the mock it was built as.
 *
 * @template T of object
 *
 * @param  T  $double  The double
 * @return T&MockObject The mock
 */
function fpcMock(object $double): object
{
    if (! $double instanceof MockObject) {
        throw new LogicException('Name the role in fpcBuild() to assert calls on it.');
    }

    return $double;
}

/**
 * One plugin's coordinator, built over test doubles.
 *
 * @param  string  $plugin  formidable, gravity or ninja
 * @param  Closure(class-string, bool): object  $double  Builds a stub of a class, or a mock when the second argument is true; made in the test, where createMock() is callable
 * @param  list<string>  $mocked  The roles the test asserts calls on: detector, extractor, data, prompts or creation
 * @param  bool  $injectCreation  Pass a creation coordinator double rather than let the coordinator build its own
 * @return array{coordinator: FormidableForms\FormidableFormsCoordinator|GravityForms\GravityFormsCoordinator|NinjaForms\NinjaFormsCoordinator, detector: BaseContentDetector&Stub, extractor: BaseFormIdExtractor&Stub, data: BaseDataManager&Stub, prompts: FormPluginUserInteractionHandlerContract&Stub, creation: (FormidableForms\FormCreationCoordinator&Stub)|(GravityForms\FormCreationCoordinator&Stub)|(NinjaForms\FormCreationCoordinator&Stub)|null}
 */
function fpcBuild(string $plugin, Closure $double, array $mocked = [], bool $injectCreation = true): array
{
    if ($plugin === 'formidable') {
        $detector = fpcTyped($double(FormidableForms\ContentDetector::class, \in_array('detector', $mocked, true)), FormidableForms\ContentDetector::class);
        $extractor = fpcTyped($double(FormidableForms\FormIdExtractor::class, \in_array('extractor', $mocked, true)), FormidableForms\FormIdExtractor::class);
        $data = fpcTyped($double(FormidableForms\DataManager::class, \in_array('data', $mocked, true)), FormidableForms\DataManager::class);
        $prompts = fpcTyped($double(FormidableForms\UserInteractionHandler::class, \in_array('prompts', $mocked, true)), FormidableForms\UserInteractionHandler::class);
        $creation = $injectCreation ? fpcTyped($double(FormidableForms\FormCreationCoordinator::class, \in_array('creation', $mocked, true)), FormidableForms\FormCreationCoordinator::class) : null;
        $coordinator = new FormidableForms\FormidableFormsCoordinator($detector, $extractor, $data, $prompts, $creation);
    } elseif ($plugin === 'gravity') {
        $detector = fpcTyped($double(GravityForms\ContentDetector::class, \in_array('detector', $mocked, true)), GravityForms\ContentDetector::class);
        $extractor = fpcTyped($double(GravityForms\FormIdExtractor::class, \in_array('extractor', $mocked, true)), GravityForms\FormIdExtractor::class);
        $data = fpcTyped($double(GravityForms\DataManager::class, \in_array('data', $mocked, true)), GravityForms\DataManager::class);
        $prompts = fpcTyped($double(GravityForms\UserInteractionHandler::class, \in_array('prompts', $mocked, true)), GravityForms\UserInteractionHandler::class);
        $creation = $injectCreation ? fpcTyped($double(GravityForms\FormCreationCoordinator::class, \in_array('creation', $mocked, true)), GravityForms\FormCreationCoordinator::class) : null;
        $coordinator = new GravityForms\GravityFormsCoordinator($detector, $extractor, $data, $prompts, $creation);
    } else {
        $detector = fpcTyped($double(NinjaForms\ContentDetector::class, \in_array('detector', $mocked, true)), NinjaForms\ContentDetector::class);
        $extractor = fpcTyped($double(NinjaForms\FormIdExtractor::class, \in_array('extractor', $mocked, true)), NinjaForms\FormIdExtractor::class);
        $data = fpcTyped($double(NinjaForms\DataManager::class, \in_array('data', $mocked, true)), NinjaForms\DataManager::class);
        $prompts = fpcTyped($double(NinjaForms\UserInteractionHandler::class, \in_array('prompts', $mocked, true)), NinjaForms\UserInteractionHandler::class);
        $creation = $injectCreation ? fpcTyped($double(NinjaForms\FormCreationCoordinator::class, \in_array('creation', $mocked, true)), NinjaForms\FormCreationCoordinator::class) : null;
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
    $this->mock = function (string $class, bool $mocked = true): object {
        if (! \class_exists($class)) {
            throw new LogicException("No class {$class} to mock.");
        }

        return $mocked ? $this->createMock($class) : $this::createStub($class);
    };
    $this->parser = $this::createStub(XmlParser::class);
    $this->assetImporter = $this::createStub(AssetImporter::class);
});

dataset('form plugins', ['formidable', 'gravity', 'ninja']);

it('passes the export data to and from its data manager', function (string $plugin): void {
    ['coordinator' => $coordinator, 'data' => $data] = fpcBuild($plugin, $this->mock, ['data']);
    $ops = fpcOps($plugin);
    $forms = [['id' => 1]];

    fpcMock($data)->expects($this->once())->method('setFormsData')->with($forms);
    fpcMock($data)->expects($this->once())->method('getFormsData')->willReturn($forms);
    fpcMock($data)->expects($this->once())->method('clearFormsData');

    $ops['setData']($coordinator, $forms);

    expect($ops['getData']($coordinator))->toBe($forms);

    $ops['clearData']($coordinator);
})->with('form plugins');

it('asks its content detector whether the export uses the plugin', function (string $plugin, bool $found): void {
    ['coordinator' => $coordinator, 'detector' => $detector] = fpcBuild($plugin, $this->mock, ['detector']);

    fpcMock($detector)->expects($this->once())->method('hasFormPluginContent')->with($this->parser)->willReturn($found);

    expect(fpcOps($plugin)['hasContent']($coordinator, $this->parser))->toBe($found);
})->with('form plugins')->with([true, false]);

it('re-keys the referenced form IDs and drops an empty one', function (string $plugin): void {
    ['coordinator' => $coordinator, 'extractor' => $extractor] = fpcBuild($plugin, $this->mock, ['extractor']);

    // The empty ID sits between two real ones, so the scan has to continue past it.
    fpcMock($extractor)->expects($this->once())->method('getFormIdsUsedWithPageSlugs')->with($this->parser)->willReturn([
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
    ['coordinator' => $coordinator, 'prompts' => $prompts] = fpcBuild($plugin, $this->mock, ['prompts']);
    $input = new ArrayInput([]);
    $output = new BufferedOutput;
    $command = $this::createStub(Command::class);

    fpcMock($prompts)->expects($this->once())->method('handleFormPluginDetection')->with($this->parser, $input, $output, $command)->willReturn($loaded);

    expect(fpcOps($plugin)['detect']($coordinator, $this->parser, $input, $output, $command))->toBe($loaded);
})->with('form plugins')->with([true, false]);

it('names the export key that holds a form title', function (string $plugin): void {
    ['coordinator' => $coordinator] = fpcBuild($plugin, $this->mock);

    expect($coordinator->getFormTitleAttributeName())->toBe(fpcOps($plugin)['title']);
})->with('form plugins');

it('creates the form through its creation coordinator once the export is loaded', function (string $plugin): void {
    ['coordinator' => $coordinator, 'data' => $data, 'creation' => $creation] = fpcBuild($plugin, $this->mock, ['data', 'creation']);
    $ops = fpcOps($plugin);
    $forms = [7 => ['id' => 7, 'title' => 'Contact']];
    $form = FormFacade::make('contact');

    fpcMock($data)->expects($this->once())->method('getFormsData')->willReturn($forms);
    if ($creation === null) {
        throw new LogicException('fpcBuild() injects a creation coordinator by default.');
    }

    fpcMock($creation)->expects($this->once())->method($ops['createFrom'])->with(7, $forms, 'Main Site', 'default', $this->assetImporter)->willReturn($form);

    expect($ops['createIfNeeded']($coordinator, 7, $this->assetImporter))->toBe($form);
})->with('form plugins');

it('logs an error and creates nothing when the export is not loaded', function (string $plugin): void {
    ['coordinator' => $coordinator, 'data' => $data, 'creation' => $creation] = fpcBuild($plugin, $this->mock, ['data', 'creation']);
    $ops = fpcOps($plugin);
    $log = Log::spy();

    fpcMock($data)->expects($this->once())->method('getFormsData')->willReturn(null);
    if ($creation === null) {
        throw new LogicException('fpcBuild() injects a creation coordinator by default.');
    }

    fpcMock($creation)->expects($this->never())->method($ops['createFrom']);

    expect($ops['createIfNeeded']($coordinator, 7, $this->assetImporter))->toBeNull();

    $log->shouldHaveReceived('error', ["{$ops['label']}Coordinator: {$ops['label']} data is not loaded. Cannot create form for ID 7."]);
})->with('form plugins');

it('creates nothing without a creation coordinator of its own plugin', function (string $plugin, string $state): void {
    ['coordinator' => $coordinator, 'data' => $data] = fpcBuild($plugin, $this->mock, ['data']);

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

    fpcMock($data)->expects($this->never())->method('getFormsData');

    expect(fpcOps($plugin)['createIfNeeded']($coordinator, 7, $this->assetImporter))->toBeNull();
})->with('form plugins')->with(['none', 'another implementation']);

it('reports the created form handle its creation coordinator holds', function (string $plugin): void {
    ['coordinator' => $coordinator, 'creation' => $creation] = fpcBuild($plugin, $this->mock, ['creation']);

    if (! $coordinator instanceof FormidableForms\FormidableFormsCoordinator && ! $coordinator instanceof GravityForms\GravityFormsCoordinator) {
        throw new LogicException('Only the Formidable Forms and Gravity Forms coordinators report handles.');
    }

    if ($creation === null) {
        throw new LogicException('fpcBuild() injects a creation coordinator by default.');
    }

    fpcMock($creation)->expects($this->once())->method('getCreatedFormHandle')->with(7)->willReturn('default_contact');

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
        fpcMock($creation)->expects($this->once())->method('setCoordinator')->with(Assert::isInstanceOf(FormidableForms\FormidableFormsCoordinator::class));
        $coordinator = new FormidableForms\FormidableFormsCoordinator($this::createStub(FormidableForms\ContentDetector::class), $this::createStub(FormidableForms\FormIdExtractor::class), $this::createStub(FormidableForms\DataManager::class), $this::createStub(FormidableForms\UserInteractionHandler::class), $creation);
    } elseif ($plugin === 'gravity') {
        $creation = $this->createMock(GravityForms\FormCreationCoordinator::class);
        fpcMock($creation)->expects($this->once())->method('setCoordinator')->with(Assert::isInstanceOf(GravityForms\GravityFormsCoordinator::class));
        $coordinator = new GravityForms\GravityFormsCoordinator($this::createStub(GravityForms\ContentDetector::class), $this::createStub(GravityForms\FormIdExtractor::class), $this::createStub(GravityForms\DataManager::class), $this::createStub(GravityForms\UserInteractionHandler::class), $creation);
    } else {
        $creation = $this->createMock(NinjaForms\FormCreationCoordinator::class);
        fpcMock($creation)->expects($this->once())->method('setCoordinator')->with(Assert::isInstanceOf(NinjaForms\NinjaFormsCoordinator::class));
        $coordinator = new NinjaForms\NinjaFormsCoordinator($this::createStub(NinjaForms\ContentDetector::class), $this::createStub(NinjaForms\FormIdExtractor::class), $this::createStub(NinjaForms\DataManager::class), $this::createStub(NinjaForms\UserInteractionHandler::class), $creation);
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
