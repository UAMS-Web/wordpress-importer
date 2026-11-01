<?php

declare(strict_types=1);

use Mockery\CompositeExpectation;
use Mockery\Expectation;
use Mockery\ExpectationInterface;
use Mockery\Matcher\Pattern;
use Mockery\MockInterface;
use Statamic\Contracts\Taxonomies\Taxonomy;
use Statamic\Facades\Site as SiteFacade;
use Statamic\Facades\Taxonomy as TaxonomyFacade;
use Statamic\Facades\Term as TermFacade;
use Statamic\Sites\Site;
use Statamic\Taxonomies\Term;
use Statamic\Taxonomies\TermCollection;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Tests\TestCase;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\AudioSeries\AudioSeriesConfig;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\AudioSeries\AudioSeriesManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\TaxonomySlugProcessor;

uses(TestCase::class);

afterEach(function (): void {
    Mockery::close();
});

/**
 * Unit tests for term creation in non-default sites.
 *
 * Tests the Statamic v5.55.0 upgrade fix from commit dc53707:
 *   - Ensures `$term->in($site)` is called before `$term->save()`
 *   - Verifies terms are properly associated with non-default sites
 *
 * This test verifies that all three term creation methods follow the correct
 * pattern for creating terms in non-default sites:
 *   1. BaseEntryCreator::createTaxonomyTermsFromStoredData() (line 969)
 *   2. BaseEntryCreator::createTaxonomyTermFromStoredData() (line 1647)
 *   3. AudioSeriesManager::createAudioSeriesTerm() (line 247)
 *
 * @command  php artisan test vendor/uams-web/aether-wordpress-importer/tests/Feature/TermCreationInNonDefaultSitesTest.php
 */

/**
 * Verifies the Statamic v5.55.0 fix from commit dc53707: the single-term path
 * calls `$term->in($site)` before `$term->save()` so the term is associated
 * with the non-default site.
 */
it('calls in($site) before save when creating a single taxonomy term', function (): void {
    $taxonomyHandle = 'test_taxonomy';
    $termSlug = 'test-term';
    $site = 'non-default-site';
    $output = new BufferedOutput;

    termCreationMockSiteFacade($site);
    termCreationMockTaxonomyFacade($taxonomyHandle);

    termCreationExpectTermFacade('find')
        ->with("{$taxonomyHandle}::{$site}_{$termSlug}")
        ->andReturn(null);

    $methodCallOrder = [];
    $mockTerm = Mockery::mock(Term::class);
    termCreationConfigureTermMockCallOrderTracking(
        $mockTerm,
        $taxonomyHandle,
        Mockery::pattern('/'.preg_quote($site, '/').'[-_].*/'),
        $site,
        $methodCallOrder
    );

    termCreationExpectTermFacade('make')->once()->andReturn($mockTerm);

    termCreationResetBaseEntryCreatorCaches();

    BaseEntryCreator::createTaxonomyTerm(
        $taxonomyHandle,
        $termSlug,
        $site,
        $output
    );

    termCreationAssertInCalledBeforeSave($methodCallOrder);
});

/**
 * Verifies the commit dc53707 fix for the batch term-creation path:
 * `$term->in($site)` is called before `$term->save()`.
 */
it('calls in($site) before save when creating taxonomy terms in batch', function (): void {
    $taxonomyHandle = 'test_taxonomy';
    $site = 'non-default-site';
    $output = new BufferedOutput;
    $siteSpecificData = [
        [
            'original' => 'term-1',
            'name' => 'Term 1',
        ],
    ];

    termCreationMockSiteFacade($site);
    termCreationMockTaxonomyFacade($taxonomyHandle);

    termCreationExpectTermFacade('whereTaxonomy')
        ->with($taxonomyHandle)
        ->andReturn(TermCollection::make());

    $methodCallOrder = [];
    $mockTerm = Mockery::mock(Term::class);
    termCreationConfigureTermMockCallOrderTracking(
        $mockTerm,
        $taxonomyHandle,
        Mockery::pattern('/'.preg_quote($site, '/').'[-_].*/'),
        $site,
        $methodCallOrder
    );

    termCreationExpectTermFacade('make')->once()->andReturn($mockTerm);

    $testCreator = termCreationMakeTestCreator();
    termCreationResetBaseEntryCreatorCaches($testCreator, 'Overwrite');

    $testCreator->test_create_taxonomy_terms_from_stored_data(
        $siteSpecificData,
        $taxonomyHandle,
        $site,
        $output
    );

    termCreationAssertInCalledBeforeSave($methodCallOrder);
});

/**
 * Covers the issue wordpress-importer-archive#46 performance fix: createTaxonomyTermsFromStoredData()
 * preloads all existing terms for the taxonomy with a single whereTaxonomy()
 * query and tests membership in-memory, rather than a per-term find(). An
 * already-existing term is reported as already-present and never passed to
 * TermFacade::make().
 */
it('preloads existing terms via a single whereTaxonomy query instead of per-term find', function (): void {
    $taxonomyHandle = 'test_taxonomy';
    $site = 'non-default-site';
    $output = new BufferedOutput;
    $siteSpecificData = [
        [
            'original' => 'term-1',
            'name' => 'Term 1',
        ],
    ];

    $siteSpecificSlug = TaxonomySlugProcessor::addSitePrefixToSlug('term-1', $site);
    $cacheKey = "{$taxonomyHandle}::{$siteSpecificSlug}";

    termCreationMockSiteFacade($site);
    termCreationMockTaxonomyFacade($taxonomyHandle);

    $existingTerm = Mockery::mock(Term::class);
    termCreationSingleExpectation($existingTerm, 'id')->andReturn($cacheKey);

    termCreationExpectTermFacade('whereTaxonomy')
        ->with($taxonomyHandle)
        ->once()
        ->andReturn(TermCollection::make([$existingTerm]));

    termCreationExpectTermFacade('find')->never();
    termCreationExpectTermFacade('make')->never();

    $testCreator = termCreationMakeTestCreator();
    termCreationResetBaseEntryCreatorCaches($testCreator, 'Skip matching terms');

    $testCreator->test_create_taxonomy_terms_from_stored_data(
        $siteSpecificData,
        $taxonomyHandle,
        $site,
        $output
    );

    // Existing terms are reported as already-present, never re-created.
    expect($output->fetch())->toContain('already exists');
});

/**
 * Verifies the commit dc53707 fix for
 * AudioSeriesManager::createAudioSeriesTerm(): `$term->in($site)` is called
 * before `$term->save()`.
 */
it('calls in($site) before save when creating an audio series term', function (): void {
    $taxonomyHandle = 'audio_series';
    $termSlug = 'test-series';
    $site = 'non-default-site';
    $output = new BufferedOutput;

    $mockConfig = Mockery::mock(AudioSeriesConfig::class);
    expect($mockConfig)->toBeInstanceOf(AudioSeriesConfig::class);

    if (! $mockConfig instanceof AudioSeriesConfig) {
        throw new RuntimeException('Expected a mocked AudioSeriesConfig instance.');
    }

    // Stub every getter `createAudioSeriesTerm()` reads, and no more. The mock is
    // strict, so a seventh getter added to the manager raises here rather than
    // being absorbed as null — which is how wordpress-importer-archive#513's `getSubtype()` was caught.
    termCreationSingleExpectation($mockConfig, 'getTermSlug')->andReturn($termSlug);
    termCreationSingleExpectation($mockConfig, 'getTaxonomyHandle')->andReturn($taxonomyHandle);
    termCreationSingleExpectation($mockConfig, 'getTermName')->andReturn('Test Series');
    termCreationSingleExpectation($mockConfig, 'getTermBlueprint')->andReturn($taxonomyHandle);
    termCreationSingleExpectation($mockConfig, 'getSubtype')->andReturn(['radio']);
    termCreationSingleExpectation($mockConfig, 'getDescription')->andReturn('A test audio series.');

    termCreationExpectTermFacade('find')
        ->with("{$taxonomyHandle}::{$termSlug}")
        ->andReturn(null);

    $methodCallOrder = [];
    $mockTerm = Mockery::mock(Term::class);
    termCreationConfigureTermMockCallOrderTracking(
        $mockTerm,
        $taxonomyHandle,
        $termSlug,
        $site,
        $methodCallOrder
    );

    termCreationExpectTermFacade('make')->once()->andReturn($mockTerm);

    AudioSeriesManager::createAudioSeriesTerm(
        $mockConfig,
        $site,
        $output
    );

    termCreationAssertInCalledBeforeSave($methodCallOrder);
});

/**
 * Verifies that, after the fix, a term created in a non-default site is
 * correctly associated with that site (createTaxonomyTerm() returns true).
 */
it('associates a created term with the non-default site', function (): void {
    $taxonomyHandle = 'test_taxonomy';
    $termSlug = 'test-term';
    $site = 'non-default-site';
    $output = new BufferedOutput;

    termCreationMockSiteFacade($site);
    termCreationMockTaxonomyFacade($taxonomyHandle);

    termCreationExpectTermFacade('find')
        ->with("{$taxonomyHandle}::{$site}_{$termSlug}")
        ->andReturn(null);

    $mockTerm = Mockery::mock(Term::class);
    termCreationSingleExpectation($mockTerm, 'taxonomy')
        ->with($taxonomyHandle)
        ->once()
        ->andReturnSelf();
    termCreationSingleExpectation($mockTerm, 'slug')
        ->with(Mockery::pattern('/'.preg_quote($site, '/').'[-_].*/'))
        ->once()
        ->andReturnSelf();
    termCreationSingleExpectation($mockTerm, 'in')
        ->with($site)
        ->once()
        ->andReturnSelf();
    termCreationSingleExpectation($mockTerm, 'set')
        ->with(Mockery::any(), Mockery::any())
        ->andReturnSelf();
    termCreationSingleExpectation($mockTerm, 'save')
        ->once()
        ->andReturn(true);

    termCreationExpectTermFacade('make')->once()->andReturn($mockTerm);

    termCreationResetBaseEntryCreatorCaches();

    $result = BaseEntryCreator::createTaxonomyTerm(
        $taxonomyHandle,
        $termSlug,
        $site,
        $output
    );

    // Term should be created successfully.
    expect($result)->toBeTrue();
});

/**
 * Exposes createTaxonomyTermsFromStoredData() for term-creation tests.
 */
interface TermCreationTaxonomyTermsTestCreator
{
    /**
     * @param  list<array{original: string, name: string}>  $siteSpecificData
     */
    public function test_create_taxonomy_terms_from_stored_data(
        array $siteSpecificData,
        string $statamicTaxonomyHandle,
        string $site,
        OutputInterface $output
    ): void;
}

/**
 * Forwards Mockery facade expectation chains for static analysis.
 */
final class TermCreationFacadeExpectationChain
{
    private Expectation $expectation;

    public function __construct(ExpectationInterface $composite)
    {
        if (! $composite instanceof CompositeExpectation) {
            throw new RuntimeException('Expected a Mockery CompositeExpectation from the facade.');
        }

        $this->expectation = termCreationExtractSingleExpectation($composite);
    }

    public function with(mixed ...$args): self
    {
        $this->expectation->with(...$args);

        return $this;
    }

    public function once(): self
    {
        $this->expectation->once();

        return $this;
    }

    public function never(): self
    {
        $this->expectation->never();

        return $this;
    }

    public function andReturn(mixed ...$args): self
    {
        $this->expectation->andReturn(...$args);

        return $this;
    }
}

/**
 * Return the sole Expectation from a mock shouldReceive() call.
 */
function termCreationSingleExpectation(MockInterface $mock, string $method): Expectation
{
    $composite = $mock->shouldReceive($method);
    expect($composite)->toBeInstanceOf(CompositeExpectation::class);

    if (! $composite instanceof CompositeExpectation) {
        throw new RuntimeException('Expected a Mockery CompositeExpectation from shouldReceive().');
    }

    return termCreationExtractSingleExpectation($composite);
}

/**
 * Unwrap the single Expectation held inside a CompositeExpectation.
 */
function termCreationExtractSingleExpectation(CompositeExpectation $composite): Expectation
{
    $reflection = new ReflectionClass(CompositeExpectation::class);
    $property = $reflection->getProperty('_expectations');
    $property->setAccessible(true);
    $rawExpectations = $property->getValue($composite);

    expect($rawExpectations)->toBeArray();

    if (! is_array($rawExpectations)) {
        throw new RuntimeException('Expected the Mockery _expectations property to be an array.');
    }

    expect($rawExpectations)->toHaveCount(1);

    $expectation = $rawExpectations[0] ?? null;
    expect($expectation)->toBeInstanceOf(Expectation::class);

    if (! $expectation instanceof Expectation) {
        throw new RuntimeException('Expected a Mockery Expectation instance.');
    }

    return $expectation;
}

/**
 * Return a chainable wrapper around a Term facade shouldReceive() call.
 */
function termCreationExpectTermFacade(string $method): TermCreationFacadeExpectationChain
{
    return new TermCreationFacadeExpectationChain(TermFacade::shouldReceive($method));
}

/**
 * Return a chainable wrapper around a Site facade shouldReceive() call.
 */
function termCreationExpectSiteFacade(string $method): TermCreationFacadeExpectationChain
{
    return new TermCreationFacadeExpectationChain(SiteFacade::shouldReceive($method));
}

/**
 * Return a chainable wrapper around a Taxonomy facade shouldReceive() call.
 */
function termCreationExpectTaxonomyFacade(string $method): TermCreationFacadeExpectationChain
{
    return new TermCreationFacadeExpectationChain(TaxonomyFacade::shouldReceive($method));
}

/**
 * Mock SiteFacade::get() for a non-default site handle.
 */
function termCreationMockSiteFacade(string $site): void
{
    $mockSite = Mockery::mock(Site::class);
    termCreationSingleExpectation($mockSite, 'handle')->andReturn($site);
    termCreationSingleExpectation($mockSite, 'name')->andReturn('Non Default Site');

    termCreationExpectSiteFacade('get')
        ->with($site)
        ->andReturn($mockSite);
}

/**
 * Mock TaxonomyFacade::find() for the given taxonomy handle.
 */
function termCreationMockTaxonomyFacade(string $taxonomyHandle): void
{
    $mockTaxonomy = Mockery::mock(Taxonomy::class);
    termCreationExpectTaxonomyFacade('find')
        ->with($taxonomyHandle)
        ->andReturn($mockTaxonomy);
}

/**
 * Reset BaseEntryCreator static caches and optional overwrite behavior.
 */
function termCreationResetBaseEntryCreatorCaches(
    ?BaseEntryCreator $creator = null,
    ?string $overwriteBehavior = null
): void {
    $reflection = new ReflectionClass(BaseEntryCreator::class);

    foreach (['processedTermsCache', 'siteNamesCache'] as $staticProperty) {
        $property = $reflection->getProperty($staticProperty);
        $property->setAccessible(true);
        $property->setValue(null, []);
    }

    if ($overwriteBehavior !== null && $creator !== null) {
        $overwriteProperty = $reflection->getProperty('taxonomyOverwriteBehavior');
        $overwriteProperty->setAccessible(true);
        $overwriteProperty->setValue($creator, $overwriteBehavior);
    }
}

/**
 * Configure a mock term to record taxonomy/slug/in/set/save call order.
 *
 * @param  list<string>  $methodCallOrder
 */
function termCreationConfigureTermMockCallOrderTracking(
    MockInterface $mockTerm,
    string $taxonomyHandle,
    string|Pattern $slugMatcher,
    string $site,
    array &$methodCallOrder
): void {
    termCreationSingleExpectation($mockTerm, 'taxonomy')
        ->with($taxonomyHandle)
        ->once()
        ->andReturnUsing(function () use (&$methodCallOrder, $mockTerm) {
            $methodCallOrder[] = 'taxonomy';

            return $mockTerm;
        });
    termCreationSingleExpectation($mockTerm, 'slug')
        ->with($slugMatcher)
        ->once()
        ->andReturnUsing(function () use (&$methodCallOrder, $mockTerm) {
            $methodCallOrder[] = 'slug';

            return $mockTerm;
        });
    termCreationSingleExpectation($mockTerm, 'in')
        ->with($site)
        ->once()
        ->andReturnUsing(function () use (&$methodCallOrder, $mockTerm) {
            $methodCallOrder[] = 'in';

            return $mockTerm;
        });
    termCreationSingleExpectation($mockTerm, 'set')
        ->with(Mockery::any(), Mockery::any())
        ->andReturnUsing(function () use (&$methodCallOrder, $mockTerm) {
            if (! in_array('set', $methodCallOrder, true)) {
                $methodCallOrder[] = 'set';
            }

            return $mockTerm;
        });
    termCreationSingleExpectation($mockTerm, 'save')
        ->once()
        ->andReturnUsing(function () use (&$methodCallOrder) {
            $methodCallOrder[] = 'save';

            return true;
        });
}

/**
 * Assert in($site) was called before save() in the tracked call order.
 *
 * @param  list<string>  $methodCallOrder
 */
function termCreationAssertInCalledBeforeSave(array $methodCallOrder): void
{
    $inIndex = array_search('in', $methodCallOrder, true);
    $saveIndex = array_search('save', $methodCallOrder, true);

    // in() method should be called.
    expect($inIndex)->not->toBeFalse();

    // save() method should be called.
    expect($saveIndex)->not->toBeFalse();

    if ($inIndex === false || $saveIndex === false) {
        throw new RuntimeException('Both in() and save() must be recorded in the call order.');
    }

    // in($site) should be called before save().
    expect($inIndex)->toBeLessThan($saveIndex);
}

/**
 * Build a concrete BaseEntryCreator subclass exposing the protected
 * createTaxonomyTermsFromStoredData() method for testing.
 *
 * @return TermCreationTaxonomyTermsTestCreator&BaseEntryCreator
 */
function termCreationMakeTestCreator(): TermCreationTaxonomyTermsTestCreator
{
    return new class extends BaseEntryCreator implements TermCreationTaxonomyTermsTestCreator
    {
        public function test_create_taxonomy_terms_from_stored_data(
            array $siteSpecificData,
            string $statamicTaxonomyHandle,
            string $site,
            OutputInterface $output
        ): void {
            $this->createTaxonomyTermsFromStoredData(
                $siteSpecificData,
                $statamicTaxonomyHandle,
                $site,
                $output
            );
        }

        public function createEntries(
            string $site,
            string $collectionHandle,
            XmlParser $xmlParser,
            AssetImporter $assetImporter,
            OutputInterface $output,
            MetadataMapper $metadataMapper,
            ContentProcessor $contentProcessor,
            ?array $minervaKbSettings = null
        ): array {
            return [];
        }

        public function getPostCategories(): array
        {
            return [];
        }

        public function getPostTags(): array
        {
            return [];
        }

        public function hasShowPeople(): bool
        {
            return false;
        }

        public function validateTaxonomies(
            string $collectionHandle,
            string $site,
            OutputInterface $output
        ): void {
            // No-op for testing
        }
    };
}
