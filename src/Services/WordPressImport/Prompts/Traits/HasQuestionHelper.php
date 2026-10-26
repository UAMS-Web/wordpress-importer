<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Traits;

use Illuminate\Console\Command;
use LogicException;
use Stringable;
use Symfony\Component\Console\Exception\MissingInputException;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Trait providing centralized QuestionHelper functionality.
 *
 * This trait eliminates code duplication by providing common methods
 * for handling both Laravel Prompts and Symfony Console interactions.
 * It centralizes the QuestionHelper retrieval and common question patterns.
 */
trait HasQuestionHelper
{
    /**
     * Get the QuestionHelper instance from a command.
     *
     * @param  ?Command  $command  The command instance (optional if called from within a command)
     * @return QuestionHelper The question helper instance
     */
    protected function getQuestionHelper(
        ?Command $command = null
    ): QuestionHelper {
        // Check if no command is provided and we're in a command context
        if (
            ! $command instanceof Command
            && $this instanceof Command
        ) {
            // Set the command to the current instance
            $command = $this;
        }

        // Fail fast when no command context is available
        if (! $command instanceof Command) {
            throw new LogicException(
                'Question helper requires a command instance.'
            );
        }

        // Get the helper from the command helper set
        $helper = $command->getHelper('question');

        // Ensure the resolved helper is the expected QuestionHelper instance
        if (! $helper instanceof QuestionHelper) {
            throw new LogicException(
                'Command question helper must be a QuestionHelper instance.'
            );
        }

        // Return the QuestionHelper instance
        return $helper;
    }

    /**
     * Ask a confirmation question using the appropriate prompt system.
     *
     * @param  string  $question  The question text
     * @param  bool  $default  The default value
     * @param  InputInterface  $input  Console input interface
     * @param  OutputInterface  $output  Console output interface
     * @param  Command  $command  Command instance
     * @return bool The user's response
     */
    protected function askConfirmation(
        string $question,
        bool $default,
        InputInterface $input,
        OutputInterface $output,
        Command $command
    ): bool {
        // Check if Laravel Prompts is supported and input is interactive
        if (
            $this->isPromptsSupported()
            && $input->isInteractive()
        ) {
            // Use Laravel Prompts for confirmation
            return confirm(
                label: $question,
                default: $default
            );
        }

        // ---
        // Ask the confirmation through Laravel's command helper so
        // `expectsQuestion()` can intercept it in tests.
        // ---
        return $command->confirm(
            $question,
            $default
        );
    }

    /**
     * Resolve the policy for an unsupported-content gate.
     *
     * Interactive runs are unchanged: the operator is prompted exactly as
     * before (default No = do not continue), which resolves to "import" when
     * confirmed and "skip" when declined. Non-interactive runs cannot prompt,
     * so the supplied `--unsupported-content` policy ("abort", "skip", or
     * "import") is used instead.
     *
     * @param  string  $question  The confirmation question text
     * @param  string  $policy  The configured non-interactive policy (abort|skip|import)
     * @param  InputInterface  $input  Console input interface
     * @param  OutputInterface  $output  Console output interface
     * @param  Command  $command  Command instance
     * @return string The resolved decision: "import", "skip", or "abort"
     */
    protected function resolveUnsupportedContent(
        string $question,
        string $policy,
        InputInterface $input,
        OutputInterface $output,
        Command $command
    ): string {
        // Interactive runs prompt the operator exactly as before (default No).
        if ($input->isInteractive()) {
            // Reuse the shared confirmation helper so interactive behavior is identical.
            return $this->askConfirmation(
                $question,
                false,
                $input,
                $output,
                $command
            )
                ? 'import'
                : 'skip';
        }

        // Non-interactive runs fall back to the configured policy.
        return $policy;
    }

    /**
     * Ask a choice question using the appropriate prompt system.
     *
     * @param  string  $question  The question text
     * @param  array<int|string, mixed>  $options  The available options
     * @param  string|int  $default  The default option (key or index)
     * @param  InputInterface  $input  Console input interface
     * @param  OutputInterface  $output  Console output interface
     * @param  Command  $command  Command instance
     * @return string The selected option
     */
    protected function askChoice(
        string $question,
        array $options,
        int|string $default,
        InputInterface $input,
        OutputInterface $output,
        Command $command
    ): string {
        // Normalize option values so both prompt systems receive string labels
        $normalizedOptions = $this->normalizeChoiceOptions(
            $options
        );

        // Check if input is non-interactive
        if (! $input->isInteractive()) {
            // Check if the default is an integer and is set in the options
            if (
                \is_int($default)
                && isset($normalizedOptions[$default])
            ) {
                // Return the default option as a string
                return $normalizedOptions[$default];
            }

            // Check if the default is a string and is set in the options
            if (
                \is_string($default)
                && isset($normalizedOptions[$default])
            ) {
                // Return the default option as a string
                return $normalizedOptions[$default];
            }

            // Get the first option
            $firstOption = reset($normalizedOptions);

            // Return the first option as a string
            return $firstOption === false ? '' : $firstOption;
        }

        // Check if Laravel Prompts is supported (input already confirmed interactive above)
        if ($this->isPromptsSupported()) {
            // Use Laravel Prompts for choice selection
            $selectedOption = select(
                label: $question,
                options: $normalizedOptions,
                default: $default
            );

            // Return the selected option key as a normalized string
            return (string) $selectedOption;
        }

        // Attempt to ask using Laravel command helper for test interception
        try {
            // Use Laravel command helper to select the choice
            $selectedOption = $command->choice(
                $question,
                $normalizedOptions,
                $default
            );

            // Return the selected option value as a normalized string
            if (\is_array($selectedOption)) {
                // Fall back to the first selected option in unexpected array responses
                $firstSelectedOption = reset($selectedOption);

                // Return a safe string representation of the first selected option
                if (
                    \is_string($firstSelectedOption)
                    || \is_int($firstSelectedOption)
                    || \is_float($firstSelectedOption)
                    || \is_bool($firstSelectedOption)
                    || $firstSelectedOption instanceof Stringable
                ) {
                    return (string) $firstSelectedOption;
                }

                return '';
            }

            return $selectedOption;
        } catch (MissingInputException) {
            // Check if the default is an integer and is set in the options
            if (
                \is_int($default)
                && isset($normalizedOptions[$default])
            ) {
                // Return the default option as a string
                return $normalizedOptions[$default];
            }

            // Check if the default is a string and is set in the options
            if (
                \is_string($default)
                && isset($normalizedOptions[$default])
            ) {
                // Return the default option as a string
                return $normalizedOptions[$default];
            }

            // Get the first option
            $firstOption = reset($normalizedOptions);

            // Return the first option as a string
            return $firstOption === false ? '' : $firstOption;
        }
    }

    /**
     * Ask a text question using the appropriate prompt system.
     *
     * @param  string  $question  The question text
     * @param  InputInterface  $input  Console input interface
     * @param  OutputInterface  $output  Console output interface
     * @param  Command  $command  Command instance
     * @param  bool  $required  Whether the input is required
     * @param  ?string  $default  The default value
     * @param  ?string  $placeholder  The placeholder text
     * @param  ?callable  $validator  Optional validator function
     * @return ?string The user's response
     */
    protected function askText(
        string $question,
        InputInterface $input,
        OutputInterface $output,
        Command $command,
        bool $required = false,
        ?string $default = null,
        ?string $placeholder = null,
        ?callable $validator = null
    ): ?string {
        // Check if input is non-interactive
        if (! $input->isInteractive()) {
            // Return the default value
            return $default;
        }

        // Check if Laravel Prompts is supported (input already confirmed interactive above)
        if ($this->isPromptsSupported()) {
            // Use Laravel Prompts for text input
            return text(
                label: $question,
                placeholder: $placeholder ?? '',
                default: $default ?? '',
                required: $required
            );
        }

        // Attempt to ask using Laravel command helper for test interception
        try {
            // Use Laravel command helper to ask the text question
            $response = $command->ask(
                $question,
                $default
            );
            // Return string responses and normalize scalar values to strings
            if (\is_string($response)) {
                return $response;
            }

            // Return string responses and normalize scalar values to strings
            if (\is_int($response)
            || \is_float($response)
            || \is_bool($response)
            || $response instanceof Stringable) {
                return (string) $response;
            }

            // Return null for unsupported response types
            return null;
        } catch (MissingInputException) {
            // Return the default value
            return $default;
        }
    }

    /**
     * Normalize mixed choice options into keyed string labels.
     *
     * @param  array<int|string, mixed>  $options  Options to normalize
     * @return array<int|string, string> Normalized option labels
     */
    private function normalizeChoiceOptions(
        array $options
    ): array {
        // Initialize the normalized option map
        $normalizedOptions = [];

        // Normalize each option value into a displayable string
        foreach ($options as $optionKey => $optionValue) {
            // Keep string values unchanged
            if (\is_string($optionValue)) {
                $normalizedOptions[$optionKey] = $optionValue;
            } elseif (
                \is_int($optionValue)
                || \is_float($optionValue)
                || \is_bool($optionValue)
                || $optionValue instanceof Stringable
            ) {
                // Convert scalar and Stringable values into strings
                $normalizedOptions[$optionKey] = (string) $optionValue;
            } else {
                // Use the option key as a safe fallback label
                $normalizedOptions[$optionKey] = (string) $optionKey;
            }
        }

        // Return normalized options for downstream prompt APIs
        return $normalizedOptions;
    }

    /**
     * Ask a question with custom validation using the appropriate prompt system.
     *
     * @param  string  $question  The question text
     * @param  callable  $validator  The validation function
     * @param  InputInterface  $input  Console input interface
     * @param  OutputInterface  $output  Console output interface
     * @param  Command  $command  Command instance
     * @param  ?string  $default  The default value
     * @return mixed The validated user response
     */
    protected function askWithValidation(
        string $question,
        callable $validator,
        InputInterface $input,
        OutputInterface $output,
        Command $command,
        ?string $default = null
    ): mixed {
        // Check if input is non-interactive
        if (! $input->isInteractive()) {
            // Return the default value
            return $default;
        }

        // Check if Laravel Prompts is supported (input already confirmed interactive above)
        if ($this->isPromptsSupported()) {
            // For Laravel Prompts, we need to handle validation differently
            // since it doesn't support custom validators like Symfony Console
            $response = text(
                label: $question,
                default: $default ?? '',
                required: false
            );

            // Attempt to validate the response
            try {
                return $validator($response);
            }

            // Handle validation errors by displaying error and retrying
            catch (\Exception $e) {
                // Display the validation error message
                $output->writeln('<error>'.$e->getMessage().'</error>');

                // Recursively call this method to retry the question
                return $this->askWithValidation(
                    $question,
                    $validator,
                    $input,
                    $output,
                    $command,
                    $default
                );
            }
        }

        // Attempt to ask with validator using Laravel command helper for test interception
        while (true) {
            // Attempt to ask the question with the default value
            try {
                // Use Laravel command helper to ask the question with the default value
                $response = $command->ask(
                    $question,
                    $default
                );
            } catch (MissingInputException) {
                // Return the default value
                return $default;
            }

            // Attempt to validate the response
            try {
                // Return the validated response
                return $validator($response);
            } catch (\Exception $e) {
                // Display the validation error message
                $output->writeln('<error>'.$e->getMessage().'</error>');
            }
        }
    }
}
