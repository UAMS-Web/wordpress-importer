<?php

declare(strict_types=1);

/**
 * Tests that `SlugGenerator` picks a slug from the WordPress slug, then the
 * title, then the post id, trims a long title slug at a word boundary, and builds
 * an entry id from the slug, the post id and a random suffix (#1314).
 *
 * @command  php vendor/bin/pest tests/Unit/SlugGeneratorTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\SlugGenerator;

it('uses the WordPress slug when there is one, ignoring the title', function (): void {
    [$slug] = SlugGenerator::generateSlugAndId('about-us', '42', 'A Different Title');

    expect($slug)->toBe('about-us');
});

it('slugs the title when the WordPress slug is empty', function (): void {
    [$slug] = SlugGenerator::generateSlugAndId('', '42', 'Our New Clinic, Opening Soon!');

    expect($slug)->toBe('our-new-clinic-opening-soon');
});

it('falls back to the post id when there is no slug or title', function (?string $title): void {
    [$slug] = SlugGenerator::generateSlugAndId('', '42', $title);

    expect($slug)->toBe('item-42');
})->with([
    'no title' => [null],
    'an empty title' => [''],
]);

it('keeps a title slug of exactly 80 characters whole', function (): void {
    $title = \str_repeat('a', 80);

    [$slug] = SlugGenerator::generateSlugAndId('', '1', $title);

    expect($slug)->toBe($title);
});

it('keeps an 80-character title slug whole even with a late hyphen', function (): void {
    $title = \str_repeat('a', 70).' '.\str_repeat('b', 9);

    [$slug] = SlugGenerator::generateSlugAndId('', '1', $title);

    expect($slug)->toBe(\str_repeat('a', 70).'-'.\str_repeat('b', 9));
});

it('cuts an 81-character title slug back to a late hyphen', function (): void {
    $title = \str_repeat('a', 70).' '.\str_repeat('b', 10);

    [$slug] = SlugGenerator::generateSlugAndId('', '1', $title);

    expect($slug)->toBe(\str_repeat('a', 70));
});

it('cuts a long title slug back to its last hyphen when that falls after character 60', function (): void {
    // 70 letters, a hyphen at index 70, then 20 more: the first 80 characters end mid-word
    $title = \str_repeat('a', 70).' '.\str_repeat('b', 20);

    [$slug] = SlugGenerator::generateSlugAndId('', '1', $title);

    expect($slug)->toBe(\str_repeat('a', 70));
});

it('cuts at a hyphen at character 61 but not at 60', function (int $hyphenAt, int $expectedLength): void {
    $title = \str_repeat('a', $hyphenAt).' '.\str_repeat('b', 40);

    [$slug] = SlugGenerator::generateSlugAndId('', '1', $title);

    expect(\strlen($slug))->toBe($expectedLength);
})->with([
    'hyphen at 61 is used' => [61, 61],
    'hyphen at 60 is too early, so 80 is kept' => [60, 80],
]);

it('truncates a long title slug with no hyphen to 80 characters', function (): void {
    [$slug] = SlugGenerator::generateSlugAndId('', '1', \str_repeat('x', 120));

    expect($slug)->toBe(\str_repeat('x', 80));
});

it('builds the id from the slug, the post id and a 16-character random suffix', function (): void {
    [$slug, $id] = SlugGenerator::generateSlugAndId('about-us', '42');

    expect($slug)->toBe('about-us')
        ->and($id)->toMatch('/\Aabout-us-42-[A-Za-z0-9]{16}\z/');
});

it('gives two calls for the same post different ids', function (): void {
    [, $first] = SlugGenerator::generateSlugAndId('about-us', '42');
    [, $second] = SlugGenerator::generateSlugAndId('about-us', '42');

    expect($first)->not->toBe($second);
});
