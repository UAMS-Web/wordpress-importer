<?php

declare(strict_types=1);

use UAMSWeb\Aether\WordPressImporter\Console\Commands\ImportWordPress;

/**
 * The command ends the run for a content type with no default mapping, so every content type
 * it imports needs one (#213).
 */
it('maps every content type the command imports by default', function (): void {
    $supported = (new ReflectionProperty(ImportWordPress::class, 'supportedContentTypes'))->getDefaultValue();

    assert(is_array($supported) && $supported !== []);

    $unmapped = array_values(array_filter(
        $supported,
        fn (mixed $type): bool => ! is_string($type) || ! array_key_exists($type, ImportWordPress::getDefaultMappings())
    ));

    expect($unmapped)->toBe([]);
});
