<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use PHPUnit\Framework\Assert;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings\Prompter;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;
use UAMSWeb\WordPressImporter\Tests\Support\LfBufferedOutput;

/**
 * The MinervaKB settings `Prompter` explains where the settings file comes from, then asks for
 * its path, which must name a file that exists, or be left empty to skip (#100).
 */
uses(StatamicTestCase::class);

/**
 * A Prompter whose platform is chosen, rather than read from the machine.
 */
final class MinervaKbSettingsPrompterTestPrompter extends Prompter
{
    public function __construct(private readonly bool $prompts) {}

    protected function isPromptsSupported(): bool
    {
        return $this->prompts;
    }
}

/**
 * Ask for the settings file inside a real Command::run(), which routes prompts to the input's
 * stream.
 *
 * @param  bool  $prompts  Whether Laravel Prompts are supported
 * @param  ?string  $answers  The scripted answers, or null for a run that is not interactive
 * @param  ?list<string>  $keys  The keys the real Laravel Prompts answer with, or null for the fallback a test run uses
 * @return array{?string, string} The path asked for, and the run's output
 */
function minervaKbSettingsPrompterRun(bool $prompts, ?string $answers, ?array $keys = null): array
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

    $command = new class($prompts, $keys) extends Command
    {
        protected $signature = 'test:minerva-kb-settings-prompter';

        public ?string $path = null;

        /**
         * @param  ?list<string>  $keys
         */
        public function __construct(private readonly bool $prompts, private readonly ?array $keys)
        {
            parent::__construct();
        }

        public function handle(): int
        {
            $ask = fn (InputInterface $input, OutputInterface $output): ?string => (new MinervaKbSettingsPrompterTestPrompter($this->prompts))->promptForSettingsFile($input, $output, $this);

            if ($this->keys === null) {
                $this->path = $ask($this->input, $this->output);

                return self::SUCCESS;
            }

            // Laravel Prompts throws on Windows before it reads a key, and the command falls
            // back there
            if (\PHP_OS_FAMILY === 'Windows') {
                Assert::markTestSkipped('Laravel Prompts does not run on Windows.');
            }

            // Command::run() turns the fallback on, and fallbackWhen() only ever turns it
            // on, so it is turned off directly for the call and turned back on after
            $fallback = new ReflectionProperty(Prompt::class, 'shouldFallback');
            $fallback->setValue(null, false);
            Prompt::fake($this->keys);

            try {
                $this->path = $ask($this->input, $this->output);
            } finally {
                $fallback->setValue(null, true);
            }

            return self::SUCCESS;
        }
    };

    $buffer = new LfBufferedOutput;
    $command->setLaravel(app());
    $command->run($input, $buffer);

    return [$command->path, $buffer->fetch()];
}

beforeEach(function (): void {
    $this->settingsFile = tempnam(sys_get_temp_dir(), 'minerva-kb-settings-');
});

afterEach(function (): void {
    if (is_string($this->settingsFile) && file_exists($this->settingsFile)) {
        unlink($this->settingsFile);
    }
});

it('explains where the settings file comes from before it asks', function (): void {
    [, $out] = minervaKbSettingsPrompterRun(false, null);

    expect($out)->toBe(implode("\n", [
        'MinervaKB Settings Configuration',
        '',
        'You can optionally provide a MinervaKB export settings JSON file to map configuration values to Statamic fields.',
        '',
        'To get this file:',
        '  1. Go to the "Import / Export" tab of the MinervaKB settings page on your WordPress site',
        '  2. Click the "Download JSON file" button under "Settings export"',
        '  3. Provide the path to the downloaded JSON file below',
        '',
        '',
    ]));
});

it('skips when the run is not interactive', function (): void {
    expect(minervaKbSettingsPrompterRun(false, null)[0])->toBeNull()
        ->and(minervaKbSettingsPrompterRun(true, null)[0])->toBeNull();
});

it('skips for an empty answer to its own question, without calling it invalid', function (): void {
    // The command's question answers an empty line with null, which it once refused as an
    // invalid path, asking again for as long as the operator pressed Enter
    [$path, $out] = minervaKbSettingsPrompterRun(false, "\n");

    expect($path)->toBeNull()
        ->and($out)->toContain('Enter the path to your MinervaKB settings JSON file (or press Enter to skip):')
        ->and($out)->not->toContain('Invalid file path.')
        ->and($out)->not->toContain('The specified file does not exist.');
});

it('takes a path that names a file', function (bool $prompts): void {
    expect(minervaKbSettingsPrompterRun($prompts, $this->settingsFile."\n")[0])->toBe($this->settingsFile);
})->with(['prompts' => [true], 'questions' => [false]]);

it('says a path names no file, and asks again', function (bool $prompts): void {
    [$path, $out] = minervaKbSettingsPrompterRun($prompts, "/no/such/settings.json\n".$this->settingsFile."\n");

    expect($path)->toBe($this->settingsFile)
        ->and(substr_count($out, 'The specified file does not exist.'))->toBe(1);
})->with(['prompts' => [true], 'questions' => [false]]);

it('asks through Laravel Prompts, where an empty answer skips and a path is taken', function (): void {
    // The input has no answer, so only the prompt can produce one
    [$skipped] = minervaKbSettingsPrompterRun(true, '', [Key::ENTER]);
    [$path] = minervaKbSettingsPrompterRun(true, '', [...str_split((string) $this->settingsFile), Key::ENTER]);

    expect($skipped)->toBeNull()
        ->and($path)->toBe($this->settingsFile);
});
