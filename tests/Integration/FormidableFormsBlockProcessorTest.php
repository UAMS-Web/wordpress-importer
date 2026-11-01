<?php

declare(strict_types=1);

/**
 * Tests for FormidableFormsBlockProcessor.
 *
 * `canProcess()` accepts a `wp:formidable/simple-form` comment, with or without
 * a JSON attribute object and a self-closing slash. `process()` emits a form
 * Bard set with a skip count of 2, using the created Formidable handle when
 * one exists. Formidable hides the title and description unless the block
 * sets them to the string `'1'`, the reverse of Gravity Forms. The shared
 * trait paths are covered in GravityFormsBlockProcessorTest; this file pins
 * the Formidable-specific values.
 *
 * This is in the light Statamic band because the warning goes through the
 * `Log` facade.
 *
 * @command  php vendor/bin/pest tests/Integration/FormidableFormsBlockProcessorTest.php
 */

use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Tiptap\SetMarkupNonce;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\FormidableFormsBlockProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\GravityFormsBlockProcessor;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * Replace one of a processor's created-handle caches.
 *
 * @param  class-string  $class  The processor class owning the cache
 * @param  string  $property  The static cache property
 * @param  array<array-key, string>  $handles  Form ID => Statamic form handle
 */
function setFormidableBlockHandles(string $class, string $property, array $handles): void
{
    (new ReflectionProperty($class, $property))->setValue(null, $handles);
}

/**
 * Run the processor over a fresh opening comment.
 *
 * @param  array<int|string, mixed>  $attributes  Block attributes
 * @return array<int|string, mixed>|null The processor's result
 */
function processFormidableBlock(array $attributes): ?array
{
    $node = (new DOMDocument)->createComment(' wp:formidable/simple-form '.json_encode($attributes).' /');

    $result = (new FormidableFormsBlockProcessor)->process(
        $node,
        $attributes,
        'default',
        (new ReflectionClass(AssetImporter::class))->newInstanceWithoutConstructor(),
        new BufferedOutput
    );

    if ($result !== null) {
        expect($result['processedNode'] ?? null)->toBe($node);
    }

    return $result;
}

beforeEach(function (): void {
    FormidableFormsBlockProcessor::clearFormIds();
    GravityFormsBlockProcessor::clearFormIds();
    setFormidableBlockHandles(FormidableFormsBlockProcessor::class, 'createdFormidableFormsHandles', []);
    setFormidableBlockHandles(FormidableFormsBlockProcessor::class, 'createdGravityFormsHandles', []);
    ProcessingContext::clearWordPressPageLink();
    ProcessingContext::clearFormConfigContext();
});

afterEach(function (): void {
    FormidableFormsBlockProcessor::clearFormIds();
    setFormidableBlockHandles(FormidableFormsBlockProcessor::class, 'createdFormidableFormsHandles', []);
    setFormidableBlockHandles(FormidableFormsBlockProcessor::class, 'createdGravityFormsHandles', []);
});

it('recognizes a wp:formidable/simple-form comment', function (string $comment): void {
    $node = (new DOMDocument)->createComment($comment);

    expect((new FormidableFormsBlockProcessor)->canProcess($node, 'default', null))->toBeTrue();
})->with([
    'bare' => ['wp:formidable/simple-form'],
    'surrounding whitespace' => ['  wp:formidable/simple-form  '],
    'self-closing' => ['wp:formidable/simple-form /'],
    'with attributes' => ['wp:formidable/simple-form {"formId":"8"}'],
    'with attributes, self-closing' => [' wp:formidable/simple-form {"formId":"8"} / '],
    'attributes spanning lines' => ["wp:formidable/simple-form {\n\"formId\":\"8\"\n} /"],
]);

it('rejects comments that are not a wp:formidable/simple-form block', function (string $comment): void {
    $node = (new DOMDocument)->createComment($comment);

    expect((new FormidableFormsBlockProcessor)->canProcess($node, 'default', null))->toBeFalse();
})->with([
    'closing comment' => ['/wp:formidable/simple-form'],
    'gravity forms block' => ['wp:gravityforms/form {"formId":"8"}'],
    'longer block name' => ['wp:formidable/simple-formx'],
    'prefixed' => ['x wp:formidable/simple-form'],
    'trailing text' => ['wp:formidable/simple-form {"formId":"8"} extra'],
]);

it('rejects nodes that are not comments', function (): void {
    $node = (new DOMDocument)->createTextNode('wp:formidable/simple-form');

    expect((new FormidableFormsBlockProcessor)->canProcess($node, 'default', null))->toBeFalse();
});

it('emits an import note with no title or description when the block does not set them', function (): void {
    $result = processFormidableBlock(['formId' => '8']);

    expect($result)->toBe([
        'html' => '<div data-set-type="form"'.SetMarkupNonce::attribute()
            .' data-set-attr-import_wordpress_notes="Formidable Forms form ID 8"></div>',
        'processedNode' => $result['processedNode'] ?? null,
        'skipCount' => 2,
    ])
        ->and(FormidableFormsBlockProcessor::getFormIds())->toBe(['8'])
        ->and(GravityFormsBlockProcessor::getFormIds())->toBe([]);
});

it('shows the title and description only when the block sets them to the string 1', function (array $attributes, string $expectedAttrs): void {
    $result = processFormidableBlock(['formId' => '8', ...$attributes]);

    expect($result['html'] ?? null)->toBe(
        '<div data-set-type="form"'.SetMarkupNonce::attribute()
        .' data-set-attr-import_wordpress_notes="Formidable Forms form ID 8"'
        .$expectedAttrs.'></div>'
    );
})->with([
    'both on' => [
        ['title' => '1', 'description' => '1'],
        ' data-set-attr-heading_query="1" data-set-attr-heading_level="h2" data-set-attr-description_query="1"',
    ],
    'title only' => [
        ['title' => '1'],
        ' data-set-attr-heading_query="1" data-set-attr-heading_level="h2"',
    ],
    'description only' => [
        ['description' => '1'],
        ' data-set-attr-description_query="1"',
    ],
    'integer 1 is not the string 1' => [
        ['title' => 1, 'description' => 1],
        '',
    ],
    'string 0' => [
        ['title' => '0', 'description' => '0'],
        '',
    ],
]);

it('uses the created Formidable handle, not a Gravity Forms handle for the same ID', function (): void {
    setFormidableBlockHandles(FormidableFormsBlockProcessor::class, 'createdGravityFormsHandles', ['8' => 'gravity_handle']);

    $noteOnly = processFormidableBlock(['formId' => '8']);

    setFormidableBlockHandles(FormidableFormsBlockProcessor::class, 'createdFormidableFormsHandles', ['8' => 'newsletter']);

    $withHandle = processFormidableBlock(['formId' => '8']);

    expect($noteOnly['html'] ?? null)->toBe(
        '<div data-set-type="form"'.SetMarkupNonce::attribute()
        .' data-set-attr-import_wordpress_notes="Formidable Forms form ID 8"></div>'
    )
        ->and($withHandle['html'] ?? null)->toBe(
            '<div data-set-type="form"'.SetMarkupNonce::attribute().' data-set-attr-form="newsletter"></div>'
        );
});

it('warns under the Formidable name and returns null without a usable form ID', function (): void {
    $log = Log::spy();

    expect(processFormidableBlock(['formId' => 'x']))->toBeNull()
        ->and(FormidableFormsBlockProcessor::getFormIds())->toBe([]);

    $log->shouldHaveReceived('warning', [
        'Formidable FormsBlockProcessor::process: Invalid or missing formId in Formidable Forms',
        [
            'site' => 'default',
            'pageSlug' => 'none',
            'form_id' => 'none',
            'attributes' => ['formId' => 'x'],
        ],
    ]);
});
