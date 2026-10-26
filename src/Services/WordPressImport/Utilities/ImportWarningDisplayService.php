<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities;

use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormWarningFormatter;

/**
 * Service for displaying import warnings in a user-friendly format.
 *
 * This class handles the formatting and display of all warnings collected during
 * the WordPress import process. It simply dumps all warnings to the console.
 */
class ImportWarningDisplayService
{
    /**
     * Display all collected warnings to the console output.
     *
     * @param  OutputInterface  $output  The console output interface
     */
    public static function displayAllWarnings(
        OutputInterface $output
    ): void {
        // Get all warnings from the centralized warning manager
        $allWarnings = ImportWarningManager::getAllWarnings();

        // If there are no warnings, return early
        if ($allWarnings === []) {
            return;
        }

        // Display warnings by category
        foreach ($allWarnings as $category => $categoryWarnings) {
            if (
                ! \is_string($category)
                || ! \is_array($categoryWarnings)
            ) {
                continue;
            }

            // Iterate through each warning type within the category
            foreach ($categoryWarnings as $type => $warnings) {
                if (
                    ! \is_string($type)
                    || ! \is_array($warnings)
                ) {
                    continue;
                }

                // Check if this is a form-specific warning type that has a custom formatter
                if (
                    $category === 'forms'
                    && $type === 'unsupported_attributes'
                ) {
                    // Display unsupported form attribute warnings using custom formatter
                    FormWarningFormatter::displayUnsupportedFormAttributeWarnings(
                        $output,
                        $warnings
                    );
                } elseif (
                    $category === 'forms'
                    && $type === 'unmapped_subfields'
                ) { // Check if this is unmapped subfields warning type
                    // Display unmapped subfield warnings using custom formatter
                    FormWarningFormatter::displayUnmappedSubfieldWarnings(
                        $output,
                        $warnings
                    );
                } elseif (
                    $category === 'forms'
                    && $type === 'unmapped_fieldtypes'
                ) { // Check if this is unmapped fieldtypes warning type
                    // Display unmapped fieldtype warnings using custom formatter
                    FormWarningFormatter::displayUnmappedFieldtypeWarnings(
                        $output,
                        $warnings
                    );
                } elseif (
                    $category === 'content'
                    && $type === 'unsupported_embeds'
                ) { // Check if this is unsupported embeds warning type
                    // Display unsupported embed warnings using custom formatter
                    FormWarningFormatter::displayUnsupportedEmbedWarnings(
                        $output,
                        $warnings
                    );
                } elseif (
                    $category === 'content'
                    && $type === 'unaddressed_shortcodes'
                ) { // Check if this is unaddressed shortcodes warning type
                    // Display unaddressed shortcode warnings using custom formatter
                    FormWarningFormatter::displayUnaddressedShortcodeWarnings(
                        $output,
                        $warnings
                    );
                } elseif (
                    $category === 'content'
                    && $type === 'malformed_shortcodes'
                ) { // Check if this is malformed shortcodes warning type
                    // Display malformed shortcode warnings using custom formatter
                    FormWarningFormatter::displayMalformedShortcodeWarnings(
                        $output,
                        $warnings
                    );
                } else { // Otherwise
                    // Fallback for other warning types

                    // Output the warning category and type as a section header
                    $output->writeln("<comment>{$category} - {$type}:</comment>");

                    // Output each warning in the current type as JSON
                    foreach ($warnings as $warning) {
                        // Output the warning data as a JSON string for readability
                        $output->writeln(' - '.\json_encode($warning));
                    }

                    // Add a blank line after each warning type for readability
                    $output->writeln('');
                }
            }
        }

        // Clean up variables to free up memory
        unset(
            $allWarnings,
            $category,
            $categoryWarnings,
            $type,
            $warnings
        );
    }

    /**
     * Public wrapper method for displayAllWarnings() to enable mocking in tests.
     * This method should only be used for testing purposes.
     *
     * @param  OutputInterface  $output  The console output interface
     */
    public function displayAllWarningsWrapper(
        OutputInterface $output
    ): void {
        self::displayAllWarnings($output);
    }
}
