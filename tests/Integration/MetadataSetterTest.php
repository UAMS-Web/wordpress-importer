<?php

declare(strict_types=1);

/**
 * Tests `MetadataSetter`: which values each sink writes to an asset, the
 * blueprint gate on the extras a file sink is handed, the attachment-page URL
 * check, and the warning each sink logs when it has no asset to write to.
 *
 * `AssetMetadataMergeTest` covers the two sinks merging over each other. This
 * file covers the rest, against a probe asset whose stored data, blueprint and
 * data reads are set per test, so no container is touched.
 *
 * @command  php vendor/bin/pest tests/Integration/MetadataSetterTest.php
 */

use Illuminate\Support\Facades\Log;
use Statamic\Assets\Asset as StatamicAsset;
use Statamic\Contracts\Assets\Asset as AssetContract;
use Statamic\Facades\Asset;
use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Fields\Blueprint;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\MetadataSetter;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * An in-memory asset whose blueprint and data reads a test controls.
 */
final class MetadataSetterTestAsset extends StatamicAsset
{
    /**
     * How many times the asset was saved.
     */
    public int $saves = 0;

    /**
     * How many times MetadataSetter read the blueprint.
     */
    public int $blueprintReads = 0;

    /**
     * Whether a data read returns the stored data, or what to return instead.
     */
    public mixed $dataRead = true;

    /**
     * Start with the given data, a blueprint defining the given handles, and
     * hydration disabled so nothing reads a container.
     *
     * @param  array<string, mixed>  $data  The stored data
     * @param  list<string>  $handles  The handles the blueprint defines
     */
    public function __construct(array $data = [], public array $handles = ['alt', 'caption', 'creator'])
    {
        parent::__construct();

        $this->shouldHydrate = false;
        $this->meta = ['data' => []];
        parent::data($data);
    }

    /**
     * Read or write the asset's data, reading what the test chose.
     *
     * @param  mixed  $data  The data to write, or null to read
     * @return mixed The data, or this asset after a write
     */
    public function data($data = null)
    {
        // A read passes no argument at all: an explicit null is a write
        if (\func_num_args() === 0) {
            return $this->dataRead === true ? parent::data() : $this->dataRead;
        }

        return parent::data($data);
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
     * Return a blueprint defining the chosen handles, counting the reads
     * MetadataSetter makes, apart from those Statamic's data writes make.
     *
     * @return Blueprint The blueprint
     */
    public function blueprint()
    {
        if ((\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['class'] ?? null) === MetadataSetter::class) {
            $this->blueprintReads++;
        }

        $blueprint = BlueprintFacade::make();

        $blueprint->setContents([
            'fields' => \array_map(
                static fn (string $handle): array => ['handle' => $handle, 'field' => ['type' => 'text']],
                $this->handles
            ),
        ]);

        return $blueprint;
    }

    /**
     * The stored data as a plain array.
     *
     * @return array<int|string, mixed> The data
     */
    public function stored(): array
    {
        $collection = parent::data();

        if (! \is_object($collection) || ! \method_exists($collection, 'all')) {
            return [];
        }

        $data = $collection->all();

        return \is_array($data) ? $data : [];
    }
}

/**
 * Point the Asset facade at the given asset, recording the IDs looked up.
 *
 * @param  mixed  $asset  What a lookup returns
 * @return ArrayObject<int, mixed> The IDs looked up
 */
function metadataSetterFind(mixed $asset): ArrayObject
{
    $lookups = new ArrayObject;

    Asset::shouldReceive('find')->andReturnUsing(static function (mixed $id) use ($asset, $lookups): mixed {
        $lookups->append($id);

        return $asset;
    });

    return $lookups;
}

/**
 * Record the warnings logged.
 *
 * @return ArrayObject<int, mixed> Each warning's message and context
 */
function metadataSetterWarnings(): ArrayObject
{
    $warnings = new ArrayObject;

    Log::shouldReceive('warning')->andReturnUsing(static function (mixed $message, mixed $context = []) use ($warnings): void {
        $warnings->append([$message, $context]);
    });

    return $warnings;
}

afterEach(function (): void {
    Mockery::close();
});

describe('setting image metadata', function (): void {
    it('writes each value it is given, stripping markup from the text', function (): void {
        $asset = new MetadataSetterTestAsset;
        $lookups = metadataSetterFind($asset);

        (new MetadataSetter)->setImageMetadata(
            'news/photo.jpg',
            'images',
            '<b>Alt</b>',
            '<i>Caption</i>',
            'https://example.org/photo.jpg',
            '40-60-1',
            '<span>Credit</span>',
            ' https://example.org/?attachment_id=1 '
        );

        expect($lookups->getArrayCopy())->toBe(['images::news/photo.jpg'])
            ->and($asset->stored())->toBe([
                'exempt_from_alt' => false,
                'alt' => 'Alt',
                'caption' => 'Caption',
                'original_wordpress_url' => 'https://example.org/photo.jpg',
                'focus' => '40-60-1',
                'creator' => 'Credit',
                'wordpress_attachment_page_url' => 'https://example.org/?attachment_id=1',
            ])
            ->and($asset->saves)->toBe(1);
    });

    it('writes only the alt exemption when every value is missing or empty', function (?string $value): void {
        $asset = new MetadataSetterTestAsset;
        metadataSetterFind($asset);

        (new MetadataSetter)->setImageMetadata('news/photo.jpg', 'images', $value, $value, $value, $value, $value, $value);

        expect($asset->stored())->toBe(['exempt_from_alt' => false])
            ->and($asset->saves)->toBe(1);
    })->with([
        'missing' => [null],
        'empty' => [''],
    ]);

    it('keeps the asset\'s other data', function (): void {
        $asset = new MetadataSetterTestAsset(['title' => 'Kept', 'alt' => 'Old']);
        metadataSetterFind($asset);

        (new MetadataSetter)->setImageMetadata('news/photo.jpg', 'images', 'New', null);

        expect($asset->stored())->toBe(['title' => 'Kept', 'alt' => 'New', 'exempt_from_alt' => false]);
    });

    it('warns and writes nothing for an asset outside the images container, or no asset', function (string $kind, string $container, bool $exists): void {
        $found = match ($kind) {
            'probe' => new MetadataSetterTestAsset,
            'contract' => Mockery::mock(AssetContract::class),
            default => null,
        };
        $warnings = metadataSetterWarnings();
        metadataSetterFind($found);

        (new MetadataSetter)->setImageMetadata('news/photo.jpg', $container, 'Alt', 'Caption');

        expect($warnings->getArrayCopy())->toBe([[
            'MetadataSetter::setImageMetadata: Failed to set metadata for asset',
            ['path' => 'news/photo.jpg', 'container' => $container, 'asset_exists' => $exists, 'is_images_container' => $container === 'images'],
        ]]);

        if ($found instanceof MetadataSetterTestAsset) {
            expect($found->saves)->toBe(0)
                ->and($found->stored())->toBe([]);
        }
    })->with([
        'an asset in another container' => ['probe', 'portraits', true],
        'no asset' => ['none', 'images', false],
        'an asset that cannot be written' => ['contract', 'images', false],
    ]);
});

describe('setting file metadata', function (): void {
    it('writes the source URL, page URL, title and extras', function (): void {
        $asset = new MetadataSetterTestAsset(['alt' => 'Kept']);
        $lookups = metadataSetterFind($asset);

        (new MetadataSetter)->setFileMetadata(
            'docs/report.pdf',
            'files',
            'https://example.org/report.pdf',
            'Report',
            ['size' => 5, 'title' => 'Extra title'],
            'https://example.org/?attachment_id=2'
        );

        expect($lookups->getArrayCopy())->toBe(['files::docs/report.pdf'])
            ->and($asset->stored())->toBe([
                'alt' => 'Kept',
                'original_wordpress_url' => 'https://example.org/report.pdf',
                'wordpress_attachment_page_url' => 'https://example.org/?attachment_id=2',
                'title' => 'Extra title',
                'size' => 5,
            ])
            ->and($asset->saves)->toBe(1);
    });

    it('writes nothing when every value is missing or empty', function (?string $value, ?array $extras): void {
        $asset = new MetadataSetterTestAsset(['alt' => 'Kept']);
        metadataSetterFind($asset);

        (new MetadataSetter)->setFileMetadata('docs/report.pdf', 'files', $value, $value, $extras, $value);

        expect($asset->stored())->toBe(['alt' => 'Kept'])
            ->and($asset->saves)->toBe(0)
            ->and($asset->blueprintReads)->toBe(0);
    })->with([
        'missing' => [null, null],
        'empty' => ['', []],
    ]);

    it('writes a single value on its own', function (?string $url, ?string $title, ?array $extras, array $expected): void {
        $asset = new MetadataSetterTestAsset;
        metadataSetterFind($asset);

        (new MetadataSetter)->setFileMetadata('docs/report.pdf', 'files', $url, $title, $extras);

        expect($asset->stored())->toBe($expected)
            ->and($asset->saves)->toBe(1);
    })->with([
        'the source URL' => ['https://example.org/r.pdf', null, null, ['original_wordpress_url' => 'https://example.org/r.pdf']],
        'the title' => [null, 'Report', null, ['title' => 'Report']],
        'an extra' => [null, null, ['size' => 5], ['size' => 5]],
    ]);

    it('warns and writes nothing when there is no asset', function (bool $contract): void {
        $warnings = metadataSetterWarnings();
        metadataSetterFind($contract ? Mockery::mock(AssetContract::class) : null);

        (new MetadataSetter)->setFileMetadata('docs/report.pdf', 'files', 'https://example.org/r.pdf');

        expect($warnings->getArrayCopy())->toBe([[
            'MetadataSetter::setFileMetadata: Failed to set metadata for asset',
            ['path' => 'docs/report.pdf', 'container' => 'files', 'asset_exists' => false],
        ]]);
    })->with([
        'no asset' => [false],
        'an asset that cannot be written' => [true],
    ]);
});

describe('gating the extras on the blueprint', function (): void {
    it('drops a gated handle the blueprint does not define and keeps every other handle', function (): void {
        $asset = new MetadataSetterTestAsset([], ['caption']);
        metadataSetterFind($asset);

        (new MetadataSetter)->setFileMetadata('docs/report.pdf', 'files', additionalMetadata: [
            'alt' => 'Dropped',
            'caption' => 'Kept',
            'creator' => 'Dropped',
            'size' => 5,
            7 => 'numeric key',
        ]);

        // Spreading the extras renumbers a numeric key, as it always has
        expect($asset->stored())->toBe(['caption' => 'Kept', 'size' => 5, 0 => 'numeric key']);
    });

    it('drops each gated handle on its own when the blueprint does not define it', function (string $handle): void {
        $asset = new MetadataSetterTestAsset([], []);
        metadataSetterFind($asset);

        (new MetadataSetter)->setFileMetadata('docs/report.pdf', 'files', additionalMetadata: [$handle => 'Dropped', 'size' => 5]);

        expect($asset->stored())->toBe(['size' => 5]);
    })->with(['alt', 'caption', 'creator']);

    it('keeps every gated handle the blueprint defines', function (): void {
        $asset = new MetadataSetterTestAsset;
        metadataSetterFind($asset);

        (new MetadataSetter)->setFileMetadata('docs/report.pdf', 'files', additionalMetadata: ['alt' => 'A', 'caption' => 'C', 'creator' => 'R']);

        expect($asset->stored())->toBe(['alt' => 'A', 'caption' => 'C', 'creator' => 'R']);
    });

    it('does not read the blueprint when no extra is a gated handle', function (array $extras): void {
        $asset = new MetadataSetterTestAsset([], []);
        metadataSetterFind($asset);

        (new MetadataSetter)->setFileMetadata('docs/report.pdf', 'files', additionalMetadata: $extras);

        expect($asset->blueprintReads)->toBe(0)
            ->and($asset->stored())->toBe($extras);
    })->with([
        'other handles' => [['size' => 5]],
        'a gated handle\'s name as a value' => [['label' => 'alt']],
    ]);
});

describe('checking the attachment page URL', function (): void {
    it('stamps only an absolute http or https URL', function (string $url, ?string $expected): void {
        $asset = new MetadataSetterTestAsset;
        metadataSetterFind($asset);

        (new MetadataSetter)->setFileMetadata('docs/report.pdf', 'files', title: 'T', attachmentPageUrl: $url);

        expect($asset->stored()['wordpress_attachment_page_url'] ?? null)->toBe($expected);
    })->with([
        'https' => ['https://example.org/a/', 'https://example.org/a/'],
        'http in capitals, padded' => ["  HTTP://example.org/a/\n", 'HTTP://example.org/a/'],
        'blank' => ['   ', null],
        'a relative path' => ['/a/', null],
        'a javascript URL' => ['javascript:alert(1)', null],
        'a quote' => ['https://example.org/"onmouseover', null],
        'a space inside' => ['https://example.org/a b', null],
        'an angle bracket' => ['https://example.org/<a>', null],
        'a backtick' => ['https://example.org/`a`', null],
        'a backslash' => ['https://example.org/a\\b', null],
        'a single quote' => ["https://example.org/a'b", null],
        'a scheme with nothing after it' => ['https://', null],
        'text before the scheme' => ['see https://example.org/', null],
    ]);
});

describe('reading the asset\'s existing data', function (): void {
    it('writes over nothing when the asset\'s data cannot be read', function (mixed $read): void {
        $asset = new MetadataSetterTestAsset(['title' => 'Lost']);
        $asset->dataRead = $read;
        metadataSetterFind($asset);

        (new MetadataSetter)->setFileMetadata('docs/report.pdf', 'files', title: 'New');
        $asset->dataRead = true;

        expect($asset->stored())->toBe(['title' => 'New']);
    })->with([
        'not an object' => [5],
        'an object with no way to list it' => [new stdClass],
        'a list that is not an array' => [new class
        {
            /**
             * Return something other than an array.
             */
            public function all(): string
            {
                return 'none';
            }
        }],
    ]);
});
