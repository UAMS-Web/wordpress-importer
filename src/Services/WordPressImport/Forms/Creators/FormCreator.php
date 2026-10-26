<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Creators;

use Illuminate\Support\Str;
use Statamic\Facades\Form as FormFacade;
use Statamic\Forms\Form;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\FormBlueprintCreator as FormidableFormBlueprintCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\FormidableFormsCoordinator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FormBlueprintCreator as GravityFormBlueprintCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\GravityFormsCoordinator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\UserRegistrationFeed;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms\FormBlueprintCreator as NinjaFormBlueprintCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms\NinjaFormsCoordinator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\HtmlTagStripper;

/**
 * Creates Statamic forms from WordPress form plugin JSON data.
 *
 * This class is responsible for:
 *   - Creating Statamic forms from any WordPress form plugin data
 *   - Mapping form data to Statamic form handles and titles
 *   - Creating basic blueprints for imported forms
 *   - Providing a consistent interface for form creation across different plugins
 */
class FormCreator
{
    /**
     * Create a new FormCreator instance.
     *
     * @param  FormidableFormBlueprintCreator|GravityFormBlueprintCreator|NinjaFormBlueprintCreator|null  $blueprintCreator  Plugin-specific blueprint creator
     * @param  FormidableFormsCoordinator|GravityFormsCoordinator|NinjaFormsCoordinator|null  $coordinator  Plugin-specific coordinator
     */
    public function __construct(protected FormidableFormBlueprintCreator|GravityFormBlueprintCreator|NinjaFormBlueprintCreator|null $blueprintCreator = null, protected FormidableFormsCoordinator|GravityFormsCoordinator|NinjaFormsCoordinator|null $coordinator = null) {}

    /**
     * Create a Statamic form from WordPress form plugin JSON data.
     *
     * @param  array<int|string, mixed>  $formData  The WordPress form plugin form array (single form)
     * @param  string  $siteName  The Statamic site name (for display)
     * @param  string  $siteHandle  The Statamic site handle (for handle prefix)
     * @param  string  $pluginName  The form plugin name (e.g., 'gravity-forms', 'formidable-forms')
     * @param  ?list<array<string, mixed>>  $feeds  The form's add-on feeds as the migration
     *                                              API serves them, or null when they were not
     *                                              fetched. A Gravity Forms export carries no
     *                                              feeds, so this is their only source
     * @param  array<int|string, mixed>|null  $sourceForms  Every form in the export, not just this
     *                                                      one. A nested-form field names a sibling
     *                                                      form by id, and that form's fields are
     *                                                      reachable from nowhere else (wordpress-importer-archive#1156).
     *                                                      Null leaves such a reference unresolved
     *                                                      and reported
     * @return ?Form The created Statamic form or null on failure
     */
    public function createForm(
        array $formData,
        string $siteName,
        string $siteHandle,
        string $pluginName,
        ?array $feeds = null,
        ?array $sourceForms = null
    ): ?Form {
        // Get the form title based on the plugin
        $formTitle = $this->getFormTitle(
            $formData
        );

        // Ensure the form has a title
        if (($formTitle === null || $formTitle === '')) {
            return null;
        }

        // Strip HTML tags from form title
        $formTitle = HtmlTagStripper::strip($formTitle);

        // Build the Statamic form name: '{{Site Name}} — {{title}}'
        $name = $siteName.' — '.$formTitle;

        // Build the Statamic form handle: '{{site handle}}_{{form plugin}}_{{source form id}}_{{sluggified title}}'
        $sourceFormId = $formData['id'] ?? '';
        $sourceFormId = \is_scalar($sourceFormId) ? (string) $sourceFormId : '';

        $handle = $siteHandle.'_'.$pluginName.'_'.$sourceFormId.'_'.Str::slug($formTitle);

        // Create a new Statamic form instance with the generated handle
        $form = FormFacade::make($handle);
        assert($form instanceof Form);
        $form->title($name); // Set the form's display title

        // Carry the user-registration configuration, when this form has a live feed for it
        $registration = $this->userRegistrationFor($feeds);

        if ($registration !== null) {
            // Statamic's Form uses ContainsData: save() persists arbitrary keys and hydrate()
            // reads them back, so this round-trips without a host-app change. The consumer is
            // UAMS-Web/uams-statamic#2115.
            $form->set('user_registration', $registration);
        }

        // Assign the form to the site it was imported for. statamic-multisite-forms reads this
        // key to decide where the form may render, and its own listener leaves a form that
        // already lists sites alone. The form is made fresh on every import, so a re-import
        // writes the same single entry rather than adding a second one.
        $form->set('sites', [$siteHandle]);

        // Save the new form to the Statamic forms repository
        $form->save();

        // Create a blueprint for the form if it doesn't already exist
        if ($this->blueprintCreator !== null) {
            // Check if blueprint already exists to avoid recreation
            if (! $this->blueprintCreator->blueprintExists($handle)) {
                // Get the form fields if available
                $formFields = $formData['fields'] ?? [];

                if (! \is_array($formFields)) {
                    $formFields = [];
                }

                $formId = $formData['id'] ?? null;
                $formId = \is_scalar($formId) ? (string) $formId : null;

                // Create blueprint with fields if available, otherwise create basic blueprint
                if ($formFields !== []) {
                    $this->blueprintCreator->createBlueprintWithFields(
                        $handle,
                        $name,
                        $formFields,
                        $pluginName,
                        $formId,
                        $siteHandle,
                        $sourceForms
                    );
                } else {
                    $this->blueprintCreator->createBasicBlueprint(
                        $handle,
                        $name
                    );
                }
            }
        }

        // Return the created Statamic form instance
        return $form;
    }

    /**
     * Get the form title based on the plugin coordinator.
     *
     * Uses the coordinator's getFormTitleAttributeName() method to determine
     * which field contains the form title.
     *
     * @param  array<int|string, mixed>  $formData  The WordPress form plugin form array
     * @return ?string The form title or null if not found
     */
    private function getFormTitle(
        array $formData
    ): ?string {
        // Use coordinator if available
        if ($this->coordinator !== null) {
            $titleAttribute = $this->coordinator->getFormTitleAttributeName();
            $title = $formData[$titleAttribute] ?? null;

            return \is_string($title) ? $title : null;
        }

        // Return null if no coordinator or method available
        return null;
    }

    /**
     * The user-registration configuration for a form, or null when it registers nobody.
     *
     * Takes the migration API's feed payload for one form, because a Gravity Forms export
     * carries no feeds at all — the configuration is unreachable from the corpus. A form
     * with no feeds, or with only other add-ons' feeds, or with a disabled registration
     * feed, all resolve to null and import as ordinary forms.
     *
     * @param  ?list<array<string, mixed>>  $feeds  The form's feeds as the API serves them
     * @return ?array<string, mixed> The configuration to write, or null
     */
    private function userRegistrationFor(?array $feeds): ?array
    {
        if ($feeds === null) {
            return null;
        }

        foreach ($feeds as $feed) {
            $registration = UserRegistrationFeed::fromPayload($feed);

            if ($registration instanceof UserRegistrationFeed) {
                return $registration->toFormData();
            }
        }

        return null;
    }
}
