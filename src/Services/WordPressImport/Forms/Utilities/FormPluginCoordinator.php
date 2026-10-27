<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities;

use Illuminate\Console\Command;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Forms\Form;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\FormidableFormsCoordinator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\GravityFormsCoordinator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms\NinjaFormsCoordinator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\EntryQueryHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\FormidableFormsBlockProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\FormidableFormsShortcodeProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\FormProcessorTrait;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\GravityFormsBlockProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\GravityFormsShortcodeProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\NinjaFormsBlockProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\NinjaFormsShortcodeProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Traits\StacheCacheManagementTrait;

/**
 * Coordinates form creation for different WordPress form plugins.
 *
 * This class acts as a central coordinator for creating Statamic forms
 * from various WordPress form plugins (Gravity Forms, Formidable Forms, etc.)
 * regardless of whether the forms are referenced via blocks or shortcodes.
 * It determines which plugin is being used and delegates to the appropriate
 * plugin coordinator for detection, user interaction, and form creation.
 *
 * Follows the Single Responsibility Principle by focusing solely on
 * determining the appropriate plugin coordinator and delegating operations.
 */
class FormPluginCoordinator
{
    /**
     * Use form processor functionality for form handle management.
     */
    use FormProcessorTrait;

    /**
     * Use Stache cache management functionality.
     */
    use StacheCacheManagementTrait;

    /**
     * Form IDs the imported content references, with the pages referencing them.
     *
     * Keyed by plugin display name, then by form ID. Captured during detection, because
     * that is where an XmlParser is in scope — `processFormImports()` runs later and
     * receives no parser. Read back when reconciling against the supplied export.
     *
     * @var array<string, array<array-key, list<string>>>
     */
    protected array $referencedFormPages = [];

    /**
     * Create a new FormPluginCoordinator instance.
     *
     * @param  GravityFormsCoordinator  $gravityFormsCoordinator  Service for Gravity Forms operations
     * @param  FormidableFormsCoordinator  $formidableFormsCoordinator  Service for Formidable Forms operations
     * @param  NinjaFormsCoordinator  $ninjaFormsCoordinator  Service for Ninja Forms operations
     */
    public function __construct(protected GravityFormsCoordinator $gravityFormsCoordinator, protected FormidableFormsCoordinator $formidableFormsCoordinator, protected NinjaFormsCoordinator $ninjaFormsCoordinator) {}

    /**
     * Handle form plugin detection and user interaction.
     *
     * This method detects which form plugins are being used in the XML
     * and handles user interaction for each detected plugin. It delegates
     * to the appropriate plugin coordinator for plugin-specific operations.
     *
     * @param  XmlParser  $xmlParser  The XML parser instance
     * @param  InputInterface  $input  Console input interface
     * @param  OutputInterface  $output  Console output interface
     * @param  Command  $command  Command instance
     * @return bool True if any form plugin data was loaded, false otherwise
     */
    public function handleFormPluginDetection(
        XmlParser $xmlParser,
        InputInterface $input,
        OutputInterface $output,
        Command $command
    ): bool {
        $anyDataLoaded = false;

        // Handle Gravity Forms detection and user interaction
        if ($this->gravityFormsCoordinator->hasGravityFormsContent($xmlParser)) {
            // Run the detection and user interaction workflow for Gravity Forms
            $gravityFormsDataLoaded = $this->gravityFormsCoordinator->handleGravityFormsDetection(
                $xmlParser,
                $input,
                $output,
                $command
            );

            // Capture what the content references, with the pages, while an XmlParser
            // is still in scope. Reconciled against the supplied export later.
            $this->referencedFormPages['Gravity Forms'] = $this->gravityFormsCoordinator->getGravityFormsFormIdsWithPages($xmlParser);

            // Update the flag if any Gravity Forms data was loaded
            $anyDataLoaded = $gravityFormsDataLoaded;
        }

        // Handle Formidable Forms detection and user interaction
        if ($this->formidableFormsCoordinator->hasFormidableFormsContent($xmlParser)) {
            // Run the detection and user interaction workflow for Formidable Forms
            $formidableFormsDataLoaded = $this->formidableFormsCoordinator->handleFormidableFormsDetection(
                $xmlParser,
                $input,
                $output,
                $command
            );

            // Capture what the content references, with the pages, while an XmlParser
            // is still in scope. Reconciled against the supplied export later.
            $this->referencedFormPages['Formidable Forms'] = $this->formidableFormsCoordinator->getFormidableFormsFormIdsWithPages($xmlParser);

            // Update the flag if any Formidable Forms data was loaded
            $anyDataLoaded = $anyDataLoaded || $formidableFormsDataLoaded;
        }

        // Handle Ninja Forms detection and user interaction
        if ($this->ninjaFormsCoordinator->hasNinjaFormsContent($xmlParser)) {
            // Run the detection and user interaction workflow for Ninja Forms
            $ninjaFormsDataLoaded = $this->ninjaFormsCoordinator->handleNinjaFormsDetection(
                $xmlParser,
                $input,
                $output,
                $command
            );

            // Capture what the content references, with the pages, while an XmlParser
            // is still in scope. Reconciled against the supplied export later.
            $this->referencedFormPages['Ninja Forms'] = $this->ninjaFormsCoordinator->getNinjaFormsFormIdsWithPages($xmlParser);

            // Update the flag if any Ninja Forms data was loaded
            $anyDataLoaded = $anyDataLoaded || $ninjaFormsDataLoaded;
        }

        // Return true if any form plugin data was loaded, false otherwise
        return $anyDataLoaded;
    }

    /**
     * Clear all form creation caches and data.
     *
     * Useful for resetting between different import sessions.
     */
    public function clearAllCaches(): void
    {
        // Clear Gravity Forms data from the coordinator
        $this->gravityFormsCoordinator->clearGravityFormsData();

        // Clear Formidable Forms data from the coordinator
        $this->formidableFormsCoordinator->clearFormidableFormsData();

        // Clear Ninja Forms data from the coordinator
        $this->ninjaFormsCoordinator->clearNinjaFormsData();

        // Clear static caches using trait properties
        static::$createdGravityFormsHandles = [];
        static::$createdFormidableFormsHandles = [];
        static::$createdNinjaFormsHandles = [];
    }

    /**
     * Post-processes imported entries to create Statamic forms for referenced Gravity
     * Forms and Formidable Forms IDs.
     *
     * This method collects all form IDs from the processors and creates Statamic
     * forms using the appropriate coordinators. It resolves the site name from the
     * site handle and passes it to the form creation methods.
     *
     * @param  string  $siteHandle  The Statamic site handle
     * @param  OutputInterface  $output  Console output interface
     * @param  AssetImporter  $assetImporter  The asset importer instance
     * @param  array<int|string, mixed>  $pageDataMapping  Optional page data mapping for WordPress post ID to Statamic entry ID
     *                                                     translation
     */
    public function processFormImports(
        string $siteHandle,
        OutputInterface $output,
        AssetImporter $assetImporter,
        array $pageDataMapping = []
    ): void {
        // Resolve the site name from the site handle using Statamic's Site facade
        $siteInstance = Site::get($siteHandle);
        $siteName = $siteHandle;
        if (
            \is_object($siteInstance)
            && method_exists(
                $siteInstance,
                'name'
            )
        ) {
            $resolvedSiteName = $siteInstance->name();
            if (\is_string($resolvedSiteName) && $resolvedSiteName !== '') {
                $siteName = $resolvedSiteName;
            }
        }

        // Set page data mapping on form creation coordinators if available
        if ($pageDataMapping !== []) {
            // Set page data mapping on Gravity Forms coordinator if it has a form creation coordinator
            $gravityFormsFormCreationCoordinator = $this->gravityFormsCoordinator->getFormCreationCoordinator();
            if (
                $gravityFormsFormCreationCoordinator instanceof FormPluginFormCreationCoordinatorContract
                && method_exists(
                    $gravityFormsFormCreationCoordinator,
                    'setPageDataMapping'
                )
            ) {
                $gravityFormsFormCreationCoordinator->setPageDataMapping($pageDataMapping);
            }

            // Set page data mapping on Formidable Forms coordinator if it has a form creation coordinator
            $formidableFormsFormCreationCoordinator = $this->formidableFormsCoordinator->getFormCreationCoordinator();
            if (
                $formidableFormsFormCreationCoordinator instanceof FormPluginFormCreationCoordinatorContract
                && method_exists(
                    $formidableFormsFormCreationCoordinator,
                    'setPageDataMapping'
                )
            ) {
                $formidableFormsFormCreationCoordinator->setPageDataMapping($pageDataMapping);
            }

            // Set page data mapping on Ninja Forms coordinator if it has a form creation coordinator
            $ninjaFormsFormCreationCoordinator = $this->ninjaFormsCoordinator->getFormCreationCoordinator();
            if (
                $ninjaFormsFormCreationCoordinator instanceof FormPluginFormCreationCoordinatorContract
                && method_exists(
                    $ninjaFormsFormCreationCoordinator,
                    'setPageDataMapping'
                )
            ) {
                $ninjaFormsFormCreationCoordinator->setPageDataMapping($pageDataMapping);
            }
        }

        // ---
        // Collect all Gravity Forms and Formidable Forms IDs from processors
        // ---

        // Gather all referenced Gravity Forms form IDs from both shortcode and block processors
        $gravityFormIds = array_unique([
            ...GravityFormsShortcodeProcessor::getFormIds(),
            ...GravityFormsBlockProcessor::getFormIds(),
        ]);

        // Gather all referenced Formidable Forms form IDs from both shortcode and block processors
        $formidableFormIds = array_unique([
            ...FormidableFormsShortcodeProcessor::getFormIds(),
            ...FormidableFormsBlockProcessor::getFormIds(),
        ]);

        // Gather all referenced Ninja Forms form IDs from both shortcode and block processors
        $ninjaFormIds = array_unique([
            ...NinjaFormsShortcodeProcessor::getFormIds(),
            ...NinjaFormsBlockProcessor::getFormIds(),
        ]);

        // ---
        // Filter form IDs to only include those that exist in the provided form data
        // ---

        // Get Gravity Forms data
        $gravityFormsData = $this->gravityFormsCoordinator->getGravityFormsData();

        // Check if Gravity Forms data exists and is not empty before filtering
        if (
            $gravityFormsData !== null
            && $gravityFormsData !== []
        ) {
            // Extract available form IDs from the data
            $availableGravityFormIds = $this->extractAvailableFormIds(
                $gravityFormsData
            );

            // Filter to only form IDs that exist in the data
            $gravityFormIds = \array_intersect(
                $gravityFormIds,
                $availableGravityFormIds
            );
        }

        // Get Formidable Forms data
        $formidableFormsData = $this->formidableFormsCoordinator->getFormidableFormsData();

        // Check if Formidable Forms data exists and is not empty before filtering
        if (
            $formidableFormsData !== null
            && $formidableFormsData !== []
        ) {
            // Extract available form IDs from the data
            $availableFormidableFormIds = $this->extractAvailableFormIds(
                $formidableFormsData
            );

            // Filter to only form IDs that exist in the data
            $formidableFormIds = \array_intersect(
                $formidableFormIds,
                $availableFormidableFormIds
            );
        }

        // Get Ninja Forms data
        $ninjaFormsData = $this->ninjaFormsCoordinator->getNinjaFormsData();

        // Check if Ninja Forms data exists and is not empty before filtering
        if (
            $ninjaFormsData !== null
            && $ninjaFormsData !== []
        ) {
            // Extract available form IDs from the data
            $availableNinjaFormIds = $this->extractAvailableFormIds(
                $ninjaFormsData
            );

            // Filter to only form IDs that exist in the data
            $ninjaFormIds = \array_intersect(
                $ninjaFormIds,
                $availableNinjaFormIds
            );
        }

        // ---
        // Report references the supplied export cannot satisfy, BEFORE the early return.
        // The availability filter above intersects them away silently, so a page that
        // references a form absent from the export imports with a placeholder note and no
        // form — and nothing said so.
        // ---
        $this->reportUnsatisfiedReferences(
            $output,
            'Gravity Forms',
            $gravityFormsData
        );
        $this->reportUnsatisfiedReferences(
            $output,
            'Formidable Forms',
            $formidableFormsData
        );
        $this->reportUnsatisfiedReferences(
            $output,
            'Ninja Forms',
            $ninjaFormsData
        );

        // Check if any forms need to be imported
        if (
            $gravityFormIds === []
            && $formidableFormIds === []
            && $ninjaFormIds === []
        ) {
            // No forms to import, return early
            return;
        }

        // Inform user that form import phase is starting
        $output->writeln('Processing form imports...');

        // Import Gravity Forms
        // Create Statamic forms for each referenced Gravity Forms form ID
        if ($gravityFormIds !== []) {
            foreach ($gravityFormIds as $formId) {
                // Log that we're processing this Gravity Forms form
                $output->writeln("Processing Gravity Forms form ID '{$formId}'...");

                // Attempt to create the Statamic form for this Gravity Forms form ID
                $result = $this->gravityFormsCoordinator->createGravityFormsFormIfNeeded(
                    (int) $formId,
                    $siteName,
                    $siteHandle,
                    $assetImporter
                );

                // If creation was successful, log the successful import and cache the handle
                if ($result instanceof Form) {
                    $resultHandle = $this->resolveFormHandle(
                        $result
                    );
                    if ($resultHandle === null) {
                        continue;
                    }

                    // Cache the form handle for static access using the trait method
                    static::setFormHandle(
                        $formId,
                        $resultHandle,
                        'Gravity Forms'
                    );

                    // Inform user that form was created
                    $output->writeln(
                        "  - <info>Created form '{$resultHandle}' in Statamic</info>"
                    );
                }
            }

            // Clear the static cache of Gravity Forms form IDs after processing
            GravityFormsShortcodeProcessor::clearFormIds();
            GravityFormsBlockProcessor::clearFormIds();
        }

        // Import Formidable Forms
        // Create Statamic forms for each referenced Formidable Forms form ID
        if ($formidableFormIds !== []) {
            foreach ($formidableFormIds as $formId) {
                // Log that we're processing this Formidable Forms form
                $output->writeln("Processing Formidable Forms form ID '{$formId}'...");

                // Attempt to create the Statamic form for this Formidable Forms form ID
                $result = $this->formidableFormsCoordinator->createFormidableFormsFormIfNeeded(
                    (int) $formId,
                    $siteName,
                    $siteHandle,
                    $assetImporter
                );

                // If creation was successful, log the successful import and cache the handle
                if ($result instanceof Form) {
                    $resultHandle = $this->resolveFormHandle(
                        $result
                    );
                    if ($resultHandle === null) {
                        continue;
                    }

                    // Cache the form handle for static access using the trait method
                    static::setFormHandle(
                        $formId,
                        $resultHandle,
                        'Formidable Forms'
                    );

                    // Inform user that form was created
                    $output->writeln(
                        "  - <info>Created form '{$resultHandle}' in Statamic</info>"
                    );
                }
            }

            // Clear the static cache of Formidable Forms form IDs after processing
            FormidableFormsShortcodeProcessor::clearFormIds();
            FormidableFormsBlockProcessor::clearFormIds();
        }

        // Import Ninja Forms
        // Create Statamic forms for each referenced Ninja Forms form ID
        if ($ninjaFormIds !== []) {
            foreach ($ninjaFormIds as $formId) {
                // Log that we're processing this Ninja Forms form
                $output->writeln("Processing Ninja Forms form ID '{$formId}'...");

                // Attempt to create the Statamic form for this Ninja Forms form ID
                $result = $this->ninjaFormsCoordinator->createNinjaFormsFormIfNeeded(
                    (int) $formId,
                    $siteName,
                    $siteHandle,
                    $assetImporter
                );

                // If creation was successful, log the successful import and cache the handle
                if ($result instanceof Form) {
                    $resultHandle = $this->resolveFormHandle(
                        $result
                    );
                    if ($resultHandle === null) {
                        continue;
                    }

                    // Cache the form handle for static access using the trait method
                    static::setFormHandle(
                        $formId,
                        $resultHandle,
                        'Ninja Forms'
                    );

                    // Inform user that form was created
                    $output->writeln(
                        "  - <info>Created form '{$resultHandle}' in Statamic</info>"
                    );
                }
            }

            // Clear the static cache of Ninja Forms form IDs after processing
            NinjaFormsShortcodeProcessor::clearFormIds();
            NinjaFormsBlockProcessor::clearFormIds();
        }
    }

    /**
     * Report content references the supplied export cannot satisfy.
     *
     * Reconciles what the content references — captured during detection, with the pages
     * that reference it — against the IDs the operator's export actually contains. An
     * unmatched reference is reported with the page carrying it, rather than being
     * intersected away in silence.
     *
     * Reconciliation is against the **whole supplied set** for the plugin, not per file.
     * On a multisite a block can legitimately reference a form living in another site's
     * export, and reporting those would be a false alarm — the failure mode that makes a
     * warning worth ignoring.
     *
     * Nothing is reported when no export was supplied at all: the operator declined the
     * prompt, already knows no forms are coming, and does not need one line per reference.
     *
     * @param  OutputInterface  $output  Console output interface
     * @param  string  $pluginName  The plugin display name
     * @param  array<int|string, mixed>|null  $formsData  The supplied export data
     */
    private function reportUnsatisfiedReferences(
        OutputInterface $output,
        string $pluginName,
        ?array $formsData
    ): void {
        $referenced = $this->referencedFormPages[$pluginName] ?? [];

        // No export supplied: the operator declined the prompt and knows why.
        if ($formsData === null || $formsData === []) {
            return;
        }

        $available = $this->extractAvailableFormIds($formsData);

        $unsatisfied = [];

        foreach ($referenced as $formId => $pageSlugs) {
            // Compare as strings: PHP coerced any numeric-string key to an int on the way
            // in, while extractAvailableFormIds() yields strings. A strict comparison
            // between the two never matches, and would report every form as missing.
            if (! \in_array((string) $formId, $available, true)) {
                $unsatisfied[$formId] = $pageSlugs;
            }
        }

        // Every reference is satisfied, which is the normal case and stays quiet.
        if ($unsatisfied === []) {
            return;
        }

        $output->writeln(
            \sprintf(
                '  <comment>%d referenced %s form(s) are not in the supplied export:</comment>',
                \count($unsatisfied),
                $pluginName
            )
        );

        foreach ($unsatisfied as $formId => $pageSlugs) {
            $pages = $pageSlugs === []
                ? 'no page recorded'
                : 'page: '.\implode(', ', $pageSlugs);

            $output->writeln("      form {$formId}  ({$pages})");
        }

        $output->writeln(
            '    <comment>These pages import with a placeholder note and no form.</comment>'
        );
    }

    /**
     * Updates all form handles in entries in a single pass.
     *
     * Iterates through entries once and updates all form handles (both Gravity
     * Forms and Formidable Forms) in a single pass, which is more efficient
     * than iterating once per form ID.
     *
     * @param  string  $siteHandle  The Statamic site handle
     * @param  OutputInterface  $output  Console output interface
     * @return array<int|string, mixed> Map of entry IDs to updated data arrays for entries that were modified
     */
    public function updateAllFormHandlesInEntries(
        string $siteHandle,
        OutputInterface $output
    ): array {
        // ---
        // Check if any forms were created during import
        // ---

        // Check if any Gravity Forms were created
        $hasGravityForms = static::$createdGravityFormsHandles !== [];

        // Check if any Formidable Forms were created
        $hasFormidableForms = static::$createdFormidableFormsHandles !== [];

        // Check if any Ninja Forms were created
        $hasNinjaForms = static::$createdNinjaFormsHandles !== [];

        // ---
        // Proceed with updates only if forms were created
        // ---

        // Check if no forms were created
        if (
            ! $hasGravityForms
            && ! $hasFormidableForms
            && ! $hasNinjaForms
        ) {
            // Return empty array if no forms to update
            return [];
        }

        // Build map of import_wordpress_notes to form handles for efficient lookup
        $formHandleMap = [];

        // ---
        // Add Gravity Forms to map
        // ---

        // Loop through all created Gravity Forms handles
        foreach (static::$createdGravityFormsHandles as $formId => $formHandle) {
            // Add Gravity Forms entry to form handle map
            $formHandleMap["Gravity Forms form ID $formId"] = $formHandle;
        }

        // ---
        // Add Formidable Forms to map
        // ---

        // Loop through all created Formidable Forms handles
        foreach (static::$createdFormidableFormsHandles as $formId => $formHandle) {
            // Add Formidable Forms entry to form handle map
            $formHandleMap["Formidable Forms form ID $formId"] = $formHandle;
        }

        // ---
        // Add Ninja Forms to map
        // ---

        // Loop through all created Ninja Forms handles
        foreach (static::$createdNinjaFormsHandles as $formId => $formHandle) {
            // Add Ninja Forms entry to form handle map
            $formHandleMap["Ninja Forms form ID $formId"] = $formHandle;
        }

        // ---
        // Get all collections that might contain entries with form Bard sets
        // ---

        // Define collections that may contain form Bard sets
        $collections = ['pages', 'posts', 'people', 'tables'];

        // Initialize array to store updated entries
        $updatedEntries = [];

        // Iterate through each collection once
        foreach ($collections as $collectionHandle) {
            // ---
            // Clear both paths cache and item cache for this collection before
            // querying entries to ensure entries are loaded fresh from disk with
            // correct file paths (including date prefixes for dated collections).
            // This prevents entries with stale initialPath values from being used,
            // which could cause duplicate files to be created when saving.
            // ---
            $this->clearCollectionCache(
                $collectionHandle,
                true
            );

            // Query entries in this collection for the site. Filter out null
            // entries that may occur when paths cache is cleared and some entries
            // can't be found immediately.
            $entryQuery = EntryQueryHelper::query();
            $collectionQuery = $entryQuery->where(
                'collection',
                $collectionHandle
            );
            // Statamic's where() is untyped, so PHPStan needs this guard, but it always returns
            // a query builder, so neither half can be false and joining them with `and` changes nothing.
            // @pest-mutate-ignore: BooleanOrToBooleanAnd
            if (
                ! \is_object($collectionQuery)
                || ! method_exists(
                    $collectionQuery,
                    'where'
                )
            ) {
                continue;
            }

            $siteQuery = $collectionQuery->where(
                'site',
                $siteHandle
            );
            // The same guard on the second where(), for the same reason.
            // @pest-mutate-ignore: BooleanOrToBooleanAnd
            if (
                ! \is_object($siteQuery)
                || ! method_exists(
                    $siteQuery,
                    'get'
                )
            ) {
                continue;
            }

            $entries = $siteQuery->get();
            if (! \is_iterable($entries)) {
                continue;
            }

            // ---
            // Process each entry once, updating all form handles
            // ---

            // Loop through all entries in this collection
            foreach ($entries as $entry) {
                // An entry query yields only entries; the check narrows the untyped get() for PHPStan.
                // @pest-mutate-ignore: InstanceOfToTrue
                if (! $entry instanceof StatamicEntry) {
                    continue;
                }

                // Get entry data as array
                $entryData = $entry->data();
                // An entry's data() is always a collection, so this guard, like the query guards,
                // cannot see either half false.
                // @pest-mutate-ignore: BooleanOrToBooleanAnd
                if (
                    ! \is_object($entryData)
                    || ! method_exists(
                        $entryData,
                        'toArray'
                    )
                ) {
                    continue;
                }

                $data = $entryData->toArray();
                if (! \is_array($data)) {
                    continue;
                }

                // Initialize flag to track if entry was updated
                $updated = false;

                // ---
                // Update all form handles in content field
                // ---

                // Initialize array to collect form updates for this entry
                $entryFormUpdates = [];

                // Bard sets in this entry naming a form the API did not serve.
                $entryUnresolvedNotes = [];

                // Check if content field exists and is an array
                if (isset($data['content']) && \is_array($data['content'])) {
                    // Update all form handles in content field and collect updates
                    [$updatedContent, $contentFormUpdates, $contentUnresolved] = $this->updateAllFormHandlesInArray(
                        $data['content'],
                        $formHandleMap
                    );

                    // Carry unresolved form references up to the entry, which is the only frame
                    // that knows which entry they belong to (wordpress-importer-archive#1162).
                    $entryUnresolvedNotes = $contentUnresolved;

                    // Start the entry's form updates with the content's
                    $entryFormUpdates = $contentFormUpdates;

                    // Check if content was updated
                    if ($updatedContent !== $data['content']) {
                        // Store updated content
                        $data['content'] = $updatedContent;

                        // Mark entry as updated
                        $updated = true;
                    }
                }

                // ---
                // Update all form handles in page_builder field
                // ---

                // Check if page_builder field exists and is an array
                if (isset($data['page_builder']) && \is_array($data['page_builder'])) {
                    // Update all form handles in page_builder field and collect updates
                    [$updatedPageBuilder, $pageBuilderFormUpdates, $pageBuilderUnresolved] = $this->updateAllFormHandlesInArray(
                        $data['page_builder'],
                        $formHandleMap
                    );

                    // Merge page_builder form updates into entry form updates
                    $entryFormUpdates = [
                        ...$entryFormUpdates,
                        ...$pageBuilderFormUpdates,
                    ];

                    // Same for unresolved references found in the page builder.
                    $entryUnresolvedNotes = [
                        ...$entryUnresolvedNotes,
                        ...$pageBuilderUnresolved,
                    ];

                    // Check if page_builder was updated
                    if ($updatedPageBuilder !== $data['page_builder']) {
                        // Store updated page_builder
                        $data['page_builder'] = $updatedPageBuilder;

                        // Mark entry as updated
                        $updated = true;
                    }
                }

                // ---
                // Report Bard sets naming a form that was never served
                // ---

                // **Reported here rather than where it is found (wordpress-importer-archive#1162).** The lookup frame knows
                // the note and nothing else; this one knows the entry it belongs to, which is what
                // makes the warning actionable — an editor needs the page to go and fix the embed,
                // not the id of a form that no longer exists.
                if ($entryUnresolvedNotes !== []) {
                    $this->warnUnresolvedFormReferences(
                        $entry,
                        $entryUnresolvedNotes
                    );
                }

                // ---
                // Store updated entry data if modified
                // ---

                // Check if entry was updated
                if ($updated) {
                    $entryId = $this->normalizeIdentifier(
                        $entry->id()
                    );
                    if ($entryId === null) {
                        continue;
                    }

                    // Store entry data in updated entries map
                    $updatedEntries[$entryId] = [
                        'entry' => $entry,
                        'data' => $data,
                        'collection' => $collectionHandle,
                        'form_updates' => $entryFormUpdates,
                    ];
                }
            }
        }

        // Return map of updated entries
        return $updatedEntries;
    }

    /**
     * Record a Bard form set naming a form the migration API never served.
     *
     * **The crash this replaces was the whole of wordpress-importer-archive#1162**, and the cost was not the one post: the
     * abort landed in the form-update pass, which runs after every entry is written and before the
     * post-import work, so a 366-entry site was left with no deferred link resolution, no tree
     * update, no Bard sanitization, no search index and no warning summary — and re-running
     * reproduced it exactly, because the dead reference lives in the source content.
     *
     * Naming the entry is what makes this actionable. The form id is a dangling pointer; the entry
     * is the page an editor opens to fix the embed.
     *
     * @param  array<int, string>  $notes  The unresolved `import_wordpress_notes` values
     */
    private function warnUnresolvedFormReferences(
        StatamicEntry $entry,
        array $notes
    ): void {
        // Deduplicate: one entry can carry the same dead embed in several sets, and reporting it
        // once per set tells an editor nothing extra.
        $unique = \array_filter(
            \array_unique($notes),
            static fn (string $note): bool => $note !== ''
        );

        $entryId = $entry->id();

        ImportWarningManager::addWarning(
            'content',
            'unresolved_form_references',
            [
                'entry_id' => \is_string($entryId) ? $entryId : '(unknown)',
                'entry_title' => $entry->get('title'),
                'source_url' => $entry->get('original_wordpress_url'),
                'form_notes' => \implode(', ', $unique),
                'effect' => 'The Bard set was left exactly as authored, so the page renders without '
                    .'the form rather than failing. The import now completes.',
                'reason' => 'The migration API served no definition for this form, which is what a '
                    .'form deleted in WordPress looks like. A re-run will not resolve it: the '
                    .'reference is in the source content, so the form must be restored in '
                    .'WordPress or the embed removed from the post.',
            ]
        );
    }

    /**
     * Recursively updates all form handles in an array in a single pass.
     *
     * @param  array<int|string, mixed>  $array  The array to search and update
     * @param  array<int|string, mixed>  $formHandleMap  Map of import_wordpress_notes to form handles
     * @return array{
     *   0: array<int|string, mixed>,
     *   1: array<int, array{form_note: string, form_handle: string}>,
     *   2: array<int, string>
     * } Tuple containing [updated array, array of form updates, unresolved form notes]
     */
    private function updateAllFormHandlesInArray(
        array $array,
        array $formHandleMap
    ): array {
        // Initialize result array
        $result = [];

        // Initialize array to collect form updates
        $formUpdates = [];

        // Bard sets naming a form the map cannot resolve. Collected rather than thrown on (wordpress-importer-archive#1162).
        $unresolvedNotes = [];

        // Loop through all array elements
        foreach ($array as $key => $value) {
            // Check if value is an array
            if (\is_array($value)) {
                $bardFormValues = $this->extractBardFormValues(
                    $value
                );

                // Check if this is a Bard set with a form type and import_wordpress_notes
                if ($bardFormValues !== null) {
                    // ---
                    // Update the Bard set to use form handle instead of import_wordpress_notes
                    // ---

                    // Get current values from Bard set
                    $updatedValues = $bardFormValues['values'];

                    // Get form handle from map
                    //
                    // **`?? null` is what makes the guard below reachable (wordpress-importer-archive#1162).** The lookup was
                    // unguarded, so a Bard set naming a form the API does not serve raised
                    // `Undefined array key` and aborted the whole import — after every entry had
                    // been created and before any post-import pass ran, losing deferred link
                    // resolution, the tree update, Bard sanitization, the search index and the
                    // warning summary itself. One form deleted in WordPress was enough to
                    // half-process a 366-entry site, identically on every re-run.
                    $formHandle = $formHandleMap[$bardFormValues['note']] ?? null;
                    if (! \is_string($formHandle)) {
                        // Leave the set exactly as authored: the note is the only record of which
                        // form was meant, and rewriting it would destroy the evidence an editor
                        // needs to fix the embed.
                        $result[$key] = $value;

                        // Report it rather than passing over it silently — a dead embed that
                        // no longer crashes is still a dead embed.
                        $unresolvedNotes[] = $bardFormValues['note'];

                        continue;
                    }

                    // Get form note for logging
                    $formNote = $bardFormValues['note'];

                    // Collect form update information
                    $formUpdates[] = [
                        'form_note' => $formNote,
                        'form_handle' => $formHandle,
                    ];

                    // Remove import_wordpress_notes from values
                    unset($updatedValues['import_wordpress_notes']);

                    // Add form handle to values
                    $updatedValues['form'] = $formHandle;

                    // Create updated Bard set structure
                    $result[$key] = [
                        'type' => 'set',
                        'attrs' => [
                            'id' => $bardFormValues['id'],
                            'values' => $updatedValues,
                        ],
                    ];
                } else { // Otherwise, recursively process nested arrays
                    // ---
                    // Recursively update nested arrays
                    // ---

                    // Recursively update nested array
                    [$nestedResult, $nestedFormUpdates, $nestedUnresolved] = $this->updateAllFormHandlesInArray(
                        $value,
                        $formHandleMap
                    );

                    // Merge nested form updates into main form updates array
                    $formUpdates = [
                        ...$formUpdates,
                        ...$nestedFormUpdates,
                    ];

                    // Nested unresolved notes travel with them, or a dead embed inside a
                    // replicator set would be reported at no level.
                    $unresolvedNotes = [
                        ...$unresolvedNotes,
                        ...$nestedUnresolved,
                    ];

                    // Store the nested result, which is the original array itself when nothing in it resolved
                    $result[$key] = $nestedResult;
                }
            } else { // Otherwise, store non-array value unchanged
                // Store non-array value unchanged
                $result[$key] = $value;
            }
        }

        // An array with no resolved set comes back as the original array itself, so the caller's
        // `!==` check is a reference comparison rather than a deep one, and a value that never
        // equals itself (NAN) cannot mark an untouched entry as rewritten. Its unresolved notes
        // still travel up: an entry whose only form set is unresolved must still be reported.
        return $formUpdates === []
            ? [$array, [], $unresolvedNotes]
            : [$result, $formUpdates, $unresolvedNotes];
    }

    /**
     * Extracts string form IDs from plugin form datasets.
     *
     * @param  array<int|string, mixed>  $formsData  Source plugin form data
     * @return array<int, string> List of available form IDs
     */
    private function extractAvailableFormIds(
        array $formsData
    ): array {
        $availableFormIds = [];

        foreach ($formsData as $form) {
            if (! \is_array($form)) {
                continue;
            }

            $formId = $this->normalizeIdentifier(
                $form['id'] ?? null
            );
            if ($formId !== null) {
                $availableFormIds[] = $formId;
            }
        }

        return $availableFormIds;
    }

    /**
     * Resolves a created Statamic form handle from plugin coordinator results.
     *
     * Every caller checks for a Form first, so this only narrows the handle to a string.
     *
     * @param  Form  $result  The created form
     * @return ?string The created form handle, or null when it has none
     */
    private function resolveFormHandle(
        Form $result
    ): ?string {
        return $this->normalizeIdentifier(
            $result->handle()
        );
    }

    /**
     * Extracts Bard form set values when the structure matches expected fields.
     *
     * @param  array<int|string, mixed>  $value  Candidate Bard set structure
     * @return array{values: array<int|string, mixed>, note: string, id: mixed}|null
     *                                                                               Parsed values when this is a form set, otherwise null
     */
    private function extractBardFormValues(
        array $value
    ): ?array {
        if (
            ($value['type'] ?? null) !== 'set'
            || ! isset($value['attrs'])
            || ! \is_array($value['attrs'])
            || ! isset($value['attrs']['values'])
            || ! \is_array($value['attrs']['values'])
        ) {
            return null;
        }

        $values = $value['attrs']['values'];
        if (
            ($values['type'] ?? null) !== 'form'
            || ! isset($values['import_wordpress_notes'])
            || ! \is_string($values['import_wordpress_notes'])
        ) {
            return null;
        }

        return [
            'values' => $values,
            'note' => $values['import_wordpress_notes'],
            'id' => $value['attrs']['id'] ?? null,
        ];
    }

    /**
     * Normalizes mixed identifier values to strings.
     *
     * @param  mixed  $value  Source identifier value
     * @return ?string Normalized identifier, or null when invalid
     */
    private function normalizeIdentifier(
        mixed $value
    ): ?string {
        if (\is_string($value)) {
            return $value;
        }

        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }

        return null;
    }
}
