<?php

declare(strict_types=1);

use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Facades\Form as FormFacade;
use Statamic\Fields\Blueprint;
use Statamic\Forms\Form;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms\DataManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms\NinjaFormsCoordinator;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * The Elavon payment action's configuration survives the import (issue wordpress-importer-archive#652).
 *
 * Statamic has no payment infrastructure, so nothing here is applied — this records what
 * WordPress does today so it is not lost before someone can act on it. The Statamic-side
 * decision is `UAMS-Web/uams-statamic#48` and the implementation `#295`; neither is
 * needed to preserve configuration, which is why this did not wait on them.
 *
 * Exactly one production form is affected — Miracle Stars, the only one with an active
 * `collectpayment` action. Love Lights carries a copy that is switched off *and* names a
 * gateway whose plugin is not installed on its subsite: dead configuration inherited by
 * duplicating Miracle Stars.
 *
 * @command  php vendor/bin/pest tests/Integration/NinjaFormsPaymentConfigCarryTest.php
 */
uses(StatamicTestCase::class);

/**
 * The handle FormCreator composes for this file's fixture form.
 */
const NINJA_PAYMENT_HANDLE = 'default_ninja-forms_31_payment-form';

/**
 * The Miracle Stars field shape: a priced quantity select plus the mapped card fields.
 *
 * @return array<int, array<string, mixed>>
 */
function ninjaPaymentFields(): array
{
    return [
        [
            'type' => 'listselect',
            'key' => 'number_of_miracle_stars_1637610265081',
            'label' => 'Number of Miracle Stars',
            // The price table: value is the quantity, calc is the price — $4 per star.
            'options' => [
                ['label' => '1', 'value' => '1', 'calc' => '4', 'order' => 0],
                ['label' => '2', 'value' => '2', 'calc' => '8', 'order' => 1],
            ],
        ],
        ['type' => 'firstname', 'key' => 'cardholder_first_name_1623682985623', 'label' => 'First Name'],
        ['type' => 'lastname', 'key' => 'cardholder_last_name_1623682977301', 'label' => 'Last Name'],
        ['type' => 'address', 'key' => 'address_1623683683568', 'label' => 'Address'],
    ];
}

/**
 * Import a form carrying the given payment action, and return the carried record.
 *
 * @param  array<string, mixed>|null  $paymentAction  The collectpayment action, or null
 * @param  AssetImporter  $assetImporter  Asset importer for any referenced media
 * @return array<int|string, mixed>|null The carried payment record, or null when absent
 */
function importNinjaPaymentConfig(?array $paymentAction, AssetImporter $assetImporter): ?array
{
    forgetNinjaPaymentArtifacts();

    $actions = [
        ['type' => 'successmessage', 'active' => '1', 'label' => 'Thanks', 'message' => '<p>Thanks.</p>'],
    ];

    if ($paymentAction !== null) {
        $actions[] = $paymentAction;
    }

    $dataManager = app(DataManager::class);
    $dataManager->clearNinjaFormsData();
    $dataManager->addNinjaForm(31, [
        'settings' => ['title' => 'Payment Form'],
        'fields' => ninjaPaymentFields(),
        'actions' => $actions,
    ]);

    app(NinjaFormsCoordinator::class)->createNinjaFormsFormIfNeeded(
        31,
        'Default',
        'default',
        $assetImporter
    );

    $form = FormFacade::find(NINJA_PAYMENT_HANDLE);

    expect($form)->toBeInstanceOf(Form::class);

    $carried = $form instanceof Form ? $form->get('wordpress_payment_import') : null;

    return \is_array($carried) ? $carried : null;
}

/**
 * A `collectpayment` action in the production shape.
 *
 * @param  string  $active  The action's active flag
 * @param  string  $addressSource  The CardholderAddress row's source text
 * @return array<string, mixed>
 */
function ninjaPaymentAction(string $active = '1', string $addressSource = '{field:address_1623683683568}'): array
{
    return [
        'type' => 'collectpayment',
        'active' => $active,
        'label' => 'Collect Payment',
        'payment_gateways' => 'elavon',
        'payment_total' => '{calc:Total}',
        'payment_total_type' => 'calc',
        'nfelavon_field_map' => [
            ['field_map' => 'FirstName', 'form_field' => '{field:cardholder_first_name_1623682985623}'],
            ['field_map' => 'CardholderAddress', 'form_field' => $addressSource],
            // Ninja skips a row with either half blank; the record should too.
            ['field_map' => '', 'form_field' => ''],
        ],
    ];
}

/**
 * Delete the form and blueprint this file creates.
 */
function forgetNinjaPaymentArtifacts(): void
{
    $form = FormFacade::find(NINJA_PAYMENT_HANDLE);

    if ($form instanceof Form) {
        $form->delete();
    }

    $blueprint = BlueprintFacade::find('forms.'.NINJA_PAYMENT_HANDLE);

    if ($blueprint instanceof Blueprint) {
        $blueprint->delete();
    }
}

afterEach(function (): void {
    app(DataManager::class)->clearNinjaFormsData();
    forgetNinjaPaymentArtifacts();
});

it('carries the gateway and amount source from an active payment action', function (): void {
    $carried = importNinjaPaymentConfig(ninjaPaymentAction(), $this->createMock(AssetImporter::class));

    expect($carried)->toBeArray()
        ->and($carried['plugin'] ?? null)->toBe('Ninja Forms')
        ->and($carried['gateway'] ?? null)->toBe('elavon')
        ->and($carried['amount_source'] ?? null)->toBe('{calc:Total}')
        ->and($carried['amount_source_type'] ?? null)->toBe('calc')
        // Labelled as a record, not as configuration Statamic acts on.
        ->and($carried['status'] ?? null)->toContain('Not configured in Statamic');
});

it('carries the per-option price table, which the choice-list mapper drops', function (): void {
    $carried = importNinjaPaymentConfig(ninjaPaymentAction(), $this->createMock(AssetImporter::class));

    $table = $carried['price_table'] ?? null;

    expect($table)->toBeArray();

    $prices = \is_array($table) ? ($table['number_of_miracle_stars_1637610265081'] ?? null) : null;

    // The amount equation reads like arithmetic over fields; the prices are in the options.
    // Without this record the amount rule cannot be reconstructed from the imported form.
    expect($prices)->toBe([
        '1' => '4',
        '2' => '8',
    ]);
});

it('flags a field-map reference that resolves to nothing', function (): void {
    // The live defect: the map references a ZIP field key that does not exist, and Ninja
    // leaves an unresolvable tag in an action setting LITERAL rather than empty.
    $carried = importNinjaPaymentConfig(
        ninjaPaymentAction('1', '{field:address_1623683683568}, {field:creditcardzip_1623684352579}'),
        $this->createMock(AssetImporter::class)
    );

    $map = $carried['observed_field_map'] ?? null;

    expect($map)->toBeArray();

    $address = null;

    foreach (\is_array($map) ? $map : [] as $row) {
        if (\is_array($row) && ($row['gateway_field'] ?? null) === 'CardholderAddress') {
            $address = $row;
        }
    }

    expect($address)->toBeArray()
        ->and($address['unresolved_references'] ?? null)->toBe(['creditcardzip_1623684352579']);
});

it('records a fully-resolving row as having no unresolved references', function (): void {
    $carried = importNinjaPaymentConfig(ninjaPaymentAction(), $this->createMock(AssetImporter::class));

    $map = $carried['observed_field_map'] ?? null;

    $first = \is_array($map) ? ($map[0] ?? null) : null;

    expect($first)->toBeArray();

    // Bounding the flag on the other side: a good row must not be marked suspect.
    expect(\is_array($first) ? ($first['gateway_field'] ?? null) : null)->toBe('FirstName')
        ->and(\is_array($first) ? ($first['unresolved_references'] ?? null) : null)->toBe([]);
});

it('skips blank map rows, as Ninja itself does', function (): void {
    $carried = importNinjaPaymentConfig(ninjaPaymentAction(), $this->createMock(AssetImporter::class));

    $map = $carried['observed_field_map'] ?? null;

    // Two populated rows in the fixture; the blank third is not recorded.
    expect($map)->toBeArray()->toHaveCount(2);
});

it('carries nothing for an inactive payment action — the Love Lights shape', function (): void {
    // Love Lights' copy is switched off and names a gateway whose plugin is not even
    // installed on its subsite. Recording it would invent a payment form.
    $carried = importNinjaPaymentConfig(ninjaPaymentAction('0'), $this->createMock(AssetImporter::class));

    expect($carried)->toBeNull();
});

it('carries nothing for a form with no payment action at all', function (): void {
    // The negative control: the overwhelming majority of forms must be untouched, and an
    // empty payment record on every imported form would be its own defect.
    $carried = importNinjaPaymentConfig(null, $this->createMock(AssetImporter::class));

    expect($carried)->toBeNull();
});

it('leaves the confirmation and email import undisturbed', function (): void {
    importNinjaPaymentConfig(ninjaPaymentAction(), $this->createMock(AssetImporter::class));

    $form = FormFacade::find(NINJA_PAYMENT_HANDLE);

    // Bounding the change against wordpress-importer-archive#651's work, which shares the same entry point.
    expect($form instanceof Form ? $form->get('confirmation_type') : null)->toBe('message');
});
