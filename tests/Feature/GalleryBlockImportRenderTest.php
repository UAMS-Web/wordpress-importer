<?php

declare(strict_types=1);

use Statamic\Facades\Antlers;
use Statamic\Fields\Field;
use Statamic\Fieldtypes\Bard;
use Statamic\Fieldtypes\Radio;
use Statamic\Fieldtypes\Toggle;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Tests\TestCase;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\SameSitePolicy;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\GalleryParser;

uses(TestCase::class);

afterEach(function (): void {
    Mockery::close();
});

/**
 * Tests that `GalleryParser`'s output renders through the real gallery partial
 * (issue wordpress-importer-archive#430).
 *
 * `UAMS-Web/uams-statamic#1514` repaired `_uamsweb_block_gallery.antlers.html`,
 * whose validity guard used loose `!= null` on `crop` / `modal` / `more_query`. A
 * falsy-but-answered value therefore invalidated the whole block and it rendered
 * nothing — and those are exactly the values the importer emits for the COMMON
 * case: `more_query` defaults off in WordPress (`gallery_more ?? '0'`), so every
 * gallery without a "more" link got `more_query => false`.
 *
 * The app-side render is covered by that repo's own `GalleryBlockRenderTest`. What
 * was missing, and what these tests add, is the IMPORTER-side guard: proof that the
 * set `GalleryParser` actually produces reaches the partial and emits its grid. A
 * green app-side test says the template handles a hand-written field set; only this
 * says it handles the importer's.
 *
 * wordpress-importer-archive#430 also aligns `crop` with the radio's numeric option keys — `extractGalleryFields()`
 * used to emit the string `'0'` / `'1.7778'` while `uamsweb_block_gallery.yaml`
 * declares `0` / `1.7778`. The template rendered either form (its ratio line unwraps
 * with a loose `block:crop != 0`), but the Control Panel radio did not mark the
 * imported option as selected on edit.
 *
 * Hermetic-render note: the parser emits `images` as asset PATH strings, which
 * Statamic augments to Asset objects against a container at request time. These
 * tests keep the parser's real image list (count and order) and materialize each
 * path as a plain array stand-in, so Glide no-ops instead of resolving a rendition
 * and no asset container is needed. `crop` / `modal` / `more_query` are bound
 * through the real fieldtypes' `augment()`, which is the production shape.
 *
 * @command  php artisan test vendor/uams-web/wordpress-importer/tests/Feature/GalleryBlockImportRenderTest.php
 */

/**
 * Build the ACF `uams-gallery` block attributes a WordPress export carries.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function galleryImportAttributes(array $overrides = []): array
{
    return \array_merge([
        'gallery_heading' => 'Campus Scenes',
        'gallery_description' => '<p>Photographs from around campus.</p>',
        'gallery_hide_heading' => '0',
        'gallery_columns' => '3',
        'gallery_images' => ['101', '102', '103'],
        'gallery_crop' => 'none',
        'gallery_background_color' => 'bg-primary',
        'gallery_modal' => '1',
        'gallery_more' => '0',
    ], $overrides);
}

/**
 * An `AssetImporter` whose image imports always succeed, naming each imported
 * image distinctly so the rendered grid can be checked image by image.
 *
 * A real subclass rather than a PHPUnit mock: the parent constructor only reads
 * config and news up its collaborators, so subclassing is cheap, fully typed, and
 * needs no mock framework in a file that already has plenty to set up.
 */
final class GalleryStubAssetImporter extends AssetImporter
{
    /**
     * @var int How many images this stub has "imported" so far
     */
    private int $imported = 0;

    /**
     * Report image uploads as enabled, which is the path under test.
     *
     * @return bool Always true
     */
    public function getUploadImages(): bool
    {
        return true;
    }

    /**
     * Succeed with a distinct path per call.
     *
     * @param  string  $attachmentId  The WordPress attachment ID
     * @param  string  $site  The Statamic site handle
     * @param  array<int|string, mixed>  $attachments  Attachment elements (unused)
     * @param  OutputInterface  $output  Console output (unused)
     * @param  SameSitePolicy  $sameSitePolicy  Same-site policy (unused)
     * @param  ?string  $pageUrl  Page URL for context (unused)
     * @param  string  $container  Target container (unused)
     * @param  bool  $hasFallbackUrl  Whether a fallback URL exists (unused)
     * @return string The stubbed asset path — this stub never fails an import
     */
    public function importImage(
        string $attachmentId,
        string $site,
        array $attachments,
        OutputInterface $output,
        SameSitePolicy $sameSitePolicy = SameSitePolicy::ALWAYS_IMPORT,
        ?string $pageUrl = null,
        string $container = 'images',
        bool $hasFallbackUrl = true
    ): string {
        $this->imported++;

        return "site/campus-{$this->imported}.jpg";
    }
}

/**
 * Run the attributes through the real `GalleryParser` and return its replicator set.
 *
 * @param  AssetImporter  $assetImporter  A `GalleryStubAssetImporter`
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function galleryImportSet(AssetImporter $assetImporter, array $overrides = []): array
{
    $set = (new GalleryParser)->parse(
        galleryImportAttributes($overrides),
        'site',
        $assetImporter,
        new BufferedOutput
    );

    /** @var array<string, mixed> $set */
    return $set;
}

/**
 * Render an imported gallery set through the real partial and return its HTML.
 *
 * @param  array<string, mixed>  $set
 */
function renderImportedGallerySet(array $set): string
{
    $radio = new Radio;
    $toggle = new Toggle;

    // Materialize the parser's asset paths as hermetic stand-ins, preserving order
    $paths = \is_array($set['images'] ?? null) ? $set['images'] : [];
    $images = \array_map(
        static fn (mixed $path): array => [
            'url' => '/assets/'.(\is_string($path) ? $path : 'unknown.jpg'),
            'alt' => 'A campus photograph',
            'caption' => 'On campus',
            'creator' => 'Jane Doe',
        ],
        \array_values($paths)
    );

    // Bind the fields the partial's guard and ratio math read, in production shape
    $fields = $set;
    $fields['images'] = $images;
    $fields['crop'] = $radio->augment($set['crop'] ?? null);
    $fields['modal'] = $toggle->augment($set['modal'] ?? null);
    $fields['more_query'] = $toggle->augment($set['more_query'] ?? null);

    // ---
    // `block_description` is a Bard field: the parser stores a Tiptap node array and
    // Statamic augments it to HTML at request time. Rendering the raw array instead
    // throws `Passed value cannot be an array` deep in Stringy, so augment it here
    // too rather than substituting a plain string.
    // ---
    if (isset($set['block_description'])) {
        $bard = new Bard;
        $bard->setField(new Field('block_description', ['type' => 'bard']));

        $fields['block_description'] = $bard->augment($set['block_description']);
    }

    if (($set['more_query'] ?? false) === true) {
        $fields['more_button_target'] = $toggle->augment($set['more_button_target'] ?? false);
    }

    // Any render fault propagates — a throw fails the test
    $html = (string) Antlers::parse(
        '{{ partial:page_builder/_uamsweb_block_gallery }}',
        $fields + ['block' => $fields],
        true,
    );

    // The block header runs headings through `widont`, which replaces the space
    // before the final word with `&nbsp;` — normalize so text assertions read plainly
    return \str_replace('&nbsp;', ' ', $html);
}

/**
 * Assert that a rendered gallery emitted its image grid rather than nothing.
 *
 * @param  array<int, string>  $expectedImages  Filenames that must appear in the grid
 */
function expectGalleryGridRendered(string $html, array $expectedImages = ['campus-1.jpg']): void
{
    // The block wrapper only exists past the `$block_valid` guard
    expect($html)->toContain('gallery-block');

    // The grid itself, which is what "renders its grid, not nothing" means
    expect($html)->toContain('image-container');

    // Every image the parser emitted reached the grid
    foreach ($expectedImages as $image) {
        expect($html)->toContain($image);
    }
}

it('renders the grid for an imported gallery with no "more" link', function (): void {
    // `more_query` defaults off in WordPress, so this is the common case — and the
    // exact shape the pre-#1514 loose guard rendered as nothing. wordpress-importer-archive#430
    $set = galleryImportSet(new GalleryStubAssetImporter, ['gallery_more' => '0']);

    expect($set['more_query'])->toBeFalse();

    $html = renderImportedGallerySet($set);

    expectGalleryGridRendered($html, ['campus-1.jpg', 'campus-2.jpg', 'campus-3.jpg']);

    // The heading and the Bard description both survive to the rendered block
    expect($html)->toContain('Campus Scenes');
    expect($html)->toContain('Photographs from around campus.');
});

it('renders the grid for an imported gallery with modal off', function (): void {
    $set = galleryImportSet(new GalleryStubAssetImporter, ['gallery_modal' => '0']);

    expect($set['modal'])->toBeFalse();

    $html = renderImportedGallerySet($set);

    expectGalleryGridRendered($html);
});

it('renders the grid for an imported gallery with crop None', function (): void {
    $set = galleryImportSet(new GalleryStubAssetImporter, ['gallery_crop' => 'none']);

    expect($set['crop'])->toBe(0);

    $html = renderImportedGallerySet($set);

    expectGalleryGridRendered($html);
});

it('renders the grid with all three falsy answers at once', function (): void {
    $set = galleryImportSet(new GalleryStubAssetImporter, [
        'gallery_crop' => 'none',
        'gallery_modal' => '0',
        'gallery_more' => '0',
    ]);

    $html = renderImportedGallerySet($set);

    expectGalleryGridRendered($html);
});

it('renders every crop option the export corpus carries', function (
    string $wordPressCrop,
    int|float $expectedKey
): void {
    // The five values in the corpus's WordPress/*.xml, each emitted as the radio's
    // own key type so the Control Panel marks the imported option selected. wordpress-importer-archive#430
    $set = galleryImportSet(new GalleryStubAssetImporter, ['gallery_crop' => $wordPressCrop]);

    expect($set['crop'])->toBe($expectedKey);

    $html = renderImportedGallerySet($set);

    expectGalleryGridRendered($html);
})->with([
    'none' => ['none', 0],
    '1:1 square' => ['1', 1],
    '16:9 landscape' => ['1.7778', 1.7778],
    '4:3 landscape' => ['1.3333', 1.3333],
    '3:4 portrait' => ['0.75', 0.75],
]);

it('renders the grid and the more link for an imported gallery with one', function (): void {
    $set = galleryImportSet(new GalleryStubAssetImporter, [
        'gallery_more' => '1',
        'gallery_more_text' => 'More campus photographs',
        'gallery_more_button_text' => 'View the archive',
        'gallery_more_button_url' => ['url' => '/campus/archive', 'target' => '_blank'],
    ]);

    expect($set['more_query'])->toBeTrue();

    $html = renderImportedGallerySet($set);

    expectGalleryGridRendered($html);
    expect($html)->toContain('View the archive');
});
