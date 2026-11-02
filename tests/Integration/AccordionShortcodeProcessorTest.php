<?php

declare(strict_types=1);

/**
 * Tests for AccordionShortcodeProcessor, which turns `[accordion]` and
 * `[section]` shortcodes into accordion section data.
 *
 * `parse()` reads the shortcode content from ProcessingContext and returns
 * `['type' => 'accordion', ...sections]`, each section holding a tag-stripped
 * title and its trimmed content run through `TextProcessor::processForBard()`
 * with wpautop. `parseFromSegment()` finds the first `[accordion]...[/accordion]`
 * in a segment, stores its trimmed content in ProcessingContext and parses it,
 * or returns an empty array when there is no non-empty accordion.
 * `toTiptapHtml()` flattens an accordion for Bard content: each section becomes
 * an `<h3>` title and a paragraph, since the host app defines no accordion set.
 *
 * This is in the light Statamic band because `TextProcessor` logs through the
 * `Log` facade.
 *
 * @command  php vendor/bin/pest tests/Integration/AccordionShortcodeProcessorTest.php
 */

use PHPUnit\Framework\Assert;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\AccordionShortcodeProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\TextProcessor;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;
use UAMSWeb\Aether\WordPressImporter\Tests\Support\ExportsCorpus;

uses(StatamicTestCase::class);

/**
 * Build an AssetImporter without its constructor, with the base URL the link
 * processing inside TextProcessor reads.
 */
function accordionAssetImporter(): AssetImporter
{
    $assetImporter = (new ReflectionClass(AssetImporter::class))->newInstanceWithoutConstructor();

    (new ReflectionProperty(AssetImporter::class, 'baseBlogUrl'))->setValue($assetImporter, 'https://example.test');

    return $assetImporter;
}

/**
 * The content TextProcessor produces for a section body, with wpautop on.
 *
 * @param  string  $content  The section body as the processor passes it
 * @return mixed The processed Bard content
 */
function accordionSectionContent(string $content): mixed
{
    return TextProcessor::processForBard(
        $content,
        'default',
        accordionAssetImporter(),
        new BufferedOutput,
        true,
        false,
        [],
        [],
        'slug'
    );
}

/**
 * A Bard paragraph node holding plain text.
 *
 * @param  string  $text  The paragraph text
 * @return array<string, mixed> The paragraph node
 */
function accordionParagraph(string $text): array
{
    return [
        'type' => 'paragraph',
        'content' => [['type' => 'text', 'text' => $text]],
        'attrs' => [],
    ];
}

beforeEach(function (): void {
    ImportWarningManager::clearWarnings();
    ProcessingContext::clearShortcodeContent();
});

afterEach(function (): void {
    ImportWarningManager::clearWarnings();
    ProcessingContext::clearShortcodeContent();
    ProcessingContext::clearAttachments();
});

it('parses each section into a stripped title and wpautop-processed content', function (): void {
    ProcessingContext::setShortcodeContent(
        "[section title=\"<b>One</b> &amp; two\"]\n Hello\n\nWorld \n[/section]\n[section title='Two' open=\"yes\"]Second[/section]"
    );

    $result = (new AccordionShortcodeProcessor)->parse(
        [],
        'default',
        accordionAssetImporter(),
        new BufferedOutput,
        [],
        'slug'
    );

    // A blank line between paragraphs becomes two paragraphs only with wpautop on
    expect($result)->toBe([
        'type' => 'accordion',
        [
            'title' => 'One & two',
            'content' => [accordionParagraph('Hello'), accordionParagraph('World')],
        ],
        [
            'title' => 'Two',
            'content' => [accordionParagraph('Second')],
        ],
    ]);
});

it('produces the same content whatever whitespace surrounds a section body', function (): void {
    ProcessingContext::setShortcodeContent('[section title="A"]  <strong>Bold</strong> text  [/section]');

    $result = (new AccordionShortcodeProcessor)->parse([], 'default', accordionAssetImporter(), new BufferedOutput, [], 'slug');

    expect($result)->toBe([
        'type' => 'accordion',
        ['title' => 'A', 'content' => accordionSectionContent('<strong>Bold</strong> text')],
    ]);
});

it('processes a section body as ordinary content, not as inner blocks', function (): void {
    // Inner-block processing strips block comments first; here the comment stays,
    // and the hard break it leaves after "one" is the observable difference
    $body = "one\n<!-- wp:x -->\n\ntwo";

    ProcessingContext::setShortcodeContent('[section title="A"]'.$body.'[/section]');

    $result = (new AccordionShortcodeProcessor)->parse([], 'default', accordionAssetImporter(), new BufferedOutput, [], 'slug');

    expect($result)->toBe([
        'type' => 'accordion',
        [
            'title' => 'A',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [['type' => 'text', 'text' => 'one'], ['type' => 'hardBreak']],
                    'attrs' => [],
                ],
                accordionParagraph('two'),
            ],
        ],
    ])
        ->and($result)->toBe([
            'type' => 'accordion',
            ['title' => 'A', 'content' => accordionSectionContent($body)],
        ]);
});

it('keeps other shortcodes inside a section body', function (): void {
    ProcessingContext::setShortcodeContent('[section title="A"]before [b]x[/b] after[/section]');

    $result = (new AccordionShortcodeProcessor)->parse([], 'default', accordionAssetImporter(), new BufferedOutput, [], 'slug');

    expect($result)->toBe([
        'type' => 'accordion',
        ['title' => 'A', 'content' => accordionSectionContent('before [b]x[/b] after')],
    ]);
});

it('ends a section at its own closing tag', function (): void {
    ProcessingContext::setShortcodeContent('[section title="A"]one[/section] between [section title="B"]two[/section]');

    $result = (new AccordionShortcodeProcessor)->parse([], 'default', accordionAssetImporter(), new BufferedOutput, [], 'slug');

    expect($result)->toBe([
        'type' => 'accordion',
        ['title' => 'A', 'content' => [accordionParagraph('one')]],
        ['title' => 'B', 'content' => [accordionParagraph('two')]],
    ]);
});

it('returns only the type when the content holds no complete section', function (?string $content): void {
    if ($content !== null) {
        ProcessingContext::setShortcodeContent($content);
    }

    $result = (new AccordionShortcodeProcessor)->parse([], 'default', accordionAssetImporter(), new BufferedOutput);

    expect($result)->toBe(['type' => 'accordion']);
})->with([
    'no shortcode content in context' => [null],
    'no sections' => ['just text'],
    'section never closed' => ['[section title="A"]body'],
]);

it('parses a section with an empty or missing title as untitled (#167)', function (string $content): void {
    ProcessingContext::setShortcodeContent($content);

    $result = (new AccordionShortcodeProcessor)->parse([], 'default', accordionAssetImporter(), new BufferedOutput);

    expect($result)->toBe([
        'type' => 'accordion',
        ['title' => '', 'content' => [accordionParagraph('body')]],
    ]);
})->with([
    'empty title' => ['[section title=""]body[/section]'],
    'no attributes' => ['[section]body[/section]'],
    'other attributes only' => ['[section open="true"]body[/section]'],
]);

it('parses an unquoted title, and a title after another attribute (#167)', function (string $content, string $title): void {
    ProcessingContext::setShortcodeContent($content);

    $result = (new AccordionShortcodeProcessor)->parse([], 'default', accordionAssetImporter(), new BufferedOutput);

    expect($result)->toBe([
        'type' => 'accordion',
        ['title' => $title, 'content' => [accordionParagraph('body')]],
    ]);
})->with([
    'unquoted' => ['[section title=Transcript]body[/section]', 'Transcript'],
    'unquoted, then another attribute' => ['[section title=Transcript open=1]body[/section]', 'Transcript'],
    'after another attribute' => ['[section open="1" title="Late"]body[/section]', 'Late'],
    'unquoted with markup stripped' => ['[section title=<b>Bold</b>]body[/section]', 'Bold'],
]);

it('finds the accordion in a segment, stores its trimmed content and parses it', function (): void {
    $result = (new AccordionShortcodeProcessor)->parseFromSegment(
        "Intro [accordion]\n [section title=\"A\"]one[/section] \n[/accordion] outro",
        'default',
        accordionAssetImporter(),
        new BufferedOutput,
        'slug'
    );

    expect($result)->toBe([
        'type' => 'accordion',
        ['title' => 'A', 'content' => [accordionParagraph('one')]],
    ])
        ->and(ProcessingContext::getShortcodeContent())->toBe('[section title="A"]one[/section]');
});

it('parses only the first accordion in a segment', function (): void {
    $result = (new AccordionShortcodeProcessor)->parseFromSegment(
        '[accordion][section title="A"]one[/section][/accordion][accordion][section title="B"]two[/section][/accordion]',
        'default',
        accordionAssetImporter(),
        new BufferedOutput
    );

    expect($result)->toBe([
        'type' => 'accordion',
        ['title' => 'A', 'content' => [accordionParagraph('one')]],
    ]);
});

it('returns an empty array and leaves the context alone without a non-empty accordion', function (string $segment): void {
    ProcessingContext::setShortcodeContent('untouched');

    $result = (new AccordionShortcodeProcessor)->parseFromSegment(
        $segment,
        'default',
        accordionAssetImporter(),
        new BufferedOutput
    );

    expect($result)->toBe([])
        ->and(ProcessingContext::getShortcodeContent())->toBe('untouched');
})->with([
    'no accordion' => ['[section title="A"]one[/section]'],
    'never closed' => ['[accordion][section title="A"]one[/section]'],
    'whitespace only' => ["[accordion] \n [/accordion]"],
    'empty' => ['[accordion][/accordion]'],
]);

/**
 * The flattened HTML for an accordion with the given inner content.
 *
 * @param  mixed  $content  The shortcode's inner content
 * @return string The HTML toTiptapHtml() returns
 */
function accordionFlattened(mixed $content): string
{
    return (new AccordionShortcodeProcessor)->toTiptapHtml(
        ['name' => 'accordion', 'attributes' => [], 'content' => $content],
        'default',
        accordionAssetImporter(),
        new BufferedOutput,
        [],
        [],
        'slug'
    );
}

/**
 * The type, heading level and text of each top-level Bard node.
 *
 * @param  mixed  $nodes  Bard content
 * @return list<string> One "type[level]: text" line per node
 */
function accordionBardOutline(mixed $nodes): array
{
    $outline = [];

    foreach (\is_array($nodes) ? $nodes : [] as $node) {
        if (! \is_array($node)) {
            continue;
        }

        $text = '';
        array_walk_recursive($node, function (mixed $value, int|string $key) use (&$text): void {
            if ($key === 'text' && \is_string($value)) {
                $text .= $value;
            }
        });

        $type = \is_string($node['type'] ?? null) ? $node['type'] : '?';
        $level = \is_array($node['attrs'] ?? null) && \is_int($node['attrs']['level'] ?? null) ? (string) $node['attrs']['level'] : '';
        $outline[] = $type.$level.': '.$text;
    }

    return $outline;
}

describe('toTiptapHtml', function (): void {
    it('turns each section into a heading and a paragraph', function (): void {
        expect(accordionFlattened('[section title="A"]one[/section][section title="B"]two[/section]'))
            ->toBe("</p><h3>A</h3><p>one</p>\n<p></p><h3>B</h3><p>two</p>\n<p></p><p>");
    });

    it('drops the line breaks wpautop leaves at the edges, between sections and inside a body', function (): void {
        $content = "<br />\n[section title=\"A\"]<br />\none<br />\n[/section]<br />\n[section title=\"B\"]two[/section]<br />\n";

        expect(accordionFlattened($content))->toBe("</p><h3>A</h3><p>one</p>\n<p></p><h3>B</h3><p>two</p>\n<p></p><p>");
    });

    it('gives a plain-text section body paragraphs, as WordPress renders it', function (): void {
        // Gutenberg content and wp:shortcode blocks reach this without wpautop
        expect(accordionFlattened("[section title=\"A\"]Line one.\n\nLine two.[/section]"))
            ->toBe("</p><h3>A</h3><p>Line one.</p>\n<p>Line two.</p>\n<p></p><p>")
            ->and(accordionFlattened('[section title="A"]<ul><li>x</li></ul>[/section]'))
            ->toBe("</p><h3>A</h3><ul><li>x</li>\n\n</ul><p></p><p>");
    });

    it('keeps the paragraph boundaries wpautop put inside a section body', function (): void {
        expect(accordionFlattened("[section title=\"A\"]one.</p>\n<p>two.[/section]"))
            ->toBe("</p><h3>A</h3><p>one.</p>\n<p>two.</p><p></p><p>");
    });

    it('keeps a title whole when it holds a bracket, and drops the break after it', function (): void {
        expect(accordionFlattened("[section title=\"Phase [1] results\"]<br />\nBody</p>\n<p>more[/section]"))
            ->toBe("</p><h3>Phase [1] results</h3><p>Body</p>\n<p>more</p><p></p><p>");
    });

    it('strips tags from a title, decodes its entities and escapes the text', function (): void {
        expect(accordionFlattened('[section title="<b>Q</b> &amp; <A>"]x[/section]'))
            ->toBe("</p><h3>Q &amp;</h3><p>x</p>\n<p></p><p>")
            ->and(accordionFlattened('[section title="T &amp;amp; U"]x[/section]'))
            ->toBe("</p><h3>T &amp;amp; U</h3><p>x</p>\n<p></p><p>");
    });

    it('reads a title to its matching quote and escapes it', function (): void {
        // A quote of the other kind, or a decoded &quot;, does not end the title
        expect(accordionFlattened("[section title='Say \"hi\"']x[/section]"))->toBe("</p><h3>Say &quot;hi&quot;</h3><p>x</p>\n<p></p><p>")
            ->and(accordionFlattened('[section title="It\'s"]x[/section]'))->toBe("</p><h3>It&#039;s</h3><p>x</p>\n<p></p><p>")
            ->and(accordionFlattened('[section title="He said "hi" there"]x[/section]'))->toBe("</p><h3>He said &quot;hi&quot; there</h3><p>x</p>\n<p></p><p>")
            ->and(accordionFlattened('[section title="a > b"]x[/section]'))->toBe("</p><h3>a &gt; b</h3><p>x</p>\n<p></p><p>");
    });

    it('keeps the words of a linked title', function (): void {
        // The shape of ortho.uams.edu post 118205
        expect(accordionFlattened("[section title='<a href=\"https://example.test/away\">Visiting Scholarships</a>']x[/section]"))
            ->toBe("</p><h3>Visiting Scholarships</h3><p>x</p>\n<p></p><p>");
    });

    it('ends a title at its closing quote when another attribute follows', function (): void {
        expect(accordionFlattened('[section title="A" class="b c" open=1]x[/section]'))->toBe("</p><h3>A</h3><p>x</p>\n<p></p><p>")
            ->and(accordionFlattened("[section title='A' data-x='y']x[/section]"))->toBe("</p><h3>A</h3><p>x</p>\n<p></p><p>");
    });

    it('does not run a title across another section tag', function (): void {
        // The first tag never closes its quote, so only the second section converts
        expect(accordionFlattened('[section title="A]one[/section][section title="B"]two[/section]'))
            ->toBe("</p><p>[section title=\"A]one[/section]</p><h3>B</h3><p>two</p>\n<p></p><p>");
    });

    it('keeps the body alone for a section whose title is empty or only whitespace', function (string $title): void {
        expect(accordionFlattened('[section title="'.$title.'"]x[/section]'))->toBe("</p><p>x</p>\n<p></p><p>");
    })->with(['empty' => [''], 'whitespace' => ['  ']]);

    it('keeps text outside any section', function (): void {
        expect(accordionFlattened('Lead in. [section title="A"]one[/section] tail'))
            ->toBe("</p><p>Lead in.</p><h3>A</h3><p>one</p>\n<p>tail</p><p>");
    });

    it('keeps the content of an accordion without sections', function (string $content, string $expected): void {
        expect(accordionFlattened($content))->toBe($expected);
    })->with([
        'text' => ["<br />\nLoose text.<br />\n", '</p><p>Loose text.</p><p>'],
        'empty' => ['', '</p><p></p><p>'],
    ]);

    it('reads content that is not a string as empty', function (): void {
        expect(accordionFlattened(['not', 'a', 'string']))->toBe('</p><p></p><p>');
    });

    it('keeps the content as it was when a replacement fails', function (): void {
        // A one-step backtrack limit with the JIT off fails every pattern on this input
        $limit = \ini_get('pcre.backtrack_limit');
        $jit = \ini_get('pcre.jit');
        \ini_set('pcre.backtrack_limit', '1');
        \ini_set('pcre.jit', '0');

        // Longer than the 100-character snippet the warning keeps
        $content = "<br />\n[section title=\"A\"]<br />\n".\str_repeat('word ', 30)."<br />\n[/section]";

        try {
            $result = accordionFlattened($content);
        } finally {
            \ini_set('pcre.backtrack_limit', (string) $limit);
            \ini_set('pcre.jit', (string) $jit);
        }

        expect($result)->toBe($content)
            ->and(ImportWarningManager::getWarnings('content', 'unflattened_accordions'))->toBe([[
                'site' => 'default',
                'page_slug' => 'slug',
                'error' => 'Backtrack limit exhausted',
                'content_snippet' => \substr($content, 0, 100),
                'page_url' => null,
            ]]);
    });

    it('processes the shortcodes inside a section', function (): void {
        // A dropped shortcode renders as nothing, so it shows the nested call ran
        expect(accordionFlattened('[section title="A"]see [subpage-list link=""] here[/section]'))
            ->toBe("</p><h3>A</h3><p>see  here</p>\n<p></p><p>");
    });
});

describe('in Bard content', function (): void {
    it('imports a bare accordion as headings and paragraphs', function (): void {
        $html = "Intro.\n\n[accordion]\n[section title=\"Transcript\"]First line.\n\nSecond line.[/section]\n[section title=\"Notes\"]A note.[/section]\n[/accordion]\n\nAfter.";

        expect(accordionBardOutline(accordionSectionContent($html)))->toBe([
            'paragraph: Intro.',
            'heading3: Transcript',
            'paragraph: First line.',
            'paragraph: Second line.',
            'heading3: Notes',
            'paragraph: A note.',
            'paragraph: After.',
        ]);
    });

    it('gives section bodies paragraphs in content that skips wpautop', function (): void {
        $html = "[accordion]\n[section title=\"T\"]Line one.\n\nLine two.[/section]\n[/accordion]";
        $nodes = TextProcessor::processForBard($html, 'default', accordionAssetImporter(), new BufferedOutput, false, false, [], [], 'slug');

        expect(accordionBardOutline($nodes))->toBe([
            'heading3: T',
            'paragraph: Line one.',
            'paragraph: Line two.',
        ]);
    });

    it('keeps the text after an accordion in a paragraph of its own', function (): void {
        // The news exports' shape: a download link, the accordion, then more text on
        // the same wpautop paragraph
        $html = "<a href=\"https://example.test/episode\">Download</a>\n[accordion]\n[section title=\"Transcript\"]Line one.[/section]\n[/accordion]\nTrailing text.";
        $nodes = accordionSectionContent($html);

        expect(accordionBardOutline($nodes))->toBe([
            'paragraph: Download',
            'heading3: Transcript',
            'paragraph: Line one.',
            'paragraph: Trailing text.',
        ])->and(array_column(\is_array($nodes) ? $nodes : [], 'type'))->each->toBeIn(['paragraph', 'heading']);
    });

    it('leaves no line break behind when an accordion is followed by a heading', function (): void {
        // The news exports' shape: wpautop leaves "[/accordion]<br />" before the
        // next heading, and that break must not become a blank-line paragraph
        $html = "<p>Intro <a href=\"https://example.test/e\">Listen</a></p>\n[accordion]\n[section title=\"Transcript\"]Line one.\n\nLine two.[/section]\n[/accordion]\n<h3>Caused by bacteria</h3>\nText between.\n[accordion]\n[section title=\"More\"]M.[/section]\n[/accordion]\n<h3>End</h3>";
        $nodes = accordionSectionContent($html);

        expect(accordionBardOutline($nodes))->toBe([
            'paragraph: Intro Listen',
            'heading3: Transcript',
            'paragraph: Line one.',
            'paragraph: Line two.',
            'heading3: Caused by bacteria',
            'paragraph: Text between.',
            'heading3: More',
            'paragraph: M.',
            'heading3: End',
        ])->and(\json_encode($nodes))->not->toContain('hardBreak');
    });

    it('puts text outside any section in a paragraph', function (): void {
        $nodes = accordionSectionContent("Before\n[accordion]Lead in.\n[section title=\"T\"]Body.[/section]\ntail\n[/accordion]\nAfter");

        expect(accordionBardOutline($nodes))->toBe([
            'paragraph: Before',
            'paragraph: Lead in.',
            'heading3: T',
            'paragraph: Body.',
            'paragraph: tail',
            'paragraph: After',
        ])->and(\json_encode($nodes))->not->toContain('hardBreak');
    });

    it('leaves no empty paragraph after a post that is only an accordion block', function (): void {
        $html = '<!-- wp:shortcode -->[accordion][section title="T"]Body.[/section][/accordion]<!-- /wp:shortcode -->';
        $nodes = TextProcessor::processForBard($html, 'default', accordionAssetImporter(), new BufferedOutput, false, false, [], [], 'slug');

        // An empty paragraph would show as "paragraph: " in the outline
        expect(accordionBardOutline($nodes))->toBe(['heading3: T', 'paragraph: Body.'])
            ->and(\array_keys($nodes))->toBe([0, 1]);
    });

    it('keeps the content a list when an empty section\'s paragraph is dropped', function (): void {
        $html = '<!-- wp:shortcode -->[accordion][section title="A"][/section][section title="B"]y[/section][/accordion]<!-- /wp:shortcode -->';
        $nodes = TextProcessor::processForBard($html, 'default', accordionAssetImporter(), new BufferedOutput, false, false, [], [], 'slug');

        // The empty section leaves an empty paragraph between its heading and the next
        expect(accordionBardOutline($nodes))->toBe(['heading3: A', 'heading3: B', 'paragraph: y'])
            ->and(\array_keys($nodes))->toBe([0, 1, 2]);
    });

    it('keeps the heading and the section body of an accordion in a wp:shortcode block among other blocks', function (): void {
        // The block loop keeps every node of a shortcode block's HTML, so the section
        // body follows the heading (#156)
        $html = "<!-- wp:paragraph --><p>Intro</p><!-- /wp:paragraph -->\n<!-- wp:shortcode -->\n[accordion][section title=\"Q\"]Answer text.\n\nSecond answer para.[/section][/accordion]\n<!-- /wp:shortcode -->\n<!-- wp:paragraph --><p>Outro</p><!-- /wp:paragraph -->";

        // The block path reads the importer's upload setting, which the
        // constructor-less importer leaves unset
        $nodes = TextProcessor::processForBard($html, 'default', new AssetImporter, new BufferedOutput, false, false, [], [], 'slug');

        expect(accordionBardOutline($nodes))->toBe([
            'paragraph: Intro',
            'heading3: Q',
            'paragraph: Answer text.',
            'paragraph: Second answer para.',
            'paragraph: Outro',
        ]);
    });

    it('imports an attributed accordion the same way', function (): void {
        $html = "[accordion class=\"faq\"]\n[section title=\"Q\"]\n\nAnswer.\n\n[/section]\n[/accordion]";

        expect(accordionBardOutline(accordionSectionContent($html)))->toBe([
            'heading3: Q',
            'paragraph: Answer.',
        ]);
    });
});

describe('a section without a usable title (#167)', function (): void {
    it('flattens an untitled section to its body, with no heading', function (string $content): void {
        expect(accordionFlattened($content))->toBe("</p><p>Body text</p>\n<p></p><p>")
            ->and(ImportWarningManager::getWarnings('content', 'unconverted_accordion_sections'))->toBe([]);
    })->with([
        'no attributes' => ['[section]Body text[/section]'],
        'other attributes only' => ['[section open="true"]Body text[/section]'],
    ]);

    it('flattens an unquoted title to a heading', function (): void {
        expect(accordionFlattened('[section title=Unquoted]Body[/section]'))->toBe("</p><h3>Unquoted</h3><p>Body</p>\n<p></p><p>");
    });

    it('imports untitled and unquoted sections into Bard with no shortcode text', function (): void {
        $nodes = accordionSectionContent("[accordion]\n[section]Body text[/section]\n[section title=Unquoted]Body[/section]\n[/accordion]");

        expect(accordionBardOutline($nodes))->toBe([
            'paragraph: Body text',
            'heading3: Unquoted',
            'paragraph: Body',
        ])->and(\json_encode($nodes))->not->toContain('[section')->not->toContain('[/section]');
    });
});

describe('a [section] tag left unconverted (#167)', function (): void {
    it('records a warning naming the site, the page and the text from the tag on', function (string $content, string $snippet): void {
        ProcessingContext::setWordPressPageLink('https://example.test/page/');

        accordionFlattened($content);

        expect(ImportWarningManager::getWarnings('content', 'unconverted_accordion_sections'))->toBe([[
            'site' => 'default',
            'page_slug' => 'slug',
            'page_link' => 'https://example.test/page/',
            'content_snippet' => $snippet,
            'reason' => 'A [section] tag in this [accordion] was not converted, so it was kept in the content as text.',
        ]]);
    })->with([
        'an opening tag never closed' => ['[section title="A"]body', '[section title="A"]body</p><p>'],
        'a stray closing tag' => ['x [/section] y', '[/section]y</p><p>'],
    ]);

    it('records empty page fields when no page is known', function (): void {
        ProcessingContext::clearWordPressPageLink();

        (new AccordionShortcodeProcessor)->toTiptapHtml(
            ['name' => 'accordion', 'attributes' => [], 'content' => '[section]body'],
            'default',
            accordionAssetImporter(),
            new BufferedOutput
        );

        /** @var array<int, array<string, string>> $warnings */
        $warnings = ImportWarningManager::getWarnings('content', 'unconverted_accordion_sections');

        expect($warnings)->toHaveCount(1)
            ->and($warnings[0]['page_slug'] ?? null)->toBe('')
            ->and($warnings[0]['page_link'] ?? null)->toBe('');
    });

    it('cuts the snippet to 100 bytes without splitting a character', function (): void {
        // "é" is two bytes, so a cut at byte 100 would fall inside one
        accordionFlattened('[section]'.str_repeat('é', 60));

        /** @var array<int, array<string, string>> $warnings */
        $warnings = ImportWarningManager::getWarnings('content', 'unconverted_accordion_sections');
        $snippet = $warnings[0]['content_snippet'] ?? null;

        expect($snippet)->toBe('[section]'.str_repeat('é', 45))
            ->and(mb_check_encoding(\is_string($snippet) ? $snippet : '', 'UTF-8'))->toBeTrue();
    });

    it('keeps the first 100 bytes of the text from the tag on', function (): void {
        accordionFlattened('[section]'.str_repeat('a', 200));

        /** @var array<int, array<string, string>> $warnings */
        $warnings = ImportWarningManager::getWarnings('content', 'unconverted_accordion_sections');

        expect($warnings[0]['content_snippet'] ?? null)->toBe('[section]'.str_repeat('a', 91));
    });

    it('records nothing when every tag converts', function (): void {
        accordionFlattened('[section title="A"]one[/section][section]two[/section]');

        expect(ImportWarningManager::getWarnings('content', 'unconverted_accordion_sections'))->toBe([]);
    });
});

/**
 * Counts the nodes of a Bard tree by what they are and where they sit.
 *
 * @param  mixed  $nodes  Bard content
 * @param  bool  $inListItem  Whether these nodes sit inside a list item
 * @return array{empty: int, emptyInListItem: int, heading: int, headingInListItem: int} The counts
 */
function accordionNodeCounts(mixed $nodes, bool $inListItem = false): array
{
    $counts = ['empty' => 0, 'emptyInListItem' => 0, 'heading' => 0, 'headingInListItem' => 0];

    foreach (\is_array($nodes) ? $nodes : [] as $node) {
        if (! \is_array($node)) {
            continue;
        }

        $type = $node['type'] ?? null;
        $key = match (true) {
            $type === 'heading' => 'heading',
            $type === 'paragraph' && ($node['content'] ?? []) === [] => 'empty',
            default => null,
        };

        if ($key !== null) {
            $counts[$inListItem ? $key.'InListItem' : $key]++;
        }

        // Add what the node holds, which sits in a list item if the node is one
        foreach (accordionNodeCounts($node['content'] ?? [], $inListItem || $type === 'listItem') as $name => $count) {
            $counts[$name] += $count;
        }
    }

    return $counts;
}

describe('in a list, and with sections that leave their lists uneven (#178)', function (): void {
    it('leaves no empty paragraph in the list item an accordion sits in', function (): void {
        $content = accordionSectionContent('<ul><li>Item [accordion][section title="A"]Body[/section][/accordion] tail</li><li>Next</li></ul>');

        expect(accordionNodeCounts($content))->toBe([
            'empty' => 0,
            'emptyInListItem' => 0,
            'heading' => 0,
            'headingInListItem' => 1,
        ]);
    });

    it('drops empty paragraphs from a list item at any depth', function (): void {
        $content = accordionSectionContent('<ul><li>One<p></p><ul><li>Two<p></p>Three</li></ul></li></ul>');

        expect(accordionNodeCounts($content)['emptyInListItem'])->toBe(0)
            ->and(accordionBardOutline($content))->toBe(['bulletList: OneTwoThree']);
    });

    it('filters list items only, and keeps the paragraph an item must start with', function (array $node, array $expected): void {
        // The parser normalizes most of these shapes away, so the filter is called directly
        $filter = new ReflectionMethod(TextProcessor::class, 'withoutEmptyParagraphs');

        expect($filter->invoke(null, [$node]))->toBe([$expected]);
    })->with(function (): array {
        $empty = ['type' => 'paragraph'];
        $text = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'a']]];
        $list = ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [$text]]]];

        return [
            'an empty item' => [
                ['type' => 'listItem', 'content' => [$empty]],
                ['type' => 'listItem', 'content' => [$empty]],
            ],
            'an item that opens on a list' => [
                ['type' => 'listItem', 'content' => [$empty, $list, $empty]],
                ['type' => 'listItem', 'content' => [$empty, $list]],
            ],
            'an item with a paragraph later' => [
                ['type' => 'listItem', 'content' => [$empty, $text, $empty]],
                ['type' => 'listItem', 'content' => [$text]],
            ],
            'an item that starts on a list and has no empty paragraph' => [
                ['type' => 'listItem', 'content' => [$list]],
                ['type' => 'listItem', 'content' => [$list]],
            ],
            'an item nested in a list' => [
                ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [$text, $empty]]]],
                ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [$text]]]],
            ],
            'a node that is not a list item' => [
                ['type' => 'blockquote', 'content' => [$text, $empty]],
                ['type' => 'blockquote', 'content' => [$text, $empty]],
            ],
        ];
    });

    it('keeps a list a section leaves open out of the sections after it', function (): void {
        $content = accordionSectionContent('[accordion][section title="A"]<ul><li>Open[/section][section title="B"]Next[/section][/accordion]');

        expect(accordionBardOutline($content))->toBe([
            'heading3: A',
            'bulletList: Open',
            'heading3: B',
            'paragraph: Next',
        ]);
    });

    it('closes what a section leaves open and drops what it never opened', function (string $body, string $expected): void {
        // The leading paragraph tag keeps wpautop off the body
        expect(accordionFlattened('[section title="A"]<p>'.$body.'[/section]'))
            ->toBe('</p><h3>A</h3><p><p>'.$expected.'</p><p></p><p>');
    })->with([
        'balanced' => ['<ul><li>a</li></ul>', '<ul><li>a</li></ul>'],
        'an open list' => ['<ul><li>a', '<ul><li>a</li></ul>'],
        'an open nested list' => ['<ol><li><ul><li>a', '<ol><li><ul><li>a</li></ul></li></ol>'],
        'a stray close' => ['a</ul>b</li>', 'ab'],
        'a list closed around an open item' => ['<ul><li>a</ul>', '<ul><li>a</li></ul>'],
        'an item closed around an open list' => ['<ul><li><ol><li>a</li></li></ul>', '<ul><li><ol><li>a</li></ol></li></ul>'],
        'an item left open before the next' => ['<ul><li>a<li>b</li></ul>', '<ul><li>a</li><li>b</li></ul>'],
        'attributes and case' => ['<UL class="x"><LI>a</LI></UL>', '<UL class="x"><LI>a</LI></UL>'],
        'a tag that only starts like one' => ['<link></ul>', '<link>'],
    ]);

    it('leaves a balanced body alone, reading no list tag where there is none', function (string $body): void {
        // The closes shown here close nothing, so the real ones have to stay
        expect(accordionFlattened('[section title="A"]<p>'.$body.'[/section]'))
            ->toBe('</p><h3>A</h3><p><p>'.$body.'</p><p></p><p>');
    })->with([
        'a comment' => ['<ul><li><!-- </li></ul> -->a</li></ul>'],
        'an empty comment' => ['<ul><li><!-->a</li></ul>'],
        'a comment with dashes' => ['<ul><li><!-- a - b -- c </ul> -->d</li></ul>'],
        'another tag\'s attribute' => ['<ul><li><a title="a</ul>b" href="x">c</a></li></ul>'],
        'single quotes' => ["<ul><li><a title='</li>'>c</a></li></ul>"],
        'a list tag\'s own attribute' => ['<ul title="</ul>"><li data-x=\'<li>\'>a</li></ul>'],
        'a stray less-than sign' => ['<ul><li>a<b and c</li></ul>'],
    ]);

    it('balances a section with a comment too long for a lazy match', function (): void {
        $body = '<ul><li>a<!-- '.\str_repeat('x', 2000000).' -->';

        expect(accordionFlattened('[section title="A"]<p>'.$body.'[/section]'))
            ->toEndWith('--></li></ul></p><p></p><p>');
    });

    it('keeps the body and names the page when the balancing pattern fails', function (): void {
        // A one-step backtrack limit with the JIT off fails the pattern
        $limit = \ini_get('pcre.backtrack_limit');
        $jit = \ini_get('pcre.jit');
        \ini_set('pcre.backtrack_limit', '1');
        \ini_set('pcre.jit', '0');

        // Longer than the 100-character snippet the warning keeps
        $body = '<ul><li>'.\str_repeat('word ', 30);

        try {
            $result = (new ReflectionMethod(AccordionShortcodeProcessor::class, 'balanceLists'))
                ->invoke(null, $body, 'default', 'slug');
        } finally {
            \ini_set('pcre.backtrack_limit', (string) $limit);
            \ini_set('pcre.jit', (string) $jit);
        }

        expect($result)->toBe($body)
            ->and(ImportWarningManager::getWarnings('content', 'unbalanced_accordion_sections'))->toBe([[
                'site' => 'default',
                'page_slug' => 'slug',
                'error' => 'Backtrack limit exhausted',
                'content_snippet' => \substr($body, 0, 100),
                'page_url' => null,
            ]]);
    });

    it('opens no leading paragraph for a break before the first section', function (string $break): void {
        expect(accordionFlattened($break.'[section title="A"]one[/section]'))
            ->toStartWith('</p><h3>A</h3>');
    })->with([
        'an entity' => ['&nbsp;'],
        'the character' => ["\u{00A0}"],
        'several, among whitespace' => ["&nbsp; \u{00A0}<br />\n"],
    ]);

    it('still opens a leading paragraph for text before the first section', function (): void {
        expect(accordionFlattened('&nbsp;Intro[section title="A"]one[/section]'))
            ->toStartWith('</p><p>Intro</p><h3>A</h3>');
    });

    it('keeps a non-breaking space inside a section body', function (): void {
        expect(accordionFlattened('[section title="A"]<p>one&nbsp;two</p>[/section]'))
            ->toContain('one&nbsp;two');
    });

    it('converts an accordion too long for the JIT stack', function (): void {
        $body = \str_repeat('A long paragraph of section text. ', 3000);
        $content = accordionSectionContent('[accordion][section title="A"]'.$body.'[/section][/accordion]');

        expect(accordionNodeCounts($content)['heading'])->toBe(1)
            ->and(\json_encode($content))->not->toContain('[section');
    });

    it('imports the nicuguidebook Medications page cleanly', function (): void {
        $export = ExportsCorpus::path('WordPress/wordpress-export_inside-uams-edu_nicuguidebook.xml');

        if ($export === null) {
            Assert::markTestSkipped(ExportsCorpus::SKIP_MESSAGE);
        }

        // A clone without this export is a broken clone, not an absent corpus
        expect($export)->toBeFile();

        $xml = \simplexml_load_file($export, SimpleXMLElement::class, LIBXML_PARSEHUGE | LIBXML_NOCDATA);
        expect($xml)->toBeInstanceOf(SimpleXMLElement::class);

        /** @var SimpleXMLElement $xml */
        $xml->registerXPathNamespace('wp', 'http://wordpress.org/export/1.2/');
        $items = $xml->xpath('//item[wp:post_id="610"]');
        expect($items)->toHaveCount(1);

        /** @var array<int, SimpleXMLElement> $items */
        $source = (string) $items[0]->children('content', true)->encoded;

        // Run under `php -d pcre.jit=0` as well: ini_set() does not reach the JIT here
        $content = accordionSectionContent($source);

        expect(accordionNodeCounts($content))->toBe([
            'empty' => 0,
            'emptyInListItem' => 0,
            'heading' => 119,
            'headingInListItem' => 0,
        ])->and(\json_encode($content))->not->toContain('[section');
    });
});

it('imports a plain table in an accordion section as a table set', function (string $content): void {
    // #327: a section body is read as ordinary content whichever way the accordion
    // reaches Bard, so the table neither flattens nor leaves bare text at the top level
    $nodes = accordionSectionContent($content);

    expect(is_array($nodes) ? array_column($nodes, 'type') : null)->toBe(str_contains($content, '[section') ? ['heading', 'set'] : ['set'])
        ->and(data_get($nodes, (str_contains($content, '[section') ? '1' : '0').'.attrs.values.table_data'))->toBe([
            ['cells' => ['Weight<br>(kg)', 'Dose']],
            ['cells' => ['1', '2']],
        ]);
})->with([
    'a section body' => ['<table><tbody><tr><th>Weight<br />(kg)</th><th>Dose</th></tr><tr><td>1</td><td>2</td></tr></tbody></table>'],
    'a whole accordion in content' => ['[accordion][section title="Doses"]<table><tbody><tr><th>Weight<br />(kg)</th><th>Dose</th></tr><tr><td>1</td><td>2</td></tr></tbody></table>[/section][/accordion]'],
]);
