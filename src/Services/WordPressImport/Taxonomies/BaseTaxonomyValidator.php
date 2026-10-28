<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies;

use Illuminate\Support\Facades\File as LaravelFileFacade;
use Statamic\Facades\Collection as CollectionFacade;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentRoot;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\TaxonomySlugProcessor;

/**
 * Base class for taxonomy validation with common functionality.
 *
 * Provides shared validation logic for all taxonomy validators, including
 * term existence checking, missing term creation, and caching mechanisms.
 * Follows the Template Method pattern to allow specific validators to
 * customize behavior while sharing common functionality.
 */
abstract class BaseTaxonomyValidator implements TaxonomyValidatorInterface
{
    /**
     * Cache for existing taxonomy terms to avoid repeated filesystem scans.
     *
     * @var array<string, array<string>>
     */
    private static array $existingTermsCache = [];

    /**
     * Tracks which terms have already been processed in the current session.
     *
     * @var array<string, bool>
     */
    private static array $processedTermsCache = [];

    /**
     * Get the taxonomy handle this validator is responsible for.
     *
     * @return string The taxonomy handle (e.g., 'people_groups', 'post_categories')
     */
    abstract protected function getTaxonomyHandle(): string;

    /**
     * Get the display name for this taxonomy type.
     *
     * @return string The display name (e.g., 'people group', 'post category')
     */
    abstract protected function getTaxonomyDisplayName(): string;

    /**
     * Get the shortcode or content type name for user messaging.
     *
     * @return string The content type name (e.g., 'people shortcodes', 'post entries')
     */
    abstract protected function getContentTypeName(): string;

    /**
     * Validates taxonomy terms used in content against existing terms.
     *
     * Template method that orchestrates the validation process:
     *   1. Checks if any terms are used
     *   2. Scans for missing terms
     *   3. Creates missing terms
     *   4. Provides user feedback
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
    ): void {
        // Get taxonomy handle once to avoid repeated method calls
        $taxonomyHandle = $this->getTaxonomyHandle();

        // Get content type name once to avoid repeated method calls
        $contentTypeName = $this->getContentTypeName();

        // Get taxonomy display name once to avoid repeated method calls
        $taxonomyDisplayName = $this->getTaxonomyDisplayName();

        // Display initial validation message
        $output->writeln("Validating {$contentTypeName} that reference '{$taxonomyHandle}' taxonomy terms...");

        // Check if any terms were found in the content
        if ($termsUsed === []) {
            // No terms found, inform user and exit early
            $output->writeln("  - <info>No {$contentTypeName} found that reference '{$taxonomyHandle}' taxonomy terms.</info>");

            // Exit the method since there's nothing to validate
            return;
        }

        // Inform user that we're checking for missing taxonomy terms
        $output->writeln("  - Checking if any taxonomy terms referenced in {$contentTypeName} don't exist in the Statamic '{$taxonomyHandle}' taxonomy...");

        // Get all existing terms for this taxonomy from the filesystem
        $existingTerms = $this->getExistingTerms($taxonomyHandle);

        // Initialize array to track missing terms
        $missingTerms = [];

        // Initialize array to track terms that need to be created
        $newTermsToProcess = [];

        // Check each used term against existing terms
        foreach ($termsUsed as $term => $details) {
            // Create a unique cache key for this term and site
            $termKey = "{$site}:{$term}";

            // Initialize variable to store the site-specific slug
            $siteSpecificSlug = null;

            // Check if details is a valid non-empty array
            if (
                \is_array($details)
                && $details !== []
            ) {
                // Get the first detail entry from the array
                $firstDetail = reset($details);

                if (\is_array($firstDetail)) {
                    // Extract the site-specific slug from the detail entry
                    $siteSpecificSlug = $firstDetail['site_specific'] ?? null;
                    $siteSpecificSlug = \is_string($siteSpecificSlug)
                        ? $siteSpecificSlug
                        : null;
                }
            }

            // Reduce the reference to the form its destination actually stores.
            // A flat taxonomy stores the whole hierarchical path as one slug; a
            // structured collection stores the leaf alone and carries the
            // hierarchy in its tree, so the two cannot be compared as written.
            $comparisonSlug = $siteSpecificSlug === null
                ? null
                : self::comparisonSlugFor(
                    $siteSpecificSlug,
                    $taxonomyHandle
                );

            // Check if the site-specific term is missing and hasn't been processed yet
            if (
                $comparisonSlug !== null
                && $comparisonSlug !== ''
                && ! \in_array(
                    $comparisonSlug,
                    $existingTerms,
                    true
                )
                && ! isset(self::$processedTermsCache[$termKey])
            ) {
                // Add to missing terms for reporting
                $missingTerms[$term] = $details;

                // Add to terms that need to be created
                $newTermsToProcess[$term] = $details;
            }
        }

        // Check if there are any terms that need to be created
        if ($newTermsToProcess !== []) {
            // Get the number of terms that need to be created
            $termCount = \count($newTermsToProcess);

            // Inform user about the number of terms that need to be created
            $output->writeln("      - Creating {$termCount} missing {$taxonomyDisplayName} terms...");

            // Create all missing terms using the centralized method
            $this->createMissingTerms(
                $newTermsToProcess,
                $collectionHandle,
                $site,
                $output
            );
        }

        // Check if there are any missing terms
        if ($missingTerms === []) {
            // All terms exist, inform the user
            $output->writeln("      - <info>All {$taxonomyDisplayName} terms referenced in {$contentTypeName} exist in the Statamic '{$taxonomyHandle}' taxonomy.</info>");
        }
    }

    /**
     * Creates missing taxonomy terms and logs the process.
     *
     * @param  array<int|string, mixed>  $missingTerms  Array of missing terms and their associated details
     * @param  string  $collectionHandle  The collection handle
     * @param  string  $site  The Statamic site handle
     * @param  OutputInterface  $output  Console output for logging
     */
    protected function createMissingTerms(
        array $missingTerms,
        string $collectionHandle,
        string $site,
        OutputInterface $output
    ): void {
        // Get collection information for better reporting
        $collection = CollectionFacade::findByHandle($collectionHandle);

        // Use collection title if available, otherwise use handle
        $collectionTitle = $collection !== null ? $collection->title() : $collectionHandle;
        $collectionName = \is_string($collectionTitle) ? $collectionTitle : $collectionHandle;

        // Inform user that we're creating missing terms
        $output->writeln("Creating missing {$this->getTaxonomyDisplayName()} terms...");

        // Process each missing term
        foreach ($missingTerms as $term => $details) {
            if (! \is_string($term)) {
                continue;
            }

            // Create cache key for this term and site
            $termKey = "{$site}:{$term}";

            // Create the missing term using BaseEntryCreator
            $created = BaseEntryCreator::createTaxonomyTerm(
                $this->getTaxonomyHandle(),
                $term,
                $site,
                $output
            );

            // Mark as processed regardless of creation result
            self::$processedTermsCache[$termKey] = $created;

            // Check if usage details are available
            if (
                \is_array($details)
                && $details !== []
            ) {
                // Extract usage details from the first entry
                $firstUsage = $details[0] ?? null;
                $usageDetails = \is_array($firstUsage) ? $firstUsage : $details;

                // Get the page slug for reporting
                $pageSlugValue = $usageDetails['page_slug'] ?? 'unknown';
                $pageSlug = \is_string($pageSlugValue) ? $pageSlugValue : 'unknown';

                // Report where this term is used
                $output->writeln("  - Used in: {$collectionName} > {$pageSlug}");
            }
        }

        // Inform user that term creation is complete
        $output->writeln("Missing {$this->getTaxonomyDisplayName()} terms creation completed.");
    }

    /**
     * Gets existing terms for a handle, from whichever shape actually holds them.
     *
     * A structured destination has no taxonomy directory at all, so scanning one
     * returns an empty list and every referenced term is reported missing. The
     * two shapes are read separately and both return **site-prefixed leaf
     * slugs**, so {@see self::validateTerms()} compares one form either way.
     *
     * @param  string  $statamicTaxonomyHandle  The Statamic destination handle
     * @return array<int|string, mixed> Array of existing site-prefixed term slugs
     */
    protected function getExistingTerms(
        string $statamicTaxonomyHandle
    ): array {
        // Check cache first
        if (isset(self::$existingTermsCache[$statamicTaxonomyHandle])) {
            // Return cached terms if available
            return self::$existingTermsCache[$statamicTaxonomyHandle];
        }

        // Read the shape this handle is actually stored in
        $existingTerms = TaxonomyDispositions::isStructuredDestination($statamicTaxonomyHandle)
            ? self::structuredEntrySlugs($statamicTaxonomyHandle)
            : self::flatTermSlugs($statamicTaxonomyHandle);

        // Cache the result
        self::$existingTermsCache[$statamicTaxonomyHandle] = $existingTerms;

        // Return the list of existing terms
        return $existingTerms;
    }

    /**
     * Read the term slugs a flat taxonomy holds on disk.
     *
     * Flat term files are already named with the site prefix and with the whole
     * hierarchical path, so the filename is the comparison form as it stands.
     *
     * @param  string  $statamicTaxonomyHandle  The Statamic taxonomy handle
     * @return list<string> The site-prefixed term slugs
     */
    private static function flatTermSlugs(
        string $statamicTaxonomyHandle
    ): array {
        // Build path to taxonomy terms directory, on the same tree the run
        // writes its own terms to
        $termsPath = ContentRoot::taxonomies()."/{$statamicTaxonomyHandle}";

        // Initialize array to store existing terms
        $existingTerms = [];

        // Check if taxonomy directory exists
        if (LaravelFileFacade::exists($termsPath)) {
            // Get all files in the taxonomy directory
            $files = LaravelFileFacade::files($termsPath);

            // Process each term file
            foreach ($files as $file) {
                // Extract filename without extension as term slug
                $filename = LaravelFileFacade::name($file->getPathname());

                // Add term slug to existing terms array
                $existingTerms[] = $filename;
            }
        }

        // Return the list of existing terms
        return $existingTerms;
    }

    /**
     * Read the entry slugs a structured collection holds on disk.
     *
     * Collection entries live at `<collections>/<collection>/<site>/<slug>.md`,
     * so the directory name is the site and the filename is the slug - bare,
     * because {@see StructuredTermImporter} writes the WordPress term slug
     * unchanged and carries the hierarchy in the collection's tree rather than
     * in the slug. Each is re-prefixed with its own site, so a term that exists
     * on one site cannot satisfy a reference from another.
     *
     * Read from the filesystem rather than queried, for the reason the flat path
     * already gives: this is the same tree the run writes its own entries to,
     * and it answers without depending on the Stache being current mid-run.
     *
     * @param  string  $collectionHandle  The Statamic collection handle
     * @return list<string> The site-prefixed entry slugs
     */
    private static function structuredEntrySlugs(
        string $collectionHandle
    ): array {
        // Build path to the collection directory, on the same tree the run
        // writes its own entries to
        $collectionPath = ContentRoot::collections()."/{$collectionHandle}";

        // Initialize array to store existing entry slugs
        $existingSlugs = [];

        // A collection that has never been written to has no directory
        if (! LaravelFileFacade::exists($collectionPath)) {
            return $existingSlugs;
        }

        // Each immediate subdirectory holds one site's entries
        foreach (LaravelFileFacade::directories($collectionPath) as $siteDirectory) {
            // The facade types its elements `mixed`, and anything that is not a
            // path cannot name a site
            if (! \is_string($siteDirectory)) {
                continue;
            }

            // The directory name is the site handle the entries were saved under
            $site = \basename($siteDirectory);

            // Process each entry file in that site
            foreach (LaravelFileFacade::files($siteDirectory) as $file) {
                // Rebuild the site-prefixed form the reference is compared as
                $existingSlugs[] = TaxonomySlugProcessor::addSitePrefixToSlug(
                    LaravelFileFacade::name($file->getPathname()),
                    $site
                );
            }
        }

        // Return the list of existing entry slugs
        return $existingSlugs;
    }

    /**
     * Reduce a site-specific reference to the form its destination stores.
     *
     * A flat taxonomy stores the whole hierarchical path as its slug, so the
     * reference is already the stored form and is returned unchanged. A
     * structured collection stores only the leaf, so `site_a_b_c` reduces to
     * `site_c`.
     *
     * The reduction is exact under the encoding the importer already relies on:
     * {@see BaseEntryCreator::getFullTermSlug()} joins ancestors with `_`, and
     * {@see TaxonomySlugProcessor::addSitePrefixToSlug()} rewrites the site
     * handle's own underscores to hyphens before prefixing - so every `_` that
     * survives into the reference is a separator one of those two put there. A
     * WordPress term slug carrying a literal `_` would be ambiguous here, and is
     * equally ambiguous to the join that produced the reference.
     *
     * Matching the leaf alone is not a weakening: an entry slug is unique within
     * a collection and site, so the leaf identifies exactly one entry.
     *
     * @param  string  $siteSpecificSlug  The site-prefixed reference slug
     * @param  string  $statamicTaxonomyHandle  The Statamic destination handle
     * @return string The slug in the form the destination stores
     */
    private static function comparisonSlugFor(
        string $siteSpecificSlug,
        string $statamicTaxonomyHandle
    ): string {
        // A flat destination stores the reference exactly as written
        if (! TaxonomyDispositions::isStructuredDestination($statamicTaxonomyHandle)) {
            return $siteSpecificSlug;
        }

        // Split off the site prefix, which is everything before the first separator
        $sitePrefixEnd = \strpos($siteSpecificSlug, '_');

        // Without a separator there is no prefix to keep and no path to reduce
        if ($sitePrefixEnd === false) {
            return $siteSpecificSlug;
        }

        // Everything after the site prefix is the underscore-joined term path
        $termPath = \substr($siteSpecificSlug, $sitePrefixEnd + 1);

        // The leaf is the last segment of that path
        $leafStart = \strrpos($termPath, '_');
        $leaf = $leafStart === false
            ? $termPath
            : \substr($termPath, $leafStart + 1);

        // Reattach the site prefix so the comparison stays site-scoped
        return \substr($siteSpecificSlug, 0, $sitePrefixEnd + 1).$leaf;
    }

    /**
     * Clears all caches for a new import session.
     */
    public static function clearCaches(): void
    {
        // Clear existing terms cache
        self::$existingTermsCache = [];

        // Clear processed terms cache
        self::$processedTermsCache = [];
    }
}
