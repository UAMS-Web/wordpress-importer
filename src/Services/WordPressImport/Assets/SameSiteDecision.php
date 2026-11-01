<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Assets;

/**
 * What a same-site policy decided for one asset URL.
 *
 * A `null` cannot carry this answer, because "skip this asset" and "carry on with
 * the import" both need one, so an importer reads `$import` rather than the value
 * (#232).
 */
final readonly class SameSiteDecision
{
    /**
     * Create a decision through one of the named constructors.
     *
     * @param  bool  $import  Whether the importer should go on to import the asset
     * @param  ?string  $earlyResult  What the importer returns when it does not import
     */
    private function __construct(
        public bool $import,
        public ?string $earlyResult
    ) {}

    /**
     * The asset should be imported.
     *
     * @return self The decision
     */
    public static function import(): self
    {
        return new self(true, null);
    }

    /**
     * The asset should be left out, with nothing in its place.
     *
     * @return self The decision
     */
    public static function skip(): self
    {
        return new self(false, null);
    }

    /**
     * The asset should stay where it is, linked by its original URL.
     *
     * @param  string  $url  The original asset URL
     * @return self The decision
     */
    public static function link(string $url): self
    {
        return new self(false, $url);
    }
}
