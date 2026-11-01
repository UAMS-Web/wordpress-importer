<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File as LaravelFileFacade;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Taxonomy as TaxonomyFacade;
use Statamic\Taxonomies\Taxonomy;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Taxonomies\BaseTaxonomyValidator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Taxonomies\Validators\PeopleGroupValidator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ContentRoot;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;
use UAMSWeb\Aether\WordPressImporter\Tests\Support\LfBufferedOutput;

/**
 * `BaseTaxonomyValidator` checks the terms content references against what its destination
 * holds, creates the missing ones, and reports each step (#102). The structured destinations
 * are covered by `StructuredTermValidationTest`; this file covers the messages, a flat
 * taxonomy, the creation report and the per-run caches.
 *
 * @command  php vendor/bin/pest tests/Integration/BaseTaxonomyValidatorTest.php
 */
uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

beforeEach(function (): void {
    BaseTaxonomyValidator::clearCaches();
    BaseEntryCreator::clearTaxonomyCaches();
});

afterEach(function (): void {
    BaseTaxonomyValidator::clearCaches();
    BaseEntryCreator::clearTaxonomyCaches();
    LaravelFileFacade::deleteDirectory(ContentRoot::taxonomies().'/classified_categories');
});

/**
 * A validator over the flat `classified_categories` taxonomy, with term creation exposed.
 */
final class BaseTaxonomyValidatorTestValidator extends BaseTaxonomyValidator
{
    protected function getTaxonomyHandle(): string
    {
        return 'classified_categories';
    }

    protected function getTaxonomyDisplayName(): string
    {
        return 'Classified Category';
    }

    protected function getContentTypeName(): string
    {
        return 'adverts';
    }

    /**
     * @param  array<int|string, mixed>  $missingTerms
     */
    public function create(array $missingTerms, string $collectionHandle, string $site, OutputInterface $output): void
    {
        $this->createMissingTerms($missingTerms, $collectionHandle, $site, $output);
    }
}

/**
 * Make the flat taxonomy the validator creates terms in.
 */
function baseTaxonomyMake(): void
{
    $taxonomy = TaxonomyFacade::make('classified_categories');
    assert($taxonomy instanceof Taxonomy);
    $taxonomy->save();
}

/**
 * Write a flat term file where the importer would have written it.
 */
function baseTaxonomyTermFile(string $siteSpecificSlug): void
{
    $path = ContentRoot::taxonomies()."/classified_categories/{$siteSpecificSlug}.yaml";
    LaravelFileFacade::ensureDirectoryExists(\dirname($path));
    LaravelFileFacade::put($path, "title: {$siteSpecificSlug}\n");
}

/**
 * One term reference as the content scan records it.
 *
 * @return array<string, list<array<string, string>>>
 */
function baseTaxonomyReference(string $term, string $siteSpecific, string $pageSlug = 'an-advert'): array
{
    return [$term => [['page_slug' => $pageSlug, 'original' => $term, 'site_specific' => $siteSpecific]]];
}

/**
 * Validate the references, and return what the pass printed.
 *
 * @param  array<int|string, mixed>  $termsUsed
 */
function baseTaxonomyValidate(array $termsUsed, ?BaseTaxonomyValidator $validator = null): string
{
    $output = new LfBufferedOutput;
    ($validator ?? new BaseTaxonomyValidatorTestValidator)->validateTerms($termsUsed, 'adverts', 'default', $output);

    return $output->fetch();
}

it('says there is nothing to validate when no term is referenced', function (): void {
    expect(baseTaxonomyValidate([]))->toBe(
        "Validating adverts that reference 'classified_categories' taxonomy terms...\n"
        ."  - No adverts found that reference 'classified_categories' taxonomy terms.\n"
    );
});

it('finds a referenced term in the flat taxonomy\'s directory, nested ones included', function (): void {
    baseTaxonomyTermFile('default_for-sale');
    baseTaxonomyTermFile('default_jobs_nursing');

    expect(baseTaxonomyValidate(baseTaxonomyReference('for-sale', 'default_for-sale') + baseTaxonomyReference('jobs_nursing', 'default_jobs_nursing')))->toBe(
        "Validating adverts that reference 'classified_categories' taxonomy terms...\n"
        ."  - Checking if any taxonomy terms referenced in adverts don't exist in the Statamic 'classified_categories' taxonomy...\n"
        ."      - All Classified Category terms referenced in adverts exist in the Statamic 'classified_categories' taxonomy.\n"
    );
});

it('creates a referenced term the taxonomy does not hold, and reports it', function (): void {
    baseTaxonomyMake();

    $printed = baseTaxonomyValidate(baseTaxonomyReference('for-sale', 'default_for-sale'));

    expect($printed)->toContain("      - Creating 1 missing Classified Category terms...\nCreating missing Classified Category terms...\n")
        ->and($printed)->toContain("  - Used in: adverts > an-advert\nMissing Classified Category terms creation completed.\n")
        ->and($printed)->not->toContain('All Classified Category terms');
});

it('creates a missing term once per run, and checks what the taxonomy held when the run first looked', function (): void {
    baseTaxonomyMake();

    baseTaxonomyValidate(baseTaxonomyReference('for-sale', 'default_for-sale'));
    $again = baseTaxonomyValidate(baseTaxonomyReference('for-sale', 'default_for-sale'));

    // A term written after the first look is still missing to this run
    baseTaxonomyTermFile('default_jobs');
    $later = baseTaxonomyValidate(baseTaxonomyReference('jobs', 'default_jobs'));

    expect($again)->not->toContain('Creating 1 missing')
        ->and($later)->toContain('Creating 1 missing Classified Category terms');
});

it('skips a reference that carries no usable site-specific slug', function (mixed $details): void {
    $printed = baseTaxonomyValidate(['for-sale' => $details]);

    expect($printed)->not->toContain('Creating')
        ->and($printed)->toContain('All Classified Category terms referenced in adverts exist');
})->with([
    'no details' => [[]],
    'details that are not a list' => ['for-sale'],
    'a first detail that is not a map' => [['for-sale']],
    'a slug that is not text' => [[['site_specific' => 5]]],
    'an empty slug' => [[['site_specific' => '']]],
]);

it('names the collection and the page each created term is used on', function (array $missingTerms, string $collection, ?string $used): void {
    baseTaxonomyMake();
    $adverts = CollectionFacade::make('adverts');
    $adverts->title('Classified Adverts');
    $adverts->save();

    $output = new LfBufferedOutput;
    (new BaseTaxonomyValidatorTestValidator)->create($missingTerms, $collection, 'default', $output);
    $printed = $output->fetch();

    expect($printed)->toStartWith("Creating missing Classified Category terms...\n")
        ->and($printed)->toEndWith("Missing Classified Category terms creation completed.\n");

    if ($used === null) {
        expect($printed)->not->toContain('Used in:');
    } else {
        expect($printed)->toContain("  - Used in: {$used}\n");
    }
})->with([
    'a listed usage' => [['for-sale' => [['page_slug' => 'an-advert']]], 'adverts', 'Classified Adverts > an-advert'],
    'a usage given as a map' => [['for-sale' => ['page_slug' => 'an-advert']], 'adverts', 'Classified Adverts > an-advert'],
    'a usage with no page' => [['for-sale' => [['original' => 'for-sale']]], 'adverts', 'Classified Adverts > unknown'],
    'a page that is not text' => [['for-sale' => [['page_slug' => 5]]], 'adverts', 'Classified Adverts > unknown'],
    'a collection that does not exist' => [['for-sale' => [['page_slug' => 'an-advert']]], 'nowhere', 'nowhere > an-advert'],
    'no usage' => [['for-sale' => []], 'adverts', null],
    'a term that is not text' => [[5 => [['page_slug' => 'an-advert']]], 'adverts', null],
    'a term that is not text, then one that is' => [[5 => [['page_slug' => 'skipped']], 'for-sale' => [['page_slug' => 'an-advert']]], 'adverts', 'Classified Adverts > an-advert'],
]);

it('reduces a structured reference to its leaf, keeping its site', function (string $entry, string $reference, bool $missing): void {
    $path = ContentRoot::collectionEntry('people_groups', 'default', $entry);
    LaravelFileFacade::ensureDirectoryExists(\dirname($path));
    LaravelFileFacade::put($path, "id: {$entry}-id\ntitle: {$entry}\n");

    $validator = new PeopleGroupValidator;
    $validator->setContext('entries');
    $printed = baseTaxonomyValidate(baseTaxonomyReference($reference, "default_{$reference}"), $validator);

    expect(\str_contains($printed, 'Creating 1 missing'))->toBe($missing);

    LaravelFileFacade::deleteDirectory(ContentRoot::collections().'/people_groups');
})->with([
    'a single segment' => ['staff', 'staff', false],
    'a nested path, by its leaf' => ['staff', 'leadership_staff', false],
    'a deeper path, by its leaf' => ['staff', 'school_leadership_staff', false],
    'a path whose leaf is another entry' => ['staff', 'staff_leadership', true],
]);
