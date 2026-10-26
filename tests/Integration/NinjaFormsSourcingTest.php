<?php

declare(strict_types=1);

use Closure;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms\ContentDetector;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms\DataManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms\FormIdExtractor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms\UserInteractionHandler;
use UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi\Client;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Loaders\NinjaFormsLoader;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\NinjaForms\Selector as NinjaFormsSelector;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\NinjaForms\Validator as NinjaFormsValidator;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * Ninja Forms sourced from the migration API (wordpress-importer-archive#810).
 *
 * Ninja Forms is the one plugin whose file path **cannot** be a straight sourcing swap. A
 * `.nff` carries no form ID at any level, while content references `[ninja_form id=N]`, so
 * the file path asks the operator to answer for each referenced ID in turn. The endpoint
 * serves every form with its own `id`, which is exactly the field the file lacks — so the
 * substance of this ticket is that the prompt loop is retired, not merely that a different
 * reader is called.
 *
 * @command  php vendor/bin/pest tests/Integration/NinjaFormsSourcingTest.php
 */
uses(StatamicTestCase::class);

/**
 * One real-shaped Ninja Forms definition, as the endpoint serves it.
 *
 * @param  int  $id  The form ID the endpoint reports
 * @param  string  $title  The form title
 * @return array<string, mixed>
 */
function ninjaSampleForm(int $id, string $title): array
{
    return [
        'id' => $id,
        'title' => $title,
        'settings' => [
            'title' => $title,
            'key' => 'form_'.$id,
            'embed_form' => '',
        ],
        'fields' => [
            [
                'id' => 1,
                'type' => 'textbox',
                'label' => 'Full name',
                'key' => 'full_name_1',
                'required' => 1,
            ],
            [
                'id' => 2,
                'type' => 'email',
                'label' => 'Email',
                'key' => 'email_2',
                'required' => 1,
            ],
        ],
        'actions' => [
            [
                'id' => 9,
                'type' => 'email',
                'label' => 'Admin Email',
                'to' => '{wp:admin_email}',
            ],
        ],
    ];
}

/**
 * A command with output attached, so writeln()/info() have somewhere to go.
 */
function ninjaSourcingCommand(BufferedOutput $buffer, ?InputInterface $input = null): Command
{
    $command = new class extends Command
    {
        protected $signature = 'test:ninja-sourcing-host';
    };

    // `$command->ask()` and `$command->confirm()` read the input INSIDE this OutputStyle, not
    // the one passed to `select()`. A test that scripts answers must hand the same input to
    // both, or the trait checks one object's interactivity and reads another's stream.
    $command->setOutput(new OutputStyle($input ?? new ArrayInput([]), $buffer));

    return $command;
}

/**
 * An interactive input whose answers come from a stream rather than a terminal (wordpress-importer-archive#1104).
 *
 * The input stays INTERACTIVE, so the production short-circuit in `askWithValidation()` —
 * non-interactive returns the default without asking — does not fire. What makes the
 * stream get READ instead of the real STDIN is not this helper but the one below: the
 * selector has to run inside `Command::run()`, which under `runningUnitTests()` routes
 * every Laravel Prompt to a Symfony question on the command's own input.
 *
 * @param  string  $answers  The answers, one per line, in the order the questions are asked
 */
function ninjaSourcingScriptedInput(string $answers): ArrayInput
{
    $stream = \fopen('php://memory', 'r+');

    \assert(\is_resource($stream));

    \fwrite($stream, $answers);
    \rewind($stream);

    $input = new ArrayInput([]);
    $input->setStream($stream);

    return $input;
}

/**
 * A Ninja selector with both dispatch targets exposed (wordpress-importer-archive#1104).
 *
 * `select()` picks one branch per platform — Laravel Prompts on macOS and Linux, Symfony
 * questions on Windows — so a test that only calls `select()` exercises one branch here
 * and the other there, and a regression in either is invisible on the platform that does
 * not take it. Naming each lets one file cover both everywhere. A named class rather than
 * an anonymous one, so the extra methods exist on a type the analyser can see.
 */
final class NinjaSourcingBranchSelector extends NinjaFormsSelector
{
    /** @return array<int|string, mixed>|null */
    public function viaLaravelPrompts(Command $command): ?array
    {
        return $this->selectWithLaravelPrompts($command);
    }

    /** @return array<int|string, mixed>|null */
    public function viaSymfonyConsole(InputInterface $input, OutputInterface $output, Command $command): ?array
    {
        return $this->selectWithSymfonyConsole($input, $output, $command);
    }
}

function ninjaSourcingBranchSelector(NinjaFormsLoader $loader): NinjaSourcingBranchSelector
{
    return new NinjaSourcingBranchSelector(new NinjaFormsValidator, $loader);
}

/**
 * Run the selector the way production reaches it: inside a real `Command::run()` (wordpress-importer-archive#1104).
 *
 * This is the route a test takes through the file-path prompt, and it needs no seam in the
 * code under test. `Command::run()` calls `configurePrompts()`, which under
 * `runningUnitTests()` turns `Prompt::fallbackWhen()` on and registers a fallback for every
 * prompt class that reads through `$this->components` — so `confirm()` and `text()` become
 * Symfony questions on the command's input stream, and the Symfony branch beside them was
 * already reading it. A hand-built command that never runs skips that call, which is why
 * driving `select()` directly reached the real STDIN on macOS and hung. Platform does not
 * enter into it: on Windows the selector takes the Symfony branch outright and reads the
 * same stream.
 *
 * `run()` binds `$input` to the command's (empty) definition first, which is why the
 * scripted input is an `ArrayInput` with no arguments rather than a bare stream.
 *
 * @param  Closure(InputInterface, OutputInterface, Command): (array<int|string, mixed>|null)  $body  What to run inside the command
 * @param  ArrayInput  $input  The scripted input, from ninjaSourcingScriptedInput()
 * @param  BufferedOutput  $buffer  Where the run's output goes
 * @return array<int|string, mixed>|null What the body returned inside the run
 */
function ninjaSourcingSelectThroughCommand(Closure $body, ArrayInput $input, BufferedOutput $buffer): ?array
{
    $command = new class($body) extends Command
    {
        protected $signature = 'test:ninja-sourcing-select';

        /** @var array<int|string, mixed>|null */
        public ?array $forms = null;

        /**
         * @param  Closure(InputInterface, OutputInterface, Command): (array<int|string, mixed>|null)  $body
         */
        public function __construct(private readonly Closure $body)
        {
            parent::__construct();
        }

        public function handle(): int
        {
            $this->forms = ($this->body)($this->input, $this->output, $this);

            return self::SUCCESS;
        }
    };

    $command->setLaravel(app());
    $command->run($input, $buffer);

    return $command->forms;
}

/**
 * Write a `.nff` export: one form per file, no id, optionally advertising one.
 *
 * @param  string  $path  Where to write it
 * @param  int  $id  The form id the CONTENT will reference
 * @param  string  $title  The form title
 * @param  string  $advertises  The id `settings.embed_form` advertises, or '' for none
 */
function ninjaWriteNffExport(string $path, int $id, string $title, string $advertises = ''): void
{
    $form = ninjaSampleForm($id, $title);

    // A `.nff` carries NO id at any level — that absence is the whole premise of this ticket,
    // so the fixture reproduces it rather than trimming a field for convenience.
    unset($form['id']);

    $settings = $form['settings'];

    // Narrowed through a local because the fixture's own type is `array<string, mixed>`, so a
    // nested offset write is a write to `mixed` as far as level max is concerned.
    $form['settings'] = \is_array($settings)
        ? \array_merge($settings, ['embed_form' => $advertises])
        : ['embed_form' => $advertises];

    \file_put_contents($path, (string) \json_encode($form));
}

/**
 * A parser over a one-item WXR whose content references the given form IDs by shortcode.
 *
 * @param  string  $workingDirectory  Scratch directory for the fixture
 * @param  list<int>  $formIds  The form IDs the content references
 */
function ninjaSourcingParser(string $workingDirectory, array $formIds): XmlParser
{
    $shortcodes = '';

    foreach ($formIds as $formId) {
        $shortcodes .= "[ninja_form id={$formId}]";
    }

    $document = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
        .'<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" '
        .'xmlns:wp="http://wordpress.org/export/1.2/">'."\n"
        .'<channel>'."\n"
        .'<wp:base_blog_url>https://uams-forms.test</wp:base_blog_url>'."\n"
        .'<item><title>Contact</title><link>https://uams-forms.test/contact</link>'
        .'<wp:post_id>101</wp:post_id><wp:post_name>contact</wp:post_name>'
        .'<wp:post_type>page</wp:post_type><wp:status>publish</wp:status>'
        .'<content:encoded><![CDATA['.$shortcodes.']]></content:encoded></item>'."\n"
        .'</channel>'."\n".'</rss>';

    $path = $workingDirectory.\DIRECTORY_SEPARATOR.'ninja-references.xml';
    \file_put_contents($path, $document);

    $parser = new XmlParser;
    $parser->parse($path);

    return $parser;
}

/**
 * A handler wired to a loader whose source is the caller's to configure.
 *
 * @return array{0: UserInteractionHandler, 1: DataManager, 2: NinjaFormsLoader}
 */
function ninjaSourcingHandler(): array
{
    $loader = new NinjaFormsLoader;
    $dataManager = new DataManager;

    $handler = new UserInteractionHandler(
        new ContentDetector,
        $dataManager,
        new NinjaFormsSelector(new NinjaFormsValidator, $loader),
        new FormIdExtractor,
        new NinjaFormsValidator
    );

    return [$handler, $dataManager, $loader];
}

beforeEach(function (): void {
    $this->workingDirectory = \sys_get_temp_dir()
        .\DIRECTORY_SEPARATOR
        .\uniqid('nf_sourcing_', true);

    \mkdir($this->workingDirectory);
});

afterEach(function (): void {
    foreach ((array) \glob($this->workingDirectory.\DIRECTORY_SEPARATOR.'*') as $file) {
        if (\is_string($file)) {
            \unlink($file);
        }
    }

    \rmdir($this->workingDirectory);
});

it('binds every referenced form from the endpoint without prompting for one', function (): void {
    // The claim the ticket rests on. On the file path each of these two IDs costs the
    // operator a prompt and a file; here both are resolved from ids the endpoint itself
    // reports, and the run is non-interactive end to end.
    Http::fake([
        '*/forms/ninja*' => Http::response(
            [ninjaSampleForm(3, 'Contact Us'), ninjaSampleForm(7, 'Refer a Patient')],
            200,
            ['X-WP-Total' => '2']
        ),
    ]);

    [$handler, $dataManager, $loader] = ninjaSourcingHandler();
    $loader->setMigrationApiSource(new Client('https://example.test', 'shhh'));

    $buffer = new BufferedOutput;
    $command = ninjaSourcingCommand($buffer);

    // Non-interactive deliberately: the file path must be able to decline rather than block,
    // so that disabling the short-circuit produces a FAILURE rather than a hung run.
    $input = new ArrayInput([]);
    $input->setInteractive(false);

    $loaded = $handler->handleFormPluginDetection(
        ninjaSourcingParser($this->workingDirectory, [3, 7]),
        $input,
        $buffer,
        $command
    );

    expect($loaded)->toBeTrue();

    // The prompt the API path exists to retire, asserted BEFORE the content below. Ordering
    // matters: measured, a build with the short-circuit disabled still binds a stub for each
    // referenced ID, so the content assertions fail first with `Undefined array key "title"`
    // and the claim this ticket actually makes is never the thing that reports.
    expect($buffer->fetch())->not->toContain('Supply the Ninja Forms');

    // Each form is keyed by the ID the CONTENT referenced, which is what the downstream
    // availability filter intersects against.
    $three = $dataManager->getNinjaForm(3);
    $seven = $dataManager->getNinjaForm(7);

    // Matched rather than indexed, so the assertion needs no narrowing of a `?array` and the
    // `id` it checks is the one the ENDPOINT reported, not the key it was filed under.
    expect($three)->not->toBeNull()
        ->and($seven)->not->toBeNull()
        ->and($three)->toMatchArray(['id' => 3, 'title' => 'Contact Us'])
        ->and($seven)->toMatchArray(['id' => 7, 'title' => 'Refer a Patient']);

});

it('still prompts per referenced form when no API source is configured', function (): void {
    // The control for the arm above. Without it, a handler that ALWAYS read the API would
    // satisfy that test and break every run still supplying `.nff` files.
    Http::fake([
        '*' => Http::response('should not be called', 500),
    ]);

    [$handler, , $loader] = ninjaSourcingHandler();

    expect($loader->hasMigrationApiSource())->toBeFalse();

    $buffer = new BufferedOutput;

    // Non-interactive input, so the file prompt declines rather than blocking — and the
    // COMMAND is given the same input. Built with its own interactive input, as it was
    // until wordpress-importer-archive#1104, `$command->confirm()` read that one instead: no stream, so it fell
    // through to the real STDIN. That passed in the gate only because pest's STDIN yields
    // EOF there; under an open pipe this test blocked until killed.
    $input = new ArrayInput([]);
    $input->setInteractive(false);

    $command = ninjaSourcingCommand($buffer, $input);

    $handler->handleFormPluginDetection(
        ninjaSourcingParser($this->workingDirectory, [3]),
        $input,
        $buffer,
        $command
    );

    expect($buffer->fetch())->toContain('Supply the Ninja Forms');

    Http::assertNothingSent();
});

it('loads a real export from disk through the selector when the answers come from a stream', function (): void {
    // wordpress-importer-archive#1104. The file branch of `select()` — confirmation, path, `loadAndValidateFile()` —
    // had no integration coverage: the path question returned null on non-interactive
    // input, and interactive input reached the real STDIN. Both answers now come from a
    // stream, and what is asserted is the LOADED form, not the prompt.
    Http::fake([
        '*' => Http::response('should not be called', 500),
    ]);

    $exportPath = $this->workingDirectory.\DIRECTORY_SEPARATOR.'contact.nff';
    ninjaWriteNffExport($exportPath, 3, 'Contact Us');

    $loader = new NinjaFormsLoader;
    $selector = new NinjaFormsSelector(new NinjaFormsValidator, $loader);

    $input = ninjaSourcingScriptedInput("yes\n{$exportPath}\n");
    $buffer = new BufferedOutput;

    $forms = ninjaSourcingSelectThroughCommand(
        fn (InputInterface $i, OutputInterface $o, Command $c): ?array => $selector->select($i, $o, $c),
        $input,
        $buffer
    );

    // Both answers were consumed: the stream sits at its end, which is what distinguishes
    // "the path was asked and answered" from "the confirmation alone was read".
    $stream = $input->getStream();

    \assert(\is_resource($stream));

    expect(\ftell($stream))->toBe(\strlen("yes\n{$exportPath}\n"));

    // And the thing loaded is the file's form, byte-for-byte what the loader reads directly.
    expect($forms)->not->toBeNull()
        ->and($forms)->toBe($loader->load($exportPath, ninjaSourcingCommand(new BufferedOutput)))
        ->and(\json_encode($forms))->toContain('Contact Us');

    Http::assertNothingSent();
});

it('still declines on non-interactive input without reading a stream that has answers', function (): void {
    // The production short-circuit is unchanged: a run that cannot ask must not block. The
    // stream carries both answers, so a decline that READ it would be the wrong kind of
    // decline — this asserts the question was never put, not merely that null came back.
    $exportPath = $this->workingDirectory.\DIRECTORY_SEPARATOR.'contact.nff';
    ninjaWriteNffExport($exportPath, 3, 'Contact Us');

    $selector = new NinjaFormsSelector(new NinjaFormsValidator, new NinjaFormsLoader);

    $input = ninjaSourcingScriptedInput("yes\n{$exportPath}\n");
    $input->setInteractive(false);

    $forms = ninjaSourcingSelectThroughCommand(
        fn (InputInterface $i, OutputInterface $o, Command $c): ?array => $selector->select($i, $o, $c),
        $input,
        new BufferedOutput
    );

    $stream = $input->getStream();

    \assert(\is_resource($stream));

    expect($forms)->toBeNull()
        ->and(\ftell($stream))->toBe(0);
});

it('loads the export through the Symfony branch, whatever platform dispatched it', function (): void {
    // On macOS `select()` never reaches this branch, so its `askWithValidation()` path —
    // the one the ticket measured — would otherwise be tested only on Windows.
    $exportPath = $this->workingDirectory.\DIRECTORY_SEPARATOR.'contact.nff';
    ninjaWriteNffExport($exportPath, 3, 'Contact Us');

    $loader = new NinjaFormsLoader;
    $selector = ninjaSourcingBranchSelector($loader);

    $input = ninjaSourcingScriptedInput("yes\n{$exportPath}\n");

    $forms = ninjaSourcingSelectThroughCommand(
        fn (InputInterface $i, OutputInterface $o, Command $c): ?array => $selector->viaSymfonyConsole($i, $o, $c),
        $input,
        new BufferedOutput
    );

    $stream = $input->getStream();

    \assert(\is_resource($stream));

    expect(\ftell($stream))->toBe(\strlen("yes\n{$exportPath}\n"))
        ->and($forms)->toBe($loader->load($exportPath, ninjaSourcingCommand(new BufferedOutput)));
});

it('loads the export through the Laravel Prompts branch, whatever platform dispatched it', function (): void {
    // The mirror of the test above: on Windows `select()` never reaches this branch. Inside
    // `run()` under `runningUnitTests()` the prompts fall back to Symfony questions on the
    // same stream, which is what makes it drivable here at all.
    $exportPath = $this->workingDirectory.\DIRECTORY_SEPARATOR.'contact.nff';
    ninjaWriteNffExport($exportPath, 3, 'Contact Us');

    $loader = new NinjaFormsLoader;
    $selector = ninjaSourcingBranchSelector($loader);

    $input = ninjaSourcingScriptedInput("yes\n{$exportPath}\n");

    $forms = ninjaSourcingSelectThroughCommand(
        fn (InputInterface $i, OutputInterface $o, Command $c): ?array => $selector->viaLaravelPrompts($c),
        $input,
        new BufferedOutput
    );

    $stream = $input->getStream();

    \assert(\is_resource($stream));

    expect(\ftell($stream))->toBe(\strlen("yes\n{$exportPath}\n"))
        ->and($forms)->toBe($loader->load($exportPath, ninjaSourcingCommand(new BufferedOutput)));
});

it('reports a referenced form the endpoint does not serve rather than dropping it', function (): void {
    // A referenced ID with no definition behind it is the shape the availability filter
    // silently swallows — it intersects to an empty set and imports nothing without an
    // error. Saying so is the difference between a skipped form and an invisible one.
    Http::fake([
        '*/forms/ninja*' => Http::response(
            [ninjaSampleForm(3, 'Contact Us')],
            200,
            ['X-WP-Total' => '1']
        ),
    ]);

    [$handler, $dataManager, $loader] = ninjaSourcingHandler();
    $loader->setMigrationApiSource(new Client('https://example.test', 'shhh'));

    $buffer = new BufferedOutput;

    $loaded = $handler->handleFormPluginDetection(
        ninjaSourcingParser($this->workingDirectory, [3, 41]),
        new ArrayInput([]),
        $buffer,
        ninjaSourcingCommand($buffer)
    );

    // One of the two bound, so the run is not a failure — it is a partial with a named gap.
    expect($loaded)->toBeTrue()
        ->and($dataManager->getNinjaForm(3))->not->toBeNull()
        ->and($dataManager->getNinjaForm(41))->toBeNull()
        ->and($buffer->fetch())->toContain('form ID 41');
});

it('sends the site id the run was scoped to, so definitions come from that site', function (): void {
    // The hole wordpress-importer-archive#1100 found under Gravity: the site was stored and never sent, and no test
    // could catch it because every fake matched with or without the parameter. This asserts
    // the wire.
    Http::fake([
        '*/forms/ninja*' => Http::response([ninjaSampleForm(3, 'Contact Us')], 200, ['X-WP-Total' => '1']),
    ]);

    [$handler, , $loader] = ninjaSourcingHandler();
    $loader->setMigrationApiSource(new Client('https://example.test', 'shhh'), 7);

    $buffer = new BufferedOutput;

    $handler->handleFormPluginDetection(
        ninjaSourcingParser($this->workingDirectory, [3]),
        new ArrayInput([]),
        $buffer,
        ninjaSourcingCommand($buffer)
    );

    $urls = [];

    Http::assertSent(function (Request $request) use (&$urls): bool {
        $urls[] = $request->url();

        return true;
    });

    expect($urls)->not->toBeEmpty()
        ->and($urls[0])->toContain('site=7');
});

it('sends no site parameter when the run was not scoped to one', function (): void {
    // The control in the other direction: a hardcoded site would satisfy the arm above and
    // break every unscoped run.
    Http::fake([
        '*/forms/ninja*' => Http::response([ninjaSampleForm(3, 'Contact Us')], 200, ['X-WP-Total' => '1']),
    ]);

    [$handler, , $loader] = ninjaSourcingHandler();
    $loader->setMigrationApiSource(new Client('https://example.test', 'shhh'));

    $buffer = new BufferedOutput;

    $handler->handleFormPluginDetection(
        ninjaSourcingParser($this->workingDirectory, [3]),
        new ArrayInput([]),
        $buffer,
        ninjaSourcingCommand($buffer)
    );

    $urls = [];

    Http::assertSent(function (Request $request) use (&$urls): bool {
        $urls[] = $request->url();

        return true;
    });

    expect($urls)->not->toBeEmpty()
        ->and($urls[0])->not->toContain('site=');
});

it('yields the same bound form from the endpoint as from the .nff for that form', function (): void {
    // AC 1, asserted where the claim is actually decidable. What every downstream blueprint,
    // notification and action mapper reads is what the DataManager HOLDS after binding — not
    // the payload, which differs by construction: the file carries no `id` and the endpoint
    // does, so asserting the raw payloads matched would assert the opposite of the thing that
    // makes this slice worth building.
    //
    // Driven through the loader rather than through the handler's prompt, because
    // `askWithValidation()` returns its default the moment input is non-interactive — so the
    // file path cannot be given a path in a test without driving Laravel Prompts' key stream.
    // The handler binds with the same `addNinjaForm($formId, $formData)` call on both paths,
    // so the loader output is the only thing that differs and the only thing worth comparing.
    $exportPath = $this->workingDirectory.\DIRECTORY_SEPARATOR.'contact.nff';
    ninjaWriteNffExport($exportPath, 3, 'Contact Us');

    Http::fake([
        '*/forms/ninja*' => Http::response([ninjaSampleForm(3, 'Contact Us')], 200, ['X-WP-Total' => '1']),
    ]);

    $command = ninjaSourcingCommand(new BufferedOutput);

    $fileLoader = new NinjaFormsLoader;
    $fromFile = $fileLoader->load($exportPath, $command);

    $apiLoader = new NinjaFormsLoader;
    $apiLoader->setMigrationApiSource(new Client('https://example.test', 'shhh'));
    $fromApi = $apiLoader->load('', $command);

    expect($fromFile)->not->toBeNull()
        ->and($fromApi)->not->toBeNull();

    // Bind each through its own manager and hold both. Two managers are two stores (wordpress-importer-archive#1103),
    // so the arms can be compared side by side — the comparison this test always meant to
    // make. Under the static store this had to bind, read, clear and bind again, and the API
    // arm read its binding back from a manager that had never written it, which only worked
    // because every manager was one slot. Equal values cannot expose a shared store from
    // here; the independence test in tests/Unit/BaseDataManagerTest.php is what does.
    $fileManager = new DataManager;

    $fileManager->addNinjaForm(3, (array) $fromFile);
    $boundFromFile = $fileManager->getNinjaForm(3);

    // The API arm returns a LIST of the site's forms; the handler picks the one whose served
    // `id` matches the reference, which is the step the file path has no counterpart for.
    $served = ((array) $fromApi)[0] ?? null;

    expect($served)->toBeArray();

    $apiManager = new DataManager;

    $apiManager->addNinjaForm(3, (array) $served);
    $boundFromApi = $apiManager->getNinjaForm(3);

    expect($boundFromFile)->not->toBeNull()
        ->and($boundFromApi)->not->toBeNull();

    $boundFromFile = (array) $boundFromFile;
    $boundFromApi = (array) $boundFromApi;

    // Key ORDER differs between the two, and nothing reads these by position. `addNinjaForm()`
    // lifts the settings envelope with `array_merge()` and then assigns `id`: the file payload
    // has no `id` to overwrite so it is appended last, while the endpoint's already has one and
    // is updated in place. Sorting both is narrower than dropping to a loose comparison, which
    // would also stop `'3'` and `3` being told apart — and the id's TYPE is the thing this
    // slice is about.
    \ksort($boundFromFile);
    \ksort($boundFromApi);

    expect($boundFromApi)->toBe($boundFromFile);
});

it('still warns when a supplied export advertises a different form id', function (): void {
    // AC 5's third clause. The warning sits BELOW the new short-circuit, inside the file-path
    // loop, so a short-circuit placed one line too high would silence it — and nothing else
    // in this file would notice, because every other file-path arm declines before reaching
    // it.
    //
    // The selector is stubbed rather than driven, for the reason given above: the real one
    // cannot be handed a path non-interactively. What is under test here is the HANDLER's
    // loop, which is the part this ticket changed.
    Http::fake([
        '*' => Http::response('should not be called', 500),
    ]);

    $advertising = ninjaSampleForm(3, 'Contact Us');
    unset($advertising['id']);
    $advertisingSettings = $advertising['settings'];
    $advertising['settings'] = \is_array($advertisingSettings)
        ? \array_merge($advertisingSettings, ['embed_form' => '[ninja_form id=41]'])
        : ['embed_form' => '[ninja_form id=41]'];

    $dataManager = new DataManager;

    $selector = new class(new NinjaFormsValidator, new NinjaFormsLoader) extends NinjaFormsSelector
    {
        /**
         * The export this stub hands back, standing in for one the operator supplied.
         *
         * @var array<int|string, mixed>
         */
        public array $supplied = [];

        /**
         * Return the supplied export without prompting.
         *
         * @param  InputInterface  $input  Console input interface
         * @param  OutputInterface  $output  Console output interface
         * @param  Command  $command  Command instance
         * @return array<int|string, mixed>|null The supplied export
         */
        public function select(
            InputInterface $input,
            OutputInterface $output,
            Command $command
        ): ?array {
            // Returned through a nullable local so the signature stays compatible with the
            // parent without level max reporting the null arm as unreachable.
            $supplied = $this->supplied === [] ? null : $this->supplied;

            return $supplied;
        }
    };

    $selector->supplied = $advertising;

    $handler = new UserInteractionHandler(
        new ContentDetector,
        $dataManager,
        $selector,
        new FormIdExtractor,
        new NinjaFormsValidator
    );

    $buffer = new BufferedOutput;

    $input = new ArrayInput([]);
    $input->setInteractive(false);

    $loaded = $handler->handleFormPluginDetection(
        ninjaSourcingParser($this->workingDirectory, [3]),
        $input,
        $buffer,
        ninjaSourcingCommand($buffer)
    );

    $written = $buffer->fetch();

    // Reported, and imported as SUPPLIED rather than as advertised — the importer cannot know
    // which of the two the operator meant, so it says so and does not silently re-file it.
    expect($loaded)->toBeTrue()
        ->and($written)->toContain('advertises')
        ->and($written)->toContain('41')
        ->and($dataManager->getNinjaForm(3))->not->toBeNull()
        ->and($dataManager->getNinjaForm(41))->toBeNull();

    Http::assertNothingSent();
});

it('accepts a numeric-string id and files a non-numeric one nowhere', function (): void {
    // Both halves matter and they fail in opposite directions. A served id can genuinely be a
    // string — the Gravity payload's is `'12'` — so rejecting strings would drop real forms.
    // But casting ANY string files a non-numeric id under key `0`, where it matches no
    // reference and would shadow a real form `0`. The digit test is what separates them, and
    // without this arm it is a branch no input exercises.
    $stringId = ninjaSampleForm(3, 'Contact Us');
    $stringId['id'] = '3';

    $junkId = ninjaSampleForm(0, 'Not A Form');
    $junkId['id'] = 'not-a-number';

    Http::fake([
        '*/forms/ninja*' => Http::response([$stringId, $junkId], 200, ['X-WP-Total' => '2']),
    ]);

    [$handler, $dataManager, $loader] = ninjaSourcingHandler();
    $loader->setMigrationApiSource(new Client('https://example.test', 'shhh'));

    $buffer = new BufferedOutput;

    $input = new ArrayInput([]);
    $input->setInteractive(false);

    $loaded = $handler->handleFormPluginDetection(
        // Form 0 is referenced deliberately: it is the key a bare `(int)` cast would have
        // filed the junk id under, so this reference is what would pick the wrong form up.
        ninjaSourcingParser($this->workingDirectory, [0, 3]),
        $input,
        $buffer,
        ninjaSourcingCommand($buffer)
    );

    $bound = $dataManager->getNinjaForm(3);

    expect($loaded)->toBeTrue()
        ->and($bound)->not->toBeNull()
        ->and($bound)->toMatchArray(['id' => 3, 'title' => 'Contact Us'])
        ->and($dataManager->getNinjaForm(0))->toBeNull();
});
