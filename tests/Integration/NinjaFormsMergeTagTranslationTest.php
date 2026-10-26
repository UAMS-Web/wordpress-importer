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
 * Merge-tag translation in imported Ninja Forms notifications (issue wordpress-importer-archive#644).
 *
 * Enumerating every brace token across the four production exports yields exactly four
 * shapes — `{field:…}` (20 occurrences), `{fields_table}` (18), `{wp:…}` (11) and
 * `{calc:…}` (4), with no `{system:…}` at all. This covers the ones that reach an email
 * body, and it asserts against the **persisted** configuration rather than a transient
 * copy, which is the distinction wordpress-importer-archive#38 turned on for Formidable Forms: a translation applied
 * only to `currentFormData['email']` is replaced wholesale before `Form::save()` and never
 * reaches disk.
 *
 * `{fields_table}` is the reason the file exists. It is on every email action of all four
 * production forms, and unmapped it survives verbatim — so every imported notification
 * arrives with the literal token where the submitted data should be, with no error raised.
 *
 * @command  php vendor/bin/pest tests/Integration/NinjaFormsMergeTagTranslationTest.php
 */
uses(StatamicTestCase::class);

/**
 * The handle FormCreator composes for this file's fixture form.
 */
const NINJA_MERGE_TAG_HANDLE = 'default_ninja-forms_11_merge-tag-form';

/**
 * Import a one-action export whose email body is the given string, and return what was
 * persisted to the form's email configuration.
 *
 * @param  string  $emailMessage  The Ninja Forms email body, merge tags included
 * @param  AssetImporter  $assetImporter  Asset importer for any referenced media
 * @return array<int|string, mixed> The persisted email configuration
 */
function importNinjaFormsEmailBody(string $emailMessage, AssetImporter $assetImporter): array
{
    forgetNinjaMergeTagArtifacts();

    $dataManager = app(DataManager::class);
    $dataManager->clearNinjaFormsData();
    $dataManager->addNinjaForm(11, [
        'settings' => ['title' => 'Merge Tag Form'],
        'fields' => [
            [
                'type' => 'email',
                'key' => 'email_1642172432990',
                'label' => 'Email',
            ],
        ],
        'actions' => [
            [
                'type' => 'email',
                'active' => '1',
                'label' => 'Admin Email',
                'to' => 'admin@example.edu',
                'email_subject' => 'New submission',
                'email_message' => $emailMessage,
                'from_name' => '',
                'from_address' => '',
                'cc' => '',
                'bcc' => '',
                'reply_to' => '',
            ],
        ],
    ]);

    $form = app(NinjaFormsCoordinator::class)->createNinjaFormsFormIfNeeded(
        11,
        'Default',
        'default',
        $assetImporter
    );

    expect($form)->toBeInstanceOf(Form::class);

    // Re-read through the facade rather than using the returned instance, so the assertion
    // is against what actually reached disk.
    $persisted = FormFacade::find(NINJA_MERGE_TAG_HANDLE);

    expect($persisted)->toBeInstanceOf(Form::class);

    $email = $persisted instanceof Form ? $persisted->email() : null;

    expect($email)->toBeArray()->not->toBeEmpty();

    $first = \is_array($email) ? ($email[0] ?? null) : null;

    expect($first)->toBeArray();

    return \is_array($first) ? $first : [];
}

/**
 * Delete the form and blueprint this file creates.
 *
 * Testbench persists both to its workbench, and FormCreator skips blueprint creation when
 * one already exists — so a leftover makes a later run assert against an earlier one.
 */
function forgetNinjaMergeTagArtifacts(): void
{
    $form = FormFacade::find(NINJA_MERGE_TAG_HANDLE);

    if ($form instanceof Form) {
        $form->delete();
    }

    $blueprint = BlueprintFacade::find('forms.'.NINJA_MERGE_TAG_HANDLE);

    if ($blueprint instanceof Blueprint) {
        $blueprint->delete();
    }
}

afterEach(function (): void {
    app(DataManager::class)->clearNinjaFormsData();
    forgetNinjaMergeTagArtifacts();
});

it('translates {fields_table} to the Statamic all_fields tag', function (): void {
    $config = importNinjaFormsEmailBody(
        // Exactly what the T-Shirt Reservation export carries.
        'Your T-Shirt(s) will be available to purchase at the event.<br><br>{fields_table}',
        $this->createMock(AssetImporter::class)
    );

    $message = $config['message'] ?? null;

    expect($message)->toBeString()
        ->and($message)->toContain('{{ all_fields }}')
        // The literal token must not survive — an untranslated body loses its content.
        ->and($message)->not->toContain('{fields_table}')
        // Surrounding prose is untouched.
        ->and($message)->toContain('available to purchase at the event');
});

it('translates a bare {fields_table} body, which four production actions carry', function (): void {
    // Several production email actions have exactly this as their entire body.
    $config = importNinjaFormsEmailBody('{fields_table}', $this->createMock(AssetImporter::class));

    expect($config['message'] ?? null)->toBe('{{ all_fields }}');
});

it('still translates the {wp:…} system tags alongside it', function (): void {
    $config = importNinjaFormsEmailBody(
        'Site: {wp:site_title} at {wp:site_url}.<br>{fields_table}',
        $this->createMock(AssetImporter::class)
    );

    $message = $config['message'] ?? null;

    expect($message)->toBeString()
        ->and($message)->toContain('{{ site:name }}')
        ->and($message)->toContain('{{ config:app:url }}')
        ->and($message)->toContain('{{ all_fields }}')
        ->and($message)->not->toContain('{wp:')
        ->and($message)->not->toContain('{fields_table}');
});

it('still resolves {field:…} references to their blueprint handles', function (): void {
    $config = importNinjaFormsEmailBody(
        'Reply to {field:email_1642172432990}.<br>{fields_table}',
        $this->createMock(AssetImporter::class)
    );

    $message = $config['message'] ?? null;

    expect($message)->toBeString()
        // The field reference resolves to the handle, which is the export key verbatim.
        ->and($message)->toContain('email_1642172432990')
        ->and($message)->not->toContain('{field:')
        ->and($message)->toContain('{{ all_fields }}');
});

it('leaves an unknown token alone rather than mangling it', function (): void {
    // The negative control. No `{system:…}` occurs in the corpus, so nothing maps it — and
    // a visible unresolved token in the CP is a better failure than a silently wrong value.
    $config = importNinjaFormsEmailBody(
        'Ref {system:not_a_real_tag}.<br>{fields_table}',
        $this->createMock(AssetImporter::class)
    );

    $message = $config['message'] ?? null;

    expect($message)->toBeString()
        ->and($message)->toContain('{system:not_a_real_tag}')
        // ...while the mapped token beside it is still translated, so this proves the
        // translation ran at all rather than passing everything through.
        ->and($message)->toContain('{{ all_fields }}');
});
