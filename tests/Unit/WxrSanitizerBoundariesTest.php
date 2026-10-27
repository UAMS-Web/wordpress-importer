<?php

declare(strict_types=1);

/**
 * Tests the edges of `WxrSanitizer` that `WxrSanitizerTest` does not reach: what
 * it withholds at a chunk boundary and when it lets go of it, a `]]>` that only
 * appears once a control byte is stripped, the repair counters at one repair of
 * each kind, and regions whose end a wrong terminator would misplace.
 *
 * @command  php vendor/bin/pest tests/Unit/WxrSanitizerBoundariesTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\WxrSanitizer;

it('keeps a comment literal through more than one `]]>`', function (): void {
    // Ending the comment at its first `]]>` would leave the second to be escaped as text
    $source = '<a><!-- a ]]> b ]]> c --></a>';

    expect(WxrSanitizer::string($source))->toBe($source);
});

it('repairs text again once a processing instruction ends', function (): void {
    // Holding the instruction open past its terminator would leave this stray `]]>`
    // unescaped
    expect(WxrSanitizer::string('<a><?pi x ?>b ]]> c</a>'))->toBe('<a><?pi x ?>b ]]&gt; c</a>');
});

it('passes plain text through a chunk without holding any of it back', function (): void {
    $sanitizer = new WxrSanitizer;

    expect($sanitizer->feed('abc'))->toBe('abc')
        ->and($sanitizer->flush())->toBe('');
});

it('holds back only a partial token at the end of a chunk', function (): void {
    $sanitizer = new WxrSanitizer;

    expect($sanitizer->feed('ab<!-'))->toBe('ab')
        ->and($sanitizer->flush())->toBe('<!-');
});

it('lets go of what it held once flushed', function (): void {
    $sanitizer = new WxrSanitizer;
    $sanitizer->feed('a<');

    expect($sanitizer->flush())->toBe('<')
        ->and($sanitizer->flush())->toBe('');
});

it('returns a partial token held at the end of a whole string', function (string $xml): void {
    expect(WxrSanitizer::string($xml))->toBe($xml);
})->with([
    'a lone opening bracket' => ['a<'],
    'the start of a CDATA opener' => ['text <![CDA'],
]);

it('counts a single repair of either kind as a repair', function (): void {
    $stripped = new WxrSanitizer;
    $stripped->feed("a\x01b");
    $stripped->flush();

    $escaped = new WxrSanitizer;
    $escaped->feed('a ]]> b');
    $escaped->flush();

    expect($stripped->getStrippedControlBytes())->toBe(1)
        ->and($stripped->getEscapedCdataTerminators())->toBe(0)
        ->and($stripped->madeRepairs())->toBeTrue()
        ->and($escaped->getStrippedControlBytes())->toBe(0)
        ->and($escaped->getEscapedCdataTerminators())->toBe(1)
        ->and($escaped->madeRepairs())->toBeTrue();
});

it('escapes a `]]>` that stripping a control byte creates, by region', function (string $xml, string $expected, int $escaped): void {
    $sanitizer = new WxrSanitizer;
    $out = $sanitizer->feed($xml).$sanitizer->flush();

    expect($out)->toBe($expected)
        ->and($sanitizer->getStrippedControlBytes())->toBe(1)
        ->and($sanitizer->getEscapedCdataTerminators())->toBe($escaped);
})->with([
    'in text' => ["<a>x]]\x01>y</a>", '<a>x]]&gt;y</a>', 1],
    'in CDATA' => ["<a><![CDATA[x]]\x01>y]]></a>", '<a><![CDATA[x]]]]><![CDATA[>y]]></a>', 1],
    'in a comment, which has no escape' => ["<a><!--x]]\x01>y--></a>", '<a><!--x]]>y--></a>', 0],
]);
