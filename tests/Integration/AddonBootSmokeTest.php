<?php

declare(strict_types=1);

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

it('boots a minimal Statamic with the importer service provider and resolves a service', function (): void {
    $parser = app(XmlParser::class);

    expect($parser->getFilePaths())->toBe([]);
});
