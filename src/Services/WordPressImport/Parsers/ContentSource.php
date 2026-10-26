<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers;

use UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi\WxrMaterializer;

/**
 * A source of WXR export parts the importer can stream.
 *
 * The seam is the **file path**, not the parser's read facade. `wordpress-importer-archive#587` established
 * that: six entry creators reach past `XmlParser` entirely and stream the files
 * themselves, so a source implementing the seven read methods and serving no paths
 * would satisfy the type system and import nothing. `getFilePaths()` carries more
 * call sites than every read method except `getItems()`, and is what every bypass
 * actually consumes — so it is the contract worth naming.
 *
 * Implementing this interface is therefore a claim about paths, not about parsing.
 * The API-backed source satisfies it the same way the WXR source does, because
 * `wordpress-importer-archive#587` chose to materialize real part files rather than synthesize nodes.
 *
 * @see WxrMaterializer
 */
interface ContentSource
{
    /**
     * Every export part this source can serve, in reading order.
     *
     * @return list<string> Absolute paths to the export parts, empty when the source has none
     */
    public function getFilePaths(): array;
}
