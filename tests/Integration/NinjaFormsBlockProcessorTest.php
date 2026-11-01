<?php

declare(strict_types=1);

/**
 * Tests that `NinjaFormsBlockProcessor` recognizes a `wp:ninja-forms/form`
 * comment, reads the form ID from the block's `formID` attribute (or `formId`
 * when both are present), consumes only the comment, and leaves the title and
 * description fields alone (#1314).
 *
 * Routed to the light Statamic band for the `Log` facade.
 *
 * @command  php vendor/bin/pest tests/Integration/NinjaFormsBlockProcessorTest.php
 */

use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Tiptap\SetMarkupNonce;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\NinjaFormsBlockProcessor;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

beforeEach(function (): void {
    NinjaFormsBlockProcessor::clearFormIds();
    ProcessingContext::clearWordPressPageLink();
});

afterEach(function (): void {
    NinjaFormsBlockProcessor::clearFormIds();
});

/**
 * Run the processor over a fresh opening comment.
 *
 * @param  array<int|string, mixed>  $attributes  Block attributes
 * @return array<int|string, mixed>|null The processor's result
 */
function processNinjaBlock(array $attributes): ?array
{
    $node = (new DOMDocument)->createComment(' wp:ninja-forms/form '.\json_encode($attributes).' /');

    $result = new NinjaFormsBlockProcessor()->process(
        $node,
        $attributes,
        'default',
        new ReflectionClass(AssetImporter::class)->newInstanceWithoutConstructor(),
        new BufferedOutput,
        [],
        [],
        'about'
    );

    if ($result !== null) {
        expect($result['processedNode'] ?? null)->toBe($node);
    }

    return $result;
}

/**
 * The form set markup for a form with no created handle.
 *
 * @param  string  $formId  The form ID named in the import note
 * @return string The markup
 */
function ninjaBlockNoteHtml(string $formId): string
{
    return '<div data-set-type="form"'.SetMarkupNonce::attribute()
        .' data-set-attr-import_wordpress_notes="Ninja Forms form ID '.$formId.'"></div>';
}

it('recognizes a wp:ninja-forms/form comment', function (string $comment): void {
    $node = (new DOMDocument)->createComment($comment);

    expect(new NinjaFormsBlockProcessor()->canProcess($node, 'default', null))->toBeTrue();
})->with([
    'bare' => ['wp:ninja-forms/form'],
    'surrounding whitespace' => ['  wp:ninja-forms/form  '],
    'self-closing' => ['wp:ninja-forms/form /'],
    'with attributes' => ['wp:ninja-forms/form {"formID":"3"}'],
    'with attributes, self-closing' => [' wp:ninja-forms/form {"formID":"3"} / '],
    'attributes spanning lines' => ["wp:ninja-forms/form {\n\"formID\":\"3\"\n} /"],
]);

it('rejects comments that are not a wp:ninja-forms/form block', function (string $comment): void {
    $node = (new DOMDocument)->createComment($comment);

    expect(new NinjaFormsBlockProcessor()->canProcess($node, 'default', null))->toBeFalse();
})->with([
    'closing comment' => ['/wp:ninja-forms/form'],
    'gravity forms block' => ['wp:gravityforms/form {"formId":"3"}'],
    'longer block name' => ['wp:ninja-forms/formx'],
    'prefixed' => ['x wp:ninja-forms/form'],
    'trailing text' => ['wp:ninja-forms/form {"formID":"3"} extra'],
    'empty attribute braces' => ['wp:ninja-forms/form {}'],
]);

it('rejects nodes that are not comments', function (DOMNode $node): void {
    expect(new NinjaFormsBlockProcessor()->canProcess($node, 'default', null))->toBeFalse();
})->with([
    'text node holding the block name' => fn (): DOMNode => (new DOMDocument)->createTextNode('wp:ninja-forms/form'),
    'element' => fn (): DOMNode => (new DOMDocument)->createElement('div', 'wp:ninja-forms/form'),
]);

it('reads the form ID from the formID attribute and consumes only the comment', function (): void {
    $result = processNinjaBlock(['formID' => '3']);

    expect($result['html'] ?? null)->toBe(ninjaBlockNoteHtml('3'))
        ->and($result['skipCount'] ?? null)->toBe(1)
        ->and(NinjaFormsBlockProcessor::getFormIds())->toBe(['3']);
});

it('prefers formId to formID when the block carries both', function (): void {
    $result = processNinjaBlock(['formId' => '5', 'formID' => '3']);

    expect($result['html'] ?? null)->toBe(ninjaBlockNoteHtml('5'))
        ->and(NinjaFormsBlockProcessor::getFormIds())->toBe(['5']);
});

it('ignores block title and description settings', function (): void {
    $result = processNinjaBlock(['formID' => '3', 'title' => false, 'description' => true]);

    expect($result['html'] ?? null)->toBe(ninjaBlockNoteHtml('3'));
});

it('logs and skips a block with no form ID', function (): void {
    $logSpy = Log::spy();

    expect(processNinjaBlock(['title' => true]))->toBeNull();

    $logSpy->shouldHaveReceived('warning', [
        'Ninja FormsBlockProcessor::process: Invalid or missing formId in Ninja Forms',
        Mockery::on(static fn (mixed $context): bool => \is_array($context)
            && ($context['site'] ?? null) === 'default'
            && ($context['pageSlug'] ?? null) === 'about'
            && ($context['form_id'] ?? null) === 'none'),
    ]);
});

it('leaves the set values alone in handleTitleAndDescription', function (): void {
    $values = ['type' => 'form', 'show_title' => false];

    new NinjaFormsBlockProcessor()->handleTitleAndDescription($values, ['title' => true, 'description' => true]);

    expect($values)->toBe(['type' => 'form', 'show_title' => false]);
});
