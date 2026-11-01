<?php

declare(strict_types=1);

use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Facades\Form as FormFacade;
use Statamic\Fields\Blueprint;
use Statamic\Forms\Form;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormPluginCoordinator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms\DataManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms\NinjaFormsCoordinator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\NinjaFormsBlockProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\NinjaFormsShortcodeProcessor;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * Proves the Ninja Forms importer is actually reachable end to end (issue #10).
 *
 * Every piece built in the earlier slices — detector, extractor, fieldtype map, blueprint
 * creator, configuration manager — was unit-tested in isolation and passed while nothing
 * in the import pipeline ever called any of it. These tests drive the wired path: a
 * referenced form ID reaches FormPluginCoordinator, survives the shared availability
 * filter, produces a Statamic form and blueprint, and carries its email notifications.
 *
 * Each assertion here corresponds to a defect this file found, all of the same shape —
 * shared code carrying a two-plugin assumption that fails silently on a third:
 *
 * - The availability filter reads `$form['id']`, which a `.nff` has not got, so every
 *   referenced Ninja Forms ID was filtered away and zero forms were created.
 * - FormCreator reads the title off the top level, where a `.nff` keeps it under
 *   `settings`, so creation returned null with no error.
 * - The email-configuration step is gated on an allow-list of plugin names that did not
 *   include Ninja Forms, so forms imported with no notifications at all.
 * - The recipient mapping is gated on Gravity Forms' `toType` discriminator, which Ninja
 *   Forms actions do not carry, so every notification imported addressed to nobody.
 *
 * @command  php vendor/bin/pest tests/Integration/NinjaFormsWiringTest.php
 */
uses(StatamicTestCase::class);

/**
 * The handle FormCreator composes for the fixture form.
 *
 * Shaped `{site}_{plugin}_{source form id}_{slugified title}`.
 */
const NINJA_FORMS_TEST_HANDLE = 'default_ninja-forms_2_t-shirt-reservation';

/**
 * A minimal Ninja Forms export in the real `.nff` envelope shape.
 *
 * Mirrors the production T-Shirt Reservation export: a `settings`/`fields`/`actions`
 * envelope with no top-level `id`, keys carrying the plugin's creation-timestamp suffix,
 * and an email action whose recipient is a `{field:<key>}` merge tag.
 *
 * @return array<string, mixed>
 */
function ninjaFormsExportEnvelope(): array
{
    return [
        'settings' => [
            'title' => 'T-Shirt Reservation',
            'embed_form' => "[ninja_form id='2']",
        ],
        'fields' => [
            [
                'type' => 'firstname',
                'key' => 'firstname_1642172426631',
                'label' => 'First Name',
                'required' => 1,
            ],
            [
                'type' => 'email',
                'key' => 'email_1642172432990',
                'label' => 'Email',
                'required' => 1,
            ],
            [
                'type' => 'submit',
                'key' => 'submit_1642172445123',
                'label' => 'Reserve',
            ],
        ],
        'actions' => [
            [
                'type' => 'successmessage',
                'label' => 'Thank You for reserving a T-Shirt',
            ],
            [
                'type' => 'email',
                'active' => '1',
                'label' => 'Customer Email',
                'to' => '{field:email_1642172432990}',
                'email_subject' => 'Thank you for reserving your T-Shirt(s)',
                'email_message' => 'Your T-Shirt(s) will be available at the event.',
                'from_name' => '',
                'from_address' => '',
                'cc' => '',
                'bcc' => '',
                'reply_to' => '',
            ],
        ],
    ];
}

/**
 * Reset the processors' static form-ID caches so rows cannot leak into each other.
 */
function clearNinjaFormsProcessorState(): void
{
    NinjaFormsBlockProcessor::clearFormIds();
    NinjaFormsShortcodeProcessor::clearFormIds();
}

/**
 * Delete any form and blueprint left behind by an earlier run.
 *
 * Testbench persists both to its workbench on disk, so they survive the process. That
 * matters more than ordinary tidiness here: FormCreator skips blueprint creation entirely
 * when `blueprintExists()` is true, so a stale blueprint makes this file assert against a
 * previous run's output while reporting a pass. This was observed, not anticipated.
 */
function forgetNinjaFormsArtifacts(): void
{
    $form = FormFacade::find(NINJA_FORMS_TEST_HANDLE);

    if ($form instanceof Form) {
        $form->delete();
    }

    $blueprint = BlueprintFacade::find('forms.'.NINJA_FORMS_TEST_HANDLE);

    if ($blueprint instanceof Blueprint) {
        $blueprint->delete();
    }
}

/**
 * Load the fixture export into the Ninja Forms store, bound to form ID 2.
 */
function seedNinjaFormsExport(): DataManager
{
    $dataManager = app(DataManager::class);
    $dataManager->clearNinjaFormsData();
    $dataManager->addNinjaForm(2, ninjaFormsExportEnvelope());

    return $dataManager;
}

it('wires the Ninja Forms coordinator into the shared plugin coordinator', function (): void {
    // Resolving through the container is the path the console command takes, so a missing
    // binding or an unresolvable dependency fails here rather than mid-import.
    app(FormPluginCoordinator::class);

    // The creation coordinator is attached by the plugin coordinator's own constructor;
    // without it createNinjaFormsFormIfNeeded() returns null before doing any work.
    expect(app(NinjaFormsCoordinator::class)->getFormCreationCoordinator())->not->toBeNull();
});

it('stamps the binding form ID onto a stored export so the availability filter can read it', function (): void {
    $dataManager = seedNinjaFormsExport();

    $stored = $dataManager->getNinjaFormsData();

    expect($stored)->toBeArray();

    $export = \is_array($stored) ? ($stored[2] ?? null) : null;

    expect($export)->toBeArray();

    // The stamp is the key FormPluginCoordinator's availability filter reads.
    expect(\is_array($export) ? ($export['id'] ?? null) : null)->toBe(2)
        // The settings envelope is lifted so FormCreator can read the title.
        ->and(\is_array($export) ? ($export['title'] ?? null) : null)->toBe('T-Shirt Reservation')
        // Lifting must not displace what the export itself carries.
        ->and(\is_array($export) ? ($export['fields'] ?? null) : null)->toBeArray();

    $dataManager->clearNinjaFormsData();
});

it('creates a Statamic form and blueprint for a referenced Ninja Forms ID', function (): void {
    clearNinjaFormsProcessorState();
    forgetNinjaFormsArtifacts();

    $dataManager = seedNinjaFormsExport();

    // Reference form ID 2 the way an imported page does: run the real block processor over
    // a real `wp:ninja-forms/form` comment node. That exercises the capture leg too,
    // including the formID -> formId aliasing, rather than seeding the static cache.
    $document = new DOMDocument;
    $node = $document->createComment(' wp:ninja-forms/form {"formID":2} /');

    (new NinjaFormsBlockProcessor)->process(
        $node,
        ['formID' => 2],
        'default',
        $this::createStub(AssetImporter::class),
        new BufferedOutput
    );

    expect(NinjaFormsBlockProcessor::getFormIds())->toContain('2');

    $output = new BufferedOutput;

    app(FormPluginCoordinator::class)->processFormImports(
        'default',
        $output,
        $this::createStub(AssetImporter::class)
    );

    $written = $output->fetch();

    // The import pass must reach this form rather than filtering it away.
    expect($written)->toContain("Processing Ninja Forms form ID '2'")
        ->and($written)->toContain('Created form')
        ->and(FormFacade::find(NINJA_FORMS_TEST_HANDLE))->not->toBeNull();

    // The blueprint is the actual payload, so assert the fields survived the mapping
    // rather than trusting the log line, which prints either way.
    $blueprint = BlueprintFacade::find('forms.'.NINJA_FORMS_TEST_HANDLE);

    expect($blueprint)->toBeInstanceOf(Blueprint::class);

    $fieldHandles = $blueprint instanceof Blueprint
        ? $blueprint->fields()->all()->keys()->all()
        : [];

    // The handle is the export's `key` verbatim, timestamp suffix included — a stable ugly
    // handle beats a readable one that changes on every import.
    expect($fieldHandles)->toContain('firstname_1642172426631')
        ->and($fieldHandles)->toContain('email_1642172432990')
        // `submit` maps to false in the fieldtype map, so it must not reach the blueprint.
        ->and($fieldHandles)->not->toContain('submit_1642172445123')
        // Two mapped fields in, two out: nothing silently added or dropped.
        ->and($fieldHandles)->toHaveCount(2);

    $dataManager->clearNinjaFormsData();
    clearNinjaFormsProcessorState();
    forgetNinjaFormsArtifacts();
});

it('configures email notifications, with recipients, for an imported Ninja Forms form', function (): void {
    clearNinjaFormsProcessorState();
    forgetNinjaFormsArtifacts();

    $dataManager = seedNinjaFormsExport();

    $form = app(NinjaFormsCoordinator::class)->createNinjaFormsFormIfNeeded(
        2,
        'Default',
        'default',
        $this::createStub(AssetImporter::class)
    );

    expect($form)->toBeInstanceOf(Form::class);

    // The shared engine gates email configuration behind an allow-list of plugin names. A
    // plugin missing from it imports its forms with no notifications and says nothing, so
    // assert the configuration landed rather than that the form was created.
    $email = $form instanceof Form ? $form->email() : null;

    expect($email)->toBeArray()->not->toBeEmpty();

    $first = \is_array($email) ? ($email[0] ?? null) : null;

    expect($first)->toBeArray();

    $recipient = \is_array($first) ? ($first['to'] ?? null) : null;

    expect(\is_array($first) ? ($first['subject'] ?? null) : null)
        ->toBe('Thank you for reserving your T-Shirt(s)')
        // The recipient must survive the Gravity-Forms-shaped `toType` gate at all...
        ->and($recipient)->toBeString()
        // ...and resolve to the blueprint handle rather than staying a raw merge tag,
        // which would address the notification to a literal string.
        ->and($recipient)->not->toContain('{field:')
        ->and($recipient)->toContain('email_1642172432990')
        // The action is active in the export, so the notification imports enabled.
        ->and(\is_array($first) ? ($first['enabled'] ?? null) : null)->toBe('1');

    $dataManager->clearNinjaFormsData();
    clearNinjaFormsProcessorState();
    forgetNinjaFormsArtifacts();
});

it('reports nothing to import when no Ninja Forms IDs are referenced', function (): void {
    clearNinjaFormsProcessorState();

    app(NinjaFormsCoordinator::class)->clearNinjaFormsData();

    $output = new BufferedOutput;

    app(FormPluginCoordinator::class)->processFormImports(
        'default',
        $output,
        $this::createStub(AssetImporter::class)
    );

    // The negative control for the tests above: with no referenced IDs the pass returns
    // early, so "Processing Ninja Forms" appearing here would mean those assertions prove
    // nothing about the wiring.
    expect($output->fetch())->not->toContain('Processing Ninja Forms');
});
