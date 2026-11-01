<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\MigrationApi\Client;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\MigrationApi\MigrationApiException;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings\Extractor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings\Loader;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings\Prompter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings\Selector;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings\Validator;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;
use UAMSWeb\Aether\WordPressImporter\Tests\Support\LfBufferedOutput;

/**
 * Sourcing MinervaKB plugin configuration from the options endpoint (issue wordpress-importer-archive#586).
 *
 * `MKB_Options` persists the whole settings set as one `wp_options` row — `minerva-kb-options`,
 * written with `json_encode()` — and that stored JSON is the same shape the hand-made export
 * file carries. So only `Loader` changes; `Validator`, `Extractor`, `Selector` and `Prompter`
 * consume the identical array either way, which the equivalence test below is what actually
 * proves.
 *
 * The hand-made export file is deliberately still present: it is the baseline this file
 * compares against. Retiring it is wordpress-importer-archive#666, and it cannot happen before this comparison exists.
 *
 * Whether a real network's blob survives the endpoint's secret filter is wordpress-importer-archive#665 — not
 * answerable here, which is exactly why the absent-row case must raise rather than return
 * nothing.
 *
 * @command  php vendor/bin/pest tests/Integration/MinervaKbOptionsSourceTest.php
 */
uses(StatamicTestCase::class);

beforeEach(function (): void {
    $this->workingDirectory = \sys_get_temp_dir().\DIRECTORY_SEPARATOR.\uniqid('mkb_', true);
    \mkdir($this->workingDirectory);
});

afterEach(function (): void {
    $entries = \glob($this->workingDirectory.\DIRECTORY_SEPARATOR.'*');

    foreach ($entries === false ? [] : $entries as $entry) {
        \unlink($entry);
    }

    \rmdir($this->workingDirectory);
});

/**
 * A MinervaKB settings set carrying every key `Validator` requires.
 *
 * @return array<string, mixed>
 */
function minervaKbSettings(): array
{
    return [
        'search_title' => 'Search the knowledge base',
        'search_placeholder' => 'What are you looking for?',
        'breadcrumbs_home_label' => 'Home',
        'cpt_label_name' => 'Article',
        'cpt_topic_label_name' => 'Topic',
        'nav_alt_name' => 'Knowledge Base',
        'home_sections_switch' => true,
        'home_sections' => [
            ['title' => 'Popular', 'count' => 5],
        ],
        'knowledge_base_search_query' => 'kb_search',
        'knowledge_base_search_title' => 'Results',
    ];
}

/**
 * Fake the options endpoint, serving the settings as the stored JSON string.
 *
 * @param  array<string, mixed>|null  $settings  The settings to serve, or null to omit the row
 * @param  array<string, int>  $denied  The endpoint's withheld-row counts
 */
function fakeOptionsEndpoint(?array $settings, array $denied = ['by_shape' => 0, 'by_name' => 0]): void
{
    $options = [];

    if ($settings !== null) {
        // Stored as a JSON *string*, not as structure — the endpoint serves the value as
        // the string it is (its D-009), so decoding is the importer's job.
        $options['minerva-kb-options'] = \json_encode($settings);
    }

    $options['blogname'] = 'UAMS News';

    Http::fake([
        '*/options*' => Http::response([
            'options' => $options,
            'acf' => [],
            'denylist' => [],
            'denied' => $denied + ['total' => \array_sum($denied)],
        ], 200),
    ]);
}

/**
 * Fake the options endpoint serving an arbitrary set of `wp_options` rows.
 *
 * The sibling above serves one fixed row and twenty tests depend on that, so this is a
 * second helper rather than a widened signature. It exists for wordpress-importer-archive#1107, where WHICH rows are
 * present is the subject rather than a detail.
 *
 * @param  array<string, array<string, mixed>>  $rows  Settings keyed by `wp_options` row name
 * @param  array<string, int>  $denied  The endpoint's withheld-row counts
 */
function fakeOptionsRows(array $rows, array $denied = ['by_shape' => 0, 'by_name' => 0]): void
{
    $options = [];

    foreach ($rows as $key => $settings) {
        $options[$key] = \json_encode($settings);
    }

    $options['blogname'] = 'UAMS News';

    Http::fake([
        '*/options*' => Http::response([
            'options' => $options,
            'acf' => [],
            'denylist' => [],
            'denied' => $denied + ['total' => \array_sum($denied)],
        ], 200),
    ]);
}

/**
 * The v2 fork's live settings, as that generation stores them.
 *
 * Real JSON booleans, which is how the fork writes them — the original plugin writes the
 * same settings as strings, and that difference is wordpress-importer-archive#1106 rather than this ticket.
 *
 * @return array<string, mixed>
 */
function minervaKbV2Settings(): array
{
    return \array_merge(minervaKbSettings(), [
        'kb_page' => '21',
        'breadcrumbs_home_label' => 'Knowledge Base',
        'search_placeholder' => 'e.g., Guidelines',
        'show_related_articles' => true,
    ]);
}

/**
 * The SAME site's v1-era row: a copy of the above, frozen when v2 took over, since drifted.
 *
 * The identity values still agree and the edited ones do not, which is exactly why reading
 * this row was quiet rather than obviously wrong. Values are the measured ones from
 * `communications.uams.edu/web`.
 *
 * @return array<string, mixed>
 */
function minervaKbDriftedV1Settings(): array
{
    return \array_merge(minervaKbSettings(), [
        'kb_page' => '21',
        'breadcrumbs_home_label' => 'Knowledge Base',
        'search_placeholder' => 'ex.: Guidelines',
        'show_related_articles' => 'false',
    ]);
}

/**
 * Narrow a loader result to a non-null array.
 *
 * Beyond satisfying the type, this stops an equivalence assertion passing on two nulls —
 * which would be green and prove nothing.
 *
 * @param  array<int|string, mixed>|null  $settings  The loader result
 * @return array<int|string, mixed> The settings
 */
function loadedSettings(?array $settings): array
{
    if ($settings === null) {
        throw new RuntimeException('The loader returned no settings where settings were expected.');
    }

    return $settings;
}

/**
 * A loader wired to a fake migration API.
 */
function apiLoader(?int $site = null, ?string $generation = null): Loader
{
    $loader = new Loader;
    $loader->setMigrationApiSource(new Client('https://example.test', 'shhh'), $site, $generation);

    return $loader;
}

it('produces settings identical to the file-sourced path', function (): void {
    // This is the criterion the whole ticket rests on: the four downstream components are
    // untouched precisely because the array they receive is the same one.
    $settings = minervaKbSettings();

    $path = $this->workingDirectory.\DIRECTORY_SEPARATOR.'minerva-kb-settings.json';
    \file_put_contents($path, \json_encode($settings));

    $output = new LfBufferedOutput;

    $fromFile = loadedSettings((new Loader)->loadSettings($path, $output));

    fakeOptionsEndpoint($settings);
    $fromApi = loadedSettings(apiLoader()->loadSettings('/ignored/by/the/api/path.json', $output));

    expect($fromApi)->toBe($fromFile)
        ->and($fromApi)->toBe($settings);
});

it('carries both sources through Validator and Extractor to the same output', function (): void {
    // Equivalence at the Loader boundary is necessary but not sufficient — what matters is
    // that the components downstream of it cannot tell the two apart.
    $settings = minervaKbSettings();

    $path = $this->workingDirectory.\DIRECTORY_SEPARATOR.'minerva-kb-settings.json';
    \file_put_contents($path, \json_encode($settings));

    $output = new LfBufferedOutput;
    $validator = new Validator;
    $extractor = new Extractor;

    $fromFile = loadedSettings((new Loader)->loadSettings($path, $output));

    fakeOptionsEndpoint($settings);
    $fromApi = loadedSettings(apiLoader()->loadSettings('/ignored.json', $output));

    // Both must satisfy the same validation, and extract to byte-identical field values.
    expect($validator->validateSettings($fromFile, $output))->toBeTrue()
        ->and($validator->validateSettings($fromApi, $output))->toBeTrue()
        ->and($extractor->extractKnowledgeBaseSettings($fromApi, 'UAMS Health'))
        ->toBe($extractor->extractKnowledgeBaseSettings($fromFile, 'UAMS Health'))
        // And the extraction is not vacuously empty on both sides.
        ->and($extractor->extractKnowledgeBaseSettings($fromApi, 'UAMS Health'))->not->toBe([]);
});

it('ignores the file path entirely once the API source is set', function (): void {
    // A stale path must not become a silent fallback: if the API is the source, a file
    // sitting at the prompted path is not an answer.
    $decoy = $this->workingDirectory.\DIRECTORY_SEPARATOR.'decoy.json';
    \file_put_contents($decoy, \json_encode(['search_title' => 'FROM THE FILE'] + minervaKbSettings()));

    fakeOptionsEndpoint(minervaKbSettings());

    $settings = apiLoader()->loadSettings($decoy, new LfBufferedOutput);

    expect($settings['search_title'] ?? null)->toBe('Search the knowledge base');
});

it('raises when the options payload carries no MinervaKB row', function (): void {
    // The silent outcome this whole path exists to prevent: no row, no settings, and an
    // import that reports success having configured nothing.
    fakeOptionsEndpoint(null);

    expect(fn () => apiLoader()->loadSettings('/ignored.json', new LfBufferedOutput))
        ->toThrow(
            MigrationApiException::class,
            'served no MinervaKB settings row (tried "uamswp-minerva-kb-options", "minerva-kb-options")'
        );
});

it('names both causes and the withheld counts when the row is missing', function (): void {
    // The endpoint reports how many rows it withheld, never which — so the message has to
    // offer both readings rather than assert one. wordpress-importer-archive#665 is what resolves it.
    fakeOptionsEndpoint(null, ['by_shape' => 3, 'by_name' => 11]);

    expect(fn () => apiLoader()->loadSettings('/ignored.json', new LfBufferedOutput))->toThrow(
        MigrationApiException::class,
        'Either MinervaKB is not installed on this site, it is installed but was never '
        .'configured, or the options endpoint withheld the row (it reports 3 withheld by '
        .'shape and 11 by name). Refusing to import knowledge base content with no '
        .'configuration.'
    );
});

it('raises when the row is present but empty', function (): void {
    Http::fake(['*/options*' => Http::response([
        'options' => ['minerva-kb-options' => ''],
        'denied' => ['by_shape' => 0, 'by_name' => 0],
    ], 200)]);

    expect(fn () => apiLoader()->loadSettings('/ignored.json', new LfBufferedOutput))
        ->toThrow(
            MigrationApiException::class,
            'served no MinervaKB settings row (tried "uamswp-minerva-kb-options", "minerva-kb-options")'
        );
});

it('does not swallow the raise in the loader generic handler', function (): void {
    // `loadSettings()` wraps its work in a catch-all that returns null. Without an explicit
    // re-throw ahead of it, the loud failure above would become a quiet one.
    fakeOptionsEndpoint(null);

    $threw = false;

    try {
        apiLoader()->loadSettings('/ignored.json', new LfBufferedOutput);
    } catch (MigrationApiException) {
        $threw = true;
    }

    expect($threw)->toBeTrue();
});

it('scopes the options read to the requested site', function (): void {
    fakeOptionsEndpoint(minervaKbSettings());

    apiLoader(4)->loadSettings('/ignored.json', new LfBufferedOutput);

    Http::assertSent(fn (Request $request): bool => \str_contains($request->url(), 'site=4'));
});

it('reads the documented options route', function (): void {
    fakeOptionsEndpoint(minervaKbSettings());

    apiLoader()->loadSettings('/ignored.json', new LfBufferedOutput);

    Http::assertSent(fn (Request $request): bool => \str_contains(
        $request->url(),
        '/wp-json/uamswp-migration/v1/options'
    ));
});

it('tells the operator which source the settings came from', function (): void {
    fakeOptionsEndpoint(minervaKbSettings());

    $output = new LfBufferedOutput;
    apiLoader()->loadSettings('/ignored.json', $output);

    expect(\trim($output->fetch()))
        ->toBe('Fetched the MinervaKB settings row "minerva-kb-options" from the migration API.');
});

it('still reads a file when no API source is configured', function (): void {
    // The file path is not retired until wordpress-importer-archive#666, and until then it must keep working exactly
    // as it does today.
    $path = $this->workingDirectory.\DIRECTORY_SEPARATOR.'settings.json';
    \file_put_contents($path, \json_encode(minervaKbSettings()));

    $settings = (new Loader)->loadSettings($path, new LfBufferedOutput);

    expect($settings['cpt_label_name'] ?? null)->toBe('Article');

    // And nothing was requested over HTTP.
    Http::assertNothingSent();
});

it('reports a transport failure rather than returning nothing', function (): void {
    Http::fake(['*' => Http::response('gateway down', 502)]);

    expect(fn () => apiLoader()->loadSettings('/ignored.json', new LfBufferedOutput))
        ->toThrow(MigrationApiException::class, 'HTTP 502');
});

it('reports malformed served JSON against the option key rather than a file path', function (): void {
    Http::fake(['*/options*' => Http::response([
        'options' => ['minerva-kb-options' => '{not valid json'],
        'denied' => ['by_shape' => 0, 'by_name' => 0],
    ], 200)]);

    $log = Log::spy();
    $output = new LfBufferedOutput;

    $settings = apiLoader()->loadSettings('/some/decoy/path.json', $output);

    expect($settings)->toBeNull()
        // The operator sees the console, not the log, so assert both — and assert the whole
        // thing. `LfBufferedOutput` renders through Symfony's formatter, which strips the
        // `<error>` tags, so these are the plain lines an operator actually reads. The fetch
        // notice comes first because the row *was* read; it was the parse that failed.
        // `LfBufferedOutput` writes `PHP_EOL`, so this two-line message is CRLF-separated on
        // Windows and LF-separated elsewhere. What is under test is WHICH lines are printed,
        // not how the console terminates one, so the actual is folded to a single form first.
        ->and(\preg_replace('/\R/', "\n", \trim($output->fetch())))->toBe(
            'Fetched the MinervaKB settings row "minerva-kb-options" from the migration API.'
            ."\nInvalid JSON format: Syntax error"
        );

    // A message naming the decoy path would send an operator to a file that had no part in
    // this run.
    $log->shouldHaveReceived('error', [Mockery::on(function (string $message): bool {
        return \str_contains($message, 'Invalid JSON format in minerva-kb-options: Syntax error')
            && ! \str_contains($message, '/some/decoy/path.json');
    })]);
});

it('reports malformed file JSON against the file path', function (): void {
    // The mirror of the test above: on the file path the label must be the file, or the
    // operator is told to look at a `wp_options` row that had no part in this run.
    $path = $this->workingDirectory.\DIRECTORY_SEPARATOR.'broken.json';
    \file_put_contents($path, '{not valid json');

    $log = Log::spy();
    $output = new LfBufferedOutput;

    expect((new Loader)->loadSettings($path, $output))->toBeNull()
        ->and(\trim($output->fetch()))->toBe('Invalid JSON format: Syntax error');

    $log->shouldHaveReceived('error', [Mockery::on(function (string $message) use ($path): bool {
        return \str_contains($message, $path)
            && ! \str_contains($message, 'minerva-kb-options');
    })]);
});

it('reports a file it cannot read rather than treating it as empty', function (): void {
    // A path that does not exist, rather than a directory: `file_get_contents()` on a
    // directory returns an empty *string* on macOS (verified), which takes the JSON-parse
    // branch instead and would make this assert the wrong thing on one platform.
    $log = Log::spy();

    $missing = $this->workingDirectory.\DIRECTORY_SEPARATOR.'no-such-file.json';
    $output = new LfBufferedOutput;

    $settings = @(new Loader)->loadSettings($missing, $output);

    $console = $output->fetch();

    expect($settings)->toBeNull()
        ->and($console)->toContain("Failed to read file: {$missing}")
        // And it stops there: reporting a JSON error on top would describe a parse that
        // never happened.
        ->and($console)->not->toContain('Invalid JSON format');

    $log->shouldHaveReceived('error', [Mockery::on(
        fn (string $message): bool => \str_contains($message, 'Failed to read file')
    )]);
});

it('returns to the file source when the API source is cleared', function (): void {
    // The loader is a container singleton, so a source set by one run outlives it. Without
    // an explicit reset a later file-sourced run would read the API while its operator was
    // answering a file prompt — and would look like it had worked.
    $path = $this->workingDirectory.\DIRECTORY_SEPARATOR.'settings.json';
    \file_put_contents($path, \json_encode(['search_title' => 'FROM THE FILE'] + minervaKbSettings()));

    fakeOptionsEndpoint(minervaKbSettings());

    $loader = apiLoader();
    $loader->clearMigrationApiSource();

    $settings = loadedSettings($loader->loadSettings($path, new LfBufferedOutput));

    expect($settings['search_title'] ?? null)->toBe('FROM THE FILE');

    Http::assertNothingSent();
});

/**
 * A Selector wired from real components, as the container builds it.
 */
function makeSelector(Loader $loader): Selector
{
    return new Selector(new Prompter, $loader, new Validator, new Extractor);
}

/**
 * A non-interactive console input, standing in for `--no-interaction`.
 */
function scriptedInput(): ArrayInput
{
    $input = new ArrayInput([]);
    $input->setInteractive(false);

    return $input;
}

it('reaches the loader through the selector when the API source is set', function (): void {
    // wordpress-importer-archive#679: this is the test whose absence let the whole wordpress-importer-archive#586 slice ship unreachable.
    // `Selector::select()` prompted for a local file first, and on `--source=api` there is
    // no file — so the only sane answer skipped straight past the loader.
    fakeOptionsEndpoint(minervaKbSettings());

    $loader = apiLoader();
    $output = new LfBufferedOutput;

    $settings = makeSelector($loader)->select(
        scriptedInput(),
        $output,
        new Command
    );

    expect($settings)->not->toBeNull()
        ->and($settings['search_title'] ?? null)->toBe('Search the knowledge base')
        // And it says where they came from, rather than naming a file nobody supplied.
        ->and($output->fetch())->toContain('Successfully loaded MinervaKB settings from the migration API.');
});

it('never prompts for a file when the API source is set', function (): void {
    // The prompt is what produced the skip. A scripted run reaching it would return null.
    fakeOptionsEndpoint(minervaKbSettings());

    $output = new LfBufferedOutput;

    makeSelector(apiLoader())->select(scriptedInput(), $output, new Command);

    expect($output->fetch())->not->toContain('Import / Export')
        ->and($output->fetch())->not->toContain('Skipping MinervaKB settings configuration.');
});

it('raises through the selector when the row is absent, rather than skipping', function (): void {
    // The guard wordpress-importer-archive#586 was built around, exercised end to end rather than by calling the
    // loader directly. Reaching it from here is the whole point.
    fakeOptionsEndpoint(null);

    expect(fn () => makeSelector(apiLoader())->select(
        scriptedInput(),
        new LfBufferedOutput,
        new Command
    ))->toThrow(
        MigrationApiException::class,
        'served no MinervaKB settings row (tried "uamswp-minerva-kb-options", "minerva-kb-options")'
    );
});

it('still prompts for a file when no API source is configured', function (): void {
    // The WXR path is unchanged: a scripted run with no file skips, as it always has.
    $output = new LfBufferedOutput;

    $settings = makeSelector(new Loader)->select(
        scriptedInput(),
        $output,
        new Command
    );

    expect($settings)->toBeNull()
        ->and($output->fetch())->toContain('Skipping MinervaKB settings configuration.');

    Http::assertNothingSent();
});

it('refuses a site that serves both generations\' rows, rather than guessing which is live', function (): void {
    // wordpress-importer-archive#1134. Both rows are this same site's configuration, one of them written by a generation
    // it no longer runs, and nothing the endpoint serves says which. wordpress-importer-archive#1107 picked v2 by name
    // and wordpress-importer-archive#1124 picked by entry-file name; both were guesses, so this stops instead.
    fakeOptionsRows([
        'minerva-kb-options' => minervaKbDriftedV1Settings(),
        'uamswp-minerva-kb-options' => minervaKbV2Settings(),
    ]);

    expect(fn () => apiLoader()->loadSettings('/ignored.json', new LfBufferedOutput))->toThrow(
        MigrationApiException::class,
        'served both MinervaKB settings rows'
    );
});

it('names both rows, the withheld counts and how to recover when it refuses', function (): void {
    // The refusal is the operator's only record of why the import stopped, so it has to say
    // which rows were served, that absence of a third is not evidence, and what fixes it.
    fakeOptionsRows(
        [
            'minerva-kb-options' => minervaKbDriftedV1Settings(),
            'uamswp-minerva-kb-options' => minervaKbV2Settings(),
        ],
        ['by_shape' => 3, 'by_name' => 6]
    );

    try {
        apiLoader()->loadSettings('/ignored.json', new LfBufferedOutput);
        $message = '';
    } catch (MigrationApiException $e) {
        $message = $e->getMessage();
    }

    expect($message)->toContain('("uamswp-minerva-kb-options", "minerva-kb-options")')
        ->and($message)->toContain('3 rows withheld by shape and 6 by name')
        ->and($message)->toContain('wp-admin/network/plugins.php');

    // And the whole sentence, so a recovery instruction that lost or reordered a clause
    // cannot pass on the three fragments above.
    expect($message)->toBe(
        'The migration API served both MinervaKB settings rows ("uamswp-minerva-kb-options", '
        .'"minerva-kb-options"). Each generation of the plugin writes its own, so one of them is '
        .'a leftover of a generation this site no longer runs, and nothing the endpoint serves '
        .'can say which: a plugin\'s entry-file name is not its generation. Read which generation '
        .'is active from the network plugins page (wp-admin/network/plugins.php), then re-run with '
        .'--minerva-generation=v1 or --minerva-generation=v2. '
        .'(The endpoint also reports 3 rows withheld by shape and 6 by name.) Refusing to import '
        .'knowledge base content from a guess.'
    );
});

it('reads the v2 row on a site that carries only that one', function (): void {
    fakeOptionsRows(['uamswp-minerva-kb-options' => minervaKbV2Settings()]);

    $settings = loadedSettings(apiLoader()->loadSettings('/ignored.json', new LfBufferedOutput));

    expect($settings['search_placeholder'])->toBe('e.g., Guidelines');
});

it('still reads the v1 row on a site that carries only that one', function (): void {
    // The regression this fix must not cause. Three of the nine sites measured are v1-only,
    // and a preference implemented as a rename rather than a preference would break them.
    fakeOptionsRows(['minerva-kb-options' => minervaKbDriftedV1Settings()]);

    $output = new LfBufferedOutput;
    $settings = loadedSettings(apiLoader()->loadSettings('/ignored.json', $output));

    expect($settings['search_placeholder'])->toBe('ex.: Guidelines')
        ->and($output->fetch())->toContain('"minerva-kb-options"');
});

it('skips a candidate row that is present but empty rather than stopping on it', function (): void {
    // A row can exist and carry nothing. Stopping at the first name that is merely PRESENT
    // would raise on this site while a perfectly good row sat one name further down.
    Http::fake(['*/options*' => Http::response([
        'options' => [
            'uamswp-minerva-kb-options' => '',
            'minerva-kb-options' => (string) \json_encode(minervaKbDriftedV1Settings()),
        ],
        'denied' => ['by_shape' => 0, 'by_name' => 0],
    ], 200)]);

    $settings = loadedSettings(apiLoader()->loadSettings('/ignored.json', new LfBufferedOutput));

    expect($settings['search_placeholder'])->toBe('ex.: Guidelines');
});

it('raises on a site where the plugin is active but was never configured', function (): void {
    // The case most likely to be missed, and the reason the selection must END in a raise
    // rather than in an empty array. This site has no settings row at all; its committed
    // export holds plugin DEFAULTS rendered by the settings screen, which exist nowhere in
    // the database. "Absent" and "defaults" are not the same thing and must not be conflated
    // into a quiet success.
    fakeOptionsRows([]);

    expect(fn () => apiLoader()->loadSettings('/ignored.json', new LfBufferedOutput))->toThrow(
        MigrationApiException::class,
        'it is installed but was never configured'
    );
});

// --- an orphaned row on a site with no MinervaKB installed (wordpress-importer-archive#1124) -----------

/**
 * Fake the options endpoint with a denylist provenance block.
 *
 * `denylist` is what the endpoint derives from the site's live plugin set — `active_plugins`
 * plus the network's `active_sitewide_plugins`, reduced to directory slugs and diffed against
 * a reviewed baseline. It is the only view of that set the payload carries.
 *
 * @param  array<string, array<string, mixed>>  $rows  Settings keyed by `wp_options` row name
 * @param  list<string>  $added  Slugs running here but not in the reviewed baseline
 * @param  list<string>  $removed  Slugs in the baseline but not running here
 */
function fakeOptionsWithPlugins(array $rows, array $added, array $removed): void
{
    $options = [];

    foreach ($rows as $key => $settings) {
        $options[$key] = \json_encode($settings);
    }

    Http::fake([
        '*/options*' => Http::response([
            'options' => $options,
            'acf' => [],
            'denylist' => [
                'reviewed_against' => 68,
                'current' => 30,
                'stale' => true,
                'added' => $added,
                'removed' => $removed,
            ],
            'denied' => ['by_shape' => 0, 'by_name' => 0, 'total' => 0],
        ], 200),
    ]);
}

it('refuses an orphaned row where no MinervaKB plugin is installed', function (): void {
    // The `wordpress-importer-archive#1124` case measured on two sites: the plugin directory is in `removed` and nowhere
    // in `added`, so nothing of either generation runs here and every row served is a leftover
    // of a plugin that was uninstalled. Importing it applies a configuration the site stopped
    // using, and does so silently.
    fakeOptionsWithPlugins(
        ['uamswp-minerva-kb-options' => minervaKbV2Settings()],
        [],
        ['UAMSWP-minervakb', 'some-other-plugin']
    );

    expect(fn () => apiLoader()->loadSettings('/ignored.json', new LfBufferedOutput))->toThrow(
        MigrationApiException::class,
        'no MinervaKB plugin installed'
    );
});

it('still loads where the plugin directory is running under a second casing', function (): void {
    // The casing hazard, measured on a live site: one plugin, two directory casings, and the
    // endpoint's own `array_diff` is case-sensitive — so the slug appears in `removed` under
    // the baseline's casing AND in `added` under the site's. Matching only `removed` would
    // refuse an import on a site that is running the plugin perfectly well.
    fakeOptionsWithPlugins(
        ['minerva-kb-options' => minervaKbDriftedV1Settings()],
        ['uamswp-minervakb'],
        ['UAMSWP-minervakb']
    );

    $settings = loadedSettings(apiLoader()->loadSettings('/ignored.json', new LfBufferedOutput));

    expect($settings['search_placeholder'])->toBe('ex.: Guidelines');
});

it('does not refuse when the plugin set cannot settle the question', function (): void {
    // The ambiguous case, and the reason the helper reports *not established* rather than
    // *absent*: a site running the plugin from the reviewed baseline appears in neither list.
    // Refusing here would stop every such import — which is most of them.
    fakeOptionsWithPlugins(
        ['uamswp-minerva-kb-options' => minervaKbV2Settings()],
        ['something-unreviewed'],
        ['a-plugin-that-left']
    );

    $settings = loadedSettings(apiLoader()->loadSettings('/ignored.json', new LfBufferedOutput));

    expect($settings['search_placeholder'])->toBe('e.g., Guidelines');
});

it('does not refuse when the payload carries no plugin provenance at all', function (): void {
    // An endpoint that serves no `denylist` block must not start failing imports. Absence of
    // the signal is not evidence for the claim.
    fakeOptionsRows(['uamswp-minerva-kb-options' => minervaKbV2Settings()]);

    $settings = loadedSettings(apiLoader()->loadSettings('/ignored.json', new LfBufferedOutput));

    expect($settings['search_placeholder'])->toBe('e.g., Guidelines');
});

// --- choosing between two rows by the installed generation (wordpress-importer-archive#1124) -----------

/**
 * Fake the options endpoint with settings rows and an `active_plugins` row.
 *
 * `active_plugins` is served as an ordinary `wp_options` row — it matches no denylist
 * pattern — and it is the only served signal that distinguishes the two generations: they
 * share a directory and differ only in their entry file.
 *
 * @param  array<string, array<string, mixed>>  $rows  Settings keyed by `wp_options` row name
 * @param  list<string>|array<int|string, string>  $activePlugins  Plugin paths, as stored
 * @param  array<string, int>  $denied  The endpoint's withheld-row counts
 */
function fakeOptionsWithActivePlugins(
    array $rows,
    array $activePlugins,
    array $denied = ['by_shape' => 0, 'by_name' => 0, 'total' => 0]
): void {
    $options = [];

    foreach ($rows as $key => $settings) {
        $options[$key] = \json_encode($settings);
    }

    $options['active_plugins'] = $activePlugins;

    Http::fake([
        '*/options*' => Http::response([
            'options' => $options,
            'acf' => [],
            'denylist' => ['added' => [], 'removed' => [], 'stale' => true],
            'denied' => $denied,
        ], 200),
    ]);
}

it('refuses where both are served even though active_plugins names the v1 entry file', function (): void {
    // `inside/clinicaleducation`'s shape, and the mirror criterion of wordpress-importer-archive#1134. `minervakb.php`
    // is read as v1 by the same name-derived inference that misread `hr`, so it decides
    // nothing: a site running v2 from that file would otherwise take the v1 row silently.
    fakeOptionsWithActivePlugins(
        [
            'minerva-kb-options' => minervaKbDriftedV1Settings(),
            'uamswp-minerva-kb-options' => minervaKbV2Settings(),
        ],
        ['__UAMSWP-minervakb/minervakb.php', 'some-other/some-other.php']
    );

    expect(fn () => apiLoader()->loadSettings('/ignored.json', new LfBufferedOutput))->toThrow(
        MigrationApiException::class,
        'served both MinervaKB settings rows'
    );
});

it('refuses where both are served even though active_plugins names the v2 entry file', function (): void {
    // `cancer/root`, `communications/creative-services` and `communications/web` (wordpress-importer-archive#1134).
    // This test used to be named for "the v2 plugin is installed", which the fixture never
    // established: it establishes that a file of v2's name is active, and `hr` ran v1 from
    // exactly that file.
    fakeOptionsWithActivePlugins(
        [
            'minerva-kb-options' => minervaKbDriftedV1Settings(),
            'uamswp-minerva-kb-options' => minervaKbV2Settings(),
        ],
        ['UAMSWP-minervakb/uamswp-minervakb.php']
    );

    expect(fn () => apiLoader()->loadSettings('/ignored.json', new LfBufferedOutput))->toThrow(
        MigrationApiException::class,
        'served both MinervaKB settings rows'
    );
});

it('reads active_plugins whether it decodes to a list or to a numeric map', function (): void {
    // Measured: the stored value comes back as a list on some sites and as a map with numeric
    // keys on others. Reading keys rather than values is what made an earlier reading of this
    // same data report no plugin entry at all on three sites that have one. Observed through
    // the v1 refusal, the one place the entry file still acts.
    fakeOptionsWithActivePlugins(
        ['uamswp-minerva-kb-options' => minervaKbV2Settings()],
        [3 => '__UAMSWP-minervakb/minervakb.php', 7 => 'other/other.php']
    );

    expect(fn () => apiLoader()->loadSettings('/ignored.json', new LfBufferedOutput))
        ->toThrow(MigrationApiException::class, 'is written by v2, which is not what runs here');
});

it('refuses where both are served and active_plugins names no generation', function (): void {
    // The name preference no longer decides between two served rows either (wordpress-importer-archive#1134).
    fakeOptionsWithActivePlugins(
        [
            'minerva-kb-options' => minervaKbDriftedV1Settings(),
            'uamswp-minerva-kb-options' => minervaKbV2Settings(),
        ],
        ['unrelated/unrelated.php']
    );

    expect(fn () => apiLoader()->loadSettings('/ignored.json', new LfBufferedOutput))->toThrow(
        MigrationApiException::class,
        'served both MinervaKB settings rows'
    );
});

it('reads the only row served even where the entry file names the other generation', function (): void {
    // The residual, pinned as today's behaviour rather than left to chance. One measured site
    // carries a v2 entry file while serving only the v1 row; letting the entry file decide
    // there would refuse an import that works. wordpress-importer-archive#1125 owns the rule; this pins the selection.
    fakeOptionsWithActivePlugins(
        ['minerva-kb-options' => minervaKbDriftedV1Settings()],
        ['uamswp-minervakb/uamswp-minervakb.php']
    );

    $output = new LfBufferedOutput;
    $settings = loadedSettings(apiLoader()->loadSettings('/ignored.json', $output));

    // The row read is unchanged. The generation IS consulted now — it simply does not get to
    // re-select a row that was never served.
    expect($settings['search_placeholder'])->toBe('ex.: Guidelines')
        ->and($output->fetch())->toContain('Fetched the MinervaKB settings row "minerva-kb-options"');
});

it('says so when the installed generation names a row that was not served', function (): void {
    // The half of wordpress-importer-archive#1125 that does not wait on the rule. Both measured sites sit here, and
    // until now the disagreement passed without a word — the import read the only row there
    // was and the operator had no way to recover afterwards that the signals disagreed.
    fakeOptionsWithActivePlugins(
        ['minerva-kb-options' => minervaKbDriftedV1Settings()],
        ['uamswp-minervakb/uamswp-minervakb.php']
    );

    $output = new LfBufferedOutput;
    apiLoader()->loadSettings('/ignored.json', $output);

    $text = $output->fetch();

    // Names the row the installed generation writes, and the row read instead.
    expect($text)->toContain('uamswp-minerva-kb-options')
        ->and($text)->toContain('"minerva-kb-options"')
        ->and($text)->toContain('not among those the migration API served');
});

it('quotes the withheld counts in the disagreement warning, because absence is not evidence', function (): void {
    // The endpoint reports HOW MANY rows it withheld and never which, so the row the installed
    // generation writes may exist here and have been withheld rather than be absent. A warning
    // that did not carry the counts would invite reading a missing row as a row that is gone.
    fakeOptionsWithActivePlugins(
        ['minerva-kb-options' => minervaKbDriftedV1Settings()],
        ['uamswp-minervakb/uamswp-minervakb.php'],
        ['by_shape' => 4, 'by_name' => 9, 'total' => 13]
    );

    $output = new LfBufferedOutput;
    apiLoader()->loadSettings('/ignored.json', $output);

    expect($output->fetch())->toContain('4 rows withheld by shape and 9 by name');
});

it('does not warn where the installed generation is the row it served', function (): void {
    // The negative control. Without it the three assertions above are satisfied by a warning
    // that fires on every site, which would be noise rather than a signal and would read
    // exactly the same in a passing suite.
    fakeOptionsWithActivePlugins(
        ['minerva-kb-options' => minervaKbDriftedV1Settings()],
        ['UAMSWP-minervakb/minervakb.php']
    );

    $output = new LfBufferedOutput;
    apiLoader()->loadSettings('/ignored.json', $output);

    expect($output->fetch())->not->toContain('not among those the migration API served');
});

it('refuses where both entry files appear at once and both rows are served', function (): void {
    // No measured site has both generations active; with both rows served there is still
    // nothing sound to choose by.
    fakeOptionsWithActivePlugins(
        [
            'minerva-kb-options' => minervaKbDriftedV1Settings(),
            'uamswp-minerva-kb-options' => minervaKbV2Settings(),
        ],
        ['UAMSWP-minervakb/minervakb.php', 'UAMSWP-minervakb/uamswp-minervakb.php']
    );

    expect(fn () => apiLoader()->loadSettings('/ignored.json', new LfBufferedOutput))->toThrow(
        MigrationApiException::class,
        'served both MinervaKB settings rows'
    );
});

it('gives the no-plugin diagnosis ahead of the both-rows one where both apply', function (): void {
    // `inside/center-for-nursing-excellence`: both rows served and no MinervaKB installed.
    // Either refusal stops the import; this one names the actual cause.
    fakeOptionsWithPlugins(
        [
            'minerva-kb-options' => minervaKbDriftedV1Settings(),
            'uamswp-minerva-kb-options' => minervaKbV2Settings(),
        ],
        [],
        ['UAMSWP-minervakb']
    );

    expect(fn () => apiLoader()->loadSettings('/ignored.json', new LfBufferedOutput))->toThrow(
        MigrationApiException::class,
        'no MinervaKB plugin installed'
    );
});

it('refuses where the v1 plugin is active and only the v2 row was served', function (): void {
    // `inside.uams.edu/tri` (wordpress-importer-archive#1133): v1 runs there on its defaults and never wrote a row, and
    // the only row served is v2's, left behind by a plugin its network does not have.
    fakeOptionsWithActivePlugins(
        ['uamswp-minerva-kb-options' => minervaKbV2Settings()],
        ['__UAMSWP-minervakb/minervakb.php']
    );

    expect(fn () => apiLoader()->loadSettings('/ignored.json', new LfBufferedOutput))
        ->toThrow(MigrationApiException::class, 'is written by v2, which is not what runs here');
});

it('names the row it refused and quotes the withheld counts', function (): void {
    // The v1 row may have been withheld rather than be absent, so the refusal must not read
    // as proof that it is gone.
    fakeOptionsWithActivePlugins(
        ['uamswp-minerva-kb-options' => minervaKbV2Settings()],
        ['UAMSWP-minervakb/minervakb.php'],
        ['by_shape' => 2, 'by_name' => 5, 'total' => 7]
    );

    try {
        apiLoader()->loadSettings('/ignored.json', new LfBufferedOutput);
        $message = '';
    } catch (MigrationApiException $e) {
        $message = $e->getMessage();
    }

    expect($message)->toContain('"minerva-kb-options"')
        ->and($message)->toContain('("uamswp-minerva-kb-options")')
        ->and($message)->toContain('2 rows withheld by shape and 5 by name');
});

it('still imports where the v1 plugin is active and its own row was served', function (): void {
    // `hr/root` and `hr/benefits` as they stand since their plugins were swapped (wordpress-importer-archive#1134): v1
    // active under `minervakb.php`, serving v1's row, 27 of 27 loader keys correct (wordpress-importer-archive#666).
    fakeOptionsWithActivePlugins(
        ['minerva-kb-options' => minervaKbDriftedV1Settings()],
        ['UAMSWP-minervakb/minervakb.php']
    );

    $settings = loadedSettings(apiLoader()->loadSettings('/ignored.json', new LfBufferedOutput));

    expect($settings['search_placeholder'])->toBe('ex.: Guidelines');
});

it('does not refuse on the v2 entry file, whose name a v1 plugin has run under', function (): void {
    // The mirror of the refusal, and the reason it is narrow. `uamswp-minervakb.php` is v2's
    // name, but `hr` ran v1 1.6.6 from it and imported correctly from the v1 row (wordpress-importer-archive#1134), so
    // that name decides nothing here: the row served is read, with the warning.
    fakeOptionsWithActivePlugins(
        ['minerva-kb-options' => minervaKbDriftedV1Settings()],
        ['uamswp-minervakb/uamswp-minervakb.php']
    );

    $output = new LfBufferedOutput;
    $settings = loadedSettings(apiLoader()->loadSettings('/ignored.json', $output));

    expect($settings['search_placeholder'])->toBe('ex.: Guidelines')
        ->and($output->fetch())->toContain('not among those the migration API served');
});

// --- a generation the operator declares (wordpress-importer-archive#1235) -------------------------------

it('reads the declared v2 row where both are served, and says the operator chose it', function (): void {
    fakeOptionsRows([
        'minerva-kb-options' => minervaKbDriftedV1Settings(),
        'uamswp-minerva-kb-options' => minervaKbV2Settings(),
    ]);

    $output = new LfBufferedOutput;
    $settings = loadedSettings(apiLoader(null, 'v2')->loadSettings('/ignored.json', $output));

    // The drifted values specifically, so a loader that named v2 and read v1 cannot pass
    expect($settings['search_placeholder'])->toBe('e.g., Guidelines')
        ->and($settings['show_related_articles'])->toBe(true)
        ->and($output->fetch())->toContain('chosen by the declared generation v2 (--minerva-generation=v2)');
});

it('reads the declared v1 row where both are served', function (): void {
    // The Clinical Education shape: v1 runs, and both rows are served.
    fakeOptionsRows([
        'minerva-kb-options' => minervaKbDriftedV1Settings(),
        'uamswp-minerva-kb-options' => minervaKbV2Settings(),
    ]);

    $settings = loadedSettings(apiLoader(null, 'v1')->loadSettings('/ignored.json', new LfBufferedOutput));

    expect($settings['search_placeholder'])->toBe('ex.: Guidelines')
        ->and($settings['show_related_articles'])->toBe('false');
});

it('refuses a declared generation whose row was not served, naming both rows and the counts', function (): void {
    // Reading the other row in its place would import the wrong generation's settings under
    // the operator's name.
    fakeOptionsRows(
        ['uamswp-minerva-kb-options' => minervaKbV2Settings()],
        ['by_shape' => 2, 'by_name' => 4]
    );

    try {
        apiLoader(null, 'v1')->loadSettings('/ignored.json', new LfBufferedOutput);
        $message = '';
    } catch (MigrationApiException $e) {
        $message = $e->getMessage();
    }

    expect($message)->toBe(
        'The run declares MinervaKB generation v1 (--minerva-generation=v1), which writes '
        .'"minerva-kb-options", and the migration API did not serve that row. It served '
        .'"uamswp-minerva-kb-options". The endpoint reports 2 rows withheld by shape and 4 by name '
        .'and never says which, so "minerva-kb-options" may exist and have been withheld. Refusing '
        .'to read another generation\'s row in its place.'
    );
});

it('reads the declared row over an entry file that names the other generation', function (): void {
    // `inside/tri`'s shape, but with the operator's plugins-page read supplied. The declaration
    // comes from the disk; the entry file is the heuristic wordpress-importer-archive#1133 refused on for want of it.
    fakeOptionsWithActivePlugins(
        ['uamswp-minerva-kb-options' => minervaKbV2Settings()],
        ['__UAMSWP-minervakb/minervakb.php']
    );

    $output = new LfBufferedOutput;
    $settings = loadedSettings(apiLoader(null, 'v2')->loadSettings('/ignored.json', $output));

    expect($settings['search_placeholder'])->toBe('e.g., Guidelines')
        ->and($output->fetch())->not->toContain('not among those the migration API served');
});

it('still refuses a declared generation where no MinervaKB plugin is installed', function (): void {
    // The declaration and the site disagree, so the run stops rather than choosing.
    fakeOptionsWithPlugins(
        [
            'minerva-kb-options' => minervaKbDriftedV1Settings(),
            'uamswp-minerva-kb-options' => minervaKbV2Settings(),
        ],
        [],
        ['UAMSWP-minervakb']
    );

    expect(fn () => apiLoader(null, 'v2')->loadSettings('/ignored.json', new LfBufferedOutput))->toThrow(
        MigrationApiException::class,
        'no MinervaKB plugin installed'
    );
});

it('does not carry a declaration into a later source that sets none', function (): void {
    // The loader is a container singleton, so a second run in the same process must not
    // inherit the first run's declaration.
    fakeOptionsRows([
        'minerva-kb-options' => minervaKbDriftedV1Settings(),
        'uamswp-minerva-kb-options' => minervaKbV2Settings(),
    ]);

    $loader = apiLoader(null, 'v2');
    $loader->clearMigrationApiSource();
    $loader->setMigrationApiSource(new Client('https://example.test', 'shhh'));

    expect(fn () => $loader->loadSettings('/ignored.json', new LfBufferedOutput))->toThrow(
        MigrationApiException::class,
        'served both MinervaKB settings rows'
    );
});

it('refuses a generation it does not know rather than matching nothing', function (): void {
    expect(fn () => apiLoader(null, 'v3'))->toThrow(
        InvalidArgumentException::class,
        'Unknown MinervaKB generation "v3"; expected one of: v1, v2.'
    );
});

it('reports the declared generation, and none once the source is cleared', function (): void {
    $loader = apiLoader(null, 'v1');

    expect($loader->declaredGeneration())->toBe('v1');

    $loader->clearMigrationApiSource();

    expect($loader->declaredGeneration())->toBeNull()
        ->and(apiLoader()->declaredGeneration())->toBeNull();
});

// --- the generation the plugin header on disk declares (wordpress-importer-archive#1262) ----------------

/**
 * Fake the options endpoint with settings rows and the `plugins` field.
 *
 * `plugins` is the one field the endpoint reads from disk (its D-087): each active plugin path
 * with the `Name`, `Version` and `TextDomain` its file declares, or a null header where no
 * installed plugin sits at that path.
 *
 * @param  array<string, array<string, mixed>>  $rows  Settings keyed by `wp_options` row name
 * @param  array<mixed>  $plugins  The `plugins` entries, as served
 */
function fakeOptionsWithPluginHeaders(array $rows, array $plugins): void
{
    $options = [];

    foreach ($rows as $key => $settings) {
        $options[$key] = \json_encode($settings);
    }

    Http::fake([
        '*/options*' => Http::response([
            'options' => $options,
            'acf' => [],
            'denylist' => ['added' => [], 'removed' => [], 'stale' => true],
            'denied' => ['by_shape' => 0, 'by_name' => 0, 'total' => 0],
            'plugins' => $plugins,
        ], 200),
    ]);
}

/**
 * A `plugins` entry for an installed plugin declaring the given Text Domain.
 *
 * @return array<string, mixed>
 */
function pluginWithTextDomain(string $textDomain, string $path = 'UAMSWP-minervakb/uamswp-minervakb.php'): array
{
    return [
        'path' => $path,
        'scope' => 'site',
        'header' => ['Name' => 'MinervaKB', 'Version' => '1.6.6', 'TextDomain' => $textDomain],
    ];
}

/**
 * Both generations' rows, the shape every test below reads from.
 *
 * @return array<string, array<string, mixed>>
 */
function bothMinervaKbRows(): array
{
    return [
        'minerva-kb-options' => minervaKbDriftedV1Settings(),
        'uamswp-minerva-kb-options' => minervaKbV2Settings(),
    ];
}

it('refuses a declaration the plugin header on disk contradicts, naming both generations', function (): void {
    // `hr`'s shape (wordpress-importer-archive#1134): v2's entry file, v1's header, and an operator who declared v2
    fakeOptionsWithPluginHeaders(bothMinervaKbRows(), [
        pluginWithTextDomain('minerva-kb'),
        ['path' => 'other/other.php', 'scope' => 'network', 'header' => ['Name' => 'Other', 'Version' => '1', 'TextDomain' => 'other']],
    ]);

    try {
        apiLoader(null, 'v2')->loadSettings('/ignored.json', new LfBufferedOutput);
        $message = '';
    } catch (MigrationApiException $e) {
        $message = $e->getMessage();
    }

    expect($message)->toBe(
        'The run declares MinervaKB generation v2 (--minerva-generation=v2), but the MinervaKB '
        .'plugin installed on this site declares Text Domain "minerva-kb", which is generation v1, '
        .'read from disk by the migration API. Refusing to import generation v2\'s settings for a '
        .'site that runs generation v1. Check the network plugins page, and re-run with '
        .'--minerva-generation=v1 or without the option.'
    );
});

it('refuses a declared v1 where the header on disk is v2', function (): void {
    fakeOptionsWithPluginHeaders(bothMinervaKbRows(), [pluginWithTextDomain('uamswp-minerva-kb')]);

    expect(fn () => apiLoader(null, 'v1')->loadSettings('/ignored.json', new LfBufferedOutput))->toThrow(
        MigrationApiException::class,
        'declares Text Domain "uamswp-minerva-kb", which is generation v2'
    );
});

it('accepts a declaration the header on disk agrees with, as before', function (): void {
    fakeOptionsWithPluginHeaders(bothMinervaKbRows(), [pluginWithTextDomain('uamswp-minerva-kb')]);

    $output = new LfBufferedOutput;
    $settings = loadedSettings(apiLoader(null, 'v2')->loadSettings('/ignored.json', $output));

    expect($settings['search_placeholder'])->toBe('e.g., Guidelines')
        ->and($output->fetch())->toContain('chosen by the declared generation v2 (--minerva-generation=v2)');
});

it('reads the v1 row where both are served and the header on disk is v1, and says why', function (): void {
    fakeOptionsWithPluginHeaders(bothMinervaKbRows(), [pluginWithTextDomain('minerva-kb')]);

    $output = new LfBufferedOutput;
    $settings = loadedSettings(apiLoader()->loadSettings('/ignored.json', $output));

    expect($settings['search_placeholder'])->toBe('ex.: Guidelines')
        ->and($settings['show_related_articles'])->toBe('false')
        ->and($output->fetch())->toContain(
            'Fetched the MinervaKB settings row "minerva-kb-options" from the migration API, chosen by '
            .'the installed plugin\'s Text Domain "minerva-kb" (generation v1), read from disk.'
        );
});

it('reads the v2 row where both are served and the header on disk is v2', function (): void {
    fakeOptionsWithPluginHeaders(bothMinervaKbRows(), [pluginWithTextDomain('uamswp-minerva-kb')]);

    $output = new LfBufferedOutput;
    $settings = loadedSettings(apiLoader()->loadSettings('/ignored.json', $output));

    expect($settings['search_placeholder'])->toBe('e.g., Guidelines')
        ->and($output->fetch())->toContain('(generation v2), read from disk');
});

it('keeps the both-rows refusal where no header names a generation', function (array $plugins): void {
    fakeOptionsWithPluginHeaders(bothMinervaKbRows(), $plugins);

    expect(fn () => apiLoader()->loadSettings('/ignored.json', new LfBufferedOutput))->toThrow(
        MigrationApiException::class,
        'served both MinervaKB settings rows'
    );
})->with([
    'a missing file' => [[['path' => 'UAMSWP-minervakb/uamswp-minervakb.php', 'scope' => 'site', 'header' => null]]],
    'no Text Domain declared' => [[pluginWithTextDomain('')]],
    'another plugin only' => [[pluginWithTextDomain('other-plugin', 'other/other.php')]],
    'both generations at once' => [[pluginWithTextDomain('minerva-kb'), pluginWithTextDomain('uamswp-minerva-kb', 'b/b.php')]],
    'an empty list' => [[]],
]);

it('keeps a declaration where no header names a generation', function (): void {
    // A header that decides nothing cannot contradict the operator
    fakeOptionsWithPluginHeaders(bothMinervaKbRows(), [
        pluginWithTextDomain('minerva-kb'),
        pluginWithTextDomain('uamswp-minerva-kb', 'b/b.php'),
    ]);

    $settings = loadedSettings(apiLoader(null, 'v2')->loadSettings('/ignored.json', new LfBufferedOutput));

    expect($settings['search_placeholder'])->toBe('e.g., Guidelines');
});

it('treats a payload without the plugins field exactly as before', function (): void {
    // An endpoint released before its D-087: the declaration decides, and without one both rows
    // are refused
    fakeOptionsRows(bothMinervaKbRows());

    $settings = loadedSettings(apiLoader(null, 'v1')->loadSettings('/ignored.json', new LfBufferedOutput));

    expect($settings['search_placeholder'])->toBe('ex.: Guidelines')
        ->and(fn () => apiLoader()->loadSettings('/ignored.json', new LfBufferedOutput))
        ->toThrow(MigrationApiException::class, 'served both MinervaKB settings rows');
});

it('reads the only row served where the header on disk names its generation', function (): void {
    fakeOptionsWithPluginHeaders(
        ['uamswp-minerva-kb-options' => minervaKbV2Settings()],
        [pluginWithTextDomain('uamswp-minerva-kb', '__UAMSWP-minervakb/minervakb.php')]
    );

    // The v1 entry file would refuse this under #1133; the header on disk outranks it
    $output = new LfBufferedOutput;
    $settings = loadedSettings(apiLoader()->loadSettings('/ignored.json', $output));

    expect($settings['search_placeholder'])->toBe('e.g., Guidelines')
        ->and($output->fetch())->toContain('(generation v2), read from disk');
});

it('refuses where the header on disk names a generation whose row was not served', function (): void {
    // Reading the v1 row here would import a generation this site does not run
    Http::fake([
        '*/options*' => Http::response([
            'options' => ['minerva-kb-options' => \json_encode(minervaKbDriftedV1Settings())],
            'acf' => [],
            'denylist' => ['added' => [], 'removed' => [], 'stale' => true],
            'denied' => ['by_shape' => 1, 'by_name' => 3, 'total' => 4],
            'plugins' => [pluginWithTextDomain('uamswp-minerva-kb')],
        ], 200),
    ]);

    try {
        apiLoader()->loadSettings('/ignored.json', new LfBufferedOutput);
        $message = '';
    } catch (MigrationApiException $e) {
        $message = $e->getMessage();
    }

    expect($message)->toBe(
        'The MinervaKB plugin installed on this site declares Text Domain "uamswp-minerva-kb", read '
        .'from disk by the migration API, so it runs generation v2, which writes '
        .'"uamswp-minerva-kb-options". The migration API did not serve that row. It served '
        .'"minerva-kb-options". The endpoint reports 1 rows withheld by shape and 3 by name and '
        .'never says which, so "uamswp-minerva-kb-options" may exist and have been withheld. '
        .'Refusing to read another generation\'s row in its place.'
    );
});

// --- #100: the exact refusals and warning, and the readers behind them -----------------------

it('words the refusal of a v1 site served only the v2 row in full', function (): void {
    fakeOptionsWithActivePlugins(
        ['uamswp-minerva-kb-options' => minervaKbV2Settings()],
        ['UAMSWP-minervakb/minervakb.php'],
        ['by_shape' => 2, 'by_name' => 5, 'total' => 7]
    );

    expect(fn () => apiLoader()->loadSettings('/ignored.json', new LfBufferedOutput))->toThrow(
        MigrationApiException::class,
        'The MinervaKB plugin active on this site is v1 (its entry file is minervakb.php), '
        .'and v1 writes "minerva-kb-options", which the migration API did not serve. The row it did serve '
        .'("uamswp-minerva-kb-options") is written by v2, which is not what runs here, so it is a leftover this '
        .'site does not use. The endpoint reports 2 rows withheld by shape and 5 by name '
        .'and never says which, so "minerva-kb-options" may exist and have been withheld rather than be '
        .'absent. Refusing to import knowledge base content with a configuration this site '
        .'does not use.'
    );
});

it('words the warning that the installed generation\'s row was not served in full', function (): void {
    fakeOptionsWithActivePlugins(
        ['minerva-kb-options' => minervaKbDriftedV1Settings()],
        ['uamswp-minervakb/uamswp-minervakb.php'],
        ['by_shape' => 2, 'by_name' => 5, 'total' => 7]
    );

    $output = new LfBufferedOutput;
    apiLoader()->loadSettings('/ignored.json', $output);

    expect($output->fetch())->toContain(
        'Warning: the MinervaKB generation installed on this site writes '
        .'"uamswp-minerva-kb-options", and that row is not among those the migration API served. The row '
        .'read instead ("minerva-kb-options") is written by the other generation. The endpoint '
        .'reports 2 rows withheld by shape and 5 by name and never reports which, '
        .'so "uamswp-minerva-kb-options" may exist here and have been withheld rather than be absent. If '
        .'this site runs the generation that writes "uamswp-minerva-kb-options", these are not the settings '
        ."it uses.\n"
    );
});

it('words the refusal of a row left by a plugin that is not installed in full', function (): void {
    fakeOptionsWithPlugins(['uamswp-minerva-kb-options' => minervaKbV2Settings()], [], ['UAMSWP-minervakb']);

    expect(fn () => apiLoader()->loadSettings('/ignored.json', new LfBufferedOutput))->toThrow(
        MigrationApiException::class,
        'The migration API served a MinervaKB settings row ("uamswp-minerva-kb-options"), but this site has no '
        ."MinervaKB plugin installed \u{2014} the endpoint reports the plugin directory as not "
        .'running here. A `wp_options` row outlives the plugin that wrote it, so the row '
        .'is a leftover and its configuration is not what this site uses. Refusing to '
        .'import knowledge base content from it.'
    );
});

it('finds the v1 entry file however active_plugins is served or cased', function (mixed $activePlugins): void {
    // Served as a JSON object string, with something that is not a path ahead of it, or with
    // the file's name in another case: each still names v1, so the v2 row alone is refused
    Http::fake([
        '*/options*' => Http::response([
            'options' => [
                'uamswp-minerva-kb-options' => \json_encode(minervaKbV2Settings()),
                'active_plugins' => $activePlugins,
            ],
            'acf' => [],
            'denylist' => ['added' => [], 'removed' => [], 'stale' => true],
            'denied' => ['by_shape' => 0, 'by_name' => 0, 'total' => 0],
        ], 200),
    ]);

    expect(fn () => apiLoader()->loadSettings('/ignored.json', new LfBufferedOutput))
        ->toThrow(MigrationApiException::class, 'is written by v2, which is not what runs here');
})->with([
    'a JSON object string' => ['{"3":"UAMSWP-minervakb/minervakb.php"}'],
    'after an entry that is not a path' => [[5, 'UAMSWP-minervakb/minervakb.php']],
    'in another case' => [['UAMSWP-minervakb/MinervaKB.php']],
]);

it('reads the denylist halves on their own, and only a mention of the plugin counts', function (array $denylist, bool $refused): void {
    Http::fake([
        '*/options*' => Http::response([
            'options' => ['uamswp-minerva-kb-options' => \json_encode(minervaKbV2Settings())],
            'acf' => [],
            'denylist' => $denylist,
            'denied' => ['by_shape' => 0, 'by_name' => 0, 'total' => 0],
        ], 200),
    ]);

    $load = fn (): ?array => apiLoader()->loadSettings('/ignored.json', new LfBufferedOutput);

    if ($refused) {
        expect($load)->toThrow(MigrationApiException::class, 'has no MinervaKB plugin installed');
    } else {
        expect(loadedSettings($load()))->toBe(minervaKbV2Settings());
    }
})->with([
    'removed alone names it' => [['removed' => ['UAMSWP-minervakb']], true],
    'removed names another plugin' => [['added' => [], 'removed' => ['akismet']], false],
]);

it('reports a file it fails to read for any other reason, and logs it', function (): void {
    $log = Log::spy();
    $loader = new class extends Loader
    {
        protected function readFileContent(string $filePath, OutputInterface $output): ?string
        {
            throw new RuntimeException('disk gone');
        }
    };

    $output = new LfBufferedOutput;

    expect($loader->loadSettings('/settings.json', $output))->toBeNull()
        ->and($output->fetch())->toBe("Error loading settings file: disk gone\n");

    $log->shouldHaveReceived('error', ['Loader::loadSettings: Error loading settings file /settings.json: disk gone']);
});

it('reports a file whose JSON is not an object or a list, and logs it', function (): void {
    $path = $this->workingDirectory.\DIRECTORY_SEPARATOR.'scalar.json';
    \file_put_contents($path, '5');

    $log = Log::spy();
    $output = new LfBufferedOutput;

    expect((new Loader)->loadSettings($path, $output))->toBeNull()
        ->and($output->fetch())->toBe("Invalid JSON format: expected an object or array.\n");

    $log->shouldHaveReceived('error', ["Loader::parseJsonContent: JSON root is not an array in {$path}"]);
});

it('words an invalid JSON error with the parser\'s reason', function (): void {
    $path = $this->workingDirectory.\DIRECTORY_SEPARATOR.'broken.json';
    \file_put_contents($path, '{not valid json');

    $output = new LfBufferedOutput(decorated: true);
    (new Loader)->loadSettings($path, $output);

    expect($output->fetch())->toBe("\e[37;41mInvalid JSON format: Syntax error\e[39;49m\n");
});
