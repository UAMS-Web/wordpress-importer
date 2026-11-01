<?php

declare(strict_types=1);

/**
 * Tests for the Gravity Forms content detector and the BaseContentDetector walk it
 * runs (#1314).
 *
 * `hasFormPluginContent()` reports whether any item of any content type holds a
 * `wp:gravityforms/form` block, a `[gravityform id="N"]` shortcode (quoted
 * or unquoted id), or that shortcode inside an ACF block's JSON data, at any depth, whether or
 * not the block is self-closing (wordpress-importer-archive#1369).
 *
 * @command  php vendor/bin/pest tests/Unit/GravityFormsContentDetectorTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\BaseContentDetector;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\ContentDetector;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;

/**
 * An XmlParser whose content types hold items with the given bodies.
 *
 * @param  array<string, list<string>>  $bodiesByType  Content type => item bodies
 * @return XmlParser The parser
 */
function GravityFormsDetectorParser(array $bodiesByType): XmlParser
{
    $types = [];
    $itemsByType = [];

    foreach ($bodiesByType as $type => $bodies) {
        $types[] = [$type, \count($bodies)];

        $itemsByType[$type] = \array_map(
            static fn (string $body): SimpleXMLElement => new SimpleXMLElement(
                '<item xmlns:content="http://purl.org/rss/1.0/modules/content/"><content:encoded><![CDATA['.$body.']]></content:encoded></item>'
            ),
            $bodies
        );
    }

    return new class($types, $itemsByType) extends XmlParser
    {
        /**
         * Hold the content types and items the parser reports.
         *
         * @param  list<array{0: string, 1: int}>  $typeList  Content type and count pairs
         * @param  array<string, list<SimpleXMLElement>>  $itemsByType  Content type => items
         */
        public function __construct(private readonly array $typeList, private readonly array $itemsByType) {}

        /**
         * Return the configured content types.
         *
         * @return list<array{0: string, 1: int}> Content type and count pairs
         */
        public function getContentTypes(): array
        {
            return $this->typeList;
        }

        /**
         * Return the configured items for a content type.
         *
         * @param  string  $contentType  The content type
         * @return list<SimpleXMLElement> Its items
         */
        public function getItems(string $contentType): array
        {
            return $this->itemsByType[$contentType] ?? [];
        }
    };
}

/**
 * An ACF block comment whose JSON data is the given array.
 *
 * @param  array<string, mixed>  $data  The block data
 * @return string The block comment
 */
function GravityFormsAcfBlock(array $data): string
{
    return '<!-- wp:acf/uams-content '.\json_encode(['name' => 'acf/uams-content', 'data' => $data]).' /-->';
}

it('names its plugin', function (): void {
    expect((new ReflectionProperty(BaseContentDetector::class, 'pluginName'))->getValue(new ContentDetector))->toBe('GravityForms');
});

it('finds Gravity Forms content in an item body', function (string $body): void {
    expect((new ContentDetector)->hasFormPluginContent(GravityFormsDetectorParser(['page' => [$body]])))->toBeTrue();
})->with([
    'block' => ['<p>x</p><!-- wp:gravityforms/form {"formId":"3"} /-->'],
    'block with no space before the name' => ['<!--wp:gravityforms/form /-->'],
    'shortcode, double quotes' => ['Before [gravityform id="12"] after'],
    'shortcode, single quotes' => ["[gravityform id='12' title=\"false\"]"],
    'shortcode, other case' => ['[GRAVITYFORM ID="5"]'],
    'shortcode in ACF block data' => [GravityFormsAcfBlock(['content' => 'Fill in [gravityform id="4"] below'])],
    'shortcode nested in ACF block data' => [GravityFormsAcfBlock(['rows' => [['cells' => ['x', 'Use [gravityform id="4"]']]]])],
    'shortcode in a later ACF block' => [GravityFormsAcfBlock(['content' => 'none']).GravityFormsAcfBlock(['content' => '[gravityform id="9"]'])],
    'shortcode in an ACF block after one whose data is not JSON' => ['<!-- wp:acf/uams-content {not json} /-->'.GravityFormsAcfBlock(['content' => '[gravityform id="9"]'])],
    'shortcode with an unquoted id (wordpress-importer-archive#1369)' => ['[gravityform id=3]'],
    'shortcode with an unquoted id, then other attributes' => ['[gravityform id=3 title="false"]'],
    'shortcode in the data of an ACF block that is not self-closing (wordpress-importer-archive#1369)' => ['<!-- wp:acf/uams-content {"data":{"c":"[gravityform id=\"1\"]"}} -->'],
    'shortcode in an ACF block after one with inner blocks (wordpress-importer-archive#1369)' => ['<!-- wp:acf/a {"x":1} --><p>inner</p><!-- /wp:acf/a -->'.GravityFormsAcfBlock(['content' => '[gravityform id="4"]'])],
    'shortcode in an ACF block after an opener whose data is not JSON' => ['<!-- wp:acf/a {not json -->'.GravityFormsAcfBlock(['content' => '[gravityform id="4"]'])],
    'shortcode in an ACF block with no space after the comment opener' => ['<!--wp:acf/uams-content {"data":{"c":"[gravityform id=\\"4\\"]"}}/-->'],
    'shortcode in an ACF block that is not self-closing, with no space before the comment end' => ['<!-- wp:acf/uams-content {"data":{"c":"[gravityform id=\\"4\\"]"}}--><p>inner</p><!-- /wp:acf/uams-content -->'],
    'shortcode in an ACF block after an empty comment' => ['<!---->'.GravityFormsAcfBlock(['content' => '[gravityform id="4"]'])],
    'shortcode in an ACF block after an abruptly closed comment' => ['<!-->'.GravityFormsAcfBlock(['content' => '[gravityform id="4"]'])],
    'shortcode in an ACF block after a comment that holds another opener' => ['<!-- a <!-->'.GravityFormsAcfBlock(['content' => '[gravityform id="4"]'])],
    // Past PCRE's backtrack limit, where a regular-expression scan returned false without notice
    'shortcode at the end of a 2 MB ACF block' => [GravityFormsAcfBlock(['content' => \str_repeat('x-', 1_000_000).'[gravityform id="4"]'])],
]);

it('finds no Gravity Forms content where there is none', function (string $body): void {
    expect((new ContentDetector)->hasFormPluginContent(GravityFormsDetectorParser(['page' => [$body]])))->toBeFalse();
})->with([
    'plain content' => ['<p>No forms here.</p>'],
    'the other plugin\'s block' => ['<!-- wp:formidable/simple-form {"formId":"3"} /-->'],
    'the other plugin\'s shortcode' => ['[formidable id="3"]'],
    'block name with a longer suffix' => ['<!-- wp:gravityforms/formx /-->'],
    'shortcode without an id' => ['[gravityform title="x"]'],
    'shortcode with a non-numeric id' => ['[gravityform id="abc"]'],
    'shortcode with an unquoted id that runs into letters' => ['[gravityform id=3x]'],
    'shortcode with an id missing its closing quote' => ['[gravityform id="3]'],
    'ACF block comment that is never closed' => ['<p>x</p><!-- wp:acf/uams-content {"data":{"c":"x"}}'],
    'ACF data without the shortcode' => [GravityFormsAcfBlock(['content' => 'Plain', 'n' => 3, 'flag' => true])],
    'ACF data that is not JSON' => ['<!-- wp:acf/uams-content {not json} /-->'],
    'empty body' => [''],
]);

it('checks every item of every content type', function (): void {
    $parser = GravityFormsDetectorParser([
        'attachment' => ['<p>a</p>'],
        'page' => ['<p>b</p>', '<p>c</p>'],
        'post' => ['<p>d</p>', 'Contact us: [gravityform id="1"]'],
    ]);

    expect((new ContentDetector)->hasFormPluginContent($parser))->toBeTrue();
});

it('finds a block in a later item', function (): void {
    $parser = GravityFormsDetectorParser(['page' => ['<p>plain</p>', '<!-- wp:gravityforms/form /-->']]);

    expect((new ContentDetector)->hasFormPluginContent($parser))->toBeTrue();
});

it('finds nothing in an export with no content types', function (): void {
    expect((new ContentDetector)->hasFormPluginContent(GravityFormsDetectorParser([])))->toBeFalse();
});
