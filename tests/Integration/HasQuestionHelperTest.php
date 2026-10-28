<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Traits\HasPromptsSupport;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Traits\HasQuestionHelper;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * `HasQuestionHelper` asks through Laravel Prompts where they are supported and through the
 * command's own questions where they are not, and answers with a default where it cannot
 * ask (#100).
 */
uses(StatamicTestCase::class);

/**
 * A class using the trait, with its protected methods exposed and its platform chosen.
 */
final class HasQuestionHelperTestHarness
{
    use HasPromptsSupport;
    use HasQuestionHelper;

    public function __construct(public bool $prompts = true) {}

    protected function isPromptsSupported(): bool
    {
        return $this->prompts;
    }

    public function helperFor(?Command $command): QuestionHelper
    {
        return $this->getQuestionHelper($command);
    }

    public function confirmation(string $question, bool $default, InputInterface $input, OutputInterface $output, Command $command): bool
    {
        return $this->askConfirmation($question, $default, $input, $output, $command);
    }

    public function unsupported(string $policy, InputInterface $input, OutputInterface $output, Command $command): string
    {
        return $this->resolveUnsupportedContent('Import it anyway?', $policy, $input, $output, $command);
    }

    /**
     * @param  array<int|string, mixed>  $options
     */
    public function choice(array $options, int|string $default, InputInterface $input, OutputInterface $output, Command $command): string
    {
        return $this->askChoice('Pick one', $options, $default, $input, $output, $command);
    }

    public function text(InputInterface $input, OutputInterface $output, Command $command, bool $required = false, ?string $default = null): ?string
    {
        return $this->askText('Name it', $input, $output, $command, $required, $default, 'a placeholder');
    }

    public function textAsDeclared(InputInterface $input, OutputInterface $output, Command $command): ?string
    {
        return $this->askText('Name it', $input, $output, $command);
    }

    public function textWithPlaceholder(?string $placeholder, InputInterface $input, OutputInterface $output, Command $command): ?string
    {
        return $this->askText('Name it', $input, $output, $command, false, null, $placeholder);
    }

    public function validated(callable $validator, InputInterface $input, OutputInterface $output, Command $command, ?string $default = null): mixed
    {
        return $this->askWithValidation('Which one?', $validator, $input, $output, $command, $default);
    }
}

/**
 * A command using the trait, so the question helper can be its own.
 */
final class HasQuestionHelperTestCommand extends Command
{
    use HasPromptsSupport;
    use HasQuestionHelper;

    protected $signature = 'test:has-question-helper-self';

    public function ownHelper(): QuestionHelper
    {
        return $this->getQuestionHelper();
    }

    public function helperOf(Command $command): QuestionHelper
    {
        return $this->getQuestionHelper($command);
    }
}

/**
 * A value that turns into a string.
 */
final class HasQuestionHelperTestLabel implements Stringable
{
    public function __construct(private readonly string $label) {}

    public function __toString(): string
    {
        return $this->label;
    }
}

/**
 * Run a body inside a real Command::run(), which routes prompts to the input's stream.
 *
 * @param  Closure(InputInterface, OutputInterface, Command): mixed  $body  What to run
 * @param  ?string  $answers  The scripted answers, or null for a run that is not interactive
 * @return array{mixed, string} What the body returned, and the run's output
 */
function hasQuestionHelperRun(Closure $body, ?string $answers = null): array
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
        protected $signature = 'test:has-question-helper';

        public mixed $result = null;

        /**
         * @param  Closure(InputInterface, OutputInterface, Command): mixed  $body
         */
        public function __construct(private readonly Closure $body)
        {
            parent::__construct();
        }

        public function handle(): int
        {
            $this->result = ($this->body)($this->input, $this->output, $this);

            return self::SUCCESS;
        }
    };

    $buffer = new BufferedOutput;
    $command->setLaravel(app());
    $command->run($input, $buffer);

    return [$command->result, $buffer->fetch()];
}

/**
 * Run a body with the real Laravel Prompts answering the given keys, instead of the
 * fallback a test run uses, which reads the input's stream.
 *
 * Command::run() turns the fallback on, and fallbackWhen() only ever turns it on, so it is
 * turned off directly inside the run and turned back on after.
 *
 * @template T
 *
 * @param  list<string>  $keys  The keys pressed, in order
 * @param  Closure(): T  $body  What to run
 * @return T What the body returned
 */
function hasQuestionHelperWithRealPrompts(array $keys, Closure $body): mixed
{
    $fallback = new ReflectionProperty(Prompt::class, 'shouldFallback');
    $fallback->setValue(null, false);
    Prompt::fake($keys);

    try {
        return $body();
    } finally {
        $fallback->setValue(null, true);
    }
}

it('takes the question helper of the command it is given', function (): void {
    $helper = new QuestionHelper;
    $command = new class extends Command
    {
        protected $signature = 'test:has-question-helper-given';
    };
    $command->setHelperSet(new HelperSet(['question' => $helper]));

    expect((new HasQuestionHelperTestHarness)->helperFor($command))->toBe($helper);
});

it('takes its own question helper when it is the command', function (): void {
    $helper = new QuestionHelper;
    $command = new HasQuestionHelperTestCommand;
    $command->setHelperSet(new HelperSet(['question' => $helper]));

    expect($command->ownHelper())->toBe($helper);
});

it('refuses to find a question helper without a command, or one that is not a question helper', function (): void {
    $command = new class extends Command
    {
        protected $signature = 'test:has-question-helper-wrong';
    };
    $command->setHelperSet(new HelperSet(['question' => new FormatterHelper]));

    expect(fn () => (new HasQuestionHelperTestHarness)->helperFor(null))
        ->toThrow(LogicException::class, 'Question helper requires a command instance.')
        ->and(fn () => (new HasQuestionHelperTestHarness)->helperFor($command))
        ->toThrow(LogicException::class, 'Command question helper must be a QuestionHelper instance.');
});

it('asks for a confirmation, and takes its default for an empty answer', function (bool $prompts, string $answers, bool $default, bool $confirmed): void {
    [$result, $out] = hasQuestionHelperRun(
        fn (InputInterface $input, OutputInterface $output, Command $command): bool => (new HasQuestionHelperTestHarness($prompts))->confirmation('Go on?', $default, $input, $output, $command),
        $answers
    );

    expect($result)->toBe($confirmed)
        ->and($out)->toContain('Go on?');
})->with([
    'prompts, yes' => [true, "yes\n", false, true],
    'prompts, no' => [true, "no\n", true, false],
    'prompts, empty, default yes' => [true, "\n", true, true],
    'prompts, empty, default no' => [true, "\n", false, false],
    'questions, yes' => [false, "yes\n", false, true],
    'questions, empty, default yes' => [false, "\n", true, true],
]);

it('takes the confirmation default when the run is not interactive', function (bool $default): void {
    [$result] = hasQuestionHelperRun(
        fn (InputInterface $input, OutputInterface $output, Command $command): bool => (new HasQuestionHelperTestHarness)->confirmation('Go on?', $default, $input, $output, $command)
    );

    expect($result)->toBe($default);
})->with([true, false]);

it('asks whether to import unsupported content, and otherwise follows the policy', function (?string $answers, string $policy, string $decision): void {
    [$result] = hasQuestionHelperRun(
        fn (InputInterface $input, OutputInterface $output, Command $command): string => (new HasQuestionHelperTestHarness)->unsupported($policy, $input, $output, $command),
        $answers
    );

    expect($result)->toBe($decision);
})->with([
    'asked, yes' => ["yes\n", 'abort', 'import'],
    'asked, no' => ["no\n", 'import', 'skip'],
    'asked, the default no' => ["\n", 'import', 'skip'],
    'not interactive, abort' => [null, 'abort', 'abort'],
    'not interactive, import' => [null, 'import', 'import'],
]);

it('answers a choice with its default, or its first option, when the run is not interactive', function (array $options, int|string $default, string $chosen): void {
    [$result] = hasQuestionHelperRun(
        fn (InputInterface $input, OutputInterface $output, Command $command): string => (new HasQuestionHelperTestHarness)->choice($options, $default, $input, $output, $command)
    );

    expect($result)->toBe($chosen);
})->with([
    'an index default' => [['a', 'b', 'c'], 1, 'b'],
    'a key default' => [['x' => 'Ex', 'y' => 'Why'], 'y', 'Why'],
    'a default that is no key' => [['a', 'b'], 'b', 'a'],
    'an index past the options' => [['a', 'b'], 5, 'a'],
    'no options' => [[], 0, ''],
]);

it('asks a choice and returns the option chosen', function (bool $prompts): void {
    [$result, $out] = hasQuestionHelperRun(
        fn (InputInterface $input, OutputInterface $output, Command $command): string => (new HasQuestionHelperTestHarness($prompts))->choice(['a', 'b', 'c'], 0, $input, $output, $command),
        "c\n"
    );

    expect($result)->toBe('c')
        ->and($out)->toContain('Pick one');
})->with(['prompts' => [true], 'questions' => [false]]);

it('labels every option as a string, and one that cannot be by its key', function (): void {
    [$result, $out] = hasQuestionHelperRun(
        fn (InputInterface $input, OutputInterface $output, Command $command): string => (new HasQuestionHelperTestHarness(false))->choice(
            ['text', 7, 2.5, true, new HasQuestionHelperTestLabel('stringable'), 'list' => ['not', 'a', 'label']],
            0,
            $input,
            $output,
            $command
        ),
        "list\n"
    );

    expect($result)->toBe('list')
        ->and($out)->toContain('text')
        ->and($out)->toContain('7')
        ->and($out)->toContain('2.5')
        ->and($out)->toContain('stringable');
});

it('answers a question choice with its default when the answers run out', function (array $options, int|string $default, string $chosen): void {
    [$result] = hasQuestionHelperRun(
        fn (InputInterface $input, OutputInterface $output, Command $command): string => (new HasQuestionHelperTestHarness(false))->choice($options, $default, $input, $output, $command),
        ''
    );

    expect($result)->toBe($chosen);
})->with([
    // A choice question answers with the option at its default, and with the default
    // itself for a keyed one, as an answered keyed choice does
    'an index default' => [['a', 'b', 'c'], 2, 'c'],
    'a key default' => [['x' => 'Ex', 'y' => 'Why'], 'y', 'y'],
]);

it('answers a text question with its default when the run is not interactive', function (?string $default): void {
    [$result] = hasQuestionHelperRun(
        fn (InputInterface $input, OutputInterface $output, Command $command): ?string => (new HasQuestionHelperTestHarness)->text($input, $output, $command, false, $default)
    );

    expect($result)->toBe($default);
})->with(['a default' => ['kept'], 'none' => [null]]);

it('asks a text question and returns the answer', function (bool $prompts): void {
    [$result, $out] = hasQuestionHelperRun(
        fn (InputInterface $input, OutputInterface $output, Command $command): ?string => (new HasQuestionHelperTestHarness($prompts))->text($input, $output, $command),
        "Typed\n"
    );

    expect($result)->toBe('Typed')
        ->and($out)->toContain('Name it');
})->with(['prompts' => [true], 'questions' => [false]]);

it('takes the text default for an empty answer', function (bool $prompts): void {
    [$result] = hasQuestionHelperRun(
        fn (InputInterface $input, OutputInterface $output, Command $command): ?string => (new HasQuestionHelperTestHarness($prompts))->text($input, $output, $command, false, 'kept'),
        "\n"
    );

    expect($result)->toBe('kept');
})->with(['prompts' => [true], 'questions' => [false]]);

it('returns null for an empty question answer with no default, and the default when the answers run out', function (string $answers, ?string $default, ?string $expected): void {
    [$result] = hasQuestionHelperRun(
        fn (InputInterface $input, OutputInterface $output, Command $command): ?string => (new HasQuestionHelperTestHarness(false))->text($input, $output, $command, false, $default),
        $answers
    );

    expect($result)->toBe($expected);
})->with([
    'empty, no default' => ["\n", null, null],
    'run out, a default' => ['', 'kept', 'kept'],
]);

it('returns what the validator makes of the answer', function (bool $prompts): void {
    [$result] = hasQuestionHelperRun(
        fn (InputInterface $input, OutputInterface $output, Command $command): mixed => (new HasQuestionHelperTestHarness($prompts))->validated(
            fn (mixed $answer): string => 'checked '.(is_string($answer) ? $answer : ''),
            $input,
            $output,
            $command
        ),
        "fine\n"
    );

    expect($result)->toBe('checked fine');
})->with(['prompts' => [true], 'questions' => [false]]);

it('says why the validator refused an answer, and asks again', function (bool $prompts): void {
    [$result, $out] = hasQuestionHelperRun(
        fn (InputInterface $input, OutputInterface $output, Command $command): mixed => (new HasQuestionHelperTestHarness($prompts))->validated(
            fn (mixed $answer): string => $answer === 'good' ? 'good' : throw new RuntimeException('Not that one.'),
            $input,
            $output,
            $command
        ),
        "bad\nworse\ngood\n"
    );

    expect($result)->toBe('good')
        ->and(substr_count($out, 'Not that one.'))->toBe(2);
})->with(['prompts' => [true], 'questions' => [false]]);

it('answers a validated question with its default when the run is not interactive', function (?string $answers): void {
    [$result] = hasQuestionHelperRun(
        fn (InputInterface $input, OutputInterface $output, Command $command): mixed => (new HasQuestionHelperTestHarness(false))->validated(
            fn (mixed $answer): never => throw new RuntimeException('Never valid.'),
            $input,
            $output,
            $command,
            'kept'
        ),
        $answers
    );

    expect($result)->toBe('kept');
})->with(['not interactive' => [null]]);

it('takes the question helper of the command it is given, though it is a command itself', function (): void {
    $helper = new QuestionHelper;
    $other = new class extends Command
    {
        protected $signature = 'test:has-question-helper-other';
    };
    $other->setHelperSet(new HelperSet(['question' => $helper]));
    $command = new HasQuestionHelperTestCommand;
    $command->setHelperSet(new HelperSet(['question' => new QuestionHelper]));

    expect($command->helperOf($other))->toBe($helper);
});

it('confirms through Laravel Prompts where they are supported, and not where they are not', function (bool $prompts, bool $confirmed): void {
    // The stream answers with the default, no; the prompt with yes, and a key no prompt
    // reads fails the test
    [$result] = hasQuestionHelperRun(
        fn (InputInterface $input, OutputInterface $output, Command $command): bool => hasQuestionHelperWithRealPrompts(
            $prompts ? ['y', Key::ENTER] : [],
            fn (): bool => (new HasQuestionHelperTestHarness($prompts))->confirmation('Go on?', false, $input, $output, $command)
        ),
        "\n"
    );

    expect($result)->toBe($confirmed);
})->with(['prompts' => [true, true], 'questions' => [false, false]]);

it('chooses through Laravel Prompts, and answers with the key chosen as a string', function (): void {
    // The stream answers with the default; the prompt with the second option
    [$result] = hasQuestionHelperRun(
        fn (InputInterface $input, OutputInterface $output, Command $command): string => hasQuestionHelperWithRealPrompts(
            [Key::DOWN, Key::ENTER],
            fn (): string => (new HasQuestionHelperTestHarness(true))->choice([10 => 'Ten', 20 => 'Twenty'], 10, $input, $output, $command)
        ),
        "\n"
    );

    expect($result)->toBe('20');
});

it('answers with the key, as a string, of an option that cannot be labeled', function (): void {
    [$result] = hasQuestionHelperRun(
        fn (InputInterface $input, OutputInterface $output, Command $command): string => (new HasQuestionHelperTestHarness)->choice([5 => ['not', 'a', 'label']], 5, $input, $output, $command)
    );

    expect($result)->toBe('5');
});

it('asks a text question through Laravel Prompts, and not through the input', function (): void {
    // The input has no answer, so a question would answer with no default
    [$result] = hasQuestionHelperRun(
        fn (InputInterface $input, OutputInterface $output, Command $command): ?string => hasQuestionHelperWithRealPrompts(
            ['T', 'y', 'p', Key::ENTER],
            fn (): ?string => (new HasQuestionHelperTestHarness(true))->text($input, $output, $command)
        ),
        ''
    );

    expect($result)->toBe('Typ');
});

it('shows a text prompt its placeholder, and none when it has none, with nothing for its default', function (): void {
    [$placed] = hasQuestionHelperRun(
        fn (InputInterface $input, OutputInterface $output, Command $command): ?string => hasQuestionHelperWithRealPrompts(
            [Key::ENTER],
            function () use ($input, $output, $command): ?string {
                $answer = (new HasQuestionHelperTestHarness(true))->textWithPlaceholder('e.g. Jane', $input, $output, $command);
                Prompt::assertStrippedOutputContains('e.g. Jane');

                return $answer;
            }
        ),
        ''
    );

    [$bare] = hasQuestionHelperRun(
        fn (InputInterface $input, OutputInterface $output, Command $command): ?string => hasQuestionHelperWithRealPrompts(
            [Key::ENTER],
            function () use ($input, $output, $command): ?string {
                $answer = (new HasQuestionHelperTestHarness(true))->textWithPlaceholder(null, $input, $output, $command);
                Prompt::assertStrippedOutputDoesntContain('PEST');

                return $answer;
            }
        ),
        ''
    );

    expect($placed)->toBe('')
        ->and($bare)->toBe('');
});

it('does not require a text answer unless asked to', function (): void {
    // The fallback a test run uses refuses an empty answer to a required prompt outright
    [$result] = hasQuestionHelperRun(
        fn (InputInterface $input, OutputInterface $output, Command $command): ?string => (new HasQuestionHelperTestHarness(true))->textAsDeclared($input, $output, $command),
        "\n"
    );

    expect($result)->toBe('');
});

it('validates through Laravel Prompts, accepting an empty answer, and offers its default', function (?string $default, string $expected): void {
    // An empty answer is accepted; a required one would read a key the test does not press
    [$result] = hasQuestionHelperRun(
        fn (InputInterface $input, OutputInterface $output, Command $command): mixed => hasQuestionHelperWithRealPrompts(
            [Key::ENTER],
            fn (): mixed => (new HasQuestionHelperTestHarness(true))->validated(
                fn (mixed $answer): string => 'checked '.(is_string($answer) ? $answer : ''),
                $input,
                $output,
                $command,
                $default
            )
        ),
        ''
    );

    expect($result)->toBe($expected);
})->with([
    'no default' => [null, 'checked '],
    'a default' => ['kept', 'checked kept'],
]);

it('shows a refused answer its reason as an error', function (bool $prompts): void {
    $errors = new BufferedOutput(decorated: true);

    [$result] = hasQuestionHelperRun(
        fn (InputInterface $input, OutputInterface $output, Command $command): mixed => hasQuestionHelperWithRealPrompts(
            $prompts ? ['b', Key::ENTER, 'g', Key::ENTER] : [],
            fn (): mixed => (new HasQuestionHelperTestHarness($prompts))->validated(
                fn (mixed $answer): string => $answer === 'g' ? 'g' : throw new RuntimeException('Not that one.'),
                $input,
                $errors,
                $command
            )
        ),
        "b\ng\n"
    );

    expect($result)->toBe('g')
        ->and($errors->fetch())->toBe("\e[37;41mNot that one.\e[39;49m\n");
})->with(['prompts' => [true], 'questions' => [false]]);

it('starts a Laravel Prompts choice over a list at its default index', function (int|string $default, string $chosen): void {
    // A list's default is matched against its labels, so an index default once started the
    // prompt at the first option whatever it named
    [$result] = hasQuestionHelperRun(
        fn (InputInterface $input, OutputInterface $output, Command $command): string => hasQuestionHelperWithRealPrompts(
            [Key::ENTER],
            fn (): string => (new HasQuestionHelperTestHarness(true))->choice(['a', 'b', 'c'], $default, $input, $output, $command)
        ),
        ''
    );

    expect($result)->toBe($chosen);
})->with([
    'an index' => [2, 'c'],
    'an index past the options' => [5, 'a'],
    'a label' => ['b', 'b'],
]);

it('starts a Laravel Prompts choice over keyed options at its default key', function (): void {
    [$result] = hasQuestionHelperRun(
        fn (InputInterface $input, OutputInterface $output, Command $command): string => hasQuestionHelperWithRealPrompts(
            [Key::ENTER],
            fn (): string => (new HasQuestionHelperTestHarness(true))->choice([3 => 'Three', 7 => 'Seven'], 7, $input, $output, $command)
        ),
        ''
    );

    expect($result)->toBe('7');
});
