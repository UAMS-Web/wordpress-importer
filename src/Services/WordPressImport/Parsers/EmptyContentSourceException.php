<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers;

use RuntimeException;

/**
 * A content source was asked to stream and could serve no export parts.
 *
 * Deliberately an exception rather than an empty generator. Each of the six entry
 * creators used to open with its own variant of `if ($filePaths === []) { return
 * []; }` — a source with no paths imported zero items and reported success, which
 * is this codebase's signature defect rather than a hypothetical one: a truncation
 * that imported 103 of 1826 items, an availability filter that matched nothing, an
 * extractor that returned an empty list for all 870 blocks.
 *
 * Raising here makes that class of failure loud at the point it occurs, and makes
 * the short-circuit it replaced unreachable.
 */
class EmptyContentSourceException extends RuntimeException {}
