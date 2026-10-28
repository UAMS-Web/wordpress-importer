<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Statamic\Facades\Form as FormFacade;
use Statamic\Forms\Form;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FormConfigurationManager;

// Shared formAuditGravityFormsFixturesDir() resolver — the committed corpus by
// default, overridable host-side. See that file's header for the full contract.
require_once __DIR__.'/../Support/form-audit-test-support.php';

uses(TestCase::class)->group('serial');

/**
 * One-shot dump test for the Gravity Forms notification import audit (issue wordpress-importer-archive#25).
 *
 * Loads the committed Gravity Forms export fixtures, runs each through
 * FormConfigurationManager::configureFormEmailSettings(), and dumps the resulting
 * Statamic Form configuration to storage/app/audit/ for inspection.
 *
 * This is NOT a regression test - the assertions only confirm a dump was
 * produced per sample and that each form's notification input count matches its
 * email-config output count. The audit value is in inspecting the YAML output.
 *
 * Minimal synthetic fixtures are committed under
 * tests/Fixtures/form-audit/gravity-forms/ and used by default, so the audit
 * runs wherever this package is installed and needs no host-app wiring. The
 * host may repoint it through config testing.gf_audit_fixtures_dir (backed by
 * GF_AUDIT_FIXTURES_DIR) at any other directory carrying the
 * GF_NOTIFICATION_AUDIT_SAMPLES filenames; a directory that does not carry them
 * skips the audit rather than failing it.
 *
 * The override is NOT a way to audit the full wordpress-importer exports
 * corpus. Every file there is named gravityforms-export_<site>.json, so no
 * sample name resolves and the audit skips in full - the samples below are the
 * unit of this audit, not the directory (uams-statamic-archive#1724).
 *
 * Host-bound for the FIXTURE CORPUS, not for the host app — the reason was corrected
 * in wordpress-importer-archive#844. It previously read "it drives real Statamic forms through the booted host
 * app"; measured under `StatamicTestCase` with the corpus present, both tests pass in
 * the minimal band (2 passed, 9 assertions), so the booted app is not what binds it.
 * What does: the audit `markTestSkipped`s when its fixtures directory is absent, and
 * the corpus is a separate private repository, configured on demand. In the
 * standalone gate that yields a test which silently skips on a fresh slot and runs on a
 * provisioned one — coverage that reads as present and is not. It stays in tests/Feature
 * on that basis (uams-statamic-archive#1725). It creates and deletes
 * throwaway Statamic forms, so it stays in the host suite's `serial` group.
 *
 * @command  php artisan test --compact vendor/uams-web/wordpress-importer/tests/Feature/GravityFormsNotificationAuditTest.php
 */

/**
 * Representative forms covering the audit complexity buckets.
 *
 * Each tuple is [bucket-label, fixture-file, form-index].
 *
 * @var array<int, array{0: string, 1: string, 2: string}>
 */
const GF_NOTIFICATION_AUDIT_SAMPLES = [
    ['01-simple-contact',     'simple-contact.json',     '0'],
    ['02-multi-notification', 'multi-notification.json', '0'],
];

/**
 * Absolute path to the directory holding the Gravity Forms JSON export fixtures.
 *
 * Resolved by formAuditGravityFormsFixturesDir(): the host-side
 * testing.gf_audit_fixtures_dir override when set, else the committed corpus.
 *
 * @var string Filesystem path to the source Gravity Forms exports.
 */
$gfNotificationAuditFixturesDir = '';

/**
 * Absolute path where the per-bucket YAML audit dumps are written.
 *
 * @var string Filesystem path to the audit output directory.
 */
$gfNotificationAuditDumpDir = '';

/**
 * Normalize a Statamic data structure to a plain array for dumping.
 *
 * @param  mixed  $data  Array, Collection, or arrayable object to normalize.
 * @return array<array-key, mixed> Plain-array representation of the given data.
 */
function gfNotificationAuditDataToArray(mixed $data): array
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
        /** @var array<array-key, mixed> $asArray */
        $asArray = $data->toArray();

        return $asArray;
    }

    // Cast any remaining scalar/object to an array as a last resort
    return (array) $data;
}

/**
 * Extract one form payload from a decoded Gravity Forms export.
 *
 * Reading the offset here rather than inline keeps the index a plain string
 * parameter instead of the sample list's literal, which is what lets both this
 * repo's and the importer package's static analysis see the access as total.
 *
 * @param  array<array-key, mixed>  $exportData  Decoded export, keyed by form index.
 * @param  string  $formIndex  Index of the form to extract.
 * @return array<array-key, mixed> The form payload, or an empty array when absent.
 */
function gfNotificationAuditFormPayload(array $exportData, string $formIndex): array
{
    // Read the requested form payload out of the decoded export
    $formData = $exportData[$formIndex] ?? null;

    // Hand back an empty payload when the index holds anything but an array
    return \is_array($formData) ? $formData : [];
}

/**
 * Resolve a sample fixture path, or null when the corpus does not carry it.
 *
 * The directory-level guard in the audit below only covers "no directory at
 * all". A configured directory that exists but holds a different corpus - the
 * state the GF_AUDIT_FIXTURES_DIR override produces against the real exports -
 * used to fall through to a hard assertion and red the suite. This is a dump
 * test, not a regression test, so an unresolvable sample is a reason to skip
 * the audit, never to fail it (#1724).
 *
 * @param  string  $fixturesDir  Absolute path to the configured fixtures directory.
 * @param  string  $fixture  File name of the sample to resolve within it.
 * @return string|null Absolute path to the sample, or null when it is absent.
 */
function gfNotificationAuditResolveSamplePath(string $fixturesDir, string $fixture): ?string
{
    // Build the absolute path to the requested sample
    $fixturePath = "{$fixturesDir}/{$fixture}";

    // Report the sample as unresolvable when the corpus does not carry it
    return is_file($fixturePath) ? $fixturePath : null;
}

/**
 * Resolve the fixture and dump paths and ensure the dump directory exists.
 */
beforeEach(function () use (&$gfNotificationAuditFixturesDir, &$gfNotificationAuditDumpDir) {
    // Resolve the Gravity Forms fixtures directory (committed corpus by default)
    $gfNotificationAuditFixturesDir = formAuditGravityFormsFixturesDir();

    // Resolve the audit output directory under storage/app
    $gfNotificationAuditDumpDir = storage_path('app/audit/issue-25-notification-import');

    // Create the dump directory when it does not yet exist
    if (! is_dir($gfNotificationAuditDumpDir)) {
        mkdir(
            $gfNotificationAuditDumpDir,
            0755,
            true
        );
    }
});

it('dumps each form import for audit', function () use (&$gfNotificationAuditFixturesDir, &$gfNotificationAuditDumpDir) {
    // Skip when the Gravity Forms fixtures are not configured/available (e.g. in CI)
    if (! is_dir($gfNotificationAuditFixturesDir)) {
        Assert::markTestSkipped("Gravity Forms fixtures unavailable; set GF_AUDIT_FIXTURES_DIR. Looked in: \"{$gfNotificationAuditFixturesDir}\"");
    }

    // Build the configuration manager and asset importer under test
    $manager = new FormConfigurationManager;
    $assetImporter = new AssetImporter;
    $siteHandle = 'audit';

    // Accumulate a per-bucket summary for the trailing assertions and summary file
    $summary = [];

    // Walk each representative sample bucket
    foreach (GF_NOTIFICATION_AUDIT_SAMPLES as [$bucket, $fixture, $formIndex]) {
        // Resolve the fixture file path against the configured corpus
        $fixturePath = gfNotificationAuditResolveSamplePath($gfNotificationAuditFixturesDir, $fixture);

        // Skip the audit when the configured corpus does not carry this sample
        if ($fixturePath === null) {
            Assert::markTestSkipped("Gravity Forms audit sample \"{$fixture}\" is not present in \"{$gfNotificationAuditFixturesDir}\"; skipping the audit dump.");
        }

        // Decode the Gravity Forms export, failing loudly on malformed JSON
        /** @var array<string, mixed> $exportData */
        $exportData = json_decode(
            File::get($fixturePath),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        Assert::assertArrayHasKey($formIndex, $exportData, "Form index {$formIndex} missing in {$fixture}");

        // Extract the target form payload and derive a unique audit handle
        $formData = gfNotificationAuditFormPayload($exportData, $formIndex);
        $title = $formData['title'] ?? 'untitled';
        $titleString = \is_string($title) ? $title : 'untitled';
        $formId = $formData['id'] ?? 'x';
        $formIdString = \is_scalar($formId) ? (string) $formId : 'x';
        $handle = \sprintf(
            '%s_audit_%s_%s',
            $bucket,
            $formIdString,
            Str::slug($titleString)
        );

        // Create a throwaway Statamic form for this sample
        $form = FormFacade::make($handle);

        // Narrow the contract return to the concrete form for the fluent API
        \assert($form instanceof Form);

        // Apply a recognizable audit title and persist the form
        $form->title("AUDIT - {$bucket} - {$titleString}");
        $form->save();

        // Capture the form data before configuration to diff added keys later
        $beforeData = gfNotificationAuditDataToArray($form->data());

        // Run the email-settings configuration under audit
        $result = $manager->configureFormEmailSettings(
            $form,
            $formData,
            $siteHandle,
            $assetImporter
        );

        // Capture the form data after configuration
        $afterData = gfNotificationAuditDataToArray($form->data());

        // Normalize the resulting email configuration to a plain array
        $emailConfigs = $form->email();
        if (\is_object($emailConfigs) && method_exists($emailConfigs, 'all')) {
            $emailConfigs = $emailConfigs->all();
        }
        if (\is_object($emailConfigs) && method_exists($emailConfigs, 'toArray')) {
            $emailConfigs = $emailConfigs->toArray();
        }

        // Assemble the full audit payload for this bucket
        $dump = [
            'meta' => [
                'bucket' => $bucket,
                'fixture' => $fixture,
                'form_index' => $formIndex,
                'form_id' => $formData['id'] ?? null,
                'form_title' => $title,
                'notification_count_input' => \is_array($formData['notifications'] ?? null)
                    ? \count($formData['notifications'])
                    : 0,
                'confirmation_count_input' => \is_array($formData['confirmations'] ?? null)
                    ? \count($formData['confirmations'])
                    : 0,
                'configure_return' => $result,
                'form_data_keys_added' => array_values(
                    array_diff(
                        array_keys($afterData),
                        array_keys($beforeData)
                    )
                ),
                'email_config_count_output' => \is_array($emailConfigs) ? \count($emailConfigs) : 0,
            ],
            'input_notifications' => array_values(
                \is_array($formData['notifications'] ?? null) ? $formData['notifications'] : []
            ),
            'output_email_configs' => $emailConfigs,
            'output_confirmation_default' => $afterData['confirmation_settings_default'] ?? null,
            'output_confirmation_additional' => $afterData['confirmation_additional'] ?? null,
            'output_form_data_full' => $afterData,
        ];

        // Write the per-bucket YAML dump for inspection
        $outFile = $gfNotificationAuditDumpDir.'/'.$bucket.'.yaml';
        file_put_contents(
            $outFile,
            Yaml::dump($dump, 8, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK)
        );

        // Record the summary row for this bucket
        $summary[$bucket] = [
            'in' => $dump['meta']['notification_count_input'],
            'out' => $dump['meta']['email_config_count_output'],
            'ok' => $result,
            'file' => basename($outFile),
        ];

        // Delete the throwaway form so the audit leaves no residual entries
        $existing = FormFacade::find($handle);
        if ($existing instanceof Form) {
            $existing->delete();
        }
    }

    // Write the roll-up summary across all buckets
    file_put_contents(
        $gfNotificationAuditDumpDir.'/_summary.yaml',
        Yaml::dump(['samples' => $summary], 4, 2)
    );

    // Assert every sample produced a summary row
    expect($summary)->toHaveCount(\count(GF_NOTIFICATION_AUDIT_SAMPLES), 'Every sample form processed');

    // Assert input notification counts match output email-config counts per bucket
    foreach ($summary as $bucket => $row) {
        expect($row['out'])->toBe($row['in'], "Bucket {$bucket}: input notif count != output email config count");
    }
});

it('resolves no sample path when the fixtures directory holds a different corpus', function () {
    // Stand up an existing-but-unrelated corpus - the exact state pointing
    // GF_AUDIT_FIXTURES_DIR at the real exports produces, where the directory
    // resolves but none of the sample filenames do.
    $unrelatedCorpus = sys_get_temp_dir().'/gf-audit-unrelated-corpus-'.getmypid();
    if (! is_dir($unrelatedCorpus)) {
        mkdir($unrelatedCorpus, 0755, true);
    }

    try {
        // Every sample is unresolvable there, so the audit skips rather than
        // failing its first hard fixture assertion.
        foreach (GF_NOTIFICATION_AUDIT_SAMPLES as [, $fixture]) {
            expect(gfNotificationAuditResolveSamplePath($unrelatedCorpus, $fixture))->toBeNull();
        }

        // Negative control: the committed synthetic corpus still resolves every
        // sample, so the default path keeps every bit of its coverage. Named
        // explicitly rather than read from config, so a developer's
        // GF_AUDIT_FIXTURES_DIR override cannot skip this assertion away.
        foreach (GF_NOTIFICATION_AUDIT_SAMPLES as [, $fixture]) {
            expect(gfNotificationAuditResolveSamplePath(\dirname(__DIR__).'/Fixtures/form-audit/gravity-forms', $fixture))->toBeFile();
        }
    } finally {
        // Remove the throwaway corpus directory
        if (is_dir($unrelatedCorpus)) {
            rmdir($unrelatedCorpus);
        }
    }
});
