<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File as LaravelFileFacade;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Taxonomies\BaseTaxonomyValidator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Taxonomies\StructuredTermImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Taxonomies\TaxonomyDispositions;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Taxonomies\Validators\PeopleGroupValidator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ContentRoot;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\TaxonomySlugProcessor;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

/**
 * Tests that the term-validation pass resolves a structured handle against its
 * collection rather than against a taxonomy that does not exist (issue wordpress-importer-archive#1144).
 *
 * `UAMS-Web/uams-statamic-archive#1863` converted `people_groups` and
 * `logos_item_categories` into structured collections. The validation pass kept
 * asking `Taxonomy::find()`, which returns null for a collection, so **every**
 * referenced term was classified missing, creation was attempted, and it failed
 * with `Warning: Taxonomy 'people_groups' does not exist`. No content was lost —
 * {@see StructuredTermImporter}
 * had already created the entries correctly earlier in the same run — so the
 * whole product of the path was a run asserting a loss that did not occur.
 *
 * The two halves fail independently and are asserted separately. `getExistingTerms()`
 * scanned the **taxonomies** directory, which for a structured handle holds
 * nothing; and `createTaxonomyTerm()` then reported the collection as a missing
 * taxonomy. Fixing only the first leaves a genuinely-absent term still reported
 * with the wrong cause, and fixing only the second leaves every term still
 * classified missing.
 *
 * **Why the assertions are about the absence of a string.** The defect never
 * threw and never lost data; its entire signature is a line of console output
 * that misstates what happened. So the negative assertions are the subject
 * rather than a weak proxy for it — and each is paired with a positive one, so a
 * test that stopped exercising the path could not pass by producing no output at
 * all.
 *
 * @command  php vendor/bin/pest tests/Integration/StructuredTermValidationTest.php
 */
uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

beforeEach(function (): void {
    BaseTaxonomyValidator::clearCaches();
    BaseEntryCreator::clearTaxonomyCaches();
});

afterEach(function (): void {
    BaseTaxonomyValidator::clearCaches();
    BaseEntryCreator::clearTaxonomyCaches();
});

/**
 * The site every fixture in this file is written and referenced under.
 */
const STRUCTURED_TERM_SITE = 'health_employee_assistance_program';

/**
 * Write a structured collection entry where the importer would have written it.
 *
 * Mirrors {@see ContentRoot::collectionEntry()} — `<collections>/<collection>/<site>/<slug>.md`
 * — and the bare slug {@see StructuredTermImporter}
 * writes, which carries no hierarchy because the collection's tree does.
 *
 * @param  string  $collectionHandle  The structured collection handle
 * @param  string  $slug  The bare entry slug, as the WordPress term declared it
 * @param  string  $site  The site handle whose directory the entry belongs in
 */
function writeStructuredTermEntry(
    string $collectionHandle,
    string $slug,
    string $site = STRUCTURED_TERM_SITE
): void {
    $path = ContentRoot::collectionEntry($collectionHandle, $site, $slug);

    LaravelFileFacade::ensureDirectoryExists(\dirname($path));
    LaravelFileFacade::put($path, "id: {$slug}-id\nblueprint: people_group\ntitle: ".\ucfirst($slug)."\n");
}

/**
 * Build the `$termsUsed` shape a content creator hands the validator.
 *
 * The key is the term's full hierarchical slug and `site_specific` is that slug
 * with the site prefixed, which is what
 * {@see TaxonomySlugProcessor::addSitePrefixToSlug()}
 * produces and what the validator compares.
 *
 * @param  string  $fullSlug  The term's full slug, ancestors joined with underscores
 * @param  string  $site  The site handle the reference belongs to
 * @return array<string, list<array<string, string>>> One reference in the validator's own shape
 */
function structuredTermReference(
    string $fullSlug,
    string $site = STRUCTURED_TERM_SITE
): array {
    return [
        $fullSlug => [
            [
                'page_slug' => 'people-entries',
                'original' => $fullSlug,
                'name' => \ucwords(\str_replace(['-', '_'], ' ', $fullSlug)),
                'site_specific' => \str_replace('_', '-', $site)."_{$fullSlug}",
            ],
        ],
    ];
}

/**
 * Run the people-group validator over one reference set and return what it wrote.
 *
 * @param  array<string, list<array<string, string>>>  $termsUsed  The references to validate
 * @return string Everything the pass printed
 */
function runPeopleGroupValidation(
    array $termsUsed
): string {
    $output = new BufferedOutput;

    $validator = new PeopleGroupValidator;
    $validator->setContext('entries');
    $validator->validateTerms($termsUsed, 'people', STRUCTURED_TERM_SITE, $output);

    return $output->fetch();
}

it('treats people_groups as a structured destination', function (): void {
    // The whole fix keys on this, and it is a constant rather than a lookup, so
    // a reader meeting the assertions below can see which branch they exercise.
    expect(TaxonomyDispositions::isStructuredDestination('people_groups'))->toBeTrue()
        ->and(TaxonomyDispositions::isStructuredDestination('logos_item_categories'))->toBeTrue()
        ->and(TaxonomyDispositions::isStructuredDestination('classified_categories'))->toBeFalse();
});

it('does not report an imported structured term as missing', function (): void {
    // The term pass has already created this entry, which is the whole point:
    // the run used to announce it missing anyway.
    writeStructuredTermEntry('people_groups', 'staff');

    $printed = runPeopleGroupValidation(structuredTermReference('staff'));

    expect($printed)
        ->not->toContain("Taxonomy 'people_groups' does not exist")
        ->not->toContain('Creating 1 missing people group terms')
        ->and($printed)->toContain("All people group terms referenced in people entries exist in the Statamic 'people_groups' taxonomy");
});

it('resolves a nested structured reference against the leaf entry the tree holds', function (): void {
    // A structured collection stores the leaf slug and keeps the hierarchy in
    // its tree, while the reference carries the whole underscore-joined path.
    // Compared as written the two never match, so a child term would be
    // reported missing even with the directory fixed.
    writeStructuredTermEntry('people_groups', 'staff');

    $printed = runPeopleGroupValidation(structuredTermReference('leadership_staff'));

    expect($printed)
        ->not->toContain("Taxonomy 'people_groups' does not exist")
        ->and($printed)->toContain('All people group terms referenced in people entries exist');
});

it('keeps a structured term scoped to its own site', function (): void {
    // The reference belongs to one site and the entry to another, so this must
    // still be a miss. Without the site prefix the leaf match would be global,
    // and every site would satisfy every other site's references.
    writeStructuredTermEntry('people_groups', 'staff', 'another_site');

    $printed = runPeopleGroupValidation(structuredTermReference('staff'));

    expect($printed)->toContain('Creating 1 missing people group terms');
});

it('reports a genuinely absent structured term without blaming a missing taxonomy', function (): void {
    // Nothing is written, so this reference really has no entry. The run should
    // say so — and say which shape it looked in, since the collection is the
    // thing that is missing an entry and the taxonomy was never the question.
    $printed = runPeopleGroupValidation(structuredTermReference('ghost'));

    expect($printed)
        ->not->toContain("Taxonomy 'people_groups' does not exist")
        ->and($printed)->toContain('Creating 1 missing people group terms')
        ->and($printed)->toContain("cannot create 'health-employee-assistance-program_ghost'")
        ->and($printed)->toContain('Structured terms are created by the term pass');
});

it('names the collection when the destination exists but the entry does not', function (): void {
    // With the collection present the two failures are distinguishable, and they
    // need different fixes: import the term, versus configure the destination.
    CollectionFacade::make('people_groups')->save();

    $printed = runPeopleGroupValidation(structuredTermReference('ghost'));

    expect($printed)
        ->toContain("the 'people_groups' collection has no entry for it")
        ->not->toContain('exists as neither a taxonomy nor a collection');
});

it('says so when the handle is neither a taxonomy nor a collection', function (): void {
    // No collection is created here, so the destination is absent in both
    // shapes. The loss is still reported rather than passed over in silence.
    $printed = runPeopleGroupValidation(structuredTermReference('ghost'));

    expect($printed)->toContain("'people_groups' exists as neither a taxonomy nor a collection");
});

it('leaves a flat taxonomy reading the taxonomies directory', function (): void {
    // The control for over-reach. `classified_categories` is not a structured
    // destination, so it must keep both the taxonomy-directory lookup and the
    // original missing-taxonomy message — a fix that silenced this one would
    // have silenced a true report. `post_tags` served here until it became a
    // collection (wordpress-importer-archive#1220), which left no concrete flat validator, so the
    // control is an anonymous one over a handle that is still flat.
    $output = new BufferedOutput;

    $validator = new class extends BaseTaxonomyValidator
    {
        /**
         * The flat Statamic taxonomy this control validates.
         */
        protected function getTaxonomyHandle(): string
        {
            return 'classified_categories';
        }

        /**
         * The name used in console messages.
         */
        protected function getTaxonomyDisplayName(): string
        {
            return 'Classified Category';
        }

        /**
         * The content type named in console messages.
         */
        protected function getContentTypeName(): string
        {
            return 'classified entries';
        }
    };
    $validator->validateTerms(
        structuredTermReference('news'),
        'classifieds',
        STRUCTURED_TERM_SITE,
        $output
    );

    $printed = $output->fetch();

    expect($printed)
        ->toContain("Taxonomy 'classified_categories' does not exist")
        ->not->toContain('Structured terms are created by the term pass');
});
