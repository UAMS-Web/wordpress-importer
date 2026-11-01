<?php

declare(strict_types=1);

/**
 * Unit tests for audio post default asset metadata.
 *
 * @command  php vendor/bin/pest tests/Unit/AudioPostAssetMetadataTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AudioPostAssetMetadata;

it('returns defaults matching the Statamic audio asset blueprint contract', function (): void {
    $defaults = AudioPostAssetMetadata::defaults();

    expect($defaults['language'])->toBe(['en'])
        ->and($defaults['creator_organization'])->toBe('UAMS')
        ->and($defaults['country_of_origin_query'])->toBeTrue()
        ->and($defaults['geographic_location'])->toBe(['little-rock'])
        ->and($defaults['geographic_location_content'])->toBe(['little-rock'])
        ->and($defaults['digital_source_type'])->toBe([
            'preset' => 'humanEdits_audio_mastered',
        ]);
});
