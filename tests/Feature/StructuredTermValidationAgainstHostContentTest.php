<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File as LaravelFileFacade;
use PHPUnit\Framework\Assert;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\BaseTaxonomyValidator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\Validators\PeopleGroupValidator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentRoot;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\TaxonomySlugProcessor;

/**
 * Asserts that the term-validation pass does not report the host's OWN
 * `people_groups` entries as missing (issue wordpress-importer-archive#1144).
 *
 * The companion `tests/Integration/StructuredTermValidationTest.php` asserts the
 * same behavior against a synthetic content tree, and that is the test the gate
 * runs. This one exists because the gate's version cannot see the thing the
 * defect actually turned on: whether `ContentRoot::collections()` resolves to
 * where the host really keeps structured terms, under the host's real Stache and
 * its real multi-site layout. A synthetic tree written by the test itself agrees
 * with the code by construction.
 *
 * **Every other host test for this surface mocks the output away.**
 * `UamsPeopleImportTest` passes `$this->createMock(OutputInterface::class)` into
 * `createEntries()`, so the importer's console writes are discarded and the
 * warning this ticket is about cannot be observed there — a run of that file
 * greps clean for the warning string whether or not the defect is present.
 * Asserting on captured output is the whole point of this file.
 *
 * **Read-only by construction.** It calls the validation pass directly rather
 * than running an import, so it creates no entries, writes no terms, and cannot
 * disturb the `content/` submodule. What it reads is whatever the host checkout
 * already holds, so it skips rather than passing vacuously when that is empty.
 *
 * @command  php artisan test vendor/uams-web/wordpress-importer/tests/Feature/StructuredTermValidationAgainstHostContentTest.php
 */
uses(TestCase::class);

beforeEach(function (): void {
    BaseTaxonomyValidator::clearCaches();
    BaseEntryCreator::clearTaxonomyCaches();
});

afterEach(function (): void {
    BaseTaxonomyValidator::clearCaches();
    BaseEntryCreator::clearTaxonomyCaches();
});

/**
 * Find one real `people_groups` entry the host checkout already holds.
 *
 * Returns the site directory and the bare entry slug, which together are what a
 * reference to that term is built from. A named pair is returned rather than a
 * query's first result: the directory listing is sorted, so the same entry is
 * chosen on every run and a failure names the same term twice running.
 *
 * @return ?array{site: string, slug: string} The first entry in sorted order, or null when there is none
 */
function firstHostPeopleGroup(): ?array
{
    $collectionPath = ContentRoot::collections().'/people_groups';

    if (! LaravelFileFacade::exists($collectionPath)) {
        return null;
    }

    $siteDirectories = LaravelFileFacade::directories($collectionPath);
    \sort($siteDirectories);

    foreach ($siteDirectories as $siteDirectory) {
        if (! \is_string($siteDirectory)) {
            continue;
        }

        $files = LaravelFileFacade::files($siteDirectory);
        $slugs = [];

        foreach ($files as $file) {
            $slugs[] = LaravelFileFacade::name($file->getPathname());
        }

        if ($slugs === []) {
            continue;
        }

        \sort($slugs);

        return [
            'site' => \basename($siteDirectory),
            'slug' => $slugs[0],
        ];
    }

    return null;
}

it('does not report the host own people_groups entries as missing', function (): void {
    $group = firstHostPeopleGroup();

    // An empty checkout must skip rather than pass over nothing, which would be
    // a green that asserted the defect away instead of testing it.
    if ($group === null) {
        Assert::markTestSkipped('This checkout holds no people_groups entries, so there is nothing to resolve against.');
    }

    $site = $group['site'];
    $slug = $group['slug'];

    // Build the reference exactly as a people import builds it: the bare term
    // slug as the key, and the site-prefixed form as `site_specific`.
    $termsUsed = [
        $slug => [
            [
                'page_slug' => 'people-entries',
                'original' => $slug,
                'name' => \ucwords(\str_replace(['-', '_'], ' ', $slug)),
                'site_specific' => TaxonomySlugProcessor::addSitePrefixToSlug($slug, $site),
            ],
        ],
    ];

    $output = new BufferedOutput;

    $validator = new PeopleGroupValidator;
    $validator->setContext('entries');
    $validator->validateTerms($termsUsed, 'people', $site, $output);

    $printed = $output->fetch();

    // The warning the ticket is about, named literally so a grep of this file
    // finds it alongside the string it guards.
    expect($printed)
        ->not->toContain("Taxonomy 'people_groups' does not exist")
        ->not->toContain('Creating 1 missing people group terms')
        ->and($printed)->toContain('All people group terms referenced in people entries exist');
});

it('still reports a people group this checkout really does not hold', function (): void {
    // The control. Without it the assertions above are satisfied by a validator
    // that reports nothing at all, and a pass would say nothing about whether
    // the resolution works or merely never runs.
    $termsUsed = [
        'no-such-people-group-1144' => [
            [
                'page_slug' => 'people-entries',
                'original' => 'no-such-people-group-1144',
                'name' => 'No Such People Group 1144',
                'site_specific' => 'default_no-such-people-group-1144',
            ],
        ],
    ];

    $output = new BufferedOutput;

    $validator = new PeopleGroupValidator;
    $validator->setContext('entries');
    $validator->validateTerms($termsUsed, 'people', 'default', $output);

    $printed = $output->fetch();

    expect($printed)
        ->toContain('Creating 1 missing people group terms')
        ->and($printed)->not->toContain("Taxonomy 'people_groups' does not exist");
});
