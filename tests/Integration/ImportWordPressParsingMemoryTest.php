<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Console\Commands\ImportWordPress;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * Tests the `ImportWordPress` command's parsing and memory check: counting each content type
 * across the parts of an export and reading its base blog URL, measuring its content items, and
 * the check that warns when the largest item needs more memory than the limit allows, with the
 * choice it then offers.
 *
 * The command is a subclass whose `terminate()` throws instead of ending the process, so the
 * choices that end the run can be asserted. It is built without its constructor and given only
 * its parser, an input and an output. The choice is a prompt, answered from the input's stream
 * inside `Command::run()`, which routes it to a Symfony question on every platform (see
 * `tests/CLAUDE.md`).
 *
 * @command  php vendor/bin/pest tests/Integration/ImportWordPressParsingMemoryTest.php
 */

/**
 * Thrown in place of ending the process, carrying the status it would have ended with.
 */
final class IwpmTerminated extends RuntimeException
{
    /**
     * Create the exception for the given status.
     */
    public function __construct(public readonly int $status)
    {
        parent::__construct("terminated with {$status}");
    }
}

/**
 * The command, with `terminate()` throwing in place of ending the process.
 */
final class IwpmCommand extends ImportWordPress
{
    /**
     * The memory limit the check reads, or null to read the process's own.
     */
    public static ?string $memoryLimit = null;

    /**
     * {@inheritDoc}
     */
    protected function memoryLimit(): string
    {
        return self::$memoryLimit ?? parent::memoryLimit();
    }

    /**
     * {@inheritDoc}
     */
    protected function terminate(int $status): never
    {
        throw new IwpmTerminated($status);
    }
}

beforeEach(function (): void {
    IwpmCommand::$memoryLimit = null;
    $this->root = sys_get_temp_dir().'/iwpm-'.bin2hex(random_bytes(6));
    mkdir($this->root);
});

afterEach(function (): void {
    IwpmCommand::$memoryLimit = null;

    $paths = glob($this->root.'/*.xml');

    foreach ($paths === false ? [] : $paths as $path) {
        unlink($path);
    }

    rmdir($this->root);
});

/**
 * Write an export part holding the given channel XML, and answer its path.
 */
function iwpmPart(mixed $test, string $channel): string
{
    $root = is_object($test) ? ($test->root ?? null) : null;

    if (! is_string($root)) {
        throw new LogicException('No test directory.');
    }

    $path = $root.'/part-'.bin2hex(random_bytes(4)).'.xml';
    file_put_contents($path, '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wp="http://wordpress.org/export/1.2/"><channel>'.$channel.'</channel></rss>');

    return $path;
}

/**
 * An export item of the given post type whose content is the given number of bytes.
 */
function iwpmItem(int $bytes, string $type = 'page'): string
{
    return '<item><title>Title</title><wp:post_type>'.$type.'</wp:post_type><content:encoded><![CDATA['.str_repeat('x', $bytes).']]></content:encoded></item>';
}

/**
 * The command, built without its constructor, with a fresh parser, the given input and an
 * output, and the buffer behind that output.
 *
 * @return array{IwpmCommand, BufferedOutput, XmlParser}
 */
function iwpmCommand(?ArrayInput $input = null): array
{
    $command = (new ReflectionClass(IwpmCommand::class))->newInstanceWithoutConstructor();
    $parser = new XmlParser;
    (new ReflectionProperty(ImportWordPress::class, 'xmlParser'))->setValue($command, $parser);
    $input ??= new ArrayInput([]);
    $buffer = new BufferedOutput;
    $command->setInput($input);
    $command->setOutput(new OutputStyle($input, $buffer));

    return [$command, $buffer, $parser];
}

/**
 * Call one of the command's private methods.
 */
function iwpmCall(ImportWordPress $command, string $method, mixed ...$arguments): mixed
{
    return (new ReflectionMethod(ImportWordPress::class, $method))->invoke($command, ...$arguments);
}

/**
 * Run the memory check over the given parts inside `Command::run()`, answering its choice from
 * the given lines, and answer its output with plain line endings and the status it ended with,
 * or null when it went on.
 *
 * @param  list<string>  $parts  The part paths
 * @return array{string, ?int}
 */
function iwpmCheck(array $parts, string $answers = '', bool $interactive = true): array
{
    $stream = fopen('php://memory', 'r+');
    assert(is_resource($stream));
    fwrite($stream, $answers);
    rewind($stream);

    $input = new ArrayInput([]);
    $input->setStream($stream);
    $input->setInteractive($interactive);
    $buffer = new BufferedOutput;

    $runner = new class($parts) extends Command
    {
        protected $signature = 'test:iwpm-memory';

        /**
         * The status the check ended with, or null when it went on.
         */
        public ?int $status = null;

        /**
         * @param  list<string>  $parts  The part paths
         */
        public function __construct(private readonly array $parts)
        {
            parent::__construct();
        }

        public function handle(): int
        {
            $command = (new ReflectionClass(IwpmCommand::class))->newInstanceWithoutConstructor();
            $command->setInput($this->input);
            $command->setOutput($this->output);

            try {
                iwpmCall($command, 'checkMemoryRequirements', $this->parts);
            } catch (IwpmTerminated $terminated) {
                $this->status = $terminated->status;
            }

            return self::SUCCESS;
        }
    };

    $runner->setLaravel(app());

    if (! $interactive) {
        $input->setInteractive(false);
    }

    $runner->run($input, $buffer);

    return [str_replace(PHP_EOL, "\n", $buffer->fetch()), $runner->status];
}

/**
 * Set the memory limit the check reads, leaving the process's own alone.
 */
function iwpmLimit(string $limit): void
{
    IwpmCommand::$memoryLimit = $limit;
}

/**
 * The memory limit the larger cases set: 527,171,584 bytes, whose threshold of 1,054,343
 * bytes reads as 1.01MB, just over the rounding edge.
 */
const IWPM_LIMIT = '514816K';

/**
 * The item size the larger cases measure: 1,253,147 bytes, which reads as 1.2MB, just over
 * the rounding edge, and whose recommendation reads as 597.55MB.
 */
const IWPM_ITEM = 1253147;

/**
 * The lines the check prints before it offers its choice, for the limit and the largest item
 * the larger cases use, given how many items it found too large.
 *
 * @return non-empty-string
 */
function iwpmWarnings(int $found): string
{
    return "Current memory limit: 514816K\n"
        ."Analyzing content item sizes...\n"
        ."Largest content item: 1.2MB\n"
        ."Found {$found} content items larger than 1.01MB.\n"
        ."These items may cause memory exhaustion during XML parsing and processing.\n"
        ."Large content items detected. Recommended memory: 597.55MB\n"
        ."Current memory limit (514816K) may be insufficient for this import.\n";
}

it('counts each content type across every part, and reads the base blog URL from the first', function (): void {
    $first = iwpmPart($this, '<wp:base_blog_url>https://first.test</wp:base_blog_url>'.iwpmItem(1).iwpmItem(1).iwpmItem(1, 'post').iwpmItem(1, ''));
    $second = iwpmPart($this, '<wp:base_blog_url>https://second.test</wp:base_blog_url>'.iwpmItem(1).iwpmItem(1, 'kb'));
    [$command, $buffer, $parser] = iwpmCommand();

    iwpmCall($command, 'parseXmlFile', [$first, $second]);

    expect($buffer->fetch())->toBe('Using streaming parser for memory efficiency.'.PHP_EOL)
        ->and((new ReflectionProperty(XmlParser::class, 'contentTypes'))->getValue($parser))->toBe(['page' => 3, 'post' => 1, 'kb' => 1])
        ->and($parser->getFilePaths())->toBe([$first, $second])
        ->and($parser->getBaseBlogUrl())->toBe('https://first.test');
});

it('reads the base blog URL, or none when the export names none', function (string $channel, string $url): void {
    [$command] = iwpmCommand();

    expect(iwpmCall($command, 'getBaseBlogUrlFromStream', iwpmPart($this, $channel)))->toBe($url);
})->with([
    'named after the items' => [iwpmItem(1).'<wp:base_blog_url>https://old.test</wp:base_blog_url>', 'https://old.test'],
    'named twice' => ['<wp:base_blog_url>https://old.test</wp:base_blog_url><wp:base_blog_url>https://new.test</wp:base_blog_url>', 'https://old.test'],
    'named empty' => ['<wp:base_blog_url></wp:base_blog_url>', ''],
    'not named' => [iwpmItem(1).'<wp:base_site_url>https://old.test</wp:base_site_url>', ''],
]);

it('reads no base blog URL from an export it cannot open', function (): void {
    [$command] = iwpmCommand();

    expect(@iwpmCall($command, 'getBaseBlogUrlFromStream', sys_get_temp_dir().'/iwpm-missing-'.bin2hex(random_bytes(6)).'.xml'))->toBe('');
});

it('measures the largest item, and counts those larger than the threshold', function (): void {
    $path = iwpmPart($this, iwpmItem(10).iwpmItem(30).iwpmItem(15).iwpmItem(20));
    [$command] = iwpmCommand();

    expect(iwpmCall($command, 'analyzeContentItemSizes', $path, 15))->toBe(['largestItemSize' => 30, 'largeItemsCount' => 2]);
});

it('measures only an item\'s encoded content, all of it', function (): void {
    $path = iwpmPart($this, '<content:encoded>'.str_repeat('x', 99).'</content:encoded>'
        .'<item><title>'.str_repeat('t', 50).'</title><content:encoded>aaaa<b>bb</b>ccc</content:encoded><wp:post_name>'.str_repeat('n', 50).'</wp:post_name></item>');
    [$command] = iwpmCommand();

    expect(iwpmCall($command, 'analyzeContentItemSizes', $path, 6))->toBe(['largestItemSize' => 9, 'largeItemsCount' => 1]);
});

it('measures each item on its own, however many there are', function (): void {
    $path = iwpmPart($this, str_repeat(iwpmItem(3), 150).iwpmItem(5).str_repeat(iwpmItem(4), 60));
    [$command] = iwpmCommand();

    expect(iwpmCall($command, 'analyzeContentItemSizes', $path, 3))->toBe(['largestItemSize' => 5, 'largeItemsCount' => 61]);
});

it('measures nothing in an export with no items, or only empty ones', function (string $channel): void {
    [$command] = iwpmCommand();

    expect(iwpmCall($command, 'analyzeContentItemSizes', iwpmPart($this, $channel), 0))->toBe(['largestItemSize' => 0, 'largeItemsCount' => 0]);
})->with([
    'no items' => ['<title>Site</title>'],
    'only empty items' => ['<item><content:encoded></content:encoded></item><item><title>No content</title></item>'],
]);

it('stops measuring an item\'s content at 50MB', function (): void {
    // libxml refuses a text node over 10MB, so the content is text nodes of 9MB, split by
    // elements, ending exactly one byte below the cap before the last two
    $content = str_repeat(str_repeat('x', 9000000).'<b/>', 5).str_repeat('x', 7428799).'<b/>y<b/>z';
    $path = iwpmPart($this, '<item><content:encoded>'.$content.'</content:encoded></item>');
    unset($content);
    [$command] = iwpmCommand();

    expect(iwpmCall($command, 'analyzeContentItemSizes', $path, 1))->toBe(['largestItemSize' => 50 * 1024 * 1024, 'largeItemsCount' => 1]);
});

it('measures nothing in an export it cannot open', function (): void {
    [$command] = iwpmCommand();

    expect(@iwpmCall($command, 'analyzeContentItemSizes', sys_get_temp_dir().'/iwpm-missing-'.bin2hex(random_bytes(6)).'.xml', 1))
        ->toBe(['largestItemSize' => 0, 'largeItemsCount' => 0]);
});

it('measures items against a 50KB threshold by default', function (): void {
    $path = iwpmPart($this, iwpmItem(50 * 1024).iwpmItem(50 * 1024 + 1));
    [$command] = iwpmCommand();

    expect(iwpmCall($command, 'analyzeContentItemSizes', $path))->toBe(['largestItemSize' => 50 * 1024 + 1, 'largeItemsCount' => 1]);
});

it('reads the process\'s own memory limit', function (): void {
    $command = (new ReflectionClass(ImportWordPress::class))->newInstanceWithoutConstructor();

    expect((new ReflectionMethod(ImportWordPress::class, 'memoryLimit'))->invoke($command))->toBe(ini_get('memory_limit'));
});

it('says the memory limit is enough for an export of no parts', function (): void {
    iwpmLimit('-1');

    [$output] = iwpmCheck([]);

    expect($output)->toContain("Largest content item: 0MB\n");
});

it('says the memory limit is enough when no item needs more', function (): void {
    iwpmLimit('-1');

    [$output, $status] = iwpmCheck([iwpmPart($this, iwpmItem(IWPM_ITEM))]);

    expect($output)->toBe(
        "Current memory limit: -1\n"
        ."Analyzing content item sizes...\n"
        ."Largest content item: 1.2MB\n"
        ."Memory limit appears sufficient for this import.\n"
    )->and($status)->toBeNull();
});

it('reads the largest item to two places, rounding down below the edge', function (): void {
    iwpmLimit('-1');

    [$output] = iwpmCheck([iwpmPart($this, iwpmItem(1252935))]);

    expect($output)->toContain("Largest content item: 1.19MB\n");
});

it('reads the threshold to two places, rounding down below the edge', function (): void {
    iwpmLimit('514508K');

    [$output] = iwpmCheck([iwpmPart($this, iwpmItem(IWPM_ITEM))], "1\n");

    expect($output)->toContain("Found 1 content items larger than 1MB.\n");
});

it('says the memory limit is enough when the largest item needs exactly that much', function (): void {
    iwpmLimit('536870500');

    [$output, $status] = iwpmCheck([iwpmPart($this, iwpmItem(1073741))]);

    expect($output)->toEndWith("Memory limit appears sufficient for this import.\n")
        ->and($status)->toBeNull();
});

it('warns about every large item across the parts, and goes on when told to continue', function (): void {
    iwpmLimit(IWPM_LIMIT);

    [$output, $status] = iwpmCheck([iwpmPart($this, iwpmItem(IWPM_ITEM)), iwpmPart($this, iwpmItem(1100000).iwpmItem(1054344))], "1\n");

    expect($output)->toStartWith(iwpmWarnings(3))
        ->and($output)->toContain('How would you like to proceed?')
        ->and($output)->toEndWith("Proceeding with current memory limit. Monitor for memory exhaustion.\n")
        ->and($status)->toBeNull();
});

it('ends the run with an error when told to exit', function (): void {
    iwpmLimit(IWPM_LIMIT);

    [$output, $status] = iwpmCheck([iwpmPart($this, iwpmItem(IWPM_ITEM))], "2\n");

    expect($output)->toStartWith(iwpmWarnings(1))
        ->and($output)->toEndWith("Exiting. Please restart the command with increased memory limit when ready.\n")
        ->and($status)->toBe(1);
});

/**
 * Restart is the first option and the default, so answering it, pressing Enter at the Symfony
 * question, and a run that cannot ask all print the command to rerun with the suggested limit
 * and end the run with success (#277).
 */
it('tells how to rerun with more memory when told to restart, or left to the default', function (string $answers, bool $interactive): void {
    iwpmLimit(IWPM_LIMIT);
    $part = iwpmPart($this, iwpmItem(IWPM_ITEM));

    [$output, $status] = iwpmCheck([$part], $answers, $interactive);

    expect($output)->toStartWith(iwpmWarnings(1))
        ->and($output)->toEndWith(
            "Restarting command with increased memory limit...\n"
            ."\n"
            ."Run this command with increased memory:\n"
            ."php artisan cache:clear && > storage/logs/laravel.log && php -d memory_limit=640M artisan statamic:import:wordpress {$part}\n"
            ."\n"
            ."Exiting. Please run the command above with the increased memory limit.\n"
        )
        ->and($output)->not->toContain('when ready.')
        ->and($status)->toBe(0);
})->with([
    'told to restart' => ["0\n", true],
    'Enter, taking the default' => ["\n", true],
    'left to the default' => ['', false],
]);

/**
 * The rerun command names the first part, from which the locator finds the rest.
 */
it('names the first part in the rerun command', function (): void {
    iwpmLimit(IWPM_LIMIT);
    $first = iwpmPart($this, iwpmItem(IWPM_ITEM));
    $second = iwpmPart($this, iwpmItem(10));

    [$output] = iwpmCheck([$first, $second], "0\n");

    expect($output)->toContain("artisan statamic:import:wordpress {$first}\n")
        ->and($output)->not->toContain($second);
});

it('tells how to rerun the import with more memory, then ends the run', function (): void {
    [$command, $buffer] = iwpmCommand();

    $status = null;

    try {
        iwpmCall($command, 'restartWithIncreasedMemory', '640M', '/exports/site.xml');
    } catch (IwpmTerminated $terminated) {
        $status = $terminated->status;
    }

    expect($status)->toBe(0)
        ->and(str_replace(PHP_EOL, "\n", $buffer->fetch()))->toBe(
            "\n"
        ."Run this command with increased memory:\n"
        ."php artisan cache:clear && > storage/logs/laravel.log && php -d memory_limit=640M artisan statamic:import:wordpress /exports/site.xml\n"
        ."\n"
        ."Exiting. Please run the command above with the increased memory limit.\n"
        );
});
