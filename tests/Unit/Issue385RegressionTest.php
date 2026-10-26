<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Traits\HasPromptsSupport;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Traits\HasQuestionHelper;

/**
 * Regression test for issue wordpress-importer-archive#385.
 *
 * The four unsupported-content gates (Classic, Freeform, Details, and KB
 * entries without topics) previously defaulted a non-interactive confirm to
 * `false`, silently skipping the whole content type with a success exit. The
 * fix routes every gate through HasQuestionHelper::resolveUnsupportedContent(),
 * which in a non-interactive run returns the configured --unsupported-content
 * policy verbatim ("abort", "skip", or "import") instead of always skipping.
 *
 * These assertions exercise only the non-interactive branch of the resolver,
 * which returns the policy without touching the Command, Laravel Prompts, or
 * any Statamic/app context — so the test runs standalone (no host app). The
 * interactive branch (which invokes Laravel Prompts / a TTY) is covered by the
 * host-app command harness (see deferred wordpress-importer-archive#366 note).
 *
 * @command  php vendor/bin/pest tests/Unit/Issue385RegressionTest.php
 */

/**
 * Resolve an unsupported-content policy through the real trait method.
 *
 * Mixes the prompt traits into an anonymous harness and exposes the protected
 * resolver publicly, so the non-interactive branch can be asserted directly
 * without a Command, Laravel Prompts, or any app context.
 *
 * @param  string  $policy  The configured non-interactive policy (abort|skip|import)
 * @param  InputInterface  $input  Console input interface
 * @param  OutputInterface  $output  Console output interface
 * @param  Command  $command  Command instance
 * @return string The resolved decision: "import", "skip", or "abort"
 */
function resolveUnsupportedContentPolicy(
    string $policy,
    InputInterface $input,
    OutputInterface $output,
    Command $command
): string {
    $harness = new class
    {
        use HasPromptsSupport;
        use HasQuestionHelper;

        /**
         * Publicly invoke the protected unsupported-content resolver.
         *
         * @param  string  $policy  The configured non-interactive policy (abort|skip|import)
         * @param  InputInterface  $input  Console input interface
         * @param  OutputInterface  $output  Console output interface
         * @param  Command  $command  Command instance
         * @return string The resolved decision: "import", "skip", or "abort"
         */
        public function resolve(
            string $policy,
            InputInterface $input,
            OutputInterface $output,
            Command $command
        ): string {
            return $this->resolveUnsupportedContent(
                'Would you like to continue?',
                $policy,
                $input,
                $output,
                $command
            );
        }
    };

    return $harness->resolve(
        $policy,
        $input,
        $output,
        $command
    );
}

it('resolves a non-interactive run to the configured policy verbatim', function (string $policy): void {
    // Build a non-interactive input stub so the resolver takes the
    // policy-driven branch rather than prompting.
    $input = $this->createMock(InputInterface::class);
    $input->method('isInteractive')->willReturn(false);

    $resolved = resolveUnsupportedContentPolicy(
        $policy,
        $input,
        $this->createMock(OutputInterface::class),
        $this->createMock(Command::class)
    );

    expect($resolved)->toBe($policy);
})->with([
    'abort policy resolves to abort' => ['abort'],
    'skip policy resolves to skip' => ['skip'],
    'import policy resolves to import' => ['import'],
]);
