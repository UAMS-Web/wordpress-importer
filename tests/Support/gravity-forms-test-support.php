<?php

declare(strict_types=1);

use Illuminate\Support\Collection as IlluminateCollection;
use Illuminate\Support\Str as IlluminateStr;
use Illuminate\Testing\PendingCommand;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Site as SiteFacade;
use Statamic\Fields\Values;
use Statamic\Forms\Form as StatamicForm;
use Statamic\Sites\Site;
use Statamic\Stache\Query\EntryQueryBuilder;
use Tests\TestCase;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FieldtypeMapping;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FormBlueprintCreator;

/**
 * Return an empty, precisely typed list of temporary forms for per-test tracking.
 *
 * @return list<StatamicForm>
 */
function gravityEmptyFormList(): array
{
    return [];
}

/**
 * Return an empty, precisely typed list of temporary blueprint handles.
 *
 * @return list<string>
 */
function gravityEmptyStringList(): array
{
    return [];
}

/**
 * Delete the temporary forms and blueprints created during a test.
 *
 * @param  list<StatamicForm>  $forms
 * @param  list<string>  $handles
 */
function gravityCleanupTemporaryForms(
    array $forms,
    array $handles
): void {
    // Clean up forms
    foreach ($forms as $form) {
        try {
            $form->delete();
        } catch (Exception $e) {
            // Ignore errors if form was already deleted or doesn't exist
        }
    }

    // Clean up blueprints
    $blueprintCreator = new FormBlueprintCreator;
    foreach ($handles as $handle) {
        try {
            $blueprintCreator->deleteBlueprint($handle);
        } catch (Exception $e) {
            // Ignore errors if blueprint was already deleted or doesn't exist
        }
    }
}

/**
 * Normalize a mixed value to array shape for safe iteration and offsets.
 *
 * @return array<int|string, mixed>
 */
function gravityAsArray(mixed $value): array
{
    if (! \is_array($value)) {
        return [];
    }

    return $value;
}

/**
 * Return the matched files for a glob pattern, or an empty list on failure.
 *
 * @return array<int, string>
 */
function gravityGlobMatches(string $pattern): array
{
    $matches = glob($pattern);

    if ($matches === false) {
        return [];
    }

    return $matches;
}

/**
 * Return the Stache-backed entry query builder from the pages collection.
 */
function gravityEntryQuery(): EntryQueryBuilder
{
    $collection = CollectionFacade::find('pages');
    if ($collection === null) {
        throw new RuntimeException('Pages collection not found.');
    }

    $query = $collection->queryEntries();
    if (! $query instanceof EntryQueryBuilder) {
        throw new RuntimeException('Entry query() did not return EntryQueryBuilder.');
    }

    return $query;
}

/**
 * Find a pages collection entry by site and slug.
 *
 * @param  string  $slug  The entry slug
 */
function gravityRequirePagesEntryBySlug(string $slug): StatamicEntry
{
    $query = gravityEntryQuery()->where(
        'site',
        'default'
    );
    if (! $query instanceof EntryQueryBuilder) {
        throw new RuntimeException('Entry site filter did not return EntryQueryBuilder.');
    }

    $slugQuery = $query->where(
        'slug',
        $slug
    );
    if (! $slugQuery instanceof EntryQueryBuilder) {
        throw new RuntimeException('Entry slug filter did not return EntryQueryBuilder.');
    }

    $entry = $slugQuery->first();
    expect($entry)->toBeInstanceOf(StatamicEntry::class);
    if (! $entry instanceof StatamicEntry) {
        throw new RuntimeException("Pages entry with slug '{$slug}' should exist.");
    }

    return $entry;
}

/**
 * Start the WordPress import command for a given XML file path.
 *
 * @param  string  $file  Path to the WordPress export XML file
 */
function gravityWordpressImportCommand(TestCase $test, string $file): PendingCommand
{
    $command = $test->artisan(
        'statamic:import:wordpress',
        ['file' => $file]
    );
    if (! $command instanceof PendingCommand) {
        throw new RuntimeException('Expected a PendingCommand from the WordPress import command.');
    }

    return $command;
}

/**
 * Return test form field rows from the shared fixture data.
 *
 * @return list<array<string, mixed>>
 */
function gravitySharedTestFormFields(): array
{
    $fields = gravityAsArray(gravityTestFormData()['fields'] ?? []);
    $validated = [];

    foreach ($fields as $field) {
        $validated[] = gravityNormalizeStringKeyedRow($field);
    }

    return $validated;
}

/**
 * Resolve a Statamic site display name for form creation tests.
 */
function gravitySiteDisplayName(string $siteHandle): string
{
    $siteInstance = SiteFacade::get($siteHandle);
    if (! $siteInstance instanceof Site) {
        return $siteHandle;
    }

    $name = $siteInstance->name();

    return \is_string($name) ? $name : $siteHandle;
}

/**
 * Return a string field from a normalized form array.
 *
 * @param  array<string, mixed>  $formData
 */
function gravityRequireFormString(array $formData, string $key): string
{
    $value = $formData[$key] ?? null;
    if (! is_string($value)) {
        throw new RuntimeException("Form data key '{$key}' should be a string.");
    }

    return $value;
}

/**
 * Return a scalar field from a normalized form array as a string.
 *
 * Gravity Forms exports carry a form `id` as either an integer or a string, so a
 * strict string guard rejects most fixtures outright. This accepts any scalar and
 * renders it as a string, while still rejecting arrays, objects, and null. wordpress-importer-archive#469
 *
 * @param  array<string, mixed>  $formData
 */
function gravityRequireFormScalarString(array $formData, string $key): string
{
    $value = $formData[$key] ?? null;
    if (! \is_scalar($value)) {
        throw new RuntimeException("Form data key '{$key}' should be a scalar.");
    }

    return (string) $value;
}

/**
 * Return a blueprint expectation row at the given index.
 *
 * @param  list<array<string, mixed>>  $statamicBlueprint
 * @return array<string, mixed>
 */
function gravityRequireBlueprintExpectationAt(
    array $statamicBlueprint,
    int $index
): array {
    expect($statamicBlueprint)->toHaveKey($index);

    $expected = $statamicBlueprint[$index] ?? null;

    return gravityNormalizeStringKeyedRow($expected);
}

/**
 * Invoke the private createBlueprintField method via reflection.
 *
 * @param  array<string, mixed>  $fieldData
 * @param  list<array<string, mixed>>  $source
 * @return array<string, mixed>
 */
function gravityInvokeCreateBlueprintField(
    FormBlueprintCreator $blueprintCreator,
    array $fieldData,
    array $source,
    string $formTitle = 'Test Form',
    string $formId = '57'
): array {
    $reflection = new ReflectionClass($blueprintCreator);
    $method = $reflection->getMethod('createBlueprintField');
    $method->setAccessible(true);

    $result = $method->invoke(
        $blueprintCreator,
        $fieldData,
        'gravity forms',
        $source,
        $formTitle,
        $formId,
        'supply_chain'
    );

    return gravityNormalizeStringKeyedRow($result);
}

/**
 * Invoke the private generateFieldHandle method via reflection.
 *
 * @param  array<string, mixed>  $fieldData
 */
function gravityInvokeGenerateFieldHandle(
    FormBlueprintCreator $blueprintCreator,
    array $fieldData
): string {
    $reflection = new ReflectionClass($blueprintCreator);
    $method = $reflection->getMethod('generateFieldHandle');
    $method->setAccessible(true);

    $result = $method->invoke(
        $blueprintCreator,
        $fieldData
    );
    if (! is_string($result)) {
        throw new RuntimeException('Expected generateFieldHandle to return a string.');
    }

    return $result;
}

/**
 * Return a string entry from base field definition metadata.
 *
 * @param  array<string, array<string, mixed>|string>  $definitions
 */
function gravityFormDefinitionString(
    array $definitions,
    string $key
): string {
    if (! \array_key_exists($key, $definitions)) {
        throw new RuntimeException("Missing base field definition '{$key}'.");
    }
    $value = $definitions[$key];
    if (! is_string($value)) {
        throw new RuntimeException("Base field definition '{$key}' should be a string.");
    }

    return $value;
}

/**
 * Return a base Gravity Forms field fixture row from definition metadata.
 *
 * @param  array<string, array<string, mixed>|string>  $definitions
 * @return array<string, mixed>
 */
function gravityFormBaseField(
    array $definitions,
    string $key
): array {
    if (! \array_key_exists($key, $definitions)) {
        throw new RuntimeException("Missing base field '{$key}'.");
    }
    $value = $definitions[$key];
    if (! is_array($value)) {
        throw new RuntimeException("Base field '{$key}' should be an array.");
    }

    return $value;
}

/**
 * Return normalized form configuration data from a Statamic form.
 *
 * @return array<string, mixed>
 */
function gravityFormConfigurationData(StatamicForm $form): array
{
    // Statamic's Form::data() returns an Illuminate collection, where an earlier
    // version returned a Fields\Values instance. Both expose all(), so accept
    // either rather than pinning the assertion to one of them — this assertion had
    // never executed, so the stale expectation went unnoticed. wordpress-importer-archive#469
    $data = $form->data();
    if (
        ! $data instanceof Values
        && ! $data instanceof IlluminateCollection
    ) {
        throw new RuntimeException('Expected the form data to be a Values or collection instance.');
    }

    $all = $data->all();
    expect($all)->toBeArray();
    if (! is_array($all)) {
        throw new RuntimeException('Expected the form data values to be an array.');
    }

    $normalized = [];

    foreach ($all as $key => $value) {
        if (\is_string($key)) {
            $normalized[$key] = $value;
        }
    }

    return $normalized;
}

/**
 * Find the expected Statamic form definition for a Gravity Forms source form.
 *
 * @param  list<array<string, mixed>>  $statamicForms
 * @param  array<string, mixed>  $formData
 * @return array<string, mixed>|null
 */
function gravityFindExpectedStatamicForm(
    array $statamicForms,
    array $formData
): ?array {
    $formId = gravityRequireFormScalarString($formData, 'id');
    $formTitle = gravityRequireFormString($formData, 'title');
    $sluggifiedName = IlluminateStr::slug($formTitle);

    foreach ($statamicForms as $expectedForm) {
        if (! isset($expectedForm['handle'])) {
            continue;
        }

        $expectedHandle = $expectedForm['handle'];
        if (! \is_string($expectedHandle)) {
            continue;
        }

        if (
            \str_contains($expectedHandle, $formId)
            && \str_contains($expectedHandle, $sluggifiedName)
        ) {
            return $expectedForm;
        }
    }

    return null;
}

/**
 * Return Gravity form rows from a repeater provider source payload.
 *
 * The payload is a list of form arrays alongside a string-keyed `version` entry,
 * which is exactly what the body skips — the previous `array<string, mixed>` was
 * never the real shape. wordpress-importer-archive#469
 *
 * @param  array<int|string, mixed>  $source
 * @return list<array<string, mixed>>
 */
function gravityRequireSourceForms(array $source): array
{
    $validated = [];

    foreach ($source as $key => $form) {
        if ($key === 'version') {
            continue;
        }

        if (\is_array($form)) {
            $validated[] = gravityNormalizeStringKeyedRow($form);
        }
    }

    return $validated;
}

/**
 * Return field rows from a normalized form array.
 *
 * @param  array<string, mixed>  $formData
 * @return list<array<string, mixed>>
 */
function gravityRequireFormFieldsFromFormData(array $formData): array
{
    $fields = gravityAsArray($formData['fields'] ?? []);
    $validated = [];

    foreach ($fields as $field) {
        $validated[] = gravityNormalizeStringKeyedRow($field);
    }

    return $validated;
}

/**
 * Assert repeater field set groups match the expected blueprint structure.
 *
 * @param  array<string, mixed>  $fieldConfig
 * @param  array<string, mixed>  $expectedSets
 */
function gravityAssertRepeaterSetsMatch(
    array $fieldConfig,
    array $expectedSets
): void {
    $fieldSets = gravityAsArray($fieldConfig['sets'] ?? []);

    expect($fieldConfig)->toHaveKey('sets');

    foreach ($expectedSets as $setGroupKey => $setGroup) {
        expect($setGroup)->toBeArray();
        if (! is_array($setGroup)) {
            throw new RuntimeException('Expected the repeater set group to be an array.');
        }
        $setGroupKeyString = gravityMessageKey($setGroupKey);
        $setGroupConfig = gravityAsArray($fieldSets[$setGroupKeyString] ?? []);

        expect($fieldSets)->toHaveKey($setGroupKeyString);

        foreach ($setGroup as $setKey => $setDefinition) {
            expect($setDefinition)->toBeArray();
            if (! is_array($setDefinition)) {
                throw new RuntimeException('Expected the repeater set definition to be an array.');
            }
            $setKeyString = gravityMessageKey($setKey);
            $actualSet = gravityAsArray($setGroupConfig[$setKeyString] ?? []);

            expect($setGroupConfig)->toHaveKey($setKeyString);
            expect($actualSet)->toEqual($setDefinition);
        }
    }
}

/**
 * Normalize a mixed row to a string-keyed array for assertions.
 *
 * @return array<string, mixed>
 */
function gravityNormalizeStringKeyedRow(mixed $row): array
{
    if (! is_array($row)) {
        throw new RuntimeException('Expected a string-keyed array row.');
    }

    $normalized = [];

    foreach ($row as $key => $value) {
        if (\is_string($key)) {
            $normalized[$key] = $value;
        }
    }

    return $normalized;
}

/**
 * Normalize a map with string keys and array values.
 *
 * @param  array<int|string, mixed>  $map
 * @return array<string, mixed>
 */
function gravityNormalizeStringKeyedMap(array $map): array
{
    $normalized = [];

    foreach ($map as $key => $value) {
        if (\is_string($key) && \is_array($value)) {
            $normalized[$key] = $value;
        }
    }

    return $normalized;
}

/**
 * Return a page-data mapping row for the given WordPress page id.
 *
 * @param  array<int|string, mixed>  $mapping
 * @return array<string, mixed>
 */
function gravityRequireMappingEntry(
    array $mapping,
    string $pageId
): array {
    if (! \array_key_exists($pageId, $mapping)) {
        throw new RuntimeException("Expected page data mapping offset '{$pageId}'.");
    }

    return gravityNormalizeStringKeyedRow($mapping[$pageId]);
}

/**
 * Return a scalar string for assertion message interpolation.
 *
 * Retained after wordpress-importer-archive#509, but not as a `peststan` artifact. Both call sites iterate
 * an array the `*NormalizeStringKeyedRow` helper has already string-keyed, so
 * PHPStan proves the key is a `string` and the `int` / `json_encode` branches
 * below are unreachable today. This is defence against a future caller passing
 * an integer key, not analyser appeasement.
 */
function gravityMessageKey(mixed $key): string
{
    if (\is_string($key)) {
        return $key;
    }

    if (\is_int($key)) {
        return (string) $key;
    }

    return \json_encode($key, JSON_THROW_ON_ERROR);
}

/**
 * Normalize a list of mixed rows to string-keyed arrays.
 *
 * @param  array<int|string, mixed>  $rows
 * @return list<array<string, mixed>>
 */
function gravityNormalizeStringKeyedRows(array $rows): array
{
    $normalized = [];

    foreach ($rows as $row) {
        $normalized[] = gravityNormalizeStringKeyedRow($row);
    }

    return $normalized;
}

/**
 * Narrow the handle of a temporary Statamic form to a string.
 */
function gravityRequireFormHandle(StatamicForm $form): string
{
    $handle = $form->handle();
    if (! is_string($handle)) {
        throw new RuntimeException('Expected the form handle to be a string.');
    }

    return $handle;
}

/**
 * Normalize assertion values to avoid failures from non-semantic editor attrs.
 *
 * @param  mixed  $value  The value to normalize recursively
 * @return mixed The normalized value
 */
function gravityNormalizeValueForAssertion(
    mixed $value
): mixed {
    // Check if the value is an array
    if (! \is_array($value)) {
        // Return value as-is
        return $value;
    }

    // ---
    // Remove rich-text node attrs, then normalize nested values recursively.
    // ---

    // Initialize the normalized value as an empty array
    $normalizedValue = [];

    // Loop through the value
    foreach ($value as $key => $nestedValue) {
        // Check if the key is 'attrs'
        if ($key === 'attrs') {
            // Skip the key
            continue;
        }

        // Normalize the nested value
        $normalizedValue[$key] = gravityNormalizeValueForAssertion($nestedValue);
    }

    // Return the normalized value
    return $normalizedValue;
}

/**
 * Test data for Gravity Forms blueprint creation tests.
 *
 * @return array<string, mixed>
 */
function gravityTestFormData(): array
{
    return [
        'fields' => [
            [
                'id' => '1',
                'type' => 'text',
                'label' => 'First Name',
                'adminLabel' => 'first_name',
                'isRequired' => true,
                'size' => 'medium',
                'errorMessage' => '',
                'visibility' => 'visible',
                'formId' => '57',
                'description' => '',
                'placeholder' => 'Enter your first name',
                'cssClass' => '',
                'inputName' => '',
                'noDuplicates' => false,
                'defaultValue' => '',
                'choices' => '',
                'conditionalLogic' => '',
                'productField' => '',
                'form_id' => '',
                'useRichTextEditor' => false,
                'multipleFiles' => false,
                'maxFiles' => '',
                'calculationFormula' => '',
                'calculationRounding' => '',
                'enableCalculation' => '',
                'disableQuantity' => false,
                'displayAllCategories' => false,
                'displayOnly' => '',
                'fields' => '',
            ],
            [
                'id' => '2',
                'type' => 'email',
                'label' => 'Email Address',
                'adminLabel' => 'email_address',
                'isRequired' => true,
                'size' => 'medium',
                'errorMessage' => '',
                'visibility' => 'visible',
                'formId' => '57',
                'description' => '',
                'placeholder' => 'Enter your email address',
                'cssClass' => '',
                'inputName' => '',
                'noDuplicates' => false,
                'defaultValue' => '',
                'choices' => '',
                'conditionalLogic' => '',
                'productField' => '',
                'form_id' => '',
                'useRichTextEditor' => false,
                'multipleFiles' => false,
                'maxFiles' => '',
                'calculationFormula' => '',
                'calculationRounding' => '',
                'enableCalculation' => '',
                'disableQuantity' => false,
                'displayAllCategories' => false,
                'displayOnly' => '',
                'fields' => '',
            ],
            [
                'id' => '3',
                'type' => 'textarea',
                'label' => 'Message',
                'adminLabel' => 'message',
                'isRequired' => false,
                'size' => 'medium',
                'errorMessage' => '',
                'visibility' => 'visible',
                'formId' => '57',
                'description' => 'Please provide additional details',
                'placeholder' => 'Enter your message',
                'cssClass' => '',
                'inputName' => '',
                'noDuplicates' => false,
                'defaultValue' => '',
                'choices' => '',
                'conditionalLogic' => '',
                'productField' => '',
                'form_id' => '',
                'useRichTextEditor' => true,
                'multipleFiles' => false,
                'maxFiles' => '',
                'calculationFormula' => '',
                'calculationRounding' => '',
                'enableCalculation' => '',
                'disableQuantity' => false,
                'displayAllCategories' => false,
                'displayOnly' => '',
                'fields' => '',
            ],
        ],
    ];
}

/**
 * Get base field definitions used across multiple data providers.
 *
 * This method centralizes the field definitions to avoid duplication
 * between gravityAttributeMappingProvider() and gravityAttributeMappingRepeaterProvider().
 *
 * @return array<string, array<string, mixed>|string> Array containing all base field definitions
 */
function gravityGetBaseFieldDefinitions(): array
{

    $siteHandle = 'supply_chain';

    $siteTitle = 'Supply Chain';

    $baseFieldComponent = [
        'id' => 123,
        'formId' => 999,
        'label' => 'Test Field',
        'adminLabel' => '',
        'isRequired' => false,
        'size' => 'large',
        'errorMessage' => '',
        'visibility' => 'visible',
        'allowsPrepopulate' => false,
        'inputMask' => false,
        'inputMaskValue' => '',
        'maxLength' => '',
        'labelPlacement' => '',
        'descriptionPlacement' => '',
        'subLabelPlacement' => '',
        'placeholder' => '',
        'cssClass' => '',
        'inputName' => '',
        'noDuplicates' => false,
        'defaultValue' => '',
        'enableAutocomplete' => false,
        'conditionalLogic' => '',
        'productField' => '',
        'layoutGridColumnSpan' => '',
        'enableDisplayInColumns' => '',
        'enableEnhancedUI' => 0,
        'layoutGroupId' => '1ec2839e',
    ];

    $baseField_text = \array_merge(
        $baseFieldComponent,
        [
            'id' => 123,
            'type' => 'text',
            'inputs' => null,
            'description' => '',
            'inputMaskIsCustom' => '',
            'autocompleteAttribute' => '',
            'choices' => '',
            'enablePasswordInput' => '',
            'fields' => '',
        ]
    );

    $baseField_email = \array_merge(
        $baseFieldComponent,
        [
            'id' => 124,
            'type' => 'email',
            'inputs' => null,
            'autocompleteAttribute' => 'email',
            'description' => '',
            'inputMaskIsCustom' => false,
            'choices' => '',
            'emailConfirmEnabled' => '',
            'multipleFiles' => false,
            'maxFiles' => '',
            'calculationFormula' => '',
            'calculationRounding' => '',
            'enableCalculation' => '',
            'disableQuantity' => false,
            'displayAllCategories' => false,
            'useRichTextEditor' => false,
            'fields' => '',
        ]
    );

    $baseField_address = \array_merge(
        $baseFieldComponent,
        [
            'id' => 125,
            'type' => 'address',
            'addressType' => 'international',
            'inputs' => [
                [
                    'id' => '125.1',
                    'label' => 'Street Address',
                    'name' => '',
                    'autocompleteAttribute' => 'address-line1',
                ],
                [
                    'id' => '125.2',
                    'label' => 'Address Line 2',
                    'name' => '',
                    'autocompleteAttribute' => 'address-line2',
                ],
                [
                    'id' => '125.3',
                    'label' => 'City',
                    'name' => '',
                    'autocompleteAttribute' => 'address-level2',
                ],
                [
                    'id' => '125.4',
                    'label' => 'State \/ Province',
                    'name' => '',
                    'autocompleteAttribute' => 'address-level1',
                ],
                [
                    'id' => '125.5',
                    'label' => 'ZIP \/ Postal Code',
                    'name' => '',
                    'autocompleteAttribute' => 'postal-code',
                ],
                [
                    'id' => '125.6',
                    'label' => 'Country',
                    'name' => '',
                    'autocompleteAttribute' => 'country-name',
                ],
            ],
            'description' => '',
            'inputMaskIsCustom' => '',
            'autocompleteAttribute' => '',
            'choices' => '',
            'defaultCountry' => '',
            'defaultProvince' => '',
            'copyValuesOptionLabel' => '',
            'hideCountry' => '',
            'defaultState' => '',
            'hideState' => '',
            'hideAddress2' => '',
            'enableCopyValuesOption' => '',
            'copyValuesOptionDefault' => '',
            'copyValuesOptionField' => '',
            'fields' => '',
        ]
    );

    $baseField_consent = \array_merge(
        $baseFieldComponent,
        [
            'id' => 126,
            'type' => 'consent',
            'checked_indicator_url' => 'https:\/\/communications.uams.edu\/web\/wp-content\/plugins\/gravityforms\/images\/tick.png',
            'checked_indicator_markup' => '<img src=\'https:\/\/communications.uams.edu\/web\/wp-content\/plugins\/gravityforms\/images\/tick.png\' alt=\'\' \/>',
            'inputs' => [
                [
                    'id' => '126.1',
                    'label' => 'Consent',
                    'name' => '',
                ],
                [
                    'id' => '126.2',
                    'label' => 'Text',
                    'name' => '',
                    'isHidden' => true,
                ],
                [
                    'id' => '126.3',
                    'label' => 'Description',
                    'name' => '',
                    'isHidden' => true,
                ],
            ],
            'checkboxLabel' => 'I agree to the privacy policy.',
            'descriptionPlaceholder' => 'Enter consent agreement text here.  The Consent Field will store this agreement text with the form entry in order to track what the user has consented to.',
            'choices' => [
                [
                    'text' => 'Checked',
                    'value' => '1',
                    'isSelected' => false,
                    'price' => '',
                ],
            ],
            'description' => "Foo.\nBar.\n\nBaz.", // double quotes to preserve newlines
            'inputMaskIsCustom' => false,
            'autocompleteAttribute' => '',
            'multipleFiles' => false,
            'maxFiles' => '',
            'calculationFormula' => '',
            'calculationRounding' => '',
            'enableCalculation' => '',
            'disableQuantity' => false,
            'displayAllCategories' => false,
            'useRichTextEditor' => false,
            'fields' => '',
            'validateState' => true,
        ]
    );

    $baseField_date = \array_merge(
        $baseFieldComponent,
        [
            'id' => 127,
            'type' => 'date',
            'inputs' => null,
            'dateType' => 'datepicker',
            'dateFormat' => 'mdy',
            'dateFormatPlacement' => 'below',
            'calendarIconType' => 'none',
            'description' => '',
            'inputMaskIsCustom' => '',
            'autocompleteAttribute' => '',
            'choices' => '',
            'calendarIconUrl' => '',
            'fields' => '',
        ]
    );

    $baseField_html = \array_merge(
        $baseFieldComponent,
        [
            'id' => 128,
            'type' => 'html',
            'inputs' => null,
            'displayOnly' => true,
            'description' => '',
            'inputMaskIsCustom' => false,
            'autocompleteAttribute' => '',
            'choices' => '',
            'content' => "<ul>\n  <li>Lorem ipsum dolor sit amet consectetuer.<\/li>\n  <li>Aenean commodo ligula eget dolor.<\/li>\n  <li>Aenean massa cum sociis natoque penatibus.<\/li>\n<\/ul>", // double quotes to preserve newlines
            'disableMargins' => '',
            'multipleFiles' => false,
            'maxFiles' => '',
            'calculationFormula' => '',
            'calculationRounding' => '',
            'enableCalculation' => '',
            'disableQuantity' => false,
            'displayAllCategories' => false,
            'useRichTextEditor' => false,
            'fields' => '',
        ]
    );

    $baseField_list = \array_merge(
        $baseFieldComponent,
        [
            'id' => 129,
            'type' => 'list',
            'inputs' => null,
            'description' => '',
            'inputMaskIsCustom' => '',
            'autocompleteAttribute' => '',
            'choices' => '',
            'maxRows' => 0,
            'enableColumns' => false,
            'fields' => '',
        ]
    );

    $baseField_multi_choice_component = \array_merge(
        $baseFieldComponent,
        [
            'type' => 'multi_choice',
            'choices' => [
                [
                    'text' => 'First Choice',
                    'value' => 'First Choice',
                    'isSelected' => false,
                    'price' => '',
                    'key' => 'x4pqbs0i4mf5pkq32',
                ],
                [
                    'text' => 'Second Choice',
                    'value' => 'Second Choice',
                    'isSelected' => false,
                    'price' => '',
                    'key' => '0daf1sb7qmf5pkq32',
                ],
                [
                    'text' => 'Third Choice',
                    'value' => 'Third Choice',
                    'isSelected' => false,
                    'price' => '',
                    'key' => 'u5ku9r7xomf5pkq32',
                ],
            ],
            'selectAllText' => 'Select All',
            'validateState' => true,
            'inputs' => [
                [
                    'id' => '130.1',
                    'label' => 'First Choice',
                    'name' => '',
                    'key' => 'x4pqbs0i4mf5pkq32',
                ],
                [
                    'id' => '130.2',
                    'label' => 'Second Choice',
                    'name' => '',
                    'key' => '0daf1sb7qmf5pkq32',
                ],
                [
                    'id' => '130.3',
                    'label' => 'Third Choice',
                    'name' => '',
                    'key' => 'u5ku9r7xomf5pkq32',
                ],
            ],
            'description' => '',
            'inputMaskIsCustom' => '',
            'autocompleteAttribute' => '',
            'enableOtherChoice' => '',
            'enablePrice' => '',
            'fields' => '',
        ]
    );

    $baseField_multi_choice_radio = \array_merge(
        $baseField_multi_choice_component,
        [
            'id' => 130,
            'inputType' => 'radio',
            'layoutGridColumnSpan' => '',
            'choiceLimit' => 'single',
        ]
    );

    $baseField_multi_choice_checkbox_component = \array_merge(
        $baseField_multi_choice_component,
        [
            'inputType' => 'checkbox',
            'layoutGridColumnSpan' => 12,
            'checkboxLabel' => '',
        ]
    );

    $baseField_multi_choice_checkbox_unlimited = \array_merge(
        $baseField_multi_choice_checkbox_component,
        [
            'id' => 142,
            'choiceLimit' => 'unlimited',
        ]
    );

    $baseField_multi_choice_checkbox_exactly = \array_merge(
        $baseField_multi_choice_checkbox_component,
        [
            'id' => 143,
            'choiceLimit' => 'exactly',
            'choiceLimitNumber' => '2',
        ]
    );

    $baseField_multi_choice_checkbox_range = \array_merge(
        $baseField_multi_choice_checkbox_component,
        [
            'id' => 144,
            'choiceLimit' => 'range',
            'choiceLimitMin' => '2',
            'choiceLimitMax' => '3',
        ]
    );

    $baseField_number = \array_merge(
        $baseFieldComponent,
        [
            'id' => 131,
            'type' => 'number',
            'inputs' => null,
            'numberFormat' => 'decimal_dot',
            'description' => '',
            'inputMaskIsCustom' => '',
            'autocompleteAttribute' => '',
            'choices' => '',
            'enableCalculation' => false,
            'rangeMin' => '',
            'rangeMax' => '',
            'calculationFormula' => '',
            'fields' => '',
        ]
    );

    $baseField_phone = \array_merge(
        $baseFieldComponent,
        [
            'id' => 132,
            'type' => 'phone',
            'inputs' => null,
            'phoneFormat' => 'international',
            'autocompleteAttribute' => 'tel',
            'description' => '',
            'inputMaskIsCustom' => '',
            'choices' => '',
            'fields' => '',
        ]
    );

    $baseField_poll_radio = \array_merge(
        $baseFieldComponent,
        [
            'id' => 133,
            'type' => 'poll',
            'inputs' => null,
            'poll_field_type' => 'radio',
            'inputType' => 'radio',
            'enableChoiceValue' => true,
            'enablePrice' => false,
            'enableRandomizeChoices' => false,
            'choices' => [
                [
                    'text' => 'First Choice',
                    'value' => 'gpoll1134008a97',
                    'isSelected' => false,
                    'price' => '',
                ],
                [
                    'text' => 'Second Choice',
                    'value' => 'gpoll115a3aa7a5',
                    'isSelected' => false,
                    'price' => '',
                ],
                [
                    'text' => 'Third Choice',
                    'value' => 'gpoll117942e6ad',
                    'isSelected' => false,
                    'price' => '',
                ],
            ],
            'description' => '',
            'inputMaskIsCustom' => '',
            'autocompleteAttribute' => '',
            'enableOtherChoice' => '',
            'fields' => '',
        ]
    );

    $baseField_poll_checkbox = \array_merge(
        $baseFieldComponent,
        [
            'id' => 152,
            'type' => 'poll',
            'inputs' => [
                [
                    'id' => '152.1',
                    'label' => 'First Choice',
                    'name' => '',
                ],
                [
                    'id' => '152.2',
                    'label' => 'Second Choice',
                    'name' => '',
                ],
                [
                    'id' => '152.3',
                    'label' => 'Third Choice',
                    'name' => '',
                ],
            ],
            'poll_field_type' => 'checkbox',
            'inputType' => 'checkbox',
            'enableChoiceValue' => true,
            'enablePrice' => false,
            'enableRandomizeChoices' => false,
            'choices' => [
                [
                    'text' => 'First Choice',
                    'value' => 'gpoll4e20a6125',
                    'isSelected' => false,
                    'price' => '',
                ],
                [
                    'text' => 'Second Choice',
                    'value' => 'gpoll4a521ea88',
                    'isSelected' => false,
                    'price' => '',
                ],
                [
                    'text' => 'Third Choice',
                    'value' => 'gpoll407db78db',
                    'isSelected' => false,
                    'price' => '',
                ],
            ],
            'description' => '',
            'inputMaskIsCustom' => false,
            'autocompleteAttribute' => '',
            'enableOtherChoice' => '',
            'multipleFiles' => false,
            'maxFiles' => '',
            'calculationFormula' => '',
            'calculationRounding' => '',
            'enableCalculation' => '',
            'disableQuantity' => false,
            'displayAllCategories' => false,
            'useRichTextEditor' => false,
            'validateState' => true,
            'checkboxLabel' => '',
            'fields' => '',
            'selectAllText' => 'Select All',
        ]
    );

    $baseField_poll_select = \array_merge(
        $baseFieldComponent,
        [
            'id' => 153,
            'type' => 'poll',
            'inputs' => null,
            'poll_field_type' => 'select',
            'inputType' => 'select',
            'enableChoiceValue' => true,
            'enablePrice' => false,
            'enableRandomizeChoices' => false,
            'choices' => [
                [
                    'text' => 'First Choice',
                    'value' => 'gpoll351ade716',
                    'isSelected' => false,
                    'price' => '',
                ],
                [
                    'text' => 'Second Choice',
                    'value' => 'gpoll34d2a28b4',
                    'isSelected' => false,
                    'price' => '',
                ],
                [
                    'text' => 'Third Choice',
                    'value' => 'gpoll39f0f946b',
                    'isSelected' => false,
                    'price' => '',
                ],
            ],
            'description' => '',
            'inputMaskIsCustom' => false,
            'autocompleteAttribute' => '',
            'enableOtherChoice' => '',
            'enableEnhancedUI' => false,
            'multipleFiles' => false,
            'maxFiles' => '',
            'calculationFormula' => '',
            'calculationRounding' => '',
            'enableCalculation' => '',
            'disableQuantity' => false,
            'displayAllCategories' => false,
            'useRichTextEditor' => false,
            'validateState' => true,
            'checkboxLabel' => '',
            'fields' => '',
        ]
    );

    $baseField_post_custom_field_component = \array_merge(
        $baseFieldComponent,
        [
            'type' => 'post_custom_field',
            'postCustomFieldName' => 'page_nav_alt_name',
            'description' => '',
            'inputMaskIsCustom' => false,
            'enablePasswordInput' => '',
            'postFeaturedImage' => false,
            'multipleFiles' => false,
            'maxFiles' => '',
            'calculationFormula' => '',
            'calculationRounding' => '',
            'disableQuantity' => false,
            'displayAllCategories' => false,
            'useRichTextEditor' => false,
            'checkboxLabel' => '',
            'validateState' => true,
            'storageType' => 'json',
            'numberFormat' => 'decimal_dot',
            'layoutGridColumnSpan' => 12,
            'fields' => '',
        ]
    );

    $baseField_post_custom_field_text = \array_merge(
        $baseField_post_custom_field_component,
        [
            'id' => 134,
            'inputType' => 'text',
            'choices' => '',
            'inputs' => null,
            'autocompleteAttribute' => '',
            'enableCalculation' => '',
        ]
    );

    $baseField_post_custom_field_textarea = \array_merge(
        $baseField_post_custom_field_component,
        [
            'id' => 173,
            'inputType' => 'textarea',
            'inputs' => null,
            'autocompleteAttribute' => '',
            'choices' => null,
            'enableCalculation' => '',
        ]
    );

    $baseField_post_custom_field_select = \array_merge(
        $baseField_post_custom_field_component,
        [
            'id' => 174,
            'inputType' => 'select',
            'choices' => [
                [
                    'text' => 'First Choice',
                    'value' => 'First Choice',
                    'isSelected' => false,
                    'price' => '',
                ],
                [
                    'text' => 'Second Choice',
                    'value' => 'Second Choice',
                    'isSelected' => false,
                    'price' => '',
                ],
                [
                    'text' => 'Third Choice',
                    'value' => 'Third Choice',
                    'isSelected' => false,
                    'price' => '',
                ],
            ],
            'enableEnhancedUI' => false,
            'inputs' => null,
            'autocompleteAttribute' => '',
            'enableCalculation' => '',
        ]
    );

    $baseField_post_custom_field_multiselect = \array_merge(
        $baseField_post_custom_field_component,
        [
            'id' => 175,
            'inputType' => 'multiselect',
            'choices' => [
                [
                    'text' => 'First Choice',
                    'value' => 'First Choice',
                    'isSelected' => false,
                    'price' => '',
                ],
                [
                    'text' => 'Second Choice',
                    'value' => 'Second Choice',
                    'isSelected' => false,
                    'price' => '',
                ],
                [
                    'text' => 'Third Choice',
                    'value' => 'Third Choice',
                    'isSelected' => false,
                    'price' => '',
                ],
            ],
            'enableEnhancedUI' => false,
            'inputs' => null,
            'autocompleteAttribute' => '',
            'enableCalculation' => '',
        ]
    );

    $baseField_post_custom_field_number = \array_merge(
        $baseField_post_custom_field_component,
        [
            'id' => 176,
            'inputType' => 'number',
            'enableCalculation' => false,
            'rangeMin' => '',
            'rangeMax' => '',
            'inputs' => null,
            'autocompleteAttribute' => '',
            'choices' => null,
        ]
    );

    $baseField_post_custom_field_checkbox = \array_merge(
        $baseField_post_custom_field_component,
        [
            'id' => 177,
            'inputType' => 'checkbox',
            'inputs' => [
                [
                    'id' => '177.1',
                    'label' => 'First Choice',
                    'name' => '',
                ],
                [
                    'id' => '177.2',
                    'label' => 'Second Choice',
                    'name' => '',
                ],
                [
                    'id' => '177.3',
                    'label' => 'Third Choice',
                    'name' => '',
                ],
            ],
            'choices' => [
                [
                    'text' => 'First Choice',
                    'value' => 'First Choice',
                    'isSelected' => false,
                    'price' => '',
                ],
                [
                    'text' => 'Second Choice',
                    'value' => 'Second Choice',
                    'isSelected' => false,
                    'price' => '',
                ],
                [
                    'text' => 'Third Choice',
                    'value' => 'Third Choice',
                    'isSelected' => false,
                    'price' => '',
                ],
            ],
            'selectAllText' => 'Select All',
            'autocompleteAttribute' => '',
            'enableCalculation' => '',
        ]
    );

    $baseField_post_custom_field_radio = \array_merge(
        $baseField_post_custom_field_component,
        [
            'id' => 178,
            'inputType' => 'radio',
            'choices' => [
                [
                    'text' => 'First Choice',
                    'value' => 'First Choice',
                    'isSelected' => false,
                    'price' => '',
                ],
                [
                    'text' => 'Second Choice',
                    'value' => 'Second Choice',
                    'isSelected' => false,
                    'price' => '',
                ],
                [
                    'text' => 'Third Choice',
                    'value' => 'Third Choice',
                    'isSelected' => false,
                    'price' => '',
                ],
            ],
            'inputs' => null,
            'autocompleteAttribute' => '',
            'enableCalculation' => '',
        ]
    );

    $baseField_post_custom_field_hidden = \array_merge(
        $baseField_post_custom_field_component,
        [
            'id' => 179,
            'inputType' => 'hidden',
            'inputs' => null,
            'autocompleteAttribute' => '',
            'choices' => null,
            'enableCalculation' => '',
        ]
    );

    $baseField_post_custom_field_date = \array_merge(
        $baseField_post_custom_field_component,
        [
            'id' => 180,
            'inputType' => 'date',
            'dateFormat' => 'mdy',
            'dateFormatPlacement' => 'below',
            'calendarIconUrl' => '',
            'dateType' => 'datepicker',
            'calendarIconType' => 'none',
            'inputs' => null,
            'autocompleteAttribute' => '',
            'choices' => null,
            'enableCalculation' => '',
        ]
    );

    $baseField_post_custom_field_time = \array_merge(
        $baseField_post_custom_field_component,
        [
            'id' => 181,
            'inputType' => 'time',
            'inputs' => [
                [
                    'id' => '181.1',
                    'label' => 'Hour',
                    'name' => '',
                ],
                [
                    'id' => '181.2',
                    'label' => 'Minute',
                    'name' => '',
                ],
                [
                    'id' => '181.3',
                    'label' => 'AM\/PM',
                    'name' => '',
                ],
            ],
            'timeFormat' => '12',
            'autocompleteAttribute' => '',
            'choices' => null,
            'enableCalculation' => '',
        ]
    );

    $baseField_post_custom_field_website = \array_merge(
        $baseField_post_custom_field_component,
        [
            'id' => 182,
            'inputType' => 'website',
            'placeholder' => 'https:\/\/',
            'autocompleteAttribute' => 'url',
            'inputs' => null,
            'choices' => null,
            'enableCalculation' => '',
        ]
    );

    $baseField_post_custom_field_email = \array_merge(
        $baseField_post_custom_field_component,
        [
            'id' => 183,
            'inputType' => 'email',
            'inputs' => null,
            'autocompleteAttribute' => 'email',
            'choices' => null,
            'enableCalculation' => '',
        ]
    );

    $baseField_post_custom_field_fileupload = \array_merge(
        $baseField_post_custom_field_component,
        [
            'id' => 184,
            'inputType' => 'fileupload',
            'maxFileSize' => '',
            'allowedExtensions' => '',
            'inputs' => null,
            'autocompleteAttribute' => '',
            'choices' => null,
            'enableCalculation' => '',
        ]
    );

    $baseField_post_custom_field_list = \array_merge(
        $baseField_post_custom_field_component,
        [
            'id' => 185,
            'inputType' => 'list',
            'maxRows' => 0,
            'enableColumns' => false,
            'inputs' => null,
            'autocompleteAttribute' => '',
            'choices' => null,
            'enableCalculation' => '',
        ]
    );

    $baseField_post_custom_field_phone = \array_merge(
        $baseField_post_custom_field_component,
        [
            'id' => 186,
            'inputType' => 'phone',
            'autocompleteAttribute' => 'tel',
            'phoneFormat' => 'international',
            'inputs' => null,
            'choices' => null,
            'enableCalculation' => '',
        ]
    );

    $baseField_product = \array_merge(
        $baseFieldComponent,
        [
            'id' => 135,
            'type' => 'product',
            'inputs' => [
                [
                    'id' => '135.1',
                    'label' => 'Name',
                    'name' => '',
                ],
                [
                    'id' => '135.2',
                    'label' => 'Price',
                    'name' => '',
                ],
                [
                    'id' => '135.3',
                    'label' => 'Quantity',
                    'name' => '',
                ],
            ],
            'inputType' => 'singleproduct',
            'enablePrice' => null,
            'description' => '',
            'inputMaskIsCustom' => false,
            'autocompleteAttribute' => '',
            'choices' => null,
            'disableQuantity' => false,
            'basePrice' => '$42.13',
            'multipleFiles' => false,
            'maxFiles' => '',
            'calculationFormula' => '',
            'calculationRounding' => '',
            'enableCalculation' => '',
            'displayAllCategories' => false,
            'useRichTextEditor' => false,
            'checkboxLabel' => '',
            'errors' => [],
            'fields' => '',
            'validateState' => true,
        ]
    );

    $baseField_survey = \array_merge(
        $baseFieldComponent,
        [
            'type' => 'survey',
            'inputs' => null,
            'enableChoiceValue' => true,
            'enablePrice' => false,
            'gsurveyLikertEnableMultipleRows' => false,
            'gsurveyLikertEnableScoring' => false,
            'choices' => [
                [
                    'text' => 'Strongly disagree',
                    'value' => 'glikertcol000000000a',
                    'isSelected' => false,
                    'score' => 1,
                ],
                [
                    'text' => 'Disagree',
                    'value' => 'glikertcol000000000b',
                    'isSelected' => false,
                    'score' => 2,
                ],
                [
                    'text' => 'Neutral',
                    'value' => 'glikertcol000000000c',
                    'isSelected' => false,
                    'score' => 3,
                ],
                [
                    'text' => 'Agree',
                    'value' => 'glikertcol000000000d',
                    'isSelected' => false,
                    'score' => 4,
                ],
                [
                    'text' => 'Strongly agree',
                    'value' => 'glikertcol000000000e',
                    'isSelected' => false,
                    'score' => 5,
                ],
            ],
            'gsurveyLikertRows' => [
                [
                    'text' => 'First row',
                    'value' => 'glikertrow0000000a',
                ],
                [
                    'text' => 'Second row',
                    'value' => 'glikertrow0000000b',
                ],
                [
                    'text' => 'Third row',
                    'value' => 'glikertrow0000000c',
                ],
                [
                    'text' => 'Fourth row',
                    'value' => 'glikertrow0000000d',
                ],
                [
                    'text' => 'Fifth row',
                    'value' => 'glikertrow0000000e',
                ],
            ],
            'description' => '',
            'autocompleteAttribute' => '',
            'fields' => '',
        ]
    );

    $baseField_survey_likert = \array_merge(
        $baseField_survey,
        [
            'id' => 136,
            'choices' => [
                [
                    'text' => 'Strongly disagree',
                    'value' => 'glikertcol149d27f288',
                    'isSelected' => false,
                    'score' => 1,
                ],
                [
                    'text' => 'Disagree',
                    'value' => 'glikertcol1445fb1ab3',
                    'isSelected' => false,
                    'score' => 2,
                ],
                [
                    'text' => 'Neutral',
                    'value' => 'glikertcol14ffe7dd74',
                    'isSelected' => false,
                    'score' => 3,
                ],
                [
                    'text' => 'Agree',
                    'value' => 'glikertcol14db7dd763',
                    'isSelected' => false,
                    'score' => 4,
                ],
                [
                    'text' => 'Strongly agree',
                    'value' => 'glikertcol1470872cf7',
                    'isSelected' => false,
                    'score' => 5,
                ],
            ],
            'inputType' => 'likert',
            'inputMaskIsCustom' => '',
        ]
    );

    $baseField_survey_rating = \array_merge(
        $baseField_survey,
        [
            'id' => 145,
            'choices' => [
                [
                    'text' => 'Terrible',
                    'value' => 'grating149d27f288',
                    'isSelected' => false,
                    'price' => '',
                ],
                [
                    'text' => 'Not so great',
                    'value' => 'grating1445fb1ab3',
                    'isSelected' => false,
                    'price' => '',
                ],
                [
                    'text' => 'Neutral',
                    'value' => 'grating14ffe7dd74',
                    'isSelected' => false,
                    'price' => '',
                ],
                [
                    'text' => 'Pretty good',
                    'value' => 'grating14db7dd763',
                    'isSelected' => false,
                    'price' => '',
                ],
                [
                    'text' => 'Excellent',
                    'value' => 'grating1470872cf7',
                    'isSelected' => false,
                    'price' => '',
                ],
            ],
            'inputType' => 'rating',
            'inputMaskIsCustom' => '',
        ]
    );

    $baseField_survey_checkbox = \array_merge(
        $baseField_survey,
        [
            'id' => 160,
            'inputs' => [
                [
                    'id' => '1.1',
                    'label' => 'First Choice',
                    'name' => '',
                ],
                [
                    'id' => '1.2',
                    'label' => 'Second Choice',
                    'name' => '',
                ],
                [
                    'id' => '1.3',
                    'label' => 'Third Choice',
                    'name' => '',
                ],
            ],
            'choices' => [
                [
                    'text' => 'First Choice',
                    'value' => 'gsurvey1d0c0c0e2',
                    'isSelected' => false,
                    'price' => '',
                ],
                [
                    'text' => 'Second Choice',
                    'value' => 'gsurvey1e9a2790f',
                    'isSelected' => false,
                    'price' => '',
                ],
                [
                    'text' => 'Third Choice',
                    'value' => 'gsurvey1e4a9b30e',
                    'isSelected' => false,
                    'price' => '',
                ],
            ],
            'inputType' => 'checkbox',
            'inputMaskIsCustom' => false,
            'multipleFiles' => false,
            'maxFiles' => '',
            'calculationFormula' => '',
            'calculationRounding' => '',
            'enableCalculation' => '',
            'disableQuantity' => false,
            'displayAllCategories' => false,
            'useRichTextEditor' => false,
            'validateState' => true,
            'checkboxLabel' => '',
            'selectAllText' => 'Select All',
        ]
    );

    $baseField_survey_radio = \array_merge(
        $baseField_survey,
        [
            'id' => 161,
            'choices' => [
                [
                    'text' => 'First Choice',
                    'value' => 'gsurvey1bbebdcbb',
                    'isSelected' => false,
                    'price' => '',
                ],
                [
                    'text' => 'Second Choice',
                    'value' => 'gsurvey1a5d08801',
                    'isSelected' => false,
                    'price' => '',
                ],
                [
                    'text' => 'Third Choice',
                    'value' => 'gsurvey118333736',
                    'isSelected' => false,
                    'price' => '',
                ],
            ],
            'inputType' => 'radio',
            'inputMaskIsCustom' => false,
            'multipleFiles' => false,
            'maxFiles' => '',
            'calculationFormula' => '',
            'calculationRounding' => '',
            'enableCalculation' => '',
            'disableQuantity' => false,
            'displayAllCategories' => false,
            'useRichTextEditor' => false,
            'validateState' => true,
            'checkboxLabel' => '',
        ]
    );

    $baseField_survey_select = \array_merge(
        $baseField_survey,
        [
            'id' => 162,
            'choices' => [
                [
                    'text' => 'First Choice',
                    'value' => 'gsurvey1e2a5106f',
                    'isSelected' => false,
                    'price' => '',
                ],
                [
                    'text' => 'Second Choice',
                    'value' => 'gsurvey1b607a637',
                    'isSelected' => false,
                    'price' => '',
                ],
                [
                    'text' => 'Third Choice',
                    'value' => 'gsurvey19b9eb86b',
                    'isSelected' => false,
                    'price' => '',
                ],
            ],
            'inputType' => 'select',
            'inputMaskIsCustom' => false,
            'enableEnhancedUI' => false,
            'multipleFiles' => false,
            'maxFiles' => '',
            'calculationFormula' => '',
            'calculationRounding' => '',
            'enableCalculation' => '',
            'disableQuantity' => false,
            'displayAllCategories' => false,
            'useRichTextEditor' => false,
            'validateState' => true,
            'checkboxLabel' => '',
        ]
    );

    $baseField_survey_text = \array_merge(
        $baseField_survey,
        [
            'id' => 163,
            'choices' => null,
            'inputType' => 'text',
            'inputMaskIsCustom' => false,
            'multipleFiles' => false,
            'maxFiles' => '',
            'calculationFormula' => '',
            'calculationRounding' => '',
            'enableCalculation' => '',
            'disableQuantity' => false,
            'displayAllCategories' => false,
            'useRichTextEditor' => false,
            'checkboxLabel' => '',
        ]
    );

    $baseField_survey_textarea = \array_merge(
        $baseField_survey,
        [
            'id' => 164,
            'choices' => null,
            'inputType' => 'textarea',
            'inputMaskIsCustom' => false,
            'multipleFiles' => false,
            'maxFiles' => '',
            'calculationFormula' => '',
            'calculationRounding' => '',
            'enableCalculation' => '',
            'disableQuantity' => false,
            'displayAllCategories' => false,
            'useRichTextEditor' => false,
            'checkboxLabel' => '',
        ]
    );

    $baseField_radio = \array_merge(
        $baseFieldComponent,
        [
            'id' => 137,
            'type' => 'radio',
            'validateState' => true,
            'choices' => [
                [
                    'text' => 'First Choice',
                    'value' => 'First Choice',
                    'isSelected' => false,
                    'price' => '',
                ],
                [
                    'text' => 'Second Choice',
                    'value' => 'Second Choice',
                    'isSelected' => false,
                    'price' => '',
                ],
                [
                    'text' => 'Third Choice',
                    'value' => 'Third Choice',
                    'isSelected' => false,
                    'price' => '',
                ],
            ],
            'description' => '',
            'inputMaskIsCustom' => '',
            'autocompleteAttribute' => '',
            'enableOtherChoice' => '',
            'enablePrice' => '',
            'fields' => '',
            'enableChoiceValue' => false,
        ]
    );

    $baseField_fileupload = \array_merge(
        $baseFieldComponent,
        [
            'id' => 138,
            'type' => 'fileupload',
            'inputs' => null,
            'description' => '',
            'inputMaskIsCustom' => '',
            'autocompleteAttribute' => '',
            'choices' => '',
            'maxFileSize' => '',
            'maxFiles' => '',
            'multipleFiles' => false,
            'allowedExtensions' => '',
            'fields' => '',
        ]
    );

    $baseField_checkbox = \array_merge(
        $baseFieldComponent,
        [
            'id' => 139,
            'type' => 'checkbox',
            'choices' => [
                [
                    'text' => 'First Choice',
                    'value' => 'First Choice',
                    'isSelected' => false,
                    'price' => '',
                ],
                [
                    'text' => 'Second Choice',
                    'value' => 'Second Choice',
                    'isSelected' => false,
                    'price' => '',
                ],
                [
                    'text' => 'Third Choice',
                    'value' => 'Third Choice',
                    'isSelected' => false,
                    'price' => '',
                ],
            ],
            'validateState' => true,
            'inputs' => [
                [
                    'id' => '139.1',
                    'label' => 'First Choice',
                    'name' => '',
                ],
                [
                    'id' => '139.2',
                    'label' => 'Second Choice',
                    'name' => '',
                ],
                [
                    'id' => '139.3',
                    'label' => 'Third Choice',
                    'name' => '',
                ],
            ],
            'description' => '',
            'inputMaskIsCustom' => '',
            'autocompleteAttribute' => '',
            'enableSelectAll' => '',
            'choiceLimit' => '',
            'enablePrice' => '',
            'fields' => '',
            'selectAllText' => 'Select All',
        ]
    );

    $baseField_select = \array_merge(
        $baseFieldComponent,
        [
            'id' => 140,
            'type' => 'select',
            'validateState' => true,
            'inputs' => null,
            'choices' => [
                [
                    'text' => 'First Choice',
                    'value' => 'First Choice',
                    'isSelected' => false,
                    'price' => '',
                ],
                [
                    'text' => 'Second Choice',
                    'value' => 'Second Choice',
                    'isSelected' => false,
                    'price' => '',
                ],
                [
                    'text' => 'Third Choice',
                    'value' => 'Third Choice',
                    'isSelected' => false,
                    'price' => '',
                ],
            ],
            'description' => '',
            'inputMaskIsCustom' => '',
            'autocompleteAttribute' => '',
            'enableEnhancedUI' => false,
            'enablePrice' => '',
            'fields' => '',
        ]
    );

    $baseField_name = \array_merge(
        $baseFieldComponent,
        [
            'id' => 141,
            'type' => 'name',
            'nameFormat' => 'advanced',
            'inputs' => [
                [
                    'id' => '141.2',
                    'label' => 'Prefix',
                    'name' => '',
                    'autocompleteAttribute' => 'honorific-prefix',
                    'choices' => [
                        [
                            'text' => 'Dr.',
                            'value' => 'Dr.',
                        ],
                        [
                            'text' => 'Miss',
                            'value' => 'Miss',
                        ],
                        [
                            'text' => 'Mr.',
                            'value' => 'Mr.',
                        ],
                        [
                            'text' => 'Mrs.',
                            'value' => 'Mrs.',
                        ],
                        [
                            'text' => 'Ms.',
                            'value' => 'Ms.',
                        ],
                        [
                            'text' => 'Mx.',
                            'value' => 'Mx.',
                        ],
                        [
                            'text' => 'Prof.',
                            'value' => 'Prof.',
                        ],
                        [
                            'text' => 'Rev.',
                            'value' => 'Rev.',
                        ],
                    ],
                    'isHidden' => true,
                    'inputType' => 'radio',
                ],
                [
                    'id' => '141.3',
                    'label' => 'First',
                    'name' => '',
                    'autocompleteAttribute' => 'given-name',
                ],
                [
                    'id' => '141.4',
                    'label' => 'Middle',
                    'name' => '',
                    'autocompleteAttribute' => 'additional-name',
                    'isHidden' => true,
                ],
                [
                    'id' => '141.6',
                    'label' => 'Last',
                    'name' => '',
                    'autocompleteAttribute' => 'family-name',
                ],
                [
                    'id' => '141.8',
                    'label' => 'Suffix',
                    'name' => '',
                    'autocompleteAttribute' => 'honorific-suffix',
                    'isHidden' => true,
                ],
            ],
            'description' => '',
            'inputMaskIsCustom' => '',
            'autocompleteAttribute' => '',
            'choices' => '',
            'fields' => '',
        ]
    );

    $baseField_quiz = \array_merge(
        $baseFieldComponent,
        [
            'id' => 146,
            'type' => 'quiz',
            'gquizFieldType' => 'radio',
            'inputType' => 'radio',
            'enableChoiceValue' => true,
            'gquizEnableRandomizeQuizChoices' => false,
            'gquizShowAnswerExplanation' => false,
            'gquizAnswerExplanation' => '',
            'gquizWeightedScoreEnabled' => false,
            'choices' => [
                [
                    'text' => 'First Choice',
                    'value' => 'gquiz0001',
                    'isSelected' => false,
                    'price' => '',
                    'gquizIsCorrect' => false,
                    'gquizWeight' => '0',
                ],
                [
                    'text' => 'Second Choice',
                    'value' => 'gquiz0002',
                    'isSelected' => false,
                    'price' => '',
                    'gquizIsCorrect' => true,
                    'gquizWeight' => '0',
                ],
                [
                    'text' => 'Third Choice',
                    'value' => 'gquiz0003',
                    'isSelected' => false,
                    'price' => '',
                    'gquizIsCorrect' => false,
                    'gquizWeight' => '0',
                ],
            ],
        ]
    );

    $baseField_textarea = \array_merge(
        $baseFieldComponent,
        [
            'id' => 147,
            'type' => 'textarea',
            'inputs' => null,
            'description' => '',
            'inputMaskIsCustom' => false,
            'autocompleteAttribute' => '',
            'choices' => '',
            'useRichTextEditor' => false,
            'multipleFiles' => false,
            'maxFiles' => '',
            'calculationFormula' => '',
            'calculationRounding' => '',
            'enableCalculation' => '',
            'disableQuantity' => false,
            'displayAllCategories' => false,
            'errors' => [],
            'fields' => '',
        ]
    );

    $baseField_password = \array_merge(
        $baseFieldComponent,
        [
            'id' => 148,
            'type' => 'password',
            'inputs' => [
                [
                    'id' => '148',
                    'label' => 'Enter Password',
                    'name' => '',
                ],
                [
                    'id' => '148.2',
                    'label' => 'Confirm Password',
                    'name' => '',
                ],
            ],
            'displayOnly' => true,
            'description' => '',
            'inputMaskIsCustom' => false,
            'autocompleteAttribute' => '',
            'choices' => '',
            'passwordStrengthEnabled' => '',
            'passwordVisibilityEnabled' => '',
            'multipleFiles' => false,
            'maxFiles' => '',
            'calculationFormula' => '',
            'calculationRounding' => '',
            'enableCalculation' => '',
            'disableQuantity' => false,
            'displayAllCategories' => false,
            'useRichTextEditor' => false,
            'fields' => '',
        ]
    );

    $baseField_time = \array_merge(
        $baseFieldComponent,
        [
            'id' => 149,
            'type' => 'time',
            'inputs' => [
                [
                    'id' => '1.1',
                    'label' => 'Hour',
                    'name' => '',
                ],
                [
                    'id' => '1.2',
                    'label' => 'Minute',
                    'name' => '',
                ],
                [
                    'id' => '1.3',
                    'label' => 'AM\/PM',
                    'name' => '',
                ],
            ],
            'description' => '',
            'inputMaskIsCustom' => '',
            'autocompleteAttribute' => '',
            'choices' => '',
            'timeFormat' => '12',
            'fields' => '',
        ]
    );

    $baseField_multiselect = \array_merge(
        $baseFieldComponent,
        [
            'id' => 150,
            'type' => 'multiselect',
            'storageType' => 'json',
            'inputs' => null,
            'choices' => [
                [
                    'text' => 'First Choice',
                    'value' => 'First Choice',
                    'isSelected' => false,
                    'price' => '',
                ],
                [
                    'text' => 'Second Choice',
                    'value' => 'Second Choice',
                    'isSelected' => false,
                    'price' => '',
                ],
                [
                    'text' => 'Third Choice',
                    'value' => 'Third Choice',
                    'isSelected' => false,
                    'price' => '',
                ],
            ],
            'description' => '',
            'inputMaskIsCustom' => false,
            'autocompleteAttribute' => '',
            'enableEnhancedUI' => false,
            'multiSelectSize' => '',
            'enablePrice' => '',
            'multipleFiles' => false,
            'maxFiles' => '',
            'calculationFormula' => '',
            'calculationRounding' => '',
            'enableCalculation' => '',
            'disableQuantity' => false,
            'displayAllCategories' => false,
            'useRichTextEditor' => false,
            'errors' => [],
            'fields' => '',
        ]
    );

    $baseField_post_title = \array_merge(
        $baseFieldComponent,
        [
            'id' => 154,
            'type' => 'post_title',
            'inputs' => null,
            'inputMaskIsCustom' => false,
            'inputName' => 'qux',
            'autocompleteAttribute' => '',
            'choices' => '',
            'multipleFiles' => false,
            'maxFiles' => '',
            'calculationFormula' => '',
            'calculationRounding' => '',
            'enableCalculation' => '',
            'disableQuantity' => false,
            'displayAllCategories' => false,
            'useRichTextEditor' => false,
            'postFeaturedImage' => false,
            'fields' => '',
        ]
    );

    $baseField_post_content = \array_merge(
        $baseFieldComponent,
        [
            'id' => 155,
            'type' => 'post_content',
            'inputs' => null,
            'inputMaskIsCustom' => false,
            'inputName' => 'qux',
            'autocompleteAttribute' => '',
            'choices' => '',
            'useRichTextEditor' => false,
            'multipleFiles' => false,
            'maxFiles' => '',
            'calculationFormula' => '',
            'calculationRounding' => '',
            'enableCalculation' => '',
            'disableQuantity' => false,
            'displayAllCategories' => false,
            'postFeaturedImage' => false,
            'fields' => '',
        ]
    );

    $baseField_post_excerpt = \array_merge(
        $baseFieldComponent,
        [
            'id' => 156,
            'type' => 'post_excerpt',
            'inputs' => null,
            'inputMaskIsCustom' => false,
            'inputName' => 'qux',
            'autocompleteAttribute' => '',
            'choices' => '',
            'multipleFiles' => false,
            'maxFiles' => '',
            'calculationFormula' => '',
            'calculationRounding' => '',
            'enableCalculation' => '',
            'disableQuantity' => false,
            'displayAllCategories' => false,
            'useRichTextEditor' => false,
            'postFeaturedImage' => false,
            'fields' => '',
        ]
    );

    $baseField_post_tags_component = \array_merge(
        $baseFieldComponent,
        [
            'type' => 'post_tags',
            'inputs' => null,
            'description' => '',
            'inputMaskIsCustom' => false,
            'autocompleteAttribute' => '',
            'choices' => [
                [
                    'text' => 'First Choice',
                    'value' => 'First Choice',
                    'isSelected' => false,
                    'price' => '',
                ],
                [
                    'text' => 'Second Choice',
                    'value' => 'Second Choice',
                    'isSelected' => false,
                    'price' => '',
                ],
                [
                    'text' => 'Third Choice',
                    'value' => 'Third Choice',
                    'isSelected' => false,
                    'price' => '',
                ],
            ],
            'multipleFiles' => false,
            'maxFiles' => '',
            'calculationFormula' => '',
            'calculationRounding' => '',
            'enableCalculation' => '',
            'disableQuantity' => false,
            'displayAllCategories' => false,
            'useRichTextEditor' => false,
            'errors' => [],
            'postFeaturedImage' => false,
            'fields' => '',
        ]
    );

    $baseField_post_tags_empty = \array_merge(
        $baseField_post_tags_component,
        [
            'id' => 157,
            'inputType' => '',
            'choices' => '',
        ]
    );

    $baseField_post_tags_not_set = \array_merge(
        $baseField_post_tags_component,
        [
            'id' => 167,
            'choices' => '',
        ]
    );

    $baseField_post_tags_text = \array_merge(
        $baseField_post_tags_component,
        [
            'id' => 168,
            'inputType' => 'text',
            'choices' => '',
        ]
    );

    $baseField_post_tags_select = \array_merge(
        $baseField_post_tags_component,
        [
            'id' => 169,
            'inputType' => 'select',
        ]
    );

    $baseField_post_tags_checkbox = \array_merge(
        $baseField_post_tags_component,
        [
            'id' => 170,
            'inputType' => 'checkbox',
        ]
    );

    $baseField_post_tags_radio = \array_merge(
        $baseField_post_tags_component,
        [
            'id' => 171,
            'inputType' => 'radio',
        ]
    );

    $baseField_post_tags_multiselect = \array_merge(
        $baseField_post_tags_component,
        [
            'id' => 172,
            'inputType' => 'multiselect',
        ]
    );

    $baseField_post_category_component = \array_merge(
        $baseFieldComponent,
        [
            'type' => 'post_category',
            'inputs' => null,
            'choices' => [],
            'displayAllCategories' => true,
            'inputType' => 'select',
            'description' => '',
            'inputMaskIsCustom' => false,
            'autocompleteAttribute' => '',
            'enableEnhancedUI' => false,
            'categoryInitialItemEnabled' => '',
            'enablePrice' => '',
            'multipleFiles' => false,
            'maxFiles' => '',
            'calculationFormula' => '',
            'calculationRounding' => '',
            'enableCalculation' => '',
            'disableQuantity' => false,
            'useRichTextEditor' => false,
            'checkboxLabel' => '',
            'postFeaturedImage' => false,
            'fields' => '',
        ]
    );

    $baseField_post_category_select = \array_merge(
        $baseField_post_category_component,
        [
            'id' => 151,
            'inputType' => 'select',
        ]
    );

    $baseField_post_category_radio = \array_merge(
        $baseField_post_category_component,
        [
            'id' => 158,
            'inputType' => 'radio',
        ]
    );

    $baseField_post_category_checkbox = \array_merge(
        $baseField_post_category_component,
        [
            'id' => 165,
            'inputType' => 'checkbox',
        ]
    );

    $baseField_post_category_multiselect = \array_merge(
        $baseField_post_category_component,
        [
            'id' => 166,
            'inputType' => 'multiselect',
        ]
    );

    $baseField_post_image = \array_merge(
        $baseFieldComponent,
        [
            'id' => 159,
            'type' => 'post_image',
            'inputs' => null,
            'allowedExtensions' => 'jpg, jpeg, png, gif',
            'description' => '',
            'inputMaskIsCustom' => false,
            'autocompleteAttribute' => '',
            'choices' => '',
            'maxFileSize' => '',
            'maxFiles' => '',
            'multipleFiles' => false,
            'displayAlt' => '',
            'displayTitle' => '',
            'displayCaption' => '',
            'displayDescription' => '',
            'calculationFormula' => '',
            'calculationRounding' => '',
            'enableCalculation' => '',
            'disableQuantity' => false,
            'displayAllCategories' => false,
            'useRichTextEditor' => false,
            'postFeaturedImage' => false,
            'fields' => '',
        ]
    );

    $baseField_hidden = \array_merge(
        $baseFieldComponent,
        [
            'id' => 187,
            'type' => 'hidden',
        ]
    );

    $baseField_rcwdupload = \array_merge(
        $baseFieldComponent,
        [
            'id' => 188,
            'type' => 'rcwdupload',
            'inputs' => null,
            'gfurclass' => 'GFUserData',
            'gfurd' => 'old',
            'rnm_search' => [
                '[form_id]',
                '[field_id]',
                '[lead_id]',
                '[fieldlabel]',
                '[field:ID]',
            ],
            'description' => '',
            'choices' => '',
            'multipleFiles' => false,
            'maxFiles' => '',
            'calculationFormula' => '',
            'calculationRounding' => '',
            'enableCalculation' => '',
            'disableQuantity' => false,
            'displayAllCategories' => false,
            'useRichTextEditor' => false,
            'rcwdupload_autoupload' => 'Y',
            'rcwdupload_hidebrowse' => 'Y2',
            'rcwdupload_textfields_choices' => [
                '',
            ],
            'rcwdupload_textfields_choices_req' => [
                false,
            ],
            'displayOnly' => '',
            'fields' => '',
            'inputMaskIsCustom' => false,
            'autocompleteAttribute' => '',
            'gppa-values-object-type' => '',
            'gppa-choices-object-type' => '',
        ]
    );

    $baseField_form = \array_merge(
        $baseFieldComponent,
        [
            'id' => 189,
            'type' => 'form',
            'gp-nested-forms_fields' => '',
            'gp-nested-forms_form' => '',
            'gpnfEntryLabelPlural' => 'Things',
            'gpnfEntryLabelSingular' => 'Thing',
            'gpnfEntryLimitMin' => '',
            'gpnfFields' => [
                '1',
                '2',
                '3',
            ],
            'gpnfForm' => '001',
            'gpnfFormTitle' => 'Foo Bar Baz Nested Form',
        ]
    );

    return [
        'siteHandle' => $siteHandle,
        'siteTitle' => $siteTitle,
        'baseFieldComponent' => $baseFieldComponent,
        'baseField_address' => $baseField_address,
        'baseField_checkbox' => $baseField_checkbox,
        'baseField_consent' => $baseField_consent,
        'baseField_date' => $baseField_date,
        'baseField_email' => $baseField_email,
        'baseField_fileupload' => $baseField_fileupload,
        'baseField_form' => $baseField_form,
        'baseField_hidden' => $baseField_hidden,
        'baseField_html' => $baseField_html,
        'baseField_list' => $baseField_list,
        'baseField_multi_choice_checkbox_exactly' => $baseField_multi_choice_checkbox_exactly,
        'baseField_multi_choice_checkbox_range' => $baseField_multi_choice_checkbox_range,
        'baseField_multi_choice_checkbox_unlimited' => $baseField_multi_choice_checkbox_unlimited,
        'baseField_multi_choice_radio' => $baseField_multi_choice_radio,
        'baseField_multiselect' => $baseField_multiselect,
        'baseField_name' => $baseField_name,
        'baseField_number' => $baseField_number,
        'baseField_password' => $baseField_password,
        'baseField_phone' => $baseField_phone,
        'baseField_poll_checkbox' => $baseField_poll_checkbox,
        'baseField_poll_radio' => $baseField_poll_radio,
        'baseField_poll_select' => $baseField_poll_select,
        'baseField_post_category_radio' => $baseField_post_category_radio,
        'baseField_post_category_checkbox' => $baseField_post_category_checkbox,
        'baseField_post_category_multiselect' => $baseField_post_category_multiselect,
        'baseField_post_category_select' => $baseField_post_category_select,
        'baseField_post_content' => $baseField_post_content,
        'baseField_post_custom_field_text' => $baseField_post_custom_field_text,
        'baseField_post_custom_field_textarea' => $baseField_post_custom_field_textarea,
        'baseField_post_custom_field_select' => $baseField_post_custom_field_select,
        'baseField_post_custom_field_multiselect' => $baseField_post_custom_field_multiselect,
        'baseField_post_custom_field_number' => $baseField_post_custom_field_number,
        'baseField_post_custom_field_checkbox' => $baseField_post_custom_field_checkbox,
        'baseField_post_custom_field_radio' => $baseField_post_custom_field_radio,
        'baseField_post_custom_field_hidden' => $baseField_post_custom_field_hidden,
        'baseField_post_custom_field_date' => $baseField_post_custom_field_date,
        'baseField_post_custom_field_time' => $baseField_post_custom_field_time,
        'baseField_post_custom_field_website' => $baseField_post_custom_field_website,
        'baseField_post_custom_field_email' => $baseField_post_custom_field_email,
        'baseField_post_custom_field_fileupload' => $baseField_post_custom_field_fileupload,
        'baseField_post_custom_field_list' => $baseField_post_custom_field_list,
        'baseField_post_custom_field_phone' => $baseField_post_custom_field_phone,
        'baseField_post_excerpt' => $baseField_post_excerpt,
        'baseField_post_image' => $baseField_post_image,
        'baseField_post_tags_empty' => $baseField_post_tags_empty,
        'baseField_post_tags_not_set' => $baseField_post_tags_not_set,
        'baseField_post_tags_text' => $baseField_post_tags_text,
        'baseField_post_tags_select' => $baseField_post_tags_select,
        'baseField_post_tags_checkbox' => $baseField_post_tags_checkbox,
        'baseField_post_tags_radio' => $baseField_post_tags_radio,
        'baseField_post_tags_multiselect' => $baseField_post_tags_multiselect,
        'baseField_post_title' => $baseField_post_title,
        'baseField_product' => $baseField_product,
        'baseField_quiz' => $baseField_quiz,
        'baseField_radio' => $baseField_radio,
        'baseField_rcwdupload' => $baseField_rcwdupload,
        'baseField_select' => $baseField_select,
        'baseField_survey_checkbox' => $baseField_survey_checkbox,
        'baseField_survey_likert' => $baseField_survey_likert,
        'baseField_survey_radio' => $baseField_survey_radio,
        'baseField_survey_rating' => $baseField_survey_rating,
        'baseField_survey_select' => $baseField_survey_select,
        'baseField_survey_text' => $baseField_survey_text,
        'baseField_survey_textarea' => $baseField_survey_textarea,
        'baseField_text' => $baseField_text,
        'baseField_textarea' => $baseField_textarea,
        'baseField_time' => $baseField_time,
    ];
}

/**
 * Data provider for fieldtype mapping tests.
 *
 * @return array<string, array{gravity_forms_type: string, expected_statamic_type: string|false|null, assertion_type: string, inputType?: ?string}>
 */
function gravityFieldtypeMappingProvider(): array
{
    $siteHandle = 'supply_chain';
    $siteTitle = 'Supply Chain';

    return [
        // Standard Fields - Mapped
        'text fieldtype' => [
            'gravity_forms_type' => 'text',
            'expected_statamic_type' => 'text',
            'assertion_type' => 'assertEquals',
        ],
        'textarea fieldtype' => [
            'gravity_forms_type' => 'textarea',
            'expected_statamic_type' => 'textarea',
            'assertion_type' => 'assertEquals',
        ],
        'select fieldtype' => [
            'gravity_forms_type' => 'select',
            'expected_statamic_type' => 'select',
            'assertion_type' => 'assertEquals',
        ],
        'number fieldtype' => [
            'gravity_forms_type' => 'number',
            'expected_statamic_type' => 'float',
            'assertion_type' => 'assertEquals',
        ],
        'checkbox fieldtype' => [
            'gravity_forms_type' => 'checkbox',
            'expected_statamic_type' => 'checkboxes',
            'assertion_type' => 'assertEquals',
        ],
        'radio fieldtype' => [
            'gravity_forms_type' => 'radio',
            'expected_statamic_type' => 'radio',
            'assertion_type' => 'assertEquals',
        ],
        'rcwdupload fieldtype' => [
            'gravity_forms_type' => 'rcwdupload',
            'expected_statamic_type' => 'assets',
            'assertion_type' => 'assertEquals',
        ],
        'hidden fieldtype' => [
            'gravity_forms_type' => 'hidden',
            'expected_statamic_type' => 'hidden',
            'assertion_type' => 'assertEquals',
        ],
        'html fieldtype' => [
            'gravity_forms_type' => 'html',
            'expected_statamic_type' => 'html',
            'assertion_type' => 'assertEquals',
        ],
        'section fieldtype' => [
            'gravity_forms_type' => 'section',
            'expected_statamic_type' => 'subsection',
            'assertion_type' => 'assertEquals',
        ],

        // Advanced Fields - Mapped
        'name fieldtype' => [
            'gravity_forms_type' => 'name',
            'expected_statamic_type' => 'name',
            'assertion_type' => 'assertEquals',
        ],
        'date fieldtype' => [
            'gravity_forms_type' => 'date',
            'expected_statamic_type' => 'date',
            'assertion_type' => 'assertEquals',
        ],
        'time fieldtype' => [
            'gravity_forms_type' => 'time',
            'expected_statamic_type' => 'time',
            'assertion_type' => 'assertEquals',
        ],
        'phone fieldtype' => [
            'gravity_forms_type' => 'phone',
            'expected_statamic_type' => 'phone',
            'assertion_type' => 'assertEquals',
        ],
        'address fieldtype' => [
            'gravity_forms_type' => 'address',
            'expected_statamic_type' => 'address',
            'assertion_type' => 'assertEquals',
        ],
        'website fieldtype' => [
            'gravity_forms_type' => 'website',
            'expected_statamic_type' => 'url',
            'assertion_type' => 'assertEquals',
        ],
        'email fieldtype' => [
            'gravity_forms_type' => 'email',
            'expected_statamic_type' => 'email',
            'assertion_type' => 'assertEquals',
        ],
        'fileupload fieldtype' => [
            'gravity_forms_type' => 'fileupload',
            'expected_statamic_type' => 'assets',
            'assertion_type' => 'assertEquals',
        ],
        'list fieldtype' => [
            'gravity_forms_type' => 'list',
            'expected_statamic_type' => 'list',
            'assertion_type' => 'assertEquals',
        ],
        'multiselect fieldtype' => [
            'gravity_forms_type' => 'multiselect',
            'expected_statamic_type' => 'select',
            'assertion_type' => 'assertEquals',
        ],
        'consent fieldtype' => [
            'gravity_forms_type' => 'consent',
            'expected_statamic_type' => 'consent',
            'assertion_type' => 'assertEquals',
        ],

        // Add-On Fields - Mapped
        'password fieldtype' => [
            'gravity_forms_type' => 'password',
            'expected_statamic_type' => 'password',
            'assertion_type' => 'assertEquals',
        ],
        'quiz fieldtype' => [
            'gravity_forms_type' => 'quiz',
            'expected_statamic_type' => 'quiz',
            'assertion_type' => 'assertEquals',
        ],
        'signature fieldtype' => [
            'gravity_forms_type' => 'signature',
            'expected_statamic_type' => 'signature',
            'assertion_type' => 'assertEquals',
        ],

        // Post Fields - Mapped
        'post_title fieldtype' => [
            'gravity_forms_type' => 'post_title',
            'expected_statamic_type' => 'text',
            'assertion_type' => 'assertEquals',
        ],
        'post_content fieldtype' => [
            'gravity_forms_type' => 'post_content',
            'expected_statamic_type' => 'textarea',
            'assertion_type' => 'assertEquals',
        ],
        'post_excerpt fieldtype' => [
            'gravity_forms_type' => 'post_excerpt',
            'expected_statamic_type' => 'textarea',
            'assertion_type' => 'assertEquals',
        ],
        'post_category fieldtype' => [
            'gravity_forms_type' => 'post_category',
            'expected_statamic_type' => 'select',
            'assertion_type' => 'assertEquals',
        ],
        'post_image fieldtype' => [
            'gravity_forms_type' => 'post_image',
            'expected_statamic_type' => 'assets',
            'assertion_type' => 'assertEquals',
        ],
        'post_custom_field fieldtype' => [
            'gravity_forms_type' => 'post_custom_field',
            'expected_statamic_type' => 'text',
            'assertion_type' => 'assertEquals',
        ],

        // Suppressed Fieldtypes (mapped to false)
        'page fieldtype' => [
            'gravity_forms_type' => 'page',
            'expected_statamic_type' => false,
            'assertion_type' => 'assertFalse',
        ],

        // Conditional Fieldtypes (mapped based on inputType attribute)
        'multi_choice checkbox' => [
            'gravity_forms_type' => 'multi_choice',
            'expected_statamic_type' => 'checkboxes',
            'assertion_type' => 'assertEquals',
            'inputType' => 'checkbox',
        ],
        'multi_choice radio' => [
            'gravity_forms_type' => 'multi_choice',
            'expected_statamic_type' => 'radio',
            'assertion_type' => 'assertEquals',
            'inputType' => 'radio',
        ],
        'poll checkbox' => [
            'gravity_forms_type' => 'poll',
            'expected_statamic_type' => 'checkboxes',
            'assertion_type' => 'assertEquals',
            'inputType' => 'checkbox',
        ],
        'poll radio' => [
            'gravity_forms_type' => 'poll',
            'expected_statamic_type' => 'radio',
            'assertion_type' => 'assertEquals',
            'inputType' => 'radio',
        ],
        'poll select' => [
            'gravity_forms_type' => 'poll',
            'expected_statamic_type' => 'select',
            'assertion_type' => 'assertEquals',
            'inputType' => 'select',
        ],
        'post tags not set' => [
            'gravity_forms_type' => 'post_tags',
            'expected_statamic_type' => 'list',
            'assertion_type' => 'assertEquals',
            'inputType' => '#not_set',
        ],
        'post tags empty' => [
            'gravity_forms_type' => 'post_tags',
            'expected_statamic_type' => 'list',
            'assertion_type' => 'assertEquals',
            'inputType' => '#empty',
        ],
        'post tags checkbox' => [
            'gravity_forms_type' => 'post_tags',
            'expected_statamic_type' => 'checkboxes',
            'assertion_type' => 'assertEquals',
            'inputType' => 'checkbox',
        ],
        'post tags radio' => [
            'gravity_forms_type' => 'post_tags',
            'expected_statamic_type' => 'radio',
            'assertion_type' => 'assertEquals',
            'inputType' => 'radio',
        ],
        'post tags select' => [
            'gravity_forms_type' => 'post_tags',
            'expected_statamic_type' => 'select',
            'assertion_type' => 'assertEquals',
            'inputType' => 'select',
        ],
        'post tags multiselect' => [
            'gravity_forms_type' => 'post_tags',
            'expected_statamic_type' => 'select',
            'assertion_type' => 'assertEquals',
            'inputType' => 'multiselect',
        ],
        'post tags text' => [
            'gravity_forms_type' => 'post_tags',
            'expected_statamic_type' => 'list',
            'assertion_type' => 'assertEquals',
            'inputType' => 'text',
        ],
        'survey checkbox' => [
            'gravity_forms_type' => 'survey',
            'expected_statamic_type' => 'checkboxes',
            'assertion_type' => 'assertEquals',
            'inputType' => 'checkbox',
        ],
        'survey likert' => [
            'gravity_forms_type' => 'survey',
            'expected_statamic_type' => 'likert_scale',
            'assertion_type' => 'assertEquals',
            'inputType' => 'likert',
        ],
        'survey radio' => [
            'gravity_forms_type' => 'survey',
            'expected_statamic_type' => 'radio',
            'assertion_type' => 'assertEquals',
            'inputType' => 'radio',
        ],
        'survey rating' => [
            'gravity_forms_type' => 'survey',
            'expected_statamic_type' => 'radio',
            'assertion_type' => 'assertEquals',
            'inputType' => 'rating',
        ],
        'survey select' => [
            'gravity_forms_type' => 'survey',
            'expected_statamic_type' => 'select',
            'assertion_type' => 'assertEquals',
            'inputType' => 'select',
        ],
        'survey text' => [
            'gravity_forms_type' => 'survey',
            'inputType' => 'text',
            'expected_statamic_type' => 'text',
            'assertion_type' => 'assertEquals',
        ],
        'survey textarea' => [
            'gravity_forms_type' => 'survey',
            'expected_statamic_type' => 'textarea',
            'assertion_type' => 'assertEquals',
            'inputType' => 'textarea',
        ],

        // Unmapped Fieldtypes (mapped to null)
        'image_choice fieldtype' => [
            'gravity_forms_type' => 'image_choice',
            'expected_statamic_type' => null,
            'assertion_type' => 'assertNull',
        ],
        'captcha fieldtype' => [
            'gravity_forms_type' => 'captcha',
            'expected_statamic_type' => null,
            'assertion_type' => 'assertNull',
        ],
        'gf_no_captcha_recaptcha fieldtype' => [
            'gravity_forms_type' => 'gf_no_captcha_recaptcha',
            'expected_statamic_type' => null,
            'assertion_type' => 'assertNull',
        ],
        // Resolves to the Statamic `username` fieldtype since
        // `UAMS-Web/uams-statamic-archive#312` shipped it; before that it fell back to `text` (#300)
        'username fieldtype' => [
            'gravity_forms_type' => 'username',
            'expected_statamic_type' => 'username',
            'assertion_type' => 'assertEquals',
        ],
        'quantity fieldtype' => [
            'gravity_forms_type' => 'quantity',
            'expected_statamic_type' => null,
            'assertion_type' => 'assertNull',
        ],
        // Resolves to the Statamic `option` fieldtype since
        // `UAMS-Web/uams-statamic-archive#316` shipped it; before that it fell back to `text` (#302)
        'option fieldtype' => [
            'gravity_forms_type' => 'option',
            'expected_statamic_type' => 'option',
            'assertion_type' => 'assertEquals',
        ],
        'shipping fieldtype' => [
            'gravity_forms_type' => 'shipping',
            'expected_statamic_type' => null,
            'assertion_type' => 'assertNull',
        ],

        // Special Fieldtypes
        'product fieldtype' => [
            'gravity_forms_type' => 'product',
            'expected_statamic_type' => 'product',
            'assertion_type' => 'assertEquals',
        ],
        'total fieldtype' => [
            'gravity_forms_type' => 'total',
            'expected_statamic_type' => 'total',
            'assertion_type' => 'assertEquals',
        ],

        // Unknown Fieldtypes
        'unknown fieldtype' => [
            'gravity_forms_type' => 'unknown_fieldtype',
            'expected_statamic_type' => null,
            'assertion_type' => 'assertNull',
        ],
    ];
}

/**
 * Data provider for testing different fieldtypes and their attribute mappings.
 *
 * @return array<string, array<string, mixed>>
 */
function gravityAttributeMappingProvider(): array
{
    // Get base field definitions from helper method
    $fieldDefinitions = gravityGetBaseFieldDefinitions();

    // Extract individual field definitions for easier access
    $siteHandle = gravityFormDefinitionString($fieldDefinitions, 'siteHandle');
    $siteTitle = gravityFormDefinitionString($fieldDefinitions, 'siteTitle');
    $baseFieldComponent = gravityFormBaseField($fieldDefinitions, 'baseFieldComponent');
    $baseField_address = gravityFormBaseField($fieldDefinitions, 'baseField_address');
    $baseField_checkbox = gravityFormBaseField($fieldDefinitions, 'baseField_checkbox');
    $baseField_consent = gravityFormBaseField($fieldDefinitions, 'baseField_consent');
    $baseField_date = gravityFormBaseField($fieldDefinitions, 'baseField_date');
    $baseField_email = gravityFormBaseField($fieldDefinitions, 'baseField_email');
    $baseField_fileupload = gravityFormBaseField($fieldDefinitions, 'baseField_fileupload');
    $baseField_form = gravityFormBaseField($fieldDefinitions, 'baseField_form');
    $baseField_hidden = gravityFormBaseField($fieldDefinitions, 'baseField_hidden');
    $baseField_html = gravityFormBaseField($fieldDefinitions, 'baseField_html');
    $baseField_list = gravityFormBaseField($fieldDefinitions, 'baseField_list');
    $baseField_multi_choice_checkbox_exactly = gravityFormBaseField($fieldDefinitions, 'baseField_multi_choice_checkbox_exactly');
    $baseField_multi_choice_checkbox_range = gravityFormBaseField($fieldDefinitions, 'baseField_multi_choice_checkbox_range');
    $baseField_multi_choice_checkbox_unlimited = gravityFormBaseField($fieldDefinitions, 'baseField_multi_choice_checkbox_unlimited');
    $baseField_multi_choice_radio = gravityFormBaseField($fieldDefinitions, 'baseField_multi_choice_radio');
    $baseField_multiselect = gravityFormBaseField($fieldDefinitions, 'baseField_multiselect');
    $baseField_name = gravityFormBaseField($fieldDefinitions, 'baseField_name');
    $baseField_number = gravityFormBaseField($fieldDefinitions, 'baseField_number');
    $baseField_password = gravityFormBaseField($fieldDefinitions, 'baseField_password');
    $baseField_phone = gravityFormBaseField($fieldDefinitions, 'baseField_phone');
    $baseField_poll_checkbox = gravityFormBaseField($fieldDefinitions, 'baseField_poll_checkbox');
    $baseField_poll_radio = gravityFormBaseField($fieldDefinitions, 'baseField_poll_radio');
    $baseField_poll_select = gravityFormBaseField($fieldDefinitions, 'baseField_poll_select');
    $baseField_post_category_radio = gravityFormBaseField($fieldDefinitions, 'baseField_post_category_radio');
    $baseField_post_category_checkbox = gravityFormBaseField($fieldDefinitions, 'baseField_post_category_checkbox');
    $baseField_post_category_multiselect = gravityFormBaseField($fieldDefinitions, 'baseField_post_category_multiselect');
    $baseField_post_category_select = gravityFormBaseField($fieldDefinitions, 'baseField_post_category_select');
    $baseField_post_content = gravityFormBaseField($fieldDefinitions, 'baseField_post_content');
    $baseField_post_custom_field_text = gravityFormBaseField($fieldDefinitions, 'baseField_post_custom_field_text');
    $baseField_post_custom_field_textarea = gravityFormBaseField($fieldDefinitions, 'baseField_post_custom_field_textarea');
    $baseField_post_custom_field_select = gravityFormBaseField($fieldDefinitions, 'baseField_post_custom_field_select');
    $baseField_post_custom_field_multiselect = gravityFormBaseField($fieldDefinitions, 'baseField_post_custom_field_multiselect');
    $baseField_post_custom_field_number = gravityFormBaseField($fieldDefinitions, 'baseField_post_custom_field_number');
    $baseField_post_custom_field_checkbox = gravityFormBaseField($fieldDefinitions, 'baseField_post_custom_field_checkbox');
    $baseField_post_custom_field_radio = gravityFormBaseField($fieldDefinitions, 'baseField_post_custom_field_radio');
    $baseField_post_custom_field_hidden = gravityFormBaseField($fieldDefinitions, 'baseField_post_custom_field_hidden');
    $baseField_post_custom_field_date = gravityFormBaseField($fieldDefinitions, 'baseField_post_custom_field_date');
    $baseField_post_custom_field_time = gravityFormBaseField($fieldDefinitions, 'baseField_post_custom_field_time');
    $baseField_post_custom_field_website = gravityFormBaseField($fieldDefinitions, 'baseField_post_custom_field_website');
    $baseField_post_custom_field_email = gravityFormBaseField($fieldDefinitions, 'baseField_post_custom_field_email');
    $baseField_post_custom_field_fileupload = gravityFormBaseField($fieldDefinitions, 'baseField_post_custom_field_fileupload');
    $baseField_post_custom_field_list = gravityFormBaseField($fieldDefinitions, 'baseField_post_custom_field_list');
    $baseField_post_custom_field_phone = gravityFormBaseField($fieldDefinitions, 'baseField_post_custom_field_phone');
    $baseField_post_excerpt = gravityFormBaseField($fieldDefinitions, 'baseField_post_excerpt');
    $baseField_post_image = gravityFormBaseField($fieldDefinitions, 'baseField_post_image');
    $baseField_post_tags_empty = gravityFormBaseField($fieldDefinitions, 'baseField_post_tags_empty');
    $baseField_post_tags_not_set = gravityFormBaseField($fieldDefinitions, 'baseField_post_tags_not_set');
    $baseField_post_tags_text = gravityFormBaseField($fieldDefinitions, 'baseField_post_tags_text');
    $baseField_post_tags_select = gravityFormBaseField($fieldDefinitions, 'baseField_post_tags_select');
    $baseField_post_tags_checkbox = gravityFormBaseField($fieldDefinitions, 'baseField_post_tags_checkbox');
    $baseField_post_tags_radio = gravityFormBaseField($fieldDefinitions, 'baseField_post_tags_radio');
    $baseField_post_tags_multiselect = gravityFormBaseField($fieldDefinitions, 'baseField_post_tags_multiselect');
    $baseField_post_title = gravityFormBaseField($fieldDefinitions, 'baseField_post_title');
    $baseField_product = gravityFormBaseField($fieldDefinitions, 'baseField_product');
    $baseField_quiz = gravityFormBaseField($fieldDefinitions, 'baseField_quiz');
    $baseField_radio = gravityFormBaseField($fieldDefinitions, 'baseField_radio');
    $baseField_rcwdupload = gravityFormBaseField($fieldDefinitions, 'baseField_rcwdupload');
    $baseField_select = gravityFormBaseField($fieldDefinitions, 'baseField_select');
    $baseField_survey_checkbox = gravityFormBaseField($fieldDefinitions, 'baseField_survey_checkbox');
    $baseField_survey_likert = gravityFormBaseField($fieldDefinitions, 'baseField_survey_likert');
    $baseField_survey_radio = gravityFormBaseField($fieldDefinitions, 'baseField_survey_radio');
    $baseField_survey_rating = gravityFormBaseField($fieldDefinitions, 'baseField_survey_rating');
    $baseField_survey_select = gravityFormBaseField($fieldDefinitions, 'baseField_survey_select');
    $baseField_survey_text = gravityFormBaseField($fieldDefinitions, 'baseField_survey_text');
    $baseField_survey_textarea = gravityFormBaseField($fieldDefinitions, 'baseField_survey_textarea');
    $baseField_text = gravityFormBaseField($fieldDefinitions, 'baseField_text');
    $baseField_textarea = gravityFormBaseField($fieldDefinitions, 'baseField_textarea');
    $baseField_time = gravityFormBaseField($fieldDefinitions, 'baseField_time');

    return [
        'text field with description' => [
            'source' => [
                \array_merge(
                    $baseField_text,
                    ['description' => 'Lorem ipsum dolor sit amet, consectetuer adipiscing elit.']
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                        'instructions' => 'Lorem ipsum dolor sit amet, consectetuer adipiscing elit.',
                    ],
                ],
            ],
        ],
        'text field without description' => [
            'source' => [
                \array_merge(
                    $baseField_text,
                    []
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'text field with is required true' => [
            'source' => [
                \array_merge($baseField_text, [
                    'isRequired' => true,
                ]),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                        'validate' => [
                            'required',
                            'sometimes',
                        ],
                    ],
                ],
            ],
        ],
        'text field with is required "1"' => [
            'source' => [
                \array_merge($baseField_text, [
                    'isRequired' => '1',
                ]),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                        'validate' => [
                            'required',
                            'sometimes',
                        ],
                    ],
                ],
            ],
        ],
        'text field with is required "0"' => [
            'source' => [
                \array_merge($baseField_text, [
                    'isRequired' => '0',
                ]),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'text field with width class gf_first_quarter' => [
            'source' => [
                \array_merge(
                    $baseField_text,
                    ['cssClass' => 'gf_first_quarter']
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                        'width' => 25,
                    ],
                ],
            ],
        ],
        'text field with d-none class' => [
            'source' => [
                \array_merge(
                    $baseField_text,
                    ['cssClass' => 'd-none']
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                        'visibility' => 'hidden',
                    ],
                ],
            ],
        ],
        'text field with width class gf_fourth_quarter' => [
            'source' => [
                \array_merge(
                    $baseField_text,
                    ['cssClass' => 'gf_fourth_quarter']
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                        'width' => 25,
                    ],
                ],
            ],
        ],
        'text field with width class gf_left_half' => [
            'source' => [
                \array_merge(
                    $baseField_text,
                    ['cssClass' => 'gf_left_half']
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                        'width' => 50,
                    ],
                ],
            ],
        ],
        'text field with width class gf_left_third' => [
            'source' => [
                \array_merge(
                    $baseField_text,
                    ['cssClass' => 'gf_left_third']
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                        'width' => 33,
                    ],
                ],
            ],
        ],
        'text field with width class gf_middle_third' => [
            'source' => [
                \array_merge(
                    $baseField_text,
                    ['cssClass' => 'gf_middle_third']
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                        'width' => 33,
                    ],
                ],
            ],
        ],
        'text field with width class gf_right_half' => [
            'source' => [
                \array_merge(
                    $baseField_text,
                    ['cssClass' => 'gf_right_half']
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                        'width' => 50,
                    ],
                ],
            ],
        ],
        'text field with width class gf_right_third' => [
            'source' => [
                \array_merge(
                    $baseField_text,
                    ['cssClass' => 'gf_right_third']
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                        'width' => 33,
                    ],
                ],
            ],
        ],
        'text field with width class gf_second_quarter' => [
            'source' => [
                \array_merge(
                    $baseField_text,
                    ['cssClass' => 'gf_second_quarter']
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                        'width' => 25,
                    ],
                ],
            ],
        ],
        'text field with width class gf_third_quarter' => [
            'source' => [
                \array_merge(
                    $baseField_text,
                    ['cssClass' => 'gf_third_quarter']
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                        'width' => 25,
                    ],
                ],
            ],
        ],
        'text field with layout grid column span 12' => [
            'source' => [
                \array_merge(
                    $baseField_text,
                    ['layoutGridColumnSpan' => 12]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'text field with layout grid column span 11' => [
            'source' => [
                \array_merge(
                    $baseField_text,
                    ['layoutGridColumnSpan' => 11]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'text field with layout grid column span 10' => [
            'source' => [
                \array_merge(
                    $baseField_text,
                    ['layoutGridColumnSpan' => 10]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'text field with layout grid column span 9' => [
            'source' => [
                \array_merge(
                    $baseField_text,
                    ['layoutGridColumnSpan' => 9]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                        'width' => 75,
                    ],
                ],
            ],
        ],
        'text field with layout grid column span 8' => [
            'source' => [
                \array_merge(
                    $baseField_text,
                    ['layoutGridColumnSpan' => 8]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                        'width' => 66,
                    ],
                ],
            ],
        ],
        'text field with layout grid column span 7' => [
            'source' => [
                \array_merge(
                    $baseField_text,
                    ['layoutGridColumnSpan' => 7]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                        'width' => 50,
                    ],
                ],
            ],
        ],
        'text field with layout grid column span 6' => [
            'source' => [
                \array_merge(
                    $baseField_text,
                    ['layoutGridColumnSpan' => 6]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                        'width' => 50,
                    ],
                ],
            ],
        ],
        'text field with layout grid column span 5' => [
            'source' => [
                \array_merge(
                    $baseField_text,
                    ['layoutGridColumnSpan' => 5]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                        'width' => 50,
                    ],
                ],
            ],
        ],
        'text field with layout grid column span 4' => [
            'source' => [
                \array_merge(
                    $baseField_text,
                    ['layoutGridColumnSpan' => 4]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                        'width' => 33,
                    ],
                ],
            ],
        ],
        'text field with layout grid column span 3' => [
            'source' => [
                \array_merge(
                    $baseField_text,
                    ['layoutGridColumnSpan' => 3]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                        'width' => 25,
                    ],
                ],
            ],
        ],
        'radio field with options' => [
            'source' => [
                \array_merge(
                    $baseField_radio,
                    [
                        'choices' => [
                            [
                                'text' => 'Green',
                                'value' => 'Green',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Yellow',
                                'value' => 'Yellow',
                                'isSelected' => false,
                                'price' => '',
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_137',
                    'field' => [
                        'type' => 'radio',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'Green',
                                'value' => null,
                            ],
                            [
                                'key' => 'Yellow',
                                'value' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'text field with literal default' => [
            'source' => [
                \array_merge(
                    $baseField_text,
                    ['defaultValue' => 'Foo']
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                        'default' => 'Foo',
                    ],
                ],
            ],
        ],
        'text field with calculation query' => [
            'source' => [
                \array_merge(
                    $baseField_text,
                    [
                        'allowsPrepopulate' => true,
                        'inputName' => 'foo',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                        'calculation_query' => true,
                        'calculation_formula_text' => '[get_param:foo]',
                    ],
                ],
            ],
        ],
        'address field with address type international' => [
            'source' => [
                \array_merge(
                    $baseField_address,
                    ['addressType' => 'international']
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_125',
                    'field' => [
                        'type' => 'address',
                        'display' => 'Test Field',
                        'preset' => 'international',
                    ],
                ],
            ],
        ],
        'address field with address type international and default country united states' => [
            'source' => [
                \array_merge(
                    $baseField_address,
                    [
                        'addressType' => 'international',
                        'defaultCountry' => 'United States',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_125',
                    'field' => [
                        'type' => 'address',
                        'display' => 'Test Field',
                        'preset' => 'international',
                        'country_default' => 'US',
                    ],
                ],
            ],
        ],
        'address field with address type canadian' => [
            'source' => [
                \array_merge(
                    $baseField_address,
                    ['addressType' => 'canadian']
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_125',
                    'field' => [
                        'type' => 'address',
                        'display' => 'Test Field',
                        'preset' => 'canadian',
                    ],
                ],
            ],
        ],
        'address field with address type canadian and default province quebec' => [
            'source' => [
                \array_merge(
                    $baseField_address,
                    [
                        'addressType' => 'canadian',
                        'defaultProvince' => 'Quebec',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_125',
                    'field' => [
                        'type' => 'address',
                        'display' => 'Test Field',
                        'preset' => 'canadian',
                        'state_province_region_canadian_default' => 'QC',
                    ],
                ],
            ],
        ],
        'address field with address type canadian and default state quebec' => [
            'source' => [
                \array_merge(
                    $baseField_address,
                    [
                        'addressType' => 'canadian',
                        'defaultState' => 'Quebec',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_125',
                    'field' => [
                        'type' => 'address',
                        'display' => 'Test Field',
                        'preset' => 'canadian',
                        'state_province_region_canadian_default' => 'QC',
                    ],
                ],
            ],
        ],
        'address field with address type us' => [
            'source' => [
                \array_merge(
                    $baseField_address,
                    ['addressType' => 'us']
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_125',
                    'field' => [
                        'type' => 'address',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'address field with address type us and default country united states' => [
            'source' => [
                \array_merge(
                    $baseField_address,
                    [
                        'addressType' => 'us',
                        'defaultCountry' => 'United States',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_125',
                    'field' => [
                        'type' => 'address',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'address field with address type us and default state arkansas' => [
            'source' => [
                \array_merge(
                    $baseField_address,
                    [
                        'addressType' => 'us',
                        'defaultState' => 'Arkansas',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_125',
                    'field' => [
                        'type' => 'address',
                        'display' => 'Test Field',
                        'state_province_region_american_default' => 'AR',
                    ],
                ],
            ],
        ],
        'address field with address type us and default state virginia' => [
            'source' => [
                \array_merge(
                    $baseField_address,
                    [
                        'addressType' => 'us',
                        'defaultState' => 'Virginia',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_125',
                    'field' => [
                        'type' => 'address',
                        'display' => 'Test Field',
                        'state_province_region_american_default' => 'VA',
                    ],
                ],
            ],
        ],
        'address field with address type international and custom labels' => [
            'source' => [
                \array_merge(
                    $baseField_address,
                    [
                        'addressType' => 'international',
                        'inputs' => [
                            [
                                'id' => '67.1',
                                'label' => 'Street Address',
                                'name' => '',
                                'autocompleteAttribute' => 'address-line1',
                                'customLabel' => 'Foo Street Address',
                                'placeholder' => 'Bar Street Address',
                                'defaultValue' => 'Baz Street Address',
                            ],
                            [
                                'id' => '67.2',
                                'label' => 'Address Line 2',
                                'name' => '',
                                'autocompleteAttribute' => 'address-line2',
                                'customLabel' => 'Foo Address Line 2',
                                'placeholder' => 'Bar Address Line 2',
                                'defaultValue' => 'Baz Address Line 2',
                            ],
                            [
                                'id' => '67.3',
                                'label' => 'City',
                                'name' => '',
                                'autocompleteAttribute' => 'address-level2',
                                'customLabel' => 'Foo City',
                                'placeholder' => 'Bar City',
                                'defaultValue' => 'Baz City',
                            ],
                            [
                                'id' => '67.4',
                                'label' => 'State \/ Province',
                                'name' => '',
                                'autocompleteAttribute' => 'address-level1',
                                'customLabel' => 'Foo State',
                                'placeholder' => 'Bar State',
                                'defaultValue' => 'Baz State',
                            ],
                            [
                                'id' => '67.5',
                                'label' => 'ZIP \/ Postal Code',
                                'name' => '',
                                'autocompleteAttribute' => 'postal-code',
                                'customLabel' => 'Foo ZIP',
                                'placeholder' => 'Bar ZIP',
                                'defaultValue' => 'Baz ZIP',
                            ],
                            [
                                'id' => '67.6',
                                'label' => 'Country',
                                'name' => '',
                                'autocompleteAttribute' => 'country-name',
                                'customLabel' => 'Foo Country',
                                'placeholder' => 'Bar Country',
                                'defaultValue' => 'Baz Country',
                            ],
                        ],
                        'defaultCountry' => 'Afghanistan',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_125',
                    'field' => [
                        'type' => 'address',
                        'display' => 'Test Field',
                        'preset' => 'international',
                        'street_address_label' => 'Foo Street Address',
                        'street_address_placeholder' => 'Bar Street Address',
                        'street_address_default' => 'Baz Street Address',
                        'address_line_2_label' => 'Foo Address Line 2',
                        'address_line_2_placeholder' => 'Bar Address Line 2',
                        'address_line_2_default' => 'Baz Address Line 2',
                        'city_label' => 'Foo City',
                        'city_placeholder' => 'Bar City',
                        'city_default' => 'Baz City',
                        'state_province_region_international_label' => 'Foo State',
                        'state_province_region_international_placeholder' => 'Bar State',
                        'state_province_region_international_default' => 'Baz State',
                        'postal_code_label' => 'Foo ZIP',
                        'postal_code_placeholder' => 'Bar ZIP',
                        'postal_code_default' => 'Baz ZIP',
                        'country_label' => 'Foo Country',
                        'country_default' => 'AF',
                    ],
                ],
            ],
        ],
        'consent field' => [
            'source' => [
                \array_merge(
                    $baseField_consent,
                    []
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_126',
                    'field' => [
                        'type' => 'consent',
                        'display' => 'Test Field',
                        'checkbox_label' => 'I agree to the privacy policy.',
                        'consent_agreement' => "Foo.\nBar.\n\nBaz.", // double quotes to preserve newlines
                    ],
                ],
            ],
        ],
        'html field' => [
            'source' => [
                \array_merge(
                    $baseField_html,
                    []
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_128',
                    'field' => [
                        'type' => 'html',
                        'display' => 'Test Field',
                        'html' => "<ul>\n  <li>Lorem ipsum dolor sit amet consectetuer.</li>\n  <li>Aenean commodo ligula eget dolor.</li>\n  <li>Aenean massa cum sociis natoque penatibus.</li>\n</ul>", // double quotes to preserve newlines
                    ],
                ],
            ],
        ],
        'date field' => [
            'source' => [
                \array_merge(
                    $baseField_date,
                    []
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_127',
                    'field' => [
                        'type' => 'date',
                        'display' => 'Test Field',
                        'format' => 'm/d/Y',
                    ],
                ],
            ],
        ],
        'date field with date format dmy' => [
            'source' => [
                \array_merge(
                    $baseField_date,
                    ['dateFormat' => 'dmy']
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_127',
                    'field' => [
                        'type' => 'date',
                        'display' => 'Test Field',
                        'format' => 'd/m/Y',
                    ],
                ],
            ],
        ],
        'phone field with phone format international' => [
            'source' => [
                \array_merge(
                    $baseField_phone,
                    []
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_132',
                    'field' => [
                        'type' => 'phone',
                        'display' => 'Test Field',
                        'preset' => 'tel',
                    ],
                ],
            ],
        ],
        'phone field with phone format standard' => [
            'source' => [
                \array_merge(
                    $baseField_phone,
                    ['phoneFormat' => 'standard']
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_132',
                    'field' => [
                        'type' => 'phone',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'phone input type post custom field field' => [
            'source' => [
                \array_merge(
                    $baseField_post_custom_field_phone,
                    []
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_186',
                    'field' => [
                        'type' => 'phone',
                        'display' => 'Test Field',
                        'preset' => 'tel',
                    ],
                ],
            ],
        ],
        'number input type post custom field field and range max "1000" and range min "500"' => [
            'source' => [
                \array_merge(
                    $baseField_post_custom_field_number,
                    [
                        'rangeMax' => '1000',
                        'rangeMin' => '500',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_176',
                    'field' => [
                        'type' => 'float',
                        'display' => 'Test Field',
                        'min' => '500',
                        'max' => '1000',
                    ],
                ],
            ],
        ],
        'number fieldtype with number format currency' => [
            'source' => [
                \array_merge(
                    $baseField_number,
                    ['numberFormat' => 'currency']
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_131',
                    'field' => [
                        'type' => 'currency',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'number input type post custom field field and range max 1000 and range min 500' => [
            'source' => [
                \array_merge(
                    $baseField_post_custom_field_number,
                    [
                        'rangeMax' => 1000,
                        'rangeMin' => 500,
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_176',
                    'field' => [
                        'type' => 'float',
                        'display' => 'Test Field',
                        'min' => '500',
                        'max' => '1000',
                    ],
                ],
            ],
        ],
        'number input type post custom field field and range max false and range min false' => [
            'source' => [
                \array_merge(
                    $baseField_post_custom_field_number,
                    [
                        'rangeMax' => false,
                        'rangeMin' => false,
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_176',
                    'field' => [
                        'type' => 'float',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'number field with range max "1000" and range min "500"' => [
            'source' => [
                \array_merge(
                    $baseField_number,
                    [
                        'rangeMax' => '1000',
                        'rangeMin' => '500',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_131',
                    'field' => [
                        'type' => 'float',
                        'display' => 'Test Field',
                        'min' => '500',
                        'max' => '1000',
                    ],
                ],
            ],
        ],
        'number field with range max 1000 and range min 500' => [
            'source' => [
                \array_merge(
                    $baseField_number,
                    [
                        'rangeMax' => 1000,
                        'rangeMin' => 500,
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_131',
                    'field' => [
                        'type' => 'float',
                        'display' => 'Test Field',
                        'min' => '500',
                        'max' => '1000',
                    ],
                ],
            ],
        ],
        'number field with range max "1000"' => [
            'source' => [
                \array_merge(
                    $baseField_number,
                    ['rangeMax' => '1000']
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_131',
                    'field' => [
                        'type' => 'float',
                        'display' => 'Test Field',
                        'max' => '1000',
                    ],
                ],
            ],
        ],
        'number field with range max 1000' => [
            'source' => [
                \array_merge(
                    $baseField_number,
                    ['rangeMax' => 1000]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_131',
                    'field' => [
                        'type' => 'float',
                        'display' => 'Test Field',
                        'max' => '1000',
                    ],
                ],
            ],
        ],
        'number field with range min "500"' => [
            'source' => [
                \array_merge(
                    $baseField_number,
                    ['rangeMin' => '500']
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_131',
                    'field' => [
                        'type' => 'float',
                        'display' => 'Test Field',
                        'min' => '500',
                    ],
                ],
            ],
        ],
        'number field with range min 500' => [
            'source' => [
                \array_merge(
                    $baseField_number,
                    ['rangeMin' => 500]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_131',
                    'field' => [
                        'type' => 'float',
                        'display' => 'Test Field',
                        'min' => '500',
                    ],
                ],
            ],
        ],
        'number field with range max false and range min false' => [
            'source' => [
                \array_merge(
                    $baseField_number,
                    [
                        'rangeMax' => false,
                        'rangeMin' => false,
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_131',
                    'field' => [
                        'type' => 'float',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'radio input type multi-choice field' => [
            'source' => [
                \array_merge(
                    $baseField_multi_choice_radio,
                    []
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_130',
                    'field' => [
                        'type' => 'radio',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'First Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Second Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Third Choice',
                                'value' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'multiple choice field checkbox unlimited' => [
            'source' => [
                \array_merge(
                    $baseField_multi_choice_checkbox_unlimited,
                    []
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_142',
                    'field' => [
                        'type' => 'checkboxes',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'First Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Second Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Third Choice',
                                'value' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'multiple choice field checkbox exactly' => [
            'source' => [
                \array_merge(
                    $baseField_multi_choice_checkbox_exactly,
                    []
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_143',
                    'field' => [
                        'type' => 'checkboxes',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'First Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Second Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Third Choice',
                                'value' => null,
                            ],
                        ],
                        'selection_limit' => 'exact',
                        'exact_selections' => '2',
                    ],
                ],
            ],
        ],
        'multiple choice field checkbox range' => [
            'source' => [
                \array_merge(
                    $baseField_multi_choice_checkbox_range,
                    []
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_144',
                    'field' => [
                        'type' => 'checkboxes',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'First Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Second Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Third Choice',
                                'value' => null,
                            ],
                        ],
                        'selection_limit' => 'range',
                        'min_selections' => '2',
                        'max_selections' => '3',
                    ],
                ],
            ],
        ],
        'list field with max rows' => [
            'source' => [
                \array_merge(
                    $baseField_list,
                    ['maxRows' => 10]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_129',
                    'field' => [
                        'type' => 'list',
                        'display' => 'Test Field',
                        'max_items' => 10,
                    ],
                ],
            ],
        ],
        'list field with enable columns and no max rows' => [
            'source' => [
                \array_merge(
                    $baseField_list,
                    [
                        'enableColumns' => true,
                        'choices' => [
                            [
                                'text' => 'Foo',
                                'value' => 'Foo',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Bar',
                                'value' => 'Bar',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Baz',
                                'value' => 'Baz',
                                'isSelected' => false,
                                'price' => '',
                            ],
                        ],
                        'layoutGridColumnSpan' => 12,
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_129',
                    'field' => [
                        'type' => 'grid',
                        'display' => 'Test Field',
                        'mode' => 'stacked',
                        'fields' => [
                            [
                                'handle' => 'test_field_129_foo_1',
                                'field' => [
                                    'type' => 'text',
                                    'display' => 'Foo',
                                ],
                            ],
                            [
                                'handle' => 'test_field_129_bar_2',
                                'field' => [
                                    'type' => 'text',
                                    'display' => 'Bar',
                                ],
                            ],
                            [
                                'handle' => 'test_field_129_baz_3',
                                'field' => [
                                    'type' => 'text',
                                    'display' => 'Baz',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'list field with enable columns and max rows' => [
            'source' => [
                \array_merge(
                    $baseField_list,
                    [
                        'maxRows' => 10,
                        'enableColumns' => true,
                        'choices' => [
                            [
                                'text' => 'Foo',
                                'value' => 'Foo',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Bar',
                                'value' => 'Bar',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Baz',
                                'value' => 'Baz',
                                'isSelected' => false,
                                'price' => '',
                            ],
                        ],
                        'layoutGridColumnSpan' => 12,
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_129',
                    'field' => [
                        'type' => 'grid',
                        'display' => 'Test Field',
                        'mode' => 'stacked',
                        'max_rows' => 10,
                        'fields' => [
                            [
                                'handle' => 'test_field_129_foo_1',
                                'field' => [
                                    'type' => 'text',
                                    'display' => 'Foo',
                                ],
                            ],
                            [
                                'handle' => 'test_field_129_bar_2',
                                'field' => [
                                    'type' => 'text',
                                    'display' => 'Bar',
                                ],
                            ],
                            [
                                'handle' => 'test_field_129_baz_3',
                                'field' => [
                                    'type' => 'text',
                                    'display' => 'Baz',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'survey field likert scale 1 without multiple rows' => [
            'source' => [
                \array_merge(
                    $baseField_survey_likert,
                    [
                        'gsurveyLikertEnableMultipleRows' => false,
                        'choices' => [
                            [
                                'text' => 'Strongly disagree',
                                'value' => 'glikertcol001',
                                'isSelected' => false,
                                'score' => 1,
                            ],
                            [
                                'text' => 'Disagree',
                                'value' => 'glikertcol002',
                                'isSelected' => false,
                                'score' => 2,
                            ],
                            [
                                'text' => 'Neutral',
                                'value' => 'glikertcol003',
                                'isSelected' => false,
                                'score' => 3,
                            ],
                            [
                                'text' => 'Agree',
                                'value' => 'glikertcol004',
                                'isSelected' => false,
                                'score' => 4,
                            ],
                            [
                                'text' => 'Strongly agree',
                                'value' => 'glikertcol005',
                                'isSelected' => false,
                                'score' => 5,
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_136',
                    'field' => [
                        'type' => 'likert_scale',
                        'display' => 'Test Field',
                        'scale_type' => 'agreement',
                        'number_of_points' => 5,
                        'enable_not_applicable' => false,
                        'display_order' => 'asc',
                        'reverse_scale' => false,
                    ],
                ],
            ],
        ],
        'survey field likert scale 1 with enable scoring true and without multiple rows' => [
            'source' => [
                \array_merge(
                    $baseField_survey_likert,
                    [
                        'gsurveyLikertEnableScoring' => true,
                        'gsurveyLikertEnableMultipleRows' => false,
                        'choices' => [
                            [
                                'text' => 'Strongly disagree',
                                'value' => 'glikertcol001',
                                'isSelected' => false,
                                'score' => 1,
                            ],
                            [
                                'text' => 'Disagree',
                                'value' => 'glikertcol002',
                                'isSelected' => false,
                                'score' => 2,
                            ],
                            [
                                'text' => 'Neutral',
                                'value' => 'glikertcol003',
                                'isSelected' => false,
                                'score' => 3,
                            ],
                            [
                                'text' => 'Agree',
                                'value' => 'glikertcol004',
                                'isSelected' => false,
                                'score' => 4,
                            ],
                            [
                                'text' => 'Strongly agree',
                                'value' => 'glikertcol005',
                                'isSelected' => false,
                                'score' => 5,
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_136',
                    'field' => [
                        'type' => 'likert_scale',
                        'display' => 'Test Field',
                        'scale_type' => 'agreement',
                        'number_of_points' => 5,
                        'enable_not_applicable' => false,
                        'display_order' => 'asc',
                        'reverse_scale' => false,
                    ],
                ],
            ],
        ],
        'survey field likert scale 2' => [
            'source' => [
                \array_merge(
                    $baseField_survey_likert,
                    [
                        'gsurveyLikertEnableMultipleRows' => false,
                        'choices' => [
                            [
                                'text' => 'Absent-0',
                                'value' => 'glikertcol000',
                                'isSelected' => false,
                                'score' => '0',
                            ],
                            [
                                'text' => 'Fair-1',
                                'value' => 'glikertcol000',
                                'isSelected' => false,
                                'score' => '1',
                            ],
                            [
                                'text' => 'Good-2',
                                'value' => 'glikertcol000',
                                'isSelected' => false,
                                'score' => '2',
                            ],
                            [
                                'text' => 'Excellent-3',
                                'value' => 'glikertcol000',
                                'isSelected' => false,
                                'score' => '3',
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_136',
                    'field' => [
                        'type' => 'likert_scale',
                        'display' => 'Test Field',
                        'scale_type' => 'quality',
                        'number_of_points' => 3,
                        'enable_not_applicable' => true,
                        'not_applicable_label' => 'Absent',
                        'display_order' => 'asc',
                        'reverse_scale' => false,
                    ],
                ],
            ],
        ],
        'survey field likert scale 3' => [
            'source' => [
                \array_merge(
                    $baseField_survey_likert,
                    [
                        'gsurveyLikertEnableMultipleRows' => false,
                        'choices' => [
                            [
                                'text' => 'Absent-0',
                                'value' => 'glikertcol000',
                                'isSelected' => false,
                                'score' => '1',
                            ],
                            [
                                'text' => 'Fair-1',
                                'value' => 'glikertcol000',
                                'isSelected' => false,
                                'score' => '2',
                            ],
                            [
                                'text' => 'Good-2',
                                'value' => 'glikertcol000',
                                'isSelected' => false,
                                'score' => '3',
                            ],
                            [
                                'text' => 'Excellent-3',
                                'value' => 'glikertcol000',
                                'isSelected' => false,
                                'score' => '4',
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_136',
                    'field' => [
                        'type' => 'likert_scale',
                        'display' => 'Test Field',
                        'scale_type' => 'quality',
                        'number_of_points' => 3,
                        'enable_not_applicable' => true,
                        'not_applicable_label' => 'Absent',
                        'display_order' => 'asc',
                        'reverse_scale' => false,
                    ],
                ],
            ],
        ],
        'survey field likert scale 4' => [
            'source' => [
                \array_merge(
                    $baseField_survey_likert,
                    [
                        'gsurveyLikertEnableMultipleRows' => false,
                        'choices' => [
                            [
                                'text' => 'Absent- 0',
                                'value' => 'glikertcol000',
                                'isSelected' => false,
                                'score' => '1',
                            ],
                            [
                                'text' => 'Fair- 1',
                                'value' => 'glikertcol000',
                                'isSelected' => false,
                                'score' => '2',
                            ],
                            [
                                'text' => 'Good- 2',
                                'value' => 'glikertcol000',
                                'isSelected' => false,
                                'score' => '3',
                            ],
                            [
                                'text' => 'Excellent- 3',
                                'value' => 'glikertcol000',
                                'isSelected' => false,
                                'score' => '4',
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_136',
                    'field' => [
                        'type' => 'likert_scale',
                        'display' => 'Test Field',
                        'scale_type' => 'quality',
                        'number_of_points' => 3,
                        'enable_not_applicable' => true,
                        'not_applicable_label' => 'Absent',
                        'display_order' => 'asc',
                        'reverse_scale' => false,
                    ],
                ],
            ],
        ],
        'survey field likert scale 5' => [
            'source' => [
                \array_merge(
                    $baseField_survey_likert,
                    [
                        'gsurveyLikertEnableMultipleRows' => false,
                        'choices' => [
                            [
                                'text' => 'Never',
                                'value' => 'glikertcol000',
                                'isSelected' => false,
                                'score' => '1',
                            ],
                            [
                                'text' => 'Rarely',
                                'value' => 'glikertcol000',
                                'isSelected' => false,
                                'score' => '2',
                            ],
                            [
                                'text' => 'Sometimes',
                                'value' => 'glikertcol000',
                                'isSelected' => false,
                                'score' => '3',
                            ],
                            [
                                'text' => 'Often',
                                'value' => 'glikertcol000',
                                'isSelected' => false,
                                'score' => '4',
                            ],
                            [
                                'text' => 'Very Often',
                                'value' => 'glikertcol000',
                                'isSelected' => false,
                                'score' => '5',
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_136',
                    'field' => [
                        'type' => 'likert_scale',
                        'display' => 'Test Field',
                        'scale_type' => 'frequency_no_always',
                        'number_of_points' => 5,
                        'enable_not_applicable' => false,
                        'display_order' => 'asc',
                        'reverse_scale' => false,
                    ],
                ],
            ],
        ],
        'survey field likert scale 6' => [
            'source' => [
                \array_merge(
                    $baseField_survey_likert,
                    [
                        'gsurveyLikertEnableMultipleRows' => false,
                        'choices' => [
                            [
                                'text' => 'Never',
                                'value' => 'glikertcol000',
                                'isSelected' => false,
                                'score' => '5',
                            ],
                            [
                                'text' => 'Rarely',
                                'value' => 'glikertcol000',
                                'isSelected' => false,
                                'score' => '4',
                            ],
                            [
                                'text' => 'Sometimes',
                                'value' => 'glikertcol000',
                                'isSelected' => false,
                                'score' => '3',
                            ],
                            [
                                'text' => 'Often',
                                'value' => 'glikertcol000',
                                'isSelected' => false,
                                'score' => '2',
                            ],
                            [
                                'text' => 'Very Often',
                                'value' => 'glikertcol000',
                                'isSelected' => false,
                                'score' => '1',
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_136',
                    'field' => [
                        'type' => 'likert_scale',
                        'display' => 'Test Field',
                        'scale_type' => 'frequency_no_always',
                        'number_of_points' => 5,
                        'enable_not_applicable' => false,
                        'display_order' => 'asc',
                        'reverse_scale' => true,
                    ],
                ],
            ],
        ],
        'survey field likert scale 7' => [
            'source' => [
                \array_merge(
                    $baseField_survey_likert,
                    [
                        'gsurveyLikertEnableMultipleRows' => false,
                        'choices' => [
                            [
                                'text' => 'STRONGLY DISAGREE',
                                'value' => 'glikertcol000',
                                'isSelected' => false,
                                'score' => '1',
                            ],
                            [
                                'text' => 'DISAGREE',
                                'value' => 'glikertcol000',
                                'isSelected' => false,
                                'score' => '2',
                            ],
                            [
                                'text' => 'AGREE',
                                'value' => 'glikertcol000',
                                'isSelected' => false,
                                'score' => '3',
                            ],
                            [
                                'text' => 'STRONGLY AGREE',
                                'value' => 'glikertcol000',
                                'isSelected' => false,
                                'score' => '4',
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_136',
                    'field' => [
                        'type' => 'likert_scale',
                        'display' => 'Test Field',
                        'scale_type' => 'agreement',
                        'number_of_points' => 4,
                        'enable_not_applicable' => false,
                        'display_order' => 'asc',
                        'reverse_scale' => false,
                    ],
                ],
            ],
        ],
        'survey field likert scale 8' => [
            'source' => [
                \array_merge(
                    $baseField_survey_likert,
                    [
                        'gsurveyLikertEnableMultipleRows' => false,
                        'choices' => [
                            [
                                'text' => 'VERY DISSATISFIED',
                                'value' => 'glikertcol000',
                                'isSelected' => false,
                                'score' => '1',
                            ],
                            [
                                'text' => 'MODERATELY DISSATISFIED',
                                'value' => 'glikertcol000',
                                'isSelected' => false,
                                'score' => '2',
                            ],
                            [
                                'text' => 'NEITHER SATISFIED NOR DISSATISFIED',
                                'value' => 'glikertcol000',
                                'isSelected' => false,
                                'score' => '3',
                            ],
                            [
                                'text' => 'MODERATELY SATISFIED',
                                'value' => 'glikertcol000',
                                'isSelected' => false,
                                'score' => '4',
                            ],
                            [
                                'text' => 'VERY SATISFIED',
                                'value' => 'glikertcol000',
                                'isSelected' => false,
                                'score' => '5',
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_136',
                    'field' => [
                        'type' => 'likert_scale',
                        'display' => 'Test Field',
                        'scale_type' => 'satisfaction',
                        'number_of_points' => 5,
                        'enable_not_applicable' => false,
                        'display_order' => 'asc',
                        'reverse_scale' => false,
                    ],
                ],
            ],
        ],
        'survey field likert scale 9' => [
            'source' => [
                \array_merge(
                    $baseField_survey_likert,
                    [
                        'gsurveyLikertEnableMultipleRows' => false,
                        'choices' => [
                            [
                                'text' => 'STRONGLY DISAGREE',
                                'value' => 'glikertcol000',
                                'isSelected' => false,
                                'score' => '1',
                            ],
                            [
                                'text' => 'DISAGREE',
                                'value' => 'glikertcol000',
                                'isSelected' => false,
                                'score' => '2',
                            ],
                            [
                                'text' => 'AGREE',
                                'value' => 'glikertcol000',
                                'isSelected' => false,
                                'score' => '4',
                            ],
                            [
                                'text' => 'STRONGLY AGREE',
                                'value' => 'glikertcol000',
                                'isSelected' => false,
                                'score' => '5',
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_136',
                    'field' => [
                        'type' => 'likert_scale',
                        'display' => 'Test Field',
                        'scale_type' => 'agreement',
                        'number_of_points' => 5,
                        'enable_not_applicable' => false,
                        'display_order' => 'asc',
                        'reverse_scale' => false,
                    ],
                ],
            ],
        ],
        'survey field likert scale 1 with multiple rows' => [
            'source' => [
                \array_merge(
                    $baseField_survey_likert,
                    [
                        'gsurveyLikertEnableMultipleRows' => true,
                        'choices' => [
                            [
                                'text' => 'Strongly disagree',
                                'value' => 'glikertcol001',
                                'isSelected' => false,
                                'score' => 1,
                            ],
                            [
                                'text' => 'Disagree',
                                'value' => 'glikertcol002',
                                'isSelected' => false,
                                'score' => 2,
                            ],
                            [
                                'text' => 'Neutral',
                                'value' => 'glikertcol003',
                                'isSelected' => false,
                                'score' => 3,
                            ],
                            [
                                'text' => 'Agree',
                                'value' => 'glikertcol004',
                                'isSelected' => false,
                                'score' => 4,
                            ],
                            [
                                'text' => 'Strongly agree',
                                'value' => 'glikertcol005',
                                'isSelected' => false,
                                'score' => 5,
                            ],
                        ],
                        'gsurveyLikertRows' => [
                            [
                                'text' => 'First row',
                                'value' => 'glikertrowd7085989',
                            ],
                            [
                                'text' => 'Second row',
                                'value' => 'glikertrowc575a29d',
                            ],
                            [
                                'text' => 'Third row',
                                'value' => 'glikertrowaea764c0',
                            ],
                            [
                                'text' => 'Fourth row',
                                'value' => 'glikertrow8904d614',
                            ],
                            [
                                'text' => 'Fifth row',
                                'value' => 'glikertrow9931ec5b',
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_136',
                    'field' => [
                        'type' => 'likert_scale',
                        'display' => 'Test Field',
                        'scale_type' => 'agreement',
                        'number_of_points' => 5,
                        'enable_not_applicable' => false,
                        'display_order' => 'asc',
                        'reverse_scale' => false,
                        'enable_multiple_rows' => true,
                        'multiple_rows' => [
                            'First row',
                            'Second row',
                            'Third row',
                            'Fourth row',
                            'Fifth row',
                        ],
                    ],
                ],
            ],
        ],
        'invalid survey field likert 1' => [
            'source' => [
                \array_merge(
                    $baseField_survey_likert,
                    [
                        'gsurveyLikertEnableMultipleRows' => false,
                        'choices' => [
                            [
                                'text' => 'I like the Seeds of Science Program as is',
                                'value' => 'glikertcol001',
                                'isSelected' => false,
                                'score' => '1',
                            ],
                            [
                                'text' => 'I like the Arkansas Breast Cancer Research Program (ABCRP) pilot funding program as is',
                                'value' => 'glikertcol002',
                                'isSelected' => false,
                                'score' => '2',
                            ],
                            [
                                'text' => 'I would like all available funds to support cancer-related K awards similar to what is made available through the TRI.',
                                'value' => 'glikertcol003',
                                'isSelected' => false,
                                'score' => '3',
                            ],
                            [
                                'text' => 'I would like some of the available funds to support cancer-related K awards similar to what is made available through the TRI.',
                                'value' => 'glikertcol004',
                                'isSelected' => false,
                                'score' => '4',
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_136',
                    'field' => [
                        'type' => 'radio',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => '1',
                                'value' => 'I like the Seeds of Science Program as is',
                            ],
                            [
                                'key' => '2',
                                'value' => 'I like the Arkansas Breast Cancer Research Program (ABCRP) pilot funding program as is',
                            ],
                            [
                                'key' => '3',
                                'value' => 'I would like all available funds to support cancer-related K awards similar to what is made available through the TRI.',
                            ],
                            [
                                'key' => '4',
                                'value' => 'I would like some of the available funds to support cancer-related K awards similar to what is made available through the TRI.',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'invalid survey field likert 2' => [
            'source' => [
                \array_merge(
                    $baseField_survey_likert,
                    [
                        'gsurveyLikertEnableMultipleRows' => false,
                        'choices' => [
                            [
                                'text' => '$10,000',
                                'value' => 'glikertcol001',
                                'isSelected' => false,
                                'score' => '1',
                            ],
                            [
                                'text' => '$25,000',
                                'value' => 'glikertcol002',
                                'isSelected' => false,
                                'score' => '2',
                            ],
                            [
                                'text' => '$50,000',
                                'value' => 'glikertcol003',
                                'isSelected' => false,
                                'score' => '3',
                            ],
                            [
                                'text' => '$75,000',
                                'value' => 'glikertcol004',
                                'isSelected' => false,
                                'score' => '4',
                            ],
                            [
                                'text' => '$100,000',
                                'value' => 'glikertcol005',
                                'isSelected' => false,
                                'score' => '5',
                            ],
                            [
                                'text' => 'Other',
                                'value' => 'glikertcol006',
                                'isSelected' => false,
                                'score' => '6',
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_136',
                    'field' => [
                        'type' => 'radio',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => '1',
                                'value' => '$10,000',
                            ],
                            [
                                'key' => '2',
                                'value' => '$25,000',
                            ],
                            [
                                'key' => '3',
                                'value' => '$50,000',
                            ],
                            [
                                'key' => '4',
                                'value' => '$75,000',
                            ],
                            [
                                'key' => '5',
                                'value' => '$100,000',
                            ],
                            [
                                'key' => '6',
                                'value' => 'Other',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'rating field' => [
            'source' => [
                \array_merge(
                    $baseField_survey_rating,
                    []
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_145',
                    'field' => [
                        'type' => 'radio',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'Terrible',
                                'value' => null,
                            ],
                            [
                                'key' => 'Not so great',
                                'value' => null,
                            ],
                            [
                                'key' => 'Neutral',
                                'value' => null,
                            ],
                            [
                                'key' => 'Pretty good',
                                'value' => null,
                            ],
                            [
                                'key' => 'Excellent',
                                'value' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'product field single' => [
            'source' => [
                \array_merge(
                    $baseField_product,
                    []
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_135',
                    'field' => [
                        'type' => 'product',
                        'display' => 'Test Field',
                        'price' => 42.13,
                    ],
                ],
            ],
        ],
        'product field single with disable quantity' => [
            'source' => [
                \array_merge(
                    $baseField_product,
                    ['disableQuantity' => true]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_135',
                    'field' => [
                        'type' => 'product',
                        'display' => 'Test Field',
                        'price' => 42.13,
                        'disable_quantity' => true,
                    ],
                ],
            ],
        ],
        'product field multiple without separate value' => [
            'source' => [
                \array_merge(
                    $baseField_product,
                    [
                        'inputType' => 'radio',
                        'choices' => [
                            [
                                'text' => 'First Choice',
                                'value' => 'First Choice',
                                'isSelected' => false,
                                'price' => '$42.13',
                            ],
                            [
                                'text' => 'Second Choice',
                                'value' => 'Second Choice',
                                'isSelected' => false,
                                'price' => '$13.42',
                            ],
                            [
                                'text' => 'Third Choice',
                                'value' => 'Third Choice',
                                'isSelected' => false,
                                'price' => '$0.42',
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_135',
                    'field' => [
                        'type' => 'product',
                        'display' => 'Test Field',
                        'selection_type' => 'multiple',
                        'options' => [
                            [
                                'id' => 'test_field_135_first_choice_1',
                                'key' => 'test_field_135_first_choice_1',
                                'label' => 'First Choice',
                                'price' => 42.13,
                            ],
                            [
                                'id' => 'test_field_135_second_choice_2',
                                'key' => 'test_field_135_second_choice_2',
                                'label' => 'Second Choice',
                                'price' => 13.42,
                            ],
                            [
                                'id' => 'test_field_135_third_choice_3',
                                'key' => 'test_field_135_third_choice_3',
                                'label' => 'Third Choice',
                                'price' => 0.42,
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'product field multiple with separate value' => [
            'source' => [
                \array_merge(
                    $baseField_product,
                    [
                        'inputType' => 'radio',
                        'enableChoiceValue' => true,
                        'choices' => [
                            [
                                'text' => 'First Choice',
                                'value' => 'foo',
                                'isSelected' => false,
                                'price' => '$42.13',
                            ],
                            [
                                'text' => 'Second Choice',
                                'value' => 'bar',
                                'isSelected' => false,
                                'price' => '$13.42',
                            ],
                            [
                                'text' => 'Third Choice',
                                'value' => 'baz',
                                'isSelected' => false,
                                'price' => '$0.42',
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_135',
                    'field' => [
                        'type' => 'product',
                        'display' => 'Test Field',
                        'selection_type' => 'multiple',
                        'options' => [
                            [
                                'id' => 'test_field_135_foo_1',
                                'key' => 'test_field_135_first_choice_1',
                                'label' => 'First Choice',
                                'price' => 42.13,
                            ],
                            [
                                'id' => 'test_field_135_bar_2',
                                'key' => 'test_field_135_second_choice_2',
                                'label' => 'Second Choice',
                                'price' => 13.42,
                            ],
                            [
                                'id' => 'test_field_135_baz_3',
                                'key' => 'test_field_135_third_choice_3',
                                'label' => 'Third Choice',
                                'price' => 0.42,
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'product field multiple with base price and without separate value' => [
            'source' => [
                \array_merge(
                    $baseField_product,
                    [
                        'inputType' => 'radio',
                        'choices' => [
                            [
                                'text' => 'First Choice',
                                'value' => 'First Choice',
                                'isSelected' => false,
                                'price' => '$42.13',
                            ],
                            [
                                'text' => 'Second Choice',
                                'value' => 'Second Choice',
                                'isSelected' => false,
                                'price' => '$13.42',
                            ],
                            [
                                'text' => 'Third Choice',
                                'value' => 'Third Choice',
                                'isSelected' => false,
                                'price' => '$0.42',
                            ],
                        ],
                        'basePrice' => '$42.13',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_135',
                    'field' => [
                        'type' => 'product',
                        'display' => 'Test Field',
                        'selection_type' => 'multiple',
                        'options' => [
                            [
                                'id' => 'test_field_135_first_choice_1',
                                'key' => 'test_field_135_first_choice_1',
                                'label' => 'First Choice',
                                'price' => 42.13,
                            ],
                            [
                                'id' => 'test_field_135_second_choice_2',
                                'key' => 'test_field_135_second_choice_2',
                                'label' => 'Second Choice',
                                'price' => 13.42,
                            ],
                            [
                                'id' => 'test_field_135_third_choice_3',
                                'key' => 'test_field_135_third_choice_3',
                                'label' => 'Third Choice',
                                'price' => 0.42,
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'product field multiple with base price and separate value' => [
            'source' => [
                \array_merge(
                    $baseField_product,
                    [
                        'inputType' => 'radio',
                        'enableChoiceValue' => true,
                        'choices' => [
                            [
                                'text' => 'First Choice',
                                'value' => 'foo',
                                'isSelected' => false,
                                'price' => '$42.13',
                            ],
                            [
                                'text' => 'Second Choice',
                                'value' => 'bar',
                                'isSelected' => false,
                                'price' => '$13.42',
                            ],
                            [
                                'text' => 'Third Choice',
                                'value' => 'baz',
                                'isSelected' => false,
                                'price' => '$0.42',
                            ],
                        ],
                        'basePrice' => '$42.13',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_135',
                    'field' => [
                        'type' => 'product',
                        'display' => 'Test Field',
                        'selection_type' => 'multiple',
                        'options' => [
                            [
                                'id' => 'test_field_135_foo_1',
                                'key' => 'test_field_135_first_choice_1',
                                'label' => 'First Choice',
                                'price' => 42.13,
                            ],
                            [
                                'id' => 'test_field_135_bar_2',
                                'key' => 'test_field_135_second_choice_2',
                                'label' => 'Second Choice',
                                'price' => 13.42,
                            ],
                            [
                                'id' => 'test_field_135_baz_3',
                                'key' => 'test_field_135_third_choice_3',
                                'label' => 'Third Choice',
                                'price' => 0.42,
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'product field multiple with enable price true and without separate value' => [
            'source' => [
                \array_merge(
                    $baseField_product,
                    [
                        'inputType' => 'radio',
                        'enablePrice' => true,
                        'choices' => [
                            [
                                'text' => 'First Choice',
                                'value' => 'First Choice',
                                'isSelected' => false,
                                'price' => '$42.13',
                            ],
                            [
                                'text' => 'Second Choice',
                                'value' => 'Second Choice',
                                'isSelected' => false,
                                'price' => '$13.42',
                            ],
                            [
                                'text' => 'Third Choice',
                                'value' => 'Third Choice',
                                'isSelected' => false,
                                'price' => '$0.42',
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_135',
                    'field' => [
                        'type' => 'product',
                        'display' => 'Test Field',
                        'selection_type' => 'multiple',
                        'options' => [
                            [
                                'id' => 'test_field_135_first_choice_1',
                                'key' => 'test_field_135_first_choice_1',
                                'label' => 'First Choice',
                                'price' => 42.13,
                            ],
                            [
                                'id' => 'test_field_135_second_choice_2',
                                'key' => 'test_field_135_second_choice_2',
                                'label' => 'Second Choice',
                                'price' => 13.42,
                            ],
                            [
                                'id' => 'test_field_135_third_choice_3',
                                'key' => 'test_field_135_third_choice_3',
                                'label' => 'Third Choice',
                                'price' => 0.42,
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'product field multiple with enable price true and separate value' => [
            'source' => [
                \array_merge(
                    $baseField_product,
                    [
                        'inputType' => 'radio',
                        'enableChoiceValue' => true,
                        'enablePrice' => true,
                        'choices' => [
                            [
                                'text' => 'First Choice',
                                'value' => 'foo',
                                'isSelected' => false,
                                'price' => '$42.13',
                            ],
                            [
                                'text' => 'Second Choice',
                                'value' => 'bar',
                                'isSelected' => false,
                                'price' => '$13.42',
                            ],
                            [
                                'text' => 'Third Choice',
                                'value' => 'baz',
                                'isSelected' => false,
                                'price' => '$0.42',
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_135',
                    'field' => [
                        'type' => 'product',
                        'display' => 'Test Field',
                        'selection_type' => 'multiple',
                        'options' => [
                            [
                                'id' => 'test_field_135_foo_1',
                                'key' => 'test_field_135_first_choice_1',
                                'label' => 'First Choice',
                                'price' => 42.13,
                            ],
                            [
                                'id' => 'test_field_135_bar_2',
                                'key' => 'test_field_135_second_choice_2',
                                'label' => 'Second Choice',
                                'price' => 13.42,
                            ],
                            [
                                'id' => 'test_field_135_baz_3',
                                'key' => 'test_field_135_third_choice_3',
                                'label' => 'Third Choice',
                                'price' => 0.42,
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'product field multiple with enable price true and base price and without separate value' => [
            'source' => [
                \array_merge(
                    $baseField_product,
                    [
                        'inputType' => 'radio',
                        'enablePrice' => true,
                        'choices' => [
                            [
                                'text' => 'First Choice',
                                'value' => 'First Choice',
                                'isSelected' => false,
                                'price' => '$42.13',
                            ],
                            [
                                'text' => 'Second Choice',
                                'value' => 'Second Choice',
                                'isSelected' => false,
                                'price' => '$13.42',
                            ],
                            [
                                'text' => 'Third Choice',
                                'value' => 'Third Choice',
                                'isSelected' => false,
                                'price' => '$0.42',
                            ],
                        ],
                        'basePrice' => '$42.13',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_135',
                    'field' => [
                        'type' => 'product',
                        'display' => 'Test Field',
                        'selection_type' => 'multiple',
                        'options' => [
                            [
                                'id' => 'test_field_135_first_choice_1',
                                'key' => 'test_field_135_first_choice_1',
                                'label' => 'First Choice',
                                'price' => 42.13,
                            ],
                            [
                                'id' => 'test_field_135_second_choice_2',
                                'key' => 'test_field_135_second_choice_2',
                                'label' => 'Second Choice',
                                'price' => 13.42,
                            ],
                            [
                                'id' => 'test_field_135_third_choice_3',
                                'key' => 'test_field_135_third_choice_3',
                                'label' => 'Third Choice',
                                'price' => 0.42,
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'product field multiple with enable price true and base price and separate value' => [
            'source' => [
                \array_merge(
                    $baseField_product,
                    [
                        'inputType' => 'radio',
                        'enableChoiceValue' => true,
                        'enablePrice' => true,
                        'choices' => [
                            [
                                'text' => 'First Choice',
                                'value' => 'foo',
                                'isSelected' => false,
                                'price' => '$42.13',
                            ],
                            [
                                'text' => 'Second Choice',
                                'value' => 'bar',
                                'isSelected' => false,
                                'price' => '$13.42',
                            ],
                            [
                                'text' => 'Third Choice',
                                'value' => 'baz',
                                'isSelected' => false,
                                'price' => '$0.42',
                            ],
                        ],
                        'basePrice' => '$42.13',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_135',
                    'field' => [
                        'type' => 'product',
                        'display' => 'Test Field',
                        'selection_type' => 'multiple',
                        'options' => [
                            [
                                'id' => 'test_field_135_foo_1',
                                'key' => 'test_field_135_first_choice_1',
                                'label' => 'First Choice',
                                'price' => 42.13,
                            ],
                            [
                                'id' => 'test_field_135_bar_2',
                                'key' => 'test_field_135_second_choice_2',
                                'label' => 'Second Choice',
                                'price' => 13.42,
                            ],
                            [
                                'id' => 'test_field_135_baz_3',
                                'key' => 'test_field_135_third_choice_3',
                                'label' => 'Third Choice',
                                'price' => 0.42,
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'text field with visibility administrative' => [
            'source' => [
                \array_merge(
                    $baseField_text,
                    ['visibility' => 'administrative']
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                        'visibility' => 'hidden',
                    ],
                ],
            ],
        ],
        'text field with visibility hidden' => [
            'source' => [
                \array_merge(
                    $baseField_text,
                    ['visibility' => 'hidden']
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                        'visibility' => 'hidden',
                    ],
                ],
            ],
        ],
        'text field with visibility visible' => [
            'source' => [
                \array_merge(
                    $baseField_text,
                    ['visibility' => 'visible']
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'text field with enable autocomplete true and autocomplete attribute name' => [
            'source' => [
                \array_merge(
                    $baseField_text,
                    [
                        'enableAutocomplete' => true,
                        'autocompleteAttribute' => 'name',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                        'autocomplete_query' => 'on',
                        'autocomplete' => 'name',
                    ],
                ],
            ],
        ],
        'text field with input mask true input mask is custom true input mask value 99/99/9999' => [
            'source' => [
                \array_merge($baseField_text, [
                    'inputMask' => true,
                    'inputMaskValue' => '99\/99\/9999',
                    'inputMaskIsCustom' => true,
                ]),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                        'input_mask' => true,
                        'input_mask_mask_MaskedPattern' => '00/00/0000',
                    ],
                ],
            ],
        ],
        'text field with input mask true input mask is custom true input mask value 999-99-9999' => [
            'source' => [
                \array_merge($baseField_text, [
                    'inputMask' => true,
                    'inputMaskValue' => '999-99-9999',
                    'inputMaskIsCustom' => true,
                ]),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                        'input_mask' => true,
                        'input_mask_mask_MaskedPattern' => '000-00-0000',
                    ],
                ],
            ],
        ],
        'text field with input mask true input mask is custom true input mask value aaa 999' => [
            'source' => [
                \array_merge($baseField_text, [
                    'inputMask' => true,
                    'inputMaskValue' => 'aaa 999',
                    'inputMaskIsCustom' => true,
                ]),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                        'input_mask' => true,
                        'input_mask_mask_MaskedPattern' => 'aaa 000',
                    ],
                ],
            ],
        ],
        'text field with input mask true input mask is custom true input mask value ***-***-***' => [
            'source' => [
                \array_merge($baseField_text, [
                    'inputMask' => true,
                    'inputMaskValue' => '***-***-***',
                    'inputMaskIsCustom' => true,
                ]),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                        'input_mask' => true,
                        'input_mask_mask_MaskedPattern' => '###-###-###',
                    ],
                ],
            ],
        ],
        'text field with input mask true input mask is custom true input mask value 99999?-9999' => [
            'source' => [
                \array_merge($baseField_text, [
                    'inputMask' => true,
                    'inputMaskValue' => '99999?-9999',
                    'inputMaskIsCustom' => true,
                ]),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                        'input_mask' => true,
                        'input_mask_mask_MaskedPattern' => '00000[-0000]',
                    ],
                ],
            ],
        ],
        'text field with input mask true input mask is custom false field_mask_select not set' => [
            'source' => [
                \array_merge($baseField_text, [
                    'inputMask' => true,
                    'inputMaskValue' => '',
                    'inputMaskIsCustom' => false,
                ]),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'text field with input mask true input mask is custom false field_mask_select us phone' => [
            'source' => [
                \array_merge($baseField_text, [
                    'inputMask' => true,
                    'inputMaskValue' => '(999) 999-9999',
                    'inputMaskIsCustom' => false,
                ]),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                        'input_mask' => true,
                        'input_mask_mask_MaskedPattern' => '(000) 000-0000',
                    ],
                ],
            ],
        ],
        'text field with input mask true input mask is custom false field_mask_select us phone + ext' => [
            'source' => [
                \array_merge($baseField_text, [
                    'inputMask' => true,
                    'inputMaskValue' => '(999) 999-9999? x99999',
                    'inputMaskIsCustom' => false,
                ]),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                        'input_mask' => true,
                        'input_mask_mask_MaskedPattern' => '(000) 000-0000[ x00000]',
                    ],
                ],
            ],
        ],
        'text field with input mask true input mask is custom false field_mask_select date' => [
            'source' => [
                \array_merge($baseField_text, [
                    'inputMask' => true,
                    'inputMaskValue' => '99\/99\/9999',
                    'inputMaskIsCustom' => false,
                ]),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                        'input_mask' => true,
                        'input_mask_mask_MaskedPattern' => '00/00/0000',
                    ],
                ],
            ],
        ],
        'text field with input mask true input mask is custom false field_mask_select tax id' => [
            'source' => [
                \array_merge($baseField_text, [
                    'inputMask' => true,
                    'inputMaskValue' => '99-9999999',
                    'inputMaskIsCustom' => false,
                ]),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                        'input_mask' => true,
                        'input_mask_mask_MaskedPattern' => '00-0000000',
                    ],
                ],
            ],
        ],
        'text field with input mask true input mask is custom false field_mask_select ssn' => [
            'source' => [
                \array_merge($baseField_text, [
                    'inputMask' => true,
                    'inputMaskValue' => '999-99-9999',
                    'inputMaskIsCustom' => false,
                ]),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                        'input_mask' => true,
                        'input_mask_mask_MaskedPattern' => '000-00-0000',
                    ],
                ],
            ],
        ],
        'text field with input mask true input mask is custom false field_mask_select zip code' => [
            'source' => [
                \array_merge($baseField_text, [
                    'inputMask' => true,
                    'inputMaskValue' => '99999',
                    'inputMaskIsCustom' => false,
                ]),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                        'input_mask' => true,
                        'input_mask_mask_MaskedPattern' => '00000',
                    ],
                ],
            ],
        ],
        'text field with input mask true input mask is custom false field_mask_select full zip code' => [
            'source' => [
                \array_merge($baseField_text, [
                    'inputMask' => true,
                    'inputMaskValue' => '99999?-9999',
                    'inputMaskIsCustom' => false,
                ]),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                        'input_mask' => true,
                        'input_mask_mask_MaskedPattern' => '00000[-0000]',
                    ],
                ],
            ],
        ],
        'quiz field with gquiz field type radio' => [
            'source' => [
                \array_merge(
                    $baseField_quiz,
                    [
                        'gquizFieldType' => 'radio',
                        'inputType' => 'radio',
                        'gquizEnableRandomizeQuizChoices' => false,
                        'gquizShowAnswerExplanation' => false,
                        'gquizAnswerExplanation' => '',
                        'gquizWeightedScoreEnabled' => false,
                        'choices' => [
                            [
                                'text' => 'First Choice',
                                'value' => 'gquiz0001',
                                'isSelected' => false,
                                'price' => '',
                                'gquizIsCorrect' => false,
                                'gquizWeight' => '0',
                            ],
                            [
                                'text' => 'Second Choice',
                                'value' => 'gquiz0002',
                                'isSelected' => false,
                                'price' => '',
                                'gquizIsCorrect' => true,
                                'gquizWeight' => '0',
                            ],
                            [
                                'text' => 'Third Choice',
                                'value' => 'gquiz0003',
                                'isSelected' => false,
                                'price' => '',
                                'gquizIsCorrect' => false,
                                'gquizWeight' => '0',
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_146',
                    'field' => [
                        'type' => 'quiz',
                        'display' => 'Test Field',
                        'question_fieldtype' => 'radio',
                        'options' => [
                            [
                                'id' => 'gquiz0001',
                                'is_correct' => false,
                                'label' => 'First Choice',
                            ],
                            [
                                'id' => 'gquiz0002',
                                'is_correct' => true,
                                'label' => 'Second Choice',
                            ],
                            [
                                'id' => 'gquiz0003',
                                'is_correct' => false,
                                'label' => 'Third Choice',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'quiz field with gquiz field type checkbox' => [
            'source' => [
                \array_merge(
                    $baseField_quiz,
                    [
                        'gquizFieldType' => 'checkbox',
                        'inputType' => 'checkbox',
                        'gquizEnableRandomizeQuizChoices' => false,
                        'gquizShowAnswerExplanation' => false,
                        'gquizAnswerExplanation' => '',
                        'gquizWeightedScoreEnabled' => false,
                        'choices' => [
                            [
                                'text' => 'First Choice',
                                'value' => 'gquiz0001',
                                'isSelected' => false,
                                'price' => '',
                                'gquizIsCorrect' => false,
                                'gquizWeight' => '0',
                            ],
                            [
                                'text' => 'Second Choice',
                                'value' => 'gquiz0002',
                                'isSelected' => false,
                                'price' => '',
                                'gquizIsCorrect' => true,
                                'gquizWeight' => '0',
                            ],
                            [
                                'text' => 'Third Choice',
                                'value' => 'gquiz0003',
                                'isSelected' => false,
                                'price' => '',
                                'gquizIsCorrect' => false,
                                'gquizWeight' => '0',
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_146',
                    'field' => [
                        'type' => 'quiz',
                        'display' => 'Test Field',
                        'question_fieldtype' => 'checkboxes',
                        'options' => [
                            [
                                'id' => 'gquiz0001',
                                'is_correct' => false,
                                'label' => 'First Choice',
                            ],
                            [
                                'id' => 'gquiz0002',
                                'is_correct' => true,
                                'label' => 'Second Choice',
                            ],
                            [
                                'id' => 'gquiz0003',
                                'is_correct' => false,
                                'label' => 'Third Choice',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'quiz field with gquiz field type select' => [
            'source' => [
                \array_merge(
                    $baseField_quiz,
                    [
                        'gquizFieldType' => 'select',
                        'inputType' => 'select',
                        'gquizEnableRandomizeQuizChoices' => false,
                        'gquizShowAnswerExplanation' => false,
                        'gquizAnswerExplanation' => '',
                        'gquizWeightedScoreEnabled' => false,
                        'choices' => [
                            [
                                'text' => 'First Choice',
                                'value' => 'gquiz0001',
                                'isSelected' => false,
                                'price' => '',
                                'gquizIsCorrect' => false,
                                'gquizWeight' => '0',
                            ],
                            [
                                'text' => 'Second Choice',
                                'value' => 'gquiz0002',
                                'isSelected' => false,
                                'price' => '',
                                'gquizIsCorrect' => true,
                                'gquizWeight' => '0',
                            ],
                            [
                                'text' => 'Third Choice',
                                'value' => 'gquiz0003',
                                'isSelected' => false,
                                'price' => '',
                                'gquizIsCorrect' => false,
                                'gquizWeight' => '0',
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_146',
                    'field' => [
                        'type' => 'quiz',
                        'display' => 'Test Field',
                        'question_fieldtype' => 'select',
                        'options' => [
                            [
                                'id' => 'gquiz0001',
                                'is_correct' => false,
                                'label' => 'First Choice',
                            ],
                            [
                                'id' => 'gquiz0002',
                                'is_correct' => true,
                                'label' => 'Second Choice',
                            ],
                            [
                                'id' => 'gquiz0003',
                                'is_correct' => false,
                                'label' => 'Third Choice',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'radio input type quiz field with gquiz enable randomize quiz choices true' => [
            'source' => [
                \array_merge(
                    $baseField_quiz,
                    [
                        'gquizFieldType' => 'radio',
                        'inputType' => 'radio',
                        'gquizEnableRandomizeQuizChoices' => true,
                        'gquizShowAnswerExplanation' => false,
                        'gquizAnswerExplanation' => '',
                        'gquizWeightedScoreEnabled' => false,
                        'choices' => [
                            [
                                'text' => 'First Choice',
                                'value' => 'gquiz0001',
                                'isSelected' => false,
                                'price' => '',
                                'gquizIsCorrect' => false,
                                'gquizWeight' => '0',
                            ],
                            [
                                'text' => 'Second Choice',
                                'value' => 'gquiz0002',
                                'isSelected' => false,
                                'price' => '',
                                'gquizIsCorrect' => true,
                                'gquizWeight' => '0',
                            ],
                            [
                                'text' => 'Third Choice',
                                'value' => 'gquiz0003',
                                'isSelected' => false,
                                'price' => '',
                                'gquizIsCorrect' => false,
                                'gquizWeight' => '0',
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_146',
                    'field' => [
                        'type' => 'quiz',
                        'display' => 'Test Field',
                        'question_fieldtype' => 'radio',
                        'randomize_order' => true,
                        'options' => [
                            [
                                'id' => 'gquiz0001',
                                'is_correct' => false,
                                'label' => 'First Choice',
                            ],
                            [
                                'id' => 'gquiz0002',
                                'is_correct' => true,
                                'label' => 'Second Choice',
                            ],
                            [
                                'id' => 'gquiz0003',
                                'is_correct' => false,
                                'label' => 'Third Choice',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'radio input type quiz field with answer_explanation_query true' => [
            'source' => [
                \array_merge(
                    $baseField_quiz,
                    [
                        'gquizFieldType' => 'radio',
                        'inputType' => 'radio',
                        'gquizEnableRandomizeQuizChoices' => false,
                        'gquizShowAnswerExplanation' => true,
                        'gquizAnswerExplanation' => 'Foo bar baz',
                        'gquizWeightedScoreEnabled' => false,
                        'choices' => [
                            [
                                'text' => 'First Choice',
                                'value' => 'gquiz0001',
                                'isSelected' => false,
                                'price' => '',
                                'gquizIsCorrect' => false,
                                'gquizWeight' => '0',
                            ],
                            [
                                'text' => 'Second Choice',
                                'value' => 'gquiz0002',
                                'isSelected' => false,
                                'price' => '',
                                'gquizIsCorrect' => true,
                                'gquizWeight' => '0',
                            ],
                            [
                                'text' => 'Third Choice',
                                'value' => 'gquiz0003',
                                'isSelected' => false,
                                'price' => '',
                                'gquizIsCorrect' => false,
                                'gquizWeight' => '0',
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_146',
                    'field' => [
                        'type' => 'quiz',
                        'display' => 'Test Field',
                        'question_fieldtype' => 'radio',
                        'answer_explanation_query' => true,
                        'answer_explanation' => 'Foo bar baz',
                        'options' => [
                            [
                                'id' => 'gquiz0001',
                                'is_correct' => false,
                                'label' => 'First Choice',
                            ],
                            [
                                'id' => 'gquiz0002',
                                'is_correct' => true,
                                'label' => 'Second Choice',
                            ],
                            [
                                'id' => 'gquiz0003',
                                'is_correct' => false,
                                'label' => 'Third Choice',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'radio input type quiz field with gquiz weighted score enabled true' => [
            'source' => [
                \array_merge(
                    $baseField_quiz,
                    [
                        'gquizFieldType' => 'radio',
                        'inputType' => 'radio',
                        'gquizEnableRandomizeQuizChoices' => false,
                        'gquizShowAnswerExplanation' => false,
                        'gquizAnswerExplanation' => '',
                        'gquizWeightedScoreEnabled' => true,
                        'choices' => [
                            [
                                'text' => 'First Choice',
                                'value' => 'gquiz0001',
                                'isSelected' => false,
                                'price' => '',
                                'gquizIsCorrect' => false,
                                'gquizWeight' => '1',
                            ],
                            [
                                'text' => 'Second Choice',
                                'value' => 'gquiz0002',
                                'isSelected' => false,
                                'price' => '',
                                'gquizIsCorrect' => true,
                                'gquizWeight' => '10',
                            ],
                            [
                                'text' => 'Third Choice',
                                'value' => 'gquiz0003',
                                'isSelected' => false,
                                'price' => '',
                                'gquizIsCorrect' => false,
                                'gquizWeight' => '2',
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_146',
                    'field' => [
                        'type' => 'quiz',
                        'display' => 'Test Field',
                        'question_fieldtype' => 'radio',
                        'weighted_score_query' => true,
                        'options' => [
                            [
                                'id' => 'gquiz0001',
                                'is_correct' => false,
                                'label' => 'First Choice',
                                'weighted_score' => '1',
                            ],
                            [
                                'id' => 'gquiz0002',
                                'is_correct' => true,
                                'label' => 'Second Choice',
                                'weighted_score' => '10',
                            ],
                            [
                                'id' => 'gquiz0003',
                                'is_correct' => false,
                                'label' => 'Third Choice',
                                'weighted_score' => '2',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'email field with enable autocomplete false and autocomplete attribute email' => [
            'source' => [
                \array_merge(
                    $baseField_email,
                    []
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_124',
                    'field' => [
                        'type' => 'email',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'name field with custom labels' => [
            'source' => [
                \array_merge(
                    $baseField_name,
                    [
                        'inputs' => [
                            [
                                'id' => '141.2',
                                'label' => 'Prefix',
                                'name' => '',
                                'autocompleteAttribute' => 'honorific-prefix',
                                'choices' => [
                                    [
                                        'text' => 'Dr.',
                                        'value' => 'Dr.',
                                    ],
                                    [
                                        'text' => 'Miss',
                                        'value' => 'Miss',
                                    ],
                                    [
                                        'text' => 'Mr.',
                                        'value' => 'Mr.',
                                    ],
                                    [
                                        'text' => 'Mrs.',
                                        'value' => 'Mrs.',
                                    ],
                                    [
                                        'text' => 'Ms.',
                                        'value' => 'Ms.',
                                    ],
                                    [
                                        'text' => 'Mx.',
                                        'value' => 'Mx.',
                                    ],
                                    [
                                        'text' => 'Prof.',
                                        'value' => 'Prof.',
                                    ],
                                    [
                                        'text' => 'Rev.',
                                        'value' => 'Rev.',
                                    ],
                                ],
                                'isHidden' => false,
                                'inputType' => 'radio',
                                'customLabel' => 'Foo Prefix',
                                'placeholder' => 'Bar Prefix',
                                'defaultValue' => 'Baz Prefix',
                            ],
                            [
                                'id' => '141.3',
                                'label' => 'First',
                                'name' => '',
                                'autocompleteAttribute' => 'given-name',
                                'customLabel' => 'Foo First',
                                'placeholder' => 'Bar First',
                                'defaultValue' => 'Baz First',
                            ],
                            [
                                'id' => '141.4',
                                'label' => 'Middle',
                                'name' => '',
                                'autocompleteAttribute' => 'additional-name',
                                'isHidden' => false,
                                'customLabel' => 'Foo Middle',
                                'placeholder' => 'Bar Middle',
                                'defaultValue' => 'Baz Middle',
                            ],
                            [
                                'id' => '141.6',
                                'label' => 'Last',
                                'name' => '',
                                'autocompleteAttribute' => 'family-name',
                                'customLabel' => 'Foo Last',
                                'placeholder' => 'Bar Last',
                                'defaultValue' => 'Baz Last',
                            ],
                            [
                                'id' => '141.8',
                                'label' => 'Suffix',
                                'name' => '',
                                'autocompleteAttribute' => 'honorific-suffix',
                                'isHidden' => false,
                                'customLabel' => 'Foo Suffix',
                                'placeholder' => 'Bar Suffix',
                                'defaultValue' => 'Baz Suffix',
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_141',
                    'field' => [
                        'type' => 'name',
                        'display' => 'Test Field',
                        'prefix_include' => true,
                        'prefix_label' => 'Foo Prefix',
                        'prefix_placeholder' => 'Bar Prefix',
                        'prefix_default' => 'Baz Prefix',
                        'first_name_label' => 'Foo First',
                        'first_name_placeholder' => 'Bar First',
                        'first_name_default' => 'Baz First',
                        'middle_name_include' => true,
                        'middle_name_label' => 'Foo Middle',
                        'middle_name_placeholder' => 'Bar Middle',
                        'middle_name_default' => 'Baz Middle',
                        'last_name_label' => 'Foo Last',
                        'last_name_placeholder' => 'Bar Last',
                        'last_name_default' => 'Baz Last',
                        'suffix_include' => true,
                        'suffix_label' => 'Foo Suffix',
                        'suffix_placeholder' => 'Bar Suffix',
                        'suffix_default' => 'Baz Suffix',
                    ],
                ],
            ],
        ],
        'text field with max length 42' => [
            'source' => [
                \array_merge(
                    $baseField_text,
                    ['maxLength' => 42]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                        'character_limit' => 42,
                    ],
                ],
            ],
        ],
        'text field with max length "42"' => [
            'source' => [
                \array_merge(
                    $baseField_text,
                    ['maxLength' => '42']
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                        'character_limit' => 42,
                    ],
                ],
            ],
        ],
        'file field with multiple files false' => [
            'source' => [
                \array_merge(
                    $baseField_fileupload,
                    [
                        'size' => 'large',
                        'enableAutocomplete' => false,
                        'autocompleteAttribute' => '',
                        'maxFileSize' => '',
                        'maxFiles' => '',
                        'multipleFiles' => false,
                        'allowedExtensions' => '',
                        'layoutGridColumnSpan' => '',
                        'enableEnhancedUI' => 0,
                        'layoutGroupId' => 'c8877110',
                        'errors' => [],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_138',
                    'field' => [
                        'type' => 'assets',
                        'display' => 'Test Field',
                        'container' => 'form_entry_uploads',
                        'folder' => $siteHandle,
                        'dynamic' => 'id',
                        'restrict' => true,
                        'max_files' => 1,
                    ],
                ],
            ],
        ],
        'file field with multiple files true and max files ""' => [
            'source' => [
                \array_merge(
                    $baseField_fileupload,
                    [
                        'size' => 'large',
                        'enableAutocomplete' => false,
                        'autocompleteAttribute' => '',
                        'maxFileSize' => '',
                        'maxFiles' => '',
                        'multipleFiles' => true,
                        'allowedExtensions' => '',
                        'layoutGridColumnSpan' => '',
                        'enableEnhancedUI' => 0,
                        'layoutGroupId' => 'c8877110',
                        'errors' => [],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_138',
                    'field' => [
                        'type' => 'assets',
                        'display' => 'Test Field',
                        'container' => 'form_entry_uploads',
                        'folder' => $siteHandle,
                        'dynamic' => 'id',
                        'restrict' => true,
                    ],
                ],
            ],
        ],
        'file field with multiple files true and max files "2"' => [
            'source' => [
                \array_merge(
                    $baseField_fileupload,
                    [
                        'size' => 'large',
                        'enableAutocomplete' => false,
                        'autocompleteAttribute' => '',
                        'maxFileSize' => '',
                        'maxFiles' => '2',
                        'multipleFiles' => true,
                        'allowedExtensions' => '',
                        'layoutGridColumnSpan' => '',
                        'enableEnhancedUI' => 0,
                        'layoutGroupId' => 'c8877110',
                        'errors' => [],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_138',
                    'field' => [
                        'type' => 'assets',
                        'display' => 'Test Field',
                        'container' => 'form_entry_uploads',
                        'folder' => $siteHandle,
                        'dynamic' => 'id',
                        'restrict' => true,
                        'max_files' => 2,
                    ],
                ],
            ],
        ],
        'file field with multiple files true and max files 2' => [
            'source' => [
                \array_merge(
                    $baseField_fileupload,
                    [
                        'size' => 'large',
                        'enableAutocomplete' => false,
                        'autocompleteAttribute' => '',
                        'maxFileSize' => '',
                        'maxFiles' => 2,
                        'multipleFiles' => true,
                        'allowedExtensions' => '',
                        'layoutGridColumnSpan' => '',
                        'enableEnhancedUI' => 0,
                        'layoutGroupId' => 'c8877110',
                        'errors' => [],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_138',
                    'field' => [
                        'type' => 'assets',
                        'display' => 'Test Field',
                        'container' => 'form_entry_uploads',
                        'folder' => $siteHandle,
                        'dynamic' => 'id',
                        'restrict' => true,
                        'max_files' => 2,
                    ],
                ],
            ],
        ],
        'rcwdupload field' => [
            'source' => [
                \array_merge(
                    $baseField_rcwdupload,
                    []
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_188',
                    'field' => [
                        'type' => 'assets',
                        'display' => 'Test Field',
                        'container' => 'form_entry_uploads',
                        'folder' => $siteHandle,
                        'dynamic' => 'id',
                        'restrict' => true,
                        'max_files' => 1,
                    ],
                ],
            ],
        ],
        'rcwdupload field with rcwdupload_filter n' => [
            'source' => [
                \array_merge(
                    $baseField_rcwdupload,
                    ['rcwdupload_filter' => 'N']
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_188',
                    'field' => [
                        'type' => 'assets',
                        'display' => 'Test Field',
                        'container' => 'form_entry_uploads',
                        'folder' => $siteHandle,
                        'dynamic' => 'id',
                        'restrict' => true,
                        'max_files' => 1,
                    ],
                ],
            ],
        ],
        'rcwdupload field with rcwdupload_preview n' => [
            'source' => [
                \array_merge(
                    $baseField_rcwdupload,
                    ['rcwdupload_preview' => 'N']
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_188',
                    'field' => [
                        'type' => 'assets',
                        'display' => 'Test Field',
                        'container' => 'form_entry_uploads',
                        'folder' => $siteHandle,
                        'dynamic' => 'id',
                        'restrict' => true,
                        'max_files' => 1,
                    ],
                ],
            ],
        ],
        'rcwdupload field with rcwdupload_preview y' => [
            'source' => [
                \array_merge(
                    $baseField_rcwdupload,
                    ['rcwdupload_preview' => 'Y']
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_188',
                    'field' => [
                        'type' => 'assets',
                        'display' => 'Test Field',
                        'container' => 'form_entry_uploads',
                        'folder' => $siteHandle,
                        'dynamic' => 'id',
                        'restrict' => true,
                        'max_files' => 1,
                    ],
                ],
            ],
        ],
        'radio field with other' => [
            'source' => [
                \array_merge(
                    $baseField_radio,
                    [
                        'choices' => [
                            [
                                'text' => 'Red',
                                'value' => 'Red',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Blue',
                                'value' => 'Blue',
                                'isSelected' => false,
                                'price' => '',
                            ],
                        ],
                        'enableOtherChoice' => true,
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_137',
                    'field' => [
                        'type' => 'radio',
                        'display' => 'Test Field',
                        'enable_other' => true,
                        'options' => [
                            [
                                'key' => 'Red',
                                'value' => null,
                            ],
                            [
                                'key' => 'Blue',
                                'value' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'text field with placeholder' => [
            'source' => [
                \array_merge(
                    $baseField_text,
                    ['placeholder' => 'Enter your name']
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                        'placeholder' => 'Enter your name',
                    ],
                ],
            ],
        ],
        'file field with allowed extensions jpg, gif,png, pdf' => [
            'source' => [
                \array_merge(
                    $baseField_fileupload,
                    [
                        'size' => 'large',
                        'enableAutocomplete' => false,
                        'autocompleteAttribute' => '',
                        'maxFileSize' => '',
                        'maxFiles' => '',
                        'multipleFiles' => false,
                        'allowedExtensions' => 'jpg, gif,png, pdf',
                        'layoutGridColumnSpan' => '',
                        'enableEnhancedUI' => 0,
                        'layoutGroupId' => 'c8877110',
                        'errors' => [],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_138',
                    'field' => [
                        'type' => 'assets',
                        'display' => 'Test Field',
                        'container' => 'form_entry_uploads',
                        'folder' => $siteHandle,
                        'dynamic' => 'id',
                        'restrict' => true,
                        'max_files' => 1,
                        'file_type_restrictions' => 'mime_types',
                        'mime_types' => [
                            'image/jpeg',
                            'image/gif',
                            'image/png',
                            'application/pdf',
                        ],
                    ],
                ],
            ],
        ],
        'rcwdupload field with rcwdupload_repeater n' => [
            'source' => [
                \array_merge(
                    $baseField_rcwdupload,
                    ['rcwdupload_repeater' => 'N']
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_188',
                    'field' => [
                        'type' => 'assets',
                        'display' => 'Test Field',
                        'container' => 'form_entry_uploads',
                        'folder' => $siteHandle,
                        'dynamic' => 'id',
                        'restrict' => true,
                        'max_files' => 1,
                    ],
                ],
            ],
        ],
        'post image field with allowed extensions jpg, gif,png, pdf' => [
            'source' => [
                \array_merge(
                    $baseField_post_image,
                    ['allowedExtensions' => 'jpg, gif,png, pdf']
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_159',
                    'field' => [
                        'type' => 'assets',
                        'display' => 'Test Field',
                        'container' => 'form_entry_uploads',
                        'folder' => $siteHandle,
                        'dynamic' => 'id',
                        'restrict' => true,
                        'max_files' => 1,
                        'file_type_restrictions' => 'mime_types',
                        'mime_types' => [
                            'image/jpeg',
                            'image/gif',
                            'image/png',
                            'application/pdf',
                        ],
                    ],
                ],
            ],
        ],
        'file field with max file size 42' => [
            'source' => [
                \array_merge(
                    $baseField_fileupload,
                    [
                        'size' => 'large',
                        'enableAutocomplete' => false,
                        'autocompleteAttribute' => '',
                        'maxFileSize' => 42,
                        'multipleFiles' => false,
                        'allowedExtensions' => '',
                        'layoutGridColumnSpan' => '',
                        'enableEnhancedUI' => 0,
                        'layoutGroupId' => 'c8877110',
                        'errors' => [],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_138',
                    'field' => [
                        'type' => 'assets',
                        'display' => 'Test Field',
                        'container' => 'form_entry_uploads',
                        'folder' => $siteHandle,
                        'dynamic' => 'id',
                        'restrict' => true,
                        'max_files' => 1,
                        'max_file_size' => 42,
                    ],
                ],
            ],
        ],
        'checkbox field with enable choice value true' => [
            'source' => [
                \array_merge(
                    $baseField_checkbox,
                    [
                        'choices' => [
                            [
                                'text' => 'Square',
                                'value' => 'square',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Circle',
                                'value' => 'circle',
                                'isSelected' => false,
                                'price' => '',
                            ],
                        ],
                        'enableChoiceValue' => true,
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_139',
                    'field' => [
                        'type' => 'checkboxes',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'square',
                                'value' => 'Square',
                            ],
                            [
                                'key' => 'circle',
                                'value' => 'Circle',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'checkbox field with allows prepopulate true and input name foo' => [
            'source' => [
                \array_merge(
                    $baseField_checkbox,
                    [
                        'allowsPrepopulate' => true,
                        'inputName' => 'foo',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_139',
                    'field' => [
                        'type' => 'checkboxes',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'First Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Second Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Third Choice',
                                'value' => null,
                            ],
                        ],
                        'calculation_query' => true,
                        'calculation_formula_text' => '[get_param:foo]',
                    ],
                ],
            ],
        ],
        'checkbox field with allows prepopulate true and input name empty' => [
            'source' => [
                \array_merge(
                    $baseField_checkbox,
                    [
                        'allowsPrepopulate' => true,
                        'inputName' => '',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_139',
                    'field' => [
                        'type' => 'checkboxes',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'First Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Second Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Third Choice',
                                'value' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'radio field with enable choice value true' => [
            'source' => [
                \array_merge(
                    $baseField_radio,
                    [
                        'choices' => [
                            [
                                'text' => 'Yes',
                                'value' => 'yes',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'No',
                                'value' => 'no',
                                'isSelected' => false,
                                'price' => '',
                            ],
                        ],
                        'enableChoiceValue' => true,
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_137',
                    'field' => [
                        'type' => 'radio',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'yes',
                                'value' => 'Yes',
                            ],
                            [
                                'key' => 'no',
                                'value' => 'No',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'select field with enable choice value true' => [
            'source' => [
                \array_merge(
                    $baseField_select,
                    [
                        'validateState' => true,
                        'choices' => [
                            [
                                'text' => 'Near',
                                'value' => 'near',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Far',
                                'value' => 'far',
                                'isSelected' => false,
                                'price' => '',
                            ],
                        ],
                        'enableAutocomplete' => false,
                        'autocompleteAttribute' => '',
                        'layoutGridColumnSpan' => '',
                        'enablePrice' => '',
                        'enableEnhancedUI' => 0,
                        'layoutGroupId' => 'd3d3dc27',
                        'errors' => [],
                        'enableChoiceValue' => true,
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_140',
                    'field' => [
                        'type' => 'select',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'near',
                                'value' => 'Near',
                            ],
                            [
                                'key' => 'far',
                                'value' => 'Far',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'calculation text target field 1' => [
            'source' => [
                \array_merge(
                    $baseField_text,
                    [
                        'id' => '1013',
                        'label' => 'Text Calculation Target Field 1',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'text_calculation_target_field_1_1013',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Text Calculation Target Field 1',
                    ],
                ],
            ],
        ],
        'calculation text target field 2' => [
            'source' => [
                \array_merge(
                    $baseField_text,
                    [
                        'id' => '1014',
                        'label' => 'Text Calculation Target Field 2',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'text_calculation_target_field_2_1014',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Text Calculation Target Field 2',
                    ],
                ],
            ],
        ],
        'calculation math target field 1' => [
            'source' => [
                \array_merge(
                    $baseField_number,
                    [
                        'id' => '1016',
                        'label' => 'Math Calculation Target Field 1',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'math_calculation_target_field_1_1016',
                    'field' => [
                        'type' => 'float',
                        'display' => 'Math Calculation Target Field 1',
                    ],
                ],
            ],
        ],
        'calculation math target field 2' => [
            'source' => [
                \array_merge(
                    $baseField_number,
                    [
                        'id' => '1017',
                        'label' => 'Math Calculation Target Field 2',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'math_calculation_target_field_2_1017',
                    'field' => [
                        'type' => 'float',
                        'display' => 'Math Calculation Target Field 2',
                    ],
                ],
            ],
        ],
        'calculation math' => [
            'source' => [
                \array_merge(
                    $baseField_number,
                    [
                        'id' => '1016',
                        'label' => 'Math Calculation Target Field 1',
                        'numberFormat' => 'decimal_dot',
                        'enableAutocomplete' => false,
                        'autocompleteAttribute' => '',
                        'enableCalculation' => false,
                        'rangeMin' => '',
                        'rangeMax' => '',
                        'layoutGridColumnSpan' => '',
                        'enableDisplayInColumns' => '',
                        'enableEnhancedUI' => 0,
                        'layoutGroupId' => 'e71720ca',
                        'errors' => [],
                    ]
                ),
                \array_merge(
                    $baseField_number,
                    [
                        'id' => '1017',
                        'label' => 'Math Calculation Target Field 2',
                        'numberFormat' => 'decimal_dot',
                        'enableAutocomplete' => false,
                        'autocompleteAttribute' => '',
                        'enableCalculation' => false,
                        'rangeMin' => '',
                        'rangeMax' => '',
                        'layoutGridColumnSpan' => 12,
                        'enableDisplayInColumns' => '',
                        'enableEnhancedUI' => 0,
                        'layoutGroupId' => '40575e51',
                        'errors' => [],
                    ]
                ),
                \array_merge(
                    $baseField_number,
                    [
                        'id' => '1018',
                        'label' => 'Math Calculation',
                        'numberFormat' => 'decimal_dot',
                        'enableAutocomplete' => false,
                        'autocompleteAttribute' => '',
                        'enableCalculation' => true,
                        'rangeMin' => '',
                        'rangeMax' => '',
                        'calculationFormula' => '{Math Calculation Target Field 1:1016} * {Math Calculation Target Field 2:1017}',
                        'layoutGridColumnSpan' => 12,
                        'enableDisplayInColumns' => '',
                        'enableEnhancedUI' => 0,
                        'layoutGroupId' => 'd1126eaf',
                        'calculationRounding' => '3',
                        'errors' => [],
                        'fields' => '',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'math_calculation_target_field_1_1016',
                    'field' => [
                        'type' => 'float',
                        'display' => 'Math Calculation Target Field 1',
                    ],
                ],
                [
                    'handle' => 'math_calculation_target_field_2_1017',
                    'field' => [
                        'type' => 'float',
                        'display' => 'Math Calculation Target Field 2',
                    ],
                ],
                [
                    'handle' => 'math_calculation_1018',
                    'field' => [
                        'type' => 'float',
                        'display' => 'Math Calculation',
                        'calculation_query' => true,
                        'calculation_formula_math' => '[math_calculation_target_field_1_1016] * [math_calculation_target_field_2_1017]',
                        'calculation_decimals' => 3,
                    ],
                ],
            ],
        ],
        'conditional logic target radio field 1' => [
            'source' => [
                \array_merge(
                    $baseField_radio,
                    [
                        'id' => '915',
                        'label' => 'Target Radio Field 1',
                        'choices' => [
                            [
                                'text' => 'Red',
                                'value' => 'red',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Blue',
                                'value' => 'blue',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Green',
                                'value' => 'green',
                                'isSelected' => false,
                                'price' => '',
                            ],
                        ],
                        'enableChoiceValue' => true,
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'target_radio_field_1_915',
                    'field' => [
                        'options' => [
                            [
                                'key' => 'red',
                                'value' => 'Red',
                            ],
                            [
                                'key' => 'blue',
                                'value' => 'Blue',
                            ],
                            [
                                'key' => 'green',
                                'value' => 'Green',
                            ],
                        ],
                        'type' => 'radio',
                        'display' => 'Target Radio Field 1',
                    ],
                ],
            ],
        ],
        'conditional logic target radio field 2' => [
            'source' => [
                \array_merge(
                    $baseField_radio,
                    [
                        'id' => '916',
                        'label' => 'Target Radio Field 2',
                        'choices' => [
                            [
                                'text' => 'Square',
                                'value' => 'square',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Circle',
                                'value' => 'circle',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Triangle',
                                'value' => 'triangle',
                                'isSelected' => false,
                                'price' => '',
                            ],
                        ],
                        'enableChoiceValue' => true,
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'target_radio_field_2_916',
                    'field' => [
                        'options' => [
                            [
                                'key' => 'square',
                                'value' => 'Square',
                            ],
                            [
                                'key' => 'circle',
                                'value' => 'Circle',
                            ],
                            [
                                'key' => 'triangle',
                                'value' => 'Triangle',
                            ],
                        ],
                        'type' => 'radio',
                        'display' => 'Target Radio Field 2',
                    ],
                ],
            ],
        ],
        'conditional logic target number field 1' => [
            'source' => [
                \array_merge(
                    $baseField_number,
                    [
                        'id' => '917',
                        'label' => 'Target Number Field 1',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'target_number_field_1_917',
                    'field' => [
                        'type' => 'float',
                        'display' => 'Target Number Field 1',
                    ],
                ],
            ],
        ],
        'conditional logic target text field 1' => [
            'source' => [
                \array_merge(
                    $baseField_text,
                    [
                        'id' => '918',
                        'label' => 'Target Text Field 1',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'target_text_field_1_918',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Target Text Field 1',
                    ],
                ],
            ],
        ],
        'conditional logic 01' => [
            'source' => [
                \array_merge(
                    $baseField_radio,
                    [
                        'id' => '915',
                        'label' => 'Target Radio Field 1',
                        'choices' => [
                            [
                                'text' => 'Red',
                                'value' => 'red',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Blue',
                                'value' => 'blue',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Green',
                                'value' => 'green',
                                'isSelected' => false,
                                'price' => '',
                            ],
                        ],
                        'enableChoiceValue' => true,
                    ]
                ),
                \array_merge(
                    $baseField_text,
                    [
                        'id' => '919',
                        'label' => 'Conditional Field 1',
                        'conditionalLogic' => '{
                                "actionType": "show",
                                "logicType": "all",
                                "rules": [
                                    {
                                        "fieldId": "915",
                                        "operator": "is",
                                        "value": "red"
                                    }
                                ]
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'target_radio_field_1_915',
                    'field' => [
                        'options' => [
                            [
                                'key' => 'red',
                                'value' => 'Red',
                            ],
                            [
                                'key' => 'blue',
                                'value' => 'Blue',
                            ],
                            [
                                'key' => 'green',
                                'value' => 'Green',
                            ],
                        ],
                        'type' => 'radio',
                        'display' => 'Target Radio Field 1',
                    ],
                ],
                [
                    'handle' => 'conditional_field_1_919',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Conditional Field 1',
                        'if' => [
                            'target_radio_field_1_915' => 'equals red',
                        ],
                    ],
                ],
            ],
        ],
        'conditional logic 02' => [
            'source' => [
                \array_merge(
                    $baseField_radio,
                    [
                        'id' => '915',
                        'label' => 'Target Radio Field 1',
                        'choices' => [
                            [
                                'text' => 'Red',
                                'value' => 'red',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Blue',
                                'value' => 'blue',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Green',
                                'value' => 'green',
                                'isSelected' => false,
                                'price' => '',
                            ],
                        ],
                        'enableChoiceValue' => true,
                    ]
                ),
                \array_merge(
                    $baseField_radio,
                    [
                        'id' => '916',
                        'label' => 'Target Radio Field 2',
                        'choices' => [
                            [
                                'text' => 'Square',
                                'value' => 'square',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Circle',
                                'value' => 'circle',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Triangle',
                                'value' => 'triangle',
                                'isSelected' => false,
                                'price' => '',
                            ],
                        ],
                        'enableChoiceValue' => true,
                    ]
                ),
                \array_merge(
                    $baseField_text,
                    [
                        'id' => '920',
                        'label' => 'Conditional Field 2',
                        'conditionalLogic' => '{
                                "actionType": "show",
                                "logicType": "all",
                                "rules": [
                                    {
                                        "fieldId": "915",
                                        "operator": "is",
                                        "value": "red"
                                    },
                                    {
                                        "fieldId": "916",
                                        "operator": "is",
                                        "value": "square"
                                    }
                                ]
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'target_radio_field_1_915',
                    'field' => [
                        'options' => [
                            [
                                'key' => 'red',
                                'value' => 'Red',
                            ],
                            [
                                'key' => 'blue',
                                'value' => 'Blue',
                            ],
                            [
                                'key' => 'green',
                                'value' => 'Green',
                            ],
                        ],
                        'type' => 'radio',
                        'display' => 'Target Radio Field 1',
                    ],
                ],
                [
                    'handle' => 'target_radio_field_2_916',
                    'field' => [
                        'options' => [
                            [
                                'key' => 'square',
                                'value' => 'Square',
                            ],
                            [
                                'key' => 'circle',
                                'value' => 'Circle',
                            ],
                            [
                                'key' => 'triangle',
                                'value' => 'Triangle',
                            ],
                        ],
                        'type' => 'radio',
                        'display' => 'Target Radio Field 2',
                    ],
                ],
                [
                    'handle' => 'conditional_field_2_920',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Conditional Field 2',
                        'if' => [
                            'target_radio_field_1_915' => 'equals red',
                            'target_radio_field_2_916' => 'equals square',
                        ],
                    ],
                ],
            ],
        ],
        'conditional logic 03' => [
            'source' => [
                \array_merge(
                    $baseField_radio,
                    [
                        'id' => '915',
                        'label' => 'Target Radio Field 1',
                        'choices' => [
                            [
                                'text' => 'Red',
                                'value' => 'red',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Blue',
                                'value' => 'blue',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Green',
                                'value' => 'green',
                                'isSelected' => false,
                                'price' => '',
                            ],
                        ],
                        'enableChoiceValue' => true,
                    ]
                ),
                \array_merge(
                    $baseField_text,
                    [
                        'id' => '921',
                        'label' => 'Conditional Field 3',
                        'conditionalLogic' => '{
                                "actionType": "show",
                                "logicType": "all",
                                "rules": [
                                    {
                                        "fieldId": "915",
                                        "operator": "isnot",
                                        "value": "red"
                                    }
                                ]
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'target_radio_field_1_915',
                    'field' => [
                        'options' => [
                            [
                                'key' => 'red',
                                'value' => 'Red',
                            ],
                            [
                                'key' => 'blue',
                                'value' => 'Blue',
                            ],
                            [
                                'key' => 'green',
                                'value' => 'Green',
                            ],
                        ],
                        'type' => 'radio',
                        'display' => 'Target Radio Field 1',
                    ],
                ],
                [
                    'handle' => 'conditional_field_3_921',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Conditional Field 3',
                        'if' => [
                            'target_radio_field_1_915' => 'not red',
                        ],
                    ],
                ],
            ],
        ],
        'conditional logic 04' => [
            'source' => [
                \array_merge(
                    $baseField_number,
                    [
                        'id' => '917',
                        'label' => 'Target Number Field 1',
                    ]
                ),
                \array_merge(
                    $baseField_text,
                    [
                        'id' => '922',
                        'label' => 'Conditional Field 4',
                        'conditionalLogic' => '{
                                "actionType": "show",
                                "logicType": "all",
                                "rules": [
                                    {
                                        "fieldId": "917",
                                        "operator": ">",
                                        "value": "42"
                                    }
                                ]
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'target_number_field_1_917',
                    'field' => [
                        'type' => 'float',
                        'display' => 'Target Number Field 1',
                    ],
                ],
                [
                    'handle' => 'conditional_field_4_922',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Conditional Field 4',
                        'if' => [
                            'target_number_field_1_917' => '> 42',
                        ],
                    ],
                ],
            ],
        ],
        'conditional logic 05' => [
            'source' => [
                \array_merge(
                    $baseField_number,
                    [
                        'id' => '917',
                        'label' => 'Target Number Field 1',
                    ]
                ),
                \array_merge(
                    $baseField_text,
                    [
                        'id' => '923',
                        'label' => 'Conditional Field 5',
                        'conditionalLogic' => '{
                                "actionType": "show",
                                "logicType": "all",
                                "rules": [
                                    {
                                        "fieldId": "917",
                                        "operator": "<",
                                        "value": "42"
                                    }
                                ]
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'target_number_field_1_917',
                    'field' => [
                        'type' => 'float',
                        'display' => 'Target Number Field 1',
                    ],
                ],
                [
                    'handle' => 'conditional_field_5_923',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Conditional Field 5',
                        'if' => [
                            'target_number_field_1_917' => '< 42',
                        ],
                    ],
                ],
            ],
        ],
        'conditional logic 06' => [
            'source' => [
                \array_merge(
                    $baseField_text,
                    [
                        'id' => '918',
                        'label' => 'Target Text Field 1',
                    ]
                ),
                \array_merge(
                    $baseField_text,
                    [
                        'id' => '924',
                        'label' => 'Conditional Field 6',
                        'conditionalLogic' => '{
                                "actionType": "show",
                                "logicType": "all",
                                "rules": [
                                    {
                                        "fieldId": "918",
                                        "operator": "contains",
                                        "value": "foo"
                                    }
                                ]
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'target_text_field_1_918',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Target Text Field 1',
                    ],
                ],
                [
                    'handle' => 'conditional_field_6_924',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Conditional Field 6',
                        'if' => [
                            'target_text_field_1_918' => 'contains foo',
                        ],
                    ],
                ],
            ],
        ],
        'conditional logic 07' => [
            'source' => [
                \array_merge(
                    $baseField_radio,
                    [
                        'id' => '915',
                        'label' => 'Target Radio Field 1',
                        'choices' => [
                            [
                                'text' => 'Red',
                                'value' => 'red',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Blue',
                                'value' => 'blue',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Green',
                                'value' => 'green',
                                'isSelected' => false,
                                'price' => '',
                            ],
                        ],
                        'enableChoiceValue' => true,
                    ]
                ),
                \array_merge(
                    $baseField_radio,
                    [
                        'id' => '916',
                        'label' => 'Target Radio Field 2',
                        'choices' => [
                            [
                                'text' => 'Square',
                                'value' => 'square',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Circle',
                                'value' => 'circle',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Triangle',
                                'value' => 'triangle',
                                'isSelected' => false,
                                'price' => '',
                            ],
                        ],
                        'enableChoiceValue' => true,
                    ]
                ),
                \array_merge(
                    $baseField_text,
                    [
                        'id' => '925',
                        'label' => 'Conditional Field 7',
                        'conditionalLogic' => '{
                                "actionType": "show",
                                "logicType": "any",
                                "rules": [
                                    {
                                        "fieldId": "915",
                                        "operator": "is",
                                        "value": "red"
                                    },
                                    {
                                        "fieldId": "916",
                                        "operator": "is",
                                        "value": "square"
                                    }
                                ]
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'target_radio_field_1_915',
                    'field' => [
                        'options' => [
                            [
                                'key' => 'red',
                                'value' => 'Red',
                            ],
                            [
                                'key' => 'blue',
                                'value' => 'Blue',
                            ],
                            [
                                'key' => 'green',
                                'value' => 'Green',
                            ],
                        ],
                        'type' => 'radio',
                        'display' => 'Target Radio Field 1',
                    ],
                ],
                [
                    'handle' => 'target_radio_field_2_916',
                    'field' => [
                        'options' => [
                            [
                                'key' => 'square',
                                'value' => 'Square',
                            ],
                            [
                                'key' => 'circle',
                                'value' => 'Circle',
                            ],
                            [
                                'key' => 'triangle',
                                'value' => 'Triangle',
                            ],
                        ],
                        'type' => 'radio',
                        'display' => 'Target Radio Field 2',
                    ],
                ],
                [
                    'handle' => 'conditional_field_7_925',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Conditional Field 7',
                        'if_any' => [
                            'target_radio_field_1_915' => 'equals red',
                            'target_radio_field_2_916' => 'equals square',
                        ],
                    ],
                ],
            ],
        ],
        'conditional logic 08' => [
            'source' => [
                \array_merge(
                    $baseField_radio,
                    [
                        'id' => '915',
                        'label' => 'Target Radio Field 1',
                        'choices' => [
                            [
                                'text' => 'Red',
                                'value' => 'red',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Blue',
                                'value' => 'blue',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Green',
                                'value' => 'green',
                                'isSelected' => false,
                                'price' => '',
                            ],
                        ],
                        'enableChoiceValue' => true,
                    ]
                ),
                \array_merge(
                    $baseField_text,
                    [
                        'id' => '926',
                        'label' => 'Conditional Field 8',
                        'conditionalLogic' => '{
                                "actionType": "hide",
                                "logicType": "all",
                                "rules": [
                                    {
                                        "fieldId": "915",
                                        "operator": "is",
                                        "value": "red"
                                    }
                                ]
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'target_radio_field_1_915',
                    'field' => [
                        'options' => [
                            [
                                'key' => 'red',
                                'value' => 'Red',
                            ],
                            [
                                'key' => 'blue',
                                'value' => 'Blue',
                            ],
                            [
                                'key' => 'green',
                                'value' => 'Green',
                            ],
                        ],
                        'type' => 'radio',
                        'display' => 'Target Radio Field 1',
                    ],
                ],
                [
                    'handle' => 'conditional_field_8_926',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Conditional Field 8',
                        'unless' => [
                            'target_radio_field_1_915' => 'equals red',
                        ],
                    ],
                ],
            ],
        ],
        'conditional logic 09' => [
            'source' => [
                \array_merge(
                    $baseField_radio,
                    [
                        'id' => '915',
                        'label' => 'Target Radio Field 1',
                        'choices' => [
                            [
                                'text' => 'Red',
                                'value' => 'red',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Blue',
                                'value' => 'blue',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Green',
                                'value' => 'green',
                                'isSelected' => false,
                                'price' => '',
                            ],
                        ],
                        'enableChoiceValue' => true,
                    ]
                ),
                \array_merge(
                    $baseField_radio,
                    [
                        'id' => '916',
                        'label' => 'Target Radio Field 2',
                        'choices' => [
                            [
                                'text' => 'Square',
                                'value' => 'square',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Circle',
                                'value' => 'circle',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Triangle',
                                'value' => 'triangle',
                                'isSelected' => false,
                                'price' => '',
                            ],
                        ],
                        'enableChoiceValue' => true,
                    ]
                ),
                \array_merge(
                    $baseField_text,
                    [
                        'id' => '927',
                        'label' => 'Conditional Field 9',
                        'conditionalLogic' => '{
                                "actionType": "hide",
                                "logicType": "all",
                                "rules": [
                                    {
                                        "fieldId": "915",
                                        "operator": "is",
                                        "value": "red"
                                    },
                                    {
                                        "fieldId": "916",
                                        "operator": "is",
                                        "value": "square"
                                    }
                                ]
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'target_radio_field_1_915',
                    'field' => [
                        'options' => [
                            [
                                'key' => 'red',
                                'value' => 'Red',
                            ],
                            [
                                'key' => 'blue',
                                'value' => 'Blue',
                            ],
                            [
                                'key' => 'green',
                                'value' => 'Green',
                            ],
                        ],
                        'type' => 'radio',
                        'display' => 'Target Radio Field 1',
                    ],
                ],
                [
                    'handle' => 'target_radio_field_2_916',
                    'field' => [
                        'options' => [
                            [
                                'key' => 'square',
                                'value' => 'Square',
                            ],
                            [
                                'key' => 'circle',
                                'value' => 'Circle',
                            ],
                            [
                                'key' => 'triangle',
                                'value' => 'Triangle',
                            ],
                        ],
                        'type' => 'radio',
                        'display' => 'Target Radio Field 2',
                    ],
                ],
                [
                    'handle' => 'conditional_field_9_927',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Conditional Field 9',
                        'unless' => [
                            'target_radio_field_1_915' => 'equals red',
                            'target_radio_field_2_916' => 'equals square',
                        ],
                    ],
                ],
            ],
        ],
        'conditional logic 10' => [
            'source' => [
                \array_merge(
                    $baseField_radio,
                    [
                        'id' => '915',
                        'label' => 'Target Radio Field 1',
                        'choices' => [
                            [
                                'text' => 'Red',
                                'value' => 'red',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Blue',
                                'value' => 'blue',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Green',
                                'value' => 'green',
                                'isSelected' => false,
                                'price' => '',
                            ],
                        ],
                        'enableChoiceValue' => true,
                    ]
                ),
                \array_merge(
                    $baseField_text,
                    [
                        'id' => '936',
                        'label' => 'Conditional Field 10',
                        'conditionalLogic' => '{
                                "actionType": "hide",
                                "logicType": "all",
                                "rules": [
                                    {
                                        "fieldId": "915",
                                        "operator": "isnot",
                                        "value": "red"
                                    }
                                ]
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'target_radio_field_1_915',
                    'field' => [
                        'options' => [
                            [
                                'key' => 'red',
                                'value' => 'Red',
                            ],
                            [
                                'key' => 'blue',
                                'value' => 'Blue',
                            ],
                            [
                                'key' => 'green',
                                'value' => 'Green',
                            ],
                        ],
                        'type' => 'radio',
                        'display' => 'Target Radio Field 1',
                    ],
                ],
                [
                    'handle' => 'conditional_field_10_936',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Conditional Field 10',
                        'unless' => [
                            'target_radio_field_1_915' => 'not red',
                        ],
                    ],
                ],
            ],
        ],
        'conditional logic 11' => [
            'source' => [
                \array_merge(
                    $baseField_number,
                    [
                        'id' => '917',
                        'label' => 'Target Number Field 1',
                    ]
                ),
                \array_merge(
                    $baseField_text,
                    [
                        'id' => '928',
                        'label' => 'Conditional Field 11',
                        'conditionalLogic' => '{
                                "actionType": "hide",
                                "logicType": "all",
                                "rules": [
                                    {
                                        "fieldId": "917",
                                        "operator": ">",
                                        "value": "42"
                                    }
                                ]
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'target_number_field_1_917',
                    'field' => [
                        'type' => 'float',
                        'display' => 'Target Number Field 1',
                    ],
                ],
                [
                    'handle' => 'conditional_field_11_928',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Conditional Field 11',
                        'unless' => [
                            'target_number_field_1_917' => '> 42',
                        ],
                    ],
                ],
            ],
        ],
        'conditional logic 12' => [
            'source' => [
                \array_merge(
                    $baseField_number,
                    [
                        'id' => '917',
                        'label' => 'Target Number Field 1',
                    ]
                ),
                \array_merge(
                    $baseField_text,
                    [
                        'id' => '929',
                        'label' => 'Conditional Field 12',
                        'conditionalLogic' => '{
                                "actionType": "hide",
                                "logicType": "all",
                                "rules": [
                                    {
                                        "fieldId": "917",
                                        "operator": "<",
                                        "value": "42"
                                    }
                                ]
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'target_number_field_1_917',
                    'field' => [
                        'type' => 'float',
                        'display' => 'Target Number Field 1',
                    ],
                ],
                [
                    'handle' => 'conditional_field_12_929',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Conditional Field 12',
                        'unless' => [
                            'target_number_field_1_917' => '< 42',
                        ],
                    ],
                ],
            ],
        ],
        'conditional logic 13' => [
            'source' => [
                \array_merge(
                    $baseField_text,
                    [
                        'id' => '918',
                        'label' => 'Target Text Field 1',
                    ]
                ),
                \array_merge(
                    $baseField_text,
                    [
                        'id' => '930',
                        'label' => 'Conditional Field 13',
                        'conditionalLogic' => '{
                                "actionType": "hide",
                                "logicType": "all",
                                "rules": [
                                    {
                                        "fieldId": "918",
                                        "operator": "is",
                                        "value": "foo"
                                    }
                                ]
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'target_text_field_1_918',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Target Text Field 1',
                    ],
                ],
                [
                    'handle' => 'conditional_field_13_930',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Conditional Field 13',
                        'unless' => [
                            'target_text_field_1_918' => 'equals foo',
                        ],
                    ],
                ],
            ],
        ],
        'conditional logic 14' => [
            'source' => [
                \array_merge(
                    $baseField_radio,
                    [
                        'id' => '915',
                        'label' => 'Target Radio Field 1',
                        'choices' => [
                            [
                                'text' => 'Red',
                                'value' => 'red',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Blue',
                                'value' => 'blue',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Green',
                                'value' => 'green',
                                'isSelected' => false,
                                'price' => '',
                            ],
                        ],
                        'enableChoiceValue' => true,
                    ]
                ),
                \array_merge(
                    $baseField_radio,
                    [
                        'id' => '916',
                        'label' => 'Target Radio Field 2',
                        'choices' => [
                            [
                                'text' => 'Square',
                                'value' => 'square',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Circle',
                                'value' => 'circle',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Triangle',
                                'value' => 'triangle',
                                'isSelected' => false,
                                'price' => '',
                            ],
                        ],
                        'enableChoiceValue' => true,
                    ]
                ),
                \array_merge(
                    $baseField_text,
                    [
                        'id' => '931',
                        'label' => 'Conditional Field 14',
                        'conditionalLogic' => '{
                                "actionType": "hide",
                                "logicType": "any",
                                "rules": [
                                    {
                                        "fieldId": "915",
                                        "operator": "is",
                                        "value": "red"
                                    },
                                    {
                                        "fieldId": "916",
                                        "operator": "is",
                                        "value": "square"
                                    }
                                ]
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'target_radio_field_1_915',
                    'field' => [
                        'options' => [
                            [
                                'key' => 'red',
                                'value' => 'Red',
                            ],
                            [
                                'key' => 'blue',
                                'value' => 'Blue',
                            ],
                            [
                                'key' => 'green',
                                'value' => 'Green',
                            ],
                        ],
                        'type' => 'radio',
                        'display' => 'Target Radio Field 1',
                    ],
                ],
                [
                    'handle' => 'target_radio_field_2_916',
                    'field' => [
                        'options' => [
                            [
                                'key' => 'square',
                                'value' => 'Square',
                            ],
                            [
                                'key' => 'circle',
                                'value' => 'Circle',
                            ],
                            [
                                'key' => 'triangle',
                                'value' => 'Triangle',
                            ],
                        ],
                        'type' => 'radio',
                        'display' => 'Target Radio Field 2',
                    ],
                ],
                [
                    'handle' => 'conditional_field_14_931',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Conditional Field 14',
                        'unless_any' => [
                            'target_radio_field_1_915' => 'equals red',
                            'target_radio_field_2_916' => 'equals square',
                        ],
                    ],
                ],
            ],
        ],
        'conditional logic 15' => [
            'source' => [
                \array_merge(
                    $baseField_radio,
                    [
                        'id' => '915',
                        'label' => 'Target Radio Field 1',
                        'choices' => [
                            [
                                'text' => 'Red',
                                'value' => 'red',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Blue',
                                'value' => 'blue',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Green',
                                'value' => 'green',
                                'isSelected' => false,
                                'price' => '',
                            ],
                        ],
                        'enableChoiceValue' => true,
                    ]
                ),
                \array_merge(
                    $baseField_text,
                    [
                        'id' => '932',
                        'label' => 'Conditional Field 15',
                        'conditionalLogic' => '{
                                "actionType": "show",
                                "logicType": "all",
                                "rules": [
                                    {
                                        "fieldId": "915",
                                        "operator": "is",
                                        "value": ""
                                    }
                                ]
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'target_radio_field_1_915',
                    'field' => [
                        'options' => [
                            [
                                'key' => 'red',
                                'value' => 'Red',
                            ],
                            [
                                'key' => 'blue',
                                'value' => 'Blue',
                            ],
                            [
                                'key' => 'green',
                                'value' => 'Green',
                            ],
                        ],
                        'type' => 'radio',
                        'display' => 'Target Radio Field 1',
                    ],
                ],
                [
                    'handle' => 'conditional_field_15_932',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Conditional Field 15',
                        'if' => [
                            'target_radio_field_1_915' => 'equals empty',
                        ],
                    ],
                ],
            ],
        ],
        'conditional logic 16' => [
            'source' => [
                \array_merge(
                    $baseField_radio,
                    [
                        'id' => '915',
                        'label' => 'Target Radio Field 1',
                        'choices' => [
                            [
                                'text' => 'Red',
                                'value' => 'red',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Blue',
                                'value' => 'blue',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Green',
                                'value' => 'green',
                                'isSelected' => false,
                                'price' => '',
                            ],
                        ],
                        'enableChoiceValue' => true,
                    ]
                ),
                \array_merge(
                    $baseField_text,
                    [
                        'id' => '933',
                        'label' => 'Conditional Field 16',
                        'conditionalLogic' => '{
                                "actionType": "show",
                                "logicType": "all",
                                "rules": [
                                    {
                                        "fieldId": "915",
                                        "operator": "isnot",
                                        "value": ""
                                    }
                                ]
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'target_radio_field_1_915',
                    'field' => [
                        'options' => [
                            [
                                'key' => 'red',
                                'value' => 'Red',
                            ],
                            [
                                'key' => 'blue',
                                'value' => 'Blue',
                            ],
                            [
                                'key' => 'green',
                                'value' => 'Green',
                            ],
                        ],
                        'type' => 'radio',
                        'display' => 'Target Radio Field 1',
                    ],
                ],
                [
                    'handle' => 'conditional_field_16_933',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Conditional Field 16',
                        'if' => [
                            'target_radio_field_1_915' => 'not empty',
                        ],
                    ],
                ],
            ],
        ],
        'conditional logic 17' => [
            'source' => [
                \array_merge(
                    $baseField_radio,
                    [
                        'id' => '915',
                        'label' => 'Target Radio Field 1',
                        'choices' => [
                            [
                                'text' => 'Red',
                                'value' => 'red',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Blue',
                                'value' => 'blue',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Green',
                                'value' => 'green',
                                'isSelected' => false,
                                'price' => '',
                            ],
                        ],
                        'enableChoiceValue' => true,
                    ]
                ),
                \array_merge(
                    $baseField_text,
                    [
                        'id' => '934',
                        'label' => 'Conditional Field 17',
                        'conditionalLogic' => '{
                                "actionType": "hide",
                                "logicType": "all",
                                "rules": [
                                    {
                                        "fieldId": "915",
                                        "operator": "is",
                                        "value": ""
                                    }
                                ]
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'target_radio_field_1_915',
                    'field' => [
                        'options' => [
                            [
                                'key' => 'red',
                                'value' => 'Red',
                            ],
                            [
                                'key' => 'blue',
                                'value' => 'Blue',
                            ],
                            [
                                'key' => 'green',
                                'value' => 'Green',
                            ],
                        ],
                        'type' => 'radio',
                        'display' => 'Target Radio Field 1',
                    ],
                ],
                [
                    'handle' => 'conditional_field_17_934',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Conditional Field 17',
                        'unless' => [
                            'target_radio_field_1_915' => 'equals empty',
                        ],
                    ],
                ],
            ],
        ],
        'conditional logic 18' => [
            'source' => [
                \array_merge(
                    $baseField_radio,
                    [
                        'id' => '915',
                        'label' => 'Target Radio Field 1',
                        'choices' => [
                            [
                                'text' => 'Red',
                                'value' => 'red',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Blue',
                                'value' => 'blue',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Green',
                                'value' => 'green',
                                'isSelected' => false,
                                'price' => '',
                            ],
                        ],
                        'enableChoiceValue' => true,
                    ]
                ),
                \array_merge(
                    $baseField_text,
                    [
                        'id' => '935',
                        'label' => 'Conditional Field 18',
                        'conditionalLogic' => '{
                                "actionType": "hide",
                                "logicType": "all",
                                "rules": [
                                    {
                                        "fieldId": "915",
                                        "operator": "isnot",
                                        "value": ""
                                    }
                                ]
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'target_radio_field_1_915',
                    'field' => [
                        'options' => [
                            [
                                'key' => 'red',
                                'value' => 'Red',
                            ],
                            [
                                'key' => 'blue',
                                'value' => 'Blue',
                            ],
                            [
                                'key' => 'green',
                                'value' => 'Green',
                            ],
                        ],
                        'type' => 'radio',
                        'display' => 'Target Radio Field 1',
                    ],
                ],
                [
                    'handle' => 'conditional_field_18_935',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Conditional Field 18',
                        'unless' => [
                            'target_radio_field_1_915' => 'not empty',
                        ],
                    ],
                ],
            ],
        ],
        'time field' => [
            'source' => [
                \array_merge(
                    $baseField_time,
                    []
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_149',
                    'field' => [
                        'type' => 'time',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'time field with time format 24' => [
            'source' => [
                \array_merge(
                    $baseField_time,
                    ['timeFormat' => '24']
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_149',
                    'field' => [
                        'type' => 'time',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'time field with custom label and placeholder and default value' => [
            'source' => [
                \array_merge(
                    $baseField_time,
                    [
                        'inputs' => [
                            [
                                'id' => '149.1',
                                'label' => 'Hour',
                                'name' => '',
                                'customLabel' => 'Foo',
                                'placeholder' => 'Bar',
                                'defaultValue' => 'Baz',
                            ],
                            [
                                'id' => '149.2',
                                'label' => 'Minute',
                                'name' => '',
                                'customLabel' => 'Qux',
                                'placeholder' => 'Quux',
                                'defaultValue' => 'Corge',
                            ],
                            [
                                'id' => '149.3',
                                'label' => 'AM\/PM',
                                'name' => '',
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_149',
                    'field' => [
                        'type' => 'time',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'textarea field' => [
            'source' => [
                \array_merge(
                    $baseField_textarea,
                    []
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_147',
                    'field' => [
                        'type' => 'textarea',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'textarea field with max length 42' => [
            'source' => [
                \array_merge(
                    $baseField_textarea,
                    ['maxLength' => 42]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_147',
                    'field' => [
                        'type' => 'textarea',
                        'display' => 'Test Field',
                        'character_limit' => 42,
                    ],
                ],
            ],
        ],
        'textarea field with max length "42"' => [
            'source' => [
                \array_merge(
                    $baseField_textarea,
                    ['maxLength' => '42']
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_147',
                    'field' => [
                        'type' => 'textarea',
                        'display' => 'Test Field',
                        'character_limit' => 42,
                    ],
                ],
            ],
        ],
        'checkbox input type survey field' => [
            'source' => [
                \array_merge(
                    $baseField_survey_checkbox,
                    []
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_160',
                    'field' => [
                        'type' => 'checkboxes',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'First Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Second Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Third Choice',
                                'value' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'radio input type survey field' => [
            'source' => [
                \array_merge(
                    $baseField_survey_radio,
                    []
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_161',
                    'field' => [
                        'type' => 'radio',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'First Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Second Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Third Choice',
                                'value' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'select input type survey field' => [
            'source' => [
                \array_merge(
                    $baseField_survey_select,
                    []
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_162',
                    'field' => [
                        'type' => 'select',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'First Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Second Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Third Choice',
                                'value' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'text input type survey field' => [
            'source' => [
                \array_merge(
                    $baseField_survey_text,
                    []
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_163',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'text input type survey field with input mask' => [
            'source' => [
                \array_merge(
                    $baseField_survey_text,
                    [
                        'inputMask' => true,
                        'inputMaskValue' => '(999) 999-9999',
                        'inputMaskIsCustom' => false,
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_163',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                        'input_mask' => true,
                        'input_mask_mask_MaskedPattern' => '(000) 000-0000',
                    ],
                ],
            ],
        ],
        'textarea input type survey field' => [
            'source' => [
                \array_merge(
                    $baseField_survey_textarea,
                    []
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_164',
                    'field' => [
                        'type' => 'textarea',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'multiselect field with enable choice value false' => [
            'source' => [
                \array_merge(
                    $baseField_multiselect,
                    [
                        'choices' => [
                            [
                                'text' => 'Red',
                                'value' => 'Red',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Blue',
                                'value' => 'Blue',
                                'isSelected' => false,
                                'price' => '',
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_150',
                    'field' => [
                        'type' => 'select',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'Red',
                                'value' => null,
                            ],
                            [
                                'key' => 'Blue',
                                'value' => null,
                            ],
                        ],
                        'multiple' => true,
                    ],
                ],
            ],
        ],
        'multiselect field with enable choice value true' => [
            'source' => [
                \array_merge(
                    $baseField_multiselect,
                    [
                        'enableChoiceValue' => true,
                        'choices' => [
                            [
                                'text' => 'Red',
                                'value' => 'red',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Blue',
                                'value' => 'blue',
                                'isSelected' => false,
                                'price' => '',
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_150',
                    'field' => [
                        'type' => 'select',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'red',
                                'value' => 'Red',
                            ],
                            [
                                'key' => 'blue',
                                'value' => 'Blue',
                            ],
                        ],
                        'multiple' => true,
                    ],
                ],
            ],
        ],
        'password field with inputs confirm password is hidden not set' => [
            'source' => [
                \array_merge(
                    $baseField_password,
                    [
                        'inputs' => [
                            [
                                'id' => '148',
                                'label' => 'Enter Password',
                                'name' => '',
                            ],
                            [
                                'id' => '148.2',
                                'label' => 'Confirm Password',
                                'name' => '',
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_148',
                    'field' => [
                        'type' => 'password',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'password field with inputs confirm password is hidden false' => [
            'source' => [
                \array_merge(
                    $baseField_password,
                    [
                        'inputs' => [
                            [
                                'id' => '148',
                                'label' => 'Enter Password',
                                'name' => '',
                            ],
                            [
                                'id' => '148.2',
                                'label' => 'Confirm Password',
                                'name' => '',
                                'isHidden' => false,
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_148',
                    'field' => [
                        'type' => 'password',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'checkbox input type poll field with enable randomize choices true' => [
            'source' => [
                \array_merge(
                    $baseField_poll_checkbox,
                    ['enableRandomizeChoices' => true]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_152',
                    'field' => [
                        'type' => 'checkboxes',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'First Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Second Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Third Choice',
                                'value' => null,
                            ],
                        ],
                        'randomize_order' => true,
                    ],
                ],
            ],
        ],
        'radio input type poll field with enable randomize choices true' => [
            'source' => [
                \array_merge(
                    $baseField_poll_radio,
                    ['enableRandomizeChoices' => true]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_133',
                    'field' => [
                        'type' => 'radio',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'First Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Second Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Third Choice',
                                'value' => null,
                            ],
                        ],
                        'randomize_order' => true,
                    ],
                ],
            ],
        ],
        'select input type poll field with enable randomize choices true' => [
            'source' => [
                \array_merge(
                    $baseField_poll_select,
                    ['enableRandomizeChoices' => true]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_153',
                    'field' => [
                        'type' => 'select',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'First Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Second Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Third Choice',
                                'value' => null,
                            ],
                        ],
                        'randomize_order' => true,
                    ],
                ],
            ],
        ],
        'select input type post category field' => [
            'source' => [
                \array_merge(
                    $baseField_post_category_select,
                    []
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_151',
                    'field' => [
                        'type' => 'select',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'radio input type post category field' => [
            'source' => [
                \array_merge(
                    $baseField_post_category_radio,
                    []
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_158',
                    'field' => [
                        'type' => 'radio',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'checkbox input type post category field' => [
            'source' => [
                \array_merge(
                    $baseField_post_category_checkbox,
                    []
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_165',
                    'field' => [
                        'type' => 'checkboxes',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'multiselect input type post category field' => [
            'source' => [
                \array_merge(
                    $baseField_post_category_multiselect,
                    []
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_166',
                    'field' => [
                        'type' => 'select',
                        'display' => 'Test Field',
                        'multiple' => true,
                    ],
                ],
            ],
        ],
        'post content field with use rich text editor false' => [
            'source' => [
                \array_merge(
                    $baseField_post_content,
                    []
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_155',
                    'field' => [
                        'type' => 'textarea',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'post content field with use rich text editor false and max length 42' => [
            'source' => [
                \array_merge(
                    $baseField_post_content,
                    ['maxLength' => 42]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_155',
                    'field' => [
                        'type' => 'textarea',
                        'display' => 'Test Field',
                        'character_limit' => 42,
                    ],
                ],
            ],
        ],
        'post content field with use rich text editor false and max length "42"' => [
            'source' => [
                \array_merge(
                    $baseField_post_content,
                    ['maxLength' => '42']
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_155',
                    'field' => [
                        'type' => 'textarea',
                        'display' => 'Test Field',
                        'character_limit' => 42,
                    ],
                ],
            ],
        ],
        'post image field' => [
            'source' => [
                \array_merge(
                    $baseField_post_image,
                    []
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_159',
                    'field' => [
                        'type' => 'assets',
                        'display' => 'Test Field',
                        'container' => 'form_entry_uploads',
                        'folder' => $siteHandle,
                        'dynamic' => 'id',
                        'restrict' => true,
                        'max_files' => 1,
                        'file_type_restrictions' => 'mime_types',
                        'mime_types' => [
                            'image/jpeg',
                            'image/png',
                            'image/gif',
                        ],
                    ],
                ],
            ],
        ],
        'post tags field with input type not set' => [
            'source' => [
                \array_merge(
                    $baseField_post_tags_not_set,
                    []
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_167',
                    'field' => [
                        'type' => 'list',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'post tags field with input type empty' => [
            'source' => [
                \array_merge(
                    $baseField_post_tags_empty,
                    []
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_157',
                    'field' => [
                        'type' => 'list',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'text input type post tags field' => [
            'source' => [
                \array_merge(
                    $baseField_post_tags_text,
                    []
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_168',
                    'field' => [
                        'type' => 'list',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'select input type post tags field' => [
            'source' => [
                \array_merge(
                    $baseField_post_tags_select,
                    []
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_169',
                    'field' => [
                        'type' => 'select',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'First Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Second Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Third Choice',
                                'value' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'checkbox input type post tags field' => [
            'source' => [
                \array_merge(
                    $baseField_post_tags_checkbox,
                    []
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_170',
                    'field' => [
                        'type' => 'checkboxes',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'First Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Second Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Third Choice',
                                'value' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'radio input type post tags field' => [
            'source' => [
                \array_merge(
                    $baseField_post_tags_radio,
                    []
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_171',
                    'field' => [
                        'type' => 'radio',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'First Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Second Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Third Choice',
                                'value' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'multiselect input type post tags field' => [
            'source' => [
                \array_merge(
                    $baseField_post_tags_multiselect,
                    []
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_172',
                    'field' => [
                        'type' => 'select',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'First Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Second Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Third Choice',
                                'value' => null,
                            ],
                        ],
                        'multiple' => true,
                    ],
                ],
            ],
        ],
        'file upload input type post custom field field' => [
            'source' => [
                \array_merge(
                    $baseField_post_custom_field_fileupload,
                    []
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_184',
                    'field' => [
                        'type' => 'assets',
                        'display' => 'Test Field',
                        'container' => 'form_entry_uploads',
                        'folder' => 'supply_chain',
                        'dynamic' => 'id',
                        'restrict' => true,
                        'max_files' => 1,
                    ],
                ],
            ],
        ],
        'email input type post custom field field' => [
            'source' => [
                \array_merge(
                    $baseField_post_custom_field_email,
                    []
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_183',
                    'field' => [
                        'type' => 'email',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'website input type post custom field field' => [
            'source' => [
                \array_merge(
                    $baseField_post_custom_field_website,
                    []
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_182',
                    'field' => [
                        'type' => 'url',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'list input type post custom field field' => [
            'source' => [
                \array_merge(
                    $baseField_post_custom_field_list,
                    []
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_185',
                    'field' => [
                        'type' => 'list',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'list input type post custom field field and enable columns true' => [
            'source' => [
                \array_merge(
                    $baseField_post_custom_field_list,
                    [
                        'enableColumns' => true,
                        'choices' => [
                            [
                                'text' => 'Foo',
                                'value' => 'Foo',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Bar',
                                'value' => 'Bar',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Baz',
                                'value' => 'Baz',
                                'isSelected' => false,
                                'price' => '',
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_185',
                    'field' => [
                        'type' => 'grid',
                        'display' => 'Test Field',
                        'mode' => 'stacked',
                        'fields' => [
                            [
                                'handle' => 'test_field_185_foo_1',
                                'field' => [
                                    'type' => 'text',
                                    'display' => 'Foo',
                                ],
                            ],
                            [
                                'handle' => 'test_field_185_bar_2',
                                'field' => [
                                    'type' => 'text',
                                    'display' => 'Bar',
                                ],
                            ],
                            [
                                'handle' => 'test_field_185_baz_3',
                                'field' => [
                                    'type' => 'text',
                                    'display' => 'Baz',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'time input type post custom field field' => [
            'source' => [
                \array_merge(
                    $baseField_post_custom_field_time,
                    []
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_181',
                    'field' => [
                        'type' => 'time',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'date input type post custom field field' => [
            'source' => [
                \array_merge(
                    $baseField_post_custom_field_date,
                    []
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_180',
                    'field' => [
                        'type' => 'date',
                        'display' => 'Test Field',
                        'format' => 'm/d/Y',
                    ],
                ],
            ],
        ],
        'hidden input type post custom field field' => [
            'source' => [
                \array_merge(
                    $baseField_post_custom_field_hidden,
                    []
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_179',
                    'field' => [
                        'type' => 'hidden',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'hidden fieldtype' => [
            'source' => [
                \array_merge(
                    $baseField_hidden,
                    []
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_187',
                    'field' => [
                        'type' => 'hidden',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'hidden fieldtype with allows prepopulate true and input name foo' => [
            'source' => [
                \array_merge(
                    $baseField_hidden,
                    [
                        'allowsPrepopulate' => true,
                        'inputName' => 'foo',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_187',
                    'field' => [
                        'type' => 'hidden',
                        'display' => 'Test Field',
                        'calculation_query' => true,
                        'calculation_formula_text' => '[get_param:foo]',
                    ],
                ],
            ],
        ],
        'hidden fieldtype with allows prepopulate "1" and input name foo' => [
            'source' => [
                \array_merge(
                    $baseField_hidden,
                    [
                        'allowsPrepopulate' => '1',
                        'inputName' => 'foo',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_187',
                    'field' => [
                        'type' => 'hidden',
                        'display' => 'Test Field',
                        'calculation_query' => true,
                        'calculation_formula_text' => '[get_param:foo]',
                    ],
                ],
            ],
        ],
        'hidden fieldtype with allows prepopulate true and input name empty' => [
            'source' => [
                \array_merge(
                    $baseField_hidden,
                    [
                        'allowsPrepopulate' => true,
                        'inputName' => '',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_187',
                    'field' => [
                        'type' => 'hidden',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'hidden fieldtype with allows prepopulate "1" and input name empty' => [
            'source' => [
                \array_merge(
                    $baseField_hidden,
                    [
                        'allowsPrepopulate' => '1',
                        'inputName' => '',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_187',
                    'field' => [
                        'type' => 'hidden',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'radio input type post custom field field' => [
            'source' => [
                \array_merge(
                    $baseField_post_custom_field_radio,
                    []
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_178',
                    'field' => [
                        'type' => 'radio',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'First Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Second Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Third Choice',
                                'value' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'checkbox input type post custom field field' => [
            'source' => [
                \array_merge(
                    $baseField_post_custom_field_checkbox,
                    []
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_177',
                    'field' => [
                        'type' => 'checkboxes',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'First Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Second Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Third Choice',
                                'value' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'number input type post custom field field' => [
            'source' => [
                \array_merge(
                    $baseField_post_custom_field_number,
                    []
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_176',
                    'field' => [
                        'type' => 'float',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'multiselect input type post custom field field' => [
            'source' => [
                \array_merge(
                    $baseField_post_custom_field_multiselect,
                    []
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_175',
                    'field' => [
                        'type' => 'select',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'First Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Second Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Third Choice',
                                'value' => null,
                            ],
                        ],
                        'multiple' => true,
                    ],
                ],
            ],
        ],
        'select input type post custom field field' => [
            'source' => [
                \array_merge(
                    $baseField_post_custom_field_select,
                    []
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_174',
                    'field' => [
                        'type' => 'select',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'First Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Second Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Third Choice',
                                'value' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'textarea input type post custom field field' => [
            'source' => [
                \array_merge(
                    $baseField_post_custom_field_textarea,
                    []
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_173',
                    'field' => [
                        'type' => 'textarea',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'text input type post custom field field' => [
            'source' => [
                \array_merge(
                    $baseField_post_custom_field_text,
                    []
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_134',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'date field with allows prepopulate and input name' => [
            'source' => [
                \array_merge(
                    $baseField_date,
                    [
                        'allowsPrepopulate' => true,
                        'inputName' => '{today}',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_127',
                    'field' => [
                        'type' => 'date',
                        'display' => 'Test Field',
                        'format' => 'm/d/Y',
                    ],
                ],
            ],
        ],
        'radio field with is selected true and enable choice value false' => [
            'source' => [
                \array_merge(
                    $baseField_radio,
                    [
                        'choices' => [
                            [
                                'text' => 'Red',
                                'value' => 'Red',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Blue',
                                'value' => 'Blue',
                                'isSelected' => true,
                                'price' => '',
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_137',
                    'field' => [
                        'type' => 'radio',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'Red',
                                'value' => null,
                            ],
                            [
                                'key' => 'Blue',
                                'value' => null,
                            ],
                        ],
                        'default' => 'Blue',
                    ],
                ],
            ],
        ],
        'radio field with is selected true and enable choice value true' => [
            'source' => [
                \array_merge(
                    $baseField_radio,
                    [
                        'enableChoiceValue' => true,
                        'choices' => [
                            [
                                'text' => 'Red',
                                'value' => 'red',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Blue',
                                'value' => 'blue',
                                'isSelected' => true,
                                'price' => '',
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_137',
                    'field' => [
                        'type' => 'radio',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'red',
                                'value' => 'Red',
                            ],
                            [
                                'key' => 'blue',
                                'value' => 'Blue',
                            ],
                        ],
                        'default' => 'blue',
                    ],
                ],
            ],
        ],
        'checkbox field with is selected true and enable choice value false' => [
            'source' => [
                \array_merge(
                    $baseField_checkbox,
                    [
                        'choices' => [
                            [
                                'text' => 'Red',
                                'value' => 'Red',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Blue',
                                'value' => 'Blue',
                                'isSelected' => true,
                                'price' => '',
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_139',
                    'field' => [
                        'type' => 'checkboxes',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'Red',
                                'value' => null,
                            ],
                            [
                                'key' => 'Blue',
                                'value' => null,
                            ],
                        ],
                        'default' => 'Blue',
                    ],
                ],
            ],
        ],
        'checkbox field with is selected true and enable choice value true' => [
            'source' => [
                \array_merge(
                    $baseField_checkbox,
                    [
                        'enableChoiceValue' => true,
                        'choices' => [
                            [
                                'text' => 'Red',
                                'value' => 'red',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Blue',
                                'value' => 'blue',
                                'isSelected' => true,
                                'price' => '',
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_139',
                    'field' => [
                        'type' => 'checkboxes',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'red',
                                'value' => 'Red',
                            ],
                            [
                                'key' => 'blue',
                                'value' => 'Blue',
                            ],
                        ],
                        'default' => 'blue',
                    ],
                ],
            ],
        ],
        'select field with is selected true and enable choice value false' => [
            'source' => [
                \array_merge(
                    $baseField_select,
                    [
                        'choices' => [
                            [
                                'text' => 'Red',
                                'value' => 'Red',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Blue',
                                'value' => 'Blue',
                                'isSelected' => true,
                                'price' => '',
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_140',
                    'field' => [
                        'type' => 'select',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'Red',
                                'value' => null,
                            ],
                            [
                                'key' => 'Blue',
                                'value' => null,
                            ],
                        ],
                        'default' => 'Blue',
                    ],
                ],
            ],
        ],
        'select field with is selected true and enable choice value true' => [
            'source' => [
                \array_merge(
                    $baseField_select,
                    [
                        'enableChoiceValue' => true,
                        'choices' => [
                            [
                                'text' => 'Red',
                                'value' => 'red',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Blue',
                                'value' => 'blue',
                                'isSelected' => true,
                                'price' => '',
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_140',
                    'field' => [
                        'type' => 'select',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'red',
                                'value' => 'Red',
                            ],
                            [
                                'key' => 'blue',
                                'value' => 'Blue',
                            ],
                        ],
                        'default' => 'blue',
                    ],
                ],
            ],
        ],
        'multiselect field with is selected true and enable choice value false' => [
            'source' => [
                \array_merge(
                    $baseField_multiselect,
                    [
                        'choices' => [
                            [
                                'text' => 'Red',
                                'value' => 'Red',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Blue',
                                'value' => 'Blue',
                                'isSelected' => true,
                                'price' => '',
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_150',
                    'field' => [
                        'type' => 'select',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'Red',
                                'value' => null,
                            ],
                            [
                                'key' => 'Blue',
                                'value' => null,
                            ],
                        ],
                        'multiple' => true,
                        'default' => 'Blue',
                    ],
                ],
            ],
        ],
        'multiselect field with is selected true and enable choice value true' => [
            'source' => [
                \array_merge(
                    $baseField_multiselect,
                    [
                        'enableChoiceValue' => true,
                        'choices' => [
                            [
                                'text' => 'Red',
                                'value' => 'red',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Blue',
                                'value' => 'blue',
                                'isSelected' => true,
                                'price' => '',
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_150',
                    'field' => [
                        'type' => 'select',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'red',
                                'value' => 'Red',
                            ],
                            [
                                'key' => 'blue',
                                'value' => 'Blue',
                            ],
                        ],
                        'multiple' => true,
                        'default' => 'blue',
                    ],
                ],
            ],
        ],
        'multi-choice checkbox exactly field with is selected true and enable choice value false' => [
            'source' => [
                \array_merge(
                    $baseField_multi_choice_checkbox_exactly,
                    [
                        'choices' => [
                            [
                                'text' => 'First Choice',
                                'value' => 'First Choice',
                                'isSelected' => false,
                                'price' => '',
                                'key' => '0000000000000000a',
                            ],
                            [
                                'text' => 'Second Choice',
                                'value' => 'Second Choice',
                                'isSelected' => true,
                                'price' => '',
                                'key' => '0000000000000000b',
                            ],
                            [
                                'text' => 'Third Choice',
                                'value' => 'Third Choice',
                                'isSelected' => false,
                                'price' => '',
                                'key' => '0000000000000000c',
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_143',
                    'field' => [
                        'type' => 'checkboxes',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'First Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Second Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Third Choice',
                                'value' => null,
                            ],
                        ],
                        'selection_limit' => 'exact',
                        'exact_selections' => '2',
                        'default' => 'Second Choice',
                    ],
                ],
            ],
        ],
        'multi-choice checkbox exactly with is selected true and enable choice value true' => [
            'source' => [
                \array_merge(
                    $baseField_multi_choice_checkbox_exactly,
                    [
                        'enableChoiceValue' => true,
                        'choices' => [
                            [
                                'text' => 'First Choice',
                                'value' => 'first_choice',
                                'isSelected' => false,
                                'price' => '',
                                'key' => '0000000000000000a',
                            ],
                            [
                                'text' => 'Second Choice',
                                'value' => 'second_choice',
                                'isSelected' => true,
                                'price' => '',
                                'key' => '0000000000000000b',
                            ],
                            [
                                'text' => 'Third Choice',
                                'value' => 'third_choice',
                                'isSelected' => false,
                                'price' => '',
                                'key' => '0000000000000000c',
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_143',
                    'field' => [
                        'type' => 'checkboxes',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'first_choice',
                                'value' => 'First Choice',
                            ],
                            [
                                'key' => 'second_choice',
                                'value' => 'Second Choice',
                            ],
                            [
                                'key' => 'third_choice',
                                'value' => 'Third Choice',
                            ],
                        ],
                        'selection_limit' => 'exact',
                        'exact_selections' => '2',
                        'default' => 'second_choice',
                    ],
                ],
            ],
        ],
        'multi-choice checkbox range field with is selected true and enable choice value false' => [
            'source' => [
                \array_merge(
                    $baseField_multi_choice_checkbox_range,
                    [
                        'choices' => [
                            [
                                'text' => 'First Choice',
                                'value' => 'First Choice',
                                'isSelected' => false,
                                'price' => '',
                                'key' => '0000000000000000a',
                            ],
                            [
                                'text' => 'Second Choice',
                                'value' => 'Second Choice',
                                'isSelected' => true,
                                'price' => '',
                                'key' => '0000000000000000b',
                            ],
                            [
                                'text' => 'Third Choice',
                                'value' => 'Third Choice',
                                'isSelected' => false,
                                'price' => '',
                                'key' => '0000000000000000c',
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_144',
                    'field' => [
                        'type' => 'checkboxes',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'First Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Second Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Third Choice',
                                'value' => null,
                            ],
                        ],
                        'selection_limit' => 'range',
                        'min_selections' => '2',
                        'max_selections' => '3',
                        'default' => 'Second Choice',
                    ],
                ],
            ],
        ],
        'multi-choice checkbox range field with is selected true and enable choice value true' => [
            'source' => [
                \array_merge(
                    $baseField_multi_choice_checkbox_range,
                    [
                        'enableChoiceValue' => true,
                        'choices' => [
                            [
                                'text' => 'First Choice',
                                'value' => 'first_choice',
                                'isSelected' => false,
                                'price' => '',
                                'key' => '0000000000000000a',
                            ],
                            [
                                'text' => 'Second Choice',
                                'value' => 'second_choice',
                                'isSelected' => true,
                                'price' => '',
                                'key' => '0000000000000000b',
                            ],
                            [
                                'text' => 'Third Choice',
                                'value' => 'third_choice',
                                'isSelected' => false,
                                'price' => '',
                                'key' => '0000000000000000c',
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_144',
                    'field' => [
                        'type' => 'checkboxes',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'first_choice',
                                'value' => 'First Choice',
                            ],
                            [
                                'key' => 'second_choice',
                                'value' => 'Second Choice',
                            ],
                            [
                                'key' => 'third_choice',
                                'value' => 'Third Choice',
                            ],
                        ],
                        'selection_limit' => 'range',
                        'min_selections' => '2',
                        'max_selections' => '3',
                        'default' => 'second_choice',
                    ],
                ],
            ],
        ],
        'multi-choice checkbox unlimited field with is selected true and enable choice value false' => [
            'source' => [
                \array_merge(
                    $baseField_multi_choice_checkbox_unlimited,
                    [
                        'choices' => [
                            [
                                'text' => 'First Choice',
                                'value' => 'First Choice',
                                'isSelected' => false,
                                'price' => '',
                                'key' => '0000000000000000a',
                            ],
                            [
                                'text' => 'Second Choice',
                                'value' => 'Second Choice',
                                'isSelected' => true,
                                'price' => '',
                                'key' => '0000000000000000b',
                            ],
                            [
                                'text' => 'Third Choice',
                                'value' => 'Third Choice',
                                'isSelected' => false,
                                'price' => '',
                                'key' => '0000000000000000c',
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_142',
                    'field' => [
                        'type' => 'checkboxes',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'First Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Second Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Third Choice',
                                'value' => null,
                            ],
                        ],
                        'default' => 'Second Choice',
                    ],
                ],
            ],
        ],
        'multi-choice checkbox unlimited field with is selected true and enable choice value true' => [
            'source' => [
                \array_merge(
                    $baseField_multi_choice_checkbox_unlimited,
                    [
                        'enableChoiceValue' => true,
                        'choices' => [
                            [
                                'text' => 'First Choice',
                                'value' => 'first_choice',
                                'isSelected' => false,
                                'price' => '',
                                'key' => '0000000000000000a',
                            ],
                            [
                                'text' => 'Second Choice',
                                'value' => 'second_choice',
                                'isSelected' => true,
                                'price' => '',
                                'key' => '0000000000000000b',
                            ],
                            [
                                'text' => 'Third Choice',
                                'value' => 'third_choice',
                                'isSelected' => false,
                                'price' => '',
                                'key' => '0000000000000000c',
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_142',
                    'field' => [
                        'type' => 'checkboxes',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'first_choice',
                                'value' => 'First Choice',
                            ],
                            [
                                'key' => 'second_choice',
                                'value' => 'Second Choice',
                            ],
                            [
                                'key' => 'third_choice',
                                'value' => 'Third Choice',
                            ],
                        ],
                        'default' => 'second_choice',
                    ],
                ],
            ],
        ],
        'radio input type multi-choice field with is selected true and enable choice value false' => [
            'source' => [
                \array_merge(
                    $baseField_multi_choice_radio,
                    [
                        'choices' => [
                            [
                                'text' => 'First Choice',
                                'value' => 'First Choice',
                                'isSelected' => false,
                                'price' => '',
                                'key' => '0000000000000000a',
                            ],
                            [
                                'text' => 'Second Choice',
                                'value' => 'Second Choice',
                                'isSelected' => true,
                                'price' => '',
                                'key' => '0000000000000000b',
                            ],
                            [
                                'text' => 'Third Choice',
                                'value' => 'Third Choice',
                                'isSelected' => false,
                                'price' => '',
                                'key' => '0000000000000000c',
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_130',
                    'field' => [
                        'type' => 'radio',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'First Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Second Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Third Choice',
                                'value' => null,
                            ],
                        ],
                        'default' => 'Second Choice',
                    ],
                ],
            ],
        ],
        'radio input type multi-choice field with is selected true and enable choice value true' => [
            'source' => [
                \array_merge(
                    $baseField_multi_choice_radio,
                    [
                        'enableChoiceValue' => true,
                        'choices' => [
                            [
                                'text' => 'First Choice',
                                'value' => 'first_choice',
                                'isSelected' => false,
                                'price' => '',
                                'key' => '0000000000000000a',
                            ],
                            [
                                'text' => 'Second Choice',
                                'value' => 'second_choice',
                                'isSelected' => true,
                                'price' => '',
                                'key' => '0000000000000000b',
                            ],
                            [
                                'text' => 'Third Choice',
                                'value' => 'third_choice',
                                'isSelected' => false,
                                'price' => '',
                                'key' => '0000000000000000c',
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_130',
                    'field' => [
                        'type' => 'radio',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'first_choice',
                                'value' => 'First Choice',
                            ],
                            [
                                'key' => 'second_choice',
                                'value' => 'Second Choice',
                            ],
                            [
                                'key' => 'third_choice',
                                'value' => 'Third Choice',
                            ],
                        ],
                        'default' => 'second_choice',
                    ],
                ],
            ],
        ],
        'select input type post tags field and is selected true' => [
            'source' => [
                \array_merge(
                    $baseField_post_tags_select,
                    [
                        'choices' => [
                            [
                                'text' => 'First Choice',
                                'value' => 'First Choice',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Second Choice',
                                'value' => 'Second Choice',
                                'isSelected' => true,
                                'price' => '',
                            ],
                            [
                                'text' => 'Third Choice',
                                'value' => 'Third Choice',
                                'isSelected' => false,
                                'price' => '',
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_169',
                    'field' => [
                        'type' => 'select',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'First Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Second Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Third Choice',
                                'value' => null,
                            ],
                        ],
                        'default' => 'Second Choice',
                    ],
                ],
            ],
        ],
        'checkbox input type post tags field and is selected true' => [
            'source' => [
                \array_merge(
                    $baseField_post_tags_checkbox,
                    [
                        'choices' => [
                            [
                                'text' => 'First Choice',
                                'value' => 'First Choice',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Second Choice',
                                'value' => 'Second Choice',
                                'isSelected' => true,
                                'price' => '',
                            ],
                            [
                                'text' => 'Third Choice',
                                'value' => 'Third Choice',
                                'isSelected' => false,
                                'price' => '',
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_170',
                    'field' => [
                        'type' => 'checkboxes',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'First Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Second Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Third Choice',
                                'value' => null,
                            ],
                        ],
                        'default' => 'Second Choice',
                    ],
                ],
            ],
        ],
        'radio input type post tags field and is selected true' => [
            'source' => [
                \array_merge(
                    $baseField_post_tags_radio,
                    [
                        'choices' => [
                            [
                                'text' => 'First Choice',
                                'value' => 'First Choice',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Second Choice',
                                'value' => 'Second Choice',
                                'isSelected' => true,
                                'price' => '',
                            ],
                            [
                                'text' => 'Third Choice',
                                'value' => 'Third Choice',
                                'isSelected' => false,
                                'price' => '',
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_171',
                    'field' => [
                        'type' => 'radio',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'First Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Second Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Third Choice',
                                'value' => null,
                            ],
                        ],
                        'default' => 'Second Choice',
                    ],
                ],
            ],
        ],
        'multiselect input type post tags field and is selected true' => [
            'source' => [
                \array_merge(
                    $baseField_post_tags_multiselect,
                    [
                        'choices' => [
                            [
                                'text' => 'First Choice',
                                'value' => 'First Choice',
                                'isSelected' => false,
                                'price' => '',
                            ],
                            [
                                'text' => 'Second Choice',
                                'value' => 'Second Choice',
                                'isSelected' => true,
                                'price' => '',
                            ],
                            [
                                'text' => 'Third Choice',
                                'value' => 'Third Choice',
                                'isSelected' => false,
                                'price' => '',
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_172',
                    'field' => [
                        'type' => 'select',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'First Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Second Choice',
                                'value' => null,
                            ],
                            [
                                'key' => 'Third Choice',
                                'value' => null,
                            ],
                        ],
                        'multiple' => true,
                        'default' => 'Second Choice',
                    ],
                ],
            ],
        ],
        'address field with address type international and hiding street address' => [
            'source' => [
                \array_merge(
                    $baseField_address,
                    [
                        'addressType' => 'international',
                        'inputs' => [
                            [
                                'id' => '125.1',
                                'label' => 'Street Address',
                                'name' => '',
                                'autocompleteAttribute' => 'address-line1',
                                'isHidden' => true,
                            ],
                            [
                                'id' => '125.2',
                                'label' => 'Address Line 2',
                                'name' => '',
                                'autocompleteAttribute' => 'address-line2',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.3',
                                'label' => 'City',
                                'name' => '',
                                'autocompleteAttribute' => 'address-level2',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.4',
                                'label' => 'State \/ Province',
                                'name' => '',
                                'autocompleteAttribute' => 'address-level1',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.5',
                                'label' => 'ZIP \/ Postal Code',
                                'name' => '',
                                'autocompleteAttribute' => 'postal-code',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.6',
                                'label' => 'Country',
                                'name' => '',
                                'autocompleteAttribute' => 'country-name',
                                'isHidden' => false,
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_125',
                    'field' => [
                        'type' => 'address',
                        'display' => 'Test Field',
                        'preset' => 'international',
                        'street_address_include' => false,
                    ],
                ],
            ],
        ],
        'address field with address type canadian and hiding street address' => [
            'source' => [
                \array_merge(
                    $baseField_address,
                    [
                        'addressType' => 'canadian',
                        'inputs' => [
                            [
                                'id' => '125.1',
                                'label' => 'Street Address',
                                'name' => '',
                                'autocompleteAttribute' => 'address-line1',
                                'isHidden' => true,
                            ],
                            [
                                'id' => '125.2',
                                'label' => 'Address Line 2',
                                'name' => '',
                                'autocompleteAttribute' => 'address-line2',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.3',
                                'label' => 'City',
                                'name' => '',
                                'autocompleteAttribute' => 'address-level2',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.4',
                                'label' => 'State \/ Province',
                                'name' => '',
                                'autocompleteAttribute' => 'address-level1',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.5',
                                'label' => 'ZIP \/ Postal Code',
                                'name' => '',
                                'autocompleteAttribute' => 'postal-code',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.6',
                                'label' => 'Country',
                                'name' => '',
                                'autocompleteAttribute' => 'country-name',
                                'isHidden' => false,
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_125',
                    'field' => [
                        'type' => 'address',
                        'display' => 'Test Field',
                        'preset' => 'canadian',
                        'street_address_include' => false,
                    ],
                ],
            ],
        ],
        'address field with address type us and hiding street address' => [
            'source' => [
                \array_merge(
                    $baseField_address,
                    [
                        'addressType' => 'us',
                        'inputs' => [
                            [
                                'id' => '125.1',
                                'label' => 'Street Address',
                                'name' => '',
                                'autocompleteAttribute' => 'address-line1',
                                'isHidden' => true,
                            ],
                            [
                                'id' => '125.2',
                                'label' => 'Address Line 2',
                                'name' => '',
                                'autocompleteAttribute' => 'address-line2',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.3',
                                'label' => 'City',
                                'name' => '',
                                'autocompleteAttribute' => 'address-level2',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.4',
                                'label' => 'State \/ Province',
                                'name' => '',
                                'autocompleteAttribute' => 'address-level1',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.5',
                                'label' => 'ZIP \/ Postal Code',
                                'name' => '',
                                'autocompleteAttribute' => 'postal-code',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.6',
                                'label' => 'Country',
                                'name' => '',
                                'autocompleteAttribute' => 'country-name',
                                'isHidden' => false,
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_125',
                    'field' => [
                        'type' => 'address',
                        'display' => 'Test Field',
                        'street_address_include' => false,
                    ],
                ],
            ],
        ],
        'address field with address type international and hiding address line 2' => [
            'source' => [
                \array_merge(
                    $baseField_address,
                    [
                        'addressType' => 'international',
                        'inputs' => [
                            [
                                'id' => '125.1',
                                'label' => 'Street Address',
                                'name' => '',
                                'autocompleteAttribute' => 'address-line1',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.2',
                                'label' => 'Address Line 2',
                                'name' => '',
                                'autocompleteAttribute' => 'address-line2',
                                'isHidden' => true,
                            ],
                            [
                                'id' => '125.3',
                                'label' => 'City',
                                'name' => '',
                                'autocompleteAttribute' => 'address-level2',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.4',
                                'label' => 'State \/ Province',
                                'name' => '',
                                'autocompleteAttribute' => 'address-level1',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.5',
                                'label' => 'ZIP \/ Postal Code',
                                'name' => '',
                                'autocompleteAttribute' => 'postal-code',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.6',
                                'label' => 'Country',
                                'name' => '',
                                'autocompleteAttribute' => 'country-name',
                                'isHidden' => false,
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_125',
                    'field' => [
                        'type' => 'address',
                        'display' => 'Test Field',
                        'preset' => 'international',
                        'address_line_2_include' => false,
                    ],
                ],
            ],
        ],
        'address field with address type canadian and hiding address line 2' => [
            'source' => [
                \array_merge(
                    $baseField_address,
                    [
                        'addressType' => 'canadian',
                        'inputs' => [
                            [
                                'id' => '125.1',
                                'label' => 'Street Address',
                                'name' => '',
                                'autocompleteAttribute' => 'address-line1',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.2',
                                'label' => 'Address Line 2',
                                'name' => '',
                                'autocompleteAttribute' => 'address-line2',
                                'isHidden' => true,
                            ],
                            [
                                'id' => '125.3',
                                'label' => 'City',
                                'name' => '',
                                'autocompleteAttribute' => 'address-level2',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.4',
                                'label' => 'State \/ Province',
                                'name' => '',
                                'autocompleteAttribute' => 'address-level1',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.5',
                                'label' => 'ZIP \/ Postal Code',
                                'name' => '',
                                'autocompleteAttribute' => 'postal-code',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.6',
                                'label' => 'Country',
                                'name' => '',
                                'autocompleteAttribute' => 'country-name',
                                'isHidden' => false,
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_125',
                    'field' => [
                        'type' => 'address',
                        'display' => 'Test Field',
                        'preset' => 'canadian',
                        'address_line_2_include' => false,
                    ],
                ],
            ],
        ],
        'address field with address type us and hiding address line 2' => [
            'source' => [
                \array_merge(
                    $baseField_address,
                    [
                        'addressType' => 'us',
                        'inputs' => [
                            [
                                'id' => '125.1',
                                'label' => 'Street Address',
                                'name' => '',
                                'autocompleteAttribute' => 'address-line1',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.2',
                                'label' => 'Address Line 2',
                                'name' => '',
                                'autocompleteAttribute' => 'address-line2',
                                'isHidden' => true,
                            ],
                            [
                                'id' => '125.3',
                                'label' => 'City',
                                'name' => '',
                                'autocompleteAttribute' => 'address-level2',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.4',
                                'label' => 'State \/ Province',
                                'name' => '',
                                'autocompleteAttribute' => 'address-level1',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.5',
                                'label' => 'ZIP \/ Postal Code',
                                'name' => '',
                                'autocompleteAttribute' => 'postal-code',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.6',
                                'label' => 'Country',
                                'name' => '',
                                'autocompleteAttribute' => 'country-name',
                                'isHidden' => false,
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_125',
                    'field' => [
                        'type' => 'address',
                        'display' => 'Test Field',
                        'address_line_2_include' => false,
                    ],
                ],
            ],
        ],
        'address field with address type international and hiding city' => [
            'source' => [
                \array_merge(
                    $baseField_address,
                    [
                        'addressType' => 'international',
                        'inputs' => [
                            [
                                'id' => '125.1',
                                'label' => 'Street Address',
                                'name' => '',
                                'autocompleteAttribute' => 'address-line1',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.2',
                                'label' => 'Address Line 2',
                                'name' => '',
                                'autocompleteAttribute' => 'address-line2',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.3',
                                'label' => 'City',
                                'name' => '',
                                'autocompleteAttribute' => 'address-level2',
                                'isHidden' => true,
                            ],
                            [
                                'id' => '125.4',
                                'label' => 'State \/ Province',
                                'name' => '',
                                'autocompleteAttribute' => 'address-level1',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.5',
                                'label' => 'ZIP \/ Postal Code',
                                'name' => '',
                                'autocompleteAttribute' => 'postal-code',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.6',
                                'label' => 'Country',
                                'name' => '',
                                'autocompleteAttribute' => 'country-name',
                                'isHidden' => false,
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_125',
                    'field' => [
                        'type' => 'address',
                        'display' => 'Test Field',
                        'preset' => 'international',
                        'city_include' => false,
                    ],
                ],
            ],
        ],
        'address field with address type international and hiding state' => [
            'source' => [
                \array_merge(
                    $baseField_address,
                    [
                        'addressType' => 'international',
                        'inputs' => [
                            [
                                'id' => '125.1',
                                'label' => 'Street Address',
                                'name' => '',
                                'autocompleteAttribute' => 'address-line1',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.2',
                                'label' => 'Address Line 2',
                                'name' => '',
                                'autocompleteAttribute' => 'address-line2',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.3',
                                'label' => 'City',
                                'name' => '',
                                'autocompleteAttribute' => 'address-level2',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.4',
                                'label' => 'State \/ Province',
                                'name' => '',
                                'autocompleteAttribute' => 'address-level1',
                                'isHidden' => true,
                            ],
                            [
                                'id' => '125.5',
                                'label' => 'ZIP \/ Postal Code',
                                'name' => '',
                                'autocompleteAttribute' => 'postal-code',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.6',
                                'label' => 'Country',
                                'name' => '',
                                'autocompleteAttribute' => 'country-name',
                                'isHidden' => false,
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_125',
                    'field' => [
                        'type' => 'address',
                        'display' => 'Test Field',
                        'preset' => 'international',
                        'state_province_region_international_include' => false,
                    ],
                ],
            ],
        ],
        'address field with address type canadian and hiding state' => [
            'source' => [
                \array_merge(
                    $baseField_address,
                    [
                        'addressType' => 'canadian',
                        'inputs' => [
                            [
                                'id' => '125.1',
                                'label' => 'Street Address',
                                'name' => '',
                                'autocompleteAttribute' => 'address-line1',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.2',
                                'label' => 'Address Line 2',
                                'name' => '',
                                'autocompleteAttribute' => 'address-line2',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.3',
                                'label' => 'City',
                                'name' => '',
                                'autocompleteAttribute' => 'address-level2',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.4',
                                'label' => 'State \/ Province',
                                'name' => '',
                                'autocompleteAttribute' => 'address-level1',
                                'isHidden' => true,
                            ],
                            [
                                'id' => '125.5',
                                'label' => 'ZIP \/ Postal Code',
                                'name' => '',
                                'autocompleteAttribute' => 'postal-code',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.6',
                                'label' => 'Country',
                                'name' => '',
                                'autocompleteAttribute' => 'country-name',
                                'isHidden' => false,
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_125',
                    'field' => [
                        'type' => 'address',
                        'display' => 'Test Field',
                        'preset' => 'canadian',
                        'state_province_region_canadian_include' => false,
                    ],
                ],
            ],
        ],
        'address field with address type us and hiding state' => [
            'source' => [
                \array_merge(
                    $baseField_address,
                    [
                        'addressType' => 'us',
                        'inputs' => [
                            [
                                'id' => '125.1',
                                'label' => 'Street Address',
                                'name' => '',
                                'autocompleteAttribute' => 'address-line1',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.2',
                                'label' => 'Address Line 2',
                                'name' => '',
                                'autocompleteAttribute' => 'address-line2',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.3',
                                'label' => 'City',
                                'name' => '',
                                'autocompleteAttribute' => 'address-level2',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.4',
                                'label' => 'State \/ Province',
                                'name' => '',
                                'autocompleteAttribute' => 'address-level1',
                                'isHidden' => true,
                            ],
                            [
                                'id' => '125.5',
                                'label' => 'ZIP \/ Postal Code',
                                'name' => '',
                                'autocompleteAttribute' => 'postal-code',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.6',
                                'label' => 'Country',
                                'name' => '',
                                'autocompleteAttribute' => 'country-name',
                                'isHidden' => false,
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_125',
                    'field' => [
                        'type' => 'address',
                        'display' => 'Test Field',
                        'state_province_region_american_include' => false,
                    ],
                ],
            ],
        ],
        'address field with address type international and hiding postal code' => [
            'source' => [
                \array_merge(
                    $baseField_address,
                    [
                        'addressType' => 'international',
                        'inputs' => [
                            [
                                'id' => '125.1',
                                'label' => 'Street Address',
                                'name' => '',
                                'autocompleteAttribute' => 'address-line1',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.2',
                                'label' => 'Address Line 2',
                                'name' => '',
                                'autocompleteAttribute' => 'address-line2',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.3',
                                'label' => 'City',
                                'name' => '',
                                'autocompleteAttribute' => 'address-level2',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.4',
                                'label' => 'State \/ Province',
                                'name' => '',
                                'autocompleteAttribute' => 'address-level1',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.5',
                                'label' => 'ZIP \/ Postal Code',
                                'name' => '',
                                'autocompleteAttribute' => 'postal-code',
                                'isHidden' => true,
                            ],
                            [
                                'id' => '125.6',
                                'label' => 'Country',
                                'name' => '',
                                'autocompleteAttribute' => 'country-name',
                                'isHidden' => false,
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_125',
                    'field' => [
                        'type' => 'address',
                        'display' => 'Test Field',
                        'preset' => 'international',
                        'postal_code_include' => false,
                    ],
                ],
            ],
        ],
        'address field with address type canadian and hiding postal code' => [
            'source' => [
                \array_merge(
                    $baseField_address,
                    [
                        'addressType' => 'canadian',
                        'inputs' => [
                            [
                                'id' => '125.1',
                                'label' => 'Street Address',
                                'name' => '',
                                'autocompleteAttribute' => 'address-line1',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.2',
                                'label' => 'Address Line 2',
                                'name' => '',
                                'autocompleteAttribute' => 'address-line2',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.3',
                                'label' => 'City',
                                'name' => '',
                                'autocompleteAttribute' => 'address-level2',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.4',
                                'label' => 'State \/ Province',
                                'name' => '',
                                'autocompleteAttribute' => 'address-level1',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.5',
                                'label' => 'ZIP \/ Postal Code',
                                'name' => '',
                                'autocompleteAttribute' => 'postal-code',
                                'isHidden' => true,
                            ],
                            [
                                'id' => '125.6',
                                'label' => 'Country',
                                'name' => '',
                                'autocompleteAttribute' => 'country-name',
                                'isHidden' => false,
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_125',
                    'field' => [
                        'type' => 'address',
                        'display' => 'Test Field',
                        'preset' => 'canadian',
                        'postal_code_include' => false,
                    ],
                ],
            ],
        ],
        'address field with address type us and hiding postal code' => [
            'source' => [
                \array_merge(
                    $baseField_address,
                    [
                        'addressType' => 'us',
                        'inputs' => [
                            [
                                'id' => '125.1',
                                'label' => 'Street Address',
                                'name' => '',
                                'autocompleteAttribute' => 'address-line1',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.2',
                                'label' => 'Address Line 2',
                                'name' => '',
                                'autocompleteAttribute' => 'address-line2',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.3',
                                'label' => 'City',
                                'name' => '',
                                'autocompleteAttribute' => 'address-level2',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.4',
                                'label' => 'State \/ Province',
                                'name' => '',
                                'autocompleteAttribute' => 'address-level1',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.5',
                                'label' => 'ZIP \/ Postal Code',
                                'name' => '',
                                'autocompleteAttribute' => 'postal-code',
                                'isHidden' => true,
                            ],
                            [
                                'id' => '125.6',
                                'label' => 'Country',
                                'name' => '',
                                'autocompleteAttribute' => 'country-name',
                                'isHidden' => false,
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_125',
                    'field' => [
                        'type' => 'address',
                        'display' => 'Test Field',
                        'postal_code_include' => false,
                    ],
                ],
            ],
        ],
        'address field with address type international and hiding country' => [
            'source' => [
                \array_merge(
                    $baseField_address,
                    [
                        'addressType' => 'international',
                        'inputs' => [
                            [
                                'id' => '125.1',
                                'label' => 'Street Address',
                                'name' => '',
                                'autocompleteAttribute' => 'address-line1',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.2',
                                'label' => 'Address Line 2',
                                'name' => '',
                                'autocompleteAttribute' => 'address-line2',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.3',
                                'label' => 'City',
                                'name' => '',
                                'autocompleteAttribute' => 'address-level2',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.4',
                                'label' => 'State \/ Province',
                                'name' => '',
                                'autocompleteAttribute' => 'address-level1',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.5',
                                'label' => 'ZIP \/ Postal Code',
                                'name' => '',
                                'autocompleteAttribute' => 'postal-code',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.6',
                                'label' => 'Country',
                                'name' => '',
                                'autocompleteAttribute' => 'country-name',
                                'isHidden' => true,
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_125',
                    'field' => [
                        'type' => 'address',
                        'display' => 'Test Field',
                        'preset' => 'international',
                        'country_include' => false,
                    ],
                ],
            ],
        ],
        'address field with address type canadian and hiding country' => [
            'source' => [
                \array_merge(
                    $baseField_address,
                    [
                        'addressType' => 'canadian',
                        'inputs' => [
                            [
                                'id' => '125.1',
                                'label' => 'Street Address',
                                'name' => '',
                                'autocompleteAttribute' => 'address-line1',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.2',
                                'label' => 'Address Line 2',
                                'name' => '',
                                'autocompleteAttribute' => 'address-line2',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.3',
                                'label' => 'City',
                                'name' => '',
                                'autocompleteAttribute' => 'address-level2',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.4',
                                'label' => 'State \/ Province',
                                'name' => '',
                                'autocompleteAttribute' => 'address-level1',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.5',
                                'label' => 'ZIP \/ Postal Code',
                                'name' => '',
                                'autocompleteAttribute' => 'postal-code',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.6',
                                'label' => 'Country',
                                'name' => '',
                                'autocompleteAttribute' => 'country-name',
                                'isHidden' => true,
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_125',
                    'field' => [
                        'type' => 'address',
                        'display' => 'Test Field',
                        'preset' => 'canadian',
                    ],
                ],
            ],
        ],
        'address field with address type us and hiding country' => [
            'source' => [
                \array_merge(
                    $baseField_address,
                    [
                        'addressType' => 'us',
                        'inputs' => [
                            [
                                'id' => '125.1',
                                'label' => 'Street Address',
                                'name' => '',
                                'autocompleteAttribute' => 'address-line1',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.2',
                                'label' => 'Address Line 2',
                                'name' => '',
                                'autocompleteAttribute' => 'address-line2',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.3',
                                'label' => 'City',
                                'name' => '',
                                'autocompleteAttribute' => 'address-level2',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.4',
                                'label' => 'State \/ Province',
                                'name' => '',
                                'autocompleteAttribute' => 'address-level1',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.5',
                                'label' => 'ZIP \/ Postal Code',
                                'name' => '',
                                'autocompleteAttribute' => 'postal-code',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '125.6',
                                'label' => 'Country',
                                'name' => '',
                                'autocompleteAttribute' => 'country-name',
                                'isHidden' => true,
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_125',
                    'field' => [
                        'type' => 'address',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'address field with hide address line 2 true' => [
            'source' => [
                \array_merge(
                    $baseField_address,
                    [
                        'addressType' => 'us',
                        'hideAddress2' => true,
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_125',
                    'field' => [
                        'type' => 'address',
                        'display' => 'Test Field',
                        'address_line_2_include' => false,
                    ],
                ],
            ],
        ],
        'address field with hide address line 2 false' => [
            'source' => [
                \array_merge(
                    $baseField_address,
                    [
                        'addressType' => 'us',
                        'hideAddress2' => false,
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_125',
                    'field' => [
                        'type' => 'address',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'address field with address type international and hide country true' => [
            'source' => [
                \array_merge(
                    $baseField_address,
                    [
                        'addressType' => 'international',
                        'hideCountry' => true,
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_125',
                    'field' => [
                        'type' => 'address',
                        'display' => 'Test Field',
                        'preset' => 'international',
                        'country_include' => false,
                    ],
                ],
            ],
        ],
        'name field with all subfields visible' => [
            'source' => [
                \array_merge(
                    $baseField_name,
                    [
                        'inputs' => [
                            [
                                'id' => '141.2',
                                'label' => 'Prefix',
                                'name' => '',
                                'autocompleteAttribute' => 'honorific-prefix',
                                'choices' => [
                                    [
                                        'text' => 'Dr.',
                                        'value' => 'Dr.',
                                    ],
                                    [
                                        'text' => 'Miss',
                                        'value' => 'Miss',
                                    ],
                                    [
                                        'text' => 'Mr.',
                                        'value' => 'Mr.',
                                    ],
                                    [
                                        'text' => 'Mrs.',
                                        'value' => 'Mrs.',
                                    ],
                                    [
                                        'text' => 'Ms.',
                                        'value' => 'Ms.',
                                    ],
                                    [
                                        'text' => 'Mx.',
                                        'value' => 'Mx.',
                                    ],
                                    [
                                        'text' => 'Prof.',
                                        'value' => 'Prof.',
                                    ],
                                    [
                                        'text' => 'Rev.',
                                        'value' => 'Rev.',
                                    ],
                                ],
                                'isHidden' => false,
                                'inputType' => 'radio',
                            ],
                            [
                                'id' => '141.3',
                                'label' => 'First',
                                'name' => '',
                                'autocompleteAttribute' => 'given-name',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '141.4',
                                'label' => 'Middle',
                                'name' => '',
                                'autocompleteAttribute' => 'additional-name',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '141.6',
                                'label' => 'Last',
                                'name' => '',
                                'autocompleteAttribute' => 'family-name',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '141.8',
                                'label' => 'Suffix',
                                'name' => '',
                                'autocompleteAttribute' => 'honorific-suffix',
                                'isHidden' => false,
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_141',
                    'field' => [
                        'type' => 'name',
                        'display' => 'Test Field',
                        'prefix_include' => true,
                        'middle_name_include' => true,
                        'suffix_include' => true,
                    ],
                ],
            ],
        ],
        'name field hiding prefix' => [
            'source' => [
                \array_merge(
                    $baseField_name,
                    [
                        'inputs' => [
                            [
                                'id' => '141.2',
                                'label' => 'Prefix',
                                'name' => '',
                                'autocompleteAttribute' => 'honorific-prefix',
                                'choices' => [
                                    [
                                        'text' => 'Dr.',
                                        'value' => 'Dr.',
                                    ],
                                    [
                                        'text' => 'Miss',
                                        'value' => 'Miss',
                                    ],
                                    [
                                        'text' => 'Mr.',
                                        'value' => 'Mr.',
                                    ],
                                    [
                                        'text' => 'Mrs.',
                                        'value' => 'Mrs.',
                                    ],
                                    [
                                        'text' => 'Ms.',
                                        'value' => 'Ms.',
                                    ],
                                    [
                                        'text' => 'Mx.',
                                        'value' => 'Mx.',
                                    ],
                                    [
                                        'text' => 'Prof.',
                                        'value' => 'Prof.',
                                    ],
                                    [
                                        'text' => 'Rev.',
                                        'value' => 'Rev.',
                                    ],
                                ],
                                'isHidden' => true,
                                'inputType' => 'radio',
                            ],
                            [
                                'id' => '141.3',
                                'label' => 'First',
                                'name' => '',
                                'autocompleteAttribute' => 'given-name',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '141.4',
                                'label' => 'Middle',
                                'name' => '',
                                'autocompleteAttribute' => 'additional-name',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '141.6',
                                'label' => 'Last',
                                'name' => '',
                                'autocompleteAttribute' => 'family-name',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '141.8',
                                'label' => 'Suffix',
                                'name' => '',
                                'autocompleteAttribute' => 'honorific-suffix',
                                'isHidden' => false,
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_141',
                    'field' => [
                        'type' => 'name',
                        'display' => 'Test Field',
                        'middle_name_include' => true,
                        'suffix_include' => true,
                    ],
                ],
            ],
        ],
        'name field hiding first name' => [
            'source' => [
                \array_merge(
                    $baseField_name,
                    [
                        'inputs' => [
                            [
                                'id' => '141.2',
                                'label' => 'Prefix',
                                'name' => '',
                                'autocompleteAttribute' => 'honorific-prefix',
                                'choices' => [
                                    [
                                        'text' => 'Dr.',
                                        'value' => 'Dr.',
                                    ],
                                    [
                                        'text' => 'Miss',
                                        'value' => 'Miss',
                                    ],
                                    [
                                        'text' => 'Mr.',
                                        'value' => 'Mr.',
                                    ],
                                    [
                                        'text' => 'Mrs.',
                                        'value' => 'Mrs.',
                                    ],
                                    [
                                        'text' => 'Ms.',
                                        'value' => 'Ms.',
                                    ],
                                    [
                                        'text' => 'Mx.',
                                        'value' => 'Mx.',
                                    ],
                                    [
                                        'text' => 'Prof.',
                                        'value' => 'Prof.',
                                    ],
                                    [
                                        'text' => 'Rev.',
                                        'value' => 'Rev.',
                                    ],
                                ],
                                'isHidden' => false,
                                'inputType' => 'radio',
                            ],
                            [
                                'id' => '141.3',
                                'label' => 'First',
                                'name' => '',
                                'autocompleteAttribute' => 'given-name',
                                'isHidden' => true,
                            ],
                            [
                                'id' => '141.4',
                                'label' => 'Middle',
                                'name' => '',
                                'autocompleteAttribute' => 'additional-name',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '141.6',
                                'label' => 'Last',
                                'name' => '',
                                'autocompleteAttribute' => 'family-name',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '141.8',
                                'label' => 'Suffix',
                                'name' => '',
                                'autocompleteAttribute' => 'honorific-suffix',
                                'isHidden' => false,
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_141',
                    'field' => [
                        'type' => 'name',
                        'display' => 'Test Field',
                        'prefix_include' => true,
                        'first_name_include' => false,
                        'middle_name_include' => true,
                        'suffix_include' => true,
                    ],
                ],
            ],
        ],
        'name field hiding middle name' => [
            'source' => [
                \array_merge(
                    $baseField_name,
                    [
                        'inputs' => [
                            [
                                'id' => '141.2',
                                'label' => 'Prefix',
                                'name' => '',
                                'autocompleteAttribute' => 'honorific-prefix',
                                'choices' => [
                                    [
                                        'text' => 'Dr.',
                                        'value' => 'Dr.',
                                    ],
                                    [
                                        'text' => 'Miss',
                                        'value' => 'Miss',
                                    ],
                                    [
                                        'text' => 'Mr.',
                                        'value' => 'Mr.',
                                    ],
                                    [
                                        'text' => 'Mrs.',
                                        'value' => 'Mrs.',
                                    ],
                                    [
                                        'text' => 'Ms.',
                                        'value' => 'Ms.',
                                    ],
                                    [
                                        'text' => 'Mx.',
                                        'value' => 'Mx.',
                                    ],
                                    [
                                        'text' => 'Prof.',
                                        'value' => 'Prof.',
                                    ],
                                    [
                                        'text' => 'Rev.',
                                        'value' => 'Rev.',
                                    ],
                                ],
                                'isHidden' => false,
                                'inputType' => 'radio',
                            ],
                            [
                                'id' => '141.3',
                                'label' => 'First',
                                'name' => '',
                                'autocompleteAttribute' => 'given-name',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '141.4',
                                'label' => 'Middle',
                                'name' => '',
                                'autocompleteAttribute' => 'additional-name',
                                'isHidden' => true,
                            ],
                            [
                                'id' => '141.6',
                                'label' => 'Last',
                                'name' => '',
                                'autocompleteAttribute' => 'family-name',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '141.8',
                                'label' => 'Suffix',
                                'name' => '',
                                'autocompleteAttribute' => 'honorific-suffix',
                                'isHidden' => false,
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_141',
                    'field' => [
                        'type' => 'name',
                        'display' => 'Test Field',
                        'prefix_include' => true,
                        'suffix_include' => true,
                    ],
                ],
            ],
        ],
        'name field hiding last name' => [
            'source' => [
                \array_merge(
                    $baseField_name,
                    [
                        'inputs' => [
                            [
                                'id' => '141.2',
                                'label' => 'Prefix',
                                'name' => '',
                                'autocompleteAttribute' => 'honorific-prefix',
                                'choices' => [
                                    [
                                        'text' => 'Dr.',
                                        'value' => 'Dr.',
                                    ],
                                    [
                                        'text' => 'Miss',
                                        'value' => 'Miss',
                                    ],
                                    [
                                        'text' => 'Mr.',
                                        'value' => 'Mr.',
                                    ],
                                    [
                                        'text' => 'Mrs.',
                                        'value' => 'Mrs.',
                                    ],
                                    [
                                        'text' => 'Ms.',
                                        'value' => 'Ms.',
                                    ],
                                    [
                                        'text' => 'Mx.',
                                        'value' => 'Mx.',
                                    ],
                                    [
                                        'text' => 'Prof.',
                                        'value' => 'Prof.',
                                    ],
                                    [
                                        'text' => 'Rev.',
                                        'value' => 'Rev.',
                                    ],
                                ],
                                'isHidden' => false,
                                'inputType' => 'radio',
                            ],
                            [
                                'id' => '141.3',
                                'label' => 'First',
                                'name' => '',
                                'autocompleteAttribute' => 'given-name',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '141.4',
                                'label' => 'Middle',
                                'name' => '',
                                'autocompleteAttribute' => 'additional-name',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '141.6',
                                'label' => 'Last',
                                'name' => '',
                                'autocompleteAttribute' => 'family-name',
                                'isHidden' => true,
                            ],
                            [
                                'id' => '141.8',
                                'label' => 'Suffix',
                                'name' => '',
                                'autocompleteAttribute' => 'honorific-suffix',
                                'isHidden' => false,
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_141',
                    'field' => [
                        'type' => 'name',
                        'display' => 'Test Field',
                        'prefix_include' => true,
                        'middle_name_include' => true,
                        'last_name_include' => false,
                        'suffix_include' => true,
                    ],
                ],
            ],
        ],
        'name field hiding suffix' => [
            'source' => [
                \array_merge(
                    $baseField_name,
                    [
                        'inputs' => [
                            [
                                'id' => '141.2',
                                'label' => 'Prefix',
                                'name' => '',
                                'autocompleteAttribute' => 'honorific-prefix',
                                'choices' => [
                                    [
                                        'text' => 'Dr.',
                                        'value' => 'Dr.',
                                    ],
                                    [
                                        'text' => 'Miss',
                                        'value' => 'Miss',
                                    ],
                                    [
                                        'text' => 'Mr.',
                                        'value' => 'Mr.',
                                    ],
                                    [
                                        'text' => 'Mrs.',
                                        'value' => 'Mrs.',
                                    ],
                                    [
                                        'text' => 'Ms.',
                                        'value' => 'Ms.',
                                    ],
                                    [
                                        'text' => 'Mx.',
                                        'value' => 'Mx.',
                                    ],
                                    [
                                        'text' => 'Prof.',
                                        'value' => 'Prof.',
                                    ],
                                    [
                                        'text' => 'Rev.',
                                        'value' => 'Rev.',
                                    ],
                                ],
                                'isHidden' => false,
                                'inputType' => 'radio',
                            ],
                            [
                                'id' => '141.3',
                                'label' => 'First',
                                'name' => '',
                                'autocompleteAttribute' => 'given-name',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '141.4',
                                'label' => 'Middle',
                                'name' => '',
                                'autocompleteAttribute' => 'additional-name',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '141.6',
                                'label' => 'Last',
                                'name' => '',
                                'autocompleteAttribute' => 'family-name',
                                'isHidden' => false,
                            ],
                            [
                                'id' => '141.8',
                                'label' => 'Suffix',
                                'name' => '',
                                'autocompleteAttribute' => 'honorific-suffix',
                                'isHidden' => true,
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_141',
                    'field' => [
                        'type' => 'name',
                        'display' => 'Test Field',
                        'prefix_include' => true,
                        'middle_name_include' => true,
                    ],
                ],
            ],
        ],
        'rcwdupload field with rcwdupload_filter y and only rcwdupload_filter_compr zip,rar,7z' => [
            'source' => [
                \array_merge(
                    $baseField_rcwdupload,
                    [
                        'rcwdupload_filter' => 'Y',
                        'rcwdupload_filter_compr' => 'zip,rar,7z',
                        'rcwdupload_filter_docs' => '',
                        'rcwdupload_filter_images' => '',
                        'rcwdupload_filter_others' => '',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_188',
                    'field' => [
                        'type' => 'assets',
                        'display' => 'Test Field',
                        'container' => 'form_entry_uploads',
                        'folder' => $siteHandle,
                        'dynamic' => 'id',
                        'restrict' => true,
                        'max_files' => 1,
                        'file_type_restrictions' => 'mime_types',
                        'mime_types' => [
                            'application/zip',
                            'application/x-rar-compressed',
                            'application/x-7z-compressed',
                        ],
                    ],
                ],
            ],
        ],
        'rcwdupload field with rcwdupload_filter y and only rcwdupload_filter_docs pdf,doc,docx' => [
            'source' => [
                \array_merge(
                    $baseField_rcwdupload,
                    [
                        'rcwdupload_filter' => 'Y',
                        'rcwdupload_filter_compr' => '',
                        'rcwdupload_filter_docs' => 'pdf,doc,docx',
                        'rcwdupload_filter_images' => '',
                        'rcwdupload_filter_others' => '',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_188',
                    'field' => [
                        'type' => 'assets',
                        'display' => 'Test Field',
                        'container' => 'form_entry_uploads',
                        'folder' => $siteHandle,
                        'dynamic' => 'id',
                        'restrict' => true,
                        'max_files' => 1,
                        'file_type_restrictions' => 'mime_types',
                        'mime_types' => [
                            'application/pdf',
                            'application/msword',
                            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        ],
                    ],
                ],
            ],
        ],
        'rcwdupload field with rcwdupload_filter y and only rcwdupload_filter_images jpg,gif,png' => [
            'source' => [
                \array_merge(
                    $baseField_rcwdupload,
                    [
                        'rcwdupload_filter' => 'Y',
                        'rcwdupload_filter_compr' => '',
                        'rcwdupload_filter_docs' => '',
                        'rcwdupload_filter_images' => 'jpg,gif,png',
                        'rcwdupload_filter_others' => '',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_188',
                    'field' => [
                        'type' => 'assets',
                        'display' => 'Test Field',
                        'container' => 'form_entry_uploads',
                        'folder' => $siteHandle,
                        'dynamic' => 'id',
                        'restrict' => true,
                        'max_files' => 1,
                        'file_type_restrictions' => 'mime_types',
                        'mime_types' => [
                            'image/jpeg',
                            'image/gif',
                            'image/png',
                        ],
                    ],
                ],
            ],
        ],
        'rcwdupload field with rcwdupload_filter y and only rcwdupload_filter_others xml,json,txt' => [
            'source' => [
                \array_merge(
                    $baseField_rcwdupload,
                    [
                        'rcwdupload_filter' => 'Y',
                        'rcwdupload_filter_compr' => '',
                        'rcwdupload_filter_docs' => '',
                        'rcwdupload_filter_images' => '',
                        'rcwdupload_filter_others' => 'xml,json,txt',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_188',
                    'field' => [
                        'type' => 'assets',
                        'display' => 'Test Field',
                        'container' => 'form_entry_uploads',
                        'folder' => $siteHandle,
                        'dynamic' => 'id',
                        'restrict' => true,
                        'max_files' => 1,
                        'file_type_restrictions' => 'mime_types',
                        'mime_types' => [
                            'application/xml',
                            'application/json',
                            'text/plain',
                        ],
                    ],
                ],
            ],
        ],
        'rcwdupload field with rcwdupload_filter y and rcwdupload_filter_compr zip,rar,7z and rcwdupload_filter_docs pdf,doc,docx and rcwdupload_filter_images jpg,gif,png and and rcwdupload_filter_others xml,json,txt' => [
            'source' => [
                \array_merge(
                    $baseField_rcwdupload,
                    [
                        'rcwdupload_filter' => 'Y',
                        'rcwdupload_filter_compr' => 'zip,rar,7z',
                        'rcwdupload_filter_docs' => 'pdf,doc,docx',
                        'rcwdupload_filter_images' => 'jpg,gif,png',
                        'rcwdupload_filter_others' => 'xml,json,txt',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_188',
                    'field' => [
                        'type' => 'assets',
                        'display' => 'Test Field',
                        'container' => 'form_entry_uploads',
                        'folder' => $siteHandle,
                        'dynamic' => 'id',
                        'restrict' => true,
                        'max_files' => 1,
                        'file_type_restrictions' => 'mime_types',
                        'mime_types' => [
                            'application/zip',
                            'application/x-rar-compressed',
                            'application/x-7z-compressed',
                            'application/pdf',
                            'application/msword',
                            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            'image/jpeg',
                            'image/gif',
                            'image/png',
                            'application/xml',
                            'application/json',
                            'text/plain',
                        ],
                    ],
                ],
            ],
        ],
        'rcwdupload field with rcwdupload_repeater y' => [
            'source' => [
                \array_merge(
                    $baseField_rcwdupload,
                    ['rcwdupload_repeater' => 'Y']
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_188',
                    'field' => [
                        'type' => 'assets',
                        'display' => 'Test Field',
                        'container' => 'form_entry_uploads',
                        'folder' => $siteHandle,
                        'dynamic' => 'id',
                        'restrict' => true,
                    ],
                ],
            ],
        ],
        'rcwdupload field with rcwdupload_sizelimit 20 and rcwdupload_sizelimit_type mb' => [
            'source' => [
                \array_merge(
                    $baseField_rcwdupload,
                    [
                        'rcwdupload_sizelimit' => '20',
                        'rcwdupload_sizelimit_type' => 'mb',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_188',
                    'field' => [
                        'type' => 'assets',
                        'display' => 'Test Field',
                        'container' => 'form_entry_uploads',
                        'folder' => $siteHandle,
                        'dynamic' => 'id',
                        'restrict' => true,
                        'max_files' => 1,
                        'max_file_size' => '20',
                    ],
                ],
            ],
        ],
        'rcwdupload field with rcwdupload_sizelimit 20 and rcwdupload_sizelimit_type kb' => [
            'source' => [
                \array_merge(
                    $baseField_rcwdupload,
                    [
                        'rcwdupload_sizelimit' => '20',
                        'rcwdupload_sizelimit_type' => 'kb',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_188',
                    'field' => [
                        'type' => 'assets',
                        'display' => 'Test Field',
                        'container' => 'form_entry_uploads',
                        'folder' => $siteHandle,
                        'dynamic' => 'id',
                        'restrict' => true,
                        'max_files' => 1,
                        'max_file_size' => '0.02',
                    ],
                ],
            ],
        ],
        'file field with allowed extensions .jpg, .gif,.png, .pdf' => [
            'source' => [
                \array_merge(
                    $baseField_fileupload,
                    [
                        'size' => 'large',
                        'enableAutocomplete' => false,
                        'autocompleteAttribute' => '',
                        'maxFileSize' => '',
                        'maxFiles' => '',
                        'multipleFiles' => false,
                        'allowedExtensions' => '.jpg, .gif,.png, .pdf',
                        'layoutGridColumnSpan' => '',
                        'enableEnhancedUI' => 0,
                        'layoutGroupId' => 'c8877110',
                        'errors' => [],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_138',
                    'field' => [
                        'type' => 'assets',
                        'display' => 'Test Field',
                        'container' => 'form_entry_uploads',
                        'folder' => $siteHandle,
                        'dynamic' => 'id',
                        'restrict' => true,
                        'max_files' => 1,
                        'file_type_restrictions' => 'mime_types',
                        'mime_types' => [
                            'image/jpeg',
                            'image/gif',
                            'image/png',
                            'application/pdf',
                        ],
                    ],
                ],
            ],
        ],
        'post image field with allowed extensions .jpg, .gif,.png, .pdf' => [
            'source' => [
                \array_merge(
                    $baseField_post_image,
                    ['allowedExtensions' => '.jpg, .gif,.png, .pdf']
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_159',
                    'field' => [
                        'type' => 'assets',
                        'display' => 'Test Field',
                        'container' => 'form_entry_uploads',
                        'folder' => $siteHandle,
                        'dynamic' => 'id',
                        'restrict' => true,
                        'max_files' => 1,
                        'file_type_restrictions' => 'mime_types',
                        'mime_types' => [
                            'image/jpeg',
                            'image/gif',
                            'image/png',
                            'application/pdf',
                        ],
                    ],
                ],
            ],
        ],
        'file field with max file size "42"' => [
            'source' => [
                \array_merge(
                    $baseField_fileupload,
                    [
                        'size' => 'large',
                        'enableAutocomplete' => false,
                        'autocompleteAttribute' => '',
                        'maxFileSize' => '42',
                        'multipleFiles' => false,
                        'allowedExtensions' => '',
                        'layoutGridColumnSpan' => '',
                        'enableEnhancedUI' => 0,
                        'layoutGroupId' => 'c8877110',
                        'errors' => [],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_138',
                    'field' => [
                        'type' => 'assets',
                        'display' => 'Test Field',
                        'container' => 'form_entry_uploads',
                        'folder' => $siteHandle,
                        'dynamic' => 'id',
                        'restrict' => true,
                        'max_files' => 1,
                        'max_file_size' => '42',
                    ],
                ],
            ],
        ],
        'file field with max file size uppercase "42mb"' => [
            'source' => [
                \array_merge(
                    $baseField_fileupload,
                    [
                        'size' => 'large',
                        'enableAutocomplete' => false,
                        'autocompleteAttribute' => '',
                        'maxFileSize' => '42MB',
                        'multipleFiles' => false,
                        'allowedExtensions' => '',
                        'layoutGridColumnSpan' => '',
                        'enableEnhancedUI' => 0,
                        'layoutGroupId' => 'c8877110',
                        'errors' => [],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_138',
                    'field' => [
                        'type' => 'assets',
                        'display' => 'Test Field',
                        'container' => 'form_entry_uploads',
                        'folder' => $siteHandle,
                        'dynamic' => 'id',
                        'restrict' => true,
                        'max_files' => 1,
                        'max_file_size' => '42',
                    ],
                ],
            ],
        ],
        'file field with max file size lowercase "42mb"' => [
            'source' => [
                \array_merge(
                    $baseField_fileupload,
                    [
                        'size' => 'large',
                        'enableAutocomplete' => false,
                        'autocompleteAttribute' => '',
                        'maxFileSize' => '42mb',
                        'multipleFiles' => false,
                        'allowedExtensions' => '',
                        'layoutGridColumnSpan' => '',
                        'enableEnhancedUI' => 0,
                        'layoutGroupId' => 'c8877110',
                        'errors' => [],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_138',
                    'field' => [
                        'type' => 'assets',
                        'display' => 'Test Field',
                        'container' => 'form_entry_uploads',
                        'folder' => $siteHandle,
                        'dynamic' => 'id',
                        'restrict' => true,
                        'max_files' => 1,
                        'max_file_size' => '42',
                    ],
                ],
            ],
        ],
        'number field with enable calculation true and calculation formula empty' => [
            'source' => [
                \array_merge(
                    $baseField_number,
                    [
                        'enableCalculation' => true,
                        'calculationFormula' => '',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_131',
                    'field' => [
                        'type' => 'float',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'date field with days of week 1-5' => [
            'source' => [
                \array_merge(
                    $baseField_date,
                    [
                        'gpLimitDatesdaysOfWeek' => [
                            '1',
                            '2',
                            '3',
                            '4',
                            '5',
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_127',
                    'field' => [
                        'type' => 'date',
                        'display' => 'Test Field',
                        'format' => 'm/d/Y',
                        'days_of_week' => [
                            '1',
                            '2',
                            '3',
                            '4',
                            '5',
                        ],
                    ],
                ],
            ],
        ],
        'date field with days of week 0-6' => [
            'source' => [
                \array_merge(
                    $baseField_date,
                    [
                        'gpLimitDatesdaysOfWeek' => [
                            '0',
                            '1',
                            '2',
                            '3',
                            '4',
                            '5',
                            '6',
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_127',
                    'field' => [
                        'type' => 'date',
                        'display' => 'Test Field',
                        'format' => 'm/d/Y',
                    ],
                ],
            ],
        ],
        'date field with single dates exceptions' => [
            'source' => [
                \array_merge(
                    $baseField_date,
                    ['gpLimitDatesexceptions' => ['01\/16\/2023']]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_127',
                    'field' => [
                        'type' => 'date',
                        'display' => 'Test Field',
                        'format' => 'm/d/Y',
                        'date_exceptions' => [
                            [
                                'id' => 'test_field_127_date_exceptions_1',
                                'item' => '2023-01-16',
                                'type' => 'single_date',
                                'enabled' => true,
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'date field with multiple dates exceptions' => [
            'source' => [
                \array_merge(
                    $baseField_date,
                    [
                        'gpLimitDatesexceptions' => [
                            '01\/16\/2023',
                            '01\/17\/2023',
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_127',
                    'field' => [
                        'type' => 'date',
                        'display' => 'Test Field',
                        'format' => 'm/d/Y',
                        'date_exceptions' => [
                            [
                                'id' => 'test_field_127_date_exceptions_1',
                                'item' => '2023-01-16',
                                'type' => 'single_date',
                                'enabled' => true,
                            ],
                            [
                                'id' => 'test_field_127_date_exceptions_2',
                                'item' => '2023-01-17',
                                'type' => 'single_date',
                                'enabled' => true,
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'date field with min date custom and no exclude before today' => [
            'source' => [
                \array_merge(
                    $baseField_date,
                    [
                        'gpLimitDatesminDateMod' => '',
                        'gpLimitDatesminDate' => '_custom_',
                        'gpLimitDatesmaxDateMod' => '',
                        'gpLimitDatesmaxDate' => '',
                        'gpLimitDatesexceptions' => [],
                        'gpLimitDatesminDateExcludeBeforeTodayMod' => '',
                        'gpLimitDatesminDateExcludeBeforeToday' => false,
                        'gpLimitDatesdaysOfWeek' => [],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_127',
                    'field' => [
                        'type' => 'date',
                        'display' => 'Test Field',
                        'format' => 'm/d/Y',
                        'earliest_date_type' => 'today',
                    ],
                ],
            ],
        ],
        'date field with min date custom and exclude before today without offset' => [
            'source' => [
                \array_merge(
                    $baseField_date,
                    [
                        'gpLimitDatesminDateMod' => '',
                        'gpLimitDatesminDate' => '_custom_',
                        'gpLimitDatesmaxDateMod' => '',
                        'gpLimitDatesmaxDate' => '',
                        'gpLimitDatesexceptions' => [],
                        'gpLimitDatesminDateExcludeBeforeTodayMod' => '',
                        'gpLimitDatesminDateExcludeBeforeToday' => true,
                        'gpLimitDatesdaysOfWeek' => [],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_127',
                    'field' => [
                        'type' => 'date',
                        'display' => 'Test Field',
                        'format' => 'm/d/Y',
                        'earliest_date_type' => 'today',
                        'earliest_date_exclude_before_today' => true,
                    ],
                ],
            ],
        ],
        'date field with min date custom and exclude before today with offset' => [
            'source' => [
                \array_merge(
                    $baseField_date,
                    [
                        'gpLimitDatesminDateMod' => '',
                        'gpLimitDatesminDate' => '_custom_',
                        'gpLimitDatesmaxDateMod' => '',
                        'gpLimitDatesmaxDate' => '',
                        'gpLimitDatesexceptions' => [],
                        'gpLimitDatesminDateExcludeBeforeTodayMod' => '+10 weeks',
                        'gpLimitDatesminDateExcludeBeforeToday' => true,
                        'gpLimitDatesdaysOfWeek' => [],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_127',
                    'field' => [
                        'type' => 'date',
                        'display' => 'Test Field',
                        'format' => 'm/d/Y',
                        'earliest_date_type' => 'today',
                        'earliest_date_exclude_before_today' => true,
                        'earliest_date_today_offset' => [
                            [
                                'id' => 'test_field_127_earliest_date_today_offset_1',
                                'operator' => 'add',
                                'number' => 10,
                                'unit' => 'week',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'date field with limit dates attributes 1' => [
            'source' => [
                \array_merge(
                    $baseField_date,
                    [
                        'gpLimitDatesminDateMod' => '',
                        'gpLimitDatesminDate' => '_custom_',
                        'gpLimitDatesmaxDateMod' => '',
                        'gpLimitDatesmaxDate' => '',
                        'gpLimitDatesexceptions' => [
                            '01\/16\/2023',
                            '02\/20\/2023',
                            '05\/29\/2023',
                            '07\/04\/2023',
                            '09\/04\/2023',
                            '11\/10\/2023',
                            '11\/23\/2023',
                            '11\/24\/2023',
                            '12\/22\/2023',
                            '12\/25\/2023',
                        ],
                        'gpLimitDatesminDateExcludeBeforeTodayMod' => '+10 weeks',
                        'gpLimitDatesminDateExcludeBeforeToday' => true,
                        'gpLimitDatesdaysOfWeek' => [
                            '1',
                            '2',
                            '3',
                            '4',
                            '5',
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_127',
                    'field' => [
                        'type' => 'date',
                        'display' => 'Test Field',
                        'format' => 'm/d/Y',
                        'earliest_date_type' => 'today',
                        'earliest_date_exclude_before_today' => true,
                        'earliest_date_today_offset' => [
                            [
                                'id' => 'test_field_127_earliest_date_today_offset_1',
                                'operator' => 'add',
                                'number' => 10,
                                'unit' => 'week',
                            ],
                        ],
                        'days_of_week' => [
                            '1',
                            '2',
                            '3',
                            '4',
                            '5',
                        ],
                        'date_exceptions' => [
                            [
                                'id' => 'test_field_127_date_exceptions_1',
                                'item' => '2023-01-16',
                                'type' => 'single_date',
                                'enabled' => true,
                            ],
                            [
                                'id' => 'test_field_127_date_exceptions_2',
                                'item' => '2023-02-20',
                                'type' => 'single_date',
                                'enabled' => true,
                            ],
                            [
                                'id' => 'test_field_127_date_exceptions_3',
                                'item' => '2023-05-29',
                                'type' => 'single_date',
                                'enabled' => true,
                            ],
                            [
                                'id' => 'test_field_127_date_exceptions_4',
                                'item' => '2023-07-04',
                                'type' => 'single_date',
                                'enabled' => true,
                            ],
                            [
                                'id' => 'test_field_127_date_exceptions_5',
                                'item' => '2023-09-04',
                                'type' => 'single_date',
                                'enabled' => true,
                            ],
                            [
                                'id' => 'test_field_127_date_exceptions_6',
                                'item' => '2023-11-10',
                                'type' => 'single_date',
                                'enabled' => true,
                            ],
                            [
                                'id' => 'test_field_127_date_exceptions_7',
                                'item' => '2023-11-23',
                                'type' => 'single_date',
                                'enabled' => true,
                            ],
                            [
                                'id' => 'test_field_127_date_exceptions_8',
                                'item' => '2023-11-24',
                                'type' => 'single_date',
                                'enabled' => true,
                            ],
                            [
                                'id' => 'test_field_127_date_exceptions_9',
                                'item' => '2023-12-22',
                                'type' => 'single_date',
                                'enabled' => true,
                            ],
                            [
                                'id' => 'test_field_127_date_exceptions_10',
                                'item' => '2023-12-25',
                                'type' => 'single_date',
                                'enabled' => true,
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'date field with limit dates attributes 2' => [
            'source' => [
                \array_merge(
                    $baseField_date,
                    [
                        'gpLimitDatesminDateMod' => '',
                        'gpLimitDatesminDate' => '_custom_',
                        'gpLimitDatesmaxDateMod' => '',
                        'gpLimitDatesmaxDate' => '',
                        'gpLimitDatesexceptions' => [],
                        'gpLimitDatesminDateExcludeBeforeTodayMod' => '+3 weeks',
                        'gpLimitDatesminDateExcludeBeforeToday' => true,
                        'gpLimitDatesdaysOfWeek' => [
                            1,
                            2,
                            3,
                            4,
                            5,
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_127',
                    'field' => [
                        'type' => 'date',
                        'display' => 'Test Field',
                        'format' => 'm/d/Y',
                        'earliest_date_type' => 'today',
                        'earliest_date_exclude_before_today' => true,
                        'earliest_date_today_offset' => [
                            [
                                'id' => 'test_field_127_earliest_date_today_offset_1',
                                'operator' => 'add',
                                'number' => 3,
                                'unit' => 'week',
                            ],
                        ],
                        'days_of_week' => [
                            '1',
                            '2',
                            '3',
                            '4',
                            '5',
                        ],
                    ],
                ],
            ],
        ],
        'date field with limit dates attributes 3' => [
            'source' => [
                \array_merge(
                    $baseField_date,
                    [
                        'gpLimitDatesminDateMod' => '',
                        'gpLimitDatesminDate' => '_custom_',
                        'gpLimitDatesmaxDateMod' => '',
                        'gpLimitDatesmaxDate' => '',
                        'gpLimitDatesexceptions' => [],
                        'gpLimitDatesminDateExcludeBeforeTodayMod' => '+2 weeks',
                        'gpLimitDatesminDateExcludeBeforeToday' => true,
                        'gpLimitDatesdaysOfWeek' => [
                            1,
                            2,
                            3,
                            4,
                            5,
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_127',
                    'field' => [
                        'type' => 'date',
                        'display' => 'Test Field',
                        'format' => 'm/d/Y',
                        'earliest_date_type' => 'today',
                        'earliest_date_exclude_before_today' => true,
                        'earliest_date_today_offset' => [
                            [
                                'id' => 'test_field_127_earliest_date_today_offset_1',
                                'operator' => 'add',
                                'number' => 2,
                                'unit' => 'week',
                            ],
                        ],
                        'days_of_week' => [
                            '1',
                            '2',
                            '3',
                            '4',
                            '5',
                        ],
                    ],
                ],
            ],
        ],
        'date field with limit dates attributes 4' => [
            'source' => [
                \array_merge(
                    $baseField_date,
                    [
                        'gpLimitDatesminDateMod' => '',
                        'gpLimitDatesminDate' => '_custom_',
                        'gpLimitDatesmaxDateMod' => '',
                        'gpLimitDatesmaxDate' => '_custom_',
                        'gpLimitDatesexceptions' => [],
                        'gpLimitDatesminDateExcludeBeforeTodayMod' => '+3 weeks',
                        'gpLimitDatesminDateExcludeBeforeToday' => true,
                        'gpLimitDatesdaysOfWeek' => [
                            1,
                            2,
                            3,
                            4,
                            5,
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_127',
                    'field' => [
                        'type' => 'date',
                        'display' => 'Test Field',
                        'format' => 'm/d/Y',
                        'earliest_date_type' => 'today',
                        'earliest_date_exclude_before_today' => true,
                        'earliest_date_today_offset' => [
                            [
                                'id' => 'test_field_127_earliest_date_today_offset_1',
                                'operator' => 'add',
                                'number' => 3,
                                'unit' => 'week',
                            ],
                        ],
                        'days_of_week' => [
                            '1',
                            '2',
                            '3',
                            '4',
                            '5',
                        ],
                    ],
                ],
            ],
        ],
        'date field with limit dates attributes 5' => [
            'source' => [
                \array_merge(
                    $baseField_date,
                    [
                        'gpLimitDatesminDateMod' => '',
                        'gpLimitDatesminDate' => '_custom_',
                        'gpLimitDatesmaxDateMod' => '',
                        'gpLimitDatesmaxDate' => '',
                        'gpLimitDatesexceptions' => [],
                        'gpLimitDatesminDateExcludeBeforeTodayMod' => '+7 days',
                        'gpLimitDatesminDateExcludeBeforeToday' => true,
                        'gpLimitDatesdaysOfWeek' => [
                            1,
                            2,
                            3,
                            4,
                            5,
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_127',
                    'field' => [
                        'type' => 'date',
                        'display' => 'Test Field',
                        'format' => 'm/d/Y',
                        'earliest_date_type' => 'today',
                        'earliest_date_exclude_before_today' => true,
                        'earliest_date_today_offset' => [
                            [
                                'id' => 'test_field_127_earliest_date_today_offset_1',
                                'operator' => 'add',
                                'number' => 7,
                                'unit' => 'day',
                            ],
                        ],
                        'days_of_week' => [
                            '1',
                            '2',
                            '3',
                            '4',
                            '5',
                        ],
                    ],
                ],
            ],
        ],
        'date field with limit dates attributes 6' => [
            'source' => [
                \array_merge(
                    $baseField_date,
                    [
                        'gpLimitDatesminDateMod' => '',
                        'gpLimitDatesminDate' => '_custom_',
                        'gpLimitDatesmaxDateMod' => '',
                        'gpLimitDatesmaxDate' => '',
                        'gpLimitDatesexceptions' => [],
                        'gpLimitDatesminDateExcludeBeforeTodayMod' => '+7 days',
                        'gpLimitDatesminDateExcludeBeforeToday' => true,
                        'gpLimitDatesdaysOfWeek' => [
                            1,
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_127',
                    'field' => [
                        'type' => 'date',
                        'display' => 'Test Field',
                        'format' => 'm/d/Y',
                        'earliest_date_type' => 'today',
                        'earliest_date_exclude_before_today' => true,
                        'earliest_date_today_offset' => [
                            [
                                'id' => 'test_field_127_earliest_date_today_offset_1',
                                'operator' => 'add',
                                'number' => 7,
                                'unit' => 'day',
                            ],
                        ],
                        'days_of_week' => [
                            '1',
                        ],
                    ],
                ],
            ],
        ],
        'date field with limit dates attributes 7' => [
            'source' => [
                \array_merge(
                    $baseField_date,
                    [
                        'gpLimitDatesminDateMod' => '',
                        'gpLimitDatesminDate' => '_custom_',
                        'gpLimitDatesmaxDateMod' => '',
                        'gpLimitDatesmaxDate' => '',
                        'gpLimitDatesexceptions' => [],
                        'gpLimitDatesminDateExcludeBeforeTodayMod' => '+8',
                        'gpLimitDatesminDateExcludeBeforeToday' => true,
                        'gpLimitDatesdaysOfWeek' => [
                            1,
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_127',
                    'field' => [
                        'type' => 'date',
                        'display' => 'Test Field',
                        'format' => 'm/d/Y',
                        'earliest_date_type' => 'today',
                        'earliest_date_exclude_before_today' => true,
                        'earliest_date_today_offset' => [
                            [
                                'id' => 'test_field_127_earliest_date_today_offset_1',
                                'operator' => 'add',
                                'number' => 8,
                                'unit' => 'day',
                            ],
                        ],
                        'days_of_week' => [
                            '1',
                        ],
                    ],
                ],
            ],
        ],
        'date field with limit dates attributes 8' => [
            'source' => [
                \array_merge(
                    $baseField_date,
                    [
                        'gpLimitDatesminDateMod' => '',
                        'gpLimitDatesminDate' => '',
                        'gpLimitDatesmaxDateMod' => '',
                        'gpLimitDatesmaxDate' => '',
                        'gpLimitDatesexceptions' => [],
                        'gpLimitDatesminDateExcludeBeforeTodayMod' => '',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_127',
                    'field' => [
                        'type' => 'date',
                        'display' => 'Test Field',
                        'format' => 'm/d/Y',
                    ],
                ],
            ],
        ],
        'date field with limit dates attributes 9' => [
            'source' => [
                \array_merge(
                    $baseField_date,
                    [
                        'gpLimitDatesminDateMod' => '',
                        'gpLimitDatesminDateExcludeBeforeTodayMod' => '',
                        'gpLimitDatesminDate' => '{today}',
                        'gpLimitDatesmaxDateMod' => '',
                        'gpLimitDatesmaxDate' => '',
                        'gpLimitDatesexceptions' => [],
                        'gpLimitDatesinlineDatepicker' => false,
                        'gpLimitDatesdaysOfWeek' => [
                            1,
                            2,
                            3,
                            4,
                            5,
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_127',
                    'field' => [
                        'type' => 'date',
                        'display' => 'Test Field',
                        'format' => 'm/d/Y',
                        'earliest_date_type' => 'today',
                        'days_of_week' => [
                            '1',
                            '2',
                            '3',
                            '4',
                            '5',
                        ],
                    ],
                ],
            ],
        ],
        'password field with inputs confirm password is hidden true' => [
            'source' => [
                \array_merge(
                    $baseField_password,
                    [
                        'inputs' => [
                            [
                                'id' => '148',
                                'label' => 'Enter Password',
                                'name' => '',
                            ],
                            [
                                'id' => '148.2',
                                'label' => 'Confirm Password',
                                'name' => '',
                                'isHidden' => true,
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_148',
                    'field' => [
                        'type' => 'password',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'password field with password visibility enabled true' => [
            'source' => [
                \array_merge(
                    $baseField_password,
                    ['passwordVisibilityEnabled' => true]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_148',
                    'field' => [
                        'type' => 'password',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'email input type post custom field field with email confirm enabled' => [
            'source' => [
                \array_merge(
                    $baseField_post_custom_field_email,
                    [
                        'emailConfirmEnabled' => true,
                        'inputs' => [
                            [
                                'id' => '000',
                                'label' => 'Enter Email',
                                'name' => '',
                                'autocompleteAttribute' => '',
                                'customLabel' => 'Foo',
                                'placeholder' => 'Bar',
                                'defaultValue' => 'Baz',
                            ],
                            [
                                'id' => '000.2',
                                'label' => 'Confirm Email',
                                'name' => '',
                                'autocompleteAttribute' => '',
                                'customLabel' => 'Qux',
                                'placeholder' => 'Quux',
                                'defaultValue' => 'Corge',
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_183',
                    'field' => [
                        'type' => 'email',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'textarea field with use rich text editor true' => [
            'source' => [
                \array_merge(
                    $baseField_textarea,
                    ['useRichTextEditor' => true]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_147',
                    'field' => 'uamsweb_common.bard_simple',
                    'config' => [
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'textarea input type survey field with use rich text editor true' => [
            'source' => [
                \array_merge(
                    $baseField_survey_textarea,
                    ['useRichTextEditor' => true]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_164',
                    'field' => 'uamsweb_common.bard_simple',
                    'config' => [
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'post content field with use rich text editor true' => [
            'source' => [
                \array_merge(
                    $baseField_post_content,
                    ['useRichTextEditor' => true]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_155',
                    'field' => 'uamsweb_common.bard_simple',
                    'config' => [
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'textarea input type post custom field field with use rich text editor true' => [
            'source' => [
                \array_merge(
                    $baseField_post_custom_field_textarea,
                    ['useRichTextEditor' => true]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_173',
                    'field' => 'uamsweb_common.bard_simple',
                    'config' => [
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
    ];
}

/**
 * Data provider for testing different repeater fields and their attribute
 * mappings.
 *
 * @return array<string, array<string, mixed>>
 */
function gravityAttributeMappingRepeaterProvider(): array
{
    // Get base field definitions from helper method
    $fieldDefinitions = gravityGetBaseFieldDefinitions();

    // Extract individual field definitions for easier access
    $siteHandle = gravityFormDefinitionString($fieldDefinitions, 'siteHandle');
    $siteTitle = gravityFormDefinitionString($fieldDefinitions, 'siteTitle');
    $baseFieldComponent = gravityFormBaseField($fieldDefinitions, 'baseFieldComponent');
    $baseField_address = gravityFormBaseField($fieldDefinitions, 'baseField_address');
    $baseField_checkbox = gravityFormBaseField($fieldDefinitions, 'baseField_checkbox');
    $baseField_consent = gravityFormBaseField($fieldDefinitions, 'baseField_consent');
    $baseField_date = gravityFormBaseField($fieldDefinitions, 'baseField_date');
    $baseField_email = gravityFormBaseField($fieldDefinitions, 'baseField_email');
    $baseField_fileupload = gravityFormBaseField($fieldDefinitions, 'baseField_fileupload');
    $baseField_form = gravityFormBaseField($fieldDefinitions, 'baseField_form');
    $baseField_hidden = gravityFormBaseField($fieldDefinitions, 'baseField_hidden');
    $baseField_html = gravityFormBaseField($fieldDefinitions, 'baseField_html');
    $baseField_list = gravityFormBaseField($fieldDefinitions, 'baseField_list');
    $baseField_multi_choice_checkbox_exactly = gravityFormBaseField($fieldDefinitions, 'baseField_multi_choice_checkbox_exactly');
    $baseField_multi_choice_checkbox_range = gravityFormBaseField($fieldDefinitions, 'baseField_multi_choice_checkbox_range');
    $baseField_multi_choice_checkbox_unlimited = gravityFormBaseField($fieldDefinitions, 'baseField_multi_choice_checkbox_unlimited');
    $baseField_multi_choice_radio = gravityFormBaseField($fieldDefinitions, 'baseField_multi_choice_radio');
    $baseField_multiselect = gravityFormBaseField($fieldDefinitions, 'baseField_multiselect');
    $baseField_name = gravityFormBaseField($fieldDefinitions, 'baseField_name');
    $baseField_number = gravityFormBaseField($fieldDefinitions, 'baseField_number');
    $baseField_password = gravityFormBaseField($fieldDefinitions, 'baseField_password');
    $baseField_phone = gravityFormBaseField($fieldDefinitions, 'baseField_phone');
    $baseField_poll_checkbox = gravityFormBaseField($fieldDefinitions, 'baseField_poll_checkbox');
    $baseField_poll_radio = gravityFormBaseField($fieldDefinitions, 'baseField_poll_radio');
    $baseField_poll_select = gravityFormBaseField($fieldDefinitions, 'baseField_poll_select');
    $baseField_post_category_radio = gravityFormBaseField($fieldDefinitions, 'baseField_post_category_radio');
    $baseField_post_category_checkbox = gravityFormBaseField($fieldDefinitions, 'baseField_post_category_checkbox');
    $baseField_post_category_multiselect = gravityFormBaseField($fieldDefinitions, 'baseField_post_category_multiselect');
    $baseField_post_category_select = gravityFormBaseField($fieldDefinitions, 'baseField_post_category_select');
    $baseField_post_content = gravityFormBaseField($fieldDefinitions, 'baseField_post_content');
    $baseField_post_custom_field_text = gravityFormBaseField($fieldDefinitions, 'baseField_post_custom_field_text');
    $baseField_post_custom_field_textarea = gravityFormBaseField($fieldDefinitions, 'baseField_post_custom_field_textarea');
    $baseField_post_custom_field_select = gravityFormBaseField($fieldDefinitions, 'baseField_post_custom_field_select');
    $baseField_post_custom_field_multiselect = gravityFormBaseField($fieldDefinitions, 'baseField_post_custom_field_multiselect');
    $baseField_post_custom_field_number = gravityFormBaseField($fieldDefinitions, 'baseField_post_custom_field_number');
    $baseField_post_custom_field_checkbox = gravityFormBaseField($fieldDefinitions, 'baseField_post_custom_field_checkbox');
    $baseField_post_custom_field_radio = gravityFormBaseField($fieldDefinitions, 'baseField_post_custom_field_radio');
    $baseField_post_custom_field_hidden = gravityFormBaseField($fieldDefinitions, 'baseField_post_custom_field_hidden');
    $baseField_post_custom_field_date = gravityFormBaseField($fieldDefinitions, 'baseField_post_custom_field_date');
    $baseField_post_custom_field_time = gravityFormBaseField($fieldDefinitions, 'baseField_post_custom_field_time');
    $baseField_post_custom_field_website = gravityFormBaseField($fieldDefinitions, 'baseField_post_custom_field_website');
    $baseField_post_custom_field_email = gravityFormBaseField($fieldDefinitions, 'baseField_post_custom_field_email');
    $baseField_post_custom_field_fileupload = gravityFormBaseField($fieldDefinitions, 'baseField_post_custom_field_fileupload');
    $baseField_post_custom_field_list = gravityFormBaseField($fieldDefinitions, 'baseField_post_custom_field_list');
    $baseField_post_custom_field_phone = gravityFormBaseField($fieldDefinitions, 'baseField_post_custom_field_phone');
    $baseField_post_excerpt = gravityFormBaseField($fieldDefinitions, 'baseField_post_excerpt');
    $baseField_post_image = gravityFormBaseField($fieldDefinitions, 'baseField_post_image');
    $baseField_post_tags_empty = gravityFormBaseField($fieldDefinitions, 'baseField_post_tags_empty');
    $baseField_post_tags_not_set = gravityFormBaseField($fieldDefinitions, 'baseField_post_tags_not_set');
    $baseField_post_tags_text = gravityFormBaseField($fieldDefinitions, 'baseField_post_tags_text');
    $baseField_post_tags_select = gravityFormBaseField($fieldDefinitions, 'baseField_post_tags_select');
    $baseField_post_tags_checkbox = gravityFormBaseField($fieldDefinitions, 'baseField_post_tags_checkbox');
    $baseField_post_tags_radio = gravityFormBaseField($fieldDefinitions, 'baseField_post_tags_radio');
    $baseField_post_tags_multiselect = gravityFormBaseField($fieldDefinitions, 'baseField_post_tags_multiselect');
    $baseField_post_title = gravityFormBaseField($fieldDefinitions, 'baseField_post_title');
    $baseField_product = gravityFormBaseField($fieldDefinitions, 'baseField_product');
    $baseField_quiz = gravityFormBaseField($fieldDefinitions, 'baseField_quiz');
    $baseField_radio = gravityFormBaseField($fieldDefinitions, 'baseField_radio');
    $baseField_rcwdupload = gravityFormBaseField($fieldDefinitions, 'baseField_rcwdupload');
    $baseField_select = gravityFormBaseField($fieldDefinitions, 'baseField_select');
    $baseField_survey_checkbox = gravityFormBaseField($fieldDefinitions, 'baseField_survey_checkbox');
    $baseField_survey_likert = gravityFormBaseField($fieldDefinitions, 'baseField_survey_likert');
    $baseField_survey_radio = gravityFormBaseField($fieldDefinitions, 'baseField_survey_radio');
    $baseField_survey_select = gravityFormBaseField($fieldDefinitions, 'baseField_survey_select');
    $baseField_survey_text = gravityFormBaseField($fieldDefinitions, 'baseField_survey_text');
    $baseField_survey_textarea = gravityFormBaseField($fieldDefinitions, 'baseField_survey_textarea');
    $baseField_text = gravityFormBaseField($fieldDefinitions, 'baseField_text');
    $baseField_textarea = gravityFormBaseField($fieldDefinitions, 'baseField_textarea');
    $baseField_time = gravityFormBaseField($fieldDefinitions, 'baseField_time');

    $baseForm = [
        'fields' => [],
        'title' => 'Test Form',
        'description' => 'Test form description',
        'version' => '2.9.16',
        'id' => 999,
        'markupVersion' => 2,
        'nextFieldId' => 2,
        'useCurrentUserAsAuthor' => true,
        'postContentTemplateEnabled' => false,
        'postTitleTemplateEnabled' => false,
        'postTitleTemplate' => '',
        'postContentTemplate' => '',
        'lastPageButton' => null,
        'pagination' => null,
        'firstPageCssClass' => null,
        'notifications' => [],
        'confirmations' => [],
        'labelPlacement' => 'top_label',
        'descriptionPlacement' => 'below',
        'validationPlacement' => 'below',
        'subLabelPlacement' => 'below',
        'requiredIndicator' => 'text',
        'customRequiredIndicator' => '(Required)',
        'cssClass' => '',
        'saveButtonText' => 'Save & Continue',
        'limitEntries' => false,
        'limitEntriesCount' => '',
        'limitEntriesPeriod' => '',
        'limitEntriesMessage' => '',
        'scheduleForm' => false,
        'scheduleStart' => '',
        'scheduleEnd' => '',
        'schedulePendingMessage' => '',
        'scheduleMessage' => '',
        'requireLogin' => false,
        'requireLoginMessage' => '',
        'honeypotAction' => 'spam',
        'enableGFZeroSpam' => '1',
        'validationSummary' => false,
        'deprecated' => '',
        'saveEnabled' => '',
        'enableHoneypot' => false,
        'enableAnimation' => false,
        'save' => [
            'enabled' => false,
            'button' => [
                'type' => 'link',
                'text' => 'Save & Continue',
            ],
        ],
        'scheduleStartHour' => '',
        'scheduleStartMinute' => '',
        'scheduleStartAmpm' => '',
        'scheduleEndHour' => '',
        'scheduleEndMinute' => '',
        'scheduleEndAmpm' => '',
        'button' => [
            'type' => 'text',
            'text' => 'Submit',
            'imageUrl' => '',
        ],
    ];

    $baseConfirmation = [
        'id' => '0000000000001',
        'name' => 'Test Confirmation',
        'isDefault' => true,
        'type' => 'message',
        'message' => "Thanks for contacting us! We will get in touch with you shortly.\r\n{Test Field:123}",
        'url' => '',
        'pageId' => '',
        'queryString' => '',
        'event' => 'form_submission',
        'disableAutoformat' => false,
        'page' => '',
        'conditionalLogic' => [],
    ];

    $baseEmail = [
        'id' => '1000000000001',
        'isActive' => true,
        'to' => '{admin_email}',
        'name' => 'Test Notification',
        'event' => 'form_submission',
        'toType' => 'email',
        'subject' => 'New submission from {form_title}',
        'message' => '{all_fields}',
        'service' => 'wordpress',
        'toEmail' => '{admin_email}',
        'routing' => null,
        'fromName' => '',
        'from' => '{admin_email}',
        'replyTo' => '',
        'bcc' => '',
        'disableAutoformat' => false,
        'notification_conditional_logic_object' => '',
        'notification_conditional_logic' => '0',
        'conditionalLogic' => null,
        'cc' => '',
        'enableAttachments' => false,
    ];

    return [
        'single form with text field' => [
            'site_handle' => $siteHandle,
            'source' => [
                \array_merge(
                    $baseForm,
                    [
                        'fields' => [
                            \array_merge(
                                $baseField_text,
                                []
                            ),
                        ],
                        'notifications' => [],
                        'confirmations' => [],
                    ]
                ),
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_gravity-forms_999_test-form',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form',
                        'description' => 'Test form description',
                        'submit' => 'Submit',
                    ],
                    'blueprint_fields' => [
                        [
                            'handle' => 'test_field_123',
                            'field' => [
                                'type' => 'text',
                                'display' => 'Test Field',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'single form with different submit button text' => [
            'site_handle' => $siteHandle,
            'source' => [
                \array_merge(
                    $baseForm,
                    [
                        'fields' => [
                            \array_merge(
                                $baseField_text,
                                []
                            ),
                        ],
                        'notifications' => [],
                        'confirmations' => [],
                        'button' => [
                            'type' => 'text',
                            'text' => 'Foo Bar',
                            'imageUrl' => '',
                        ],
                    ]
                ),
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_gravity-forms_999_test-form',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form',
                        'description' => 'Test form description',
                        'submit' => 'Foo Bar',
                    ],
                    'blueprint_fields' => [
                        [
                            'handle' => 'test_field_123',
                            'field' => [
                                'type' => 'text',
                                'display' => 'Test Field',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'two forms' => [
            'site_handle' => $siteHandle,
            'source' => [
                \array_merge(
                    $baseForm,
                    [
                        'id' => '012',
                        'title' => 'Test Form 1',
                        'fields' => [
                            \array_merge(
                                $baseField_text,
                                []
                            ),
                        ],
                        'notifications' => [],
                        'confirmations' => [],
                    ]
                ),
                \array_merge(
                    $baseForm,
                    [
                        'id' => '345',
                        'title' => 'Test Form 2',
                        'fields' => [
                            \array_merge(
                                $baseField_text,
                                []
                            ),
                        ],
                    ]
                ),
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_gravity-forms_012_test-form-1',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form 1',
                        'description' => 'Test form description',
                        'submit' => 'Submit',
                    ],
                    'blueprint_fields' => [
                        [
                            'handle' => 'test_field_123',
                            'field' => [
                                'type' => 'text',
                                'display' => 'Test Field',
                            ],
                        ],
                    ],
                ],
                [
                    'handle' => 'supply_chain_gravity-forms_345_test-form-2',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form 2',
                        'description' => 'Test form description',
                        'submit' => 'Submit',
                    ],
                    'blueprint_fields' => [
                        [
                            'handle' => 'test_field_123',
                            'field' => [
                                'type' => 'text',
                                'display' => 'Test Field',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'single form with no description' => [
            'site_handle' => $siteHandle,
            'source' => [
                \array_merge(
                    $baseForm,
                    [
                        'description' => '',
                        'fields' => [
                            \array_merge(
                                $baseField_text,
                                []
                            ),
                        ],
                        'notifications' => [],
                        'confirmations' => [],
                    ]
                ),
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_gravity-forms_999_test-form',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form',
                        'submit' => 'Submit',
                    ],
                    'blueprint_fields' => [
                        [
                            'handle' => 'test_field_123',
                            'field' => [
                                'type' => 'text',
                                'display' => 'Test Field',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'single form with html in description' => [
            'site_handle' => $siteHandle,
            'source' => [
                \array_merge(
                    $baseForm,
                    [
                        'description' => '<p>Test form description</p>',
                        'fields' => [
                            \array_merge(
                                $baseField_text,
                                []
                            ),
                        ],
                        'notifications' => [],
                        'confirmations' => [],
                    ]
                ),
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_gravity-forms_999_test-form',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form',
                        'description' => 'Test form description',
                        'submit' => 'Submit',
                    ],
                    'blueprint_fields' => [
                        [
                            'handle' => 'test_field_123',
                            'field' => [
                                'type' => 'text',
                                'display' => 'Test Field',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'single form with default confirmation' => [
            'site_handle' => $siteHandle,
            'source' => [
                \array_merge(
                    $baseForm,
                    [
                        'fields' => [
                            \array_merge(
                                $baseField_text,
                                []
                            ),
                        ],
                        'notifications' => [],
                        'confirmations' => [
                            \array_merge(
                                $baseConfirmation,
                                []
                            ),
                        ],
                    ]
                ),
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_gravity-forms_999_test-form',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form',
                        'description' => 'Test form description',
                        'submit' => 'Submit',
                        'confirmation_settings_default' => [
                            'confirmation_type' => 'message',
                            'confirmation_message' => [
                                [
                                    'type' => 'paragraph',
                                    'content' => [
                                        [
                                            'type' => 'text',
                                            'text' => 'Thanks for contacting us! We will get in touch with you shortly.',
                                        ],
                                        [
                                            'type' => 'hardBreak',
                                        ],
                                        [
                                            'type' => 'text',
                                            'text' => '{{ submission:test_field_123 }}',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'blueprint_fields' => [
                        [
                            'handle' => 'test_field_123',
                            'field' => [
                                'type' => 'text',
                                'display' => 'Test Field',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'single form with enabled additional confirmation' => [
            'site_handle' => $siteHandle,
            'source' => [
                \array_merge(
                    $baseForm,
                    [
                        'fields' => [
                            \array_merge(
                                $baseField_text,
                                []
                            ),
                        ],
                        'notifications' => [],
                        'confirmations' => [
                            \array_merge(
                                $baseConfirmation,
                                []
                            ),
                            \array_merge(
                                $baseConfirmation,
                                [
                                    'id' => '0000000000002',
                                    'name' => 'Additional Confirmation',
                                    'isDefault' => false,
                                    'disableAutoformat' => false,
                                    'page' => '',
                                    'url' => '',
                                    'queryString' => '',
                                    'confirmation_conditional_logic_object' => '',
                                    'confirmation_conditional_logic' => '1',
                                    'conditionalLogic' => null,
                                    'pageId' => '',
                                    'isActive' => true,
                                ]
                            ),
                        ],
                    ]
                ),
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_gravity-forms_999_test-form',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form',
                        'description' => 'Test form description',
                        'submit' => 'Submit',
                        'confirmation_settings_default' => [
                            'confirmation_type' => 'message',
                            'confirmation_message' => [
                                [
                                    'type' => 'paragraph',
                                    'content' => [
                                        [
                                            'type' => 'text',
                                            'text' => 'Thanks for contacting us! We will get in touch with you shortly.',
                                        ],
                                        [
                                            'type' => 'hardBreak',
                                        ],
                                        [
                                            'type' => 'text',
                                            'text' => '{{ submission:test_field_123 }}',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'confirmation_additional' => [
                            [
                                'id' => '0000000000002',
                                'confirmation_name' => 'Additional Confirmation',
                                'confirmation_settings' => [
                                    'confirmation_type' => 'message',
                                    'confirmation_message' => [
                                        [
                                            'type' => 'paragraph',
                                            'content' => [
                                                [
                                                    'type' => 'text',
                                                    'text' => 'Thanks for contacting us! We will get in touch with you shortly.',
                                                ],
                                                [
                                                    'type' => 'hardBreak',
                                                ],
                                                [
                                                    'type' => 'text',
                                                    'text' => '{{ submission:test_field_123 }}',
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                                'type' => 'confirmation',
                                'enabled' => true,
                            ],
                        ],
                    ],
                    'blueprint_fields' => [
                        [
                            'handle' => 'test_field_123',
                            'field' => [
                                'type' => 'text',
                                'display' => 'Test Field',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'single form with disabled additional confirmation' => [
            'site_handle' => $siteHandle,
            'source' => [
                \array_merge(
                    $baseForm,
                    [
                        'fields' => [
                            \array_merge(
                                $baseField_text,
                                []
                            ),
                        ],
                        'notifications' => [],
                        'confirmations' => [
                            \array_merge(
                                $baseConfirmation,
                                []
                            ),
                            \array_merge(
                                $baseConfirmation,
                                [
                                    'id' => '0000000000002',
                                    'name' => 'Additional Confirmation',
                                    'isDefault' => false,
                                    'disableAutoformat' => false,
                                    'page' => '',
                                    'url' => '',
                                    'queryString' => '',
                                    'confirmation_conditional_logic_object' => '',
                                    'confirmation_conditional_logic' => '1',
                                    'conditionalLogic' => null,
                                    'pageId' => '',
                                    'isActive' => false,
                                ]
                            ),
                        ],
                    ]
                ),
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_gravity-forms_999_test-form',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form',
                        'description' => 'Test form description',
                        'submit' => 'Submit',
                        'confirmation_settings_default' => [
                            'confirmation_type' => 'message',
                            'confirmation_message' => [
                                [
                                    'type' => 'paragraph',
                                    'content' => [
                                        [
                                            'type' => 'text',
                                            'text' => 'Thanks for contacting us! We will get in touch with you shortly.',
                                        ],
                                        [
                                            'type' => 'hardBreak',
                                        ],
                                        [
                                            'type' => 'text',
                                            'text' => '{{ submission:test_field_123 }}',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'confirmation_additional' => [
                            [
                                'id' => '0000000000002',
                                'confirmation_name' => 'Additional Confirmation',
                                'confirmation_settings' => [
                                    'confirmation_type' => 'message',
                                    'confirmation_message' => [
                                        [
                                            'type' => 'paragraph',
                                            'content' => [
                                                [
                                                    'type' => 'text',
                                                    'text' => 'Thanks for contacting us! We will get in touch with you shortly.',
                                                ],
                                                [
                                                    'type' => 'hardBreak',
                                                ],
                                                [
                                                    'type' => 'text',
                                                    'text' => '{{ submission:test_field_123 }}',
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                                'type' => 'confirmation',
                                'enabled' => false,
                            ],
                        ],
                    ],
                    'blueprint_fields' => [
                        [
                            'handle' => 'test_field_123',
                            'field' => [
                                'type' => 'text',
                                'display' => 'Test Field',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'single form with additional conditional confirmation' => [
            'site_handle' => $siteHandle,
            'source' => [
                \array_merge(
                    $baseForm,
                    [
                        'fields' => [
                            \array_merge(
                                $baseField_text,
                                []
                            ),
                            \array_merge(
                                $baseField_email,
                                []
                            ),
                        ],
                        'notifications' => [],
                        'confirmations' => [
                            \array_merge(
                                $baseConfirmation,
                                []
                            ),
                            \array_merge(
                                $baseConfirmation,
                                [
                                    'id' => '0000000000002',
                                    'name' => 'Additional Confirmation',
                                    'isDefault' => false,
                                    'disableAutoformat' => false,
                                    'page' => '',
                                    'url' => '',
                                    'queryString' => '',
                                    'confirmation_conditional_logic_object' => [
                                        'actionType' => 'show',
                                        'logicType' => 'all',
                                        'rules' => [
                                            [
                                                'fieldId' => '124',
                                                'operator' => 'is',
                                                'value' => 'foo@bar.com',
                                            ],
                                            [
                                                'fieldId' => '123',
                                                'operator' => 'is',
                                                'value' => 'foo',
                                            ],
                                        ],
                                    ],
                                    'confirmation_conditional_logic' => '1',
                                    'conditionalLogic' => [
                                        'actionType' => 'show',
                                        'logicType' => 'all',
                                        'rules' => [
                                            [
                                                'fieldId' => '124',
                                                'operator' => 'is',
                                                'value' => 'foo@bar.com',
                                            ],
                                            [
                                                'fieldId' => '123',
                                                'operator' => 'is',
                                                'value' => 'foo',
                                            ],
                                        ],
                                    ],
                                    'pageId' => '',
                                    'isActive' => true,
                                ]
                            ),
                        ],
                    ]
                ),
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_gravity-forms_999_test-form',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form',
                        'description' => 'Test form description',
                        'submit' => 'Submit',
                        'confirmation_settings_default' => [
                            'confirmation_type' => 'message',
                            'confirmation_message' => [
                                [
                                    'type' => 'paragraph',
                                    'content' => [
                                        [
                                            'type' => 'text',
                                            'text' => 'Thanks for contacting us! We will get in touch with you shortly.',
                                        ],
                                        [
                                            'type' => 'hardBreak',
                                        ],
                                        [
                                            'type' => 'text',
                                            'text' => '{{ submission:test_field_123 }}',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'confirmation_additional' => [
                            [
                                'id' => '0000000000002',
                                'confirmation_name' => 'Additional Confirmation',
                                'confirmation_settings' => [
                                    'confirmation_type' => 'message',
                                    'confirmation_message' => [
                                        [
                                            'type' => 'paragraph',
                                            'content' => [
                                                [
                                                    'type' => 'text',
                                                    'text' => 'Thanks for contacting us! We will get in touch with you shortly.',
                                                ],
                                                [
                                                    'type' => 'hardBreak',
                                                ],
                                                [
                                                    'type' => 'text',
                                                    'text' => '{{ submission:test_field_123 }}',
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                                'conditional_logic' => [
                                    'if' => [
                                        'test_field_124' => 'equals foo@bar.com',
                                        'test_field_123' => 'equals foo',
                                    ],
                                ],
                                'type' => 'confirmation',
                                'enabled' => true,
                            ],
                        ],
                    ],
                    'blueprint_fields' => [
                        [
                            'handle' => 'test_field_123',
                            'field' => [
                                'type' => 'text',
                                'display' => 'Test Field',
                            ],
                        ],
                        [
                            'handle' => 'test_field_124',
                            'field' => [
                                'type' => 'email',
                                'display' => 'Test Field',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'single form with enabled page confirmation with no query string' => [
            'site_handle' => $siteHandle,
            'use_page_data_mapping' => true,
            'source' => [
                \array_merge(
                    $baseForm,
                    [
                        'fields' => [
                            \array_merge(
                                $baseField_text,
                                []
                            ),
                        ],
                        'notifications' => [],
                        'confirmations' => [
                            \array_merge(
                                $baseConfirmation,
                                []
                            ),
                            \array_merge(
                                $baseConfirmation,
                                [
                                    'id' => '0000000000002',
                                    'name' => 'Additional Confirmation',
                                    'type' => 'page',
                                    'isDefault' => false,
                                    'page' => '321',
                                    'pageId' => '321',
                                    'queryString' => '',
                                    'isActive' => true,
                                    'disableAutoformat' => false,
                                    'url' => '',
                                    'message' => '',
                                ]
                            ),
                        ],
                    ]
                ),
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_gravity-forms_999_test-form',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form',
                        'description' => 'Test form description',
                        'submit' => 'Submit',
                        'confirmation_settings_default' => [
                            'confirmation_type' => 'message',
                            'confirmation_message' => [
                                [
                                    'type' => 'paragraph',
                                    'content' => [
                                        [
                                            'type' => 'text',
                                            'text' => 'Thanks for contacting us! We will get in touch with you shortly.',
                                        ],
                                        [
                                            'type' => 'hardBreak',
                                        ],
                                        [
                                            'type' => 'text',
                                            'text' => '{{ submission:test_field_123 }}',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'confirmation_additional' => [
                            [
                                'id' => '0000000000002',
                                'confirmation_name' => 'Additional Confirmation',
                                'confirmation_settings' => [
                                    'confirmation_type' => 'entry',
                                    'confirmation_page' => 'statamic-entry-321',
                                ],
                                'type' => 'confirmation',
                                'enabled' => true,
                            ],
                        ],
                    ],
                    'blueprint_fields' => [
                        [
                            'handle' => 'test_field_123',
                            'field' => [
                                'type' => 'text',
                                'display' => 'Test Field',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'single form with enabled page confirmation with query string' => [
            'site_handle' => $siteHandle,
            'use_page_data_mapping' => true,
            'source' => [
                \array_merge(
                    $baseForm,
                    [
                        'fields' => [
                            \array_merge(
                                $baseField_text,
                                []
                            ),
                            \array_merge(
                                $baseField_email,
                                []
                            ),
                        ],
                        'notifications' => [],
                        'confirmations' => [
                            \array_merge(
                                $baseConfirmation,
                                []
                            ),
                            \array_merge(
                                $baseConfirmation,
                                [
                                    'id' => '0000000000002',
                                    'name' => 'Additional Confirmation',
                                    'type' => 'page',
                                    'isDefault' => false,
                                    'page' => '321',
                                    'pageId' => '321',
                                    'queryString' => 'foo={Test Field:123}&bar={Test Field:124}',
                                    'isActive' => true,
                                    'disableAutoformat' => false,
                                    'url' => '',
                                    'message' => '',
                                ]
                            ),
                        ],
                    ]
                ),
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_gravity-forms_999_test-form',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form',
                        'description' => 'Test form description',
                        'submit' => 'Submit',
                        'confirmation_settings_default' => [
                            'confirmation_type' => 'message',
                            'confirmation_message' => [
                                [
                                    'type' => 'paragraph',
                                    'content' => [
                                        [
                                            'type' => 'text',
                                            'text' => 'Thanks for contacting us! We will get in touch with you shortly.',
                                        ],
                                        [
                                            'type' => 'hardBreak',
                                        ],
                                        [
                                            'type' => 'text',
                                            'text' => '{{ submission:test_field_123 }}',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'confirmation_additional' => [
                            [
                                'id' => '0000000000002',
                                'confirmation_name' => 'Additional Confirmation',
                                'confirmation_settings' => [
                                    'confirmation_type' => 'entry',
                                    'confirmation_page' => 'statamic-entry-321',
                                    'confirmation_query_string' => 'foo={{ submission:test_field_123 }}&bar={{ submission:test_field_124 }}',
                                ],
                                'type' => 'confirmation',
                                'enabled' => true,
                            ],
                        ],
                    ],
                    'blueprint_fields' => [
                        [
                            'handle' => 'test_field_123',
                            'field' => [
                                'type' => 'text',
                                'display' => 'Test Field',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'single form with enabled redirect confirmation with no query string' => [
            'site_handle' => $siteHandle,
            'source' => [
                \array_merge(
                    $baseForm,
                    [
                        'fields' => [
                            \array_merge(
                                $baseField_text,
                                []
                            ),
                        ],
                        'notifications' => [],
                        'confirmations' => [
                            \array_merge(
                                $baseConfirmation,
                                []
                            ),
                            \array_merge(
                                $baseConfirmation,
                                [
                                    'id' => '0000000000002',
                                    'name' => 'Additional Confirmation',
                                    'type' => 'redirect',
                                    'isDefault' => false,
                                    'url' => 'https:\/\/google.com\/',
                                    'queryString' => '',
                                    'isActive' => true,
                                    'page' => '',
                                    'pageId' => '',
                                    'disableAutoformat' => false,
                                    'message' => '',
                                ]
                            ),
                        ],
                    ]
                ),
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_gravity-forms_999_test-form',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form',
                        'description' => 'Test form description',
                        'submit' => 'Submit',
                        'confirmation_settings_default' => [
                            'confirmation_type' => 'message',
                            'confirmation_message' => [
                                [
                                    'type' => 'paragraph',
                                    'content' => [
                                        [
                                            'type' => 'text',
                                            'text' => 'Thanks for contacting us! We will get in touch with you shortly.',
                                        ],
                                        [
                                            'type' => 'hardBreak',
                                        ],
                                        [
                                            'type' => 'text',
                                            'text' => '{{ submission:test_field_123 }}',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'confirmation_additional' => [
                            [
                                'id' => '0000000000002',
                                'confirmation_name' => 'Additional Confirmation',
                                'confirmation_settings' => [
                                    'confirmation_type' => 'redirect',
                                    'confirmation_redirect_url' => 'https://google.com/',
                                ],
                                'type' => 'confirmation',
                                'enabled' => true,
                            ],
                        ],
                    ],
                    'blueprint_fields' => [
                        [
                            'handle' => 'test_field_123',
                            'field' => [
                                'type' => 'text',
                                'display' => 'Test Field',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'single form with enabled redirect confirmation with query string' => [
            'site_handle' => $siteHandle,
            'source' => [
                \array_merge(
                    $baseForm,
                    [
                        'fields' => [
                            \array_merge(
                                $baseField_text,
                                []
                            ),
                            \array_merge(
                                $baseField_email,
                                []
                            ),
                        ],
                        'notifications' => [],
                        'confirmations' => [
                            \array_merge(
                                $baseConfirmation,
                                []
                            ),
                            \array_merge(
                                $baseConfirmation,
                                [
                                    'id' => '0000000000002',
                                    'name' => 'Additional Confirmation',
                                    'type' => 'redirect',
                                    'isDefault' => false,
                                    'url' => 'https:\/\/google.com\/',
                                    'queryString' => 'foo={Test Field:123}&bar={Test Field:124}',
                                    'isActive' => true,
                                    'page' => '',
                                    'pageId' => '',
                                    'disableAutoformat' => false,
                                    'message' => '',
                                ]
                            ),
                        ],
                    ]
                ),
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_gravity-forms_999_test-form',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form',
                        'description' => 'Test form description',
                        'submit' => 'Submit',
                        'confirmation_settings_default' => [
                            'confirmation_type' => 'message',
                            'confirmation_message' => [
                                [
                                    'type' => 'paragraph',
                                    'content' => [
                                        [
                                            'type' => 'text',
                                            'text' => 'Thanks for contacting us! We will get in touch with you shortly.',
                                        ],
                                        [
                                            'type' => 'hardBreak',
                                        ],
                                        [
                                            'type' => 'text',
                                            'text' => '{{ submission:test_field_123 }}',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'confirmation_additional' => [
                            [
                                'id' => '0000000000002',
                                'confirmation_name' => 'Additional Confirmation',
                                'confirmation_settings' => [
                                    'confirmation_type' => 'redirect',
                                    'confirmation_redirect_url' => 'https://google.com/',
                                    'confirmation_query_string' => 'foo={{ submission:test_field_123 }}&bar={{ submission:test_field_124 }}',
                                ],
                                'type' => 'confirmation',
                                'enabled' => true,
                            ],
                        ],
                    ],
                    'blueprint_fields' => [
                        [
                            'handle' => 'test_field_123',
                            'field' => [
                                'type' => 'text',
                                'display' => 'Test Field',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'single form with single email notification' => [
            'site_handle' => $siteHandle,
            'source' => [
                \array_merge(
                    $baseForm,
                    [
                        'fields' => [
                            \array_merge(
                                $baseField_text,
                                []
                            ),
                        ],
                        'notifications' => [
                            \array_merge(
                                $baseEmail,
                                []
                            ),
                        ],
                        'confirmations' => [],
                    ]
                ),
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_gravity-forms_999_test-form',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form',
                        'description' => 'Test form description',
                        'submit' => 'Submit',
                        'email' => [
                            [
                                'id' => '1000000000001',
                                'name' => 'Test Notification',
                                'enabled' => true,
                                'to_type' => 'email',
                                'to' => '{{ admin_email }}',
                                'cc_type' => 'email',
                                'bcc_type' => 'email',
                                'from' => '{{ admin_email }}',
                                'from_name' => '{{ site:name }}',
                                'reply_to_type' => 'email',
                                'subject' => 'New submission from {{ form:title }}',
                                'message' => [
                                    [
                                        'type' => 'paragraph',
                                        'content' => [
                                            [
                                                'type' => 'text',
                                                'text' => '{{ all_fields }}',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'blueprint_fields' => [
                        [
                            'handle' => 'test_field_123',
                            'field' => [
                                'type' => 'text',
                                'display' => 'Test Field',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'single form with single email notification with enable attachments' => [
            'site_handle' => $siteHandle,
            'source' => [
                \array_merge(
                    $baseForm,
                    [
                        'fields' => [
                            \array_merge(
                                $baseField_text,
                                []
                            ),
                        ],
                        'notifications' => [
                            \array_merge(
                                $baseEmail,
                                ['enableAttachments' => true]
                            ),
                        ],
                        'confirmations' => [],
                    ]
                ),
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_gravity-forms_999_test-form',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form',
                        'description' => 'Test form description',
                        'submit' => 'Submit',
                        'email' => [
                            [
                                'id' => '1000000000001',
                                'name' => 'Test Notification',
                                'enabled' => true,
                                'to_type' => 'email',
                                'to' => '{{ admin_email }}',
                                'cc_type' => 'email',
                                'bcc_type' => 'email',
                                'from' => '{{ admin_email }}',
                                'from_name' => '{{ site:name }}',
                                'reply_to_type' => 'email',
                                'subject' => 'New submission from {{ form:title }}',
                                'message' => [
                                    [
                                        'type' => 'paragraph',
                                        'content' => [
                                            [
                                                'type' => 'text',
                                                'text' => '{{ all_fields }}',
                                            ],
                                        ],
                                    ],
                                ],
                                'attachments' => true,
                            ],
                        ],
                    ],
                    'blueprint_fields' => [
                        [
                            'handle' => 'test_field_123',
                            'field' => [
                                'type' => 'text',
                                'display' => 'Test Field',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'single form with enabled additional email notification' => [
            'site_handle' => $siteHandle,
            'source' => [
                \array_merge(
                    $baseForm,
                    [
                        'fields' => [
                            \array_merge(
                                $baseField_text,
                                []
                            ),
                        ],
                        'notifications' => [
                            \array_merge(
                                $baseEmail,
                                []
                            ),
                            \array_merge(
                                $baseEmail,
                                [
                                    'id' => '1000000000002',
                                    'name' => 'Additional Notification',
                                    'isActive' => true,
                                ]
                            ),
                        ],
                        'confirmations' => [],
                    ]
                ),
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_gravity-forms_999_test-form',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form',
                        'description' => 'Test form description',
                        'submit' => 'Submit',
                        'email' => [
                            [
                                'id' => '1000000000001',
                                'name' => 'Test Notification',
                                'enabled' => true,
                                'to_type' => 'email',
                                'to' => '{{ admin_email }}',
                                'cc_type' => 'email',
                                'bcc_type' => 'email',
                                'from' => '{{ admin_email }}',
                                'from_name' => '{{ site:name }}',
                                'reply_to_type' => 'email',
                                'subject' => 'New submission from {{ form:title }}',
                                'message' => [
                                    [
                                        'type' => 'paragraph',
                                        'content' => [
                                            [
                                                'type' => 'text',
                                                'text' => '{{ all_fields }}',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            [
                                'id' => '1000000000002',
                                'name' => 'Additional Notification',
                                'enabled' => true,
                                'to_type' => 'email',
                                'to' => '{{ admin_email }}',
                                'cc_type' => 'email',
                                'bcc_type' => 'email',
                                'from' => '{{ admin_email }}',
                                'from_name' => '{{ site:name }}',
                                'reply_to_type' => 'email',
                                'subject' => 'New submission from {{ form:title }}',
                                'message' => [
                                    [
                                        'type' => 'paragraph',
                                        'content' => [
                                            [
                                                'type' => 'text',
                                                'text' => '{{ all_fields }}',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'blueprint_fields' => [
                        [
                            'handle' => 'test_field_123',
                            'field' => [
                                'type' => 'text',
                                'display' => 'Test Field',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'single form with disabled email notification' => [
            'site_handle' => $siteHandle,
            'source' => [
                \array_merge(
                    $baseForm,
                    [
                        'fields' => [
                            \array_merge(
                                $baseField_text,
                                []
                            ),
                        ],
                        'notifications' => [
                            \array_merge(
                                $baseEmail,
                                []
                            ),
                            \array_merge(
                                $baseEmail,
                                [
                                    'id' => '1000000000002',
                                    'name' => 'Additional Notification',
                                    'isActive' => false,
                                ]
                            ),
                        ],
                        'confirmations' => [],
                    ]
                ),
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_gravity-forms_999_test-form',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form',
                        'description' => 'Test form description',
                        'submit' => 'Submit',
                        'email' => [
                            [
                                'id' => '1000000000001',
                                'name' => 'Test Notification',
                                'enabled' => true,
                                'to_type' => 'email',
                                'to' => '{{ admin_email }}',
                                'cc_type' => 'email',
                                'bcc_type' => 'email',
                                'from' => '{{ admin_email }}',
                                'from_name' => '{{ site:name }}',
                                'reply_to_type' => 'email',
                                'subject' => 'New submission from {{ form:title }}',
                                'message' => [
                                    [
                                        'type' => 'paragraph',
                                        'content' => [
                                            [
                                                'type' => 'text',
                                                'text' => '{{ all_fields }}',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            [
                                'id' => '1000000000002',
                                'name' => 'Additional Notification',
                                'enabled' => false,
                                'to_type' => 'email',
                                'to' => '{{ admin_email }}',
                                'cc_type' => 'email',
                                'bcc_type' => 'email',
                                'from' => '{{ admin_email }}',
                                'from_name' => '{{ site:name }}',
                                'reply_to_type' => 'email',
                                'subject' => 'New submission from {{ form:title }}',
                                'message' => [
                                    [
                                        'type' => 'paragraph',
                                        'content' => [
                                            [
                                                'type' => 'text',
                                                'text' => '{{ all_fields }}',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'blueprint_fields' => [
                        [
                            'handle' => 'test_field_123',
                            'field' => [
                                'type' => 'text',
                                'display' => 'Test Field',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'single form with additional conditional email notification' => [
            'site_handle' => $siteHandle,
            'source' => [
                \array_merge(
                    $baseForm,
                    [
                        'fields' => [
                            \array_merge(
                                $baseField_text,
                                []
                            ),
                            \array_merge(
                                $baseField_email,
                                []
                            ),
                        ],
                        'notifications' => [
                            \array_merge(
                                $baseEmail,
                                []
                            ),
                            \array_merge(
                                $baseEmail,
                                [
                                    'id' => '1000000000002',
                                    'name' => 'Additional Notification',
                                    'isActive' => true,
                                    'notification_conditional_logic_object' => [
                                        'actionType' => 'show',
                                        'logicType' => 'all',
                                        'rules' => [
                                            [
                                                'fieldId' => '124',
                                                'operator' => 'is',
                                                'value' => 'foo@bar.com',
                                            ],
                                            [
                                                'fieldId' => '123',
                                                'operator' => 'is',
                                                'value' => 'foo',
                                            ],
                                        ],
                                    ],
                                    'notification_conditional_logic' => '1',
                                    'conditionalLogic' => [
                                        'actionType' => 'show',
                                        'logicType' => 'all',
                                        'rules' => [
                                            [
                                                'fieldId' => '124',
                                                'operator' => 'is',
                                                'value' => 'foo@bar.com',
                                            ],
                                            [
                                                'fieldId' => '123',
                                                'operator' => 'is',
                                                'value' => 'foo',
                                            ],
                                        ],
                                    ],
                                ]
                            ),
                        ],
                        'confirmations' => [],
                    ]
                ),
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_gravity-forms_999_test-form',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form',
                        'description' => 'Test form description',
                        'submit' => 'Submit',
                        'email' => [
                            [
                                'id' => '1000000000001',
                                'name' => 'Test Notification',
                                'enabled' => true,
                                'to_type' => 'email',
                                'to' => '{{ admin_email }}',
                                'cc_type' => 'email',
                                'bcc_type' => 'email',
                                'from' => '{{ admin_email }}',
                                'from_name' => '{{ site:name }}',
                                'reply_to_type' => 'email',
                                'subject' => 'New submission from {{ form:title }}',
                                'message' => [
                                    [
                                        'type' => 'paragraph',
                                        'content' => [
                                            [
                                                'type' => 'text',
                                                'text' => '{{ all_fields }}',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            [
                                'id' => '1000000000002',
                                'name' => 'Additional Notification',
                                'enabled' => true,
                                'to_type' => 'email',
                                'to' => '{{ admin_email }}',
                                'cc_type' => 'email',
                                'bcc_type' => 'email',
                                'from' => '{{ admin_email }}',
                                'from_name' => '{{ site:name }}',
                                'reply_to_type' => 'email',
                                'subject' => 'New submission from {{ form:title }}',
                                'message' => [
                                    [
                                        'type' => 'paragraph',
                                        'content' => [
                                            [
                                                'type' => 'text',
                                                'text' => '{{ all_fields }}',
                                            ],
                                        ],
                                    ],
                                ],
                                'conditional_logic' => [
                                    'if' => [
                                        'test_field_124' => 'equals foo@bar.com',
                                        'test_field_123' => 'equals foo',
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'blueprint_fields' => [
                        [
                            'handle' => 'test_field_123',
                            'field' => [
                                'type' => 'text',
                                'display' => 'Test Field',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'single form with enabled additional field email notification' => [
            'site_handle' => $siteHandle,
            'source' => [
                \array_merge(
                    $baseForm,
                    [
                        'fields' => [
                            \array_merge(
                                $baseField_text,
                                []
                            ),
                            \array_merge(
                                $baseField_email,
                                []
                            ),
                        ],
                        'notifications' => [
                            \array_merge(
                                $baseEmail,
                                []
                            ),
                            \array_merge(
                                $baseEmail,
                                [
                                    'id' => '1000000000002',
                                    'name' => 'Additional Notification',
                                    'toType' => 'field',
                                    'toEmail' => '',
                                    'toField' => '124',
                                    'isActive' => true,
                                ]
                            ),
                        ],
                        'confirmations' => [],
                    ]
                ),
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_gravity-forms_999_test-form',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form',
                        'description' => 'Test form description',
                        'submit' => 'Submit',
                        'email' => [
                            [
                                'id' => '1000000000001',
                                'name' => 'Test Notification',
                                'enabled' => true,
                                'to_type' => 'email',
                                'to' => '{{ admin_email }}',
                                'cc_type' => 'email',
                                'bcc_type' => 'email',
                                'from' => '{{ admin_email }}',
                                'from_name' => '{{ site:name }}',
                                'reply_to_type' => 'email',
                                'subject' => 'New submission from {{ form:title }}',
                                'message' => [
                                    [
                                        'type' => 'paragraph',
                                        'content' => [
                                            [
                                                'type' => 'text',
                                                'text' => '{{ all_fields }}',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            [
                                'id' => '1000000000002',
                                'name' => 'Additional Notification',
                                'enabled' => true,
                                'to_type' => 'field',
                                'to_field' => 'test_field_124',
                                'cc_type' => 'email',
                                'bcc_type' => 'email',
                                'from' => '{{ admin_email }}',
                                'from_name' => '{{ site:name }}',
                                'reply_to_type' => 'email',
                                'subject' => 'New submission from {{ form:title }}',
                                'message' => [
                                    [
                                        'type' => 'paragraph',
                                        'content' => [
                                            [
                                                'type' => 'text',
                                                'text' => '{{ all_fields }}',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'blueprint_fields' => [
                        [
                            'handle' => 'test_field_123',
                            'field' => [
                                'type' => 'text',
                                'display' => 'Test Field',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        // Issue wordpress-importer-archive#34: the legacy `fromNameField` alias populates From Name from a
        // form field. It must resolve to a `{{ submission:<handle> }}` merge tag
        // and win over the empty `fromName` literal (which would otherwise fall
        // back to `{{ site:name }}`).
        'single form with legacy fromNameField email notification' => [
            'site_handle' => $siteHandle,
            'source' => [
                \array_merge(
                    $baseForm,
                    [
                        'fields' => [
                            \array_merge(
                                $baseField_text,
                                []
                            ),
                        ],
                        'notifications' => [
                            \array_merge(
                                $baseEmail,
                                []
                            ),
                            \array_merge(
                                $baseEmail,
                                [
                                    'id' => '1000000000002',
                                    'name' => 'Additional Notification',
                                    'fromName' => '',
                                    'fromNameField' => '123',
                                    'isActive' => true,
                                ]
                            ),
                        ],
                        'confirmations' => [],
                    ]
                ),
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_gravity-forms_999_test-form',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form',
                        'description' => 'Test form description',
                        'submit' => 'Submit',
                        'email' => [
                            [
                                'id' => '1000000000001',
                                'name' => 'Test Notification',
                                'enabled' => true,
                                'to_type' => 'email',
                                'to' => '{{ admin_email }}',
                                'cc_type' => 'email',
                                'bcc_type' => 'email',
                                'from' => '{{ admin_email }}',
                                'from_name' => '{{ site:name }}',
                                'reply_to_type' => 'email',
                                'subject' => 'New submission from {{ form:title }}',
                                'message' => [
                                    [
                                        'type' => 'paragraph',
                                        'content' => [
                                            [
                                                'type' => 'text',
                                                'text' => '{{ all_fields }}',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            [
                                'id' => '1000000000002',
                                'name' => 'Additional Notification',
                                'enabled' => true,
                                'to_type' => 'email',
                                'to' => '{{ admin_email }}',
                                'cc_type' => 'email',
                                'bcc_type' => 'email',
                                'from' => '{{ admin_email }}',
                                'from_name' => '{{ submission:test_field_123 }}',
                                'reply_to_type' => 'email',
                                'subject' => 'New submission from {{ form:title }}',
                                'message' => [
                                    [
                                        'type' => 'paragraph',
                                        'content' => [
                                            [
                                                'type' => 'text',
                                                'text' => '{{ all_fields }}',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'blueprint_fields' => [
                        [
                            'handle' => 'test_field_123',
                            'field' => [
                                'type' => 'text',
                                'display' => 'Test Field',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'single form with enabled additional routing email notification' => [
            'site_handle' => $siteHandle,
            'source' => [
                \array_merge(
                    $baseForm,
                    [
                        'fields' => [
                            \array_merge(
                                $baseField_text,
                                []
                            ),
                        ],
                        'notifications' => [
                            \array_merge(
                                $baseEmail,
                                []
                            ),
                            \array_merge(
                                $baseEmail,
                                [
                                    'id' => '1000000000002',
                                    'name' => 'Additional Notification',
                                    'toType' => 'routing',
                                    'toEmail' => '',
                                    'routing' => [
                                        [
                                            'fieldId' => '123',
                                            'operator' => 'is',
                                            'value' => 'Foo',
                                            'email' => 'foo@bar.com',
                                        ],
                                        [
                                            'fieldId' => '123',
                                            'operator' => 'is',
                                            'value' => 'Bar',
                                            'email' => 'baz@qux.com',
                                        ],
                                    ],
                                    'isActive' => true,
                                ]
                            ),
                        ],
                        'confirmations' => [],
                    ]
                ),
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_gravity-forms_999_test-form',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form',
                        'description' => 'Test form description',
                        'submit' => 'Submit',
                        'email' => [
                            [
                                'id' => '1000000000001',
                                'name' => 'Test Notification',
                                'enabled' => true,
                                'to_type' => 'email',
                                'to' => '{{ admin_email }}',
                                'cc_type' => 'email',
                                'bcc_type' => 'email',
                                'from' => '{{ admin_email }}',
                                'from_name' => '{{ site:name }}',
                                'reply_to_type' => 'email',
                                'subject' => 'New submission from {{ form:title }}',
                                'message' => [
                                    [
                                        'type' => 'paragraph',
                                        'content' => [
                                            [
                                                'type' => 'text',
                                                'text' => '{{ all_fields }}',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            [
                                'id' => '1000000000002',
                                'name' => 'Additional Notification',
                                'enabled' => true,
                                'to_type' => 'routing',
                                'to_routing' => [
                                    [
                                        'field' => 'test_field_123',
                                        'operator' => 'equals',
                                        'value' => 'Foo',
                                        'email' => 'foo@bar.com',
                                    ],
                                    [
                                        'field' => 'test_field_123',
                                        'operator' => 'equals',
                                        'value' => 'Bar',
                                        'email' => 'baz@qux.com',
                                    ],
                                ],
                                'cc_type' => 'email',
                                'bcc_type' => 'email',
                                'from' => '{{ admin_email }}',
                                'from_name' => '{{ site:name }}',
                                'reply_to_type' => 'email',
                                'subject' => 'New submission from {{ form:title }}',
                                'message' => [
                                    [
                                        'type' => 'paragraph',
                                        'content' => [
                                            [
                                                'type' => 'text',
                                                'text' => '{{ all_fields }}',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'blueprint_fields' => [
                        [
                            'handle' => 'test_field_123',
                            'field' => [
                                'type' => 'text',
                                'display' => 'Test Field',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'single form with robust email notification content' => [
            'site_handle' => $siteHandle,
            'source' => [
                \array_merge(
                    $baseForm,
                    [
                        'fields' => [
                            \array_merge(
                                $baseField_text,
                                []
                            ),
                            \array_merge(
                                $baseField_email,
                                []
                            ),
                        ],
                        'notifications' => [
                            \array_merge(
                                $baseEmail,
                                []
                            ),
                            \array_merge(
                                $baseEmail,
                                [
                                    'id' => '1000000000002',
                                    'name' => 'Additional Notification',
                                    'toEmail' => 'foo@bar.com, {Test Field:123}',
                                    'fromName' => 'Foo Bar',
                                    'replyTo' => 'foo@bar.com, {Test Field:123}',
                                    'cc' => 'foo@bar.com, {Test Field:123}',
                                    'bcc' => 'foo@bar.com, {Test Field:123}',
                                    'subject' => 'Foo Bar {Test Field:123}',
                                    'message' => 'Foo bar baz.',
                                    'disableAutoformat' => true,
                                    'to' => 'foo@bar.com, {Test Field:123}',
                                    'isActive' => true,
                                ]
                            ),
                        ],
                        'confirmations' => [],
                    ]
                ),
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_gravity-forms_999_test-form',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form',
                        'description' => 'Test form description',
                        'submit' => 'Submit',
                        'email' => [
                            [
                                'id' => '1000000000001',
                                'name' => 'Test Notification',
                                'enabled' => true,
                                'to_type' => 'email',
                                'to' => '{{ admin_email }}',
                                'cc_type' => 'email',
                                'bcc_type' => 'email',
                                'from' => '{{ admin_email }}',
                                'from_name' => '{{ site:name }}',
                                'reply_to_type' => 'email',
                                'subject' => 'New submission from {{ form:title }}',
                                'message' => [
                                    [
                                        'type' => 'paragraph',
                                        'content' => [
                                            [
                                                'type' => 'text',
                                                'text' => '{{ all_fields }}',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            [
                                'id' => '1000000000002',
                                'name' => 'Additional Notification',
                                'enabled' => true,
                                'to_type' => 'email',
                                'to' => 'foo@bar.com,{{ submission:test_field_123 }}',
                                'cc_type' => 'email',
                                'cc' => 'foo@bar.com,{{ submission:test_field_123 }}',
                                'bcc_type' => 'email',
                                'bcc' => 'foo@bar.com,{{ submission:test_field_123 }}',
                                'from' => '{{ admin_email }}',
                                'from_name' => 'Foo Bar',
                                'reply_to_type' => 'email',
                                'reply_to' => 'foo@bar.com,{{ submission:test_field_123 }}',
                                'subject' => 'Foo Bar {{ submission:test_field_123 }}',
                                'message' => [
                                    [
                                        'type' => 'paragraph',
                                        'content' => [
                                            [
                                                'type' => 'text',
                                                'text' => 'Foo bar baz.',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'blueprint_fields' => [
                        [
                            'handle' => 'test_field_123',
                            'field' => [
                                'type' => 'text',
                                'display' => 'Test Field',
                            ],
                        ],
                        [
                            'handle' => 'test_field_124',
                            'field' => [
                                'type' => 'email',
                                'display' => 'Test Field',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'form with limit entries total entries' => [
            'site_handle' => $siteHandle,
            'source' => [
                \array_merge(
                    $baseForm,
                    [
                        'fields' => [
                            \array_merge(
                                $baseField_text,
                                []
                            ),
                        ],
                        'notifications' => [],
                        'confirmations' => [],
                        'limitEntries' => true,
                        'limitEntriesCount' => 42,
                        'limitEntriesPeriod' => '',
                        'limitEntriesMessage' => 'Foo bar baz',
                        'limitEntriesNumber' => '',
                    ]
                ),
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_gravity-forms_999_test-form',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form',
                        'description' => 'Test form description',
                        'submit' => 'Submit',
                        'limit_entries' => true,
                        'limit_entries_count' => '42',
                        'limit_entries_period' => 'total',
                        'limit_entries_message' => 'Foo bar baz',
                    ],
                    'blueprint_fields' => [
                        [
                            'handle' => 'test_field_123',
                            'field' => [
                                'type' => 'text',
                                'display' => 'Test Field',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'form with limit entries per day' => [
            'site_handle' => $siteHandle,
            'source' => [
                \array_merge(
                    $baseForm,
                    [
                        'fields' => [
                            \array_merge(
                                $baseField_text,
                                []
                            ),
                        ],
                        'notifications' => [],
                        'confirmations' => [],
                        'limitEntries' => true,
                        'limitEntriesCount' => 42,
                        'limitEntriesPeriod' => 'day',
                        'limitEntriesMessage' => 'Foo bar baz',
                        'limitEntriesNumber' => '',
                    ]
                ),
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_gravity-forms_999_test-form',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form',
                        'description' => 'Test form description',
                        'submit' => 'Submit',
                        'limit_entries' => true,
                        'limit_entries_count' => '42',
                        'limit_entries_period' => 'day',
                        'limit_entries_message' => 'Foo bar baz',
                    ],
                    'blueprint_fields' => [
                        [
                            'handle' => 'test_field_123',
                            'field' => [
                                'type' => 'text',
                                'display' => 'Test Field',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'form with schedule form' => [
            'site_handle' => $siteHandle,
            'source' => [
                \array_merge(
                    $baseForm,
                    [
                        'fields' => [
                            \array_merge(
                                $baseField_text,
                                []
                            ),
                        ],
                        'notifications' => [],
                        'confirmations' => [],
                        'scheduleForm' => true,
                        'scheduleStart' => '09\/03\/2025',
                        'scheduleEnd' => '09\/30\/2025',
                        'schedulePendingMessage' => 'Foo bar baz',
                        'scheduleMessage' => 'Foo bar baz',
                        'scheduleStartHour' => 10,
                        'scheduleStartMinute' => 15,
                        'scheduleStartAmpm' => 'pm',
                        'scheduleEndHour' => 12,
                        'scheduleEndMinute' => 1,
                        'scheduleEndAmpm' => 'am',
                    ]
                ),
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_gravity-forms_999_test-form',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form',
                        'description' => 'Test form description',
                        'submit' => 'Submit',
                        'schedule_form_query' => true,
                        'schedule_form' => [
                            'start_datetime' => '2025-09-03 22:15',
                            'end_datetime' => '2025-09-30 00:01',
                        ],
                        'schedule_pending_message' => 'Foo bar baz',
                        'schedule_expired_message' => 'Foo bar baz',
                    ],
                    'blueprint_fields' => [
                        [
                            'handle' => 'test_field_123',
                            'field' => [
                                'type' => 'text',
                                'display' => 'Test Field',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'form with require login' => [
            'site_handle' => $siteHandle,
            'source' => [
                \array_merge(
                    $baseForm,
                    [
                        'fields' => [
                            \array_merge(
                                $baseField_text,
                                []
                            ),
                        ],
                        'notifications' => [],
                        'confirmations' => [],
                        'requireLogin' => true,
                        'requireLoginMessage' => 'Foo bar baz',
                    ]
                ),
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_gravity-forms_999_test-form',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form',
                        'description' => 'Test form description',
                        'submit' => 'Submit',
                        'require_login_query' => true,
                        'require_login_message' => 'Foo bar baz',
                    ],
                    'blueprint_fields' => [
                        [
                            'handle' => 'test_field_123',
                            'field' => [
                                'type' => 'text',
                                'display' => 'Test Field',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        // ---
        // TODO: Add necessary functionality to `FieldtypeMappingTrait` and
        // `FieldtypeMapping` to support this expected Statamic blueprint output
        // ---
        'embedded form' => [
            'site_handle' => $siteHandle,
            'source' => [
                \array_merge(
                    $baseForm,
                    [
                        'id' => '012',
                        'title' => 'Test Form 1',
                        'fields' => [
                            \array_merge($baseField_form, [
                                'formId' => '012',
                                'gpnfForm' => '345',
                                'gpnfFormTitle' => 'Test Form 2',
                            ]),
                        ],
                        'notifications' => [],
                        'confirmations' => [],
                    ]
                ),
                \array_merge(
                    $baseForm,
                    [
                        'id' => '345',
                        'title' => 'Test Form 2',
                        'fields' => [
                            \array_merge($baseField_text, [
                                'id' => '1',
                                'formId' => '345',
                            ]),
                            \array_merge($baseField_text, [
                                'id' => '2',
                                'formId' => '345',
                            ]),
                            \array_merge($baseField_text, [
                                'id' => '3',
                                'formId' => '345',
                            ]),
                        ],
                    ]
                ),
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_gravity-forms_012_test-form-1',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form 1',
                        'description' => 'Test form description',
                        'submit' => 'Submit',
                    ],
                    'blueprint_fields' => [
                        [
                            'handle' => 'test_field_189',
                            'field' => [
                                'type' => 'replicator',
                                'display' => 'Test Field',
                                'sets' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        // Grading values mirror the real corpus fixture
        // `gravityforms-export_idhi-uams-edu_stroke-program.json`, whose two quiz forms
        // both carry `grading: passfail`, `passPercent: "50"` (a string), and a full
        // A-E `grades` array. wordpress-importer-archive#420
        'form with quiz grading' => [
            'site_handle' => $siteHandle,
            'source' => [
                \array_merge(
                    $baseForm,
                    [
                        'fields' => [
                            \array_merge(
                                $baseField_text,
                                []
                            ),
                        ],
                        'notifications' => [],
                        'confirmations' => [],
                        'gravityformsquiz' => [
                            'grading' => 'passfail',
                            'passPercent' => '50',
                            'grades' => [
                                ['text' => 'A', 'value' => 90],
                                ['text' => 'B', 'value' => 80],
                                ['text' => 'C', 'value' => 70],
                                ['text' => 'D', 'value' => 60],
                                ['text' => 'E', 'value' => 0],
                            ],
                        ],
                    ]
                ),
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_gravity-forms_999_test-form',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form',
                        'description' => 'Test form description',
                        'submit' => 'Submit',
                        'quiz_grading' => 'passfail',
                        // Coerced from the string Gravity Forms exports.
                        'quiz_pass_percent' => 50,
                        'quiz_grades' => [
                            ['text' => 'A', 'value' => 90],
                            ['text' => 'B', 'value' => 80],
                            ['text' => 'C', 'value' => 70],
                            ['text' => 'D', 'value' => 60],
                            ['text' => 'E', 'value' => 0],
                        ],
                    ],
                    'blueprint_fields' => [
                        [
                            'handle' => 'test_field_123',
                            'field' => [
                                'type' => 'text',
                                'display' => 'Test Field',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];
}

/**
 * Data provider for Gravity Forms block import tests.
 *
 * @return array<string, array<string, string>> Test cases with formId, blockAttributes, and expectedFormId
 */
function gravityFormsBlockImportProvider(): array
{
    return [
        'simple form block' => [
            'formId' => '1',
            'blockAttributes' => '{"formId":"1","title":false,"description":false,"formPreview":false}',
            'expectedFormId' => '1',
        ],
        'form block with styling' => [
            'formId' => '6',
            'blockAttributes' => '{"formId":"6","inputPrimaryColor":"#204ce5"}',
            'expectedFormId' => '6',
        ],
        'form block with advanced options' => [
            'formId' => '6',
            'blockAttributes' => '{"formId":"6","title":false,"description":false,"ajax":true,"tabindex":"99","fieldValues":"foo","theme":"orbital","inputSize":"lg","inputBorderRadius":"6","inputBorderColor":"#596982","inputBackgroundColor":"#d2c9c9","inputColor":"#2e4e73","inputPrimaryColor":"#4961b6","labelFontSize":"18","labelColor":"#143c69","descriptionFontSize":"16","descriptionColor":"#303847","buttonPrimaryBackgroundColor":"#26325a","buttonPrimaryColor":"#ead0d0"}',
            'expectedFormId' => '6',
        ],
    ];
}

/**
 * Data provider for Gravity Forms shortcode import tests.
 *
 * @return array<string, array<string, string>> Test cases with shortcode and expectedFormId
 */
function gravityFormsShortcodeImportProvider(): array
{
    return [
        'simple shortcode' => [
            'shortcode' => '[gravityform id="1"]',
            'expectedFormId' => '1',
        ],
        'shortcode with multiple attributes' => [
            'shortcode' => '[gravityform id="2" title="false" description="true" ajax="true" tabindex="4" field_values="check=First Choice,Second Choice" theme="orbital"]',
            'expectedFormId' => '2',
        ],
        'shortcode wrapped in paragraph' => [
            'shortcode' => '<p>[gravityform id="3"]</p>',
            'expectedFormId' => '3',
        ],
    ];
}

/**
 * Data provider for landing page form variant tests.
 *
 * @return array<string, array{variant: string, formId: string, blockAttributes: ?string, expectedSlug: string}>
 */
function gravityLandingPageFormVariantsProvider(): array
{
    return [
        'form block direct' => [
            'variant' => 'block_direct',
            'formId' => '1',
            'blockAttributes' => '{"formId":"1","title":false,"description":false}',
            'expectedSlug' => 'form-block-direct-page',
        ],
        'form shortcode block direct' => [
            'variant' => 'shortcode_block_direct',
            'formId' => '1',
            'blockAttributes' => null,
            'expectedSlug' => 'form-shortcode-block-direct-page',
        ],
        'form shortcode text direct' => [
            'variant' => 'shortcode_text_direct',
            'formId' => '1',
            'blockAttributes' => null,
            'expectedSlug' => 'form-shortcode-text-direct-page',
        ],
        'form block in section block' => [
            'variant' => 'block_section',
            'formId' => '1',
            'blockAttributes' => '{"formId":"1","title":false,"description":false}',
            'expectedSlug' => 'form-block-section-page',
        ],
        'form shortcode block in section block' => [
            'variant' => 'shortcode_block_section',
            'formId' => '1',
            'blockAttributes' => null,
            'expectedSlug' => 'form-shortcode-block-section-page',
        ],
        'form shortcode text in content block' => [
            'variant' => 'shortcode_text_content',
            'formId' => '1',
            'blockAttributes' => null,
            'expectedSlug' => 'form-shortcode-text-content-page',
        ],
    ];
}

/**
 * Data provider for basic page form variants.
 *
 * @return array<string, array{variant: string, formId: string, blockAttributes: ?string, expectedSlug: string}>
 */
function gravityBasicPageFormVariantsProvider(): array
{
    return [
        'form block direct' => [
            'variant' => 'block_direct',
            'formId' => '1',
            'blockAttributes' => '{"formId":"1","title":false,"description":false}',
            'expectedSlug' => 'form-block-direct-page',
        ],
        'form shortcode block direct' => [
            'variant' => 'shortcode_block_direct',
            'formId' => '1',
            'blockAttributes' => null,
            'expectedSlug' => 'form-shortcode-block-direct-page',
        ],
        'form shortcode text direct' => [
            'variant' => 'shortcode_text_direct',
            'formId' => '1',
            'blockAttributes' => null,
            'expectedSlug' => 'form-shortcode-text-direct-page',
        ],
    ];
}
