<?php

declare(strict_types=1);

use Mockery\MockInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;

/*
 * Logo list fixture helpers shared by LogoListBlockImportTest and
 * LogoListParserSetTest, so each file runs on its own as well as in the suite.
 */

/**
 * Absolute path to the git-tracked fixture.
 *
 * Deliberately NOT one of the private `Exports` submodule files: those are absent
 * from a clean checkout, so a test built on them could not run in package CI.
 */
function logoListFixturePath(): string
{
    return __DIR__.'/../Fixtures/wxr/logo-list-block.xml';
}

/**
 * Read the fixture's four `logo-list-category` terms with the production parser.
 *
 * `TermMapBuilder::buildTermMap()` reads `wp:term_id` by XPath, so the elements
 * must carry the `wp` namespace. A hand-built `SimpleXMLElement` silently yields
 * an empty term map, the category filter collapses to `[]`, and a test that only
 * checked the heading would stay green with the whole term path dead.
 *
 * @return array<int|string, mixed> SimpleXMLElement term nodes
 */
function logoListFixtureTerms(): array
{
    $xmlParser = new XmlParser;
    $xmlParser->parse(logoListFixturePath());

    return $xmlParser->getTerms();
}

/**
 * A mocked `AssetImporter`, the only collaborator `parse()` requires.
 *
 * `LinkProcessor` participates solely through `more_button_url`; with an empty URL
 * it early-returns, so no network, filesystem, or asset container is touched.
 */
function logoListAssetImporter(): AssetImporter
{
    /** @var AssetImporter&MockInterface $assetImporter */
    $assetImporter = Mockery::mock(
        AssetImporter::class,
        ['getBaseBlogUrl' => 'https://uamshealth.com/']
    )->shouldIgnoreMissing();

    return $assetImporter;
}

/**
 * Minimal valid block attributes, shaped like the fixture's first block.
 *
 * The negative cases each vary exactly one field away from this known-good block,
 * so a skip test cannot pass because of the wrong guard.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function logoListAttributes(array $overrides = []): array
{
    return [
        'name' => 'acf/logo-list',
        'data' => array_merge([
            'block_logo_list_heading' => 'Valid Heading',
            'block_logo_list_hide_heading' => '0',
            'block_logo_list_background_color' => 'bg-gray',
            'block_logo_list_filter_ids' => ['7908'],
            'block_logo_list_filter_logic' => 'IN',
            'block_logo_list_labels_ids' => '',
            'block_logo_list_featured' => '0',
            'block_logo_list_layout' => 'grid',
            'block_logo_list_width' => 'alignfull',
            'block_logo_list_titles' => '0',
            'block_logo_list_labels' => '0',
            'block_logo_list_shortdescr' => '0',
            'block_logo_list_links' => '0',
            'block_logo_list_order' => ['title|ASC'],
            'block_logo_list_max' => '',
            'block_logo_list_more' => '0',
        ], $overrides),
        'align' => 'full',
        'mode' => 'auto',
    ];
}
