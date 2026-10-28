<?php

declare(strict_types=1);

/**
 * Tests the `_tablepress_table_options` → Statamic table-field mapping,
 * including the Premium "Tier 1" options added by `UAMS-Web/uams-statamic-archive#243`.
 *
 * Every payload below is a real one, lifted from the `_tablepress_table_options`
 * meta of the exports in `UAMS-Web/wordpress-importer-exports`. The four
 * `uamshealth.com/ar-connectnow` tables are reproduced verbatim because wordpress-importer-archive#447
 * names them as the acceptance case; the remaining rows cover option
 * combinations a corpus survey of 1392 tables showed to exist (`responsive` is
 * absent in 1378, `''` in 10, `modal` in 3, and `scroll` in 1 — `collapse` and
 * `flip` never appear, but `collapse` is mapped because the field supports it).
 *
 * @command  php vendor/bin/pest tests/Unit/TablePressTableOptionsMappingTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators\TablePressEntryCreator;

beforeEach(function (): void {
    $this->creator = new TablePressEntryCreator;
});

/**
 * The full option payload of `ar-connectnow` table 1 — DataTables on, horizontal
 * scroll on, responsive modal stacking, and a column filter over `A,C,D,E,I`.
 *
 * @return array<string, mixed>
 */
function arConnectNowTableOne(): array
{
    return [
        'last_row_footers' => false,
        'table_foot' => false,
        'alternating_row_colors' => true,
        'row_hover' => true,
        'use_datatables' => true,
        'datatables_sort' => true,
        'datatables_filter' => true,
        'datatables_paginate' => false,
        'datatables_info' => false,
        'datatables_scrollx' => true,
        'responsive' => 'modal',
        'responsive_breakpoint' => 'phone',
        // Present-but-unmapped Premium options — must be ignored without error
        'responsive_scroll_buttons' => false,
        'datatables_columnfilterwidgets' => true,
        'datatables_columnfilterwidgets_columns' => 'A,C,D,E,I',
        'datatables_columnfilterwidgets_exclude_columns' => '',
        'datatables_columnfilterwidgets_separator' => ', ',
        'datatables_columnfilterwidgets_max_selections' => '1',
        'datatables_columnfilterwidgets_group_terms' => false,
    ];
}

it('maps the Tier 1 column-filter options onto the Statamic fields', function (): void {
    $data = $this->creator->mapTableOptions(arConnectNowTableOne());

    expect($data['column_filter_enable'] ?? null)->toBeTrue();
    expect($data['column_filter_columns'] ?? null)->toBe('A,C,D,E,I');
});

it('maps the Tier 1 responsive-stacking options onto the Statamic fields', function (): void {
    $data = $this->creator->mapTableOptions(arConnectNowTableOne());

    expect($data['responsive_stack_enable'] ?? null)->toBeTrue();
    expect($data['responsive_stack_breakpoint'] ?? null)->toBe('phone');
});

it('keeps mapping the free-tier options alongside the Tier 1 ones', function (): void {
    $data = $this->creator->mapTableOptions(arConnectNowTableOne());

    expect($data['striped'] ?? null)->toBeTrue();
    expect($data['row_hover_highlighting'] ?? null)->toBeTrue();
    expect($data['sorting_enable'] ?? null)->toBeTrue();
    expect($data['search_enable'] ?? null)->toBeTrue();
    expect($data['pagination_enable'] ?? null)->toBeFalse();
    expect($data['info_enable'] ?? null)->toBeFalse();
    expect($data['horizontal_scroll_enable'] ?? null)->toBeTrue();
    expect($data['last_row_footers'] ?? null)->toBeFalse();
});

it('normalizes whitespace in a column-filter list', function (): void {
    // ar-connectnow table 4 writes its column list with spaces after each comma,
    // while tables 1 and 3 write it without. The Statamic field expects bare
    // comma-separated spreadsheet letters, so the two must converge.
    $options = arConnectNowTableOne();
    $options['datatables_columnfilterwidgets_columns'] = 'A, C, D, E, F';

    $data = $this->creator->mapTableOptions($options);

    expect($data['column_filter_columns'] ?? null)->toBe('A,C,D,E,F');
});

it('treats an empty responsive value as no stacking', function (): void {
    // ar-connectnow table 2: every Tier 1 feature off, but the breakpoint key is
    // still present — it must not leak through as an enabled setting.
    $options = arConnectNowTableOne();
    $options['responsive'] = '';
    $options['datatables_scrollx'] = false;
    $options['datatables_columnfilterwidgets'] = false;
    $options['datatables_columnfilterwidgets_columns'] = '';

    $data = $this->creator->mapTableOptions($options);

    expect($data['responsive_stack_enable'] ?? null)->toBeFalse();
    expect($data['column_filter_enable'] ?? null)->toBeFalse();
    expect($data['horizontal_scroll_enable'] ?? null)->toBeFalse();
});

it('omits the companion field when its Tier 1 toggle is off', function (): void {
    // The host fieldset gates `column_filter_columns` on `column_filter_enable`
    // and `responsive_stack_breakpoint` on `responsive_stack_enable`, so writing
    // either while the toggle is off stores unreachable data.
    $options = arConnectNowTableOne();
    $options['responsive'] = '';
    $options['datatables_columnfilterwidgets'] = false;

    $data = $this->creator->mapTableOptions($options);

    expect($data)->not->toHaveKey('column_filter_columns');
    expect($data)->not->toHaveKey('responsive_stack_breakpoint');
});

it('enables stacking only for the responsive modes that stack', function (string $responsive, bool $expected): void {
    $options = arConnectNowTableOne();
    $options['responsive'] = $responsive;

    expect($this->creator->mapTableOptions($options)['responsive_stack_enable'] ?? null)->toBe($expected);
})->with([
    // The only stacking modes named by wordpress-importer-archive#447
    'modal stacks' => ['modal', true],
    'collapse stacks' => ['collapse', true],
    // ---
    // `scroll` is horizontal scrolling, which already has its own field fed by
    // `datatables_scrollx`. Safe to leave alone: across all 1392 tables in the
    // corpus the single `responsive=scroll` table also sets
    // `datatables_scrollx=true`, so it keeps its horizontal scroll either way.
    // ---
    'scroll does not stack' => ['scroll', false],
    // `flip` transposes rather than stacks, and never occurs in the corpus
    'flip does not stack' => ['flip', false],
    'empty does not stack' => ['', false],
    'an unknown mode does not stack' => ['something-new', false],
]);

it('honors the responsive breakpoint when stacking is enabled', function (string $breakpoint, string $expected): void {
    $options = arConnectNowTableOne();
    $options['responsive_breakpoint'] = $breakpoint;

    expect($this->creator->mapTableOptions($options)['responsive_stack_breakpoint'] ?? null)->toBe($expected);
})->with([
    'phone' => ['phone', 'phone'],
    'tablet' => ['tablet', 'tablet'],
    // The Statamic field offers only phone/tablet, and TablePress defaults to
    // phone, so anything else falls back rather than writing an invalid value.
    'an unknown breakpoint falls back to phone' => ['desktop', 'phone'],
    'an empty breakpoint falls back to phone' => ['', 'phone'],
]);

it('disables the column filter when DataTables is off', function (): void {
    // Column Filter Dropdowns is a DataTables widget, so it cannot apply when
    // DataTables itself is disabled — even if the option says otherwise.
    $options = arConnectNowTableOne();
    $options['use_datatables'] = false;

    $data = $this->creator->mapTableOptions($options);

    expect($data['column_filter_enable'] ?? null)->toBeFalse();
    expect($data)->not->toHaveKey('column_filter_columns');
});

it('still stacks responsively when DataTables is off', function (): void {
    // TablePress's Responsive Tables module is independent of DataTables, so
    // stacking must survive `use_datatables: false`.
    $options = arConnectNowTableOne();
    $options['use_datatables'] = false;

    $data = $this->creator->mapTableOptions($options);

    expect($data['responsive_stack_enable'] ?? null)->toBeTrue();
    expect($data['responsive_stack_breakpoint'] ?? null)->toBe('phone');
});

it('leaves the Tier 1 fields off for a free-tier payload with no Premium keys', function (): void {
    // The overwhelming majority: 1378 of 1392 tables carry no `responsive` or
    // `columnfilterwidgets` keys at all.
    $data = $this->creator->mapTableOptions([
        'table_foot' => true,
        'alternating_row_colors' => true,
        'row_hover' => false,
        'use_datatables' => true,
        'datatables_sort' => true,
        'datatables_filter' => false,
        'datatables_paginate' => true,
        'datatables_paginate_entries' => 25,
        'datatables_lengthchange' => true,
        'datatables_info' => true,
        'datatables_scrollx' => false,
    ]);

    // Absent Premium options read as disabled, never as null or a stray string
    expect($data['column_filter_enable'] ?? null)->toBeFalse();
    expect($data['responsive_stack_enable'] ?? null)->toBeFalse();
    expect($data)->not->toHaveKey('column_filter_columns');
    expect($data)->not->toHaveKey('responsive_stack_breakpoint');

    // Free-tier mapping is untouched, including the pagination sub-options
    expect($data['last_row_footers'] ?? null)->toBeTrue();
    expect($data['pagination_enable'] ?? null)->toBeTrue();
    expect($data['pagination_count'] ?? null)->toBe(25);
    expect($data['pagination_change_enable'] ?? null)->toBeTrue();
    expect($data['info_enable'] ?? null)->toBeTrue();
});

it('turns every DataTables feature off when DataTables is disabled', function (): void {
    $options = arConnectNowTableOne();
    $options['use_datatables'] = false;

    $data = $this->creator->mapTableOptions($options);

    // Each flag must be forced off, not merely left at whatever the payload said
    expect($data['sorting_enable'] ?? null)->toBeFalse();
    expect($data['search_enable'] ?? null)->toBeFalse();
    expect($data['pagination_enable'] ?? null)->toBeFalse();
    expect($data['info_enable'] ?? null)->toBeFalse();
    expect($data['horizontal_scroll_enable'] ?? null)->toBeFalse();
});

it('falls back to ten rows when pagination is on with no explicit count', function (): void {
    $options = arConnectNowTableOne();
    $options['datatables_paginate'] = true;
    unset($options['datatables_paginate_entries']);

    expect($this->creator->mapTableOptions($options)['pagination_count'] ?? null)->toBe(10);
});

it('normalizes the case and padding of a responsive mode', function (string $responsive): void {
    $options = arConnectNowTableOne();
    $options['responsive'] = $responsive;

    expect($this->creator->mapTableOptions($options)['responsive_stack_enable'] ?? null)->toBeTrue();
})->with([
    'uppercase' => ['MODAL'],
    'mixed case' => ['Collapse'],
    'padded' => ['  modal  '],
    'padded and uppercase' => ["\tCOLLAPSE "],
]);

it('normalizes the case and padding of a responsive breakpoint', function (string $breakpoint): void {
    $options = arConnectNowTableOne();
    $options['responsive_breakpoint'] = $breakpoint;

    expect($this->creator->mapTableOptions($options)['responsive_stack_breakpoint'] ?? null)->toBe('tablet');
})->with([
    'uppercase' => ['TABLET'],
    'mixed case' => ['Tablet'],
    'padded' => ['  tablet  '],
]);

it('normalizes non-boolean scalar option values to booleans', function (mixed $raw, bool $expected): void {
    // ---
    // TablePress writes JSON booleans today, but older versions wrote `"1"` / `"0"`
    // and the option payload is untyped either way. `getBooleanOption()` casts any
    // scalar, so a string or integer flag must land as a real boolean rather than
    // reaching the blueprint field as a truthy string.
    // ---
    $options = arConnectNowTableOne();
    $options['row_hover'] = $raw;

    expect($this->creator->mapTableOptions($options)['row_hover_highlighting'] ?? null)->toBe($expected);
})->with([
    'string one' => ['1', true],
    'string zero' => ['0', false],
    'integer one' => [1, true],
    'integer zero' => [0, false],
    'empty string' => ['', false],
    // A non-scalar cannot be normalized, so the documented default applies
    'array falls back to the default' => [['unexpected'], false],
]);

it('drops a column-filter list that holds no usable column letters', function (string $columns): void {
    $options = arConnectNowTableOne();
    $options['datatables_columnfilterwidgets_columns'] = $columns;

    $data = $this->creator->mapTableOptions($options);

    // The toggle still reflects the source, but an unusable list is not written
    expect($data['column_filter_enable'] ?? null)->toBeTrue();
    expect($data)->not->toHaveKey('column_filter_columns');
})->with([
    'empty' => [''],
    'only whitespace' => ['   '],
    'only separators' => [' , , '],
]);
