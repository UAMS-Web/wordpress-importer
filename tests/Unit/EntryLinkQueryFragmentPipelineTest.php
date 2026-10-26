<?php

declare(strict_types=1);

/**
 * Pipeline test for query/fragment preservation on resolved entry links (#3).
 *
 * Where {@see EntryReferenceQueryFragmentOrderTest} pins the leaf helper, this
 * drives the *public* {@see LinkProcessor::processUrlString()} entry point the
 * import uses for `<a>` links — parse (`parseUrlComponents`) → same-site &
 * kb-link detection → `convertKbLink()` → `generateEntryReference()` — and
 * asserts the emitted `statamic://entry::{id}` reference carries the query
 * before the fragment for every suffix combination.
 *
 * The kb-link path is used because it resolves through the seedable
 * {@see KbLinkRegistry} with a string id, so the whole chain runs without a
 * booted Statamic app. The generic page-link path (`convertInternalPageLink()`)
 * shares the identical `parseUrlComponents()`/`generateEntryReference()` pair,
 * so the suffix ordering it emits is covered by the same assertions; its
 * EntryFinder lookup needs real entries and is exercised by the host app's
 * import suite.
 *
 * Standalone (no host Statamic app required):
 *
 * @command  php vendor/bin/pest tests/Unit/EntryLinkQueryFragmentPipelineTest.php
 */

use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\KbLinkRegistry;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;

/**
 * Base blog URL for the fixture links. Trailing-slash-less so the same-site
 * check lines up (see {@see RedirectRuleImportTest} BASE_BLOG_URL rationale).
 */
const BASE = 'https://kb-verification.uams.test';

/**
 * The kb post slug seeded into the registry and linked to in the fixtures.
 */
const SLUG = 'wt3-kb-article';

/**
 * The Statamic entry id the registry resolves the slug to.
 */
const ENTRY_ID = '123';

// Reset the shared registry and LinkProcessor caches before each case so the
// query-keyed conversion cache can't leak a suffix across data rows, then seed
// the slug → entry-id mapping the kb-link path resolves against.
beforeEach(function (): void {
    resetLinkProcessorState();

    KbLinkRegistry::registerKbPostMapping(SLUG, ENTRY_ID);
});

afterEach(function (): void {
    resetLinkProcessorState();
});

// The "both" row is the #3 regression guard end-to-end: the pre-fix append
// order emitted `statamic://entry::123#section?utm=foo`.
it('orders the query before the fragment on a resolved entry link', function (string $wordPressUrl, string $expected): void {
    $result = LinkProcessor::processUrlString(
        $wordPressUrl,
        'default',
        assetImporterForBaseUrl(),
        $this->createMock(OutputInterface::class),
    );

    expect($result)->toBe($expected);
})->with(function (): array {
    $base = BASE.'/kb/'.SLUG.'/';
    $ref = 'statamic://entry::'.ENTRY_ID;

    // [wordpress url, expected statamic reference]
    return [
        'no query, no fragment' => [$base, $ref],
        'query only' => [$base.'?utm=foo', $ref.'?utm=foo'],
        'fragment only' => [$base.'#section', $ref.'#section'],
        'both — query precedes fragment' => [$base.'?utm=foo#section', $ref.'?utm=foo#section'],
    ];
});

// The page-content flavour of the fix: process() rewrites the `<a href>` the
// entry reference lands in — exactly what the Bard body serialises — so the
// query must precede the fragment and the malformed `#fragment?query` order
// must never appear.
it('rewrites the query before the fragment in page-body HTML', function (): void {
    $html = '<p>See <a href="'.BASE.'/kb/'.SLUG.'/?utm=foo#section">the article</a> for details.</p>';

    $result = (new LinkProcessor)->process(
        $html,
        'default',
        assetImporterForBaseUrl(),
        $this->createMock(OutputInterface::class),
    );

    expect($result)
        ->toContain('statamic://entry::'.ENTRY_ID.'?utm=foo#section')
        ->and($result)->not->toContain('#section?');
});

/**
 * Build an AssetImporter with only its base blog URL set, bypassing the
 * constructor (which reads `config()` and so needs a booted app). The kb-link
 * path only ever calls `getBaseBlogUrl()` on it.
 */
function assetImporterForBaseUrl(): AssetImporter
{
    $assetImporter = (new ReflectionClass(AssetImporter::class))
        ->newInstanceWithoutConstructor();

    $property = new ReflectionProperty(AssetImporter::class, 'baseBlogUrl');
    $property->setAccessible(true);
    $property->setValue($assetImporter, BASE);

    return $assetImporter;
}

/**
 * Clear the kb registry, the LinkProcessor conversion caches, and any lingering
 * ProcessingContext XML path so each case starts from clean state.
 */
function resetLinkProcessorState(): void
{
    KbLinkRegistry::clear();
    ProcessingContext::clearXmlPath();

    foreach (['processedLinksCache', 'unresolvedLinksCache'] as $name) {
        $property = new ReflectionProperty(LinkProcessor::class, $name);
        $property->setAccessible(true);
        $property->setValue(null, []);
    }
}
