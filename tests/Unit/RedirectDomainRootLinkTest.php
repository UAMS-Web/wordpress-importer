<?php

declare(strict_types=1);

/**
 * Pins that an authored link to a domain-level redirect domain is left
 * unmodified by the import (`#853`, first clause).
 *
 * `#853` decided that the corpus's 146 root links into redirect domains stay as
 * they are: the redirect is a single point of repointing, while a rewrite would
 * trade it for 146 links that break again at the next migration. Every one of
 * those destinations is itself migrating.
 *
 * **This pins behaviour that is already correct rather than changing any.**
 * `isInternalPageLink()` delegates to `AssetUtils::isSameSiteUrl()`, so a link
 * on a different host than the site being imported is external and never
 * reaches `convertInternalPageLink()` — which is the only path that calls
 * `followRedirects()`. Following a redirect *is* a rewrite, so that boundary is
 * exactly what has to hold, and nothing currently asserts it.
 *
 * The value is in the direction of the failure. If a later change widened the
 * same-site test, these links would silently resolve to their redirect
 * destinations and the suite would stay green — the rewrite would look like
 * correct link resolution, because that is what it is everywhere else.
 *
 * **This file covers the root-link clause only.** The asset clause is blocked
 * by `#855` and the content-path clause is not built here; `#853` stays open.
 *
 * Standalone (no host Statamic app required):
 *
 * @command  php vendor/bin/pest tests/Unit/RedirectDomainRootLinkTest.php
 */

use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\KbLinkRegistry;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;

/**
 * The site being imported. The redirect domains under test are all different
 * hosts, which is the property that makes them external.
 */
const REDIRECT_TEST_BASE = 'https://aging.uams.test';

/**
 * Build an AssetImporter carrying only its base blog URL, bypassing the
 * constructor so no booted app is needed.
 */
function redirectDomainAssetImporter(): AssetImporter
{
    $assetImporter = (new ReflectionClass(AssetImporter::class))
        ->newInstanceWithoutConstructor();

    $property = new ReflectionProperty(AssetImporter::class, 'baseBlogUrl');
    $property->setAccessible(true);
    $property->setValue($assetImporter, REDIRECT_TEST_BASE);

    return $assetImporter;
}

/**
 * Clear the registry, the conversion caches and any lingering XML path so one
 * case cannot leak a cached conversion into the next.
 */
function resetRedirectDomainLinkState(): void
{
    KbLinkRegistry::clear();
    ProcessingContext::clearXmlPath();

    foreach (['processedLinksCache', 'unresolvedLinksCache'] as $name) {
        $property = new ReflectionProperty(LinkProcessor::class, $name);
        $property->setAccessible(true);
        $property->setValue(null, []);
    }
}

beforeEach(function (): void {
    resetRedirectDomainLinkState();
});

afterEach(function (): void {
    resetRedirectDomainLinkState();
});

/**
 * Invoke the protected classification helper directly.
 *
 * @param  string  $wordPressUrl  The URL to classify
 * @return bool Whether the processor treats it as an internal page link
 */
function isInternalRedirectDomainLink(
    string $wordPressUrl
): bool {
    $method = new ReflectionMethod(LinkProcessor::class, 'isInternalPageLink');
    $method->setAccessible(true);

    return (bool) $method->invoke(new LinkProcessor, $wordPressUrl, REDIRECT_TEST_BASE);
}

// ---
// The load-bearing assertion, and it has to be this one rather than the
// end-to-end shape below.
//
// At this tier no Statamic app is booted, so `convertInternalPageLink()` cannot
// resolve an entry and returns the link unchanged regardless of how it was
// classified. An end-to-end "the URL comes back identical" assertion therefore
// passes whether or not these domains are treated as internal — verified by
// mutation: hardwiring `AssetUtils::isSameSiteUrl()` to `true` left the
// end-to-end cases green. This pins the classification itself, which is the
// property the clause actually depends on.
// ---
it('classifies a redirect-domain root link as external', function (string $wordPressUrl): void {
    expect(isInternalRedirectDomainLink($wordPressUrl))->toBeFalse();
})->with([
    'agec.org' => ['https://agec.org/'],
    'beapartofthecure.com' => ['https://beapartofthecure.com/'],
    'schmiedingcenter.org' => ['https://schmiedingcenter.org/'],
    'arpoisoncenter.org' => ['https://arpoisoncenter.org/'],
    'methresearch.com' => ['https://methresearch.com/'],
    'uamssouthwest.com' => ['https://uamssouthwest.com/'],
    'uamselink.net' => ['https://uamselink.net/'],
    'www subdomain' => ['https://www.agec.org/'],
]);

// Control for the classifier: a link on the importing host classifies as
// internal, so a build returning `false` unconditionally fails here.
it('still classifies a same-site link as internal', function (): void {
    expect(isInternalRedirectDomainLink(REDIRECT_TEST_BASE.'/about/staff/'))->toBeTrue();
});

// The clause itself: a root link on a redirect domain comes back byte-identical.
it('leaves a root link to a redirect domain unmodified', function (string $wordPressUrl): void {
    $result = LinkProcessor::processUrlString(
        $wordPressUrl,
        'default',
        redirectDomainAssetImporter(),
        $this::createStub(OutputInterface::class),
    );

    expect($result)->toBe($wordPressUrl);
})->with([
    // The eight domains wordpress-importer-archive#778 inventoried, at the shapes the corpus authored
    'agec.org' => ['https://agec.org/'],
    'agec.org, no trailing slash' => ['https://agec.org'],
    'agec.org over http' => ['http://agec.org/'],
    'beapartofthecure.com' => ['https://beapartofthecure.com/'],
    'bepartofthecure.com' => ['https://bepartofthecure.com/'],
    'schmiedingcenter.org' => ['https://schmiedingcenter.org/'],
    'arpoisoncenter.org' => ['https://arpoisoncenter.org/'],
    'methresearch.com' => ['https://methresearch.com/'],
    'uamssouthwest.com' => ['https://uamssouthwest.com/'],
    'uamselink.net' => ['https://uamselink.net/'],
    'www subdomain' => ['https://www.agec.org/'],
]);

// The same links inside page-body HTML, which is the shape the Bard body
// serialises. `processUrlString()` wraps its input in an anchor and unwraps the
// result, so this exercises the rewrite path rather than a second entry point.
it('leaves a root link to a redirect domain unmodified in page-body HTML', function (): void {
    $html = '<p>See <a href="https://agec.org/">the centre</a> and '
        .'<a href="https://schmiedingcenter.org/">Schmieding</a>.</p>';

    $result = (new LinkProcessor)->process(
        $html,
        'default',
        redirectDomainAssetImporter(),
        $this::createStub(OutputInterface::class),
    );

    expect($result)
        ->toContain('href="https://agec.org/"')
        ->toContain('href="https://schmiedingcenter.org/"')
        // The destinations these domains redirect to must never appear
        ->not->toContain('agec.uams.edu')
        ->not->toContain('aging.uams.edu/uams-caregiving');
});

// Control: the assertions above are only meaningful if this pipeline rewrites
// something. A same-site link on the importing host resolves, so a build that
// returned its input unchanged would pass the cases above and fail here.
it('still rewrites a same-site link, so the cases above are not vacuous', function (): void {
    KbLinkRegistry::registerKbPostMapping('an-article', '4242');

    $result = LinkProcessor::processUrlString(
        REDIRECT_TEST_BASE.'/kb/an-article/',
        'default',
        redirectDomainAssetImporter(),
        $this::createStub(OutputInterface::class),
    );

    expect($result)->toBe('statamic://entry::4242');
});
