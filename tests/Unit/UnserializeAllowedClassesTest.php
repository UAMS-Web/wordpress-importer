<?php

declare(strict_types=1);

/**
 * Regression test for wordpress-importer-archive#324.
 *
 * Importer postmeta `unserialize()` calls now pass `['allowed_classes' => false]`,
 * so a serialized PHP object embedded in a WXR cannot be instantiated (its
 * `__wakeup`/`__destruct` gadget never fires). The same option is applied at every
 * postmeta `unserialize()` in the importer; `GalleryParser::normalizeGalleryImages()`
 * and `AttachmentCreditResolver::parseExifCredit()` are pinned here as representative
 * call sites.
 *
 * @command  php vendor/bin/pest tests/Unit/UnserializeAllowedClassesTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\Blocks\GalleryParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\AttachmentCreditResolver;

/**
 * Probe object whose `__wakeup()` records that it ran, used to prove postmeta
 * `unserialize()` no longer instantiates arbitrary classes (PHP object injection).
 */
class GadgetProbe
{
    public static bool $woke = false;

    public function __wakeup(): void
    {
        self::$woke = true;
    }
}

beforeEach(function (): void {
    GadgetProbe::$woke = false;
});

it('does not instantiate a serialized object embedded in gallery postmeta', function (): void {
    $serializedObject = serialize(new GadgetProbe);

    $method = new ReflectionMethod(GalleryParser::class, 'normalizeGalleryImages');
    $method->invoke(new GalleryParser, $serializedObject);

    expect(GadgetProbe::$woke)->toBeFalse();
});

it('still unserializes a serialized array of ids', function (): void {
    $serializedArray = serialize([12, 34, 56]);

    $method = new ReflectionMethod(GalleryParser::class, 'normalizeGalleryImages');
    $result = $method->invoke(new GalleryParser, $serializedArray);

    // Behaviour preserved: a plain array of ids round-trips.
    expect($result)->toBe([12, 34, 56]);
});

it('does not instantiate a serialized object embedded in attachment metadata', function (): void {
    $serializedObject = serialize(new GadgetProbe);

    // The credit resolver unserializes _wp_attachment_metadata for the EXIF fallback.
    $credit = AttachmentCreditResolver::parseExifCredit($serializedObject);

    // A serialized object must neither instantiate a gadget nor yield a credit.
    expect(GadgetProbe::$woke)->toBeFalse()
        ->and($credit)->toBe('');
});
