<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\Loaders;

/**
 * The Ninja Forms sourcing seam (wordpress-importer-archive#810).
 *
 * The file loader is the shared JSON one: a `.nff` is plain JSON despite its extension, so
 * the file path needs no Ninja-specific reader. What Ninja does need, and Gravity does not,
 * is the **form id** — a `.nff` carries none at any level while content references
 * `[ninja_form id=N]`, which is why the file path prompts once per referenced id. The
 * endpoint serves `id` on every form, so the API path binds by it and prompts for nothing.
 */
class NinjaFormsLoader extends MigrationApiFormLoader
{
    /**
     * Create the Ninja Forms loader.
     */
    public function __construct()
    {
        parent::__construct(
            'NinjaForms',
            'ninja',
            new JsonFileLoader('NinjaForms')
        );
    }
}
