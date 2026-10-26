<?php

declare(strict_types=1);

/**
 * The Ninja Forms prompt handler's per-form loop and its migration API branch (#98).
 *
 * `NinjaFormsSourcingTest` drives the handler through real selectors and loaders. This file
 * replaces the detector, the ID extractor and the selector with mocks, so each decision can
 * be pinned on its own: what is written to the console, which form IDs are prompted for, and
 * which exports are bound. The data manager and validator are the real classes.
 *
 * @command  php vendor/bin/pest tests/Unit/NinjaFormsUserInteractionHandlerTest.php
 */

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms\ContentDetector;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms\DataManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms\FormIdExtractor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms\UserInteractionHandler;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\NinjaForms\Selector;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\NinjaForms\Validator;

beforeEach(function (): void {
    $this->detector = $this->createMock(ContentDetector::class);
    $this->extractor = $this->createMock(FormIdExtractor::class);
    $this->selector = $this->createMock(Selector::class);
    $this->store = new DataManager;
    $this->parser = $this->createMock(XmlParser::class);
    $this->input = new ArrayInput([]);
    $this->output = new BufferedOutput;
    $this->command = $this->createMock(Command::class);

    $this->handler = new UserInteractionHandler($this->detector, $this->store, $this->selector, $this->extractor, new Validator);
});

/**
 * A `.nff`-shaped export, optionally advertising a form ID through `settings.embed_form`.
 *
 * @param  string  $title  The form title
 * @param  ?int  $advertises  The form ID the export's embed shortcode names, if any
 * @return array<string, mixed> The export
 */
function ninjaHandlerExport(string $title, ?int $advertises = null): array
{
    return ['settings' => ['title' => $title, 'embed_form' => $advertises === null ? '' : "[ninja_form id='{$advertises}']"], 'fields' => []];
}

it('does nothing when the content holds no Ninja Forms reference', function (): void {
    $this->detector->method('hasFormPluginContent')->willReturn(false);
    $this->extractor->expects($this->never())->method('getFormIdsUsedWithPageSlugs');

    expect($this->handler->handleFormPluginDetection($this->parser, $this->input, $this->output, $this->command))->toBeFalse()
        ->and($this->output->fetch())->toBe('');
});

it('skips with a note when no form ID can be read from the references', function (): void {
    $this->detector->method('hasFormPluginContent')->willReturn(true);
    // A non-numeric key is not a form ID, so nothing remains to prompt for.
    $this->extractor->method('getFormIdsUsedWithPageSlugs')->willReturn(['abc' => ['contact']]);
    // With an API source configured, carrying on past the skip would read the source.
    $this->selector->method('hasMigrationApiSource')->willReturn(true);
    $this->selector->expects($this->never())->method('select');

    expect($this->handler->handleFormPluginDetection($this->parser, $this->input, $this->output, $this->command))->toBeFalse()
        ->and($this->output->fetch())->toBe(
            "Ninja Forms blocks or shortcodes detected in the XML.\n"
            ."No Ninja Forms form ID could be read from the detected references; skipping.\n"
        );
});

it('prompts once per distinct form ID and binds each supplied export', function (): void {
    $this->detector->method('hasFormPluginContent')->willReturn(true);
    // '07' is a distinct array key from 7 but names the same form, so it is prompted for once.
    $this->extractor->method('getFormIdsUsedWithPageSlugs')->willReturn([7 => ['a'], '07' => ['b'], '9' => ['c']]);
    $this->selector->method('hasMigrationApiSource')->willReturn(false);
    $this->selector->expects($this->exactly(2))->method('select')->willReturnOnConsecutiveCalls(ninjaHandlerExport('Contact'), ninjaHandlerExport('Apply'));

    expect($this->handler->handleFormPluginDetection($this->parser, $this->input, $this->output, $this->command))->toBeTrue()
        ->and(\array_keys($this->store->getNinjaFormsData() ?? []))->toBe([7, 9])
        ->and($this->store->getNinjaForm(9)['title'] ?? null)->toBe('Apply')
        ->and($this->output->fetch())->toBe(
            "Ninja Forms blocks or shortcodes detected in the XML.\n"
            ."Supply the Ninja Forms .nff export for form ID 7.\n"
            ."Supply the Ninja Forms .nff export for form ID 9.\n"
        );
});

it('reports a declined export and carries on to the next form ID', function (): void {
    $this->detector->method('hasFormPluginContent')->willReturn(true);
    $this->extractor->method('getFormIdsUsedWithPageSlugs')->willReturn([7 => ['a'], 9 => ['b']]);
    $this->selector->method('hasMigrationApiSource')->willReturn(false);
    $this->selector->expects($this->exactly(2))->method('select')->willReturnOnConsecutiveCalls(null, ninjaHandlerExport('Apply'));

    expect($this->handler->handleFormPluginDetection($this->parser, $this->input, $this->output, $this->command))->toBeTrue()
        ->and(\array_keys($this->store->getNinjaFormsData() ?? []))->toBe([9])
        ->and($this->output->fetch())->toContain("No export supplied for Ninja Forms form ID 7; it will not be imported.\nSupply the Ninja Forms .nff export for form ID 9.\n");
});

it('reports nothing loaded when every export is declined', function (): void {
    $this->detector->method('hasFormPluginContent')->willReturn(true);
    $this->extractor->method('getFormIdsUsedWithPageSlugs')->willReturn([7 => ['a']]);
    $this->selector->method('hasMigrationApiSource')->willReturn(false);
    $this->selector->method('select')->willReturn(null);

    expect($this->handler->handleFormPluginDetection($this->parser, $this->input, $this->output, $this->command))->toBeFalse()
        ->and($this->store->getNinjaFormsData())->toBeNull();
});

it('warns only when an export advertises a different form ID', function (?int $advertises, bool $warns): void {
    $this->detector->method('hasFormPluginContent')->willReturn(true);
    $this->extractor->method('getFormIdsUsedWithPageSlugs')->willReturn([7 => ['a']]);
    $this->selector->method('hasMigrationApiSource')->willReturn(false);
    $this->selector->method('select')->willReturn(ninjaHandlerExport('Contact', $advertises));

    $this->handler->handleFormPluginDetection($this->parser, $this->input, $this->output, $this->command);

    $warning = "Warning: the export supplied for Ninja Forms form ID 7 advertises form ID 3. Importing it as form ID 7 as supplied.\n";
    $printed = $this->output->fetch();

    expect($warns ? \str_contains($printed, $warning) : ! \str_contains($printed, 'Warning:'))->toBeTrue()
        // The export is bound to the ID it was supplied for either way.
        ->and($this->store->getNinjaForm(7)['id'] ?? null)->toBe(7);
})->with([
    'a different ID' => [3, true],
    'the same ID' => [7, false],
    'no ID' => [null, false],
]);

it('binds served forms from the migration API without a per-form prompt', function (): void {
    $this->detector->method('hasFormPluginContent')->willReturn(true);
    $this->extractor->method('getFormIdsUsedWithPageSlugs')->willReturn([7 => ['a'], 12 => ['b'], 30 => ['c'], 40 => ['d']]);
    $this->selector->method('hasMigrationApiSource')->willReturn(true);
    // One read for the whole site. A digit-string id is accepted, including one with a leading
    // zero, which is a string key unless cast; a non-numeric one is filed nowhere.
    $this->selector->expects($this->once())->method('select')->willReturn([
        // The real loader drops entries that are not forms; the contract still allows one,
        // and it is first so the forms after it are still read.
        'not a form',
        ['id' => 7, 'title' => 'Contact'],
        ['id' => '12', 'title' => 'Apply'],
        ['id' => '040', 'title' => 'Register'],
        ['id' => 'x', 'title' => 'Unattributable'],
    ]);

    expect($this->handler->handleFormPluginDetection($this->parser, $this->input, $this->output, $this->command))->toBeTrue()
        ->and(\array_keys($this->store->getNinjaFormsData() ?? []))->toBe([7, 12, 40])
        ->and($this->store->getNinjaForm(12)['title'] ?? null)->toBe('Apply')
        ->and($this->output->fetch())->toBe(
            "Ninja Forms blocks or shortcodes detected in the XML.\n"
            ."Ninja Forms form ID 30 is referenced by the content but the migration API does not serve it; it will not be imported.\n"
        );
});

it('reports nothing loaded when the migration API serves none of the referenced forms', function (?array $served): void {
    $this->detector->method('hasFormPluginContent')->willReturn(true);
    $this->extractor->method('getFormIdsUsedWithPageSlugs')->willReturn([7 => ['a']]);
    $this->selector->method('hasMigrationApiSource')->willReturn(true);
    $this->selector->method('select')->willReturn($served);

    expect($this->handler->handleFormPluginDetection($this->parser, $this->input, $this->output, $this->command))->toBeFalse()
        ->and($this->store->getNinjaFormsData())->toBeNull()
        // A failed read reports nothing per form; only an unserved form is named.
        ->and(\str_contains($this->output->fetch(), 'does not serve it'))->toBe($served !== null);
})->with([
    'nothing read' => [null],
    'other forms only' => [[['id' => 3, 'title' => 'Other']]],
]);
