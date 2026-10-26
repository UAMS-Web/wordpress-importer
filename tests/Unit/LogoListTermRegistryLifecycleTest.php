<?php

declare(strict_types=1);

/**
 * Pins ownership of the `LogoListParser` term-registry lifecycle (wordpress-importer-archive#529).
 *
 * `EntryCreator::createEntries()` used to call `LogoListParser::resetTermsUsed()`
 * at the end of its own body. `ImportWordPress::runImport()` calls that method and
 * only *then* reads the registry, to hand the recorded terms to
 * `LogosItemCategoriesValidator` and `LogosItemLabelsValidator`. So the registry was
 * emptied before its only consumer ran, both validators received `[]` on every
 * import, and reporting an unresolvable term — the sole thing they do — never
 * happened. Nothing failed; the validation was simply dead.
 *
 * These assertions are deliberately source-level. The defect is an *ordering*
 * relationship between two files, and it produces no observable difference in any
 * unit-testable return value: the buggy and fixed versions both return `void` and
 * both leave a consistent registry once the cycle ends. Reproducing it behaviorally
 * needs a full import against the booted host app, which cannot run in package CI —
 * so the ordering is pinned where it can actually be gated.
 *
 * **Known limitation, accepted deliberately.** A source-level assertion cannot tell a
 * behavior-preserving refactor from a regression: extracting the validator calls into
 * a helper method, or moving the reset into one, would turn this red while the
 * lifecycle stayed correct. That is the price of pinning an ordering the unit tier
 * cannot otherwise observe, and a false red is the safe direction — it asks a human
 * to look, where a false green (which an earlier version of this file produced) hides
 * a dead validation path in production. Re-derive the assertions if the shape of
 * either file genuinely changes; do not weaken them to make a refactor pass.
 *
 * Bare `TestCase`: this reads source files and touches no framework.
 *
 * @command  php vendor/bin/pest tests/Unit/LogoListTermRegistryLifecycleTest.php
 */
$sourceOf = function (string $relativePath): string {
    $path = \dirname(__DIR__, 2).'/'.$relativePath;

    expect(file_exists($path))->toBeTrue("expected {$relativePath} to exist");

    return (string) file_get_contents($path);
};

$entryCreatorPath = 'src/Services/WordPressImport/Entries/EntryCreator.php';
$commandPath = 'src/Console/Commands/ImportWordPress.php';

it('does not reset the term registry anywhere in the entry-creation path', function () use ($sourceOf, $entryCreatorPath): void {
    // ---
    // The regression itself. Reintroducing the reset here empties the registry
    // before ImportWordPress reads it, silently disabling both validators again.
    // Scoped to the whole `Entries/` tree rather than one file, so moving the call
    // into a creator or a helper does not slip through.
    // ---
    expect($sourceOf($entryCreatorPath))->not->toContain('LogoListParser::resetTermsUsed()');

    $entriesDir = \dirname(__DIR__, 2).'/src/Services/WordPressImport/Entries';
    $offenders = [];

    /** @var iterable<SplFileInfo> $files */
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($entriesDir));

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        if (str_contains((string) file_get_contents($file->getPathname()), 'LogoListParser::resetTermsUsed()')) {
            $offenders[] = $file->getFilename();
        }
    }

    // Naming the offender beats a bare false — the next reader gets the file for free.
    expect($offenders)->toBe([]);
});

it('keeps the end-of-cycle reset in the import command', function () use ($sourceOf, $commandPath): void {
    // Removing this would leak one content type's terms into the next cycle.
    expect($sourceOf($commandPath))->toContain('LogoListParser::resetTermsUsed()');
});

it('resets the registry only after the validators have read it', function () use ($sourceOf, $commandPath): void {
    // ---
    // The invariant the fix restores: within the command's content-type loop the
    // reads must precede the reset. A future reordering that hoists the reset above
    // the validator calls reproduces wordpress-importer-archive#529 exactly, without touching EntryCreator.
    // ---
    $source = $sourceOf($commandPath);

    $lastRead = strrpos($source, 'LogoListParser::getTermsUsed()');

    expect($lastRead)->not->toBeFalse('expected the command to read the term registry');

    // ---
    // The assertion has to be that NO reset precedes the final read, not merely that
    // one follows it. Checking only the tail passes when a reset is ADDED above the
    // validator calls and the end-of-cycle one is left in place — which is wordpress-importer-archive#529's
    // exact shape, and an earlier version of this test went green on it.
    // ---
    $beforeLastRead = $lastRead === false ? '' : substr($source, 0, $lastRead);
    $afterLastRead = $lastRead === false ? '' : substr($source, $lastRead);

    expect($beforeLastRead)->not->toContain('LogoListParser::resetTermsUsed()');
    expect($afterLastRead)->toContain('LogoListParser::resetTermsUsed()');
});

it('reads the registry for the logos-item categories', function () use ($sourceOf, $commandPath): void {
    // The categories validator is fed from the registry, and that read may not be
    // dropped. Labels no longer pass through it: the block records only resolved
    // `logos_item_labels` entry ids, which the term pass created (wordpress-importer-archive#1220).
    $source = $sourceOf($commandPath);

    expect($source)->toContain("LogoListParser::getTermsUsed()['logos_item_categories']")
        ->and($source)->not->toContain("LogoListParser::getTermsUsed()['logos_item_labels']");
});
