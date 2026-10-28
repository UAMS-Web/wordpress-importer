<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\TaxonomyOverwriteBehaviorSelector;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * `TaxonomyOverwriteBehaviorSelector` asks how existing terms are handled, and answers the
 * first option without asking while unit tests run (#100).
 */
uses(StatamicTestCase::class);

/**
 * Run select() inside a real Command::run(), optionally as though no unit test were running.
 *
 * `run()` configures the prompts while the environment is still `testing`, so a prompt falls
 * back to a question on the input's stream rather than the real STDIN. Only then, around the
 * call itself, is the environment switched, so select() takes the path it takes outside tests.
 *
 * @param  ?string  $answers  The scripted answers, or null for a run that is not interactive
 * @param  bool  $outsideTests  Whether select() runs as though no unit test were running
 * @return array{string, string} The selected behavior and the run's output
 */
function taxonomyOverwriteBehaviorRun(?string $answers, bool $outsideTests): array
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

    $command = new class($outsideTests) extends Command
    {
        protected $signature = 'test:taxonomy-overwrite-behavior';

        public string $selected = '';

        public function __construct(private readonly bool $outsideTests)
        {
            parent::__construct();
        }

        public function handle(): int
        {
            $environment = app()['env'];

            if ($this->outsideTests) {
                app()['env'] = 'local';
            }

            try {
                $this->selected = (new TaxonomyOverwriteBehaviorSelector)->select($this->input, $this->output, $this);
            } finally {
                app()['env'] = $environment;
            }

            return self::SUCCESS;
        }
    };

    $buffer = new BufferedOutput;
    $command->setLaravel(app());
    $command->run($input, $buffer);

    return [$command->selected, $buffer->fetch()];
}

it('offers the three behaviors, overwriting first', function (): void {
    $selector = new TaxonomyOverwriteBehaviorSelector;

    expect($selector->getOptions())->toBe(['Overwrite matching terms', 'Skip matching terms', 'Create an additional term'])
        ->and($selector->getDefault())->toBe('Overwrite matching terms');
});

it('answers the first behavior without asking while unit tests run', function (): void {
    [$selected, $out] = taxonomyOverwriteBehaviorRun("Skip matching terms\n", false);

    expect($selected)->toBe('Overwrite matching terms')
        ->and($out)->not->toContain('How should existing taxonomy terms be handled?');
});

it('asks how existing terms are handled, and returns the behavior chosen', function (string $answer, string $behavior): void {
    [$selected, $out] = taxonomyOverwriteBehaviorRun($answer."\n", true);

    expect($selected)->toBe($behavior)
        ->and($out)->toContain('How should existing taxonomy terms be handled?');
})->with([
    'skip' => ['Skip matching terms', 'Skip matching terms'],
    'create' => ['Create an additional term', 'Create an additional term'],
    'the default, by an empty answer' => ['', 'Overwrite matching terms'],
]);

it('takes the first behavior when the run is not interactive', function (): void {
    [$selected] = taxonomyOverwriteBehaviorRun(null, true);

    expect($selected)->toBe('Overwrite matching terms');
});
