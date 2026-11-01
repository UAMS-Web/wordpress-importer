<?php

declare(strict_types=1);

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms\FormBlueprintCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms\FormConfigurationManager;

/**
 * Ninja Forms notification and merge-tag handling (#10, phase 4).
 *
 * Each importer finds notifications somewhere different, and Ninja is a third shape:
 * Gravity Forms uses `notifications[]`, Formidable filters `views[]` by `excerpt`, and
 * Ninja filters `actions[]` by `type=email`. That array also holds `save`,
 * `successmessage`, `redirect` and `collectpayment` entries, so the type filter is what
 * separates a notification from a submission behavior.
 *
 * Merge tags are the other divergence: Ninja references a field by its **`key`**, and
 * top-level fields carry no `id` at all, so the trait's id-based resolution cannot work.
 *
 * @command  vendor/bin/pest --testsuite=Unit tests/Unit/NinjaFormsNotificationTest.php
 */

/**
 * Call a protected method on the manager.
 */
function nfConfig(string $method, mixed ...$args): mixed
{
    $manager = new FormConfigurationManager;
    $reflected = (new ReflectionClass($manager))->getMethod($method);
    $reflected->setAccessible(true);

    return $reflected->invoke($manager, ...$args);
}

/**
 * Call a protected method and narrow the result to an array.
 *
 * @return array<int|string, mixed>
 */
function nfConfigArray(string $method, mixed ...$args): array
{
    $result = nfConfig($method, ...$args);

    return \is_array($result) ? $result : [];
}

/**
 * Read the email action mapping constant, narrowed to an array.
 *
 * @return array<string, array<string, mixed>>
 */
function nfEmailMapping(): array
{
    /** @var array<string, array<string, mixed>> $mapping */
    $mapping = (new ReflectionClass(FormConfigurationManager::class))
        ->getConstant('FORM_CONFIGURATION_ACTION_MAPPING_EMAIL');

    return $mapping;
}

/**
 * An export shaped like Love Lights: six actions, only two of them enabled.
 *
 * @return array<string, mixed>
 */
function nfExportWithActions(): array
{
    return [
        'settings' => ['title' => 'Love Lights'],
        'fields' => [
            ['key' => 'email_1623450939394', 'type' => 'email', 'label' => 'Email'],
            ['key' => 'participants_1', 'type' => 'repeater', 'label' => 'Participants', 'fields' => [
                ['key' => 'signature_1', 'type' => 'textbox', 'label' => 'Signature'],
            ]],
        ],
        'actions' => [
            ['type' => 'save', 'active' => 0, 'label' => 'Store Submission'],
            ['type' => 'collectpayment', 'active' => 0, 'label' => 'Collect Payment'],
            ['type' => 'successmessage', 'active' => 0, 'label' => 'Success Message'],
            ['type' => 'email', 'active' => 1, 'label' => 'Admin Email', 'to' => '{wp:admin_email}', 'email_subject' => 'UAMS Love Lights'],
            ['type' => 'email', 'active' => 0, 'label' => 'Customer Email', 'to' => '{field:email_1623450939394}'],
            ['type' => 'redirect', 'active' => 1, 'label' => 'Redirect'],
        ],
    ];
}

it('finds notifications only among the email actions', function (): void {
    // Six actions, two of which are emails. Counting all six would treat a redirect and
    // a payment action as notifications.
    $actions = nfConfigArray('emailActions', nfExportWithActions());

    expect($actions)->toHaveCount(2);
    expect(\array_column($actions, 'label'))->toBe(['Admin Email', 'Customer Email']);
});

it('reports whether a form has notifications at all', function (array $export, bool $expected): void {
    expect(nfConfig('hasNotifications', $export))->toBe($expected);
})->with([
    'has email actions' => [nfExportWithActions(), true],
    'no actions key' => [['settings' => [], 'fields' => []], false],
    'only non-email actions' => [['actions' => [['type' => 'redirect', 'active' => 1]]], false],
]);

it('lifts settings to the top level so the engine can read them', function (): void {
    // The engine reads FORM_CONFIGURATION_MAPPING keys off the top level, but a .nff
    // nests them under `settings`.
    $parsed = nfConfigArray('parseFormData', nfExportWithActions());

    expect($parsed['title'])->toBe('Love Lights');
    expect($parsed)->toHaveKey('actions');
});

it('translates a field merge tag by key, not by id', function (): void {
    // Ninja references `{field:<key>}`; top-level Ninja fields have no `id` at all.
    $result = nfConfig(
        'processFieldMergeTags',
        'Reply to {field:email_1623450939394} please',
        nfExportWithActions()['fields']
    );

    expect($result)->toBe('Reply to {{ email_1623450939394 }} please');
});

it('resolves a merge tag naming a field inside a repeater', function (): void {
    // Six of the 23 production field types exist only inside repeater sets, so merge-tag
    // resolution has to recurse.
    $result = nfConfig(
        'processFieldMergeTags',
        'Signed: {field:signature_1}',
        nfExportWithActions()['fields']
    );

    expect($result)->toBe('Signed: {{ signature_1 }}');
});

it('leaves an unresolvable merge tag verbatim rather than blanking it', function (): void {
    // ---
    // The corpus contains references to deleted fields — the `{calc:Total}` equation in
    // both payment forms names an operand that exists in neither. Emptying those would
    // hide the breakage; leaving them visible surfaces it.
    // ---
    $result = nfConfig(
        'processFieldMergeTags',
        'Total {field:donate_additional_amount_1623685178335}',
        nfExportWithActions()['fields']
    );

    expect($result)->toBe('Total {field:donate_additional_amount_1623685178335}');
});

it('translates the wp system tags', function (): void {
    $result = nfConfig('translateFieldReferences', 'Send to {wp:admin_email}', []);

    expect($result)->toBe('Send to {{ config:mail:from:address }}');
});

it('translates field and system tags together', function (): void {
    $result = nfConfig(
        'translateFieldReferences',
        '{wp:admin_email} and {field:email_1623450939394}',
        nfExportWithActions()['fields']
    );

    expect($result)->toBe('{{ config:mail:from:address }} and {{ email_1623450939394 }}');
});

it('maps the active flag, which decides whether mail actually sends', function (): void {
    // Love Lights has four of six actions switched off. Importing them all as enabled
    // would send mail the live form does not.
    $mapping = nfEmailMapping();

    expect($mapping['active']['statamic_attribute'])->toBe('enabled');
});

it('maps Ninja\'s own sender key names', function (): void {
    // Ninja spells these `from_address` / `from_name`; Gravity Forms uses `from` /
    // `fromName`, so the keys cannot be shared.
    $mapping = nfEmailMapping();

    expect($mapping['from_address']['statamic_attribute'])->toBe('from');
    expect($mapping['from_name']['statamic_attribute'])->toBe('from_name');
    expect($mapping['email_subject']['statamic_attribute'])->toBe('subject');
    expect($mapping['email_message']['statamic_attribute'])->toBe('message');
});

it('resolves merge tags to the same handles the blueprint creator generates', function (): void {
    // ---
    // The load-bearing invariant of this slice. The two classes derive handles
    // independently — they share no base — so a divergence would make every merge tag
    // point at a field the blueprint never created. Nothing would report that: the
    // notification would just render an empty value.
    // ---
    $field = ['key' => 'email_1623450939394', 'type' => 'email', 'label' => 'Email'];

    $creator = new FormBlueprintCreator;
    $generate = (new ReflectionClass($creator))->getMethod('generateFieldHandle');
    $generate->setAccessible(true);
    $blueprintHandle = $generate->invoke($creator, $field);

    $mergeTagHandle = nfConfig('ninjaFieldHandle', 'email_1623450939394');

    expect($mergeTagHandle)->toBe($blueprintHandle);
});

it('generates a stable handle, not a random one', function (): void {
    // Ninja top-level fields carry no `id`, so the shared trait would fall through to
    // `field_<type>_<random>` — a different blueprint on every import.
    $creator = new FormBlueprintCreator;
    $generate = (new ReflectionClass($creator))->getMethod('generateFieldHandle');
    $generate->setAccessible(true);
    $field = ['key' => 'email_1623450939394', 'type' => 'email', 'label' => 'Email'];

    expect($generate->invoke($creator, $field))->toBe($generate->invoke($creator, $field));
    expect($generate->invoke($creator, $field))->toBe('email_1623450939394');
});
