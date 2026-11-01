<?php

declare(strict_types=1);

/**
 * Tests for the default toTiptapHtml() that most shortcode processors share: it
 * records the shortcode's context, hands the attributes to parse(), and renders
 * the parsed set's values as Tiptap set markup (#1314).
 *
 * @command  php vendor/bin/pest tests/Unit/DefaultTiptapHtmlTraitTest.php
 */

use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\ShortcodeProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\Traits\DefaultTiptapHtmlTrait;

/**
 * A processor using the default trait, whose parse() returns a fixed set and
 * records the arguments it was called with.
 */
final class DefaultTiptapHtmlTraitTestProcessor
{
    use DefaultTiptapHtmlTrait;

    /**
     * The arguments of the last parse() call, or null before any call.
     *
     * @var array<int, mixed>|null
     */
    public ?array $parseArguments = null;

    /**
     * @param  array<int|string, mixed>|null  $set  What parse() returns
     */
    public function __construct(
        private readonly ?array $set
    ) {}

    /**
     * Record the call and return the configured set.
     *
     * @param  array<int|string, mixed>  $attributes  The shortcode attributes
     * @param  string  $site  The site handle
     * @param  AssetImporter  $assetImporter  The asset importer
     * @param  OutputInterface  $output  Console output
     * @param  array<int|string, mixed>  $terms  Term objects
     * @param  ?string  $pageSlug  The page slug
     * @return array<int|string, mixed>|null The configured set
     */
    public function parse(
        array $attributes,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $terms = [],
        ?string $pageSlug = null
    ): ?array {
        $this->parseArguments = [$attributes, $site, $assetImporter, $output, $terms, $pageSlug];

        return $this->set;
    }
}

/**
 * Build an AssetImporter without its collaborators; parse() never uses it here.
 */
function defaultTiptapHtmlTraitAssetImporter(): AssetImporter
{
    return (new ReflectionClass(AssetImporter::class))->newInstanceWithoutConstructor();
}

beforeEach(function (): void {
    ProcessingContext::clearAttachments();
    ProcessingContext::clearShortcodeContent();
});

afterEach(function (): void {
    ProcessingContext::clearAttachments();
    ProcessingContext::clearShortcodeContent();
});

it('passes the attributes and context to parse(), and records the attachments and content', function (): void {
    $processor = new DefaultTiptapHtmlTraitTestProcessor(['type' => 'accordion']);
    $assetImporter = defaultTiptapHtmlTraitAssetImporter();
    $output = new BufferedOutput;

    $processor->toTiptapHtml(
        ['attributes' => ['id' => '4'], 'content' => 'Inner'],
        'default',
        $assetImporter,
        $output,
        ['attachment'],
        ['term'],
        'the-page'
    );

    expect($processor->parseArguments)->toBe([['id' => '4'], 'default', $assetImporter, $output, ['term'], 'the-page'])
        ->and(ProcessingContext::getAttachments())->toBe(['attachment'])
        ->and(ProcessingContext::getShortcodeContent())->toBe('Inner');
});

it('passes no attributes to parse() when the shortcode carries none usable', function (mixed $attributes): void {
    $processor = new DefaultTiptapHtmlTraitTestProcessor(['type' => 'accordion']);
    $shortcode = $attributes === 'absent' ? [] : ['attributes' => $attributes];

    $processor->toTiptapHtml($shortcode, 'default', defaultTiptapHtmlTraitAssetImporter(), new BufferedOutput);

    expect($processor->parseArguments[0] ?? 'not called')->toBe([]);
})->with([
    'absent' => ['absent'],
    'not an array' => ['id=4'],
]);

it('records no shortcode content when the content is not a string', function (): void {
    ProcessingContext::setShortcodeContent('earlier');

    (new DefaultTiptapHtmlTraitTestProcessor(['type' => 'accordion']))
        ->toTiptapHtml(['content' => ['x']], 'default', defaultTiptapHtmlTraitAssetImporter(), new BufferedOutput);

    expect(ProcessingContext::getShortcodeContent())->toBe('earlier');
});

it('renders nothing when parse() returns an empty set', function (): void {
    expect((new DefaultTiptapHtmlTraitTestProcessor([]))
        ->toTiptapHtml(['content' => 'Inner'], 'default', defaultTiptapHtmlTraitAssetImporter(), new BufferedOutput))
        ->toBe('');
});

it('renders the values nested under attrs.values, typed by their own type', function (): void {
    $values = ['type' => 'accordion', 'title' => 'FAQ'];
    $set = ['type' => 'set', 'attrs' => ['values' => $values]];

    expect((new DefaultTiptapHtmlTraitTestProcessor($set))
        ->toTiptapHtml(['content' => 'Inner'], 'default', defaultTiptapHtmlTraitAssetImporter(), new BufferedOutput))
        ->toBe(ShortcodeProcessor::generateTiptapHtml($values, 'accordion', 'Inner'));
});

it('renders a flat set as its own values, without reading a key it lacks', function (array $set): void {
    // Warnings become exceptions, so a read of an absent key fails rather than warns
    set_error_handler(static function (int $severity, string $message): never {
        throw new ErrorException($message, 0, $severity);
    });

    try {
        $html = (new DefaultTiptapHtmlTraitTestProcessor($set))
            ->toTiptapHtml(['content' => 'Inner'], 'default', defaultTiptapHtmlTraitAssetImporter(), new BufferedOutput);
    } finally {
        restore_error_handler();
    }

    expect($html)->toBe(ShortcodeProcessor::generateTiptapHtml($set, 'gallery', 'Inner'));
})->with([
    'no attrs' => [['type' => 'gallery', 'id' => '3']],
    'attrs that are not an array' => [['type' => 'gallery', 'attrs' => 'x']],
    'attrs without values' => [['type' => 'gallery', 'attrs' => ['other' => 1]]],
    'attrs whose values are not an array' => [['type' => 'gallery', 'attrs' => ['values' => 'x']]],
]);

it('renders an unknown set type, with no content, when neither is a string', function (): void {
    $set = ['type' => ['gallery'], 'id' => '3'];

    expect((new DefaultTiptapHtmlTraitTestProcessor($set))
        ->toTiptapHtml(['content' => 5], 'default', defaultTiptapHtmlTraitAssetImporter(), new BufferedOutput))
        ->toBe(ShortcodeProcessor::generateTiptapHtml($set, 'unknown', ''));
});

it('renders an unknown, empty set when parse() returns null', function (): void {
    expect((new DefaultTiptapHtmlTraitTestProcessor(null))
        ->toTiptapHtml([], 'default', defaultTiptapHtmlTraitAssetImporter(), new BufferedOutput))
        ->toBe(ShortcodeProcessor::generateTiptapHtml([], 'unknown', ''));
});
