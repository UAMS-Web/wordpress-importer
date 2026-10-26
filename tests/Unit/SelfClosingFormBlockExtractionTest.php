<?php

declare(strict_types=1);

/**
 * Regression test for issue wordpress-importer-archive#632.
 *
 * A Gutenberg block with no inner content is written **self-closing** — `{…} /-->`. The
 * Gravity Forms and Formidable Forms block patterns ended `\s*-->`, and `/` is not
 * whitespace, so neither could match one.
 *
 * The exposure was not marginal. Counted across the 259 WXR exports in
 * `wordpress-importer-exports/WordPress/`:
 *
 * | Block | self-closing | paired |
 * | --- | --- | --- |
 * | `wp:gravityforms/form` | **870** | **0** |
 * | `wp:formidable/simple-form` | 1 | 24 |
 * | `wp:ninja-forms/form` | 3 | 0 |
 *
 * So Gravity Forms extracted **no IDs at all** from blocks, and Formidable lost one.
 *
 * It survived because `ContentDetector` uses a looser pattern with no terminator
 * requirement: detection correctly reported the plugin present, and only ID extraction
 * came back empty — the failure sat downstream of a green-looking step.
 *
 * @command  php vendor/bin/pest tests/Unit/SelfClosingFormBlockExtractionTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\FormIdExtractor as FormidableFormIdExtractor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FormIdExtractor as GravityFormIdExtractor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms\FormIdExtractor as NinjaFormIdExtractor;

it('extracts the form ID from a self-closing block', function (string $plugin, string $block, string|int $expected): void {
    $extractor = match ($plugin) {
        'gravity' => new GravityFormIdExtractor,
        'formidable' => new FormidableFormIdExtractor,
        'ninja' => new NinjaFormIdExtractor,
        // Exhaustive for level max: an unknown plugin key is a typo in the dataset, and
        // failing loudly beats silently exercising the wrong extractor.
        default => throw new InvalidArgumentException("unknown plugin: {$plugin}"),
    };

    expect($extractor->extractFormIds($block))->toContain($expected);
})->with([
    // Verbatim shapes from the export corpus.
    'gravity forms, self-closing (870 of 870 in the corpus)' => [
        'gravity',
        '<!-- wp:gravityforms/form {"formId":"2","title":false,"description":false} /-->',
        '2',
    ],
    'formidable, self-closing (1 in the corpus)' => [
        'formidable',
        '<!-- wp:formidable/simple-form {"formId":"17"} /-->',
        '17',
    ],
    'ninja forms, self-closing (3 of 3 in the corpus)' => [
        'ninja',
        '<!-- wp:ninja-forms/form {"formID":3} /-->',
        // Ninja writes the id UNQUOTED, so it decodes to an int where Gravity Forms and
        // Formidable Forms both quote theirs and decode to strings.
        3,
    ],
]);

it('still extracts the form ID from a paired block', function (string $plugin, string $block, string|int $expected): void {
    $extractor = match ($plugin) {
        'gravity' => new GravityFormIdExtractor,
        'formidable' => new FormidableFormIdExtractor,
        'ninja' => new NinjaFormIdExtractor,
        // Exhaustive for level max: an unknown plugin key is a typo in the dataset, and
        // failing loudly beats silently exercising the wrong extractor.
        default => throw new InvalidArgumentException("unknown plugin: {$plugin}"),
    };

    expect($extractor->extractFormIds($block))->toContain($expected);
})->with([
    // Bounding the fix on the other side: making the slash optional must not stop the
    // paired form matching. Formidable's 24 corpus blocks are all paired.
    'gravity forms, paired' => [
        'gravity',
        '<!-- wp:gravityforms/form {"formId":"5"} --><div></div><!-- /wp:gravityforms/form -->',
        '5',
    ],
    'formidable, paired (24 of 25 in the corpus)' => [
        'formidable',
        '<!-- wp:formidable/simple-form {"formId":"9"} --><div></div><!-- /wp:formidable/simple-form -->',
        '9',
    ],
    'ninja forms, paired' => [
        'ninja',
        '<!-- wp:ninja-forms/form {"formID":7} --><div></div><!-- /wp:ninja-forms/form -->',
        7,
    ],
]);

it('extracts every ID when self-closing and paired blocks share a page', function (): void {
    $extractor = new GravityFormIdExtractor;

    $content = '<!-- wp:gravityforms/form {"formId":"11"} /-->'
        .'<p>Between the two.</p>'
        .'<!-- wp:gravityforms/form {"formId":"12"} --><div></div><!-- /wp:gravityforms/form -->';

    $ids = $extractor->extractFormIds($content);

    expect($ids)->toContain('11')
        ->and($ids)->toContain('12');
});

it('does not match a different plugin block', function (): void {
    // The negative control. `\/?` widens the terminator, not the block name — a pattern
    // that started matching its siblings would attribute forms to the wrong plugin, which
    // is worse than extracting none.
    $gravity = new GravityFormIdExtractor;

    expect($gravity->extractFormIds('<!-- wp:ninja-forms/form {"formID":3} /-->'))->toBe([])
        ->and($gravity->extractFormIds('<!-- wp:formidable/simple-form {"formId":"17"} /-->'))->toBe([]);
});

it('still extracts shortcode-embedded form IDs', function (): void {
    // Shortcodes were never affected — asserted so the fix is bounded to blocks.
    expect((new GravityFormIdExtractor)->extractFormIds('[gravityform id="7" title="false"]'))->toContain('7')
        ->and((new NinjaFormIdExtractor)->extractFormIds("[ninja_form id='2']"))->toContain('2');
});
