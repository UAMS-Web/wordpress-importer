<?php

declare(strict_types=1);

use Statamic\Assets\Asset as StatamicAsset;
use Statamic\Facades\Asset;
use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Fields\Blueprint;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetDownloader;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\MetadataSetter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\OverwriteHandler;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

/**
 * Tests that the two asset-metadata sinks stamp the attachment-page URL and stop
 * erasing each other's keys (issue wordpress-importer-archive#489).
 *
 * `MetadataSetter` writes through two sinks: `setFileMetadata()` (featured images,
 * portraits, files) and `setImageMetadata()` (inline body images). wordpress-importer-archive#446 stamped
 * `wordpress_attachment_page_url` on the file sink only, so inline images — the
 * bulk of WordPress attachments — never received it.
 *
 * Worse, both sinks finished with `$asset->data($data); $asset->save();`, and
 * `Statamic\Assets\Asset::data()` is a FULL REPLACE: every key absent from the
 * argument is recorded in `$removedData`. An attachment used both ways (a featured
 * image that also appears inline) therefore lost whatever the other sink had
 * written — `title` and `original_wordpress_url` were already being erased before
 * wordpress-importer-archive#446 added another key to the set.
 *
 * The probe asset below reproduces that replace semantics faithfully: it disables
 * hydration (no disk) but leaves the real `ContainsData::data()` in place, so a
 * bare `data()` write still clobbers, exactly as it does against a real container.
 *
 * @command  php vendor/bin/pest tests/Integration/AssetMetadataMergeTest.php
 */
uses(StatamicTestCase::class);

/**
 * An in-memory `Asset` that keeps the real `data()` replace semantics but never
 * touches a container.
 */
final class MetadataMergeProbeAsset extends StatamicAsset
{
    /**
     * @var int How many times the sink persisted this asset
     */
    public int $saves = 0;

    /**
     * Start with empty meta and hydration disabled so `data()` never reads disk.
     */
    public function __construct()
    {
        parent::__construct();

        // Skip the container round-trip `hydrate()` would otherwise perform
        $this->shouldHydrate = false;

        // `data()` diffs against this to compute `$removedData`
        $this->meta = ['data' => []];
    }

    /**
     * Record the save instead of writing to a container.
     *
     * @return bool Always true
     */
    public function save()
    {
        $this->saves++;

        return true;
    }

    /**
     * Stand in for the `images` container's blueprint.
     *
     * `Asset::data()` reaches augmentation while computing `$removedData`, which
     * resolves the blueprint through the container — absent here. Declaring the
     * three handles `filterToBlueprintFields()` gates (wordpress-importer-archive#435) also keeps that filter
     * behaving as it does against the real `images` container.
     *
     * @return Blueprint The stand-in blueprint
     */
    public function blueprint()
    {
        $blueprint = BlueprintFacade::make();

        // `setContents()` is fluent but facade-typed as mixed, so configure in place
        // and return the narrowed instance
        $blueprint->setContents([
            'fields' => [
                ['handle' => 'alt', 'field' => ['type' => 'text']],
                ['handle' => 'caption', 'field' => ['type' => 'text']],
                ['handle' => 'creator', 'field' => ['type' => 'text']],
            ],
        ]);

        return $blueprint;
    }
}

/**
 * Point the Asset facade at a single probe asset and return it.
 */
function metadataMergeProbe(): MetadataMergeProbeAsset
{
    $asset = new MetadataMergeProbeAsset;

    Asset::shouldReceive('find')->andReturn($asset);

    return $asset;
}

/**
 * Read the probe's current data as a plain array.
 *
 * @return array<string, mixed>
 */
function metadataMergeData(MetadataMergeProbeAsset $asset): array
{
    $collection = $asset->data();

    // `Asset::data()` is typed loosely (getter/setter in one), so narrow before use
    if (! \is_object($collection) || ! \method_exists($collection, 'all')) {
        return [];
    }

    $data = $collection->all();

    if (! \is_array($data)) {
        return [];
    }

    /** @var array<string, mixed> $keyed */
    $keyed = $data;

    return $keyed;
}

afterEach(function (): void {
    Mockery::close();
});

it('confirms the probe reproduces the full-replace semantics under test', function (): void {
    // Guards the test double itself: if `data()` ever stopped clobbering, every
    // ordering assertion below would pass vacuously.
    $asset = new MetadataMergeProbeAsset;

    $asset->data(['title' => 'Report', 'alt' => 'A chart']);
    $asset->data(['alt' => 'A chart']);

    expect(metadataMergeData($asset))->not->toHaveKey('title');
})->group('asset-metadata');

it('stamps the attachment page URL on the image sink', function (): void {
    // Inline body images reach only this sink, so wordpress-importer-archive#446's file-side stamp left most
    // attachments without the key. wordpress-importer-archive#489
    $asset = metadataMergeProbe();

    (new MetadataSetter)->setImageMetadata(
        'site/photo.jpg',
        'images',
        'A photo',
        'A caption',
        'https://old.uams.edu/wp-content/uploads/photo.jpg',
        null,
        null,
        'https://old.uams.edu/a-post/photo/'
    );

    expect(metadataMergeData($asset)['wordpress_attachment_page_url'] ?? null)
        ->toBe('https://old.uams.edu/a-post/photo/');
})->group('asset-metadata');

it('refuses an attachment page URL that is not an absolute http(s) URL', function (string $pageUrl): void {
    // The value comes straight from the WXR `<link>` — attacker-influenced input —
    // and is stored for the redirect generator to turn into a redirect target, so a
    // non-http scheme or an attribute-breaking character must never be stamped.
    $asset = metadataMergeProbe();

    (new MetadataSetter)->setImageMetadata(
        'site/photo.jpg',
        'images',
        'A photo',
        null,
        null,
        null,
        null,
        $pageUrl
    );

    expect(metadataMergeData($asset))->not->toHaveKey('wordpress_attachment_page_url');
})->with([
    'javascript scheme' => ['javascript:alert(1)'],
    'data scheme' => ['data:text/html,<script>alert(1)</script>'],
    'protocol relative' => ['//old.uams.edu/a-post/photo/'],
    'relative path' => ['/a-post/photo/'],
    'double quote' => ['https://old.uams.edu/a" onload="alert(1)'],
    'angle bracket' => ['https://old.uams.edu/a<script>'],
    'embedded space' => ['https://old.uams.edu/a b'],
])->group('asset-metadata');

it('omits the attachment page URL when the attachment carries none', function (?string $pageUrl): void {
    $asset = metadataMergeProbe();

    (new MetadataSetter)->setImageMetadata(
        'site/photo.jpg',
        'images',
        'A photo',
        null,
        'https://old.uams.edu/wp-content/uploads/photo.jpg',
        null,
        null,
        $pageUrl
    );

    expect(metadataMergeData($asset))->not->toHaveKey('wordpress_attachment_page_url');
})->with([
    'null' => [null],
    'empty' => [''],
    'whitespace' => ['   '],
])->group('asset-metadata');

it('trims the attachment page URL on the image sink', function (): void {
    $asset = metadataMergeProbe();

    (new MetadataSetter)->setImageMetadata(
        'site/photo.jpg',
        'images',
        null,
        null,
        null,
        null,
        null,
        '  https://old.uams.edu/a-post/photo/  '
    );

    expect(metadataMergeData($asset)['wordpress_attachment_page_url'] ?? null)
        ->toBe('https://old.uams.edu/a-post/photo/');
})->group('asset-metadata');

it('loses no key when both sinks touch one asset, in either order', function (string $order): void {
    // The featured-image-that-is-also-inline case. Before wordpress-importer-archive#489 the second sink
    // deleted every key the first had written that it does not itself set.
    $asset = metadataMergeProbe();
    $setter = new MetadataSetter;

    $file = static function () use ($setter): void {
        $setter->setFileMetadata(
            'site/photo.jpg',
            'images',
            'https://old.uams.edu/wp-content/uploads/photo.jpg',
            'Annual Report',
            null,
            'https://old.uams.edu/a-post/photo/'
        );
    };

    $image = static function () use ($setter): void {
        $setter->setImageMetadata(
            'site/photo.jpg',
            'images',
            'A photo',
            'A caption',
            'https://old.uams.edu/wp-content/uploads/photo.jpg',
            '50-50-1',
            'Jane Doe',
            'https://old.uams.edu/a-post/photo/'
        );
    };

    // Run the two sinks in the order under test
    if ($order === 'file-then-image') {
        $file();
        $image();
    } else {
        $image();
        $file();
    }

    $data = metadataMergeData($asset);

    // Written only by the file sink — the key wordpress-importer-archive#489 names as already being lost
    expect($data['title'] ?? null)->toBe('Annual Report');

    // Written by both sinks
    expect($data['original_wordpress_url'] ?? null)
        ->toBe('https://old.uams.edu/wp-content/uploads/photo.jpg');
    expect($data['wordpress_attachment_page_url'] ?? null)
        ->toBe('https://old.uams.edu/a-post/photo/');

    // Written only by the image sink
    expect($data['alt'] ?? null)->toBe('A photo');
    expect($data['caption'] ?? null)->toBe('A caption');
    expect($data['focus'] ?? null)->toBe('50-50-1');
    expect($data['creator'] ?? null)->toBe('Jane Doe');
    expect($data['exempt_from_alt'] ?? null)->toBeFalse();
})->with([
    'file-then-image',
    'image-then-file',
])->group('asset-metadata');

it('keeps the page URL when the second import through the other sink resolves none', function (
    string $order
): void {
    // The realistic erasure: only one of the two import paths knows the attachment
    // page URL. A merge keeps it; a replace drops it.
    $asset = metadataMergeProbe();
    $setter = new MetadataSetter;

    $withUrl = static function () use ($setter): void {
        $setter->setFileMetadata(
            'site/photo.jpg',
            'images',
            'https://old.uams.edu/wp-content/uploads/photo.jpg',
            'Annual Report',
            null,
            'https://old.uams.edu/a-post/photo/'
        );
    };

    $withoutUrl = static function () use ($setter): void {
        $setter->setImageMetadata(
            'site/photo.jpg',
            'images',
            'A photo',
            null,
            'https://old.uams.edu/wp-content/uploads/photo.jpg'
        );
    };

    // Run the two sinks in the order under test
    if ($order === 'url-first') {
        $withUrl();
        $withoutUrl();
    } else {
        $withoutUrl();
        $withUrl();
    }

    $data = metadataMergeData($asset);

    expect($data['wordpress_attachment_page_url'] ?? null)
        ->toBe('https://old.uams.edu/a-post/photo/');
    expect($data['title'] ?? null)->toBe('Annual Report');
    expect($data['alt'] ?? null)->toBe('A photo');
})->with([
    'url-first',
    'url-second',
])->group('asset-metadata');

it('still persists once per sink invocation', function (): void {
    // Merging must not change how often the asset is written.
    $asset = metadataMergeProbe();
    $setter = new MetadataSetter;

    $setter->setImageMetadata('site/photo.jpg', 'images', 'A photo', null);
    $setter->setFileMetadata('site/photo.jpg', 'images', null, 'Annual Report');

    expect($asset->saves)->toBe(2);
})->group('asset-metadata');

it('writes nothing through the file sink when it resolved no values', function (): void {
    // The pre-existing empty-payload guard must survive the merge change, so a
    // no-op file import cannot rewrite the asset.
    $asset = metadataMergeProbe();

    (new MetadataSetter)->setFileMetadata('site/photo.jpg', 'images');

    expect($asset->saves)->toBe(0);
})->group('asset-metadata');

it('forwards the attachment page URL from the image overwrite handler to the sink', function (): void {
    // Guards the wiring the sink tests above cannot see: `handleImageImport()` is
    // the only caller on the inline-image path, so a dropped argument there would
    // leave every inline attachment unstamped while the sink tests stayed green.
    Asset::shouldReceive('find')->andReturnNull();

    // Typed PHPUnit mocks satisfy the parameter types without a Mockery-specific
    // expectation API, matching the convention in the sibling Integration tests.
    $downloader = $this::createStub(AssetDownloader::class);
    $downloader->method('download')->willReturn('site/photo.jpg');

    $metadataSetter = $this->createMock(MetadataSetter::class);
    $metadataSetter->expects($this->once())
        ->method('setImageMetadata')
        ->with(
            'site/photo.jpg',
            'images',
            'A photo',
            'A caption',
            'https://old.uams.edu/wp-content/uploads/photo.jpg',
            '50-50-1',
            'Jane Doe',
            'https://old.uams.edu/a-post/photo/'
        );

    (new OverwriteHandler)->handleImageImport(
        'https://old.uams.edu/wp-content/uploads/photo.jpg',
        'site/photo.jpg',
        'site',
        new BufferedOutput,
        $downloader,
        $metadataSetter,
        'A photo',
        'A caption',
        null,
        '50-50-1',
        'Jane Doe',
        'https://old.uams.edu/a-post/photo/'
    );
})->group('asset-metadata');
