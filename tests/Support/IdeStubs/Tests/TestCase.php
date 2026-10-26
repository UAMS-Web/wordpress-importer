<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as IlluminateTestCase;

/**
 * IDE and static-analysis stub for {@see TestCase}.
 *
 * The real class lives in the consuming Laravel / Statamic application
 * (`tests/TestCase.php`). That implementation is used when you run
 * `php artisan test …`. This file is not registered in `composer.json`
 * autoload so it never replaces the app's class at runtime.
 *
 * Point both tools at `tests/Support/IdeStubs` in `.vscode/settings.json`:
 * Intelephense via `intelephense.environment.includePaths`, and PHP Tools
 * (Devsense) via `php.workspace.includePath`.
 *
 * It mirrors the host app's class and adds nothing. It used to carry a `use`
 * of `WordPressImportTestTrait` for the analyser, standing in for the trait
 * Pest composed at run time through each file's `uses()` call. The Feature
 * files now extend `WordPressImportTestCase`, a real class that uses the trait,
 * so analysis and run time read the same hierarchy (wordpress-importer-archive#1031).
 */
abstract class TestCase extends IlluminateTestCase {}
