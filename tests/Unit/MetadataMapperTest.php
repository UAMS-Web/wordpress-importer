<?php

declare(strict_types=1);

/**
 * Tests that `MetadataMapper` hands each item's parsed postmeta and post type to the
 * field mapper, returns the mapper's writes to the caller's data array, and passes the
 * parsed and mapped keys to the key tracker (#1314).
 *
 * @command  php vendor/bin/pest tests/Unit/MetadataMapperTest.php
 */

use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\FieldMapper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\KeyTracker;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\MetaDataParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;

afterEach(function (): void {
    ProcessingContext::clearXmlPath();
});

/**
 * Field mapper double that records each call, writes one field, and reports one key.
 */
class MetadataMapperTestFieldMapper extends FieldMapper
{
    /**
     * The arguments of each call, in order.
     *
     * @var list<array{metaData: array<int|string, mixed>, data: array<int|string, mixed>, postType: string, xmlParser: XmlParser, assetImporter: AssetImporter, attachments: array<int|string, mixed>, output: OutputInterface}>
     */
    public array $calls = [];

    /**
     * Record the call, write a field into the data, and report one mapped key.
     *
     * @param  array<int|string, mixed>  $metaData
     * @param  array<int|string, mixed>  &$data
     * @param  array<int|string, mixed>  $attachments
     * @return array<int|string, mixed>
     */
    public function mapFields(
        array $metaData,
        array &$data,
        string $postType,
        XmlParser $xmlParser,
        AssetImporter $assetImporter,
        array $attachments,
        OutputInterface $output
    ): array {
        $this->calls[] = [
            'metaData' => $metaData,
            'data' => $data,
            'postType' => $postType,
            'xmlParser' => $xmlParser,
            'assetImporter' => $assetImporter,
            'attachments' => $attachments,
            'output' => $output,
        ];
        $data['subtitle'] = 'mapped';

        return ['subtitle'];
    }
}

/**
 * Key tracker double that records each call and reports fixed unaddressed keys.
 */
class MetadataMapperTestKeyTracker extends KeyTracker
{
    /**
     * The arguments of each call, in order.
     *
     * @var list<array{page: SimpleXMLElement, metaKeys: array<int|string, mixed>, mappedKeys: array<int|string, mixed>}>
     */
    public array $calls = [];

    /**
     * Record the call.
     *
     * @param  array<int|string, mixed>  $metaKeys
     * @param  array<int|string, mixed>  $mappedKeys
     */
    public function trackKeys(
        SimpleXMLElement $page,
        array $metaKeys,
        array $mappedKeys
    ): void {
        $this->calls[] = [
            'page' => $page,
            'metaKeys' => $metaKeys,
            'mappedKeys' => $mappedKeys,
        ];
    }

    /**
     * Report a fixed set of unaddressed keys.
     *
     * @return array<int|string, mixed>
     */
    public function getUnaddressedKeys(): array
    {
        return ['orphan_key'];
    }
}

/**
 * Metadata parser double that reports fixed metadata whatever the item holds.
 */
class MetadataMapperTestParser extends MetaDataParser
{
    /**
     * Report fixed metadata.
     *
     * @return array<int|string, mixed>
     */
    public function parse(SimpleXMLElement $page): array
    {
        return ['injected' => 'value'];
    }
}

/**
 * XML parser double that reports a fixed export path.
 */
class MetadataMapperTestXmlParser extends XmlParser
{
    /**
     * Report the fixed export path.
     */
    public function getFilePath(): ?string
    {
        return '/exports/site.xml';
    }
}

/**
 * Build a WXR item carrying a post type and two postmeta rows.
 */
function metadataMapperItem(): SimpleXMLElement
{
    $xml = <<<'XML'
<rss xmlns:wp="http://wordpress.org/export/1.2/">
  <channel>
    <item>
      <wp:post_type>page</wp:post_type>
      <wp:postmeta><wp:meta_key>subtitle</wp:meta_key><wp:meta_value>Hello</wp:meta_value></wp:postmeta>
      <wp:postmeta><wp:meta_key>_edit_lock</wp:meta_key><wp:meta_value>1</wp:meta_value></wp:postmeta>
    </item>
  </channel>
</rss>
XML;

    $root = simplexml_load_string($xml);

    if (! $root instanceof SimpleXMLElement) {
        throw new RuntimeException('The fixture item did not parse.');
    }

    return $root->channel->item;
}

/**
 * An asset importer that is never used, built without the configuration its
 * constructor reads.
 */
function metadataMapperAssetImporter(): AssetImporter
{
    return (new ReflectionClass(AssetImporter::class))->newInstanceWithoutConstructor();
}

it('maps an item through the field mapper and tracks its keys', function (): void {
    $fieldMapper = new MetadataMapperTestFieldMapper;
    $keyTracker = new MetadataMapperTestKeyTracker;
    $mapper = new MetadataMapper(null, $fieldMapper, $keyTracker);

    $xmlParser = new MetadataMapperTestXmlParser;
    $assetImporter = metadataMapperAssetImporter();
    $output = new BufferedOutput;
    $item = metadataMapperItem();
    $data = ['title' => 'Kept'];

    $mapper->mapMetadata($item, $data, $xmlParser, $assetImporter, ['attachment'], $output);

    // The export's path is published for link processing
    expect(ProcessingContext::getXmlPath())->toBe('/exports/site.xml');

    // The field mapper receives the parsed postmeta, the post type and every collaborator
    expect($fieldMapper->calls)->toHaveCount(1)
        ->and($fieldMapper->calls[0]['metaData'] ?? null)->toBe(['subtitle' => 'Hello', '_edit_lock' => '1'])
        ->and($fieldMapper->calls[0]['data'] ?? null)->toBe(['title' => 'Kept'])
        ->and($fieldMapper->calls[0]['postType'] ?? null)->toBe('page')
        ->and($fieldMapper->calls[0]['xmlParser'] ?? null)->toBe($xmlParser)
        ->and($fieldMapper->calls[0]['assetImporter'] ?? null)->toBe($assetImporter)
        ->and($fieldMapper->calls[0]['attachments'] ?? null)->toBe(['attachment'])
        ->and($fieldMapper->calls[0]['output'] ?? null)->toBe($output);

    // The mapper's write reaches the caller's array
    expect($data)->toBe(['title' => 'Kept', 'subtitle' => 'mapped']);

    // The tracker receives the item, every parsed key, and the keys the mapper reported
    expect($keyTracker->calls)->toHaveCount(1)
        ->and($keyTracker->calls[0]['page'] ?? null)->toBe($item)
        ->and($keyTracker->calls[0]['metaKeys'] ?? null)->toBe(['subtitle', '_edit_lock'])
        ->and($keyTracker->calls[0]['mappedKeys'] ?? null)->toBe(['subtitle']);
});

it('parses the item with the parser it was given', function (): void {
    $fieldMapper = new MetadataMapperTestFieldMapper;
    $keyTracker = new MetadataMapperTestKeyTracker;
    $data = [];

    (new MetadataMapper(new MetadataMapperTestParser, $fieldMapper, $keyTracker))->mapMetadata(
        metadataMapperItem(),
        $data,
        new MetadataMapperTestXmlParser,
        metadataMapperAssetImporter(),
        [],
        new BufferedOutput
    );

    expect($fieldMapper->calls[0]['metaData'] ?? null)->toBe(['injected' => 'value'])
        ->and($keyTracker->calls[0]['metaKeys'] ?? null)->toBe(['injected']);
});

it('reports the unaddressed keys of the tracker it was given', function (): void {
    $mapper = new MetadataMapper(null, null, new MetadataMapperTestKeyTracker);

    expect($mapper->getUnaddressedKeys())->toBe(['orphan_key']);
});

it('reports no unaddressed keys from a default tracker before any mapping', function (): void {
    expect((new MetadataMapper)->getUnaddressedKeys())->toBe([]);
});
