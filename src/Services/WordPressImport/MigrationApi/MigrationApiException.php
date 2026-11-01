<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\MigrationApi;

use RuntimeException;

/**
 * A migration API request could not be completed.
 *
 * Deliberately an exception rather than an empty result. Every consumer of this client
 * imports content, and this codebase's recurring defect is a step that returns nothing and
 * reports success — a truncation that imported 103 of 1826 items, an availability filter
 * that matched nothing, an extractor that returned an empty list for all 870 blocks. A
 * client that turned a transport failure into `[]` would seat that failure mode at the
 * root of every importer built on it.
 */
class MigrationApiException extends RuntimeException
{
    /**
     * The error code the API named in its response body, when it named one.
     */
    private ?string $apiCode = null;

    /**
     * An exception for a response the API answered with an error status.
     *
     * Carries the API's own error code, so a caller can tell an expected refusal, such as a
     * plugin that is not active on the site, from a failure, without matching the message.
     *
     * @param  string  $message  The message
     * @param  string|null  $apiCode  The `code` from the response body, if any
     */
    public static function fromErrorResponse(
        string $message,
        ?string $apiCode
    ): self {
        $exception = new self($message);
        $exception->apiCode = $apiCode;

        return $exception;
    }

    /**
     * The error code the API named, or null when the failure was not an API error response.
     */
    public function apiCode(): ?string
    {
        return $this->apiCode;
    }
}
