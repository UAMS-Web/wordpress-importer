<?php

declare(strict_types=1);

/**
 * Pins the libxml limits `TextProcessor::loadHtml()` parses post content under (wordpress-importer-archive#1273).
 *
 * `LIBXML_PARSEHUGE` stays on this parser because real content needs it: three
 * `inside.uams.edu/clinicaleducation` pages carry runs of unclosed `<a><strong>`
 * that nest 318 to 786 elements deep, past libxml's default limit of 256, and
 * without the flag the text past that depth is lost. The fragment below reproduces
 * that shape at 300 levels, so dropping the flag fails the first case.
 *
 * `LIBXML_NOENT` is gone. On HTML it changed none of the 82,459 post bodies in the
 * exports corpus, and the second case pins that named, numeric and escaped
 * entities still come out as they did.
 *
 * @command  php vendor/bin/pest tests/Unit/TextProcessorParseLimitsTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\TextProcessor;

it('keeps text nested past libxml\'s default depth, as unclosed links in real content do', function (): void {
    // Unclosed <a><strong> pairs, each nesting inside the last, as on the clinicaleducation pages
    $html = '<p>'.\str_repeat('<a href="https://example.test/"><strong>link', 150).'deepest text</p>';

    $out = TextProcessor::getInnerHtml(TextProcessor::loadHtml($html));

    expect($out)->toContain('deepest text')
        ->and(\substr_count($out, '<strong>'))->toBe(150);
});

it('leaves entities as it did when LIBXML_NOENT was set', function (): void {
    $out = TextProcessor::getInnerHtml(TextProcessor::loadHtml('<p>caf&eacute; &amp; &#233; &lt;b&gt;</p>'));

    // Captured from main's TextProcessor, which still passed LIBXML_NOENT. The escaped
    // tag becoming a real one is existing behavior, from decoding entities before the parse
    expect($out)->toBe('<p>café &amp; é <b></b></p>');
});
