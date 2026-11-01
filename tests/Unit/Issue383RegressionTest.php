<?php

declare(strict_types=1);

/**
 * Regression test for issue wordpress-importer-archive#383.
 *
 * The PHPStan cleanup changed the attachment-id guard to
 * `\is_string($attachmentId) && $attachmentId !== ''`, which silently
 * skipped integer attachment ids (the WXR parser can surface ids as ints).
 * The fix coerces both int and string ids to string before the guard so the
 * lookup path is exercised again.
 *
 * These assertions avoid booting Laravel/Statamic by passing an empty
 * attachments array (so XpathHelper::findAttachmentById short-circuits to
 * null without touching the Log facade) and stubbing the AssetImporter /
 * OutputInterface dependencies, which the alt-text logic never invokes.
 *
 * @command  php vendor/bin/pest tests/Unit/Issue383RegressionTest.php
 */

use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\ImageProcessor\AltTextProcessor;

it('sets the alt override from the img alt regardless of the attachment id type', function (array $context, ?string $expectedOverride): void {
    $processor = new AltTextProcessor;

    $result = $processor->process(
        $context,
        'default',
        $this::createStub(AssetImporter::class),
        $this::createStub(OutputInterface::class),
        [],
        null
    );

    expect($result['altOverride'])->toBe($expectedOverride);
})->with([
    // An integer attachment id must still be processed: the img alt wins as the
    // override, proving the integer id no longer skips the comparison branch a
    // string-only guard would have skipped.
    'integer attachment id is processed' => [
        ['imgAlt' => 'A descriptive alt', 'attachmentId' => 123],
        'A descriptive alt',
    ],
    // A string attachment id keeps working exactly as before.
    'string attachment id is processed' => [
        ['imgAlt' => 'Another alt', 'attachmentId' => '456'],
        'Another alt',
    ],
    // A missing/null attachment id with an empty img alt yields no override.
    'missing attachment id yields no override' => [
        ['imgAlt' => ''],
        null,
    ],
]);
