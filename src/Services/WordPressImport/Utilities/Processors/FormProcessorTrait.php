<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors;

use Illuminate\Support\Facades\Log;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;

/**
 * Base shared functionality for form processors.
 *
 * This trait provides common methods and properties used by both form block and
 * form shortcode processor traits to eliminate code duplication.
 */
trait FormProcessorTrait
{
    /**
     * Cache of extracted form IDs for import triggering and linking.
     *
     * @var string[]
     */
    protected static $formIds = [];

    /**
     * Track form ID for import and linking.
     *
     * Adds the form ID to the static cache for later import processing.
     * Validates that the form ID is not null and not empty.
     *
     * @param  string|int|null  $formId  The form ID to track
     */
    protected function trackFormId(
        string|int|null $formId
    ): void {
        // Add the form ID to the static cache for later import processing
        if ($formId !== null && $formId !== '') {
            // Add the form ID to the static cache for later import processing
            static::$formIds[] = (string) $formId;
        }
    }

    /**
     * Build the values array for the Bard set.
     *
     * Creates a standardized values array for form Bard sets with proper handling
     * of null or empty form IDs. When a form handle is provided, uses the 'form'
     * field instead of 'import_wordpress_notes'. Allows plugin-specific handling
     * of title and description attributes via a callback.
     *
     * @param  string|int|null  $formId  The form ID
     * @param  string  $pluginName  The plugin name for the import notes
     * @param  ?string  $formHandle  The Statamic form handle (optional)
     * @param  array<int|string, mixed>  $attributes  The block attributes containing title/description
     * @param  ?callable  $titleDescriptionHandler  Optional callback for plugin-specific handling
     * @return array<int|string, mixed> The values array for the Bard set
     */
    protected function buildFormBardSetValues(
        string|int|null $formId,
        string $pluginName,
        ?string $formHandle = null,
        array $attributes = [],
        ?callable $titleDescriptionHandler = null
    ): array {
        // Initialize the values array with the Bard set type
        $values = [
            'type' => 'form', // Bard set type for forms
        ];

        // Determine whether to use form handle or import notes
        if ($formHandle !== null) {
            // Use the actual form handle when available
            $values['form'] = $formHandle;
        } else { // Otherwise
            // Fall back to import_wordpress_notes during content processing
            $values['import_wordpress_notes'] = ($formId !== null && $formId !== '')
                ? "{$pluginName} form ID $formId"
                : "{$pluginName} form ID not specified";
        }

        // Check if a plugin-specific handler was provided for title/description processing
        if ($titleDescriptionHandler !== null) {
            // Call the plugin-specific handler to process title and description attributes
            $titleDescriptionHandler(
                $values,
                $attributes
            );
        }

        // Return the completed values array
        return $values;
    }

    /**
     * Log form processing warning.
     *
     * @param  string  $processorName  The processor class name for logging
     * @param  string  $pluginName  The plugin name for logging
     * @param  string  $site  The site handle
     * @param  array<int|string, mixed>  $attributes  The attributes array
     * @param  ?string  $pageSlug  The page slug (optional)
     */
    protected function logFormProcessingWarning(
        string $processorName,
        string $pluginName,
        string $site,
        array $attributes,
        ?string $pageSlug = null
    ): void {
        // Enhance log context with entry information
        $logData = LogContextHelper::enhanceWithEntryContext(
            [
                'site' => $site,
                'pageSlug' => $pageSlug ?? 'none',
                'form_id' => 'none',
                'attributes' => $attributes,
            ]
        );

        // Log warning about invalid or missing form ID
        Log::warning(
            LogContextHelper::formatMessageWithEntry(
                "{$processorName}::process: Invalid or missing formId in {$pluginName}",
                $logData['page_url']
            ),
            $logData['context']
        );
    }

    /**
     * Retrieves cached form IDs.
     *
     * @return string[] Array of form IDs
     */
    public static function getFormIds(): array
    {
        return static::$formIds;
    }

    /**
     * Clears cached form IDs.
     */
    public static function clearFormIds(): void
    {
        static::$formIds = [];
    }

    /**
     * Check if a form handle exists for the given form ID.
     *
     * @param  string|int|null  $formId  The form ID to check
     * @param  string  $pluginName  The plugin name ('Gravity Forms', 'Formidable Forms' or 'Ninja Forms')
     * @return bool True if a form handle exists, false otherwise
     */
    protected function hasFormHandle(
        string|int|null $formId,
        string $pluginName
    ): bool {
        $formHandle = $this->getFormHandle(
            $formId,
            $pluginName
        );

        return $formHandle !== null;
    }

    /**
     * Get the form handle for the given form ID.
     *
     * @param  string|int|null  $formId  The form ID to look up
     * @param  string  $pluginName  The plugin name ('Gravity Forms', 'Formidable Forms' or 'Ninja Forms')
     * @return ?string The form handle or null if not found
     */
    protected function getFormHandle(
        string|int|null $formId,
        string $pluginName
    ): ?string {
        if ($formId === null || $formId === '') {
            return null;
        }

        // PHP normalizes an array key anyway, so the cast only states the key type
        // @pest-mutate-ignore: RemoveStringCast
        $formIdString = (string) $formId;
        // Check the appropriate static cache based on plugin name
        if ($pluginName === 'Gravity Forms') {
            return static::$createdGravityFormsHandles[$formIdString] ?? null;
        }

        if ($pluginName === 'Formidable Forms') {
            return static::$createdFormidableFormsHandles[$formIdString] ?? null;
        }

        // Check the appropriate static cache based on plugin name
        if ($pluginName === 'Ninja Forms') {
            return static::$createdNinjaFormsHandles[$formIdString] ?? null;
        }

        return null;
    }

    /**
     * Static cache of created Gravity Forms form handles.
     *
     * @var array<string, string>
     */
    protected static $createdGravityFormsHandles = [];

    /**
     * Static cache of created Formidable Forms form handles.
     *
     * @var array<string, string>
     */
    protected static $createdFormidableFormsHandles = [];

    /**
     * Static cache of created Ninja Forms form handles.
     *
     * @var array<string, string>
     */
    protected static $createdNinjaFormsHandles = [];

    /**
     * Set the created form handle for a form ID.
     *
     * @param  string|int|null  $formId  The form ID
     * @param  string  $formHandle  The form handle
     * @param  string  $pluginName  The plugin name ('Gravity Forms', 'Formidable Forms' or 'Ninja Forms')
     */
    protected static function setFormHandle(
        string|int|null $formId,
        string $formHandle,
        string $pluginName
    ): void {
        // PHP normalizes an array key anyway, so the cast only states the key type
        // @pest-mutate-ignore: RemoveStringCast
        $formIdString = (string) $formId;

        if ($pluginName === 'Gravity Forms') {
            static::$createdGravityFormsHandles[$formIdString] = $formHandle;
        } elseif ($pluginName === 'Formidable Forms') {
            static::$createdFormidableFormsHandles[$formIdString] = $formHandle;
        } elseif ($pluginName === 'Ninja Forms') {
            static::$createdNinjaFormsHandles[$formIdString] = $formHandle;
        }
    }
}
