<?php

declare(strict_types=1);

/**
 * Tests for XmlParser::getTerms() — verifying term extraction from WordPress XML.
 *
 * These tests verify the fix for the XMLReader namespace handling so that
 * `wp:term` elements are correctly extracted from WordPress XML export files,
 * including exports whose term elements use a different namespace URI or are
 * unnamespaced.
 *
 * @command  php vendor/bin/pest tests/Unit/XmlParserGetTermsTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;

beforeEach(function (): void {
    // A self-contained WordPress term export exercising getTerms(): three
    // `wp:term` nodes across two taxonomies, including the `travel-awards`
    // term the specific-term assertions rely on. Inlined so the file-reading
    // tests do not depend on the private Exports fixtures submodule, which is
    // absent in standalone package CI.
    $fixture = <<<'XML'
    <?xml version="1.0" encoding="UTF-8"?>
    <rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">
        <channel>
            <wp:term>
                <wp:term_id>1</wp:term_id>
                <wp:term_taxonomy><![CDATA[category]]></wp:term_taxonomy>
                <wp:term_slug><![CDATA[news]]></wp:term_slug>
                <wp:term_parent><![CDATA[]]></wp:term_parent>
                <wp:term_name><![CDATA[News]]></wp:term_name>
            </wp:term>
            <wp:term>
                <wp:term_id>6</wp:term_id>
                <wp:term_taxonomy><![CDATA[uamspeople-categories]]></wp:term_taxonomy>
                <wp:term_slug><![CDATA[travel-awards]]></wp:term_slug>
                <wp:term_parent><![CDATA[]]></wp:term_parent>
                <wp:term_name><![CDATA[Travel Awards]]></wp:term_name>
            </wp:term>
            <wp:term>
                <wp:term_id>7</wp:term_id>
                <wp:term_taxonomy><![CDATA[uamspeople-categories]]></wp:term_taxonomy>
                <wp:term_slug><![CDATA[faculty-awards]]></wp:term_slug>
                <wp:term_parent><![CDATA[]]></wp:term_parent>
                <wp:term_name><![CDATA[Faculty Awards]]></wp:term_name>
            </wp:term>
        </channel>
    </rss>
    XML;

    // Write the fixture to a temp file so the tests exercise the real
    // file-parsing path without an external fixture.
    $this->testXmlPath = (string) tempnam(sys_get_temp_dir(), 'wxr_terms_');

    file_put_contents($this->testXmlPath, $fixture);
});

afterEach(function (): void {
    // Remove the temp fixture written in beforeEach().
    if (isset($this->testXmlPath) && is_file($this->testXmlPath)) {
        unlink($this->testXmlPath);
    }
});

/**
 * Assert the value is a SimpleXMLElement and return it, narrowed, so it can be
 * passed to the XpathHelper calls that require a concrete SimpleXMLElement.
 *
 * @param  mixed  $value  Parsed XML node from XmlParser::getTerms()
 */
function requireSimpleXmlElement(mixed $value): SimpleXMLElement
{
    expect($value)->toBeInstanceOf(SimpleXMLElement::class);

    // expect()->toBeInstanceOf() asserts at runtime but does not narrow the
    // static type, so re-check here to hand PHPStan a SimpleXMLElement.
    if (! $value instanceof SimpleXMLElement) {
        throw new RuntimeException('Expected a SimpleXMLElement from getTerms().');
    }

    return $value;
}

it('extracts terms correctly from WordPress XML', function (): void {
    $xmlParser = new XmlParser;
    $xmlParser->parse($this->testXmlPath);

    $terms = $xmlParser->getTerms();

    // Terms are extracted (not empty)
    expect($terms)->not->toBeEmpty();

    // Every returned item is a SimpleXMLElement object
    foreach ($terms as $term) {
        requireSimpleXmlElement($term);
    }
});

it('finds the travel-awards term in the uamspeople-categories taxonomy', function (): void {
    $xmlParser = new XmlParser;
    $xmlParser->parse($this->testXmlPath);

    $terms = $xmlParser->getTerms();

    $travelAwardsFound = false;
    $travelAwardsTerm = null;

    foreach ($terms as $term) {
        $termElement = requireSimpleXmlElement($term);

        $termSlug = XpathHelper::getStringValue(
            $termElement,
            'wp:term_slug'
        );

        $termTaxonomy = XpathHelper::getStringValue(
            $termElement,
            'wp:term_taxonomy'
        );

        if (
            $termSlug === 'travel-awards'
            && $termTaxonomy === 'uamspeople-categories'
        ) {
            $travelAwardsFound = true;
            $travelAwardsTerm = $termElement;

            break;
        }
    }

    expect($travelAwardsFound)->toBeTrue()
        ->and($travelAwardsTerm)->not->toBeNull();

    $travelAwardsElement = requireSimpleXmlElement($travelAwardsTerm);

    $termId = XpathHelper::getStringValue(
        $travelAwardsElement,
        'wp:term_id'
    );

    $termName = XpathHelper::getStringValue(
        $travelAwardsElement,
        'wp:term_name'
    );

    $termParent = XpathHelper::getStringValue(
        $travelAwardsElement,
        'wp:term_parent'
    );

    expect($termId)->toBe('6')
        ->and($termName)->toBe('Travel Awards')
        ->and($termParent)->toBeEmpty();
});

it('extracts uamspeople-categories terms specifically', function (): void {
    $xmlParser = new XmlParser;
    $xmlParser->parse($this->testXmlPath);

    $terms = $xmlParser->getTerms();

    $uamspeopleTerms = [];

    foreach ($terms as $term) {
        $termElement = requireSimpleXmlElement($term);

        $termTaxonomy = XpathHelper::getStringValue(
            $termElement,
            'wp:term_taxonomy'
        );

        if ($termTaxonomy === 'uamspeople-categories') {
            $termSlug = XpathHelper::getStringValue(
                $termElement,
                'wp:term_slug'
            );

            $termName = XpathHelper::getStringValue(
                $termElement,
                'wp:term_name'
            );

            $uamspeopleTerms[] = [
                'slug' => $termSlug,
                'name' => $termName,
            ];
        }
    }

    expect($uamspeopleTerms)->not->toBeEmpty();

    // travel-awards is among the extracted uamspeople-categories terms
    $travelAwardsFound = false;

    foreach ($uamspeopleTerms as $term) {
        if ($term['slug'] === 'travel-awards') {
            $travelAwardsFound = true;

            expect($term['name'])->toBe('Travel Awards');

            break;
        }
    }

    expect($travelAwardsFound)->toBeTrue()
        ->and(count($uamspeopleTerms))->toBeGreaterThan(0);
});

it('returns an empty array when no file path is set', function (): void {
    $xmlParser = new XmlParser;

    // Don't call parse() - leave the file path unset
    $terms = $xmlParser->getTerms();

    expect($terms)->toBeEmpty();
});

it('returns an empty array for well-formed but term-less XML', function (): void {
    $tempFile = (string) tempnam(
        sys_get_temp_dir(),
        'test_xml_'
    );

    file_put_contents(
        $tempFile,
        '<?xml version="1.0"?><rss><channel><item>malformed</item></channel></rss>'
    );

    $xmlParser = new XmlParser;

    // Parsing well-formed but term-less XML must not throw, and getTerms()
    // must return an empty array rather than error.
    $xmlParser->parse($tempFile);
    $terms = $xmlParser->getTerms();

    expect($terms)->toBe([]);

    unlink($tempFile);
});

it('handles WordPress terms with a different namespace URI', function (): void {
    $tempFile = (string) tempnam(
        sys_get_temp_dir(),
        'test_xml_'
    );

    $xmlContent = <<<'XML'
    <?xml version="1.0" encoding="UTF-8"?>
    <rss version="2.0" xmlns:wp="http://wordpress.org/export/1.1/">
        <channel>
            <wp:term>
                <wp:term_id>1</wp:term_id>
                <wp:term_taxonomy><![CDATA[uamspeople-categories]]></wp:term_taxonomy>
                <wp:term_slug><![CDATA[test-term]]></wp:term_slug>
                <wp:term_parent><![CDATA[]]></wp:term_parent>
                <wp:term_name><![CDATA[Test Term]]></wp:term_name>
            </wp:term>
        </channel>
    </rss>
    XML;

    file_put_contents(
        $tempFile,
        $xmlContent
    );

    $xmlParser = new XmlParser;
    $xmlParser->parse($tempFile);

    $terms = $xmlParser->getTerms();

    // The term is found even with a different namespace URI
    expect($terms)->not->toBeEmpty()
        ->and($terms)->toHaveCount(1);

    requireSimpleXmlElement($terms[0]);

    unlink($tempFile);
});

it('handles WordPress terms with unnamespaced term elements', function (): void {
    $tempFile = (string) tempnam(
        sys_get_temp_dir(),
        'test_xml_'
    );

    $xmlContent = <<<'XML'
    <?xml version="1.0" encoding="UTF-8"?>
    <rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">
        <channel>
            <!-- Term element without namespace but with WordPress content -->
            <term>
                <wp:term_id>1</wp:term_id>
                <wp:term_taxonomy><![CDATA[uamspeople-categories]]></wp:term_taxonomy>
                <wp:term_slug><![CDATA[test-term]]></wp:term_slug>
                <wp:term_parent><![CDATA[]]></wp:term_parent>
                <wp:term_name><![CDATA[Test Term]]></wp:term_name>
            </term>
        </channel>
    </rss>
    XML;

    file_put_contents(
        $tempFile,
        $xmlContent
    );

    $xmlParser = new XmlParser;
    $xmlParser->parse($tempFile);

    $terms = $xmlParser->getTerms();

    // The term is found even when the term element itself is unnamespaced
    expect($terms)->not->toBeEmpty()
        ->and($terms)->toHaveCount(1);

    requireSimpleXmlElement($terms[0]);

    unlink($tempFile);
});

it('ignores non-WordPress term elements', function (): void {
    $tempFile = (string) tempnam(
        sys_get_temp_dir(),
        'test_xml_'
    );

    $xmlContent = <<<'XML'
    <?xml version="1.0" encoding="UTF-8"?>
    <rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">
        <channel>
            <!-- WordPress term - should be included -->
            <wp:term>
                <wp:term_id>1</wp:term_id>
                <wp:term_taxonomy><![CDATA[uamspeople-categories]]></wp:term_taxonomy>
                <wp:term_slug><![CDATA[wordpress-term]]></wp:term_slug>
                <wp:term_parent><![CDATA[]]></wp:term_parent>
                <wp:term_name><![CDATA[WordPress Term]]></wp:term_name>
            </wp:term>
            <!-- Non-WordPress term - should be ignored -->
            <term>
                <id>2</id>
                <name>Non-WordPress Term</name>
                <slug>non-wordpress-term</slug>
            </term>
        </channel>
    </rss>
    XML;

    file_put_contents(
        $tempFile,
        $xmlContent
    );

    $xmlParser = new XmlParser;
    $xmlParser->parse($tempFile);

    $terms = $xmlParser->getTerms();

    // Only the WordPress term is found
    expect($terms)->toHaveCount(1);

    requireSimpleXmlElement($terms[0]);

    unlink($tempFile);
});
