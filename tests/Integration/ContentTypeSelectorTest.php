<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\ContentTypeSelector;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

/**
 * `ContentTypeSelector` offers the content types an export holds, without menu items, and
 * ends the run when none is left (#100).
 */
uses(StatamicTestCase::class);

/**
 * Run select() in a child PHP process, which is the only place its exit can be observed.
 *
 * @param  array<int|string, mixed>  $contentTypes  The content types to offer
 * @return array{int, string} The child's exit status and output
 */
function contentTypeSelectorInChild(array $contentTypes): array
{
    $script = <<<'PHP'
        <?php
        require $argv[1].'/vendor/autoload.php';
        $command = new class extends Illuminate\Console\Command { protected $signature = 'child'; };
        $input = new Symfony\Component\Console\Input\ArrayInput([]);
        $input->setInteractive(false);
        $output = new Symfony\Component\Console\Output\StreamOutput(fopen('php://stdout', 'w'));
        $command->setOutput(new Illuminate\Console\OutputStyle($input, $output));
        $selected = (new UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\ContentTypeSelector)->select(json_decode($argv[2], true), $input, $output, $command);
        echo "returned {$selected}\n";
        PHP;

    $path = tempnam(sys_get_temp_dir(), 'content-type-selector-');
    file_put_contents((string) $path, $script);

    try {
        $process = proc_open(
            [PHP_BINARY, (string) $path, dirname(__DIR__, 2), (string) json_encode($contentTypes)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $out = is_resource($process) ? (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]) : '';
        $status = is_resource($process) ? proc_close($process) : -1;
    } finally {
        unlink((string) $path);
    }

    return [$status, $out];
}

/**
 * Run select() inside a real Command::run(), which routes its prompt to the input's stream.
 *
 * @param  array<int|string, mixed>  $contentTypes  The content types to offer
 * @param  ?string  $answers  The scripted answers, or null for a run that is not interactive
 * @return array{string, string} The selected type and the run's output
 */
function contentTypeSelectorRun(array $contentTypes, ?string $answers = null): array
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

    $command = new class($contentTypes) extends Command
    {
        protected $signature = 'test:content-type-selector';

        public string $selected = '';

        /**
         * @param  array<int|string, mixed>  $contentTypes
         */
        public function __construct(private readonly array $contentTypes)
        {
            parent::__construct();
        }

        public function handle(): int
        {
            $this->selected = (new ContentTypeSelector)->select($this->contentTypes, $this->input, $this->output, $this);

            return self::SUCCESS;
        }
    };

    $buffer = new BufferedOutput;
    $command->setLaravel(app());
    $command->run($input, $buffer);

    return [$command->selected, $buffer->fetch()];
}

/**
 * A selector whose end of the run throws instead of exiting, so a test can observe it.
 */
final class ContentTypeSelectorEndingRun extends ContentTypeSelector
{
    /**
     * Throw the status instead of exiting.
     *
     * @param  int  $status  The exit status
     */
    protected function terminate(int $status): never
    {
        throw new RuntimeException("terminated with {$status}");
    }
}

it('says why it ends the run, and ends it with status 1, in process', function (array $contentTypes): void {
    $buffer = new BufferedOutput;
    $input = new ArrayInput([]);
    $input->setInteractive(false);
    $command = new class extends Command
    {
        protected $signature = 'test:content-type-selector-end';
    };
    $command->setOutput(new OutputStyle($input, $buffer));

    expect(fn () => (new ContentTypeSelectorEndingRun)->select($contentTypes, $input, $buffer, $command))
        ->toThrow(RuntimeException::class, 'terminated with 1')
        ->and(substr_count($buffer->fetch(), 'No supported content types (page, post, uamspeople) found in the XML.'))->toBe(1);
})->with([
    'none at all' => [[]],
    'only menu items' => [[['nav_menu_item', 3]]],
]);

it('ends the run with status 1 when the export holds no content type, saying so', function (array $contentTypes): void {
    [$status, $out] = contentTypeSelectorInChild($contentTypes);

    expect($status)->toBe(1)
        ->and($out)->toContain('No supported content types (page, post, uamspeople) found in the XML.')
        ->and($out)->not->toContain('returned');
})->with([
    'none at all' => [[]],
    'only menu items' => [[['nav_menu_item', 3]]],
    'only rows that name no type' => [['not a pair', [7, 2], [null, 1]]],
]);

it('returns the type it offers when the run is not interactive', function (): void {
    [$status, $out] = contentTypeSelectorInChild([['page', 17], ['post', 2]]);

    expect($status)->toBe(0)
        ->and($out)->toContain('returned page');
});

it('takes the first type it offers when the run is not interactive', function (): void {
    // Ahead of it, rows the selector does not offer: a menu item, a bare string and a pair
    // whose type is not a string
    [$selected] = contentTypeSelectorRun([['nav_menu_item', 9], 'not a pair', [7, 2], ['post', 2], ['page', 17]]);

    expect($selected)->toBe('post');
});

it('offers each type with its count, leaving out menu items and rows that name no type', function (): void {
    [$selected, $out] = contentTypeSelectorRun(
        [['page', 17], ['nav_menu_item', 9], 'not a pair', [3, 4], ['post', ['not', 'a', 'count']], ['uamspeople', 5]],
        "uamspeople (5)\n"
    );

    expect($selected)->toBe('uamspeople')
        ->and($out)->toContain('page (17)')
        ->and($out)->toContain('post (0)')
        ->and($out)->toContain('uamspeople (5)')
        ->and($out)->not->toContain('nav_menu_item');
});

it('offers a row that gives no count with a count of 0', function (): void {
    [$selected, $out] = contentTypeSelectorRun([['solo'], ['page', 1]], "solo (0)\n");

    expect($selected)->toBe('solo')
        ->and($out)->toContain('solo (0)');
});

it('takes the first type it offers when the answer is left empty', function (): void {
    [$selected] = contentTypeSelectorRun([['page', 17], ['post', 2]], "\n");

    expect($selected)->toBe('page');
});

it('returns the name of the type chosen, without its count', function (): void {
    [$selected] = contentTypeSelectorRun([['page', 17], ['post', 2]], "post (2)\n");

    expect($selected)->toBe('post');
});
