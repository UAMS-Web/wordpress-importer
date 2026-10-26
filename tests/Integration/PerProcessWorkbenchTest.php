<?php

declare(strict_types=1);

use Statamic\Facades\Form as FormFacade;
use Statamic\Fields\BlueprintRepository;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * Pins the per-process Testbench workbench (issue wordpress-importer-archive#827).
 *
 * Forms and blueprints are written to the Testbench application path —
 * `vendor/orchestra/testbench-core/laravel/resources/{forms,blueprints}` — one
 * location per checkout, shared by every process. They are not Stache items, so
 * neither `PreventsSavingStacheItemsToDisk` nor wordpress-importer-archive#828's per-process Stache directory
 * covered them.
 *
 * Measured with `--parallel`, the workbench deliberately NOT cleared between runs:
 * **5 failures in 34 runs before, 0 in 34 after**, and no file reaching the shared
 * path at all.
 *
 * **Without this file the change would be invisible to the gate**, the way wordpress-importer-archive#828's
 * was: the sequential suite passes identically with and without it, because the
 * collision needs two processes. The three assertions are one per way the
 * redirection can silently stop working — the config, the blueprint repository, and
 * the directory not existing when a test writes to it.
 *
 * @command  php vendor/bin/pest tests/Integration/PerProcessWorkbenchTest.php
 */
it('points form writes at a per-process directory', function (): void {
    $forms = config('statamic.forms.forms');

    expect($forms)->toBeString();
    expect($forms)->toEndWith('/workbench-'.\getmypid().'/forms');

    // The shared Testbench path is what the collision contends over
    expect($forms)->not->toContain('testbench-core');
});

it('points blueprint writes at the same per-process directory', function (): void {
    $directory = app(BlueprintRepository::class)->directory();

    expect($directory)->toBeString();
    expect($directory)->toEndWith('/workbench-'.\getmypid().'/blueprints');
    expect($directory)->not->toContain('testbench-core');
});

it('creates both directories, because the emptying reads them', function (): void {
    // ---
    // NOT because a write would otherwise fail — `File::put()` resolves to
    // `AbstractAdapter::put()`, whose first statement is
    // `makeDirectory(pathinfo($path)['dirname'])`, recursively, so both writers
    // create what they need. The mkdir loop is load-bearing for a different reason:
    // `removeContents()` calls `scandir()` unguarded, which raises an `E_WARNING`
    // that PHPUnit turns into an `ErrorException` and aborts `setUp()`.
    // ---
    $forms = config('statamic.forms.forms');
    $blueprints = app(BlueprintRepository::class)->directory();

    if (! \is_string($forms) || ! \is_string($blueprints)) {
        throw new RuntimeException('The workbench directories were not configured.');
    }

    expect(\is_dir($forms))->toBeTrue();
    expect(\is_dir($blueprints))->toBeTrue();
});

it('writes a form into the per-process directory and not the shared one', function (): void {
    // ---
    // The three cases above assert where the directories POINT. This one asserts
    // where a write actually LANDS, which is the change's headline claim and was
    // otherwise pinned by nothing.
    // ---
    $form = FormFacade::make('per_process_probe');
    $form->title('Per Process Probe');
    $form->save();

    $forms = config('statamic.forms.forms');

    if (! \is_string($forms) || $forms === '') {
        throw new RuntimeException('The forms directory was not configured.');
    }

    expect(\is_file($forms.'/per_process_probe.yaml'))->toBeTrue();

    // The shared Testbench path is the one two workers collide over
    $shared = \dirname(__DIR__, 2)
        .'/vendor/orchestra/testbench-core/laravel/resources/forms/per_process_probe.yaml';

    expect(\is_file($shared))->toBeFalse();
});
