<?php

declare(strict_types=1);

use App\Services\BardContentSanitizer;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\Aether\WordPressImporter\Console\Commands\ImportWordPress;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\DeferredProcessing\DeferredProcessingCoordinator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormPluginCoordinator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\MigrationApi\Client;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\MigrationApi\MaterializationScope;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\MigrationApi\MigrationApiException;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\MigrationApi\WxrMaterializer;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\Loaders\FormidableFormsLoader;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\Loaders\GravityFormsLoader;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\Loaders\NinjaFormsLoader;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings\Loader as MinervaKbSettingsLoader;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\NavigationMenuSelector;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\ReusableBlocks\ReusableBlockCoordinator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\SiteManager;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

// The sanitizer is the host app's; standalone, the package's stub stands in for it
if (! class_exists(BardContentSanitizer::class)) {
    require_once __DIR__.'/../Support/IdeStubs/App/Services/BardContentSanitizer.php';
}

/**
 * Tests how the `ImportWordPress` command sources content from the migration API: reading and
 * checking `--site` and `--minerva-generation`, asking for the network and its key at the
 * prompt or taking them from `--network` and standard input, pointing every settings and forms
 * loader at the client, materializing the parts, and reporting what the API does not serve and
 * what each redirect plugin served.
 *
 * The command is built by the container, so its options are its own, and is a subclass that
 * stands in for the materializer and for standard input. The loaders it hands the client to
 * are recording stand-ins. Nothing reaches the network.
 *
 * @command  php vendor/bin/pest tests/Integration/ImportWordPressApiSourcingTest.php
 */

/**
 * A materializer that records what it is asked to read and answers as told, without reading.
 */
final class IwasMaterializer extends WxrMaterializer
{
    /**
     * Each read, as the site, the modified-after bound and the content type.
     *
     * @var list<array{?int, ?string, ?string}>
     */
    public array $reads = [];

    /**
     * The client it was built over.
     */
    public Client $client;

    /**
     * The failure to throw, or null to answer the parts.
     */
    public ?MigrationApiException $failure = null;

    /**
     * Whether the scope was clear when the read began.
     */
    public ?bool $scopeClear = null;

    /**
     * Create the materializer over the client and the directory.
     */
    public function __construct(Client $client, public string $destination)
    {
        parent::__construct($client, $destination);
        $this->client = $client;
    }

    /**
     * {@inheritDoc}
     */
    public function materialize(?int $site = null, ?string $modifiedAfter = null, array $consumedOptions = [], ?string $contentType = null): array
    {
        $this->reads[] = [$site, $modifiedAfter, $contentType];
        $this->scopeClear = ! MaterializationScope::isScoped();

        // Report one page, as a real read does through the client
        $onPage = (new ReflectionProperty(Client::class, 'onPage'))->getValue($this->client);

        if ($onPage instanceof Closure) {
            $onPage('  page 1 read');
        }

        if ($this->failure instanceof MigrationApiException) {
            throw $this->failure;
        }

        return [$this->destination.'/wordpress-export.001.xml', $this->destination.'/wordpress-export.002.xml'];
    }

    /**
     * {@inheritDoc}
     */
    public function unservedContent(): array
    {
        return [['kind' => 'post type', 'name' => 'event', 'records' => 3]];
    }

    /**
     * {@inheritDoc}
     */
    public function redirectPluginRules(): array
    {
        return [['host' => 'old.test', 'plugin' => 'Redirection', 'rules' => 4, 'written' => 3, 'unsupported' => ['regular expressions' => 1]]];
    }
}

/**
 * The command, standing in for the materializer and for standard input.
 */
final class IwasCommand extends ImportWordPress
{
    /**
     * The materializer built, once one is.
     */
    public ?IwasMaterializer $materializer = null;

    /**
     * The failure the materializer is to throw, or null for none.
     */
    public ?MigrationApiException $failure = null;

    /**
     * What standard input holds.
     */
    public string $stdin = '';

    /**
     * {@inheritDoc}
     */
    protected function makeMaterializer(Client $client, string $destination): WxrMaterializer
    {
        $this->materializer = new IwasMaterializer($client, $destination);
        $this->materializer->failure = $this->failure;

        return $this->materializer;
    }

    /**
     * {@inheritDoc}
     */
    protected function apiKeyStream()
    {
        $stream = fopen('php://memory', 'r+');
        assert(is_resource($stream));
        fwrite($stream, $this->stdin);
        rewind($stream);

        return $stream;
    }
}

/**
 * Records the client and site a loader is pointed at.
 */
trait IwasRecordsSource
{
    /**
     * Each source set, as the client, the site and the generation.
     *
     * @var list<array{Client, ?int, ?string}>
     */
    public array $sources = [];
}

/**
 * A MinervaKB settings loader that records its source.
 */
final class IwasMinerva extends MinervaKbSettingsLoader
{
    use IwasRecordsSource;

    /**
     * {@inheritDoc}
     */
    public function setMigrationApiSource(Client $client, ?int $site = null, ?string $generation = null): void
    {
        $this->sources[] = [$client, $site, $generation];
    }
}

/**
 * A navigation selector that records its source.
 */
final class IwasNavigation extends NavigationMenuSelector
{
    use IwasRecordsSource;

    /**
     * {@inheritDoc}
     */
    public function setMigrationApiSource(Client $client, ?int $site = null): void
    {
        $this->sources[] = [$client, $site, null];
    }
}

/**
 * A Gravity Forms loader that records its source.
 */
final class IwasGravity extends GravityFormsLoader
{
    use IwasRecordsSource;

    /**
     * {@inheritDoc}
     */
    public function setMigrationApiSource(Client $client, ?int $site = null): void
    {
        $this->sources[] = [$client, $site, null];
    }
}

/**
 * A Ninja Forms loader that records its source.
 */
final class IwasNinja extends NinjaFormsLoader
{
    use IwasRecordsSource;

    /**
     * {@inheritDoc}
     */
    public function setMigrationApiSource(Client $client, ?int $site = null): void
    {
        $this->sources[] = [$client, $site, null];
    }
}

/**
 * A Formidable Forms loader that records its source.
 */
final class IwasFormidable extends FormidableFormsLoader
{
    use IwasRecordsSource;

    /**
     * {@inheritDoc}
     */
    public function setMigrationApiSource(Client $client, ?int $site = null): void
    {
        $this->sources[] = [$client, $site, null];
    }
}

beforeEach(function (): void {
    MaterializationScope::clear();
    $this->root = sys_get_temp_dir().'/iwas-'.bin2hex(random_bytes(6));
    mkdir($this->root);
});

afterEach(function (): void {
    MaterializationScope::clear();
    exec('rm -rf '.escapeshellarg($this->root));
});

/**
 * The command, built by the container with recording loaders, its input made from the given
 * arguments and options and answering prompts from the given lines, and the buffer behind its
 * output.
 *
 * @param  array<string, mixed>  $parameters  The arguments and options, as `ArrayInput` takes them
 * @return array{IwasCommand, BufferedOutput, array<string, object>}
 */
function iwasCommand(array $parameters = [], string $answers = '', bool $interactive = true): array
{
    $command = app(IwasCommand::class);
    $loaders = [
        'minervaKbSettingsLoader' => new IwasMinerva,
        'navigationMenuSelector' => (new ReflectionClass(IwasNavigation::class))->newInstanceWithoutConstructor(),
        'gravityFormsLoader' => new IwasGravity,
        'ninjaFormsLoader' => new IwasNinja,
        'formidableFormsLoader' => new IwasFormidable,
    ];

    foreach ($loaders as $property => $loader) {
        (new ReflectionProperty(ImportWordPress::class, $property))->setValue($command, $loader);
    }

    $stream = fopen('php://memory', 'r+');
    assert(is_resource($stream));
    fwrite($stream, $answers);
    rewind($stream);

    $input = new ArrayInput($parameters, $command->getDefinition());
    $input->setStream($stream);
    $input->setInteractive($interactive);
    $buffer = new BufferedOutput;
    $command->setInput($input);
    $command->setOutput(new OutputStyle($input, $buffer));

    return [$command, $buffer, $loaders];
}

/**
 * Call one of the command's private methods.
 */
function iwasCall(ImportWordPress $command, string $method, mixed ...$arguments): mixed
{
    return (new ReflectionMethod(ImportWordPress::class, $method))->invoke($command, ...$arguments);
}

/**
 * The buffer's output, with plain line endings.
 */
function iwasOutput(BufferedOutput $buffer): string
{
    return str_replace(PHP_EOL, "\n", $buffer->fetch());
}

/**
 * The address a client reads from.
 */
function iwasBaseUrl(mixed $client): ?string
{
    if (! $client instanceof Client) {
        return null;
    }

    $url = (new ReflectionProperty(Client::class, 'baseUrl'))->getValue($client);

    return is_string($url) ? $url : null;
}

/**
 * The key a client sends.
 */
function iwasKey(mixed $client): ?string
{
    if (! $client instanceof Client) {
        return null;
    }

    $key = (new ReflectionProperty(Client::class, 'secret'))->getValue($client);

    return is_string($key) ? $key : null;
}

/**
 * The sources a recording loader was given.
 *
 * @return list<array{Client, ?int, ?string}>
 */
function iwasSources(object $loader): array
{
    $sources = property_exists($loader, 'sources') ? $loader->sources : [];

    /** @var list<array{Client, ?int, ?string}> $sources */
    return $sources;
}

/**
 * The parameters of a run that supplies the network and key without a prompt.
 *
 * @param  array<string, mixed>  $more  More arguments and options
 * @return array<string, mixed>
 */
function iwasUnattended(array $more = []): array
{
    return ['--network' => 'https://network.test', '--key-from-stdin' => true] + $more;
}

it('reads no MinervaKB generation when none is declared', function (array $parameters): void {
    /** @var array<string, mixed> $parameters */
    [$command, $buffer] = iwasCommand($parameters);

    expect(iwasCall($command, 'resolveMinervaGeneration'))->toBeNull()
        ->and(iwasOutput($buffer))->toBe('');
})->with([
    'no option' => [[]],
    'an empty option' => [['--minerva-generation' => '']],
]);

it('reads a declared MinervaKB generation in any case', function (string $declared, string $generation): void {
    [$command] = iwasCommand(['--minerva-generation' => $declared]);

    expect(iwasCall($command, 'resolveMinervaGeneration'))->toBe($generation);
})->with([
    ['v1', 'v1'],
    ['V2', 'v2'],
]);

it('refuses a MinervaKB generation it does not know', function (): void {
    [$command, $buffer] = iwasCommand(['--minerva-generation' => 'v3']);

    expect(iwasCall($command, 'resolveMinervaGeneration'))->toBeFalse()
        ->and(iwasOutput($buffer))->toBe("The --minerva-generation option must be one of: v1, v2, got 'v3'.\n");
});

it('materializes the site into the named directory, pointing every loader at the API', function (): void {
    $destination = $this->root.'/nested/parts';
    [$command, $buffer, $loaders] = iwasCommand(iwasUnattended([
        'file' => $destination,
        '--site' => '12',
        '--modified-after' => '2026-01-01',
        '--content-type' => '  page ',
        '--minerva-generation' => 'V2',
    ]));
    $command->stdin = "secret-key\n";
    MaterializationScope::set(['page']);

    $parts = iwasCall($command, 'materializeFromApi');

    expect($parts)->toBe([$destination.'/wordpress-export.001.xml', $destination.'/wordpress-export.002.xml'])
        ->and(is_dir($destination))->toBeTrue()
        ->and(fileperms($destination) & 0o777)->toBe(\PHP_OS_FAMILY === 'Windows' ? 0o777 : 0o775 & ~umask())
        ->and($command->materializer?->reads)->toBe([[12, '2026-01-01', 'page']])
        ->and($command->materializer?->scopeClear)->toBeTrue()
        ->and(iwasBaseUrl($command->materializer?->client))->toBe('https://network.test')
        ->and(iwasKey($command->materializer?->client))->toBe('secret-key')
        ->and(iwasOutput($buffer))->toBe(
            "  Reading: https://network.test\n"
            ."Reading the migration API...\n"
            ."Writing WXR parts to {$destination}\n"
            ."  page 1 read\n"
            ."3 record(s) on this site will NOT be imported: the API does not serve them.\n"
            ."      post type      event                    3 record(s)\n"
            ."      Usually this means the plugin registering that type is not active on the site being read. See docs/migration-api-contract.md.\n"
            ."Redirection on old.test: 4 rule(s) served, 3 written as redirect rules.\n"
            ."      The redirect domains collection cannot express:\n"
            ."      regular expressions                                          1 rule(s)\n"
            ."Materialized 2 WXR part(s) into {$destination}\n"
        );

    foreach ($loaders as $name => $loader) {
        $sources = iwasSources($loader);
        $generation = $name === 'minervaKbSettingsLoader' ? 'v2' : null;

        expect(count($sources))->toBe(1, $name);

        [$client, $site, $declared] = $sources[0];

        expect(iwasBaseUrl($client))->toBe('https://network.test', $name)
            ->and($site)->toBe(12, $name)
            ->and($declared)->toBe($generation, $name);
    }
});

it('reads the whole site, unbounded, into a new temporary directory when nothing narrows it', function (array $more): void {
    /** @var array<string, mixed> $more */
    [$command, $buffer, $loaders] = iwasCommand(iwasUnattended($more));
    $command->stdin = "secret-key\n";

    $parts = iwasCall($command, 'materializeFromApi');
    $destination = $command->materializer instanceof IwasMaterializer ? $command->materializer->destination : '';

    expect($destination)->toStartWith(sys_get_temp_dir().DIRECTORY_SEPARATOR.'wordpress-import-')
        ->and(is_dir($destination))->toBeTrue()
        ->and($parts)->toBe([$destination.'/wordpress-export.001.xml', $destination.'/wordpress-export.002.xml'])
        ->and($command->materializer?->reads)->toBe([[null, null, null]])
        ->and(iwasSources($loaders['minervaKbSettingsLoader']))->toHaveCount(1)
        ->and(array_key_exists(1, iwasSources($loaders['minervaKbSettingsLoader'])[0] ?? []) ? iwasSources($loaders['minervaKbSettingsLoader'])[0][1] : 'unset')->toBeNull();

    rmdir($destination);
})->with([
    'no options' => [[]],
    'empty options' => [['file' => '', '--site' => '', '--modified-after' => '', '--content-type' => '   ']],
]);

it('materializes into a directory that already exists, without making it again', function (): void {
    [$command] = iwasCommand(iwasUnattended(['file' => $this->root]));
    $command->stdin = "secret-key\n";

    set_error_handler(static function (int $level, string $message): never {
        throw new ErrorException($message, 0, $level);
    });

    try {
        $parts = iwasCall($command, 'materializeFromApi');
    } finally {
        restore_error_handler();
    }

    expect($parts)->toBe([$this->root.'/wordpress-export.001.xml', $this->root.'/wordpress-export.002.xml']);
});

it('refuses a directory it cannot create', function (): void {
    file_put_contents($this->root.'/taken', 'a file');
    [$command, $buffer] = iwasCommand(iwasUnattended(['file' => $this->root.'/taken']));
    $command->stdin = "secret-key\n";

    expect(@iwasCall($command, 'materializeFromApi'))->toBeNull()
        ->and($command->materializer)->toBeNull()
        ->and(iwasOutput($buffer))->toBe("Could not create the directory '{$this->root}/taken' for materialized parts.\n");
});

it('refuses a site that is not a positive whole number, before asking for the network', function (string $site): void {
    [$command, $buffer] = iwasCommand(iwasUnattended(['file' => $this->root, '--site' => $site]));

    expect(iwasCall($command, 'materializeFromApi'))->toBeNull()
        ->and(iwasOutput($buffer))->toBe("The --site option must be a positive whole number (the network blog id), got '{$site}'.\n");
})->with(['uams-health', '0', '07', '3.5', '+3', '1e3', ' 3']);

it('refuses an unknown MinervaKB generation before asking for the network', function (): void {
    [$command, $buffer] = iwasCommand(iwasUnattended(['file' => $this->root, '--minerva-generation' => 'v3']));

    expect(iwasCall($command, 'materializeFromApi'))->toBeNull()
        ->and(iwasOutput($buffer))->toBe("The --minerva-generation option must be one of: v1, v2, got 'v3'.\n");
});

it('stops when the network cannot be set up', function (): void {
    [$command, $buffer] = iwasCommand(['file' => $this->root], '', false);

    expect(iwasCall($command, 'materializeFromApi'))->toBeNull()
        ->and($command->materializer)->toBeNull()
        ->and(iwasOutput($buffer))->toStartWith('Reading the migration API needs the network address');
});

it('reports a migration API failure as itself', function (): void {
    [$command, $buffer] = iwasCommand(iwasUnattended(['file' => $this->root]));
    $command->stdin = "secret-key\n";
    $command->failure = new MigrationApiException('the API answered 500');

    expect(iwasCall($command, 'materializeFromApi'))->toBeNull()
        ->and(iwasOutput($buffer))->toEndWith("  page 1 read\nCould not read the migration API: the API answered 500\n");
});

it('refuses to ask for the network without a terminal, naming the way round it', function (): void {
    [$command, $buffer] = iwasCommand([], "https://network.test\nsecret-key\n", false);

    expect(iwasCall($command, 'askForNetwork'))->toBeNull()
        ->and(iwasOutput($buffer))->toBe(
            'Reading the migration API needs the network address and its API key, and both are asked at the prompt rather than stored. '
            .'Re-run without --no-interaction, or supply them without a terminal: pass the address as --network=https://... '
            ."and the key on the first line of standard input with --key-from-stdin.\n"
        );
});

it('asks for the network and its key, echoing the network before the key', function (): void {
    [$command, $buffer] = iwasCommand([], "https://network.test\nsecret-key\n");

    $client = iwasCall($command, 'askForNetwork');
    $output = iwasOutput($buffer);

    expect(iwasBaseUrl($client))->toBe('https://network.test')
        ->and(iwasKey($client))->toBe('secret-key')
        ->and($output)->toContain('Which network? Paste its WordPress address, including https://')
        ->and($output)->toContain("  Reading: https://network.test\n")
        ->and(strpos($output, '  Reading: https://network.test'))->toBeLessThan((int) strpos($output, "Paste that network's migration API key"));
});

it('takes the network from --network and asks only for the key', function (): void {
    [$command, $buffer] = iwasCommand(['--network' => '  https://network.test  '], "secret-key\n");

    $client = iwasCall($command, 'askForNetwork');
    $output = iwasOutput($buffer);

    expect(iwasBaseUrl($client))->toBe('https://network.test')
        ->and($output)->not->toContain('Which network?')
        ->and($output)->toContain("  Reading: https://network.test\n");
});

it('asks for the network when --network is blank', function (): void {
    [$command, $buffer] = iwasCommand(['--network' => '   '], "https://network.test\nsecret-key\n");

    expect(iwasBaseUrl(iwasCall($command, 'askForNetwork')))->toBe('https://network.test')
        ->and(iwasOutput($buffer))->toContain('Which network?');
});

it('reports each retry the client makes as a warning', function (array $parameters, string $answers, string $stdin): void {
    /** @var array<string, mixed> $parameters */
    [$command, $buffer] = iwasCommand($parameters, $answers);
    $command->stdin = $stdin;

    $client = iwasCall($command, 'askForNetwork');
    $buffer->fetch();
    $onRetry = $client instanceof Client ? (new ReflectionProperty(Client::class, 'onRetry'))->getValue($client) : null;

    expect($onRetry)->toBeInstanceOf(Closure::class);

    if ($onRetry instanceof Closure) {
        $onRetry('  retrying in 2s');
    }

    expect(iwasOutput($buffer))->toBe("  retrying in 2s\n");
})->with([
    'at the prompt' => [[], "https://network.test\nsecret-key\n", ''],
    'from standard input' => [iwasUnattended(), '', "secret-key\n"],
]);

it('refuses an empty answer for the network, or for its key', function (string $answers, string $error): void {
    [$command, $buffer] = iwasCommand([], $answers);

    expect(iwasCall($command, 'askForNetwork'))->toBeNull()
        ->and(iwasOutput($buffer))->toContain($error);
})->with([
    'no network' => ["\nsecret-key\n", 'Could not read the migration API: The migration API address must be an absolute `https://` URL, got ""'],
    'no key' => ["https://network.test\n\n", 'Could not read the migration API: No migration API key was supplied.'],
]);

it('refuses a network the client will not send a key to', function (): void {
    [$command, $buffer] = iwasCommand([], "http://network.test\nsecret-key\n");

    expect(iwasCall($command, 'askForNetwork'))->toBeNull()
        ->and(iwasOutput($buffer))->toContain('Could not read the migration API: The migration API address must be an absolute `https://` URL');
});

it('reads the key from standard input when asked to, with no prompt', function (): void {
    [$command, $buffer] = iwasCommand(iwasUnattended(), '', false);
    $command->stdin = "secret-key\r\n";

    $client = iwasCall($command, 'askForNetwork');

    expect(iwasBaseUrl($client))->toBe('https://network.test')
        ->and(iwasKey($client))->toBe('secret-key')
        ->and(iwasOutput($buffer))->toBe("  Reading: https://network.test\n");
});

it('refuses to read a key from standard input with no network to pair it with', function (string $network): void {
    [$command, $buffer] = iwasCommand(['--key-from-stdin' => true, '--network' => $network]);
    $command->stdin = "secret-key\n";

    expect(iwasCall($command, 'askForNetwork'))->toBeNull()
        ->and(iwasOutput($buffer))->toBe("--key-from-stdin needs the network it belongs to. Pass the address as --network=https://..., since a key from one network authenticates against no other.\n");
})->with(['', '   ']);

it('refuses to read a key from standard input when --network is not given', function (): void {
    [$command, $buffer] = iwasCommand(['--key-from-stdin' => true]);
    $command->stdin = "secret-key\n";

    expect(iwasCall($command, 'askForNetwork'))->toBeNull()
        ->and(iwasOutput($buffer))->toStartWith('--key-from-stdin needs the network it belongs to.');
});

it('reports a key that does not arrive on standard input', function (): void {
    [$command, $buffer] = iwasCommand(iwasUnattended());

    expect(iwasCall($command, 'askForNetwork'))->toBeNull()
        ->and(iwasOutput($buffer))->toBe(
            "  Reading: https://network.test\n"
            ."Could not read the migration API: No migration API key arrived on standard input. --key-from-stdin expects the key on the first line; nothing was received before the stream ended.\n"
        );
});

it('reads the standard input stream by default', function (): void {
    $command = (new ReflectionClass(ImportWordPress::class))->newInstanceWithoutConstructor();

    expect((new ReflectionMethod(ImportWordPress::class, 'apiKeyStream'))->invoke($command))->toBe(STDIN);
});

it('builds the materializer over the client and the directory', function (): void {
    $command = (new ReflectionClass(ImportWordPress::class))->newInstanceWithoutConstructor();
    $client = new Client('https://network.test', 'secret-key');

    $materializer = (new ReflectionMethod(ImportWordPress::class, 'makeMaterializer'))->invoke($command, $client, $this->root);
    assert($materializer instanceof WxrMaterializer);

    expect((new ReflectionProperty(WxrMaterializer::class, 'client'))->getValue($materializer))->toBe($client)
        ->and((new ReflectionProperty(WxrMaterializer::class, 'outputDirectory'))->getValue($materializer))->toBe($this->root);
});

it('says nothing when the API serves everything on the site', function (): void {
    [$command, $buffer] = iwasCommand();

    iwasCall($command, 'reportUnservedContent', []);

    expect(iwasOutput($buffer))->toBe('');
});

it('totals what the API does not serve, and names each kind', function (): void {
    [$command, $buffer] = iwasCommand();

    iwasCall($command, 'reportUnservedContent', [
        ['kind' => 'post type', 'name' => 'event', 'records' => 3],
        ['kind' => 'taxonomy', 'name' => 'event_category', 'records' => 9],
    ]);

    expect(iwasOutput($buffer))->toBe(
        "12 record(s) on this site will NOT be imported: the API does not serve them.\n"
        ."      post type      event                    3 record(s)\n"
        ."      taxonomy       event_category           9 record(s)\n"
        ."      Usually this means the plugin registering that type is not active on the site being read. See docs/migration-api-contract.md.\n"
    );
});

it('reports each redirect plugin\'s rules, and what the collection cannot express', function (): void {
    [$command, $buffer] = iwasCommand();

    iwasCall($command, 'reportRedirectPluginRules', [
        ['host' => 'old.test', 'plugin' => 'Redirection', 'rules' => 4, 'written' => 4, 'unsupported' => []],
        ['host' => 'other.test', 'plugin' => 'Safe Redirect Manager', 'rules' => 5, 'written' => 2, 'unsupported' => ['regular expressions' => 2, 'a status other than 301 or 302' => 1]],
        ['host' => 'third.test', 'plugin' => 'Redirection', 'rules' => 1, 'written' => 1, 'unsupported' => []],
    ]);

    expect(iwasOutput($buffer))->toBe(
        "Redirection on old.test: 4 rule(s) served, 4 written as redirect rules.\n"
        ."Safe Redirect Manager on other.test: 5 rule(s) served, 2 written as redirect rules.\n"
        ."      The redirect domains collection cannot express:\n"
        ."      regular expressions                                          2 rule(s)\n"
        ."      a status other than 301 or 302                               1 rule(s)\n"
        ."Redirection on third.test: 1 rule(s) served, 1 written as redirect rules.\n"
    );
});

it('hands the entry creator its own form, reusable block, deferred processing and site collaborators', function (): void {
    // Fresh collaborators, so none can match the entry creator's own by being the
    // container's shared instance
    $command = app()->make(ImportWordPress::class, [
        'formPluginCoordinator' => app()->build(FormPluginCoordinator::class),
        'reusableBlockCoordinator' => app()->build(ReusableBlockCoordinator::class),
        'deferredProcessingCoordinator' => new DeferredProcessingCoordinator,
        'siteManager' => new SiteManager,
    ]);
    $read = static fn (object $owner, string $property): mixed => (new ReflectionProperty($owner, $property))->getValue($owner);
    $entryCreator = (new ReflectionProperty(ImportWordPress::class, 'entryCreator'))->getValue($command);

    expect($entryCreator)->toBeObject();

    if (! is_object($entryCreator)) {
        return;
    }

    foreach (['formPluginCoordinator', 'reusableBlockCoordinator', 'deferredProcessingCoordinator', 'siteManager'] as $collaborator) {
        expect($read($entryCreator, $collaborator))->toBe($read($command, $collaborator), $collaborator);
    }
});
