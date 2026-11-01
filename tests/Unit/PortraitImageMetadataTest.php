<?php

declare(strict_types=1);

/**
 * Tests the image metadata carried onto portrait attachments at import.
 *
 * The `images` container gets `alt`, `caption`, and `creator` through
 * `MetadataSetter::setImageMetadata()`, which is hard-guarded to
 * `$container === 'images'`. People portraits take the *file* path instead —
 * `FileProcessor::importFileFromAttachmentWithDisabledUploadHandling()` with the
 * `portrait` type — which historically wrote only `original_wordpress_url` and
 * `title`, so a WordPress portrait's alt text, caption, and `_media_credit`
 * photo credit were dropped on migration (wordpress-importer-archive#435).
 *
 * `XpathHelper::extractAttachmentData()` already resolves all three (the credit
 * via `AttachmentCreditResolver`), so this covers selecting them for the asset
 * without inventing keys the payload does not carry.
 *
 * @command  php vendor/bin/pest tests/Unit/PortraitImageMetadataTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\FileProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\HtmlTagStripper;

it('carries alt, caption, and creator off the attachment', function (): void {
    $metadata = FileProcessor::buildAttachmentImageMetadata([
        'url' => 'https://example.com/wp-content/uploads/2024/01/portrait.jpg',
        'title' => 'Portrait of Jane Doe',
        'alt' => 'Jane Doe, smiling',
        'caption' => 'Dr. Jane Doe in the clinic',
        'creator' => 'UAMS Photography',
        'focus' => '50-50-1',
    ]);

    expect($metadata['alt'] ?? null)->toBe('Jane Doe, smiling');
    expect($metadata['caption'] ?? null)->toBe('Dr. Jane Doe in the clinic');
    expect($metadata['creator'] ?? null)->toBe('UAMS Photography');
});

it('carries only the image metadata keys, not the whole payload', function (): void {
    // ---
    // `url` and `title` are already written by setFileMetadata's own parameters,
    // and `focus` is not a portraits field — re-sending them would either
    // duplicate work or push a handle the container's blueprint may not define.
    // ---
    $metadata = FileProcessor::buildAttachmentImageMetadata([
        'url' => 'https://example.com/a.jpg',
        'title' => 'A title',
        'alt' => 'Alt text',
        'focus' => '50-50-1',
    ]);

    expect(array_keys($metadata))->toBe(['alt']);
});

it('omits a field the attachment does not carry', function (string $missing): void {
    $payload = [
        'alt' => 'Alt text',
        'caption' => 'A caption',
        'creator' => 'A credit',
    ];
    unset($payload[$missing]);

    expect(FileProcessor::buildAttachmentImageMetadata($payload))->not->toHaveKey($missing);
})->with(['alt', 'caption', 'creator']);

it('treats an empty or whitespace-only value as absent', function (mixed $value): void {
    // An empty string would overwrite a curated asset value with nothing
    $metadata = FileProcessor::buildAttachmentImageMetadata([
        'alt' => $value,
        'caption' => 'Kept',
    ]);

    expect($metadata)->not->toHaveKey('alt');
    expect($metadata['caption'] ?? null)->toBe('Kept');
})->with([
    'empty string' => [''],
    'spaces' => ['   '],
    'newline' => ["\n"],
]);

it('ignores a non-string value rather than casting it', function (mixed $value): void {
    expect(FileProcessor::buildAttachmentImageMetadata(['creator' => $value]))->toBe([]);
})->with([
    'null' => [null],
    'array' => [['UAMS Photography']],
    'integer' => [42],
    'boolean' => [true],
]);

it('returns nothing for an attachment with no image metadata at all', function (): void {
    expect(FileProcessor::buildAttachmentImageMetadata([
        'url' => 'https://example.com/a.jpg',
        'title' => 'A title',
    ]))->toBe([]);
});

it('strips HTML and decodes entities like the image path does', function (string $raw, string $expected): void {
    // ---
    // The same attachment can reach the `images` container through either sink: a
    // featured image takes this file path, an inline image takes the image path and
    // `MetadataSetter::setImageMetadata()`, which runs `HtmlTagStripper::strip()`.
    // Skipping the strip here would store two incompatible conventions in one container,
    // and `alt`/`caption` are `textarea` fields, so markup would render literally.
    // ---
    expect(FileProcessor::buildAttachmentImageMetadata(['caption' => $raw])['caption'] ?? null)
        ->toBe($expected);
})->with([
    'bold and break' => [
        '<strong>Heather Broyles, D.O.</strong><br> Division Director',
        'Heather Broyles, D.O. Division Director',
    ],
    'paragraph markup' => [
        '<p><strong>Hong-Yu Li, Ph.D. </strong><br /> Leader, Therapeutic Sciences</p>',
        'Hong-Yu Li, Ph.D. Leader, Therapeutic Sciences',
    ],
    // The WXR carries `&amp;amp;`; XML parsing decodes once, so the value reaching
    // the importer is `&amp;`, which the strip decodes to `&` — matching the image path.
    'escaped entity' => [
        'Nadine Baxter &amp; the Leukemia &amp; Lymphoma Society board',
        'Nadine Baxter & the Leukemia & Lymphoma Society board',
    ],
    'plain text is untouched' => [
        'Dr. Jane Doe in the clinic',
        'Dr. Jane Doe in the clinic',
    ],
]);

it('produces the same value the image path would store', function (string $raw): void {
    // ---
    // The invariant that matters: whichever sink writes the asset, one container must not
    // end up with two caption conventions. `setImageMetadata()` normalizes with
    // `HtmlTagStripper::strip()`, so this path must agree with it exactly.
    // ---
    $viaFilePath = FileProcessor::buildAttachmentImageMetadata(['caption' => $raw])['caption'] ?? null;
    $viaImagePath = HtmlTagStripper::strip($raw);

    expect($viaFilePath)->toBe($viaImagePath);
})->with([
    'markup' => ['<strong>Seth Berney, M.D.</strong><br> Division Director'],
    'entity' => ['Fresh &amp; Reddie meal kit'],
    'plain' => ['A plain caption'],
]);

it('drops a value that is only markup', function (): void {
    // `<p></p>` strips to nothing, and an empty caption must not be written
    expect(FileProcessor::buildAttachmentImageMetadata(['caption' => '<p></p>']))
        ->not->toHaveKey('caption');
});

it('trims surrounding whitespace from a kept value', function (): void {
    $metadata = FileProcessor::buildAttachmentImageMetadata([
        'creator' => "  UAMS Photography \n",
    ]);

    expect($metadata['creator'])->toBe('UAMS Photography');
});
