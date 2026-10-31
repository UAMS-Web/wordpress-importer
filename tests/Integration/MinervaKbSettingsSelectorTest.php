<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings\Extractor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings\Loader;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings\Prompter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings\Selector;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings\Validator;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;
use UAMSWeb\WordPressImporter\Tests\Support\LfBufferedOutput;

/**
 * The MinervaKB settings `Selector` reads the settings from the migration API, or from the file
 * its prompter asks for, and keeps them only when they load and validate (#100).
 */
uses(StatamicTestCase::class);

/**
 * A prompter that answers with a path it was given, and counts the times it is asked.
 */
final class MinervaKbSettingsSelectorTestPrompter extends Prompter
{
    public int $asked = 0;

    public function __construct(private readonly ?string $path) {}

    public function promptForSettingsFile(InputInterface $input, OutputInterface $output, Command $command): ?string
    {
        $this->asked++;

        return $this->path;
    }
}

/**
 * A loader that answers with settings it was given, from the API or not, and records the paths.
 */
final class MinervaKbSettingsSelectorTestLoader extends Loader
{
    /** @var list<string> */
    public array $paths = [];

    /**
     * @param  array<int|string, mixed>|null  $settings
     */
    public function __construct(private readonly ?array $settings, private readonly bool $api = false) {}

    public function hasMigrationApiSource(): bool
    {
        return $this->api;
    }

    public function loadSettings(string $filePath, OutputInterface $output): ?array
    {
        $this->paths[] = $filePath;

        return $this->settings;
    }
}

/**
 * A validator that answers as it was told, and records the settings it was given.
 */
final class MinervaKbSettingsSelectorTestValidator extends Validator
{
    /** @var list<array<int|string, mixed>> */
    public array $validated = [];

    public function __construct(private readonly bool $valid = true) {}

    public function validateSettings(array $settings, OutputInterface $output): bool
    {
        $this->validated[] = $settings;

        return $this->valid;
    }
}

/**
 * Run select() for the given collaborators.
 *
 * @return array{array<int|string, mixed>|null, string} What select() returned, and its output
 */
function minervaKbSettingsSelectorRun(Prompter $prompter, Loader $loader, Validator $validator): array
{
    $input = new ArrayInput([]);
    $input->setInteractive(false);
    $output = new LfBufferedOutput;

    $settings = (new Selector($prompter, $loader, $validator, new Extractor))->select($input, $output, new Command);

    return [$settings, $output->fetch()];
}

it('reads the settings from the migration API without asking for a file', function (): void {
    $prompter = new MinervaKbSettingsSelectorTestPrompter('/settings.json');
    $loader = new MinervaKbSettingsSelectorTestLoader(['search_title' => 'Search'], true);
    $validator = new MinervaKbSettingsSelectorTestValidator;

    [$settings, $out] = minervaKbSettingsSelectorRun($prompter, $loader, $validator);

    expect($settings)->toBe(['search_title' => 'Search'])
        ->and($prompter->asked)->toBe(0)
        ->and($loader->paths)->toBe([''])
        ->and($validator->validated)->toBe([['search_title' => 'Search']])
        ->and($out)->toBe("Successfully loaded MinervaKB settings from the migration API.\n");
});

it('keeps nothing from the migration API that is empty or does not validate', function (?array $served, bool $valid): void {
    [$settings, $out] = minervaKbSettingsSelectorRun(
        new MinervaKbSettingsSelectorTestPrompter('/settings.json'),
        new MinervaKbSettingsSelectorTestLoader($served, true),
        new MinervaKbSettingsSelectorTestValidator($valid)
    );

    expect($settings)->toBeNull()
        ->and($out)->toBe('');
})->with([
    'not loaded' => [null, true],
    'empty' => [[], true],
    'not valid' => [['search_title' => 'Search'], false],
]);

it('reads the settings from the file it asks for', function (): void {
    $loader = new MinervaKbSettingsSelectorTestLoader(['search_title' => 'Search']);
    $validator = new MinervaKbSettingsSelectorTestValidator;

    [$settings, $out] = minervaKbSettingsSelectorRun(new MinervaKbSettingsSelectorTestPrompter('/settings.json'), $loader, $validator);

    expect($settings)->toBe(['search_title' => 'Search'])
        ->and($loader->paths)->toBe(['/settings.json'])
        ->and($validator->validated)->toBe([['search_title' => 'Search']])
        ->and($out)->toBe("Successfully loaded MinervaKB settings from: /settings.json\n");
});

it('skips the settings when no file is given', function (?string $path): void {
    $loader = new MinervaKbSettingsSelectorTestLoader(['search_title' => 'Search']);

    [$settings, $out] = minervaKbSettingsSelectorRun(new MinervaKbSettingsSelectorTestPrompter($path), $loader, new MinervaKbSettingsSelectorTestValidator);

    expect($settings)->toBeNull()
        ->and($loader->paths)->toBe([])
        ->and($out)->toBe("Skipping MinervaKB settings configuration.\n");
})->with(['none' => [null], 'an empty path' => ['']]);

it('keeps nothing from a file that is empty or does not validate', function (?array $loaded, bool $valid): void {
    [$settings, $out] = minervaKbSettingsSelectorRun(
        new MinervaKbSettingsSelectorTestPrompter('/settings.json'),
        new MinervaKbSettingsSelectorTestLoader($loaded),
        new MinervaKbSettingsSelectorTestValidator($valid)
    );

    expect($settings)->toBeNull()
        ->and($out)->toBe('');
})->with([
    'not loaded' => [null, true],
    'empty' => [[], true],
    'not valid' => [['search_title' => 'Search'], false],
]);

it('hands knowledge base extraction to its extractor', function (): void {
    $extractor = new class extends Extractor
    {
        /** @var list<array{array<int|string, mixed>, string}> */
        public array $calls = [];

        public function extractKnowledgeBaseSettings(array $settings, string $siteName): array
        {
            $this->calls[] = [$settings, $siteName];

            return ['title' => 'Extracted'];
        }
    };

    $selector = new Selector(new Prompter, new Loader, new Validator, $extractor);

    expect($selector->extractKnowledgeBaseSettings(['search_title' => 'Search'], 'Research'))->toBe(['title' => 'Extracted'])
        ->and($extractor->calls)->toBe([[['search_title' => 'Search'], 'Research']]);
});
