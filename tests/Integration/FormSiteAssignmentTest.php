<?php

declare(strict_types=1);

use Statamic\Contracts\Forms\Form as FormContract;
use Statamic\Facades\Form as FormFacade;
use Statamic\Forms\Form as ConcreteForm;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Creators\FormCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\FormidableFormsCoordinator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\GravityFormsCoordinator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms\NinjaFormsCoordinator;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * Proves every imported form is saved assigned to the site it was imported for (issue wordpress-importer-archive#1379).
 *
 * statamic-multisite-forms reads a `sites` list off the form to decide where it renders, and
 * an absent list means every site. Its own listener cannot fill the key in for an import,
 * because a console run has no signed-in user, so the importer writes it. The value is read
 * back through the repository, so the test fails unless `save()` actually persisted it.
 */
uses(StatamicTestCase::class);

/**
 * A saved form, reloaded from the repository so the assertion reads what was persisted.
 *
 * @param  ?FormContract  $form  The form as createForm() returned it
 * @return ConcreteForm The reloaded form
 */
function reloadedImportedForm(?FormContract $form): ConcreteForm
{
    expect($form)->not->toBeNull();
    assert($form instanceof FormContract);

    $reloaded = FormFacade::find($form->handle());

    expect($reloaded)->not->toBeNull();
    assert($reloaded instanceof ConcreteForm);

    return $reloaded;
}

/**
 * A FormCreator wired with the real coordinator for one form plugin.
 *
 * The coordinator supplies the title key `createForm()` reads, so each plugin is driven
 * through its own answer rather than a stub that could pass where the real one fails.
 *
 * @param  string  $plugin  The plugin name, as the importer passes it to createForm()
 * @return FormCreator The creator
 */
function siteAssignmentFormCreator(string $plugin): FormCreator
{
    return new FormCreator(null, match ($plugin) {
        'gravity-forms' => app(GravityFormsCoordinator::class),
        'formidable-forms' => app(FormidableFormsCoordinator::class),
        'ninja-forms' => app(NinjaFormsCoordinator::class),
        default => throw new InvalidArgumentException("No coordinator for form plugin '{$plugin}'."),
    });
}

dataset('form plugins', [
    'Gravity Forms' => ['gravity-forms', 'title'],
    'Formidable Forms' => ['formidable-forms', 'name'],
    'Ninja Forms' => ['ninja-forms', 'title'],
]);

it('saves the form assigned to the site it was imported for', function (string $plugin, string $titleKey): void {
    $form = siteAssignmentFormCreator($plugin)->createForm(
        ['id' => 21, $titleKey => 'Contact Us'],
        'Patients Learn',
        'patientslearn',
        $plugin,
    );

    $reloaded = reloadedImportedForm($form);

    expect($reloaded->get('sites'))->toBe(['patientslearn'])
        // The rest of the form is written as before
        ->and($reloaded->handle())->toBe('patientslearn_'.$plugin.'_21_contact-us')
        ->and($reloaded->title())->toBe("Patients Learn \u{2014} Contact Us")
        ->and($reloaded->get('user_registration'))->toBeNull();
})->with('form plugins');

it('writes the same single site on a re-import', function (string $plugin, string $titleKey): void {
    $creator = siteAssignmentFormCreator($plugin);
    $formData = ['id' => 22, $titleKey => 'Appointment Request'];

    // Import the same form twice, as a second run over the same site would
    $creator->createForm($formData, 'Patients Learn', 'patientslearn', $plugin);
    $form = $creator->createForm($formData, 'Patients Learn', 'patientslearn', $plugin);

    expect(reloadedImportedForm($form)->get('sites'))->toBe(['patientslearn']);
})->with('form plugins');

it('assigns each site its own form when two sites import the same source form', function (): void {
    $creator = siteAssignmentFormCreator('gravity-forms');
    $formData = ['id' => 23, 'title' => 'Newsletter'];

    $first = $creator->createForm($formData, 'Patients Learn', 'patientslearn', 'gravity-forms');
    $second = $creator->createForm($formData, 'Health News', 'healthnews', 'gravity-forms');

    expect(reloadedImportedForm($first)->get('sites'))->toBe(['patientslearn'])
        ->and(reloadedImportedForm($second)->get('sites'))->toBe(['healthnews']);
});
