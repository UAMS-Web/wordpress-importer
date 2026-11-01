<?php

declare(strict_types=1);

/**
 * Regression test for the unguarded `inputs` access in FormBlueprintCreator.
 *
 * `GravityForms\FormBlueprintCreator::getMappedSubfieldAttributes()` reads
 * `$field['inputs']` to decide whether a field has subfields. Non-composite
 * Gravity Forms fields omit the `inputs` key entirely, so under Laravel's error
 * handler the undefined-key read promotes to an ErrorException — which
 * `createBlueprintWithFields()` catches and turns into a `false` return,
 * silently skipping the whole form blueprint during import (wordpress-importer-archive#410).
 *
 * Extends the minimal LaravelHttpTestCase so the guard is exercised without the
 * host app, mirroring the promoted-warning approach in FormidableXmlFileLoaderTest.
 *
 * @command  php vendor/bin/pest tests/Unit/GravityFormsSubfieldInputsGuardTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FormBlueprintCreator;
use UAMSWeb\Aether\WordPressImporter\Tests\LaravelHttpTestCase;

uses(LaravelHttpTestCase::class);

it('maps a field without usable subfields to no subfield attributes, without a warning', function (array $field): void {
    // Access the protected mapper via reflection.
    $blueprintCreator = new FormBlueprintCreator;
    $reflection = new ReflectionClass($blueprintCreator);
    $method = $reflection->getMethod('getMappedSubfieldAttributes');
    $method->setAccessible(true);

    // Promote any PHP warning (e.g. "Undefined array key") to an exception so
    // the missing isset() guard would fail this test loudly.
    \set_error_handler(static function (int $severity, string $message): bool {
        throw new ErrorException($message, 0, $severity);
    });

    // Invoke the mapper, restoring the handler regardless of outcome.
    try {
        $result = $method->invoke(
            $blueprintCreator,
            $field,
            'gravity forms'
        );
    } finally {
        \restore_error_handler();
    }

    // A field without usable `inputs` maps to no subfield attributes without throwing.
    expect($result)->toBe([]);
})->with([
    // The missing-key row is the wordpress-importer-archive#410 regression; the null and empty-array rows
    // bound the rest of the guard's contract so a future edit can't quietly drop
    // one branch.
    'missing inputs key' => [['type' => 'text', 'label' => 'First Name']],
    'null inputs' => [['type' => 'text', 'label' => 'First Name', 'inputs' => null]],
    'empty inputs array' => [['type' => 'text', 'label' => 'First Name', 'inputs' => []]],
]);
