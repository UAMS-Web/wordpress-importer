<?php

declare(strict_types=1);

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms\ContentDetector;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms\FormIdExtractor;

/**
 * Detection of Ninja Forms embeds in WXR content (#10).
 *
 * Every fixture string below is taken verbatim from the export corpus audited in
 * `uams-statamic-archive#2046`, not invented, because the two shapes that matter are exactly
 * the ones the corpus varies on:
 *
 * - the block is **self-closing** (`… /-->`) and names its attribute `formID`, capital
 *   D, where Gravity Forms uses `formId`;
 * - the shortcode appears **both quoted and unquoted** — `[ninja_form id='2']` on one
 *   page and `[ninja_form id=3]` on another — so a quote-mandatory pattern like the
 *   Gravity Forms one silently misses real production usages.
 *
 * @command  vendor/bin/pest --testsuite=Unit tests/Unit/NinjaFormsDetectionTest.php
 */

/**
 * The self-closing block, as it appears on `cancer.uams.edu/get-involved`.
 */
const NF_BLOCK = '<!-- wp:ninja-forms/form {"formID":2,"formTitle":"Miracle Stars"} /-->';

/**
 * The single-quoted shortcode, as it appears on `cancer.uams.edu/cure`.
 */
const NF_SHORTCODE_QUOTED = "<!-- wp:html --> [ninja_form id='2'] <!-- /wp:html -->";

/**
 * The unquoted shortcode, from the same site's waiver page.
 */
const NF_SHORTCODE_BARE = '<!-- wp:shortcode --> [ninja_form id=3] <!-- /wp:shortcode -->';

/**
 * Invoke one of ContentDetector's protected pattern matchers.
 *
 * The public entry point takes an XmlParser rather than a string, so the patterns —
 * which are the whole of this subclass — are exercised directly.
 */
function nfMatches(string $method, string $content): bool
{
    $detector = new ContentDetector;
    $reflected = (new ReflectionClass($detector))->getMethod($method);
    $reflected->setAccessible(true);

    return (bool) $reflected->invoke($detector, $content);
}

it('detects a self-closing ninja-forms block', function (): void {
    expect(nfMatches('matchesBlockPattern', NF_BLOCK))->toBeTrue();
});

it('detects the shortcode whether or not the id is quoted', function (string $content): void {
    expect(nfMatches('matchesShortcodePattern', $content))->toBeTrue();
})->with([
    'single-quoted' => [NF_SHORTCODE_QUOTED],
    'unquoted' => [NF_SHORTCODE_BARE],
]);

it('does not claim another plugin\'s embeds', function (string $content): void {
    // Bounds detection on the other side: the three form plugins coexist in this corpus,
    // and a detector that over-matches would prompt for the wrong export file.
    expect(nfMatches('matchesBlockPattern', $content))->toBeFalse();
    expect(nfMatches('matchesShortcodePattern', $content))->toBeFalse();
})->with([
    'gravity forms block' => ['<!-- wp:gravityforms/form {"formId":"5"} /-->'],
    'gravity forms shortcode' => ['[gravityform id="5" title="false"]'],
    'formidable block' => ['<!-- wp:formidable/simple-form {"formId":"7"} /-->'],
    'formidable shortcode' => ['[formidable id=7]'],
    'prose mentioning ninja' => ['<p>He was named a Telehealth Ninja at the forum.</p>'],
]);

it('extracts the form id from a block, reading the formID attribute', function (): void {
    // Ninja Forms spells it `formID`; reading `formId` would yield nothing here.
    expect((new FormIdExtractor)->extractFormIds(NF_BLOCK))->toContain(2);
});

it('extracts a block id even though the block is self-closing', function (): void {
    // The sibling extractors' patterns require `\s*-->` and so match nothing here; every
    // Gravity Forms block in the corpus is self-closing, which is filed as wordpress-importer-archive#632. This
    // pattern allows the optional slash, and this case is what pins that.
    expect((new FormIdExtractor)->extractFormIds('<!-- wp:ninja-forms/form {"formID":9} /-->'))->toContain(9);
});

it('extracts the form id from a shortcode, quoted or not', function (string $content, string $expected): void {
    // Shortcode ids come back as strings (regex capture) while block ids come back as ints
    // (JSON-decoded). That asymmetry is inherited from BaseFormIdExtractor and shared with
    // the sibling plugins, so it is pinned here rather than papered over locally.
    expect((new FormIdExtractor)->extractFormIds($content))->toContain($expected);
})->with([
    'single-quoted' => [NF_SHORTCODE_QUOTED, '2'],
    'unquoted' => [NF_SHORTCODE_BARE, '3'],
]);

it('extracts every distinct id when a page carries several embeds', function (): void {
    $content = NF_BLOCK.NF_SHORTCODE_BARE;

    $ids = (new FormIdExtractor)->extractFormIds($content);

    expect($ids)->toContain(2);
    expect($ids)->toContain('3');
});
