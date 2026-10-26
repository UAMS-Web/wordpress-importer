<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

/**
 * Source selection on the import command (issue wordpress-importer-archive#587).
 *
 * The command gained `--source=wxr|api`. Both paths converge on `runImportForParts()` with a
 * list of real file paths, which is why no importer subsystem downstream had to change.
 *
 * **These are host-app-bound, and the reason is worth recording.** They look like they should
 * be `Integration` tests — every one exits before a single entry is written, so none of them
 * needs real content. But the command cannot be *constructed* without the host app: resolving
 * it builds `ContentProcessor`, whose constructor instantiates `App\Services\BardContentSanitizer`,
 * which lives in `uams-statamic`. Attempted in `tests/Integration/` they fail at resolution
 * with `Class "App\Services\BardContentSanitizer" not found`, before any assertion runs.
 *
 * So this file does not run in package CI (`pest --testsuite=Unit,Integration`). Run it with
 * the `host-app-tests` skill. See `docs/DECISIONS.md` for why materialization is the seam, and
 * wordpress-importer-archive#588 for the parity proof.
 *
 * @command  php vendor/bin/pest tests/Integration/ImportSourceSelectionTest.php
 */
uses(TestCase::class);

/**
 * The address prompt, verbatim.
 *
 * Held as a constant because `expectsQuestion()` matches on the question text: a reworded
 * prompt with the constant left alone fails loudly here, where the same drift spread across
 * five string literals would fail five times and read as five unrelated breakages.
 */
const ADDRESS_PROMPT = 'Which network? Paste its WordPress address, including https://';

/**
 * The key prompt, verbatim. See `ADDRESS_PROMPT`.
 */
const KEY_PROMPT = "Paste that network's migration API key";

/**
 * Narrow `artisan()`'s `PendingCommand|int` union to the pending command.
 *
 * `artisan()` returns an exit code directly when the command has already been run, and a
 * `PendingCommand` otherwise. Every call here expects the latter; raising on the former
 * keeps a mis-set expectation from reading as a passing assertion.
 */
function pendingImport(PendingCommand|int $command): PendingCommand
{
    if (! $command instanceof PendingCommand) {
        throw new RuntimeException('artisan() returned an exit code rather than a pending command.');
    }

    return $command;
}

it('rejects a source it does not recognise', function (): void {
    pendingImport($this->artisan('statamic:import:wordpress', ['--source' => 'ftp']))
        ->expectsOutputToContain("Unknown --source 'ftp'. Use 'wxr' or 'api'.")
        ->assertExitCode(1);
});

it('reports a migration API failure as itself, not as a parse error', function (): void {
    // A malformed address is a setup error. Reporting it as "failed to parse XML file"
    // would send an operator to look at an export that was never involved.
    pendingImport($this->artisan('statamic:import:wordpress', ['--source' => 'api']))
        ->expectsQuestion(ADDRESS_PROMPT, 'example.test')
        ->expectsQuestion(KEY_PROMPT, 'shhh')
        ->expectsOutputToContain('Could not read the migration API')
        ->assertExitCode(1);
});

it('names the network before asking for the key', function (): void {
    // The echoed address is the only thing in the run that identifies the network, and it
    // has to come before the key so an operator can stop rather than paste against the
    // wrong one. `--site` cannot serve that purpose: a blog id is unique only within its
    // own network, so the same id names a different site on each (wordpress-importer-archive#982).
    Http::fake(['*' => Http::response('gateway down', 502)]);

    pendingImport($this->artisan('statamic:import:wordpress', ['--source' => 'api']))
        ->expectsQuestion(ADDRESS_PROMPT, 'https://example.test')
        ->expectsOutputToContain('Reading: https://example.test')
        ->expectsQuestion(KEY_PROMPT, 'shhh')
        ->assertExitCode(1);
});

it('refuses a cleartext address rather than putting the key on the wire', function (): void {
    Http::fake(['*' => Http::response([], 200)]);

    pendingImport($this->artisan('statamic:import:wordpress', ['--source' => 'api']))
        ->expectsQuestion(ADDRESS_PROMPT, 'http://example.test')
        ->expectsQuestion(KEY_PROMPT, 'shhh')
        ->expectsOutputToContain('in cleartext')
        ->assertExitCode(1);

    // Refused before anything left the process, which is the whole point of checking the
    // scheme at the start of the run rather than at request time.
    Http::assertNothingSent();
});

it('still requires a file path when the source is a local export', function (): void {
    // The file argument became optional so `--source=api` could omit it; a WXR run must
    // still refuse to proceed without one rather than importing nothing.
    pendingImport($this->artisan('statamic:import:wordpress'))
        ->expectsOutputToContain('A valid XML file path is required.')
        ->assertExitCode(1);
});

it('defaults to the local export source when none is given', function (): void {
    // No `--source`, a path that does not exist: the WXR branch must be the one that runs.
    pendingImport($this->artisan('statamic:import:wordpress', ['file' => '/nonexistent/export.xml']))
        ->expectsOutputToContain("The file '/nonexistent/export.xml' does not exist.")
        ->assertExitCode(1);
});

it('surfaces an unreachable API rather than importing nothing', function (): void {
    Http::fake(['*' => Http::response('gateway down', 502)]);

    pendingImport($this->artisan('statamic:import:wordpress', ['--source' => 'api']))
        ->expectsQuestion(ADDRESS_PROMPT, 'https://example.test')
        ->expectsQuestion(KEY_PROMPT, 'shhh')
        ->expectsOutputToContain('Could not read the migration API')
        ->assertExitCode(1);
});

it('refuses a --site that is not a whole number', function (string $value): void {
    // wordpress-importer-archive#680: these were silently discarded, so the run imported the API's default site and
    // reported success. `uams-health` is an operator reaching for the network slug; `3a`
    // and `+2` are typos; `3.5` and `1e3` are the cases `is_numeric` waves through and
    // `(int)` quietly reshapes into a site id nobody asked for.
    config(['wordpress-import.migration_api' => [
        'base_url' => 'https://example.test',
        'secret' => 'shhh',
    ]]);

    pendingImport($this->artisan('statamic:import:wordpress', [
        '--source' => 'api',
        '--site' => $value,
    ]))
        ->expectsOutputToContain('The --site option must be a positive whole number')
        ->assertExitCode(1);
})->with(['uams-health', '3a', '3.5', '1e3', '+2', '0', '-1']);

it('refuses the bad site before contacting the API at all', function (): void {
    config(['wordpress-import.migration_api' => [
        'base_url' => 'https://example.test',
        'secret' => 'shhh',
    ]]);

    Http::fake(['*' => Http::response([], 200)]);

    pendingImport($this->artisan('statamic:import:wordpress', [
        '--source' => 'api',
        '--site' => 'not-a-number',
    ]))->assertExitCode(1);

    // Validation precedes the request rather than following it.
    Http::assertNothingSent();
});

it('still refuses a non-interactive run that has not opted into the stdin route', function (): void {
    // wordpress-importer-archive#1046's guard, and the criterion that names it. Adding an unattended route must not
    // quietly make the terminal-less case succeed by some other path: a run that supplies
    // the credential by neither the prompt nor `--key-from-stdin` has supplied it at all,
    // and reading an unanswered prompt as an empty key would draw the API's `401` and
    // report an operator's setup slip as an authentication failure.
    pendingImport($this->artisan('statamic:import:wordpress', [
        '--source' => 'api',
        '--no-interaction' => true,
    ]))
        ->expectsOutputToContain('asked at the prompt rather than stored')
        ->assertExitCode(1);
});

it('names both flags when it refuses, rather than offering only a dead end', function (): void {
    // The guard previously named `--no-interaction` alone, which is not a remedy for a run
    // that has no terminal to go back to.
    pendingImport($this->artisan('statamic:import:wordpress', [
        '--source' => 'api',
        '--no-interaction' => true,
    ]))
        ->expectsOutputToContain('--key-from-stdin')
        ->assertExitCode(1);
});

it('refuses --key-from-stdin with no network, before reading the stream', function (): void {
    // The pair is refused rather than the key alone. Reading a key that no address can be
    // paired with would spend the operator's credential on a request that was never going
    // to be made -- and refusing first is also what keeps this assertion safe to run, since
    // it never touches `STDIN`.
    pendingImport($this->artisan('statamic:import:wordpress', [
        '--source' => 'api',
        '--key-from-stdin' => true,
        '--no-interaction' => true,
    ]))
        ->expectsOutputToContain('--key-from-stdin needs the network it belongs to')
        ->assertExitCode(1);
});
