<?php

declare(strict_types=1);

/**
 * Pins this package's examples for the adversarial-review questions (wordpress-importer-archive#1264, wordpress-importer-archive#1320).
 *
 * The questions live in the synced `adversarial-review` rule, and their wording is checked in
 * `UAMS-Web/uams-claude-skills` beside the one copy it guards (`UAMS-Web/uams-claude-skills#33`).
 * What stays here is the repository's own rule, `adversarial-review-in-this-package`, which says
 * where this importer hides a failure. Each case pins a name a reviewer looks for, so rewording the
 * prose passes and dropping an example fails. A pure file read, so it runs on the bare test case.
 *
 * @command  php vendor/bin/pest tests/Unit/AdversarialReviewQuestionsTest.php
 */

/**
 * Read a file from the repository root.
 *
 * @param  string  $path  The path, relative to the repository root
 * @return string The file's contents
 */
function adversarialReviewFile(string $path): string
{
    $contents = file_get_contents(dirname(__DIR__, 2).'/'.$path);

    if ($contents === false) {
        throw new RuntimeException(sprintf('%s could not be read.', $path));
    }

    return $contents;
}

/**
 * Read the repository's own adversarial-review rule.
 *
 * @return string The rule's contents
 */
function adversarialReviewLocalRule(): string
{
    return adversarialReviewFile('.claude/rules/adversarial-review-in-this-package.md');
}

it('names the masking reads this package actually uses', function (): void {
    expect(adversarialReviewLocalRule())->toContain('XpathHelper::getStringValue()', 'ImportWarningManager');
});

it('names the memory a long import loop releases', function (): void {
    expect(adversarialReviewLocalRule())->toContain('unset()', 'gc_collect_cycles()');
});

it('points at the synced rule that asks the questions', function (): void {
    expect(adversarialReviewLocalRule())->toContain('[`adversarial-review`](adversarial-review.md)');
});

it('is a file the shared sync does not manage', function (): void {
    $manifest = adversarialReviewFile('.claude/sync-manifest.jsonc');

    // The control: the manifest lists the synced rule, so an absent entry is a real reading
    expect($manifest)->toContain('".claude/rules/adversarial-review.md"')
        ->not->toContain('adversarial-review-in-this-package');
});
