<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities;

/**
 * Utility class for handling MinervaKB restriction-related logic.
 *
 * Provides shared functionality for processing restriction roles,
 * formatting roles for import notes, and checking guest-only access.
 */
class RestrictionHelper
{
    /**
     * Format roles for import_wordpress_notes entry.
     *
     * Converts restrict_article_role value to a formatted string
     * suitable for import_wordpress_notes, with roles as a
     * comma-separated list wrapped in double-quotes.
     *
     * Handles various input formats including arrays, JSON strings,
     * and other string representations.
     *
     * @param  mixed  $role  The restrict_article_role value to format (can be array, JSON string, or other
     *                       string)
     * @return ?string The formatted string or null if role is invalid or empty
     */
    public static function formatRolesForNotes(
        mixed $role
    ): ?string {
        // Initialize roles array for processing; an empty role leaves it empty,
        // and the check after filtering returns null for it
        $rolesArray = [];

        // Check if role is already an array
        if (\is_array($role)) {
            // Use role array directly
            $rolesArray = $role;
        } elseif (\is_string($role)) { // If role is a string, try to decode it as JSON
            // Decode JSON string to array
            $decoded = \json_decode(
                $role,
                true
            );

            // Use decoded array if valid, otherwise split by comma
            $rolesArray = (
                json_last_error() === JSON_ERROR_NONE
                && \is_array($decoded)
            ) ? $decoded : \array_map(
                trim(...),
                \explode(
                    ',',
                    $role
                )
            );

            // Clean up variables to free up memory
            unset(
                $decoded
            );
        }

        // Filter out empty values and format roles
        $rolesArray = \array_filter(
            $rolesArray,
            fn ($r): bool => ! (in_array($r, [null, '', false, []], true))
        );

        // Check if roles array is empty after filtering
        if ($rolesArray === []) {
            // Return null if no valid roles found
            return null;
        }

        // ---
        // Format each role with double-quotes and join with commas. Escape any double
        // quotes within role names.
        // ---
        $formattedRoles = \array_map(
            function ($r): string {
                // Skip non-scalar role values that cannot be formatted
                if (! \is_string($r) && ! \is_numeric($r)) {
                    return '';
                }

                // Escape double quotes within role name
                $escaped = \str_replace(
                    '"',
                    '\\"',
                    (string) $r
                );

                // Wrap escaped role in double quotes
                return '"'.$escaped.'"';
            },
            $rolesArray
        );

        // Build formatted string with restriction prefix
        $result = 'restriction_user_roles: '.\implode(
            ', ',
            $formattedRoles
        );

        // Clean up variables to free up memory
        unset(
            $rolesArray,
            $formattedRoles
        );

        // Return the formatted result
        return $result;
    }

    /**
     * Check if restrict_article_role represents guest-only access.
     *
     * Handles various formats including arrays, JSON strings, and
     * other string representations. Normalizes the value and checks
     * if it contains only 'guest'.
     *
     * @param  mixed  $role  The restrict_article_role value to check
     * @return bool True if the role represents guest-only access
     */
    public static function isGuestOnlyRole(
        mixed $role
    ): bool {
        // Check if role is empty or null
        if ((in_array($role, [null, '', false, []], true))) {
            // Empty role is considered guest-only
            return true;
        }

        // Check if role is already an array
        if (\is_array($role)) {
            // Return true if array contains only 'guest'
            return
                \count($role) === 1
                && \in_array(
                    'guest',
                    $role,
                    true
                );
        }

        // Check if role is a string
        if (\is_string($role)) {
            // Try to decode JSON string
            $decoded = \json_decode(
                $role,
                true
            );

            // Check if JSON decode was successful and result is an array
            if (
                json_last_error() === JSON_ERROR_NONE
                && \is_array($decoded)
            ) {
                // Determine if decoded array contains only 'guest'
                $isGuest =
                    \count($decoded) === 1
                    && \in_array(
                        'guest',
                        $decoded,
                        true
                    );

                // Clean up variables to free up memory
                unset(
                    $decoded
                );

                // Return guest-only determination
                return $isGuest;
            }

            // Trim whitespace from role string
            $trimmed = \trim($role);

            // Normalize quotes to double-quotes for comparison
            $normalized = \preg_replace(
                '/[\'"]/',
                '"',
                $trimmed
            );

            // Check if normalized string matches guest-only format
            $isGuest = $normalized === '["guest"]';

            // Clean up variables to free up memory
            unset(
                $trimmed,
                $normalized
            );

            // Return guest-only determination
            return $isGuest;
        }

        // If we can't determine it's guest-only, assume it's not
        return false;
    }

    /**
     * Apply restriction fields to entry data when roles are not guest-only.
     *
     * Sets restriction_category, restriction_user_roles, and formats
     * roles for import_wordpress_notes when the restriction is
     * role-based (not guest-only).
     *
     * @param  array<int|string, mixed>  &$entryData  The entry data array to update
     * @param  mixed  $roles  The roles array or value to format
     */
    public static function applyRestrictionFields(
        array &$entryData,
        mixed $roles
    ): void {
        // Set restriction_category to ['user_roles']
        $entryData['restriction_category'] = ['user_roles'];

        // Set restriction_user_roles to null
        $entryData['restriction_user_roles'] = null;

        // Format roles for import_wordpress_notes
        $rolesFormatted = self::formatRolesForNotes($roles);

        // Check if roles were successfully formatted
        if ($rolesFormatted !== null) {
            $entryData['import_wordpress_notes'] ??= [];

            // Check if import_wordpress_notes is an array
            if (! \is_array($entryData['import_wordpress_notes'])) {
                // Convert import_wordpress_notes to array
                $entryData['import_wordpress_notes'] = [];
            }

            // Add the formatted roles note
            $entryData['import_wordpress_notes'][] = $rolesFormatted;
        }

        // Clean up variables to free up memory
        unset(
            $rolesFormatted
        );
    }
}
