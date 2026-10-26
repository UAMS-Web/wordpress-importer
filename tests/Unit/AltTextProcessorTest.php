<?php

declare(strict_types=1);

/**
 * Tests that `AltTextProcessor` compares an image's own alt text with its
 * attachment's alt text in the export, and records the image's alt as an
 * override only when it is non-empty and differs (#1314).
 *
 * @command  php vendor/bin/pest tests/Unit/AltTextProcessorTest.php
 */

use Symfony\Component\Console\Output\NullOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\ImageProcessor\AltTextProcessor;

/**
 * An attachment item as the export carries it.
 *
 * @param  string  $postId  The attachment's post ID
 * @param  ?string  $alt  Its alt text, or null for no alt meta at all
 * @return SimpleXMLElement The item
 */
function altTextAttachment(string $postId, ?string $alt): SimpleXMLElement
{
    $meta = $alt === null
        ? '<wp:postmeta><wp:meta_key>_wp_attached_file</wp:meta_key><wp:meta_value>a.jpg</wp:meta_value></wp:postmeta>'
        : '<wp:postmeta><wp:meta_key>_wp_attachment_image_alt</wp:meta_key><wp:meta_value>'.\htmlspecialchars($alt).'</wp:meta_value></wp:postmeta>';

    $item = \simplexml_load_string(
        '<item xmlns:wp="http://wordpress.org/export/1.2/"><wp:post_id>'.$postId.'</wp:post_id>'.$meta.'</item>'
    );
    assert($item instanceof SimpleXMLElement);

    return $item;
}

/**
 * Run the processor over a context.
 *
 * @param  array<mixed>  $context  The image context
 * @param  array<int|string, mixed>  $attachments  The export's attachments
 * @return array<string, mixed> The context after processing
 */
function altTextProcess(array $context, array $attachments = []): array
{
    $assetImporter = new ReflectionClass(AssetImporter::class)->newInstanceWithoutConstructor();

    /** @var array<string, mixed> $result */
    $result = new AltTextProcessor()->process($context, 'default', $assetImporter, new NullOutput, $attachments);

    return $result;
}

it('records the image alt as an override when it differs from the attachment alt', function (mixed $attachmentId): void {
    $result = altTextProcess(
        ['imgAlt' => 'A nurse', 'attachmentId' => $attachmentId, 'keep' => 'me'],
        [altTextAttachment('7', 'Something else'), altTextAttachment('8', 'A doctor')]
    );

    expect($result)->toBe(['imgAlt' => 'A nurse', 'attachmentId' => $attachmentId, 'keep' => 'me', 'altOverride' => 'A nurse']);
})->with([
    'a string id' => ['8'],
    'an integer id' => [8],
]);

it('records no override when the image alt matches the attachment alt', function (mixed $attachmentId): void {
    $result = altTextProcess(
        ['imgAlt' => 'A doctor', 'attachmentId' => $attachmentId],
        ['not xml', altTextAttachment('7', 'Something else'), altTextAttachment('8', 'A doctor')]
    );

    expect($result)->toHaveKey('altOverride')
        ->and($result['altOverride'])->toBeNull();
})->with([
    'a string id' => ['8'],
    'an integer id' => [8],
]);

it('records no override for an empty image alt', function (): void {
    $result = altTextProcess(['imgAlt' => '', 'attachmentId' => '8'], [altTextAttachment('8', 'A doctor')]);

    expect($result)->toHaveKey('altOverride')
        ->and($result['altOverride'])->toBeNull();
});

it('records the image alt when there is nothing to compare it with', function (array $context, array $attachments): void {
    expect(altTextProcess($context, $attachments)['altOverride'] ?? null)->toBe('A nurse');
})->with([
    'no attachment id' => [['imgAlt' => 'A nurse'], []],
    'an attachment id that is neither string nor integer' => [['imgAlt' => 'A nurse', 'attachmentId' => 8.0], []],
    'no attachment with that id' => [['imgAlt' => 'A nurse', 'attachmentId' => '9'], []],
    'an attachment with no alt' => [['imgAlt' => 'A nurse', 'attachmentId' => '8'], [altTextAttachment('8', null)]],
]);

it('reads a missing attachment id without touching an undefined key', function (): void {
    // A read of a missing key raises a warning; fail on it rather than let the
    // processor lean on PHP's null for an absent attachment id
    \set_error_handler(static function (int $severity, string $message): never {
        throw new ErrorException($message, 0, $severity);
    });

    try {
        $result = altTextProcess(['imgAlt' => 'A nurse']);
    } finally {
        \restore_error_handler();
    }

    expect($result['altOverride'] ?? null)->toBe('A nurse');
});

it('does not look up an attachment for an empty attachment id', function (): void {
    // An attachment whose post ID is empty would match an empty id if it were
    // looked up, and its alt would then suppress the override
    $result = altTextProcess(['imgAlt' => 'A nurse', 'attachmentId' => ''], [altTextAttachment('', 'A nurse')]);

    expect($result['altOverride'] ?? null)->toBe('A nurse');
});

it('reads a missing image alt as empty', function (): void {
    $result = altTextProcess(['attachmentId' => '8'], [altTextAttachment('8', 'A doctor')]);

    expect($result)->toHaveKey('altOverride')
        ->and($result['altOverride'])->toBeNull();
});
