<?php

declare(strict_types=1);

/**
 * Tests for the Formidable Forms user interaction handler and the
 * UserInteractionHandlerTrait it uses (#1314).
 *
 * When the export holds Formidable Forms content, the handler announces it, asks the
 * selector for the forms, stores what comes back in the data manager, and
 * reports whether any forms were supplied. Without such content it does
 * nothing. The detector and selector are stubbed, so no export or prompt is
 * involved.
 *
 * @command  php vendor/bin/pest tests/Unit/FormidableFormsUserInteractionHandlerTest.php
 */

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\ContentDetector;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\DataManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\UserInteractionHandler;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\FormidableForms\Selector;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\FormidableForms\Validator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\Loaders\FormidableFormsLoader;

/**
 * A detector that reports the given answer without reading the export.
 *
 * @param  bool  $hasContent  Whether the export holds Formidable Forms content
 * @return ContentDetector The detector
 */
function FormidableFormsHandlerDetector(bool $hasContent): ContentDetector
{
    return new class($hasContent) extends ContentDetector
    {
        /**
         * Hold the fixed answer.
         *
         * @param  bool  $hasContent  The answer to report
         */
        public function __construct(private readonly bool $hasContent)
        {
            parent::__construct();
        }

        /**
         * Report the fixed answer.
         *
         * @param  XmlParser  $xmlParser  Unused
         * @return bool The fixed answer
         */
        public function hasFormPluginContent(XmlParser $xmlParser): bool
        {
            return $this->hasContent;
        }
    };
}

/**
 * A selector that returns the given forms, writing a line each time it is asked.
 *
 * @param  array<int|string, mixed>|null  $forms  What select() returns
 * @return Selector The selector
 */
function FormidableFormsHandlerSelector(?array $forms): Selector
{
    return new class($forms) extends Selector
    {
        /**
         * Hold the fixed result.
         *
         * @param  array<int|string, mixed>|null  $forms  What select() returns
         */
        public function __construct(private readonly ?array $forms)
        {
            parent::__construct(new Validator, new FormidableFormsLoader);
        }

        /**
         * Return the fixed result, noting the call on the output.
         *
         * @param  InputInterface  $input  Unused
         * @param  OutputInterface  $output  Where the call is noted
         * @param  Command  $command  Unused
         * @return array<int|string, mixed>|null The fixed result
         */
        public function select(InputInterface $input, OutputInterface $output, Command $command): ?array
        {
            $output->writeln('selector asked');

            return $this->forms;
        }
    };
}

/**
 * Run detection with the given detector answer and selector result.
 *
 * @param  bool  $hasContent  Whether the export holds Formidable Forms content
 * @param  array<int|string, mixed>|null  $forms  What the selector returns
 * @return array{result: bool, output: string, data: array<int|string, mixed>|null} What happened
 */
function runFormidableFormsHandler(bool $hasContent, ?array $forms): array
{
    $dataManager = new DataManager;
    $selector = FormidableFormsHandlerSelector($forms);
    $output = new BufferedOutput;

    $result = (new UserInteractionHandler(FormidableFormsHandlerDetector($hasContent), $dataManager, $selector))
        ->handleFormPluginDetection(new XmlParser, new ArrayInput([]), $output, new Command);

    // `BufferedOutput` ends each line with `PHP_EOL`, which is CRLF on Windows, so fold
    // line breaks to LF: the lines printed are under test, not how a console ends one
    return [
        'result' => $result,
        'output' => \preg_replace('/\R/', "\n", $output->fetch()) ?? '',
        'data' => $dataManager->getFormsData(),
    ];
}

it('announces the content, asks for the forms, and stores what is supplied', function (): void {
    $forms = [['id' => 1]];

    expect(runFormidableFormsHandler(true, $forms))->toBe([
        'result' => true,
        'output' => "Formidable Forms blocks or shortcodes detected in the XML.\nselector asked\n",
        'data' => $forms,
    ]);
});

it('stores an empty list as supplied forms', function (): void {
    expect(runFormidableFormsHandler(true, []))->toMatchArray(['result' => true, 'data' => []]);
});

it('reports no forms when the selector supplies none', function (): void {
    expect(runFormidableFormsHandler(true, null))->toBe([
        'result' => false,
        'output' => "Formidable Forms blocks or shortcodes detected in the XML.\nselector asked\n",
        'data' => null,
    ]);
});

it('does nothing when the export has no Formidable Forms content', function (): void {
    expect(runFormidableFormsHandler(false, [['id' => 1]]))->toBe([
        'result' => false,
        'output' => '',
        'data' => null,
    ]);
});
