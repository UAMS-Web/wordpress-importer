<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Tests\TestCase;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;

uses(TestCase::class);

beforeEach(function (): void {
    $this->contentProcessor = new ContentProcessor;

    // Override items so getItems('tablepress_table') returns an empty set without
    // needing a real WXR file on disk.
    $this->xmlParser = new XmlParser;
    $this->xmlParser->setItems([]);
});

afterEach(function (): void {
    // Clear the shared page link so it never leaks between tests.
    ProcessingContext::clearWordPressPageLink();

    Mockery::close();
});

/**
 * Regression tests for ContentProcessor::linkTableEntries table-id accounting.
 *
 * Covers issue wordpress-importer-archive#332: a bare `[table id=N /]` shortcode on a landing_page entry is
 * emitted as a self-contained `uamsweb_block_table` page_builder block (which already
 * carries its own table_id), not as a Bard `table`/`collection` set. The linker must
 * count that block as consuming a table id so it does not falsely report the id as
 * unlinked, and any genuine warning must name the entry actually being linked rather
 * than the stale last-set ProcessingContext page link.
 *
 * @command  php artisan test vendor/uams-web/aether-wordpress-importer/tests/Feature/LinkTableEntriesPageBuilderTest.php
 */
it('does not warn about unlinked table ids for a self-contained page builder table block', function (): void {
    // Spy on the log so we can assert the unlinked-IDs warning never fires.
    $logSpy = Log::spy();

    // A genuine page link is set during creation; even with it present the warning
    // must not fire, because the table id is accounted for by the block.
    ProcessingContext::setWordPressPageLink(
        'https://inside.uams.edu/clinicaleducation/2022/01/20/untitled-reusable-block-2/'
    );

    // The page_builder block emitted by TablePressShortcodeProcessor::toPageBuilderBlock()
    // for a bare `[table id=52 /]` shortcode — self-contained, carries table_id directly.
    $pageBuilderContent = [
        [
            'id' => 'row-id-abc',
            'type' => 'uamsweb_block_table',
            'enabled' => true,
            'table_id' => '52',
            'align' => 'aligncenter',
        ],
    ];

    $result = $this->contentProcessor->linkTableEntries(
        [],                       // bardContent
        $pageBuilderContent,      // pageBuilderContent
        'inside_clinical_education',
        ['52'],                   // tableIds counted for this page
        ['blueprint' => 'landing_page'],
        'pages',
        $this->xmlParser
    );

    expect($result)->toHaveKey('pageBuilderContent');

    $linkedPageBuilder = $result['pageBuilderContent'];
    expect($linkedPageBuilder)->toBeArray();

    if (! is_array($linkedPageBuilder)) {
        throw new RuntimeException('Expected linked pageBuilderContent to be an array.');
    }

    expect($linkedPageBuilder)->toHaveKey(0);

    $block = $linkedPageBuilder[0];
    expect($block)->toBeArray();

    if (! is_array($block)) {
        throw new RuntimeException('Expected the first page builder block to be an array.');
    }

    // The block is returned untouched — its table_id is preserved.
    expect($block['table_id'])->toBe('52');
    expect($block['type'])->toBe('uamsweb_block_table');

    // The false-positive warning must not fire: the block consumed the table id.
    $logSpy->shouldNotHaveReceived('warning', [
        Mockery::pattern('/Table IDs not linked to sets/'),
        Mockery::any(),
    ]);
});

it('names the current entry in the warning for a genuinely unlinked table id', function (): void {
    // Spy on the log so we can inspect the warning's context.
    $logSpy = Log::spy();

    // The deferred loop sets the link to the entry actually being linked. This is the
    // page that really references table 52 (EpicCare Ambulatory Tip Sheets, WP 1149).
    ProcessingContext::setWordPressPageLink(
        'https://inside.uams.edu/clinicaleducation/uconnect/epiccare-ambulatory/tip-sheets/'
    );

    // No set and no page_builder block consume the id, so it stays unlinked and the
    // warning fires — exercising the entry_url path.
    $this->contentProcessor->linkTableEntries(
        [],   // bardContent — nothing to link
        [],   // pageBuilderContent — nothing to link
        'inside_clinical_education',
        ['52'],
        ['blueprint' => 'landing_page'],
        'pages',
        $this->xmlParser
    );

    // The warning must fire and carry the entry_url of the entry being linked.
    $logSpy->shouldHaveReceived('warning', [
        Mockery::on(
            fn (mixed $message): bool => \is_string($message)
                && \str_contains($message, 'Table IDs not linked to sets')
        ),
        Mockery::on(
            fn (mixed $context): bool => \is_array($context)
                && ($context['entry_url'] ?? null)
                    === 'https://inside.uams.edu/clinicaleducation/uconnect/epiccare-ambulatory/tip-sheets/'
                && ($context['unlinkedTableIds'] ?? null) === ['52']
        ),
    ]);
});
