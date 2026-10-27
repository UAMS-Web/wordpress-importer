<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Statamic\Forms\Form;
use Symfony\Component\Console\Output\NullOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\HtmlTagStripper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\TextProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\WpAutoP;

/**
 * Shared functionality for form configuration managers.
 *
 * This trait provides common methods used by both Gravity Forms and Formidable
 * Forms
 * form configuration managers to eliminate code duplication.
 */
trait FormConfigurationManagerTrait
{
    /**
     * Page data mapping from WordPress post IDs to Statamic entry IDs.
     *
     * @var array<int|string, mixed>
     */
    protected $pageDataMapping = [];

    /**
     * Set the page data mapping for WordPress post ID to Statamic entry ID
     * translation.
     *
     * @param  array<int|string, mixed>  $pageDataMapping  Array mapping WordPress post IDs to Statamic entry data
     */
    public function setPageDataMapping(
        array $pageDataMapping
    ): void {
        $this->pageDataMapping = $pageDataMapping;
    }

    /**
     * Get the page data mapping.
     *
     * @return array<int|string, mixed> The page data mapping array
     */
    public function getPageDataMapping(): array
    {
        return $this->pageDataMapping;
    }

    /**
     * Process a mapping configuration to transform a value according to its settings.
     *
     * This method handles all the common value processing logic that is repeated
     * throughout the trait, including boolean conversion, integer conversion,
     * value translation, regex substitution, field reference translation,
     * HTML stripping, and Bard field processing via the 'as_bard' => true
     * configuration.
     *
     * @param  mixed  $value  The original value to process
     * @param  array<int|string, mixed>|string  $mappingConfig  The mapping
     *                                                          configuration array
     * @param  array<int|string, mixed>  $formData  The complete form data for context
     * @param  string  $siteHandle  The Statamic site handle
     * @param  ?AssetImporter  $assetImporter  The asset importer instance
     * @param  array<int|string, mixed>  $formFields  Array of form fields for field reference translation
     * @return mixed The processed value
     */
    private function processMappingConfiguration(
        $value,
        array|string $mappingConfig,
        array $formData,
        string $siteHandle,
        ?AssetImporter $assetImporter = null,
        array $formFields = []
    ) {
        $mappingConfig = \is_array($mappingConfig) ? $mappingConfig : [];

        // Check if this is a datetime combination field
        if ($this->hasDatetimeCombination($mappingConfig)) {
            // Process the datetime combination and return the combined value
            return $this->processDatetimeCombination(
                $mappingConfig,
                $formData
            );
        }

        // Process boolean conversion if configured
        if (isset($mappingConfig['as_boolean']) && $mappingConfig['as_boolean'] === true) {
            $value = $this->convertToBoolean($value);

            // Apply boolean inversion if configured
            if (isset($mappingConfig['invert_boolean']) && $mappingConfig['invert_boolean'] === true) {
                $value = ! $value;
            }
        }

        // Process integer conversion if configured
        if (isset($mappingConfig['as_integer']) && $mappingConfig['as_integer'] === true) {
            $value = $this->convertToInteger($value);
        }

        // Apply value translation if configured
        if (
            isset($mappingConfig['value_translation'])
            && \is_array($mappingConfig['value_translation'])
        ) {
            $value = $this->applyValueTranslation(
                $value,
                $mappingConfig['value_translation']
            );
        }

        // Apply value substitution regex if configured
        if (
            isset($mappingConfig['value_substitution_regex'])
            && \is_array($mappingConfig['value_substitution_regex'])
            && \is_string($value)
        ) {
            $value = $this->applyValueSubstitutionRegex(
                $value,
                $mappingConfig['value_substitution_regex']
            );
        }

        // Apply field reference translation if configured
        if (isset($mappingConfig['translate_field_references']) && $mappingConfig['translate_field_references'] === true && \is_string($value)) {
            $value = $this->translateFieldReferences(
                $value,
                $formFields
            );
        }

        // Strip HTML if configured
        if (isset($mappingConfig['strip_html']) && $mappingConfig['strip_html'] === true && \is_string($value)) {
            $value = HtmlTagStripper::strip($value);
        }

        // Process as Bard field if configured
        if (isset($mappingConfig['as_bard']) && $mappingConfig['as_bard'] === true && \is_string($value)) {
            if (\trim($value) === '') {
                $value = [];
            } elseif ($assetImporter instanceof AssetImporter) { // Otherwise
                // Apply WpAutoP formatting first to convert line breaks to paragraphs
                $wpAutoP = new WpAutoP;
                $value = $wpAutoP->process(
                    $value,
                    true
                );

                // Process merge tags after WpAutoP formatting
                $value = $this->processMergeTagsInString(
                    $value,
                    $formFields
                );

                $value = TextProcessor::processForBard(
                    $value, // The input HTML content
                    $siteHandle, // The Statamic site handle
                    $assetImporter, // Instance for importing files
                    new NullOutput, // Console output for logging
                    // Don't apply WpAutoP formatting again since we already did it. A second
                    // pass finds nothing left to change: the text above is already decoded and
                    // wrapped, so escaped markup and entities above U+00FF come out the same.
                    // @pest-mutate-ignore: FalseToTrue
                    false,
                    // Not inner-block content. Treating it as such would only strip WordPress
                    // block comments, which the Bard parser drops anyway.
                    // @pest-mutate-ignore: FalseToTrue
                    false,
                    [],
                    []
                );
            }
        }

        // Process page reference translation if configured
        if (isset($mappingConfig['translate_page_references']) && $mappingConfig['translate_page_references'] === true && (! in_array($value, [null, '', []], true))) {
            $value = $this->processPageIdMapping(
                $value,
                $formData,
                $siteHandle
            );
        }

        // Trim whitespace at beginning and end if configured
        if (isset($mappingConfig['trim']) && $mappingConfig['trim'] === true && \is_string($value)) {
            $value = \trim($value);
        }

        // Trim comma-separated values if configured
        if (isset($mappingConfig['trim_comma_separated_values']) && $mappingConfig['trim_comma_separated_values'] === true && \is_string($value)) {
            $value = $this->trimCommaSeparatedValues($value);
        }

        // Unescape forward slashes if configured
        if (isset($mappingConfig['unescape_slashes']) && $mappingConfig['unescape_slashes'] === true && \is_string($value)) {
            $value = \str_replace(
                '\/',
                '/',
                $value
            );
        }

        // Clean up variables to free up memory
        unset(
            $mappingConfig,
            $formData,
            $siteHandle,
            $assetImporter,
            $formFields
        );

        return $value;
    }

    /**
     * Map form configuration fields from WordPress form plugin data to Statamic form
     * settings.
     *
     * Maps fields like description, title, etc. from the WordPress form plugin
     * data to Statamic form configuration fields. Supports dot notation for
     * accessing nested object properties (e.g., 'button.text').
     *
     * Note: Email configuration fields (notifications) are handled separately
     * by the mapNotificationsToEmailConfigurations method.
     *
     * @param  Form  $form  The Statamic form to configure
     * @param  array<int|string, mixed>  $formData  The WordPress form plugin form data
     * @param  string  $siteHandle  The Statamic site handle
     * @param  ?AssetImporter  $assetImporter  The asset importer instance
     */
    private function mapFormConfigurationFields(
        Form $form,
        array $formData,
        string $siteHandle,
        $assetImporter = null
    ): void {
        // Read the form configuration mapping, if the class defines one. It is read through
        // reflection, as processEmailContentMappings() reads its table, so the trait stays
        // analyzable in a class that defines no table.
        $mappingConfigs = (new \ReflectionClass(static::class))->getConstant('FORM_CONFIGURATION_MAPPING');
        if (! \is_array($mappingConfigs)) {
            return;
        }

        // Get the current form data. A Statamic form's data is always a collection; the check
        // narrows the untyped accessor for PHPStan.
        $formDataStore = $form->data();
        // @pest-mutate-ignore: InstanceOfToTrue
        $currentFormData = $formDataStore instanceof Collection ? $formDataStore->all() : [];

        // Parse and prepare form data using plugin-specific logic
        $processedFormData = $this->parseFormData($formData);

        // Map each form configuration field according to the mapping
        foreach ($mappingConfigs as $pluginKey => $mappingConfig) {
            // Every usable mapping is a table or a plain attribute name
            if (! \is_array($mappingConfig) && ! \is_string($mappingConfig)) {
                continue;
            }

            // Handle complex mapping configurations
            // Check if this is a multiple mapping configuration first
            if ($this->hasMultipleMappings($mappingConfig)) {
                // Extract the value using dot notation if needed
                $value = $this->extractValueFromData(
                    $processedFormData,
                    $pluginKey
                );

                // ---
                // Check if this field should be processed even when empty
                // (e.g., has #empty translation)
                // ---
                $shouldProcessEmpty = isset($mappingConfig['value_translation']) &&
                    \is_array($mappingConfig['value_translation']) &&
                    \array_key_exists(
                        '#empty',
                        $mappingConfig['value_translation']
                    );

                // ---
                // Only include the field if the value exists and is not empty, OR if it should be
                // processed when empty
                // ---
                if (
                    (
                        $value !== null
                        && $value !== ''
                    )
                    || $shouldProcessEmpty
                ) {
                    // ---
                    // Process the value using the common configuration processing method
                    // ---

                    // Get form fields array for field reference translation
                    $formFields = isset($formData['fields']) && \is_array($formData['fields'])
                        ? $formData['fields']
                        : [];

                    // Process the value using the mapping configuration
                    $value = $this->processMappingConfiguration(
                        $value,
                        $mappingConfig,
                        $formData,
                        $siteHandle,
                        $assetImporter,
                        $formFields
                    );

                    // Process multiple mappings, which hasMultipleMappings() has just confirmed
                    $multipleResults = $this->processMultipleMappingsAndGetResults(
                        $value,
                        $mappingConfig,
                        $formData,
                        $siteHandle,
                        $assetImporter
                    );

                    // ---
                    // Add all results to the current form data
                    // ---

                    // Loop through each target attribute and processed value
                    foreach ($multipleResults as $targetAttribute => $processedValue) {
                        if (! \is_string($targetAttribute)) {
                            continue;
                        }

                        // Check if the processed value is not null
                        if ($processedValue !== null) {
                            // ---
                            // Handle nested fields (like schedule_form.start_datetime)
                            // ---

                            // Check if the target attribute contains a dot (nested field)
                            if (
                                str_contains(
                                    $targetAttribute,
                                    '.'
                                )
                            ) {
                                // Split the target attribute by dot to get parent and child keys
                                $parts = \explode(
                                    '.',
                                    $targetAttribute
                                );

                                // Extract the parent key (first part)
                                $parentKey = $parts[0];

                                // Extract the child key (second part)
                                $childKey = $parts[1];

                                // ---
                                // Initialize the parent array if it doesn't exist
                                // ---

                                // Check if the parent key doesn't exist in current form data
                                if (
                                    ! isset($currentFormData[$parentKey])
                                    || ! \is_array($currentFormData[$parentKey])
                                ) {
                                    // Initialize the parent array
                                    $currentFormData[$parentKey] = [];
                                }

                                // Assign the processed value to the nested field
                                $currentFormData[$parentKey][$childKey] = $processedValue;
                            } else { // Otherwise
                                // Assign the processed value to the top-level field
                                $currentFormData[$targetAttribute] = $processedValue;
                            }
                        }
                    }

                    // Skip the rest of the processing for this field since we've handled it with multiple mappings
                    continue;
                }

                // Skip the rest of the processing for this field since we've handled it with multiple mappings
                continue;
            }

            // Check if this is a conditional mapping with based_on_other_attribute_value
            $conditionalMapping = data_get($mappingConfig, 'statamic_attribute.based_on_other_attribute_value');
            if (\is_array($conditionalMapping)) {
                $statamicKey = $this->processConditionalAttributeMapping(
                    $conditionalMapping,
                    $processedFormData
                );

                if ($statamicKey === null) {
                    continue; // Skip this field if the condition isn't met
                }
            } elseif (isset($mappingConfig['statamic_attribute']) && \is_string($mappingConfig['statamic_attribute'])) {
                // Simple complex mapping with just statamic_attribute
                $statamicKey = $mappingConfig['statamic_attribute'];
            } else {
                // Skip complex mappings that need special handling
                continue;
            }

            // Extract the value using dot notation if needed
            $value = $this->extractValueFromData(
                $processedFormData,
                $pluginKey
            );

            // Check if this field should be processed even when empty (e.g., has #empty translation)
            $shouldProcessEmpty = isset($mappingConfig['value_translation']) &&
                \is_array($mappingConfig['value_translation']) &&
                \array_key_exists(
                    '#empty',
                    $mappingConfig['value_translation']
                );

            // Only include the field if the value exists and is not empty, OR if it should be processed when empty
            if (($value !== null && $value !== '') || $shouldProcessEmpty) {
                // Process the value using the common configuration processing method
                $formFields = isset($formData['fields']) && \is_array($formData['fields'])
                    ? $formData['fields']
                    : [];
                $value = $this->processMappingConfiguration(
                    $value,
                    $mappingConfig,
                    $formData,
                    $siteHandle,
                    $assetImporter,
                    $formFields
                );

                // Handle special field types
                $value = $this->processFieldValue(
                    $statamicKey,
                    $value,
                    $formData,
                    $siteHandle,
                    $assetImporter
                );

                // Process merge tags for string fields
                if (\is_string($value)) {
                    $formFields = isset($formData['fields']) && \is_array($formData['fields'])
                        ? $formData['fields']
                        : [];
                    $value = $this->processMergeTagsInString(
                        $value,
                        $formFields
                    );
                }

                // Handle nested fields (like schedule_form.start_datetime)
                if (
                    str_contains(
                        $statamicKey,
                        '.'
                    )
                ) {
                    $parts = \explode(
                        '.',
                        $statamicKey
                    );

                    $parentKey = $parts[0];
                    $childKey = $parts[1];

                    // Initialize the parent array if it doesn't exist
                    if (
                        ! isset($currentFormData[$parentKey])
                        || ! \is_array($currentFormData[$parentKey])
                    ) {
                        $currentFormData[$parentKey] = [];
                    }

                    // For nested fields, only set the value if it's not null
                    if ($value !== null) {
                        $currentFormData[$parentKey][$childKey] = $value;
                    }
                } else { // Otherwise
                    // For regular fields, only set the value if it's not null
                    if ($value !== null) {
                        $currentFormData[$statamicKey] = $value;

                        // Process statamic_other_field_value if configured
                        $this->processStatamicOtherFieldValue(
                            $mappingConfig,
                            $value,
                            $currentFormData
                        );
                    }
                }
            }
        }

        // Map plugin-specific fields using plugin-specific logic
        $this->mapPluginSpecificFields(
            $currentFormData,
            $formData,
            $siteHandle,
            $assetImporter
        );

        // Map confirmation fields using plugin-specific logic
        $this->mapConfirmationFields(
            $form,
            $formData,
            $siteHandle,
            $assetImporter
        );

        // Apply the mapped form configuration data to the form
        $form->merge($currentFormData);

        // Clean up variables to free up memory
        unset(
            $formData,
            $siteHandle,
            $assetImporter,
            $currentFormData,
            $pluginKey,
            $statamicKey,
            $value,
            $parentKey,
            $childKey,
            $parts
        );
    }

    /**
     * Map confirmation fields from WordPress form plugin data to Statamic form
     * settings.
     *
     * Each plugin class must implement this method to handle their specific
     * confirmation processing logic, including default and additional confirmations
     * with proper nested group structure.
     *
     * @param  Form  $form  The Statamic form to configure
     * @param  array<int|string, mixed>  $formData  The WordPress form plugin form data
     * @param  string  $siteHandle  The Statamic site handle
     * @param  ?AssetImporter  $assetImporter  The asset importer instance
     */
    abstract protected function mapConfirmationFields(
        Form $form,
        array $formData,
        string $siteHandle,
        $assetImporter = null
    ): void;

    /**
     * Parse and prepare form data for mapping (plugin-specific).
     *
     * Each plugin class must implement this method to handle their specific
     * data structure and parsing requirements (e.g., options JSON parsing).
     *
     * @param  array<int|string, mixed>  $formData  The raw form data from the plugin
     * @return array<int|string, mixed> The processed form data ready for mapping
     */
    abstract protected function parseFormData(
        array $formData
    ): array;

    /**
     * Map plugin-specific fields that aren't covered by standard mappings.
     *
     * Each plugin class must implement this method to handle their specific
     * field mappings, view-based mappings, and other plugin-specific logic.
     *
     * @param  array<int|string, mixed>  &$currentFormData  The current form data (passed by reference)
     * @param  array<int|string, mixed>  $formData  The complete form data for context
     * @param  string  $siteHandle  The Statamic site handle
     * @param  ?AssetImporter  $assetImporter  The asset importer instance
     */
    abstract protected function mapPluginSpecificFields(
        array &$currentFormData,
        array $formData,
        string $siteHandle,
        $assetImporter = null
    ): void;

    /**
     * Process a single confirmation view and return the mapped data.
     *
     * Each plugin class must implement this method to handle their specific
     * confirmation view processing logic, including view-level mappings,
     * content mappings, and field structure requirements.
     *
     * @param  array<int|string, mixed>  $processedViewData  The processed view data (including parsed content)
     * @param  array<int|string, mixed>  $formData  The complete form data for context
     * @param  string  $siteHandle  The Statamic site handle
     * @param  ?AssetImporter  $assetImporter  The asset importer instance
     * @param  bool  $isAdditional  Whether this is an additional confirmation (affects field structure)
     * @return array<int|string, mixed>|null The mapped confirmation data or null if no data was mapped
     */
    abstract protected function processSingleConfirmationView(
        array $processedViewData,
        array $formData,
        string $siteHandle,
        $assetImporter = null,
        bool $isAdditional = false
    ): ?array;

    /**
     * Process field values for special field types.
     *
     * @param  string  $statamicKey  The Statamic field handle
     * @param  mixed  $value  The original value
     * @param  array<int|string, mixed>  $formData  The complete form data for context
     * @param  string  $siteHandle  The Statamic site handle
     * @param  ?AssetImporter  $assetImporter  The asset importer instance
     * @return mixed The processed value
     */
    private function processFieldValue(
        string $statamicKey,
        $value,
        array $formData,
        string $siteHandle,
        $assetImporter = null
    ) {
        // Handle plugin-specific field types
        $pluginSpecificResult = $this->processPluginSpecificField(
            $statamicKey,
            $value,
            $formData,
            $siteHandle,
            $assetImporter
        );
        if ($pluginSpecificResult !== null) {
            return $pluginSpecificResult;
        }

        // Clean up variables to free up memory
        unset(
            $statamicKey,
            $formData,
            $siteHandle,
            $assetImporter
        );

        // Return the original value for fields that don't need special processing
        return $value;
    }

    /**
     * Get plugin-specific variable mappings for merge tag processing.
     *
     * Each plugin class must implement this method to provide their specific
     * WordPress to Statamic variable mappings.
     *
     * @return array<string, string> Array mapping WordPress variables to Statamic variables
     */
    abstract protected function getVariableMappings(): array;

    /**
     * Process plugin-specific field types that require special handling.
     *
     * Each plugin class must implement this method to handle their specific
     * field types that don't fit the standard processing patterns.
     *
     * @param  string  $statamicKey  The Statamic field handle
     * @param  mixed  $value  The original value
     * @param  array<int|string, mixed>  $formData  The complete form data for context
     * @param  string  $siteHandle  The Statamic site handle
     * @param  ?AssetImporter  $assetImporter  The asset importer instance
     * @return ?mixed The processed value or null if no special processing was needed
     */
    abstract protected function processPluginSpecificField(
        string $statamicKey,
        $value,
        array $formData,
        string $siteHandle,
        $assetImporter = null
    ): mixed;

    /**
     * Process complex format mappings that require regex patterns.
     *
     * Each plugin class must implement this method to handle their specific
     * complex format mappings (e.g., GravityForms today, quiz, and survey mappings).
     * Plugins that don't have complex format mappings should return the value
     * unchanged.
     *
     * @param  string  $value  The value to process
     * @return string The processed value
     */
    abstract protected function processComplexFormatMappings(
        string $value
    ): string;

    /**
     * Check if the form has notifications or views (plugin-specific).
     *
     * Each plugin class must implement this method to determine whether the form
     * has notifications or views that need to be processed.
     *
     * @param  array<int|string, mixed>  $formData  The WordPress form plugin form data
     * @return bool True if the form has notifications/views, false otherwise
     */
    abstract protected function hasNotifications(
        array $formData
    ): bool;

    /**
     * Map notifications or views to Statamic email configurations (plugin-specific).
     *
     * Each plugin class must implement this method to handle their specific
     * notification or view structure and convert them to Statamic email
     * configurations.
     *
     * @param  array<int|string, mixed>  $formData  The WordPress form plugin form data
     * @param  string  $siteHandle  The Statamic site handle
     * @param  ?AssetImporter  $assetImporter  The asset importer instance
     * @return array<int|string, mixed> Array of Statamic email configurations
     */
    abstract protected function mapNotificationsToEmailConfigurations(
        array $formData,
        string $siteHandle,
        $assetImporter = null
    ): array;

    /**
     * Set form plugin configuration context for logs (Gravity Forms, Formidable, etc.).
     *
     * When processing notifications, views, or confirmations, call this so
     * warning logs show "from {plugin} form X ({source}: Y)" instead of the
     * current entry URL. Call clearFormConfigContextForLogging() when done
     * (e.g., in finally). Optional key 'plugin' (e.g., 'Gravity Forms') improves
     * the log message.
     *
     * @param  array<string, mixed>  $context  Form configuration log context.
     */
    protected function setFormConfigContextForLogging(
        array $context
    ): void {
        $formId = '?';
        if (isset($context['form_id']) && (\is_int($context['form_id']) || \is_string($context['form_id']))) {
            $formId = $context['form_id'];
        }

        $source = isset($context['source']) && \is_string($context['source'])
            ? $context['source']
            : 'unknown';
        $sourceName = isset($context['source_name']) && \is_string($context['source_name'])
            ? $context['source_name']
            : 'Unnamed';

        $normalizedContext = [
            'form_id' => $formId,
            'source' => $source,
            'source_name' => $sourceName,
        ];

        if (isset($context['plugin']) && \is_string($context['plugin'])) {
            $normalizedContext['plugin'] = $context['plugin'];
        }

        ProcessingContext::setFormConfigContext($normalizedContext);
    }

    /**
     * Clear the form configuration context set by setFormConfigContextForLogging.
     */
    protected function clearFormConfigContextForLogging(): void
    {
        ProcessingContext::clearFormConfigContext();
    }

    /**
     * Build email configuration from processed data (common logic).
     *
     * @param  array<int|string, mixed>  $processedData  The processed notification/view data
     * @param  array<int|string, mixed>  $formData  The complete form data for context
     * @param  string  $siteHandle  The Statamic site handle
     * @param  ?AssetImporter  $assetImporter  The asset importer instance
     * @param  array<int|string, mixed>  $formFields  Array of form fields for field ID to handle mapping
     * @return array<int|string, mixed> The email configuration array
     */
    protected function buildEmailConfiguration(
        array $processedData,
        array $formData,
        string $siteHandle,
        $assetImporter = null,
        array $formFields = []
    ): array {
        $emailConfig = [];

        // Read the email mapping, if the class defines one, through reflection for the
        // same reason as mapFormConfigurationFields()
        $emailMapping = (new \ReflectionClass(static::class))->getConstant('FORM_CONFIGURATION_ACTION_MAPPING_EMAIL');
        if (! \is_array($emailMapping)) {
            return $emailConfig;
        }

        // Map each notification field according to the mapping
        foreach ($emailMapping as $pluginKey => $mappingConfig) {
            // Only a table naming a single Statamic attribute is mapped here; anything else
            // needs special handling
            if (! \is_array($mappingConfig)) {
                continue;
            }

            if (! isset($mappingConfig['statamic_attribute']) || ! \is_string($mappingConfig['statamic_attribute'])) {
                continue;
            }

            $statamicKey = $mappingConfig['statamic_attribute'];

            // ---
            // Special handling for the 'to' field: Gravity Forms notifications carry a
            // `toType` discriminator ('email' / 'field' / 'routing') and the recipient in
            // `to` is only an address when it reads 'email' — the other modes are handled
            // by 'toField' and 'routing' below.
            //
            // That discriminator is a Gravity Forms concept, not a universal one. Ninja
            // Forms actions carry a plain `to` and nothing else, so an unconditional gate
            // reads the absent `toType` as null, fails the 'email' comparison, and drops
            // the recipient from every notification — a form that imports and saves
            // cleanly, with mail configured to go to nobody.
            //
            // So enforce it only for a plugin whose mapping actually declares `toType`.
            // Keying on the mapping rather than on the individual action keeps Gravity
            // Forms behavior identical even for a notification that omits the field.
            // Formidable Forms is unaffected either way: it spells the key `email_to`.
            // ---
            if (
                $pluginKey === 'to'
                && \array_key_exists(
                    'toType',
                    $emailMapping
                )
            ) {
                $toType = $this->extractValueFromData(
                    $processedData,
                    'toType'
                );
                if ($toType !== 'email') {
                    continue;
                }
            }

            // Extract the value using dot notation if needed
            $value = $this->extractValueFromData(
                $processedData,
                $pluginKey
            );

            // Check if this field should be processed even when empty (e.g., has #empty translation)
            $shouldProcessEmpty =
                isset($mappingConfig['value_translation']) &&
                \is_array($mappingConfig['value_translation']) &&
                \array_key_exists(
                    '#empty',
                    $mappingConfig['value_translation']
                );

            // Only include the field if the value exists and is not empty, OR if it should be processed when empty
            if (($value !== null && $value !== '') || $shouldProcessEmpty) {
                // Special handling for 'toField': convert field ID to field handle
                if ($pluginKey === 'toField' && $value !== null) {
                    if (! \is_scalar($value)) {
                        continue;
                    }

                    $fieldHandle = $this->getFieldHandleById(
                        (string) $value,
                        $formFields
                    );
                    if ($fieldHandle !== null) {
                        $value = $fieldHandle;
                    }
                }

                // Special handling for the legacy '*Field' aliases (fromField,
                // fromNameField, replyToField): Gravity Forms stores the source
                // field as a bare field ID. Statamic's from/from_name/reply_to
                // have no dedicated *_field selector (unlike to/to_field), so
                // resolve the ID to a field handle and emit a
                // {{ submission:<handle> }} merge tag the email renderer
                // dereferences at send time. These entries follow the literal
                // from/fromName/replyTo entries in the mapping, so a set field
                // reference overwrites the literal (matching the Gravity Forms UI).
                if (
                    isset($mappingConfig['field_id_to_submission_tag'])
                    && $mappingConfig['field_id_to_submission_tag'] === true
                    && $value !== null
                ) {
                    if (! \is_scalar($value)) {
                        continue;
                    }

                    $fieldHandle = $this->getFieldHandleById(
                        (string) $value,
                        $formFields
                    );

                    // Unresolvable field ID: leave the literal fallback in place
                    // rather than emitting a broken merge tag or leaking the ID.
                    if ($fieldHandle === null) {
                        continue;
                    }

                    $value = "{{ submission:{$fieldHandle} }}";
                }

                // Special handling for 'routing': convert routing rules to Statamic format
                if ($pluginKey === 'routing' && $value !== null && \is_array($value)) {
                    $value = $this->convertRoutingRulesToStatamicFormat(
                        $value,
                        $formFields
                    );
                }

                // Process the value using the common configuration processing method
                $value = $this->processMappingConfiguration(
                    $value,
                    $mappingConfig,
                    $formData,
                    $siteHandle,
                    $assetImporter,
                    $formFields
                );

                // Handle special cases for boolean fields
                if ($pluginKey === 'isActive') {
                    // Map isActive to enabled with proper boolean conversion
                    $emailConfig['enabled'] = isset($processedData['isActive']) ? (bool) $processedData['isActive'] : true;
                } elseif ($value !== null) {
                    // Handle special field types (like bard fields)
                    $value = $this->processFieldValue(
                        $statamicKey,
                        $value,
                        $formData,
                        $siteHandle,
                        $assetImporter
                    );

                    // Process merge tags for string fields
                    if (\is_string($value)) {
                        $value = $this->processMergeTagsInString(
                            $value,
                            $formFields
                        );
                    }

                    $emailConfig[$statamicKey] = $value;

                    // Process statamic_other_field_value if configured
                    $this->processStatamicOtherFieldValue(
                        $mappingConfig,
                        $value,
                        $emailConfig
                    );
                }
            }
        }

        $emailConfig['id'] ??= Str::random(8);

        // Set default values for required Statamic fields
        $emailConfig = $this->setDefaultEmailConfigurationValues($emailConfig);

        // Clean up variables to free up memory
        unset(
            $processedData,
            $formData,
            $siteHandle,
            $assetImporter,
            $formFields,
            $pluginKey,
            $mappingConfig,
            $statamicKey,
            $value,
            $toType,
            $fieldHandle
        );

        return $emailConfig;
    }

    /**
     * Process content mappings for email configurations (common logic).
     *
     * @param  array<int|string, mixed>  $processedData  The processed notification/view data
     * @param  array<int|string, mixed>  $formData  The complete form data for context
     * @param  string  $siteHandle  The Statamic site handle
     * @param  ?AssetImporter  $assetImporter  The asset importer instance
     * @param  array<int|string, mixed>  $formFields  Array of form fields for field ID to handle mapping
     * @param  string  $contentMappingConstant  The constant name for content mapping
     * @return array<int|string, mixed> The processed content mappings
     */
    protected function processEmailContentMappings(
        array $processedData,
        array $formData,
        string $siteHandle,
        $assetImporter = null,
        array $formFields = [],
        string $contentMappingConstant = 'FORM_CONFIGURATION_ACTION_MAPPING_EMAIL_CONTENT'
    ): array {
        $contentMappings = [];

        // Check if content mapping is defined
        $reflection = new \ReflectionClass(static::class);
        $mappingConfigs = $reflection->getConstant($contentMappingConstant);

        if (! \is_array($mappingConfigs)) {
            return $contentMappings;
        }

        foreach ($mappingConfigs as $pluginKey => $mappingConfig) {
            // Skip unmapped fields (null values in mapping)
            if ($mappingConfig === null) {
                continue;
            }

            // Handle complex mapping configurations (arrays) vs simple string mappings
            if (\is_array($mappingConfig)) {
                // Check if this is a multiple mapping configuration
                if ($this->hasMultipleMappings($mappingConfig)) {
                    // Extract the value using dot notation if needed
                    $value = $this->extractValueFromData(
                        $processedData,
                        $pluginKey
                    );
                    // Only include the field if the value exists and is not empty
                    if ($value !== null && $value !== '') {
                        // Process the value using the common configuration processing method
                        $value = $this->processMappingConfiguration(
                            $value,
                            $mappingConfig,
                            $formData,
                            $siteHandle,
                            $assetImporter,
                            $formFields
                        );

                        $multipleResults = $this->processMultipleMappingsAndGetResults(
                            $value,
                            $mappingConfig,
                            $formData,
                            $siteHandle,
                            $assetImporter
                        );

                        // Add all results to the content mappings
                        foreach ($multipleResults as $targetAttribute => $processedValue) {
                            if ($processedValue !== null) {
                                // Process merge tags for string fields
                                if (\is_string($processedValue)) {
                                    $processedValue = $this->processMergeTagsInString(
                                        $processedValue,
                                        $formFields
                                    );
                                }

                                $contentMappings[$targetAttribute] = $processedValue;
                            }
                        }

                        // Process statamic_other_field_value if configured
                        $this->processStatamicOtherFieldValue(
                            $mappingConfig,
                            $value,
                            $contentMappings
                        );
                    }

                    // Skip the rest of the processing for this field since we've handled it with multiple mappings
                    continue;
                }

                // Check if this is a multiple mapping configuration
                if (isset($mappingConfig['statamic_attribute']) && \is_string($mappingConfig['statamic_attribute'])) {
                    // Simple complex mapping with just statamic_attribute
                    $statamicKey = $mappingConfig['statamic_attribute'];
                } else { // Otherwise
                    // Skip complex mappings that need special handling
                    continue;
                }
            } elseif (\is_string($mappingConfig)) { // Otherwise
                // Simple string mapping
                $statamicKey = $mappingConfig;
            } else {
                continue;
            }

            // Extract the value using dot notation if needed
            $value = $this->extractValueFromData(
                $processedData,
                $pluginKey
            );

            // Only include the field if the value exists and is not empty
            if ($value !== null && $value !== '') {
                // Process the value using the common configuration processing method
                $mappingConfigArray = \is_array($mappingConfig)
                    ? $mappingConfig
                    : [];
                $value = $this->processMappingConfiguration(
                    $value,
                    $mappingConfigArray,
                    $formData,
                    $siteHandle,
                    $assetImporter,
                    $formFields
                );

                // Set the value in the content mappings
                $contentMappings[$statamicKey] = $value;

                // Process merge tags for string fields
                if (\is_string($value)) {
                    $value = $this->processMergeTagsInString(
                        $value,
                        $formFields
                    );
                    $contentMappings[$statamicKey] = $value;
                }

                // Process statamic_other_field_value if configured
                $this->processStatamicOtherFieldValue(
                    $mappingConfigArray,
                    $value,
                    $contentMappings
                );
            }
        }

        // Clean up variables to free up memory
        unset(
            $processedData,
            $formData,
            $siteHandle,
            $assetImporter,
            $formFields,
            $contentMappingConstant,
            $mappingConfigs,
            $pluginKey,
            $mappingConfig,
            $statamicKey,
            $value,
            $multipleResults,
            $targetAttribute,
            $processedValue
        );

        return $contentMappings;
    }

    /**
     * Process merge tags in any string value, replacing WordPress form plugin merge
     * tags with Statamic variables.
     *
     * This method can be used for any string field that may contain merge tags,
     * regardless of whether it's a bard field or a simple string field.
     *
     * @param  string  $value  The original value that may contain merge tags
     * @param  array<int|string, mixed>  $formFields  Array of form fields for field ID to handle mapping
     * @return string The processed value with Statamic-style variables
     */
    private function processMergeTagsInString(
        string $value,
        array $formFields = []
    ): string {
        // Get plugin-specific variable mappings
        $variableMappings = $this->getVariableMappings();

        // Replace WordPress-style variables with Statamic-style variables
        foreach ($variableMappings as $wordpressVar => $statamicVar) {
            $value = \str_replace(
                $wordpressVar,
                $statamicVar,
                $value
            );
        }

        // Handle complex format mappings that require regex
        // Each plugin class must implement this method to handle their specific
        // complex format mappings (e.g., GravityForms today, quiz, and survey mappings)
        $value = $this->processComplexFormatMappings($value);

        // Process field merge tags if form fields are provided
        if ($formFields !== []) {
            $value = $this->processFieldMergeTags(
                $value,
                $formFields
            );
        }

        // Clean up variables to free up memory
        unset(
            $formFields,
            $variableMappings,
            $wordpressVar,
            $statamicVar
        );

        return $value;
    }

    /**
     * Process page ID mapping for fields that contain page references.
     *
     * Maps WordPress page IDs to their corresponding Statamic entry IDs using
     * the page data mapping that was created during the page import process.
     *
     * @param  mixed  $pageId  The WordPress page ID (can be int or string)
     * @param  array<int|string, mixed>  $formData  The complete form data for context
     * @param  string  $siteHandle  The Statamic site handle
     * @return string The mapped Statamic entry ID or placeholder if not found
     */
    private function processPageIdMapping(
        $pageId,
        array $formData,
        string $siteHandle
    ): string {
        // Convert to string if it's numeric
        if (\is_numeric($pageId)) {
            $pageId = (string) $pageId;
        }

        // Check if we have page data mapping available
        if (
            \is_string($pageId)
            && isset($this->pageDataMapping[$pageId])
        ) {
            $pageData = $this->pageDataMapping[$pageId];

            // Return the Statamic entry ID if available
            $mappedPageId = data_get($pageData, 'entry');
            if (\is_scalar($mappedPageId)) {
                // Clean up variables to free up memory
                unset(
                    $pageId,
                    $formData,
                    $siteHandle,
                    $pageData
                );

                return (string) $mappedPageId;
            }
        }

        // Fallback: return a placeholder indicating the original WordPress page ID
        // This helps with debugging and manual mapping if needed
        $sourceId = isset($formData['id']) && \is_scalar($formData['id'])
            ? (string) $formData['id']
            : 'unknown';
        $pageIdString = \is_scalar($pageId) ? (string) $pageId : 'unknown';
        $mappedPageId = "{$siteHandle}_wordpress_page_id_{$pageIdString}";

        // Enhance log context with entry information
        $logData = LogContextHelper::enhanceWithEntryContext(
            [
                'wordpress_page_id' => $pageIdString,
                'form_id' => $sourceId,
                'site_handle' => $siteHandle,
                'page_data_mapping_available' => $this->pageDataMapping !== [],
            ]
        );

        // Log a warning that the page ID could not be mapped
        Log::warning(
            LogContextHelper::formatMessageWithEntry(
                "FormConfigurationManagerTrait::processPageIdMapping: Could not map WordPress page ID '{$pageIdString}' to Statamic entry ID. Using placeholder: '{$mappedPageId}'",
                $logData['page_url']
            ),
            $logData['context']
        );

        // Clean up variables to free up memory
        unset(
            $pageId,
            $pageIdString,
            $formData,
            $siteHandle,
            $sourceId
        );

        return $mappedPageId;
    }

    /**
     * Process datetime fields that require special handling.
     *
     * Each plugin class must implement this method to handle their specific
     * datetime field processing logic, including field structure, date formats,
     * and component field mapping.
     *
     * @param  string  $statamicKey  The Statamic field handle
     * @param  mixed  $value  The original value
     * @param  array<int|string, mixed>  $formData  The complete form data for context
     * @return ?string The formatted datetime string or null if incomplete
     */
    abstract protected function processDateTimeField(
        string $statamicKey,
        $value,
        array $formData
    ): ?string;

    /**
     * Process datetime combination fields and return the combined datetime value.
     *
     * Each plugin class must implement this method to handle their specific
     * datetime combination logic, including field structure, date formats,
     * and component field mapping.
     *
     * @param  array<int|string, mixed>  $mappingConfig  The mapping configuration with datetime_combination
     * @param  array<int|string, mixed>  $formData  The complete form data for context
     * @return ?string The combined datetime string or null if incomplete
     */
    abstract protected function processDatetimeCombination(
        array $mappingConfig,
        array $formData
    ): ?string;

    /**
     * Check if a mapping configuration has datetime combination fields.
     *
     * @param  array<int|string, mixed>  $mappingConfig  The mapping configuration to check
     * @return bool True if the configuration has datetime combination fields
     */
    protected function hasDatetimeCombination(
        array $mappingConfig
    ): bool {
        return isset($mappingConfig['datetime_combination'])
            && \is_array($mappingConfig['datetime_combination']);
    }

    /**
     * Extract a value from data using dot notation for nested object access.
     *
     * Supports accessing nested object properties using dot notation.
     * For example, 'button.text' will extract the 'text' property from
     * the 'button' object.
     *
     * @param  array<int|string, mixed>  $data  The data array to search in
     * @param  int|string  $key  The key to extract (supports dot notation)
     * @return ?mixed The extracted value or null if not found
     */
    private function extractValueFromData(
        array $data,
        string|int $key
    ) {
        if (\is_int($key)) {
            return $data[$key] ?? null;
        }

        // Split the key by dots to traverse nested objects
        $keys = \explode(
            '.',
            $key
        );
        $current = $data;

        // Traverse the nested structure
        foreach ($keys as $segment) {
            // Check if the current level is an array and contains the segment
            if (! \is_array($current) || ! isset($current[$segment])) {
                return null;
            }

            $current = $current[$segment];
        }

        $result = $current;

        // Clean up variables to free up memory
        unset(
            $data,
            $key,
            $keys,
            $segment,
            $current
        );

        // Return the final value
        return $result;
    }

    /**
     * Configure a Statamic form with settings from WordPress form plugin data.
     *
     * Maps WordPress form plugin notifications to Statamic email configurations
     * and form configuration fields, then applies them to the provided form.
     *
     * @param  Form  $form  The Statamic form to configure
     * @param  array<int|string, mixed>  $formData  The WordPress form plugin form data
     * @param  string  $pluginName  The name of the plugin for logging
     * @param  string  $siteHandle  The Statamic site handle
     * @param  AssetImporter  $assetImporter  The asset importer instance
     * @return bool True if configuration was successful, false otherwise
     */
    protected function configureFormSettingsInternal(
        Form $form,
        array $formData,
        string $pluginName,
        string $siteHandle,
        $assetImporter
    ): bool {
        // Map form configuration fields (like description) if mapping is defined
        $this->mapFormConfigurationFields(
            $form,
            $formData,
            $siteHandle,
            $assetImporter
        );

        // Check if the form has notifications/views (plugin-specific)
        if (! $this->hasNotifications($formData)) {
            // Save the form with the basic configuration
            $form->save();

            // Clean up variables to free up memory
            unset(
                $form,
                $formData,
                $pluginName,
                $siteHandle,
                $assetImporter
            );

            // Return true to indicate successful configuration (even without notifications)
            return true;
        }

        // Map notifications/views to Statamic email configurations (plugin-specific)
        $emailConfigurations = $this->mapNotificationsToEmailConfigurations(
            $formData,
            $siteHandle,
            $assetImporter
        );

        // Apply the email configurations to the form
        $this->applyEmailConfigurationsToForm(
            $form,
            $emailConfigurations
        );

        // Save the form with the new configuration
        $form->save();

        // Clean up variables to free up memory
        unset(
            $form,
            $formData,
            $pluginName,
            $siteHandle,
            $assetImporter,
            $emailConfigurations
        );

        // Return true to indicate successful configuration
        return true;
    }

    /**
     * Set default values for required Statamic email configuration fields.
     *
     * @param  array<int|string, mixed>  $emailConfig  The email configuration array
     * @return array<int|string, mixed> The email configuration with default values set
     */
    private function setDefaultEmailConfigurationValues(
        array $emailConfig
    ): array {
        // Convert enableAttachments boolean to attachments boolean
        if (isset($emailConfig['attachments'])) {
            $emailConfig['attachments'] = (bool) $emailConfig['attachments'];
        }

        $emailConfig['enabled'] ??= true;

        $result = $emailConfig;

        // Clean up variables to free up memory
        unset(
            $emailConfig,
        );

        // Return the email configuration with defaults applied
        return $result;
    }

    /**
     * Apply email configurations to a Statamic form.
     *
     * @param  Form  $form  The Statamic form to configure
     * @param  array<int|string, mixed>  $emailConfigurations  Array of email configurations
     */
    private function applyEmailConfigurationsToForm(
        Form $form,
        array $emailConfigurations
    ): void {
        // Set the email configurations using Statamic's email() method
        $form->email($emailConfigurations);

        // Clean up variables to free up memory
        unset(
            $form,
            $emailConfigurations
        );
    }

    /**
     * Process field merge tags and convert them to Statamic submission field tags.
     *
     * Each plugin class must implement this method to handle their specific
     * field merge tag syntax and convert them to Statamic format.
     *
     * @param  string  $value  The value to process
     * @param  array<int|string, mixed>  $formFields  Array of form fields for field ID to handle mapping
     * @return string The processed value with field merge tags converted
     */
    abstract protected function processFieldMergeTags(
        string $value,
        array $formFields
    ): string;

    /**
     * Get the field handle for a given field ID from the form fields array.
     *
     * @param  string  $fieldId  The field ID to look up
     * @param  array<int|string, mixed>  $formFields  Array of form fields
     * @return ?string The field handle or null if not found
     */
    protected function getFieldHandleById(
        string $fieldId,
        array $formFields
    ): ?string {
        // Check if this is a subfield (contains a dot)
        if (
            str_contains(
                $fieldId,
                '.'
            )
        ) {
            return $this->getSubfieldHandleById(
                $fieldId,
                $formFields
            );
        }

        // Search through all form fields to find matching field ID
        foreach ($formFields as $field) {
            if (! \is_array($field)) {
                continue;
            }

            // Check if field ID matches (convert to string for comparison)
            if (isset($field['id']) && \is_scalar($field['id']) && (string) $field['id'] === $fieldId) {
                // Extract the field name/label for handle conversion
                $fieldName = $this->getFieldNameFromField($field);

                // Convert the name to a Statamic handle format
                $handle = $this->convertLabelToHandle(
                    $fieldName,
                    $fieldId
                );

                // Clean up variables to free up memory
                unset(
                    $fieldId,
                    $formFields,
                    $field,
                );

                return $handle;
            }
        }

        // Clean up variables to free up memory
        unset(
            $fieldId,
            $formFields,
            $field,
        );

        // Return null if field ID not found in any field
        return null;
    }

    /**
     * Get the field name/label from a field array (plugin-specific).
     *
     * Each plugin class must implement this method to handle their specific
     * field property names (e.g., 'label' vs 'name').
     *
     * @param  array<int|string, mixed>  $field  The field array
     * @return string The field name/label
     */
    abstract protected function getFieldNameFromField(
        array $field
    ): string;

    /**
     * Get the subfield handle for a given subfield ID from the form fields array.
     *
     * Each plugin class must implement this method to handle their specific
     * subfield structure and mapping logic.
     *
     * @param  string  $subfieldId  The subfield ID (e.g., "67.1")
     * @param  array<int|string, mixed>  $formFields  Array of form fields
     * @return ?string The subfield handle or null if not found
     */
    abstract protected function getSubfieldHandleById(
        string $subfieldId,
        array $formFields
    ): ?string;

    /**
     * Get the subfield handle from the mapping based on field type and subfield label.
     *
     * Each plugin class must implement this method to handle their specific
     * subfield mapping logic and structure.
     *
     * @param  string  $fieldType  The field type
     * @param  string  $subfieldLabel  The subfield label
     * @param  array<int|string, mixed>  $subfieldMapping  The subfield mapping configuration
     * @return ?string The subfield handle or null if not found
     */
    abstract protected function getSubfieldHandleFromMapping(
        string $fieldType,
        string $subfieldLabel,
        array $subfieldMapping
    ): ?string;

    /**
     * Process conditional attribute mapping based on other attribute values.
     *
     * This method handles the common conditional mapping logic for both plugins.
     * The conditional mapping structure is:
     * 'based_on_other_attribute_value' => [
     *     'field_name' => [
     *         'value1' => 'statamic_field_1',
     *         'value2' => 'statamic_field_2',
     *     ],
     * ]
     *
     * @param  array<int|string, mixed>  $conditionalMapping  The conditional
     *                                                        mapping configuration
     * @param  array<int|string, mixed>  $confirmation  The confirmation data
     * @return ?string The resolved Statamic attribute name or null if condition not met
     */
    protected function processConditionalAttributeMapping(
        array $conditionalMapping,
        array $confirmation
    ): ?string {
        foreach ($conditionalMapping as $attributeKey => $valueMapping) {
            if (! \is_string($attributeKey) || ! \is_array($valueMapping)) {
                continue;
            }

            // Get the current value of the attribute we're checking
            $currentValue = $confirmation[$attributeKey] ?? null;

            // Skip when there is no value to match against
            if ($currentValue === null) {
                continue;
            }

            // Normalise booleans to their string keys ('true'/'false') so they can
            // be matched against the string-keyed value mapping.
            $lookupKey = \is_bool($currentValue)
                ? ($currentValue ? 'true' : 'false')
                : $currentValue;

            if (! \is_int($lookupKey) && ! \is_string($lookupKey)) {
                continue;
            }

            if (isset($valueMapping[$lookupKey])) {
                $resolvedAttribute = $valueMapping[$lookupKey];
                if (! \is_string($resolvedAttribute)) {
                    continue;
                }

                // Return the mapped value for this condition
                return $resolvedAttribute;
            }
        }

        // No condition was met
        return null;
    }

    /**
     * Converts a field label to a Statamic handle format, appending field ID for
     * uniqueness.
     *
     * @param  string  $label  The field label
     * @param  string  $fieldId  The field ID for uniqueness
     * @return string The handle
     */
    private function convertLabelToHandle(
        string $label,
        string $fieldId
    ): string {
        // Use the same approach as FormBlueprintCreator for consistency
        $base = \str_replace(
            '-',
            '_',
            Str::slug($label)
        );

        // If base is empty, use field ID
        if ($base === '') {
            $handle = 'field_'.$fieldId;
        } else { // Otherwise
            // Append field ID for uniqueness
            $handle = $base.'_'.$fieldId;
        }

        // Clean up variables to free up memory
        unset(
            $label,
            $fieldId,
            $base
        );

        return $handle;
    }

    /**
     * Convert routing rules to Statamic format.
     *
     * Each plugin class must implement this method to handle their specific
     * routing rule structure and convert it to Statamic format.
     *
     * @param  array<int|string, mixed>  $routingRules  Array of routing rules
     * @param  array<int|string, mixed>  $formFields  Array of form fields for field ID to handle mapping
     * @return array<int|string, mixed> Array of Statamic routing rules
     */
    abstract protected function convertRoutingRulesToStatamicFormat(
        array $routingRules,
        array $formFields
    ): array;

    /**
     * Convert routing operator to Statamic format.
     *
     * Each plugin class must implement this method to handle their specific
     * routing operator syntax and convert it to Statamic format.
     *
     * @param  string  $operator  The routing operator
     * @return string The Statamic operator
     */
    abstract protected function convertRoutingOperatorToStatamicFormat(
        string $operator
    ): string;

    /**
     * Convert various boolean-like values to actual boolean values.
     *
     * @param  mixed  $value  The value to convert to boolean
     * @return bool The converted boolean value
     */
    private function convertToBoolean(
        $value
    ): bool {
        // Handle string values: the words for true, then the trimmed text for the numeric
        // check below, which reads '0', ' 0 ' and '0.0' as false and true as numbers do. Any
        // other word, 'false', 'no' and 'off' among them, is false.
        if (\is_string($value)) {
            $value = \strtolower(\trim($value));

            if (
                \in_array(
                    $value,
                    [
                        'true',
                        'yes',
                        'on',
                    ],
                    // A string is compared with words here, so a loose comparison matches
                    // exactly what a strict one does.
                    // @pest-mutate-ignore: TrueToFalse
                    true
                )
            ) {
                return true;
            }
        }

        // Handle numeric values
        if (\is_numeric($value)) {
            return (bool) $value;
        }

        // A boolean is itself, and anything else is false
        return $value === true;
    }

    /**
     * Convert various integer-like values to actual integer values.
     *
     * @param  mixed  $value  The value to convert to integer
     * @return ?int The converted integer value or null if conversion fails
     */
    private function convertToInteger(
        $value
    ): ?int {
        // Judge a string by its trimmed text
        if (\is_string($value)) {
            $value = \trim($value);
        }

        // A whole number, given as one or as text, is that integer. Rounding it down, up or to
        // the nearest whole number all leave a whole number alone and move anything else, so
        // the choice of rounding cannot change the answer.
        // @pest-mutate-ignore: FloorToRound, FloorToCiel
        if (\is_numeric($value) && floor((float) $value) === (float) $value) {
            return (int) $value;
        }

        // Return null for any other type or non-integer values
        return null;
    }

    /**
     * Translate field references in string values to Statamic format.
     *
     * Each plugin class must implement this method to handle their specific
     * field reference syntax and convert them to Statamic format.
     *
     * @param  string  $text  The text containing field references
     * @param  array<int|string, mixed>  $allFields  Array of all form fields for field handle lookup
     * @return string The text with field references translated to Statamic format
     */
    abstract protected function translateFieldReferences(
        string $text,
        array $allFields = []
    ): string;

    /**
     * Apply value translation to a value based on the translation configuration.
     *
     * @param  mixed  $value  The value to translate
     * @param  array<int|string, mixed>  $translationConfig  The translation configuration
     * @return mixed The translated value or original value if no translation found
     */
    private function applyValueTranslation(
        $value,
        array $translationConfig
    ) {
        // Handle #empty special case for empty values
        if (
            \array_key_exists(
                '#empty',
                $translationConfig
            )
            && $this->isEmptyValue($value)
        ) {
            return $translationConfig['#empty'];
        }

        // Handle simple array structure
        if (\is_int($value) || \is_string($value)) {
            if (
                \array_key_exists(
                    $value,
                    $translationConfig
                )
            ) {
                return $translationConfig[$value];
            }
        }

        // Handle boolean to string conversion for comparison with string keys
        if (\is_bool($value)) {
            $stringValue = $value ? 'true' : 'false';
            if (
                \array_key_exists(
                    $stringValue,
                    $translationConfig
                )
            ) {
                return $translationConfig[$stringValue];
            }
        }

        // Handle complex structure with 'default', 'specific', etc.
        if (
            \array_key_exists(
                'default',
                $translationConfig
            )
            || \array_key_exists(
                'specific',
                $translationConfig
            )
        ) {
            $defaultTranslations = isset($translationConfig['default']) && \is_array($translationConfig['default'])
                ? $translationConfig['default']
                : [];
            $specificTranslations = isset($translationConfig['specific']) && \is_array($translationConfig['specific'])
                ? $translationConfig['specific']
                : [];

            // Check for exact value match in default translations
            if (\is_int($value) || \is_string($value)) {
                if (
                    \array_key_exists(
                        $value,
                        $defaultTranslations
                    )
                ) {
                    return $defaultTranslations[$value];
                }
            }

            // Check for boolean to string conversion in default translations
            if (\is_bool($value)) {
                $stringValue = $value ? 'true' : 'false';
                if (
                    \array_key_exists(
                        $stringValue,
                        $defaultTranslations
                    )
                ) {
                    return $defaultTranslations[$stringValue];
                }
            }

            // Check for exact value match in specific translations (if we had a fieldtype context)
            // For now, just use default translations
        }

        // Return original value if no translation found
        return $value;
    }

    /**
     * Check if a value should be considered empty for #empty translation purposes.
     *
     * @param  mixed  $value  The value to check
     * @return bool True if the value is considered empty, false otherwise
     */
    private function isEmptyValue(
        $value
    ): bool {
        // Handle null values
        if ($value === null) {
            return true;
        }

        // Handle empty strings and whitespace-only strings
        if (\is_string($value) && \trim($value) === '') {
            return true;
        }

        // Handle empty arrays
        if (\is_array($value) && $value === []) {
            return true;
        }

        // Handle false boolean values
        if ($value === false) {
            return true;
        }

        // Handle zero values (both integer and string)
        return $value === 0 || $value === '0';
    }

    /**
     * Apply value substitution regex patterns to a value.
     *
     * @param  string  $value  The value to transform
     * @param  array<int|string, mixed>  $patterns  Array of regex patterns where keys are patterns and values are replacements
     * @return string The transformed value
     */
    private function applyValueSubstitutionRegex(
        string $value,
        array $patterns
    ): string {
        $result = $value;

        foreach ($patterns as $pattern => $replacement) {
            if (! \is_string($pattern) || ! \is_scalar($replacement)) {
                continue;
            }

            // Apply the regex substitution
            $candidateResult = \preg_replace(
                $pattern,
                (string) $replacement,
                $result
            );

            if (\is_string($candidateResult)) {
                $result = $candidateResult;
            }
        }

        return $result;
    }

    /**
     * Process multiple mappings for a single source attribute.
     *
     * @param  mixed  $value  The original value
     * @param  array<int|string, mixed>  $multipleConfig  The multiple configuration array
     * @param  array<int|string, mixed>  $formData  The complete form data for context
     * @param  string  $siteHandle  The Statamic site handle
     * @param  ?AssetImporter  $assetImporter  The asset importer instance
     * @return array<int|string, mixed> Array of processed values with their target attributes
     */
    private function processMultipleMappings(
        $value,
        array $multipleConfig,
        array $formData,
        string $siteHandle,
        $assetImporter = null
    ): array {
        $results = [];

        // Loop through each mapping configuration
        foreach ($multipleConfig as $key => $mapping) {
            // ---
            // Handle simple key-value format where key is the target attribute
            // ---

            // Check if this is a simple string mapping format
            if (
                \is_string($mapping)
                && \is_string($key)
            ) {
                // Use the key as the target attribute name
                $targetAttribute = $key;

                // Start with the original value
                $processedValue = $value;

                // ---
                // Process the value using the common configuration processing method
                // ---

                // Get form fields array for field reference translation
                $formFields = isset($formData['fields']) && \is_array($formData['fields'])
                    ? $formData['fields']
                    : [];

                // Process the value with empty mapping config (no special processing)
                $processedValue = $this->processMappingConfiguration(
                    $processedValue,
                    [],
                    $formData,
                    $siteHandle,
                    $assetImporter,
                    $formFields
                );

                // Store the processed value for this target attribute
                $results[$targetAttribute] = $processedValue;
            } elseif (
                \is_array($mapping)
                && isset($mapping['statamic_attribute'])
                && \is_string($mapping['statamic_attribute'])
            ) { // Check if this is a complex array mapping format
                // ---
                // Handle complex format with statamic_attribute
                // ---

                // Extract the target attribute from the mapping configuration
                $targetAttribute = $mapping['statamic_attribute'];

                // Start with the original value
                $processedValue = $value;

                // ---
                // Process the value using the common configuration processing method
                // ---

                // Get form fields array for field reference translation
                $formFields = isset($formData['fields']) && \is_array($formData['fields'])
                    ? $formData['fields']
                    : [];

                // Process the value using the mapping configuration
                $processedValue = $this->processMappingConfiguration(
                    $processedValue,
                    $mapping,
                    $formData,
                    $siteHandle,
                    $assetImporter,
                    $formFields
                );

                // Store the processed value for this target attribute
                $results[$targetAttribute] = $processedValue;
            }
        }

        return $results;
    }

    /**
     * Process statamic_other_field_value configuration to set additional fields.
     *
     * @param  array<int|string, mixed>|string  $mappingConfig  The mapping
     *                                                          configuration
     * @param  mixed  $value  The processed value
     * @param  array<int|string, mixed>  &$targetData  The target data array to modify (passed by reference)
     * @param  string  $parentKey  Optional parent key for nested structures
     */
    private function processStatamicOtherFieldValue(
        array|string $mappingConfig,
        $value,
        array &$targetData,
        ?string $parentKey = null
    ): void {
        if (! \is_array($mappingConfig)) {
            return;
        }

        // Check if mapping configuration contains other field values to set
        if (
            isset($mappingConfig['statamic_other_field_value'])
            && \is_array($mappingConfig['statamic_other_field_value'])
            && (! in_array($value, [null, '', []], true))
        ) {
            // Process each other field value mapping
            foreach ($mappingConfig['statamic_other_field_value'] as $otherFieldKey => $otherFieldValue) {
                // Handle nested structure if parent key is provided
                if ($parentKey !== null) {
                    // For nested structures (like confirmation_settings)
                    if (
                        ! isset($targetData[$parentKey])
                        || ! \is_array($targetData[$parentKey])
                    ) {
                        $targetData[$parentKey] = [];
                    }

                    // Set the other field value in the nested structure
                    $targetData[$parentKey][$otherFieldKey] = $otherFieldValue;
                } else { // For top-level structures
                    $targetData[$otherFieldKey] = $otherFieldValue;
                }
            }
        }
    }

    /**
     * Process mapping configurations for a given constant and apply value assignment
     * logic.
     *
     * This method extracts the common logic for processing mapping configurations
     * that was previously duplicated across multiple methods. It handles the core
     * mapping logic and delegates the final value assignment to a callback function.
     *
     * @param  string  $mappingConstant  The constant name to iterate over
     * @param  array<int|string, mixed>  $processedViewData  The processed view data to extract values from
     * @param  array<int|string, mixed>  $formData  The complete form data for context
     * @param  string  $siteHandle  The Statamic site handle
     * @param  ?AssetImporter  $assetImporter  The asset importer instance
     * @param  callable  $valueAssignmentCallback  Callback function to handle final value assignment
     * @param  array<int|string, mixed>  $callbackParams  Additional parameters to pass to the callback
     */
    private function processMappingConfigurations(
        string $mappingConstant,
        array $processedViewData,
        array $formData,
        string $siteHandle,
        $assetImporter,
        callable $valueAssignmentCallback,
        array $callbackParams = []
    ): void {
        // Get the mapping configuration constant using reflection
        $reflection = new \ReflectionClass(static::class);

        $mappingConfigs = $reflection->getConstant($mappingConstant);
        if (! \is_array($mappingConfigs)) {
            return;
        }

        foreach ($mappingConfigs as $pluginKey => $mappingConfig) {
            // Skip unmapped fields (null values in mapping)
            if ($mappingConfig === null) {
                continue;
            }

            // Handle complex mapping configurations (arrays) vs simple string mappings
            if (\is_array($mappingConfig)) {
                $statamicAttributeConfig = $mappingConfig['statamic_attribute'] ?? null;

                // Check if this is a conditional mapping with based_on_other_attribute_value
                if (
                    \is_array($statamicAttributeConfig)
                    && isset($statamicAttributeConfig['based_on_other_attribute_value'])
                    && \is_array($statamicAttributeConfig['based_on_other_attribute_value'])
                ) {
                    $conditionalMapping = $statamicAttributeConfig['based_on_other_attribute_value'];
                    $statamicKey = $this->processConditionalAttributeMapping(
                        $conditionalMapping,
                        $processedViewData
                    );
                    if ($statamicKey === null) {
                        continue; // Skip this field if the condition isn't met
                    }
                } elseif (isset($mappingConfig['statamic_attribute']) && \is_string($mappingConfig['statamic_attribute'])) {
                    // Simple complex mapping with just statamic_attribute
                    $statamicKey = $mappingConfig['statamic_attribute'];
                } else { // Otherwise
                    // Skip complex mappings that need special handling
                    continue;
                }
            } elseif (\is_string($mappingConfig)) { // Otherwise
                // Simple string mapping
                $statamicKey = $mappingConfig;
            } else {
                continue;
            }

            // Extract the value using dot notation if needed
            $value = $this->extractValueFromData(
                $processedViewData,
                $pluginKey
            );

            // Only include the field if the value exists and is not empty
            if ($value !== null && $value !== '') {
                // Process the value using the common configuration processing method
                $formFields = isset($formData['fields']) && \is_array($formData['fields'])
                    ? $formData['fields']
                    : [];
                $value = $this->processMappingConfiguration(
                    $value,
                    \is_array($mappingConfig) ? $mappingConfig : [],
                    $formData,
                    $siteHandle,
                    $assetImporter,
                    $formFields
                );

                // Call the callback to handle the final value assignment
                call_user_func_array(
                    $valueAssignmentCallback,
                    \array_merge(
                        [
                            $statamicKey,
                            $value,
                            $mappingConfig,
                        ],
                        $callbackParams
                    )
                );
            }
        }

        // Clean up variables to free up memory
        unset(
            $reflection,
            $mappingConfigs,
            $pluginKey,
            $mappingConfig,
            $statamicKey,
            $value,
            $formFields
        );
    }

    /**
     * Check if a mapping configuration has multiple mappings
     *
     * @param  array<int|string, mixed>|string  $mappingConfig  The mapping
     *                                                          configuration to check
     * @return bool True if the configuration has multiple mappings
     */
    protected function hasMultipleMappings(
        array|string $mappingConfig
    ): bool {
        // A plain attribute name has no 'statamic_attribute' entry, so the check below would
        // answer false for it too; this narrows the parameter for PHPStan.
        // @pest-mutate-ignore: RemoveEarlyReturn
        if (! \is_array($mappingConfig)) {
            return false;
        }

        $statamicAttributeConfig = $mappingConfig['statamic_attribute'] ?? null;

        return \is_array($statamicAttributeConfig)
            && isset($statamicAttributeConfig['multiple'])
            && \is_array($statamicAttributeConfig['multiple']);
    }

    /**
     * Process multiple mappings and return results
     *
     * @param  mixed  $value  The value to process
     * @param  array<int|string, mixed>|string  $mappingConfig  The mapping
     *                                                          configuration
     * @param  array<int|string, mixed>  $formData  The form data
     * @param  string  $siteHandle  The site handle
     * @param  mixed  $assetImporter  The asset importer
     * @return array<int|string, mixed> The processed multiple mapping results
     */
    protected function processMultipleMappingsAndGetResults(
        $value,
        array|string $mappingConfig,
        array $formData,
        string $siteHandle,
        $assetImporter
    ): array {
        // As in hasMultipleMappings(), the checks below already reject a plain attribute name;
        // this narrows the parameter for PHPStan.
        // @pest-mutate-ignore: RemoveEarlyReturn
        if (! \is_array($mappingConfig)) {
            return [];
        }

        if (
            ! isset($mappingConfig['statamic_attribute'])
            || ! \is_array($mappingConfig['statamic_attribute'])
            || ! isset($mappingConfig['statamic_attribute']['multiple'])
            || ! \is_array($mappingConfig['statamic_attribute']['multiple'])
        ) {
            return [];
        }

        $normalizedAssetImporter = $assetImporter instanceof AssetImporter
            ? $assetImporter
            : null;

        return $this->processMultipleMappings(
            $value,
            $mappingConfig['statamic_attribute']['multiple'],
            $formData,
            $siteHandle,
            $normalizedAssetImporter
        );
    }

    /**
     * Handle nested field assignment to form data
     *
     * @param  array<int|string, mixed>  $currentFormData  The current form data array (passed by reference)
     * @param  string  $targetAttribute  The target attribute (may contain dots for nesting)
     * @param  mixed  $value  The value to assign
     */
    protected function assignNestedFieldToFormData(
        array &$currentFormData,
        string|int $targetAttribute,
        $value
    ): void {
        $targetAttribute = (string) $targetAttribute;

        if (
            str_contains(
                $targetAttribute,
                '.'
            )
        ) {
            $parts = \explode(
                '.',
                $targetAttribute
            );

            $parentKey = $parts[0];
            $childKey = $parts[1];

            // Initialize the parent array if it doesn't exist
            if (
                ! isset($currentFormData[$parentKey])
                || ! \is_array($currentFormData[$parentKey])
            ) {
                $currentFormData[$parentKey] = [];
            }

            $currentFormData[$parentKey][$childKey] = $value;
        } else { // Otherwise
            $currentFormData[$targetAttribute] = $value;
        }
    }

    /**
     * Handle nested field assignment to mapped data
     *
     * @param  array<int|string, mixed>  $mappedData  The mapped data array (passed by reference)
     * @param  string  $targetAttribute  The target attribute (may contain dots for nesting)
     * @param  mixed  $value  The value to assign
     * @param  bool  $isAdditional  Whether this is an additional confirmation
     */
    protected function assignNestedFieldToMappedData(
        array &$mappedData,
        string|int $targetAttribute,
        $value,
        bool $isAdditional = false
    ): void {
        if ($isAdditional) {
            // For additional confirmations, add to confirmation_settings
            if (
                ! isset($mappedData['confirmation_settings'])
                || ! \is_array($mappedData['confirmation_settings'])
            ) {
                $mappedData['confirmation_settings'] = [];
            }

            $mappedData['confirmation_settings'][$targetAttribute] = $value;
        } else { // Otherwise
            // For default confirmation, set directly
            $mappedData[$targetAttribute] = $value;
        }
    }

    /**
     * Process multiple mappings for form data with nested field support
     *
     * @param  mixed  $value  The value to process
     * @param  array<int|string, mixed>  $mappingConfig  The mapping configuration
     * @param  array<int|string, mixed>  $formData  The form data
     * @param  string  $siteHandle  The site handle
     * @param  mixed  $assetImporter  The asset importer
     * @param  array<int|string, mixed>  $currentFormData  The current form data array (passed by reference)
     * @return bool True if multiple mappings were processed, false otherwise
     */
    protected function processMultipleMappingsForFormData(
        $value,
        array $mappingConfig,
        array $formData,
        string $siteHandle,
        $assetImporter,
        array &$currentFormData
    ): bool {
        if (! $this->hasMultipleMappings($mappingConfig)) {
            return false;
        }

        $multipleResults = $this->processMultipleMappingsAndGetResults(
            $value,
            $mappingConfig,
            $formData,
            $siteHandle,
            $assetImporter
        );

        // Add all results to the current form data
        foreach ($multipleResults as $targetAttribute => $processedValue) {
            if ($processedValue !== null) {
                $this->assignNestedFieldToFormData(
                    $currentFormData,
                    $targetAttribute,
                    $processedValue
                );
            }
        }

        return true;
    }

    /**
     * Process confirmation field mapping using the provided mapping configurations.
     *
     * This method maps confirmation fields from the source plugin to Statamic
     * confirmation fields using the provided mapping configurations.
     *
     * @param  array<int|string, mixed>  $confirmation  The confirmation data to map
     * @param  array<int|string, mixed>  $formData  The complete form data for context
     * @param  string  $siteHandle  The Statamic site handle
     * @param  ?AssetImporter  $assetImporter  The asset importer instance
     * @param  array<int|string, mixed>  $formFields  Array of form fields for field reference translation
     * @param  array<int|string, mixed>  $mappingConfigs  The mapping configurations to apply
     * @param  callable  $valueProcessor  Callback to process the final value
     * @param  bool  $isDefaultConfirmation  Whether this is a default confirmation (affects
     *                                       exclude_from_default_confirmation logic)
     * @return array<int|string, mixed> The processed confirmation data
     */
    protected function processConfirmationFieldMapping(
        array $confirmation,
        array $formData,
        string $siteHandle,
        $assetImporter,
        array $formFields,
        array $mappingConfigs,
        callable $valueProcessor,
        bool $isDefaultConfirmation = false
    ): array {
        $mappedData = [];

        foreach ($mappingConfigs as $pluginKey => $mappingConfig) {
            // Skip unmapped fields (null values in mapping)
            if ($mappingConfig === null) {
                continue;
            }

            // Handle complex mapping configurations (arrays) vs simple string mappings
            if (\is_array($mappingConfig)) {
                // Check if this field should be excluded from default confirmations, a flag only
                // a table can carry
                if ($isDefaultConfirmation && ($mappingConfig['exclude_from_default_confirmation'] ?? null) === true) {
                    continue;
                }

                $statamicAttributeConfig = $mappingConfig['statamic_attribute'] ?? null;

                // Check if this is a conditional mapping with based_on_other_attribute_value
                if (
                    \is_array($statamicAttributeConfig)
                    && isset($statamicAttributeConfig['based_on_other_attribute_value'])
                    && \is_array($statamicAttributeConfig['based_on_other_attribute_value'])
                ) {
                    $conditionalMapping = $statamicAttributeConfig['based_on_other_attribute_value'];
                    $statamicKey = $this->processConditionalAttributeMapping(
                        $conditionalMapping,
                        $confirmation
                    );
                    if ($statamicKey === null) {
                        continue; // Skip this field if the condition isn't met
                    }
                } elseif (isset($mappingConfig['statamic_attribute']) && \is_string($mappingConfig['statamic_attribute'])) {
                    // Simple complex mapping with just statamic_attribute
                    $statamicKey = $mappingConfig['statamic_attribute'];
                } else { // Otherwise
                    // Skip complex mappings that need special handling
                    continue;
                }
            } elseif (\is_string($mappingConfig)) { // Otherwise
                // Simple string mapping
                $statamicKey = $mappingConfig;
            } else {
                continue;
            }

            // Only include the field if it exists in the confirmation and has a meaningful value
            if (isset($confirmation[$pluginKey]) && $this->hasMeaningfulValue($confirmation[$pluginKey])) {
                // Get the original value from the confirmation data
                $value = $confirmation[$pluginKey];

                // Process the value using the common configuration processing method
                $mappingConfigArray = \is_array($mappingConfig)
                    ? $mappingConfig
                    : [];
                $value = $this->processMappingConfiguration(
                    $value,
                    $mappingConfigArray,
                    $formData,
                    $siteHandle,
                    $assetImporter,
                    $formFields
                );

                // Check if this is a multiple mapping configuration
                if (\is_array($mappingConfig) && $this->hasMultipleMappings($mappingConfig)) {
                    // Process multiple mappings
                    $multipleResults = $this->processMultipleMappingsAndGetResults(
                        $value,
                        $mappingConfig,
                        $formData,
                        $siteHandle,
                        $assetImporter
                    );

                    // Add all results to the mapped data
                    foreach ($multipleResults as $targetAttribute => $processedValue) {
                        if ($processedValue !== null) {
                            $this->assignNestedFieldToMappedData(
                                $mappedData,
                                $targetAttribute,
                                $processedValue,
                                false
                            );
                        }
                    }

                    // Skip the rest of the processing for this field since we've handled it with multiple mappings
                    continue;
                }

                // Handle special field types
                $value = $this->processFieldValue(
                    $statamicKey,
                    $value,
                    $formData,
                    $siteHandle,
                    $assetImporter
                );

                // Only call the callback if the processed value is meaningful
                if ($this->hasMeaningfulValue($value)) {
                    // Use the callback to process the final value
                    $valueProcessor(
                        $statamicKey,
                        $value,
                        $mappingConfig
                    );

                    // Process statamic_other_field_value if configured
                    $this->processStatamicOtherFieldValue(
                        $mappingConfigArray,
                        $value,
                        $mappedData
                    );
                }
            }
        }

        return $mappedData;
    }

    /**
     * Check if a value has meaningful content (not empty, null, or just whitespace).
     *
     * @param  mixed  $value  The value to check
     * @return bool True if the value has meaningful content, false otherwise
     */
    private function hasMeaningfulValue(
        $value
    ): bool {
        // Handle null values
        if ($value === null) {
            return false;
        }

        // Handle empty strings and whitespace-only strings
        if (\is_string($value) && \trim($value) === '') {
            return false;
        }

        // Handle empty arrays
        if (\is_array($value) && $value === []) {
            return false;
        }

        // Handle false, the one other value that means nothing
        return $value !== false;
    }

    /**
     * Process multiple mappings for mapped data with confirmation settings support
     *
     * @param  mixed  $value  The value to process
     * @param  array<int|string, mixed>  $mappingConfig  The mapping configuration
     * @param  array<int|string, mixed>  $formData  The form data
     * @param  string  $siteHandle  The site handle
     * @param  mixed  $assetImporter  The asset importer
     * @param  array<int|string, mixed>  $mappedData  The mapped data array (passed by reference)
     * @param  bool  $isAdditional  Whether this is an additional confirmation
     * @param  bool  $processOtherField  Whether to process statamic_other_field_value
     * @return bool True if multiple mappings were processed, false otherwise
     */
    protected function processMultipleMappingsForMappedData(
        $value,
        array $mappingConfig,
        array $formData,
        string $siteHandle,
        $assetImporter,
        array &$mappedData,
        bool $isAdditional = false,
        bool $processOtherField = false
    ): bool {
        if (! $this->hasMultipleMappings($mappingConfig)) {
            return false;
        }

        $multipleResults = $this->processMultipleMappingsAndGetResults(
            $value,
            $mappingConfig,
            $formData,
            $siteHandle,
            $assetImporter
        );

        // Add all results to the mapped data
        foreach ($multipleResults as $targetAttribute => $processedValue) {
            if ($processedValue !== null) {
                $this->assignNestedFieldToMappedData(
                    $mappedData,
                    $targetAttribute,
                    $processedValue,
                    $isAdditional
                );

                // Process statamic_other_field_value if configured (for multiple mappings)
                if ($processOtherField) {
                    $this->processStatamicOtherFieldValue(
                        $mappingConfig,
                        $processedValue,
                        $mappedData,
                        $isAdditional ? 'confirmation_settings' : null
                    );
                }
            }
        }

        return true;
    }

    /**
     * Trim whitespace at beginning, end, and around commas in comma-separated values.
     *
     * This method cleans up comma-separated strings by removing extra whitespace
     * around commas and at the beginning and end of the string. For example:
     * " foo@bar.com, bar@bar.com , baz@bar.com " becomes
     * "foo@bar.com,bar@bar.com,baz@bar.com"
     *
     * @param  string  $value  The comma-separated string to clean up
     * @return string The cleaned comma-separated string
     */
    private function trimCommaSeparatedValues(
        string $value
    ): string {
        // Split by comma, trim each part, and rejoin
        $parts = \explode(
            ',',
            $value
        );
        $trimmedParts = \array_map(
            trim(...),
            $parts
        );

        // Filter out empty parts and rejoin with commas
        $filteredParts = \array_filter(
            $trimmedParts,
            fn ($part): bool => $part !== ''
        );

        return \implode(
            ',',
            $filteredParts
        );
    }
}
