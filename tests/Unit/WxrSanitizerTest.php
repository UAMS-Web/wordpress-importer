<?php

declare(strict_types=1);

/**
 * Tests for WxrSanitizer / WxrSanitizingStreamFilter — the pre-parse repair of
 * XML-illegal bytes in WordPress exports.
 *
 * Covers the two defects from #8 (raw C0 control bytes and stray `]]>` inside
 * CDATA payloads), the regions whose literal text must survive untouched
 * (CDATA, comments, processing instructions), and the chunk-boundary invariant
 * that makes the streaming design equivalent to sanitizing the whole string.
 *
 * Fixtures are synthesized inline rather than read from the private Exports
 * submodule, which is absent in standalone package CI.
 *
 * @command  php vendor/bin/pest tests/Unit/WxrSanitizerTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlStreamParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\WxrSanitizedStream;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\WxrSanitizer;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\WxrSanitizingStreamFilter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;

/**
 * Write a string to a temporary file and register it for cleanup.
 */
function wxrTempFile(string $contents): string
{
    // tempnam() creates the reservation file itself; appending .xml would leave
    // that zero-byte stub behind, so rename onto the name actually used.
    $reserved = tempnam(sys_get_temp_dir(), 'wxr');
    $path = $reserved.'.xml';
    rename($reserved, $path);
    file_put_contents($path, $contents);

    return $path;
}

/**
 * Build a minimal but structurally real WXR document around the given items.
 */
function wxrDocument(string $items): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
        .'<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">'."\n"
        .'<channel><wp:base_blog_url>https://example.test</wp:base_blog_url>'."\n"
        .$items
        .'</channel></rss>';
}

/**
 * Build one WXR <item> with the given post type and meta payload.
 */
function wxrItem(string $postType, string $metaValue, string $title = 'Item'): string
{
    return '<item><title>'.$title.'</title>'
        .'<wp:post_type><![CDATA['.$postType.']]></wp:post_type>'
        .'<wp:status><![CDATA[publish]]></wp:status>'
        .'<wp:postmeta><wp:meta_key><![CDATA[_wp_attachment_metadata]]></wp:meta_key>'
        .'<wp:meta_value><![CDATA['.$metaValue.']]></wp:meta_value>'
        .'</wp:postmeta></item>'."\n";
}

/**
 * Remember libxml's internal-errors mode as a test found it, or read it back.
 *
 * Several tests here switch the mode on to read libxml's error list. The mode
 * is process-wide, so leaving it on silences warnings in every later test
 * (wordpress-importer-archive#1354); each test's mode is put back after it.
 *
 * @param  ?bool  $mode  The mode to remember, or null to read the remembered one
 * @return bool The remembered mode
 */
function wxrRememberedLibxmlMode(?bool $mode = null): bool
{
    static $remembered = false;

    if ($mode !== null) {
        $remembered = $mode;
    }

    // A function-level static reads as mixed to PHPStan; only a bool is stored
    return $remembered === true;
}

beforeEach(function (): void {
    wxrRememberedLibxmlMode(libxml_use_internal_errors());
});

afterEach(function (): void {
    libxml_clear_errors();
    libxml_use_internal_errors(wxrRememberedLibxmlMode());

    $stale = glob(sys_get_temp_dir().'/wxr*.xml');

    foreach ($stale === false ? [] : $stale as $file) {
        @unlink($file);
    }
});

// ---
// The two defects the ticket names
// ---

it('strips the XML-illegal C0 control bytes libxml rejects', function (): void {
    $payload = "a:1:{s:5:\"codec\";s:9:\"still\x00\x00\x10time\";}";

    $sanitized = WxrSanitizer::string(wxrDocument(wxrItem('attachment', $payload)));

    expect($sanitized)->not->toContain("\x00")
        ->and($sanitized)->not->toContain("\x10")
        ->and($sanitized)->toContain('stilltime');
});

it('preserves tab, newline and carriage return, which XML 1.0 allows', function (): void {
    $sanitized = WxrSanitizer::string("<a>\tone\ntwo\rthree</a>");

    expect($sanitized)->toBe("<a>\tone\ntwo\rthree</a>");
});

it('escapes a stray `]]>` that appears outside any CDATA section', function (): void {
    $sanitized = WxrSanitizer::string('<a>oops ]]> here</a>');

    expect($sanitized)->toBe('<a>oops ]]&gt; here</a>');
});

it('leaves a legitimate CDATA terminator alone', function (): void {
    $source = '<a><![CDATA[keep ]] and > apart]]></a>';

    expect(WxrSanitizer::string($source))->toBe($source);
});

it('leaves `]]>` inside a comment untouched', function (): void {
    $source = '<a><!-- a ]]> in a comment --></a>';

    expect(WxrSanitizer::string($source))->toBe($source);
});

it('leaves `]]>` inside a processing instruction untouched', function (): void {
    $source = '<a><?php echo "]]>"; ?></a>';

    expect(WxrSanitizer::string($source))->toBe($source);
});

it('reports what it repaired', function (): void {
    $sanitizer = new WxrSanitizer;
    $out = $sanitizer->feed("<a>\x00\x01 ]]> ]]> b</a>").$sanitizer->flush();

    expect($sanitizer->getStrippedControlBytes())->toBe(2)
        ->and($sanitizer->getEscapedCdataTerminators())->toBe(2)
        ->and($sanitizer->madeRepairs())->toBeTrue()
        ->and($out)->toBe('<a> ]]&gt; ]]&gt; b</a>');
});

it('reports no repairs on a clean document', function (): void {
    $sanitizer = new WxrSanitizer;
    $sanitizer->feed('<a><![CDATA[clean]]></a>');
    $sanitizer->flush();

    expect($sanitizer->madeRepairs())->toBeFalse();
});

// ---
// The chunk-boundary invariant
// ---

it('produces the same output no matter where the chunk boundaries fall', function (int $chunkSize): void {
    // A document deliberately dense in tokens that can straddle a boundary
    $source = wxrDocument(
        wxrItem('attachment', "a:1:{s:3:\"bin\";s:4:\"x\x00y\x10\";}")
        .wxrItem('post', 'plain payload')
        .'<item><!-- comment with ]]> --><?pi ]]> ?>stray ]]> text</item>'."\n"
        .wxrItem('attachment', 'trailing ]] and > pieces')
    );

    $expected = WxrSanitizer::string($source);

    $sanitizer = new WxrSanitizer;
    $streamed = '';

    foreach (str_split($source, max(1, $chunkSize)) as $chunk) {
        $streamed .= $sanitizer->feed($chunk);
    }

    $streamed .= $sanitizer->flush();

    expect($streamed)->toBe($expected);
})->with([1, 2, 3, 4, 7, 8, 9, 13, 64, 511, 8192]);

it('reassembles a token split across every possible offset', function (): void {
    // Walk the split point through a `<![CDATA[ … ]]>` pair one byte at a time
    $source = '<a>lead<![CDATA[body ]] here]]>tail ]]> end</a>';
    $expected = WxrSanitizer::string($source);

    for ($split = 1; $split < strlen($source); $split++) {
        $sanitizer = new WxrSanitizer;
        $actual = $sanitizer->feed(substr($source, 0, $split))
            .$sanitizer->feed(substr($source, $split))
            .$sanitizer->flush();

        expect($actual)->toBe($expected, "split at offset {$split}");
    }
});

it('never splits a multi-byte UTF-8 character across a chunk boundary', function (): void {
    // Curly quotes, an em dash, a registered mark and a non-Latin script
    $source = '<a>“smart” — ® 日本語 ünïcøde</a>';

    for ($chunkSize = 1; $chunkSize <= 12; $chunkSize++) {
        $sanitizer = new WxrSanitizer;
        $actual = '';

        foreach (str_split($source, max(1, $chunkSize)) as $chunk) {
            $actual .= $sanitizer->feed($chunk);
        }

        $actual .= $sanitizer->flush();

        expect($actual)->toBe($source, "chunk size {$chunkSize}");
    }
});

it('leaves UTF-8 continuation bytes in the C1 range intact', function (): void {
    // U+0085 and U+009F encode as C2 85 / C2 9F — byte-level stripping would
    // shred them, so the sanitizer must only ever touch bytes below 0x20.
    $source = "<a>\u{0085}\u{009F}\u{00A0}</a>";

    expect(WxrSanitizer::string($source))->toBe($source);
});

// ---
// The stream filter, end to end through the real parsers
// ---

it('wraps a path in an opaque URI libxml cannot mangle', function (): void {
    $wrapped = WxrSanitizedStream::wrap('/tmp/export.xml');

    // Opaque and hex-only, so libxml's URI canonicalization is a no-op
    expect($wrapped)->toStartWith('uamswxr://')
        ->and($wrapped)->toMatch('#^uamswxr://[0-9a-f]{32}$#')
        ->and(WxrSanitizedStream::resolve($wrapped))->toBe('/tmp/export.xml');
});

it('wraps a given path to a stable URI', function (): void {
    expect(WxrSanitizedStream::wrap('/tmp/export.xml'))
        ->toBe(WxrSanitizedStream::wrap('/tmp/export.xml'));
});

it('does not wrap an already-wrapped path twice', function (): void {
    $once = WxrSanitizedStream::wrap('/tmp/export.xml');

    expect(WxrSanitizedStream::wrap($once))->toBe($once);
});

it('parses an export whose path contains characters libxml would escape', function (): void {
    // A php://filter URI fails here: libxml percent-escapes the space before the
    // streams layer ever sees it, so the file "does not exist". The opaque URI
    // has nothing escapable in it.
    $dir = sys_get_temp_dir().'/wxr dir with spaces';
    @mkdir($dir);

    $path = $dir.'/export file.xml';
    file_put_contents($path, wxrDocument(wxrItem('post', "poison\x00payload", 'Spaced')));

    $parser = new XmlParser;
    $parser->parse($path);

    expect($parser->getContentTypes())->toBe([['post', 1]]);

    $streamed = [];

    foreach ((new XmlStreamParser($path))->streamItems('post') as $item) {
        $streamed[] = (string) $item->title;
    }

    expect($streamed)->toBe(['Spaced']);

    unlink($path);
    @rmdir($dir);
});

it('reports a failure instead of silently succeeding when the file is missing', function (): void {
    $parser = new XmlParser;

    // libxml emits its own I/O warning for an unreadable path, exactly as it
    // does without the wrapper; capture it so the assertion is about the throw.
    $previous = libxml_use_internal_errors(true);

    try {
        expect(fn () => $parser->parse('/nonexistent/path/to/export.xml'))
            ->toThrow(Exception::class, 'Failed to parse XML file.');
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
});

it('reads a poisoned document through the filter without libxml errors', function (): void {
    $path = wxrTempFile(wxrDocument(wxrItem('attachment', "a:1:{s:1:\"x\";s:3:\"a\x00b\";}")));

    libxml_use_internal_errors(true);
    libxml_clear_errors();

    $xml = simplexml_load_file(
        WxrSanitizedStream::wrap($path),
        'SimpleXMLElement',
        LIBXML_NOCDATA
    );

    $errors = libxml_get_errors();
    libxml_clear_errors();

    expect($xml)->not->toBeFalse()
        ->and($errors)->toBe([]);

    unlink($path);
});

it('parses a document that fails outright without the filter', function (): void {
    $path = wxrTempFile(wxrDocument(wxrItem('attachment', "a:1:{s:1:\"x\";s:3:\"a\x00b\";}")));

    libxml_use_internal_errors(true);
    libxml_clear_errors();

    // Establish the defect: the unwrapped read is a hard failure
    $unfiltered = simplexml_load_file($path, 'SimpleXMLElement', LIBXML_NOCDATA);
    libxml_clear_errors();

    expect($unfiltered)->toBeFalse();

    // And the wrapped read through XmlParser succeeds
    $parser = new XmlParser;
    $parser->parse($path);

    expect($parser->getContentTypes())->toBe([['attachment', 1]]);

    unlink($path);
});

it('streams every item past a poisoned one instead of truncating', function (): void {
    // Ten clean items, a poisoned one in the middle, ten more after it
    $items = '';

    for ($i = 0; $i < 10; $i++) {
        $items .= wxrItem('post', 'clean', "Before {$i}");
    }

    $items .= wxrItem('post', "a:1:{s:1:\"x\";s:3:\"a\x00b\";}", 'Poisoned');

    for ($i = 0; $i < 10; $i++) {
        $items .= wxrItem('post', 'clean', "After {$i}");
    }

    $path = wxrTempFile(wxrDocument($items));

    $streamed = [];

    foreach ((new XmlStreamParser($path))->streamItems('post') as $item) {
        $streamed[] = (string) $item->title;
    }

    // The items after the poisoned one are the regression: without the filter
    // XMLReader stops at the bad byte and yields only the leading ten.
    expect($streamed)->toHaveCount(21)
        ->and($streamed[0])->toBe('Before 0')
        ->and($streamed[10])->toBe('Poisoned')
        ->and($streamed[20])->toBe('After 9');

    unlink($path);
});

it('demonstrates the untreated truncation the filter prevents', function (): void {
    // The same document read with a bare XMLReader stops early, which is the
    // silent data loss #8 is really about.
    $items = '';

    for ($i = 0; $i < 5; $i++) {
        $items .= wxrItem('post', 'clean', "Before {$i}");
    }

    $items .= wxrItem('post', "poison\x00here", 'Poisoned');

    for ($i = 0; $i < 5; $i++) {
        $items .= wxrItem('post', 'clean', "After {$i}");
    }

    $path = wxrTempFile(wxrDocument($items));

    libxml_use_internal_errors(true);
    libxml_clear_errors();

    $reader = XMLReader::open($path, null, 0);
    $seen = 0;

    expect($reader)->not->toBeFalse();

    if ($reader !== false) {
        while (@$reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'item') {
                $seen++;
            }
        }

        $reader->close();
    }
    libxml_clear_errors();

    expect($seen)->toBeLessThan(11);

    unlink($path);
});

it('streams terms, tags and categories past a poisoned item', function (): void {
    // Every node sits *after* the poisoned item, so an unwrapped reader stops
    // before reaching any of them. With the node placed first the assertion
    // would pass either way, which is the false green this ordering removes.
    $channel = wxrItem('attachment', "poison\x00bytes")
        .'<wp:term><wp:term_id>1</wp:term_id><wp:term_taxonomy><![CDATA[category]]></wp:term_taxonomy>'
        .'<wp:term_slug><![CDATA[news]]></wp:term_slug><wp:term_name><![CDATA[News]]></wp:term_name></wp:term>'."\n"
        .'<wp:tag><wp:tag_slug><![CDATA[after]]></wp:tag_slug></wp:tag>'."\n"
        .'<wp:category><wp:category_nicename><![CDATA[later]]></wp:category_nicename></wp:category>'."\n";

    $path = wxrTempFile(wxrDocument($channel));

    $parser = new XmlParser;
    $parser->setFilePaths([$path]);

    expect($parser->getTerms())->toHaveCount(1)
        ->and($parser->getTags())->toHaveCount(1)
        ->and($parser->getCategories())->toHaveCount(1);

    unlink($path);
});

it('streams every unfiltered item past a poisoned one', function (): void {
    // Covers XmlStreamParser::streamAllItems, whose wrap() site no test reached
    $items = wxrItem('post', "poison\x00bytes", 'Poisoned')
        .wxrItem('page', 'clean', 'After A')
        .wxrItem('attachment', 'clean', 'After B');

    $path = wxrTempFile(wxrDocument($items));

    $titles = [];

    foreach ((new XmlStreamParser($path))->streamAllItems() as $item) {
        $titles[] = (string) $item->title;
    }

    expect($titles)->toBe(['Poisoned', 'After A', 'After B']);

    unlink($path);
});

it('emits the withheld tail when the stream closes', function (): void {
    // The filter holds back bytes that may be a split token and only releases
    // them on teardown. Without that release the document loses its last bytes.
    foreach (['<a>text<![CDATA', '<a>b</a>]]', '<a>c</a><', '<a>d</a>-'] as $source) {
        $path = wxrTempFile($source);

        expect(file_get_contents(WxrSanitizedStream::wrap($path)))->toBe($source, $source);

        unlink($path);
    }
});

it('keeps a comment region open across a chunk boundary', function (): void {
    // Kills the mutant that never enters the comment state: a `]]>` inside a
    // comment must survive, and the comment must still terminate correctly.
    $source = '<a><!-- keep ]]> here --><b>after</b></a>';
    $path = wxrTempFile($source);

    expect(file_get_contents(WxrSanitizedStream::wrap($path)))->toBe($source);

    libxml_use_internal_errors(true);
    libxml_clear_errors();

    $xml = simplexml_load_file(WxrSanitizedStream::wrap($path), 'SimpleXMLElement', LIBXML_NOCDATA);
    $errors = libxml_get_errors();
    libxml_clear_errors();

    expect($errors)->toBe([])
        ->and($xml)->not->toBeFalse();
    expect($xml === false ? '' : (string) $xml->b)->toBe('after');

    unlink($path);
});

it('keeps a processing instruction region open across a chunk boundary', function (): void {
    $source = '<a><?target ]]> body ?><b>after</b></a>';
    $path = wxrTempFile($source);

    expect(file_get_contents(WxrSanitizedStream::wrap($path)))->toBe($source);

    unlink($path);
});

// ---
// Byte-level repair is only safe for ASCII-superset encodings
// ---

it('leaves a UTF-16 document alone rather than shredding it', function (): void {
    // In UTF-16 every ASCII character carries a 0x00 byte, so byte-level
    // stripping would delete half of every character. Such a file parses fine
    // untouched, so the wrapper must pass it straight through.
    $utf8 = '<?xml version="1.0" encoding="UTF-16"?><rss><channel><title>Café</title></channel></rss>';
    $utf16 = "\xFF\xFE".mb_convert_encoding($utf8, 'UTF-16LE', 'UTF-8');

    $path = wxrTempFile($utf16);

    expect(file_get_contents(WxrSanitizedStream::wrap($path)))->toBe($utf16);

    libxml_use_internal_errors(true);
    libxml_clear_errors();

    $xml = simplexml_load_file(WxrSanitizedStream::wrap($path), 'SimpleXMLElement', LIBXML_NOCDATA);
    libxml_clear_errors();

    expect($xml)->not->toBeFalse();
    expect($xml === false ? '' : (string) $xml->channel->title)->toBe('Café');

    unlink($path);
});

// ---
// Removing a control byte must not splice a structural token into existence
// ---

it('does not forge a CDATA terminator by removing a control byte', function (): void {
    // "]]" NUL ">" is not a CDATA terminator in the source bytes. Stripping the
    // NUL first would collapse it into one and close the section early, losing
    // the rest of the payload.
    $source = '<a><![CDATA[before ]]'."\x00".'> after]]></a>';

    $sanitized = WxrSanitizer::string($source);

    libxml_use_internal_errors(true);
    libxml_clear_errors();

    $xml = simplexml_load_string($sanitized, 'SimpleXMLElement', LIBXML_NOCDATA);
    $errors = libxml_get_errors();
    libxml_clear_errors();

    expect($errors)->toBe([])
        ->and($xml)->not->toBeFalse()
        ->and((string) $xml)->toBe('before ]]> after');
});

it('does not let a forged token break out of a CDATA payload', function (): void {
    // A bare `<` is legal inside CDATA, so stripping a control byte here really
    // can splice `<![CDATA[` or `<!--` into the payload. Those must stay inert
    // text: only `]]>` can end the section, and only the real one does.
    $source = '<a><![CDATA[x<'."\x00".'!-- y <'."\x00".'![CDATA[ z]]></a>';

    $sanitized = WxrSanitizer::string($source);

    libxml_use_internal_errors(true);
    libxml_clear_errors();

    $xml = simplexml_load_string($sanitized, 'SimpleXMLElement', LIBXML_NOCDATA);
    $errors = libxml_get_errors();
    libxml_clear_errors();

    expect($errors)->toBe([])
        ->and($xml)->not->toBeFalse()
        ->and((string) $xml)->toBe('x<!-- y <![CDATA[ z');
});

it('finds an item that sits after a poisoned one via XpathHelper', function (): void {
    // XpathHelper::findItemByPostId loads each part with simplexml_load_file.
    // Unwrapped, the poisoned part returns false and the lookup silently misses.
    $items = wxrItem('post', 'clean', 'First')
        .'<item><title>Poisoned</title>'
        .'<wp:post_id>10</wp:post_id>'
        .'<wp:post_type><![CDATA[post]]></wp:post_type>'
        .'<wp:status><![CDATA[publish]]></wp:status>'
        ."<wp:postmeta><wp:meta_value><![CDATA[bad\x00byte]]></wp:meta_value></wp:postmeta>"
        .'</item>'."\n"
        .'<item><title>Wanted</title>'
        .'<wp:post_id>42</wp:post_id>'
        .'<wp:post_type><![CDATA[post]]></wp:post_type>'
        .'<wp:status><![CDATA[publish]]></wp:status>'
        .'</item>'."\n";

    $path = wxrTempFile(wxrDocument($items));

    $found = XpathHelper::findItemByPostId($path, '42', 'post');

    expect($found)->not->toBeNull();
    expect($found === null ? '' : (string) $found->title)->toBe('Wanted');

    unlink($path);
});

it('resolves a link whose item sits after a poisoned one via LinkProcessor', function (): void {
    // LinkProcessor::findPostDataByUrl loads each part the same way.
    $items = '<item><title>Poisoned</title><link>https://example.test/first/</link>'
        .'<wp:post_id>1</wp:post_id>'
        .'<wp:post_type><![CDATA[page]]></wp:post_type>'
        .'<wp:status><![CDATA[publish]]></wp:status>'
        ."<wp:postmeta><wp:meta_value><![CDATA[bad\x00byte]]></wp:meta_value></wp:postmeta>"
        .'</item>'."\n"
        .'<item><title>Target</title><link>https://example.test/third/</link>'
        .'<wp:post_id>3</wp:post_id>'
        .'<wp:post_name><![CDATA[third]]></wp:post_name>'
        .'<wp:post_parent>0</wp:post_parent>'
        .'<wp:post_type><![CDATA[page]]></wp:post_type>'
        .'<wp:status><![CDATA[publish]]></wp:status>'
        .'</item>'."\n";

    $path = wxrTempFile(wxrDocument($items));

    $reflection = new ReflectionClass(LinkProcessor::class);
    $processor = $reflection->newInstanceWithoutConstructor();
    $method = $reflection->getMethod('findPostDataByUrl');
    $method->setAccessible(true);

    $resolved = $method->invoke($processor, 'https://example.test/third/', $path, null);

    expect($resolved)->not->toBeNull();

    unlink($path);
});

it('keeps a clean document byte-identical through the filter', function (): void {
    $source = wxrDocument(wxrItem('post', 'entirely clean payload'));
    $path = wxrTempFile($source);

    $read = file_get_contents(WxrSanitizedStream::wrap($path));

    expect($read)->toBe($source);

    unlink($path);
});

it('round-trips a large clean document through the filter unchanged', function (): void {
    // Exercise real bucket boundaries: well past the 8 KB chunk libxml reads
    $items = '';

    for ($i = 0; $i < 400; $i++) {
        $items .= wxrItem('post', str_repeat('payload ]] with > pieces ', 8), "Item {$i}");
    }

    $source = wxrDocument($items);
    $path = wxrTempFile($source);

    expect(strlen($source))->toBeGreaterThan(100_000)
        ->and(file_get_contents(WxrSanitizedStream::wrap($path)))->toBe($source);

    unlink($path);
});
