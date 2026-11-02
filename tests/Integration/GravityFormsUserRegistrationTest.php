<?php

declare(strict_types=1);

use Statamic\Contracts\Forms\Form as FormContract;
use Statamic\Facades\Form as FormFacade;
use Statamic\Forms\Form as ConcreteForm;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Creators\FormCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\ContentDetector;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\DataManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FormIdExtractor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\GravityFormsCoordinator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\UserInteractionHandler;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\GravityForms\Selector as GravityFormsSelector;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\GravityForms\Validator as GravityFormsValidator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\Loaders\GravityFormsLoader;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

/**
 * Proves the user-registration configuration survives a real Statamic form (issue wordpress-importer-archive#821).
 *
 * The unit tests drive the decision — which feed registers users, with what mapping. This
 * drives the part that cannot be asserted in isolation: that an arbitrary key set on a
 * `Statamic\Forms\Form` is persisted by `save()` and read back by `hydrate()`. That property
 * is the whole reason `wordpress-importer-archive#821` can carry the configuration without a host-app change, and it
 * belongs to Statamic rather than to this package — so it is proven against a real form
 * rather than assumed from reading `ContainsData`.
 */
uses(StatamicTestCase::class);

/**
 * One served feed row, with overrides merged last.
 *
 * @param  array<string, mixed>  $overrides  Keys to replace
 * @return array<string, mixed> The feed row
 */
function servedRegistrationFeed(array $overrides = []): array
{
    return array_merge([
        'id' => 7,
        'form_id' => 10,
        'addon_slug' => 'gravityformsuserregistration',
        'is_active' => true,
        'feed_order' => 0,
        'event_type' => null,
        'meta' => [
            'feedName' => 'Create an account',
            'feedType' => 'create',
            'username' => '3',
            'email' => '2',
            'password' => '4',
            'role' => 'subscriber',
        ],
        'denied' => ['total' => 0, 'by_name' => 0, 'by_shape' => 0],
    ], $overrides);
}

/**
 * A FormCreator that can name a form.
 *
 * `createForm()` returns null without a coordinator, because `getFormTitle()` reads the title
 * key from `getFormTitleAttributeName()`. Building the real `GravityFormsCoordinator` would
 * drag in a prompt selector and three collaborators none of these tests exercise, so this
 * supplies the one method the path calls — and supplies Gravity Forms' real answer, `title`,
 * so the stub cannot pass while the coordinator would fail.
 *
 * @return FormCreator The creator
 */
function gravityFormCreator(): FormCreator
{
    return new FormCreator(null, new GravityFormsCoordinator(
        new ContentDetector,
        new FormIdExtractor,
        new DataManager,
        new UserInteractionHandler(new ContentDetector, new DataManager, new GravityFormsSelector(new GravityFormsValidator, new GravityFormsLoader)),
    ));
}

/**
 * The `user_registration` value carried by a saved form, read back through the repository.
 *
 * `get()` lives on the concrete `Statamic\Forms\Form` via `ContainsData` and is NOT on the
 * `Contracts\Forms\Form` interface the facade returns — so the concrete type is asserted
 * here rather than assumed. That gap is worth knowing: a consumer typed against the contract
 * cannot read this key without the same narrowing.
 *
 * @param  ?FormContract  $form  The form as createForm() returned it
 * @return mixed The carried configuration, or null
 */
function carriedRegistration(?FormContract $form): mixed
{
    expect($form)->not->toBeNull();
    assert($form instanceof FormContract);

    $reloaded = FormFacade::find($form->handle());

    expect($reloaded)->not->toBeNull();
    assert($reloaded instanceof ConcreteForm);

    return $reloaded->get('user_registration');
}

it('writes the registration configuration onto the form and reads it back', function (): void {
    $form = gravityFormCreator()->createForm(
        ['id' => 10, 'title' => 'Registration'],
        'Patients Learn',
        'patientslearn',
        'gravity-forms',
        [servedRegistrationFeed()],
    );

    $carried = carriedRegistration($form);

    expect($carried)->toBe([
        'creates_user' => true,
        'feed_id' => 7,
        'source_form_id' => 10,
        'feed_type' => 'create',
        'role' => 'subscriber',
        'field_map' => [
            'username' => '3',
            'email' => '2',
            'password' => '4',
        ],
    ]);
});

it('leaves a form with no feeds as an ordinary form', function (): void {
    $form = gravityFormCreator()->createForm(
        ['id' => 11, 'title' => 'Contact'],
        'Patients Learn',
        'patientslearn',
        'gravity-forms',
    );

    expect(carriedRegistration($form))->toBeNull();
});

it('leaves a form whose registration feed is disabled as an ordinary form', function (): void {
    // The acceptance criterion this covers is the dangerous one: a feed the site switched
    // off must not import as one that is silently on.
    $form = gravityFormCreator()->createForm(
        ['id' => 12, 'title' => 'Old Registration'],
        'Patients Learn',
        'patientslearn',
        'gravity-forms',
        [servedRegistrationFeed(['is_active' => false])],
    );

    expect(carriedRegistration($form))->toBeNull();
});

it('picks the registration feed out of a form carrying several add-ons', function (): void {
    $form = gravityFormCreator()->createForm(
        ['id' => 13, 'title' => 'Registration And Payment'],
        'Patients Learn',
        'patientslearn',
        'gravity-forms',
        [
            ['id' => 1, 'form_id' => 13, 'addon_slug' => 'gravityformsstripe', 'is_active' => true, 'meta' => []],
            servedRegistrationFeed(['form_id' => 13]),
        ],
    );

    $carried = carriedRegistration($form);

    expect($carried)->not->toBeNull();
    assert(is_array($carried));
    expect($carried['creates_user'])->toBeTrue();
});

it('writes no submitted value, only field references', function (): void {
    // wordpress-importer-archive#821's AC4: a registration import moves configuration, never credentials. The API
    // withholds credential-shaped values upstream; this asserts the importer adds none.
    $form = gravityFormCreator()->createForm(
        ['id' => 14, 'title' => 'Registration'],
        'Patients Learn',
        'patientslearn',
        'gravity-forms',
        [servedRegistrationFeed([
            'meta' => [
                'feedType' => 'create',
                'username' => '3',
                'password' => '4',
                'someLeakedSecret' => 'hunter2-should-never-travel',
            ],
        ])],
    );

    $carried = carriedRegistration($form);
    $serialised = json_encode($carried, JSON_THROW_ON_ERROR);

    assert(is_array($carried));

    expect($serialised)->not->toContain('hunter2-should-never-travel')
        ->and($carried['field_map'])->toBe(['username' => '3', 'password' => '4']);
});
