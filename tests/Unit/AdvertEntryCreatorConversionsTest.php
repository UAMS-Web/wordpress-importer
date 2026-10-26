<?php

declare(strict_types=1);

use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators\AdvertEntryCreator;

/**
 * Pins the three value conversions `AdvertEntryCreator` applies to a WP Adverts listing (wordpress-importer-archive#774).
 *
 * Each is a pure function of the raw meta value, so each is asserted here without
 * booting Statamic. How the converted values reach an entry is
 * `tests/Integration/AdvertImportTest.php`.
 *
 * @command  php vendor/bin/pest tests/Unit/AdvertEntryCreatorConversionsTest.php
 */
it('reads an expiration timestamp on the Little Rock calendar, not UTC', function (): void {
    // ---
    // 2025-03-17 03:00:00 UTC is still the evening of the 16th in Little Rock
    // (UTC-5 under daylight time). Read in UTC this would store the 17th, and a
    // listing would stay on the board a day longer than the poster was promised.
    // ---
    expect(AdvertEntryCreator::expirationDateFromTimestamp('1742180400'))->toBe('2025-03-16');

    // Control: an instant that is the same calendar day in both zones
    expect(AdvertEntryCreator::expirationDateFromTimestamp('1742220000'))->toBe('2025-03-17');

    // Surrounding whitespace in the stored meta value does not make it unreadable
    expect(AdvertEntryCreator::expirationDateFromTimestamp(" 1742180400\n"))->toBe('2025-03-16');
});

it('refuses an expiration value that is not a Unix timestamp', function (string $value): void {
    expect(AdvertEntryCreator::expirationDateFromTimestamp($value))->toBeNull();
})->with([
    'empty' => '',
    'a formatted date' => '2025-03-17',
    'negative' => '-5',
    'fractional' => '1742180400.5',
]);

it('converts a numeric price and nothing else', function (string $value, ?float $expected): void {
    expect(AdvertEntryCreator::priceFromText($value))->toBe($expected);
})->with([
    'whole dollars' => ['25', 25.0],
    'cents' => ['1250.50', 1250.5],
    'surrounding space' => [' 12 ', 12.0],
    'zero' => ['0', 0.0],
    'empty' => ['', null],
    'free text' => ['OBO', null],
    'a currency sign' => ['$25', null],
]);

it('orders the gallery as stored and leaves the featured image out wherever it falls', function (string $order, string $thumbnail, array $expected): void {
    expect(AdvertEntryCreator::galleryAttachmentIds($order, $thumbnail))->toBe($expected);
})->with([
    // 202 of the 229 listings in the classifieds export store the featured image first
    'featured image first' => ['[9047,9046,9044]', '9047', ['9046', '9044']],
    // 6 store it further along; the others keep their relative order around it
    'featured image in the middle' => ['[9014,9013,9015]', '9013', ['9014', '9015']],
    // 2 carry a featured image the stored order does not name
    'featured image not in the order' => ['[9014,9015]', '9013', ['9014', '9015']],
    // 19 carry no images at all
    'no stored order' => ['', '', []],
    'a lone featured image' => ['[9042]', '9042', []],
    'no featured image' => ['[3,1,2]', '', ['3', '1', '2']],
    'ids stored as strings' => ['["3","1"]', '', ['3', '1']],
    'a repeated id' => ['[3,1,3]', '', ['3', '1']],
    'padded ids' => ['[" 3", "1 "]', '', ['3', '1']],
    'an empty id' => ['["", 3]', '9', ['3']],
    'an object, read for its values' => ['{"a":3,"b":1}', '', ['3', '1']],
    'a null id' => ['[null, 3]', '', ['3']],
    'malformed JSON' => ['[3,1', '', []],
    'not an array' => ['7', '', []],
]);

it('keeps all twelve images of the largest listing, eleven of them in the gallery', function (): void {
    // The classifieds export's largest listings carry 12 images; one is the featured image
    $order = \json_encode(\range(9001, 9012), JSON_THROW_ON_ERROR);

    $gallery = AdvertEntryCreator::galleryAttachmentIds($order, '9001');

    expect($gallery)->toHaveCount(11)
        ->and($gallery)->toBe(\array_map('strval', \range(9002, 9012)));
});
