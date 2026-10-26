<?php

declare(strict_types=1);

use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings\Extractor;

/**
 * The extractor accepts both representations MinervaKB settings arrive in (wordpress-importer-archive#1106).
 *
 * The UAMS fork stores real JSON booleans; the original plugin stores the strings
 * `'true'` and `'false'`, and the migration API returns each faithfully. Against the
 * string form, `restrict_on`'s strict comparisons matched neither branch — so a
 * restricted knowledge base imported unrestricted — and the ten other booleans went
 * through a `(bool)` cast that turns `'false'` into `true`.
 *
 * `extractKnowledgeBaseSettings()` is pure, so the whole surface is covered here
 * without booting anything. The measured sites all have `restrict_on` false, which
 * is why the enabled case is constructed rather than drawn from a fixture: a test
 * built only from what was measured would pass against the unfixed code.
 *
 * @command  php vendor/bin/pest tests/Unit/MinervaKbSettingsExtractorNormalizationTest.php
 */

/**
 * Extract with the given settings against a fixed site name.
 *
 * @param  array<int|string, mixed>  $settings
 * @return array<int|string, mixed>
 */
function extractMinervaKb(array $settings): array
{
    return (new Extractor)->extractKnowledgeBaseSettings($settings, 'Test');
}

// --- restrict_on: the strict comparisons -------------------------------------

it('enables restriction when restrict_on arrives as the string true', function (): void {
    // The enabled case, which no measured site has. Against the unfixed code this
    // set nothing at all, because 'true' === true is false.
    $result = extractMinervaKb(['restrict_on' => 'true', 'restrict_article_role' => '["subscriber"]']);

    expect($result['restriction_enabled'])->toBeTrue();
});

it('disables restriction when restrict_on arrives as the string false', function (): void {
    $result = extractMinervaKb(['restrict_on' => 'false']);

    expect($result['restriction_enabled'])->toBeFalse();
});

it('treats the string and boolean forms of restrict_on identically', function (): void {
    $role = '["subscriber"]';

    expect(extractMinervaKb(['restrict_on' => 'true', 'restrict_article_role' => $role]))
        ->toBe(extractMinervaKb(['restrict_on' => true, 'restrict_article_role' => $role]));

    expect(extractMinervaKb(['restrict_on' => 'false']))
        ->toBe(extractMinervaKb(['restrict_on' => false]));
});

// --- restrict_article_role: the escaped quotes --------------------------------

it('resolves an escaped guest-only role list to the guest-only branch', function (): void {
    // The API form, with literal backslashes. Unfixed, json_decode fails inside
    // isGuestOnlyRole() and the role-based branch runs instead.
    $result = extractMinervaKb(['restrict_on' => 'true', 'restrict_article_role' => '[\"guest\"]']);

    expect($result['restriction_enabled'])->toBeTrue();
    expect($result['restriction_global_query'])->toBeFalse();
});

it('treats the escaped and unescaped role lists identically', function (): void {
    expect(extractMinervaKb(['restrict_on' => 'true', 'restrict_article_role' => '[\"guest\"]']))
        ->toBe(extractMinervaKb(['restrict_on' => 'true', 'restrict_article_role' => '["guest"]']));
});

it('takes the role-based branch for a non-guest role, escaped or not', function (): void {
    $escaped = extractMinervaKb(['restrict_on' => 'true', 'restrict_article_role' => '[\"subscriber\"]']);

    expect($escaped['restriction_enabled'])->toBeTrue();
    expect($escaped['restriction_global_query'])->toBeTrue();
});

// --- the other booleans: the (bool) cast --------------------------------------

it('does not invert a string false into an enabled toggle', function (): void {
    // (bool) 'false' is true in PHP. Unfixed, every one of these imported as enabled.
    $result = extractMinervaKb([
        'add_topic_search' => 'false',
        'show_related_articles' => 'false',
    ]);

    expect($result['topic_search_query'])->toBeFalse();
    expect($result['related_articles_query'])->toBeFalse();
});

it('keeps a string true as an enabled toggle', function (): void {
    $result = extractMinervaKb([
        'add_topic_search' => 'true',
        'show_related_articles' => 'true',
    ]);

    expect($result['topic_search_query'])->toBeTrue();
    expect($result['related_articles_query'])->toBeTrue();
});

it('reads home_sections_switch as enabled when it arrives as the string true', function (): void {
    // This one is compared with !== true rather than cast, so the string form fell
    // through to the disabled branch and enabled the search query by mistake.
    $result = extractMinervaKb(['home_sections_switch' => 'true']);

    expect($result['knowledge_base_search_query'])->toBeFalse();
});

it('leaves a genuine boolean untouched', function (): void {
    $result = extractMinervaKb(['add_topic_search' => false, 'restrict_on' => false]);

    expect($result['topic_search_query'])->toBeFalse();
    expect($result['restriction_enabled'])->toBeFalse();
});

// --- normalize(): unescaping by shape, not by name (wordpress-importer-archive#1118) -------------------

/**
 * Normalize a raw settings array, which is where escaping is undone.
 *
 * Reached by reflection because `normalize()` is private and the members under test are
 * not carried to `extractKnowledgeBaseSettings()`'s output — that method maps a fixed set
 * of keys, and seven of the escaped members are read nowhere yet. Testing through the
 * public surface would therefore assert nothing about them. The same reflection idiom is
 * already used in `UnserializeAllowedClassesTest`.
 *
 * @param  array<int|string, mixed>  $settings
 * @return array<int|string, mixed>
 */
function normalizeMinervaKb(array $settings): array
{
    $method = new ReflectionMethod(Extractor::class, 'normalize');
    $method->setAccessible(true);

    /** @var array<int|string, mixed> $result */
    $result = $method->invoke(new Extractor, $settings);

    return $result;
}

it('unescapes a member beyond restrict_article_role', function (): void {
    // AC 2. `submit_restrict_role` is the exposure named on the ticket: same value shape as
    // `restrict_article_role`, escaped on all three measured sites, and read nowhere yet —
    // so the day someone reads it, `json_decode` rejects the value with no error.
    $result = normalizeMinervaKb(['submit_restrict_role' => '[\"guest\"]']);

    expect($result['submit_restrict_role'])->toBe('["guest"]');
});

it('unescapes a member whose unescaped form is not JSON', function (): void {
    // The case a "parses as JSON once unescaped" rule would miss. `like_message_text` is
    // HTML, so it never parses either way — measured — and a JSON-shaped test would leave it
    // escaped while reporting success.
    $result = normalizeMinervaKb(['like_message_text' => '<i class=\"fa fa-heart\"></i> Like']);

    expect($result['like_message_text'])->toBe('<i class="fa fa-heart"></i> Like');
});

it('unescapes every escaped member in one pass', function (): void {
    // The escaped set tracks which settings a site has CONFIGURED rather than a fixed list
    // of names: eight members on two measured sites, ten on a third. A name list compiled
    // from one site is wrong by construction, which is why this is shape-based.
    $result = normalizeMinervaKb([
        'restrict_article_role' => '[\"guest\"]',
        'submit_restrict_role' => '[\"guest\"]',
        'search_container_image_bg' => '{\"isUrl\":true}',
        'breadcrumbs_image_pattern' => '{\"isUrl\":false}',
    ]);

    expect($result)->toBe([
        'restrict_article_role' => '["guest"]',
        'submit_restrict_role' => '["guest"]',
        'search_container_image_bg' => '{"isUrl":true}',
        'breadcrumbs_image_pattern' => '{"isUrl":false}',
    ]);
});

it('does not corrupt a value that legitimately carries backslashes', function (): void {
    // AC 3, and the hazard the shape-based form carries where the name-based form did not.
    // Neither of these contains an escaped quote, so neither is touched. Measured against an
    // unconditional `stripslashes()`, which turns them into `C:Usersjosh` and `/^d+.d+$/`.
    $result = normalizeMinervaKb([
        'a_windows_path' => 'C:\Users\josh\notes',
        'a_regex' => '/^\d+\.\d+$/',
    ]);

    expect($result['a_windows_path'])->toBe('C:\Users\josh\notes')
        ->and($result['a_regex'])->toBe('/^\d+\.\d+$/');
});

it('leaves a non-string member alone', function (): void {
    // Arrays and booleans reach `normalize()` too; `str_contains()` would raise on them.
    $result = normalizeMinervaKb([
        'home_sections' => [['title' => 'Popular']],
        'already_boolean' => true,
        'a_number' => 42,
    ]);

    expect($result['home_sections'])->toBe([['title' => 'Popular']])
        ->and($result['already_boolean'])->toBeTrue()
        ->and($result['a_number'])->toBe(42);
});

it('still coerces string booleans alongside the unescaping', function (): void {
    // The two halves share one loop now, and the `continue` statements mean a member takes
    // exactly one branch. Asserted together so a refactor cannot silently drop one.
    $result = normalizeMinervaKb([
        'restrict_on' => 'true',
        'show_related_articles' => 'false',
        'restrict_article_role' => '[\"guest\"]',
    ]);

    expect($result['restrict_on'])->toBeTrue()
        ->and($result['show_related_articles'])->toBeFalse()
        ->and($result['restrict_article_role'])->toBe('["guest"]');
});
