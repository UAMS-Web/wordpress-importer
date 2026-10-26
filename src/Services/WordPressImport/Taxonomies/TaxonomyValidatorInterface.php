<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Interface for validating taxonomy terms used in imported content.
 *
 * Provides a polymorphic approach to taxonomy validation, allowing different
 * content types to have their own validation logic while maintaining a
 * consistent interface. This follows the Single Responsibility Principle
 * by separating validation concerns by taxonomy type.
 */
interface TaxonomyValidatorInterface
{
    /**
     * Validates taxonomy terms used in content against existing terms.
     *
     * Checks if terms referenced in content exist in their respective taxonomies
     * and creates missing terms as needed. Logs validation results and missing
     * terms for user feedback.
     *
     * @param  array<int|string, mixed>  $termsUsed  Associative array of terms and their associated details
     * @param  string  $collectionHandle  The collection handle (e.g., 'people', 'posts')
     * @param  string  $site  The Statamic site handle
     * @param  OutputInterface  $output  Console output for logging
     */
    public function validateTerms(
        array $termsUsed,
        string $collectionHandle,
        string $site,
        OutputInterface $output
    ): void;
}
