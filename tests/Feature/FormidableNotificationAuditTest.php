<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Statamic\Facades\Form as FormFacade;
use Statamic\Forms\Form;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\FormConfigurationManager;

// Shared formAuditFormidableFixturesDir() resolver — the committed corpus by
// default, overridable host-side. See the file header for the full contract.
require_once __DIR__.'/../Support/form-audit-test-support.php';

/**
 * One-shot dump test for the Formidable Forms notification import audit (issue wordpress-importer-archive#26).
 *
 * Walks every Formidable Forms WXR XML export in the fixtures directory, parses
 * each <form> together with its <view> (frm_form_actions) siblings - grouped by
 * <menu_order> = form id - into the shape FormConfigurationManager expects, runs
 * each form through FormConfigurationManager::configureFormEmailSettings(), and
 * dumps the resulting Statamic Form configuration (including the YAML actually
 * persisted to disk) to storage/app/audit/ for inspection.
 *
 * NOTE: the production loader (Prompts\Loaders\XmlFileLoader) does NOT extract
 * <view> elements - it only parses <form>/<field>. This test reconstructs the
 * `views` array the mapper expects so the mapper's coverage can be audited in
 * isolation; the missing-views loader gap is documented in the audit comment.
 *
 * This is NOT a regression test - the assertions only confirm a dump was
 * produced for every form-with-actions and that the email input/output counts
 * line up (mapper crashes are recorded as audit data, not failures). The audit
 * value is in inspecting the YAML output.
 *
 * Minimal synthetic fixtures are committed under
 * tests/Fixtures/form-audit/formidable/ and used by default, so the audit runs
 * wherever this package is installed and needs no host-app wiring. The host may
 * repoint it through config testing.frm_audit_fixtures_dir (backed by
 * FRM_AUDIT_FIXTURES_DIR) at a local copy of the full wordpress-importer
 * exports to audit the real-world corpus instead - this audit discovers its
 * inputs by globbing *.xml, so any export directory works.
 *
 * Host-bound for the OPTIONAL FIXTURE CORPUS, not for the host app — narrowed in wordpress-importer-archive#844.
 * The first half of the old reason ("drives real Statamic forms through the booted host
 * app") is false: measured under `StatamicTestCase` with the corpus present, both tests
 * pass in the minimal band (2 passed, 5 assertions). The second half is the real and
 * sufficient one — the corpus is optional, the audit `markTestSkipped`s without it, and
 * a gate test that skips on a fresh slot reads as covered while verifying nothing. It
 * stays in tests/Feature on that basis (uams-statamic-archive#1725). It creates and deletes
 * throwaway Statamic forms, so it stays quarantined to the host suite's
 * `serial` group.
 *
 * @command  php artisan test --compact vendor/uams-web/aether-wordpress-importer/tests/Feature/FormidableNotificationAuditTest.php
 */
uses(TestCase::class)->group('serial');

/**
 * Absolute path to the directory holding the Formidable Forms XML fixtures.
 *
 * Resolved by formAuditFormidableFixturesDir(): the host-side
 * testing.frm_audit_fixtures_dir override when set, else the committed corpus.
 *
 * @var string
 */
$formidableNotificationAuditFixturesDir = '';

/**
 * Absolute path where the per-form YAML audit dumps are written.
 *
 * @var string
 */
$formidableNotificationAuditDumpDir = '';

beforeEach(function () use (&$formidableNotificationAuditFixturesDir, &$formidableNotificationAuditDumpDir): void {
    // Resolve the Formidable fixtures directory (committed corpus by default)
    $formidableNotificationAuditFixturesDir = formAuditFormidableFixturesDir();

    // Resolve the audit output directory under storage/app
    $formidableNotificationAuditDumpDir = storage_path('app/audit/issue-26-notification-import');

    // Create the dump directory when it does not yet exist
    if (! is_dir($formidableNotificationAuditDumpDir)) {
        mkdir(
            $formidableNotificationAuditDumpDir,
            0755,
            true
        );
    }
});

/**
 * Normalize a Statamic data structure to a plain array for dumping.
 *
 * @param  mixed  $data  Array, Collection, or arrayable object to normalize.
 * @return array<array-key, mixed> Plain-array representation of the given data.
 */
function formidableNotificationAuditDataToArray(mixed $data): array
{
    // Return the value untouched when it is already a plain array
    if (\is_array($data)) {
        return $data;
    }

    // Prefer all() on Statamic/Illuminate collections
    if (\is_object($data) && method_exists($data, 'all')) {
        /** @var array<array-key, mixed> $all */
        $all = $data->all();

        return $all;
    }

    // Fall back to toArray() on arrayable objects
    if (\is_object($data) && method_exists($data, 'toArray')) {
        /** @var array<array-key, mixed> $arrayable */
        $arrayable = $data->toArray();

        return $arrayable;
    }

    // Cast any remaining scalar/object to an array as a last resort
    return (array) $data;
}

/**
 * Parse a Formidable WXR export into [form-id => form-data] including views.
 *
 * Mirrors Prompts\Loaders\XmlFileLoader for <form>/<field> and additionally
 * attaches the <view> (frm_form_actions) siblings grouped by <menu_order>,
 * which the production loader omits.
 *
 * @param  string  $path  Absolute path to the XML export.
 * @return array<string, array<string, mixed>> Map of form id to form-data arrays.
 */
function formidableNotificationAuditParseExport(string $path): array
{
    // Parse the export, failing loudly on malformed XML
    $xml = simplexml_load_string((string) file_get_contents($path));
    Assert::assertInstanceOf(
        SimpleXMLElement::class,
        $xml,
        "Invalid XML: {$path}"
    );

    // Accumulate forms keyed by id so views can attach by menu_order
    $forms = [];

    // Build each form + its fields (faithful to XmlFileLoader::convertXmlToArray)
    foreach ($xml->form as $form) {
        // Capture the form-level attributes the mapper reads
        $id = (string) $form->id;
        $formData = [
            'id' => (int) $form->id,
            'form_key' => (string) $form->form_key,
            'name' => (string) $form->name,
            'description' => (string) $form->description,
            'created_at' => (string) $form->created_at,
            'logged_in' => (int) $form->logged_in,
            'is_template' => (int) $form->is_template,
            'editable' => (int) $form->editable,
            'options' => (string) $form->options,
            'status' => (string) $form->status,
            'parent_form_id' => (int) $form->parent_form_id,
            'fields' => [],
            'views' => [],
        ];

        // Append each field with the same keys the loader produces
        foreach ($form->field as $field) {
            $formData['fields'][] = [
                'id' => (int) $field->id,
                'field_key' => (string) $field->field_key,
                'name' => (string) $field->name,
                'description' => (string) $field->description,
                'type' => (string) $field->type,
                'default_value' => (string) $field->default_value,
                'field_order' => (int) $field->field_order,
                'form_id' => (int) $field->form_id,
                'required' => (int) $field->required,
                'options' => (string) $field->options,
                'field_options' => (string) $field->field_options,
            ];
        }

        // Register the form for later view attachment
        $forms[$id] = $formData;
    }

    // Attach views (frm_form_actions) to their parent form via <menu_order>
    foreach ($xml->view as $view) {
        // Skip any non-action view defensively
        if ((string) $view->post_type !== 'frm_form_actions') {
            continue;
        }

        // Capture every child node generically so the mapper sees all keys
        $viewData = [];
        foreach ($view->children() as $child) {
            $viewData[$child->getName()] = (string) $child;
        }

        // Attach the view to its parent form when that form exists
        $formId = (string) $view->menu_order;
        if (isset($forms[$formId])) {
            $forms[$formId]['views'][] = $viewData;
        }
    }

    return $forms;
}

/**
 * Count the email and confirmation action views on a parsed form.
 *
 * @param  list<array<string, mixed>>  $views  The form's parsed view array.
 * @return array{email: int, confirmation: int} View counts by action type.
 */
function formidableNotificationAuditCountActionViews(array $views): array
{
    // Initialize the per-type counters
    $counts = ['email' => 0, 'confirmation' => 0];

    // Tally each action view by its excerpt (action type)
    foreach ($views as $view) {
        $rawExcerpt = $view['excerpt'] ?? '';
        $excerpt = \trim(\is_string($rawExcerpt) ? $rawExcerpt : '');
        if ($excerpt === 'email') {
            $counts['email']++;
        } elseif ($excerpt === 'on_submit') {
            $counts['confirmation']++;
        }
    }

    return $counts;
}

/**
 * List every Formidable XML export in a fixtures directory.
 *
 * A configured directory that exists but holds no exports - the state a
 * mistyped FRM_AUDIT_FIXTURES_DIR produces - used to fall through to a hard
 * assertNotEmpty() and red the run. This is a dump test, not a regression test,
 * so an empty corpus is a reason to skip the audit, never to fail it. Same
 * defect class as the Gravity Forms sample guard (uams-statamic-archive#1724).
 *
 * @param  string  $fixturesDir  Absolute path to the configured fixtures directory.
 * @return list<string> Absolute paths to every *.xml export found, possibly empty.
 */
function formidableNotificationAuditExportPaths(string $fixturesDir): array
{
    // Glob the directory for exports, tolerating glob()'s false return
    $files = glob(rtrim($fixturesDir, '/\\').'/*.xml');

    // Hand back a plain list so the caller can test it for emptiness
    return \is_array($files) ? $files : [];
}

it('dumps all form imports for audit', function () use (&$formidableNotificationAuditFixturesDir, &$formidableNotificationAuditDumpDir): void {
    // Skip when the Formidable fixtures are not configured/available (e.g. in CI)
    if (! is_dir($formidableNotificationAuditFixturesDir)) {
        Assert::markTestSkipped("Formidable fixtures unavailable; set FRM_AUDIT_FIXTURES_DIR. Looked in: \"{$formidableNotificationAuditFixturesDir}\"");
    }

    // Collect every XML export in the fixtures directory
    $files = formidableNotificationAuditExportPaths($formidableNotificationAuditFixturesDir);

    // Skip when the configured corpus carries no exports for this audit to walk
    if ($files === []) {
        Assert::markTestSkipped("Formidable audit corpus at \"{$formidableNotificationAuditFixturesDir}\" holds no *.xml exports; skipping the audit dump.");
    }

    // Build the configuration manager and asset importer under test
    $manager = new FormConfigurationManager;
    $assetImporter = new AssetImporter;
    $siteHandle = 'audit';

    // Accumulate a per-form summary for the trailing assertions and summary file
    $summary = [];

    // Walk each export file
    foreach ($files as $path) {
        // Derive a readable site label and parse the forms + views
        $site = basename($path, '.xml');
        $forms = formidableNotificationAuditParseExport($path);

        // Process each form that carries notification/confirmation actions
        foreach ($forms as $formId => $formData) {
            // Narrow the parsed views to the shape the counter expects
            $views = $formData['views'];
            /** @var list<array<string, mixed>> $views */

            // Count the email/confirmation action views for this form
            $actionCounts = formidableNotificationAuditCountActionViews($views);
            $emailViews = $actionCounts['email'];
            $confirmViews = $actionCounts['confirmation'];

            // Skip forms with no notification/confirmation actions
            if ($emailViews === 0 && $confirmViews === 0) {
                continue;
            }

            // Derive a unique, recognizable audit handle for this form
            $rawName = $formData['name'];
            $name = \is_string($rawName) ? $rawName : '';
            $title = $name === '' ? 'untitled' : $name;
            $titleSlug = Str::slug($title);
            $handle = \sprintf(
                'frm_audit_%s_%s_%s',
                Str::slug(str_replace('formidable-forms-export_', '', $site)),
                $formId,
                $titleSlug === '' ? 'x' : $titleSlug
            );

            // Create and persist a throwaway Statamic form for this sample
            $form = FormFacade::make($handle);
            \assert($form instanceof Form);
            $form->title("AUDIT - {$site} - {$title}");
            $form->save();

            // Capture the form data before configuration to diff added keys later
            $beforeData = formidableNotificationAuditDataToArray($form->data());

            // Run the email-settings configuration, capturing crashes as audit data
            $crash = null;
            $result = false;
            try {
                $result = $manager->configureFormEmailSettings(
                    $form,
                    $formData,
                    $siteHandle,
                    $assetImporter
                );
            } catch (Throwable $e) {
                // Record the failure message + location instead of aborting the run
                $crash = \sprintf(
                    '%s @ %s:%d',
                    $e->getMessage(),
                    basename($e->getFile()),
                    $e->getLine()
                );
            }

            // Capture the in-memory form data after configuration
            $afterData = formidableNotificationAuditDataToArray($form->data());

            // Ground truth: parse the YAML the form actually persisted to disk
            /** @var array<string, mixed> $persisted */
            $persisted = [];
            $persistedPath = $form->path();
            if (is_file($persistedPath)) {
                // Form YAML is always a mapping, so the parse result is an array
                $parsedYaml = Yaml::parse((string) file_get_contents($persistedPath));

                /** @var array<string, mixed> $persisted */
                $persisted = \is_array($parsedYaml) ? $parsedYaml : [];
            }

            // Normalize the resulting email configuration to a plain array
            $emailConfigs = $form->email();
            if (\is_object($emailConfigs) && method_exists($emailConfigs, 'all')) {
                $emailConfigs = $emailConfigs->all();
            }
            if (\is_object($emailConfigs) && method_exists($emailConfigs, 'toArray')) {
                $emailConfigs = $emailConfigs->toArray();
            }

            // Assemble the full audit payload for this form
            $dump = [
                'meta' => [
                    'site' => $site,
                    'form_id' => $formData['id'],
                    'form_title' => $title,
                    'email_view_count_input' => $emailViews,
                    'confirmation_view_count_input' => $confirmViews,
                    'configure_return' => $result,
                    'crash' => $crash,
                    'form_data_keys_added' => array_values(
                        array_diff(
                            array_keys($afterData),
                            array_keys($beforeData)
                        )
                    ),
                    'email_config_count_output' => \is_array($emailConfigs) ? \count($emailConfigs) : 0,
                ],
                'input_views' => $formData['views'],
                'persisted_email' => $persisted['email'] ?? null,
                'persisted_confirmation_default' => $persisted['confirmation_settings_default'] ?? null,
                'persisted_confirmation_additional' => $persisted['confirmation_additional'] ?? null,
                'output_email_configs' => $emailConfigs,
                'output_confirmation_default' => $afterData['confirmation_settings_default'] ?? null,
                'output_confirmation_additional' => $afterData['confirmation_additional'] ?? null,
                'output_form_data_full' => $afterData,
            ];

            // Write the per-form YAML dump for inspection
            $outFile = "{$formidableNotificationAuditDumpDir}/{$handle}.yaml";
            file_put_contents(
                $outFile,
                Yaml::dump($dump, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK)
            );

            // Record the summary row for this form
            $summary[$handle] = [
                'site' => $site,
                'form_id' => $formData['id'],
                'title' => $title,
                'email_in' => $emailViews,
                'email_out' => $dump['meta']['email_config_count_output'],
                'confirm_in' => $confirmViews,
                'ok' => $result,
                'crash' => $crash,
            ];

            // Delete the throwaway form so the audit leaves no residual entries
            $existing = FormFacade::find($handle);
            if ($existing instanceof Form) {
                $existing->delete();
            }
        }
    }

    // Write the roll-up summary across all forms
    file_put_contents(
        "{$formidableNotificationAuditDumpDir}/_summary.yaml",
        Yaml::dump(['count' => \count($summary), 'forms' => $summary], 4, 2)
    );

    // Assert at least one form with actions was processed
    Assert::assertNotEmpty($summary, 'No forms with actions were processed.');

    // Every email view should produce exactly one output email config, except
    // for forms whose mapping crashed (recorded separately for the audit).
    foreach ($summary as $handle => $row) {
        // Skip count reconciliation for forms the mapper failed to process
        if ($row['crash'] !== null) {
            continue;
        }

        // Confirm no email views were silently lost during mapping
        Assert::assertSame(
            $row['email_in'],
            $row['email_out'],
            "Form {$handle}: email view count != output email config count"
        );
    }
});

it('finds no exports when the fixtures directory holds a different corpus', function (): void {
    // Stand up an existing-but-empty corpus - the state a mistyped
    // FRM_AUDIT_FIXTURES_DIR produces, where the directory resolves but carries
    // no exports for the audit to walk.
    $unrelatedCorpus = sys_get_temp_dir().'/frm-audit-unrelated-corpus-'.getmypid();
    if (! is_dir($unrelatedCorpus)) {
        mkdir($unrelatedCorpus, 0755, true);
    }

    try {
        // No exports there, so the audit skips rather than failing its glob assertion
        expect(formidableNotificationAuditExportPaths($unrelatedCorpus))->toBe([]);

        // Negative control: the committed synthetic corpus still yields exports,
        // so the default path keeps every bit of its coverage. Named explicitly
        // rather than read from config, so a developer's FRM_AUDIT_FIXTURES_DIR
        // override cannot empty this assertion out.
        $committed = formidableNotificationAuditExportPaths(\dirname(__DIR__).'/Fixtures/form-audit/formidable');
        expect(\count($committed))->toBeGreaterThan(0);
    } finally {
        // Remove the throwaway corpus directory
        if (is_dir($unrelatedCorpus)) {
            rmdir($unrelatedCorpus);
        }
    }
});
