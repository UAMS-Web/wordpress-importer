<?php

declare(strict_types=1);

use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Facades\Form as FormFacade;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;
use Statamic\Forms\Form;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms\DataManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms\NinjaFormsCoordinator;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

require_once __DIR__.'/../Support/form-audit-test-support.php';

/**
 * Read a form's handle as a string.
 *
 * Statamic's Form::handle() is untyped, so level max sees mixed and refuses both a cast
 * and a concatenation. Narrowing once here keeps the call sites readable.
 *
 * @param  Form  $form  The Statamic form
 * @return string The form handle
 */
function ninjaFormsHandle(Form $form): string
{
    $handle = $form->handle();

    return \is_string($handle) ? $handle : '';
}

/**
 * End-to-end import of each production Ninja Forms shape (issue #10).
 *
 * The earlier files test the pieces — fieldtype resolution, attribute mapping, the wiring
 * that makes any of it run. This one imports whole forms and asserts what an editor would
 * actually receive, which is the only level at which "the Waiver form imports correctly"
 * is a meaningful claim.
 *
 * Fixtures are derived from the real exports rather than hand-written, so they carry the
 * structures that actually occur — a repeater wrapping an entire form body, a select whose
 * options each carry a calculation value, a state list opening with a prompt option, and
 * notification actions that are switched off. See formAuditNinjaFormsFixturesDir() for
 * what was trimmed and what was deliberately kept.
 *
 * Point NF_AUDIT_FIXTURES_DIR at a wordpress-importer-exports checkout to run the same
 * assertions against the full untrimmed corpus.
 *
 * @command  php vendor/bin/pest tests/Integration/NinjaFormsPerFormImportTest.php
 */
uses(StatamicTestCase::class);

/**
 * Import one fixture export and return the created Statamic form.
 *
 * Takes a list of candidate file names rather than one, so the same assertions run
 * against either corpus: the trimmed fixture committed here, or the production export it
 * was derived from when NF_AUDIT_FIXTURES_DIR points at a wordpress-importer-exports
 * checkout. Pinning a single name would make that override decorative — the sibling
 * Gravity Forms audit does pin one, and pointing it at the real corpus skips every
 * assertion with "sample not present" while still reporting a pass.
 *
 * @param  array<int, string>  $candidates  Accepted file names, most specific first
 * @param  int  $formId  The form ID the export is bound to
 * @param  AssetImporter  $assetImporter  Asset importer for any referenced media
 * @return ?Form The created form, or null when creation failed
 */
function importNinjaFormsFixture(array $candidates, int $formId, AssetImporter $assetImporter): ?Form
{
    $directory = formAuditNinjaFormsFixturesDir();
    $path = null;

    foreach ($candidates as $candidate) {
        $attempt = $directory.'/'.$candidate;

        if (\is_file($attempt)) {
            $path = $attempt;

            break;
        }
    }

    // No candidate present is a real failure, not a reason to skip: a skip here would
    // report green while proving nothing about any form importing.
    expect($path)->not->toBeNull(
        'no fixture found in '.$directory.' among: '.\implode(', ', $candidates)
    );

    $path = (string) $path;

    $decoded = \json_decode((string) \file_get_contents($path), true);

    expect($decoded)->toBeArray();

    $dataManager = app(DataManager::class);
    $dataManager->clearNinjaFormsData();
    $dataManager->addNinjaForm($formId, \is_array($decoded) ? $decoded : []);

    return app(NinjaFormsCoordinator::class)->createNinjaFormsFormIfNeeded(
        $formId,
        'Default',
        'default',
        $assetImporter
    );
}

/**
 * Read a created form's blueprint field handles, in blueprint order.
 *
 * @param  Form  $form  The created Statamic form
 * @return array<int, string> The field handles
 */
function ninjaFormsBlueprintHandles(Form $form): array
{
    $blueprint = BlueprintFacade::find('forms.'.ninjaFormsHandle($form));

    if (! $blueprint instanceof Blueprint) {
        return [];
    }

    /** @var array<int, string> $handles */
    $handles = $blueprint->fields()->all()->keys()->all();

    return $handles;
}

/**
 * Delete every form and blueprint this file creates.
 *
 * Testbench persists both to its workbench on disk, and FormCreator skips blueprint
 * creation entirely when one already exists — so a leftover blueprint makes a later run
 * assert against an earlier one's output while reporting a pass.
 */
function forgetNinjaFormsPerFormArtifacts(): void
{
    foreach (FormFacade::all() as $form) {
        if (! $form instanceof Form || ! \str_contains(ninjaFormsHandle($form), '_ninja-forms_')) {
            continue;
        }

        $blueprint = BlueprintFacade::find('forms.'.ninjaFormsHandle($form));

        if ($blueprint instanceof Blueprint) {
            $blueprint->delete();
        }

        $form->delete();
    }
}

beforeEach(function (): void {
    forgetNinjaFormsPerFormArtifacts();
});

afterEach(function (): void {
    app(DataManager::class)->clearNinjaFormsData();
    forgetNinjaFormsPerFormArtifacts();
});

it('imports the waiver form, preserving the repeater that wraps its body', function (): void {
    $form = importNinjaFormsFixture([
        'waiver-with-repeater.nff',
        'ninja-forms-export_cancer-uams-edu_cure_03_waiver-and-photo-release-agreement.nff',
    ], 3, $this->createMock(AssetImporter::class));

    expect($form)->toBeInstanceOf(Form::class);

    $handles = $form instanceof Form ? ninjaFormsBlueprintHandles($form) : [];

    // The whole participant section is one repeater — the form's defining structure, and
    // the thing most likely to be flattened by a regression.
    expect($handles)->not->toBeEmpty();

    $blueprint = $form instanceof Form ? BlueprintFacade::find('forms.'.ninjaFormsHandle($form)) : null;

    $types = [];

    if ($blueprint instanceof Blueprint) {
        foreach ($blueprint->fields()->all() as $handle => $field) {
            if (! $field instanceof Field) {
                continue;
            }

            $types[(string) $handle] = $field->type();
        }
    }

    // `repeater` maps to Statamic's `replicator`.
    expect(\in_array('replicator', $types, true))->toBeTrue(
        'the waiver repeater did not survive as a replicator: '.\json_encode($types)
    );

    // `submit` maps to false in the fieldtype map and must never reach a blueprint.
    foreach (\array_keys($types) as $handle) {
        expect($handle)->not->toStartWith('submit_');
    }
});

it('imports the choice-heavy form with real options rather than the settingModel template', function (): void {
    $form = importNinjaFormsFixture([
        'choices-and-calc.nff',
        'ninja-forms-export_cancer-uams-edu_get-involved_02_miracle-stars.nff',
    ], 2, $this->createMock(AssetImporter::class));

    expect($form)->toBeInstanceOf(Form::class);

    $blueprint = $form instanceof Form ? BlueprintFacade::find('forms.'.ninjaFormsHandle($form)) : null;

    expect($blueprint)->toBeInstanceOf(Blueprint::class);

    $optionSets = [];

    if ($blueprint instanceof Blueprint) {
        foreach ($blueprint->fields()->all() as $handle => $field) {
            if (! $field instanceof Field) {
                continue;
            }

            $config = $field->config();

            if (isset($config['options']) && \is_array($config['options']) && $config['options'] !== []) {
                $optionSets[(string) $handle] = $config['options'];
            }
        }
    }

    // The form's whole purpose is its choice lists; an import with none is a silent loss.
    expect($optionSets)->not->toBeEmpty();

    // The star-count select is the form's substance: a numeric quantity list whose first
    // choice is 1. The committed corpus trims it to four entries and the production export
    // carries ten, so assert the shared property rather than a length that differs between
    // the two corpora this file is designed to run against.
    $stars = null;

    foreach ($optionSets as $handle => $options) {
        if (\str_starts_with($handle, 'number_of_')) {
            $stars = $options;

            break;
        }
    }

    expect($stars)->toBeArray()->not->toBeEmpty();

    $starKeys = \array_map('strval', \array_keys(\is_array($stars) ? $stars : []));

    expect($starKeys[0] ?? null)->toBe('1');

    foreach ($starKeys as $key) {
        expect(\ctype_digit($key))->toBeTrue("unexpected non-numeric choice '{$key}'");
    }

    // Every list must carry at least two real choices; a single-entry list is what a
    // partial read of the option array would leave behind.
    foreach ($optionSets as $handle => $options) {
        expect(\count($options))->toBeGreaterThan(1, "field {$handle} imported a degenerate list");
    }
});

it('imports the notification form with its email actions configured and recipients resolved', function (): void {
    $form = importNinjaFormsFixture([
        'numbers-and-notifications.nff',
        'ninja-forms-export_cancer-uams-edu_cure_01_t-shirt-reservation.nff',
    ], 1, $this->createMock(AssetImporter::class));

    expect($form)->toBeInstanceOf(Form::class);

    $email = $form instanceof Form ? $form->email() : null;

    expect($email)->toBeArray()->not->toBeEmpty();

    $configs = \is_array($email) ? $email : [];

    foreach ($configs as $config) {
        expect($config)->toBeArray();

        if (! \is_array($config)) {
            continue;
        }

        // An unresolved merge tag would address the notification to a literal string.
        $recipient = $config['to'] ?? null;

        if (\is_string($recipient)) {
            expect($recipient)->not->toContain('{field:');
        }

        // Every notification must carry a subject; a blank one sends untitled mail.
        expect($config['subject'] ?? null)->toBeString();
    }
});

it('creates no form when the export carries no title', function (): void {
    $dataManager = app(DataManager::class);
    $dataManager->clearNinjaFormsData();

    // The negative control for all three imports above: FormCreator returns null on a
    // missing title, so a green result here would mean those assertions prove nothing
    // about the fixtures actually being read.
    $dataManager->addNinjaForm(99, [
        'settings' => [],
        'fields' => [['type' => 'textbox', 'key' => 'notes_1', 'label' => 'Notes']],
        'actions' => [],
    ]);

    $form = app(NinjaFormsCoordinator::class)->createNinjaFormsFormIfNeeded(
        99,
        'Default',
        'default',
        $this->createMock(AssetImporter::class)
    );

    expect($form)->toBeNull();
});
