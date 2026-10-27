<?php

declare(strict_types=1);

/**
 * The Gravity Forms form configuration manager: its mapping tables, merge tags, subfields,
 * routing, confirmations, schedules and notifications (#98).
 *
 * Most of the manager's hooks are protected, so they are called through reflection on a
 * real instance; the public entry point configures and saves a real form.
 * `PreventsSavingStacheItemsToDisk` keeps that form out of the working tree.
 *
 * @command  php vendor/bin/pest tests/Integration/GravityFormsFormConfigurationManagerTest.php
 */

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\Form as FormFacade;
use Statamic\Forms\Form;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FormConfigurationManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

/**
 * Call one of the manager's methods, whatever its visibility.
 *
 * @param  FormConfigurationManager  $manager  The manager
 * @param  string  $method  The method name
 * @param  mixed  ...$arguments  The arguments
 * @return mixed What the method returned
 */
function gfcmCall(FormConfigurationManager $manager, string $method, mixed ...$arguments): mixed
{
    return (new ReflectionMethod($manager, $method))->invoke($manager, ...$arguments);
}

/**
 * Make a Statamic form.
 *
 * @param  string  $handle  The form handle
 * @return Form The form
 */
function gfcmForm(string $handle): Form
{
    $form = FormFacade::make($handle);

    if (! $form instanceof Form) {
        throw new LogicException('FormFacade::make() returned no Statamic form.');
    }

    return $form;
}

/**
 * Read a form's stored data.
 *
 * @param  Form  $form  The form
 * @return array<int|string, mixed> The data
 */
function gfcmFormData(Form $form): array
{
    $data = $form->data();

    return $data instanceof Collection ? $data->all() : [];
}

/**
 * Read the warnings recorded in one category and type.
 *
 * @param  string  $category  The warning category
 * @param  string  $type  The warning type
 * @return list<mixed> The warnings
 */
function gfcmWarnings(string $category, string $type): array
{
    $warnings = ImportWarningManager::getWarningsByCategory($category)[$type] ?? [];

    return \is_array($warnings) ? \array_values($warnings) : [];
}

/**
 * Narrow a result a test knows is a table.
 *
 * @param  mixed  $value  The result
 * @return array<int|string, mixed> The table
 */
function gfcmTable(mixed $value): array
{
    if (! \is_array($value)) {
        throw new LogicException('Expected a table, got '.\get_debug_type($value).'.');
    }

    return $value;
}

/**
 * A form's fields: a text field, an email field and an address with a City input.
 *
 * @return list<array<string, mixed>> The fields
 */
function gfcmFields(): array
{
    return [
        ['id' => 1, 'label' => 'Full Name', 'type' => 'text'],
        ['id' => 2, 'label' => 'Email', 'type' => 'email'],
        ['id' => 4, 'label' => 'Home Address', 'type' => 'address', 'inputs' => [['id' => '4.1', 'label' => 'Street Address'], ['id' => '4.3', 'label' => 'City']]],
    ];
}

beforeEach(function (): void {
    $this->manager = new FormConfigurationManager;
    $this->importer = $this->createMock(AssetImporter::class);
    ImportWarningManager::clearWarnings();
});

afterEach(function (): void {
    ImportWarningManager::clearWarnings();
    ProcessingContext::clearFormConfigContext();
});

it('rewrites today, quiz and survey merge tags into Antlers', function (): void {
    expect(gfcmCall($this->manager, 'processComplexFormatMappings', 'On {today:format:Y-m-d}, {quiz:id=q1} scored {quiz_score:id=q1} and {score:id=s2}.'))
        ->toBe('On {{ today format="Y-m-d" }}, {{ quiz handle="q1" }} scored {{ quiz:score handle="q1" }} and {{ survey:score handle="s2" }}.')
        ->and(gfcmCall($this->manager, 'processComplexFormatMappings', 'Nothing to rewrite'))->toBe('Nothing to rewrite');
});

it('strips current-user and entry-author merge tags, warning once for each', function (): void {
    $value = gfcmCall($this->manager, 'processCurrentUserMergeTags', 'From {user:display_name} <{user:user_email}> for {created_by:first_name}, nested {user:dis{user:user_email}play_name}.');
    $warnings = gfcmWarnings('forms', 'neutralized_merge_tags');

    expect($value)->toBe('From  <> for , nested .')
        ->and(\array_map(static fn (mixed $warning): mixed => \is_array($warning) ? $warning['merge_tag'] : null, $warnings))->toBe(['{user:display_name}', '{user:user_email}', '{created_by:first_name}', '{user:user_email}'])
        ->and(\is_array($warnings[2] ?? null) ? $warnings[2]['reason'] : null)->toBe('Entry-author merge tags have no Statamic equivalent; the value falls back to the site-default From.')
        ->and(\is_array($warnings[0] ?? null) ? $warnings[0]['reason'] : null)->toBe('Current-user merge tags have no Statamic equivalent; the value falls back to the site-default From.')
        ->and(gfcmCall($this->manager, 'processCurrentUserMergeTags', 'No tags {user:login}'))->toBe('No tags {user:login}');
});

it('neutralizes the merge tags in every string of a notification, routing rules included', function (): void {
    $notification = gfcmCall($this->manager, 'neutralizeCurrentUserMergeTags', [
        'fromName' => '{user:display_name}',
        'isActive' => true,
        'routing' => [['email' => '{user:user_email}', 'value' => 'kept']],
    ]);

    expect($notification)->toBe(['fromName' => '', 'isActive' => true, 'routing' => [['email' => '', 'value' => 'kept']]]);
});

it('rewrites a field merge tag to its submission value, and leaves every other tag alone', function (): void {
    $value = gfcmCall($this->manager, 'processFieldMergeTags', 'Hi {Full Name:1}, city {City:4.3}, {Email:2:value}, {all_fields:3}, {:2}, {Unknown:9}, {plain}, {no:number}, {1}, {Full Name:1:2}', gfcmFields());

    // A bare number is not a field tag, and the first number in a tag is its field ID.
    expect($value)->toBe('Hi {{ submission:full_name_1 }}, city {{ submission:home_address_4.city }}, {{ submission:email_2 }}, {all_fields:3}, {{ submission:email_2 }}, {Unknown:9}, {plain}, {no:number}, {1}, {{ submission:full_name_1 }}');
});

it('leaves every system merge tag alone, even one carrying a field number', function (): void {
    $tags = (new ReflectionClassConstant(FormConfigurationManager::class, 'SYSTEM_MERGE_TAGS'))->getValue();
    $text = \implode(' ', \array_map(static fn (mixed $tag): string => '{'.(\is_string($tag) ? $tag : '').':1}', \is_array($tags) ? $tags : []));

    expect(\is_array($tags) ? \count($tags) : 0)->toBe(37)
        ->and(gfcmCall($this->manager, 'processFieldMergeTags', $text, gfcmFields()))->toBe($text)
        ->and(gfcmCall($this->manager, 'translateFieldReferences', $text, gfcmFields()))->toBe($text);
});

it('reads a field name from its label', function (mixed $label, string $expected): void {
    expect(gfcmCall($this->manager, 'getFieldNameFromField', ['label' => $label]))->toBe($expected);
})->with([
    'text' => ['Email', 'Email'],
    'a number' => [5, '5'],
    'a list' => [['x'], ''],
    'nothing' => [null, ''],
]);

it('resolves a subfield to its parent handle and mapped subfield handle', function (string $subfieldId, array $fields, ?string $expected): void {
    expect(gfcmCall($this->manager, 'getSubfieldHandleById', $subfieldId, $fields))->toBe($expected);
})->with([
    'a mapped subfield' => ['4.3', gfcmFields(), 'home_address_4.city'],
    'not a subfield' => ['4', gfcmFields(), null],
    'too many parts' => ['4.3.1', gfcmFields(), null],
    'no such parent' => ['7.3', gfcmFields(), null],
    'no such input' => ['4.9', gfcmFields(), null],
    'a field that is not a table' => ['4.3', ['not a field', ...gfcmFields()], 'home_address_4.city'],
    'a parent without a label' => ['4.3', [['id' => 4, 'type' => 'address', 'inputs' => [['id' => '4.3', 'label' => 'City']]]], null],
    'a parent without inputs' => ['4.3', [['id' => 4, 'label' => 'Home', 'type' => 'address']], null],
    'an input that is not a table' => ['4.3', [['id' => 4, 'label' => 'Home', 'type' => 'address', 'inputs' => ['x', ['id' => '4.3', 'label' => 'City']]]], 'home_4.city'],
    'an input without a label' => ['4.3', [['id' => 4, 'label' => 'Home', 'type' => 'address', 'inputs' => [['id' => '4.3']]]], null],
    'a parent without a type' => ['4.3', [['id' => 4, 'label' => 'Home', 'inputs' => [['id' => '4.3', 'label' => 'City']]]], null],
    'a parent ID that is not scalar' => ['4.3', [['id' => ['4'], 'label' => 'Home', 'type' => 'address', 'inputs' => [['id' => '4.3', 'label' => 'City']]]], null],
    'a numeric parent label' => ['4.3', [['id' => 4, 'label' => 5, 'type' => 'address', 'inputs' => [['id' => '4.3', 'label' => 'City']]]], '5_4.city'],
    'a numeric input ID' => ['4.3', [['id' => 4, 'label' => 'Home', 'type' => 'address', 'inputs' => [['id' => 4.3, 'label' => 'City']]]], 'home_4.city'],
    'an input without an ID' => ['4.3', [['id' => 4, 'label' => 'Home', 'type' => 'address', 'inputs' => [['label' => 'City'], ['id' => '4.3', 'label' => 'City']]]], 'home_4.city'],
    'a numeric input label' => ['4.3', [['id' => 4, 'label' => 'Home', 'type' => 'address', 'inputs' => [['id' => '4.3', 'label' => 5]]]], null],
    'a numeric parent type' => ['4.3', [['id' => 4, 'label' => 'Home', 'type' => 5, 'inputs' => [['id' => '4.3', 'label' => 'City']]]], null],
    'an input ID with three parts' => ['4.3.1', [['id' => 4, 'label' => 'Home', 'type' => 'address', 'inputs' => [['id' => '4.3.1', 'label' => 'City']]]], null],
    'a labelled parent after an unlabelled one' => ['4.3', [['id' => 4, 'type' => 'address', 'inputs' => [['id' => '4.3', 'label' => 'City']]], ['id' => 4, 'label' => 'Home', 'type' => 'address', 'inputs' => [['id' => '4.3', 'label' => 'City']]]], 'home_4.city'],
    'a typed parent after an untyped one' => ['4.3', [['id' => 4, 'label' => 'Home', 'inputs' => [['id' => '4.3', 'label' => 'City']]], ['id' => 4, 'label' => 'Home', 'type' => 'address', 'inputs' => [['id' => '4.3', 'label' => 'City']]]], 'home_4.city'],
    'a parent with inputs after one without' => ['4.3', [['id' => 4, 'label' => 'Home', 'type' => 'address'], ['id' => 4, 'label' => 'Home', 'type' => 'address', 'inputs' => [['id' => '4.3', 'label' => 'City']]]], 'home_4.city'],
    'a labelled input after an unlabelled one' => ['4.3', [['id' => 4, 'label' => 'Home', 'type' => 'address', 'inputs' => [['id' => '4.3'], ['id' => '4.3', 'label' => 'City']]]], 'home_4.city'],
    'a second parent after a mismatch' => ['4.3', [['id' => 3, 'label' => 'Other'], ['id' => 4, 'label' => 'Home', 'type' => 'address', 'inputs' => [['id' => '4.1', 'label' => 'Street Address'], ['id' => '4.3', 'label' => 'City']]]], 'home_4.city'],
]);

it('reads a subfield handle from the mapping, and nothing from a mapping it cannot read', function (array $mapping, ?string $expected): void {
    expect(gfcmCall($this->manager, 'getSubfieldHandleFromMapping', 'address', 'City', $mapping))->toBe($expected);
})->with([
    'a handle' => [['fieldtypes' => ['address' => ['subfields' => ['City' => ['handle' => 'city']]]]], 'city'],
    'a handle that is not text' => [['fieldtypes' => ['address' => ['subfields' => ['City' => ['handle' => 5]]]]], null],
    'no handle' => [['fieldtypes' => ['address' => ['subfields' => ['City' => []]]]], null],
    'no such subfield' => [['fieldtypes' => ['address' => ['subfields' => ['Zip' => ['handle' => 'zip']]]]], null],
    'subfields that are not a table' => [['fieldtypes' => ['address' => ['subfields' => 'x']]], null],
    'no such field type' => [['fieldtypes' => ['name' => []]], null],
    'field types that are not a table' => [['fieldtypes' => 'x'], null],
    'no field types' => [[], null],
]);

it('converts routing rules whose field resolves, with each operator', function (): void {
    $rules = gfcmCall($this->manager, 'convertRoutingRulesToStatamicFormat', [
        'not a rule',
        ['fieldId' => '9', 'operator' => 'is', 'value' => 'x', 'email' => 'never@uams.edu'],
        ['fieldId' => 1, 'operator' => 'is', 'value' => 'Ann', 'email' => 'ann@uams.edu'],
        ['fieldId' => '2', 'operator' => 'isnot', 'value' => 5, 'email' => 'b@uams.edu'],
        ['fieldId' => '1', 'operator' => '>', 'value' => ['x'], 'email' => ['x']],
        ['fieldId' => '1', 'operator' => '<'],
        ['fieldId' => '1', 'operator' => 'contains', 'value' => 'a', 'email' => 'c@uams.edu'],
        ['fieldId' => '1', 'operator' => 'starts_with', 'value' => 'a', 'email' => 'c@uams.edu'],
        ['fieldId' => '1', 'operator' => 'ends_with', 'value' => 'a', 'email' => 'c@uams.edu'],
        ['fieldId' => '1', 'operator' => 'unknown', 'value' => 'a', 'email' => 'c@uams.edu'],
        ['fieldId' => '1', 'operator' => ['x'], 'value' => 'a', 'email' => 'c@uams.edu'],
        ['fieldId' => ['1'], 'operator' => 'is', 'value' => 'a', 'email' => 'c@uams.edu'],
        ['operator' => 'is', 'value' => 'a', 'email' => 'c@uams.edu'],
        ['fieldId' => '1', 'operator' => 5, 'value' => 'a', 'email' => 7],
    ], gfcmFields());

    expect($rules)->toBe([
        ['field' => 'full_name_1', 'operator' => 'equals', 'value' => 'Ann', 'email' => 'ann@uams.edu'],
        ['field' => 'email_2', 'operator' => 'not', 'value' => '5', 'email' => 'b@uams.edu'],
        ['field' => 'full_name_1', 'operator' => 'greater_than', 'value' => '', 'email' => ''],
        ['field' => 'full_name_1', 'operator' => 'less_than', 'value' => '', 'email' => ''],
        ['field' => 'full_name_1', 'operator' => 'contains', 'value' => 'a', 'email' => 'c@uams.edu'],
        ['field' => 'full_name_1', 'operator' => 'starts_with', 'value' => 'a', 'email' => 'c@uams.edu'],
        ['field' => 'full_name_1', 'operator' => 'ends_with', 'value' => 'a', 'email' => 'c@uams.edu'],
        ['field' => 'full_name_1', 'operator' => 'unknown', 'value' => 'a', 'email' => 'c@uams.edu'],
        ['field' => 'full_name_1', 'operator' => '', 'value' => 'a', 'email' => 'c@uams.edu'],
        ['field' => 'full_name_1', 'operator' => '5', 'value' => 'a', 'email' => '7'],
    ]);
});

it('passes form data through unparsed', function (): void {
    expect(gfcmCall($this->manager, 'parseFormData', ['title' => 'Contact']))->toBe(['title' => 'Contact']);
});

it('drops a schedule date and time that could not be combined, and an emptied schedule group', function (): void {
    $method = new ReflectionMethod($this->manager, 'mapPluginSpecificFields');
    $partial = ['schedule_form' => ['start_datetime' => null, 'end_datetime' => '2026-01-01 09:00']];
    $empty = ['schedule_form' => ['start_datetime' => null, 'end_datetime' => null]];
    $other = ['schedule_form' => 'not a table', 'title' => 'x'];
    $untouched = ['schedule_form' => ['other' => null]];

    foreach ([&$partial, &$empty, &$other, &$untouched] as &$data) {
        $method->invokeArgs($this->manager, [&$data, [], 'default']);
    }

    expect($partial)->toBe(['schedule_form' => ['end_datetime' => '2026-01-01 09:00']])
        ->and($empty)->toBe([])
        ->and($other)->toBe(['schedule_form' => 'not a table', 'title' => 'x'])
        ->and($untouched)->toBe(['schedule_form' => ['other' => null]]);
});

it('combines a schedule date, hour, minute and meridiem into a 24-hour datetime', function (string $hour, string $ampm, string $expected): void {
    $formData = ['scheduleStart' => '08/19/2025', 'scheduleStartHour' => $hour, 'scheduleStartMinute' => '05', 'scheduleStartAmpm' => $ampm];

    expect(gfcmCall($this->manager, 'processDateTimeField', 'schedule_form.start_datetime', 'ignored', $formData))->toBe($expected);
})->with([
    'a morning hour' => ['9', 'am', '2025-08-19 09:05'],
    'midnight' => ['12', 'AM', '2025-08-19 00:05'],
    'noon' => ['12', 'pm', '2025-08-19 12:05'],
    'an afternoon hour' => ['3', 'PM', '2025-08-19 15:05'],
    'an unknown meridiem' => ['3', 'xx', '2025-08-19 03:05'],
]);

it('reads the end of the schedule from its own fields', function (): void {
    $formData = ['scheduleEnd' => '12/31/2026', 'scheduleEndHour' => '11', 'scheduleEndMinute' => '30', 'scheduleEndAmpm' => 'pm'];

    expect(gfcmCall($this->manager, 'processDateTimeField', 'schedule_form.end_datetime', null, $formData))->toBe('2026-12-31 23:30');
});

it('combines no schedule datetime from a missing, blank or unreadable part, and warns about none of them', function (array $formData): void {
    Log::shouldReceive('warning')->never();

    expect(gfcmCall($this->manager, 'processDateTimeField', 'schedule_form.start_datetime', null, $formData))->toBeNull();
})->with([
    'a date that is a list' => [['scheduleStart' => ['x'], 'scheduleStartHour' => '9', 'scheduleStartMinute' => '0', 'scheduleStartAmpm' => 'am']],
    'an hour that is a list' => [['scheduleStart' => '08/19/2025', 'scheduleStartHour' => ['9'], 'scheduleStartMinute' => '0', 'scheduleStartAmpm' => 'am']],
    'a minute that is a list' => [['scheduleStart' => '08/19/2025', 'scheduleStartHour' => '9', 'scheduleStartMinute' => ['0'], 'scheduleStartAmpm' => 'am']],
    'a meridiem that is a list' => [['scheduleStart' => '08/19/2025', 'scheduleStartHour' => '9', 'scheduleStartMinute' => '0', 'scheduleStartAmpm' => ['am']]],
    'a false hour' => [['scheduleStart' => '08/19/2025', 'scheduleStartHour' => false, 'scheduleStartMinute' => '0', 'scheduleStartAmpm' => 'am']],
    'no date' => [['scheduleStartHour' => '9', 'scheduleStartMinute' => '0', 'scheduleStartAmpm' => 'am']],
    'no hour' => [['scheduleStart' => '08/19/2025', 'scheduleStartMinute' => '0', 'scheduleStartAmpm' => 'am']],
    'no minute' => [['scheduleStart' => '08/19/2025', 'scheduleStartHour' => '9', 'scheduleStartAmpm' => 'am']],
    'no meridiem' => [['scheduleStart' => '08/19/2025', 'scheduleStartHour' => '9', 'scheduleStartMinute' => '0']],
    'a blank date' => [['scheduleStart' => '', 'scheduleStartHour' => '9', 'scheduleStartMinute' => '0', 'scheduleStartAmpm' => 'am']],
    'a blank hour' => [['scheduleStart' => '08/19/2025', 'scheduleStartHour' => '', 'scheduleStartMinute' => '0', 'scheduleStartAmpm' => 'am']],
    'a blank minute' => [['scheduleStart' => '08/19/2025', 'scheduleStartHour' => '9', 'scheduleStartMinute' => '', 'scheduleStartAmpm' => 'am']],
    'a blank meridiem' => [['scheduleStart' => '08/19/2025', 'scheduleStartHour' => '9', 'scheduleStartMinute' => '0', 'scheduleStartAmpm' => '']],
]);

it('warns about a schedule date it cannot read, naming the date', function (mixed $date, string $shown): void {
    $warnings = [];
    Log::shouldReceive('warning')->andReturnUsing(static function (string $message, array $context) use (&$warnings): void {
        $warnings[] = [$message, $context];
    });
    $formData = ['scheduleEnd' => $date, 'scheduleEndHour' => '9', 'scheduleEndMinute' => '0', 'scheduleEndAmpm' => 'am'];

    expect(gfcmCall($this->manager, 'processDateTimeField', 'schedule_form.end_datetime', null, $formData))->toBeNull()
        ->and(\count($warnings))->toBe(1)
        ->and($warnings[0][0] ?? null)->toContain("Invalid date format for scheduleEnd: {$shown}")
        ->and(\array_intersect_key($warnings[0][1] ?? [], ['prefix' => true, 'date' => true]))->toBe(['prefix' => 'scheduleEnd', 'date' => $shown]);
})->with([
    'a date in another format' => ['2025-08-19', '2025-08-19'],
    'a number' => [8192025, '8192025'],
]);

it('processes only the two schedule attributes as plugin-specific fields', function (): void {
    $formData = ['scheduleStart' => '08/19/2025', 'scheduleStartHour' => '9', 'scheduleStartMinute' => '05', 'scheduleStartAmpm' => 'am'];

    expect(gfcmCall($this->manager, 'processPluginSpecificField', 'schedule_form.start_datetime', 'x', $formData, 'default'))->toBe('2025-08-19 09:05')
        ->and(gfcmCall($this->manager, 'processPluginSpecificField', 'schedule_form.end_datetime', 'x', $formData, 'default'))->toBeNull()
        ->and(gfcmCall($this->manager, 'processPluginSpecificField', 'schedule_form.end_datetime', 'x', ['scheduleEnd' => '08/20/2025', 'scheduleEndHour' => '1', 'scheduleEndMinute' => '00', 'scheduleEndAmpm' => 'pm'], 'default'))->toBe('2025-08-20 13:00')
        ->and(gfcmCall($this->manager, 'processPluginSpecificField', 'heading', 'x', $formData, 'default'))->toBeNull();
});

it('combines the datetime fields a mapping names', function (string $date, string $hour, string $ampm, string $expected): void {
    $config = ['datetime_combination' => ['date_field' => 'd', 'hour_field' => 'h', 'minute_field' => 'm', 'ampm_field' => 'a']];

    expect(gfcmCall($this->manager, 'processDatetimeCombination', $config, ['d' => $date, 'h' => $hour, 'm' => '07', 'a' => $ampm]))->toBe($expected);
})->with([
    'an escaped date' => ['09\/03\/2025', '1', 'pm', '2025-09-03 13:07'],
    'midnight' => ['09/03/2025', '12', 'am', '2025-09-03 00:07'],
    'noon' => ['09/03/2025', '12', 'pm', '2025-09-03 12:07'],
    'an unknown meridiem' => ['09/03/2025', '4', 'xx', '2025-09-03 04:07'],
    'an upper-case afternoon' => ['09/03/2025', '4', 'PM', '2025-09-03 16:07'],
    'an upper-case midnight' => ['09/03/2025', '12', 'AM', '2025-09-03 00:07'],
]);

it('combines no datetime from a mapping or a value it cannot use', function (array $config, array $formData): void {
    expect(gfcmCall($this->manager, 'processDatetimeCombination', $config, $formData))->toBeNull();
})->with(function (): array {
    $full = ['date_field' => 'd', 'hour_field' => 'h', 'minute_field' => 'm', 'ampm_field' => 'a'];
    $data = ['d' => '09/03/2025', 'h' => '1', 'm' => '07', 'a' => 'pm'];
    $cases = [
        'not a table' => [['datetime_combination' => 'x'], $data],
        'an unreadable date' => [['datetime_combination' => $full], [...$data, 'd' => '2025-09-03']],
        'a numeric date' => [['datetime_combination' => $full], [...$data, 'd' => 9032025]],
    ];

    foreach (['date_field', 'hour_field', 'minute_field', 'ampm_field'] as $key) {
        $cases["no {$key}"] = [['datetime_combination' => \array_diff_key($full, [$key => true])], $data];
        $cases["a {$key} that is not text"] = [['datetime_combination' => [...$full, $key => ['x']]], $data];
        $cases["an empty {$key}"] = [['datetime_combination' => [...$full, $key => '']], [...$data, '' => '09/03/2025']];
        $cases["a numeric {$key}"] = [['datetime_combination' => [...$full, $key => 5]], [...$data, 5 => '09/03/2025']];
    }

    foreach (['d', 'h', 'm', 'a'] as $key) {
        $cases["no value for {$key}"] = [['datetime_combination' => $full], \array_diff_key($data, [$key => true])];
        $cases["a blank value for {$key}"] = [['datetime_combination' => $full], [...$data, $key => '']];
        $cases["a list for {$key}"] = [['datetime_combination' => $full], [...$data, $key => ['x']]];
        $cases["a false value for {$key}"] = [['datetime_combination' => $full], [...$data, $key => false]];
    }

    return $cases;
});

it('maps Gravity Forms variables to Antlers', function (): void {
    expect(gfcmCall($this->manager, 'getVariableMappings'))->toHaveCount(20)
        ->and(gfcmCall($this->manager, 'getVariableMappings'))->toMatchArray(['{admin_email}' => '{{ admin_email }}', '{referer}' => '{{ referrer }}', '{all_fields:nohidden}' => '{{ all_fields hide_hidden="true" }}']);
});

it('reports notifications only for a list holding at least one', function (mixed $notifications, bool $expected): void {
    expect(gfcmCall($this->manager, 'hasNotifications', ['notifications' => $notifications]))->toBe($expected);
})->with([
    'one' => [[['id' => 'a']], true],
    'none' => [[], false],
    'not a list' => ['x', false],
    'absent' => [null, false],
]);

it('recognizes a Save and Continue notification by its event', function (mixed $event, bool $expected): void {
    expect(gfcmCall($this->manager, 'isSaveAndContinueNotification', ['event' => $event]))->toBe($expected);
})->with([
    'save and continue' => ['form_save_email_requested', true],
    'a submission' => ['form_submission', false],
    'not text' => [['form_save_email_requested'], false],
    'absent' => [null, false],
]);

it('warns about a notification schedule the import cannot honor', function (mixed $scheduleType, ?string $warned): void {
    gfcmCall($this->manager, 'warnUnhonoredNotificationSchedule', ['id' => 'n1', 'scheduleType' => $scheduleType], 7, 'Reminder');
    $warnings = gfcmWarnings('forms', 'unhonored_notification_schedule');

    expect($warned === null ? $warnings : (\is_array($warnings[0] ?? null) ? $warnings[0] : null))->toBe($warned === null ? [] : [
        'plugin' => 'Gravity Forms',
        'form_id' => '7',
        'notification_id' => 'n1',
        'notification_name' => 'Reminder',
        'schedule_type' => $warned,
        'reason' => 'The Notification Scheduler add-on has no Statamic analog; this notification will send immediately instead of on its configured schedule.',
    ]);
})->with([
    'a delay' => [' delay ', 'delay'],
    'immediate' => ['immediate', null],
    'blank' => ['  ', null],
    'not text' => [5, null],
    'absent' => [null, null],
]);

it('identifies a notification by a numeric ID as text', function (): void {
    gfcmCall($this->manager, 'warnUnhonoredNotificationSchedule', ['id' => 5, 'scheduleType' => 'date'], 7, 'Reminder');
    gfcmCall($this->manager, 'warnSkippedSaveAndContinueNotification', ['id' => 6], 7, 'Resume');

    expect(\array_column(gfcmWarnings('forms', 'unhonored_notification_schedule'), 'notification_id'))->toBe(['5'])
        ->and(\array_column(gfcmWarnings('forms', 'skipped_notifications'), 'notification_id'))->toBe(['6']);
});

it('identifies a notification it cannot name by a question mark', function (): void {
    gfcmCall($this->manager, 'warnUnhonoredNotificationSchedule', ['id' => ['x'], 'scheduleType' => 'date'], 'form', 'Reminder');
    gfcmCall($this->manager, 'warnSkippedSaveAndContinueNotification', ['id' => ['x']], true, 'Resume');
    $schedule = gfcmWarnings('forms', 'unhonored_notification_schedule')[0] ?? null;
    $skipped = gfcmWarnings('forms', 'skipped_notifications')[0] ?? null;

    expect(\is_array($schedule) ? [$schedule['notification_id'], $schedule['form_id']] : null)->toBe(['?', 'form'])
        ->and(\is_array($skipped) ? [$skipped['notification_id'], $skipped['form_id']] : null)->toBe(['?', '1']);
});

it('maps each notification to an email configuration, skipping Save and Continue and warning about schedules', function (): void {
    $configs = gfcmTable(gfcmCall($this->manager, 'mapNotificationsToEmailConfigurations', [
        'id' => 12,
        'fields' => gfcmFields(),
        'notifications' => [
            'not a notification',
            ['id' => 'resume', 'name' => 'Resume', 'event' => 'form_save_email_requested'],
            [
                'id' => 'admin',
                'name' => 'Admin',
                'event' => 'form_submission',
                'isActive' => true,
                'toType' => 'email',
                'to' => ' office@uams.edu , {Email:2} ',
                'fromName' => '{user:display_name}',
                'subject' => 'New entry from {Full Name:1}',
                'enableAttachments' => 'true',
                'scheduleType' => 'delay',
                'conditionalLogic' => ['actionType' => 'show', 'logicType' => 'all', 'rules' => [['fieldId' => '1', 'operator' => 'is', 'value' => 'Ann']]],
            ],
            ['id' => 'plain', 'isActive' => false, 'toType' => 'field', 'toField' => '2', 'conditionalLogic' => ['rules' => []]],
        ],
    ], 'default'));

    $admin = gfcmTable($configs[0] ?? null);
    $plain = gfcmTable($configs[1] ?? null);

    expect(\count($configs))->toBe(2)
        ->and($admin)->toMatchArray([
            'id' => 'admin',
            'name' => 'Admin',
            'to' => 'office@uams.edu,{{ submission:email_2 }}',
            'from_name' => '{{ site:name }}',
            'subject' => 'New entry from {{ submission:full_name_1 }}',
            'attachments' => true,
            'enabled' => true,
            'to_type' => 'email',
            'cc_type' => 'email',
            'bcc_type' => 'email',
            'reply_to_type' => 'email',
            'conditional_logic' => ['if' => ['full_name_1' => 'equals Ann']],
        ])
        ->and($plain)->toMatchArray(['id' => 'plain', 'enabled' => false, 'to_type' => 'field', 'to_field' => 'email_2'])
        ->and(\array_key_exists('conditional_logic', $plain))->toBeFalse()
        ->and(\array_key_exists('to', $plain))->toBeFalse()
        ->and(gfcmWarnings('forms', 'skipped_notifications'))->toBe([[
            'plugin' => 'Gravity Forms',
            'form_id' => '12',
            'notification_id' => 'resume',
            'notification_name' => 'Resume',
            'event' => 'form_save_email_requested',
            'reason' => 'Statamic has no Save & Continue analog; importing this notification would create an email action with no recipient and an unresolvable {save_link} token, so it was skipped.',
        ]])
        ->and(\array_column(gfcmWarnings('forms', 'unhonored_notification_schedule'), 'notification_name'))->toBe(['Admin'])
        ->and(ProcessingContext::getFormConfigContext())->toBeNull();
});

it('names an unnamed notification of an unidentified form, and maps none from a form without them', function (): void {
    gfcmCall($this->manager, 'mapNotificationsToEmailConfigurations', ['notifications' => [['event' => 'form_save_email_requested']]], 'default');
    $skipped = gfcmWarnings('forms', 'skipped_notifications')[0] ?? null;

    expect(\is_array($skipped) ? [$skipped['form_id'], $skipped['notification_name']] : null)->toBe(['?', 'Unnamed'])
        ->and(gfcmCall($this->manager, 'mapNotificationsToEmailConfigurations', ['notifications' => 'x', 'fields' => 'x'], 'default'))->toBe([]);
});

it('identifies the notification being mapped while it is mapped', function (): void {
    $seen = null;
    $manager = new class extends FormConfigurationManager
    {
        /**
         * The context seen while building the configuration.
         */
        public mixed $seen = null;

        /**
         * Record the context, then build as the manager does.
         *
         * @param  array<int|string, mixed>  $processedData  The notification
         * @param  array<int|string, mixed>  $formData  The form data
         * @param  string  $siteHandle  The site handle
         * @param  ?AssetImporter  $assetImporter  The asset importer
         * @param  array<int|string, mixed>  $formFields  The form fields
         * @return array<int|string, mixed> The configuration
         */
        protected function buildEmailConfiguration(array $processedData, array $formData, string $siteHandle, $assetImporter = null, array $formFields = []): array
        {
            $this->seen = ProcessingContext::getFormConfigContext();

            return parent::buildEmailConfiguration($processedData, $formData, $siteHandle, $assetImporter, $formFields);
        }
    };

    gfcmCall($manager, 'mapNotificationsToEmailConfigurations', ['id' => 12, 'notifications' => [['name' => 'Admin']]], 'default');
    $named = $manager->seen;
    gfcmCall($manager, 'mapNotificationsToEmailConfigurations', ['notifications' => [['name' => 9]]], 'default');

    expect($named)->toBe(['form_id' => 12, 'source' => 'notification', 'source_name' => 'Admin', 'plugin' => 'Gravity Forms'])
        ->and($manager->seen)->toBe(['form_id' => '?', 'source' => 'notification', 'source_name' => '9', 'plugin' => 'Gravity Forms']);
});

it('rewrites field references to submission values, leaving system and unknown references alone', function (): void {
    expect(gfcmCall($this->manager, 'translateFieldReferences', '?name={Full Name:1}&city={City:4.3}&e={Email:2:value}&{entry_id:5}&{Unknown:9}&{plain}&{a:b}', gfcmFields()))
        ->toBe('?name={{ submission:full_name_1 }}&city={{ submission:home_address_4.city }}&e={{ submission:email_2 }}&{entry_id:5}&{Unknown:9}&{plain}&{a:b}')
        ->and(gfcmCall($this->manager, 'translateFieldReferences', '{Full Name:1}'))->toBe('{Full Name:1}');
});

it('maps a single confirmation view by its own table', function (): void {
    $view = ['type' => 'redirect', 'url' => 'https:\/\/www.uams.edu\/', 'queryString' => ' a={Full Name:1} ', 'name' => 'ignored', 'empty' => ''];
    $formData = ['fields' => gfcmFields()];

    expect(gfcmCall($this->manager, 'processSingleConfirmationView', $view, $formData, 'default'))->toBe([
        'confirmation_type' => 'redirect',
        'confirmation_redirect_url' => 'https://www.uams.edu/',
        'confirmation_query_string' => 'a={{ submission:full_name_1 }}',
    ])->and(gfcmCall($this->manager, 'processSingleConfirmationView', $view, $formData, 'default', null, true))->toBe(['confirmation_settings' => [
        'confirmation_type' => 'redirect',
        'confirmation_redirect_url' => 'https://www.uams.edu/',
        'confirmation_query_string' => 'a={{ submission:full_name_1 }}',
    ]])->and(gfcmCall($this->manager, 'processSingleConfirmationView', ['type' => '', 'url' => ''], [], 'default'))->toBeNull()
        // Without form fields there is nothing to resolve a field reference against.
        ->and(gfcmCall($this->manager, 'processSingleConfirmationView', ['queryString' => 'a={Full Name:1}'], [], 'default'))->toBe(['confirmation_query_string' => 'a={Full Name:1}']);
});

it('maps the default confirmation and each active additional one onto the form', function (): void {
    $form = gfcmForm('contact');
    $form->merge(['kept' => 'yes']);

    gfcmCall($this->manager, 'mapConfirmationFields', $form, [
        'id' => 12,
        'fields' => gfcmFields(),
        'confirmations' => [
            'not a confirmation',
            ['id' => 'skip', 'event' => 'form_saved', 'isDefault' => false, 'type' => 'message'],
            ['id' => 'none', 'isDefault' => false, 'type' => 'message'],
            ['id' => 'default', 'name' => 'Default Confirmation', 'event' => 'form_submission', 'isDefault' => true, 'type' => 'redirect', 'url' => 'https:\/\/www.uams.edu\/thanks', 'queryString' => ' who={Full Name:1} '],
            ['id' => 'extra', 'name' => 'Extra', 'event' => 'form_submission', 'isDefault' => '1', 'isActive' => false, 'type' => 'page', 'pageId' => '', 'conditionalLogic' => ['actionType' => 'show', 'logicType' => 'all', 'rules' => [['fieldId' => '2', 'operator' => 'is', 'value' => 'a@uams.edu']]]],
            ['event' => 'form_submission', 'type' => 'redirect', 'url' => '/next', 'conditionalLogic' => ['rules' => []]],
        ],
    ], 'default', $this->importer);

    expect(gfcmFormData($form))->toBe([
        'kept' => 'yes',
        'confirmation_settings_default' => [
            'confirmation_type' => 'redirect',
            'confirmation_redirect_url' => 'https://www.uams.edu/thanks',
            'confirmation_query_string' => 'who={{ submission:full_name_1 }}',
        ],
        'confirmation_additional' => [
            [
                'type' => 'confirmation',
                'enabled' => false,
                'id' => 'extra',
                'confirmation_name' => 'Extra',
                'confirmation_settings' => ['confirmation_type' => 'entry'],
                'conditional_logic' => ['if' => ['email_2' => 'equals a@uams.edu']],
            ],
            [
                'type' => 'confirmation',
                'enabled' => true,
                'confirmation_settings' => ['confirmation_type' => 'redirect', 'confirmation_redirect_url' => '/next'],
            ],
        ],
    ])->and(ProcessingContext::getFormConfigContext())->toBeNull();
});

it('keeps the confirmation settings and additional confirmations the form already holds', function (): void {
    $form = gfcmForm('contact');
    $form->merge(['confirmation_settings_default' => ['kept' => 1], 'confirmation_additional' => [['type' => 'confirmation', 'id' => 'earlier']]]);

    gfcmCall($this->manager, 'mapConfirmationFields', $form, ['confirmations' => [
        ['event' => 'form_submission', 'isDefault' => true, 'type' => 'message'],
        ['event' => 'form_submission', 'isActive' => '0', 'type' => 'redirect'],
    ]], 'default');

    expect(gfcmFormData($form))->toBe([
        'confirmation_settings_default' => ['kept' => 1, 'confirmation_type' => 'message'],
        'confirmation_additional' => [
            ['type' => 'confirmation', 'id' => 'earlier'],
            ['type' => 'confirmation', 'enabled' => false, 'confirmation_settings' => ['confirmation_type' => 'redirect']],
        ],
    ]);
});

it('clears the confirmation context after mapping only a default confirmation', function (): void {
    gfcmCall($this->manager, 'mapConfirmationFields', gfcmForm('contact'), ['confirmations' => [['event' => 'form_submission', 'isDefault' => true]]], 'default');

    expect(ProcessingContext::getFormConfigContext())->toBeNull();
});

it('replaces a confirmation group the form holds as something other than a table', function (): void {
    $form = gfcmForm('contact');
    $form->merge(['confirmation_settings_default' => 'not a table', 'confirmation_additional' => 'not a list']);

    gfcmCall($this->manager, 'mapConfirmationFields', $form, ['confirmations' => [
        ['event' => 'form_submission', 'isDefault' => true, 'type' => 'message'],
        ['event' => 'form_submission', 'type' => 'redirect'],
    ]], 'default');

    expect(gfcmFormData($form))->toBe([
        'confirmation_settings_default' => ['confirmation_type' => 'message'],
        'confirmation_additional' => [['type' => 'confirmation', 'enabled' => true, 'confirmation_settings' => ['confirmation_type' => 'redirect']]],
    ]);
});

it('maps no confirmation from a form without any', function (mixed $confirmations): void {
    $form = gfcmForm('contact');

    gfcmCall($this->manager, 'mapConfirmationFields', $form, ['confirmations' => $confirmations], 'default');

    expect(gfcmFormData($form))->toBe([]);
})->with([
    'none' => [[]],
    'not a list' => ['x'],
    'absent' => [null],
]);

it('identifies each confirmation being mapped while it is mapped', function (): void {
    $manager = new class extends FormConfigurationManager
    {
        /**
         * The contexts seen while mapping confirmation fields.
         *
         * @var list<mixed>
         */
        public array $seen = [];

        /**
         * Record the context, then map as the manager does.
         *
         * @param  array<int|string, mixed>  $confirmation  The confirmation
         * @param  array<int|string, mixed>  $formData  The form data
         * @param  string  $siteHandle  The site handle
         * @param  ?AssetImporter  $assetImporter  The asset importer
         * @param  array<int|string, mixed>  $formFields  The form fields
         * @param  array<int|string, mixed>  $mappingConfigs  The mapping
         * @param  callable  $valueProcessor  The value processor
         * @param  bool  $isDefaultConfirmation  Whether it is the default confirmation
         * @return array<int|string, mixed> The mapped data
         */
        protected function processConfirmationFieldMapping(array $confirmation, array $formData, string $siteHandle, $assetImporter, array $formFields, array $mappingConfigs, callable $valueProcessor, bool $isDefaultConfirmation = false): array
        {
            $this->seen[] = ProcessingContext::getFormConfigContext();

            return parent::processConfirmationFieldMapping($confirmation, $formData, $siteHandle, $assetImporter, $formFields, $mappingConfigs, $valueProcessor, $isDefaultConfirmation);
        }
    };

    gfcmCall($manager, 'mapConfirmationFields', gfcmForm('contact'), ['confirmations' => [
        ['event' => 'form_submission', 'isDefault' => true],
        ['event' => 'form_submission'],
        ['event' => 'form_submission', 'name' => 'Named'],
    ]], 'default');
    gfcmCall($manager, 'mapConfirmationFields', gfcmForm('contact'), ['id' => 'f', 'confirmations' => [['event' => 'form_submission', 'isDefault' => true, 'name' => 'Main'], ['event' => 'form_submission', 'name' => 7]]], 'default');
    gfcmCall($manager, 'mapConfirmationFields', gfcmForm('contact'), ['confirmations' => [['event' => 'form_submission', 'isDefault' => true, 'name' => 8]]], 'default');

    expect($manager->seen)->toBe([
        ['form_id' => '?', 'source' => 'confirmation', 'source_name' => 'Default', 'plugin' => 'Gravity Forms'],
        ['form_id' => '?', 'source' => 'confirmation', 'source_name' => 'Additional', 'plugin' => 'Gravity Forms'],
        ['form_id' => '?', 'source' => 'confirmation', 'source_name' => 'Named', 'plugin' => 'Gravity Forms'],
        ['form_id' => 'f', 'source' => 'confirmation', 'source_name' => 'Main', 'plugin' => 'Gravity Forms'],
        ['form_id' => 'f', 'source' => 'confirmation', 'source_name' => '7', 'plugin' => 'Gravity Forms'],
        ['form_id' => '?', 'source' => 'confirmation', 'source_name' => '8', 'plugin' => 'Gravity Forms'],
    ]);
});

it('configures and saves a form from its settings, confirmations and notifications', function (): void {
    $form = gfcmForm('gf_contact');

    $saved = $this->manager->configureFormEmailSettings($form, [
        'id' => 12,
        'title' => 'Contact Us',
        'description' => '<p>Write to <b>us</b></p>',
        'button' => ['text' => 'Send'],
        'limitEntries' => true,
        'limitEntriesCount' => 5,
        'limitEntriesPeriod' => '',
        'limitEntriesMessage' => 'Full',
        'scheduleForm' => 'false',
        'scheduleStart' => '08/19/2025',
        'scheduleStartHour' => '9',
        'scheduleStartMinute' => '05',
        'scheduleStartAmpm' => 'am',
        'scheduleEnd' => '',
        'requireLogin' => 'true',
        'gravityformsquiz' => ['grading' => 'letter', 'passPercent' => '70', 'grades' => [['text' => 'A', 'value' => 90]]],
        'fields' => gfcmFields(),
        'confirmations' => [['event' => 'form_submission', 'isDefault' => true, 'type' => 'message']],
        'notifications' => [['id' => 'admin', 'toType' => 'email', 'to' => 'office@uams.edu']],
    ], 'default', $this->importer);

    $data = gfcmFormData($form);
    $email = $form->email();

    expect($saved)->toBeTrue()
        ->and(\array_intersect_key($data, \array_flip(['description', 'heading', 'submit', 'limit_entries', 'limit_entries_count', 'limit_entries_period', 'limit_entries_message', 'schedule_form', 'require_login_query', 'quiz_grading', 'quiz_pass_percent', 'quiz_grades', 'confirmation_settings_default'])))->toBe([
            // The confirmations are mapped while the settings are, and land first.
            'confirmation_settings_default' => ['confirmation_type' => 'message'],
            'description' => 'Write to us',
            'heading' => 'Contact Us',
            'submit' => 'Send',
            'limit_entries' => true,
            'limit_entries_count' => 5,
            'limit_entries_period' => 'total',
            'limit_entries_message' => 'Full',
            'schedule_form' => ['start_datetime' => '2025-08-19 09:05'],
            'require_login_query' => true,
            'quiz_grading' => 'letter',
            'quiz_pass_percent' => 70,
            'quiz_grades' => [['text' => 'A', 'value' => 90]],
        ])
        ->and(\array_key_exists('schedule_form_query', $data))->toBeFalse()
        ->and(\is_array($email) ? \array_column($email, 'to') : null)->toBe(['office@uams.edu']);
});
