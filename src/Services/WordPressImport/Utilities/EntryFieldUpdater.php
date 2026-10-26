<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities;

use Illuminate\Support\Facades\Log;
use Statamic\Entries\Entry;

/**
 * Utility class for updating entry fields.
 *
 * This class provides a centralized way to update entry field values.
 * The class follows the Single Responsibility Principle by focusing solely
 * on field updates, and the DRY principle by centralizing this common pattern.
 */
class EntryFieldUpdater
{
    /**
     * Update one or more fields on an entry.
     *
     * This method safely updates field values on an entry. For dated collections,
     * it preserves the original entry date before saving to prevent duplicate
     * file creation. The return type depends on the save parameter:
     *   - When saving (default): returns the save's own boolean result, which is
     *     false when a listener halts the save as well as when one throws
     *   - When not saving: returns the updated entry instance
     *
     * @param  Entry  $entry  The entry to update
     * @param  array<int|string, mixed>  $fields  Associative array of field names and their values
     * @param  bool  $save  Whether to save the entry after updating (default: true)
     * @return bool|Entry Returns bool (success/failure) when $save=true, or Entry when $save=false
     *
     * @throws \Exception When field update or save operation fails
     */
    public static function updateEntryFields(
        Entry $entry,
        array $fields,
        bool $save = true
    ): bool|Entry {
        try {
            // Preserve the entry's date before updating fields
            $originalDate = $entry->date();

            // Update each field with its new value
            foreach ($fields as $fieldName => $value) {
                $entry->set(
                    $fieldName,
                    $value
                );
            }

            // Save the entry if requested
            if ($save) {
                // Restore the original date before saving to prevent duplicate files
                if ($originalDate) {
                    $entry->date($originalDate);
                }

                // ---
                // Report the save's OWN result rather than assuming it succeeded.
                // `Statamic\Entries\Entry::save()` returns false — without throwing —
                // when an `EntryCreating` or `EntrySaving` listener halts it, so
                // discarding the return made a returned `true` mean "nothing threw"
                // rather than "the entry was saved".
                //
                // This is hardening, not a fix for an observed failure: every
                // `EntrySaving` listener the host app registers declares
                // `handle(): void` and therefore cannot halt, so the false-returning
                // path is unreachable there today. The rejection that wordpress-importer-archive#761 actually
                // hit was a THROW, which the catch below already turns into `false`.
                // Verified on macOS, 2026-08-27 against the eleven listeners in the
                // host's `EventServiceProvider`. (wordpress-importer-archive#761)
                // ---
                return $entry->save() !== false;
            }

            // Return the updated entry when the caller asked not to save
            return $entry;
        } catch (\Exception $exception) {
            // Log error details for debugging and monitoring
            $fieldNames = \implode(
                ', ',
                \array_keys($fields)
            );
            $titleValue = $entry->get('title');
            $entryTitle = is_string($titleValue) ? $titleValue : '';
            $entryIdValue = $entry->id();
            // An integer id interpolates the same without the cast, which is here
            // to keep the type a string.
            // @pest-mutate-ignore: RemoveStringCast
            $entryId = is_string($entryIdValue)
                ? $entryIdValue
                : (is_int($entryIdValue) ? (string) $entryIdValue : '');
            Log::error(
                "EntryFieldUpdater::updateEntryFields: Failed to update fields [{$fieldNames}] on entry '{$entryTitle}' (ID: {$entryId})",
                [
                    'error' => $exception->getMessage(),
                    'fields' => $fields,
                    'entry_id' => $entry->id(),
                ]
            );

            // Return failure (false) when an exception occurs
            return false;
        }
    }
}
