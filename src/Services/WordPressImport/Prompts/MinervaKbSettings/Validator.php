<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Validates MinervaKB settings content for correctness and completeness.
 *
 * This class is responsible for:
 *   - Validating that the parsed content appears to be MinervaKB settings
 *   - Checking for required MinervaKB-specific keys
 *   - Ensuring the settings structure is valid
 *   - Providing meaningful validation error messages
 *
 * Follows the Single Responsibility Principle by focusing solely on content
 * validation.
 */
class Validator
{
    /**
     * Validate that the parsed settings appear to be from a MinervaKB settings file.
     *
     * @param  array<int|string, mixed>  $settings  The parsed settings array
     * @param  OutputInterface  $output  Console output interface
     * @return bool True if the settings appear valid, false otherwise
     */
    public function validateSettings(
        array $settings,
        OutputInterface $output
    ): bool {
        if (! $this->hasRequiredMinervaKbKeys($settings)) {
            $output->writeln('<error>The file does not appear to be a valid MinervaKB settings file.</error>');
            Log::error('Validator::validateSettings: File does not appear to be a valid MinervaKB settings file');

            return false;
        }

        return true;
    }

    /**
     * Check if the parsed settings contain required MinervaKB-specific keys.
     *
     * Validates that the settings array contains key MinervaKB configuration
     * options that should be present in a valid MinervaKB settings file.
     *
     * @param  array<int|string, mixed>  $settings  The parsed settings array
     * @return bool True if required keys are present, false otherwise
     */
    protected function hasRequiredMinervaKbKeys(
        array $settings
    ): bool {
        // Check for key MinervaKB-specific settings that should be present
        $requiredKeys = [
            'search_title',
            'search_placeholder',
            'breadcrumbs_home_label',
            'cpt_label_name',
            'cpt_topic_label_name',
        ];

        return array_all($requiredKeys, fn (string $key): bool => \array_key_exists(
            $key,
            $settings
        ));
    }
}
