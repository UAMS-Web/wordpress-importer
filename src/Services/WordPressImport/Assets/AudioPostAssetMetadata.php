<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Assets;

/**
 * Default Statamic asset metadata for audio files imported from audio-format
 * WordPress posts ([audio] shortcode paths).
 */
final class AudioPostAssetMetadata
{
    /**
     * Return metadata applied to every imported audio post asset.
     *
     * @return array<int|string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'language' => [
                'en',
            ],
            'creator_organization' => 'UAMS',
            'country_of_origin_query' => true,
            'geographic_location' => [
                'little-rock',
            ],
            'geographic_location_content' => [
                'little-rock',
            ],
            'digital_source_type' => [
                'preset' => 'humanEdits_audio_mastered',
            ],
        ];
    }
}
