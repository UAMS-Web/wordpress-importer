<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities;

/**
 * Contract for plugin-specific form creation coordinators.
 */
interface FormPluginFormCreationCoordinatorContract
{
    /**
     * Get the created Statamic form handle for a form plugin form ID.
     *
     * @param  int  $formId  The form plugin form ID
     * @return ?string The Statamic form handle or null if not created
     */
    public function getCreatedFormHandle(
        int $formId
    ): ?string;

    /**
     * Set the parent plugin coordinator on the form creation coordinator.
     *
     * @param  object  $coordinator  The plugin coordinator instance
     */
    public function setCoordinator(
        object $coordinator
    ): void;
}
