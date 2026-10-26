<?php

declare(strict_types=1);

use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormPluginCoordinator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\GravityFormsCoordinator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\GravityFormsBlockProcessor;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * Content referencing a form the supplied export lacks is reported (issue wordpress-importer-archive#658).
 *
 * `extractAvailableFormIds()` intersects referenced form IDs against the supplied export
 * and drops the difference **silently**, so a page referencing a form the export does not
 * contain imports with a placeholder note, no form, and no warning. Measured across the
 * corpus: 31 referenced forms across 12 of 133 sites are in that position.
 *
 * This is the consumer the decision on wordpress-importer-archive#658 gave `getFormPluginFormIdsWithPages()`, which
 * had none for Gravity Forms or Formidable Forms. The page slugs are the point — form
 * creation reads processor-recorded IDs, so the only thing extraction can contribute is
 * naming the page that references a form nobody supplied.
 *
 * @command  php vendor/bin/pest tests/Integration/UnsatisfiedFormReferenceTest.php
 */
uses(StatamicTestCase::class);

/**
 * Seed the coordinator's captured references, as detection does from an XmlParser.
 *
 * The key type is `array-key`, not `string`, for the same reason the production method
 * declares it that way: PHP coerces a numeric-string array key to an int, so `'7'` is
 * stored under `7`. Declaring `string` here would be the same lie that caused the strict
 * comparison to report every form as missing.
 *
 * @param  array<array-key, list<string>>  $referenced  Form ID => page slugs
 */
function seedReferencedFormPages(FormPluginCoordinator $coordinator, array $referenced): void
{
    $property = new ReflectionProperty($coordinator, 'referencedFormPages');
    $property->setAccessible(true);
    $property->setValue($coordinator, ['Gravity Forms' => $referenced]);
}

/**
 * Run the import pass and return everything written to the console.
 *
 * Takes the asset importer rather than the test case: `createMock()` is protected, so a
 * plain function cannot call it on a passed-in test instance.
 */
function runFormImportsCapturingOutput(
    FormPluginCoordinator $coordinator,
    AssetImporter $assetImporter
): string {
    $output = new BufferedOutput;

    $coordinator->processFormImports(
        'default',
        $output,
        $assetImporter
    );

    return $output->fetch();
}

afterEach(function (): void {
    app(GravityFormsCoordinator::class)->clearGravityFormsData();
    GravityFormsBlockProcessor::clearFormIds();
});

it('reports a referenced form the supplied export does not contain, naming the page', function (): void {
    $coordinator = app(FormPluginCoordinator::class);

    seedReferencedFormPages($coordinator, [
        '7' => ['clinics/family-medicine'],
        '12' => ['about/contact', 'research/participate'],
    ]);

    // The export contains form 7 but not form 12.
    app(GravityFormsCoordinator::class)->setGravityFormsData([
        ['id' => '7', 'title' => 'Contact', 'fields' => []],
    ]);

    $written = runFormImportsCapturingOutput($coordinator, $this->createMock(AssetImporter::class));

    expect($written)->toContain('1 referenced Gravity Forms form(s) are not in the supplied export')
        ->and($written)->toContain('form 12')
        // The page slugs are the reason extraction exists at all here.
        ->and($written)->toContain('about/contact')
        ->and($written)->toContain('research/participate')
        // The satisfied reference must not be reported.
        ->and($written)->not->toContain('form 7  (');
});

it('says nothing when every reference is satisfied', function (): void {
    $coordinator = app(FormPluginCoordinator::class);

    seedReferencedFormPages($coordinator, ['7' => ['clinics/family-medicine']]);

    app(GravityFormsCoordinator::class)->setGravityFormsData([
        ['id' => '7', 'title' => 'Contact', 'fields' => []],
    ]);

    // Bounding the change: the normal case gains no output at all. A warning that fires on
    // a healthy import is a warning operators learn to skip.
    expect(runFormImportsCapturingOutput($coordinator, $this->createMock(AssetImporter::class)))
        ->not->toContain('not in the supplied export');
});

it('reports every unsatisfied reference when the export contains none of them', function (): void {
    $coordinator = app(FormPluginCoordinator::class);

    seedReferencedFormPages($coordinator, [
        '31' => ['a'],
        '44' => ['b'],
    ]);

    app(GravityFormsCoordinator::class)->setGravityFormsData([
        ['id' => '99', 'title' => 'Something Else', 'fields' => []],
    ]);

    $written = runFormImportsCapturingOutput($coordinator, $this->createMock(AssetImporter::class));

    expect($written)->toContain('2 referenced Gravity Forms form(s) are not in the supplied export')
        ->and($written)->toContain('form 31')
        ->and($written)->toContain('form 44');
});

it('says nothing when no export was supplied at all', function (): void {
    $coordinator = app(FormPluginCoordinator::class);

    seedReferencedFormPages($coordinator, ['7' => ['clinics/family-medicine']]);

    // The operator declined the prompt. They already know no forms are coming, and one
    // line per reference would be noise rather than information.
    app(GravityFormsCoordinator::class)->clearGravityFormsData();

    expect(runFormImportsCapturingOutput($coordinator, $this->createMock(AssetImporter::class)))
        ->not->toContain('not in the supplied export');
});

it('says nothing when the content referenced no forms', function (): void {
    // The negative control. Without it, a report that fired unconditionally would still
    // pass the assertions above.
    $coordinator = app(FormPluginCoordinator::class);

    seedReferencedFormPages($coordinator, []);

    app(GravityFormsCoordinator::class)->setGravityFormsData([
        ['id' => '7', 'title' => 'Contact', 'fields' => []],
    ]);

    expect(runFormImportsCapturingOutput($coordinator, $this->createMock(AssetImporter::class)))
        ->not->toContain('not in the supplied export');
});
