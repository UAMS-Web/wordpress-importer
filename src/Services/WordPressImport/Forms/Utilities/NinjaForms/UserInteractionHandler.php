<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormPluginUserInteractionHandlerContract;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\UserInteractionHandlerTrait;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\NinjaForms\Selector as NinjaFormsSelector;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\NinjaForms\Validator as NinjaFormsValidator;

/**
 * Handles user interaction for Ninja Forms operations.
 *
 * This class provides Ninja Forms-specific user interaction functionality.
 *
 * Follows the Single Responsibility Principle by focusing solely on
 * user interaction operations.
 */
class UserInteractionHandler implements FormPluginUserInteractionHandlerContract
{
    /**
     * Use shared functionality for user interaction handling.
     */
    use UserInteractionHandlerTrait;

    /**
     * The Ninja Forms data store, typed concretely for the keyed-by-ID writes.
     */
    private DataManager $ninjaFormsDataManager;

    /**
     * Create a new UserInteractionHandler instance.
     *
     * @param  ContentDetector  $contentDetector  Service for detecting Ninja Forms content
     * @param  DataManager  $dataManager  Service for managing Ninja Forms data
     * @param  NinjaFormsSelector  $ninjaFormsSelector  Service for Ninja Forms user interaction
     * @param  FormIdExtractor  $formIdExtractor  Service for extracting referenced form IDs
     * @param  NinjaFormsValidator  $validator  Service for reading an export's advertised form ID
     */
    public function __construct(
        ContentDetector $contentDetector,
        DataManager $dataManager,
        NinjaFormsSelector $ninjaFormsSelector,
        private FormIdExtractor $formIdExtractor,
        private NinjaFormsValidator $validator
    ) {
        $this->initializeUserInteractionHandler(
            $contentDetector,
            $dataManager,
            $ninjaFormsSelector
        );
        $this->ninjaFormsDataManager = $dataManager;
    }

    /**
     * Handle Ninja Forms detection and user interaction.
     *
     * Deliberately does NOT delegate to the shared single-prompt routine. That routine
     * prompts once and stores the decoded export verbatim, which works for Gravity Forms
     * and Formidable Forms because each of those exports is a **list of forms that carry
     * their own `id`**. A `.nff` is one form per file and carries no `id` at all, so an
     * export stored verbatim is unattributable: the downstream availability filter reads
     * `$form['id']` off each entry, finds nothing, and intersects the referenced IDs down
     * to an empty set — importing zero forms without reporting an error.
     *
     * So the binding ID is the one **detection surfaced and the operator answered for**,
     * not one read out of the file. Only two of the four production exports advertise an
     * ID at all (via `settings.embed_form`); the other two are anonymous. Where a file
     * does advertise one, it is used as a cross-check and a mismatch is reported rather
     * than silently trusted in either direction.
     *
     * **That reasoning is about the FILE path, and the migration API dissolves it** (wordpress-importer-archive#810).
     * The endpoint returns every form the site has, each carrying its own `id` — the field a
     * `.nff` lacks — so on an API-sourced run the binding ID comes from the source and the
     * per-form prompt is skipped entirely. The file path below is untouched: its prompt, its
     * `settings.embed_form` cross-check and its mismatch warning all still run.
     *
     * @param  XmlParser  $xmlParser  The XML parser instance
     * @param  InputInterface  $input  Console input interface
     * @param  OutputInterface  $output  Console output interface
     * @param  Command  $command  Command instance
     * @return bool True if Ninja Forms data was loaded, false otherwise
     */
    public function handleFormPluginDetection(
        XmlParser $xmlParser,
        InputInterface $input,
        OutputInterface $output,
        Command $command
    ): bool {
        // Nothing to do when no block or shortcode references Ninja Forms.
        if (! $this->contentDetector->hasFormPluginContent($xmlParser)) {
            return false;
        }

        $output->writeln('<info>Ninja Forms blocks or shortcodes detected in the XML.</info>');

        // Collect the form IDs actually referenced by the content being imported.
        $formIds = $this->referencedFormIds($xmlParser);

        // Detection fired but no ID could be read, so there is nothing to prompt for.
        if ($formIds === []) {
            $output->writeln(
                '<comment>No Ninja Forms form ID could be read from the detected references; skipping.</comment>'
            );

            return false;
        }

        // On an API-sourced run the endpoint serves every form WITH its id, which is the one
        // thing a `.nff` cannot supply — so the ids come from the source rather than from an
        // operator, and the per-form prompt below is skipped entirely (wordpress-importer-archive#810).
        if ($this->formSelector->hasMigrationApiSource()) {
            return $this->bindFromMigrationApi(
                $formIds,
                $input,
                $output,
                $command
            );
        }

        $anyLoaded = false;

        // A `.nff` holds one form per file, so prompt once per referenced form ID.
        foreach ($formIds as $formId) {
            $output->writeln(
                "Supply the Ninja Forms <info>.nff</info> export for form ID <info>{$formId}</info>."
            );

            $formData = $this->formSelector->select(
                $input,
                $output,
                $command
            );

            // The operator declined to supply an export for this form ID.
            if ($formData === null) {
                $output->writeln(
                    "<comment>No export supplied for Ninja Forms form ID {$formId}; it will not be imported.</comment>"
                );

                continue;
            }

            // Report a file that names a different form than the one it was supplied for.
            $this->reportAdvertisedIdMismatch(
                $formId,
                $formData,
                $output
            );

            // Bind the export to the form ID it was supplied for.
            $this->ninjaFormsDataManager->addNinjaForm(
                $formId,
                $formData
            );

            $anyLoaded = true;
        }

        return $anyLoaded;
    }

    /**
     * Collect the unique Ninja Forms form IDs referenced in the XML.
     *
     * @param  XmlParser  $xmlParser  The XML parser instance
     * @return list<int> The referenced form IDs
     */
    private function referencedFormIds(
        XmlParser $xmlParser
    ): array {
        $formIds = [];

        // The extractor keys its result by form ID, with the page slugs as values.
        foreach (
            \array_keys(
                $this->formIdExtractor->getFormIdsUsedWithPageSlugs($xmlParser)
            ) as $formId
        ) {
            if (\is_int($formId)) {
                $formIds[] = $formId;
            } elseif (\ctype_digit($formId)) {
                $formIds[] = (int) $formId;
            }
        }

        return \array_values(\array_unique($formIds));
    }

    /**
     * Bind every referenced form id to the definition the migration API serves for it.
     *
     * The endpoint returns all of this site's forms in one read, each carrying its own `id`,
     * so the ids are matched rather than asked for. A referenced id the API does not serve is
     * **reported and skipped**, never silently dropped — the same disposition the file path
     * gives an export the operator declines to supply.
     *
     * @param  list<int>  $formIds  The form ids the content references
     * @param  InputInterface  $input  Console input interface
     * @param  OutputInterface  $output  Console output interface
     * @param  Command  $command  Command instance for error reporting
     * @return bool True when at least one referenced form was bound
     */
    private function bindFromMigrationApi(
        array $formIds,
        InputInterface $input,
        OutputInterface $output,
        Command $command
    ): bool {
        // One read for the whole site; the file path's per-id prompt has no counterpart here.
        $forms = $this->formSelector->select(
            $input,
            $output,
            $command
        );

        if ($forms === null) {
            return false;
        }

        // Index by the id the API reports, so a referenced id is matched rather than assumed
        // to be positional.
        $byId = [];

        foreach ($forms as $form) {
            // Narrowing for the type system rather than a runtime guard, and recorded as such
            // because it cannot be covered: `select()` returns `array<int|string, mixed>`, so
            // `$form` is `mixed` and level max refuses the offset read below without it —
            // while `MigrationApiFormLoader` has ALREADY dropped every non-array entry, so no
            // input reaching here can take this branch. Deleting it leaves every test green
            // (measured: a surviving mutant), which is the correct result for an equivalent
            // mutant and not a gap to close with a test that would pass either way.
            if (! is_array($form)) {
                continue;
            }

            $id = $form['id'] ?? null;

            // A served id may arrive as a numeric string — the Gravity payload's is `'12'` —
            // so a string is accepted, but only a DIGIT string. Casting any string would file
            // a non-numeric id under key `0`, where it would match no reference and shadow a
            // real form `0` if one existed. Same test `referencedFormIds()` applies to the
            // ids coming off the content.
            if (\is_int($id)) {
                $byId[$id] = $form;
            } elseif (\is_string($id) && \ctype_digit($id)) {
                $byId[(int) $id] = $form;
            }
        }

        $anyLoaded = false;

        foreach ($formIds as $formId) {
            if (! array_key_exists($formId, $byId)) {
                $output->writeln(
                    "<comment>Ninja Forms form ID {$formId} is referenced by the content but the "
                    .'migration API does not serve it; it will not be imported.</comment>'
                );

                continue;
            }

            $this->ninjaFormsDataManager->addNinjaForm(
                $formId,
                $byId[$formId]
            );

            $anyLoaded = true;
        }

        return $anyLoaded;
    }

    /**
     * Warn when an export advertises a different form ID than it was supplied for.
     *
     * Two of the four production exports carry `settings.embed_form`; the rest are
     * anonymous, so an absent advertised ID is normal and is not reported.
     *
     * @param  int  $formId  The form ID the export was supplied for
     * @param  array<int|string, mixed>  $formData  The decoded `.nff` export
     * @param  OutputInterface  $output  Console output interface
     */
    private function reportAdvertisedIdMismatch(
        int $formId,
        array $formData,
        OutputInterface $output
    ): void {
        $advertised = $this->validator->advertisedFormId($formData);

        // Most exports advertise nothing, which is not a mismatch.
        if ($advertised === null || $advertised === $formId) {
            return;
        }

        $output->writeln(
            "<comment>Warning: the export supplied for Ninja Forms form ID {$formId} advertises "
            ."form ID {$advertised}. Importing it as form ID {$formId} as supplied.</comment>"
        );
    }
}
