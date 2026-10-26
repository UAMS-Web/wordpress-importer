<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms;

use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\BaseDataManager;

/**
 * Manages Formidable Forms data storage and retrieval.
 *
 * This class extends BaseDataManager to provide Formidable Forms-specific
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
        parent::__construct('FormidableForms');
    }

    /**
     * Set the loaded Formidable Forms forms data.
     *
     * @param  array<int|string, mixed>|null  $forms  The loaded Formidable Forms forms array
     */
    public function setFormidableFormsData(
        ?array $forms
    ): void {
        $this->setFormsData($forms);
    }

    /**
     * Get the loaded Formidable Forms forms data.
     *
     * @return array<int|string, mixed>|null The loaded Formidable Forms forms array
     */
    public function getFormidableFormsData(): ?array
    {
        return $this->getFormsData();
    }

    /**
     * Clear the Formidable Forms data.
     *
     * Useful for resetting between different import sessions.
     */
    public function clearFormidableFormsData(): void
    {
        $this->clearFormsData();
    }

    /**
     * Check if Formidable Forms data is loaded.
     *
     * @return bool True if data is loaded, false otherwise
     */
    public function hasFormidableFormsData(): bool
    {
        return $this->getFormsData() !== null;
    }
}
