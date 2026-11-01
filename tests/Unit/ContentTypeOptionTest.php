<?php

declare(strict_types=1);

use UAMSWeb\Aether\WordPressImporter\Console\Commands\ImportWordPress;

/**
 * Tests that the import command exposes a way to name one content type (wordpress-importer-archive#706).
 *
 * A `--no-interaction` run imports exactly one content type — the interactive
 * continuation prompt defaults to "no" as well, so that is the designed unit of
 * work rather than a non-interactive accident. What was missing is that an
 * unattended caller could not *choose* which one, and was told nothing about the
 * rest: a 465-item export with seven supported types imported `kb` alone and
 * exited `0`.
 *
 * The behaviour those two facts produce is asserted end to end against the host
 * app rather than here, because it needs a real export and a booted Statamic.
 * This pins the surface that makes it reachable — the option's existence and its
 * description — which is what a caller scripting a full import reads first.
 *
 * @command  php vendor/bin/pest tests/Unit/ContentTypeOptionTest.php
 */
it('declares a --content-type option', function (): void {
    $signature = (new ReflectionClass(ImportWordPress::class))
        ->getDefaultProperties()['signature'] ?? '';

    expect($signature)->toBeString()
        ->and($signature)->toContain('--content-type=');
});

it('documents that omitting it reports the untouched types and exits non-zero', function (): void {
    // The description is load-bearing: without it, `--help` shows an option whose
    // absence looks harmless, which is exactly the assumption that let a 1-of-7
    // import read as complete.
    $signature = (new ReflectionClass(ImportWordPress::class))
        ->getDefaultProperties()['signature'] ?? '';

    expect($signature)->toBeString()
        ->and($signature)->toContain('reports the rest as untouched');
});

it('no longer carries the unreachable per-iteration tracking from wordpress-importer-archive#389', function (): void {
    // wordpress-importer-archive#389 narrowed the selectable pool so a non-interactive run would advance
    // between content types. It never could: the loop's continuation prompt
    // defaults to false, so iteration two is unreachable and the machinery was
    // dead while its comments described behaviour the command did not have.
    $source = file_get_contents(
        dirname(__DIR__, 2).'/src/Console/Commands/ImportWordPress.php'
    );

    expect($source)->toBeString()
        ->and($source)->not->toContain('processedContentTypes');
});

it('does not advertise nav_menu_item, which the selector refuses', function (): void {
    // `nav_menu_item` is in the command's `$supportedContentTypes` but
    // `ContentTypeSelector` filters it out — menu items ride along with the
    // navigation pass rather than being imported as a content type. Advertising it
    // would hand callers a `--content-type=` value that fails, and would list it as
    // "not imported" on every run that did import everything selectable.
    $source = file_get_contents(
        dirname(__DIR__, 2).'/src/Console/Commands/ImportWordPress.php'
    );

    expect($source)->toBeString()
        ->and($source)->toContain("!== 'nav_menu_item'")
        ->and($source)->toContain('$availableContentTypes');
});
