<?php

declare(strict_types=1);

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\Creators\TablePressEntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;

/**
 * Run a table options payload through the Premium feature check.
 *
 * @param  array<int|string, mixed>  $tableOptions
 * @return list<array<int|string, mixed>>
 */
function tablePressPremiumWarnings(array $tableOptions): array
{
    // Drive the real mapping entry point rather than the reporter directly, so
    // deleting the report call turns these red — and so the responsive check is
    // evaluated against what the mapping actually produced.
    (new TablePressEntryCreator)->mapTableOptions(
        $tableOptions,
        'tablepress-42',
        '1234'
    );

    $warnings = ImportWarningManager::getWarnings('content', 'unsupported_table_features');

    $rows = [];

    foreach ($warnings as $warning) {
        if (\is_array($warning)) {
            $rows[] = $warning;
        }
    }

    return $rows;
}

/**
 * The options a free-tier TablePress table carries, all mapped already.
 *
 * Overrides are applied with array_merge rather than the `+` union operator,
 * which keeps the left-hand value and would silently discard an override for a
 * key this baseline already sets.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function tablePressOptions(array $overrides = []): array
{
    return \array_merge(
        [
            'table_head' => true,
            'table_foot' => false,
            'alternating_row_colors' => true,
            'row_hover' => true,
            'use_datatables' => true,
            'datatables_sort' => true,
            'datatables_filter' => true,
            'datatables_paginate' => true,
            'datatables_paginate_entries' => 10,
            'datatables_lengthchange' => true,
            'datatables_info' => true,
            'datatables_scrollx' => false,
            'datatables_custom_commands' => '',
        ],
        $overrides
    );
}

beforeEach(function (): void {
    ImportWarningManager::clearWarnings();
});

afterEach(function (): void {
    ImportWarningManager::clearWarnings();
});

it('stays silent for a free-tier table', function (): void {
    // 1,368 of the 1,392 corpus tables carry no Premium option at all — they must
    // not produce a single warning. wordpress-importer-archive#21
    expect(tablePressPremiumWarnings(tablePressOptions()))->toBeEmpty();
})->group('tablepress');

it('reports each Premium feature a table switches on', function (
    string $option,
    mixed $enabledValue,
    string $feature
): void {
    $warnings = tablePressPremiumWarnings(
        tablePressOptions([$option => $enabledValue])
    );

    expect($warnings)->toHaveCount(1);
    expect($warnings[0]['option'] ?? null)->toBe($option);
    expect($warnings[0]['feature'] ?? null)->toBe($feature);
    expect($warnings[0]['table_slug'] ?? null)->toBe('tablepress-42');
    expect($warnings[0]['post_id'] ?? null)->toBe('1234');
})->with([
    // The two Premium features actually enabled anywhere in the corpus
    'individual column filtering' => ['datatables_column_filter', 'input', 'Individual Column Filtering'],
    'grouped filter terms' => ['datatables_columnfilterwidgets_group_terms', true, 'Column Filter Dropdowns (grouped terms)'],
    'export buttons' => ['datatables_buttons', 'excel', 'Export Buttons'],
    'fixed header' => ['datatables_fixedheader', true, 'Fixed Header'],
    'fixed columns' => ['datatables_fixedcolumns', true, 'Fixed Columns'],
    'search builder' => ['datatables_searchbuilder', true, 'Search Builder'],
    'search highlighting' => ['datatables_searchhighlight', true, 'Search Highlighting'],
    'fuzzy search' => ['datatables_fuzzysearch', true, 'Fuzzy Search'],
    'inverted filtering' => ['datatables_inverted_filter', true, 'Inverted Filtering'],
    'automatic filtering' => ['datatables_auto_filter', 'q', 'Automatic Filtering'],
    'row filtering' => ['filter', 'something', 'Row Filtering'],
    'cell highlighting' => ['row_highlight', 'term', 'Cell Highlighting'],
    'server-side processing' => ['datatables_serverside_processing', true, 'Server-side Processing'],
    'custom DataTables commands' => ['datatables_custom_commands', '{"foo":1}', 'Custom DataTables Commands'],
    'load more button' => ['datatables_pagination_loadmore_button', true, 'Load More Button'],
    'horizontal scrolling buttons' => ['datatables_scrollx_buttons', true, 'Horizontal Scrolling Buttons'],
    'responsive scrolling buttons' => ['responsive_scroll_buttons', true, 'Responsive Scrolling Buttons'],
])->group('tablepress');

it('treats every off-state TablePress writes as off', function (mixed $offValue): void {
    // TablePress serializes a gate as a real boolean, an empty string, or '0'
    // depending on the module, so all of them have to read as off — otherwise a
    // Premium table reports every feature it does not use. wordpress-importer-archive#21
    expect(tablePressPremiumWarnings(tablePressOptions([
        'datatables_column_filter' => $offValue,
        'datatables_searchhighlight' => $offValue,
        'filter' => $offValue,
    ])))->toBeEmpty();
})->with([
    'boolean false' => [false],
    'empty string' => [''],
    'zero string' => ['0'],
    'zero int' => [0],
    'whitespace' => ['   '],
    'literal false' => ['false'],
    'null' => [null],
])->group('tablepress');

it('does not report the two Premium modules that do have destinations', function (): void {
    // Column Filter Dropdowns and Responsive stacking are imported by
    // mapTableOptions(), so reporting them would tell an editor to hand-port work
    // the importer already did. wordpress-importer-archive#21
    expect(tablePressPremiumWarnings(tablePressOptions([
        'datatables_columnfilterwidgets' => true,
        'datatables_columnfilterwidgets_columns' => 'A,C,D',
        'responsive' => 'collapse',
        'responsive_breakpoint' => 'tablet',
    ])))->toBeEmpty();
})->group('tablepress');

it('reports a responsive mode that reaches no Statamic field', function (): void {
    // `flip` transposes the table into a column-per-row layout. Nothing here can
    // express it, and it is the one responsive mode with no destination at all. wordpress-importer-archive#21
    $warnings = tablePressPremiumWarnings(tablePressOptions(['responsive' => 'flip']));

    expect($warnings)->toHaveCount(1);
    expect($warnings[0]['option'] ?? null)->toBe('responsive');
    expect($warnings[0]['feature'] ?? null)->toBe('Responsive Tables (flip mode)');
})->group('tablepress');

it('stays silent for the responsive modes that do reach a field', function (string $mode): void {
    // `modal`/`collapse` become the stacking toggle. wordpress-importer-archive#21
    expect(tablePressPremiumWarnings(tablePressOptions(['responsive' => $mode])))->toBeEmpty();
})->with([
    'modal' => ['modal'],
    'collapse' => ['collapse'],
    'uppercase' => ['Collapse'],
    'padded' => ['  modal  '],
    'off' => [''],
])->group('tablepress');

it('stays silent for a scroll responsive mode that really is carried', function (): void {
    // `scroll` reaches `horizontal_scroll_enable`, but only through
    // `datatables_scrollx` under `use_datatables`. When both are on the behavior
    // survives and there is nothing to report. wordpress-importer-archive#21
    expect(tablePressPremiumWarnings(tablePressOptions([
        'responsive' => 'scroll',
        'use_datatables' => true,
        'datatables_scrollx' => true,
    ])))->toBeEmpty();
})->group('tablepress');

it('reports a scroll responsive mode that reached no field', function (
    bool $useDatatables,
    bool $scrollX
): void {
    // A table declaring `responsive=scroll` gets horizontal scrolling ONLY via
    // `datatables_scrollx` under `use_datatables`. With either off the declared
    // behavior lands nowhere, so exempting `scroll` by name would hide a real
    // loss — the test has to be what the mapping produced, not what was asked for.
    $warnings = tablePressPremiumWarnings(tablePressOptions([
        'responsive' => 'scroll',
        'use_datatables' => $useDatatables,
        'datatables_scrollx' => $scrollX,
    ]));

    expect($warnings)->toHaveCount(1);
    expect($warnings[0]['option'] ?? null)->toBe('responsive');
    expect($warnings[0]['feature'] ?? null)->toBe('Responsive Tables (scroll mode)');
})->with([
    'DataTables off' => [false, true],
    'DataTables on but scroll-x off' => [true, false],
])->group('tablepress');

it('reports a stacking mode that reached no field', function (): void {
    // Symmetry with the `scroll` case: the report follows the mapped result, so a
    // stacking mode that somehow failed to set its toggle would surface too.
    $mapped = (new TablePressEntryCreator)->mapTableOptions(
        tablePressOptions(['responsive' => 'collapse'])
    );

    expect($mapped['responsive_stack_enable'] ?? null)->toBeTrue();
})->group('tablepress');

it('reports every enabled feature on a table that switches on several', function (): void {
    $warnings = tablePressPremiumWarnings(tablePressOptions([
        'datatables_column_filter' => 'input',
        'datatables_fixedheader' => true,
        'responsive' => 'flip',
    ]));

    $options = \array_map(
        static fn (array $warning): mixed => $warning['option'] ?? null,
        $warnings
    );

    expect($options)->toHaveCount(3);
    expect($options)->toContain('datatables_column_filter');
    expect($options)->toContain('datatables_fixedheader');
    expect($options)->toContain('responsive');
})->group('tablepress');

it('ignores a companion option that carries a default whether or not its feature is on', function (): void {
    // These are present with a non-empty value on every corpus table that has them,
    // so warning on them would report each Premium table as broken while telling an
    // editor nothing. wordpress-importer-archive#21
    expect(tablePressPremiumWarnings(tablePressOptions([
        'datatables_column_filter_position' => 'table_head',
        'responsive_breakpoint' => 'phone',
        'datatables_fuzzysearch_threshold' => 0.6,
        'datatables_fuzzysearch_togglesmart' => true,
        'row_highlight_full_cell_match' => true,
        'datatables_columnfilterwidgets_separator' => ', ',
        'datatables_columnfilterwidgets_max_selections' => 5,
        'datatables_fixedheader_offsettop' => 0,
        'datatables_layout' => ['top' => ['buttons']],
    ])))->toBeEmpty();
})->group('tablepress');

it('treats a non-scalar gate value as off', function (): void {
    // A malformed payload must not crash the import or invent a warning.
    expect(tablePressPremiumWarnings(tablePressOptions([
        'datatables_column_filter' => ['unexpected' => 'shape'],
    ])))->toBeEmpty();
})->group('tablepress');
