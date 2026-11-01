<?php

declare(strict_types=1);

/**
 * The Ninja Forms configuration manager's action, confirmation and payment readers (#98).
 *
 * `NinjaFormsNotificationTest` covers merge-tag translation and the email mapping, and the
 * `NinjaForms*` integration tests cover full imports. This file calls the manager's
 * protected and private methods directly for what those leave open: malformed `actions`
 * and `fields`, inactive actions, the confirmation precedence, the payment record, and the
 * contract methods Ninja Forms implements as no-ops.
 *
 * @command  php vendor/bin/pest tests/Integration/NinjaFormsConfigurationManagerTest.php
 */

use Statamic\Facades\Form as FormFacade;
use Statamic\Forms\Form;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms\FormConfigurationManager;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * A configuration manager that records the asset importer its plugin hook receives.
 */
final class NinjaConfigurationRecorder extends FormConfigurationManager
{
    /**
     * The asset importers the plugin hook was handed, in call order.
     *
     * @var list<mixed>
     */
    public array $importersSeen = [];

    /**
     * Record the importer, then defer to the real hook.
     *
     * @param  string  $statamicKey  The Statamic field handle
     * @param  mixed  $value  The original value
     * @param  array<int|string, mixed>  $formData  The form data
     * @param  string  $siteHandle  The Statamic site handle
     * @param  mixed  $assetImporter  The asset importer
     * @return mixed The processed value
     */
    protected function processPluginSpecificField(string $statamicKey, $value, array $formData, string $siteHandle, $assetImporter = null): mixed
    {
        $this->importersSeen[] = $assetImporter;

        return parent::processPluginSpecificField($statamicKey, $value, $formData, $siteHandle, $assetImporter);
    }
}

/**
 * Call a protected or private method on a configuration manager.
 *
 * @param  string  $method  The method name
 * @param  mixed  ...$args  The method's arguments
 * @return mixed What the method returned
 */
function ncmCall(string $method, mixed ...$args): mixed
{
    $manager = new FormConfigurationManager;

    return (new ReflectionMethod($manager, $method))->invoke($manager, ...$args);
}

/**
 * A fresh, unsaved Statamic form.
 *
 * @param  string  $handle  The form handle
 * @return Form The form
 */
function ncmForm(string $handle): Form
{
    $form = FormFacade::make($handle);

    if (! $form instanceof Form) {
        throw new LogicException('FormFacade::make() returned no Statamic form.');
    }

    return $form;
}

/**
 * Run a callback with every PHP warning and notice turned into an exception.
 *
 * @param  Closure(): mixed  $callback  The code to run
 * @return mixed What the callback returned
 */
function ncmStrict(Closure $callback): mixed
{
    set_error_handler(static function (int $severity, string $message): never {
        throw new ErrorException($message, 0, $severity);
    });

    try {
        return $callback();
    } finally {
        restore_error_handler();
    }
}

/**
 * A minimal email action.
 *
 * @param  array<string, mixed>  $overrides  Keys to replace
 * @return array<string, mixed> The action
 */
function ncmEmail(array $overrides = []): array
{
    return $overrides + ['type' => 'email', 'active' => '1', 'label' => 'Admin', 'to' => 'admin@example.com', 'email_subject' => 'New entry', 'email_message' => 'Hello'];
}

it('hands the asset importer to the plugin hook only when it is one', function (): void {
    $importer = $this::createStub(AssetImporter::class);
    $export = ['fields' => [], 'actions' => [ncmEmail()]];

    $withImporter = new NinjaConfigurationRecorder;
    (new ReflectionMethod($withImporter, 'mapNotificationsToEmailConfigurations'))->invoke($withImporter, $export, 'default', $importer);

    $withOther = new NinjaConfigurationRecorder;
    (new ReflectionMethod($withOther, 'mapNotificationsToEmailConfigurations'))->invoke($withOther, $export, 'default', new stdClass);

    expect($withImporter->importersSeen)->not->toBe([])
        ->and(\array_unique(\array_map(static fn (mixed $seen): bool => $seen === $importer, $withImporter->importersSeen)))->toBe([true])
        ->and(\array_unique(\array_map(static fn (mixed $seen): bool => $seen === null, $withOther->importersSeen)))->toBe([true]);
});

it('finds the email actions among the others, in order', function (): void {
    $actions = ['not an action', ['type' => 'successmessage'], ncmEmail(['label' => 'First']), ['type' => 'sms'], ncmEmail(['label' => 'Second'])];

    $found = ncmCall('emailActions', ['actions' => $actions]);

    expect(\is_array($found) ? \array_column($found, 'label') : null)->toBe(['First', 'Second']);
});

it('reads no email actions, and raises no warning, when `actions` is not a list', function (): void {
    expect(ncmStrict(static fn (): mixed => ncmCall('emailActions', ['actions' => 'nope'])))->toBe([])
        ->and(ncmCall('emailActions', []))->toBe([]);
});

it('builds one email configuration per action, with or without a usable field list', function (mixed $fields): void {
    $export = ['actions' => [ncmEmail(), ncmEmail(['label' => 'Copy'])]];

    if ($fields !== 'absent') {
        $export['fields'] = $fields;
    }

    $configs = ncmCall('mapNotificationsToEmailConfigurations', $export, 'default');

    expect($configs)->toBeArray()->toHaveCount(2);
})->with([
    'no fields key' => ['absent'],
    'fields that are not a list' => ['nope'],
    'an empty list' => [[]],
]);

it('resolves merge tags only from real field keys and from repeater children', function (): void {
    $fields = [
        'not a field',
        // A key that is not a string is skipped.
        ['key' => 5, 'type' => 'textbox'],
        // A field that is not a repeater does not lend its nested fields.
        ['key' => 'notes_1', 'type' => 'textbox', 'fields' => [['key' => 'hidden_1', 'type' => 'textbox']]],
        ['key' => 'people_1', 'type' => 'repeater', 'fields' => [['key' => 'Guest-Name_1', 'type' => 'textbox']]],
        // A repeater whose `fields` is not a list contributes nothing rather than failing.
        ['key' => 'broken_1', 'type' => 'repeater', 'fields' => 'nope'],
    ];

    $text = ncmCall('processFieldMergeTags', '{field:Guest-Name_1} {field:hidden_1} {field:notes_1}', $fields);

    expect($text)->toBe('{{ guest_name_1 }} {field:hidden_1} {{ notes_1 }}');
});

it('turns a field key into a snake-case handle without stray separators', function (string $key, string $expected): void {
    expect(ncmCall('ninjaFieldHandle', $key))->toBe($expected);
})->with([
    'mixed case and hyphens' => ['Email-Address_1642', 'email_address_1642'],
    'leading and trailing separators' => ['--Phone.Number--', 'phone_number'],
]);

it('names a field by its label, or by nothing when that is not a string', function (): void {
    expect(ncmCall('getFieldNameFromField', ['label' => 'Email']))->toBe('Email')
        ->and(ncmCall('getFieldNameFromField', ['label' => 7]))->toBe('');
});

it('translates every WordPress system tag', function (): void {
    expect(ncmCall('translateFieldReferences', '{fields_table}|{wp:admin_email}|{wp:user_email}|{wp:site_title}|{wp:site_url}'))
        ->toBe('{{ all_fields }}|{{ config:mail:from:address }}|{{ user:email }}|{{ site:name }}|{{ config:app:url }}');
});

it('implements the engine hooks it has no use for as no-ops', function (): void {
    $data = ['title' => 'Kept'];
    $manager = new FormConfigurationManager;
    $hook = new ReflectionMethod($manager, 'mapPluginSpecificFields');
    $current = $data;
    $hook->invokeArgs($manager, [&$current, $data, 'default']);

    expect(ncmCall('getSubfieldHandleById', '1.3', []))->toBeNull()
        ->and(ncmCall('getSubfieldHandleFromMapping', 'name', 'First', []))->toBeNull()
        ->and(ncmCall('processSingleConfirmationView', [], [], 'default'))->toBeNull()
        ->and(ncmCall('processPluginSpecificField', 'to', 'x@example.com', [], 'default'))->toBe('x@example.com')
        ->and(ncmCall('processComplexFormatMappings', '{date}'))->toBe('{date}')
        ->and(ncmCall('processDateTimeField', 'date', '2026-01-01', []))->toBeNull()
        ->and(ncmCall('processDatetimeCombination', [], []))->toBeNull()
        ->and(ncmCall('convertRoutingRulesToStatamicFormat', [['field' => 1]], []))->toBe([])
        ->and(ncmCall('convertRoutingOperatorToStatamicFormat', 'is'))->toBe('is')
        ->and($current)->toBe($data);
});

it('confirms with a redirect first, then a message, then nothing', function (array $actions, array $expected): void {
    $form = ncmForm('ninja_confirmation_probe');

    ncmCall('mapConfirmationFields', $form, ['actions' => $actions, 'fields' => [['key' => 'name_1', 'type' => 'textbox']]], 'default');

    expect([
        'type' => $form->get('confirmation_type'),
        'redirect' => $form->get('confirmation_redirect_url'),
        'message' => $form->get('confirmation_message'),
    ])->toBe($expected);
})->with([
    'a redirect, trimmed, over a message' => [
        [['type' => 'successmessage', 'message' => 'Thanks'], ['type' => 'redirect', 'redirect_url' => '  /thanks  ']],
        ['type' => 'redirect', 'redirect' => '/thanks', 'message' => null],
    ],
    'a blank redirect falls back to the message' => [
        [['type' => 'redirect', 'redirect_url' => '   '], ['type' => 'successmessage', 'message' => '  Thanks {field:name_1}  ']],
        ['type' => 'message', 'redirect' => null, 'message' => '  Thanks {{ name_1 }}  '],
    ],
    'a redirect URL that is not a string falls back to the message' => [
        [['type' => 'redirect', 'redirect_url' => ['/thanks']], ['type' => 'successmessage', 'message' => 'Thanks']],
        ['type' => 'message', 'redirect' => null, 'message' => 'Thanks'],
    ],
    'an inactive redirect is ignored' => [
        [['type' => 'redirect', 'active' => '0', 'redirect_url' => '/thanks'], ['type' => 'successmessage', 'message' => 'Thanks']],
        ['type' => 'message', 'redirect' => null, 'message' => 'Thanks'],
    ],
    'a blank message writes nothing' => [
        [['type' => 'successmessage', 'message' => '   ']],
        ['type' => null, 'redirect' => null, 'message' => null],
    ],
    'a message that is not a string writes nothing' => [
        [['type' => 'successmessage', 'message' => 7]],
        ['type' => null, 'redirect' => null, 'message' => null],
    ],
    'no confirmation action writes nothing' => [
        [ncmEmail()],
        ['type' => null, 'redirect' => null, 'message' => null],
    ],
]);

it('stores submissions as the first save action says, and leaves the default without one', function (array $actions, ?bool $expected): void {
    $form = ncmForm('ninja_storage_probe');
    $default = $form->store();

    ncmCall('mapSubmissionStorage', $form, ['actions' => $actions]);

    expect($form->store())->toBe($expected ?? $default);
})->with([
    'an active save action' => [[['type' => 'save', 'active' => '1']], true],
    'an inactive save action' => [[['type' => 'save', 'active' => '0']], false],
    // An inactive email action ahead of it must not be read as the save action.
    'a save action after another action' => [[['type' => 'email', 'active' => '0'], ['type' => 'save', 'active' => '1']], true],
    'no save action' => [[ncmEmail()], null],
]);

it('reads an action as inactive only for the values Ninja Forms writes for off', function (mixed $active, bool $expected): void {
    $action = ['type' => 'email'];

    if ($active !== 'absent') {
        $action['active'] = $active;
    }

    expect(ncmCall('isActionActive', $action))->toBe($expected);
})->with([
    'no flag' => ['absent', true],
    "'1'" => ['1', true],
    'true' => [true, true],
    'int 1' => [1, true],
    // A loose comparison would read the numeric string '0.0' as '0'.
    "'0.0'" => ['0.0', true],
    "'0'" => ['0', false],
    'int 0' => [0, false],
    'false' => [false, false],
    "''" => ['', false],
]);

it('records the payment configuration of the first active payment action', function (): void {
    $form = ncmForm('ninja_payment_probe');
    $fields = [
        'not a field',
        // A field with no key comes first, so the scan has to continue past it.
        ['type' => 'listselect', 'options' => [['value' => 'x', 'calc' => '1']]],
        ['key' => 'no_options_1', 'type' => 'textbox', 'options' => 'nope'],
        ['key' => 'ticket_1', 'type' => 'listselect', 'options' => [
            'not an option',
            // Options with no usable price come first, so the scan has to continue past them.
            ['value' => 'free', 'calc' => ''],
            ['value' => 'none'],
            ['value' => 'boolean', 'calc' => false],
            ['value' => 'listed', 'calc' => ['5']],
            ['value' => ['x'], 'calc' => '5'],
            ['value' => 'adult', 'calc' => 25],
            ['value' => 1.5, 'calc' => '10.50'],
        ]],
        ['key' => 'unpriced_1', 'type' => 'listselect', 'options' => [['value' => 'a']]],
        ['key' => 'people_1', 'type' => 'repeater', 'fields' => [['key' => 'guest_1', 'type' => 'textbox']]],
        ['key' => '10', 'type' => 'textbox'],
    ];
    $actions = [
        ['type' => 'collectpayment', 'active' => '0', 'payment_gateways' => 'inactive'],
        ['type' => 'collectpayment', 'active' => '1', 'payment_gateways' => 7, 'payment_total' => 12.5, 'payment_total_type' => 1, 'nfelavon_field_map' => [
            'not a row',
            ['field_map' => '   ', 'form_field' => '{field:ticket_1}'],
            ['field_map' => ['x'], 'form_field' => '{field:ticket_1}'],
            ['field_map' => 'amount', 'form_field' => '  '],
            ['field_map' => 'amount', 'form_field' => 5],
            ['field_map' => 'name', 'form_field' => '{field:guest_1}'],
            ['field_map' => 'total', 'form_field' => '{field:ticket_1} + {field:deleted_9}'],
            // A loose comparison would read '1e1' as the known key '10'.
            ['field_map' => 'code', 'form_field' => '{field:1e1}'],
            ['field_map' => 'note', 'form_field' => 'plain text'],
        ]],
    ];

    ncmCall('carryPaymentConfiguration', $form, ['fields' => $fields, 'actions' => $actions]);

    expect($form->get('wordpress_payment_import'))->toBe([
        'plugin' => 'Ninja Forms',
        'status' => "Recorded from the WordPress form. Not configured in Statamic \u{2014} see UAMS-Web/uams-statamic#48.",
        'gateway' => '7',
        'amount_source' => '12.5',
        'amount_source_type' => '1',
        'price_table' => ['ticket_1' => ['adult' => '25', '1.5' => '10.50']],
        'observed_field_map' => [
            ['gateway_field' => 'name', 'source' => '{field:guest_1}', 'unresolved_references' => []],
            ['gateway_field' => 'total', 'source' => '{field:ticket_1} + {field:deleted_9}', 'unresolved_references' => ['deleted_9']],
            ['gateway_field' => 'code', 'source' => '{field:1e1}', 'unresolved_references' => ['1e1']],
            ['gateway_field' => 'note', 'source' => 'plain text', 'unresolved_references' => []],
        ],
    ]);
});

it('records no gateway, amount or type that is not a single value, and no field map that is not a list', function (): void {
    $form = ncmForm('ninja_payment_probe_2');

    ncmCall('carryPaymentConfiguration', $form, ['actions' => [['type' => 'collectpayment', 'payment_gateways' => ['a'], 'payment_total' => ['b'], 'payment_total_type' => ['c'], 'nfelavon_field_map' => 'nope']]]);

    $record = $form->get('wordpress_payment_import');

    expect(\is_array($record) ? [$record['gateway'], $record['amount_source'], $record['amount_source_type'], $record['price_table'], $record['observed_field_map']] : null)
        ->toBe([null, null, null, [], []]);
});

it('records no payment configuration without an active payment action', function (array $actions): void {
    $form = ncmForm('ninja_payment_probe_3');

    ncmCall('carryPaymentConfiguration', $form, ['actions' => $actions]);

    expect($form->get('wordpress_payment_import'))->toBeNull();
})->with([
    'none' => [[ncmEmail()]],
    'an inactive one' => [[['type' => 'collectpayment', 'active' => '0']]],
]);

it('hands back an export with no settings envelope unchanged', function (): void {
    $export = ['title' => 'Contact', 'settings' => 'nope'];

    expect(ncmCall('parseFormData', $export))->toBe($export);
});

it('finds no action at all when `actions` is not a list', function (): void {
    $form = ncmForm('ninja_no_actions_probe');
    $default = $form->store();

    ncmCall('mapSubmissionStorage', $form, ['actions' => 'nope']);
    ncmCall('mapConfirmationFields', $form, ['actions' => 'nope'], 'default');
    ncmCall('carryPaymentConfiguration', $form, ['actions' => 'nope']);

    expect($form->store())->toBe($default)
        ->and($form->get('confirmation_type'))->toBeNull()
        ->and($form->get('wordpress_payment_import'))->toBeNull();
});

it('keeps an all-digit field key resolvable when a repeater follows it', function (): void {
    // PHP stores the key '10' as the int 10, which array_merge() would renumber to 0.
    $fields = [
        ['key' => '10', 'type' => 'textbox'],
        ['key' => 'people_1', 'type' => 'repeater', 'fields' => [['key' => 'guest_1', 'type' => 'textbox']]],
    ];

    expect(ncmCall('processFieldMergeTags', '{field:10} {field:0} {field:guest_1}', $fields))->toBe('{{ 10 }} {field:0} {{ guest_1 }}');
});

it('records a field-map reference to any key the form carries', function (): void {
    // The empty-key check must exclude only ''. This key is the mutation tool's own
    // placeholder for '', which the field-map pattern can reference because it accepts
    // spaces and punctuation.
    $form = ncmForm('ninja_payment_probe_4');

    ncmCall('carryPaymentConfiguration', $form, [
        'fields' => [['key' => 'PEST Mutator was here!', 'type' => 'textbox']],
        'actions' => [['type' => 'collectpayment', 'nfelavon_field_map' => [['field_map' => 'note', 'form_field' => '{field:PEST Mutator was here!}']]]],
    ]);

    $record = $form->get('wordpress_payment_import');

    expect(\is_array($record) ? $record['observed_field_map'] : null)->toBe([
        ['gateway_field' => 'note', 'source' => '{field:PEST Mutator was here!}', 'unresolved_references' => []],
    ]);
});
