<?php

declare(strict_types=1);

/**
 * Tests the WordPress `_psyoutube` / `_psvimeo` postmeta → `person` video mapping.
 *
 * The importer used to write a bare `video_url` / `video_link_text` pair, which the
 * `people/person` blueprint does not define — it carries four prefixed pairs
 * (`academic_`, `clinical_`, `institutional_`, `research_`) and no bare handle. The
 * imported values augmented to nothing, so the person page showed no video link.
 *
 * WordPress records no such context for either key, so `institutional_` is a
 * recorded default rather than a reading of the source (wordpress-importer-archive#501). The provenance note
 * is what makes that legible, so this file pins the note alongside the handles.
 *
 * Bare `TestCase`: `applyPersonVideo()` is pure array manipulation over
 * `HtmlTagStripper`, so it needs no container and no booted Statamic — the same
 * band as `PeopleJobTitleMappingTest`.
 *
 * @command  php vendor/bin/pest tests/Unit/PeopleVideoHandleMappingTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators\PeopleEntryCreator;

const YOUTUBE_URL = 'https://www.youtube.com/watch?v=bcK3RsG2ATg';
const VIMEO_URL = 'https://vimeo.com/123456789';

/**
 * Run the mapping over a minimal entry scaffold and return the resulting data.
 *
 * @param  ?string  $youtube  The `_psyoutube` value, or null when absent
 * @param  ?string  $vimeo  The `_psvimeo` value, or null when absent
 * @param  array<int|string, mixed>  $seed  Extra entry data to start from
 * @return array<int|string, mixed> The populated entry data
 */
function applyVideo(?string $youtube, ?string $vimeo, array $seed = []): array
{
    $data = array_merge(['title' => 'Bar Baz'], $seed);

    (new PeopleEntryCreator)->applyPersonVideo($data, $youtube, $vimeo);

    return $data;
}

it('lands a YouTube URL on the institutional handle the blueprint defines', function (): void {
    $data = applyVideo(YOUTUBE_URL, null);

    expect($data['institutional_video_url'] ?? null)->toBe(YOUTUBE_URL);
});

it('lands the generated link text on the matching institutional handle', function (): void {
    $data = applyVideo(YOUTUBE_URL, null);

    expect($data['institutional_video_link_text'] ?? null)->toBe('Video Referencing Bar Baz');
});

it('never writes the retired bare handles', function (): void {
    // ---
    // The whole defect: `video_url` / `video_link_text` are not on the `person`
    // blueprint, so anything written there augments to nothing and renders nowhere.
    // ---
    $data = applyVideo(YOUTUBE_URL, VIMEO_URL);

    expect($data)->not->toHaveKey('video_url');
    expect($data)->not->toHaveKey('video_link_text');
});

it('maps a Vimeo URL onto the same handles when there is no YouTube URL', function (): void {
    // ---
    // `_psvimeo` was mapped nowhere at all before wordpress-importer-archive#501. It appears 4,183 times in the
    // export corpus, though every occurrence there is empty — so this path is
    // defensive against a live site rather than exercised by the sampled exports.
    // ---
    $data = applyVideo(null, VIMEO_URL);

    expect($data['institutional_video_url'] ?? null)->toBe(VIMEO_URL);
    expect($data['institutional_video_link_text'] ?? null)->toBe('Video Referencing Bar Baz');
});

it('prefers YouTube over Vimeo when a person carries both', function (): void {
    // ---
    // Precedence has to be stated rather than left to postmeta order: the meta loop
    // walks the export's own node order, so a last-write-wins rule would make the
    // result depend on how the export happened to be serialized.
    // ---
    $data = applyVideo(YOUTUBE_URL, VIMEO_URL);

    expect($data['institutional_video_url'] ?? null)->toBe(YOUTUBE_URL);
});

it('names the video it did not import rather than dropping it silently', function (): void {
    $data = applyVideo(YOUTUBE_URL, VIMEO_URL);

    expect($data['import_wordpress_notes'] ?? '')->toContain(VIMEO_URL);
});

it('records why the institutional context was chosen', function (): void {
    $data = applyVideo(YOUTUBE_URL, null);

    expect($data['import_wordpress_notes'] ?? '')->toContain('Institutional');
});

it('writes the note as a string, matching the fieldset type', function (): void {
    // ---
    // `import_wordpress_notes` is `type: text` on `uamsweb_common` — a single string.
    // Writing an array would not round-trip through the fieldtype.
    // ---
    $data = applyVideo(YOUTUBE_URL, null);

    expect($data['import_wordpress_notes'] ?? null)->toBeString();
});

it('extends an existing note instead of overwriting it', function (): void {
    $data = applyVideo(YOUTUBE_URL, null, ['import_wordpress_notes' => 'Original excerpt value: Foo.']);

    expect($data['import_wordpress_notes'] ?? '')->toContain('Original excerpt value: Foo.');
    expect($data['import_wordpress_notes'] ?? '')->toContain('Institutional');
});

it('writes nothing at all when the person carries neither key', function (): void {
    $data = applyVideo(null, null);

    expect($data)->not->toHaveKey('institutional_video_url');
    expect($data)->not->toHaveKey('institutional_video_link_text');
    expect($data)->not->toHaveKey('import_wordpress_notes');
});

it('falls back to a placeholder name when the title is unusable', function (mixed $title): void {
    $data = applyVideo(YOUTUBE_URL, null, ['title' => $title]);

    expect($data['institutional_video_link_text'] ?? null)->toBe('Video Referencing Untitled Person');
})->with([
    'empty string' => [''],
    'null' => [null],
    'non-string' => [123],
]);

it('strips HTML from a title before it reaches the link text', function (): void {
    $data = applyVideo(YOUTUBE_URL, null, ['title' => '<em>Bar</em> Baz']);

    expect($data['institutional_video_link_text'] ?? null)->toBe('Video Referencing Bar Baz');
});
