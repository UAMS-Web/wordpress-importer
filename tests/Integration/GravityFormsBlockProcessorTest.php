<?php

declare(strict_types=1);

/**
 * Tests for GravityFormsBlockProcessor and the FormBlockProcessorTrait it uses.
 *
 * `canProcess()` accepts a `wp:gravityforms/form` comment, with or without a
 * JSON attribute object and a self-closing slash. `process()` tracks the form
 * ID, then emits a form Bard set: the created form handle when one exists,
 * otherwise an import note naming the form ID. Gravity Forms shows the title
 * and description unless the block turns them off, so a missing `title` or
 * `description` attribute switches each on. A missing, empty or non-numeric
 * form ID logs a warning and returns null.
 *
 * This is in the light Statamic band because the warning goes through the
 * `Log` facade.
 *
 * @command  php vendor/bin/pest tests/Integration/GravityFormsBlockProcessorTest.php
 */

use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Tiptap\SetMarkupNonce;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\GravityFormsBlockProcessor;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * Replace the processor's created-handle cache.
 *
 * @param  array<array-key, string>  $handles  Form ID => Statamic form handle
 */
function setGravityBlockHandles(array $handles): void
{
    new ReflectionProperty(GravityFormsBlockProcessor::class, 'createdGravityFormsHandles')->setValue(null, $handles);
}

/**
 * Run the processor over a fresh opening comment.
 *
 * @param  array<int|string, mixed>  $attributes  Block attributes
 * @param  ?string  $pageSlug  The page slug passed to the processor
 * @return array<int|string, mixed>|null The processor's result
 */
function processGravityBlock(array $attributes, ?string $pageSlug = null): ?array
{
    $node = (new DOMDocument)->createComment(' wp:gravityforms/form '.json_encode($attributes).' /');

    $result = (new GravityFormsBlockProcessor)->process(
        $node,
        $attributes,
        'default',
        new ReflectionClass(AssetImporter::class)->newInstanceWithoutConstructor(),
        new BufferedOutput,
        [],
        [],
        $pageSlug
    );

    if ($result !== null) {
        expect($result['processedNode'] ?? null)->toBe($node);
    }

    return $result;
}

beforeEach(function (): void {
    GravityFormsBlockProcessor::clearFormIds();
    setGravityBlockHandles([]);
    ProcessingContext::clearWordPressPageLink();
    ProcessingContext::clearFormConfigContext();
});

afterEach(function (): void {
    GravityFormsBlockProcessor::clearFormIds();
    setGravityBlockHandles([]);
    ProcessingContext::clearWordPressPageLink();
});

it('recognizes a wp:gravityforms/form comment', function (string $comment): void {
    $node = (new DOMDocument)->createComment($comment);

    expect((new GravityFormsBlockProcessor)->canProcess($node, 'default', null))->toBeTrue();
})->with([
    'bare' => ['wp:gravityforms/form'],
    'surrounding whitespace' => ['  wp:gravityforms/form  '],
    'self-closing' => ['wp:gravityforms/form /'],
    'with attributes' => ['wp:gravityforms/form {"formId":"3"}'],
    'with attributes, self-closing' => [' wp:gravityforms/form {"formId":"3"} / '],
    'attributes spanning lines' => ["wp:gravityforms/form {\n\"formId\":\"3\"\n} /"],
]);

it('rejects comments that are not a wp:gravityforms/form block', function (string $comment): void {
    $node = (new DOMDocument)->createComment($comment);

    expect((new GravityFormsBlockProcessor)->canProcess($node, 'default', null))->toBeFalse();
})->with([
    'closing comment' => ['/wp:gravityforms/form'],
    'formidable block' => ['wp:formidable/simple-form {"formId":"3"}'],
    'longer block name' => ['wp:gravityforms/formx'],
    'prefixed' => ['x wp:gravityforms/form'],
    'trailing text' => ['wp:gravityforms/form {"formId":"3"} extra'],
    'empty attribute braces' => ['wp:gravityforms/form {}'],
]);

it('rejects nodes that are not comments', function (DOMNode $node): void {
    expect((new GravityFormsBlockProcessor)->canProcess($node, 'default', null))->toBeFalse();
})->with([
    'text node holding the block name' => fn (): DOMNode => (new DOMDocument)->createTextNode('wp:gravityforms/form'),
    'element' => fn (): DOMNode => (new DOMDocument)->createElement('div', 'wp:gravityforms/form'),
]);

it('emits an import note and shows title and description when the block does not set them', function (): void {
    $result = processGravityBlock(['formId' => '3']);

    expect($result)->toBe([
        'html' => '<div data-set-type="form"'.SetMarkupNonce::attribute()
            .' data-set-attr-import_wordpress_notes="Gravity Forms form ID 3"'
            .' data-set-attr-heading_query="1"'
            .' data-set-attr-heading_level="h2"'
            .' data-set-attr-description_query="1"></div>',
        'processedNode' => $result['processedNode'] ?? null,
        'skipCount' => 1,
    ])
        ->and(GravityFormsBlockProcessor::getFormIds())->toBe(['3']);
});

it('uses the created form handle in place of the import note', function (): void {
    setGravityBlockHandles(['3' => 'contact_us', '4' => 'other']);

    $result = processGravityBlock(['formId' => 3, 'title' => false, 'description' => false]);

    // An integer form ID is looked up by its string form
    expect($result['html'] ?? null)->toBe(
        '<div data-set-type="form"'.SetMarkupNonce::attribute().' data-set-attr-form="contact_us"></div>'
    )
        ->and(GravityFormsBlockProcessor::getFormIds())->toBe(['3']);
});

it('leaves the title or description off when the block sets that attribute', function (array $attributes, string $expectedAttrs): void {
    $result = processGravityBlock(['formId' => '5', ...$attributes]);

    expect($result['html'] ?? null)->toBe(
        '<div data-set-type="form"'.SetMarkupNonce::attribute()
        .' data-set-attr-import_wordpress_notes="Gravity Forms form ID 5"'
        .$expectedAttrs.'></div>'
    );
})->with([
    'title set' => [
        ['title' => false],
        ' data-set-attr-description_query="1"',
    ],
    'description set' => [
        ['description' => false],
        ' data-set-attr-heading_query="1" data-set-attr-heading_level="h2"',
    ],
]);

it('escapes form handles in the set markup', function (): void {
    setGravityBlockHandles(['6' => 'a"b<c']);

    $result = processGravityBlock(['formId' => '6', 'title' => false, 'description' => false]);

    expect($result['html'] ?? null)->toBe(
        '<div data-set-type="form"'.SetMarkupNonce::attribute().' data-set-attr-form="a&quot;b&lt;c"></div>'
    );
});

it('escapes attribute names in the set markup', function (): void {
    $processor = new class extends GravityFormsBlockProcessor
    {
        /**
         * Add a value whose key needs escaping.
         *
         * @param  array<int|string, mixed>  $values  The values array to modify
         * @param  array<int|string, mixed>  $attributes  The block attributes
         */
        public function handleTitleAndDescription(array &$values, array $attributes): void
        {
            $values['a"b'] = 'x';
        }
    };

    $result = $processor->process(
        (new DOMDocument)->createComment(' wp:gravityforms/form {"formId":"5"} /'),
        ['formId' => '5'],
        'default',
        new ReflectionClass(AssetImporter::class)->newInstanceWithoutConstructor(),
        new BufferedOutput,
        [],
        []
    );

    expect($result['html'] ?? null)->toContain('data-set-attr-a&quot;b="x"');
});

it('accepts a numeric float form ID and tracks its string form', function (): void {
    $result = processGravityBlock(['formId' => 7.0, 'title' => false, 'description' => false]);

    expect($result['html'] ?? null)->toBe(
        '<div data-set-type="form"'.SetMarkupNonce::attribute()
        .' data-set-attr-import_wordpress_notes="Gravity Forms form ID 7"></div>'
    )
        ->and(GravityFormsBlockProcessor::getFormIds())->toBe(['7']);
});

it('tracks every processed form ID in order', function (): void {
    processGravityBlock(['formId' => '2']);
    processGravityBlock(['formId' => 9]);

    expect(GravityFormsBlockProcessor::getFormIds())->toBe(['2', '9']);
});

it('warns and returns null when the form ID is missing, empty or not numeric', function (array $attributes): void {
    $log = Log::spy();

    expect(processGravityBlock($attributes, 'contact'))->toBeNull()
        ->and(GravityFormsBlockProcessor::getFormIds())->toBe([]);

    $log->shouldHaveReceived('warning', [
        'Gravity FormsBlockProcessor::process: Invalid or missing formId in Gravity Forms',
        [
            'site' => 'default',
            'pageSlug' => 'contact',
            'form_id' => 'none',
            'attributes' => $attributes,
        ],
    ]);
})->with([
    'no attributes' => [[]],
    'empty string' => [['formId' => '']],
    'null' => [['formId' => null]],
    'not numeric' => [['formId' => 'abc']],
    'array' => [['formId' => ['3']]],
]);

it('names the page slug as none and appends the entry URL to the warning', function (): void {
    ProcessingContext::setWordPressPageLink('https://example.test/contact/');
    $log = Log::spy();

    expect(processGravityBlock([]))->toBeNull();

    $log->shouldHaveReceived('warning', [
        'Gravity FormsBlockProcessor::process: Invalid or missing formId in Gravity Forms (from entry: https://example.test/contact/)',
        [
            'site' => 'default',
            'pageSlug' => 'none',
            'form_id' => 'none',
            'attributes' => [],
            'entry_url' => 'https://example.test/contact/',
        ],
    ]);
});
