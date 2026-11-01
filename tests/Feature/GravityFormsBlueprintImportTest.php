<?php

declare(strict_types=1);

/**
 * Imports synthetic Gravity Forms definitions end to end and asserts the blueprints,
 * form configuration and field mappings they produce.
 *
 * **Host-bound because it needs a database, not because it saves blueprints** — the
 * reason was recorded in wordpress-importer-archive#844, which found the adjacent
 * `GravityFormsUserRegistrationImportTest` citing blueprint saving as its binding and
 * showed that claim to be stale since wordpress-importer-archive#827. This file is different: it composes
 * `RefreshDatabase`, so it needs the host app's migrated SQLite database and the
 * `assets_meta` table `AssetImporter` writes through. Blueprint saving alone would no
 * longer bind it.
 *
 * @command  php artisan test vendor/uams-web/aether-wordpress-importer/tests/Feature/GravityFormsBlueprintImportTest.php
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Statamic\Sites\Site;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\ContentDetector;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\DataManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FormBlueprintCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FormConfigurationManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FormCreationCoordinator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FormIdExtractor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\GravityFormsCoordinator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\UserInteractionHandler;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\GravityForms\Selector;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\GravityForms\Validator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\Loaders\GravityFormsLoader;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\HtmlTagStripper;
use UAMSWeb\Aether\WordPressImporter\Tests\Feature\WordPressImportTestCase;

/**
 * A Gravity Forms loader with no migration API source, i.e. the file path (wordpress-importer-archive#809).
 *
 * The selector's loader is injected rather than built inside it, so these tests supply the
 * same seam the container registers — with no source set, which is what "read the committed
 * export" now means.
 */
function gravityFormsFileLoader(): GravityFormsLoader
{
    return new GravityFormsLoader;
}

uses(WordPressImportTestCase::class, RefreshDatabase::class);

require_once __DIR__.'/../Support/gravity-forms-test-support.php';

beforeEach(function (): void {
    // Guard the live `content/` submodule against anything this test leaves behind (wordpress-importer-archive#570)
    $this->captureSubmoduleStates();

    // Reset the per-test registries that track forms and blueprint handles created
    // during a test so afterEach can delete them. Typed empty-list helpers keep the
    // property types precise for static analysis.
    $this->temporaryForms = gravityEmptyFormList();
    $this->temporaryBlueprintHandles = gravityEmptyStringList();
});

afterEach(function (): void {
    // Clean up any created blueprints
    $blueprintCreator = new FormBlueprintCreator;
    $testHandles = [
        'test_gravity_forms_57_test-form-fieldtypes',
        'test_gravity_forms_59_test-form-default-values',
        'test_gravity_forms_59_test-form-top-level-attributes',
        'test_gravity_forms_61_test-form-field-options-attributes',
        'test_suppressed_fields',
    ];

    foreach ($testHandles as $handle) {
        $blueprintCreator->deleteBlueprint($handle);
    }

    // Clean up any remaining temporary forms (fallback)
    gravityCleanupTemporaryForms($this->temporaryForms, $this->temporaryBlueprintHandles);
    $this->temporaryForms = gravityEmptyFormList();
    $this->temporaryBlueprintHandles = gravityEmptyStringList();

    // Clean up any created page files from tests
    $entrySlugs = [
        'gravity-forms-block-page',
        'gravity-forms-block-page.1',
        'gravity-forms-shortcode-page',
        'gravity-forms-shortcode-page.1',
        'formidable-forms-block-page',
        'formidable-forms-block-page.1',
        'formidable-forms-shortcode-page',
        'formidable-forms-shortcode-page.1',
        'basic-page',
        'landing-page',
    ];

    // Use the trait's static helper method to do the actual cleanup
    $this::cleanupTestFilesWithPaths(
        $entrySlugs,
        'pages', // collection handle
        'default', // site handle
        [], // No specific asset files for GravityForms tests
        [], // No specific taxonomy terms for GravityForms tests
        true // Restore tree files for GravityForms tests
    );

    // Clean up custom glob patterns specific to GravityForms tests
    $customGlobPatterns = [
        'resources/forms/*test*.yaml',
        'resources/forms/*gravity*.yaml',
        'resources/blueprints/forms/*test*.yaml',
        'resources/blueprints/forms/*gravity*.yaml',
    ];
    $this::cleanupItemsWithNormalizedVersions(
        $customGlobPatterns,
        fn (string $pattern): array => gravityGlobMatches($pattern)
    );

    // Clear any temporary data using trait method
    $this->clearTestData();

    // Last, so it judges what the cleanup above did NOT put back (wordpress-importer-archive#570).
    $this->assertSubmodulesUnchanged();
});

/**
 * Unit tests for Gravity Forms form blueprint import/creation process.
 *
 * Tests the mapping functionality from Gravity Forms fieldtypes to Statamic
 * fieldtypes and the creation of form blueprints with proper field
 * configurations.
 *
 * TODO: Make separate tests for the <field_options> attributes.
 *
 * @command  php artisan test vendor/uams-web/aether-wordpress-importer/tests/Feature/GravityFormsBlueprintImportTest.php
 */
it('instantiates the Gravity Forms coordinator', function (): void {
    // One store for the whole graph, as the container provides (wordpress-importer-archive#1103)
    $dataManager = new DataManager;

    // Construct it directly: an uncaught Throwable fails the test on its own,
    // which a negated toThrow(Throwable::class) never does (wordpress-importer-archive#1348)
    $coordinator = new GravityFormsCoordinator(
        new ContentDetector,
        new FormIdExtractor,
        $dataManager,
        new UserInteractionHandler(
            new ContentDetector,
            $dataManager,
            new Selector(
                new Validator,
                gravityFormsFileLoader()
            )
        ),
        new FormCreationCoordinator(
            $dataManager,
            new FormConfigurationManager
        )
    );

    // A new coordinator holds no forms data until one is loaded
    expect($coordinator->getGravityFormsData())->toBeNull();
});

it('creates a form blueprint with fields', function (): void {
    $blueprintCreator = new FormBlueprintCreator;
    $formHandle = 'test_gravity_forms_57_test-form-fieldtypes';
    $formTitle = 'Test Form, Fieldtypes';

    // Get fields from test data
    $fields = gravitySharedTestFormFields();

    // Create blueprint with fields
    $result = $blueprintCreator->createBlueprintWithFields(
        $formHandle,
        $formTitle,
        $fields,
        'gravity forms',
        '57'
    );

    expect($result)->toBeTrue();

    // Verify blueprint was created
    $blueprint = $blueprintCreator->getBlueprint($formHandle);
    expect($blueprint)->not->toBeNull();

    // Clean up
    $blueprintCreator->deleteBlueprint($formHandle);
});

it('sets and uses page data mapping in the form configuration manager', function (): void {
    // Create a FormConfigurationManager instance
    $formConfigurationManager = new FormConfigurationManager;

    // Test that page data mapping can be set
    $pageDataMapping = [
        '123' => [
            'entry' => 'statamic-entry-123',
            'parent' => 'statamic-parent-123',
            'menu_order' => 1,
            'title' => 'Test Page',
        ],
        '456' => [
            'entry' => 'statamic-entry-456',
            'parent' => 'statamic-parent-456',
            'menu_order' => 2,
            'title' => 'Another Test Page',
        ],
    ];

    // Set the page data mapping
    $formConfigurationManager->setPageDataMapping($pageDataMapping);

    // Verify that the page data mapping was set correctly
    expect($formConfigurationManager->getPageDataMapping())->toEqual($pageDataMapping);

    // Test that the page data mapping is accessible
    $retrievedMapping = gravityAsArray($formConfigurationManager->getPageDataMapping());
    expect($retrievedMapping)->toHaveKey('123');
    expect($retrievedMapping)->toHaveKey('456');
    $page123 = gravityRequireMappingEntry($retrievedMapping, '123');
    $page456 = gravityRequireMappingEntry($retrievedMapping, '456');
    expect($page123['entry'])->toEqual('statamic-entry-123');
    expect($page456['entry'])->toEqual('statamic-entry-456');
})->group('page-data-mapping');

it('lets the form creation coordinator set page data mapping', function (): void {
    // Create a FormConfigurationManager instance
    $formConfigurationManager = new FormConfigurationManager;

    // Create a FormCreationCoordinator with the form configuration manager
    $formCreationCoordinator = new FormCreationCoordinator(
        new DataManager,
        $formConfigurationManager
    );

    // Test page data mapping
    $pageDataMapping = [
        '789' => [
            'entry' => 'statamic-entry-789',
            'parent' => 'statamic-parent-789',
            'menu_order' => 3,
            'title' => 'Test Page 789',
        ],
    ];

    // Set the page data mapping on the form creation coordinator
    $formCreationCoordinator->setPageDataMapping($pageDataMapping);

    // Verify that the page data mapping was set on the form configuration manager
    expect($formConfigurationManager->getPageDataMapping())->toEqual($pageDataMapping);
})->group('page-data-mapping');

it('maps repeater field attributes across multiple forms', function (): void {
    // Assert every provider case in a single test so all cases share one app
    // boot rather than one boot per case (wordpress-importer-archive#391). The provider remains the data
    // source.
    foreach (gravityAttributeMappingRepeaterProvider() as $case) {
        // The source is a list of forms, so it needs the list-aware normalizer.
        // gravityNormalizeStringKeyedRow() keeps only string keys and therefore
        // emptied the integer-keyed source outright, which left every assertion
        // below unreachable for all 27 provider rows. wordpress-importer-archive#469
        $source = gravityNormalizeStringKeyedRows(gravityAsArray($case['source'] ?? []));
        $statamic_forms = gravityNormalizeStringKeyedRows(gravityAsArray($case['statamic_forms'] ?? []));
        $site_handle = gravityRequireFormString($case, 'site_handle');
        $use_page_data_mapping = (bool) ($case['use_page_data_mapping'] ?? false);

        $page_data_mapping = $use_page_data_mapping
            ? [
                '321' => [
                    'entry' => 'statamic-entry-321',
                    'parent' => 'statamic-parent-321',
                    'menu_order' => 1,
                    'title' => 'Test Page',
                ],
            ]
            : [];

        $source['version'] = '2.9.16';

        // Create a FormBlueprintCreator instance to use its field creation logic
        $blueprintCreator = new FormBlueprintCreator;

        // ---
        // One store shared by the coordinator, the handler and the form-creation
        // coordinator, as the container provides (wordpress-importer-archive#1103). Separate instances
        // would be separate stores.
        // ---
        $dataManager = new DataManager;

        // Create the GravityFormsCoordinator first
        $coordinator = new GravityFormsCoordinator(
            new ContentDetector,
            new FormIdExtractor,
            $dataManager,
            new UserInteractionHandler(
                new ContentDetector,
                $dataManager,
                new Selector(
                    new Validator,
                    gravityFormsFileLoader()
                )
            ),
            null // Will be set after FormCreationCoordinator is created
        );

        // Create the FormConfigurationManager
        $formConfigurationManager = new FormConfigurationManager;

        // Set page data mapping if provided
        if ($page_data_mapping !== []) {
            $formConfigurationManager->setPageDataMapping($page_data_mapping);
            expect($formConfigurationManager->getPageDataMapping())->toEqual($page_data_mapping);
        }

        // Create the FormCreationCoordinator with the coordinator
        $formCreationCoordinator = new FormCreationCoordinator(
            $dataManager,
            $formConfigurationManager,
            $coordinator
        );

        // Set the FormCreationCoordinator in the coordinator
        $coordinator->setFormCreationCoordinator($formCreationCoordinator);

        $sourceForms = gravityRequireSourceForms($source);
        $siteName = gravitySiteDisplayName($site_handle);

        foreach ($sourceForms as $formData) {
            $parentFormId = $formData['parent_form_id'] ?? '0';

            if (
                \is_string($parentFormId)
                && $parentFormId !== '0'
            ) {
                continue;
            }

            $expectedStatamicForm = gravityFindExpectedStatamicForm(
                $statamic_forms,
                $formData
            );

            if ($expectedStatamicForm === null) {
                continue;
            }

            // Assert: Verify form-level configuration
            if (isset($expectedStatamicForm['configuration'])) {
                $expectedConfig = gravityAsArray($expectedStatamicForm['configuration']);
                $formId = (int) gravityRequireFormScalarString($formData, 'id');

                $tempForm = $formCreationCoordinator->createStatamicFormFromGravityForm(
                    $formId,
                    [$formData],
                    $siteName,
                    $site_handle,
                    new AssetImporter
                );

                if ($tempForm !== null) {
                    $this->temporaryForms[] = $tempForm;
                    $this->temporaryBlueprintHandles[] = gravityRequireFormHandle($tempForm);

                    $actualFormData = gravityFormConfigurationData($tempForm);

                    // Verify all expected configuration attributes
                    foreach ($expectedConfig as $expectedKey => $expectedValue) {
                        expect($expectedKey)->toBeString();
                        if (! is_string($expectedKey)) {
                            throw new RuntimeException('Expected the configuration key to be a string.');
                        }

                        if ($expectedKey === 'title') {
                            // Title is stored separately from form data in Statamic
                            expect($tempForm->title())->toEqual($expectedValue);
                        } elseif ($expectedKey === 'email') {
                            // Email configurations are stored separately using Statamic's email() method
                            $actualEmailConfig = $tempForm->email();
                            expect(gravityNormalizeValueForAssertion($actualEmailConfig))
                                ->toEqual(gravityNormalizeValueForAssertion($expectedValue));
                        } else { // Otherwise
                            expect($actualFormData)->toHaveKey($expectedKey);
                            $actualConfigValue = $actualFormData[$expectedKey];
                            expect(gravityNormalizeValueForAssertion($actualConfigValue))
                                ->toEqual(gravityNormalizeValueForAssertion($expectedValue));
                        }
                    }

                    // A form without any `gravityformsquiz` settings must not pick up
                    // the quiz grading keys — the mapping is a plain passthrough, so an
                    // absent source value is skipped rather than defaulted. wordpress-importer-archive#420
                    if (! isset($formData['gravityformsquiz'])) {
                        expect($actualFormData)->not->toHaveKey('quiz_grading');
                        expect($actualFormData)->not->toHaveKey('quiz_pass_percent');
                        expect($actualFormData)->not->toHaveKey('quiz_grades');
                    }

                    $formTitle = gravityRequireFormString($formData, 'title');
                    if ($formTitle !== '') {
                        expect($actualFormData)->toHaveKey('heading');
                        $heading = $actualFormData['heading'];
                        expect($heading)->toBe(HtmlTagStripper::strip($formTitle));
                    }

                    // ---
                    // Assert: Verify no unexpected configuration attributes are present (strict
                    // checking)
                    // ---
                    // The form is assigned to the site it was imported for (wordpress-importer-archive#1389)
                    expect($actualFormData)->toHaveKey('sites');
                    expect($actualFormData['sites'])->toBe([$site_handle]);

                    $expectedConfigKeys = \array_keys($expectedConfig);
                    $expectedConfigKeys[] = 'sites';
                    if ($formTitle !== '') {
                        $expectedConfigKeys[] = 'heading';
                    }
                    $actualConfigKeys = \array_keys($actualFormData);
                    $unexpectedConfigKeys = array_diff(
                        $actualConfigKeys,
                        $expectedConfigKeys
                    );
                    expect($unexpectedConfigKeys)->toBeEmpty();

                    // Clean up: delete the temporary form
                    $tempForm->delete();
                } else {
                    throw new RuntimeException('Failed to create temporary form for configuration validation');
                }
            }

            if (isset($expectedStatamicForm['handle'])) {
                $formId = (int) gravityRequireFormScalarString($formData, 'id');

                $tempForm = $formCreationCoordinator->createStatamicFormFromGravityForm(
                    $formId,
                    [$formData],
                    $siteName,
                    $site_handle,
                    new AssetImporter
                );

                if ($tempForm !== null) {
                    $this->temporaryForms[] = $tempForm;
                    $this->temporaryBlueprintHandles[] = gravityRequireFormHandle($tempForm);

                    expect($tempForm->handle())->toEqual($expectedStatamicForm['handle']);

                    $tempForm->delete();
                } else {
                    throw new RuntimeException('Failed to create temporary form for handle validation');
                }
            }

            if (isset($expectedStatamicForm['blueprint_fields'])) {
                $expectedBlueprintFields = gravityAsArray($expectedStatamicForm['blueprint_fields']);
                $formFields = gravityRequireFormFieldsFromFormData($formData);
                $formTitle = gravityRequireFormString($formData, 'title');
                $formIdString = gravityRequireFormScalarString($formData, 'id');

                foreach ($expectedBlueprintFields as $expectedFieldData) {
                    expect($expectedFieldData)->toBeArray();
                    if (! is_array($expectedFieldData)) {
                        throw new RuntimeException('Expected the blueprint field data to be an array.');
                    }

                    $sourceFieldData = null;

                    foreach ($formFields as $sourceField) {
                        $generatedHandle = gravityInvokeGenerateFieldHandle(
                            $blueprintCreator,
                            $sourceField
                        );
                        $expectedHandle = $expectedFieldData['handle'] ?? null;

                        if (
                            \is_string($expectedHandle)
                            && $generatedHandle === $expectedHandle
                        ) {
                            $sourceFieldData = $sourceField;
                            break;
                        }
                    }

                    if ($sourceFieldData === null) {
                        continue;
                    }

                    $fieldConfig = gravityInvokeCreateBlueprintField(
                        $blueprintCreator,
                        $sourceFieldData,
                        $sourceForms,
                        $formTitle,
                        $formIdString
                    );

                    // Assert: Verify handle is present and correct
                    if (isset($expectedFieldData['handle'])) {
                        $actualHandle = gravityInvokeGenerateFieldHandle(
                            $blueprintCreator,
                            $sourceFieldData
                        );

                        expect($actualHandle)->toEqual($expectedFieldData['handle']);
                    }

                    // Assert: Verify type is present and correct
                    $expectedFieldDefinition = gravityAsArray($expectedFieldData['field'] ?? []);
                    if (isset($expectedFieldDefinition['type'])) {
                        expect($fieldConfig)->toHaveKey('type');
                        expect($fieldConfig['type'])->toEqual($expectedFieldDefinition['type']);
                    }

                    // Assert: Verify expected field attributes are present and correct
                    $expectedField = $expectedFieldDefinition;
                    foreach ($expectedField as $key => $expectedValue) {
                        expect($key)->toBeString();
                        if (! is_string($key)) {
                            throw new RuntimeException('Expected the repeater field attribute key to be a string.');
                        }

                        if ($key === 'sets') {
                            expect($expectedValue)->toBeArray();
                            if (! is_array($expectedValue)) {
                                throw new RuntimeException('Expected the sets value to be an array.');
                            }
                            gravityAssertRepeaterSetsMatch(
                                $fieldConfig,
                                gravityNormalizeStringKeyedMap($expectedValue)
                            );
                        } else {
                            // Assert that the field has the expected key
                            expect($fieldConfig)->toHaveKey($key);

                            // ---
                            // Assert that the actual set fields field configuration field key matches the
                            // expected field key
                            // ---
                            expect($fieldConfig[$key])->toEqual($expectedValue);
                        }
                    }

                    // Assert: Verify suppressed attributes are excluded
                    $suppressedAttributes = [
                        'field_key',
                        'field_order',
                        'form_id',
                        'id',
                        'required',
                        'btsp',
                        'admin_only',
                        'in_section',
                        'invalid',
                    ];
                    foreach ($suppressedAttributes as $attribute) {
                        expect($fieldConfig)->not->toHaveKey($attribute);
                    }

                    // Assert: Verify no unexpected attributes are present (strict checking)
                    $expectedKeys = \array_keys($expectedField);
                    $actualKeys = \array_keys($fieldConfig);
                    $unexpectedKeys = array_diff(
                        $actualKeys,
                        $expectedKeys
                    );
                    expect($unexpectedKeys)->toBeEmpty();
                }
            }
        }

        // Clean up any temporary forms created during this test
        gravityCleanupTemporaryForms($this->temporaryForms, $this->temporaryBlueprintHandles);
        $this->temporaryForms = gravityEmptyFormList();
        $this->temporaryBlueprintHandles = gravityEmptyStringList();
    }
})
    ->group('field-attributes', 'field-options-mapping', 'repeater');

it('imports Gravity Forms blocks', function (
    string $formId,
    string $blockAttributes,
    string $expectedFormId
): void {
    // Create XML content with Gravity Forms block
    $xmlContent = $this->buildXmlWithGravityFormsBlock(
        $formId,
        $blockAttributes
    );
    $xmlPath = $this->createTemporaryXmlFile($xmlContent);

    try {
        // Test that the import process can handle Gravity Forms blocks
        gravityWordpressImportCommand($this, $xmlPath)
            ->expectsOutput('Starting WordPress import from: '.$xmlPath)
            ->expectsOutput('Content types found in the XML:')
            ->expectsOutput('All content types in XML are supported.')
            ->expectsQuestion(
                'Would you like to provide a GravityForms export file to create Statamic forms?',
                false
            )
            ->expectsQuestion(
                'Which site would you like to import the content into?',
                'default'
            )
            ->expectsOutput("Selected site 'default' is valid.")
            ->expectsQuestion(
                'Which content type would you like to import?',
                'page (1)'
            )
            ->expectsOutput("Selected content type: 'page'")
            ->expectsQuestion(
                'How should existing entries in the \'pages\' collection with matching IDs be handled?',
                'Overwrite matching files'
            )
            ->expectsQuestion(
                'How should existing assets in the relevant container be handled?',
                'Overwrite matching files'
            )
            ->expectsQuestion(
                'Would you like to import more content?',
                false
            )
            ->expectsOutput('Laravel cache cleared.')
            ->expectsOutput('Import process complete.')
            ->assertExitCode(0);

        // Command exit code assertion above confirms import completed successfully.
    } finally {
        // Clean up temporary XML file
        if (\file_exists($xmlPath)) {
            unlink($xmlPath);
        }
    }
})->with(gravityFormsBlockImportProvider());

it('imports Gravity Forms shortcodes', function (
    string $shortcode,
    string $expectedFormId
): void {
    // Create XML content with Gravity Forms shortcode
    $xmlContent = $this->buildXmlWithGravityFormsShortcode($shortcode);
    $xmlPath = $this->createTemporaryXmlFile($xmlContent);

    try {
        // Test that the import process can handle Gravity Forms shortcodes
        gravityWordpressImportCommand($this, $xmlPath)
            ->expectsOutput('Starting WordPress import from: '.$xmlPath)
            ->expectsOutput('Content types found in the XML:')
            ->expectsOutput('All content types in XML are supported.')
            ->expectsQuestion(
                'Would you like to provide a GravityForms export file to create Statamic forms?',
                false
            )
            ->expectsQuestion(
                'Which site would you like to import the content into?',
                'default'
            )
            ->expectsOutput("Selected site 'default' is valid.")
            ->expectsQuestion(
                'Which content type would you like to import?',
                'page (1)'
            )
            ->expectsOutput("Selected content type: 'page'")
            ->expectsQuestion(
                'How should existing entries in the \'pages\' collection with matching IDs be handled?',
                'Overwrite matching files'
            )
            ->expectsQuestion(
                'How should existing assets in the relevant container be handled?',
                'Overwrite matching files'
            )
            ->expectsQuestion(
                'Would you like to import more content?',
                false
            )
            ->expectsOutput('Laravel cache cleared.')
            ->expectsOutput('Import process complete.')
            ->assertExitCode(0);

        // Command exit code assertion above confirms import completed successfully.
    } finally {
        // Clean up temporary XML file
        if (\file_exists($xmlPath)) {
            unlink($xmlPath);
        }
    }
})->with(gravityFormsShortcodeImportProvider());

it('imports Gravity Forms with an export file provided', function (): void {
    // Create XML content with Gravity Forms block
    $xmlContent = $this->buildXmlWithGravityFormsBlock(
        '1',
        '{"formId":"1","title":false,"description":false}'
    );
    $xmlPath = $this->createTemporaryXmlFile($xmlContent);

    // Create Gravity Forms JSON export file
    $gravityFormsJsonPath = $this->createGravityFormsJsonFile();

    try {
        // Test that the import process can handle Gravity Forms with export file
        gravityWordpressImportCommand($this, $xmlPath)
            ->expectsOutput('Starting WordPress import from: '.$xmlPath)
            ->expectsOutput('Content types found in the XML:')
            ->expectsOutput('All content types in XML are supported.')
            ->expectsQuestion(
                'Would you like to provide a GravityForms export file to create Statamic forms?',
                true
            )
            ->expectsQuestion(
                'Enter the path to your GravityForms export file:',
                $gravityFormsJsonPath
            )
            ->expectsQuestion(
                'Which site would you like to import the content into?',
                'default'
            )
            ->expectsOutput("Selected site 'default' is valid.")
            ->expectsQuestion(
                'Which content type would you like to import?',
                'page (1)'
            )
            ->expectsOutput("Selected content type: 'page'")
            ->expectsQuestion(
                'How should existing entries in the \'pages\' collection with matching IDs be handled?',
                'Overwrite matching files'
            )
            ->expectsQuestion(
                'How should existing assets in the relevant container be handled?',
                'Overwrite matching files'
            )
            ->expectsQuestion(
                'Would you like to import more content?',
                false
            )
            ->expectsOutput('Laravel cache cleared.')
            ->expectsOutput('Import process complete.')
            ->assertExitCode(0);

        // Command exit code assertion above confirms import completed successfully.
    } finally {
        // Clean up the temporary files
        if (\file_exists($xmlPath)) {
            unlink($xmlPath);
        }
        if (\file_exists($gravityFormsJsonPath)) {
            unlink($gravityFormsJsonPath);
        }
    }
});

it('creates landing page entries for form variants without an export file', function (
    string $variant,
    string $formId,
    ?string $blockAttributes,
    string $expectedSlug
): void {
    $this->setupLandingPageTestMocks();

    $pageItem = $this->getLandingPageItemWithFormVariant(
        $variant,
        $formId,
        $blockAttributes,
        'gravityforms'
    );

    $xmlContent = $this->buildXmlContent([$pageItem]);

    $pageXmlPath = $this->createTemporaryXmlFile($xmlContent);

    try {
        // ---
        // Landing pages mix ACF blocks with a raw shortcode body for this variant; when
        // mixed classic HTML/text exists outside block comments, ImportWordPress also
        // prompts to continue importing 'page' content (scanForClassicBlocks).
        // ---

        // Check if the expected slug is for the form-shortcode-text-direct-page variant
        if ($expectedSlug === 'form-shortcode-text-direct-page') {
            // Run the import command without export file
            gravityWordpressImportCommand($this, $pageXmlPath)
                ->expectsOutput('Starting WordPress import from: '.$pageXmlPath)
                ->expectsOutput('Content types found in the XML:')
                ->expectsOutput('All content types in XML are supported.')
                ->expectsQuestion(
                    'Would you like to provide a GravityForms export file to create Statamic forms?',
                    false
                )
                ->expectsQuestion(
                    'Which site would you like to import the content into?',
                    'default'
                )
                ->expectsOutput("Selected site 'default' is valid.")
                ->expectsQuestion(
                    'Which content type would you like to import?',
                    'page (1)'
                )
                ->expectsOutput("Selected content type: 'page'")
                ->expectsQuestion(
                    'Would you like to continue importing \'page\' content?',
                    true
                )
                ->expectsQuestion(
                    'How should existing entries in the \'pages\' collection with matching IDs be handled?',
                    'Overwrite matching files'
                )
                ->expectsQuestion(
                    'How should existing assets in the relevant container be handled?',
                    'Overwrite matching files'
                )
                ->expectsQuestion(
                    'Would you like to import more content?',
                    false
                )
                ->assertExitCode(0);
        } else { // Otherwise
            // Run the import command without export file
            gravityWordpressImportCommand($this, $pageXmlPath)
                ->expectsOutput('Starting WordPress import from: '.$pageXmlPath)
                ->expectsOutput('Content types found in the XML:')
                ->expectsOutput('All content types in XML are supported.')
                ->expectsQuestion(
                    'Would you like to provide a GravityForms export file to create Statamic forms?',
                    false
                )
                ->expectsQuestion(
                    'Which site would you like to import the content into?',
                    'default'
                )
                ->expectsOutput("Selected site 'default' is valid.")
                ->expectsQuestion(
                    'Which content type would you like to import?',
                    'page (1)'
                )
                ->expectsOutput("Selected content type: 'page'")
                ->expectsQuestion(
                    'How should existing entries in the \'pages\' collection with matching IDs be handled?',
                    'Overwrite matching files'
                )
                ->expectsQuestion(
                    'How should existing assets in the relevant container be handled?',
                    'Overwrite matching files'
                )
                ->expectsQuestion(
                    'Would you like to import more content?',
                    false
                )
                ->assertExitCode(0);
        }

        $entry = gravityRequirePagesEntryBySlug(
            $expectedSlug
        );

        // Raw shortcode after block markup is mixed content (see scanForClassicBlocks);
        // user must confirm continue; PageEntryCreator adds a Classic block note.
        if ($expectedSlug === 'form-shortcode-text-direct-page') {
            expect(['basic_page', 'landing_page'])->toContain($entry->get('blueprint'));
            if ($entry->get('blueprint') === 'landing_page') {
                expect($entry->get('template'))->toEqual('pages/page_landing');
                expect($entry->get('title_options'))->toEqual('graphic');
            }
            $this->assertEntryContainsClassicBlockNote($entry);
        } else { // Otherwise
            // Assert that the entry uses the landing_page blueprint
            expect($entry->get('blueprint'))->toEqual('landing_page');

            // Assert that the entry uses the pages/page_landing template
            expect($entry->get('template'))->toEqual('pages/page_landing');

            // Assert that the entry uses the graphic title options
            expect($entry->get('title_options'))->toEqual('graphic');

            // Assert that the entry contains a proper Bard form set
            $this->assertEntryContainsFormBardSet(
                $entry,
                '1',
                'Gravity Forms',
                false
            );
        }
    } finally {
        // Clean up temporary files
        if (\file_exists($pageXmlPath)) {
            unlink($pageXmlPath);
        }
    }
})
    ->group('pages', 'pages-without-export', 'landing-pages', 'landing-pages-without-export')
    ->with(gravityLandingPageFormVariantsProvider());

it('creates landing page entries for form variants with an export file', function (
    string $variant,
    string $formId,
    ?string $blockAttributes,
    string $expectedSlug
): void {
    $this->setupLandingPageTestMocks();

    $pageItem = $this->getLandingPageItemWithFormVariant(
        $variant,
        $formId,
        $blockAttributes,
        'gravityforms'
    );

    $xmlContent = $this->buildXmlContent([$pageItem]);

    $pageXmlPath = $this->createTemporaryXmlFile($xmlContent);

    try {
        // Create Gravity Forms JSON export file
        $gravityFormsJsonPath = $this->createGravityFormsJsonFile();

        try {
            if ($expectedSlug === 'form-shortcode-text-direct-page') {
                gravityWordpressImportCommand($this, $pageXmlPath)
                    ->expectsOutput('Starting WordPress import from: '.$pageXmlPath)
                    ->expectsOutput('Content types found in the XML:')
                    ->expectsOutput('All content types in XML are supported.')
                    ->expectsQuestion(
                        'Would you like to provide a GravityForms export file to create Statamic forms?',
                        true
                    )
                    ->expectsQuestion(
                        'Enter the path to your GravityForms export file:',
                        $gravityFormsJsonPath
                    )
                    ->expectsQuestion(
                        'Which site would you like to import the content into?',
                        'default'
                    )
                    ->expectsOutput("Selected site 'default' is valid.")
                    ->expectsQuestion(
                        'Which content type would you like to import?',
                        'page (1)'
                    )
                    ->expectsOutput("Selected content type: 'page'")
                    ->expectsQuestion(
                        'Would you like to continue importing \'page\' content?',
                        true
                    )
                    ->expectsQuestion(
                        'How should existing entries in the \'pages\' collection with matching IDs be handled?',
                        'Overwrite matching files'
                    )
                    ->expectsQuestion(
                        'How should existing assets in the relevant container be handled?',
                        'Overwrite matching files'
                    )
                    ->expectsQuestion(
                        'Would you like to import more content?',
                        false
                    )
                    ->assertExitCode(0);
            } else { // Otherwise
                gravityWordpressImportCommand($this, $pageXmlPath)
                    ->expectsOutput('Starting WordPress import from: '.$pageXmlPath)
                    ->expectsOutput('Content types found in the XML:')
                    ->expectsOutput('All content types in XML are supported.')
                    ->expectsQuestion(
                        'Would you like to provide a GravityForms export file to create Statamic forms?',
                        true
                    )
                    ->expectsQuestion(
                        'Enter the path to your GravityForms export file:',
                        $gravityFormsJsonPath
                    )
                    ->expectsQuestion(
                        'Which site would you like to import the content into?',
                        'default'
                    )
                    ->expectsOutput("Selected site 'default' is valid.")
                    ->expectsQuestion(
                        'Which content type would you like to import?',
                        'page (1)'
                    )
                    ->expectsOutput("Selected content type: 'page'")
                    ->expectsQuestion(
                        'How should existing entries in the \'pages\' collection with matching IDs be handled?',
                        'Overwrite matching files'
                    )
                    ->expectsQuestion(
                        'How should existing assets in the relevant container be handled?',
                        'Overwrite matching files'
                    )
                    ->expectsQuestion(
                        'Would you like to import more content?',
                        false
                    )
                    ->assertExitCode(0);
            }

            $entry = gravityRequirePagesEntryBySlug(
                $expectedSlug
            );

            if ($expectedSlug === 'form-shortcode-text-direct-page') {
                expect(['basic_page', 'landing_page'])->toContain($entry->get('blueprint'));
                if ($entry->get('blueprint') === 'landing_page') {
                    expect($entry->get('template'))->toEqual('pages/page_landing');
                    expect($entry->get('title_options'))->toEqual('graphic');
                }
                $this->assertEntryContainsClassicBlockNote($entry);
            } else { // Otherwise
                expect($entry->get('blueprint'))->toEqual('landing_page');
                expect($entry->get('template'))->toEqual('pages/page_landing');
                expect($entry->get('title_options'))->toEqual('graphic');
                $this->assertEntryContainsFormBardSet(
                    $entry,
                    '1',
                    'Gravity Forms',
                    true
                );
            }
        } finally {
            if (\file_exists($gravityFormsJsonPath)) {
                unlink($gravityFormsJsonPath);
            }
        }
    } finally {
        // Clean up temporary files
        if (\file_exists($pageXmlPath)) {
            unlink($pageXmlPath);
        }
    }
})
    ->group('pages', 'pages-with-export', 'landing-pages', 'landing-pages-with-export')
    ->with(gravityLandingPageFormVariantsProvider());

it('creates basic page entries for form variants without an export file', function (
    string $variant,
    string $formId,
    ?string $blockAttributes,
    string $expectedSlug
): void {
    $this->setupBasicPageTestMocks();

    $pageItem = $this->getBasicPageItemWithFormVariant(
        $variant,
        $formId,
        $blockAttributes,
        'gravityforms'
    );

    $xmlContent = $this->buildXmlContent([$pageItem]);

    $pageXmlPath = $this->createTemporaryXmlFile($xmlContent);

    try {
        // Run the import command without export file
        gravityWordpressImportCommand($this, $pageXmlPath)
            ->expectsOutput('Starting WordPress import from: '.$pageXmlPath)
            ->expectsOutput('Content types found in the XML:')
            ->expectsOutput('All content types in XML are supported.')
            ->expectsQuestion(
                'Would you like to provide a GravityForms export file to create Statamic forms?',
                false
            )
            ->expectsQuestion(
                'Which site would you like to import the content into?',
                'default'
            )
            ->expectsOutput("Selected site 'default' is valid.")
            ->expectsQuestion(
                'Which content type would you like to import?',
                'page (1)'
            )
            ->expectsOutput("Selected content type: 'page'")
            ->expectsQuestion(
                'How should existing entries in the \'pages\' collection with matching IDs be handled?',
                'Overwrite matching files'
            )
            ->expectsQuestion(
                'How should existing assets in the relevant container be handled?',
                'Overwrite matching files'
            )
            ->expectsQuestion(
                'Would you like to import more content?',
                false
            )
            ->assertExitCode(0);

        // Get the entry
        $entry = gravityRequirePagesEntryBySlug(
            $expectedSlug
        );

        expect($entry->get('blueprint'))->toEqual('basic_page');

        if ($expectedSlug !== 'form-shortcode-text-direct-page') {
            expect($entry->get('template'))->toEqual('pages/default');
        }

        $this->assertEntryContainsFormBardSet(
            $entry,
            '1',
            'Gravity Forms',
            false
        );
    } finally {
        // Clean up temporary files
        if (\file_exists($pageXmlPath)) {
            unlink($pageXmlPath);
        }
    }
})
    ->group('pages', 'pages-without-export', 'basic-pages', 'basic-pages-without-export')
    ->with(gravityBasicPageFormVariantsProvider());

it('creates basic page entries for form variants with an export file', function (
    string $variant,
    string $formId,
    ?string $blockAttributes,
    string $expectedSlug
): void {
    $this->setupBasicPageTestMocks();

    $pageItem = $this->getBasicPageItemWithFormVariant(
        $variant,
        $formId,
        $blockAttributes,
        'gravityforms'
    );

    $xmlContent = $this->buildXmlContent([$pageItem]);

    $pageXmlPath = $this->createTemporaryXmlFile($xmlContent);

    try {
        // Create Gravity Forms JSON export file
        $gravityFormsJsonPath = $this->createGravityFormsJsonFile();

        // Attempt to run the import command with export file
        try {
            // Run the import command with export file
            gravityWordpressImportCommand($this, $pageXmlPath)
                ->expectsOutput('Starting WordPress import from: '.$pageXmlPath)
                ->expectsOutput('Content types found in the XML:')
                ->expectsOutput('All content types in XML are supported.')
                ->expectsQuestion(
                    'Would you like to provide a GravityForms export file to create Statamic forms?',
                    true
                )
                ->expectsQuestion(
                    'Enter the path to your GravityForms export file:',
                    $gravityFormsJsonPath
                )
                ->expectsQuestion(
                    'Which site would you like to import the content into?',
                    'default'
                )
                ->expectsOutput("Selected site 'default' is valid.")
                ->expectsQuestion(
                    'Which content type would you like to import?',
                    'page (1)'
                )
                ->expectsOutput("Selected content type: 'page'")
                ->expectsQuestion(
                    'How should existing entries in the \'pages\' collection with matching IDs be handled?',
                    'Overwrite matching files'
                )
                ->expectsQuestion(
                    'How should existing assets in the relevant container be handled?',
                    'Overwrite matching files'
                )
                ->expectsQuestion(
                    'Would you like to import more content?',
                    false
                )
                ->assertExitCode(0);

            // Get the entry
            $entry = gravityRequirePagesEntryBySlug(
                $expectedSlug
            );

            expect($entry->get('blueprint'))->toEqual('basic_page');

            if ($expectedSlug !== 'form-shortcode-text-direct-page') {
                expect($entry->get('template'))->toEqual('pages/default');
            }

            $this->assertEntryContainsFormBardSet(
                $entry,
                '1',
                'Gravity Forms',
                true
            );
        } finally {
            if (\file_exists($gravityFormsJsonPath)) {
                unlink($gravityFormsJsonPath);
            }
        }
    } finally {
        // Clean up temporary files
        if (\file_exists($pageXmlPath)) {
            unlink($pageXmlPath);
        }
    }
})
    ->group('pages', 'pages-with-export', 'basic-pages', 'basic-pages-with-export')
    ->with(gravityBasicPageFormVariantsProvider());
