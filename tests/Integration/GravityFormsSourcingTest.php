<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi\Client;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Loaders\GravityFormsLoader;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * The form-plugin sourcing seam (wordpress-importer-archive#809).
 *
 * The claim these gate is the epic's whole premise: the migration API serves
 * `GFAPI::get_form()` output, which is *the same native form array the committed export
 * contains*, so the mapping sees identical input from either source. Asserting that the two
 * paths agree is what makes it a claim rather than an assumption.
 *
 * @command  php vendor/bin/pest tests/Integration/GravityFormsSourcingTest.php
 */
uses(StatamicTestCase::class);

/**
 * One real-shaped Gravity Forms definition.
 *
 * @return array<string, mixed>
 */
function gravitySampleForm(): array
{
    return [
        'id' => '12',
        'title' => 'Request an Appointment',
        'description' => 'Tell us how to reach you.',
        'labelPlacement' => 'top_label',
        'fields' => [
            [
                'id' => 1,
                'type' => 'text',
                'label' => 'Full name',
                'isRequired' => true,
            ],
            [
                'id' => 2,
                'type' => 'email',
                'label' => 'Email',
                'isRequired' => true,
            ],
        ],
        'notifications' => [
            '5e8c' => [
                'id' => '5e8c',
                'name' => 'Admin Notification',
                'to' => '{admin_email}',
                'subject' => 'New appointment request',
            ],
        ],
    ];
}

/**
 * A loader with no API source, i.e. the committed-export path.
 */
function fileSourcedLoader(): GravityFormsLoader
{
    return new GravityFormsLoader;
}

/**
 * A command with output attached, which the loader writes its count line to.
 *
 * A container-resolved `Command` has no output style and raises the moment anything calls
 * `info()` — which is a test-harness gap rather than anything about the loader.
 */
function loaderCommand(): Command
{
    $command = new class extends Command
    {
        protected $signature = 'test:loader-host';
    };

    $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));

    return $command;
}

beforeEach(function (): void {
    $this->exportPath = \sys_get_temp_dir()
        .\DIRECTORY_SEPARATOR
        .\uniqid('gf_export_', true).'.json';
});

afterEach(function (): void {
    if (\file_exists($this->exportPath)) {
        \unlink($this->exportPath);
    }
});

it('yields the same form definitions from the API as from the committed export', function (): void {
    $form = gravitySampleForm();

    // The committed export shape: numeric string keys plus a `version` element.
    \file_put_contents(
        $this->exportPath,
        (string) \json_encode(['0' => $form, 'version' => '2.7.4'])
    );

    Http::fake([
        '*/forms/gravity*' => Http::response([$form], 200, ['X-WP-Total' => '1']),
    ]);

    $command = loaderCommand();

    $fromFile = fileSourcedLoader()->load($this->exportPath, $command);

    $apiLoader = fileSourcedLoader();
    $apiLoader->setMigrationApiSource(new Client('https://example.test', 'shhh'));
    $fromApi = $apiLoader->load('', $command);

    expect($fromFile)->not->toBeNull()
        ->and($fromApi)->not->toBeNull();

    // Compare the FORM DEFINITIONS, not the envelopes. The export's `version` element is the
    // envelope the API does not send, and it is read nowhere in `src/` — 0 occurrences of
    // `['version']` against 43 of `['title']` as a control. Unwrapping it at this boundary is
    // the point of the seam, so asserting the raw arrays match would assert the opposite.
    $fileForms = \array_values(\array_filter(
        (array) $fromFile,
        static fn (mixed $entry): bool => \is_array($entry)
    ));

    expect($fromApi)->toBe($fileForms);

    /** @var array<string, mixed> $first */
    $first = ((array) $fromApi)[0];

    expect($first['id'])->toBe('12')
        ->and($first['title'])->toBe('Request an Appointment')
        ->and($first['fields'])->toHaveCount(2)
        ->and($first['notifications'])->toHaveKey('5e8c');
});

it('reads a file when no API source is configured', function (): void {
    \file_put_contents(
        $this->exportPath,
        (string) \json_encode(['0' => gravitySampleForm(), 'version' => '2.7.4'])
    );

    // The control for the arm above: without this, a loader that ALWAYS read the API would
    // satisfy the agreement test and break every run that has not adopted the API.
    Http::fake([
        '*' => Http::response('should not be called', 500),
    ]);

    $loader = fileSourcedLoader();

    expect($loader->hasMigrationApiSource())->toBeFalse();

    $forms = $loader->load($this->exportPath, loaderCommand());

    expect($forms)->not->toBeNull()
        ->and($forms)->toHaveKey('version');

    Http::assertNothingSent();
});

it('returns to the file path when the API source is cleared', function (): void {
    $loader = fileSourcedLoader();

    $loader->setMigrationApiSource(new Client('https://example.test', 'shhh'));
    expect($loader->hasMigrationApiSource())->toBeTrue();

    // The singleton hazard the MinervaKB precedent records: this object outlives the run
    // that configured it, so a second import in one process would inherit the first run's
    // source unless the command clears it on both branches.
    $loader->clearMigrationApiSource();

    expect($loader->hasMigrationApiSource())->toBeFalse();
});

it('keeps only form definitions when the API response carries a scalar alongside them', function (): void {
    // The filter in `loadFromApi()` exists so the returned shape is a list of forms whichever
    // source produced it, rather than depending on the server never sending anything else.
    // Without this arm that filter is unverified: a fake returning a clean list passes just
    // as happily with the filter deleted — measured, the mutant survived until this existed.
    Http::fake([
        '*/forms/gravity*' => Http::response(
            [gravitySampleForm(), '2.7.4', gravitySampleForm()],
            200,
            ['X-WP-Total' => '3']
        ),
    ]);

    $loader = fileSourcedLoader();
    $loader->setMigrationApiSource(new Client('https://example.test', 'shhh'));

    $forms = $loader->load('', loaderCommand());

    expect($forms)->not->toBeNull()
        ->and($forms)->toHaveCount(2)
        // Re-keyed from zero, so a consumer indexing the list does not meet a hole where the
        // scalar was.
        ->and(\array_keys((array) $forms))->toBe([0, 1]);

    /** @var array<string, mixed> $kept */
    $kept = ((array) $forms)[0];

    expect($kept['title'])->toBe('Request an Appointment');
});

it('sends the site id the run was scoped to, so definitions come from that site', function (): void {
    // The defect this pins shipped to the gate: the site was stored and never sent, so a run
    // scoped to site N materialized that site's content and read its form definitions from
    // whatever the addressed root serves by default. Nothing on screen distinguishes the two,
    // and the definitions drive blueprint and field mapping.
    //
    // No earlier test could catch it — every `setMigrationApiSource()` call above passes no
    // site, and the `forms/gravity` fake matches with or without the parameter.
    Http::fake([
        '*/forms/gravity*' => Http::response([gravitySampleForm()], 200, ['X-WP-Total' => '1']),
    ]);

    $loader = fileSourcedLoader();
    $loader->setMigrationApiSource(new Client('https://example.test', 'shhh'), 7);

    $loader->load('', loaderCommand());

    $urls = [];

    Http::assertSent(function (Request $request) use (&$urls): bool {
        $urls[] = $request->url();

        return true;
    });

    expect($urls)->not->toBeEmpty()
        ->and($urls[0])->toContain('site=7');
});

it('sends no site parameter when the run was not scoped to one', function (): void {
    // The control. Without it, a loader that hardcoded any site would satisfy the arm above
    // while breaking every unscoped run — and `site=` on a single-site install is the shape
    // wordpress-importer-archive#671 refuses on two endpoints.
    Http::fake([
        '*/forms/gravity*' => Http::response([gravitySampleForm()], 200, ['X-WP-Total' => '1']),
    ]);

    $loader = fileSourcedLoader();
    $loader->setMigrationApiSource(new Client('https://example.test', 'shhh'));

    $loader->load('', loaderCommand());

    $urls = [];

    Http::assertSent(function (Request $request) use (&$urls): bool {
        $urls[] = $request->url();

        return true;
    });

    expect($urls)->not->toBeEmpty()
        ->and($urls[0])->not->toContain('site=');
});
