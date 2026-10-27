<?php

declare(strict_types=1);

/**
 * Tests for AccordionShortcodeProcessor, which turns `[accordion]` and
 * `[section title="..."]` shortcodes into accordion section data.
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

use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\AccordionShortcodeProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\TextProcessor;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

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

it('ignores sections without a title attribute and returns only the type with no sections', function (?string $content): void {
    if ($content !== null) {
        ProcessingContext::setShortcodeContent($content);
    }

    $result = (new AccordionShortcodeProcessor)->parse([], 'default', accordionAssetImporter(), new BufferedOutput);

    expect($result)->toBe(['type' => 'accordion']);
})->with([
    'no shortcode content in context' => [null],
    'no sections' => ['just text'],
    'section without a title' => ['[section]body[/section]'],
    'section never closed' => ['[section title="A"]body'],
]);

it('accepts an empty title', function (): void {
    ProcessingContext::setShortcodeContent('[section title=""]body[/section]');

    $result = (new AccordionShortcodeProcessor)->parse([], 'default', accordionAssetImporter(), new BufferedOutput);

    expect($result)->toBe([
        'type' => 'accordion',
        ['title' => '', 'content' => [accordionParagraph('body')]],
    ]);
});

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
            ->toBe("</p><h3>A</h3><p>one</p>\n<h3>B</h3><p>two</p>\n<p>");
    });

    it('drops the line breaks wpautop leaves at the edges, between sections and inside a body', function (): void {
        $content = "<br />\n[section title=\"A\"]<br />\none<br />\n[/section]<br />\n[section title=\"B\"]two[/section]<br />\n";

        expect(accordionFlattened($content))->toBe("</p><h3>A</h3><p>one</p>\n<h3>B</h3><p>two</p>\n<p>");
    });

    it('gives a plain-text section body paragraphs, as WordPress renders it', function (): void {
        // Gutenberg content and wp:shortcode blocks reach this without wpautop
        expect(accordionFlattened("[section title=\"A\"]Line one.\n\nLine two.[/section]"))
            ->toBe("</p><h3>A</h3><p>Line one.</p>\n<p>Line two.</p>\n<p>")
            ->and(accordionFlattened('[section title="A"]<ul><li>x</li></ul>[/section]'))
            ->toBe("</p><h3>A</h3><ul><li>x</li>\n\n</ul><p>");
    });

    it('keeps the paragraph boundaries wpautop put inside a section body', function (): void {
        expect(accordionFlattened("[section title=\"A\"]one.</p>\n<p>two.[/section]"))
            ->toBe("</p><h3>A</h3><p>one.</p>\n<p>two.</p><p>");
    });

    it('keeps a title whole when it holds a bracket, and drops the break after it', function (): void {
        expect(accordionFlattened("[section title=\"Phase [1] results\"]<br />\nBody</p>\n<p>more[/section]"))
            ->toBe("</p><h3>Phase [1] results</h3><p>Body</p>\n<p>more</p><p>");
    });

    it('strips tags from a title, decodes its entities and escapes the text', function (): void {
        expect(accordionFlattened('[section title="<b>Q</b> &amp; <A>"]x[/section]'))
            ->toBe("</p><h3>Q &amp;</h3><p>x</p>\n<p>")
            ->and(accordionFlattened('[section title="T &amp;amp; U"]x[/section]'))
            ->toBe("</p><h3>T &amp;amp; U</h3><p>x</p>\n<p>");
    });

    it('reads a title to its matching quote and escapes it', function (): void {
        // A quote of the other kind, or a decoded &quot;, does not end the title
        expect(accordionFlattened("[section title='Say \"hi\"']x[/section]"))->toBe("</p><h3>Say &quot;hi&quot;</h3><p>x</p>\n<p>")
            ->and(accordionFlattened('[section title="It\'s"]x[/section]'))->toBe("</p><h3>It&#039;s</h3><p>x</p>\n<p>")
            ->and(accordionFlattened('[section title="He said "hi" there"]x[/section]'))->toBe("</p><h3>He said &quot;hi&quot; there</h3><p>x</p>\n<p>")
            ->and(accordionFlattened('[section title="a > b"]x[/section]'))->toBe("</p><h3>a &gt; b</h3><p>x</p>\n<p>");
    });

    it('keeps the words of a linked title', function (): void {
        // The shape of ortho.uams.edu post 118205
        expect(accordionFlattened("[section title='<a href=\"https://example.test/away\">Visiting Scholarships</a>']x[/section]"))
            ->toBe("</p><h3>Visiting Scholarships</h3><p>x</p>\n<p>");
    });

    it('ends a title at its closing quote when another attribute follows', function (): void {
        expect(accordionFlattened('[section title="A" class="b c" open=1]x[/section]'))->toBe("</p><h3>A</h3><p>x</p>\n<p>")
            ->and(accordionFlattened("[section title='A' data-x='y']x[/section]"))->toBe("</p><h3>A</h3><p>x</p>\n<p>");
    });

    it('does not run a title across another section tag', function (): void {
        // The first tag never closes its quote, so only the second section converts
        expect(accordionFlattened('[section title="A]one[/section][section title="B"]two[/section]'))
            ->toBe("</p>[section title=\"A]one[/section]<h3>B</h3><p>two</p>\n<p>");
    });

    it('keeps the body alone for a section whose title is empty or only whitespace', function (string $title): void {
        expect(accordionFlattened('[section title="'.$title.'"]x[/section]'))->toBe("</p><p>x</p>\n<p>");
    })->with(['empty' => [''], 'whitespace' => ['  ']]);

    it('keeps text outside any section', function (): void {
        expect(accordionFlattened('Lead in. [section title="A"]one[/section] tail'))
            ->toBe("</p>Lead in.<h3>A</h3><p>one</p>\ntail<p>");
    });

    it('keeps the content of an accordion without sections', function (string $content, string $expected): void {
        expect(accordionFlattened($content))->toBe($expected);
    })->with([
        'text' => ["<br />\nLoose text.<br />\n", '</p>Loose text.<p>'],
        'empty' => ['', '</p><p>'],
    ]);

    it('reads content that is not a string as empty', function (): void {
        expect(accordionFlattened(['not', 'a', 'string']))->toBe('</p><p>');
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
            ->toBe("</p><h3>A</h3><p>see  here</p>\n<p>");
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

    it('imports an attributed accordion the same way', function (): void {
        $html = "[accordion class=\"faq\"]\n[section title=\"Q\"]\n\nAnswer.\n\n[/section]\n[/accordion]";

        expect(accordionBardOutline(accordionSectionContent($html)))->toBe([
            'heading3: Q',
            'paragraph: Answer.',
        ]);
    });
});
