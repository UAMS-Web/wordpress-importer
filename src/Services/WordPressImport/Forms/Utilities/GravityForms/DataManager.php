<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms;

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\BaseDataManager;

/**
 * Manages Gravity Forms data storage and retrieval.
 *
 * This class extends BaseDataManager to provide Gravity Forms-specific
 * data management functionality.
 *
 * Follows the Single Responsibility Principle by focusing solely on
 * data management operations.
 */
class DataManager extends BaseDataManager
{
    /**
     * Create a new DataManager instance.
     */
    public function __construct()
    {
        parent::__construct('GravityForms');
    }

    /**
     * Set the loaded Gravity Forms forms data.
     *
     * @param  array<int|string, mixed>|null  $forms  The loaded Gravity Forms forms array
     */
    public function setGravityFormsData(
        ?array $forms
    ): void {
        $this->setFormsData($forms);
    }

    /**
     * Get the loaded Gravity Forms forms data.
     *
     * @return array<int|string, mixed>|null The loaded Gravity Forms forms array
     */
    public function getGravityFormsData(): ?array
    {
        return $this->getFormsData();
    }

    /**
     * Clear the Gravity Forms data.
     *
     * Useful for resetting between different import sessions.
     */
    public function clearGravityFormsData(): void
    {
        $this->clearFormsData();
    }
}
