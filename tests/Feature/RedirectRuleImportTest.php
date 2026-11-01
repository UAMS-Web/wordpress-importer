<?php

declare(strict_types=1);

use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\GlobalSet;
use Statamic\Globals\Variables;
use Statamic\Stache\Query\EntryQueryBuilder;
use Symfony\Component\Console\Output\NullOutput;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Globals\GlobalCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\Aether\WordPressImporter\Tests\Feature\WordPressImportTestCase;

uses(WordPressImportTestCase::class);

/**
 * The Statamic site handle the test imports into. Chosen because it has the
 * `pages` collection and `redirects` global enabled by default and is
 * conventionally treated as a sacrificial site for import tests (mirrors
 * `PostImportTest`).
 */
const REDIRECT_RULE_SITE = 'default';

/**
 * Base URL stamped into the synthesized WXR's `<wp:base_blog_url>`. The value is
 * intentionally trailing-slash-less because {@see AssetUtils::isSameSiteUrl()}
 * expects the next URL character after the base to be `/` — a trailing slash on
 * the base shifts the comparison off-by-one and the same-site check fails.
 */
const REDIRECT_RULE_BASE_BLOG_URL = 'https://redirect-rule-verification.uams.test';

beforeEach(function (): void {
    // Guard the live `content/` submodule against anything this test leaves behind (wordpress-importer-archive#570)
    $this->captureSubmoduleStates();

    // Snapshot the current redirects global before the import mutates it
    $this->redirectsBackup = redirectRuleReadRedirectsForSite(REDIRECT_RULE_SITE);

    // Fresh, typed accumulator for the slugs of the page entries this test
    // seeds, so the afterEach teardown can remove them by slug
    $this->createdEntrySlugs = redirectRuleFreshSlugList();

    // Allocate the temp path for the synthesized WXR file up front so the
    // afterEach teardown can always remove it (the test fills it below)
    $this->xmlPath = $this->createTempFile('redirect_rule_verification_');

    // Defensively delete any rrit- entries left behind by a prior failed run
    // so the EntryFinder lookup is deterministic in the test below
    redirectRuleDeletePageEntriesBySlugPrefix('rrit-', REDIRECT_RULE_SITE);
});

afterEach(function (): void {
    try {
        // Delete each page entry created by the test, by slug
        foreach ($this->createdEntrySlugs as $slug) {
            foreach (redirectRuleAllStatamicEntries() as $entry) {
                if ($entry->collectionHandle() !== 'pages') {
                    continue;
                }

                if (redirectRuleEntrySiteHandle($entry) !== REDIRECT_RULE_SITE) {
                    continue;
                }

                if ($entry->slug() !== $slug) {
                    continue;
                }

                $entry->delete();
            }
        }

        // Restore the redirects global to its pre-test snapshot
        redirectRuleWriteRedirectsForSite(
            REDIRECT_RULE_SITE,
            $this->redirectsBackup
        );

        // Remove the synthesized WXR file from temp storage
        if (file_exists($this->xmlPath)) {
            // Unlink the temp file ignoring failures (best-effort cleanup)
            @unlink($this->xmlPath);
        }
    } catch (Exception $e) {
        // Log cleanup failures without failing the test
        error_log('RedirectRuleImportTest cleanup error: '.$e->getMessage());
    }

    // Last, so it judges what the cleanup above did NOT put back (wordpress-importer-archive#570).
    $this->assertSubmodulesUnchanged();
});

/**
 * End-to-end import test for `redirect_rule` globals.
 *
 * Drives the full pipeline — `XmlParser` → `GlobalCreator` →
 * `RedirectRuleGlobalCreator` → `LinkProcessor` → Statamic `GlobalSet` save —
 * against a synthesized WXR fixture, then reads `content/globals/redirects.yaml`
 * back through the real `GlobalSet` facade to assert that each `resolveTarget()`
 * branch materialises the expected row shape on disk:
 *
 *  - resolved-entry: target URL maps to an imported page entry →
 *    `target_type: 'entry'` + `target_entry: <id>` (no `target_url`).
 *  - external-URL: target host is foreign → `target_type: 'url'` +
 *    `target_url: <raw>`.
 *  - file-asset-URL: target points at a downloadable file →
 *    `target_type: 'url'` + `target_url: <raw>` (no entry resolution).
 *  - regex-target: source is regex AND target carries `$1`/`*`/`^`/… →
 *    `target_type: 'url'` + `target_url: <raw>`, with `use_regex: true`
 *    preserved.
 *
 * @command  php artisan test vendor/uams-web/aether-wordpress-importer/tests/Feature/RedirectRuleImportTest.php
 */
it('writes one row per resolveTarget branch to the redirects global', function (): void {
    // Seed the page entry the fixture's resolvable target maps to. Slug prefix
    // `rrit-` (RedirectRuleImportTest) keeps this distinct from the host app's
    // real `about` page so the EntryFinder lookup is deterministic.
    $aboutEntryId = redirectRuleSeedPageEntry(
        'rrit-about',
        'RRIT About',
        REDIRECT_RULE_SITE
    );

    // Record the seeded slug so the afterEach teardown removes the entry
    $this->createdEntrySlugs[] = 'rrit-about';

    // Assemble the item set: one page (resolvable target) + four redirects,
    // then swap the standard header's hardcoded base blog URL for the
    // verification host so all relative targets resolve as same-site.
    $items = [
        redirectRuleBuildPageItem(
            101,
            'rrit-about',
            'About',
            REDIRECT_RULE_BASE_BLOG_URL
        ),
        redirectRuleBuildRedirectRuleItem(
            201,
            '/old-about',
            REDIRECT_RULE_BASE_BLOG_URL.'/rrit-about/',
            false,
            REDIRECT_RULE_BASE_BLOG_URL
        ),
        redirectRuleBuildRedirectRuleItem(
            202,
            '/old-link',
            'https://external.example.com/page',
            false,
            REDIRECT_RULE_BASE_BLOG_URL
        ),
        redirectRuleBuildRedirectRuleItem(
            203,
            '/old-handbook',
            '/files/handbook.pdf',
            false,
            REDIRECT_RULE_BASE_BLOG_URL
        ),
        redirectRuleBuildRedirectRuleItem(
            204,
            '^/old-news/(.+)',
            '/news/$1',
            true,
            REDIRECT_RULE_BASE_BLOG_URL
        ),
    ];

    $xml = str_replace(
        'https://supplychain.uams.edu/',
        REDIRECT_RULE_BASE_BLOG_URL,
        $this->buildXmlContent($items)
    );

    file_put_contents(
        $this->xmlPath,
        $xml
    );

    // Run the full GlobalCreator → RedirectRuleGlobalCreator pipeline
    redirectRuleRunImport(
        $this->xmlPath,
        REDIRECT_RULE_SITE,
        REDIRECT_RULE_BASE_BLOG_URL
    );

    // Read the saved redirects back through the production GlobalSet facade
    $redirects = redirectRuleReadRedirectsForSite(REDIRECT_RULE_SITE) ?? [];

    // Index rows by source URL for assertion lookup
    $bySource = redirectRuleIndexRedirectsBySource($redirects);

    // ---
    // resolved-entry branch: /old-about → imported `about` page entry.
    // ---

    // The resolvable target row was written
    expect($bySource)->toHaveKey('/old-about');
    $resolvedRow = $bySource['/old-about'];

    // The row switched to the entry shape
    expect($resolvedRow['target_type'])->toBe('entry');

    // The row points at the seeded entry by id
    expect($resolvedRow['target_entry'])->toBe($aboutEntryId);

    // target_url was dropped from the entry-resolved row
    expect($resolvedRow)->not->toHaveKey('target_url');

    // ---
    // external-URL branch: /old-link → foreign host stays a plain URL.
    // ---

    // The external target row was written
    expect($bySource)->toHaveKey('/old-link');
    $externalRow = $bySource['/old-link'];

    // The row kept the URL shape
    expect($externalRow['target_type'])->toBe('url');

    // The row preserved the foreign URL verbatim
    expect($externalRow['target_url'])->toBe('https://external.example.com/page');

    // ---
    // file-asset-URL branch: /old-handbook → /files/handbook.pdf falls through
    // to the URL shape because no asset import is performed in this isolated
    // test (the rewrite would otherwise mutate it).
    // ---

    // The file target row was written
    expect($bySource)->toHaveKey('/old-handbook');
    $fileRow = $bySource['/old-handbook'];

    // The row kept the URL shape (no entry resolution for files)
    expect($fileRow['target_type'])->toBe('url');

    // The row preserved the raw file path
    expect($fileRow['target_url'])->toBe('/files/handbook.pdf');

    // ---
    // regex-target branch: source is regex AND target carries `$1`, so the guard
    // short-circuits LinkProcessor and the raw target stays exactly as authored.
    // ---

    // The regex source row was written
    expect($bySource)->toHaveKey('^/old-news/(.+)');
    $regexRow = $bySource['^/old-news/(.+)'];

    // The use_regex flag was preserved
    expect($regexRow['use_regex'])->toBeTrue();

    // The row kept the URL shape with the raw $1 backreference
    expect($regexRow['target_type'])->toBe('url');
    expect($regexRow['target_url'])->toBe('/news/$1');

    // Confirm we cover all four branches in a single import run
    expect($bySource)->toHaveCount(4);
});

/**
 * A fresh, typed slug accumulator for the entries a single test run seeds.
 *
 * @return list<string>
 */
function redirectRuleFreshSlugList(): array
{
    return [];
}

/**
 * Return the Stache-backed entry query builder from the Entry facade.
 */
function redirectRuleEntryQuery(): EntryQueryBuilder
{
    $query = EntryFacade::query();

    if (! $query instanceof EntryQueryBuilder) {
        throw new RuntimeException('Entry facade query() did not return EntryQueryBuilder.');
    }

    return $query;
}

/**
 * Return every Statamic entry visible to the Entry facade query.
 *
 * @return list<StatamicEntry>
 */
function redirectRuleAllStatamicEntries(): array
{
    $entries = redirectRuleEntryQuery()->get();

    if (! is_iterable($entries)) {
        return [];
    }

    $result = [];

    foreach ($entries as $entry) {
        if ($entry instanceof StatamicEntry) {
            $result[] = $entry;
        }
    }

    return $result;
}

/**
 * Return the site handle for an entry when available.
 */
function redirectRuleEntrySiteHandle(StatamicEntry $entry): ?string
{
    $site = $entry->site();

    if (! is_object($site) || ! method_exists($site, 'handle')) {
        return null;
    }

    $handle = $site->handle();

    return is_string($handle) ? $handle : null;
}

/**
 * Normalize redirect rows to string-keyed arrays for assertions.
 *
 * @param  array<mixed>  $redirects
 * @return array<int, array<string, mixed>>
 */
function redirectRuleNormalizeRedirectRows(array $redirects): array
{
    $normalized = [];

    foreach ($redirects as $row) {
        if (! is_array($row)) {
            continue;
        }

        $normalizedRow = [];

        foreach ($row as $key => $value) {
            if (is_string($key)) {
                $normalizedRow[$key] = $value;
            }
        }

        $normalized[] = $normalizedRow;
    }

    return $normalized;
}

/**
 * Index redirect rows by their source URL.
 *
 * @param  array<int, array<string, mixed>>  $redirects
 * @return array<string, array<string, mixed>>
 */
function redirectRuleIndexRedirectsBySource(array $redirects): array
{
    $bySource = [];

    foreach ($redirects as $row) {
        $source = $row['source'] ?? null;

        if (! is_string($source)) {
            continue;
        }

        $bySource[$source] = $row;
    }

    return $bySource;
}

/**
 * Removes every `pages` entry on the given site whose slug starts with
 * $prefix. Used by beforeEach to clear stale fixtures from prior failed runs.
 *
 * @param  string  $prefix  The slug prefix to match
 * @param  string  $site  The Statamic site handle to clean up
 */
function redirectRuleDeletePageEntriesBySlugPrefix(
    string $prefix,
    string $site
): void {
    // Walk every entry and delete matches on the test site
    foreach (redirectRuleAllStatamicEntries() as $entry) {
        if ($entry->collectionHandle() !== 'pages') {
            continue;
        }

        if (redirectRuleEntrySiteHandle($entry) !== $site) {
            continue;
        }

        $entrySlug = $entry->slug();

        if (! is_string($entrySlug)) {
            continue;
        }

        // Skip entries whose slug doesn't begin with the prefix
        if (! str_starts_with($entrySlug, $prefix)) {
            continue;
        }

        // Delete the stale entry to keep the lookup deterministic
        $entry->delete();
    }
}

/**
 * Looks up the redirects global for the given site and returns its stored array
 * (or null when the localization carries no value yet).
 *
 * @param  string  $site  The Statamic site handle to read from
 * @return ?array<int, array<string, mixed>> The redirects array, or null
 */
function redirectRuleReadRedirectsForSite(string $site): ?array
{
    // Resolve the redirects global by handle
    $global = GlobalSet::findByHandle('redirects');

    // Guard against the global being absent in the host environment
    if ($global === null) {
        return null;
    }

    // Pull the variables localization for the target site
    $variables = $global->in($site);

    // Guard against the global not being enabled for this site
    if (! $variables instanceof Variables) {
        return null;
    }

    // Return the redirects array (null when the site has no value yet)
    $redirects = $variables->get('redirects');

    if ($redirects === null) {
        return null;
    }

    if (! is_array($redirects)) {
        return null;
    }

    return redirectRuleNormalizeRedirectRows($redirects);
}

/**
 * Writes the redirects array back to the given site's global localization, used
 * by the afterEach teardown to restore the pre-test snapshot.
 *
 * @param  string  $site  The Statamic site handle to write to
 * @param  ?array<int, array<string, mixed>>  $redirects  Snapshot to restore (null treated as empty)
 */
function redirectRuleWriteRedirectsForSite(
    string $site,
    ?array $redirects
): void {
    // Resolve the redirects global by handle
    $global = GlobalSet::findByHandle('redirects');

    // Nothing to restore if the global is missing entirely
    if ($global === null) {
        return;
    }

    // Pull the variables localization for the target site
    $variables = $global->in($site);

    // Skip when the site doesn't have the global enabled
    if (! $variables instanceof Variables) {
        return;
    }

    // Set the redirects array back to its pre-test value (null → [])
    $variables->set(
        'redirects',
        $redirects ?? []
    );

    // Persist the restored value to disk
    $variables->save();
}

/**
 * Creates a minimal `pages` entry on the given site so that `LinkProcessor` can
 * resolve a redirect target URL to a real Statamic entry id.
 *
 * @param  string  $slug  The slug + URL segment for the entry
 * @param  string  $title  The entry title
 * @param  string  $site  The Statamic site handle to seed into
 * @return string The newly-created Statamic entry id
 */
function redirectRuleSeedPageEntry(
    string $slug,
    string $title,
    string $site
): string {
    // Build a minimal pages entry on the test site
    $entry = EntryFacade::make();

    if (! $entry instanceof StatamicEntry) {
        throw new RuntimeException('Entry facade make() did not return a Statamic entry.');
    }

    $entry->collection('pages');
    $entry->locale($site);
    $entry->slug($slug);
    $entry->data(
        [
            'title' => $title,
        ]
    );

    // Persist the entry so LinkProcessor's EntryFinder can locate it
    $entry->save();

    // Return the generated entry id for assertion comparisons
    $entryId = $entry->id();

    if (! is_string($entryId)) {
        throw new RuntimeException('Seeded page entry id was not a string.');
    }

    return $entryId;
}

/**
 * Constructs the dependency graph the import command builds at runtime and runs
 * the redirect_rule global creation against the fixture.
 *
 * @param  string  $xmlPath  Path to the synthesized WXR fixture
 * @param  string  $site  The Statamic site handle to import into
 * @param  string  $baseBlogUrl  The base blog URL the fixture URLs resolve against
 */
function redirectRuleRunImport(
    string $xmlPath,
    string $site,
    string $baseBlogUrl
): void {
    // Parse the fixture with the production XmlParser
    $xmlParser = new XmlParser;
    $xmlParser->parse($xmlPath);

    // Build an AssetImporter scoped to the fixture's base blog URL so
    // LinkProcessor's same-site check treats fixture URLs as internal. The
    // constant already excludes the trailing slash because the same-site check
    // is off-by-one with one (see REDIRECT_RULE_BASE_BLOG_URL docblock).
    $assetImporter = new AssetImporter;
    $assetImporter->setBaseBlogUrl($baseBlogUrl);

    // Silent console output — the assertions don't care about progress logs
    $output = new NullOutput;

    // Construct the supporting utilities the GlobalCreator depends on
    $metadataMapper = new MetadataMapper;
    $contentProcessor = new ContentProcessor;

    // Wire up the coordinator and pick the deterministic overwrite path
    $globalCreator = new GlobalCreator(
        $metadataMapper,
        $contentProcessor
    );
    $globalCreator->setGlobalOverwriteBehavior('Overwrite matching sets');

    // Seed the ProcessingContext with the fixture path so LinkProcessor's
    // internal-link resolution can read page metadata from the XML
    ProcessingContext::setXmlPath($xmlPath);

    try {
        // Run the same call ImportWordPress.php makes at runtime
        $globalCreator->createGlobals(
            $site,
            'redirect_rule',
            'redirects',
            $xmlParser,
            $assetImporter,
            $output
        );
    } finally {
        // Clear the ProcessingContext so test isolation isn't compromised
        ProcessingContext::clearXmlPath();
    }

    // Free locals defined in this code path before returning
    unset(
        $xmlParser,
        $assetImporter,
        $output,
        $metadataMapper,
        $contentProcessor,
        $globalCreator
    );
}

/**
 * Builds a minimal `<item>` of post_type=page that LinkProcessor can match
 * against a redirect target URL. The `<link>` is the resolution key — the
 * redirect's `_redirect_rule_to` must match this path (relative to the base blog
 * URL) for the entry branch to fire.
 *
 * @param  int  $postId  The WordPress post id
 * @param  string  $slug  The slug + URL segment
 * @param  string  $title  The page title
 * @param  string  $baseBlogUrl  The base blog URL the item links resolve against
 * @return string The complete `<item>` XML
 */
function redirectRuleBuildPageItem(
    int $postId,
    string $slug,
    string $title,
    string $baseBlogUrl
): string {
    return '        <item>
            <title><![CDATA['.$title.']]></title>
            <link>'.$baseBlogUrl.'/'.$slug.'/</link>
            <pubDate></pubDate>
            <dc:creator><![CDATA[uamsonline]]></dc:creator>
            <guid isPermaLink="false">'.$baseBlogUrl.'/?page_id='.$postId.'</guid>
            <description></description>
            <content:encoded><![CDATA[]]></content:encoded>
            <excerpt:encoded><![CDATA[]]></excerpt:encoded>
            <wp:post_id>'.$postId.'</wp:post_id>
            <wp:post_date><![CDATA[2024-12-09 16:04:12]]></wp:post_date>
            <wp:post_date_gmt><![CDATA[0000-00-00 00:00:00]]></wp:post_date_gmt>
            <wp:post_modified><![CDATA[2024-12-09 16:04:12]]></wp:post_modified>
            <wp:post_modified_gmt><![CDATA[2024-12-09 22:04:12]]></wp:post_modified_gmt>
            <wp:comment_status><![CDATA[closed]]></wp:comment_status>
            <wp:ping_status><![CDATA[closed]]></wp:ping_status>
            <wp:post_name><![CDATA['.$slug.']]></wp:post_name>
            <wp:status><![CDATA[publish]]></wp:status>
            <wp:post_parent>0</wp:post_parent>
            <wp:menu_order>0</wp:menu_order>
            <wp:post_type><![CDATA[page]]></wp:post_type>
            <wp:post_password><![CDATA[]]></wp:post_password>
            <wp:is_sticky>0</wp:is_sticky>
        </item>';
}

/**
 * Builds a minimal `<item>` of post_type=redirect_rule carrying the postmeta
 * keys `RedirectRuleGlobalCreator` reads: `_redirect_rule_from`,
 * `_redirect_rule_to`, `_redirect_rule_from_regex`, plus status defaults.
 *
 * @param  int  $postId  The WordPress post id
 * @param  string  $sourceUrl  The `_redirect_rule_from` value
 * @param  string  $targetUrl  The `_redirect_rule_to` value
 * @param  bool  $useRegex  Whether the source matches as a regex
 * @param  string  $baseBlogUrl  The base blog URL the item links resolve against
 * @return string The complete `<item>` XML
 */
function redirectRuleBuildRedirectRuleItem(
    int $postId,
    string $sourceUrl,
    string $targetUrl,
    bool $useRegex,
    string $baseBlogUrl
): string {
    // Encode the regex flag as the WordPress 0/1 string the plugin writes
    $regexFlag = $useRegex ? '1' : '0';

    return '        <item>
            <title><![CDATA[Redirect '.$postId.']]></title>
            <link>'.$baseBlogUrl.'/?post_type=redirect_rule&amp;p='.$postId.'</link>
            <pubDate></pubDate>
            <dc:creator><![CDATA[uamsonline]]></dc:creator>
            <guid isPermaLink="false">'.$baseBlogUrl.'/?post_type=redirect_rule&amp;p='.$postId.'</guid>
            <description></description>
            <content:encoded><![CDATA[]]></content:encoded>
            <excerpt:encoded><![CDATA[]]></excerpt:encoded>
            <wp:post_id>'.$postId.'</wp:post_id>
            <wp:post_date><![CDATA[2024-12-09 16:04:12]]></wp:post_date>
            <wp:post_date_gmt><![CDATA[0000-00-00 00:00:00]]></wp:post_date_gmt>
            <wp:post_modified><![CDATA[2024-12-09 16:04:12]]></wp:post_modified>
            <wp:post_modified_gmt><![CDATA[2024-12-09 22:04:12]]></wp:post_modified_gmt>
            <wp:comment_status><![CDATA[closed]]></wp:comment_status>
            <wp:ping_status><![CDATA[closed]]></wp:ping_status>
            <wp:post_name><![CDATA[redirect-'.$postId.']]></wp:post_name>
            <wp:status><![CDATA[publish]]></wp:status>
            <wp:post_parent>0</wp:post_parent>
            <wp:menu_order>30</wp:menu_order>
            <wp:post_type><![CDATA[redirect_rule]]></wp:post_type>
            <wp:post_password><![CDATA[]]></wp:post_password>
            <wp:is_sticky>0</wp:is_sticky>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_redirect_rule_from]]></wp:meta_key>
                <wp:meta_value><![CDATA['.$sourceUrl.']]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_redirect_rule_to]]></wp:meta_key>
                <wp:meta_value><![CDATA['.$targetUrl.']]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_redirect_rule_from_regex]]></wp:meta_key>
                <wp:meta_value><![CDATA['.$regexFlag.']]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_redirect_rule_status_code]]></wp:meta_key>
                <wp:meta_value><![CDATA[301]]></wp:meta_value>
            </wp:postmeta>
        </item>';
}
