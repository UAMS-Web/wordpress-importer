<?php

declare(strict_types=1);

/**
 * Regression test for issue wordpress-importer-archive#368.
 *
 * Ensures handleFormPluginDetection() OR-accumulates the per-plugin
 * "data loaded" flags so a later plugin's result can no longer
 * overwrite (clobber) an earlier plugin's positive result.
 *
 * @command  php vendor/bin/pest tests/Unit/Issue368RegressionTest.php
 */

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\FormidableFormsCoordinator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormPluginCoordinator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\GravityFormsCoordinator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms\NinjaFormsCoordinator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;

it(
    'OR-accumulates plugin data-loaded flags so no plugin can clobber another',
    function (
        bool $gravityLoaded,
        bool $formidableLoaded,
        bool $ninjaLoaded,
        bool $expected
    ): void {
        // Every plugin reports having content, so all three detection branches run;
        // only the per-plugin "data loaded" results vary across the truth-table rows.
        $gravity = $this::createStub(GravityFormsCoordinator::class);
        $gravity->method('hasGravityFormsContent')->willReturn(true);
        $gravity->method('handleGravityFormsDetection')->willReturn($gravityLoaded);

        $formidable = $this::createStub(FormidableFormsCoordinator::class);
        $formidable->method('hasFormidableFormsContent')->willReturn(true);
        $formidable->method('handleFormidableFormsDetection')->willReturn($formidableLoaded);

        $ninja = $this::createStub(NinjaFormsCoordinator::class);
        $ninja->method('hasNinjaFormsContent')->willReturn(true);
        $ninja->method('handleNinjaFormsDetection')->willReturn($ninjaLoaded);

        $coordinator = new FormPluginCoordinator($gravity, $formidable, $ninja);

        $result = $coordinator->handleFormPluginDetection(
            $this::createStub(XmlParser::class),
            $this::createStub(InputInterface::class),
            $this::createStub(OutputInterface::class),
            $this::createStub(Command::class)
        );

        expect($result)->toBe($expected);
    }
)->with([
    // Each plugin alone must survive the two that follow it reporting false.
    'gravity alone loads (must not be clobbered)' => [true, false, false, true],
    'formidable alone loads (must not be clobbered)' => [false, true, false, true],
    'ninja alone loads' => [false, false, true, true],

    // Pairs, so a true result cannot depend on any single branch.
    'gravity and formidable load' => [true, true, false, true],
    'gravity and ninja load' => [true, false, true, true],
    'formidable and ninja load' => [false, true, true, true],

    'all three plugins load data' => [true, true, true, true],
    'no plugin loads data' => [false, false, false, false],
]);
