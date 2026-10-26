<?php

declare(strict_types=1);

/**
 * Tests the `XpathHelper` paths its focused unit tests leave unreached (#1314):
 * how an invalid expression is handled, the element and presence helpers, the
 * attachment lookups' skips and path-suffix fallback, the status-exclusion query
 * with extra conditions, and the parts `findItemByPostId()` cannot read.
 *
 * An invalid XPath raises a PHP warning, and what happens next depends on the
 * error handler in force. Under one that turns warnings into exceptions, as
 * Laravel's does, the helper's catch logs and gives up; under one that does not,
 * `xpath()` returns false and the helper gives up without logging. Each test sets
 * the handler it means to exercise, so both paths run on purpose rather than by
 * whatever handler the runner installed. Routed to the light Statamic band
 * because the catch logs through the `Log` facade.
 *
 * @command  php vendor/bin/pest tests/Integration/XpathHelperTest.php
 */

use Illuminate\Support\Facades\Log;
use Mockery;
use Tiptap\Editor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * A WXR channel holding the given item markup.
 *
 * @param  string  $items  The `<item>` elements
 * @return SimpleXMLElement The parsed document
 */
function xpathHelperDocument(string $items): SimpleXMLElement
{
    return new SimpleXMLElement(
        '<rss xmlns:wp="http://wordpress.org/export/1.2/"><channel>'.$items.'</channel></rss>'
    );
}

/**
 * An attachment item with the given post id and URL.
 *
 * @param  string  $postId  The attachment post id
 * @param  string  $url  The `wp:attachment_url`, or '' for none
 * @return SimpleXMLElement The attachment `<item>`
 */
function xpathHelperAttachment(string $postId, string $url): SimpleXMLElement
{
    $document = xpathHelperDocument(
        '<item><wp:post_id>'.$postId.'</wp:post_id><wp:attachment_url>'.$url.'</wp:attachment_url></item>'
    );

    $items = $document->xpath('//item');

    return \is_array($items) && isset($items[0]) ? $items[0] : $document;
}

/**
 * The text of each element in an XPath result.
 *
 * @param  array<int|string, mixed>  $elements  The result
 * @return list<string> The text of each element
 */
function xpathHelperTexts(array $elements): array
{
    return \array_values(\array_map(
        static fn (mixed $element): string => $element instanceof SimpleXMLElement ? (string) $element : '',
        $elements
    ));
}

/**
 * Run a callback under an error handler that either throws or swallows warnings.
 *
 * @template T
 *
 * @param  bool  $throw  Whether a warning becomes an ErrorException
 * @param  Closure(): T  $callback  The code to run
 * @return T The callback's result
 */
function xpathHelperUnderHandler(bool $throw, Closure $callback): mixed
{
    // libxml's internal-errors mode is process-wide, and with it on an invalid
    // expression raises no warning at all; another test leaving it on must not
    // decide which path this one exercises
    $previousLibxmlMode = \libxml_use_internal_errors(false);

    \set_error_handler(static function (int $severity, string $message, string $file, int $line) use ($throw): bool {
        if ($throw) {
            throw new ErrorException($message, 0, $severity, $file, $line);
        }

        return true;
    });

    try {
        return $callback();
    } finally {
        \restore_error_handler();
        \libxml_use_internal_errors($previousLibxmlMode);
    }
}

it('returns the matching elements as a list', function (): void {
    $document = xpathHelperDocument('<item><wp:post_id>1</wp:post_id></item><item><wp:post_id>2</wp:post_id></item>');

    $elements = XpathHelper::xpathElements($document, '//item/wp:post_id');

    expect(xpathHelperTexts($elements))->toBe(['1', '2']);
});

it('returns no elements when xpath() fails without raising', function (): void {
    $document = xpathHelperDocument('');

    $elements = xpathHelperUnderHandler(false, fn (): array => XpathHelper::xpathElements($document, '//['));

    expect($elements)->toBe([]);
});

it('returns every match from getElements and reports presence through hasValue', function (): void {
    $document = xpathHelperDocument('<item><wp:status>publish</wp:status></item>');

    expect(xpathHelperTexts(XpathHelper::getElements($document, '//wp:status')))->toBe(['publish'])
        ->and(XpathHelper::hasValue($document, '//wp:status'))->toBeTrue()
        ->and(XpathHelper::getElements($document, '//wp:missing'))->toBe([])
        ->and(XpathHelper::hasValue($document, '//wp:missing'))->toBeFalse();
});

it('logs an invalid expression and reports nothing when the warning is raised', function (string $method, Closure $call, mixed $expected): void {
    $logSpy = Log::spy();
    $document = xpathHelperDocument('');

    $result = xpathHelperUnderHandler(true, fn (): mixed => $call($document, '//['));

    expect($result)->toBe($expected);

    $logSpy->shouldHaveReceived('warning', [
        Mockery::on(
            static fn (mixed $message): bool => \is_string($message)
                && \str_contains($message, "XPath error in XpathHelper::{$method}")
        ),
        Mockery::on(
            static fn (mixed $context): bool => \is_array($context)
                && ($context['xpath'] ?? null) === '//['
                && ($context['method_name'] ?? null) === $method
        ),
    ]);
})->with([
    'getElements' => ['getElements', XpathHelper::getElements(...), []],
    'hasValue' => ['hasValue', XpathHelper::hasValue(...), false],
]);

it('reports nothing and logs nothing when xpath() fails without raising', function (Closure $call, mixed $expected): void {
    $logSpy = Log::spy();
    $document = xpathHelperDocument('');

    $result = xpathHelperUnderHandler(false, fn (): mixed => $call($document, '//['));

    expect($result)->toBe($expected);
    $logSpy->shouldNotHaveReceived('warning');
})->with([
    'getElements' => [XpathHelper::getElements(...), []],
    'hasValue' => [XpathHelper::hasValue(...), false],
]);

it('finds an attachment by id, skipping entries that are not XML', function (): void {
    $wanted = xpathHelperAttachment('7', 'https://example.org/a.jpg');

    $found = XpathHelper::findAttachmentById(
        ['not xml', xpathHelperAttachment('6', 'https://example.org/b.jpg'), $wanted],
        '7'
    );

    expect($found)->toBe($wanted)
        ->and(XpathHelper::findAttachmentById([xpathHelperAttachment('6', 'x')], '7'))->toBeNull();
});

it('matches an attachment URL exactly, ignoring a size suffix, and skips non-XML and empty entries', function (): void {
    $wanted = xpathHelperAttachment('7', 'https://example.org/wp-content/uploads/2020/08/banner.jpg');

    $found = XpathHelper::findAttachmentByUrl(
        ['not xml', xpathHelperAttachment('5', ''), $wanted],
        'https://example.org/wp-content/uploads/2020/08/banner-300x200.jpg'
    );

    expect($found)->toBe($wanted);
});

it('falls back to matching a short URL as the tail of an attachment path', function (): void {
    $wanted = xpathHelperAttachment('7', 'https://example.org/wp-content/blogs.dir/16/files/2020/08/DHSBanner.jpg');

    $found = XpathHelper::findAttachmentByUrl(
        ['not xml', xpathHelperAttachment('5', ''), xpathHelperAttachment('6', 'https://example.org/files/other.jpg'), $wanted],
        'https://example.org/files/2020/08/DHSBanner.jpg'
    );

    expect($found)->toBe($wanted);
});

it('finds nothing when neither the URL nor its path tail matches', function (): void {
    $found = XpathHelper::findAttachmentByUrl(
        [xpathHelperAttachment('6', 'https://example.org/files/other.jpg')],
        'https://example.org/files/2020/08/DHSBanner.jpg'
    );

    expect($found)->toBeNull();
});

it('skips the path-tail fallback for a URL that has no path', function (): void {
    // An attachment whose path is just "/" would end with any URL's empty-path
    // tail, so a URL with no path must not reach the fallback at all
    $found = XpathHelper::findAttachmentByUrl(
        [xpathHelperAttachment('6', 'https://example.org/')],
        'https://example.org'
    );

    expect($found)->toBeNull();
});

it('skips an attachment whose URL has no parsable path in the fallback', function (): void {
    $found = XpathHelper::findAttachmentByUrl(
        [xpathHelperAttachment('6', 'http:///broken')],
        'https://example.org/files/a.jpg'
    );

    expect($found)->toBeNull();
});

it('builds a status-exclusion query, with or without extra conditions', function (): void {
    expect(XpathHelper::buildStatusExclusionXPath('page'))
        ->toBe("//item[wp:post_type='page' and wp:status!='trash' and wp:status!='virtual']")
        ->and(XpathHelper::buildStatusExclusionXPath('post', 'wp:post_parent=0'))
        ->toBe("//item[wp:post_type='post' and wp:status!='trash' and wp:status!='virtual' and wp:post_parent=0]");
});

it('returns null for an export part that is not on disk', function (): void {
    $path = \sys_get_temp_dir().\DIRECTORY_SEPARATOR.\uniqid('xpath_missing_', true).'.xml';

    expect(XpathHelper::findItemByPostId($path, '1'))->toBeNull();
});

it('returns null for an export part that cannot be parsed', function (): void {
    $path = \sys_get_temp_dir().\DIRECTORY_SEPARATOR.\uniqid('xpath_broken_', true).'.xml';
    \file_put_contents($path, '<rss><channel><item>');

    $previous = \libxml_use_internal_errors(true);

    try {
        expect(XpathHelper::findItemByPostId($path, '1'))->toBeNull();
    } finally {
        \libxml_clear_errors();
        \libxml_use_internal_errors($previous);
        @\unlink($path);
    }
});

it('registers every WordPress namespace by URI, whatever prefix the export uses', function (string $query, string $uri): void {
    // The document binds each namespace to a prefix of its own, so a query by the
    // helper's prefix resolves only through the helper's own registration
    $document = new SimpleXMLElement('<rss xmlns:x="'.$uri.'"><x:node>found</x:node></rss>');

    // A fresh document for each call: a registration made by one call stays on the
    // element and would answer for the next
    $fresh = static fn (): SimpleXMLElement => new SimpleXMLElement((string) $document->asXML());

    expect(XpathHelper::getStringValue($fresh(), $query))->toBe('found')
        ->and(xpathHelperTexts(XpathHelper::xpathElements($fresh(), $query)))->toBe(['found'])
        ->and(xpathHelperTexts(XpathHelper::getElements($fresh(), $query)))->toBe(['found']);
})->with([
    'wp' => ['//wp:node', 'http://wordpress.org/export/1.2/'],
    'content' => ['//content:node', 'http://purl.org/rss/1.0/modules/content/'],
    'excerpt' => ['//excerpt:node', 'http://wordpress.org/export/1.2/excerpt/'],
    'wfw' => ['//wfw:node', 'http://wellformedweb.org/CommentAPI/'],
    'dc' => ['//dc:node', 'http://purl.org/dc/elements/1.1/'],
]);

it('records the libxml error in the log context of an invalid expression', function (): void {
    $logSpy = Log::spy();

    xpathHelperUnderHandler(true, fn (): array => XpathHelper::getElements(xpathHelperDocument(''), '//['));

    $logSpy->shouldHaveReceived('warning', [
        Mockery::any(),
        Mockery::on(
            static fn (mixed $context): bool => \is_array($context)
                && \is_string($context['error'] ?? null)
                && \str_contains($context['error'], 'Invalid expression')
        ),
    ]);
});

it('reads an integer, or its default when the node is absent', function (): void {
    $document = xpathHelperDocument('<item><wp:menu_order>42</wp:menu_order></item>');

    expect(XpathHelper::getIntValue($document, '//wp:menu_order'))->toBe(42)
        ->and(XpathHelper::getIntValue($document, '//wp:missing'))->toBe(0)
        ->and(XpathHelper::getIntValue($document, '//wp:missing', 7))->toBe(7);
});

it('reads a boolean from the node text, or its default when the node is absent', function (): void {
    $document = xpathHelperDocument('<item><wp:is_sticky>1</wp:is_sticky><wp:off>0</wp:off></item>');

    expect(XpathHelper::getBoolValue($document, '//wp:is_sticky'))->toBeTrue()
        ->and(XpathHelper::getBoolValue($document, '//wp:off', true))->toBeFalse()
        ->and(XpathHelper::getBoolValue($document, '//wp:missing'))->toBeFalse()
        ->and(XpathHelper::getBoolValue($document, '//wp:missing', true))->toBeTrue();
});

it('prefers an exact attachment URL over an earlier one that only shares its path', function (): void {
    $pathOnly = xpathHelperAttachment('5', 'https://cdn.example.org/files/a.jpg');
    $exact = xpathHelperAttachment('6', 'https://example.org/files/a.jpg');

    $found = XpathHelper::findAttachmentByUrl(
        ['not xml', xpathHelperAttachment('4', ''), $pathOnly, $exact],
        'https://example.org/files/a.jpg'
    );

    expect($found)->toBe($exact);
});

it('finds nothing for an empty URL, even beside attachments with and without URLs', function (): void {
    $found = XpathHelper::findAttachmentByUrl(
        [xpathHelperAttachment('4', ''), xpathHelperAttachment('5', 'https://example.org/files/a.jpg')],
        ''
    );

    expect($found)->toBeNull();
});

it('skips the trash and virtual statuses only', function (string $status, bool $skip): void {
    expect(XpathHelper::shouldSkipStatus($status))->toBe($skip);
})->with([
    'trash' => ['trash', true],
    'virtual' => ['virtual', true],
    'publish' => ['publish', false],
    'draft' => ['draft', false],
]);

it('quotes an ordinary value in single quotes, and one with a single quote in double quotes', function (): void {
    expect(XpathHelper::xpathStringLiteral('plain value'))->toBe("'plain value'")
        ->and(XpathHelper::xpathStringLiteral("it's"))->toBe('"it\'s"');
});

it('stamps the post id and author a migration redirect needs', function (string $postId, ?int $expected): void {
    $item = new SimpleXMLElement(
        '<item xmlns:wp="http://wordpress.org/export/1.2/" xmlns:dc="http://purl.org/dc/elements/1.1/">'
        .'<wp:post_id>'.$postId.'</wp:post_id><dc:creator> jdoe </dc:creator></item>'
    );

    $metadata = XpathHelper::extractMigrationRedirectMetadata($item);

    expect($metadata['wordpress_post_id'] ?? null)->toBe($expected)
        ->and($metadata['wordpress_author_login'] ?? null)->toBe('jdoe');
})->with([
    'an id' => ['42', 42],
    'id one' => ['1', 1],
    'padded with spaces' => [' 42 ', 42],
    'zero' => ['0', null],
    'digits then letters' => ['12abc', null],
    'blank' => ['', null],
]);

it('leaves out a blank author', function (): void {
    $item = new SimpleXMLElement(
        '<item xmlns:wp="http://wordpress.org/export/1.2/" xmlns:dc="http://purl.org/dc/elements/1.1/">'
        .'<wp:post_id>9</wp:post_id><dc:creator>  </dc:creator></item>'
    );

    expect(XpathHelper::extractMigrationRedirectMetadata($item))->toBe(['wordpress_post_id' => 9]);
});

it('extracts every attachment field, decoding entities in the title', function (): void {
    $attachment = new SimpleXMLElement(
        '<item xmlns:wp="http://wordpress.org/export/1.2/" xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/">'
        .'<title>Tom &amp;amp; Jerry&amp;#039;s &lt;b&gt;day&lt;/b&gt;</title>'
        .'<wp:attachment_url>https://example.org/a.jpg</wp:attachment_url>'
        .'<excerpt:encoded>A caption</excerpt:encoded>'
        .'<wp:postmeta><wp:meta_key>_wp_attachment_image_alt</wp:meta_key><wp:meta_value>Alt text</wp:meta_value></wp:postmeta>'
        .'</item>'
    );

    $data = XpathHelper::extractAttachmentData($attachment);

    expect($data)->toHaveKeys(['url', 'alt', 'caption', 'title', 'focus', 'creator'])
        ->and($data['url'])->toBe('https://example.org/a.jpg')
        ->and($data['alt'])->toBe('Alt text')
        ->and($data['caption'])->toBe('A caption')
        ->and($data['title'])->toBe("Tom & Jerry's day");
});

it('decodes a doubly encoded attachment title twice', function (): void {
    // The title's text is `&amp;` once the XML is parsed; the helper decodes it and
    // the tag stripper decodes again, so it reads as a bare ampersand
    $attachment = new SimpleXMLElement('<item><title>R&amp;amp;amp;D</title></item>');

    expect(XpathHelper::extractAttachmentData($attachment)['title'])->toBe('R&D');
});

it('decodes HTML5-only entities in an attachment title before stripping tags', function (string $title, string $expected): void {
    $attachment = new SimpleXMLElement('<item><title>'.$title.'</title></item>');

    expect(XpathHelper::extractAttachmentData($attachment)['title'])->toBe($expected);
})->with([
    // `&semi;` exists only in HTML5, so the first decode must use HTML5 rules for
    // the tag to be recognized and stripped
    'an encoded tag spelled with semi' => ['&amp;lt&amp;semi;b&gt;x', 'x'],
    'an ampersand spelled with semi' => ['&amp;amp&amp;semi;', '&'],
]);

it('finds an item in an export that binds the WordPress namespace to another prefix', function (): void {
    $path = \sys_get_temp_dir().\DIRECTORY_SEPARATOR.\uniqid('xpath_prefix_', true).'.xml';
    \file_put_contents(
        $path,
        '<rss xmlns:x="http://wordpress.org/export/1.2/"><channel><item><x:post_id>5</x:post_id><title>Found</title></item></channel></rss>'
    );

    try {
        $item = XpathHelper::findItemByPostId($path, '5');

        expect((string) $item?->title)->toBe('Found');
    } finally {
        @\unlink($path);
    }
});

it('keeps searching past an export part that cannot be parsed', function (): void {
    $directory = \sys_get_temp_dir().\DIRECTORY_SEPARATOR.\uniqid('xpath_parts_', true);
    \mkdir($directory);
    \file_put_contents($directory.'/news.000.xml', '<rss><channel><item>');
    \file_put_contents(
        $directory.'/news.001.xml',
        '<rss xmlns:wp="http://wordpress.org/export/1.2/"><channel><item><wp:post_id>5</wp:post_id><title>Found</title></item></channel></rss>'
    );

    $previous = \libxml_use_internal_errors(true);

    try {
        $item = XpathHelper::findItemByPostId($directory.'/news.000.xml', '5');

        expect($item)->not->toBeNull()
            ->and((string) $item?->title)->toBe('Found');
    } finally {
        \libxml_clear_errors();
        \libxml_use_internal_errors($previous);
        @\unlink($directory.'/news.000.xml');
        @\unlink($directory.'/news.001.xml');
        @\rmdir($directory);
    }
});

/**
 * Run a callback with libxml's internal-errors mode set as a caller left it, under
 * an error handler that throws, restoring both afterwards.
 *
 * @template T
 *
 * @param  bool  $mode  The internal-errors mode in force when the callback runs
 * @param  Closure(): T  $callback  The code to run
 * @return array{0: T, 1: bool} The callback's result, and the mode right after it
 */
function xpathHelperWithCallerMode(bool $mode, Closure $callback): array
{
    $previousLibxmlMode = \libxml_use_internal_errors($mode);

    \set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    try {
        $result = $callback();

        return [$result, \libxml_use_internal_errors()];
    } finally {
        \restore_error_handler();
        \libxml_use_internal_errors($previousLibxmlMode);
    }
}

it('logs an invalid expression even when a caller left libxml internal errors on', function (string $method, Closure $call, mixed $expected): void {
    $logSpy = Log::spy();

    [$result] = xpathHelperWithCallerMode(true, fn (): mixed => $call(xpathHelperDocument(''), '//['));

    expect($result)->toBe($expected);

    $logSpy->shouldHaveReceived('warning', [
        Mockery::on(
            static fn (mixed $message): bool => \is_string($message)
                && \str_contains($message, "XPath error in XpathHelper::{$method}")
        ),
        Mockery::any(),
    ]);
})->with([
    'getElements' => ['getElements', XpathHelper::getElements(...), []],
    'hasValue' => ['hasValue', XpathHelper::hasValue(...), false],
]);

it('leaves libxml internal errors as the caller had them', function (bool $mode, string $query): void {
    Log::spy();

    [, $after] = xpathHelperWithCallerMode(
        $mode,
        fn (): array => XpathHelper::getElements(xpathHelperDocument('<item><wp:post_id>1</wp:post_id></item>'), $query)
    );

    expect($after)->toBe($mode);
})->with([
    'on, valid expression' => [true, '//wp:post_id'],
    'on, invalid expression' => [true, '//['],
    'off, valid expression' => [false, '//wp:post_id'],
    'off, invalid expression' => [false, '//['],
]);

it('still logs an invalid expression after tiptap-php has parsed HTML', function (): void {
    $logSpy = Log::spy();

    [$result] = xpathHelperWithCallerMode(false, function (): array {
        // tiptap-php switches the mode on to parse and leaves it on (wordpress-importer-archive#1357)
        (new Editor)->setContent('<p>hi</p>')->getDocument();

        return XpathHelper::getElements(xpathHelperDocument(''), '//[');
    });

    expect($result)->toBe([]);

    $logSpy->shouldHaveReceived('warning', [
        Mockery::on(
            static fn (mixed $message): bool => \is_string($message)
                && \str_contains($message, 'XPath error in XpathHelper::getElements')
        ),
        Mockery::any(),
    ]);
});
