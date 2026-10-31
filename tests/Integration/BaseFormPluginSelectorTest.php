<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use PHPUnit\Framework\Assert;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi\Client;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\BaseFormPluginSelector;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\BaseFormPluginValidator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Contracts\FileLoaderInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Loaders\MigrationApiFormLoader;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * `BaseFormPluginSelector`, through a minimal plugin: the API path, both prompt paths, and
 * the load and validation every path ends in (#100).
 */
uses(StatamicTestCase::class);

/**
 * A validator that accepts a form carrying a title.
 */
final class BaseFormPluginSelectorTestValidator extends BaseFormPluginValidator
{
    /**
     * A form is one with a title.
     */
    protected function validateFormStructure(mixed $form): bool
    {
        return is_array($form) && isset($form['title']);
    }
}

/**
 * A file loader that records the paths it is asked for and returns the forms it is given.
 */
final class BaseFormPluginSelectorTestLoader implements FileLoaderInterface
{
    /** @var list<string> */
    public array $paths = [];

    /**
     * @param  array<int|string, mixed>|null  $forms  What load() returns
     */
    public function __construct(private readonly ?array $forms, private readonly string $format = 'JSON file') {}

    /** @return array<int|string, mixed>|null */
    public function load(string $filePath, Command $command): ?array
    {
        $this->paths[] = $filePath;

        return $this->forms;
    }

    public function getFileExtension(): string
    {
        return 'json';
    }

    public function getFormatDescription(): string
    {
        return $this->format;
    }
}

/**
 * A migration API loader whose read returns the forms it is given, and records its path.
 */
final class BaseFormPluginSelectorTestApiLoader extends MigrationApiFormLoader
{
    /** @var list<string> */
    public array $paths = [];

    /**
     * @param  array<int|string, mixed>|null  $forms  What load() returns
     */
    public function __construct(private readonly ?array $forms)
    {
        parent::__construct('TestForms', 'test', new BaseFormPluginSelectorTestLoader(null));
    }

    /** @return array<int|string, mixed>|null */
    public function load(string $filePath, Command $command): ?array
    {
        $this->paths[] = $filePath;

        return $this->forms;
    }
}

/**
 * The selector under test, with its protected parts exposed.
 */
final class BaseFormPluginSelectorTestSelector extends BaseFormPluginSelector
{
    public bool $promptsSupported = true;

    public function __construct(FileLoaderInterface $loader)
    {
        parent::__construct(new BaseFormPluginSelectorTestValidator('TestForms'), 'TestForms', $loader);
    }

    protected function getPluginSlug(): string
    {
        return 'test_forms';
    }

    protected function isPromptsSupported(): bool
    {
        return $this->promptsSupported;
    }

    /** @return array<int|string, mixed>|null */
    public function viaSymfony(InputInterface $input, OutputInterface $output, Command $command): ?array
    {
        return $this->selectWithSymfonyConsole($input, $output, $command);
    }

    /** @return array<int|string, mixed>|null */
    public function loadAndValidate(string $filePath, Command $command): ?array
    {
        return $this->loadAndValidateFile($filePath, $command);
    }

    /** @return array{string, string, string, string} */
    public function messages(): array
    {
        return [$this->getPromptMessage(), $this->getHelpText(), $this->getSkipMessage(), $this->getSuccessMessage()];
    }
}

/**
 * Two forms the validator accepts.
 *
 * @return list<array<string, string>>
 */
function baseFormPluginSelectorForms(): array
{
    return [['title' => 'Contact'], ['title' => 'Apply']];
}

/**
 * Run a body inside a real Command::run(), which routes prompts to the input's stream.
 *
 * @param  Closure(InputInterface, OutputInterface, Command): (array<int|string, mixed>|null)  $body  What to run
 * @param  ?string  $answers  The scripted answers, or null for a run that is not interactive
 * @return array{array<int|string, mixed>|null, string} What the body returned, and the run's output
 */
function baseFormPluginSelectorRun(Closure $body, ?string $answers = null): array
{
    $input = new ArrayInput([]);

    if ($answers === null) {
        $input->setInteractive(false);
    } else {
        $stream = fopen('php://memory', 'r+');
        assert(is_resource($stream));
        fwrite($stream, $answers);
        rewind($stream);
        $input->setStream($stream);
    }

    $command = new class($body) extends Command
    {
        protected $signature = 'test:base-form-plugin-selector';

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

    $buffer = new BufferedOutput;
    $command->setLaravel(app());
    $command->run($input, $buffer);

    return [$command->forms, $buffer->fetch()];
}

/**
 * Run a body with the real Laravel Prompts answering the given keys, instead of the
 * fallback a test run uses, which trims each answer before a prompt sees it.
 *
 * fallbackWhen() only ever turns the fallback on, so it is turned off directly and turned
 * back on after, for the tests that rely on it.
 *
 * @param  list<string>  $keys  The keys pressed, in order
 * @param  Closure(): (array<int|string, mixed>|null)  $body  What to run
 * @return array<int|string, mixed>|null What the body returned
 */
function baseFormPluginSelectorWithRealPrompts(array $keys, Closure $body): ?array
{
    // Laravel Prompts throws on Windows before it reads a key, and the command falls back
    // there. With no keys to press the body never reaches a prompt, so it still runs.
    if (\PHP_OS_FAMILY === 'Windows' && $keys !== []) {
        Assert::markTestSkipped('Laravel Prompts does not run on Windows.');
    }

    $fallback = new ReflectionProperty(Prompt::class, 'shouldFallback');
    $fallback->setValue(null, false);
    Prompt::fake($keys);

    try {
        return $body();
    } finally {
        $fallback->setValue(null, true);
    }
}

it('reads from the migration API only when its loader has an API source', function (): void {
    $api = new BaseFormPluginSelectorTestApiLoader(baseFormPluginSelectorForms());
    $withoutSource = new BaseFormPluginSelectorTestSelector($api);
    $file = new BaseFormPluginSelectorTestSelector(new BaseFormPluginSelectorTestLoader(baseFormPluginSelectorForms()));

    expect($file->hasMigrationApiSource())->toBeFalse()
        ->and($withoutSource->hasMigrationApiSource())->toBeFalse();

    $api->setMigrationApiSource(new Client('https://example.test', 'shhh'));

    expect((new BaseFormPluginSelectorTestSelector($api))->hasMigrationApiSource())->toBeTrue();
});

it('reads the definitions from the migration API without asking for a file', function (): void {
    $api = new BaseFormPluginSelectorTestApiLoader(baseFormPluginSelectorForms());
    $api->setMigrationApiSource(new Client('https://example.test', 'shhh'));

    [$forms, $out] = baseFormPluginSelectorRun(
        fn (InputInterface $input, OutputInterface $output, Command $command): ?array => (new BaseFormPluginSelectorTestSelector($api))->select($input, $output, $command),
        "no\n"
    );

    expect($forms)->toBe(baseFormPluginSelectorForms())
        ->and($api->paths)->toBe([''])
        ->and($out)->not->toContain('Would you like to provide');
});

it('returns nothing when the migration API read fails, or serves no usable forms', function (?array $served, string $said): void {
    $api = new BaseFormPluginSelectorTestApiLoader($served);
    $api->setMigrationApiSource(new Client('https://example.test', 'shhh'));

    [$forms, $out] = baseFormPluginSelectorRun(
        fn (InputInterface $input, OutputInterface $output, Command $command): ?array => (new BaseFormPluginSelectorTestSelector($api))->select($input, $output, $command)
    );

    expect($forms)->toBeNull()
        ->and($said === '' ? $out : $out)->toContain($said);

    if ($said === '') {
        expect($out)->not->toContain('no usable');
    }
})->with([
    'the read failed' => [null, ''],
    'no form has a title' => [[['name' => 'untitled']], 'The migration API returned no usable TestForms form definitions.'],
]);

it('asks for an export through Laravel Prompts, and loads the one named', function (string $answers): void {
    $loader = new BaseFormPluginSelectorTestLoader(baseFormPluginSelectorForms());

    [$forms, $out] = baseFormPluginSelectorRun(
        fn (InputInterface $input, OutputInterface $output, Command $command): ?array => (new BaseFormPluginSelectorTestSelector($loader))->select($input, $output, $command),
        $answers
    );

    expect($forms)->toBe(baseFormPluginSelectorForms())
        ->and($loader->paths)->toBe(['/exports/forms.json'])
        ->and($out)->toContain('Would you like to provide a TestForms export file to create Statamic forms?')
        ->and($out)->toContain('Enter the path to your TestForms export file:')
        ->and($out)->toContain('Successfully loaded TestForms export file with 2 forms.');
})->with([
    'a yes' => ["yes\n/exports/forms.json\n"],
    'the default, by an empty answer' => ["\n/exports/forms.json\n"],
]);

it('loads nothing when the Laravel Prompts answer declines, or names a blank path', function (string $answers): void {
    $loader = new BaseFormPluginSelectorTestLoader(baseFormPluginSelectorForms());

    [$forms] = baseFormPluginSelectorRun(
        fn (InputInterface $input, OutputInterface $output, Command $command): ?array => (new BaseFormPluginSelectorTestSelector($loader))->select($input, $output, $command),
        $answers
    );

    expect($forms)->toBeNull()
        ->and($loader->paths)->toBe([]);
})->with([
    'declined' => ["no\n"],
]);

it('loads nothing when the path typed into Laravel Prompts is only spaces', function (): void {
    $loader = new BaseFormPluginSelectorTestLoader(baseFormPluginSelectorForms());

    // The fallback a test run uses trims the answer, which a required prompt then refuses,
    // so the real prompts are driven here: they pass spaces through to the selector
    [$forms] = baseFormPluginSelectorRun(fn (InputInterface $input, OutputInterface $output, Command $command): ?array => baseFormPluginSelectorWithRealPrompts(
        [Key::ENTER, ' ', ' ', ' ', Key::ENTER],
        fn (): ?array => (new BaseFormPluginSelectorTestSelector($loader))->select($input, $output, $command)
    ), '');

    expect($forms)->toBeNull()
        ->and($loader->paths)->toBe([]);
});

it('asks again through Laravel Prompts when the path is left empty', function (): void {
    $loader = new BaseFormPluginSelectorTestLoader(baseFormPluginSelectorForms());

    [$forms] = baseFormPluginSelectorRun(fn (InputInterface $input, OutputInterface $output, Command $command): ?array => baseFormPluginSelectorWithRealPrompts(
        [Key::ENTER, Key::ENTER, ...str_split('/exports/forms.json'), Key::ENTER],
        fn (): ?array => (new BaseFormPluginSelectorTestSelector($loader))->select($input, $output, $command)
    ), '');

    expect($forms)->toBe(baseFormPluginSelectorForms())
        ->and($loader->paths)->toBe(['/exports/forms.json']);
});

it('refuses a path of only spaces in the Symfony questions\' validator', function (): void {
    $loader = new BaseFormPluginSelectorTestLoader(baseFormPluginSelectorForms());

    // The validator sees spaces only from a prompt that does not trim them, which is what
    // the real text prompt does when this method is reached interactively
    [$forms, $out] = baseFormPluginSelectorRun(fn (InputInterface $input, OutputInterface $output, Command $command): ?array => baseFormPluginSelectorWithRealPrompts(
        [Key::ENTER, ' ', ' ', Key::ENTER, ...str_split('/exports/forms.json'), Key::ENTER],
        fn (): ?array => (new BaseFormPluginSelectorTestSelector($loader))->viaSymfony($input, $output, $command)
    ), '');

    expect($forms)->toBe(baseFormPluginSelectorForms())
        ->and($loader->paths)->toBe(['/exports/forms.json'])
        ->and($out)->toContain('File path cannot be empty.');
});

it('asks through Symfony questions where Laravel Prompts is not supported, and loads the one named', function (string $answers): void {
    $loader = new BaseFormPluginSelectorTestLoader(baseFormPluginSelectorForms());
    $selector = new BaseFormPluginSelectorTestSelector($loader);
    $selector->promptsSupported = false;

    [$forms, $out] = baseFormPluginSelectorRun(
        fn (InputInterface $input, OutputInterface $output, Command $command): ?array => $selector->select($input, $output, $command),
        $answers
    );

    expect($forms)->toBe(baseFormPluginSelectorForms())
        ->and($loader->paths)->toBe(['/exports/forms.json'])
        ->and($out)->toContain('Would you like to provide a TestForms export file to create Statamic forms?');
})->with([
    'a yes' => ["yes\n/exports/forms.json\n"],
    'the default, by an empty answer' => ["\n/exports/forms.json\n"],
]);

it('asks again through Symfony questions when the path is left blank', function (): void {
    $loader = new BaseFormPluginSelectorTestLoader(baseFormPluginSelectorForms());

    [$forms, $out] = baseFormPluginSelectorRun(
        fn (InputInterface $input, OutputInterface $output, Command $command): ?array => (new BaseFormPluginSelectorTestSelector($loader))->viaSymfony($input, $output, $command),
        "yes\n   \n/exports/forms.json\n"
    );

    expect($forms)->toBe(baseFormPluginSelectorForms())
        ->and($out)->toContain('File path cannot be empty.');
});

it('loads nothing when the Symfony answer declines, or the run is not interactive', function (?string $answers): void {
    $loader = new BaseFormPluginSelectorTestLoader(baseFormPluginSelectorForms());

    [$forms] = baseFormPluginSelectorRun(
        fn (InputInterface $input, OutputInterface $output, Command $command): ?array => (new BaseFormPluginSelectorTestSelector($loader))->viaSymfony($input, $output, $command),
        $answers
    );

    expect($forms)->toBeNull()
        ->and($loader->paths)->toBe([]);
})->with([
    'declined' => ["no\n"],
    'not interactive' => [null],
]);

it('says the export is invalid, and logs it, when no form in it has a title', function (): void {
    $logged = [];
    Log::shouldReceive('error')->andReturnUsing(function (string $message, array $context = []) use (&$logged): void {
        $logged[] = [$message, $context];
    });
    $loader = new BaseFormPluginSelectorTestLoader([['name' => 'untitled']]);

    [$forms, $out] = baseFormPluginSelectorRun(
        fn (InputInterface $input, OutputInterface $output, Command $command): ?array => (new BaseFormPluginSelectorTestSelector($loader))->loadAndValidate('/exports/bad.json', $command)
    );

    expect($forms)->toBeNull()
        ->and($out)->toContain('Invalid TestForms data structure in file: /exports/bad.json')
        ->and($out)->not->toContain('Successfully loaded')
        ->and($logged)->toBe([['TestForms\\Selector::loadAndValidateFile: Invalid TestForms data structure', ['file_path' => '/exports/bad.json']]]);
});

it('returns nothing, and says nothing, when the export does not load', function (): void {
    $loader = new BaseFormPluginSelectorTestLoader(null);

    [$forms, $out] = baseFormPluginSelectorRun(
        fn (InputInterface $input, OutputInterface $output, Command $command): ?array => (new BaseFormPluginSelectorTestSelector($loader))->loadAndValidate('/exports/missing.json', $command)
    );

    expect($forms)->toBeNull()
        ->and($out)->not->toContain('Invalid')
        ->and($out)->not->toContain('Successfully loaded');
});

it('words its messages for the plugin and its export format', function (string $format, string $help): void {
    [$prompt, $helpText, $skip, $success] = (new BaseFormPluginSelectorTestSelector(new BaseFormPluginSelectorTestLoader(null, $format)))->messages();

    expect($prompt)->toBe("TestForms blocks or shortcodes detected. Please provide the path to your TestForms forms export {$format} (or press Enter to skip):")
        ->and($helpText)->toBe($help)
        ->and($skip)->toBe('Skipping TestForms forms import.')
        ->and($success)->toBe('TestForms forms data loaded successfully.');
})->with([
    'an XML file' => ['XML file', 'This should be an XML file exported from TestForms containing all your forms data.'],
    'a JSON file' => ['JSON file', 'This should be a JSON file exported from TestForms containing all your forms data.'],
]);
