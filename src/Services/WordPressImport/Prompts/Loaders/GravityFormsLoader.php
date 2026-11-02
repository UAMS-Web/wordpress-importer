<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\Loaders;

/**
 * The Gravity Forms sourcing seam (wordpress-importer-archive#809).
 *
 * A named subclass rather than a configured instance, because the container binds by type
 * and each plugin needs its **own** singleton: one shared `MigrationApiFormLoader` binding
 * could only ever carry one endpoint slug, and the command configures each loader's source
 * separately. The class is the name the container and the constructor signatures agree on.
 */
class GravityFormsLoader extends MigrationApiFormLoader
{
    /**
     * Create the Gravity Forms loader.
     */
    public function __construct()
    {
        parent::__construct(
            'GravityForms',
            'gravity',
            new JsonFileLoader('GravityForms')
        );
    }
}
