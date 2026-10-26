<?php

declare(strict_types=1);

/**
 * FormCreator's title, handle and blueprint decisions (#98).
 *
 * FormSiteAssignmentTest covers the site assignment and GravityFormsUserRegistrationTest the
 * registration feeds. This file covers what is left: no title, the handle's parts, and which
 * blueprint call a form gets. The blueprint creator is a mock, so only the form itself is
 * written to Testbench's workbench, and each test deletes the form it saved.
 *
 * @command  php vendor/bin/pest tests/Integration/FormCreatorTest.php
 */

use Statamic\Facades\Form as FormFacade;
use Statamic\Forms\Form;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Creators\FormCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\FormidableFormsCoordinator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FormBlueprintCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\GravityFormsCoordinator;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

beforeEach(function (): void {
    // A Gravity Forms coordinator that names `title` as the export key holding the title.
    $this->titleCoordinator = $this->createMock(GravityFormsCoordinator::class);
    $this->titleCoordinator->method('getFormTitleAttributeName')->willReturn('title');

    $this->blueprints = $this->createMock(FormBlueprintCreator::class);
});

afterEach(function (): void {
    // Testbench keeps saved forms on disk across processes, so remove what each test wrote.
    foreach (['default_gravity-forms_7_contact-us', 'default_gravity-forms__contact-us', 'default_formidable-forms_7_apply-now'] as $handle) {
        $form = FormFacade::find($handle);

        if ($form instanceof Form) {
            $form->delete();
        }
    }
});

it('creates nothing without a coordinator to say where the title is', function (): void {
    expect((new FormCreator)->createForm(['id' => 7, 'title' => 'Contact Us'], 'Main Site', 'default', 'gravity-forms'))->toBeNull();
});

it('creates nothing when the title is missing, empty or not a string', function (mixed $title): void {
    expect((new FormCreator(null, $this->titleCoordinator))->createForm(['id' => 7, 'title' => $title], 'Main Site', 'default', 'gravity-forms'))->toBeNull();
})->with([
    'missing' => [null],
    'empty' => [''],
    'an array' => [['Contact Us']],
]);

it('reads the title from the key the coordinator names', function (): void {
    $coordinator = $this->createMock(FormidableFormsCoordinator::class);
    $coordinator->method('getFormTitleAttributeName')->willReturn('name');

    $form = (new FormCreator(null, $coordinator))->createForm(['id' => 7, 'title' => 'Ignored', 'name' => 'Apply Now'], 'Main Site', 'default', 'formidable-forms');

    expect($form?->handle())->toBe('default_formidable-forms_7_apply-now')
        ->and($form?->title())->toBe("Main Site \u{2014} Apply Now");
});

it('strips markup from the title and builds the handle from the site, plugin, ID and title', function (): void {
    $form = (new FormCreator(null, $this->titleCoordinator))->createForm(['id' => '7', 'title' => '<strong>Contact</strong> Us'], 'Main Site', 'default', 'gravity-forms');

    expect($form?->handle())->toBe('default_gravity-forms_7_contact-us')
        ->and($form?->title())->toBe("Main Site \u{2014} Contact Us")
        // The form is saved, so it can be found again.
        ->and(FormFacade::find('default_gravity-forms_7_contact-us'))->toBeInstanceOf(Form::class);
});

it('leaves the ID out of the handle when the export carries none it can use', function (mixed $id): void {
    $form = (new FormCreator(null, $this->titleCoordinator))->createForm(['id' => $id, 'title' => 'Contact Us'], 'Main Site', 'default', 'gravity-forms');

    expect($form?->handle())->toBe('default_gravity-forms__contact-us');
})->with([
    'missing' => [null],
    'an array' => [[7]],
]);

it('gives a form with fields a blueprint built from them', function (): void {
    $blueprints = $this->blueprints;
    $fields = [['id' => 1, 'type' => 'text', 'label' => 'Name']];
    $sourceForms = [['id' => 7], ['id' => 8]];

    $blueprints->expects($this->once())->method('blueprintExists')->with('default_gravity-forms_7_contact-us')->willReturn(false);
    $blueprints->expects($this->once())->method('createBlueprintWithFields')->with('default_gravity-forms_7_contact-us', "Main Site \u{2014} Contact Us", $fields, 'gravity-forms', '7', 'default', $sourceForms);
    $blueprints->expects($this->never())->method('createBasicBlueprint');

    (new FormCreator($blueprints, $this->titleCoordinator))->createForm(['id' => 7, 'title' => 'Contact Us', 'fields' => $fields], 'Main Site', 'default', 'gravity-forms', null, $sourceForms);
});

it('passes no form ID to the blueprint when the export carries none it can use', function (): void {
    $blueprints = $this->blueprints;
    $fields = [['id' => 1, 'type' => 'text', 'label' => 'Name']];

    $blueprints->method('blueprintExists')->willReturn(false);
    $blueprints->expects($this->once())->method('createBlueprintWithFields')->with('default_gravity-forms__contact-us', "Main Site \u{2014} Contact Us", $fields, 'gravity-forms', null, 'default', null);

    (new FormCreator($blueprints, $this->titleCoordinator))->createForm(['id' => [7], 'title' => 'Contact Us', 'fields' => $fields], 'Main Site', 'default', 'gravity-forms');
});

it('gives a form with no usable fields a basic blueprint', function (mixed $fields): void {
    $blueprints = $this->blueprints;
    $formData = ['id' => 7, 'title' => 'Contact Us'];

    if ($fields !== 'absent') {
        $formData['fields'] = $fields;
    }

    $blueprints->expects($this->once())->method('blueprintExists')->willReturn(false);
    $blueprints->expects($this->once())->method('createBasicBlueprint')->with('default_gravity-forms_7_contact-us', "Main Site \u{2014} Contact Us");
    $blueprints->expects($this->never())->method('createBlueprintWithFields');

    (new FormCreator($blueprints, $this->titleCoordinator))->createForm($formData, 'Main Site', 'default', 'gravity-forms');
})->with([
    'no fields key' => ['absent'],
    'an empty list' => [[]],
    'not a list' => ['text'],
]);

it('leaves an existing blueprint alone', function (): void {
    $blueprints = $this->blueprints;

    $blueprints->expects($this->once())->method('blueprintExists')->willReturn(true);
    $blueprints->expects($this->never())->method('createBlueprintWithFields');
    $blueprints->expects($this->never())->method('createBasicBlueprint');

    $form = (new FormCreator($blueprints, $this->titleCoordinator))->createForm(['id' => 7, 'title' => 'Contact Us', 'fields' => [['id' => 1]]], 'Main Site', 'default', 'gravity-forms');

    expect($form)->toBeInstanceOf(Form::class);
});
