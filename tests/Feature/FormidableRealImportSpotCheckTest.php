<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Statamic\Facades\Form as FormFacade;
use Statamic\Facades\Site as SiteFacade;
use Statamic\Forms\Form;
use Statamic\Sites\Site;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\ContentDetector;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\DataManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\FormBlueprintCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\FormConfigurationManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\FormCreationCoordinator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\FormidableFormsCoordinator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\FormIdExtractor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\UserInteractionHandler;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\FormidableForms\Selector;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\FormidableForms\Validator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\Loaders\FormidableFormsLoader;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\Loaders\FormidableXmlFileLoader;

uses(TestCase::class);

beforeEach(function (): void {
    // Reset the created-handle tracker so teardown never touches a stale list
    $this->createdHandles = [];
});

afterEach(function (): void {
    // Remove every form this test persisted to disk, and the blueprint its import wrote
    $blueprintCreator = new FormBlueprintCreator;

    foreach (formidableSpotCheckCreatedHandles($this->createdHandles) as $handle) {
        // Look the form up and delete it when it still exists
        $existing = FormFacade::find($handle);

        // Delete the form when it still exists
        if ($existing instanceof Form) {
            $existing->delete();
        }

        // `Form::delete()` leaves `resources/blueprints/forms/<handle>.yaml` behind, which
        // the host slot's release then refuses on (wordpress-importer-archive#1137). Scoped to this test's own
        // handles, so a blueprint another suite is mid-way through is never touched.
        $blueprintCreator->deleteBlueprint($handle);
    }
});

/**
 * Real end-to-end import spot-check for the Formidable view loader (issue wordpress-importer-archive#37).
 *
 * The audit reproducer (host tests/Feature/FormidableNotificationAuditTest.php)
 * proves the mapper by reconstructing the views array with its own parseExport().
 * This spot-check instead drives the production path on a real-world export:
 * FormidableXmlFileLoader::load() feeds
 * FormCreationCoordinator::createStatamicFormFromFormidableForm().
 *
 * It confirms the production loader actually extracts the frm_form_actions views
 * from a real file, that the form-creation pipeline persists a Statamic form, and
 * that the persisted email configuration matches the reproducer's output for the
 * same form (aging-uams-edu form 1, "Contact Us").
 *
 * The fixtures live outside this repo, so the directory is read from config
 * (testing.frm_audit_fixtures_dir, backed by FRM_AUDIT_FIXTURES_DIR) and the test
 * skips itself when the export is unavailable, keeping CI green on runners that do
 * not have the wordpress-importer-exports corpus checked out.
 *
 * @command  php artisan test vendor/uams-web/aether-wordpress-importer/tests/Feature/FormidableRealImportSpotCheckTest.php
 */
it('persists an email config matching the audit reproducer from a real export', function (): void {
    // The production loader extracts the email view from a real export and the
    // persisted Statamic form's email config matches the audit reproducer output.

    // Resolve the fixtures directory from the configuration
    $fixturesDir = config('testing.frm_audit_fixtures_dir');

    // ---
    // Convert the fixtures directory to a string, or an empty string if it's not a
    // string
    // ---
    $fixturesDir = \is_string($fixturesDir) ? $fixturesDir : '';

    // Skip cleanly when the external corpus is not checked out (e.g., CI)
    if (
        $fixturesDir === ''
        || ! is_dir($fixturesDir)
    ) {
        TestCase::markTestSkipped(
            "Formidable export corpus unavailable; set FRM_AUDIT_FIXTURES_DIR. Looked in: \"{$fixturesDir}\""
        );
    }

    // Build the path to the sampled export (one form, one email notification)
    $path = rtrim($fixturesDir, '/\\').\DIRECTORY_SEPARATOR.'formidable-forms-export_aging-uams-edu.xml';

    // Skip when the specific sampled export is missing from the corpus
    if (! is_file($path)) {
        TestCase::markTestSkipped("Sampled export not found at: \"{$path}\"");
    }

    // Build the production loader
    $loader = new FormidableXmlFileLoader('FormidableForms');

    // Load the export from disk through the production loader
    $forms = $loader->load(
        $path,
        formidableSpotCheckDummyCommand()
    );

    // Confirm the loader returned a usable forms array
    expect($forms)->toBeArray();

    if (! is_array($forms)) {
        throw new RuntimeException('The production loader should return a forms array.');
    }

    // Locate form 1 ("Contact Us") in the loaded data
    $form1 = formidableSpotCheckFindFormById(
        $forms,
        1
    );

    // Confirm form 1 is present in the loaded export
    expect($form1)->not->toBeNull();

    if ($form1 === null) {
        throw new RuntimeException('Form id 1 should be present in the loaded export.');
    }

    // Confirm the loader has attached the email view (the wordpress-importer-archive#37 fix) by menu_order
    expect($form1)->toHaveKey('views');

    // Resolve the views array for typed access below
    $views = $form1['views'];

    // Confirm form 1 has exactly one action view in the export
    expect($views)->toBeArray();

    if (! is_array($views)) {
        throw new RuntimeException('The production loader must attach a views array.');
    }

    expect($views)->toHaveCount(1);

    // Resolve the first view entry
    $view = $views[0];

    // Confirm the attached view should be the email notification action
    expect($view)->toBeArray();

    if (! is_array($view)) {
        throw new RuntimeException('The attached view should be an array.');
    }

    // The attached view should be the email notification action
    expect($view['excerpt'] ?? null)->toBe('email');

    // Confirm the loader should capture the raw view content JSON verbatim
    expect($view['content'] ?? null)->toBe(
        '{"email_to":"[admin_email]","cc":"","bcc":"","reply_to":"[3]","from":"[sitename] <[admin_email]>","email_subject":"","email_message":"[default-message]","event":["create"],"conditions":{"send_stop":"send","any_all":"any"}}'
    );

    // Resolve the default site handle
    $siteHandle = 'default';

    // Resolve the destination site for the production form-creation call
    $site = SiteFacade::get($siteHandle);

    // Confirm the site is present and get its name
    if ($site instanceof Site) {
        $resolvedSiteName = $site->name();
        $siteName = \is_string($resolvedSiteName) ? $resolvedSiteName : $siteHandle;
    } else {
        $siteName = $siteHandle;
    }

    // Create a real Statamic form through the production form-creation path
    $createdForm = formidableSpotCheckMakeCoordinator()->createStatamicFormFromFormidableForm(
        1,
        $forms,
        $siteName,
        $siteHandle,
        new AssetImporter
    );

    // Confirm a concrete form was created
    expect($createdForm)->toBeInstanceOf(Form::class);

    if (! $createdForm instanceof Form) {
        throw new RuntimeException('A Statamic form should be created.');
    }

    // Track the handle so teardown removes the created form
    $formHandle = $createdForm->handle();
    expect($formHandle)->toBeString();

    if (! is_string($formHandle)) {
        throw new RuntimeException('The created form handle should be a string.');
    }

    $this->createdHandles[] = $formHandle;

    // Resolve the path to the created form
    $persistedPath = $createdForm->path();

    // Confirm the path exists (the created form should be persisted to disk)
    expect($persistedPath)->toBeFile();

    // Read and parse the persisted form YAML
    $fileContents = file_get_contents($persistedPath);
    expect($fileContents)->toBeString();

    if (! is_string($fileContents)) {
        throw new RuntimeException('The persisted form file should be readable.');
    }

    $parsedYaml = Yaml::parse($fileContents);
    $persisted = \is_array($parsedYaml) ? $parsedYaml : [];

    // Confirm the persisted form has an email config
    expect($persisted)->toHaveKey('email');

    // Resolve the email configs array
    $emailConfigs = $persisted['email'];

    // Confirm the persisted form has exactly one email notification
    expect($emailConfigs)->toBeArray();

    if (! is_array($emailConfigs)) {
        throw new RuntimeException('The persisted email config should be an array.');
    }

    expect($emailConfigs)->toHaveCount(1);

    // Resolve the email config
    $email = $emailConfigs[0];

    // Confirm the email config is an array
    expect($email)->toBeArray();

    if (! is_array($email)) {
        throw new RuntimeException('The email notification should be an array.');
    }

    // Confirm the email config is enabled
    expect($email['enabled'] ?? null)->toBeTrue();

    // Confirm the email config id derives from the view post_name
    expect($email['id'] ?? null)->toBe('1_email_1');

    // Confirm the [admin_email] merge tag maps to {{ admin_email }}
    expect($email['to'] ?? null)->toBe('{{ admin_email }}');

    // Confirm the recipient type is email
    expect($email['to_type'] ?? null)->toBe('email');

    // Confirm the [admin_email] from address maps to {{ admin_email }}
    expect($email['from'] ?? null)->toBe('{{ admin_email }}');

    // Confirm the [sitename] merge tag maps to {{ site:name }}
    expect($email['from_name'] ?? null)->toBe('{{ site:name }}');

    // Confirm the [3] field reference maps to the submission email field
    expect($email['reply_to'] ?? null)->toBe('{{ submission:email_3 }}');

    // Confirm the reply-to type is email
    expect($email['reply_to_type'] ?? null)->toBe('email');

    // Resolve the nested message text from the email config
    expect($email)->toHaveKey('message');
    $messageParts = $email['message'];
    expect($messageParts)->toBeArray();

    if (! is_array($messageParts)) {
        throw new RuntimeException('The email message should be an array.');
    }

    expect($messageParts)->toHaveKey(0);
    $contentWrapper = $messageParts[0];
    expect($contentWrapper)->toBeArray();

    if (! is_array($contentWrapper)) {
        throw new RuntimeException('The message content wrapper should be an array.');
    }

    expect($contentWrapper)->toHaveKey('content');
    $textParts = $contentWrapper['content'];
    expect($textParts)->toBeArray();

    if (! is_array($textParts)) {
        throw new RuntimeException('The message content should be an array.');
    }

    expect($textParts)->toHaveKey(0);
    $textBlock = $textParts[0];
    expect($textBlock)->toBeArray();

    if (! is_array($textBlock)) {
        throw new RuntimeException('The message text block should be an array.');
    }

    $messageText = $textBlock['text'] ?? null;

    // Confirm the email body maps to {{ all_fields }}
    expect($messageText)->toBe('{{ all_fields }}');
});

/**
 * Coerce the bound test case's created-form handle tracker to a typed list.
 *
 * The tracker lives on the Pest-bound TestCase as a dynamic property, so this
 * normalizes it to a list of string handles for the teardown lookup.
 *
 * @param  mixed  $handles  The raw handle tracker from the bound test case.
 * @return list<string> The string form handles persisted during the test.
 */
function formidableSpotCheckCreatedHandles(mixed $handles): array
{
    // Guard against an uninitialised or non-array tracker
    if (! \is_array($handles)) {
        return [];
    }

    $result = [];

    foreach ($handles as $handle) {
        // Only keep string handles for the form lookup
        if (\is_string($handle)) {
            $result[] = $handle;
        }
    }

    return $result;
}

/**
 * Build a throwaway console command so the production loader has somewhere to
 * report errors (only exercised on failure paths, never on a valid file).
 *
 * @return Command A minimal, runnable command instance.
 */
function formidableSpotCheckDummyCommand(): Command
{
    // Provide a signature so the command constructs without a registered name
    return new class extends Command
    {
        protected $signature = 'test:formidable-spot-check';
    };
}

/**
 * Build the production form-creation coordinator graph, mirroring the wiring
 * the import command performs.
 *
 * The coordinator, the user-interaction handler and the form-creation
 * coordinator share ONE `DataManager`, as they do in production, where the
 * container binds the manager as a singleton per plugin (wordpress-importer-archive#1103). Three
 * separate instances would be three separate stores: forms bound through the
 * handler would read back empty through the coordinator.
 *
 * @return FormCreationCoordinator The wired coordinator under test.
 */
function formidableSpotCheckMakeCoordinator(): FormCreationCoordinator
{
    // One store for the whole graph, as the container provides
    $dataManager = new DataManager;

    // Build the top-level Formidable coordinator (form-creation set afterwards)
    $coordinator = new FormidableFormsCoordinator(
        new ContentDetector,
        new FormIdExtractor,
        $dataManager,
        new UserInteractionHandler(
            new ContentDetector,
            $dataManager,
            new Selector(
                new Validator,
                new FormidableFormsLoader
            )
        ),
        null
    );

    // Create the form-creation coordinator around the real configuration manager
    $formCreationCoordinator = new FormCreationCoordinator(
        $dataManager,
        new FormConfigurationManager
    );

    // Wire the two coordinators together as the production flow does
    $formCreationCoordinator->setCoordinator($coordinator);
    $coordinator->setFormCreationCoordinator($formCreationCoordinator);

    // Return the created form-creation coordinator
    return $formCreationCoordinator;
}

/**
 * Normalize a loaded form entry to a string-keyed array.
 *
 * @param  mixed  $candidate  Raw form data from the loader.
 * @return array<string, mixed>|null Normalized form data, or null when invalid.
 */
function formidableSpotCheckNormalizeFormArray(mixed $candidate): ?array
{
    if (! \is_array($candidate)) {
        return null;
    }

    $normalized = [];

    foreach ($candidate as $key => $value) {
        $normalized[(string) $key] = $value;
    }

    return $normalized;
}

/**
 * Locate a loaded form by its Formidable id.
 *
 * @param  array<int|string, mixed>  $forms  The forms array returned by the production loader.
 * @param  int  $id  The Formidable form id to find.
 * @return array<string, mixed>|null The matching form data, or null when absent.
 */
function formidableSpotCheckFindFormById(
    array $forms,
    int $id
): ?array {
    // Iterate through the forms to find a match by id
    foreach ($forms as $form) {
        // Normalize the form entry for typed access
        $normalizedForm = formidableSpotCheckNormalizeFormArray($form);

        if ($normalizedForm === null) {
            continue;
        }

        // Check if the form's id matches the requested id
        if (($normalizedForm['id'] ?? null) === $id) {
            // Return the form when found
            return $normalizedForm;
        }
    }

    // Return null when no form is found
    return null;
}
