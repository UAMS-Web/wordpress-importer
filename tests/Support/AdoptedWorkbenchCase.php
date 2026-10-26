<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Tests\Support;

use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * A case that plants form and blueprint YAML in this process's workbench before the
 * `setUp()` chain runs (issue wordpress-importer-archive#827).
 *
 * `isolateWorkbench()` empties an adopted workbench for the reason wordpress-importer-archive#832 records — a
 * run killed mid-test at a pid the operating system later reissues leaves its form
 * and blueprint files behind, and the next process would otherwise resolve them.
 * Reaching that branch means planting ahead of `parent::setUp()`; a Pest `beforeEach`
 * runs after the workbench is already prepared.
 *
 * Lives here rather than beside its test because it extends `StatamicTestCase` and is
 * therefore late-bound: it does not exist when Pest's `uses()` resolves at the top of
 * the test file.
 */
abstract class AdoptedWorkbenchCase extends StatamicTestCase
{
    /**
     * @var string The stale form file planted before setUp, or '' if none was
     */
    public static string $plantedForm = '';

    /**
     * @var string The stale blueprint file planted before setUp, or '' if none was
     */
    public static string $plantedBlueprint = '';

    /**
     * @var bool Whether both plants were verified on disk before `setUp()` ran
     */
    public static bool $plantsLanded = false;

    /**
     * Plant a stale form and blueprint, then boot as normal.
     */
    protected function setUp(): void
    {
        $workbench = \dirname(__DIR__).'/__fixtures__/workbench-'.\getmypid();

        foreach (['forms', 'blueprints/forms'] as $directory) {
            if (! \is_dir($workbench.'/'.$directory)) {
                \mkdir($workbench.'/'.$directory, 0755, true);
            }
        }

        self::$plantedForm = $workbench.'/forms/left_by_a_killed_run.yaml';
        self::$plantedBlueprint = $workbench.'/blueprints/forms/left_by_a_killed_run.yaml';

        \file_put_contents(self::$plantedForm, "title: Stale\n");
        \file_put_contents(self::$plantedBlueprint, "tabs:\n  main:\n    display: Stale\n");

        // ---
        // Every assertion in the test file is an absence, which is also what a plant
        // that silently failed produces. Recording this makes a defused fixture fail
        // rather than read as green.
        // ---
        self::$plantsLanded = \is_file(self::$plantedForm)
            && \is_file(self::$plantedBlueprint);

        parent::setUp();
    }
}
