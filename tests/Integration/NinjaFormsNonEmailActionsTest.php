<?php

declare(strict_types=1);

use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Facades\Form as FormFacade;
use Statamic\Fields\Blueprint;
use Statamic\Forms\Form;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms\DataManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms\NinjaFormsCoordinator;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * Non-email Ninja Forms actions survive the import (issue wordpress-importer-archive#651).
 *
 * `actions[]` was filtered to `type === 'email'`, so every other action was dropped
 * without a note. Across the production corpus that is `successmessage` (active on all
 * four forms), `save` (three), and `redirect` (one, active). Payment actions are wordpress-importer-archive#652.
 *
 * Ninja models confirmations as actions where Gravity Forms uses a `confirmations[]`
 * collection, so these map onto the same Statamic attributes the Gravity Forms importer
 * writes — `confirmation_type`, `confirmation_message`, `confirmation_redirect_url` —
 * rather than inventing a parallel vocabulary.
 *
 * @command  php vendor/bin/pest tests/Integration/NinjaFormsNonEmailActionsTest.php
 */
uses(StatamicTestCase::class);

/**
 * The handle FormCreator composes for this file's fixture form.
 */
const NINJA_ACTIONS_HANDLE = 'default_ninja-forms_21_actions-form';

/**
 * Import a form carrying the given actions and return the created Statamic form.
 *
 * @param  array<int, array<string, mixed>>  $actions  The Ninja Forms actions
 * @param  AssetImporter  $assetImporter  Asset importer for any referenced media
 * @return ?Form The created form
 */
function importNinjaFormsWithActions(array $actions, AssetImporter $assetImporter): ?Form
{
    forgetNinjaActionsArtifacts();

    $dataManager = app(DataManager::class);
    $dataManager->clearNinjaFormsData();
    $dataManager->addNinjaForm(21, [
        'settings' => ['title' => 'Actions Form'],
        'fields' => [['type' => 'email', 'key' => 'email_1', 'label' => 'Email']],
        'actions' => $actions,
    ]);

    app(NinjaFormsCoordinator::class)->createNinjaFormsFormIfNeeded(
        21,
        'Default',
        'default',
        $assetImporter
    );

    // Re-read through the facade so assertions are against what reached disk.
    $form = FormFacade::find(NINJA_ACTIONS_HANDLE);

    return $form instanceof Form ? $form : null;
}

/**
 * Read a value from the created form's stored data.
 *
 * Accepts null so the call sites stay readable: a Pest expectation does not narrow the
 * type for static analysis, and every caller has already asserted the form exists.
 *
 * @param  ?Form  $form  The Statamic form
 * @param  string  $key  The data key
 */
function ninjaActionsFormValue(?Form $form, string $key): mixed
{
    return $form instanceof Form ? $form->get($key) : null;
}

/**
 * Delete the form and blueprint this file creates.
 */
function forgetNinjaActionsArtifacts(): void
{
    $form = FormFacade::find(NINJA_ACTIONS_HANDLE);

    if ($form instanceof Form) {
        $form->delete();
    }

    $blueprint = BlueprintFacade::find('forms.'.NINJA_ACTIONS_HANDLE);

    if ($blueprint instanceof Blueprint) {
        $blueprint->delete();
    }
}

afterEach(function (): void {
    app(DataManager::class)->clearNinjaFormsData();
    forgetNinjaActionsArtifacts();
});

it('carries an active success message onto the Statamic confirmation attributes', function (): void {
    $form = importNinjaFormsWithActions([
        [
            'type' => 'successmessage',
            'active' => '1',
            'label' => 'Thank You',
            'message' => '<p>Thank you for reserving a T-Shirt.</p>',
        ],
    ], $this::createStub(AssetImporter::class));

    expect($form)->toBeInstanceOf(Form::class);

    expect(ninjaActionsFormValue($form, 'confirmation_type'))->toBe('message')
        ->and(ninjaActionsFormValue($form, 'confirmation_message'))
        ->toContain('Thank you for reserving a T-Shirt');
});

it('carries an active redirect, which is the whole of Love Lights post-submit behaviour', function (): void {
    $form = importNinjaFormsWithActions([
        [
            'type' => 'redirect',
            'active' => '1',
            'label' => 'Redirect',
            'redirect_url' => 'https://uamshealth.com/…/pay-for-love-lights/',
        ],
    ], $this::createStub(AssetImporter::class));

    expect($form)->toBeInstanceOf(Form::class);

    expect(ninjaActionsFormValue($form, 'confirmation_type'))->toBe('redirect')
        ->and(ninjaActionsFormValue($form, 'confirmation_redirect_url'))
        ->toBe('https://uamshealth.com/…/pay-for-love-lights/');
});

it('lets an active redirect supersede an active message, as Ninja does', function (): void {
    $form = importNinjaFormsWithActions([
        ['type' => 'successmessage', 'active' => '1', 'label' => 'Thanks', 'message' => '<p>Thanks.</p>'],
        ['type' => 'redirect', 'active' => '1', 'label' => 'Redirect', 'redirect_url' => 'https://example.edu/done'],
    ], $this::createStub(AssetImporter::class));

    expect($form)->toBeInstanceOf(Form::class);

    // The redirect leaves the page, so the message would never render.
    expect(ninjaActionsFormValue($form, 'confirmation_type'))->toBe('redirect')
        ->and(ninjaActionsFormValue($form, 'confirmation_message'))->toBeNull();
});

it('ignores an inactive action — the Love Lights shape exactly', function (): void {
    // Love Lights: success message off, redirect on. Importing the message would give the
    // form post-submit behaviour the live form does not have.
    $form = importNinjaFormsWithActions([
        ['type' => 'successmessage', 'active' => '0', 'label' => 'Thanks', 'message' => '<p>Thanks.</p>'],
        ['type' => 'redirect', 'active' => '1', 'label' => 'Redirect', 'redirect_url' => 'https://example.edu/pay'],
    ], $this::createStub(AssetImporter::class));

    expect($form)->toBeInstanceOf(Form::class);

    expect(ninjaActionsFormValue($form, 'confirmation_type'))->toBe('redirect')
        ->and(ninjaActionsFormValue($form, 'confirmation_message'))->toBeNull();
});

it('writes no confirmation at all when every candidate action is inactive', function (): void {
    // The negative control: an empty confirmation block must not be written onto forms
    // that have no active post-submit behaviour.
    $form = importNinjaFormsWithActions([
        ['type' => 'successmessage', 'active' => '0', 'label' => 'Thanks', 'message' => '<p>Thanks.</p>'],
    ], $this::createStub(AssetImporter::class));

    expect($form)->toBeInstanceOf(Form::class);

    expect(ninjaActionsFormValue($form, 'confirmation_type'))->toBeNull()
        ->and(ninjaActionsFormValue($form, 'confirmation_message'))->toBeNull()
        ->and(ninjaActionsFormValue($form, 'confirmation_redirect_url'))->toBeNull();
});

it('maps an inactive save action to submission storage being off', function (): void {
    $form = importNinjaFormsWithActions([
        ['type' => 'save', 'active' => '0', 'label' => 'Record Submission'],
    ], $this::createStub(AssetImporter::class));

    expect($form)->toBeInstanceOf(Form::class)
        ->and($form?->store())->toBeFalse();
});

it('maps an active save action to submission storage being on', function (): void {
    $form = importNinjaFormsWithActions([
        ['type' => 'save', 'active' => '1', 'label' => 'Record Submission'],
    ], $this::createStub(AssetImporter::class));

    expect($form)->toBeInstanceOf(Form::class)
        ->and($form?->store())->toBeTrue();
});

it('leaves storage at the Statamic default when the export has no save action', function (): void {
    // The T-Shirt Reservation shape. Reading absence as "do not store" would risk
    // discarding submissions on a form whose export simply predates the action.
    $form = importNinjaFormsWithActions([
        ['type' => 'successmessage', 'active' => '1', 'label' => 'Thanks', 'message' => '<p>Thanks.</p>'],
    ], $this::createStub(AssetImporter::class));

    expect($form)->toBeInstanceOf(Form::class)
        ->and($form?->store())->toBeTrue();
});

it('still imports email actions exactly as before', function (): void {
    $form = importNinjaFormsWithActions([
        ['type' => 'successmessage', 'active' => '1', 'label' => 'Thanks', 'message' => '<p>Thanks.</p>'],
        [
            'type' => 'email', 'active' => '1', 'label' => 'Admin Email',
            'to' => 'admin@example.edu', 'email_subject' => 'New submission',
            'email_message' => '{fields_table}', 'from_name' => '', 'from_address' => '',
            'cc' => '', 'bcc' => '', 'reply_to' => '',
        ],
    ], $this::createStub(AssetImporter::class));

    expect($form)->toBeInstanceOf(Form::class);

    $email = $form?->email();

    // Bounding the change on the other side: widening the action filter must not disturb
    // the notification path, including the {fields_table} translation from wordpress-importer-archive#649.
    expect($email)->toBeArray()->not->toBeEmpty();

    $first = \is_array($email) ? ($email[0] ?? null) : null;

    expect(\is_array($first) ? ($first['message'] ?? null) : null)->toBe('{{ all_fields }}');
});
