<?php

declare(strict_types=1);

use Statamic\Contracts\Forms\Form;
use Statamic\Facades\Form as FormFacade;
use UAMSWeb\WordPressImporter\Tests\Support\AdoptedWorkbenchCase;

uses(AdoptedWorkbenchCase::class);

/**
 * Pins that an adopted per-process workbench is emptied (issue wordpress-importer-archive#827).
 *
 * `isolateWorkbench()` empties `workbench-<pid>/{forms,blueprints}` rather than
 * adopting them. Without this file that emptying is a surviving mutant: deleting both
 * `removeContents()` calls leaves the whole suite byte-identical, because
 * `PerProcessWorkbenchTest` asserts only where the directories point, never what is
 * in them.
 *
 * @command  php vendor/bin/pest tests/Integration/AdoptedWorkbenchTest.php
 */
it('empties a workbench left behind by a killed run', function (): void {
    expect(AdoptedWorkbenchCase::$plantsLanded)->toBeTrue();

    expect(\file_exists(AdoptedWorkbenchCase::$plantedForm))->toBeFalse();
    expect(\file_exists(AdoptedWorkbenchCase::$plantedBlueprint))->toBeFalse();
});

it('does not resolve a form the killed run left behind', function (): void {
    expect(AdoptedWorkbenchCase::$plantsLanded)->toBeTrue();

    // ---
    // The reason the emptying matters, stated as the reader meets it: a stale file
    // resolves as a real form. That is the inverse of the concurrent-delete race —
    // `Failed asserting that an instance of Statamic\Forms\Form is null` rather than
    // the reverse — and it is what an adopted workbench actually produces.
    // ---
    $handles = [];

    foreach (FormFacade::all() as $form) {
        if ($form instanceof Form) {
            $handles[] = $form->handle();
        }
    }

    expect($handles)->not->toContain('left_by_a_killed_run');
});
