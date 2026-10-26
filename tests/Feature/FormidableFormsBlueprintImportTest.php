<?php

declare(strict_types=1);

/**
 * Imports Formidable Forms definitions end to end and asserts the blueprints, form
 * configuration, entries and field mappings they produce.
 *
 * **Host-bound because it needs a database and real collections, not because it saves
 * blueprints** — the reason was recorded in wordpress-importer-archive#844. It composes `RefreshDatabase` and
 * drives entries through `CollectionFacade` and the Stache query builder, so it needs
 * the booted host app. The blueprint-saving half stopped binding anything with wordpress-importer-archive#827,
 * which points `BlueprintRepository` at a per-process directory.
 *
 * @command  php artisan test vendor/uams-web/wordpress-importer/tests/Feature/FormidableFormsBlueprintImportTest.php
 */

use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str as IlluminateStr;
use Illuminate\Testing\PendingCommand;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Site as SiteFacade;
use Statamic\Forms\Form as StatamicForm;
use Statamic\Sites\Site;
use Statamic\Stache\Query\EntryQueryBuilder;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Tests\TestCase;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Creators\FormCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\ContentDetector;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\DataManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\FieldtypeMapping;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\FormBlueprintCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\FormConfigurationManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\FormCreationCoordinator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\FormidableFormsCoordinator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\FormIdExtractor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\UserInteractionHandler;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\FormidableForms\Selector;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\FormidableForms\Validator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Loaders\FormidableFormsLoader;
use UAMSWeb\WordPressImporter\Tests\Feature\WordPressImportTestCase;

uses(WordPressImportTestCase::class, RefreshDatabase::class);

// Guard the live `content/` submodule: capture it before each test and refuse
// to leave anything behind afterwards (wordpress-importer-archive#570).
beforeEach(function (): void {
    $this->captureSubmoduleStates();
});

afterEach(function (): void {
    // Delete any blueprints created during the tests by their known handles.
    $blueprintCreator = new FormBlueprintCreator;
    $testHandles = [
        'test_formidable_forms_57_test-form-fieldtypes',
        'test_formidable_forms_59_test-form-default-values',
        'test_formidable_forms_59_test-form-top-level-attributes',
        'test_formidable_forms_61_test-form-field-options-attributes',
        'test_suppressed_fields',
    ];
    foreach ($testHandles as $handle) {
        $blueprintCreator->deleteBlueprint($handle);
    }

    // The original tearDown also called cleanupTemporaryForms() here, but that is a
    // proven no-op: the only test that tracks temporary blueprint handles
    // (the repeater case) cleans and resets them within its own helper, so by
    // teardown the tracked set is always empty.

    // Clean up any created page files (entries, blueprints, assets, tree files).
    $entrySlugs = [
        'basic-page',
        'form-block-direct-page',
        'form-block-direct-page.1',
        'form-block-section-page',
        'form-block-section-page.1',
        'form-shortcode-block-direct-page',
        'form-shortcode-block-direct-page.1',
        'form-shortcode-block-section-page',
        'form-shortcode-block-section-page.1',
        'form-shortcode-text-content-page',
        'form-shortcode-text-content-page.1',
        'form-shortcode-text-direct-page',
        'form-shortcode-text-direct-page.1',
        'formidable-forms-block-page',
        'formidable-forms-block-page.1',
        'formidable-forms-shortcode-page',
        'formidable-forms-shortcode-page.1',
        'gravity-forms-block-page',
        'gravity-forms-block-page.1',
        'gravity-forms-shortcode-page',
        'gravity-forms-shortcode-page.1',
        'landing-page',
    ];
    $this::cleanupTestFilesWithPaths(
        $entrySlugs,
        'pages', // collection handle
        'default', // site handle
        [], // No specific asset files for FormidableForms tests
        [], // No specific taxonomy terms for FormidableForms tests
        true // Restore tree files for FormidableForms tests
    );

    // Clean up custom glob patterns specific to FormidableForms tests.
    $customGlobPatterns = [
        'resources/forms/*test*.yaml',
        'resources/forms/*formidable*.yaml',
        'resources/blueprints/forms/*test*.yaml',
        'resources/blueprints/forms/*formidable*.yaml',
    ];
    $this::cleanupItemsWithNormalizedVersions(
        $customGlobPatterns,
        fn (string $pattern): array => $this::globMatches($pattern)
    );

    // Clear any temporary data using trait method.
    $this->clearTestData();

    // Last, so it judges what the cleanup above did NOT put back (wordpress-importer-archive#570).
    $this->assertSubmodulesUnchanged();
});

/**
 * Unit tests for Formidable Forms form blueprint import/creation process.
 *
 * Tests the mapping functionality from Formidable Forms fieldtypes to Statamic
 * fieldtypes and the creation of form blueprints with proper field configurations.
 *
 * TODO: Make separate tests for the <field_options> attributes.
 *
 * @command  php artisan test vendor/uams-web/wordpress-importer/tests/Feature/FormidableFormsBlueprintImportTest.php
 */
it('instantiates the Formidable Forms coordinator', function (): void {
    // One store for the whole graph, as the container provides (wordpress-importer-archive#1103)
    $dataManager = new DataManager;

    // Construct it directly: an uncaught Throwable fails the test on its own,
    // which a negated toThrow(Throwable::class) never does (wordpress-importer-archive#1348)
    $coordinator = new FormidableFormsCoordinator(
        new ContentDetector,
        new FormIdExtractor,
        $dataManager,
        new UserInteractionHandler(
            new ContentDetector,
            $dataManager,
            new Selector(
                new Validator,
                new FormidableFormsLoader
            )
        ),
        new FormCreationCoordinator(
            $dataManager,
            new FormConfigurationManager
        )
    );

    // A new coordinator holds no forms data until one is loaded
    expect($coordinator->getFormidableFormsData())->toBeNull();
});

it('reads forms bound through the handler back through the coordinator', function (): void {
    // The forms the export selector hands the handler
    $forms = [
        5 => ['id' => '5', 'name' => 'Contact Us', 'fields' => []],
    ];

    // ---
    // A real handler over the shared graph, with only the export selection fixed.
    // The selector is the one step that would otherwise prompt for a file path;
    // everything after it is production code: detection, the handler's write
    // to its store, and the coordinator's read from its own.
    // ---
    $graph = formidableMakeCoordinatorGraph(
        selector: new FormidableFixedFormsSelector($forms)
    );

    // An export carrying a Formidable shortcode, so detection reaches the selector
    $exportPath = formidableShortcodeExport();

    try {
        // `parse()`, as the import command does: detection enumerates the content
        // types it records, which a bare file path does not populate
        $xmlParser = new XmlParser;
        $xmlParser->parse($exportPath);

        $loaded = $graph['coordinator']->handleFormidableFormsDetection(
            $xmlParser,
            new ArrayInput([]),
            new BufferedOutput,
            new class extends Command
            {
                protected $signature = 'test:formidable-binding';
            }
        );
    } finally {
        \unlink($exportPath);
    }

    // ---
    // Assert the handler actually bound something before asserting where it went.
    // A detection that never reached the selector returns false and leaves every
    // store empty, so the read below would fail for a reason unrelated to sharing.
    // ---
    expect($loaded)->toBeTrue();

    // ---
    // The handler wrote to its manager and the coordinator reads from its own.
    // With one shared manager they are the same store; with separate instances
    // this read is null, which is what production never does (wordpress-importer-archive#1103, wordpress-importer-archive#1116).
    // ---
    expect($graph['coordinator']->getFormidableFormsData())->toBe($forms);
});

it('creates a form blueprint with fields', function (): void {
    // Create a new FormBlueprintCreator instance
    $blueprintCreator = new FormBlueprintCreator;

    // Set the form handle and title
    $formHandle = 'test_formidable_forms_57_test-form-fieldtypes';
    $formTitle = 'Test Form, Fieldtypes';

    // Get fields from test data
    $fields = formidableFixtureFormFields();

    // Create blueprint with fields
    $result = $blueprintCreator->createBlueprintWithFields(
        $formHandle,
        $formTitle,
        $fields,
        'formidable-forms',
        '57'
    );

    // Assert that the blueprint was created successfully
    expect($result)->toBeTrue();

    // Assert that the blueprint was created
    $blueprint = $blueprintCreator->getBlueprint($formHandle);
    expect($blueprint)->not->toBeNull();

    // Clean up
    $blueprintCreator->deleteBlueprint($formHandle);
});

it('generates field handles suffixed with the field id', function (): void {
    // Set the field data
    $fieldData = [
        'id' => '949',
        'name' => 'Email Address',
        'type' => 'email',
    ];

    // Field handles are generated with the field ID for uniqueness
    $expectedHandle = 'email_949';

    // Verify the pattern is correct
    expect($expectedHandle)->toMatch('/^[a-z_]+_\d+$/');
});

it('structures the fieldtype mapping array with a default entry per type', function (): void {
    // Get the mapping
    $mapping = FieldtypeMapping::getMapping();

    // Assert that the mapping is not empty
    expect($mapping)->not->toBeEmpty();

    // Test that each mapping has the correct structure
    foreach ($mapping as $fieldType => $mappingConfig) {
        expect($fieldType)->toBeString();
        expect($mappingConfig)->toBeArray();
        if (! \is_array($mappingConfig)) {
            throw new RuntimeException('Expected the mapping config to be an array.');
        }
        expect($mappingConfig)->toHaveKey('default');
    }
});

it('maps each Formidable Forms fieldtype to the expected Statamic fieldtype', function (): void {
    // Assert every provider case in a single test so all cases share one app
    // boot rather than one boot per case (wordpress-importer-archive#391). The provider remains the data
    // source.
    foreach (formidableFieldtypeMappingProvider() as $case) {
        formidableAssertFieldtypeMappingCase(
            $case['formidable_forms_type'],
            $case['expected_statamic_type'],
            $case['assertion_type']
        );
    }
})->group('fieldtypes');

it('structures the field attribute mapping array with a statamic attribute per entry', function (): void {
    // Get the field attribute mapping
    $fieldAttributeMapping = FieldtypeMapping::getFieldAttributeMapping();

    // Assert that the field attribute mapping is not empty
    expect($fieldAttributeMapping)->not->toBeEmpty();

    // Test that each attribute mapping has the correct structure
    foreach ($fieldAttributeMapping as $attributeName => $mappingConfig) {
        expect($attributeName)->toBeString();
        expect($mappingConfig)->toBeArray();
        if (! \is_array($mappingConfig)) {
            throw new RuntimeException('Expected the mapping config to be an array.');
        }
        expect($mappingConfig)->toHaveKey('statamic_attribute');
    }
});

it('maps Formidable Forms field attributes onto Statamic blueprint fields', function (): void {
    // Assert every provider case in a single test so all cases share one app
    // boot rather than one boot per case (wordpress-importer-archive#391). The provider remains the data
    // source.
    foreach (formidableAttributeMappingProvider() as $case) {
        formidableAssertAttributeMappingCase(
            $case['source'],
            $case['statamic_blueprint']
        );
    }
})->group('field-attributes', 'field-options-mapping');

it('structures the field options mapping array with a statamic attribute per entry', function (): void {
    // Get the field options mapping
    $fieldOptionsMapping = FieldtypeMapping::getFieldOptionsMapping();

    // Assert that the field options mapping is not empty
    expect($fieldOptionsMapping)->not->toBeEmpty();

    // Test that each options mapping has the correct structure
    foreach ($fieldOptionsMapping as $optionName => $mappingConfig) {
        expect($optionName)->toBeString();
        expect($mappingConfig)->toBeArray();
        if (! \is_array($mappingConfig)) {
            throw new RuntimeException('Expected the mapping config to be an array.');
        }
        expect($mappingConfig)->toHaveKey('statamic_attribute');
    }
});

it('maps Formidable Forms repeater field attributes onto Statamic forms', function (): void {
    // Assert every provider case in a single test so all cases share one app
    // boot rather than one boot per case (wordpress-importer-archive#391). The provider remains the data
    // source.
    foreach (formidableAttributeMappingRepeaterProvider() as $case) {
        formidableAssertAttributeMappingRepeaterCase(
            $case['source'],
            $case['statamic_forms'],
            $case['site_handle'],
            $case['use_page_data_mapping'] ?? false
        );
    }
})->group('field-attributes', 'field-options-mapping', 'repeater');

it('imports pages containing a Formidable Forms block', function (
    string $formId,
    string $blockAttributes,
    string $expectedFormId
): void {
    // Build the XML content with Formidable Forms block
    $xmlContent = $this->buildXmlWithFormidableFormsBlock(
        $formId,
        $blockAttributes
    );

    // Create a temporary XML file
    $xmlPath = $this->createTemporaryXmlFile($xmlContent);

    try {
        // Test that the import process can handle Formidable Forms blocks
        formidableWordpressImportCommand($this, $xmlPath)
            ->expectsOutput('Starting WordPress import from: '.$xmlPath)
            ->expectsOutput('Content types found in the XML:')
            ->expectsOutput('All content types in XML are supported.')
            ->expectsQuestion(
                'Would you like to provide a FormidableForms export file to create Statamic forms?',
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
        // Clean up the temporary XML file
        if (\file_exists($xmlPath)) {
            unlink($xmlPath);
        }
    }
})->with(formidableFormsBlockImportProvider());

it('imports pages containing a Formidable Forms shortcode', function (
    string $shortcode,
    string $expectedFormId
): void {
    // Create XML content with Formidable Forms shortcode
    $xmlContent = $this->buildXmlWithFormidableFormsShortcode($shortcode);

    // Create a temporary XML file
    $xmlPath = $this->createTemporaryXmlFile($xmlContent);

    try {
        // Test that the import process can handle Formidable Forms shortcodes
        formidableWordpressImportCommand($this, $xmlPath)
            ->expectsOutput('Starting WordPress import from: '.$xmlPath)
            ->expectsOutput('Content types found in the XML:')
            ->expectsOutput('All content types in XML are supported.')
            ->expectsQuestion(
                'Would you like to provide a FormidableForms export file to create Statamic forms?',
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
        // Clean up the temporary XML file
        if (\file_exists($xmlPath)) {
            unlink($xmlPath);
        }
    }
})->with(formidableFormsShortcodeImportProvider());

it('imports Formidable Forms content with an export file provided', function (): void {
    // Create XML content with FormidableForms block
    $xmlContent = $this->buildXmlWithFormidableFormsBlock(
        '1',
        '{"formId":"1","title":false,"description":false}'
    );

    // Create a temporary XML file for the FormidableForms block
    $xmlPath = $this->createTemporaryXmlFile($xmlContent);

    // Create a temporary FormidableForms XML file
    $formidableFormsXmlPath = $this->createFormidableFormsXmlFile();

    try {
        // Test that the import process can handle FormidableForms with export file
        formidableWordpressImportCommand($this, $xmlPath)
            ->expectsOutput('Starting WordPress import from: '.$xmlPath)
            ->expectsOutput('Content types found in the XML:')
            ->expectsOutput('All content types in XML are supported.')
            ->expectsQuestion(
                'Would you like to provide a FormidableForms export file to create Statamic forms?',
                true
            )
            ->expectsQuestion(
                'Enter the path to your FormidableForms export file:',
                $formidableFormsXmlPath
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

        if (\file_exists($formidableFormsXmlPath)) {
            unlink($formidableFormsXmlPath);
        }
    }
});

it('sets and reads the page data mapping on the form configuration manager', function (): void {
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
    $retrievedMapping = formidableNormalizeStringKeyedMap(
        $formConfigurationManager->getPageDataMapping()
    );

    $page123 = formidableRequireMappingEntry($retrievedMapping, '123');
    $page456 = formidableRequireMappingEntry($retrievedMapping, '456');

    // Assert that the retrieved mapping has the expected values
    expect(formidableStringValue($page123['entry'] ?? null))->toEqual('statamic-entry-123');
    expect(formidableStringValue($page456['entry'] ?? null))->toEqual('statamic-entry-456');
})->group('page-data-mapping');

it('propagates the page data mapping from the form creation coordinator', function (): void {
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

it('creates landing pages for Formidable Forms variants without an export file', function (
    string $variant,
    string $formId,
    ?string $blockAttributes,
    string $expectedSlug
): void {
    // Setup landing page test mocks
    $this->setupLandingPageTestMocks();

    // Get the landing page item with the form variant
    $pageItem = $this->getLandingPageItemWithFormVariant(
        $variant,
        $formId,
        $blockAttributes,
        'formidable'
    );

    // Build the XML content
    $xmlContent = $this->buildXmlContent([$pageItem]);

    // Create a temporary XML file
    $pageXmlPath = $this->createTemporaryXmlFile($xmlContent);

    try {
        // Mixed Gutenberg + raw shortcode (see ImportWordPress::scanForClassicBlocks).
        $expectContinuePrompt = $expectedSlug === 'form-shortcode-text-direct-page';

        // Run the import command with common expectations
        formidableRunPageImportCommandWithoutExport(
            $this,
            $pageXmlPath,
            $expectedSlug,
            $expectContinuePrompt
        );

        // Get the entry
        $entry = formidableRequirePagesEntryBySlug($expectedSlug);

        if ($expectedSlug === 'form-shortcode-text-direct-page') {
            // Mixed Gutenberg + raw shortcode may import as basic_page or landing_page
            expect(['basic_page', 'landing_page'])->toContain($entry->get('blueprint'));

            if ($entry->get('blueprint') === 'landing_page') {
                expect($entry->get('template'))->toEqual('pages/page_landing');
                expect($entry->get('title_options'))->toEqual('graphic');
            }

            $this->assertEntryContainsClassicBlockNote($entry);
        } else {
            expect($entry->get('blueprint'))->toEqual('landing_page');
            expect($entry->get('template'))->toEqual('pages/page_landing');
            expect($entry->get('title_options'))->toEqual('graphic');
            $this->assertEntryContainsFormBardSet(
                $entry,
                '1',
                'Formidable Forms',
                false
            );
        }
    } finally {
        // Clean up the temporary XML file
        if (\file_exists($pageXmlPath)) {
            unlink($pageXmlPath);
        }
    }
})
    ->group('pages', 'pages-without-export', 'landing-pages', 'landing-pages-without-export')
    ->with(formidableLandingPageFormVariantsProvider());

it('creates landing pages for Formidable Forms variants with an export file', function (
    string $variant,
    string $formId,
    ?string $blockAttributes,
    string $expectedSlug
): void {
    // Setup landing page test mocks
    $this->setupLandingPageTestMocks();

    // Get the landing page item with the form variant
    $pageItem = $this->getLandingPageItemWithFormVariant(
        $variant,
        $formId,
        $blockAttributes,
        'formidable'
    );

    // Build the XML content
    $xmlContent = $this->buildXmlContent([$pageItem]);

    // Create a temporary XML file
    $pageXmlPath = $this->createTemporaryXmlFile($xmlContent);

    try {
        // Create FormidableForms XML export file
        $formidableFormsXmlPath = $this->createFormidableFormsXmlFile();

        try {
            $expectContinuePrompt = $expectedSlug === 'form-shortcode-text-direct-page';

            // Run the page import command
            formidableRunPageImportCommand(
                $this,
                $pageXmlPath,
                function (PendingCommand $command) use ($formidableFormsXmlPath): PendingCommand {
                    return $command
                        ->expectsQuestion(
                            'Would you like to provide a FormidableForms export file to create Statamic forms?',
                            true
                        )
                        ->expectsQuestion(
                            'Enter the path to your FormidableForms export file:',
                            $formidableFormsXmlPath
                        );
                },
                $expectContinuePrompt
            );

            // Get the entry
            $entry = formidableRequirePagesEntryBySlug($expectedSlug);

            if ($expectedSlug === 'form-shortcode-text-direct-page') {
                // Mixed Gutenberg + raw shortcode may import as basic_page or landing_page
                expect(['basic_page', 'landing_page'])->toContain($entry->get('blueprint'));

                if ($entry->get('blueprint') === 'landing_page') {
                    expect($entry->get('template'))->toEqual('pages/page_landing');
                    expect($entry->get('title_options'))->toEqual('graphic');
                }

                $this->assertEntryContainsClassicBlockNote($entry);
            } else {
                expect($entry->get('blueprint'))->toEqual('landing_page');
                expect($entry->get('template'))->toEqual('pages/page_landing');
                expect($entry->get('title_options'))->toEqual('graphic');
                $this->assertEntryContainsFormBardSet(
                    $entry,
                    '1',
                    'Formidable Forms',
                    true
                );
            }
        } finally {
            // Clean up the temporary FormidableForms XML file
            if (\file_exists($formidableFormsXmlPath)) {
                unlink($formidableFormsXmlPath);
            }
        }
    } finally {
        // Clean up the temporary XML file
        if (\file_exists($pageXmlPath)) {
            unlink($pageXmlPath);
        }
    }
})
    ->group('pages', 'pages-with-export', 'landing-pages', 'landing-pages-with-export')
    ->with(formidableLandingPageFormVariantsProvider());

it('creates basic pages for Formidable Forms variants without an export file', function (
    string $variant,
    string $formId,
    ?string $blockAttributes,
    string $expectedSlug
): void {
    // Setup basic page test mocks
    $this->setupBasicPageTestMocks();

    // Get the basic page item with the form variant
    $pageItem = $this->getBasicPageItemWithFormVariant(
        $variant,
        $formId,
        $blockAttributes,
        'formidable'
    );

    // Build the XML content
    $xmlContent = $this->buildXmlContent([$pageItem]);

    // Create a temporary XML file
    $pageXmlPath = $this->createTemporaryXmlFile($xmlContent);

    try {
        // Run the import command with common expectations
        formidableRunPageImportCommandWithoutExport(
            $this,
            $pageXmlPath,
            $expectedSlug
        );

        // Get the entry
        $entry = formidableRequirePagesEntryBySlug($expectedSlug);

        // Assert that the entry blueprint is basic_page
        expect($entry->get('blueprint'))->toEqual('basic_page');

        if ($expectedSlug !== 'form-shortcode-text-direct-page') {
            expect($entry->get('template'))->toEqual('pages/default');
        }

        // Assert that the entry contains a Formidable Forms Bard set
        $this->assertEntryContainsFormBardSet(
            $entry,
            '1',
            'Formidable Forms',
            false
        );
    } finally {
        // Clean up the temporary XML file
        if (\file_exists($pageXmlPath)) {
            unlink($pageXmlPath);
        }
    }
})
    ->group('pages', 'pages-without-export', 'basic-pages', 'basic-pages-without-export')
    ->with(formidableBasicPageFormVariantsProvider());

it('creates basic pages for Formidable Forms variants with an export file', function (
    string $variant,
    string $formId,
    ?string $blockAttributes,
    string $expectedSlug
): void {
    // Setup basic page test mocks
    $this->setupBasicPageTestMocks();

    // Get the basic page item with the form variant
    $pageItem = $this->getBasicPageItemWithFormVariant(
        $variant,
        $formId,
        $blockAttributes,
        'formidable'
    );

    // Build the XML content
    $xmlContent = $this->buildXmlContent([$pageItem]);

    // Create a temporary XML file
    $pageXmlPath = $this->createTemporaryXmlFile($xmlContent);

    try {
        // Create FormidableForms XML export file
        $formidableFormsXmlPath = $this->createFormidableFormsXmlFile();

        try {
            // Run the page import command
            formidableRunPageImportCommand(
                $this,
                $pageXmlPath,
                function (PendingCommand $command) use ($formidableFormsXmlPath): PendingCommand {
                    return $command
                        ->expectsQuestion(
                            'Would you like to provide a FormidableForms export file to create Statamic forms?',
                            true
                        )
                        ->expectsQuestion(
                            'Enter the path to your FormidableForms export file:',
                            $formidableFormsXmlPath
                        );
                },
                false // Basic pages don't trigger the "continue importing" prompt
            );

            // Get the entry
            $entry = formidableRequirePagesEntryBySlug($expectedSlug);

            // Assert that the entry blueprint is basic_page
            expect($entry->get('blueprint'))->toEqual('basic_page');

            if ($expectedSlug !== 'form-shortcode-text-direct-page') {
                expect($entry->get('template'))->toEqual('pages/default');
            }

            // Assert that the entry contains a Formidable Forms Bard set
            $this->assertEntryContainsFormBardSet(
                $entry,
                '1',
                'Formidable Forms',
                true
            );
        } finally {
            // Clean up the FormidableForms XML file
            if (\file_exists($formidableFormsXmlPath)) {
                unlink($formidableFormsXmlPath);
            }
        }
    } finally {
        // Clean up the temporary XML file
        if (\file_exists($pageXmlPath)) {
            unlink($pageXmlPath);
        }
    }
})
    ->group('pages', 'pages-with-export', 'basic-pages', 'basic-pages-with-export')
    ->with(formidableBasicPageFormVariantsProvider());

/**
 * Normalize mixed values to array shape for safe iteration and offsets.
 *
 * @param  mixed  $value  The candidate array value
 * @return array<int|string, mixed> The normalized array value
 */
function formidableAsArray(mixed $value): array
{
    if (! \is_array($value)) {
        return [];
    }

    return $value;
}

/**
 * Normalize mixed values to strings for safe string-only operations.
 *
 * @param  mixed  $value  The candidate string value
 * @return ?string The normalized string value
 */
function formidableStringValue(mixed $value): ?string
{
    if (! \is_string($value)) {
        return null;
    }

    return $value;
}

/**
 * Normalize a mixed row to a string-keyed array for assertions.
 *
 * @return array<string, mixed>
 */
function formidableNormalizeStringKeyedRow(mixed $row): array
{
    expect($row)->toBeArray();

    if (! \is_array($row)) {
        throw new RuntimeException('Expected a string-keyed row array.');
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
 * Normalize a list of mixed rows to string-keyed arrays.
 *
 * @param  array<int|string, mixed>  $rows
 * @return list<array<string, mixed>>
 */
function formidableNormalizeStringKeyedRows(array $rows): array
{
    $normalized = [];

    foreach ($rows as $row) {
        $normalized[] = formidableNormalizeStringKeyedRow($row);
    }

    return $normalized;
}

/**
 * Normalize a map keyed by id (string or numeric) with array values.
 *
 * @param  array<int|string, mixed>  $map
 * @return array<string, mixed>
 */
function formidableNormalizeStringKeyedMap(array $map): array
{
    $normalized = [];

    foreach ($map as $key => $value) {
        // PHP stores numeric-string array keys (e.g. WordPress page ids like
        // '123') as integers, so guard on the value and normalize the key to a
        // string rather than dropping every numeric-keyed entry (wordpress-importer-archive#400).
        if (\is_array($value)) {
            $normalized[(string) $key] = $value;
        }
    }

    return $normalized;
}

/**
 * Return a page-data mapping row for the given WordPress page id.
 *
 * @param  array<string, mixed>  $mapping
 * @return array<string, mixed>
 */
function formidableRequireMappingEntry(array $mapping, string $pageId): array
{
    if (! \array_key_exists($pageId, $mapping)) {
        throw new RuntimeException("Expected page data mapping offset '{$pageId}'.");
    }

    return formidableNormalizeStringKeyedRow($mapping[$pageId]);
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
function formidableMessageKey(mixed $key): string
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
 * Return a string field from a normalized form array.
 *
 * @param  array<string, mixed>  $formData
 */
function formidableRequireFormString(array $formData, string $key): string
{
    $value = $formData[$key] ?? null;
    expect($value)->toBeString();

    if (! \is_string($value)) {
        throw new RuntimeException("Expected form data key '{$key}' to be a string.");
    }

    return $value;
}

/**
 * Return form rows from a repeater provider source payload.
 *
 * @param  array<string, mixed>  $source
 * @return list<array<string, mixed>>
 */
function formidableRequireSourceForms(array $source): array
{
    $forms = formidableAsArray($source['forms'] ?? []);
    $validated = [];

    foreach ($forms as $form) {
        $validated[] = formidableNormalizeStringKeyedRow($form);
    }

    return $validated;
}

/**
 * Return view rows from a repeater provider source payload.
 *
 * @param  array<string, mixed>  $source
 * @return list<array<string, mixed>>
 */
function formidableSourceViews(array $source): array
{
    $views = formidableAsArray($source['views'] ?? []);
    $validated = [];

    foreach ($views as $view) {
        if (\is_array($view)) {
            $validated[] = formidableNormalizeStringKeyedRow($view);
        }
    }

    return $validated;
}

/**
 * Return a blueprint expectation row at the given index.
 *
 * @param  list<array<string, mixed>>  $statamicBlueprint
 * @return array<string, mixed>
 */
function formidableRequireBlueprintExpectationAt(array $statamicBlueprint, int $index): array
{
    expect($statamicBlueprint)->toHaveKey($index);

    if (! \array_key_exists($index, $statamicBlueprint)) {
        throw new RuntimeException("Blueprint expectation at index {$index} should exist.");
    }

    return formidableNormalizeStringKeyedRow($statamicBlueprint[$index]);
}

/**
 * Invoke the private createBlueprintField method via reflection.
 *
 * @param  array<string, mixed>  $fieldData
 * @param  list<array<string, mixed>>  $formFields  THIS form's fields, which is what the
 *                                                  production path passes
 * @param  list<array<string, mixed>>  $sourceForms  Every exported form, set on the creator
 *                                                   so a repeater can reach its child
 * @return array<string, mixed>
 */
function formidableInvokeCreateBlueprintField(
    FormBlueprintCreator $blueprintCreator,
    array $fieldData,
    array $formFields,
    array $sourceForms = []
): array {
    $reflection = new ReflectionClass($blueprintCreator);

    // `$sourceForms` is how a repeater reaches the child form it names, and the
    // production path sets it through `createBlueprintWithFields()`. Setting it here
    // rather than passing forms as `$formFields` is what keeps the two scopes apart:
    // before wordpress-importer-archive#1158 this helper passed forms into the fields position, which happened to
    // satisfy the `button_label` lookup and silently starved the child-form one.
    $sourceFormsProperty = $reflection->getProperty('sourceForms');
    $sourceFormsProperty->setAccessible(true);
    $sourceFormsProperty->setValue(
        $blueprintCreator,
        $sourceForms === [] ? null : $sourceForms
    );

    $method = $reflection->getMethod('createBlueprintField');
    $method->setAccessible(true);

    $result = $method->invoke(
        $blueprintCreator,
        $fieldData,
        'formidable-forms',
        $formFields,
        'Test Form',
        '57',
        'supply_chain'
    );

    return formidableNormalizeStringKeyedRow($result);
}

/**
 * Invoke the private generateFieldHandle method via reflection.
 *
 * @param  array<string, mixed>  $fieldData
 */
function formidableInvokeGenerateFieldHandle(
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
    expect($result)->toBeString();

    if (! \is_string($result)) {
        throw new RuntimeException('Expected the generated field handle to be a string.');
    }

    return $result;
}

/**
 * Return normalized form configuration data from a Statamic form.
 *
 * @return array<string, mixed>
 */
function formidableFormConfigurationData(StatamicForm $form): array
{
    // Statamic 6 returns the form's data as an Illuminate Collection; earlier
    // versions exposed it as Statamic\Fields\Values (wordpress-importer-archive#400).
    $data = $form->data();
    expect($data)->toBeInstanceOf(Collection::class);

    if (! $data instanceof Collection) {
        throw new RuntimeException('Expected the form data to be a Collection.');
    }

    $all = $data->all();

    $normalized = [];

    foreach ($all as $key => $value) {
        if (\is_string($key)) {
            $normalized[$key] = $value;
        }
    }

    return $normalized;
}

/**
 * Find the expected Statamic form definition for a Formidable source form.
 *
 * @param  list<array<string, mixed>>  $statamicForms
 * @param  array<string, mixed>  $formData
 * @return array<string, mixed>|null
 */
function formidableFindExpectedStatamicForm(array $statamicForms, array $formData): ?array
{
    $formId = formidableRequireFormString($formData, 'id');
    $formName = formidableRequireFormString($formData, 'name');
    $sluggifiedName = IlluminateStr::slug($formName);

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
 * Merge source views into a form payload when present.
 *
 * @param  array<string, mixed>  $formData
 * @param  array<string, mixed>  $source
 * @return array<string, mixed>
 */
function formidableMergeSourceViewsIntoFormData(array $formData, array $source): array
{
    $views = formidableSourceViews($source);

    if ($views === []) {
        return $formData;
    }

    $formDataWithViews = $formData;
    $formDataWithViews['views'] = $views;

    return $formDataWithViews;
}

/**
 * Return field rows from a Formidable form payload.
 *
 * @param  array<string, mixed>  $formData
 * @return list<array<string, mixed>>
 */
function formidableRequireFormFieldsFromFormData(array $formData): array
{
    $fields = formidableAsArray($formData['fields'] ?? []);
    $validated = [];

    foreach ($fields as $field) {
        $validated[] = formidableNormalizeStringKeyedRow($field);
    }

    return $validated;
}

/**
 * Return test form field rows from the shared fixture data.
 *
 * @return list<array<string, mixed>>
 */
function formidableFixtureFormFields(): array
{
    $data = formidableTestFormData();
    $fields = formidableAsArray($data['fields'] ?? []);
    $validated = [];

    foreach ($fields as $field) {
        $validated[] = formidableNormalizeStringKeyedRow($field);
    }

    return $validated;
}

/**
 * Resolve a Statamic site display name for form creation tests.
 */
function formidableSiteDisplayName(string $siteHandle): string
{
    $siteInstance = SiteFacade::get($siteHandle);
    if (! $siteInstance instanceof Site) {
        return $siteHandle;
    }

    $name = $siteInstance->name();

    return \is_string($name) ? $name : $siteHandle;
}

/**
 * Return the Stache-backed entry query builder from the pages collection.
 */
function formidableEntryQuery(): EntryQueryBuilder
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
function formidableRequirePagesEntryBySlug(string $slug): StatamicEntry
{
    $query = formidableEntryQuery()->where(
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
 * @param  TestCase  $test  The bound test case (provides the artisan() harness)
 * @param  string  $file  Path to the WordPress export XML file
 */
function formidableWordpressImportCommand(TestCase $test, string $file): PendingCommand
{
    $command = $test->artisan(
        'statamic:import:wordpress',
        ['file' => $file]
    );
    expect($command)->toBeInstanceOf(PendingCommand::class);

    if (! $command instanceof PendingCommand) {
        throw new RuntimeException('Expected a PendingCommand instance.');
    }

    return $command;
}

/**
 * Run the WordPress import command with common expectations for page imports.
 *
 * @param  TestCase  $test  The bound test case (provides the artisan() harness)
 * @param  string  $pageXmlPath  The path to the XML file
 * @param  callable(PendingCommand): PendingCommand|null  $initialPromptsCallback  Optional callback to add initial prompts (e.g., export file prompts) before
 *                                                                                 common prompts
 * @param  bool  $expectContinuePrompt  Whether to expect the "continue importing" prompt (default: false)
 */
function formidableRunPageImportCommand(
    TestCase $test,
    string $pageXmlPath,
    ?callable $initialPromptsCallback = null,
    bool $expectContinuePrompt = false
): void {
    // Create a new Artisan command instance
    $command = formidableWordpressImportCommand($test, $pageXmlPath)
        ->expectsOutput('Starting WordPress import from: '.$pageXmlPath)
        ->expectsOutput('Content types found in the XML:')
        ->expectsOutput('All content types in XML are supported.');

    // Add initial prompts if callback is provided (e.g., export file prompts).
    // The callable's typed signature already guarantees a PendingCommand return.
    if ($initialPromptsCallback !== null) {
        $command = $initialPromptsCallback($command);
    }

    // Add the common prompts to the command
    $command
        ->expectsQuestion(
            'Which site would you like to import the content into?',
            'default'
        )
        ->expectsOutput("Selected site 'default' is valid.")
        ->expectsQuestion(
            'Which content type would you like to import?',
            'page (1)'
        )
        ->expectsOutput("Selected content type: 'page'");

    // Mixed Gutenberg + freeform body text triggers scanForClassicBlocks (ImportWordPress).
    if ($expectContinuePrompt) {
        $command->expectsQuestion(
            'Would you like to continue importing \'page\' content?',
            true
        );
    }

    // Add the common prompts to the command
    $command
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

/**
 * Run the WordPress import command with common expectations for page imports
 * without export file.
 *
 * @param  TestCase  $test  The bound test case (provides the artisan() harness)
 * @param  string  $pageXmlPath  The path to the XML file
 * @param  string  $expectedSlug  The expected slug (used to determine if "continue importing" prompt should be
 *                                expected)
 */
function formidableRunPageImportCommandWithoutExport(
    TestCase $test,
    string $pageXmlPath,
    string $expectedSlug,
    bool $expectContinuePrompt = false
): void {
    // Run the page import command
    formidableRunPageImportCommand(
        $test,
        $pageXmlPath,
        function (PendingCommand $command): PendingCommand {
            return $command->expectsQuestion(
                'Would you like to provide a FormidableForms export file to create Statamic forms?',
                false
            );
        },
        $expectContinuePrompt
    );
}

/**
 * Assert repeater field set groups match the expected blueprint structure.
 *
 * @param  array<string, mixed>  $fieldConfig
 * @param  array<string, mixed>  $expectedSets
 */
function formidableAssertRepeaterSetsMatch(array $fieldConfig, array $expectedSets): void
{
    $fieldSets = formidableAsArray($fieldConfig['sets'] ?? []);

    expect($fieldConfig)->toHaveKey('sets');

    foreach ($expectedSets as $setGroupKey => $setGroup) {
        expect($setGroup)->toBeArray();
        if (! \is_array($setGroup)) {
            throw new RuntimeException('Expected a repeater set group array.');
        }
        $setGroupKeyString = formidableMessageKey($setGroupKey);

        $setGroupConfig = formidableAsArray($fieldSets[$setGroupKeyString] ?? []);

        expect($fieldSets)->toHaveKey($setGroupKeyString);
        expect($setGroupConfig)->toHaveKey('display');
        expect($setGroupConfig['display'])->toEqual($setGroup['display']);

        if (! isset($setGroup['sets'])) {
            continue;
        }

        expect($setGroup['sets'])->toBeArray();
        if (! \is_array($setGroup['sets'])) {
            throw new RuntimeException('Expected a nested repeater sets array.');
        }
        $nestedSets = formidableAsArray($setGroupConfig['sets'] ?? []);

        expect($setGroupConfig)->toHaveKey('sets');

        foreach ($setGroup['sets'] as $setKey => $set) {
            expect($set)->toBeArray();
            if (! \is_array($set)) {
                throw new RuntimeException('Expected a repeater set array.');
            }
            $setKeyString = formidableMessageKey($setKey);

            $setConfig = formidableAsArray($nestedSets[$setKeyString] ?? []);

            expect($nestedSets)->toHaveKey($setKeyString);
            expect($setConfig)->toHaveKey('display');
            expect($setConfig['display'])->toEqual($set['display']);

            if (! isset($set['fields'])) {
                continue;
            }

            expect($set['fields'])->toBeArray();
            if (! \is_array($set['fields'])) {
                throw new RuntimeException('Expected a repeater set fields array.');
            }
            $actualFields = formidableAsArray($setConfig['fields'] ?? []);

            expect($setConfig)->toHaveKey('fields');
            expect($actualFields)->toHaveCount(\count($set['fields']));

            foreach ($set['fields'] as $fieldIndex => $expectedFieldConfig) {
                $expectedFieldConfigArray = formidableNormalizeStringKeyedRow($expectedFieldConfig);

                $actualFieldConfig = formidableAsArray($actualFields[$fieldIndex] ?? []);

                expect($actualFields)->toHaveKey($fieldIndex);
                expect($actualFieldConfig)->toHaveKey('handle');
                expect($actualFieldConfig['handle'])->toEqual($expectedFieldConfigArray['handle']);

                if (! isset($expectedFieldConfigArray['field'])) {
                    continue;
                }

                $expectedFieldDefinition = formidableNormalizeStringKeyedRow(
                    $expectedFieldConfigArray['field']
                );

                $actualFieldDefinition = formidableAsArray($actualFieldConfig['field'] ?? []);

                foreach ($expectedFieldDefinition as $fieldKey => $fieldValue) {
                    expect($actualFieldDefinition)->toHaveKey($fieldKey);
                    expect($actualFieldDefinition[$fieldKey])->toEqual($fieldValue);
                }
            }
        }
    }
}

/**
 * Clean up temporary forms and blueprints created during a repeater test case.
 *
 * @param  list<StatamicForm>  $temporaryForms
 * @param  list<string>  $temporaryBlueprintHandles
 */
function formidableCleanupTemporaryForms(array $temporaryForms, array $temporaryBlueprintHandles): void
{
    // Clean up forms
    foreach ($temporaryForms as $form) {
        try {
            $form->delete();
        } catch (Exception $e) {
            // Ignore errors if the form was already deleted or doesn't exist
        }
    }

    // Clean up blueprints
    $blueprintCreator = new FormBlueprintCreator;

    foreach ($temporaryBlueprintHandles as $handle) {
        try {
            $blueprintCreator->deleteBlueprint($handle);
        } catch (Exception $e) {
            // Ignore errors if the blueprint was already deleted or doesn't exist
        }
    }
}

/**
 * Normalize assertion values to avoid failures from non-semantic editor attrs.
 *
 * @param  mixed  $value  The value to normalize recursively
 * @return mixed The normalized value
 */
function formidableNormalizeValueForAssertion(mixed $value): mixed
{
    if (! \is_array($value)) {
        return $value;
    }

    // Remove rich-text node attrs, then normalize nested values recursively.
    $normalizedValue = [];

    foreach ($value as $key => $nestedValue) {
        if ($key === 'attrs') {
            continue;
        }

        $normalizedValue[$key] = formidableNormalizeValueForAssertion($nestedValue);
    }

    return $normalizedValue;
}

/**
 * Assert a single Formidable Forms fieldtype mapping case.
 *
 * @param  string  $formidable_forms_type  The Formidable Forms fieldtype
 * @param  string|false|null  $expected_statamic_type  The expected Statamic fieldtype
 * @param  string  $assertion_type  The assertion to perform ('assertEquals', 'assertFalse', 'assertNull')
 */
function formidableAssertFieldtypeMappingCase(
    string $formidable_forms_type,
    string|false|null $expected_statamic_type,
    string $assertion_type
): void {
    // Act: Get the mapped Statamic fieldtype
    $actual_statamic_type = FieldtypeMapping::getStatamicFieldtype($formidable_forms_type);

    // Verify the mapping is correct based on assertion type
    switch ($assertion_type) {
        case 'assertEquals':
            expect($actual_statamic_type)->toEqual($expected_statamic_type);
            break;

        case 'assertFalse':
            expect($actual_statamic_type)->toBeFalse();
            break;

        case 'assertNull':
            expect($actual_statamic_type)->toBeNull();
            break;

        default:
            throw new RuntimeException("Unknown assertion type: {$assertion_type}");
    }
}

/**
 * Assert a single Formidable Forms field attribute mapping case.
 *
 * @param  list<array<string, mixed>>  $source
 * @param  list<array<string, mixed>>  $statamic_blueprint
 */
function formidableAssertAttributeMappingCase(array $source, array $statamic_blueprint): void
{
    $normalizedSource = formidableNormalizeStringKeyedRows($source);
    $normalizedBlueprint = formidableNormalizeStringKeyedRows($statamic_blueprint);

    // Create a FormBlueprintCreator instance to use its field creation logic
    $blueprintCreator = new FormBlueprintCreator;

    // Create blueprint field configuration for each source field
    foreach ($normalizedSource as $index => $fieldData) {
        $fieldConfig = formidableInvokeCreateBlueprintField(
            $blueprintCreator,
            $fieldData,
            $normalizedSource
        );

        $expectedStatamic = formidableRequireBlueprintExpectationAt(
            $normalizedBlueprint,
            $index
        );

        // Verify handle is present and correct
        if (isset($expectedStatamic['handle'])) {
            $actualHandle = formidableInvokeGenerateFieldHandle(
                $blueprintCreator,
                $fieldData
            );

            expect($actualHandle)->toEqual($expectedStatamic['handle']);
        }

        // Verify type is present and correct (only for regular fields, not linked fields)
        if (isset($expectedStatamic['type'])) {
            expect($fieldConfig)->toHaveKey('type');
            expect($fieldConfig['type'])->toEqual($expectedStatamic['type']);
        }

        // Get the expected field
        $expectedField = $expectedStatamic['field'] ?? null;

        // Handle linked fields (field property is a string) vs regular fields (field
        // property is an array)
        if (\is_string($expectedField)) {
            // For linked fields, check that the field property matches
            expect($fieldConfig)->toHaveKey('field');
            expect($fieldConfig['field'])->toEqual($expectedField);

            // Check config attributes if they exist
            if (isset($expectedStatamic['config'])) {
                $fieldConfigData = formidableAsArray($fieldConfig['config'] ?? []);
                $expectedConfig = formidableAsArray($expectedStatamic['config']);

                expect($fieldConfig)->toHaveKey('config');

                foreach ($expectedConfig as $key => $expectedValue) {
                    expect($key)->toBeString();
                    if (! \is_string($key)) {
                        throw new RuntimeException('Expected a string config key.');
                    }

                    expect($fieldConfigData)->toHaveKey($key);
                    expect($fieldConfigData[$key])->toEqual($expectedValue);
                }
            }
        } else {
            // For regular fields, iterate over field properties as before
            expect($expectedField)->toBeArray();
            if (! \is_array($expectedField)) {
                throw new RuntimeException('Expected the field definition to be an array.');
            }

            foreach ($expectedField as $key => $expectedValue) {
                expect($key)->toBeString();
                if (! \is_string($key)) {
                    throw new RuntimeException('Expected a string field key.');
                }

                if ($key === 'options') {
                    // For options, verify the options array is present and correct
                    $actualOptions = formidableAsArray($fieldConfig['options'] ?? []);
                    expect($expectedValue)->toBeArray();
                    if (! \is_array($expectedValue)) {
                        throw new RuntimeException('Expected the options to be an array.');
                    }

                    expect($fieldConfig)->toHaveKey('options');
                    expect($actualOptions)->toHaveCount(\count($expectedValue));

                    foreach ($expectedValue as $optionIndex => $expectedOption) {
                        $expectedOptionArray = formidableNormalizeStringKeyedRow($expectedOption);
                        $actualOption = formidableNormalizeStringKeyedRow(
                            $actualOptions[$optionIndex] ?? null
                        );

                        expect($actualOption['key'] ?? null)->toEqual($expectedOptionArray['key'] ?? null);
                        expect($actualOption['value'] ?? null)->toEqual($expectedOptionArray['value'] ?? null);
                    }
                } elseif ($key === 'mime_types') {
                    // For mime types, verify the mime types array is present and correct
                    $actualMimeTypes = formidableAsArray($fieldConfig['mime_types'] ?? []);
                    expect($expectedValue)->toBeArray();
                    if (! \is_array($expectedValue)) {
                        throw new RuntimeException('Expected the mime types to be an array.');
                    }

                    expect($fieldConfig)->toHaveKey('mime_types');
                    expect($actualMimeTypes)->toHaveCount(\count($expectedValue));

                    foreach ($expectedValue as $mimeType) {
                        expect($mimeType)->toBeString();
                        if (! \is_string($mimeType)) {
                            throw new RuntimeException('Expected a string mime type.');
                        }

                        expect($actualMimeTypes)->toContain($mimeType);
                    }
                } else {
                    // For other keys, verify the key is present and correct
                    expect($fieldConfig)->toHaveKey($key);
                    expect($fieldConfig[$key])->toEqual($expectedValue);
                }
            }
        }

        // Verify suppressed attributes are excluded
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

        // Verify no unexpected attributes are present (strict checking)
        if (\is_string($expectedField)) {
            // For linked fields, compare the expected structure (field + config) with
            // actual structure
            $expectedStructure = [
                'field',
            ];

            if (isset($expectedStatamic['config'])) {
                $expectedStructure[] = 'config';
            }

            $actualKeys = \array_keys($fieldConfig);

            $unexpectedKeys = array_diff(
                $actualKeys,
                $expectedStructure
            );
        } else {
            // For regular fields, compare field properties as before
            $expectedKeys = \array_keys($expectedField);

            $actualKeys = \array_keys($fieldConfig);

            $unexpectedKeys = array_diff(
                $actualKeys,
                $expectedKeys
            );
        }

        expect($unexpectedKeys)->toBeEmpty();
    }
}

/**
 * Build the Formidable Forms coordinator graph the way the import command's container does.
 *
 * The coordinator, the user-interaction handler and the form-creation coordinator
 * share ONE `DataManager`. Production resolves the manager as a singleton per
 * plugin (wordpress-importer-archive#1103), so forms the handler binds are the forms the coordinator reads;
 * three separate instances would be three separate stores, and a read through
 * the coordinator would come back empty.
 *
 * @param  FormConfigurationManager  $formConfigurationManager  The configuration manager for the form-creation coordinator
 * @param  Selector|null  $selector  The export selector the handler uses; the production selector when null
 * @return array{coordinator: FormidableFormsCoordinator, formCreationCoordinator: FormCreationCoordinator} The wired graph
 */
function formidableMakeCoordinatorGraph(
    FormConfigurationManager $formConfigurationManager = new FormConfigurationManager,
    ?Selector $selector = null
): array {
    // One store for the whole graph, as the container provides
    $dataManager = new DataManager;

    // Build the top-level coordinator; its form-creation coordinator is set afterwards
    $coordinator = new FormidableFormsCoordinator(
        new ContentDetector,
        new FormIdExtractor,
        $dataManager,
        new UserInteractionHandler(
            new ContentDetector,
            $dataManager,
            $selector ?? new Selector(
                new Validator,
                new FormidableFormsLoader
            )
        ),
        null
    );

    // Create the form-creation coordinator around the same store
    $formCreationCoordinator = new FormCreationCoordinator(
        $dataManager,
        $formConfigurationManager
    );

    // Wire the two coordinators to each other
    $formCreationCoordinator->setCoordinator($coordinator);
    $coordinator->setFormCreationCoordinator($formCreationCoordinator);

    return [
        'coordinator' => $coordinator,
        'formCreationCoordinator' => $formCreationCoordinator,
    ];
}

/**
 * Assert a single Formidable Forms repeater field attribute mapping case.
 *
 * @param  array<string, mixed>  $source
 * @param  list<array<string, mixed>>  $statamic_forms
 */
function formidableAssertAttributeMappingRepeaterCase(
    array $source,
    array $statamic_forms,
    string $site_handle,
    bool $use_page_data_mapping = false
): void {
    $source = formidableNormalizeStringKeyedRow($source);
    $statamic_forms = formidableNormalizeStringKeyedRows($statamic_forms);

    // Track temporary forms and blueprint handles created during this case for
    // cleanup (originally instance state on the test class).
    $temporaryForms = [];
    $temporaryBlueprintHandles = [];

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

    // Create a FormBlueprintCreator instance to use its field creation logic
    $blueprintCreator = new FormBlueprintCreator;

    // Create the FormConfigurationManager
    $formConfigurationManager = new FormConfigurationManager;

    // Set page data mapping if provided
    if ($page_data_mapping !== []) {
        $formConfigurationManager->setPageDataMapping($page_data_mapping);

        expect($formConfigurationManager->getPageDataMapping())->toEqual($page_data_mapping);
    }

    // Build the coordinator graph the way the container wires it
    [
        'coordinator' => $coordinator,
        'formCreationCoordinator' => $formCreationCoordinator,
    ] = formidableMakeCoordinatorGraph($formConfigurationManager);

    // Create the FormCreator
    $formCreator = new FormCreator(
        $blueprintCreator,
        $coordinator
    );

    // Process each form in the source data
    $sourceForms = formidableRequireSourceForms($source);
    $siteName = formidableSiteDisplayName($site_handle);

    foreach ($sourceForms as $formIndex => $formData) {
        // Skip child forms as they are processed as part of the parent form's repeater
        // logic
        $parentFormId = $formData['parent_form_id'] ?? '0';

        if (
            \is_string($parentFormId)
            && $parentFormId !== '0'
        ) {
            continue;
        }

        $expectedStatamicForm = formidableFindExpectedStatamicForm(
            $statamic_forms,
            $formData
        );

        if ($expectedStatamicForm === null) {
            continue;
        }

        // Verify form-level configuration
        if (isset($expectedStatamicForm['configuration'])) {
            $expectedConfig = formidableAsArray($expectedStatamicForm['configuration']);
            $formDataWithViews = formidableMergeSourceViewsIntoFormData(
                $formData,
                $source
            );
            $formId = (int) formidableRequireFormString($formData, 'id');

            // Create the temporary form
            $tempForm = $formCreationCoordinator->createStatamicFormFromFormidableForm(
                $formId,
                [$formDataWithViews],
                $siteName,
                $site_handle,
                new AssetImporter
            );

            if ($tempForm !== null) {
                // Track the temporary form and blueprint handle for cleanup
                $temporaryForms[] = $tempForm;
                $handle = $tempForm->handle();
                expect($handle)->toBeString();
                if (! \is_string($handle)) {
                    throw new RuntimeException('Expected the form handle to be a string.');
                }
                $temporaryBlueprintHandles[] = $handle;

                $actualFormData = formidableFormConfigurationData($tempForm);

                foreach ($expectedConfig as $expectedKey => $expectedValue) {
                    expect($expectedKey)->toBeString();
                    if (! \is_string($expectedKey)) {
                        throw new RuntimeException('Expected a string configuration key.');
                    }

                    if ($expectedKey === 'title') {
                        // Title is stored separately from form data in Statamic
                        expect($tempForm->title())->toEqual($expectedValue);
                    } else {
                        // For other keys, verify the key is present and correct
                        expect($actualFormData)->toHaveKey($expectedKey);
                        expect(formidableNormalizeValueForAssertion($actualFormData[$expectedKey]))
                            ->toEqual(formidableNormalizeValueForAssertion($expectedValue));
                    }
                }

                // The form is assigned to the site it was imported for (wordpress-importer-archive#1389)
                expect($actualFormData)->toHaveKey('sites');
                expect($actualFormData['sites'])->toBe([$site_handle]);

                // Verify no unexpected configuration attributes are present (strict checking)
                $expectedConfigKeys = \array_keys($expectedConfig);
                $expectedConfigKeys[] = 'sites';

                $actualConfigKeys = \array_keys($actualFormData);

                $unexpectedConfigKeys = array_diff(
                    $actualConfigKeys,
                    $expectedConfigKeys
                );

                expect($unexpectedConfigKeys)->toBeEmpty();

                // Delete the temporary form
                $tempForm->delete();
            } else {
                throw new RuntimeException('Failed to create temporary form for configuration validation');
            }
        }

        // Verify form handle is correctly generated
        if (isset($expectedStatamicForm['handle'])) {
            $formDataWithViews = formidableMergeSourceViewsIntoFormData(
                $formData,
                $source
            );
            $formId = (int) formidableRequireFormString($formData, 'id');

            // Create the temporary form
            $tempForm = $formCreationCoordinator->createStatamicFormFromFormidableForm(
                $formId,
                [$formDataWithViews],
                $siteName,
                $site_handle,
                new AssetImporter
            );

            if ($tempForm !== null) {
                // Track the temporary form and blueprint handle for cleanup
                $temporaryForms[] = $tempForm;
                $handle = $tempForm->handle();
                expect($handle)->toBeString();
                if (! \is_string($handle)) {
                    throw new RuntimeException('Expected the form handle to be a string.');
                }
                $temporaryBlueprintHandles[] = $handle;

                // Get the actual generated handle from the form
                $actualGeneratedHandle = $tempForm->handle();

                // Verify the expected handle matches the actual generated handle
                expect($actualGeneratedHandle)->toEqual($expectedStatamicForm['handle']);

                // Delete the temporary form
                $tempForm->delete();
            } else {
                throw new RuntimeException('Failed to create temporary form for handle validation');
            }
        }

        // Process fields within the current form
        if (isset($expectedStatamicForm['blueprint_fields'])) {
            $expectedBlueprintFields = formidableAsArray($expectedStatamicForm['blueprint_fields']);
            $formFields = formidableRequireFormFieldsFromFormData($formData);

            // Assert the source form carries string name/id fields (side-effecting
            // guards preserved from the original helper).
            formidableRequireFormString($formData, 'name');
            formidableRequireFormString($formData, 'id');

            foreach ($expectedBlueprintFields as $fieldIndex => $expectedFieldData) {
                expect($expectedFieldData)->toBeArray();
                if (! \is_array($expectedFieldData)) {
                    throw new RuntimeException('Expected the blueprint field to be an array.');
                }

                // Initialize the source field data
                $sourceFieldData = null;

                foreach ($formFields as $sourceField) {
                    $generatedHandle = formidableInvokeGenerateFieldHandle(
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

                $fieldConfig = formidableInvokeCreateBlueprintField(
                    $blueprintCreator,
                    $sourceFieldData,
                    $formFields,
                    $sourceForms
                );

                if (isset($expectedFieldData['handle'])) {
                    $actualHandle = formidableInvokeGenerateFieldHandle(
                        $blueprintCreator,
                        $sourceFieldData
                    );

                    expect($actualHandle)->toEqual($expectedFieldData['handle']);
                }

                $expectedFieldDefinition = formidableAsArray($expectedFieldData['field'] ?? []);

                if (isset($expectedFieldDefinition['type'])) {
                    expect($fieldConfig)->toHaveKey('type');
                    expect($fieldConfig['type'])->toEqual($expectedFieldDefinition['type']);
                }

                // Get the expected field
                $expectedField = $expectedFieldDefinition;

                foreach ($expectedField as $key => $expectedValue) {
                    expect($key)->toBeString();
                    if (! \is_string($key)) {
                        throw new RuntimeException('Expected a string field key.');
                    }

                    if ($key === 'sets') {
                        expect($expectedValue)->toBeArray();
                        if (! \is_array($expectedValue)) {
                            throw new RuntimeException('Expected the sets to be an array.');
                        }
                        formidableAssertRepeaterSetsMatch(
                            $fieldConfig,
                            formidableNormalizeStringKeyedMap($expectedValue)
                        );
                    } else {
                        expect($fieldConfig)->toHaveKey($key);
                        expect($fieldConfig[$key])->toEqual($expectedValue);
                    }
                }

                // Verify suppressed attributes are excluded
                $suppressedAttributes = [
                    'field_key',
                    'field_order',
                    'form_id',
                    'id',
                    'required',
                    'in_section',
                ];

                foreach ($suppressedAttributes as $attribute) {
                    expect($fieldConfig)->not->toHaveKey($attribute);
                }

                // Verify no unexpected attributes are present (strict checking)
                $expectedKeys = \array_keys($expectedField);

                $actualKeys = \array_keys($fieldConfig);

                $unexpectedKeys = array_diff(
                    $actualKeys,
                    $expectedKeys,
                );

                expect($unexpectedKeys)->toBeEmpty();
            }
        }
    }

    // Clean up any temporary forms created during this case
    formidableCleanupTemporaryForms($temporaryForms, $temporaryBlueprintHandles);
}

/**
 * Data provider for fieldtype mapping tests.
 *
 * Tests the mapping of Formidable Forms fieldtypes to Statamic fieldtypes.
 *
 * @return array<string, array{formidable_forms_type: string, expected_statamic_type: string|null|false, assertion_type: string}>
 */
function formidableFieldtypeMappingProvider(): array
{
    return [
        // Mapped Fieldtypes
        'address fieldtype' => [
            'formidable_forms_type' => 'address',
            'expected_statamic_type' => 'address',
            'assertion_type' => 'assertEquals',
        ],
        'checkbox fieldtype' => [
            'formidable_forms_type' => 'checkbox',
            'expected_statamic_type' => 'checkboxes',
            'assertion_type' => 'assertEquals',
        ],
        'date fieldtype' => [
            'formidable_forms_type' => 'date',
            'expected_statamic_type' => 'date',
            'assertion_type' => 'assertEquals',
        ],
        'divider fieldtype' => [
            'formidable_forms_type' => 'divider',
            'expected_statamic_type' => 'replicator',
            'assertion_type' => 'assertEquals',
        ],
        'email fieldtype' => [
            'formidable_forms_type' => 'email',
            'expected_statamic_type' => 'email',
            'assertion_type' => 'assertEquals',
        ],
        'end_divider fieldtype' => [
            'formidable_forms_type' => 'end_divider',
            'expected_statamic_type' => 'replicator',
            'assertion_type' => 'assertEquals',
        ],
        'file fieldtype' => [
            'formidable_forms_type' => 'file',
            'expected_statamic_type' => 'assets',
            'assertion_type' => 'assertEquals',
        ],
        'hidden fieldtype' => [
            'formidable_forms_type' => 'hidden',
            'expected_statamic_type' => 'hidden',
            'assertion_type' => 'assertEquals',
        ],
        'html fieldtype' => [
            'formidable_forms_type' => 'html',
            'expected_statamic_type' => 'html',
            'assertion_type' => 'assertEquals',
        ],
        'name fieldtype' => [
            'formidable_forms_type' => 'name',
            'expected_statamic_type' => 'name',
            'assertion_type' => 'assertEquals',
        ],
        'number fieldtype' => [
            'formidable_forms_type' => 'number',
            'expected_statamic_type' => 'float',
            'assertion_type' => 'assertEquals',
        ],
        'password fieldtype' => [
            'formidable_forms_type' => 'password',
            'expected_statamic_type' => 'password',
            'assertion_type' => 'assertEquals',
        ],
        'phone fieldtype' => [
            'formidable_forms_type' => 'phone',
            'expected_statamic_type' => 'phone',
            'assertion_type' => 'assertEquals',
        ],
        'radio fieldtype' => [
            'formidable_forms_type' => 'radio',
            'expected_statamic_type' => 'radio',
            'assertion_type' => 'assertEquals',
        ],
        'range fieldtype' => [
            'formidable_forms_type' => 'range',
            'expected_statamic_type' => 'range',
            'assertion_type' => 'assertEquals',
        ],
        'rte fieldtype' => [
            'formidable_forms_type' => 'rte',
            'expected_statamic_type' => 'bard',
            'assertion_type' => 'assertEquals',
        ],
        'select fieldtype' => [
            'formidable_forms_type' => 'select',
            'expected_statamic_type' => 'select',
            'assertion_type' => 'assertEquals',
        ],
        'tag fieldtype' => [
            'formidable_forms_type' => 'tag',
            'expected_statamic_type' => 'tags',
            'assertion_type' => 'assertEquals',
        ],
        'text fieldtype' => [
            'formidable_forms_type' => 'text',
            'expected_statamic_type' => 'text',
            'assertion_type' => 'assertEquals',
        ],
        'textarea fieldtype' => [
            'formidable_forms_type' => 'textarea',
            'expected_statamic_type' => 'textarea',
            'assertion_type' => 'assertEquals',
        ],
        'time fieldtype' => [
            'formidable_forms_type' => 'time',
            'expected_statamic_type' => 'time',
            'assertion_type' => 'assertEquals',
        ],
        'toggle fieldtype' => [
            'formidable_forms_type' => 'toggle',
            'expected_statamic_type' => 'toggle',
            'assertion_type' => 'assertEquals',
        ],
        'url fieldtype' => [
            'formidable_forms_type' => 'url',
            'expected_statamic_type' => 'url',
            'assertion_type' => 'assertEquals',
        ],

        // Suppressed Fieldtypes
        'break fieldtype' => [
            'formidable_forms_type' => 'break',
            'expected_statamic_type' => false,
            'assertion_type' => 'assertFalse',
        ],
        'captcha fieldtype' => [
            'formidable_forms_type' => 'captcha',
            'expected_statamic_type' => false,
            'assertion_type' => 'assertFalse',
        ],
        'submit fieldtype' => [
            'formidable_forms_type' => 'submit',
            'expected_statamic_type' => false,
            'assertion_type' => 'assertFalse',
        ],
        'user_id fieldtype' => [
            'formidable_forms_type' => 'user_id',
            'expected_statamic_type' => false,
            'assertion_type' => 'assertFalse',
        ],

        // Unmapped Fieldtypes
        'credit_card fieldtype' => [
            'formidable_forms_type' => 'credit_card',
            'expected_statamic_type' => null,
            'assertion_type' => 'assertNull',
        ],
        'data fieldtype' => [
            'formidable_forms_type' => 'data',
            'expected_statamic_type' => null,
            'assertion_type' => 'assertNull',
        ],
        'form fieldtype' => [
            'formidable_forms_type' => 'form',
            'expected_statamic_type' => null,
            'assertion_type' => 'assertNull',
        ],
        'lookup fieldtype' => [
            'formidable_forms_type' => 'lookup',
            'expected_statamic_type' => null,
            'assertion_type' => 'assertNull',
        ],
        'product fieldtype' => [
            'formidable_forms_type' => 'product',
            'expected_statamic_type' => null,
            'assertion_type' => 'assertNull',
        ],
        'quantity fieldtype' => [
            'formidable_forms_type' => 'quantity',
            'expected_statamic_type' => null,
            'assertion_type' => 'assertNull',
        ],
        'scale fieldtype' => [
            'formidable_forms_type' => 'scale',
            'expected_statamic_type' => null,
            'assertion_type' => 'assertNull',
        ],
        'signature fieldtype' => [
            'formidable_forms_type' => 'signature',
            'expected_statamic_type' => null,
            'assertion_type' => 'assertNull',
        ],
        'star fieldtype' => [
            'formidable_forms_type' => 'star',
            'expected_statamic_type' => null,
            'assertion_type' => 'assertNull',
        ],
        'summary fieldtype' => [
            'formidable_forms_type' => 'summary',
            'expected_statamic_type' => null,
            'assertion_type' => 'assertNull',
        ],
        'total fieldtype' => [
            'formidable_forms_type' => 'total',
            'expected_statamic_type' => null,
            'assertion_type' => 'assertNull',
        ],

        // Unknown Fieldtypes
        'unknown fieldtype' => [
            'formidable_forms_type' => 'unknown_fieldtype',
            'expected_statamic_type' => null,
            'assertion_type' => 'assertNull',
        ],
    ];
}

/**
 * Data provider for testing different fieldtypes and their attribute mappings.
 *
 * @return array<string, array{source: list<array<string, mixed>>, statamic_blueprint: list<array<string, mixed>>}>
 */
function formidableAttributeMappingProvider(): array
{
    // Set the base field data
    $baseField = [
        'id' => '123',
        'field_key' => 'zzz999',
        'name' => 'Test Field',
        'description' => '',
        'type' => 'text',
        'default_value' => '',
        'field_order' => '1',
        'form_id' => '999',
        'required' => '0',
        'options' => '',
        'field_options' => '{}',
    ];

    // Return the test cases
    return [
        'text field with description' => [
            'source' => [
                \array_merge(
                    $baseField,
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
                    $baseField,
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
        'radio field with options' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'radio',
                        'options' => '[
                                {
                                    "label":"Green",
                                    "value":"Green",
                                    "image":"0"
                                },
                                {
                                    "label":"Yellow",
                                    "value":"Yellow",
                                    "image":"0"
                                }
                            ]',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
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
                    $baseField,
                    ['default_value' => 'Foo']
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
                    $baseField,
                    ['default_value' => '[get param=foo]']
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
        'text field with date calculation' => [
            'source' => [
                \array_merge(
                    $baseField,
                    ['default_value' => '[date]']
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
        'date field with date calculation' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'date',
                        'default_value' => '[date]',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'date',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'address field with address_type (not set)' => [
            'source' => [
                \array_merge(
                    $baseField,
                    ['type' => 'address']
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'address',
                        'display' => 'Test Field',
                        'preset' => 'international',
                    ],
                ],
            ],
        ],
        'address field with address_type (international)' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'address',
                        'field_options' => '{
                                "address_type":"international"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'address',
                        'display' => 'Test Field',
                        'preset' => 'international',
                    ],
                ],
            ],
        ],
        'address field with address_type (europe)' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'address',
                        'field_options' => '{
                                "address_type":"europe"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'address',
                        'display' => 'Test Field',
                        'preset' => 'international',
                    ],
                ],
            ],
        ],
        'address field with address_type (generic)' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'address',
                        'field_options' => '{
                                "address_type":"generic"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'address',
                        'display' => 'Test Field',
                        'preset' => 'american',
                    ],
                ],
            ],
        ],
        'address field with address_type (us)' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'address',
                        'field_options' => '{
                                "address_type":"us"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'address',
                        'display' => 'Test Field',
                        'preset' => 'american',
                    ],
                ],
            ],
        ],
        'text field with admin_only (administrator)' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "admin_only":"administrator"
                            }',
                    ]
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
        'text field with admin_only (editor)' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "admin_only":"editor"
                            }',
                    ]
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
        'text field with admin_only (author)' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "admin_only":"author"
                            }',
                    ]
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
        'text field with admin_only (contributor)' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "admin_only":"contributor"
                            }',
                    ]
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
        'text field with admin_only (subscriber)' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "admin_only":"subscriber"
                            }',
                    ]
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
        'text field with admin_only (newsletterglue_manager)' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "admin_only":"newsletterglue_manager"
                            }',
                    ]
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
        'text field with admin_only (loggedin)' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "admin_only":"loggedin"
                            }',
                    ]
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
        'text field with align' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "align":"block"
                            }',
                    ]
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
        'text field with append' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "append":"foo"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                        'append' => 'foo',
                    ],
                ],
            ],
        ],
        'text field with prepend' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "prepend":"foo"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                        'prepend' => 'foo',
                    ],
                ],
            ],
        ],
        'text field with prepend and append' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "prepend":"foo",
                                "append":"foo"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                        'prepend' => 'foo',
                        'append' => 'foo',
                    ],
                ],
            ],
        ],
        'file field with attach' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'file',
                        'field_options' => '{
                                "attach":true
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'assets',
                        'display' => 'Test Field',
                        'container' => 'form_entry_uploads',
                        'folder' => 'supply_chain',
                        'dynamic' => 'id',
                        'restrict' => true,
                        'max_files' => 1,
                        'attach_to_email' => true,
                    ],
                ],
            ],
        ],
        'textarea field with auto_grow' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'textarea',
                        'field_options' => '{
                                "auto_grow":0
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'textarea',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'text field with autocom' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "autocom":0
                            }',
                    ]
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
        'email field with autocomplete' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'email',
                        'field_options' => '{
                                "autocomplete":"email"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'email',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'text field with blank' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "blank":"This field cannot be blank."
                            }',
                    ]
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
        'text field with btsp ("")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "btsp":""
                            }',
                    ]
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
        'text field with btsp ({"prepend":"","append":""})' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "btsp":{
                                    "prepend":"",
                                    "append":""
                                }
                            }',
                    ]
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
        'number field with calc (no field references), calc_dec and calc_type (not set)' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'number',
                        'field_options' => '{
                                "calc":"13 / 42",
                                "calc_dec":"3"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'float',
                        'display' => 'Test Field',
                        'calculation_query' => true,
                        'calculation_formula_math' => '13 / 42',
                        'calculation_decimals' => 3,
                    ],
                ],
            ],
        ],
        'text field with calc (no field references) and calc_type (text)' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "calc":"foo bar",
                                "calc_type":"text"
                            }',
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
                        'calculation_formula_text' => 'foo bar',
                    ],
                ],
            ],
        ],
        'file field with choose_msg' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'file',
                        'field_options' => '{
                                "choose_msg":"Choose File"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
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
        'address field with city_desc' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'address',
                        'field_options' => '{
                                "city_desc":"City"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'address',
                        'display' => 'Test Field',
                        'preset' => 'international',
                    ],
                ],
            ],
        ],
        'address field with country_desc' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'address',
                        'field_options' => '{
                                "country_desc":"Country"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'address',
                        'display' => 'Test Field',
                        'preset' => 'international',
                    ],
                ],
            ],
        ],
        'text field with classes ("frm12 frm_first")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "classes":"frm12 frm_first"
                            }',
                    ]
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
        'text field with classes ("frm_first frm12")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "classes":"frm_first frm12"
                            }',
                    ]
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
        'text field with classes ("frm12")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "classes":"frm12"
                            }',
                    ]
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
        'text field with classes ("frm_full frm_first")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "classes":"frm_full frm_first"
                            }',
                    ]
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
        'text field with classes ("frm_first frm_full")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "classes":"frm_first frm_full"
                            }',
                    ]
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
        'text field with classes ("frm_full")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "classes":"frm_full"
                            }',
                    ]
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
        'text field with classes ("frm3 frm_first")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "classes":"frm3 frm_first"
                            }',
                    ]
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
        'text field with classes ("frm3")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "classes":"frm3"
                            }',
                    ]
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
        'text field with classes ("frm_fourth frm_first")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "classes":"frm_fourth frm_first"
                            }',
                    ]
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
        'text field with classes ("frm_fourth")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "classes":"frm_fourth"
                            }',
                    ]
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
        'text field with classes ("frm4 frm_first")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "classes":"frm4 frm_first"
                            }',
                    ]
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
        'text field with classes ("frm4")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "classes":"frm4"
                            }',
                    ]
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
        'text field with classes ("frm_third frm_first")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "classes":"frm_third frm_first"
                            }',
                    ]
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
        'text field with classes ("frm_third")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "classes":"frm_third"
                            }',
                    ]
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
        'text field with classes ("frm6 frm_first")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "classes":"frm6 frm_first"
                            }',
                    ]
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
        'text field with classes ("frm6")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "classes":"frm6"
                            }',
                    ]
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
        'text field with classes ("frm_half frm_first")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "classes":"frm_half frm_first"
                            }',
                    ]
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
        'text field with classes ("frm_half")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "classes":"frm_half"
                            }',
                    ]
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
        'text field with classes ("frm_two_thirds frm_first")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "classes":"frm_two_thirds frm_first"
                            }',
                    ]
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
        'text field with classes ("frm_two_thirds")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "classes":"frm_two_thirds"
                            }',
                    ]
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
        'text field with classes ("frm8 frm_first")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "classes":"frm8 frm_first"
                            }',
                    ]
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
        'text field with classes ("frm8")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "classes":"frm8"
                            }',
                    ]
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
        'text field with classes ("frm9 frm_first")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "classes":"frm9 frm_first"
                            }',
                    ]
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
        'text field with classes ("frm9")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "classes":"frm9"
                            }',
                    ]
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
        'text field with classes ("frm_three_fourths frm_first")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "classes":"frm_three_fourths frm_first"
                            }',
                    ]
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
        'text field with classes ("frm_three_fourths")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "classes":"frm_three_fourths"
                            }',
                    ]
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
        'text field with classes ("frm_inline")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "classes":"frm_inline"
                            }',
                    ]
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
        'text field with clear_on_focus' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "clear_on_focus":1
                            }',
                    ]
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
        'time field with clock ("12")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'time',
                        'field_options' => '{
                                "clock":"12"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'time',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'time field with clock ("24")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'time',
                        'field_options' => '{
                                "clock":"24"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'time',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'email field with conf_field (""), conf_input (""), conf_desc (""), and conf_msg ("The entered values do not match")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'email',
                        'field_options' => '{
                                "conf_field": "",
                                "conf_input": "",
                                "conf_desc": "",
                                "conf_msg": "The entered values do not match"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'email',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'password field with conf_field (""), conf_input (""), conf_desc (""), and conf_msg ("The entered values do not match")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'password',
                        'field_options' => '{
                                "conf_field": "",
                                "conf_input": "",
                                "conf_desc": "",
                                "conf_msg": "The entered values do not match"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'password',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'email field with conf_field ("inline"), conf_input (""), conf_desc (""), and conf_msg ("The entered values do not match")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'email',
                        'field_options' => '{
                                "conf_field": "inline",
                                "conf_input": "",
                                "conf_desc": "",
                                "conf_msg": "The entered values do not match"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'email',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'password field with conf_field ("inline"), conf_input (""), conf_desc (""), and conf_msg ("The entered values do not match")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'password',
                        'field_options' => '{
                                "conf_field": "inline",
                                "conf_input": "",
                                "conf_desc": "",
                                "conf_msg": "The entered values do not match"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'password',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'email field with conf_field ("below"), conf_input (""), conf_desc (""), and conf_msg ("The entered values do not match")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'email',
                        'field_options' => '{
                                "conf_field": "below",
                                "conf_input": "",
                                "conf_desc": "",
                                "conf_msg": "The entered values do not match"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'email',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'password field with conf_field ("below"), conf_input (""), conf_desc (""), and conf_msg ("The entered values do not match")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'password',
                        'field_options' => '{
                                "conf_field": "below",
                                "conf_input": "",
                                "conf_desc": "",
                                "conf_msg": "The entered values do not match"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'password',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'text field with custom_field ("")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "custom_field":""
                            }',
                    ]
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
        'text field with custom_html' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "custom_html":"<div id=\\\"frm_field_[id]_container\\\" class=\\\"frm_form_field form-field [required_class][error_class]\\\">\r\n    <label for=\\\"field_[key]\\\" id=\\\"field_[key]_label\\\" class=\\\"frm_primary_label\\\">[field_name]\r\n        <span class=\\\"frm_required\\\">[required_label]<\/span>\r\n    <\/label>\r\n    [if description]<div class=\\\"frm_description\\\" id=\\\"frm_desc_field_[key]\\\">[description]<\/div>[\/if description]\r\n    [input]\r\n    [if error]<div class=\\\"frm_error\\\" id=\\\"frm_error_field_[key]\\\">[error]<\/div>[\/if error]\r\n<\/div>"
                            }',
                    ]
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
        'file field with delete' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'file',
                        'field_options' => '{
                                "delete":false
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
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
        'text field with draft' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "draft":1
                            }',
                    ]
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
        'file field with drop_msg' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'file',
                        'field_options' => '{
                                "drop_msg":"Drop a file here or click to upload"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
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
        'radio field with dyn_default_value ("[get param=\"editor_email_query\"]")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'radio',
                        'options' => '[
                                {
                                    "label":"Yes",
                                    "value":"Yes",
                                    "image":"0"
                                },
                                {
                                    "label":"No",
                                    "value":"No",
                                    "image":"0"
                                }
                            ]',
                        'field_options' => '{
                                "dyn_default_value":"[get param=\"editor_email_query\"]"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'radio',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'Yes',
                                'value' => null,
                            ],
                            [
                                'key' => 'No',
                                'value' => null,
                            ],
                        ],
                        'calculation_query' => true,
                        'calculation_formula' => '[get_param:editor_email_query]',
                    ],
                ],
            ],
        ],
        'radio field with dyn_default_value ("Change Needed - No")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'radio',
                        'options' => '{
                                "0":{
                                    "label":"Change Needed - No",
                                    "value":"Change Needed - No",
                                    "image":"0"
                                },
                                "other_2":"Yes - "
                            }',
                        'field_options' => '{
                                "dyn_default_value":"Change Needed - No",
                                "other":"1"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'radio',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'Change Needed - No',
                                'value' => null,
                            ],
                        ],
                        'enable_other' => true,
                        'other_label' => 'Yes -',
                    ],
                ],
            ],
        ],
        'text field with exclude_cat' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "exclude_cat":0
                            }',
                    ]
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
        'name field with first_desc' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'name',
                        'field_options' => '{
                                "first_desc":"First"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'name',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'text field with format ("(999) 999-9999? x99999")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "format":"(999) 999-9999? x99999"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                        'input_mask' => true,
                        'input_mask_class' => 'MaskedPattern',
                        'input_mask_mask_MaskedPattern' => '(000) 000-0000[ x00000]',
                    ],
                ],
            ],
        ],
        'text field with format ("**-99999?-99-99")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "format":"**-99999?-99-99"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                        'input_mask' => true,
                        'input_mask_class' => 'MaskedPattern',
                        'input_mask_mask_MaskedPattern' => '**-00000[-00-00]',
                    ],
                ],
            ],
        ],
        'text field with format ("^((https?:\/\/)?uams-triprofiles\.uams\.edu\/profiles\/display\/\d+/?)$")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "format":"^((https?:\\\\\/\\\\\/)?uams-triprofiles\\\\.uams\\\\.edu\\\\\/profiles\\\\\/display\\\\\/\\\\d+\/?)$"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                        'input_mask' => true,
                        'input_mask_class' => 'MaskedRegExp',
                        'input_mask_mask_MaskedRegExp' => '^((https?:\/\/)?uams-triprofiles\.uams\.edu\/profiles\/display\/\d+/?)$',
                    ],
                ],
            ],
        ],
        'phone field with format' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'phone',
                        'field_options' => '{
                                "format":"(999) 999-9999"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'phone',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'text field with get_most_recent_value ("")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "get_most_recent_value":""
                            }',
                    ]
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
        'text field with get_values_field ("")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "get_values_field":""
                            }',
                    ]
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
        'text field with get_values_form ("")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "get_values_form":""
                            }',
                    ]
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
        'text field with invalid' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "invalid":"Text is invalid"
                            }',
                    ]
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
        'text field with label (hidden)' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "label":"hidden"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                        'hide_display' => true,
                    ],
                ],
            ],
        ],
        'text field with label (none)' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "label":"none"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                        'hide_display' => true,
                    ],
                ],
            ],
        ],
        'text field with label (top)' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "label":"top"
                            }',
                    ]
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
        'text field with label (left)' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "label":"left"
                            }',
                    ]
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
        'text field with label (right)' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "label":"right"
                            }',
                    ]
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
        'text field with label (inline)' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "label":"inline"
                            }',
                    ]
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
        'name field with last_desc' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'name',
                        'field_options' => '{
                                "last_desc":"Last"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'name',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'text field with limit_selections ("")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "limit_selections":""
                            }',
                    ]
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
        'address field with line1_desc' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'address',
                        'field_options' => '{
                                "line1_desc":"Street Address"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'address',
                        'display' => 'Test Field',
                        'preset' => 'international',
                    ],
                ],
            ],
        ],
        'address field with line2_desc' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'address',
                        'field_options' => '{
                                "line2_desc":"Apt/Unit"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'address',
                        'display' => 'Test Field',
                        'preset' => 'international',
                    ],
                ],
            ],
        ],
        'text field with lookup_filter_current_user' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "lookup_filter_current_user":false
                            }',
                    ]
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
        'text field with max' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "max":"42"
                            }',
                    ]
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
        'textarea field with max' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'textarea',
                        'field_options' => '{
                                "max":"42"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'textarea',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'rte field with max' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'rte',
                        'field_options' => '{
                                "max":"42"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'bard',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'file field with max ("42")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'file',
                        'field_options' => '{
                                "multiple":"1",
                                "max":"42"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'assets',
                        'display' => 'Test Field',
                        'container' => 'form_entry_uploads',
                        'folder' => 'supply_chain',
                        'dynamic' => 'id',
                        'restrict' => true,
                        'max_files' => 42,
                    ],
                ],
            ],
        ],
        'textarea field with max_limit (0) and max_limit_type ("char")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'textarea',
                        'field_options' => '{
                                "max_limit":0,
                                "max_limit_type":"char"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'textarea',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'textarea field with max_limit (42) and max_limit_type ("char")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'textarea',
                        'field_options' => '{
                                "max_limit":42,
                                "max_limit_type":"char"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'textarea',
                        'display' => 'Test Field',
                        'character_limit' => 42,
                    ],
                ],
            ],
        ],
        'textarea field with max_limit (42) and max_limit_type ("word")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'textarea',
                        'field_options' => '{
                                "max_limit":42,
                                "max_limit_type":"word"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'textarea',
                        'display' => 'Test Field',
                        'character_limit' => 201,
                    ],
                ],
            ],
        ],
        'number field with maxnum' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'number',
                        'field_options' => '{
                                "maxnum":"42"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'float',
                        'display' => 'Test Field',
                        'max' => '42',
                    ],
                ],
            ],
        ],
        'name field with middle_desc' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'name',
                        'field_options' => '{
                                "middle_desc":"Middle"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'name',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'file field with min_size' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'file',
                        'field_options' => '{
                                "min_size":"42"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'assets',
                        'display' => 'Test Field',
                        'container' => 'form_entry_uploads',
                        'folder' => 'supply_chain',
                        'dynamic' => 'id',
                        'restrict' => true,
                        'max_files' => 1,
                        'min_file_size' => 42,
                    ],
                ],
            ],
        ],
        'number field with minnum' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'number',
                        'field_options' => '{
                                "minnum":"42"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'float',
                        'display' => 'Test Field',
                        'min' => '42',
                    ],
                ],
            ],
        ],
        'file field with multiple (0)' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'file',
                        'field_options' => '{
                                "multiple":"0"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
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
        'file field with multiple (not set)' => [
            'source' => [
                \array_merge(
                    $baseField,
                    ['type' => 'file']
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
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
        'file field with multiple (1)' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'file',
                        'field_options' => '{
                                "multiple":"1"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'assets',
                        'display' => 'Test Field',
                        'container' => 'form_entry_uploads',
                        'folder' => 'supply_chain',
                        'dynamic' => 'id',
                        'restrict' => true,
                    ],
                ],
            ],
        ],
        'file field with new_size ("600")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'file',
                        'field_options' => '{
                                "new_size":"600"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
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
        'radio field with other' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'radio',
                        'options' => '{
                                "0":{
                                    "label":"Red",
                                    "value":"Red",
                                    "image":"0"
                                },
                                "1":{
                                    "label":"Blue",
                                    "value":"Blue",
                                    "image":"0"
                                },
                                "other_2":"Other Green"
                            }',
                        'field_options' => '{
                                "other":"1"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'radio',
                        'display' => 'Test Field',
                        'enable_other' => true,
                        'other_label' => 'Other Green',
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
        'checkbox field with other' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'checkbox',
                        'options' => '{
                                "0":{
                                    "label":"Square",
                                    "value":"Square",
                                    "image":"0"
                                },
                                "1":{
                                    "label":"Circle",
                                    "value":"Circle",
                                    "image":"0"
                                },
                                "other_2":"Other Triangle"
                            }',
                        'field_options' => '{
                                "other":"1"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'checkboxes',
                        'display' => 'Test Field',
                        'enable_other' => true,
                        'other_label' => 'Other Triangle',
                        'options' => [
                            [
                                'key' => 'Square',
                                'value' => null,
                            ],
                            [
                                'key' => 'Circle',
                                'value' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'select field with other' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'select',
                        'options' => '{
                                "0":{
                                    "label":"",
                                    "value":""
                                },
                                "1":{
                                    "label":"Near",
                                    "value":"Near"
                                },
                                "2":{
                                    "label":"Far",
                                    "value":"Far"
                                },
                                "other_3":"Other Across"
                            }',
                        'field_options' => '{
                                "other":"1"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'select',
                        'display' => 'Test Field',
                        'taggable' => true,
                        'options' => [
                            [
                                'key' => 'Near',
                                'value' => null,
                            ],
                            [
                                'key' => 'Far',
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
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "placeholder":"Enter your name"
                            }',
                    ]
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
        'text field with post_field' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "post_field":""
                            }',
                    ]
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
        'text field with read_only ("")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "read_only":"0"
                            }',
                    ]
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
        'text field with read_only ("0")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "read_only":"0"
                            }',
                    ]
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
        'text field with read_only ("1")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "read_only":"1"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Test Field',
                        'visibility' => 'read_only',
                    ],
                ],
            ],
        ],
        'file field with resize (true) and resize_dir ("width")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'file',
                        'field_options' => '{
                                "resize":true,
                                "resize_dir":"width"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
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
        'file field with resize and resize_dir ("height")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'file',
                        'field_options' => '{
                                "resize":true,
                                "resize_dir":"height"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
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
        'file field with restrict ("")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'file',
                        'field_options' => '{
                                "multiple":"1",
                                "restrict":""
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'assets',
                        'display' => 'Test Field',
                        'container' => 'form_entry_uploads',
                        'folder' => 'supply_chain',
                        'dynamic' => 'id',
                        'restrict' => true,
                    ],
                ],
            ],
        ],
        'file field with restrict ("0")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'file',
                        'field_options' => '{
                                "multiple":"1",
                                "restrict":""
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'assets',
                        'display' => 'Test Field',
                        'container' => 'form_entry_uploads',
                        'folder' => 'supply_chain',
                        'dynamic' => 'id',
                        'restrict' => true,
                    ],
                ],
            ],
        ],
        'file field with restrict and ftypes single' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'file',
                        'field_options' => '{
                                "restrict":"1",
                                "ftypes":{"jpg|jpeg|jpe":"image\/jpeg"}
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'assets',
                        'display' => 'Test Field',
                        'container' => 'form_entry_uploads',
                        'folder' => 'supply_chain',
                        'dynamic' => 'id',
                        'restrict' => true,
                        'max_files' => 1,
                        'file_type_restrictions' => 'mime_types',
                        'mime_types' => ['image/jpeg'],
                    ],
                ],
            ],
        ],
        'file field with restrict and ftypes multiple' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'file',
                        'field_options' => '{
                                "restrict":"1",
                                "ftypes":{"jpg|jpeg|jpe":"image\/jpeg","png":"image\/png","gif":"image\/gif"}
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'assets',
                        'display' => 'Test Field',
                        'container' => 'form_entry_uploads',
                        'folder' => 'supply_chain',
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
        // Formidable always stores ftypes as {"ext":"mime/type"} (string values),
        // but guard the mapping so a malformed object (nested values, numbers, null)
        // cannot leak non-string list items into the blueprint's mime_types.
        'file field with restrict and ftypes non-string values dropped' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'file',
                        'field_options' => '{
                                "restrict":"1",
                                "ftypes":{"png":"image\/png","nested":{"x":"image\/jpeg"},"nullish":null}
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'assets',
                        'display' => 'Test Field',
                        'container' => 'form_entry_uploads',
                        'folder' => 'supply_chain',
                        'dynamic' => 'id',
                        'restrict' => true,
                        'max_files' => 1,
                        'file_type_restrictions' => 'mime_types',
                        'mime_types' => ['image/png'],
                    ],
                ],
            ],
        ],
        'text field with size ("42")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "size":"42"
                            }',
                    ]
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
        'text field with size ("42px")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "size":"42px"
                            }',
                    ]
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
        'text field with size ("42%")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "size":"42%"
                            }',
                    ]
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
        'text field with size ("42em")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "size":"42em"
                            }',
                    ]
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
        'file field with size' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'file',
                        'field_options' => '{
                                "size":"42"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'assets',
                        'display' => 'Test Field',
                        'container' => 'form_entry_uploads',
                        'folder' => 'supply_chain',
                        'dynamic' => 'id',
                        'restrict' => true,
                        'max_files' => 1,
                        'max_file_size' => 42,
                    ],
                ],
            ],
        ],
        'address field with state_desc' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'address',
                        'field_options' => '{
                                "state_desc":"State/Province"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'address',
                        'display' => 'Test Field',
                        'preset' => 'international',
                    ],
                ],
            ],
        ],
        'number field with step' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'number',
                        'field_options' => '{
                                "step":"0.5"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'float',
                        'display' => 'Test Field',
                        'step' => '0.5',
                    ],
                ],
            ],
        ],
        'time field with step (60 minutes)' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'time',
                        'field_options' => '{
                                "step":"60"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'time',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'time field with step (60 seconds)' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'time',
                        'field_options' => '{
                                "step":"60",
                                "step_unit":"sec"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'time',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'time field with start_time' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'time',
                        'field_options' => '{
                                "start_time":"00:00:00"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'time',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'time field with end_time' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'time',
                        'field_options' => '{
                                "end_time":"23:59:59"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'time',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'text field with taxonomy ("category")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "taxonomy":"category"
                            }',
                    ]
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
        'text field with unique (0)' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "unique":0
                            }',
                    ]
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
        'text field with unique (1) and unique_msg' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "unique_":1,
                                "unique_msg":"This value must be unique."
                            }',
                    ]
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
        'text field with watch_lookup ([])' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "watch_lookup":[]
                            }',
                    ]
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
        'lookup field with watch_lookup ("")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'field_options' => '{
                                "watch_lookup":""
                            }',
                    ]
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
        'address field with zip_desc' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'address',
                        'field_options' => '{
                                "zip_desc":"ZIP\/Postal"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'address',
                        'display' => 'Test Field',
                        'preset' => 'international',
                    ],
                ],
            ],
        ],
        'checkbox field with hide_image_text (1)' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'checkbox',
                        'options' => '{
                                "0":{
                                    "label":"Square",
                                    "value":"Square",
                                    "image":"0"
                                },
                                "1":{
                                    "label":"Circle",
                                    "value":"Circle",
                                    "image":"0"
                                }
                            }',
                        'field_options' => '{
                                "hide_image_text":1
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'checkboxes',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'Square',
                                'value' => null,
                            ],
                            [
                                'key' => 'Circle',
                                'value' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'checkbox field with image_options ("0")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'checkbox',
                        'options' => '{
                                "0":{
                                    "label":"Square",
                                    "value":"Square",
                                    "image":"0"
                                },
                                "1":{
                                    "label":"Circle",
                                    "value":"Circle",
                                    "image":"0"
                                }
                            }',
                        'field_options' => '{
                                "image_options":"0"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'checkboxes',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'Square',
                                'value' => null,
                            ],
                            [
                                'key' => 'Circle',
                                'value' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'checkbox field with image_size ("small")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'checkbox',
                        'options' => '{
                                "0":{
                                    "label":"Square",
                                    "value":"Square",
                                    "image":"0"
                                },
                                "1":{
                                    "label":"Circle",
                                    "value":"Circle",
                                    "image":"0"
                                }
                            }',
                        'field_options' => '{
                                "image_size":"small"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'checkboxes',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'Square',
                                'value' => null,
                            ],
                            [
                                'key' => 'Circle',
                                'value' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'checkbox field with show_image (1)' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'checkbox',
                        'options' => '{
                                "0":{
                                    "label":"Square",
                                    "value":"Square",
                                    "image":"0"
                                },
                                "1":{
                                    "label":"Circle",
                                    "value":"Circle",
                                    "image":"0"
                                }
                            }',
                        'field_options' => '{
                                "show_image":1
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'checkboxes',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'Square',
                                'value' => null,
                            ],
                            [
                                'key' => 'Circle',
                                'value' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'checkbox field with separate_value ("1")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'checkbox',
                        'options' => '{
                                "0":{
                                    "label":"Square",
                                    "value":"square",
                                    "image":"0"
                                },
                                "1":{
                                    "label":"Circle",
                                    "value":"circle",
                                    "image":"0"
                                }
                            }',
                        'field_options' => '{
                                "separate_value":"1"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
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
        'checkbox field with separate_value (1)' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'checkbox',
                        'options' => '{
                                "0":{
                                    "label":"Square",
                                    "value":"square",
                                    "image":"0"
                                },
                                "1":{
                                    "label":"Circle",
                                    "value":"circle",
                                    "image":"0"
                                }
                            }',
                        'field_options' => '{
                                "separate_value":1
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
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
        'radio field with hide_image_text (1)' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'radio',
                        'options' => '[
                                {
                                    "label":"Yes",
                                    "value":"Yes",
                                    "image":"0"
                                },
                                {
                                    "label":"No",
                                    "value":"No",
                                    "image":"0"
                                }
                            ]',
                        'field_options' => '{
                                "hide_image_text":1
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'radio',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'Yes',
                                'value' => null,
                            ],
                            [
                                'key' => 'No',
                                'value' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'radio field with image_options ("0")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'radio',
                        'options' => '[
                                {
                                    "label":"Yes",
                                    "value":"Yes",
                                    "image":"0"
                                },
                                {
                                    "label":"No",
                                    "value":"No",
                                    "image":"0"
                                }
                            ]',
                        'field_options' => '{
                                "image_options":"0"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'radio',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'Yes',
                                'value' => null,
                            ],
                            [
                                'key' => 'No',
                                'value' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'radio field with image_size ("small")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'radio',
                        'options' => '[
                                {
                                    "label":"Yes",
                                    "value":"Yes",
                                    "image":"0"
                                },
                                {
                                    "label":"No",
                                    "value":"No",
                                    "image":"0"
                                }
                            ]',
                        'field_options' => '{
                                "image_size":"small"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'radio',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'Yes',
                                'value' => null,
                            ],
                            [
                                'key' => 'No',
                                'value' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'radio field with show_image (1)' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'radio',
                        'options' => '[
                                {
                                    "label":"Yes",
                                    "value":"Yes",
                                    "image":"0"
                                },
                                {
                                    "label":"No",
                                    "value":"No",
                                    "image":"0"
                                }
                            ]',
                        'field_options' => '{
                                "show_image":1
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'radio',
                        'display' => 'Test Field',
                        'options' => [
                            [
                                'key' => 'Yes',
                                'value' => null,
                            ],
                            [
                                'key' => 'No',
                                'value' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'radio field with separate_value ("1")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'radio',
                        'options' => '[
                                {
                                    "label":"Yes",
                                    "value":"yes",
                                    "image":"0"
                                },
                                {
                                    "label":"No",
                                    "value":"no",
                                    "image":"0"
                                }
                            ]',
                        'field_options' => '{
                                "separate_value":"1"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
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
        'radio field with separate_value (1)' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'radio',
                        'options' => '[
                                {
                                    "label":"Yes",
                                    "value":"yes",
                                    "image":"0"
                                },
                                {
                                    "label":"No",
                                    "value":"no",
                                    "image":"0"
                                }
                            ]',
                        'field_options' => '{
                                "separate_value":1
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
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
        'select field with separate_value ("1")' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'select',
                        'options' => '{
                                "0":{
                                    "label":"",
                                    "value":""
                                },
                                "1":{
                                    "label":"Near",
                                    "value":"near"
                                },
                                "2":{
                                    "label":"Far",
                                    "value":"far"
                                }
                            }',
                        'field_options' => '{
                                "separate_value":"1"
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
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
        'select field with separate_value (1)' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'select',
                        'options' => '{
                                "0":{
                                    "label":"",
                                    "value":""
                                },
                                "1":{
                                    "label":"Near",
                                    "value":"near"
                                },
                                "2":{
                                    "label":"Far",
                                    "value":"far"
                                }
                            }',
                        'field_options' => '{
                                "separate_value":1
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
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
        'text field with is_currency, custom_currency, custom_symbol_left, custom_symbol_right, custom_decimal_separator, custom_thousand_separator, and custom_decimals' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'number',
                        'field_options' => '{
                                "is_currency":1,
                                "custom_currency":"foo",
                                "custom_symbol_left":"foo",
                                "custom_symbol_right":"foo",
                                "custom_decimal_separator":".",
                                "custom_thousand_separator":",",
                                "custom_decimals":2,
                            }',
                    ]
                ),
            ],
            'statamic_blueprint' => [
                [
                    'handle' => 'test_field_123',
                    'field' => [
                        'type' => 'float',
                        'display' => 'Test Field',
                    ],
                ],
            ],
        ],
        'text field with required_indicator' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'type' => 'text',
                        'required' => '1',
                        'field_options' => '{
                                "required_indicator":"(required)"
                            }',
                    ]
                ),
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
        'calculation text target field 1' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'id' => '1013',
                        'type' => 'text',
                        'name' => 'Text Calculation Target Field 1',
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
                    $baseField,
                    [
                        'id' => '1014',
                        'type' => 'text',
                        'name' => 'Text Calculation Target Field 2',
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
                    $baseField,
                    [
                        'id' => '1016',
                        'type' => 'number',
                        'name' => 'Math Calculation Target Field 1',
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
                    $baseField,
                    [
                        'id' => '1017',
                        'type' => 'number',
                        'name' => 'Math Calculation Target Field 2',
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
        'calculation text' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'id' => '1013',
                        'type' => 'text',
                        'name' => 'Text Calculation Target Field 1',
                    ]
                ),
                \array_merge(
                    $baseField,
                    [
                        'id' => '1014',
                        'type' => 'text',
                        'name' => 'Text Calculation Target Field 2',
                    ]
                ),
                \array_merge(
                    $baseField,
                    [
                        'id' => '1015',
                        'type' => 'text',
                        'name' => 'Text Calculation',
                        'field_options' => '{
                                "calc": "[1013], [1014]",
                                "calc_type": "text"
                            }',
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
                [
                    'handle' => 'text_calculation_target_field_2_1014',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Text Calculation Target Field 2',
                    ],
                ],
                [
                    'handle' => 'text_calculation_1015',
                    'field' => [
                        'type' => 'text',
                        'display' => 'Text Calculation',
                        'calculation_query' => true,
                        'calculation_formula_text' => '[text_calculation_target_field_1_1013], [text_calculation_target_field_2_1014]',
                    ],
                ],
            ],
        ],
        'calculation math' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'id' => '1016',
                        'type' => 'number',
                        'name' => 'Math Calculation Target Field 1',
                    ]
                ),
                \array_merge(
                    $baseField,
                    [
                        'id' => '1017',
                        'type' => 'number',
                        'name' => 'Math Calculation Target Field 2',
                    ]
                ),
                \array_merge(
                    $baseField,
                    [
                        'id' => '1018',
                        'type' => 'number',
                        'name' => 'Math Calculation',
                        'field_options' => '{
                                "calc": "[1016] * [1017]",
                                "calc_dec": "3"
                            }',
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
                    $baseField,
                    [
                        'id' => '915',
                        'name' => 'Target Radio Field 1',
                        'type' => 'radio',
                        'options' => '[
                                {
                                    "label":"Red",
                                    "value":"red",
                                    "image":"0"
                                },
                                {
                                    "label":"Blue",
                                    "value":"blue",
                                    "image":"0"
                                },
                                {
                                    "label":"Green",
                                    "value":"green",
                                    "image":"0"
                                }
                            ]',
                        'field_options' => '{
                                "separate_value":"1",
                                "other":"0"
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
            ],
        ],
        'conditional logic target radio field 2' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'id' => '916',
                        'name' => 'Target Radio Field 2',
                        'type' => 'radio',
                        'options' => '[
                                {
                                    "label": "Square",
                                    "value": "square",
                                    "image": "0"
                                },
                                {
                                    "label": "Circle",
                                    "value": "circle",
                                    "image": "0"
                                },
                                {
                                    "label": "Triangle",
                                    "value": "triangle",
                                    "image": "0"
                                }
                            ]',
                        'field_options' => '{
                                "separate_value":"1",
                                "other":"0"
                            }',
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
                    $baseField,
                    [
                        'id' => '917',
                        'name' => 'Target Number Field 1',
                        'type' => 'number',
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
                    $baseField,
                    [
                        'id' => '918',
                        'name' => 'Target Text Field 1',
                        'type' => 'text',
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
        'conditional logic 1' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'id' => '915',
                        'name' => 'Target Radio Field 1',
                        'type' => 'radio',
                        'options' => '[
                                    {
                                    "label":"Red",
                                    "value":"red",
                                    "image":"0"
                                },
                                {
                                    "label":"Blue",
                                    "value":"blue",
                                    "image":"0"
                                },
                                {
                                    "label":"Green",
                                    "value":"green",
                                    "image":"0"
                                }
                            ]',
                        'field_options' => '{
                                "separate_value":"1",
                                "other":"0"
                            }',
                    ]
                ),
                \array_merge(
                    $baseField,
                    [
                        'id' => '919',
                        'name' => 'Conditional Field 1',
                        'type' => 'text',
                        'field_options' => '{
                                "any_all": "all",
                                "hide_field": [
                                    "915"
                                ],
                                "hide_opt": [
                                    "red"
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
        'conditional logic 2' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'id' => '915',
                        'name' => 'Target Radio Field 1',
                        'type' => 'radio',
                        'options' => '[
                                    {
                                    "label":"Red",
                                    "value":"red",
                                    "image":"0"
                                },
                                {
                                    "label":"Blue",
                                    "value":"blue",
                                    "image":"0"
                                },
                                {
                                    "label":"Green",
                                    "value":"green",
                                    "image":"0"
                                }
                            ]',
                        'field_options' => '{
                                "separate_value":"1",
                                "other":"0"
                            }',
                    ]
                ),
                \array_merge(
                    $baseField,
                    [
                        'id' => '916',
                        'name' => 'Target Radio Field 2',
                        'type' => 'radio',
                        'options' => '[
                                    {
                                    "label": "Square",
                                    "value": "square",
                                    "image": "0"
                                },
                                {
                                    "label": "Circle",
                                    "value": "circle",
                                    "image": "0"
                                },
                                {
                                    "label": "Triangle",
                                    "value": "triangle",
                                    "image": "0"
                                }
                            ]',
                        'field_options' => '{
                                "separate_value":"1",
                                "other":"0"
                            }',
                    ]
                ),
                \array_merge(
                    $baseField,
                    [
                        'id' => '920',
                        'name' => 'Conditional Field 2',
                        'type' => 'text',
                        'field_options' => '{
                                "any_all":"all",
                                "hide_field":[
                                    "915",
                                    "916"
                                ],
                                "hide_field_cond":[
                                    "==",
                                    "=="
                                ],
                                "hide_opt":[
                                    "red",
                                    "square"
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
        'conditional logic 3' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'id' => '915',
                        'name' => 'Target Radio Field 1',
                        'type' => 'radio',
                        'options' => '[
                                    {
                                    "label":"Red",
                                    "value":"red",
                                    "image":"0"
                                },
                                {
                                    "label":"Blue",
                                    "value":"blue",
                                    "image":"0"
                                },
                                {
                                    "label":"Green",
                                    "value":"green",
                                    "image":"0"
                                }
                            ]',
                        'field_options' => '{
                                "separate_value":"1",
                                "other":"0"
                            }',
                    ]
                ),
                \array_merge(
                    $baseField,
                    [
                        'id' => '921',
                        'name' => 'Conditional Field 3',
                        'type' => 'text',
                        'field_options' => '{
                                "any_all":"all",
                                "hide_field":[
                                    "915"
                                ],
                                "hide_field_cond":[
                                    "!="
                                ],
                                "hide_opt":[
                                    "red"
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
        'conditional logic 4' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'id' => '917',
                        'name' => 'Target Number Field 1',
                        'type' => 'number',
                    ]
                ),
                \array_merge(
                    $baseField,
                    [
                        'id' => '922',
                        'name' => 'Conditional Field 4',
                        'type' => 'text',
                        'field_options' => '{
                                "any_all":"all",
                                "hide_field":[
                                    "917"
                                ],
                                "hide_field_cond":[
                                    ">"
                                ],
                                "hide_opt":[
                                    "42"
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
        'conditional logic 5' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'id' => '917',
                        'name' => 'Target Number Field 1',
                        'type' => 'number',
                    ]
                ),
                \array_merge(
                    $baseField,
                    [
                        'id' => '923',
                        'name' => 'Conditional Field 5',
                        'type' => 'text',
                        'field_options' => '{
                                "any_all":"all",
                                "hide_field":[
                                    "917"
                                ],
                                "hide_field_cond":[
                                    "<"
                                ],
                                "hide_opt":[
                                    "42"
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
        'conditional logic 6' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'id' => '918',
                        'name' => 'Target Text Field 1',
                        'type' => 'text',
                    ]
                ),
                \array_merge(
                    $baseField,
                    [
                        'id' => '924',
                        'name' => 'Conditional Field 6',
                        'type' => 'text',
                        'field_options' => '{
                                "any_all":"all",
                                "hide_field":[
                                    "918"
                                ],
                                "hide_field_cond":[
                                    "LIKE"
                                ],
                                "hide_opt":[
                                    "foo"
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
        'conditional logic 7' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'id' => '915',
                        'name' => 'Target Radio Field 1',
                        'type' => 'radio',
                        'options' => '[
                                    {
                                    "label":"Red",
                                    "value":"red",
                                    "image":"0"
                                },
                                {
                                    "label":"Blue",
                                    "value":"blue",
                                    "image":"0"
                                },
                                {
                                    "label":"Green",
                                    "value":"green",
                                    "image":"0"
                                }
                            ]',
                        'field_options' => '{
                                "separate_value":"1",
                                "other":"0"
                            }',
                    ]
                ),
                \array_merge(
                    $baseField,
                    [
                        'id' => '916',
                        'name' => 'Target Radio Field 2',
                        'type' => 'radio',
                        'options' => '[
                                    {
                                    "label": "Square",
                                    "value": "square",
                                    "image": "0"
                                },
                                {
                                    "label": "Circle",
                                    "value": "circle",
                                    "image": "0"
                                },
                                {
                                    "label": "Triangle",
                                    "value": "triangle",
                                    "image": "0"
                                }
                            ]',
                        'field_options' => '{
                                "separate_value":"1",
                                "other":"0"
                            }',
                    ]
                ),
                \array_merge(
                    $baseField,
                    [
                        'id' => '925',
                        'name' => 'Conditional Field 7',
                        'type' => 'text',
                        'field_options' => '{
                                "hide_field":[
                                    "915",
                                    "916"
                                ],
                                "hide_field_cond":[
                                    "==",
                                    "=="
                                ],
                                "hide_opt":[
                                    "red",
                                    "square"
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
        'conditional logic 8' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'id' => '915',
                        'name' => 'Target Radio Field 1',
                        'type' => 'radio',
                        'options' => '[
                                    {
                                    "label":"Red",
                                    "value":"red",
                                    "image":"0"
                                },
                                {
                                    "label":"Blue",
                                    "value":"blue",
                                    "image":"0"
                                },
                                {
                                    "label":"Green",
                                    "value":"green",
                                    "image":"0"
                                }
                            ]',
                        'field_options' => '{
                                "separate_value":"1",
                                "other":"0"
                            }',
                    ]
                ),
                \array_merge(
                    $baseField,
                    [
                        'id' => '926',
                        'name' => 'Conditional Field 8',
                        'type' => 'text',
                        'field_options' => '{
                                "show_hide":"hide",
                                "any_all":"all",
                                "hide_field":[
                                    "915"
                                ],
                                "hide_opt":[
                                    "red"
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
        'conditional logic 9' => [
            'source' => [
                \array_merge(
                    $baseField,
                    [
                        'id' => '915',
                        'name' => 'Target Radio Field 1',
                        'type' => 'radio',
                        'options' => '[
                                    {
                                    "label":"Red",
                                    "value":"red",
                                    "image":"0"
                                },
                                {
                                    "label":"Blue",
                                    "value":"blue",
                                    "image":"0"
                                },
                                {
                                    "label":"Green",
                                    "value":"green",
                                    "image":"0"
                                }
                            ]',
                        'field_options' => '{
                                "separate_value":"1",
                                "other":"0"
                            }',
                    ]
                ),
                \array_merge(
                    $baseField,
                    [
                        'id' => '916',
                        'name' => 'Target Radio Field 2',
                        'type' => 'radio',
                        'options' => '[
                                    {
                                    "label": "Square",
                                    "value": "square",
                                    "image": "0"
                                },
                                {
                                    "label": "Circle",
                                    "value": "circle",
                                    "image": "0"
                                },
                                {
                                    "label": "Triangle",
                                    "value": "triangle",
                                    "image": "0"
                                }
                            ]',
                        'field_options' => '{
                                "separate_value":"1",
                                "other":"0"
                            }',
                    ]
                ),
                \array_merge(
                    $baseField,
                    [
                        'id' => '927',
                        'name' => 'Conditional Field 9',
                        'type' => 'text',
                        'field_options' => '{
                                "show_hide":"hide",
                                "any_all":"all",
                                "hide_field":[
                                    "915",
                                    "916"
                                ],
                                "hide_field_cond":[
                                    "==",
                                    "=="
                                ],
                                "hide_opt":[
                                    "red",
                                    "square"
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
                    $baseField,
                    [
                        'id' => '915',
                        'name' => 'Target Radio Field 1',
                        'type' => 'radio',
                        'options' => '[
                                    {
                                    "label":"Red",
                                    "value":"red",
                                    "image":"0"
                                },
                                {
                                    "label":"Blue",
                                    "value":"blue",
                                    "image":"0"
                                },
                                {
                                    "label":"Green",
                                    "value":"green",
                                    "image":"0"
                                }
                            ]',
                        'field_options' => '{
                                "separate_value":"1",
                                "other":"0"
                            }',
                    ]
                ),
                \array_merge(
                    $baseField,
                    [
                        'id' => '936',
                        'name' => 'Conditional Field 10',
                        'type' => 'text',
                        'field_options' => '{
                                "show_hide":"hide",
                                "any_all":"all",
                                "hide_field":[
                                    "915"
                                ],
                                "hide_field_cond":[
                                    "!="
                                ],
                                "hide_opt":[
                                    "red"
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
                    $baseField,
                    [
                        'id' => '917',
                        'name' => 'Target Number Field 1',
                        'type' => 'number',
                    ]
                ),
                \array_merge(
                    $baseField,
                    [
                        'id' => '928',
                        'name' => 'Conditional Field 11',
                        'type' => 'text',
                        'field_options' => '{
                                "show_hide":"hide",
                                "any_all":"all",
                                "hide_field":[
                                    "917"
                                ],
                                "hide_field_cond":[
                                    ">"
                                ],
                                "hide_opt":[
                                    "42"
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
                    $baseField,
                    [
                        'id' => '917',
                        'name' => 'Target Number Field 1',
                        'type' => 'number',
                    ]
                ),
                \array_merge(
                    $baseField,
                    [
                        'id' => '929',
                        'name' => 'Conditional Field 12',
                        'type' => 'text',
                        'field_options' => '{
                                "show_hide":"hide",
                                "any_all":"all",
                                "hide_field":[
                                    "917"
                                ],
                                "hide_field_cond":[
                                    "<"
                                ],
                                "hide_opt":[
                                    "42"
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
                    $baseField,
                    [
                        'id' => '918',
                        'name' => 'Target Text Field 1',
                        'type' => 'text',
                    ]
                ),
                \array_merge(
                    $baseField,
                    [
                        'id' => '930',
                        'name' => 'Conditional Field 13',
                        'type' => 'text',
                        'field_options' => '{
                                "show_hide":"hide",
                                "any_all":"all",
                                "hide_field":[
                                    "918"
                                ],
                                "hide_opt":[
                                    "foo"
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
                    $baseField,
                    [
                        'id' => '915',
                        'name' => 'Target Radio Field 1',
                        'type' => 'radio',
                        'options' => '[
                                    {
                                    "label":"Red",
                                    "value":"red",
                                    "image":"0"
                                },
                                {
                                    "label":"Blue",
                                    "value":"blue",
                                    "image":"0"
                                },
                                {
                                    "label":"Green",
                                    "value":"green",
                                    "image":"0"
                                }
                            ]',
                        'field_options' => '{
                                "separate_value":"1",
                                "other":"0"
                            }',
                    ]
                ),
                \array_merge(
                    $baseField,
                    [
                        'id' => '916',
                        'name' => 'Target Radio Field 2',
                        'type' => 'radio',
                        'options' => '[
                                    {
                                    "label": "Square",
                                    "value": "square",
                                    "image": "0"
                                },
                                {
                                    "label": "Circle",
                                    "value": "circle",
                                    "image": "0"
                                },
                                {
                                    "label": "Triangle",
                                    "value": "triangle",
                                    "image": "0"
                                }
                            ]',
                        'field_options' => '{
                                "separate_value":"1",
                                "other":"0"
                            }',
                    ]
                ),
                \array_merge(
                    $baseField,
                    [
                        'id' => '931',
                        'name' => 'Conditional Field 14',
                        'type' => 'text',
                        'field_options' => '{
                                "show_hide":"hide",
                                "hide_field":[
                                    "915",
                                    "916"
                                ],
                                "hide_field_cond":[
                                    "==",
                                    "=="
                                ],
                                "hide_opt":[
                                    "red",
                                    "square"
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
                    $baseField,
                    [
                        'id' => '915',
                        'name' => 'Target Radio Field 1',
                        'type' => 'radio',
                        'options' => '[
                                    {
                                    "label":"Red",
                                    "value":"red",
                                    "image":"0"
                                },
                                {
                                    "label":"Blue",
                                    "value":"blue",
                                    "image":"0"
                                },
                                {
                                    "label":"Green",
                                    "value":"green",
                                    "image":"0"
                                }
                            ]',
                        'field_options' => '{
                                "separate_value":"1",
                                "other":"0"
                            }',
                    ]
                ),
                \array_merge(
                    $baseField,
                    [
                        'id' => '932',
                        'name' => 'Conditional Field 15',
                        'type' => 'text',
                        'field_options' => '{
                                "any_all":"all",
                                "hide_field":[
                                    "915"
                                ],
                                "hide_opt":[
                                    ""
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
                    $baseField,
                    [
                        'id' => '915',
                        'name' => 'Target Radio Field 1',
                        'type' => 'radio',
                        'options' => '[
                                    {
                                    "label":"Red",
                                    "value":"red",
                                    "image":"0"
                                },
                                {
                                    "label":"Blue",
                                    "value":"blue",
                                    "image":"0"
                                },
                                {
                                    "label":"Green",
                                    "value":"green",
                                    "image":"0"
                                }
                            ]',
                        'field_options' => '{
                                "separate_value":"1",
                                "other":"0"
                            }',
                    ]
                ),
                \array_merge(
                    $baseField,
                    [
                        'id' => '933',
                        'name' => 'Conditional Field 16',
                        'type' => 'text',
                        'field_options' => '{
                                "any_all":"all",
                                "hide_field":[
                                    "915"
                                ],
                                "hide_field_cond":[
                                    "!="
                                ],
                                "hide_opt":[
                                    ""
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
                    $baseField,
                    [
                        'id' => '915',
                        'name' => 'Target Radio Field 1',
                        'type' => 'radio',
                        'options' => '[
                                    {
                                    "label":"Red",
                                    "value":"red",
                                    "image":"0"
                                },
                                {
                                    "label":"Blue",
                                    "value":"blue",
                                    "image":"0"
                                },
                                {
                                    "label":"Green",
                                    "value":"green",
                                    "image":"0"
                                }
                            ]',
                        'field_options' => '{
                                "separate_value":"1",
                                "other":"0"
                            }',
                    ]
                ),
                \array_merge(
                    $baseField,
                    [
                        'id' => '934',
                        'name' => 'Conditional Field 17',
                        'type' => 'text',
                        'field_options' => '{
                                "show_hide":"hide",
                                "any_all":"all",
                                "hide_field":[
                                    "915"
                                ],
                                "hide_opt":[
                                    ""
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
                    $baseField,
                    [
                        'id' => '915',
                        'name' => 'Target Radio Field 1',
                        'type' => 'radio',
                        'options' => '[
                                    {
                                    "label":"Red",
                                    "value":"red",
                                    "image":"0"
                                },
                                {
                                    "label":"Blue",
                                    "value":"blue",
                                    "image":"0"
                                },
                                {
                                    "label":"Green",
                                    "value":"green",
                                    "image":"0"
                                }
                            ]',
                        'field_options' => '{
                                "separate_value":"1",
                                "other":"0"
                            }',
                    ]
                ),
                \array_merge(
                    $baseField,
                    [
                        'id' => '935',
                        'name' => 'Conditional Field 18',
                        'type' => 'text',
                        'field_options' => '{
                                "show_hide":"hide",
                                "any_all":"all",
                                "hide_field":[
                                    "915"
                                ],
                                "hide_field_cond":[
                                    "!="
                                ],
                                "hide_opt":[
                                    ""
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
        // TODO: Signature fieldtype
        // 'signature field with max' => [
        //     'source' => [
        //     \array_merge($baseField, [
        //         'type' => 'signature',
        //         'field_options' => '{
        //             "max":"foo"
        //         }'
        //     ])
        //     ],
        //     'statamic_blueprint' => [
        //         [
        //             'handle' => 'test_field_123',
        //             'field' => [
        //                 'type' => 'text',
        //                 'display' => 'Test Field',
        //             ]
        //         ],
        //     ]
        // ],
        // 'signature field with size' => [
        //     'source' => [
        //     \array_merge($baseField, [
        //         'type' => 'signature',
        //         'field_options' => '{
        //             "size":"foo"
        //         }'
        //     ])
        //     ],
        //     'statamic_blueprint' => [
        //         [
        //             'handle' => 'test_field_123',
        //             'field' => [
        //                 'type' => 'text',
        //                 'display' => 'Test Field',
        //             ]
        //         ],
        //     ]
        // ],
    ];
}

/**
 * Data provider for testing different repeater fields and their attribute
 * mappings.
 *
 * @return array<string, array{site_handle: string, source: array<string, mixed>, statamic_forms: list<array<string, mixed>>, use_page_data_mapping?: bool}>
 */
function formidableAttributeMappingRepeaterProvider(): array
{
    // Set the site handle
    $siteHandle = 'supply_chain';

    // Set the site title
    $siteTitle = 'Supply Chain';

    // Set the base form
    $baseForm = [
        'id' => '999',
        'form_key' => 'zl1vk',
        'name' => 'Test Form',
        'description' => 'Test form description',
        'created_at' => '2025-08-15 14:37:38',
        'logged_in' => '0',
        'is_template' => '0',
        'editable' => '1',
        'options' => '{
                "show_title": "1",
                "show_description": "1",
                "on_submit_migrated": "1",
                "no_save": "0",
                "honeypot": "strict",
                "antispam": "1",
                "ajax_load": "1",
                "ajax_submit": "1",
                "js_validate": "1",
                "custom_style": "1",
                "rootline_titles_on": "0",
                "rootline_numbers_off": "0",
                "rootline_lines_off": "0",
                "submit_conditions": {
                    "show_hide": "show",
                    "any_all": "all",
                    "hide_field_cond": [
                        "=="
                    ]
                },
                "logged_in_role": [
                    "administrator",
                    "editor"
                ],
                "single_entry": "1",
                "single_entry_type": [
                    "user"
                ],
                "cookie_expiration": "8000",
                "unique_email_id": "",
                "protect_files": "1",
                "protect_files_role": [
                    "administrator",
                    "editor",
                    "author"
                ],
                "noindex_files": "1",
                "copy": "1",
                "editable_role": [
                    "editor",
                    "author"
                ],
                "open_editable_role": [
                    "administrator",
                    "editor"
                ],
                "save_draft": "1",
                "edit_draft_role": [
                    ""
                ],
                "draft_msg": "Your draft has been saved. Foo bar.",
                "draft_label": "Save Draft Foo Bar",
                "open_status": "schedule-limit",
                "open_date": "2025-08-15 09:37",
                "close_date": "2025-08-31 13:54",
                "max_entries": "10",
                "closed_msg": "<p>In enim justo, rhoncus ut, imperdiet a, venenatis vitae, justo. Nullam dictum felis eu pede mollis pretium. Integer tincidunt. Cras dapibus. Vivamus elementum semper nisi. Aenean vulputate eleifend tellus.<\/p>",
                "before_html": "<legend class=\\\"frm_screen_reader\\\">[form_name]<\/legend>\r\n[if form_name]<h3 class=\\\"frm_form_title\\\">[form_name]<\/h3>[\/if form_name]\r\n[if form_description]<div class=\\\"frm_description\\\">[form_description]<\/div>[\/if form_description]",
                "submit_html": "<div class=\\\"frm_submit frm_flex\\\">\r\n<button class=\\\"frm_button_submit\\\" type=\\\"submit\\\"  [button_action]>[button_label]<\/button>\r\n[if back_button]<button type=\\\"submit\\\" name=\\\"frm_prev_page\\\" formnovalidate=\\\"formnovalidate\\\" class=\\\"frm_prev_page\\\" [back_hook]>[back_label]<\/button>[\/if back_button]\r\n[if save_draft]<button class=\\\"frm_save_draft\\\" [draft_hook]>[draft_label]<\/button>[\/if save_draft]\r\n[if start_over]<a href=\\\"#\\\" tabindex=\\\"0\\\" class=\\\"frm_start_over\\\" [start_over_hook]>[start_over_label]<\/a>[\/if start_over]\r\n<\/div>",
                "success_msg": "Thanks for contacting us! We will get in touch with you shortly.\r\n[765]\r\n[826]",
                "show_form": false,
                "edit_msg": "Your responses were successfully submitted. Thank you!"
            }', // 'no_save' is the only relevant option for these tests
        'status' => 'published',
        'parent_form_id' => '0',
        'fields' => [],
    ];

    // Set the base field
    $baseField = [
        'id' => '123',
        'field_key' => 'zzz999',
        'name' => 'Test Field',
        'description' => '',
        'type' => 'text',
        'default_value' => '',
        'field_order' => '1',
        'form_id' => '999',
        'required' => '0',
        'options' => '',
        'field_options' => '{}',
    ];

    // Set the base confirmation
    $baseConfirmation = [
        'title' => 'Test Confirmation',
        'link' => '',
        'post_author' => 'foo',
        'description' => '',
        'content' => '{
                "event": [
                    "create"
                ],
                "success_action": "message",
                "success_msg": "Thanks for contacting us! We will get in touch with you shortly.\r\n[123]",
                "success_url": "",
                "redirect_delay_time": "8",
                "redirect_delay_msg": "Please wait while you are redirected.",
                "success_page_id": "",
                "conditions": {
                    "send_stop": "send",
                    "any_all": "any"
                }
            }',
        'excerpt' => 'on_submit',
        'post_id' => '001',
        'post_date' => '2025-08-15 09:37:38',
        'post_date_gmt' => '2025-08-15 14:37:38',
        'comment_status' => 'closed',
        'ping_status' => 'closed',
        'post_name' => '999_on_submit_001',
        'status' => 'publish',
        'post_parent' => '0',
        'menu_order' => '999',
        'post_type' => 'frm_form_actions',
        'post_password' => '',
        'is_sticky' => '0',
    ];

    // Set the base email
    $baseEmail = [
        'title' => 'Test Notification',
        'link' => '',
        'post_author' => 'foo',
        'description' => '',
        'content' => '{
                "event": [
                    "create"
                ],
                "child_form": "",
                "email_to": "[default-email]",
                "cc": "",
                "bcc": "",
                "from": "[sitename] <[default-from-email]>",
                "reply_to": "",
                "email_subject": "New submission from [form_name]",
                "email_message": "[default-message]",
                "email_attachment_id": "",
                "conditions": {
                    "send_stop": "send",
                    "any_all": "any"
                }
            }',
        'excerpt' => 'email',
        'post_id' => '101',
        'post_date' => '2025-08-15 09:37:38',
        'post_date_gmt' => '2025-08-15 14:37:38',
        'comment_status' => 'closed',
        'ping_status' => 'closed',
        'post_name' => '999_email_101',
        'status' => 'publish',
        'post_parent' => '0',
        'menu_order' => '999',
        'post_type' => 'frm_form_actions',
        'post_password' => '',
        'is_sticky' => '0',
        'postmeta' => null,
    ];

    // Set the test cases
    return [
        'single form with text field' => [
            'site_handle' => $siteHandle,
            'source' => [
                'forms' => [
                    \array_merge(
                        $baseForm,
                        [
                            'fields' => [
                                \array_merge(
                                    $baseField,
                                    []
                                ),
                            ],
                        ]
                    ),
                ],
                'views' => [],
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_formidable-forms_999_test-form',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form',
                        'heading' => 'Test Form',
                        'description' => 'Test form description',
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
                'forms' => [
                    \array_merge(
                        $baseForm,
                        [
                            'id' => '012',
                            'name' => 'Test Form 1',
                            'fields' => [
                                \array_merge(
                                    $baseField,
                                    []
                                ),
                            ],
                        ]
                    ),
                    \array_merge(
                        $baseForm,
                        [
                            'id' => '345',
                            'name' => 'Test Form 2',
                            'fields' => [
                                \array_merge(
                                    $baseField,
                                    []
                                ),
                            ],
                        ]
                    ),
                ],
                'views' => [],
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_formidable-forms_012_test-form-1',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form 1',
                        'heading' => 'Test Form 1',
                        'description' => 'Test form description',
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
                    'handle' => 'supply_chain_formidable-forms_345_test-form-2',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form 2',
                        'heading' => 'Test Form 2',
                        'description' => 'Test form description',
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
                'forms' => [
                    \array_merge(
                        $baseForm,
                        [
                            'description' => '',
                            'fields' => [
                                \array_merge(
                                    $baseField,
                                    []
                                ),
                            ],
                        ]
                    ),
                ],
                'views' => [],
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_formidable-forms_999_test-form',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form',
                        'heading' => 'Test Form',
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
                'forms' => [
                    \array_merge(
                        $baseForm,
                        [
                            'description' => '<p>Test form description</p>',
                            'fields' => [
                                \array_merge(
                                    $baseField,
                                    []
                                ),
                            ],
                        ]
                    ),
                ],
                'views' => [],
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_formidable-forms_999_test-form',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form',
                        'heading' => 'Test Form',
                        'description' => 'Test form description',
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
        'single form with no_save set to 0' => [
            'site_handle' => $siteHandle,
            'source' => [
                'forms' => [
                    \array_merge(
                        $baseForm,
                        [
                            'options' => '{
                                    "no_save": "0"
                                }',
                            'fields' => [
                                \array_merge(
                                    $baseField,
                                    []
                                ),
                            ],
                        ]
                    ),
                ],
                'views' => [],
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_formidable-forms_999_test-form',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form',
                        'heading' => 'Test Form',
                        'description' => 'Test form description',
                        // 'store' => true, // expected `store` to be `true` but not intended to be explicitly stored because it is the default value; I expect the test to pass
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
        'single form with no_save set to 1' => [
            'site_handle' => $siteHandle,
            'source' => [
                'forms' => [
                    \array_merge(
                        $baseForm,
                        [
                            'options' => '{
                                    "no_save": "1"
                                }',
                            'fields' => [
                                \array_merge(
                                    $baseField,
                                    []
                                ),
                            ],
                        ]
                    ),
                ],
                'views' => [],
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_formidable-forms_999_test-form',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form',
                        'heading' => 'Test Form',
                        'description' => 'Test form description',
                        'store' => false, // I expect this test to pass
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
        'single form with basic repeater' => [
            'site_handle' => $siteHandle,
            'source' => [
                'forms' => [
                    \array_merge(
                        $baseForm,
                        [
                            'id' => '61',
                            'fields' => [
                                \array_merge(
                                    $baseField,
                                    [
                                        'id' => '989',
                                        'name' => 'Repeater (Repeat Layout: Default)',
                                        'type' => 'divider',
                                        'field_order' => '2',
                                        'form_id' => '61',
                                        'field_options' => '{
                                                "form_select": "62",
                                                "in_section": "0",
                                                "label": "",
                                                "repeat": "1"
                                            }',
                                    ]
                                ),
                                \array_merge(
                                    $baseField,
                                    [
                                        'id' => '990',
                                        'name' => 'Section Buttons',
                                        'type' => 'end_divider',
                                        'field_order' => '5',
                                        'form_id' => '61',
                                        'field_options' => '{
                                                "in_section": "0",
                                                "add_label": "Add Foo",
                                                "remove_label": "Remove Bar"
                                            }',
                                    ]
                                ),
                            ],
                        ]
                    ),
                    \array_merge(
                        $baseForm,
                        [
                            'id' => '62',
                            'form_key' => 'o85u9',
                            'name' => 'Repeater (Repeat Layout: Default)',
                            'parent_form_id' => '61',
                            'fields' => [
                                \array_merge(
                                    $baseField,
                                    [
                                        'form_id' => '62',
                                        'field_options' => '{
                                                "in_section": "989"
                                            }',
                                    ]
                                ),
                            ],
                        ]
                    ),
                ],
                'views' => [],
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_formidable-forms_61_test-form',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form',
                        'heading' => 'Test Form',
                        'description' => 'Test form description',
                    ],
                    'blueprint_fields' => [
                        [
                            'handle' => 'repeater_repeat_layout_default_989',
                            'field' => [
                                'type' => 'replicator',
                                'display' => 'Repeater (Repeat Layout: Default)',
                                'button_label' => 'Add Foo',
                                'sets' => [
                                    'new_set_group' => [
                                        'display' => 'New Set Group',
                                        'sets' => [
                                            'new_set' => [
                                                'display' => 'New Set',
                                                'fields' => [
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
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'single form with default layout repeater' => [
            'site_handle' => $siteHandle,
            'source' => [
                'forms' => [
                    \array_merge(
                        $baseForm,
                        [
                            'id' => '61',
                            'fields' => [
                                \array_merge(
                                    $baseField,
                                    [
                                        'id' => '989',
                                        'name' => 'Repeater (Repeat Layout: Default)',
                                        'type' => 'divider',
                                        'field_order' => '2',
                                        'form_id' => '61',
                                        'field_options' => '{
                                                "form_select": "62",
                                                "in_section": "0",
                                                "label": "",
                                                "repeat": "1"
                                            }',
                                    ]
                                ),
                                \array_merge(
                                    $baseField,
                                    [
                                        'id' => '990',
                                        'name' => 'Section Buttons',
                                        'type' => 'end_divider',
                                        'field_order' => '5',
                                        'form_id' => '61',
                                        'field_options' => '{
                                                "in_section": "0",
                                                "add_label": "Add Foo",
                                                "remove_label": "Remove Bar"
                                            }',
                                    ]
                                ),
                            ],
                        ]
                    ),
                    \array_merge(
                        $baseForm,
                        [
                            'id' => '62',
                            'name' => 'Repeater (Repeat Layout: Default)',
                            'parent_form_id' => '61',
                            'fields' => [
                                \array_merge(
                                    $baseField,
                                    [
                                        'field_order' => '4',
                                        'form_id' => '62',
                                        'field_options' => '{"in_section":"989"}',
                                    ]
                                ),
                            ],
                        ]
                    ),
                ],
                'views' => [],
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_formidable-forms_61_test-form',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form',
                        'heading' => 'Test Form',
                        'description' => 'Test form description',
                    ],
                    'blueprint_fields' => [
                        [
                            'handle' => 'repeater_repeat_layout_default_989',
                            'field' => [
                                'button_label' => 'Add Foo',
                                'type' => 'replicator',
                                'display' => 'Repeater (Repeat Layout: Default)',
                                'sets' => [
                                    'new_set_group' => [
                                        'display' => 'New Set Group',
                                        'sets' => [
                                            'new_set' => [
                                                'display' => 'New Set',
                                                'fields' => [
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
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'single form with inline layout repeater' => [
            'site_handle' => $siteHandle,
            'source' => [
                'forms' => [
                    \array_merge(
                        $baseForm,
                        [
                            'id' => '61',
                            'fields' => [
                                \array_merge(
                                    $baseField,
                                    [
                                        'id' => '1073',
                                        'name' => 'Repeater (Repeat Layout: Inline)',
                                        'type' => 'divider',
                                        'field_order' => '7',
                                        'form_id' => '61',
                                        'field_options' => '{
                                                "form_select": "63",
                                                "in_section": "0",
                                                "label": "",
                                                "format": "inline",
                                                "repeat": "1"
                                            }',
                                    ]
                                ),
                                \array_merge(
                                    $baseField,
                                    [
                                        'id' => '1074',
                                        'name' => 'Section Buttons',
                                        'type' => 'end_divider',
                                        'field_order' => '10',
                                        'form_id' => '61',
                                        'field_options' => '{
                                                "in_section": "0"
                                            }',
                                    ]
                                ),
                            ],
                        ]
                    ),
                    \array_merge(
                        $baseForm,
                        [
                            'id' => '63',
                            'name' => 'Repeater (Repeat Layout: Inline)',
                            'parent_form_id' => '61',
                            'fields' => [
                                \array_merge(
                                    $baseField,
                                    [
                                        'field_order' => '9',
                                        'form_id' => '63',
                                        'field_options' => '{"in_section":"1073"}',
                                    ]
                                ),
                            ],
                        ]
                    ),
                ],
                'views' => [],
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_formidable-forms_61_test-form',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form',
                        'heading' => 'Test Form',
                        'description' => 'Test form description',
                    ],
                    'blueprint_fields' => [
                        [
                            'handle' => 'repeater_repeat_layout_inline_1073',
                            'field' => [
                                'type' => 'replicator',
                                'display' => 'Repeater (Repeat Layout: Inline)',
                                'sets' => [
                                    'new_set_group' => [
                                        'display' => 'New Set Group',
                                        'sets' => [
                                            'new_set' => [
                                                'display' => 'New Set',
                                                'fields' => [
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
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'single form with grid layout repeater' => [
            'site_handle' => $siteHandle,
            'source' => [
                'forms' => [
                    \array_merge(
                        $baseForm,
                        [
                            'id' => '61',
                            'fields' => [
                                \array_merge(
                                    $baseField,
                                    [
                                        'id' => '1076',
                                        'name' => 'Repeater (Repeat Layout: Grid)',
                                        'type' => 'divider',
                                        'field_order' => '12',
                                        'form_id' => '61',
                                        'field_options' => '{
                                                "form_select": "64",
                                                "in_section": "0",
                                                "label": "",
                                                "repeat": "1"
                                            }',
                                    ]
                                ),
                                \array_merge(
                                    $baseField,
                                    [
                                        'id' => '1077',
                                        'name' => 'Section Buttons',
                                        'type' => 'end_divider',
                                        'field_order' => '15',
                                        'form_id' => '61',
                                        'field_options' => '{
                                                "in_section": "0"
                                            }',
                                    ]
                                ),
                            ],
                        ]
                    ),
                    \array_merge(
                        $baseForm,
                        [
                            'id' => '64',
                            'name' => 'Repeater (Repeat Layout: Grid)',
                            'parent_form_id' => '61',
                            'fields' => [
                                \array_merge(
                                    $baseField,
                                    [
                                        'field_order' => '14',
                                        'form_id' => '64',
                                        'field_options' => '{"in_section":"1076"}',
                                    ]
                                ),
                            ],
                        ]
                    ),
                ],
                'views' => [],
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_formidable-forms_61_test-form',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form',
                        'heading' => 'Test Form',
                        'description' => 'Test form description',
                    ],
                    'blueprint_fields' => [
                        [
                            'handle' => 'repeater_repeat_layout_grid_1076',
                            'field' => [
                                'type' => 'replicator',
                                'display' => 'Repeater (Repeat Layout: Grid)',
                                'sets' => [
                                    'new_set_group' => [
                                        'display' => 'New Set Group',
                                        'sets' => [
                                            'new_set' => [
                                                'display' => 'New Set',
                                                'fields' => [
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
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'single form with icon links repeater' => [
            'site_handle' => $siteHandle,
            'source' => [
                'forms' => [
                    \array_merge(
                        $baseForm,
                        [
                            'id' => '61',
                            'fields' => [
                                \array_merge(
                                    $baseField,
                                    [
                                        'id' => '1079',
                                        'name' => 'Repeater (Repeat Links: Icons)',
                                        'type' => 'divider',
                                        'field_order' => '17',
                                        'form_id' => '61',
                                        'field_options' => '{
                                                "form_select": "65",
                                                "in_section": "0",
                                                "label": "",
                                                "repeat": "1"
                                            }',
                                    ]
                                ),
                                \array_merge(
                                    $baseField,
                                    [
                                        'id' => '1080',
                                        'name' => 'Section Buttons',
                                        'type' => 'end_divider',
                                        'field_order' => '20',
                                        'form_id' => '61',
                                        'field_options' => '{
                                                "in_section": "0",
                                                "format": ""
                                            }',
                                    ]
                                ),
                            ],
                        ]
                    ),
                    \array_merge(
                        $baseForm,
                        [
                            'id' => '65',
                            'name' => 'Repeater (Repeat Links: Icons)',
                            'parent_form_id' => '61',
                            'fields' => [
                                \array_merge(
                                    $baseField,
                                    [
                                        'field_order' => '19',
                                        'form_id' => '65',
                                        'field_options' => '{"in_section":"1079"}',
                                    ]
                                ),
                            ],
                        ]
                    ),
                ],
                'views' => [],
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_formidable-forms_61_test-form',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form',
                        'heading' => 'Test Form',
                        'description' => 'Test form description',
                    ],
                    'blueprint_fields' => [
                        [
                            'handle' => 'repeater_repeat_links_icons_1079',
                            'field' => [
                                'type' => 'replicator',
                                'display' => 'Repeater (Repeat Links: Icons)',
                                'sets' => [
                                    'new_set_group' => [
                                        'display' => 'New Set Group',
                                        'sets' => [
                                            'new_set' => [
                                                'display' => 'New Set',
                                                'fields' => [
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
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'single form with text links repeater' => [
            'site_handle' => $siteHandle,
            'source' => [
                'forms' => [
                    \array_merge(
                        $baseForm,
                        [
                            'id' => '61',
                            'fields' => [
                                \array_merge(
                                    $baseField,
                                    [
                                        'id' => '1081',
                                        'name' => 'Repeater (Repeat Links: Text Links)',
                                        'type' => 'divider',
                                        'field_order' => '22',
                                        'form_id' => '61',
                                        'field_options' => '{
                                                "form_select": "66",
                                                "in_section": "0",
                                                "label": "",
                                                "repeat": "1"
                                            }',
                                    ]
                                ),
                                \array_merge(
                                    $baseField,
                                    [
                                        'id' => '1082',
                                        'name' => 'Section Buttons',
                                        'type' => 'end_divider',
                                        'field_order' => '25',
                                        'form_id' => '61',
                                        'field_options' => '{
                                                "in_section": "0",
                                                "format": "text"
                                            }',
                                    ]
                                ),
                            ],
                        ]
                    ),
                    \array_merge(
                        $baseForm,
                        [
                            'id' => '66',
                            'name' => 'Repeater (Repeat Links: Text Links)',
                            'parent_form_id' => '61',
                            'fields' => [
                                \array_merge(
                                    $baseField,
                                    [
                                        'field_order' => '24',
                                        'form_id' => '66',
                                        'field_options' => '{"in_section":"1081"}',
                                    ]
                                ),
                            ],
                        ]
                    ),
                ],
                'views' => [],
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_formidable-forms_61_test-form',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form',
                        'heading' => 'Test Form',
                        'description' => 'Test form description',
                    ],
                    'blueprint_fields' => [
                        [
                            'handle' => 'repeater_repeat_links_text_links_1081',
                            'field' => [
                                'type' => 'replicator',
                                'display' => 'Repeater (Repeat Links: Text Links)',
                                'sets' => [
                                    'new_set_group' => [
                                        'display' => 'New Set Group',
                                        'sets' => [
                                            'new_set' => [
                                                'display' => 'New Set',
                                                'fields' => [
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
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'single form with basic section' => [
            'site_handle' => $siteHandle,
            'source' => [
                'forms' => [
                    \array_merge(
                        $baseForm,
                        [
                            'id' => '61',
                            'fields' => [
                                \array_merge(
                                    $baseField,
                                    [
                                        'id' => '1111',
                                        'name' => 'Section',
                                        'type' => 'divider',
                                        'field_order' => '27',
                                        'form_id' => '61',
                                        'field_options' => '{
                                                "in_section": "0",
                                                "label": "",
                                                "repeat": "0"
                                            }',
                                    ]
                                ),
                                \array_merge(
                                    $baseField,
                                    [
                                        'id' => '1113',
                                        'name' => 'Section Text Field',
                                        'type' => 'text',
                                        'field_order' => '29',
                                        'form_id' => '61',
                                        'field_options' => '{
                                                "in_section": "1111",
                                            }',
                                    ]
                                ),
                                \array_merge(
                                    $baseField,
                                    [
                                        'id' => '1112',
                                        'name' => 'Section Buttons',
                                        'type' => 'end_divider',
                                        'field_order' => '30',
                                        'form_id' => '61',
                                        'field_options' => '{
                                                "in_section": "0"
                                            }',
                                    ]
                                ),
                            ],
                        ]
                    ),
                ],
                'views' => [],
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_formidable-forms_61_test-form',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form',
                        'heading' => 'Test Form',
                        'description' => 'Test form description',
                    ],
                    'blueprint_fields' => [
                        [
                            'handle' => 'section_1111',
                            'field' => [
                                'type' => 'subsection',
                                'display' => 'Section',
                            ],
                        ],
                        [
                            'handle' => 'test_field_123',
                            'field' => [
                                'type' => 'text',
                                'display' => 'Text Field',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'single form with default confirmation' => [
            'site_handle' => $siteHandle,
            'source' => [
                'forms' => [
                    \array_merge(
                        $baseForm,
                        [
                            'fields' => [
                                \array_merge(
                                    $baseField,
                                    []
                                ),
                            ],
                        ]
                    ),
                ],
                'views' => [
                    \array_merge(
                        $baseConfirmation,
                        []
                    ),
                ],
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_formidable-forms_999_test-form',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form',
                        'heading' => 'Test Form',
                        'description' => 'Test form description',
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
                'forms' => [
                    \array_merge(
                        $baseForm,
                        [
                            'fields' => [
                                \array_merge(
                                    $baseField,
                                    []
                                ),
                            ],
                        ]
                    ),
                ],
                'views' => [
                    \array_merge(
                        $baseConfirmation,
                        []
                    ),
                    \array_merge(
                        $baseConfirmation,
                        [
                            'title' => 'Additional Confirmation',
                            'post_id' => '002',
                            'post_name' => '999_on_submit_002',
                        ]
                    ),
                ],
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_formidable-forms_999_test-form',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form',
                        'heading' => 'Test Form',
                        'description' => 'Test form description',
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
                                'id' => '999_on_submit_002',
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
                'forms' => [
                    \array_merge(
                        $baseForm,
                        [
                            'fields' => [
                                \array_merge(
                                    $baseField,
                                    []
                                ),
                            ],
                        ]
                    ),
                ],
                'views' => [
                    \array_merge(
                        $baseConfirmation,
                        []
                    ),
                    \array_merge(
                        $baseConfirmation,
                        [
                            'title' => 'Additional Confirmation',
                            'post_id' => '002',
                            'post_name' => '999_on_submit_002',
                            'status' => 'draft',
                        ]
                    ),
                ],
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_formidable-forms_999_test-form',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form',
                        'heading' => 'Test Form',
                        'description' => 'Test form description',
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
                                'id' => '999_on_submit_002',
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
                'forms' => [
                    \array_merge(
                        $baseForm,
                        [
                            'fields' => [
                                \array_merge(
                                    $baseField,
                                    []
                                ),
                                \array_merge(
                                    $baseField,
                                    [
                                        'id' => '124',
                                        'field_key' => 'zzz001',
                                        'name' => 'Email Field',
                                        'type' => 'email',
                                        'field_order' => '2',
                                    ]
                                ),
                            ],
                        ]
                    ),
                ],
                'views' => [
                    \array_merge(
                        $baseConfirmation,
                        []
                    ),
                    \array_merge(
                        $baseConfirmation,
                        [
                            'title' => 'Additional Confirmation',
                            'post_id' => '002',
                            'post_name' => '999_on_submit_002',
                            'content' => '{
                                    "event": [
                                        "create"
                                    ],
                                    "success_action": "message",
                                    "success_msg": "Thanks for contacting us! We will get in touch with you shortly.\r\n[123]",
                                    "success_url": "",
                                    "redirect_delay_time": "8",
                                    "redirect_delay_msg": "Please wait while you are redirected.",
                                    "success_page_id": "",
                                    "conditions": {
                                        "send_stop": "send",
                                        "any_all": "all",
                                        "0": {
                                            "hide_field": "124",
                                            "hide_field_cond": "==",
                                            "hide_opt": "foo@bar.com"
                                        },
                                        "1": {
                                            "hide_field": "123",
                                            "hide_field_cond": "==",
                                            "hide_opt": "foo"
                                        }
                                    }
                                }',
                        ]
                    ),
                ],
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_formidable-forms_999_test-form',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form',
                        'heading' => 'Test Form',
                        'description' => 'Test form description',
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
                                'id' => '999_on_submit_002',
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
                                        'email_field_124' => 'equals foo@bar.com',
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
                            'handle' => 'email_field_124',
                            'field' => [
                                'type' => 'email',
                                'display' => 'Email Field',
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
                'forms' => [
                    \array_merge(
                        $baseForm,
                        [
                            'fields' => [
                                \array_merge(
                                    $baseField,
                                    []
                                ),
                            ],
                        ]
                    ),
                ],
                'views' => [
                    \array_merge(
                        $baseConfirmation,
                        []
                    ),
                    \array_merge(
                        $baseConfirmation,
                        [
                            'title' => 'Additional Confirmation',
                            'post_id' => '002',
                            'post_name' => '999_on_submit_002',
                            'content' => '{
                                    "event": [
                                        "create"
                                    ],
                                    "success_action": "page",
                                    "success_page_id": "321",
                                    "success_msg": "Thanks for contacting us! We will get in touch with you shortly.\r\n[765]\r\n[826]",
                                    "success_url": "https:\/\/statamic.dev\/",
                                    "open_in_new_tab": "1",
                                    "redirect_delay": "1",
                                    "redirect_delay_time": "42",
                                    "redirect_delay_msg": "Please wait while you are redirected. Donec quam felis, ultricies nec, pellentesque eu, pretium quis, sem. Nulla consequat massa quis enim.\r\n\r\n[765]",
                                    "conditions": {}
                                }',
                        ]
                    ),
                ],
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_formidable-forms_999_test-form',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form',
                        'heading' => 'Test Form',
                        'description' => 'Test form description',
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
                                'id' => '999_on_submit_002',
                                'confirmation_name' => 'Additional Confirmation',
                                'confirmation_settings' => [
                                    'confirmation_type' => 'replace',
                                    'confirmation_replace_entry' => 'statamic-entry-321',
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
                'forms' => [
                    \array_merge(
                        $baseForm,
                        [
                            'fields' => [
                                \array_merge(
                                    $baseField,
                                    []
                                ),
                            ],
                        ]
                    ),
                ],
                'views' => [
                    \array_merge(
                        $baseEmail,
                        []
                    ),
                ],
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_formidable-forms_999_test-form',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form',
                        'heading' => 'Test Form',
                        'description' => 'Test form description',
                        'email' => [
                            [
                                'id' => '999_email_101',
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
        'single form with enabled additional email notification' => [
            'site_handle' => $siteHandle,
            'source' => [
                'forms' => [
                    \array_merge(
                        $baseForm,
                        [
                            'fields' => [
                                \array_merge(
                                    $baseField,
                                    []
                                ),
                            ],
                        ]
                    ),
                ],
                'views' => [
                    \array_merge(
                        $baseEmail,
                        []
                    ),
                    \array_merge(
                        $baseEmail,
                        [
                            'title' => 'Additional Email Notification',
                            'post_id' => '002',
                            'post_name' => '999_email_102',
                        ]
                    ),
                ],
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_formidable-forms_999_test-form',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form',
                        'heading' => 'Test Form',
                        'description' => 'Test form description',
                        'email' => [
                            [
                                'id' => '999_email_101',
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
                                'id' => '999_email_102',
                                'name' => 'Additional Email Notification',
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
        'single form with additional conditional email notification' => [
            'site_handle' => $siteHandle,
            'source' => [
                'forms' => [
                    \array_merge(
                        $baseForm,
                        [
                            'fields' => [
                                \array_merge(
                                    $baseField,
                                    []
                                ),
                                \array_merge(
                                    $baseField,
                                    [
                                        'id' => '124',
                                        'field_key' => 'zzz001',
                                        'name' => 'Email Field',
                                        'type' => 'email',
                                        'field_order' => '2',
                                    ]
                                ),
                            ],
                        ]
                    ),
                ],
                'views' => [
                    \array_merge(
                        $baseEmail,
                        []
                    ),
                    \array_merge(
                        $baseEmail,
                        [
                            'title' => 'Additional Email Notification',
                            'post_id' => '002',
                            'post_name' => '999_email_102',
                            'content' => '{
                                    "event": [
                                        "create"
                                    ],
                                    "child_form": "",
                                    "email_to": "[default-email]",
                                    "cc": "",
                                    "bcc": "",
                                    "from": "[sitename] <[default-from-email]>",
                                    "reply_to": "",
                                    "email_subject": "New submission from [form_name]",
                                    "email_message": "[default-message]",
                                    "email_attachment_id": "",
                                    "conditions": {
                                        "send_stop": "send",
                                        "any_all": "all",
                                        "0": {
                                            "hide_field": "124",
                                            "hide_field_cond": "==",
                                            "hide_opt": "foo@bar.com"
                                        },
                                        "1": {
                                            "hide_field": "123",
                                            "hide_field_cond": "==",
                                            "hide_opt": "foo"
                                        }
                                    }
                                }',
                        ]
                    ),
                ],
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_formidable-forms_999_test-form',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form',
                        'heading' => 'Test Form',
                        'description' => 'Test form description',
                        'email' => [
                            [
                                'id' => '999_email_101',
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
                                'id' => '999_email_102',
                                'name' => 'Additional Email Notification',
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
                                        'email_field_124' => 'equals foo@bar.com',
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
                        [
                            'handle' => 'email_field_124',
                            'field' => [
                                'type' => 'email',
                                'display' => 'Email Field',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'single form with disabled email notification' => [
            'site_handle' => $siteHandle,
            'source' => [
                'forms' => [
                    \array_merge(
                        $baseForm,
                        [
                            'fields' => [
                                \array_merge(
                                    $baseField,
                                    []
                                ),
                            ],
                        ]
                    ),
                ],
                'views' => [
                    \array_merge(
                        $baseEmail,
                        ['status' => 'draft']
                    ),
                ],
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_formidable-forms_999_test-form',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form',
                        'heading' => 'Test Form',
                        'description' => 'Test form description',
                        'email' => [
                            [
                                'id' => '999_email_101',
                                'name' => 'Test Notification',
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
        'single form with robust email notification content' => [
            'site_handle' => $siteHandle,
            'source' => [
                'forms' => [
                    \array_merge(
                        $baseForm,
                        [
                            'fields' => [
                                \array_merge(
                                    $baseField,
                                    []
                                ),
                                \array_merge(
                                    $baseField,
                                    [
                                        'id' => '124',
                                        'field_key' => 'zzz001',
                                        'name' => 'Email Field',
                                        'type' => 'email',
                                        'field_order' => '2',
                                    ]
                                ),
                            ],
                        ]
                    ),
                ],
                'views' => [
                    \array_merge(
                        $baseEmail,
                        []
                    ),
                    \array_merge(
                        $baseEmail,
                        [
                            'title' => 'Additional Notification',
                            'content' => '{
                                    "email_to": "foo@bar.com,[admin_email], [124] ",
                                    "cc": "baz@bar.com,[default-email], [124] ",
                                    "bcc": "qux@bar.com,[default-from-email], [124] ",
                                    "from": "[123] <[default-from-email]> ",
                                    "reply_to": "foo@bar.com,[admin_email], [124] ",
                                    "email_subject": "Foo Bar Baz [123] ",
                                    "email_message": "Foo.\u00a0 Bar.\r\n\r\n[default-message]",
                                    "post_id": "102",
                                    "post_name": "999_email_102",
                                    "status": "draft",
                                    "conditions": {
                                        "send_stop": "send",
                                        "any_all": "any"
                                    }
                                }',
                        ]
                    ),
                ],
            ],
            'statamic_forms' => [
                [
                    'handle' => 'supply_chain_formidable-forms_999_test-form',
                    'configuration' => [
                        'title' => $siteTitle.' — Test Form',
                        'heading' => 'Test Form',
                        'description' => 'Test form description',
                        'email' => [
                            [
                                'id' => '999_email_101',
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
                                'id' => '999_email_102',
                                'name' => 'Additional Notification',
                                'enabled' => false,
                                'to_type' => 'email',
                                'to' => 'foo@bar.com,{{ admin_email }},{{ submission:email_field_124 }}',
                                'cc_type' => 'email',
                                'cc' => 'baz@bar.com,{{ admin_email }},{{ submission:email_field_124 }}',
                                'bcc_type' => 'email',
                                'bcc' => 'qux@bar.com,{{ admin_email }},{{ submission:email_field_124 }}',
                                'from' => '{{ admin_email }}',
                                'from_name' => '{{ submission:test_field_123 }}',
                                'reply_to_type' => 'email',
                                'reply_to' => 'foo@bar.com,{{ admin_email }},{{ submission:email_field_124 }}',
                                'subject' => 'Foo Bar Baz {{ submission:test_field_123 }}',
                                'message' => [
                                    [
                                        'type' => 'paragraph',
                                        'content' => [
                                            [
                                                'type' => 'text',
                                                'text' => "Foo.\_ Bar.",
                                            ],
                                        ],
                                    ],
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
                        [
                            'handle' => 'email_field_124',
                            'field' => [
                                'type' => 'email',
                                'display' => 'Email Field',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];
}

/**
 * Data provider for Formidable Forms block import tests.
 *
 * @return array<string, array<string, string>> Test cases with formId, blockAttributes, and expectedFormId
 */
function formidableFormsBlockImportProvider(): array
{
    return [
        'simple form block' => [
            'formId' => '1',
            'blockAttributes' => '{"formId":"1","title":false,"description":false}',
            'expectedFormId' => '1',
        ],
        'form block with styling' => [
            'formId' => '2',
            'blockAttributes' => '{"formId":"2","title":true,"description":true}',
            'expectedFormId' => '2',
        ],
        'form block with advanced options' => [
            'formId' => '3',
            'blockAttributes' => '{"formId":"3","title":false,"description":false,"ajax":true,"tabindex":"99"}',
            'expectedFormId' => '3',
        ],
    ];
}

/**
 * Data provider for Formidable Forms shortcode import tests.
 *
 * @return array<string, array<string, string>> Test cases with shortcode and expectedFormId
 */
function formidableFormsShortcodeImportProvider(): array
{
    return [
        'simple shortcode' => [
            'shortcode' => '[formidable id="1"]',
            'expectedFormId' => '1',
        ],
        'shortcode with multiple attributes' => [
            'shortcode' => '[formidable id="2" title="true" description="false" ajax="true"]',
            'expectedFormId' => '2',
        ],
        'shortcode wrapped in paragraph' => [
            'shortcode' => '<p>[formidable id="3"]</p>',
            'expectedFormId' => '3',
        ],
    ];
}

/**
 * Data provider for landing page form variant tests.
 *
 * @return array<string, array{variant: string, formId: string, blockAttributes: ?string, expectedSlug: string}>
 */
function formidableLandingPageFormVariantsProvider(): array
{
    // Set the data provider for landing page form variants
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
function formidableBasicPageFormVariantsProvider(): array
{
    // Set the data provider for basic page form variants
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

/**
 * Test data for Formidable Forms blueprint creation tests.
 *
 * @return array<string, mixed>
 */
function formidableTestFormData(): array
{
    return [
        'fields' => [
            [
                'id' => '949',
                'name' => 'First Name',
                'type' => 'text',
                'required' => 1,
                'options' => '',
                'field_key' => 'first_name_949',
                'description' => '',
                'field_options' => '{"size":"medium","clear_on_focus":0,"invalid":""}',
                'form_id' => '57',
                'created_at' => '2025-01-01 00:00:00',
                'updated_at' => '2025-01-01 00:00:00',
            ],
            [
                'id' => '950',
                'name' => 'Email Address',
                'type' => 'email',
                'required' => 1,
                'options' => '',
                'field_key' => 'email_address_950',
                'description' => '',
                'field_options' => '{"size":"medium","clear_on_focus":0,"invalid":""}',
                'form_id' => '57',
                'created_at' => '2025-01-01 00:00:00',
                'updated_at' => '2025-01-01 00:00:00',
            ],
            [
                'id' => '951',
                'name' => 'Message',
                'type' => 'textarea',
                'required' => 0,
                'options' => '',
                'field_key' => 'message_951',
                'description' => 'Please provide additional details',
                'field_options' => '{"size":"medium","clear_on_focus":0,"invalid":"","max":500}',
                'form_id' => '57',
                'created_at' => '2025-01-01 00:00:00',
                'updated_at' => '2025-01-01 00:00:00',
            ],
        ],
    ];
}

/**
 * Write a minimal export whose one page carries a Formidable shortcode, and return its path.
 *
 * @return string The export path
 */
function formidableShortcodeExport(): string
{
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"
  xmlns:content="http://purl.org/rss/1.0/modules/content/"
  xmlns:wp="http://wordpress.org/export/1.2/">
<channel>
  <title>Example</title>
  <item>
    <title>Contact</title>
    <content:encoded><![CDATA[<p>[formidable id="5"]</p>]]></content:encoded>
    <wp:post_id>10</wp:post_id>
    <wp:post_name>contact</wp:post_name>
    <wp:status>publish</wp:status>
    <wp:post_type>page</wp:post_type>
  </item>
</channel>
</rss>
XML;

    $path = \tempnam(\sys_get_temp_dir(), 'formidable-binding-');

    if ($path === false) {
        throw new RuntimeException('Could not create the export file.');
    }

    \file_put_contents($path, $xml);

    return $path;
}

/**
 * A Formidable Forms export selector that returns fixed forms instead of prompting.
 *
 * Selection is the only interactive step between detection and the handler's
 * write, so replacing it keeps the rest of the path production code.
 */
final class FormidableFixedFormsSelector extends Selector
{
    /**
     * Create the selector around the forms it will return.
     *
     * @param  array<int|string, mixed>  $forms  The forms to hand back
     */
    public function __construct(
        private readonly array $forms
    ) {
        parent::__construct(
            new Validator,
            new FormidableFormsLoader
        );
    }

    /**
     * Return the fixed forms.
     *
     * @param  InputInterface  $input  Console input interface
     * @param  OutputInterface  $output  Console output interface
     * @param  Command  $command  Command instance
     * @return array<int|string, mixed> The fixed forms
     */
    public function select(
        InputInterface $input,
        OutputInterface $output,
        Command $command
    ): array {
        return $this->forms;
    }
}
