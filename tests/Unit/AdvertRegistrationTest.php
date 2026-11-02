<?php

declare(strict_types=1);

use UAMSWeb\Aether\WordPressImporter\Console\Commands\ImportWordPress;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\Creators\AdvertEntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\EntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Taxonomies\TaxonomyDispositions;

/**
 * Pins the four places `advert` has to be registered for `--content-type=advert` to reach its creator (wordpress-importer-archive#774).
 *
 * Each is a table entry, and a missing one fails differently: without the
 * supported-type entry the command never offers the type, without the collection
 * mapping it has no destination, without the taxonomy mapping the categories land
 * in a taxonomy named `advert_category`, and without the dispatch the run throws
 * "Unsupported content type". None needs the host app to read, so all four are
 * asserted here.
 *
 * @command  php vendor/bin/pest tests/Unit/AdvertRegistrationTest.php
 */
it('lists advert among the content types the command imports', function (): void {
    $supported = (new ReflectionProperty(ImportWordPress::class, 'supportedContentTypes'))->getDefaultValue();

    expect($supported)->toBeArray()->toContain('advert');
});

it('maps advert to the classifieds collection and its categories to classified_categories', function (): void {
    expect(ImportWordPress::getDefaultMappings()['advert'] ?? null)->toBe([
        'type' => 'collection',
        'handle' => 'classifieds',
        'name' => 'Classifieds',
    ])
        ->and(ImportWordPress::mapWordPressToStatamicHandle('advert', 'collection'))->toBe('classifieds')
        ->and(ImportWordPress::mapWordPressToStatamicHandle('advert_category', 'taxonomy'))->toBe('classified_categories')
        ->and(TaxonomyDispositions::shapeFor('advert_category'))->toBe(TaxonomyDispositions::SHAPE_TAXONOMY)
        ->and(TaxonomyDispositions::isRecorded('advert_category'))->toBeTrue();
});

it('hands an advert import to the advert creator', function (): void {
    // ---
    // `getCreator()` is private and `EntryCreator`'s own constructor graph does not
    // resolve outside the host app, so the instance is made without it. The `advert`
    // branch reads no property, which is what makes that safe here.
    // ---
    $entryCreator = (new ReflectionClass(EntryCreator::class))->newInstanceWithoutConstructor();
    $getCreator = new ReflectionMethod(EntryCreator::class, 'getCreator');

    expect($getCreator->invoke($entryCreator, 'advert'))->toBeInstanceOf(AdvertEntryCreator::class);
});
