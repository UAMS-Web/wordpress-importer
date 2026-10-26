<?php

declare(strict_types=1);

/**
 * Unit tests for the URL helpers and target resolution on
 * {@see RedirectRuleGlobalCreator}.
 *
 * These cover the logic that bridges the WordPress redirect target to the
 * Statamic redirect schema:
 *  - `looksLikeTargetPattern()` — regex/wildcard guard that suppresses
 *    entry lookups for pattern-style targets (for example `/news/$1`).
 *  - `parseEntryReferenceId()` — extracts the entry ID out of a
 *    `statamic://entry::{id}` reference returned by
 *    `LinkProcessor::processUrlString()`.
 *  - `resolveTarget()` — driven through each of its four branches using real
 *    `redirect_rule` items from the `academicsenate` WXR fixture, with the
 *    `LinkProcessor` seam stubbed so the host-app-bound `createGlobals()` flow
 *    need not boot Statamic.
 *
 * @command  php vendor/bin/pest tests/Unit/RedirectRuleGlobalCreatorTest.php
 */

use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Globals\Creators\RedirectRuleGlobalCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;

it('detects regex and wildcard pattern tokens so pattern targets skip entry resolution', function (string $target, bool $expected): void {
    // Resolve the private static helper via reflection for direct invocation
    $method = new ReflectionMethod(
        RedirectRuleGlobalCreator::class,
        'looksLikeTargetPattern'
    );

    expect($method->invoke(null, $target))->toBe($expected);
})->with([
    'plain path' => [
        '/about/contact',
        false,
    ],
    'absolute url' => [
        'https://example.uams.edu/about',
        false,
    ],
    'backreference $1' => [
        '/news/$1',
        true,
    ],
    'backreference $9' => [
        '/news/$9/archive',
        true,
    ],
    'glob wildcard' => [
        '/news/announcement/*',
        true,
    ],
    'regex anchor' => [
        '^/legacy/path',
        true,
    ],
    'regex group' => [
        '/news/(.+)',
        true,
    ],
    'character class' => [
        '/news/[abc]',
        true,
    ],
    'escape sequence' => [
        '/news/\\d+',
        true,
    ],
    'alternation' => [
        '/news|/blog',
        true,
    ],
    'plain url with hyphens and dots' => [
        'https://example.uams.edu/news/some-page-title.html',
        false,
    ],
]);

it('parses the entry id from a statamic entry reference, stripping fragment and query', function (string $url, ?string $expectedId): void {
    // Resolve the private static helper via reflection for direct invocation
    $method = new ReflectionMethod(
        RedirectRuleGlobalCreator::class,
        'parseEntryReferenceId'
    );

    expect($method->invoke(null, $url))->toBe($expectedId);
})->with([
    'plain entry reference' => [
        'statamic://entry::abc-123',
        'abc-123',
    ],
    'reference with fragment' => [
        'statamic://entry::abc-123#staff',
        'abc-123',
    ],
    'reference with query' => [
        'statamic://entry::abc-123?utm=foo',
        'abc-123',
    ],
    'reference with fragment and query' => [
        'statamic://entry::abc-123?utm=foo#staff',
        'abc-123',
    ],
    'uuid-style id' => [
        'statamic://entry::550e8400-e29b-41d4-a716-446655440000',
        '550e8400-e29b-41d4-a716-446655440000',
    ],
    'absolute url is not a reference' => [
        'https://example.uams.edu/about',
        null,
    ],
    'relative path is not a reference' => [
        '/about',
        null,
    ],
    'rewritten file url is not a reference' => [
        'https://example.uams.edu/files/_documents/handbook.pdf',
        null,
    ],
    'empty reference returns null' => [
        'statamic://entry::',
        null,
    ],
]);

/**
 * Load the redirect-rule fixture, pull every `redirect_rule` item, and return
 * them keyed by `wp:post_id` so individual rules can be reached by ID in the
 * branch test.
 *
 * The fixture carries the four rules this file asserts on — 1110, 1111, 1112
 * and 1115 — lifted verbatim from a real Academic Senate export so the
 * production `XmlParser` still sees genuine WXR markup and namespaces. It is
 * tracked in this repository rather than sourced from the private `Exports`
 * submodule, which is absent from a clean checkout and would make this test
 * unrunnable in package CI.
 *
 * @return array<int, SimpleXMLElement> Map of post-id to redirect-rule node
 */
function loadAcademicSenateRedirectRules(): array
{
    // Resolve the fixture path relative to this test file (tests/Unit/).
    $fixturePath = __DIR__.'/../Fixtures/wxr/redirect-rules.xml';

    // Guard against accidental fixture relocation
    expect($fixturePath)->toBeFile('Expected academicsenate WXR fixture to be present.');

    // Parse the fixture with the production XmlParser
    $xmlParser = new XmlParser;
    $xmlParser->parse($fixturePath);

    // Pull every redirect_rule item from the parsed document
    $rules = $xmlParser->getItems('redirect_rule');

    // Index the rules by their WordPress post id for direct lookup
    $rulesById = [];
    foreach ($rules as $rule) {
        $postId = (int) XpathHelper::getStringValue(
            $rule,
            'wp:post_id'
        );

        $rulesById[$postId] = $rule;
    }

    return $rulesById;
}

/**
 * Extract `[rawTarget, useRegex]` from a `redirect_rule` SimpleXMLElement using
 * the same XPath queries the production `createGlobals()` applies.
 *
 * @param  SimpleXMLElement  $rule  The `redirect_rule` item node
 * @return array{0: string, 1: bool} Tuple of raw `_redirect_rule_to` value and
 *                                   the `_redirect_rule_from_regex` boolean
 */
function extractTargetFields(SimpleXMLElement $rule): array
{
    // Extract the raw redirect target URL from the rule
    $rawTarget = XpathHelper::getStringValue(
        $rule,
        'wp:postmeta[wp:meta_key="_redirect_rule_to"]/wp:meta_value'
    );

    // Extract the regex match flag from the rule
    $useRegex = XpathHelper::getBoolValue(
        $rule,
        'wp:postmeta[wp:meta_key="_redirect_rule_from_regex"]/wp:meta_value',
        false
    );

    return [
        $rawTarget,
        $useRegex,
    ];
}

/**
 * Test double that swaps the static {@see LinkProcessor::processUrlString()}
 * dependency for a deterministic, programmable map.
 *
 * Tests populate {@see $urlMap} with `rawTarget => processedReturn` entries
 * and read {@see callCountForTarget()} to assert the seam fired (or didn't,
 * in the regex-guard case).
 */
class StubbedRedirectRuleGlobalCreator extends RedirectRuleGlobalCreator
{
    /**
     * Canned `processTargetUrl()` return values, keyed by raw target string.
     *
     * @var array<string, string>
     */
    public array $urlMap = [];

    /**
     * Per-target invocation counter populated by {@see processTargetUrl()}.
     *
     * @var array<string, int>
     */
    private array $callCounts = [];

    /**
     * Returns the canned value for $rawTarget, falling back to $rawTarget
     * itself when no entry has been programmed (mirroring LinkProcessor's
     * pass-through behaviour for unrecognised URLs).
     *
     * @param  string  $rawTarget  The raw `_redirect_rule_to` value
     * @param  string  $site  The Statamic site handle (unused in the stub)
     * @param  AssetImporter  $assetImporter  Unused in the stub
     * @param  OutputInterface  $output  Unused in the stub
     * @return string The programmed return value, or the raw target if none
     */
    protected function processTargetUrl(
        string $rawTarget,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output
    ): string {
        // Record the invocation so tests can assert call counts per target
        $this->callCounts[$rawTarget] = ($this->callCounts[$rawTarget] ?? 0) + 1;

        // Return the canned mapping, or the raw target as a no-op fallback
        return $this->urlMap[$rawTarget] ?? $rawTarget;
    }

    /**
     * Returns how many times {@see processTargetUrl()} was called with
     * $rawTarget — used by tests to assert the regex-guard short-circuit.
     *
     * @param  string  $rawTarget  The raw `_redirect_rule_to` value queried
     * @return int Number of times the seam fired for this target
     */
    public function callCountForTarget(
        string $rawTarget
    ): int {
        return $this->callCounts[$rawTarget] ?? 0;
    }
}

it('resolves each branch of resolveTarget against the academicsenate fixture', function (): void {
    // Load the fixture WXR via XmlParser and index redirect rules by post id
    $rulesById = loadAcademicSenateRedirectRules();

    // Build the stub subclass with a map of canned processTargetUrl returns
    $stub = new StubbedRedirectRuleGlobalCreator;

    // Reach the private resolveTarget via reflection
    $resolveTarget = new ReflectionMethod(
        RedirectRuleGlobalCreator::class,
        'resolveTarget'
    );

    // Build minimal dependencies for the resolveTarget signature
    $assetImporter = $this->createMock(AssetImporter::class);
    $output = $this->createMock(OutputInterface::class);
    $site = 'academicsenate';

    // ---
    // regex-target branch: post 1110 carries use_regex=true and a $1
    // backreference, so resolveTarget must short-circuit before calling the
    // stub and preserve the raw target verbatim.
    // ---
    [$rawTarget1110, $useRegex1110] = extractTargetFields($rulesById[1110]);

    // Sanity-check the fixture matches the branch under test
    expect($useRegex1110)->toBeTrue()
        ->and($rawTarget1110)->toContain('$1');

    $result1110 = $resolveTarget->invoke(
        $stub,
        $rawTarget1110,
        $useRegex1110,
        $site,
        $assetImporter,
        $output
    );

    // The regex-target branch preserves the raw URL and never consults the stub
    expect($result1110)->toBe([
        'target_type' => 'url',
        'target_url' => $rawTarget1110,
    ])->and($stub->callCountForTarget($rawTarget1110))->toBe(0);

    // ---
    // external-URL branch: post 1111. The stub returns the URL unchanged,
    // mirroring LinkProcessor when the target points outside any entry.
    // ---
    [$rawTarget1111, $useRegex1111] = extractTargetFields($rulesById[1111]);
    $stub->urlMap[$rawTarget1111] = $rawTarget1111;

    $result1111 = $resolveTarget->invoke(
        $stub,
        $rawTarget1111,
        $useRegex1111,
        $site,
        $assetImporter,
        $output
    );

    // The URL fall-through keeps the stub-returned value and fires the seam once
    expect($result1111)->toBe([
        'target_type' => 'url',
        'target_url' => $rawTarget1111,
    ])->and($stub->callCountForTarget($rawTarget1111))->toBe(1);

    // ---
    // resolved-entry branch: post 1112. The stub returns a fabricated
    // statamic://entry::… reference, simulating a recognised imported page.
    // ---
    [$rawTarget1112, $useRegex1112] = extractTargetFields($rulesById[1112]);
    $entryId = 'ent-1112-uams-head-start-classroom';
    $stub->urlMap[$rawTarget1112] = "statamic://entry::{$entryId}";

    $result1112 = $resolveTarget->invoke(
        $stub,
        $rawTarget1112,
        $useRegex1112,
        $site,
        $assetImporter,
        $output
    );

    // The resolved-entry branch switches the shape to entry/id and drops the URL
    expect($result1112)->toBe([
        'target_type' => 'entry',
        'target_entry' => $entryId,
    ])->and($result1112)->not->toHaveKey('target_url');

    // ---
    // file-asset-URL branch: post 1115. The stub returns a rewritten /files/…
    // URL, simulating LinkProcessor importing the linked asset and handing back
    // its new Statamic URL.
    // ---
    [$rawTarget1115, $useRegex1115] = extractTargetFields($rulesById[1115]);
    $rewrittenAssetUrl = 'https://academicsenate.uams.test/files/_documents/handbook.pdf';
    $stub->urlMap[$rawTarget1115] = $rewrittenAssetUrl;

    $result1115 = $resolveTarget->invoke(
        $stub,
        $rawTarget1115,
        $useRegex1115,
        $site,
        $assetImporter,
        $output
    );

    // The file-asset branch keeps target_type=url but stores the rewritten value
    expect($result1115)->toBe([
        'target_type' => 'url',
        'target_url' => $rewrittenAssetUrl,
    ]);

    // The stored URL must be the rewritten value, not the raw WordPress target
    $resolvedUrl1115 = is_array($result1115) ? ($result1115['target_url'] ?? null) : null;
    expect($resolvedUrl1115)->not->toBe($rawTarget1115);
});
